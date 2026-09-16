<?php
declare(strict_types=1);

require_once __DIR__.'/host-stand-core.php';
require_once __DIR__.'/table-service-reconcile.php';
require_once __DIR__.'/service-ops-hardening.php';
require_once __DIR__.'/service-ops-atomic.php';
require_once __DIR__.'/service-ops-reservation.php';
require_once __DIR__.'/service-visit-live.php';
require_once __DIR__.'/service-reservation-protection.php';
require_once __DIR__.'/table-cleaning-lifecycle.php';
require_once __DIR__.'/table-turn-readiness.php';
require_once __DIR__.'/agent-confirmation-core.php';

final class FrontOfHouseAgentPermissionException extends RuntimeException {}

function foh_can_read(array $user): bool
{
    return app_has_permission('host.view',$user)
        && (app_has_permission('table_service.view',$user)
            || app_has_permission('host.use',$user)
            || app_has_permission('host.manage',$user));
}

function foh_require_read(array $user): void
{
    if(!foh_can_read($user))throw new FrontOfHouseAgentPermissionException('Host Stand access is required.');
}

function foh_require_use(array $user): void
{
    if(!app_has_permission('host.use',$user))throw new FrontOfHouseAgentPermissionException('Host Stand operating permission is required for this action.');
}

function foh_clean_id(mixed $value): string
{
    return preg_replace('/[^A-Za-z0-9_.:-]/','',trim((string)$value))??'';
}

function foh_location_id(PDO $pdo,array $user,array $context): int
{
    $org=(int)$user['organization_id'];
    $locationId=(int)($context['locationId']??0);
    if($locationId>0){table_service_location($pdo,$org,$locationId);return $locationId;}
    $membership=(int)($user['membership_id']??0);
    if($membership>0){$primary=pos_primary_location_id($pdo,$org,$membership);if($primary){table_service_location($pdo,$org,$primary);return $primary;}}
    $locations=pos_locations($pdo,$org);
    if(!$locations)throw new InvalidArgumentException('Create an active restaurant location first.');
    return (int)$locations[0]['id'];
}

function foh_business_date(PDO $pdo,int $org,int $locationId,array $context): string
{
    $candidate=trim((string)($context['date']??''));
    if($candidate!==''){
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$candidate,service_ops_timezone($pdo,$org,$locationId));
        if($d&&$d->format('Y-m-d')===$candidate)return $candidate;
    }
    return service_ops_business_date($pdo,$org,$locationId);
}

function foh_dashboard(PDO $pdo,array $user,array $context): array
{
    foh_require_read($user);
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];$locationId=foh_location_id($pdo,$user,$context);$date=foh_business_date($pdo,$org,$locationId,$context);
    if(!host_ready($pdo)||!service_visit_ready($pdo)||!table_cleaning_ready($pdo)||!table_turn_policy_ready($pdo))throw new RuntimeException('Host Stand migrations are not installed. Run upgrade.php.');
    service_visit_reconcile_live($pdo,$org,$locationId,$uid);
    $dashboard=service_reservation_protection_dashboard($pdo,$org,$locationId,$date,$uid);
    $dashboard=table_turn_readiness_enrich_dashboard($pdo,$org,$locationId,$date,$dashboard);
    return ['locationId'=>$locationId,'date'=>$date,'dashboard'=>$dashboard];
}

function foh_context_reservation(array $dashboard,array $context): ?array
{
    $public=foh_clean_id($context['reservationPublicId']??$context['selectedReservationPublicId']??'');
    if($public==='')return null;
    foreach((array)($dashboard['reservations']??[]) as $row)if((string)($row['publicId']??'')===$public)return $row;
    return null;
}

function foh_context_table(array $dashboard,array $context): ?array
{
    $public=foh_clean_id($context['tablePublicId']??$context['selectedTablePublicId']??'');
    if($public==='')return null;
    foreach((array)($dashboard['tables']??[]) as $row)if((string)($row['publicId']??'')===$public)return $row;
    return null;
}

