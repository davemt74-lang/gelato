<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/customer-crm-core.php';
require_once __DIR__.'/../includes/customer-inbox-core.php';
require_once __DIR__.'/../includes/menu-training-knowledge.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$uid=(int)$user['id'];

if(!app_has_permission('pos.use',$user))app_json_response(['ok'=>false,'message'=>'Native POS permission required.'],403);
if(!pos_ready($pdo))app_json_response(['ok'=>false,'message'=>'Native POS migration is not installed. Run upgrade.php.'],503);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}

$input=app_json_input();
app_verify_request_csrf($input);
if((string)($input['action']??'ask')!=='ask')app_json_response(['ok'=>false,'message'=>'Unsupported POS Agent action.'],422);
$message=trim((string)($input['message']??''));
if($message===''||mb_strlen($message,'UTF-8')>1600)app_json_response(['ok'=>false,'message'=>'Enter a POS question no longer than 1,600 characters.'],422);
$pageContext=is_array($input['pageContext']??null)?$input['pageContext']:[];
if(($pageContext['module']??'')!=='pos')app_json_response(['ok'=>false,'message'=>'POS Agent context is required for this skill.'],422);

function pos_agent_clean_id(mixed $value,int $max=120): string
{
    $value=preg_replace('/[^A-Za-z0-9_.:-]/','',trim((string)$value))??'';
    return mb_substr($value,0,$max,'UTF-8');
}
function pos_agent_location_allowed(PDO $pdo,array $user,int $locationId): bool
{
    return $locationId>0&&operational_location_allowed($pdo,$user,'pos.use',$locationId);
}
function pos_agent_can_customer_history(array $user): bool
{
    return app_has_permission('crm.view',$user);
}
function pos_agent_can_customer_offers(PDO $pdo,array $user,int $locationId): bool
{
    if(!crm_ready($pdo))return false;
    if(app_has_permission('crm.view',$user))return true;
    return app_has_permission('crm.pos_link',$user)&&operational_location_allowed($pdo,$user,'crm.pos_link',$locationId);
}
function pos_agent_money(float $value): string {return '$'.number_format($value,2);}
function pos_agent_cart_item_ids(array $check): array
{
    $ids=[];
    foreach($check['items']??[] as $line){
        if((string)($line['status']??'active')!=='active')continue;
        $id=(int)($line['menu_item_id']??0);if($id>0)$ids[]=$id;
    }
    return array_values(array_unique($ids));
}
function pos_agent_lines_for_menu_item(array $check,int $menuItemId): array
{
    return array_values(array_filter($check['items']??[],static fn(array $line):bool=>(string)($line['status']??'active')==='active'&&(int)($line['menu_item_id']??0)===$menuItemId));
}
function pos_agent_pick_items(array $knowledge,array $check,string $message,int $focusedLineId=0): array
{
    $lower=mb_strtolower($message,'UTF-8');$matches=[];
    foreach($knowledge as $item){$name=mb_strtolower((string)$item['name'],'UTF-8');if($name!==''&&str_contains($lower,$name))$matches[]=$item;}
    if($matches)return $matches;
    if($focusedLineId>0){
        foreach($check['items']??[] as $line){
            if((int)$line['id']!==$focusedLineId||(string)($line['status']??'active')!=='active')continue;
            $menuItemId=(int)($line['menu_item_id']??0);
            foreach($knowledge as $item)if((int)$item['id']===$menuItemId)return [$item];
        }
    }
    return $knowledge;
}
function pos_agent_item_sources(array $items): array
{
    return array_values(array_unique(array_map(static fn(array $item):string=>'Menu Knowledge → '.(string)$item['name'],$items)));
}
function pos_agent_item_price_text(array $item): string
{
    $prices=[];foreach($item['prices']??[] as $price)$prices[]=(string)$price['label'].' '.pos_agent_money((float)$price['amount']);
    return $prices?implode(' · ',$prices):'No active price recorded';
}
function pos_agent_item_lines_text(array $check,array $item): string
{
    $notes=[];
    foreach(pos_agent_lines_for_menu_item($check,(int)$item['id']) as $line){
        $text=trim((string)($line['option_name_snapshot']??''));
        $guest=trim((string)($line['special_instructions']??''));if($guest!=='')$text.=($text!==''?' — ':'').'guest note: '.$guest;
        if($text!=='')$notes[]=$text;
    }
    return implode(' | ',$notes);
}
function pos_agent_allergen_answer(array $items,array $check): array
{
    $lines=[];
    foreach($items as $item){
        $profile=$item['allergens']??['direct'=>[],'verify'=>[]];
        $direct=array_map(static fn(array $a):string=>(string)$a['name'],$profile['direct']??[]);
        $verify=array_map(static fn(array $a):string=>(string)$a['name'],$profile['verify']??[]);
        $text=(string)$item['name'].': direct menu indicators '.($direct?implode(', ',$direct):'none identified from the recorded ingredient names').'; verify '.($verify?implode(', ',$verify):'the current recipe, supplier labels, substitutions, and cross-contact controls').'.';
        $guest=pos_agent_item_lines_text($check,$item);if($guest!=='')$text.=' Current check: '.$guest.'.';
        $lines[]=$text;
    }
    $answer=$lines?"Allergen training profile:\n- ".implode("\n- ",$lines):'No menu item matched that allergen question.';
    $answer.="\n\nSafety boundary: these are training indicators, not an allergy-safety guarantee. Verify current recipes, supplier labels, substitutions, preparation methods, and shared-equipment/cross-contact controls with a manager before telling a guest an item is safe.";
    return ['answer'=>$answer,'sources'=>array_values(array_unique([...pos_agent_item_sources($items),'Allergens & Menu Tags','Menu Notes → allergy verification boundary']))];
}
function pos_agent_ingredient_answer(array $items,array $check): array
{
    $lines=[];
    foreach($items as $item){
        $ingredients=$item['ingredients']??[];$text=(string)$item['name'].': '.($ingredients?implode(', ',$ingredients):'no complete ingredient list is recorded in the current training data');
        $guest=pos_agent_item_lines_text($check,$item);if($guest!=='')$text.='. Current check: '.$guest;$lines[]=$text;
    }
    return ['answer'=>$lines?"Recorded Ingredient Catalog entries:\n- ".implode("\n- ",$lines):'I could not match that question to the Ingredient Catalog.','sources'=>array_values(array_unique([...pos_agent_item_sources($items),'Ingredient Catalog']))];
}
function pos_agent_prep_answer(array $items,array $check): array
{
    $lines=[];
    foreach($items as $item){
        $parts=[];$prep=trim((string)($item['preparationNotes']??''));$section=trim((string)($item['sectionNote']??''));
        if($prep!=='')$parts[]='prep note: '.$prep;if($section!=='')$parts[]='service/menu note: '.$section;
        $guest=pos_agent_item_lines_text($check,$item);if($guest!=='')$parts[]='current check: '.$guest;
        if(!$parts)$parts[]='no item-specific cooking/preparation note is documented in the current training data';
        $lines[]=(string)$item['name'].': '.implode(' · ',$parts);
    }
    $answer=$lines?"Current Menu Notes / preparation guidance:\n- ".implode("\n- ",$lines):'I could not match that question to current menu training knowledge.';
    $answer.="\n\nIf a cook time, temperature, substitution, or recipe step is not documented here, do not guess—use the current recipe/manager procedure.";
    return ['answer'=>$answer,'sources'=>array_values(array_unique([...pos_agent_item_sources($items),'Menu Notes']))];
}
function pos_agent_price_answer(array $items,array $check): array
{
    $lines=[];foreach($items as $item){$text=(string)$item['name'].': '.pos_agent_item_price_text($item);$guest=pos_agent_item_lines_text($check,$item);if($guest!=='')$text.=' · current check '.$guest;$lines[]=$text;}
    return ['answer'=>$lines?"Current menu prices/options:\n- ".implode("\n- ",$lines):'I could not match a menu item to that price question.','sources'=>pos_agent_item_sources($items)];
}
function pos_agent_check_answer(array $check): array
{
    $lines=[];
    foreach($check['items']??[] as $line){
        if((string)($line['status']??'active')!=='active')continue;
        $entry=rtrim(rtrim(number_format((float)$line['quantity'],2,'.',''),'0'),'.').'× '.(string)$line['item_name_snapshot'];
        if(trim((string)($line['option_name_snapshot']??''))!=='')$entry.=' — '.(string)$line['option_name_snapshot'];
        if(trim((string)($line['special_instructions']??''))!=='')$entry.=' — note: '.trim((string)$line['special_instructions']);$lines[]=$entry;
    }
    $customer=$check['customer']['displayName']??null;
    $answer=(string)$check['checkNumber'].' · '.str_replace('_',' ',(string)$check['serviceMode']).' · '.(int)$check['guestCount'].' guest'.((int)$check['guestCount']===1?'':'s').'. Customer: '.($customer?(string)$customer:'walk-in / no CRM customer attached').'.';
    $answer.=$lines?"\nCurrent cart:\n- ".implode("\n- ",$lines):"\nThe check has no active menu items yet.";
    $answer.="\nSubtotal ".pos_agent_money((float)$check['subtotal']).' · Total '.pos_agent_money((float)$check['totalAmount']).' · Balance due '.pos_agent_money((float)$check['balanceDue']).'.';
    return ['answer'=>$answer,'sources'=>['POS check → '.(string)$check['checkNumber']]];
}
function pos_agent_customer_context(PDO $pdo,int $org,array $user,array $check): ?array
{
    $customer=$check['customer']??null;if(!$customer||empty($customer['publicId']))return null;
    $locationId=(int)$check['locationId'];$historyAllowed=pos_agent_can_customer_history($user);$offersAllowed=pos_agent_can_customer_offers($pdo,$user,$locationId);
    $context=['identity'=>['publicId'=>(string)$customer['publicId'],'displayName'=>(string)$customer['displayName']],'historyAllowed'=>$historyAllowed,'offersAllowed'=>$offersAllowed,'metrics'=>null,'promotions'=>[]];
    if(!$historyAllowed&&!$offersAllowed)return $context;
    $row=crm_customer_row($pdo,$org,(string)$customer['publicId']);$customerId=(int)$row['id'];
    if($historyAllowed){
        $metrics=crm_metrics($pdo,$org,$customerId);
        $context['metrics']=['visits'=>(int)$metrics['visits'],'lifetimeSpend'=>(float)$metrics['lifetimeSpend'],'averageCheck'=>(float)$metrics['averageCheck'],'firstVisit'=>$metrics['firstVisit'],'lastVisit'=>$metrics['lastVisit'],'recentChecks'=>array_slice($metrics['recentChecks']??[],0,5),'favoriteItems'=>array_slice($metrics['favoriteItems']??[],0,8)];
    }
    if($offersAllowed&&customer_inbox_ready($pdo)){
        foreach(customer_inbox_messages($pdo,$org,$customerId,40) as $inboxMessage){
            if(!in_array((string)$inboxMessage['type'],['promotion','reward'],true))continue;
            $locationName=trim((string)($inboxMessage['locationName']??''));if($locationName!==''&&$locationName!==(string)$check['locationName'])continue;
            $context['promotions'][]=$inboxMessage;if(count($context['promotions'])>=12)break;
        }
    }
    return $context;
}
function pos_agent_customer_answer(?array $context): array
{
    if(!$context)return ['answer'=>'This check is currently a walk-in / unlinked guest, so there is no repeat-customer history to display.','sources'=>['POS check']];
    $name=(string)($context['identity']['displayName']??'Customer');
    if(empty($context['historyAllowed']))return ['answer'=>$name.' is attached to this check. Repeat-order history, favorites, average check, and lifetime spend require CRM view permission.','sources'=>['POS customer link']];
    $m=$context['metrics']??[];$visits=(int)($m['visits']??0);
    $answer=$name.' · '.($visits>1?'repeat customer':($visits===1?'one completed prior visit':'no completed prior visit yet')).'. '.$visits.' paid visit'.($visits===1?'':'s').' · average check '.pos_agent_money((float)($m['averageCheck']??0)).' · lifetime spend '.pos_agent_money((float)($m['lifetimeSpend']??0)).'.';
    if(!empty($m['lastVisit']))$answer.=' Last visit: '.(string)$m['lastVisit'].'.';
    $favorites=$m['favoriteItems']??[];if($favorites)$answer.="\nFrequent items: ".implode(', ',array_map(static fn(array $item):string=>(string)$item['itemName'].' ('.rtrim(rtrim(number_format((float)$item['quantity'],2,'.',''),'0'),'.').' ordered)',$favorites));
    return ['answer'=>$answer,'sources'=>['Customer CRM → repeat-order metrics']];
}
function pos_agent_recent_orders_answer(?array $context): array
{
    if(!$context)return ['answer'=>'No CRM customer is attached to this check, so there is no customer order history to show.','sources'=>['POS check']];
    if(empty($context['historyAllowed']))return ['answer'=>'A customer is attached, but order history requires CRM view permission.','sources'=>['POS customer link']];
    $rows=$context['metrics']['recentChecks']??[];if(!$rows)return ['answer'=>'This customer does not have a completed paid POS order in the current CRM history yet.','sources'=>['Customer CRM']];
    $lines=[];foreach($rows as $row)$lines[]=(string)$row['checkNumber'].' · '.(string)$row['businessDate'].' · '.str_replace('_',' ',(string)$row['serviceMode']).' · '.pos_agent_money((float)$row['totalAmount']).' · '.(string)$row['locationName'];
    return ['answer'=>"Recent customer orders:\n- ".implode("\n- ",$lines),'sources'=>['Customer CRM → recent paid orders']];
}
function pos_agent_promotions_answer(?array $context): array
{
    if(!$context)return ['answer'=>'No CRM customer is attached to this check, so customer-specific promotions or rewards cannot be matched yet.','sources'=>['POS check']];
    if(empty($context['offersAllowed']))return ['answer'=>'A customer is attached, but this staff account does not have CRM customer-link or view access for customer-specific offers.','sources'=>['POS customer link']];
    $rows=$context['promotions']??[];if(!$rows)return ['answer'=>'There are no active, undismissed customer Inbox promotions or rewards for this customer at this location right now.','sources'=>['Customer Inbox Promotions']];
    $lines=[];foreach($rows as $row){$entry=(string)$row['title'];if(!empty($row['promoCode']))$entry.=' · code '.(string)$row['promoCode'];if(!empty($row['expiresAt']))$entry.=' · expires '.(string)$row['expiresAt'];if(!empty($row['previewText']))$entry.=' · '.(string)$row['previewText'];$lines[]=$entry;}
    return ['answer'=>"Available customer promotions / rewards:\n- ".implode("\n- ",$lines),'sources'=>['Customer Inbox Promotions']];
}

