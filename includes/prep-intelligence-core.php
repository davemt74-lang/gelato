<?php
declare(strict_types=1);

require_once __DIR__ . '/operations-core.php';

function prep_intelligence_ready(PDO $pdo): bool
{
    foreach (['prep_plans','prep_recommendations','prep_plan_tasks','prep_plan_events','prep_demand_signals','inventory_forecasts'] as $table) {
        if (!restaurant_brain_table_ready($pdo, $table)) return false;
    }
    return true;
}

function prep_public_id(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(10));
}

function prep_service_period(string $value): string
{
    $value=mb_strtolower(trim($value),'UTF-8');
    $aliases=['am'=>'morning','breakfast'=>'morning','midday'=>'lunch','pm'=>'dinner','evening'=>'dinner','night'=>'dinner','full'=>'all','day'=>'all'];
    $value=$aliases[$value]??$value;
    return in_array($value,['all','morning','lunch','dinner','closing'],true)?$value:'all';
}

function prep_normalized_key(string $value): string
{
    $value=mb_strtolower(trim($value),'UTF-8');
    $value=preg_replace('/\b(?:make|prep|prepare|batch|batches|of|the)\b/u',' ',$value)??$value;
    $value=preg_replace('/[^a-z0-9]+/u',' ',$value)??$value;
    $value=preg_replace('/\s+/u',' ',trim($value))??trim($value);
    return mb_substr($value,0,180,'UTF-8')?:'prep-item';
}

function prep_normalized_unit(string $value): string
{
    $value=mb_strtolower(trim($value),'UTF-8');
    $aliases=['lbs'=>'lb','pound'=>'lb','pounds'=>'lb','ounces'=>'oz','ounce'=>'oz','quarts'=>'qt','quart'=>'qt','gallons'=>'gal','gallon'=>'gal','cups'=>'cup','batches'=>'batch','cases'=>'case','trays'=>'tray','pans'=>'pan','each'=>'ea'];
    return $aliases[$value]??$value;
}

function prep_plan(PDO $pdo,int $organizationId,string $publicId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM prep_plans WHERE organization_id=? AND public_id=? LIMIT 1');
    $stmt->execute([$organizationId,$publicId]);
    $row=$stmt->fetch();
    return $row?:null;
}

function prep_plan_for_period(PDO $pdo,int $organizationId,string $date,string $service='all'): ?array
{
    $service=prep_service_period($service);
    $stmt=$pdo->prepare('SELECT * FROM prep_plans WHERE organization_id=? AND plan_date=? AND service_period=? LIMIT 1');
    $stmt->execute([$organizationId,$date,$service]);
    $row=$stmt->fetch();
    return $row?:null;
}

