<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/operations-core.php';
require_once dirname(__DIR__).'/includes/catering-operations.php';
$checks=0;
function hri_check(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);++$GLOBALS['checks'];}
$examples=[
    ['2 tbsp olive oil','olive oil',2.0,'tbsp'],
    ['1 cup brown sugar','brown sugar',1.0,'cup'],
    ['1/2 kg bread flour','bread flour',0.5,'kg'],
    ['1 1/2 fluid ounces extra virgin olive oil','extra virgin olive oil',1.5,'fl oz'],
    ['2½ cups brown sugar','brown sugar',2.5,'cup'],
    ['½ tsp sea salt','sea salt',0.5,'tsp'],
    ['1⁄2 kg bread flour','bread flour',0.5,'kg'],
    ['.25 lb unsalted butter','unsalted butter',0.25,'lb'],
    ['2 eggs','eggs',2.0,''],
    ['1 fl. oz. rose water','rose water',1.0,'fl oz'],
    [['name'=>'sea salt','quantity'=>'1 1/2','unit'=>'teaspoons'],'sea salt',1.5,'tsp'],
    [['ingredient'=>'crème fraîche','amount'=>'⅓','measure'=>'cups'],'crème fraîche',1/3,'cup'],
    [['item'=>'olive oil','qty'=>'1/2','unit'=>'tbsp'],'olive oil',0.5,'tbsp'],
    [['name'=>'milk','quantity'=>0,'unit'=>'millilitres'],'milk',0.0,'ml'],
];
foreach($examples as [$raw,$name,$amount,$unit]){
    $parsed=operations_parse_ingredient($raw);
    hri_check($parsed!==null&&$parsed['name']===$name,'Complete multiword name must be preserved: '.json_encode($raw));
    hri_check($parsed['quantity']!==null&&abs($parsed['quantity']-$amount)<1e-10,'Fraction/decimal amount must be exact: '.json_encode($raw));
    hri_check($parsed['unit']===$unit&&!$parsed['needsReview'],'Known unit must stop before the ingredient name.');
    hri_check($parsed['raw']===$raw,'Saved input must survive parsing unchanged.');
    hri_check(catering_operations_parse_ingredient($raw)===$parsed,'Operations and Catering must share the same amount semantics.');
}
foreach(['1-2','1 to 2','about 2','2 cups','1/0','-1','1e4','NaN',true,[],INF] as $raw){
    $parsed=operations_parse_ingredient(['name'=>'brown sugar','quantity'=>$raw,'unit'=>'cup']);
    hri_check($parsed['quantity']===null&&$parsed['needsReview'],'Ambiguous or invalid amount must require review: '.json_encode($raw));
}
foreach(['salt to taste','1-2 cups brown sugar','1 to 2 cups brown sugar','1 TO 2 cups brown sugar','1 OR 2 cups brown sugar','1/0 cup flour','2 tbsp','1 cup','1 fluid ounce','2 TBSP.'] as $raw){
    $parsed=operations_parse_ingredient($raw);hri_check($parsed['quantity']===null&&$parsed['needsReview']&&$parsed['raw']===$raw,'Unreviewed text must not become a guessed numeric requirement.');
}
$recipe=['public_id'=>'recipe-pure','name'=>'Pure Recipe','status'=>'active','archived_at'=>null,'updated_at'=>'2026-11-03 00:00:00','ingredients_json'=>json_encode([
    ['name'=>'Flour','quantity'=>'1/2','unit'=>'kg'],['name'=>'flour','quantity'=>'1 1/2','unit'=>'kilograms']
],JSON_THROW_ON_ERROR)];
$plan=operations_recipe_source_plan([$recipe]);$flour=$plan['recipe:recipe-pure']['ingredients']['flour'];
hri_check($flour['quantity']===2.0&&count($flour['lines'])===2,'Duplicate same-unit ingredient lines must sum instead of overwriting the first line.');
$recipe['ingredients_json']=json_encode([['name'=>'flour','quantity'=>1,'unit'=>'kg'],['name'=>'flour','quantity'=>1,'unit'=>'cup']]);
$blocked=false;try{operations_recipe_source_plan([$recipe]);}catch(InvalidArgumentException){$blocked=true;}
hri_check($blocked,'Incompatible duplicate units must be reviewed before any source replacement.');
$recipe['ingredients_json']='[{"name":"flour","quantity":"1-2","unit":"kg"}]';$blocked=false;
try{operations_recipe_source_plan([$recipe]);}catch(InvalidArgumentException){$blocked=true;}
hri_check($blocked,'A desired source set containing a range must fail before writes.');
foreach(['1 TO 2 cups brown sugar','1 OR 2 cups brown sugar','2 tbsp','1 cup'] as $raw){
    $recipe['ingredients_json']=json_encode([$raw],JSON_THROW_ON_ERROR);$blocked=false;
    try{operations_recipe_source_plan([$recipe]);}catch(InvalidArgumentException){$blocked=true;}
    hri_check($blocked,'An ambiguous range or missing ingredient name must not create a source: '.$raw);
}
$recipe['status']='archived';hri_check(operations_recipe_source_plan([$recipe])===[],'Archived recipes must not remain in the desired active source set.');
echo 'Recipe ingredient parsing: '.$checks." checks passed.\n";
