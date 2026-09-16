<?php
declare(strict_types=1);

require_once __DIR__.'/operational-access.php';
require_once __DIR__.'/pos-core.php';
require_once __DIR__.'/pos-item-actions.php';
require_once __DIR__.'/customer-crm-core.php';
require_once __DIR__.'/kds-core.php';
require_once __DIR__.'/table-service-core.php';
require_once __DIR__.'/service-ops-map.php';
require_once __DIR__.'/service-ops-atomic.php';
require_once __DIR__.'/service-visit-live.php';
require_once __DIR__.'/service-reservation-protection.php';
require_once __DIR__.'/table-turn-readiness.php';
require_once __DIR__.'/online-order-lifecycle.php';

function live_shift_clean(string $value,int $max=180): string
{
    return mb_substr(trim(preg_replace('/\s+/u',' ',$value)??$value),0,$max,'UTF-8');
}

function live_shift_norm(string $value): string
{
    $value=mb_strtolower(live_shift_clean($value,240),'UTF-8');
    return trim(preg_replace('/[^\pL\pN]+/u',' ',$value)??$value);
}

function live_shift_can_view(array $user): bool
{
    return app_has_permission('table_service.view',$user)||app_has_permission('pos.use',$user)||app_has_permission('kds.view',$user);
}

function live_shift_locations(PDO $pdo,array $user): array
{
    $org=(int)$user['organization_id'];$out=[];
    foreach(pos_locations($pdo,$org) as $location){
        $id=(int)$location['id'];
        if((app_has_permission('table_service.view',$user)&&operational_location_allowed($pdo,$user,'table_service.view',$id))
            ||(app_has_permission('pos.use',$user)&&operational_location_allowed($pdo,$user,'pos.use',$id))
            ||(app_has_permission('kds.view',$user)&&operational_location_allowed($pdo,$user,'kds.view',$id)))$out[]=$location;
    }
    return $out;
}

function live_shift_location(PDO $pdo,array $user,array $pageContext=[]): int
{
    $org=(int)$user['organization_id'];$requested=max(0,(int)($pageContext['locationId']??0));
    if($requested>0){
        pos_location($pdo,$org,$requested);
        foreach(live_shift_locations($pdo,$user) as $location)if((int)$location['id']===$requested)return $requested;
        throw new DomainException('Live Shift access is not assigned at that restaurant location.');
    }
    $primary=pos_primary_location_id($pdo,$org,(int)($user['membership_id']??0));
    if($primary){foreach(live_shift_locations($pdo,$user) as $location)if((int)$location['id']===$primary)return $primary;}
    $locations=live_shift_locations($pdo,$user);
    if(!$locations)throw new DomainException('No active restaurant location is assigned for Live Shift access.');
    return (int)$locations[0]['id'];
}

function live_shift_minutes(?string $date): int
{
    if(!$date)return 0;$ts=strtotime($date);if(!$ts)return 0;return max(0,(int)floor((time()-$ts)/60));
}

