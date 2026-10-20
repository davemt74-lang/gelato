<?php
declare(strict_types=1);

/**
 * FM-KB1: read-only, principal-scoped Food Monster knowledge projections.
 * The request principal is always obtained from the signed-in session.
 * Future HomeServer service authentication must resolve to an equally scoped
 * Gelato principal BEFORE calling these functions.
 */
require_once __DIR__.'/menu-training-knowledge.php';
require_once __DIR__.'/restaurant-brain.php';

function fm_kb1_allowed_types(array $user): array
{
    $allowed=[];
    if(app_has_permission('menu.view',$user)
        ||(app_has_permission('employee.self',$user)&&app_has_permission('training.self_view',$user)))$allowed[]='menu';
    if(app_has_permission('recipes.view',$user)&&app_has_permission('recipes.agent',$user))$allowed[]='recipe';
    return $allowed;
}

function fm_kb1_request(string $query,string $type,int $limit): array
{
    $query=preg_replace('/\s+/u',' ',trim($query))??'';
    if($query===''||mb_strlen($query,'UTF-8')>160)
        throw new InvalidArgumentException('Search must contain between 1 and 160 characters.');
    if(!in_array($type,['all','menu','recipe'],true))
        throw new InvalidArgumentException('Unsupported knowledge type.');
    if($limit<1||$limit>20)throw new InvalidArgumentException('Limit must be between 1 and 20.');
    return [$query,$type,$limit];
}

function fm_kb1_revision(array $projection): string
{
    return hash('sha256',json_encode($projection,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

function fm_kb1_menu_document(array $item): array
{
    $id=(int)($item['id']??0);
    $title=trim((string)($item['name']??''));
    if($id<1||$title==='')throw new InvalidArgumentException('Invalid menu evidence.');
    $ingredients=array_values(array_filter(array_map(
        static fn($v):string=>is_string($v)?trim($v):'',(array)($item['ingredients']??[])
    ),static fn(string $v):bool=>$v!==''));
    $body=[
        'description'=>(string)($item['description']??''),
        'section'=>(string)($item['sectionName']??''),
        'preparationNotes'=>(string)($item['preparationNotes']??''),
        'ingredients'=>array_slice($ingredients,0,60),
        'allergens'=>(array)($item['allergens']??[]),
        'allergenWarning'=>'Ingredient indicators are not a safety guarantee. Verify ingredients, suppliers and cross-contact.',
    ];
    return [
        'id'=>'gelato:menu:'.$id,'type'=>'menu','title'=>$title,
        'content'=>$body,'updatedAt'=>null,
        'source'=>['app'=>'food_monster','kind'=>'active_menu_item','id'=>(string)$id],
        'revision'=>fm_kb1_revision(['id'=>$id,'title'=>$title,'content'=>$body]),
    ];
}

function fm_kb1_recipe_lines(?string $json,int $max=60): array
{
    $decoded=json_decode((string)$json,true);
    if(!is_array($decoded))return [];
    $out=[];
    foreach(array_slice($decoded,0,$max) as $row){
        if(is_string($row))$line=trim($row);
        elseif(is_array($row)){
            // Explicit field allowlist avoids surfacing internal raw JSON keys.
            $parts=[];
            foreach(['quantity','unit','ingredient','name','text','instruction','notes'] as $key){
                if(isset($row[$key])&&(is_string($row[$key])||is_numeric($row[$key])))
                    $parts[]=trim((string)$row[$key]);
            }
            $line=trim(implode(' ',array_filter($parts,static fn($part)=>$part!=='')));
        }else continue;
        if($line!=='')$out[]=mb_substr($line,0,800,'UTF-8');
    }
    return $out;
}

function fm_kb1_recipe_document(array $recipe): array
{
    $id=trim((string)($recipe['public_id']??''));
    $title=trim((string)($recipe['name']??''));
    if($id===''||$title==='')throw new InvalidArgumentException('Invalid recipe evidence.');
    $body=[
        'description'=>(string)($recipe['description']??''),
        'category'=>(string)($recipe['category']??''),
        'yieldQuantity'=>isset($recipe['yield_quantity'])?(float)$recipe['yield_quantity']:null,
        'yieldUnit'=>(string)($recipe['yield_unit']??''),
        'ingredients'=>fm_kb1_recipe_lines($recipe['ingredients_json']??null),
        'instructions'=>fm_kb1_recipe_lines($recipe['instructions_json']??null),
        'notes'=>(string)($recipe['notes']??''),
        'safetyNote'=>'Use the current approved production standard. Recipe and allergen details require verification.',
    ];
    return [
        'id'=>'gelato:recipe:'.$id,'type'=>'recipe','title'=>$title,
        'content'=>$body,'updatedAt'=>isset($recipe['updated_at'])?(string)$recipe['updated_at']:null,
        'source'=>['app'=>'food_monster','kind'=>'active_recipe','id'=>$id],
        'revision'=>fm_kb1_revision(['id'=>$id,'title'=>$title,'content'=>$body,'updatedAt'=>$recipe['updated_at']??null]),
    ];
}

/**
 * Search is intentionally NOT a bulk sync endpoint. Future incremental sync
 * requires independently governed revision/tombstone and revocation contracts.
 * Reader injection is for isolated authorization and tenant-boundary tests.
 */
function fm_kb1_search(PDO $pdo,array $user,string $query,string $type='all',int $limit=10,
    ?callable $menuReader=null,?callable $recipeReader=null): array
{
    [$query,$type,$limit]=fm_kb1_request($query,$type,$limit);
    $org=(int)($user['organization_id']??0);
    if($org<=0)throw new DomainException('An authorized organization is required.');
    $allowed=fm_kb1_allowed_types($user);
    if(!$allowed)throw new DomainException('No Food Monster knowledge access.');
    if($type!=='all'&&!in_array($type,$allowed,true))
        throw new DomainException('This knowledge category is not authorized.');
    $types=$type==='all'?$allowed:[$type];
    $menuReader??=static fn(PDO $pdo,int $org,string $q,int $limit):array=>
        menu_training_search_items($pdo,$org,$q,$limit);
    $recipeReader??=static fn(PDO $pdo,int $org,string $q,int $limit):array=>
        restaurant_brain_recipe_search($pdo,$org,$q,$limit);
    $results=[];
    foreach($types as $kind){
        $rows=$kind==='menu'?$menuReader($pdo,$org,$query,$limit):$recipeReader($pdo,$org,$query,$limit);
        foreach($rows as $row){
            // Defensive status checks in addition to the tenant/active SQL filters.
            if($kind==='recipe'&&((string)($row['status']??'')!=='active'||!empty($row['archived_at'])))continue;
            if($kind==='menu'&&isset($row['is_active'])&&!(bool)$row['is_active'])continue;
            $results[]=$kind==='menu'?fm_kb1_menu_document($row):fm_kb1_recipe_document($row);
            if(count($results)>=$limit)break 2;
        }
    }
    return ['ok'=>true,'schema'=>'food-monster-knowledge-v1','readOnly'=>true,
        'types'=>$types,'results'=>$results,'count'=>count($results)];
}
