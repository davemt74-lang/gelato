<?php
declare(strict_types=1);

require_once __DIR__ . '/restaurant-brain.php';

function catering_operations_ready(PDO $pdo): bool
{
    foreach (['restaurant_operations','restaurant_operation_menu_items','restaurant_operation_requirements','restaurant_operation_tasks','restaurant_operation_staff'] as $table) {
        if (!restaurant_brain_table_ready($pdo, $table)) return false;
    }
    return true;
}

function catering_operation_row(PDO $pdo, int $organizationId, string $publicId): ?array
{
    $statement = $pdo->prepare("SELECT o.*,c.company_name,c.contact_name,c.email,c.phone,c.event_type,c.event_date,c.start_time,c.end_time,c.menu_interests,c.dietary_requirements,c.beverage_service,c.staffing_needs,c.rentals_needs,c.pipeline_stage FROM restaurant_operations o LEFT JOIN catering_leads c ON c.organization_id=o.organization_id AND c.public_id=o.source_public_id AND o.source_type='catering' WHERE o.organization_id=? AND o.public_id=? AND o.archived_at IS NULL LIMIT 1");
    $statement->execute([$organizationId, $publicId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function catering_operation_list(PDO $pdo, int $organizationId, int $days = 120): array
{
    $days = max(1, min(365, $days));
    $statement = $pdo->prepare("SELECT o.*,c.company_name,c.contact_name,c.event_type,c.pipeline_stage FROM restaurant_operations o LEFT JOIN catering_leads c ON c.organization_id=o.organization_id AND c.public_id=o.source_public_id AND o.source_type='catering' WHERE o.organization_id=? AND o.archived_at IS NULL AND o.status<>'cancelled' AND (o.service_start_at IS NULL OR o.service_start_at<=DATE_ADD(NOW(),INTERVAL {$days} DAY)) ORDER BY o.status='completed',o.service_start_at IS NULL,o.service_start_at,o.updated_at DESC LIMIT 250");
    $statement->execute([$organizationId]);
    return $statement->fetchAll();
}

function catering_operation_menu(PDO $pdo, int $organizationId, int $operationId): array
{
    $statement=$pdo->prepare("SELECT m.*,r.public_id AS recipe_public_id,r.name AS recipe_name,r.yield_quantity,r.yield_unit FROM restaurant_operation_menu_items m LEFT JOIN recipes r ON r.id=m.recipe_id WHERE m.organization_id=? AND m.operation_id=? ORDER BY m.id");
    $statement->execute([$organizationId,$operationId]);
    return $statement->fetchAll();
}

function catering_operation_tasks(PDO $pdo, int $organizationId, int $operationId): array
{
    $statement=$pdo->prepare("SELECT t.*,u.display_name AS assigned_name FROM restaurant_operation_tasks t LEFT JOIN users u ON u.id=t.assigned_to WHERE t.organization_id=? AND t.operation_id=? ORDER BY FIELD(t.status,'open','in_progress','blocked','done','cancelled'),t.due_at IS NULL,t.due_at,t.sort_order,t.id");
    $statement->execute([$organizationId,$operationId]);
    return $statement->fetchAll();
}

function catering_operation_staff(PDO $pdo, int $organizationId, int $operationId): array
{
    $statement=$pdo->prepare("SELECT s.*,u.display_name AS user_name FROM restaurant_operation_staff s INNER JOIN users u ON u.id=s.user_id WHERE s.organization_id=? AND s.operation_id=? ORDER BY s.shift_start_at IS NULL,s.shift_start_at,u.display_name");
    $statement->execute([$organizationId,$operationId]);
    return $statement->fetchAll();
}

function catering_operation_requirements(PDO $pdo, int $organizationId, int $operationId): array
{
    $statement=$pdo->prepare("SELECT q.*,r.public_id AS recipe_public_id,r.name AS recipe_name,m.item_name FROM restaurant_operation_requirements q LEFT JOIN recipes r ON r.id=q.recipe_id LEFT JOIN restaurant_operation_menu_items m ON m.id=q.menu_item_id WHERE q.organization_id=? AND q.operation_id=? ORDER BY FIELD(q.status,'planned','ordered','ready','unavailable'),q.ingredient_name,q.id");
    $statement->execute([$organizationId,$operationId]);
    return $statement->fetchAll();
}

function catering_operations_yield_is_servings(?string $unit): bool
{
    $unit=mb_strtolower(trim((string)$unit),'UTF-8');
    return in_array($unit,['serving','servings','portion','portions','each','ea','piece','pieces'],true);
}

function catering_operations_numeric_quantity(string $value): ?float
{
    $value=trim($value);
    if(is_numeric($value))return (float)$value;
    if(preg_match('/^(\d+)\/(\d+)$/',$value,$m) && (int)$m[2]!==0)return (float)$m[1]/(float)$m[2];
    return null;
}

function catering_operations_parse_ingredient(mixed $ingredient): ?array
{
    if (is_string($ingredient)) {
        $text=trim($ingredient);if($text==='')return null;
        $quantityPattern='(?:\d+(?:\.\d+)?|\d+\/\d+)';
        $unitPattern='(?:tsp|tbsp|teaspoons?|tablespoons?|cups?|fl\s*oz|ounces?|oz|pounds?|lbs?|lb|kilograms?|kg|grams?|g|milliliters?|ml|liters?|l|gallons?|gal|quarts?|qt|pints?|pt|each|ea)';
        if(preg_match('/^('.$quantityPattern.')\s+('.$unitPattern.')\s+(.+)$/iu',$text,$m)){
            return ['name'=>trim($m[3]),'quantity'=>catering_operations_numeric_quantity($m[1]),'unit'=>trim($m[2])];
        }
        return ['name'=>$text,'quantity'=>null,'unit'=>''];
    }
    if (!is_array($ingredient)) return null;
    $name=trim((string)($ingredient['name']??$ingredient['ingredient']??$ingredient['item']??$ingredient['description']??''));
    $quantity=$ingredient['quantity']??$ingredient['amount']??$ingredient['qty']??null;
    $unit=trim((string)($ingredient['unit']??$ingredient['measure']??''));
    if($name==='' && isset($ingredient[0]) && is_string($ingredient[0])) return catering_operations_parse_ingredient($ingredient[0]);
    if($name==='')return null;
    if(is_string($quantity)){
        $parsedQuantity=catering_operations_numeric_quantity($quantity);
        if($parsedQuantity===null && preg_match('/((?:\d+(?:\.\d+)?|\d+\/\d+))/',$quantity,$m))$parsedQuantity=catering_operations_numeric_quantity($m[1]);
        $quantity=$parsedQuantity;
    }
    $quantity=is_numeric($quantity)?(float)$quantity:null;
    return ['name'=>$name,'quantity'=>$quantity,'unit'=>$unit];
}

function catering_operations_rebuild_requirements(PDO $pdo, int $organizationId, int $operationId, ?int $userId): int
{
    $items=$pdo->prepare("SELECT m.*,r.ingredients_json,r.yield_quantity,r.yield_unit FROM restaurant_operation_menu_items m LEFT JOIN recipes r ON r.id=m.recipe_id WHERE m.organization_id=? AND m.operation_id=? ORDER BY m.id");
    $items->execute([$organizationId,$operationId]);
    $rows=$items->fetchAll();
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $pdo->prepare('DELETE FROM restaurant_operation_requirements WHERE organization_id=? AND operation_id=?')->execute([$organizationId,$operationId]);
        $insert=$pdo->prepare("INSERT INTO restaurant_operation_requirements (organization_id,operation_id,menu_item_id,recipe_id,ingredient_name,required_quantity,unit,status,notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,'planned',?,?,?)");
        $count=0;
        foreach($rows as $item){
            if(!$item['recipe_id'])continue;
            $ingredients=json_decode((string)($item['ingredients_json']??'[]'),true);if(!is_array($ingredients))continue;
            $scale=null;$batches=(float)($item['batches']??0);
            if($batches>0)$scale=$batches;
            else{
                $yield=(float)($item['yield_quantity']??0);$target=(float)($item['target_servings']??0);
                if($yield>0&&$target>0&&catering_operations_yield_is_servings((string)($item['yield_unit']??'')))$scale=$target/$yield;
            }
            $scaleNote=$scale===null?'Batch quantity required before total ingredient quantity can be calculated.':null;
            foreach($ingredients as $ingredient){
                $parsed=catering_operations_parse_ingredient($ingredient);if(!$parsed)continue;
                $qty=($parsed['quantity']===null||$scale===null)?null:round((float)$parsed['quantity']*$scale,4);
                $insert->execute([$organizationId,$operationId,(int)$item['id'],(int)$item['recipe_id'],mb_substr($parsed['name'],0,220,'UTF-8'),$qty,mb_substr($parsed['unit'],0,80,'UTF-8')?:null,$scaleNote,$userId,$userId]);$count++;
            }
        }
        if($ownsTransaction)$pdo->commit();
        return $count;
    }catch(Throwable $error){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
}

function catering_operations_staff_conflict(PDO $pdo,int $organizationId,int $userId,?string $startAt,?string $endAt,int $excludeStaffId=0):?array
{
    if($userId<=0||!$startAt||!$endAt)return null;
    $statement=$pdo->prepare("SELECT s.id,s.role_name,s.shift_start_at,s.shift_end_at,o.public_id AS operation_public_id,o.title AS operation_title FROM restaurant_operation_staff s INNER JOIN restaurant_operations o ON o.id=s.operation_id WHERE s.organization_id=? AND s.user_id=? AND s.status<>'cancelled' AND s.id<>? AND s.shift_start_at IS NOT NULL AND s.shift_end_at IS NOT NULL AND s.shift_start_at<? AND s.shift_end_at>? ORDER BY s.shift_start_at LIMIT 1");
    $statement->execute([$organizationId,$userId,$excludeStaffId,$endAt,$startAt]);
    $row=$statement->fetch();return $row?:null;
}

function catering_operations_readiness(PDO $pdo, int $organizationId, int $operationId, bool $persist = true): array
{
    $op=$pdo->prepare('SELECT * FROM restaurant_operations WHERE id=? AND organization_id=? AND archived_at IS NULL LIMIT 1');$op->execute([$operationId,$organizationId]);$operation=$op->fetch();if(!$operation)return ['percent'=>0,'issues'=>['Operation not found.']];
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM restaurant_operation_menu_items WHERE organization_id=? AND operation_id=?');$stmt->execute([$organizationId,$operationId]);$menuCount=(int)$stmt->fetchColumn();
    $stmt=$pdo->prepare("SELECT COUNT(*) total,SUM(status='done') done_count,SUM(status IN ('open','in_progress','blocked') AND due_at IS NOT NULL AND due_at<NOW()) overdue_count,SUM(status IN ('open','in_progress','blocked') AND priority IN ('critical','high') AND assigned_to IS NULL) unassigned_critical FROM restaurant_operation_tasks WHERE organization_id=? AND operation_id=? AND status<>'cancelled'");$stmt->execute([$organizationId,$operationId]);$tasks=$stmt->fetch()?:[];
    $stmt=$pdo->prepare("SELECT COUNT(*) total,SUM(status='ready') ready_count,SUM(status='unavailable') unavailable_count,SUM(required_quantity IS NULL) unknown_count FROM restaurant_operation_requirements WHERE organization_id=? AND operation_id=?");$stmt->execute([$organizationId,$operationId]);$req=$stmt->fetch()?:[];
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM restaurant_operation_staff WHERE organization_id=? AND operation_id=? AND status<>'cancelled'");$stmt->execute([$organizationId,$operationId]);$staffCount=(int)$stmt->fetchColumn();
    $score=0;$issues=[];
    if($operation['service_start_at'])$score+=10;else $issues[]='Event date/time is not set.';
    if((int)($operation['guest_count']??0)>0)$score+=5;else $issues[]='Guest count is not set.';
    if($menuCount>0)$score+=20;else $issues[]='No operational menu items are linked yet.';
    $taskTotal=(int)($tasks['total']??0);$taskDone=(int)($tasks['done_count']??0);$score+=($taskTotal>0?(int)round(35*$taskDone/$taskTotal):0);if($taskTotal===0)$issues[]='No execution tasks exist.';
    $reqTotal=(int)($req['total']??0);$reqReady=(int)($req['ready_count']??0);$score+=($reqTotal>0?(int)round(15*$reqReady/$reqTotal):0);if($reqTotal===0)$issues[]='Ingredient requirements have not been generated.';
    if($staffCount>0)$score+=15;else $issues[]='No event staff are assigned.';
    if((int)($tasks['overdue_count']??0)>0)$issues[]=(int)$tasks['overdue_count'].' task(s) are overdue.';
    if((int)($tasks['unassigned_critical']??0)>0)$issues[]=(int)$tasks['unassigned_critical'].' high/critical task(s) are unassigned.';
    if((int)($req['unavailable_count']??0)>0)$issues[]=(int)$req['unavailable_count'].' ingredient requirement(s) are unavailable.';
    if((int)($req['unknown_count']??0)>0)$issues[]=(int)$req['unknown_count'].' ingredient requirement(s) need a batch quantity before totals can be calculated.';
    $score=max(0,min(100,$score));if(((int)($tasks['overdue_count']??0)>0||(int)($req['unavailable_count']??0)>0||(int)($req['unknown_count']??0)>0)&&$score>79)$score=79;
    if($persist)$pdo->prepare('UPDATE restaurant_operations SET readiness_percent=?,updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([$score,$operationId,$organizationId]);
    return ['percent'=>$score,'issues'=>$issues,'menuCount'=>$menuCount,'taskTotal'=>$taskTotal,'taskDone'=>$taskDone,'requirementsTotal'=>$reqTotal,'requirementsReady'=>$reqReady,'requirementsUnknown'=>(int)($req['unknown_count']??0),'staffCount'=>$staffCount,'overdueTasks'=>(int)($tasks['overdue_count']??0),'unassignedCritical'=>(int)($tasks['unassigned_critical']??0)];
}

function catering_operations_context(PDO $pdo, int $organizationId, array $operation): string
{
    $id=(int)$operation['id'];$readiness=catering_operations_readiness($pdo,$organizationId,$id,true);$menu=catering_operation_menu($pdo,$organizationId,$id);$tasks=catering_operation_tasks($pdo,$organizationId,$id);$requirements=catering_operation_requirements($pdo,$organizationId,$id);$staff=catering_operation_staff($pdo,$organizationId,$id);
    $lines=['Restaurant operation: '.$operation['title'],'Source: '.$operation['source_type'].' / '.$operation['source_public_id'],'Status: '.$operation['status'],'Readiness: '.$readiness['percent'].'%','Service: '.($operation['service_start_at']?:'not scheduled').' to '.($operation['service_end_at']?:'not scheduled'),'Guests: '.($operation['guest_count']??'not recorded'),'Venue: '.(($operation['venue_name']??'')?:'not recorded')];
    if(($operation['dietary_requirements']??'')!=='')$lines[]='Dietary/allergy requirements: '.$operation['dietary_requirements'];
    if($menu){$lines[]='Operational menu:';foreach($menu as $row)$lines[]='- '.$row['item_name'].($row['recipe_name']?' / recipe '.$row['recipe_name']:'').($row['target_servings']?' / '.$row['target_servings'].' servings':'').($row['batches']?' / '.round((float)$row['batches'],2).' batches':'');}
    if($requirements){$lines[]='Ingredient requirements:';foreach(array_slice($requirements,0,80) as $row)$lines[]='- '.$row['ingredient_name'].($row['required_quantity']!==null?' '.$row['required_quantity'].' '.($row['unit']??''):' quantity TBD').' / '.$row['status'].(($row['notes']??'')?' / '.$row['notes']:'');}
    if($tasks){$lines[]='Execution tasks:';foreach(array_slice($tasks,0,60) as $row)$lines[]='- '.$row['title'].' / '.$row['status'].($row['due_at']?' / due '.$row['due_at']:'').($row['assigned_name']?' / '.$row['assigned_name']:' / unassigned');}
    if($staff){$lines[]='Event staffing:';foreach($staff as $row)$lines[]='- '.$row['user_name'].' / '.$row['role_name'].($row['shift_start_at']?' / '.$row['shift_start_at'].' to '.($row['shift_end_at']?:'TBD'):'');}
    if($readiness['issues']){$lines[]='Readiness issues:';foreach($readiness['issues'] as $issue)$lines[]='- '.$issue;}
    return implode("\n",$lines);
}

function catering_operations_sync_knowledge(PDO $pdo, int $organizationId, int $operationId, ?int $userId): void
{
    if(!restaurant_brain_table_ready($pdo,'agent_knowledge_records'))return;
    $stmt=$pdo->prepare("SELECT o.*,c.dietary_requirements FROM restaurant_operations o LEFT JOIN catering_leads c ON c.organization_id=o.organization_id AND c.public_id=o.source_public_id AND o.source_type='catering' WHERE o.id=? AND o.organization_id=? AND o.archived_at IS NULL LIMIT 1");$stmt->execute([$operationId,$organizationId]);$operation=$stmt->fetch();if(!$operation)return;
    restaurant_brain_write_knowledge($pdo,$organizationId,'restaurant_operation',(string)$operation['public_id'],'Operations: '.$operation['title'],catering_operations_context($pdo,$organizationId,$operation),$userId);
}

function catering_operations_find(PDO $pdo, int $organizationId, string $query, int $limit=20): array
{
    $limit=max(1,min(50,$limit));$q=trim($query);$like='%'.$q.'%';
    $stmt=$pdo->prepare("SELECT o.*,c.company_name,c.contact_name,c.event_type,c.pipeline_stage FROM restaurant_operations o LEFT JOIN catering_leads c ON c.organization_id=o.organization_id AND c.public_id=o.source_public_id AND o.source_type='catering' WHERE o.organization_id=? AND o.archived_at IS NULL AND (?='' OR o.title LIKE ? OR c.company_name LIKE ? OR c.contact_name LIKE ? OR c.event_type LIKE ? OR o.venue_name LIKE ?) ORDER BY o.status='completed',o.service_start_at IS NULL,o.service_start_at LIMIT {$limit}");
    $stmt->execute([$organizationId,$q,$like,$like,$like,$like,$like]);return $stmt->fetchAll();
}

function catering_operations_agent_answer(PDO $pdo, int $organizationId, string $message): array
{
    $rows=catering_operations_find($pdo,$organizationId,$message,12);
    if(!$rows){
        $stop=['what','which','where','when','who','how','the','our','for','are','is','catering','catered','event','events','operations','operational','readiness','ready','prep','production','ingredient','ingredients','requirement','requirements','staff','staffing','shift','shifts','task','tasks','pack','load','setup','shortage','execution','execute','need','needs','missing','attention'];
        $tokens=preg_split('/[^\pL\pN_-]+/u',mb_strtolower($message,'UTF-8'),-1,PREG_SPLIT_NO_EMPTY)?:[];$matched=[];
        foreach($tokens as $token){if(mb_strlen($token,'UTF-8')<3||in_array($token,$stop,true))continue;foreach(catering_operations_find($pdo,$organizationId,$token,12) as $row)$matched[(string)$row['public_id']]=$row;}
        $rows=array_values($matched);
    }
    if(!$rows && preg_match('/\b(readiness|ready|operations|prep|production|ingredient|staff|staffing|task|pack|load|setup|shortage)\b/i',$message))$rows=catering_operations_find($pdo,$organizationId,'',12);
    if(!$rows)return ['skill'=>'catering.operations','answer'=>'No catering operations records match that request yet. Move an event into menu planning or a later catering stage to create its operations record.','data'=>[],'sources'=>[]];
    if(count($rows)===1){$text=catering_operations_context($pdo,$organizationId,$rows[0]);return ['skill'=>'catering.operations.context','answer'=>$text,'data'=>$rows[0],'sources'=>[(string)$rows[0]['public_id']]];}
    $lines=[];foreach($rows as $row){$r=catering_operations_readiness($pdo,$organizationId,(int)$row['id'],true);$lines[]=$row['title'].' — '.($row['service_start_at']?:'date TBD').' — '.$r['percent'].'% ready — '.$r['taskDone'].'/'.$r['taskTotal'].' tasks done — '.$r['staffCount'].' staff';}
    return ['skill'=>'catering.operations.summary','answer'=>"Catering operations readiness:\n- ".implode("\n- ",$lines),'data'=>$rows,'sources'=>array_column($rows,'public_id')];
}
