<?php
declare(strict_types=1);

function app_has_permission(string $permission,array $user):bool
{
    return in_array('*',(array)($user['permissions']??[]),true)
        ||in_array($permission,(array)($user['permissions']??[]),true);
}
require_once __DIR__.'/../includes/food-monster-unified-knowledge.php';
$checks=0;
function fmuk_assert(bool $yes,string $message):void
{
    global $checks;
    if(!$yes)throw new RuntimeException('FM-UK FAIL: '.$message);
    $checks++;
}
$owner=['id'=>11,'organization_id'=>7,'permissions'=>['menu.view','recipes.view','recipes.agent','schedule.view','staff.view','inventory.view','tasks.view','sales.view','sales.agent']];
$employee=['id'=>22,'organization_id'=>7,'permissions'=>['menu.view','schedule.self','tasks.self']];
$outsider=['id'=>33,'organization_id'=>8,'permissions'=>[]];
fmuk_assert(in_array('menu',fmuk_topics('What pizzas are on the menu?'),true),'menu intent');
fmuk_assert(in_array('recipes',fmuk_topics('How do I make the house dough recipe?'),true),'recipe intent');
fmuk_assert(in_array('schedule',fmuk_topics('Who is working tonight?'),true),'schedule intent');
fmuk_assert(in_array('employees',fmuk_topics('Who are the employees?'),true),'employee intent');
fmuk_assert(in_array('inventory',fmuk_topics('What inventory is low on stock?'),true),'inventory intent');
fmuk_assert(in_array('tasks',fmuk_topics('Show my tasks.'),true),'task intent');
fmuk_assert(in_array('sales',fmuk_topics('What are net sales?'),true),'sales intent');
fmuk_assert(fmuk_topics('Create a recipe')===[],'writes excluded from read broker');
fmuk_assert(fmuk_topics('Approve the employee shift')===[],'approvals excluded');
fmuk_assert(count(fmuk_topics('Show employees schedules, inventory, tasks and sales'))===3,'retrieval bounded');
fmuk_assert(fmuk_capability_question('Do you have access to the menu?'),'capability question');
fmuk_assert(fmuk_menu_overview_question('What is on the menu?'),'menu overview question');
fmuk_assert(fmuk_roster_question('Who are our employees?'),'employee roster question');
fmuk_assert(fmuk_prefers_broker('What is on the menu?',$owner),'generic menu uses broker');
fmuk_assert(!fmuk_prefers_broker('Create a shift for Sam',$owner),'writes stay with governed tools');
fmuk_assert(fmuk_prefers_broker('What are my shifts tomorrow?',$employee),'schedule self without agent permission');
fmuk_assert(fmuk_schedule_target_day('Who is on tonight?',new DateTimeImmutable('2026-10-23'))==='2026-10-23','today shift filter');
fmuk_assert(fmuk_schedule_target_day('Who is on tomorrow?',new DateTimeImmutable('2026-10-23'))==='2026-10-24','tomorrow shift filter');
fmuk_assert(!fmuk_menu_overview_question('Does the pesto pizza contain milk?'),'allergen uses specialized handler');
fmuk_assert(fmuk_allowed($employee,'menu',true,false),'staff-facing menu for unknown voice');
fmuk_assert(!fmuk_allowed($employee,'schedule',true,false),'unknown voice cannot borrow self schedule');
fmuk_assert(!fmuk_allowed($owner,'employees',true,false),'unknown voice cannot borrow owner staff roster');
fmuk_assert(!fmuk_allowed($owner,'recipes',true,false),'unknown voice cannot borrow private recipes');
fmuk_assert(fmuk_allowed($owner,'recipes',true,true),'freshly verified owner voice can read recipe');
fmuk_assert(fmuk_allowed($employee,'schedule',false,false),'employee can read own schedule');
fmuk_assert(!fmuk_allowed($employee,'employees',false,false),'employee cannot read full roster');
fmuk_assert(!fmuk_allowed($outsider,'menu',false,false),'no menu access without permission');
fmuk_assert(!in_array('staff roster',fmuk_capabilities($employee,false,false),true),'capabilities filter employee roster');
fmuk_assert(!in_array('recorded sales',fmuk_capabilities($owner,true,false),true),'unknown voice capabilities are bounded');
$items=[['id'=>21,'name'=>'Margherita','ingredients'=>['Tomato','Mozzarella'],
    'prices'=>[['label'=>'12 inch','amount'=>17.5,'currency'=>'USD']]]];
[$lines,$sources]=fmuk_render_menu($items);
fmuk_assert(str_contains($lines[0],'17.50 USD')&&str_contains($lines[0],'Mozzarella'),'menu answer uses stored price and ingredient');
fmuk_assert($sources===['Gelato active menu item #21'],'menu source provenance');
[$lines,$sources]=fmuk_render_menu([['id'=>22,'name'=>'Special','prices'=>[]]]);
fmuk_assert(str_contains($lines[0],'not recorded'),'unknown prices not fabricated');
$recipe=['name'=>'Dough','public_id'=>'recipe-123','status'=>'active','yield_quantity'=>10,'yield_unit'=>'balls',
    'ingredients_json'=>json_encode([['quantity'=>1000,'unit'=>'g','ingredient'=>'Flour']]),
    'instructions_json'=>json_encode([['text'=>'Mix flour']])];
[$lines,$sources]=fmuk_render_recipes([$recipe]);
fmuk_assert(str_contains($lines[0],'1000 g Flour')&&str_contains($lines[0],'Mix flour'),'recipe answer from saved records');
fmuk_assert($sources===['Gelato active recipe recipe-123'],'recipe source provenance');
[$lines,$sources]=fmuk_render_recipes([array_replace($recipe,['status'=>'inactive'])]);
fmuk_assert($lines===[]&&$sources===[],'inactive recipes cannot be exposed');
$root=dirname(__DIR__);
$api=(string)file_get_contents($root.'/api/food-monster-unified-knowledge.php');
$route=(string)file_get_contents($root.'/api/agent-workspace.php');
fmuk_assert(str_contains($api,'app_require_auth()')&&str_contains($api,'app_verify_request_csrf'),'endpoint requires auth and CSRF');
fmuk_assert(str_contains($api,'gaw_thread($pdo,$org,$uid,$thread)')&&str_contains($api,'gaw_messages($pdo,$org,$uid,$thread,1)'),'canonical tenant-bound latest turn required');
fmuk_assert(str_contains($api,'avc_verified_speaker')&&str_contains($api,'voice_event_public_id'),'server-based voice verification');
fmuk_assert(!str_contains($api,'fmllm_exchange')&&!str_contains($api,'curl_'),'records remain in Gelato server');
$broker=(string)file_get_contents($root.'/includes/food-monster-unified-knowledge.php');
fmuk_assert(!str_contains($broker,'operations_task_list(') || str_contains($broker,'Do not call operations_task_list()'),
    'task reads avoid side-effecting operations task list');
fmuk_assert(str_contains($broker,'restaurant_task_assignments a')&&str_contains($broker,'a.user_id=?'),
    'self-task lookups retain explicit assignment scope');
fmuk_assert(str_contains($route,'api/food-monster-unified-knowledge.php?threadId='),'router chooses unified read');
fmuk_assert(str_contains($api,"\$_GET['threadId']"),'existing browser route carries server-issued thread reference');
echo 'FM-UK read broker authorization and evidence: PASS ('.$checks.' assertions)' . "\n";
