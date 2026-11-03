<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$mode=(string)($argv[1]??'');
if($mode==='mail') {
    $target=(string)getenv('GELATO_CUSTOMER_MAIL_CAPTURE');
    if($target==='' || getenv('GELATO_CUSTOMER_MAIL_FAIL')==='1')exit(1);
    file_put_contents($target,(string)file_get_contents('php://stdin'),LOCK_EX);
    chmod($target,0600);exit(0);
}
require __DIR__.'/../includes/public-site.php';
require __DIR__.'/../includes/customer-account-core.php';
$pdo=app_pdo();$org=public_site_organization_id($pdo);
if($mode==='seed') {
    $suffix=bin2hex(random_bytes(8));$email='customer-http-'.$suffix.'@example.test';$public='customer-http-'.$suffix;
    $pdo->prepare("INSERT INTO crm_customers (organization_id,public_id,first_name,last_name,display_name,email,email_normalized,phone,phone_normalized,status,source) VALUES (?,?,?,?,?,?,?,?,?,'active','pos')")
        ->execute([$org,$public,'Private','Customer','Private Customer',$email,$email,'6024440198','6024440198']);
    echo json_encode(['email'=>$email,'publicId'=>$public,'org'=>$org],JSON_THROW_ON_ERROR);exit;
}
if($mode==='legacy-unverified') {
    $email=(string)($argv[2]??'');
    $pdo->prepare('UPDATE users SET email_verified_at=NULL WHERE email=?')->execute([$email]);
    $pdo->prepare('UPDATE customer_account_verifications SET last_requested_at=DATE_SUB(NOW(6),INTERVAL 61 SECOND) WHERE organization_id=? AND email=?')->execute([$org,$email]);
    echo json_encode(['unverified'=>true],JSON_THROW_ON_ERROR);exit;
}
if($mode==='inspect') {
    $email=(string)($argv[2]??'');
    $q=$pdo->prepare('SELECT user_id,display_name,phone FROM crm_customers WHERE organization_id=? AND email_normalized=?');$q->execute([$org,$email]);$crm=$q->fetch();
    $q=$pdo->prepare('SELECT COUNT(*) FROM users WHERE email=?');$q->execute([$email]);
    echo json_encode(['crm'=>$crm,'users'=>(int)$q->fetchColumn()],JSON_THROW_ON_ERROR);exit;
}
throw new RuntimeException('Unknown fixture mode.');