function live_shift_snapshot(PDO $pdo,array $user,int $locationId): array
{
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];
    $location=pos_location($pdo,$org,$locationId);
    $canTable=table_service_ready($pdo)&&app_has_permission('table_service.view',$user)&&operational_location_allowed($pdo,$user,'table_service.view',$locationId);
    $canPos=pos_ready($pdo)&&app_has_permission('pos.use',$user)&&operational_location_allowed($pdo,$user,'pos.use',$locationId);
    $canKds=kds_ready($pdo)&&app_has_permission('kds.view',$user)&&operational_location_allowed($pdo,$user,'kds.view',$locationId);
    $map=['tables'=>[],'staff'=>[],'sections'=>[]];
    if($canTable){service_visit_reconcile_live($pdo,$org,$locationId,$uid);$map=service_ops_canonical_map($pdo,$org,$locationId);}
    $board=$canKds?kds_board($pdo,$org,$locationId,null,false):['items'=>[],'stations'=>[],'unroutedCount'=>0];
    $kdsByCheck=[];
    foreach((array)$board['items'] as $item){
        $check=(string)($item['check_public_id']??'');if($check==='')continue;
        if(!isset($kdsByCheck[$check]))$kdsByCheck[$check]=['ready'=>0,'late'=>0,'held'=>0,'active'=>0,'items'=>[]];
        $kdsByCheck[$check]['active']++;
        if((string)$item['status']==='ready')$kdsByCheck[$check]['ready']++;
        if(!empty($item['late']))$kdsByCheck[$check]['late']++;
        if((string)$item['status']==='held')$kdsByCheck[$check]['held']++;
        $kdsByCheck[$check]['items'][]=$item;
    }
    $checks=[];
    if($canPos){
        foreach(array_slice(pos_open_checks($pdo,$org,$locationId),0,100) as $base){
            $public=(string)($base['publicId']??$base['public_id']??'');if($public==='')continue;
            try{$detail=pos_check_details($pdo,$org,$public);}catch(Throwable){continue;}
            $summary=kds_ready($pdo)?kds_check_summary($pdo,$org,$public):['sent'=>0,'unsent'=>count($detail['items']??[]),'unrouted'=>0,'items'=>[]];
            $ctx=table_service_ready($pdo)?table_service_context($pdo,$org,(int)$detail['id'],false):null;
            $checks[]=[
                'publicId'=>$public,'checkNumber'=>(string)$detail['checkNumber'],'tableName'=>(string)($detail['tableName']??''),'serviceMode'=>(string)$detail['serviceMode'],
                'guestCount'=>(int)$detail['guestCount'],'totalAmount'=>(float)$detail['totalAmount'],'balanceDue'=>(float)$detail['balanceDue'],'openedAt'=>$detail['openedAt']??null,
                'ageMinutes'=>live_shift_minutes($detail['openedAt']??null),'serverUserId'=>$ctx&&$ctx['server_user_id']!==null?(int)$ctx['server_user_id']:null,'serverName'=>$ctx['server_name']??null,
                'tablePublicId'=>$ctx['table_public_id']??null,'sent'=>(int)($summary['sent']??0),'unsent'=>(int)($summary['unsent']??0),'unrouted'=>(int)($summary['unrouted']??0),
                'ready'=>(int)($kdsByCheck[$public]['ready']??0),'late'=>(int)($kdsByCheck[$public]['late']??0),'held'=>(int)($kdsByCheck[$public]['held']??0),
                'items'=>array_values(array_map(static fn(array $i):array=>['id'=>(int)$i['id'],'name'=>(string)$i['item_name_snapshot'],'option'=>(string)($i['option_name_snapshot']??''),'quantity'=>(float)$i['quantity'],'note'=>(string)($i['special_instructions']??''),'status'=>(string)$i['status']],$detail['items']??[])),
            ];
        }
    }
    $readiness=[];
    if($canTable){try{$dash=table_turn_readiness_dashboard($pdo,$org,$locationId,service_ops_business_date($pdo,$org,$locationId),$uid);$readiness=(array)($dash['readinessQueue']??[]);}catch(Throwable){$readiness=[];}}
    $next=[];
    foreach($checks as $check){
        if($check['ready']>0)$next[]=['key'=>'ready:'.$check['publicId'],'severity'=>'high','score'=>96,'node'=>'live_shift','title'=>$check['ready'].' ready item'.($check['ready']===1?'':'s').' waiting on '.$check['checkNumber'],'detail'=>trim(($check['tableName']?:'Open check').' · '.($check['serverName']?:'unassigned server')),'checkPublicId'=>$check['publicId'],'prompt'=>'Read the ready items for '.$check['checkNumber'].' and tell me what needs to run now.'];
        if($check['late']>0)$next[]=['key'=>'late:'.$check['publicId'],'severity'=>'high','score'=>94,'node'=>'live_shift','title'=>$check['late'].' late kitchen item'.($check['late']===1?'':'s').' on '.$check['checkNumber'],'detail'=>trim(($check['tableName']?:'Open check').' · '.$check['ageMinutes'].'m open'),'checkPublicId'=>$check['publicId'],'prompt'=>'What is holding up '.$check['checkNumber'].' and what should happen next?'];
        if($check['unsent']>0&&$check['ageMinutes']>=8)$next[]=['key'=>'unsent:'.$check['publicId'],'severity'=>'normal','score'=>76,'node'=>'live_shift','title'=>$check['unsent'].' unsent item'.($check['unsent']===1?'':'s').' on '.$check['checkNumber'],'detail'=>trim(($check['tableName']?:'Open check').' · '.$check['ageMinutes'].'m open'),'checkPublicId'=>$check['publicId'],'prompt'=>'Review the unsent items on '.$check['checkNumber'].' and tell me whether they should be sent or held.'];
        if($check['ageMinutes']>=45)$next[]=['key'=>'age:'.$check['publicId'],'severity'=>$check['ageMinutes']>=75?'high':'normal','score'=>$check['ageMinutes']>=75?90:70,'node'=>'live_shift','title'=>$check['checkNumber'].' has been open '.$check['ageMinutes'].' minutes','detail'=>trim(($check['tableName']?:'Open check').' · balance $'.number_format($check['balanceDue'],2)),'checkPublicId'=>$check['publicId'],'prompt'=>'Review '.$check['checkNumber'].' and tell me what is holding up the visit.'];
    }
    foreach(array_slice($readiness,0,8) as $row){if(!in_array((string)($row['urgency']??''),['overdue','urgent'],true)&&empty($row['atRisk']))continue;$next[]=['key'=>'reset:'.(string)$row['tablePublicId'],'severity'=>'high','score'=>92,'node'=>'live_shift','title'=>(string)$row['tableName'].' needs reset attention','detail'=>!empty($row['nextReservation'])?'Ready-by risk before '.(string)$row['nextReservation']['scheduledAt']:'Table reset is outside the preferred service flow.','tablePublicId'=>$row['tablePublicId'],'prompt'=>'Review '.(string)$row['tableName'].' turn readiness and tell me the next service action.'];}
    usort($next,static fn(array $a,array $b):int=>$b['score']<=>$a['score']);
    return [
        'location'=>['id'=>$locationId,'name'=>(string)$location['name']],
        'tables'=>(array)($map['tables']??[]),'staff'=>(array)($map['staff']??[]),'sections'=>(array)($map['sections']??[]),'checks'=>$checks,
        'kds'=>['items'=>(array)$board['items'],'stations'=>(array)$board['stations'],'unroutedCount'=>(int)($board['unroutedCount']??0)],
        'readinessQueue'=>$readiness,'nextMoves'=>array_slice($next,0,20),
        'summary'=>['openChecks'=>count($checks),'occupiedTables'=>count(array_filter((array)($map['tables']??[]),static fn(array $t):bool=>!empty($t['checkPublicId']))),'readyItems'=>count(array_filter((array)$board['items'],static fn(array $i):bool=>(string)$i['status']==='ready')),'lateKitchenItems'=>count(array_filter((array)$board['items'],static fn(array $i):bool=>!empty($i['late']))),'heldKitchenItems'=>count(array_filter((array)$board['items'],static fn(array $i):bool=>(string)$i['status']==='held')),'unroutedKitchenItems'=>(int)($board['unroutedCount']??0),'urgentTableResets'=>count(array_filter($readiness,static fn(array $r):bool=>in_array((string)($r['urgency']??''),['overdue','urgent'],true)||!empty($r['atRisk'])))],
    ];
}

