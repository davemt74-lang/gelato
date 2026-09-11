<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (app_has_config()) {
    try {
        app_boot_session();
        $user = app_current_user();
        if ($user) {
            app_audit(app_pdo(), (int)$user['organization_id'], (int)$user['id'], 'authentication.logout', 'user', (string)$user['id']);
        }
    } catch (Throwable) {
    }
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
header('Location: login.php', true, 302);
exit;
