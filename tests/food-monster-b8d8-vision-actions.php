<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-workspace-core.php';
require_once dirname(__DIR__).'/includes/food-monster-llm.php';
require_once dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php';

$checks=0;
function b8d8_check(bool $ok,string $why):void {
    global $checks;
    if(!$ok)throw new RuntimeException('B8D8 FAIL: '.$why);
    $checks++;
}
function b8d8_count(PDO $pdo,string $table,int $org):int {
    $allowed=['glasses_vision_training_missions','glasses_vision_dataset_versions'];
    if(!in_array($table,$allowed,true))throw new LogicException('Invalid table.');
    $q=$pdo->prepare("SELECT COUNT(*) FROM $table WHERE organization_id=?");
    $q->execute([$org]);
    return (int)$q->fetchColumn();
}
$pdo=app_pdo();$slug='b8d8-'.bin2hex(random_bytes(5));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Vision Management '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status)
    VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','x','Vision','Manager','Vision Manager']);
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status)
    VALUES (?,?,'active')")->execute([$org,$uid]);
$actor=['id'=>$uid,'organization_id'=>$org,'display_name'=>'Vision Manager',
    'permissions'=>['glasses.view','glasses.manage']];
b8d8_check(gaw_ready($pdo)&&glasses_vision_lab_ready($pdo)&&fm8d8_receipts_ready($pdo),
    'Agent Chat, Vision Lab and durable agent receipt schemas upgraded');
$room=(string)gaw_create_thread($pdo,$org,$uid,'text','Vision routine actions')['public_id'];
$first=gaw_append($pdo,$org,$uid,$room,'user','Create a collection mission for cheese portioning');
$context=['threadId'=>$room,'messageId'=>(string)$first['id'],
    'channel'=>'text','voiceEventId'=>'','verifiedSameUser'=>true];
$catalogue=fm8d8_vision_catalogue($actor,$context);
$expect=['list_vision_training_missions','list_vision_dataset_versions',
    'create_vision_collection_mission','create_vision_dataset_draft'];
b8d8_check(count($catalogue)===4&&!array_diff($expect,array_keys($catalogue)),
    'manager discovers four authorized Vision Lab skills');
$viewer=array_replace($actor,['permissions'=>['glasses.view']]);
$viewerSkills=fm8d8_vision_catalogue($viewer,$context);
b8d8_check(count($viewerSkills)===2&&isset($viewerSkills['list_vision_training_missions'])
    &&!isset($viewerSkills['create_vision_collection_mission']),'view-only account cannot discover write skills');
b8d8_check(fm8d8_vision_catalogue(array_replace($actor,['permissions'=>[]]),$context)===[],
    'unauthorized user sees no Vision Lab tools');
b8d8_check(count(fm8d8_vision_catalogue($actor,[]))===2,
    'routine writes require a saved authenticated Agent Chat turn');
foreach($expect as $name) {
    $skill=$catalogue[$name];
    b8d8_check($skill['authorize']($actor,[])===true,
        $name.' grants current actor');
    b8d8_check($skill['authorize'](array_replace($actor,['permissions'=>[]]),[])===false,
        $name.' rejects revoked permission');
}
b8d8_check($catalogue['create_vision_collection_mission']['impact']==='routine'
    &&$catalogue['create_vision_dataset_draft']['impact']==='routine',
    'writes are bounded routine actions, never silent high impact');
b8d8_check($catalogue['create_vision_collection_mission']['validate']([], $actor)===['title'],
    'missing mission title must be provided');
b8d8_check($catalogue['create_vision_dataset_draft']['validate']([], $actor)===['name','version_label'],
    'blank dataset requires an explicit name and version');
$empty=$catalogue['list_vision_training_missions']['execute']([], $actor);
b8d8_check(str_contains($empty['data']['answer'],'No Vision Lab collection missions'),
    'empty mission ledger never invents missions');
$mission=$catalogue['create_vision_collection_mission']['execute']([
    'title'=>'Cheese Portion Check','description'=>'Collect portion visuals for review','target_samples'=>30],$actor);