function live_shift_table(array $snapshot,string $needle,bool $availableOnly=false): array
{
    $needle=live_shift_norm($needle);$matches=[];
    foreach($snapshot['tables'] as $table){
        if($availableOnly&&(!empty($table['checkPublicId'])||(string)($table['status']??'active')!=='active'||($table['physicalReady']??true)===false))continue;
        $name=live_shift_norm((string)$table['name']);$public=live_shift_norm((string)$table['publicId']);
        if($needle===$name||$needle===$public||($needle!==''&&str_contains($needle,$name)))$matches[]=$table;
    }
    if(count($matches)===1)return $matches[0];
    if(!$matches)throw new InvalidArgumentException('I could not match that table or bar seat on the current floor.');
    throw new InvalidArgumentException('That matches more than one service point. Name the exact table or bar seat.');
}

function live_shift_check(array $snapshot,array $pageContext,string $message): array
{
    $public=live_shift_clean((string)($pageContext['checkPublicId']??''),100);
    if($public!=='')foreach($snapshot['checks'] as $check)if($check['publicId']===$public)return $check;
    $lower=live_shift_norm($message);
    foreach($snapshot['checks'] as $check){
        if($check['checkNumber']!==''&&str_contains($lower,live_shift_norm($check['checkNumber'])))return $check;
        if($check['tableName']!==''&&str_contains($lower,live_shift_norm($check['tableName'])))return $check;
    }
    $tablePublic=live_shift_clean((string)($pageContext['tablePublicId']??''),100);
    if($tablePublic!=='')foreach($snapshot['checks'] as $check)if((string)$check['tablePublicId']===$tablePublic)return $check;
    if(count($snapshot['checks'])===1)return $snapshot['checks'][0];
    throw new InvalidArgumentException('Name or select the table, bar seat, or check you want me to act on.');
}

