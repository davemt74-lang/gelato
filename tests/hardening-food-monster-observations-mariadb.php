<?php
declare(strict_types=1);
// These fixtures require an isolated, fully upgraded database and CLI execution.
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}

require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-workspace-core.php';
require_once dirname(__DIR__).'/includes/food-monster-llm.php';
require_once dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php';
$checks=0;
function hfodb_check(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException('AI-01 MariaDB: '.$message);
    ++$GLOBALS['checks'];
}
function hfodb_reject(callable $call,string $message): void
{
    try{$call();}catch(DomainException|InvalidArgumentException){hfodb_check(true,$message);return;}
    throw new RuntimeException('AI-01 MariaDB accepted '.$message);
}
$pdo=app_pdo();
if(session_status()!==PHP_SESSION_ACTIVE&&!session_start())throw new RuntimeException('Fixture session unavailable.');
$tag='hfo-'.bin2hex(random_bytes(5));$date='2026-11-03';
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute([$tag]);
$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status)
    VALUES (?,'fixture-only','Observation','Owner','Observation Owner','active')")->execute([$tag.'@example.test']);
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);
$membership=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO roles(organization_id,name,slug,is_system_role,is_owner_role,is_assignable)
    VALUES (?,'Observation Owner','super_admin',1,1,1)")->execute([$org]);$role=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO user_roles(membership_id,role_id) VALUES (?,?)')->execute([$membership,$role]);
