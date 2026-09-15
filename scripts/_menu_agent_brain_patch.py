from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        raise SystemExit(f"patch target not found: {label}")
    return text.replace(old, new, 1)


p = Path("api/agent-brain.php")
s = p.read_text()

s = replace_once(
    s,
    "require __DIR__ . '/../includes/restaurant-brain.php';",
    "require __DIR__ . '/../includes/restaurant-brain.php';\nrequire __DIR__ . '/../includes/menu-operations-core.php';",
    "menu operations require",
)

needle = """function brain_can_recipes(array $user): bool
{
    return app_has_permission('recipes.agent', $user) && app_has_permission('recipes.view', $user);
}
"""
s = replace_once(
    s,
    needle,
    needle + """function brain_can_menu(array $user): bool
{
    return app_has_permission('menu.view', $user);
}
""",
    "menu permission helper",
)

s = replace_once(
    s,
    "if (!brain_can_equipment($user) && !brain_can_wholesale($user) && !brain_can_recipes($user))",
    "if (!brain_can_menu($user) && !brain_can_equipment($user) && !brain_can_wholesale($user) && !brain_can_recipes($user))",
    "restaurant agent permission gate",
)

menu_functions = r'''
function brain_menu_location(PDO $pdo,int $organizationId,string $message): ?array
{
    if(!restaurant_brain_table_ready($pdo,'locations'))return null;
    $q=$pdo->prepare("SELECT id,name,status,timezone FROM locations WHERE organization_id=? AND status='active' ORDER BY name,id");
    $q->execute([$organizationId]);$rows=$q->fetchAll();
    if(count($rows)===1)return $rows[0];
    $lower=mb_strtolower($message,'UTF-8');
    foreach($rows as $row){$name=mb_strtolower(trim((string)$row['name']),'UTF-8');if($name!==''&&str_contains($lower,$name))return $row;}
    return null;
}

function brain_menu_answer(PDO $pdo,int $organizationId,string $message): array
{
    if(!menu_operations_ready($pdo))return ['skill'=>'menu.summary','answer'=>'Menu Operations is not installed yet. Run Upgrade once to activate live menu availability and Agent Brain context.','data'=>[],'sources'=>[]];
    $normalized=mb_strtolower(trim($message),'UTF-8');$location=brain_menu_location($pdo,$organizationId,$message);$locationId=$location?(int)$location['id']:null;
    if(preg_match('/\b(recent|changed|changes|history|updated|who changed)\b/u',$normalized)){
        $events=menu_operations_recent_events($pdo,$organizationId,20);$lines=[];foreach($events as $event)$lines[]=$event['createdAt'].' — '.$event['summary'];
        return ['skill'=>'menu.recent_changes','answer'=>$events?"Recent menu changes:\n- ".implode("\n- ",$lines):'No Menu Operations changes have been recorded yet.','data'=>$events,'sources'=>array_column($events,'id')];
    }
    if(preg_match('/\b(sold.?out|86(?:d)?|eighty.?six|availability|available|unavailable)\b/u',$normalized)){
        if(!$location)return ['skill'=>'menu.availability','answer'=>'Tell me which restaurant location you mean so I can check its live item and size availability.','data'=>[],'sources'=>[]];
        $state=menu_operations_location_state($pdo,$organizationId,$locationId);$blocked=[];
        foreach($state as $item){
            if(!empty($item['status']['soldOut']))$blocked[]=$item['name'].' — item sold out'.(!empty($item['status']['reason'])?' ('.$item['status']['reason'].')':'');
            foreach($item['sizes'] as $size)if(!empty($size['status']['soldOut']))$blocked[]=$item['name'].' / '.$size['label'].' — sold out'.(!empty($size['status']['reason'])?' ('.$size['status']['reason'].')':'');
        }
        return ['skill'=>'menu.availability','answer'=>$blocked?('Live menu availability at '.$location['name'].":\n- ".implode("\n- ",$blocked)):('No item- or size-level 86s are active at '.$location['name'].'.'),'data'=>$state,'sources'=>['menu_operations-current']];
    }
    if(preg_match('/\b(summary|status|overview|how many|missing image|images)\b/u',$normalized)){
        $summary=menu_operations_summary($pdo,$organizationId,$locationId);$answer='Menu summary: '.$summary['published'].' published, '.$summary['draft'].' draft, '.$summary['paused'].' paused, '.$summary['soldOutItems'].' item-level 86(s), '.$summary['soldOutSizes'].' size-level 86(s), '.$summary['scheduledItems'].' scheduled item(s).';
        $img=$summary['missingImages'];$answer.=' Missing images: '.$img['menuItems']['missing'].' menu items, '.$img['ingredients']['missing'].' ingredients, '.$img['locations']['missing'].' locations.';
        return ['skill'=>'menu.summary','answer'=>$answer,'data'=>$summary,'sources'=>['menu_operations-current']];
    }
    $items=menu_operations_search($pdo,$organizationId,$message,20,$locationId);
    if(!$items)$items=menu_operations_search($pdo,$organizationId,'',20,$locationId);
    $lines=[];foreach($items as $item){$price=$item['minPrice']===null?'no active price':('$'.number_format((float)$item['minPrice'],2).(($item['maxPrice']!==null&&(float)$item['maxPrice']!==(float)$item['minPrice'])?'–$'.number_format((float)$item['maxPrice'],2):''));$lines[]=$item['name'].' — '.$item['category'].' — '.$price.(isset($item['operationalStatus'])&&!empty($item['operationalStatus']['soldOut'])?' — SOLD OUT':'');}
    return ['skill'=>'menu.search','answer'=>$lines?"Matching menu items:\n- ".implode("\n- ",$lines):'No matching menu items were found.','data'=>$items,'sources'=>['menu_operations-current']];
}

'''
s = replace_once(
    s,
    "function brain_answer(PDO $pdo,int $organizationId,string $message,array $user):array\n{",
    menu_functions + "function brain_answer(PDO $pdo,int $organizationId,string $message,array $user):array\n{",
    "menu answer functions",
)

