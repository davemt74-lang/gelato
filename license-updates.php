<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/food-monster-installation.php';

$user = app_require_auth();
if (!fmi_owner($user)) {
    http_response_code(403);
    exit('Only the protected Gelato owner can manage License & Updates.');
}
header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');
$csrf = app_csrf_token();
$pdo = app_pdo();
$error = '';
$notice = '';
$view = ['connection' => 'unavailable', 'status' => null];

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        http_response_code(405);
        exit;
    }
    if ($method === 'POST') {
        if (!app_verify_csrf(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
            throw new FoodMonsterInstallationError('Refresh this page before submitting the form again.');
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'activate') {
            $password = $_POST['owner_password'] ?? '';
            $statement = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? AND status = 'active' AND archived_at IS NULL");
            $statement->execute([(int)$user['id']]);
            if (!is_string($password) || strlen($password) > 1024 || !password_verify($password, (string)$statement->fetchColumn())) {
                throw new FoodMonsterInstallationError('The current owner password was not accepted.');
            }
            $id = is_string($_POST['installation_id'] ?? null) ? trim($_POST['installation_id']) : '';
            $key = is_string($_POST['activation_key'] ?? null) ? trim($_POST['activation_key']) : '';
            $status = fmi_activate($user, $id, $key);
            $notice = 'Gelato is connected to Food Monster.';
        } elseif ($action === 'report') {
            $attempt = is_string($_POST['attempt_id'] ?? null) ? $_POST['attempt_id'] : '';
            $state = is_string($_POST['state'] ?? null) ? $_POST['state'] : '';
            $failure = is_string($_POST['failure_code'] ?? null) ? $_POST['failure_code'] : '';
            if ($state === 'completed') {
                require_once __DIR__ . '/includes/UpgradeService.php';
                $upgrader = new UpgradeService($pdo, __DIR__);
                foreach ($upgrader->migrationStatus() as $migration) {
                    if (($migration['status'] ?? '') === 'pending' || !empty($migration['checksum_changed'])) {
                        throw new FoodMonsterInstallationError('Resolve pending or changed migrations on the Gelato upgrade page before reporting completion.');
                    }
                }
            }
            $status = fmi_report($user, $attempt, $state, $failure);
            $notice = 'The update outcome was sent to Food Monster.';
        } else {
            throw new FoodMonsterInstallationError('Select a supported owner action.');
        }
        $view = ['connection' => 'connected', 'status' => $status];
        try {
            app_audit($pdo, (int)$user['organization_id'], (int)$user['id'],
                'system.food_monster_' . $action, 'installation', $status['installation_id']);
        } catch (Throwable $auditError) {
            error_log('Gelato installation audit: ' . get_class($auditError));
        }
    } else {
        session_write_close();
        $view = fmi_status($user);
    }
} catch (FoodMonsterInstallationError $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Gelato License & Updates: ' . get_class($exception));
    $error = 'Gelato could not complete this licensing request.';
}