$base=['id'=>$uid,'organization_id'=>$org,'display_name'=>'Observation Owner'];
$owner=fm8d2_refresh_operator($pdo,$base);
hfodb_check(is_array($owner)&&$owner['permissions']===['*']&&$owner['role_slug']==='super_admin','real fresh owner authority');
$refresh=static fn():?array=>fm8d2_refresh_operator($pdo,$base);
$thread=(string)gaw_create_thread($pdo,$org,$uid,'text','AI-01 composed observations')['public_id'];
$send=static function(string $prompt)use($pdo,$org,$uid,$thread):array{
    $message=gaw_append($pdo,$org,$uid,$thread,'user',$prompt);
    return ['threadId'=>$thread,'messageId'=>(string)$message['id'],'channel'=>'text','verifiedSameUser'=>true];
};
$providerPayloads=[];
$turn=static function(string $tool,array $args,string $prompt)use($pdo,$owner,$refresh,$send,&$providerPayloads):array{
    $ctx=$send($prompt);$before=count($providerPayloads);
    gac_request_context(['threadId'=>$ctx['threadId'],'sourceMessageId'=>$ctx['messageId']],$pdo,$owner);
    $transport=static function($endpoint,$headers,$payload)use($tool,$args,&$providerPayloads):array{
        $providerPayloads[]=$payload;
        return ['output'=>[['type'=>'function_call','call_id'=>'fixture-selection','name'=>$tool,
            'arguments'=>json_encode($args?:new stdClass(),JSON_THROW_ON_ERROR)]]];
    };
    // No catalogue override: production registration, authorization, execute,
    // observation, per-step checkpoint and final journal all run together.
    $result=fm8d3_turn($pdo,$owner,'openai','fixture-no-network',
        [['role'=>'user','content'=>$prompt]],'',$transport,null,$refresh,null,$ctx);
    hfodb_check(($result['data']['status']??'')==='completed',$tool.' completes through the real runtime');
    hfodb_check(count($providerPayloads)===$before+1,$tool.' uses one selection call and no observation roundtrip');
    hfodb_check(strlen(json_encode($result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE))<=24000,
        $tool.' fits unchanged 24 KB durable reply limit');
    hfodb_check(mb_strlen($result['answer'],'UTF-8')<=12000,$tool.' fits canonical chat message limit');
    $q=$pdo->prepare('SELECT s.observation_json FROM food_monster_agent_task_steps s
        JOIN food_monster_agent_task_runs r ON r.id=s.task_run_id
        WHERE r.organization_id=? AND r.actor_user_id=? AND r.turn_public_id=? AND s.step_index=0');
    $q->execute([$owner['organization_id'],$owner['id'],$ctx['messageId']]);$json=$q->fetchColumn();
    hfodb_check(is_string($json)&&strlen($json)<=fm8do_limit($tool),$tool.' persists a bounded checkpoint');
    $observation=json_decode((string)$json,true,32,JSON_THROW_ON_ERROR);
    hfodb_check(is_array($observation)&&fm8do_fits($tool,$observation),$tool.' checkpoint obeys the same full contract');
    return [$result,$observation,$ctx];
};
[$capReply,$cap,$capCtx]=$turn('list_my_gelato_capabilities',[],'What tools can my account use?');
// Canonical turns also register the training/vision routine tools. Compare
// the same authorized context rather than a context-free discovery snapshot.
$report=fm8d17a_snapshot($owner,$capCtx);
hfodb_check(strlen(fm8do_json($report['planned']))>3072,'real owner source inventory exceeds old limit');
hfodb_check($cap['capabilityTruth']['counts']===$report['counts'],
    'terminal counts match the same canonical-turn catalogue exactly');

$stockInsert=$pdo->prepare('INSERT INTO inventory_items
    (organization_id,public_id,normalized_key,name,base_unit,on_hand_quantity,reorder_point,created_by)
    VALUES (?,?,?,?,?,?,?,?)');
$historyInsert=$pdo->prepare("INSERT INTO vendor_price_history
    (organization_id,vendor_id,inventory_item_id,vendor_item_id,price_per_pack,pack_size,unit,source_type,created_by)
    VALUES (?,?,?,?,?,2,'kg','receiving',?)");
$fixtures=[];
foreach([1,3,10,20] as $number){
    $stock=$tag.'-stock-'.$number;$name='Private supplier flour '.$number.' '.$tag;
    $stockInsert->execute([$org,$stock,$stock,$name,'kg',2,null,$uid]);$stockId=(int)$pdo->lastInsertId();
    $request=daily_quicklist_add($pdo,$org,$uid,['type'=>'inventory','title'=>$name,'inventoryId'=>$stock],false);
    daily_quicklist_mutate($pdo,$org,$uid,'update',['id'=>$request,'requestedStockQuantity'=>5],true);
    $mappings=[];$vendors=[];
    for($i=1;$i<=$number;++$i){
        $vendorName=sprintf('%02d Secret supplier ', $i).str_repeat('Café食材🙂', $number>=10?18:2).' '.$tag.'-'.$number;
        $vendor=purchasing_save_vendor($pdo,$org,['name'=>$vendorName,'minimumOrderAmount'=>100,'leadTimeDays'=>2],$uid);
        $map=purchasing_save_vendor_item($pdo,$org,['vendorId'=>$vendor['public_id'],'inventoryId'=>$stock,
            'vendorSku'=>'sku-'.$i,'packSize'=>2,'packUnit'=>'kg','pricePerPack'=>12+$i,'preferred'=>$i===1],$uid);
        for($h=1;$h<=4;++$h)$historyInsert->execute([$org,(int)$vendor['id'],$stockId,(int)$map['id'],10+$i+$h,$uid]);
        $mappings[]=$map;$vendors[]=$vendor;
    }
    if($number>=10){
        // Legacy/open-order fixtures exercise complete blockers even when
        // only a bounded subset of order detail rows fits the observation.
        for($i=0;$i<5;++$i){
            $public=$tag.'-po-'.$number.'-'.$i;
            $pdo->prepare('INSERT INTO purchase_orders(organization_id,public_id,vendor_id,order_number,created_by)
                VALUES (?,?,?,?,?)')->execute([$org,$public,(int)$vendors[$i]['id'],'PO-'.$public,$uid]);
            $po=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO purchase_order_items
                (organization_id,purchase_order_id,inventory_item_id,vendor_item_id,public_id,description,
                 ordered_packs,pack_size,unit,ordered_base_quantity,price_per_pack)
                VALUES (?,?,?,?,?,?,3,2,?,6,?)')
                ->execute([$org,$po,$stockId,(int)$mappings[$i]['id'],$public.'-line',$name,'kg',13+$i]);
        }
    }
    $comparison=dqpo_comparison($pdo,$org,$request);
    hfodb_check(count($comparison['options'])===$number&&count($comparison['options'][0]['priceHistory'])===5,
        $number.' suppliers have five real recorded history entries');
    $ctx=['threadId'=>$thread,'channel'=>'text'];$cat=fm8d3_catalogue($owner,$ctx);
    $direct=fm8d2_run([['tool'=>'get_need_more_vendor_options','arguments'=>['name'=>$name,'date'=>$date]]],$owner,$cat,
        ['refreshActor'=>$refresh,'audit'=>static function(array $event):void{}]);
    hfodb_check($direct['status']==='completed',$number.' supplier fixture passes real fm8d2 wrapper');
    [$reply,$obs,$savedCtx]=$turn('get_need_more_vendor_options',['name'=>$name,'date'=>$date],'Compare saved suppliers.');
    hfodb_check($obs['optionCount']===$number&&$obs['optionCountExact']&&$obs['returnedOptionCount']===count($obs['options'])
        &&count($obs['options'])>0&&count($obs['options'])<=20,$number.' supplier output counts are truthful and useful');
    foreach($obs['options'] as $index=>$option){
        $numbered=$index+1;
        hfodb_check($option['number']===$numbered&&str_contains($reply['answer'],$numbered.'. '.$option['vendor']),
            'complete supplier identity is displayed beside its exact number');
        hfodb_check($option['priceHistoryRetrievedCount']===5&&count($option['priceHistory'])===2&&$option['priceHistoryTruncated'],
            'history reduction is explicit and keeps real recent evidence');
        $resolved=pqa_vendor_context_resolve($org,$uid,$thread,$request,$numbered);
        hfodb_check($resolved['vendorItemId']===$comparison['options'][$index]['vendorItemId']
            &&$resolved['comparisonHash']===$comparison['comparisonHash'],'displayed number binds the exact mapping and full source hash');
        hfodb_check(!isset($option['vendorItemId'])&&!isset($option['vendorId']),'private mapping IDs are not model choices');
    }
    hfodb_reject(static fn()=>pqa_vendor_context_resolve($org,$uid,$thread,$request,count($obs['options'])+1),
        'a supplier not displayed cannot be selected');
    hfodb_reject(static fn()=>pqa_vendor_context_resolve($org,$uid,'another-thread',$request,1),'cross-thread supplier selection');
    if($number>=10)hfodb_check(!$obs['canDraft']&&$obs['openOrderCount']===5&&count($obs['openOrders'])<=3
        &&$obs['openOrdersTruncated']&&str_contains($reply['answer'],'Draft blockers:'),'open-order blocker survives all detail compaction');
    if(count($obs['options'])<$number)hfodb_check($obs['optionsTruncated']&&str_contains($reply['answer'],
        'Showing '.count($obs['options']).' of '.$number),'byte-limited supplier prefix is explicitly disclosed');
    $fixtures[]=['stock'=>$stock,'stockId'=>$stockId,'request'=>$request,'name'=>$name,'context'=>$savedCtx];
}

// Real inventories above the general picker's 400-row cap must report all
// matching reorder-point items without returning all 600 raw alert objects.
$low=[];
for($i=0;$i<600;++$i){
    $id=$tag.'-low-'.$i;$name=sprintf('A low %04d ', $i).str_repeat('食材é',35);
    $stockInsert->execute([$org,$id,$id,$name,'kg',2,10,$uid]);$low[]=['id'=>$id,'name'=>$name];
}
for($i=0;$i<40;++$i){
    daily_quicklist_add($pdo,$org,$uid,['type'=>'inventory','title'=>$low[$i]['name'],'inventoryId'=>$low[$i]['id']],false);
    daily_quicklist_add($pdo,$org,$uid,['type'=>'prep','date'=>$date,
        'title'=>'Private prep '.$i.' '.str_repeat('長い準備é',32)],false);
}
$recipe=$tag.'-recipe';$recipeName='Private active recipe é';
$pdo->prepare("INSERT INTO recipes(organization_id,public_id,name,status,yield_quantity,yield_unit,ingredients_json,instructions_json,created_by)
    VALUES (?,?,?,'active',1,'batch',?,?,?)")->execute([$org,$recipe,$recipeName,
        json_encode([['ingredient'=>$low[40]['name'],'quantity'=>'1','unit'=>'kg']],JSON_THROW_ON_ERROR),'[]',$uid]);
$pdo->prepare("UPDATE daily_quicklist_items SET recipe_public_id=?
    WHERE organization_id=? AND list_type='prep' AND list_date=? ORDER BY id LIMIT 1")->execute([$recipe,$org,$date]);
[$stockReply,$stockObs]=$turn('get_need_more_stock_insights',['date'=>$date],'What stock is at its reorder point?');
hfodb_check($stockObs['totalCount']===600&&$stockObs['retrievedCount']===400&&$stockObs['returnedCount']>0
    &&$stockObs['returnedCount']<=12&&count($stockObs['alerts'])===$stockObs['returnedCount'],
    '600-item inventory has exact match count and bounded retrieved/displayed rows');
hfodb_check($stockObs['truncated']&&str_contains($stockReply['answer'],'of 600')
    &&str_contains($stockReply['answer'],'not calculated recipe shortages'),'large stock summary discloses counts and evidence limits');
hfodb_check(in_array($recipeName,$stockObs['alerts'][0]['prepRecipes'],true),'authorized exact-name active recipe evidence survives compaction');
$withoutRecipes=array_replace($owner,['permissions'=>['tasks.manage','inventory.view','prep.intelligence.view','prep.intelligence.agent']]);
$restricted=fm8d18j11_stock_read(['date'=>$date],$withoutRecipes,['threadId'=>$thread,'channel'=>'text']);
hfodb_check($restricted['data']['alerts'][0]['prepRecipes']===[],'stock evidence does not disclose recipes after recipe access is removed');
[$checklistReply,$checklist]=$turn('get_daily_prep_checklist',['date'=>$date],'Show the dated prep checklist.');
$selection=pqa_context_get($org,$uid,$thread);
hfodb_check($checklist['prepCount']===40&&$checklist['needMoreCount']===44&&$checklist['truncated'],
    'large checklist reports actual counts rather than the first twenty');
foreach(['prep','inventory'] as $kind){
    hfodb_check(count($selection['lists'][$kind])===$checklist['returnedCounts'][$kind],
        'checklist selection contains exactly displayed '.$kind.' rows');
    foreach($selection['lists'][$kind] as $i=>$row)hfodb_check(str_contains($checklistReply['answer'],($i+1).'. '.$row['title']),
        'every stored checklist ordinal has a complete displayed title');
}

// Bounded receipt numbering uses the same displayed-subset rule.
$fixture=$fixtures[0];
for($i=0;$i<20;++$i)$pdo->prepare("INSERT INTO inventory_transactions
    (organization_id,inventory_item_id,transaction_type,quantity_delta,resulting_quantity,unit,created_by)
    VALUES (?,?,'receive',1,3,'kg',?)")->execute([$org,$fixture['stockId'],$uid]);
[$receiptReply,$receipts]=$turn('get_need_more_receipts',['name'=>$fixture['name'],'date'=>$date],'Show eligible recorded receipts.');
$receiptCandidates=daily_quicklist_receipt_candidates($pdo,$org,$fixture['request']);
hfodb_check($receipts['count']>0&&$receipts['count']<=20&&$receipts['retrievedCount']===20&&$receipts['lookupBounded'],
    'receipt count and bounded source lookup are explicit');
for($i=1;$i<=$receipts['count'];++$i)hfodb_check(pqa_receipt_context_resolve($org,$uid,$thread,$fixture['request'],$i)
    ===$receiptCandidates[$i-1]['id'],'displayed receipt resolves to the exact ledger entry');
hfodb_reject(static fn()=>pqa_receipt_context_resolve($org,$uid,$thread,$fixture['request'],$receipts['count']+1),'undisplayed receipt');

// AI-02 composition: a real native proposal must retain canonical review
// metadata through the same terminal answer assembly, without a stock write.
$stockBefore=(float)$pdo->query('SELECT on_hand_quantity FROM inventory_items WHERE id='.$fixture['stockId'])->fetchColumn();
$ledgerBefore=(int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id='.$org)->fetchColumn();
[$proposalReply,$proposalObs,$proposalCtx]=$turn('propose_inventory_stock_change',
    ['item_name'=>$fixture['name'],'action'=>'receive','quantity'=>1,'unit'=>'kg'],
    'Receive 1 kg of '.$fixture['name'].'.');
$proposal=$proposalReply['data']['proposal']??[];
hfodb_check(($proposalReply['data']['requiresConfirmation']??false)===true
    &&($proposal['id']??'')!==''&&$proposal['id']===($proposalObs['proposal']['id']??null)
    &&($proposal['threadId']??'')===$thread&&($proposal['sourceMessageId']??'')===$proposalCtx['messageId'],
    'real native proposal metadata survives the complete model-selected turn');
hfodb_check((float)$pdo->query('SELECT on_hand_quantity FROM inventory_items WHERE id='.$fixture['stockId'])->fetchColumn()===$stockBefore
    &&(int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id='.$org)->fetchColumn()===$ledgerBefore,
    'the composed native proposal does not apply stock or receipt writes');
gac_pending_clear('operations',$org,$uid,(string)$proposal['id']);

// Resume a real observation, refuse a forged oversized checkpoint, and refuse
// private checkpoint recovery after the current account authority is revoked.
$resumeContext=$send('Recover the verified capability summary.');
$plan=[['tool'=>'list_my_gelato_capabilities','arguments'=>[]]];$catalogue=fm8d3_catalogue($owner,$resumeContext);
$claim=fm8d13b_claim($pdo,$owner,$resumeContext,$plan,$catalogue);
fm8d13c_checkpoint($pdo,$claim,$plan,['index'=>0,'tool'=>$plan[0]['tool'],'status'=>'completed','observation'=>$cap]);
hfodb_check(fm8d13c_trace($pdo,$claim,$plan)[0]['observation']===$cap,'bounded checkpoint roundtrips exactly');
hfodb_reject(static fn()=>fm8d13c_checkpoint($pdo,$claim,$plan,['index'=>0,'tool'=>$plan[0]['tool'],
    'status'=>'completed','observation'=>['answer'=>str_repeat('x',20000),'source'=>'Test']]),'oversized checkpoint write');
$pdo->prepare("UPDATE food_monster_agent_task_steps s JOIN food_monster_agent_task_runs r ON r.id=s.task_run_id
    SET s.observation_json=? WHERE r.organization_id=? AND r.turn_public_id=?")
    ->execute([json_encode(['answer'=>str_repeat('x',20000),'source'=>'Test'],JSON_THROW_ON_ERROR),$org,$resumeContext['messageId']]);
hfodb_reject(static fn()=>fm8d13c_trace($pdo,$claim,$plan),'oversized stored checkpoint read');
$pdo->prepare("UPDATE user_roles SET revoked_at=NOW(6) WHERE membership_id=? AND role_id=?")->execute([$membership,$role]);
$replay=fm8d3_turn($pdo,$owner,'openai','fixture-no-network',[['role'=>'user','content'=>'What tools can my account use?']],
    '',static function():array{throw new RuntimeException('Revoked replay must not call provider.');},null,$refresh,null,$capCtx);
hfodb_check(($replay['data']['status']??'')==='denied'&&!str_contains($replay['answer'],'Registered Gelato tools'),
    'fresh SQL authorization blocks completed private receipt replay');
foreach($providerPayloads as $payload){
    $json=json_encode($payload,JSON_THROW_ON_ERROR);
    hfodb_check(!str_contains($json,'Secret supplier')&&!str_contains($json,'A low 0000')&&!str_contains($json,'Private prep 0'),
        'private supplier, inventory and prep result records never enter provider payloads');
}
echo 'AI-01 Food Monster composed MariaDB observations: '.$checks." checks passed.\n";