function live_shift_staff(array $snapshot,string $needle): array
{
    $needle=live_shift_norm($needle);$matches=[];
    foreach($snapshot['staff'] as $staff){$name=live_shift_norm((string)$staff['name']);if($needle===$name||($name!==''&&str_contains($needle,$name)))$matches[]=$staff;}
    if(count($matches)===1)return $matches[0];
    if(!$matches)throw new InvalidArgumentException('I could not match that active staff member at this location.');
    throw new InvalidArgumentException('That staff name is ambiguous. Use the full name.');
}

function live_shift_customer(PDO $pdo,int $org,string $needle): array
{
    $rows=crm_search($pdo,$org,live_shift_clean($needle,200),10,true);
    if(count($rows)===1)return $rows[0];
    $norm=live_shift_norm($needle);$exact=array_values(array_filter($rows,static fn(array $r):bool=>live_shift_norm((string)$r['displayName'])===$norm));
    if(count($exact)===1)return $exact[0];
    if(!$rows)throw new InvalidArgumentException('I could not find that customer in CRM.');
    throw new InvalidArgumentException('More than one customer matches. Use the full name, email, or phone.');
}

function live_shift_menu_price(PDO $pdo,int $org,string $needle): array
{
    $norm=live_shift_norm($needle);$matches=[];
    foreach(pos_menu($pdo,$org) as $section)foreach((array)$section['items'] as $item){$name=live_shift_norm((string)$item['name']);if($norm===$name||($name!==''&&str_contains($norm,$name))){$price=$item['prices'][0]??null;if($price)$matches[]=['item'=>$item,'price'=>$price];}}
    if(count($matches)===1)return $matches[0];
    if(!$matches)throw new InvalidArgumentException('I could not match that active menu item.');
    throw new InvalidArgumentException('That menu request is ambiguous. Name the exact menu item or option.');
}

