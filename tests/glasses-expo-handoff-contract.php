<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/kds-production.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-definition.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-validation.php';
require_once __DIR__.'/../includes/glasses-handoff.php';
require_once __DIR__.'/../includes/glasses-simulator.php';

$pdo=app_pdo();

function ghci_assert(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException($message);
}
function ghci_one(PDO $pdo,string $sql,array $args=[]): mixed {
    $q=$pdo->prepare($sql); $q->execute($args); return $q->fetchColumn();
}
if(($argv[1]??'')==='lock-worker'){
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=10');
    echo ghci_one($pdo,'SELECT CONNECTION_ID()')."\n";
    fflush(STDOUT);
    if($input['action']==='kds'){
        kds_transaction($pdo,fn()=>kds_item($pdo,$input['org'],$input['kds'],true));
    }else{
        $device=glasses_device_row($pdo,$input['org'],$input['device']);
        glasses_handoff_prepare($pdo,$device,$input['session'],$input['validation'],$input['hash']);
    }
    exit;
}
function ghci_check_lock_order(PDO $pdo,int $checkId,array $input,callable $whileBlocked): void {
    $pdo->beginTransaction();
    $worker=null;$pipes=[];
    try{
        ghci_one($pdo,'SELECT id FROM pos_checks WHERE organization_id=? AND id=? FOR UPDATE',[$input['org'],$checkId]);
        $worker=proc_open([PHP_BINARY,__FILE__,'lock-worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        ghci_assert(is_resource($worker),'Start a separate MariaDB connection for the lock-order regression.');
        fwrite($pipes[0],json_encode($input,JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_timeout($pipes[1],10);
        $connection=(int)fgets($pipes[1]);
        ghci_assert($connection>0,'The lock-order worker must connect.');
        $deadline=microtime(true)+5;
        do{
            $waiting=ghci_one($pdo,"SELECT COUNT(*) FROM information_schema.INNODB_TRX
                WHERE trx_mysql_thread_id=? AND trx_state='LOCK WAIT'",[$connection]);
            if((int)$waiting===1)break;
            usleep(200000);
        }while(microtime(true)<$deadline);
        ghci_assert((int)$waiting===1,'Observe the worker blocked by the held POS check before testing its lock order.');
        $whileBlocked();
        $pdo->commit();
        $errors=stream_get_contents($pipes[2]);
        ghci_assert(proc_close($worker)===0,'The competing transaction must succeed after the POS lock is released. '.$errors);
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
        if(is_resource($worker))proc_terminate($worker);
        foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
        if(is_resource($worker))proc_close($worker);
    }
}
function ghci_complete_component(PDO $pdo,array $device,string $sessionPublic,string $key,float $quantity,string $obs): array {
    return glasses_build_observe($pdo,$device,$sessionPublic,[
        'observationKey'=>$obs,
        'componentKey'=>$key,
        'observationAction'=>'added',
        'quantity'=>$quantity,
        'confidence'=>0.97,
        'trackingId'=>$obs.'-track',
    ]);
}

ghci_assert(glasses_handoff_ready($pdo),'Expo handoff migration must be installed.');

$slug='ghci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Handoff CI '.$slug]);
$org=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")
    ->execute([$org]);
$location=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
    ->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Expo','Lead','Expo Lead']);
$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$user]);

$station=kds_station_save($pdo,$org,$location,[
    'name'=>'Sandwich','slug'=>'sandwich','targetSeconds'=>300,'sortOrder'=>10
],$user);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Lunch',?,'active',1)")
    ->execute([$org,'lunch-'.$slug]);
$section=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,preparation_notes,is_active) VALUES (?,?,'Club Sandwich + Fries',?,'Build and plate.',1)")
    ->execute([$org,$section,'club-'.$slug]);
$item=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',16.00,'USD',1)")
    ->execute([$item]);
$price=(int)$pdo->lastInsertId();

$ids=[];
foreach(['Bread','Turkey','Fries'] as $i=>$name){
    $pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,?,?,'food','verified')")
        ->execute([$org,$name,strtolower($name).'-'.$slug]);
    $id=(int)$pdo->lastInsertId();
    $ids[$name]=$id;
    $pdo->prepare('INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,?,0,1,?)')
        ->execute([$item,$id,$name,$i+1]);
}

$recipePublic='recipe-'.bin2hex(random_bytes(8));
$recipeIngredients=[
    ['quantity'=>'3','unit'=>'slices','ingredient'=>'Bread','notes'=>''],
    ['quantity'=>'3','unit'=>'slices','ingredient'=>'Turkey','notes'=>''],
    ['quantity'=>'1','unit'=>'portion','ingredient'=>'Fries','notes'=>''],
];
$recipeSteps=['Add Bread','Add Turkey','Plate with Fries'];
$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,ingredients_json,instructions_json,status,mapping_status,created_by,updated_by) VALUES (?,?,?,?,?,?,'active','mapped',?,?)")
    ->execute([$org,$recipePublic,'Club Sandwich + Fries','Sandwich',json_encode($recipeIngredients),json_encode($recipeSteps),$user,$user]);

