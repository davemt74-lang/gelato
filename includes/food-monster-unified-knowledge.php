<?php
declare(strict_types=1);

/**
 * Read-only evidence broker inside Gelato's authenticated PHP runtime.
 * Never send these records to an external model or expose an arbitrary query.
 */
require_once __DIR__.'/food-monster-g13b-knowledge.php';
require_once __DIR__.'/food-monster-read-tools.php';

function fmuk_topics(string $question): array
{
    $text=mb_strtolower(trim($question),'UTF-8');
    if($text===''||mb_strlen($text,'UTF-8')>4500)return [];
    // A proposed write is governed by the existing explicit Agent route, not
    // converted into a read snapshot or interpreted as model authorization.
    if(preg_match('/\b(?:create|add|delete|remove|edit|change|update|approve|publish|refund|void|charge|assign|clock in|clock out|place an order|make a purchase)\b/u',$text))return [];
    $patterns=[
        'menu'=>'/\b(?:menu|pizza|pasta|dish|dishes|entree|food items?|prices?|toppings?|specials?|gelato flavors?)\b/u',
        'recipes'=>'/\b(?:recipes?|batch formula|dough formula|ingredients?|production standards?|how (?:do|to) (?:make|prepare|cook|bake))\b/u',
        'vision_lab'=>'/\b(?:vision lab|vision operations?|vision models?|vision datasets?|vision training|machine vision|computer vision|camera learning|visual recognition|vision missions?|vision samples?|vision drift|model benchmarks?|model rollouts?|glasses? vision)\b/u',
        'schedule'=>'/\b(?:schedules?|shifts?|roster|working (?:today|tonight|tomorrow)|staffing|coverage|who works|when (?:am|are|is) .*working)\b/u',
        'employees'=>'/\b(?:employees?|staff|crew|team members?|personnel|workers?|who works here|who is our)\b/u',
        'inventory'=>'/\b(?:inventory|stock|on hand|reorder|shortage|supplies)\b/u',
        'tasks'=>'/\b(?:tasks?|checklists?|to.do|prep list|opening duties|closing duties)\b/u',
        'sales'=>'/\b(?:sales|revenue|tickets|average check|gross|net sales)\b/u',
        'crm'=>'/\b(?:crm|customers?|guests?|customer relationships?|loyalty|regulars?)\b/u',
        'equipment'=>'/\b(?:equipment|appliances?|assets?|maintenance|repairs?|warranty|service history)\b/u',
        'purchasing'=>'/\b(?:purchasing|vendors?|suppliers?|purchase orders?|po numbers?|receiving|deliveries)\b/u',
        'catering'=>'/\b(?:catering|banquets?|event bookings?|event readiness)\b/u',
        'wholesale'=>'/\b(?:wholesale|business accounts?|distributors?|b2b)\b/u',
        'knowledge'=>'/\b(?:knowledge (?:center|base|documents?)|handbooks?|polic(?:y|ies)|procedures?|sops?|manuals?|training documents?|reference materials?|staff guides?)\b/u',
        'kds'=>'/\b(?:kds|kitchen tickets?|kitchen display|expo tickets?|station load)\b/u',
        'online_orders'=>'/\b(?:online orders?|pickup orders?|web orders?|pickup queues?|fulfillment status|curbside orders?)\b/u',
        'attendance'=>'/\b(?:attendance|time ?clock|clocked in|clocked out|on break|break status|labor hours)\b/u',
        'pos'=>'/\b(?:pos|open checks?|open tabs?|table checks?|table service|unpaid checks?|dine.?in checks?)\b/u',
        'reservations'=>'/\b(?:reservations?|waitlist|guest parties?|upcoming bookings?|host stand|seating)\b/u',
        'training'=>'/\b(?:training (?:assignments?|progress|status|records?|due|overdue|courses?)|(?:my|our|employee|staff|team) training|certifications?|certificates?|learning progress|training requirements?)\b/u',
    ];
    $topics=[];
    foreach($patterns as $name=>$pattern)if(preg_match($pattern,$text))$topics[]=$name;
    // A menu-item ingredient question is not a request to disclose a private
    // production recipe. Voice guests can ask what's listed on a pizza
    // without provoking an unrelated "recipe not authorized" diagnostic.
    $namedMenuIngredients=preg_match('/\b(?:pizza|pasta|dish|menu item|entree)\b/u',$text)
        &&preg_match('/\b(?:ingredients?|toppings?|contains?|what.s in|made of)\b/u',$text);
    $productionRecipe=preg_match('/\b(?:recipe|formula|batch|production|dough|yield|mixing|kneading)\b/u',$text);
    if($namedMenuIngredients&&!$productionRecipe&&in_array('menu',$topics,true))
        $topics=array_values(array_filter($topics,static fn(string $topic):bool=>$topic!=='recipes'));
    return array_slice($topics,0,3);
}

function fmuk_capability_question(string $question): bool
{
    return preg_match('/\b(?:what (?:can|do) you (?:access|know|see|help with)|what (?:information|data|tools) (?:can|do) you (?:access|have)|do you have access|can you access|what are your capabilities)\b/iu',$question)===1;
}

function fmuk_menu_overview_question(string $question): bool
{
    return preg_match('/\b(?:what(?:\x27s| is) on the menu|show (?:me )?(?:the |our )?menu|menu (?:overview|list|items|options|specials)|what (?:food|dishes|pizzas|pastas) (?:do|can) (?:you|we) (?:have|serve|offer))\b/iu',$question)===1;
}

/** Preserve item names while removing menu-question wording and contractions. */
function fmuk_menu_subject(string $question): string
{
    $subject=mb_strtolower(trim($question),'UTF-8');
    $subject=preg_replace('/[^\\p{L}\\p{N}\\s-]+/u',' ',$subject)??$subject;
    $subject=preg_replace('/\\b(?:what|which|where|does|do|can|is|are|the|this|that|it|in|of|on|for|to|i|we|you|s|would|should|there|with|without|and|a|an|some|please|show|me|tell|about|how|much|price|prices|cost|menu|item|items|ingredients?|toppings?|contains?|listed|details?|serve|served|has|have)\\b/iu',' ',$subject)??$subject;
    return trim(preg_replace('/\\s+/u',' ',$subject)??$subject);
}

function fmuk_roster_question(string $question): bool
{
    return preg_match('/\b(?:who (?:are|is) (?:the |our )?(?:employees|staff|crew)|(?:show|list) (?:me )?(?:the |our )?(?:employees|staff|crew|team)|employee (?:directory|roster|list)|staff (?:directory|roster|list))\b/iu',$question)===1;
}

