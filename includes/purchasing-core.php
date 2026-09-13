<?php
declare(strict_types=1);
require_once __DIR__.'/operations-core.php';

function purchasing_ready(PDO $pdo): bool {
    foreach (['vendors','inventory_vendor_items','purchase_orders','purchase_order_items','goods_receipts','goods_receipt_items','vendor_price_history','purchase_order_events'] as $table) {
        if (!restaurant_brain_table_ready($pdo,$table)) return false;
    }
    return true;
}
function purchasing_documents_ready(PDO $pdo): bool { return restaurant_brain_table_ready($pdo,'purchasing_documents'); }
function purchasing_public_id(string $prefix): string { return $prefix.'-'.bin2hex(random_bytes(10)); }
function purchasing_vendor(PDO $pdo,int $org,string $publicId): ?array {
    $q=$pdo->prepare('SELECT * FROM vendors WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');
    $q->execute([$org,$publicId]); $row=$q->fetch(); return $row?:null;
}
function purchasing_vendors(PDO $pdo,int $org): array {
    $q=$pdo->prepare("SELECT * FROM vendors WHERE organization_id=? AND archived_at IS NULL ORDER BY status<>'active',name");
    $q->execute([$org]); return $q->fetchAll();
}
function purchasing_event(PDO $pdo,int $org,int $poId,string $type,string $summary,?int $uid=null,array $meta=[]): void {
    $q=$pdo->prepare('INSERT INTO purchase_order_events (organization_id,purchase_order_id,event_type,summary,metadata_json,actor_user_id) VALUES (?,?,?,?,?,?)');
    $q->execute([$org,$poId,$type,mb_substr($summary,0,600,'UTF-8'),$meta?json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$uid]);
}
function purchasing_save_vendor(PDO $pdo,int $org,array $in,int $uid): array {
    $id=trim((string)($in['id']??'')); $name=mb_substr(trim((string)($in['name']??'')),0,220,'UTF-8');
    if($name==='') throw new InvalidArgumentException('Vendor name is required.');
    $days=array_values(array_unique(array_filter(array_map('intval',(array)($in['deliveryDays']??[])),static fn($d)=>$d>=0&&$d<=6)));
    $vals=[($in['accountNumber']??'')?:null,($in['phone']??'')?:null,($in['email']??'')?:null,($in['orderingEmail']??'')?:null,($in['website']??'')?:null,($in['minimumOrderAmount']??'')!==''?(float)$in['minimumOrderAmount']:null,max(0,(int)($in['leadTimeDays']??0)),$days?json_encode($days):null,($in['cutoffTime']??'')?:null,($in['paymentTerms']??'')?:null,($in['notes']??'')?:null,(string)($in['status']??'active')==='inactive'?'inactive':'active'];
    if($id===''){
        $public=purchasing_public_id('vendor');
        $q=$pdo->prepare('INSERT INTO vendors (organization_id,public_id,name,account_number,phone,email,ordering_email,website,minimum_order_amount,lead_time_days,delivery_days_json,cutoff_time,payment_terms,notes,status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute(array_merge([$org,$public,$name],$vals,[$uid,$uid])); return purchasing_vendor($pdo,$org,$public)??[];
    }
    $vendor=purchasing_vendor($pdo,$org,$id); if(!$vendor) throw new RuntimeException('Vendor not found.');
    $q=$pdo->prepare('UPDATE vendors SET name=?,account_number=?,phone=?,email=?,ordering_email=?,website=?,minimum_order_amount=?,lead_time_days=?,delivery_days_json=?,cutoff_time=?,payment_terms=?,notes=?,status=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?');
    $q->execute(array_merge([$name],$vals,[$uid,(int)$vendor['id'],$org])); return purchasing_vendor($pdo,$org,$id)??[];
}
function purchasing_vendor_catalog(PDO $pdo,int $org,string $vendorPublic=''): array {
    $sql="SELECT vi.*,v.public_id vendor_public_id,v.name vendor_name,i.public_id inventory_public_id,i.name inventory_name,i.base_unit,i.on_hand_quantity,i.par_level,i.reorder_point FROM inventory_vendor_items vi JOIN vendors v ON v.id=vi.vendor_id JOIN inventory_items i ON i.id=vi.inventory_item_id WHERE vi.organization_id=? AND vi.archived_at IS NULL";
    $params=[$org]; if($vendorPublic!==''){$sql.=' AND v.public_id=?';$params[]=$vendorPublic;} $sql.=' ORDER BY v.name,i.name';
    $q=$pdo->prepare($sql);$q->execute($params);return $q->fetchAll();
}
function purchasing_documents_for_order(PDO $pdo,int $org,int $poId):array{
    if(!purchasing_documents_ready($pdo))return [];
    $q=$pdo->prepare("SELECT d.public_id,d.document_type,d.extraction_status,d.notes,d.created_at,f.original_name,f.mime_type,f.file_size,r.public_id receipt_public_id FROM purchasing_documents d INNER JOIN files f ON f.id=d.file_id AND f.organization_id=d.organization_id LEFT JOIN goods_receipts r ON r.id=d.goods_receipt_id AND r.organization_id=d.organization_id WHERE d.organization_id=? AND d.purchase_order_id=? ORDER BY d.created_at DESC");
    $q->execute([$org,$poId]);return $q->fetchAll();
}
