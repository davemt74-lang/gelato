<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/glasses-customer-photo-notifications.php';
function assertion(bool $value,string $why):void{
    if(!$value){fwrite(STDERR,"FAIL Section 260: ".$why."\n");exit(1);}
}
$good=['orderStatus'=>'ready','locationEnabled'=>true,'customerOptedIn'=>true,'alreadyNotified'=>false,'approvedPhotos'=>1];
assertion(glasses_customer_photo_delivery_gate($good)==='eligible','eligible approved ready order');
foreach(['ready','kitchen_complete','completed'] as $state){
    assertion(glasses_customer_photo_delivery_gate(array_replace($good,['orderStatus'=>$state]))==='eligible','valid lifecycle milestone '.$state);
}
foreach(['submitted','preparing','cancelled','unknown'] as $state){
    assertion(glasses_customer_photo_delivery_gate(array_replace($good,['orderStatus'=>$state]))==='order_not_ready','non-ready order must not send '.$state);
}
assertion(glasses_customer_photo_delivery_gate(array_replace($good,['locationEnabled'=>false]))==='location_off','restaurant setting off');
assertion(glasses_customer_photo_delivery_gate(array_replace($good,['customerOptedIn'=>false]))==='customer_not_opted_in','customer has not consented');
assertion(glasses_customer_photo_delivery_gate(array_replace($good,['alreadyNotified'=>true]))==='already_notified','duplicate must not deliver');
assertion(glasses_customer_photo_delivery_gate(array_replace($good,['approvedPhotos'=>0]))==='no_approved_photos','unapproved stage cannot send');
assertion(glasses_customer_photo_delivery_gate(array_replace($good,['approvedPhotos'=>-1]))==='no_approved_photos','negative images not accepted');
echo "section260-customer-photo-delivery-gates=ok\n";
