<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


include __DIR__ . '/db.php';
include __DIR__ . '/auth.php';
include __DIR__ . '/email_helper.php';
include __DIR__ . '/stripe_helper.php';
include_once __DIR__ . '/payout_helper.php';
include_once __DIR__ . '/global/sidebar_info_content.php';
include_once __DIR__ . '/global/i18n.php';
if (function_exists('shobidPayoutMaybeRunDailyAuto')) {
    shobidPayoutMaybeRunDailyAuto($conn);
}
if (!isset($_SESSION['user_id'])) { header("Location: bejelentezes.php"); exit; }

if (function_exists('shobidMarketEnsureTermekekOrszagKod')) {
    shobidMarketEnsureTermekekOrszagKod($conn);
}

$profileLocaleCode = function_exists('shobidI18nCurrentLocale') ? shobidI18nCurrentLocale() : 'hu-hu';
$profilOrszagOpcioLista = function_exists('shobidMarketList') ? shobidMarketList() : [];
if (empty($profilOrszagOpcioLista)) {
    $profilOrszagOpcioLista = [
        ['code' => 'hu-hu', 'name' => 'Hungary', 'label' => 'HU-HU', 'available' => true]
    ];
}
$profilElerhetoOrszagKodok = [];
foreach ($profilOrszagOpcioLista as $orszagSor) {
    if (isset($orszagSor['available']) && !$orszagSor['available']) {
        continue;
    }
    $orszagKod = strtolower(trim((string)($orszagSor['code'] ?? '')));
    if ($orszagKod === '') {
        continue;
    }
    $profilElerhetoOrszagKodok[$orszagKod] = true;
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

function profilForditottKategoriNev($kategoriaId, $fallbackNev, $locale = null) {
    if (function_exists('shobidI18nCategoryName')) {
        return shobidI18nCategoryName(intval($kategoriaId), (string)$fallbackNev, $locale);
    }
    return (string)$fallbackNev;
}

function profilForditSorKategoriat(&$row, $locale = null) {
    if (!is_array($row)) {
        return;
    }
    $kategoriaId = intval($row['kategoria_id'] ?? 0);
    $katNev = (string)($row['kat_nev'] ?? '');
    if ($kategoriaId > 0) {
        $row['kat_nev'] = profilForditottKategoriNev($kategoriaId, $katNev, $locale);
    }
}

function profilRenderCustomCountrySelect($name, $selectedCode, $orszagOpcioLista, $placeholder = 'Valassz orszagot', $locked = false) {
    $selectedCode = strtolower(trim((string)$selectedCode));
    $selectedLabel = $placeholder;
    $options = [];

    foreach ((array)$orszagOpcioLista as $orszagSor) {
        if (isset($orszagSor['available']) && !$orszagSor['available']) {
            continue;
        }
        $code = strtolower(trim((string)($orszagSor['code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $nameText = trim((string)($orszagSor['name'] ?? strtoupper($code)));
        $label = $nameText . ' (' . strtoupper($code) . ')';
        $options[] = ['code' => $code, 'label' => $label];
        if ($code === $selectedCode) {
            $selectedLabel = $label;
        }
    }
    ?>
    <div class="custom-select custom-select--category custom-select--country<?php echo $locked ? ' is-readonly' : ''; ?>" data-custom-select>
        <select name="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" required class="custom-select__native" data-custom-select-native>
            <option value=""><?php echo htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php foreach ($options as $orszagOption): ?>
            <option value="<?php echo htmlspecialchars((string)$orszagOption['code'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo (string)$orszagOption['code'] === $selectedCode ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string)$orszagOption['label'], ENT_QUOTES, 'UTF-8'); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="custom-select__trigger" data-custom-select-trigger aria-expanded="false" <?php echo $locked ? 'disabled' : ''; ?>>
            <span class="custom-select__value" data-custom-select-value><?php echo htmlspecialchars($selectedLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="custom-select__arrow" aria-hidden="true">⌄</span>
        </button>
        <div class="custom-select__menu" data-custom-select-menu hidden>
            <button type="button" class="custom-select__option<?php echo $selectedCode === '' ? ' is-selected' : ''; ?>" data-custom-select-option data-value="">
                <?php echo htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8'); ?>
            </button>
            <?php foreach ($options as $orszagOption): ?>
            <button type="button" class="custom-select__option<?php echo (string)$orszagOption['code'] === $selectedCode ? ' is-selected' : ''; ?>" data-custom-select-option data-value="<?php echo htmlspecialchars((string)$orszagOption['code'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars((string)$orszagOption['label'], ENT_QUOTES, 'UTF-8'); ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function profilMobilNezet() {
    $secChMobile = trim((string)($_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? ''));
    if ($secChMobile === '?1' || $secChMobile === '1') {
        return true;
    }

    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') {
        return false;
    }

    $mobilMintak = [
        'android',
        'iphone',
        'ipad',
        'ipod',
        'blackberry',
        'windows phone',
        'opera mini',
        'mobile',
        'silk',
        'kindle'
    ];

    foreach ($mobilMintak as $minta) {
        if (strpos($ua, $minta) !== false) {
            return true;
        }
    }

    return false;
}

function uzenetIdoSql($conn, $alias = 'u') {
    foreach (['letrehozva', 'created_at', 'datum', 'uzenet_ideje'] as $oszlop) {
        if (oszlopLetezik($conn, 'uzenetek', $oszlop)) {
            return "$alias.$oszlop";
        }
    }
    return "NOW()";
}

function licitNaploIdoSql($conn, $alias = 'ln') {
    foreach (['letrehozva', 'created_at', 'datum', 'licit_ideje'] as $oszlop) {
        if (oszlopLetezik($conn, 'licit_naplo', $oszlop)) {
            return "$alias.$oszlop";
        }
    }
    return "$alias.id";
}

function licitNaploMultiTetelOszlop($conn, $alias = 'ln') {
    foreach (['multi_tetel_id', 'tetel_id', 'multi_item_id', 'resz_tetel_id'] as $oszlop) {
        if (oszlopLetezik($conn, 'licit_naplo', $oszlop)) {
            if ($alias === null || $alias === '') {
                return $oszlop;
            }
            return "$alias.$oszlop";
        }
    }
    return null;
}

function licitNaploOsszegOszlop($conn, $alias = 'ln') {
    foreach (['licit_osszeg', 'osszeg', 'ar', 'licit_ar'] as $oszlop) {
        if (oszlopLetezik($conn, 'licit_naplo', $oszlop)) {
            if ($alias === null || $alias === '') {
                return $oszlop;
            }
            return "$alias.$oszlop";
        }
    }
    return null;
}

function licitShopTetelNevProfil($conn, $termekId, $felhasznaloId) {
    static $cache = [];
    $cacheKulcs = $termekId . ':' . $felhasznaloId;
    if (array_key_exists($cacheKulcs, $cache)) {
        return $cache[$cacheKulcs];
    }

    if (!tablaLetezik($conn, 'licit_naplo') || !tablaLetezik($conn, 'multi_aukcio_tetelek')) {
        $cache[$cacheKulcs] = '';
        return '';
    }

    $idoSql = licitNaploIdoSql($conn, 'ln');
    $tetelOszlop = licitNaploMultiTetelOszlop($conn, 'ln');
    if (!$tetelOszlop) {
        $cache[$cacheKulcs] = '';
        return '';
    }

    $termekId = intval($termekId);
    $felhasznaloId = intval($felhasznaloId);
    $res = $conn->query("SELECT $tetelOszlop AS tetel_id
        FROM licit_naplo ln
        WHERE ln.termek_id = $termekId
          AND ln.felhasznalo_id = $felhasznaloId
          AND $tetelOszlop IS NOT NULL
          AND $tetelOszlop > 0
        ORDER BY $idoSql DESC, ln.id DESC
        LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    $tetelId = intval($row['tetel_id'] ?? 0);
    if ($tetelId < 1) {
        if (!tablaLetezik($conn, 'multi_aukciok')) {
            $cache[$cacheKulcs] = '';
            return '';
        }

        $multiRes = $conn->query("SELECT ma.* FROM multi_aukciok ma WHERE ma.termek_id = $termekId LIMIT 1");
        $multi = $multiRes ? $multiRes->fetch_assoc() : null;
        if (!$multi) {
            $cache[$cacheKulcs] = '';
            return '';
        }

        $items = [];
        $itemsRes = $conn->query("SELECT id, nev FROM multi_aukcio_tetelek WHERE multi_aukcio_id = " . intval($multi['id']) . " ORDER BY sorszam ASC, id ASC");
        while ($itemsRes && ($item = $itemsRes->fetch_assoc())) {
            $items[] = $item;
        }
        if (!$items) {
            $cache[$cacheKulcs] = '';
            return '';
        }

        $fallbackIndex = 0;
        if (multiAktivTetelIndexOszlopVanProfil($conn) && multiAktivTetelStartOszlopVanProfil($conn)) {
            $currentIndex = max(0, intval($multi['aktiv_tetel_index'] ?? 0));
            $activeStartRaw = trim((string)($multi['aktiv_tetel_start'] ?? ''));
            if ($activeStartRaw === '' || $activeStartRaw === '0000-00-00 00:00:00') {
                $fallbackIndex = max(0, $currentIndex - 1);
            } else {
                $fallbackIndex = min($currentIndex, max(0, count($items) - 1));
            }
        }

        $fallbackNev = trim((string)($items[$fallbackIndex]['nev'] ?? ''));
        $cache[$cacheKulcs] = $fallbackNev;
        return $fallbackNev;
    }

    $tetelRes = $conn->query("SELECT nev FROM multi_aukcio_tetelek WHERE id = $tetelId LIMIT 1");
    $tetel = $tetelRes ? $tetelRes->fetch_assoc() : null;
    $nev = trim((string)($tetel['nev'] ?? ''));
    $cache[$cacheKulcs] = $nev;
    return $nev;
}

function biztositsProfilAktivitasRejtettTabla($conn) {
    if (!tablaLetezik($conn, 'profil_aktivitas_rejtett')) {
        $conn->query("CREATE TABLE IF NOT EXISTS profil_aktivitas_rejtett (
            user_id INT NOT NULL,
            termek_id INT NOT NULL,
            elrejtve DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, termek_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
}

function feldolgozLezartMultiTetelekProfilhoz($conn) {
    if (!tablaLetezik($conn, 'multi_aukciok') || !tablaLetezik($conn, 'multi_aukcio_tetelek')) {
        return;
    }

    $termekekRes = $conn->query("SELECT id, kezdes_idopont FROM termekek WHERE ajanlat_tipus = 'multi'");
    while ($termekekRes && ($termek = $termekekRes->fetch_assoc())) {
        $termekId = intval($termek['id'] ?? 0);
        if ($termekId < 1) {
            continue;
        }

        $multiRes = $conn->query("SELECT * FROM multi_aukciok WHERE termek_id = $termekId LIMIT 1");
        $multi = $multiRes ? $multiRes->fetch_assoc() : null;
        if (!$multi) {
            continue;
        }

        $items = [];
        $itemsRes = $conn->query("SELECT * FROM multi_aukcio_tetelek WHERE multi_aukcio_id = " . intval($multi['id']) . " ORDER BY sorszam ASC, id ASC");
        while ($itemsRes && ($row = $itemsRes->fetch_assoc())) {
            $items[] = $row;
        }

        if (!$items) {
            continue;
        }

        $mostTs = time();
        $kezdesTs = !empty($termek['kezdes_idopont']) ? strtotime((string)$termek['kezdes_idopont']) : 0;
        if ($kezdesTs > $mostTs) {
            continue;
        }

        if (multiAktivTetelIndexOszlopVanProfil($conn) && multiAktivTetelStartOszlopVanProfil($conn)) {
            $currentIndex = max(0, intval($multi['aktiv_tetel_index'] ?? 0));
            if ($currentIndex >= count($items)) {
                $conn->query("UPDATE termekek SET eladva = 1 WHERE id = $termekId");
                continue;
            }

            $activeStartRaw = trim((string)($multi['aktiv_tetel_start'] ?? ''));
            if ($activeStartRaw === '' || $activeStartRaw === '0000-00-00 00:00:00') {
                if ($currentIndex > 0) {
                    rogzitsMultiTetelNyertestProfil($conn, $termekId, $items[$currentIndex - 1]);
                }
                continue;
            }

            $activeStartTs = strtotime($activeStartRaw);
            $item = $items[$currentIndex];
            $itemEnd = $activeStartTs + max(1, intval($item['idotartam_mp'] ?? 60));
            if ($itemEnd > $mostTs) {
                continue;
            }

            rogzitsMultiTetelNyertestProfil($conn, $termekId, $item);

            $nextIndex = $currentIndex + 1;
            if ($nextIndex >= count($items)) {
                $conn->query("UPDATE multi_aukciok SET aktiv_tetel_index = $nextIndex, aktiv_tetel_start = NULL WHERE id = " . intval($multi['id']));
                $conn->query("UPDATE termekek SET eladva = 1, lejarat_idopont = NOW() WHERE id = $termekId");
            } else {
                $conn->query("UPDATE multi_aukciok SET aktiv_tetel_index = $nextIndex, aktiv_tetel_start = NULL WHERE id = " . intval($multi['id']));
            }
            continue;
        }

        $gap = max(0, intval($multi['szunet_mp'] ?? 15));
        $cursor = $kezdesTs;
        foreach ($items as $item) {
            $itemId = intval($item['id'] ?? 0);
            $itemEnd = $cursor + max(1, intval($item['idotartam_mp'] ?? 60));
            $marFeldolgozva = intval($item['eladva'] ?? 0) === 1;

            if ($itemId > 0 && !$marFeldolgozva && $mostTs >= $itemEnd) {
                rogzitsMultiTetelNyertestProfil($conn, $termekId, $item);
            }

            $cursor = $itemEnd + $gap;
        }
    }
}

function rogzitsMultiTetelNyertestProfil($conn, $termekId, $item) {
    $itemId = intval($item['id'] ?? 0);
    if ($itemId < 1) {
        return;
    }

    $nyertes = trim((string)($item['legmagasabb_licit_felhasznalo'] ?? ''));
    if ($nyertes === '') {
        return;
    }

    $osszegErtek = intval($item['aktualis_ar'] ?? 0);
    $chargeKey = 'multi|tetel|' . $itemId . '|vevo|' . $nyertes;
    $chargeRes = shobidStripeAutoChargeByNickname(
        $conn,
        $chargeKey,
        $nyertes,
        intval($termekId),
        $osszegErtek,
        trim((string)($item['nev'] ?? ('LICIT SHOP tétel #' . $itemId))),
        [
            'ajanlat_tipus' => 'multi',
            'multi_tetel_id' => (string)$itemId
        ]
    );
    if (empty($chargeRes['ok'])) {
        return;
    }

    if (intval($item['eladva'] ?? 0) !== 1) {
        $conn->query("UPDATE multi_aukcio_tetelek SET eladva = 1 WHERE id = $itemId");
    }

    $nyertesSql = $conn->real_escape_string($nyertes);
    $tetelNev = html_entity_decode(trim((string)($item['nev'] ?? '')), ENT_QUOTES, 'UTF-8');
    $osszeg = number_format($osszegErtek, 0, ' ', ' ');
    $uzenetSzoveg = html_entity_decode('&#127942; Megnyerte: ', ENT_QUOTES, 'UTF-8') . $tetelNev . ' - ' . $osszeg . ' Ft';
    $uzenetSql = $conn->real_escape_string($uzenetSzoveg);
    $letezikRes = $conn->query("SELECT id FROM uzenetek WHERE termek_id = $termekId AND felhasznalo = '$nyertesSql' AND szoveg = '$uzenetSql' LIMIT 1");
    $uzenetId = 0;
    if (!($letezikRes && $letezikRes->num_rows > 0)) {
        $conn->query("INSERT INTO uzenetek (felhasznalo, szoveg, termek_id) VALUES('$nyertesSql', '$uzenetSql', $termekId)");
        $uzenetId = intval($conn->insert_id);
    } else {
        $letezo = $letezikRes->fetch_assoc();
        $uzenetId = intval($letezo['id'] ?? 0);
    }
    if ($uzenetId > 0) {
        shobidKuldMultiNyertesEmail($conn, $termekId, $tetelNev, intval($item['aktualis_ar'] ?? 0), $nyertes, $uzenetId);
    }
    $termekRes = $conn->query("SELECT feltolto_id FROM termekek WHERE id = " . intval($termekId) . " LIMIT 1");
    $termekRow = $termekRes ? $termekRes->fetch_assoc() : null;
    shobidPayoutEnsureFromStatus(
        $conn,
        shobidPenzugyiAzonosito('multi', intval($termekId), $nyertes, $uzenetId),
        intval($termekId),
        intval($termekRow['feltolto_id'] ?? 0),
        $nyertes,
        'multi',
        $osszegErtek,
        $uzenetId,
        $tetelNev
    );
}

function rogzitsLezartLicitNyertestProfil($conn, $termek) {
    $tipus = (string)($termek['ajanlat_tipus'] ?? 'licit');
    if (!$termek || ($tipus !== '' && $tipus !== 'licit')) {
        return;
    }

    $termekId = intval($termek['id'] ?? 0);
    $nyertes = trim((string)($termek['legmagasabb_licit_felhasznalo'] ?? ''));
    $aktualisAr = intval($termek['aktualis_ar'] ?? 0);

    if (($nyertes === '' || $aktualisAr <= 0) && tablaLetezik($conn, 'licit_naplo')) {
        $licitIdoSql = licitNaploIdoSql($conn, 'ln');
        $osszegSelect = '0 AS licit_osszeg';
        foreach (['licit_osszeg', 'osszeg', 'ar', 'licit_ar'] as $osszegOszlop) {
            if (oszlopLetezik($conn, 'licit_naplo', $osszegOszlop)) {
                $osszegSelect = "ln.$osszegOszlop AS licit_osszeg";
                break;
            }
        }

        $naploRes = $conn->query("SELECT f.becenev, $osszegSelect
            FROM licit_naplo ln
            INNER JOIN felhasznalok f ON f.id = ln.felhasznalo_id
            WHERE ln.termek_id = $termekId
            ORDER BY $licitIdoSql DESC, ln.id DESC
            LIMIT 1");
        $naploSor = $naploRes ? $naploRes->fetch_assoc() : null;
        if ($naploSor) {
            if ($nyertes === '') {
                $nyertes = trim((string)($naploSor['becenev'] ?? ''));
            }
            if ($aktualisAr <= 0) {
                $aktualisAr = intval($naploSor['licit_osszeg'] ?? 0);
            }
        }
    }
    if (($nyertes === '' || $aktualisAr <= 0) && tablaLetezik($conn, 'uzenetek')) {
        $uzenetRes = $conn->query("SELECT felhasznalo, szoveg
            FROM uzenetek
            WHERE termek_id = $termekId
              AND szoveg LIKE '%Licit%Ft%'
            ORDER BY id DESC
            LIMIT 1");
        $uzenetSor = $uzenetRes ? $uzenetRes->fetch_assoc() : null;
        if ($uzenetSor) {
            if ($nyertes === '') {
                $nyertes = trim((string)($uzenetSor['felhasznalo'] ?? ''));
            }
            if ($aktualisAr <= 0 && preg_match('/([0-9 ]+)\s*Ft/u', (string)($uzenetSor['szoveg'] ?? ''), $talalat)) {
                $aktualisAr = intval(str_replace(' ', '', (string)($talalat[1] ?? '0')));
            }
        }
    }

    if ($termekId < 1 || $nyertes === '') {
        return;
    }

    $chargeKey = shobidPenzugyiAzonosito('licit', $termekId, $nyertes, 0);
    $chargeRes = shobidStripeAutoChargeByNickname(
        $conn,
        $chargeKey,
        $nyertes,
        $termekId,
        $aktualisAr,
        trim((string)($termek['nev'] ?? ('LICIT #' . $termekId))),
        [
            'ajanlat_tipus' => 'licit'
        ]
    );
    if (empty($chargeRes['ok'])) {
        return;
    }

    $nyertesUpdate = $conn->real_escape_string($nyertes);
    $conn->query("UPDATE termekek SET eladva = 1, legmagasabb_licit_felhasznalo = '$nyertesUpdate', aktualis_ar = " . max(0, $aktualisAr) . " WHERE id = $termekId");

    $nyertesSql = $conn->real_escape_string($nyertes);
    $uzenetSzoveg = html_entity_decode('&#127942; Megnyerte: ', ENT_QUOTES, 'UTF-8') . number_format($aktualisAr, 0, ' ', ' ') . " Ft";
    $uzenetSql = $conn->real_escape_string($uzenetSzoveg);
    $letezikRes = $conn->query("SELECT id FROM uzenetek WHERE termek_id = $termekId AND felhasznalo = '$nyertesSql' AND szoveg = '$uzenetSql' LIMIT 1");
    $uzenetId = 0;
    if (!($letezikRes && $letezikRes->num_rows > 0)) {
        $conn->query("INSERT INTO uzenetek (felhasznalo, szoveg, termek_id) VALUES('$nyertesSql', '$uzenetSql', $termekId)");
        $uzenetId = intval($conn->insert_id);
    } else {
        $letezo = $letezikRes->fetch_assoc();
        $uzenetId = intval($letezo['id'] ?? 0);
    }

    if ($uzenetId > 0) {
        shobidKuldLicitNyertesEmail($conn, $termekId, $nyertes);
    }
    shobidPayoutEnsureFromStatus(
        $conn,
        shobidPenzugyiAzonosito('licit', $termekId, $nyertes, 0),
        $termekId,
        intval($termek['feltolto_id'] ?? 0),
        $nyertes,
        'licit',
        $aktualisAr,
        $uzenetId,
        ''
    );
}

function feldolgozLezartLicitNyertesekProfilhoz($conn) {
    $res = $conn->query("SELECT * FROM termekek
        WHERE (ajanlat_tipus IS NULL OR ajanlat_tipus = '' OR ajanlat_tipus = 'licit')");
    while ($res && ($termek = $res->fetch_assoc())) {
        $allapot = getSingleLicitAllapotProfil($conn, $termek);
        if (($allapot['allapot'] ?? '') === 'closed') {
            rogzitsLezartLicitNyertestProfil($conn, $termek);
        }
    }
}

function licitStartedOszlopVanProfil($conn) {
    static $cache = null;
    if ($cache === null) {
        $cache = oszlopLetezik($conn, 'termekek', 'licit_started_at');
    }
    return $cache;
}

function licitIdotartamOszlopVanProfil($conn) {
    static $cache = null;
    if ($cache === null) {
        $cache = oszlopLetezik($conn, 'termekek', 'licit_idotartam_mp');
    }
    return $cache;
}

function multiAktivTetelIndexOszlopVanProfil($conn) {
    static $cache = null;
    if ($cache === null) {
        $cache = oszlopLetezik($conn, 'multi_aukciok', 'aktiv_tetel_index');
    }
    return $cache;
}

function multiAktivTetelStartOszlopVanProfil($conn) {
    static $cache = null;
    if ($cache === null) {
        $cache = oszlopLetezik($conn, 'multi_aukciok', 'aktiv_tetel_start');
    }
    return $cache;
}

function liveAktivOszlopVanProfil($conn) {
    static $cache = null;
    if ($cache === null) {
        $cache = oszlopLetezik($conn, 'termekek', 'live_active');
    }
    return $cache;
}

function getLicitIdotartamMpProfil($conn, $termek) {
    $duration = licitIdotartamOszlopVanProfil($conn) ? intval($termek['licit_idotartam_mp'] ?? 0) : 0;
    if ($duration > 0) return $duration;
    $kezdesTs = !empty($termek['kezdes_idopont']) ? strtotime((string)$termek['kezdes_idopont']) : 0;
    $lejaratTs = !empty($termek['lejarat_idopont']) ? strtotime((string)$termek['lejarat_idopont']) : 0;
    return ($kezdesTs > 0 && $lejaratTs > $kezdesTs) ? max(1, $lejaratTs - $kezdesTs) : 60;
}

function getSingleLicitAllapotProfil($conn, $termek) {
    $kezdesTs = !empty($termek['kezdes_idopont']) ? strtotime((string)$termek['kezdes_idopont']) : null;
    $lejaratTs = !empty($termek['lejarat_idopont']) ? strtotime((string)$termek['lejarat_idopont']) : null;
    $mostTs = time();
    $allapot = 'live';
    $manualStartRequired = false;
    if ($kezdesTs && $kezdesTs > $mostTs) {
        return ['allapot' => 'soon', 'kezdes_ts' => $kezdesTs, 'lejarat_ts' => $lejaratTs, 'manual_start_required' => false];
    }
    if (licitStartedOszlopVanProfil($conn)) {
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
            $lejaratTs = $startedTs + getLicitIdotartamMpProfil($conn, $termek);
            if ($lejaratTs <= $mostTs || intval($termek['eladva'] ?? 0) === 1) {
                $allapot = 'closed';
            }
        }
    } elseif (($lejaratTs && $lejaratTs <= $mostTs) || intval($termek['eladva'] ?? 0) === 1) {
        $allapot = 'closed';
    }
    return ['allapot' => $allapot, 'kezdes_ts' => $kezdesTs, 'lejarat_ts' => $lejaratTs, 'manual_start_required' => $manualStartRequired];
}

function getMultiAukcioAdatProfil($conn, $termek) {
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
    if ($kezdesTs > $nowTs) return ['phase' => 'soon', 'display_item' => $items[0] ?? null];
    if (multiAktivTetelIndexOszlopVanProfil($conn) && multiAktivTetelStartOszlopVanProfil($conn)) {
        $currentIndex = intval($multi['aktiv_tetel_index'] ?? 0);
        if ($currentIndex >= count($items) || intval($termek['eladva'] ?? 0) === 1) {
            return ['phase' => 'closed', 'display_item' => $items[max(0, count($items) - 1)] ?? null];
        }
        $activeStartRaw = trim((string)($multi['aktiv_tetel_start'] ?? ''));
        if ($activeStartRaw === '' || $activeStartRaw === '0000-00-00 00:00:00') {
            $displayIndex = $currentIndex > 0 ? ($currentIndex - 1) : 0;
            return ['phase' => 'ready', 'display_item' => $items[$displayIndex] ?? ($items[0] ?? null)];
        }
        $activeStartTs = strtotime($activeStartRaw);
        $activeItem = $items[$currentIndex];
        $activeEndTs = $activeStartTs + max(1, intval($activeItem['idotartam_mp'] ?? 60));
        return ['phase' => ($activeEndTs > $nowTs ? 'live' : 'closed'), 'display_item' => $activeItem];
    }
    $gap = max(0, intval($multi['szunet_mp'] ?? 15));
    $cursor = $kezdesTs;
    foreach ($items as $index => $item) {
        $itemEnd = $cursor + max(1, intval($item['idotartam_mp'] ?? 60));
        if ($nowTs < $itemEnd) return ['phase' => 'live', 'display_item' => $item];
        $nextStart = $itemEnd + $gap;
        if ($index < count($items) - 1 && $nowTs < $nextStart) return ['phase' => 'break', 'display_item' => $item];
        $cursor = $nextStart;
    }
    return ['phase' => 'closed', 'display_item' => $items[max(0, count($items) - 1)] ?? null];
}

function mentettProfilkepNev($file) {
    if (!isset($file) || $file['error'] !== 0) {
        return null;
    }

    $profilkepekDir = __DIR__ . '/profilkepek';

    if (!is_dir($profilkepekDir)) {
        mkdir($profilkepekDir, 0777, true);
    }

    $eredetiNev = basename($file['name']);
    $biztonsagosNev = preg_replace('/[^A-Za-z0-9._-]/', '_', $eredetiNev);
    $alapNev = pathinfo($biztonsagosNev, PATHINFO_FILENAME);
    $kiterjesztes = strtolower(pathinfo($biztonsagosNev, PATHINFO_EXTENSION));
    $engedelyezett = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    if (!in_array($kiterjesztes, $engedelyezett, true)) {
        return false;
    }

    if (function_exists('getimagesize')) {
        $info = @getimagesize($file['tmp_name']);
        $mime = strtolower((string)($info['mime'] ?? ''));
        $forras = null;
        if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
            $forras = function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($file['tmp_name']) : null;
        } elseif ($mime === 'image/png') {
            $forras = function_exists('imagecreatefrompng') ? @imagecreatefrompng($file['tmp_name']) : null;
        } elseif ($mime === 'image/webp') {
            $forras = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : null;
        } elseif ($mime === 'image/gif') {
            $forras = function_exists('imagecreatefromgif') ? @imagecreatefromgif($file['tmp_name']) : null;
        }

        if ($forras && function_exists('imagewebp')) {
            $szelesseg = imagesx($forras);
            $magassag = imagesy($forras);
            $maxOldal = 1200;
            $celSzelesseg = $szelesseg;
            $celMagassag = $magassag;

            if ($szelesseg > $maxOldal || $magassag > $maxOldal) {
                if ($szelesseg >= $magassag) {
                    $celSzelesseg = $maxOldal;
                    $celMagassag = (int)round(($magassag / max(1, $szelesseg)) * $maxOldal);
                } else {
                    $celMagassag = $maxOldal;
                    $celSzelesseg = (int)round(($szelesseg / max(1, $magassag)) * $maxOldal);
                }
            }

            $cel = imagecreatetruecolor($celSzelesseg, $celMagassag);
            imagealphablending($cel, true);
            imagesavealpha($cel, true);
            $atlatszo = imagecolorallocatealpha($cel, 255, 255, 255, 127);
            imagefill($cel, 0, 0, $atlatszo);

            imagecopyresampled($cel, $forras, 0, 0, 0, 0, $celSzelesseg, $celMagassag, $szelesseg, $magassag);

            $fajlnev = time() . '_' . mt_rand(1000, 9999) . '_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $alapNev) . '_profil.webp';
            $siker = @imagewebp($cel, $profilkepekDir . '/' . $fajlnev, 84);
            imagedestroy($cel);
            imagedestroy($forras);
            if ($siker) {
                return $fajlnev;
            }
        } elseif ($forras) {
            imagedestroy($forras);
        }
    }

    $fajlnev = time() . '_' . mt_rand(1000, 9999) . '_' . $biztonsagosNev;
    if (!move_uploaded_file($file['tmp_name'], $profilkepekDir . '/' . $fajlnev)) {
        return false;
    }

    return $fajlnev;
}

function feldolgozottAjanlatKepMenteseProfil($tmpPath, $celPath) {
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

function mentettAjanlatKepNevProfil($file) {
    if (!isset($file) || !is_array($file)) {
        return null;
    }
    if (intval($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (intval($file['error'] ?? 0) !== 0) {
        return false;
    }

    $kepekDir = __DIR__ . '/kepek/ajanlatok';
    if (!is_dir($kepekDir)) {
        mkdir($kepekDir, 0777, true);
    }

    $eredetiNev = basename((string)($file['name'] ?? ''));
    $biztonsagosNev = preg_replace('/[^A-Za-z0-9._-]/', '_', $eredetiNev);
    $alapNev = pathinfo($biztonsagosNev, PATHINFO_FILENAME);
    $fajlNev = time() . "_" . mt_rand(1000, 9999) . "_" . $alapNev . ".webp";
    $celPath = $kepekDir . "/" . $fajlNev;

    if (feldolgozottAjanlatKepMenteseProfil((string)$file['tmp_name'], $celPath)) {
        return 'ajanlatok/' . $fajlNev;
    }

    $fallbackNev = time() . "_" . mt_rand(1000, 9999) . "_" . $biztonsagosNev;
    if (!@move_uploaded_file((string)$file['tmp_name'], $kepekDir . "/" . $fallbackNev)) {
        return false;
    }
    return 'ajanlatok/' . $fallbackNev;
}

function penzugyiSzamitas($bruttoOsszeg, $locale = 'hu-hu') {
    $afaKulcs = function_exists('shobidPayoutVatRateByLocale')
        ? floatval(shobidPayoutVatRateByLocale($locale))
        : 0.27;
    $szamitas = shobidPayoutFinancialSplit($bruttoOsszeg, $locale);
    $brutto = floatval($szamitas['brutto'] ?? 0);
    $jutalekBrutto = floatval($szamitas['jutalek_brutto'] ?? 0);
    $kartyaBrutto = floatval($szamitas['kartya_brutto'] ?? 0);
    $kifizetesBrutto = floatval($szamitas['elado_osszeg'] ?? 0);
    $netto = $brutto / (1 + $afaKulcs);
    $jutalekNetto = $jutalekBrutto / (1 + $afaKulcs);
    $kartyaNetto = $kartyaBrutto / (1 + $afaKulcs);
    $kifizetesNetto = $kifizetesBrutto / (1 + $afaKulcs);
    return [
        'netto' => $netto,
        'jutalek_netto' => $jutalekNetto,
        'kartya_netto' => $kartyaNetto,
        'kifizetes_netto' => $kifizetesNetto,
        'brutto' => $brutto,
        'jutalek_brutto' => $jutalekBrutto,
        'kartya_brutto' => $kartyaBrutto,
        'kifizetes_brutto' => $kifizetesBrutto
    ];
}

function penzugyiStatuszTablaVanProfil($conn) {
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

function normalizaltPenzugyiStatuszProfil($statusz) {
    $engedelyezett = ['Postázásra vár', 'Postázva', 'Kiutalva'];
    return in_array($statusz, $engedelyezett, true) ? $statusz : 'Postázásra vár';
}

function vasarloStatuszProfil($statusz) {
    $normalizalt = normalizaltPenzugyiStatuszProfil($statusz);
    if ($normalizalt === 'Kiutalva') {
        return 'Postázva';
    }
    return in_array($normalizalt, ['Postázásra vár', 'Postázva'], true) ? $normalizalt : 'Postázásra vár';
}

function penzugyiSorAzonositoProfil($tipus, $termekId, $vasarloNev = '', $tetelNev = '', $datum = '') {
    return sha1(implode('|', [$tipus, intval($termekId), trim((string)$vasarloNev), trim((string)$tetelNev), trim((string)$datum)]));
}

function penzugyiMuveletKulcsProfil($azonosito, $muveletTipus) {
    return trim((string)$azonosito) . '|' . trim((string)$muveletTipus);
}

function penzugyiMuveletAzonositoProfil($tipus, $termekId = 0, $vasarloNev = '', $uzenetId = 0) {
    $tipus = trim((string)$tipus);
    if ($tipus === 'fix' || $tipus === 'multi' || $tipus === 'kupon') {
        return $tipus . '|uzenet|' . intval($uzenetId);
    }
    return 'licit|termek|' . intval($termekId) . '|vevo|' . trim((string)$vasarloNev);
}

function penzugyiMuveletekTablaVanProfil($conn) {
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

function mentsPostaDokumentumProfil($file) {
    if (!isset($file) || !is_array($file) || intval($file['error'] ?? UPLOAD_ERR_NO_FILE) !== 0) {
        return '';
    }

    $eredetiNev = basename((string)($file['name'] ?? ''));
    $biztonsagosNev = preg_replace('/[^A-Za-z0-9._-]/', '_', $eredetiNev);
    $kiterjesztes = strtolower((string)pathinfo($biztonsagosNev, PATHINFO_EXTENSION));
    $engedelyezett = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($kiterjesztes, $engedelyezett, true)) {
        return false;
    }

    $celMappa = __DIR__ . '/dokumentumok';
    if (!is_dir($celMappa)) {
        @mkdir($celMappa, 0777, true);
    }

    $fajlNev = time() . '_' . mt_rand(1000, 9999) . '_' . $biztonsagosNev;
    $celUt = $celMappa . '/' . $fajlNev;
    if (@move_uploaded_file((string)$file['tmp_name'], $celUt)) {
        return $fajlNev;
    }

    return false;
}

function penzugyiTipusLabelProfil($tipus) {
    if ($tipus === 'fix') return 'Fix ár';
    if ($tipus === 'kupon') return 'Kupon';
    if ($tipus === 'multi') return 'LICIT SHOP';
    return 'Licit';
}

function kuponTablaVanProfil($conn) {
    static $cache = null;
    if ($cache === null) {
        $cache = tablaLetezik($conn, 'kupon_vasarlasok');
    }
    return $cache;
}

function penzugyiDatumSzovegProfil($row) {
    $start = !empty($row['kezdes_idopont']) ? date('Y.m.d H:i', strtotime((string)$row['kezdes_idopont'])) : '-';
    $endForras = !empty($row['esemeny_datum']) ? (string)$row['esemeny_datum'] : (string)($row['lejarat_idopont'] ?? '');
    $end = $endForras !== '' ? date('Y.m.d H:i', strtotime($endForras)) : '-';
    return $start . ' - ' . $end;
}

function parseMultiNyertesUzenet($szoveg) {
    $eredmeny = [
        'nev' => '',
        'osszeg' => 0
    ];

    $szoveg = trim((string)$szoveg);
    if ($szoveg === '') {
        return $eredmeny;
    }

    if (preg_match('/Megnyerte:\s*(.*?)\s*-\s*([0-9 ]+)\s*Ft/u', $szoveg, $talalat)) {
        $eredmeny['nev'] = trim((string)($talalat[1] ?? ''));
        $eredmeny['osszeg'] = intval(str_replace(' ', '', (string)($talalat[2] ?? '0')));
    }

    return $eredmeny;
}

$user_id = $_SESSION['user_id'];
$becenev = $_SESSION['becenev'];
$uzenet = "";
$profilPiacKod = function_exists('shobidMarketActiveCode')
    ? shobidMarketActiveCode($conn, intval($user_id), $profileLocaleCode)
    : $profileLocaleCode;
$multiTablaVan = tablaLetezik($conn, 'multi_aukciok') && tablaLetezik($conn, 'multi_aukcio_tetelek');
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
$aszfElfogadvaOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'aszf_elfogadva');
if (!$aszfElfogadvaOszlopVan) {
    $conn->query("ALTER TABLE felhasznalok ADD aszf_elfogadva TINYINT(1) NOT NULL DEFAULT 0");
    $aszfElfogadvaOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'aszf_elfogadva');
}
$fizetesiSzabalyzatElfogadvaOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'fizetesi_szabalyzat_elfogadva');
if (!$fizetesiSzabalyzatElfogadvaOszlopVan) {
    $conn->query("ALTER TABLE felhasznalok ADD fizetesi_szabalyzat_elfogadva TINYINT(1) NOT NULL DEFAULT 0");
    $fizetesiSzabalyzatElfogadvaOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'fizetesi_szabalyzat_elfogadva');
}
$orszagKodOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'orszag_kod');
if (!$orszagKodOszlopVan) {
    $conn->query("ALTER TABLE felhasznalok ADD orszag_kod VARCHAR(10) NOT NULL DEFAULT 'hu-hu'");
    $orszagKodOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'orszag_kod');
}
$szallitasiNevOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'szallitasi_nev');
$szallitasiIranyitoszamOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'szallitasi_iranyitoszam');
$szallitasiVarosOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'szallitasi_varos');
$szallitasiUtcaOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'szallitasi_utca');
$szallitasiHazszamOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'szallitasi_hazszam');
$szallitasiEmeletAjtoOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'szallitasi_emelet_ajto');
$szallitasiMegjegyzesOszlopVan = oszlopLetezik($conn, 'felhasznalok', 'szallitasi_megjegyzes');
$szallitasiCimOszlopokMegvannak = $szallitasiNevOszlopVan
    && $szallitasiIranyitoszamOszlopVan
    && $szallitasiVarosOszlopVan
    && $szallitasiUtcaOszlopVan
    && $szallitasiHazszamOszlopVan
    && $szallitasiEmeletAjtoOszlopVan
    && $szallitasiMegjegyzesOszlopVan;
shobidStripeEnsureFelhasznaloFizetesiOszlopok($conn);
shobidStripeEnsureFelhasznaloConnectOszlopok($conn);
shobidStripeSzinkronFuggoFixVasarlasokFelhasznalonak($conn, $user_id);
feldolgozLezartMultiTetelekProfilhoz($conn);
feldolgozLezartLicitNyertesekProfilhoz($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !authValidateCsrfFromRequest()) {
    $uzenet = 'Biztonsagi token hiba. Frissitsd az oldalt, es probald ujra.';
    $_POST = [];
}

if (isset($_POST['stripe_fizetesi_setup_inditas'])) {
    $setupCheckout = shobidStripeFizetesiSetupCheckoutLetrehoz($conn, $user_id);
    if (!empty($setupCheckout['ok']) && !empty($setupCheckout['checkout_url'])) {
        header('Location: ' . $setupCheckout['checkout_url']);
        exit;
    }
    $uzenet = html_entity_decode("A Stripe fizet&eacute;si be&aacute;ll&iacute;t&aacute;s nem indult el: " . htmlspecialchars(trim((string)($setupCheckout['error'] ?? 'ismeretlen hiba')), ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
}

if (isset($_POST['stripe_connect_onboarding_inditas'])) {
    $connectLink = shobidStripeConnectOnboardingLink($conn, $user_id);
    if (!empty($connectLink['ok']) && !empty($connectLink['url'])) {
        header('Location: ' . $connectLink['url']);
        exit;
    }
    $uzenet = html_entity_decode("A Stripe kifizet&eacute;si fi&oacute;k csatlakoztat&aacute;sa nem indult el: " . htmlspecialchars(trim((string)($connectLink['error'] ?? 'ismeretlen hiba')), ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
}

if (isset($_POST['stripe_connect_dashboard_nyitas'])) {
    $dashboardLink = shobidStripeConnectDashboardLink($conn, $user_id);
    if (!empty($dashboardLink['ok']) && !empty($dashboardLink['url'])) {
        header('Location: ' . $dashboardLink['url']);
        exit;
    }
    $uzenet = html_entity_decode("A Stripe kifizet&eacute;si fi&oacute;k megnyit&aacute;sa nem siker&uuml;lt: " . htmlspecialchars(trim((string)($dashboardLink['error'] ?? 'ismeretlen hiba')), ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
}

if (isset($_GET['stripe_setup'])) {
    $setupAllapot = trim((string)($_GET['stripe_setup'] ?? ''));
    $setupSessionId = trim((string)($_GET['stripe_session_id'] ?? ''));
    if ($setupAllapot === 'success' && $setupSessionId !== '') {
        if (shobidStripeSetupSessionRogzites($conn, $setupSessionId)) {
            $uzenet = html_entity_decode("A fizet&eacute;si be&aacute;ll&iacute;t&aacute;sok sikeresen elmentve. Most m&aacute;r tudsz v&aacute;s&aacute;rolni, licit&aacute;lni &eacute;s eladni.", ENT_QUOTES, 'UTF-8');
        } else {
            $uzenet = html_entity_decode("A Stripe fizet&eacute;si be&aacute;ll&iacute;t&aacute;s visszaigazol&aacute;sa nem siker&uuml;lt. Pr&oacute;b&aacute;ld meg &uacute;jra.", ENT_QUOTES, 'UTF-8');
        }
    } elseif ($setupAllapot === 'cancel') {
        $uzenet = html_entity_decode("A Stripe fizet&eacute;si be&aacute;ll&iacute;t&aacute;s megszakadt.", ENT_QUOTES, 'UTF-8');
    }
}

if (isset($_GET['stripe_connect'])) {
    $connectAllapot = trim((string)($_GET['stripe_connect'] ?? ''));
    if ($connectAllapot === 'return' || $connectAllapot === 'refresh') {
        if (shobidStripeSyncConnectAccount($conn, $user_id)) {
            $connectSummaryAfter = shobidStripeConnectSummary($conn, $user_id);
            if (!empty($connectSummaryAfter['ready'])) {
                $uzenet = html_entity_decode("A Stripe kifizet&eacute;si fi&oacute;k sikeresen csatlakozott. Az automatikus kiutal&aacute;sok most m&aacute;r erre a fi&oacute;kra tudnak majd menni.", ENT_QUOTES, 'UTF-8');
            } else {
                $uzenet = html_entity_decode("A Stripe kifizet&eacute;si fi&oacute;k folyamata elindult, de m&eacute;g lehet, hogy hi&aacute;nyzik n&eacute;h&aacute;ny adat. A gombbal b&aacute;rmikor folytathatod az onboardingot.", ENT_QUOTES, 'UTF-8');
            }
        } else {
            $uzenet = html_entity_decode("A Stripe kifizet&eacute;si fi&oacute;k &aacute;llapot&aacute;t most nem siker&uuml;lt friss&iacute;teni.", ENT_QUOTES, 'UTF-8');
        }
    }
}

$stripeConnectSummary = shobidStripeConnectSummary($conn, $user_id);

if (isset($_POST['kupon_ellenorzes'])) {
    $kuponKodRaw = strtoupper(trim((string)($_POST['kupon_kod'] ?? '')));
    $visszaHash = trim((string)($_POST['vissza_hash'] ?? 'penzugy'));
    if ($kuponKodRaw === '' || !kuponTablaVanProfil($conn)) {
        header("Location: profil.php#" . preg_replace('/[^a-zA-Z0-9_-]/', '', $visszaHash));
        exit;
    }
    $kuponKod = $conn->real_escape_string($kuponKodRaw);
    $res = $conn->query("SELECT * FROM kupon_vasarlasok WHERE kupon_kod = '$kuponKod' AND seller_id = " . intval($user_id) . " LIMIT 1");
    $sor = $res ? $res->fetch_assoc() : null;
    if ($sor) {
        if ((string)($sor['allapot'] ?? '') !== 'bevaltva') {
            $conn->query("UPDATE kupon_vasarlasok
                SET allapot = 'bevaltva', bevaltva_at = NOW(), bevaltva_by = " . intval($user_id) . "
                WHERE id = " . intval($sor['id']) . " AND allapot <> 'bevaltva'");
        }
    }
    $eredmeny = $sor ? ((string)($sor['allapot'] ?? '') === 'bevaltva' ? 'already' : 'ok') : 'invalid';
    header("Location: profil.php?kupon_ellenorzes=" . urlencode($eredmeny) . "#" . preg_replace('/[^a-zA-Z0-9_-]/', '', $visszaHash));
    exit;
}

if (isset($_POST['penzugyi_muvelet_mentes'])) {
    $muveletAzonosito = $conn->real_escape_string(trim((string)($_POST['muvelet_azonosito'] ?? '')));
    $muveletTipus = trim((string)($_POST['muvelet_tipus'] ?? ''));
    $ertek = isset($_POST['muvelet_aktiv']) ? 1 : 0;
    $visszaHash = trim((string)($_POST['vissza_hash'] ?? ''));
    $trackingKod = $conn->real_escape_string(trim((string)($_POST['tracking_kod'] ?? '')));
    $meglevoPostaDokumentum = $conn->real_escape_string(trim((string)($_POST['meglevo_posta_dokumentum'] ?? '')));
    $ujPostaDokumentum = mentsPostaDokumentumProfil($_FILES['posta_dokumentum'] ?? null);
    if ($ujPostaDokumentum === false) {
        $uzenet = html_entity_decode("A posta dokumentum csak pdf, jpg, jpeg, png vagy webp lehet.", ENT_QUOTES, 'UTF-8');
    } elseif ($muveletAzonosito !== '' && ($muveletTipus === 'postara_adva' || $muveletTipus === 'megkapta') && penzugyiMuveletekTablaVanProfil($conn)) {
        $postaDokumentum = $ujPostaDokumentum !== '' ? $conn->real_escape_string($ujPostaDokumentum) : $meglevoPostaDokumentum;
        $postaraAdva = $muveletTipus === 'postara_adva' ? $ertek : 0;
        $megkapta = $muveletTipus === 'megkapta' ? $ertek : 0;
        $trackingSql = oszlopLetezik($conn, 'penzugyi_muveletek', 'tracking_kod') ? ", tracking_kod = IF('$muveletTipus' = 'postara_adva', '$trackingKod', tracking_kod)" : '';
        $dokumentumSql = oszlopLetezik($conn, 'penzugyi_muveletek', 'posta_dokumentum') ? ", posta_dokumentum = IF('$muveletTipus' = 'postara_adva', '$postaDokumentum', posta_dokumentum)" : '';
        $conn->query("INSERT INTO penzugyi_muveletek (azonosito, postara_adva, megkapta, tracking_kod, posta_dokumentum) VALUES ('$muveletAzonosito', $postaraAdva, $megkapta, '$trackingKod', '$postaDokumentum')
            ON DUPLICATE KEY UPDATE
                postara_adva = IF('$muveletTipus' = 'postara_adva', $postaraAdva, postara_adva),
                megkapta = IF('$muveletTipus' = 'megkapta', $megkapta, megkapta)
                $trackingSql
                $dokumentumSql");
        if ($muveletTipus === 'postara_adva' && penzugyiStatuszTablaVanProfil($conn)) {
            $ujStatusz = $ertek ? 'Postázva' : 'Postázásra vár';
            $statuszSql = $conn->real_escape_string($ujStatusz);
            $conn->query("INSERT INTO penzugyi_statuszok (azonosito, statusz) VALUES ('$muveletAzonosito', '$statuszSql')
                ON DUPLICATE KEY UPDATE statusz = VALUES(statusz)");
            if ($ertek) {
                shobidKuldPostazvaEmail($conn, $muveletAzonosito, trim((string)($_POST['tracking_kod'] ?? '')));
            }
        }
        if ($muveletTipus === 'megkapta' && $ertek) {
            $felszabaditas = shobidPayoutReleaseByAzonosito($conn, $muveletAzonosito);
            if (!empty($felszabaditas['ok'])) {
                $uzenet = html_entity_decode("A vásárlás megjelölve: MEGKAPTAM. Az összeg most már kiutalható az eladó számára, és a napi automatikus kiutalásba bekerül.", ENT_QUOTES, 'UTF-8');
            }
        }
    }
    header("Location: profil.php" . ($visszaHash !== '' ? ('#' . preg_replace('/[^a-zA-Z0-9_-]/', '', $visszaHash)) : ''));
    exit;
}

if (isset($_POST['azonnali_kiutalas_igenylese'])) {
    $eredmeny = shobidPayoutProcessInstant($conn, $user_id);
    $uzenet = !empty($eredmeny['ok'])
        ? html_entity_decode("Az azonnali kiutalás rögzítve lett.", ENT_QUOTES, 'UTF-8')
        : html_entity_decode((string)($eredmeny['error'] ?? 'Az azonnali kiutalás nem sikerült.'), ENT_QUOTES, 'UTF-8');
    header("Location: profil.php#penzugy");
    exit;
}

if (isset($_POST['sajat_aukcio_video_mentes'])) {
    $aukcioId = intval($_POST['sajat_aukcio_id'] ?? 0);
    $ujVideoRaw = trim($_POST['uj_video_url'] ?? '');
    $ujLiveRaw = trim($_POST['uj_live_url'] ?? '');
    $ujAjanlatKep = mentettAjanlatKepNevProfil($_FILES['uj_ajanlat_kep'] ?? null);

    $ellenorzesStmt = $conn->prepare("SELECT id FROM termekek WHERE id = ? AND feltolto_id = ? AND orszag_kod = ? LIMIT 1");
    $vanJog = false;
    if ($ellenorzesStmt) {
        $ellenorzesStmt->bind_param('iis', $aukcioId, $user_id, $profilPiacKod);
        $ellenorzesStmt->execute();
        $ellenorzesRes = $ellenorzesStmt->get_result();
        $vanJog = $ellenorzesRes && $ellenorzesRes->num_rows > 0;
        $ellenorzesStmt->close();
    }

    if (!$vanJog) {
        $uzenet = html_entity_decode("Ehhez az aukci&oacute;hoz nincs jogosults&aacute;god.", ENT_QUOTES, 'UTF-8');
    } elseif ($ujAjanlatKep === false) {
        $uzenet = html_entity_decode("A k&eacute;p felt&ouml;lt&eacute;se nem siker&uuml;lt.", ENT_QUOTES, 'UTF-8');
    } elseif ($ujVideoRaw === '') {
        $uzenet = html_entity_decode("A vide&oacute; link nem lehet &uuml;res.", ENT_QUOTES, 'UTF-8');
    } else {
        if (oszlopLetezik($conn, 'termekek', 'live_url')) {
            if (is_string($ujAjanlatKep) && $ujAjanlatKep !== '' && oszlopLetezik($conn, 'termekek', 'kep_url')) {
                $updateStmt = $conn->prepare("UPDATE termekek SET video_url = ?, live_url = ?, kep_url = ? WHERE id = ?");
                if ($updateStmt) {
                    $updateStmt->bind_param('sssi', $ujVideoRaw, $ujLiveRaw, $ujAjanlatKep, $aukcioId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
            } else {
                $updateStmt = $conn->prepare("UPDATE termekek SET video_url = ?, live_url = ? WHERE id = ?");
                if ($updateStmt) {
                    $updateStmt->bind_param('ssi', $ujVideoRaw, $ujLiveRaw, $aukcioId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
            }
        } else {
            if (is_string($ujAjanlatKep) && $ujAjanlatKep !== '' && oszlopLetezik($conn, 'termekek', 'kep_url')) {
                $updateStmt = $conn->prepare("UPDATE termekek SET video_url = ?, kep_url = ? WHERE id = ?");
                if ($updateStmt) {
                    $updateStmt->bind_param('ssi', $ujVideoRaw, $ujAjanlatKep, $aukcioId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
            } else {
                $updateStmt = $conn->prepare("UPDATE termekek SET video_url = ? WHERE id = ?");
                if ($updateStmt) {
                    $updateStmt->bind_param('si', $ujVideoRaw, $aukcioId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
            }
        }
        if (is_string($ujAjanlatKep) && $ujAjanlatKep !== '') {
            $uzenet = html_entity_decode("A vide&oacute; linkek &eacute;s a k&eacute;p sikeresen friss&iacute;tve!", ENT_QUOTES, 'UTF-8');
        } else {
            $uzenet = html_entity_decode("A vide&oacute; linkek sikeresen friss&iacute;tve!", ENT_QUOTES, 'UTF-8');
        }
    }
}

if (isset($_POST['sajat_aukcio_torles'])) {
    $aukcioId = intval($_POST['sajat_aukcio_id'] ?? 0);
    $ellenorzesStmt = $conn->prepare("SELECT id FROM termekek WHERE id = ? AND feltolto_id = ? AND orszag_kod = ? LIMIT 1");
    $vanJog = false;
    if ($ellenorzesStmt) {
        $ellenorzesStmt->bind_param('iis', $aukcioId, $user_id, $profilPiacKod);
        $ellenorzesStmt->execute();
        $ellenorzesRes = $ellenorzesStmt->get_result();
        $vanJog = $ellenorzesRes && $ellenorzesRes->num_rows > 0;
        $ellenorzesStmt->close();
    }

    if (!$vanJog) {
        $uzenet = html_entity_decode("Ehhez az aukci&oacute;hoz nincs jogosults&aacute;god.", ENT_QUOTES, 'UTF-8');
    } else {
        if ($multiTablaVan) {
            $multiRes = $conn->query("SELECT id FROM multi_aukciok WHERE termek_id = " . intval($aukcioId) . " LIMIT 1");
            $multi = $multiRes ? $multiRes->fetch_assoc() : null;
            if ($multi) {
                $multiId = intval($multi['id']);
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
        $delTermekStmt = $conn->prepare("DELETE FROM termekek WHERE id = ? AND feltolto_id = ? AND orszag_kod = ?");
        if ($delTermekStmt) {
            $delTermekStmt->bind_param('iis', $aukcioId, $user_id, $profilPiacKod);
            $delTermekStmt->execute();
            $delTermekStmt->close();
        }
        $uzenet = html_entity_decode("Aukci&oacute; sikeresen t&ouml;r&ouml;lve!", ENT_QUOTES, 'UTF-8');
    }
}

if (isset($_POST['aktivitas_lista_torles'])) {
    biztositsProfilAktivitasRejtettTabla($conn);
    $conn->query("INSERT IGNORE INTO profil_aktivitas_rejtett (user_id, termek_id)
        SELECT DISTINCT $user_id, ln.termek_id
        FROM licit_naplo ln
        WHERE ln.felhasznalo_id = $user_id");
    $uzenet = html_entity_decode("Az Aktivit&aacute;som lista sikeresen ki&uuml;r&iacute;tve!", ENT_QUOTES, 'UTF-8');
}

if (isset($_POST['szallitasi_cim_mentes'])) {
    $uj_szallitasi_nev = trim((string)($_POST['uj_szallitasi_nev'] ?? ''));
    $uj_szallitasi_iranyitoszam = trim((string)($_POST['uj_szallitasi_iranyitoszam'] ?? ''));
    $uj_szallitasi_varos = trim((string)($_POST['uj_szallitasi_varos'] ?? ''));
    $uj_szallitasi_utca = trim((string)($_POST['uj_szallitasi_utca'] ?? ''));
    $uj_szallitasi_hazszam = trim((string)($_POST['uj_szallitasi_hazszam'] ?? ''));
    $uj_szallitasi_emelet_ajto = trim((string)($_POST['uj_szallitasi_emelet_ajto'] ?? ''));
    $uj_szallitasi_megjegyzes = trim((string)($_POST['uj_szallitasi_megjegyzes'] ?? ''));

    if (!$szallitasiCimOszlopokMegvannak) {
        $uzenet = html_entity_decode("A sz&aacute;ll&iacute;t&aacute;si c&iacute;m ment&eacute;s&eacute;hez m&eacute;g hi&aacute;nyoznak az adatb&aacute;zis oszlopok.", ENT_QUOTES, 'UTF-8');
    } elseif ($uj_szallitasi_nev === '' || $uj_szallitasi_iranyitoszam === '' || $uj_szallitasi_varos === '' || $uj_szallitasi_utca === '' || $uj_szallitasi_hazszam === '') {
        $uzenet = html_entity_decode("A sz&aacute;ll&iacute;t&aacute;si c&iacute;mn&eacute;l a n&eacute;v, ir&aacute;ny&iacute;t&oacute;sz&aacute;m, v&aacute;ros, utca &eacute;s h&aacute;zsz&aacute;m k&ouml;telez&#337;.", ENT_QUOTES, 'UTF-8');
    } else {
        $updateStmt = $conn->prepare("UPDATE felhasznalok
            SET szallitasi_nev = ?,
                szallitasi_iranyitoszam = ?,
                szallitasi_varos = ?,
                szallitasi_utca = ?,
                szallitasi_hazszam = ?,
                szallitasi_emelet_ajto = ?,
                szallitasi_megjegyzes = ?
            WHERE id = ?");
        if ($updateStmt) {
            $updateStmt->bind_param(
                'sssssssi',
                $uj_szallitasi_nev,
                $uj_szallitasi_iranyitoszam,
                $uj_szallitasi_varos,
                $uj_szallitasi_utca,
                $uj_szallitasi_hazszam,
                $uj_szallitasi_emelet_ajto,
                $uj_szallitasi_megjegyzes,
                $user_id
            );
            $updateStmt->execute();
            $updateStmt->close();
        }

        $userStmt = $conn->prepare("SELECT * FROM felhasznalok WHERE id = ? LIMIT 1");
        $userRes = null;
        if ($userStmt) {
            $userStmt->bind_param('i', $user_id);
            $userStmt->execute();
            $userRes = $userStmt->get_result();
            $userStmt->close();
        }
        if ($userRes && $userRes->num_rows > 0) {
            $user = $userRes->fetch_assoc();
        }
        $uzenet = html_entity_decode("Sz&aacute;ll&iacute;t&aacute;si c&iacute;m sikeresen mentve!", ENT_QUOTES, 'UTF-8');
    }
}

if (isset($_POST['ceges_adatok_mentes'])) {
    $uj_cegnev_raw = trim($_POST['uj_cegnev'] ?? '');
    $uj_ceges_iranyitoszam_raw = trim($_POST['uj_ceges_iranyitoszam'] ?? '');
    $uj_ceges_varos_raw = trim($_POST['uj_ceges_varos'] ?? '');
    $uj_ceges_utca_raw = trim($_POST['uj_ceges_utca'] ?? '');
    $uj_ceges_hazszam_raw = trim($_POST['uj_ceges_hazszam'] ?? '');
    $uj_adoszam_raw = trim($_POST['uj_adoszam'] ?? '');
    $uj_cegkent_vasarolok = isset($_POST['uj_cegkent_vasarolok']) ? 1 : 0;

    if ($uj_cegkent_vasarolok && (
        $uj_cegnev_raw === '' ||
        $uj_ceges_iranyitoszam_raw === '' ||
        $uj_ceges_varos_raw === '' ||
        $uj_ceges_utca_raw === '' ||
        $uj_ceges_hazszam_raw === '' ||
        $uj_adoszam_raw === ''
    )) {
        $uzenet = html_entity_decode("A C&eacute;gk&eacute;nt v&aacute;s&aacute;rolok / eladok aktiv&aacute;l&aacute;s&aacute;hoz t&ouml;ltsd ki a c&eacute;ges adatokat.", ENT_QUOTES, 'UTF-8');
    } else {
        $uj_ceges_szekhely_osszefuzott = trim(implode(', ', array_filter([
            trim($uj_ceges_iranyitoszam_raw . ' ' . $uj_ceges_varos_raw),
            $uj_ceges_utca_raw,
            $uj_ceges_hazszam_raw
        ])));

        $cegesSql = '';
        if ($cegnevOszlopVan) {
            $cegesSql .= ", cegnev = '" . $conn->real_escape_string($uj_cegnev_raw) . "'";
        }
        if ($cegesSzekhelyOszlopVan) {
            $cegesSql .= ", ceges_szekhely = '" . $conn->real_escape_string($uj_ceges_szekhely_osszefuzott) . "'";
        }
        if ($cegesIranyitoszamOszlopVan) {
            $cegesSql .= ", ceges_iranyitoszam = '" . $conn->real_escape_string($uj_ceges_iranyitoszam_raw) . "'";
        }
        if ($cegesVarosOszlopVan) {
            $cegesSql .= ", ceges_varos = '" . $conn->real_escape_string($uj_ceges_varos_raw) . "'";
        }
        if ($cegesUtcaOszlopVan) {
            $cegesSql .= ", ceges_utca = '" . $conn->real_escape_string($uj_ceges_utca_raw) . "'";
        }
        if ($cegesHazszamOszlopVan) {
            $cegesSql .= ", ceges_hazszam = '" . $conn->real_escape_string($uj_ceges_hazszam_raw) . "'";
        }
        if ($adoszamOszlopVan) {
            $cegesSql .= ", adoszam = '" . $conn->real_escape_string($uj_adoszam_raw) . "'";
        }
        if ($cegkentVasarolokOszlopVan) {
            $cegesSql .= ", cegkent_vasarolok = " . intval($uj_cegkent_vasarolok);
        }

        if ($cegesSql !== '') {
            $conn->query("UPDATE felhasznalok SET id = id $cegesSql WHERE id = $user_id");
            $userRes = $conn->query("SELECT * FROM felhasznalok WHERE id = $user_id");
            if ($userRes && $userRes->num_rows > 0) {
                $user = $userRes->fetch_assoc();
            }
        }
        $uzenet = html_entity_decode("C&eacute;ges adatok sikeresen mentve!", ENT_QUOTES, 'UTF-8');
    }
}

if (isset($_POST['adat_mentes'])) {
    $aktualisProfilRes = $conn->query("SELECT teljes_nev, lakcim, telefonszam FROM felhasznalok WHERE id = $user_id");
    $aktualisProfil = $aktualisProfilRes ? $aktualisProfilRes->fetch_assoc() : null;
    $elsoKitoltesAdatok = isset($_GET['complete_profile'])
        || !$aktualisProfil
        || empty(trim((string)($aktualisProfil['teljes_nev'] ?? '')))
        || empty(trim((string)($aktualisProfil['lakcim'] ?? '')))
        || empty(trim((string)($aktualisProfil['telefonszam'] ?? '')));

    $uj_teljes_nev = $conn->real_escape_string(trim($_POST['uj_teljes_nev'] ?? ''));
    $uj_becenev = $conn->real_escape_string($_POST['uj_becenev']);
    $uj_email = $conn->real_escape_string($_POST['uj_email']);
    $aktualis_orszag_kod = strtolower(trim((string)($user['orszag_kod'] ?? '')));
    $bekuldott_orszag_kod = strtolower(trim((string)($_POST['uj_orszag_kod'] ?? '')));
    $uj_orszag_kod = $aktualis_orszag_kod;
    if ($uj_orszag_kod === '') {
        $uj_orszag_kod = $bekuldott_orszag_kod;
    }
    $uj_telefonszam = $conn->real_escape_string(trim($_POST['uj_telefonszam'] ?? ''));
    $uj_lakcim_iranyitoszam_raw = trim($_POST['uj_lakcim_iranyitoszam'] ?? '');
    $uj_lakcim_varos_raw = trim($_POST['uj_lakcim_varos'] ?? '');
    $uj_lakcim_utca_raw = trim($_POST['uj_lakcim_utca'] ?? '');
    $uj_lakcim_hazszam_raw = trim($_POST['uj_lakcim_hazszam'] ?? '');
    $uj_lakcim_osszefuzott = trim(implode(', ', array_filter([
        trim($uj_lakcim_iranyitoszam_raw . ' ' . $uj_lakcim_varos_raw),
        $uj_lakcim_utca_raw,
        $uj_lakcim_hazszam_raw
    ])));
    $uj_lakcim = $conn->real_escape_string($uj_lakcim_osszefuzott);
    $uj_cegnev = $conn->real_escape_string(trim($_POST['uj_cegnev'] ?? ''));
    $uj_ceges_iranyitoszam_raw = trim($_POST['uj_ceges_iranyitoszam'] ?? '');
    $uj_ceges_varos_raw = trim($_POST['uj_ceges_varos'] ?? '');
    $uj_ceges_utca_raw = trim($_POST['uj_ceges_utca'] ?? '');
    $uj_ceges_hazszam_raw = trim($_POST['uj_ceges_hazszam'] ?? '');
    $uj_ceges_szekhely_osszefuzott = trim(implode(', ', array_filter([
        trim($uj_ceges_iranyitoszam_raw . ' ' . $uj_ceges_varos_raw),
        $uj_ceges_utca_raw,
        $uj_ceges_hazszam_raw
    ])));
    $uj_ceges_szekhely = $conn->real_escape_string($uj_ceges_szekhely_osszefuzott);
    $uj_adoszam = $conn->real_escape_string(trim($_POST['uj_adoszam'] ?? ''));
    $uj_cegkent_vasarolok = isset($_POST['uj_cegkent_vasarolok']) ? 1 : 0;
    $uj_aszf_elfogadva = isset($_POST['uj_aszf_elfogadva']) ? 1 : 0;
    $uj_fizetesi_szabalyzat_elfogadva = isset($_POST['uj_fizetesi_szabalyzat_elfogadva']) ? 1 : 0;
    $profilkep = mentettProfilkepNev($_FILES['uj_profilkep'] ?? null);

    if ($profilkep === false) {
        $uzenet = html_entity_decode("Csak jpg, jpeg, png, webp vagy gif profilk&eacute;pet t&ouml;lthetsz fel.", ENT_QUOTES, 'UTF-8');
    } elseif ($uj_teljes_nev === '') {
        $uzenet = html_entity_decode("A teljes nevet k&ouml;telez&#337; megadni.", ENT_QUOTES, 'UTF-8');
    } elseif ($orszagKodOszlopVan && $uj_orszag_kod === '') {
        $uzenet = html_entity_decode("Az orsz&aacute;g kiv&aacute;laszt&aacute;sa k&ouml;telez&#337;.", ENT_QUOTES, 'UTF-8');
    } elseif ($orszagKodOszlopVan && !isset($profilElerhetoOrszagKodok[$uj_orszag_kod])) {
        $uzenet = html_entity_decode("Csak a list&aacute;ban szerepl&#337; orsz&aacute;gok k&ouml;z&uuml;l v&aacute;laszthatsz.", ENT_QUOTES, 'UTF-8');
    } elseif ($orszagKodOszlopVan && $aktualis_orszag_kod !== '' && $bekuldott_orszag_kod !== '' && $bekuldott_orszag_kod !== $aktualis_orszag_kod) {
        $uzenet = html_entity_decode("Az orsz&aacute;g nem m&oacute;dos&iacute;that&oacute;. Egy fi&oacute;k egy orsz&aacute;ghoz tartozik.", ENT_QUOTES, 'UTF-8');
    } elseif ($uj_aszf_elfogadva !== 1 || $uj_fizetesi_szabalyzat_elfogadva !== 1) {
        $uzenet = html_entity_decode("Az &Aacute;SZF &eacute;s a Fizet&eacute;si szab&aacute;lyzat elfogad&aacute;sa k&ouml;telez&#337;.", ENT_QUOTES, 'UTF-8');
    } else {
        $profilkepSql = $profilkep ? ", profilkep = '" . $conn->real_escape_string($profilkep) . "'" : "";
        $teljesNevSql = ", teljes_nev = '$uj_teljes_nev'";
        $cegesSql = '';
        if ($cegnevOszlopVan) {
            $cegesSql .= ", cegnev = '$uj_cegnev'";
        }
        if ($lakcimIranyitoszamOszlopVan) {
            $cegesSql .= ", lakcim_iranyitoszam = '" . $conn->real_escape_string($uj_lakcim_iranyitoszam_raw) . "'";
        }
        if ($lakcimVarosOszlopVan) {
            $cegesSql .= ", lakcim_varos = '" . $conn->real_escape_string($uj_lakcim_varos_raw) . "'";
        }
        if ($lakcimUtcaOszlopVan) {
            $cegesSql .= ", lakcim_utca = '" . $conn->real_escape_string($uj_lakcim_utca_raw) . "'";
        }
        if ($lakcimHazszamOszlopVan) {
            $cegesSql .= ", lakcim_hazszam = '" . $conn->real_escape_string($uj_lakcim_hazszam_raw) . "'";
        }
        if ($cegesSzekhelyOszlopVan) {
            $cegesSql .= ", ceges_szekhely = '$uj_ceges_szekhely'";
        }
        if ($cegesIranyitoszamOszlopVan) {
            $cegesSql .= ", ceges_iranyitoszam = '" . $conn->real_escape_string($uj_ceges_iranyitoszam_raw) . "'";
        }
        if ($cegesVarosOszlopVan) {
            $cegesSql .= ", ceges_varos = '" . $conn->real_escape_string($uj_ceges_varos_raw) . "'";
        }
        if ($cegesUtcaOszlopVan) {
            $cegesSql .= ", ceges_utca = '" . $conn->real_escape_string($uj_ceges_utca_raw) . "'";
        }
        if ($cegesHazszamOszlopVan) {
            $cegesSql .= ", ceges_hazszam = '" . $conn->real_escape_string($uj_ceges_hazszam_raw) . "'";
        }
        if ($adoszamOszlopVan) {
            $cegesSql .= ", adoszam = '$uj_adoszam'";
        }
        if ($cegkentVasarolokOszlopVan) {
            $cegesSql .= ", cegkent_vasarolok = $uj_cegkent_vasarolok";
        }
        if ($aszfElfogadvaOszlopVan) {
            $cegesSql .= ", aszf_elfogadva = $uj_aszf_elfogadva";
        }
        if ($fizetesiSzabalyzatElfogadvaOszlopVan) {
            $cegesSql .= ", fizetesi_szabalyzat_elfogadva = $uj_fizetesi_szabalyzat_elfogadva";
        }
        if ($orszagKodOszlopVan) {
            $cegesSql .= ", orszag_kod = '" . $conn->real_escape_string($uj_orszag_kod) . "'";
        }
        $conn->query("UPDATE felhasznalok SET becenev = '$uj_becenev', email = '$uj_email', telefonszam = '$uj_telefonszam', lakcim = '$uj_lakcim' $teljesNevSql $cegesSql $profilkepSql WHERE id = $user_id");
        if ($orszagKodOszlopVan && $uj_orszag_kod !== '') {
            setcookie('shobid_market', $uj_orszag_kod, time() + (86400 * 365), '/');
        }
        $_SESSION['becenev'] = $uj_becenev;
        $becenev = $uj_becenev;
        $uzenet = $profilkep
            ? html_entity_decode("Adatok &eacute;s profilk&eacute;p sikeresen friss&iacute;tve!", ENT_QUOTES, 'UTF-8')
            : html_entity_decode("Adatok sikeresen friss&iacute;tve!", ENT_QUOTES, 'UTF-8');
    }
}

if (isset($_POST['jelszo_mentes'])) {
    $regi = (string)($_POST['regi_jelszo'] ?? '');
    $uj = (string)($_POST['uj_jelszo'] ?? '');

    $uStmt = $conn->prepare("SELECT jelszo FROM felhasznalok WHERE id = ? LIMIT 1");
    $u_adat = null;
    if ($uStmt) {
        $uStmt->bind_param('i', $user_id);
        $uStmt->execute();
        $uRes = $uStmt->get_result();
        $u_adat = $uRes ? $uRes->fetch_assoc() : null;
        $uStmt->close();
    }

    $profilAdatStmt = $conn->prepare("SELECT teljes_nev, lakcim, telefonszam FROM felhasznalok WHERE id = ? LIMIT 1");
    $profilAdat = null;
    if ($profilAdatStmt) {
        $profilAdatStmt->bind_param('i', $user_id);
        $profilAdatStmt->execute();
        $profilAdatRes = $profilAdatStmt->get_result();
        $profilAdat = $profilAdatRes ? $profilAdatRes->fetch_assoc() : null;
        $profilAdatStmt->close();
    }

    $elsoKitoltes = isset($_GET['complete_profile'])
        || !$profilAdat
        || empty(trim((string)($profilAdat['teljes_nev'] ?? '')))
        || empty(trim((string)($profilAdat['lakcim'] ?? '')))
        || empty(trim((string)($profilAdat['telefonszam'] ?? '')));

    $aktualisJelszoHash = (string)($u_adat['jelszo'] ?? '');
    if (($elsoKitoltes && $uj !== '') || (!$elsoKitoltes && $aktualisJelszoHash !== '' && password_verify($regi, $aktualisJelszoHash))) {
        $ujHash = password_hash($uj, PASSWORD_DEFAULT);
        $updateStmt = $conn->prepare("UPDATE felhasznalok SET jelszo = ? WHERE id = ?");
        if ($updateStmt) {
            $updateStmt->bind_param('si', $ujHash, $user_id);
            $updateStmt->execute();
            $updateStmt->close();
        }
        $uzenet = html_entity_decode("Jelsz&oacute; sikeresen megv&aacute;ltoztatva!", ENT_QUOTES, 'UTF-8');
    } else {
        $uzenet = html_entity_decode("Hiba: A r&eacute;gi jelsz&oacute; nem megfelel&#337;!", ENT_QUOTES, 'UTF-8');
    }
}

$kategoriak = [];
$kategoriaRes = $conn->query("SELECT * FROM kategoriak ORDER BY nev ASC");
if ($kategoriaRes) {
    while ($k = $kategoriaRes->fetch_assoc()) {
        $k['nev'] = profilForditottKategoriNev(intval($k['id'] ?? 0), (string)($k['nev'] ?? ''), $profileLocaleCode);
        $kategoriak[] = $k;
    }
}

$kategoriakEloDb = [];
$mostMegyDb = 0;
$eloDb = 0;
$kozelgoDb = 0;
$kuponDb = 0;
$statMostTs = time();
$sidebarPiacKod = function_exists('shobidMarketCurrentCode')
    ? shobidMarketCurrentCode()
    : $profileLocaleCode;
$statRes = $conn->query("SELECT * FROM termekek WHERE eladva = 0 AND " . shobidMarketTermekWhere($conn, '', $sidebarPiacKod));
if ($statRes) {
    while ($statTermek = $statRes->fetch_assoc()) {
        $tenylegesenElo = liveAktivOszlopVanProfil($conn) && intval($statTermek['live_active'] ?? 0) === 1;
        $tipus = (string)($statTermek['ajanlat_tipus'] ?? 'licit');
        if ($tipus === 'multi') {
            $multiStat = getMultiAukcioAdatProfil($conn, $statTermek);
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
                $kuponDb++;
                $mostMegyDb++;
                if ($tenylegesenElo) $eloDb++;
            }
            continue;
        }
        $singleStat = getSingleLicitAllapotProfil($conn, $statTermek);
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

$user = $conn->query("SELECT * FROM felhasznalok WHERE id = $user_id")->fetch_assoc();
$profilAktivOrszagKod = strtolower(trim((string)($user['orszag_kod'] ?? '')));
if ($profilAktivOrszagKod === '' || !isset($profilElerhetoOrszagKodok[$profilAktivOrszagKod])) {
    $profilAktivOrszagKod = isset($profilElerhetoOrszagKodok[$profileLocaleCode]) ? $profileLocaleCode : 'hu-hu';
}
$profilFormOrszagKod = strtolower(trim((string)($_POST['uj_orszag_kod'] ?? $profilAktivOrszagKod)));
if ($profilFormOrszagKod === '' || !isset($profilElerhetoOrszagKodok[$profilFormOrszagKod])) {
    $profilFormOrszagKod = $profilAktivOrszagKod;
}
$fizetesiBeallitasKesz = shobidStripeFelhasznaloFizetesKesz($conn, $user_id);
$stripeConnectSummary = shobidStripeConnectSummary($conn, $user_id);
$profilHianyos = empty(trim((string)($user['teljes_nev'] ?? ''))) || empty(trim((string)($user['lakcim'] ?? ''))) || empty(trim((string)($user['telefonszam'] ?? '')));
$elsoKitoltesMost = $profilHianyos || isset($_GET['complete_profile']);
$kedvencekTablaVan = tablaLetezik($conn, 'kedvenc_felhasznalok');
$kiemeltFelhasznaloOszlop = oszlopLetezik($conn, 'felhasznalok', 'kiemelt_felhasznalo');
$licitNaploIdoExpr = licitNaploIdoSql($conn, 'ln');
$licitNaploVanValosIdoOszlop = ($licitNaploIdoExpr !== 'ln.id');
$licitNaploDatumSelect = $licitNaploVanValosIdoOszlop
    ? "MAX($licitNaploIdoExpr) AS licit_datum"
    : "NULL AS licit_datum";
$licitNaploRendezesSelect = $licitNaploVanValosIdoOszlop
    ? "MAX($licitNaploIdoExpr) AS licit_sorrend"
    : "MAX(ln.id) AS licit_sorrend";
$licitNaploTetelOszlop = licitNaploMultiTetelOszlop($conn, 'ln');
$licitAdatTetelSelect = $licitNaploTetelOszlop ? ", MAX($licitNaploTetelOszlop) AS multi_tetel_id" : ", NULL AS multi_tetel_id";
$licitaltTetelJoin = $licitNaploTetelOszlop ? "LEFT JOIN multi_aukcio_tetelek mt_aktiv ON mt_aktiv.id = licit_adat.multi_tetel_id" : "";
$licitaltTetelSelect = $licitNaploTetelOszlop ? ", mt_aktiv.nev AS licitshop_tetel_nev" : ", '' AS licitshop_tetel_nev";
$licitalt_aukciok = $conn->query("SELECT t.*, k.nev as kat_nev, licit_adat.licit_datum,
    licit_adat.multi_tetel_id
    $licitaltTetelSelect,
    CASE WHEN EXISTS (
        SELECT 1
        FROM multi_aukciok ma
        WHERE ma.termek_id = t.id
          AND EXISTS (
              SELECT 1
              FROM multi_aukcio_tetelek mt
              WHERE mt.multi_aukcio_id = ma.id
          )
    ) THEN 1 ELSE 0 END AS is_multi,
    (SELECT COUNT(DISTINCT felhasznalo_id) FROM licit_naplo WHERE termek_id = t.id) as licitalok_szama
    FROM termekek t
    INNER JOIN (
        SELECT ln.termek_id, $licitNaploDatumSelect, $licitNaploRendezesSelect
        $licitAdatTetelSelect
        FROM licit_naplo ln
        WHERE ln.felhasznalo_id = $user_id
        GROUP BY ln.termek_id
    ) licit_adat ON t.id = licit_adat.termek_id
    LEFT JOIN kategoriak k ON t.kategoria_id = k.id
    $licitaltTetelJoin
    WHERE " . shobidMarketTermekWhere($conn, 't', $profilPiacKod) . "
    ORDER BY licit_adat.licit_sorrend DESC, t.id DESC");
$licitaltAukciokLista = [];
biztositsProfilAktivitasRejtettTabla($conn);
$rejtettAktivitasok = [];
$rejtettRes = $conn->query("SELECT termek_id FROM profil_aktivitas_rejtett WHERE user_id = $user_id");
while ($rejtettRes && ($rejtettSor = $rejtettRes->fetch_assoc())) {
    $rejtettAktivitasok[intval($rejtettSor['termek_id'] ?? 0)] = true;
}
while ($licitalt_aukciok && ($laSor = $licitalt_aukciok->fetch_assoc())) {
    $termekId = intval($laSor['id'] ?? 0);
    if ($termekId > 0 && isset($rejtettAktivitasok[$termekId])) {
        continue;
    }
    profilForditSorKategoriat($laSor, $profileLocaleCode);
    $licitaltAukciokLista[] = $laSor;
}
$profilPerOldal = 12;
$aktivitasPerOldal = $profilPerOldal;
$aktivitasOldal = max(1, intval($_GET['aktivitas_oldal'] ?? 1));
$aktivitasOsszes = count($licitaltAukciokLista);
$aktivitasOldalak = max(1, (int)ceil($aktivitasOsszes / $aktivitasPerOldal));
if ($aktivitasOldal > $aktivitasOldalak) {
    $aktivitasOldal = $aktivitasOldalak;
}
$licitaltAukciokOldal = array_slice($licitaltAukciokLista, ($aktivitasOldal - 1) * $aktivitasPerOldal, $aktivitasPerOldal);
$nyertAukciokWhere = [
    "t.eladva = 1",
    "(t.ajanlat_tipus IS NULL OR t.ajanlat_tipus = 'licit')",
    "t.legmagasabb_licit_felhasznalo = '" . $conn->real_escape_string($becenev) . "'"
];
if ($multiTablaVan) {
    $nyertAukciokWhere[] = "NOT EXISTS (
        SELECT 1
        FROM multi_aukciok ma
        WHERE ma.termek_id = t.id
          AND EXISTS (
              SELECT 1
              FROM multi_aukcio_tetelek mt
              WHERE mt.multi_aukcio_id = ma.id
          )
    )";
}
$sikeresVasarlasok = [];
$sikeresVasarlasokMap = [];
$profilKuponKategoriakTablaVan = tablaLetezik($conn, 'kupon_kategoriak');
$profilKuponKategoriaIdOszlopVan = oszlopLetezik($conn, 'termekek', 'kupon_kategoria_id');
$profilKuponKatSelect = ($profilKuponKategoriakTablaVan && $profilKuponKategoriaIdOszlopVan) ? ", kk.nev AS kupon_kat_nev" : "";
$profilKuponKatJoin = ($profilKuponKategoriakTablaVan && $profilKuponKategoriaIdOszlopVan) ? " LEFT JOIN kupon_kategoriak kk ON kk.id = t.kupon_kategoria_id " : "";
$ledgerVasarlasok = $conn->query("SELECT e.*, t.*, k.nev as kat_nev$profilKuponKatSelect,
        e.tipus AS vasarlas_tipus,
        COALESCE(e.felszabadult_at, e.letrehozva) AS vasarlas_datum,
        e.azonosito AS muvelet_azonosito,
        e.brutto_osszeg AS aktualis_ar,
        e.tetel_nev AS ledger_tetel_nev
    FROM elado_egyenleg_tetelek e
    INNER JOIN termekek t ON t.id = e.termek_id
    LEFT JOIN kategoriak k ON t.kategoria_id = k.id
    $profilKuponKatJoin
    WHERE e.vevo_id = $user_id AND " . shobidMarketTermekWhere($conn, 't', $profilPiacKod) . "
    ORDER BY COALESCE(e.felszabadult_at, e.letrehozva) DESC, e.id DESC");
while ($ledgerVasarlasok && ($vasarlasSor = $ledgerVasarlasok->fetch_assoc())) {
    $muveletAzonosito = trim((string)($vasarlasSor['muvelet_azonosito'] ?? ''));
    if ($muveletAzonosito === '' || isset($sikeresVasarlasokMap[$muveletAzonosito])) {
        continue;
    }
    if (!empty($vasarlasSor['ledger_tetel_nev'])) {
        $vasarlasSor['nev'] = $vasarlasSor['ledger_tetel_nev'];
    }
    if ((string)($vasarlasSor['vasarlas_tipus'] ?? '') === 'kupon' && !empty($vasarlasSor['kupon_kat_nev'])) {
        $vasarlasSor['kat_nev'] = (string)$vasarlasSor['kupon_kat_nev'];
    } else {
        profilForditSorKategoriat($vasarlasSor, $profileLocaleCode);
    }
    $sikeresVasarlasokMap[$muveletAzonosito] = true;
    $sikeresVasarlasok[] = $vasarlasSor;
}
usort($sikeresVasarlasok, function ($a, $b) {
    $aTs = !empty($a['vasarlas_datum']) ? strtotime((string)$a['vasarlas_datum']) : 0;
    $bTs = !empty($b['vasarlas_datum']) ? strtotime((string)$b['vasarlas_datum']) : 0;
    if ($aTs === $bTs) {
        return intval($b['uzenet_id'] ?? ($b['id'] ?? 0)) <=> intval($a['uzenet_id'] ?? ($a['id'] ?? 0));
    }
    return $bTs <=> $aTs;
});

$vasarlasPerOldal = $profilPerOldal;
$vasarlasOldal = max(1, intval($_GET['vasarlas_oldal'] ?? 1));
$vasarlasOsszes = count($sikeresVasarlasok);
$vasarlasOldalak = max(1, (int)ceil($vasarlasOsszes / $vasarlasPerOldal));
if ($vasarlasOldal > $vasarlasOldalak) {
    $vasarlasOldal = $vasarlasOldalak;
}
$sikeresVasarlasokOldal = array_slice($sikeresVasarlasok, ($vasarlasOldal - 1) * $vasarlasPerOldal, $vasarlasPerOldal);
$kuponKodByMuvelet = [];
if (kuponTablaVanProfil($conn)) {
    $kuponSorokByKulcs = [];
    $kuponRes = $conn->query("SELECT kv.*
        FROM kupon_vasarlasok kv
        WHERE kv.buyer_id = " . intval($user_id) . "
        ORDER BY kv.id DESC");
    while ($kuponRes && ($kuponSor = $kuponRes->fetch_assoc())) {
        $termekId = intval($kuponSor['termek_id'] ?? 0);
        $buyerId = intval($kuponSor['buyer_id'] ?? 0);
        if ($termekId < 1 || $buyerId < 1) {
            continue;
        }
        $kulcs = $termekId . '|' . $buyerId;
        if (!isset($kuponSorokByKulcs[$kulcs])) {
            $kuponSorokByKulcs[$kulcs] = [];
        }
        $kuponSorokByKulcs[$kulcs][] = [
            'kod' => (string)($kuponSor['kupon_kod'] ?? ''),
            'allapot' => (string)($kuponSor['allapot'] ?? 'uj')
        ];
    }

    $vasarlasMuveletByKulcs = [];
    foreach ($sikeresVasarlasok as $vasarlasSor) {
        if ((string)($vasarlasSor['vasarlas_tipus'] ?? '') !== 'kupon') {
            continue;
        }
        $termekId = intval($vasarlasSor['termek_id'] ?? $vasarlasSor['id'] ?? 0);
        $buyerId = intval($vasarlasSor['vevo_id'] ?? $user_id);
        $muveletAzonosito = trim((string)($vasarlasSor['muvelet_azonosito'] ?? ''));
        if ($termekId < 1 || $buyerId < 1 || $muveletAzonosito === '') {
            continue;
        }
        $kulcs = $termekId . '|' . $buyerId;
        if (!isset($vasarlasMuveletByKulcs[$kulcs])) {
            $vasarlasMuveletByKulcs[$kulcs] = [];
        }
        $vasarlasMuveletByKulcs[$kulcs][] = $muveletAzonosito;
    }

    foreach ($vasarlasMuveletByKulcs as $kulcs => $muveletek) {
        $kuponSorok = $kuponSorokByKulcs[$kulcs] ?? [];
        $darab = min(count($muveletek), count($kuponSorok));
        for ($i = 0; $i < $darab; $i++) {
            $muveletAzonosito = trim((string)$muveletek[$i]);
            if ($muveletAzonosito === '') {
                continue;
            }
            $kuponKodByMuvelet[$muveletAzonosito] = $kuponSorok[$i];
        }
    }
}

$eladottKuponok = [];
$eladottKuponStatByTermek = [];
if (kuponTablaVanProfil($conn)) {
    $eladottKuponRes = $conn->query("SELECT kv.*, t.nev AS termek_nev
        FROM kupon_vasarlasok kv
        INNER JOIN termekek t ON t.id = kv.termek_id
        WHERE kv.seller_id = " . intval($user_id) . "
        ORDER BY kv.id DESC");
    while ($eladottKuponRes && ($ek = $eladottKuponRes->fetch_assoc())) {
        $eladottKuponok[] = $ek;
        $termekId = intval($ek['termek_id'] ?? 0);
        if ($termekId > 0) {
            if (!isset($eladottKuponStatByTermek[$termekId])) {
                $eladottKuponStatByTermek[$termekId] = ['eladott' => 0, 'bevaltott' => 0];
            }
            $eladottKuponStatByTermek[$termekId]['eladott']++;
            if ((string)($ek['allapot'] ?? '') === 'bevaltva') {
                $eladottKuponStatByTermek[$termekId]['bevaltott']++;
            }
        }
    }
}

$sajatKuponKatSelect = ($profilKuponKategoriakTablaVan && $profilKuponKategoriaIdOszlopVan) ? ", kk.nev AS kupon_kat_nev" : "";
$sajatKuponKatJoin = ($profilKuponKategoriakTablaVan && $profilKuponKategoriaIdOszlopVan) ? " LEFT JOIN kupon_kategoriak kk ON kk.id = t.kupon_kategoria_id " : "";
$sajat = $conn->query("SELECT t.*, k.nev as kat_nev$sajatKuponKatSelect, (SELECT COUNT(DISTINCT felhasznalo_id) FROM licit_naplo WHERE termek_id = t.id) as licitalok_szama
    FROM termekek t LEFT JOIN kategoriak k ON t.kategoria_id = k.id $sajatKuponKatJoin WHERE t.feltolto_id = $user_id AND " . shobidMarketTermekWhere($conn, 't', $profilPiacKod) . " ORDER BY t.kezdes_idopont DESC, t.id DESC");
$sajatAjanlatok = [];
while ($sajat && ($sajatSor = $sajat->fetch_assoc())) {
    if ((string)($sajatSor['ajanlat_tipus'] ?? '') === 'kupon' && !empty($sajatSor['kupon_kat_nev'])) {
        $sajatSor['kat_nev'] = (string)$sajatSor['kupon_kat_nev'];
    } else {
        profilForditSorKategoriat($sajatSor, $profileLocaleCode);
    }
    $sajatAjanlatok[] = $sajatSor;
}
$szerkesztettSajatAukcio = null;
$szerkesztettSajatAukcioMulti = false;
$szerkesztettSajatAukcioFix = false;
$szerkesztettSajatAukcioTetelek = [];
if (isset($_GET['sajat_szerkeszt'])) {
    $sajatSzerkesztId = intval($_GET['sajat_szerkeszt']);
    $res = $conn->query("SELECT t.*, k.nev as kat_nev FROM termekek t LEFT JOIN kategoriak k ON t.kategoria_id = k.id WHERE t.id = $sajatSzerkesztId AND t.feltolto_id = $user_id AND " . shobidMarketTermekWhere($conn, 't', $profilPiacKod) . " LIMIT 1");
    $szerkesztettSajatAukcio = $res ? $res->fetch_assoc() : null;
    profilForditSorKategoriat($szerkesztettSajatAukcio, $profileLocaleCode);
    $szerkesztettSajatAukcioFix = $szerkesztettSajatAukcio && (($szerkesztettSajatAukcio['ajanlat_tipus'] ?? 'licit') === 'fix');
    if ($szerkesztettSajatAukcio && $multiTablaVan) {
        $multiCheckRes = $conn->query("SELECT ma.id FROM multi_aukciok ma WHERE ma.termek_id = " . intval($szerkesztettSajatAukcio['id']) . " AND EXISTS (SELECT 1 FROM multi_aukcio_tetelek mt WHERE mt.multi_aukcio_id = ma.id) LIMIT 1");
        $multiCheck = $multiCheckRes ? $multiCheckRes->fetch_assoc() : null;
        $szerkesztettSajatAukcioMulti = !empty($multiCheck['id']);
        if ($szerkesztettSajatAukcioMulti) {
            $tetelRes = $conn->query("SELECT mt.*, k.nev AS kat_nev FROM multi_aukcio_tetelek mt LEFT JOIN kategoriak k ON mt.kategoria_id = k.id WHERE mt.multi_aukcio_id = " . intval($multiCheck['id']) . " ORDER BY mt.sorszam ASC, mt.id ASC");
            while ($tetelRes && ($tetel = $tetelRes->fetch_assoc())) {
                profilForditSorKategoriat($tetel, $profileLocaleCode);
                $szerkesztettSajatAukcioTetelek[] = $tetel;
            }
        }
    }
}
$kedvencek = $kedvencekTablaVan
    ? $conn->query("SELECT f.id, f.becenev, f.profilkep" . ($kiemeltFelhasznaloOszlop ? ", f.kiemelt_felhasznalo" : "") . ",
        (SELECT COUNT(*) FROM termekek t WHERE t.feltolto_id = f.id AND " . shobidMarketTermekWhere($conn, 't', $profilPiacKod) . " AND t.eladva = 0 AND (t.kezdes_idopont IS NULL OR t.kezdes_idopont = '' OR t.kezdes_idopont <= NOW()) AND t.lejarat_idopont > NOW()) AS aktiv_aukciok_szama
        FROM kedvenc_felhasznalok kf
        INNER JOIN felhasznalok f ON f.id = kf.kedvenc_felhasznalo_id
        WHERE kf.felhasznalo_id = $user_id
        ORDER BY f.becenev ASC")
    : false;

$penzugyiMuveletekMap = [];
$penzugyiStatuszMap = [];
if (penzugyiStatuszTablaVanProfil($conn)) {
    $statuszRes = $conn->query("SELECT azonosito, statusz FROM penzugyi_statuszok");
    while ($statuszRes && ($statuszSor = $statuszRes->fetch_assoc())) {
        $azon = (string)$statuszSor['azonosito'];
        $statuszErtek = (string)$statuszSor['statusz'];
        $penzugyiStatuszMap[$azon] = normalizaltPenzugyiStatuszProfil($statuszErtek);
    }
}
if (penzugyiMuveletekTablaVanProfil($conn)) {
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
    "t.feltolto_id = $user_id",
    "(t.ajanlat_tipus IS NULL OR t.ajanlat_tipus = 'licit')",
    "(
        t.eladva = 1
        OR (
          t.lejarat_idopont IS NOT NULL
          AND t.lejarat_idopont <> ''
          AND t.lejarat_idopont <= NOW()
          AND t.legmagasabb_licit_felhasznalo IS NOT NULL
          AND t.legmagasabb_licit_felhasznalo <> ''
        )
      )"
];
if ($multiTablaVan) {
    $penzugyWhere[] = "NOT EXISTS (
        SELECT 1
        FROM multi_aukciok ma
        WHERE ma.termek_id = t.id
          AND EXISTS (
              SELECT 1
              FROM multi_aukcio_tetelek mt
              WHERE mt.multi_aukcio_id = ma.id
        )
    )";
}
$penzugySorok = [];
$penzugySorokMap = [];
$penzugyKuponKatSelect = ($profilKuponKategoriakTablaVan && $profilKuponKategoriaIdOszlopVan) ? ", kk.nev AS kupon_kat_nev" : "";
$penzugyKuponKatJoin = ($profilKuponKategoriakTablaVan && $profilKuponKategoriaIdOszlopVan) ? " LEFT JOIN kupon_kategoriak kk ON kk.id = t.kupon_kategoria_id " : "";
$ledgerEladasok = $conn->query("SELECT e.*, t.*, k.nev AS kat_nev$penzugyKuponKatSelect,
        e.tipus AS penzugy_tipus,
        COALESCE(e.felszabadult_at, e.letrehozva) AS esemeny_datum,
        e.azonosito AS statusz_azonosito,
        e.vasarlo_nev AS vasarlo_nev,
        e.tetel_nev AS tetel_nev
    FROM elado_egyenleg_tetelek e
    INNER JOIN termekek t ON t.id = e.termek_id
    LEFT JOIN kategoriak k ON t.kategoria_id = k.id
    $penzugyKuponKatJoin
    WHERE e.elado_id = $user_id AND " . shobidMarketTermekWhere($conn, 't', $profilPiacKod) . "
    ORDER BY COALESCE(e.felszabadult_at, e.letrehozva) DESC, e.id DESC");
while ($ledgerEladasok && ($row = $ledgerEladasok->fetch_assoc())) {
    $statuszAzonosito = trim((string)($row['statusz_azonosito'] ?? ''));
    if ($statuszAzonosito === '' || isset($penzugySorokMap[$statuszAzonosito])) {
        continue;
    }
    $row['tipus_label'] = penzugyiTipusLabelProfil((string)($row['penzugy_tipus'] ?? 'licit'));
    $row['ajanlat_datum_szoveg'] = penzugyiDatumSzovegProfil($row);
    if ((string)($row['penzugy_tipus'] ?? '') === 'kupon' && !empty($row['kupon_kat_nev'])) {
        $row['kat_nev'] = (string)$row['kupon_kat_nev'];
    }
    profilForditSorKategoriat($row, $profileLocaleCode);
    $ledgerAllapot = trim((string)($row['allapot'] ?? ''));
    $row['penzugyi_statusz'] = ($ledgerAllapot === 'paid')
        ? 'Kiutalva'
        : ($penzugyiStatuszMap[$statuszAzonosito] ?? 'Postázásra vár');
    $penzugySorokMap[$statuszAzonosito] = true;
    $penzugySorok[] = [
        'adat' => $row,
        'szamitas' => [
            'brutto' => floatval($row['brutto_osszeg'] ?? 0),
            'jutalek_brutto' => floatval($row['jutalek_brutto'] ?? 0),
            'kartya_brutto' => floatval($row['kartya_brutto'] ?? 0),
            'kifizetes_brutto' => floatval($row['elado_osszeg'] ?? 0),
        ]
    ];
}
usort($penzugySorok, function ($a, $b) {
    $aTs = !empty($a['adat']['esemeny_datum']) ? strtotime((string)$a['adat']['esemeny_datum']) : 0;
    $bTs = !empty($b['adat']['esemeny_datum']) ? strtotime((string)$b['adat']['esemeny_datum']) : 0;
    if ($aTs === $bTs) {
        return intval($b['adat']['uzenet_id'] ?? ($b['adat']['id'] ?? 0)) <=> intval($a['adat']['uzenet_id'] ?? ($a['adat']['id'] ?? 0));
    }
    return $bTs <=> $aTs;
});

$eladasPerOldal = $profilPerOldal;
$eladasOldal = max(1, intval($_GET['eladas_oldal'] ?? 1));
$eladasOsszes = count($penzugySorok);
$eladasOldalak = max(1, (int)ceil($eladasOsszes / $eladasPerOldal));
if ($eladasOldal > $eladasOldalak) {
    $eladasOldal = $eladasOldalak;
}
$penzugySorokOldal = array_slice($penzugySorok, ($eladasOldal - 1) * $eladasPerOldal, $eladasPerOldal);

$eladoEgyenleg = shobidPayoutSellerSummary($conn, $user_id);
$eladoKiutalasok = shobidPayoutSellerHistory($conn, $user_id, 25);
$azonnaliKiutalasElerheto = ($eladoEgyenleg['available'] ?? 0) > 0 && ($eladoEgyenleg['available'] ?? 0) < 10000;
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profilom - Hard Illustrated</title>
    <link rel="stylesheet" href="/style.css?v=20260402-1">
</head>
<body class="auction-profile-page">
<div class="auction-shell">
    <?php
    $sidebarActivePage = 'profile';
    $sidebarSearchAction = 'index.php';
    $sidebarIndexBase = 'index.php';
    $sidebarShowSearch = true;
    include __DIR__ . '/auction_sidebar.php';
    ?>

    <main class="profile-shell-main">
        <div class="profile-shell-scroll">
            <section class="profile-hero">
                <div>
                    <div class="profile-kicker"><?php echo htmlspecialchars(st('profile.hero.kicker', 'Sajat fiok')); ?></div>
                    <h1><?php echo htmlspecialchars(st('profile.hero.greeting_prefix', 'Szia,')); ?> <?php echo htmlspecialchars($becenev); ?>!<?php if (!empty($user['kiemelt_felhasznalo'])): ?> <span class="featured-user-badge"><?php echo htmlspecialchars(st('profile.hero.featured_user', 'Kiemelt felhasznalo')); ?></span><?php endif; ?></h1>
                    <p><?php echo htmlspecialchars(st('profile.hero.subtitle', 'Itt kezeled a licitjeidet es a sajat aukcioidat.')); ?></p>
                </div>
                <a href="uj_aukcio.php" class="profile-primary-link">+ <?php echo htmlspecialchars(st('profile.hero.new_offer', 'Uj ajanlat')); ?></a>
            </section>

            <?php if ($uzenet): ?>
            <div class="profile-alert"><?php echo htmlspecialchars($uzenet); ?></div>
            <?php endif; ?>

            <?php if ($profilHianyos || isset($_GET['complete_profile'])): ?>
            <div class="profile-alert">
                Licit&aacute;l&aacute;shoz &eacute;s az oldal haszn&aacute;lat&aacute;hoz add meg a teljes nevet, telefonsz&aacute;mot &eacute;s lakc&iacute;met és a Szállítási címed a Fi&oacute;k be&aacute;ll&iacute;t&aacute;sokn&aacute;l.
            </div>
            <?php endif; ?>
            <?php if (!$fizetesiBeallitasKesz): ?>
            <div class="profile-alert">
                A v&aacute;s&aacute;rl&aacute;shoz, licit&aacute;l&aacute;shoz &eacute;s elad&aacute;shoz el&#337;bb ments el egy bankk&aacute;rty&aacute;t a <a href="#bankkartya">Fizet&eacute;si be&aacute;ll&iacute;t&aacute;sok</a> tabon.
            </div>
            <?php endif; ?>

            <div class="profile-tabbar">
                <button type="button" class="profile-tab is-active" data-tab="aktivitasom" onclick="openTab(event, 'aktivitasom')"><?php echo htmlspecialchars(st('profile.tab.activity', 'Aktivitasom')); ?></button>
                <button type="button" class="profile-tab" data-tab="ajanlataim" onclick="openTab(event, 'ajanlataim')"><?php echo htmlspecialchars(st('profile.tab.offers', 'Ajanlataim')); ?></button>
                <button type="button" class="profile-tab" data-tab="vasarlasaim" onclick="openTab(event, 'vasarlasaim')"><?php echo htmlspecialchars(st('profile.tab.purchases', 'Vasarlasaim')); ?></button>
                <button type="button" class="profile-tab" data-tab="penzugy" onclick="openTab(event, 'penzugy')"><?php echo htmlspecialchars(st('profile.tab.sales', 'Eladasaim')); ?></button>
                <button type="button" class="profile-tab" data-tab="bankkartya" onclick="openTab(event, 'bankkartya')"><?php echo htmlspecialchars(st('profile.tab.payment', 'Fizetesi beallitasok')); ?></button>
                <button type="button" class="profile-tab" data-tab="beallitasok" onclick="openTab(event, 'beallitasok')"><?php echo htmlspecialchars(st('profile.tab.account', 'Fiok beallitasok')); ?></button>
                <button type="button" class="profile-tab" data-tab="kedvencek" onclick="openTab(event, 'kedvencek')"><?php echo htmlspecialchars(st('profile.tab.favorites', 'Kedvenceim')); ?></button>
            </div>

            <div class="profile-tabs-stack">
            <div id="aktivitasom" class="profile-tabpanel is-active">
                <section class="profile-panel">
                    <div class="profile-panel__head">
                        <h2><?php echo htmlspecialchars(st('profile.tab.activity', 'Aktivitasom')); ?></h2>
                        <?php if (!empty($licitaltAukciokLista)): ?>
                        <form method="POST" onsubmit="return confirm('<?php echo htmlspecialchars(st('profile.activity.clear_confirm', 'Biztosan kiurited az Aktivitasom listat?')); ?>');">
                            <button type="submit" name="aktivitas_lista_torles" class="create-cancel create-cancel--danger profile-head-action"><?php echo htmlspecialchars(st('profile.activity.clear', 'Lista torlese')); ?></button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($licitaltAukciokLista)): ?>
                    <div class="profile-tablewrap" style="overflow-x:auto;max-width:100%;-webkit-overflow-scrolling:touch;">
                        <div class="profile-tabledrag" style="display:inline-block;width:max-content;min-width:max-content;">
                        <table class="profile-table" style="min-width:900px;width:max-content;">
                            <thead>
                                <tr><th><?php echo htmlspecialchars(st('profile.table.product', 'Termek')); ?></th><th><?php echo htmlspecialchars(st('profile.table.date', 'Datum')); ?></th><th><?php echo htmlspecialchars(st('profile.table.bidders', 'Licitalok')); ?></th><th><?php echo htmlspecialchars(st('profile.table.current_price', 'Aktualis ar')); ?></th><th><?php echo htmlspecialchars(st('profile.table.action', 'Muvelet')); ?></th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($licitaltAukciokOldal as $la): ?>
                                <?php
                                    $aktivitasTermekNev = (string)($la['nev'] ?? '');
                                    $aktivitasTetelNev = '';
                                    $aktivitasVezeto = trim((string)($la['legmagasabb_licit_felhasznalo'] ?? ''));
                                    if (intval($la['is_multi'] ?? 0) === 1 || (string)($la['ajanlat_tipus'] ?? 'licit') === 'multi') {
                                        $aktivitasTetelNev = trim((string)($la['licitshop_tetel_nev'] ?? ''));
                                        if ($aktivitasTetelNev === '') {
                                            $aktivitasTetelNev = licitShopTetelNevProfil($conn, intval($la['id'] ?? 0), $user_id);
                                        }
                                        if ($aktivitasTetelNev === '') {
                                            $multiAktivitasAdat = getMultiAukcioAdatProfil($conn, $la);
                                            $aktivitasTetelNev = trim((string)($multiAktivitasAdat['display_item']['nev'] ?? ''));
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($aktivitasTermekNev); ?><?php if ($aktivitasTetelNev !== ''): ?> <span style="color:#c0ff00;">|</span> <?php echo htmlspecialchars($aktivitasTetelNev); ?><?php endif; ?></strong></td>
                                    <td><?php echo !empty($la['licit_datum']) ? date('Y.m.d H:i', strtotime((string)$la['licit_datum'])) : '-'; ?></td>
                                    <td>
                                        <?php echo intval($la['licitalok_szama']); ?> <?php echo htmlspecialchars(st('profile.table.bidder', 'licitalo')); ?>
                                        <?php if ($aktivitasVezeto !== ''): ?><div class="profile-table__sub">Vezet: <?php echo htmlspecialchars($aktivitasVezeto); ?></div><?php endif; ?>
                                    </td>
                                    <td class="profile-table__price"><?php echo number_format($la['aktualis_ar'], 0, ',', ' '); ?> Ft</td>
                                    <td><a class="profile-table__link" href="index.php?id=<?php echo $la['id']; ?>"><?php echo htmlspecialchars(st('profile.action.open', 'Megnyitas')); ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                    <?php if ($aktivitasOldalak > 1): ?>
                    <div class="profile-pagination">
                        <?php for ($aktivitasLap = 1; $aktivitasLap <= $aktivitasOldalak; $aktivitasLap++): ?>
                            <a class="profile-pagination__link <?php echo $aktivitasLap === $aktivitasOldal ? 'is-active' : ''; ?>" href="profil.php?aktivitas_oldal=<?php echo $aktivitasLap; ?>#aktivitasom"><?php echo $aktivitasLap; ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="profile-empty"><?php echo htmlspecialchars(st('profile.activity.empty', 'Meg nem licitaltal semmire.')); ?></div>
                    <?php endif; ?>
                </section>

            </div>
            <div id="vasarlasaim" class="profile-tabpanel">
                <section class="profile-panel">
                    <div class="profile-panel__head">
                        <h2><?php echo htmlspecialchars(st('profile.purchases.title', 'Sikeres vasarlasaim')); ?></h2>
                    </div>
                    <?php if ($sikeresVasarlasok): ?>
                    <div class="profile-tablewrap" style="overflow-x:auto;max-width:100%;-webkit-overflow-scrolling:touch;">
                        <div class="profile-tabledrag" style="display:inline-block;width:max-content;min-width:max-content;">
                        <table class="profile-table" style="min-width:980px;width:max-content;">
                            <thead>
                                <tr><th><?php echo htmlspecialchars(st('profile.table.product', 'Termek')); ?></th><th><?php echo htmlspecialchars(st('profile.table.category', 'Kategoria')); ?></th><th><?php echo htmlspecialchars(st('profile.table.type', 'Tipus')); ?></th><th><?php echo htmlspecialchars(st('profile.table.date', 'Datum')); ?></th><th><?php echo htmlspecialchars(st('profile.table.price', 'Ar')); ?></th><th><?php echo htmlspecialchars(st('profile.table.status', 'Statusz')); ?></th><th><?php echo htmlspecialchars(st('profile.table.action', 'Muvelet')); ?></th><th><?php echo htmlspecialchars(st('profile.table.action', 'Muvelet')); ?></th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($sikeresVasarlasokOldal as $ny): ?>
                                <?php $nyMuvelet = $penzugyiMuveletekMap[$ny['muvelet_azonosito'] ?? ''] ?? ['megkapta' => false]; ?>
                                <?php
                                    $vasarlasTipus = (string)($ny['vasarlas_tipus'] ?? 'licit');
                                    $kuponInfo = $kuponKodByMuvelet[$ny['muvelet_azonosito'] ?? ''] ?? null;
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($ny['nev']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($ny['kat_nev'] ?? ''); ?></td>
                                    <td><?php
                                        echo $vasarlasTipus === 'fix'
                                            ? 'Fix áras vétel'
                                            : ($vasarlasTipus === 'kupon' ? 'Kupon' : ($vasarlasTipus === 'multi' ? 'Megnyert LICIT SHOP' : 'Megnyert licit'));
                                    ?></td>
                                    <td><?php echo !empty($ny['vasarlas_datum']) ? date('Y.m.d H:i', strtotime((string)$ny['vasarlas_datum'])) : '-'; ?></td>
                                    <td class="profile-table__price"><?php echo number_format($ny['aktualis_ar'], 0, ',', ' '); ?> Ft</td>
                                    <td>
                                        <?php if ($vasarlasTipus === 'kupon'): ?>
                                            <?php if (is_array($kuponInfo) && (($kuponInfo['allapot'] ?? '') === 'bevaltva')): ?>
                                                <span>Nem beváltható</span>
                                            <?php else: ?>
                                                <?php
                                                    $bevaltasDatumRaw = trim((string)($ny['kupon_bevaltas_vege'] ?? ''));
                                                    if ($bevaltasDatumRaw === '' || $bevaltasDatumRaw === '0000-00-00') {
                                                        $bevaltasDatumRaw = trim((string)($ny['lejarat_idopont'] ?? ''));
                                                    }
                                                    $bevaltasDatumFmt = '';
                                                    if ($bevaltasDatumRaw !== '') {
                                                        $bevaltasTs = strtotime($bevaltasDatumRaw);
                                                        if ($bevaltasTs) {
                                                            $bevaltasDatumFmt = date('Y.m.d.', $bevaltasTs);
                                                        }
                                                    }
                                                ?>
                                                <?php if ($bevaltasDatumFmt !== ''): ?>
                                                    <span>Beváltható: <?php echo htmlspecialchars($bevaltasDatumFmt, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php else: ?>
                                                    <span>Beváltható</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php echo !empty($nyMuvelet['megkapta']) ? '<span class="profile-status-tag profile-status-tag--received">' . htmlspecialchars(st('profile.status.received_upper', 'MEGKAPTAM')) . '</span>' : htmlspecialchars(vasarloStatuszProfil($penzugyiStatuszMap[$ny['muvelet_azonosito'] ?? ''] ?? st('admin.finance.waiting_for_shipping', 'Postazasra var'))); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><a class="profile-table__link" href="index.php?id=<?php echo $ny['id']; ?>"><?php echo htmlspecialchars(st('profile.action.open', 'Megnyitas')); ?></a></td>
                                    <td>
                                        <?php if ($vasarlasTipus === 'kupon'): ?>
                                            <?php if (is_array($kuponInfo) && !empty($kuponInfo['kod']) && (($kuponInfo['allapot'] ?? '') !== 'bevaltva')): ?>
                                                <button type="button" class="profile-submit profile-submit--compact" onclick="openKuponKodModal('<?php echo htmlspecialchars((string)$kuponInfo['kod'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars('https://api.qrserver.com/v1/create-qr-code/?size=360x360&data=' . rawurlencode((string)$kuponInfo['kod']), ENT_QUOTES, 'UTF-8'); ?>')">Beváltom</button>
                                            <?php else: ?>
                                                &nbsp;
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <form method="POST" class="profile-action-form">
                                                <input type="hidden" name="muvelet_azonosito" value="<?php echo htmlspecialchars($ny['muvelet_azonosito'] ?? ''); ?>">
                                                <input type="hidden" name="muvelet_tipus" value="megkapta">
                                                <input type="hidden" name="vissza_hash" value="vasarlasaim">
                                                <label class="profile-action-check">
                                                    <input class="profile-action-check__input" type="checkbox" name="muvelet_aktiv" <?php echo !empty($nyMuvelet['megkapta']) ? 'checked' : ''; ?>>
                                                    <span class="profile-table__sub"><?php echo htmlspecialchars(st('profile.status.received_me', 'Megkaptam')); ?></span>
                                                </label>
                                                <button type="submit" name="penzugyi_muvelet_mentes" class="profile-submit profile-submit--compact"><?php echo htmlspecialchars(st('common.save', 'Mentes')); ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                    <?php if ($vasarlasOldalak > 1): ?>
                    <div class="profile-pagination">
                        <?php for ($vasarlasLap = 1; $vasarlasLap <= $vasarlasOldalak; $vasarlasLap++): ?>
                            <a class="profile-pagination__link <?php echo $vasarlasLap === $vasarlasOldal ? 'is-active' : ''; ?>" href="profil.php?vasarlas_oldal=<?php echo $vasarlasLap; ?>#vasarlasaim"><?php echo $vasarlasLap; ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="profile-empty"><?php echo htmlspecialchars(st('profile.purchases.empty', 'Meg nincs sikeres vasarlasod.')); ?></div>
                    <?php endif; ?>
                </section>

            </div>
            <div id="ajanlataim" class="profile-tabpanel">
                <section class="profile-panel">
                    <div class="profile-panel__head">
                        <h2><?php echo htmlspecialchars(st('profile.offers.title', 'Sajat ajanlataim')); ?></h2>
                    </div>
                    <?php if ($szerkesztettSajatAukcio): ?>
                    <div class="profile-inline-editor">
                        <div class="profile-inline-editor__head">
                            <h3><?php echo $szerkesztettSajatAukcioMulti ? 'LICIT SHOP szerkeszt&eacute;se' : ($szerkesztettSajatAukcioFix ? 'Fix &aacute;ras aj&aacute;nlat szerkeszt&eacute;se' : 'Aukci&oacute; szerkeszt&eacute;se'); ?></h3>
                            <a class="profile-table__link" href="profil.php#ajanlataim">Bez&aacute;r&aacute;s</a>
                        </div>
                        <form method="POST" enctype="multipart/form-data" class="profile-formgrid">
                            <input type="hidden" name="sajat_aukcio_id" value="<?php echo intval($szerkesztettSajatAukcio['id']); ?>">
                            <?php if ($szerkesztettSajatAukcioMulti): ?>
                            <div class="create-field create-field--full"><label>F&#337;n&eacute;v</label><input type="text" value="<?php echo htmlspecialchars($szerkesztettSajatAukcio['nev']); ?>" readonly></div>
                            <div class="create-field create-field--full"><label>Kateg&oacute;ria</label><input type="text" value="<?php echo htmlspecialchars($szerkesztettSajatAukcio['kat_nev'] ?? ''); ?>" readonly></div>
                            <div class="create-field create-field--full"><label>YouTube vide&oacute; link</label><input type="text" name="uj_video_url" value="<?php echo htmlspecialchars($szerkesztettSajatAukcio['video_url'] ?? ''); ?>" required></div>
                            <div class="create-field create-field--full"><label>YouTube live link</label><input type="text" name="uj_live_url" value="<?php echo htmlspecialchars($szerkesztettSajatAukcio['live_url'] ?? ''); ?>" placeholder="https://www.youtube.com/live/..."></div>
                            <div class="create-field create-field--full"><label>Multi aukci&oacute; kezdete</label><input type="text" value="<?php echo !empty($szerkesztettSajatAukcio['kezdes_idopont']) ? date('Y. m. d. H:i', strtotime($szerkesztettSajatAukcio['kezdes_idopont'])) : ''; ?>" readonly></div>
                            <div class="create-field create-field--full">
                                <label>F&#337;k&eacute;p</label>
                                <label class="create-filepicker create-filepicker--disabled" aria-disabled="true">
                                    <input type="file" disabled>
                                    <span class="create-filepicker__button">K&eacute;p kiv&aacute;laszt&aacute;sa</span>
                                    <span class="create-filepicker__name">A jelenlegi k&eacute;p megtarthat&oacute;, vagy v&aacute;laszthatsz &uacute;jat</span>
                                </label>
                            </div>
                            <?php elseif ($szerkesztettSajatAukcioFix): ?>
                            <label>Term&eacute;k neve</label>
                            <input type="text" value="<?php echo htmlspecialchars($szerkesztettSajatAukcio['nev']); ?>" readonly>
                            <label>T&iacute;pus</label>
                            <input type="text" value="Fix &aacute;r" readonly>
                            <label>Kateg&oacute;ria</label>
                            <input type="text" value="<?php echo htmlspecialchars($szerkesztettSajatAukcio['kat_nev'] ?? ''); ?>" readonly>
                            <label>Le&iacute;r&aacute;s</label>
                            <textarea rows="4" readonly><?php echo htmlspecialchars($szerkesztettSajatAukcio['leiras'] ?? ''); ?></textarea>
                            <label>Fix &aacute;r</label>
                            <input type="text" value="<?php echo number_format(intval($szerkesztettSajatAukcio['fix_ar'] ?? $szerkesztettSajatAukcio['aktualis_ar']), 0, ',', ' '); ?> Ft" readonly>
                            <label>Eredeti &aacute;r</label>
                            <input type="text" value="<?php echo number_format(intval($szerkesztettSajatAukcio['eredeti_ar'] ?? 0), 0, ',', ' '); ?> Ft" readonly>
                            <label>V&aacute;ros</label>
                            <input type="text" value="<?php echo htmlspecialchars($szerkesztettSajatAukcio['varos'] ?? ''); ?>" readonly>
                            <label>Darabsz&aacute;m</label>
                            <input type="text" value="<?php echo intval($szerkesztettSajatAukcio['darabszam'] ?? 0); ?>" readonly>
                            <label>Sz&aacute;ll&iacute;t&aacute;s</label>
                            <input type="text" value="<?php echo ($szerkesztettSajatAukcio['szallitasi_mod'] ?? 'ingyenes') === 'egyedi' ? number_format(intval($szerkesztettSajatAukcio['szallitasi_dij'] ?? 0), 0, ',', ' ') . ' Ft' : 'Ingyenes'; ?>" readonly>
                            <label>Kezd&eacute;s</label>
                            <input type="text" value="<?php echo !empty($szerkesztettSajatAukcio['kezdes_idopont']) ? date('Y.m.d H:i', strtotime($szerkesztettSajatAukcio['kezdes_idopont'])) : ''; ?>" readonly>
                            <label>Lej&aacute;rat</label>
                            <input type="text" value="<?php echo !empty($szerkesztettSajatAukcio['lejarat_idopont']) ? date('Y.m.d H:i', strtotime($szerkesztettSajatAukcio['lejarat_idopont'])) : ''; ?>" readonly>
                            <label>YouTube vide&oacute; link</label>
                            <input type="text" name="uj_video_url" value="<?php echo htmlspecialchars($szerkesztettSajatAukcio['video_url'] ?? ''); ?>" required>
                            <label>YouTube live link</label>
                            <input type="text" name="uj_live_url" value="<?php echo htmlspecialchars($szerkesztettSajatAukcio['live_url'] ?? ''); ?>" placeholder="https://www.youtube.com/live/...">
                            <div class="create-field create-field--full">
                                <label>F&#337;k&eacute;p</label>
                                <label class="create-filepicker" for="uj_ajanlat_kep">
                                    <span class="create-filepicker__button">K&eacute;p kiv&aacute;laszt&aacute;sa</span>
                                    <span class="create-filepicker__name" id="uj-ajanlat-kep-nev">A jelenlegi k&eacute;p megtarthat&oacute;, vagy v&aacute;laszthatsz &uacute;jat</span>
                                </label>
                                <input id="uj_ajanlat_kep" type="file" name="uj_ajanlat_kep" accept=".jpg,.jpeg,.png,.webp,.gif" hidden onchange="updateAjanlatKepPickerName(this)">
                            </div>
                            <?php else: ?>
                            <label>Term&eacute;k neve</label>
                            <input type="text" value="<?php echo htmlspecialchars($szerkesztettSajatAukcio['nev']); ?>" readonly>
                            <label>T&iacute;pus</label>
                            <input type="text" value="Licit" readonly>
                            <label>Kateg&oacute;ria</label>
                            <input type="text" value="<?php echo htmlspecialchars($szerkesztettSajatAukcio['kat_nev'] ?? ''); ?>" readonly>
                            <label>Le&iacute;r&aacute;s</label>
                            <textarea rows="4" readonly><?php echo htmlspecialchars($szerkesztettSajatAukcio['leiras'] ?? ''); ?></textarea>
                            <label>Aktu&aacute;lis &aacute;r</label>
                            <input type="text" value="<?php echo number_format(intval($szerkesztettSajatAukcio['aktualis_ar']), 0, ',', ' '); ?> Ft" readonly>
                            <label>Licitl&eacute;pcs&#337;</label>
                            <input type="text" value="<?php echo number_format(intval($szerkesztettSajatAukcio['licit_lepcso']), 0, ',', ' '); ?> Ft" readonly>
                            <label>Kezd&eacute;s</label>
                            <input type="text" value="<?php echo !empty($szerkesztettSajatAukcio['kezdes_idopont']) ? date('Y.m.d H:i', strtotime($szerkesztettSajatAukcio['kezdes_idopont'])) : ''; ?>" readonly>
                            <label>Lej&aacute;rat</label>
                            <input type="text" value="<?php echo !empty($szerkesztettSajatAukcio['lejarat_idopont']) ? date('Y.m.d H:i', strtotime($szerkesztettSajatAukcio['lejarat_idopont'])) : ''; ?>" readonly>
                            <label>YouTube vide&oacute; link</label>
                            <input type="text" name="uj_video_url" value="<?php echo htmlspecialchars($szerkesztettSajatAukcio['video_url'] ?? ''); ?>" required>
                            <label>YouTube live link</label>
                            <input type="text" name="uj_live_url" value="<?php echo htmlspecialchars($szerkesztettSajatAukcio['live_url'] ?? ''); ?>" placeholder="https://www.youtube.com/live/...">
                            <div class="create-field create-field--full">
                                <label>F&#337;k&eacute;p</label>
                                <label class="create-filepicker" for="uj_ajanlat_kep">
                                    <span class="create-filepicker__button">K&eacute;p kiv&aacute;laszt&aacute;sa</span>
                                    <span class="create-filepicker__name" id="uj-ajanlat-kep-nev">A jelenlegi k&eacute;p megtarthat&oacute;, vagy v&aacute;laszthatsz &uacute;jat</span>
                                </label>
                                <input id="uj_ajanlat_kep" type="file" name="uj_ajanlat_kep" accept=".jpg,.jpeg,.png,.webp,.gif" hidden onchange="updateAjanlatKepPickerName(this)">
                            </div>
                            <?php endif; ?>
                            <div class="profile-inline-editor__actions">
                                <button type="submit" name="sajat_aukcio_video_mentes" class="profile-submit"><?php echo ($szerkesztettSajatAukcioMulti || $szerkesztettSajatAukcioFix || (($szerkesztettSajatAukcio['ajanlat_tipus'] ?? 'licit') === 'kupon')) ? 'Linkek ment&eacute;se' : 'Vide&oacute; link ment&eacute;se'; ?></button>
                                <button type="submit" name="sajat_aukcio_torles" class="create-cancel create-cancel--danger" onclick="return confirm('Biztosan t&ouml;rl&ouml;d ezt az aukci&oacute;t?')">T&ouml;rl&eacute;s</button>
                            </div>
                        </form>
                        <?php if ($szerkesztettSajatAukcioMulti && $szerkesztettSajatAukcioTetelek): ?>
                        <div class="profile-inline-editor__multi">
                            <h4>R&eacute;szaukci&oacute;k</h4>
                            <div class="multi-items-stack">
                                <?php foreach ($szerkesztettSajatAukcioTetelek as $index => $tetel): ?>
                                <details class="multi-item-card" open>
                                    <summary class="multi-item-card__summary"><span>T&eacute;tel #<?php echo $index + 1; ?></span></summary>
                                    <div class="multi-item-card__body">
                                        <div class="create-field create-field--full"><label>N&eacute;v</label><input type="text" value="<?php echo htmlspecialchars($tetel['nev']); ?>" readonly></div>
                                        <div class="create-field create-field--full"><label>Poz&iacute;ci&oacute;</label><input type="text" value="<?php echo intval($tetel['sorszam']); ?>" readonly></div>
                                        <div class="create-field create-field--full"><label>Le&iacute;r&aacute;s</label><textarea rows="4" readonly><?php echo htmlspecialchars($tetel['leiras'] ?? ''); ?></textarea></div>
                                        <div class="create-field create-field--full"><label>Kateg&oacute;ria</label><input type="text" value="<?php echo htmlspecialchars($tetel['kat_nev'] ?? ''); ?>" readonly></div>
                                        <div class="create-field create-field--full"><label>Sz&aacute;ll&iacute;t&aacute;si m&oacute;d</label><input type="text" value="<?php echo ($tetel['szallitasi_mod'] ?? 'ingyenes') === 'egyedi' ? 'Én adom meg a szállítási díjat' : 'Ingyenes szállítás'; ?>" readonly></div>
                                        <div class="create-field create-field--full"><label>Sz&aacute;ll&iacute;t&aacute;si d&iacute;j</label><input type="text" value="<?php echo ($tetel['szallitasi_mod'] ?? 'ingyenes') === 'egyedi' ? number_format(intval($tetel['szallitasi_dij'] ?? 0), 0, ',', ' ') . ' Ft' : 'Ingyenes'; ?>" readonly></div>
                                        <div class="create-field create-field--full"><label>Indul&oacute; licit</label><input type="text" value="<?php echo number_format(intval($tetel['aktualis_ar'] ?? 0), 0, ',', ' '); ?> Ft" readonly></div>
                                        <div class="create-field create-field--full"><label>Licitl&eacute;pcs&#337;</label><input type="text" value="<?php echo number_format(intval($tetel['licit_lepcso'] ?? 0), 0, ',', ' '); ?> Ft" readonly></div>
                                        <div class="create-field create-field--full"><label>H&aacute;ny percig tartson</label><input type="text" value="<?php echo max(1, (int)ceil(intval($tetel['idotartam_mp'] ?? 0) / 60)); ?> perc" readonly></div>
                                    </div>
                                </details>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($sajatAjanlatok)): ?>
                    <div class="profile-tablewrap" style="overflow-x:auto;max-width:100%;-webkit-overflow-scrolling:touch;">
                        <div class="profile-tabledrag" style="display:inline-block;width:max-content;min-width:max-content;">
                        <table class="profile-table" style="min-width:1080px;width:max-content;">
                            <thead>
                                <tr><th>ID</th><th>N&eacute;v</th><th>T&iacute;pus</th><th>Felt&ouml;lt&#337;</th><th>Kateg&oacute;ria</th><th>D&aacute;tum</th><th>&Aacute;r / L&eacute;pcs&#337;</th><th>St&aacute;tusz</th><th>M&#369;velet</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($sajatAjanlatok as $s): ?>
                                <?php
                                $sajatTipus = ($s['ajanlat_tipus'] ?? 'licit') === 'fix'
                                    ? 'Fix &aacute;r'
                                    : ((($s['ajanlat_tipus'] ?? 'licit') === 'multi')
                                        ? 'LICIT SHOP'
                                        : ((($s['ajanlat_tipus'] ?? 'licit') === 'kupon') ? 'Kupon' : 'Licit'));
                                $sajatLejart = intval($s['eladva']) === 1 || (!empty($s['lejarat_idopont']) && strtotime($s['lejarat_idopont']) <= time());
                                $sajatArSzoveg = (($s['ajanlat_tipus'] ?? 'licit') === 'fix')
                                    ? number_format(intval($s['fix_ar'] ?? $s['aktualis_ar']), 0, ',', ' ') . ' Ft'
                                    : number_format(intval($s['aktualis_ar']), 0, ',', ' ') . ' Ft';
                                $sajatLepcsoSzoveg = (($s['ajanlat_tipus'] ?? 'licit') === 'fix')
                                    ? 'Fix &aacute;r'
                                    : '+' . intval($s['licit_lepcso']) . ' Ft';
                                ?>
                                <tr>
                                    <td>#<?php echo intval($s['id']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($s['nev']); ?></strong></td>
                                    <td><?php echo html_entity_decode($sajatTipus, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($becenev); ?></td>
                                    <td><?php echo htmlspecialchars($s['kat_nev'] ?? ''); ?></td>
                                    <td><?php echo !empty($s['kezdes_idopont']) ? date('Y.m.d H:i', strtotime((string)$s['kezdes_idopont'])) : '-'; ?></td>
                                    <td><?php echo $sajatArSzoveg; ?><div class="profile-table__sub"><?php echo html_entity_decode($sajatLepcsoSzoveg, ENT_QUOTES, 'UTF-8'); ?></div></td>
                                    <td><span class="admin-status <?php echo $sajatLejart ? 'is-closed' : 'is-live'; ?>"><?php echo $sajatLejart ? 'Lez&aacute;rt' : 'MOST'; ?></span></td>
                                    <td>
                                        <a class="profile-table__link" href="index.php?id=<?php echo $s['id']; ?>">Adatlap</a>
                                        <span class="admin-divider">|</span>
                                        <a class="profile-table__link" href="profil.php?sajat_szerkeszt=<?php echo $s['id']; ?>#ajanlataim">Szerkeszt&eacute;s</a>
                                        <?php if ($sajatLejart): ?>
                                        <span class="admin-divider">|</span>
                                        <a class="profile-table__link" href="uj_aukcio.php?ujrakozlom=<?php echo $s['id']; ?>">&Uacute;jrak&ouml;zl&ouml;m</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="profile-empty"><?php echo htmlspecialchars(st('profile.offers.empty', 'Meg nem toltottel fel sajat aukciot.')); ?></div>
                    <?php endif; ?>
                </section>
            </div>
            </div>

            <div id="kedvencek" class="profile-tabpanel">
                <section class="profile-panel">
                    <div class="profile-panel__head">
                        <h2><?php echo htmlspecialchars(st('profile.favorites.title', 'Kedvenc felhasznaloim')); ?></h2>
                    </div>
                    <?php if ($kedvencekTablaVan && $kedvencek && $kedvencek->num_rows > 0): ?>
                    <div class="favorite-users-grid">
                        <?php while ($kedvenc = $kedvencek->fetch_assoc()): ?>
                        <a class="favorite-user-card" href="index.php?elado=<?php echo intval($kedvenc['id']); ?>">
                            <?php if (!empty($kedvenc['profilkep'])): ?>
                            <img class="favorite-user-card__avatar <?php echo !empty($kedvenc['kiemelt_felhasznalo']) ? 'is-featured-user' : ''; ?>" src="/profilkepek/<?php echo htmlspecialchars($kedvenc['profilkep']); ?>" alt="<?php echo htmlspecialchars($kedvenc['becenev']); ?>">
                            <?php else: ?>
                            <span class="favorite-user-card__avatar favorite-user-card__avatar--fallback <?php echo !empty($kedvenc['kiemelt_felhasznalo']) ? 'is-featured-user' : ''; ?>"><?php echo strtoupper(substr($kedvenc['becenev'] ?: 'F', 0, 1)); ?></span>
                            <?php endif; ?>
                            <div class="favorite-user-card__name"><?php echo htmlspecialchars($kedvenc['becenev']); ?></div>
                            <div class="favorite-user-card__meta"><?php echo intval($kedvenc['aktiv_aukciok_szama']); ?> akt&iacute;v aukci&oacute;</div>
                        </a>
                        <?php endwhile; ?>
                    </div>
                    <?php else: ?>
                    <div class="profile-empty"><?php echo htmlspecialchars(st('profile.favorites.empty', 'Meg nincs kedvencnek jelolt felhasznalod.')); ?></div>
                    <?php endif; ?>
                </section>
            </div>

            <div id="penzugy" class="profile-tabpanel">
                <section class="profile-panel">
                    <div class="profile-panel__head">
                        <h2><?php echo htmlspecialchars(st('profile.payout.history_title', 'Kiutalasi elozmenyek')); ?></h2>
                    </div>
                    <?php if ($eladoKiutalasok): ?>
                    <div class="profile-tablewrap" style="overflow-x:auto;max-width:100%;-webkit-overflow-scrolling:touch;">
                        <div class="profile-tabledrag" style="display:inline-block;width:max-content;min-width:max-content;">
                        <table class="profile-table" style="min-width:980px;width:max-content;">
                            <thead>
                                <tr><th>D&aacute;tum</th><th>T&iacute;pus</th><th>Brutt&oacute; &ouml;sszeg</th><th>Levon&aacute;s</th><th>Kifizetett &ouml;sszeg</th><th>&Aacute;llapot</th><th>Stripe transfer</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($eladoKiutalasok as $kiutalas): ?>
                                <tr>
                                    <td><?php echo !empty($kiutalas['letrehozva']) ? date('Y.m.d H:i', strtotime((string)$kiutalas['letrehozva'])) : '-'; ?></td>
                                    <td><?php echo (($kiutalas['tipus'] ?? 'auto') === 'instant') ? 'Azonnali kiutal&aacute;s' : 'Automatikus kiutal&aacute;s'; ?></td>
                                    <td><?php echo number_format(floatval($kiutalas['brutto_osszeg'] ?? 0), 2, ',', ' '); ?> Ft</td>
                                    <td><?php echo number_format(floatval($kiutalas['levont_dij'] ?? 0), 2, ',', ' '); ?> Ft</td>
                                    <td class="profile-table__price"><?php echo number_format(floatval($kiutalas['kifizetett_osszeg'] ?? 0), 2, ',', ' '); ?> Ft</td>
                                    <td><?php echo htmlspecialchars(($kiutalas['statusz'] ?? 'processed') === 'processed' ? 'Feldolgozva' : (string)($kiutalas['statusz'] ?? 'Feldolgozva')); ?></td>
                                    <td class="profile-table__sub"><?php echo !empty($kiutalas['stripe_transfer_id']) ? htmlspecialchars((string)$kiutalas['stripe_transfer_id']) : '&mdash;'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="profile-empty"><?php echo htmlspecialchars(st('profile.payout.history_empty', 'Meg nincs kiutalasi elozmenyed.')); ?></div>
                    <?php endif; ?>

                    <div class="profile-panel__head">
                        <h2><?php echo htmlspecialchars(st('profile.tab.sales', 'Eladasaim')); ?></h2>
                    </div>
                    <div class="profile-balance-grid">
                        <article class="profile-balance-card">
                            <div class="profile-balance-card__label">F&uuml;gg&#337; egyenleg</div>
                            <div class="profile-balance-card__value"><?php echo number_format(floatval($eladoEgyenleg['pending'] ?? 0), 2, ',', ' '); ?> Ft</div>
                            <div class="profile-balance-card__note">M&eacute;g nem lett megjel&ouml;lve <strong>MEGKAPTAM</strong>-nak.</div>
                        </article>
                        <article class="profile-balance-card">
                            <div class="profile-balance-card__label">Kiutalhat&oacute; egyenleg</div>
                            <div class="profile-balance-card__value"><?php echo number_format(floatval($eladoEgyenleg['available'] ?? 0), 2, ',', ' '); ?> Ft</div>
                            <div class="profile-balance-card__note">10 000 Ft felett automatikusan kiutal&aacute;sra ker&uuml;l.</div>
                        </article>
                        <article class="profile-balance-card">
                            <div class="profile-balance-card__label">M&aacute;r kiutalt &ouml;sszeg</div>
                            <div class="profile-balance-card__value"><?php echo number_format(floatval($eladoEgyenleg['paid'] ?? 0), 2, ',', ' '); ?> Ft</div>
                            <div class="profile-balance-card__note">A rendszer m&aacute;r lez&aacute;rta &eacute;s kiutalta.</div>
                        </article>
                    </div>

                    <div class="profile-payout-box">
                        <div class="profile-payout-box__copy">
                            <strong>Kiutal&aacute;si szab&aacute;ly</strong>
                            <span>A kiutalhat&oacute; egyenleg automatikusan kimegy, ha el&eacute;ri a 10 000 Ft-ot. 10 000 Ft alatt k&eacute;rhetsz azonnali kiutal&aacute;st.</span>
                            <span>
                                Stripe Connect:
                                <?php if (!empty($stripeConnectSummary['ready'])): ?>
                                k&eacute;sz
                                <?php elseif (!empty($stripeConnectSummary['account_id'])): ?>
                                onboarding folyamatban
                                <?php else: ?>
                                nincs csatlakoztatva
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if ($azonnaliKiutalasElerheto): ?>
                        <form method="POST" class="profile-payout-box__form" onsubmit="return confirm('Biztosan k&eacute;red az azonnali kiutal&aacute;st? A d&iacute;j: 850 Ft + 0,25%.');">
                            <button type="submit" name="azonnali_kiutalas_igenylese" class="profile-submit">Azonnali kiutal&aacute;s k&eacute;r&eacute;se</button>
                        </form>
                        <?php else: ?>
                        <div class="profile-payout-box__hint">Az azonnali kiutal&aacute;s akkor k&eacute;rhet&#337;, ha van kiutalhat&oacute; egyenleged, de az m&eacute;g nem &eacute;ri el a 10 000 Ft-ot.</div>
                        <?php endif; ?>
                    </div>

                    <?php if ($penzugySorok): ?>
                    <div class="profile-tablewrap" style="overflow-x:auto;max-width:100%;-webkit-overflow-scrolling:touch;">
                        <div class="profile-tabledrag" style="display:inline-block;width:max-content;min-width:max-content;">
                        <table class="profile-table" style="min-width:1320px;width:max-content;">
                            <thead>
                                <tr><th>Term&eacute;k</th><th>T&iacute;pus</th><th>Aj&aacute;nlat d&aacute;tuma</th><th>Brutt&oacute; forgalom</th><th>Brutt&oacute; jutal&eacute;k</th><th>Brutt&oacute; k&aacute;rtyad&iacute;j</th><th>Brutt&oacute; kifizet&eacute;s</th><th>St&aacute;tusz</th><th>M&#369;velet</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($penzugySorokOldal as $sor): $t = $sor['adat']; $sz = $sor['szamitas']; ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($t['nev']); ?><?php if (!empty($t['tetel_nev'])): ?> <span style="color:#c0ff00;">|</span> <?php echo htmlspecialchars($t['tetel_nev']); ?><?php endif; ?></strong>
                                        <div class="profile-table__sub"><?php echo htmlspecialchars($t['kat_nev'] ?? ''); ?></div>
                                        <?php if (!empty($t['vasarlo_nev'])): ?><div class="profile-table__sub">Vev&#337;: <?php echo htmlspecialchars($t['vasarlo_nev']); ?></div><?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($t['tipus_label'] ?? 'Licit'); ?></td>
                                    <td><?php echo !empty($t['esemeny_datum']) ? date('Y.m.d H:i', strtotime((string)$t['esemeny_datum'])) : '-'; ?></td>
                                    <td><?php echo number_format($sz['brutto'], 2, ',', ' '); ?> Ft</td>
                                    <td><?php echo number_format($sz['jutalek_brutto'], 2, ',', ' '); ?> Ft</td>
                                    <td><?php echo number_format($sz['kartya_brutto'], 2, ',', ' '); ?> Ft</td>
                                    <td class="profile-table__price"><?php echo number_format($sz['kifizetes_brutto'], 2, ',', ' '); ?> Ft</td>
                                    <td><?php echo (($t['ajanlat_tipus'] ?? '') === 'kupon') ? '&mdash;' : htmlspecialchars($t['penzugyi_statusz'] ?? st('admin.finance.waiting_for_shipping', 'Postazasra var')); ?></td>
                                    <td>
                                        <?php if (($t['ajanlat_tipus'] ?? '') === 'kupon'): ?>
                                            &mdash;
                                        <?php else: ?>
                                        <?php $penzMuvelet = $penzugyiMuveletekMap[$t['statusz_azonosito'] ?? ''] ?? ['postara_adva' => false, 'megkapta' => false, 'tracking_kod' => '', 'posta_dokumentum' => '']; ?>
                                        <?php if (!empty($penzMuvelet['megkapta'])): ?><div class="profile-table__sub profile-status-tag profile-status-tag--received"><?php echo htmlspecialchars(st('profile.status.received_seller_upper', 'MEGKAPTA')); ?></div><?php endif; ?>
                                        <?php if (($t['penzugyi_statusz'] ?? '') === 'Kiutalva'): ?><div class="profile-table__sub profile-status-tag profile-status-tag--paid"><?php echo htmlspecialchars(st('profile.status.paid_out_upper', 'KIUTALVA')); ?></div><?php endif; ?>
                                        <form method="POST" enctype="multipart/form-data" class="profile-action-form">
                                            <input type="hidden" name="muvelet_azonosito" value="<?php echo htmlspecialchars($t['statusz_azonosito'] ?? ''); ?>">
                                            <input type="hidden" name="muvelet_tipus" value="postara_adva">
                                            <input type="hidden" name="vissza_hash" value="penzugy">
                                            <input type="hidden" name="meglevo_posta_dokumentum" value="<?php echo htmlspecialchars($penzMuvelet['posta_dokumentum'] ?? ''); ?>">
                                            <label class="profile-action-check">
                                                <input class="profile-action-check__input" type="checkbox" name="muvelet_aktiv" <?php echo !empty($penzMuvelet['postara_adva']) ? 'checked' : ''; ?>>
                                                <span class="profile-table__sub"><?php echo htmlspecialchars(st('profile.status.marked_shipped', 'Postara adtam')); ?></span>
                                            </label>
                                            <input class="profile-action-input" type="text" name="tracking_kod" placeholder="<?php echo htmlspecialchars(st('profile.tracking.placeholder', 'Tracking kod')); ?>" value="<?php echo htmlspecialchars($penzMuvelet['tracking_kod'] ?? ''); ?>">
                                            <label class="create-filepicker profile-action-filepicker" for="posta_dokumentum_<?php echo htmlspecialchars(md5((string)($t['statusz_azonosito'] ?? ''))); ?>">
                                                <span class="create-filepicker__button">Dokumentum</span>
                                                <span class="create-filepicker__name"><?php echo !empty($penzMuvelet['posta_dokumentum']) ? htmlspecialchars($penzMuvelet['posta_dokumentum']) : htmlspecialchars(st('profile.document.none_selected', 'Nincs dokumentum kivalasztva')); ?></span>
                                            </label>
                                            <input id="posta_dokumentum_<?php echo htmlspecialchars(md5((string)($t['statusz_azonosito'] ?? ''))); ?>" type="file" name="posta_dokumentum" accept=".pdf,.jpg,.jpeg,.png,.webp" hidden>
                                            <?php if (!empty($penzMuvelet['posta_dokumentum'])): ?>
                                            <a class="profile-table__link" href="/dokumentumok/<?php echo rawurlencode((string)$penzMuvelet['posta_dokumentum']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars(st('profile.document.open', 'Dokumentum megnyitasa')); ?></a>
                                            <?php endif; ?>
                                            <button type="submit" name="penzugyi_muvelet_mentes" class="profile-submit profile-submit--compact">Ment&eacute;s</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                    <?php if ($eladasOldalak > 1): ?>
                    <div class="profile-pagination">
                        <?php for ($eladasLap = 1; $eladasLap <= $eladasOldalak; $eladasLap++): ?>
                            <a class="profile-pagination__link <?php echo $eladasLap === $eladasOldal ? 'is-active' : ''; ?>" href="profil.php?eladas_oldal=<?php echo $eladasLap; ?>#penzugy"><?php echo $eladasLap; ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($eladottKuponok)): ?>
                    <div class="profile-panel" style="margin-top:1rem;">
                        <div class="profile-panel__head">
                            <h2>Kupon ellenőrzés</h2>
                        </div>
                        <form method="POST" id="kupon-ellenorzes-form" class="profile-action-form" style="margin-bottom:1rem;">
                            <input type="hidden" name="vissza_hash" value="penzugy">
                            <input type="hidden" name="kupon_ellenorzes" value="1">
                            <input class="profile-action-input" id="kupon-kod-input" type="text" name="kupon_kod" placeholder="Kupon ID (pl. KABC123...)" required>
                            <button type="button" class="profile-submit profile-submit--compact" onclick="startKuponKameraScan()">Kamera</button>
                            <button type="submit" class="profile-submit profile-submit--compact">Ellenőrzés</button>
                        </form>
                        <div class="profile-tablewrap kupon-ellenorzes-wrap" style="overflow-x:auto;max-width:100%;-webkit-overflow-scrolling:touch;">
                            <div class="profile-tabledrag" style="display:inline-block;width:max-content;min-width:max-content;">
                            <table class="profile-table kupon-ellenorzes-table" style="min-width:920px;width:max-content;">
                                <thead>
                                    <tr><th>Kupon</th><th>Kupon ID</th><th>Állapot</th><th>Dátum</th><th>Eladott / Beváltott</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach (array_slice($eladottKuponok, 0, 40) as $ek): ?>
                                    <?php $bevalt = ((string)($ek['allapot'] ?? '') === 'bevaltva'); ?>
                                    <?php $ekTermekId = intval($ek['termek_id'] ?? 0); ?>
                                    <?php $ekStat = ($ekTermekId > 0 && isset($eladottKuponStatByTermek[$ekTermekId])) ? $eladottKuponStatByTermek[$ekTermekId] : ['eladott' => 0, 'bevaltott' => 0]; ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars((string)($ek['termek_nev'] ?? 'Kupon')); ?></strong></td>
                                        <td><?php echo htmlspecialchars((string)($ek['kupon_kod'] ?? '')); ?></td>
                                        <td>
                                            <?php if ($bevalt): ?>
                                                <span class="profile-status-tag" style="background:#e2ff00;color:#111;font-weight:800;">Beváltva</span>
                                            <?php else: ?>
                                                <span>Beváltható</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo !empty($ek['vasarolva_at']) ? date('Y.m.d H:i', strtotime((string)$ek['vasarolva_at'])) : '-'; ?></td>
                                        <td><?php echo intval($ekStat['eladott']); ?> / <?php echo intval($ekStat['bevaltott']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="profile-empty"><?php echo htmlspecialchars(st('profile.sales.empty', 'Meg nincs kifizetheto eladott termeked.')); ?></div>
                    <?php endif; ?>
                </section>
            </div>

            <div id="bankkartya" class="profile-tabpanel">
                <section class="profile-panel">
                    <div class="profile-panel__head">
                        <h2><?php echo htmlspecialchars(st('profile.tab.payment', 'Fizetesi beallitasok')); ?></h2>
                    </div>
                    <div class="profile-payment-card">
                        <div class="profile-payment-card__status <?php echo $fizetesiBeallitasKesz ? 'is-ready' : 'is-missing'; ?>">
                            <?php if ($fizetesiBeallitasKesz): ?>
                            <strong><?php echo htmlspecialchars(st('profile.payment.ready_title', 'Stripe fizetesi mod elmentve')); ?></strong>
                            <span><?php echo htmlspecialchars(st('profile.payment.ready_desc', 'A rendszer keszen all a vasarlasra, licitalasra es eladasra.')); ?></span>
                            <?php else: ?>
                            <strong><?php echo htmlspecialchars(st('profile.payment.missing_title', 'Meg nincs elmentett fizetesi mod')); ?></strong>
                            <span><?php echo htmlspecialchars(st('profile.payment.missing_desc', 'Amig itt nem mentesz bankkartyat a Stripe rendszerben, csak nezelodni tudsz az oldalon.')); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="profile-payment-card__body">
                            <p>A bankk&aacute;rtya adatait nem a Shobid t&aacute;rolja, hanem a Stripe biztons&aacute;gos fizet&eacute;si fel&uuml;lete. A gombra kattintva &aacute;tvisz&uuml;nk a Stripe-hoz, ott tudod elmenteni a k&aacute;rty&aacute;dat.</p>
                            <p>Ha m&aacute;r van mentett k&aacute;rty&aacute;d, ugyanitt tudod friss&iacute;teni vagy cser&eacute;lni.</p>
                            <form method="POST" class="profile-payment-card__form">
                                <button type="submit" name="stripe_fizetesi_setup_inditas" class="profile-submit"><?php echo $fizetesiBeallitasKesz ? htmlspecialchars(st('profile.payment.card_update', 'Kartya frissitese Stripe-ban')) : htmlspecialchars(st('profile.payment.card_save', 'Bankkartya mentese Stripe-ban')); ?></button>
                            </form>
                        </div>
                    </div>

                    <div class="profile-payment-card" style="margin-top:1rem;">
                        <div class="profile-payment-card__status <?php echo !empty($stripeConnectSummary['ready']) ? 'is-ready' : 'is-missing'; ?>">
                            <?php if (!empty($stripeConnectSummary['ready'])): ?>
                            <strong>Stripe kifizet&eacute;si fi&oacute;k csatlakoztatva</strong>
                            <span>Az elad&oacute;i kiutal&aacute;sok ehhez a Stripe fiókhoz kapcsolhatók.</span>
                            <?php elseif (!empty($stripeConnectSummary['account_id'])): ?>
                            <strong>A Stripe kifizet&eacute;si fi&oacute;k folyamatban van</strong>
                            <span>A fi&oacute;k m&aacute;r l&eacute;tezik, de az onboarding m&eacute;g nincs teljesen befejezve.</span>
                            <?php else: ?>
                            <strong>M&eacute;g nincs csatlakoztatott kifizet&eacute;si fi&oacute;k</strong>
                            <span>Az automatikus elad&oacute;i kiutal&aacute;sokhoz csatlakoztatnod kell a saj&aacute;t Stripe kifizet&eacute;si fi&oacute;kodat.</span>
                            <?php endif; ?>
                        </div>

                        <div class="profile-payment-card__body">
                            <p>A kifizet&eacute;si fi&oacute;k nem ugyanaz, mint a bankk&aacute;rtya-ment&eacute;s. Itt azt a Stripe fi&oacute;kot kapcsolod &ouml;ssze, ahov&aacute; az elad&aacute;sok ut&aacute;ni kiutal&aacute;sok meg fognak &eacute;rkezni.</p>
                            <?php if (!empty($stripeConnectSummary['account_id'])): ?>
                            <p>Stripe fi&oacute;k azonos&iacute;t&oacute;: <strong><?php echo htmlspecialchars($stripeConnectSummary['account_id']); ?></strong></p>
                            <form method="POST" class="profile-payment-card__form">
                                <button type="submit" name="stripe_connect_dashboard_nyitas" class="profile-submit">Stripe kifizet&eacute;si fi&oacute;k megnyit&aacute;sa</button>
                            </form>
                            <?php endif; ?>
                            <?php if (empty($stripeConnectSummary['ready'])): ?>
                            <form method="POST" class="profile-payment-card__form">
                                <button type="submit" name="stripe_connect_onboarding_inditas" class="profile-submit">
                                    <?php echo !empty($stripeConnectSummary['account_id']) ? 'Stripe onboarding folytat&aacute;sa' : 'Stripe kifizet&eacute;si fi&oacute;k csatlakoztat&aacute;sa'; ?>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>

            <div id="beallitasok" class="profile-tabpanel">
                <div class="profile-settings-grid">
                    <section class="profile-panel">
                        <div class="profile-panel__head">
                            <h2>Szem&eacute;lyes adatok</h2>
                        </div>
                        <form method="POST" enctype="multipart/form-data" class="profile-formgrid">
                            <div class="profile-avatar-editor">
                                <?php if (!empty($user['profilkep'])): ?>
                                <img class="profile-avatar-image <?php echo !empty($user['kiemelt_felhasznalo']) ? 'is-featured-user' : ''; ?>" src="/profilkepek/<?php echo htmlspecialchars($user['profilkep']); ?>" alt="Profilk&eacute;p">
                                <?php else: ?>
                                <div class="profile-avatar-fallback <?php echo !empty($user['kiemelt_felhasznalo']) ? 'is-featured-user' : ''; ?>"><?php echo strtoupper(substr($user['becenev'], 0, 1)); ?></div>
                                <?php endif; ?>
                            </div>
                            <label class="required-label">Teljes n&eacute;v</label>
                            <input type="text" name="uj_teljes_nev" value="<?php echo htmlspecialchars($user['teljes_nev'] ?? ''); ?>" required>
                            <label class="required-label">Becen&eacute;v</label>
                            <input type="text" name="uj_becenev" value="<?php echo htmlspecialchars($user['becenev']); ?>" required>
                            <label class="required-label">Email c&iacute;m</label>
                            <input type="email" name="uj_email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            <label class="required-label">Orsz&aacute;g</label>
                            <?php profilRenderCustomCountrySelect('uj_orszag_kod', $profilFormOrszagKod, $profilOrszagOpcioLista, 'Valassz orszagot', true); ?>
                            <div class="create-help">Az orszag rogzitett, kesobb nem modosithato (egy fiok = egy orszag).</div>
                            <label>Telefonsz&aacute;m</label>
                            <input type="text" name="uj_telefonszam" value="<?php echo htmlspecialchars($user['telefonszam'] ?? ''); ?>">
                            <label class="required-label">Ir&aacute;ny&iacute;t&oacute;sz&aacute;m</label>
                            <input type="text" name="uj_lakcim_iranyitoszam" value="<?php echo htmlspecialchars($user['lakcim_iranyitoszam'] ?? ''); ?>" required>
                            <label class="required-label">V&aacute;ros</label>
                            <input type="text" name="uj_lakcim_varos" value="<?php echo htmlspecialchars($user['lakcim_varos'] ?? ''); ?>" required>
                            <label class="required-label">Utca</label>
                            <input type="text" name="uj_lakcim_utca" value="<?php echo htmlspecialchars($user['lakcim_utca'] ?? ''); ?>" required>
                            <label class="required-label">H&aacute;zsz&aacute;m</label>
                            <input type="text" name="uj_lakcim_hazszam" value="<?php echo htmlspecialchars($user['lakcim_hazszam'] ?? ''); ?>" required>
                            <label>Profilk&eacute;p cser&eacute;je</label>
                            <label class="create-filepicker" for="uj_profilkep">
                                <span class="create-filepicker__button">K&eacute;p kiv&aacute;laszt&aacute;sa</span>
                                <span class="create-filepicker__name" id="uj-profilkep-nev">Nem v&aacute;lasztott&aacute;l f&aacute;jlt</span>
                            </label>
                            <input id="uj_profilkep" type="file" name="uj_profilkep" accept=".jpg,.jpeg,.png,.webp,.gif" hidden onchange="updateProfilePickerName(this)">
                            <label class="profile-action-check required-label">
                                <input class="profile-action-check__input" type="checkbox" name="uj_aszf_elfogadva" value="1" <?php echo !empty($user['aszf_elfogadva']) ? 'checked' : ''; ?> required>
                                <span>Elfogadom az &Aacute;ltal&aacute;nos szerz&#337;d&eacute;si felt&eacute;teleket</span>
                            </label>
                            <label class="profile-action-check required-label">
                                <input class="profile-action-check__input" type="checkbox" name="uj_fizetesi_szabalyzat_elfogadva" value="1" <?php echo !empty($user['fizetesi_szabalyzat_elfogadva']) ? 'checked' : ''; ?> required>
                                <span>Elfogadom a Fizet&eacute;si szab&aacute;lyzatot</span>
                            </label>
                            <button type="submit" name="adat_mentes" class="profile-submit">Ment&eacute;s</button>
                        </form>
                    </section>

                    <section class="profile-panel">
                        <div class="profile-panel__head">
                            <h2>Sz&aacute;ll&iacute;t&aacute;si c&iacute;m</h2>
                        </div>
                        <form method="POST" class="profile-formgrid">
                            <label class="required-label">Sz&aacute;ll&iacute;t&aacute;si n&eacute;v</label>
                            <input type="text" name="uj_szallitasi_nev" value="<?php echo htmlspecialchars($user['szallitasi_nev'] ?? ''); ?>" required>
                            <label class="required-label">Ir&aacute;ny&iacute;t&oacute;sz&aacute;m</label>
                            <input type="text" name="uj_szallitasi_iranyitoszam" value="<?php echo htmlspecialchars($user['szallitasi_iranyitoszam'] ?? ''); ?>" required>
                            <label class="required-label">V&aacute;ros</label>
                            <input type="text" name="uj_szallitasi_varos" value="<?php echo htmlspecialchars($user['szallitasi_varos'] ?? ''); ?>" required>
                            <label class="required-label">Utca</label>
                            <input type="text" name="uj_szallitasi_utca" value="<?php echo htmlspecialchars($user['szallitasi_utca'] ?? ''); ?>" required>
                            <label class="required-label">H&aacute;zsz&aacute;m</label>
                            <input type="text" name="uj_szallitasi_hazszam" value="<?php echo htmlspecialchars($user['szallitasi_hazszam'] ?? ''); ?>" required>
                            <label>Emelet / Ajt&oacute;</label>
                            <input type="text" name="uj_szallitasi_emelet_ajto" value="<?php echo htmlspecialchars($user['szallitasi_emelet_ajto'] ?? ''); ?>">
                            <label>Megjegyz&eacute;s</label>
                            <textarea name="uj_szallitasi_megjegyzes" rows="4"><?php echo htmlspecialchars($user['szallitasi_megjegyzes'] ?? ''); ?></textarea>
                            <button type="submit" name="szallitasi_cim_mentes" class="profile-submit">Ment&eacute;s</button>
                        </form>
                    </section>

                    <section class="profile-panel">
                        <div class="profile-panel__head">
                            <h2>C&eacute;ges adatok</h2>
                        </div>
                        <form method="POST" enctype="multipart/form-data" class="profile-formgrid">
                            <label>C&eacute;gn&eacute;v</label>
                            <input type="text" name="uj_cegnev" value="<?php echo htmlspecialchars($user['cegnev'] ?? ''); ?>">
                            <label>Sz&eacute;khely ir&aacute;ny&iacute;t&oacute;sz&aacute;m</label>
                            <input type="text" name="uj_ceges_iranyitoszam" value="<?php echo htmlspecialchars($user['ceges_iranyitoszam'] ?? ''); ?>">
                            <label>Sz&eacute;khely v&aacute;ros</label>
                            <input type="text" name="uj_ceges_varos" value="<?php echo htmlspecialchars($user['ceges_varos'] ?? ''); ?>">
                            <label>Sz&eacute;khely utca</label>
                            <input type="text" name="uj_ceges_utca" value="<?php echo htmlspecialchars($user['ceges_utca'] ?? ''); ?>">
                            <label>Sz&eacute;khely h&aacute;zsz&aacute;m</label>
                            <input type="text" name="uj_ceges_hazszam" value="<?php echo htmlspecialchars($user['ceges_hazszam'] ?? ''); ?>">
                            <label>Ad&oacute;sz&aacute;m</label>
                            <input type="text" name="uj_adoszam" value="<?php echo htmlspecialchars($user['adoszam'] ?? ''); ?>">
                            <label class="profile-action-check">
                                <input class="profile-action-check__input" type="checkbox" name="uj_cegkent_vasarolok" value="1" <?php echo !empty($user['cegkent_vasarolok']) ? 'checked' : ''; ?>>
                                <span>C&eacute;gk&eacute;nt v&aacute;s&aacute;rolok / eladok</span>
                            </label>
                            <button type="submit" name="ceges_adatok_mentes" class="profile-submit">Ment&eacute;s</button>
                        </form>
                    </section>

                    <section class="profile-panel">
                        <div class="profile-panel__head">
                            <h2>Jelsz&oacute; m&oacute;dos&iacute;t&aacute;sa</h2>
                        </div>
                        <form method="POST" class="profile-formgrid">
                            <label><?php echo ($profilHianyos || isset($_GET['complete_profile'])) ? 'Jelenlegi jelsz&oacute; (els&#337; be&aacute;ll&iacute;t&aacute;sn&aacute;l nem k&ouml;telez&#337;)' : 'Jelenlegi jelsz&oacute;'; ?></label>
                            <input type="password" name="regi_jelszo" <?php echo ($profilHianyos || isset($_GET['complete_profile'])) ? '' : 'required'; ?>>
                            <label>&Uacute;j jelsz&oacute;</label>
                            <input type="password" name="uj_jelszo" required>
                            <button type="submit" name="jelszo_mentes" class="profile-submit">Jelsz&oacute; friss&iacute;t&eacute;se</button>
                        </form>
                    </section>
                </div>
            </div>
        </div>
    </main>
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

<div class="site-modal" id="kupon-kod-modal" aria-hidden="true">
    <div class="site-modal__backdrop" onclick="closeKuponKodModal()"></div>
    <div class="site-modal__dialog" role="dialog" aria-modal="true">
        <button class="site-modal__close" type="button" onclick="closeKuponKodModal()">&times;</button>
        <div id="kupon-kod-value" style="font-size:56px;font-weight:800;letter-spacing:2px;text-align:center;padding:24px 0;"></div>
        <div style="display:flex;justify-content:center;padding:0 0 18px 0;">
            <img id="kupon-kod-qr" src="" alt="Kupon QR" style="display:none;max-width:260px;width:100%;height:auto;border-radius:12px;background:#fff;padding:8px;">
        </div>
    </div>
</div>

<div class="site-modal" id="kupon-ellenorzes-modal" aria-hidden="true">
    <div class="site-modal__backdrop" onclick="closeKuponEllenorzesModal()"></div>
    <div class="site-modal__dialog" role="dialog" aria-modal="true">
        <button class="site-modal__close" type="button" onclick="closeKuponEllenorzesModal()">&times;</button>
        <div id="kupon-ellenorzes-icon" style="font-size:120px;line-height:1;text-align:center;padding:16px 0;"></div>
        <div id="kupon-ellenorzes-text" style="font-size:28px;font-weight:800;text-align:center;"></div>
    </div>
</div>

<div class="site-modal" id="kupon-kamera-modal" aria-hidden="true">
    <div class="site-modal__backdrop" onclick="stopKuponKameraScan()"></div>
    <div class="site-modal__dialog" role="dialog" aria-modal="true">
        <button class="site-modal__close" type="button" onclick="stopKuponKameraScan()">&times;</button>
        <h3 class="site-modal__title">Kupon beolvasás</h3>
        <video id="kupon-kamera-video" autoplay playsinline muted style="width:100%;border-radius:10px;background:#000;"></video>
        <div class="site-modal__text" id="kupon-kamera-help" style="margin-top:10px;">Irányítsd a kamerát a kupon QR/ID kódra, majd nyomd meg a Beolvasás gombot.</div>
        <div class="site-modal__actions" style="margin-top:12px;">
            <button type="button" class="profile-submit profile-submit--compact" onclick="readKuponFromCamera()">Beolvasás</button>
        </div>
    </div>
</div>

<script>
const kuponEllenorzesResult = <?php echo json_encode((string)($_GET['kupon_ellenorzes'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
const csrfToken = <?php echo json_encode(authCsrfToken(), JSON_UNESCAPED_UNICODE); ?>;
let kuponKameraStream = null;
let kuponBarcodeDetector = null;
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

function openKuponKodModal(kod, qrUrl) {
    var modal = document.getElementById('kupon-kod-modal');
    var value = document.getElementById('kupon-kod-value');
    var qr = document.getElementById('kupon-kod-qr');
    if (!modal || !value) return;
    value.textContent = kod || '';
    if (qr) {
        var finalQr = (qrUrl || '').trim();
        if (!finalQr && kod) {
            finalQr = 'https://api.qrserver.com/v1/create-qr-code/?size=360x360&data=' + encodeURIComponent(String(kod));
        }
        if (finalQr) {
            qr.src = finalQr;
            qr.style.display = 'block';
        } else {
            qr.src = '';
            qr.style.display = 'none';
        }
    }
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('has-site-modal');
}

function closeKuponKodModal() {
    var modal = document.getElementById('kupon-kod-modal');
    var qr = document.getElementById('kupon-kod-qr');
    if (!modal) return;
    if (qr) {
        qr.src = '';
        qr.style.display = 'none';
    }
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('has-site-modal');
}

function openKuponEllenorzesModal(result) {
    var modal = document.getElementById('kupon-ellenorzes-modal');
    var icon = document.getElementById('kupon-ellenorzes-icon');
    var text = document.getElementById('kupon-ellenorzes-text');
    if (!modal || !icon || !text) return;
    if (result === 'ok') {
        icon.textContent = '✔';
        icon.style.color = '#00ff66';
        text.textContent = 'Kupon beváltva';
    } else if (result === 'already') {
        icon.textContent = '✖';
        icon.style.color = '#ff2d2d';
        text.textContent = 'Már beváltott kupon';
    } else {
        icon.textContent = '✖';
        icon.style.color = '#ff2d2d';
        text.textContent = 'Érvénytelen kupon ID';
    }
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('has-site-modal');
}

function closeKuponEllenorzesModal() {
    var modal = document.getElementById('kupon-ellenorzes-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('has-site-modal');
}

async function startKuponKameraScan() {
    const modal = document.getElementById('kupon-kamera-modal');
    const video = document.getElementById('kupon-kamera-video');
    const help = document.getElementById('kupon-kamera-help');
    const input = document.getElementById('kupon-kod-input');
    if (!modal || !video || !input) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        alert('A kamera nem elérhető ezen az eszközön.');
        return;
    }
    try {
        kuponKameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
        video.srcObject = kuponKameraStream;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('has-site-modal');
    } catch (e) {
        alert('Nem sikerült megnyitni a kamerát.');
        return;
    }

    kuponBarcodeDetector = null;
    if ('BarcodeDetector' in window) {
        try {
            kuponBarcodeDetector = new BarcodeDetector({ formats: ['qr_code', 'code_128', 'code_39', 'ean_13'] });
        } catch (e) {
            kuponBarcodeDetector = null;
        }
    }
    if (help) {
        help.textContent = kuponBarcodeDetector
            ? 'Irányítsd a kamerát a kupon QR/ID kódra, majd nyomd meg a Beolvasás gombot.'
            : 'A böngésző nem támogatja az automata kódolvasást. Írd be kézzel a Kupon ID-t.';
    }
}

async function readKuponFromCamera() {
    const video = document.getElementById('kupon-kamera-video');
    const input = document.getElementById('kupon-kod-input');
    const form = document.getElementById('kupon-ellenorzes-form');
    const help = document.getElementById('kupon-kamera-help');
    if (!video || !input || !form) return;
    if (!kuponBarcodeDetector) {
        if (help) help.textContent = 'Automata beolvasás nem elérhető ezen a böngészőn. Add meg kézzel a Kupon ID-t.';
        return;
    }
    if (video.readyState < 2) {
        if (help) help.textContent = 'Várj egy pillanatot, amíg a kamera képe elindul, majd próbáld újra.';
        return;
    }
    try {
        const codes = await kuponBarcodeDetector.detect(video);
        if (codes && codes.length > 0) {
            const valueRaw = (codes[0].rawValue || '').trim();
            const value = valueRaw.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            if (value !== '') {
                input.value = value;
                stopKuponKameraScan();
                form.submit();
                return;
            }
        }
        if (help) help.textContent = 'Nem találtam olvasható kódot. Igazítsd rá jobban a kamerát, majd Beolvasás újra.';
    } catch (e) {
        if (help) help.textContent = 'A beolvasás sikertelen. Próbáld meg újra vagy add meg kézzel a Kupon ID-t.';
    }
}

function stopKuponKameraScan() {
    const modal = document.getElementById('kupon-kamera-modal');
    const video = document.getElementById('kupon-kamera-video');
    const help = document.getElementById('kupon-kamera-help');
    if (kuponKameraStream) {
        kuponKameraStream.getTracks().forEach(function (track) { track.stop(); });
        kuponKameraStream = null;
    }
    kuponBarcodeDetector = null;
    if (video) {
        video.srcObject = null;
    }
    if (help) {
        help.textContent = 'Irányítsd a kamerát a kupon QR/ID kódra, majd nyomd meg a Beolvasás gombot.';
    }
    if (modal) {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }
    document.body.classList.remove('has-site-modal');
}

function openTab(evt, tabName) {
    showProfileTab(tabName, evt ? evt.currentTarget : null);
}

function normalizeProfileTabName(tabName) {
    var normalized = (tabName || '').replace('#', '').trim();
    var params = new URLSearchParams(window.location.search || '');

    if (!normalized) {
        return params.has('sajat_szerkeszt') ? 'ajanlataim' : 'aktivitasom';
    }

    if (normalized === 'aukciok') {
        return params.has('sajat_szerkeszt') ? 'ajanlataim' : 'aktivitasom';
    }

    if (normalized === 'sikeresvasarlasok' || normalized === 'sikeres_vasarlasok') {
        return 'vasarlasaim';
    }

    if (normalized === 'fizetes' || normalized === 'bankkartyaadatok') {
        return 'bankkartya';
    }

    if (normalized === 'bankkartya') {
        return 'bankkartya';
    }

    if (normalized === 'fiok' || normalized === 'beallitasok') {
        return 'beallitasok';
    }

    if (normalized === 'kedvencek') {
        return 'kedvencek';
    }

    if (normalized === 'penzugy') {
        return 'penzugy';
    }

    if (normalized === 'ajanlataim') {
        return 'ajanlataim';
    }

    if (normalized === 'vasarlasaim') {
        return 'vasarlasaim';
    }

    return 'aktivitasom';
}

function showProfileTab(tabName, sourceButton) {
    tabName = normalizeProfileTabName(tabName);
    var tabcontent = document.getElementsByClassName("profile-tabpanel");
    for (var i = 0; i < tabcontent.length; i++) {
        tabcontent[i].classList.remove("is-active");
    }

    var tablinks = document.getElementsByClassName("profile-tab");
    for (var j = 0; j < tablinks.length; j++) {
        tablinks[j].classList.remove("is-active");
    }

    var targetPanel = document.getElementById(tabName);
    var targetButton = sourceButton || document.querySelector('.profile-tab[data-tab="' + tabName + '"]');
    if (!targetPanel || !targetButton) {
        return;
    }

    targetPanel.classList.add("is-active");
    targetButton.classList.add("is-active");
    if (window.location.hash !== '#' + tabName) {
        window.location.hash = tabName;
    }
}

function updateProfilePickerName(input) {
    var label = document.getElementById("uj-profilkep-nev");
    if (!label) return;
    label.textContent = input.files && input.files[0] ? input.files[0].name : "Nem választottál fájlt";
}
function updateAjanlatKepPickerName(input) {
    var label = document.getElementById("uj-ajanlat-kep-nev");
    if (!label) return;
    label.textContent = input.files && input.files[0]
        ? input.files[0].name
        : "A jelenlegi kép megtartható, vagy választhatsz újat";
}
function initCustomSelectsProfile() {
    var customSelects = document.querySelectorAll('[data-custom-select]');
    if (!customSelects.length) return;

    function closeAll(except) {
        customSelects.forEach(function (selectRoot) {
            if (selectRoot === except) return;
            selectRoot.classList.remove('is-open');
            var trigger = selectRoot.querySelector('[data-custom-select-trigger]');
            var menu = selectRoot.querySelector('[data-custom-select-menu]');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
            if (menu) menu.hidden = true;
        });
    }

    customSelects.forEach(function (selectRoot) {
        var nativeSelect = selectRoot.querySelector('[data-custom-select-native]');
        var trigger = selectRoot.querySelector('[data-custom-select-trigger]');
        var valueNode = selectRoot.querySelector('[data-custom-select-value]');
        var menu = selectRoot.querySelector('[data-custom-select-menu]');
        var options = selectRoot.querySelectorAll('[data-custom-select-option]');
        if (!nativeSelect || !trigger || !valueNode || !menu || !options.length) return;

        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var open = !selectRoot.classList.contains('is-open');
            closeAll(selectRoot);
            selectRoot.classList.toggle('is-open', open);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            menu.hidden = !open;
        });

        options.forEach(function (optionButton) {
            optionButton.addEventListener('click', function () {
                var value = optionButton.getAttribute('data-value') || '';
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

function syncHashToProfileTab() {
    showProfileTab(window.location.hash || '', null);
}
document.addEventListener("DOMContentLoaded", function () {
    initCustomSelectsProfile();
    syncHashToProfileTab();
    var receivedLabel = <?php echo json_encode(st('profile.status.received_seller_upper', 'MEGKAPTA'), JSON_UNESCAPED_UNICODE); ?>;
    var paidOutLabel = <?php echo json_encode(st('profile.status.paid_out_upper', 'KIUTALVA'), JSON_UNESCAPED_UNICODE); ?>;
    var rows = document.querySelectorAll('#penzugy .profile-table tbody tr');
    rows.forEach(function (row) {
        var cells = row.querySelectorAll('td');
        if (cells.length < 9) return;
        if (cells[8].textContent.indexOf(receivedLabel) === -1) return;
        var statusText = (cells[7].textContent || '').toUpperCase();
        var statusHtml = '<span class="profile-status-tag profile-status-tag--received">' + receivedLabel + '</span>';
        if (statusText.indexOf(paidOutLabel) !== -1) {
            statusHtml += '<br><span class="profile-status-tag profile-status-tag--paid">' + paidOutLabel + '</span>';
        }
        cells[7].innerHTML = statusHtml;
    });

    var tableWraps = document.querySelectorAll('.profile-tabledrag');
    tableWraps.forEach(function (wrap) {
        var table = wrap.querySelector('.profile-table');
        if (!table) return;
        var headerCells = table.querySelectorAll('thead th');
        var firstRowCells = table.querySelectorAll('tbody tr:first-child td');
        var columnCount = Math.max(headerCells.length, firstRowCells.length, 6);
        var minWidth = Math.max(columnCount * 9.25 * 16, 900);

        table.style.minWidth = minWidth + 'px';
        table.style.width = 'max-content';
        table.style.tableLayout = 'auto';
        table.style.whiteSpace = 'nowrap';
    });

    document.querySelectorAll('.profile-scroll-slider').forEach(function (slider) {
        if (slider && slider.parentNode) {
            slider.parentNode.removeChild(slider);
        }
    });

    if (kuponEllenorzesResult) {
        openKuponEllenorzesModal(kuponEllenorzesResult);
    }

});
window.addEventListener("hashchange", syncHashToProfileTab);

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





