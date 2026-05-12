<?php
include __DIR__ . '/db.php';
include __DIR__ . '/auth.php';
include __DIR__ . '/email_helper.php';
include __DIR__ . '/stripe_helper.php';
include_once __DIR__ . '/payout_helper.php';
include_once __DIR__ . '/global/i18n.php';

shobidPayoutMaybeRunDailyAuto($conn);
if (function_exists('shobidMarketEnsureTermekekOrszagKod')) {
    shobidMarketEnsureTermekekOrszagKod($conn);
}
if (function_exists('shobidStripeEnsureTermekPenznemOszlop')) {
    shobidStripeEnsureTermekPenznemOszlop($conn);
}
$adminPiacKod = function_exists('shobidMarketCurrentCode') ? shobidMarketCurrentCode() : 'hu-hu';
$adminPenznemKod = function_exists('shobidStripeCurrencyByLocale') ? shobidStripeCurrencyByLocale($adminPiacKod) : 'huf';

function tablaLetezik($conn, $tablaNev) {
    $tablaNev = $conn->real_escape_string($tablaNev);
    $res = $conn->query("SHOW TABLES LIKE '$tablaNev'");
    return $res && $res->num_rows > 0;
}

function oszlopLetezik($conn, $tablaNev, $oszlopNev) {
    $tablaNev = $conn->real_escape_string($tablaNev);
    $oszlopNev = $conn->real_escape_string($oszlopNev);
    $res = $conn->query("SHOW COLUMNS FROM `$tablaNev` LIKE '$oszlopNev'");
    return $res && $res->num_rows > 0;
}

function uzenetIdoSql($conn, $alias = 'u') {
    foreach (['letrehozva', 'created_at', 'datum', 'uzenet_ideje'] as $oszlop) {
        if (oszlopLetezik($conn, 'uzenetek', $oszlop)) {
            return "$alias.$oszlop";
        }
    }
    return "NOW()";
}

function feldolgozottAukcioKepMenteseAdmin($tmpPath, $celPath) {
    if (!function_exists('getimagesize')) {
        return false;
    }

    $info = @getimagesize($tmpPath);
    if (!$info || empty($info[0]) || empty($info[1])) {
        return false;
    }

    $mime = strtolower((string)($info['mime'] ?? ''));
    if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
        $forras = @imagecreatefromjpeg($tmpPath);
    } elseif ($mime === 'image/png') {
        $forras = @imagecreatefrompng($tmpPath);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $forras = @imagecreatefromwebp($tmpPath);
    } elseif ($mime === 'image/gif') {
        $forras = @imagecreatefromgif($tmpPath);
    } else {
        return false;
    }

    if (!$forras) {
        return false;
    }

    $forrasSzelesseg = imagesx($forras);
    $forrasMagassag = imagesy($forras);
    if ($forrasSzelesseg < 1 || $forrasMagassag < 1) {
        imagedestroy($forras);
        return false;
    }

    $celSzelesseg = 720;
    $celMagassag = 1280;
    $celArany = $celSzelesseg / $celMagassag;
    $forrasArany = $forrasSzelesseg / $forrasMagassag;

    if ($forrasArany > $celArany) {
        $vagasMagassag = $forrasMagassag;
        $vagasSzelesseg = (int) round($forrasMagassag * $celArany);
        $vagasX = (int) round(($forrasSzelesseg - $vagasSzelesseg) / 2);
        $vagasY = 0;
    } else {
        $vagasSzelesseg = $forrasSzelesseg;
        $vagasMagassag = (int) round($forrasSzelesseg / $celArany);
        $vagasX = 0;
        $vagasY = (int) round(($forrasMagassag - $vagasMagassag) / 2);
    }

    $cel = imagecreatetruecolor($celSzelesseg, $celMagassag);
    imagealphablending($cel, true);
    imagesavealpha($cel, true);
    $hatter = imagecolorallocate($cel, 255, 255, 255);
    imagefill($cel, 0, 0, $hatter);

    $siker = imagecopyresampled(
        $cel,
        $forras,
        0,
        0,
        $vagasX,
        $vagasY,
        $celSzelesseg,
        $celMagassag,
        $vagasSzelesseg,
        $vagasMagassag
    );

    if (!$siker) {
        imagedestroy($forras);
        imagedestroy($cel);
        return false;
    }

    $mentve = function_exists('imagewebp')
        ? @imagewebp($cel, $celPath, 82)
        : @imagejpeg($cel, $celPath, 84);

    imagedestroy($forras);
    imagedestroy($cel);
    return (bool) $mentve;
}

function mentsKepAdmin($fileKey, $default = 'nincs_kep.jpg') {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== 0) {
        return $default;
    }

    $kepekDir = __DIR__ . '/kepek/ajanlatok';
    if (!is_dir($kepekDir)) {
        mkdir($kepekDir, 0777, true);
    }

    $eredetiNev = basename($_FILES[$fileKey]['name']);
    $biztonsagosNev = preg_replace('/[^A-Za-z0-9._-]/', '_', $eredetiNev);
    $alapNev = pathinfo($biztonsagosNev, PATHINFO_FILENAME);
    $fajlNev = time() . '_' . mt_rand(1000, 9999) . '_' . $alapNev . '.webp';
    $celPath = $kepekDir . '/' . $fajlNev;

    if (feldolgozottAukcioKepMenteseAdmin($_FILES[$fileKey]['tmp_name'], $celPath)) {
        return 'ajanlatok/' . $fajlNev;
    }

    $fallbackNev = time() . '_' . mt_rand(1000, 9999) . '_' . $biztonsagosNev;
    move_uploaded_file($_FILES[$fileKey]['tmp_name'], $kepekDir . '/' . $fallbackNev);
    return 'ajanlatok/' . $fallbackNev;
}

function mentsKepAdminEredetiArannyal($fileKey, $default = 'nincs_kep.jpg') {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== 0) {
        return $default;
    }

    $kepekDir = __DIR__ . '/kepek';
    if (!is_dir($kepekDir)) {
        mkdir($kepekDir, 0777, true);
    }

    $eredetiNev = basename($_FILES[$fileKey]['name']);
    $biztonsagosNev = preg_replace('/[^A-Za-z0-9._-]/', '_', $eredetiNev);
    $alapNev = pathinfo($biztonsagosNev, PATHINFO_FILENAME);
    $kiterjesztes = strtolower((string)pathinfo($biztonsagosNev, PATHINFO_EXTENSION));
    $engedelyezett = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($kiterjesztes, $engedelyezett, true)) {
        $kiterjesztes = 'jpg';
    }

    $fajlNev = time() . '_' . mt_rand(1000, 9999) . '_' . $alapNev . '.' . $kiterjesztes;
    $celPath = $kepekDir . '/' . $fajlNev;

    if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $celPath)) {
        return $fajlNev;
    }

    return $default;
}

function osszesKategoriaKepPath() {
    return __DIR__ . '/osszes_kategoria_kep.txt';
}

function kozelgoKategoriaKepPath() {
    return __DIR__ . '/kozelgo_kategoria_kep.txt';
}

function mostKategoriaKepPath() {
    return __DIR__ . '/most_kategoria_kep.txt';
}

function eloKategoriaKepPath() {
    return __DIR__ . '/elo_kategoria_kep.txt';
}

function kuponOsszesKategoriaKepPath() {
    return __DIR__ . '/kupon_osszes_kategoria_kep.txt';
}

function getOsszesKategoriaKep() {
    $path = osszesKategoriaKepPath();
    if (!file_exists($path)) {
        return '';
    }
    return trim((string) file_get_contents($path));
}

function setOsszesKategoriaKep($filename) {
    file_put_contents(osszesKategoriaKepPath(), trim((string) $filename));
}

function getKozelgoKategoriaKep() {
    $path = kozelgoKategoriaKepPath();
    if (!file_exists($path)) {
        return '';
    }
    return trim((string) file_get_contents($path));
}

function setKozelgoKategoriaKep($filename) {
    file_put_contents(kozelgoKategoriaKepPath(), trim((string) $filename));
}

function getMostKategoriaKep() {
    $path = mostKategoriaKepPath();
    if (!file_exists($path)) {
        return '';
    }
    return trim((string) file_get_contents($path));
}

function setMostKategoriaKep($filename) {
    file_put_contents(mostKategoriaKepPath(), trim((string) $filename));
}

function getEloKategoriaKep() {
    $path = eloKategoriaKepPath();
    if (!file_exists($path)) {
        return '';
    }
    return trim((string) file_get_contents($path));
}

function setEloKategoriaKep($filename) {
    file_put_contents(eloKategoriaKepPath(), trim((string) $filename));
}

function getKuponOsszesKategoriaKep() {
    $path = kuponOsszesKategoriaKepPath();
    if (!file_exists($path)) {
        return '';
    }
    return trim((string) file_get_contents($path));
}

function setKuponOsszesKategoriaKep($filename) {
    file_put_contents(kuponOsszesKategoriaKepPath(), trim((string) $filename));
}

function ajanlatBeallitasokPath() {
    return __DIR__ . '/ajanlat_beallitasok.json';
}

function alapAjanlatBeallitasok() {
    $defaultVatMap = function_exists('shobidPayoutDefaultVatPercentByLocale')
        ? shobidPayoutDefaultVatPercentByLocale()
        : ['hu-hu' => 27.0];
    $defaultCardFeeMap = function_exists('shobidPayoutDefaultCardFeePercentByLocale')
        ? shobidPayoutDefaultCardFeePercentByLocale()
        : ['hu-hu' => 2.2];
    return [
        'licit_max_ora' => 24,
        'fix_max_ora' => 24,
        'vat_by_country_percent' => $defaultVatMap,
        'card_fee_percent_by_country' => $defaultCardFeeMap
    ];
}

function getAjanlatBeallitasok() {
    $defaults = alapAjanlatBeallitasok();
    $path = ajanlatBeallitasokPath();
    if (!file_exists($path)) {
        return $defaults;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    return array_merge($defaults, $decoded);
}

function setAjanlatBeallitasok($settings) {
    file_put_contents(ajanlatBeallitasokPath(), json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function penzugyiSzamitas($bruttoOsszeg, $locale = 'hu-hu') {
    $afaKulcs = function_exists('shobidPayoutVatRateByLocale')
        ? floatval(shobidPayoutVatRateByLocale($locale))
        : 0.27;
    $cardFeeRate = function_exists('shobidPayoutCardFeePercentByLocale')
        ? max(0.0, floatval(shobidPayoutCardFeePercentByLocale($locale)) / 100)
        : 0.022;
    $brutto = max(0, floatval($bruttoOsszeg));
    $jutalekBrutto = $brutto * 0.078 * (1 + $afaKulcs);
    $kartyaBrutto = $brutto * $cardFeeRate * (1 + $afaKulcs);
    $kifizetesBrutto = $brutto - ($jutalekBrutto + $kartyaBrutto);
    $netto = $brutto / (1 + $afaKulcs);
    $jutalekNetto = $jutalekBrutto / (1 + $afaKulcs);
    $kartyaNetto = $kartyaBrutto / (1 + $afaKulcs);
    $kifizetesNetto = $kifizetesBrutto / (1 + $afaKulcs);
    return [
        'brutto' => $brutto,
        'netto' => $netto,
        'jutalek_netto' => $jutalekNetto,
        'kartya_netto' => $kartyaNetto,
        'kifizetes_netto' => $kifizetesNetto,
        'jutalek_brutto' => $jutalekBrutto,
        'kartya_brutto' => $kartyaBrutto,
        'kifizetes_brutto' => $kifizetesBrutto
    ];
}

function penzugyiStatuszTablaVan($conn) {
    static $cache = null;
    if ($cache === null) {
        $cache = tablaLetezik($conn, 'penzugyi_statuszok');
        if (!$cache) {
            $cache = (bool) $conn->query("CREATE TABLE IF NOT EXISTS penzugyi_statuszok (
                azonosito VARCHAR(191) NOT NULL,
                statusz VARCHAR(50) NOT NULL DEFAULT 'Postázásra vár',
                frissitve TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (azonosito)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        }
    }
    return $cache;
}

function penzugyiMuveletKulcs($azonosito, $muveletTipus) {
    return trim((string)$azonosito) . '|' . trim((string)$muveletTipus);
}

function penzugyiMuveletAzonosito($tipus, $termekId = 0, $vasarloNev = '', $uzenetId = 0) {
    $tipus = trim((string)$tipus);
    if ($tipus === 'fix' || $tipus === 'multi') {
        return $tipus . '|uzenet|' . intval($uzenetId);
    }
    return 'licit|termek|' . intval($termekId) . '|vevo|' . trim((string)$vasarloNev);
}

function penzugyiMuveletekTablaVan($conn) {
    static $cache = null;
    if ($cache === null) {
        $cache = tablaLetezik($conn, 'penzugyi_muveletek');
        if (!$cache) {
            $cache = (bool) $conn->query("CREATE TABLE IF NOT EXISTS penzugyi_muveletek (
                azonosito VARCHAR(191) NOT NULL,
                postara_adva TINYINT(1) NOT NULL DEFAULT 0,
                megkapta TINYINT(1) NOT NULL DEFAULT 0,
                frissitve TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (azonosito)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        }
        if ($cache) {
            if (!oszlopLetezik($conn, 'penzugyi_muveletek', 'tracking_kod')) {
                $conn->query("ALTER TABLE penzugyi_muveletek ADD tracking_kod VARCHAR(255) NULL");
            }
            if (!oszlopLetezik($conn, 'penzugyi_muveletek', 'posta_dokumentum')) {
                $conn->query("ALTER TABLE penzugyi_muveletek ADD posta_dokumentum VARCHAR(255) NULL");
            }
        }
    }
    return $cache;
}

function normalizaltPenzugyiStatusz($statusz) {
    $engedelyezett = ['Postázásra vár', 'Postázva', 'Kiutalva'];
    return in_array($statusz, $engedelyezett, true) ? $statusz : 'Postázásra vár';
}

function penzugyiSorAzonosito($tipus, $termekId, $vasarloNev = '', $tetelNev = '', $datum = '') {
    return sha1(implode('|', [$tipus, intval($termekId), trim((string)$vasarloNev), trim((string)$tetelNev), trim((string)$datum)]));
}

function penzugyiTipusLabel($tipus) {
    if ($tipus === 'fix') return 'Fix ár';
    if ($tipus === 'kupon') return 'Kupon';
    if ($tipus === 'multi') return 'LICIT SHOP';
    return 'Licit';
}

function penzugyiDatumSzoveg($row) {
    $start = !empty($row['kezdes_idopont']) ? date('Y.m.d H:i', strtotime((string)$row['kezdes_idopont'])) : '-';
    $endForras = !empty($row['esemeny_datum']) ? (string)$row['esemeny_datum'] : (string)($row['lejarat_idopont'] ?? '');
    $end = $endForras !== '' ? date('Y.m.d H:i', strtotime($endForras)) : '-';
    return $start . ' - ' . $end;
}

function normalizaltForditasKulcsAdmin($kulcs) {
    $kulcs = strtolower(trim((string)$kulcs));
    if ($kulcs === '') {
        return '';
    }
    $kulcs = preg_replace('/[^a-z0-9._-]/', '_', $kulcs);
    $kulcs = preg_replace('/_{2,}/', '_', (string)$kulcs);
    return trim((string)$kulcs, '_');
}

function adminForditasUrl($params = []) {
    $query = ['panel' => 'forditasok'];

    if (isset($params['forditas_q'])) {
        $q = trim((string)$params['forditas_q']);
        if ($q !== '') {
            $query['forditas_q'] = $q;
        }
    }

    if (isset($params['forditas_page'])) {
        $page = max(1, intval($params['forditas_page']));
        if ($page > 1) {
            $query['forditas_page'] = $page;
        }
    }

    if (isset($params['mentve'])) {
        $query['mentve'] = (string)$params['mentve'];
    }

    return 'admin.php?' . http_build_query($query);
}

function adminKategoriaForditasokFromPost($input, $localeKodok) {
    $out = [];
    $inputArr = is_array($input) ? $input : [];
    foreach ($localeKodok as $localeKod) {
        $out[$localeKod] = trim((string)($inputArr[$localeKod] ?? ''));
    }
    return $out;
}

$multiTablaVan = tablaLetezik($conn, 'multi_aukciok') && tablaLetezik($conn, 'multi_aukcio_tetelek');
$durationOptions = [
    30 => st('admin.duration.30s', '30 másodperc'),
    60 => st('admin.duration.60s', '1 perc'),
    120 => st('admin.duration.120s', '2 perc'),
    300 => st('admin.duration.300s', '5 perc')
];
$ajanlatBeallitasok = getAjanlatBeallitasok();
$licitMaxOra = max(1, intval($ajanlatBeallitasok['licit_max_ora'] ?? 24));
$fixMaxOra = max(1, intval($ajanlatBeallitasok['fix_max_ora'] ?? 24));
$adminPiacLista = function_exists('shobidMarketList') ? shobidMarketList() : [];
$adminAfaByCountry = function_exists('shobidPayoutDefaultVatPercentByLocale')
    ? shobidPayoutDefaultVatPercentByLocale()
    : ['hu-hu' => 27.0];
 $adminCardFeeByCountry = function_exists('shobidPayoutDefaultCardFeePercentByLocale')
    ? shobidPayoutDefaultCardFeePercentByLocale()
    : ['hu-hu' => 2.2];
if (isset($ajanlatBeallitasok['vat_by_country_percent']) && is_array($ajanlatBeallitasok['vat_by_country_percent'])) {
    foreach ($ajanlatBeallitasok['vat_by_country_percent'] as $countryCode => $vatPercent) {
        $normalizedCode = function_exists('shobidPayoutNormalizeLocale')
            ? shobidPayoutNormalizeLocale((string)$countryCode, (string)$countryCode)
            : strtolower(trim((string)$countryCode));
        if ($normalizedCode === '') {
            continue;
        }
        $adminAfaByCountry[$normalizedCode] = max(0.0, min(99.0, floatval($vatPercent)));
    }
}
if (isset($ajanlatBeallitasok['card_fee_percent_by_country']) && is_array($ajanlatBeallitasok['card_fee_percent_by_country'])) {
    foreach ($ajanlatBeallitasok['card_fee_percent_by_country'] as $countryCode => $cardPercent) {
        $normalizedCode = function_exists('shobidPayoutNormalizeLocale')
            ? shobidPayoutNormalizeLocale((string)$countryCode, (string)$countryCode)
            : strtolower(trim((string)$countryCode));
        if ($normalizedCode === '') {
            continue;
        }
        $adminCardFeeByCountry[$normalizedCode] = max(0.0, min(99.0, floatval($cardPercent)));
    }
}
$adminPanel = $_GET['panel'] ?? 'dashboard';
$adminSort = $_GET['sorrend'] ?? 'legujabb';
$adminKategoriaszuro = intval($_GET['kategoria'] ?? 0);
$adminPenzugyUser = intval($_GET['penzugy_user'] ?? 0);
$adminPenzugyQ = trim($_GET['penzugy_q'] ?? '');
$adminForditasQ = trim((string)($_GET['forditas_q'] ?? ''));
$adminForditasPage = max(1, intval($_GET['forditas_page'] ?? 1));
$adminLoginError = '';
$adminConfigReady = authAdminConfigReady();
$forditasLocaleKodok = function_exists('shobidI18nSupportedLocales') ? shobidI18nSupportedLocales() : ['hu-hu'];
$i18nAlapLocale = function_exists('shobidI18nDefaultLocale') ? shobidI18nDefaultLocale() : 'hu-hu';
$i18nAlapLocaleOpcio = [];
foreach ($forditasLocaleKodok as $localeKod) {
    $market = function_exists('shobidMarketByCode') ? shobidMarketByCode($localeKod) : null;
    $localeNev = trim((string)($market['name'] ?? ''));
    $i18nAlapLocaleOpcio[$localeKod] = strtoupper((string)$localeKod) . ($localeNev !== '' ? (' - ' . $localeNev) : '');
}
$kiemeltFelhasznaloOszlop = oszlopLetezik($conn, 'felhasznalok', 'kiemelt_felhasznalo');
$cegnevOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'cegnev');
$cegesSzekhelyOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'ceges_szekhely');
$adoszamOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'adoszam');
$lakcimIranyitoszamOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'lakcim_iranyitoszam');
$lakcimVarosOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'lakcim_varos');
$lakcimUtcaOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'lakcim_utca');
$lakcimHazszamOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'lakcim_hazszam');
$cegesIranyitoszamOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'ceges_iranyitoszam');
$cegesVarosOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'ceges_varos');
$cegesUtcaOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'ceges_utca');
$cegesHazszamOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'ceges_hazszam');
$cegkentVasarolokOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'cegkent_vasarolok');
if (!$cegkentVasarolokOszlopVan) {
    $conn->query("ALTER TABLE felhasznalok ADD cegkent_vasarolok TINYINT(1) NOT NULL DEFAULT 0");
    $cegkentVasarolokOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'cegkent_vasarolok');
}
$szallitasiNevOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'szallitasi_nev');
$szallitasiIranyitoszamOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'szallitasi_iranyitoszam');
$szallitasiVarosOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'szallitasi_varos');
$szallitasiUtcaOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'szallitasi_utca');
$szallitasiHazszamOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'szallitasi_hazszam');
$szallitasiEmeletAjtoOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'szallitasi_emelet_ajto');
$szallitasiMegjegyzesOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'szallitasi_megjegyzes');
$eredetiArOszlopVan = oszlopLetezik($conn, 'termekek', 'eredeti_ar');
$varosOszlopVan = oszlopLetezik($conn, 'termekek', 'varos');
$kategoriaSorrendOszlopVan = oszlopLetezik($conn, 'kategoriak', 'sorrend');
$licitStartedOszlopVan = oszlopLetezik($conn, 'termekek', 'licit_started_at');
$liveUrlOszlopVan = oszlopLetezik($conn, 'termekek', 'live_url');
$liveAktivOszlopVan = oszlopLetezik($conn, 'termekek', 'live_active');
$licitIdotartamOszlopVan = oszlopLetezik($conn, 'termekek', 'licit_idotartam_mp');
$multiAktivTetelIndexOszlopVan = oszlopLetezik($conn, 'multi_aukciok', 'aktiv_tetel_index');
$multiAktivTetelStartOszlopVan = oszlopLetezik($conn, 'multi_aukciok', 'aktiv_tetel_start');

if (!isset($_SESSION['admin_belepve'])) {
    if (isset($_POST['pass'])) {
        if (!authValidateCsrfFromRequest()) {
            $adminLoginError = 'CSRF token hiba. Frissitsd az oldalt, es probald ujra.';
        } elseif (!$adminConfigReady) {
            $adminLoginError = 'Az admin belepes nincs beallitva (ADMIN_PASSWORD_HASH hianyzik).';
        } elseif (!authAdminCanAttempt()) {
            $adminLoginError = 'Tobb hibas probalkozas volt. Probald ujra ' . authAdminSecondsUntilAllowed() . ' mp mulva.';
        } elseif (authAdminVerifyPassword((string)($_POST['pass'] ?? ''))) {
            authAdminRegisterAttempt(true);
            session_regenerate_id(true);
            $_SESSION['admin_belepve'] = true;
            header('Location: admin.php');
            exit;
        } else {
            authAdminRegisterAttempt(false);
            $adminLoginError = st('admin.login.invalid_password', 'Hibás admin jelszó.');
        }
    }
}

$isAdmin = !empty($_SESSION['admin_belepve']);
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['pass']) && !authValidateCsrfFromRequest()) {
    http_response_code(403);
    die(st('admin.validation.csrf_error', 'CSRF token hiba.'));
}

if ($isAdmin && isset($_POST['forditas_kulcs_hozzaad'])) {
    $ujKulcs = normalizaltForditasKulcsAdmin($_POST['uj_forditas_kulcs'] ?? '');
    $alapSzoveg = trim((string)($_POST['uj_forditas_alap'] ?? ($_POST['uj_forditas_hu'] ?? '')));
    $aktualisAlapLocale = function_exists('shobidI18nDefaultLocale') ? shobidI18nDefaultLocale() : 'hu-hu';
    if ($ujKulcs !== '') {
        $localeMessages = [];
        foreach ($forditasLocaleKodok as $localeKod) {
            $localeMessages[$localeKod] = shobidI18nLoadLocaleMessages($localeKod);
            if (!array_key_exists($ujKulcs, $localeMessages[$localeKod])) {
                $localeMessages[$localeKod][$ujKulcs] = '';
            }
        }
        if (isset($localeMessages[$aktualisAlapLocale])) {
            $localeMessages[$aktualisAlapLocale][$ujKulcs] = $alapSzoveg;
        } else {
            $elsoLocale = $forditasLocaleKodok[0] ?? 'hu-hu';
            $localeMessages[$elsoLocale][$ujKulcs] = $alapSzoveg;
        }
        foreach ($localeMessages as $localeKod => $messages) {
            shobidI18nSaveLocaleMessages($localeKod, $messages);
        }
    }
    header('Location: ' . adminForditasUrl([
        'forditas_q' => $_POST['forditas_q'] ?? '',
        'forditas_page' => $_POST['forditas_page'] ?? 1
    ]));
    exit;
}

if ($isAdmin && isset($_POST['forditas_kulcs_torles'])) {
    $torlendoKulcs = normalizaltForditasKulcsAdmin($_POST['forditas_kulcs_torles'] ?? '');
    if ($torlendoKulcs !== '') {
        foreach ($forditasLocaleKodok as $localeKod) {
            $messages = shobidI18nLoadLocaleMessages($localeKod);
            if (array_key_exists($torlendoKulcs, $messages)) {
                unset($messages[$torlendoKulcs]);
                shobidI18nSaveLocaleMessages($localeKod, $messages);
            }
        }
    }
    header('Location: ' . adminForditasUrl([
        'forditas_q' => $_POST['forditas_q'] ?? '',
        'forditas_page' => $_POST['forditas_page'] ?? 1
    ]));
    exit;
}

if ($isAdmin && isset($_POST['forditasok_mentes'])) {
    $kulcsLista = $_POST['forditas_kulcsok'] ?? [];
    if (!is_array($kulcsLista)) {
        $kulcsLista = [];
    }
    $kulcsLista = array_values(array_unique(array_filter(array_map('normalizaltForditasKulcsAdmin', $kulcsLista))));
    if (!empty($kulcsLista)) {
        $bekuldott = $_POST['forditas'] ?? [];
        if (!is_array($bekuldott)) {
            $bekuldott = [];
        }
        foreach ($forditasLocaleKodok as $localeKod) {
            $messages = shobidI18nLoadLocaleMessages($localeKod);
            foreach ($kulcsLista as $kulcs) {
                if (!array_key_exists($kulcs, $messages)) {
                    $messages[$kulcs] = '';
                }
                if (isset($bekuldott[$localeKod]) && is_array($bekuldott[$localeKod]) && array_key_exists($kulcs, $bekuldott[$localeKod])) {
                    $messages[$kulcs] = trim((string)$bekuldott[$localeKod][$kulcs]);
                }
            }
            shobidI18nSaveLocaleMessages($localeKod, $messages);
        }
    }
    header('Location: ' . adminForditasUrl([
        'forditas_q' => $_POST['forditas_q'] ?? '',
        'forditas_page' => $_POST['forditas_page'] ?? 1,
        'mentve' => 1
    ]));
    exit;
}

