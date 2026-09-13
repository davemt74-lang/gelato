<?php
declare(strict_types=1);

function crm_ready(PDO $pdo): bool
{
    foreach (['crm_customers','crm_customer_consents','crm_customer_notes','crm_tags','crm_customer_tags','pos_checks'] as $table) {
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        if ((int)$q->fetchColumn()!==1) return false;
    }
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pos_checks' AND column_name='customer_id'");
    return (int)$q->fetchColumn()===1;
}

function crm_public_id(string $prefix='customer'): string
{
    return $prefix.'-'.bin2hex(random_bytes(12));
}

function crm_normalize_email(?string $email): ?string
{
    $email=mb_strtolower(trim((string)$email),'UTF-8');
    if ($email==='') return null;
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid customer email address.');
    return $email;
}

function crm_normalize_phone(?string $phone): ?string
{
    $digits=preg_replace('/\D+/','',(string)$phone)??'';
    if ($digits==='') return null;
    if (strlen($digits)<7 || strlen($digits)>15) throw new InvalidArgumentException('Enter a valid customer phone number.');
    return $digits;
}

function crm_customer_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $q=$pdo->prepare('SELECT * FROM crm_customers WHERE organization_id=? AND public_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $q->execute([$org,$publicId]);
    $row=$q->fetch();
    if(!$row) throw new InvalidArgumentException('Customer was not found.');
    return $row;
}

function crm_customer_save(PDO $pdo,int $org,array $input,int $userId,string $source='manual'): array
{
    $public=trim((string)($input['publicId']??''));
    $first=mb_substr(trim((string)($input['firstName']??'')),0,120,'UTF-8');
    $last=mb_substr(trim((string)($input['lastName']??'')),0,120,'UTF-8');
    $display=mb_substr(trim((string)($input['displayName']??trim($first.' '.$last))),0,240,'UTF-8');
    if($display==='') throw new InvalidArgumentException('Customer name is required.');
    $emailRaw=mb_substr(trim((string)($input['email']??'')),0,320,'UTF-8');
    $phoneRaw=mb_substr(trim((string)($input['phone']??'')),0,64,'UTF-8');
    $email=crm_normalize_email($emailRaw);
    $phone=crm_normalize_phone($phoneRaw);
    $month=(int)($input['birthdayMonth']??0);$day=(int)($input['birthdayDay']??0);
    if(($month||$day) && !($month>=1&&$month<=12&&$day>=1&&checkdate($month,$day,2024))) throw new InvalidArgumentException('Birthday month and day must form a valid date.');
    $birthdayMonth=$month?:null;$birthdayDay=$day?:null;
    $source=in_array($source,['manual','pos','import','web'],true)?$source:'manual';

    $existingId=0;
    if($public!==''){$existing=crm_customer_row($pdo,$org,$public,true);$existingId=(int)$existing['id'];}
    if($email||$phone){
        $clauses=[];$args=[$org];
        if($email){$clauses[]='email_normalized=?';$args[]=$email;}
        if($phone){$clauses[]='phone_normalized=?';$args[]=$phone;}
        $sql='SELECT public_id,display_name FROM crm_customers WHERE organization_id=? AND status=\'active\' AND ('.implode(' OR ',$clauses).')';
        if($existingId){$sql.=' AND id<>?';$args[]=$existingId;}
        $sql.=' ORDER BY id LIMIT 1';$q=$pdo->prepare($sql);$q->execute($args);$dup=$q->fetch();
        if($dup) throw new InvalidArgumentException('A customer with that email or phone already exists: '.(string)$dup['display_name'].'. Open the existing profile instead of creating a duplicate.');
    }
    if($existingId){
        $pdo->prepare("UPDATE crm_customers SET first_name=?,last_name=?,display_name=?,email=?,email_normalized=?,phone=?,phone_normalized=?,birthday_month=?,birthday_day=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")
            ->execute([$first?:null,$last?:null,$display,$emailRaw?:null,$email,$phoneRaw?:null,$phone,$birthdayMonth,$birthdayDay,$userId,$org,$existingId]);
        return crm_customer_row($pdo,$org,$public);
    }
    $public=crm_public_id();
    $pdo->prepare("INSERT INTO crm_customers (organization_id,public_id,first_name,last_name,display_name,email,email_normalized,phone,phone_normalized,birthday_month,birthday_day,status,source,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,'active',?,?,?)")
        ->execute([$org,$public,$first?:null,$last?:null,$display,$emailRaw?:null,$email,$phoneRaw?:null,$phone,$birthdayMonth,$birthdayDay,$source,$userId,$userId]);
    return crm_customer_row($pdo,$org,$public);
}

