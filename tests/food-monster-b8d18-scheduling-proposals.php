<?php
declare(strict_types=1);

function app_has_permission(string $name,array $actor):bool
{
    return in_array('*',(array)($actor['permissions']??[]),true)
        ||in_array($name,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-scheduling-proposals.php';

$checks=0;
function f18g_check(bool $condition,string $label):void
{
    global $checks;
    if(!$condition){fwrite(STDERR,"B8D18G FAIL: $label\n");exit(1);}
    $checks++;
}
function f18g_reject(callable $fn,string $label):void
{
    try{$fn();f18g_check(false,$label);}catch(DomainException|InvalidArgumentException){f18g_check(true,$label);}
}
$user=['id'=>9,'organization_id'=>17,'permissions'=>['schedule.agent','schedule.self']];
$manager=['id'=>9,'organization_id'=>17,'permissions'=>['schedule.agent','schedule.manage']];
$noAgent=['id'=>9,'organization_id'=>17,'permissions'=>['schedule.self']];
f18g_check(fm8d18g_can($user),'signed-in self with Scheduling Agent allowed');
f18g_check(!fm8d18g_can($manager),'manager-only authority does not impersonate an employee');
f18g_check(!fm8d18g_can($noAgent),'self cannot access model action without agent permission');
f18g_check(fm8d18g_catalogue($manager)===[],'manager-only tool not advertised');
f18g_check(fm8d18g_catalogue($user,['channel'=>'voice','privateVoiceApproved'=>false])===[],
    'unapproved room voice cannot stage a private time-off request');
f18g_check(isset(fm8d18g_catalogue($user,['channel'=>'text'])['propose_my_time_off_request']),
    'typed explicit self request has a native model tool');
f18g_check(isset(fm8d18g_catalogue($user,['channel'=>'voice','privateVoiceApproved'=>true])['propose_my_time_off_request']),
    'approved voice can reach fresh identity preflight');
$tool=fm8d18g_catalogue($user)['propose_my_time_off_request'];
f18g_check($tool['impact']==='read','model can stage, but never directly write');
f18g_check(!in_array('userId',$tool['allowedArguments'],true),'model cannot choose employee identifier');
f18g_check(($tool['authorize'])($user,[])===true,'signed-in self permitted at execution');
f18g_check(($tool['authorize'])($noAgent,[])===false,'revocation denies action');
f18g_check(($tool['validate'])([], $user)===['start_date'],'missing date requires clarification');
f18g_check(($tool['validate'])(['start_date'=>['2030-01-05']],$user)===['start_date'],
    'untrusted nested date object is rejected before string conversion');
f18g_check(($tool['validate'])(['start_date'=>'2030-01-05','start_time'=>'09:00'],$user)===['end_time'],
    'partial-day time requires end time');
f18g_check(($tool['validate'])(['start_date'=>'2030-01-05','end_time'=>'11:00'],$user)===['start_time'],
    'partial-day time requires start time');

$today=new DateTimeImmutable('2030-01-01',new DateTimeZone('America/Phoenix'));
f18g_check(sac_time_off_dates(['startDate'=>'2030-01-05'],$today)===['2030-01-05'],
    'single day normalizes to one record');
f18g_check(sac_time_off_dates(['startDate'=>'2030-01-05','endDate'=>'2030-01-07'],$today)===
    ['2030-01-05','2030-01-06','2030-01-07'],'inclusive contiguous range');
f18g_check(count(sac_time_off_dates(['startDate'=>'2030-01-05','endDate'=>'2030-02-04'],$today))===31,
    'maximum thirty-one day request');
f18g_reject(static fn()=>sac_time_off_dates(['startDate'=>'2030-02-30'],$today),
    'invalid calendar day rejected');
f18g_reject(static fn()=>sac_time_off_dates(['startDate'=>'2030-01-05','endDate'=>'2030-01-04'],$today),
    'reversed range rejected');
f18g_reject(static fn()=>sac_time_off_dates(['startDate'=>'2030-01-05','endDate'=>'2030-02-05'],$today),
    'oversized range rejected');
f18g_reject(static fn()=>sac_time_off_dates(['startDate'=>'2029-12-31'],$today),
    'past request rejected');
f18g_reject(static fn()=>sac_time_off_dates(['startDate'=>'2030-01-05','startTime'=>'9:00','endTime'=>'12:00'],$today),
    'noncanonical time rejected');
f18g_reject(static fn()=>sac_time_off_dates(['startDate'=>'2030-01-05','startTime'=>'12:00','endTime'=>'11:00'],$today),
    'negative time window rejected');
f18g_reject(static fn()=>sac_time_off_dates(['startDate'=>'2030-01-05','endDate'=>'2030-01-06','startTime'=>'09:00','endTime'=>'11:00'],$today),
    'partial-day time not repeated silently over multiple dates');
f18g_reject(static fn()=>fm8d18g_payload(['start_date'=>'2030-01-05','note'=>str_repeat('x',1001)]),
    'oversized note rejected');

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE staff_availability_exceptions (id INTEGER PRIMARY KEY, organization_id INTEGER, user_id INTEGER, exception_type TEXT, status TEXT, exception_date TEXT)');
$dates=['2030-01-05','2030-01-06'];
sac_time_off_check_existing($pdo,17,9,$dates);
$pdo->exec("INSERT INTO staff_availability_exceptions VALUES (1,18,9,'time_off','pending','2030-01-05')");
sac_time_off_check_existing($pdo,17,9,$dates);
f18g_check(true,'a different tenant cannot block this employee');
$pdo->exec("INSERT INTO staff_availability_exceptions VALUES (2,17,8,'time_off','pending','2030-01-05')");
sac_time_off_check_existing($pdo,17,9,$dates);
f18g_check(true,'another employee request does not block this employee');
$pdo->exec("INSERT INTO staff_availability_exceptions VALUES (3,17,9,'time_off','rejected','2030-01-05')");
sac_time_off_check_existing($pdo,17,9,$dates);
f18g_check(true,'rejected request does not block a new request');
$pdo->exec("INSERT INTO staff_availability_exceptions VALUES (4,17,9,'time_off','pending','2030-01-06')");
f18g_reject(static fn()=>sac_time_off_check_existing($pdo,17,9,$dates),
    'pending self request overlapping range rejected');
$pdo->exec("UPDATE staff_availability_exceptions SET status='approved' WHERE id=4");
f18g_reject(static fn()=>sac_time_off_check_existing($pdo,17,9,$dates),
    'approved self request overlapping range rejected');
f18g_check((int)$pdo->query('SELECT COUNT(*) FROM staff_availability_exceptions')->fetchColumn()===4,
    'preflight does not write time-off rows');

$_SESSION=[];
$pending=sac_pending_store(17,9,'time_off_request',[
    'userId'=>9,'startDate'=>'2030-01-05','endDate'=>'2030-01-06'], 'Test proposal');
f18g_check(sac_pending_get(17,9)['id']===$pending['id'],'existing Scheduling Agent owns pending proposal');
f18g_check(sac_pending_get(17,8)===null,'other account cannot read pending proposal');
sac_pending_clear(17,9);
f18g_check(sac_pending_get(17,9)===null,'cancellation clears proposed action');

$runtime=(string)file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$specialist=(string)file_get_contents(dirname(__DIR__).'/includes/scheduling-agent-core.php');
$adapter=(string)file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-scheduling-proposals.php');
f18g_check(str_contains($runtime,'fm8d18g_catalogue($principal,$context)'),
    'same model tool is registered for typed and approved voice turns');
f18g_check(str_contains($runtime,"'propose_my_time_off_request'"),
    'request cannot be batched alongside other model tools');
f18g_check(str_contains($specialist,"if(\$type==='time_off_request')"),
    'existing Scheduling Agent confirms the new proposal type');
f18g_check(str_contains($specialist,'scheduling_exception_create(')&&str_contains($specialist,'$pdo->beginTransaction()'),
    'confirmation uses established core inside atomic transaction');
f18g_check(str_contains($specialist,"'schedule.self'")&&str_contains($specialist,"'schedule.agent'"),
    'fresh permissions checked by specialist at confirmation');
f18g_check(str_contains($adapter,'tv_voice_event_valid('),
    'private voice requires a current matching voice identity event');
f18g_check(str_contains($specialist,'], $userId,false)')||str_contains($specialist,'],$userId,false)'),
    'confirm creates pending review requests even for managers');
echo "Food Monster B8D18G: $checks checks passed.\n";
