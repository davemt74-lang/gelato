<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/food-monster-vp3-link.php';
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
$user=app_current_user();
if(!$user||!fm_kb2_owner($user)){
    http_response_code(403);
    exit('Only the authorized Food Monster owner can manage VP3 connections.');
}
if(!fm_kb2_https($_SERVER)){
    http_response_code(403);
    exit('Secure HTTPS is required to manage VP3 connections.');
}
$pdo=app_pdo();$error='';$notice='';$issued=null;
try{
    $instance=fm_kb2_instance($pdo);
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        if(!app_verify_csrf((string)($_POST['_csrf']??'')))
            throw new DomainException('Security token expired. Refresh the page.');
        $action=(string)($_POST['action']??'');
        if($action==='create'){
            $requested=[];
            if(isset($_POST['allow_menu']))$requested[]='menu';
            if(isset($_POST['allow_recipe']))$requested[]='recipe';
            $issued=fm_kb2_create($pdo,$user,(string)($_POST['label']??''),$requested);
            $notice='One-time pairing authorization generated. It expires in 10 minutes and is displayed only once.';
            app_audit($pdo,(int)$user['organization_id'],(int)$user['id'],
                'vp3.connection_approved','vp3_connection',$issued['id'],null,
                ['scopes'=>$issued['scopes']]);
        }elseif($action==='revoke'){
            $revoked=fm_kb2_revoke($pdo,$user,(string)($_POST['id']??''));
            $notice=$revoked?'Connection revoked. Its credential cannot be used again.':'Connection was not active or was already revoked.';
            if($revoked)app_audit($pdo,(int)$user['organization_id'],(int)$user['id'],
                'vp3.connection_revoked','vp3_connection',(string)$_POST['id'],null,null);
        }else throw new InvalidArgumentException('Unsupported action.');
    }
    $connections=fm_kb2_connections($pdo,$user);
}catch(Throwable $e){
    $error=$e instanceof DomainException||$e instanceof InvalidArgumentException
        ?$e->getMessage():'Unable to manage connections. Check upgrade.php.';
    $connections=[];
}
$allowed=fm_kb1_allowed_types($user);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>VP3 Connection Authorization — Food Monster</title>
<style>
:root{font-family:system-ui,sans-serif;color-scheme:dark;background:#111417;color:#e9f0ef}
body{max-width:860px;margin:auto;padding:clamp(16px,4vw,40px);line-height:1.5}
section{background:#1c2225;border:1px solid #384246;border-radius:16px;padding:24px;margin:22px 0}
h1{font-size:1.8rem}h2{font-size:1.2rem}p,small{color:#bac6c5}input[type=text]{background:#101617;border:1px solid #647574;border-radius:9px;color:inherit;padding:12px;width:min(100%,380px);box-sizing:border-box}
button{padding:11px 18px;border:0;border-radius:9px;color:#061a14;background:#85e2bf;font-weight:700;cursor:pointer}
button.danger{background:#f8aeaa;color:#381212}label{display:block;margin:12px 0}.code{font-family:ui-monospace,monospace;overflow-wrap:anywhere;color:#c0ffe6;background:#0c1d19;padding:18px;border-radius:10px;font-size:1rem}
.row{display:flex;gap:14px;flex-wrap:wrap;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid #384246}
.notice{color:#b2f7d8}.error{color:#ffb9b9}
</style></head><body>
<h1>VP3 HomeServer connection</h1>
<p>Authorize your private HomeServer to read selected Food Monster knowledge. Each connection is individually approved and revocable. Your restaurant database and account password are never shared.</p>
<p><strong>Instance:</strong> <?=app_escape($instance??'Not installed')?>. This page works on any HTTPS installation; the hostname is not hardcoded.</p>
<?php if($error!==''):?><p class="error" role="alert"><?=app_escape($error)?></p><?php endif;?>
<?php if($notice!==''):?><p class="notice" role="status"><?=app_escape($notice)?></p><?php endif;?>
<?php if($issued):?><section>
<h2>One-time pairing code</h2><p>Enter this code in the VP3 HomeServer connection flow. It grants only the approved knowledge scopes and can be redeemed just once.</p>
<div class="code"><?=app_escape((string)$issued['pairingCode'])?></div>
<p><small>Expires in 10 minutes. If lost or expired, create another authorization; never send this code in an email or URL.</small></p>
</section><?php endif;?>
<section><h2>Authorize HomeServer</h2>
<form method="post" autocomplete="off">
<input type="hidden" name="_csrf" value="<?=app_escape(app_csrf_token())?>">
<input type="hidden" name="action" value="create">
<label>Connection label <input type="text" name="label" maxlength="80" required placeholder="My VP3 HomeServer"></label>
<?php if(in_array('menu',$allowed,true)):?><label><input type="checkbox" name="allow_menu" value="1" checked> Allow menu and staff-facing preparation knowledge</label><?php endif;?>
<?php if(in_array('recipe',$allowed,true)):?><label><input type="checkbox" name="allow_recipe" value="1"> Allow private recipes and production standards</label><?php endif;?>
<p><small>Read-only access. Employee records, financial data, conversations, and write operations are not included.</small></p>
<button type="submit">Approve and generate pairing code</button>
</form></section>
<section><h2>Existing HomeServer connections</h2>
<?php if(!$connections):?><p>No connections yet.</p><?php endif;?>
<?php foreach($connections as $row):?>
<div class="row"><div><strong><?=app_escape($row['label'])?></strong>
<small> · <?=app_escape($row['status'])?></small>
<div><small>Scopes: <?=app_escape(implode(', ',$row['scopes']))?></small></div>
<div><small>Last used: <?=app_escape($row['last_used_at']??'Never')?></small></div></div>
<?php if($row['status']!=='revoked'):?>
<form method="post" autocomplete="off">
<input type="hidden" name="_csrf" value="<?=app_escape(app_csrf_token())?>">
<input type="hidden" name="action" value="revoke">
<input type="hidden" name="id" value="<?=app_escape($row['public_id'])?>">
<button type="submit" class="danger">Revoke</button></form><?php endif;?></div>
<?php endforeach;?>
</section></body></html>
