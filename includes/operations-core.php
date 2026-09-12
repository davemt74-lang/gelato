<?php
declare(strict_types=1);

require_once __DIR__ . '/restaurant-brain.php';

function operations_core_ready(PDO $pdo): bool
{
    foreach (['inventory_items','inventory_item_sources','inventory_transactions','task_categories','restaurant_tasks','restaurant_task_assignments','restaurant_task_time_entries','restaurant_task_events','restaurant_task_proofs'] as $table) {
        if (!restaurant_brain_table_ready($pdo, $table)) return false;
    }
    return true;
}

function operations_slug(string $value): string
{
    $value=mb_strtolower(trim($value),'UTF-8');
    $value=preg_replace('/[^a-z0-9]+/u','-',$value)??'';
    return trim($value,'-') ?: 'item';
}

function operations_normalized_ingredient(string $value): string
{
    $value=mb_strtolower(trim($value),'UTF-8');
    $value=preg_replace('/\s+/u',' ',$value)??$value;
    $aliases=[
        'mozzarella cheese'=>'mozzarella','pure mozzarella cheese'=>'mozzarella','melted mozzarella'=>'mozzarella',
        'parmesan cheese'=>'parmesan','grated parmesan'=>'parmesan','fresh mushrooms'=>'mushrooms','fresh mushroom'=>'mushrooms',
        'fresh onions'=>'onions','fresh onion'=>'onions','onion'=>'onions','green pepper'=>'green peppers','black olive'=>'black olives',
        'house-made ranch'=>'ranch','house made ranch'=>'ranch','whole milk'=>'milk','granulated sugar'=>'sugar'
    ];
    return $aliases[$value] ?? $value;
}

function operations_parse_ingredient(mixed $ingredient): ?array
{
    if (is_string($ingredient)) {
        $text=trim($ingredient); if($text==='') return null;
        if(preg_match('/^([0-9]+(?:\.[0-9]+)?)\s+([A-Za-z][A-Za-z .-]{0,30})\s+(.+)$/u',$text,$m)) return ['name'=>trim($m[3]),'quantity'=>(float)$m[1],'unit'=>trim($m[2])];
        return ['name'=>$text,'quantity'=>null,'unit'=>''];
    }
    if(!is_array($ingredient)) return null;
    $name=trim((string)($ingredient['name']??$ingredient['ingredient']??$ingredient['item']??$ingredient['description']??''));
    if($name===''&&isset($ingredient[0])&&is_string($ingredient[0])) return operations_parse_ingredient($ingredient[0]);
    if($name==='') return null;
    $quantity=$ingredient['quantity']??$ingredient['amount']??$ingredient['qty']??null;
    if(is_string($quantity)&&!is_numeric($quantity)){if(preg_match('/([0-9]+(?:\.[0-9]+)?)/',$quantity,$m))$quantity=(float)$m[1];else $quantity=null;}
    return ['name'=>$name,'quantity'=>is_numeric($quantity)?(float)$quantity:null,'unit'=>trim((string)($ingredient['unit']??$ingredient['measure']??''))];
}

function operations_ensure_default_categories(PDO $pdo,int $organizationId,?int $userId=null): void
{
    $defaults=[
        ['prep','Prep','Food and product preparation','prep',10],['opening','Opening','Opening shift tasks','open',20],['closing','Closing','Closing shift tasks','close',30],['cleaning','Cleaning','Cleaning and sanitation tasks','clean',40],['inventory','Inventory','Counts, receiving and stock tasks','inventory',50],['catering','Catering','Catering execution tasks','catering',60],['wholesale','Wholesale','Wholesale production and fulfillment','wholesale',70],['maintenance','Maintenance','Equipment and facility maintenance','maintenance',80],['training','Training','Employee training and certifications','training',90],['manager','Manager','Manager and administrative tasks','manager',100],['delivery','Delivery','Delivery, loading and logistics','delivery',110],['general','General','General restaurant tasks','task',120]
    ];
    $stmt=$pdo->prepare("INSERT INTO task_categories (organization_id,public_id,name,slug,description,icon,sort_order,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),icon=VALUES(icon),sort_order=VALUES(sort_order),updated_by=COALESCE(VALUES(updated_by),updated_by)");
    foreach($defaults as [$slug,$name,$description,$icon,$sort])$stmt->execute([$organizationId,'cat-'.$slug,$name,$slug,$description,$icon,$sort,$userId,$userId]);
}

