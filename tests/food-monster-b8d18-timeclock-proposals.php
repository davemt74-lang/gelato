<?php
declare(strict_types=1);

function app_has_permission(string $name,array $actor):bool
{
    return in_array('*',(array)($actor['permissions']??[]),true)
        ||in_array($name,(array)($actor['permissions']??[]),true);
}

require dirname(__DIR__).'/includes/food-monster-b8d18-timeclock-proposals.php';

$assertions=0;
function f18f_check(bool $condition,string $label):void
{
    global $assertions;
    if(!$condition){fwrite(STDERR,"B8D18F FAIL: $label\n");exit(1);}
    $assertions++;
}
function f18f_reject(callable $task,string $label):void
{
    try{$task();f18f_check(false,$label);}catch(DomainException|InvalidArgumentException){
        f18f_check(true,$label);
    }
}

$user=['id'=>9,'organization_id'=>17,'role_slug'=>'employee',
    'permissions'=>['timeclock.self','timeclock.agent']];
$missing=['id'=>9,'organization_id'=>17,'permissions'=>['timeclock.agent']];
$foreign=['id'=>9,'organization_id'=>0,'permissions'=>['*']];
f18f_check(fm8d18f_can($user),'self + agent permission allows discovery');
f18f_check(!fm8d18f_can($missing),'self clock permission independently required');
f18f_check(!fm8d18f_can($foreign),'nonzero tenant independently required');
f18f_check(fm8d18f_catalogue($missing)===[],'tool not advertised without full permission');
f18f_check(fm8d18f_catalogue($user,['channel'=>'voice','privateVoiceApproved'=>false])===[],
    'unapproved room voice does not receive a write proposal tool');
f18f_check(isset(fm8d18f_catalogue($user,['channel'=>'text'])['propose_my_timeclock_action']),
    'typed model tool available when permitted');
f18f_check(isset(fm8d18f_catalogue($user,['channel'=>'voice','privateVoiceApproved'=>true])['propose_my_timeclock_action']),
    'approved voice model tool available for fresh identity check');
f18f_check(fm8d18f_fields(['action'=>'break_start'])===[],'valid action accepted');
f18f_check(fm8d18f_fields(['action'=>'clock_someone_else'])===['action'],
    'other-employee operation cannot be selected');
f18f_check(fm8d18f_fields([])===['action'],'missing action requires clarification');
$tool=fm8d18f_catalogue($user)['propose_my_timeclock_action'];
f18f_check(($tool['impact']??'')==='read','model cannot directly execute a business write');
f18f_check(($tool['allowedArguments']??[])===['action'],'cannot accept employee or tenant identifiers');
f18f_check(($tool['authorize'])($user,[])===true,'current user authorized at invocation');
f18f_check(($tool['authorize'])($missing,[])===false,'revoked self permission denies invocation');
f18f_check(($tool['validate'])(['action'=>'clock_out'],$user)===[],'model action validated');

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE schedule_shifts (id INTEGER PRIMARY KEY, public_id TEXT, title TEXT, starts_at TEXT, ends_at TEXT)');
$pdo->exec('CREATE TABLE time_clock_entries (id INTEGER PRIMARY KEY, organization_id INTEGER, user_id INTEGER, public_id TEXT, schedule_shift_id INTEGER, clocked_in_at TEXT, clocked_out_at TEXT, updated_at TEXT, status TEXT)');
$pdo->exec('CREATE TABLE time_clock_breaks (id INTEGER PRIMARY KEY, time_clock_entry_id INTEGER, started_at TEXT, ended_at TEXT)');
$before=(int)$pdo->query('SELECT COUNT(*) FROM time_clock_entries')->fetchColumn();
f18f_check(str_contains(fm8d18f_preflight($pdo,$user,'clock_in'),'clock you in'),
    'clock-in proposal supported when self has no open punch');
f18f_reject(static fn()=>fm8d18f_preflight($pdo,$user,'clock_out'),
    'cannot clock out when not clocked in');
f18f_reject(static fn()=>fm8d18f_preflight($pdo,$user,'break_start'),
    'cannot start break without clock-in');
$pdo->exec("INSERT INTO time_clock_entries VALUES (4,19,9,'other-tenant',NULL,'2026-10-31 08:00:00',NULL,'2026-10-31 08:00:00','open')");
f18f_check(str_contains(fm8d18f_preflight($pdo,$user,'clock_in'),'clock you in'),
    'clock records from another tenant are ignored');
$pdo->exec("INSERT INTO time_clock_entries VALUES (5,17,9,'own-punch',NULL,'2026-10-31 08:00:00',NULL,'2026-10-31 08:00:00','open')");
f18f_reject(static fn()=>fm8d18f_preflight($pdo,$user,'clock_in'),
    'duplicate clock-in rejected');
f18f_check(str_contains(fm8d18f_preflight($pdo,$user,'clock_out'),'clock you out'),
    'clock-out proposal supported for self');
f18f_check(str_contains(fm8d18f_preflight($pdo,$user,'break_start'),'start your break'),
    'start break proposal supported');
f18f_reject(static fn()=>fm8d18f_preflight($pdo,$user,'break_end'),
    'no open break cannot end');
$pdo->exec("INSERT INTO time_clock_breaks VALUES (7,5,'2026-10-31 10:00:00',NULL)");
f18f_reject(static fn()=>fm8d18f_preflight($pdo,$user,'break_start'),
    'duplicate break start rejected');
f18f_check(str_contains(fm8d18f_preflight($pdo,$user,'break_end'),'end your break'),
    'end break proposal supported');
f18f_reject(static fn()=>fm8d18f_preflight($pdo,$missing,'clock_in'),
    'revoked permission denies state inspection');
f18f_check((int)$pdo->query('SELECT COUNT(*) FROM time_clock_entries')->fetchColumn()===$before+2,
    'preflight only reads, never creates a punch');
f18f_check((int)$pdo->query('SELECT COUNT(*) FROM time_clock_breaks')->fetchColumn()===1,
    'preflight never starts or ends a break');

$runtime=(string)file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
f18f_check(str_contains($runtime,'fm8d18f_catalogue($principal,$context)'),
    'same model action catalogue handles text and approved voice');
f18f_check(str_contains($runtime,"'propose_my_timeclock_action'"),
    'proposal cannot be batched with other actions');
f18f_check(str_contains((string)file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-timeclock-proposals.php'),
    'tv_voice_event_valid('),'speech requires actual voice identity event');
echo "Food Monster B8D18F: $assertions checks passed.\n";
