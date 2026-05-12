<?php

function shobidPayoutSettingsPath() {
    return __DIR__ . '/ajanlat_beallitasok.json';
}

function shobidPayoutDefaultVatPercentByLocale() {
    return [
        'hu-hu' => 27.0,
        'de-de' => 19.0,
        'pl-pl' => 23.0,
        'sk-sk' => 23.0,
        'cz-cz' => 21.0,
        'au-au' => 20.0,
        'hr-hr' => 25.0,
        'ro-ro' => 19.0,
        'uk-uk' => 20.0
    ];
}

function shobidPayoutDefaultCardFeePercentByLocale() {
    return [
        'hu-hu' => 2.2,
        'de-de' => 2.2,
        'pl-pl' => 2.2,
        'sk-sk' => 2.2,
        'cz-cz' => 2.2,
        'au-au' => 2.2,
        'hr-hr' => 2.2,
        'ro-ro' => 2.2,
        'uk-uk' => 2.2
    ];
}

function shobidPayoutNormalizeLocale($locale, $fallback = 'hu-hu') {
    $candidate = strtolower(trim((string)$locale));
    $fallback = strtolower(trim((string)$fallback));
    if (function_exists('shobidMarketNormalizeCode')) {
        return shobidMarketNormalizeCode($candidate, $fallback !== '' ? $fallback : 'hu-hu');
    }
    if ($candidate !== '') {
        return $candidate;
    }
    return $fallback !== '' ? $fallback : 'hu-hu';
}

function shobidPayoutVatPercentByLocale($locale = 'hu-hu') {
    $defaults = shobidPayoutDefaultVatPercentByLocale();
    $normalized = shobidPayoutNormalizeLocale($locale, 'hu-hu');
    $fallbackPercent = floatval($defaults['hu-hu'] ?? 27.0);

    $configured = [];
    $path = shobidPayoutSettingsPath();
    if (is_file($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded) && isset($decoded['vat_by_country_percent']) && is_array($decoded['vat_by_country_percent'])) {
            foreach ($decoded['vat_by_country_percent'] as $localeCode => $percent) {
                $key = shobidPayoutNormalizeLocale($localeCode, $localeCode);
                if ($key === '') {
                    continue;
                }
                $configured[$key] = max(0.0, min(99.0, floatval($percent)));
            }
        }
    }

    $all = array_merge($defaults, $configured);
    if (isset($all[$normalized])) {
        return floatval($all[$normalized]);
    }
    return $fallbackPercent;
}

function shobidPayoutCardFeePercentByLocale($locale = 'hu-hu') {
    $defaults = shobidPayoutDefaultCardFeePercentByLocale();
    $normalized = shobidPayoutNormalizeLocale($locale, 'hu-hu');
    $fallbackPercent = floatval($defaults['hu-hu'] ?? 2.2);

    $configured = [];
    $path = shobidPayoutSettingsPath();
    if (is_file($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded) && isset($decoded['card_fee_percent_by_country']) && is_array($decoded['card_fee_percent_by_country'])) {
            foreach ($decoded['card_fee_percent_by_country'] as $localeCode => $percent) {
                $key = shobidPayoutNormalizeLocale($localeCode, $localeCode);
                if ($key === '') {
                    continue;
                }
                $configured[$key] = max(0.0, min(99.0, floatval($percent)));
            }
        }
    }

    $all = array_merge($defaults, $configured);
    if (isset($all[$normalized])) {
        return floatval($all[$normalized]);
    }
    return $fallbackPercent;
}

function shobidPayoutVatRateByLocale($locale = 'hu-hu') {
    return shobidPayoutVatPercentByLocale($locale) / 100;
}

function shobidPayoutVatMultiplierForLocale($locale = 'hu-hu') {
    return 1 + shobidPayoutVatRateByLocale($locale);
}