function operations_category_by_slug(PDO $pdo,int $organizationId,string $slug): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM task_categories WHERE organization_id=? AND slug=? AND archived_at IS NULL LIMIT 1");$stmt->execute([$organizationId,operations_slug($slug)]);$row=$stmt->fetch();return $row?:null;
}

function operations_create_category(PDO $pdo,int $organizationId,string $name,?int $userId=null,string $description=''): array
{
    $name=mb_substr(trim($name),0,140,'UTF-8');if($name==='')throw new InvalidArgumentException('Task category name is required.');
    $slug=operations_slug($name);$publicId='cat-'.substr(hash('sha256',$organizationId.'|'.$slug),0,20);
    $stmt=$pdo->prepare("INSERT INTO task_categories (organization_id,public_id,name,slug,description,sort_order,created_by,updated_by) VALUES (?,?,?,?,?,999,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),status='active',archived_at=NULL,updated_by=VALUES(updated_by),updated_at=NOW(6)");
    $stmt->execute([$organizationId,$publicId,$name,$slug,$description?:null,$userId,$userId]);
    $row=operations_category_by_slug($pdo,$organizationId,$slug);if(!$row)throw new RuntimeException('Task category could not be loaded.');return $row;
}

function operations_inventory_upsert(PDO $pdo,int $organizationId,string $name,?int $userId=null,array $metadata=[]): array
{
    $normalized=operations_normalized_ingredient($name);$key=operations_slug($normalized);$publicId='inv-'.substr(hash('sha256',$organizationId.'|'.$key),0,20);
    $stmt=$pdo->prepare("INSERT INTO inventory_items (organization_id,public_id,normalized_key,name,source_metadata_json,created_by,updated_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=IF(name='',VALUES(name),name),source_metadata_json=COALESCE(VALUES(source_metadata_json),source_metadata_json),status='active',archived_at=NULL,updated_by=COALESCE(VALUES(updated_by),updated_by),updated_at=NOW(6)");
    $stmt->execute([$organizationId,$publicId,$key,mb_substr($normalized,0,220,'UTF-8'),$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null,$userId,$userId]);
    $load=$pdo->prepare('SELECT * FROM inventory_items WHERE organization_id=? AND normalized_key=? LIMIT 1');$load->execute([$organizationId,$key]);$row=$load->fetch();if(!$row)throw new RuntimeException('Inventory item could not be loaded.');return $row;
}

