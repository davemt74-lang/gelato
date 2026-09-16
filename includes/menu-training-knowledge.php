<?php
declare(strict_types=1);

/**
 * Shared staff-facing menu knowledge used by Training and contextual service tools.
 * Allergen output is intentionally conservative: indicators are not safety guarantees.
 */

function menu_training_allergen_knowledge(): array
{
    return [
        'majorAllergens' => [
            ['id'=>'milk','name'=>'Milk','note'=>'Cheese, dairy, cream, butter, milk, and gelato names are direct or likely indicators; verify recipes and cross-contact.'],
            ['id'=>'egg','name'=>'Egg','note'=>'Egg and mayonnaise are direct indicators; dressings, breads, pasta, gelato, and baked goods require verification.'],
            ['id'=>'fish','name'=>'Fish','note'=>'Verify recipes and labels when fish or fish-derived ingredients may be present.'],
            ['id'=>'shellfish','name'=>'Crustacean shellfish','note'=>'Verify current recipes, suppliers, substitutions, and shared preparation areas.'],
            ['id'=>'tree-nuts','name'=>'Tree nuts','note'=>'Almond, pecan, and nut-branded flavors are direct indicators; pesto, spreads, and gelato require verification.'],
            ['id'=>'peanuts','name'=>'Peanuts','note'=>'Peanut and peanut-butter names are direct indicators; verify gelato cross-contact and supplier labels.'],
            ['id'=>'wheat','name'=>'Wheat','note'=>'Pizza dough, bread, panini, pasta, croutons, cookies, cakes, brownies, and similar baked items are indicators.'],
            ['id'=>'soy','name'=>'Soy','note'=>'Sauces, processed foods, chocolate, baked goods, breads, and gelato may contain soy; verify labels.'],
            ['id'=>'sesame','name'=>'Sesame','note'=>'Bread, buns, sauces, dressings, and garnishes may contain sesame; verify labels.'],
        ],
        'directRules' => [
            ['allergen'=>'milk','patterns'=>['milk','cream','gelato','mozzarella','parmesan','cheese','butter','affogato','latte']],
            ['allergen'=>'egg','patterns'=>['egg','mayonnaise','mayo']],
            ['allergen'=>'tree-nuts','patterns'=>['almond','pecan','tree nut']],
            ['allergen'=>'peanuts','patterns'=>['peanut','peanut butter']],
            ['allergen'=>'wheat','patterns'=>['pizza','crust','dough','bread','panini','pasta','spaghetti','rigatoni','crouton','cookie','cake','brownie','biscoff']],
            ['allergen'=>'soy','patterns'=>['soy']],
            ['allergen'=>'sesame','patterns'=>['sesame']],
            ['allergen'=>'fish','patterns'=>['anchovy','tuna','salmon','fish']],
            ['allergen'=>'shellfish','patterns'=>['shrimp','crab','lobster','crustacean']],
        ],
        'verifyRules' => [
            ['allergen'=>'milk','patterns'=>['gelato','chocolate','caramel','dressing','sauce','pesto','meatball','bread','pizza','panini','pasta','coffee']],
            ['allergen'=>'egg','patterns'=>['gelato','dressing','aioli','bread','panini','pasta','cookie','cake','brownie','biscoff']],
            ['allergen'=>'fish','patterns'=>['caesar','dressing']],
            ['allergen'=>'tree-nuts','patterns'=>['pesto','nutella','gelato','dessert','cookie','cake']],
            ['allergen'=>'peanuts','patterns'=>['gelato','dessert','chocolate','candy']],
            ['allergen'=>'wheat','patterns'=>['pizza','panini','pasta','bread','crouton','dessert','cookie','cake','brownie','biscoff']],
            ['allergen'=>'soy','patterns'=>['chocolate','sauce','dressing','bread','pizza','panini','pasta','gelato','dessert']],
            ['allergen'=>'sesame','patterns'=>['bread','panini','dressing','sauce','garnish']],
        ],
        'alwaysVerify' => [
            'Menu names and descriptions do not establish complete ingredient or cross-contact information.',
            'Supplier labels, recipes, and substitutions can change.',
            'Shared ovens, prep surfaces, utensils, equipment, and storage areas require current restaurant verification.',
            'Employees must escalate allergy questions and verify current recipes and labels before making safety statements.',
        ],
    ];
}

