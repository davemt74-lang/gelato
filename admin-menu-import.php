<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/menu-sync.php';

$user = app_require_auth();
if ((int)($user['is_owner_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Owner access required.');
}

$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];
$settings = menu_source_settings();
$message = null;
$error = null;
$summary = null;

try {
    $currentSections = menu_database_sections($pdo, $organizationId);
    $currentSummary = [
        'sections' => count($currentSections),
        'items' => array_sum(array_map(static fn(array $section): int => count($section['items'] ?? []), $currentSections)),
    ];
} catch (Throwable) {
    $currentSummary = ['sections' => 0, 'items' => 0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Your session token expired. Refresh the page and try again.');
        }

        $payload = menu_http_json((string)$settings['rest_url'], (int)$settings['timeout_seconds']);
        $normalized = menu_normalize_source_payload($payload);
        $summary = menu_sync_database($pdo, $organizationId, $normalized, (int)$user['id']);
        $message = 'Current Gelato Spot menu imported successfully from the live REST API.';
        $currentSummary = [
            'sections' => (int)$summary['sections'],
            'items' => (int)$summary['items'],
        ];
    } catch (Throwable $exception) {
        error_log('Menu import failed: ' . $exception->getMessage());
        $error = 'The live Gelato Spot menu could not be imported. No database changes were committed.';
    }
}

$csrf = app_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Menu Import · <?= app_escape((string)($user['organization_name'] ?? 'Restaurant Training')) ?></title>
<style>
:root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#171717;background:#f5f4f1}*{box-sizing:border-box}body{margin:0}.shell{max-width:980px;margin:0 auto;padding:40px 22px 70px}.top{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:28px}.top a{color:#171717;text-decoration:none;font-weight:700}.eyebrow{margin:0 0 7px;text-transform:uppercase;letter-spacing:.12em;font-size:12px;color:#777}h1{font-size:38px;line-height:1.05;margin:0}p{line-height:1.6;color:#555}.grid{display:grid;grid-template-columns:1.15fr .85fr;gap:18px}.card{background:#fff;border:1px solid #e2e0da;border-radius:22px;padding:24px;box-shadow:0 14px 40px rgba(0,0,0,.04)}.stat{font-size:36px;font-weight:800}.muted{color:#777}.btn{appearance:none;border:0;border-radius:11px;padding:12px 16px;font:inherit;font-weight:800;cursor:pointer}.btn-dark{background:#171717;color:#fff}.notice{padding:13px 15px;border-radius:12px;margin-bottom:15px}.ok{background:#eaf7ee;color:#165c2d}.bad{background:#fff0ef;color:#8d2019}.meta{display:grid;grid-template-columns:1fr 1fr;gap:10px}.meta div{background:#f7f6f2;border-radius:13px;padding:14px}.meta small{display:block;color:#777;margin-bottom:4px}.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;word-break:break-word}.warning{background:#eef5ff;border:1px solid #cfdef6;border-radius:14px;padding:14px;margin-top:18px;color:#27456f}@media(max-width:760px){.grid{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}h1{font-size:32px}}
</style>
</head>
<body>
<div class="shell">
  <div class="top">
    <div><p class="eyebrow">Owner tools</p><h1>Import current menu</h1><p>Pull the same live menu data used by Gelato Spot and load it into this installation's database.</p></div>
    <a href="index.html#menu">← Back to workspace</a>
  </div>

  <?php if ($message): ?><div class="notice ok"><?= app_escape($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice bad"><?= app_escape($error) ?></div><?php endif; ?>

  <div class="grid">
    <section class="card">
      <p class="eyebrow">Live source</p>
      <h2>Gelato Spot REST menu</h2>
      <p>No API key or menu-source setup is required. The application has the public REST endpoint built in. Importing updates matching menu sections and items, deactivates menu records no longer present in the current source, and keeps the training interface database-driven.</p>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= app_escape($csrf) ?>">
        <button class="btn btn-dark" type="submit">Import current Gelato Spot menu</button>
      </form>
      <div class="warning"><strong>MCP is separate.</strong> MCP is not required to import the menu or run training. It remains available for later agent-facing restaurant knowledge integration.</div>
    </section>

    <aside class="card">
      <p class="eyebrow">Current database</p>
      <div class="meta">
        <div><small>Active sections</small><strong class="stat"><?= (int)$currentSummary['sections'] ?></strong></div>
        <div><small>Active items</small><strong class="stat"><?= (int)$currentSummary['items'] ?></strong></div>
      </div>
      <h3>REST source</h3>
      <div class="code"><?= app_escape((string)$settings['rest_url']) ?></div>
      <h3>Optional MCP</h3>
      <div class="code"><?= app_escape((string)$settings['mcp_url']) ?></div>
      <?php if ($summary): ?>
        <h3>Last import</h3>
        <div class="code"><?= app_escape(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></div>
      <?php endif; ?>
    </aside>
  </div>
</div>
</body>
</html>
