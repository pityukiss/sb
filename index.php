<?php
include __DIR__ . '/global/markets.php';

$countries = shobidMarketList();
$availableCountries = array_values(array_filter($countries, function ($country) {
    return !empty($country['available']);
}));
$availableCodes = array_map(function ($country) {
    return strtolower((string)($country['code'] ?? ''));
}, $availableCountries);

$requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/index.php'), PHP_URL_PATH) ?? '/index.php');
$requestSegments = explode('/', trim($requestPath, '/'));
$requestMarketCode = strtolower((string)($requestSegments[0] ?? ''));
$looksLikeLocaleIndex = preg_match('#^/[a-z]{2}-[a-z]{2}/index\.php$#i', $requestPath) === 1;

// Compatibility guard: if an old locale wrapper still includes root index.php,
// serve app_index directly for /xx-xx/index.php instead of forcing geo-redirect.
if ($looksLikeLocaleIndex && in_array($requestMarketCode, $availableCodes, true)) {
    include __DIR__ . '/app_index.php';
    return;
}

if (!function_exists('shobidIsCrawlerRequest')) {
    function shobidIsCrawlerRequest() {
        $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua === '') {
            return false;
        }
        $needles = [
            'googlebot',
            'bingbot',
            'yandex',
            'duckduckbot',
            'baiduspider',
            'slurp',
            'facebookexternalhit',
            'linkedinbot',
            'twitterbot',
        ];
        foreach ($needles as $needle) {
            if (strpos($ua, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('shobidNormalizeCountryCode')) {
    function shobidNormalizeCountryCode($value) {
        $normalized = strtoupper(trim((string)$value));
        if (preg_match('/^[A-Z]{2}$/', $normalized)) {
            return $normalized;
        }
        $iso3ToIso2 = [
            'HUN' => 'HU',
            'DEU' => 'DE',
            'SVK' => 'SK',
            'GBR' => 'GB',
            'POL' => 'PL',
            'CZE' => 'CZ',
            'HRV' => 'HR',
            'AUT' => 'AT',
            'ROU' => 'RO',
            'USA' => 'US',
            'UKR' => 'UA',
        ];
        if (preg_match('/^[A-Z]{3}$/', $normalized) && isset($iso3ToIso2[$normalized])) {
            return $iso3ToIso2[$normalized];
        }
        return '';
    }
}

if (!function_exists('shobidDetectCountryCodeFromRequest')) {
    function shobidDetectCountryCodeFromRequest() {
        $headerKeys = [
            'HTTP_CF_IPCOUNTRY',
            'HTTP_X_COUNTRY_CODE',
            'HTTP_X_APPENGINE_COUNTRY',
            'GEOIP_COUNTRY_CODE',
            'HTTP_CLOUDFRONT_VIEWER_COUNTRY',
            'HTTP_X_GEO_COUNTRY',
            'HTTP_X_COUNTRY',
            'HTTP_FASTLY_COUNTRY_CODE',
            'HTTP_FLY_COUNTRY',
            'HTTP_VERCEL_IP_COUNTRY',
            'HTTP_X_VERCEL_IP_COUNTRY',
        ];
        foreach ($headerKeys as $key) {
            $value = shobidNormalizeCountryCode((string)($_SERVER[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }
}

if (!function_exists('shobidDetectClientIpForGeoApi')) {
    function shobidDetectClientIpForGeoApi() {
        $candidates = [];

        $cfConnectingIp = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cfConnectingIp !== '') {
            $candidates[] = $cfConnectingIp;
        }

        $xForwardedFor = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xForwardedFor !== '') {
            $parts = explode(',', $xForwardedFor);
            foreach ($parts as $part) {
                $candidate = trim($part);
                if ($candidate !== '') {
                    $candidates[] = $candidate;
                }
            }
        }

        $xRealIp = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($xRealIp !== '') {
            $candidates[] = $xRealIp;
        }

        $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remoteAddr !== '') {
            $candidates[] = $remoteAddr;
        }

        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('shobidFetchGeoApiJson')) {
    function shobidFetchGeoApiJson($url, $timeoutSeconds = 1.8) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 900);
                curl_setopt($ch, CURLOPT_TIMEOUT_MS, (int)round($timeoutSeconds * 1000));
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
                $body = curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if (is_string($body) && $body !== '' && $status >= 200 && $status < 300) {
                    $decoded = json_decode($body, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'header' => "Accept: application/json\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body) || $body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('shobidDetectCountryCodeFromGeoApi')) {
    function shobidDetectCountryCodeFromGeoApi() {
        $clientIp = shobidDetectClientIpForGeoApi();
        if ($clientIp === '') {
            return '';
        }

        $urls = [
            'https://ipwho.is/' . rawurlencode($clientIp),
            'https://ipapi.co/' . rawurlencode($clientIp) . '/json/',
        ];

        foreach ($urls as $url) {
            $json = shobidFetchGeoApiJson($url);
            if (!is_array($json)) {
                continue;
            }
            $candidate = shobidNormalizeCountryCode(
                (string)($json['country_code'] ?? ($json['countryCode'] ?? ''))
            );
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}

$countryToMarket = [
    'HU' => 'hu-hu',
    'DE' => 'de-de',
    'SK' => 'sk-sk',
    'GB' => 'uk-uk',
    'UK' => 'uk-uk',
    'PL' => 'pl-pl',
    'CZ' => 'cz-cz',
    'HR' => 'hr-hr',
    'AT' => 'au-au',
    'RO' => 'ro-ro',
];

$forceSelector = isset($_GET['country_selector']) && (string)$_GET['country_selector'] === '1';
$detectedCountryCode = shobidDetectCountryCodeFromRequest();
$resolvedCountryCode = $detectedCountryCode;

$canResolveGeoNow = !$forceSelector && !shobidIsCrawlerRequest();
if ($canResolveGeoNow && $resolvedCountryCode === '') {
    $geoApiCountryCode = shobidDetectCountryCodeFromGeoApi();
    if ($geoApiCountryCode !== '') {
        $resolvedCountryCode = $geoApiCountryCode;
    }
}

$resolvedMarketCode = ($resolvedCountryCode !== '' && isset($countryToMarket[$resolvedCountryCode]))
    ? strtolower((string)$countryToMarket[$resolvedCountryCode])
    : '';
if ($resolvedMarketCode !== '' && !in_array($resolvedMarketCode, $availableCodes, true)) {
    $resolvedMarketCode = '';
}

$autoRedirectMarketCode = '';
if ($resolvedMarketCode !== '') {
    $autoRedirectMarketCode = $resolvedMarketCode;
}

if (!$forceSelector && !shobidIsCrawlerRequest() && $autoRedirectMarketCode !== '') {
    if ($resolvedMarketCode !== '' && !headers_sent()) {
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie('shobid_market', $resolvedMarketCode, time() + 31536000, '/', '', $isHttps, false);
        $_COOKIE['shobid_market'] = $resolvedMarketCode;
    }
    header('Vary: CF-IPCountry, X-Country-Code, X-AppEngine-Country, CloudFront-Viewer-Country, X-Geo-Country, X-Country, Fastly-Country-Code, Fly-Country, X-Vercel-IP-Country, Cookie, User-Agent');
    header('Location: /' . rawurlencode($autoRedirectMarketCode) . '/index.php', true, 302);
    exit;
}

$showUnavailableMessage = !$forceSelector && $autoRedirectMarketCode === '';
$pageLang = $showUnavailableMessage ? 'en' : 'hu';
$pageTitle = $showUnavailableMessage ? 'Shobid - Not Available In Your Country' : 'Shobid Orszagvalaszto';
$pageKicker = $showUnavailableMessage ? 'Global' : 'Orszagvalaszto';
$pageHeading = $showUnavailableMessage ? 'Currently not available in your country.' : 'Valaszd ki az orszagot';
$pageDescription = $showUnavailableMessage
    ? 'Please choose an available country site to continue.'
    : 'A Shobid orszagonkent kulon utvonalon fut. Valassz piacot a belepeshez.';
$siteScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$siteHost = (string)($_SERVER['HTTP_HOST'] ?? 'shobid.com');
$siteBase = $siteScheme . '://' . $siteHost;
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($pageLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="canonical" href="<?php echo htmlspecialchars($siteBase . '/index.php'); ?>">
    <link rel="alternate" hreflang="x-default" href="<?php echo htmlspecialchars($siteBase . '/index.php'); ?>">
    <?php foreach ($availableCountries as $country): ?>
    <?php $code = strtolower((string)($country['code'] ?? '')); ?>
    <?php if ($code !== ''): ?>
    <link rel="alternate" hreflang="<?php echo htmlspecialchars($code); ?>" href="<?php echo htmlspecialchars($siteBase . '/' . rawurlencode($code) . '/index.php'); ?>">
    <?php endif; ?>
    <?php endforeach; ?>
    <link rel="stylesheet" href="/style.css?v=20260409-2">
</head>
<body class="country-selector-page">
    <main class="country-selector-shell">
        <section class="country-selector-card">
            <a class="auction-brand country-selector-brand" href="/index.php?country_selector=1">
                <img class="auction-brand__logo" src="/kepek/web_sb_logo.webp" alt="Shobid">
            </a>
            <div class="profile-kicker"><?php echo htmlspecialchars($pageKicker); ?></div>
            <h1><?php echo htmlspecialchars($pageHeading); ?></h1>
            <p><?php echo htmlspecialchars($pageDescription); ?></p>
            <div class="country-selector-grid">
                <?php foreach ($countries as $country): ?>
                <?php $isAvailable = !empty($country['available']); ?>
                <?php $targetHref = '/' . rawurlencode((string)$country['code']) . '/index.php'; ?>
                <?php if ($isAvailable): ?>
                <a class="country-selector-option" href="<?php echo htmlspecialchars($targetHref); ?>">
                <?php else: ?>
                <span class="country-selector-option is-unavailable">
                <?php endif; ?>
                    <span class="country-selector-option__code"><?php echo htmlspecialchars((string)$country['label']); ?></span>
                    <strong><?php echo htmlspecialchars((string)$country['name']); ?></strong>
                    <span><?php echo $isAvailable ? ($showUnavailableMessage ? 'Open site' : 'Belepes az oldalra') : ($showUnavailableMessage ? 'Not available yet' : 'Jelenleg nem elerheto'); ?></span>
                <?php if ($isAvailable): ?>
                </a>
                <?php else: ?>
                </span>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>
