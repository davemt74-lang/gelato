<?php
declare(strict_types=1);
require_once __DIR__.'/includes/public-site.php';
require_once __DIR__.'/includes/customer-account-core.php';

app_boot_session();
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
$pdo=app_pdo();
$context=public_site_context($pdo);
$organizationId=(int)$context['organizationId'];
$settings=$context['settings'];
$token=(string)($_POST['token']??$_GET['token']??'');
$error=null;
$request=null;
try {
    $request=customer_account_verification_request($pdo,$organizationId,$token);
    if(!$request) $error='This verification link is invalid, expired, or already used. Request another email.';
    if($_SERVER['REQUEST_METHOD']==='POST' && $request) {
        if(!app_verify_csrf($_POST['csrf_token']??null)) {
            $error='The confirmation form expired. Refresh the page and try again.';
        } else {
            $result=customer_account_complete_registration($pdo,$organizationId,$token,[
                'password'=>(string)($_POST['password']??''),
                'passwordConfirm'=>(string)($_POST['password_confirm']??''),
                'emailMarketing'=>isset($_POST['email_marketing']),
                'smsMarketing'=>isset($_POST['sms_marketing']),
            ]);
            customer_account_start_session($pdo,$organizationId,(int)$result['userId'],(string)$result['expectedPasswordHash']);
            app_redirect((string)$result['return']);
        }
    }
} catch(Throwable $e) {
    $error=$e instanceof InvalidArgumentException?$e->getMessage():'Email verification is temporarily unavailable. Please try again or sign in if your account was already confirmed.';
    error_log('Customer email verification failed: '.get_class($e));
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">
  <title>Verify Email | <?=app_escape((string)$settings['restaurant_name'])?></title>
  <link rel="stylesheet" href="assets/css/site.css?v=20260914-2">
  <link rel="stylesheet" href="assets/css/customer-account.css?v=20260914-1">
</head>
<body class="customer-page">
<?php public_site_render_header($settings,''); ?>
<main class="customer-main"><div class="shell customer-auth-grid">
  <section class="customer-intro"><div class="eyebrow">Customer Account</div><h1>Confirm your<br>email and account.</h1>
    <p>Only confirm an account you requested. Choose your own password for a new account. If this email already has an account, enter that account's current password to connect this restaurant.</p>
    <p>Your customer profile is connected only after this confirmation succeeds.</p>
  </section>
  <section class="customer-card"><h2>Complete your account</h2>
    <?php if($error):?><div class="customer-message bad" role="alert"><?=app_escape($error)?></div><?php endif;?>
    <?php if($request):?>
      <p><?=app_escape((string)$request['first_name'].' '.(string)$request['last_name'])?><br><?=app_escape((string)$request['email'])?></p>
      <?php if($request['phone']):?><p>Phone: <?=app_escape((string)$request['phone'])?></p><?php endif;?>
      <form class="customer-form" method="post">
        <input type="hidden" name="csrf_token" value="<?=app_escape(app_csrf_token())?>">
        <input type="hidden" name="token" value="<?=app_escape($token)?>">
        <div class="customer-field wide"><label for="password">New or existing account password</label><input id="password" name="password" type="password" maxlength="200" autocomplete="new-password" required><small>New passwords need 12 to 200 characters, uppercase, lowercase, a number, and a symbol.</small></div>
        <div class="customer-field wide"><label for="password_confirm">Confirm password</label><input id="password_confirm" name="password_confirm" type="password" maxlength="200" autocomplete="new-password" required></div>
        <?php if(!empty($request['email_marketing'])):?><label class="customer-check"><input type="checkbox" name="email_marketing" value="1"><span>Also subscribe me to restaurant email updates. Optional.</span></label><?php endif;?>
        <?php if(!empty($request['sms_marketing'])):?><label class="customer-check"><input type="checkbox" name="sms_marketing" value="1"><span>Also subscribe me to restaurant text updates at the phone number above. Optional.</span></label><?php endif;?>
        <button class="customer-submit" type="submit">Verify email and continue</button>
      </form>
    <?php endif;?>
    <div class="customer-switch"><a href="customer-signup.php">Request another verification email</a> · <a href="customer-login.php">Sign in</a> · <a href="forgot-password.php">Reset password</a></div>
  </section>
</div></main>
<?php public_site_render_footer($settings); ?>
</body></html>
