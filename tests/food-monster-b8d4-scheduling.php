<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/food-monster-llm.php';
require_once dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php';

$n=0;
function b8d4_assert(bool $yes,string $message):void{
    global $n;if(!$yes)throw new RuntimeException('B8D4 FAIL: '.$message);$n++;
}
$pdo=app_pdo();
$slug='fm-b8d4-'.bin2hex(random_bytes(5));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['B8D4 Scheduling '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,status) VALUES (?,?,'active')")
    ->execute([$org,'Main Restaurant']);$loc=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO positions (organization_id,name,slug,status) VALUES (?,?,?,'active')")
    ->execute([$org,'Prep Cook','prep-'.$slug]);$pos=(int)$pdo->lastInsertId();
$makeUser=static function(string $first,string $last)use($pdo,$slug,$org,$loc,$pos):int{
    $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status)
        VALUES (?,?,?,?,?,'active')")->execute([
        strtolower($first).'-'.bin2hex(random_bytes(3)).'-'.$slug.'@example.test','x',
        $first,$last,$first.' '.$last]);
    $uid=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organization_memberships
        (organization_id,user_id,primary_location_id,job_title,status)
        VALUES (?,?,?,'Prep Cook','active')")->execute([$org,$uid,$loc]);
    $membership=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO user_positions
        (membership_id,position_id,is_primary,status,assigned_by)
        VALUES (?,?,1,'active',?)")->execute([$membership,$pos,$uid]);
    return $uid;
};
$managerId=$makeUser('Manager','Test');
$davidId=$makeUser('David','Worker');
$manager=['id'=>$managerId,'organization_id'=>$org,'display_name'=>'Manager Test',
    'permissions'=>['schedule.agent','schedule.manage','schedule.view','schedule.self']];
$david=['id'=>$davidId,'organization_id'=>$org,'display_name'=>'David Worker',
    'permissions'=>['schedule.self']];
scheduling_availability_replace($pdo,$org,$davidId,[
    ['weekday'=>5,'start'=>'09:00','end'=>'23:00','type'=>'available']],$managerId);
$registry=fm8d3_catalogue($manager);
$mine=fm8d3_catalogue($david);
b8d4_assert(isset($registry['get_my_schedule'],$registry['get_employee_schedule'],
    $registry['get_day_roster'],$registry['check_staff_availability'],$registry['propose_staff_shift']),
    'manager discovers full scheduling skills');
b8d4_assert(isset($mine['get_my_schedule'])&&!isset($mine['get_employee_schedule'],
    $mine['get_day_roster'],$mine['check_staff_availability'],$mine['propose_staff_shift']),
    'self-only user gets only own schedule');
b8d4_assert(!isset(fm8d3_catalogue(['id'=>3,'organization_id'=>$org,'permissions'=>[]])['get_my_schedule']),
    'no schedule grant means no scheduling skills');
b8d4_assert(fm8d4_schedule_day('2026-10-30')==='2026-10-30','strict valid date');
try{fm8d4_schedule_day('2026-02-30');b8d4_assert(false,'invalid date rejected');}
catch(InvalidArgumentException){b8d4_assert(true,'invalid date rejected');}
try{fm8d4_schedule_range(['date'=>'2026-10-30','start_time'=>'22:00','end_time'=>'08:00']);b8d4_assert(false,'bad time accepted');}
catch(InvalidArgumentException){b8d4_assert(true,'invalid time range rejected');}
$proposalTool=$registry['propose_staff_shift'];
b8d4_assert(($proposalTool['validate'])(['employee_name'=>'David Worker'],$manager)===
    ['date','start_time','end_time'],'missing times require clarification');
$range=['employee_name'=>'David Worker','date'=>'2026-10-30',
    'start_time'=>'16:00','end_time'=>'22:00'];
$availability=$registry['check_staff_availability']['execute']($range,$manager);
b8d4_assert(str_contains($availability['data']['answer'],'no shift overlap'),
    'recorded availability checked');
$original=(int)$pdo->query("SELECT COUNT(*) FROM schedule_shifts WHERE organization_id=".$org)->fetchColumn();
$proposal=$proposalTool['execute']($range,$manager);
b8d4_assert(str_contains($proposal['data']['answer'],'Reply Confirm'),
    'model-selected shift only stages a proposal');
b8d4_assert((int)$pdo->query("SELECT COUNT(*) FROM schedule_shifts WHERE organization_id=".$org)->fetchColumn()===$original,
    'proposal does not write schedule');
$pending=sac_pending_get($org,$managerId);
b8d4_assert(is_array($pending)&&($pending['type']??'')==='shift_create','server owns pending shift');
$blocked=fm8d4_confirm_pending($pdo,$manager,[['role'=>'user','content'=>'What would confirmation do?']],
    static fn():array=>$manager);
