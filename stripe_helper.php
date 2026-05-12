<?php

include_once __DIR__ . '/stripe_config.php';
include_once __DIR__ . '/email_helper.php';
include_once __DIR__ . '/payout_helper.php';
include_once __DIR__ . '/global/i18n.php';

function shobidStripeColumnExists($conn, $tableName, $columnName) {
    $tableSql = $conn->real_escape_string(trim((string)$tableName));
    $columnSql = $conn->real_escape_string(trim((string)$columnName));
    if ($tableSql === '' || $columnSql === '') {
        return false;
    }
    $res = $conn->query("SHOW COLUMNS FROM `$tableSql` LIKE '$columnSql'");
    return $res && $res->num_rows > 0;
}

function shobidStripeNormalizeLocale($locale, $fallback = 'hu-hu') {
    $candidate = strtolower(trim((string)$locale));
    if ($candidate === '') {
        $candidate = strtolower(trim((string)$fallback));
    }
    if (function_exists('shobidMarketNormalizeCode')) {
        return shobidMarketNormalizeCode($candidate, $fallback);
    }
    return $candidate !== '' ? $candidate : 'hu-hu';
}

function shobidStripeCountryByLocale($locale) {
    $locale = shobidStripeNormalizeLocale($locale);
    $map = [
        'hu-hu' => 'HU',
        'de-de' => 'DE',
        'sk-sk' => 'SK',
        'uk-uk' => 'GB',
        'pl-pl' => 'PL',
        'cz-cz' => 'CZ',
        'hr-hr' => 'HR',
        'au-au' => 'AT',
        'ro-ro' => 'RO'
    ];
    return (string)($map[$locale] ?? 'HU');
}

function shobidStripeCurrencyByLocale($locale) {
    $locale = shobidStripeNormalizeLocale($locale);
    $map = [
        'hu-hu' => 'huf',
        'de-de' => 'eur',
        'sk-sk' => 'eur',
        'uk-uk' => 'gbp',
        'pl-pl' => 'pln',
        'cz-cz' => 'czk',
        'hr-hr' => 'eur',
        'au-au' => 'eur',
        'ro-ro' => 'ron'
    ];
    return (string)($map[$locale] ?? 'huf');
}

function shobidStripeNormalizeCurrency($currency, $fallback = 'huf') {
    $currency = strtolower(trim((string)$currency));
    if ($currency === '') {
        $currency = strtolower(trim((string)$fallback));
    }
    if ($currency === '') {
        $currency = 'huf';
    }
    return preg_replace('/[^a-z]/', '', $currency) ?: 'huf';
}

function shobidStripeZeroDecimalCurrencies() {
    return [
        'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
        'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
        'huf', 'twd'
    ];
}

function shobidStripeIsZeroDecimalCurrency($currency) {
    $currency = shobidStripeNormalizeCurrency($currency);
    return in_array($currency, shobidStripeZeroDecimalCurrencies(), true);
}

function shobidStripeCurrencySuffix($currency) {
    $currency = shobidStripeNormalizeCurrency($currency);
    $map = [
        'huf' => 'Ft',
        'eur' => 'EUR',
        'gbp' => 'GBP',
        'pln' => 'PLN',
        'czk' => 'CZK',
        'ron' => 'RON'
    ];
    return (string)($map[$currency] ?? strtoupper($currency));
}

function shobidStripeFormatAmountHuman($amountMajor, $currency) {
    $currency = shobidStripeNormalizeCurrency($currency);
    $decimals = shobidStripeIsZeroDecimalCurrency($currency) ? 0 : 2;
    $formatted = number_format(floatval($amountMajor), $decimals, ',', ' ');
    return $formatted . ' ' . shobidStripeCurrencySuffix($currency);
}