function foh_find_reservation(array $dashboard,string $message,array $context): ?array
{
    if($selected=foh_context_reservation($dashboard,$context))return $selected;
    $lower=mb_strtolower($message,'UTF-8');$matches=[];
    foreach((array)($dashboard['reservations']??[]) as $row){
        $name=mb_strtolower(trim((string)($row['guestName']??'')),'UTF-8');
        $public=mb_strtolower((string)($row['publicId']??''),'UTF-8');
        if(($name!==''&&str_contains($lower,$name))||($public!==''&&str_contains($lower,$public)))$matches[]=$row;
    }
    return count($matches)===1?$matches[0]:null;
}

function foh_find_table(array $dashboard,string $message,array $context): ?array
{
    if($selected=foh_context_table($dashboard,$context))return $selected;
    $lower=mb_strtolower($message,'UTF-8');$matches=[];
    foreach((array)($dashboard['tables']??[]) as $row){
        $name=mb_strtolower(trim((string)($row['name']??'')),'UTF-8');$public=mb_strtolower((string)($row['publicId']??''),'UTF-8');
        if(($name!==''&&preg_match('/(?:^|\b)'.preg_quote($name,'/').'(?:\b|$)/u',$lower))||($public!==''&&str_contains($lower,$public)))$matches[]=$row;
    }
    return count($matches)===1?$matches[0]:null;
}

function foh_snapshot(PDO $pdo,array $user,array $context): array
{
    $base=foh_dashboard($pdo,$user,$context);$dashboard=$base['dashboard'];$tables=(array)($dashboard['tables']??[]);$reservations=(array)($dashboard['reservations']??[]);
    $activeReservations=array_values(array_filter($reservations,static fn(array $r):bool=>(string)($r['type']??'reservation')==='reservation'&&!in_array((string)($r['status']??''),['cancelled','no_show','completed'],true)));
    $waitlist=array_values(array_filter($reservations,static fn(array $r):bool=>(string)($r['type']??'')==='waitlist'&&in_array((string)($r['status']??''),['waiting','arrived'],true)));
    return $base+[
        'tables'=>$tables,'reservations'=>$reservations,'activeReservations'=>$activeReservations,'waitlist'=>$waitlist,
        'availableTables'=>array_values(array_filter($tables,static fn(array $t):bool=>!empty($t['reservableNow']))),
        'seatedTables'=>array_values(array_filter($tables,static fn(array $t):bool=>!empty($t['activeCheckId']))),
        'dirtyTables'=>array_values(array_filter($tables,static fn(array $t):bool=>(string)($t['state']??'')==='dirty')),
        'cleaningTables'=>array_values(array_filter($tables,static fn(array $t):bool=>(string)($t['state']??'')==='cleaning')),
    ];
}

function foh_reservation_answer(array $r): string
{
    $tables=implode(' + ',array_values(array_filter(array_map(static fn(array $t):string=>(string)($t['name']??''),(array)($r['tables']??[])))))?:'unassigned';
    if((string)($r['type']??'reservation')==='waitlist'){
        $quote=$r['quotedWaitMinutes']??null;
        return (string)$r['guestName'].' is '.$r['status'].' on the waitlist for '.(int)$r['partySize'].' guest'.((int)$r['partySize']===1?'':'s').', assigned to '.$tables.($quote!==null?' Quoted wait: '.(int)$quote.' minutes.':'');
    }
    $when=(string)($r['scheduledAt']??'');
    return (string)$r['guestName'].' is '.$r['status'].' for '.(int)$r['partySize'].' guest'.((int)$r['partySize']===1?'':'s').' at '.($when!==''?$when:'an unscheduled time').', assigned to '.$tables.'.';
}