function prep_event(PDO $pdo,int $organizationId,int $planId,string $type,string $summary,?int $userId=null,array $metadata=[],?int $taskId=null,?int $recommendationId=null): void
{
    $stmt=$pdo->prepare('INSERT INTO prep_plan_events (organization_id,plan_id,task_id,recommendation_id,event_type,summary,metadata_json,actor_user_id) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$organizationId,$planId,$taskId,$recommendationId,mb_substr($type,0,60,'UTF-8'),mb_substr($summary,0,600,'UTF-8'),$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null,$userId]);
}

function prep_get_or_create_plan(PDO $pdo,int $organizationId,string $date,string $service='all',?int $userId=null): array
{
    $service=prep_service_period($service);
    $dt=DateTimeImmutable::createFromFormat('Y-m-d',$date);
    if(!$dt||$dt->format('Y-m-d')!==$date)throw new InvalidArgumentException('Invalid prep plan date.');
    $existing=prep_plan_for_period($pdo,$organizationId,$date,$service);
    if($existing)return $existing;
    $publicId=prep_public_id('prep');
    $title=$dt->format('D, M j').' · '.ucfirst($service).' Prep';
    $stmt=$pdo->prepare("INSERT INTO prep_plans (organization_id,public_id,plan_date,service_period,title,created_by,updated_by) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$organizationId,$publicId,$date,$service,$title,$userId,$userId]);
    $plan=prep_plan($pdo,$organizationId,$publicId);
    if(!$plan)throw new RuntimeException('Prep plan could not be created.');
    prep_event($pdo,$organizationId,(int)$plan['id'],'created','Prep plan created.',$userId,['date'=>$date,'service'=>$service]);
    return $plan;
}

function prep_due_at(array $plan): string
{
    $times=['morning'=>'09:00:00','lunch'=>'10:30:00','dinner'=>'16:30:00','closing'=>'21:30:00','all'=>'15:00:00'];
    return (string)$plan['plan_date'].' '.($times[(string)$plan['service_period']]??$times['all']);
}

function prep_history_rows(PDO $pdo,int $organizationId,string $from,string $to,string $query=''): array
{
    $params=[$organizationId,$from,$to];$where="p.organization_id=? AND p.plan_date BETWEEN ? AND ?";
    if(trim($query)!==''){$where.=" AND (p.title LIKE ? OR EXISTS (SELECT 1 FROM prep_plan_tasks pt INNER JOIN restaurant_tasks t ON t.id=pt.task_id WHERE pt.plan_id=p.id AND t.title LIKE ?))";$like='%'.trim($query).'%';$params[]=$like;$params[]=$like;}
    $stmt=$pdo->prepare("SELECT p.*,(SELECT COUNT(*) FROM prep_plan_tasks pt WHERE pt.plan_id=p.id) task_count,(SELECT COUNT(*) FROM prep_recommendations r WHERE r.plan_id=p.id) recommendation_count,(SELECT COUNT(*) FROM inventory_forecasts f WHERE f.plan_id=p.id AND (f.shortage_quantity>0 OR f.restock_quantity>0)) shortage_count FROM prep_plans p WHERE {$where} ORDER BY p.plan_date DESC,FIELD(p.service_period,'dinner','lunch','morning','all','closing') LIMIT 180");
    $stmt->execute($params);return $stmt->fetchAll();
}

function prep_task_history_for_date(PDO $pdo,int $organizationId,string $date,string $query=''): array
{
    $params=[$organizationId,$date];$where="t.organization_id=? AND c.slug='prep' AND t.archived_at IS NULL AND DATE(COALESCE(t.due_at,t.created_at))=?";
    if(trim($query)!==''){$where.=' AND t.title LIKE ?';$params[]='%'.trim($query).'%';}
    $stmt=$pdo->prepare("SELECT t.public_id,t.title,t.quantity,t.unit,t.station,t.status,t.due_at,t.created_at,t.original_transcript,(SELECT GROUP_CONCAT(u.display_name ORDER BY u.display_name SEPARATOR ', ') FROM restaurant_task_assignments a JOIN users u ON u.id=a.user_id WHERE a.task_id=t.id) assigned_names FROM restaurant_tasks t LEFT JOIN task_categories c ON c.id=t.category_id WHERE {$where} ORDER BY COALESCE(t.due_at,t.created_at),t.title");
    $stmt->execute($params);return $stmt->fetchAll();
}

function prep_historical_baselines(PDO $pdo,int $organizationId,string $targetDate,int $lookbackWeeks=8,string $query=''): array
{
    $target=new DateTimeImmutable($targetDate);$from=$target->modify('-'.max(2,min(26,$lookbackWeeks)).' weeks')->format('Y-m-d');$targetWeekday=(int)$target->format('N');
    $params=[$organizationId,$from,$targetDate];$where="t.organization_id=? AND c.slug='prep' AND t.archived_at IS NULL AND t.status IN ('completed','verified') AND t.quantity IS NOT NULL AND t.quantity>0 AND DATE(COALESCE(t.due_at,t.created_at)) BETWEEN ? AND DATE_SUB(?,INTERVAL 1 DAY)";
    if(trim($query)!==''){$where.=' AND t.title LIKE ?';$params[]='%'.trim($query).'%';}
    $stmt=$pdo->prepare("SELECT t.title,t.quantity,t.unit,t.station,t.status,COALESCE(t.due_at,t.created_at) activity_at FROM restaurant_tasks t LEFT JOIN task_categories c ON c.id=t.category_id WHERE {$where} ORDER BY activity_at DESC LIMIT 900");$stmt->execute($params);$groups=[];
    foreach($stmt->fetchAll() as $row){
        $qty=(float)$row['quantity'];if($qty<=0)continue;$unit=prep_normalized_unit((string)($row['unit']??''));$key=prep_normalized_key((string)$row['title']).'|'.$unit;
        if(!isset($groups[$key]))$groups[$key]=['title'=>(string)$row['title'],'unit'=>$unit,'same'=>[],'all'=>[],'stations'=>[],'lastCompleted'=>null];
        $activity=new DateTimeImmutable((string)$row['activity_at']);$groups[$key]['all'][]=$qty;if((int)$activity->format('N')===$targetWeekday)$groups[$key]['same'][]=$qty;
        $station=trim((string)($row['station']??''));if($station!=='')$groups[$key]['stations'][$station]=($groups[$key]['stations'][$station]??0)+1;
        if($groups[$key]['lastCompleted']===null)$groups[$key]['lastCompleted']=$activity->format('Y-m-d H:i:s');
    }
    $out=[];
    foreach($groups as $key=>$g){
        $samples=count($g['same'])?$g['same']:$g['all'];if(!$samples)continue;$count=count($samples);$avg=array_sum($samples)/$count;sort($samples);$mid=(int)floor(($count-1)/2);$median=$count%2?$samples[$mid]:($samples[$mid]+$samples[$mid+1])/2;
        $station='';if($g['stations']){arsort($g['stations']);$station=(string)array_key_first($g['stations']);}
        $sameDay=count($g['same'])>0;$confidence=min($sameDay?.95:.72,($sameDay?.38:.24)+($sameDay?.12:.08)*$count);
        $out[]=['key'=>explode('|',$key,2)[0],'title'=>$g['title'],'unit'=>$g['unit'],'average'=>round($avg,3),'median'=>round($median,3),'recommendedBase'=>round(($avg+$median)/2,3),'sampleCount'=>$count,'sameWeekday'=>$sameDay,'confidence'=>round($confidence,4),'station'=>$station,'lastCompleted'=>$g['lastCompleted']];
    }
    usort($out,static fn(array $a,array $b):int=>[$b['sampleCount'],$b['confidence']]<=>[$a['sampleCount'],$a['confidence']]);return $out;
}

function prep_source_commitments(PDO $pdo,int $organizationId,array $plan): array
{
    $date=(string)$plan['plan_date'];
    $stmt=$pdo->prepare("SELECT t.public_id,t.title,t.quantity,t.unit,t.station,t.priority,t.status,t.source_type,t.source_public_id,c.slug category_slug,c.name category_name FROM restaurant_tasks t LEFT JOIN task_categories c ON c.id=t.category_id WHERE t.organization_id=? AND t.archived_at IS NULL AND t.status NOT IN ('completed','verified','cancelled') AND DATE(COALESCE(t.due_at,t.created_at))=? AND c.slug IN ('prep','catering','wholesale') ORDER BY FIELD(c.slug,'prep','catering','wholesale'),FIELD(t.priority,'critical','high','normal','low'),t.title");
    $stmt->execute([$organizationId,$date]);return $stmt->fetchAll();
}

function prep_generate_recommendations(PDO $pdo,int $organizationId,array $plan,?int $userId=null): array
{
    if(!prep_intelligence_ready($pdo))throw new RuntimeException('Prep Intelligence migration is not installed.');
    $baselines=prep_historical_baselines($pdo,$organizationId,(string)$plan['plan_date'],8);$mult=max(.25,min(4.0,(float)$plan['demand_multiplier']));$upsert=$pdo->prepare("INSERT INTO prep_recommendations (organization_id,plan_id,public_id,normalized_key,title,recommended_quantity,unit,station,confidence,sample_count,status,basis_json) VALUES (?,?,?,?,?,?,?,?,?,?,'proposed',?) ON DUPLICATE KEY UPDATE title=VALUES(title),recommended_quantity=IF(status='proposed',VALUES(recommended_quantity),recommended_quantity),station=IF(status='proposed',VALUES(station),station),confidence=VALUES(confidence),sample_count=VALUES(sample_count),basis_json=VALUES(basis_json),updated_at=NOW(6)");$created=0;
    foreach($baselines as $base){
        $qty=round((float)$base['recommendedBase']*$mult,3);if($qty<=0)continue;$basis=['method'=>'historical_same_weekday','average'=>$base['average'],'median'=>$base['median'],'sampleCount'=>$base['sampleCount'],'sameWeekday'=>$base['sameWeekday'],'demandMultiplier'=>$mult,'lastCompleted'=>$base['lastCompleted']];$public='rec-'.substr(hash('sha256',(string)$plan['public_id'].'|'.$base['key'].'|'.$base['unit']),0,24);
        $upsert->execute([$organizationId,(int)$plan['id'],$public,$base['key'],$base['title'],$qty,$base['unit'],$base['station']?:null,$base['confidence'],$base['sampleCount'],json_encode($basis,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);$created++;
    }
    $pdo->prepare("UPDATE prep_plans SET generated_source='history+operations',generated_at=NOW(6),updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([$userId,(int)$plan['id'],$organizationId]);
    prep_event($pdo,$organizationId,(int)$plan['id'],'recommendations_generated','Prep recommendations regenerated from operational history.',$userId,['recommendations'=>$created,'multiplier'=>$mult]);
    prep_inventory_forecasts_rebuild($pdo,$organizationId,$plan,$userId);
    return prep_plan_detail($pdo,$organizationId,(string)$plan['public_id']);
}

function prep_recommendation(PDO $pdo,int $organizationId,string $publicId): ?array
{
    $stmt=$pdo->prepare('SELECT r.*,p.public_id plan_public_id,p.status plan_status FROM prep_recommendations r JOIN prep_plans p ON p.id=r.plan_id WHERE r.organization_id=? AND r.public_id=? LIMIT 1');$stmt->execute([$organizationId,$publicId]);$row=$stmt->fetch();return $row?:null;
}

function prep_update_recommendation(PDO $pdo,int $organizationId,string $publicId,array $input,?int $userId=null): array
{
    $row=prep_recommendation($pdo,$organizationId,$publicId);if(!$row)throw new InvalidArgumentException('Prep recommendation not found.');if($row['plan_status']==='closed')throw new RuntimeException('Closed prep plans cannot be edited.');
    $status=(string)($input['status']??$row['status']);if(!in_array($status,['proposed','accepted','dismissed','generated'],true))throw new InvalidArgumentException('Invalid recommendation status.');$qty=array_key_exists('quantity',$input)&&$input['quantity']!==''?(float)$input['quantity']:(float)$row['recommended_quantity'];if($qty<0)throw new InvalidArgumentException('Prep quantity cannot be negative.');$unit=mb_substr(trim((string)($input['unit']??$row['unit'])),0,80,'UTF-8');$station=mb_substr(trim((string)($input['station']??$row['station']??'')),0,120,'UTF-8');
    $pdo->prepare('UPDATE prep_recommendations SET recommended_quantity=?,unit=?,station=?,status=?,updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([$qty,$unit,$station?:null,$status,(int)$row['id'],$organizationId]);
    prep_event($pdo,$organizationId,(int)$row['plan_id'],'recommendation_updated','Prep recommendation updated.',$userId,['recommendation'=>$publicId,'quantity'=>$qty,'unit'=>$unit,'station'=>$station,'status'=>$status],null,(int)$row['id']);
    $plan=prep_plan($pdo,$organizationId,(string)$row['plan_public_id']);if($plan)prep_inventory_forecasts_rebuild($pdo,$organizationId,$plan,$userId);
    return prep_recommendation($pdo,$organizationId,$publicId)??[];
}

function prep_link_task(PDO $pdo,int $organizationId,array $plan,array $task,?int $userId=null,string $source='manual',?int $recommendationId=null): void
{
    $taskId=(int)($task['id']??0);if(!$taskId&&isset($task['public_id'])){$q=$pdo->prepare('SELECT id FROM restaurant_tasks WHERE organization_id=? AND public_id=? LIMIT 1');$q->execute([$organizationId,$task['public_id']]);$taskId=(int)$q->fetchColumn();}
    if(!$taskId)throw new InvalidArgumentException('Prep task could not be linked.');
    $pdo->prepare("INSERT IGNORE INTO prep_plan_tasks (organization_id,plan_id,task_id,recommendation_id,source,added_by) VALUES (?,?,?,?,?,?)")->execute([$organizationId,(int)$plan['id'],$taskId,$recommendationId,mb_substr($source,0,40,'UTF-8'),$userId]);
    prep_event($pdo,$organizationId,(int)$plan['id'],'task_linked','Prep task added to the plan.',$userId,['source'=>$source,'taskPublicId'=>$task['public_id']??null],$taskId,$recommendationId);
}

function prep_publish_plan(PDO $pdo,int $organizationId,array $plan,?int $userId=null): array
{
    if((string)$plan['status']==='closed')throw new RuntimeException('Closed prep plans cannot be published.');
    operations_ensure_default_categories($pdo,$organizationId,$userId);$stmt=$pdo->prepare("SELECT * FROM prep_recommendations WHERE organization_id=? AND plan_id=? AND status IN ('proposed','accepted','generated') ORDER BY title");$stmt->execute([$organizationId,(int)$plan['id']]);$recommendations=$stmt->fetchAll();$created=0;$linked=0;
    foreach($recommendations as $rec){
        if(!empty($rec['task_id'])){$linked++;continue;}
        $sourceId=(string)$rec['public_id'];$existing=$pdo->prepare("SELECT * FROM restaurant_tasks WHERE organization_id=? AND source_type='prep_plan' AND source_public_id=? LIMIT 1");$existing->execute([$organizationId,$sourceId]);$task=$existing->fetch();
        if(!$task){$task=operations_create_task($pdo,$organizationId,['title'=>$rec['title'],'category'=>'prep','quantity'=>$rec['recommended_quantity'],'unit'=>$rec['unit'],'station'=>$rec['station'],'priority'=>'normal','status'=>'queued','dueAt'=>prep_due_at($plan),'sourceType'=>'prep_plan','sourcePublicId'=>$sourceId,'description'=>'Generated from Prep Intelligence historical baseline.','aiConfidence'=>$rec['confidence']],$userId);$created++;}
        prep_link_task($pdo,$organizationId,$plan,$task,$userId,'recommendation',(int)$rec['id']);$taskId=(int)($task['id']??0);if(!$taskId){$q=$pdo->prepare('SELECT id FROM restaurant_tasks WHERE organization_id=? AND public_id=?');$q->execute([$organizationId,$task['public_id']]);$taskId=(int)$q->fetchColumn();}
        $pdo->prepare("UPDATE prep_recommendations SET status='generated',task_id=?,updated_at=NOW(6) WHERE id=?")->execute([$taskId,(int)$rec['id']]);$linked++;
    }
    $pdo->prepare("UPDATE prep_plans SET status='published',published_at=NOW(6),published_by=?,revision=revision+1,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([$userId,$userId,(int)$plan['id'],$organizationId]);
    prep_event($pdo,$organizationId,(int)$plan['id'],'published','Prep plan published to the canonical restaurant task engine.',$userId,['createdTasks'=>$created,'linkedTasks'=>$linked]);
    return prep_plan_detail($pdo,$organizationId,(string)$plan['public_id']);
}

function prep_close_plan(PDO $pdo,int $organizationId,array $plan,?int $userId=null): array
{
    $pdo->prepare("UPDATE prep_plans SET status='closed',closed_at=NOW(6),closed_by=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([$userId,$userId,(int)$plan['id'],$organizationId]);prep_event($pdo,$organizationId,(int)$plan['id'],'closed','Prep plan closed.',$userId);return prep_plan($pdo,$organizationId,(string)$plan['public_id'])??[];
}

function prep_add_demand_signal(PDO $pdo,int $organizationId,array $input,?int $userId=null): array
{
    $date=(string)($input['date']??date('Y-m-d'));$service=prep_service_period((string)($input['service']??'all'));$type=mb_substr(trim((string)($input['type']??'manual')),0,50,'UTF-8')?:'manual';$name=mb_substr(trim((string)($input['itemName']??'')),0,240,'UTF-8');$key=$name!==''?prep_normalized_key($name):null;$qty=isset($input['quantity'])&&$input['quantity']!==''?(float)$input['quantity']:null;$unit=mb_substr(trim((string)($input['unit']??'')),0,80,'UTF-8');$confidence=isset($input['confidence'])?max(0,min(1,(float)$input['confidence'])):1.0;$public=prep_public_id('signal');$metadata=$input['metadata']??null;
    $pdo->prepare('INSERT INTO prep_demand_signals (organization_id,public_id,signal_date,service_period,signal_type,item_key,item_name,quantity,unit,confidence,source_type,source_public_id,metadata_json,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$organizationId,$public,$date,$service,$type,$key,$name?:null,$qty,$unit?:null,$confidence,($input['sourceType']??'')?:null,($input['sourcePublicId']??'')?:null,$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null,$userId]);
    $q=$pdo->prepare('SELECT * FROM prep_demand_signals WHERE organization_id=? AND public_id=?');$q->execute([$organizationId,$public]);return $q->fetch()?:[];
}

function prep_inventory_forecasts_rebuild(PDO $pdo,int $organizationId,array $plan,?int $userId=null): array
{
    $pdo->prepare('DELETE FROM inventory_forecasts WHERE organization_id=? AND plan_id=?')->execute([$organizationId,(int)$plan['id']]);$aggregates=[];
    $recs=$pdo->prepare("SELECT * FROM prep_recommendations WHERE organization_id=? AND plan_id=? AND status<>'dismissed' AND recommended_quantity>0");$recs->execute([$organizationId,(int)$plan['id']]);$recommendations=$recs->fetchAll();$recipeMap=[];
    if(restaurant_brain_table_ready($pdo,'recipes')){$q=$pdo->prepare("SELECT public_id,name FROM recipes WHERE organization_id=? AND status='active' AND archived_at IS NULL");$q->execute([$organizationId]);foreach($q->fetchAll() as $recipe)$recipeMap[prep_normalized_key((string)$recipe['name'])]=$recipe;}
    foreach($recommendations as $rec){
        $unit=prep_normalized_unit((string)$rec['unit']);if(!in_array($unit,['batch','ea','tray','pan','case'],true))continue;$recipe=$recipeMap[(string)$rec['normalized_key']]??null;if(!$recipe)continue;$sourceId='recipe:'.$recipe['public_id'];$q=$pdo->prepare("SELECT s.quantity_per_source,s.unit,i.* FROM inventory_item_sources s JOIN inventory_items i ON i.id=s.inventory_item_id WHERE s.organization_id=? AND s.source_type='recipe' AND s.source_public_id=? AND s.quantity_per_source IS NOT NULL AND i.archived_at IS NULL");$q->execute([$organizationId,$sourceId]);
        foreach($q->fetchAll() as $row){$sourceUnit=prep_normalized_unit((string)($row['unit']??''));if($sourceUnit==='')continue;$key=(int)$row['id'].'|'.$sourceUnit;if(!isset($aggregates[$key]))$aggregates[$key]=['item'=>$row,'unit'=>$sourceUnit,'required'=>0.0,'confidence'=>1.0,'recipes'=>[]];$required=(float)$row['quantity_per_source']*(float)$rec['recommended_quantity'];$aggregates[$key]['required']+=$required;$aggregates[$key]['confidence']=min($aggregates[$key]['confidence'],(float)($rec['confidence']??.5));$aggregates[$key]['recipes'][]=['recipe'=>$recipe['name'],'prepQuantity'=>(float)$rec['recommended_quantity'],'ingredientPerBatch'=>(float)$row['quantity_per_source'],'required'=>$required];}
    }
    $items=$pdo->prepare("SELECT * FROM inventory_items WHERE organization_id=? AND archived_at IS NULL AND (par_level IS NOT NULL OR reorder_point IS NOT NULL)");$items->execute([$organizationId]);foreach($items->fetchAll() as $row){$unit=prep_normalized_unit((string)($row['base_unit']??''));$key=(int)$row['id'].'|'.$unit;if(!isset($aggregates[$key])&&((float)$row['on_hand_quantity']<=(float)($row['reorder_point']??-INF)))$aggregates[$key]=['item'=>$row,'unit'=>$unit,'required'=>0.0,'confidence'=>1.0,'recipes'=>[]];}
    $insert=$pdo->prepare("INSERT INTO inventory_forecasts (organization_id,plan_id,inventory_item_id,public_id,required_quantity,unit,on_hand_quantity,projected_quantity,shortage_quantity,restock_quantity,confidence,basis_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");$rows=[];
    foreach($aggregates as $agg){$item=$agg['item'];$base=prep_normalized_unit((string)($item['base_unit']??''));$comparable=$base===''||$agg['unit']===''||$base===$agg['unit'];$on=(float)$item['on_hand_quantity'];$required=round((float)$agg['required'],4);$projected=$comparable?$on-$required:$on;$shortage=$comparable?max(0,$required-$on):0.0;$par=$item['par_level']!==null?(float)$item['par_level']:($item['reorder_point']!==null?(float)$item['reorder_point']:0.0);$restock=$comparable?max(0,$par-$projected):0.0;$public='forecast-'.substr(hash('sha256',(string)$plan['public_id'].'|'.(string)$item['public_id'].'|'.$agg['unit']),0,24);$basis=['comparableUnits'=>$comparable,'inventoryBaseUnit'=>$base,'forecastUnit'=>$agg['unit'],'recipes'=>$agg['recipes'],'parLevel'=>$item['par_level'],'reorderPoint'=>$item['reorder_point']];$insert->execute([$organizationId,(int)$plan['id'],(int)$item['id'],$public,$required,$agg['unit'],$on,$projected,$shortage,$restock,round((float)$agg['confidence'],4),json_encode($basis,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);$rows[]=['public_id'=>$public,'inventory_name'=>$item['name'],'required_quantity'=>$required,'unit'=>$agg['unit'],'on_hand_quantity'=>$on,'projected_quantity'=>$projected,'shortage_quantity'=>$shortage,'restock_quantity'=>$restock,'confidence'=>$agg['confidence'],'basis'=>$basis];}
    return $rows;
}

function prep_plan_detail(PDO $pdo,int $organizationId,string $publicId): array
{
    $plan=prep_plan($pdo,$organizationId,$publicId);if(!$plan)throw new InvalidArgumentException('Prep plan not found.');
    $q=$pdo->prepare('SELECT * FROM prep_recommendations WHERE organization_id=? AND plan_id=? ORDER BY FIELD(status,\'accepted\',\'proposed\',\'generated\',\'dismissed\'),title');$q->execute([$organizationId,(int)$plan['id']]);$recommendations=$q->fetchAll();foreach($recommendations as &$r){$r['basis']=$r['basis_json']?json_decode((string)$r['basis_json'],true):null;unset($r['basis_json']);}
    $q=$pdo->prepare("SELECT t.public_id,t.title,t.quantity,t.unit,t.station,t.priority,t.status,t.due_at,t.source_type,t.source_public_id,pt.source,pt.created_at,(SELECT GROUP_CONCAT(u.display_name ORDER BY u.display_name SEPARATOR ', ') FROM restaurant_task_assignments a JOIN users u ON u.id=a.user_id WHERE a.task_id=t.id) assigned_names FROM prep_plan_tasks pt JOIN restaurant_tasks t ON t.id=pt.task_id WHERE pt.organization_id=? AND pt.plan_id=? ORDER BY FIELD(t.status,'in_progress','assigned','queued','accepted','blocked','completed','verified','cancelled'),t.title");$q->execute([$organizationId,(int)$plan['id']]);$tasks=$q->fetchAll();
    $q=$pdo->prepare("SELECT f.*,i.name inventory_name,i.base_unit,i.par_level,i.reorder_point,i.storage_location FROM inventory_forecasts f JOIN inventory_items i ON i.id=f.inventory_item_id WHERE f.organization_id=? AND f.plan_id=? ORDER BY (f.shortage_quantity>0 OR f.restock_quantity>0) DESC,f.shortage_quantity DESC,f.restock_quantity DESC,i.name");$q->execute([$organizationId,(int)$plan['id']]);$forecasts=$q->fetchAll();foreach($forecasts as &$f){$f['basis']=$f['basis_json']?json_decode((string)$f['basis_json'],true):null;unset($f['basis_json']);}
    $q=$pdo->prepare("SELECT e.*,u.display_name actor_name FROM prep_plan_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.organization_id=? AND e.plan_id=? ORDER BY e.created_at DESC LIMIT 200");$q->execute([$organizationId,(int)$plan['id']]);$events=$q->fetchAll();foreach($events as &$e){$e['metadata']=$e['metadata_json']?json_decode((string)$e['metadata_json'],true):null;unset($e['metadata_json']);}
    $signals=$pdo->prepare('SELECT * FROM prep_demand_signals WHERE organization_id=? AND signal_date=? AND service_period IN (?,\'all\') ORDER BY created_at DESC LIMIT 100');$signals->execute([$organizationId,$plan['plan_date'],$plan['service_period']]);
    return ['plan'=>$plan,'recommendations'=>$recommendations,'tasks'=>$tasks,'forecasts'=>$forecasts,'commitments'=>prep_source_commitments($pdo,$organizationId,$plan),'signals'=>$signals->fetchAll(),'events'=>$events];
}

function prep_save_plan(PDO $pdo,int $organizationId,array $plan,array $input,?int $userId=null): array
{
    if((string)$plan['status']==='closed')throw new RuntimeException('Closed prep plans cannot be edited.');$title=mb_substr(trim((string)($input['title']??$plan['title'])),0,220,'UTF-8')?:$plan['title'];$covers=isset($input['projectedCovers'])&&$input['projectedCovers']!==''?max(0,(int)$input['projectedCovers']):null;$mult=isset($input['demandMultiplier'])?max(.25,min(4.0,(float)$input['demandMultiplier'])):(float)$plan['demand_multiplier'];$notes=mb_substr(trim((string)($input['notes']??$plan['notes']??'')),0,8000,'UTF-8');
    $pdo->prepare('UPDATE prep_plans SET title=?,projected_covers=?,demand_multiplier=?,notes=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([$title,$covers,$mult,$notes?:null,$userId,(int)$plan['id'],$organizationId]);prep_event($pdo,$organizationId,(int)$plan['id'],'plan_updated','Prep plan settings updated.',$userId,['projectedCovers'=>$covers,'demandMultiplier'=>$mult]);return prep_plan($pdo,$organizationId,(string)$plan['public_id'])??[];
}

function prep_attach_manual_task(PDO $pdo,int $organizationId,array $plan,array $input,?int $userId=null,string $source='manual'): array
{
    $task=operations_create_task($pdo,$organizationId,['title'=>$input['title']??'','category'=>'prep','quantity'=>$input['quantity']??null,'unit'=>$input['unit']??null,'station'=>$input['station']??null,'priority'=>$input['priority']??'normal','status'=>'queued','dueAt'=>$input['dueAt']??prep_due_at($plan),'originalTranscript'=>$input['transcript']??null,'transcript'=>$input['transcript']??null,'aiConfidence'=>$input['aiConfidence']??null,'assigneeIds'=>$input['assigneeIds']??[]],$userId);prep_link_task($pdo,$organizationId,$plan,$task,$userId,$source);return $task;
}

function prep_parse_date_phrase(string $phrase,?DateTimeImmutable $now=null): string
{
    $now=$now??new DateTimeImmutable('now');$text=mb_strtolower(trim($phrase),'UTF-8');if(preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/',$text,$m))return $m[1];if(preg_match('/\b(today|tomorrow|yesterday|last\s+(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday))\b/',$text,$m)){try{return $now->modify($m[1])->format('Y-m-d');}catch(Throwable){}}
    return $now->format('Y-m-d');
}

function prep_weekday_from_text(string $text): ?int
{
    $days=['monday'=>1,'tuesday'=>2,'wednesday'=>3,'thursday'=>4,'friday'=>5,'saturday'=>6,'sunday'=>7];foreach($days as $name=>$n)if(preg_match('/\b'.$name.'s?\b/i',$text))return $n;return null;
}

function prep_item_history_summary(PDO $pdo,int $organizationId,string $query,?int $weekday=null,int $weeks=12): array
{
    $from=(new DateTimeImmutable('today'))->modify('-'.max(2,min(52,$weeks)).' weeks')->format('Y-m-d');$stmt=$pdo->prepare("SELECT t.title,t.quantity,t.unit,t.station,t.status,COALESCE(t.due_at,t.created_at) activity_at FROM restaurant_tasks t LEFT JOIN task_categories c ON c.id=t.category_id WHERE t.organization_id=? AND c.slug='prep' AND t.archived_at IS NULL AND t.status IN ('completed','verified') AND t.quantity IS NOT NULL AND DATE(COALESCE(t.due_at,t.created_at))>=? AND t.title LIKE ? ORDER BY activity_at DESC LIMIT 500");$stmt->execute([$organizationId,$from,'%'.trim($query).'%']);$rows=[];foreach($stmt->fetchAll() as $row){$dt=new DateTimeImmutable((string)$row['activity_at']);if($weekday!==null&&(int)$dt->format('N')!==$weekday)continue;$rows[]=$row;}
    if(!$rows)return ['query'=>$query,'weekday'=>$weekday,'sampleCount'=>0,'average'=>null,'median'=>null,'unit'=>null,'lastCompleted'=>null,'samples'=>[]];$values=array_map(static fn(array $r):float=>(float)$r['quantity'],$rows);sort($values);$count=count($values);$mid=(int)floor(($count-1)/2);$median=$count%2?$values[$mid]:($values[$mid]+$values[$mid+1])/2;$units=array_count_values(array_map(static fn(array $r):string=>prep_normalized_unit((string)$r['unit']),$rows));arsort($units);$unit=(string)array_key_first($units);
    return ['query'=>$query,'weekday'=>$weekday,'sampleCount'=>$count,'average'=>round(array_sum($values)/$count,3),'median'=>round($median,3),'unit'=>$unit,'lastCompleted'=>$rows[0]['activity_at'],'samples'=>array_slice($rows,0,20)];
}
