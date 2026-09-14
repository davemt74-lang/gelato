<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';

app_boot_session();
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}
if(!app_verify_csrf($_POST['csrf_token']??null)){
    http_response_code(403);
    exit('The sign-out request expired.');
}
$_SESSION=[];
if(ini_get('session.use_cookies')){
    $params=session_get_cookie_params();
    setcookie(session_name(),'',time()-42000,$params['path'],$params['domain']??'',(bool)$params['secure'],(bool)$params['httponly']);
}
session_destroy();
header('Location: customer-login.php',true,303);
exit;
