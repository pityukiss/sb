<?php
// ==================== SHobid ROOT INDEX - ULTRA BIZTONSÁGOS ====================

// Hiba mutatása csak fejlesztéshez (élesben tedd false-ra)
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    include __DIR__ . '/global/markets.php';
} catch (Throwable $e) {
    // Ha bármi hiba van az include-ban, akkor is fusson tovább
}

$forceSelector = isset($_GET['country_selector']) && $_GET['country_selector'] == '1';

// Cookie alapú emlékezés (ha van)
if (!$forceSelector && isset($_COOKIE['shobid_market'])) {
    $market = strtolower(trim($_COOKIE['shobid_market']));
    if ($market !== '' && in_array($market, ['hu-hu', 'de-de', 'pl-pl', 'sk-sk', 'cz-cz'])) {
        header("Location: /$market/index.php", true, 302);
        exit;
    }
}

// Alapértelmezett Magyarország
$marketCode = 'hu-hu';

// Auto detect ha van függvény
if (!$forceSelector && function_exists('shobidDetectCountryCodeFromRequest')) {
    $detected = shobidDetectCountryCodeFromRequest();
    
    $mapping = [
        'HU' => 'hu-hu',
        'DE' => 'de-de',
        'PL' => 'pl-pl',
        'SK' => 'sk-sk',
        'CZ' => 'cz-cz',
        'HR' => 'hr-hr',
        'RO' => 'ro-ro'
    ];
    
    if (isset($mapping[$detected])) {
        $marketCode = $mapping[$detected];
    }
}

// Redirect
if (!$forceSelector) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('shobid_market', $marketCode, time() + 31536000, '/', '', $isHttps, true);
    
    header("Location: /$marketCode/index.php", true, 302);
    exit;
}

// Ha forceSelector = 1 vagy valami hiba történt → országválasztó
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shobid - Országválasztó</title>
    <style>
        body {font-family: Arial, sans-serif; background:#111; color:#fff; text-align:center; padding:80px 20px;}
        .card {max-width:700px; margin:0 auto; background:#1f1f1f; padding:50px; border-radius:16px;}
        h1 {color:#0f0;}
        .option {
            display:inline-block; margin:10px; padding:20px 30px; background:#222; 
            color:white; text-decoration:none; font-size:18px; border-radius:10px;
        }
        .option:hover {background:#0a0;}
    </style>
</head>
<body>
    <div class="card">
        <h1>Országválasztó</h1>
        <p>Válassz országot:</p>
        <a class="option" href="/hu-hu/index.php">🇭🇺 Magyarország</a><br><br>
        <a class="option" href="/de-de/index.php">🇩🇪 Németország</a><br><br>
        <a class="option" href="/pl-pl/index.php">🇵🇱 Lengyelország</a>
    </div>
</body>
</html>
