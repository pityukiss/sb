<?php
// ==================== SHobid ROOT INDEX - JOBB DETECT ====================

ini_set('display_errors', 0);
error_reporting(E_ALL);

include __DIR__ . '/global/markets.php';

$countries = function_exists('shobidMarketList') ? shobidMarketList() : [];
$availableCodes = [];
foreach ($countries as $c) {
    if (!empty($c['available']) && !empty($c['code'])) {
        $availableCodes[] = strtolower($c['code']);
    }
}

$forceSelector = isset($_GET['country_selector']) && $_GET['country_selector'] == '1';

// Cookie alapú emlékezés
if (!$forceSelector && isset($_COOKIE['shobid_market'])) {
    $market = strtolower(trim($_COOKIE['shobid_market']));
    if (in_array($market, $availableCodes)) {
        header("Location: /$market/index.php", true, 302);
        exit;
    }
}

// ====================== JOBB ORSZÁG DETECT ======================
$detectedMarket = 'hu-hu'; // alapértelmezett

// 1. Cloudflare header (legjobb, ha Cloudflare előtt van)
if (isset($_SERVER['HTTP_CF_IPCOUNTRY'])) {
    $cfCountry = strtoupper($_SERVER['HTTP_CF_IPCOUNTRY']);
    $cfMapping = ['HU'=>'hu-hu','DE'=>'de-de','PL'=>'pl-pl','SK'=>'sk-sk','CZ'=>'cz-cz','HR'=>'hr-hr','RO'=>'ro-ro','AT'=>'au-au','GB'=>'uk-uk'];
    if (isset($cfMapping[$cfCountry])) {
        $detectedMarket = $cfMapping[$cfCountry];
    }
}

// 2. Ha nincs CF, próbáljuk a szokásos header-eket
elseif (function_exists('shobidDetectCountryCodeFromRequest')) {
    $code = shobidDetectCountryCodeFromRequest();
    $mapping = ['HU'=>'hu-hu','DE'=>'de-de','PL'=>'pl-pl','SK'=>'sk-sk','CZ'=>'cz-cz','HR'=>'hr-hr','RO'=>'ro-ro','AT'=>'au-au','GB'=>'uk-uk','UK'=>'uk-uk'];
    if (isset($mapping[$code])) {
        $detectedMarket = $mapping[$code];
    }
}

// Redirect ha nem force selector
if (!$forceSelector) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('shobid_market', $detectedMarket, time() + 31536000, '/', '', $isHttps, true);
    
    header("Location: /$detectedMarket/index.php", true, 302);
    exit;
}

// ====================== ORSZÁGVÁLASZTÓ OLDAL ======================
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shobid - Országválasztó</title>
    <style>
        body {font-family: Arial, sans-serif; background:#0a0a0a; color:#eee; text-align:center; padding:60px 20px;}
        .card {max-width:680px; margin:0 auto; background:#1a1a1a; padding:50px; border-radius:16px;}
        h1 {color:#0f0;}
        .option {display:inline-block; margin:12px; padding:18px 32px; background:#222; color:white; text-decoration:none; font-size:18px; border-radius:10px;}
        .option:hover {background:#00bb00;}
    </style>
</head>
<body>
    <div class="card">
        <h1>Országválasztó</h1>
        <p>Válassz országot:</p>
        <?php foreach ($countries as $country): ?>
            <?php if (!empty($country['available']) && !empty($country['code'])): ?>
                <a class="option" href="/<?= strtolower(htmlspecialchars($country['code'])) ?>/index.php">
                    <?= htmlspecialchars($country['name'] ?? $country['code']) ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</body>
</html>
