<?php
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
include __DIR__ . '/db.php';
include __DIR__ . '/auth.php';
include __DIR__ . '/stripe_helper.php';
include_once __DIR__ . '/payout_helper.php';
include_once __DIR__ . '/global/markets.php';
include_once __DIR__ . '/global/i18n.php';
include_once __DIR__ . '/global/sidebar_info_content.php';

if (function_exists('shobidPayoutMaybeRunDailyAuto')) {
    shobidPayoutMaybeRunDailyAuto($conn);
}

if (function_exists('shobidMarketEnsureTermekekOrszagKod')) {
    shobidMarketEnsureTermekekOrszagKod($conn);
}

$aktivPiacKod = function_exists('shobidMarketCurrentCode')
    ? shobidMarketCurrentCode()
    : (function_exists('shobidI18nCurrentLocale') ? shobidI18nCurrentLocale() : 'hu-hu');

$__marketCookiePath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$__marketCookieSegments = explode('/', trim($__marketCookiePath, '/'));
$__marketCookieCode = strtolower((string)($__marketCookieSegments[0] ?? ''));
$__marketCookieCodes = function_exists('shobidMarketCodes') ? shobidMarketCodes() : [];
if ($__marketCookieCode !== '' && in_array($__marketCookieCode, $__marketCookieCodes, true)) {
    $__marketCookieCurrent = strtolower((string)($_COOKIE['shobid_market'] ?? ''));
    if ($__marketCookieCurrent !== $__marketCookieCode && !headers_sent()) {
        $__marketCookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie('shobid_market', $__marketCookieCode, time() + 31536000, '/', '', $__marketCookieSecure, false);
        $_COOKIE['shobid_market'] = $__marketCookieCode;
    }
}

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

function oszlopLetezik($conn, $tablaNev, $oszlopNev) {
    $tablaNev = $conn->real_escape_string($tablaNev);
    $oszlopNev = $conn->real_escape_string($oszlopNev);
    $res = $conn->query("SHOW COLUMNS FROM `$tablaNev` LIKE '$oszlopNev'");
    return $res && $res->num_rows > 0;
}

function licitStartedOszlopVan($conn) {
    static $cache = null;
    if ($cache === null) {
        $cache = oszlopLetezik($conn, 'termekek', 'licit_started_at');
    }
    return $cache;
}

function licitIdotartamOszlopVan($conn) {
    static $cache = null;
    if ($cache === null) {
        $cache = oszlopLetezik($conn, 'termekek', 'licit_idotartam_mp');
    }
    return $cache;
}

function multiAktivTetelIndexOszlopVan($conn) {
    static $cache = null;
    if ($cache === null) {
        $cache = oszlopLetezik($conn, 'multi_aukciok', 'aktiv_tetel_index');
    }
    return $cache;
}

function multiAktivTetelStartOszlopVan($conn) {
    static $cache = null;
    if ($cache === null) {
        $cache = oszlopLetezik($conn, 'multi_aukciok', 'aktiv_tetel_start');
    }
    return $cache;
}

function liveUrlOszlopVan($conn) {
    static $cache = null;
    if ($cache === null) {
        $cache = oszlopLetezik($conn, 'termekek', 'live_url');
    }
    return $cache;
}

function liveAktivOszlopVan($conn) {
    static $cache = null;
    if ($cache === null) {
        $cache = oszlopLetezik($conn, 'termekek', 'live_active');
    }
    return $cache;
}

function eloKategoriaKepPath() {
    return __DIR__ . '/elo_kategoria_kep.txt';
}

function mostKategoriaKepPath() {
    return __DIR__ . '/most_kategoria_kep.txt';
}

function getEloKategoriaKep() {
    $path = eloKategoriaKepPath();
    if (!file_exists($path)) {
        return '';
    }
    return trim((string) file_get_contents($path));
}

function getLicitIdotartamMp($conn, $termek) {
    $duration = licitIdotartamOszlopVan($conn) ? intval($termek['licit_idotartam_mp'] ?? 0) : 0;
    if ($duration > 0) {
        return $duration;
    }

    $kezdesTs = !empty($termek['kezdes_idopont']) ? strtotime((string)$termek['kezdes_idopont']) : 0;
    $lejaratTs = !empty($termek['lejarat_idopont']) ? strtotime((string)$termek['lejarat_idopont']) : 0;
    if ($kezdesTs > 0 && $lejaratTs > $kezdesTs) {
        return max(1, $lejaratTs - $kezdesTs);
    }

    return 60;
}

function getSingleLicitAllapot($conn, $termek) {
    $kezdesTs = !empty($termek['kezdes_idopont']) ? strtotime((string)$termek['kezdes_idopont']) : null;
    $lejaratTs = !empty($termek['lejarat_idopont']) ? strtotime((string)$termek['lejarat_idopont']) : null;
    $mostTs = time();
    $allapot = 'live';
    $manualStartRequired = false;

    if ($kezdesTs && $kezdesTs > $mostTs) {
        return [
            'allapot' => 'soon',
            'kezdes_ts' => $kezdesTs,
            'lejarat_ts' => $lejaratTs,
            'manual_start_required' => false
        ];
    }

    if (licitStartedOszlopVan($conn)) {
        $startedTs = !empty($termek['licit_started_at']) ? strtotime((string)$termek['licit_started_at']) : null;
        if (!$startedTs) {
            if (intval($termek['eladva'] ?? 0) === 1 || ($lejaratTs && $lejaratTs <= $mostTs)) {
                $allapot = 'closed';
            } else {
                $allapot = 'live';
                $lejaratTs = null;
                $manualStartRequired = true;
            }
        } else {
            $lejaratTs = $startedTs + getLicitIdotartamMp($conn, $termek);
            if ($lejaratTs <= $mostTs || intval($termek['eladva'] ?? 0) === 1) {
                $allapot = 'closed';
            }
        }
    } elseif (($lejaratTs && $lejaratTs <= $mostTs) || intval($termek['eladva'] ?? 0) === 1) {
        $allapot = 'closed';
    }

    return [
        'allapot' => $allapot,
        'kezdes_ts' => $kezdesTs,
        'lejarat_ts' => $lejaratTs,
        'manual_start_required' => $manualStartRequired
    ];
}

