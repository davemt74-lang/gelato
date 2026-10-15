<?php
declare(strict_types=1);
/** FM-G7B1: curated Agent Brain discovery only; no execution or tool grants. */
require_once __DIR__.'/agent-node-registry.php';
function fm_skill_registry_definitions():array {
 return [
  'brain_brief'=>['node'=>'brain','label'=>'Agent Brain priorities','description'=>'Review cross-department risks and next moves.','prompt'=>'Show the Agent Brain priorities and recommended next reviews.','manager'=>true],
  'sales'=>['node'=>'sales','label'=>'Verified sales','description'=>'Review recorded sales totals from Sales Intelligence.','prompt'=>'What were our recorded sales today?','all'=>['sales.view','sales.agent']],
  'clock_ins'=>['node'=>'timeclock','label'=>'Staff clock-ins','description'=>'Review currently clocked-in staff.','prompt'=>'Who is currently clocked in?','all'=>['timeclock.agent'],'any'=>[['timeclock.view'],['attendance.view']]],
  'kitchen'=>['node'=>'kds','label'=>'Kitchen tickets','description'=>'Review kitchen tickets and service priorities.','prompt'=>'Review KDS tickets and tell me what needs attention.','all'=>['kds.view']],
  'inventory'=>['node'=>'operations','label'=>'Inventory & tasks','description'=>'Review low stock and operational tasks.','prompt'=>'Review inventory shortages and overdue restaurant tasks.','any'=>[['inventory.view','inventory.agent'],['tasks.view','tasks.agent']]],
  'staffing'=>['node'=>'scheduling','label'=>'Staffing & shifts','description'=>'Review staffing coverage and open shifts.','prompt'=>'Review staffing schedule coverage and open shifts.','any'=>[['schedule.view'],['schedule.agent']]],
  'purchasing'=>['node'=>'purchasing','label'=>'Purchasing needs','description'=>'Review purchases and vendor follow-ups.','prompt'=>'Review purchasing suggestions, suppliers and open purchase orders.','all'=>['purchasing.agent','purchasing.view']],
  'prep'=>['node'=>'prep','label'=>'Prep forecast','description'=>'Review shortages and upcoming prep needs.','prompt'=>'Review prep forecasts and production shortages.','all'=>['prep.intelligence.view','prep.intelligence.agent']],
  'recipes'=>['node'=>'recipes','label'=>'Recipe standards','description'=>'Review approved recipes and standards.','prompt'=>'Review recipe production standards and available recipes.','all'=>['recipes.view','recipes.agent']],
  'equipment'=>['node'=>'equipment','label'=>'Equipment service','description'=>'Review maintenance and equipment status.','prompt'=>'Review equipment maintenance due and service history.','all'=>['equipment.view','agent.equipment_skills']],
  'catering'=>['node'=>'catering','label'=>'Catering readiness','description'=>'Review upcoming catering commitments.','prompt'=>'Review catering readiness and upcoming events.','all'=>['catering.view','catering.agent']],
  'customers'=>['node'=>'crm','label'=>'Customer relationships','description'=>'Review customer relationship follow-ups.','prompt'=>'Review CRM customer follow-ups and retention trends.','all'=>['crm.view']],
  'timeclock'=>['node'=>'timeclock','label'=>'Attendance','description'=>'Review employee attendance exceptions.','prompt'=>'Review time clock attendance issues and exceptions.','all'=>['timeclock.agent']],
 ];
}
function fm_skill_registry_allowed(array $user,array $entry):bool {
 if(!empty($entry['manager'])&&(!function_exists('admin_dashboard_allowed')||!admin_dashboard_allowed($user)))return false;
 foreach((array)($entry['all']??[]) as $key)if(!app_has_permission((string)$key,$user))return false;
 $options=(array)($entry['any']??[]);
 if($options){
  $eligible=false;
  foreach($options as $group){
   if(!is_array($group)||!$group)continue;
   $ok=true;foreach($group as $permission)if(!app_has_permission((string)$permission,$user)){$ok=false;break;}
   if($ok){$eligible=true;break;}
  }
  if(!$eligible)return false;
 }
 return true;
}
function fm_skill_registry_for_user(array $user):array {
 $nodes=gaw_agent_nodes();$skills=[];
 foreach(fm_skill_registry_definitions() as $id=>$entry){
  if(!isset($nodes[$entry['node']])||!fm_skill_registry_allowed($user,$entry))continue;
  $skills[]=['id'=>$id,'node'=>$entry['node'],'label'=>$entry['label'],
   'description'=>$entry['description'],'prompt'=>$entry['prompt'],'mode'=>'chat_review',
   'approval'=>'existing_node_policy'];
 }
 return $skills;
}
