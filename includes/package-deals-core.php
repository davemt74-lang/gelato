<?php
declare(strict_types=1);

require_once __DIR__.'/online-order-core.php';
require_once __DIR__.'/discount-core.php';

function package_deal_tables(): array
{
    return ['package_deals','package_deal_groups','package_deal_group_items','package_redemptions','pos_discounts'];
}

function package_deals_ready(PDO $pdo): bool
{
    try{
        foreach(package_deal_tables() as $table){
            $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
            $q->execute([$table]);
            if((int)$q->fetchColumn()!==1) return false;
        }
        return online_order_ready($pdo);
    }catch(Throwable){ return false; }
}

function package_deal_statuses(): array { return ['draft','active','paused','ended','archived']; }

function package_deal_slug(string $name): string
{
    $slug=strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/','-',$name),'-'));
    return substr($slug?:'package',0,120);
}

function package_deal_unique_slug(PDO $pdo,int $org,string $base,?int $ignoreId=null): string
{
    $base=package_deal_slug($base);
    for($i=0;$i<500;$i++){
        $slug=$i===0?$base:$base.'-'.($i+1);
        $sql='SELECT COUNT(*) FROM package_deals WHERE organization_id=? AND slug=?'.($ignoreId?' AND id<>?':'');
        $q=$pdo->prepare($sql);
        $args=[$org,$slug]; if($ignoreId)$args[]=$ignoreId;
        $q->execute($args);
        if((int)$q->fetchColumn()===0) return $slug;
    }
    return $base.'-'.bin2hex(random_bytes(4));
}

function package_deal_row(PDO $pdo,int $org,string $publicOrSlug,bool $forUpdate=false): array
{
    $sql='SELECT * FROM package_deals WHERE organization_id=? AND (public_id=? OR slug=?) LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,$publicOrSlug,$publicOrSlug]);$row=$q->fetch();
    if(!$row) throw new InvalidArgumentException('Package deal was not found.');
    return $row;
}