function foh_table_answer(array $t): string
{
    $answer=(string)$t['name'].' seats '.(int)$t['capacity'].' and is currently '.(string)$t['state'].'.';
    if(!empty($t['activeCheckId']))$answer.=' It has an active check'.(!empty($t['checkNumber'])?' #'.$t['checkNumber']:'').'.';
    elseif(!empty($t['reservationProtected']))$answer.=' It is protected for the next reservation'.(!empty($t['nextReservationAt'])?' at '.$t['nextReservationAt']:'').'.';
    elseif(!empty($t['reservableNow']))$answer.=' It is available to seat now.';
    if(empty($t['physicalReady']))$answer.=' The physical table is not currently service-ready.';
    return $answer;
}

function foh_overview_answer(array $snapshot): string
{
    return 'Front-of-house snapshot for '.$snapshot['date'].': '.count($snapshot['availableTables']).' table'.(count($snapshot['availableTables'])===1?'':'s').' available now, '.count($snapshot['seatedTables']).' seated, '.count($snapshot['activeReservations']).' active reservation'.(count($snapshot['activeReservations'])===1?'':'s').', '.count($snapshot['waitlist']).' waitlist part'.(count($snapshot['waitlist'])===1?'y':'ies').', '.count($snapshot['dirtyTables']).' dirty and '.count($snapshot['cleaningTables']).' being cleaned.';
}

