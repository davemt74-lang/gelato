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
$snapshotPath = __DIR__ . '/data/gelato-menu-scan.json';
$settings = menu_source_settings();
$message = null;
$error = null;
$summary = null;

function menu_import_snapshot_payload(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Bundled menu snapshot is missing or unreadable.');
    }
    $body = file_get_contents($path);
    if (!is_string($body)) {
        throw new RuntimeException('Unable to read bundled menu snapshot.');
    }
    try {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Bundled menu snapshot contains invalid JSON.', 0, $exception);
    }
    if (!is_array($payload)) {
        throw new RuntimeException('Bundled menu snapshot contains an unexpected payload.');
    }
    return $payload;
}

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
        $source = (string)($_POST['source'] ?? 'snapshot');
        if (!in_array($source, ['snapshot', 'live'], true)) {
            throw new RuntimeException('Unsupported menu import source.');
        }

        if ($source === 'live') {
            $payload = menu_http_json((string)$settings['rest_url'], (int)$settings['timeout_seconds']);
            $sourceLabel = 'Live Gelato Spot REST menu';
        } else {
            $payload = menu_import_snapshot_payload($snapshotPath);
            $sourceLabel = 'Bundled scanned-menu snapshot';
        }

        $normalized = menu_normalize_source_payload($payload);
        $summary = menu_sync_database($pdo, $organizationId, $normalized, (int)$user['id']);
        $message = $sourceLabel . ' imported successfully.';
        $currentSummary = [
            'sections' => (int)$summary['sections'],
            'items' => (int)$summary['items'],
        ];
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
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
:root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#171717;background:#f5f4f1}*{box-sizing:border-box}body{margin:0}.shell{max-width:1040px;margin:0 auto;padding:40px 22px 70px}.top{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:28px}.top a{color:#171717;text-decoration:none;font-weight:700}.eyebrow{margin:0 0 7px;text-transform:uppercase;letter-spacing:.12em;font-size:12px;color:#777}h1{font-size:38px;line-height:1.05;margin:0}p{line-height:1.6;color:#555}.grid{display:grid;grid-template-columns:1.2fr .8fr;gap:18px}.card{background:#fff;border:1px solid #e2e0da;border-radius:22px;padding:24px;box-shadow:0 14px 40px rgba(0,0,0,.04)}.stat{font-size:36px;font-weight:800}.muted{color:#777}.actions{display:grid;gap:12px;margin-top:20px}.action{border:1px solid #dedbd3;border-radius:16px;padding:18px;background:#faf9f6}.action h3{margin:0 0 8px;font-size:18px}.action p{margin:0 0 14px}.btn{appearance:none;border:0;border-radius:11px;padding:11px 15px;font:inherit;font-weight:800;cursor:pointer}.btn-dark{background:#171717;color:#fff}.btn-light{background:#eceae4;color:#171717}.notice{padding:13px 15px;border-radius:12px;margin-bottom:15px}.ok{background:#eaf7ee;color:#165c2d}.bad{background:#fff0ef;color:#8d2019}.meta{display:grid;grid-template-columns:1fr 1fr;gap:10px}.meta div{background:#f7f6f2;border-radius:13px;padding:14px}.meta small{display:block;color:#777;margin-bottom:4px}.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;word-break:break-word}.warning{background:#fff8df;border:1px solid #eddca0;border-radius:14px;padding:14px;margin-top:16px;color:#5f4a10}@media(max-width:760px){.grid{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}h1{font-size:32px}}
</style>
</head>
<body>
<div class="shell">
  <div class="top">
    <div><p class="eyebrow">Owner tools</p><h1>Menu data import</h1><p>Load the scanned Gelato menu into this installation without configuring any external API.</p></div>
    <a href="index.html#menu">← Back to workspace</a>
  </div>

  <?php if ($message): ?><div class="notice ok"><?= app_escape($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice bad"><?= app_escape($error) ?></div><?php endif; ?>

  <div class="grid">
    <section class="card">
      <p class="eyebrow">Recommended install path</p>
      <h2>Import the bundled scan</h2>
      <p>This is the zero-configuration source. It writes the scanned menu into the local database and the training app reads from that database afterward. Running it again is safe: matching sections and items are updated instead of duplicated.</p>
      <div class="actions">
        <div class="action">
          <h3>Bundled scanned menu</h3>
          <p>Works offline from the REST/MCP services. Use this for the first deployment and whenever you want a known local baseline.</p>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= app_escape($csrf) ?>">
            <input type="hidden" name="source" value="snapshot">
            <button class="btn btn-dark" type="submit">Import scanned menu</button>
          </form>
        </div>
        <div class="action">
          <h3>Optional live REST refresh</h3>
          <p>When the public Gelato Spot REST endpoint is reachable, this refreshes the database from the current published menu.</p>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= app_escape($csrf) ?>">
            <input type="hidden" name="source" value="live">
            <button class="btn btn-light" type="submit">Refresh from live REST</button>
          </form>
        </div>
      </div>
      <div class="warning"><strong>MCP is not required for import.</strong> The MCP endpoint remains a separate, read-only agent knowledge integration. Menu installation and training continue to work from the local database without MCP.</div>
    </section>

    <aside class="card">
      <p class="eyebrow">Current database</p>
      <div class="meta">
        <div><small>Active sections</small><strong class="stat"><?= (int)$currentSummary['sections'] ?></strong></div>
        <div><small>Active items</small><strong class="stat"><?= (int)$currentSummary['items'] ?></strong></div>
      </div>
      <h3>Sources</h3>
      <p class="muted">Bundled snapshot</p>
      <div class="code">data/gelato-menu-scan.json</div>
      <p class="muted">Optional REST</p>
      <div class="code"><?= app_escape((string)$settings['rest_url']) ?></div>
      <p class="muted">Optional MCP</p>
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