$status = $view['status'];
$last = $status['last_update'] ?? null;
$release = $status['available_release'] ?? null;
$displayTime = static fn ($value): string => $value ? gmdate('Y-m-d H:i', (int)$value) . ' UTC' : 'Unknown';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>License &amp; Updates · Gelato</title>
<link rel="stylesheet" href="assets/css/admin-control.css">
</head>
<body class="admin-control">
<main class="admin-shell">
    <section class="admin-section">
        <div class="admin-section-head">
            <div><div class="admin-eyebrow">Owner · System</div><h1>License &amp; Updates</h1></div>
            <a class="admin-button" href="admin.php">Back to admin</a>
        </div>
        <?php if ($error !== ''): ?><p role="alert"><?= app_escape($error) ?></p><?php endif; ?>
        <?php if ($notice !== ''): ?><p role="status"><?= app_escape($notice) ?></p><?php endif; ?>
        <p>Installed Gelato version <strong><?= app_escape(fmi_version() ?? 'Unknown') ?></strong></p>
        <?php if ($status): ?>
            <dl>
                <dt>License</dt><dd><?= app_escape(ucfirst($status['license_state'])) ?></dd>
                <dt>Access through</dt><dd><?= app_escape($displayTime($status['expires_at'] ?? null)) ?></dd>
                <dt>Assigned channel</dt><dd><?= app_escape($status['channel'] ?? '') ?></dd>
                <dt>Automatic update preference</dt><dd><?= app_escape($status['auto_update'] ?? 'off') ?></dd>
                <dt>Latest published release</dt><dd><?= app_escape($status['latest_release']['version'] ?? 'None') ?></dd>
            </dl>
            <?php if (in_array($status['license_state'], ['expired', 'suspended', 'revoked'], true)): ?>
                <p>Restaurant operations remain available. New updates are paused.</p>
            <?php endif; ?>
            <?php if ($release): ?>
                <h2>Gelato <?= app_escape($release['version']) ?> is available</h2>
                <p><?= nl2br(app_escape($release['notes'] ?? '')) ?></p>
                <p>Approve the download in your Food Monster account. Verify its SHA-256 before extracting the ZIP over Gelato.</p>
                <p><code><?= app_escape($release['checksum'] ?? '') ?></code></p>
                <?php if ($release['migration_required'] ?? false): ?><p>This release requires the Gelato database upgrade after deployment.</p><?php endif; ?>
            <?php elseif (($status['update_status'] ?? '') === 'minimum_version'): ?>
                <p>An intermediate Gelato release is required before the latest version.</p>
            <?php endif; ?>
            <p><a class="admin-button" href="https://foodmonster.xyz/account.html?section=restaurant">Open Food Monster account</a>
            <a class="admin-button" href="upgrade.php">Open Gelato database upgrade</a></p>
        <?php elseif ($view['connection'] === 'unconfigured'): ?>
            <p>Connect this installation to check its license and available updates.</p>
        <?php elseif (isset($view['message'])): ?>
            <p role="status"><?= app_escape($view['message']) ?> Restaurant operations remain available.</p>
        <?php endif; ?>
        <?php if ($last): ?>
            <h2>Latest update attempt</h2>
            <p><?= app_escape($last['version']) ?> · <?= app_escape($last['state']) ?> <?= app_escape($last['error_summary'] ?? '') ?></p>
            <?php if ($last['state'] === 'started'): ?>
                <p>Report completion after deploying the package, applying pending Gelato migrations and checking the installation.</p>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= app_escape($csrf) ?>">
                    <input type="hidden" name="action" value="report">
                    <input type="hidden" name="attempt_id" value="<?= app_escape($last['id']) ?>">
                    <label for="failure-code">Reason when reporting failure</label>
                    <select id="failure-code" name="failure_code">
                        <option value="">Other failure</option><option value="checksum">Checksum</option>
                        <option value="deployment">File deployment</option><option value="migration">Database upgrade</option>
                        <option value="health">Health check</option>
                    </select>
                    <button class="admin-button" name="state" value="completed">Report completed</button>
                    <button class="admin-button" name="state" value="failed">Report failed</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <section class="admin-section">
        <h2><?= $status ? 'Reconnect installation' : 'Connect installation' ?></h2>
        <p>Enter the installation ID and activation key issued from your Food Monster account.</p>
        <form method="post" class="admin-panel">
            <input type="hidden" name="_csrf" value="<?= app_escape($csrf) ?>">
            <input type="hidden" name="action" value="activate">
            <p><label for="installation-id">Installation ID</label> <input id="installation-id" name="installation_id" autocomplete="off" autocapitalize="none" spellcheck="false" required maxlength="32" value="<?= app_escape($status['installation_id'] ?? '') ?>"></p>
            <p><label for="activation-key">Activation key</label> <input id="activation-key" type="password" name="activation_key" autocomplete="off" required maxlength="68"></p>
            <p><label for="owner-password">Owner password</label> <input id="owner-password" type="password" name="owner_password" autocomplete="current-password" required></p>
            <button class="admin-button dark" type="submit">Connect Food Monster</button>
        </form>
    </section>
</main>
</body>
</html>
