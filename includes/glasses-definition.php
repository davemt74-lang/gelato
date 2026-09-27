<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-core.php';
require_once __DIR__.'/menu-training-knowledge.php';

function glasses_definition_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_build_definitions'");
    $q->execute();
    return (int)$q->fetchColumn()===1;
}

function glasses_definition_normalize_name(string $value): string
{
    $value=mb_strtolower(trim($value),'UTF-8');
    $value=preg_replace('/[^\pL\pN]+/u',' ', $value)??$value;
    return trim(preg_replace('/\s+/u',' ',$value)??$value);
}

function glasses_definition_component_key(string $name,int $ingredientId): string
{
    if($ingredientId>0)return 'ingredient:'.$ingredientId;
    $slug=glasses_definition_normalize_name($name);
    $slug=str_replace(' ','-',$slug);
    return $slug!==''?'name:'.$slug:'name:unknown';
}

function glasses_definition_table_exists(PDO $pdo,string $table): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$table]);
    return (int)$q->fetchColumn()===1;
}

function glasses_definition_menu(PDO $pdo,int $org,int $menuItemId): array
{
    $menu=menu_training_item($pdo,$org,$menuItemId);
    if(!$menu)throw new InvalidArgumentException('Active menu item was not found.');
    $q=$pdo->prepare("SELECT ing.id,ing.canonical_name,mii.display_name,mii.is_optional,mii.can_remove,mii.sort_order
        FROM menu_item_ingredients mii
        JOIN ingredients ing ON ing.id=mii.ingredient_id AND ing.organization_id=?
        WHERE mii.menu_item_id=?
        ORDER BY mii.sort_order,ing.id");
    $q->execute([$org,$menuItemId]);
    $menu['ingredientDetails']=array_map(static fn(array $r):array=>[
        'id'=>(int)$r['id'],
        'name'=>(string)($r['display_name']?:$r['canonical_name']),
        'canonicalName'=>(string)$r['canonical_name'],
        'optional'=>(bool)$r['is_optional'],
        'canRemove'=>(bool)$r['can_remove'],
        'sortOrder'=>(int)$r['sort_order'],
    ],$q->fetchAll());
    return $menu;
}

function glasses_definition_quantity(mixed $value): ?float
{
    if(is_int($value)||is_float($value))return (float)$value;
    $raw=trim((string)$value);
    if($raw==='')return null;
    if(is_numeric($raw))return (float)$raw;
    if(preg_match('/^(\d+)\s+(\d+)\/(\d+)$/',$raw,$m)&&((int)$m[3])!==0)
        return (float)$m[1]+((float)$m[2]/(float)$m[3]);
    if(preg_match('/^(\d+)\/(\d+)$/',$raw,$m)&&((int)$m[2])!==0)
        return (float)$m[1]/(float)$m[2];
    if(preg_match('/^([0-9]+(?:\.[0-9]+)?)/',$raw,$m))return (float)$m[1];
    return null;
}

function glasses_definition_recipe(PDO $pdo,int $org,int $menuItemId,?string $recipePublicId=null): array
{
    $menu=glasses_definition_menu($pdo,$org,$menuItemId);
    if(!glasses_definition_table_exists($pdo,'recipes'))throw new RuntimeException('Recipe Library is not installed.');

    if($recipePublicId!==null&&trim($recipePublicId)!==''){
        $q=$pdo->prepare("SELECT * FROM recipes WHERE organization_id=? AND public_id=? AND archived_at IS NULL AND status='active' LIMIT 1");
        $q->execute([$org,trim($recipePublicId)]);$recipe=$q->fetch();
        if(!$recipe)throw new InvalidArgumentException('Active recipe was not found.');
        return ['menu'=>$menu,'recipe'=>$recipe,'resolution'=>'explicit'];
    }

    $q=$pdo->prepare("SELECT * FROM recipes WHERE organization_id=? AND archived_at IS NULL AND status='active' AND name=? ORDER BY updated_at DESC,id DESC LIMIT 3");
    $q->execute([$org,(string)$menu['name']]);$rows=$q->fetchAll();
    if(count($rows)===0)throw new InvalidArgumentException('No active recipe has the exact menu-item name.');
    if(count($rows)>1)throw new InvalidArgumentException('Multiple active recipes have this menu-item name. Choose a recipe explicitly.');
    return ['menu'=>$menu,'recipe'=>$rows[0],'resolution'=>'exact_name'];
}

function glasses_definition_match_recipe_ingredient(array $menuIngredient,array $recipeIngredients): ?array
{
    $names=[
        glasses_definition_normalize_name((string)($menuIngredient['canonicalName']??'')),
        glasses_definition_normalize_name((string)($menuIngredient['name']??'')),
    ];
    $names=array_values(array_unique(array_filter($names)));
    $matches=[];
    foreach($recipeIngredients as $index=>$raw){
        if(is_string($raw))$row=['ingredient'=>$raw,'quantity'=>'','unit'=>'','notes'=>''];
        elseif(is_array($raw))$row=$raw;
        else continue;
        $recipeName=glasses_definition_normalize_name((string)($row['ingredient']??$row['name']??''));
        if($recipeName!==''&&in_array($recipeName,$names,true))$matches[]=['index'=>$index,'row'=>$row];
    }
    return count($matches)===1?$matches[0]:null;
}

function glasses_definition_source_hash(array $menu,array $recipe): string
{
    $material=[
        'menu'=>[
            'id'=>$menu['id']??null,
            'name'=>$menu['name']??'',
            'ingredients'=>$menu['ingredientDetails']??[],
            'preparationNotes'=>$menu['preparationNotes']??'',
        ],
        'recipe'=>[
            'id'=>$recipe['id']??null,
            'publicId'=>$recipe['public_id']??'',
            'name'=>$recipe['name']??'',
            'ingredients'=>json_decode((string)($recipe['ingredients_json']??'[]'),true)?:[],
            'instructions'=>json_decode((string)($recipe['instructions_json']??'[]'),true)?:[],
            'updatedAt'=>$recipe['updated_at']??null,
        ],
    ];
    return hash('sha256',json_encode($material,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

function glasses_definition_compile_data(array $menu,array $recipe,string $resolution): array
{
    $recipeIngredients=json_decode((string)($recipe['ingredients_json']??'[]'),true)?:[];
    $instructions=json_decode((string)($recipe['instructions_json']??'[]'),true)?:[];
    $components=[];$unresolved=[];$matchedRecipeIndexes=[];

    foreach((array)($menu['ingredientDetails']??[]) as $sort=>$ingredient){
        $ingredientId=(int)($ingredient['id']??0);
        if($ingredientId<1)continue;
        $name=(string)($ingredient['name']??$ingredient['canonicalName']??'Ingredient');
        $key=glasses_definition_component_key($name,$ingredientId);
        $match=glasses_definition_match_recipe_ingredient($ingredient,$recipeIngredients);
        $quantity=1.0;$unit='portion';$matched=false;$notes='';
        if($match){
            $matched=true;$matchedRecipeIndexes[(int)$match['index']]=true;
            $row=(array)$match['row'];
            $parsed=glasses_definition_quantity($row['quantity']??null);
            if($parsed!==null&&$parsed>0)$quantity=$parsed;
            $unit=trim((string)($row['unit']??''))?:'portion';
            $notes=trim((string)($row['notes']??''));
        }elseif(empty($ingredient['optional'])){
            $unresolved[]=['type'=>'menu_ingredient_unmatched','componentKey'=>$key,'name'=>$name];
        }
        $components[]=[
            'componentKey'=>$key,
            'ingredientId'=>$ingredientId,
            'displayName'=>$name,
            'canonicalName'=>(string)($ingredient['canonicalName']??$name),
            'expectedQuantity'=>$quantity,
            'unit'=>$unit,
            'optional'=>!empty($ingredient['optional']),
            'sortOrder'=>$sort+1,
            'recipeMatched'=>$matched,
            'notes'=>$notes,
        ];
    }

    foreach($recipeIngredients as $index=>$raw){
        if(isset($matchedRecipeIndexes[$index]))continue;
        $row=is_array($raw)?$raw:['ingredient'=>(string)$raw];
        $name=trim((string)($row['ingredient']??$row['name']??''));
        if($name!=='')$unresolved[]=['type'=>'recipe_ingredient_unmapped','name'=>$name,'recipeIndex'=>$index];
    }

    $steps=[];
    foreach($instructions as $index=>$instruction){
        $text=is_array($instruction)?trim((string)($instruction['text']??'')):trim((string)$instruction);
        if($text==='')continue;
        $normalized=glasses_definition_normalize_name($text);
        $keys=[];
        foreach($components as $component){
            $needle=glasses_definition_normalize_name((string)$component['displayName']);
            if($needle!==''&&str_contains($normalized,$needle))$keys[]=$component['componentKey'];
        }
        $steps[]=['stepKey'=>'step:'.($index+1),'order'=>$index+1,'text'=>$text,'componentKeys'=>$keys];
    }

    return [
        'schema'=>'gelato.ar_build_definition.v1',
        'menuItem'=>['id'=>(int)$menu['id'],'name'=>(string)$menu['name']],
        'recipe'=>['publicId'=>(string)$recipe['public_id'],'name'=>(string)$recipe['name'],'resolution'=>$resolution],
        'components'=>$components,
        'steps'=>$steps,
        'unresolved'=>$unresolved,
        'ready'=>count($unresolved)===0&&count($components)>0,
    ];
}

function glasses_definition_compile(PDO $pdo,int $org,int $menuItemId,?string $recipePublicId,?int $userId): array
{
    if(!glasses_definition_ready($pdo))throw new RuntimeException('Glasses build-definition migration is not installed.');
    $source=glasses_definition_recipe($pdo,$org,$menuItemId,$recipePublicId);
    $menu=$source['menu'];$recipe=$source['recipe'];
    $definition=glasses_definition_compile_data($menu,$recipe,(string)$source['resolution']);
    $hash=glasses_definition_source_hash($menu,$recipe);
    $status=!empty($definition['ready'])?'ready':'needs_review';

    return glasses_transaction($pdo,function()use($pdo,$org,$menuItemId,$recipe,$definition,$hash,$status,$userId):array{
        $q=$pdo->prepare("SELECT id,public_id,version FROM glasses_build_definitions WHERE organization_id=? AND menu_item_id=? ORDER BY version DESC,id DESC LIMIT 1 FOR UPDATE");
        $q->execute([$org,$menuItemId]);$previous=$q->fetch();
        $version=$previous?((int)$previous['version']+1):1;
        $public=glasses_public_id('build-def');
        $pdo->prepare("INSERT INTO glasses_build_definitions (organization_id,public_id,menu_item_id,recipe_id,version,status,source_hash,definition_json,compiled_by) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$org,$public,$menuItemId,(int)$recipe['id'],$version,$status,$hash,json_encode($definition,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$userId]);
        if($status==='ready'){
            $pdo->prepare("UPDATE glasses_build_definitions SET status='superseded',updated_at=NOW(6) WHERE organization_id=? AND menu_item_id=? AND public_id<>? AND status='ready'")
                ->execute([$org,$menuItemId,$public]);
        }
        return glasses_definition_by_public_id($pdo,$org,$public);
    });
}

function glasses_definition_by_public_id(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT d.*,r.public_id recipe_public_id,r.name recipe_name,m.name menu_item_name
        FROM glasses_build_definitions d
        JOIN menu_items m ON m.id=d.menu_item_id AND m.organization_id=d.organization_id
        LEFT JOIN recipes r ON r.id=d.recipe_id AND r.organization_id=d.organization_id
        WHERE d.organization_id=? AND d.public_id=? LIMIT 1");
    $q->execute([$org,$publicId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('AR build definition was not found.');
    $definition=json_decode((string)$row['definition_json'],true)?:[];
    return [
        'id'=>(int)$row['id'],'publicId'=>(string)$row['public_id'],'menuItemId'=>(int)$row['menu_item_id'],'menuItemName'=>(string)$row['menu_item_name'],
        'recipePublicId'=>$row['recipe_public_id'],'recipeName'=>$row['recipe_name'],'version'=>(int)$row['version'],'status'=>(string)$row['status'],
        'sourceHash'=>(string)$row['source_hash'],'definition'=>$definition,'compiledAt'=>$row['compiled_at'],'updatedAt'=>$row['updated_at'],
    ];
}

function glasses_definition_for_menu_item(PDO $pdo,int $org,int $menuItemId): ?array
{
    if(!glasses_definition_ready($pdo))return null;
    $q=$pdo->prepare("SELECT public_id FROM glasses_build_definitions WHERE organization_id=? AND menu_item_id=? AND status='ready' ORDER BY version DESC,id DESC LIMIT 1");
    $q->execute([$org,$menuItemId]);$public=$q->fetchColumn();
    if(!$public)return null;
    $definition=glasses_definition_by_public_id($pdo,$org,(string)$public);
    $source=glasses_definition_recipe($pdo,$org,$menuItemId,(string)$definition['recipePublicId']);
    $currentHash=glasses_definition_source_hash($source['menu'],$source['recipe']);
    $definition['stale']=!hash_equals((string)$definition['sourceHash'],$currentHash);
    return $definition;
}

function glasses_definition_list(PDO $pdo,int $org,?int $menuItemId=null): array
{
    $sql="SELECT public_id FROM glasses_build_definitions WHERE organization_id=?";
    $args=[$org];
    if($menuItemId!==null){$sql.=' AND menu_item_id=?';$args[]=$menuItemId;}
    $sql.=' ORDER BY menu_item_id,version DESC,id DESC';
    $q=$pdo->prepare($sql);$q->execute($args);
    return array_map(static fn(string $public):array=>glasses_definition_by_public_id($pdo,$org,$public),$q->fetchAll(PDO::FETCH_COLUMN));
}