function shobidStripeLocaleForUser($conn, $userId = 0, $fallbackLocale = 'hu-hu') {
    $fallbackLocale = shobidStripeNormalizeLocale(
        $fallbackLocale,
        function_exists('shobidI18nCurrentLocale') ? shobidI18nCurrentLocale() : 'hu-hu'
    );
    $userId = intval($userId);

    if ($userId > 0 && function_exists('shobidMarketCodeForUser')) {
        return shobidMarketCodeForUser($conn, $userId, $fallbackLocale);
    }

    if ($userId > 0 && shobidStripeColumnExists($conn, 'felhasznalok', 'orszag_kod')) {
        $res = $conn->query("SELECT orszag_kod FROM felhasznalok WHERE id = $userId LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        $locale = trim((string)($row['orszag_kod'] ?? ''));
        if ($locale !== '') {
            return shobidStripeNormalizeLocale($locale, $fallbackLocale);
        }
    }

    return $fallbackLocale;
}

function shobidStripeEnsureTermekPenznemOszlop($conn) {
    static $done = false;
    if ($done) {
        return true;
    }
    $tableRes = $conn->query("SHOW TABLES LIKE 'termekek'");
    if (!($tableRes && $tableRes->num_rows > 0)) {
        $done = true;
        return false;
    }

    if (!shobidStripeColumnExists($conn, 'termekek', 'penznem')) {
        $conn->query("ALTER TABLE termekek ADD penznem VARCHAR(10) NOT NULL DEFAULT 'huf'");
    }

    if (shobidStripeColumnExists($conn, 'termekek', 'orszag_kod')) {
        $conn->query("UPDATE termekek
            SET penznem = CASE LOWER(TRIM(orszag_kod))
                WHEN 'de-de' THEN 'eur'
                WHEN 'sk-sk' THEN 'eur'
                WHEN 'uk-uk' THEN 'gbp'
                WHEN 'pl-pl' THEN 'pln'
                WHEN 'cz-cz' THEN 'czk'
                WHEN 'hr-hr' THEN 'eur'
                WHEN 'au-au' THEN 'eur'
                WHEN 'ro-ro' THEN 'ron'
                ELSE 'huf'
            END
            WHERE penznem IS NULL OR TRIM(penznem) = ''");
    }
    $conn->query("UPDATE termekek SET penznem = LOWER(TRIM(penznem)) WHERE penznem IS NOT NULL");
    $done = true;
    return true;
}

function shobidStripeTermekLocale($termek, $fallbackLocale = 'hu-hu') {
    $fallbackLocale = shobidStripeNormalizeLocale($fallbackLocale);
    if (!is_array($termek)) {
        return $fallbackLocale;
    }
    $fromTermek = trim((string)($termek['orszag_kod'] ?? ''));
    if ($fromTermek !== '') {
        return shobidStripeNormalizeLocale($fromTermek, $fallbackLocale);
    }
    return $fallbackLocale;
}

function shobidStripeTermekCurrency($termek, $fallbackLocale = 'hu-hu') {
    if (is_array($termek)) {
        $stored = trim((string)($termek['penznem'] ?? $termek['currency'] ?? ''));
        if ($stored !== '') {
            return shobidStripeNormalizeCurrency($stored, shobidStripeCurrencyByLocale($fallbackLocale));
        }
    }
    $locale = shobidStripeTermekLocale($termek, $fallbackLocale);
    return shobidStripeCurrencyByLocale($locale);
}

function shobidStripeTermekCurrencyById($conn, $termekId, $fallbackLocale = 'hu-hu') {
    $termekId = intval($termekId);
    if ($termekId < 1) {
        return shobidStripeCurrencyByLocale($fallbackLocale);
    }
    shobidStripeEnsureTermekPenznemOszlop($conn);

    $selectCols = "orszag_kod";
    if (shobidStripeColumnExists($conn, 'termekek', 'penznem')) {
        $selectCols .= ", penznem";
    }
    $res = $conn->query("SELECT $selectCols FROM termekek WHERE id = $termekId LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) {
        return shobidStripeCurrencyByLocale($fallbackLocale);
    }
    return shobidStripeTermekCurrency($row, $fallbackLocale);
}

function shobidStripeConfigElerheto() {
    return defined('SHOBID_STRIPE_SECRET_KEY')
        && defined('SHOBID_STRIPE_PUBLISHABLE_KEY')
        && defined('SHOBID_STRIPE_WEBHOOK_SECRET')
        && trim((string) SHOBID_STRIPE_SECRET_KEY) !== ''
        && trim((string) SHOBID_STRIPE_PUBLISHABLE_KEY) !== ''
        && trim((string) SHOBID_STRIPE_WEBHOOK_SECRET) !== '';
}

function shobidStripeEnsureCheckoutTabla($conn) {
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = (bool) $conn->query("CREATE TABLE IF NOT EXISTS stripe_checkout_sessions (
        id INT(11) NOT NULL AUTO_INCREMENT,
        local_order_id VARCHAR(191) NOT NULL,
        stripe_session_id VARCHAR(191) NOT NULL,
        stripe_payment_intent_id VARCHAR(191) NULL,
        termek_id INT(11) NOT NULL,
        buyer_id INT(11) NOT NULL,
        amount_minor BIGINT NOT NULL DEFAULT 0,
        amount_huf INT(11) NOT NULL DEFAULT 0,
        currency VARCHAR(10) NOT NULL DEFAULT 'huf',
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_local_order_id (local_order_id),
        UNIQUE KEY uniq_stripe_session_id (stripe_session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    if ($ok && !shobidStripeColumnExists($conn, 'stripe_checkout_sessions', 'amount_minor')) {
        $conn->query("ALTER TABLE stripe_checkout_sessions ADD amount_minor BIGINT NOT NULL DEFAULT 0");
        if (shobidStripeColumnExists($conn, 'stripe_checkout_sessions', 'amount_huf')) {
            $conn->query("UPDATE stripe_checkout_sessions SET amount_minor = amount_huf WHERE amount_minor = 0");
        }
    }
    if ($ok && !shobidStripeColumnExists($conn, 'stripe_checkout_sessions', 'currency')) {
        $conn->query("ALTER TABLE stripe_checkout_sessions ADD currency VARCHAR(10) NOT NULL DEFAULT 'huf'");
    }
    return $ok;
}

function shobidStripeEnsureAutoChargeTabla($conn) {
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = (bool) $conn->query("CREATE TABLE IF NOT EXISTS stripe_payment_records (
        azonosito VARCHAR(191) NOT NULL,
        stripe_payment_intent_id VARCHAR(191) NULL,
        user_id INT(11) NOT NULL DEFAULT 0,
        termek_id INT(11) NOT NULL DEFAULT 0,
        amount_minor BIGINT NOT NULL DEFAULT 0,
        amount_huf INT(11) NOT NULL DEFAULT 0,
        currency VARCHAR(10) NOT NULL DEFAULT 'huf',
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
        hiba TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (azonosito)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    if ($ok && !shobidStripeColumnExists($conn, 'stripe_payment_records', 'amount_minor')) {
        $conn->query("ALTER TABLE stripe_payment_records ADD amount_minor BIGINT NOT NULL DEFAULT 0");
        if (shobidStripeColumnExists($conn, 'stripe_payment_records', 'amount_huf')) {
            $conn->query("UPDATE stripe_payment_records SET amount_minor = amount_huf WHERE amount_minor = 0");
        }
    }
    if ($ok && !shobidStripeColumnExists($conn, 'stripe_payment_records', 'currency')) {
        $conn->query("ALTER TABLE stripe_payment_records ADD currency VARCHAR(10) NOT NULL DEFAULT 'huf'");
    }
    return $ok;
}

function shobidStripeEnsureFelhasznaloFizetesiOszlopok($conn) {
    static $done = false;
    if ($done) {
        return true;
    }

    $oszlopok = [
        'stripe_customer_id' => "ALTER TABLE felhasznalok ADD stripe_customer_id VARCHAR(191) NULL",
        'stripe_payment_method_id' => "ALTER TABLE felhasznalok ADD stripe_payment_method_id VARCHAR(191) NULL",
        'stripe_setup_complete_at' => "ALTER TABLE felhasznalok ADD stripe_setup_complete_at DATETIME NULL"
    ];

    foreach ($oszlopok as $oszlop => $sql) {
        $res = $conn->query("SHOW COLUMNS FROM felhasznalok LIKE '" . $conn->real_escape_string($oszlop) . "'");
        if (!$res || $res->num_rows === 0) {
            $conn->query($sql);
        }
    }

    $done = true;
    return true;
}

function shobidStripeEnsureFelhasznaloConnectOszlopok($conn) {
    static $done = false;
    if ($done) {
        return true;
    }

    $oszlopok = [
        'stripe_connect_account_id' => "ALTER TABLE felhasznalok ADD stripe_connect_account_id VARCHAR(191) NULL",
        'stripe_connect_country' => "ALTER TABLE felhasznalok ADD stripe_connect_country VARCHAR(2) NULL",
        'stripe_connect_details_submitted_at' => "ALTER TABLE felhasznalok ADD stripe_connect_details_submitted_at DATETIME NULL",
        'stripe_connect_onboarded_at' => "ALTER TABLE felhasznalok ADD stripe_connect_onboarded_at DATETIME NULL",
        'stripe_connect_charges_enabled' => "ALTER TABLE felhasznalok ADD stripe_connect_charges_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'stripe_connect_payouts_enabled' => "ALTER TABLE felhasznalok ADD stripe_connect_payouts_enabled TINYINT(1) NOT NULL DEFAULT 0"
    ];

    foreach ($oszlopok as $oszlop => $sql) {
        $res = $conn->query("SHOW COLUMNS FROM felhasznalok LIKE '" . $conn->real_escape_string($oszlop) . "'");
        if (!$res || $res->num_rows === 0) {
            $conn->query($sql);
        }
    }

    $done = true;
    return true;
}

function shobidStripeConnectSummary($conn, $userId) {
    shobidStripeEnsureFelhasznaloConnectOszlopok($conn);
    $userId = intval($userId);
    $summary = [
        'account_id' => '',
        'charges_enabled' => false,
        'payouts_enabled' => false,
        'details_submitted' => false,
        'ready' => false
    ];
    if ($userId < 1) {
        return $summary;
    }

    $res = $conn->query("SELECT stripe_connect_account_id, stripe_connect_details_submitted_at, stripe_connect_onboarded_at, stripe_connect_charges_enabled, stripe_connect_payouts_enabled
        FROM felhasznalok WHERE id = $userId LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) {
        return $summary;
    }

    $summary['account_id'] = trim((string)($row['stripe_connect_account_id'] ?? ''));
    $summary['charges_enabled'] = intval($row['stripe_connect_charges_enabled'] ?? 0) === 1;
    $summary['payouts_enabled'] = intval($row['stripe_connect_payouts_enabled'] ?? 0) === 1;
    $summary['details_submitted'] = trim((string)($row['stripe_connect_details_submitted_at'] ?? '')) !== '';
    $summary['ready'] = $summary['payouts_enabled'] && $summary['account_id'] !== '';

    return $summary;
}

function shobidStripeFelhasznaloFizetesKesz($conn, $userId) {
    shobidStripeEnsureFelhasznaloFizetesiOszlopok($conn);
    $userId = intval($userId);
    if ($userId < 1) {
        return false;
    }

    $res = $conn->query("SELECT stripe_customer_id, stripe_payment_method_id FROM felhasznalok WHERE id = $userId LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) {
        return false;
    }

    return trim((string)($row['stripe_customer_id'] ?? '')) !== ''
        && trim((string)($row['stripe_payment_method_id'] ?? '')) !== '';
}

function shobidStripeBiztositsConnectAccount($conn, $userId, array $userAdat = []) {
    shobidStripeEnsureFelhasznaloConnectOszlopok($conn);

    $userId = intval($userId);
    if ($userId < 1) {
        return ['ok' => false, 'error' => 'HibĂˇs felhasznĂˇlĂł'];
    }

    $userSelect = "stripe_connect_account_id, stripe_connect_country, becenev, email, teljes_nev";
    if (shobidStripeColumnExists($conn, 'felhasznalok', 'orszag_kod')) {
        $userSelect .= ", orszag_kod";
    }
    $res = $conn->query("SELECT $userSelect FROM felhasznalok WHERE id = $userId LIMIT 1");
    $user = $res ? $res->fetch_assoc() : null;
    if (!$user) {
        return ['ok' => false, 'error' => 'FelhasznĂˇlĂł nem talĂˇlhatĂł'];
    }

    $meglevo = trim((string)($user['stripe_connect_account_id'] ?? ''));
    $storedCountry = strtoupper(trim((string)($user['stripe_connect_country'] ?? '')));
    $userLocale = shobidStripeLocaleForUser($conn, $userId, (string)($user['orszag_kod'] ?? 'hu-hu'));
    $connectCountry = preg_match('/^[A-Z]{2}$/', $storedCountry)
        ? $storedCountry
        : shobidStripeCountryByLocale($userLocale);
    if ($meglevo !== '') {
        if (!preg_match('/^[A-Z]{2}$/', $storedCountry)) {
            $countrySql = $conn->real_escape_string($connectCountry);
            $conn->query("UPDATE felhasznalok SET stripe_connect_country = '$countrySql' WHERE id = $userId");
        }
        return ['ok' => true, 'account_id' => $meglevo];
    }

    $nev = trim((string)($userAdat['teljes_nev'] ?? $user['teljes_nev'] ?? $user['becenev'] ?? ''));
    $email = trim((string)($userAdat['email'] ?? $user['email'] ?? ''));

    $accountRes = shobidStripeApiRequest('POST', 'accounts', [
        'type' => 'express',
        'country' => $connectCountry,
        'email' => $email,
        'business_type' => 'individual',
        'capabilities' => [
            'transfers' => ['requested' => true]
        ],
        'business_profile' => [
            'product_description' => 'Shobid eladĂłi kifizetĂ©si fiĂłk'
        ],
        'metadata' => [
            'local_user_id' => (string)$userId,
            'becenev' => trim((string)($user['becenev'] ?? '')),
            'nev' => $nev
        ]
    ]);

    if (empty($accountRes['ok'])) {
        return $accountRes;
    }

    $accountId = trim((string)($accountRes['data']['id'] ?? ''));
    if ($accountId === '') {
        return ['ok' => false, 'error' => 'Stripe kifizetĂ©si fiĂłk nem jĂ¶tt lĂ©tre'];
    }

    $accountIdSql = $conn->real_escape_string($accountId);
    $countrySql = $conn->real_escape_string($connectCountry);
    $conn->query("UPDATE felhasznalok SET stripe_connect_account_id = '$accountIdSql', stripe_connect_country = '$countrySql' WHERE id = $userId");

    return ['ok' => true, 'account_id' => $accountId];
}

function shobidStripeSyncConnectAccount($conn, $userId) {
    shobidStripeEnsureFelhasznaloConnectOszlopok($conn);

    $userId = intval($userId);
    if ($userId < 1) {
        return false;
    }

    $res = $conn->query("SELECT stripe_connect_account_id FROM felhasznalok WHERE id = $userId LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    $accountId = trim((string)($row['stripe_connect_account_id'] ?? ''));
    if ($accountId === '') {
        return false;
    }

    $accountRes = shobidStripeApiRequest('GET', 'accounts/' . rawurlencode($accountId));
    if (empty($accountRes['ok'])) {
        return false;
    }

    $account = $accountRes['data'] ?? [];
    $chargesEnabled = !empty($account['charges_enabled']) ? 1 : 0;
    $payoutsEnabled = !empty($account['payouts_enabled']) ? 1 : 0;
    $detailsSubmitted = !empty($account['details_submitted']);

    $conn->query("UPDATE felhasznalok
        SET stripe_connect_charges_enabled = $chargesEnabled,
            stripe_connect_payouts_enabled = $payoutsEnabled,
            stripe_connect_details_submitted_at = " . ($detailsSubmitted ? "NOW()" : "stripe_connect_details_submitted_at") . ",
            stripe_connect_onboarded_at = " . ($payoutsEnabled ? "NOW()" : "stripe_connect_onboarded_at") . "
        WHERE id = $userId");

    return true;
}

function shobidStripeConnectOnboardingLink($conn, $userId) {
    $accountRes = shobidStripeBiztositsConnectAccount($conn, $userId);
    if (empty($accountRes['ok'])) {
        return $accountRes;
    }

    $accountId = trim((string)($accountRes['account_id'] ?? ''));
    if ($accountId === '') {
        return ['ok' => false, 'error' => 'Stripe kifizetĂ©si fiĂłk azonosĂ­tĂł hiĂˇnyzik'];
    }

    $callbackLocale = shobidStripeLocaleForUser($conn, $userId);
    $linkRes = shobidStripeApiRequest('POST', 'account_links', [
        'account' => $accountId,
        'refresh_url' => shobidStripeAbsUrl($callbackLocale . '/profil.php?stripe_connect=refresh#bankkartya'),
        'return_url' => shobidStripeAbsUrl($callbackLocale . '/profil.php?stripe_connect=return#bankkartya'),
        'type' => 'account_onboarding'
    ]);

    if (empty($linkRes['ok'])) {
        return $linkRes;
    }

    $url = trim((string)($linkRes['data']['url'] ?? ''));
    if ($url === '') {
        return ['ok' => false, 'error' => 'Stripe onboarding link nem jĂ¶tt lĂ©tre'];
    }

    return ['ok' => true, 'url' => $url, 'account_id' => $accountId];
}

function shobidStripeConnectDashboardLink($conn, $userId) {
    shobidStripeEnsureFelhasznaloConnectOszlopok($conn);

    $userId = intval($userId);
    if ($userId < 1) {
        return ['ok' => false, 'error' => 'HiĂˇnyzĂł felhasznĂˇlĂł.'];
    }

    shobidStripeSyncConnectAccount($conn, $userId);
    $summary = shobidStripeConnectSummary($conn, $userId);
    $accountId = trim((string)($summary['account_id'] ?? ''));
    if ($accountId === '') {
        return ['ok' => false, 'error' => 'MĂ©g nincs csatlakoztatott Stripe kifizetĂ©si fiĂłk.'];
    }

    $loginRes = shobidStripeApiRequest('POST', 'accounts/' . rawurlencode($accountId) . '/login_links');
    if (empty($loginRes['ok'])) {
        return $loginRes;
    }

    $url = trim((string)($loginRes['data']['url'] ?? ''));
    if ($url === '') {
        return ['ok' => false, 'error' => 'A Stripe dashboard link nem jĂ¶tt lĂ©tre.'];
    }

    return ['ok' => true, 'url' => $url, 'account_id' => $accountId];
}

function shobidStripeBaseUrl() {
    return 'https://shobid.com';
}

function shobidStripeAbsUrl($path) {
    return rtrim(shobidStripeBaseUrl(), '/') . '/' . ltrim($path, '/');
}

function shobidStripeCurrencyAmount($amount, $currency = 'huf') {
    $currency = shobidStripeNormalizeCurrency($currency);
    $majorAmount = max(0, floatval($amount));
    if (shobidStripeIsZeroDecimalCurrency($currency)) {
        return intval(round($majorAmount));
    }
    return intval(round($majorAmount * 100));
}

function shobidStripeChargeAmountMinor($amount, $currency = 'huf') {
    $currency = shobidStripeNormalizeCurrency($currency);
    $majorAmount = max(0, floatval($amount));
    // Stripe charge special-case: HUF card charges are sent with 2 decimal scaling.
    if ($currency === 'huf') {
        return intval(round($majorAmount * 100));
    }
    return shobidStripeCurrencyAmount($majorAmount, $currency);
}

function shobidStripeChargeMinimumMinor($currency = 'huf') {
    $currency = shobidStripeNormalizeCurrency($currency);
    if ($currency === 'huf') {
        return 17500;
    }
    return 1;
}

function shobidStripeParseIntAmount($value) {
    if (is_int($value) || is_float($value)) {
        return max(0, intval(round(floatval($value))));
    }
    $raw = trim((string)$value);
    if ($raw === '') {
        return 0;
    }
    $digits = preg_replace('/[^0-9]/', '', $raw);
    if ($digits === '') {
        return 0;
    }
    return max(0, intval($digits));
}

function shobidStripeResolveFixBaseAmount($conn, $termek) {
    $fixAr = shobidStripeParseIntAmount($termek['fix_ar'] ?? 0);
    $aktualisAr = shobidStripeParseIntAmount($termek['aktualis_ar'] ?? 0);
    $base = max($fixAr, $aktualisAr);

    $termekId = intval($termek['id'] ?? 0);
    if ($termekId > 0) {
        $res = $conn->query("SELECT CAST(fix_ar AS CHAR) AS fix_ar_txt, CAST(aktualis_ar AS CHAR) AS aktualis_ar_txt FROM termekek WHERE id = $termekId LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if (is_array($row)) {
            $fixTxt = shobidStripeParseIntAmount($row['fix_ar_txt'] ?? 0);
            $aktTxt = shobidStripeParseIntAmount($row['aktualis_ar_txt'] ?? 0);
            $base = max($base, $fixTxt, $aktTxt);
        }
    }

    return max(0, intval($base));
}

function shobidStripeResolveFixPayableAmount($conn, $termek) {
    $clientMinAmount = shobidStripeParseIntAmount($termek['__client_min_amount'] ?? 0);
    $termekId = intval($termek['id'] ?? 0);
    if ($termekId > 0) {
        $sanitizeFix = "CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(COALESCE(CAST(fix_ar AS CHAR), '0')), 'ft', ''), ' ', ''), '.', ''), ',', ''), \"'\", '') AS UNSIGNED)";
        $sanitizeAkt = "CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(COALESCE(CAST(aktualis_ar AS CHAR), '0')), 'ft', ''), ' ', ''), '.', ''), ',', ''), \"'\", '') AS UNSIGNED)";
        $sanitizeDij = "CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(COALESCE(CAST(szallitasi_dij AS CHAR), '0')), 'ft', ''), ' ', ''), '.', ''), ',', ''), \"'\", '') AS UNSIGNED)";
        $res = $conn->query("SELECT
                GREATEST($sanitizeFix, $sanitizeAkt) AS base_ar,
                COALESCE(szallitasi_mod, '') AS szall_mod,
                $sanitizeDij AS szall_dij
            FROM termekek
            WHERE id = $termekId
            LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if (is_array($row)) {
            $base = shobidStripeParseIntAmount($row['base_ar'] ?? 0);
            $mod = trim((string)($row['szall_mod'] ?? ''));
            $dij = shobidStripeParseIntAmount($row['szall_dij'] ?? 0);
            $resolved = shobidPayoutBruttoWithShipping($base, $mod, $dij);
            if ($clientMinAmount > 0) {
                $resolved = max($resolved, $clientMinAmount);
            }
            return $resolved;
        }
    }

    $base = shobidStripeResolveFixBaseAmount($conn, $termek);
    $mod = (string)($termek['szallitasi_mod'] ?? '');
    $dij = shobidStripeParseIntAmount($termek['szallitasi_dij'] ?? 0);
    $resolved = shobidPayoutBruttoWithShipping($base, $mod, $dij);
    if ($clientMinAmount > 0) {
        $resolved = max($resolved, $clientMinAmount);
    }
    return $resolved;
}

function shobidStripeFlattenParams($value, $prefix = '') {
    $result = [];
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $newPrefix = $prefix === '' ? (string)$key : $prefix . '[' . $key . ']';
            $result += shobidStripeFlattenParams($item, $newPrefix);
        }
        return $result;
    }
    if (is_bool($value)) {
        $value = $value ? 'true' : 'false';
    }
    $result[$prefix] = $value;
    return $result;
}

function shobidStripeApiRequest($method, $path, array $params = [], array $extraHeaders = []) {
    if (!shobidStripeConfigElerheto()) {
        return ['ok' => false, 'error' => 'Stripe config hianyzik'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'A szerveren nincs engedelyezve a cURL, ezert a Stripe kapcsolat nem indithato el'];
    }

    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    if (strtoupper($method) === 'GET' && !empty($params)) {
        $url .= '?' . http_build_query(shobidStripeFlattenParams($params));
    }

    $headers = [
        'Authorization: Bearer ' . SHOBID_STRIPE_SECRET_KEY
    ];
    foreach ($extraHeaders as $extraHeader) {
        $extraHeader = trim((string)$extraHeader);
        if ($extraHeader !== '') {
            $headers[] = $extraHeader;
        }
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if (strtoupper($method) !== 'GET' && !empty($params)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(shobidStripeFlattenParams($params)));
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);

    if ($errno) {
        return ['ok' => false, 'error' => $error ?: 'Stripe kapcsolat hiba'];
    }

    $data = json_decode((string)$raw, true);
    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'error' => trim((string)($data['error']['message'] ?? 'Stripe hiba')),
            'status' => $status,
            'raw' => $raw
        ];
    }

    return ['ok' => true, 'data' => is_array($data) ? $data : []];
}

function shobidStripeTransferToSellerAccount($conn, $eladoId, $amountFt, $description = '', array $metadata = [], $currency = null) {
    $eladoId = intval($eladoId);
    $amountFt = round(max(0, floatval($amountFt)), 2);
    if ($eladoId < 1 || $amountFt <= 0) {
        return ['ok' => false, 'error' => 'HibĂˇs transfer adatok'];
    }

    shobidStripeSyncConnectAccount($conn, $eladoId);
    $connect = shobidStripeConnectSummary($conn, $eladoId);
    if (empty($connect['account_id'])) {
        return ['ok' => false, 'error' => 'Az eladĂłhoz mĂ©g nincs csatlakoztatott Stripe kifizetĂ©si fiĂłk.', 'reason' => 'connect_missing'];
    }
    if (empty($connect['ready'])) {
        return ['ok' => false, 'error' => 'Az eladĂł Stripe kifizetĂ©si fiĂłkja mĂ©g nincs teljesen beĂˇllĂ­tva.', 'reason' => 'connect_incomplete'];
    }

    $sellerLocale = shobidStripeLocaleForUser($conn, $eladoId);
    $resolvedCurrency = shobidStripeNormalizeCurrency(
        $currency !== null ? $currency : shobidStripeCurrencyByLocale($sellerLocale)
    );

    $params = [
        // HUF transfers use the same minor-unit scaling as our charge flow.
        'amount' => shobidStripeChargeAmountMinor($amountFt, $resolvedCurrency),
        'currency' => $resolvedCurrency,
        'destination' => $connect['account_id'],
        'metadata' => array_merge($metadata, [
            'local_seller_id' => (string)$eladoId,
            'currency' => $resolvedCurrency
        ])
    ];

    $description = trim((string)$description);
    if ($description !== '') {
        $params['description'] = $description;
    }

    $transferRes = shobidStripeApiRequest('POST', 'transfers', $params);
    if (empty($transferRes['ok'])) {
        return $transferRes;
    }

    $transferId = trim((string)($transferRes['data']['id'] ?? ''));
    if ($transferId === '') {
        return ['ok' => false, 'error' => 'A Stripe transfer nem jĂ¶tt lĂ©tre.'];
    }

    return ['ok' => true, 'transfer_id' => $transferId, 'account_id' => $connect['account_id'], 'currency' => $resolvedCurrency];
}

function shobidStripeAutoChargeRecord($conn, $azonosito) {
    if (!shobidStripeEnsureAutoChargeTabla($conn)) {
        return null;
    }
    $azonositoSql = $conn->real_escape_string(trim((string)$azonosito));
    if ($azonositoSql === '') {
        return null;
    }
    $res = $conn->query("SELECT * FROM stripe_payment_records WHERE azonosito = '$azonositoSql' LIMIT 1");
    return $res ? $res->fetch_assoc() : null;
}

function shobidStripeSaveAutoChargeRecord($conn, $azonosito, $userId, $termekId, $amountHuf, $status, $paymentIntentId = '', $hiba = '', $currency = 'huf') {
    if (!shobidStripeEnsureAutoChargeTabla($conn)) {
        return false;
    }
    $azonositoSql = $conn->real_escape_string(trim((string)$azonosito));
    if ($azonositoSql === '') {
        return false;
    }
    $userId = intval($userId);
    $termekId = intval($termekId);
    $amountMajor = intval($amountHuf);
    $currencyNormalized = shobidStripeNormalizeCurrency($currency);
    $currencySql = $conn->real_escape_string($currencyNormalized);
    $amountMinor = shobidStripeCurrencyAmount($amountMajor, $currencyNormalized);
    $statusSql = $conn->real_escape_string(trim((string)$status));
    $intentSql = $conn->real_escape_string(trim((string)$paymentIntentId));
    $hibaSql = $conn->real_escape_string(trim((string)$hiba));
    $conn->query("INSERT INTO stripe_payment_records (azonosito, stripe_payment_intent_id, user_id, termek_id, amount_minor, amount_huf, currency, status, hiba)
        VALUES ('$azonositoSql', " . ($intentSql !== '' ? "'$intentSql'" : "NULL") . ", $userId, $termekId, $amountMinor, $amountMajor, '$currencySql', '$statusSql', " . ($hibaSql !== '' ? "'$hibaSql'" : "NULL") . ")
        ON DUPLICATE KEY UPDATE
            stripe_payment_intent_id = " . ($intentSql !== '' ? "'$intentSql'" : "stripe_payment_intent_id") . ",
            user_id = VALUES(user_id),
            termek_id = VALUES(termek_id),
            amount_minor = VALUES(amount_minor),
            amount_huf = VALUES(amount_huf),
            currency = VALUES(currency),
            status = VALUES(status),
            hiba = " . ($hibaSql !== '' ? "'$hibaSql'" : "NULL"));
    return true;
}

function shobidStripeAutoChargeByNickname($conn, $azonosito, $vevoBecenev, $termekId, $osszeg, $leiras, array $metadata = []) {
    $azonosito = trim((string)$azonosito);
    $vevoBecenev = trim((string)$vevoBecenev);
    $termekId = intval($termekId);
    $osszeg = intval($osszeg);

    if ($azonosito === '' || $vevoBecenev === '' || $termekId < 1 || $osszeg < 1) {
        return ['ok' => false, 'error' => 'Hibas automatikus terhelesi adatok'];
    }
    $currency = shobidStripeTermekCurrencyById(
        $conn,
        $termekId,
        function_exists('shobidI18nCurrentLocale') ? shobidI18nCurrentLocale() : 'hu-hu'
    );

    $letezo = shobidStripeAutoChargeRecord($conn, $azonosito);
    if ($letezo) {
        $status = trim((string)($letezo['status'] ?? ''));
        if ($status === 'completed') {
            return [
                'ok' => true,
                'already_completed' => true,
                'payment_intent_id' => trim((string)($letezo['stripe_payment_intent_id'] ?? ''))
            ];
        }
        if ($status === 'failed') {
            // Failed charge should be retryable on the next close-check cycle.
        }
    }

    $buyer = shobidFelhasznaloByBecenev($conn, $vevoBecenev);
    if (!$buyer) {
        shobidStripeSaveAutoChargeRecord($conn, $azonosito, 0, $termekId, $osszeg, 'failed', '', 'A nyertes felhasznalo nem talalhato.', $currency);
        return ['ok' => false, 'error' => 'A nyertes felhasznalo nem talalhato.'];
    }

    $buyerId = intval($buyer['id'] ?? 0);
    $customerId = trim((string)($buyer['stripe_customer_id'] ?? ''));
    $paymentMethodId = trim((string)($buyer['stripe_payment_method_id'] ?? ''));
    if ($buyerId < 1 || $customerId === '' || $paymentMethodId === '') {
        shobidStripeSaveAutoChargeRecord($conn, $azonosito, $buyerId, $termekId, $osszeg, 'failed', '', 'A nyertesnel nincs mentett bankkartya.', $currency);
        return ['ok' => false, 'error' => 'A nyertesnel nincs mentett bankkartya.'];
    }

    $metadata = array_merge($metadata, [
        'charge_key' => $azonosito,
        'termek_id' => (string)$termekId,
        'buyer_id' => (string)$buyerId,
        'buyer_name' => $vevoBecenev
    ]);

    $amountMinor = shobidStripeChargeAmountMinor($osszeg, $currency);
    $amountMinor = max(shobidStripeChargeMinimumMinor($currency), intval($amountMinor));

    $intentRes = shobidStripeApiRequest('POST', 'payment_intents', [
        'amount' => $amountMinor,
        'currency' => $currency,
        'customer' => $customerId,
        'payment_method' => $paymentMethodId,
        'confirm' => 'true',
        'off_session' => 'true',
        'description' => trim((string)$leiras),
        'metadata' => $metadata
    ]);

    if (empty($intentRes['ok'])) {
        $hiba = trim((string)($intentRes['error'] ?? 'Stripe terhelĂ©si hiba'));
        if (stripos($hiba, 'authentication') !== false || stripos($hiba, 'requires_action') !== false) {
            $hiba = 'A bankkĂˇrtya tovĂˇbbi jĂłvĂˇhagyĂˇst kĂ©r, ezĂ©rt a nyertes licitet most nem tudtuk automatikusan levonni.';
        }
        $hiba .= ' [amount_minor=' . intval($amountMinor) . ' currency=' . strtoupper((string)$currency) . ']';
        shobidStripeSaveAutoChargeRecord($conn, $azonosito, $buyerId, $termekId, $osszeg, 'failed', '', $hiba, $currency);
        return ['ok' => false, 'error' => $hiba];
    }

    $intent = $intentRes['data'] ?? [];
    $paymentIntentId = trim((string)($intent['id'] ?? ''));
    $status = trim((string)($intent['status'] ?? ''));
    if ($paymentIntentId === '' || $status !== 'succeeded') {
        $hiba = 'A Stripe fizetĂ©s nem lett sikeres.';
        shobidStripeSaveAutoChargeRecord($conn, $azonosito, $buyerId, $termekId, $osszeg, 'failed', $paymentIntentId, $hiba, $currency);
        return ['ok' => false, 'error' => $hiba];
    }

    shobidStripeSaveAutoChargeRecord($conn, $azonosito, $buyerId, $termekId, $osszeg, 'completed', $paymentIntentId, '', $currency);
    return ['ok' => true, 'payment_intent_id' => $paymentIntentId];
}

function shobidStripeFixDirectPayment($conn, $termek, $buyerId, $buyerName = '') {
    shobidStripeEnsureFelhasznaloFizetesiOszlopok($conn);
    shobidStripeEnsureCheckoutTabla($conn);

    $termekId = intval($termek['id'] ?? 0);
    $buyerId = intval($buyerId);
    $osszeg = shobidStripeResolveFixPayableAmount($conn, $termek);
    if ($termekId < 1 || $buyerId < 1 || $osszeg < 1) {
        return ['ok' => false, 'error' => 'Hibas fizetesi adatok'];
    }

    $buyerRes = $conn->query("SELECT stripe_customer_id, stripe_payment_method_id FROM felhasznalok WHERE id = $buyerId LIMIT 1");
    $buyer = $buyerRes ? $buyerRes->fetch_assoc() : null;
    $customerId = trim((string)($buyer['stripe_customer_id'] ?? ''));
    $paymentMethodId = trim((string)($buyer['stripe_payment_method_id'] ?? ''));
    if ($customerId === '' || $paymentMethodId === '') {
        return ['ok' => false, 'error' => 'A vĂˇsĂˇrlĂˇshoz elĹ‘bb ments bankkĂˇrtyĂˇt a FizetĂ©si beĂˇllĂ­tĂˇsok rĂ©sznĂ©l.'];
    }

    $termekNev = trim((string)($termek['nev'] ?? 'AjĂˇnlat'));
    $currency = shobidStripeTermekCurrency($termek, function_exists('shobidI18nCurrentLocale') ? shobidI18nCurrentLocale() : 'hu-hu');
    $stripeOsszeg = shobidStripeChargeAmountMinor($osszeg, $currency);
    $stripeOsszeg = max(shobidStripeChargeMinimumMinor($currency), intval($stripeOsszeg));
    $localOrderId = 'fix_direct_' . $termekId . '_' . $buyerId . '_' . time() . '_' . mt_rand(1000, 9999);

    $intentRes = shobidStripeApiRequest('POST', 'payment_intents', [
        'amount' => $stripeOsszeg,
        'currency' => $currency,
        'customer' => $customerId,
        'payment_method' => $paymentMethodId,
        'confirm' => 'true',
        'off_session' => 'true',
        'description' => $termekNev,
        'metadata' => [
            'local_order_id' => $localOrderId,
            'termek_id' => (string)$termekId,
            'buyer_id' => (string)$buyerId,
            'buyer_name' => trim((string)$buyerName),
            'currency' => $currency
        ]
    ]);

    if (empty($intentRes['ok'])) {
        $stripeHiba = trim((string)($intentRes['error'] ?? 'Stripe terhelĂ©si hiba'));
        if (stripos($stripeHiba, 'authentication') !== false || stripos($stripeHiba, 'requires_action') !== false) {
            return ['ok' => false, 'error' => 'A bankkĂˇrtya tovĂˇbbi jĂłvĂˇhagyĂˇst kĂ©r, ezĂ©rt ezt a vĂˇsĂˇrlĂˇst most nem tudtuk automatikusan levonni.'];
        }
        return ['ok' => false, 'error' => $stripeHiba];
    }

    $intent = $intentRes['data'] ?? [];
    $paymentIntentId = trim((string)($intent['id'] ?? ''));
    $status = trim((string)($intent['status'] ?? ''));
    if ($paymentIntentId === '') {
        return ['ok' => false, 'error' => 'A Stripe terhelĂ©s nem jĂ¶tt lĂ©tre.'];
    }

    if ($status !== 'succeeded') {
        return ['ok' => false, 'error' => 'A Stripe fizetĂ©s nem lett sikeres.'];
    }

    $vasarlas = shobidDirektFixVasarlas($conn, $termekId, $buyerId);
    if (empty($vasarlas['ok'])) {
        shobidStripeApiRequest('POST', 'refunds', [
            'payment_intent' => $paymentIntentId,
            'reason' => 'requested_by_customer',
            'metadata' => [
                'local_order_id' => $localOrderId,
                'termek_id' => (string)$termekId,
                'buyer_id' => (string)$buyerId
            ]
        ]);
        return ['ok' => false, 'error' => trim((string)($vasarlas['error'] ?? 'A vĂˇsĂˇrlĂˇst nem sikerĂĽlt vĂ©glegesĂ­teni, a terhelĂ©st visszafordĂ­tottuk.'))];
    }

    $localOrderSql = $conn->real_escape_string($localOrderId);
    $intentSql = $conn->real_escape_string($paymentIntentId);
    $currencySql = $conn->real_escape_string($currency);
    $conn->query("INSERT INTO stripe_checkout_sessions (local_order_id, stripe_session_id, stripe_payment_intent_id, termek_id, buyer_id, amount_minor, amount_huf, currency, status, completed_at)
        VALUES ('$localOrderSql', '$intentSql', '$intentSql', $termekId, $buyerId, $stripeOsszeg, $osszeg, '$currencySql', 'completed', NOW())
        ON DUPLICATE KEY UPDATE
            stripe_payment_intent_id = '$intentSql',
            status = 'completed',
            completed_at = NOW()");

    return [
        'ok' => true,
        'payment_intent_id' => $paymentIntentId,
        'uzenet_id' => intval($vasarlas['uzenet_id'] ?? 0)
    ];
}

function shobidStripeFixCheckoutSessionLetrehoz($conn, $termek, $buyerId, $buyerName) {
    if (!shobidStripeEnsureCheckoutTabla($conn)) {
        return ['ok' => false, 'error' => 'Stripe tabla nem hozhato letre'];
    }

    $termekId = intval($termek['id'] ?? 0);
    $buyerId = intval($buyerId);
    $osszeg = shobidStripeResolveFixPayableAmount($conn, $termek);
    if ($termekId < 1 || $buyerId < 1 || $osszeg < 1) {
        return ['ok' => false, 'error' => 'Hibas checkout adatok'];
    }

    $localOrderId = 'fix_' . $termekId . '_' . $buyerId . '_' . time() . '_' . mt_rand(1000, 9999);
    $termekNev = trim((string)($termek['nev'] ?? 'AjĂˇnlat'));
    $buyerName = trim((string)$buyerName);
    $fallbackLocale = function_exists('shobidI18nCurrentLocale') ? shobidI18nCurrentLocale() : 'hu-hu';
    $callbackLocale = shobidStripeTermekLocale($termek, $fallbackLocale);
    $currency = shobidStripeTermekCurrency($termek, $fallbackLocale);
    $stripeOsszeg = shobidStripeChargeAmountMinor($osszeg, $currency);
    $stripeOsszeg = max(shobidStripeChargeMinimumMinor($currency), intval($stripeOsszeg));

    $sessionParams = [
        'mode' => 'payment',
        'success_url' => shobidStripeAbsUrl($callbackLocale . '/index.php?id=' . $termekId . '&stripe=success&stripe_session_id={CHECKOUT_SESSION_ID}'),
        'cancel_url' => shobidStripeAbsUrl($callbackLocale . '/index.php?id=' . $termekId . '&stripe=cancel'),
        'payment_method_types' => ['card'],
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $stripeOsszeg,
                'product_data' => [
                    'name' => $termekNev
                ]
            ]
        ]],
        'client_reference_id' => $localOrderId,
        'metadata' => [
            'local_order_id' => $localOrderId,
            'termek_id' => (string)$termekId,
            'buyer_id' => (string)$buyerId,
            'buyer_name' => $buyerName,
            'currency' => $currency
        ]
    ];

    $sessionRes = shobidStripeApiRequest('POST', 'checkout/sessions', $sessionParams);
    if (empty($sessionRes['ok'])) {
        $stripeHiba = trim((string)($sessionRes['error'] ?? ''));
        $minimumHiba = stripos($stripeHiba, 'must add up to at least') !== false
            || stripos($stripeHiba, 'amount must be at least') !== false;
        if ($minimumHiba && $currency === 'huf') {
            $fallbackMajor = shobidStripeResolveFixPayableAmount($conn, $termek);
            $retryMinor = max(shobidStripeChargeMinimumMinor($currency), shobidStripeChargeAmountMinor($fallbackMajor, $currency));
            $sessionParams['line_items'][0]['price_data']['unit_amount'] = $retryMinor;
            $sessionRes = shobidStripeApiRequest('POST', 'checkout/sessions', $sessionParams);
        }
    }

    if (empty($sessionRes['ok'])) {
        return $sessionRes;
    }

    $session = $sessionRes['data'];
    $sessionId = trim((string)($session['id'] ?? ''));
    $checkoutUrl = trim((string)($session['url'] ?? ''));
    if ($sessionId === '' || $checkoutUrl === '') {
        return ['ok' => false, 'error' => 'Stripe session nem jott letre'];
    }

    $localOrderSql = $conn->real_escape_string($localOrderId);
    $sessionIdSql = $conn->real_escape_string($sessionId);
    $currencySql = $conn->real_escape_string($currency);
    $conn->query("INSERT INTO stripe_checkout_sessions (local_order_id, stripe_session_id, termek_id, buyer_id, amount_minor, amount_huf, currency, status)
        VALUES ('$localOrderSql', '$sessionIdSql', $termekId, $buyerId, $stripeOsszeg, $osszeg, '$currencySql', 'pending')");

    return [
        'ok' => true,
        'checkout_url' => $checkoutUrl,
        'session_id' => $sessionId,
        'local_order_id' => $localOrderId
    ];
}

function shobidStripeBiztositsCustomer($conn, $userId, array $userAdat = []) {
    shobidStripeEnsureFelhasznaloFizetesiOszlopok($conn);

    $userId = intval($userId);
    if ($userId < 1) {
        return ['ok' => false, 'error' => 'Hibas felhasznalo'];
    }

    $res = $conn->query("SELECT stripe_customer_id, becenev, email, teljes_nev FROM felhasznalok WHERE id = $userId LIMIT 1");
    $user = $res ? $res->fetch_assoc() : null;
    if (!$user) {
        return ['ok' => false, 'error' => 'Felhasznalo nem talalhato'];
    }

    $meglevoCustomer = trim((string)($user['stripe_customer_id'] ?? ''));
    if ($meglevoCustomer !== '') {
        return ['ok' => true, 'customer_id' => $meglevoCustomer];
    }

    $nev = trim((string)($userAdat['teljes_nev'] ?? $user['teljes_nev'] ?? $user['becenev'] ?? ''));
    $email = trim((string)($userAdat['email'] ?? $user['email'] ?? ''));

    $customerRes = shobidStripeApiRequest('POST', 'customers', [
        'name' => $nev !== '' ? $nev : ('Shobid user #' . $userId),
        'email' => $email,
        'metadata' => [
            'local_user_id' => (string)$userId,
            'becenev' => trim((string)($user['becenev'] ?? ''))
        ]
    ]);

    if (empty($customerRes['ok'])) {
        return $customerRes;
    }

    $customerId = trim((string)($customerRes['data']['id'] ?? ''));
    if ($customerId === '') {
        return ['ok' => false, 'error' => 'Stripe customer nem jott letre'];
    }

    $customerIdSql = $conn->real_escape_string($customerId);
    $conn->query("UPDATE felhasznalok SET stripe_customer_id = '$customerIdSql' WHERE id = $userId");

    return ['ok' => true, 'customer_id' => $customerId];
}

function shobidStripeFizetesiSetupCheckoutLetrehoz($conn, $userId, array $userAdat = []) {
    $customerRes = shobidStripeBiztositsCustomer($conn, $userId, $userAdat);
    if (empty($customerRes['ok'])) {
        return $customerRes;
    }

    $customerId = trim((string)($customerRes['customer_id'] ?? ''));
    if ($customerId === '') {
        return ['ok' => false, 'error' => 'Stripe customer hianyzik'];
    }

    $callbackLocale = shobidStripeLocaleForUser($conn, intval($userId), function_exists('shobidI18nCurrentLocale') ? shobidI18nCurrentLocale() : 'hu-hu');
    $setupRes = shobidStripeApiRequest('POST', 'checkout/sessions', [
        'mode' => 'setup',
        'customer' => $customerId,
        'success_url' => shobidStripeAbsUrl($callbackLocale . '/profil.php?stripe_setup=success&stripe_session_id={CHECKOUT_SESSION_ID}#bankkartya'),
        'cancel_url' => shobidStripeAbsUrl($callbackLocale . '/profil.php?stripe_setup=cancel#bankkartya'),
        'payment_method_types' => ['card'],
        'client_reference_id' => 'setup_' . intval($userId),
        'metadata' => [
            'local_user_id' => (string)intval($userId)
        ]
    ]);

    if (empty($setupRes['ok'])) {
        return $setupRes;
    }

    $checkoutUrl = trim((string)($setupRes['data']['url'] ?? ''));
    if ($checkoutUrl === '') {
        return ['ok' => false, 'error' => 'Stripe setup checkout nem jott letre'];
    }

    return [
        'ok' => true,
        'checkout_url' => $checkoutUrl,
        'session_id' => trim((string)($setupRes['data']['id'] ?? ''))
    ];
}

function shobidStripeSetupSessionRogzites($conn, $stripeSessionId) {
    shobidStripeEnsureFelhasznaloFizetesiOszlopok($conn);

    $stripeSessionId = trim((string)$stripeSessionId);
    if ($stripeSessionId === '') {
        return false;
    }

    $sessionRes = shobidStripeApiRequest('GET', 'checkout/sessions/' . rawurlencode($stripeSessionId), [
        'expand' => ['setup_intent']
    ]);
    if (empty($sessionRes['ok'])) {
        return false;
    }

    $session = $sessionRes['data'] ?? [];
    if (trim((string)($session['mode'] ?? '')) !== 'setup') {
        return false;
    }

    $customerId = trim((string)($session['customer'] ?? ''));
    $setupIntent = $session['setup_intent'] ?? null;
    $setupIntentId = is_array($setupIntent)
        ? trim((string)($setupIntent['id'] ?? ''))
        : trim((string)$setupIntent);

    if ($setupIntentId === '') {
        return false;
    }

    $setupIntentRes = is_array($setupIntent) && !empty($setupIntent['payment_method'])
        ? ['ok' => true, 'data' => $setupIntent]
        : shobidStripeApiRequest('GET', 'setup_intents/' . rawurlencode($setupIntentId));

    if (empty($setupIntentRes['ok'])) {
        return false;
    }

    $setupData = $setupIntentRes['data'] ?? [];
    $paymentMethodId = trim((string)($setupData['payment_method'] ?? ''));
    if ($paymentMethodId === '') {
        return false;
    }

    $userId = intval($session['metadata']['local_user_id'] ?? 0);
    if ($userId < 1) {
        return false;
    }

    $customerIdSql = $conn->real_escape_string($customerId);
    $paymentMethodSql = $conn->real_escape_string($paymentMethodId);
    $conn->query("UPDATE felhasznalok
        SET stripe_customer_id = '$customerIdSql',
            stripe_payment_method_id = '$paymentMethodSql',
            stripe_setup_complete_at = NOW()
        WHERE id = $userId");

    return true;
}

function shobidStripeFixVasarlasSessionAlapjanTeljesites($conn, $stripeSessionId) {
    $stripeSessionId = trim((string)$stripeSessionId);
    if ($stripeSessionId === '') {
        return false;
    }

    $sessionRes = shobidStripeApiRequest('GET', 'checkout/sessions/' . rawurlencode($stripeSessionId));
    if (empty($sessionRes['ok'])) {
        return false;
    }

    $session = $sessionRes['data'] ?? [];
    if (trim((string)($session['mode'] ?? '')) !== 'payment') {
        return false;
    }

    if (trim((string)($session['payment_status'] ?? '')) !== 'paid') {
        return false;
    }

    return shobidStripeFixVasarlasTeljesites(
        $conn,
        trim((string)($session['id'] ?? '')),
        trim((string)($session['payment_intent'] ?? ''))
    );
}

function shobidKuponTablaBiztosit($conn) {
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $ok = (bool)$conn->query("CREATE TABLE IF NOT EXISTS kupon_vasarlasok (
        id INT(11) NOT NULL AUTO_INCREMENT,
        termek_id INT(11) NOT NULL,
        buyer_id INT(11) NOT NULL,
        seller_id INT(11) NOT NULL,
        kupon_kod VARCHAR(64) NOT NULL,
        allapot VARCHAR(20) NOT NULL DEFAULT 'uj',
        vasarolva_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        bevaltva_at DATETIME NULL,
        bevaltva_by INT(11) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_kupon_kod (kupon_kod),
        KEY idx_kupon_termek (termek_id),
        KEY idx_kupon_buyer (buyer_id),
        KEY idx_kupon_seller (seller_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    return $ok;
}

function shobidKuponGeneralKod() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $bytes = random_bytes(8);
    $parts = [];
    for ($i = 0; $i < strlen($bytes); $i++) {
        $parts[] = $chars[ord($bytes[$i]) % strlen($chars)];
    }
    return 'K' . implode('', $parts) . strtoupper(dechex(time() % 65536));
}

function shobidKuponRogzites($conn, $termek, $buyerId) {
    if (!shobidKuponTablaBiztosit($conn)) {
        return '';
    }
    $termekId = intval($termek['id'] ?? 0);
    $buyerId = intval($buyerId);
    $sellerId = intval($termek['feltolto_id'] ?? 0);
    if ($termekId < 1 || $buyerId < 1 || $sellerId < 1) {
        return '';
    }
    for ($i = 0; $i < 10; $i++) {
        $kod = shobidKuponGeneralKod();
        $kodSql = $conn->real_escape_string($kod);
        $ok = $conn->query("INSERT INTO kupon_vasarlasok (termek_id, buyer_id, seller_id, kupon_kod, allapot)
            VALUES ($termekId, $buyerId, $sellerId, '$kodSql', 'uj')");
        if ($ok) {
            return $kod;
        }
    }
    return '';
}

function shobidStripeSzinkronFuggoFixVasarlasokFelhasznalonak($conn, $userId, $limit = 10) {
    if (!shobidStripeEnsureCheckoutTabla($conn)) {
        return 0;
    }

    $userId = intval($userId);
    $limit = max(1, intval($limit));
    if ($userId < 1) {
        return 0;
    }

    $count = 0;
    $res = $conn->query("SELECT stripe_session_id
        FROM stripe_checkout_sessions
        WHERE buyer_id = $userId
          AND status = 'pending'
        ORDER BY id DESC
        LIMIT $limit");

    while ($res && ($row = $res->fetch_assoc())) {
        $sessionId = trim((string)($row['stripe_session_id'] ?? ''));
        if ($sessionId !== '' && shobidStripeFixVasarlasSessionAlapjanTeljesites($conn, $sessionId)) {
            $count++;
        }
    }

    return $count;
}

function shobidStripeFixVasarlasTeljesites($conn, $stripeSessionId, $paymentIntentId = '') {
    if (!shobidStripeEnsureCheckoutTabla($conn)) {
        return false;
    }

    $sessionIdSql = $conn->real_escape_string(trim((string)$stripeSessionId));
    if ($sessionIdSql === '') {
        return false;
    }

    $res = $conn->query("SELECT * FROM stripe_checkout_sessions WHERE stripe_session_id = '$sessionIdSql' LIMIT 1");
    $checkout = $res ? $res->fetch_assoc() : null;
    if (!$checkout) {
        return false;
    }

    if (($checkout['status'] ?? '') === 'completed') {
        return true;
    }

    $termekId = intval($checkout['termek_id'] ?? 0);
    $buyerId = intval($checkout['buyer_id'] ?? 0);
    if ($termekId < 1 || $buyerId < 1) {
        return false;
    }

    $termekRes = $conn->query("SELECT * FROM termekek WHERE id = $termekId LIMIT 1");
    $termek = $termekRes ? $termekRes->fetch_assoc() : null;
    $ajanlatTipus = (string)($termek['ajanlat_tipus'] ?? 'licit');
    if (!$termek || !in_array($ajanlatTipus, ['fix', 'kupon'], true)) {
        return false;
    }

    $darabszam = intval($termek['darabszam'] ?? 0);
    if ($darabszam < 1 || intval($termek['eladva'] ?? 0) === 1) {
        return false;
    }

    $buyerRes = $conn->query("SELECT becenev FROM felhasznalok WHERE id = $buyerId LIMIT 1");
    $buyer = $buyerRes ? $buyerRes->fetch_assoc() : null;
    $buyerNev = $conn->real_escape_string(trim((string)($buyer['becenev'] ?? '')));
    if ($buyerNev === '') {
        return false;
    }

    $ujDarabszam = max(0, $darabszam - 1);
    $ujEladva = $ujDarabszam === 0 ? 1 : 0;
    $conn->query("UPDATE termekek SET darabszam = $ujDarabszam, eladva = $ujEladva WHERE id = $termekId");

    $fixBrutto = shobidStripeParseIntAmount($termek['fix_ar'] ?? $termek['aktualis_ar'] ?? 0);
    if ($ajanlatTipus === 'fix') {
        $fixBrutto = shobidPayoutBruttoWithShipping(
            $fixBrutto,
            (string)($termek['szallitasi_mod'] ?? ''),
            shobidStripeParseIntAmount($termek['szallitasi_dij'] ?? 0)
        );
    }
    $currency = shobidStripeTermekCurrency($termek, function_exists('shobidI18nCurrentLocale') ? shobidI18nCurrentLocale() : 'hu-hu');
    $uzenetPrefix = $ajanlatTipus === 'kupon'
        ? html_entity_decode('&#127915; Kupont vett: ', ENT_QUOTES, 'UTF-8')
        : html_entity_decode('&#128722; Megvette: ', ENT_QUOTES, 'UTF-8');
    $vasarlasUzenet = $conn->real_escape_string($uzenetPrefix . shobidStripeFormatAmountHuman($fixBrutto, $currency));
    $conn->query("INSERT INTO uzenetek (felhasznalo, szoveg, termek_id) VALUES('$buyerNev', '$vasarlasUzenet', $termekId)");
    $vasarlasUzenetId = intval($conn->insert_id);
    if ($vasarlasUzenetId > 0 && $ajanlatTipus !== 'kupon') {
        shobidKuldFixVasarlasEmail($conn, $termek, $buyerId, $vasarlasUzenetId);
    }
    $kuponKod = '';
    if ($ajanlatTipus === 'kupon' && $vasarlasUzenetId > 0) {
        $kuponKod = shobidKuponRogzites($conn, $termek, $buyerId);
        shobidKuldKuponVasarlasEmail($conn, $termek, $buyerId, $vasarlasUzenetId, $kuponKod);
    }

    shobidPayoutEnsureFromStatus(
        $conn,
        shobidPenzugyiAzonosito($ajanlatTipus === 'kupon' ? 'kupon' : 'fix', $termekId, trim((string)($buyer['becenev'] ?? '')), $vasarlasUzenetId),
        $termekId,
        intval($termek['feltolto_id'] ?? 0),
        trim((string)($buyer['becenev'] ?? '')),
        $ajanlatTipus === 'kupon' ? 'kupon' : 'fix',
        $fixBrutto,
        $vasarlasUzenetId,
        ''
    );
    if ($ajanlatTipus === 'kupon') {
        shobidPayoutReleaseByAzonosito($conn, shobidPenzugyiAzonosito('kupon', $termekId, trim((string)($buyer['becenev'] ?? '')), $vasarlasUzenetId));
    }

    $paymentIntentSql = $conn->real_escape_string(trim((string)$paymentIntentId));
    $conn->query("UPDATE stripe_checkout_sessions
        SET status = 'completed',
            stripe_payment_intent_id = " . ($paymentIntentSql !== '' ? "'$paymentIntentSql'" : "stripe_payment_intent_id") . ",
            completed_at = NOW()
        WHERE stripe_session_id = '$sessionIdSql'");

    return true;
}

function shobidDirektFixVasarlas($conn, $termekId, $buyerId) {
    $termekId = intval($termekId);
    $buyerId = intval($buyerId);
    if ($termekId < 1 || $buyerId < 1) {
        return ['ok' => false, 'error' => 'Hibas vasarlasi adatok'];
    }

    $termekRes = $conn->query("SELECT * FROM termekek WHERE id = $termekId LIMIT 1");
    $termek = $termekRes ? $termekRes->fetch_assoc() : null;
    $ajanlatTipus = (string)($termek['ajanlat_tipus'] ?? 'licit');
    if (!$termek || !in_array($ajanlatTipus, ['fix', 'kupon'], true)) {
        return ['ok' => false, 'error' => 'Ez a termek nem fix/kupon tipus'];
    }

    $darabszam = intval($termek['darabszam'] ?? 0);
    if ($darabszam < 1 || intval($termek['eladva'] ?? 0) === 1) {
        return ['ok' => false, 'error' => 'Ez a termek mar nem elerheto'];
    }

    $buyerRes = $conn->query("SELECT becenev FROM felhasznalok WHERE id = $buyerId LIMIT 1");
    $buyer = $buyerRes ? $buyerRes->fetch_assoc() : null;
    $buyerNev = trim((string)($buyer['becenev'] ?? ''));
    if ($buyerNev === '') {
        return ['ok' => false, 'error' => 'A vasarlo nem talalhato'];
    }

    $buyerNevSql = $conn->real_escape_string($buyerNev);
    $ujDarabszam = max(0, $darabszam - 1);
    $ujEladva = $ujDarabszam === 0 ? 1 : 0;
    $conn->query("UPDATE termekek SET darabszam = $ujDarabszam, eladva = $ujEladva WHERE id = $termekId");

    $fixBrutto = shobidStripeParseIntAmount($termek['fix_ar'] ?? $termek['aktualis_ar'] ?? 0);
    if ($ajanlatTipus === 'fix') {
        $fixBrutto = shobidPayoutBruttoWithShipping(
            $fixBrutto,
            (string)($termek['szallitasi_mod'] ?? ''),
            shobidStripeParseIntAmount($termek['szallitasi_dij'] ?? 0)
        );
    }
    $currency = shobidStripeTermekCurrency($termek, function_exists('shobidI18nCurrentLocale') ? shobidI18nCurrentLocale() : 'hu-hu');
    $uzenetPrefix = $ajanlatTipus === 'kupon'
        ? html_entity_decode('&#127915; Kupont vett: ', ENT_QUOTES, 'UTF-8')
        : html_entity_decode('&#128722; Megvette: ', ENT_QUOTES, 'UTF-8');
    $vasarlasUzenet = $conn->real_escape_string($uzenetPrefix . shobidStripeFormatAmountHuman($fixBrutto, $currency));
    $conn->query("INSERT INTO uzenetek (felhasznalo, szoveg, termek_id) VALUES('$buyerNevSql', '$vasarlasUzenet', $termekId)");
    $vasarlasUzenetId = intval($conn->insert_id);
    if ($vasarlasUzenetId > 0 && $ajanlatTipus !== 'kupon') {
        shobidKuldFixVasarlasEmail($conn, $termek, $buyerId, $vasarlasUzenetId);
    }
    $kuponKod = '';
    if ($ajanlatTipus === 'kupon' && $vasarlasUzenetId > 0) {
        $kuponKod = shobidKuponRogzites($conn, $termek, $buyerId);
        shobidKuldKuponVasarlasEmail($conn, $termek, $buyerId, $vasarlasUzenetId, $kuponKod);
    }

    shobidPayoutEnsureFromStatus(
        $conn,
        shobidPenzugyiAzonosito($ajanlatTipus === 'kupon' ? 'kupon' : 'fix', $termekId, $buyerNev, $vasarlasUzenetId),
        $termekId,
        intval($termek['feltolto_id'] ?? 0),
        $buyerNev,
        $ajanlatTipus === 'kupon' ? 'kupon' : 'fix',
        $fixBrutto,
        $vasarlasUzenetId,
        ''
    );
    if ($ajanlatTipus === 'kupon') {
        shobidPayoutReleaseByAzonosito($conn, shobidPenzugyiAzonosito('kupon', $termekId, $buyerNev, $vasarlasUzenetId));
    }

    return ['ok' => true, 'uzenet_id' => $vasarlasUzenetId, 'kupon_kod' => $kuponKod];
}

