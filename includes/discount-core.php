<?php
declare(strict_types=1);

require_once __DIR__.'/pos-core.php';

function discount_types(): array
{
    return [
        'package_deal'=>'Package Deal',
        'coupon'=>'Coupon / Promo',
        'make_good'=>'Make Good',
        'admin_discount'=>'Admin Discount',
        'loyalty_reward'=>'Loyalty / Reward',
        'catering_wholesale_adjustment'=>'Catering / Wholesale Adjustment',
        'other'=>'Other',
    ];
}

function discount_manual_types(): array
{
    $types=discount_types();
    unset($types['package_deal']);
    return $types;
}

function discount_methods(): array { return ['fixed','percent']; }

function discount_table_ready(PDO $pdo): bool
{
    try{
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='pos_discounts'");
        return (int)$q->fetchColumn()===1;
    }catch(Throwable){ return false; }
}

function discount_calculate(float $subtotal,string $method,float $value): float
{
    $subtotal=pos_money($subtotal);
    $method=strtolower(trim($method));
    if(!in_array($method,discount_methods(),true)) throw new InvalidArgumentException('Choose a valid discount method.');
    if($value<0) throw new InvalidArgumentException('Discount value cannot be negative.');
    if($method==='percent'){
        if($value>100) throw new InvalidArgumentException('Percentage discounts cannot exceed 100%.');
        return pos_money($subtotal*($value/100));
    }
    return pos_money(min($subtotal,$value));
}

function discount_active_total(PDO $pdo,int $org,int $checkId): float
{
    $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM pos_discounts WHERE organization_id=? AND pos_check_id=? AND status='active'");
    $q->execute([$org,$checkId]);
    return pos_money((float)$q->fetchColumn());
}

function discount_sync_check(PDO $pdo,int $org,int $checkId): array
{
    $q=$pdo->prepare('SELECT public_id,status FROM pos_checks WHERE organization_id=? AND id=? LIMIT 1 FOR UPDATE');
    $q->execute([$org,$checkId]);
    $check=$q->fetch();
    if(!$check) throw new InvalidArgumentException('POS check was not found.');
    if((string)$check['status']!=='open') throw new InvalidArgumentException('Discounts can only be changed while the POS check is open.');
    pos_recalculate_check($pdo,$org,$checkId);

    $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) amount,GROUP_CONCAT(DISTINCT discount_type ORDER BY applied_at,id SEPARATOR ', ') reasons FROM pos_discounts WHERE organization_id=? AND pos_check_id=? AND status='active'");
    $q->execute([$org,$checkId]);
    $row=$q->fetch()?:[];
    $s=$pdo->prepare('SELECT subtotal FROM pos_checks WHERE organization_id=? AND id=? LIMIT 1');
    $s->execute([$org,$checkId]);
    $subtotal=(float)$s->fetchColumn();
    $amount=pos_money(min($subtotal,(float)($row['amount']??0)));
    $reason=$amount>0?'Typed discounts: '.(string)($row['reasons']??'discount'):null;
    $pdo->prepare('UPDATE pos_checks SET discount_amount=?,discount_reason=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')
        ->execute([$amount,$reason,$org,$checkId]);
    pos_recalculate_check($pdo,$org,$checkId);
    return pos_check_details($pdo,$org,(string)$check['public_id']);
}

