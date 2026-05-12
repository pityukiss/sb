<?php
include __DIR__ . '/db.php';
include __DIR__ . '/auth.php';
include_once __DIR__ . '/global/i18n.php';

$pageLocaleCode = function_exists('shobidI18nCurrentLocale') ? shobidI18nCurrentLocale() : 'hu-hu';
$pageHtmlLang = substr($pageLocaleCode, 0, 2);

if (isset($_SESSION['user_id'])) {
    header('Location: profil.php');
    exit;
}

$uzenet = '';
$kategoriak = [];
$kategoriaRes = $conn->query('SELECT * FROM kategoriak ORDER BY nev ASC');
if ($kategoriaRes) {
    while ($k = $kategoriaRes->fetch_assoc()) {
        $kategoriak[] = $k;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!authValidateCsrfFromRequest()) {
        $uzenet = st('auth.login.error.csrf', 'Biztonsagi token hiba. Frissitsd az oldalt, es probald ujra.', $pageLocaleCode);
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $jelszo = (string)($_POST['jelszo'] ?? '');

        $stmt = $conn->prepare('SELECT * FROM felhasznalok WHERE email = ? LIMIT 1');
        if (!$stmt) {
            error_log('Login prepare failed: ' . $conn->error);
            $uzenet = st('auth.error.server', 'Ideiglenes szerverhiba. Probald ujra kesobb.', $pageLocaleCode);
        } else {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $res = $stmt->get_result();
            $user = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if ($user) {
                if (password_verify($jelszo, (string)($user['jelszo'] ?? ''))) {
                    authLoginUser($conn, $user);
                    header('Location: index.php');
                    exit;
                }
                $uzenet = st('auth.login.error.password', 'Hibas jelszo.', $pageLocaleCode);
            } else {
                $uzenet = st('auth.login.error.email_not_found', 'Nincs ilyen email cimmel regisztralt felhasznalo.', $pageLocaleCode);
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
    <title><?php echo htmlspecialchars(st('auth.login.title', 'Bejelentkezes - SHOBID', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/style.css?v=20260318-1">
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
                <h1><?php echo htmlspecialchars(st('auth.login.heading', 'Bejelentkezes', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></h1>
                <p><?php echo htmlspecialchars(st('auth.login.subheading', 'Lepj be a fiokodba, hogy licitalni, chatelni es aukciot feltolteni tudj.', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </section>

        <?php if ($uzenet): ?>
        <div class="profile-alert auth-alert"><?php echo htmlspecialchars($uzenet, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <section class="auth-grid auth-grid--single">
            <article class="auth-panel auth-panel--standalone">
                <div class="profile-panel__head">
                    <h2><?php echo htmlspecialchars(st('auth.login.panel_title', 'Belepes a fiokba', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></h2>
                </div>

                <form method="POST" class="auth-formgrid">
                    <?php echo authCsrfInputHtml(); ?>
                    <div class="create-field">
                        <label for="email"><?php echo htmlspecialchars(st('auth.email_label', 'Email cim', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                        <input id="email" type="email" name="email" placeholder="pelda@email.hu" required>
                    </div>

                    <div class="create-field">
                        <label for="jelszo"><?php echo htmlspecialchars(st('auth.password_label', 'Jelszo', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></label>
                        <input id="jelszo" type="password" name="jelszo" placeholder="<?php echo htmlspecialchars(st('auth.password_placeholder', 'Add meg a jelszavad', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>

                    <button class="profile-submit" type="submit"><?php echo htmlspecialchars(st('auth.login.submit', 'Belepek', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
                </form>

                <p class="auth-note"><?php echo htmlspecialchars(st('auth.login.no_account', 'Nincs meg fiokod?', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?> <a class="auth-link" href="regisztracio.php"><?php echo htmlspecialchars(st('auth.login.register_link', 'Regisztralj itt', $pageLocaleCode), ENT_QUOTES, 'UTF-8'); ?></a></p>
            </article>
        </section>
    </section>
</main>
</body>
</html>