function menu_training_normalize_text(string $value): string
{
    $value=mb_strtolower(trim($value),'UTF-8');
    return preg_replace('/\s+/u',' ',$value)??$value;
}

function menu_training_allergen_name(array $knowledge,string $id): string
{
    foreach($knowledge['majorAllergens']??[] as $allergen){
        if((string)($allergen['id']??'')===$id)return (string)($allergen['name']??$id);
    }
    return $id;
}

function menu_training_allergen_profile(array $item): array
{
    $knowledge=menu_training_allergen_knowledge();
    $ingredients=array_values(array_filter(array_map(
        static fn($value):string=>menu_training_normalize_text((string)$value),
        is_array($item['ingredients']??null)?$item['ingredients']:[]
    ),static fn(string $value):bool=>$value!==''));
    $directText=implode(' ',$ingredients);
    $verifyText=menu_training_normalize_text($directText.' '.(string)($item['name']??'').' '.(string)($item['description']??'').' '.(string)($item['sectionName']??''));
    $direct=[];$verify=[];
    foreach($knowledge['directRules'] as $rule){
        foreach($rule['patterns'] as $pattern){
            if($pattern!==''&&str_contains($directText,menu_training_normalize_text((string)$pattern))){$direct[]=(string)$rule['allergen'];break;}
        }
    }
    foreach($knowledge['verifyRules'] as $rule){
        foreach($rule['patterns'] as $pattern){
            if($pattern!==''&&str_contains($verifyText,menu_training_normalize_text((string)$pattern))){$verify[]=(string)$rule['allergen'];break;}
        }
    }
    $direct=array_values(array_unique($direct));
    $verify=array_values(array_diff(array_unique($verify),$direct));
    return [
        'direct'=>array_map(static fn(string $id):array=>['id'=>$id,'name'=>menu_training_allergen_name($knowledge,$id)],$direct),
        'verify'=>array_map(static fn(string $id):array=>['id'=>$id,'name'=>menu_training_allergen_name($knowledge,$id)],$verify),
        'warning'=>'Training reference only. Verify current recipes, supplier labels, substitutions, preparation methods, and cross-contact controls before making an allergy-safety statement.',
    ];
}

function menu_training_decode_metadata(?string $json): array
{
    if(!$json)return [];
    try{$decoded=json_decode($json,true,512,JSON_THROW_ON_ERROR);return is_array($decoded)?$decoded:[];}catch(Throwable){return [];}
}

function menu_training_item(PDO $pdo,int $organizationId,int $menuItemId): ?array
{
    if($menuItemId<1)return null;
    $q=$pdo->prepare("SELECT i.id,i.name,i.slug,i.description,i.preparation_notes,i.behavior_tags_json,
        s.id section_id,s.name section_name,s.slug section_slug,s.description section_note
        FROM menu_items i
        JOIN menu_sections s ON s.id=i.section_id AND s.organization_id=i.organization_id
        WHERE i.organization_id=? AND i.id=? AND i.is_active=1 AND s.status='active' LIMIT 1");
    $q->execute([$organizationId,$menuItemId]);$row=$q->fetch();if(!$row)return null;

    $prices=$pdo->prepare("SELECT id,option_name,size_code,amount,currency,sort_order
        FROM menu_item_prices WHERE menu_item_id=?
          AND (active_from IS NULL OR active_from<=NOW(6)) AND (active_until IS NULL OR active_until>NOW(6))
        ORDER BY sort_order,id");
    $prices->execute([$menuItemId]);
    $ingredients=$pdo->prepare("SELECT COALESCE(mi.display_name,ing.canonical_name) display_name,ing.canonical_name,
        ing.category,ing.verification_status,mi.is_optional,mi.can_remove,mi.sort_order
        FROM menu_item_ingredients mi JOIN ingredients ing ON ing.id=mi.ingredient_id
        WHERE mi.menu_item_id=? ORDER BY mi.sort_order,mi.ingredient_id");
    $ingredients->execute([$menuItemId]);

    $metadata=menu_training_decode_metadata($row['behavior_tags_json']!==null?(string)$row['behavior_tags_json']:null);
    $ingredientRows=$ingredients->fetchAll();
    $item=[
        'id'=>(int)$row['id'],'name'=>(string)$row['name'],'slug'=>(string)$row['slug'],
        'sectionId'=>(int)$row['section_id'],'sectionName'=>(string)$row['section_name'],'sectionSlug'=>(string)$row['section_slug'],
        'description'=>(string)($row['description']??''),'preparationNotes'=>(string)($row['preparation_notes']??''),
        'sectionNote'=>(string)($row['section_note']??''),
        'tags'=>array_values(array_filter(array_map('strval',is_array($metadata['tags']??null)?$metadata['tags']:[]))),
        'featured'=>!empty($metadata['featured']),
        'prices'=>array_map(static fn(array $price):array=>[
            'id'=>(int)$price['id'],'label'=>(string)$price['option_name'],'sizeCode'=>(string)($price['size_code']??''),
            'amount'=>(float)$price['amount'],'currency'=>(string)$price['currency'],
        ],$prices->fetchAll()),
        'ingredients'=>array_map(static fn(array $ingredient):string=>(string)$ingredient['display_name'],$ingredientRows),
        'ingredientDetails'=>array_map(static fn(array $ingredient):array=>[
            'name'=>(string)$ingredient['display_name'],'canonicalName'=>(string)$ingredient['canonical_name'],
            'category'=>(string)($ingredient['category']??''),'verificationStatus'=>(string)($ingredient['verification_status']??''),
            'optional'=>(bool)$ingredient['is_optional'],'canRemove'=>(bool)$ingredient['can_remove'],
        ],$ingredientRows),
    ];
    $item['allergens']=menu_training_allergen_profile($item);
    return $item;
}

function menu_training_items_by_ids(PDO $pdo,int $organizationId,array $ids,int $limit=40): array
{
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $id):bool=>$id>0)));
    $ids=array_slice($ids,0,max(1,min(80,$limit)));
    $result=[];foreach($ids as $id){$item=menu_training_item($pdo,$organizationId,$id);if($item)$result[]=$item;}
    return $result;
}