b8d8_check(str_contains($mission['data']['answer'],'Created Vision Lab collection mission')
    &&str_contains($mission['data']['answer'],'Cheese Portion Check')
    &&str_contains($mission['data']['answer'],'30'),'real collection mission created from verified user turn');
b8d8_check(b8d8_count($pdo,'glasses_vision_training_missions',$org)===1,
    'one mission persisted');
$q=$pdo->prepare("SELECT source_type,source_reference,status,target_samples,created_by
    FROM glasses_vision_training_missions WHERE organization_id=?");
$q->execute([$org]);$row=$q->fetch(PDO::FETCH_ASSOC);
b8d8_check($row['source_type']==='manual'
    &&$row['source_reference']==='agentchat:'.$first['id']
    &&$row['status']==='active'&&(int)$row['target_samples']===30
    &&(int)$row['created_by']===$uid,
    'mission source records real signed-in operator and canonical chat identity');
$again=$catalogue['create_vision_collection_mission']['execute']([
    'title'=>'Malicious modified mission','target_samples'=>500],$actor);
b8d8_check(str_contains($again['data']['answer'],'already completed')
    &&str_contains($again['data']['answer'],'Cheese Portion Check')
    &&str_contains($again['data']['answer'],'30')
    &&b8d8_count($pdo,'glasses_vision_training_missions',$org)===1,
    'same chat turn cannot create a second mission or mutate original observation');
$list=$catalogue['list_vision_training_missions']['execute']([], $actor);
b8d8_check(str_contains($list['data']['answer'],'Cheese Portion Check')
    &&str_contains($list['data']['answer'],'active'),
    'read returns real saved mission');
$second=gaw_append($pdo,$org,$uid,$room,'user','Create draft dataset Cheese QC v1');
$ctx2=array_replace($context,['messageId'=>(string)$second['id']]);
$cat2=fm8d8_vision_catalogue($actor,$ctx2);
$dataset=$cat2['create_vision_dataset_draft']['execute']([
    'name'=>'Cheese QC','version_label'=>'v1'],$actor);
b8d8_check(str_contains($dataset['data']['answer'],'Created new draft')
    &&str_contains($dataset['data']['answer'],'Cheese QC')
    &&str_contains($dataset['data']['answer'],'draft'),
    'actual draft dataset version created');
b8d8_check(b8d8_count($pdo,'glasses_vision_dataset_versions',$org)===1,
    'draft saved exactly once');
$datasetRepeat=$cat2['create_vision_dataset_draft']['execute']([
    'name'=>'Cheese QC','version_label'=>'v1'],$actor);
b8d8_check(str_contains($datasetRepeat['data']['answer'],'already exists')
    &&b8d8_count($pdo,'glasses_vision_dataset_versions',$org)===1,
    'dataset version creation is idempotent');
$altered=$cat2['create_vision_dataset_draft']['execute']([
    'name'=>'Injected different dataset','version_label'=>'v42'],$actor);
b8d8_check(str_contains($altered['data']['answer'],'Cheese QC')
    &&!str_contains($altered['data']['answer'],'Injected different dataset')
    &&b8d8_count($pdo,'glasses_vision_dataset_versions',$org)===1,
    'one verified Agent Chat turn cannot create multiple draft datasets by changing arguments');
$q=$pdo->prepare("SELECT resource_public_id FROM food_monster_agent_action_receipts
    WHERE organization_id=? AND actor_user_id=? AND turn_public_id=?
        AND action='create_vision_dataset_draft'");
$q->execute([$org,$uid,$ctx2['messageId']]);
b8d8_check((bool)$q->fetchColumn(),'durable receipt binds draft dataset to signed-in chat turn');
$datasetList=$cat2['list_vision_dataset_versions']['execute']([], $actor);
b8d8_check(str_contains($datasetList['data']['answer'],'Cheese QC')
    &&str_contains($datasetList['data']['answer'],'draft'),
    'dataset read reports real draft version');
try{$catalogue['create_vision_collection_mission']['execute'](['title'=>'Stale'], $actor);$staleBlocked=false;}
catch(DomainException){$staleBlocked=true;}
b8d8_check($staleBlocked&&b8d8_count($pdo,'glasses_vision_training_missions',$org)===1,
    'superseded canonical message cannot trigger stale new mission');
try{fm8d8_create_dataset($pdo,$viewer,$ctx2,['name'=>'Forbidden','version_label'=>'v1']);$permissionBlocked=false;}
catch(DomainException){$permissionBlocked=true;}
b8d8_check($permissionBlocked&&b8d8_count($pdo,'glasses_vision_dataset_versions',$org)===1,
    'direct adapter rejects insufficient manager permissions');
$third=gaw_append($pdo,$org,$uid,$room,'user','Create a training mission for final pizza presentation');
$ctx3=array_replace($context,['messageId'=>(string)$third['id']]);
$skills=fm8d3_catalogue($actor,$ctx3);
$providerRequest=[];
$openai=static function($url,$headers,$payload)use(&$providerRequest):array{
    $providerRequest=$payload;
    return ['output'=>[['type'=>'function_call','name'=>'create_vision_collection_mission',
        'call_id'=>'b8d8-openai','arguments'=>'{"title":"Pizza Presentation","target_samples":12}']]];
};
$reply=fm8d3_turn($pdo,$actor,'openai','fake',
    [['role'=>'user','content'=>'Create a training mission for final pizza presentation']],'',
    $openai,$skills,static fn():array=>$actor,static function(array $event):void{},$ctx3);
b8d8_check(($reply['data']['status']??'')==='completed'
    &&($reply['data']['tools'][0]??'')==='create_vision_collection_mission'
    &&str_contains($reply['answer'],'Pizza Presentation'),
    'OpenAI selects and completes a real routine Vision Lab write');
b8d8_check(b8d8_count($pdo,'glasses_vision_training_missions',$org)===2,
    'provider-selected mission persisted one time');
b8d8_check(!str_contains(json_encode($providerRequest),'Cheese Portion Check')
    &&!str_contains(json_encode($providerRequest),'Cheese QC'),
    'private mission and dataset records never returned to LLM provider');
$claude=static fn():array=>['content'=>[['type'=>'tool_use','id'=>'b8d8-anthropic',
    'name'=>'list_vision_dataset_versions','input'=>[]]]];
$read=fm8d3_turn($pdo,$actor,'anthropic','fake',
    [['role'=>'user','content'=>'Which vision datasets have we made?']],'',
    $claude,$skills,static fn():array=>$actor,static function(array $event):void{},$ctx3);
b8d8_check(str_contains($read['answer'],'Cheese QC')
    &&($read['data']['tools'][0]??'')==='list_vision_dataset_versions',
    'Claude selects authorized dataset read');
$denied=fm8d3_turn($pdo,$actor,'openai','fake',
    [['role'=>'user','content'=>'Create another mission']],'',
    $openai,$skills,static fn():array=>array_replace($actor,['permissions'=>[]]),
    static function(array $event):void{},$ctx3);
b8d8_check(($denied['data']['status']??'')==='denied'
    &&b8d8_count($pdo,'glasses_vision_training_missions',$org)===2,
    'freshly revoked authority prevents model-requested write');
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Other Vision '.$slug]);$otherOrg=(int)$pdo->lastInsertId();
$otherActor=array_replace($viewer,['organization_id'=>$otherOrg]);
$foreignMissions=fm8d8_list_missions($pdo,$otherActor);
$foreignDatasets=fm8d8_list_datasets($pdo,$otherActor);
b8d8_check(str_contains($foreignMissions['data']['answer'],'No Vision Lab collection missions')
    &&str_contains($foreignDatasets['data']['answer'],'No Vision Lab dataset versions'),
    'other restaurant cannot read first restaurant missions or datasets');
$otherContext=array_replace($ctx3,['threadId'=>'agent-'.str_repeat('a',20)]);
try{fm8d8_create_mission($pdo,$actor,$otherContext,['title'=>'Forged context']);$forged=false;}
catch(DomainException){$forged=true;}
b8d8_check($forged&&b8d8_count($pdo,'glasses_vision_training_missions',$org)===2,
    'forged thread fails canonical read and creates no mission');
echo 'food-monster-b8d8-vision-actions-ok assertions='.$checks."\n";
