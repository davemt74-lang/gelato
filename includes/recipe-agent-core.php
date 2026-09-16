<?php
declare(strict_types=1);

require_once __DIR__.'/restaurant-brain.php';
require_once __DIR__.'/agent-confirmation-core.php';

final class RecipeAgentPermissionException extends RuntimeException {}

function recipe_agent_require_read(array $user): void
{
    if(!app_has_permission('recipes.view',$user)||!app_has_permission('recipes.agent',$user)){
        throw new RecipeAgentPermissionException('Recipe Agent access requires Recipes View and Recipe Agent permissions.');
    }
}

function recipe_agent_require_edit(array $user): void
{
    recipe_agent_require_read($user);
    if(!app_has_permission('recipes.edit',$user))throw new RecipeAgentPermissionException('Recipe changes require Recipes Edit permission.');
}

function recipe_agent_ready(PDO $pdo): bool
{
    return restaurant_brain_table_ready($pdo,'recipes');
}

function recipe_agent_clean_id(mixed $value): string
{
    return preg_replace('/[^A-Za-z0-9_.:-]/','',trim((string)$value))??'';
}

function recipe_agent_recipe_by_public_id(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): ?array
{
    $publicId=recipe_agent_clean_id($publicId);if($publicId==='')return null;
    $sql='SELECT * FROM recipes WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,$publicId]);$row=$q->fetch();return $row?:null;
}

function recipe_agent_context_recipe(PDO $pdo,int $org,array $context): ?array
{
    $id=recipe_agent_clean_id($context['selectedRecipePublicId']??$context['recipePublicId']??'');
    return $id!==''?recipe_agent_recipe_by_public_id($pdo,$org,$id):null;
}

function recipe_agent_find_recipe(PDO $pdo,int $org,string $message,array $context=[]): ?array
{
    $text=mb_strtolower(trim($message),'UTF-8');
    $rows=restaurant_brain_recipe_search($pdo,$org,'',50);$matches=[];
    foreach($rows as $row){
        $name=mb_strtolower(trim((string)$row['name']),'UTF-8');$public=mb_strtolower((string)$row['public_id'],'UTF-8');$score=0;
        if($public!==''&&str_contains($text,$public))$score=200+mb_strlen($public,'UTF-8');
        if($name!==''&&mb_strlen($name,'UTF-8')>=2&&str_contains($text,$name))$score=max($score,100+mb_strlen($name,'UTF-8'));
        if($score>0)$matches[]=['score'=>$score,'row'=>$row];
    }
    if(!$matches)return recipe_agent_context_recipe($pdo,$org,$context);
    usort($matches,static fn(array $a,array $b):int=>$b['score']<=>$a['score']);
    if(count($matches)>1&&$matches[0]['score']===$matches[1]['score']){
        $names=array_slice(array_map(static fn(array $m):string=>(string)$m['row']['name'],$matches),0,5);
        throw new InvalidArgumentException('More than one recipe matches that request: '.implode(', ',$names).'. Select the recipe or name it more specifically.');
    }
    return $matches[0]['row'];
}

function recipe_agent_decode_list(?string $json): array
{
    $rows=json_decode((string)$json,true);return is_array($rows)?array_values($rows):[];
}

function recipe_agent_recipe_data(array $recipe): array
{
    return [
        'publicId'=>(string)$recipe['public_id'],'name'=>(string)$recipe['name'],'category'=>(string)($recipe['category']??''),
        'description'=>(string)($recipe['description']??''),'yieldQuantity'=>$recipe['yield_quantity']!==null?(float)$recipe['yield_quantity']:null,
        'yieldUnit'=>(string)($recipe['yield_unit']??''),'ingredients'=>recipe_agent_decode_list($recipe['ingredients_json']??null),
        'instructions'=>recipe_agent_decode_list($recipe['instructions_json']??null),'notes'=>(string)($recipe['notes']??''),
        'status'=>(string)($recipe['status']??'active'),'mappingStatus'=>(string)($recipe['mapping_status']??'unmapped'),
        'sourceTitle'=>(string)($recipe['source_title']??''),'sourceUrl'=>(string)($recipe['source_url']??''),'updatedAt'=>(string)$recipe['updated_at'],
    ];
}

