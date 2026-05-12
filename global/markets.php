<?php

if (!function_exists('shobidMarketList')) {
    function shobidMarketList() {
        return [
            ['code' => 'hu-hu', 'name' => 'Hungary', 'label' => 'HU-HU', 'available' => true],
            ['code' => 'de-de', 'name' => 'Germany', 'label' => 'DE-DE', 'available' => true],
            ['code' => 'pl-pl', 'name' => 'Poland', 'label' => 'PL-PL', 'available' => true],
            ['code' => 'sk-sk', 'name' => 'Slovakia', 'label' => 'SK-SK', 'available' => true],
            ['code' => 'cz-cz', 'name' => 'Czech Republic', 'label' => 'CZ-CZ', 'available' => true],
            ['code' => 'au-au', 'name' => 'Austria', 'label' => 'AU-AU', 'available' => true],
            ['code' => 'hr-hr', 'name' => 'Croatia', 'label' => 'HR-HR', 'available' => true],
            ['code' => 'ro-ro', 'name' => 'Romania', 'label' => 'RO-RO', 'available' => true],
            ['code' => 'uk-uk', 'name' => 'United Kingdom', 'label' => 'UK-UK', 'available' => true],
        ];
    }
}

if (!function_exists('shobidMarketCodes')) {
    function shobidMarketCodes() {
        return array_map(function ($market) {
            return (string)($market['code'] ?? '');
        }, shobidMarketList());
    }
}

if (!function_exists('shobidMarketDefaultCode')) {
    function shobidMarketDefaultCode() {
        return 'hu-hu';
    }
}

if (!function_exists('shobidMarketNormalizeCode')) {
    function shobidMarketNormalizeCode($code, $fallback = null) {
        $candidate = strtolower(trim((string)$code));
        if ($candidate !== '' && in_array($candidate, shobidMarketCodes(), true)) {
            return $candidate;
        }

        $fallbackCode = $fallback !== null ? strtolower(trim((string)$fallback)) : shobidMarketDefaultCode();
        if ($fallbackCode !== '' && in_array($fallbackCode, shobidMarketCodes(), true)) {
            return $fallbackCode;
        }
        return shobidMarketDefaultCode();
    }
}

if (!function_exists('shobidMarketByCode')) {
    function shobidMarketByCode($code) {
        $target = strtolower(trim((string)$code));
        foreach (shobidMarketList() as $market) {
            if (strtolower((string)($market['code'] ?? '')) === $target) {
                return $market;
            }
        }
        return null;
    }
}

if (!function_exists('shobidDetectMarketCodeFromRequest')) {
    function shobidDetectMarketCodeFromRequest() {
        $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        $first = strtolower(trim((string)(explode('/', trim($path, '/'))[0] ?? '')));
        if ($first !== '' && in_array($first, shobidMarketCodes(), true)) {
            return $first;
        }
        return shobidMarketDefaultCode();
    }
}

if (!function_exists('shobidMarketCurrentCode')) {
    function shobidMarketCurrentCode() {
        if (function_exists('shobidI18nCurrentLocale')) {
            return shobidMarketNormalizeCode(shobidI18nCurrentLocale());
        }

        $fromRequest = shobidDetectMarketCodeFromRequest();
        if ($fromRequest !== '') {
            return shobidMarketNormalizeCode($fromRequest);
        }

        $cookieMarket = strtolower(trim((string)($_COOKIE['shobid_market'] ?? '')));
        if ($cookieMarket !== '') {
            return shobidMarketNormalizeCode($cookieMarket);
        }

        return shobidMarketDefaultCode();
    }
}