try{
    $checkPublicId=pos_agent_clean_id($pageContext['checkPublicId']??'');$check=null;$locationId=max(0,(int)($pageContext['locationId']??0));
    if($checkPublicId!==''){
        $base=pos_check_base($pdo,$org,$checkPublicId,false);$locationId=(int)$base['location_id'];
        if(!pos_agent_location_allowed($pdo,$user,$locationId))throw new DomainException('POS access is not assigned for this check location.');
        $check=pos_check_details($pdo,$org,$checkPublicId);$check['customer']=crm_ready($pdo)?crm_check_customer($pdo,$org,$checkPublicId):null;
    }elseif($locationId>0){pos_location($pdo,$org,$locationId);if(!pos_agent_location_allowed($pdo,$user,$locationId))throw new DomainException('POS access is not assigned for this restaurant location.');}

    $cartKnowledge=$check?menu_training_items_by_ids($pdo,$org,pos_agent_cart_item_ids($check),40):[];
    $focusedLineId=max(0,(int)($pageContext['focusedLineId']??0));$selected=$check?pos_agent_pick_items($cartKnowledge,$check,$message,$focusedLineId):[];
    if(!$selected)$selected=menu_training_search_items($pdo,$org,$message,12);
    $lower=mb_strtolower($message,'UTF-8');$customerContext=$check?pos_agent_customer_context($pdo,$org,$user,$check):null;

    if(preg_match('/\b(promotion|promotions|promo|reward|rewards|offer|offers|coupon|coupons|deal|deals|available discount)\b/u',$lower))$result=pos_agent_promotions_answer($customerContext);
    elseif(preg_match('/\b(recent orders?|last orders?|last order|order history|previous orders?|what .* ordered|what did .* order)\b/u',$lower))$result=pos_agent_recent_orders_answer($customerContext);
    elseif(preg_match('/\b(customer|guest|repeat customer|regular|profile|favorite|favourite|usual|typically orders?|normally orders?|how often)\b/u',$lower))$result=pos_agent_customer_answer($customerContext);
    elseif(preg_match('/\b(allergen|allergens|allergy|allergies|gluten|dairy|milk|egg|nuts?|peanut|wheat|soy|sesame|shellfish|fish)\b/u',$lower))$result=pos_agent_allergen_answer($selected,$check??['items'=>[]]);
    elseif(preg_match('/\b(ingredient|ingredients|what(?:\x27s| is) in|made with|contain|contains|component|components)\b/u',$lower))$result=pos_agent_ingredient_answer($selected,$check??['items'=>[]]);
    elseif(preg_match('/\b(cook|cooking|prep|prepare|preparation|oven|temperature|method|instructions|special notes?|menu notes?|service notes?)\b/u',$lower))$result=pos_agent_prep_answer($selected,$check??['items'=>[]]);
    elseif(preg_match('/\b(price|prices|cost|how much|size|sizes|option|options)\b/u',$lower))$result=pos_agent_price_answer($selected,$check??['items'=>[]]);
    elseif($check&&preg_match('/\b(cart|check|ticket|this order|current order|what .* ordered|what is on|what\x27s on)\b/u',$lower))$result=pos_agent_check_answer($check);
    elseif($selected){
        $lines=[];foreach($selected as $item){$line=(string)$item['name'].' — '.((string)$item['description']!==''?(string)$item['description']:'No description recorded').'. '.pos_agent_item_price_text($item).'.';if(!empty($item['tags']))$line.=' Tags: '.implode(', ',$item['tags']).'.';$guest=pos_agent_item_lines_text($check??['items'=>[]],$item);if($guest!=='')$line.=' Current check: '.$guest.'.';$lines[]=$line;}
        $result=['answer'=>"Menu Knowledge:\n- ".implode("\n- ",$lines),'sources'=>array_values(array_unique([...pos_agent_item_sources($selected),'Menu Knowledge']))];
    }elseif($check)$result=pos_agent_check_answer($check);
    else $result=['answer'=>'The POS Agent is ready. Open a check or ask about a menu item, ingredients, preparation notes, allergens, prices, or sizes. Customer history and promotions appear only when a CRM customer is attached and your staff account has access.','sources'=>['POS','Menu Knowledge']];

    $sourceText=implode(' ',array_map('strval',$result['sources']??[]));$skill='pos.context';
    if(str_contains($sourceText,'Allergen'))$skill='pos.menu_allergens';elseif(str_contains($sourceText,'Ingredient'))$skill='pos.menu_ingredients';elseif(str_contains($sourceText,'Customer Inbox'))$skill='pos.customer_promotions';elseif(str_contains($sourceText,'Customer CRM'))$skill='pos.customer_context';elseif(str_contains($sourceText,'Menu Notes'))$skill='pos.menu_notes';elseif(str_contains($sourceText,'Menu Knowledge'))$skill='pos.menu_knowledge';

    app_audit($pdo,$org,$uid,'agent.pos_context_used','pos_check',$checkPublicId?:null,null,['skill'=>$skill,'locationId'=>$locationId?:null,'checkPublicId'=>$checkPublicId?:null,'customerLinked'=>!empty($check['customer']['publicId']),'cartMenuItems'=>count($cartKnowledge),'message'=>mb_substr($message,0,300,'UTF-8')]);
    app_json_response(['ok'=>true,'skill'=>$skill,'answer'=>$result['answer'],'data'=>['checkPublicId'=>$checkPublicId?:null,'locationId'=>$locationId?:null,'cartMenuItemIds'=>array_column($cartKnowledge,'id'),'customerLinked'=>!empty($check['customer']['publicId'])],'sources'=>$result['sources']??[]]);
}catch(Throwable $e){
    $status=$e instanceof InvalidArgumentException?422:($e instanceof DomainException?403:500);if($status===500)error_log('POS Agent error: '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>$status===500?'The POS Agent could not load the current transaction context.':$e->getMessage()],$status);
}