s = replace_once(
    s,
    "if($normalized==='')return ['skill'=>'none','answer'=>'Ask me about wholesale opportunities, recipes, online recipe mappings, equipment, maintenance, service contacts, or floor plans.'",
    "if($normalized==='')return ['skill'=>'none','answer'=>'Ask me about the menu, sold-outs, pricing, recent menu changes, wholesale opportunities, recipes, equipment, maintenance, service contacts, or floor plans.'",
    "empty prompt menu help",
)

s = replace_once(
    s,
    "    $wholesaleIntent=preg_match('/\\b(wholesale|buyer|lead|prospect|sample|quote|private label|foodservice|pipeline|follow.?up|account opportunity)\\b/u',$normalized)===1;",
    "    $menuIntent=preg_match('/\\b(menu|menu item|sold.?out|86(?:d)?|eighty.?six|food item|drink item|menu price|pizza size|modifier|topping|add.?on)\\b/u',$normalized)===1;\n    $wholesaleIntent=preg_match('/\\b(wholesale|buyer|lead|prospect|sample|quote|private label|foodservice|pipeline|follow.?up|account opportunity)\\b/u',$normalized)===1;",
    "menu intent",
)

s = replace_once(
    s,
    "    if($wholesaleIntent&&brain_can_wholesale($user))return brain_wholesale_answer($pdo,$organizationId,$message);",
    "    if($menuIntent&&brain_can_menu($user))return brain_menu_answer($pdo,$organizationId,$message);\n    if($wholesaleIntent&&brain_can_wholesale($user))return brain_wholesale_answer($pdo,$organizationId,$message);",
    "menu natural language routing",
)