if ($isAdmin && isset($_POST['aukcio_torles'])) {
    $torlesId = intval($_POST['aukcio_torles']);
    if ($multiTablaVan) {
        $multiTorlesRes = $conn->query("SELECT id FROM multi_aukciok WHERE termek_id = " . intval($torlesId) . " LIMIT 1");
        $multiTorles = $multiTorlesRes ? $multiTorlesRes->fetch_assoc() : null;
        if ($multiTorles) {
            $multiId = intval($multiTorles['id']);
            $delItemsStmt = $conn->prepare("DELETE FROM multi_aukcio_tetelek WHERE multi_aukcio_id = ?");
            if ($delItemsStmt) {
                $delItemsStmt->bind_param('i', $multiId);
                $delItemsStmt->execute();
                $delItemsStmt->close();
            }
            $delMultiStmt = $conn->prepare("DELETE FROM multi_aukciok WHERE id = ?");
            if ($delMultiStmt) {
                $delMultiStmt->bind_param('i', $multiId);
                $delMultiStmt->execute();
                $delMultiStmt->close();
            }
        }
    }
    $delTermekStmt = $conn->prepare("DELETE FROM termekek WHERE id = ?");
    if ($delTermekStmt) {
        $delTermekStmt->bind_param('i', $torlesId);
        $delTermekStmt->execute();
        $delTermekStmt->close();
    }
    header('Location: admin.php');
    exit;
}

if ($isAdmin && isset($_POST['felhasznalo_torles'])) {
    $felhasznaloTorlesId = intval($_POST['felhasznalo_torles']);
    if ($felhasznaloTorlesId > 0) {
        $delUserStmt = $conn->prepare("DELETE FROM felhasznalok WHERE id = ?");
        if ($delUserStmt) {
            $delUserStmt->bind_param('i', $felhasznaloTorlesId);
            $delUserStmt->execute();
            $delUserStmt->close();
        }
    }
    header('Location: admin.php?panel=felhasznalok');
    exit;
}

if ($isAdmin && isset($_POST['felhasznalo_mentes'])) {
    $felhasznaloId = intval($_POST['felhasznalo_id'] ?? 0);
    $felhasznaloBecenev = $conn->real_escape_string(trim($_POST['felhasznalo_becenev'] ?? ''));
    $felhasznaloTeljesNev = $conn->real_escape_string(trim($_POST['felhasznalo_teljes_nev'] ?? ''));
    $felhasznaloEmail = $conn->real_escape_string(trim($_POST['felhasznalo_email'] ?? ''));
    $felhasznaloTelefonszam = $conn->real_escape_string(trim($_POST['felhasznalo_telefonszam'] ?? ''));
    $felhasznaloLakcimIranyitoszamRaw = trim($_POST['felhasznalo_lakcim_iranyitoszam'] ?? '');
    $felhasznaloLakcimVarosRaw = trim($_POST['felhasznalo_lakcim_varos'] ?? '');
    $felhasznaloLakcimUtcaRaw = trim($_POST['felhasznalo_lakcim_utca'] ?? '');
    $felhasznaloLakcimHazszamRaw = trim($_POST['felhasznalo_lakcim_hazszam'] ?? '');
    $felhasznaloLakcim = $conn->real_escape_string(trim(implode(', ', array_filter([
        trim($felhasznaloLakcimIranyitoszamRaw . ' ' . $felhasznaloLakcimVarosRaw),
        $felhasznaloLakcimUtcaRaw,
        $felhasznaloLakcimHazszamRaw
    ]))));
    $felhasznaloCegnev = $conn->real_escape_string(trim($_POST['felhasznalo_cegnev'] ?? ''));
    $felhasznaloCegesIranyitoszamRaw = trim($_POST['felhasznalo_ceges_iranyitoszam'] ?? '');
    $felhasznaloCegesVarosRaw = trim($_POST['felhasznalo_ceges_varos'] ?? '');
    $felhasznaloCegesUtcaRaw = trim($_POST['felhasznalo_ceges_utca'] ?? '');
    $felhasznaloCegesHazszamRaw = trim($_POST['felhasznalo_ceges_hazszam'] ?? '');
    $felhasznaloCegesSzekhely = $conn->real_escape_string(trim(implode(', ', array_filter([
        trim($felhasznaloCegesIranyitoszamRaw . ' ' . $felhasznaloCegesVarosRaw),
        $felhasznaloCegesUtcaRaw,
        $felhasznaloCegesHazszamRaw
    ]))));
    $felhasznaloAdoszam = $conn->real_escape_string(trim($_POST['felhasznalo_adoszam'] ?? ''));
    $felhasznaloSzallitasiNev = $conn->real_escape_string(trim($_POST['felhasznalo_szallitasi_nev'] ?? ''));
    $felhasznaloSzallitasiIranyitoszam = $conn->real_escape_string(trim($_POST['felhasznalo_szallitasi_iranyitoszam'] ?? ''));
    $felhasznaloSzallitasiVaros = $conn->real_escape_string(trim($_POST['felhasznalo_szallitasi_varos'] ?? ''));
    $felhasznaloSzallitasiUtca = $conn->real_escape_string(trim($_POST['felhasznalo_szallitasi_utca'] ?? ''));
    $felhasznaloSzallitasiHazszam = $conn->real_escape_string(trim($_POST['felhasznalo_szallitasi_hazszam'] ?? ''));
    $felhasznaloSzallitasiEmeletAjto = $conn->real_escape_string(trim($_POST['felhasznalo_szallitasi_emelet_ajto'] ?? ''));
    $felhasznaloSzallitasiMegjegyzes = $conn->real_escape_string(trim($_POST['felhasznalo_szallitasi_megjegyzes'] ?? ''));
    $felhasznaloCegkentVasarolok = isset($_POST['felhasznalo_cegkent_vasarolok']) ? 1 : 0;
    $felhasznaloKiemelt = isset($_POST['felhasznalo_kiemelt']) ? 1 : 0;

    if ($felhasznaloId > 0) {
        $sql = "UPDATE felhasznalok SET becenev = '$felhasznaloBecenev', teljes_nev = '$felhasznaloTeljesNev', email = '$felhasznaloEmail', telefonszam = '$felhasznaloTelefonszam', lakcim = '$felhasznaloLakcim'";
        if ($lakcimIranyitoszamOszlopVan) {
            $sql .= ", lakcim_iranyitoszam = '" . $conn->real_escape_string($felhasznaloLakcimIranyitoszamRaw) . "'";
        }
        if ($lakcimVarosOszlopVan) {
            $sql .= ", lakcim_varos = '" . $conn->real_escape_string($felhasznaloLakcimVarosRaw) . "'";
        }
        if ($lakcimUtcaOszlopVan) {
            $sql .= ", lakcim_utca = '" . $conn->real_escape_string($felhasznaloLakcimUtcaRaw) . "'";
        }
        if ($lakcimHazszamOszlopVan) {
            $sql .= ", lakcim_hazszam = '" . $conn->real_escape_string($felhasznaloLakcimHazszamRaw) . "'";
        }
        if ($cegnevOszlopVan) {
            $sql .= ", cegnev = '$felhasznaloCegnev'";
        }
        if ($cegesSzekhelyOszlopVan) {
            $sql .= ", ceges_szekhely = '$felhasznaloCegesSzekhely'";
        }
        if ($cegesIranyitoszamOszlopVan) {
            $sql .= ", ceges_iranyitoszam = '" . $conn->real_escape_string($felhasznaloCegesIranyitoszamRaw) . "'";
        }
        if ($cegesVarosOszlopVan) {
            $sql .= ", ceges_varos = '" . $conn->real_escape_string($felhasznaloCegesVarosRaw) . "'";
        }
        if ($cegesUtcaOszlopVan) {
            $sql .= ", ceges_utca = '" . $conn->real_escape_string($felhasznaloCegesUtcaRaw) . "'";
        }
        if ($cegesHazszamOszlopVan) {
            $sql .= ", ceges_hazszam = '" . $conn->real_escape_string($felhasznaloCegesHazszamRaw) . "'";
        }
        if ($adoszamOszlopVan) {
            $sql .= ", adoszam = '$felhasznaloAdoszam'";
        }
        if ($szallitasiNevOszlopVan) {
            $sql .= ", szallitasi_nev = '$felhasznaloSzallitasiNev'";
        }
        if ($szallitasiIranyitoszamOszlopVan) {
            $sql .= ", szallitasi_iranyitoszam = '$felhasznaloSzallitasiIranyitoszam'";
        }
        if ($szallitasiVarosOszlopVan) {
            $sql .= ", szallitasi_varos = '$felhasznaloSzallitasiVaros'";
        }
        if ($szallitasiUtcaOszlopVan) {
            $sql .= ", szallitasi_utca = '$felhasznaloSzallitasiUtca'";
        }
        if ($szallitasiHazszamOszlopVan) {
            $sql .= ", szallitasi_hazszam = '$felhasznaloSzallitasiHazszam'";
        }
        if ($szallitasiEmeletAjtoOszlopVan) {
            $sql .= ", szallitasi_emelet_ajto = '$felhasznaloSzallitasiEmeletAjto'";
        }
        if ($szallitasiMegjegyzesOszlopVan) {
            $sql .= ", szallitasi_megjegyzes = '$felhasznaloSzallitasiMegjegyzes'";
        }
        if ($cegkentVasarolokOszlopVan) {
            $sql .= ", cegkent_vasarolok = $felhasznaloCegkentVasarolok";
        }
        if ($kiemeltFelhasznaloOszlop) {
            $sql .= ", kiemelt_felhasznalo = $felhasznaloKiemelt";
        }
        $sql .= " WHERE id = $felhasznaloId";
        $conn->query($sql);
    }

    header('Location: admin.php?panel=felhasznalok');
    exit;
}

if ($isAdmin && isset($_POST['kategoria_kep_mentes'])) {
    $kategoriaKod = trim((string)($_POST['kategoria_kod'] ?? ''));
    $kategoriaId = intval($_POST['kategoria_id'] ?? 0);
    if ($kategoriaId > 0 && $kategoriaSorrendOszlopVan && isset($_POST['kategoria_sorrend'])) {
        $sorrend = max(1, intval($_POST['kategoria_sorrend'] ?? 1));
        $stmt = $conn->prepare("UPDATE kategoriak SET sorrend = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $sorrend, $kategoriaId);
            $stmt->execute();
            $stmt->close();
        }
    }
    if ($kategoriaKod === 'most' && isset($_FILES['kategoria_kep']) && $_FILES['kategoria_kep']['error'] === 0) {
        setMostKategoriaKep(mentsKepAdminEredetiArannyal('kategoria_kep'));
    } elseif ($kategoriaKod === 'kozelgo' && isset($_FILES['kategoria_kep']) && $_FILES['kategoria_kep']['error'] === 0) {
        setKozelgoKategoriaKep(mentsKepAdminEredetiArannyal('kategoria_kep'));
    } elseif ($kategoriaKod === 'elo' && isset($_FILES['kategoria_kep']) && $_FILES['kategoria_kep']['error'] === 0) {
        setEloKategoriaKep(mentsKepAdminEredetiArannyal('kategoria_kep'));
    } elseif ($kategoriaId > 0 && isset($_FILES['kategoria_kep']) && $_FILES['kategoria_kep']['error'] === 0) {
        $kepNev = mentsKepAdminEredetiArannyal('kategoria_kep');
        $stmt = $conn->prepare("UPDATE kategoriak SET kep_url = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('si', $kepNev, $kategoriaId);
            $stmt->execute();
            $stmt->close();
        }
    }
    header('Location: admin.php?panel=kategoriak');
    exit;
}

if ($isAdmin && isset($_POST['kategoria_mentes'])) {
    $kategoriaId = intval($_POST['kategoria_id'] ?? 0);
    $ujNevRaw = trim((string)($_POST['kategoria_nev'] ?? ''));
    $kategoriaForditasok = adminKategoriaForditasokFromPost($_POST['kategoria_forditas'] ?? [], $forditasLocaleKodok);

    if ($kategoriaId > 0) {
        if ($ujNevRaw !== '') {
            $stmt = $conn->prepare("UPDATE kategoriak SET nev = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $ujNevRaw, $kategoriaId);
                $stmt->execute();
                $stmt->close();
            }
        }

        if ($kategoriaSorrendOszlopVan && isset($_POST['kategoria_sorrend'])) {
            $sorrend = max(1, intval($_POST['kategoria_sorrend'] ?? 1));
            $stmt = $conn->prepare("UPDATE kategoriak SET sorrend = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $sorrend, $kategoriaId);
                $stmt->execute();
                $stmt->close();
            }
        }

        if (isset($_FILES['kategoria_kep']) && $_FILES['kategoria_kep']['error'] === 0) {
            $kepNev = mentsKepAdminEredetiArannyal('kategoria_kep');
            $stmt = $conn->prepare("UPDATE kategoriak SET kep_url = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $kepNev, $kategoriaId);
                $stmt->execute();
                $stmt->close();
            }
        }

        if ($ujNevRaw !== '' && function_exists('shobidI18nSaveCategoryTranslations')) {
            shobidI18nSaveCategoryTranslations($kategoriaId, $kategoriaForditasok, $ujNevRaw);
        }
    }

    header('Location: admin.php?panel=kategoriak');
    exit;
}

