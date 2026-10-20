<?php
declare(strict_types=1);
function app_has_permission(string $permission,?array $user=null):bool {
    return in_array('*',(array)($user['permissions']??[]),true)
        ||in_array($permission,(array)($user['permissions']??[]),true);
}
require __DIR__.'/../includes/food-monster-knowledge-api.php';
class KB1TestPDO extends PDO {public function __construct(){}}
$n=0;
function kb1_check(bool $condition,string $message):void {
    global $n;
    if(!$condition)throw new RuntimeException('FM-KB1 FAIL: '.$message);
    $n++;
}
function kb1_throws(callable $run,string $exception):bool {
    try{$run();}catch(Throwable $e){return $e instanceof $exception;}
    return false;
}
$pdo=new KB1TestPDO();
$staff=['organization_id'=>7,'id'=>9,'permissions'=>['menu.view']];
$trained=['organization_id'=>7,'id'=>10,'permissions'=>['employee.self','training.self_view']];
$owner=['organization_id'=>7,'id'=>11,'permissions'=>['menu.view','recipes.view','recipes.agent']];
$none=['organization_id'=>7,'id'=>12,'permissions'=>[]];
$menuCalls=[];$recipeCalls=[];
$menuReader=function(PDO $pdo,int $org,string $query,int $limit)use(&$menuCalls):array{
    $menuCalls[]=[$org,$query,$limit];
    return [['id'=>12,'name'=>'Margherita Pizza','sectionName'=>'Pizza',
        'description'=>'Fresh mozzarella and tomato','preparationNotes'=>'Stretch and bake',
        'ingredients'=>['Dough','Fresh mozzarella','Tomato sauce'],
        'allergens'=>['direct'=>[['id'=>'milk','name'=>'Milk']]],
        'prices'=>[['amount'=>18]]]];
};
$recipeReader=function(PDO $pdo,int $org,string $query,int $limit)use(&$recipeCalls):array{
    $recipeCalls[]=[$org,$query,$limit];
    return [
      ['public_id'=>'recipe-dough','name'=>'House Dough','status'=>'active',
       'description'=>'Approved batch','yield_quantity'=>12,'yield_unit'=>'balls',
       'ingredients_json'=>json_encode([['quantity'=>'800','unit'=>'g','ingredient'=>'flour'],
                                        ['quantity'=>520,'unit'=>'g','ingredient'=>'water']]),
       'instructions_json'=>json_encode([['text'=>'Mix ingredients'],['text'=>'Rest dough']]),
       'notes'=>'Use manager-approved procedure','updated_at'=>'2026-10-20 07:00:00'],
      ['public_id'=>'recipe-disabled','name'=>'Retired Dough','status'=>'inactive'],
      ['public_id'=>'recipe-archived','name'=>'Archived Dough','status'=>'active','archived_at'=>'2026-10-01'],
    ];
};
kb1_check(fm_kb1_allowed_types($staff)===['menu'],'menu-view permits only menu');
kb1_check(fm_kb1_allowed_types($trained)===['menu'],'self training permits staff menu');
kb1_check(fm_kb1_allowed_types($owner)===['menu','recipe'],'owner permissions permit both');
kb1_check(fm_kb1_allowed_types($none)===[],'ungranted principal has no access');
kb1_check(kb1_throws(fn()=>fm_kb1_request('','all',10),InvalidArgumentException::class),'no empty search');
kb1_check(kb1_throws(fn()=>fm_kb1_request(str_repeat('z',161),'all',10),InvalidArgumentException::class),'bounded search');
kb1_check(kb1_throws(fn()=>fm_kb1_request('dough','equipment',10),InvalidArgumentException::class),'allowlisted types');
kb1_check(kb1_throws(fn()=>fm_kb1_request('dough','menu',21),InvalidArgumentException::class),'bounded results');
kb1_check(fm_kb1_request('  pizza  dough  ','menu',3)[0]==='pizza dough','normalized query');
$menu=fm_kb1_search($pdo,$staff,'Margherita','all',10,$menuReader,$recipeReader);
kb1_check($menu['schema']==='food-monster-knowledge-v1'&&$menu['readOnly']===true,'versioned read-only response');
kb1_check($menu['count']===1&&$menu['types']===['menu'],'staff can only retrieve menu');
kb1_check(count($recipeCalls)===0,'forbidden recipe reader was never invoked');
kb1_check($menuCalls[0]===[7,'Margherita',10],'authorized tenant and query reach reader');
$first=$menu['results'][0];
kb1_check($first['id']==='gelato:menu:12'&&$first['source']['kind']==='active_menu_item','stable menu provenance');
kb1_check(!isset($first['content']['prices']),'prices omitted from knowledge snapshot (live data only)');
kb1_check(str_contains($first['content']['allergenWarning'],'not a safety guarantee'),'allergen warning required');
kb1_check($first['revision']===fm_kb1_menu_document($menuReader($pdo,7,'Margherita',10)[0])['revision'],'deterministic revision');
$updated=$menuReader($pdo,7,'Margherita',10)[0];
$updated['preparationNotes']='Different preparation';
kb1_check($first['revision']!==fm_kb1_menu_document($updated)['revision'],'revisions change with content');
kb1_check(kb1_throws(fn()=>fm_kb1_search($pdo,$staff,'dough','recipe',10,$menuReader,$recipeReader),DomainException::class),'explicit forbidden recipe rejects');
kb1_check(count($recipeCalls)===0,'forbidden explicit recipe never reads DB');
kb1_check(kb1_throws(fn()=>fm_kb1_search($pdo,$none,'pizza','all',10,$menuReader,$recipeReader),DomainException::class),'ungranted principal blocked');
kb1_check(kb1_throws(fn()=>fm_kb1_search($pdo,['organization_id'=>0,'permissions'=>['*']],'pizza','all',10,$menuReader,$recipeReader),DomainException::class),'missing tenant blocked');
$before=count($menuCalls);
$recipes=fm_kb1_search($pdo,$owner,'dough','recipe',10,$menuReader,$recipeReader);
kb1_check(count($menuCalls)===$before,'recipe-only search does not access menu reader');
kb1_check(count($recipeCalls)===1&&$recipeCalls[0][0]===7,'recipe search scoped by authorized organization');
kb1_check($recipes['count']===1,'archived and inactive recipes omitted');
$rec=$recipes['results'][0];
kb1_check($rec['id']==='gelato:recipe:recipe-dough'&&$rec['source']['kind']==='active_recipe','recipe provenance');
kb1_check(count($rec['content']['ingredients'])===2&&count($rec['content']['instructions'])===2,'structured ingredients and steps');
kb1_check($rec['updatedAt']==='2026-10-20 07:00:00','real record update timestamp');
kb1_check(fm_kb1_recipe_lines('invalid')===[],'invalid JSON cannot leak raw fields');
kb1_check(fm_kb1_recipe_lines(json_encode([['text'=>'Bake','secret'=>'PRIVATE']],JSON_THROW_ON_ERROR))===['Bake'],'only allowlisted source fields exported');
kb1_check(kb1_throws(fn()=>fm_kb1_menu_document(['id'=>0]),InvalidArgumentException::class),'invalid menu evidence rejected');
$api=file_get_contents(__DIR__.'/../api/food-monster-knowledge-v1.php');
kb1_check(is_string($api)&&str_contains($api,'app_current_user()')&&str_contains($api,'Authentication required.'),'endpoint rejects unauthenticated access as JSON');
kb1_check(str_contains($api,"['types'=>\$response['types'],'count'=>\$response['count']]"),'audit stores metadata only');
echo "FM-KB1 knowledge API PASS ({$n} assertions)\n";
