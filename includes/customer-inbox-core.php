<?php
declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/customer-crm-core.php';

function customer_inbox_ready(PDO $pdo): bool
{
    foreach (['customer_inbox_messages','customer_inbox_recipients','customer_inbox_preferences'] as $table) {
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        if ((int)$q->fetchColumn()!==1) return false;
    }
    return true;
}

function customer_inbox_public_id(string $prefix='inbox'): string
{
    return $prefix.'-'.bin2hex(random_bytes(12));
}

function customer_inbox_transaction(PDO $pdo, callable $work): mixed
{
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $result=$work();
        if($owns)$pdo->commit();
        return $result;
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function customer_inbox_safe_url(?string $value): ?string
{
    $value=trim((string)$value);
    if($value==='') return null;
    if(strlen($value)>500 || str_contains($value,'..') || str_starts_with($value,'//') || preg_match('/^[a-z][a-z0-9+.-]*:/i',$value)) return null;
    return preg_match('/^[A-Za-z0-9_\/.?=&%#-]+$/',$value)?$value:null;
}

function customer_inbox_preferences(PDO $pdo,int $organizationId,int $customerId): array
{
    $q=$pdo->prepare('SELECT promotions_enabled,updated_at FROM customer_inbox_preferences WHERE organization_id=? AND customer_id=? LIMIT 1');
    $q->execute([$organizationId,$customerId]);
    $row=$q->fetch();
    return [
        'promotionsEnabled'=>$row?((bool)$row['promotions_enabled']):true,
        'updatedAt'=>$row?(string)$row['updated_at']:null,
    ];
}

function customer_inbox_preferences_save(PDO $pdo,int $organizationId,int $customerId,bool $promotionsEnabled,?int $userId): array
{
    $q=$pdo->prepare("SELECT id FROM crm_customers WHERE organization_id=? AND id=? AND status='active' LIMIT 1");
    $q->execute([$organizationId,$customerId]);
    if(!(int)$q->fetchColumn()) throw new InvalidArgumentException('Customer profile was not found.');
    $pdo->prepare('INSERT INTO customer_inbox_preferences (organization_id,customer_id,promotions_enabled,updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE promotions_enabled=VALUES(promotions_enabled),updated_by=VALUES(updated_by),updated_at=NOW(6)')
        ->execute([$organizationId,$customerId,$promotionsEnabled?1:0,$userId]);
    try{app_audit($pdo,$organizationId,$userId,'customer.inbox_preferences','crm_customer',(string)$customerId,null,['promotionsEnabled'=>$promotionsEnabled]);}catch(Throwable){}
    return customer_inbox_preferences($pdo,$organizationId,$customerId);
}

function customer_inbox_create_message(PDO $pdo,int $organizationId,array $input,?int $actorUserId): array
{
    if(!customer_inbox_ready($pdo)) throw new RuntimeException('Customer Inbox is not installed. Run Upgrade first.');
    $type=strtolower(trim((string)($input['messageType']??'promotion')));
    if(!in_array($type,['promotion','order_update','reward','announcement'],true)) throw new InvalidArgumentException('Unsupported customer inbox message type.');
    $title=mb_substr(trim((string)($input['title']??'')),0,180,'UTF-8');
    $preview=mb_substr(trim((string)($input['previewText']??'')),0,320,'UTF-8');
    $body=mb_substr(trim((string)($input['bodyText']??'')),0,12000,'UTF-8');
    if($title===''||$body==='') throw new InvalidArgumentException('Promotion title and message are required.');
    $ctaLabel=mb_substr(trim((string)($input['ctaLabel']??'')),0,80,'UTF-8');
    $ctaUrl=customer_inbox_safe_url($input['ctaUrl']??null);
    if($ctaLabel!==''&&$ctaUrl===null) throw new InvalidArgumentException('CTA links must stay inside the Stonefellows site.');
    if($ctaLabel===''&&$ctaUrl!==null) $ctaLabel='View';
    $promoCode=mb_substr(trim((string)($input['promoCode']??'')),0,80,'UTF-8');
    $locationId=max(0,(int)($input['locationId']??0));
    if($locationId>0){
        $q=$pdo->prepare("SELECT id FROM locations WHERE organization_id=? AND id=? AND status='active' LIMIT 1");
        $q->execute([$organizationId,$locationId]);
        if(!(int)$q->fetchColumn()) throw new InvalidArgumentException('Promotion location was not found.');
    }
    $expiresAt=null;
    $rawExpires=trim((string)($input['expiresAt']??''));
    if($rawExpires!==''){
        try{$expires=(new DateTimeImmutable($rawExpires));}catch(Throwable){throw new InvalidArgumentException('Promotion expiration date is invalid.');}
        if($expires<=new DateTimeImmutable('now')) throw new InvalidArgumentException('Promotion expiration must be in the future.');
        $expiresAt=$expires->format('Y-m-d H:i:s.u');
    }
    $public=customer_inbox_public_id($type==='promotion'?'promo':'message');
    $pdo->prepare("INSERT INTO customer_inbox_messages (organization_id,location_id,public_id,message_type,title,preview_text,body_text,cta_label,cta_url,promo_code,status,starts_at,expires_at,created_by,sent_by,sent_at) VALUES (?,?,?,?,?,?,?,?,?,?,'sent',NOW(6),?,?,?,NOW(6))")
        ->execute([$organizationId,$locationId?:null,$public,$type,$title,$preview?:null,$body,$ctaLabel?:null,$ctaUrl,$promoCode?:null,$expiresAt,$actorUserId,$actorUserId]);
    return [
        'id'=>(int)$pdo->lastInsertId(),'publicId'=>$public,'messageType'=>$type,'title'=>$title,'previewText'=>$preview?:null,
        'bodyText'=>$body,'ctaLabel'=>$ctaLabel?:null,'ctaUrl'=>$ctaUrl,'promoCode'=>$promoCode?:null,'locationId'=>$locationId?:null,'expiresAt'=>$expiresAt,
    ];
}

function customer_inbox_send_direct(PDO $pdo,int $organizationId,int $customerId,array $input,?int $actorUserId=null): array
{
    return customer_inbox_transaction($pdo,function()use($pdo,$organizationId,$customerId,$input,$actorUserId):array{
        $q=$pdo->prepare("SELECT id FROM crm_customers WHERE organization_id=? AND id=? AND status='active' LIMIT 1");
        $q->execute([$organizationId,$customerId]);
        if(!(int)$q->fetchColumn()) throw new InvalidArgumentException('Customer profile was not found.');
        $message=customer_inbox_create_message($pdo,$organizationId,$input,$actorUserId);
        $pdo->prepare('INSERT INTO customer_inbox_recipients (organization_id,message_id,customer_id) VALUES (?,?,?)')
            ->execute([$organizationId,$message['id'],$customerId]);
        return $message+['recipientCount'=>1];
    });
}

function customer_inbox_send_promotion(PDO $pdo,int $organizationId,array $input,int $actorUserId): array
{
    return customer_inbox_transaction($pdo,function()use($pdo,$organizationId,$input,$actorUserId):array{
        $tagId=max(0,(int)($input['tagId']??0));
        if($tagId>0){
            $q=$pdo->prepare('SELECT id FROM crm_tags WHERE organization_id=? AND id=? LIMIT 1');
            $q->execute([$organizationId,$tagId]);
            if(!(int)$q->fetchColumn()) throw new InvalidArgumentException('Audience tag was not found.');
        }
        $message=customer_inbox_create_message($pdo,$organizationId,[...$input,'messageType'=>'promotion'],$actorUserId);
        $sql="INSERT IGNORE INTO customer_inbox_recipients (organization_id,message_id,customer_id)
              SELECT ?,?,c.id
              FROM crm_customers c
              JOIN users u ON u.id=c.user_id AND u.status='active' AND u.archived_at IS NULL
              JOIN organization_memberships om ON om.organization_id=c.organization_id AND om.user_id=c.user_id AND om.status='active'
              JOIN user_roles ur ON ur.membership_id=om.id AND ur.revoked_at IS NULL
              JOIN roles r ON r.id=ur.role_id AND r.organization_id=c.organization_id AND r.slug='customer'
              LEFT JOIN customer_inbox_preferences pref ON pref.organization_id=c.organization_id AND pref.customer_id=c.id
              WHERE c.organization_id=? AND c.status='active' AND COALESCE(pref.promotions_enabled,1)=1";
        $args=[$organizationId,$message['id'],$organizationId];
        if($tagId>0){
            $sql.=' AND EXISTS (SELECT 1 FROM crm_customer_tags ct WHERE ct.organization_id=c.organization_id AND ct.customer_id=c.id AND ct.tag_id=?)';
            $args[]=$tagId;
        }
        $pdo->prepare($sql)->execute($args);
        $q=$pdo->prepare('SELECT COUNT(*) FROM customer_inbox_recipients WHERE organization_id=? AND message_id=?');
        $q->execute([$organizationId,$message['id']]);
        $count=(int)$q->fetchColumn();
        if($count<1) throw new InvalidArgumentException('No eligible customer accounts are subscribed to inbox promotions for that audience.');
        try{app_audit($pdo,$organizationId,$actorUserId,'customer.promotion_sent','customer_inbox_message',$message['publicId'],null,['recipientCount'=>$count,'tagId'=>$tagId?:null,'locationId'=>$message['locationId']]);}catch(Throwable){}
        return $message+['recipientCount'=>$count,'tagId'=>$tagId?:null];
    });
}

function customer_inbox_messages(PDO $pdo,int $organizationId,int $customerId,int $limit=50): array
{
    if(!customer_inbox_ready($pdo)) return [];
    $limit=max(1,min(100,$limit));
    $q=$pdo->prepare("SELECT m.public_id,m.message_type,m.title,m.preview_text,m.body_text,m.cta_label,m.cta_url,m.promo_code,m.sent_at,m.expires_at,r.delivered_at,r.read_at,l.name location_name
        FROM customer_inbox_recipients r
        JOIN customer_inbox_messages m ON m.id=r.message_id AND m.organization_id=r.organization_id
        LEFT JOIN locations l ON l.id=m.location_id AND l.organization_id=m.organization_id
        WHERE r.organization_id=? AND r.customer_id=? AND r.dismissed_at IS NULL AND m.status='sent'
          AND (m.starts_at IS NULL OR m.starts_at<=NOW(6)) AND (m.expires_at IS NULL OR m.expires_at>NOW(6))
        ORDER BY r.delivered_at DESC,r.id DESC LIMIT {$limit}");
    $q->execute([$organizationId,$customerId]);
    return array_map(static fn(array $r):array=>[
        'publicId'=>(string)$r['public_id'],'type'=>(string)$r['message_type'],'title'=>(string)$r['title'],
        'previewText'=>$r['preview_text'],'bodyText'=>(string)$r['body_text'],'ctaLabel'=>$r['cta_label'],'ctaUrl'=>$r['cta_url'],
        'promoCode'=>$r['promo_code'],'locationName'=>$r['location_name'],'deliveredAt'=>(string)$r['delivered_at'],
        'sentAt'=>$r['sent_at'],'expiresAt'=>$r['expires_at'],'readAt'=>$r['read_at'],
    ],$q->fetchAll());
}

function customer_inbox_unread_count(PDO $pdo,int $organizationId,int $customerId): int
{
    if(!customer_inbox_ready($pdo)) return 0;
    $q=$pdo->prepare("SELECT COUNT(*) FROM customer_inbox_recipients r JOIN customer_inbox_messages m ON m.id=r.message_id AND m.organization_id=r.organization_id WHERE r.organization_id=? AND r.customer_id=? AND r.read_at IS NULL AND r.dismissed_at IS NULL AND m.status='sent' AND (m.starts_at IS NULL OR m.starts_at<=NOW(6)) AND (m.expires_at IS NULL OR m.expires_at>NOW(6))");
    $q->execute([$organizationId,$customerId]);
    return (int)$q->fetchColumn();
}

function customer_inbox_mark_read(PDO $pdo,int $organizationId,int $customerId,?string $messagePublicId=null): void
{
    if($messagePublicId===null||trim($messagePublicId)===''){
        $q=$pdo->prepare("UPDATE customer_inbox_recipients r JOIN customer_inbox_messages m ON m.id=r.message_id AND m.organization_id=r.organization_id SET r.read_at=COALESCE(r.read_at,NOW(6)) WHERE r.organization_id=? AND r.customer_id=? AND r.dismissed_at IS NULL AND m.status='sent'");
        $q->execute([$organizationId,$customerId]);
        return;
    }
    $q=$pdo->prepare("UPDATE customer_inbox_recipients r JOIN customer_inbox_messages m ON m.id=r.message_id AND m.organization_id=r.organization_id SET r.read_at=COALESCE(r.read_at,NOW(6)) WHERE r.organization_id=? AND r.customer_id=? AND m.public_id=?");
    $q->execute([$organizationId,$customerId,trim($messagePublicId)]);
}

function customer_inbox_dismiss(PDO $pdo,int $organizationId,int $customerId,string $messagePublicId): void
{
    $q=$pdo->prepare("UPDATE customer_inbox_recipients r JOIN customer_inbox_messages m ON m.id=r.message_id AND m.organization_id=r.organization_id SET r.dismissed_at=COALESCE(r.dismissed_at,NOW(6)),r.read_at=COALESCE(r.read_at,NOW(6)) WHERE r.organization_id=? AND r.customer_id=? AND m.public_id=?");
    $q->execute([$organizationId,$customerId,trim($messagePublicId)]);
}

function customer_inbox_campaigns(PDO $pdo,int $organizationId,int $limit=40): array
{
    $limit=max(1,min(100,$limit));
    $q=$pdo->prepare("SELECT m.public_id,m.title,m.preview_text,m.promo_code,m.sent_at,m.expires_at,l.name location_name,
        COUNT(r.id) recipient_count,SUM(CASE WHEN r.read_at IS NOT NULL THEN 1 ELSE 0 END) read_count
        FROM customer_inbox_messages m
        LEFT JOIN customer_inbox_recipients r ON r.message_id=m.id AND r.organization_id=m.organization_id
        LEFT JOIN locations l ON l.id=m.location_id AND l.organization_id=m.organization_id
        WHERE m.organization_id=? AND m.message_type='promotion' AND m.status='sent'
        GROUP BY m.id ORDER BY m.sent_at DESC,m.id DESC LIMIT {$limit}");
    $q->execute([$organizationId]);
    return $q->fetchAll();
}

function customer_inbox_tags(PDO $pdo,int $organizationId): array
{
    $q=$pdo->prepare('SELECT id,name,slug FROM crm_tags WHERE organization_id=? ORDER BY name,id');
    $q->execute([$organizationId]);
    return $q->fetchAll();
}