if ($isAdmin && isset($_POST['uj_kategoria_mentes'])) {
    $ujKategoriaRaw = trim($_POST['uj_kategoria_nev'] ?? '');
    $ujKategoriaForditasok = adminKategoriaForditasokFromPost($_POST['uj_kategoria_forditas'] ?? [], $forditasLocaleKodok);
    $specialTiltottKategoriak = ['Kozelgo', 'kozelgo', 'Osszes', 'osszes', 'Elo', 'elo'];
    if ($ujKategoriaRaw !== '' && !in_array($ujKategoriaRaw, $specialTiltottKategoriak, true)) {
        $ujKategoriaId = 0;
        if ($kategoriaSorrendOszlopVan) {
            $maxSorrendRes = $conn->query("SELECT MAX(COALESCE(sorrend, 0)) AS max_sorrend FROM kategoriak");
            $maxSorrend = $maxSorrendRes ? intval(($maxSorrendRes->fetch_assoc()['max_sorrend'] ?? 0)) : 0;
            $ujSorrend = $maxSorrend + 1;
            $stmt = $conn->prepare("INSERT INTO kategoriak (nev, sorrend) VALUES (?, ?)");
            if ($stmt) {
                $stmt->bind_param('si', $ujKategoriaRaw, $ujSorrend);
                $stmt->execute();
                $ujKategoriaId = intval($stmt->insert_id ?? 0);
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO kategoriak (nev) VALUES (?)");
            if ($stmt) {
                $stmt->bind_param('s', $ujKategoriaRaw);
                $stmt->execute();
                $ujKategoriaId = intval($stmt->insert_id ?? 0);
                $stmt->close();
            }
        }

        if ($ujKategoriaId > 0 && function_exists('shobidI18nSaveCategoryTranslations')) {
            shobidI18nSaveCategoryTranslations($ujKategoriaId, $ujKategoriaForditasok, $ujKategoriaRaw);
        }
    }
    header('Location: admin.php?panel=kategoriak');
    exit;
}

if ($isAdmin && isset($_POST['kategoria_atnevezes'])) {
    $kategoriaId = intval($_POST['kategoria_id'] ?? 0);
    $ujNevRaw = trim((string)($_POST['kategoria_nev'] ?? ''));
    $kategoriaForditasok = adminKategoriaForditasokFromPost($_POST['kategoria_forditas'] ?? [], $forditasLocaleKodok);
    if ($kategoriaId > 0 && $ujNevRaw !== '') {
        $stmt = $conn->prepare("UPDATE kategoriak SET nev = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('si', $ujNevRaw, $kategoriaId);
            $stmt->execute();
            $stmt->close();
        }
        if (function_exists('shobidI18nSaveCategoryTranslations')) {
            shobidI18nSaveCategoryTranslations($kategoriaId, $kategoriaForditasok, $ujNevRaw);
        }
    }
    header('Location: admin.php?panel=kategoriak');
    exit;
}

if ($isAdmin && isset($_POST['kategoria_torles'])) {
    $kategoriaTorlesId = intval($_POST['kategoria_torles']);
    if ($kategoriaTorlesId > 0) {
        $stmt = $conn->prepare("DELETE FROM kategoriak WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $kategoriaTorlesId);
            $stmt->execute();
            $stmt->close();
        }
        if (function_exists('shobidI18nDeleteCategoryTranslations')) {
            shobidI18nDeleteCategoryTranslations($kategoriaTorlesId);
        }
    }
    header('Location: admin.php?panel=kategoriak');
    exit;
}

if ($isAdmin && isset($_POST['kategoria_sorrend_mentes']) && $kategoriaSorrendOszlopVan) {
    $kategoriaId = intval($_POST['kategoria_id'] ?? 0);
    $sorrend = max(1, intval($_POST['kategoria_sorrend'] ?? 1));
    if ($kategoriaId > 0) {
        $stmt = $conn->prepare("UPDATE kategoriak SET sorrend = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $sorrend, $kategoriaId);
            $stmt->execute();
            $stmt->close();
        }
    }
    header('Location: admin.php?panel=kategoriak');
    exit;
}

if ($isAdmin && isset($_POST['uj_kupon_kategoria_mentes'])) {
    $ujNev = trim((string)($_POST['uj_kupon_kategoria_nev'] ?? ''));
    $ujKuponKategoriaForditasok = adminKategoriaForditasokFromPost($_POST['uj_kupon_kategoria_forditas'] ?? [], $forditasLocaleKodok);
    if ($ujNev !== '' && tablaLetezik($conn, 'kupon_kategoriak')) {
        $ujKuponKategoriaId = 0;
        $maxSorrend = 0;
        if (oszlopLetezik($conn, 'kupon_kategoriak', 'sorrend')) {
            $maxRes = $conn->query("SELECT MAX(COALESCE(sorrend, 0)) AS max_sorrend FROM kupon_kategoriak");
            $maxSorrend = $maxRes ? intval(($maxRes->fetch_assoc()['max_sorrend'] ?? 0)) : 0;
        }
        $ujSorrend = $maxSorrend + 1;
        $kepNev = '';
        if (isset($_FILES['uj_kupon_kategoria_kep']) && $_FILES['uj_kupon_kategoria_kep']['error'] === 0) {
            $kepNev = mentsKepAdminEredetiArannyal('uj_kupon_kategoria_kep');
        }
        if (oszlopLetezik($conn, 'kupon_kategoriak', 'sorrend') && oszlopLetezik($conn, 'kupon_kategoriak', 'kep_url')) {
            $stmt = $conn->prepare("INSERT INTO kupon_kategoriak (nev, sorrend, kep_url) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('sis', $ujNev, $ujSorrend, $kepNev);
                $stmt->execute();
                $ujKuponKategoriaId = intval($stmt->insert_id ?? 0);
                $stmt->close();
            }
        } elseif (oszlopLetezik($conn, 'kupon_kategoriak', 'sorrend')) {
            $stmt = $conn->prepare("INSERT INTO kupon_kategoriak (nev, sorrend) VALUES (?, ?)");
            if ($stmt) {
                $stmt->bind_param('si', $ujNev, $ujSorrend);
                $stmt->execute();
                $ujKuponKategoriaId = intval($stmt->insert_id ?? 0);
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO kupon_kategoriak (nev) VALUES (?)");
            if ($stmt) {
                $stmt->bind_param('s', $ujNev);
                $stmt->execute();
                $ujKuponKategoriaId = intval($stmt->insert_id ?? 0);
                $stmt->close();
            }
        }
        if ($ujKuponKategoriaId > 0 && function_exists('shobidI18nSaveCouponCategoryTranslations')) {
            shobidI18nSaveCouponCategoryTranslations($ujKuponKategoriaId, $ujKuponKategoriaForditasok, $ujNev);
        }
    }
    header('Location: admin.php?panel=kupon_kategoriak');
    exit;
}

if ($isAdmin && isset($_POST['kupon_kategoria_mentes'])) {
    $id = intval($_POST['kupon_kategoria_id'] ?? 0);
    $nev = trim((string)($_POST['kupon_kategoria_nev'] ?? ''));
    $kuponKategoriaForditasok = adminKategoriaForditasokFromPost($_POST['kupon_kategoria_forditas'] ?? [], $forditasLocaleKodok);
    if ($id > 0 && tablaLetezik($conn, 'kupon_kategoriak')) {
        if ($nev !== '') {
            $stmt = $conn->prepare("UPDATE kupon_kategoriak SET nev = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $nev, $id);
                $stmt->execute();
                $stmt->close();
            }
        }
        if (oszlopLetezik($conn, 'kupon_kategoriak', 'sorrend') && isset($_POST['kupon_kategoria_sorrend'])) {
            $sorrend = max(1, intval($_POST['kupon_kategoria_sorrend'] ?? 1));
            $stmt = $conn->prepare("UPDATE kupon_kategoriak SET sorrend = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $sorrend, $id);
                $stmt->execute();
                $stmt->close();
            }
        }
        if (oszlopLetezik($conn, 'kupon_kategoriak', 'kep_url') && isset($_FILES['kupon_kategoria_kep']) && $_FILES['kupon_kategoria_kep']['error'] === 0) {
            $kepNev = mentsKepAdminEredetiArannyal('kupon_kategoria_kep');
            $stmt = $conn->prepare("UPDATE kupon_kategoriak SET kep_url = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $kepNev, $id);
                $stmt->execute();
                $stmt->close();
            }
        }
        if ($nev !== '' && function_exists('shobidI18nSaveCouponCategoryTranslations')) {
            shobidI18nSaveCouponCategoryTranslations($id, $kuponKategoriaForditasok, $nev);
        }
    }
    header('Location: admin.php?panel=kupon_kategoriak');
    exit;
}

if ($isAdmin && isset($_POST['kupon_kategoria_torles'])) {
    $id = intval($_POST['kupon_kategoria_torles'] ?? 0);
    if ($id > 0 && tablaLetezik($conn, 'kupon_kategoriak')) {
        $stmt = $conn->prepare("DELETE FROM kupon_kategoriak WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
        if (function_exists('shobidI18nDeleteCouponCategoryTranslations')) {
            shobidI18nDeleteCouponCategoryTranslations($id);
        }
    }
    header('Location: admin.php?panel=kupon_kategoriak');
    exit;
}

if ($isAdmin && isset($_POST['kupon_osszes_kep_mentes'])) {
    $kuponOsszesForditasok = adminKategoriaForditasokFromPost($_POST['kupon_osszes_forditas'] ?? [], $forditasLocaleKodok);
    if (isset($_FILES['kupon_osszes_kep']) && $_FILES['kupon_osszes_kep']['error'] === 0) {
        setKuponOsszesKategoriaKep(mentsKepAdminEredetiArannyal('kupon_osszes_kep'));
    }
    if (function_exists('shobidI18nSaveLocaleMessages')) {
        $osszesKulcs = 'feed.coupon.subcategories.all';
        $defaultLocale = function_exists('shobidI18nDefaultLocale') ? shobidI18nDefaultLocale() : 'hu-hu';
        foreach ($forditasLocaleKodok as $localeKod) {
            $messages = shobidI18nLoadLocaleMessages($localeKod);
            $ertek = trim((string)($kuponOsszesForditasok[$localeKod] ?? ''));
            if ($ertek === '' && (string)$localeKod === (string)$defaultLocale) {
                $ertek = 'Osszes';
            }
            $messages[$osszesKulcs] = $ertek;
            shobidI18nSaveLocaleMessages($localeKod, $messages);
        }
    }
    header('Location: admin.php?panel=kupon_kategoriak');
    exit;
}

if ($isAdmin && isset($_POST['beallitasok_mentes'])) {
    $ujVatByCountry = [];
    $ujCardFeeByCountry = [];
    $vatInput = $_POST['vat_by_country_percent'] ?? [];
    $cardFeeInput = $_POST['card_fee_percent_by_country'] ?? [];
    if (is_array($vatInput)) {
        foreach ($vatInput as $countryCode => $vatPercent) {
            $normalizedCode = function_exists('shobidPayoutNormalizeLocale')
                ? shobidPayoutNormalizeLocale((string)$countryCode, (string)$countryCode)
                : strtolower(trim((string)$countryCode));
            if ($normalizedCode === '') {
                continue;
            }
            $ujVatByCountry[$normalizedCode] = max(0.0, min(99.0, floatval($vatPercent)));
        }
    }
    if (is_array($cardFeeInput)) {
        foreach ($cardFeeInput as $countryCode => $cardPercent) {
            $normalizedCode = function_exists('shobidPayoutNormalizeLocale')
                ? shobidPayoutNormalizeLocale((string)$countryCode, (string)$countryCode)
                : strtolower(trim((string)$countryCode));
            if ($normalizedCode === '') {
                continue;
            }
            $ujCardFeeByCountry[$normalizedCode] = max(0.0, min(99.0, floatval($cardPercent)));
        }
    }
    if (!$ujVatByCountry && function_exists('shobidPayoutDefaultVatPercentByLocale')) {
        $ujVatByCountry = shobidPayoutDefaultVatPercentByLocale();
    }
    if (!$ujCardFeeByCountry && function_exists('shobidPayoutDefaultCardFeePercentByLocale')) {
        $ujCardFeeByCountry = shobidPayoutDefaultCardFeePercentByLocale();
    }

    $ujBeallitasok = [
        'licit_max_ora' => max(1, intval($_POST['licit_max_ora'] ?? 24)),
        'fix_max_ora' => max(1, intval($_POST['fix_max_ora'] ?? 24)),
        'vat_by_country_percent' => $ujVatByCountry,
        'card_fee_percent_by_country' => $ujCardFeeByCountry
    ];
    setAjanlatBeallitasok($ujBeallitasok);
    if (function_exists('shobidI18nSetDefaultLocale')) {
        shobidI18nSetDefaultLocale(trim((string)($_POST['i18n_default_locale'] ?? '')));
    }
    header('Location: admin.php?panel=beallitasok');
    exit;
}

if ($isAdmin && isset($_POST['mentes'])) {
    $sid = intval($_POST['szerkeszt_id'] ?? 0);
    $nev = $conn->real_escape_string(trim($_POST['nev'] ?? ''));
    $leirasRaw = trim($_POST['leiras'] ?? '');
    $leiras = $conn->real_escape_string($leirasRaw);
    $videoRaw = trim($_POST['video_url'] ?? '');
    $liveUrlRaw = trim($_POST['live_url'] ?? '');
    $video = $conn->real_escape_string($videoRaw);
    $liveUrl = $conn->real_escape_string($liveUrlRaw);
    $ar = intval($_POST['ar'] ?? 0);
    $lepcso = intval($_POST['lepcso'] ?? 0);
    $szallitasi_mod = $_POST['szallitasi_mod'] ?? '';
    $szallitasi_dij = intval($_POST['szallitasi_dij'] ?? 0);
    $kat = intval($_POST['kategoria'] ?? 0);
    $kezdet = $_POST['kezdet'] ?? '';
    $lejarat = $_POST['lejarat'] ?? '';
    $eladva = isset($_POST['eladva']) ? 1 : 0;

    if ($leirasRaw === '') die(adminSt('admin.validation.description_required', 'A leírás megadása kötelező.'));
    if ($videoRaw === '') die(adminSt('admin.validation.video_required', 'A videó link megadása kötelező.'));
    if ($kat < 1) die(adminSt('admin.validation.category_required', 'Válassz kategóriát.'));
    if ($szallitasi_mod !== 'ingyenes' && $szallitasi_mod !== 'egyedi') die(adminSt('admin.validation.shipping_mode_required', 'A szállítási mód kiválasztása kötelező.'));
    if ($szallitasi_mod === 'egyedi' && $szallitasi_dij < 1) die(adminSt('admin.validation.shipping_fee_required', 'Add meg a szállítási díjat.'));
    if (empty($kezdet) || empty($lejarat)) die(adminSt('admin.validation.start_end_required', 'Adj meg kezdési és lejárati időpontot.'));
    if (strtotime($lejarat) <= strtotime($kezdet)) die(adminSt('admin.validation.end_after_start', 'A lejárat későbbi kell legyen, mint a kezdés.'));
    if ((strtotime($lejarat) - strtotime($kezdet)) > ($licitMaxOra * 3600)) die(adminSt('admin.validation.max_hours_dynamic_prefix', 'A maximum idő ') . intval($licitMaxOra) . adminSt('admin.validation.max_hours_dynamic_suffix', ' óra lehet.'));

    $kepFrissites = '';
    if (isset($_FILES['kep']) && $_FILES['kep']['error'] === 0) {
        $kep_nev = mentsKepAdmin('kep');
        $kepFrissites = ", kep_url='" . $conn->real_escape_string($kep_nev) . "'";
    }

    $licitIdotartamMp = max(1, strtotime($lejarat) - strtotime($kezdet));
    $licitExtraSql = $licitIdotartamOszlopVan ? ", licit_idotartam_mp = $licitIdotartamMp" : "";
    if ($licitStartedOszlopVan) {
        $licitExtraSql .= ", licit_started_at = NULL";
    }

    if ($sid > 0) {
        $liveSql = $liveUrlOszlopVan ? ", live_url='$liveUrl'" : "";
        if ($liveAktivOszlopVan) {
            $liveSql .= ", live_active=0";
        }
        $conn->query("UPDATE termekek SET nev='$nev', leiras='$leiras', aktualis_ar=$ar, licit_lepcso=$lepcso, szallitasi_mod='$szallitasi_mod', szallitasi_dij=" . ($szallitasi_mod === 'ingyenes' ? '0' : $szallitasi_dij) . ", kategoria_id=$kat, kezdes_idopont='$kezdet', lejarat_idopont='$lejarat', video_url='$video'$liveSql, eladva=$eladva, ajanlat_tipus='licit', fix_ar=0 $licitExtraSql $kepFrissites WHERE id = $sid");
    } else {
        $kep_nev = mentsKepAdmin('kep');
        $licitExtraOszlopSql = $licitIdotartamOszlopVan ? ", licit_idotartam_mp" : "";
        $licitExtraErtekSql = $licitIdotartamOszlopVan ? ", $licitIdotartamMp" : "";
        if ($licitStartedOszlopVan) {
            $licitExtraOszlopSql .= ", licit_started_at";
            $licitExtraErtekSql .= ", NULL";
        }
        $liveExtraOszlopSql = $liveUrlOszlopVan ? ", live_url" : "";
        $liveExtraErtekSql = $liveUrlOszlopVan ? ", '$liveUrl'" : "";
        if ($liveAktivOszlopVan) {
            $liveExtraOszlopSql .= ", live_active";
            $liveExtraErtekSql .= ", 0";
        }
        $conn->query("INSERT INTO termekek (nev, leiras, aktualis_ar, licit_lepcso, szallitasi_mod, szallitasi_dij, kategoria_id, kezdes_idopont, lejarat_idopont, kep_url, video_url, feltolto_id, eladva, ajanlat_tipus, fix_ar, orszag_kod, penznem$licitExtraOszlopSql$liveExtraOszlopSql) VALUES ('$nev', '$leiras', $ar, $lepcso, '$szallitasi_mod', " . ($szallitasi_mod === 'ingyenes' ? '0' : $szallitasi_dij) . ", $kat, '$kezdet', '$lejarat', '$kep_nev', '$video', 0, 0, 'licit', 0, " . shobidMarketSqlValue($conn, $adminPiacKod) . ", '" . $conn->real_escape_string($adminPenznemKod) . "'$licitExtraErtekSql$liveExtraErtekSql)");
    }

    header('Location: admin.php');
    exit;
}

if ($isAdmin && isset($_POST['mentes_multi']) && $multiTablaVan) {
    $sid = intval($_POST['multi_szerkeszt_id'] ?? 0);
    $eladva = isset($_POST['multi_eladva']) ? 1 : 0;
    $foNevRaw = trim($_POST['multi_fo_nev'] ?? '');
    $foNev = $conn->real_escape_string($foNevRaw);
    $videoRaw = trim($_POST['multi_video_url'] ?? '');
    $liveUrlRaw = trim($_POST['multi_live_url'] ?? '');
    $video = $conn->real_escape_string($videoRaw);
    $liveUrl = $conn->real_escape_string($liveUrlRaw);
    $kezdet = $_POST['multi_kezdet'] ?? '';
    $multiKat = intval($_POST['multi_kategoria'] ?? 0);
    $gapMp = 15;

    if ($foNevRaw === '') die(adminSt('admin.validation.multi_main_name_required', 'A főnév megadása kötelező.'));
    if ($videoRaw === '') die(adminSt('admin.validation.video_required', 'A videó link megadása kötelező.'));
    if ($kezdet === '') die(adminSt('admin.validation.start_required', 'A kezdési időpont megadása kötelező.'));
    if ($multiKat < 1) die(adminSt('admin.validation.multi_category_required', 'Válassz kategóriát a licit shophez.'));

    $items = [];
    for ($i = 0; $i < 25; $i++) {
        $row = $_POST['multi_items'][$i] ?? [];
        $nevRaw = trim($row['nev'] ?? '');
        if ($nevRaw === '') {
            continue;
        }

        $leirasRaw = trim($row['leiras'] ?? '');
        $szallitasMod = $row['szallitasi_mod'] ?? '';
        $szallitasDij = intval($row['szallitasi_dij'] ?? 0);
        $ar = intval($row['ar'] ?? 0);
        $lepcso = intval($row['lepcso'] ?? 0);
        $idotartam = intval($row['idotartam_mp'] ?? 0);
        $pozicio = intval($row['pozicio'] ?? ($i + 1));

        if ($leirasRaw === '') die(adminSt('admin.validation.multi_item_description_required', 'Minden multi tételnél meg kell adni a leírást.'));
        if ($szallitasMod !== 'ingyenes' && $szallitasMod !== 'egyedi') die(adminSt('admin.validation.multi_item_shipping_required', 'Minden multi tételnél válassz szállítást.'));
        if ($szallitasMod === 'egyedi' && $szallitasDij < 1) die(adminSt('admin.validation.multi_item_shipping_fee_required', 'Az egyedi szállítási díjat minden tételnél add meg.'));
        if ($ar < 1 || $lepcso < 1) die(adminSt('admin.validation.multi_item_price_step_nonzero', 'Az induló licit és a licitlépcső nem lehet 0.'));
        if (!isset($durationOptions[$idotartam])) die(adminSt('admin.validation.multi_item_duration_invalid', 'Válassz érvényes időtartamot.'));

        $items[] = [
            'nev' => $conn->real_escape_string($nevRaw),
            'leiras' => $conn->real_escape_string($leirasRaw),
            'kategoria' => $multiKat,
            'szallitas_mod' => $szallitasMod,
            'szallitas_dij' => $szallitasMod === 'ingyenes' ? 0 : $szallitasDij,
            'ar' => $ar,
            'lepcso' => $lepcso,
            'idotartam_mp' => $idotartam,
            'pozicio' => $pozicio
        ];
    }

    if (!$items) die(adminSt('admin.validation.multi_item_min_one', 'Adj meg legalább egy multi tételt.'));

    usort($items, function ($a, $b) {
        return $a['pozicio'] <=> $b['pozicio'];
    });

    $foKep = $sid > 0 ? null : mentsKepAdmin('multi_kep');
    if ($sid > 0 && isset($_FILES['multi_kep']) && $_FILES['multi_kep']['error'] === 0) {
        $foKep = mentsKepAdmin('multi_kep');
    }

    $elsoTetel = $items[0];
    $kezdesTs = strtotime($kezdet);
    $vegTs = $kezdesTs;
    foreach ($items as $idx => $item) {
        $vegTs += intval($item['idotartam_mp']);
        if ($idx < count($items) - 1) {
            $vegTs += $gapMp;
        }
    }
    $lejarat = date('Y-m-d H:i:s', $vegTs);

    if ($sid > 0) {
        $kepSql = $foKep ? ", kep_url='" . $conn->real_escape_string($foKep) . "'" : '';
        $liveSql = $liveUrlOszlopVan ? ", live_url='$liveUrl'" : "";
        if ($liveAktivOszlopVan) {
            $liveSql .= ", live_active=0";
        }
        $conn->query("UPDATE termekek SET nev='$foNev', leiras='', aktualis_ar=" . intval($elsoTetel['ar']) . ", licit_lepcso=" . intval($elsoTetel['lepcso']) . ", szallitasi_mod='{$elsoTetel['szallitas_mod']}', szallitasi_dij=" . intval($elsoTetel['szallitas_dij']) . ", kategoria_id=$multiKat, kezdes_idopont='" . $conn->real_escape_string($kezdet) . "', lejarat_idopont='$lejarat', video_url='$video'$liveSql, eladva=$eladva, ajanlat_tipus='multi', fix_ar=0 $kepSql WHERE id = $sid");

        $multiRes = $conn->query("SELECT id FROM multi_aukciok WHERE termek_id = $sid AND EXISTS (SELECT 1 FROM multi_aukcio_tetelek WHERE multi_aukcio_id = multi_aukciok.id) LIMIT 1");
        $multiRow = $multiRes ? $multiRes->fetch_assoc() : null;
        $multiId = intval($multiRow['id'] ?? 0);
        if ($multiId < 1) {
            $multiExtraOszlopSql = '';
            $multiExtraErtekSql = '';
            if ($multiAktivTetelIndexOszlopVan) {
                $multiExtraOszlopSql .= ', aktiv_tetel_index';
                $multiExtraErtekSql .= ', 0';
            }
            if ($multiAktivTetelStartOszlopVan) {
                $multiExtraOszlopSql .= ', aktiv_tetel_start';
                $multiExtraErtekSql .= ', NULL';
            }
            $conn->query("INSERT INTO multi_aukciok (termek_id, szunet_mp$multiExtraOszlopSql) VALUES ($sid, $gapMp$multiExtraErtekSql)");
            $multiId = intval($conn->insert_id);
        } else {
            $multiResetSql = '';
            if ($multiAktivTetelIndexOszlopVan) {
                $multiResetSql .= ", aktiv_tetel_index = 0";
            }
            if ($multiAktivTetelStartOszlopVan) {
                $multiResetSql .= ", aktiv_tetel_start = NULL";
            }
            $conn->query("UPDATE multi_aukciok SET szunet_mp = $gapMp$multiResetSql WHERE id = $multiId");
            $conn->query("DELETE FROM multi_aukcio_tetelek WHERE multi_aukcio_id = $multiId");
        }

        foreach ($items as $index => $item) {
            $sorszam = $index + 1;
            $conn->query("INSERT INTO multi_aukcio_tetelek (multi_aukcio_id, sorszam, nev, leiras, kategoria_id, szallitasi_mod, szallitasi_dij, aktualis_ar, licit_lepcso, idotartam_mp, legmagasabb_licit_felhasznalo, eladva) VALUES ($multiId, $sorszam, '{$item['nev']}', '{$item['leiras']}', {$item['kategoria']}, '{$item['szallitas_mod']}', {$item['szallitas_dij']}, {$item['ar']}, {$item['lepcso']}, {$item['idotartam_mp']}, '', 0)");
        }
    } else {
        $liveExtraOszlopSql = $liveUrlOszlopVan ? ", live_url" : "";
        $liveExtraErtekSql = $liveUrlOszlopVan ? ", '$liveUrl'" : "";
        if ($liveAktivOszlopVan) {
            $liveExtraOszlopSql .= ", live_active";
            $liveExtraErtekSql .= ", 0";
        }
        $conn->query("INSERT INTO termekek (nev, leiras, aktualis_ar, licit_lepcso, szallitasi_mod, szallitasi_dij, kategoria_id, kezdes_idopont, lejarat_idopont, kep_url, video_url, feltolto_id, eladva, ajanlat_tipus, fix_ar, orszag_kod, penznem$liveExtraOszlopSql) VALUES ('$foNev', '', " . intval($elsoTetel['ar']) . ", " . intval($elsoTetel['lepcso']) . ", '{$elsoTetel['szallitas_mod']}', " . intval($elsoTetel['szallitas_dij']) . ", $multiKat, '" . $conn->real_escape_string($kezdet) . "', '$lejarat', '$foKep', '$video', 0, 0, 'multi', 0, " . shobidMarketSqlValue($conn, $adminPiacKod) . ", '" . $conn->real_escape_string($adminPenznemKod) . "'$liveExtraErtekSql)");
        $termekId = intval($conn->insert_id);
        $multiExtraOszlopSql = '';
        $multiExtraErtekSql = '';
        if ($multiAktivTetelIndexOszlopVan) {
            $multiExtraOszlopSql .= ', aktiv_tetel_index';
            $multiExtraErtekSql .= ', 0';
        }
        if ($multiAktivTetelStartOszlopVan) {
            $multiExtraOszlopSql .= ', aktiv_tetel_start';
            $multiExtraErtekSql .= ', NULL';
        }
        $conn->query("INSERT INTO multi_aukciok (termek_id, szunet_mp$multiExtraOszlopSql) VALUES ($termekId, $gapMp$multiExtraErtekSql)");
        $multiId = intval($conn->insert_id);

        foreach ($items as $index => $item) {
            $sorszam = $index + 1;
            $conn->query("INSERT INTO multi_aukcio_tetelek (multi_aukcio_id, sorszam, nev, leiras, kategoria_id, szallitasi_mod, szallitasi_dij, aktualis_ar, licit_lepcso, idotartam_mp, legmagasabb_licit_felhasznalo, eladva) VALUES ($multiId, $sorszam, '{$item['nev']}', '{$item['leiras']}', {$item['kategoria']}, '{$item['szallitas_mod']}', {$item['szallitas_dij']}, {$item['ar']}, {$item['lepcso']}, {$item['idotartam_mp']}, '', 0)");
        }
    }

    header('Location: admin.php');
    exit;
}

if ($isAdmin && isset($_POST['mentes_fix'])) {
    $sid = intval($_POST['fix_szerkeszt_id'] ?? 0);
    $nev = $conn->real_escape_string(trim($_POST['fix_nev'] ?? ''));
    $leirasRaw = trim($_POST['fix_leiras'] ?? '');
    $leiras = $conn->real_escape_string($leirasRaw);
    $videoRaw = trim($_POST['fix_video_url'] ?? '');
    $liveUrlRaw = trim($_POST['fix_live_url'] ?? '');
    $video = $conn->real_escape_string($videoRaw);
    $liveUrl = $conn->real_escape_string($liveUrlRaw);
    $fixAr = intval($_POST['fix_ar'] ?? 0);
    $eredetiAr = intval($_POST['fix_eredeti_ar'] ?? 0);
    $varos = $conn->real_escape_string(trim($_POST['fix_varos'] ?? ''));
    $darabszam = intval($_POST['fix_darabszam'] ?? 0);
    $szallitasi_mod = $_POST['fix_szallitasi_mod'] ?? '';
    $szallitasi_dij = intval($_POST['fix_szallitasi_dij'] ?? 0);
    $kat = intval($_POST['fix_kategoria'] ?? 0);
    $kezdet = $_POST['fix_kezdet'] ?? '';
    $lejarat = $_POST['fix_lejarat'] ?? '';
    $eladva = isset($_POST['fix_eladva']) ? 1 : 0;

    if ($nev === '') die(adminSt('admin.validation.name_required', 'A név megadása kötelező.'));
    if ($leirasRaw === '') die(adminSt('admin.validation.description_required', 'A leírás megadása kötelező.'));
    if ($videoRaw === '') die(adminSt('admin.validation.video_required', 'A videó link megadása kötelező.'));
    if ($fixAr < 1) die(adminSt('admin.validation.fix_price_required', 'A fix ár megadása kötelező.'));
    if ($darabszam < 1) die(adminSt('admin.validation.quantity_required', 'A darabszám megadása kötelező.'));
    if ($kat < 1) die(adminSt('admin.validation.category_required', 'Válassz kategóriát.'));
    if ($kezdet === '') die(adminSt('admin.validation.start_required_plain', 'Add meg a kezdési időpontot.'));
    if ($lejarat === '') die(adminSt('admin.validation.end_required', 'Add meg a lejárati időpontot.'));
    if (strtotime($lejarat) <= strtotime($kezdet)) die(adminSt('admin.validation.end_after_start', 'A lejárat későbbi kell legyen, mint a kezdés.'));
    if ((strtotime($lejarat) - strtotime($kezdet)) > ($fixMaxOra * 3600)) die(adminSt('admin.validation.max_hours_dynamic_prefix', 'A maximum idő ') . intval($fixMaxOra) . adminSt('admin.validation.max_hours_dynamic_suffix', ' óra lehet.'));
    if ($szallitasi_mod !== 'ingyenes' && $szallitasi_mod !== 'egyedi') die(adminSt('admin.validation.shipping_mode_required', 'A szállítási mód kiválasztása kötelező.'));
    if ($szallitasi_mod === 'egyedi' && $szallitasi_dij < 1) die(adminSt('admin.validation.shipping_fee_required', 'Add meg a szállítási díjat.'));

    $kepFrissites = '';
    if (isset($_FILES['fix_kep']) && $_FILES['fix_kep']['error'] === 0) {
        $kepNev = mentsKepAdmin('fix_kep');
        $kepFrissites = ", kep_url='" . $conn->real_escape_string($kepNev) . "'";
    }

    if ($sid > 0) {
        $eredetiArSql = $eredetiArOszlopVan ? ", eredeti_ar=" . max(0, $eredetiAr) : "";
        $varosSql = $varosOszlopVan ? ", varos='$varos'" : "";
        $liveSql = $liveUrlOszlopVan ? ", live_url='$liveUrl'" : "";
        if ($liveAktivOszlopVan) {
            $liveSql .= ", live_active=0";
        }
        $conn->query("UPDATE termekek SET nev='$nev', leiras='$leiras', aktualis_ar=$fixAr, licit_lepcso=0, szallitasi_mod='$szallitasi_mod', szallitasi_dij=" . ($szallitasi_mod === 'ingyenes' ? '0' : $szallitasi_dij) . ", kategoria_id=$kat, kezdes_idopont='$kezdet', lejarat_idopont='$lejarat', video_url='$video'$liveSql, eladva=$eladva, ajanlat_tipus='fix', fix_ar=$fixAr, darabszam=$darabszam$eredetiArSql$varosSql $kepFrissites WHERE id = $sid");
    } else {
        $kepNev = mentsKepAdmin('fix_kep');
        $eredetiArOszlopSql = $eredetiArOszlopVan ? ", eredeti_ar" : "";
        $eredetiArErtekSql = $eredetiArOszlopVan ? ", " . max(0, $eredetiAr) : "";
        $varosOszlopSql = $varosOszlopVan ? ", varos" : "";
        $varosErtekSql = $varosOszlopVan ? ", '$varos'" : "";
        $liveExtraOszlopSql = $liveUrlOszlopVan ? ", live_url" : "";
        $liveExtraErtekSql = $liveUrlOszlopVan ? ", '$liveUrl'" : "";
        if ($liveAktivOszlopVan) {
            $liveExtraOszlopSql .= ", live_active";
            $liveExtraErtekSql .= ", 0";
        }
        $conn->query("INSERT INTO termekek (nev, leiras, aktualis_ar, licit_lepcso, szallitasi_mod, szallitasi_dij, kategoria_id, kezdes_idopont, lejarat_idopont, kep_url, video_url, feltolto_id, eladva, ajanlat_tipus, fix_ar, darabszam, orszag_kod, penznem$eredetiArOszlopSql$varosOszlopSql$liveExtraOszlopSql) VALUES ('$nev', '$leiras', $fixAr, 0, '$szallitasi_mod', " . ($szallitasi_mod === 'ingyenes' ? '0' : $szallitasi_dij) . ", $kat, '$kezdet', '$lejarat', '$kepNev', '$video', 0, 0, 'fix', $fixAr, $darabszam, " . shobidMarketSqlValue($conn, $adminPiacKod) . ", '" . $conn->real_escape_string($adminPenznemKod) . "'$eredetiArErtekSql$varosErtekSql$liveExtraErtekSql)");
    }

    header('Location: admin.php');
    exit;
}

$kategoriak = [];
$kategoriakOrderBy = $kategoriaSorrendOszlopVan ? 'COALESCE(sorrend, 9999) ASC, nev ASC' : 'nev ASC';
$ks = $conn->query("SELECT * FROM kategoriak ORDER BY $kategoriakOrderBy");
if ($ks) {
    while ($k = $ks->fetch_assoc()) {
        $kategoriak[] = $k;
    }
}

$edit = null;
if (isset($_GET['szerkeszt'])) {
    $szerkesztId = intval($_GET['szerkeszt']);
    $stmt = $conn->prepare("SELECT * FROM termekek WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $szerkesztId);
        $stmt->execute();
        $res = $stmt->get_result();
        $edit = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}

$editFix = null;
if (isset($_GET['fix_szerkeszt'])) {
    $fixSzerkesztId = intval($_GET['fix_szerkeszt']);
    $stmt = $conn->prepare("SELECT * FROM termekek WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $fixSzerkesztId);
        $stmt->execute();
        $res = $stmt->get_result();
        $editFix = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}
$editMultiTermek = null;
$editMultiMeta = null;
$editMultiItems = [];
if ($multiTablaVan && isset($_GET['multi_szerkeszt'])) {
    $multiEditId = intval($_GET['multi_szerkeszt']);
    $metaRes = $conn->query("SELECT ma.* FROM multi_aukciok ma WHERE ma.termek_id = $multiEditId AND EXISTS (SELECT 1 FROM multi_aukcio_tetelek mt WHERE mt.multi_aukcio_id = ma.id) LIMIT 1");
    $editMultiMeta = $metaRes ? $metaRes->fetch_assoc() : null;
    if ($editMultiMeta) {
        $stmt = $conn->prepare("SELECT * FROM termekek WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $multiEditId);
            $stmt->execute();
            $res = $stmt->get_result();
            $editMultiTermek = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        }
        $itemsRes = $conn->query('SELECT * FROM multi_aukcio_tetelek WHERE multi_aukcio_id = ' . intval($editMultiMeta['id']) . ' ORDER BY sorszam ASC, id ASC');
        while ($itemsRes && ($row = $itemsRes->fetch_assoc())) {
            $editMultiItems[] = $row;
        }
    }
}

$editMultiCategory = intval($editMultiTermek['kategoria_id'] ?? ($editMultiItems[0]['kategoria_id'] ?? 0));
$multiVisibleCount = max(1, count($editMultiItems));

$stats = ['osszes' => 0, 'elo' => 0, 'kozelgo' => 0, 'lezart' => 0];
$statRes = $conn->query("SELECT * FROM termekek");
while ($statRes && ($statSor = $statRes->fetch_assoc())) {
    $stats['osszes']++;
    $kezdesTs = !empty($statSor['kezdes_idopont']) ? strtotime((string)$statSor['kezdes_idopont']) : 0;
    $lejaratTs = !empty($statSor['lejarat_idopont']) ? strtotime((string)$statSor['lejarat_idopont']) : 0;
    $mostTs = time();
    $ajanlatTipusStat = (string)($statSor['ajanlat_tipus'] ?? 'licit');
    $lejartStat = intval($statSor['eladva'] ?? 0) === 1
        || ($ajanlatTipusStat === 'fix' && intval($statSor['darabszam'] ?? 0) <= 0)
        || ($lejaratTs > 0 && $lejaratTs <= $mostTs);

    if ($lejartStat) {
        $stats['lezart']++;
    } elseif ($kezdesTs > $mostTs) {
        $stats['kozelgo']++;
    } else {
        $stats['elo']++;
    }
}

if ($isAdmin && isset($_POST['penzugyi_statusz_mentes'])) {
    $statuszAzonosito = trim((string)($_POST['statusz_azonosito'] ?? ''));
    $ujStatusz = normalizaltPenzugyiStatusz(trim((string)($_POST['penzugyi_statusz'] ?? '')));
    if ($statuszAzonosito !== '' && penzugyiStatuszTablaVan($conn)) {
        $stmt = $conn->prepare("INSERT INTO penzugyi_statuszok (azonosito, statusz) VALUES (?, ?)
                      ON DUPLICATE KEY UPDATE statusz = VALUES(statusz)");
        if ($stmt) {
            $stmt->bind_param('ss', $statuszAzonosito, $ujStatusz);
            $stmt->execute();
            $stmt->close();
        }
    }
    $vissza = 'admin.php?panel=penzugy';
    $params = array_filter([
        'penzugy_user' => $adminPenzugyUser > 0 ? $adminPenzugyUser : null,
        'penzugy_q' => $adminPenzugyQ !== '' ? $adminPenzugyQ : null
    ]);
    if ($params) {
        $vissza .= '&' . http_build_query($params);
    }
    header('Location: ' . $vissza);
    exit;
}
$kozelgoKategoriaKep = getKozelgoKategoriaKep();
$felhasznalokLista = [];
$felhasznalokRes = $conn->query("SELECT * FROM felhasznalok ORDER BY id DESC");
if ($felhasznalokRes) {
    while ($row = $felhasznalokRes->fetch_assoc()) {
        $felhasznalokLista[] = $row;
    }
}
$szerkesztettFelhasznalo = null;
if ($adminPanel === 'felhasznalok' && isset($_GET['felhasznalo_szerkeszt'])) {
    $szerkesztettFelhasznaloId = intval($_GET['felhasznalo_szerkeszt']);
    $stmt = $conn->prepare("SELECT * FROM felhasznalok WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $szerkesztettFelhasznaloId);
        $stmt->execute();
        $szerkesztettFelhasznaloRes = $stmt->get_result();
        $szerkesztettFelhasznalo = $szerkesztettFelhasznaloRes ? $szerkesztettFelhasznaloRes->fetch_assoc() : null;
        $stmt->close();
    }
}

$kategoriakLista = [];
$kuponKategoriakLista = [];
$kuponOsszesKategoriaKep = getKuponOsszesKategoriaKep();
$osszesKategoriaKep = getOsszesKategoriaKep();
$mostKategoriaKep = getMostKategoriaKep();
$eloKategoriaKep = getEloKategoriaKep();
$kategoriakListaRes = $conn->query("SELECT * FROM kategoriak ORDER BY $kategoriakOrderBy");
if ($kategoriakListaRes) {
    while ($row = $kategoriakListaRes->fetch_assoc()) {
        $kategoriakLista[] = $row;
    }
}
if (tablaLetezik($conn, 'kupon_kategoriak')) {
    $kuponOrderBy = oszlopLetezik($conn, 'kupon_kategoriak', 'sorrend')
        ? "COALESCE(sorrend, 9999) ASC, nev ASC"
        : "nev ASC";
    $kuponKatRes = $conn->query("SELECT * FROM kupon_kategoriak ORDER BY $kuponOrderBy");
    if ($kuponKatRes) {
        while ($row = $kuponKatRes->fetch_assoc()) {
            $kuponKategoriakLista[] = $row;
        }
    }
}

$adminListaWhere = [];
if ($adminKategoriaszuro > 0) {
    $adminListaWhere[] = "termekek.kategoria_id = $adminKategoriaszuro";
}

$adminOrderBy = "termekek.id DESC";
if ($adminSort === 'legregibb') {
    $adminOrderBy = "termekek.id ASC";
} elseif ($adminSort === 'nev_az') {
    $adminOrderBy = "termekek.nev ASC, termekek.id DESC";
} elseif ($adminSort === 'nev_za') {
    $adminOrderBy = "termekek.nev DESC, termekek.id DESC";
} elseif ($adminSort === 'kategoria_az') {
    $adminOrderBy = "kategoriak.nev ASC, termekek.id DESC";
} elseif ($adminSort === 'kategoria_za') {
    $adminOrderBy = "kategoriak.nev DESC, termekek.id DESC";
}

$listaSql = "SELECT termekek.*, kategoriak.nev AS kat_nev, felhasznalok.becenev AS feltolto_neve, CASE WHEN EXISTS (SELECT 1 FROM multi_aukciok ma WHERE ma.termek_id = termekek.id AND EXISTS (SELECT 1 FROM multi_aukcio_tetelek mt WHERE mt.multi_aukcio_id = ma.id)) THEN 1 ELSE 0 END AS is_multi FROM termekek LEFT JOIN kategoriak ON termekek.kategoria_id = kategoriak.id LEFT JOIN felhasznalok ON termekek.feltolto_id = felhasznalok.id";
if ($adminListaWhere) {
    $listaSql .= " WHERE " . implode(" AND ", $adminListaWhere);
}
$listaSql .= " ORDER BY " . $adminOrderBy;
$lista = $conn->query($listaSql);

$penzugyFelhasznalok = [];
$penzugyFelhasznalokRes = $conn->query("SELECT id, becenev FROM felhasznalok ORDER BY becenev ASC");
if ($penzugyFelhasznalokRes) {
    while ($row = $penzugyFelhasznalokRes->fetch_assoc()) {
        $penzugyFelhasznalok[] = $row;
    }
}

$forditasUzenet = (isset($_GET['mentve']) && (string)$_GET['mentve'] === '1')
    ? st('admin.translations.save_success', 'A fordítások mentése sikeres.')
    : '';
$forditasAdatok = [];
foreach ($forditasLocaleKodok as $localeKod) {
    $forditasAdatok[$localeKod] = shobidI18nLoadLocaleMessages($localeKod);
}
$forditasKulcsok = shobidI18nAllKeys();
if ($adminForditasQ !== '') {
    $needle = function_exists('mb_strtolower')
        ? mb_strtolower($adminForditasQ, 'UTF-8')
        : strtolower($adminForditasQ);
    $forditasKulcsok = array_values(array_filter($forditasKulcsok, function ($kulcs) use ($needle, $forditasAdatok) {
        $kulcsNeedle = function_exists('mb_strtolower')
            ? mb_strtolower((string)$kulcs, 'UTF-8')
            : strtolower((string)$kulcs);
        if (strpos($kulcsNeedle, $needle) !== false) {
            return true;
        }
        foreach ($forditasAdatok as $localeSorok) {
            $ertek = trim((string)($localeSorok[$kulcs] ?? ''));
            $ertekNeedle = function_exists('mb_strtolower')
                ? mb_strtolower($ertek, 'UTF-8')
                : strtolower($ertek);
            if ($ertek !== '' && strpos($ertekNeedle, $needle) !== false) {
                return true;
            }
        }
        return false;
    }));
}
$forditasOldalMeret = 30;
$forditasOsszesKulcs = count($forditasKulcsok);
$forditasOldalakSzama = max(1, (int)ceil($forditasOsszesKulcs / $forditasOldalMeret));
if ($adminForditasPage > $forditasOldalakSzama) {
    $adminForditasPage = $forditasOldalakSzama;
}
$forditasOldalOffset = ($adminForditasPage - 1) * $forditasOldalMeret;
$forditasKulcsokOldalon = array_slice($forditasKulcsok, $forditasOldalOffset, $forditasOldalMeret);

$penzugyiMuveletekMap = [];
$penzugyiStatuszMap = [];
$emailErtesitesMap = [];
if (penzugyiStatuszTablaVan($conn)) {
    $statuszRes = $conn->query("SELECT azonosito, statusz FROM penzugyi_statuszok");
    while ($statuszRes && ($statuszSor = $statuszRes->fetch_assoc())) {
        $azon = (string)$statuszSor['azonosito'];
        $statuszErtek = (string)$statuszSor['statusz'];
        $penzugyiStatuszMap[$azon] = normalizaltPenzugyiStatusz($statuszErtek);
    }
}
if (function_exists('shobidEmailTablaVan') && shobidEmailTablaVan($conn)) {
    $emailRes = $conn->query("SELECT azonosito FROM email_ertesitesek");
    while ($emailRes && ($emailSor = $emailRes->fetch_assoc())) {
        $emailErtesitesMap[(string)$emailSor['azonosito']] = true;
    }
}
if (penzugyiMuveletekTablaVan($conn)) {
    $muveletRes = $conn->query("SELECT azonosito, postara_adva, megkapta, tracking_kod, posta_dokumentum FROM penzugyi_muveletek");
    while ($muveletRes && ($muveletSor = $muveletRes->fetch_assoc())) {
        $penzugyiMuveletekMap[(string)$muveletSor['azonosito']] = [
            'postara_adva' => !empty($muveletSor['postara_adva']),
            'megkapta' => !empty($muveletSor['megkapta']),
            'tracking_kod' => trim((string)($muveletSor['tracking_kod'] ?? '')),
            'posta_dokumentum' => trim((string)($muveletSor['posta_dokumentum'] ?? ''))
        ];
    }
}

$penzugyWhere = [
    "termekek.eladva = 1",
    "(termekek.ajanlat_tipus IS NULL OR termekek.ajanlat_tipus = 'licit')"
];
if ($multiTablaVan) {
    $penzugyWhere[] = "NOT EXISTS (
        SELECT 1
        FROM multi_aukciok ma
        WHERE ma.termek_id = termekek.id
          AND EXISTS (
              SELECT 1
              FROM multi_aukcio_tetelek mt
              WHERE mt.multi_aukcio_id = ma.id
          )
    )";
}
if ($adminPenzugyUser > 0) {
    $penzugyWhere[] = "termekek.feltolto_id = $adminPenzugyUser";
}
if ($adminPenzugyQ !== '') {
    $penzugyQEsc = $conn->real_escape_string($adminPenzugyQ);
    $penzugyWhere[] = "(termekek.nev LIKE '%$penzugyQEsc%' OR termekek.leiras LIKE '%$penzugyQEsc%' OR felhasznalok.becenev LIKE '%$penzugyQEsc%')";
}

$penzugySorok = [];
$penzugySorokMap = [];
$penzugyOsszesNetto = 0.0;
$penzugyOsszesJutalek = 0.0;
$penzugyOsszesKartya = 0.0;
$penzugyOsszesKifizetes = 0.0;
$penzugyOsszesBrutto = 0.0;
$penzugyOsszesBruttoJutalek = 0.0;
$penzugyOsszesBruttoKifizetes = 0.0;
$ledgerWhere = [];
if ($adminPenzugyUser > 0) {
    $ledgerWhere[] = "e.elado_id = $adminPenzugyUser";
}
if ($adminPenzugyQ !== '') {
    $penzugyQEsc = $conn->real_escape_string($adminPenzugyQ);
    $ledgerWhere[] = "(t.nev LIKE '%$penzugyQEsc%' OR t.leiras LIKE '%$penzugyQEsc%' OR f.becenev LIKE '%$penzugyQEsc%' OR e.vasarlo_nev LIKE '%$penzugyQEsc%' OR e.tetel_nev LIKE '%$penzugyQEsc%')";
}

$ledgerSql = "SELECT e.*, t.*, f.becenev AS feltolto_neve, k.nev AS kat_nev,
        e.tipus AS penzugy_tipus,
        e.azonosito AS statusz_azonosito,
        COALESCE(e.felszabadult_at, e.letrehozva) AS esemeny_datum,
        e.vasarlo_nev AS vasarlo_nev,
        e.tetel_nev AS tetel_nev,
        e.letrehozva AS vasarlas_datum
    FROM elado_egyenleg_tetelek e
    INNER JOIN termekek t ON t.id = e.termek_id
    LEFT JOIN felhasznalok f ON f.id = e.elado_id
    LEFT JOIN kategoriak k ON k.id = t.kategoria_id";
if ($ledgerWhere) {
    $ledgerSql .= " WHERE " . implode(" AND ", $ledgerWhere);
}
$ledgerSql .= " ORDER BY COALESCE(e.felszabadult_at, e.letrehozva) DESC, e.id DESC";
$ledgerRes = $conn->query($ledgerSql);
while ($ledgerRes && ($row = $ledgerRes->fetch_assoc())) {
    $statuszAzonosito = trim((string)($row['statusz_azonosito'] ?? ''));
    if ($statuszAzonosito === '' || isset($penzugySorokMap[$statuszAzonosito])) {
        continue;
    }
    $row['tipus_label'] = penzugyiTipusLabel((string)($row['penzugy_tipus'] ?? 'licit'));
    $row['kezdes_idopont'] = $row['vasarlas_datum'] ?? $row['esemeny_datum'] ?? null;
    $row['ajanlat_datum_szoveg'] = penzugyiDatumSzoveg($row);
    $row['penzugyi_statusz'] = $penzugyiStatuszMap[$statuszAzonosito] ?? 'Postázásra vár';
    $penzugySorokMap[$statuszAzonosito] = true;

    $sorLocaleKod = function_exists('shobidPayoutNormalizeLocale')
        ? shobidPayoutNormalizeLocale((string)($row['orszag_kod'] ?? ''), $adminPiacKod)
        : strtolower(trim((string)($row['orszag_kod'] ?? $adminPiacKod)));
    $afaMultiplier = function_exists('shobidPayoutVatMultiplierForLocale')
        ? floatval(shobidPayoutVatMultiplierForLocale($sorLocaleKod))
        : 1.27;
    if ($afaMultiplier <= 0) {
        $afaMultiplier = 1.27;
    }

    $szamitas = [
        'brutto' => floatval($row['brutto_osszeg'] ?? 0),
        'netto' => floatval($row['brutto_osszeg'] ?? 0) / $afaMultiplier,
        'jutalek_netto' => floatval($row['jutalek_brutto'] ?? 0) / $afaMultiplier,
        'kartya_netto' => floatval($row['kartya_brutto'] ?? 0) / $afaMultiplier,
        'kifizetes_netto' => floatval($row['elado_osszeg'] ?? 0) / $afaMultiplier,
        'jutalek_brutto' => floatval($row['jutalek_brutto'] ?? 0),
        'kartya_brutto' => floatval($row['kartya_brutto'] ?? 0),
        'kifizetes_brutto' => floatval($row['elado_osszeg'] ?? 0),
    ];

    $penzugyOsszesBrutto += $szamitas['brutto'];
    $penzugyOsszesNetto += $szamitas['netto'];
    $penzugyOsszesJutalek += $szamitas['jutalek_netto'];
    $penzugyOsszesKartya += $szamitas['kartya_netto'];
    $penzugyOsszesKifizetes += $szamitas['kifizetes_netto'];
    $penzugyOsszesBruttoJutalek += $szamitas['jutalek_brutto'];
    $penzugyOsszesBruttoKifizetes += $szamitas['kifizetes_brutto'];
    $penzugySorok[] = [
        'adat' => $row,
        'szamitas' => $szamitas
    ];
}

usort($penzugySorok, function ($a, $b) {
    $aTs = !empty($a['adat']['esemeny_datum']) ? strtotime((string)$a['adat']['esemeny_datum']) : 0;
    $bTs = !empty($b['adat']['esemeny_datum']) ? strtotime((string)$b['adat']['esemeny_datum']) : 0;
    if ($aTs === $bTs) {
        return intval($b['adat']['id'] ?? 0) <=> intval($a['adat']['id'] ?? 0);
    }
    return $bTs <=> $aTs;
});

$payoutGlobalSummary = shobidPayoutGlobalSummary($conn);
$recentPayouts = shobidPayoutRecentHistory($conn, 25);

if (!function_exists('adminI18nRepairText')) {
    function adminI18nRepairText($text) {
        $value = (string)$text;
        if ($value === '') {
            return $value;
        }

        $map = [
            "\u{00C4}\u{201A}\u{00CB}\u{2021}" => 'á',
            "\u{0102}\u{02C7}" => 'á',
            "\u{0102}\u{00A9}" => 'é',
            "\u{0102}\u{00AD}" => 'í',
            "\u{0102}\u{0142}" => 'ó',
            "\u{0102}\u{00B6}" => 'ö',
            "\u{0102}\u{013D}" => 'ü',
            "\u{0102}\u{0161}" => 'ú',
            "\u{0139}\u{2018}" => 'ő',
            "\u{0139}\u{00B1}" => 'ű',
            "\u{0102}\u{0081}" => 'Á',
            "\u{0102}\u{2030}" => 'É',
            "\u{0102}\u{2013}" => 'Ö'
        ];

        return strtr($value, $map);
    }
}

if (!function_exists('adminSt')) {
    function adminSt($key, $fallback = '', $locale = null) {
        $safeFallback = adminI18nRepairText($fallback);
        $translated = st($key, $safeFallback, $locale);
        return adminI18nRepairText($translated);
    }
}

if (!function_exists('adminStE')) {
    function adminStE($key, $fallback = '', $locale = null) {
        return htmlspecialchars(adminSt($key, $fallback, $locale), ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo adminStE('admin.meta.title', 'Admin Panel - SHOBID'); ?></title>
    <link rel="stylesheet" href="/style.css?v=20260402-1">
</head>
<body class="auction-admin-page">
<?php if (!isset($_SESSION['admin_belepve'])): ?>
<div class="admin-login-shell">
    <div class="admin-login-card">
        <div class="profile-kicker"><?php echo adminStE('admin.login.kicker', 'Admin'); ?></div>
        <h1><?php echo adminStE('admin.login.title', 'Admin belĂ©pĂ©s'); ?></h1>
        <p><?php echo adminStE('admin.login.subtitle', 'BelĂ©pĂ©s utĂˇn ugyanabban a dashboard shellben kezeled az aukciĂłkat.'); ?></p>
        <?php if ($adminLoginError !== ''): ?>
        <div class="profile-alert auth-alert"><?php echo htmlspecialchars($adminLoginError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if (!$adminConfigReady): ?>
        <div class="profile-alert auth-alert"><?php echo adminStE('admin.login.config_missing', 'ĂllĂ­tsd be az ADMIN_PASSWORD_HASH (vagy Ăˇtmenetileg az ADMIN_PASSWORD) Ă©rtĂ©ket a configban.'); ?></div>
        <?php endif; ?>
        <form method="POST" class="admin-login-form">
            <?php echo authCsrfInputHtml(); ?>
            <input type="password" name="pass" placeholder="<?php echo adminStE('admin.login.password_placeholder', 'JelszĂł'); ?>" required>
            <button type="submit"><?php echo adminStE('admin.login.submit', 'BelĂ©pĂ©s'); ?></button>
        </form>
    </div>
</div>
<?php else: ?>
<div class="auction-shell">
    <aside class="auction-sidebar">
        <a class="auction-brand" href="admin.php">
            <img class="auction-brand__logo" src="/kepek/web_sb_logo.webp" alt="Hard Illustrated">
        </a>
        <div class="admin-sidebar-profile">
            <div class="detail-avatar">A</div>
            <div>
                <div class="detail-seller__name"><?php echo adminStE('admin.sidebar.profile', 'Profilom'); ?></div>
                <div class="detail-seller__meta"><?php echo adminStE('admin.sidebar.access', 'Admin hozzĂˇfĂ©rĂ©s'); ?></div>
            </div>
        </div>
        <nav class="auction-sidebar__nav">
            <a class="auction-navlink <?php echo $adminPanel === 'dashboard' ? 'is-active' : ''; ?>" href="admin.php">
                <span class="auction-navlink__icon">&#8962;</span>
                <span><?php echo adminStE('admin.sidebar.dashboard', 'FĹ‘oldal'); ?></span>
            </a>
            <a class="auction-navlink <?php echo $adminPanel === 'osszes' ? 'is-active' : ''; ?>" href="admin.php?panel=osszes">
                <span class="auction-navlink__icon">&#9776;</span>
                <span><?php echo adminStE('admin.sidebar.all_offers', 'Ă–sszes AjĂˇnlat'); ?></span>
            </a>
            <a class="auction-navlink <?php echo $adminPanel === 'ujajanlat' ? 'is-active' : ''; ?>" href="admin.php?panel=ujajanlat">
                <img class="auction-navlink__icon auction-navlink__icon--image" src="/kepek/icons/add.webp" alt="">
                <span><?php echo adminStE('admin.sidebar.new_offer', 'Ăšj AjĂˇnlat'); ?></span>
            </a>
            <a class="auction-navlink <?php echo $adminPanel === 'kategoriak' ? 'is-active' : ''; ?>" href="admin.php?panel=kategoriak">
                <span class="auction-navlink__icon">&#128247;</span>
                <span><?php echo adminStE('admin.sidebar.categories', 'KategĂłriĂˇk'); ?></span>
            </a>
            <a class="auction-navlink <?php echo $adminPanel === 'kupon_kategoriak' ? 'is-active' : ''; ?>" href="admin.php?panel=kupon_kategoriak">
                <span class="auction-navlink__icon">%</span>
                <span>Kupon kategóriák</span>
            </a>
            <a class="auction-navlink <?php echo $adminPanel === 'beallitasok' ? 'is-active' : ''; ?>" href="admin.php?panel=beallitasok">
                <span class="auction-navlink__icon">&#9881;</span>
                <span><?php echo adminStE('admin.sidebar.settings', 'BeĂˇllĂ­tĂˇsok'); ?></span>
            </a>
            <a class="auction-navlink <?php echo $adminPanel === 'felhasznalok' ? 'is-active' : ''; ?>" href="admin.php?panel=felhasznalok">
                <span class="auction-navlink__icon">&#128101;</span>
                <span><?php echo adminStE('admin.sidebar.users', 'FelhasznĂˇlĂłk'); ?></span>
            </a>
            <a class="auction-navlink <?php echo $adminPanel === 'penzugy' ? 'is-active' : ''; ?>" href="admin.php?panel=penzugy">
                <span class="auction-navlink__icon">&#128176;</span>
                <span><?php echo adminStE('admin.sidebar.finance', 'PĂ©nzĂĽgy'); ?></span>
            </a>
            <a class="auction-navlink <?php echo $adminPanel === 'forditasok' ? 'is-active' : ''; ?>" href="admin.php?panel=forditasok">
                <span class="auction-navlink__icon">&#127760;</span>
                <span><?php echo adminStE('admin.sidebar.translations', 'FordĂ­tĂˇsok'); ?></span>
            </a>
            <a class="auction-navlink" href="kijelentkezes.php">
                <img class="auction-navlink__icon auction-navlink__icon--image" src="/kepek/icons/logout.webp" alt="">
                <span><?php echo adminStE('admin.sidebar.logout', 'KilĂ©pĂ©s'); ?></span>
            </a>
        </nav>
    </aside>

    <main class="admin-shell-main">
        <div class="admin-shell-scroll">
            <?php if ($adminPanel === 'kategoriak'): ?>
            <section class="admin-hero">
                <div>
                    <div class="profile-kicker"><?php echo adminStE('admin.categories.kicker', 'KategĂłriĂˇk'); ?></div>
                    <h1><?php echo adminStE('admin.categories.title', 'KategĂłria kezelĂ©s'); ?></h1>
                    <p><?php echo adminStE('admin.categories.subtitle', 'Itt tudsz Ăşj kategĂłriĂˇt lĂ©trehozni, kĂ©pet feltĂ¶lteni hozzĂˇ, vagy kategĂłriĂˇt tĂ¶rĂ¶lni.'); ?></p>
                </div>
            </section>

            <section class="admin-panel">
                <div class="admin-panel__head"><h2><?php echo adminStE('admin.categories.new_title', 'Ăšj kategĂłria'); ?></h2></div>
                <form method="POST" class="admin-formgrid">
                    <div class="create-field"><label><?php echo adminStE('admin.categories.name', 'KategĂłria neve'); ?></label><input type="text" name="uj_kategoria_nev" required></div>
                    <div class="create-field create-field--full">
                        <label><?php echo adminStE('admin.categories.localized_names', 'KategĂłria nevek nyelvenkĂ©nt (opcionĂˇlis)'); ?></label>
                        <div class="admin-category-locale-grid">
                            <?php foreach ($forditasLocaleKodok as $localeKod): ?>
                            <div class="create-field">
                                <label><?php echo htmlspecialchars((string)($i18nAlapLocaleOpcio[$localeKod] ?? strtoupper((string)$localeKod)), ENT_QUOTES, 'UTF-8'); ?></label>
                                <input type="text" name="uj_kategoria_forditas[<?php echo htmlspecialchars((string)$localeKod, ENT_QUOTES, 'UTF-8'); ?>]" value="">
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="create-actions create-field--full"><button type="submit" name="uj_kategoria_mentes" class="profile-submit"><?php echo adminStE('admin.categories.create', 'KategĂłria lĂ©trehozĂˇsa'); ?></button></div>
                </form>
                <div class="profile-table__sub" style="margin-top:1rem;"><?php echo adminStE('admin.categories.rename_hint', 'A kategĂłria ĂˇtnevezĂ©se nem tĂ¶ri el a meglĂ©vĹ‘ ajĂˇnlatokat, mert azok kategĂłria ID alapjĂˇn vannak tĂˇrolva.'); ?></div>
            </section>

            <section class="admin-panel" id="admin-category-images">
                <div class="admin-panel__head"><h2><?php echo adminStE('admin.categories.images_title', 'KategĂłria kĂ©pek'); ?></h2></div>
                <div class="favorite-users-grid">
                    <form method="POST" enctype="multipart/form-data" class="favorite-user-card">
                        <input type="hidden" name="kategoria_kod" value="most">
                        <input type="hidden" name="kategoria_id" value="0">
                        <?php if (!empty($mostKategoriaKep)): ?>
                        <img class="favorite-user-card__avatar" src="/kepek/<?php echo htmlspecialchars($mostKategoriaKep); ?>" alt="<?php echo adminStE('feed.title.now', 'Most'); ?>">
                        <?php else: ?>
                        <div class="favorite-user-card__avatar favorite-user-card__avatar--fallback">M</div>
                        <?php endif; ?>
                        <div class="favorite-user-card__name"><?php echo adminStE('feed.title.now', 'Most'); ?></div>
                        <label class="create-filepicker">
                            <input type="file" name="kategoria_kep" accept=".jpg,.jpeg,.png,.webp,.gif" onchange="updateFileName(this,'kat-file-most')">
                            <span class="create-filepicker__button"><?php echo adminStE('common.filepicker.select_image', 'KĂ©p kivĂˇlasztĂˇsa'); ?></span>
                            <span class="create-filepicker__name" id="kat-file-most"><?php echo adminStE('common.filepicker.no_file', 'Nincs fĂˇjl kivĂˇlasztva'); ?></span>
                        </label>
                        <div class="create-actions create-field--full">
                            <button type="submit" name="kategoria_kep_mentes" class="profile-submit"><?php echo adminStE('common.save', 'MentĂ©s'); ?></button>
                        </div>
                    </form>
                    <form method="POST" enctype="multipart/form-data" class="favorite-user-card">
                        <input type="hidden" name="kategoria_kod" value="kozelgo">
                        <input type="hidden" name="kategoria_id" value="0">
                        <?php if (!empty($kozelgoKategoriaKep)): ?>
                        <img class="favorite-user-card__avatar" src="/kepek/<?php echo htmlspecialchars($kozelgoKategoriaKep); ?>" alt="<?php echo adminStE('feed.title.upcoming', 'KĂ¶zelgĹ‘'); ?>">
                        <?php else: ?>
                        <div class="favorite-user-card__avatar favorite-user-card__avatar--fallback">K</div>
                        <?php endif; ?>
                        <div class="favorite-user-card__name"><?php echo adminStE('feed.title.upcoming', 'KĂ¶zelgĹ‘'); ?></div>
                        <label class="create-filepicker">
                            <input type="file" name="kategoria_kep" accept=".jpg,.jpeg,.png,.webp,.gif" onchange="updateFileName(this,'kat-file-kozelgo')">
                            <span class="create-filepicker__button"><?php echo adminStE('common.filepicker.select_image', 'KĂ©p kivĂˇlasztĂˇsa'); ?></span>
                            <span class="create-filepicker__name" id="kat-file-kozelgo"><?php echo adminStE('common.filepicker.no_file', 'Nincs fĂˇjl kivĂˇlasztva'); ?></span>
                        </label>
                        <div class="create-actions create-field--full">
                            <button type="submit" name="kategoria_kep_mentes" class="profile-submit"><?php echo adminStE('common.save', 'MentĂ©s'); ?></button>
                        </div>
                    </form>
                    <form method="POST" enctype="multipart/form-data" class="favorite-user-card">
                        <input type="hidden" name="kategoria_kod" value="elo">
                        <input type="hidden" name="kategoria_id" value="0">
                        <?php if (!empty($eloKategoriaKep)): ?>
                        <img class="favorite-user-card__avatar" src="/kepek/<?php echo htmlspecialchars($eloKategoriaKep); ?>" alt="<?php echo adminStE('feed.title.live', 'Ă‰lĹ‘'); ?>">
                        <?php else: ?>
                        <div class="favorite-user-card__avatar favorite-user-card__avatar--fallback">&Eacute;</div>
                        <?php endif; ?>
                        <div class="favorite-user-card__name"><?php echo adminStE('feed.title.live', 'Ă‰lĹ‘'); ?></div>
                        <label class="create-filepicker">
                            <input type="file" name="kategoria_kep" accept=".jpg,.jpeg,.png,.webp,.gif" onchange="updateFileName(this,'kat-file-elo')">
                            <span class="create-filepicker__button"><?php echo adminStE('common.filepicker.select_image', 'KĂ©p kivĂˇlasztĂˇsa'); ?></span>
                            <span class="create-filepicker__name" id="kat-file-elo"><?php echo adminStE('common.filepicker.no_file', 'Nincs fĂˇjl kivĂˇlasztva'); ?></span>
                        </label>
                        <div class="create-actions create-field--full">
                            <button type="submit" name="kategoria_kep_mentes" class="profile-submit"><?php echo adminStE('common.save', 'MentĂ©s'); ?></button>
                        </div>
                    </form>
                    <?php foreach ($kategoriakLista as $katIndex => $kat): ?>
                    <form method="POST" enctype="multipart/form-data" class="favorite-user-card favorite-user-card--admin-category">
                        <input type="hidden" name="kategoria_kod" value="">
                        <input type="hidden" name="kategoria_id" value="<?php echo intval($kat['id']); ?>">
                        <?php if (!empty($kat['kep_url'])): ?>
                        <img class="favorite-user-card__avatar" src="/kepek/<?php echo htmlspecialchars($kat['kep_url']); ?>" alt="<?php echo htmlspecialchars($kat['nev']); ?>">
                        <?php else: ?>
                        <div class="favorite-user-card__avatar favorite-user-card__avatar--fallback"><?php echo strtoupper(substr($kat['nev'], 0, 1)); ?></div>
                        <?php endif; ?>
                        <div class="create-field create-field--full">
                            <label><?php echo adminStE('admin.categories.name', 'KategĂłria neve'); ?></label>
                            <input type="text" name="kategoria_nev" value="<?php echo htmlspecialchars($kat['nev']); ?>" required>
                        </div>
                        <div class="create-field create-field--full">
                            <label><?php echo adminStE('admin.categories.translated_names', 'FordĂ­tott kategĂłria nevek'); ?></label>
                            <div class="admin-category-locale-grid">
                                <?php
                                    $katForditasKulcs = function_exists('shobidI18nCategoryKey')
                                        ? shobidI18nCategoryKey(intval($kat['id']))
                                        : '';
                                ?>
                                <?php foreach ($forditasLocaleKodok as $localeKod): ?>
                                <?php
                                    $katForditasErtek = '';
                                    if ($katForditasKulcs !== '' && isset($forditasAdatok[$localeKod]) && is_array($forditasAdatok[$localeKod])) {
                                        $katForditasErtek = trim((string)($forditasAdatok[$localeKod][$katForditasKulcs] ?? ''));
                                    }
                                    if ($katForditasErtek === '' && (string)$localeKod === (string)$i18nAlapLocale) {
                                        $katForditasErtek = (string)$kat['nev'];
                                    }
                                ?>
                                <div class="create-field">
                                    <label><?php echo htmlspecialchars((string)($i18nAlapLocaleOpcio[$localeKod] ?? strtoupper((string)$localeKod)), ENT_QUOTES, 'UTF-8'); ?></label>
                                    <input type="text" name="kategoria_forditas[<?php echo htmlspecialchars((string)$localeKod, ENT_QUOTES, 'UTF-8'); ?>]" value="<?php echo htmlspecialchars((string)$katForditasErtek, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php if ($kategoriaSorrendOszlopVan): ?>
                        <div class="create-field create-field--full">
                            <label><?php echo adminStE('admin.categories.sort_order', 'MegjelenĂ©si sorrend'); ?></label>
                            <input type="number" name="kategoria_sorrend" min="1" value="<?php echo (intval($kat['sorrend'] ?? 0) > 0 && intval($kat['sorrend'] ?? 0) < 9999) ? intval($kat['sorrend']) : ($katIndex + 1); ?>">
                        </div>
                        <?php endif; ?>
                        <label class="create-filepicker">
                            <input type="file" name="kategoria_kep" accept=".jpg,.jpeg,.png,.webp,.gif" onchange="updateFileName(this,'kat-file-<?php echo intval($kat['id']); ?>')">
                            <span class="create-filepicker__button"><?php echo adminStE('common.filepicker.select_image', 'KĂ©p kivĂˇlasztĂˇsa'); ?></span>
                            <span class="create-filepicker__name" id="kat-file-<?php echo intval($kat['id']); ?>"><?php echo adminStE('common.filepicker.no_file', 'Nincs fĂˇjl kivĂˇlasztva'); ?></span>
                        </label>
                        <div class="create-actions create-field--full">
                            <button type="submit" name="kategoria_mentes" class="profile-submit"><?php echo adminStE('common.save', 'MentĂ©s'); ?></button>
                            <button type="submit" name="kategoria_torles" value="<?php echo intval($kat['id']); ?>" class="create-cancel create-cancel--danger" onclick="return confirm('<?php echo adminStE('admin.categories.delete_confirm', 'TĂ¶rlĂ¶d a kategĂłriĂˇt?'); ?>')"><?php echo adminStE('common.delete', 'TĂ¶rlĂ©s'); ?></button>
                        </div>
                    </form>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php elseif ($adminPanel === 'kupon_kategoriak'): ?>
            <section class="admin-hero">
                <div>
                    <div class="profile-kicker">Kupon kategóriák</div>
                    <h1>Kupon kategória kezelés</h1>
                    <p>Itt tudod kezelni a kupon alkategóriákat: név, sorrend, kép és törlés.</p>
                </div>
            </section>

            <section class="admin-panel">
                <div class="admin-panel__head"><h2>Új kupon kategória</h2></div>
                <form method="POST" enctype="multipart/form-data" class="admin-formgrid">
                    <?php echo authCsrfInputHtml(); ?>
                    <div class="create-field">
                        <label>Név</label>
                        <input type="text" name="uj_kupon_kategoria_nev" required>
                    </div>
                    <div class="create-field create-field--full">
                        <label>Forditott nevek nyelvenkent (opcionalis)</label>
                        <div class="admin-category-locale-grid">
                            <?php foreach ($forditasLocaleKodok as $localeKod): ?>
                            <div class="create-field">
                                <label><?php echo htmlspecialchars((string)($i18nAlapLocaleOpcio[$localeKod] ?? strtoupper((string)$localeKod)), ENT_QUOTES, 'UTF-8'); ?></label>
                                <input type="text" name="uj_kupon_kategoria_forditas[<?php echo htmlspecialchars((string)$localeKod, ENT_QUOTES, 'UTF-8'); ?>]" value="">
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="create-field">
                        <label>Kép (opcionális)</label>
                        <label class="create-filepicker">
                            <input type="file" name="uj_kupon_kategoria_kep" accept=".jpg,.jpeg,.png,.webp,.gif" onchange="updateFileName(this,'uj-kupon-kat-file')">
                            <span class="create-filepicker__button">Kép kiválasztása</span>
                            <span class="create-filepicker__name" id="uj-kupon-kat-file">Nincs fájl kiválasztva</span>
                        </label>
                    </div>
                    <div class="create-actions create-field--full">
                        <button type="submit" name="uj_kupon_kategoria_mentes" class="profile-submit">Létrehozás</button>
                    </div>
                </form>
            </section>

            <section class="admin-panel">
                <div class="admin-panel__head"><h2>Meglévő kupon kategóriák</h2></div>
                <div class="favorite-users-grid">
                    <form method="POST" enctype="multipart/form-data" class="favorite-user-card favorite-user-card--admin-category">
                        <?php echo authCsrfInputHtml(); ?>
                        <?php if (!empty($kuponOsszesKategoriaKep)): ?>
                        <img class="favorite-user-card__avatar" src="/kepek/<?php echo htmlspecialchars((string)$kuponOsszesKategoriaKep, ENT_QUOTES, 'UTF-8'); ?>" alt="Összes">
                        <?php else: ?>
                        <div class="favorite-user-card__avatar favorite-user-card__avatar--fallback">Ö</div>
                        <?php endif; ?>
                        <div class="create-field create-field--full">
                            <label>Név</label>
                            <input type="text" value="Összes" readonly>
                        </div>
                        <div class="create-field create-field--full">
                            <label>Forditott nevek nyelvenkent</label>
                            <div class="admin-category-locale-grid">
                                <?php foreach ($forditasLocaleKodok as $localeKod): ?>
                                <?php
                                    $kuponOsszesForditasErtek = '';
                                    if (isset($forditasAdatok[$localeKod]) && is_array($forditasAdatok[$localeKod])) {
                                        $kuponOsszesForditasErtek = trim((string)($forditasAdatok[$localeKod]['feed.coupon.subcategories.all'] ?? ''));
                                    }
                                    if ($kuponOsszesForditasErtek === '' && (string)$localeKod === (string)$i18nAlapLocale) {
                                        $kuponOsszesForditasErtek = 'Osszes';
                                    }
                                ?>
                                <div class="create-field">
                                    <label><?php echo htmlspecialchars((string)($i18nAlapLocaleOpcio[$localeKod] ?? strtoupper((string)$localeKod)), ENT_QUOTES, 'UTF-8'); ?></label>
                                    <input type="text" name="kupon_osszes_forditas[<?php echo htmlspecialchars((string)$localeKod, ENT_QUOTES, 'UTF-8'); ?>]" value="<?php echo htmlspecialchars((string)$kuponOsszesForditasErtek, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <label class="create-filepicker">
                            <input type="file" name="kupon_osszes_kep" accept=".jpg,.jpeg,.png,.webp,.gif" onchange="updateFileName(this,'kupon-osszes-file')">
                            <span class="create-filepicker__button">Kép kiválasztása</span>
                            <span class="create-filepicker__name" id="kupon-osszes-file">Nincs fájl kiválasztva</span>
                        </label>
                        <div class="create-actions create-field--full">
                            <button type="submit" name="kupon_osszes_kep_mentes" class="profile-submit">Mentés</button>
                        </div>
                    </form>
                    <?php foreach ($kuponKategoriakLista as $index => $kuponKat): ?>
                    <form method="POST" enctype="multipart/form-data" class="favorite-user-card favorite-user-card--admin-category">
                        <?php echo authCsrfInputHtml(); ?>
                        <input type="hidden" name="kupon_kategoria_id" value="<?php echo intval($kuponKat['id']); ?>">
                        <?php if (!empty($kuponKat['kep_url'])): ?>
                        <img class="favorite-user-card__avatar" src="/kepek/<?php echo htmlspecialchars((string)$kuponKat['kep_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string)$kuponKat['nev'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php else: ?>
                        <div class="favorite-user-card__avatar favorite-user-card__avatar--fallback"><?php echo strtoupper(substr((string)$kuponKat['nev'], 0, 1)); ?></div>
                        <?php endif; ?>
                        <div class="create-field create-field--full">
                            <label>Név</label>
                            <input type="text" name="kupon_kategoria_nev" value="<?php echo htmlspecialchars((string)$kuponKat['nev'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="create-field create-field--full">
                            <label>Forditott nevek nyelvenkent</label>
                            <div class="admin-category-locale-grid">
                                <?php
                                    $kuponKatForditasKulcs = function_exists('shobidI18nCouponCategoryKey')
                                        ? shobidI18nCouponCategoryKey(intval($kuponKat['id']))
                                        : '';
                                ?>
                                <?php foreach ($forditasLocaleKodok as $localeKod): ?>
                                <?php
                                    $kuponKatForditasErtek = '';
                                    if ($kuponKatForditasKulcs !== '' && isset($forditasAdatok[$localeKod]) && is_array($forditasAdatok[$localeKod])) {
                                        $kuponKatForditasErtek = trim((string)($forditasAdatok[$localeKod][$kuponKatForditasKulcs] ?? ''));
                                    }
                                    if ($kuponKatForditasErtek === '' && (string)$localeKod === (string)$i18nAlapLocale) {
                                        $kuponKatForditasErtek = (string)$kuponKat['nev'];
                                    }
                                ?>
                                <div class="create-field">
                                    <label><?php echo htmlspecialchars((string)($i18nAlapLocaleOpcio[$localeKod] ?? strtoupper((string)$localeKod)), ENT_QUOTES, 'UTF-8'); ?></label>
                                    <input type="text" name="kupon_kategoria_forditas[<?php echo htmlspecialchars((string)$localeKod, ENT_QUOTES, 'UTF-8'); ?>]" value="<?php echo htmlspecialchars((string)$kuponKatForditasErtek, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="create-field create-field--full">
                            <label>Sorrend</label>
                            <input type="number" min="1" name="kupon_kategoria_sorrend" value="<?php echo intval($kuponKat['sorrend'] ?? ($index + 1)); ?>">
                        </div>
                        <label class="create-filepicker">
                            <input type="file" name="kupon_kategoria_kep" accept=".jpg,.jpeg,.png,.webp,.gif" onchange="updateFileName(this,'kupon-kat-file-<?php echo intval($kuponKat['id']); ?>')">
                            <span class="create-filepicker__button">Kép kiválasztása</span>
                            <span class="create-filepicker__name" id="kupon-kat-file-<?php echo intval($kuponKat['id']); ?>">Nincs fájl kiválasztva</span>
                        </label>
                        <div class="create-actions create-field--full">
                            <button type="submit" name="kupon_kategoria_mentes" class="profile-submit">Mentés</button>
                            <button type="submit" name="kupon_kategoria_torles" value="<?php echo intval($kuponKat['id']); ?>" class="create-cancel create-cancel--danger" onclick="return confirm('Törlöd a kupon kategóriát?')">Törlés</button>
                        </div>
                    </form>
                    <?php endforeach; ?>
                    <?php if (!$kuponKategoriakLista): ?>
                    <div class="profile-table__sub">Még nincs kupon kategória.</div>
                    <?php endif; ?>
                </div>
            </section>
            <?php elseif ($adminPanel === 'beallitasok'): ?>
            <section class="admin-hero">
                <div>
                    <div class="profile-kicker"><?php echo adminStE('admin.settings.kicker', 'BeĂˇllĂ­tĂˇsok'); ?></div>
                    <h1><?php echo adminStE('admin.settings.title', 'AjĂˇnlat idĹ‘korlĂˇtok'); ?></h1>
                    <p><?php echo adminStE('admin.settings.subtitle', 'Itt tudod beĂˇllĂ­tani a licit, licit shop Ă©s fix Ăˇras ajĂˇnlat maximum idejĂ©t ĂłrĂˇban.'); ?></p>
                </div>
            </section>

            <section class="admin-panel">
                <div class="admin-panel__head"><h2><?php echo adminStE('admin.settings.max_times', 'Maximum idĹ‘k'); ?></h2></div>
                <form method="POST" class="admin-formgrid">
                    <?php echo authCsrfInputHtml(); ?>
                    <div class="create-field">
                        <label><?php echo adminStE('admin.settings.licit_max_hours', 'LICIT maximum idĹ‘ (Ăłra)'); ?></label>
                        <input type="number" name="licit_max_ora" min="1" value="<?php echo intval($licitMaxOra); ?>" required>
                    </div>
                    <div class="create-field">
                        <label><?php echo adminStE('admin.settings.fix_max_hours', 'FIX ĂR maximum idĹ‘ (Ăłra)'); ?></label>
                        <input type="number" name="fix_max_ora" min="1" value="<?php echo intval($fixMaxOra); ?>" required>
                    </div>
                    <div class="create-field create-field--full">
                        <label><?php echo adminStE('admin.settings.default_locale', 'Alap (fallback) nyelv'); ?></label>
                        <select name="i18n_default_locale" required>
                            <?php foreach ($i18nAlapLocaleOpcio as $localeKod => $localeLabel): ?>
                            <option value="<?php echo htmlspecialchars((string)$localeKod, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (string)$localeKod === (string)$i18nAlapLocale ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$localeLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="create-help"><?php echo adminStE('admin.settings.default_locale_help', 'Ha egy fordĂ­tĂˇs hiĂˇnyzik, innen fog fallbackelni a rendszer.'); ?></div>
                    </div>
                    <div class="create-field create-field--full">
                        <label><?php echo adminStE('admin.settings.vat_by_country', 'ÁFA százalék országonként'); ?></label>
                        <div class="create-help"><?php echo adminStE('admin.settings.vat_by_country_help', 'Itt adhatod meg országonként a bruttó számításhoz használt ÁFA kulcsot (%).'); ?></div>
                    </div>
                    <div class="create-field create-field--full">
                        <label><?php echo adminStE('admin.settings.card_fee_by_country', 'Kartyadij szazalek orszagonkent'); ?></label>
                        <div class="create-help"><?php echo adminStE('admin.settings.card_fee_by_country_help', 'Itt adhatod meg orszagonkent a kartya koltseghez hasznalt szazalekot (%).'); ?></div>
                    </div>
                    <?php foreach ($adminPiacLista as $adminPiacSor): ?>
                    <?php
                        $orszagKod = strtolower(trim((string)($adminPiacSor['code'] ?? '')));
                        if ($orszagKod === '') {
                            continue;
                        }
                        $orszagNev = trim((string)($adminPiacSor['name'] ?? strtoupper($orszagKod)));
                        $afaPercent = floatval($adminAfaByCountry[$orszagKod] ?? 27.0);
                        $cardPercent = floatval($adminCardFeeByCountry[$orszagKod] ?? 2.2);
                    ?>
                    <div class="create-field">
                        <label><?php echo htmlspecialchars($orszagNev . ' (' . strtoupper($orszagKod) . ')', ENT_QUOTES, 'UTF-8'); ?></label>
                        <input type="number" name="vat_by_country_percent[<?php echo htmlspecialchars($orszagKod, ENT_QUOTES, 'UTF-8'); ?>]" min="0" max="99" step="0.01" value="<?php echo htmlspecialchars(number_format($afaPercent, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="create-field">
                        <label><?php echo htmlspecialchars($orszagNev . ' (' . strtoupper($orszagKod) . ') - Kartyadij %', ENT_QUOTES, 'UTF-8'); ?></label>
                        <input type="number" name="card_fee_percent_by_country[<?php echo htmlspecialchars($orszagKod, ENT_QUOTES, 'UTF-8'); ?>]" min="0" max="99" step="0.01" value="<?php echo htmlspecialchars(number_format($cardPercent, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <?php endforeach; ?>
                    <div class="create-actions create-field--full">
                        <button type="submit" name="beallitasok_mentes" class="profile-submit"><?php echo adminStE('admin.settings.save', 'BeĂˇllĂ­tĂˇsok mentĂ©se'); ?></button>
                    </div>
                </form>
            </section>
            <?php elseif ($adminPanel === 'forditasok'): ?>
            <section class="admin-hero">
                <div>
                    <div class="profile-kicker"><?php echo adminStE('admin.translations.kicker', 'FordĂ­tĂˇsok'); ?></div>
                    <h1><?php echo adminStE('admin.translations.title', '9 orszĂˇg nyelvi szĂ¶vegei'); ?></h1>
                    <p><?php echo adminStE('admin.translations.subtitle', 'Kulcs alapĂş fordĂ­tĂˇskezelĹ‘. Minden kulcshoz szerkesztheted a 9 locale szĂ¶vegĂ©t.'); ?></p>
                </div>
            </section>

            <?php if ($forditasUzenet !== ''): ?>
            <section class="admin-panel">
                <div class="profile-alert"><?php echo htmlspecialchars($forditasUzenet, ENT_QUOTES, 'UTF-8'); ?></div>
            </section>
            <?php endif; ?>

            <section class="admin-panel">
                <div class="admin-panel__head"><h2><?php echo adminStE('admin.translations.new_key_title', 'Ăšj fordĂ­tĂˇs kulcs'); ?></h2></div>
                <form method="POST" class="admin-formgrid">
                    <?php echo authCsrfInputHtml(); ?>
                    <input type="hidden" name="forditas_q" value="<?php echo htmlspecialchars($adminForditasQ, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="forditas_page" value="<?php echo intval($adminForditasPage); ?>">
                    <div class="create-field">
                        <label><?php echo adminStE('admin.translations.key_label', 'Kulcs (pl. sidebar.search_placeholder)'); ?></label>
                        <input type="text" name="uj_forditas_kulcs" required>
                    </div>
                    <div class="create-field create-field--full">
                        <label><?php echo adminStE('admin.translations.base_text_prefix', 'Alap'); ?> (<?php echo htmlspecialchars(strtoupper((string)$i18nAlapLocale), ENT_QUOTES, 'UTF-8'); ?>) <?php echo adminStE('admin.translations.base_text_suffix', 'szĂ¶veg'); ?></label>
                        <textarea name="uj_forditas_alap" rows="2" placeholder="<?php echo adminStE('common.search', 'KeresĂ©s'); ?>"></textarea>
                    </div>
                    <div class="create-actions create-field--full">
                        <button type="submit" name="forditas_kulcs_hozzaad" class="profile-submit"><?php echo adminStE('admin.translations.create_key', 'Kulcs lĂ©trehozĂˇsa'); ?></button>
                    </div>
                </form>
            </section>

            <section class="admin-panel admin-panel--wide">
                <div class="admin-panel__head"><h2><?php echo adminStE('admin.translations.table_title', 'FordĂ­tĂˇs tĂˇbla'); ?></h2></div>
                <form method="GET" class="admin-forditas-filter">
                    <input type="hidden" name="panel" value="forditasok">
                    <input type="text" name="forditas_q" value="<?php echo htmlspecialchars($adminForditasQ, ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo adminStE('admin.translations.search_placeholder', 'KeresĂ©s kulcsban vagy szĂ¶vegben'); ?>">
                    <button type="submit" class="profile-submit"><?php echo adminStE('common.filter', 'SzĹ±rĂ©s'); ?></button>
                    <?php if ($adminForditasQ !== ''): ?>
                    <a href="admin.php?panel=forditasok" class="create-cancel"><?php echo adminStE('admin.translations.clear_filter', 'SzĹ±rĹ‘ tĂ¶rlĂ©se'); ?></a>
                    <?php endif; ?>
                </form>
                <?php if ($forditasKulcsok): ?>
                <form method="POST">
                    <?php echo authCsrfInputHtml(); ?>
                    <input type="hidden" name="forditas_q" value="<?php echo htmlspecialchars($adminForditasQ, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="forditas_page" value="<?php echo intval($adminForditasPage); ?>">
                    <div class="create-help"><?php echo adminStE('admin.translations.pagination_help_prefix', 'OldalankĂ©nt'); ?> <?php echo intval($forditasOldalMeret); ?> <?php echo adminStE('admin.translations.pagination_help_middle', 'kulcs mentĹ‘dik'); ?> (<?php echo intval($forditasOsszesKulcs); ?> <?php echo adminStE('admin.translations.pagination_help_suffix', 'kulcs Ă¶sszesen'); ?>).</div>
                    <div class="profile-tablewrap admin-translate-wrap">
                        <table class="profile-table admin-translate-table">
                            <thead>
                                <tr>
                                    <th><?php echo adminStE('admin.translations.col.key', 'Kulcs'); ?></th>
                                    <?php foreach ($forditasLocaleKodok as $localeKod): ?>
                                    <th><?php echo strtoupper(htmlspecialchars($localeKod, ENT_QUOTES, 'UTF-8')); ?></th>
                                    <?php endforeach; ?>
                                    <th><?php echo adminStE('common.action', 'MĹ±velet'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($forditasKulcsokOldalon as $forditasKulcs): ?>
                                <tr>
                                    <td>
                                        <div class="admin-translate-key"><?php echo htmlspecialchars($forditasKulcs, ENT_QUOTES, 'UTF-8'); ?></div>
                                        <input type="hidden" name="forditas_kulcsok[]" value="<?php echo htmlspecialchars($forditasKulcs, ENT_QUOTES, 'UTF-8'); ?>">
                                    </td>
                                    <?php foreach ($forditasLocaleKodok as $localeKod): ?>
                                    <td>
                                        <textarea
                                            class="admin-translate-input"
                                            name="forditas[<?php echo htmlspecialchars($localeKod, ENT_QUOTES, 'UTF-8'); ?>][<?php echo htmlspecialchars($forditasKulcs, ENT_QUOTES, 'UTF-8'); ?>]"
                                            rows="2"
                                        ><?php echo htmlspecialchars((string)($forditasAdatok[$localeKod][$forditasKulcs] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                    </td>
                                    <?php endforeach; ?>
                                    <td>
                                        <button
                                            type="submit"
                                            name="forditas_kulcs_torles"
                                            value="<?php echo htmlspecialchars($forditasKulcs, ENT_QUOTES, 'UTF-8'); ?>"
                                            class="admin-delete"
                                            onclick="return confirm('<?php echo adminStE('admin.translations.delete_confirm', 'Biztosan tĂ¶rlĂ¶d ezt a fordĂ­tĂˇs kulcsot?'); ?>');"
                                        ><?php echo adminStE('common.delete', 'TĂ¶rlĂ©s'); ?></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($forditasOldalakSzama > 1): ?>
                    <div class="profile-pagination">
                        <?php for ($forditasLap = 1; $forditasLap <= $forditasOldalakSzama; $forditasLap++): ?>
                        <?php
                            $lapQuery = ['panel' => 'forditasok', 'forditas_page' => $forditasLap];
                            if ($adminForditasQ !== '') {
                                $lapQuery['forditas_q'] = $adminForditasQ;
                            }
                        ?>
                        <a
                            class="profile-pagination__link <?php echo $forditasLap === $adminForditasPage ? 'is-active' : ''; ?>"
                            href="admin.php?<?php echo htmlspecialchars(http_build_query($lapQuery), ENT_QUOTES, 'UTF-8'); ?>"
                        ><?php echo intval($forditasLap); ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                    <div class="create-actions" style="margin-top:1rem;">
                        <button type="submit" name="forditasok_mentes" class="profile-submit"><?php echo adminStE('admin.translations.save_all', 'Ă–sszes mĂłdosĂ­tĂˇs mentĂ©se'); ?></button>
                    </div>
                </form>
                <?php else: ?>
                <div class="profile-empty"><?php echo adminStE('admin.translations.empty', 'MĂ©g nincs fordĂ­tĂˇs kulcs. Hozz lĂ©tre az elsĹ‘t felĂĽl.'); ?></div>
                <?php endif; ?>
            </section>
            <?php elseif ($adminPanel === 'felhasznalok'): ?>
            <section class="admin-hero">
                <div>
                    <div class="profile-kicker"><?php echo adminStE('admin.users.kicker', 'FelhasznĂˇlĂłk'); ?></div>
                    <h1><?php echo adminStE('admin.users.title', 'FelhasznĂˇlĂłk kezelĂ©se'); ?></h1>
                    <p><?php echo adminStE('admin.users.subtitle', 'Itt tudod a felhasznĂˇlĂłi adatokat szerkeszteni, tĂ¶rĂ¶lni, Ă©s kiemelt felhasznĂˇlĂłvĂˇ tenni.'); ?></p>
                </div>
            </section>

            <?php if ($szerkesztettFelhasznalo): ?>
            <section class="admin-panel">
                <div class="admin-panel__head"><h2><?php echo adminStE('admin.users.edit_title', 'FelhasznĂˇlĂł szerkesztĂ©se'); ?></h2></div>
                <form method="POST" class="admin-formgrid">
                    <input type="hidden" name="felhasznalo_id" value="<?php echo intval($szerkesztettFelhasznalo['id']); ?>">
                    <div class="create-field"><label class="required-label"><?php echo adminStE('admin.users.form.nickname', 'BecenĂ©v'); ?></label><input type="text" name="felhasznalo_becenev" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['becenev'] ?? ''); ?>" required></div>
                    <div class="create-field"><label class="required-label"><?php echo adminStE('admin.users.form.full_name', 'Teljes nĂ©v'); ?></label><input type="text" name="felhasznalo_teljes_nev" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['teljes_nev'] ?? ''); ?>" required></div>
                    <div class="create-field"><label class="required-label"><?php echo adminStE('admin.users.form.email', 'Email'); ?></label><input type="email" name="felhasznalo_email" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['email'] ?? ''); ?>" required></div>
                    <div class="create-field"><label><?php echo adminStE('admin.users.form.phone', 'TelefonszĂˇm'); ?></label><input type="text" name="felhasznalo_telefonszam" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['telefonszam'] ?? ''); ?>"></div>
                    <div class="create-field"><label><?php echo adminStE('admin.users.form.addr_zip', 'LakcĂ­m irĂˇnyĂ­tĂłszĂˇm'); ?></label><input type="text" name="felhasznalo_lakcim_iranyitoszam" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['lakcim_iranyitoszam'] ?? ''); ?>"></div>
                    <div class="create-field"><label><?php echo adminStE('admin.users.form.addr_city', 'LakcĂ­m vĂˇros'); ?></label><input type="text" name="felhasznalo_lakcim_varos" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['lakcim_varos'] ?? ''); ?>"></div>
                    <div class="create-field"><label><?php echo adminStE('admin.users.form.addr_street', 'LakcĂ­m utca'); ?></label><input type="text" name="felhasznalo_lakcim_utca" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['lakcim_utca'] ?? ''); ?>"></div>
                    <div class="create-field"><label><?php echo adminStE('admin.users.form.addr_house', 'LakcĂ­m hĂˇzszĂˇm'); ?></label><input type="text" name="felhasznalo_lakcim_hazszam" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['lakcim_hazszam'] ?? ''); ?>"></div>
                    <div class="create-field"><label><?php echo adminStE('admin.users.form.company_name', 'CĂ©gnĂ©v'); ?></label><input type="text" name="felhasznalo_cegnev" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['cegnev'] ?? ''); ?>"></div>
                    <div class="create-field"><label><?php echo adminStE('admin.users.form.tax_number', 'AdĂłszĂˇm'); ?></label><input type="text" name="felhasznalo_adoszam" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['adoszam'] ?? ''); ?>"></div>
                    <div class="create-field"><label><?php echo adminStE('admin.users.form.company_zip', 'SzĂ©khely irĂˇnyĂ­tĂłszĂˇm'); ?></label><input type="text" name="felhasznalo_ceges_iranyitoszam" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['ceges_iranyitoszam'] ?? ''); ?>"></div>
                    <div class="create-field"><label><?php echo adminStE('admin.users.form.company_city', 'SzĂ©khely vĂˇros'); ?></label><input type="text" name="felhasznalo_ceges_varos" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['ceges_varos'] ?? ''); ?>"></div>
                    <div class="create-field"><label><?php echo adminStE('admin.users.form.company_street', 'SzĂ©khely utca'); ?></label><input type="text" name="felhasznalo_ceges_utca" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['ceges_utca'] ?? ''); ?>"></div>
                    <div class="create-field"><label><?php echo adminStE('admin.users.form.company_house', 'SzĂ©khely hĂˇzszĂˇm'); ?></label><input type="text" name="felhasznalo_ceges_hazszam" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['ceges_hazszam'] ?? ''); ?>"></div>
                    <div class="create-field"><label class="required-label"><?php echo adminStE('admin.users.form.ship_name', 'SzĂˇllĂ­tĂˇsi nĂ©v'); ?></label><input type="text" name="felhasznalo_szallitasi_nev" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['szallitasi_nev'] ?? ''); ?>" required></div>
                    <div class="create-field"><label class="required-label"><?php echo adminStE('admin.users.form.ship_zip', 'SzĂˇllĂ­tĂˇsi irĂˇnyĂ­tĂłszĂˇm'); ?></label><input type="text" name="felhasznalo_szallitasi_iranyitoszam" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['szallitasi_iranyitoszam'] ?? ''); ?>" required></div>
                    <div class="create-field"><label class="required-label"><?php echo adminStE('admin.users.form.ship_city', 'SzĂˇllĂ­tĂˇsi vĂˇros'); ?></label><input type="text" name="felhasznalo_szallitasi_varos" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['szallitasi_varos'] ?? ''); ?>" required></div>
                    <div class="create-field"><label class="required-label"><?php echo adminStE('admin.users.form.ship_street', 'SzĂˇllĂ­tĂˇsi utca'); ?></label><input type="text" name="felhasznalo_szallitasi_utca" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['szallitasi_utca'] ?? ''); ?>" required></div>
                    <div class="create-field"><label class="required-label"><?php echo adminStE('admin.users.form.ship_house', 'SzĂˇllĂ­tĂˇsi hĂˇzszĂˇm'); ?></label><input type="text" name="felhasznalo_szallitasi_hazszam" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['szallitasi_hazszam'] ?? ''); ?>" required></div>
                    <div class="create-field"><label><?php echo adminStE('admin.users.form.ship_floor_door', 'SzĂˇllĂ­tĂˇsi emelet / ajtĂł'); ?></label><input type="text" name="felhasznalo_szallitasi_emelet_ajto" value="<?php echo htmlspecialchars($szerkesztettFelhasznalo['szallitasi_emelet_ajto'] ?? ''); ?>"></div>
                    <div class="create-field create-field--full"><label><?php echo adminStE('admin.users.form.ship_note', 'SzĂˇllĂ­tĂˇsi megjegyzĂ©s'); ?></label><textarea name="felhasznalo_szallitasi_megjegyzes" rows="4"><?php echo htmlspecialchars($szerkesztettFelhasznalo['szallitasi_megjegyzes'] ?? ''); ?></textarea></div>
                    <?php if ($cegkentVasarolokOszlopVan): ?>
                    <label class="admin-checkbox create-field--full">
                        <input type="checkbox" name="felhasznalo_cegkent_vasarolok" value="1" <?php echo !empty($szerkesztettFelhasznalo['cegkent_vasarolok']) ? 'checked' : ''; ?>>
                        <span><?php echo adminStE('admin.users.form.buy_as_company', 'CĂ©gkĂ©nt vĂˇsĂˇrolok'); ?></span>
                    </label>
                    <?php endif; ?>
                    <?php if ($kiemeltFelhasznaloOszlop): ?>
                    <label class="admin-checkbox create-field--full">
                        <input type="checkbox" name="felhasznalo_kiemelt" value="1" <?php echo !empty($szerkesztettFelhasznalo['kiemelt_felhasznalo']) ? 'checked' : ''; ?>>
                        <span><?php echo adminStE('admin.users.form.featured_user', 'Kiemelt felhasznĂˇlĂł'); ?></span>
                    </label>
                    <?php endif; ?>
                    <div class="create-actions create-field--full">
                        <button type="submit" name="felhasznalo_mentes" class="profile-submit"><?php echo adminStE('common.save', 'MentĂ©s'); ?></button>
                        <a href="admin.php?panel=felhasznalok" class="create-cancel"><?php echo adminStE('common.cancel_back', 'MĂ©gse / Vissza'); ?></a>
                    </div>
                </form>
            </section>
            <?php endif; ?>

            <section class="admin-panel">
                <div class="admin-panel__head"><h2><?php echo adminStE('admin.users.all_title', 'Ă–sszes felhasznĂˇlĂł'); ?></h2></div>
                <?php if ($felhasznalokLista): ?>
                <div class="profile-tablewrap admin-finance-wrap">
                    <table class="profile-table admin-finance-table">
                        <thead>
                            <tr><th><?php echo adminStE('common.id', 'ID'); ?></th><th><?php echo adminStE('admin.users.col.nickname', 'BecenĂ©v'); ?></th><th><?php echo adminStE('common.email', 'Email'); ?></th><th><?php echo adminStE('admin.users.col.company', 'CĂ©g'); ?></th><th><?php echo adminStE('admin.users.col.status', 'StĂˇtusz'); ?></th><th><?php echo adminStE('common.action', 'MĹ±velet'); ?></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($felhasznalokLista as $felh): ?>
                            <tr>
                                <td>#<?php echo intval($felh['id']); ?></td>
                                <td><strong><?php echo htmlspecialchars($felh['becenev'] ?? ''); ?></strong><?php if (!empty($felh['teljes_nev'])): ?><div class="profile-table__sub"><?php echo htmlspecialchars($felh['teljes_nev']); ?></div><?php endif; ?></td>
                                <td><?php echo htmlspecialchars($felh['email'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($felh['cegnev'] ?? ''); ?></td>
                                <td><?php echo ($kiemeltFelhasznaloOszlop && !empty($felh['kiemelt_felhasznalo'])) ? adminStE('admin.users.featured', 'Kiemelt felhasznĂˇlĂł') : '-'; ?></td>
                                <td><a class="profile-table__link" href="admin.php?panel=felhasznalok&felhasznalo_szerkeszt=<?php echo intval($felh['id']); ?>"><?php echo adminStE('common.edit', 'SzerkesztĂ©s'); ?></a><span class="admin-divider">|</span><form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo adminStE('admin.users.delete_confirm', 'TĂ¶rlĂ¶d ezt a felhasznĂˇlĂłt?'); ?>')"><?php echo authCsrfInputHtml(); ?><input type="hidden" name="felhasznalo_torles" value="<?php echo intval($felh['id']); ?>"><button type="submit" class="admin-delete" style="background:none;border:0;padding:0;cursor:pointer;"><?php echo adminStE('common.delete', 'TĂ¶rlĂ©s'); ?></button></form></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="profile-empty"><?php echo adminStE('admin.users.empty', 'Nincs mĂ©g felhasznĂˇlĂł.'); ?></div>
                <?php endif; ?>
            </section>
            <?php elseif ($adminPanel === 'penzugy'): ?>
            <section class="admin-hero">
                <div>
                    <div class="profile-kicker"><?php echo adminStE('admin.finance.kicker', 'PĂ©nzĂĽgy'); ?></div>
                    <h1><?php echo adminStE('admin.finance.title', 'Sikeres termĂ©kek pĂ©nzĂĽgyei'); ?></h1>
                    <p><?php echo adminStE('admin.finance.subtitle', 'TermĂ©kenkĂ©nti bruttĂł lista a forgalomrĂłl, jutalĂ©krĂłl, kĂˇrtyadĂ­jrĂłl Ă©s a felhasznĂˇlĂłnak utalandĂł Ă¶sszegrĹ‘l.'); ?></p>
                </div>
            </section>

            <section class="admin-stats">
                <div class="admin-stat"><span><?php echo adminStE('admin.finance.total_gross_revenue', 'Ă–sszes bruttĂł forgalom'); ?></span><strong><?php echo number_format($penzugyOsszesBrutto, 2, ',', ' '); ?> Ft</strong></div>
                <div class="admin-stat"><span><?php echo adminStE('admin.finance.total_net_revenue', 'Ă–sszes nettĂł forgalom'); ?></span><strong><?php echo number_format($penzugyOsszesNetto, 2, ',', ' '); ?> Ft</strong></div>
                <div class="admin-stat"><span><?php echo adminStE('admin.finance.total_gross_commission', 'Ă–sszes bruttĂł jutalĂ©k'); ?></span><strong><?php echo number_format($penzugyOsszesBruttoJutalek, 2, ',', ' '); ?> Ft</strong></div>
                <div class="admin-stat"><span><?php echo adminStE('admin.finance.total_net_commission', 'Ă–sszes nettĂł jutalĂ©k'); ?></span><strong><?php echo number_format($penzugyOsszesJutalek, 2, ',', ' '); ?> Ft</strong></div>
                <div class="admin-stat"><span><?php echo adminStE('admin.finance.total_gross_payout', 'Ă–sszes bruttĂł kifizetĂ©s'); ?></span><strong><?php echo number_format($penzugyOsszesBruttoKifizetes, 2, ',', ' '); ?> Ft</strong></div>
            </section>

            <section class="admin-stats">
                <div class="admin-stat"><span><?php echo adminStE('admin.finance.pending_seller_balance', 'FĂĽggĹ‘ eladĂłi egyenleg'); ?></span><strong><?php echo number_format(floatval($payoutGlobalSummary['pending'] ?? 0), 2, ',', ' '); ?> Ft</strong></div>
                <div class="admin-stat"><span><?php echo adminStE('admin.finance.available_balance', 'KiutalhatĂł egyenleg'); ?></span><strong><?php echo number_format(floatval($payoutGlobalSummary['available'] ?? 0), 2, ',', ' '); ?> Ft</strong></div>
                <div class="admin-stat"><span><?php echo adminStE('admin.finance.already_paid_total', 'MĂˇr kiutalt Ă¶sszeg'); ?></span><strong><?php echo number_format(floatval($payoutGlobalSummary['paid'] ?? 0), 2, ',', ' '); ?> Ft</strong></div>
            </section>

            <section class="admin-panel">
                <div class="admin-panel__head"><h2><?php echo adminStE('admin.finance.payout_watch', 'KiutalĂˇsi figyelĹ‘'); ?></h2></div>
                <div class="profile-empty" style="margin-bottom:1rem;"><?php echo adminStE('admin.finance.payout_watch_help', 'A rendszer a vevĹ‘ MEGKAPTA jelzĂ©se utĂˇn teszi kiutalhatĂłvĂˇ az eladĂłi Ă¶sszeget. A 10 000 Ft feletti kiutalĂˇs automatikus, az alatta lĂ©vĹ‘ egyenleghez az eladĂł kĂ©rhet azonnali kiutalĂˇst.'); ?></div>
                <?php if ($recentPayouts): ?>
                <div class="profile-tablewrap admin-finance-wrap">
                    <table class="profile-table admin-finance-table">
                        <thead>
                            <tr><th><?php echo adminStE('common.date', 'DĂˇtum'); ?></th><th><?php echo adminStE('common.seller', 'EladĂł'); ?></th><th><?php echo adminStE('common.type', 'TĂ­pus'); ?></th><th><?php echo adminStE('admin.finance.col.gross_balance', 'BruttĂł egyenleg'); ?></th><th><?php echo adminStE('admin.finance.col.deduction', 'LevonĂˇs'); ?></th><th><?php echo adminStE('admin.finance.col.paid_amount', 'Kifizetett Ă¶sszeg'); ?></th><th><?php echo adminStE('common.status', 'Ăllapot'); ?></th><th><?php echo adminStE('admin.finance.col.stripe_transfer', 'Stripe transfer'); ?></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentPayouts as $kiutalas): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('Y.m.d H:i', strtotime((string)($kiutalas['letrehozva'] ?? 'now')))); ?></td>
                                <td><?php echo htmlspecialchars($kiutalas['elado_nev'] ?? ('#' . intval($kiutalas['elado_id'] ?? 0))); ?></td>
                                <td><?php echo ($kiutalas['tipus'] ?? 'auto') === 'instant' ? adminStE('common.type.instant', 'Azonnali') : adminStE('common.type.automatic', 'Automatikus'); ?></td>
                                <td><?php echo number_format(floatval($kiutalas['brutto_osszeg'] ?? 0), 2, ',', ' '); ?> Ft</td>
                                <td><?php echo number_format(floatval($kiutalas['levont_dij'] ?? 0), 2, ',', ' '); ?> Ft</td>
                                <td><?php echo number_format(floatval($kiutalas['kifizetett_osszeg'] ?? 0), 2, ',', ' '); ?> Ft</td>
                                <td><?php echo htmlspecialchars($kiutalas['statusz'] ?? 'processed'); ?></td>
                                <td class="profile-table__sub"><?php echo !empty($kiutalas['stripe_transfer_id']) ? htmlspecialchars((string)$kiutalas['stripe_transfer_id']) : '&mdash;'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="profile-empty"><?php echo adminStE('admin.finance.payout_empty', 'MĂ©g nincs rĂ¶gzĂ­tett kiutalĂˇs.'); ?></div>
                <?php endif; ?>
            </section>

            <section class="admin-panel">
                <div class="admin-panel__head"><h2><?php echo adminStE('common.filter', 'SzĹ±rĂ©s'); ?></h2></div>
                <form method="GET" class="admin-formgrid">
                    <input type="hidden" name="panel" value="penzugy">
                    <div class="create-field">
                        <label><?php echo adminStE('common.user', 'FelhasznĂˇlĂł'); ?></label>
                        <select name="penzugy_user">
                            <option value="0"><?php echo adminStE('admin.finance.all_users', 'Ă–sszes felhasznĂˇlĂł'); ?></option>
                            <?php foreach ($penzugyFelhasznalok as $pf): ?>
                            <option value="<?php echo intval($pf['id']); ?>" <?php echo $adminPenzugyUser === intval($pf['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($pf['becenev']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="create-field">
                        <label><?php echo adminStE('common.search', 'KeresĂ©s'); ?></label>
                        <input type="text" name="penzugy_q" value="<?php echo htmlspecialchars($adminPenzugyQ); ?>" placeholder="<?php echo adminStE('admin.finance.search_placeholder', 'TermĂ©k vagy felhasznĂˇlĂł'); ?>">
                    </div>
                    <div class="create-actions create-field--full">
                        <button type="submit" class="profile-submit"><?php echo adminStE('common.refresh_list', 'Lista frissĂ­tĂ©se'); ?></button>
                    </div>
                </form>
            </section>

            <section class="admin-panel admin-panel--wide">
                <div class="admin-panel__head"><h2><?php echo adminStE('admin.finance.list_title', 'PĂ©nzĂĽgyi lista'); ?></h2></div>
                <?php if ($penzugySorok): ?>
                <div class="profile-tablewrap">
                    <table class="profile-table">
                        <thead>
                            <tr><th><?php echo adminStE('common.product', 'TermĂ©k'); ?></th><th><?php echo adminStE('common.user', 'FelhasznĂˇlĂł'); ?></th><th><?php echo adminStE('common.type', 'TĂ­pus'); ?></th><th><?php echo adminStE('admin.finance.col.offer_date', 'AjĂˇnlat dĂˇtuma'); ?></th><th><?php echo adminStE('admin.finance.col.gross_revenue', 'BruttĂł forgalom'); ?></th><th><?php echo adminStE('admin.finance.col.gross_commission', 'BruttĂł jutalĂ©k'); ?></th><th><?php echo adminStE('admin.finance.col.gross_card_fee', 'BruttĂł kĂˇrtyadĂ­j'); ?></th><th><?php echo adminStE('admin.finance.col.gross_payout', 'BruttĂł kifizetĂ©s'); ?></th><th><?php echo adminStE('common.email', 'Email'); ?></th><th><?php echo adminStE('common.shipping', 'Posta'); ?></th><th><?php echo adminStE('common.actions', 'MĹ±veletek'); ?></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($penzugySorok as $sor): $t = $sor['adat']; $sz = $sor['szamitas']; ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($t['nev']); ?><?php if (!empty($t['tetel_nev'])): ?> <span style="color:#c0ff00;">|</span> <?php echo htmlspecialchars($t['tetel_nev']); ?><?php endif; ?></strong><div class="profile-table__sub">#<?php echo intval($t['id']); ?></div></td>
                                <td><?php echo htmlspecialchars($t['feltolto_neve'] ?? adminSt('common.unknown', 'Ismeretlen')); ?></td>
                                <td><?php echo htmlspecialchars($t['tipus_label'] ?? adminSt('common.bid', 'Licit')); ?><?php if (!empty($t['vasarlo_nev'])): ?><div class="profile-table__sub"><?php echo adminStE('common.buyer_prefix', 'VevĹ‘:'); ?> <?php echo htmlspecialchars($t['vasarlo_nev']); ?></div><?php endif; ?></td>
                                <td><?php echo htmlspecialchars($t['ajanlat_datum_szoveg'] ?? '-'); ?></td>
                                <td><?php echo number_format($sz['brutto'], 2, ',', ' '); ?> Ft</td>
                                <td><?php echo number_format($sz['jutalek_brutto'], 2, ',', ' '); ?> Ft</td>
                                <td><?php echo number_format($sz['kartya_brutto'], 2, ',', ' '); ?> Ft</td>
                                <td><?php echo number_format($sz['kifizetes_brutto'], 2, ',', ' '); ?> Ft</td>
                                <td>
                                    <?php $emailBuyerKulcs = 'statusz|' . (string)($t['statusz_azonosito'] ?? '') . '|buyer'; ?>
                                    <?php $emailSellerKulcs = 'statusz|' . (string)($t['statusz_azonosito'] ?? '') . '|seller'; ?>
                                    <?php $emailShippingKulcs = 'shipping|' . (string)($t['statusz_azonosito'] ?? '') . '|buyer'; ?>
                                    <?php if (!empty($emailErtesitesMap[$emailBuyerKulcs])): ?><div class="profile-table__sub"><?php echo adminStE('admin.finance.email.buyer_sent', 'VevĹ‘ email kiment'); ?></div><?php endif; ?>
                                    <?php if (!empty($emailErtesitesMap[$emailSellerKulcs])): ?><div class="profile-table__sub"><?php echo adminStE('admin.finance.email.seller_sent', 'EladĂł email kiment'); ?></div><?php endif; ?>
                                    <?php if (!empty($emailErtesitesMap[$emailShippingKulcs])): ?><div class="profile-table__sub"><?php echo adminStE('admin.finance.email.shipping_sent', 'PostĂˇzĂˇsi email kiment'); ?></div><?php endif; ?>
                                    <?php if (empty($emailErtesitesMap[$emailBuyerKulcs]) && empty($emailErtesitesMap[$emailSellerKulcs]) && empty($emailErtesitesMap[$emailShippingKulcs])): ?>-<?php endif; ?>
                                </td>
                                <td>
                                    <?php $adminMuvelet = $penzugyiMuveletekMap[$t['statusz_azonosito'] ?? ''] ?? ['postara_adva' => false, 'megkapta' => false, 'tracking_kod' => '', 'posta_dokumentum' => '']; ?>
                                    <?php if (!empty($adminMuvelet['tracking_kod'])): ?><div class="profile-table__sub"><?php echo adminStE('admin.finance.tracking_prefix', 'Tracking:'); ?> <?php echo htmlspecialchars($adminMuvelet['tracking_kod']); ?></div><?php endif; ?>
                                    <?php if (!empty($adminMuvelet['posta_dokumentum'])): ?><a class="profile-table__link" href="/dokumentumok/<?php echo rawurlencode((string)$adminMuvelet['posta_dokumentum']); ?>" target="_blank" rel="noopener"><?php echo adminStE('common.document', 'Dokumentum'); ?></a><?php endif; ?>
                                    <?php if (empty($adminMuvelet['tracking_kod']) && empty($adminMuvelet['posta_dokumentum'])): ?>-<?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($adminMuvelet['postara_adva']) && empty($adminMuvelet['megkapta']) && (($t['penzugyi_statusz'] ?? '') !== 'Kiutalva')): ?><div class="profile-table__sub"><?php echo adminStE('admin.finance.waiting_for_shipping', 'PostĂˇzĂˇsra vĂˇr'); ?></div><?php endif; ?>
                                    <?php if (!empty($adminMuvelet['postara_adva'])): ?><div class="profile-table__sub"><?php echo adminStE('admin.finance.shipped', 'PostĂˇra adva'); ?></div><?php endif; ?>
                                    <?php if (!empty($adminMuvelet['megkapta'])): ?><div class="profile-table__sub" style="color:#c0ff00;font-weight:800;"><?php echo adminStE('order.status.received', 'MEGKAPTA'); ?></div><?php endif; ?>
                                    <?php if (($t['penzugyi_statusz'] ?? '') === 'Kiutalva'): ?><div class="profile-table__sub" style="color:#ff3333;font-weight:800;"><?php echo adminStE('payout.status.paid', 'KIUTALVA'); ?></div><?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="profile-empty"><?php echo adminStE('admin.finance.empty_filtered', 'Nincs a szĹ±rĂ©snek megfelelĹ‘ sikeres termĂ©k.'); ?></div>
                <?php endif; ?>
            </section>
            <?php elseif ($adminPanel === 'osszes'): ?>
            <section class="admin-hero">
                <div>
                    <div class="profile-kicker"><?php echo adminStE('admin.offers.kicker', 'AjĂˇnlatok kezelĂ©se'); ?></div>
                    <h1><?php echo adminStE('admin.offers.title', 'Ă–sszes AjĂˇnlat'); ?></h1>
                    <p><?php echo adminStE('admin.offers.subtitle', 'Licit, licit shop Ă©s fix Ăˇras ajĂˇnlat kezelĂ©se egy helyen.'); ?></p>
                </div>
            </section>

            <section class="admin-stats">
                <div class="admin-stat"><span><?php echo adminStE('admin.stats.total_auctions', 'Ă–sszes aukciĂł'); ?></span><strong><?php echo intval($stats['osszes']); ?></strong></div>
                <div class="admin-stat"><span><?php echo adminStE('feed.title.now', 'MOST'); ?></span><strong><?php echo intval($stats['elo']); ?></strong></div>
                <div class="admin-stat"><span><?php echo adminStE('feed.title.upcoming', 'KĂ¶zelgĹ‘'); ?></span><strong><?php echo intval($stats['kozelgo']); ?></strong></div>
                <div class="admin-stat"><span><?php echo adminStE('common.closed', 'LezĂˇrt'); ?></span><strong><?php echo intval($stats['lezart']); ?></strong></div>
            </section>

            <section class="admin-panel">
                <div class="admin-panel__head"><h2><?php echo adminStE('admin.offers.filter_sort', 'Lista szĹ±rĂ©s Ă©s rendezĂ©s'); ?></h2></div>
                <form method="GET" class="admin-formgrid">
                    <input type="hidden" name="panel" value="osszes">
                    <div class="create-field">
                        <label><?php echo adminStE('common.category', 'KategĂłria'); ?></label>
                        <select name="kategoria">
                            <option value="0"><?php echo adminStE('admin.offers.all_categories', 'Ă–sszes kategĂłria'); ?></option>
                            <?php foreach ($kategoriak as $k): ?>
                            <option value="<?php echo intval($k['id']); ?>" <?php echo $adminKategoriaszuro === intval($k['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($k['nev']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="create-field">
                        <label><?php echo adminStE('common.sort', 'RendezĂ©s'); ?></label>
                        <select name="sorrend">
                            <option value="legujabb" <?php echo $adminSort === 'legujabb' ? 'selected' : ''; ?>><?php echo adminStE('admin.sort.time_desc', 'IdĹ‘ szerint: legĂşjabb'); ?></option>
                            <option value="legregibb" <?php echo $adminSort === 'legregibb' ? 'selected' : ''; ?>><?php echo adminStE('admin.sort.time_asc', 'IdĹ‘ szerint: legrĂ©gebbi'); ?></option>
                            <option value="nev_az" <?php echo $adminSort === 'nev_az' ? 'selected' : ''; ?>><?php echo adminStE('admin.sort.name_az', 'NĂ©v szerint: A-Z'); ?></option>
                            <option value="nev_za" <?php echo $adminSort === 'nev_za' ? 'selected' : ''; ?>><?php echo adminStE('admin.sort.name_za', 'NĂ©v szerint: Z-A'); ?></option>
                            <option value="kategoria_az" <?php echo $adminSort === 'kategoria_az' ? 'selected' : ''; ?>><?php echo adminStE('admin.sort.category_az', 'KategĂłria szerint: A-Z'); ?></option>
                            <option value="kategoria_za" <?php echo $adminSort === 'kategoria_za' ? 'selected' : ''; ?>><?php echo adminStE('admin.sort.category_za', 'KategĂłria szerint: Z-A'); ?></option>
                        </select>
                    </div>
                    <div class="create-actions create-field--full">
                        <button type="submit" class="profile-submit"><?php echo adminStE('common.refresh_list', 'Lista frissĂ­tĂ©se'); ?></button>
                    </div>
                </form>
            </section>

            <section class="admin-panel admin-panel--wide">
                <div class="admin-panel__head"><h2><?php echo adminStE('admin.offers.all_auctions', 'Ă–sszes aukciĂł'); ?></h2></div>
                <div class="profile-tablewrap">
                    <table class="profile-table">
                        <thead>
                            <tr><th><?php echo adminStE('common.id', 'ID'); ?></th><th><?php echo adminStE('common.name', 'NĂ©v'); ?></th><th><?php echo adminStE('common.type', 'TĂ­pus'); ?></th><th><?php echo adminStE('admin.offers.uploader', 'FeltĂ¶ltĹ‘'); ?></th><th><?php echo adminStE('common.category', 'KategĂłria'); ?></th><th><?php echo adminStE('admin.offers.price_step', 'Ăr / LĂ©pcsĹ‘'); ?></th><th><?php echo adminStE('common.status', 'StĂˇtusz'); ?></th><th><?php echo adminStE('common.action', 'MĹ±velet'); ?></th></tr>
                        </thead>
                        <tbody>
                            <?php while ($t = $lista->fetch_assoc()): $adminLejart = intval($t['eladva']) === 1 || (!empty($t['lejarat_idopont']) && strtotime($t['lejarat_idopont']) <= time()); ?>
                            <tr>
                                <td>#<?php echo $t['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($t['nev']); ?></strong></td>
                                <td><?php echo (($t['ajanlat_tipus'] ?? 'licit') === 'fix') ? adminStE('offer.type.fix', 'Fix ár') : (intval($t['is_multi']) === 1 ? adminStE('offer.type.multi', 'LICIT SHOP') : adminStE('offer.type.licit', 'Licit')); ?></td>
                                <td><?php echo htmlspecialchars($t['feltolto_neve'] ?? adminSt('admin.common.admin', 'ADMIN')); ?></td>
                                <td><?php echo htmlspecialchars($t['kat_nev'] ?? adminSt('feed.category.fallback', 'Egyéb')); ?></td>
                                <td><?php echo number_format((($t['ajanlat_tipus'] ?? 'licit') === 'fix' ? intval($t['fix_ar'] ?? $t['aktualis_ar']) : intval($t['aktualis_ar'])), 0, ',', ' '); ?> Ft<div class="profile-table__sub"><?php echo (($t['ajanlat_tipus'] ?? 'licit') === 'fix') ? adminStE('offer.type.fix', 'Fix ár') : ('+' . intval($t['licit_lepcso']) . ' Ft'); ?></div></td>
                                <td><span class="admin-status <?php echo $adminLejart ? 'is-closed' : 'is-live'; ?>"><?php echo $adminLejart ? adminStE('common.closed', 'Lezárt') : adminStE('feed.title.now', 'MOST'); ?></span></td>
                                <td><?php if ((($t['ajanlat_tipus'] ?? 'licit') === 'fix')): ?><a class="profile-table__link" href="admin.php?panel=ujajanlat&fix_szerkeszt=<?php echo $t['id']; ?>"><?php echo adminStE('common.edit', 'Szerkeszt'); ?></a><?php elseif (intval($t['is_multi']) === 1): ?><a class="profile-table__link" href="admin.php?panel=ujajanlat&multi_szerkeszt=<?php echo $t['id']; ?>"><?php echo adminStE('common.edit', 'Szerkeszt'); ?></a><?php else: ?><a class="profile-table__link" href="admin.php?panel=ujajanlat&szerkeszt=<?php echo $t['id']; ?>"><?php echo adminStE('common.edit', 'Szerkeszt'); ?></a><?php endif; ?><span class="admin-divider">|</span><form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo adminStE('admin.offers.delete_confirm_short', 'Törlöd?'); ?>')"><?php echo authCsrfInputHtml(); ?><input type="hidden" name="aukcio_torles" value="<?php echo intval($t['id']); ?>"><button type="submit" class="admin-delete" style="background:none;border:0;padding:0;cursor:pointer;"><?php echo adminStE('common.delete', 'Törlés'); ?></button></form></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php elseif ($adminPanel === 'dashboard'): ?>
            <section class="admin-hero">
                <div>
                    <div class="profile-kicker"><?php echo adminStE('admin.offers.title', 'Ă–sszes AjĂˇnlat'); ?></div>
                    <h1><?php echo adminStE('admin.offers.title', 'Ă–sszes AjĂˇnlat'); ?></h1>
                    <p><?php echo adminStE('admin.offers.subtitle', 'Licit, licit shop Ă©s fix Ăˇras ajĂˇnlat kezelĂ©se egy helyen.'); ?></p>
                </div>
            </section>

            <section class="admin-stats">
                <div class="admin-stat"><span><?php echo adminStE('admin.stats.total_auctions', 'Ă–sszes aukciĂł'); ?></span><strong><?php echo intval($stats['osszes']); ?></strong></div>
                <div class="admin-stat"><span><?php echo adminStE('feed.title.now', 'MOST'); ?></span><strong><?php echo intval($stats['elo']); ?></strong></div>
                <div class="admin-stat"><span><?php echo adminStE('feed.title.upcoming', 'KĂ¶zelgĹ‘'); ?></span><strong><?php echo intval($stats['kozelgo']); ?></strong></div>
                <div class="admin-stat"><span><?php echo adminStE('common.closed', 'LezĂˇrt'); ?></span><strong><?php echo intval($stats['lezart']); ?></strong></div>
            </section>

            <?php
            $dashboardUserCount = count($felhasznalokLista);
            $dashboardCategoryCount = count($kategoriakLista);
            $dashboardSalesCount = count($penzugySorok);
            $dashboardFeaturedUserCount = 0;
            foreach ($felhasznalokLista as $dashboardUser) {
                if ($kiemeltFelhasznaloOszlop && !empty($dashboardUser['kiemelt_felhasznalo'])) {
                    $dashboardFeaturedUserCount++;
                }
            }
            $dashboardRecentUsers = array_slice($felhasznalokLista, 0, 5);
            $dashboardRecentSales = array_slice($penzugySorok, 0, 5);
            $dashboardRecentPayouts = array_slice($recentPayouts, 0, 5);
            ?>

            <section class="admin-stats">
                <div class="admin-stat"><span><?php echo adminStE('admin.dashboard.total_users', 'Ă–sszes felhasznĂˇlĂł'); ?></span><strong><?php echo intval($dashboardUserCount); ?></strong></div>
                <div class="admin-stat"><span><?php echo adminStE('admin.sidebar.categories', 'KategĂłriĂˇk'); ?></span><strong><?php echo intval($dashboardCategoryCount); ?></strong></div>
                <div class="admin-stat"><span><?php echo adminStE('admin.dashboard.success_sales', 'Sikeres eladĂˇsok'); ?></span><strong><?php echo intval($dashboardSalesCount); ?></strong></div>
                <div class="admin-stat"><span><?php echo adminStE('admin.dashboard.featured_users', 'Kiemelt felhasznĂˇlĂłk'); ?></span><strong><?php echo intval($dashboardFeaturedUserCount); ?></strong></div>
            </section>

            <section class="admin-hero">
                <div>
                    <div class="profile-kicker"><?php echo adminStE('admin.dashboard.finance.kicker', 'Sikeres termĂ©kek pĂ©nzĂĽgyei'); ?></div>
                    <h1><?php echo adminStE('admin.dashboard.finance.title', 'Sikeres termĂ©kek pĂ©nzĂĽgyei'); ?></h1>
                    <p><?php echo adminStE('admin.dashboard.finance.subtitle', 'TermĂ©kenkĂ©nti nettĂł lista a forgalomrĂłl, jutalĂ©krĂłl, kĂˇrtyadĂ­jrĂłl Ă©s a felhasznĂˇlĂłnak utalandĂł Ă¶sszegrĹ‘l.'); ?></p>
                </div>
            </section>

            <section class="admin-stats">
                <div class="admin-stat"><span><?php echo adminStE('admin.finance.total_gross_revenue', 'Ă–sszes bruttĂł forgalom'); ?></span><strong><?php echo number_format($penzugyOsszesBrutto, 2, ',', ' '); ?> Ft</strong></div>
                <div class="admin-stat"><span><?php echo adminStE('admin.finance.total_net_revenue', 'Ă–sszes nettĂł forgalom'); ?></span><strong><?php echo number_format($penzugyOsszesNetto, 2, ',', ' '); ?> Ft</strong></div>
                <div class="admin-stat"><span><?php echo adminStE('admin.finance.total_gross_commission', 'Ă–sszes bruttĂł jutalĂ©k'); ?></span><strong><?php echo number_format($penzugyOsszesBruttoJutalek, 2, ',', ' '); ?> Ft</strong></div>
                <div class="admin-stat"><span><?php echo adminStE('admin.finance.total_net_commission', 'Ă–sszes nettĂł jutalĂ©k'); ?></span><strong><?php echo number_format($penzugyOsszesJutalek, 2, ',', ' '); ?> Ft</strong></div>
                <div class="admin-stat"><span><?php echo adminStE('admin.finance.total_gross_payout', 'Ă–sszes bruttĂł kifizetĂ©s'); ?></span><strong><?php echo number_format($penzugyOsszesBruttoKifizetes, 2, ',', ' '); ?> Ft</strong></div>
            </section>

            <section class="admin-stats">
                <div class="admin-stat"><span><?php echo adminStE('admin.finance.pending_seller_balance', 'FĂĽggĹ‘ eladĂłi egyenleg'); ?></span><strong><?php echo number_format(floatval($payoutGlobalSummary['pending'] ?? 0), 2, ',', ' '); ?> Ft</strong></div>
                <div class="admin-stat"><span><?php echo adminStE('admin.finance.available_balance', 'KiutalhatĂł egyenleg'); ?></span><strong><?php echo number_format(floatval($payoutGlobalSummary['available'] ?? 0), 2, ',', ' '); ?> Ft</strong></div>
                <div class="admin-stat"><span><?php echo adminStE('admin.finance.already_paid_total', 'MĂˇr kiutalt Ă¶sszeg'); ?></span><strong><?php echo number_format(floatval($payoutGlobalSummary['paid'] ?? 0), 2, ',', ' '); ?> Ft</strong></div>
            </section>

            <section class="admin-panel">
                <div class="admin-panel__head"><h2><?php echo adminStE('admin.dashboard.recent_payouts', 'LegutĂłbbi kiutalĂˇsok'); ?></h2></div>
                <?php if ($dashboardRecentPayouts): ?>
                <div class="profile-tablewrap">
                    <table class="profile-table">
                        <thead>
                            <tr><th><?php echo adminStE('common.date', 'DĂˇtum'); ?></th><th><?php echo adminStE('common.seller', 'EladĂł'); ?></th><th><?php echo adminStE('common.type', 'TĂ­pus'); ?></th><th><?php echo adminStE('admin.finance.col.paid_amount', 'Kifizetett Ă¶sszeg'); ?></th><th><?php echo adminStE('common.status', 'Ăllapot'); ?></th><th><?php echo adminStE('admin.finance.col.stripe_transfer', 'Stripe transfer'); ?></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dashboardRecentPayouts as $kiutalas): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('Y.m.d H:i', strtotime((string)($kiutalas['letrehozva'] ?? 'now')))); ?></td>
                                <td><?php echo htmlspecialchars($kiutalas['elado_nev'] ?? ('#' . intval($kiutalas['elado_id'] ?? 0))); ?></td>
                                <td><?php echo ($kiutalas['tipus'] ?? 'auto') === 'instant' ? adminStE('common.type.instant', 'Azonnali') : adminStE('common.type.automatic', 'Automatikus'); ?></td>
                                <td><?php echo number_format(floatval($kiutalas['kifizetett_osszeg'] ?? 0), 2, ',', ' '); ?> Ft</td>
                                <td><?php echo htmlspecialchars($kiutalas['statusz'] ?? 'processed'); ?></td>
                                <td class="profile-table__sub"><?php echo !empty($kiutalas['stripe_transfer_id']) ? htmlspecialchars((string)$kiutalas['stripe_transfer_id']) : '&mdash;'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="profile-empty"><?php echo adminStE('admin.finance.payout_empty', 'MĂ©g nincs rĂ¶gzĂ­tett kiutalĂˇs.'); ?></div>
                <?php endif; ?>
            </section>

            <section class="admin-panel">
                <div class="admin-panel__head"><h2><?php echo adminStE('admin.dashboard.recent_sales', 'LegutĂłbbi sikeres eladĂˇsok'); ?></h2></div>
                <?php if ($dashboardRecentSales): ?>
                <div class="profile-tablewrap">
                    <table class="profile-table">
                        <thead>
                            <tr><th><?php echo adminStE('common.product', 'TermĂ©k'); ?></th><th><?php echo adminStE('common.type', 'TĂ­pus'); ?></th><th><?php echo adminStE('common.seller', 'EladĂł'); ?></th><th><?php echo adminStE('admin.finance.col.gross_revenue', 'BruttĂł forgalom'); ?></th><th><?php echo adminStE('admin.dashboard.col.payout', 'KifizetĂ©s'); ?></th><th><?php echo adminStE('common.status', 'StĂˇtusz'); ?></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dashboardRecentSales as $dashboardSale): $t = $dashboardSale['adat']; $sz = $dashboardSale['szamitas']; ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($t['nev'] ?? ''); ?></strong><?php if (!empty($t['tetel_nev'])): ?><div class="profile-table__sub"><?php echo htmlspecialchars($t['tetel_nev']); ?></div><?php endif; ?></td>
                                <td><?php echo htmlspecialchars($t['tipus_label'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($t['feltolto_neve'] ?? ''); ?></td>
                                <td><?php echo number_format(floatval($sz['brutto'] ?? 0), 2, ',', ' '); ?> Ft</td>
                                <td><?php echo number_format(floatval($sz['kifizetes_brutto'] ?? 0), 2, ',', ' '); ?> Ft</td>
                                <td><?php echo htmlspecialchars($t['penzugyi_statusz'] ?? adminSt('admin.finance.waiting_for_shipping', 'PostĂˇzĂˇsra vĂˇr')); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="profile-empty"><?php echo adminStE('admin.dashboard.sales_empty', 'MĂ©g nincs sikeres eladĂˇs.'); ?></div>
                <?php endif; ?>
            </section>

            <section class="admin-panel">
                <div class="admin-panel__head"><h2><?php echo adminStE('admin.dashboard.recent_users', 'LegutĂłbbi felhasznĂˇlĂłk'); ?></h2></div>
                <?php if ($dashboardRecentUsers): ?>
                <div class="profile-tablewrap">
                    <table class="profile-table">
                        <thead>
                            <tr><th><?php echo adminStE('common.id', 'ID'); ?></th><th><?php echo adminStE('admin.users.col.nickname', 'BecenĂ©v'); ?></th><th><?php echo adminStE('common.email', 'Email'); ?></th><th><?php echo adminStE('admin.users.col.company', 'CĂ©g'); ?></th><th><?php echo adminStE('common.status', 'StĂˇtusz'); ?></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dashboardRecentUsers as $felh): ?>
                            <tr>
                                <td>#<?php echo intval($felh['id']); ?></td>
                                <td><strong><?php echo htmlspecialchars($felh['becenev'] ?? ''); ?></strong></td>
                                <td><?php echo htmlspecialchars($felh['email'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($felh['cegnev'] ?? ''); ?></td>
                                <td><?php echo ($kiemeltFelhasznaloOszlop && !empty($felh['kiemelt_felhasznalo'])) ? adminStE('admin.users.featured_short', 'Kiemelt') : '&mdash;'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="profile-empty"><?php echo adminStE('admin.users.empty', 'MĂ©g nincs felhasznĂˇlĂł.'); ?></div>
                <?php endif; ?>
            </section>
            <?php else: ?>
            <div class="profile-tabbar">
                <button class="profile-tab <?php echo (!$editMultiTermek && !$editFix) ? 'is-active' : ''; ?>" type="button" onclick="openAdminTab(event, 'admin-single-tab')"><?php echo adminStE('offer.type.licit', 'LICIT'); ?></button>
                <button class="profile-tab <?php echo $editMultiTermek ? 'is-active' : ''; ?>" type="button" onclick="openAdminTab(event, 'admin-multi-tab')"><?php echo adminStE('offer.type.multi', 'LICIT SHOP'); ?></button>
                <button class="profile-tab <?php echo $editFix ? 'is-active' : ''; ?>" type="button" onclick="openAdminTab(event, 'admin-fix-tab')"><?php echo adminStE('offer.type.fix', 'FIX ĂR'); ?></button>
            </div>

            <div id="admin-single-tab" class="profile-tabpanel <?php echo (!$editMultiTermek && !$editFix) ? 'is-active' : ''; ?>">
                <div class="admin-grid">
                    <section class="admin-panel">
                        <div class="admin-panel__head">
                            <h2><?php echo $edit ? adminStE('admin.offer.licit.edit_prefix', 'Licit mĂłdosĂ­tĂˇsa (#') . $edit['id'] . ')' : adminStE('admin.offer.licit.create_title', 'Ăšj licit lĂ©trehozĂˇsa'); ?></h2>
                        </div>
                        <form method="POST" enctype="multipart/form-data" class="admin-formgrid">
                            <?php if ($edit): ?>
                            <input type="hidden" name="szerkeszt_id" value="<?php echo $edit['id']; ?>">
                            <?php endif; ?>
                            <div class="create-field create-field--full"><label><?php echo adminStE('common.product_name', 'TermĂ©k neve'); ?></label><input type="text" name="nev" value="<?php echo $edit ? htmlspecialchars($edit['nev']) : ''; ?>" required></div>
                            <div class="create-field create-field--full"><label><?php echo adminStE('common.description', 'LeĂ­rĂˇs'); ?></label><textarea name="leiras" rows="5" required><?php echo $edit ? htmlspecialchars($edit['leiras'] ?? '') : ''; ?></textarea></div>
                            <div class="create-field"><label><?php echo adminStE('common.category', 'KategĂłria'); ?></label><select name="kategoria" required><option value="">-- <?php echo adminStE('common.choose', 'VĂˇlassz'); ?> --</option><?php foreach ($kategoriak as $k): ?><option value="<?php echo $k['id']; ?>" <?php echo ($edit && intval($edit['kategoria_id']) === intval($k['id'])) ? 'selected' : ''; ?>><?php echo htmlspecialchars($k['nev']); ?></option><?php endforeach; ?></select></div>
                            <div class="create-field"><label><?php echo adminStE('admin.offer.youtube_video', 'YouTube videĂł link'); ?></label><input type="text" name="video_url" value="<?php echo $edit ? htmlspecialchars($edit['video_url']) : ''; ?>" placeholder="https://www.youtube.com/watch?v=..." required></div>
                            <div class="create-field"><label><?php echo adminStE('admin.offer.youtube_live', 'YouTube live link'); ?></label><input type="text" name="live_url" value="<?php echo $edit ? htmlspecialchars($edit['live_url'] ?? '') : ''; ?>" placeholder="https://www.youtube.com/live/..."></div>
                            <div class="create-field"><label><?php echo adminStE('admin.offer.starting_price', 'KikiĂˇltĂˇsi Ăˇr (Ft)'); ?></label><div class="money-input"><input type="number" name="ar" value="<?php echo $edit ? intval($edit['aktualis_ar']) : ''; ?>" placeholder="<?php echo adminStE('admin.offer.starting_price_short', 'KikiĂˇltĂˇsi Ăˇr'); ?>" required><span class="money-input__suffix">Ft</span></div></div>
                            <div class="create-field"><label><?php echo adminStE('admin.offer.bid_step', 'LicitlĂ©pcsĹ‘ (Ft)'); ?></label><div class="money-input"><input type="number" name="lepcso" value="<?php echo $edit ? intval($edit['licit_lepcso']) : ''; ?>" placeholder="<?php echo adminStE('admin.offer.bid_step_short', 'LicitlĂ©pcsĹ‘'); ?>" required><span class="money-input__suffix">Ft</span></div></div>
                            <div class="create-field create-field--full">
                                <label><?php echo adminStE('common.shipping', 'SzĂˇllĂ­tĂˇs'); ?></label>
                                <div class="shipping-choice-group">
                                    <label class="shipping-choice"><input type="radio" name="szallitasi_mod" value="ingyenes" required <?php echo (!$edit || ($edit['szallitasi_mod'] ?? 'ingyenes') === 'ingyenes') ? 'checked' : ''; ?> onchange="toggleShippingFee(document.querySelector('#admin-single-tab .admin-formgrid'),'admin')"><span><?php echo adminStE('shipping.free_full', 'INGYENES SZĂLLĂŤTĂS'); ?></span></label>
                                    <label class="shipping-choice"><input type="radio" name="szallitasi_mod" value="egyedi" required <?php echo ($edit && ($edit['szallitasi_mod'] ?? '') === 'egyedi') ? 'checked' : ''; ?> onchange="toggleShippingFee(document.querySelector('#admin-single-tab .admin-formgrid'),'admin')"><span><?php echo adminStE('shipping.custom_fee', 'Ă‰n adom meg a szĂˇllĂ­tĂˇsi dĂ­jat'); ?></span></label>
                                </div>
                            </div>
                            <div class="create-field" id="admin-shipping-fee-wrap" style="display:none;"><label><?php echo adminStE('shipping.fee', 'SzĂˇllĂ­tĂˇsi dĂ­j'); ?></label><div class="money-input"><input type="number" name="szallitasi_dij" id="admin-shipping-fee-input" min="1" placeholder="<?php echo adminStE('shipping.fee', 'SzĂˇllĂ­tĂˇsi dĂ­j'); ?>" value="<?php echo ($edit && ($edit['szallitasi_mod'] ?? '') === 'egyedi') ? intval($edit['szallitasi_dij'] ?? 0) : ''; ?>"><span class="money-input__suffix">Ft</span></div></div>
                            <div class="create-field"><label><?php echo adminStE('common.offer_start', 'AjĂˇnlat kezdete'); ?></label><input type="datetime-local" name="kezdet" value="<?php echo $edit && !empty($edit['kezdes_idopont']) ? date('Y-m-d\TH:i', strtotime($edit['kezdes_idopont'])) : date('Y-m-d\TH:i'); ?>" required></div>
                            <div class="create-field"><label><?php echo adminStE('common.offer_end', 'AjĂˇnlat vĂ©ge'); ?></label><input type="datetime-local" name="lejarat" value="<?php echo $edit ? date('Y-m-d\TH:i', strtotime($edit['lejarat_idopont'])) : ''; ?>" required><div class="create-help"><?php echo adminStE('admin.offer.max_time_prefix', 'Maximum idĹ‘:'); ?> <?php echo intval($licitMaxOra); ?> <?php echo adminStE('common.hours', 'Ăłra'); ?></div></div>
                            <div class="create-field create-field--full"><label><?php echo adminStE('common.product_image', 'TermĂ©k fotĂłja'); ?></label><label class="create-filepicker"><input type="file" name="kep" id="admin-kep" accept=".jpg,.jpeg,.png,.webp,.gif" <?php echo $edit ? '' : 'required'; ?> onchange="updateFileName(this,'admin-file-name')"><span class="create-filepicker__button"><?php echo adminStE('common.filepicker.select_image', 'KĂ©p kivĂˇlasztĂˇsa'); ?></span><span class="create-filepicker__name" id="admin-file-name"><?php echo $edit ? adminStE('common.filepicker.keep_or_new', 'A jelenlegi kĂ©p megtarthatĂł, vagy vĂˇlaszthatsz Ăşjat') : adminStE('common.filepicker.no_file', 'Nincs fĂˇjl kivĂˇlasztva'); ?></span></label></div>
                            <?php if ($edit): ?>
                            <label class="admin-checkbox create-field--full"><input type="checkbox" name="eladva" <?php echo $edit['eladva'] ? 'checked' : ''; ?>><span><?php echo adminStE('admin.offer.close_auction', 'AukciĂł lezĂˇrĂˇsa'); ?></span></label>
                            <?php endif; ?>
                            <div class="create-actions create-field--full"><button type="submit" name="mentes" class="profile-submit"><?php echo $edit ? adminStE('common.save_changes', 'MĂłdosĂ­tĂˇsok mentĂ©se') : adminStE('admin.offer.licit.create', 'Licit lĂ©trehozĂˇsa'); ?></button><?php if ($edit): ?><a href="admin.php?panel=ujajanlat" class="create-cancel"><?php echo adminStE('common.cancel_new', 'MĂ©gse / Ăšj hozzĂˇadĂˇsa'); ?></a><?php endif; ?></div>
                        </form>
                    </section>

                </div>
            </div>

            <div id="admin-multi-tab" class="profile-tabpanel <?php echo $editMultiTermek ? 'is-active' : ''; ?>">
                <section class="admin-panel">
                    <div class="admin-panel__head">
                        <h2><?php echo $editMultiTermek ? adminStE('admin.offer.multi.edit_prefix', 'LICIT SHOP mĂłdosĂ­tĂˇsa (#') . $editMultiTermek['id'] . ')' : adminStE('admin.offer.multi.create_title', 'LICIT SHOP lĂ©trehozĂˇsa'); ?></h2>
                    </div>
                    <?php if (!$multiTablaVan): ?>
                    <div class="profile-empty"><?php echo adminStE('admin.offer.multi.missing_tables', 'A licit shophez hiĂˇnyoznak az adatbĂˇzis tĂˇblĂˇk.'); ?></div>
                    <?php else: ?>
                    <form method="POST" enctype="multipart/form-data" class="create-formgrid" id="admin-multi-form" data-visible-count="<?php echo $multiVisibleCount; ?>" data-max-items="25">
                        <?php if ($editMultiTermek): ?>
                        <input type="hidden" name="multi_szerkeszt_id" value="<?php echo $editMultiTermek['id']; ?>">
                        <?php endif; ?>

                        <div class="create-field create-field--full"><label><?php echo adminStE('admin.offer.multi.main_name', 'FĹ‘nĂ©v'); ?></label><input type="text" name="multi_fo_nev" value="<?php echo htmlspecialchars($editMultiTermek['nev'] ?? ''); ?>" required></div>
                        <div class="create-field"><label><?php echo adminStE('common.category', 'KategĂłria'); ?></label><select name="multi_kategoria" required><option value="">-- <?php echo adminStE('admin.offer.multi.choose_category', 'VĂˇlassz kategĂłriĂˇt'); ?> --</option><?php foreach ($kategoriak as $k): ?><option value="<?php echo $k['id']; ?>" <?php echo $editMultiCategory === intval($k['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($k['nev']); ?></option><?php endforeach; ?></select></div>
                        <div class="create-field"><label><?php echo adminStE('admin.offer.youtube_video', 'YouTube videĂł link'); ?></label><input type="text" name="multi_video_url" value="<?php echo htmlspecialchars($editMultiTermek['video_url'] ?? ''); ?>" required></div>
                        <div class="create-field"><label><?php echo adminStE('admin.offer.youtube_live', 'YouTube live link'); ?></label><input type="text" name="multi_live_url" value="<?php echo htmlspecialchars($editMultiTermek['live_url'] ?? ''); ?>" placeholder="https://www.youtube.com/live/..."></div>
                        <div class="create-field"><label><?php echo adminStE('admin.offer.multi.start', 'Multi aukciĂł kezdete'); ?></label><input type="datetime-local" name="multi_kezdet" value="<?php echo $editMultiTermek && !empty($editMultiTermek['kezdes_idopont']) ? date('Y-m-d\TH:i', strtotime($editMultiTermek['kezdes_idopont'])) : date('Y-m-d\TH:i'); ?>" required></div>
                        <div class="create-field create-field--full"><label><?php echo adminStE('admin.offer.multi.main_image', 'FĹ‘kĂ©p'); ?></label><label class="create-filepicker"><input type="file" name="multi_kep" id="admin-multi-kep" accept=".jpg,.jpeg,.png,.webp,.gif" <?php echo $editMultiTermek ? '' : 'required'; ?> onchange="updateFileName(this,'admin-multi-file-name')"><span class="create-filepicker__button"><?php echo adminStE('common.filepicker.select_image', 'KĂ©p kivĂˇlasztĂˇsa'); ?></span><span class="create-filepicker__name" id="admin-multi-file-name"><?php echo $editMultiTermek ? adminStE('common.filepicker.keep_or_new', 'A jelenlegi kĂ©p megtarthatĂł, vagy vĂˇlaszthatsz Ăşjat') : adminStE('common.filepicker.no_file', 'Nincs fĂˇjl kivĂˇlasztva'); ?></span></label></div>

                        <div class="create-field create-field--full">
                            <label><?php echo adminStE('admin.offer.multi.items', 'RĂ©szaukciĂłk'); ?></label>
                            <div class="multi-items-stack" id="admin-multi-items-stack">
                                <?php for ($i = 0; $i < 25; $i++): $item = $editMultiItems[$i] ?? null; $itemSzallitas = $item['szallitasi_mod'] ?? 'ingyenes'; $hidden = $i >= $multiVisibleCount; ?>
                                <details class="multi-item-card<?php echo $hidden ? ' is-hidden' : ''; ?>" data-item-index="<?php echo $i; ?>" <?php echo $hidden ? '' : 'open'; ?>>
                                    <summary class="multi-item-card__summary"><span><?php echo adminStE('admin.offer.multi.item_prefix', 'TĂ©tel #'); ?><?php echo $i + 1; ?></span><button type="button" class="multi-item-card__remove" onclick="event.preventDefault(); event.stopPropagation(); removeAdminMultiItem(this, <?php echo $i; ?>);"><?php echo adminStE('common.delete', 'TĂ¶rlĂ©s'); ?></button></summary>
                                    <div class="multi-item-card__body">
                                        <div class="create-field"><label><?php echo adminStE('common.name', 'NĂ©v'); ?></label><input type="text" name="multi_items[<?php echo $i; ?>][nev]" value="<?php echo htmlspecialchars($item['nev'] ?? ''); ?>" required></div>
                                        <div class="create-field"><label><?php echo adminStE('admin.offer.multi.position', 'PozĂ­ciĂł'); ?></label><input type="number" name="multi_items[<?php echo $i; ?>][pozicio]" min="1" max="25" value="<?php echo intval($item['sorszam'] ?? ($i + 1)); ?>" required></div>
                                        <div class="create-field create-field--full"><label><?php echo adminStE('common.description', 'LeĂ­rĂˇs'); ?></label><textarea name="multi_items[<?php echo $i; ?>][leiras]" rows="4" required><?php echo htmlspecialchars($item['leiras'] ?? ''); ?></textarea></div>
                                        <div class="create-field"><label><?php echo adminStE('common.shipping', 'SzĂˇllĂ­tĂˇs'); ?></label><div class="shipping-choice-group"><label class="shipping-choice"><input type="radio" name="multi_items[<?php echo $i; ?>][szallitasi_mod]" value="ingyenes" required <?php echo $itemSzallitas === 'ingyenes' ? 'checked' : ''; ?> onchange="toggleItemShipping(<?php echo $i; ?>)"><span><?php echo adminStE('shipping.free', 'INGYENES'); ?></span></label><label class="shipping-choice"><input type="radio" name="multi_items[<?php echo $i; ?>][szallitasi_mod]" value="egyedi" required <?php echo $itemSzallitas === 'egyedi' ? 'checked' : ''; ?> onchange="toggleItemShipping(<?php echo $i; ?>)"><span><?php echo adminStE('shipping.custom_fee', 'Ă‰n adom meg a szĂˇllĂ­tĂˇsi dĂ­jat'); ?></span></label></div></div>
                                        <div class="create-field" id="multi-item-shipping-wrap-<?php echo $i; ?>" style="display:none;"><label><?php echo adminStE('shipping.fee', 'SzĂˇllĂ­tĂˇsi dĂ­j'); ?></label><div class="money-input"><input type="number" name="multi_items[<?php echo $i; ?>][szallitasi_dij]" id="multi-item-shipping-input-<?php echo $i; ?>" min="1" placeholder="<?php echo adminStE('shipping.fee', 'SzĂˇllĂ­tĂˇsi dĂ­j'); ?>" value="<?php echo $itemSzallitas === 'egyedi' ? intval($item['szallitasi_dij'] ?? 0) : ''; ?>"><span class="money-input__suffix">Ft</span></div></div>
                                        <div class="create-field"><label><?php echo adminStE('admin.offer.starting_bid', 'IndulĂł licit'); ?></label><div class="money-input"><input type="number" name="multi_items[<?php echo $i; ?>][ar]" min="1" required placeholder="<?php echo adminStE('admin.offer.starting_bid', 'IndulĂł licit'); ?>" value="<?php echo htmlspecialchars($item['aktualis_ar'] ?? ''); ?>"><span class="money-input__suffix">Ft</span></div></div>
                                        <div class="create-field"><label><?php echo adminStE('admin.offer.bid_step_short', 'LicitlĂ©pcsĹ‘'); ?></label><div class="money-input"><input type="number" name="multi_items[<?php echo $i; ?>][lepcso]" min="1" required placeholder="<?php echo adminStE('admin.offer.bid_step_short', 'LicitlĂ©pcsĹ‘'); ?>" value="<?php echo htmlspecialchars($item['licit_lepcso'] ?? ''); ?>"><span class="money-input__suffix">Ft</span></div></div>
                                        <div class="create-field"><label><?php echo adminStE('admin.offer.duration_minutes', 'HĂˇny percig tartson'); ?></label><select name="multi_items[<?php echo $i; ?>][idotartam_mp]" required><?php foreach ($durationOptions as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo ($item && intval($item['idotartam_mp']) === intval($value)) ? 'selected' : ''; ?>><?php echo html_entity_decode($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                                    </div>
                                </details>
                                <?php endfor; ?>
                            </div>
                            <button type="button" class="create-cancel create-cancel--secondary" id="admin-add-multi-item">+ <?php echo adminStE('admin.offer.multi.add_item', 'Licit tĂ©tel hozzĂˇadĂˇsa'); ?></button>
                        </div>

                        <?php if ($editMultiTermek): ?>
                        <label class="admin-checkbox create-field--full"><input type="checkbox" name="multi_eladva" <?php echo $editMultiTermek['eladva'] ? 'checked' : ''; ?>><span><?php echo adminStE('admin.offer.close_auction', 'AukciĂł lezĂˇrĂˇsa'); ?></span></label>
                        <?php endif; ?>

                        <div class="create-actions create-field--full"><button type="submit" name="mentes_multi" class="profile-submit"><?php echo $editMultiTermek ? adminStE('admin.offer.multi.save', 'LICIT SHOP mentĂ©se') : adminStE('admin.offer.multi.create', 'LICIT SHOP lĂ©trehozĂˇsa'); ?></button><?php if ($editMultiTermek): ?><a href="admin.php?panel=ujajanlat" class="create-cancel"><?php echo adminStE('common.cancel_new', 'MĂ©gse / Ăšj hozzĂˇadĂˇsa'); ?></a><?php endif; ?></div>
                    </form>
                    <?php endif; ?>
                </section>
            </div>
            <div id="admin-fix-tab" class="profile-tabpanel <?php echo $editFix ? 'is-active' : ''; ?>">
                <section class="admin-panel">
                    <div class="admin-panel__head">
                        <h2><?php echo $editFix ? adminStE('admin.offer.fix.edit_prefix', 'Fix Ăˇras ajĂˇnlat mĂłdosĂ­tĂˇsa (#') . $editFix['id'] . ')' : adminStE('admin.offer.fix.create_title', 'Fix Ăˇras ajĂˇnlat lĂ©trehozĂˇsa'); ?></h2>
                    </div>
                    <form method="POST" enctype="multipart/form-data" class="create-formgrid" id="admin-fix-form">
                        <?php if ($editFix): ?>
                        <input type="hidden" name="fix_szerkeszt_id" value="<?php echo $editFix['id']; ?>">
                        <?php endif; ?>
                        <div class="create-field create-field--full"><label><?php echo adminStE('common.product_name', 'TermĂ©k neve'); ?></label><input type="text" name="fix_nev" value="<?php echo htmlspecialchars($editFix['nev'] ?? ''); ?>" required></div>
                        <div class="create-field create-field--full"><label><?php echo adminStE('common.description', 'LeĂ­rĂˇs'); ?></label><textarea name="fix_leiras" rows="5" required><?php echo htmlspecialchars($editFix['leiras'] ?? ''); ?></textarea></div>
                        <div class="create-field"><label><?php echo adminStE('common.category', 'KategĂłria'); ?></label><select name="fix_kategoria" required><option value="">-- <?php echo adminStE('common.choose', 'VĂˇlassz'); ?> --</option><?php foreach ($kategoriak as $k): ?><option value="<?php echo $k['id']; ?>" <?php echo ($editFix && intval($editFix['kategoria_id']) === intval($k['id'])) ? 'selected' : ''; ?>><?php echo htmlspecialchars($k['nev']); ?></option><?php endforeach; ?></select></div>
                        <div class="create-field"><label><?php echo adminStE('admin.offer.youtube_video', 'YouTube videĂł link'); ?></label><input type="text" name="fix_video_url" value="<?php echo htmlspecialchars($editFix['video_url'] ?? ''); ?>" placeholder="https://www.youtube.com/watch?v=..." required></div>
                        <div class="create-field"><label><?php echo adminStE('admin.offer.youtube_live', 'YouTube live link'); ?></label><input type="text" name="fix_live_url" value="<?php echo htmlspecialchars($editFix['live_url'] ?? ''); ?>" placeholder="https://www.youtube.com/live/..."></div>
                        <div class="create-field"><label><?php echo adminStE('offer.type.fix_price', 'Fix Ăˇr (Ft)'); ?></label><div class="money-input"><input type="number" name="fix_ar" value="<?php echo $editFix ? intval($editFix['fix_ar'] ?? $editFix['aktualis_ar']) : ''; ?>" min="1" placeholder="<?php echo adminStE('offer.type.fix_price_short', 'Fix Ăˇr'); ?>" required><span class="money-input__suffix">Ft</span></div></div>
                        <div class="create-field"><label><?php echo adminStE('admin.offer.original_price', 'Eredeti Ăˇr'); ?></label><div class="money-input"><input type="number" name="fix_eredeti_ar" value="<?php echo $editFix ? intval($editFix['eredeti_ar'] ?? 0) : ''; ?>" min="0" placeholder="<?php echo adminStE('admin.offer.original_price', 'Eredeti Ăˇr'); ?>"><span class="money-input__suffix">Ft</span></div></div>
                        <div class="create-field"><label><?php echo adminStE('common.city', 'VĂˇros'); ?></label><input type="text" name="fix_varos" value="<?php echo htmlspecialchars($editFix['varos'] ?? ''); ?>" placeholder="<?php echo adminStE('admin.offer.city_placeholder', 'Pl. Budapest'); ?>"></div>
                        <div class="create-field"><label><?php echo adminStE('common.quantity', 'DarabszĂˇm'); ?></label><input type="number" name="fix_darabszam" value="<?php echo $editFix ? intval($editFix['darabszam'] ?? 1) : 1; ?>" min="1" required></div>
                        <div class="create-field"><label><?php echo adminStE('common.offer_start', 'AjĂˇnlat kezdete'); ?></label><input type="datetime-local" name="fix_kezdet" value="<?php echo $editFix && !empty($editFix['kezdes_idopont']) ? date('Y-m-d\TH:i', strtotime($editFix['kezdes_idopont'])) : date('Y-m-d\TH:i'); ?>" required></div>
                        <div class="create-field"><label><?php echo adminStE('common.offer_end', 'AjĂˇnlat vĂ©ge'); ?></label><input type="datetime-local" name="fix_lejarat" value="<?php echo $editFix && !empty($editFix['lejarat_idopont']) ? date('Y-m-d\TH:i', strtotime($editFix['lejarat_idopont'])) : ''; ?>" required><div class="create-help"><?php echo adminStE('admin.offer.fix.max_time_prefix', 'A fix Ăˇras ajĂˇnlat maximum ideje:'); ?> <?php echo intval($fixMaxOra); ?> <?php echo adminStE('common.hours', 'Ăłra'); ?></div></div>
                        <div class="create-field create-field--full">
                            <label><?php echo adminStE('common.shipping', 'SzĂˇllĂ­tĂˇs'); ?></label>
                            <div class="shipping-choice-group">
                                <label class="shipping-choice"><input type="radio" name="fix_szallitasi_mod" value="ingyenes" required <?php echo (!$editFix || ($editFix['szallitasi_mod'] ?? 'ingyenes') === 'ingyenes') ? 'checked' : ''; ?> onchange="toggleShippingFee(document.querySelector('#admin-fix-form'),'admin-fix','fix_szallitasi_mod')"><span><?php echo adminStE('shipping.free_full', 'INGYENES SZĂLLĂŤTĂS'); ?></span></label>
                                <label class="shipping-choice"><input type="radio" name="fix_szallitasi_mod" value="egyedi" required <?php echo ($editFix && ($editFix['szallitasi_mod'] ?? '') === 'egyedi') ? 'checked' : ''; ?> onchange="toggleShippingFee(document.querySelector('#admin-fix-form'),'admin-fix','fix_szallitasi_mod')"><span><?php echo adminStE('shipping.custom_fee', 'Ă‰n adom meg a szĂˇllĂ­tĂˇsi dĂ­jat'); ?></span></label>
                            </div>
                        </div>
                        <div class="create-field" id="admin-fix-shipping-fee-wrap" style="display:none;"><label><?php echo adminStE('shipping.fee', 'SzĂˇllĂ­tĂˇsi dĂ­j'); ?></label><div class="money-input"><input type="number" name="fix_szallitasi_dij" id="admin-fix-shipping-fee-input" min="1" placeholder="<?php echo adminStE('shipping.fee', 'SzĂˇllĂ­tĂˇsi dĂ­j'); ?>" value="<?php echo ($editFix && ($editFix['szallitasi_mod'] ?? '') === 'egyedi') ? intval($editFix['szallitasi_dij'] ?? 0) : ''; ?>"><span class="money-input__suffix">Ft</span></div></div>
                        <div class="create-field create-field--full"><label><?php echo adminStE('common.product_image', 'TermĂ©k fotĂłja'); ?></label><label class="create-filepicker"><input type="file" name="fix_kep" id="admin-fix-kep" accept=".jpg,.jpeg,.png,.webp,.gif" <?php echo $editFix ? '' : 'required'; ?> onchange="updateFileName(this,'admin-fix-file-name')"><span class="create-filepicker__button"><?php echo adminStE('common.filepicker.select_image', 'KĂ©p kivĂˇlasztĂˇsa'); ?></span><span class="create-filepicker__name" id="admin-fix-file-name"><?php echo $editFix ? adminStE('common.filepicker.keep_or_new', 'A jelenlegi kĂ©p megtarthatĂł, vagy vĂˇlaszthatsz Ăşjat') : adminStE('common.filepicker.no_file', 'Nincs fĂˇjl kivĂˇlasztva'); ?></span></label></div>
                        <?php if ($editFix): ?>
                        <label class="admin-checkbox create-field--full"><input type="checkbox" name="fix_eladva" <?php echo $editFix['eladva'] ? 'checked' : ''; ?>><span><?php echo adminStE('admin.offer.close_offer', 'AjĂˇnlat lezĂˇrĂˇsa'); ?></span></label>
                        <?php endif; ?>
                        <div class="create-actions create-field--full"><button type="submit" name="mentes_fix" class="profile-submit"><?php echo $editFix ? adminStE('common.save_changes', 'MĂłdosĂ­tĂˇsok mentĂ©se') : adminStE('admin.offer.fix.create', 'Fix Ăˇras ajĂˇnlat lĂ©trehozĂˇsa'); ?></button><?php if ($editFix): ?><a href="admin.php?panel=ujajanlat" class="create-cancel"><?php echo adminStE('common.cancel_new', 'MĂ©gse / Ăšj hozzĂˇadĂˇsa'); ?></a><?php endif; ?></div>
                    </form>
                </section>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php endif; ?>
<script>
const csrfToken = <?php echo json_encode(authCsrfToken(), JSON_UNESCAPED_UNICODE); ?>;
document.querySelectorAll('form').forEach(function(form) {
    const method = (form.getAttribute('method') || 'GET').toUpperCase();
    if (method !== 'POST') return;
    if (form.querySelector('input[name="csrf_token"]')) return;
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'csrf_token';
    hidden.value = csrfToken;
    form.appendChild(hidden);
});

function updateFileName(input, targetId) {
    const target = document.getElementById(targetId);
    if (!target) return;
    target.textContent = input.files && input.files.length ? input.files[0].name : <?php echo json_encode(adminSt('common.filepicker.no_file', 'Nincs fĂˇjl kivĂˇlasztva'), JSON_UNESCAPED_UNICODE); ?>;
}

function toggleShippingFee(form, prefix, groupName) {
    const selected = form.querySelector('input[name="' + (groupName || 'szallitasi_mod') + '"]:checked');
    const wrap = document.getElementById(prefix + '-shipping-fee-wrap');
    const input = document.getElementById(prefix + '-shipping-fee-input');
    if (!selected || !wrap || !input) return;
    const kell = selected.value === 'egyedi';
    wrap.style.display = kell ? 'grid' : 'none';
    input.required = kell;
    if (!kell) input.value = '';
}

function toggleItemShipping(index) {
    const selected = document.querySelector('input[name="multi_items[' + index + '][szallitasi_mod]"]:checked');
    const wrap = document.getElementById('multi-item-shipping-wrap-' + index);
    const input = document.getElementById('multi-item-shipping-input-' + index);
    if (!selected || !wrap || !input) return;
    const kell = selected.value === 'egyedi';
    wrap.style.display = kell ? 'grid' : 'none';
    input.required = kell;
    if (!kell) input.value = '';
}

function openAdminTab(evt, tabName) {
    const panels = document.getElementsByClassName('profile-tabpanel');
    for (let i = 0; i < panels.length; i++) panels[i].classList.remove('is-active');
    const tabs = document.getElementsByClassName('profile-tab');
    for (let i = 0; i < tabs.length; i++) tabs[i].classList.remove('is-active');
    document.getElementById(tabName).classList.add('is-active');
    evt.currentTarget.classList.add('is-active');
}

function initMultiItemCards() {
    const form = document.getElementById('admin-multi-form');
    const addButton = document.getElementById('admin-add-multi-item');
    if (!form || !addButton) return;

    let visibleCount = parseInt(form.dataset.visibleCount || '1', 10);
    const maxItems = parseInt(form.dataset.maxItems || '25', 10);

    function syncButton() {
        addButton.style.display = visibleCount >= maxItems ? 'none' : 'inline-flex';
    }

    addButton.addEventListener('click', function () {
        if (visibleCount >= maxItems) return;
        const card = form.querySelector('.multi-item-card[data-item-index="' + visibleCount + '"]');
        if (!card) return;
        card.classList.remove('is-hidden');
        card.setAttribute('open', 'open');
        visibleCount += 1;
        form.dataset.visibleCount = String(visibleCount);
        syncButton();
    });

    syncButton();
}

function attachMaxDurationValidation(formSelector, startName, endName, maxHours) {
    const form = document.querySelector(formSelector);
    if (!form) return;
    form.addEventListener('submit', function (event) {
        const startInput = form.querySelector('[name="' + startName + '"]');
        const endInput = form.querySelector('[name="' + endName + '"]');
        if (!startInput || !endInput || !startInput.value || !endInput.value) return;
        const start = new Date(startInput.value);
        const end = new Date(endInput.value);
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return;
        if ((end.getTime() - start.getTime()) > (maxHours * 60 * 60 * 1000)) {
            event.preventDefault();
            alert(<?php echo json_encode(adminSt('admin.validation.max_hours_prefix', 'A maximum idĹ‘ '), JSON_UNESCAPED_UNICODE); ?> + maxHours + <?php echo json_encode(adminSt('admin.validation.max_hours_suffix', ' Ăłra lehet.'), JSON_UNESCAPED_UNICODE); ?>);
        }
    });
}

function removeAdminMultiItem(button, index) {
    const form = document.getElementById('admin-multi-form');
    if (!form) return;
    const card = form.querySelector('.multi-item-card[data-item-index="' + index + '"]');
    if (!card) return;

    const fields = card.querySelectorAll('input, textarea, select');
    fields.forEach(function (field) {
        if (field.type === 'radio') {
            field.checked = field.value === 'ingyenes';
        } else if (field.name.indexOf('[pozicio]') !== -1) {
            field.value = String(index + 1);
        } else {
            field.value = '';
        }
    });

    if (index === 0) {
        card.setAttribute('open', 'open');
    } else {
        card.classList.add('is-hidden');
        card.removeAttribute('open');
    }

    let visibleCount = 0;
    form.querySelectorAll('.multi-item-card').forEach(function (itemCard) {
        if (!itemCard.classList.contains('is-hidden')) visibleCount += 1;
    });
    form.dataset.visibleCount = String(Math.max(1, visibleCount));
    document.getElementById('admin-add-multi-item').disabled = false;
    toggleItemShipping(index);
}

const singleAdminForm = document.querySelector('#admin-single-tab .admin-formgrid');
if (singleAdminForm) toggleShippingFee(singleAdminForm, 'admin');
const fixAdminForm = document.getElementById('admin-fix-form');
if (fixAdminForm) toggleShippingFee(fixAdminForm, 'admin-fix', 'fix_szallitasi_mod');
for (let i = 0; i < 25; i++) toggleItemShipping(i);
initMultiItemCards();
attachMaxDurationValidation('#admin-single-tab form', 'kezdet', 'lejarat', <?php echo intval($licitMaxOra); ?>);
attachMaxDurationValidation('#admin-fix-tab form', 'fix_kezdet', 'fix_lejarat', <?php echo intval($fixMaxOra); ?>);
</script>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>