function fmuk_prefers_broker(string $question,array $user): bool
{
    $topics=fmuk_topics($question);
    if(!$topics&&!fmuk_capability_question($question))return false;
    return fmuk_capability_question($question)||fmuk_menu_overview_question($question)
        ||in_array('menu',$topics,true)||in_array('recipes',$topics,true)
        ||in_array('vision_lab',$topics,true)
        ||fmuk_roster_question($question)||count($topics)>1||in_array('knowledge',$topics,true)
        ||in_array('pos',$topics,true)||in_array('reservations',$topics,true)||in_array('training',$topics,true)
        ||(in_array('schedule',$topics,true)&&!app_has_permission('schedule.agent',$user));
}

function fmuk_schedule_target_day(string $question,?DateTimeImmutable $now=null): ?string
{
    $now=$now??new DateTimeImmutable('today');
    if(preg_match('/\btomorrow\b/iu',$question))return $now->modify('+1 day')->format('Y-m-d');
    if(preg_match('/\b(?:today|tonight)\b/iu',$question))return $now->format('Y-m-d');
    if(preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/u',$question,$match)){
        $day=DateTimeImmutable::createFromFormat('!Y-m-d',$match[1]);
        return $day&&$day->format('Y-m-d')===$match[1]?$match[1]:null;
    }
    return null;
}

function fmuk_allowed(array $user,string $topic,bool $voice,bool $ownerVoiceVerified): bool
{
    $safe=!$voice||$ownerVoiceVerified;
    if($topic==='menu')return app_has_permission('menu.view',$user)
        ||(app_has_permission('employee.self',$user)&&app_has_permission('training.self_view',$user));
    if(!$safe)return false;
    return match($topic){
        'recipes'=>app_has_permission('recipes.view',$user)&&app_has_permission('recipes.agent',$user),
        'vision_lab'=>app_has_permission('glasses.view',$user),
        'schedule'=>app_has_permission('schedule.view',$user)||app_has_permission('schedule.manage',$user)||app_has_permission('schedule.self',$user),
        'employees'=>app_has_permission('staff.view',$user)||app_has_permission('staff.manage',$user)||app_has_permission('schedule.view',$user),
        'inventory'=>app_has_permission('inventory.view',$user),
        'tasks'=>app_has_permission('tasks.view',$user)||app_has_permission('tasks.self',$user),
        'sales'=>app_has_permission('sales.view',$user)&&app_has_permission('sales.agent',$user),
        'crm'=>app_has_permission('crm.view',$user),
        'equipment'=>app_has_permission('equipment.view',$user)&&app_has_permission('agent.equipment_skills',$user),
        'purchasing'=>app_has_permission('purchasing.view',$user)&&app_has_permission('purchasing.agent',$user),
        'catering'=>app_has_permission('catering.view',$user)&&app_has_permission('catering.agent',$user),
        'wholesale'=>app_has_permission('wholesale.view',$user)&&app_has_permission('wholesale.agent',$user),
        'knowledge'=>app_has_permission('knowledge.view',$user),
        'kds'=>app_has_permission('kds.view',$user),
        'online_orders'=>fmuk_online_order_view_allowed($user),
        'attendance'=>app_has_permission('timeclock.view',$user)
            ||app_has_permission('attendance.view',$user)||app_has_permission('timeclock.self',$user),
        'pos'=>app_has_permission('pos.use',$user)||app_has_permission('pos.manage',$user)
            ||app_has_permission('table_service.view',$user),
        'reservations'=>app_has_permission('host.view',$user)
            &&(app_has_permission('table_service.view',$user)||app_has_permission('host.use',$user)
                ||app_has_permission('host.manage',$user)),
        'training'=>app_has_permission('training.self_view',$user)
            ||app_has_permission('training.view_all_progress',$user),
        default=>false,
    };
}

/** Visible capability names only; never expose role-inaccessible tools. */
function fmuk_capabilities(array $user,bool $voice,bool $ownerVoiceVerified): array
{
    $names=['menu'=>'active menu and prices','recipes'=>'approved recipes and production standards',
        'vision_lab'=>'Vision Lab missions, datasets, samples and drift',
        'schedule'=>'shift schedules','employees'=>'staff roster','inventory'=>'inventory and stock',
        'tasks'=>'restaurant tasks','sales'=>'recorded sales','crm'=>'customer CRM',
        'equipment'=>'equipment and maintenance','purchasing'=>'vendors and purchasing',
        'catering'=>'catering','wholesale'=>'wholesale',
        'knowledge'=>'published Knowledge Center documents and policies',
        'kds'=>'live kitchen tickets','online_orders'=>'pickup and online order status',
        'attendance'=>'time clock and attendance',
        'pos'=>'open POS checks and table service',
        'reservations'=>'guest reservations and waitlist',
        'training'=>'employee training assignments and certifications'];
    $result=[];
    foreach($names as $topic=>$description)
        if(fmuk_allowed($user,$topic,$voice,$ownerVoiceVerified))$result[]=$description;
    if(!$voice||$ownerVoiceVerified){
        $specialists=[
            ['timeclock.agent'=>'time clock actions'],
        ];
        foreach($specialists as $entry)foreach($entry as $permission=>$label)
            if(app_has_permission($permission,$user))$result[]=$label;
    }
    return $result;
}

