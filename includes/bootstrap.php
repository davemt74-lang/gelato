<?php
declare(strict_types=1);

const RESTAURANT_APP_ROOT = __DIR__ . '/..';

function app_config_path(): string
{
    return RESTAURANT_APP_ROOT . '/config.php';
}

function app_has_config(): bool
{
    return is_file(app_config_path());
}

function app_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    if (!app_has_config()) {
        throw new RuntimeException('config.php was not found. Rename config-example.php to config.php and enter your database settings.');
    }

    $loaded = require app_config_path();
    if (!is_array($loaded)) {
        throw new RuntimeException('config.php must return a PHP array.');
    }

    $config = $loaded;
    return $config;
}

function app_boot_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $config = app_config();
    $security = $config['security'] ?? [];
    $name = (string)($security['session_name'] ?? 'restaurant_workspace_session');
    $lifetime = max(900, (int)($security['session_lifetime_seconds'] ?? 28800));
    $secure = (bool)($security['cookie_secure'] ?? false);
    $sameSite = (string)($security['cookie_samesite'] ?? 'Lax');

    session_name($name);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => in_array($sameSite, ['Lax', 'Strict', 'None'], true) ? $sameSite : 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

function app_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $database = app_config()['database'] ?? [];
    $dsn = trim((string)($database['dsn'] ?? ''));
    if ($dsn === '') {
        $host = (string)($database['host'] ?? '127.0.0.1');
        $port = (int)($database['port'] ?? 3306);
        $name = (string)($database['name'] ?? '');
        $charset = (string)($database['charset'] ?? 'utf8mb4');
        if ($name === '') {
            throw new RuntimeException('The database name is missing from config.php.');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
    }

    $pdo = new PDO(
        $dsn,
        (string)($database['username'] ?? ''),
        (string)($database['password'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]
    );
    return $pdo;
}

function app_escape(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path = ''): string
{
    $base = rtrim((string)(app_config()['app']['url'] ?? ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function app_redirect(string $path): never
{
    header('Location: ' . $path, true, 302);
    exit;
}

function app_csrf_token(): string
{
    app_boot_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function app_verify_csrf(?string $token): bool
{
    app_boot_session();
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], $token);
}

function app_schema_is_installed(PDO $pdo): bool
{
    try {
        $statement = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('organizations','users','roles','permissions')");
        return (int)$statement->fetchColumn() === 4;
    } catch (Throwable) {
        return false;
    }
}

function app_owner_exists(PDO $pdo): bool
{
    if (!app_schema_is_installed($pdo)) {
        return false;
    }
    $sql = "SELECT COUNT(*)
            FROM users u
            INNER JOIN organization_memberships om ON om.user_id = u.id
            INNER JOIN user_roles ur ON ur.membership_id = om.id AND ur.revoked_at IS NULL
            INNER JOIN roles r ON r.id = ur.role_id AND r.is_owner_role = 1
            WHERE u.status = 'active' AND u.archived_at IS NULL";
    return (int)$pdo->query($sql)->fetchColumn() > 0;
}

function app_current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;

    app_boot_session();
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $organizationId = isset($_SESSION['organization_id']) ? (int)$_SESSION['organization_id'] : 0;
    if ($userId < 1 || $organizationId < 1) {
        return null;
    }

    $sql = "SELECT u.id, u.email, u.first_name, u.last_name, u.display_name, u.phone,
                   u.status, u.last_login_at, u.created_at,
                   om.id AS membership_id, om.organization_id, om.job_title,
                   o.name AS organization_name,
                   r.id AS role_id, r.name AS role_name, r.slug AS role_slug,
                   r.description AS role_description, r.is_system_role, r.is_owner_role
            FROM users u
            INNER JOIN organization_memberships om ON om.user_id = u.id AND om.organization_id = :organization_id
            INNER JOIN organizations o ON o.id = om.organization_id
            INNER JOIN user_roles ur ON ur.membership_id = om.id AND ur.revoked_at IS NULL
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE u.id = :user_id AND u.status = 'active' AND u.archived_at IS NULL
            ORDER BY r.is_owner_role DESC, r.id ASC
            LIMIT 1";
    $statement = app_pdo()->prepare($sql);
    $statement->execute(['organization_id' => $organizationId, 'user_id' => $userId]);
    $row = $statement->fetch();
    if (!$row) {
        unset($_SESSION['user_id'], $_SESSION['organization_id']);
        return null;
    }

    $permissionStatement = app_pdo()->prepare(
        "SELECT DISTINCT p.permission_key
         FROM organization_memberships om
         INNER JOIN user_roles ur ON ur.membership_id = om.id AND ur.revoked_at IS NULL
         INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
         INNER JOIN permissions p ON p.id = rp.permission_id
         WHERE om.user_id = :user_id AND om.organization_id = :organization_id"
    );
    $permissionStatement->execute(['user_id' => $userId, 'organization_id' => $organizationId]);
    $permissions = $permissionStatement->fetchAll(PDO::FETCH_COLUMN);
    $row['permissions'] = (int)$row['is_owner_role'] === 1 ? ['*'] : array_values($permissions);
    $user = $row;
    return $user;
}

function app_require_auth(): array
{
    $user = app_current_user();
    if (!$user) {
        $return = basename((string)($_SERVER['REQUEST_URI'] ?? 'index.php'));
        app_redirect('login.php?return=' . rawurlencode($return));
    }
    return $user;
}

function app_has_permission(string $permission, ?array $user = null): bool
{
    $user ??= app_current_user();
    if (!$user) {
        return false;
    }
    return in_array('*', $user['permissions'], true) || in_array($permission, $user['permissions'], true);
}

function app_activity_notification_copy(string $action, string $entityType, ?string $entityId, ?array $new = null): array
{
    $map = [
        'login.success' => ['Account sign-in', 'A user signed in to the training workspace.', 'index.php#dashboard'],
        'logout.success' => ['Account sign-out', 'A user signed out of the training workspace.', 'index.php#dashboard'],
        'password.reset_requested' => ['Password reset requested', 'A password-reset request was submitted.', 'index.php#notifications'],
        'password.reset_completed' => ['Password updated', 'An account password was reset successfully.', 'index.php#profile'],
        'user.created' => ['User account created', 'A new restaurant training account was created.', 'index.php#users'],
        'user.updated' => ['User account updated', 'A user profile or access setting was changed.', 'index.php#users'],
        'user.suspended' => ['User access suspended', 'A restaurant training account was suspended.', 'index.php#users'],
        'role.created' => ['Account type created', 'A new account type and permission set was created.', 'index.php#roles'],
        'role.updated' => ['Account permissions updated', 'An account type permission set was changed.', 'index.php#roles'],
        'resume.created' => ['New resume submitted', 'A new public resume entered the hiring queue.', 'index.php#resumes'],
        'resume.status_changed' => ['Resume status updated', 'An applicant moved to a new hiring status.', 'index.php#resumes'],
        'resume.note_added' => ['Resume note added', 'A private reviewer note was added to an applicant.', 'index.php#resumes'],
        'job.created' => ['Job opening created', 'A new restaurant job opening was created.', 'index.php#jobs'],
        'job.updated' => ['Job opening updated', 'A restaurant job opening or publishing status changed.', 'index.php#jobs'],
        'job.status_changed' => ['Job status updated', 'A restaurant job opening was published, paused, or archived.', 'index.php#jobs'],
        'brand.updated' => ['Brand settings updated', 'Restaurant identity, logo, cover image, or public-page styling changed.', 'index.php#brand'],
        'llm_key.updated' => ['LLM API key updated', 'An encrypted organization LLM credential was added or replaced.', 'index.php#llm'],
        'llm_key.removed' => ['LLM API key removed', 'An organization LLM credential was removed.', 'index.php#llm'],
        'form.published' => ['Resume form published', 'A new public resume-form version was published.', 'index.php#forms'],
        'public_page.published' => ['Landing page published', 'The public recruitment landing page was updated.', 'index.php#landing-builder'],
        'certification.issued' => ['Certification issued', 'A restaurant position certification was issued.', 'index.php#certifications'],
        'training.assigned' => ['Training assigned', 'A new training requirement was assigned.', 'index.php#dashboard'],
        'quiz.completed' => ['Quiz completed', 'A learning quiz result was recorded.', 'index.php#dashboard'],
        'daily_training.completed' => ['Daily training completed', 'A daily training session was completed.', 'index.php#daily'],
        'flashcard_session.completed' => ['Flashcard session completed', 'A menu flashcard study session was completed.', 'index.php#flashcards'],
        'kitchen_verification.completed' => ['Kitchen verification completed', 'A kitchen-ticket verification result was recorded.', 'index.php#kitchen'],
        'profile.updated' => ['Profile updated', 'Personal account settings were changed.', 'index.php#profile'],
    ];
    if (isset($map[$action])) {
        return [$action, ...$map[$action]];
    }
    $humanAction = ucfirst(str_replace(['.', '_'], ' ', $action));
    return [$action ?: 'activity', $humanAction, 'Recent account or training activity was recorded.', 'index.php#notifications'];
}

function app_create_activity_notifications(PDO $pdo, int $organizationId, ?int $actorUserId, string $action, string $entityType, ?string $entityId = null, ?array $new = null): void
{
    try {
        [$type, $title, $message, $actionUrl] = app_activity_notification_copy($action, $entityType, $entityId, $new);
        $actorName = 'A user';
        if ($actorUserId) {
            $actorStatement = $pdo->prepare('SELECT display_name FROM users WHERE id = ? LIMIT 1');
            $actorStatement->execute([$actorUserId]);
            $actorName = (string)($actorStatement->fetchColumn() ?: 'A user');
        }

        $recipients = [];
        if ($actorUserId) {
            $recipients[$actorUserId] = ['self' => true];
        }
        $adminStatement = $pdo->prepare(
            "SELECT DISTINCT u.id
             FROM users u
             INNER JOIN organization_memberships om ON om.user_id = u.id AND om.organization_id = :organization_id
             INNER JOIN user_roles ur ON ur.membership_id = om.id AND ur.revoked_at IS NULL
             INNER JOIN roles r ON r.id = ur.role_id
             LEFT JOIN role_permissions rp ON rp.role_id = r.id
             LEFT JOIN permissions p ON p.id = rp.permission_id
             WHERE u.status = 'active' AND u.archived_at IS NULL
               AND (r.is_owner_role = 1 OR p.permission_key = 'audit.view')"
        );
        $adminStatement->execute(['organization_id' => $organizationId]);
        foreach ($adminStatement->fetchAll(PDO::FETCH_COLUMN) as $recipientId) {
            $recipients[(int)$recipientId] = ['self' => (int)$recipientId === $actorUserId];
        }
        if (!$recipients) {
            return;
        }

        $insert = $pdo->prepare(
            "INSERT INTO notifications
             (organization_id, user_id, notification_type, title, message, action_url)
             VALUES (:organization_id, :user_id, :notification_type, :title, :message, :action_url)"
        );
        foreach ($recipients as $recipientId => $meta) {
            $recipientMessage = $meta['self'] ? $message : $actorName . ': ' . $message;
            $insert->execute([
                'organization_id' => $organizationId,
                'user_id' => $recipientId,
                'notification_type' => mb_substr($type, 0, 100),
                'title' => mb_substr($title, 0, 220),
                'message' => $recipientMessage,
                'action_url' => $actionUrl,
            ]);
        }
    } catch (Throwable) {
        // Notification delivery must never make the primary audited action fail.
    }
}

function app_audit(PDO $pdo, int $organizationId, ?int $actorUserId, string $action, string $entityType, ?string $entityId = null, ?array $previous = null, ?array $new = null): void
{
    $statement = $pdo->prepare(
        "INSERT INTO audit_log
        (organization_id, actor_user_id, action, entity_type, entity_id, previous_values_json, new_values_json, ip_address, user_agent)
        VALUES (:organization_id, :actor_user_id, :action, :entity_type, :entity_id, :previous_values_json, :new_values_json, INET6_ATON(:ip_address), :user_agent)"
    );
    $statement->execute([
        'organization_id' => $organizationId,
        'actor_user_id' => $actorUserId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'previous_values_json' => $previous ? json_encode($previous, JSON_THROW_ON_ERROR) : null,
        'new_values_json' => $new ? json_encode($new, JSON_THROW_ON_ERROR) : null,
        'ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
        'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
    ]);
    app_create_activity_notifications($pdo, $organizationId, $actorUserId, $action, $entityType, $entityId, $new);
}

function app_guest_account_menu(string $context = 'public'): string
{
    $class = $context === 'auth' ? 'auth-account-menu' : '';
    return '<div class="guest-profile-wrap ' . $class . '" data-public-account-menu>'
        . '<button class="guest-profile-button" type="button" data-guest-menu-button aria-haspopup="true" aria-expanded="false">'
        . '<span class="guest-avatar">G</span><span class="guest-profile-copy"><strong>Guest</strong><span>Not signed in</span></span><span class="guest-chevron">⌄</span></button>'
        . '<div class="guest-profile-menu hidden" data-guest-menu>'
        . '<div class="guest-menu-head"><span class="guest-avatar">G</span><div><strong>Logged-out account</strong><span>Sign in to access training</span></div></div>'
        . '<div class="guest-menu-list">'
        . '<a class="primary" href="login.php">↪ Sign in</a>'
        . '<a href="forgot-password.php">? Forgot password</a>'
        . '<div class="guest-menu-divider"></div>'
        . '<a href="landing.html">⌂ Public landing page</a>'
        . '<a href="apply.html">▤ Submit a resume</a>'
        . '</div></div></div>';
}

function app_password_hash(string $password): string
{
    $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $hash = password_hash($password, $algorithm);
    if (!is_string($hash)) {
        throw new RuntimeException('The password could not be secured.');
    }
    return $hash;
}

function app_password_is_valid(string $password): bool
{
    if (strlen($password) < 12 || strlen($password) > 200) {
        return false;
    }
    return preg_match('/[A-Z]/', $password) === 1
        && preg_match('/[a-z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1;
}

function app_send_password_reset_email(string $email, string $displayName, string $resetUrl): bool
{
    if (!function_exists('mail')) {
        return false;
    }
    $config = app_config();
    $appName = (string)($config['app']['name'] ?? 'Restaurant Training Workspace');
    $mailConfig = $config['mail'] ?? [];
    $fromName = str_replace(["\r", "\n"], '', trim((string)($mailConfig['from_name'] ?? $appName)));
    $host = (string)(parse_url((string)($config['app']['url'] ?? ''), PHP_URL_HOST) ?: 'localhost');
    $fromEmail = str_replace(["\r", "\n"], '', trim((string)($mailConfig['from_email'] ?? ('no-reply@' . $host))));
    $subject = $appName . ' password reset';
    $safeName = trim($displayName) !== '' ? $displayName : 'there';
    $body = "Hello {$safeName},\n\n"
        . "A password reset was requested for your {$appName} account.\n\n"
        . "Reset your password using this one-time link:\n{$resetUrl}\n\n"
        . "This link expires in 60 minutes. If you did not request this reset, you can ignore this message.\n";
    $headers = [
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];
    return @mail($email, $subject, $body, implode("\r\n", $headers));
}

function app_require_permission(string $permission): array
{
    $user = app_require_auth();
    if (!app_has_permission($permission, $user)) {
        if (str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/') || str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/')) {
            app_json_response(['ok' => false, 'message' => 'You do not have permission to perform this action.'], 403);
        }
        http_response_code(403);
        exit('You do not have permission to perform this action.');
    }
    return $user;
}

function app_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function app_json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        app_json_response(['ok' => false, 'message' => 'The request body is not valid JSON.'], 400);
    }
    return is_array($decoded) ? $decoded : [];
}

