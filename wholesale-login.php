<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
if(app_current_user()){
    $user=app_current_user();
    if($user && app_has_permission('wholesale_portal.view',$user))app_redirect('wholesale-portal.php');
    app_redirect('index.php');
}
app_redirect('login.php?return='.rawurlencode('wholesale-portal.php'));
