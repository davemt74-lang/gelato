<?php
declare(strict_types=1);
if(!function_exists('mb_strtolower')){function mb_strtolower($s,$enc='UTF-8'){return strtolower($s);}}
if(!function_exists('mb_strlen')){function mb_strlen($s,$enc='UTF-8'){return strlen($s);}}
function app_has_permission(string $key,array $user):bool{return in_array('*',(array)($user['permissions']??[]),true)||in_array($key,(array)($user['permissions']??[]),true);}
require __DIR__.'/../includes/food-monster-read-tools.php';
$n=0;function check_b2(bool $ok,string $name):void{global $n;if(!$ok){fwrite(STDERR,"FM-G7B2 FAIL: $name\n");exit(1);}++$n;}
foreach(['Who is signed in?','Who am I?','What is my login status?','Who is logged in?'] as $q)check_b2(fm_read_intent($q)==='session_identity','Identity '.$q);
foreach(['Who is clocked in?','Who is currently clocked in?','Who is working now?','Which staff are on the clock today?'] as $q)check_b2(fm_read_intent($q)==='active_clock_ins','Clock-in '.$q);
foreach(['What were our recorded sales today?','How much did we sell?','Show sales on October 20','Net revenue yesterday'] as $q)check_b2(fm_read_intent($q)==='recorded_sales','Sales '.$q);
foreach(['Hello how are you?','What is the menu?','Tell me a story','Refund the sales from yesterday','Clock me in','Delete all clock-ins','Change our sales',''] as $q)check_b2(fm_read_intent($q)===null,'No arbitrary execution '.$q);
$user=['id'=>5,'organization_id'=>3,'display_name'=>'Taylor','role_slug'=>'manager','permissions'=>['timeclock.agent','timeclock.view','sales.view','sales.agent']];
foreach(['session_identity','active_clock_ins','recorded_sales'] as $intent)check_b2(fm_read_allowed($user,$intent),'Approved skill '.$intent);
$no=['id'=>4,'organization_id'=>3,'permissions'=>[]];
check_b2(fm_read_allowed($no,'session_identity'),'Own session visible');
check_b2(!fm_read_allowed($no,'active_clock_ins'),'No clock-ins without permission');
check_b2(!fm_read_allowed($no,'recorded_sales'),'No sales without permission');
check_b2(!fm_read_allowed(['organization_id'=>3],'session_identity'),'Identity requires account');
check_b2(!fm_read_allowed($user,'arbitrary'),'Unknown tools denied');
check_b2(fm_read_date('Sales on October 20',new DateTimeImmutable('2026-10-15'))==='2026-10-20','Month-name dates');
check_b2(fm_read_date('Sales on 2026-09-13',new DateTimeImmutable('2026-10-15'))==='2026-09-13','ISO dates');
check_b2(fm_read_date('Revenue yesterday',new DateTimeImmutable('2026-10-15'))==='2026-10-14','Relative dates');
try{fm_read_date('sales 2026-13-42');check_b2(false,'Reject invalid dates');}catch(InvalidArgumentException){check_b2(true,'Reject invalid dates');}
$missing=fm_read_sales_answer('2026-10-20',['daily'=>[],'totals'=>['netSales'=>0,'tickets'=>0]]);
check_b2($missing['data']['hasRecordedPeriod']===false,'Missing period is unknown');
check_b2(str_contains($missing['answer'],'does not mean'),'Never invent zero');
$found=fm_read_sales_answer('2026-10-14',['daily'=>[['period_start'=>'2026-10-14']],'totals'=>['netSales'=>123.45,'tickets'=>2]]);
check_b2(str_contains($found['answer'],'$123.45'),'Verified money');
check_b2($found['data']['tickets']===2,'Verified ticket count');
$clock=fm_read_clock_answer([['display_name'=>'Taylor','clocked_in_at'=>'2026-10-15 09:30:00']]);
check_b2(str_contains($clock['answer'],'Taylor'),'Verified staff');
check_b2(!isset($clock['data']['staffIds']),'No excess identifiers');
check_b2(str_contains(fm_read_clock_answer([])['answer'],'No active clock-in entries'),'No fabricated staff');
$api=file_get_contents(__DIR__.'/../api/food-monster-read.php');
$router=file_get_contents(__DIR__.'/../api/agent-workspace.php');
$js=file_get_contents(__DIR__.'/../js/global-agent.js');
check_b2(str_contains($api,'app_require_auth()')&&str_contains($api,'app_verify_request_csrf'),'Authenticated POST');
check_b2(str_contains($api,'gaw_thread(')&&str_contains($api,'gaw_messages('),'Thread ownership and message binding');
check_b2(str_contains($api,'app_audit('),'Audit recorded');
check_b2(str_contains($router,'fm_read_intent($message)'),'Server-selected intent');
check_b2(str_contains($js,"'api/food-monster-read.php'"),'Canonical thread passed');
check_b2(str_contains($api,'fm_read_allowed($user,$intent)'),'Permission rechecked');
check_b2(!str_contains($api,'eval('),'No arbitrary execution');
echo "FM-G7B2 governed data reads PASS ($n assertions)\n";