function live_shift_line(PDO $pdo,int $org,string $checkPublicId,array $pageContext,string $needle=''): array
{
    $check=pos_check_details($pdo,$org,$checkPublicId);$active=array_values(array_filter($check['items']??[],static fn(array $i):bool=>(string)$i['status']==='active'));
    $focused=max(0,(int)($pageContext['focusedLineId']??0));if($focused>0)foreach($active as $line)if((int)$line['id']===$focused)return $line;
    $norm=live_shift_norm($needle);$matches=[];foreach($active as $line){$name=live_shift_norm((string)$line['item_name_snapshot']);if($norm!==''&&($norm===$name||str_contains($norm,$name)))$matches[]=$line;}
    if(count($matches)===1)return $matches[0];if(!$matches&&count($active)===1)return $active[0];
    if(!$matches)throw new InvalidArgumentException('Select or name the ticket line you want to change.');
    throw new InvalidArgumentException('That item appears more than once. Select the exact ticket line.');
}

function live_shift_action(PDO $pdo,array $user,array $snapshot,array $pageContext,string $message): ?array
{
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];$locationId=(int)$snapshot['location']['id'];$text=live_shift_clean($message,1600);$lower=mb_strtolower($text,'UTF-8');
    if(preg_match('/\b(void|discount|comp|refund|refunds|tender|payment|cash out|close paid|reopen paid)\b/u',$lower))return ['protected'=>true,'answer'=>'That is a protected POS action. Use the existing POS void/discount/payment flow so its permission, reason, tender, and audit safeguards remain intact.','sources'=>['POS protected action policy']];

    if(preg_match('/\bseat\s+(?:a\s+)?(?:party\s+of\s+)?(\d{1,2})\s+(?:at|on|to)\s+(.+)$/iu',$text,$m)){
        if(!app_has_permission('table_service.use',$user)||!operational_location_allowed($pdo,$user,'table_service.use',$locationId))throw new DomainException('Table Service operating permission is required for seating.');
        $table=live_shift_table($snapshot,$m[2],true);$party=max(1,min(99,(int)$m[1]));
        $check=service_reservation_protection_party_seat($pdo,$org,$locationId,(string)$table['publicId'],$party,null,'Seated by Main Agent Brain',$uid);
        app_audit($pdo,$org,$uid,'agent.live_shift.party_seated','pos_check',(string)$check['publicId'],null,['sourceNode'=>'live_shift','locationId'=>$locationId,'tablePublicId'=>$table['publicId'],'partySize'=>$party]);
        return ['answer'=>'Seated '.$party.' at '.$table['name'].' and opened check '.$check['checkNumber'].'.','sources'=>['Table Service → '.(string)$table['name']],'check'=>$check];
    }

    if(preg_match('/\b(?:move|transfer)\s+(?:this\s+)?(?:check|ticket|order|table)?\s*(?:to)\s+(.+)$/iu',$text,$m)&&!preg_match('/\bserver\b/u',$lower)){
        if(!app_has_permission('table_service.use',$user)||!operational_location_allowed($pdo,$user,'table_service.use',$locationId))throw new DomainException('Table Service operating permission is required for table transfer.');
        $check=live_shift_check($snapshot,$pageContext,$text);$dest=live_shift_table($snapshot,$m[1],true);
        $updated=service_reservation_protection_transfer($pdo,$org,(string)$check['publicId'],(string)$dest['publicId'],$uid);
        app_audit($pdo,$org,$uid,'agent.live_shift.table_transferred','pos_check',(string)$check['publicId'],null,['sourceNode'=>'live_shift','locationId'=>$locationId,'destinationTablePublicId'=>$dest['publicId']]);
        return ['answer'=>'Moved '.$check['checkNumber'].' to '.$dest['name'].'.','sources'=>['Table Service → '.(string)$dest['name']],'check'=>$updated];
    }

    if(preg_match('/\b(?:assign|transfer|change)\s+(?:the\s+)?server(?:\s+(?:for|on)\s+(?:this\s+)?(?:check|table|order))?\s+(?:to\s+)?(.+)$/iu',$text,$m)){
        if(!app_has_permission('table_service.use',$user)||!operational_location_allowed($pdo,$user,'table_service.use',$locationId))throw new DomainException('Table Service operating permission is required for server assignment.');
        $check=live_shift_check($snapshot,$pageContext,$text);$staff=live_shift_staff($snapshot,$m[1]);$updated=service_ops_assign_server($pdo,$org,(string)$check['publicId'],(int)$staff['id'],$uid);
        app_audit($pdo,$org,$uid,'agent.live_shift.server_assigned','pos_check',(string)$check['publicId'],null,['sourceNode'=>'live_shift','locationId'=>$locationId,'serverUserId'=>$staff['id']]);
        return ['answer'=>'Assigned '.$staff['name'].' to '.$check['checkNumber'].'.','sources'=>['Table Service staff assignment'],'check'=>$updated];
    }

    if(preg_match('/\b(?:fire|hold)\s+(?:the\s+)?(drinks|starters|mains|dessert|other)(?:\s+course)?\b/iu',$text,$m)){
        if(!app_has_permission('table_service.use',$user)||!operational_location_allowed($pdo,$user,'table_service.use',$locationId))throw new DomainException('Table Service operating permission is required for course control.');
        $check=live_shift_check($snapshot,$pageContext,$text);$held=str_starts_with($lower,'hold')||preg_match('/\bhold\b/u',$lower);$result=table_service_fire_course($pdo,$org,(string)$check['publicId'],mb_strtolower($m[1],'UTF-8'),$uid,$held);
        app_audit($pdo,$org,$uid,$held?'agent.live_shift.course_held':'agent.live_shift.course_fired','pos_check',(string)$check['publicId'],null,['sourceNode'=>'live_shift','locationId'=>$locationId,'courseKey'=>mb_strtolower($m[1],'UTF-8')]);
        return ['answer'=>($held?'Sent ':'Fired ').mb_strtolower($m[1],'UTF-8').' for '.$check['checkNumber'].($held?' as held.':'.'),'sources'=>['Table Service course control'],'result'=>$result];
    }

    if(preg_match('/\b(?:send|fire)\s+(?:this\s+|the\s+)?(?:check|ticket|order|unsent items?|items?)?(?:\s+to\s+(?:the\s+)?kitchen)?\b/iu',$text)&&!preg_match('/\b(drinks|starters|mains|dessert|other)\b/u',$lower)){
        if(!app_has_permission('pos.use',$user)||!operational_location_allowed($pdo,$user,'pos.use',$locationId))throw new DomainException('POS operating permission is required to send items to the kitchen.');
        $check=live_shift_check($snapshot,$pageContext,$text);$hold=preg_match('/\bhold|held\b/u',$lower)===1;$summary=kds_send_check($pdo,$org,(string)$check['publicId'],$uid,$hold);online_order_lifecycle_sync_safe($pdo,$org,(string)$check['publicId'],$uid);
        app_audit($pdo,$org,$uid,'agent.live_shift.kitchen_sent','pos_check',(string)$check['publicId'],null,['sourceNode'=>'live_shift','locationId'=>$locationId,'held'=>$hold,'sent'=>$summary['sent'],'unsent'=>$summary['unsent']]);
        return ['answer'=>($hold?'Sent unsent items as held for ':'Sent unsent items to the kitchen for ').$check['checkNumber'].'.','sources'=>['KDS → '.$check['checkNumber']],'kitchen'=>$summary];
    }

    if(preg_match('/\battach\s+(?:customer\s+)?(.+?)(?:\s+to\s+(?:this\s+)?(?:check|ticket|order))?$/iu',$text,$m)){
        if(!crm_ready($pdo)||!app_has_permission('crm.pos_link',$user)||!operational_location_allowed($pdo,$user,'crm.pos_link',$locationId))throw new DomainException('CRM POS-link permission is required to attach a customer.');
        $check=live_shift_check($snapshot,$pageContext,$text);$customer=live_shift_customer($pdo,$org,$m[1]);$linked=service_visit_attach_customer_safe($pdo,$org,(string)$check['publicId'],(string)$customer['publicId']);
        app_audit($pdo,$org,$uid,'agent.live_shift.customer_attached','pos_check',(string)$check['publicId'],null,['sourceNode'=>'live_shift','locationId'=>$locationId,'customerPublicId'=>$customer['publicId']]);
        return ['answer'=>'Attached '.$customer['displayName'].' to '.$check['checkNumber'].'.','sources'=>['Customer CRM'],'customer'=>$linked];
    }

    if(preg_match('/\b(?:remove|delete)\s+(.+?)(?:\s+from\s+(?:this\s+)?(?:check|ticket|order))?$/iu',$text,$m)){
        if(!app_has_permission('pos.use',$user)||!operational_location_allowed($pdo,$user,'pos.use',$locationId))throw new DomainException('POS operating permission is required to remove an unsent item.');
        $check=live_shift_check($snapshot,$pageContext,$text);$line=live_shift_line($pdo,$org,(string)$check['publicId'],$pageContext,$m[1]);$result=pos_item_remove_unsent($pdo,$org,(string)$check['publicId'],(int)$line['id'],$uid);
        app_audit($pdo,$org,$uid,'agent.live_shift.item_removed','pos_check',(string)$check['publicId'],null,['sourceNode'=>'live_shift','locationId'=>$locationId,'itemId'=>(int)$line['id']]);
        return ['answer'=>(string)$line['item_name_snapshot'].' removed from '.$check['checkNumber'].'.','sources'=>['POS ticket'],'check'=>$result['check']];
    }

    if(preg_match('/\b(?:add|set|change)\s+(?:the\s+)?(?:item\s+)?note\s*(?::|to)?\s*(.+)$/iu',$text,$m)){
        if(!app_has_permission('pos.use',$user)||!operational_location_allowed($pdo,$user,'pos.use',$locationId))throw new DomainException('POS operating permission is required to update an unsent ticket line.');
        $check=live_shift_check($snapshot,$pageContext,$text);$line=live_shift_line($pdo,$org,(string)$check['publicId'],$pageContext,'');if(kds_ready($pdo))kds_assert_pos_line_mutable($pdo,$org,(int)$line['id']);$note=mb_substr(trim($m[1]),0,1000,'UTF-8');if($note==='')throw new InvalidArgumentException('Tell me what note to add.');
        $updated=pos_update_item($pdo,$org,(string)$check['publicId'],(int)$line['id'],(float)$line['quantity'],$note);
        app_audit($pdo,$org,$uid,'agent.live_shift.item_note_updated','pos_check',(string)$check['publicId'],null,['sourceNode'=>'live_shift','locationId'=>$locationId,'itemId'=>(int)$line['id']]);
        return ['answer'=>'Updated the unsent '.$line['item_name_snapshot'].' note on '.$check['checkNumber'].'.','sources'=>['POS ticket'],'check'=>$updated];
    }

    if(preg_match('/^add\s+(?:(\d+(?:\.\d+)?)\s+)?(.+?)(?:\s+to\s+(?:this\s+)?(?:check|ticket|order))?[.!]?$/iu',$text,$m)&&!preg_match('/\b(note|customer|tag|discount|comp)\b/u',$lower)){
        if(!app_has_permission('pos.use',$user)||!operational_location_allowed($pdo,$user,'pos.use',$locationId))throw new DomainException('POS operating permission is required to add an item.');
        $check=live_shift_check($snapshot,$pageContext,$text);$quantity=isset($m[1])&&$m[1]!==''?max(.01,min(99,(float)$m[1])):1.0;$match=live_shift_menu_price($pdo,$org,$m[2]);$updated=pos_add_item($pdo,$org,(string)$check['publicId'],(int)$match['price']['id'],$quantity,'',$uid);
        app_audit($pdo,$org,$uid,'agent.live_shift.item_added','pos_check',(string)$check['publicId'],null,['sourceNode'=>'live_shift','locationId'=>$locationId,'menuItemId'=>$match['item']['id'],'priceId'=>$match['price']['id'],'quantity'=>$quantity]);
        return ['answer'=>'Added '.rtrim(rtrim(number_format($quantity,2,'.',''),'0'),'.').' × '.$match['item']['name'].' to '.$check['checkNumber'].'.','sources'=>['POS menu'],'check'=>$updated];
    }
    return null;
}

