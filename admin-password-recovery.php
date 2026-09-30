<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

const ADMIN_RECOVERY_KEY_HASH = '44306c84e8a891787d8c08006616a9ac0f03123ad1449497dac6dc5ef77c9e01';

if (!app_has_config()) {
    http_response_code(503);
    exit('Application configuration is unavailable.');
}

$config = app_config();
date_default_timezone_set((string)($config['app']['timezone'] ?? 'UTC'));
app_boot_session();

try {
    $pdo = app_pdo();
    if (!app_schema_is_installed($pdo) || !app_owner_exists($pdo)) {
        throw new RuntimeException('The application is not fully installed.');
    }
} catch (Throwable) {
    http_response_code(503);
    exit('Password recovery is temporarily unavailable.');
}

$adminStatement = $pdo->query(
    "SELECT DISTINCT u.id, u.email, u.display_name, u.status, om.organization_id
     FROM users u
     INNER JOIN organization_memberships om
       ON om.user_id = u.id AND om.status = 'active'
     INNER JOIN user_roles ur
       ON ur.membership_id = om.id AND ur.revoked_at IS NULL
     INNER JOIN roles r
       ON r.id = ur.role_id
     WHERE u.archived_at IS NULL
       AND u.status = 'active'
       AND (r.is_owner_role = 1 OR r.slug = 'super_admin')
     ORDER BY r.is_owner_role DESC, u.id ASC
     LIMIT 1"
);
$admin = $adminStatement->fetch() ?: null;

$consumedStatement = $pdo->prepare(
    "SELECT id FROM password_reset_tokens WHERE token_hash = :token_hash LIMIT 1"
);
$consumedStatement->execute(['token_hash' => ADMIN_RECOVERY_KEY_HASH]);
$alreadyUsed = (bool)$consumedStatement->fetchColumn();

$error = null;
$success = false;
$key = (string)($_POST['recovery_key'] ?? '');
$keyValid = $key !== '' && hash_equals(ADMIN_RECOVERY_KEY_HASH, hash('sha256', $key));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset') {
    $password = (string)($_POST['password'] ?? '');
    $confirmation = (string)($_POST['password_confirmation'] ?? '');

    if ($alreadyUsed) {
        $error = 'This one-time recovery key has already been used.';
    } elseif (!$admin) {
        $error = 'No active Super Admin account was found.';
    } elseif (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'The form expired. Refresh the page and try again.';
    } elseif (!$keyValid) {
        $error = 'The recovery key is invalid.';
    } elseif ($password !== $confirmation) {
        $error = 'The passwords do not match.';
    } elseif (!app_password_is_valid($password)) {
        $error = 'Use at least 12 characters with an uppercase letter, lowercase letter, and number.';
    } else {
        try {
            $passwordHash = app_password_hash($password);
            $pdo->beginTransaction();

            $check = $pdo->prepare(
                "SELECT id FROM password_reset_tokens WHERE token_hash = :token_hash LIMIT 1 FOR UPDATE"
            );
            $check->execute(['token_hash' => ADMIN_RECOVERY_KEY_HASH]);
            if ($check->fetchColumn()) {
                throw new RuntimeException('This one-time recovery key has already been used.');
            }

            $pdo->prepare(
                "UPDATE users
                 SET password_hash = :password_hash,
                     password_changed_at = NOW(6),
                     failed_login_count = 0,
                     locked_until = NULL
                 WHERE id = :user_id"
            )->execute([
                'password_hash' => $passwordHash,
                'user_id' => (int)$admin['id'],
            ]);

            $pdo->prepare(
                "UPDATE password_reset_tokens
                 SET used_at = COALESCE(used_at, NOW(6))
                 WHERE user_id = :user_id AND used_at IS NULL"
            )->execute(['user_id' => (int)$admin['id']]);

            $pdo->prepare(
                "INSERT INTO password_reset_tokens
                    (user_id, token_hash, expires_at, used_at)
                 VALUES
                    (:user_id, :token_hash, '2099-12-31 23:59:59.000000', NOW(6))"
            )->execute([
                'user_id' => (int)$admin['id'],
                'token_hash' => ADMIN_RECOVERY_KEY_HASH,
            ]);

            app_audit(
                $pdo,
                (int)$admin['organization_id'],
                (int)$admin['id'],
                'authentication.emergency_admin_password_reset',
                'user',
                (string)$admin['id'],
                null,
                ['recovery' => 'one_time_repository_page']
            );

            $pdo->commit();
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $success = true;
            $alreadyUsed = true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage() === 'This one-time recovery key has already been used.'
                ? $exception->getMessage()
                : 'The password could not be updated.';
        }
    }
}

