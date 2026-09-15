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

if (app_current_user()) {
    app_redirect('index.php');
}

$submitted = false;
$error = null;
$developmentResetUrl = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'The request form expired. Refresh the page and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        $submitted = true;
        $statement = $pdo->prepare(
            "SELECT u.id, u.email, u.display_name, u.status, om.organization_id
             FROM users u
             INNER JOIN organization_memberships om ON om.user_id = u.id AND om.status = 'active'
             WHERE u.email = :email AND u.archived_at IS NULL
             ORDER BY om.id ASC LIMIT 1"
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        if ($user && $user['status'] === 'active') {
            $recent = $pdo->prepare(
                "SELECT COUNT(*) FROM password_reset_tokens
                 WHERE user_id = :user_id AND created_at > DATE_SUB(NOW(6), INTERVAL 2 MINUTE)"
            );
            $recent->execute(['user_id' => $user['id']]);

            if ((int)$recent->fetchColumn() === 0) {
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $pdo->beginTransaction();
                try {
                    $pdo->prepare(
                        "UPDATE password_reset_tokens SET used_at = NOW(6)
                         WHERE user_id = :user_id AND used_at IS NULL"
                    )->execute(['user_id' => $user['id']]);
                    $pdo->prepare(
                        "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                         VALUES (:user_id, :token_hash, DATE_ADD(NOW(6), INTERVAL 60 MINUTE))"
                    )->execute(['user_id' => $user['id'], 'token_hash' => $tokenHash]);
                    app_audit(
                        $pdo,
                        (int)$user['organization_id'],
                        (int)$user['id'],
                        'authentication.password_reset_requested',
                        'user',
                        (string)$user['id']
                    );
                    $pdo->commit();
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $exception;
                }

                $resetUrl = app_url('reset-password.php?token=' . rawurlencode($token));
                $sent = app_send_password_reset_email((string)$user['email'], (string)$user['display_name'], $resetUrl);
                if (!$sent) {
                    error_log('Restaurant Workspace password reset email could not be sent for user ID ' . $user['id']);
                }
                if ((bool)($config['app']['debug'] ?? false)) {
                    $developmentResetUrl = $resetUrl;
                }
            }
        }

        // Keep response timing and wording similar whether or not the account exists.
        usleep(random_int(180000, 420000));
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
  <title>Forgot Password · <?= app_escape($brandName) ?></title>
  <link rel="stylesheet" href="css/public.css">
</head>
<body>
  <?= app_guest_account_menu('auth') ?>
  <main class="auth-shell">
    <section class="auth-brand-panel">
      <div>
        <a class="public-brand" href="landing.html"><span class="public-logo">RW</span><span><strong><?= app_escape($brandName) ?></strong><span>Secure account recovery</span></span></a>
        <h1>Recover access without exposing account details.</h1>
        <p>Enter your account email. When a matching active account exists, the system creates a single-use reset link that expires after 60 minutes.</p>
      </div>
      <footer>Generic responses · One-time tokens · Expiration · Password hashing · Audit history</footer>
    </section>
    <section class="auth-form-panel">
      <div class="auth-card">
        <?php if ($submitted && !$error): ?>
          <div class="auth-success-icon">✓</div>
          <p class="eyebrow">Request received</p>
          <h2>Check your email</h2>
          <p>If an active account matches that address, a password-reset link has been sent. The link expires in 60 minutes.</p>
          <?php if ($developmentResetUrl): ?>
            <div class="form-message good" style="overflow-wrap:anywhere"><strong>Debug mode reset link:</strong><br><a href="<?= app_escape($developmentResetUrl) ?>"><?= app_escape($developmentResetUrl) ?></a></div>
          <?php endif; ?>
          <div class="auth-links"><a class="auth-back-link" href="landing.html">← Public site</a><a href="login.php">Return to sign in</a></div>
        <?php else: ?>
          <p class="eyebrow">Account recovery</p>
          <h2>Forgot password?</h2>
          <p>Enter the email address used for your restaurant account.</p>
          <?php if ($error): ?><div class="form-message bad" style="margin-bottom:14px"><?= app_escape($error) ?></div><?php endif; ?>
          <form method="post" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
            <div class="field-wrap"><label for="email">Email address</label><input class="field" id="email" name="email" type="email" value="<?= app_escape($_POST['email'] ?? '') ?>" autocomplete="email" required autofocus></div>
            <div class="auth-links"><a class="auth-back-link" href="landing.html">← Public site</a><a href="login.php">Back to sign in</a></div>
            <button class="submit-button" type="submit">Send password-reset link</button>
          </form>
        <?php endif; ?>
      </div>
    </section>
  </main>
  <script src="js/public-account-menu.js"></script>
<script src="assets/js/public-shell.js?v=20260915-1"></script>
</body>
</html>