$definition=glasses_definition_compile($pdo,$org,$item,$recipePublic,$user);
ghci_assert((string)$definition['status']==='ready','Test recipe must compile ready.');

kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$user);

$check=pos_create_check($pdo,$org,$location,[
    'serviceMode'=>'dine_in','tableName'=>'Table 8','guestCount'=>1
],$user);
$checkPublic=(string)$check['publicId'];
$check=pos_add_item($pdo,$org,$checkPublic,$price,1,'',$user);
$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,$checkPublic,$user,false);

$q=$pdo->prepare('SELECT public_id,id,status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?');
$q->execute([$org,$line]);
$kds=$q->fetch();
$kdsPublic=(string)$kds['public_id'];
$kdsId=(int)$kds['id'];

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],[
    'hardwareIdentifier'=>'AIR3-HANDOFF-'.$slug,'displayName'=>'Handoff AIR3'
]);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);
$sessionPublic=(string)$session['publicId'];

$blocked=false;
try{glasses_handoff_to_expo($pdo,$device,$sessionPublic);}catch(InvalidArgumentException){$blocked=true;}
ghci_assert($blocked,'Expo handoff must fail before product validation is ready.');
ghci_assert((string)ghci_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND id=?',[$org,$kdsId])==='queued','Failed handoff must leave KDS queued.');
ghci_assert((int)ghci_one($pdo,'SELECT COUNT(*) FROM glasses_kds_handoffs WHERE organization_id=?',[$org])===0,'Failed handoff must not persist a handoff.');
ghci_assert((string)ghci_one($pdo,'SELECT status FROM glasses_build_sessions WHERE organization_id=? AND public_id=?',[$org,$sessionPublic])==='active','Failed handoff must leave the build session active.');

ghci_complete_component($pdo,$device,$sessionPublic,'ingredient:'.$ids['Bread'],3,'bread-complete');
ghci_complete_component($pdo,$device,$sessionPublic,'ingredient:'.$ids['Turkey'],3,'turkey-complete');
ghci_complete_component($pdo,$device,$sessionPublic,'ingredient:'.$ids['Fries'],1,'fries-complete');

$ready=glasses_validation_evaluate($pdo,$device,$sessionPublic);
ghci_assert((string)$ready['status']==='ready_for_finishing','Complete build must be ready for finishing before handoff.');

$checkId=(int)pos_check_base($pdo,$org,$checkPublic)['id'];
ghci_check_lock_order($pdo,$checkId,['action'=>'kds','org'=>$org,'kds'=>$kdsPublic],
    fn()=>kds_item($pdo,$org,$kdsPublic,true));
ghci_check_lock_order($pdo,$checkId,[
    'action'=>'prepare','org'=>$org,'device'=>$device['public_id'],'session'=>$sessionPublic,
    'validation'=>$ready['publicId'],'hash'=>$ready['snapshotHash'],
],fn()=>pos_check_base($pdo,$org,$checkPublic,true));

$unexpectedKey='vision:cheese';
glasses_build_observe($pdo,$device,$sessionPublic,[
    'observationKey'=>'late-unexpected',
    'componentKey'=>$unexpectedKey,
    'displayName'=>'Swiss Cheese',
    'observationAction'=>'added',
    'quantity'=>1,
    'confidence'=>0.96,
]);
$staleGateBlocked=false;
try{glasses_handoff_to_expo($pdo,$device,$sessionPublic);}catch(InvalidArgumentException){$staleGateBlocked=true;}
ghci_assert($staleGateBlocked,'Handoff must re-evaluate current evidence instead of trusting an older ready validation.');
ghci_assert((string)ghci_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND id=?',[$org,$kdsId])==='queued','Fresh validation failure must roll back all KDS transitions.');

glasses_build_resolve_unexpected($pdo,$device,$sessionPublic,$unexpectedKey);
$handoff=glasses_handoff_to_expo($pdo,$device,$sessionPublic);

ghci_assert((string)$handoff['status']==='completed','Successful Expo handoff must be durable and completed.');
ghci_assert((string)$handoff['fromStatus']==='queued'&&(string)$handoff['toStatus']==='ready','Queued AR work must hand off to KDS Ready.');
ghci_assert((string)$handoff['kdsStatus']==='ready','Handoff response must reflect KDS Ready.');
ghci_assert((string)$handoff['next']['label']==='Sent to Expo / Finishing','Terminal AR response must identify Expo / Finishing.');

$kdsAfter=kds_item($pdo,$org,$kdsPublic,false);
ghci_assert((string)$kdsAfter['status']==='ready','Validated handoff must put the KDS item in Ready.');
ghci_assert(!empty($kdsAfter['started_at'])&&!empty($kdsAfter['ready_at']),'Queued handoff must preserve normal KDS started/ready timestamps.');
ghci_assert((int)$kdsAfter['last_action_by']===$user,'KDS audit actor must be the accountable user who paired the glasses.');

ghci_assert((string)ghci_one($pdo,'SELECT status FROM glasses_build_sessions WHERE organization_id=? AND public_id=?',[$org,$sessionPublic])==='completed','Successful handoff must close the AR build session.');
ghci_assert((string)ghci_one($pdo,'SELECT status FROM glasses_product_validations WHERE organization_id=? AND public_id=?',[$org,$ready['publicId']])==='handed_off','Successful handoff must terminalize validation.');
ghci_assert((string)ghci_one($pdo,'SELECT next_stage FROM glasses_product_validations WHERE organization_id=? AND public_id=?',[$org,$ready['publicId']])==='expo','Terminal validation must identify Expo as the active downstream stage.');

$transitionCount=(int)ghci_one($pdo,"SELECT COUNT(*) FROM kds_order_events WHERE organization_id=? AND kds_order_item_id=? AND event_type='status_changed'",[$org,$kdsId]);
ghci_assert($transitionCount===2,'Queued handoff must use the existing queued → in_progress → ready KDS lifecycle.');
$states=$pdo->prepare("SELECT CONCAT(COALESCE(from_status,''),'->',COALESCE(to_status,'')) transition_name FROM kds_order_events WHERE organization_id=? AND kds_order_item_id=? AND event_type='status_changed' ORDER BY id");
$states->execute([$org,$kdsId]);
ghci_assert($states->fetchAll(PDO::FETCH_COLUMN)===['queued->in_progress','in_progress->ready'],'Handoff must not skip an existing KDS state.');

$expo=kds_production_board($pdo,$org,$location,null,false);
$ticket=array_values(array_filter($expo['tickets'],static fn(array $t):bool=>(string)$t['checkPublicId']===$checkPublic))[0]??null;
ghci_assert(is_array($ticket)&&$ticket['readyToBump']===true,'Ready AR handoff must appear in the existing Expo board and be bumpable.');

$handoffAgain=glasses_handoff_to_expo($pdo,$device,$sessionPublic);
ghci_assert((string)$handoffAgain['publicId']===(string)$handoff['publicId'],'Retry after successful handoff must be idempotent.');
ghci_assert((int)ghci_one($pdo,'SELECT COUNT(*) FROM glasses_kds_handoffs WHERE organization_id=? AND build_session_id=(SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?)',[$org,$org,$sessionPublic])===1,'Retry must not create duplicate handoff records.');
ghci_assert((int)ghci_one($pdo,"SELECT COUNT(*) FROM kds_order_events WHERE organization_id=? AND kds_order_item_id=? AND event_type='status_changed'",[$org,$kdsId])===2,'Retry must not duplicate KDS transitions.');

$validationEvents=(int)ghci_one($pdo,"SELECT COUNT(*) FROM glasses_validation_events WHERE organization_id=? AND build_session_id=(SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?) AND event_type='handoff_to_expo'",[$org,$org,$sessionPublic]);
ghci_assert($validationEvents===1,'Handoff must append exactly one validation handoff event.');
$buildEvents=(int)ghci_one($pdo,"SELECT COUNT(*) FROM glasses_build_events WHERE organization_id=? AND build_session_id=(SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?) AND event_type='session_completed'",[$org,$org,$sessionPublic]);
ghci_assert($buildEvents===1,'Handoff must append exactly one build-session completion event.');

$grant2=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$paired2=glasses_pair_device($pdo,(string)$grant2['pairingCode'],[
    'hardwareIdentifier'=>'AIR3-HANDOFF-2-'.$slug,'displayName'=>'Other AIR3'
]);
$device2=glasses_authenticate_token($pdo,(string)$paired2['deviceToken']);
$crossDevice=false;
try{glasses_handoff_to_expo($pdo,$device2,$sessionPublic);}catch(InvalidArgumentException){$crossDevice=true;}
ghci_assert($crossDevice,'Another glasses device must not claim or replay this build handoff.');

$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
    ->execute([$slug.'-browser@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Browser','Cook','Browser Cook']);
$cookId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")
    ->execute([$org,$cookId]);
$cook=['id'=>$cookId,'organization_id'=>$org,'permissions'=>['*']];
$browserCheck=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'Table 9','guestCount'=>1],$cookId);
$browserCheck=pos_add_item($pdo,$org,$browserCheck['publicId'],$price,2,'',$cookId);
$browserLine=(int)$browserCheck['items'][0]['id'];
kds_send_check($pdo,$org,$browserCheck['publicId'],$cookId,false);
$browserKds=(string)ghci_one($pdo,'SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$browserLine]);
$browserBuild=glasses_build_start($pdo,$device,$browserKds,null);
$browserSession=(string)$browserBuild['publicId'];
$browserBase=['devicePublicId'=>$device['public_id'],'buildSessionPublicId'=>$browserSession];
$browserAction=static fn(string $action,array $data=[]):array=>
    glasses_simulator_dispatch($pdo,$cook,['action'=>$action]+$browserBase+$data);
$reject=static function(callable $operation,string $message):void{
    try{$operation();}
    catch(InvalidArgumentException){return;}
    throw new RuntimeException($message);
};

$component='ingredient:'.$ids['Bread'];
$reject(fn()=>$browserAction('build.observe',[
    'observationKey'=>'scripted','componentKey'=>$component,'quantity'=>6,'confidence'=>0.96,
    'metadata'=>['source'=>'web_glasses_simulator'],
]),'A scripted observation must be confined to Mock mode.');
$reject(fn()=>$browserAction('build.observe',[
    'observationKey'=>'multi-unit-track','componentKey'=>$component,'quantity'=>6,'confidence'=>0.96,
    'metadata'=>['source'=>'web_glasses_simulator_auto_vision','automatic'=>true],
]),'A single browser track must not satisfy a multi-unit order.');
$manual=$browserAction('build.observe',[
    'observationKey'=>'manual-review','componentKey'=>$component,'quantity'=>6,'confidence'=>0.96,
    'metadata'=>['source'=>'web_glasses_simulator_camera','manualTarget'=>true],
]);
$bread=array_values(array_filter($manual['buildSession']['components'],static fn(array $item):bool=>$item['componentKey']===$component))[0];
ghci_assert((float)$bread['expectedQuantity']===6.0,'Two ordered units need twice the base ingredients.');
ghci_assert((float)$bread['detectedQuantity']===0.0&&$bread['status']!=='confirmed','Manual camera cues must never satisfy required quantity.');
$reject(fn()=>$browserAction('handoff.expo'),'A browser handoff requires a reviewed receipt.');
foreach($browserBuild['components'] as $required)
    $browserAction('build.confirm',['componentKey'=>$required['componentKey']]);
$browserValidation=$browserAction('validation.evaluate')['validation'];
$reviewed=['validationPublicId'=>$browserValidation['publicId'],'validationSnapshotHash'=>$browserValidation['snapshotHash']];
$reject(fn()=>$browserAction('handoff.prepare',['validationPublicId'=>$reviewed['validationPublicId'],'validationSnapshotHash'=>str_repeat('a',64)]),'An unreviewed snapshot must not issue a receipt.');
$review=$browserAction('handoff.prepare',$reviewed)['handoffConfirmation'];
$reject(fn()=>glasses_simulator_dispatch($pdo,['id'=>$user,'organization_id'=>$org,'permissions'=>['*']],[
    'action'=>'handoff.expo','confirmationToken'=>$review['token'],
]+$browserBase),'A different operator cannot use the browser cook’s receipt.');
$browserAction('build.confirm',['componentKey'=>$component]);
$reject(fn()=>$browserAction('handoff.expo',['confirmationToken'=>$review['token']]),'New evidence must invalidate the reviewed receipt even if the counts are unchanged.');
ghci_assert(kds_item($pdo,$org,$browserKds)['status']==='queued','Rejected receipt must leave KDS queued.');
$review=$browserAction('handoff.prepare',$reviewed)['handoffConfirmation'];
kds_transition($pdo,$org,$browserKds,'in_progress',$cookId);
$reject(fn()=>$browserAction('handoff.expo',['confirmationToken'=>$review['token']]),'An intervening KDS transition invalidates the reviewed receipt.');
$review=$browserAction('handoff.prepare',$reviewed)['handoffConfirmation'];
$browserReceipt=$browserAction('handoff.expo',['confirmationToken'=>$review['token']])['handoff'];
$browserReplay=$browserAction('handoff.expo',['confirmationToken'=>$review['token']])['handoff'];
$browserItem=kds_item($pdo,$org,$browserKds);
ghci_assert($browserReceipt['publicId']===$browserReplay['publicId']&&$browserItem['status']==='ready','Browser handoff retry must return the same durable receipt.');
ghci_assert((int)$browserItem['last_action_by']===$cookId,'Browser handoff must identify the authenticated cook.');
ghci_assert((int)ghci_one($pdo,"SELECT COUNT(*) FROM kds_order_events WHERE organization_id=? AND kds_order_item_id=? AND event_type='status_changed'",[$org,(int)$browserItem['id']])===2,'Browser handoff replay must not repeat kitchen transitions.');

echo "glasses-expo-handoff-ok\n";