$brandName = (string)($config['app']['name'] ?? 'Restaurant Workspace');
try {
    $brandName = (string)($pdo->query(
        "SELECT restaurant_name FROM brand_settings ORDER BY id ASC LIMIT 1"
    )->fetchColumn() ?: $brandName);
} catch (Throwable) {
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>One-Time Admin Recovery · <?= app_escape($brandName) ?></title>
  <link rel="stylesheet" href="css/public.css?v=20260930-1">
  <style>
    .recovery-note{padding:12px 14px;border:1px solid #ead7a8;border-radius:12px;background:#fff9e8;color:#66501a;font-size:12px;line-height:1.55;margin-bottom:14px}
    .recovery-ok{padding:14px;border:1px solid #b9ddc8;border-radius:12px;background:#eef9f2;color:#176338;line-height:1.55;margin-bottom:14px}
    .recovery-bad{padding:14px;border:1px solid #ecc6c2;border-radius:12px;background:#fff2f1;color:#8e2a22;line-height:1.55;margin-bottom:14px}
  </style>
</head>
<body>
<main class="auth-shell">
  <section class="auth-brand-panel">
    <div>
      <a class="public-brand" href="index.php">
        <span class="public-logo">SF</span>
        <span><strong><?= app_escape($brandName) ?></strong><span>Emergency account recovery</span></span>
      </a>
      <h1>Restore Super Admin access.</h1>
      <p>This recovery page targets the active owner/Super Admin account and permanently consumes its recovery key after one successful password change.</p>
    </div>
    <footer>One-time key · Server-side password hashing · Account unlock · Audit record</footer>
  </section>
  <section class="auth-form-panel">
    <div class="auth-card">
      <p class="eyebrow">One-time recovery</p>
      <h2>Reset the Super Admin password</h2>

      <?php if ($success): ?>
        <div class="recovery-ok"><strong>Password updated.</strong> The recovery key is now consumed and cannot be reused.</div>
        <a class="submit-button" style="display:flex;align-items:center;justify-content:center;text-decoration:none" href="login.php">Open sign in</a>
      <?php elseif ($alreadyUsed): ?>
        <div class="recovery-bad"><strong>Recovery page locked.</strong> This one-time recovery key has already been consumed.</div>
        <a class="submit-button" style="display:flex;align-items:center;justify-content:center;text-decoration:none" href="login.php">Return to sign in</a>
      <?php elseif (!$admin): ?>
        <div class="recovery-bad">No active owner or Super Admin account could be found.</div>
      <?php else: ?>
        <div class="recovery-note">Resetting access for <strong><?= app_escape((string)$admin['email']) ?></strong>. After this succeeds, remove this file from production on the next deploy.</div>
        <?php if ($error): ?><div class="recovery-bad"><?= app_escape($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
          <input type="hidden" name="action" value="reset">
          <div class="field-wrap">
            <label for="recovery_key">Recovery key</label>
            <input class="field" id="recovery_key" name="recovery_key" type="password" autocomplete="off" required autofocus>
          </div>
          <div class="field-wrap">
            <label for="password">New password</label>
            <input class="field" id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>
          </div>
          <div class="field-wrap">
            <label for="password_confirmation">Confirm new password</label>
            <input class="field" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
          </div>
          <div class="password-guidance">Use at least 12 characters, including an uppercase letter, lowercase letter, and number.</div>
          <button class="submit-button" type="submit">Update Super Admin password</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</main>
</body>
</html>
