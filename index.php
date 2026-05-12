<?php
include __DIR__ . '/global/markets.php';

$countries = shobidMarketList();
$availableCountries = array_values(array_filter($countries, function ($country) {
    return !empty($country['available']);
}));
$availableCodes = array_map(function ($country) {
    return strtolower((string)($country['code'] ?? ''));
}, $availableCountries);

// ====================== REQUEST ÉS COMPATIBILITY ======================
$requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/index.php'), PHP_URL_PATH) ?? '/index.php');
$requestSegments = explode('/', trim($requestPath, '/'));
$requestMarketCode = strtolower((string)($requestSegments[0] ?? ''));

$looksLikeLocaleIndex = preg_match('#^/[a-z]{2}-[a-z]{2}/index\.php$#i', $requestPath) === 1;

if ($looksLikeLocaleIndex && in_array($requestMarketCode, $availableCodes, true)) {
    include __DIR__ . '/app_index.php';
    return;
}

// ====================== SEGÉDFÜGGVÉNYEK (maradnak) ======================
if (!function_exists('shobidIsCrawlerRequest')) {
    function shobidIsCrawlerRequest() { /* ... meglévő függvény ... */ }
}

// ... (a többi detect függvény marad ugyanaz: shobidNormalizeCountryCode, shobidDetectCountryCodeFromRequest stb.)

// ====================== COOKIE + AUTO DETECT ======================
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

$countryToMarket = [
    'HU' => 'hu-hu', 'DE' => 'de-de', 'PL' => 'pl-pl', 'SK' => 'sk-sk',
    'CZ' => 'cz-cz', 'HR' => 'hr-hr', 'RO' => 'ro-ro', 'AT' => 'au-au',
    'GB' => 'uk-uk', 'UK' => 'uk-uk',
];

$resolvedMarketCode = ($resolvedCountryCode !== '' && isset($countryToMarket[$resolvedCountryCode]))
    ? strtolower((string)$countryToMarket[$resolvedCountryCode])
    : '';

if ($resolvedMarketCode !== '' && !in_array($resolvedMarketCode, $availableCodes, true)) {
    $resolvedMarketCode = '';
}

$autoRedirectMarketCode = $resolvedMarketCode;

// ====================== COOKIE ALAPÚ EMLÉKEZÉS + REDIRECT ======================
if (!$forceSelector && !shobidIsCrawlerRequest() && $autoRedirectMarketCode !== '') {
    if (!headers_sent()) {
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        // Cookie 1 évre, HttpOnly + Secure
        setcookie('shobid_market', $autoRedirectMarketCode, [
            'expires' => time() + 31536000,
            'path'    => '/',
            'domain'  => '',
            'secure'  => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    header('Vary: CF-IPCountry, X-Country-Code, Cookie, User-Agent');
    header('Location: /' . rawurlencode($autoRedirectMarketCode) . '/index.php', true, 302);
    exit;
}

// ====================== ORSZÁGVÁLASZTÓ OLDAL ======================
$showUnavailableMessage = true;
$pageLang = 'hu';
$pageTitle = 'Shobid - Nem elérhető az országodban';
$pageKicker = 'Országválasztó';
$pageHeading = 'A Te országodban jelenleg nem elérhető';
$pageDescription = 'Sajnos a Shobid még nem működik a Te országodban. Válassz egy elérhető piacot a folytatáshoz.';

$siteScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$siteHost = (string)($_SERVER['HTTP_HOST'] ?? 'shobid.com');
$siteBase = $siteScheme . '://' . $siteHost;
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars($pageLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="canonical" href="<?= htmlspecialchars($siteBase . '/index.php') ?>">
    <link rel="stylesheet" href="/style.css?v=<?= time() ?>">
</head>
<body class="country-selector-page">
    <main class="country-selector-shell">
        <section class="country-selector-card">
            <a class="auction-brand country-selector-brand" href="/index.php?country_selector=1">
                <img class="auction-brand__logo" src="/kepek/web_sb_logo.webp" alt="Shobid">
            </a>
            
            <div class="profile-kicker"><?= htmlspecialchars($pageKicker) ?></div>
            <h1><?= htmlspecialchars($pageHeading) ?></h1>
            <p><?= htmlspecialchars($pageDescription) ?></p>

            <div class="country-selector-grid">
                <?php foreach ($countries as $country): ?>
                    <?php 
                    $isAvailable = !empty($country['available']); 
                    $code = strtolower((string)($country['code'] ?? ''));
                    $target = '/' . rawurlencode($code) . '/index.php';
                    ?>
                    <?php if ($isAvailable): ?>
                        <a class="country-selector-option" href="<?= htmlspecialchars($target) ?>">
                    <?php else: ?>
                        <span class="country-selector-option is-unavailable">
                    <?php endif; ?>
                        <span class="country-selector-option__code"><?= htmlspecialchars($country['label'] ?? '') ?></span>
                        <strong><?= htmlspecialchars($country['name'] ?? '') ?></strong>
                        <span><?= $isAvailable ? 'Belépés' : 'Hamarosan' ?></span>
                    <?= $isAvailable ? '</a>' : '</span>' ?>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>
