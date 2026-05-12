<?php
include __DIR__ . '/db.php';
include __DIR__ . '/auth.php';
include __DIR__ . '/email_helper.php';
include __DIR__ . '/stripe_helper.php';
include_once __DIR__ . '/payout_helper.php';
include_once __DIR__ . '/global/i18n.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (!function_exists('apiJson')) {
    function apiJson($payload) {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $json = '{"ok":false,"error":"JSON encoding error"}';
        }
        echo $json;
    }
}

if (function_exists('shobidMarketEnsureTermekekOrszagKod')) {
    shobidMarketEnsureTermekekOrszagKod($conn);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !authValidateCsrfFromRequest()) {
    http_response_code(403);
    apiJson(['ok' => false, 'error' => 'CSRF token hiba']);
    exit;
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

function aktualisFelhasznaloNev($conn, $aktivUserId) {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $nev = trim((string)($_SESSION['becenev'] ?? ''));
    if ($nev === '') {
        $userId = intval($aktivUserId);
        if ($userId > 0) {
            $res = $conn->query("SELECT becenev FROM felhasznalok WHERE id = $userId LIMIT 1");
            $row = $res ? $res->fetch_assoc() : null;
            $nev = trim((string)($row['becenev'] ?? ''));
        }
    }

    if ($nev === '') {
        $nev = 'ismeretlen';
    }

    $cache = $nev;
    return $cache;
}

function getInditandoMultiTetel($multiAukcio) {
    if (!is_array($multiAukcio)) {
        return null;
    }

    $items = $multiAukcio['items'] ?? null;
    if (is_array($items) && !empty($items)) {
        $index = max(0, intval($multiAukcio['current_index'] ?? 0));
        if (isset($items[$index])) {
            return $items[$index];
        }
    }

    return $multiAukcio['next_item'] ?? ($multiAukcio['display_item'] ?? null);
}

function licitNaploOsszegOszlopApi($conn, $alias = 'ln') {
    foreach (['licit_osszeg', 'osszeg', 'ar', 'licit_ar'] as $oszlop) {
        if (oszlopLetezik($conn, 'licit_naplo', $oszlop)) {
            return $alias ? ($alias . '.' . $oszlop) : $oszlop;
        }
    }
    return null;
}

function licitNaploTetelOszlopApi($conn, $alias = 'ln') {
    foreach (['multi_tetel_id', 'tetel_id', 'multi_item_id', 'resz_tetel_id'] as $oszlop) {
        if (oszlopLetezik($conn, 'licit_naplo', $oszlop)) {
            return $alias ? ($alias . '.' . $oszlop) : $oszlop;
        }
    }
    return null;
}

function biztositsLicitLathatosag($conn, $termek, $isMulti, $displayItem) {
    if (!tablaLetezik($conn, 'uzenetek')) {
        return;
    }

    $termekId = intval($termek['id'] ?? 0);
    if ($termekId < 1) {
        return;
    }

    $licitaloNev = trim((string)($isMulti ? ($displayItem['legmagasabb_licit_felhasznalo'] ?? '') : ($termek['legmagasabb_licit_felhasznalo'] ?? '')));
    $licitOsszeg = intval($isMulti ? ($displayItem['aktualis_ar'] ?? 0) : ($termek['aktualis_ar'] ?? 0));
    if ($licitaloNev === '' || $licitOsszeg <= 0) {
        return;
    }

    $licitFormatalt = number_format($licitOsszeg, 0, ' ', ' ');
    if ($isMulti) {
        $tetelNev = trim((string)($displayItem['nev'] ?? ''));
        $tetelNev = html_entity_decode($tetelNev, ENT_QUOTES, 'UTF-8');
        $chatSzoveg = $tetelNev !== ''
            ? (html_entity_decode('&#128293; Licit&aacute;lt: ', ENT_QUOTES, 'UTF-8') . $tetelNev . ' - ' . $licitFormatalt . ' Ft')
            : (html_entity_decode('&#128293; Licit&aacute;lt: ', ENT_QUOTES, 'UTF-8') . $licitFormatalt . ' Ft');
    } else {
        $chatSzoveg = html_entity_decode('&#128293; Licit&aacute;lt: ', ENT_QUOTES, 'UTF-8') . $licitFormatalt . ' Ft';
    }

    $licitaloNevEsc = $conn->real_escape_string($licitaloNev);
    $chatSzovegEsc = $conn->real_escape_string($chatSzoveg);
    $chatRes = $conn->query("SELECT id FROM uzenetek WHERE termek_id = $termekId AND felhasznalo = '$licitaloNevEsc' AND szoveg = '$chatSzovegEsc' LIMIT 1");
    if (!($chatRes && $chatRes->num_rows > 0)) {
        $stmt = $conn->prepare("INSERT INTO uzenetek (felhasznalo, szoveg, termek_id) VALUES (?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('ssi', $licitaloNev, $chatSzoveg, $termekId);
            $stmt->execute();
            $stmt->close();
        }
    }

    if (!tablaLetezik($conn, 'licit_naplo')) {
        return;
    }

    $felhasznaloRes = $conn->query("SELECT id FROM felhasznalok WHERE becenev = '$licitaloNevEsc' LIMIT 1");
    $felhasznalo = $felhasznaloRes ? $felhasznaloRes->fetch_assoc() : null;
    $felhasznaloId = intval($felhasznalo['id'] ?? 0);
    if ($felhasznaloId < 1) {
        return;
    }

    $osszegOszlop = licitNaploOsszegOszlopApi($conn, '');
    if (!$osszegOszlop) {
        return;
    }

    $where = "termek_id = $termekId AND felhasznalo_id = $felhasznaloId AND $osszegOszlop = " . intval($licitOsszeg);
    $tetelOszlop = licitNaploTetelOszlopApi($conn, '');
    $multiTetelId = $isMulti ? intval($displayItem['id'] ?? 0) : 0;
    if ($tetelOszlop && $multiTetelId > 0) {
        $where .= " AND $tetelOszlop = $multiTetelId";
    }

    $naploRes = $conn->query("SELECT id FROM licit_naplo WHERE $where LIMIT 1");
    if (!($naploRes && $naploRes->num_rows > 0)) {
        mentLicitNaplo($conn, $termekId, $felhasznaloId, $licitOsszeg, $multiTetelId > 0 ? $multiTetelId : null);
    }
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
    $manualStartEnabled = false;

    if ($kezdesTs && $kezdesTs > $mostTs) {
        return [
            'allapot' => 'soon',
            'kezdes_ts' => $kezdesTs,
            'lejarat_ts' => $lejaratTs,
            'manual_start_required' => false,
            'manual_start_enabled' => false
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
                $manualStartEnabled = true;
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
        'manual_start_required' => $manualStartRequired,
        'manual_start_enabled' => $manualStartEnabled
    ];
}

function syncMultiTermekAktualisTetel($conn, $termekId, $item, $lezart = false) {
    $termekId = intval($termekId);
    if ($termekId < 1) {
        return;
    }

    if (!$item) {
        if ($lezart) {
            $conn->query("UPDATE termekek SET eladva = 1 WHERE id = $termekId");
        }
        return;
    }

    $kategoriaId = intval($item['kategoria_id'] ?? 0);
    $ar = intval($item['aktualis_ar'] ?? 0);
    $lepcso = intval($item['licit_lepcso'] ?? 0);
    $szallitasiMod = $conn->real_escape_string((string)($item['szallitasi_mod'] ?? 'ingyenes'));
    $szallitasiDij = intval($item['szallitasi_dij'] ?? 0);
    $eladva = $lezart ? 1 : 0;
    $conn->query("UPDATE termekek SET aktualis_ar = $ar, licit_lepcso = $lepcso, kategoria_id = $kategoriaId, szallitasi_mod = '$szallitasiMod', szallitasi_dij = $szallitasiDij, eladva = $eladva WHERE id = $termekId");
}

function rogzitsMultiTetelNyertest($conn, $termekId, $item) {
    $itemId = intval($item['id'] ?? 0);
    if ($itemId < 1) {
        return false;
    }

    $nyertes = trim((string)($item['legmagasabb_licit_felhasznalo'] ?? ''));
    if ($nyertes === '') {
        if (intval($item['eladva'] ?? 0) !== 1) {
            $conn->query("UPDATE multi_aukcio_tetelek SET eladva = 1 WHERE id = $itemId");
        }
        return true;
    }

    $osszegErtek = shobidPayoutBruttoWithShipping(
        intval($item['aktualis_ar'] ?? 0),
        (string)($item['szallitasi_mod'] ?? ''),
        intval($item['szallitasi_dij'] ?? 0)
    );
    $chargeCurrency = function_exists('shobidStripeTermekCurrencyById')
        ? shobidStripeTermekCurrencyById($conn, intval($termekId), function_exists('shobidI18nCurrentLocale') ? shobidI18nCurrentLocale() : 'hu-hu')
        : 'huf';
    if ($chargeCurrency === 'huf') {
        $osszegErtek = max(175, intval($osszegErtek));
    }
    $chargeKey = 'multi|tetel|' . $itemId . '|vevo|' . $nyertes;
    $chargeRes = shobidStripeAutoChargeByNickname(
        $conn,
        $chargeKey,
        $nyertes,
        intval($termekId),
        $osszegErtek,
        trim((string)($item['nev'] ?? ('LICIT SHOP tÄ‚Â©tel #' . $itemId))),
        [
            'ajanlat_tipus' => 'multi',
            'multi_tetel_id' => (string)$itemId
        ]
    );
    if (empty($chargeRes['ok'])) {
        $hibaSzoveg = trim((string)($chargeRes['error'] ?? 'Ismeretlen Stripe hiba'));
        $hibaUzenet = 'Figyelem: Nyertes fizetes sikertelen: '
            . html_entity_decode((string)($item['nev'] ?? ('Tetel #' . $itemId)), ENT_QUOTES, 'UTF-8')
            . ' - '
            . $nyertes
            . ' (' . $hibaSzoveg . ')'
            . ' [osszeg=' . intval($osszegErtek) . ' ' . strtoupper((string)$chargeCurrency) . ']';
        $hibaUzenetSql = $conn->real_escape_string($hibaUzenet);
        $vanUzenetRes = $conn->query("SELECT id FROM uzenetek WHERE termek_id = $termekId AND felhasznalo = 'Rendszer' AND szoveg = '$hibaUzenetSql' LIMIT 1");
        if (!($vanUzenetRes && $vanUzenetRes->num_rows > 0)) {
            $msgStmt = $conn->prepare("INSERT INTO uzenetek (felhasznalo, szoveg, termek_id) VALUES (?, ?, ?)");
            if ($msgStmt) {
                $rendszerNev = 'Rendszer';
                $msgStmt->bind_param('ssi', $rendszerNev, $hibaUzenet, $termekId);
                $msgStmt->execute();
                $msgStmt->close();
            }
        }
        return false;
    }

    if (intval($item['eladva'] ?? 0) !== 1) {
        $conn->query("UPDATE multi_aukcio_tetelek SET eladva = 1 WHERE id = $itemId");
    }

    $nyertesSql = $conn->real_escape_string($nyertes);
    $tetelNev = trim((string)($item['nev'] ?? ''));
    $tetelNevSafe = html_entity_decode($tetelNev, ENT_QUOTES, 'UTF-8');
    $osszeg = number_format($osszegErtek, 0, ' ', ' ');
    $uzenetSzoveg = html_entity_decode('&#127942; Megnyerte: ', ENT_QUOTES, 'UTF-8') . $tetelNevSafe . ' - ' . number_format($osszegErtek, 0, ' ', ' ') . ' Ft';
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
        shobidKuldMultiNyertesEmail($conn, $termekId, $tetelNevSafe, $osszegErtek, $nyertes, $uzenetId);
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
        $tetelNevSafe
    );
    return true;
}

function rogzitsLezartLicitNyertest($conn, $termek) {
    $tipus = (string)($termek['ajanlat_tipus'] ?? 'licit');
    if (!$termek || ($tipus !== '' && $tipus !== 'licit')) {
        return;
    }
    $termekId = intval($termek['id'] ?? 0);
    $nyertes = trim((string)($termek['legmagasabb_licit_felhasznalo'] ?? ''));
    $aktualisAr = intval($termek['aktualis_ar'] ?? 0);
    if (($nyertes === '' || $aktualisAr <= 0) && tablaLetezik($conn, 'licit_naplo')) {
        $licitIdoSql = 'ln.id';
        foreach (['letrehozva', 'created_at', 'datum', 'licit_ideje'] as $oszlop) {
            if (oszlopLetezik($conn, 'licit_naplo', $oszlop)) {
                $licitIdoSql = "ln.$oszlop";
                break;
            }
        }

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

    $teljesBrutto = shobidPayoutBruttoWithShipping(
        $aktualisAr,
        (string)($termek['szallitasi_mod'] ?? ''),
        intval($termek['szallitasi_dij'] ?? 0)
    );

    $chargeKey = shobidPenzugyiAzonosito('licit', $termekId, $nyertes, 0);
    $chargeRes = shobidStripeAutoChargeByNickname(
        $conn,
        $chargeKey,
        $nyertes,
        $termekId,
        $teljesBrutto,
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
    $uzenetSzoveg = html_entity_decode('&#127942; Megnyerte: ', ENT_QUOTES, 'UTF-8') . number_format($teljesBrutto, 0, ' ', ' ') . " Ft";
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
        $teljesBrutto,
        $uzenetId,
        ''
    );
}

function mentLicitNaplo($conn, $termekId, $felhasznaloId, $licitOsszeg, $multiTetelId = null) {
    if (!tablaLetezik($conn, 'licit_naplo')) {
        return;
    }

    static $licitNaploIdoOszlopEllenorizve = false;
    if (!$licitNaploIdoOszlopEllenorizve) {
        $licitNaploIdoOszlopEllenorizve = true;
        $vanIdoOszlop = false;
        foreach (['letrehozva', 'created_at', 'datum', 'licit_ideje'] as $idoOszlop) {
            if (oszlopLetezik($conn, 'licit_naplo', $idoOszlop)) {
                $vanIdoOszlop = true;
                break;
            }
        }
        if (!$vanIdoOszlop) {
            // Legacy table fallback: ensure a timestamp column exists for new bids.
            $conn->query("ALTER TABLE licit_naplo ADD COLUMN created_at DATETIME NULL");
        }
    }

    $oszlopok = ['termek_id', 'felhasznalo_id'];
    $ertekSqlok = [strval(intval($termekId)), strval(intval($felhasznaloId))];

    foreach (['licit_osszeg', 'osszeg', 'ar', 'licit_ar'] as $osszegOszlop) {
        if (oszlopLetezik($conn, 'licit_naplo', $osszegOszlop)) {
            $oszlopok[] = $osszegOszlop;
            $ertekSqlok[] = strval(intval($licitOsszeg));
            break;
        }
    }

    foreach (['letrehozva', 'created_at', 'datum', 'licit_ideje'] as $idoOszlop) {
        if (oszlopLetezik($conn, 'licit_naplo', $idoOszlop)) {
            $oszlopok[] = $idoOszlop;
            $ertekSqlok[] = 'NOW()';
            break;
        }
    }

    if ($multiTetelId !== null) {
        foreach (['multi_tetel_id', 'tetel_id', 'multi_item_id', 'resz_tetel_id'] as $tetelOszlop) {
            if (oszlopLetezik($conn, 'licit_naplo', $tetelOszlop)) {
                $oszlopok[] = $tetelOszlop;
                $ertekSqlok[] = strval(intval($multiTetelId));
                break;
            }
        }
    }

    $oszlopSql = implode(', ', $oszlopok);
    $ertekSql = implode(', ', $ertekSqlok);
    $conn->query("INSERT INTO licit_naplo ($oszlopSql) VALUES ($ertekSql)");
}

function aktualisNezokSzama($conn, $termekId) {
    if (!tablaLetezik($conn, 'aukcio_nezok')) {
        return 0;
    }

    $termekId = intval($termekId);
    $conn->query("DELETE FROM aukcio_nezok WHERE utolso_aktivitas < (NOW() - INTERVAL 45 SECOND)");
    $res = $conn->query("SELECT COUNT(*) AS db FROM aukcio_nezok WHERE termek_id = $termekId");
    $row = $res ? $res->fetch_assoc() : null;
    return intval($row['db'] ?? 0);
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
        $manualStartRequired = false;
        $manualStartEnabled = false;

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
                $manualStartRequired = true;
                $manualStartEnabled = true;
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
                    $manualStartRequired = true;
                    $manualStartEnabled = true;
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
            'manual_start_required' => $manualStartRequired,
            'manual_start_enabled' => $manualStartEnabled
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
            $phase = 'soon';
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
        'manual_start_required' => false,
        'manual_start_enabled' => false
    ];
}

function feldolgozLezartMultiTetelek($conn, $termek) {
    if (!$termek || !tablaLetezik($conn, 'multi_aukciok') || !tablaLetezik($conn, 'multi_aukcio_tetelek')) {
        return;
    }

    $termekId = intval($termek['id'] ?? 0);
    if ($termekId < 1) {
        return;
    }

    $multiRes = $conn->query("SELECT * FROM multi_aukciok WHERE termek_id = $termekId LIMIT 1");
    $multi = $multiRes ? $multiRes->fetch_assoc() : null;
    if (!$multi) {
        return;
    }

    $items = [];
    $itemsRes = $conn->query("SELECT * FROM multi_aukcio_tetelek WHERE multi_aukcio_id = " . intval($multi['id']) . " ORDER BY sorszam ASC, id ASC");
    while ($itemsRes && ($row = $itemsRes->fetch_assoc())) {
        $items[] = $row;
    }

    if (!$items) {
        return;
    }

    $mostTs = time();

    if (multiAktivTetelIndexOszlopVan($conn) && multiAktivTetelStartOszlopVan($conn)) {
        $kezdesTs = !empty($termek['kezdes_idopont']) ? strtotime((string)$termek['kezdes_idopont']) : 0;
        if ($kezdesTs > $mostTs) {
            return;
        }

        $currentIndex = max(0, intval($multi['aktiv_tetel_index'] ?? 0));
        if ($currentIndex >= count($items)) {
            $conn->query("UPDATE termekek SET eladva = 1 WHERE id = $termekId");
            return;
        }

        $startTs = !empty($multi['aktiv_tetel_start']) ? strtotime((string)$multi['aktiv_tetel_start']) : null;
        if (!$startTs) {
            if ($currentIndex > 0) {
                rogzitsMultiTetelNyertest($conn, $termekId, $items[$currentIndex - 1]);
            }
            syncMultiTermekAktualisTetel($conn, $termekId, $items[$currentIndex], false);
            return;
        }

        $item = $items[$currentIndex];
        $itemId = intval($item['id'] ?? 0);
        $itemEnd = $startTs + max(1, intval($item['idotartam_mp'] ?? 60));
        if ($itemEnd > $mostTs) {
            syncMultiTermekAktualisTetel($conn, $termekId, $item, false);
            return;
        }

        if ($itemId > 0 && intval($item['eladva'] ?? 0) !== 1) {
            $lezarasOk = rogzitsMultiTetelNyertest($conn, $termekId, $item);
            if (!$lezarasOk) {
                return;
            }
        }

        $nextIndex = $currentIndex + 1;
        if ($nextIndex >= count($items)) {
            $conn->query("UPDATE multi_aukciok SET aktiv_tetel_index = $nextIndex, aktiv_tetel_start = NULL WHERE id = " . intval($multi['id']));
            $conn->query("UPDATE termekek SET eladva = 1, lejarat_idopont = NOW() WHERE id = $termekId");
        } else {
            $conn->query("UPDATE multi_aukciok SET aktiv_tetel_index = $nextIndex, aktiv_tetel_start = NULL WHERE id = " . intval($multi['id']));
            syncMultiTermekAktualisTetel($conn, $termekId, $items[$nextIndex], false);
        }
        return;
    }

    $gap = max(0, intval($multi['szunet_mp'] ?? 15));
    $kezdesTs = !empty($termek['kezdes_idopont']) ? strtotime($termek['kezdes_idopont']) : time();
    $cursor = $kezdesTs;

    foreach ($items as $item) {
        $itemId = intval($item['id'] ?? 0);
        $itemEnd = $cursor + max(1, intval($item['idotartam_mp'] ?? 60));
        $marFeldolgozva = intval($item['eladva'] ?? 0) === 1;

        if ($itemId > 0 && !$marFeldolgozva && $mostTs >= $itemEnd) {
            rogzitsMultiTetelNyertest($conn, $termekId, $item);
        }

        $cursor = $itemEnd + $gap;
    }
}

$id = intval($_GET['id'] ?? 0);
$most = date("Y-m-d H:i:s");
$mostMs = (int) round(microtime(true) * 1000);
$aktivUserId = intval($_SESSION['user_id'] ?? 0);
$marketOverrideApi = strtolower(trim((string)($_GET['market'] ?? $_POST['market'] ?? '')));
$marketFallbackApi = function_exists('shobidMarketCurrentCode')
    ? shobidMarketCurrentCode()
    : (function_exists('shobidI18nCurrentLocale') ? shobidI18nCurrentLocale() : 'hu-hu');
$aktivPiacKodApi = function_exists('shobidMarketNormalizeCode')
    ? shobidMarketNormalizeCode($marketOverrideApi, $marketFallbackApi)
    : ($marketOverrideApi !== '' ? $marketOverrideApi : $marketFallbackApi);
$profilTeljes = false;
$viewerToken = session_id() ?: ($_SERVER['REMOTE_ADDR'] ?? 'guest');
$userPiacKodApi = '';
$orszagZarAktivApi = false;

if ($aktivUserId > 0) {
    $userPiacKodApi = function_exists('shobidMarketCodeForUser')
        ? shobidMarketCodeForUser($conn, $aktivUserId, $marketFallbackApi)
        : $aktivPiacKodApi;
    $orszagZarAktivApi = (strtolower((string)$userPiacKodApi) !== strtolower((string)$aktivPiacKodApi));

    shobidStripeEnsureFelhasznaloFizetesiOszlopok($conn);
    if (!oszlopLetezik($conn, 'felhasznalok', 'aszf_elfogadva')) {
        $conn->query("ALTER TABLE felhasznalok ADD aszf_elfogadva TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!oszlopLetezik($conn, 'felhasznalok', 'fizetesi_szabalyzat_elfogadva')) {
        $conn->query("ALTER TABLE felhasznalok ADD fizetesi_szabalyzat_elfogadva TINYINT(1) NOT NULL DEFAULT 0");
    }
    $userRes = $conn->query("SELECT teljes_nev, lakcim, telefonszam, aszf_elfogadva, fizetesi_szabalyzat_elfogadva FROM felhasznalok WHERE id = $aktivUserId");
    $apiUser = $userRes ? $userRes->fetch_assoc() : null;
    $profilTeljes = $apiUser
        && !empty(trim((string)($apiUser['teljes_nev'] ?? '')))
        && !empty(trim((string)($apiUser['lakcim'] ?? '')))
        && !empty(trim((string)($apiUser['telefonszam'] ?? '')))
        && !empty($apiUser['aszf_elfogadva'])
        && !empty($apiUser['fizetesi_szabalyzat_elfogadva'])
        && shobidStripeFelhasznaloFizetesKesz($conn, $aktivUserId);
}

$t_res = $conn->query("SELECT * FROM termekek WHERE id = $id AND " . shobidMarketTermekWhere($conn, '', $aktivPiacKodApi));
$termek = $t_res ? $t_res->fetch_assoc() : null;

if (!$termek) {
    apiJson(["error" => "Termek nem talalhato"]);
    exit;
}

feldolgozLezartMultiTetelek($conn, $termek);
$t_res = $conn->query("SELECT * FROM termekek WHERE id = $id AND " . shobidMarketTermekWhere($conn, '', $aktivPiacKodApi));
$termek = $t_res ? $t_res->fetch_assoc() : $termek;

if (tablaLetezik($conn, 'aukcio_nezok')) {
    $viewerTokenSql = $conn->real_escape_string($viewerToken);
    $userIdSql = $aktivUserId > 0 ? $aktivUserId : 'NULL';
    $conn->query("INSERT INTO aukcio_nezok (termek_id, felhasznalo_id, session_token, utolso_aktivitas)
        VALUES ($id, $userIdSql, '$viewerTokenSql', NOW())
        ON DUPLICATE KEY UPDATE utolso_aktivitas = NOW(), felhasznalo_id = VALUES(felhasznalo_id)");
}

$multiAukcio = getMultiAukcioAdat($conn, $termek);
$isMulti = !!$multiAukcio;
$ajanlatTipus = (string)($termek['ajanlat_tipus'] ?? 'licit');

$kezdesTs = !empty($termek['kezdes_idopont']) ? strtotime($termek['kezdes_idopont']) : null;
$lejaratTs = !empty($termek['lejarat_idopont']) ? strtotime($termek['lejarat_idopont']) : null;
$mostTs = time();
$mostMs = (int) floor(microtime(true) * 1000);
$most = date('Y-m-d H:i:s', $mostTs);
$allapot = "live";
$manualStartRequired = false;
$manualStartEnabled = false;

if ($isMulti) {
    $allapot = $multiAukcio['phase'] === 'closed' ? 'closed' : (($multiAukcio['phase'] === 'live' || $multiAukcio['phase'] === 'ready') ? 'live' : 'soon');
    $manualStartRequired = !!($multiAukcio['manual_start_required'] ?? false);
    $manualStartEnabled = !!($multiAukcio['manual_start_enabled'] ?? false);
    $kezdesTs = $allapot === 'soon' ? intval($multiAukcio['countdown_ts']) : $kezdesTs;
    $lejaratTs = ($multiAukcio['phase'] ?? '') === 'live' ? intval($multiAukcio['countdown_ts']) : null;
} else {
    if ($ajanlatTipus === 'licit') {
        $licitAllapot = getSingleLicitAllapot($conn, $termek);
        $allapot = $licitAllapot['allapot'];
        $kezdesTs = $licitAllapot['kezdes_ts'];
        $lejaratTs = $licitAllapot['lejarat_ts'];
        $manualStartRequired = !!$licitAllapot['manual_start_required'];
        $manualStartEnabled = !!$licitAllapot['manual_start_enabled'];
    } elseif ($kezdesTs && $kezdesTs > $mostTs) {
        $allapot = "soon";
    } elseif ($ajanlatTipus === 'fix' && intval($termek['darabszam'] ?? 0) <= 0) {
        $allapot = "closed";
    } elseif (($lejaratTs && $lejaratTs <= $mostTs) || intval($termek['eladva']) === 1) {
        $allapot = "closed";
    }
}

$sajatAukcio = ($aktivUserId > 0 && intval($termek['feltolto_id']) === $aktivUserId);
$manualStartEnabled = $manualStartEnabled && $sajatAukcio;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktivUserId > 0 && $orszagZarAktivApi) {
    apiJson([
        'ok' => false,
        'error' => 'Mas orszag piacterén csak nezni tudsz. Minden muvelet a sajat orszagod piacterén engedelyezett.'
    ]);
    exit;
}

if (isset($_POST['licit_start'])) {
    if ($ajanlatTipus !== 'fix' && $sajatAukcio && $allapot !== 'closed') {
        if ($isMulti && multiAktivTetelIndexOszlopVan($conn) && multiAktivTetelStartOszlopVan($conn) && $manualStartRequired) {
            $multiId = intval($multiAukcio['multi']['id'] ?? 0);
            $inditandoTetel = getInditandoMultiTetel($multiAukcio);
            if ($multiId > 0 && $inditandoTetel) {
                $stmt = $conn->prepare("UPDATE multi_aukciok SET aktiv_tetel_start = NOW() WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $multiId);
                    $stmt->execute();
                    $stmt->close();
                }

                $tetelNev = html_entity_decode((string)($inditandoTetel['nev'] ?? ''), ENT_QUOTES, 'UTF-8');
                if ($tetelNev === '') {
                    $tetelNev = 'Kovetkezo tetel';
                }
                $startUzenet = html_entity_decode('&#128293; LICIT START: ', ENT_QUOTES, 'UTF-8') . $tetelNev;
                $feltoltoNev = aktualisFelhasznaloNev($conn, $aktivUserId);
                $msgStmt = $conn->prepare("INSERT INTO uzenetek (felhasznalo, szoveg, termek_id) VALUES (?, ?, ?)");
                if ($msgStmt) {
                    $msgStmt->bind_param('ssi', $feltoltoNev, $startUzenet, $id);
                    $msgStmt->execute();
                    $msgStmt->close();
                }
            }
        } elseif (!$isMulti && $ajanlatTipus === 'licit' && licitStartedOszlopVan($conn) && $manualStartRequired) {
            $duration = getLicitIdotartamMp($conn, $termek);
            $ujLejarat = date('Y-m-d H:i:s', time() + $duration);
            $stmt = $conn->prepare("UPDATE termekek SET licit_started_at = NOW(), lejarat_idopont = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $ujLejarat, $id);
                $stmt->execute();
                $stmt->close();
            }

            $feltoltoNev = aktualisFelhasznaloNev($conn, $aktivUserId);
            $startUzenet = html_entity_decode('&#128293; LICIT START', ENT_QUOTES, 'UTF-8');
            $msgStmt = $conn->prepare("INSERT INTO uzenetek (felhasznalo, szoveg, termek_id) VALUES (?, ?, ?)");
            if ($msgStmt) {
                $msgStmt->bind_param('ssi', $feltoltoNev, $startUzenet, $id);
                $msgStmt->execute();
                $msgStmt->close();
            }
        }
    }
}

if (isset($_POST['live_toggle'])) {
    $liveMuvelet = trim((string)($_POST['live_toggle'] ?? ''));
    $vanLiveUrl = liveUrlOszlopVan($conn) ? trim((string)($termek['live_url'] ?? '')) !== '' : false;
    if ($sajatAukcio && $vanLiveUrl && liveAktivOszlopVan($conn)) {
        $ujLiveAllapot = $liveMuvelet === 'start' ? 1 : 0;
        $stmt = $conn->prepare("UPDATE termekek SET live_active = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $ujLiveAllapot, $id);
            $stmt->execute();
            $stmt->close();
        }
        $termek['live_active'] = $ujLiveAllapot;
    }
}

if (isset($_POST['fix_vetel'])) {
    if ($aktivUserId > 0 && $orszagZarAktivApi) {
        apiJson([
            'ok' => false,
            'error' => 'Mas orszag piacterén csak nezni tudsz. Vasarlas, chat es licit csak a sajat orszagodban engedelyezett.'
        ]);
        exit;
    }
    if ($ajanlatTipus === 'fix' || $ajanlatTipus === 'kupon') {
        $darabszam = intval($termek['darabszam'] ?? 0);
        $sajatAukcio = $aktivUserId > 0 && intval($termek['feltolto_id']) === $aktivUserId;
        $aktualisNev = aktualisFelhasznaloNev($conn, $aktivUserId);
        if ($aktivUserId < 1) {
            apiJson(['ok' => false, 'error' => 'A vÄ‚Ë‡sÄ‚Ë‡rlÄ‚Ë‡shoz elÄąâ€bb be kell jelentkezned.']);
            exit;
        }
        if (!$profilTeljes) {
            apiJson(['ok' => false, 'error' => 'A vÄ‚Ë‡sÄ‚Ë‡rlÄ‚Ë‡shoz tÄ‚Â¶ltsd ki a profilodat Ä‚Â©s ments bankkÄ‚Ë‡rtyÄ‚Ë‡t a FizetÄ‚Â©si beÄ‚Ë‡llÄ‚Â­tÄ‚Ë‡sok rÄ‚Â©sznÄ‚Â©l.']);
            exit;
        }
        if ($sajatAukcio) {
            apiJson(['ok' => false, 'error' => 'A sajÄ‚Ë‡t ajÄ‚Ë‡nlatodat nem tudod megvÄ‚Ë‡sÄ‚Ë‡rolni.']);
            exit;
        }
        if ($darabszam < 1 || intval($termek['eladva']) === 1) {
            apiJson(['ok' => false, 'error' => 'Ez a termÄ‚Â©k mÄ‚Ë‡r nem elÄ‚Â©rhetÄąâ€.']);
            exit;
        }

        $fixClientAmount = intval($_POST['fix_client_amount'] ?? 0);
        if ($fixClientAmount > 0) {
            $termek['__client_min_amount'] = $fixClientAmount;
        }

        try {
            // 1) Instant charge on saved card.
            $directRes = shobidStripeFixDirectPayment($conn, $termek, $aktivUserId, $aktualisNev);
            if (!empty($directRes['ok'])) {
                apiJson([
                    'ok' => true,
                    'direct_paid' => true,
                    'uzenet_id' => intval($directRes['uzenet_id'] ?? 0)
                ]);
                exit;
            }

            // 2) Fallback: Stripe Checkout redirect (hosted payment page).
            $sessionRes = shobidStripeFixCheckoutSessionLetrehoz($conn, $termek, $aktivUserId, $aktualisNev);
            if (!empty($sessionRes['ok']) && !empty($sessionRes['checkout_url'])) {
                apiJson([
                    'ok' => true,
                    'checkout_url' => (string)$sessionRes['checkout_url']
                ]);
                exit;
            }

            $directErr = trim((string)($directRes['error'] ?? ''));
            $sessionErr = trim((string)($sessionRes['error'] ?? ''));
            $hiba = $sessionErr !== '' ? $sessionErr : ($directErr !== '' ? $directErr : 'A fizetes inditasa most nem sikerult.');
            apiJson([
                'ok' => false,
                'error' => $hiba
            ]);
            exit;
        } catch (Throwable $e) {
            apiJson([
                'ok' => false,
                'error' => 'Szerver hiba tortent a fizetes inditasakor: ' . $e->getMessage()
            ]);
            exit;
        }
    }
    apiJson(['ok' => false, 'error' => 'Ez a mÄąÂ±velet csak fix Ä‚Ë‡ras ajÄ‚Ë‡nlatnÄ‚Ë‡l Ä‚Â©rhetÄąâ€ el.']);
    exit;
}

if (isset($_POST['osszeg'])) {
    if ($aktivUserId > 0 && $orszagZarAktivApi) {
        apiJson([
            'ok' => false,
            'error' => 'Mas orszag piacterén csak nezni tudsz. Vasarlas, chat es licit csak a sajat orszagodban engedelyezett.'
        ]);
        exit;
    }
    $licit = intval($_POST['osszeg']);
    $nev = aktualisFelhasznaloNev($conn, $aktivUserId);

    if ($ajanlatTipus === 'fix' || $ajanlatTipus === 'kupon') {
        // Fix Ä‚Ë‡ras termÄ‚Â©knÄ‚Â©l nincs licit.
    } elseif ($isMulti) {
        $activeItem = $multiAukcio['active_item'] ?? null;
        if ($activeItem && !$manualStartRequired) {
            $min = intval($activeItem['aktualis_ar']) + intval($activeItem['licit_lepcso']);
            if ($aktivUserId > 0 && $profilTeljes && intval($termek['feltolto_id']) !== $aktivUserId && $licit >= $min) {
                $itemId = intval($activeItem['id']);
                $stmt = $conn->prepare("UPDATE multi_aukcio_tetelek
                    SET aktualis_ar = ?, legmagasabb_licit_felhasznalo = ?
                    WHERE id = ?
                      AND eladva = 0
                      AND (aktualis_ar + licit_lepcso) <= ?");
                if ($stmt) {
                    $stmt->bind_param('isii', $licit, $nev, $itemId, $licit);
                    $stmt->execute();
                    $frissitve = intval($stmt->affected_rows);
                    $stmt->close();
                } else {
                    $frissitve = 0;
                }
                if ($frissitve > 0) {
                    mentLicitNaplo($conn, $id, $aktivUserId, $licit, $itemId);
                    $licitFormatalt = number_format($licit, 0, ' ', ' ');
                    $tetelNev = trim((string)($activeItem['nev'] ?? ''));
                    if ($tetelNev !== '') {
                        $tetelNev = html_entity_decode($tetelNev, ENT_QUOTES, 'UTF-8');
                        $licitUzenet = html_entity_decode('&#128293; Licit&aacute;lt: ', ENT_QUOTES, 'UTF-8') . $tetelNev . ' - ' . $licitFormatalt . " Ft";
                    } else {
                        $licitUzenet = html_entity_decode('&#128293; Licit&aacute;lt: ', ENT_QUOTES, 'UTF-8') . $licitFormatalt . " Ft";
                    }
                    $msgStmt = $conn->prepare("INSERT INTO uzenetek (felhasznalo, szoveg, termek_id) VALUES (?, ?, ?)");
                    if ($msgStmt) {
                        $msgStmt->bind_param('ssi', $nev, $licitUzenet, $id);
                        $msgStmt->execute();
                        $msgStmt->close();
                    }
                }
            }
        }
    } else {
        $min = intval($termek['aktualis_ar']) + intval($termek['licit_lepcso']);
        if (!$manualStartRequired && $aktivUserId > 0 && $profilTeljes && intval($termek['feltolto_id']) !== $aktivUserId && $licit >= $min) {
            $stmt = $conn->prepare("UPDATE termekek
                SET aktualis_ar = ?, legmagasabb_licit_felhasznalo = ?
                WHERE id = ?
                  AND eladva = 0
                  AND (aktualis_ar + licit_lepcso) <= ?");
            if ($stmt) {
                $stmt->bind_param('isii', $licit, $nev, $id, $licit);
                $stmt->execute();
                $frissitve = intval($stmt->affected_rows);
                $stmt->close();
            } else {
                $frissitve = 0;
            }
            if ($frissitve > 0) {
                mentLicitNaplo($conn, $id, $aktivUserId, $licit);
                $licitFormatalt = number_format($licit, 0, ' ', ' ');
                $licitUzenet = html_entity_decode('&#128293; Licit&aacute;lt: ', ENT_QUOTES, 'UTF-8') . $licitFormatalt . " Ft";
                $msgStmt = $conn->prepare("INSERT INTO uzenetek (felhasznalo, szoveg, termek_id) VALUES (?, ?, ?)");
                if ($msgStmt) {
                    $msgStmt->bind_param('ssi', $nev, $licitUzenet, $id);
                    $msgStmt->execute();
                    $msgStmt->close();
                }
            }
        }
    }
}

if (isset($_POST['uzenet'])) {
    if ($aktivUserId > 0 && $orszagZarAktivApi) {
        apiJson([
            'ok' => false,
            'error' => 'Mas orszag piacterén csak nezni tudsz. Vasarlas, chat es licit csak a sajat orszagodban engedelyezett.'
        ]);
        exit;
    }
    $nev = aktualisFelhasznaloNev($conn, $aktivUserId);
    $msg = trim((string)($_POST['uzenet'] ?? ''));
    if ($aktivUserId > 0 && $profilTeljes && $msg !== '') {
        $stmt = $conn->prepare("INSERT INTO uzenetek (felhasznalo, szoveg, termek_id) VALUES (?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('ssi', $nev, $msg, $id);
            $stmt->execute();
            $stmt->close();
        }
    }
}

$t_res = $conn->query("SELECT * FROM termekek WHERE id = $id AND " . shobidMarketTermekWhere($conn, '', $aktivPiacKodApi));
$termek = $t_res ? $t_res->fetch_assoc() : $termek;
$multiAukcio = getMultiAukcioAdat($conn, $termek);
$isMulti = !!$multiAukcio;

$displayItem = $isMulti ? ($multiAukcio['display_item'] ?? null) : null;
$activeItem = $isMulti ? ($multiAukcio['active_item'] ?? null) : null;
$nextItem = $isMulti ? ($multiAukcio['next_item'] ?? null) : null;

$kezdesTs = !empty($termek['kezdes_idopont']) ? strtotime($termek['kezdes_idopont']) : null;
$lejaratTs = !empty($termek['lejarat_idopont']) ? strtotime($termek['lejarat_idopont']) : null;
$mostTs = time();
$mostMs = (int) floor(microtime(true) * 1000);
$most = date('Y-m-d H:i:s', $mostTs);
$allapot = "live";
$manualStartRequired = false;
$manualStartEnabled = false;

if ($isMulti) {
    $allapot = $multiAukcio['phase'] === 'closed' ? 'closed' : (($multiAukcio['phase'] === 'live' || $multiAukcio['phase'] === 'ready') ? 'live' : 'soon');
    $manualStartRequired = !!($multiAukcio['manual_start_required'] ?? false);
    $manualStartEnabled = !!($multiAukcio['manual_start_enabled'] ?? false);
    $kezdesTs = $allapot === 'soon' ? intval($multiAukcio['countdown_ts']) : $kezdesTs;
    $lejaratTs = ($multiAukcio['phase'] ?? '') === 'live' ? intval($multiAukcio['countdown_ts']) : null;
} else {
    if ($ajanlatTipus === 'licit') {
        $licitAllapot = getSingleLicitAllapot($conn, $termek);
        $allapot = $licitAllapot['allapot'];
        $kezdesTs = $licitAllapot['kezdes_ts'];
        $lejaratTs = $licitAllapot['lejarat_ts'];
        $manualStartRequired = !!$licitAllapot['manual_start_required'];
        $manualStartEnabled = !!$licitAllapot['manual_start_enabled'];
    } elseif ($kezdesTs && $kezdesTs > $mostTs) {
        $allapot = "soon";
    } elseif ($ajanlatTipus === 'fix' && intval($termek['darabszam'] ?? 0) <= 0) {
        $allapot = "closed";
    } elseif (($lejaratTs && $lejaratTs <= $mostTs) || intval($termek['eladva']) === 1) {
        $allapot = "closed";
    }
}

if (!$isMulti && $ajanlatTipus === 'licit' && $allapot === 'closed') {
    rogzitsLezartLicitNyertest($conn, $termek);
}

if ($ajanlatTipus !== 'fix') {
    biztositsLicitLathatosag($conn, $termek, $isMulti, $displayItem);
}

$cres = $conn->query("SELECT * FROM uzenetek WHERE termek_id = $id ORDER BY id ASC");
$chat = [];
while ($cres && ($c = $cres->fetch_assoc())) {
    $chat[] = $c;
}

$aktualisAr = $isMulti ? intval($displayItem['aktualis_ar'] ?? 0) : intval($termek['aktualis_ar']);
$licitLepcso = $isMulti ? intval($displayItem['licit_lepcso'] ?? 0) : intval($termek['licit_lepcso']);
$licitaloNev = $isMulti ? (string)($displayItem['legmagasabb_licit_felhasznalo'] ?? '') : (string)($termek['legmagasabb_licit_felhasznalo'] ?? '');
$leiras = $isMulti ? (string)($displayItem['leiras'] ?? '') : (string)($termek['leiras'] ?? '');
$szallitasiMod = $isMulti ? (string)($displayItem['szallitasi_mod'] ?? '') : (string)($termek['szallitasi_mod'] ?? '');
$szallitasiDij = $isMulti ? intval($displayItem['szallitasi_dij'] ?? 0) : intval($termek['szallitasi_dij'] ?? 0);
$kategoriaNev = $isMulti ? (string)($displayItem['kat_nev'] ?? '') : '';
$nezok = aktualisNezokSzama($conn, $id);
$fixVasarlasDb = 0;
if ($ajanlatTipus === 'fix' || $ajanlatTipus === 'kupon') {
    $fixVasarlasRes = $conn->query("SELECT COUNT(*) AS db FROM uzenetek WHERE termek_id = $id AND szoveg LIKE '%Megvette:%'");
    $fixVasarlasRow = $fixVasarlasRes ? $fixVasarlasRes->fetch_assoc() : null;
    $fixVasarlasDb = intval($fixVasarlasRow['db'] ?? 0);
}

$liveUrl = liveUrlOszlopVan($conn) ? trim((string)($termek['live_url'] ?? '')) : '';
$liveActive = liveAktivOszlopVan($conn) ? intval($termek['live_active'] ?? 0) === 1 : false;
$videoUrl = trim((string)($termek['video_url'] ?? ''));
$mediaUrl = ($liveActive && $liveUrl !== '') ? $liveUrl : $videoUrl;
$ownerCanToggleLive = $sajatAukcio && $liveUrl !== '';
$kuponBevaltasVegeRaw = trim((string)($termek['kupon_bevaltas_vege'] ?? ''));
$kuponBevaltasVegeText = '';
if ($kuponBevaltasVegeRaw !== '') {
    $kuponTs = strtotime($kuponBevaltasVegeRaw);
    $kuponBevaltasVegeText = $kuponTs ? date('Y.m.d.', $kuponTs) : $kuponBevaltasVegeRaw;
}

apiJson([
    "id" => $id,
    "nev" => $termek['nev'],
    "ar" => number_format($aktualisAr, 0, ' ', ' '),
    "aktualis_ar_raw" => $aktualisAr,
    "ajanlat_tipus" => $ajanlatTipus,
    "fix_ar" => intval($termek['fix_ar'] ?? 0),
    "eredeti_ar" => intval($termek['eredeti_ar'] ?? 0),
    "varos" => trim((string)($termek['varos'] ?? '')),
    "darabszam" => intval($termek['darabszam'] ?? 0),
    "darabszam_osszes" => intval($termek['darabszam'] ?? 0) + $fixVasarlasDb,
    "licitalo" => $licitaloNev,
    "licitalo_nev" => $licitaloNev ?: '',
    "sajat_aukcio" => $sajatAukcio,
    "profil_teljes" => $profilTeljes,
    "orszag_zar_aktiv" => $orszagZarAktivApi,
    "viewer_orszag_kod" => $userPiacKodApi !== '' ? $userPiacKodApi : null,
    "aktiv_piac_kod" => $aktivPiacKodApi,
    "manual_start_required" => $manualStartRequired,
    "manual_start_enabled" => ($manualStartEnabled && $sajatAukcio),
    "licit_lepcso" => $licitLepcso,
    "video_url" => $videoUrl,
    "live_url" => $liveUrl,
    "live_active" => $liveActive,
    "media_url" => $mediaUrl,
    "fallback_video_url" => $videoUrl,
    "owner_can_toggle_live" => $ownerCanToggleLive,
    "kep_url" => $termek['kep_url'],
    "allapot" => $allapot,
    "szerver_ido" => $most,
    "szerver_timestamp" => $mostTs,
    "szerver_timestamp_ms" => $mostMs,
    "kezdes_idopont" => $termek['kezdes_idopont'],
    "lejarat_idopont" => $termek['lejarat_idopont'],
    "kezdes_timestamp" => $kezdesTs,
    "lejarat_timestamp" => $lejaratTs,
    "chat" => $chat,
    "is_multi" => $isMulti,
    "multi_item_nev" => $isMulti ? (string)($displayItem['nev'] ?? '') : '',
    "multi_item_leiras" => $leiras,
    "multi_item_kategoria" => $kategoriaNev,
    "multi_next_item_nev" => $isMulti ? (string)($nextItem['nev'] ?? '') : '',
    "nezok_szama" => $nezok,
    "szallitasi_mod" => $szallitasiMod,
    "szallitasi_dij" => $szallitasiDij,
    "szallitasi_dij_formatalt" => number_format($szallitasiDij, 0, ' ', ' '),
    "kupon_bevaltas_vege" => $kuponBevaltasVegeRaw,
    "kupon_bevaltas_vege_text" => $kuponBevaltasVegeText,
    "leiras" => $leiras
]);