if (!function_exists('shobidMarketCodeForUser')) {
    function shobidMarketCodeForUser($conn, $userId, $fallback = null) {
        $defaultCode = shobidMarketNormalizeCode($fallback !== null ? $fallback : shobidMarketCurrentCode());
        $uid = intval($userId);
        if ($uid < 1 || !($conn instanceof mysqli)) {
            return $defaultCode;
        }

        static $orszagKodColumnExists = null;
        if ($orszagKodColumnExists === null) {
            $res = $conn->query("SHOW COLUMNS FROM felhasznalok LIKE 'orszag_kod'");
            $orszagKodColumnExists = $res && $res->num_rows > 0;
        }
        if (!$orszagKodColumnExists) {
            return $defaultCode;
        }

        $res = $conn->query("SELECT orszag_kod FROM felhasznalok WHERE id = $uid LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        $userCode = strtolower(trim((string)($row['orszag_kod'] ?? '')));
        if ($userCode === '') {
            return $defaultCode;
        }
        return shobidMarketNormalizeCode($userCode, $defaultCode);
    }
}

if (!function_exists('shobidMarketActiveCode')) {
    function shobidMarketActiveCode($conn = null, $userId = 0, $fallback = null) {
        $baseCode = shobidMarketNormalizeCode($fallback !== null ? $fallback : shobidMarketCurrentCode());
        if ($conn instanceof mysqli && intval($userId) > 0) {
            return shobidMarketCodeForUser($conn, intval($userId), $baseCode);
        }
        return $baseCode;
    }
}

if (!function_exists('shobidMarketSqlValue')) {
    function shobidMarketSqlValue($conn, $marketCode) {
        $normalized = shobidMarketNormalizeCode($marketCode);
        if ($conn instanceof mysqli) {
            return "'" . $conn->real_escape_string($normalized) . "'";
        }
        return "'" . addslashes($normalized) . "'";
    }
}

if (!function_exists('shobidMarketTermekWhere')) {
    function shobidMarketTermekWhere($conn, $alias = 't', $marketCode = null) {
        $code = shobidMarketNormalizeCode($marketCode !== null ? $marketCode : shobidMarketCurrentCode());
        $column = trim((string)$alias) !== '' ? (trim((string)$alias) . '.orszag_kod') : 'orszag_kod';
        return $column . ' = ' . shobidMarketSqlValue($conn, $code);
    }
}

if (!function_exists('shobidMarketEnsureTermekekOrszagKod')) {
    function shobidMarketEnsureTermekekOrszagKod($conn) {
        if (!($conn instanceof mysqli)) {
            return false;
        }

        static $done = false;
        if ($done) {
            return true;
        }

        $tableRes = $conn->query("SHOW TABLES LIKE 'termekek'");
        if (!($tableRes && $tableRes->num_rows > 0)) {
            $done = true;
            return false;
        }

        $columnRes = $conn->query("SHOW COLUMNS FROM termekek LIKE 'orszag_kod'");
        $columnExists = $columnRes && $columnRes->num_rows > 0;

        if (!$columnExists) {
            $conn->query("ALTER TABLE termekek ADD orszag_kod VARCHAR(10) NOT NULL DEFAULT '" . shobidMarketDefaultCode() . "'");

            $userCountryColumnRes = $conn->query("SHOW COLUMNS FROM felhasznalok LIKE 'orszag_kod'");
            $userCountryColumnExists = $userCountryColumnRes && $userCountryColumnRes->num_rows > 0;
            if ($userCountryColumnExists) {
                $defaultSql = shobidMarketSqlValue($conn, shobidMarketDefaultCode());
                $conn->query("UPDATE termekek t
                    LEFT JOIN felhasznalok f ON f.id = t.feltolto_id
                    SET t.orszag_kod = CASE
                        WHEN f.orszag_kod IS NOT NULL AND TRIM(f.orszag_kod) <> '' THEN LOWER(TRIM(f.orszag_kod))
                        ELSE $defaultSql
                    END
                    WHERE t.orszag_kod IS NULL OR TRIM(t.orszag_kod) = ''");
            }
        }

        $defaultSql = shobidMarketSqlValue($conn, shobidMarketDefaultCode());
        $conn->query("UPDATE termekek SET orszag_kod = $defaultSql WHERE orszag_kod IS NULL OR TRIM(orszag_kod) = ''");

        $done = true;
        return true;
    }
}