s = replace_once(
    s,
    "    if(brain_can_wholesale($user)){ $result=brain_wholesale_answer($pdo,$organizationId,$message); if(!empty($result['data']))return $result; }",
    "    if(brain_can_menu($user)){ $result=brain_menu_answer($pdo,$organizationId,$message); if(!empty($result['data']))return $result; }\n    if(brain_can_wholesale($user)){ $result=brain_wholesale_answer($pdo,$organizationId,$message); if(!empty($result['data']))return $result; }",
    "menu fallback routing",
)

s = replace_once(
    s,
    "        if(brain_can_equipment($user)&&equipment_brain_table_ready($pdo,'equipment_assets'))",
    "        if(brain_can_menu($user)&&menu_operations_ready($pdo)){$payload['domains']['menu']=['summary'=>menu_operations_summary($pdo,$organizationId),'recentChanges'=>menu_operations_recent_events($pdo,$organizationId,8)];}\n        if(brain_can_equipment($user)&&equipment_brain_table_ready($pdo,'equipment_assets'))",
    "menu summary domain",
)

s = replace_once(
    s,
    "    if($action==='recipes'&&brain_can_recipes($user))app_json_response(['ok'=>true,'skill'=>'recipe.search','recipes'=>restaurant_brain_recipe_search($pdo,$organizationId,(string)($_GET['q']??''),30)]);",
    "    if($action==='recipes'&&brain_can_recipes($user))app_json_response(['ok'=>true,'skill'=>'recipe.search','recipes'=>restaurant_brain_recipe_search($pdo,$organizationId,(string)($_GET['q']??''),30)]);\n    if($action==='menu'&&brain_can_menu($user))app_json_response(['ok'=>true,'skill'=>'menu.search','items'=>menu_operations_search($pdo,$organizationId,(string)($_GET['q']??''),30,((int)($_GET['locationId']??0))?:null)]);",
    "menu GET action",
)

s = replace_once(
    s,
    "    if(str_starts_with($skill,'equipment.')&&!brain_can_equipment($user))",
    "    if(str_starts_with($skill,'menu.')&&!brain_can_menu($user))app_json_response(['ok'=>false,'message'=>'Menu view permission required.'],403);\n    if(str_starts_with($skill,'equipment.')&&!brain_can_equipment($user))",
    "menu skill permission",
)

handlers = """    if($skill==='menu.summary')$result=['skill'=>$skill,'data'=>menu_operations_summary($pdo,$organizationId,((int)($args['locationId']??0))?:null)];
    elseif($skill==='menu.search')$result=['skill'=>$skill,'data'=>menu_operations_search($pdo,$organizationId,(string)($args['query']??''),(int)($args['limit']??20),((int)($args['locationId']??0))?:null)];
    elseif($skill==='menu.availability'){ $locationId=(int)($args['locationId']??0); if($locationId<1)throw new InvalidArgumentException('locationId is required.'); $result=['skill'=>$skill,'data'=>menu_operations_location_state($pdo,$organizationId,$locationId)]; }
    elseif($skill==='menu.recent_changes')$result=['skill'=>$skill,'data'=>menu_operations_recent_events($pdo,$organizationId,(int)($args['limit']??20))];
    elseif($skill==='menu.set_item_availability'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>menu_operations_set_item_status($pdo,$organizationId,(int)($args['itemId']??0),(int)($args['locationId']??0),!empty($args['soldOut']),(string)($args['reason']??''),isset($args['resumeAt'])?(string)$args['resumeAt']:null,(int)$user['id'])];}
    elseif($skill==='menu.set_size_availability'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>menu_operations_set_price_status($pdo,$organizationId,(int)($args['priceId']??0),(int)($args['locationId']??0),!empty($args['soldOut']),(string)($args['reason']??''),isset($args['resumeAt'])?(string)$args['resumeAt']:null,(int)$user['id'])];}
    elseif($skill==='menu.update_price'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>menu_operations_update_price($pdo,$organizationId,(int)($args['priceId']??0),(float)($args['amount']??-1),(int)$user['id'])];}
    elseif($skill==='equipment.search')"""
s = replace_once(s, "    if($skill==='equipment.search')", handlers, "menu run_skill handlers")

p.write_text(s)
