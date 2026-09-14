<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/public-site.php';

$user = app_require_auth();
if (!app_has_permission('public_pages.edit', $user) && !app_has_permission('settings.organization_edit', $user)) {
    http_response_code(403);
    exit('You do not have permission to edit public-site settings.');
}

$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];
$errors = [];
$saveError = '';

function public_site_admin_text(string $key, int $max, array $source): string
{
    $value = trim((string)($source[$key] ?? ''));
    return mb_substr($value, 0, $max, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session token expired. Reload the page and try again.';
    }
    $payload = [
        'tagline' => public_site_admin_text('tagline', 255, $_POST),
        'hours_text' => public_site_admin_text('hours_text', 4000, $_POST),
        'address_line_1' => public_site_admin_text('address_line_1', 200, $_POST),
        'address_line_2' => public_site_admin_text('address_line_2', 200, $_POST),
        'city' => public_site_admin_text('city', 100, $_POST),
        'state' => public_site_admin_text('state', 100, $_POST),
        'postal_code' => public_site_admin_text('postal_code', 30, $_POST),
        'phone' => public_site_admin_text('phone', 40, $_POST),
        'email' => public_site_admin_text('email', 254, $_POST),
        'instagram_url' => public_site_admin_text('instagram_url', 500, $_POST),
        'facebook_url' => public_site_admin_text('facebook_url', 500, $_POST),
        'tiktok_url' => public_site_admin_text('tiktok_url', 500, $_POST),
        'youtube_url' => public_site_admin_text('youtube_url', 500, $_POST),
        'x_url' => public_site_admin_text('x_url', 500, $_POST),
    ];
    if ($payload['email'] !== '' && !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid public email address.';
    }
    foreach (['instagram_url', 'facebook_url', 'tiktok_url', 'youtube_url', 'x_url'] as $field) {
        $url = $payload[$field];
        if ($url === '') continue;
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            $errors[] = ucwords(str_replace('_url', '', str_replace('_', ' ', $field))) . ' must be a valid http(s) URL.';
        }
    }
    if (!$errors) {
        try {
            $previous = public_site_load_settings($pdo, $organizationId);
            $statement = $pdo->prepare("INSERT INTO public_site_settings
                 (organization_id, tagline, hours_text, address_line_1, address_line_2, city, state, postal_code,
                  phone, email, instagram_url, facebook_url, tiktok_url, youtube_url, x_url, updated_by)
                 VALUES
                 (:organization_id, :tagline, :hours_text, :address_line_1, :address_line_2, :city, :state, :postal_code,
                  :phone, :email, :instagram_url, :facebook_url, :tiktok_url, :youtube_url, :x_url, :updated_by)
                 ON DUPLICATE KEY UPDATE
                  tagline = VALUES(tagline), hours_text = VALUES(hours_text), address_line_1 = VALUES(address_line_1),
                  address_line_2 = VALUES(address_line_2), city = VALUES(city), state = VALUES(state),
                  postal_code = VALUES(postal_code), phone = VALUES(phone), email = VALUES(email),
                  instagram_url = VALUES(instagram_url), facebook_url = VALUES(facebook_url),
                  tiktok_url = VALUES(tiktok_url), youtube_url = VALUES(youtube_url), x_url = VALUES(x_url),
                  updated_by = VALUES(updated_by)");
            $statement->execute(['organization_id' => $organizationId, ...$payload, 'updated_by' => (int)$user['id']]);
            try { app_audit($pdo, $organizationId, (int)$user['id'], 'public_site.updated', 'public_site_settings', (string)$organizationId, $previous, $payload); } catch (Throwable) {}
            app_redirect('public-site-settings.php?saved=1');
        } catch (Throwable $exception) {
            error_log('Public site settings save failed: ' . $exception->getMessage());
            $saveError = 'Settings could not be saved. Run the normal Upgrade once, then try again.';
        }
    }
}
$settings = public_site_load_settings($pdo, $organizationId);
$locations = public_site_load_locations($pdo, $organizationId);
$saved = isset($_GET['saved']) && $_GET['saved'] === '1';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Public Site Settings | Restaurant Admin</title><style>
:root{--bg:#f4f3ef;--panel:#fff;--ink:#171815;--muted:#6b6e68;--line:#dddcd5;--accent:#d94a2b;--dark:#171b1a;--good:#157347;--bad:#a62a24}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.shell{width:min(1060px,calc(100vw - 36px));margin:0 auto}.top{padding:20px 0;border-bottom:1px solid var(--line);background:#fff}.top .shell{display:flex;align-items:center;justify-content:space-between;gap:16px}.top a{color:var(--ink);text-decoration:none;font-weight:800}.page{padding:42px 0 70px}.eyebrow{color:var(--accent);font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.13em}h1{margin:8px 0 10px;font-size:clamp(32px,5vw,52px);letter-spacing:-.045em}p{color:var(--muted);line-height:1.55}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:28px}.card{padding:24px;border:1px solid var(--line);border-radius:18px;background:var(--panel)}.card.wide{grid-column:1/-1}.card h2{margin:0 0 18px;font-size:18px}.fields{display:grid;grid-template-columns:1fr 1fr;gap:14px}.field{display:grid;gap:7px}.field.full{grid-column:1/-1}.field label{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.06em}.field input,.field textarea{width:100%;padding:11px 12px;border:1px solid var(--line);border-radius:10px;background:#fff;color:var(--ink);font:inherit}.field textarea{min-height:105px;resize:vertical}.actions{display:flex;gap:10px;align-items:center;margin-top:20px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 15px;border:0;border-radius:10px;background:var(--dark);color:#fff;text-decoration:none;font-weight:800;cursor:pointer}.btn.primary{background:var(--accent)}.notice{margin:18px 0 0;padding:12px 14px;border-radius:10px;background:#edf8f1;color:var(--good);font-weight:700}.error{margin:18px 0 0;padding:12px 14px;border-radius:10px;background:#fff0ef;color:var(--bad)}.location{padding:12px 0;border-top:1px solid var(--line)}.location:first-of-type{border-top:0}@media(max-width:760px){.grid,.fields{grid-template-columns:1fr}.card.wide,.field.full{grid-column:auto}.top .shell{align-items:flex-start;flex-direction:column}}
</style></head><body><header class="top"><div class="shell"><div><strong>Restaurant Admin</strong> · Public Site</div><div><a href="public/index.php" target="_blank" rel="noopener">View public site ↗</a>&nbsp;&nbsp;<a href="index.php">Back to workspace</a></div></div></header><main class="page"><div class="shell"><div class="eyebrow">Website Settings</div><h1>Stonefellows Public Site</h1><p>Manage public contact information, hours and social links here. Menu pages read the canonical menu database directly; active locations continue to come from the Locations table.</p>
<?php if ($saved): ?><div class="notice">Public site settings saved.</div><?php endif; ?><?php if ($saveError !== ''): ?><div class="error"><?= app_escape($saveError) ?></div><?php endif; ?><?php if ($errors): ?><div class="error"><?php foreach ($errors as $error): ?><div><?= app_escape($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post" action="public-site-settings.php"><input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>"><div class="grid">
<section class="card wide"><h2>Public Identity + Hours</h2><div class="fields"><div class="field full"><label for="tagline">Public tagline</label><input id="tagline" name="tagline" maxlength="255" value="<?= app_escape((string)$settings['tagline']) ?>"></div><div class="field full"><label for="hours_text">Hours</label><textarea id="hours_text" name="hours_text" placeholder="Sun–Thu · 11 AM–10 PM&#10;Fri–Sat · 11 AM–12 AM"><?= app_escape((string)$settings['hours_text']) ?></textarea></div></div></section>
<section class="card"><h2>Address</h2><div class="fields"><div class="field full"><label for="address_line_1">Address</label><input id="address_line_1" name="address_line_1" maxlength="200" value="<?= app_escape((string)$settings['address_line_1']) ?>"></div><div class="field full"><label for="address_line_2">Suite / Unit</label><input id="address_line_2" name="address_line_2" maxlength="200" value="<?= app_escape((string)$settings['address_line_2']) ?>"></div><div class="field"><label for="city">City</label><input id="city" name="city" maxlength="100" value="<?= app_escape((string)$settings['city']) ?>"></div><div class="field"><label for="state">State</label><input id="state" name="state" maxlength="100" value="<?= app_escape((string)$settings['state']) ?>"></div><div class="field"><label for="postal_code">ZIP / Postal</label><input id="postal_code" name="postal_code" maxlength="30" value="<?= app_escape((string)$settings['postal_code']) ?>"></div></div></section>
<section class="card"><h2>Contact</h2><div class="fields"><div class="field full"><label for="phone">Phone</label><input id="phone" name="phone" maxlength="40" value="<?= app_escape((string)$settings['phone']) ?>"></div><div class="field full"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="254" value="<?= app_escape((string)$settings['email']) ?>"></div></div></section>
<section class="card wide"><h2>Social Media</h2><div class="fields"><?php foreach (['instagram_url'=>'Instagram','facebook_url'=>'Facebook','tiktok_url'=>'TikTok','youtube_url'=>'YouTube','x_url'=>'X'] as $key=>$label): ?><div class="field"><label for="<?= app_escape($key) ?>"><?= app_escape($label) ?></label><input id="<?= app_escape($key) ?>" name="<?= app_escape($key) ?>" type="url" maxlength="500" placeholder="https://" value="<?= app_escape((string)$settings[$key]) ?>"></div><?php endforeach; ?></div></section>
<section class="card wide"><h2>Canonical Locations</h2><?php if ($locations): foreach ($locations as $location): ?><div class="location"><strong><?= app_escape((string)$location['name']) ?></strong><div><?= app_escape(public_site_format_address($location)) ?></div></div><?php endforeach; else: ?><p>No active Locations records are configured yet.</p><?php endif; ?></section></div><div class="actions"><button class="btn primary" type="submit">Save Public Site Settings</button><a class="btn" href="public/index.php" target="_blank" rel="noopener">Preview Site</a></div></form></div></main></body></html>