function operations_sync_inventory_sources(PDO $pdo,int $organizationId,?int $userId=null): array
{
    $menuCount=0;$recipeCount=0;$itemsTouched=[];
    $catalogPath=RESTAURANT_APP_ROOT.'/data/ingredient-catalog.json';
    if(is_file($catalogPath)){
        $catalog=json_decode((string)file_get_contents($catalogPath),true);
        foreach((array)($catalog['ingredients']??[]) as $ingredient){
            $name=trim((string)($ingredient['name']??''));if($name==='')continue;
            $item=operations_inventory_upsert($pdo,$organizationId,$name,$userId,['menuCategories'=>$ingredient['categories']??[]]);$itemsTouched[(int)$item['id']]=true;
            $menuItems=(array)($ingredient['menuItems']??[]);$sourceId='menu:'.((string)($ingredient['id']??operations_slug($name)));
            $stmt=$pdo->prepare("INSERT INTO inventory_item_sources (organization_id,inventory_item_id,source_type,source_public_id,source_name,metadata_json) VALUES (?,?, 'menu',?,?,?) ON DUPLICATE KEY UPDATE source_name=VALUES(source_name),metadata_json=VALUES(metadata_json),updated_at=NOW(6)");
            $stmt->execute([$organizationId,(int)$item['id'],$sourceId,$name,json_encode(['menuItems'=>$menuItems,'allergens'=>$ingredient['allergens']??[]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);$menuCount+=count($menuItems);
        }
    }
    if(restaurant_brain_table_ready($pdo,'recipes')){
        $stmt=$pdo->prepare("SELECT id,public_id,name,ingredients_json FROM recipes WHERE organization_id=? AND status='active' AND archived_at IS NULL");$stmt->execute([$organizationId]);
        foreach($stmt->fetchAll() as $recipe){
            $ingredients=json_decode((string)($recipe['ingredients_json']??'[]'),true);if(!is_array($ingredients))continue;
            foreach($ingredients as $ingredient){$parsed=operations_parse_ingredient($ingredient);if(!$parsed)continue;$item=operations_inventory_upsert($pdo,$organizationId,$parsed['name'],$userId);$itemsTouched[(int)$item['id']]=true;$sourceId='recipe:'.$recipe['public_id'];$source=$pdo->prepare("INSERT INTO inventory_item_sources (organization_id,inventory_item_id,source_type,source_public_id,source_name,quantity_per_source,unit,metadata_json) VALUES (?,?, 'recipe',?,?,?,?,?) ON DUPLICATE KEY UPDATE source_name=VALUES(source_name),quantity_per_source=VALUES(quantity_per_source),unit=VALUES(unit),metadata_json=VALUES(metadata_json),updated_at=NOW(6)");$source->execute([$organizationId,(int)$item['id'],$sourceId,$recipe['name'],$parsed['quantity'],$parsed['unit']?:null,json_encode(['recipePublicId'=>$recipe['public_id'],'recipeName'=>$recipe['name']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);$recipeCount++;}
        }
    }
    $refresh=$pdo->prepare("UPDATE inventory_items i SET menu_source_count=(SELECT COALESCE(SUM(JSON_LENGTH(JSON_EXTRACT(s.metadata_json,'$.menuItems'))),0) FROM inventory_item_sources s WHERE s.inventory_item_id=i.id AND s.source_type='menu'),recipe_source_count=(SELECT COUNT(*) FROM inventory_item_sources s WHERE s.inventory_item_id=i.id AND s.source_type='recipe'),updated_at=NOW(6) WHERE i.organization_id=? AND i.archived_at IS NULL");$refresh->execute([$organizationId]);
    return ['items'=>count($itemsTouched),'menuReferences'=>$menuCount,'recipeReferences'=>$recipeCount];
}

function operations_inventory_list(PDO $pdo,int $organizationId,string $query='',bool $lowOnly=false,int $limit=500): array
{
    $limit=max(1,min(1000,$limit));$q=trim($query);$like='%'.$q.'%';$where="organization_id=? AND archived_at IS NULL";$params=[$organizationId];
    if($q!==''){$where.=' AND (name LIKE ? OR category LIKE ? OR storage_location LIKE ? OR vendor_name LIKE ?)';array_push($params,$like,$like,$like,$like);}
    if($lowOnly)$where.=' AND reorder_point IS NOT NULL AND on_hand_quantity<=reorder_point';
    $stmt=$pdo->prepare("SELECT * FROM inventory_items WHERE {$where} ORDER BY (reorder_point IS NOT NULL AND on_hand_quantity<=reorder_point) DESC,name LIMIT {$limit}");$stmt->execute($params);return $stmt->fetchAll();
}

function operations_inventory_adjust(PDO $pdo,int $organizationId,string $publicId,float $delta,string $type,?int $userId,string $note=''): array
{
    $allowed=['count','receive','use','waste','adjust','transfer'];if(!in_array($type,$allowed,true))throw new InvalidArgumentException('Invalid inventory transaction type.');
    $pdo->beginTransaction();try{$stmt=$pdo->prepare('SELECT * FROM inventory_items WHERE organization_id=? AND public_id=? AND archived_at IS NULL FOR UPDATE');$stmt->execute([$organizationId,$publicId]);$item=$stmt->fetch();if(!$item)throw new RuntimeException('Inventory item not found.');$old=(float)$item['on_hand_quantity'];$new=$type==='count'?$delta:$old+$delta;if($new<0)$new=0;$pdo->prepare('UPDATE inventory_items SET on_hand_quantity=?,last_counted_at=IF(?="count",NOW(6),last_counted_at),updated_by=?,updated_at=NOW(6) WHERE id=?')->execute([$new,$type,$userId,(int)$item['id']]);$pdo->prepare('INSERT INTO inventory_transactions (organization_id,inventory_item_id,transaction_type,quantity_delta,resulting_quantity,unit,note,created_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$organizationId,(int)$item['id'],$type,$type==='count'?$new-$old:$delta,$new,$item['base_unit'],$note?:null,$userId]);$pdo->commit();$item['on_hand_quantity']=$new;return $item;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function operations_task_event(PDO $pdo,int $organizationId,int $taskId,string $event,string $summary,?int $userId=null,array $metadata=[]): void
{
    $stmt=$pdo->prepare('INSERT INTO restaurant_task_events (organization_id,task_id,event_type,summary,metadata_json,actor_user_id) VALUES (?,?,?,?,?,?)');$stmt->execute([$organizationId,$taskId,$event,mb_substr($summary,0,500,'UTF-8'),$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null,$userId]);
}

function operations_task_by_public_id(PDO $pdo,int $organizationId,string $publicId): ?array
{
    $stmt=$pdo->prepare("SELECT t.*,c.name category_name,c.slug category_slug FROM restaurant_tasks t LEFT JOIN task_categories c ON c.id=t.category_id WHERE t.organization_id=? AND t.public_id=? AND t.archived_at IS NULL LIMIT 1");$stmt->execute([$organizationId,$publicId]);$row=$stmt->fetch();return $row?:null;
}

function operations_create_task(PDO $pdo,int $organizationId,array $input,?int $userId=null): array
{
    operations_ensure_default_categories($pdo,$organizationId,$userId);$title=mb_substr(trim((string)($input['title']??'')),0,240,'UTF-8');if($title==='')throw new InvalidArgumentException('Task title is required.');
    $categorySlug=operations_slug((string)($input['category']??'general'));$category=operations_category_by_slug($pdo,$organizationId,$categorySlug)??operations_create_category($pdo,$organizationId,ucwords(str_replace('-',' ',$categorySlug)),$userId);
    $publicId='task-'.bin2hex(random_bytes(10));$priority=(string)($input['priority']??'normal');if(!in_array($priority,['low','normal','high','critical'],true))$priority='normal';$status=(string)($input['status']??'queued');if(!in_array($status,['queued','assigned','accepted','in_progress','blocked','completed','verified','cancelled'],true))$status='queued';
    $stmt=$pdo->prepare("INSERT INTO restaurant_tasks (organization_id,public_id,category_id,source_type,source_public_id,title,description,quantity,unit,station,priority,status,due_at,estimated_minutes,requires_photo,requires_verification,original_transcript,ai_confidence,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$organizationId,$publicId,(int)$category['id'],$input['sourceType']??null,$input['sourcePublicId']??null,$title,($input['description']??'')?:null,isset($input['quantity'])&&$input['quantity']!==''?(float)$input['quantity']:null,($input['unit']??'')?:null,($input['station']??'')?:null,$priority,$status,($input['dueAt']??'')?:null,isset($input['estimatedMinutes'])&&$input['estimatedMinutes']!==''?(int)$input['estimatedMinutes']:null,!empty($input['requiresPhoto'])?1:0,!empty($input['requiresVerification'])?1:0,($input['transcript']??'')?:null,isset($input['aiConfidence'])?(float)$input['aiConfidence']:null,$userId,$userId]);$taskId=(int)$pdo->lastInsertId();
    $assignees=array_values(array_unique(array_filter(array_map('intval',(array)($input['assigneeIds']??[])))));$assign=$pdo->prepare("INSERT IGNORE INTO restaurant_task_assignments (organization_id,task_id,user_id,assignment_role,assigned_by) SELECT ?,?,u.id,'assignee',? FROM users u INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? WHERE u.id=? AND u.archived_at IS NULL AND u.status='active'");foreach($assignees as $assigned)$assign->execute([$organizationId,$taskId,$userId,$organizationId,$assigned]);if($assignees)$pdo->prepare("UPDATE restaurant_tasks SET status=IF(status='queued','assigned',status) WHERE id=?")->execute([$taskId]);
    operations_task_event($pdo,$organizationId,$taskId,'created','Task created',$userId,['category'=>$categorySlug,'assignees'=>$assignees,'source'=>$input['sourceType']??null]);$row=operations_task_by_public_id($pdo,$organizationId,$publicId);if(!$row)throw new RuntimeException('Task could not be loaded.');return $row;
}

function operations_sync_catering_tasks(PDO $pdo,int $organizationId,?int $userId=null): int
{
    if(!restaurant_brain_table_ready($pdo,'restaurant_operation_tasks')||!restaurant_brain_table_ready($pdo,'restaurant_operations'))return 0;operations_ensure_default_categories($pdo,$organizationId,$userId);
    $stmt=$pdo->prepare("SELECT t.*,o.public_id operation_public_id,o.title operation_title FROM restaurant_operation_tasks t INNER JOIN restaurant_operations o ON o.id=t.operation_id WHERE t.organization_id=?");$stmt->execute([$organizationId]);$count=0;
    foreach($stmt->fetchAll() as $source){$categorySlug=in_array((string)$source['category'],['prep','inventory','service','logistics','menu'],true)?((string)$source['category']==='logistics'?'delivery':((string)$source['category']==='menu'?'catering':(string)$source['category'])):'catering';$category=operations_category_by_slug($pdo,$organizationId,$categorySlug);$sourceId='catering-task-'.$source['id'];$statusMap=['open'=>'queued','in_progress'=>'in_progress','blocked'=>'blocked','done'=>'completed','cancelled'=>'cancelled'];$status=$statusMap[$source['status']]??'queued';$publicId='task-'.substr(hash('sha256',$organizationId.'|'.$sourceId),0,20);$upsert=$pdo->prepare("INSERT INTO restaurant_tasks (organization_id,public_id,category_id,source_type,source_public_id,title,description,priority,status,due_at,created_by,updated_by) VALUES (?,?,?,'catering_operation_task',?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE category_id=VALUES(category_id),title=VALUES(title),description=VALUES(description),priority=VALUES(priority),status=IF(status IN ('verified'),status,VALUES(status)),due_at=VALUES(due_at),updated_at=NOW(6)");$upsert->execute([$organizationId,$publicId,$category['id']??null,$sourceId,$source['title'],$source['description'],$source['priority'],$status,$source['due_at'],$userId,$userId]);$task=operations_task_by_public_id($pdo,$organizationId,$publicId);if($task&&!empty($source['assigned_to'])){$pdo->prepare("INSERT IGNORE INTO restaurant_task_assignments (organization_id,task_id,user_id,assignment_role,assigned_by) VALUES (?, ?, ?, 'assignee', ?)")->execute([$organizationId,(int)$task['id'],(int)$source['assigned_to'],$userId]);}$count++;}
    return $count;
}

function operations_task_list(PDO $pdo,int $organizationId,array $filters=[]): array
{
    operations_sync_catering_tasks($pdo,$organizationId,null);$params=[$organizationId];$where="t.organization_id=? AND t.archived_at IS NULL";
    if(!empty($filters['category'])){$where.=' AND c.slug=?';$params[]=operations_slug((string)$filters['category']);}
    if(!empty($filters['status'])){$where.=' AND t.status=?';$params[]=(string)$filters['status'];}
    if(!empty($filters['userId'])){$where.=' AND EXISTS (SELECT 1 FROM restaurant_task_assignments a WHERE a.task_id=t.id AND a.user_id=?)';$params[]=(int)$filters['userId'];}
    if(!empty($filters['query'])){$like='%'.trim((string)$filters['query']).'%';$where.=' AND (t.title LIKE ? OR t.description LIKE ? OR t.station LIKE ? OR c.name LIKE ?)';array_push($params,$like,$like,$like,$like);}
    $stmt=$pdo->prepare("SELECT t.*,c.name category_name,c.slug category_slug,(SELECT GROUP_CONCAT(u.display_name ORDER BY u.display_name SEPARATOR ', ') FROM restaurant_task_assignments a INNER JOIN users u ON u.id=a.user_id WHERE a.task_id=t.id) assigned_names,(SELECT COALESCE(SUM(COALESCE(te.duration_seconds,TIMESTAMPDIFF(SECOND,te.started_at,NOW()))),0) FROM restaurant_task_time_entries te WHERE te.task_id=t.id) tracked_seconds,(SELECT COUNT(*) FROM restaurant_task_proofs p WHERE p.task_id=t.id) proof_count FROM restaurant_tasks t LEFT JOIN task_categories c ON c.id=t.category_id WHERE {$where} ORDER BY FIELD(t.status,'in_progress','blocked','assigned','accepted','queued','completed','verified','cancelled'),t.due_at IS NULL,t.due_at,FIELD(t.priority,'critical','high','normal','low'),t.created_at DESC LIMIT 500");$stmt->execute($params);return $stmt->fetchAll();
}

function operations_task_detail(PDO $pdo,int $organizationId,string $publicId): ?array
{
    $task=operations_task_by_public_id($pdo,$organizationId,$publicId);if(!$task)return null;$id=(int)$task['id'];$stmt=$pdo->prepare("SELECT a.*,u.display_name FROM restaurant_task_assignments a INNER JOIN users u ON u.id=a.user_id WHERE a.organization_id=? AND a.task_id=? ORDER BY a.assignment_role,u.display_name");$stmt->execute([$organizationId,$id]);$task['assignments']=$stmt->fetchAll();$stmt=$pdo->prepare("SELECT e.*,u.display_name actor_name FROM restaurant_task_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.organization_id=? AND e.task_id=? ORDER BY e.created_at DESC LIMIT 100");$stmt->execute([$organizationId,$id]);$task['events']=$stmt->fetchAll();$stmt=$pdo->prepare("SELECT te.*,u.display_name FROM restaurant_task_time_entries te INNER JOIN users u ON u.id=te.user_id WHERE te.organization_id=? AND te.task_id=? ORDER BY te.started_at DESC");$stmt->execute([$organizationId,$id]);$task['timeEntries']=$stmt->fetchAll();$stmt=$pdo->prepare("SELECT p.*,f.original_name,f.mime_type,u.display_name uploader_name FROM restaurant_task_proofs p INNER JOIN files f ON f.id=p.file_id INNER JOIN users u ON u.id=p.uploaded_by WHERE p.organization_id=? AND p.task_id=? ORDER BY p.created_at DESC");$stmt->execute([$organizationId,$id]);$task['proofs']=$stmt->fetchAll();return $task;
}

function operations_task_set_status(PDO $pdo,int $organizationId,string $publicId,string $status,int $userId): array
{
    $allowed=['queued','assigned','accepted','in_progress','blocked','completed','verified','cancelled'];if(!in_array($status,$allowed,true))throw new InvalidArgumentException('Invalid task status.');$task=operations_task_by_public_id($pdo,$organizationId,$publicId);if(!$task)throw new RuntimeException('Task not found.');
    if($status==='verified'){$proof=(int)$pdo->prepare('SELECT COUNT(*) FROM restaurant_task_proofs WHERE organization_id=? AND task_id=?');}
    $pdo->prepare("UPDATE restaurant_tasks SET status=?,verified_at=IF(?='verified',NOW(6),verified_at),verified_by=IF(?='verified',?,verified_by),updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([$status,$status,$userId,$status,$userId,$userId,(int)$task['id'],$organizationId]);
    if($status==='completed')$pdo->prepare('UPDATE restaurant_task_assignments SET completed_at=COALESCE(completed_at,NOW(6)) WHERE task_id=? AND user_id=?')->execute([(int)$task['id'],$userId]);
    if($task['source_type']==='catering_operation_task'&&preg_match('/catering-task-(\d+)/',(string)$task['source_public_id'],$m)){$map=['queued'=>'open','assigned'=>'open','accepted'=>'open','in_progress'=>'in_progress','blocked'=>'blocked','completed'=>'done','verified'=>'done','cancelled'=>'cancelled'];$pdo->prepare('UPDATE restaurant_operation_tasks SET status=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([$map[$status]??'open',$userId,(int)$m[1],$organizationId]);}
    operations_task_event($pdo,$organizationId,(int)$task['id'],'status_changed','Task status changed to '.str_replace('_',' ',$status),$userId,['status'=>$status]);return operations_task_by_public_id($pdo,$organizationId,$publicId)??$task;
}

function operations_task_timer(PDO $pdo,int $organizationId,string $publicId,int $userId,bool $start): array
{
    $task=operations_task_by_public_id($pdo,$organizationId,$publicId);if(!$task)throw new RuntimeException('Task not found.');$id=(int)$task['id'];
    if($start){$existing=$pdo->prepare('SELECT id FROM restaurant_task_time_entries WHERE organization_id=? AND task_id=? AND user_id=? AND ended_at IS NULL LIMIT 1');$existing->execute([$organizationId,$id,$userId]);if(!$existing->fetchColumn())$pdo->prepare('INSERT INTO restaurant_task_time_entries (organization_id,task_id,user_id,started_at) VALUES (?,?,?,NOW(6))')->execute([$organizationId,$id,$userId]);$pdo->prepare("UPDATE restaurant_tasks SET status=IF(status IN ('queued','assigned','accepted'),'in_progress',status),updated_by=?,updated_at=NOW(6) WHERE id=?")->execute([$userId,$id]);operations_task_event($pdo,$organizationId,$id,'timer_started','Task timer started',$userId);}else{$entry=$pdo->prepare('SELECT id,started_at FROM restaurant_task_time_entries WHERE organization_id=? AND task_id=? AND user_id=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1');$entry->execute([$organizationId,$id,$userId]);$row=$entry->fetch();if($row)$pdo->prepare('UPDATE restaurant_task_time_entries SET ended_at=NOW(6),duration_seconds=GREATEST(0,TIMESTAMPDIFF(SECOND,started_at,NOW(6))) WHERE id=?')->execute([(int)$row['id']]);operations_task_event($pdo,$organizationId,$id,'timer_stopped','Task timer stopped',$userId);}
    return operations_task_detail($pdo,$organizationId,$publicId)??$task;
}

function operations_core_summary(PDO $pdo,int $organizationId): array
{
    operations_ensure_default_categories($pdo,$organizationId,null);operations_sync_catering_tasks($pdo,$organizationId,null);$inventory=(int)$pdo->prepare('SELECT COUNT(*) FROM inventory_items WHERE organization_id=? AND archived_at IS NULL');
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM inventory_items WHERE organization_id=? AND archived_at IS NULL');$stmt->execute([$organizationId]);$inventoryCount=(int)$stmt->fetchColumn();if($inventoryCount===0){operations_sync_inventory_sources($pdo,$organizationId,null);$stmt->execute([$organizationId]);$inventoryCount=(int)$stmt->fetchColumn();}
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM inventory_items WHERE organization_id=? AND archived_at IS NULL AND reorder_point IS NOT NULL AND on_hand_quantity<=reorder_point');$stmt->execute([$organizationId]);$low=(int)$stmt->fetchColumn();$stmt=$pdo->prepare("SELECT COUNT(*) total,SUM(status IN ('queued','assigned','accepted','in_progress','blocked')) open_count,SUM(status='in_progress') in_progress,SUM(status IN ('queued','assigned','accepted','in_progress','blocked') AND due_at<NOW()) overdue FROM restaurant_tasks WHERE organization_id=? AND archived_at IS NULL");$stmt->execute([$organizationId]);$tasks=$stmt->fetch()?:[];return ['inventoryCount'=>$inventoryCount,'lowStock'=>$low,'taskTotal'=>(int)($tasks['total']??0),'openTasks'=>(int)($tasks['open_count']??0),'inProgress'=>(int)($tasks['in_progress']??0),'overdue'=>(int)($tasks['overdue']??0)];
}

function operations_agent_inventory_answer(PDO $pdo,int $organizationId,string $message): array
{
    $normalized=mb_strtolower($message,'UTF-8');$low=preg_match('/\b(low|below par|reorder|short|shortage|running out)\b/u',$normalized)===1;$rows=operations_inventory_list($pdo,$organizationId,$low?'':$message,$low,40);if(!$rows&&$low)$rows=operations_inventory_list($pdo,$organizationId,'',true,40);if(!$rows)return ['skill'=>'inventory.search','answer'=>$low?'No inventory items are currently at or below their reorder point.':'I could not find a matching inventory item.','data'=>[],'sources'=>[]];$lines=[];foreach(array_slice($rows,0,25) as $row)$lines[]=$row['name'].' — on hand '.$row['on_hand_quantity'].($row['base_unit']?' '.$row['base_unit']:'').($row['par_level']!==null?' / par '.$row['par_level']:'').($row['reorder_point']!==null?' / reorder '.$row['reorder_point']:'').' — used by '.$row['menu_source_count'].' menu item(s), '.$row['recipe_source_count'].' recipe(s)';return ['skill'=>$low?'inventory.low_stock':'inventory.search','answer'=>($low?'Inventory requiring attention:':'Matching inventory:')."\n- ".implode("\n- ",$lines),'data'=>$rows,'sources'=>array_column($rows,'public_id')];
}

function operations_agent_task_answer(PDO $pdo,int $organizationId,string $message,?int $userId=null): array
{
    $normalized=mb_strtolower($message,'UTF-8');$category=preg_match('/\bprep\b/u',$normalized)?'prep':'';$my=preg_match('/\b(my|assigned to me)\b/u',$normalized)?$userId:null;$rows=operations_task_list($pdo,$organizationId,['category'=>$category,'userId'=>$my,'query'=>'']);if(!$rows)return ['skill'=>'tasks.list','answer'=>$category?'The prep list is currently empty.':'No matching restaurant tasks are open.','data'=>[],'sources'=>[]];$lines=[];foreach(array_slice($rows,0,30) as $row)$lines[]=$row['title'].' — '.($row['category_name']?:'General').' — '.$row['status'].' — '.($row['assigned_names']?:'unassigned').($row['due_at']?' — due '.$row['due_at']:'');return ['skill'=>$category?'tasks.prep_list':'tasks.list','answer'=>($category?'Current prep list:':'Restaurant tasks:')."\n- ".implode("\n- ",$lines),'data'=>$rows,'sources'=>array_column($rows,'public_id')];
}