function discount_apply(PDO $pdo,int $org,string $checkPublicId,string $type,string $method,float $value,string $reason,int $userId,?string $referenceType=null,?string $referenceId=null): array
{
    if(!discount_table_ready($pdo)) throw new RuntimeException('Typed discounts are not installed. Run Upgrade first.');
    $types=discount_types();
    if(!isset($types[$type])) throw new InvalidArgumentException('Choose a valid discount type.');
    $reason=mb_substr(trim($reason),0,500,'UTF-8');
    if($reason==='') throw new InvalidArgumentException('A discount reason is required.');
    $referenceType=mb_substr(trim((string)$referenceType),0,48,'UTF-8')?:null;
    $referenceId=mb_substr(trim((string)$referenceId),0,120,'UTF-8')?:null;

    return pos_transaction($pdo,function()use($pdo,$org,$checkPublicId,$type,$method,$value,$reason,$userId,$referenceType,$referenceId):array{
        $check=pos_require_open_check($pdo,$org,$checkPublicId,true);
        pos_recalculate_check($pdo,$org,(int)$check['id']);

        if($referenceType!==null && $referenceId!==null){
            $existing=$pdo->prepare("SELECT * FROM pos_discounts WHERE organization_id=? AND pos_check_id=? AND discount_type=? AND reference_type=? AND reference_id=? AND status='active' ORDER BY id LIMIT 1");
            $existing->execute([$org,(int)$check['id'],$type,$referenceType,$referenceId]);
            if($row=$existing->fetch()){
                return ['discount'=>$row,'check'=>discount_sync_check($pdo,$org,(int)$check['id']),'duplicate'=>true];
            }
        }

        $q=$pdo->prepare('SELECT subtotal FROM pos_checks WHERE organization_id=? AND id=? LIMIT 1');
        $q->execute([$org,(int)$check['id']]);
        $subtotal=pos_money((float)$q->fetchColumn());
        $existingTotal=discount_active_total($pdo,$org,(int)$check['id']);
        $remaining=pos_money(max(0,$subtotal-$existingTotal));
        if($remaining<=0) throw new InvalidArgumentException('This check is already fully discounted.');
        $amount=min(discount_calculate($subtotal,$method,$value),$remaining);
        $amount=pos_money($amount);
        if($amount<=0) throw new InvalidArgumentException('Discount must reduce the check by more than zero.');

        $public=sales_public_id('pos-discount');
        $pdo->prepare("INSERT INTO pos_discounts (organization_id,location_id,pos_check_id,public_id,discount_type,method,value,amount,reason,reference_type,reference_id,status,applied_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,'active',?)")
            ->execute([$org,(int)$check['location_id'],(int)$check['id'],$public,$type,$method,$value,$amount,$reason,$referenceType,$referenceId,$userId]);
        $id=(int)$pdo->lastInsertId();
        $d=$pdo->prepare('SELECT * FROM pos_discounts WHERE organization_id=? AND id=? LIMIT 1');
        $d->execute([$org,$id]);
        return ['discount'=>$d->fetch(),'check'=>discount_sync_check($pdo,$org,(int)$check['id']),'duplicate'=>false];
    });
}

function discount_void(PDO $pdo,int $org,string $discountPublicId,int $userId): array
{
    return pos_transaction($pdo,function()use($pdo,$org,$discountPublicId,$userId):array{
        $q=$pdo->prepare("SELECT * FROM pos_discounts WHERE organization_id=? AND public_id=? AND status='active' LIMIT 1 FOR UPDATE");
        $q->execute([$org,$discountPublicId]);
        $row=$q->fetch();
        if(!$row) throw new InvalidArgumentException('Active discount was not found.');
        $pdo->prepare("UPDATE pos_discounts SET status='voided',voided_by=?,voided_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=? AND status='active'")
            ->execute([$userId,$org,(int)$row['id']]);
        return ['discountPublicId'=>$discountPublicId,'check'=>discount_sync_check($pdo,$org,(int)$row['pos_check_id'])];
    });
}

function discount_check_discounts(PDO $pdo,int $org,int $checkId): array
{
    $q=$pdo->prepare('SELECT public_id,discount_type,method,value,amount,reason,reference_type,reference_id,status,applied_by,applied_at FROM pos_discounts WHERE organization_id=? AND pos_check_id=? ORDER BY applied_at,id');
    $q->execute([$org,$checkId]);
    return $q->fetchAll();
}

function discount_recent(PDO $pdo,int $org,int $limit=100): array
{
    $limit=max(1,min(250,$limit));
    $q=$pdo->prepare("SELECT d.public_id,d.discount_type,d.method,d.value,d.amount,d.reason,d.reference_type,d.reference_id,d.status,d.applied_at,d.voided_at,
                            c.public_id check_public_id,c.check_number,c.status check_status,c.location_id,l.name location_name,u.display_name applied_by_name
                     FROM pos_discounts d
                     JOIN pos_checks c ON c.id=d.pos_check_id AND c.organization_id=d.organization_id
                     JOIN locations l ON l.id=d.location_id AND l.organization_id=d.organization_id
                     JOIN users u ON u.id=d.applied_by
                     WHERE d.organization_id=?
                     ORDER BY d.applied_at DESC,d.id DESC LIMIT ".$limit);
    $q->execute([$org]);
    return $q->fetchAll();
}