function fmuk_menu_items(PDO $pdo,int $org,string $question): array
{
    $subject=fmuk_menu_subject($question);
    if(!menu_manager_ready($pdo))return [];
    // Reuse the same published, channel-enabled menu that the public site
    // renders. The previous parallel SQL search could leak draft products.
    $public=[];$publishedPrices=[];
    foreach(menu_manager_channel_menu($pdo,$org,'public_menu') as $section)
        foreach($section['items'] as $item){
            $public[]=['id'=>(int)$item['id'],'name'=>(string)$item['name'],
                'section'=>(string)$section['name']];
            $publishedPrices[(int)$item['id']]=array_map(static fn(array $price):array=>[
                'id'=>(int)$price['id'],'label'=>(string)$price['optionName'],
                'amount'=>(float)$price['amount'],'currency'=>(string)$price['currency'],
            ],$item['prices']);
        }
    $resolve=static function(array $ids,int $limit)use($pdo,$org,$publishedPrices):array{
        $items=menu_training_items_by_ids($pdo,$org,array_slice($ids,0,$limit),$limit);
        foreach($items as &$item)$item['prices']=$publishedPrices[(int)$item['id']]??[];
        unset($item);
        return $items;
    };
    $overview=fmuk_menu_overview_question($question)||(
        preg_match('/\b(?:menu|specials?|options?)\b/iu',$question)===1
        &&mb_strlen($subject,'UTF-8')<3);
    $needle=mb_strtolower($subject,'UTF-8');
    if($overview){
        $filter=preg_replace('/(?:es|s)$/u','',$needle)??$needle;
        $ids=[];
        foreach($public as $row){
            $section=mb_strtolower($row['section'],'UTF-8');
            $name=mb_strtolower($row['name'],'UTF-8');
            if($filter===''||str_contains($section,$filter)||str_contains($name,$filter))
                $ids[]=$row['id'];
        }
        return $resolve($ids,20);
    }
    if(mb_strlen($needle,'UTF-8')<2)return [];
    $name=trim(preg_replace('/\b(?:pizza|pie|pasta|dish|item)\b/iu',' ',$needle)??$needle);
    $ids=[];$candidates=[];
    foreach($public as $row){
        $label=mb_strtolower($row['name'],'UTF-8');
        if(str_contains($label,$needle)||($name!==''&&str_contains($label,$name)))
            $ids[]=$row['id'];
        $candidates[]=['id'=>$row['id'],'name'=>trim(preg_replace(
            '/\b(?:pizza|pie|pasta|dish|item)\b/iu',' ',$label)??$label)];
    }
    if(!$ids&&mb_strlen($name,'UTF-8')>=5){
        // Typo tolerance is limited to the official published items.
        $compact=preg_replace('/[^\p{L}\p{N}]/u','',$name)??$name;
        if(strlen($compact)>=5&&strlen($compact)<=80){
            $best=[];$distanceLimit=3;
            foreach($candidates as $row){
                $candidate=preg_replace('/[^\p{L}\p{N}]/u','',$row['name'])??$row['name'];
                $distance=levenshtein($compact,$candidate);
                if($distance<$distanceLimit){$distanceLimit=$distance;$best=[$row['id']];}
                elseif($distance===$distanceLimit)$best[]=$row['id'];
            }
            if($distanceLimit<=2&&count($best)===1)$ids=$best;
        }
    }
    return $resolve($ids,12);
}

function fmuk_render_menu(array $items): array
{
    $lines=[];$sources=[];
    foreach(array_slice($items,0,20) as $item){
        $prices=[];
        foreach(array_slice((array)($item['prices']??[]),0,3) as $p)
            if(is_numeric($p['amount']??null))$prices[]=trim((string)($p['label']??'')).' '.number_format((float)$p['amount'],2).' '.(string)($p['currency']??'USD');
        $lines[]=mb_substr((string)($item['name']??''),0,100,'UTF-8')
            .' | prices: '.($prices?implode(', ',$prices):'not recorded')
            .' | ingredients: '.implode(', ',array_slice((array)($item['ingredients']??[]),0,12));
        $sources[]='Gelato active menu item #'.(int)($item['id']??0);
    }
    return [$lines,$sources];
}

/** Pure renderer, independently testable with fabricated records. */
function fmuk_render_recipes(array $recipes): array
{
    $lines=[];$sources=[];
    foreach(array_slice($recipes,0,5) as $recipe){
        if(($recipe['status']??'')!=='active')continue;
        $ingredients=json_decode((string)($recipe['ingredients_json']??'[]'),true)?:[];
        $steps=json_decode((string)($recipe['instructions_json']??'[]'),true)?:[];
        $parts=[];
        foreach(array_slice($ingredients,0,12) as $x){
            $parts[]=is_array($x)?trim(implode(' ',array_filter([(string)($x['quantity']??''),(string)($x['unit']??''),(string)($x['ingredient']??$x['name']??'')]))):(string)$x;
        }
        $directions=[];
        foreach(array_slice($steps,0,8) as $x)$directions[]=mb_substr(is_array($x)?(string)($x['text']??''):(string)$x,0,170,'UTF-8');
        $lines[]=mb_substr((string)($recipe['name']??''),0,100,'UTF-8').' | yield: '
            .(string)($recipe['yield_quantity']??'not recorded').' '.(string)($recipe['yield_unit']??'')
            .' | ingredients: '.implode(', ',$parts).' | steps: '.implode('; ',$directions);
        $sources[]='Gelato active recipe '.(string)($recipe['public_id']??'');
    }
    return [$lines,$sources];
}

/** Strict display allowlist for business records; no contact details or notes. */
function fmuk_render_business_rows(string $topic,array $rows): array
{
    $lines=[];$sources=[];
    foreach(array_slice($rows,0,25) as $row){
        $id=(string)($row['public_id']??'');
        if($topic==='crm'){
            $lines[]=mb_substr((string)($row['display_name']??''),0,100,'UTF-8')
                .' | status: '.(string)($row['status']??'');
            $sources[]='Gelato CRM customer '.$id;
        }elseif($topic==='equipment'){
            $lines[]=mb_substr((string)($row['name']??''),0,110,'UTF-8')
                .' | status: '.(string)($row['operational_status']??'')
                .' | condition: '.(string)($row['condition_status']??'')
                .' | location: '.(string)($row['location_name']??'')
                .' | next service: '.(string)($row['next_service_on']??'not recorded');
            $sources[]='Gelato equipment '.$id;
        }elseif($topic==='purchasing'){
            $lines[]=(string)($row['order_number']??'').' | vendor: '
                .mb_substr((string)($row['vendor_name']??''),0,100,'UTF-8')
                .' | status: '.(string)($row['status']??'')
                .' | total: '.(string)($row['total_amount']??'not recorded')
                .' | expected: '.(string)($row['expected_delivery_date']??'not recorded');
            $sources[]='Gelato purchase order '.$id;
        }elseif($topic==='catering'){
            if(($row['source_type']??'')!=='catering')continue;
            $lines[]=mb_substr((string)($row['title']??'Event'),0,110,'UTF-8')
                .' | event date: '.(string)($row['service_start_at']??'not recorded')
                .' | status: '.(string)($row['status']??'')
                .' | readiness: '.(string)($row['readiness_percent']??'not recorded');
            $sources[]='Gelato catering operation '.$id;
        }elseif($topic==='wholesale'){
            $lines[]=mb_substr((string)($row['business_name']??''),0,110,'UTF-8')
                .' | pipeline: '.(string)($row['pipeline_stage']??'')
                .' | monthly volume: '.(string)($row['estimated_monthly_volume']??'not recorded');
            $sources[]='Gelato wholesale lead '.$id;
        }
    }
    return [$lines,$sources];
}