function app_verify_request_csrf(?array $input = null): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? $_POST['csrf_token'] ?? null);
    if (!app_verify_csrf(is_string($token) ? $token : null)) {
        app_json_response(['ok' => false, 'message' => 'The security token is invalid or expired. Refresh the page and try again.'], 419);
    }
}

function app_storage_path(string $relative = ''): string
{
    return RESTAURANT_APP_ROOT . '/storage/' . ltrim($relative, '/');
}

function app_secret_key(): string
{
    static $key = null;
    if (is_string($key)) {
        return $key;
    }
    if (!function_exists('sodium_crypto_secretbox')) {
        throw new RuntimeException('The PHP Sodium extension is required to store API keys securely.');
    }
    $directory = app_storage_path('keys');
    $path = $directory . '/application.key';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The application could not create storage/keys.');
    }
    if (!is_file($path)) {
        $generated = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        if (file_put_contents($path, base64_encode($generated), LOCK_EX) === false) {
            throw new RuntimeException('The application encryption key could not be created.');
        }
        @chmod($path, 0600);
    }
    $decoded = base64_decode(trim((string)file_get_contents($path)), true);
    if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('The application encryption key is invalid.');
    }
    $key = $decoded;
    return $key;
}

function app_encrypt_secret(string $plaintext): array
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, app_secret_key());
    sodium_memzero($plaintext);
    return [
        'ciphertext' => base64_encode($ciphertext),
        'nonce' => base64_encode($nonce),
        'method' => 'sodium_secretbox_v1',
    ];
}

function app_decrypt_secret(string $ciphertext, string $nonce, string $method): string
{
    if ($method !== 'sodium_secretbox_v1') {
        throw new RuntimeException('Unsupported credential encryption method.');
    }
    $cipherBytes = base64_decode($ciphertext, true);
    $nonceBytes = base64_decode($nonce, true);
    if (!is_string($cipherBytes) || !is_string($nonceBytes)) {
        throw new RuntimeException('Stored credential data is invalid.');
    }
    $plaintext = sodium_crypto_secretbox_open($cipherBytes, $nonceBytes, app_secret_key());
    if (!is_string($plaintext)) {
        throw new RuntimeException('The stored credential could not be decrypted.');
    }
    return $plaintext;
}

function app_public_asset_url(?string $storagePath): ?string
{
    if (!$storagePath) {
        return null;
    }
    return app_url(ltrim($storagePath, '/'));
}
