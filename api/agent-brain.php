<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/equipment-brain.php';

$user = app_require_auth();
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];

function brain_require_equipment(array $user): void
{
    if (!app_has_permission('agent.equipment_skills', $user) || !app_has_permission('equipment.view', $user)) {
        app_json_response(['ok'=>false,'message'=>'You do not have permission to use equipment agent skills.'],403);
    }
}

function brain_asset_search(PDO $pdo, int $organizationId, string $query, int $limit = 12): array
{
    $query = trim($query);
    $limit = max(1,min(30,$limit));
    $like = '%' . $query . '%';
    $statement = $pdo->prepare(
        "SELECT public_id,name,asset_type,purpose,brand,manufacturer,model,serial_number,asset_tag,manufacture_year,
                operational_status,condition_status,criticality,location_name,next_service_on,last_service_on,maintenance_required
         FROM equipment_assets
         WHERE organization_id=? AND archived_at IS NULL AND
           (?='' OR name LIKE ? OR asset_type LIKE ? OR purpose LIKE ? OR brand LIKE ? OR manufacturer LIKE ? OR model LIKE ? OR serial_number LIKE ? OR asset_tag LIKE ? OR location_name LIKE ?)
         ORDER BY FIELD(criticality,'critical','high','medium','low'), name LIMIT {$limit}"
    );
    $statement->execute([$organizationId,$query,$like,$like,$like,$like,$like,$like,$like,$like,$like]);
    return array_map(static fn(array $row): array => [
        'id'=>(string)$row['public_id'],'name'=>(string)$row['name'],'assetType'=>(string)$row['asset_type'],'purpose'=>(string)($row['purpose']??''),
        'brand'=>(string)($row['brand']??''),'manufacturer'=>(string)($row['manufacturer']??''),'model'=>(string)($row['model']??''),'serialNumber'=>(string)($row['serial_number']??''),
        'assetTag'=>(string)($row['asset_tag']??''),'manufactureYear'=>$row['manufacture_year']!==null?(int)$row['manufacture_year']:null,
        'operationalStatus'=>(string)$row['operational_status'],'conditionStatus'=>(string)$row['condition_status'],'criticality'=>(string)$row['criticality'],
        'locationName'=>(string)($row['location_name']??''),'nextServiceOn'=>$row['next_service_on'],'lastServiceOn'=>$row['last_service_on'],'maintenanceRequired'=>(bool)$row['maintenance_required'],
    ],$statement->fetchAll());
}

function brain_service_contacts(PDO $pdo, int $organizationId, string $assetPublicId = '', string $specialty = ''): array
{
    $params=[$organizationId];
    $join='';$where="c.organization_id=? AND c.archived_at IS NULL AND c.status='active'";
    if($assetPublicId!==''){
        $join=' INNER JOIN equipment_asset_service_contacts link ON link.service_contact_id=c.id INNER JOIN equipment_assets a ON a.id=link.equipment_asset_id ';
        $where.=' AND a.organization_id=? AND a.public_id=? AND a.archived_at IS NULL';$params[]=$organizationId;$params[]=$assetPublicId;
    }
    if($specialty!==''){$where.=' AND c.specialty LIKE ?';$params[]='%'.$specialty.'%';}
    $statement=$pdo->prepare("SELECT DISTINCT c.public_id,c.company_name,c.contact_name,c.specialty,c.phone,c.email,c.website,c.emergency_phone,c.is_preferred,c.is_warranty_provider FROM equipment_service_contacts c {$join} WHERE {$where} ORDER BY c.is_preferred DESC,c.is_warranty_provider DESC,c.company_name LIMIT 30");
    $statement->execute($params);
    return array_map(static fn(array $row):array=>[
        'id'=>(string)$row['public_id'],'companyName'=>(string)$row['company_name'],'contactName'=>(string)($row['contact_name']??''),'specialty'=>(string)($row['specialty']??''),
        'phone'=>(string)($row['phone']??''),'email'=>(string)($row['email']??''),'website'=>(string)($row['website']??''),'emergencyPhone'=>(string)($row['emergency_phone']??''),
        'preferred'=>(bool)$row['is_preferred'],'warrantyProvider'=>(bool)$row['is_warranty_provider'],
    ],$statement->fetchAll());
}

