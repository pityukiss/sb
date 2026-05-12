<?php
include_once __DIR__ . '/config.php';

function authSessionCookieSecure() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return (intval($_SERVER['SERVER_PORT'] ?? 0) === 443);
}

$authSessionLifetime = 60 * 60 * 24 * 30;
ini_set('session.gc_maxlifetime', (string)$authSessionLifetime);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => $authSessionLifetime,
        'path' => '/',
        'secure' => authSessionCookieSecure(),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

function authOszlopLetezik($conn, $tablaNev, $oszlopNev) {
    $tablaNev = $conn->real_escape_string($tablaNev);
    $oszlopNev = $conn->real_escape_string($oszlopNev);
    $res = $conn->query("SHOW COLUMNS FROM `$tablaNev` LIKE '$oszlopNev'");
    return $res && $res->num_rows > 0;
}

function authRememberColumnsReady($conn) {
    return authOszlopLetezik($conn, 'felhasznalok', 'remember_token_hash')
        && authOszlopLetezik($conn, 'felhasznalok', 'remember_token_expires_at');
}

function authRememberCookieName() {
    return 'shobid_remember';
}

function authCookieSecure() {
    return authSessionCookieSecure();
}

function authSetRememberCookie($token) {
    setcookie(authRememberCookieName(), $token, [
        'expires' => time() + (60 * 60 * 24 * 30),
        'path' => '/',
        'secure' => authCookieSecure(),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    $_COOKIE[authRememberCookieName()] = $token;
}

function authClearRememberCookie() {
    setcookie(authRememberCookieName(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => authCookieSecure(),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    unset($_COOKIE[authRememberCookieName()]);
}

function authSetSessionUser($user) {
    $_SESSION['user_id'] = intval($user['id'] ?? 0);
    $_SESSION['becenev'] = (string)($user['becenev'] ?? '');
}

function authCsrfToken() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function authCsrfInputHtml() {
    $token = htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function authValidateCsrfFromRequest() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    if ($sessionToken === '') {
        return false;
    }

    $requestToken = trim((string)($_POST['csrf_token'] ?? ''));
    if ($requestToken === '') {
        $requestToken = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    }
    if ($requestToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $requestToken);
}

function authAdminPasswordHash() {
    return trim((string) shobidConfig('ADMIN_PASSWORD_HASH', ''));
}

function authAdminPasswordPlain() {
    return trim((string) shobidConfig('ADMIN_PASSWORD', ''));
}

function authAdminConfigReady() {
    return authAdminPasswordHash() !== '' || authAdminPasswordPlain() !== '';
}

function authAdminThrottleState() {
    $state = $_SESSION['admin_login_throttle'] ?? null;
    if (!is_array($state)) {
        $state = ['attempts' => 0, 'lock_until' => 0];
    }
    $state['attempts'] = intval($state['attempts'] ?? 0);
    $state['lock_until'] = intval($state['lock_until'] ?? 0);
    return $state;
}

function authAdminCanAttempt() {
    $state = authAdminThrottleState();
    return $state['lock_until'] <= time();
}

function authAdminSecondsUntilAllowed() {
    $state = authAdminThrottleState();
    return max(0, $state['lock_until'] - time());
}

function authAdminRegisterAttempt($successful) {
    if ($successful) {
        $_SESSION['admin_login_throttle'] = ['attempts' => 0, 'lock_until' => 0];
        return;
    }

    $state = authAdminThrottleState();
    $attempts = min(10, intval($state['attempts']) + 1);
    $waitSeconds = min(900, (int) pow(2, max(0, $attempts - 1)));
    $_SESSION['admin_login_throttle'] = [
        'attempts' => $attempts,
        'lock_until' => time() + $waitSeconds
    ];
}

function authAdminVerifyPassword($plainPassword) {
    $plainPassword = (string)$plainPassword;
    $hash = authAdminPasswordHash();
    $fallbackPlain = authAdminPasswordPlain();
    if ($plainPassword === '') {
        return false;
    }
    if ($hash !== '') {
        return password_verify($plainPassword, $hash);
    }
    if ($fallbackPlain === '') {
        return false;
    }
    return hash_equals($fallbackPlain, $plainPassword);
}

function authIssueRememberToken($conn, $userId) {
    if (!authRememberColumnsReady($conn) || $userId < 1) {
        return;
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $tokenHashSql = $conn->real_escape_string($tokenHash);
    $expiresAt = date('Y-m-d H:i:s', time() + (60 * 60 * 24 * 30));
    $conn->query("UPDATE felhasznalok SET remember_token_hash = '$tokenHashSql', remember_token_expires_at = '$expiresAt' WHERE id = " . intval($userId));
    authSetRememberCookie($token);
}

function authLoginUser($conn, $user) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    authSetSessionUser($user);
    authIssueRememberToken($conn, intval($user['id'] ?? 0));
}

function authLogoutUser($conn) {
    $userId = intval($_SESSION['user_id'] ?? 0);
    if ($userId > 0 && authRememberColumnsReady($conn)) {
        $conn->query("UPDATE felhasznalok SET remember_token_hash = NULL, remember_token_expires_at = NULL WHERE id = $userId");
    } elseif (authRememberColumnsReady($conn) && !empty($_COOKIE[authRememberCookieName()])) {
        $tokenHashSql = $conn->real_escape_string(hash('sha256', (string)$_COOKIE[authRememberCookieName()]));
        $conn->query("UPDATE felhasznalok SET remember_token_hash = NULL, remember_token_expires_at = NULL WHERE remember_token_hash = '$tokenHashSql'");
    }

    authClearRememberCookie();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function authBootstrap($conn) {
    if (!empty($_SESSION['user_id'])) {
        return;
    }

    if (!authRememberColumnsReady($conn)) {
        return;
    }

    $cookieToken = trim((string)($_COOKIE[authRememberCookieName()] ?? ''));
    if ($cookieToken === '') {
        return;
    }

    $tokenHashSql = $conn->real_escape_string(hash('sha256', $cookieToken));
    $res = $conn->query("SELECT * FROM felhasznalok WHERE remember_token_hash = '$tokenHashSql' AND remember_token_expires_at IS NOT NULL AND remember_token_expires_at > NOW() LIMIT 1");
    $user = $res ? $res->fetch_assoc() : null;

    if (!$user) {
        authClearRememberCookie();
        return;
    }

    authSetSessionUser($user);
    authIssueRememberToken($conn, intval($user['id'] ?? 0));
}

authBootstrap($conn);