function package_deal_payload(PDO $pdo,int $org,array $row): array
{
    $q=$pdo->prepare("SELECT g.id,g.public_id,g.label,g.required_quantity,g.sort_order,gi.menu_item_price_id,p.option_name,p.size_code,p.amount,p.currency,i.id menu_item_id,i.name item_name,i.description,s.name section_name,i.is_active item_active,s.status section_status
        FROM package_deal_groups g
        LEFT JOIN package_deal_group_items gi ON gi.package_group_id=g.id AND gi.organization_id=g.organization_id
        LEFT JOIN menu_item_prices p ON p.id=gi.menu_item_price_id
        LEFT JOIN menu_items i ON i.id=p.menu_item_id AND i.organization_id=g.organization_id
        LEFT JOIN menu_sections s ON s.id=i.section_id AND s.organization_id=i.organization_id
        WHERE g.organization_id=? AND g.package_deal_id=?
        ORDER BY g.sort_order,g.id,gi.sort_order,gi.id");
    $q->execute([$org,(int)$row['id']]);
    $groups=[];
    foreach($q->fetchAll() as $r){
        $gid=(int)$r['id'];
        if(!isset($groups[$gid]))$groups[$gid]=[
            'id'=>(string)$r['public_id'],'label'=>(string)$r['label'],'requiredQuantity'=>(int)$r['required_quantity'],'sortOrder'=>(int)$r['sort_order'],'items'=>[]
        ];
        if($r['menu_item_price_id']!==null){
            $groups[$gid]['items'][]=[
                'priceId'=>(int)$r['menu_item_price_id'],'itemId'=>(int)$r['menu_item_id'],'itemName'=>(string)$r['item_name'],'description'=>$r['description'],
                'sectionName'=>(string)$r['section_name'],'optionName'=>(string)$r['option_name'],'sizeCode'=>$r['size_code'],'amount'=>(float)$r['amount'],'currency'=>(string)$r['currency'],
                'available'=>(bool)$r['item_active'] && (string)$r['section_status']==='active',
            ];
        }
    }
    $redemptions=$pdo->prepare('SELECT COUNT(*) redemptions,COALESCE(SUM(retail_amount),0) retail,COALESCE(SUM(discount_amount),0) discounts,COALESCE(SUM(package_amount),0) revenue FROM package_redemptions WHERE organization_id=? AND package_deal_id=?');
    $redemptions->execute([$org,(int)$row['id']]);$stats=$redemptions->fetch()?:[];
    return [
        'id'=>(string)$row['public_id'],'slug'=>(string)$row['slug'],'name'=>(string)$row['name'],'eyebrow'=>(string)($row['eyebrow']??''),'description'=>(string)($row['description']??''),
        'status'=>(string)$row['status'],'discountMethod'=>(string)$row['discount_method'],'discountValue'=>(float)$row['discount_value'],'pickupOnly'=>(bool)$row['pickup_only'],
        'startsAt'=>$row['starts_at'],'endsAt'=>$row['ends_at'],'sortOrder'=>(int)$row['sort_order'],'featured'=>(bool)$row['featured'],
        'pausedAt'=>$row['paused_at'],'endedAt'=>$row['ended_at'],'archivedAt'=>$row['archived_at'],'createdAt'=>(string)$row['created_at'],'updatedAt'=>(string)$row['updated_at'],
        'groups'=>array_values($groups),
        'stats'=>['redemptions'=>(int)($stats['redemptions']??0),'retail'=>(float)($stats['retail']??0),'discounts'=>(float)($stats['discounts']??0),'revenue'=>(float)($stats['revenue']??0)],
    ];
}

function package_deal_list(PDO $pdo,int $org,bool $includeArchived=false): array
{
    $sql='SELECT * FROM package_deals WHERE organization_id=?'.($includeArchived?'':' AND status<>\'archived\'').' ORDER BY FIELD(status,\'active\',\'paused\',\'draft\',\'ended\',\'archived\'),sort_order,name,id';
    $q=$pdo->prepare($sql);$q->execute([$org]);
    return array_map(static fn(array $row):array=>package_deal_payload($pdo,$org,$row),$q->fetchAll());
}

function package_deal_is_public(array $row,?DateTimeImmutable $now=null): bool
{
    if((string)($row['status']??'')!=='active' || empty($row['pickup_only'])) return false;
    $now??=new DateTimeImmutable();
    if(!empty($row['starts_at']) && $now<new DateTimeImmutable((string)$row['starts_at'])) return false;
    if(!empty($row['ends_at']) && $now>=new DateTimeImmutable((string)$row['ends_at'])) return false;
    return true;
}

function package_deal_public_list(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT * FROM package_deals WHERE organization_id=? AND status='active' AND pickup_only=1 AND (starts_at IS NULL OR starts_at<=NOW(6)) AND (ends_at IS NULL OR ends_at>NOW(6)) ORDER BY featured DESC,sort_order,name,id");
    $q->execute([$org]);
    $out=[];
    foreach($q->fetchAll() as $row){
        $package=package_deal_payload($pdo,$org,$row);
        if($package['groups']!==[])$out[]=$package;
    }
    return $out;
}

function package_deal_validate_groups(PDO $pdo,int $org,array $groups): array
{
    if(count($groups)>20) throw new InvalidArgumentException('A package cannot have more than 20 selection groups.');
    $out=[];$seen=[];
    foreach($groups as $index=>$group){
        if(!is_array($group))continue;
        $label=mb_substr(trim((string)($group['label']??'')),0,140,'UTF-8');
        $qty=(int)($group['requiredQuantity']??1);
        if($label===''||$qty<1||$qty>20) throw new InvalidArgumentException('Each package group needs a name and a quantity between 1 and 20.');
        $priceIds=[];
        foreach((array)($group['priceIds']??[]) as $priceId){$id=(int)$priceId;if($id>0)$priceIds[$id]=$id;}
        if(!$priceIds) throw new InvalidArgumentException($label.' needs at least one eligible menu item.');
        if(count($priceIds)>100) throw new InvalidArgumentException($label.' has too many eligible menu items.');
        $marks=implode(',',array_fill(0,count($priceIds),'?'));
        $q=$pdo->prepare("SELECT p.id FROM menu_item_prices p JOIN menu_items i ON i.id=p.menu_item_id AND i.organization_id=? JOIN menu_sections s ON s.id=i.section_id AND s.organization_id=i.organization_id WHERE p.id IN ($marks)");
        $q->execute(array_merge([$org],array_values($priceIds)));
        $valid=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        sort($valid);$expected=array_values($priceIds);sort($expected);
        if($valid!==$expected) throw new InvalidArgumentException('One or more selected menu items no longer belong to this restaurant.');
        $out[]=['label'=>$label,'requiredQuantity'=>$qty,'priceIds'=>$expected,'sortOrder'=>(int)($group['sortOrder']??$index)];
        $seen[]=$label;
    }
    if(!$out) throw new InvalidArgumentException('Add at least one category/group to the package.');
    return $out;
}

function package_deal_save(PDO $pdo,int $org,?string $publicId,array $input,int $userId): array
{
    if(!package_deals_ready($pdo)) throw new RuntimeException('Package Deals is not installed. Run Upgrade first.');
    $name=mb_substr(trim((string)($input['name']??'')),0,180,'UTF-8');
    if($name==='') throw new InvalidArgumentException('Package name is required.');
    $eyebrow=mb_substr(trim((string)($input['eyebrow']??'')),0,120,'UTF-8')?:null;
    $description=mb_substr(trim((string)($input['description']??'')),0,5000,'UTF-8')?:null;
    $method=strtolower(trim((string)($input['discountMethod']??'percent')));
    $value=round((float)($input['discountValue']??0),4);
    if(!in_array($method,discount_methods(),true)) throw new InvalidArgumentException('Choose a valid package discount method.');
    if($value<=0 || ($method==='percent'&&$value>100) || ($method==='fixed'&&$value>100000)) throw new InvalidArgumentException('Enter a valid package discount.');
    $groups=package_deal_validate_groups($pdo,$org,is_array($input['groups']??null)?$input['groups']:[]);
    $starts=trim((string)($input['startsAt']??''))?:null;$ends=trim((string)($input['endsAt']??''))?:null;
    if($starts!==null)new DateTimeImmutable($starts); if($ends!==null)new DateTimeImmutable($ends);
    if($starts!==null&&$ends!==null&&new DateTimeImmutable($ends)<=new DateTimeImmutable($starts)) throw new InvalidArgumentException('Package end time must be after its start time.');

    return pos_transaction($pdo,function()use($pdo,$org,$publicId,$input,$userId,$name,$eyebrow,$description,$method,$value,$groups,$starts,$ends):array{
        $existing=null;
        if($publicId){$existing=package_deal_row($pdo,$org,$publicId,true);}
        if($existing){
            $slug=package_deal_unique_slug($pdo,$org,(string)($input['slug']??$name),(int)$existing['id']);
            $pdo->prepare('UPDATE package_deals SET slug=?,name=?,eyebrow=?,description=?,discount_method=?,discount_value=?,pickup_only=1,starts_at=?,ends_at=?,sort_order=?,featured=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')
                ->execute([$slug,$name,$eyebrow,$description,$method,$value,$starts,$ends,(int)($input['sortOrder']??0),!empty($input['featured'])?1:0,$userId,$org,(int)$existing['id']]);
            $packageId=(int)$existing['id'];
            $pdo->prepare('DELETE FROM package_deal_groups WHERE organization_id=? AND package_deal_id=?')->execute([$org,$packageId]);
        }else{
            $slug=package_deal_unique_slug($pdo,$org,(string)($input['slug']??$name));
            $public=sales_public_id('package');
            $pdo->prepare("INSERT INTO package_deals (organization_id,public_id,slug,name,eyebrow,description,status,discount_method,discount_value,pickup_only,starts_at,ends_at,sort_order,featured,created_by,updated_by) VALUES (?,?,?,?,?,?,'draft',?,?,1,?,?,?,?,?,?,?)")
                ->execute([$org,$public,$slug,$name,$eyebrow,$description,$method,$value,$starts,$ends,(int)($input['sortOrder']??0),!empty($input['featured'])?1:0,$userId,$userId]);
            $packageId=(int)$pdo->lastInsertId();
        }
        foreach($groups as $groupIndex=>$group){
            $groupPublic=sales_public_id('package-group');
            $pdo->prepare('INSERT INTO package_deal_groups (organization_id,package_deal_id,public_id,label,required_quantity,sort_order) VALUES (?,?,?,?,?,?)')
                ->execute([$org,$packageId,$groupPublic,$group['label'],$group['requiredQuantity'],$group['sortOrder']]);
            $groupId=(int)$pdo->lastInsertId();
            foreach($group['priceIds'] as $itemIndex=>$priceId)$pdo->prepare('INSERT INTO package_deal_group_items (organization_id,package_deal_id,package_group_id,menu_item_price_id,sort_order) VALUES (?,?,?,?,?)')
                ->execute([$org,$packageId,$groupId,$priceId,$itemIndex]);
        }
        $q=$pdo->prepare('SELECT * FROM package_deals WHERE organization_id=? AND id=? LIMIT 1');$q->execute([$org,$packageId]);
        return package_deal_payload($pdo,$org,$q->fetch());
    });
}

function package_deal_set_status(PDO $pdo,int $org,string $publicId,string $action,int $userId): array
{
    $action=strtolower(trim($action));
    return pos_transaction($pdo,function()use($pdo,$org,$publicId,$action,$userId):array{
        $row=package_deal_row($pdo,$org,$publicId,true);$current=(string)$row['status'];
        $target=match($action){'activate','resume'=>'active','pause'=>'paused','end'=>'ended','archive'=>'archived',default=>throw new InvalidArgumentException('Unknown package status action.')};
        if($current==='archived') throw new InvalidArgumentException('Archived package deals cannot be changed. Duplicate it to create a new deal.');
        if($action==='pause'&&$current!=='active') throw new InvalidArgumentException('Only an active package can be paused.');
        if($action==='resume'&&$current!=='paused') throw new InvalidArgumentException('Only a paused package can be resumed.');
        if($action==='activate'&&!in_array($current,['draft','paused'],true)) throw new InvalidArgumentException('Only draft or paused packages can be activated.');
        if($action==='end'&&!in_array($current,['draft','active','paused'],true)) throw new InvalidArgumentException('This package can no longer be ended.');
        if($target==='active'){
            $q=$pdo->prepare('SELECT COUNT(*) FROM package_deal_groups WHERE organization_id=? AND package_deal_id=?');$q->execute([$org,(int)$row['id']]);
            if((int)$q->fetchColumn()<1) throw new InvalidArgumentException('Add at least one package group before activating the deal.');
        }
        $paused=$target==='paused'?'NOW(6)':'NULL';$ended=$target==='ended'?'NOW(6)':($target==='active'?'NULL':'ended_at');$archived=$target==='archived'?'NOW(6)':'archived_at';
        $sql="UPDATE package_deals SET status=?,paused_at=".($target==='paused'?'NOW(6)':($target==='active'?'NULL':'paused_at')).",ended_at=".($target==='ended'?'NOW(6)':($target==='active'?'NULL':'ended_at')).",archived_at=".($target==='archived'?'NOW(6)':'archived_at').",updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?";
        $pdo->prepare($sql)->execute([$target,$userId,$org,(int)$row['id']]);
        $fresh=package_deal_row($pdo,$org,$publicId,false);return package_deal_payload($pdo,$org,$fresh);
    });
}

function package_deal_duplicate(PDO $pdo,int $org,string $publicId,int $userId): array
{
    return pos_transaction($pdo,function()use($pdo,$org,$publicId,$userId):array{
        $source=package_deal_payload($pdo,$org,package_deal_row($pdo,$org,$publicId,true));
        $groups=[];
        foreach($source['groups'] as $group)$groups[]=['label'=>$group['label'],'requiredQuantity'=>$group['requiredQuantity'],'priceIds'=>array_column($group['items'],'priceId'),'sortOrder'=>$group['sortOrder']];
        return package_deal_save($pdo,$org,null,[
            'name'=>$source['name'].' Copy','slug'=>$source['slug'].'-copy','eyebrow'=>$source['eyebrow'],'description'=>$source['description'],
            'discountMethod'=>$source['discountMethod'],'discountValue'=>$source['discountValue'],'sortOrder'=>$source['sortOrder'],'featured'=>false,'groups'=>$groups,
        ],$userId);
    });
}

function package_deal_menu_search(PDO $pdo,int $org,string $query,int $limit=40): array
{
    $query=trim($query);$limit=max(1,min(80,$limit));$like='%'.$query.'%';
    $sql="SELECT p.id price_id,p.option_name,p.size_code,p.amount,p.currency,i.id item_id,i.name item_name,i.description,s.name section_name FROM menu_item_prices p JOIN menu_items i ON i.id=p.menu_item_id AND i.organization_id=? AND i.is_active=1 JOIN menu_sections s ON s.id=i.section_id AND s.organization_id=i.organization_id AND s.status='active' WHERE (i.name LIKE ? OR s.name LIKE ? OR p.option_name LIKE ?) AND (p.active_from IS NULL OR p.active_from<=NOW(6)) AND (p.active_until IS NULL OR p.active_until>NOW(6)) ORDER BY s.sort_order,s.name,i.name,p.sort_order,p.id LIMIT ".$limit;
    $q=$pdo->prepare($sql);$q->execute([$org,$like,$like,$like]);
    return array_map(static fn(array $r):array=>['priceId'=>(int)$r['price_id'],'itemId'=>(int)$r['item_id'],'itemName'=>(string)$r['item_name'],'description'=>$r['description'],'sectionName'=>(string)$r['section_name'],'optionName'=>(string)$r['option_name'],'sizeCode'=>$r['size_code'],'amount'=>(float)$r['amount'],'currency'=>(string)$r['currency']],$q->fetchAll());
}

function package_deal_selection_cart(PDO $pdo,int $org,array $package,array $selections): array
{
    $cart=[];$snapshot=[];$retail=0.0;
    foreach($package['groups'] as $group){
        $selected=array_values(array_map('intval',(array)($selections[$group['id']]??[])));
        if(count($selected)!==(int)$group['requiredQuantity']) throw new InvalidArgumentException('Choose exactly '.$group['requiredQuantity'].' item'.((int)$group['requiredQuantity']===1?'':'s').' for '.$group['label'].'.');
        $allowed=[];foreach($group['items'] as $item)if(!empty($item['available']))$allowed[(int)$item['priceId']]=$item;
        $counts=[];$selectedSnapshot=[];
        foreach($selected as $priceId){
            if(!isset($allowed[$priceId])) throw new InvalidArgumentException('One of your '.$group['label'].' selections is no longer available.');
            $counts[$priceId]=($counts[$priceId]??0)+1;$retail+=(float)$allowed[$priceId]['amount'];$selectedSnapshot[]=['priceId'=>$priceId,'itemName'=>$allowed[$priceId]['itemName'],'optionName'=>$allowed[$priceId]['optionName'],'amount'=>$allowed[$priceId]['amount']];
        }
        foreach($counts as $priceId=>$qty)$cart[]=['priceId'=>$priceId,'quantity'=>$qty,'instructions'=>'PACKAGE: '.$package['name'].' · '.$group['label']];
        $snapshot[]=['groupId'=>$group['id'],'label'=>$group['label'],'requiredQuantity'=>$group['requiredQuantity'],'selections'=>$selectedSnapshot];
    }
    if(!$cart) throw new InvalidArgumentException('This package has no orderable items.');
    return ['cart'=>$cart,'snapshot'=>$snapshot,'retail'=>pos_money($retail)];
}

function package_deal_submit_pickup(PDO $pdo,int $org,array $account,array $input): array
{
    if(!package_deals_ready($pdo)) throw new RuntimeException('Package ordering is not installed. Run Upgrade first.');
    $package=package_deal_payload($pdo,$org,package_deal_row($pdo,$org,(string)($input['package']??''),false));
    if(!package_deal_is_public(['status'=>$package['status'],'pickup_only'=>$package['pickupOnly'],'starts_at'=>$package['startsAt'],'ends_at'=>$package['endsAt']])) throw new InvalidArgumentException('That package deal is not currently available.');
    $selection=package_deal_selection_cart($pdo,$org,$package,is_array($input['selections']??null)?$input['selections']:[]);
    $customerNote=mb_substr(trim((string)($input['note']??'')),0,800,'UTF-8');
    $note='Package: '.$package['name'].($customerNote!==''?' · '.$customerNote:'');
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $order=online_order_submit_pickup($pdo,$org,$account,[
            'locationId'=>(int)($input['locationId']??0),'idempotencyKey'=>$input['idempotencyKey']??'','items'=>$selection['cart'],'note'=>$note,
        ]);
        $oq=$pdo->prepare('SELECT id,pos_check_id FROM online_orders WHERE organization_id=? AND public_id=? LIMIT 1');$oq->execute([$org,(string)$order['public_id']]);$orderRow=$oq->fetch();
        if(!$orderRow) throw new RuntimeException('Package order could not be linked to the online order.');
        if(!empty($order['duplicate'])){
            $rq=$pdo->prepare('SELECT public_id,retail_amount,discount_amount,package_amount FROM package_redemptions WHERE organization_id=? AND online_order_id=? LIMIT 1');$rq->execute([$org,(int)$orderRow['id']]);$redemption=$rq->fetch();
            if(!$redemption) throw new RuntimeException('Existing package order is missing its package redemption record.');
            if($owns)$pdo->commit();return $order+['package'=>$package,'redemption'=>$redemption];
        }
        $discount=discount_apply($pdo,$org,(string)$order['check_public_id'],'package_deal',$package['discountMethod'],(float)$package['discountValue'],'Package deal: '.$package['name'],(int)$account['id'],'package_deal',$package['id']);
        $discountRow=$discount['discount'];$check=$discount['check'];
        $redemptionPublic=sales_public_id('package-redemption');
        $packageAmount=pos_money($selection['retail']-(float)$discountRow['amount']);
        $pdo->prepare('INSERT INTO package_redemptions (organization_id,package_deal_id,online_order_id,pos_check_id,pos_discount_id,public_id,package_name_snapshot,retail_amount,discount_amount,package_amount,discount_method,discount_value,selection_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$org,(int)package_deal_row($pdo,$org,$package['id'],false)['id'],(int)$orderRow['id'],(int)$orderRow['pos_check_id'],(int)$discountRow['id'],$redemptionPublic,$package['name'],$selection['retail'],(float)$discountRow['amount'],$packageAmount,$package['discountMethod'],$package['discountValue'],json_encode($selection['snapshot'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        if($owns)$pdo->commit();
        return $order+['subtotal'=>$check['subtotal'],'tax_amount'=>$check['taxAmount'],'service_charge_amount'=>$check['serviceChargeAmount'],'total_amount'=>$check['totalAmount'],'package'=>$package,'redemption'=>['public_id'=>$redemptionPublic,'retail_amount'=>$selection['retail'],'discount_amount'=>(float)$discountRow['amount'],'package_amount'=>$packageAmount]];
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
