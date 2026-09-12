<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/UpgradeService.php';

$user = app_require_auth();
if ((int)($user['is_owner_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Only the protected owner account can run database upgrades.');
}

$pdo = app_pdo();
$upgrader = new UpgradeService($pdo, __DIR__);
$error = '';
$success = '';
$results = [];
$baselineCount = 0;

try {
    $upgrader->ensureTrackingTables();
    $baselineCount = $upgrader->bootstrapLegacyHistory((int)$user['id']);
    if ($baselineCount > 0) {
        $success = 'Upgrade tracking initialized. ' . $baselineCount . ' existing migration'
            . ($baselineCount === 1 ? ' was' : 's were') . ' safely recognized as already installed.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!app_verify_csrf($_POST['_csrf'] ?? null)) {
            throw new RuntimeException('The upgrade request expired. Refresh this page and try again.');
        }
        if ((string)($_POST['action'] ?? '') !== 'upgrade') {
            throw new RuntimeException('Unsupported upgrade action.');
        }
        $results = $upgrader->applyPending((int)$user['id']);
        $success = $results
            ? 'Gelato upgraded successfully. ' . count($results) . ' migration' . (count($results) === 1 ? '' : 's') . ' applied.'
            : 'Gelato is already up to date.';
        try {
            app_audit(
                $pdo,
                (int)$user['organization_id'],
                (int)$user['id'],
                'system.upgrade_completed',
                'database',
                null,
                null,
                ['migrations_applied' => array_column($results, 'key')]
            );
        } catch (Throwable) {
            // Upgrade success must not be reversed by a non-critical audit write.
        }
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$statusRows = [];
$pending = [];
$recentRuns = [];
$currentVersion = 'unknown';
$targetVersion = 'unknown';
$checksumProblems = [];
try {
    $statusRows = $upgrader->migrationStatus();
    $pending = array_values(array_filter($statusRows, static fn(array $row): bool => $row['status'] === 'pending'));
    $checksumProblems = array_values(array_filter($statusRows, static fn(array $row): bool => !empty($row['checksum_changed'])));
    $recentRuns = $upgrader->recentRuns();
    $currentVersion = $upgrader->currentVersion();
    $targetVersion = $upgrader->targetVersion();
} catch (Throwable $exception) {
    $error = $error ?: $exception->getMessage();
}

try {
    $dbVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
} catch (Throwable) {
    $dbVersion = 'Unavailable';
    $dbName = 'Unavailable';
}

$canUpgrade = !$checksumProblems && count($pending) > 0 && $error === '';
$csrf = app_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>System Upgrade · Gelato</title>
<style>
:root{--bg:#f4f3ef;--panel:#fff;--ink:#171815;--muted:#6b6e68;--line:#dddcd5;--accent:#d94a2b;--good:#157347;--goodbg:#eaf7ef;--warn:#8c6200;--warnbg:#fff7df;--bad:#a62a24;--badbg:#fff0ef;--shadow:0 16px 42px rgba(25,26,22,.08)}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.shell{width:min(1180px,calc(100% - 32px));margin:0 auto;padding:34px 0 70px}.top{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:22px}.eyebrow{color:var(--accent);font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.12em}.top h1{margin:7px 0 8px;font-size:clamp(32px,5vw,54px);letter-spacing:-.045em}.top p{max-width:760px;margin:0;color:var(--muted);line-height:1.55}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:9px 14px;border:0;border-radius:11px;font-weight:850;text-decoration:none;cursor:pointer}.btn.light{background:#fff;color:var(--ink);border:1px solid var(--line)}.btn.primary{background:var(--accent);color:#fff}.btn:disabled{opacity:.5;cursor:not-allowed}.notice{padding:13px 15px;margin:0 0 16px;border-radius:13px;border:1px solid}.notice.good{background:var(--goodbg);border-color:#b8dfc4;color:#0d5c35}.notice.bad{background:var(--badbg);border-color:#f1c6bc;color:#8d211d}.notice.warn{background:var(--warnbg);border-color:#ead18a;color:#6f5000}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:18px}.stat,.card{background:var(--panel);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow)}.stat{padding:15px}.stat strong{display:block;font-size:19px;line-height:1.15;word-break:break-word}.stat span{display:block;margin-top:5px;color:var(--muted);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.card{padding:20px;margin-top:14px}.card-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:14px}.card h2{margin:0;font-size:20px}.card p{margin:5px 0 0;color:var(--muted);line-height:1.5}.table-wrap{overflow:auto;border:1px solid var(--line);border-radius:13px}table{width:100%;border-collapse:collapse;background:#fff;font-size:12px}th,td{padding:11px 12px;text-align:left;border-bottom:1px solid #ecebe6;vertical-align:top}th{background:#f8f7f3;color:#5e625d;font-size:10px;text-transform:uppercase;letter-spacing:.06em}tr:last-child td{border-bottom:0}.badge{display:inline-flex;padding:5px 8px;border-radius:999px;font-size:10px;font-weight:900}.badge.applied{background:var(--goodbg);color:var(--good)}.badge.pending{background:var(--warnbg);color:var(--warn)}.badge.changed,.badge.failed{background:var(--badbg);color:var(--bad)}.badge.adopted{background:#edf4ff;color:#315f9b}.actions{margin-top:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}.tiny{font-size:11px;color:var(--muted)}code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px}@media(max-width:760px){.top{display:block}.top .btn{margin-top:14px}.stats{grid-template-columns:1fr 1fr}}@media(max-width:480px){.stats{grid-template-columns:1fr}}
</style>
</head>
<body>
<main class="shell">
  <header class="top">
    <div>
      <div class="eyebrow">Admin · System</div>
      <h1>Gelato Upgrade</h1>
      <p>One-click database upgrades for the restaurant training workspace. Completed migrations are tracked by checksum and never replayed.</p>
    </div>
    <a class="btn light" href="index.php">Back to Workspace</a>
  </header>

  <?php if ($error !== ''): ?><div class="notice bad"><strong>Upgrade stopped.</strong><br><?=app_escape($error)?></div><?php endif; ?>
  <?php if ($success !== ''): ?><div class="notice good"><?=app_escape($success)?></div><?php endif; ?>
  <?php if ($checksumProblems): ?><div class="notice bad"><strong>Migration drift detected.</strong> An already-applied SQL file changed after installation. Restore the original migration before running another upgrade.</div><?php endif; ?>

  <section class="stats">
    <div class="stat"><strong><?=app_escape($currentVersion)?></strong><span>Installed schema</span></div>
    <div class="stat"><strong><?=app_escape($targetVersion)?></strong><span>Available schema</span></div>
    <div class="stat"><strong><?=count($pending)?></strong><span>Pending migrations</span></div>
    <div class="stat"><strong><?=count($statusRows)-count($pending)?></strong><span>Tracked migrations</span></div>
  </section>

  <section class="card">
    <div class="card-head"><div><h2><?=$pending ? 'Upgrade available' : 'Database is current'?></h2><p><?=$pending ? 'Pending SQL migrations will run in filename order. Your existing config.php and application data are not replaced.' : 'There are no unapplied production SQL migrations in this release.'?></p></div></div>
    <?php if ($pending): ?>
    <div class="table-wrap"><table><thead><tr><th>Migration</th><th>Size</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($pending as $migration): ?><tr><td><strong><?=app_escape($migration['filename'])?></strong><br><code><?=app_escape(substr($migration['checksum'],0,16))?>…</code></td><td><?=number_format((int)$migration['bytes'])?> bytes</td><td><span class="badge pending">Pending</span></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <form method="post" class="actions" onsubmit="const b=this.querySelector('button');b.disabled=true;b.textContent='Upgrading Gelato…';">
      <input type="hidden" name="_csrf" value="<?=app_escape($csrf)?>">
      <input type="hidden" name="action" value="upgrade">
      <button class="btn primary" type="submit" <?=$canUpgrade?'':'disabled'?>>Run Gelato Upgrade</button>
      <span class="tiny">Runs only pending production migrations. Demo/sample SQL files are excluded.</span>
    </form>
    <?php else: ?><p><strong>✓ No database upgrade is required.</strong></p><?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><div><h2>Migration history</h2><p>Existing installations are recognized from their schema once, then every future upgrade is tracked here.</p></div></div>
    <div class="table-wrap"><table><thead><tr><th>SQL file</th><th>Status</th><th>Applied</th><th>Checksum</th></tr></thead><tbody>
      <?php foreach (array_reverse($statusRows) as $migration): ?><tr>
        <td><?=app_escape($migration['filename'])?></td>
        <td><?php if ($migration['checksum_changed']): ?><span class="badge changed">Changed after install</span><?php elseif ($migration['status']==='pending'): ?><span class="badge pending">Pending</span><?php elseif ($migration['adopted_existing']): ?><span class="badge adopted">Existing · tracked</span><?php else: ?><span class="badge applied">Applied</span><?php endif; ?></td>
        <td><?=app_escape((string)($migration['applied_at'] ?: '—'))?></td>
        <td><code><?=app_escape(substr($migration['checksum'],0,12))?>…</code></td>
      </tr><?php endforeach; ?>
    </tbody></table></div>
  </section>

  <?php if ($recentRuns): ?><section class="card"><div class="card-head"><div><h2>Recent upgrade runs</h2><p>Execution history is retained even when a migration fails.</p></div></div><div class="table-wrap"><table><thead><tr><th>Migration</th><th>Status</th><th>Statements</th><th>Runtime</th><th>Started</th></tr></thead><tbody>
  <?php foreach ($recentRuns as $run): ?><tr><td><?=app_escape((string)$run['filename'])?></td><td><span class="badge <?=app_escape((string)$run['status'])?>"><?=app_escape(ucfirst((string)$run['status']))?></span></td><td><?=(int)$run['statement_count']?></td><td><?=(int)$run['execution_ms']?> ms</td><td><?=app_escape((string)$run['started_at'])?></td></tr><?php endforeach; ?>
  </tbody></table></div></section><?php endif; ?>

  <section class="card"><div class="card-head"><div><h2>Database</h2><p>Upgrade target currently connected through <code>config.php</code>.</p></div></div><div class="table-wrap"><table><tbody><tr><th>Database</th><td><?=app_escape($dbName)?></td></tr><tr><th>Server</th><td><?=app_escape($dbVersion)?></td></tr><tr><th>Access</th><td>Protected owner account only</td></tr></tbody></table></div></section>
</main>
</body>
</html>