function shobidPayoutTermekLocaleById($conn, $termekId, $fallback = 'hu-hu') {
    $fallbackLocale = shobidPayoutNormalizeLocale($fallback, 'hu-hu');
    $tid = intval($termekId);
    if (!($conn instanceof mysqli) || $tid < 1) {
        return $fallbackLocale;
    }

    static $orszagKodColExists = null;
    if ($orszagKodColExists === null) {
        $orszagKodColExists = false;
        $res = $conn->query("SHOW COLUMNS FROM termekek LIKE 'orszag_kod'");
        if ($res && $res->num_rows > 0) {
            $orszagKodColExists = true;
        }
    }
    if (!$orszagKodColExists) {
        return $fallbackLocale;
    }

    $res = $conn->query("SELECT orszag_kod FROM termekek WHERE id = $tid LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    return shobidPayoutNormalizeLocale((string)($row['orszag_kod'] ?? ''), $fallbackLocale);
}

function shobidPayoutEnsureTables($conn) {
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }

    $ok = (bool) $conn->query("CREATE TABLE IF NOT EXISTS elado_egyenleg_tetelek (
        id INT(11) NOT NULL AUTO_INCREMENT,
        azonosito VARCHAR(191) NOT NULL,
        termek_id INT(11) NOT NULL DEFAULT 0,
        elado_id INT(11) NOT NULL DEFAULT 0,
        vevo_id INT(11) NOT NULL DEFAULT 0,
        vasarlo_nev VARCHAR(191) NOT NULL DEFAULT '',
        tipus VARCHAR(20) NOT NULL DEFAULT 'licit',
        uzenet_id INT(11) NOT NULL DEFAULT 0,
        tetel_nev VARCHAR(255) NOT NULL DEFAULT '',
        brutto_osszeg DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        jutalek_brutto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        kartya_brutto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        elado_osszeg DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        allapot VARCHAR(30) NOT NULL DEFAULT 'pending',
        felszabadult_at DATETIME NULL,
        kiutalas_id INT(11) NULL,
        letrehozva TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        frissitve TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_azonosito (azonosito),
        KEY idx_elado_allapot (elado_id, allapot)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    if ($ok) {
        $ok = (bool) $conn->query("CREATE TABLE IF NOT EXISTS elado_kiutalasok (
            id INT(11) NOT NULL AUTO_INCREMENT,
            elado_id INT(11) NOT NULL DEFAULT 0,
            tipus VARCHAR(20) NOT NULL DEFAULT 'auto',
            brutto_osszeg DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            alapdij_ft DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            szazalek_dij DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            levont_dij DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            kifizetett_osszeg DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            honap_kulcs VARCHAR(7) NOT NULL DEFAULT '',
            statusz VARCHAR(20) NOT NULL DEFAULT 'processed',
            stripe_transfer_id VARCHAR(191) NULL,
            letrehozva TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_elado_honap (elado_id, honap_kulcs)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    if ($ok) {
        $ok = (bool) $conn->query("CREATE TABLE IF NOT EXISTS rendszer_futasok (
            kulcs VARCHAR(100) NOT NULL,
            utolso_futas DATETIME NULL,
            PRIMARY KEY (kulcs)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    return $ok;
}

function shobidPayoutFinancialSplit($bruttoOsszeg, $locale = 'hu-hu') {
    $brutto = round(max(0, floatval($bruttoOsszeg)), 2);
    $afaMultiplier = max(1.0, floatval(shobidPayoutVatMultiplierForLocale($locale)));
    $cardFeeRate = max(0.0, floatval(shobidPayoutCardFeePercentByLocale($locale)) / 100);
    $jutalekBrutto = round($brutto * 0.078 * $afaMultiplier, 2);
    $kartyaBrutto = round($brutto * $cardFeeRate * $afaMultiplier, 2);
    $eladoOsszeg = round(max(0, $brutto - $jutalekBrutto - $kartyaBrutto), 2);

    return [
        'brutto' => $brutto,
        'jutalek_brutto' => $jutalekBrutto,
        'kartya_brutto' => $kartyaBrutto,
        'elado_osszeg' => $eladoOsszeg
    ];
}

function shobidPayoutNormalizeShippingFt($szallitasiMod, $szallitasiDij) {
    $mod = strtolower(trim((string)$szallitasiMod));
    $dij = max(0, intval($szallitasiDij));

    if ($mod === '' || $mod === 'ingyenes') {
        return 0;
    }

    return $dij;
}

function shobidPayoutBruttoWithShipping($alapOsszeg, $szallitasiMod = '', $szallitasiDij = 0) {
    $alap = max(0, intval($alapOsszeg));
    $szallitas = shobidPayoutNormalizeShippingFt($szallitasiMod, $szallitasiDij);
    return $alap + $szallitas;
}

function shobidPayoutUserIdByNickname($conn, $becenev) {
    $becenev = trim((string) $becenev);
    if ($becenev === '') {
        return 0;
    }

    $sql = $conn->real_escape_string($becenev);
    $res = $conn->query("SELECT id FROM felhasznalok WHERE becenev = '$sql' LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    return intval($row['id'] ?? 0);
}

function shobidPayoutEnsureFromStatus($conn, $azonosito, $termekId, $eladoId, $vasarloNev, $tipus, $bruttoOsszeg, $uzenetId = 0, $tetelNev = '') {
    if (!shobidPayoutEnsureTables($conn)) {
        return false;
    }

    $azonosito = trim((string) $azonosito);
    if ($azonosito === '' || intval($termekId) < 1 || intval($eladoId) < 1) {
        return false;
    }

    $termekLocale = shobidPayoutTermekLocaleById($conn, intval($termekId), 'hu-hu');
    $szamitas = shobidPayoutFinancialSplit($bruttoOsszeg, $termekLocale);
    $vevoId = shobidPayoutUserIdByNickname($conn, $vasarloNev);
    $azonositoSql = $conn->real_escape_string($azonosito);
    $vasarloSql = $conn->real_escape_string(trim((string) $vasarloNev));
    $tipusSql = $conn->real_escape_string(trim((string) $tipus));
    $tetelSql = $conn->real_escape_string(trim((string) $tetelNev));

    $conn->query("INSERT INTO elado_egyenleg_tetelek (
            azonosito, termek_id, elado_id, vevo_id, vasarlo_nev, tipus, uzenet_id, tetel_nev,
            brutto_osszeg, jutalek_brutto, kartya_brutto, elado_osszeg, allapot
        ) VALUES (
            '$azonositoSql', " . intval($termekId) . ", " . intval($eladoId) . ", " . intval($vevoId) . ", '$vasarloSql', '$tipusSql', " . intval($uzenetId) . ", '$tetelSql',
            {$szamitas['brutto']}, {$szamitas['jutalek_brutto']}, {$szamitas['kartya_brutto']}, {$szamitas['elado_osszeg']}, 'pending'
        )
        ON DUPLICATE KEY UPDATE
            termek_id = VALUES(termek_id),
            elado_id = VALUES(elado_id),
            vevo_id = VALUES(vevo_id),
            vasarlo_nev = VALUES(vasarlo_nev),
            tipus = VALUES(tipus),
            uzenet_id = VALUES(uzenet_id),
            tetel_nev = VALUES(tetel_nev),
            brutto_osszeg = VALUES(brutto_osszeg),
            jutalek_brutto = VALUES(jutalek_brutto),
            kartya_brutto = VALUES(kartya_brutto),
            elado_osszeg = VALUES(elado_osszeg)");

    return true;
}

function shobidPayoutRefreshStatuses($conn, $rows, $statusz) {
    $statusz = trim((string) $statusz);
    foreach ($rows as $row) {
        $azonosito = trim((string) ($row['azonosito'] ?? ''));
        if ($azonosito !== '') {
            shobidBiztositPenzugyiStatuszt($conn, $azonosito, $statusz);
        }
    }
}

function shobidPayoutStripeTransferIfAvailable($conn, $eladoId, $osszeg, $tipus, array $rows = []) {
    if (!function_exists('shobidStripeTransferToSellerAccount')) {
        return ['ok' => false, 'reason' => 'stripe_helper_missing', 'error' => 'Stripe helper nincs betoltve, a kiutalas nem indithato.'];
    }

    $termekIds = [];
    foreach ($rows as $row) {
        $termekId = intval($row['termek_id'] ?? 0);
        if ($termekId > 0) {
            $termekIds[] = $termekId;
        }
    }
    $termekIds = array_values(array_unique($termekIds));

    $currency = null;
    $detectedCurrencies = [];
    if (function_exists('shobidStripeTermekCurrencyById')) {
        foreach ($termekIds as $termekId) {
            $detected = shobidStripeTermekCurrencyById(
                $conn,
                intval($termekId),
                function_exists('shobidStripeLocaleForUser') ? shobidStripeLocaleForUser($conn, intval($eladoId), 'hu-hu') : 'hu-hu'
            );
            if ($detected !== '') {
                $detectedCurrencies[strtolower($detected)] = true;
            }
        }
        if (count($detectedCurrencies) > 1) {
            return [
                'ok' => false,
                'reason' => 'mixed_currency',
                'error' => 'Tobb devizat tartalmazo kiutalas nem indithato egy Stripe transferben.'
            ];
        }
        if (count($detectedCurrencies) === 1) {
            $keys = array_keys($detectedCurrencies);
            $currency = (string)$keys[0];
        }
    }

    $leiras = $tipus === 'instant' ? 'Shobid azonnali kiutalĂˇs' : 'Shobid automatikus kiutalĂˇs';

    return shobidStripeTransferToSellerAccount($conn, $eladoId, $osszeg, $leiras, [
        'payout_type' => (string)$tipus,
        'termek_ids' => implode(',', array_unique($termekIds))
    ], $currency);
}

function shobidPayoutDetectCurrencyForRow($conn, $eladoId, array $row) {
    $fallbackLocale = function_exists('shobidStripeLocaleForUser')
        ? shobidStripeLocaleForUser($conn, intval($eladoId), 'hu-hu')
        : 'hu-hu';
    $currency = '';

    if (function_exists('shobidStripeTermekCurrencyById')) {
        $termekId = intval($row['termek_id'] ?? 0);
        if ($termekId > 0) {
            $currency = trim((string)shobidStripeTermekCurrencyById($conn, $termekId, $fallbackLocale));
        }
    }

    if ($currency === '' && function_exists('shobidStripeCurrencyByLocale')) {
        $currency = (string)shobidStripeCurrencyByLocale($fallbackLocale);
    }

    if (function_exists('shobidStripeNormalizeCurrency')) {
        return shobidStripeNormalizeCurrency($currency, 'huf');
    }

    $currency = strtolower(trim((string)$currency));
    return $currency !== '' ? $currency : 'huf';
}

function shobidPayoutBuildAvailableCurrencyGroups($conn, $eladoId, array $rows) {
    $groups = [];
    foreach ($rows as $row) {
        $currency = shobidPayoutDetectCurrencyForRow($conn, $eladoId, $row);
        if (!isset($groups[$currency])) {
            $groups[$currency] = [
                'currency' => $currency,
                'rows' => [],
                'sum' => 0.0
            ];
        }
        $groups[$currency]['rows'][] = $row;
        $groups[$currency]['sum'] += floatval($row['elado_osszeg'] ?? 0);
    }

    foreach ($groups as $currency => $group) {
        $groups[$currency]['sum'] = round(floatval($group['sum'] ?? 0), 2);
    }

    return $groups;
}

function shobidPayoutFinalizeRowsAsPaid($conn, array $rows, $kiutalasId) {
    $ids = [];
    foreach ($rows as $row) {
        $id = intval($row['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));
    if (empty($ids)) {
        return false;
    }

    $idSql = implode(',', $ids);
    $conn->query("UPDATE elado_egyenleg_tetelek
        SET allapot = 'paid', kiutalas_id = " . intval($kiutalasId) . "
        WHERE id IN ($idSql)");
    return true;
}

function shobidPayoutProcessAutoCurrencyGroup($conn, $eladoId, $currency, array $rows, $sum) {
    $sum = round(floatval($sum), 2);
    if ($sum < 10000 || empty($rows)) {
        return ['ok' => false, 'reason' => 'threshold', 'sum' => $sum, 'currency' => $currency];
    }

    $transfer = shobidPayoutStripeTransferIfAvailable($conn, $eladoId, $sum, 'auto', $rows);
    if (empty($transfer['ok'])) {
        $honapKulcs = $conn->real_escape_string(date('Y-m'));
        $conn->query("INSERT INTO elado_kiutalasok (elado_id, tipus, brutto_osszeg, alapdij_ft, szazalek_dij, levont_dij, kifizetett_osszeg, honap_kulcs, statusz, stripe_transfer_id)
            VALUES (" . intval($eladoId) . ", 'auto', $sum, 0, 0, 0, $sum, '$honapKulcs', 'failed', NULL)");

        return [
            'ok' => false,
            'reason' => trim((string)($transfer['reason'] ?? 'stripe_transfer')),
            'error' => trim((string)($transfer['error'] ?? 'A Stripe kiutalasi transfer nem sikerult.')),
            'sum' => $sum,
            'currency' => $currency
        ];
    }

    $transferId = trim((string)($transfer['transfer_id'] ?? ''));
    if ($transferId === '' || stripos($transferId, 'tr_') !== 0) {
        $honapKulcs = $conn->real_escape_string(date('Y-m'));
        $conn->query("INSERT INTO elado_kiutalasok (elado_id, tipus, brutto_osszeg, alapdij_ft, szazalek_dij, levont_dij, kifizetett_osszeg, honap_kulcs, statusz, stripe_transfer_id)
            VALUES (" . intval($eladoId) . ", 'auto', $sum, 0, 0, 0, $sum, '$honapKulcs', 'failed', NULL)");

        return [
            'ok' => false,
            'reason' => 'transfer_id_missing',
            'error' => 'Stripe transfer azonosito hianyzik vagy ervenytelen.',
            'sum' => $sum,
            'currency' => $currency
        ];
    }

    $honapKulcs = $conn->real_escape_string(date('Y-m'));
    $transferSql = $conn->real_escape_string($transferId);
    $conn->query("INSERT INTO elado_kiutalasok (elado_id, tipus, brutto_osszeg, alapdij_ft, szazalek_dij, levont_dij, kifizetett_osszeg, honap_kulcs, statusz, stripe_transfer_id)
        VALUES (" . intval($eladoId) . ", 'auto', $sum, 0, 0, 0, $sum, '$honapKulcs', 'processed', '$transferSql')");
    $kiutalasId = intval($conn->insert_id);
    if ($kiutalasId < 1) {
        return ['ok' => false, 'reason' => 'insert', 'sum' => $sum, 'currency' => $currency];
    }

    shobidPayoutFinalizeRowsAsPaid($conn, $rows, $kiutalasId);
    shobidPayoutRefreshStatuses($conn, $rows, 'Kiutalva');

    return [
        'ok' => true,
        'tipus' => 'auto',
        'currency' => $currency,
        'kiutalas_id' => $kiutalasId,
        'kifizetett_osszeg' => $sum,
        'stripe_transfer_id' => $transferId
    ];
}

function shobidPayoutProcessAutoIfEligible($conn, $eladoId) {
    if (!shobidPayoutEnsureTables($conn)) {
        return ['ok' => false, 'reason' => 'tabla'];
    }

    $eladoId = intval($eladoId);
    if ($eladoId < 1) {
        return ['ok' => false, 'reason' => 'elado'];
    }

    $res = $conn->query("SELECT * FROM elado_egyenleg_tetelek WHERE elado_id = $eladoId AND allapot = 'available' ORDER BY COALESCE(felszabadult_at, letrehozva) ASC, id ASC");
    $rows = [];
    $sum = 0.0;
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
        $sum += floatval($row['elado_osszeg'] ?? 0);
    }
    $sum = round($sum, 2);

    if ($sum < 10000 || empty($rows)) {
        return ['ok' => false, 'reason' => 'threshold', 'sum' => $sum];
    }

    $groups = shobidPayoutBuildAvailableCurrencyGroups($conn, $eladoId, $rows);
    $processed = [];
    $failed = [];

    foreach ($groups as $group) {
        $groupCurrency = (string)($group['currency'] ?? 'huf');
        $groupRows = is_array($group['rows'] ?? null) ? $group['rows'] : [];
        $groupSum = round(floatval($group['sum'] ?? 0), 2);

        if ($groupSum < 10000) {
            $failed[] = [
                'ok' => false,
                'reason' => 'threshold',
                'currency' => $groupCurrency,
                'sum' => $groupSum
            ];
            continue;
        }

        $eredmeny = shobidPayoutProcessAutoCurrencyGroup($conn, $eladoId, $groupCurrency, $groupRows, $groupSum);
        if (!empty($eredmeny['ok'])) {
            $processed[] = $eredmeny;
        } else {
            $failed[] = $eredmeny;
        }
    }

    if (!empty($processed)) {
        return [
            'ok' => true,
            'tipus' => 'auto',
            'processed' => $processed,
            'failed' => $failed
        ];
    }

    $firstFailed = $failed[0] ?? ['reason' => 'threshold', 'sum' => $sum];
    $firstFailed['ok'] = false;
    if (!isset($firstFailed['sum'])) {
        $firstFailed['sum'] = $sum;
    }
    return $firstFailed;
}

function shobidPayoutRunDailyAutoBatch($conn) {
    if (!shobidPayoutEnsureTables($conn)) {
        return ['ok' => false, 'error' => 'A kiutalási táblák nem érhetők el.'];
    }

    $eredmenyek = [];
    $res = $conn->query("SELECT elado_id, ROUND(SUM(elado_osszeg), 2) AS available_sum
        FROM elado_egyenleg_tetelek
        WHERE allapot = 'available'
        GROUP BY elado_id
        HAVING available_sum >= 10000");

    while ($res && ($row = $res->fetch_assoc())) {
        $eladoId = intval($row['elado_id'] ?? 0);
        if ($eladoId < 1) {
            continue;
        }
        $eredmenyek[] = shobidPayoutProcessAutoIfEligible($conn, $eladoId);
    }

    return ['ok' => true, 'results' => $eredmenyek];
}

function shobidPayoutMaybeRunDailyAuto($conn) {
    if (!shobidPayoutEnsureTables($conn)) {
        return ['ok' => false, 'reason' => 'tabla'];
    }

    $beallitottOra = intval(function_exists('shobidConfig') ? shobidConfig('PAYOUT_DAILY_HOUR', 1) : 1);
    $beallitottPerc = intval(function_exists('shobidConfig') ? shobidConfig('PAYOUT_DAILY_MINUTE', 0) : 0);
    $beallitottOra = max(0, min(23, $beallitottOra));
    $beallitottPerc = max(0, min(59, $beallitottPerc));

    $forceRaw = function_exists('shobidConfig') ? shobidConfig('PAYOUT_DAILY_FORCE', 0) : 0;
    $force = in_array(strtolower(trim((string)$forceRaw)), ['1', 'true', 'yes', 'on'], true);

    $aktualisPerc = intval(date('G')) * 60 + intval(date('i'));
    $celPerc = $beallitottOra * 60 + $beallitottPerc;

    if (!$force && $aktualisPerc < $celPerc) {
        return ['ok' => false, 'reason' => 'too_early'];
    }

    $kulcs = 'daily_auto_payout_' . $beallitottOra . '_' . $beallitottPerc;
    $kulcsSql = $conn->real_escape_string($kulcs);
    $today = date('Y-m-d');
    $res = $conn->query("SELECT utolso_futas FROM rendszer_futasok WHERE kulcs = '$kulcsSql' LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    $utolso = trim((string)($row['utolso_futas'] ?? ''));
    if (!$force && $utolso !== '' && substr($utolso, 0, 10) === $today) {
        return ['ok' => false, 'reason' => 'already_ran'];
    }

    $futtatas = shobidPayoutRunDailyAutoBatch($conn);
    if (empty($futtatas['ok'])) {
        return $futtatas;
    }

    $conn->query("INSERT INTO rendszer_futasok (kulcs, utolso_futas) VALUES ('$kulcsSql', NOW())
        ON DUPLICATE KEY UPDATE utolso_futas = VALUES(utolso_futas)");

    return ['ok' => true, 'results' => $futtatas['results'] ?? []];
}

function shobidPayoutReleaseByAzonosito($conn, $azonosito) {
    if (!shobidPayoutEnsureTables($conn)) {
        return ['ok' => false, 'reason' => 'tabla'];
    }

    $azonosito = trim((string) $azonosito);
    if ($azonosito === '') {
        return ['ok' => false, 'reason' => 'azonosito'];
    }

    $azonositoSql = $conn->real_escape_string($azonosito);
    $conn->query("UPDATE elado_egyenleg_tetelek
        SET allapot = IF(allapot = 'pending', 'available', allapot),
            felszabadult_at = IF(allapot = 'pending', NOW(), felszabadult_at)
        WHERE azonosito = '$azonositoSql'");

    $res = $conn->query("SELECT * FROM elado_egyenleg_tetelek WHERE azonosito = '$azonositoSql' LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) {
        return ['ok' => false, 'reason' => 'not_found'];
    }

    return ['ok' => true, 'row' => $row];
}

function shobidPayoutInstantBaseFeeForMonth($conn, $eladoId, $honapKulcs) {
    $eladoId = intval($eladoId);
    $honapKulcs = trim((string) $honapKulcs);
    if ($eladoId < 1 || $honapKulcs === '') {
        return 850.0;
    }

    $honapSql = $conn->real_escape_string($honapKulcs);
    $res = $conn->query("SELECT id FROM elado_kiutalasok WHERE elado_id = $eladoId AND tipus = 'instant' AND honap_kulcs = '$honapSql' AND alapdij_ft > 0 LIMIT 1");
    return ($res && $res->num_rows > 0) ? 0.0 : 850.0;
}

function shobidPayoutProcessInstant($conn, $eladoId) {
    if (!shobidPayoutEnsureTables($conn)) {
        return ['ok' => false, 'error' => 'A kiutalasi tablak nem erhetoek el.'];
    }

    $eladoId = intval($eladoId);
    if ($eladoId < 1) {
        return ['ok' => false, 'error' => 'Hianyzo elado azonosito.'];
    }

    $res = $conn->query("SELECT * FROM elado_egyenleg_tetelek WHERE elado_id = $eladoId AND allapot = 'available' ORDER BY COALESCE(felszabadult_at, letrehozva) ASC, id ASC");
    $rows = [];
    $sum = 0.0;
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
        $sum += floatval($row['elado_osszeg'] ?? 0);
    }
    $sum = round($sum, 2);

    if ($sum <= 0) {
        return ['ok' => false, 'error' => 'Nincs azonnal kiutalhato egyenleged.'];
    }
    if ($sum >= 10000) {
        return ['ok' => false, 'error' => 'A kiutalhato egyenleged mar eleri a 10 000 Ft-ot, ezert automatikus kiutalasra var.'];
    }

    $honapKulcs = date('Y-m');
    $alapdij = shobidPayoutInstantBaseFeeForMonth($conn, $eladoId, $honapKulcs);
    $szazalekDij = round($sum * 0.0025, 2);
    $levontDij = round($alapdij + $szazalekDij, 2);
    $kifizetett = round(max(0, $sum - $levontDij), 2);
    if ($kifizetett <= 0) {
        return ['ok' => false, 'error' => 'A kiutalhato osszeg tul alacsony az azonnali kiutalashoz.'];
    }

    $transfer = shobidPayoutStripeTransferIfAvailable($conn, $eladoId, $kifizetett, 'instant', $rows);
    if (empty($transfer['ok'])) {
        $honapSql = $conn->real_escape_string($honapKulcs);
        $conn->query("INSERT INTO elado_kiutalasok (elado_id, tipus, brutto_osszeg, alapdij_ft, szazalek_dij, levont_dij, kifizetett_osszeg, honap_kulcs, statusz, stripe_transfer_id)
            VALUES ($eladoId, 'instant', $sum, $alapdij, $szazalekDij, $levontDij, $kifizetett, '$honapSql', 'failed', NULL)");

        return [
            'ok' => false,
            'error' => trim((string)($transfer['error'] ?? 'A Stripe kiutalasi transfer nem sikerult.'))
        ];
    }

    $transferId = trim((string)($transfer['transfer_id'] ?? ''));
    if ($transferId === '' || stripos($transferId, 'tr_') !== 0) {
        $honapSql = $conn->real_escape_string($honapKulcs);
        $conn->query("INSERT INTO elado_kiutalasok (elado_id, tipus, brutto_osszeg, alapdij_ft, szazalek_dij, levont_dij, kifizetett_osszeg, honap_kulcs, statusz, stripe_transfer_id)
            VALUES ($eladoId, 'instant', $sum, $alapdij, $szazalekDij, $levontDij, $kifizetett, '$honapSql', 'failed', NULL)");

        return ['ok' => false, 'error' => 'Stripe transfer azonosito hianyzik vagy ervenytelen.'];
    }

    $honapSql = $conn->real_escape_string($honapKulcs);
    $transferSql = $conn->real_escape_string($transferId);
    $conn->query("INSERT INTO elado_kiutalasok (elado_id, tipus, brutto_osszeg, alapdij_ft, szazalek_dij, levont_dij, kifizetett_osszeg, honap_kulcs, statusz, stripe_transfer_id)
        VALUES ($eladoId, 'instant', $sum, $alapdij, $szazalekDij, $levontDij, $kifizetett, '$honapSql', 'processed', '$transferSql')");
    $kiutalasId = intval($conn->insert_id);
    if ($kiutalasId < 1) {
        return ['ok' => false, 'error' => 'Az azonnali kiutalas rogzitese nem sikerult.'];
    }

    $conn->query("UPDATE elado_egyenleg_tetelek SET allapot = 'paid', kiutalas_id = $kiutalasId WHERE elado_id = $eladoId AND allapot = 'available'");
    shobidPayoutRefreshStatuses($conn, $rows, 'Kiutalva');

    return [
        'ok' => true,
        'tipus' => 'instant',
        'kiutalas_id' => $kiutalasId,
        'brutto_osszeg' => $sum,
        'levont_dij' => $levontDij,
        'kifizetett_osszeg' => $kifizetett,
        'stripe_transfer_id' => $transferId
    ];
}

function shobidPayoutSellerSummary($conn, $eladoId) {
    shobidPayoutEnsureTables($conn);
    $eladoId = intval($eladoId);
    $summary = ['pending' => 0.0, 'available' => 0.0, 'paid' => 0.0];
    if ($eladoId < 1) {
        return $summary;
    }

    $res = $conn->query("SELECT allapot, SUM(elado_osszeg) AS osszeg FROM elado_egyenleg_tetelek WHERE elado_id = $eladoId GROUP BY allapot");
    while ($res && ($row = $res->fetch_assoc())) {
        $allapot = trim((string) ($row['allapot'] ?? ''));
        $osszeg = round(floatval($row['osszeg'] ?? 0), 2);
        if (array_key_exists($allapot, $summary)) {
            $summary[$allapot] = $osszeg;
        }
    }
    return $summary;
}

function shobidPayoutSellerHistory($conn, $eladoId, $limit = 20) {
    shobidPayoutEnsureTables($conn);
    $rows = [];
    $eladoId = intval($eladoId);
    $limit = max(1, intval($limit));
    if ($eladoId < 1) {
        return $rows;
    }

    $res = $conn->query("SELECT * FROM elado_kiutalasok WHERE elado_id = $eladoId ORDER BY id DESC LIMIT $limit");
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    return $rows;
}

function shobidPayoutRecentHistory($conn, $limit = 20) {
    shobidPayoutEnsureTables($conn);
    $rows = [];
    $limit = max(1, intval($limit));

    $res = $conn->query("SELECT k.*, f.becenev AS elado_nev
        FROM elado_kiutalasok k
        LEFT JOIN felhasznalok f ON f.id = k.elado_id
        ORDER BY k.id DESC
        LIMIT $limit");
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    return $rows;
}

function shobidPayoutGlobalSummary($conn) {
    shobidPayoutEnsureTables($conn);
    $summary = ['pending' => 0.0, 'available' => 0.0, 'paid' => 0.0];
    $res = $conn->query("SELECT allapot, SUM(elado_osszeg) AS osszeg FROM elado_egyenleg_tetelek GROUP BY allapot");
    while ($res && ($row = $res->fetch_assoc())) {
        $allapot = trim((string) ($row['allapot'] ?? ''));
        $osszeg = round(floatval($row['osszeg'] ?? 0), 2);
        if (array_key_exists($allapot, $summary)) {
            $summary[$allapot] = $osszeg;
        }
    }
    return $summary;
}
