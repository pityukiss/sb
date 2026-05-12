<?php
// ==================== SHobid ROOT INDEX - BIZTONSÁGOS VERZIÓ ====================

error_reporting(E_ALL);
ini_set('display_errors', 0);   // élesben ne mutassa a hibát

include __DIR__ . '/global/markets.php';

// Alap adatok
$countries = shobidMarketList ? shobidMarketList() : [];
$availableCodes = [];

foreach ($countries as $c) {
    if (!empty($c['available']) && !empty($c['code'])) {
        $availableCodes[] = strtolower($c['code']);
    }
}

$forceSelector = isset($_GET['country_selector']) && $_GET['country_selector'] == '1';

// 1. Cookie alapú redirect (ha már volt választás)
if (!$forceSelector && isset($_COOKIE['shobid_market'])) {
    $market = strtolower(trim($_COOKIE['shobid_market']));
    if (in_array($market, $availableCodes)) {
        header("Location: /$market/index.php", true, 302);
        exit;
    }
}

// 2. Auto detect (IP alapján)
$marketCode = 'hu-hu'; // alapértelmezett Magyarország

// Próbáljuk meg a meglévő detect függvényeket (ha léteznek)
if (function_exists('shobidDetectCountryCodeFromRequest')) {
    $detected = shobidDetectCountryCodeFromRequest();
    
    $mapping = [
        'HU' => 'hu-hu', 'DE' => 'de-de', 'PL' => 'pl-pl',
        'SK' => 'sk-sk', 'CZ' => 'cz-cz', 'HR' => 'hr-hr',
        'RO' => 'ro-ro', 'AT' => 'au-au', 'GB' => 'uk-uk', 
        'UK' => 'uk-uk'
    ];
    
    if ($detected !== '' && isset($mapping[$detected])) {
        $tryMarket = strtolower($mapping[$detected]);
        if (in_array($tryMarket, $availableCodes)) {
            $marketCode = $tryMarket;
        }
    }
}

// 3. Redirect ha talált támogatott országot
if (!$forceSelector && $marketCode !== '') {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    
    // Régi setcookie szintaxis (hogy régebbi PHP-n is menjen)
    setcookie('shobid_market', $marketCode, time() + 31536000, '/', '', $isHttps, true);
    
    header("Location: /$marketCode/index.php", true, 302);
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
        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #0f0f0f;
            color: #ffffff;
            text-align: center;
            padding: 60px 20px;
        }
        .card {
            max-width: 620px;
            margin: 0 auto;
            background: #1a1a1a;
            padding: 50px 30px;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.6);
        }
        h1 { color: #00ff00; margin-bottom: 10px; }
        p { font-size: 18px; line-height: 1.5; }
        .option {
            display: inline-block;
            margin: 12px;
            padding: 18px 30px;
            background: #222;
            color: white;
            text-decoration: none;
            font
