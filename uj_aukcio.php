<?php
include __DIR__ . '/db.php';
include __DIR__ . '/auth.php';
include __DIR__ . '/stripe_helper.php';
include_once __DIR__ . '/global/sidebar_info_content.php';
include_once __DIR__ . '/global/i18n.php';

if (function_exists('shobidMarketEnsureTermekekOrszagKod')) {
    shobidMarketEnsureTermekekOrszagKod($conn);
}
if (function_exists('shobidStripeEnsureTermekPenznemOszlop')) {
    shobidStripeEnsureTermekPenznemOszlop($conn);
}

$pageLocaleCode = function_exists('shobidI18nCurrentLocale') ? shobidI18nCurrentLocale() : 'hu-hu';
$pageHtmlLang = substr($pageLocaleCode, 0, 2);

$_sidebarInfoModalContentJson = '{}';
if (function_exists('getSidebarInfoModalContent')) {
    $tmpSidebarInfoContentJson = json_encode(getSidebarInfoModalContent(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($tmpSidebarInfoContentJson) && $tmpSidebarInfoContentJson !== '') {
        $_sidebarInfoModalContentJson = $tmpSidebarInfoContentJson;
    }
}

function tablaLetezik($conn, $tablaNev) {
    $tablaNev = $conn->real_escape_string($tablaNev);
    $res = $conn->query("SHOW TABLES LIKE '$tablaNev'");
    return $res && $res->num_rows > 0;
}

function feldolgozottAukcioKepMentese($tmpPath, $celPath) {
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

function mentsKep($fileKey, $alapertelmezett = "nincs_kep.jpg") {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== 0) {
        return $alapertelmezett;
    }

    $kepekDir = __DIR__ . '/kepek/ajanlatok';
    if (!is_dir($kepekDir)) {
        mkdir($kepekDir, 0777, true);
    }

    $eredetiNev = basename($_FILES[$fileKey]['name']);
    $biztonsagosNev = preg_replace('/[^A-Za-z0-9._-]/', '_', $eredetiNev);
    $alapNev = pathinfo($biztonsagosNev, PATHINFO_FILENAME);
    $fajlNev = time() . "_" . mt_rand(1000, 9999) . "_" . $alapNev . ".webp";
    $celPath = $kepekDir . "/" . $fajlNev;

    if (feldolgozottAukcioKepMentese($_FILES[$fileKey]['tmp_name'], $celPath)) {
        return 'ajanlatok/' . $fajlNev;
    }

    $fallbackNev = time() . "_" . mt_rand(1000, 9999) . "_" . $biztonsagosNev;
    move_uploaded_file($_FILES[$fileKey]['tmp_name'], $kepekDir . "/" . $fallbackNev);
    return 'ajanlatok/' . $fallbackNev;
}

function renderCustomCategorySelect($name, $selectedValue, $kategoriak, $placeholder = null) {
    if ($placeholder === null || trim((string)$placeholder) === '') {
        $placeholder = function_exists('st')
            ? st('create.category.placeholder', '-- Valassz kategoriat --')
            : '-- Valassz kategoriat --';
    }
    $selectedValue = (string)$selectedValue;
    $selectedLabel = $placeholder;
    foreach ($kategoriak as $kategoria) {
        if ((string)($kategoria['id'] ?? '') === $selectedValue) {
            $selectedLabel = (string)($kategoria['nev'] ?? $placeholder);
            break;
        }
    }
    ob_start();
    ?>
    <div class="custom-select custom-select--category" data-custom-select>
        <select name="<?php echo htmlspecialchars($name); ?>" required class="custom-select__native" data-custom-select-native>
            <option value=""><?php echo htmlspecialchars($placeholder); ?></option>
            <?php foreach ($kategoriak as $kategoria): ?>
            <option value="<?php echo htmlspecialchars((string)$kategoria['id']); ?>" <?php echo (string)$kategoria['id'] === $selectedValue ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string)$kategoria['nev']); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="custom-select__trigger" data-custom-select-trigger aria-expanded="false">
            <span class="custom-select__value" data-custom-select-value><?php echo htmlspecialchars($selectedLabel); ?></span>
            <span class="custom-select__arrow" aria-hidden="true">⌄</span>
        </button>
        <div class="custom-select__menu" data-custom-select-menu hidden>
            <button type="button" class="custom-select__option<?php echo $selectedValue === '' ? ' is-selected' : ''; ?>" data-custom-select-option data-value="">
                <?php echo htmlspecialchars($placeholder); ?>
            </button>
            <?php foreach ($kategoriak as $kategoria): ?>
            <button type="button" class="custom-select__option<?php echo (string)$kategoria['id'] === $selectedValue ? ' is-selected' : ''; ?>" data-custom-select-option data-value="<?php echo htmlspecialchars((string)$kategoria['id']); ?>">
                <?php echo htmlspecialchars((string)$kategoria['nev']); ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function ajanlatBeallitasokPath() {
    return __DIR__ . '/ajanlat_beallitasok.json';
}

