<?php
include __DIR__ . '/db.php';
include __DIR__ . '/auth.php';
include_once __DIR__ . '/global/i18n.php';

$pageLocaleCode = function_exists('shobidI18nCurrentLocale') ? shobidI18nCurrentLocale() : 'hu-hu';
$pageHtmlLang = substr($pageLocaleCode, 0, 2);

function shobidEnsureFelhasznaloOrszagKodColumn($conn) {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $res = $conn->query("SHOW COLUMNS FROM felhasznalok LIKE 'orszag_kod'");
    if ($res && $res->num_rows > 0) {
        return;
    }
    $conn->query("ALTER TABLE felhasznalok ADD orszag_kod VARCHAR(10) NOT NULL DEFAULT 'hu-hu'");
}

function renderCustomCountrySelectAuth($name, $selectedCode, $orszagOpcioLista, $placeholder = 'Valassz orszagot') {
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
    <div class="custom-select custom-select--category custom-select--country" data-custom-select>
        <select id="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" name="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" required class="custom-select__native" data-custom-select-native>
            <option value=""><?php echo htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php foreach ($options as $orszagOption): ?>
            <option value="<?php echo htmlspecialchars((string)$orszagOption['code'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo (string)$orszagOption['code'] === $selectedCode ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string)$orszagOption['label'], ENT_QUOTES, 'UTF-8'); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="custom-select__trigger" data-custom-select-trigger aria-expanded="false">
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

if (isset($_SESSION['user_id'])) {
    header('Location: profil.php');
    exit;
}

shobidEnsureFelhasznaloOrszagKodColumn($conn);

$uzenet = '';
$orszagKodok = [];
$orszagOpcioLista = function_exists('shobidMarketList') ? shobidMarketList() : [];
if (empty($orszagOpcioLista)) {
    $orszagOpcioLista = [
        ['code' => 'hu-hu', 'name' => 'Hungary', 'label' => 'HU-HU', 'available' => true]
    ];
}
foreach ($orszagOpcioLista as $orszag) {
    if (isset($orszag['available']) && !$orszag['available']) {
        continue;
    }
    $code = strtolower(trim((string)($orszag['code'] ?? '')));
    if ($code === '') {
        continue;
    }
    $orszagKodok[$code] = true;
}
$kivalasztottOrszagKod = strtolower(trim((string)($_POST['orszag_kod'] ?? '')));
$kategoriak = [];
$kategoriaRes = $conn->query('SELECT * FROM kategoriak ORDER BY nev ASC');
if ($kategoriaRes) {
    while ($k = $kategoriaRes->fetch_assoc()) {
        $kategoriak[] = $k;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!authValidateCsrfFromRequest()) {
        $uzenet = st('auth.register.error.csrf', 'Biztonsagi token hiba. Frissitsd az oldalt, es probald ujra.', $pageLocaleCode);
    } else {
        $becenevRaw = trim((string)($_POST['becenev'] ?? ''));
        $emailRaw = trim((string)($_POST['email'] ?? ''));
        $kivalasztottOrszagKod = strtolower(trim((string)($_POST['orszag_kod'] ?? '')));
        $jelszo = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

        if ($becenevRaw === '' || $emailRaw === '') {
            $uzenet = st('auth.register.error.required', 'Add meg a becenevet es az email cimet.', $pageLocaleCode);
        } elseif ($kivalasztottOrszagKod === '') {
            $uzenet = st('auth.register.error.country_required', 'Valassz orszagot a listabol.', $pageLocaleCode);
        } elseif (!isset($orszagKodok[$kivalasztottOrszagKod])) {
            $uzenet = st('auth.register.error.country_invalid', 'Ervenytelen orszag. Csak a listaban szereplo orszagokbol lehet valasztani.', $pageLocaleCode);
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO felhasznalok (becenev, teljes_nev, email, jelszo, lakcim, telefonszam, profilkep, orszag_kod)
                 VALUES (?, ?, ?, ?, NULL, NULL, NULL, ?)'
            );
            if (!$stmt) {
                error_log('Register prepare failed: ' . $conn->error);
                $uzenet = st('auth.error.server', 'Ideiglenes szerverhiba. Probald ujra kesobb.', $pageLocaleCode);
            } else {
                $emptyFullName = '';
                $stmt->bind_param('sssss', $becenevRaw, $emptyFullName, $emailRaw, $jelszo, $kivalasztottOrszagKod);
                $ok = $stmt->execute();
                $stmtError = $stmt->errno;
                $stmt->close();

                if ($ok) {
                    $insertId = intval($conn->insert_id);
                    $userStmt = $conn->prepare('SELECT * FROM felhasznalok WHERE id = ? LIMIT 1');
                    if ($userStmt) {
                        $userStmt->bind_param('i', $insertId);
                        $userStmt->execute();
                        $res = $userStmt->get_result();
                        $ujUser = $res ? $res->fetch_assoc() : null;
                        $userStmt->close();
                    } else {
                        $ujUser = null;
                    }

                    if (!$ujUser) {
                        $ujUser = ['id' => $insertId, 'becenev' => $becenevRaw];
                    }

                    setcookie('shobid_market', $kivalasztottOrszagKod, time() + (86400 * 365), '/');
                    authLoginUser($conn, $ujUser);
                    header('Location: profil.php?complete_profile=1');
                    exit;
                }

                if ($stmtError === 1062) {
                    $uzenet = st('auth.register.error.email_taken', 'Hiba: ez az email cim mar foglalt.', $pageLocaleCode);
                } else {
                    error_log('Register execute failed: ' . $conn->error);
                    $uzenet = st('auth.error.server', 'Ideiglenes szerverhiba. Probald ujra kesobb.', $pageLocaleCode);
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($pageHtmlLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(st('auth.register.title', 'Regisztracio - SHOBID', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body class="auction-auth-page auction-auth-page--standalone">
<main class="auth-standalone-shell">
    <a class="auth-standalone-logo" href="index.php">
        <img src="/kepek/web_sb_logo.webp" alt="SHOBID">
    </a>

    <section class="auth-standalone-card">
        <section class="auth-hero auth-hero--standalone">
            <div>
                <div class="profile-kicker">SHOBID</div>
                <h1><?php echo htmlspecialchars(st('auth.register.heading', 'Regisztracio', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></h1>
                <p><?php echo htmlspecialchars(st('auth.register.subheading', 'Gyors regisztracio becenevvel es email cimmel. A tobbi adatot belepes utan tudod megadni.', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </section>

        <?php if ($uzenet): ?>
        <div class="profile-alert auth-alert"><?php echo htmlspecialchars($uzenet, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <section class="auth-grid auth-grid--single">
            <article class="auth-panel auth-panel--standalone">
                <div class="profile-panel__head">
                    <h2><?php echo htmlspecialchars(st('auth.register.panel_title', 'Uj fiok letrehozasa', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></h2>
                </div>

                <form method="POST" class="auth-formgrid">
                    <?php echo authCsrfInputHtml(); ?>
                    <div class="create-field">
                        <label for="becenev"><?php echo htmlspecialchars(st('auth.nickname_label', 'Becenev', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                        <input id="becenev" type="text" name="becenev" value="<?php echo htmlspecialchars((string)($_POST['becenev'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars(st('auth.register.nickname_placeholder', 'Ez fog latszani a chatben', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>

                    <div class="create-field">
                        <label for="email"><?php echo htmlspecialchars(st('auth.email_label', 'Email cim', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                        <input id="email" type="email" name="email" value="<?php echo htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="pelda@email.hu" required>
                    </div>

                    <div class="create-field">
                        <label for="orszag_kod"><?php echo htmlspecialchars(st('auth.register.country_label', 'Orszag', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                        <?php renderCustomCountrySelectAuth('orszag_kod', $kivalasztottOrszagKod, $orszagOpcioLista, st('auth.register.country_placeholder', 'Valassz orszagot', $pageLocaleCode)); ?>
                    </div>

                    <button class="profile-submit" type="submit"><?php echo htmlspecialchars(st('auth.register.submit', 'Regisztracio', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
                </form>

                <p class="auth-note"><?php echo htmlspecialchars(st('auth.register.has_account', 'Van mar fiokod?', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?> <a class="auth-link" href="bejelentezes.php"><?php echo htmlspecialchars(st('auth.register.login_link', 'Lepj be itt', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></a></p>
            </article>
        </section>
    </section>
</main>
<script>
function initCustomSelectsAuth() {
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

document.addEventListener('DOMContentLoaded', function () {
    initCustomSelectsAuth();
});
</script>
</body>
</html>