function recipe_agent_library_summary(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT COUNT(*) total,SUM(status='active') active_count,SUM(status='inactive') inactive_count,SUM(mapping_status='mapped') mapped_count,SUM(mapping_status<>'mapped') unmapped_count FROM recipes WHERE organization_id=? AND archived_at IS NULL");
    $q->execute([$org]);$r=$q->fetch()?:[];
    return ['total'=>(int)($r['total']??0),'active'=>(int)($r['active_count']??0),'inactive'=>(int)($r['inactive_count']??0),'mapped'=>(int)($r['mapped_count']??0),'unmapped'=>(int)($r['unmapped_count']??0)];
}

function recipe_agent_standard_gaps(PDO $pdo,int $org,int $limit=20): array
{
    $limit=max(1,min(50,$limit));
    $q=$pdo->prepare("SELECT public_id,name,category,yield_quantity,yield_unit,ingredients_json,instructions_json,notes,updated_at FROM recipes WHERE organization_id=? AND archived_at IS NULL AND status='active' ORDER BY updated_at DESC LIMIT 200");
    $q->execute([$org]);$out=[];
    foreach($q->fetchAll() as $row){
        $gaps=[];$ingredients=recipe_agent_decode_list($row['ingredients_json']??null);$instructions=recipe_agent_decode_list($row['instructions_json']??null);
        if($row['yield_quantity']===null||(float)$row['yield_quantity']<=0||trim((string)($row['yield_unit']??''))==='')$gaps[]='yield';
        if(!$ingredients)$gaps[]='ingredients';
        if(!$instructions)$gaps[]='instructions';
        if($gaps)$out[]=['publicId'=>(string)$row['public_id'],'name'=>(string)$row['name'],'category'=>(string)($row['category']??''),'gaps'=>$gaps,'updatedAt'=>(string)$row['updated_at']];
        if(count($out)>=$limit)break;
    }
    return $out;
}

function recipe_agent_parse_number(string $value): ?float
{
    $value=trim($value);if($value==='')return null;
    if(is_numeric($value))return (float)$value;
    if(preg_match('/^(\d+)\s+(\d+)\/(\d+)$/',$value,$m)&&((int)$m[3])!==0)return (float)$m[1]+((float)$m[2]/(float)$m[3]);
    if(preg_match('/^(\d+)\/(\d+)$/',$value,$m)&&((int)$m[2])!==0)return (float)$m[1]/(float)$m[2];
    return null;
}

function recipe_agent_format_number(float $value): string
{
    if(abs($value-round($value))<0.00001)return (string)(int)round($value);
    return rtrim(rtrim(number_format($value,3,'.',''),'0'),'.');
}

function recipe_agent_scaled_ingredients(array $recipe,float $target): array
{
    $current=$recipe['yield_quantity']!==null?(float)$recipe['yield_quantity']:0.0;
    if($current<=0)throw new InvalidArgumentException('This recipe does not have a numeric yield yet, so it cannot be scaled safely.');
    if($target<=0||$target>100000)throw new InvalidArgumentException('Choose a target yield greater than zero and no more than 100,000.');
    $factor=$target/$current;$out=[];
    foreach(recipe_agent_decode_list($recipe['ingredients_json']??null) as $item){
        if(!is_array($item)){$out[]=['ingredient'=>(string)$item,'quantity'=>'','unit'=>'','notes'=>'','scaled'=>false];continue;}
        $quantity=trim((string)($item['quantity']??''));$parsed=recipe_agent_parse_number($quantity);
        $out[]=[
            'ingredient'=>(string)($item['ingredient']??$item['name']??''),'quantity'=>$quantity,'unit'=>(string)($item['unit']??''),'notes'=>(string)($item['notes']??''),
            'scaledQuantity'=>$parsed!==null?recipe_agent_format_number($parsed*$factor):$quantity,'scaled'=>$parsed!==null,
        ];
    }
    return ['factor'=>$factor,'currentYield'=>$current,'targetYield'=>$target,'yieldUnit'=>(string)($recipe['yield_unit']??''),'ingredients'=>$out];
}

