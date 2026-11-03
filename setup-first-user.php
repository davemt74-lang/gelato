<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/first-owner-setup.php';


$configError = null;
$databaseError = null;
$schemaInstalled = false;
$ownerExists = false;
$errors = [];
$success = false;
$pdo = null;

if (!app_has_config()) {
    $configError = 'config.php was not found.';
} else {
    try {
        $config = app_config();
        if (!empty($config['food_monster_hosted']['installation_id'])) {
            http_response_code(404);
            exit('This site is set up through Food Monster.');
        }
        date_default_timezone_set((string)($config['app']['timezone'] ?? 'UTC'));
        app_boot_session();
        $pdo = app_pdo();
        $schemaInstalled = app_schema_is_installed($pdo);
        $ownerExists = $schemaInstalled && app_owner_exists($pdo);
    } catch (Throwable $exception) {
        $databaseError = $exception->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO && $schemaInstalled && !$ownerExists) {
    $input = [
        'restaurant_name' => trim((string)($_POST['restaurant_name'] ?? '')),
        'legal_name' => trim((string)($_POST['legal_name'] ?? '')),
        'location_name' => trim((string)($_POST['location_name'] ?? 'Main Location')),
        'timezone' => trim((string)($_POST['timezone'] ?? 'America/Phoenix')),
        'first_name' => trim((string)($_POST['first_name'] ?? '')),
        'last_name' => trim((string)($_POST['last_name'] ?? '')),
        'email' => strtolower(trim((string)($_POST['email'] ?? ''))),
        'password' => (string)($_POST['password'] ?? ''),
        'password_confirm' => (string)($_POST['password_confirm'] ?? ''),
    ];

    if (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The setup form expired. Refresh the page and try again.';
    }
    if ($input['restaurant_name'] === '' || mb_strlen($input['restaurant_name']) > 160) {
        $errors[] = 'Enter a restaurant name of 160 characters or fewer.';
    }
    if ($input['first_name'] === '' || $input['last_name'] === '') {
        $errors[] = 'Enter the owner’s first and last name.';
    }
    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid owner email address.';
    }
    if (strlen($input['password']) < 12
        || !preg_match('/[A-Z]/', $input['password'])
        || !preg_match('/[a-z]/', $input['password'])
        || !preg_match('/\d/', $input['password'])
        || !preg_match('/[^A-Za-z0-9]/', $input['password'])) {
        $errors[] = 'Use at least 12 characters with uppercase, lowercase, a number, and a symbol.';
    }
    if ($input['password'] !== $input['password_confirm']) {
        $errors[] = 'The password confirmation does not match.';
    }
    try {
        new DateTimeZone($input['timezone']);
    } catch (Throwable) {
        $errors[] = 'Enter a valid PHP timezone, such as America/Phoenix.';
    }

    if (!$errors) {
        try {
            $owner = gelato_seed_first_owner($pdo, $input, app_password_hash($input['password']));
            app_session_start_user($pdo, $owner['organization_id'], $owner['user_id'], 'first_owner');
            $success = true;
            app_redirect('index.php?setup=complete');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception instanceof PDOException && (string)$exception->getCode() === '23000'
                ? 'That email address is already in use.'
                : 'The owner account could not be created: ' . $exception->getMessage();
        }
    }
}