function foh_reservation_row_version(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare('SELECT id,location_id,reservation_type,status,updated_at FROM guest_reservations WHERE organization_id=? AND public_id=? LIMIT 1');$q->execute([$org,$publicId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Reservation or waitlist entry was not found.');
    return $row;
}

function foh_table_row_version(PDO $pdo,int $org,int $locationId,string $publicId): array
{
    $q=$pdo->prepare('SELECT id,state,active_check_id,updated_at FROM service_tables WHERE organization_id=? AND location_id=? AND public_id=? LIMIT 1');$q->execute([$org,$locationId,$publicId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Service table was not found.');
    return $row;
}

function foh_reservation_table_signature(PDO $pdo,int $org,int $reservationId): string
{
    $tables=host_reservation_tables($pdo,$org,$reservationId);$ids=array_map('strval',array_column($tables,'publicId'));sort($ids,SORT_STRING);return hash('sha256',implode('|',$ids));
}

function foh_guard_reservation(PDO $pdo,int $org,array $payload): array
{
    $public=foh_clean_id($payload['reservationPublicId']??'');$row=foh_reservation_row_version($pdo,$org,$public);
    if((string)$row['status']!==(string)($payload['expectedStatus']??'')||(string)$row['updated_at']!==(string)($payload['expectedUpdatedAt']??''))throw new InvalidArgumentException('That reservation changed after I proposed the action. Refresh it and ask again.');
    return $row;
}

function foh_guard_table(PDO $pdo,int $org,int $locationId,array $payload): array
{
    $public=foh_clean_id($payload['tablePublicId']??'');$row=foh_table_row_version($pdo,$org,$locationId,$public);
    $expectedCheck=$payload['expectedActiveCheckId']??null;$actualCheck=$row['active_check_id']!==null?(int)$row['active_check_id']:null;
    if((string)$row['state']!==(string)($payload['expectedState']??'')||(string)$row['updated_at']!==(string)($payload['expectedUpdatedAt']??'')||$actualCheck!==$expectedCheck)throw new InvalidArgumentException('That table changed after I proposed the action. Refresh the floor and ask again.');
    return $row;
}

function foh_execute_pending(PDO $pdo,array $user,array $proposal): array
{
    foh_require_use($user);$org=(int)$user['organization_id'];$uid=(int)$user['id'];
    if((int)($proposal['organizationId']??0)!==$org||(int)($proposal['userId']??0)!==$uid)throw new FrontOfHouseAgentPermissionException('This Front of House proposal does not belong to your session.');
    $type=(string)($proposal['type']??'');$payload=(array)($proposal['payload']??[]);$locationId=(int)($payload['locationId']??0);if($locationId>0)table_service_location($pdo,$org,$locationId);
    if($type==='reservation_status'){
        $row=foh_guard_reservation($pdo,$org,$payload);$status=(string)($payload['status']??'');
        $reservation=service_ops_reservation_status_atomic($pdo,$org,(string)$payload['reservationPublicId'],$status,$uid);
        app_audit($pdo,$org,$uid,'front_of_house.agent_reservation_status','guest_reservation',(string)$payload['reservationPublicId'],['status'=>$row['status']],['status'=>$reservation['status'],'proposalId'=>$proposal['id']]);
        return ['skill'=>'front_of_house.action_confirmed','answer'=>'Confirmed. '.(string)$reservation['guestName'].' is now '.(string)$reservation['status'].'.','data'=>['action'=>'reservation_status','reservation'=>$reservation],'sources'=>['Host Stand']];
    }
    if($type==='reservation_assign'){
        $row=foh_guard_reservation($pdo,$org,$payload);$tableIds=array_values(array_unique(array_filter(array_map('strval',(array)($payload['tablePublicIds']??[])))));if(!$tableIds)throw new InvalidArgumentException('No table is attached to this proposal.');
        foreach($tableIds as $tableId)foh_guard_table($pdo,$org,$locationId,['tablePublicId'=>$tableId,'expectedState'=>$payload['tableVersions'][$tableId]['state']??'','expectedUpdatedAt'=>$payload['tableVersions'][$tableId]['updatedAt']??'','expectedActiveCheckId'=>$payload['tableVersions'][$tableId]['activeCheckId']??null]);
        $reservation=service_reservation_protection_reservation_assign($pdo,$org,(string)$payload['reservationPublicId'],$tableIds,$uid);
        app_audit($pdo,$org,$uid,'front_of_house.agent_reservation_assign','guest_reservation',(string)$payload['reservationPublicId'],null,['tables'=>$tableIds,'proposalId'=>$proposal['id']]);
        return ['skill'=>'front_of_house.action_confirmed','answer'=>'Confirmed. Assigned '.(string)$reservation['guestName'].' to '.implode(' + ',array_map(static fn(array $t):string=>(string)$t['name'],(array)$reservation['tables'])).'.','data'=>['action'=>'reservation_assign','reservation'=>$reservation],'sources'=>['Host Stand','Table Seatability']];
    }
    if($type==='reservation_seat'){
        $row=foh_guard_reservation($pdo,$org,$payload);$signature=foh_reservation_table_signature($pdo,$org,(int)$row['id']);if($signature!==(string)($payload['tableSignature']??''))throw new InvalidArgumentException('The reservation table assignment changed after I proposed seating it. Refresh and ask again.');
        $result=service_reservation_protection_host_seat($pdo,$org,(string)$payload['reservationPublicId'],null,$uid);
        app_audit($pdo,$org,$uid,'front_of_house.agent_reservation_seated','guest_reservation',(string)$payload['reservationPublicId'],null,['checkPublicId'=>$result['check']['publicId']??null,'proposalId'=>$proposal['id']]);
        return ['skill'=>'front_of_house.action_confirmed','answer'=>'Confirmed. Seated '.(string)$result['reservation']['guestName'].' and opened the canonical POS check'.(!empty($result['check']['checkNumber'])?' #'.$result['check']['checkNumber']:'').'.','data'=>['action'=>'reservation_seat']+$result,'sources'=>['Host Stand','Native POS','Table Seatability']];
    }
    if($type==='table_cleaning_start'){
        foh_guard_table($pdo,$org,$locationId,$payload);$table=table_cleaning_start($pdo,$org,$locationId,(string)$payload['tablePublicId'],$uid);
        app_audit($pdo,$org,$uid,'front_of_house.agent_table_cleaning_started','service_table',(string)$payload['tablePublicId'],null,['proposalId'=>$proposal['id']]);
        return ['skill'=>'front_of_house.action_confirmed','answer'=>'Confirmed. Cleaning is now in progress for '.$payload['tableName'].'.','data'=>['action'=>'table_cleaning_start','table'=>$table],'sources'=>['Host Stand','Table Cleaning Lifecycle']];
    }
    if($type==='table_ready'){
        foh_guard_table($pdo,$org,$locationId,$payload);$table=table_cleaning_mark_ready($pdo,$org,$locationId,(string)$payload['tablePublicId'],$uid);
        app_audit($pdo,$org,$uid,'front_of_house.agent_table_ready','service_table',(string)$payload['tablePublicId'],null,['proposalId'=>$proposal['id']]);
        return ['skill'=>'front_of_house.action_confirmed','answer'=>'Confirmed. '.$payload['tableName'].' is ready for service.','data'=>['action'=>'table_ready','table'=>$table],'sources'=>['Host Stand','Table Cleaning Lifecycle']];
    }
    throw new InvalidArgumentException('That pending Front of House action is no longer supported.');
}

function foh_status_intent(string $message): ?string
{
    $lower=mb_strtolower($message,'UTF-8');
    if(preg_match('/\b(no[ -]?show|mark .*no[ -]?show)\b/u',$lower))return 'no_show';
    if(preg_match('/\b(cancel|cancelled|cancel reservation|remove from waitlist)\b/u',$lower))return 'cancelled';
    if(preg_match('/\b(arrived|check[ -]?in|guest is here|party is here|mark .*arrived)\b/u',$lower))return 'arrived';
    if(preg_match('/\b(confirm reservation|mark .*confirmed|reservation confirmed)\b/u',$lower))return 'confirmed';
    return null;
}

function front_of_house_agent_handle(PDO $pdo,array $user,array $input): array
{
    foh_require_read($user);$org=(int)$user['organization_id'];$uid=(int)$user['id'];$message=trim((string)($input['message']??''));if($message===''||mb_strlen($message,'UTF-8')>2400)throw new InvalidArgumentException('Ask Gelato a front-of-house question no longer than 2,400 characters.');
    $context=is_array($input['pageContext']??null)?$input['pageContext']:[];$pending=gac_pending_get('front_of_house',$org,$uid);
    if(gac_is_confirm($message)){
        if(!$pending)throw new InvalidArgumentException('There is no pending Front of House action to confirm.');
        try{$result=foh_execute_pending($pdo,$user,$pending);}catch(Throwable $e){gac_pending_clear('front_of_house',$org,$uid);throw $e;}
        gac_pending_clear('front_of_house',$org,$uid);app_audit($pdo,$org,$uid,'front_of_house.agent_action_confirmed','front_of_house_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);return ['ok'=>true]+$result;
    }
    if(gac_is_cancel($message)&&$pending){gac_pending_clear('front_of_house',$org,$uid);app_audit($pdo,$org,$uid,'front_of_house.agent_action_discarded','front_of_house_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);return ['ok'=>true,'skill'=>'front_of_house.action_cancelled','answer'=>'Cancelled. I did not change the reservation, waitlist, seating, or table state.','data'=>['cancelledProposal'=>$pending['id']],'sources'=>['Front of House Agent']];}

    $snapshot=foh_snapshot($pdo,$user,$context);$reservation=foh_find_reservation($snapshot['dashboard'],$message,$context);$table=foh_find_table($snapshot['dashboard'],$message,$context);$lower=mb_strtolower($message,'UTF-8');

    $status=foh_status_intent($message);
    if($status!==null){
        foh_require_use($user);if(!$reservation)throw new InvalidArgumentException('Select the reservation/waitlist entry in Host Stand or name the guest first.');
        if((string)$reservation['status']===$status)return ['ok'=>true,'skill'=>'front_of_house.reservation','answer'=>(string)$reservation['guestName'].' is already '.$status.'.','data'=>['reservation'=>$reservation],'sources'=>['Host Stand']];
        $row=foh_reservation_row_version($pdo,$org,(string)$reservation['publicId']);
        $proposal=gac_pending_store('front_of_house',$org,$uid,'reservation_status',['reservationPublicId'=>$reservation['publicId'],'locationId'=>$snapshot['locationId'],'status'=>$status,'expectedStatus'=>$row['status'],'expectedUpdatedAt'=>$row['updated_at']],'Proposed action: mark “'.$reservation['guestName'].'” as '.$status.'.');
        return gac_proposal_result($proposal,'front_of_house.action_proposal',['Host Stand']);
    }

    if(preg_match('/\b(assign|put|move)\b.*\b(?:this|the|selected)?\s*(?:table|reservation|party)\b/u',$lower)&&$reservation&&$table){
        foh_require_use($user);$row=foh_reservation_row_version($pdo,$org,(string)$reservation['publicId']);$tableRow=foh_table_row_version($pdo,$org,$snapshot['locationId'],(string)$table['publicId']);
        $versions=[(string)$table['publicId']=>['state'=>(string)$tableRow['state'],'updatedAt'=>(string)$tableRow['updated_at'],'activeCheckId'=>$tableRow['active_check_id']!==null?(int)$tableRow['active_check_id']:null]];
        $proposal=gac_pending_store('front_of_house',$org,$uid,'reservation_assign',['reservationPublicId'=>$reservation['publicId'],'locationId'=>$snapshot['locationId'],'tablePublicIds'=>[$table['publicId']],'tableVersions'=>$versions,'expectedStatus'=>$row['status'],'expectedUpdatedAt'=>$row['updated_at']],'Proposed action: assign “'.$reservation['guestName'].'” to '.$table['name'].'.');
        return gac_proposal_result($proposal,'front_of_house.action_proposal',['Host Stand','Table Seatability']);
    }

    if(preg_match('/\b(seat|seat this party|seat this reservation|seat them)\b/u',$lower)){
        foh_require_use($user);if(!$reservation)throw new InvalidArgumentException('Select the reservation/waitlist entry in Host Stand or name the guest first.');$row=foh_reservation_row_version($pdo,$org,(string)$reservation['publicId']);$signature=foh_reservation_table_signature($pdo,$org,(int)$row['id']);
        if($signature===hash('sha256',''))throw new InvalidArgumentException('Assign a service-ready table before seating this party.');
        $proposal=gac_pending_store('front_of_house',$org,$uid,'reservation_seat',['reservationPublicId'=>$reservation['publicId'],'locationId'=>$snapshot['locationId'],'expectedStatus'=>$row['status'],'expectedUpdatedAt'=>$row['updated_at'],'tableSignature'=>$signature],'Proposed action: seat “'.$reservation['guestName'].'” now and open the canonical POS check for the assigned table'.(count((array)$reservation['tables'])===1?'':'s').'.');
        return gac_proposal_result($proposal,'front_of_house.action_proposal',['Host Stand','Native POS','Table Seatability']);
    }

    if(preg_match('/\b(start|begin)\s+clean(?:ing)?\b|\bmark .*cleaning\b/u',$lower)){
        foh_require_use($user);if(!$table)throw new InvalidArgumentException('Select or name the table to start cleaning.');$row=foh_table_row_version($pdo,$org,$snapshot['locationId'],(string)$table['publicId']);
        $proposal=gac_pending_store('front_of_house',$org,$uid,'table_cleaning_start',['locationId'=>$snapshot['locationId'],'tablePublicId'=>$table['publicId'],'tableName'=>$table['name'],'expectedState'=>$row['state'],'expectedUpdatedAt'=>$row['updated_at'],'expectedActiveCheckId'=>$row['active_check_id']!==null?(int)$row['active_check_id']:null],'Proposed action: start the cleaning/reset lifecycle for '.$table['name'].'.');return gac_proposal_result($proposal,'front_of_house.action_proposal',['Host Stand','Table Cleaning Lifecycle']);
    }

    if(preg_match('/\b(mark|set)\b.*\bready\b|\btable ready\b|\bready for service\b/u',$lower)&&$table){
        foh_require_use($user);$row=foh_table_row_version($pdo,$org,$snapshot['locationId'],(string)$table['publicId']);
        $proposal=gac_pending_store('front_of_house',$org,$uid,'table_ready',['locationId'=>$snapshot['locationId'],'tablePublicId'=>$table['publicId'],'tableName'=>$table['name'],'expectedState'=>$row['state'],'expectedUpdatedAt'=>$row['updated_at'],'expectedActiveCheckId'=>$row['active_check_id']!==null?(int)$row['active_check_id']:null],'Proposed action: mark '.$table['name'].' ready for service.');return gac_proposal_result($proposal,'front_of_house.action_proposal',['Host Stand','Table Cleaning Lifecycle']);
    }

    if($reservation&&preg_match('/\b(this reservation|this waitlist|this party|selected reservation|selected party|status|guest|party|table|seat|arrival|arrived|wait|quote)\b/u',$lower))return ['ok'=>true,'skill'=>'front_of_house.reservation','answer'=>foh_reservation_answer($reservation),'data'=>['reservation'=>$reservation],'sources'=>['Host Stand']];
    if($table&&preg_match('/\b(this table|selected table|table|ready|clean|dirty|available|reservation|protected|occupied|seated|check)\b/u',$lower))return ['ok'=>true,'skill'=>'front_of_house.table','answer'=>foh_table_answer($table),'data'=>['table'=>$table],'sources'=>['Host Stand','Table Seatability']];
    if(preg_match('/\b(waitlist|walk[ -]?ins?|waiting parties|who is waiting)\b/u',$lower)){
        $names=array_map(static fn(array $r):string=>(string)$r['guestName'].' ('.(int)$r['partySize'].')',array_slice($snapshot['waitlist'],0,5));$answer=count($snapshot['waitlist']).' active waitlist part'.(count($snapshot['waitlist'])===1?'y':'ies').'.'.($names?' Next: '.implode(', ',$names).'.':'');return ['ok'=>true,'skill'=>'front_of_house.waitlist','answer'=>$answer,'data'=>['waitlist'=>$snapshot['waitlist']],'sources'=>['Host Stand']];
    }
    if(preg_match('/\b(available tables?|table availability|open tables?|where can .*seat|what can .*seat|seat .*party of)\b/u',$lower)){
        $available=$snapshot['availableTables'];$labels=array_map(static fn(array $t):string=>(string)$t['name'].' ('.(int)$t['capacity'].')',array_slice($available,0,8));$answer=count($available).' table'.(count($available)===1?' is':'s are').' available to seat now'.($labels?': '.implode(', ',$labels):'').'.';return ['ok'=>true,'skill'=>'front_of_house.availability','answer'=>$answer,'data'=>['availableTables'=>$available],'sources'=>['Host Stand','Table Seatability','Reservation Protection']];
    }
    if(preg_match('/\b(reservations?|bookings?|upcoming parties|today(?:\x27s|s)? reservations?)\b/u',$lower)){
        $labels=array_map(static fn(array $r):string=>(string)$r['guestName'].' · '.(int)$r['partySize'].' · '.(string)($r['scheduledAt']??$r['status']),array_slice($snapshot['activeReservations'],0,6));$answer=count($snapshot['activeReservations']).' active reservation'.(count($snapshot['activeReservations'])===1?'':'s').' for '.$snapshot['date'].($labels?'. '.implode('; ',$labels):'.');return ['ok'=>true,'skill'=>'front_of_house.reservations','answer'=>$answer,'data'=>['reservations'=>$snapshot['activeReservations']],'sources'=>['Host Stand']];
    }
    return ['ok'=>true,'skill'=>'front_of_house.overview','answer'=>foh_overview_answer($snapshot),'data'=>['locationId'=>$snapshot['locationId'],'date'=>$snapshot['date'],'availableTables'=>$snapshot['availableTables'],'seatedTables'=>$snapshot['seatedTables'],'reservations'=>$snapshot['activeReservations'],'waitlist'=>$snapshot['waitlist'],'dirtyTables'=>$snapshot['dirtyTables'],'cleaningTables'=>$snapshot['cleaningTables']],'sources'=>['Host Stand','Table Seatability','Reservation Protection','Table Cleaning Lifecycle']];
}