function recipe_agent_allergen_scan(array $recipe): array
{
    $ingredients=recipe_agent_decode_list($recipe['ingredients_json']??null);
    $haystack=mb_strtolower(json_encode($ingredients,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'','UTF-8');
    $groups=[
        'milk/dairy'=>['milk','cream','butter','cheese','mozzarella','parmesan','ricotta','whey','casein','gelato'],
        'egg'=>['egg','eggs','mayonnaise','meringue'],
        'wheat/gluten'=>['wheat','flour','bread','breadcrumbs','pasta','semolina','farro','barley','rye'],
        'soy'=>['soy','soya','tofu','edamame','miso','tamari'],
        'peanut'=>['peanut','peanuts'],
        'tree nuts'=>['almond','walnut','pecan','pistachio','cashew','hazelnut','macadamia','brazil nut','pine nut'],
        'sesame'=>['sesame','tahini'],
        'fish'=>['anchovy','anchovies','salmon','tuna','cod','fish sauce'],
        'shellfish'=>['shrimp','prawn','crab','lobster','clam','mussel','oyster','scallop'],
    ];
    $hits=[];
    foreach($groups as $group=>$terms){foreach($terms as $term){if(str_contains($haystack,$term)){$hits[]=$group;break;}}}
    return array_values(array_unique($hits));
}

function recipe_agent_ingredient_search(PDO $pdo,int $org,string $needle,int $limit=20): array
{
    $needle=trim($needle);if($needle==='')return [];$limit=max(1,min(50,$limit));$like='%'.$needle.'%';
    $q=$pdo->prepare("SELECT public_id,name,category,yield_quantity,yield_unit,status FROM recipes WHERE organization_id=? AND archived_at IS NULL AND CAST(ingredients_json AS CHAR) LIKE ? ORDER BY name LIMIT {$limit}");
    $q->execute([$org,$like]);return $q->fetchAll();
}

function recipe_agent_propose(PDO $pdo,array $user,array $recipe,string $type,array $payload,string $summary): array
{
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];
    $proposal=gac_pending_store('recipes',$org,$uid,$type,$payload+[
        'recipePublicId'=>(string)$recipe['public_id'],'recipeName'=>(string)$recipe['name'],'expectedUpdatedAt'=>(string)$recipe['updated_at'],
    ],$summary);
    app_audit($pdo,$org,$uid,'recipe.agent_action_proposed','recipe_agent_proposal',(string)$proposal['id'],null,['type'=>$type,'recipePublicId'=>$recipe['public_id']]);
    return gac_proposal_result($proposal,'recipe.action_proposal',['Recipe Library','Recipe + Production Standards Agent']);
}

function recipe_agent_assert_fresh(array $recipe,array $payload): void
{
    if((string)$recipe['updated_at']!==(string)($payload['expectedUpdatedAt']??'')){
        throw new InvalidArgumentException('This recipe changed after the proposal was created. Refresh it and ask again before confirming.');
    }
}

