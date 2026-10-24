<?php
declare(strict_types=1);

/**
 * FM-B8D5: server-grounded menu and recipe discovery. Provider-selected
 * arguments never contain database ids, SQL, URLs or raw file paths. Answers
 * are terminal verified observations; protected recipe contents remain on
 * Gelato's server rather than becoming a second model request.
 */
require_once __DIR__.'/menu-manager-core.php';
require_once __DIR__.'/recipe-agent-core.php';

function fm8d5_named_match(array $rows,string $raw):array
{
    $query=mb_strtolower(trim(preg_replace('/\s+/u',' ',$raw)??$raw),'UTF-8');
    if(mb_strlen($query,'UTF-8')<2||mb_strlen($query,'UTF-8')>140)
        return ['error'=>'Please give me the menu item or recipe name.'];
    $exact=[];$loose=[];
    foreach($rows as $row){
        $name=mb_strtolower(trim((string)($row['name']??'')),'UTF-8');
        if($name==='')continue;
        if($name===$query)$exact[]=$row;
        elseif(str_contains($name,$query))$loose[]=$row;
    }
    $matches=$exact?:$loose;
    if(count($matches)===1)return ['item'=>$matches[0]];
    if(!$matches)return ['error'=>'No matching recorded item was found under that name. Check the spelling or ask me to show the available items.'];
    $names=array_slice(array_unique(array_map(static fn(array $r):string=>(string)$r['name'],$matches)),0,8);
    return ['error'=>'Several records match that name: '.implode(', ',$names).'. Which one did you mean?'];
}
function fm8d5_menu_find(PDO $pdo,int $org,string $name):array
{
    if(!menu_manager_ready($pdo))return ['error'=>'The Menu Manager database is not installed or upgraded.'];
    $name=trim($name);
    if(mb_strlen($name,'UTF-8')<2||mb_strlen($name,'UTF-8')>140)
        return ['error'=>'Tell me the menu item name, please.'];
    $q=$pdo->prepare('SELECT id,name FROM menu_items WHERE organization_id=? AND name LIKE ? ORDER BY name,id LIMIT 35');
    $q->execute([$org,'%'.$name.'%']);
    $picked=fm8d5_named_match($q->fetchAll(PDO::FETCH_ASSOC),$name);
    if(!isset($picked['item']))return $picked;
    return ['item'=>menu_manager_item_payload($pdo,$org,(int)$picked['item']['id'])];
}
function fm8d5_recipe_find(PDO $pdo,int $org,string $name):array
{
    if(!restaurant_brain_table_ready($pdo,'recipes'))return ['error'=>'The Recipe Library database is not installed or upgraded.'];
    $name=trim($name);
    if(mb_strlen($name,'UTF-8')<2||mb_strlen($name,'UTF-8')>140)
        return ['error'=>'Tell me which recipe you want.'];
    $q=$pdo->prepare('SELECT * FROM recipes WHERE organization_id=? AND archived_at IS NULL AND name LIKE ? ORDER BY name,id LIMIT 35');
    $q->execute([$org,'%'.$name.'%']);
    return fm8d5_named_match($q->fetchAll(PDO::FETCH_ASSOC),$name);
}
function fm8d5_ingredient_line(array $row):string
{
    $quantity=trim((string)($row['quantity']??''));
    $unit=trim((string)($row['unit']??''));
    $name=trim((string)($row['ingredient']??$row['name']??''));
    $notes=trim((string)($row['notes']??''));
    return trim(implode(' ',array_filter([$quantity,$unit,$name]))).
        ($notes!==''?' ('.$notes.')':'');
}
function fm8d5_summarize_menu(array $item,bool $includePrices=false):string
{
    $name=(string)$item['name'];
    $parts=[$name.' — '.($item['categoryName']??'Menu item')];
    $description=trim((string)($item['description']??''));
    if($description!=='')$parts[]='Description: '.$description;
    $ingredients=array_map(static fn(array $r):string=>(string)$r['name'],(array)($item['ingredients']??[]));
    $parts[]=$ingredients?'Recorded ingredients: '.implode(', ',$ingredients).'.':
        'No ingredient list is recorded for this item. Do not treat the description as a verified complete ingredient list.';
    if($includePrices){
        $prices=array_map(static fn(array $r):string=>trim((string)($r['label']??'Size')).' $'.number_format((float)($r['amount']??0),2),
            (array)($item['sizes']??[]));
        $parts[]=$prices?'Recorded prices: '.implode('; ',$prices).'.':'No prices recorded for this item.';
    }
    $parts[]='For allergens or dietary restrictions, verify current recipe, supplier labels and cross-contact before advising a guest.';
    return implode("\n",$parts);
}
function fm8d5_summarize_recipe(array $row,bool $method=false):string
{
    $name=(string)$row['name'];
    $ingredients=recipe_agent_decode_list($row['ingredients_json']??null);
    $steps=recipe_agent_decode_list($row['instructions_json']??null);
    $yield=trim((string)($row['yield_quantity']??'').' '.(string)($row['yield_unit']??''));
    $result=[$name.' — recorded Gelato recipe'.($yield!==''?' (yield: '.$yield.')':'').'.'];
    $result[]=$ingredients?'Ingredients: '.implode('; ',array_map('fm8d5_ingredient_line',$ingredients)).'.':
        'No structured ingredients are recorded for this recipe.';
    if($method)$result[]=$steps?'Method: '.implode(' ',array_map('strval',$steps)):
        'No preparation steps are recorded.';
    $notes=trim((string)($row['notes']??''));
    if($method&&$notes!=='')$result[]='Production notes: '.$notes;
    $result[]='Ingredient and allergen information should be checked against the current recipe and supplier labels.';
    return implode("\n",$result);
}
function fm8d5_observation(string $message,string $source):array
{
    return ['ok'=>true,'data'=>['answer'=>mb_substr($message,0,2900,'UTF-8'),'source'=>$source]];
}
function fm8d5_catalogue(array $principal):array
{
    $tools=[];
    if(app_has_permission('menu.view',$principal)){
        $tools['get_menu_item']=[
            'description'=>'Look up a live Gelato menu item by name and return its recorded ingredients, description, optionally price and sizes. Use for questions like "what ingredients are on Testarosa Pizza?", "what is on this pizza?", "does a menu item contain cheese?", or "what does this cost?" Never infer missing ingredients from the description; a specific menu item name is required.',
            'parameters'=>['type'=>'object','properties'=>[
                'item_name'=>['type'=>'string','description'=>'Menu item name'],
                'include_prices'=>['type'=>'boolean','description'=>'Include current recorded sizes and prices'],
            ],'required'=>['item_name'],'additionalProperties'=>false],
            'allowedArguments'=>['item_name','include_prices'],'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>app_has_permission('menu.view',$actor),
            'validate'=>static fn(array $args,array $actor):array=>
                trim((string)($args['item_name']??''))===''?['item_name']:[],
            'execute'=>static function(array $args,array $actor):array{
                $r=fm8d5_menu_find(app_pdo(),(int)$actor['organization_id'],(string)$args['item_name']);
                return fm8d5_observation(isset($r['item'])?
                    fm8d5_summarize_menu($r['item'],($args['include_prices']??false)===true):
                    (string)($r['error']??'No verified menu item was returned.'),'Gelato Menu Manager');
            },
        ];
        $tools['search_menu_ingredients']=[
            'description'=>'Search the verified menu ingredient catalogue for a named ingredient, category, or supplier label. This is not an allergen clearance check.',
            'parameters'=>['type'=>'object','properties'=>['ingredient_name'=>['type'=>'string']],
                'required'=>['ingredient_name'],'additionalProperties'=>false],
            'allowedArguments'=>['ingredient_name'],'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>app_has_permission('menu.view',$actor),
            'validate'=>static fn(array $args,array $actor):array=>
                trim((string)($args['ingredient_name']??''))===''?['ingredient_name']:[],
            'execute'=>static function(array $args,array $actor):array{
                $pdo=app_pdo();if(!menu_manager_ready($pdo))return fm8d5_observation(
                    'Menu Manager is not installed. Run upgrade.php.','Gelato Menu Manager');
                $rows=menu_manager_ingredient_search($pdo,(int)$actor['organization_id'],
                    (string)$args['ingredient_name'],30);
                return fm8d5_observation($rows?'Recorded ingredients: '.implode(', ',
                    array_map(static fn(array $r):string=>(string)$r['name'],$rows)).'.':
                    'No matching ingredient is recorded in the menu ingredient catalogue.','Gelato Menu Manager');
            },
        ];
    }
    if(app_has_permission('recipes.view',$principal)){
        $tools['get_recipe_details']=[
            'description'=>'Read a verified Gelato Recipe Library recipe by name, including exact recorded ingredient quantities, yield, and optionally the method and production notes. Use for actual recipe details, quantities, and how-to questions about a named in-house recipe. Do not invent missing recipe facts.',
            'parameters'=>['type'=>'object','properties'=>[
                'recipe_name'=>['type'=>'string','description'=>'Exact or distinctive recipe name'],
                'include_method'=>['type'=>'boolean','description'=>'Include preparation steps and notes'],
            ],'required'=>['recipe_name'],'additionalProperties'=>false],
            'allowedArguments'=>['recipe_name','include_method'],'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>app_has_permission('recipes.view',$actor),
            'validate'=>static fn(array $args,array $actor):array=>
                trim((string)($args['recipe_name']??''))===''?['recipe_name']:[],
            'execute'=>static function(array $args,array $actor):array{
                $found=fm8d5_recipe_find(app_pdo(),(int)$actor['organization_id'],(string)$args['recipe_name']);
                return fm8d5_observation(isset($found['item'])?
                    fm8d5_summarize_recipe($found['item'],($args['include_method']??false)===true):
                    (string)($found['error']??'No verified recipe returned.'),'Gelato Recipe Library');
            },
        ];
        $tools['find_recipes_using_ingredient']=[
            'description'=>'List verified in-house recipes containing a specified ingredient. For example: Which recipes use honey? Returns recipe names, not guessed nutrition values.',
            'parameters'=>['type'=>'object','properties'=>['ingredient_name'=>['type'=>'string']],
                'required'=>['ingredient_name'],'additionalProperties'=>false],
            'allowedArguments'=>['ingredient_name'],'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>app_has_permission('recipes.view',$actor),
            'validate'=>static fn(array $args,array $actor):array=>
                trim((string)($args['ingredient_name']??''))===''?['ingredient_name']:[],
            'execute'=>static function(array $args,array $actor):array{
                $pdo=app_pdo();
                if(!restaurant_brain_table_ready($pdo,'recipes'))return fm8d5_observation(
                    'Recipe Library is not installed. Run upgrade.php.','Gelato Recipe Library');
                $needle=trim((string)$args['ingredient_name']);
                if(mb_strlen($needle,'UTF-8')<2||mb_strlen($needle,'UTF-8')>100)
                    return fm8d5_observation('Please provide a specific ingredient name.','Gelato Recipe Library');
                $rows=recipe_agent_ingredient_search($pdo,(int)$actor['organization_id'],$needle,30);
                return fm8d5_observation($rows?'Recorded recipes mentioning '.$needle.': '.
                    implode(', ',array_map(static fn(array $r):string=>(string)$r['name'],$rows)).'.':
                    'No recipes in the Recipe Library currently mention '.$needle.' in their ingredient lists.','Gelato Recipe Library');
            },
        ];
    }
    return $tools;
}