/** Reduce read evidence to the same assigned locations used by POS and Host Stand. */
function fmuk_service_location_ids(PDO $pdo,array $user,string $topic,int $org): array
{
    require_once __DIR__.'/pos-core.php';
    require_once __DIR__.'/operational-access.php';
    $locations=pos_locations($pdo,$org);
    $allowed=[];
    foreach($locations as $location){
        $id=(int)($location['id']??0);
        if($id<1)continue;
        if($topic==='reservations'){
            $permit=operational_location_allowed($pdo,$user,'host.view',$id)
                &&(operational_location_allowed($pdo,$user,'table_service.view',$id)
                    ||operational_location_allowed($pdo,$user,'host.use',$id)
                    ||operational_location_allowed($pdo,$user,'host.manage',$id));
        }else{
            $permit=operational_location_allowed($pdo,$user,'pos.use',$id)
                ||operational_location_allowed($pdo,$user,'pos.manage',$id)
                ||operational_location_allowed($pdo,$user,'table_service.view',$id);
        }
        if($permit)$allowed[]=$id;
    }
    return $allowed;
}

/** Only display restaurant service fields; exclude guest contact and payment details. */
function fmuk_render_service_rows(string $topic,array $rows): array
{
    $lines=[];$sources=[];
    foreach(array_slice($rows,0,20) as $row){
        $id=(string)($row['public_id']??'');
        if($topic==='pos'){
            $lines[]='Check '.(string)($row['check_number']??'')
                .' | service: '.(string)($row['service_mode']??'')
                .' | table: '.mb_substr((string)($row['table_name']??'unassigned'),0,80,'UTF-8')
                .' | guests: '.(string)($row['guest_count']??'')
                .' | total: '.(string)($row['total_amount']??'')
                .' | paid: '.(string)($row['amount_paid']??'')
                .' | location: '.mb_substr((string)($row['location_name']??''),0,80,'UTF-8')
                .' | opened: '.(string)($row['opened_at']??'');
            $sources[]='Gelato POS check '.$id;
        }elseif($topic==='reservations'){
            $lines[]=mb_substr((string)($row['guest_name']??'Guest'),0,90,'UTF-8')
                .' | party size: '.(string)($row['party_size']??'')
                .' | status: '.(string)($row['status']??'')
                .' | type: '.(string)($row['reservation_type']??'')
                .' | expected: '.(string)($row['scheduled_at']??'not recorded')
                .' | location: '.mb_substr((string)($row['location_name']??''),0,80,'UTF-8');
            $sources[]='Gelato guest reservation '.$id;
        }
    }
    return [$lines,$sources];
}

/** Match the Online Order Agent's read grants, without importing its write tools. */
function fmuk_online_order_view_allowed(array $user): bool
{
    foreach(['online_orders.fulfill','pos.use','pos.manage','kds.view','kds.update','kds.configure',
        'crm.view','crm.manage','order_recovery.view','order_recovery.manage','order_recovery.refund'] as $grant)
        if(app_has_permission($grant,$user))return true;
    return false;
}

/** Render only operational fields, never customer contacts or payment tokens. */
function fmuk_render_live_rows(string $topic,array $rows): array
{
    $lines=[];$sources=[];
    foreach(array_slice($rows,0,20) as $row){
        $id=(string)($row['public_id']??'');
        if($topic==='kds'){
            $lines[]=mb_substr((string)($row['item_name_snapshot']??''),0,100,'UTF-8')
                .' | quantity: '.(string)($row['quantity']??'')
                .' | kitchen status: '.(string)($row['status']??'')
                .' | station: '.(string)($row['station_name']??'unassigned')
                .' | check: '.(string)($row['check_number']??'')
                .' | sent: '.(string)($row['sent_at']??'');
            $sources[]='Gelato KDS item '.$id;
        }elseif($topic==='online_orders'){
            $lines[]='Order '.(string)($row['check_number']??'')
                .' | status: '.(string)($row['status']??'')
                .' | location: '.mb_substr((string)($row['location_name']??''),0,100,'UTF-8')
                .' | total: '.(string)($row['total_amount']??'not recorded')
                .' | promised: '.(string)($row['requested_ready_at']??'not recorded')
                .' | fulfilled: '.(string)($row['fulfilled_at']??'not yet');
            $sources[]='Gelato online order '.$id;
        }elseif($topic==='attendance'){
            $lines[]=mb_substr((string)($row['display_name']??'Employee'),0,100,'UTF-8')
                .' | status: '.(string)($row['status']??'')
                .' | clocked in: '.(string)($row['clocked_in_at']??'')
                .' | clocked out: '.(string)($row['clocked_out_at']??'not yet');
            $sources[]='Gelato time clock entry '.$id;
        }
    }
    return [$lines,$sources];
}

/** Read-only display projection of training progress; never show private quiz answers. */
function fmuk_render_training_rows(string $type,array $rows): array
{
    $lines=[];$sources=[];
    foreach(array_slice($rows,0,20) as $row){
        $id=(int)($row['id']??0);
        if($id<1)continue;
        $person=mb_substr((string)($row['display_name']??'Employee'),0,110,'UTF-8');
        if($type==='assignment'){
            $lines[]=$person.' | training: '.mb_substr((string)($row['assignment_type']??''),0,80,'UTF-8')
                .' | reference: '.mb_substr((string)($row['assignment_reference_id']??''),0,120,'UTF-8')
                .' | status: '.(string)($row['status']??'')
                .' | due: '.(string)($row['due_at']??'not set')
                .' | completed: '.(string)($row['completed_at']??'not yet');
            $sources[]='Gelato training assignment #'.$id;
        }elseif($type==='certification'){
            $lines[]=$person.' | certification: '.mb_substr((string)($row['certification_type']??''),0,110,'UTF-8')
                .' | status: '.(string)($row['status']??'')
                .' | issued: '.(string)($row['issued_at']??'not recorded')
                .' | expires: '.(string)($row['expires_at']??'not set');
            $sources[]='Gelato certification #'.$id;
        }
    }
    return [$lines,$sources];
}

/** Extract the most distinctive search word without allowing SQL syntax. */
function fmuk_knowledge_search_term(string $question): string
{
    $ignore=['what','which','where','when','does','have','that','this','tell','show','please',
        'about','your','ours','there','with','from','into','and','the','are','can',
        'policy','policies','knowledge','center','base','documents','document','manual',
        'manuals','handbook','handbooks','procedure','procedures','training','guide',
        'guides','sops','employee','employees','staff','restaurant','gelato'];
    $words=preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($question,'UTF-8'))?:[];
    $terms=[];
    foreach($words as $word)if(mb_strlen($word,'UTF-8')>=4&&!in_array($word,$ignore,true))$terms[]=$word;
    usort($terms,static fn(string $a,string $b):int=>mb_strlen($b,'UTF-8')<=>mb_strlen($a,'UTF-8'));
    return (string)($terms[0]??'');
}

