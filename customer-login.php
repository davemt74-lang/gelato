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
        $error='The login form expired. Refresh the page and try again.';
    }else{
        try{
            customer_account_authenticate($pdo,$organizationId,(string)($_POST['email']??''),(string)($_POST['password']??''));
            app_redirect($return);
        }catch(Throwable $exception){
            $error=$exception instanceof InvalidArgumentException?$exception->getMessage():'Customer login is temporarily unavailable.';
        }
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0b0b09"><title>Customer Sign In | <?=app_escape((string)$settings['restaurant_name'])?></title><link rel="stylesheet" href="assets/css/site.css?v=20260914-2"><link rel="stylesheet" href="assets/css/customer-account.css?v=20260914-1"></head><body class="customer-page">
<?php public_site_render_header($settings,''); ?>
<main class="customer-main"><div class="shell customer-auth-grid"><section class="customer-intro"><div class="eyebrow">Customer Account</div><h1>Welcome back.</h1><p>Sign in to your customer account for order history and the upcoming online ordering experience.</p><div class="customer-points"><div class="customer-point"><strong>Connected history</strong><span>Orders attached to your CRM customer profile can appear in the same account.</span></div><div class="customer-point"><strong>Pickup + delivery</strong><span>Your Customer role already carries the permission used by the online-ordering flow.</span></div><div class="customer-point"><strong>Separate from staff</strong><span>This login is customer-facing and does not grant POS, CRM management or restaurant administration access.</span></div></div></section><section class="customer-card"><h2>Sign in</h2><p>Use the email and password associated with your customer account.</p><form class="customer-form" method="post" autocomplete="on"><input type="hidden" name="csrf_token" value="<?=app_escape(app_csrf_token())?>"><input type="hidden" name="return" value="<?=app_escape($return)?>"><?php if($error):?><div class="customer-message bad"><?=app_escape($error)?></div><?php endif;?><div class="customer-field wide"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" maxlength="254" value="<?=app_escape($_POST['email']??'')?>" required autofocus></div><div class="customer-field wide"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div><button class="customer-submit" type="submit">Sign in to customer account</button><div class="customer-switch"><a href="forgot-password.php">Forgot password?</a> &nbsp;·&nbsp; New here? <a href="customer-signup.php">Create an account</a></div></form><div class="customer-security">Customer access is scoped to the current restaurant organization and requires the Customer account type.</div></section></div></main>
<?php public_site_render_footer($settings); ?><script src="assets/js/site.js?v=20260914"></script></body></html>
