<?php
declare(strict_types=1);
function app_has_permission(string $key,array $user):bool {
 $list=(array)($user['permissions']??[]);return in_array('*',$list,true)||in_array($key,$list,true);
}
function admin_dashboard_allowed(array $user):bool {
 return in_array((string)($user['role_slug']??''),['owner','manager'],true)
   &&app_has_permission('admin.dashboard.view',$user);
}
require __DIR__.'/../includes/food-monster-skill-registry.php';
$n=0;
function b1_check(bool $ok,string $name):void {
 global $n;if(!$ok){fwrite(STDERR,'FM-G7B1 FAIL: '.$name."\n");exit(1);}++$n;
}
b1_check(fm_skill_registry_for_user(['role_slug'=>'staff','permissions'=>[]])===[],'Deny without permissions');
$two=fm_skill_registry_for_user(['role_slug'=>'staff','permissions'=>['kds.view','schedule.view']]);
b1_check(array_column($two,'id')===['kitchen','staffing'],'Only authorized reviews visible');
$all=fm_skill_registry_for_user(['role_slug'=>'owner','permissions'=>['*']]);
b1_check(count($all)===count(fm_skill_registry_definitions()),'Owner scoped node list');
foreach($all as $entry){
 b1_check(!isset($entry['route'],$entry['tools'],$entry['arguments']),'No model tool grants');
 b1_check(!array_key_exists('route',$entry),'No endpoint disclosure');
 b1_check($entry['mode']==='chat_review','Not an executor');
 b1_check($entry['approval']==='existing_node_policy','Preserves approvals');
 b1_check(gaw_agent_node($entry['node'])!==null,'Canonical Agent Node');
 b1_check(preg_match('/\b(create|update|delete|refund|approve|confirm|submit|send|buy|publish|execute|transfer|assign)\b/i',$entry['prompt'])!==1,'Read-only prompt');
}
$nonManager=fm_skill_registry_for_user(['role_slug'=>'staff','permissions'=>['*']]);
b1_check(!in_array('brain_brief',array_column($nonManager,'id'),true),'Manager gate for Brain');
$partial=fm_skill_registry_for_user(['role_slug'=>'manager','permissions'=>['inventory.view','purchasing.view','recipes.view']]);
b1_check($partial===[],'No agent use from read-only privilege alone for gated nodes');
foreach([['inventory.view','inventory.agent'],['tasks.view','tasks.agent']] as $group){
 $match=fm_skill_registry_for_user(['role_slug'=>'staff','permissions'=>$group]);
 b1_check(array_column($match,'id')===['inventory'],'Operations permission conjunction');
}
$api=file_get_contents(__DIR__.'/../api/agent-workspace.php');
b1_check(str_contains($api,'app_require_auth()'),'Endpoint is authenticated');
b1_check(str_contains($api,"'food_monster_skills'"),'Read-only catalog action');
b1_check(str_contains($api,'fm_skill_registry_for_user($user)'),'Catalog filtered server-side');
echo "FM-G7B1 governed skill catalog: PASS ($n assertions)\n";
