<?php
declare(strict_types=1);
require __DIR__.'/includes/user-accounts.php';
if(!app_has_config())app_redirect('setup-first-user.php');
app_boot_session();$pdo=app_pdo();
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
$token=trim((string)($_POST['token']??$_GET['token']??''));$error=null;$invite=null;
try {
    if(!accounts_schema_ready($pdo))throw new InvalidArgumentException('Account invitations are unavailable until the restaurant upgrade is complete.');
    $invite=accounts_invitation_preview($pdo,$token);
    if(!$invite)$error='This invitation is invalid, expired, or already used.';
    if($_SERVER['REQUEST_METHOD']==='POST'&&$invite) {
        if(!app_verify_csrf($_POST['csrf_token']??null))throw new InvalidArgumentException('The invitation form expired. Refresh and try again.');
        $password=(string)($_POST['password']??'');
        if($invite['user_status']==='invited'&&$password!==(string)($_POST['password_confirm']??''))
            throw new InvalidArgumentException('The passwords do not match.');
        $accepted=accounts_accept_invitation($pdo,$token,$password);
        app_session_start_user($pdo,$accepted['organizationId'],$accepted['userId'],'account_invitation',$accepted['passwordHash']);
        app_redirect('workspace.php');
    }
} catch(Throwable $e) {
    $error=$e instanceof InvalidArgumentException||$e instanceof DomainException?$e->getMessage():'The invitation could not be accepted. Contact the restaurant.';
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Accept restaurant invitation</title><link rel="stylesheet" href="css/public.css"></head>
<body><main class="auth-shell"><section class="auth-brand-panel"><div><p class="eyebrow">Gelato workspace</p>
<h1>Your restaurant account.</h1><p>Accept your invitation to join the team. Your password stays private.</p></div></section>
<section class="auth-form-panel"><div class="auth-card"><h2>Accept invitation</h2>
<?php if($error):?><div class="form-message bad" role="alert"><?=app_escape($error)?></div><?php endif;?>
<?php if($invite):?><p><strong><?=app_escape($invite['organization_name'])?></strong> invited <strong><?=app_escape($invite['email'])?></strong> as <?=app_escape($invite['role_name'])?>.</p>
<p><?=$invite['user_status']==='active'?'Enter your existing account password to accept.':'Choose a password with at least 12 characters, uppercase, lowercase, and a number.'?></p>
<form method="post"><input type="hidden" name="csrf_token" value="<?=app_escape(app_csrf_token())?>"><input type="hidden" name="token" value="<?=app_escape($token)?>">
<div class="field-wrap"><label for="password"><?=$invite['user_status']==='active'?'Existing password':'Choose password'?></label>
<input class="field" id="password" name="password" type="password" required autocomplete="<?=$invite['user_status']==='active'?'current-password':'new-password'?>"></div>
<?php if($invite['user_status']==='invited'):?><div class="field-wrap"><label for="password_confirm">Confirm password</label><input class="field" id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password"></div><?php endif;?>
<button class="submit-button" type="submit">Accept invitation</button></form><?php endif;?>
<div class="auth-links"><a href="login.php">Sign in</a><a href="forgot-password.php">Forgot password</a></div>
</div></section></main></body></html>
