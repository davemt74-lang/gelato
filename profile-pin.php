<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
$user=app_require_auth();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Employee PIN & Face Sign-in · Gelato</title><style>
body{background:#0b1d2c;color:#e9f7ff;font:15px/1.55 system-ui;margin:0}.pin-page{max-width:740px;margin:4vh auto;padding:24px}.pin-card{background:#153249;border:1px solid #426071;border-radius:18px;padding:22px;margin:18px 0}h1,h2{margin:0 0 10px}label{display:block;font-weight:650;margin:14px 0 4px}input:not([type=checkbox]){display:block;width:100%;box-sizing:border-box;border-radius:8px;padding:11px;border:1px solid #9cc6d4;color:#102b40;background:white;font:inherit}button{border:0;background:#33c5e3;color:#071d2e;border-radius:10px;padding:12px 16px;font-weight:750;margin:12px 6px 0 0;cursor:pointer}a{color:#86e7ff}small{color:#b8cfdb}video{max-width:100%;width:460px;border-radius:14px;background:#08131d;transform:scaleX(-1)}[hidden]{display:none!important}#fm-pin-status{min-height:1.5em}
</style></head><body><main class="pin-page">
<a href="food-monster.php">← Back to Food Monster</a><h1>Employee PIN and Face Sign-in</h1><p>This is your existing Gelato account. Your photo only selects your account on an approved terminal; your PIN authorizes sign-in. A face never signs in by itself.</p>
<section class="pin-card"><h2>1. Set or change your PIN</h2><p>Use your existing Gelato password to authorize PIN changes. Avoid common patterns; PINs are hashed server-side.</p>
<form id="fm-pin-set"><label>Current password<input type="password" name="currentPassword" autocomplete="current-password" required></label><label>Choose six-digit PIN<input type="password" name="pin" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="new-password" required></label><label>Confirm PIN<input type="password" name="confirmPin" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="new-password" required></label><button type="submit">Save my PIN</button></form></section>
<?php if((int)($user['is_owner_role']??0)===1): ?>
<section class="pin-card"><h2>2. Register this terminal (Super Admin)</h2><p>Trust only a managed on-site browser. Employees will enroll their own face using their signed-in accounts. Registration expires after 180 days.</p>
<form id="fm-pin-trust"><label>Terminal name<input name="label" placeholder="Front counter tablet" maxlength="100" required></label><label>Super Admin password<input name="currentPassword" type="password" autocomplete="current-password" required></label><button type="submit">Trust this terminal</button></form><button id="fm-pin-revoke" type="button">Revoke this terminal</button></section>
<?php else: ?>
<section class="pin-card"><h2>2. Enroll your face on a trusted terminal</h2><p>After a Super Admin has registered this browser, consent to face enrollment. Three samples become a device-local encrypted template; no face data is sent to Gelato's server. Clear browser data or revoke to remove it.</p>
<label><input id="fm-face-consent" type="checkbox"> I consent to on-device face enrollment</label>
<video id="fm-face-preview" muted autoplay playsinline hidden></video><div><button type="button" id="fm-face-enroll">Enable camera and enroll my face</button><button type="button" id="fm-face-remove">Remove my face from this terminal</button></div></section>
<?php endif; ?>
<p id="fm-pin-status" role="status" aria-live="polite">Loading terminal status…</p></main><script src="js/fm-face-pin.js?v=20261015-fmg8bpin"></script></body></html>
