<?php
// ==================== SHobid ROOT INDEX - ÁTMENETI (STABIL) ====================

include __DIR__ . '/global/markets.php';

$forceSelector = isset($_GET['country_selector']) && $_GET['country_selector'] == '1';


// Cookie alapú emlékezés
if (!$forceSelector && isset($_COOKIE['shobid_market'])) {
    $market = strtolower(trim($_COOKIE['shobid_market']));
    if (in_array($market, ['hu-hu','de-de','pl-pl','sk-sk','cz-cz','hr-hr','ro-ro'])) {
        header("Location: /$market/index.php", true, 302);
        exit;
    }
}

// Nyelv alapú detektálás (jelenleg ez a legerősebb, ami működik)
$lang = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'hu');
$market = 'hu-hu';

if (strpos($lang, 'de') !== false)      $market = 'de-de';
elseif (strpos($lang, 'pl') !== false)  $market = 'pl-pl';
elseif (strpos($lang, 'sk') !== false)  $market = 'sk-sk';
elseif (strpos($lang, 'cz') !== false || strpos($lang, 'cs') !== false) $market = 'cz-cz';
elseif (strpos($lang, 'hr') !== false)  $market = 'hr-hr';
elseif (strpos($lang, 'ro') !== false)  $market = 'ro-ro';

if (!$forceSelector) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('shobid_market', $market, time() + 31536000, '/', '', $isHttps, true);
    
    header("Location: /$market/index.php", true, 302);
    exit;
}
?>

<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shobid - Országválasztó</title>
    <style>
        body {font-family: Arial, sans-serif; background: #0a0a0a; color: #eee; text-align: center; padding: 80px 20px;}
        .card {max-width: 700px; margin: 0 auto; background: #1a1a1a; padding: 60px 40px; border-radius: 16px;}
        h1 {color: #0f0;}
        .option {display: block; margin: 15px auto; padding: 20px; background: #222; color: white; text-decoration: none; font-size: 19px; border-radius: 10px; max-width: 400px;}
        .option:hover {background: #00aa00;}
    </style>
</head>
<body>
    <div class="card">
        <h1>Országválasztó</h1>
        <p>Válassz országot a folytatáshoz:</p>
        <a class="option" href="/hu-hu/index.php">🇭🇺 Magyarország</a>
        <a class="option" href="/de-de/index.php">🇩🇪 Németország</a>
        <a class="option" href="/pl-pl/index.php">🇵🇱 Lengyelország</a>
    </div>
</body>
</html>
