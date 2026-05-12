<?php

include_once __DIR__ . '/payout_helper.php';

function shobidEmailTablaVan($conn) {
    static $cache = null;
    if ($cache === null) {
        $res = $conn->query("SHOW TABLES LIKE 'email_ertesitesek'");
        $cache = $res && $res->num_rows > 0;
        if (!$cache) {
            $cache = (bool) $conn->query("CREATE TABLE IF NOT EXISTS email_ertesitesek (
                azonosito VARCHAR(191) NOT NULL,
                cimzett_email VARCHAR(255) NOT NULL,
                targy VARCHAR(255) NOT NULL,
                letrehozva TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (azonosito)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        }
    }
    return $cache;
}

function shobidPenzugyiStatuszTablaVan($conn) {
    static $cache = null;
    if ($cache === null) {
        $res = $conn->query("SHOW TABLES LIKE 'penzugyi_statuszok'");
        $cache = $res && $res->num_rows > 0;
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

function shobidPenzugyiAzonosito($tipus, $termekId = 0, $vasarloNev = '', $uzenetId = 0) {
    if ($tipus === 'fix') {
        return 'fix|uzenet|' . intval($uzenetId);
    }
    if ($tipus === 'kupon') {
        return 'kupon|uzenet|' . intval($uzenetId);
    }
    if ($tipus === 'multi') {
        return 'multi|uzenet|' . intval($uzenetId);
    }
    return 'licit|termek|' . intval($termekId) . '|vevo|' . trim((string)$vasarloNev);
}

function shobidBiztositPenzugyiStatuszt($conn, $azonosito, $statusz = 'Postázásra vár') {
    if ($azonosito === '' || !shobidPenzugyiStatuszTablaVan($conn)) {
        return;
    }
    $azonositoSql = $conn->real_escape_string($azonosito);
    $statuszSql = $conn->real_escape_string($statusz);
    $conn->query("INSERT INTO penzugyi_statuszok (azonosito, statusz) VALUES ('$azonositoSql', '$statuszSql')
        ON DUPLICATE KEY UPDATE statusz = statusz");
}

function shobidPenzugyiAdatStatuszAzonositoAlapjan($conn, $statuszAzonosito) {
    $statuszAzonosito = trim((string)$statuszAzonosito);
    if ($statuszAzonosito === '') {
        return null;
    }

    if (preg_match('/^fix\|uzenet\|(\d+)$/', $statuszAzonosito, $m)) {
        $uzenetId = intval($m[1] ?? 0);
        $res = $conn->query("SELECT t.*, u.id AS uzenet_id, u.felhasznalo AS vasarlo_becenev, u.szoveg
            FROM uzenetek u
            INNER JOIN termekek t ON t.id = u.termek_id
            WHERE u.id = $uzenetId
            LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            return null;
        }
        return [
            'tipus' => 'fix',
            'termek' => $row,
            'uzenet_id' => $uzenetId,
            'tetel_nev' => '',
            'osszeg' => intval($row['fix_ar'] ?? $row['aktualis_ar'] ?? 0),
            'vasarlo_becenev' => trim((string)($row['vasarlo_becenev'] ?? ''))
        ];
    }

    if (preg_match('/^multi\|uzenet\|(\d+)$/', $statuszAzonosito, $m)) {
        $uzenetId = intval($m[1] ?? 0);
        $res = $conn->query("SELECT t.*, u.id AS uzenet_id, u.felhasznalo AS vasarlo_becenev, u.szoveg
            FROM uzenetek u
            INNER JOIN termekek t ON t.id = u.termek_id
            WHERE u.id = $uzenetId
            LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            return null;
        }
        $tetelNev = '';
        $osszeg = 0;
        if (preg_match('/Megnyerte:\s*(.*?)\s*-\s*([0-9 ]+)\s*Ft/u', (string)($row['szoveg'] ?? ''), $talalat)) {
            $tetelNev = trim((string)($talalat[1] ?? ''));
            $osszeg = intval(str_replace(' ', '', (string)($talalat[2] ?? '0')));
        }
        return [
            'tipus' => 'multi',
            'termek' => $row,
            'uzenet_id' => $uzenetId,
            'tetel_nev' => $tetelNev,
            'osszeg' => $osszeg,
            'vasarlo_becenev' => trim((string)($row['vasarlo_becenev'] ?? ''))
        ];
    }

    if (preg_match('/^kupon\|uzenet\|(\d+)$/', $statuszAzonosito, $m)) {
        $uzenetId = intval($m[1] ?? 0);
        $res = $conn->query("SELECT t.*, u.id AS uzenet_id, u.felhasznalo AS vasarlo_becenev, u.szoveg
            FROM uzenetek u
            INNER JOIN termekek t ON t.id = u.termek_id
            WHERE u.id = $uzenetId
            LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            return null;
        }
        return [
            'tipus' => 'kupon',
            'termek' => $row,
            'uzenet_id' => $uzenetId,
            'tetel_nev' => '',
            'osszeg' => intval($row['fix_ar'] ?? $row['aktualis_ar'] ?? 0),
            'vasarlo_becenev' => trim((string)($row['vasarlo_becenev'] ?? ''))
        ];
    }

    if (preg_match('/^licit\|termek\|(\d+)\|vevo\|(.*)$/', $statuszAzonosito, $m)) {
        $termekId = intval($m[1] ?? 0);
        $vasarloBecenev = trim((string)($m[2] ?? ''));
        $res = $conn->query("SELECT * FROM termekek WHERE id = $termekId LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            return null;
        }
        return [
            'tipus' => 'licit',
            'termek' => $row,
            'uzenet_id' => 0,
            'tetel_nev' => '',
            'osszeg' => intval($row['aktualis_ar'] ?? 0),
            'vasarlo_becenev' => $vasarloBecenev
        ];
    }

    return null;
}

function shobidEmailMarKiment($conn, $azonosito) {
    if ($azonosito === '' || !shobidEmailTablaVan($conn)) {
        return false;
    }
    $azonositoSql = $conn->real_escape_string($azonosito);
    $res = $conn->query("SELECT azonosito FROM email_ertesitesek WHERE azonosito = '$azonositoSql' LIMIT 1");
    return $res && $res->num_rows > 0;
}

function shobidJelolEmailKikuldve($conn, $azonosito, $email, $targy) {
    if ($azonosito === '' || !shobidEmailTablaVan($conn)) {
        return;
    }
    $azonositoSql = $conn->real_escape_string($azonosito);
    $emailSql = $conn->real_escape_string($email);
    $targySql = $conn->real_escape_string($targy);
    $conn->query("INSERT INTO email_ertesitesek (azonosito, cimzett_email, targy) VALUES ('$azonositoSql', '$emailSql', '$targySql')
        ON DUPLICATE KEY UPDATE cimzett_email = VALUES(cimzett_email), targy = VALUES(targy)");
}

function shobidMimeHeader($text) {
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

function shobidSmtpReadLine($socket) {
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }
    return $response;
}

function shobidSmtpExpect($socket, array $codes) {
    $response = shobidSmtpReadLine($socket);
    $code = intval(substr(trim($response), 0, 3));
    return in_array($code, $codes, true) ? $response : false;
}

function shobidSmtpSend($socket, $command, array $codes) {
    fwrite($socket, $command . "\r\n");
    return shobidSmtpExpect($socket, $codes);
}

function shobidSmtpKuldes($toEmail, $toName, $subject, $body) {
    $host = 'mail.nethely.hu';
    $username = 'smtp@shobid.com';
    $password = 'Kistarcsa2';
    $fromEmail = 'smtp@shobid.com';
    $fromName = 'SHOBID';
    $ports = [
        ['transport' => 'tcp', 'port' => 587, 'starttls' => true],
        ['transport' => 'ssl', 'port' => 465, 'starttls' => false],
    ];

    $message = "From: " . shobidMimeHeader($fromName) . " <{$fromEmail}>\r\n";
    $message .= "To: " . shobidMimeHeader($toName !== '' ? $toName : $toEmail) . " <{$toEmail}>\r\n";
    $message .= "Subject: " . shobidMimeHeader($subject) . "\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n";
    $message .= "\r\n";
    $message .= str_replace(["\r\n", "\r"], "\n", $body);
    $message = str_replace("\n", "\r\n", $message);

    foreach ($ports as $cfg) {
        $target = ($cfg['transport'] === 'ssl' ? 'ssl://' : '') . $host . ':' . $cfg['port'];
        $socket = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            continue;
        }

        stream_set_timeout($socket, 20);
        if (!shobidSmtpExpect($socket, [220])) {
            fclose($socket);
            continue;
        }
        if (!shobidSmtpSend($socket, 'EHLO shobid.com', [250])) {
            fclose($socket);
            continue;
        }
        if ($cfg['starttls']) {
            if (!shobidSmtpSend($socket, 'STARTTLS', [220])) {
                fclose($socket);
                continue;
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                continue;
            }
            if (!shobidSmtpSend($socket, 'EHLO shobid.com', [250])) {
                fclose($socket);
                continue;
            }
        }
        if (!shobidSmtpSend($socket, 'AUTH LOGIN', [334])) {
            fclose($socket);
            continue;
        }
        if (!shobidSmtpSend($socket, base64_encode($username), [334])) {
            fclose($socket);
            continue;
        }
        if (!shobidSmtpSend($socket, base64_encode($password), [235])) {
            fclose($socket);
            continue;
        }
        if (!shobidSmtpSend($socket, 'MAIL FROM:<' . $fromEmail . '>', [250])) {
            fclose($socket);
            continue;
        }
        if (!shobidSmtpSend($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251])) {
            fclose($socket);
            continue;
        }
        if (!shobidSmtpSend($socket, 'DATA', [354])) {
            fclose($socket);
            continue;
        }
        fwrite($socket, $message . "\r\n.\r\n");
        if (!shobidSmtpExpect($socket, [250])) {
            fclose($socket);
            continue;
        }
        @shobidSmtpSend($socket, 'QUIT', [221]);
        fclose($socket);
        return true;
    }

    return false;
}

function shobidFelhasznaloById($conn, $id) {
    $id = intval($id);
    if ($id < 1) {
        return null;
    }
    $res = $conn->query("SELECT * FROM felhasznalok WHERE id = $id LIMIT 1");
    return $res ? $res->fetch_assoc() : null;
}

function shobidFelhasznaloByBecenev($conn, $becenev) {
    $becenev = trim((string)$becenev);
    if ($becenev === '') {
        return null;
    }
    $becenevSql = $conn->real_escape_string($becenev);
    $res = $conn->query("SELECT * FROM felhasznalok WHERE becenev = '$becenevSql' LIMIT 1");
    return $res ? $res->fetch_assoc() : null;
}

function shobidFelhasznaloDisplayNev($user) {
    if (!$user) {
        return '';
    }
    $teljes = trim((string)($user['teljes_nev'] ?? ''));
    $becenev = trim((string)($user['becenev'] ?? ''));
    return $teljes !== '' ? $teljes : $becenev;
}

function shobidSzallitasiCimSor($user) {
    if (!$user) {
        return '';
    }
    $parts = array_filter([
        trim((string)($user['szallitasi_iranyitoszam'] ?? '')) . ' ' . trim((string)($user['szallitasi_varos'] ?? '')),
        trim((string)($user['szallitasi_utca'] ?? '')),
        trim((string)($user['szallitasi_hazszam'] ?? '')),
        trim((string)($user['szallitasi_emelet_ajto'] ?? ''))
    ], function ($v) {
        return trim((string)$v) !== '';
    });
    return trim(implode(', ', $parts));
}

function shobidCegesAdatBlokk($user) {
    if (!$user || intval($user['cegkent_vasarolok'] ?? 0) !== 1) {
        return '';
    }
    $cegnev = trim((string)($user['cegnev'] ?? ''));
    $adoszam = trim((string)($user['adoszam'] ?? ''));
    $szekhely = trim(implode(', ', array_filter([
        trim((string)($user['ceges_iranyitoszam'] ?? '')) . ' ' . trim((string)($user['ceges_varos'] ?? '')),
        trim((string)($user['ceges_utca'] ?? '')),
        trim((string)($user['ceges_hazszam'] ?? ''))
    ], function ($v) {
        return trim((string)$v) !== '';
    })));

    if ($cegnev === '' || $adoszam === '' || $szekhely === '') {
        return '';
    }

    return "Ceges adatok:\n"
        . "Cegnev: {$cegnev}\n"
        . "Szekhely: {$szekhely}\n"
        . "Adoszam: {$adoszam}\n";
}

function shobidKuldesEgyszer($conn, $azonosito, $email, $nev, $targy, $szoveg) {
    if ($azonosito === '' || trim((string)$email) === '') {
        return false;
    }
    if (shobidEmailMarKiment($conn, $azonosito)) {
        return true;
    }
    $ok = shobidSmtpKuldes($email, $nev, $targy, $szoveg);
    if ($ok) {
        shobidJelolEmailKikuldve($conn, $azonosito, $email, $targy);
    }
    return $ok;
}

function shobidKuldFixVasarlasEmail($conn, $termek, $buyerId, $uzenetId) {
    $buyer = shobidFelhasznaloById($conn, $buyerId);
    $seller = shobidFelhasznaloById($conn, intval($termek['feltolto_id'] ?? 0));
    if (!$buyer || !$seller) {
        return;
    }
    $statuszAzonosito = shobidPenzugyiAzonosito('fix', intval($termek['id'] ?? 0), trim((string)($buyer['becenev'] ?? '')), intval($uzenetId));
    shobidBiztositPenzugyiStatuszt($conn, $statuszAzonosito, 'Postázásra vár');

    $osszeg = shobidPayoutBruttoWithShipping(
        intval($termek['fix_ar'] ?? $termek['aktualis_ar'] ?? 0),
        (string)($termek['szallitasi_mod'] ?? ''),
        intval($termek['szallitasi_dij'] ?? 0)
    );
    $termekNev = trim((string)($termek['nev'] ?? ''));
    $buyerName = shobidFelhasznaloDisplayNev($buyer);
    $sellerName = shobidFelhasznaloDisplayNev($seller);
    $szallitasiNev = trim((string)($buyer['szallitasi_nev'] ?? ''));
    $szallitasiCim = shobidSzallitasiCimSor($buyer);
    $cegesBlokk = shobidCegesAdatBlokk($buyer);

    $buyerSubject = 'SHOBID vasarlas visszaigazolas';
    $buyerBody = "Koszonjuk a vasarlast!\n\n"
        . "Termek: {$termekNev}\n"
        . "Elado: {$sellerName}\n"
        . "Ar: " . number_format($osszeg, 0, ' ', ' ') . " Ft\n";
    shobidKuldesEgyszer($conn, 'statusz|' . $statuszAzonosito . '|buyer', trim((string)$buyer['email']), $buyerName, $buyerSubject, $buyerBody);

    $sellerSubject = 'SHOBID uj vasarlas';
    $sellerBody = "Uj vasarlas tortent.\n\n"
        . "Termek: {$termekNev}\n"
        . "Vasarlo: {$buyerName}\n"
        . "Ar: " . number_format($osszeg, 0, ' ', ' ') . " Ft\n\n"
        . "Szallitasi nev: {$szallitasiNev}\n"
        . "Szallitasi cim: {$szallitasiCim}\n";
    if ($cegesBlokk !== '') {
        $sellerBody .= "\nA vasarlo cegkent vasarol / ker szamlat.\n\n" . $cegesBlokk;
    }
    shobidKuldesEgyszer($conn, 'statusz|' . $statuszAzonosito . '|seller', trim((string)$seller['email']), $sellerName, $sellerSubject, $sellerBody);
}

function shobidKuldMultiNyertesEmail($conn, $termekId, $tetelNev, $osszeg, $nyertesNev, $uzenetId) {
    $termekRes = $conn->query("SELECT * FROM termekek WHERE id = " . intval($termekId) . " LIMIT 1");
    $termek = $termekRes ? $termekRes->fetch_assoc() : null;
    if (!$termek) {
        return;
    }
    $buyer = shobidFelhasznaloByBecenev($conn, $nyertesNev);
    $seller = shobidFelhasznaloById($conn, intval($termek['feltolto_id'] ?? 0));
    if (!$buyer || !$seller) {
        return;
    }
    $statuszAzonosito = shobidPenzugyiAzonosito('multi', intval($termek['id'] ?? 0), trim((string)($buyer['becenev'] ?? '')), intval($uzenetId));
    shobidBiztositPenzugyiStatuszt($conn, $statuszAzonosito, 'Postázásra vár');

    $termekNev = trim((string)($termek['nev'] ?? ''));
    $buyerName = shobidFelhasznaloDisplayNev($buyer);
    $sellerName = shobidFelhasznaloDisplayNev($seller);
    $szallitasiNev = trim((string)($buyer['szallitasi_nev'] ?? ''));
    $szallitasiCim = shobidSzallitasiCimSor($buyer);
    $cegesBlokk = shobidCegesAdatBlokk($buyer);
    $teljesNev = $termekNev . ' | ' . $tetelNev;

    $buyerSubject = 'SHOBID nyertes licit shop tetel';
    $buyerBody = "Sikeres vasarlas / nyeres!\n\n"
        . "Termek: {$teljesNev}\n"
        . "Elado: {$sellerName}\n"
        . "Ar: " . number_format($osszeg, 0, ' ', ' ') . " Ft\n";
    shobidKuldesEgyszer($conn, 'statusz|' . $statuszAzonosito . '|buyer', trim((string)$buyer['email']), $buyerName, $buyerSubject, $buyerBody);

    $sellerSubject = 'SHOBID nyertes licit shop tetel';
    $sellerBody = "Lezart licit shop tetel.\n\n"
        . "Termek: {$teljesNev}\n"
        . "Vasarlo: {$buyerName}\n"
        . "Ar: " . number_format($osszeg, 0, ' ', ' ') . " Ft\n\n"
        . "Szallitasi nev: {$szallitasiNev}\n"
        . "Szallitasi cim: {$szallitasiCim}\n";
    if ($cegesBlokk !== '') {
        $sellerBody .= "\nA vasarlo cegkent vasarol / ker szamlat.\n\n" . $cegesBlokk;
    }
    shobidKuldesEgyszer($conn, 'statusz|' . $statuszAzonosito . '|seller', trim((string)$seller['email']), $sellerName, $sellerSubject, $sellerBody);
}

function shobidKuldLicitNyertesEmail($conn, $termekId, $nyertesNev) {
    $termekRes = $conn->query("SELECT * FROM termekek WHERE id = " . intval($termekId) . " LIMIT 1");
    $termek = $termekRes ? $termekRes->fetch_assoc() : null;
    if (!$termek) {
        return;
    }
    $buyer = shobidFelhasznaloByBecenev($conn, $nyertesNev);
    $seller = shobidFelhasznaloById($conn, intval($termek['feltolto_id'] ?? 0));
    if (!$buyer || !$seller) {
        return;
    }
    $statuszAzonosito = shobidPenzugyiAzonosito('licit', intval($termek['id'] ?? 0), trim((string)($buyer['becenev'] ?? '')), 0);
    shobidBiztositPenzugyiStatuszt($conn, $statuszAzonosito, 'Postázásra vár');

    $nyertesSql = $conn->real_escape_string($nyertesNev);
    $osszeg = shobidPayoutBruttoWithShipping(
        intval($termek['aktualis_ar'] ?? 0),
        (string)($termek['szallitasi_mod'] ?? ''),
        intval($termek['szallitasi_dij'] ?? 0)
    );
    $termekNev = trim((string)($termek['nev'] ?? ''));
    $uzenetSzoveg = $conn->real_escape_string(html_entity_decode('&#127942; Megnyerte: ', ENT_QUOTES, 'UTF-8') . number_format($osszeg, 0, ' ', ' ') . " Ft");
    $uzenetRes = $conn->query("SELECT id FROM uzenetek WHERE termek_id = " . intval($termekId) . " AND felhasznalo = '$nyertesSql' AND szoveg = '$uzenetSzoveg' LIMIT 1");
    $uzenet = $uzenetRes ? $uzenetRes->fetch_assoc() : null;
    $uzenetId = intval($uzenet['id'] ?? 0);
    if ($uzenetId < 1) {
        return;
    }

    $buyerName = shobidFelhasznaloDisplayNev($buyer);
    $sellerName = shobidFelhasznaloDisplayNev($seller);
    $szallitasiNev = trim((string)($buyer['szallitasi_nev'] ?? ''));
    $szallitasiCim = shobidSzallitasiCimSor($buyer);
    $cegesBlokk = shobidCegesAdatBlokk($buyer);

    $buyerSubject = 'SHOBID megnyert licit';
    $buyerBody = "Sikeresen megnyerted a licitet.\n\n"
        . "Termek: {$termekNev}\n"
        . "Elado: {$sellerName}\n"
        . "Ar: " . number_format($osszeg, 0, ' ', ' ') . " Ft\n";
    shobidKuldesEgyszer($conn, 'statusz|' . $statuszAzonosito . '|buyer', trim((string)$buyer['email']), $buyerName, $buyerSubject, $buyerBody);

    $sellerSubject = 'SHOBID nyertes licit';
    $sellerBody = "Lezart licit.\n\n"
        . "Termek: {$termekNev}\n"
        . "Vasarlo: {$buyerName}\n"
        . "Ar: " . number_format($osszeg, 0, ' ', ' ') . " Ft\n\n"
        . "Szallitasi nev: {$szallitasiNev}\n"
        . "Szallitasi cim: {$szallitasiCim}\n";
    if ($cegesBlokk !== '') {
        $sellerBody .= "\nA vasarlo cegkent vasarol / ker szamlat.\n\n" . $cegesBlokk;
    }
    shobidKuldesEgyszer($conn, 'statusz|' . $statuszAzonosito . '|seller', trim((string)$seller['email']), $sellerName, $sellerSubject, $sellerBody);
}

function shobidKuldKuponVasarlasEmail($conn, $termek, $buyerId, $uzenetId, $kuponKod) {
    $buyer = shobidFelhasznaloById($conn, $buyerId);
    $seller = shobidFelhasznaloById($conn, intval($termek['feltolto_id'] ?? 0));
    if (!$buyer || !$seller) {
        return;
    }

    $statuszAzonosito = shobidPenzugyiAzonosito('kupon', intval($termek['id'] ?? 0), trim((string)($buyer['becenev'] ?? '')), intval($uzenetId));
    shobidBiztositPenzugyiStatuszt($conn, $statuszAzonosito, 'Beváltható');

    $osszeg = intval($termek['fix_ar'] ?? $termek['aktualis_ar'] ?? 0);
    $termekNev = trim((string)($termek['nev'] ?? 'Kupon'));
    $buyerName = shobidFelhasznaloDisplayNev($buyer);
    $sellerName = shobidFelhasznaloDisplayNev($seller);
    $kod = trim((string)$kuponKod);
    $qrUrl = '';
    if ($kod !== '') {
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' . rawurlencode($kod);
    }

    $buyerSubject = 'SHOBID kupon vásárlás visszaigazolás';
    $buyerBody = "Köszönjük a kupon vásárlást!\n\n"
        . "Kupon: {$termekNev}\n"
        . "Eladó: {$sellerName}\n"
        . "Ár: " . number_format($osszeg, 0, ' ', ' ') . " Ft\n"
        . "Kupon ID: {$kod}\n\n"
        . "Ezt az azonosítót mutasd be a beváltáskor.";
    if ($qrUrl !== '') {
        $buyerBody .= "\n\nQR kod:\n{$qrUrl}";
    }
    shobidKuldesEgyszer($conn, 'kuponvasarlas|' . $statuszAzonosito . '|buyer', trim((string)$buyer['email']), $buyerName, $buyerSubject, $buyerBody);

    $sellerSubject = 'SHOBID új kupon vásárlás';
    $sellerBody = "Új kupon vásárlás történt.\n\n"
        . "Kupon: {$termekNev}\n"
        . "Vásárló: {$buyerName}\n"
        . "Ár: " . number_format($osszeg, 0, ' ', ' ') . " Ft\n"
        . "Kupon ID: {$kod}\n";
    if ($qrUrl !== '') {
        $sellerBody .= "\nQR kod:\n{$qrUrl}\n";
    }
    shobidKuldesEgyszer($conn, 'kuponvasarlas|' . $statuszAzonosito . '|seller', trim((string)$seller['email']), $sellerName, $sellerSubject, $sellerBody);
}

function shobidKuldPostazvaEmail($conn, $statuszAzonosito, $trackingKod = '') {
    $adat = shobidPenzugyiAdatStatuszAzonositoAlapjan($conn, $statuszAzonosito);
    if (!$adat) {
        return false;
    }

    $termek = $adat['termek'] ?? null;
    $buyer = shobidFelhasznaloByBecenev($conn, (string)($adat['vasarlo_becenev'] ?? ''));
    $seller = shobidFelhasznaloById($conn, intval($termek['feltolto_id'] ?? 0));
    if (!$termek || !$buyer || !$seller) {
        return false;
    }

    $buyerName = shobidFelhasznaloDisplayNev($buyer);
    $sellerName = shobidFelhasznaloDisplayNev($seller);
    $termekNev = trim((string)($termek['nev'] ?? ''));
    if (($adat['tipus'] ?? '') === 'multi' && trim((string)($adat['tetel_nev'] ?? '')) !== '') {
        $termekNev .= ' | ' . trim((string)$adat['tetel_nev']);
    }

    $subject = 'SHOBID csomag postázva';
    $body = "A csomagod postázva lett.\n\n"
        . "Termek: {$termekNev}\n"
        . "Elado: {$sellerName}\n"
        . "Ar: " . number_format(intval($adat['osszeg'] ?? 0), 0, ' ', ' ') . " Ft\n";
    $trackingKod = trim((string)$trackingKod);
    if ($trackingKod !== '') {
        $body .= "Tracking kod: {$trackingKod}\n";
    }

    return shobidKuldesEgyszer(
        $conn,
        'shipping|' . $statuszAzonosito . '|buyer',
        trim((string)$buyer['email']),
        $buyerName,
        $subject,
        $body
    );
}