function menu_training_query_terms(string $query): array
{
    $normalized=menu_training_normalize_text($query);
    $normalized=str_replace(["’","'"],' ',$normalized);
    $tokens=preg_split('/[^\pL\pN-]+/u',$normalized)?:[];
    $stop=array_flip([
        'a','an','and','are','at','be','can','could','do','does','for','from','give','has','have','how','i','in','is','it','list','me','of','on','or','please','show','tell','that','the','this','to','what','which','with','would',
        'allergen','allergens','allergy','allergies','contain','contains','ingredient','ingredients','made','price','prices','cost','much','size','sizes','option','options','prep','prepare','preparation','cook','cooking','notes','menu','item','items'
    ]);
    $terms=[];
    foreach($tokens as $token){
        $token=trim($token,'-');
        if($token===''||mb_strlen($token,'UTF-8')<2||isset($stop[$token]))continue;
        $terms[$token]=true;
        if(count($terms)>=8)break;
    }
    return array_keys($terms);
}

function menu_training_search_items(PDO $pdo,int $organizationId,string $query,int $limit=12): array
{
    $query=trim($query);if($query==='')return [];$limit=max(1,min(30,$limit));
    $terms=menu_training_query_terms($query);
    if(!$terms)$terms=[menu_training_normalize_text($query)];
    $clauses=[];$args=[$organizationId];
    foreach($terms as $term){
        $like='%'.$term.'%';
        $clauses[]='(LOWER(i.name) LIKE ? OR LOWER(COALESCE(i.description,\'\')) LIKE ? OR LOWER(COALESCE(i.preparation_notes,\'\')) LIKE ? OR LOWER(s.name) LIKE ? OR LOWER(COALESCE(mi.display_name,ing.canonical_name,\'\')) LIKE ?)';
        array_push($args,$like,$like,$like,$like,$like);
    }
    $q=$pdo->prepare("SELECT DISTINCT i.id
        FROM menu_items i
        JOIN menu_sections s ON s.id=i.section_id AND s.organization_id=i.organization_id
        LEFT JOIN menu_item_ingredients mi ON mi.menu_item_id=i.id
        LEFT JOIN ingredients ing ON ing.id=mi.ingredient_id
        WHERE i.organization_id=? AND i.is_active=1 AND s.status='active' AND ".implode(' AND ',$clauses)."
        ORDER BY i.name LIMIT {$limit}");
    $q->execute($args);
    return menu_training_items_by_ids($pdo,$organizationId,array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)),$limit);
}