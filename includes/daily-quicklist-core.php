<?php
declare(strict_types=1);

require_once __DIR__ . '/restaurant-brain.php';
require_once __DIR__ . '/menu-manager-core.php';
require_once __DIR__ . '/operations-core.php';

/** Quicklist access is for signed-in operational staff, not external customer accounts. */
function daily_quicklist_can_view(array $user): bool
{
    foreach (['tasks.self','tasks.view','tasks.manage','inventory.view','inventory.manage','prep.intelligence.view','prep.intelligence.manage'] as $permission) {
        if (app_has_permission($permission,$user)) return true;
    }
    return false;
}
function daily_quicklist_can_manage(array $user): bool
{
    foreach (['tasks.manage','inventory.manage','prep.intelligence.manage'] as $permission) {
        if (app_has_permission($permission,$user)) return true;
    }
    return false;
}
function daily_quicklist_ready(PDO $pdo): bool
{
    return restaurant_brain_table_ready($pdo,'daily_quicklist_items')
        && restaurant_brain_table_ready($pdo,'daily_quicklist_events');
}
function daily_quicklist_date(mixed $value): string
{
    $date = trim((string)$value);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Choose a valid calendar date.');
    return $date;
}
function daily_quicklist_title(mixed $value): string
{
    $name = trim((string)$value);
    if ($name === '' || mb_strlen($name,'UTF-8') > 220) throw new InvalidArgumentException('Item name must be between 1 and 220 characters.');
    return $name;
}
function daily_quicklist_normalize(string $name): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u',' ',$name) ?? $name),'UTF-8');
}
/** Numeric recipe amounts only; qualitative measures are deliberately never invented. */
function daily_quicklist_numeric_quantity(mixed $raw): ?float
{
    if(is_int($raw)||is_float($raw))return is_finite((float)$raw)&&$raw>=0?(float)$raw:null;
    if(!is_string($raw))return null;
    $value=trim($raw);
    if($value==='')return null;
    foreach(['½'=>' 1/2','⅓'=>' 1/3','⅔'=>' 2/3','¼'=>' 1/4','¾'=>' 3/4','⅛'=>' 1/8','⅜'=>' 3/8','⅝'=>' 5/8','⅞'=>' 7/8'] as $glyph=>$fraction)
        $value=str_replace($glyph,$fraction,$value);
    $value=trim(preg_replace('/\s+/u',' ',$value)??$value);
    if(is_numeric($value)){$n=(float)$value;return is_finite($n)&&$n>=0?$n:null;}
    if(preg_match('~^(\d+(?:\.\d+)?)\s+(\d+)/(\d+)$~D',$value,$m))
        return (int)$m[3]>0?(float)$m[1]+(int)$m[2]/(int)$m[3]:null;
    if(preg_match('~^(\d+)/(\d+)$~D',$value,$m))
        return (int)$m[2]>0?(int)$m[1]/(int)$m[2]:null;
    return null;
}
function daily_quicklist_format_quantity(float $value): string
{
    return rtrim(rtrim(number_format($value,8,'.',''),'0'),'.')?:'0';
}
/** Calculate a display-only batch projection from saved canonical recipe content. */
function daily_quicklist_scale_recipe(array $recipe,?float $targetQuantity,?string $targetUnit): array
{
    $base=$recipe['yieldQuantity']??null;
    $unit=(string)($recipe['yieldUnit']??'');
    $ingredients=is_array($recipe['ingredients']??null)?$recipe['ingredients']:[];
    $unavailable=['scalable'=>false,'baseQuantity'=>$base,'targetQuantity'=>$targetQuantity,
        'unit'=>$unit,'factor'=>null,'ingredients'=>$ingredients,'unscaledCount'=>0];
    if($base===null||!is_numeric($base)||(float)$base<=0)
        return $unavailable+['warning'=>'Set a numeric yield in Recipe Library before scaling this recipe.'];
    if($targetQuantity!==null&&(!is_finite($targetQuantity)||$targetQuantity<=0||$targetQuantity>10000))
        throw new InvalidArgumentException('Target yield must be greater than zero and no more than 10,000.');
    if($targetQuantity!==null&&$targetUnit!==$unit)
        return $unavailable+['warning'=>'The saved recipe yield unit changed. Save a new target to scale again.'];
    $target=$targetQuantity??(float)$base;$factor=$target/(float)$base;
    if(!is_finite($factor)||$factor>1000)throw new InvalidArgumentException('The requested batch multiplier is too large.');
    $scaled=[];$unscaled=0;
    foreach($ingredients as $ingredient){
        if(!is_array($ingredient)){$scaled[]=$ingredient;$unscaled++;continue;}
        $copy=$ingredient;
        if(!array_key_exists('quantity',$ingredient)){$scaled[]=$copy;$unscaled++;continue;}
        $n=daily_quicklist_numeric_quantity($ingredient['quantity']);
        if($n===null){$scaled[]=$copy;$unscaled++;continue;}
        if(abs($factor-1.0)>0.00000001)$copy['quantity']=daily_quicklist_format_quantity($n*$factor);
        $scaled[]=$copy;
    }
    return ['scalable'=>true,'baseQuantity'=>(float)$base,'targetQuantity'=>$target,
        'unit'=>$unit,'factor'=>$factor,'ingredients'=>$scaled,'unscaledCount'=>$unscaled,'warning'=>null];
}
function daily_quicklist_recipe_projections(array $items,array $recipes): array
{
    $index=[];foreach($recipes as $recipe)$index[$recipe['id']]=$recipe;
    $result=[];
    foreach($items as $item){
        $recipeId=$item['recipeId']??null;
        if($item['type']!=='prep'||!$recipeId||!isset($index[$recipeId]))continue;
        $target=$item['targetYieldQuantity']===null?null:(float)$item['targetYieldQuantity'];
        $result[$item['id']]=daily_quicklist_scale_recipe($index[$recipeId],$target,$item['targetYieldUnit']);
    }
    return $result;
}
function daily_quicklist_recipe_public(array $row): array
{
    return [
        'id'=>(string)$row['public_id'], 'name'=>(string)$row['name'],
        'description'=>(string)($row['description']??''),
        'yieldQuantity'=>$row['yield_quantity']===null?null:(float)$row['yield_quantity'],
        'yieldUnit'=>(string)($row['yield_unit']??''),
        'ingredients'=>json_decode((string)($row['ingredients_json']??'[]'),true)?:[],
        'instructions'=>json_decode((string)($row['instructions_json']??'[]'),true)?:[],
        'notes'=>(string)($row['notes']??''),
    ];
}
function daily_quicklist_recipes(PDO $pdo,int $org): array
{
    if (!restaurant_brain_table_ready($pdo,'recipes')) return [];
    $q=$pdo->prepare("SELECT public_id,name,description,yield_quantity,yield_unit,ingredients_json,instructions_json,notes
        FROM recipes WHERE organization_id=? AND status='active' AND archived_at IS NULL ORDER BY name LIMIT 300");
    $q->execute([$org]);
    return array_map('daily_quicklist_recipe_public',$q->fetchAll());
}
/** Suggestions are sourced from published menu ingredients, never invented quantities. */
function daily_quicklist_menu_suggestions(PDO $pdo,int $org,array $recipes): array
{
    if (!menu_manager_ready($pdo)) return [];
    $counts=[];$dishNames=[];
    foreach (menu_manager_public_sections($pdo,$org) as $section) {
        foreach ($section['items'] as $dish) {
            $dishName=trim((string)($dish['name']??''));
            if ($dishName!=='') $dishNames[$dishName]=true;
            foreach (array_unique(array_map('strval',(array)($dish['ingredients']??[]))) as $ingredient) {
                $name=trim($ingredient);$key=daily_quicklist_normalize($name);
                if ($key==='') continue;
                if (!isset($counts[$key])) $counts[$key]=['name'=>$name,'menuCount'=>0];
                $counts[$key]['menuCount']++;
            }
        }
    }
    uasort($counts,static fn(array $a,array $b):int=>$b['menuCount']<=>$a['menuCount'] ?: strcmp($a['name'],$b['name']));
    $byName=[];$ambiguous=[];
    foreach ($recipes as $recipe) {
        $key=daily_quicklist_normalize($recipe['name']);
        if(isset($byName[$key]))$ambiguous[$key]=true;
        $byName[$key]=$recipe['id'];
    }
    $result=[];
    foreach (array_slice($counts,0,35,true) as $key=>$data) {
        $result[]=['title'=>'Prep '.$data['name'],'source'=>$data['menuCount'].' published menu item'.($data['menuCount']===1?'':'s'),
            'recipeId'=>isset($byName[$key])&&!isset($ambiguous[$key])?$byName[$key]:null];
    }
    // A published dish with an exact, unambiguous saved recipe can be prepped directly.
    foreach (array_keys($dishNames) as $dishName) {
        $key=daily_quicklist_normalize($dishName);
        if (isset($byName[$key])&&!isset($ambiguous[$key])) {
            $result[]=['title'=>'Prep '.$dishName,'source'=>'Published menu recipe','recipeId'=>$byName[$key]];
        }
    }
    return array_slice($result,0,60);
}
function daily_quicklist_item_public(array $row): array
{
    return [
        'id'=>(string)$row['public_id'],'type'=>(string)$row['list_type'],'date'=>$row['list_date'],
        'title'=>(string)$row['title'],'status'=>(string)$row['status'],
        'quantityNote'=>(string)($row['quantity_note']??''),
        'recipeId'=>$row['recipe_public_id']?:null,
        'targetYieldQuantity'=>$row['target_yield_quantity']===null?null:(float)$row['target_yield_quantity'],
        'targetYieldUnit'=>$row['target_yield_unit']?:null,
        'inventoryId'=>$row['source_inventory_public_id']?:null,
        'elapsedSeconds'=>(int)$row['elapsed_seconds']
            + ($row['timer_started_at']!==null ? max(0,(int)($row['running_seconds']??0)) : 0),
        'timerRunning'=>$row['timer_started_at']!==null,
        'adminNote'=>(string)($row['admin_note']??''),
        'createdAt'=>(string)$row['created_at'],'updatedAt'=>(string)$row['updated_at'],
        'updatedBy'=>$row['updated_by']===null?null:(int)$row['updated_by'],
    ];
}
function daily_quicklist_items(PDO $pdo,int $org,string $date): array
{
    $q=$pdo->prepare("SELECT q.*,IF(q.timer_started_at IS NULL,0,
        GREATEST(0,TIMESTAMPDIFF(SECOND,q.timer_started_at,UTC_TIMESTAMP(6)))) AS running_seconds
        FROM daily_quicklist_items q WHERE q.organization_id=? AND q.archived_at IS NULL
        AND ((q.list_type='prep' AND q.list_date=?) OR q.list_type='inventory')
        ORDER BY q.list_type,q.created_at,q.id");
    $q->execute([$org,$date]);
    return array_map('daily_quicklist_item_public',$q->fetchAll());
}
function daily_quicklist_inventory(PDO $pdo,int $org): array
{
    if (!restaurant_brain_table_ready($pdo,'inventory_items')) return [];
    $rows=operations_inventory_list($pdo,$org,'',false,400);
    return array_map(static fn(array $r):array=>[
        'id'=>(string)$r['public_id'],'name'=>(string)$r['name'],
        'unit'=>(string)($r['base_unit']??''),'category'=>(string)($r['category']??''),
        'onHand'=>(float)$r['on_hand_quantity'],
        'reorderPoint'=>$r['reorder_point']===null?null:(float)$r['reorder_point'],
    ],$rows);
}
function daily_quicklist_row_lock(PDO $pdo,int $org,string $id): array
{
    $q=$pdo->prepare('SELECT * FROM daily_quicklist_items WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1 FOR UPDATE');
    $q->execute([$org,$id]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('This list item is no longer available. Refresh and try again.');
    return $row;
}
function daily_quicklist_event(PDO $pdo,int $org,int $item,int $actor,string $event,array $details=[]): void
{
    $pdo->prepare('INSERT INTO daily_quicklist_events (organization_id,item_id,actor_user_id,event_type,detail_json) VALUES (?,?,?,?,?)')
        ->execute([$org,$item,$actor,$event,$details?json_encode($details,JSON_THROW_ON_ERROR):null]);
}
function daily_quicklist_notify_managers(PDO $pdo,int $org,int $actor,string $title,string $message): int
{
    $q=$pdo->prepare("SELECT DISTINCT om.user_id FROM organization_memberships om
        INNER JOIN users u ON u.id=om.user_id AND u.status='active' AND u.archived_at IS NULL
        INNER JOIN user_roles ur ON ur.membership_id=om.id AND ur.revoked_at IS NULL
        INNER JOIN roles r ON r.id=ur.role_id AND r.organization_id=om.organization_id
        LEFT JOIN role_permissions rp ON rp.role_id=r.id
        LEFT JOIN permissions p ON p.id=rp.permission_id
        WHERE om.organization_id=? AND om.status='active' AND om.user_id<>?
        AND (r.is_owner_role=1 OR p.permission_key IN ('tasks.manage','inventory.manage','prep.intelligence.manage'))");
    $q->execute([$org,$actor]);$recipients=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    if(!$recipients)return 0;
    $insert=$pdo->prepare("INSERT INTO notifications (organization_id,user_id,notification_type,title,message,action_url)
        VALUES (?,?,'daily_quicklist.update',?,?,'daily-prep.php')");
    foreach($recipients as $recipient)$insert->execute([$org,$recipient,$title,$message]);
    return count($recipients);
}
function daily_quicklist_add(PDO $pdo,int $org,int $actor,array $input,bool $canReadRecipes): string
{
    $type=(string)($input['type']??'');
    if(!in_array($type,['prep','inventory'],true))throw new InvalidArgumentException('Choose Prep or Inventory.');
    $title=daily_quicklist_title($input['title']??'');
    $date=$type==='prep'?daily_quicklist_date($input['date']??''):null;
    $inventoryId=$type==='inventory'?trim((string)($input['inventoryId']??'')):'';
    $recipeId=$type==='prep'?trim((string)($input['recipeId']??'')):'';
    $qty=mb_substr(trim((string)($input['quantityNote']??'')),0,120,'UTF-8');
    if($inventoryId!==''){
        $q=$pdo->prepare('SELECT name FROM inventory_items WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');
        $q->execute([$org,$inventoryId]);$found=$q->fetchColumn();
        if($found===false)throw new InvalidArgumentException('That inventory item is unavailable.');
        $title=(string)$found;
    }
    if($recipeId!==''){
        if(!$canReadRecipes)throw new InvalidArgumentException('Recipe access is required to link a recipe.');
        $q=$pdo->prepare("SELECT name FROM recipes WHERE organization_id=? AND public_id=? AND status='active' AND archived_at IS NULL LIMIT 1");
        $q->execute([$org,$recipeId]);
        if($q->fetchColumn()===false)throw new InvalidArgumentException('Choose an active saved recipe.');
    }
    if($type==='inventory'&&$inventoryId!==''){
        $q=$pdo->prepare("SELECT public_id FROM daily_quicklist_items WHERE organization_id=? AND list_type='inventory'
            AND source_inventory_public_id=? AND archived_at IS NULL AND status<>'received' LIMIT 1");
        $q->execute([$org,$inventoryId]);$existing=$q->fetchColumn();
        if($existing!==false)throw new InvalidArgumentException('This item is already on the Need More list.');
    }
    $id=restaurant_brain_public_id('quick');
    $pdo->prepare('INSERT INTO daily_quicklist_items (organization_id,public_id,list_type,list_date,title,source_inventory_public_id,recipe_public_id,status,quantity_note,created_by,updated_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$org,$id,$type,$date,$title,$inventoryId?:null,$recipeId?:null,
            $type==='prep'?'todo':'need_more',$qty?:null,$actor,$actor]);
    daily_quicklist_event($pdo,$org,(int)$pdo->lastInsertId(),$actor,'added',['title'=>$title,'type'=>$type]);
    if($type==='inventory')daily_quicklist_notify_managers($pdo,$org,$actor,'Need More: '.$title,'A team member added '.$title.' to the Need More list.');
    return $id;
}
function daily_quicklist_seed(PDO $pdo,int $org,int $actor,string $date,bool $canReadRecipes): int
{
    $date=daily_quicklist_date($date);
    $recipes=$canReadRecipes?daily_quicklist_recipes($pdo,$org):[];
    $suggestions=array_slice(daily_quicklist_menu_suggestions($pdo,$org,$recipes),0,12);
    $q=$pdo->prepare("SELECT LOWER(title) FROM daily_quicklist_items WHERE organization_id=? AND list_type='prep' AND list_date=? AND archived_at IS NULL");
    $q->execute([$org,$date]);$existing=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);
    $created=0;
    foreach($suggestions as $suggestion){
        $title=(string)$suggestion['title'];$key=mb_strtolower($title,'UTF-8');
        if(isset($existing[$key]))continue;
        daily_quicklist_add($pdo,$org,$actor,['type'=>'prep','date'=>$date,
            'title'=>$title,'recipeId'=>$canReadRecipes?($suggestion['recipeId']??''):''],$canReadRecipes);
        $existing[$key]=true;$created++;
    }
    return $created;
}
function daily_quicklist_mutate(PDO $pdo,int $org,int $actor,string $action,array $input,bool $manager,bool $canReadRecipes=false): array
{
    $id=trim((string)($input['id']??''));
    if($id==='')throw new InvalidArgumentException('Choose a list item.');
    $pdo->beginTransaction();
    try{
        $row=daily_quicklist_row_lock($pdo,$org,$id);
        $previous=['status'=>$row['status'],'elapsedSeconds'=>$row['elapsed_seconds'],'running'=>$row['timer_started_at']!==null];
        $event=$action;$newStatus=(string)$row['status'];
        $elapsed=(int)$row['elapsed_seconds'];
        $started=$row['timer_started_at'];
        if($started!==null){
            $q=$pdo->prepare('SELECT GREATEST(0,TIMESTAMPDIFF(SECOND,?,UTC_TIMESTAMP(6)))');
            $q->execute([$started]);$running=(int)$q->fetchColumn();
        } else $running=0;
        if($action==='toggle'){
            if($row['list_type']!=='prep')throw new InvalidArgumentException('Use inventory status controls for this item.');
            $newStatus=$row['status']==='done'?'todo':'done';
            if($newStatus==='done'){$elapsed+=$running;$started=null;}
        }elseif($action==='timer'){
            if($row['list_type']!=='prep')throw new InvalidArgumentException('Timers are available for prep items.');
            $mode=(string)($input['mode']??'');
            if(!in_array($mode,['start','pause','resume','stop'],true))throw new InvalidArgumentException('Unknown timer control.');
            if($mode==='start'){$elapsed=0;$started=gmdate('Y-m-d H:i:s');$newStatus='in_progress';}
            elseif($mode==='resume'){if($started!==null)throw new InvalidArgumentException('Timer is already running.');$started=gmdate('Y-m-d H:i:s');$newStatus='in_progress';}
            elseif($mode==='pause'){if($started===null)throw new InvalidArgumentException('Timer is not running.');$elapsed+=$running;$started=null;$newStatus='paused';}
            elseif($mode==='stop'){$elapsed+=$running;$started=null;$newStatus='todo';}
            $event='timer_'.$mode;
        }elseif($action==='set_target'){
            if(!$canReadRecipes)throw new InvalidArgumentException('Recipe Library access is required to set a batch target.');
            if($row['list_type']!=='prep'||!$row['recipe_public_id'])
                throw new InvalidArgumentException('Only prep items with a linked recipe can set a target.');
            $raw=$input['targetYieldQuantity']??null;
            if(!is_numeric($raw)||!is_finite((float)$raw)||(float)$raw<0.001||(float)$raw>10000)
                throw new InvalidArgumentException('Target yield must be between 0.001 and 10,000.');
            $quantity=round((float)$raw,3);
            $q=$pdo->prepare("SELECT yield_quantity,yield_unit FROM recipes
                WHERE organization_id=? AND public_id=? AND status='active' AND archived_at IS NULL LIMIT 1");
            $q->execute([$org,$row['recipe_public_id']]);$recipe=$q->fetch();
            if(!$recipe||$recipe['yield_quantity']===null||(float)$recipe['yield_quantity']<=0)
                throw new InvalidArgumentException('Set a valid recipe yield in Recipe Library first.');
            if($quantity/(float)$recipe['yield_quantity']>1000)
                throw new InvalidArgumentException('The requested batch multiplier is too large.');
            $unit=(string)($recipe['yield_unit']??'');
            $pdo->prepare('UPDATE daily_quicklist_items SET target_yield_quantity=?,target_yield_unit=?,updated_by=? WHERE id=?')
                ->execute([$quantity,$unit,$actor,$row['id']]);
            $event='target_set';
            $previous['targetYieldQuantity']=$row['target_yield_quantity']===null?null:(float)$row['target_yield_quantity'];
            $previous['targetYieldUnit']=$row['target_yield_unit'];
        }elseif($action==='archive'){
            $pdo->prepare('UPDATE daily_quicklist_items SET archived_at=UTC_TIMESTAMP(6),updated_by=? WHERE id=?')->execute([$actor,$row['id']]);
        }elseif($action==='update'){
            if(!$manager)throw new InvalidArgumentException('Manager permission is required for status and notes.');
            $valid=$row['list_type']==='prep'?['todo','in_progress','paused','done']:['need_more','ordered','received'];
            $newStatus=(string)($input['status']??$row['status']);
            if(!in_array($newStatus,$valid,true))throw new InvalidArgumentException('Invalid status for this list.');
            $note=mb_substr(trim((string)($input['adminNote']??'')),0,2000,'UTF-8');
            if($newStatus==='done'||$newStatus==='received'){$elapsed+=$running;$started=null;}
            $pdo->prepare('UPDATE daily_quicklist_items SET admin_note=?,updated_by=? WHERE id=?')->execute([$note?:null,$actor,$row['id']]);
        }elseif($action==='notify'){
            if(!$manager)throw new InvalidArgumentException('Manager permission is required to notify the team.');
            $recipients=daily_quicklist_notify_managers($pdo,$org,$actor,'Daily list: '.$row['title'],'Please check the status of '.$row['title'].' in Daily Prep.');
            daily_quicklist_event($pdo,$org,(int)$row['id'],$actor,'notified',['recipients'=>$recipients]);
            $pdo->commit();return ['id'=>$id,'recipients'=>$recipients];
        }else throw new InvalidArgumentException('Unsupported list action.');
        if($action!=='archive'){
            $pdo->prepare('UPDATE daily_quicklist_items SET status=?,elapsed_seconds=?,timer_started_at=?,updated_by=? WHERE id=?')
                ->execute([$newStatus,$elapsed,$started,$actor,$row['id']]);
        }
        daily_quicklist_event($pdo,$org,(int)$row['id'],$actor,$event,['before'=>$previous,'after'=>['status'=>$newStatus,'elapsedSeconds'=>$elapsed,'running'=>$started!==null,'adminNote'=>$action==='update'?$note:null,'targetYieldQuantity'=>$action==='set_target'?$quantity:null,'targetYieldUnit'=>$action==='set_target'?$unit:null]]);
        if($row['list_type']==='inventory'&&$action==='update'&&$row['status']!==$newStatus){
            daily_quicklist_notify_managers($pdo,$org,$actor,'Inventory update: '.$row['title'],$row['title'].' is now '.$newStatus.'.');
        }
        $pdo->commit();return ['id'=>$id,'status'=>$newStatus];
    }catch(Throwable $exception){if($pdo->inTransaction())$pdo->rollBack();throw $exception;}
}
function daily_quicklist_history(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare('SELECT id FROM daily_quicklist_items WHERE organization_id=? AND public_id=? LIMIT 1');
    $q->execute([$org,$publicId]);$item=$q->fetchColumn();
    if($item===false)throw new InvalidArgumentException('Item not found.');
    $q=$pdo->prepare('SELECT e.event_type,e.detail_json,e.created_at,u.display_name
        FROM daily_quicklist_events e LEFT JOIN users u ON u.id=e.actor_user_id
        WHERE e.organization_id=? AND e.item_id=? ORDER BY e.id DESC LIMIT 80');
    $q->execute([$org,(int)$item]);
    return array_map(static fn(array $e):array=>['event'=>$e['event_type'],'details'=>json_decode((string)($e['detail_json']??'{}'),true)?:[],
        'when'=>$e['created_at'],'by'=>$e['display_name']??'System'],$q->fetchAll());
}