function alapAjanlatBeallitasok() {
    return [
        'licit_max_ora' => 24,
        'fix_max_ora' => 24
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

function oszlopLetezikUjAjanlat($conn, $tablaNev, $oszlopNev) {
    $tablaNev = $conn->real_escape_string($tablaNev);
    $oszlopNev = $conn->real_escape_string($oszlopNev);
    $res = $conn->query("SHOW COLUMNS FROM `$tablaNev` LIKE '$oszlopNev'");
    return $res && $res->num_rows > 0;
}

function liveUrlOszlopVanUjAjanlat($conn) {
    static $cache = null;
    if ($cache === null) {
        $cache = oszlopLetezikUjAjanlat($conn, 'termekek', 'live_url');
    }
    return $cache;
}

function liveAktivOszlopVanUjAjanlat($conn) {
    static $cache = null;
    if ($cache === null) {
        $cache = oszlopLetezikUjAjanlat($conn, 'termekek', 'live_active');
    }
    return $cache;
}

function getLicitIdotartamMpUjAjanlat($conn, $termek) {
    $duration = oszlopLetezikUjAjanlat($conn, 'termekek', 'licit_idotartam_mp') ? intval($termek['licit_idotartam_mp'] ?? 0) : 0;
    if ($duration > 0) return $duration;
    $kezdesTs = !empty($termek['kezdes_idopont']) ? strtotime((string)$termek['kezdes_idopont']) : 0;
    $lejaratTs = !empty($termek['lejarat_idopont']) ? strtotime((string)$termek['lejarat_idopont']) : 0;
    return ($kezdesTs > 0 && $lejaratTs > $kezdesTs) ? max(1, $lejaratTs - $kezdesTs) : 60;
}

function getSingleLicitAllapotUjAjanlat($conn, $termek) {
    $kezdesTs = !empty($termek['kezdes_idopont']) ? strtotime((string)$termek['kezdes_idopont']) : null;
    $lejaratTs = !empty($termek['lejarat_idopont']) ? strtotime((string)$termek['lejarat_idopont']) : null;
    $mostTs = time();
    $allapot = 'live';
    $manualStartRequired = false;
    if ($kezdesTs && $kezdesTs > $mostTs) {
        return ['allapot' => 'soon', 'kezdes_ts' => $kezdesTs, 'manual_start_required' => false];
    }
    if (oszlopLetezikUjAjanlat($conn, 'termekek', 'licit_started_at')) {
        $startedTs = !empty($termek['licit_started_at']) ? strtotime((string)$termek['licit_started_at']) : null;
        if (!$startedTs) {
            if (intval($termek['eladva'] ?? 0) === 1 || ($lejaratTs && $lejaratTs <= $mostTs)) {
                $allapot = 'closed';
            } else {
                $allapot = 'live';
                $manualStartRequired = true;
            }
        } else {
            $lejaratTs = $startedTs + getLicitIdotartamMpUjAjanlat($conn, $termek);
            if ($lejaratTs <= $mostTs || intval($termek['eladva'] ?? 0) === 1) $allapot = 'closed';
        }
    } elseif (($lejaratTs && $lejaratTs <= $mostTs) || intval($termek['eladva'] ?? 0) === 1) {
        $allapot = 'closed';
    }
    return ['allapot' => $allapot, 'kezdes_ts' => $kezdesTs, 'manual_start_required' => $manualStartRequired];
}

function getMultiAukcioAdatUjAjanlat($conn, $termek) {
    if (!tablaLetezik($conn, 'multi_aukciok') || !tablaLetezik($conn, 'multi_aukcio_tetelek')) return null;
    $termekId = intval($termek['id'] ?? 0);
    if ($termekId < 1) return null;
    $multiRes = $conn->query("SELECT * FROM multi_aukciok WHERE termek_id = $termekId LIMIT 1");
    $multi = $multiRes ? $multiRes->fetch_assoc() : null;
    if (!$multi) return null;
    $items = [];
    $itemsRes = $conn->query("SELECT * FROM multi_aukcio_tetelek WHERE multi_aukcio_id = " . intval($multi['id']) . " ORDER BY sorszam ASC, id ASC");
    while ($itemsRes && ($row = $itemsRes->fetch_assoc())) $items[] = $row;
    if (!$items) return null;
    $kezdesTs = !empty($termek['kezdes_idopont']) ? strtotime((string)$termek['kezdes_idopont']) : time();
    $nowTs = time();
    if ($kezdesTs > $nowTs) return ['phase' => 'soon'];
    if (oszlopLetezikUjAjanlat($conn, 'multi_aukciok', 'aktiv_tetel_index') && oszlopLetezikUjAjanlat($conn, 'multi_aukciok', 'aktiv_tetel_start')) {
        $currentIndex = intval($multi['aktiv_tetel_index'] ?? 0);
        if ($currentIndex >= count($items) || intval($termek['eladva'] ?? 0) === 1) return ['phase' => 'closed'];
        $activeStartRaw = trim((string)($multi['aktiv_tetel_start'] ?? ''));
        if ($activeStartRaw === '' || $activeStartRaw === '0000-00-00 00:00:00') return ['phase' => 'ready'];
        $activeStartTs = strtotime($activeStartRaw);
        $activeItem = $items[$currentIndex];
        return ['phase' => (($activeStartTs + max(1, intval($activeItem['idotartam_mp'] ?? 60))) > $nowTs ? 'live' : 'closed')];
    }
    $gap = max(0, intval($multi['szunet_mp'] ?? 15));
    $cursor = $kezdesTs;
    foreach ($items as $index => $item) {
        $itemEnd = $cursor + max(1, intval($item['idotartam_mp'] ?? 60));
        if ($nowTs < $itemEnd) return ['phase' => 'live'];
        $nextStart = $itemEnd + $gap;
        if ($index < count($items) - 1 && $nowTs < $nextStart) return ['phase' => 'break'];
        $cursor = $nextStart;
    }
    return ['phase' => 'closed'];
}

$ajanlatBeallitasok = getAjanlatBeallitasok();
$licitMaxOra = max(1, intval($ajanlatBeallitasok['licit_max_ora'] ?? 24));
$fixMaxOra = max(1, intval($ajanlatBeallitasok['fix_max_ora'] ?? 24));
$eredetiArOszlopVan = oszlopLetezikUjAjanlat($conn, 'termekek', 'eredeti_ar');
$varosOszlopVan = oszlopLetezikUjAjanlat($conn, 'termekek', 'varos');
$licitStartedOszlopVan = oszlopLetezikUjAjanlat($conn, 'termekek', 'licit_started_at');
$licitIdotartamOszlopVan = oszlopLetezikUjAjanlat($conn, 'termekek', 'licit_idotartam_mp');
$kuponReszletekOszlopVan = oszlopLetezikUjAjanlat($conn, 'termekek', 'kupon_reszletek');
$kuponBevaltasVegeOszlopVan = oszlopLetezikUjAjanlat($conn, 'termekek', 'kupon_bevaltas_vege');
$kuponKategoriaIdOszlopVan = oszlopLetezikUjAjanlat($conn, 'termekek', 'kupon_kategoria_id');
$multiAktivTetelIndexOszlopVan = oszlopLetezikUjAjanlat($conn, 'multi_aukciok', 'aktiv_tetel_index');
$multiAktivTetelStartOszlopVan = oszlopLetezikUjAjanlat($conn, 'multi_aukciok', 'aktiv_tetel_start');

$ujrakozlesForras = null;
$ujrakozlesMultiTetelek = [];
$ujrakozlesTipus = 'licit';
$singleDefaults = [
    'nev' => '',
    'leiras' => '',
    'kategoria' => '',
    'video_url' => '',
    'live_url' => '',
    'ar' => '',
    'lepcso' => '',
    'szallitasi_mod' => 'ingyenes',
    'szallitasi_dij' => '',
    'kezdet' => date('Y-m-d\TH:i'),
    'lejarat' => '',
    'kep_url' => 'nincs_kep.jpg'
];
$multiDefaults = [
    'fo_nev' => '',
    'kategoria' => '',
    'video_url' => '',
    'live_url' => '',
    'kezdet' => date('Y-m-d\TH:i'),
    'kep_url' => 'nincs_kep.jpg'
];
$fixDefaults = [
    'nev' => '',
    'leiras' => '',
    'kategoria' => '',
    'video_url' => '',
    'live_url' => '',
    'fix_ar' => '',
    'eredeti_ar' => '',
    'varos' => '',
    'darabszam' => 1,
    'szallitasi_mod' => 'ingyenes',
    'szallitasi_dij' => '',
    'kezdet' => date('Y-m-d\TH:i'),
    'lejarat' => '',
    'kep_url' => 'nincs_kep.jpg'
];
$kuponDefaults = [
    'nev' => '',
    'leiras' => '',
    'reszletek' => '',
    'kategoria' => '',
    'video_url' => '',
    'live_url' => '',
    'fix_ar' => '',
    'eredeti_ar' => '',
    'varos' => '',
    'darabszam' => 1,
    'bevaltas_vege' => date('Y-m-d'),
    'kezdet' => date('Y-m-d\TH:i'),
    'lejarat' => '',
    'kep_url' => 'nincs_kep.jpg'
];

if (!isset($_SESSION['user_id'])) {
    die(html_entity_decode("Jelentkezz be a felt&ouml;lt&eacute;shez! <a href='bejelentezes.php'>Bel&eacute;p&eacute;s</a>", ENT_QUOTES, 'UTF-8'));
}

$aktivPiacKodUjAjanlat = function_exists('shobidMarketCurrentCode')
    ? shobidMarketCurrentCode()
    : $pageLocaleCode;
$felhasznaloPiacKodUjAjanlat = function_exists('shobidMarketCodeForUser')
    ? shobidMarketCodeForUser($conn, intval($_SESSION['user_id']), $aktivPiacKodUjAjanlat)
    : $aktivPiacKodUjAjanlat;
$orszagZarAktivUjAjanlat = strtolower((string)$felhasznaloPiacKodUjAjanlat) !== strtolower((string)$aktivPiacKodUjAjanlat);
if ($orszagZarAktivUjAjanlat) {
    die(html_entity_decode("Mas orszag piacterén csak nezni tudsz. Uj ajanlatot csak a sajat orszagodban tudsz letrehozni.", ENT_QUOTES, 'UTF-8'));
}
$aktivPenznemKodUjAjanlat = function_exists('shobidStripeCurrencyByLocale')
    ? shobidStripeCurrencyByLocale($aktivPiacKodUjAjanlat)
    : 'huf';
$aktivPenznemJelUjAjanlat = function_exists('shobidStripeCurrencySuffix')
    ? shobidStripeCurrencySuffix($aktivPenznemKodUjAjanlat)
    : strtoupper((string)$aktivPenznemKodUjAjanlat);

$kategoriak = [];
$kategoriaRes = $conn->query("SELECT * FROM kategoriak ORDER BY nev ASC");
if ($kategoriaRes) {
    while ($k = $kategoriaRes->fetch_assoc()) {
        if (function_exists('shobidI18nCategoryName')) {
            $k['nev'] = shobidI18nCategoryName(intval($k['id'] ?? 0), (string)($k['nev'] ?? ''), $pageLocaleCode);
        }
        $kategoriak[] = $k;
    }
}

$kuponKategoriak = [];
if (tablaLetezik($conn, 'kupon_kategoriak')) {
    $kuponKategoriaOrder = oszlopLetezikUjAjanlat($conn, 'kupon_kategoriak', 'sorrend')
        ? "COALESCE(sorrend, 9999) ASC, nev ASC"
        : "nev ASC";
    $kuponKategoriaRes = $conn->query("SELECT * FROM kupon_kategoriak ORDER BY $kuponKategoriaOrder");
    if ($kuponKategoriaRes) {
        while ($k = $kuponKategoriaRes->fetch_assoc()) {
            $kuponKategoriak[] = $k;
        }
    }
}

$kategoriakEloDb = [];
$mostMegyDb = 0;
$eloDb = 0;
$kozelgoDb = 0;
$kuponDb = 0;
$statMostTs = time();
$statRes = $conn->query("SELECT * FROM termekek WHERE eladva = 0 AND " . shobidMarketTermekWhere($conn, '', $aktivPiacKodUjAjanlat));
if ($statRes) {
    while ($statTermek = $statRes->fetch_assoc()) {
        $tenylegesenElo = liveAktivOszlopVanUjAjanlat($conn) && intval($statTermek['live_active'] ?? 0) === 1;
        $tipus = (string)($statTermek['ajanlat_tipus'] ?? 'licit');
        if ($tipus === 'multi') {
            $multiStat = getMultiAukcioAdatUjAjanlat($conn, $statTermek);
            $phase = (string)($multiStat['phase'] ?? 'closed');
            if ($phase === 'live' || $phase === 'ready') {
                $mostMegyDb++;
                if ($tenylegesenElo) $eloDb++;
                $katId = intval($statTermek['kategoria_id'] ?? 0);
                if ($katId > 0) $kategoriakEloDb[$katId] = intval($kategoriakEloDb[$katId] ?? 0) + 1;
            } elseif ($phase === 'soon') {
                $kozelgoDb++;
            }
            continue;
        }
        if ($tipus === 'fix') {
            $kezdesTs = !empty($statTermek['kezdes_idopont']) ? strtotime((string)$statTermek['kezdes_idopont']) : 0;
            $lejaratTs = !empty($statTermek['lejarat_idopont']) ? strtotime((string)$statTermek['lejarat_idopont']) : 0;
            if ($kezdesTs > $statMostTs && $kezdesTs <= ($statMostTs + 86400)) {
                $kozelgoDb++;
            } elseif (intval($statTermek['darabszam'] ?? 0) > 0 && (!$lejaratTs || $lejaratTs > $statMostTs) && (!$kezdesTs || $kezdesTs <= $statMostTs)) {
                $mostMegyDb++;
                if ($tenylegesenElo) $eloDb++;
                $katId = intval($statTermek['kategoria_id'] ?? 0);
                if ($katId > 0) $kategoriakEloDb[$katId] = intval($kategoriakEloDb[$katId] ?? 0) + 1;
            }
            continue;
        }
        if ($tipus === 'kupon') {
            $kezdesTs = !empty($statTermek['kezdes_idopont']) ? strtotime((string)$statTermek['kezdes_idopont']) : 0;
            $lejaratTs = !empty($statTermek['lejarat_idopont']) ? strtotime((string)$statTermek['lejarat_idopont']) : 0;
            if ($kezdesTs > $statMostTs && $kezdesTs <= ($statMostTs + 86400)) {
                $kozelgoDb++;
            } elseif (intval($statTermek['darabszam'] ?? 0) > 0 && (!$lejaratTs || $lejaratTs > $statMostTs) && (!$kezdesTs || $kezdesTs <= $statMostTs)) {
                $mostMegyDb++;
                $kuponDb++;
            }
            continue;
        }
        $singleStat = getSingleLicitAllapotUjAjanlat($conn, $statTermek);
        if (($singleStat['allapot'] ?? '') === 'soon' && intval($singleStat['kezdes_ts'] ?? 0) <= ($statMostTs + 86400)) {
            $kozelgoDb++;
        } elseif (($singleStat['allapot'] ?? '') === 'live') {
            $mostMegyDb++;
            if ($tenylegesenElo) $eloDb++;
            $katId = intval($statTermek['kategoria_id'] ?? 0);
            if ($katId > 0) $kategoriakEloDb[$katId] = intval($kategoriakEloDb[$katId] ?? 0) + 1;
        }
    }
}

$sidebarUserRes = $conn->query("SELECT becenev, profilkep FROM felhasznalok WHERE id = " . intval($_SESSION['user_id']));
$sidebarUser = $sidebarUserRes ? $sidebarUserRes->fetch_assoc() : null;
$profilReszletesRes = $conn->query("SELECT teljes_nev, lakcim, telefonszam FROM felhasznalok WHERE id = " . intval($_SESSION['user_id']));
$profilReszletes = $profilReszletesRes ? $profilReszletesRes->fetch_assoc() : null;
$profilHianyos = !$profilReszletes || empty(trim((string)($profilReszletes['teljes_nev'] ?? ''))) || empty(trim((string)($profilReszletes['lakcim'] ?? ''))) || empty(trim((string)($profilReszletes['telefonszam'] ?? '')));
$fizetesiModHianyzik = !shobidStripeFelhasznaloFizetesKesz($conn, intval($_SESSION['user_id']));

if ($profilHianyos || $fizetesiModHianyzik) {
    die(html_entity_decode("Licit&aacute;l&aacute;shoz &eacute;s aukci&oacute; felt&ouml;lt&eacute;shez el&#337;bb t&ouml;ltsd ki a teljes adataidat, majd ments bankk&aacute;rty&aacute;t a <a href='profil.php?complete_profile=1#bankkartya'>profil oldalon</a>.", ENT_QUOTES, 'UTF-8'));
}

if (isset($_GET['ujrakozlom'])) {
    $ujrakozlesId = intval($_GET['ujrakozlom']);
    if ($ujrakozlesId > 0) {
        $ujrakozlesSql = "SELECT * FROM termekek WHERE id = $ujrakozlesId AND feltolto_id = " . intval($_SESSION['user_id']) . " AND " . shobidMarketTermekWhere($conn, '', $aktivPiacKodUjAjanlat) . " LIMIT 1";
        $ujrakozlesRes = $conn->query($ujrakozlesSql);
        $ujrakozlesForras = $ujrakozlesRes ? $ujrakozlesRes->fetch_assoc() : null;
        if ($ujrakozlesForras) {
            $lejart = intval($ujrakozlesForras['eladva'] ?? 0) === 1 || (!empty($ujrakozlesForras['lejarat_idopont']) && strtotime($ujrakozlesForras['lejarat_idopont']) <= time());
            if (!$lejart) {
                $ujrakozlesForras = null;
            }
        }

        if ($ujrakozlesForras) {
            $ujrakozlesTipus = (string)($ujrakozlesForras['ajanlat_tipus'] ?? 'licit');
            if ($ujrakozlesTipus === 'multi' && tablaLetezik($conn, 'multi_aukciok') && tablaLetezik($conn, 'multi_aukcio_tetelek')) {
                $multiRes = $conn->query("SELECT * FROM multi_aukciok WHERE termek_id = " . intval($ujrakozlesForras['id']) . " LIMIT 1");
                $multi = $multiRes ? $multiRes->fetch_assoc() : null;
                if ($multi) {
                    $tetelekRes = $conn->query("SELECT * FROM multi_aukcio_tetelek WHERE multi_aukcio_id = " . intval($multi['id']) . " ORDER BY sorszam ASC, id ASC");
                    while ($tetelekRes && ($tetel = $tetelekRes->fetch_assoc())) {
                        $ujrakozlesMultiTetelek[] = $tetel;
                    }
                }
            }

            $singleDefaults = [
                'nev' => (string)($ujrakozlesForras['nev'] ?? ''),
                'leiras' => (string)($ujrakozlesForras['leiras'] ?? ''),
                'kategoria' => (string)intval($ujrakozlesForras['kategoria_id'] ?? 0),
                'video_url' => (string)($ujrakozlesForras['video_url'] ?? ''),
                'live_url' => (string)($ujrakozlesForras['live_url'] ?? ''),
                'ar' => intval($ujrakozlesForras['aktualis_ar'] ?? 1000),
                'lepcso' => intval($ujrakozlesForras['licit_lepcso'] ?? 500),
                'szallitasi_mod' => (string)($ujrakozlesForras['szallitasi_mod'] ?? 'ingyenes'),
                'szallitasi_dij' => intval($ujrakozlesForras['szallitasi_dij'] ?? 0) > 0 ? intval($ujrakozlesForras['szallitasi_dij']) : '',
                'kezdet' => date('Y-m-d\TH:i'),
                'lejarat' => '',
                'kep_url' => (string)($ujrakozlesForras['kep_url'] ?? 'nincs_kep.jpg')
            ];

            $multiDefaults = [
                'fo_nev' => (string)($ujrakozlesForras['nev'] ?? ''),
                'kategoria' => (string)intval($ujrakozlesForras['kategoria_id'] ?? 0),
                'video_url' => (string)($ujrakozlesForras['video_url'] ?? ''),
                'live_url' => (string)($ujrakozlesForras['live_url'] ?? ''),
                'kezdet' => date('Y-m-d\TH:i'),
                'kep_url' => (string)($ujrakozlesForras['kep_url'] ?? 'nincs_kep.jpg')
            ];

            $fixDefaults = [
                'nev' => (string)($ujrakozlesForras['nev'] ?? ''),
                'leiras' => (string)($ujrakozlesForras['leiras'] ?? ''),
                'kategoria' => (string)intval($ujrakozlesForras['kategoria_id'] ?? 0),
                'video_url' => (string)($ujrakozlesForras['video_url'] ?? ''),
                'live_url' => (string)($ujrakozlesForras['live_url'] ?? ''),
                'fix_ar' => intval($ujrakozlesForras['fix_ar'] ?? $ujrakozlesForras['aktualis_ar'] ?? 1000),
                'eredeti_ar' => intval($ujrakozlesForras['eredeti_ar'] ?? 0),
                'varos' => (string)($ujrakozlesForras['varos'] ?? ''),
                'darabszam' => max(1, intval($ujrakozlesForras['darabszam'] ?? 1)),
                'szallitasi_mod' => (string)($ujrakozlesForras['szallitasi_mod'] ?? 'ingyenes'),
                'szallitasi_dij' => intval($ujrakozlesForras['szallitasi_dij'] ?? 0) > 0 ? intval($ujrakozlesForras['szallitasi_dij']) : '',
                'kezdet' => date('Y-m-d\TH:i'),
                'lejarat' => '',
                'kep_url' => (string)($ujrakozlesForras['kep_url'] ?? 'nincs_kep.jpg')
            ];
        }
    }
}

$durationOptions = [
    30 => '30 m&aacute;sodperc',
    60 => '1 perc',
    120 => '2 perc',
    300 => '5 perc'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !authValidateCsrfFromRequest()) {
    die('CSRF token hiba. Frissitsd az oldalt, es probald ujra.');
}

if (isset($_POST['mentes'])) {
	$nev = trim($_POST['nev'] ?? '');
	if (mb_strlen($nev, 'UTF-8') > 25) {
		die('A termék neve maximum 25 karakter lehet.');
	}
    $leiras = trim($_POST['leiras'] ?? '');
	if (mb_strlen($leiras, 'UTF-8') > 250) {
		die('A leírás maximum 250 karakter lehet.');
	}

    $ar = intval($_POST['ar'] ?? 0);
    $lepcso = intval($_POST['lepcso'] ?? 500);
    $szallitasi_mod = $_POST['szallitasi_mod'] ?? '';
    $szallitasi_dij = intval($_POST['szallitasi_dij'] ?? 0);
    $kat = intval($_POST['kategoria'] ?? 0);
    $kezdet = $_POST['kezdet'] ?? '';
    $lejarat = $_POST['lejarat'] ?? '';
    $v_url = trim($_POST['video_url'] ?? '');
    $live_url_raw = trim($_POST['live_url'] ?? '');
    $user_id = intval($_SESSION['user_id']);

    $nev = $conn->real_escape_string($nev);
    $leiras = $conn->real_escape_string($leiras);
    $v_url = $conn->real_escape_string($v_url);
    $live_url = $conn->real_escape_string($live_url_raw);

    if ($ar < 1) $ar = 1000;
    if ($lepcso < 1) $lepcso = 500;

    if ($leiras === '') die(html_entity_decode("A le&iacute;r&aacute;s megad&aacute;sa k&ouml;telez&#337;.", ENT_QUOTES, 'UTF-8'));
    if ($v_url === '') die(html_entity_decode("A vide&oacute; link megad&aacute;sa k&ouml;telez&#337;.", ENT_QUOTES, 'UTF-8'));
    if ($szallitasi_mod !== 'ingyenes' && $szallitasi_mod !== 'egyedi') die(html_entity_decode("A sz&aacute;ll&iacute;t&aacute;si m&oacute;d kiv&aacute;laszt&aacute;sa k&ouml;telez&#337;.", ENT_QUOTES, 'UTF-8'));
    if ($szallitasi_mod === 'egyedi' && $szallitasi_dij < 1) die(html_entity_decode("Add meg a sz&aacute;ll&iacute;t&aacute;si d&iacute;jat.", ENT_QUOTES, 'UTF-8'));
    if (empty($kezdet) || empty($lejarat)) die(html_entity_decode("Adj meg kezd&eacute;si &eacute;s lej&aacute;rati id&#337;pontot.", ENT_QUOTES, 'UTF-8'));
    if (strtotime($lejarat) <= strtotime($kezdet)) die(html_entity_decode("A lej&aacute;rat k&eacute;s&#337;bbi kell legyen, mint a kezd&eacute;s.", ENT_QUOTES, 'UTF-8'));
    if ((strtotime($lejarat) - strtotime($kezdet)) > ($licitMaxOra * 3600)) die(html_entity_decode("A maximum id&#337; " . intval($licitMaxOra) . " &oacute;ra lehet.", ENT_QUOTES, 'UTF-8'));

    $meglevoKep = trim((string)($_POST['meglevo_kep'] ?? ''));
    $kep = mentsKep('kep', $meglevoKep !== '' ? $meglevoKep : 'nincs_kep.jpg');
    $licitIdotartamMp = max(1, strtotime($lejarat) - strtotime($kezdet));
    $licitExtraOszlopSql = $licitIdotartamOszlopVan ? ", licit_idotartam_mp" : "";
    $licitExtraErtekSql = $licitIdotartamOszlopVan ? ", $licitIdotartamMp" : "";
    if ($licitStartedOszlopVan) {
        $licitExtraOszlopSql .= ", licit_started_at";
        $licitExtraErtekSql .= ", NULL";
    }
    $liveExtraOszlopSql = liveUrlOszlopVanUjAjanlat($conn) ? ", live_url" : "";
    $liveExtraErtekSql = liveUrlOszlopVanUjAjanlat($conn) ? ", '$live_url'" : "";
    if (liveAktivOszlopVanUjAjanlat($conn)) {
        $liveExtraOszlopSql .= ", live_active";
        $liveExtraErtekSql .= ", 0";
    }
    $sql = "INSERT INTO termekek
        (nev, leiras, aktualis_ar, licit_lepcso, szallitasi_mod, szallitasi_dij, kategoria_id, kezdes_idopont, lejarat_idopont, kep_url, video_url, feltolto_id, eladva, ajanlat_tipus, fix_ar, orszag_kod, penznem$licitExtraOszlopSql$liveExtraOszlopSql)
        VALUES
        ('$nev', '$leiras', $ar, $lepcso, '$szallitasi_mod', " . ($szallitasi_mod === 'ingyenes' ? "0" : $szallitasi_dij) . ", $kat, '$kezdet', '$lejarat', '$kep', '$v_url', $user_id, 0, 'licit', 0, " . shobidMarketSqlValue($conn, $aktivPiacKodUjAjanlat) . ", '" . $conn->real_escape_string($aktivPenznemKodUjAjanlat) . "'$licitExtraErtekSql$liveExtraErtekSql)";

    if ($conn->query($sql)) {
        header("Location: index.php");
        exit;
    }
    echo html_entity_decode("Hiba t&ouml;rt&eacute;nt a ment&eacute;s sor&aacute;n: ", ENT_QUOTES, 'UTF-8') . $conn->error;
}

if (isset($_POST['mentes_multi'])) {
    if (!tablaLetezik($conn, 'multi_aukciok') || !tablaLetezik($conn, 'multi_aukcio_tetelek')) {
        die(html_entity_decode("A LICIT SHOP-hoz hi&aacute;nyoznak az adatb&aacute;zis t&aacute;bl&aacute;k.", ENT_QUOTES, 'UTF-8'));
    }

    $user_id = intval($_SESSION['user_id']);
	$foNevRaw = trim($_POST['multi_fo_nev'] ?? '');
	if (mb_strlen($foNevRaw, 'UTF-8') > 25) {
		die('A termékek megnevezése maximum 25 karakter lehet.');
	}
    $foNev = $conn->real_escape_string($foNevRaw);
    $vUrlRaw = trim($_POST['multi_video_url'] ?? '');
    $multiLiveUrlRaw = trim($_POST['multi_live_url'] ?? '');
    $vUrl = $conn->real_escape_string($vUrlRaw);
    $multiLiveUrl = $conn->real_escape_string($multiLiveUrlRaw);
    $kezdet = $_POST['multi_kezdet'] ?? '';
    $multiKat = intval($_POST['multi_kategoria'] ?? 0);
    $gapMp = 15;

    if ($foNevRaw === '') die(html_entity_decode("A f&#337;n&eacute;v megad&aacute;sa k&ouml;telez&#337;.", ENT_QUOTES, 'UTF-8'));
    if ($vUrlRaw === '') die(html_entity_decode("A vide&oacute; link megad&aacute;sa k&ouml;telez&#337;.", ENT_QUOTES, 'UTF-8'));
    if ($kezdet === '') die(html_entity_decode("A kezd&eacute;si id&#337;pont megad&aacute;sa k&ouml;telez&#337;.", ENT_QUOTES, 'UTF-8'));
    if ($multiKat < 1) die(html_entity_decode("V&aacute;lassz kateg&oacute;ri&aacute;t a LICIT SHOP-hoz.", ENT_QUOTES, 'UTF-8'));

    $items = [];
    for ($i = 0; $i < 25; $i++) {
        $row = $_POST['multi_items'][$i] ?? [];
        $nevRaw = trim($row['nev'] ?? '');
        if ($nevRaw === '') {
            continue;
        }
		if (mb_strlen($nevRaw, 'UTF-8') > 25) {
			die('A tétel neve maximum 25 karakter lehet.');
		}
        if (mb_strlen($leirasRaw, 'UTF-8') > 250) {
		die('A tétel leírása maximum 250 karakter lehet.');
		}
        $szallitasMod = $row['szallitasi_mod'] ?? '';
        $szallitasDij = intval($row['szallitasi_dij'] ?? 0);
        $ar = intval($row['ar'] ?? 0);
        $lepcso = intval($row['lepcso'] ?? 0);
        $idotartam = intval($row['idotartam_mp'] ?? 0);
        $pozicio = intval($row['pozicio'] ?? ($i + 1));

        if ($leirasRaw === '') die(html_entity_decode("Minden multi t&eacute;teln&eacute;l meg kell adni a le&iacute;r&aacute;st.", ENT_QUOTES, 'UTF-8'));
        if ($szallitasMod !== 'ingyenes' && $szallitasMod !== 'egyedi') die(html_entity_decode("Minden multi t&eacute;teln&eacute;l v&aacute;lassz sz&aacute;ll&iacute;t&aacute;st.", ENT_QUOTES, 'UTF-8'));
        if ($szallitasMod === 'egyedi' && $szallitasDij < 1) die(html_entity_decode("Az egyedi sz&aacute;ll&iacute;t&aacute;si d&iacute;jat minden t&eacute;teln&eacute;l add meg.", ENT_QUOTES, 'UTF-8'));
        if ($ar < 1) die(html_entity_decode("Az indul&oacute; licit nem lehet 0.", ENT_QUOTES, 'UTF-8'));
        if ($lepcso < 1) die(html_entity_decode("A licitl&eacute;pcs&#337; nem lehet 0.", ENT_QUOTES, 'UTF-8'));
        if (!isset($durationOptions[$idotartam])) die(html_entity_decode("V&aacute;lassz &eacute;rv&eacute;nyes id&#337;tartamot.", ENT_QUOTES, 'UTF-8'));

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

    if (!$items) {
        die(html_entity_decode("Adj meg legal&aacute;bb egy multi t&eacute;telt.", ENT_QUOTES, 'UTF-8'));
    }

    usort($items, function ($a, $b) {
        return $a['pozicio'] <=> $b['pozicio'];
    });

    $meglevoMultiKep = trim((string)($_POST['multi_meglevo_kep'] ?? ''));
    $foKep = mentsKep('multi_kep', $meglevoMultiKep !== '' ? $meglevoMultiKep : 'nincs_kep.jpg');
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

    $multiLiveExtraOszlopSql = liveUrlOszlopVanUjAjanlat($conn) ? ", live_url" : "";
    $multiLiveExtraErtekSql = liveUrlOszlopVanUjAjanlat($conn) ? ", '$multiLiveUrl'" : "";
    if (liveAktivOszlopVanUjAjanlat($conn)) {
        $multiLiveExtraOszlopSql .= ", live_active";
        $multiLiveExtraErtekSql .= ", 0";
    }
    $sql = "INSERT INTO termekek
        (nev, leiras, aktualis_ar, licit_lepcso, szallitasi_mod, szallitasi_dij, kategoria_id, kezdes_idopont, lejarat_idopont, kep_url, video_url, feltolto_id, eladva, ajanlat_tipus, fix_ar, orszag_kod, penznem$multiLiveExtraOszlopSql)
        VALUES
        ('$foNev', '', " . intval($elsoTetel['ar']) . ", " . intval($elsoTetel['lepcso']) . ", '" . $elsoTetel['szallitas_mod'] . "', " . intval($elsoTetel['szallitas_dij']) . ", $multiKat, '" . $conn->real_escape_string($kezdet) . "', '$lejarat', '$foKep', '$vUrl', $user_id, 0, 'multi', 0, " . shobidMarketSqlValue($conn, $aktivPiacKodUjAjanlat) . ", '" . $conn->real_escape_string($aktivPenznemKodUjAjanlat) . "'$multiLiveExtraErtekSql)";

    if (!$conn->query($sql)) {
        die(html_entity_decode("Hiba t&ouml;rt&eacute;nt a multi aukci&oacute; ment&eacute;se sor&aacute;n: ", ENT_QUOTES, 'UTF-8') . $conn->error);
    }

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
        $conn->query("INSERT INTO multi_aukcio_tetelek
            (multi_aukcio_id, sorszam, nev, leiras, kategoria_id, szallitasi_mod, szallitasi_dij, aktualis_ar, licit_lepcso, idotartam_mp, legmagasabb_licit_felhasznalo, eladva)
            VALUES
            ($multiId, $sorszam, '{$item['nev']}', '{$item['leiras']}', {$item['kategoria']}, '{$item['szallitas_mod']}', {$item['szallitas_dij']}, {$item['ar']}, {$item['lepcso']}, {$item['idotartam_mp']}, '', 0)");
    }

    header("Location: index.php");
    exit;
}

if (isset($_POST['mentes_fix'])) {
	$nev = trim($_POST['fix_nev'] ?? '');
	if (mb_strlen($nev, 'UTF-8') > 25) {
		die('A termék neve maximum 25 karakter lehet.');
	}
    $leiras = trim($_POST['fix_leiras'] ?? '');
	if (mb_strlen($leiras, 'UTF-8') > 250) {
		die('A leírás maximum 250 karakter lehet.');
	}
    $fixAr = intval($_POST['fix_ar'] ?? 0);
    $eredetiAr = intval($_POST['fix_eredeti_ar'] ?? 0);
    $varos = trim($_POST['fix_varos'] ?? '');
    $darabszam = intval($_POST['fix_darabszam'] ?? 0);
    $szallitasi_mod = $_POST['fix_szallitasi_mod'] ?? '';
    $szallitasi_dij = intval($_POST['fix_szallitasi_dij'] ?? 0);
    $kat = intval($_POST['fix_kategoria'] ?? 0);
    $kezdet = $_POST['fix_kezdet'] ?? '';
    $lejarat = $_POST['fix_lejarat'] ?? '';
    $v_url = trim($_POST['fix_video_url'] ?? '');
    $fix_live_url_raw = trim($_POST['fix_live_url'] ?? '');
    $user_id = intval($_SESSION['user_id']);

    $nev = $conn->real_escape_string($nev);
    $leiras = $conn->real_escape_string($leiras);
    $v_url = $conn->real_escape_string($v_url);
    $fix_live_url = $conn->real_escape_string($fix_live_url_raw);
    $varos = $conn->real_escape_string($varos);

    if ($nev === '') die(html_entity_decode("A n&eacute;v megad&aacute;sa k&ouml;telez&#337;.", ENT_QUOTES, 'UTF-8'));
    if ($leiras === '') die(html_entity_decode("A le&iacute;r&aacute;s megad&aacute;sa k&ouml;telez&#337;.", ENT_QUOTES, 'UTF-8'));
    if ($fixAr < 1) die(html_entity_decode("A fix &aacute;r megad&aacute;sa k&ouml;telez&#337;.", ENT_QUOTES, 'UTF-8'));
    if ($darabszam < 1) die(html_entity_decode("A darabsz&aacute;m megad&aacute;sa k&ouml;telez&#337;.", ENT_QUOTES, 'UTF-8'));
    if ($v_url === '') die(html_entity_decode("A vide&oacute; link megad&aacute;sa k&ouml;telez&#337;.", ENT_QUOTES, 'UTF-8'));
    if ($kat < 1) die(html_entity_decode("V&aacute;lassz kateg&oacute;ri&aacute;t.", ENT_QUOTES, 'UTF-8'));
    if ($kezdet === '') die(html_entity_decode("Add meg a kezd&eacute;si id&#337;pontot.", ENT_QUOTES, 'UTF-8'));
    if ($lejarat === '') die(html_entity_decode("Add meg a lej&aacute;rati id&#337;pontot.", ENT_QUOTES, 'UTF-8'));
    if (strtotime($lejarat) <= strtotime($kezdet)) die(html_entity_decode("A lej&aacute;rat k&eacute;s&#337;bbi kell legyen, mint a kezd&eacute;s.", ENT_QUOTES, 'UTF-8'));
    if ((strtotime($lejarat) - strtotime($kezdet)) > ($fixMaxOra * 3600)) die(html_entity_decode("A maximum id&#337; " . intval($fixMaxOra) . " &oacute;ra lehet.", ENT_QUOTES, 'UTF-8'));
    if ($szallitasi_mod !== 'ingyenes' && $szallitasi_mod !== 'egyedi') die(html_entity_decode("A sz&aacute;ll&iacute;t&aacute;si m&oacute;d kiv&aacute;laszt&aacute;sa k&ouml;telez&#337;.", ENT_QUOTES, 'UTF-8'));
    if ($szallitasi_mod === 'egyedi' && $szallitasi_dij < 1) die(html_entity_decode("Add meg a sz&aacute;ll&iacute;t&aacute;si d&iacute;jat.", ENT_QUOTES, 'UTF-8'));

    $meglevoFixKep = trim((string)($_POST['fix_meglevo_kep'] ?? ''));
    $kep = mentsKep('fix_kep', $meglevoFixKep !== '' ? $meglevoFixKep : 'nincs_kep.jpg');
    $eredetiArOszlopSql = $eredetiArOszlopVan ? ", eredeti_ar" : "";
    $eredetiArErtekSql = $eredetiArOszlopVan ? ", " . max(0, $eredetiAr) : "";
    $varosOszlopSql = $varosOszlopVan ? ", varos" : "";
    $varosErtekSql = $varosOszlopVan ? ", '$varos'" : "";
    $fixLiveExtraOszlopSql = liveUrlOszlopVanUjAjanlat($conn) ? ", live_url" : "";
    $fixLiveExtraErtekSql = liveUrlOszlopVanUjAjanlat($conn) ? ", '$fix_live_url'" : "";
    if (liveAktivOszlopVanUjAjanlat($conn)) {
        $fixLiveExtraOszlopSql .= ", live_active";
        $fixLiveExtraErtekSql .= ", 0";
    }
    $sql = "INSERT INTO termekek
        (nev, leiras, aktualis_ar, licit_lepcso, szallitasi_mod, szallitasi_dij, kategoria_id, kezdes_idopont, lejarat_idopont, kep_url, video_url, feltolto_id, eladva, ajanlat_tipus, fix_ar, darabszam, orszag_kod, penznem$eredetiArOszlopSql$varosOszlopSql$fixLiveExtraOszlopSql)
        VALUES
        ('$nev', '$leiras', $fixAr, 0, '$szallitasi_mod', " . ($szallitasi_mod === 'ingyenes' ? "0" : $szallitasi_dij) . ", $kat, '$kezdet', '$lejarat', '$kep', '$v_url', $user_id, 0, 'fix', $fixAr, $darabszam, " . shobidMarketSqlValue($conn, $aktivPiacKodUjAjanlat) . ", '" . $conn->real_escape_string($aktivPenznemKodUjAjanlat) . "'$eredetiArErtekSql$varosErtekSql$fixLiveExtraErtekSql)";

    if ($conn->query($sql)) {
        header("Location: index.php");
        exit;
    }
    echo html_entity_decode("Hiba t&ouml;rt&eacute;nt a ment&eacute;s sor&aacute;n: ", ENT_QUOTES, 'UTF-8') . $conn->error;
}

if (isset($_POST['mentes_kupon'])) {
    $nev = trim($_POST['kupon_nev'] ?? '');
    $leiras = trim($_POST['kupon_leiras'] ?? '');
    $reszletek = trim($_POST['kupon_reszletek'] ?? '');
    $fixAr = intval($_POST['kupon_ar'] ?? 0);
    $eredetiAr = intval($_POST['kupon_eredeti_ar'] ?? 0);
    $varos = trim($_POST['kupon_varos'] ?? '');
    $darabszam = intval($_POST['kupon_darabszam'] ?? 0);
    $kat = intval($_POST['kupon_kategoria'] ?? 0);
    $kezdet = $_POST['kupon_kezdet'] ?? '';
    $lejarat = $_POST['kupon_lejarat'] ?? '';
    $bevaltasVege = trim($_POST['kupon_bevaltas_vege'] ?? '');
    $v_url = trim($_POST['kupon_video_url'] ?? '');
    $live_url_raw = trim($_POST['kupon_live_url'] ?? '');
    $user_id = intval($_SESSION['user_id']);

    $nev = $conn->real_escape_string($nev);
    $leiras = $conn->real_escape_string($leiras);
    $reszletek = $conn->real_escape_string($reszletek);
    $v_url = $conn->real_escape_string($v_url);
    $live_url = $conn->real_escape_string($live_url_raw);
    $varos = $conn->real_escape_string($varos);

    if ($nev === '') die('A kupon neve kotelezo.');
    if ($leiras === '') die('A leiras kotelezo.');
    if ($fixAr < 1) die('A kupon ar kotelezo.');
    if ($darabszam < 1) die('A kupon darabszam kotelezo.');
    if ($kat < 1) die('Valassz kupon kategoriat.');
    if ($v_url === '') die('A video link kotelezo.');
    if ($varos === '') die('A varos megadasa kotelezo.');
    if ($kezdet === '' || $lejarat === '') die('Add meg a kupon vasarlasi idointervallumot.');
    if (strtotime($lejarat) <= strtotime($kezdet)) die('A kupon vasarlasi vege legyen kesobbi mint a kezdete.');
    if ($bevaltasVege === '' || strtotime($bevaltasVege) === false) die('Add meg a kupon bevaltas veget (nap).');
    if ((strtotime($lejarat) - strtotime($kezdet)) > ($fixMaxOra * 3600)) die('A kupon vasarlas maximum ideje ' . intval($fixMaxOra) . ' ora lehet.');

    $meglevoKuponKep = trim((string)($_POST['kupon_meglevo_kep'] ?? ''));
    $kep = mentsKep('kupon_kep', $meglevoKuponKep !== '' ? $meglevoKuponKep : 'nincs_kep.jpg');

    $extraCols = '';
    $extraVals = '';
    if ($eredetiArOszlopVan) {
        $extraCols .= ", eredeti_ar";
        $extraVals .= ", " . max(0, $eredetiAr);
    }
    if ($varosOszlopVan) {
        $extraCols .= ", varos";
        $extraVals .= ", '$varos'";
    }
    if ($kuponKategoriaIdOszlopVan) {
        $extraCols .= ", kupon_kategoria_id";
        $extraVals .= ", $kat";
    }
    if (liveUrlOszlopVanUjAjanlat($conn)) {
        $extraCols .= ", live_url";
        $extraVals .= ", '$live_url'";
    }
    if (liveAktivOszlopVanUjAjanlat($conn)) {
        $extraCols .= ", live_active";
        $extraVals .= ", 0";
    }
    if ($kuponReszletekOszlopVan) {
        $extraCols .= ", kupon_reszletek";
        $extraVals .= ", '$reszletek'";
    }
    if ($kuponBevaltasVegeOszlopVan) {
        $extraCols .= ", kupon_bevaltas_vege";
        $extraVals .= ", '" . $conn->real_escape_string($bevaltasVege) . "'";
    }

    $sql = "INSERT INTO termekek
        (nev, leiras, aktualis_ar, licit_lepcso, szallitasi_mod, szallitasi_dij, kategoria_id, kezdes_idopont, lejarat_idopont, kep_url, video_url, feltolto_id, eladva, ajanlat_tipus, fix_ar, darabszam, orszag_kod, penznem$extraCols)
        VALUES
        ('$nev', '$leiras', $fixAr, 0, 'ingyenes', 0, $kat, '$kezdet', '$lejarat', '$kep', '$v_url', $user_id, 0, 'kupon', $fixAr, $darabszam, " . shobidMarketSqlValue($conn, $aktivPiacKodUjAjanlat) . ", '" . $conn->real_escape_string($aktivPenznemKodUjAjanlat) . "'$extraVals)";
    if ($conn->query($sql)) {
        header("Location: index.php?kat=kupon");
        exit;
    }
    echo "Hiba tortent a kupon mentesekor: " . $conn->error;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($pageHtmlLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(st('create.page.title', 'Uj ajanlat keszitese', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/style.css?v=20260402-1">
</head>
<body class="auction-create-page">
<div class="auction-shell">
    <?php
    $sidebarActivePage = 'create';
    $sidebarSearchAction = 'index.php';
    $sidebarIndexBase = 'index.php';
    $sidebarShowSearch = true;
    include __DIR__ . '/auction_sidebar.php';
    ?>

    <main class="create-shell-main">
        <div class="create-shell-scroll">
            <section class="create-hero">
                <div class="profile-kicker"><?php echo htmlspecialchars(st('create.hero.title', 'Uj ajanlat keszitese', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></div>
            </section>

            <div class="profile-tabbar">
                <button class="profile-tab <?php echo $ujrakozlesTipus === 'licit' ? 'is-active' : ''; ?>" type="button" onclick="openCreateTab(event, 'single-create-tab')"><?php echo htmlspecialchars(st('create.tab.licit', 'LICIT', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
                <button class="profile-tab <?php echo $ujrakozlesTipus === 'multi' ? 'is-active' : ''; ?>" type="button" onclick="openCreateTab(event, 'multi-create-tab')"><?php echo htmlspecialchars(st('create.tab.multi', 'LICIT SHOP', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
                <button class="profile-tab <?php echo $ujrakozlesTipus === 'fix' ? 'is-active' : ''; ?>" type="button" onclick="openCreateTab(event, 'fix-create-tab')"><?php echo htmlspecialchars(st('create.tab.fix', 'FIX AR', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
                <button class="profile-tab <?php echo $ujrakozlesTipus === 'kupon' ? 'is-active' : ''; ?>" type="button" onclick="openCreateTab(event, 'kupon-create-tab')"><?php echo htmlspecialchars(st('create.coupon.tab', 'KUPON', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
            </div>

            <div id="single-create-tab" class="profile-tabpanel <?php echo $ujrakozlesTipus === 'licit' ? 'is-active' : ''; ?>">
                <section class="create-panel">
                    <form method="POST" enctype="multipart/form-data" class="create-formgrid">
                        <input type="hidden" name="meglevo_kep" value="<?php echo htmlspecialchars($singleDefaults['kep_url']); ?>">
                        <div class="create-description-box create-field--full"><?php echo htmlspecialchars(st('create.single.description_box', 'LICIT tab leiras. Ezt adminban kulon tudod szerkeszteni.', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="create-field create-field--full">
                            <label><?php echo htmlspecialchars(st('create.single.product_name_label', 'Termek megnevezese', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="text" name="nev" maxlength="25" placeholder="<?php echo htmlspecialchars(st('create.single.product_name_placeholder', 'Termek neve...', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($singleDefaults['nev']); ?>" required>
                        </div>
                        <div class="create-field create-field--full">
                            <label><?php echo htmlspecialchars(st('create.single.description_label', 'Leiras', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <textarea name="leiras" rows="5" maxlength="250" placeholder="<?php echo htmlspecialchars(st('create.single.description_placeholder', 'Ird le roviden, mit kell tudni az ajanlatrol.', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" required><?php echo htmlspecialchars($singleDefaults['leiras']); ?></textarea>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.single.category_label', 'Kategoria', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <?php echo renderCustomCategorySelect('kategoria', $singleDefaults['kategoria'], $kategoriak); ?>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.single.video_label', 'YouTube video link', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="text" name="video_url" placeholder="https://www.youtube.com/watch?v=..." value="<?php echo htmlspecialchars($singleDefaults['video_url']); ?>" required>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.single.live_label', 'YouTube live link', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="text" name="live_url" placeholder="https://www.youtube.com/live/..." value="<?php echo htmlspecialchars($singleDefaults['live_url']); ?>">
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.single.start_price_label_base', 'Kikialtasi ar', $pageLocaleCode) . ' (' . $aktivPenznemJelUjAjanlat . ')', ENT_QUOTES, 'UTF-8'); ?></label>
                            <div class="money-input">
                                <input type="number" name="ar" value="<?php echo htmlspecialchars((string)$singleDefaults['ar']); ?>" min="1" placeholder="<?php echo htmlspecialchars(st('create.single.start_price_placeholder', 'Kikialtasi ar', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" required>
                                <span class="money-input__suffix"><?php echo htmlspecialchars($aktivPenznemJelUjAjanlat, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.single.bid_step_label_base', 'Licitlepcso', $pageLocaleCode) . ' (' . $aktivPenznemJelUjAjanlat . ')', ENT_QUOTES, 'UTF-8'); ?></label>
                            <div class="money-input">
                                <input type="number" name="lepcso" value="<?php echo htmlspecialchars((string)$singleDefaults['lepcso']); ?>" min="1" placeholder="<?php echo htmlspecialchars(st('create.single.bid_step_placeholder', 'Licitlepcso', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" required>
                                <span class="money-input__suffix"><?php echo htmlspecialchars($aktivPenznemJelUjAjanlat, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="create-field create-field--full">
                            <label><?php echo htmlspecialchars(st('create.single.shipping_label', 'Szallitas', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <div class="shipping-choice-group">
                                <label class="shipping-choice">
                                    <input type="radio" name="szallitasi_mod" value="ingyenes" required <?php echo ($singleDefaults['szallitasi_mod'] ?? 'ingyenes') !== 'egyedi' ? 'checked' : ''; ?> onchange="toggleShippingFee(this.form, '', '')">
                                    <span><?php echo htmlspecialchars(st('create.shipping.free', 'INGYENES SZALLITAS', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
                                </label>
                                <label class="shipping-choice">
                                    <input type="radio" name="szallitasi_mod" value="egyedi" required <?php echo ($singleDefaults['szallitasi_mod'] ?? '') === 'egyedi' ? 'checked' : ''; ?> onchange="toggleShippingFee(this.form, '', '')">
                                    <span><?php echo htmlspecialchars(st('create.shipping.custom', 'En adom meg a szallitasi dijat', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
                                </label>
                            </div>
                        </div>
                        <div class="create-field" id="shipping-fee-wrap" style="display:none;">
                            <label><?php echo htmlspecialchars(st('create.shipping.fee_label', 'Szallitasi dij', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <div class="money-input">
                                <input type="number" name="szallitasi_dij" id="shipping-fee-input" min="1" inputmode="numeric" placeholder="<?php echo htmlspecialchars(st('create.shipping.fee_placeholder', 'Szallitasi dij', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)$singleDefaults['szallitasi_dij']); ?>">
                                <span class="money-input__suffix"><?php echo htmlspecialchars($aktivPenznemJelUjAjanlat, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.single.start_label', 'Aukcio kezdete', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="datetime-local" name="kezdet" value="<?php echo htmlspecialchars($singleDefaults['kezdet']); ?>" required>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.single.end_label', 'Aukcio vege', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="datetime-local" name="lejarat" value="<?php echo htmlspecialchars($singleDefaults['lejarat']); ?>" required>
                            <div class="create-help"><?php echo htmlspecialchars(st('create.single.max_time_prefix', 'Maximum ido:', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?> <?php echo intval($licitMaxOra); ?> <?php echo htmlspecialchars(st('create.single.hour', 'ora', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="create-field create-field--full">
                            <label><?php echo htmlspecialchars(st('create.single.photo_label', 'Termek fotoja', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <label class="create-filepicker create-filepicker--drop" for="kep">
                                <input type="file" name="kep" id="kep" accept=".jpg,.jpeg,.png,.webp,.gif" <?php echo $singleDefaults['kep_url'] === 'nincs_kep.jpg' ? 'required' : ''; ?> onchange="updateFileName(this, 'create-file-name')" ondragenter="toggleUploadDrag(this, true)" ondragleave="toggleUploadDrag(this, false)" ondrop="toggleUploadDrag(this, false)">
                                <span class="create-filepicker__dropicon" aria-hidden="true">↥</span>
                                <span class="create-filepicker__droptext"><?php echo htmlspecialchars(st('create.file.drop', 'Kattints vagy huzd ide a fajlt', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="create-filepicker__meta">JPG, PNG, WEBP, GIF</span>
                                <span class="create-filepicker__name" id="create-file-name"><?php echo $singleDefaults['kep_url'] !== 'nincs_kep.jpg' ? htmlspecialchars($singleDefaults['kep_url']) : htmlspecialchars(st('create.file.none', 'Nincs fajl kivalasztva', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
                            </label>
                        </div>
                        <div class="create-actions create-field--full">
                            <button type="submit" name="mentes" class="profile-submit"><?php echo htmlspecialchars(st('create.single.submit', 'Ajanlat letrehozasa', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
                            <a href="profil.php" class="create-cancel"><?php echo htmlspecialchars(st('create.cancel', 'Megse / Vissza', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></a>
                        </div>
                    </form>
                </section>
            </div>

            <div id="multi-create-tab" class="profile-tabpanel <?php echo $ujrakozlesTipus === 'multi' ? 'is-active' : ''; ?>">
                <section class="create-panel">
                    <form method="POST" enctype="multipart/form-data" class="create-formgrid" id="user-multi-form" data-visible-count="<?php echo max(1, count($ujrakozlesMultiTetelek)); ?>" data-max-items="25">
                        <input type="hidden" name="multi_meglevo_kep" value="<?php echo htmlspecialchars($multiDefaults['kep_url']); ?>">
                        <div class="create-description-box create-field--full"><?php echo htmlspecialchars(st('create.multi.description_box', 'LICIT SHOP tab leiras. Ezt adminban kulon tudod szerkeszteni.', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="create-field create-field--full">
                            <label><?php echo htmlspecialchars(st('create.multi.main_name_label', 'Fonev', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="text" name="multi_fo_nev" maxlength="25" placeholder="<?php echo htmlspecialchars(st('create.multi.main_name_placeholder', 'Ez jelenik meg a kartyak alatt', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($multiDefaults['fo_nev']); ?>" required>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.multi.category_label', 'Kategoria', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <?php echo renderCustomCategorySelect('multi_kategoria', $multiDefaults['kategoria'], $kategoriak); ?>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.multi.video_label', 'YouTube video link', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="text" name="multi_video_url" placeholder="https://www.youtube.com/watch?v=..." value="<?php echo htmlspecialchars($multiDefaults['video_url']); ?>" required>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.multi.live_label', 'YouTube live link', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="text" name="multi_live_url" placeholder="https://www.youtube.com/live/..." value="<?php echo htmlspecialchars($multiDefaults['live_url']); ?>">
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.multi.start_label', 'Multi aukcio kezdete', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="datetime-local" name="multi_kezdet" value="<?php echo htmlspecialchars($multiDefaults['kezdet']); ?>" required>
                        </div>
                        <div class="create-field create-field--full">
                            <label><?php echo htmlspecialchars(st('create.multi.main_photo_label', 'Fokep', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <label class="create-filepicker create-filepicker--drop" for="multi_kep">
                                <input type="file" name="multi_kep" id="multi_kep" accept=".jpg,.jpeg,.png,.webp,.gif" <?php echo $multiDefaults['kep_url'] === 'nincs_kep.jpg' ? 'required' : ''; ?> onchange="updateFileName(this, 'multi-file-name')" ondragenter="toggleUploadDrag(this, true)" ondragleave="toggleUploadDrag(this, false)" ondrop="toggleUploadDrag(this, false)">
                                <span class="create-filepicker__dropicon" aria-hidden="true">↥</span>
                                <span class="create-filepicker__droptext"><?php echo htmlspecialchars(st('create.file.drop', 'Kattints vagy huzd ide a fajlt', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="create-filepicker__meta">JPG, PNG, WEBP, GIF</span>
                                <span class="create-filepicker__name" id="multi-file-name"><?php echo $multiDefaults['kep_url'] !== 'nincs_kep.jpg' ? htmlspecialchars($multiDefaults['kep_url']) : htmlspecialchars(st('create.file.none', 'Nincs fajl kivalasztva', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
                            </label>
                        </div>

                        <div class="create-field create-field--full">
                            <label><?php echo htmlspecialchars(st('create.multi.items_label', 'Reszaukciok (maximum 25 db)', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <div class="multi-items-stack">
                                <?php for ($i = 0; $i < 25; $i++): ?>
                                <?php $tetelAlap = $ujrakozlesMultiTetelek[$i] ?? null; ?>
                                <details class="multi-item-card<?php echo $i >= max(1, count($ujrakozlesMultiTetelek)) ? ' is-hidden' : ''; ?>" data-item-index="<?php echo $i; ?>" <?php echo $i < max(1, count($ujrakozlesMultiTetelek)) ? 'open' : ''; ?>>
                                    <summary class="multi-item-card__summary">
                                        <span><?php echo htmlspecialchars(st('create.multi.item_prefix', 'Tetel', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?> #<?php echo $i + 1; ?></span>
                                        <button type="button" class="multi-item-card__remove" onclick="event.preventDefault(); event.stopPropagation(); removeMultiItem(this, <?php echo $i; ?>);"><?php echo htmlspecialchars(st('create.multi.delete', 'Torles', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
                                    </summary>
                                    <div class="multi-item-card__body">
                                        <div class="create-field">
                                            <label><?php echo htmlspecialchars(st('create.multi.item_name_label', 'Nev', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                                            <input type="text" name="multi_items[<?php echo $i; ?>][nev]" maxlength="25" placeholder="<?php echo htmlspecialchars(st('create.multi.item_name_placeholder', 'Tetel neve', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($tetelAlap['nev'] ?? ''); ?>" required>
                                        </div>
                                        <div class="create-field">
                                            <label><?php echo htmlspecialchars(st('create.multi.position_label', 'Pozicio', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                                            <input type="number" name="multi_items[<?php echo $i; ?>][pozicio]" min="1" max="25" value="<?php echo intval($tetelAlap['sorszam'] ?? ($i + 1)); ?>" required>
                                        </div>
                                        <div class="create-field create-field--full">
                                            <label><?php echo htmlspecialchars(st('create.multi.description_label', 'Leiras', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                                            <textarea name="multi_items[<?php echo $i; ?>][leiras]" rows="4" maxlength="250" placeholder="<?php echo htmlspecialchars(st('create.multi.description_placeholder', 'Leiras', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" required><?php echo htmlspecialchars($tetelAlap['leiras'] ?? ''); ?></textarea>
                                        </div>
                                        <div class="create-field">
                                            <label><?php echo htmlspecialchars(st('create.shipping.label', 'Szallitas', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                                            <div class="shipping-choice-group">
                                                <label class="shipping-choice">
                                                    <input type="radio" name="multi_items[<?php echo $i; ?>][szallitasi_mod]" value="ingyenes" required <?php echo (($tetelAlap['szallitasi_mod'] ?? 'ingyenes') !== 'egyedi') ? 'checked' : ''; ?> onchange="toggleItemShipping(<?php echo $i; ?>)">
                                                    <span><?php echo htmlspecialchars(st('create.shipping.free_short', 'INGYENES', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
                                                </label>
                                                <label class="shipping-choice">
                                                    <input type="radio" name="multi_items[<?php echo $i; ?>][szallitasi_mod]" value="egyedi" required <?php echo (($tetelAlap['szallitasi_mod'] ?? '') === 'egyedi') ? 'checked' : ''; ?> onchange="toggleItemShipping(<?php echo $i; ?>)">
                                                    <span><?php echo htmlspecialchars(st('create.shipping.custom', 'En adom meg a szallitasi dijat', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="create-field" id="multi-item-shipping-wrap-<?php echo $i; ?>" style="display:none;">
                                            <label><?php echo htmlspecialchars(st('create.shipping.fee_label', 'Szallitasi dij', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                                            <div class="money-input">
                                                <input type="number" name="multi_items[<?php echo $i; ?>][szallitasi_dij]" id="multi-item-shipping-input-<?php echo $i; ?>" min="1" placeholder="<?php echo htmlspecialchars(st('create.shipping.fee_placeholder', 'Szallitasi dij', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)(intval($tetelAlap['szallitasi_dij'] ?? 0) > 0 ? intval($tetelAlap['szallitasi_dij']) : '')); ?>">
                                                <span class="money-input__suffix"><?php echo htmlspecialchars($aktivPenznemJelUjAjanlat, ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                        </div>
                                        <div class="create-field">
                                            <label><?php echo htmlspecialchars(st('create.multi.start_bid_label', 'Indulo licit', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                                            <div class="money-input">
                                                <input type="number" name="multi_items[<?php echo $i; ?>][ar]" min="1" required placeholder="<?php echo htmlspecialchars(st('create.multi.start_bid_placeholder', 'Indulo licit', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)(intval($tetelAlap['aktualis_ar'] ?? 0) > 0 ? intval($tetelAlap['aktualis_ar']) : '')); ?>">
                                                <span class="money-input__suffix"><?php echo htmlspecialchars($aktivPenznemJelUjAjanlat, ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                        </div>
                                        <div class="create-field">
                                            <label><?php echo htmlspecialchars(st('create.multi.bid_step_label', 'Licitlepcso', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                                            <div class="money-input">
                                                <input type="number" name="multi_items[<?php echo $i; ?>][lepcso]" min="1" required placeholder="<?php echo htmlspecialchars(st('create.multi.bid_step_placeholder', 'Licitlepcso', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)(intval($tetelAlap['licit_lepcso'] ?? 0) > 0 ? intval($tetelAlap['licit_lepcso']) : '')); ?>">
                                                <span class="money-input__suffix"><?php echo htmlspecialchars($aktivPenznemJelUjAjanlat, ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                        </div>
                                        <div class="create-field">
                                            <label><?php echo htmlspecialchars(st('create.multi.duration_label', 'Hany percig tartson', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                                            <select name="multi_items[<?php echo $i; ?>][idotartam_mp]" required>
                                                <?php foreach ($durationOptions as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo intval($value) === intval($tetelAlap['idotartam_mp'] ?? 60) ? 'selected' : ''; ?>><?php echo html_entity_decode($label, ENT_QUOTES, 'UTF-8'); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </details>
                                <?php endfor; ?>
                            </div>
                            <button type="button" class="create-cancel create-cancel--secondary" id="user-add-multi-item">+ <?php echo htmlspecialchars(st('create.multi.add_item', 'Licit tetel hozzaadasa', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
                        </div>

                        <div class="create-actions create-field--full">
                            <button type="submit" name="mentes_multi" class="profile-submit"><?php echo htmlspecialchars(st('create.multi.submit', 'LICIT SHOP letrehozasa', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
                            <a href="profil.php" class="create-cancel"><?php echo htmlspecialchars(st('create.cancel', 'Megse / Vissza', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></a>
                        </div>
                    </form>
                </section>
            </div>

            <div id="fix-create-tab" class="profile-tabpanel <?php echo $ujrakozlesTipus === 'fix' ? 'is-active' : ''; ?>">
                <section class="create-panel">
                    <form method="POST" enctype="multipart/form-data" class="create-formgrid">
                        <input type="hidden" name="fix_meglevo_kep" value="<?php echo htmlspecialchars($fixDefaults['kep_url']); ?>">
                        <div class="create-description-box create-field--full"><?php echo htmlspecialchars(st('create.fix.description_box', 'FIX AR tab leiras. Ezt adminban kulon tudod szerkeszteni.', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="create-field create-field--full">
                            <label><?php echo htmlspecialchars(st('create.fix.product_name_label', 'Termek megnevezese', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="text" name="fix_nev" maxlength="25" placeholder="<?php echo htmlspecialchars(st('create.fix.product_name_placeholder', 'Pl. Limitalt grafika', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($fixDefaults['nev']); ?>" required>
                        </div>
                        <div class="create-field create-field--full">
                            <label><?php echo htmlspecialchars(st('create.fix.description_label', 'Leiras', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <textarea name="fix_leiras" rows="5" maxlength="250" placeholder="<?php echo htmlspecialchars(st('create.fix.description_placeholder', 'Ird le roviden, mit kell tudni az ajanlatrol.', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" required><?php echo htmlspecialchars($fixDefaults['leiras']); ?></textarea>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.fix.category_label', 'Kategoria', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <?php echo renderCustomCategorySelect('fix_kategoria', $fixDefaults['kategoria'], $kategoriak); ?>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.fix.video_label', 'YouTube video link', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="text" name="fix_video_url" placeholder="https://www.youtube.com/watch?v=..." value="<?php echo htmlspecialchars($fixDefaults['video_url']); ?>" required>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.fix.live_label', 'YouTube live link', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="text" name="fix_live_url" placeholder="https://www.youtube.com/live/..." value="<?php echo htmlspecialchars($fixDefaults['live_url']); ?>">
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.fix.price_label_base', 'Fix ar', $pageLocaleCode) . ' (' . $aktivPenznemJelUjAjanlat . ')', ENT_QUOTES, 'UTF-8'); ?></label>
                            <div class="money-input">
                                <input type="number" name="fix_ar" min="1" placeholder="<?php echo htmlspecialchars(st('create.fix.price_placeholder', 'Fix ar', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)$fixDefaults['fix_ar']); ?>" required>
                                <span class="money-input__suffix"><?php echo htmlspecialchars($aktivPenznemJelUjAjanlat, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.fix.original_price_label', 'Eredeti ar', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <div class="money-input">
                                <input type="number" name="fix_eredeti_ar" min="0" placeholder="<?php echo htmlspecialchars(st('create.fix.original_price_placeholder', 'Eredeti ar', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)$fixDefaults['eredeti_ar']); ?>">
                                <span class="money-input__suffix"><?php echo htmlspecialchars($aktivPenznemJelUjAjanlat, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.fix.city_label', 'Varos', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="text" name="fix_varos" value="<?php echo htmlspecialchars($fixDefaults['varos']); ?>" placeholder="<?php echo htmlspecialchars(st('create.fix.city_placeholder', 'Pl. Budapest', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.fix.quantity_label', 'Darabszam', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="number" name="fix_darabszam" min="1" value="<?php echo intval($fixDefaults['darabszam']); ?>" required>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.fix.start_label', 'Ajanlat kezdete', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="datetime-local" name="fix_kezdet" value="<?php echo htmlspecialchars($fixDefaults['kezdet']); ?>" required>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.fix.end_label', 'Ajanlat vege', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="datetime-local" name="fix_lejarat" value="<?php echo htmlspecialchars($fixDefaults['lejarat']); ?>" required>
                            <div class="create-help"><?php echo htmlspecialchars(st('create.fix.max_time_prefix', 'A fix aras ajanlat maximum ideje:', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?> <?php echo intval($fixMaxOra); ?> <?php echo htmlspecialchars(st('create.single.hour', 'ora', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="create-field create-field--full">
                            <label><?php echo htmlspecialchars(st('create.fix.shipping_label', 'Szallitas', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <div class="shipping-choice-group">
                                <label class="shipping-choice">
                                    <input type="radio" name="fix_szallitasi_mod" value="ingyenes" required <?php echo ($fixDefaults['szallitasi_mod'] ?? 'ingyenes') !== 'egyedi' ? 'checked' : ''; ?> onchange="toggleShippingFee(this.form, 'fix-shipping-fee-wrap', 'fix-shipping-fee-input', 'fix_szallitasi_mod')">
                                    <span><?php echo htmlspecialchars(st('create.shipping.free', 'INGYENES SZALLITAS', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
                                </label>
                                <label class="shipping-choice">
                                    <input type="radio" name="fix_szallitasi_mod" value="egyedi" required <?php echo ($fixDefaults['szallitasi_mod'] ?? '') === 'egyedi' ? 'checked' : ''; ?> onchange="toggleShippingFee(this.form, 'fix-shipping-fee-wrap', 'fix-shipping-fee-input', 'fix_szallitasi_mod')">
                                    <span><?php echo htmlspecialchars(st('create.shipping.custom', 'En adom meg a szallitasi dijat', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
                                </label>
                            </div>
                        </div>
                        <div class="create-field" id="fix-shipping-fee-wrap" style="display:none;">
                            <label><?php echo htmlspecialchars(st('create.shipping.fee_label', 'Szallitasi dij', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <div class="money-input">
                                <input type="number" name="fix_szallitasi_dij" id="fix-shipping-fee-input" min="1" inputmode="numeric" placeholder="<?php echo htmlspecialchars(st('create.shipping.fee_placeholder', 'Szallitasi dij', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)$fixDefaults['szallitasi_dij']); ?>">
                                <span class="money-input__suffix"><?php echo htmlspecialchars($aktivPenznemJelUjAjanlat, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="create-field create-field--full">
                            <label><?php echo htmlspecialchars(st('create.fix.photo_label', 'Termek fotoja', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <label class="create-filepicker create-filepicker--drop" for="fix_kep">
                                <input type="file" name="fix_kep" id="fix_kep" accept=".jpg,.jpeg,.png,.webp,.gif" <?php echo $fixDefaults['kep_url'] === 'nincs_kep.jpg' ? 'required' : ''; ?> onchange="updateFileName(this, 'fix-file-name')" ondragenter="toggleUploadDrag(this, true)" ondragleave="toggleUploadDrag(this, false)" ondrop="toggleUploadDrag(this, false)">
                                <span class="create-filepicker__dropicon" aria-hidden="true">↥</span>
                                <span class="create-filepicker__droptext"><?php echo htmlspecialchars(st('create.file.drop', 'Kattints vagy huzd ide a fajlt', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="create-filepicker__meta">JPG, PNG, WEBP, GIF</span>
                                <span class="create-filepicker__name" id="fix-file-name"><?php echo $fixDefaults['kep_url'] !== 'nincs_kep.jpg' ? htmlspecialchars($fixDefaults['kep_url']) : htmlspecialchars(st('create.file.none', 'Nincs fajl kivalasztva', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
                            </label>
                        </div>
                        <div class="create-actions create-field--full">
                            <button type="submit" name="mentes_fix" class="profile-submit"><?php echo htmlspecialchars(st('create.fix.submit', 'Fix aras ajanlat letrehozasa', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
                            <a href="profil.php" class="create-cancel"><?php echo htmlspecialchars(st('create.cancel', 'Megse / Vissza', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></a>
                        </div>
                    </form>
                </section>
            </div>

            <div id="kupon-create-tab" class="profile-tabpanel <?php echo $ujrakozlesTipus === 'kupon' ? 'is-active' : ''; ?>">
                <section class="create-panel">
                    <form method="POST" enctype="multipart/form-data" class="create-formgrid">
                        <input type="hidden" name="kupon_meglevo_kep" value="<?php echo htmlspecialchars($kuponDefaults['kep_url']); ?>">
                        <div class="create-description-box create-field--full"><?php echo htmlspecialchars(st('create.coupon.description_box', 'Kupon tab leiras. Ezt adminban kulon tudod szerkeszteni.', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="create-field create-field--full">
                            <label><?php echo htmlspecialchars(st('create.coupon.name_label', 'Kupon neve', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="text" name="kupon_nev" maxlength="80" placeholder="<?php echo htmlspecialchars(st('create.coupon.name_placeholder', 'Pl. 50% kedvezmeny sutire', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($kuponDefaults['nev']); ?>" required>
                        </div>
                        <div class="create-field create-field--full">
                            <label>Leiras</label>
                            <textarea name="kupon_leiras" rows="4" maxlength="250" required><?php echo htmlspecialchars($kuponDefaults['leiras']); ?></textarea>
                        </div>
                        <div class="create-field create-field--full">
                            <label>Reszletek</label>
                            <textarea name="kupon_reszletek" rows="5" placeholder="<?php echo htmlspecialchars(st('create.coupon.details_placeholder', 'Itt adhatod meg a hosszu reszletes leirast.', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($kuponDefaults['reszletek']); ?></textarea>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.coupon.category_label', 'Kupon kategoria', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <?php echo renderCustomCategorySelect('kupon_kategoria', $kuponDefaults['kategoria'], $kuponKategoriak, st('create.coupon.category_placeholder', '-- Valassz kupon kategoriat --', $pageLocaleCode)); ?>
                        </div>
                        <div class="create-field">
                            <label>YouTube video link</label>
                            <input type="text" name="kupon_video_url" placeholder="https://www.youtube.com/watch?v=..." value="<?php echo htmlspecialchars($kuponDefaults['video_url']); ?>" required>
                        </div>
                        <div class="create-field">
                            <label>YouTube live link</label>
                            <input type="text" name="kupon_live_url" placeholder="https://www.youtube.com/live/..." value="<?php echo htmlspecialchars((string)($kuponDefaults['live_url'] ?? '')); ?>">
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.coupon.price_label_base', 'Kupon ara', $pageLocaleCode) . ' (' . $aktivPenznemJelUjAjanlat . ')', ENT_QUOTES, 'UTF-8'); ?></label>
                            <div class="money-input">
                                <input type="number" name="kupon_ar" min="1" value="<?php echo htmlspecialchars((string)$kuponDefaults['fix_ar']); ?>" required>
                                <span class="money-input__suffix"><?php echo htmlspecialchars($aktivPenznemJelUjAjanlat, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="create-field">
                            <label>Eredeti ar (<?php echo htmlspecialchars($aktivPenznemJelUjAjanlat, ENT_QUOTES, 'UTF-8'); ?>)</label>
                            <div class="money-input">
                                <input type="number" name="kupon_eredeti_ar" min="0" value="<?php echo htmlspecialchars((string)$kuponDefaults['eredeti_ar']); ?>">
                                <span class="money-input__suffix"><?php echo htmlspecialchars($aktivPenznemJelUjAjanlat, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="create-field">
                            <label>Varos</label>
                            <input type="text" name="kupon_varos" value="<?php echo htmlspecialchars($kuponDefaults['varos']); ?>" placeholder="<?php echo htmlspecialchars(st('create.coupon.city_placeholder', 'Pl. Budapest', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.coupon.quantity_label', 'Kupon darabszam', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="number" name="kupon_darabszam" min="1" value="<?php echo intval($kuponDefaults['darabszam']); ?>" required>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.coupon.start_label', 'Kupon vasarlas kezdete', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="datetime-local" name="kupon_kezdet" value="<?php echo htmlspecialchars($kuponDefaults['kezdet']); ?>" required>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.coupon.end_label', 'Kupon vasarlas vege', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="datetime-local" name="kupon_lejarat" value="<?php echo htmlspecialchars($kuponDefaults['lejarat']); ?>" required>
                            <div class="create-help"><?php echo htmlspecialchars(st('create.coupon.max_time_prefix', 'A kupon ajanlat maximum ideje:', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?> <?php echo intval($fixMaxOra); ?> <?php echo htmlspecialchars(st('create.single.hour', 'ora', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="create-field">
                            <label><?php echo htmlspecialchars(st('create.coupon.redeem_end_label', 'Kupon bevaltas vege (csak nap)', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="date" name="kupon_bevaltas_vege" value="<?php echo htmlspecialchars($kuponDefaults['bevaltas_vege']); ?>" required>
                        </div>
                        <div class="create-field create-field--full">
                            <label>Termek fotoja</label>
                            <label class="create-filepicker create-filepicker--drop" for="kupon_kep">
                                <input type="file" name="kupon_kep" id="kupon_kep" accept=".jpg,.jpeg,.png,.webp,.gif" <?php echo $kuponDefaults['kep_url'] === 'nincs_kep.jpg' ? 'required' : ''; ?> onchange="updateFileName(this, 'kupon-file-name')">
                                <span class="create-filepicker__dropicon" aria-hidden="true">↑</span>
                                <span class="create-filepicker__droptext">Kattints vagy huzd ide a fajlt</span>
                                <span class="create-filepicker__meta">JPG, PNG, WEBP, GIF</span>
                                <span class="create-filepicker__name" id="kupon-file-name"><?php echo htmlspecialchars(st('create.file.none', 'Nincs fajl kivalasztva', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
                            </label>
                        </div>
                        <div class="create-actions create-field--full">
                            <button type="submit" name="mentes_kupon" class="profile-submit"><?php echo htmlspecialchars(st('create.coupon.submit', 'Kupon ajanlat letrehozasa', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
                            <a href="profil.php" class="create-cancel"><?php echo htmlspecialchars(st('create.cancel', 'Megse / Vissza', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></a>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </main>
</div>
<div class="site-modal" id="sidebar-info-modal" aria-hidden="true">
    <div class="site-modal__backdrop" onclick="closeSidebarInfoModal()"></div>
    <div class="site-modal__dialog site-modal__dialog--light" role="dialog" aria-modal="true" aria-labelledby="sidebar-info-modal-title">
        <h3 class="site-modal__title site-modal__title--dark" id="sidebar-info-modal-title"><?php echo htmlspecialchars(st('create.modal.title', 'Cim', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></h3>
        <div class="site-modal__text site-modal__text--dark" id="sidebar-info-modal-text"><?php echo htmlspecialchars(st('create.modal.text_placeholder', 'Ide jon a szoveg...', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="site-modal__actions site-modal__actions--center">
            <button class="site-modal__button" type="button" onclick="closeSidebarInfoModal()"><?php echo htmlspecialchars(st('create.modal.close', 'Bezar', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
        </div>
    </div>
</div>
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
    target.textContent = input.files && input.files.length ? input.files[0].name : <?php echo json_encode(st('create.file.none', 'Nincs fajl kivalasztva', $pageLocaleCode), JSON_UNESCAPED_UNICODE); ?>;
}

function toggleUploadDrag(input, isActive) {
    const wrap = input && input.closest('.create-filepicker--drop');
    if (!wrap) return;
    wrap.classList.toggle('is-dragover', !!isActive);
}

function toggleShippingFee(form, wrapId, inputId, groupName) {
    const selected = form.querySelector('input[name="' + (groupName || 'szallitasi_mod') + '"]:checked');
    const wrap = document.getElementById(wrapId || 'shipping-fee-wrap');
    const input = document.getElementById(inputId || 'shipping-fee-input');
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

function openCreateTab(evt, tabName) {
    const panels = document.getElementsByClassName('profile-tabpanel');
    for (let i = 0; i < panels.length; i++) panels[i].classList.remove('is-active');
    const tabs = document.getElementsByClassName('profile-tab');
    for (let i = 0; i < tabs.length; i++) tabs[i].classList.remove('is-active');
    document.getElementById(tabName).classList.add('is-active');
    evt.currentTarget.classList.add('is-active');
}

function initUserMultiItemCards() {
    const form = document.getElementById('user-multi-form');
    const addButton = document.getElementById('user-add-multi-item');
    if (!form || !addButton) return;

    let visibleCount = parseInt(form.dataset.visibleCount || '1', 10);
    const maxItems = parseInt(form.dataset.maxItems || '25', 10);

    function setCardEnabled(card, enabled) {
        if (!card) return;
        card.querySelectorAll('input, textarea, select, button').forEach(function (field) {
            if (field.classList.contains('multi-item-card__remove')) return;
            field.disabled = !enabled;
        });
    }

    function syncButton() {
        addButton.disabled = visibleCount >= maxItems;
    }

    function syncVisibleCardsState() {
        form.querySelectorAll('.multi-item-card').forEach(function (card, idx) {
            const enabled = idx < visibleCount && !card.classList.contains('is-hidden');
            setCardEnabled(card, enabled);
        });
    }

    syncVisibleCardsState();

    addButton.addEventListener('click', function () {
        if (visibleCount >= maxItems) return;
        const card = form.querySelector('.multi-item-card[data-item-index="' + visibleCount + '"]');
        if (!card) return;
        card.classList.remove('is-hidden');
        card.setAttribute('open', 'open');
        setCardEnabled(card, true);
        visibleCount += 1;
        form.dataset.visibleCount = String(visibleCount);
        syncButton();
    });

    form.addEventListener('submit', function () {
        syncVisibleCardsState();
    });

    syncButton();
}

function removeMultiItem(button, index) {
    const form = document.getElementById('user-multi-form');
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
        card.querySelectorAll('input, textarea, select').forEach(function (field) {
            field.disabled = true;
        });
    }

    let visibleCount = 0;
    form.querySelectorAll('.multi-item-card').forEach(function (itemCard) {
        if (!itemCard.classList.contains('is-hidden')) visibleCount += 1;
    });
    form.dataset.visibleCount = String(Math.max(1, visibleCount));
    document.getElementById('user-add-multi-item').disabled = false;
    toggleItemShipping(index);
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
            alert('A maximum id\u0151 ' + maxHours + ' \u00f3ra lehet.');
        }
    });
}

function initCustomSelects() {
    const customSelects = document.querySelectorAll('[data-custom-select]');
    if (!customSelects.length) return;

    function closeAll(except) {
        customSelects.forEach(function (selectRoot) {
            if (selectRoot === except) return;
            selectRoot.classList.remove('is-open');
            const trigger = selectRoot.querySelector('[data-custom-select-trigger]');
            const menu = selectRoot.querySelector('[data-custom-select-menu]');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
            if (menu) menu.hidden = true;
        });
    }

    customSelects.forEach(function (selectRoot) {
        const nativeSelect = selectRoot.querySelector('[data-custom-select-native]');
        const trigger = selectRoot.querySelector('[data-custom-select-trigger]');
        const valueNode = selectRoot.querySelector('[data-custom-select-value]');
        const menu = selectRoot.querySelector('[data-custom-select-menu]');
        const options = selectRoot.querySelectorAll('[data-custom-select-option]');
        if (!nativeSelect || !trigger || !valueNode || !menu || !options.length) return;

        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            const open = !selectRoot.classList.contains('is-open');
            closeAll(selectRoot);
            selectRoot.classList.toggle('is-open', open);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            menu.hidden = !open;
        });

        options.forEach(function (optionButton) {
            optionButton.addEventListener('click', function () {
                const value = optionButton.getAttribute('data-value') || '';
                nativeSelect.value = value;
                valueNode.textContent = optionButton.textContent.trim();
                options.forEach(function (button) {
                    button.classList.toggle('is-selected', button === optionButton);
                });
                nativeSelect.dispatchEvent(new Event('change', { bubbles: true }));
                selectRoot.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
                menu.hidden = true;
            });
        });
    });

    document.addEventListener('click', function () {
        closeAll(null);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAll(null);
        }
    });
}

function markRequiredCreateFieldLabels() {
    const fields = document.querySelectorAll('.create-field');
    if (!fields.length) return;

    fields.forEach(function (field) {
        const mainLabel = field.querySelector(':scope > label:not(.shipping-choice):not(.create-filepicker):not(.admin-checkbox)');
        if (!mainLabel) return;

        const requiredInput = field.querySelector('input[required], select[required], textarea[required]');
        if (!requiredInput) return;

        mainLabel.classList.add('required-label');
    });
}

toggleShippingFee(document.forms[0], 'shipping-fee-wrap', 'shipping-fee-input');
const fixCreateForm = document.querySelector('#fix-create-tab form');
if (fixCreateForm) {
    toggleShippingFee(fixCreateForm, 'fix-shipping-fee-wrap', 'fix-shipping-fee-input', 'fix_szallitasi_mod');
}
for (let i = 0; i < 25; i++) {
    toggleItemShipping(i);
}
initUserMultiItemCards();
initCustomSelects();
markRequiredCreateFieldLabels();
attachMaxDurationValidation('#single-create-tab form', 'kezdet', 'lejarat', <?php echo intval($licitMaxOra); ?>);
attachMaxDurationValidation('#fix-create-tab form', 'fix_kezdet', 'fix_lejarat', <?php echo intval($fixMaxOra); ?>);

function openSidebarInfoModal(modalType) {
    var modal = document.getElementById('sidebar-info-modal');
    var title = document.getElementById('sidebar-info-modal-title');
    var text = document.getElementById('sidebar-info-modal-text');
    if (!modal || !title || !text) return;
    var contentMap = <?php echo $_sidebarInfoModalContentJson; ?>;
    var fallbackEntry = contentMap.gyik || { title: 'GYIK', html: '' };
    var selectedEntry = contentMap[modalType] || fallbackEntry;
    title.innerText = selectedEntry.title || fallbackEntry.title;
    text.innerHTML = selectedEntry.html || fallbackEntry.html;

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('has-site-modal');
}

function closeSidebarInfoModal() {
    var modal = document.getElementById('sidebar-info-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('has-site-modal');
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeSidebarInfoModal();
    }
});
</script>
</body>
</html>