b8d4_assert($blocked===null,'discussion about confirmation cannot execute');
$revoked=array_replace($manager,['permissions'=>[]]);
$denied=fm8d4_confirm_pending($pdo,$manager,[['role'=>'user','content'=>'Confirm']],
    static fn():array=>$revoked);
b8d4_assert(($denied['data']['status']??'')==='denied','fresh grant revocation stops confirmation');
b8d4_assert((int)$pdo->query("SELECT COUNT(*) FROM schedule_shifts WHERE organization_id=".$org)->fetchColumn()===$original,
    'revoked confirmation makes no write');
$confirmed=fm8d4_confirm_pending($pdo,$manager,[['role'=>'user','content'=>'Confirm']],
    static fn():array=>$manager);
b8d4_assert(($confirmed['skill']??'')==='schedule.action_confirmed','explicit human approval executes');
$shiftId=(string)($confirmed['data']['shiftId']??'');
$shift=scheduling_shift_by_public_id($pdo,$org,$shiftId);
b8d4_assert(is_array($shift)&&(int)$shift['user_id']===$davidId
    &&str_starts_with((string)$shift['starts_at'],'2026-10-30 16:00')
    &&str_starts_with((string)$shift['ends_at'],'2026-10-30 22:00'),'correct employee and time saved');
b8d4_assert(fm8d4_confirm_pending($pdo,$manager,[['role'=>'user','content'=>'Confirm']],
    static fn():array=>$manager)===null,'repeat confirm cannot repeat shift');
$self=$mine['get_my_schedule']['execute'](['week_date'=>'2026-10-30'],$david);
b8d4_assert(str_contains($self['data']['answer'],'David')&&str_contains($self['data']['answer'],'4:00 PM'),
    'signed-in David sees recorded schedule');
$roster=$registry['get_day_roster']['execute'](['date'=>'2026-10-30'],$manager);
b8d4_assert(str_contains($roster['data']['answer'],'David Worker'),'manager roster reflects shift');
$conflict=$proposalTool['execute']($range,$manager);
b8d4_assert(str_contains($conflict['data']['answer'],'No shift was created or proposed'),
    'overlapping shift not staged');
$other=$makeUser('David','Different');
b8d4_assert(str_contains(fm8d4_staff_resolve($pdo,$org,'David')['error'],'More than one employee'),
    'ambiguous employee names require clarification');
$ambiguous=$proposalTool['execute'](array_replace($range,['employee_name'=>'David']),$manager);
b8d4_assert(str_contains($ambiguous['data']['answer'],'More than one employee'),
    'ambiguous names do not modify schedule');
$tenant=app_pdo()->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')");
$tenant->execute(['Other Restaurant '.$slug]);$otherOrg=(int)$pdo->lastInsertId();
$external=['id'=>$davidId,'organization_id'=>$otherOrg,'display_name'=>'Other','permissions'=>['schedule.self']];
$externalReply=fm8d4_schedule_catalogue($external)['get_my_schedule']['execute'](['week_date'=>'2026-10-30'],$external);
b8d4_assert(!str_contains($externalReply['data']['answer'],'4:00 PM'),'cross-tenant cannot read shift');
$providerCall=static fn():array=>['output'=>[[
    'type'=>'function_call','name'=>'get_my_schedule',
    'call_id'=>'call_1','arguments'=>'{"week_date":"2026-10-30"}',
]]];
$payloads=[];$capture=static function($url,$headers,$payload)use(&$payloads,$providerCall):array{
    $payloads[]=$payload;return $providerCall();
};
$reply=fm8d3_turn($pdo,$david,'openai','test',
    [['role'=>'user','content'=>'When do I work next?']],'',$capture,$mine,
    static fn():array=>$david,static function(array $event):void{});
b8d4_assert($reply['skill']==='agent.model_selected_tools'
    &&str_contains($reply['answer'],'4:00 PM'),'OpenAI-selected Scheduling skill invokes live database');
b8d4_assert(!str_contains(json_encode($payloads),'David Worker — week of'),
    'private employee scheduling results not roundtripped to provider in same turn');
$claude=static fn():array=>['content'=>[[
    'type'=>'tool_use','name'=>'get_day_roster','id'=>'toolu_1','input'=>['date'=>'2026-10-30'],
]]];
$reply=fm8d3_turn($pdo,$manager,'anthropic','test',
    [['role'=>'user','content'=>'Who works Friday?']],'',$claude,$registry,
    static fn():array=>$manager,static function(array $event):void{});
b8d4_assert(str_contains($reply['answer'],'David Worker'),'Claude-selected roster skill works');
echo 'food-monster-b8d4-scheduling-ok assertions='.$n."\n";
