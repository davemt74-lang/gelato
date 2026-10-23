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
    ];
    $topics=[];
    foreach($patterns as $name=>$pattern)if(preg_match($pattern,$text))$topics[]=$name;
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

function fmuk_roster_question(string $question): bool
{
    return preg_match('/\b(?:who (?:are|is) (?:the |our )?(?:employees|staff|crew)|(?:show|list) (?:me )?(?:the |our )?(?:employees|staff|crew|team)|employee (?:directory|roster|list)|staff (?:directory|roster|list))\b/iu',$question)===1;
}

function fmuk_prefers_broker(string $question,array $user): bool
{
    $topics=fmuk_topics($question);
    if(!$topics&&!fmuk_capability_question($question))return false;
    return fmuk_capability_question($question)||fmuk_menu_overview_question($question)
        ||fmuk_roster_question($question)||count($topics)>1
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
        default=>false,
    };
}

/** Visible capability names only; never expose role-inaccessible tools. */
function fmuk_capabilities(array $user,bool $voice,bool $ownerVoiceVerified): array
{
    $names=['menu'=>'active menu and prices','recipes'=>'approved recipes and production standards',
        'schedule'=>'shift schedules','employees'=>'staff roster','inventory'=>'inventory and stock',
        'tasks'=>'restaurant tasks','sales'=>'recorded sales','crm'=>'customer CRM',
        'equipment'=>'equipment and maintenance','purchasing'=>'vendors and purchasing',
        'catering'=>'catering','wholesale'=>'wholesale'];
    $result=[];
    foreach($names as $topic=>$description)
        if(fmuk_allowed($user,$topic,$voice,$ownerVoiceVerified))$result[]=$description;
    if(!$voice||$ownerVoiceVerified){
        $specialists=[
            ['kds.view'=>'kitchen display tickets'],
            ['online_orders.fulfill'=>'online orders'],
            ['timeclock.agent'=>'time clock and attendance'],
        ];
        foreach($specialists as $entry)foreach($entry as $permission=>$label)
            if(app_has_permission($permission,$user))$result[]=$label;
    }
    return $result;
}

function fmuk_menu_items(PDO $pdo,int $org,string $question): array
{
    $subject=fm13b_subject($question);
    // Menu-wide requests return an actual bounded menu slice. A specific
    // item request is searched against active menu evidence first.
    $overview=fmuk_menu_overview_question($question)||preg_match('/\b(?:menu|specials?|options?|what (?:do|can) (?:you|we) (?:have|serve|offer))\b/iu',$question)===1
        &&mb_strlen($subject,'UTF-8')<3;
    if(!$overview&&mb_strlen($subject,'UTF-8')>=2)
        return menu_training_search_items($pdo,$org,$subject,12);
    $stmt=$pdo->prepare("SELECT i.id FROM menu_items i JOIN menu_sections s ON s.id=i.section_id AND s.organization_id=i.organization_id WHERE i.organization_id=? AND i.is_active=1 AND s.status='active' ORDER BY s.name,i.name LIMIT 20");
    $stmt->execute([$org]);
    return menu_training_items_by_ids($pdo,$org,array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)),20);
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
function fmuk_answer(PDO $pdo,array $user,string $question,bool $voice=false,bool $ownerVoiceVerified=false): array
{
    $capabilities=fmuk_capabilities($user,$voice,$ownerVoiceVerified);
    if(fmuk_capability_question($question)){
        $answer=$capabilities
            ?'Yes. Through this Gelato Agent Chat I can read your authorized restaurant information: '.implode(', ',$capabilities).'. Ask a specific question and I will check the current records. Other operational actions use Gelato\'s existing permission and approval workflows.'
            :'This session does not have permission to read restaurant records. A Gelato administrator can review your access.';
        return ['skill'=>'food_monster.unified_capabilities','answer'=>$answer,'sources'=>[],
            'data'=>['evidenceTopics'=>[],'readOnly'=>true]];
    }else{
        $evidence=fmuk_evidence($pdo,$user,$question,$voice,$ownerVoiceVerified);
        $sections=explode("\n",(string)$evidence['context']);
        array_shift($sections); // Capability inventory is separate from the actual response.
        $detail=trim(implode("\n",$sections));
        $answer="Here is what Gelato currently has recorded for your question:\n".$detail;
        if($detail==='')
            $answer='I could not match this request to an authorized read-only information source. Please specify the relevant item, person, department or date.';
        if(str_contains((string)$evidence['context'],'menu:'))
            $answer.="\nAllergen and ingredient details require verification against current recipes, supplier labels and cross-contact conditions.";
    }
    return ['skill'=>'food_monster.unified_knowledge','answer'=>mb_substr($answer,0,6900,'UTF-8'),
        'sources'=>$evidence['sources'],'data'=>['evidenceTopics'=>$evidence['topics'],'readOnly'=>true]];
}