function getMultiAukcioAdat($conn, $termek) {
    if (!$termek || !tablaLetezik($conn, 'multi_aukciok') || !tablaLetezik($conn, 'multi_aukcio_tetelek')) {
        return null;
    }

    $termekId = intval($termek['id'] ?? 0);
    if ($termekId < 1) {
        return null;
    }

    $multiRes = $conn->query("SELECT * FROM multi_aukciok WHERE termek_id = $termekId LIMIT 1");
    $multi = $multiRes ? $multiRes->fetch_assoc() : null;
    if (!$multi) {
        return null;
    }

    $items = [];
    $itemsRes = $conn->query("SELECT mt.*, k.nev AS kat_nev
        FROM multi_aukcio_tetelek mt
        LEFT JOIN kategoriak k ON mt.kategoria_id = k.id
        WHERE mt.multi_aukcio_id = " . intval($multi['id']) . "
        ORDER BY mt.sorszam ASC, mt.id ASC");
    while ($itemsRes && ($row = $itemsRes->fetch_assoc())) {
        if (function_exists('shobidI18nCategoryName')) {
            $row['kat_nev'] = shobidI18nCategoryName(intval($row['kategoria_id'] ?? 0), (string)($row['kat_nev'] ?? ''));
        }
        $items[] = $row;
    }

    if (!$items) {
        return null;
    }

    $kezdesTs = !empty($termek['kezdes_idopont']) ? strtotime((string)$termek['kezdes_idopont']) : time();
    $nowTs = time();

    if (multiAktivTetelIndexOszlopVan($conn) && multiAktivTetelStartOszlopVan($conn)) {
        $currentIndex = max(0, intval($multi['aktiv_tetel_index'] ?? 0));
        $displayItem = $items[min($currentIndex, count($items) - 1)];
        $activeItem = null;
        $nextItem = $items[$currentIndex + 1] ?? null;
        $countdownTs = null;
        $phase = 'closed';

        if ($nowTs < $kezdesTs) {
            $phase = 'soon';
            $countdownTs = $kezdesTs;
        } elseif (intval($termek['eladva'] ?? 0) === 1 || $currentIndex >= count($items)) {
            $phase = 'closed';
            $displayItem = end($items);
        } else {
            $pendingItem = $items[$currentIndex];
            $displayItem = $pendingItem;
            $startTs = !empty($multi['aktiv_tetel_start']) ? strtotime((string)$multi['aktiv_tetel_start']) : null;
            if (!$startTs) {
                $phase = 'ready';
                if ($currentIndex > 0) {
                    $displayItem = $items[$currentIndex - 1];
                    $nextItem = $pendingItem;
                } else {
                    $nextItem = $items[1] ?? null;
                }
            } else {
                $activeItem = $displayItem;
                $itemEnd = $startTs + max(1, intval($displayItem['idotartam_mp'] ?? 60));
                $countdownTs = $itemEnd;
                $phase = $itemEnd > $nowTs ? 'live' : 'ready';
                if ($phase !== 'live') {
                    $activeItem = null;
                    if ($currentIndex > 0) {
                        $displayItem = $items[$currentIndex - 1];
                        $nextItem = $pendingItem;
                    }
                }
            }
        }

        return [
            'multi' => $multi,
            'items' => $items,
            'display_item' => $displayItem,
            'active_item' => $activeItem,
            'next_item' => $nextItem,
            'phase' => $phase,
            'countdown_ts' => $countdownTs,
            'current_index' => $currentIndex,
            'total_count' => count($items),
            'vege_ts' => $countdownTs ?: $kezdesTs,
            'manual_start_required' => $phase === 'ready'
        ];
    }

    $gap = max(0, intval($multi['szunet_mp'] ?? 15));
    $cursor = $kezdesTs;
    $displayItem = $items[0];
    $activeItem = null;
    $nextItem = null;
    $phase = 'soon';
    $countdownTs = $kezdesTs;
    $currentIndex = 0;
    $talalat = false;

    foreach ($items as $index => $item) {
        $itemStart = $cursor;
        $itemEnd = $itemStart + max(1, intval($item['idotartam_mp'] ?? 60));

        if ($nowTs < $itemStart) {
            $displayItem = $item;
            $nextItem = $item;
            $phase = 'soon';
            $countdownTs = $itemStart;
            $currentIndex = $index;
            $talalat = true;
            break;
        }

        if ($nowTs < $itemEnd) {
            $displayItem = $item;
            $activeItem = $item;
            $nextItem = $items[$index + 1] ?? null;
            $phase = 'live';
            $countdownTs = $itemEnd;
            $currentIndex = $index;
            $talalat = true;
            break;
        }

        $nextStart = $itemEnd + $gap;
        if ($index < count($items) - 1 && $nowTs < $nextStart) {
            $displayItem = $items[$index + 1];
            $nextItem = $items[$index + 1];
            $phase = 'break';
            $countdownTs = $nextStart;
            $currentIndex = $index + 1;
            $talalat = true;
            break;
        }

        $cursor = $nextStart;
        $displayItem = $item;
        $currentIndex = $index;
    }

    if (!$talalat) {
        $phase = 'closed';
        $displayItem = end($items);
        $nextItem = null;
        $countdownTs = $cursor;
        $currentIndex = count($items) - 1;
    }

    $vegsoVege = $kezdesTs;
    foreach ($items as $idx => $item) {
        $vegsoVege += max(1, intval($item['idotartam_mp'] ?? 60));
        if ($idx < count($items) - 1) {
            $vegsoVege += $gap;
        }
    }

    return [
        'multi' => $multi,
        'items' => $items,
        'display_item' => $displayItem,
        'active_item' => $activeItem,
        'next_item' => $nextItem,
        'phase' => $phase,
        'countdown_ts' => $countdownTs,
        'current_index' => $currentIndex,
        'total_count' => count($items),
        'vege_ts' => $vegsoVege,
        'manual_start_required' => false
    ];
}

function formatKartyaIdo($masodperc) {
    $masodperc = max(0, intval($masodperc));
    $ora = floor($masodperc / 3600);
    $perc = floor(($masodperc % 3600) / 60);
    $mp = $masodperc % 60;
    if ($ora > 0) {
        return $ora . ':' . str_pad($perc, 2, '0', STR_PAD_LEFT) . ':' . str_pad($mp, 2, '0', STR_PAD_LEFT);
    }
    return str_pad($perc, 2, '0', STR_PAD_LEFT) . ':' . str_pad($mp, 2, '0', STR_PAD_LEFT);
}

function getOsszesKategoriaKep() {
    $path = __DIR__ . '/osszes_kategoria_kep.txt';
    if (!file_exists($path)) {
        return '';
    }
    return trim((string) file_get_contents($path));
}

function getKozelgoKategoriaKep() {
    $path = __DIR__ . '/kozelgo_kategoria_kep.txt';
    if (!file_exists($path)) {
        return '';
    }
    return trim((string) file_get_contents($path));
}

function getMostKategoriaKep() {
    $path = mostKategoriaKepPath();
    if (!file_exists($path)) {
        return '';
    }
    return trim((string) file_get_contents($path));
}

$kedvencekTablaVan = tablaLetezik($conn, 'kedvenc_felhasznalok');
$kuponKategoriakTablaVan = tablaLetezik($conn, 'kupon_kategoriak');
$kuponKategoriaIdOszlopVan = oszlopLetezik($conn, 'termekek', 'kupon_kategoria_id');
$osszesKategoriaKep = getOsszesKategoriaKep();
$kozelgoKategoriaKep = getKozelgoKategoriaKep();
$eloKategoriaKep = getEloKategoriaKep();
$kiemeltFelhasznaloOszlop = oszlopLetezik($conn, 'felhasznalok', 'kiemelt_felhasznalo');

if (
    $kedvencekTablaVan &&
    isset($_SESSION['user_id']) &&
    authValidateCsrfFromRequest() &&
    isset($_POST['kedvenc_muvelet']) &&
    isset($_POST['kedvenc_felhasznalo_id'])
) {
    $aktualisUserId = intval($_SESSION['user_id']);
    $kedvencFelhasznaloId = intval($_POST['kedvenc_felhasznalo_id']);
    $muvelet = $_POST['kedvenc_muvelet'];

    if ($kedvencFelhasznaloId > 0 && $kedvencFelhasznaloId !== $aktualisUserId) {
        if ($muvelet === 'hozzaad') {
            $conn->query("INSERT IGNORE INTO kedvenc_felhasznalok (felhasznalo_id, kedvenc_felhasznalo_id) VALUES ($aktualisUserId, $kedvencFelhasznaloId)");
        } elseif ($muvelet === 'torol') {
            $conn->query("DELETE FROM kedvenc_felhasznalok WHERE felhasznalo_id = $aktualisUserId AND kedvenc_felhasznalo_id = $kedvencFelhasznaloId");
        }
    }

    $vissza = $_SERVER['REQUEST_URI'] ?? 'index.php';
    header("Location: " . $vissza);
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$aktualisKatRaw = trim((string)($_GET['kat'] ?? ''));
$aktualis_kat = ctype_digit($aktualisKatRaw) ? intval($aktualisKatRaw) : 0;
$aktualisKatKod = in_array($aktualisKatRaw, ['kozelgo', 'elo', 'kupon'], true) ? $aktualisKatRaw : ($aktualis_kat > 0 ? (string) $aktualis_kat : '');
$elado_id = isset($_GET['elado']) ? intval($_GET['elado']) : 0;
$keresesRaw = trim($_GET['q'] ?? '');
$kereses = $conn->real_escape_string($keresesRaw);

$kategoriak = [];
$kategoriaSorrendOszlopVan = oszlopLetezik($conn, 'kategoriak', 'sorrend');
$kategoriaOrderBy = $kategoriaSorrendOszlopVan ? "COALESCE(sorrend, 9999) ASC, nev ASC" : "nev ASC";
$kategoriaRes = $conn->query("SELECT * FROM kategoriak ORDER BY $kategoriaOrderBy");
if ($kategoriaRes) {
    while ($k = $kategoriaRes->fetch_assoc()) {
        if (function_exists('shobidI18nCategoryName')) {
            $k['nev'] = shobidI18nCategoryName(intval($k['id'] ?? 0), (string)($k['nev'] ?? ''));
        }
        $kategoriak[] = $k;
    }
}

$kategoriakEloDb = [];
$mostMegyDb = 0;
$eloDb = 0;
$kozelgoDb = 0;
$kuponDb = 0;
$stripeFlashMessage = '';
$mostKategoriaKep = getMostKategoriaKep();
$kuponReszletekOszlopVan = oszlopLetezik($conn, 'termekek', 'kupon_reszletek');

if (isset($_GET['stripe'])) {
    $stripeState = trim((string)($_GET['stripe'] ?? ''));
    $stripeSessionId = trim((string)($_GET['stripe_session_id'] ?? ''));
    if ($stripeState === 'success' && $stripeSessionId !== '') {
        if (shobidStripeFixVasarlasSessionAlapjanTeljesites($conn, $stripeSessionId)) {
            $stripeFlashMessage = html_entity_decode("A fizet&eacute;s sikeres volt, a v&aacute;s&aacute;rl&aacute;s beker&uuml;lt a rendszerbe.", ENT_QUOTES, 'UTF-8');
        } else {
            $stripeFlashMessage = html_entity_decode("A fizet&eacute;s sikeres lehetett, de a rendel&eacute;s visszaigazol&aacute;sa m&eacute;g folyamatban van. Friss&iacute;ts r&aacute; p&aacute;r m&aacute;sodperc m&uacute;lva.", ENT_QUOTES, 'UTF-8');
        }
    } elseif ($stripeState === 'cancel') {
        $stripeFlashMessage = html_entity_decode("A Stripe fizet&eacute;s megszakadt.", ENT_QUOTES, 'UTF-8');
    }
}
$statMostTs = time();
$statRes = $conn->query("SELECT * FROM termekek WHERE eladva = 0 AND " . shobidMarketTermekWhere($conn, '', $aktivPiacKod));
if ($statRes) {
    while ($statTermek = $statRes->fetch_assoc()) {
        $tenylegesenElo = liveAktivOszlopVan($conn) && intval($statTermek['live_active'] ?? 0) === 1;
        $isMultiStat = (($statTermek['ajanlat_tipus'] ?? 'licit') === 'multi');
        if ($isMultiStat) {
            $multiStat = getMultiAukcioAdat($conn, $statTermek);
            $phase = (string)($multiStat['phase'] ?? 'closed');
            if ($phase === 'live' || $phase === 'ready') {
                $mostMegyDb++;
                if ($tenylegesenElo) {
                    $eloDb++;
                }
                $katId = intval($statTermek['kategoria_id'] ?? 0);
                if ($katId > 0) {
                    $kategoriakEloDb[$katId] = intval($kategoriakEloDb[$katId] ?? 0) + 1;
                }
            } elseif ($phase === 'soon') {
                $kozelgoDb++;
            }
            continue;
        }

        if (($statTermek['ajanlat_tipus'] ?? 'licit') === 'fix') {
            $kezdesTs = !empty($statTermek['kezdes_idopont']) ? strtotime((string)$statTermek['kezdes_idopont']) : 0;
            $lejaratTs = !empty($statTermek['lejarat_idopont']) ? strtotime((string)$statTermek['lejarat_idopont']) : 0;
            if ($kezdesTs > $statMostTs && $kezdesTs <= ($statMostTs + 86400)) {
                $kozelgoDb++;
            } elseif (intval($statTermek['darabszam'] ?? 0) > 0 && (!$lejaratTs || $lejaratTs > $statMostTs) && (!$kezdesTs || $kezdesTs <= $statMostTs)) {
                $mostMegyDb++;
                if ($tenylegesenElo) {
                    $eloDb++;
                }
                $katId = intval($statTermek['kategoria_id'] ?? 0);
                if ($katId > 0) {
                    $kategoriakEloDb[$katId] = intval($kategoriakEloDb[$katId] ?? 0) + 1;
                }
            }
            continue;
        }
        if (($statTermek['ajanlat_tipus'] ?? 'licit') === 'kupon') {
            $kezdesTs = !empty($statTermek['kezdes_idopont']) ? strtotime((string)$statTermek['kezdes_idopont']) : 0;
            $lejaratTs = !empty($statTermek['lejarat_idopont']) ? strtotime((string)$statTermek['lejarat_idopont']) : 0;
            if ($kezdesTs > $statMostTs && $kezdesTs <= ($statMostTs + 86400)) {
                $kozelgoDb++;
            } elseif (intval($statTermek['darabszam'] ?? 0) > 0 && (!$lejaratTs || $lejaratTs > $statMostTs) && (!$kezdesTs || $kezdesTs <= $statMostTs)) {
                $mostMegyDb++;
                $kuponDb++;
                if ($tenylegesenElo) {
                    $eloDb++;
                }
            }
            continue;
        }

        $singleStat = getSingleLicitAllapot($conn, $statTermek);
        if (($singleStat['allapot'] ?? '') === 'soon' && intval($singleStat['kezdes_ts'] ?? 0) <= ($statMostTs + 86400)) {
            $kozelgoDb++;
        } elseif (($singleStat['allapot'] ?? '') === 'live') {
            $mostMegyDb++;
            if ($tenylegesenElo) {
                $eloDb++;
            }
            $katId = intval($statTermek['kategoria_id'] ?? 0);
            if ($katId > 0) {
                $kategoriakEloDb[$katId] = intval($kategoriakEloDb[$katId] ?? 0) + 1;
            }
        }
    }
}

$aktualisFeedCim = function_exists('st') ? st('feed.title.now', 'Most') : 'Most';
if ($aktualisKatKod === 'kozelgo') {
    $aktualisFeedCim = function_exists('st') ? st('feed.title.upcoming', 'Kozelgo') : 'Kozelgo';
} elseif ($aktualisKatKod === 'elo') {
    $aktualisFeedCim = function_exists('st') ? st('feed.title.live', 'Elo') : 'Elo';
} elseif ($aktualisKatKod === 'kupon') {
    $aktualisFeedCim = function_exists('st') ? st('feed.title.coupons', 'Kuponok', $pageLocaleCode) : 'Kuponok';
} elseif ($aktualis_kat > 0) {
    foreach ($kategoriak as $kategoriaAdat) {
        if (intval($kategoriaAdat['id']) === $aktualis_kat) {
            $aktualisFeedCim = (string)$kategoriaAdat['nev'];
            break;
        }
    }
}

$sidebarUser = null;
$aktivUserProfilTeljes = false;
if (isset($_SESSION['user_id'])) {
    shobidStripeEnsureFelhasznaloFizetesiOszlopok($conn);
    if (!oszlopLetezik($conn, 'felhasznalok', 'aszf_elfogadva')) {
        $conn->query("ALTER TABLE felhasznalok ADD aszf_elfogadva TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!oszlopLetezik($conn, 'felhasznalok', 'fizetesi_szabalyzat_elfogadva')) {
        $conn->query("ALTER TABLE felhasznalok ADD fizetesi_szabalyzat_elfogadva TINYINT(1) NOT NULL DEFAULT 0");
    }
    $sidebarSelect = "becenev, profilkep" . ($kiemeltFelhasznaloOszlop ? ", kiemelt_felhasznalo" : "");
    $sidebarUserRes = $conn->query("SELECT $sidebarSelect FROM felhasznalok WHERE id = " . intval($_SESSION['user_id']));
    $sidebarUser = $sidebarUserRes ? $sidebarUserRes->fetch_assoc() : null;
    $aktivProfilRes = $conn->query("SELECT teljes_nev, lakcim, telefonszam, aszf_elfogadva, fizetesi_szabalyzat_elfogadva FROM felhasznalok WHERE id = " . intval($_SESSION['user_id']));
    $aktivProfil = $aktivProfilRes ? $aktivProfilRes->fetch_assoc() : null;
    $aktivUserProfilTeljes = $aktivProfil
      && !empty(trim((string)($aktivProfil['teljes_nev'] ?? '')))
      && !empty(trim((string)($aktivProfil['lakcim'] ?? '')))
      && !empty(trim((string)($aktivProfil['telefonszam'] ?? '')))
      && !empty($aktivProfil['aszf_elfogadva'])
      && !empty($aktivProfil['fizetesi_szabalyzat_elfogadva'])
      && shobidStripeFelhasznaloFizetesKesz($conn, intval($_SESSION['user_id']));
}

$termek = null;
$ajanlatok = [];
$eladoProfil = null;
$kedvencCelFelhasznaloId = 0;
$kedvencAzAktualisnak = false;
$multiAukcioAdat = null;
$mostMegyMenuAktiv = (!$termek && $elado_id === 0 && $aktualisKatKod === '');
$eloMenuAktiv = (!$termek && $elado_id === 0 && $aktualisKatKod === 'elo');
$kozelgoMenuAktiv = (!$termek && $elado_id === 0 && $aktualisKatKod === 'kozelgo');

if ($id > 0) {
    $kuponKatSelect = ($kuponKategoriakTablaVan && $kuponKategoriaIdOszlopVan) ? ", kk.nev AS kupon_kat_nev" : "";
    $kuponKatJoin = ($kuponKategoriakTablaVan && $kuponKategoriaIdOszlopVan) ? " LEFT JOIN kupon_kategoriak kk ON kk.id = t.kupon_kategoria_id " : "";
    $sql = "SELECT t.*, k.nev AS kat_nev$kuponKatSelect, f.becenev AS feltolto_neve, f.profilkep AS feltolto_profilkep" . ($kiemeltFelhasznaloOszlop ? ", f.kiemelt_felhasznalo AS feltolto_kiemelt" : "") . "
            FROM termekek t
            LEFT JOIN kategoriak k ON t.kategoria_id = k.id
            $kuponKatJoin
            LEFT JOIN felhasznalok f ON t.feltolto_id = f.id
            WHERE t.id = $id AND " . shobidMarketTermekWhere($conn, 't', $aktivPiacKod);
    $t_res = $conn->query($sql);
    $termek = $t_res ? $t_res->fetch_assoc() : null;
    if ($termek) {
        if (($termek['ajanlat_tipus'] ?? '') === 'kupon' && !empty($termek['kupon_kat_nev'])) {
            $termek['kat_nev'] = function_exists('shobidI18nResolveMaybeKey')
                ? shobidI18nResolveMaybeKey((string)$termek['kupon_kat_nev'], $pageLocaleCode, (function_exists('st') ? st('feed.category.fallback', 'Kategoria', $pageLocaleCode) : 'Kategoria'))
                : (string)$termek['kupon_kat_nev'];
        } elseif (function_exists('shobidI18nCategoryName')) {
            $termek['kat_nev'] = shobidI18nCategoryName(intval($termek['kategoria_id'] ?? 0), (string)($termek['kat_nev'] ?? ''));
        }
        $kedvencCelFelhasznaloId = intval($termek['feltolto_id']);
        $multiAukcioAdat = getMultiAukcioAdat($conn, $termek);
    }
} else {
    $most = date("Y-m-d H:i:s");
    $kuponKatSelect = ($kuponKategoriakTablaVan && $kuponKategoriaIdOszlopVan) ? ", kk.nev AS kupon_kat_nev" : "";
    $kuponKatJoin = ($kuponKategoriakTablaVan && $kuponKategoriaIdOszlopVan) ? " LEFT JOIN kupon_kategoriak kk ON kk.id = t.kupon_kategoria_id " : "";
    $sql = "SELECT t.*, k.nev AS kat_nev$kuponKatSelect, f.becenev AS feltolto_neve, f.profilkep AS feltolto_profilkep" . ($kiemeltFelhasznaloOszlop ? ", f.kiemelt_felhasznalo AS feltolto_kiemelt" : "") . "
            FROM termekek t
            LEFT JOIN kategoriak k ON t.kategoria_id = k.id
            $kuponKatJoin
            LEFT JOIN felhasznalok f ON t.feltolto_id = f.id";

    $where = [];
    $manualLicitLiveSql = "0";
    if (licitStartedOszlopVan($conn)) {
        $manualLicitLiveSql = "((t.ajanlat_tipus IS NULL OR t.ajanlat_tipus = 'licit') AND (t.kezdes_idopont IS NULL OR t.kezdes_idopont = '' OR t.kezdes_idopont <= '$most') AND (t.licit_started_at IS NULL OR t.licit_started_at = '0000-00-00 00:00:00') AND (t.lejarat_idopont IS NULL OR t.lejarat_idopont = '' OR t.lejarat_idopont > '$most'))";
    }
	$mostSql = "t.eladva = 0 AND (
    (t.ajanlat_tipus = 'multi' AND (t.kezdes_idopont IS NULL OR t.kezdes_idopont = '' OR t.kezdes_idopont <= '$most'))
    OR (t.ajanlat_tipus = 'fix' AND (t.kezdes_idopont IS NULL OR t.kezdes_idopont = '' OR t.kezdes_idopont <= '$most') AND (t.lejarat_idopont IS NULL OR t.lejarat_idopont = '' OR t.lejarat_idopont > '$most'))
    OR (t.ajanlat_tipus = 'kupon' AND (t.kezdes_idopont IS NULL OR t.kezdes_idopont = '' OR t.kezdes_idopont <= '$most') AND (t.lejarat_idopont IS NULL OR t.lejarat_idopont = '' OR t.lejarat_idopont > '$most') AND COALESCE(t.darabszam, 0) > 0)
    OR ((t.ajanlat_tipus IS NULL OR t.ajanlat_tipus = 'licit') AND (((t.lejarat_idopont IS NOT NULL AND t.lejarat_idopont <> '' AND t.lejarat_idopont > '$most') AND (t.kezdes_idopont IS NULL OR t.kezdes_idopont = '' OR t.kezdes_idopont <= '$most')) OR $manualLicitLiveSql))
)";
	$eloAktivSql = liveAktivOszlopVan($conn) ? "t.live_active = 1" : "0";
    $eloSql = "$mostSql AND $eloAktivSql";
    $kozelgoSql = "t.eladva = 0 AND t.kezdes_idopont > '$most' AND t.kezdes_idopont <= DATE_ADD('$most', INTERVAL 24 HOUR)";

	if ($aktualisKatKod === 'kozelgo') {
		$where[] = $kozelgoSql;
	} elseif ($aktualisKatKod === 'elo') {
		$where[] = $eloSql;
    } elseif ($aktualisKatKod === 'kupon') {
        $where[] = "t.ajanlat_tipus = 'kupon'";
        $where[] = "t.eladva = 0";
        $where[] = "COALESCE(t.darabszam, 0) > 0";
        $where[] = "(t.kezdes_idopont IS NULL OR t.kezdes_idopont = '' OR t.kezdes_idopont <= '$most')";
        $where[] = "(t.lejarat_idopont IS NULL OR t.lejarat_idopont = '' OR t.lejarat_idopont > '$most')";
    } elseif ($aktualis_kat > 0) {
        $where[] = "t.kategoria_id = $aktualis_kat";
        $where[] = "($mostSql OR $kozelgoSql)";
    } elseif ($elado_id === 0) {
        $where[] = $mostSql;
    }
    if ($keresesRaw !== '') {
    $kuponReszletekWhere = $kuponReszletekOszlopVan ? " OR (t.ajanlat_tipus = 'kupon' AND t.kupon_reszletek LIKE '%$kereses%')" : "";
    $where[] = "(
        t.nev LIKE '%$kereses%'
        OR t.leiras LIKE '%$kereses%'
        OR ((t.ajanlat_tipus = 'fix' OR t.ajanlat_tipus = 'kupon') AND t.varos LIKE '%$kereses%')
        $kuponReszletekWhere
    )";
    }
    if ($elado_id > 0) {
        $where[] = "t.feltolto_id = $elado_id";
        $eladoSelect = "id, becenev, profilkep" . ($kiemeltFelhasznaloOszlop ? ", kiemelt_felhasznalo" : "");
        $eladoRes = $conn->query("SELECT $eladoSelect FROM felhasznalok WHERE id = $elado_id");
        $eladoProfil = $eladoRes ? $eladoRes->fetch_assoc() : null;
        $kedvencCelFelhasznaloId = $elado_id;
    }
    if ($elado_id === 0) {
        $where[] = "t.eladva = 0";
    }
    $where[] = shobidMarketTermekWhere($conn, 't', $aktivPiacKod);
    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY
              CASE
                WHEN (t.eladva = 0 AND (t.kezdes_idopont IS NULL OR t.kezdes_idopont = '' OR t.kezdes_idopont <= '$most') AND t.lejarat_idopont > '$most') THEN 0
                WHEN (t.eladva = 0 AND t.kezdes_idopont > '$most') THEN 1
                ELSE 2
              END,
              CASE
                WHEN (t.eladva = 0 AND (t.kezdes_idopont IS NULL OR t.kezdes_idopont = '' OR t.kezdes_idopont <= '$most') AND t.lejarat_idopont > '$most') THEN t.lejarat_idopont
                WHEN (t.eladva = 0 AND t.kezdes_idopont > '$most') THEN t.kezdes_idopont
                ELSE t.lejarat_idopont
              END ASC,
              t.id DESC";

    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (($row['ajanlat_tipus'] ?? '') === 'kupon' && !empty($row['kupon_kat_nev'])) {
                $row['kat_nev'] = function_exists('shobidI18nResolveMaybeKey')
                    ? shobidI18nResolveMaybeKey((string)$row['kupon_kat_nev'], $pageLocaleCode, (function_exists('st') ? st('feed.category.fallback', 'Kategoria', $pageLocaleCode) : 'Kategoria'))
                    : (string)$row['kupon_kat_nev'];
            } elseif (function_exists('shobidI18nCategoryName')) {
                $row['kat_nev'] = shobidI18nCategoryName(intval($row['kategoria_id'] ?? 0), (string)($row['kat_nev'] ?? ''));
            }
            $ajanlatok[] = $row;
        }
    }
}