/** Never display unpublished, agent-disabled or arbitrary raw document payloads. */
function fmuk_render_knowledge_rows(array $rows): array
{
    $lines=[];$sources=[];
    foreach(array_slice($rows,0,6) as $row){
        $title=mb_substr(trim(strip_tags((string)($row['title']??''))),0,160,'UTF-8');
        $excerpt=trim(preg_replace('/\s+/u',' ',strip_tags((string)($row['excerpt']??'')))??'');
        $lines[]='Document: '.$title.' | recorded excerpt: '.mb_substr($excerpt,0,800,'UTF-8');
        $sources[]='Gelato Knowledge Center document '.(string)($row['public_id']??'');
    }
    return [$lines,$sources];
}

/**
 * Sanitize and summarize the existing Vision Lab catalog. No media bytes,
 * employee identifiers, annotations, or model-management actions enter chat.
 */
function fmuk_render_vision_lab(array $catalog): array
{
    if(empty($catalog['ready']))
        return [['Vision Lab tables are not initialized on this installation.'],[]];
    $assignments=(array)($catalog['assignments']??[]);
    $missions=(array)($catalog['missions']??[]);
    $samples=(array)($catalog['samples']??[]);
    $datasets=(array)($catalog['datasets']??[]);
    $devices=(array)($catalog['devices']??[]);
    $drift=(array)($catalog['driftIncidents']??[]);
    $activeAssignments=count(array_filter($assignments,
        static fn($row):bool=>is_array($row)&&empty($row['releasedAt'])));
    $pendingSamples=count(array_filter($samples,
        static fn($row):bool=>is_array($row)&&in_array((string)($row['reviewStatus']??''),
            ['pending','needs_adjudication'],true)));
    $lines=['Active vision device assignments: '.$activeAssignments
        .' | devices: '.count($devices).' | missions: '.count($missions)
        .' | datasets: '.count($datasets).' | samples awaiting review: '.$pendingSamples
        .' | unresolved drift incidents: '.count($drift)];
    $sources=['Gelato Vision Lab catalog'];
    foreach(array_slice($missions,0,6) as $row){
        if(!is_array($row))continue;
        $id=(string)($row['publicId']??'');
        $title=mb_substr(trim(strip_tags((string)($row['title']??'Untitled mission'))),0,100,'UTF-8');
        $lines[]='Mission: '.$title.' | '.mb_substr((string)($row['status']??'unknown'),0,32,'UTF-8')
            .' | approved samples: '.(int)($row['approvedCount']??0);
        if($id!=='')$sources[]='Gelato Vision Lab mission '.$id;
    }
    foreach(array_slice($datasets,0,6) as $row){
        if(!is_array($row))continue;
        $id=(string)($row['publicId']??'');
        $title=mb_substr(trim(strip_tags((string)($row['name']??'Untitled dataset'))),0,100,'UTF-8');
        $lines[]='Dataset: '.$title.' | version: '.mb_substr((string)($row['versionLabel']??''),0,40,'UTF-8')
            .' | '.mb_substr((string)($row['status']??'unknown'),0,32,'UTF-8')
            .' | items: '.(int)($row['itemCount']??0);
        if($id!=='')$sources[]='Gelato Vision Lab dataset '.$id;
    }
    foreach(array_slice($drift,0,6) as $row){
        if(!is_array($row))continue;
        $id=(string)($row['publicId']??'');
        $lines[]='Vision drift: '.mb_substr((string)($row['severity']??'unknown'),0,24,'UTF-8')
            .' | '.mb_substr((string)($row['category']??'uncategorized'),0,80,'UTF-8')
            .' | '.mb_substr((string)($row['recoveryStatus']??'open'),0,32,'UTF-8');
        if($id!=='')$sources[]='Gelato Vision Lab drift '.$id;
    }
    return [$lines,$sources];
}