function brain_asset_context(PDO $pdo,int $organizationId,string $publicId): ?array
{
    $statement=$pdo->prepare('SELECT * FROM equipment_assets WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');
    $statement->execute([$organizationId,$publicId]);$asset=$statement->fetch();if(!$asset)return null;
    return [
        'id'=>(string)$asset['public_id'],'name'=>(string)$asset['name'],'knowledge'=>equipment_brain_asset_text($pdo,$organizationId,$asset),
        'contacts'=>equipment_brain_asset_contacts($pdo,$organizationId,(int)$asset['id']),
        'serviceHistory'=>equipment_brain_asset_events($pdo,$organizationId,(int)$asset['id'],20),
    ];
}

function brain_answer(PDO $pdo,int $organizationId,string $message): array
{
    $normalized=mb_strtolower(trim($message),'UTF-8');
    if($normalized==='')return ['skill'=>'none','answer'=>'Ask me about equipment, maintenance, service history, brands, models, locations, or service contacts.','data'=>[],'sources'=>[]];

    if(preg_match('/\b(overdue|due|maintenance|service soon|needs service|preventive)\b/u',$normalized)){
        $days=preg_match('/\b(\d{1,3})\s*days?\b/u',$normalized,$match)?max(0,min(365,(int)$match[1])):30;
        $due=equipment_brain_maintenance_due($pdo,$organizationId,$days);
        if(!$due)return ['skill'=>'equipment.maintenance_due','answer'=>"No maintenance is overdue or due within {$days} days based on the current equipment schedule.",'data'=>[],'sources'=>[]];
        $lines=[];foreach(array_slice($due,0,12) as $item){$when=$item['next_service_on']?:'unscheduled';$lines[]=$item['name'].' — '.$item['asset_type'].' — due '.$when.' — '.$item['criticality'].' criticality';}
        return ['skill'=>'equipment.maintenance_due','answer'=>"Equipment maintenance requiring attention:\n- ".implode("\n- ",$lines),'data'=>$due,'sources'=>array_column($due,'public_id')];
    }

    if(preg_match('/\b(contact|technician|repair company|service company|vendor|who.*call|warranty)\b/u',$normalized)){
        $assets=brain_asset_search($pdo,$organizationId,$message,5);$assetId=count($assets)===1?$assets[0]['id']:'';
        $contacts=brain_service_contacts($pdo,$organizationId,$assetId,'');
        if(!$contacts)return ['skill'=>'equipment.service_contacts','answer'=>'No matching equipment service contacts are recorded yet. Add a service company in the Equipment Catalog and link it to the asset.','data'=>[],'sources'=>[]];
        $lines=[];foreach(array_slice($contacts,0,10) as $contact){$lines[]=$contact['companyName'].($contact['contactName']?' — '.$contact['contactName']:'').($contact['specialty']?' — '.$contact['specialty']:'').($contact['phone']?' — '.$contact['phone']:'').($contact['emergencyPhone']?' — emergency '.$contact['emergencyPhone']:'');}
        return ['skill'=>'equipment.service_contacts','answer'=>"Recorded service contacts:\n- ".implode("\n- ",$lines),'data'=>$contacts,'sources'=>array_column($contacts,'id')];
    }

    $assets=brain_asset_search($pdo,$organizationId,$message,12);
    if($assets){
        if(count($assets)===1){$context=brain_asset_context($pdo,$organizationId,$assets[0]['id']);return ['skill'=>'equipment.asset_context','answer'=>$context?mb_substr($context['knowledge'],0,6000,'UTF-8'):'Equipment record found.','data'=>$context?:$assets[0],'sources'=>[$assets[0]['id']]];}
        $lines=[];foreach($assets as $asset){$lines[]=$asset['name'].' — '.$asset['assetType'].($asset['brand']?' — '.$asset['brand']:'').($asset['model']?' '.$asset['model']:'').' — '.$asset['operationalStatus'];}
        return ['skill'=>'equipment.search','answer'=>"Matching equipment records:\n- ".implode("\n- ",$lines),'data'=>$assets,'sources'=>array_column($assets,'id')];
    }

    $knowledge=equipment_brain_search($pdo,$organizationId,$message,6);
    if($knowledge){
        $excerpts=[];$sources=[];foreach($knowledge as $record){$excerpts[]=mb_substr((string)$record['content'],0,1200,'UTF-8');$sources[]=(string)$record['source_public_id'];}
        return ['skill'=>'knowledge.search','answer'=>implode("\n\n",$excerpts),'data'=>$knowledge,'sources'=>array_values(array_unique($sources))];
    }
    return ['skill'=>'equipment.search','answer'=>'I could not find that in the internal equipment knowledge base. Add or update the equipment record so the agent has the missing facts.','data'=>[],'sources'=>[]];
}

