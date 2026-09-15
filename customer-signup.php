<?php
declare(strict_types=1);
require_once __DIR__.'/includes/public-site.php';
require_once __DIR__.'/includes/customer-account-core.php';

app_boot_session();
$pdo=app_pdo();
$context=public_site_context($pdo);
$organizationId=(int)$context['organizationId'];
$settings=$context['settings'];
$return=customer_account_safe_return($_GET['return']??$_POST['return']??'customer-account.php');
$error=null;

try{
    if(customer_account_current($pdo,$organizationId)) app_redirect($return);
}catch(Throwable){ }

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!app_verify_csrf($_POST['csrf_token']??null)){
        $error='The signup form expired. Refresh the page and try again.';
    }else{
        try{
            $result=customer_account_register($pdo,$organizationId,[
                'firstName'=>$_POST['first_name']??'',
                'lastName'=>$_POST['last_name']??'',
                'email'=>$_POST['email']??'',
                'phone'=>$_POST['phone']??'',
                'password'=>$_POST['password']??'',
                'passwordConfirm'=>$_POST['password_confirm']??'',
                'emailMarketing'=>isset($_POST['email_marketing']),
                'smsMarketing'=>isset($_POST['sms_marketing']),
            ]);
            customer_account_start_session($pdo,$organizationId,(int)$result['userId']);
            app_redirect($return);
        }catch(Throwable $exception){
            $error=$exception instanceof InvalidArgumentException?$exception->getMessage():'Customer signup is temporarily unavailable. Please try again.';
            error_log('Customer signup failed: '.$exception->getMessage());
        }
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0b0b09"><meta name="robots" content="index,follow"><meta name="description" content="Create your <?=app_escape((string)$settings['restaurant_name'])?> customer account for faster ordering and order history."><title>Create Account | <?=app_escape((string)$settings['restaurant_name'])?></title><link rel="stylesheet" href="assets/css/site.css?v=20260914-2"><link rel="stylesheet" href="assets/css/customer-account.css?v=20260914-1"></head><body class="customer-page">
<?php public_site_render_header($settings,''); ?>
<main class="customer-main"><div class="shell customer-auth-grid"><section class="customer-intro"><div class="eyebrow">Your Stonefellows Account</div><h1>Order faster.<br>Come back easier.</h1><p>Create one customer account for online ordering, order history and future rewards. Your account is linked directly to the restaurant customer CRM, so your in-store and online relationship can stay together.</p><div class="customer-points"><div class="customer-point"><strong>One customer profile</strong><span>Your account links to the same CRM profile used by the restaurant instead of creating a disconnected online identity.</span></div><div class="customer-point"><strong>Pickup + delivery ready</strong><span>Your account includes online-ordering access for the ordering flow we are building next.</span></div><div class="customer-point"><strong>Your marketing choice</strong><span>Email and SMS marketing are optional and recorded separately from your account access.</span></div></div></section><section class="customer-card"><h2>Create your account</h2><p>Use an email address you can access. If the restaurant already knows you by that email, signup links to that existing customer profile.</p><form class="customer-form" method="post" autocomplete="on"><input type="hidden" name="csrf_token" value="<?=app_escape(app_csrf_token())?>"><input type="hidden" name="return" value="<?=app_escape($return)?>"><?php if($error):?><div class="customer-message bad"><?=app_escape($error)?></div><?php endif;?><div class="customer-field"><label for="first_name">First name</label><input id="first_name" name="first_name" maxlength="100" autocomplete="given-name" value="<?=app_escape($_POST['first_name']??'')?>" required></div><div class="customer-field"><label for="last_name">Last name</label><input id="last_name" name="last_name" maxlength="100" autocomplete="family-name" value="<?=app_escape($_POST['last_name']??'')?>" required></div><div class="customer-field wide"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="254" autocomplete="email" value="<?=app_escape($_POST['email']??'')?>" required></div><div class="customer-field wide"><label for="phone">Mobile phone <span style="text-transform:none;font-weight:500">(optional)</span></label><input id="phone" name="phone" type="tel" maxlength="40" autocomplete="tel" value="<?=app_escape($_POST['phone']??'')?>"></div><div class="customer-field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required></div><div class="customer-field"><label for="password_confirm">Confirm password</label><input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" minlength="12" required></div><label class="customer-check"><input type="checkbox" name="email_marketing" value="1" <?=isset($_POST['email_marketing'])?'checked':''?>><span>Email me restaurant news, offers and updates. This is optional and can be changed later.</span></label><label class="customer-check"><input type="checkbox" name="sms_marketing" value="1" <?=isset($_POST['sms_marketing'])?'checked':''?>><span>Text me restaurant news, offers and updates. A mobile number is required. This is optional.</span></label><button class="customer-submit" type="submit">Create customer account</button><div class="customer-switch">Already have an account? <a href="customer-login.php?return=<?=rawurlencode($return)?>">Sign in</a></div></form><div class="customer-security">Passwords are stored as secure password hashes. Marketing consent is append-only in the CRM consent history and is not required to place an order.</div></section></div></main>
<?php public_site_render_footer($settings); ?><script src="assets/js/site.js?v=20260914"></script><script src="assets/js/public-shell.js?v=20260915-1"></script>
</body></html>
