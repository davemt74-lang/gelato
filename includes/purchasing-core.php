<?php
declare(strict_types=1);
require_once __DIR__.'/operations-core.php';

function purchasing_ready(PDO $pdo): bool {
    foreach (['vendors','vendor_contacts','inventory_vendor_items','purchase_orders','purchase_order_items','goods_receipts','goods_receipt_items','vendor_price_history','purchase_order_events'] as $table) {
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
    $q=$pdo->prepare("SELECT v.*,(SELECT COUNT(*) FROM vendor_contacts c WHERE c.organization_id=v.organization_id AND c.vendor_id=v.id) contact_count,(SELECT COUNT(*) FROM inventory_vendor_items vi WHERE vi.organization_id=v.organization_id AND vi.vendor_id=v.id AND vi.archived_at IS NULL AND vi.status='active') item_count FROM vendors v WHERE v.organization_id=? AND v.archived_at IS NULL ORDER BY v.status<>'active',v.name");
    $q->execute([$org]); return $q->fetchAll();
}
function purchasing_vendor_contacts(PDO $pdo,int $org,string $vendorPublic=''):array{
    $sql='SELECT c.*,v.public_id vendor_public_id,v.name vendor_name FROM vendor_contacts c INNER JOIN vendors v ON v.id=c.vendor_id AND v.organization_id=c.organization_id WHERE c.organization_id=?';$params=[$org];if($vendorPublic!==''){$sql.=' AND v.public_id=?';$params[]=$vendorPublic;}$sql.=' ORDER BY v.name,c.is_primary DESC,c.name';$q=$pdo->prepare($sql);$q->execute($params);return $q->fetchAll();
}
function purchasing_save_vendor_contact(PDO $pdo,int $org,array $in,int $uid):array{
    $vendor=purchasing_vendor($pdo,$org,trim((string)($in['vendorId']??'')));if(!$vendor)throw new InvalidArgumentException('Vendor not found.');$id=trim((string)($in['id']??''));$name=mb_substr(trim((string)($in['name']??'')),0,180,'UTF-8');if($name==='')throw new InvalidArgumentException('Contact name is required.');$primary=!empty($in['primary'])?1:0;if($primary)$pdo->prepare('UPDATE vendor_contacts SET is_primary=0 WHERE organization_id=? AND vendor_id=?')->execute([$org,(int)$vendor['id']]);
    if($id===''){$id=purchasing_public_id('vcontact');$pdo->prepare('INSERT INTO vendor_contacts (organization_id,vendor_id,public_id,name,role_title,email,phone,is_primary,notes) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$org,(int)$vendor['id'],$id,$name,mb_substr(trim((string)($in['roleTitle']??'')),0,160,'UTF-8')?:null,mb_substr(trim((string)($in['email']??'')),0,254,'UTF-8')?:null,mb_substr(trim((string)($in['phone']??'')),0,60,'UTF-8')?:null,$primary,mb_substr(trim((string)($in['notes']??'')),0,1000,'UTF-8')?:null]);}
    else{$q=$pdo->prepare('UPDATE vendor_contacts SET name=?,role_title=?,email=?,phone=?,is_primary=?,notes=?,updated_at=NOW(6) WHERE organization_id=? AND vendor_id=? AND public_id=?');$q->execute([$name,mb_substr(trim((string)($in['roleTitle']??'')),0,160,'UTF-8')?:null,mb_substr(trim((string)($in['email']??'')),0,254,'UTF-8')?:null,mb_substr(trim((string)($in['phone']??'')),0,60,'UTF-8')?:null,$primary,mb_substr(trim((string)($in['notes']??'')),0,1000,'UTF-8')?:null,$org,(int)$vendor['id'],$id]);if($q->rowCount()===0)throw new RuntimeException('Vendor contact not found.');}
    foreach(purchasing_vendor_contacts($pdo,$org,(string)$vendor['public_id']) as $row)if((string)$row['public_id']===$id)return $row;return [];
}
function purchasing_event(PDO $pdo,int $org,int $poId,string $type,string $summary,?int $uid=null,array $meta=[]): void {
    $q=$pdo->prepare('INSERT INTO purchase_order_events (organization_id,purchase_order_id,event_type,summary,metadata_json,actor_user_id) VALUES (?,?,?,?,?,?)');
    $q->execute([$org,$poId,$type,mb_substr($summary,0,600,'UTF-8'),$meta?json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$uid]);
}
function purchasing_save_vendor(PDO $pdo,int $org,array $in,int $uid): array {
    $id=trim((string)($in['id']??'')); $name=mb_substr(trim((string)($in['name']??'')),0,220,'UTF-8');
    if($name==='') throw new InvalidArgumentException('Vendor name is required.');
    $daysRaw=$in['deliveryDays']??[];if(is_string($daysRaw))$daysRaw=preg_split('/[^0-9]+/',$daysRaw,-1,PREG_SPLIT_NO_EMPTY)?:[];$days=array_values(array_unique(array_filter(array_map('intval',(array)$daysRaw),static fn($d)=>$d>=0&&$d<=6)));
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