if(!equipment_brain_table_ready($pdo,'equipment_assets')||!equipment_brain_table_ready($pdo,'agent_knowledge_records')){
    app_json_response(['ok'=>false,'message'=>'Equipment Agent Brain migration is not installed. Import database/20260912_equipment_catalog_brain.sql first.'],503);
}
brain_require_equipment($user);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'summary');
    if($action==='summary')app_json_response(['ok'=>true,'skill'=>'equipment.summary','summary'=>equipment_brain_summary($pdo,$organizationId),'maintenanceDue'=>equipment_brain_maintenance_due($pdo,$organizationId,30)]);
    if($action==='search')app_json_response(['ok'=>true,'skill'=>'equipment.search','results'=>brain_asset_search($pdo,$organizationId,(string)($_GET['q']??''))]);
    app_json_response(['ok'=>false,'message'=>'Unsupported agent skill.'],422);
}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'ask');
if($action==='ask'){
    $message=trim((string)($input['message']??''));if($message===''||mb_strlen($message,'UTF-8')>1200)app_json_response(['ok'=>false,'message'=>'Enter an equipment question no longer than 1,200 characters.'],422);
    $result=brain_answer($pdo,$organizationId,$message);
    app_audit($pdo,$organizationId,(int)$user['id'],'agent.equipment_skill_used','agent_skill',$result['skill'],null,['message'=>mb_substr($message,0,300,'UTF-8'),'sources'=>$result['sources']]);
    app_json_response(['ok'=>true]+$result);
}
if($action==='run_skill'){
    $skill=(string)($input['skill']??'');$args=(array)($input['arguments']??[]);
    if($skill==='equipment.search')$result=['skill'=>$skill,'data'=>brain_asset_search($pdo,$organizationId,(string)($args['query']??''),(int)($args['limit']??12))];
    elseif($skill==='equipment.maintenance_due')$result=['skill'=>$skill,'data'=>equipment_brain_maintenance_due($pdo,$organizationId,(int)($args['days']??30))];
    elseif($skill==='equipment.service_contacts')$result=['skill'=>$skill,'data'=>brain_service_contacts($pdo,$organizationId,(string)($args['assetId']??''),(string)($args['specialty']??''))];
    elseif($skill==='equipment.asset_context'){$context=brain_asset_context($pdo,$organizationId,(string)($args['assetId']??''));$result=['skill'=>$skill,'data'=>$context];}
    elseif($skill==='knowledge.search')$result=['skill'=>$skill,'data'=>equipment_brain_search($pdo,$organizationId,(string)($args['query']??''),(int)($args['limit']??8))];
    else app_json_response(['ok'=>false,'message'=>'Unknown equipment agent skill.'],422);
    app_audit($pdo,$organizationId,(int)$user['id'],'agent.equipment_skill_used','agent_skill',$skill,null,['arguments'=>$args]);
    app_json_response(['ok'=>true]+$result);
}
app_json_response(['ok'=>false,'message'=>'Unsupported agent brain action.'],422);