function crm_customer_archive(PDO $pdo,int $org,string $publicId,int $userId): array
{
    $c=crm_customer_row($pdo,$org,$publicId,true);
    $pdo->prepare("UPDATE crm_customers SET status='archived',updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$userId,$org,(int)$c['id']]);
    return crm_customer_row($pdo,$org,$publicId);
}

function crm_search(PDO $pdo,int $org,string $query='',int $limit=50,bool $minimal=false): array
{
    $limit=max(1,min(100,$limit));$query=trim($query);$args=[$org];
    $sql="SELECT public_id,display_name,first_name,last_name,email,phone,status,source,created_at,updated_at FROM crm_customers WHERE organization_id=? AND status='active'";
    if($query!==''){$like='%'.$query.'%';$digits=preg_replace('/\D+/','',$query)??'';$sql.=' AND (display_name LIKE ? OR email_normalized LIKE ? OR phone_normalized LIKE ?)';$args[]=$like;$args[]=mb_strtolower($like,'UTF-8');$args[]='%'.$digits.'%';}
    $sql.=' ORDER BY updated_at DESC,id DESC LIMIT '.$limit;$q=$pdo->prepare($sql);$q->execute($args);$rows=$q->fetchAll();
    if($minimal)return array_map(static fn(array $r):array=>['publicId'=>(string)$r['public_id'],'displayName'=>(string)$r['display_name'],'email'=>$r['email'],'phone'=>$r['phone']],$rows);
    foreach($rows as &$r){$r['publicId']=$r['public_id'];unset($r['public_id']);}unset($r);return $rows;
}

function crm_current_consents(PDO $pdo,int $org,int $customerId): array
{
    $q=$pdo->prepare("SELECT c.channel,c.consent_status,c.source,c.evidence_note,c.captured_at,u.display_name captured_by_name FROM crm_customer_consents c LEFT JOIN users u ON u.id=c.captured_by WHERE c.organization_id=? AND c.customer_id=? ORDER BY c.captured_at DESC,c.id DESC");$q->execute([$org,$customerId]);
    $current=[];foreach($q->fetchAll() as $r){$channel=(string)$r['channel'];if(!isset($current[$channel]))$current[$channel]=['channel'=>$channel,'status'=>(string)$r['consent_status'],'source'=>(string)$r['source'],'evidenceNote'=>$r['evidence_note'],'capturedAt'=>(string)$r['captured_at'],'capturedBy'=>$r['captured_by_name']];}
    foreach(['email','sms'] as $channel)if(!isset($current[$channel]))$current[$channel]=['channel'=>$channel,'status'=>'unknown','source'=>null,'evidenceNote'=>null,'capturedAt'=>null,'capturedBy'=>null];
    return $current;
}

function crm_consent_history(PDO $pdo,int $org,int $customerId,int $limit=50): array
{
    $limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT c.id,c.channel,c.consent_status status,c.source,c.evidence_note evidenceNote,c.captured_at capturedAt,u.display_name capturedBy FROM crm_customer_consents c LEFT JOIN users u ON u.id=c.captured_by WHERE c.organization_id=? AND c.customer_id=? ORDER BY c.captured_at DESC,c.id DESC LIMIT ".$limit);$q->execute([$org,$customerId]);return $q->fetchAll();
}

function crm_consent_set(PDO $pdo,int $org,string $publicId,string $channel,string $status,string $source,string $evidence,int $userId): array
{
    if(!in_array($channel,['email','sms'],true))throw new InvalidArgumentException('Consent channel must be email or SMS.');
    if(!in_array($status,['opted_in','opted_out'],true))throw new InvalidArgumentException('Consent status must be opted in or opted out.');
    if(!in_array($source,['manual','pos','web','import'],true))$source='manual';$evidence=mb_substr(trim($evidence),0,1000,'UTF-8');
    $c=crm_customer_row($pdo,$org,$publicId);if($channel==='email'&&empty($c['email_normalized'])&&$status==='opted_in')throw new InvalidArgumentException('Add an email address before recording email opt-in.');if($channel==='sms'&&empty($c['phone_normalized'])&&$status==='opted_in')throw new InvalidArgumentException('Add a phone number before recording SMS opt-in.');
    $pdo->prepare('INSERT INTO crm_customer_consents (organization_id,customer_id,channel,consent_status,source,evidence_note,captured_by) VALUES (?,?,?,?,?,?,?)')->execute([$org,(int)$c['id'],$channel,$status,$source,$evidence?:null,$userId]);
    return crm_current_consents($pdo,$org,(int)$c['id']);
}

function crm_note_add(PDO $pdo,int $org,string $publicId,string $note,int $userId): array
{
    $note=mb_substr(trim($note),0,10000,'UTF-8');if($note==='')throw new InvalidArgumentException('Customer note cannot be blank.');$c=crm_customer_row($pdo,$org,$publicId);$pdo->prepare('INSERT INTO crm_customer_notes (organization_id,customer_id,note_body,created_by) VALUES (?,?,?,?)')->execute([$org,(int)$c['id'],$note,$userId]);return crm_notes($pdo,$org,(int)$c['id']);
}
function crm_notes(PDO $pdo,int $org,int $customerId,int $limit=50): array
{
    $limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT n.id,n.note_body noteBody,n.created_at createdAt,u.display_name createdBy FROM crm_customer_notes n JOIN users u ON u.id=n.created_by WHERE n.organization_id=? AND n.customer_id=? AND n.archived_at IS NULL ORDER BY n.created_at DESC,n.id DESC LIMIT ".$limit);$q->execute([$org,$customerId]);return $q->fetchAll();
}

function crm_tag_slug(string $name): string
{
    $slug=mb_strtolower(trim($name),'UTF-8');$slug=preg_replace('/[^a-z0-9]+/u','-',$slug)??'';return trim($slug,'-');
}
function crm_tags(PDO $pdo,int $org,int $customerId): array
{
    $q=$pdo->prepare('SELECT t.id,t.name,t.slug FROM crm_customer_tags ct JOIN crm_tags t ON t.id=ct.tag_id AND t.organization_id=ct.organization_id WHERE ct.organization_id=? AND ct.customer_id=? ORDER BY t.name');$q->execute([$org,$customerId]);return $q->fetchAll();
}
function crm_tag_add(PDO $pdo,int $org,string $publicId,string $name,int $userId): array
{
    $name=mb_substr(trim($name),0,120,'UTF-8');$slug=crm_tag_slug($name);if($name===''||$slug==='')throw new InvalidArgumentException('Enter a valid customer tag.');$c=crm_customer_row($pdo,$org,$publicId);$pdo->prepare('INSERT INTO crm_tags (organization_id,name,slug,created_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)')->execute([$org,$name,$slug,$userId]);$q=$pdo->prepare('SELECT id FROM crm_tags WHERE organization_id=? AND slug=? LIMIT 1');$q->execute([$org,$slug]);$tagId=(int)$q->fetchColumn();$pdo->prepare('INSERT IGNORE INTO crm_customer_tags (organization_id,customer_id,tag_id,assigned_by) VALUES (?,?,?,?)')->execute([$org,(int)$c['id'],$tagId,$userId]);return crm_tags($pdo,$org,(int)$c['id']);
}
function crm_tag_remove(PDO $pdo,int $org,string $publicId,int $tagId): array
{
    $c=crm_customer_row($pdo,$org,$publicId);$pdo->prepare('DELETE FROM crm_customer_tags WHERE organization_id=? AND customer_id=? AND tag_id=?')->execute([$org,(int)$c['id'],$tagId]);return crm_tags($pdo,$org,(int)$c['id']);
}

function crm_metrics(PDO $pdo,int $org,int $customerId): array
{
    $q=$pdo->prepare("SELECT COUNT(*) visits,COALESCE(SUM(GREATEST(0,total_amount-tip_amount)),0) lifetime_spend,COALESCE(AVG(GREATEST(0,total_amount-tip_amount)),0) average_check,MIN(closed_at) first_visit,MAX(closed_at) last_visit,COALESCE(SUM(guest_count),0) covers FROM pos_checks WHERE organization_id=? AND customer_id=? AND status='paid'");$q->execute([$org,$customerId]);$m=$q->fetch()?:[];
    $q=$pdo->prepare("SELECT c.public_id checkPublicId,c.check_number checkNumber,c.business_date businessDate,c.service_mode serviceMode,c.table_name tableName,c.total_amount totalAmount,c.tip_amount tipAmount,c.closed_at closedAt,l.name locationName FROM pos_checks c JOIN locations l ON l.id=c.location_id WHERE c.organization_id=? AND c.customer_id=? AND c.status='paid' ORDER BY c.closed_at DESC,c.id DESC LIMIT 25");$q->execute([$org,$customerId]);$recent=$q->fetchAll();
    $q=$pdo->prepare("SELECT i.menu_item_id menuItemId,MAX(i.item_name_snapshot) itemName,MAX(i.category_name_snapshot) categoryName,SUM(i.quantity) quantity,SUM(i.gross_amount*CASE WHEN c.subtotal>0 THEN GREATEST(0,1-(c.discount_amount/c.subtotal)) ELSE 1 END) netSpend FROM pos_check_items i JOIN pos_checks c ON c.id=i.check_id AND c.organization_id=i.organization_id WHERE i.organization_id=? AND c.customer_id=? AND c.status='paid' AND i.status='active' GROUP BY i.menu_item_id ORDER BY quantity DESC,netSpend DESC,itemName LIMIT 8");$q->execute([$org,$customerId]);$favorites=$q->fetchAll();
    return ['visits'=>(int)($m['visits']??0),'lifetimeSpend'=>round((float)($m['lifetime_spend']??0),2),'averageCheck'=>round((float)($m['average_check']??0),2),'covers'=>(int)($m['covers']??0),'firstVisit'=>$m['first_visit']??null,'lastVisit'=>$m['last_visit']??null,'recentChecks'=>$recent,'favoriteItems'=>$favorites];
}

function crm_profile(PDO $pdo,int $org,string $publicId): array
{
    $c=crm_customer_row($pdo,$org,$publicId);$id=(int)$c['id'];
    return ['publicId'=>(string)$c['public_id'],'firstName'=>$c['first_name'],'lastName'=>$c['last_name'],'displayName'=>(string)$c['display_name'],'email'=>$c['email'],'phone'=>$c['phone'],'birthdayMonth'=>$c['birthday_month']!==null?(int)$c['birthday_month']:null,'birthdayDay'=>$c['birthday_day']!==null?(int)$c['birthday_day']:null,'status'=>(string)$c['status'],'source'=>(string)$c['source'],'createdAt'=>(string)$c['created_at'],'updatedAt'=>(string)$c['updated_at'],'metrics'=>crm_metrics($pdo,$org,$id),'tags'=>crm_tags($pdo,$org,$id),'notes'=>crm_notes($pdo,$org,$id),'consents'=>crm_current_consents($pdo,$org,$id),'consentHistory'=>crm_consent_history($pdo,$org,$id)];
}

function crm_check_customer(PDO $pdo,int $org,string $checkPublicId): ?array
{
    if(!crm_ready($pdo))return null;$q=$pdo->prepare("SELECT cu.public_id,cu.display_name,cu.email,cu.phone FROM pos_checks c LEFT JOIN crm_customers cu ON cu.id=c.customer_id AND cu.organization_id=c.organization_id WHERE c.organization_id=? AND c.public_id=? LIMIT 1");$q->execute([$org,$checkPublicId]);$r=$q->fetch();if(!$r||empty($r['public_id']))return null;return ['publicId'=>(string)$r['public_id'],'displayName'=>(string)$r['display_name'],'email'=>$r['email'],'phone'=>$r['phone']];
}

function crm_attach_check(PDO $pdo,int $org,string $checkPublicId,?string $customerPublicId): ?array
{
    $pdo->beginTransaction();try{$q=$pdo->prepare("SELECT id,status FROM pos_checks WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");$q->execute([$org,$checkPublicId]);$check=$q->fetch();if(!$check)throw new InvalidArgumentException('POS check was not found.');if($check['status']!=='open')throw new InvalidArgumentException('Customer can only be changed on an open POS check.');$customerId=null;if($customerPublicId!==null&&trim($customerPublicId)!==''){$c=crm_customer_row($pdo,$org,trim($customerPublicId));if($c['status']!=='active')throw new InvalidArgumentException('Archived customers cannot be attached to a POS check.');$customerId=(int)$c['id'];}$pdo->prepare('UPDATE pos_checks SET customer_id=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$customerId,$org,(int)$check['id']]);$pdo->commit();return crm_check_customer($pdo,$org,$checkPublicId);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