function live_shift_answer(array $snapshot,array $pageContext,string $message): array
{
    $text=live_shift_norm($message);
    if(preg_match('/\b(what needs attention|what should i do|what should we do|next moves?|what is happening|what\x27s happening|shift status|service status|run the shift)\b/u',$text)){
        if(!$snapshot['nextMoves'])return ['answer'=>'Live service is currently clear: no ready-food, late-kitchen, unsent-age, long-check, or urgent table-reset exception is active.','sources'=>['Live Shift snapshot']];
        $lines=[];foreach(array_slice($snapshot['nextMoves'],0,8) as $move)$lines[]=$move['title'].' — '.$move['detail'];
        return ['answer'=>"Live Shift priorities:\n- ".implode("\n- ",$lines),'sources'=>['POS live checks','KDS live board','Table Service floor','Table Turn readiness']];
    }
    if(preg_match('/\b(ready|food up|run food|expo)\b/u',$text)){
        $rows=array_values(array_filter($snapshot['kds']['items'],static fn(array $i):bool=>(string)$i['status']==='ready'));
        if(!$rows)return ['answer'=>'There are no KDS items in READY state at this location right now.','sources'=>['KDS live board']];
        $lines=[];foreach(array_slice($rows,0,20) as $r)$lines[]=(string)$r['check_number'].' · '.((string)($r['table_name']??'')?:'no table').' · '.(float)$r['quantity'].'× '.(string)$r['item_name_snapshot'].(!empty($r['special_instructions'])?' · '.$r['special_instructions']:'');
        return ['answer'=>"Ready now:\n- ".implode("\n- ",$lines),'sources'=>['KDS live board']];
    }
    try{$check=live_shift_check($snapshot,$pageContext,$message);$lineItems=[];foreach($check['items'] as $i)$lineItems[]=(float)$i['quantity'].'× '.$i['name'].($i['note']!==''?' · '.$i['note']:'');return ['answer'=>$check['checkNumber'].' · '.($check['tableName']?:str_replace('_',' ',$check['serviceMode'])).' · '.($check['serverName']?:'unassigned server').' · '.$check['ageMinutes'].'m open · $'.number_format($check['balanceDue'],2).' due. Kitchen: '.$check['sent'].' sent, '.$check['unsent'].' unsent, '.$check['ready'].' ready, '.$check['late'].' late, '.$check['held'].' held.'.($lineItems?"\nItems: ".implode('; ',$lineItems):''),'sources'=>['POS live check','KDS live board','Table Service context']];}catch(Throwable){}
    $s=$snapshot['summary'];
    return ['answer'=>'Live Shift at '.$snapshot['location']['name'].': '.$s['openChecks'].' open checks, '.$s['occupiedTables'].' occupied tables/bar seats, '.$s['readyItems'].' ready kitchen items, '.$s['lateKitchenItems'].' late kitchen items, '.$s['heldKitchenItems'].' held, '.$s['unroutedKitchenItems'].' unrouted, and '.$s['urgentTableResets'].' urgent table-reset risks.','sources'=>['Live Shift snapshot']];
}

function live_shift_handle(PDO $pdo,array $user,array $pageContext,string $message): array
{
    $locationId=live_shift_location($pdo,$user,$pageContext);$snapshot=live_shift_snapshot($pdo,$user,$locationId);
    $action=live_shift_action($pdo,$user,$snapshot,$pageContext,$message);
    if($action!==null)return $action+['locationId'=>$locationId,'node'=>'live_shift'];
    return live_shift_answer($snapshot,$pageContext,$message)+['snapshot'=>$snapshot,'locationId'=>$locationId,'node'=>'live_shift'];
}
