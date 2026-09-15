<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

if (!app_has_config()) {
    app_redirect('setup-first-user.php');
}

try {
    $config = app_config();
    date_default_timezone_set((string)($config['app']['timezone'] ?? 'UTC'));
    app_boot_session();
    $pdo = app_pdo();
    if (!app_schema_is_installed($pdo) || !app_owner_exists($pdo)) {
        app_redirect('setup-first-user.php');
    }
} catch (Throwable) {
    app_redirect('setup-first-user.php');
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenRecord = null;
$error = null;

if (preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
    $statement = $pdo->prepare(
        "SELECT prt.id AS reset_id, prt.user_id, prt.expires_at, prt.used_at,
                u.email, u.display_name, u.status, om.organization_id
         FROM password_reset_tokens prt
         INNER JOIN users u ON u.id = prt.user_id
         INNER JOIN organization_memberships om ON om.user_id = u.id AND om.status = 'active'
         WHERE prt.token_hash = :token_hash AND u.archived_at IS NULL
         ORDER BY om.id ASC LIMIT 1"
    );
    $statement->execute(['token_hash' => hash('sha256', $token)]);
    $tokenRecord = $statement->fetch() ?: null;
}

$validToken = $tokenRecord
    && $tokenRecord['used_at'] === null
    && $tokenRecord['status'] === 'active'
    && strtotime((string)$tokenRecord['expires_at']) > time();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = (string)($_POST['password'] ?? '');
    $confirmation = (string)($_POST['password_confirmation'] ?? '');
    if (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'The reset form expired. Refresh the page and try again.';
    } elseif ($password !== $confirmation) {
        $error = 'The passwords do not match.';
    } elseif (!app_password_is_valid($password)) {
        $error = 'Use at least 12 characters with an uppercase letter, lowercase letter, and number.';
    } else {
        $passwordHash = app_password_hash($password);
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE users
                 SET password_hash = :password_hash, password_changed_at = NOW(6),
                     failed_login_count = 0, locked_until = NULL
                 WHERE id = :user_id"
            )->execute(['password_hash' => $passwordHash, 'user_id' => $tokenRecord['user_id']]);
            $pdo->prepare(
                "UPDATE password_reset_tokens SET used_at = NOW(6)
                 WHERE user_id = :user_id AND used_at IS NULL"
            )->execute(['user_id' => $tokenRecord['user_id']]);
            app_audit(
                $pdo,
                (int)$tokenRecord['organization_id'],
                (int)$tokenRecord['user_id'],
                'authentication.password_reset_completed',
                'user',
                (string)$tokenRecord['user_id']
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $_SESSION = [];
        session_regenerate_id(true);
        app_redirect('login.php?reset=success');
    }
}

$brandName = (string)($config['app']['name'] ?? 'Restaurant Workspace');
try {
    $brandName = (string)($pdo->query("SELECT restaurant_name FROM brand_settings ORDER BY id ASC LIMIT 1")->fetchColumn() ?: $brandName);
} catch (Throwable) {
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reset Password · <?= app_escape($brandName) ?></title>
  <link rel="stylesheet" href="css/public.css">
</head>
<body>
  <?= app_guest_account_menu('auth') ?>
  <main class="auth-shell">
    <section class="auth-brand-panel">
      <div>
        <a class="public-brand" href="landing.html"><span class="public-logo">RW</span><span><strong><?= app_escape($brandName) ?></strong><span>Secure account recovery</span></span></a>
        <h1>Create a new account password.</h1>
        <p>Reset links are single-use, expire after 60 minutes, and are invalidated immediately after a successful password change.</p>
      </div>
      <footer>Single-use token · Strong password validation · Session reset · Audit history</footer>
    </section>
    <section class="auth-form-panel">
      <div class="auth-card">
        <?php if (!$validToken): ?>
          <p class="eyebrow">Reset link unavailable</p>
          <h2>Request a new link</h2>
          <p>This password-reset link is invalid, expired, or has already been used.</p>
          <div class="auth-links"><a class="auth-back-link" href="landing.html">← Public site</a><a href="forgot-password.php">Request another reset</a></div>
          <a class="submit-button" style="display:flex;align-items:center;justify-content:center;text-decoration:none" href="login.php">Return to sign in</a>
        <?php else: ?>
          <p class="eyebrow">Password reset</p>
          <h2>Choose a new password</h2>
          <p>Resetting access for <?= app_escape((string)$tokenRecord['email']) ?>.</p>
          <?php if ($error): ?><div class="form-message bad" style="margin-bottom:14px"><?= app_escape($error) ?></div><?php endif; ?>
          <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <input type="hidden" name="token" value="<?= app_escape($token) ?>">
            <div class="field-wrap"><label for="password">New password</label><input class="field" id="password" name="password" type="password" autocomplete="new-password" minlength="12" required autofocus></div>
            <div class="field-wrap"><label for="password_confirmation">Confirm new password</label><input class="field" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required></div>
            <div class="password-guidance">Use at least 12 characters, including an uppercase letter, lowercase letter, and number.</div>
            <div class="auth-links"><a class="auth-back-link" href="landing.html">← Public site</a><a href="login.php">Cancel</a></div>
            <button class="submit-button" type="submit">Update password</button>
          </form>
        <?php endif; ?>
      </div>
    </section>
  </main>
  <script src="js/public-account-menu.js"></script>
<script src="assets/js/public-shell.js?v=20260915-1"></script>
</body>
</html>