if (
    $kedvencekTablaVan &&
    isset($_SESSION['user_id']) &&
    $kedvencCelFelhasznaloId > 0 &&
    $kedvencCelFelhasznaloId !== intval($_SESSION['user_id'])
) {
    $kedvencRes = $conn->query("SELECT 1 FROM kedvenc_felhasznalok WHERE felhasznalo_id = " . intval($_SESSION['user_id']) . " AND kedvenc_felhasznalo_id = $kedvencCelFelhasznaloId LIMIT 1");
    $kedvencAzAktualisnak = $kedvencRes && $kedvencRes->num_rows > 0;
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $termek ? htmlspecialchars($termek['nev']) : 'SHOBID'; ?></title>
<link rel="stylesheet" href="/style.css?v=20260416-restore4">
</head>
<body class="<?php echo $termek ? 'auction-detail-page' : 'auction-feed-page'; ?>">

<div class="auction-shell">
  <?php
  $sidebarActivePage = '';
  $sidebarSearchAction = 'index.php';
  $sidebarIndexBase = 'index.php';
  include __DIR__ . '/auction_sidebar.php';
  ?>

  <?php if ($termek): ?>
  <?php
    $detailTipusText = (($termek['ajanlat_tipus'] ?? 'licit') === 'fix')
      ? 'FIX &Aacute;R'
      : ((($termek['ajanlat_tipus'] ?? 'licit') === 'multi') ? 'LICIT SHOP' : ((($termek['ajanlat_tipus'] ?? 'licit') === 'kupon') ? 'KUPON' : 'LICIT'));
    $detailLiveTopVisible = !empty($termek['live_active']);
  ?>
  <div class="detail-mobile-topbar">
    <div class="detail-mobile-topbar__main">
      <a class="detail-seller detail-seller--link" href="index.php?elado=<?php echo intval($termek['feltolto_id']); ?>">
      <?php if (!empty($termek['feltolto_profilkep'])): ?>
      <img class="detail-avatar detail-avatar--image <?php echo !empty($termek['feltolto_kiemelt']) ? 'is-featured-user' : ''; ?>" src="/profilkepek/<?php echo htmlspecialchars($termek['feltolto_profilkep']); ?>" alt="<?php echo htmlspecialchars($termek['feltolto_neve'] ?: 'Elad&oacute;'); ?>">
      <?php else: ?>
      <div class="detail-avatar <?php echo !empty($termek['feltolto_kiemelt']) ? 'is-featured-user' : ''; ?>"><?php echo strtoupper(substr($termek['feltolto_neve'] ?: 'A', 0, 1)); ?></div>
      <?php endif; ?>
      <div>
        <div class="detail-seller__name"><?php echo htmlspecialchars($termek['feltolto_neve'] ?: 'Elad&oacute;'); ?></div>
      </div>
      </a>
      <div class="detail-mobile-typebar">
        <span class="detail-mobile-typebar__label" id="detail-mobile-type-label"><?php echo $detailTipusText; ?></span>
        <span class="detail-live-flag detail-live-flag--mobile" id="detail-live-flag-mobile" <?php echo $detailLiveTopVisible ? '' : 'style="display:none;"'; ?>>
          <span class="detail-live-flag__dot" aria-hidden="true"></span>
          <span>&Eacute;L&#336;</span>
        </span>
      </div>
    </div>
    <div class="detail-header-actions">
      <div class="detail-share-wrap">
        <button type="button" class="detail-actionbtn" aria-label="Megoszt&aacute;s" onclick="copyShareLink(event)"><img class="detail-actionbtn__icon" src="/kepek/icons/share.webp" alt=""></button>
      </div>
      <?php if ($kedvencekTablaVan && isset($_SESSION['user_id']) && intval($_SESSION['user_id']) !== intval($termek['feltolto_id'])): ?>
      <form method="POST" class="favorite-user-form favorite-user-form--icon">
        <input type="hidden" name="kedvenc_felhasznalo_id" value="<?php echo intval($termek['feltolto_id']); ?>">
        <input type="hidden" name="kedvenc_muvelet" value="<?php echo $kedvencAzAktualisnak ? 'torol' : 'hozzaad'; ?>">
        <button type="submit" class="detail-actionbtn detail-actionbtn--favorite <?php echo $kedvencAzAktualisnak ? 'is-active' : ''; ?>" aria-label="Kedvenc">
          <img class="detail-actionbtn__icon" src="<?php echo $kedvencAzAktualisnak ? '/kepek/icons/fav_out.webp' : '/kepek/icons/fav_add.webp'; ?>" alt="">
        </button>
      </form>
      <?php else: ?>
      <button type="button" class="detail-actionbtn detail-actionbtn--favorite" aria-label="Kedvenc"><img class="detail-actionbtn__icon" src="/kepek/icons/fav_add.webp" alt=""></button>
      <?php endif; ?>
    </div>
  </div>
  <main class="auction-detail-layout">
    <?php if ($stripeFlashMessage !== ''): ?>
    <div class="profile-alert"><?php echo htmlspecialchars($stripeFlashMessage); ?></div>
    <?php endif; ?>
    <section class="detail-media-panel">
      <div class="detail-media-frame">
        <div class="detail-media-top">
          <a class="detail-back" href="index.php<?php echo $termek['kategoria_id'] ? '?kat=' . intval($termek['kategoria_id']) : ''; ?>">&#10005;</a>
        </div>
        <div class="detail-center-countdown" id="detail-center-countdown" style="display:none;"></div>
        <div id="media-content" class="detail-media-content">
          <?php if (!empty($termek['kep_url'])): ?>
          <img src="/kepek/<?php echo rawurlencode((string)$termek['kep_url']); ?>" alt="TermĂ©k kĂ©p" onerror="this.onerror=null;this.src='/kepek/nincs_kep.jpg';">
          <?php else: ?>
          <div class="live-no-media">Nincs feltĂ¶ltĂ¶tt mĂ©dia</div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="detail-info-panel" data-offer-type="<?php echo htmlspecialchars($termek['ajanlat_tipus'] ?? 'licit'); ?>">
      <div class="detail-seller-row">
        <a class="detail-seller detail-seller--link" href="index.php?elado=<?php echo intval($termek['feltolto_id']); ?>">
          <?php if (!empty($termek['feltolto_profilkep'])): ?>
          <img class="detail-avatar detail-avatar--image <?php echo !empty($termek['feltolto_kiemelt']) ? 'is-featured-user' : ''; ?>" src="/profilkepek/<?php echo htmlspecialchars($termek['feltolto_profilkep']); ?>" alt="<?php echo htmlspecialchars($termek['feltolto_neve'] ?: 'Elad&oacute;'); ?>">
          <?php else: ?>
          <div class="detail-avatar <?php echo !empty($termek['feltolto_kiemelt']) ? 'is-featured-user' : ''; ?>"><?php echo strtoupper(substr($termek['feltolto_neve'] ?: 'A', 0, 1)); ?></div>
          <?php endif; ?>
          <div>
            <div class="detail-seller__name"><?php echo htmlspecialchars($termek['feltolto_neve'] ?: 'Elad&oacute;'); ?></div>
            <div class="detail-seller__meta"><?php echo htmlspecialchars($termek['kat_nev'] ?: (function_exists('st') ? st('feed.category.fallback', 'Kategoria') : 'Kategoria')); ?></div>
          </div>
        </a>
        <div class="detail-header-actions">
          <div class="detail-share-wrap">
            <button type="button" class="detail-actionbtn" id="detail-share-btn" aria-label="Megoszt&aacute;s" onclick="copyShareLink(event)"><img class="detail-actionbtn__icon" src="/kepek/icons/share.webp" alt=""></button>
          </div>
          <?php if ($kedvencekTablaVan && isset($_SESSION['user_id']) && intval($_SESSION['user_id']) !== intval($termek['feltolto_id'])): ?>
          <form method="POST" class="favorite-user-form favorite-user-form--icon">
            <input type="hidden" name="kedvenc_felhasznalo_id" value="<?php echo intval($termek['feltolto_id']); ?>">
            <input type="hidden" name="kedvenc_muvelet" value="<?php echo $kedvencAzAktualisnak ? 'torol' : 'hozzaad'; ?>">
            <button type="submit" class="detail-actionbtn detail-actionbtn--favorite <?php echo $kedvencAzAktualisnak ? 'is-active' : ''; ?>" aria-label="Kedvenc">
              <img class="detail-actionbtn__icon" src="<?php echo $kedvencAzAktualisnak ? '/kepek/icons/fav_out.webp' : '/kepek/icons/fav_add.webp'; ?>" alt="">
            </button>
          </form>
          <?php else: ?>
          <button type="button" class="detail-actionbtn detail-actionbtn--favorite" aria-label="Kedvenc"><img class="detail-actionbtn__icon" src="/kepek/icons/fav_add.webp" alt=""></button>
          <?php endif; ?>
        </div>
      </div>
      <?php $aktualisReszTetel = $multiAukcioAdat['display_item'] ?? null; ?>
      <?php
        $detailArKezdo = $multiAukcioAdat ? intval($aktualisReszTetel['aktualis_ar'] ?? 0) : intval($termek['aktualis_ar'] ?? 0);
        $detailLicitLepcsoKezdo = $multiAukcioAdat ? intval($aktualisReszTetel['licit_lepcso'] ?? 500) : intval($termek['licit_lepcso'] ?? 500);
        if ($detailLicitLepcsoKezdo <= 0) {
            $detailLicitLepcsoKezdo = 500;
        }
        $detailLicitaloKezdo = trim((string)($multiAukcioAdat ? ($aktualisReszTetel['legmagasabb_licit_felhasznalo'] ?? '') : ($termek['legmagasabb_licit_felhasznalo'] ?? '')));
        $detailBidderKezdo = $detailLicitaloKezdo !== '' ? ('Nyertes ' . $detailLicitaloKezdo) : 'MĂ©g nincs licit';
        $detailKovetkezoLicitKezdo = $detailArKezdo + $detailLicitLepcsoKezdo;
      ?>
      <div class="detail-info-top">
        <div class="detail-multi-badge">
          <span id="detail-type-label"><?php echo $detailTipusText; ?></span>
          <span class="detail-live-flag" id="detail-live-flag" style="display:none;">
            <span class="detail-live-flag__dot" aria-hidden="true"></span>
            <span>&Eacute;L&#336;</span>
          </span>
        </div>
        <div class="detail-categoryline" id="detail-categoryline"><?php echo htmlspecialchars($termek['kat_nev'] ?: (function_exists('st') ? st('feed.category.fallback', 'Kategoria') : 'Kategoria')); ?></div>
        <h1 class="detail-title" id="detail-parent-title"><?php echo htmlspecialchars($termek['nev']); ?></h1>
        <?php if ($multiAukcioAdat): ?>
        <div class="detail-multi-itemname" id="detail-multi-itemname"><?php echo htmlspecialchars($aktualisReszTetel['nev'] ?? ''); ?></div>
        <?php endif; ?>
        <?php $reszletesLeiras = trim((string)($multiAukcioAdat ? ($aktualisReszTetel['leiras'] ?? '') : ($termek['leiras'] ?? ''))); ?>
        <?php $kuponReszletekSzoveg = trim((string)($termek['kupon_reszletek'] ?? '')); ?>
        <?php if ($reszletesLeiras !== '' || $multiAukcioAdat): ?>
        <div class="detail-copy-wrap" id="detail-copy-wrap" <?php echo $reszletesLeiras === '' ? 'style="display:none;"' : ''; ?>>
          <p class="detail-copy is-collapsed" id="detail-copy"><?php echo nl2br(htmlspecialchars($reszletesLeiras)); ?></p>
          <button type="button" class="detail-copy-toggle" id="detail-copy-toggle" onclick="toggleDetailCopy()" style="display:none;">t&ouml;bb...</button>
        </div>
        <?php endif; ?>
        <?php if (($termek['ajanlat_tipus'] ?? '') === 'kupon' && $kuponReszletekSzoveg !== ''): ?>
        <div style="margin-top:10px;">
          <button type="button" class="profile-submit profile-submit--compact" onclick="openKuponReszletekModal()"><?php echo htmlspecialchars(st('feed.coupon.details', 'Reszletek', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
        </div>
        <?php endif; ?>
        <?php $reszSzallitasMod = $multiAukcioAdat ? ($aktualisReszTetel['szallitasi_mod'] ?? '') : ($termek['szallitasi_mod'] ?? ''); ?>
        <?php $reszSzallitasDij = $multiAukcioAdat ? intval($aktualisReszTetel['szallitasi_dij'] ?? 0) : intval($termek['szallitasi_dij'] ?? 0); ?>
        <?php $kuponBevaltasVege = trim((string)($termek['kupon_bevaltas_vege'] ?? '')); ?>
        <?php if (($termek['ajanlat_tipus'] ?? '') === 'kupon' && $kuponBevaltasVege !== ''): ?>
        <div class="detail-shipping" id="detail-shipping"><?php echo htmlspecialchars(st('feed.coupon.redeem_until', 'Bevaltas vege', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>: <?php echo htmlspecialchars($kuponBevaltasVege); ?></div>
        <?php elseif ($reszSzallitasMod === 'ingyenes'): ?>
        <div class="detail-shipping" id="detail-shipping">Sz&aacute;ll&iacute;t&aacute;si d&iacute;j: Ingyenes sz&aacute;ll&iacute;t&aacute;s</div>
        <?php elseif ($reszSzallitasMod === 'egyedi' && $reszSzallitasDij > 0): ?>
        <div class="detail-shipping" id="detail-shipping">Sz&aacute;ll&iacute;t&aacute;si d&iacute;j: <?php echo number_format($reszSzallitasDij, 0, ',', ' '); ?> Ft</div>
        <?php else: ?>
        <div class="detail-shipping" id="detail-shipping" style="display:none;"></div>
        <?php endif; ?>
        <?php $fixVaros = trim((string)($termek['varos'] ?? '')); ?>
        <div class="detail-city" id="detail-city" <?php echo ((in_array(($termek['ajanlat_tipus'] ?? 'licit'), ['fix', 'kupon'], true)) && $fixVaros !== '') ? '' : 'style="display:none;"'; ?>>
          <img class="detail-city__icon" src="/kepek/icons/map.webp" alt="">
          <span id="detail-city-text"><?php echo htmlspecialchars($fixVaros); ?></span>
        </div>
        <div class="live-badge" id="auction-status">Bet&ouml;lt&eacute;s...</div>
      </div>

      <div class="detail-info-bottom">
        <div class="detail-leading" id="bidder"><?php echo htmlspecialchars($detailBidderKezdo); ?></div>
        <div class="detail-pricewrap">
          <div class="detail-label" id="original-price-label" style="display:none;">Eredeti &aacute;r:</div>
          <div class="detail-step" id="original-price" style="display:none;">... Ft</div>
          <div class="detail-label" id="current-price-label">Aktu&aacute;lis aj&aacute;nlat</div>
          <div class="detail-price" id="price"><?php echo number_format($detailArKezdo, 0, ' ', ' '); ?> Ft</div>
        </div>
        <div class="detail-timer" id="countdown">--:--</div>
        <?php if ($multiAukcioAdat): ?>
        <div class="detail-next-auction" id="next-auction-countdown"></div>
        <?php endif; ?>

      <?php if (isset($_SESSION['user_id']) && $aktivUserProfilTeljes): ?>
      <div class="detail-bidbox">
        <div class="detail-bidbox__label">K&ouml;vetkez&#337; licit</div>
        <div class="detail-step" id="min-bid-text">Licitl&eacute;pcs&#337;: <?php echo number_format($detailLicitLepcsoKezdo, 0, ' ', ' '); ?> Ft</div>
        <div class="detail-bidbox__row">
          <div id="next-bid-value" class="detail-nextbid"><?php echo number_format($detailKovetkezoLicitKezdo, 0, ' ', ' '); ?> Ft</div>
          <button class="detail-bidbtn" type="button" id="bid-button" onclick="doLicit()">
            <img class="detail-bidbtn__icon" src="/kepek/icons/3arrow.webp" alt="">
            <span id="bid-button-text">Licit&aacute;lok</span>
          </button>
        </div>
        <div class="detail-payment-note" id="detail-payment-note" <?php echo (((in_array(($termek['ajanlat_tipus'] ?? 'licit'), ['fix', 'kupon'], true)) || (isset($_SESSION['user_id']) && intval($_SESSION['user_id']) === intval($termek['feltolto_id'] ?? 0)))) ? 'style="display:none;"' : ''; ?>>
          A sikeres licit&aacute;l&aacute;s ut&aacute;n az &ouml;sszeg automatikusan levon&oacute;dik... <button class="detail-payment-note__more" type="button" onclick="openBidInfoModal()">t&ouml;bb</button>
        </div>
      </div>
      <div class="live-bottom__login-hint" id="owner-bid-hint" style="display:none;">
        Ez a saj&aacute;t aukci&oacute;d, ez&eacute;rt licit&aacute;lni nem tudsz, de chatelni igen.
      </div>
      <div class="detail-owner-live" id="detail-owner-live-wrap" style="display:none;">
        <button class="detail-bidbtn" type="button" id="live-toggle-button" onclick="doLiveToggle()">
          <img class="detail-bidbtn__icon" src="/kepek/icons/3arrow.webp" alt="">
          <span id="live-toggle-button-text">LIVE START</span>
        </button>
      </div>
      <div class="site-modal" id="fix-purchase-modal" aria-hidden="true">
        <div class="site-modal__backdrop" onclick="closeFixPurchaseModal(false)"></div>
        <div class="site-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="fix-purchase-modal-title">
          <div class="site-modal__kicker">V&aacute;s&aacute;rl&aacute;s meger&#337;s&iacute;t&eacute;se</div>
          <h3 class="site-modal__title" id="fix-purchase-modal-title">Biztos megveszed?</h3>
          <p class="site-modal__text">Ez fizet&eacute;si k&ouml;telezetts&eacute;ggel j&aacute;r.</p>
          <p class="site-modal__text">Az Igen gombra kattintva automatikusan elfogadod a v&aacute;s&aacute;rl&aacute;si felt&eacute;teleket.</p>
          <div class="site-modal__actions">
            <button class="create-cancel" type="button" onclick="closeFixPurchaseModal(false)">M&eacute;gse</button>
            <button class="profile-submit" type="button" onclick="confirmFixPurchase()">Igen, megveszem</button>
          </div>
        </div>
      </div>
      <div class="site-modal" id="bid-info-modal" aria-hidden="true">
        <div class="site-modal__backdrop" onclick="closeBidInfoModal()"></div>
        <div class="site-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="bid-info-modal-title">
          <h3 class="site-modal__title" id="bid-info-modal-title">Licit&aacute;l&aacute;si tudnival&oacute;</h3>
          <p class="site-modal__text">A sikeres licit&aacute;l&aacute;s ut&aacute;n az &ouml;sszeg automatikusan levon&oacute;dik a bankk&aacute;rty&aacute;dr&oacute;l egy elk&uuml;l&ouml;n&iacute;tett sz&aacute;ml&aacute;ra, mely csak akkor ker&uuml;l kik&uuml;ld&eacute;sre az elad&oacute;nak, ha megkaptad a v&aacute;s&aacute;rl&aacute;sod!</p>
          <div class="site-modal__actions">
            <button class="profile-submit" type="button" onclick="closeBidInfoModal()">Bez&aacute;r</button>
          </div>
        </div>
      </div>
      <?php if (($termek['ajanlat_tipus'] ?? '') === 'kupon' && $kuponReszletekSzoveg !== ''): ?>
      <div class="site-modal" id="kupon-reszletek-modal" aria-hidden="true">
        <div class="site-modal__backdrop" onclick="closeKuponReszletekModal()"></div>
        <div class="site-modal__dialog site-modal__dialog--light" role="dialog" aria-modal="true" aria-labelledby="kupon-reszletek-title">
          <h3 class="site-modal__title site-modal__title--dark" id="kupon-reszletek-title"><?php echo htmlspecialchars(st('feed.coupon.details', 'Reszletek', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></h3>
          <div class="site-modal__text site-modal__text--dark" style="max-height:52vh;overflow:auto;"><?php echo nl2br(htmlspecialchars($kuponReszletekSzoveg)); ?></div>
          <div class="site-modal__actions site-modal__actions--center">
            <button class="site-modal__button" type="button" onclick="closeKuponReszletekModal()"><?php echo htmlspecialchars(st('common.close', 'Bezar', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <?php elseif (isset($_SESSION['user_id'])): ?>
      <div class="live-bottom__login-hint">
        Licit&aacute;l&aacute;shoz &eacute;s chatel&eacute;shez el&#337;bb t&ouml;ltsd ki a teljes adataidat, majd ments bankk&aacute;rty&aacute;t a Stripe rendszer&eacute;ben.
        <a href="profil.php?complete_profile=1#bankkartya">Profil &eacute;s fizet&eacute;si be&aacute;ll&iacute;t&aacute;sok</a>
      </div>
      <?php else: ?>
      <div class="live-bottom__login-hint">
        Licit&aacute;l&aacute;shoz jelentkezz be.
        <a href="bejelentezes.php">Bejelentkez&eacute;s</a>
      </div>
      <?php endif; ?>
      </div>
    </section>

    <aside class="detail-comments-panel">
      <div class="detail-comments-header">
        <span>Hozz&aacute;sz&oacute;l&aacute;sok</span>
        <span id="comments-count">0</span>
      </div>
      <div class="chat detail-comments-list" id="chat-box"></div>

      <?php if (isset($_SESSION['user_id']) && $aktivUserProfilTeljes): ?>
      <div class="detail-comment-compose">
        <div class="detail-comment-inputwrap">
          <input
            type="text"
            id="chat_val"
            class="live-composer__input"
            placeholder="Hozz&aacute;sz&oacute;l&aacute;s"
            inputmode="text"
            onkeydown="if(event.key==='Enter') doChat()"
          >
          <button class="detail-emoji-btn" type="button" aria-label="Emoji" id="detail-emoji-btn" onclick="toggleEmojiPanel(event)"><img class="detail-emoji-btn__icon" src="/kepek/icons/smile.webp" alt=""></button>
          <div class="detail-emoji-panel" id="detail-emoji-panel">
            <div class="detail-emoji-panel__header">
              <span>Choose emoji</span>
              <button type="button" onclick="closeEmojiPanel()">&times;</button>
            </div>
            <div class="detail-emoji-panel__body">
              <div class="detail-emoji-group">
                <div class="detail-emoji-group__title">Frequently used</div>
                <div class="detail-emoji-grid">
                  <button type="button" onclick="insertEmoji('\uD83D\uDD25')">&#128293;</button>
                  <button type="button" onclick="insertEmoji('\u2764\uFE0F')">&#10084;&#65039;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDC4F')">&#128079;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE02')">&#128514;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE0D')">&#128525;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDC4D')">&#128077;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE4F')">&#128591;</button>
                  <button type="button" onclick="insertEmoji('\uD83C\uDF89')">&#127881;</button>
                  <button type="button" onclick="insertEmoji('\uD83E\uDD70')">&#129392;</button>
                  <button type="button" onclick="insertEmoji('\uD83E\uDD29')">&#129321;</button>
                </div>
              </div>
              <div class="detail-emoji-group">
                <div class="detail-emoji-group__title">Smiley & emotions</div>
                <div class="detail-emoji-grid">
                  <button type="button" onclick="insertEmoji('\uD83D\uDE00')">&#128512;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE03')">&#128515;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE04')">&#128516;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE01')">&#128513;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE06')">&#128518;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE05')">&#128517;</button>
                  <button type="button" onclick="insertEmoji('\uD83E\uDD23')">&#129315;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE0A')">&#128522;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE09')">&#128521;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE18')">&#128536;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE0E')">&#128526;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE07')">&#128519;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE42')">&#128578;</button>
                  <button type="button" onclick="insertEmoji('\uD83E\uDD17')">&#129303;</button>
                  <button type="button" onclick="insertEmoji('\uD83E\uDD14')">&#129300;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE2D')">&#128557;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE21')">&#128545;</button>
                  <button type="button" onclick="insertEmoji('\uD83E\uDD7A')">&#129402;</button>
                </div>
              </div>
              <div class="detail-emoji-group">
                <div class="detail-emoji-group__title">Symbols & reactions</div>
                <div class="detail-emoji-grid">
                  <button type="button" onclick="insertEmoji('\uD83D\uDCAF')">&#128175;</button>
                  <button type="button" onclick="insertEmoji('\u2728')">&#10024;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDCA5')">&#128165;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDCB8')">&#128184;</button>
                  <button type="button" onclick="insertEmoji('\uD83C\uDFC6')">&#127942;</button>
                  <button type="button" onclick="insertEmoji('\uD83C\uDFAF')">&#127919;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDE80')">&#128640;</button>
                  <button type="button" onclick="insertEmoji('\u2B50')">&#11088;</button>
                  <button type="button" onclick="insertEmoji('\u2705')">&#9989;</button>
                  <button type="button" onclick="insertEmoji('\uD83D\uDC40')">&#128064;</button>
                </div>
              </div>
            </div>
          </div>
        </div>
        <button class="detail-sendbtn" type="button" onclick="doChat()"><img class="detail-sendbtn__icon" src="/kepek/icons/send.webp" alt=""></button>
      </div>
      <?php elseif (isset($_SESSION['user_id'])): ?>
      <div class="live-bottom__login-hint">
        A chat haszn&aacute;lat&aacute;hoz t&ouml;ltsd ki a teljes adataidat, majd ments bankk&aacute;rty&aacute;t a Stripe rendszer&eacute;ben.
        <a href="profil.php?complete_profile=1#bankkartya">Profil &eacute;s fizet&eacute;si be&aacute;ll&iacute;t&aacute;sok</a>
      </div>
      <?php else: ?>
      <div class="live-bottom__login-hint">
        A chat haszn&aacute;lat&aacute;hoz jelentkezz be.
        <a href="bejelentezes.php">Bejelentkez&eacute;s</a>
      </div>
      <?php endif; ?>
    </aside>
  </main>
  <?php else: ?>
  <main class="auction-feed-main">
    <?php if ($stripeFlashMessage !== ''): ?>
    <div class="profile-alert"><?php echo htmlspecialchars($stripeFlashMessage); ?></div>
    <?php endif; ?>
    <div class="auction-feed-scroll" id="feed-scroll-area">
      <section class="auction-category-strip-shell" id="mobile-categories">
        <button type="button" class="auction-category-strip__arrow is-left" onclick="scrollCategoryStrip(-1)" aria-label="Kateg&oacute;ri&aacute;k balra"><img class="auction-arrow-icon" src="/kepek/icons/left.webp" alt=""></button>
        <div class="auction-category-strip" id="auction-category-strip">
          <a class="auction-category-tile <?php echo $aktualisKatKod === '' ? 'is-active' : ''; ?><?php echo !empty($mostKategoriaKep) ? ' has-image' : ''; ?>" href="index.php<?php echo ($elado_id > 0 || $keresesRaw !== '') ? '?' . http_build_query(array_filter(['elado' => $elado_id > 0 ? $elado_id : null, 'q' => $keresesRaw !== '' ? $keresesRaw : null])) : ''; ?>"<?php echo !empty($mostKategoriaKep) ? ' style="background-image: url(/kepek/' . rawurlencode($mostKategoriaKep) . '), linear-gradient(325deg, #f5f5f505, #f5f5f550);"' : ''; ?>>
            <span class="auction-category-tile__count"><?php echo intval($mostMegyDb); ?></span>
            <span class="auction-category-tile__name">Most</span>
          </a>
          <a class="auction-category-tile auction-category-tile--live <?php echo $aktualisKatKod === 'elo' ? 'is-active' : ''; ?><?php echo !empty($eloKategoriaKep) ? ' has-image' : ''; ?>" href="index.php?<?php echo http_build_query(array_filter(['elado' => $elado_id > 0 ? $elado_id : null, 'kat' => 'elo', 'q' => $keresesRaw !== '' ? $keresesRaw : null])); ?>"<?php echo !empty($eloKategoriaKep) ? ' style="background-image: url(/kepek/' . rawurlencode($eloKategoriaKep) . '), linear-gradient(325deg, #fa4230, #fe3f3415);"' : ''; ?>>
            <span class="auction-category-tile__count"><?php echo intval($eloDb); ?></span>
            <span class="auction-category-tile__name">&Eacute;l&#337;</span>
          </a>
          <a class="auction-category-tile <?php echo $aktualisKatKod === 'kozelgo' ? 'is-active' : ''; ?><?php echo !empty($kozelgoKategoriaKep) ? ' has-image' : ''; ?>" href="index.php?<?php echo http_build_query(array_filter(['elado' => $elado_id > 0 ? $elado_id : null, 'kat' => 'kozelgo', 'q' => $keresesRaw !== '' ? $keresesRaw : null])); ?>"<?php echo !empty($kozelgoKategoriaKep) ? ' style="background-image: url(/kepek/' . rawurlencode($kozelgoKategoriaKep) . '), linear-gradient(325deg, #f5f5f505, #f5f5f525);"' : ''; ?>>
            <span class="auction-category-tile__count"><?php echo intval($kozelgoDb); ?></span>
            <span class="auction-category-tile__name">K&ouml;zelg&#337;</span>
          </a>
          <a class="auction-category-tile <?php echo $aktualisKatKod === 'kupon' ? 'is-active' : ''; ?>" href="index.php?<?php echo http_build_query(array_filter(['elado' => $elado_id > 0 ? $elado_id : null, 'kat' => 'kupon', 'q' => $keresesRaw !== '' ? $keresesRaw : null])); ?>">
            <span class="auction-category-tile__count"><?php echo intval($kuponDb); ?></span>
            <span class="auction-category-tile__name"><?php echo htmlspecialchars(st('feed.title.coupons', 'Kuponok', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
          </a>
          <?php foreach ($kategoriak as $k): ?>
          <a class="auction-category-tile <?php echo $aktualisKatKod === (string) intval($k['id']) ? 'is-active' : ''; ?><?php echo !empty($k['kep_url']) ? ' has-image' : ''; ?>" href="index.php?<?php echo http_build_query(array_filter(['elado' => $elado_id > 0 ? $elado_id : null, 'kat' => $k['id'], 'q' => $keresesRaw !== '' ? $keresesRaw : null])); ?>"<?php echo !empty($k['kep_url']) ? ' style="background-image: url(/kepek/' . rawurlencode($k['kep_url']) . '), linear-gradient(325deg, #f5f5f505, #f5f5f525);"' : ''; ?>>
            <span class="auction-category-tile__count"><?php echo intval($kategoriakEloDb[intval($k['id'])] ?? 0); ?></span>
            <span class="auction-category-tile__name"><?php echo htmlspecialchars($k['nev']); ?></span>
          </a>
          <?php endforeach; ?>
        </div>
        <button type="button" class="auction-category-strip__arrow is-right" onclick="scrollCategoryStrip(1)" aria-label="Kateg&oacute;ri&aacute;k jobbra"><img class="auction-arrow-icon" src="/kepek/icons/right.webp" alt=""></button>
      </section>

      <section class="auction-feed-header">
        <?php if ($eladoProfil): ?>
        <div class="seller-feed-head">
        <div class="seller-feed-head__profile">
            <div class="seller-feed-head__identity" style="display:flex;align-items:center;gap:0.625rem;flex-wrap:nowrap;">
            <?php if (!empty($eladoProfil['profilkep'])): ?>
            <img class="seller-feed-head__avatar <?php echo !empty($eladoProfil['kiemelt_felhasznalo']) ? 'is-featured-user' : ''; ?>" src="/profilkepek/<?php echo htmlspecialchars($eladoProfil['profilkep']); ?>" alt="<?php echo htmlspecialchars($eladoProfil['becenev']); ?>">
            <?php else: ?>
            <span class="seller-feed-head__avatar seller-feed-head__avatar--fallback <?php echo !empty($eladoProfil['kiemelt_felhasznalo']) ? 'is-featured-user' : ''; ?>"><?php echo strtoupper(substr($eladoProfil['becenev'] ?: 'E', 0, 1)); ?></span>
            <?php endif; ?>
            <?php if (!empty($eladoProfil['kiemelt_felhasznalo'])): ?>
            <span class="featured-user-badge seller-feed-head__featured" style="margin-left:0;align-self:center;white-space:nowrap;">Kiemelt Felhaszn&aacute;l&oacute;</span>
            <?php endif; ?>
            </div>
            <div>
              <h1><?php echo htmlspecialchars($eladoProfil['becenev'] ?: 'Elad&oacute;'); ?> aukci&oacute;i</h1>
              <p>Az akt&iacute;v, hamarosan indul&oacute; &eacute;s lez&aacute;rt aukci&oacute;k is itt l&aacute;tszanak.</p>
            </div>
          </div>
          <?php if ($kedvencekTablaVan && isset($_SESSION['user_id']) && intval($_SESSION['user_id']) !== intval($eladoProfil['id'])): ?>
          <form method="POST" class="favorite-user-form favorite-user-form--inline">
            <input type="hidden" name="kedvenc_felhasznalo_id" value="<?php echo intval($eladoProfil['id']); ?>">
            <input type="hidden" name="kedvenc_muvelet" value="<?php echo $kedvencAzAktualisnak ? 'torol' : 'hozzaad'; ?>">
            <button type="submit" class="favorite-user-btn <?php echo $kedvencAzAktualisnak ? 'is-active' : ''; ?>">
              <img class="favorite-user-btn__icon" src="<?php echo $kedvencAzAktualisnak ? '/kepek/icons/fav_out.webp' : '/kepek/icons/fav_add.webp'; ?>" alt="">
            </button>
          </form>
          <?php endif; ?>
          <a class="seller-feed-head__back" href="index.php">Vissza a f&#337;oldalra</a>
        </div>
        <?php elseif ($keresesRaw !== ''): ?>
        <h1>Keres&eacute;s: <?php echo htmlspecialchars($keresesRaw); ?></h1>
        <?php else: ?>
        <h1><?php echo htmlspecialchars((string)$aktualisFeedCim, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php endif; ?>
      </section>

      <section class="auction-card-grid">
        <?php if ($ajanlatok): ?>
        <?php foreach ($ajanlatok as $t):
          $most = date("Y-m-d H:i:s");
          $tMulti = getMultiAukcioAdat($conn, $t);
          $tSingle = (($t['ajanlat_tipus'] ?? 'licit') === 'licit' && !$tMulti) ? getSingleLicitAllapot($conn, $t) : null;
          $lejart = $tMulti
            ? (($tMulti['phase'] ?? '') === 'closed')
            : ($tSingle ? (($tSingle['allapot'] ?? 'live') === 'closed') : ((!empty($t['lejarat_idopont']) && $t['lejarat_idopont'] <= $most) || intval($t['eladva']) === 1));
          $megkezdodott = $tMulti
            ? (($tMulti['phase'] ?? '') !== 'soon' && ($tMulti['phase'] ?? '') !== 'closed')
            : ($tSingle ? (($tSingle['allapot'] ?? 'soon') === 'live') : (empty($t['kezdes_idopont']) || $t['kezdes_idopont'] <= $most));
          $kartyaVisszaszamlalo = '';
          $kartyaCountdownTarget = 0;
          $kartyaCountdownMode = '';
          if (!$lejart) {
            if ($tMulti) {
              $kartyaCountdownTarget = intval($tMulti['countdown_ts'] ?? 0);
              $kartyaCountdownMode = (($tMulti['phase'] ?? '') === 'soon') ? 'start' : 'end';
            } elseif ($tSingle) {
              if (($tSingle['allapot'] ?? '') === 'soon') {
                $kartyaCountdownTarget = intval($tSingle['kezdes_ts'] ?? 0);
                $kartyaCountdownMode = 'start';
              } elseif (($tSingle['allapot'] ?? '') === 'live') {
                $kartyaCountdownTarget = intval($tSingle['lejarat_ts'] ?? 0);
                $kartyaCountdownMode = 'end';
              }
            } elseif (!$megkezdodott && !empty($t['kezdes_idopont'])) {
              $kartyaCountdownTarget = strtotime($t['kezdes_idopont']);
              $kartyaCountdownMode = 'start';
            } elseif (!empty($t['lejarat_idopont'])) {
              $kartyaCountdownTarget = strtotime($t['lejarat_idopont']);
              $kartyaCountdownMode = 'end';
            }
            if ($kartyaCountdownTarget > 0) {
              $kartyaKartyaDiff = max(0, $kartyaCountdownTarget - time());
              $kartyaShowNow =
                ($kartyaCountdownMode === 'start' && $kartyaKartyaDiff <= 300) ||
                ($kartyaCountdownMode === 'end' && $kartyaKartyaDiff <= 60);
              if ($kartyaShowNow) {
                $kartyaVisszaszamlalo = formatKartyaIdo($kartyaKartyaDiff);
              } else {
                $kartyaCountdownTarget = 0;
              }
            }
          }
          $kartyaTetel = $tMulti['display_item'] ?? null;
          $kartyaAr = $kartyaTetel ? intval($kartyaTetel['aktualis_ar']) : intval($t['aktualis_ar']);
          $ajanlatTipusKod = ($t['ajanlat_tipus'] ?? 'licit') === 'fix'
            ? 'FIX &Aacute;R'
            : (($t['ajanlat_tipus'] ?? 'licit') === 'multi' ? 'LICIT SHOP' : (($t['ajanlat_tipus'] ?? 'licit') === 'kupon' ? 'KUPON' : 'LICIT'));
          $statusAlapText = $lejart ? 'Lez&aacute;rt' : ($megkezdodott ? 'MOST' : 'Hamarosan');
          $statusText = $statusAlapText;
          $statusClass = $lejart ? 'is-closed' : ($megkezdodott ? 'is-live' : 'is-soon');
          $kartyaStartTarget = 0;
          $kartyaEndTarget = 0;
          if (!$lejart) {
            if ($tMulti) {
              if (($tMulti['phase'] ?? '') === 'soon') {
                $kartyaStartTarget = intval($tMulti['countdown_ts'] ?? 0);
              } elseif (($tMulti['phase'] ?? '') === 'live') {
                $kartyaEndTarget = intval($tMulti['countdown_ts'] ?? 0);
              }
            } elseif ($tSingle) {
              if (($tSingle['allapot'] ?? '') === 'soon') {
                $kartyaStartTarget = intval($tSingle['kezdes_ts'] ?? 0);
              } elseif (($tSingle['allapot'] ?? '') === 'live') {
                $kartyaEndTarget = intval($tSingle['lejarat_ts'] ?? 0);
              }
            } else {
              if (!$megkezdodott && !empty($t['kezdes_idopont'])) {
                $kartyaStartTarget = strtotime($t['kezdes_idopont']);
              } elseif (!empty($t['lejarat_idopont'])) {
                $kartyaEndTarget = strtotime($t['lejarat_idopont']);
              }
            }
          }
          if (!$lejart && $kartyaVisszaszamlalo === '') {
          $kartyaBadgeTarget = 0;
            if ($kartyaCountdownTarget > 0) {
              $kartyaBadgeTarget = $kartyaCountdownTarget;
            } elseif ($kartyaStartTarget > 0) {
              $kartyaBadgeTarget = $kartyaStartTarget;
              $kartyaCountdownMode = 'start';
            } elseif ($kartyaEndTarget > 0) {
              $kartyaBadgeTarget = $kartyaEndTarget;
              $kartyaCountdownMode = 'end';
            }
            if ($kartyaBadgeTarget > 0) {
              $kartyaDiff = max(0, $kartyaBadgeTarget - time());
              $kartyaShouldShowCountdown =
                ($kartyaCountdownMode === 'start' && $kartyaDiff <= 300) ||
                ($kartyaCountdownMode === 'end' && $kartyaDiff <= 60);
              if ($kartyaShouldShowCountdown) {
                $kartyaCountdownTarget = $kartyaBadgeTarget;
                $kartyaVisszaszamlalo = formatKartyaIdo($kartyaDiff);
              }
            }
          }
        ?>
        <article class="auction-card">
          <div class="auction-card__top">
            <div class="auction-card__seller">
              <a class="auction-card__sellerlink" href="index.php?elado=<?php echo intval($t['feltolto_id']); ?>" onclick="event.stopPropagation()">
                <?php if (!empty($t['feltolto_profilkep'])): ?>
                <img class="auction-card__avatar <?php echo !empty($t['feltolto_kiemelt']) ? 'is-featured-user' : ''; ?>" src="/profilkepek/<?php echo htmlspecialchars($t['feltolto_profilkep']); ?>" alt="<?php echo htmlspecialchars($t['feltolto_neve'] ?: 'Elad&oacute;'); ?>">
                <?php else: ?>
                <span class="auction-card__avatar auction-card__avatar--fallback <?php echo !empty($t['feltolto_kiemelt']) ? 'is-featured-user' : ''; ?>"><?php echo strtoupper(substr($t['feltolto_neve'] ?: 'E', 0, 1)); ?></span>
                <?php endif; ?>
              </a>
              <a class="auction-card__sellername" href="index.php?elado=<?php echo intval($t['feltolto_id']); ?>" onclick="event.stopPropagation()"><?php echo htmlspecialchars($t['feltolto_neve'] ?: 'Elad&oacute;'); ?></a>
            </div>
            <div class="auction-card__typebar"><?php echo $ajanlatTipusKod; ?></div>
          </div>
          <a class="auction-card__media-link" href="index.php?id=<?php echo $t['id']; ?>">
            <div class="auction-card__media">
              <img src="/kepek/<?php echo htmlspecialchars(!empty($t['kep_url']) ? $t['kep_url'] : 'nincs_kep.jpg'); ?>" alt="<?php echo htmlspecialchars($t['nev']); ?>" onerror="this.onerror=null;this.src='/kepek/nincs_kep.jpg';">
              <?php if (!$lejart && $kartyaVisszaszamlalo !== ''): ?>
              <span class="auction-card__countdown" data-countdown-target="<?php echo intval($kartyaCountdownTarget); ?>" data-countdown-mode="<?php echo htmlspecialchars($kartyaCountdownMode); ?>"><?php echo htmlspecialchars($kartyaVisszaszamlalo); ?></span>
              <?php endif; ?>
              <span class="auction-card__badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
              <div class="auction-card__center-countdown" data-start-target="<?php echo intval($kartyaStartTarget); ?>" data-end-target="<?php echo intval($kartyaEndTarget); ?>" style="display:none;"></div>
            </div>
          </a>
          <a class="auction-card__contentlink" href="index.php?id=<?php echo $t['id']; ?>">
            <?php if (!empty($t['kat_nev'])): ?>
            <div class="auction-card__category"><?php echo htmlspecialchars($t['kat_nev']); ?></div>
            <?php endif; ?>
            <h3>
              <span class="auction-card__titletext"><?php echo htmlspecialchars($t['nev']); ?></span>
              <?php if ($kartyaTetel): ?>
              <span class="auction-card__subitem"><?php echo htmlspecialchars($kartyaTetel['nev']); ?></span>
              <?php endif; ?>
            </h3>
            <?php if (in_array(($t['ajanlat_tipus'] ?? 'licit'), ['fix', 'kupon'], true)): ?>
            <div class="auction-card__price"><?php echo number_format($kartyaAr, 0, ',', ' '); ?> Ft</div>
            <?php endif; ?>
          </a>
        </article>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="auction-empty"><?php echo $eladoProfil ? st('feed.empty.user', 'Jelenleg nincs aukci&oacute;ja a felhaszn&aacute;l&oacute;nak!', $pageLocaleCode) : ($keresesRaw !== '' ? st('feed.empty.search', 'Nincs tal&aacute;lat erre a keres&eacute;sre.', $pageLocaleCode) : st('feed.empty.category', 'Nincs tal&aacute;lat ebben a kateg&oacute;ri&aacute;ban.', $pageLocaleCode)); ?></div>
		<?php endif; ?>
      </section>
    </div>

    <div class="auction-scroll-buttons">
      <button type="button" onclick="scrollFeed(-1)"><img class="auction-scroll-icon" src="/kepek/icons/up.webp" alt=""></button>
      <button type="button" onclick="scrollFeed(1)"><img class="auction-scroll-icon" src="/kepek/icons/down.webp" alt=""></button>
    </div>
  </main>
  <?php endif; ?>
</div>

<div class="site-modal" id="sidebar-info-modal" aria-hidden="true">
  <div class="site-modal__backdrop" onclick="closeSidebarInfoModal()"></div>
  <div class="site-modal__dialog site-modal__dialog--light" role="dialog" aria-modal="true" aria-labelledby="sidebar-info-modal-title">
    <h3 class="site-modal__title site-modal__title--dark" id="sidebar-info-modal-title">C&iacute;m</h3>
    <div class="site-modal__text site-modal__text--dark" id="sidebar-info-modal-text">Ide j&ouml;n a sz&ouml;veg...</div>
    <div class="site-modal__actions site-modal__actions--center">
      <button class="site-modal__button" type="button" onclick="closeSidebarInfoModal()">Bez&aacute;r</button>
    </div>
  </div>
</div>

<?php if ($termek): ?>
<script src="https://www.youtube.com/iframe_api"></script>
<script>
const tid = <?php echo $id; ?>;
const shareUrl = window.location.href;
const shareTitle = document.title;

let utolsoMedia = "";
let utolsoChat = "";
let chatElsoBetoltes = true;
let minLicit = <?php echo isset($detailKovetkezoLicitKezdo) ? intval($detailKovetkezoLicitKezdo) : 0; ?>;
let aktualisAllapot = "live";
let lejaratIdo = null;
let kezdesIdo = null;
let szerverIdoEltolas = 0;
let sajatAukcio = false;
let profilTeljes = <?php echo (isset($_SESSION['user_id']) && $aktivUserProfilTeljes) ? 'true' : 'false'; ?>;
let orszagZarAktiv = false;
let isMulti = <?php echo $multiAukcioAdat ? 'true' : 'false'; ?>;
let bidderVisible = true;
let ajanlatTipus = "licit";
let fixDarabszam = 0;
let fixDarabszamOsszes = 0;
let fixEredetiAr = 0;
let aktualisNezok = 0;
let manualStartRequired = false;
let manualStartEnabled = false;
let aktualisLicitLepcso = <?php echo isset($detailLicitLepcsoKezdo) ? intval($detailLicitLepcsoKezdo) : 500; ?>;
let ownerCanToggleLive = false;
let liveActive = false;
let liveFallbackVideoForced = false;
let aktualisVezetoLicitalo = "";

function getAjanlatTipusKod(tipus) {
  if (tipus === "fix") return "FIX \u00C1R";
  if (tipus === "kupon") return "KUPON";
  if (tipus === "multi") return "LICIT SHOP";
  return "LICIT";
}
let ytPlayer = null;
let mediaForrasKulcs = "";
let mediaFallbackAktiv = false;
let mediaUjraprobaIdo = 0;
let varakozoMediaAdat = null;
const detailApiUrl = "/api.php?id=" + tid + "&market=<?php echo rawurlencode((string)$aktivPiacKod); ?>";
const csrfToken = <?php echo json_encode(authCsrfToken(), JSON_UNESCAPED_UNICODE); ?>;

function createApiFormData() {
  const fd = new FormData();
  fd.append("csrf_token", csrfToken);
  return fd;
}

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

function setupDetailCopy() {
  const copy = document.getElementById("detail-copy");
  const toggle = document.getElementById("detail-copy-toggle");
  if (!copy || !toggle) return;
  const tulMagas = copy.scrollHeight > copy.clientHeight + 4;
  toggle.style.display = tulMagas ? "inline-flex" : "none";
}

function toggleDetailCopy() {
  const copy = document.getElementById("detail-copy");
  const toggle = document.getElementById("detail-copy-toggle");
  if (!copy || !toggle) return;
  const nyitva = copy.classList.toggle("is-expanded");
  copy.classList.toggle("is-collapsed", !nyitva);
  toggle.innerText = nyitva ? "kevesebb" : "t\u00f6bb...";
}

function escapeHtml(text) {
  const div = document.createElement("div");
  div.innerText = text ?? "";
  return div.innerHTML;
}

function frissitLeirasEsSzallitas(data) {
  const multiName = document.getElementById("detail-multi-itemname");
  if (multiName && data.multi_item_nev) {
    multiName.innerText = data.multi_item_nev;
  }

  const city = document.getElementById("detail-city");
  const cityText = document.getElementById("detail-city-text");
  if (city && cityText) {
    if (((data.ajanlat_tipus || "") === "fix" || (data.ajanlat_tipus || "") === "kupon") && (data.varos || "").trim() !== "") {
      city.style.display = "";
      cityText.innerText = data.varos;
    } else {
      city.style.display = "none";
      cityText.innerText = "";
    }
  }

  const shipping = document.getElementById("detail-shipping");
  if (shipping) {
    if ((data.ajanlat_tipus || "") === "kupon" && (data.kupon_bevaltas_vege_text || "").trim() !== "") {
      shipping.style.display = "";
      shipping.innerText = "Bev\u00E1lt\u00E1s v\u00E9ge: " + data.kupon_bevaltas_vege_text;
    } else if (data.szallitasi_mod === "ingyenes") {
      shipping.style.display = "";
      shipping.innerText = "Sz\u00E1ll\u00EDt\u00E1si d\u00EDj: Ingyenes sz\u00E1ll\u00EDt\u00E1s";
    } else if (data.szallitasi_mod === "egyedi" && parseInt(data.szallitasi_dij || 0, 10) > 0) {
      shipping.style.display = "";
      shipping.innerText = "Sz\u00E1ll\u00EDt\u00E1si d\u00EDj: " + data.szallitasi_dij_formatalt + " Ft";
    } else {
      shipping.style.display = "none";
    }
  }

  const copy = document.getElementById("detail-copy");
  const toggle = document.getElementById("detail-copy-toggle");
  const copyWrap = document.getElementById("detail-copy-wrap");
  const text = data.leiras || "";
  if (copy && toggle) {
    if (text) {
      if (copyWrap) copyWrap.style.display = "";
      copy.innerHTML = escapeHtml(text).replace(/\n/g, "<br>");
      copy.style.display = "";
      copy.classList.add("is-collapsed");
      copy.classList.remove("is-expanded");
      toggle.style.display = "none";
      setupDetailCopy();
    } else {
      if (copyWrap) copyWrap.style.display = "none";
      copy.style.display = "none";
      toggle.style.display = "none";
    }
  }
}

function mediaKepHtml(kepUrl) {
  if (!kepUrl) {
    return `<div class="live-no-media">Nincs felt\u00f6lt\u00f6tt m\u00e9dia</div>`;
  }
  return `<img src="/kepek/${encodeURIComponent(kepUrl)}" alt="Term\u00e9k k\u00e9p" onerror="this.onerror=null;this.src='/kepek/nincs_kep.jpg';">`;
}

function mediaHtmlBeallit(html) {
  if (html !== utolsoMedia) {
    document.getElementById("media-content").innerHTML = html;
    utolsoMedia = html;
  }
}

function mediaLejatszoTorol() {
  if (ytPlayer && typeof ytPlayer.destroy === "function") {
    ytPlayer.destroy();
  }
  ytPlayer = null;
}

function mediaFallbackKep(data, ujraprobal = false, ujraprobaKesleltetes = 60000) {
  mediaLejatszoTorol();
  mediaFallbackAktiv = ujraprobal;
  mediaUjraprobaIdo = ujraprobal ? Date.now() + ujraprobaKesleltetes : 0;
  mediaHtmlBeallit(mediaKepHtml(data.kep_url));
}

function onYouTubeIframeAPIReady() {
  if (varakozoMediaAdat) {
    renderMedia(varakozoMediaAdat, true);
  }
}

function getYoutubeId(url) {
  if (!url) return null;
  try {
    const u = new URL(url);
    const host = u.hostname.replace(/^www\./, "");
    if (host === "youtu.be") {
      return u.pathname.replace(/^\/+/, "").split("/")[0];
    }
    if (u.searchParams.get("v")) {
      return u.searchParams.get("v");
    }
    const parts = u.pathname.split("/").filter(Boolean);
    const specialTypes = ["live", "shorts", "embed"];
    for (const type of specialTypes) {
      const idx = parts.indexOf(type);
      if (idx !== -1 && parts[idx + 1]) {
        return parts[idx + 1];
      }
    }
    if ((host === "youtube.com" || host === "m.youtube.com") && parts.length > 0) {
      return parts[parts.length - 1];
    }
  } catch (e) {}
  return null;
}

function renderMedia(data, force = false) {
  varakozoMediaAdat = data;
  const foMedia = (liveFallbackVideoForced ? (data.fallback_video_url || data.video_url || "") : (data.media_url || data.video_url || ""));
  const fallbackVideo = data.fallback_video_url || data.video_url || "";
  const forrasKulcs = `${foMedia}|${fallbackVideo}|${data.kep_url || ""}|${data.live_active ? "1" : "0"}`;
  const yt = getYoutubeId(foMedia);
  const ugyanaz = forrasKulcs === mediaForrasKulcs;

  if (yt) {
    if (!force && ugyanaz && mediaFallbackAktiv && Date.now() < mediaUjraprobaIdo) {
      return;
    }

    if (!force && ugyanaz && ytPlayer && !mediaFallbackAktiv) {
      return;
    }

    mediaForrasKulcs = forrasKulcs;

    if (!(window.YT && window.YT.Player)) {
      mediaFallbackKep(data, true);
      return;
    }

    mediaLejatszoTorol();
    mediaFallbackAktiv = false;
    mediaHtmlBeallit('<div id="yt-player"></div>');

    ytPlayer = new YT.Player("yt-player", {
      videoId: yt,
      playerVars: {
        autoplay: 1,
        controls: 1,
        mute: 1,
        playsinline: 1,
        rel: 0,
        loop: 1,
        playlist: yt
      },
      events: {
        onReady: function (event) {
          try {
            event.target.mute();
            event.target.playVideo();
          } catch (e) {}
        },
        onStateChange: function (event) {
          if (window.YT && event.data === YT.PlayerState.ENDED) {
            try {
              event.target.seekTo(0);
              event.target.playVideo();
            } catch (e) {
              if (data.live_active && fallbackVideo) {
                liveFallbackVideoForced = true;
                renderMedia({ ...data, media_url: fallbackVideo }, true);
              } else {
                mediaFallbackKep(data, true, 60000);
              }
            }
          }
        },
        onError: function () {
          if (data.live_active && fallbackVideo) {
            liveFallbackVideoForced = true;
            renderMedia({ ...data, media_url: fallbackVideo }, true);
          } else {
            mediaFallbackKep(data, true, 60000);
          }
        }
      }
    });

    return;
  }

  if (!force && ugyanaz && !mediaFallbackAktiv) {
    return;
  }

  mediaForrasKulcs = forrasKulcs;
  mediaFallbackKep(data, false);
}

function formatCountdown(target) {
  if (!target) return "--:--";
  const most = Date.now() + szerverIdoEltolas;
  const diff = Math.max(0, Math.floor((target - most) / 1000));
  const ora = Math.floor(diff / 3600);
  const perc = Math.floor((diff % 3600) / 60);
  const mp = diff % 60;
  if (ora > 0) {
    return `${ora}:${String(perc).padStart(2, "0")}:${String(mp).padStart(2, "0")}`;
  }
  return `${String(perc).padStart(2, "0")}:${String(mp).padStart(2, "0")}`;
}

function countdownSecondsLeft(target) {
  if (!target) return 0;
  const most = Date.now() + szerverIdoEltolas;
  return Math.max(0, Math.floor((target - most) / 1000));
}

function getDetailLicitDurationMs() {
  if (kezdesIdo && lejaratIdo && lejaratIdo > kezdesIdo) {
    return lejaratIdo - kezdesIdo;
  }
  return 60000;
}

function frissitAllapotUI() {
  const statusEl = document.getElementById("auction-status");
  const detailTypeLabelEl = document.getElementById("detail-type-label");
  const detailMobileTypeLabelEl = document.getElementById("detail-mobile-type-label");
  const detailLiveFlagEl = document.getElementById("detail-live-flag");
  const detailMobileLiveFlagEl = document.getElementById("detail-live-flag-mobile");
  const countdownEl = document.getElementById("countdown");
  const detailCenterCountdownEl = document.getElementById("detail-center-countdown");
  const bidButton = document.getElementById("bid-button");
  const bidButtonTextEl = document.getElementById("bid-button-text");
  const paymentNoteEl = document.getElementById("detail-payment-note");
  const nextBidValue = document.getElementById("next-bid-value");
  const ownerHint = document.getElementById("owner-bid-hint");
  const liveToggleWrap = document.getElementById("detail-owner-live-wrap");
  const liveToggleButton = document.getElementById("live-toggle-button");
  const nextAuctionEl = document.getElementById("next-auction-countdown");
  const bidLabel = document.querySelector(".detail-bidbox__label");
  const bidderEl = document.getElementById("bidder");
  const minBidText = document.getElementById("min-bid-text");
  const originalPriceLabelEl = document.getElementById("original-price-label");
  const originalPriceEl = document.getElementById("original-price");
  const currentPriceLabelEl = document.getElementById("current-price-label");
  const priceEl = document.getElementById("price");
  const liveToggleButtonTextEl = document.getElementById("live-toggle-button-text");
  const kezdesHatralevo = countdownSecondsLeft(kezdesIdo);
  const lejaratHatralevo = countdownSecondsLeft(lejaratIdo);
  const shouldShowSoonCountdown = aktualisAllapot === "soon" && kezdesHatralevo > 0 && kezdesHatralevo <= 300;
  const shouldShowDetailLiveCountdown = aktualisAllapot === "live" && lejaratHatralevo > 0;
  const shouldShowOverlayLiveCountdown = aktualisAllapot === "live" && lejaratHatralevo > 0 && lejaratHatralevo <= 60;

  if (ownerHint) {
    ownerHint.style.display = sajatAukcio ? "block" : "none";
    if (sajatAukcio) {
      ownerHint.innerText = (ajanlatTipus === "fix" || ajanlatTipus === "kupon")
        ? "Ez a saj\u00E1t aj\u00E1nlatod, ez\u00E9rt nem tudsz v\u00E1s\u00E1rolni, de chatelni igen."
        : "Ez a saj\u00E1t aukci\u00F3d, ez\u00E9rt licit\u00E1lni nem tudsz, de chatelni igen.";
    }
  }

  if (liveToggleWrap && liveToggleButton) {
    const lathato = ownerCanToggleLive;
    liveToggleWrap.style.display = lathato ? "block" : "none";
    liveToggleButton.innerText = liveActive ? "LIVE STOP" : "LIVE START";
  }

  if ((ajanlatTipus === "fix" || ajanlatTipus === "kupon")) {
    bidderVisible = false;
    if (paymentNoteEl) paymentNoteEl.style.display = "none";
    if (bidderEl) {
      bidderEl.style.display = "none";
      bidderEl.innerText = "";
    }
    if (bidLabel) bidLabel.innerText = "Fix \u00E1ras v\u00E1s\u00E1rl\u00E1s";
    if (bidButton) bidButton.innerText = "Megveszem";
    if (minBidText) minBidText.innerText = "Darabsz\u00E1m: " + fixDarabszam + " el\u00E9rhet\u0151 / " + fixDarabszamOsszes + " \u00F6sszesen";
  } else {
    bidderVisible = true;
    if (paymentNoteEl) paymentNoteEl.style.display = sajatAukcio ? "none" : "";
    if (bidderEl) bidderEl.style.display = "";
    if (bidLabel) bidLabel.innerText = "K\u00F6vetkez\u0151 licit";
    if (bidButton) bidButton.innerText = "Licit\u00E1lok";
  }

  if (originalPriceLabelEl && originalPriceEl) {
    if ((ajanlatTipus === "fix" || ajanlatTipus === "kupon") && fixEredetiAr > 0) {
      originalPriceLabelEl.style.display = "";
      originalPriceEl.style.display = "";
      originalPriceEl.innerText = fixEredetiAr + " Ft";
    } else {
      originalPriceLabelEl.style.display = "none";
      originalPriceEl.style.display = "none";
      originalPriceEl.innerText = "";
    }
  }

  if (detailTypeLabelEl) {
    detailTypeLabelEl.innerText = (ajanlatTipus === "fix") ? "FIX \u00C1R" : ((ajanlatTipus === "kupon") ? "KUPON" : (isMulti ? "LICIT SHOP" : "LICIT"));
  }

  statusEl.className = "live-badge";
  if (aktualisAllapot === "soon") {
    statusEl.classList.add("live-badge--soon");
    statusEl.innerText = "\uD83D\uDC41 " + aktualisNezok + " K\u00D6ZELG\u0150";
    if (bidButton) bidButton.disabled = true;
  } else if (aktualisAllapot === "closed") {
    statusEl.classList.add("live-badge--closed");
    statusEl.innerText = "LEZ\u00C1RT";
    if (bidButton) bidButton.disabled = true;
  } else {
    statusEl.classList.add("live-badge--live");
    statusEl.innerText = "\uD83D\uDC41 " + aktualisNezok + " MOST";
    if (bidButton) bidButton.disabled = sajatAukcio;
  }

  if (countdownEl) {
    countdownEl.style.display = "none";
    countdownEl.innerText = "";
    if (shouldShowSoonCountdown) {
      countdownEl.style.display = "";
      countdownEl.innerText = formatCountdown(kezdesIdo);
    } else if (shouldShowDetailLiveCountdown) {
      countdownEl.style.display = "";
      countdownEl.innerText = formatCountdown(lejaratIdo);
    }
  }

  if (detailLiveFlagEl) {
    const lathatoEloFlag = aktualisAllapot === "live" && liveActive;
    detailLiveFlagEl.style.display = lathatoEloFlag ? "inline-flex" : "none";
  }

  if (nextBidValue) {
    nextBidValue.innerText = (ajanlatTipus === "fix" || ajanlatTipus === "kupon") ? ((document.getElementById("price")?.innerText) || "0 Ft") : (minLicit + " Ft");
    nextBidValue.style.opacity = sajatAukcio ? "0.5" : "1";
  }

  if (nextAuctionEl) {
    if (isMulti && shouldShowSoonCountdown) {
      nextAuctionEl.style.display = "block";
      nextAuctionEl.innerText = "K\u00F6vetkez\u0151 aukci\u00F3 kezd\u00E9s: " + formatCountdown(kezdesIdo);
    } else {
      nextAuctionEl.style.display = "none";
      nextAuctionEl.innerText = "";
    }
  }

  if (detailCenterCountdownEl) {
    detailCenterCountdownEl.style.display = "none";
    detailCenterCountdownEl.innerHTML = "";
    if (shouldShowSoonCountdown) {
      const indulDiff = Math.max(0, Math.floor((kezdesIdo - Date.now()) / 1000));
      if (indulDiff > 0 && indulDiff <= 300) {
        detailCenterCountdownEl.style.display = "grid";
        detailCenterCountdownEl.innerHTML = '<span>Indul&aacute;s</span><strong>' + formatCountdown(kezdesIdo) + '</strong>';
      }
    } else if (aktualisAllapot === "live" && lejaratIdo) {
      const zaroDiff = Math.max(0, Math.floor((lejaratIdo - Date.now()) / 1000));
      if (zaroDiff > 0 && zaroDiff <= 60) {
        detailCenterCountdownEl.style.display = "grid";
        detailCenterCountdownEl.innerHTML = '<span>Lej&aacute;rat</span><strong>' + formatCountdown(lejaratIdo) + '</strong>';
      }
    }
  }
}

function frissit() {
  fetch(detailApiUrl)
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        console.error("API hiba:", data.error);
        return;
      }
      document.getElementById("price").innerText = data.ar + " Ft";
      ajanlatTipus = data.ajanlat_tipus || "licit";
      fixDarabszam = parseInt(data.darabszam || 0, 10);
      fixDarabszamOsszes = parseInt(data.darabszam_osszes || data.darabszam || 0, 10);
      fixEredetiAr = parseInt(data.eredeti_ar || 0, 10);
      if ((ajanlatTipus !== "fix" && ajanlatTipus !== "kupon")) {
        const vezetoLicitalo = data.licitalo_nev || data.licitalo || "M\u00e9g nincs licit";
        document.getElementById("bidder").innerText = (vezetoLicitalo && vezetoLicitalo !== "M\u00e9g nincs licit")
          ? ("Nyertes " + vezetoLicitalo)
          : "M\u00e9g nincs licit";
      } else {
        document.getElementById("bidder").innerText = "";
      }
      minLicit = parseInt(data.aktualis_ar_raw) + (parseInt(data.licit_lepcso) || 500);
      const minBidText = document.getElementById("min-bid-text");
      if (minBidText && (ajanlatTipus !== "fix" && ajanlatTipus !== "kupon")) {
        minBidText.innerText = "Licitl\u00e9pcs\u0151: " + (parseInt(data.licit_lepcso) || 500) + " Ft";
      }
      aktualisAllapot = data.allapot || "live";
      sajatAukcio = !!data.sajat_aukcio;
      profilTeljes = !!data.profil_teljes;
      orszagZarAktiv = !!data.orszag_zar_aktiv;
      aktualisNezok = parseInt(data.nezok_szama || 0, 10);
      isMulti = !!data.is_multi;
      szerverIdoEltolas = data.szerver_timestamp_ms ? parseInt(data.szerver_timestamp_ms, 10) - Date.now() : (data.szerver_timestamp ? (parseInt(data.szerver_timestamp, 10) * 1000) - Date.now() : 0);
      kezdesIdo = data.kezdes_timestamp ? parseInt(data.kezdes_timestamp, 10) * 1000 : null;
      lejaratIdo = data.lejarat_timestamp ? parseInt(data.lejarat_timestamp, 10) * 1000 : null;
      frissitLeirasEsSzallitas(data);
      frissitAllapotUI();

      const kellMediaUjraproba = mediaFallbackAktiv && document.visibilityState === "visible" && !!data.video_url && Date.now() >= mediaUjraprobaIdo;
      renderMedia(data, kellMediaUjraproba);

      let chatHTML = "";
      const rendezettChat = Array.isArray(data.chat)
        ? [...data.chat].sort((a, b) => Number(a.id || 0) - Number(b.id || 0))
        : [];
      rendezettChat.forEach(c => {
        chatHTML += `<div class="msg"><b>${escapeHtml(c.felhasznalo)}:</b> ${escapeHtml(c.szoveg)}</div>`;
      });

      if (chatHTML !== utolsoChat) {
        const box = document.getElementById("chat-box");
        const tavolsagAzAljatol = box.scrollHeight - box.scrollTop - box.clientHeight;
        const autoScroll = chatElsoBetoltes || tavolsagAzAljatol < 48;
        box.innerHTML = chatHTML;
        if (autoScroll) {
          requestAnimationFrame(() => {
            box.scrollTop = box.scrollHeight;
          });
        }
        utolsoChat = chatHTML;
        chatElsoBetoltes = false;
      }

      document.getElementById("comments-count").innerText = rendezettChat.length;
    })
    .catch(() => {});
}

function frissitAllapotUI() {
  const statusEl = document.getElementById("auction-status");
  const detailTypeLabelEl = document.getElementById("detail-type-label");
  const detailLiveFlagEl = document.getElementById("detail-live-flag");
  const countdownEl = document.getElementById("countdown");
  const detailCenterCountdownEl = document.getElementById("detail-center-countdown");
  const bidButton = document.getElementById("bid-button");
  const paymentNoteEl = document.getElementById("detail-payment-note");
  const nextBidValue = document.getElementById("next-bid-value");
  const ownerHint = document.getElementById("owner-bid-hint");
  const nextAuctionEl = document.getElementById("next-auction-countdown");
  const bidLabel = document.querySelector(".detail-bidbox__label");
  const bidderEl = document.getElementById("bidder");
  const minBidText = document.getElementById("min-bid-text");
  const originalPriceLabelEl = document.getElementById("original-price-label");
  const originalPriceEl = document.getElementById("original-price");
  const kezdesHatralevo = countdownSecondsLeft(kezdesIdo);
  const lejaratHatralevo = countdownSecondsLeft(lejaratIdo);
  const shouldShowSoonCountdown = aktualisAllapot === "soon" && kezdesHatralevo > 0 && kezdesHatralevo <= 300;
  const shouldShowDetailLiveCountdown = aktualisAllapot === "live" && lejaratHatralevo > 0;
  const shouldShowOverlayLiveCountdown = aktualisAllapot === "live" && lejaratHatralevo > 0 && lejaratHatralevo <= 60;

  if (ownerHint) {
    ownerHint.style.display = sajatAukcio ? "block" : "none";
    if (sajatAukcio) {
      if (manualStartRequired && (ajanlatTipus !== "fix" && ajanlatTipus !== "kupon")) {
        ownerHint.innerText = "A licit akkor indul el, amikor megnyomod a LICIT START gombot.";
      } else if ((ajanlatTipus === "fix" || ajanlatTipus === "kupon")) {
        ownerHint.innerText = "Ez a sajat ajanlatod, ezert nem tudsz vasarolni, de chatelni igen.";
      } else {
        ownerHint.innerText = "Ez a sajat aukciod, ezert licitalni nem tudsz, de chatelni igen.";
      }
    }
  }

  if ((ajanlatTipus === "fix" || ajanlatTipus === "kupon")) {
    bidderVisible = false;
    if (paymentNoteEl) paymentNoteEl.style.display = "none";
    if (bidderEl) {
      bidderEl.style.display = "none";
      bidderEl.innerText = "";
    }
    if (bidLabel) bidLabel.innerText = "Fix aras vasarlas";
    if (bidButtonTextEl) bidButtonTextEl.innerText = "Megveszem";
    if (minBidText) minBidText.innerText = "Darabszam: " + fixDarabszam + " elerheto / " + fixDarabszamOsszes + " osszesen";
    if (currentPriceLabelEl) currentPriceLabelEl.style.display = "none";
    if (priceEl) priceEl.style.display = "none";
  } else {
    bidderVisible = true;
    if (paymentNoteEl) paymentNoteEl.style.display = sajatAukcio ? "none" : "";
    if (bidderEl) bidderEl.style.display = "";
    if (bidLabel) {
      if (isMulti && aktualisAllapot === "closed") {
        bidLabel.innerText = "Vege a licitnek!";
      } else {
        bidLabel.innerText = (manualStartRequired && sajatAukcio) ? "Licit inditasa" : "Kovetkezo licit";
      }
    }
    if (bidButton) bidButton.innerText = (manualStartRequired && sajatAukcio) ? "Licit \u00BB Start" : "Licitalok";
    if (minBidText) {
      minBidText.innerText = (isMulti && aktualisAllapot === "closed")
        ? ""
        : ((manualStartRequired && sajatAukcio)
        ? "Az elado inditja a licitet, amikor keszen all."
        : "Licitlepcso: " + aktualisLicitLepcso + " Ft");
    }
  }

  if (originalPriceLabelEl && originalPriceEl) {
    if ((ajanlatTipus === "fix" || ajanlatTipus === "kupon") && fixEredetiAr > 0) {
      originalPriceLabelEl.style.display = "";
      originalPriceEl.style.display = "";
      originalPriceEl.innerText = fixEredetiAr + " Ft";
    } else {
      originalPriceLabelEl.style.display = "none";
      originalPriceEl.style.display = "none";
      originalPriceEl.innerText = "";
    }
  }

  if (detailTypeLabelEl) {
    detailTypeLabelEl.innerText = (ajanlatTipus === "fix") ? "FIX \u00C1R" : ((ajanlatTipus === "kupon") ? "KUPON" : (isMulti ? "LICIT SHOP" : "LICIT"));
  }

  statusEl.className = "live-badge";
  if (aktualisAllapot === "soon") {
    statusEl.classList.add("live-badge--soon");
    statusEl.innerText = "\uD83D\uDC41 " + aktualisNezok + " K\u00D6ZELG\u0150";
    if (bidButton) bidButton.disabled = true;
  } else if (aktualisAllapot === "closed") {
    statusEl.classList.add("live-badge--closed");
    statusEl.innerText = "LEZ\u00C1RT";
    if (bidButton) bidButton.disabled = true;
  } else {
    statusEl.classList.add("live-badge--live");
    statusEl.innerText = "\uD83D\uDC41 " + aktualisNezok + " MOST";
    if (bidButton) {
      bidButton.disabled = manualStartRequired ? !manualStartEnabled : sajatAukcio;
    }
  }

  if (countdownEl) {
    countdownEl.style.display = "none";
    countdownEl.innerText = "";
    if (shouldShowSoonCountdown) {
      countdownEl.style.display = "";
      countdownEl.innerText = formatCountdown(kezdesIdo);
    } else if (shouldShowDetailLiveCountdown) {
      countdownEl.style.display = "";
      countdownEl.innerText = formatCountdown(lejaratIdo);
    }
  }

  if (detailLiveFlagEl) {
    detailLiveFlagEl.style.display = (aktualisAllapot === "live" && liveActive) ? "inline-flex" : "none";
  }

  if (nextBidValue) {
    if ((ajanlatTipus === "fix" || ajanlatTipus === "kupon")) {
      nextBidValue.innerText = (document.getElementById("price")?.innerText) || "0 Ft";
    } else if (isMulti && aktualisAllapot === "closed") {
      nextBidValue.innerText = "";
    } else if (manualStartRequired && sajatAukcio) {
      nextBidValue.innerText = "";
    } else {
      nextBidValue.innerText = minLicit + " Ft";
    }
    nextBidValue.style.opacity = (manualStartRequired && !manualStartEnabled) || (sajatAukcio && !manualStartRequired) ? "0.5" : "1";
    nextBidValue.style.display = ((manualStartRequired && sajatAukcio) || (isMulti && aktualisAllapot === "closed")) ? "none" : "";
  }

  if (nextAuctionEl) {
    if (isMulti && shouldShowSoonCountdown) {
      nextAuctionEl.style.display = "block";
      nextAuctionEl.innerText = "Kovetkezo aukcio kezdes: " + formatCountdown(kezdesIdo);
    } else if (isMulti && aktualisAllapot === "live" && manualStartRequired && sajatAukcio) {
      nextAuctionEl.style.display = "block";
      nextAuctionEl.innerText = "A kovetkezo tetel a LICIT START gombbal indul.";
    } else if (isMulti && aktualisAllapot === "live" && manualStartRequired && !sajatAukcio) {
      nextAuctionEl.style.display = "block";
      nextAuctionEl.innerText = "Hamarosan indul a kovetkezo licit!";
    } else {
      nextAuctionEl.style.display = "none";
      nextAuctionEl.innerText = "";
    }
  }

  if (detailCenterCountdownEl) {
    detailCenterCountdownEl.style.display = "none";
    detailCenterCountdownEl.innerHTML = "";
    if (shouldShowSoonCountdown) {
      const indulDiff = Math.max(0, Math.floor((kezdesIdo - Date.now()) / 1000));
      if (indulDiff > 0 && indulDiff <= 300) {
        detailCenterCountdownEl.style.display = "grid";
        detailCenterCountdownEl.innerHTML = '<span>Indulas</span><strong>' + formatCountdown(kezdesIdo) + '</strong>';
      }
    } else if (shouldShowOverlayLiveCountdown) {
      const zaroDiff = Math.max(0, Math.floor((lejaratIdo - Date.now()) / 1000));
      if (zaroDiff > 0 && zaroDiff <= 60) {
        detailCenterCountdownEl.style.display = "grid";
        detailCenterCountdownEl.innerHTML = '<span>Lejarat</span><strong>' + formatCountdown(lejaratIdo) + '</strong>';
      }
    }
  }
}

function frissit() {
  fetch(detailApiUrl)
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        console.error("API hiba:", data.error);
        return;
      }
      document.getElementById("price").innerText = data.ar + " Ft";
      ajanlatTipus = data.ajanlat_tipus || "licit";
      fixDarabszam = parseInt(data.darabszam || 0, 10);
      fixDarabszamOsszes = parseInt(data.darabszam_osszes || data.darabszam || 0, 10);
      fixEredetiAr = parseInt(data.eredeti_ar || 0, 10);
      aktualisLicitLepcso = parseInt(data.licit_lepcso || 500, 10);
      manualStartRequired = !!data.manual_start_required;
      manualStartEnabled = !!data.manual_start_enabled;
      ownerCanToggleLive = !!data.owner_can_toggle_live;
      liveActive = !!data.live_active;
      if (!liveActive) {
        liveFallbackVideoForced = false;
      }
      if ((ajanlatTipus !== "fix" && ajanlatTipus !== "kupon")) {
        const vezetoLicitalo = data.licitalo_nev || data.licitalo || "Meg nincs licit";
        aktualisVezetoLicitalo = vezetoLicitalo;
        document.getElementById("bidder").innerText = (vezetoLicitalo && vezetoLicitalo !== "Meg nincs licit")
          ? ("Nyertes " + vezetoLicitalo)
          : "Meg nincs licit";
      } else {
        aktualisVezetoLicitalo = "";
        document.getElementById("bidder").innerText = "";
      }
      minLicit = parseInt(data.aktualis_ar_raw) + aktualisLicitLepcso;
      aktualisAllapot = data.allapot || "live";
      sajatAukcio = !!data.sajat_aukcio;
      profilTeljes = !!data.profil_teljes;
      orszagZarAktiv = !!data.orszag_zar_aktiv;
      aktualisNezok = parseInt(data.nezok_szama || 0, 10);
      isMulti = !!data.is_multi;
      szerverIdoEltolas = data.szerver_timestamp_ms ? parseInt(data.szerver_timestamp_ms, 10) - Date.now() : (data.szerver_timestamp ? (parseInt(data.szerver_timestamp, 10) * 1000) - Date.now() : 0);
      kezdesIdo = data.kezdes_timestamp ? parseInt(data.kezdes_timestamp, 10) * 1000 : null;
      lejaratIdo = data.lejarat_timestamp ? parseInt(data.lejarat_timestamp, 10) * 1000 : null;
      frissitLeirasEsSzallitas(data);
      frissitAllapotUI();

      const kellMediaUjraproba = mediaFallbackAktiv && document.visibilityState === "visible" && !!(data.media_url || data.video_url) && Date.now() >= mediaUjraprobaIdo;
      renderMedia(data, kellMediaUjraproba);

      let chatHTML = "";
      const rendezettChat = Array.isArray(data.chat)
        ? [...data.chat].sort((a, b) => Number(a.id || 0) - Number(b.id || 0))
        : [];
      rendezettChat.forEach(c => {
        chatHTML += `<div class="msg"><b>${escapeHtml(c.felhasznalo)}:</b> ${escapeHtml(c.szoveg)}</div>`;
      });

      if (chatHTML !== utolsoChat) {
        const box = document.getElementById("chat-box");
        const tavolsagAzAljatol = box.scrollHeight - box.scrollTop - box.clientHeight;
        const autoScroll = chatElsoBetoltes || tavolsagAzAljatol < 48;
        box.innerHTML = chatHTML;
        if (autoScroll) {
          requestAnimationFrame(() => {
            box.scrollTop = box.scrollHeight;
          });
        }
        utolsoChat = chatHTML;
        chatElsoBetoltes = false;
      }

      document.getElementById("comments-count").innerText = rendezettChat.length;
    })
    .catch(() => {});
}


function doLicit() {
  if ((ajanlatTipus === "fix" || ajanlatTipus === "kupon")) {
    if (aktualisAllapot !== "live" || sajatAukcio || !profilTeljes || fixDarabszam < 1) return;
    const biztos = window.confirm("Biztos megveszed?\n\nEz fizet\u00E9si k\u00F6telezetts\u00E9ggel j\u00E1r.\nAz Igen gombra kattintva automatikusan elfogadod a v\u00E1s\u00E1rl\u00E1si felt\u00E9teleket.");
    if (!biztos) return;
    const fd = createApiFormData();
    fd.append("fix_vetel", "1");
    fetch(detailApiUrl, { method: "POST", body: fd })
      .then(r => r.json())
      .then(() => {
        const most = Date.now() + szerverIdoEltolas;
        manualStartRequired = false;
        manualStartEnabled = false;
        aktualisAllapot = "live";
        if (!lejaratIdo || lejaratIdo <= most) {
          lejaratIdo = most + getDetailLicitDurationMs();
        }
        frissitAllapotUI();
        setTimeout(frissit, 150);
        setTimeout(frissit, 1000);
      })
      .catch(() => {});
    return;
  }
  if (aktualisAllapot !== "live" || sajatAukcio || !profilTeljes) return;
  const fd = createApiFormData();
  const kuldendoLicit = Number.isFinite(minLicit) && minLicit > 0
    ? minLicit
    : ((parseInt((document.getElementById("price")?.innerText || "").replace(/[^0-9]/g, ""), 10) || 0) + (Number.isFinite(aktualisLicitLepcso) && aktualisLicitLepcso > 0 ? aktualisLicitLepcso : 500));
  fd.append("osszeg", kuldendoLicit);
  fetch(detailApiUrl, { method: "POST", body: fd })
    .then(r => r.json())
    .then((data) => {
      if (data && data.error) {
        alert(data.error);
      }
      frissit();
    })
    .catch(() => {});
}

function doLicit() {
  if ((ajanlatTipus === "fix" || ajanlatTipus === "kupon")) {
    if (aktualisAllapot !== "live" || sajatAukcio || !profilTeljes || fixDarabszam < 1) return;
    const biztos = window.confirm("Biztos megveszed?\n\nEz fizetesi kotelezettseggel jar.\nAz Igen gombra kattintva automatikusan elfogadod a vasarlasi felteteleket.");
    if (!biztos) return;
    const fd = createApiFormData();
    fd.append("fix_vetel", "1");
    fetch(detailApiUrl, { method: "POST", body: fd })
      .then(r => r.json())
      .then(() => frissit())
      .catch(() => {});
    return;
  }

  if (manualStartRequired) {
    if (!manualStartEnabled) return;
    const fd = createApiFormData();
    fd.append("licit_start", "1");
    fetch(detailApiUrl, { method: "POST", body: fd })
      .then(r => r.json())
      .then(() => {
        const most = Date.now() + szerverIdoEltolas;
        manualStartRequired = false;
        manualStartEnabled = false;
        aktualisAllapot = "live";
        if (!lejaratIdo || lejaratIdo <= most) {
          lejaratIdo = most + getDetailLicitDurationMs();
        }
        frissitAllapotUI();
        setTimeout(frissit, 150);
        setTimeout(frissit, 1000);
      })
      .catch(() => {});
    return;
  }

  if (aktualisAllapot !== "live" || sajatAukcio || !profilTeljes) return;
  const fd = createApiFormData();
  const kuldendoLicit = Number.isFinite(minLicit) && minLicit > 0
    ? minLicit
    : ((parseInt((document.getElementById("price")?.innerText || "").replace(/[^0-9]/g, ""), 10) || 0) + (Number.isFinite(aktualisLicitLepcso) && aktualisLicitLepcso > 0 ? aktualisLicitLepcso : 500));
  fd.append("osszeg", kuldendoLicit);
  fetch(detailApiUrl, { method: "POST", body: fd })
    .then(r => r.json())
    .then((data) => {
      if (data && data.error) {
        alert(data.error);
      }
      frissit();
    })
    .catch(() => {});
}

function doLiveToggle() {
  if (!ownerCanToggleLive) return;
  const fd = createApiFormData();
  fd.append("live_toggle", liveActive ? "stop" : "start");
  fetch(detailApiUrl, { method: "POST", body: fd })
    .then(r => r.json())
    .then(() => {
      liveFallbackVideoForced = false;
      frissit();
    })
    .catch(() => {});
}

function doLicit() {
  if ((ajanlatTipus === "fix" || ajanlatTipus === "kupon")) {
    if (aktualisAllapot !== "live" || sajatAukcio || !profilTeljes || fixDarabszam < 1) return;
    const biztos = window.confirm("Biztos megveszed?\n\nEz fizetesi kotelezettseggel jar.\nAz Igen gombra kattintva automatikusan elfogadod a vasarlasi felteteleket.");
    if (!biztos) return;
    const fd = createApiFormData();
    fd.append("fix_vetel", "1");
    fetch(detailApiUrl, { method: "POST", body: fd })
      .then(r => r.json())
      .then(() => frissit())
      .catch(() => {});
    return;
  }

  if (manualStartRequired) {
    if (!manualStartEnabled) return;
    const fd = createApiFormData();
    fd.append("licit_start", "1");
    fetch(detailApiUrl, { method: "POST", body: fd })
      .then(r => r.json())
      .then(() => {
        const most = Date.now() + szerverIdoEltolas;
        manualStartRequired = false;
        manualStartEnabled = false;
        aktualisAllapot = "live";
        if (!lejaratIdo || lejaratIdo <= most) {
          lejaratIdo = most + getDetailLicitDurationMs();
        }
        frissitAllapotUI();
        setTimeout(frissit, 150);
        setTimeout(frissit, 1000);
      })
      .catch(() => {});
    return;
  }

  if (aktualisAllapot !== "live" || sajatAukcio || !profilTeljes) return;
  const fd = createApiFormData();
  const kuldendoLicit = Number.isFinite(minLicit) && minLicit > 0
    ? minLicit
    : ((parseInt((document.getElementById("price")?.innerText || "").replace(/[^0-9]/g, ""), 10) || 0) + (Number.isFinite(aktualisLicitLepcso) && aktualisLicitLepcso > 0 ? aktualisLicitLepcso : 500));
  fd.append("osszeg", kuldendoLicit);
  fetch(detailApiUrl, { method: "POST", body: fd })
    .then(r => r.json())
    .then((data) => {
      if (data && data.error) {
        alert(data.error);
      }
      frissit();
    })
    .catch(() => {});
}

let fixPurchaseModalResolver = null;
let fixPurchaseRequestInFlight = false;

function openFixPurchaseModal() {
  const modal = document.getElementById("fix-purchase-modal");
  if (!modal) return Promise.resolve(true);
  modal.classList.add("is-open");
  modal.setAttribute("aria-hidden", "false");
  document.body.classList.add("has-site-modal");
  return new Promise((resolve) => {
    fixPurchaseModalResolver = resolve;
  });
}

function closeFixPurchaseModal(isConfirmed) {
  const modal = document.getElementById("fix-purchase-modal");
  if (modal) {
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
  }
  document.body.classList.remove("has-site-modal");
  if (typeof fixPurchaseModalResolver === "function") {
    const resolve = fixPurchaseModalResolver;
    fixPurchaseModalResolver = null;
    resolve(!!isConfirmed);
  }
}

function startFixPurchase() {
  if (fixPurchaseRequestInFlight) return;
  fixPurchaseRequestInFlight = true;
  const fd = createApiFormData();
  fd.append("fix_vetel", "1");
  const visibleFixAmount = parseInt((document.getElementById("price")?.innerText || "").replace(/[^0-9]/g, ""), 10) || 0;
  if (visibleFixAmount > 0) {
    fd.append("fix_client_amount", String(visibleFixAmount));
  }
  fetch(detailApiUrl, { method: "POST", body: fd })
    .then(r => r.json())
    .then((data) => {
      fixPurchaseRequestInFlight = false;
      if (data && data.checkout_url) {
        window.location.href = data.checkout_url;
        return;
      }
      if (data && data.ok) {
        frissit();
        return;
      }
      if (data && data.error) {
        alert(data.error);
        return;
      }
      alert("A Stripe fizet\u00E9si folyamat nem tudott elindulni. Pr\u00F3b\u00E1ld \u00FAjra p\u00E1r m\u00E1sodperc m\u00FAlva.");
    })
    .catch(() => {
      fixPurchaseRequestInFlight = false;
      alert("H\u00E1l\u00F3zati hiba t\u00F6rt\u00E9nt a fizet\u00E9s ind\u00EDt\u00E1sakor. Friss\u00EDts r\u00E1 az oldalra, \u00E9s pr\u00F3b\u00E1ld \u00FAjra.");
    });
}

function confirmFixPurchase() {
  closeFixPurchaseModal(false);
  startFixPurchase();
}

document.addEventListener("keydown", function (event) {
  if (event.key !== "Escape") return;
  const modal = document.getElementById("fix-purchase-modal");
  if (modal && modal.classList.contains("is-open")) {
    closeFixPurchaseModal(false);
  }
});

function openBidInfoModal() {
  const modal = document.getElementById("bid-info-modal");
  if (!modal) return;
  modal.classList.add("is-open");
  modal.setAttribute("aria-hidden", "false");
  document.body.classList.add("has-site-modal");
}

function closeBidInfoModal() {
  const modal = document.getElementById("bid-info-modal");
  if (!modal) return;
  modal.classList.remove("is-open");
  modal.setAttribute("aria-hidden", "true");
  const fixModal = document.getElementById("fix-purchase-modal");
  if (!fixModal || !fixModal.classList.contains("is-open")) {
    document.body.classList.remove("has-site-modal");
  }
}

function openKuponReszletekModal() {
  const modal = document.getElementById("kupon-reszletek-modal");
  if (!modal) return;
  modal.classList.add("is-open");
  modal.setAttribute("aria-hidden", "false");
  document.body.classList.add("has-site-modal");
}

function closeKuponReszletekModal() {
  const modal = document.getElementById("kupon-reszletek-modal");
  if (!modal) return;
  modal.classList.remove("is-open");
  modal.setAttribute("aria-hidden", "true");
  const bidModal = document.getElementById("bid-info-modal");
  const fixModal = document.getElementById("fix-purchase-modal");
  if ((!bidModal || !bidModal.classList.contains("is-open")) && (!fixModal || !fixModal.classList.contains("is-open"))) {
    document.body.classList.remove("has-site-modal");
  }
}

function openSidebarInfoModal(modalType) {
  const modal = document.getElementById("sidebar-info-modal");
  const title = document.getElementById("sidebar-info-modal-title");
  const text = document.getElementById("sidebar-info-modal-text");
  if (!modal || !title || !text) return;
  const contentMap = <?php echo $_sidebarInfoModalContentJson; ?>;
  const fallbackEntry = contentMap.gyik || { title: "GYIK", html: "" };
  const selectedEntry = contentMap[modalType] || fallbackEntry;
  title.innerText = selectedEntry.title || fallbackEntry.title;
  text.innerHTML = selectedEntry.html || fallbackEntry.html;

  modal.classList.add("is-open");
  modal.setAttribute("aria-hidden", "false");
  document.body.classList.add("has-site-modal");
}

function closeSidebarInfoModal() {
  const modal = document.getElementById("sidebar-info-modal");
  if (!modal) return;
  modal.classList.remove("is-open");
  modal.setAttribute("aria-hidden", "true");
  const bidModal = document.getElementById("bid-info-modal");
  const fixModal = document.getElementById("fix-purchase-modal");
  if ((!bidModal || !bidModal.classList.contains("is-open")) && (!fixModal || !fixModal.classList.contains("is-open"))) {
    document.body.classList.remove("has-site-modal");
  }
}

function doLicit() {
  if ((ajanlatTipus === "fix" || ajanlatTipus === "kupon")) {
    if (orszagZarAktiv) {
      alert("Ezen a piactĂ©ren csak nĂ©zni tudsz. VĂˇsĂˇrlĂˇs, chat Ă©s licit a sajĂˇt orszĂˇgod piacĂˇn engedĂ©lyezett.");
      return;
    }
    if (!profilTeljes) {
      alert("A vĂˇsĂˇrlĂˇshoz elĹ‘bb tĂ¶ltsd ki a profiladatokat Ă©s ments bankkĂˇrtyĂˇt.");
      return;
    }
    if (aktualisAllapot !== "live" || sajatAukcio || fixDarabszam < 1) return;
    openFixPurchaseModal().then((biztos) => {
      if (!biztos) return;
      const fd = createApiFormData();
      fd.append("fix_vetel", "1");
      fetch(detailApiUrl, { method: "POST", body: fd })
        .then(r => r.json())
        .then((data) => {
          if (data && data.checkout_url) {
            window.location.href = data.checkout_url;
            return;
          }
          if (data && data.error) {
            alert(data.error);
            return;
          }
          alert("A Stripe fizet\u00E9si folyamat nem tudott elindulni. Pr\u00F3b\u00E1ld \u00FAjra p\u00E1r m\u00E1sodperc m\u00FAlva.");
        })
        .catch(() => {
          alert("H\u00E1l\u00F3zati hiba t\u00F6rt\u00E9nt a fizet\u00E9s ind\u00EDt\u00E1sakor. Friss\u00EDts r\u00E1 az oldalra, \u00E9s pr\u00F3b\u00E1ld \u00FAjra.");
        });
    });
    return;
  }

  if (manualStartRequired) {
    if (!manualStartEnabled) return;
    const fd = createApiFormData();
    fd.append("licit_start", "1");
    fetch(detailApiUrl, { method: "POST", body: fd })
      .then(r => r.json())
      .then(() => {
        const most = Date.now() + szerverIdoEltolas;
        manualStartRequired = false;
        manualStartEnabled = false;
        aktualisAllapot = "live";
        if (!lejaratIdo || lejaratIdo <= most) {
          lejaratIdo = most + getDetailLicitDurationMs();
        }
        frissitAllapotUI();
        setTimeout(frissit, 150);
        setTimeout(frissit, 1000);
      })
      .catch(() => {});
    return;
  }

  if (orszagZarAktiv) {
    alert("Ezen a piactĂ©ren csak nĂ©zni tudsz. VĂˇsĂˇrlĂˇs, chat Ă©s licit a sajĂˇt orszĂˇgod piacĂˇn engedĂ©lyezett.");
    return;
  }
  if (!profilTeljes) {
    alert("A licitĂˇlĂˇshoz elĹ‘bb tĂ¶ltsd ki a profiladatokat Ă©s ments bankkĂˇrtyĂˇt.");
    return;
  }
  if (aktualisAllapot !== "live" || sajatAukcio) return;
  const fd = createApiFormData();
  const kuldendoLicit = Number.isFinite(minLicit) && minLicit > 0
    ? minLicit
    : ((parseInt((document.getElementById("price")?.innerText || "").replace(/[^0-9]/g, ""), 10) || 0) + (Number.isFinite(aktualisLicitLepcso) && aktualisLicitLepcso > 0 ? aktualisLicitLepcso : 500));
  fd.append("osszeg", kuldendoLicit);
  fetch(detailApiUrl, { method: "POST", body: fd })
    .then(r => r.json())
    .then((data) => {
      if (data && data.error) {
        alert(data.error);
      }
      frissit();
    })
    .catch(() => {});
}

function frissitAllapotUI() {
  const statusEl = document.getElementById("auction-status");
  const detailTypeLabelEl = document.getElementById("detail-type-label");
  const detailLiveFlagEl = document.getElementById("detail-live-flag");
  const countdownEl = document.getElementById("countdown");
  const detailCenterCountdownEl = document.getElementById("detail-center-countdown");
  const bidButton = document.getElementById("bid-button");
  const bidButtonTextEl = document.getElementById("bid-button-text");
  const paymentNoteEl = document.getElementById("detail-payment-note");
  const nextBidValue = document.getElementById("next-bid-value");
  const ownerHint = document.getElementById("owner-bid-hint");
  const liveToggleWrap = document.getElementById("detail-owner-live-wrap");
  const liveToggleButton = document.getElementById("live-toggle-button");
  const nextAuctionEl = document.getElementById("next-auction-countdown");
  const bidLabel = document.querySelector(".detail-bidbox__label");
  const bidderEl = document.getElementById("bidder");
  const minBidText = document.getElementById("min-bid-text");
  const originalPriceLabelEl = document.getElementById("original-price-label");
  const originalPriceEl = document.getElementById("original-price");
  const currentPriceLabelEl = document.getElementById("current-price-label");
  const priceEl = document.getElementById("price");
  const liveToggleButtonTextEl = document.getElementById("live-toggle-button-text");
  const kezdesHatralevo = countdownSecondsLeft(kezdesIdo);
  const lejaratHatralevo = countdownSecondsLeft(lejaratIdo);
  const shouldShowSoonCountdown = aktualisAllapot === "soon" && kezdesHatralevo > 0 && kezdesHatralevo <= 300;
  const shouldShowDetailLiveCountdown = aktualisAllapot === "live" && lejaratHatralevo > 0;
  const shouldShowOverlayLiveCountdown = aktualisAllapot === "live" && lejaratHatralevo > 0 && lejaratHatralevo <= 60;

  if (ownerHint) {
    ownerHint.style.display = sajatAukcio ? "block" : "none";
    if (sajatAukcio && (ajanlatTipus === "fix" || ajanlatTipus === "kupon")) {
      ownerHint.innerText = "Ez a sajat ajanlatod, ezert nem tudsz vasarolni, de chatelni igen.";
    } else if (sajatAukcio) {
      ownerHint.innerText = "Ez a sajat aukciod, ezert licitalni nem tudsz, de chatelni igen.";
    }
  }

  if (bidLabel) bidLabel.style.display = "none";

  if ((ajanlatTipus === "fix" || ajanlatTipus === "kupon")) {
    if (paymentNoteEl) paymentNoteEl.style.display = "none";
    if (bidderEl) {
      bidderEl.style.display = "none";
      bidderEl.innerText = "";
    }
    if (bidButtonTextEl) bidButtonTextEl.innerText = "Megveszem";
    if (minBidText) minBidText.innerText = "Darabszam: " + fixDarabszam + " elerheto / " + fixDarabszamOsszes + " osszesen";
    if (currentPriceLabelEl) currentPriceLabelEl.style.display = "none";
    if (priceEl) priceEl.style.display = "none";
  } else {
    if (paymentNoteEl) paymentNoteEl.style.display = sajatAukcio ? "none" : "";
    if (bidderEl) {
      bidderEl.style.display = "";
      bidderEl.innerText = (aktualisVezetoLicitalo && aktualisVezetoLicitalo !== "Meg nincs licit")
        ? ("Nyertes " + aktualisVezetoLicitalo)
        : "Meg nincs licit";
    }
    if (bidButtonTextEl) bidButtonTextEl.innerText = (manualStartRequired && sajatAukcio) ? "Start" : "Licitalok";
    if (minBidText) {
      minBidText.innerText = (isMulti && aktualisAllapot === "closed") ? "" : ("Licitlepcso " + aktualisLicitLepcso + " Ft");
    }
    if (currentPriceLabelEl) currentPriceLabelEl.style.display = "";
    if (priceEl) priceEl.style.display = "";
  }

  if (originalPriceLabelEl && originalPriceEl) {
    if ((ajanlatTipus === "fix" || ajanlatTipus === "kupon") && fixEredetiAr > 0) {
      originalPriceLabelEl.style.display = "";
      originalPriceEl.style.display = "";
      originalPriceEl.innerText = fixEredetiAr + " Ft";
    } else {
      originalPriceLabelEl.style.display = "none";
      originalPriceEl.style.display = "none";
      originalPriceEl.innerText = "";
    }
  }

  if (detailTypeLabelEl) {
    detailTypeLabelEl.innerText = (ajanlatTipus === "fix") ? "FIX \u00C1R" : ((ajanlatTipus === "kupon") ? "KUPON" : (isMulti ? "LICIT SHOP" : "LICIT"));
  }

  const detailMobileTypeLabelEl = document.getElementById("detail-mobile-type-label");
  if (detailMobileTypeLabelEl) {
    detailMobileTypeLabelEl.innerText = getAjanlatTipusKod(ajanlatTipus);
  }

  if (statusEl) {
    statusEl.className = "live-badge";
    if (aktualisAllapot === "soon") {
      statusEl.classList.add("live-badge--soon");
      statusEl.style.display = "inline-flex";
      statusEl.innerText = "K\u00D6ZELG\u0150";
    } else if (aktualisAllapot === "closed") {
      statusEl.classList.add("live-badge--closed");
      statusEl.style.display = "inline-flex";
      statusEl.innerText = "LEZ\u00C1RT";
    } else {
      statusEl.classList.add("live-badge--live");
      statusEl.style.display = "inline-flex";
      statusEl.innerText = "MOST";
    }
  }

  if (countdownEl) {
    countdownEl.style.display = "none";
    countdownEl.innerText = "";
    if (shouldShowSoonCountdown) {
      countdownEl.style.display = "";
      countdownEl.innerText = formatCountdown(kezdesIdo);
    } else if (shouldShowDetailLiveCountdown) {
      countdownEl.style.display = "";
      countdownEl.innerText = formatCountdown(lejaratIdo);
    }
  }

  if (bidButton) {
    bidButton.disabled = (ajanlatTipus === "fix" || ajanlatTipus === "kupon")
      ? (aktualisAllapot !== "live" || sajatAukcio || !profilTeljes || fixDarabszam < 1)
      : (aktualisAllapot !== "live" || !profilTeljes || (manualStartRequired ? !manualStartEnabled : sajatAukcio));
  }

  if (detailLiveFlagEl) {
    detailLiveFlagEl.style.display = (aktualisAllapot === "live" && liveActive) ? "inline-flex" : "none";
  }
  const detailMobileLiveFlagEl = document.getElementById("detail-live-flag-mobile");
  if (detailMobileLiveFlagEl) {
    detailMobileLiveFlagEl.style.display = (aktualisAllapot === "live" && liveActive) ? "inline-flex" : "none";
  }

  if (liveToggleWrap && liveToggleButton) {
    const lathato = ownerCanToggleLive && aktualisAllapot === "live";
    liveToggleWrap.style.display = lathato ? "block" : "none";
    liveToggleButton.disabled = !lathato;
  }

  if (nextBidValue) {
    if ((!Number.isFinite(minLicit) || minLicit <= 0) && priceEl) {
      const aktualisArSzam = parseInt((priceEl.innerText || "").replace(/[^0-9]/g, ""), 10) || 0;
      const lepcsoSzam = (Number.isFinite(aktualisLicitLepcso) && aktualisLicitLepcso > 0) ? aktualisLicitLepcso : 500;
      minLicit = aktualisArSzam + lepcsoSzam;
    }
    if ((ajanlatTipus === "fix" || ajanlatTipus === "kupon")) {
      nextBidValue.innerText = (priceEl?.innerText) || "0 Ft";
    } else if (isMulti && aktualisAllapot === "closed") {
      nextBidValue.innerText = "";
    } else if (manualStartRequired && sajatAukcio) {
      nextBidValue.innerText = "Licit";
    } else {
      nextBidValue.innerText = minLicit + " Ft";
    }
    nextBidValue.style.display = (isMulti && aktualisAllapot === "closed") ? "none" : "";
  }

  if (nextAuctionEl) {
    if (isMulti && shouldShowSoonCountdown) {
      nextAuctionEl.style.display = "block";
      nextAuctionEl.innerText = "Kovetkezo aukcio kezdes: " + formatCountdown(kezdesIdo);
    } else if (isMulti && aktualisAllapot === "live" && manualStartRequired && !sajatAukcio) {
      nextAuctionEl.style.display = "block";
      nextAuctionEl.innerText = "Hamarosan indul a kovetkezo licit!";
    } else {
      nextAuctionEl.style.display = "none";
      nextAuctionEl.innerText = "";
    }
  }

  if (detailCenterCountdownEl) {
    detailCenterCountdownEl.style.display = "none";
    detailCenterCountdownEl.innerHTML = "";
    if (shouldShowSoonCountdown) {
      detailCenterCountdownEl.style.display = "grid";
      detailCenterCountdownEl.innerHTML = '<span>Indul\u00E1s</span><strong>' + formatCountdown(kezdesIdo) + '</strong>';
    } else if (shouldShowOverlayLiveCountdown) {
      detailCenterCountdownEl.style.display = "grid";
      detailCenterCountdownEl.innerHTML = '<span>Lej\u00E1rat</span><strong>' + formatCountdown(lejaratIdo) + '</strong>';
    }
  }

  if (liveToggleButtonTextEl) {
    liveToggleButtonTextEl.innerText = liveActive ? "LIVE STOP" : "LIVE START";
  }
}

setInterval(frissit, 2000);
setInterval(frissitAllapotUI, 1000);
frissit();
setupDetailCopy();
window.addEventListener("resize", setupDetailCopy);

function doChat() {
  if (orszagZarAktiv) {
    alert("Ezen a piactĂ©ren csak nĂ©zni tudsz. Chat a sajĂˇt orszĂˇgod piacĂˇn engedĂ©lyezett.");
    return;
  }
  if (!profilTeljes) {
    alert("A chat hasznĂˇlatĂˇhoz elĹ‘bb tĂ¶ltsd ki a profiladatokat Ă©s ments bankkĂˇrtyĂˇt.");
    return;
  }
  const i = document.getElementById("chat_val");
  if (!i.value.trim()) return;
  const fd = createApiFormData();
  fd.append("uzenet", i.value);
  fetch(detailApiUrl, { method: "POST", body: fd })
    .then(r => r.json())
    .then((data) => {
      if (data && data.error) {
        alert(data.error);
        return;
      }
      i.value = "";
      frissit();
    })
    .catch(() => {});
}

function toggleEmojiPanel(event) {
  if (event) event.stopPropagation();
  const panel = document.getElementById("detail-emoji-panel");
  if (!panel) return;
  panel.classList.toggle("is-open");
}

function closeEmojiPanel() {
  const panel = document.getElementById("detail-emoji-panel");
  if (!panel) return;
  panel.classList.remove("is-open");
}

function insertEmoji(emoji) {
  const input = document.getElementById("chat_val");
  if (!input) return;
  const start = input.selectionStart ?? input.value.length;
  const end = input.selectionEnd ?? input.value.length;
  input.value = input.value.slice(0, start) + emoji + input.value.slice(end);
  const next = start + emoji.length;
  input.setSelectionRange(next, next);
  input.focus();
}

document.addEventListener("click", function (event) {
  const panel = document.getElementById("detail-emoji-panel");
  const button = document.getElementById("detail-emoji-btn");
  if (!panel || !button) return;
  if (!panel.contains(event.target) && !button.contains(event.target)) {
    panel.classList.remove("is-open");
  }
});

function copyShareLink(event) {
  if (event) {
    event.preventDefault();
    event.stopPropagation();
  }
  if (navigator.share) {
    navigator.share({
      title: shareTitle,
      text: shareTitle,
      url: shareUrl
    }).catch(() => {});
    return;
  }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(shareUrl)
      .then(() => {
        alert("A linket bem\u00E1soltuk a v\u00E1g\u00F3lapra.");
      })
      .catch(() => {
        alert("A megoszt\u00E1si link: " + shareUrl);
      });
    return;
  }
  const temp = document.createElement("input");
  temp.value = shareUrl;
  document.body.appendChild(temp);
  temp.select();
  document.execCommand("copy");
  document.body.removeChild(temp);
  alert("A linket bem\u00E1soltuk a v\u00E1g\u00F3lapra.");
}
</script>
<?php else: ?>
<script>
function scrollCategoryStrip(direction) {
  const el = document.getElementById("auction-category-strip");
  if (!el) return;
  const step = Math.round(Math.max(el.clientWidth * 0.7, 220));
  el.scrollBy({
    left: direction * step,
    behavior: "smooth"
  });
}

function scrollFeed(direction) {
  const el = document.getElementById("feed-scroll-area");
  if (!el) return;
  el.scrollBy({
    top: direction * Math.round(window.innerHeight * 0.72),
    behavior: "smooth"
  });
}

function formatFeedCountdown(diff) {
  const safe = Math.max(0, Math.floor(diff));
  const ora = Math.floor(safe / 3600);
  const perc = Math.floor((safe % 3600) / 60);
  const mp = safe % 60;
  if (ora > 0) {
    return ora + ":" + String(perc).padStart(2, "0") + ":" + String(mp).padStart(2, "0");
  }
  return String(perc).padStart(2, "0") + ":" + String(mp).padStart(2, "0");
}

function frissitKartyaVisszaszamlalokat() {
  document.querySelectorAll(".auction-card__countdown[data-countdown-target]").forEach(function (node) {
    const target = parseInt(node.getAttribute("data-countdown-target") || "0", 10);
    const mode = node.getAttribute("data-countdown-mode") || "end";
    if (!target) return;
    const diff = target - Math.floor(Date.now() / 1000);
    const shouldShow =
      (mode === "start" && diff > 0 && diff <= 300) ||
      (mode !== "start" && diff > 0 && diff <= 60);
    node.style.display = shouldShow ? "" : "none";
    if (shouldShow) {
      node.innerText = formatFeedCountdown(diff);
    }
  });

  document.querySelectorAll(".auction-card__center-countdown").forEach(function (node) {
    const startTarget = parseInt(node.getAttribute("data-start-target") || "0", 10);
    const endTarget = parseInt(node.getAttribute("data-end-target") || "0", 10);
    const now = Math.floor(Date.now() / 1000);
    node.style.display = "none";
    node.innerHTML = "";

    if (startTarget > now) {
      const startDiff = startTarget - now;
      if (startDiff <= 300) {
        node.style.display = "grid";
        node.innerHTML = "<span>Indul&aacute;s</span><strong>" + formatFeedCountdown(startDiff) + "</strong>";
        return;
      }
    }

    if (endTarget > now) {
      const endDiff = endTarget - now;
      if (endDiff <= 60) {
        node.style.display = "grid";
        node.innerHTML = "<span>Lej&aacute;rat</span><strong>" + formatFeedCountdown(endDiff) + "</strong>";
      }
    }
  });
}

frissitKartyaVisszaszamlalokat();
setInterval(frissitKartyaVisszaszamlalokat, 1000);
</script>
<?php endif; ?>
<script>
function openSidebarInfoModal(modalType) {
  const modal = document.getElementById("sidebar-info-modal");
  const title = document.getElementById("sidebar-info-modal-title");
  const text = document.getElementById("sidebar-info-modal-text");
  if (!modal || !title || !text) return;
  const contentMap = <?php echo $_sidebarInfoModalContentJson; ?>;
  const fallbackEntry = contentMap.gyik || { title: "GYIK", html: "" };
  const selectedEntry = contentMap[modalType] || fallbackEntry;
  title.innerText = selectedEntry.title || fallbackEntry.title;
  text.innerHTML = selectedEntry.html || fallbackEntry.html;

  modal.classList.add("is-open");
  modal.setAttribute("aria-hidden", "false");
  document.body.classList.add("has-site-modal");
}

function closeSidebarInfoModal() {
  const modal = document.getElementById("sidebar-info-modal");
  if (!modal) return;
  modal.classList.remove("is-open");
  modal.setAttribute("aria-hidden", "true");
  const bidModal = document.getElementById("bid-info-modal");
  const fixModal = document.getElementById("fix-purchase-modal");
  if ((!bidModal || !bidModal.classList.contains("is-open")) && (!fixModal || !fixModal.classList.contains("is-open"))) {
    document.body.classList.remove("has-site-modal");
  }
}

document.addEventListener("keydown", function (event) {
  if (event.key === "Escape") {
    closeSidebarInfoModal();
  }
});
</script>
</body>
</html>








