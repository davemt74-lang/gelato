<?php
declare(strict_types=1);
function app_has_permission(string $permission,?array $user=null):bool
{
    return in_array('*',(array)($user['permissions']??[]),true)
        || in_array($permission,(array)($user['permissions']??[]),true);
}
require __DIR__.'/../includes/food-monster-knowledge-manifest.php';
class KB5APDO extends PDO{public function __construct(){}}
$pdo=new KB5APDO();
$checks=0;
function verify5(bool $yes,string $description):void{
    global $checks;
    if(!$yes)throw new RuntimeException('KB5A FAIL: '.$description);
    $checks++;
}
function denies5(callable $fn,string $class):bool{
    try{$fn();}catch(Throwable $e){return $e instanceof $class;}
    return false;
}
$owner=['organization_id'=>8,'id'=>100,'permissions'=>['menu.view','recipes.view','recipes.agent']];
$menuUser=['organization_id'=>8,'id'=>101,'permissions'=>['menu.view']];
$none=['organization_id'=>8,'id'=>102,'permissions'=>[]];
$menuRows=[
    ['rowId'=>11,'record'=>['id'=>11,'name'=>'Pepperoni Pizza','ingredients'=>['Pepperoni']]],
    ['rowId'=>16,'record'=>['id'=>16,'name'=>'Margherita Pizza','ingredients'=>['Cheese']]],
    ['rowId'=>21,'record'=>['id'=>21,'name'=>'House Special','ingredients'=>['Basil']]],
];
$recipes=[
    ['rowId'=>4,'record'=>['id'=>4,'public_id'=>'dough-a','name'=>'House Dough A',
        'status'=>'active','ingredients_json'=>'[{"ingredient":"flour","quantity":850}]']],
    ['rowId'=>8,'record'=>['id'=>8,'public_id'=>'dough-b','name'=>'House Dough B',
        'status'=>'active','instructions_json'=>'[{"text":"Mix dough"}]']],
];
$readMenu=function(PDO $db,int $org,int $after,int $count)use($menuRows):array{
    verify5($org===8,'tenant forwarded to menu reader');
    return array_slice(array_values(array_filter($menuRows,static fn($r)=>$r['rowId']>$after)),0,$count);
};
$readRecipe=function(PDO $db,int $org,int $after,int $count)use($recipes):array{
    verify5($org===8,'tenant forwarded to recipe reader');
    return array_slice(array_values(array_filter($recipes,static fn($r)=>$r['rowId']>$after)),0,$count);
};
verify5(denies5(fn()=>fm_kb5a_validate('all',0,10),InvalidArgumentException::class),'category must be explicit');
verify5(denies5(fn()=>fm_kb5a_validate('menu',-1,10),InvalidArgumentException::class),'negative cursor rejected');
verify5(denies5(fn()=>fm_kb5a_validate('menu',2147483648,10),InvalidArgumentException::class),'huge cursor rejected');
verify5(denies5(fn()=>fm_kb5a_validate('menu',0,21),InvalidArgumentException::class),'oversized page rejected');
verify5(denies5(fn()=>fm_kb5a_validate('recipe',0,0),InvalidArgumentException::class),'empty page rejected');
verify5(denies5(fn()=>fm_kb5a_page($pdo,$none,'menu',0,10,$readMenu,$readRecipe),DomainException::class),'no permission denied');
verify5(denies5(fn()=>fm_kb5a_page($pdo,$menuUser,'recipe',0,10,$readMenu,$readRecipe),DomainException::class),'menu principal denied recipes');
verify5(denies5(fn()=>fm_kb5a_page($pdo,['organization_id'=>0,'permissions'=>['*']],'menu',0,10,$readMenu,$readRecipe),DomainException::class),'unbound organization rejected');
$first=fm_kb5a_page($pdo,$owner,'menu',0,2,$readMenu,$readRecipe);
verify5($first['schema']==='food-monster-knowledge-manifest-v1','manifest protocol is versioned');
verify5($first['readOnly']===true,'read-only flag');
verify5($first['collection']==='menu','menu collection identity');
verify5($first['count']===2,'first page bounded');
verify5($first['complete']===false,'partial pages explicitly incomplete');
verify5($first['nextAfterId']===16,'cursor advances to last included item not extra row');
verify5($first['results'][0]['id']==='gelato:menu:11','stable menu source ID');
verify5(preg_match('/^[a-f0-9]{64}$/D',$first['results'][0]['revision'])===1,'SHA revision present');
verify5(!isset($first['results'][0]['prices']),'pricing excluded');
$last=fm_kb5a_page($pdo,$owner,'menu',$first['nextAfterId'],2,$readMenu,$readRecipe);
verify5($last['count']===1&&$last['results'][0]['id']==='gelato:menu:21','page continuation exact');
verify5($last['complete']===true&&$last['nextAfterId']===null,'only final page may close sweep');
$empty=fm_kb5a_page($pdo,$owner,'menu',999,20,$readMenu,$readRecipe);
verify5($empty['count']===0&&$empty['complete']===true,'empty final page is complete');
$r=fm_kb5a_page($pdo,$owner,'recipe',0,1,$readMenu,$readRecipe);
verify5($r['count']===1&&$r['complete']===false&&$r['nextAfterId']===4,'recipe next cursor');
verify5($r['results'][0]['type']==='recipe','recipe source typed');
verify5($r['results'][0]['content']['ingredients']===['850 flour'],'reuses KB1 safe projections');
$r2=fm_kb5a_page($pdo,$owner,'recipe',$r['nextAfterId'],1,$readMenu,$readRecipe);
verify5($r2['complete']===true&&$r2['results'][0]['id']==='gelato:recipe:dough-b','recipe continuation');
verify5(denies5(fn()=>fm_kb5a_page($pdo,$owner,'recipe',0,2,$readMenu,
    static fn()=>[['rowId'=>4,'record'=>['public_id'=>'bad','name'=>'Hidden','status'=>'archived']]]),RuntimeException::class),'inactive recipe denied even if reader malformed');
verify5(denies5(fn()=>fm_kb5a_page($pdo,$owner,'menu',0,2,
    static fn()=>[['rowId'=>7,'record'=>['id'=>7,'name'=>'Test']],['rowId'=>7,'record'=>['id'=>7,'name'=>'Duplicate']]],$readRecipe),RuntimeException::class),'duplicate ordered cursor rejected');
verify5(denies5(fn()=>fm_kb5a_page($pdo,$owner,'menu',0,2,
    static fn()=>array_fill(0,4,['rowId'=>12,'record'=>['id'=>12,'name'=>'Excess']]),$readRecipe),RuntimeException::class),'reader cannot exceed page bound');
$api=file_get_contents(__DIR__.'/../api/food-monster-vp3.php');
verify5(str_contains($api,"if(\$action==='manifest')"),'manifest action registered');
verify5(str_contains($api,"fm_kb2_grant(app_pdo(),fm_kb2_bearer(\$_SERVER))"),'manifest authenticates active grant');
verify5(str_contains($api,"'knowledge.search','knowledge.manifest'"),'advertises safe capability');
verify5(!str_contains($api,'pizzathehut')&&!str_contains($api,'gelato.vp3.me'),'no hardcoded deployment hostname');
echo "FM-KB5A paged manifest PASS ({$checks} assertions)\n";
