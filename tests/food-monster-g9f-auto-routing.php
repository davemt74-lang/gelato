<?php
declare(strict_types=1);
function check_fm9f(bool $ok,string $name):void {if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
$workspace=file_get_contents(__DIR__.'/../api/agent-workspace.php');
$person=file_get_contents(__DIR__.'/../includes/food-monster-person-conversations.php');
$followup=file_get_contents(__DIR__.'/../includes/food-monster-operational-followup.php');
check_fm9f(str_contains($person, "'reportMessageId'=>\$meta['memoryCategory']==='operational_report'"),'only operational reports supply action key');
check_fm9f(str_contains($person,"$"."reportMessage=gaw_append("),'canonical saved report is action source');
check_fm9f(str_contains($workspace,"fm9a_agent_action_decision($"."user,'routine')==='delegated'"),'owner standing delegation is required');
check_fm9f(str_contains($workspace,"app_has_permission('tasks.manage',$"."user)"),'task management permission is required');
check_fm9f(str_contains($workspace,'fm9f_create_report_task('),'uses governed operational task service');
check_fm9f(str_contains($workspace,'operationalFollowup'),'action receipt is available to chat');
check_fm9f(str_contains($workspace,"$"."followup=['status'=>'unavailable']"),'no false success if task creation fails');
check_fm9f(str_contains($followup,'FOR UPDATE')&&str_contains($followup,"'fm9f_report_task'"),'idempotent serialized task service');