$csrf = app_has_config() && !$databaseError ? app_csrf_token() : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Create First Super Admin</title>
  <style>
    :root{--ink:#171815;--muted:#666b65;--line:#dddcd5;--accent:#d94a2b;--soft:#fff1ec;--good:#157347;--bad:#a62a24}*{box-sizing:border-box}body{margin:0;background:#f4f3ef;color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.shell{width:min(980px,calc(100% - 32px));margin:34px auto}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:18px}.brand{display:flex;gap:11px;align-items:center}.logo{width:46px;height:46px;display:grid;place-items:center;border-radius:14px;color:#fff;background:linear-gradient(145deg,#ff8a63,#d84626);font-weight:900}.brand strong{display:block}.brand span{display:block;margin-top:3px;color:var(--muted);font-size:12px}.pill{padding:7px 10px;border:1px solid var(--line);border-radius:999px;background:#fff;color:var(--muted);font-size:11px;font-weight:800}.card{padding:28px;border:1px solid var(--line);border-radius:24px;background:#fff;box-shadow:0 20px 60px rgba(20,22,18,.08)}h1{margin:7px 0 8px;font-size:clamp(31px,5vw,52px);line-height:.98;letter-spacing:-.055em}.eyebrow{margin:0;color:var(--accent);font-size:10px;font-weight:900;letter-spacing:.13em;text-transform:uppercase}.intro{max-width:720px;margin:0 0 24px;color:var(--muted);line-height:1.6}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.full{grid-column:1/-1}.field-wrap{display:grid;gap:6px}.field-wrap label{font-size:11px;font-weight:850}.field{width:100%;min-height:45px;padding:11px 12px;border:1px solid var(--line);border-radius:12px;background:#fff;color:var(--ink);outline:0}.field:focus{border-color:rgba(217,74,43,.6);box-shadow:0 0 0 4px rgba(217,74,43,.1)}.help{color:var(--muted);font-size:10px;line-height:1.45}.button{min-height:47px;padding:11px 17px;border:0;border-radius:13px;color:#fff;background:var(--accent);font-weight:900}.notice{margin-bottom:18px;padding:14px 15px;border:1px solid;border-radius:13px;font-size:12px;line-height:1.55}.notice.bad{color:var(--bad);border-color:#efc8c4;background:#fff3f2}.notice.good{color:var(--good);border-color:#c9e7d6;background:#effaf4}.notice.info{color:#315f9b;border-color:#cbd9ec;background:#f3f7fd}.steps{display:grid;gap:8px;margin:16px 0 0;padding:0;list-style:none}.steps li{padding:11px 12px;border:1px solid var(--line);border-radius:12px;background:#f8f7f3;font-size:12px}.steps code{font-weight:900}.locked{padding:24px;border:1px solid #c9e7d6;border-radius:18px;background:#effaf4}.locked h2{margin-top:0}.actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:16px}.link{display:inline-flex;align-items:center;min-height:42px;padding:9px 13px;border:1px solid var(--line);border-radius:11px;color:var(--ink);background:#fff;text-decoration:none;font-size:12px;font-weight:850}@media(max-width:700px){.shell{margin:18px auto}.card{padding:20px}.grid{grid-template-columns:1fr}.full{grid-column:auto}.top{align-items:flex-start;flex-direction:column}}
  </style>
</head>
<body>
<main class="shell">
  <div class="top"><div class="brand"><div class="logo">✦</div><div><strong>Restaurant Workspace</strong><span>Secure first-owner installation</span></div></div><span class="pill">One-time setup</span></div>
  <section class="card">
    <p class="eyebrow">Installation</p>
    <h1>Create the first Super Admin.</h1>
    <p class="intro">This page creates the store organization, first location, protected owner account, default account types, positions, brand record, resume form, public landing page, and Owner System Agent.</p>

    <?php if ($configError): ?>
      <div class="notice bad"><strong>Configuration required.</strong> <?= app_escape($configError) ?></div>
      <ol class="steps">
        <li>Rename <code>config-example.php</code> to <code>config.php</code>.</li>
        <li>Enter the database name, database username, and database password.</li>
        <li>Import <code>database/install.sql</code> into that empty database.</li>
        <li>Reload this page.</li>
      </ol>
    <?php elseif ($databaseError): ?>
      <div class="notice bad"><strong>Database connection failed.</strong> <?= app_escape($databaseError) ?></div>
      <p class="help">Confirm the database settings in config.php and verify that PHP has the PDO MySQL extension enabled.</p>
    <?php elseif (!$schemaInstalled): ?>
      <div class="notice bad"><strong>The database is connected, but the schema is missing.</strong> Import <code>database/install.sql</code>, then reload this page.</div>
    <?php elseif ($ownerExists): ?>
      <div class="locked"><h2>Installation is locked.</h2><p>A Super Admin already exists. The first-user form cannot be run again.</p><div class="actions"><a class="link" href="login.php">Open secure login</a><a class="link" href="landing.html">View public landing page</a></div></div>
    <?php else: ?>
      <?php foreach ($errors as $error): ?><div class="notice bad"><?= app_escape($error) ?></div><?php endforeach; ?>
      <div class="notice info">This installer is available only until the first Super Admin is created. It permanently locks itself by checking the database.</div>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= app_escape($csrf) ?>">
        <div class="grid">
          <div class="field-wrap"><label for="restaurant_name">Restaurant name</label><input class="field" id="restaurant_name" name="restaurant_name" value="<?= app_escape($_POST['restaurant_name'] ?? '') ?>" required></div>
          <div class="field-wrap"><label for="legal_name">Legal business name</label><input class="field" id="legal_name" name="legal_name" value="<?= app_escape($_POST['legal_name'] ?? '') ?>"></div>
          <div class="field-wrap"><label for="location_name">First location name</label><input class="field" id="location_name" name="location_name" value="<?= app_escape($_POST['location_name'] ?? 'Main Location') ?>" required></div>
          <div class="field-wrap"><label for="timezone">Timezone</label><input class="field" id="timezone" name="timezone" value="<?= app_escape($_POST['timezone'] ?? 'America/Phoenix') ?>" required></div>
          <div class="field-wrap"><label for="first_name">Owner first name</label><input class="field" id="first_name" name="first_name" value="<?= app_escape($_POST['first_name'] ?? '') ?>" autocomplete="given-name" required></div>
          <div class="field-wrap"><label for="last_name">Owner last name</label><input class="field" id="last_name" name="last_name" value="<?= app_escape($_POST['last_name'] ?? '') ?>" autocomplete="family-name" required></div>
          <div class="field-wrap full"><label for="email">Owner email</label><input class="field" id="email" name="email" type="email" value="<?= app_escape($_POST['email'] ?? '') ?>" autocomplete="email" required></div>
          <div class="field-wrap"><label for="password">Password</label><input class="field" id="password" name="password" type="password" autocomplete="new-password" required><span class="help">At least 12 characters with uppercase, lowercase, number, and symbol.</span></div>
          <div class="field-wrap"><label for="password_confirm">Confirm password</label><input class="field" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required></div>
          <div class="full"><button class="button" type="submit">Create Super Admin and finish setup</button></div>
        </div>
      </form>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
