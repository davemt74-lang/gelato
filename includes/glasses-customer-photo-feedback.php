<?php
declare(strict_types=1);
require_once __DIR__.'/glasses-customer-photo-notifications.php';

function glasses_customer_photo_feedback_ready(PDO $pdo):bool
{
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()
        AND table_name='glasses_customer_photo_feedback'");
    return (int)$q->fetchColumn()===1;
}
function glasses_customer_photo_feedback_validate(int $rating,string $comment):array
{
    if($rating<1||$rating>5)throw new InvalidArgumentException('Choose a rating from 1–5.');
    $comment=trim($comment);
    if(mb_strlen($comment,'UTF-8')>500)
        throw new InvalidArgumentException('Please keep the review below 500 characters.');
    return ['rating'=>$rating,'comment'=>$comment];
}
function glasses_customer_photo_feedback_list(PDO $pdo,int $org,int $customerId,array $visible):array
{
    if(!glasses_customer_photo_feedback_ready($pdo)||!$visible)return [];
    $ids=array_values(array_unique(array_map(static fn(array $photo):int=>(int)$photo['shareId'],$visible)));
    if(!$ids)return [];
    $ids=array_slice($ids,0,30);
    $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare("SELECT share_id,rating,comment,updated_at
       FROM glasses_customer_photo_feedback WHERE organization_id=? AND customer_id=?
           AND share_id IN ($placeholders)");
    $q->execute(array_merge([$org,$customerId],$ids));
    $result=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $result[(int)$row['share_id']]=['rating'=>(int)$row['rating'],
            'comment'=>(string)($row['comment']??''),'updatedAt'=>(string)$row['updated_at']];
    }
    return $result;
}
function glasses_customer_photo_feedback_save(PDO $pdo,int $org,int $customerId,
    string $orderPublic,int $shareId,int $rating,string $comment):array
{
    if(!glasses_customer_photo_feedback_ready($pdo))
        throw new RuntimeException('Photo feedback requires the latest database upgrade.');
    $v=glasses_customer_photo_feedback_validate($rating,$comment);
    if($shareId<1||$orderPublic===''||strlen($orderPublic)>120)
        throw new InvalidArgumentException('Select an approved order photograph.');
    // Membership in the currently visible list re-checks every consent, owner,
    // stage, active-media, retention and exact-photo approval gate.
    $visible=glasses_customer_photo_visible($pdo,$org,$customerId,$orderPublic);
    if(!array_filter($visible,static fn(array $p):bool=>(int)$p['shareId']===$shareId))
        throw new DomainException('This photo is not available for review.');
    $pdo->prepare("INSERT INTO glasses_customer_photo_feedback
        (organization_id,customer_id,share_id,rating,comment) VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE rating=VALUES(rating),comment=VALUES(comment),updated_at=NOW(6)")
        ->execute([$org,$customerId,$shareId,$v['rating'],$v['comment']]);
    try{app_audit($pdo,$org,null,'customer.order_photo_feedback','photo_share',(string)$shareId,null,
        ['rating'=>$v['rating'],'commentLength'=>mb_strlen($v['comment'],'UTF-8')]);}
    catch(Throwable $e){error_log('[customer-photo-feedback-audit] '.$e->getMessage());}
    return ['saved'=>true,'rating'=>$v['rating']];
}
