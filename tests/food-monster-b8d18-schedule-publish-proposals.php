<?php
declare(strict_types=1);
function app_has_permission(string $key,array $actor):bool
{
    return in_array($key,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-schedule-publish-proposals.php';
$checks=0;
function i_check(bool $condition,string $label):void
{
    global $checks;
    if(!$condition){fwrite(STDERR,"B8D18I FAIL: $label\n");exit(1);}
    ++$checks;
}
function i_reject(callable $operation,string $label):void
{
    try{$operation();i_check(false,$label);}catch(DomainException|InvalidArgumentException|SchedulingAgentPermissionException){i_check(true,$label);}
}
$manager=['id'=>9,'organization_id'=>17,'permissions'=>['schedule.manage','schedule.agent']];
$employee=['id'=>9,'organization_id'=>17,'permissions'=>['schedule.self','schedule.agent']];
i_check(fm8d18i_can($manager),'manager and agent required');
i_check(!fm8d18i_can($employee),'employee cannot publish');
i_check(!fm8d18i_can(['id'=>9,'organization_id'=>17,'permissions'=>['schedule.manage']]),'agent permission required');
i_check(fm8d18i_catalogue($employee)===[],'employee has no publish tool');
i_check(fm8d18i_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice cannot publish');
$tool=fm8d18i_catalogue($manager)['propose_publish_schedule_week'];
i_check($tool['impact']==='read','model cannot directly publish');
i_check(($tool['validate'])([],$manager)===['week_start'],'exact date required');
i_check(($tool['validate'])(['week_start'=>['2030-01-07']],$manager)===['week_start'],'nested date rejected');
i_check(fm8d18i_week(['week_start'=>'2030-01-07'])==='2030-01-07','valid future Monday');
i_reject(static fn()=>fm8d18i_week(['week_start'=>'2030-01-08']),'non-Monday rejected');
i_reject(static fn()=>fm8d18i_week(['week_start'=>'2030-02-30']),'invalid calendar date rejected');
i_reject(static fn()=>fm8d18i_week(['week_start'=>'2020-01-06']),'past week rejected');
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE schedule_weeks (id INTEGER PRIMARY KEY,organization_id INTEGER,week_start TEXT,status TEXT,updated_at TEXT)');
$pdo->exec('CREATE TABLE schedule_shifts (id INTEGER PRIMARY KEY,public_id TEXT,organization_id INTEGER,schedule_week_id INTEGER,updated_at TEXT,status TEXT,user_id INTEGER,starts_at TEXT,ends_at TEXT,archived_at TEXT)');
i_reject(static fn()=>sac_publish_preflight($pdo,17,$manager,'2030-01-07'),'missing draft rejected');
$pdo->exec("INSERT INTO schedule_weeks VALUES (1,17,'2030-01-07','draft','v1')");
i_reject(static fn()=>sac_publish_preflight($pdo,17,$manager,'2030-01-07'),'empty draft rejected');
$pdo->exec("INSERT INTO schedule_shifts VALUES (1,'shift-1',17,1,'v1','scheduled',9,'2030-01-07 09:00:00','2030-01-07 17:00:00',NULL)");
$checked=sac_publish_preflight($pdo,17,$manager,'2030-01-07');
i_check($checked['fingerprint']['count']===1,'nonempty draft accepted');
i_check($checked['snapshot']['status']==='draft','draft state snapshotted');
$hash=$checked['fingerprint']['hash'];
$pdo->exec("UPDATE schedule_shifts SET user_id=10,updated_at='v2' WHERE id=1");
i_check(sac_publish_fingerprint($pdo,17,'2030-01-07')['hash']!==$hash,'changed shift invalidates proposal');
$pdo->exec("UPDATE schedule_weeks SET status='published' WHERE id=1");
i_reject(static fn()=>sac_publish_preflight($pdo,17,$manager,'2030-01-07'),'published week rejected');
$pdo->exec("UPDATE schedule_weeks SET status='draft' WHERE id=1");
i_reject(static fn()=>sac_publish_preflight($pdo,17,$employee,'2030-01-07'),'employee cannot bypass permission');
$pdo->exec("INSERT INTO schedule_weeks VALUES (2,18,'2030-01-07','draft','v1')");
$pdo->exec("INSERT INTO schedule_shifts VALUES (2,'other-tenant',18,2,'v1','scheduled',99,'2030-01-07 10:00:00','2030-01-07 18:00:00',NULL)");
i_check(sac_publish_fingerprint($pdo,17,'2030-01-07')['count']===1,'tenant isolation');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$specialist=file_get_contents(dirname(__DIR__).'/includes/scheduling-agent-core.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-schedule-publish-proposals.php');
i_check(str_contains($runtime,'fm8d18i_catalogue($principal,$context)'),'native tool registered');
i_check(str_contains($runtime,"'propose_publish_schedule_week'"),'model cannot batch publish');
i_check(str_contains($specialist,"if(\$type==='week_publish')"),'specialist retains confirmation');
i_check(str_contains($specialist,'expectedShiftFingerprint'),'shift fingerprint checked at confirmation');
i_check(str_contains($adapter,'tv_voice_event_valid('),'authorized voice event checked');
i_check(str_contains($adapter,'sac_pending_store('),'only a pending proposal is staged');
echo "Food Monster B8D18I: $checks checks passed.\n";