function recipe_agent_execute_pending(PDO $pdo,array $user,array $proposal): array
{
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];
    if((int)($proposal['organizationId']??0)!==$org||(int)($proposal['userId']??0)!==$uid)throw new RecipeAgentPermissionException('This Recipe proposal belongs to another session.');
    $type=(string)($proposal['type']??'');$payload=is_array($proposal['payload']??null)?$proposal['payload']:[];
    recipe_agent_require_edit($user);
    $pdo->beginTransaction();
    try{
        $recipe=recipe_agent_recipe_by_public_id($pdo,$org,(string)($payload['recipePublicId']??''),true);
        if(!$recipe)throw new InvalidArgumentException('The recipe no longer exists.');
        recipe_agent_assert_fresh($recipe,$payload);$previous=[];$new=[];$answer='';
        if($type==='set_yield'){
            $quantity=(float)($payload['yieldQuantity']??0);$unit=mb_substr(trim((string)($payload['yieldUnit']??'')),0,80,'UTF-8');
            if($quantity<=0||$quantity>100000||$unit==='')throw new InvalidArgumentException('The proposed recipe yield is invalid.');
            $previous=['yield_quantity'=>$recipe['yield_quantity'],'yield_unit'=>$recipe['yield_unit']];$new=['yield_quantity'=>$quantity,'yield_unit'=>$unit];
            $pdo->prepare('UPDATE recipes SET yield_quantity=?,yield_unit=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([$quantity,$unit,$uid,(int)$recipe['id'],$org]);
            $answer='Confirmed. '.$recipe['name'].' now yields '.recipe_agent_format_number($quantity).' '.$unit.'.';
        }elseif($type==='set_status'){
            $status=(string)($payload['status']??'');if(!in_array($status,['active','inactive'],true))throw new InvalidArgumentException('Unsupported recipe status.');
            $previous=['status'=>(string)$recipe['status']];$new=['status'=>$status];
            $pdo->prepare('UPDATE recipes SET status=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([$status,$uid,(int)$recipe['id'],$org]);
            $answer='Confirmed. '.$recipe['name'].' is now '.$status.'.';
        }elseif($type==='add_ingredient'){
            $ingredients=recipe_agent_decode_list($recipe['ingredients_json']??null);$ingredient=[
                'quantity'=>mb_substr(trim((string)($payload['quantity']??'')),0,80,'UTF-8'),'unit'=>mb_substr(trim((string)($payload['unit']??'')),0,80,'UTF-8'),
                'ingredient'=>mb_substr(trim((string)($payload['ingredient']??'')),0,300,'UTF-8'),'notes'=>mb_substr(trim((string)($payload['notes']??'')),0,300,'UTF-8'),
            ];
            if($ingredient['ingredient']==='')throw new InvalidArgumentException('The proposed ingredient name is empty.');if(count($ingredients)>=250)throw new InvalidArgumentException('This recipe already has the maximum number of ingredients.');
            $previous=['ingredient_count'=>count($ingredients)];$ingredients[]=$ingredient;$new=['ingredient_count'=>count($ingredients),'added'=>$ingredient];
            $pdo->prepare('UPDATE recipes SET ingredients_json=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([json_encode($ingredients,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$uid,(int)$recipe['id'],$org]);
            $answer='Confirmed. I added '.$ingredient['ingredient'].' to '.$recipe['name'].'.';
        }elseif($type==='add_instruction'){
            $instructions=recipe_agent_decode_list($recipe['instructions_json']??null);$text=mb_substr(trim((string)($payload['instruction']??'')),0,2000,'UTF-8');
            if($text==='')throw new InvalidArgumentException('The proposed instruction is empty.');if(count($instructions)>=250)throw new InvalidArgumentException('This recipe already has the maximum number of instruction steps.');
            $previous=['instruction_count'=>count($instructions)];$instructions[]=$text;$new=['instruction_count'=>count($instructions),'added'=>$text];
            $pdo->prepare('UPDATE recipes SET instructions_json=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([json_encode($instructions,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$uid,(int)$recipe['id'],$org]);
            $answer='Confirmed. I added the new instruction step to '.$recipe['name'].'.';
        }elseif($type==='append_note'){
            $note=mb_substr(trim((string)($payload['note']??'')),0,2000,'UTF-8');if($note==='')throw new InvalidArgumentException('The proposed production note is empty.');
            $existing=trim((string)($recipe['notes']??''));$updated=trim($existing.($existing!==''?"\n":"").$note);if(mb_strlen($updated,'UTF-8')>20000)throw new InvalidArgumentException('Adding that note would exceed the recipe notes limit.');
            $previous=['notes'=>$existing];$new=['notes'=>$updated];$pdo->prepare('UPDATE recipes SET notes=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([$updated,$uid,(int)$recipe['id'],$org]);
            $answer='Confirmed. I appended the production note to '.$recipe['name'].'.';
        }else throw new InvalidArgumentException('The pending Recipe action is no longer supported.');

        restaurant_brain_sync_recipe($pdo,$org,(int)$recipe['id'],$uid);
        app_audit($pdo,$org,$uid,'recipe.agent_action_confirmed','recipe',(string)$recipe['public_id'],$previous,$new+['proposalId'=>$proposal['id'],'actionType'=>$type]);
        $pdo->commit();
        return ['skill'=>'recipe.action_confirmed','answer'=>$answer,'data'=>['recipePublicId'=>$recipe['public_id'],'actionType'=>$type],'sources'=>['Recipe Library','Recipe + Production Standards Agent']];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function recipe_agent_parse_ingredient(string $text,?string $targetRecipeName=null): array
{
    $text=trim($text);
    if($targetRecipeName!==null&&trim($targetRecipeName)!==''){
        $quoted=preg_quote(trim($targetRecipeName),'/');
        $text=preg_replace('/\s+(?:to|into|for)\s+(?:the\s+)?'.$quoted.'(?:\s+recipe)?[.!]?$/iu','',$text)??$text;
        $text=trim($text);
    }
    $quantity='';$unit='';$ingredient=$text;$notes='';
    if(preg_match('/^((?:\d+(?:\.\d+)?)|(?:\d+\s+\d+\/\d+)|(?:\d+\/\d+))\s+(.+)$/u',$text,$m)){
        $quantity=$m[1];$rest=trim($m[2]);
        $units='cups?|tablespoons?|tbsp|teaspoons?|tsp|ounces?|oz|pounds?|lbs?|grams?|g|kilograms?|kg|milliliters?|ml|liters?|litres?|l|each|ea|pinches?|dashes?|cloves?|cans?|packages?|pkg';
        if(preg_match('/^('.$units.')\s+(.+)$/iu',$rest,$u)){$unit=$u[1];$ingredient=trim($u[2]);}else $ingredient=$rest;
    }
    if(preg_match('/^(.*?)(?:\s+\(([^()]*)\))$/u',$ingredient,$m)){$ingredient=trim($m[1]);$notes=trim($m[2]);}
    return ['quantity'=>$quantity,'unit'=>$unit,'ingredient'=>$ingredient,'notes'=>$notes];
}

function recipe_agent_handle(PDO $pdo,array $user,array $input): array
{
    recipe_agent_require_read($user);if(!recipe_agent_ready($pdo))throw new RuntimeException('Recipe data is not installed. Run upgrade.php.');
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];$message=trim((string)($input['message']??''));
    if($message===''||mb_strlen($message,'UTF-8')>2400)throw new InvalidArgumentException('Ask Gelato a recipe question no longer than 2,400 characters.');
    $context=is_array($input['pageContext']??null)?$input['pageContext']:[];$pending=gac_pending_get('recipes',$org,$uid);

    if(gac_is_confirm($message)){
        if(!$pending)throw new InvalidArgumentException('There is no pending Recipe action to confirm.');
        try{$result=recipe_agent_execute_pending($pdo,$user,$pending);}catch(Throwable $e){gac_pending_clear('recipes',$org,$uid);throw $e;}
        gac_pending_clear('recipes',$org,$uid);return ['ok'=>true]+$result;
    }
    if(gac_is_cancel($message)&&$pending){
        gac_pending_clear('recipes',$org,$uid);app_audit($pdo,$org,$uid,'recipe.agent_action_discarded','recipe_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']??null]);
        return ['ok'=>true,'skill'=>'recipe.action_cancelled','answer'=>'Cancelled. I did not change the recipe.','data'=>['cancelledProposal'=>$pending['id']],'sources'=>['Recipe + Production Standards Agent']];
    }

    $lower=mb_strtolower($message,'UTF-8');$recipe=recipe_agent_find_recipe($pdo,$org,$message,$context);

    if(preg_match('/\byield\s+(?:to\s+)?(\d+(?:\.\d+)?)\s+([a-z][a-z0-9 _-]{0,79})\b/u',$lower,$m)&&preg_match('/\b(set|change|update)\b/u',$lower)){
        recipe_agent_require_edit($user);if(!$recipe)throw new InvalidArgumentException('Select or name the recipe before changing its yield.');
        $qty=(float)$m[1];$unit=trim($m[2]);
        $unit=preg_replace('/\s+(?:for|on|to)\s+(?:the\s+)?'.preg_quote(mb_strtolower((string)$recipe['name'],'UTF-8'),'/').'(?:\s+recipe)?[.!]?$/iu','',$unit)??$unit;$unit=trim($unit);
        if($unit==='')throw new InvalidArgumentException('Tell me the yield unit, such as portions, quarts, pans, or batches.');
        return recipe_agent_propose($pdo,$user,$recipe,'set_yield',['yieldQuantity'=>$qty,'yieldUnit'=>$unit],'Proposed change: set '.$recipe['name'].' yield to '.recipe_agent_format_number($qty).' '.$unit.'.');
    }
    if(preg_match('/\b(?:mark|set|make)\b.*\b(active|inactive)\b/u',$lower,$m)){
        recipe_agent_require_edit($user);if(!$recipe)throw new InvalidArgumentException('Select or name the recipe before changing its status.');
        return recipe_agent_propose($pdo,$user,$recipe,'set_status',['status'=>$m[1]],'Proposed change: mark '.$recipe['name'].' '.$m[1].'.');
    }
    if(preg_match('/\badd\s+(?:an?\s+)?ingredient\s*[:\-]?\s*(.+)$/iu',$message,$m)){
        recipe_agent_require_edit($user);if(!$recipe)throw new InvalidArgumentException('Select or name the recipe before adding an ingredient.');$item=recipe_agent_parse_ingredient($m[1],(string)$recipe['name']);
        if(trim((string)$item['ingredient'])==='')throw new InvalidArgumentException('Tell me which ingredient to add.');
        return recipe_agent_propose($pdo,$user,$recipe,'add_ingredient',$item,'Proposed change: add '.trim(implode(' ',array_filter([$item['quantity'],$item['unit'],$item['ingredient']]))).' to '.$recipe['name'].'.');
    }
    if(preg_match('/\badd\s+(?:an?\s+)?(?:instruction|step)\s*[:\-]?\s*(.+)$/iu',$message,$m)){
        recipe_agent_require_edit($user);if(!$recipe)throw new InvalidArgumentException('Select or name the recipe before adding an instruction step.');$instruction=trim($m[1]);$instruction=preg_replace('/\s+(?:to|for)\s+(?:the\s+)?'.preg_quote((string)$recipe['name'],'/').'(?:\s+recipe)?[.!]?$/iu','',$instruction)??$instruction;$instruction=trim($instruction);
        return recipe_agent_propose($pdo,$user,$recipe,'add_instruction',['instruction'=>$instruction],'Proposed change: add this instruction to '.$recipe['name'].': '.$instruction);
    }
    if(preg_match('/\badd\s+(?:a\s+)?(?:production|prep|internal)\s+note\s*[:\-]?\s*(.+)$/iu',$message,$m)){
        recipe_agent_require_edit($user);if(!$recipe)throw new InvalidArgumentException('Select or name the recipe before adding a production note.');$note=trim($m[1]);$note=preg_replace('/\s+(?:to|for)\s+(?:the\s+)?'.preg_quote((string)$recipe['name'],'/').'(?:\s+recipe)?[.!]?$/iu','',$note)??$note;$note=trim($note);
        return recipe_agent_propose($pdo,$user,$recipe,'append_note',['note'=>$note],'Proposed change: append this production note to '.$recipe['name'].': '.$note);
    }

    if(preg_match('/\b(standards? (?:need|needs) attention|recipe standards?|incomplete recipes?|missing (?:yield|ingredients|instructions)|recipe gaps?)\b/u',$lower)){
        $gaps=recipe_agent_standard_gaps($pdo,$org,20);$summary=recipe_agent_library_summary($pdo,$org);
        if(!$gaps)return ['ok'=>true,'skill'=>'recipe.production_standards','answer'=>'All active recipes have a recorded yield, ingredient list, and instructions.','data'=>['summary'=>$summary,'gaps'=>[]],'sources'=>['Recipe Library']];
        $lines=[];foreach($gaps as $gap)$lines[]='- '.$gap['name'].': missing '.implode(', ',$gap['gaps']);
        return ['ok'=>true,'skill'=>'recipe.production_standards','answer'=>'Recipe production standards needing attention:' . "\n" . implode("\n",$lines),'data'=>['summary'=>$summary,'gaps'=>$gaps],'sources'=>['Recipe Library','Recipe + Production Standards Agent']];
    }

    if(preg_match('/\b(?:what|which) recipes? (?:use|contain|have)\s+(.+?)[?.!]*$/u',$lower,$m)){
        $needle=trim($m[1]);$rows=recipe_agent_ingredient_search($pdo,$org,$needle,20);
        $answer=$rows?'Recipes containing “'.$needle.'”: '.implode(', ',array_column($rows,'name')).'.':'I did not find “'.$needle.'” in the recorded recipe ingredient lists.';
        return ['ok'=>true,'skill'=>'recipe.ingredient_search','answer'=>$answer,'data'=>['query'=>$needle,'recipes'=>$rows],'sources'=>['Recipe Library']];
    }

    if(preg_match('/\bscale\b.*?\b(?:to|for)\s+(\d+(?:\.\d+)?)(?:\s+([a-z][a-z0-9_-]{0,39}))?\b/u',$lower,$m)){
        if(!$recipe)throw new InvalidArgumentException('Select or name the recipe you want to scale.');
        $target=(float)$m[1];$requestedUnit=trim((string)($m[2]??''));$recordedUnit=trim((string)($recipe['yield_unit']??''));
        if($requestedUnit!==''&&$recordedUnit!==''&&strcasecmp(rtrim($requestedUnit,'s'),rtrim($recordedUnit,'s'))!==0){
            throw new InvalidArgumentException('This recipe yield is recorded in '.$recordedUnit.'. Scale it using that yield unit, or update the recipe yield first.');
        }
        $scaled=recipe_agent_scaled_ingredients($recipe,$target);
        $lines=[];foreach($scaled['ingredients'] as $item)$lines[]='- '.trim(implode(' ',array_filter([(string)$item['scaledQuantity'],(string)$item['unit'],(string)$item['ingredient']]))).(!$item['scaled']&&$item['quantity']!==''?' (quantity needs manual review)':'');
        return ['ok'=>true,'skill'=>'recipe.scale','answer'=>'Scaled '.$recipe['name'].' from '.recipe_agent_format_number((float)$scaled['currentYield']).' to '.recipe_agent_format_number($target).' '.$scaled['yieldUnit'].' (×'.recipe_agent_format_number((float)$scaled['factor']).'):' . ($lines?"\n".implode("\n",$lines):"\nNo ingredients are recorded yet."),'data'=>['recipe'=>recipe_agent_recipe_data($recipe),'scale'=>$scaled,'handoff'=>['node'=>'prep','label'=>'Prep + Inventory Intelligence','prompt'=>'Use this scaled recipe as production context for the prep plan.']],'sources'=>['Recipe Library','Recipe + Production Standards Agent']];
    }

    if($recipe&&preg_match('/\b(allergen|allergens|allergy|contains? (?:dairy|milk|egg|gluten|wheat|soy|peanut|nuts?|sesame|fish|shellfish))\b/u',$lower)){
        $hits=recipe_agent_allergen_scan($recipe);$answer=$hits?'Recorded ingredients contain keywords associated with: '.implode(', ',$hits).'.':'No common allergen keywords were found in the recorded ingredient names.';
        $answer.=' This is a keyword screen only; verify the actual ingredient labels, cross-contact procedures, and recipe source before serving a guest with an allergy.';
        return ['ok'=>true,'skill'=>'recipe.allergen_screen','answer'=>$answer,'data'=>['recipe'=>recipe_agent_recipe_data($recipe),'keywordHits'=>$hits,'verified'=>false],'sources'=>['Recipe Library']];
    }

    if($recipe){
        $data=recipe_agent_recipe_data($recipe);$ingredients=$data['ingredients'];$instructions=$data['instructions'];$lines=[];
        foreach($ingredients as $item){if(is_array($item))$lines[]='- '.trim(implode(' ',array_filter([(string)($item['quantity']??''),(string)($item['unit']??''),(string)($item['ingredient']??$item['name']??'')])));else $lines[]='- '.trim((string)$item);}
        $steps=[];foreach($instructions as $i=>$step){$text=is_array($step)?(string)($step['text']??''):(string)$step;if(trim($text)!=='')$steps[]=($i+1).'. '.trim($text);}
        $yield=$data['yieldQuantity']!==null?recipe_agent_format_number((float)$data['yieldQuantity']).' '.$data['yieldUnit']:'not recorded';
        $answer=$recipe['name'].' — yield '.$yield.'.';if($lines)$answer.="\nIngredients:\n".implode("\n",$lines);if($steps)$answer.="\nMethod:\n".implode("\n",$steps);if($data['notes']!=='')$answer.="\nProduction notes: ".$data['notes'];
        return ['ok'=>true,'skill'=>'recipe.standard','answer'=>$answer,'data'=>['recipe'=>$data,'handoffs'=>[
            ['node'=>'prep','label'=>'Prep + Inventory Intelligence'],['node'=>'purchasing','label'=>'Purchasing + Inventory'],['node'=>'employee_development','label'=>'Employee Development'],['node'=>'sales_cost','label'=>'Sales Cost Intelligence'],['node'=>'kds','label'=>'Kitchen Display System'],
        ]],'sources'=>['Recipe Library','Recipe + Production Standards Agent']];
    }

    $summary=recipe_agent_library_summary($pdo,$org);$rows=restaurant_brain_recipe_search($pdo,$org,'',12);$names=array_map(static fn(array $r):string=>(string)$r['name'],$rows);
    return ['ok'=>true,'skill'=>'recipe.library_summary','answer'=>'Recipe Library: '.$summary['active'].' active recipe'.($summary['active']===1?'':'s').' out of '.$summary['total'].' total.'.($names?' Recent recipes: '.implode(', ',$names).'.':''),'data'=>['summary'=>$summary,'recentRecipes'=>array_map('recipe_agent_recipe_data',$rows),'standardGaps'=>recipe_agent_standard_gaps($pdo,$org,8)],'sources'=>['Recipe Library','Recipe + Production Standards Agent']];
}