/** @return array{context:string,sources:array,topics:array} */
function fmuk_evidence(PDO $pdo,array $user,string $question,bool $voice=false,bool $ownerVoiceVerified=false): array
{
    $org=(int)($user['organization_id']??0);$uid=(int)($user['id']??0);
    if($org<1||$uid<1)throw new DomainException('Authenticated restaurant context is required.');
    $topics=fmuk_topics($question);$sources=[];$sections=[];$served=[];
    foreach($topics as $topic){
        if(!fmuk_allowed($user,$topic,$voice,$ownerVoiceVerified)){
            $sections[]=$topic.': NOT AUTHORIZED for this speaker/account; do not disclose records.';
            continue;
        }
        try{
            $lines=[];$used=[];
            if($topic==='menu'){
                require_once __DIR__.'/menu-training-knowledge.php';
                [$lines,$used]=fmuk_render_menu(fmuk_menu_items($pdo,$org,$question));
            }elseif($topic==='vision_lab'){
                require_once __DIR__.'/glasses-vision-lab.php';
                [$lines,$used]=fmuk_render_vision_lab(glasses_vision_lab_catalog($pdo,$user));
            }elseif($topic==='recipes'){
                require_once __DIR__.'/restaurant-brain.php';
                if(restaurant_brain_table_ready($pdo,'recipes')){
                    $subject=fm13b_subject($question);
                    [$lines,$used]=fmuk_render_recipes(restaurant_brain_recipe_search($pdo,$org,mb_strlen($subject,'UTF-8')>=2?$subject:'',5));
                }
            }elseif($topic==='schedule'){
                require_once __DIR__.'/scheduling-core.php';
                if(scheduling_core_ready($pdo)){
                    $self=!app_has_permission('schedule.view',$user)&&!app_has_permission('schedule.manage',$user);
                    $rows=[];
                    if($self){
                        require_once __DIR__.'/employee-home-core.php';
                        $rows=employee_home_shifts($pdo,$org,$uid,14);
                    }else{
                        $week=scheduling_week_start();
                        $rows=array_merge(scheduling_shifts($pdo,$org,$week),scheduling_shifts($pdo,$org,(new DateTimeImmutable($week))->modify('+7 days')->format('Y-m-d')));
                    }
                    $targetDay=fmuk_schedule_target_day($question);
                    if($targetDay!==null)$rows=array_values(array_filter($rows,
                        static fn(array $r):bool=>substr((string)($r['starts_at']??''),0,10)===$targetDay));
                    foreach(array_slice($rows,0,26) as $shift){
                        if(($shift['status']??'')==='cancelled')continue;
                        $lines[]=mb_substr((string)($shift['display_name']??'My shift'),0,90,'UTF-8')
                            .' | '.(string)($shift['starts_at']??'').'–'.(string)($shift['ends_at']??'')
                            .' | '.mb_substr((string)($shift['title']??''),0,80,'UTF-8');
                        $used[]='Gelato schedule shift '.(string)($shift['public_id']??'');
                    }
                }
            }elseif($topic==='employees'){
                require_once __DIR__.'/scheduling-core.php';
                if(scheduling_core_ready($pdo))foreach(array_slice(scheduling_staff($pdo,$org),0,30) as $employee){
                    $lines[]=mb_substr((string)(($employee['preferred_name']??'')?:($employee['display_name']??'')),0,100,'UTF-8')
                        .' | '.mb_substr((string)($employee['job_title']??''),0,80,'UTF-8')
                        .' | '.mb_substr((string)($employee['positions']??''),0,100,'UTF-8');
                }
                if($lines)$used[]='Gelato active organization staff roster';
            }elseif($topic==='inventory'){
                require_once __DIR__.'/operations-core.php';
                if(operations_core_ready($pdo))foreach(operations_inventory_list($pdo,$org,'',false,25) as $item){
                    $lines[]=mb_substr((string)($item['name']??''),0,100,'UTF-8').' | on hand: '
                        .(string)($item['on_hand_quantity']??'unknown').' '.(string)($item['base_unit']??'')
                        .' | reorder point: '.(string)($item['reorder_point']??'not recorded');
                    $used[]='Gelato inventory '.(string)($item['public_id']??'');
                }
            }elseif($topic==='tasks'){
                require_once __DIR__.'/operations-core.php';
                if(operations_core_ready($pdo)){
                    // Do not call operations_task_list() here: it performs a
                    // catering sync as a side effect. This path is read-only.
                    $self=!app_has_permission('tasks.view',$user);
                    $sql="SELECT t.public_id,t.title,t.status,t.due_at FROM restaurant_tasks t
                        WHERE t.organization_id=? AND t.archived_at IS NULL";
                    if($self)$sql.=" AND EXISTS (SELECT 1 FROM restaurant_task_assignments a
                        WHERE a.organization_id=t.organization_id AND a.task_id=t.id AND a.user_id=?)";
                    $sql.=" ORDER BY t.due_at IS NULL,t.due_at,t.id DESC LIMIT 20";
                    $stmt=$pdo->prepare($sql);$stmt->execute($self?[$org,$uid]:[$org]);
                    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $task){
                        $lines[]=mb_substr((string)($task['title']??''),0,120,'UTF-8').' | status: '
                            .(string)($task['status']??'').' | due: '.(string)($task['due_at']??'not set');
                        $used[]='Gelato restaurant task '.(string)($task['public_id']??'');
                    }
                }
            }elseif($topic==='sales'){
                $result=fm_read_execute($pdo,$user,'recorded_sales',$question);
                $lines[]=$result['answer'];$used=(array)($result['sources']??[]);
            }elseif($topic==='pos'){
                require_once __DIR__.'/pos-core.php';
                if(pos_ready($pdo)){
                    $locations=fmuk_service_location_ids($pdo,$user,$topic,$org);
                    if($locations){
                        $marks=implode(',',array_fill(0,count($locations),'?'));
                        $stmt=$pdo->prepare("SELECT c.public_id,c.check_number,c.service_mode,c.table_name,
                            c.guest_count,c.total_amount,c.amount_paid,c.opened_at,l.name location_name
                            FROM pos_checks c JOIN locations l ON l.organization_id=c.organization_id
                            AND l.id=c.location_id WHERE c.organization_id=? AND c.location_id IN ($marks)
                            AND c.status='open' ORDER BY c.opened_at DESC,c.id DESC LIMIT 20");
                        $stmt->execute(array_merge([$org],$locations));
                        [$lines,$used]=fmuk_render_service_rows($topic,$stmt->fetchAll(PDO::FETCH_ASSOC));
                    }
                }
            }elseif($topic==='reservations'){
                require_once __DIR__.'/restaurant-brain.php';
                if(restaurant_brain_table_ready($pdo,'guest_reservations')){
                    $locations=fmuk_service_location_ids($pdo,$user,$topic,$org);
                    if($locations){
                        $marks=implode(',',array_fill(0,count($locations),'?'));
                        $stmt=$pdo->prepare("SELECT r.public_id,r.guest_name,r.party_size,r.status,
                            r.reservation_type,r.scheduled_at,l.name location_name
                            FROM guest_reservations r JOIN locations l
                            ON l.organization_id=r.organization_id AND l.id=r.location_id
                            WHERE r.organization_id=? AND r.location_id IN ($marks)
                            AND r.status NOT IN ('completed','cancelled','no_show')
                            ORDER BY r.scheduled_at IS NULL,r.scheduled_at,r.id DESC LIMIT 20");
                        $stmt->execute(array_merge([$org],$locations));
                        [$lines,$used]=fmuk_render_service_rows($topic,$stmt->fetchAll(PDO::FETCH_ASSOC));
                    }
                }
            }elseif($topic==='kds'){
                require_once __DIR__.'/kds-production.php';
                if(kds_ready($pdo)){
                    $stmt=$pdo->prepare("SELECT k.public_id,k.status,k.sent_at,i.item_name_snapshot,i.quantity,
                        s.name station_name,c.check_number FROM kds_order_items k
                        JOIN pos_checks c ON c.organization_id=k.organization_id AND c.id=k.check_id
                        JOIN pos_check_items i ON i.organization_id=k.organization_id AND i.id=k.pos_check_item_id
                        LEFT JOIN kds_stations s ON s.organization_id=k.organization_id AND s.id=k.station_id
                        WHERE k.organization_id=? AND k.status NOT IN ('completed','cancelled')
                        ORDER BY k.sent_at,k.id LIMIT 20");
                    $stmt->execute([$org]);
                    [$lines,$used]=fmuk_render_live_rows($topic,$stmt->fetchAll(PDO::FETCH_ASSOC));
                }
            }elseif($topic==='online_orders'){
                require_once __DIR__.'/pickup-fulfillment-core.php';
                if(pickup_fulfillment_ready($pdo)){
                    $stmt=$pdo->prepare("SELECT oo.public_id,oo.status,oo.requested_ready_at,oo.fulfilled_at,
                        c.check_number,c.total_amount,l.name location_name FROM online_orders oo
                        JOIN pos_checks c ON c.organization_id=oo.organization_id AND c.id=oo.pos_check_id
                        JOIN locations l ON l.organization_id=oo.organization_id AND l.id=oo.location_id
                        WHERE oo.organization_id=? AND oo.fulfilled_at IS NULL AND c.status<>'cancelled'
                        ORDER BY COALESCE(oo.requested_ready_at,oo.submitted_at),oo.id LIMIT 20");
                    $stmt->execute([$org]);
                    [$lines,$used]=fmuk_render_live_rows($topic,$stmt->fetchAll(PDO::FETCH_ASSOC));
                }
            }elseif($topic==='attendance'){
                require_once __DIR__.'/timeclock-voice-core.php';
                if(tv_ready($pdo)){
                    $self=!app_has_permission('timeclock.view',$user)&&!app_has_permission('attendance.view',$user);
                    $sql="SELECT t.public_id,t.status,t.clocked_in_at,t.clocked_out_at,u.display_name
                        FROM time_clock_entries t JOIN users u ON u.id=t.user_id
                        JOIN organization_memberships om ON om.organization_id=t.organization_id
                            AND om.user_id=t.user_id AND om.status='active'
                        WHERE t.organization_id=?";
                    if($self)$sql.=" AND t.user_id=?";
                    $sql.=" ORDER BY t.clocked_in_at DESC,t.id DESC LIMIT 20";
                    $stmt=$pdo->prepare($sql);$stmt->execute($self?[$org,$uid]:[$org]);
                    [$lines,$used]=fmuk_render_live_rows($topic,$stmt->fetchAll(PDO::FETCH_ASSOC));
                }
            }elseif($topic==='training'){
                require_once __DIR__.'/restaurant-brain.php';
                $all=app_has_permission('training.view_all_progress',$user);
                if(restaurant_brain_table_ready($pdo,'training_assignments')){
                    $selfFilter=$all?'':' AND a.user_id=?';
                    $sql="SELECT a.id,a.assignment_type,a.assignment_reference_id,a.status,
                        a.due_at,a.completed_at,u.display_name FROM training_assignments a
                        JOIN users u ON u.id=a.user_id AND u.status='active' AND u.archived_at IS NULL
                        JOIN organization_memberships om ON om.organization_id=a.organization_id
                            AND om.user_id=a.user_id AND om.status='active'
                        WHERE a.organization_id=?".$selfFilter."
                        ORDER BY a.completed_at IS NULL DESC,a.due_at IS NULL,a.due_at,a.id DESC LIMIT 20";
                    $stmt=$pdo->prepare($sql);$stmt->execute($all?[$org]:[$org,$uid]);
                    [$items,$evidence]=fmuk_render_training_rows('assignment',$stmt->fetchAll(PDO::FETCH_ASSOC));
                    $lines=array_merge($lines,$items);$used=array_merge($used,$evidence);
                }
                if(restaurant_brain_table_ready($pdo,'certifications')){
                    $selfFilter=$all?'':' AND c.user_id=?';
                    $sql="SELECT c.id,c.certification_type,c.status,c.issued_at,c.expires_at,u.display_name
                        FROM certifications c JOIN users u ON u.id=c.user_id
                            AND u.status='active' AND u.archived_at IS NULL
                        JOIN organization_memberships om ON om.organization_id=c.organization_id
                            AND om.user_id=c.user_id AND om.status='active'
                        WHERE c.organization_id=?".$selfFilter."
                        ORDER BY c.expires_at IS NULL,c.expires_at,c.id DESC LIMIT 20";
                    $stmt=$pdo->prepare($sql);$stmt->execute($all?[$org]:[$org,$uid]);
                    [$items,$evidence]=fmuk_render_training_rows('certification',$stmt->fetchAll(PDO::FETCH_ASSOC));
                    $lines=array_merge($lines,$items);$used=array_merge($used,$evidence);
                }
            }elseif($topic==='knowledge'){
                require_once __DIR__.'/restaurant-brain.php';
                if(restaurant_brain_table_ready($pdo,'knowledge_documents')){
                    $term=fmuk_knowledge_search_term($question);
                    // Only current documents explicitly enabled for agent use.
                    // Locate text on the DB server and return a short matching
                    // excerpt; avoid loading entire uploads into Agent Chat.
                    $sql="SELECT public_id,title,
                        SUBSTRING(content,GREATEST(1,LOCATE(?,LOWER(content))-180),820) excerpt
                        FROM knowledge_documents WHERE organization_id=? AND status='published'
                        AND agent_enabled=1 AND archived_at IS NULL";
                    $parameters=[$term,$org];
                    if($term!==''){
                        $sql.=" AND (title LIKE ? OR content LIKE ?)";
                        $parameters[]='%'.$term.'%';$parameters[]='%'.$term.'%';
                    }
                    $sql.=" ORDER BY updated_at DESC,id DESC LIMIT 6";
                    $stmt=$pdo->prepare($sql);$stmt->execute($parameters);
                    [$lines,$used]=fmuk_render_knowledge_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
                }
            }elseif($topic==='crm'){
                require_once __DIR__.'/customer-crm-core.php';
                if(crm_ready($pdo)){
                    $stmt=$pdo->prepare("SELECT public_id,display_name,status FROM crm_customers
                        WHERE organization_id=? AND status='active' ORDER BY updated_at DESC LIMIT 20");
                    $stmt->execute([$org]);
                    [$lines,$used]=fmuk_render_business_rows($topic,$stmt->fetchAll(PDO::FETCH_ASSOC));
                }
            }elseif($topic==='equipment'){
                require_once __DIR__.'/equipment-brain.php';
                if(equipment_brain_table_ready($pdo,'equipment_assets')){
                    $stmt=$pdo->prepare("SELECT public_id,name,operational_status,condition_status,location_name,next_service_on
                        FROM equipment_assets WHERE organization_id=? AND archived_at IS NULL ORDER BY name LIMIT 25");
                    $stmt->execute([$org]);
                    [$lines,$used]=fmuk_render_business_rows($topic,$stmt->fetchAll(PDO::FETCH_ASSOC));
                }
            }elseif($topic==='purchasing'){
                require_once __DIR__.'/purchasing-core.php';
                if(purchasing_ready($pdo)){
                    $stmt=$pdo->prepare("SELECT po.public_id,po.order_number,po.status,po.expected_delivery_date,
                        po.total_amount,v.name vendor_name FROM purchase_orders po JOIN vendors v
                        ON v.id=po.vendor_id AND v.organization_id=po.organization_id
                        WHERE po.organization_id=? AND po.archived_at IS NULL
                        ORDER BY po.created_at DESC LIMIT 20");
                    $stmt->execute([$org]);
                    [$lines,$used]=fmuk_render_business_rows($topic,$stmt->fetchAll(PDO::FETCH_ASSOC));
                }
            }elseif($topic==='catering'){
                require_once __DIR__.'/restaurant-brain.php';
                if(restaurant_brain_table_ready($pdo,'restaurant_operations')){
                    $stmt=$pdo->prepare("SELECT public_id,source_type,title,service_start_at,status,readiness_percent
                        FROM restaurant_operations WHERE organization_id=? AND archived_at IS NULL
                        AND source_type='catering' AND status<>'cancelled' ORDER BY service_start_at,id LIMIT 20");
                    $stmt->execute([$org]);
                    [$lines,$used]=fmuk_render_business_rows($topic,$stmt->fetchAll(PDO::FETCH_ASSOC));
                    }
            }elseif($topic==='wholesale'){
                require_once __DIR__.'/restaurant-brain.php';
                if(restaurant_brain_table_ready($pdo,'wholesale_leads')){
                    $stmt=$pdo->prepare("SELECT public_id,business_name,pipeline_stage,estimated_monthly_volume
                        FROM wholesale_leads WHERE organization_id=? AND archived_at IS NULL
                        ORDER BY updated_at DESC LIMIT 20");
                    $stmt->execute([$org]);
                    [$lines,$used]=fmuk_render_business_rows($topic,$stmt->fetchAll(PDO::FETCH_ASSOC));
                    }
            }
            $served[]=$topic;
            $sections[]=$topic.': '.($lines?implode("\n",array_slice($lines,0,26)):'No matching recorded data was available. Do not assume zero or absence of activity.');
            $sources=array_merge($sources,$used);
        }catch(Throwable $error){
            error_log('Food Monster read evidence unavailable for '.$topic.': '.get_class($error));
            $sections[]=$topic.': Source temporarily unavailable. Do not invent data.';
        }
    }
    $capabilities=fmuk_capabilities($user,$voice,$ownerVoiceVerified);
    $intro='Available authenticated read domains for this speaker: '.($capabilities?implode(', ',$capabilities):'none');
    $context=$intro."\n".implode("\n\n",$sections);
    return ['context'=>mb_substr($context,0,7000,'UTF-8'),
        'sources'=>array_values(array_unique(array_slice($sources,0,40))), 'topics'=>$served];
}

/** Respond directly from Gelato's server. Never send raw records to a hosted LLM. */
/**
 * Turn scoped, permission-filtered facts become a conversational answer.
 * The broker's internal diagnostics and missing-source sentinels must never
 * be delivered verbatim as if they were a restaurant answer.
 */
function fmuk_reply_from_evidence(array $evidence,string $question): string
{
    $context=(string)($evidence['context']??'');
    $firstNewline=strpos($context,"\n");
    $body=$firstNewline===false?'':substr($context,$firstNewline+1);
    $blocks=preg_split('/\n\s*\n/u',$body)?:[];
    $available=[];$denied=[];$missing=[];$unavailable=[];
    foreach($blocks as $block){
        if(!preg_match('/^([a-z_]+):\s*(.*)$/us',trim($block),$match))continue;
        $topic=str_replace('_',' ',$match[1]);$detail=trim($match[2]);
        if(str_starts_with($detail,'NOT AUTHORIZED')){$denied[]=$topic;continue;}
        if(str_starts_with($detail,'No matching recorded data')){$missing[]=$topic;continue;}
        if(str_starts_with($detail,'Source temporarily unavailable')){$unavailable[]=$topic;continue;}
        if($detail!=='')$available[$topic]=$detail;
    }
    if(!$available){
        if($denied)
            return 'I cannot access the '.implode(' and ',$denied).' records with this session’s current permissions or voice verification. General questions are still welcome.';
        if($unavailable)
            return 'That Gelato data source is temporarily unavailable. Please try again in a moment.';
        if($missing){
            if(in_array('menu',$missing,true))
                return 'I could not find a matching item in Gelato’s active menu. Can you confirm the item name or spelling?';
            return 'I could not find matching records for that request. Can you tell me which item, person, or date you mean?';
        }
        return 'Which Gelato item, person, or date would you like me to check?';
    }
    if(count($available)===1&&isset($available['menu'])){
        $lines=explode("\n",$available['menu']);
        if(count($lines)===1&&preg_match('/^(.+?)\s+\|\s+prices:\s*(.+?)\s+\|\s+ingredients:\s*(.*)$/us',$lines[0],$match)){
            $name=trim($match[1]);$prices=trim($match[2]);$ingredients=trim($match[3]);
            if(preg_match('/\b(?:ingredients?|toppings?|contains?|what.s in|made of)\b/iu',$question)){
                return $ingredients!==''
                    ?'Gelato’s active menu lists these ingredients for '.$name.': '.$ingredients.'.'
                    :'I found '.$name.' in Gelato’s menu, but its ingredients are not recorded there.';
            }
            if(preg_match('/\b(?:price|cost|how much)\b/iu',$question))
                return $prices!=='not recorded'?'Gelato lists '.$name.' at '.$prices.'.'
                    :'I found '.$name.', but its price is not recorded.';
            return 'Gelato’s active menu lists '.$name.'.'
                .($ingredients!==''?' Ingredients: '.$ingredients.'.':'')
                .($prices!=='not recorded'?' Prices: '.$prices.'.':'');
        }
    }
    $sentences=[];
    foreach($available as $topic=>$detail)$sentences[]=ucfirst($topic).":\n".$detail;
    return implode("\n\n",$sentences);
}

function fmuk_answer(PDO $pdo,array $user,string $question,bool $voice=false,bool $ownerVoiceVerified=false): array
{
    $capabilities=fmuk_capabilities($user,$voice,$ownerVoiceVerified);
    if(fmuk_capability_question($question)){
        $answer=$capabilities
            ?'I can read your authorized Gelato information, including '.implode(', ',$capabilities).'. I can also help with actions through the available skills, subject to your permissions and required approvals.'
            :'This session does not have access to restaurant records. Ask a Gelato administrator to review your permissions.';
        return ['skill'=>'food_monster.unified_capabilities','answer'=>$answer,'sources'=>[],
            'data'=>['evidenceTopics'=>[],'readOnly'=>true]];
    }
    $evidence=fmuk_evidence($pdo,$user,$question,$voice,$ownerVoiceVerified);
    $answer=fmuk_reply_from_evidence($evidence,$question);
    return ['skill'=>'food_monster.unified_knowledge','answer'=>mb_substr($answer,0,6900,'UTF-8'),
        'sources'=>$evidence['sources'],'data'=>['evidenceTopics'=>$evidence['topics'],'readOnly'=>true]];
}
