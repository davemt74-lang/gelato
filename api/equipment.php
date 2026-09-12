<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/equipment-brain.php';

$user = app_require_auth();
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];
$userId = (int)$user['id'];

function equipment_require(array $user, string $permission): void
{
    if (!app_has_permission($permission, $user)) {
        app_json_response(['ok' => false, 'message' => 'You do not have permission to complete this equipment action.'], 403);
    }
}

function equipment_table_ready(PDO $pdo): bool
{
    return equipment_brain_table_ready($pdo, 'equipment_assets')
        && equipment_brain_table_ready($pdo, 'equipment_service_contacts')
        && equipment_brain_table_ready($pdo, 'equipment_service_events');
}

function equipment_clean_id(string $value): string
{
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $value) ?: '';
}

function equipment_uuid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(10));
}

function equipment_nullable_string(mixed $value, int $max = 1000): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    return mb_substr($value, 0, $max, 'UTF-8');
}

function equipment_nullable_date(mixed $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('One or more equipment dates are invalid.');
    }
    return $value;
}

function equipment_nullable_decimal(mixed $value): ?float
{
    if ($value === null || trim((string)$value) === '') return null;
    if (!is_numeric($value)) throw new InvalidArgumentException('One or more numeric equipment values are invalid.');
    return round((float)$value, 2);
}

function equipment_asset_payload(PDO $pdo, int $organizationId, array $row): array
{
    $contactStatement = $pdo->prepare(
        "SELECT c.public_id, c.company_name, c.contact_name, c.specialty, c.phone, c.email, c.emergency_phone,
                c.is_preferred, c.is_warranty_provider, link.contact_role, link.is_primary
         FROM equipment_asset_service_contacts link
         INNER JOIN equipment_service_contacts c ON c.id=link.service_contact_id
         WHERE link.equipment_asset_id=? AND c.organization_id=? AND c.archived_at IS NULL
         ORDER BY link.is_primary DESC, c.is_preferred DESC, c.company_name"
    );
    $contactStatement->execute([(int)$row['id'], $organizationId]);
    return [
        'id' => (string)$row['public_id'],
        'name' => (string)$row['name'],
        'assetType' => (string)$row['asset_type'],
        'purpose' => (string)($row['purpose'] ?? ''),
        'brand' => (string)($row['brand'] ?? ''),
        'manufacturer' => (string)($row['manufacturer'] ?? ''),
        'model' => (string)($row['model'] ?? ''),
        'serialNumber' => (string)($row['serial_number'] ?? ''),
        'assetTag' => (string)($row['asset_tag'] ?? ''),
        'manufactureYear' => $row['manufacture_year'] !== null ? (int)$row['manufacture_year'] : null,
        'purchaseDate' => $row['purchase_date'],
        'installDate' => $row['install_date'],
        'expectedLifeYears' => $row['expected_life_years'] !== null ? (float)$row['expected_life_years'] : null,
        'warrantyExpiresOn' => $row['warranty_expires_on'],
        'purchasePrice' => $row['purchase_price'] !== null ? (float)$row['purchase_price'] : null,
        'replacementCost' => $row['replacement_cost'] !== null ? (float)$row['replacement_cost'] : null,
        'operationalStatus' => (string)$row['operational_status'],
        'conditionStatus' => (string)$row['condition_status'],
        'criticality' => (string)$row['criticality'],
        'locationName' => (string)($row['location_name'] ?? ''),
        'floorPlanId' => (string)($row['floor_plan_public_id'] ?? ''),
        'floorPlanComponentId' => (string)($row['floor_plan_component_id'] ?? ''),
        'widthInches' => $row['width_inches'] !== null ? (float)$row['width_inches'] : null,
        'depthInches' => $row['depth_inches'] !== null ? (float)$row['depth_inches'] : null,
        'heightInches' => $row['height_inches'] !== null ? (float)$row['height_inches'] : null,
        'capacityText' => (string)($row['capacity_text'] ?? ''),
        'utilityType' => (string)($row['utility_type'] ?? ''),
        'voltage' => (string)($row['voltage'] ?? ''),
        'phase' => (string)($row['phase'] ?? ''),
        'amperage' => (string)($row['amperage'] ?? ''),
        'btuRating' => (string)($row['btu_rating'] ?? ''),
        'waterRequirement' => (string)($row['water_requirement'] ?? ''),
        'drainRequirement' => (string)($row['drain_requirement'] ?? ''),
        'ventilationRequirement' => (string)($row['ventilation_requirement'] ?? ''),
        'maintenanceRequired' => (bool)$row['maintenance_required'],
        'maintenanceIntervalDays' => $row['maintenance_interval_days'] !== null ? (int)$row['maintenance_interval_days'] : null,
        'lastServiceOn' => $row['last_service_on'],
        'nextServiceOn' => $row['next_service_on'],
        'cleaningNotes' => (string)($row['cleaning_notes'] ?? ''),
        'maintenanceNotes' => (string)($row['maintenance_notes'] ?? ''),
        'operatingNotes' => (string)($row['operating_notes'] ?? ''),
        'safetyNotes' => (string)($row['safety_notes'] ?? ''),
        'partsConsumables' => (string)($row['parts_consumables'] ?? ''),
        'manualUrl' => (string)($row['manual_url'] ?? ''),
        'notes' => (string)($row['notes'] ?? ''),
        'contacts' => array_map(static fn(array $contact): array => [
            'id' => (string)$contact['public_id'],
            'companyName' => (string)$contact['company_name'],
            'contactName' => (string)($contact['contact_name'] ?? ''),
            'specialty' => (string)($contact['specialty'] ?? ''),
            'phone' => (string)($contact['phone'] ?? ''),
            'email' => (string)($contact['email'] ?? ''),
            'emergencyPhone' => (string)($contact['emergency_phone'] ?? ''),
            'preferred' => (bool)$contact['is_preferred'],
            'warrantyProvider' => (bool)$contact['is_warranty_provider'],
            'role' => (string)$contact['contact_role'],
            'primary' => (bool)$contact['is_primary'],
        ], $contactStatement->fetchAll()),
        'createdAt' => (string)$row['created_at'],
        'updatedAt' => (string)$row['updated_at'],
    ];
}

function equipment_list_assets(PDO $pdo, int $organizationId): array
{
    $statement = $pdo->prepare('SELECT * FROM equipment_assets WHERE organization_id=? AND archived_at IS NULL ORDER BY FIELD(criticality,\'critical\',\'high\',\'medium\',\'low\'), name');
    $statement->execute([$organizationId]);
    return array_map(static fn(array $row): array => equipment_asset_payload($pdo, $organizationId, $row), $statement->fetchAll());
}

function equipment_contact_payload(array $row): array
{
    return [
        'id' => (string)$row['public_id'],
        'companyName' => (string)$row['company_name'],
        'contactName' => (string)($row['contact_name'] ?? ''),
        'specialty' => (string)($row['specialty'] ?? ''),
        'phone' => (string)($row['phone'] ?? ''),
        'email' => (string)($row['email'] ?? ''),
        'website' => (string)($row['website'] ?? ''),
        'emergencyPhone' => (string)($row['emergency_phone'] ?? ''),
        'accountNumber' => (string)($row['account_number'] ?? ''),
        'contractNumber' => (string)($row['contract_number'] ?? ''),
        'preferred' => (bool)$row['is_preferred'],
        'warrantyProvider' => (bool)$row['is_warranty_provider'],
        'status' => (string)$row['status'],
        'notes' => (string)($row['notes'] ?? ''),
        'updatedAt' => (string)$row['updated_at'],
    ];
}

function equipment_list_contacts(PDO $pdo, int $organizationId): array
{
    $statement = $pdo->prepare('SELECT * FROM equipment_service_contacts WHERE organization_id=? AND archived_at IS NULL ORDER BY is_preferred DESC, company_name');
    $statement->execute([$organizationId]);
    return array_map('equipment_contact_payload', $statement->fetchAll());
}

function equipment_list_events(PDO $pdo, int $organizationId, ?string $assetPublicId = null): array
{
    $params = [$organizationId];
    $where = 'e.organization_id=?';
    if ($assetPublicId) {
        $where .= ' AND a.public_id=?';
        $params[] = $assetPublicId;
    }
    $statement = $pdo->prepare(
        "SELECT e.*, a.public_id AS asset_public_id, a.name AS asset_name, c.public_id AS contact_public_id, c.company_name
         FROM equipment_service_events e
         INNER JOIN equipment_assets a ON a.id=e.equipment_asset_id
         LEFT JOIN equipment_service_contacts c ON c.id=e.service_contact_id
         WHERE {$where}
         ORDER BY e.serviced_on DESC, e.id DESC LIMIT 250"
    );
    $statement->execute($params);
    return array_map(static fn(array $row): array => [
        'id' => (string)$row['public_id'],
        'assetId' => (string)$row['asset_public_id'],
        'assetName' => (string)$row['asset_name'],
        'contactId' => (string)($row['contact_public_id'] ?? ''),
        'companyName' => (string)($row['company_name'] ?? ''),
        'eventType' => (string)$row['event_type'],
        'status' => (string)$row['status'],
        'servicedOn' => (string)$row['serviced_on'],
        'technicianName' => (string)($row['technician_name'] ?? ''),
        'description' => (string)$row['description'],
        'partsUsed' => (string)($row['parts_used'] ?? ''),
        'cost' => $row['cost'] !== null ? (float)$row['cost'] : null,
        'downtimeMinutes' => $row['downtime_minutes'] !== null ? (int)$row['downtime_minutes'] : null,
        'nextDueOn' => $row['next_due_on'],
        'createdAt' => (string)$row['created_at'],
    ], $statement->fetchAll());
}

if (!equipment_table_ready($pdo)) {
    app_json_response(['ok'=>false,'message'=>'Equipment Catalog migration is not installed. Import database/20260912_equipment_catalog_brain.sql first.'],503);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    equipment_require($user, 'equipment.view');
    $assetId = equipment_clean_id((string)($_GET['asset'] ?? ''));
    app_json_response([
        'ok' => true,
        'assets' => equipment_list_assets($pdo, $organizationId),
        'contacts' => equipment_list_contacts($pdo, $organizationId),
        'events' => equipment_list_events($pdo, $organizationId, $assetId ?: null),
        'summary' => equipment_brain_summary($pdo, $organizationId),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}

$input = app_json_input();
app_verify_request_csrf($input);
$action = (string)($input['action'] ?? '');

try {
    if ($action === 'save_asset') {
        equipment_require($user, 'equipment.edit');
        $asset = (array)($input['asset'] ?? []);
        $publicId = equipment_clean_id((string)($asset['id'] ?? ''));
        $isNew = $publicId === '';
        if ($isNew) $publicId = equipment_uuid('asset');
        $name = trim((string)($asset['name'] ?? ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 180) throw new InvalidArgumentException('Equipment name is required and must be 180 characters or fewer.');
        $assetType = trim((string)($asset['assetType'] ?? 'other')) ?: 'other';
        $allowedStatuses = ['active','maintenance','out_of_service','retired'];
        $operationalStatus = in_array((string)($asset['operationalStatus'] ?? ''), $allowedStatuses, true) ? (string)$asset['operationalStatus'] : 'active';
        $allowedConditions = ['excellent','good','fair','poor','unknown'];
        $conditionStatus = in_array((string)($asset['conditionStatus'] ?? ''), $allowedConditions, true) ? (string)$asset['conditionStatus'] : 'good';
        $allowedCriticality = ['low','medium','high','critical'];
        $criticality = in_array((string)($asset['criticality'] ?? ''), $allowedCriticality, true) ? (string)$asset['criticality'] : 'medium';
        $manufactureYear = trim((string)($asset['manufactureYear'] ?? '')) === '' ? null : (int)$asset['manufactureYear'];
        if ($manufactureYear !== null && ($manufactureYear < 1900 || $manufactureYear > (int)date('Y') + 1)) throw new InvalidArgumentException('Manufacture year is outside the supported range.');
        $maintenanceIntervalDays = trim((string)($asset['maintenanceIntervalDays'] ?? '')) === '' ? null : max(1, min(3650, (int)$asset['maintenanceIntervalDays']));
        $expectedLifeYears = equipment_nullable_decimal($asset['expectedLifeYears'] ?? null);
        $values = [
            'name'=>$name,'asset_type'=>$assetType,'purpose'=>equipment_nullable_string($asset['purpose'] ?? null,4000),
            'brand'=>equipment_nullable_string($asset['brand'] ?? null,160),'manufacturer'=>equipment_nullable_string($asset['manufacturer'] ?? null,160),
            'model'=>equipment_nullable_string($asset['model'] ?? null,160),'serial_number'=>equipment_nullable_string($asset['serialNumber'] ?? null,180),
            'asset_tag'=>equipment_nullable_string($asset['assetTag'] ?? null,120),'manufacture_year'=>$manufactureYear,
            'purchase_date'=>equipment_nullable_date($asset['purchaseDate'] ?? null),'install_date'=>equipment_nullable_date($asset['installDate'] ?? null),
            'expected_life_years'=>$expectedLifeYears,'warranty_expires_on'=>equipment_nullable_date($asset['warrantyExpiresOn'] ?? null),
            'purchase_price'=>equipment_nullable_decimal($asset['purchasePrice'] ?? null),'replacement_cost'=>equipment_nullable_decimal($asset['replacementCost'] ?? null),
            'operational_status'=>$operationalStatus,'condition_status'=>$conditionStatus,'criticality'=>$criticality,
            'location_name'=>equipment_nullable_string($asset['locationName'] ?? null,180),'floor_plan_public_id'=>equipment_nullable_string($asset['floorPlanId'] ?? null,80),
            'floor_plan_component_id'=>equipment_nullable_string($asset['floorPlanComponentId'] ?? null,120),
            'width_inches'=>equipment_nullable_decimal($asset['widthInches'] ?? null),'depth_inches'=>equipment_nullable_decimal($asset['depthInches'] ?? null),'height_inches'=>equipment_nullable_decimal($asset['heightInches'] ?? null),
            'capacity_text'=>equipment_nullable_string($asset['capacityText'] ?? null,255),'utility_type'=>equipment_nullable_string($asset['utilityType'] ?? null,120),
            'voltage'=>equipment_nullable_string($asset['voltage'] ?? null,80),'phase'=>equipment_nullable_string($asset['phase'] ?? null,40),'amperage'=>equipment_nullable_string($asset['amperage'] ?? null,80),
            'btu_rating'=>equipment_nullable_string($asset['btuRating'] ?? null,80),'water_requirement'=>equipment_nullable_string($asset['waterRequirement'] ?? null,255),
            'drain_requirement'=>equipment_nullable_string($asset['drainRequirement'] ?? null,255),'ventilation_requirement'=>equipment_nullable_string($asset['ventilationRequirement'] ?? null,255),
            'maintenance_required'=>!empty($asset['maintenanceRequired']) ? 1 : 0,'maintenance_interval_days'=>$maintenanceIntervalDays,
            'last_service_on'=>equipment_nullable_date($asset['lastServiceOn'] ?? null),'next_service_on'=>equipment_nullable_date($asset['nextServiceOn'] ?? null),
            'cleaning_notes'=>equipment_nullable_string($asset['cleaningNotes'] ?? null,8000),'maintenance_notes'=>equipment_nullable_string($asset['maintenanceNotes'] ?? null,8000),
            'operating_notes'=>equipment_nullable_string($asset['operatingNotes'] ?? null,8000),'safety_notes'=>equipment_nullable_string($asset['safetyNotes'] ?? null,8000),
            'parts_consumables'=>equipment_nullable_string($asset['partsConsumables'] ?? null,8000),'manual_url'=>equipment_nullable_string($asset['manualUrl'] ?? null,600),
            'notes'=>equipment_nullable_string($asset['notes'] ?? null,8000),
        ];
        $pdo->beginTransaction();
        if ($isNew) {
            $statement = $pdo->prepare(
                "INSERT INTO equipment_assets (organization_id,public_id,name,asset_type,purpose,brand,manufacturer,model,serial_number,asset_tag,manufacture_year,purchase_date,install_date,expected_life_years,warranty_expires_on,purchase_price,replacement_cost,operational_status,condition_status,criticality,location_name,floor_plan_public_id,floor_plan_component_id,width_inches,depth_inches,height_inches,capacity_text,utility_type,voltage,phase,amperage,btu_rating,water_requirement,drain_requirement,ventilation_requirement,maintenance_required,maintenance_interval_days,last_service_on,next_service_on,cleaning_notes,maintenance_notes,operating_notes,safety_notes,parts_consumables,manual_url,notes,created_by,updated_by)
                 VALUES (:organization_id,:public_id,:name,:asset_type,:purpose,:brand,:manufacturer,:model,:serial_number,:asset_tag,:manufacture_year,:purchase_date,:install_date,:expected_life_years,:warranty_expires_on,:purchase_price,:replacement_cost,:operational_status,:condition_status,:criticality,:location_name,:floor_plan_public_id,:floor_plan_component_id,:width_inches,:depth_inches,:height_inches,:capacity_text,:utility_type,:voltage,:phase,:amperage,:btu_rating,:water_requirement,:drain_requirement,:ventilation_requirement,:maintenance_required,:maintenance_interval_days,:last_service_on,:next_service_on,:cleaning_notes,:maintenance_notes,:operating_notes,:safety_notes,:parts_consumables,:manual_url,:notes,:user_id,:user_id)"
            );
            $statement->execute(['organization_id'=>$organizationId,'public_id'=>$publicId,'user_id'=>$userId] + $values);
            $assetDbId = (int)$pdo->lastInsertId();
            $auditAction = 'equipment.created';
        } else {
            $lookup = $pdo->prepare('SELECT id FROM equipment_assets WHERE organization_id=? AND public_id=? AND archived_at IS NULL FOR UPDATE');
            $lookup->execute([$organizationId,$publicId]);
            $assetDbId = (int)$lookup->fetchColumn();
            if ($assetDbId < 1) throw new InvalidArgumentException('Equipment asset not found.');
            $sets=[]; foreach(array_keys($values) as $key){$sets[]="$key=:$key";}
            $statement=$pdo->prepare('UPDATE equipment_assets SET '.implode(',',$sets).', updated_by=:user_id, updated_at=NOW(6) WHERE id=:asset_id AND organization_id=:organization_id');
            $statement->execute($values+['user_id'=>$userId,'asset_id'=>$assetDbId,'organization_id'=>$organizationId]);
            $auditAction='equipment.updated';
        }
        equipment_brain_sync_asset($pdo,$organizationId,$assetDbId,$userId);
        app_audit($pdo,$organizationId,$userId,$auditAction,'equipment_asset',$publicId,null,['name'=>$name,'asset_type'=>$assetType,'status'=>$operationalStatus,'next_service_on'=>$values['next_service_on']]);
        $pdo->commit();
        app_json_response(['ok'=>true,'message'=>'Equipment asset saved.','assets'=>equipment_list_assets($pdo,$organizationId),'summary'=>equipment_brain_summary($pdo,$organizationId)]);
    }

    if ($action === 'archive_asset') {
        equipment_require($user,'equipment.edit');
        $publicId=equipment_clean_id((string)($input['id']??''));
        if($publicId==='') throw new InvalidArgumentException('Equipment asset id is required.');
        $lookup=$pdo->prepare('SELECT id,name FROM equipment_assets WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');
        $lookup->execute([$organizationId,$publicId]); $asset=$lookup->fetch(); if(!$asset) throw new InvalidArgumentException('Equipment asset not found.');
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE equipment_assets SET archived_at=NOW(6),updated_by=? WHERE id=? AND organization_id=?')->execute([$userId,(int)$asset['id'],$organizationId]);
        equipment_brain_archive_asset($pdo,$organizationId,$publicId,$userId);
        app_audit($pdo,$organizationId,$userId,'equipment.archived','equipment_asset',$publicId,['name'=>$asset['name']],['archived'=>true]);
        $pdo->commit();
        app_json_response(['ok'=>true,'message'=>'Equipment asset archived.']);
    }

    if ($action === 'save_contact') {
        equipment_require($user,'equipment.contacts');
        $contact=(array)($input['contact']??[]); $publicId=equipment_clean_id((string)($contact['id']??'')); $isNew=$publicId===''; if($isNew)$publicId=equipment_uuid('contact');
        $company=trim((string)($contact['companyName']??'')); if($company===''||mb_strlen($company)>180) throw new InvalidArgumentException('Service company name is required.');
        $values=[
            'company_name'=>$company,'contact_name'=>equipment_nullable_string($contact['contactName']??null,180),'specialty'=>equipment_nullable_string($contact['specialty']??null,255),
            'phone'=>equipment_nullable_string($contact['phone']??null,60),'email'=>equipment_nullable_string($contact['email']??null,254),'website'=>equipment_nullable_string($contact['website']??null,600),
            'emergency_phone'=>equipment_nullable_string($contact['emergencyPhone']??null,60),'account_number'=>equipment_nullable_string($contact['accountNumber']??null,160),
            'contract_number'=>equipment_nullable_string($contact['contractNumber']??null,160),'is_preferred'=>!empty($contact['preferred'])?1:0,'is_warranty_provider'=>!empty($contact['warrantyProvider'])?1:0,
            'status'=>in_array((string)($contact['status']??''),['active','inactive'],true)?(string)$contact['status']:'active','notes'=>equipment_nullable_string($contact['notes']??null,8000),
        ];
        if($isNew){
            $statement=$pdo->prepare("INSERT INTO equipment_service_contacts (organization_id,public_id,company_name,contact_name,specialty,phone,email,website,emergency_phone,account_number,contract_number,is_preferred,is_warranty_provider,status,notes,created_by,updated_by) VALUES (:organization_id,:public_id,:company_name,:contact_name,:specialty,:phone,:email,:website,:emergency_phone,:account_number,:contract_number,:is_preferred,:is_warranty_provider,:status,:notes,:user_id,:user_id)");
            $statement->execute(['organization_id'=>$organizationId,'public_id'=>$publicId,'user_id'=>$userId]+$values);
            $contactDbId=(int)$pdo->lastInsertId(); $auditAction='equipment.contact_created';
        } else {
            $lookup=$pdo->prepare('SELECT id FROM equipment_service_contacts WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1'); $lookup->execute([$organizationId,$publicId]); $contactDbId=(int)$lookup->fetchColumn(); if($contactDbId<1)throw new InvalidArgumentException('Service contact not found.');
            $sets=[];foreach(array_keys($values) as $key){$sets[]="$key=:$key";} $statement=$pdo->prepare('UPDATE equipment_service_contacts SET '.implode(',',$sets).',updated_by=:user_id,updated_at=NOW(6) WHERE id=:contact_id AND organization_id=:organization_id');
            $statement->execute($values+['user_id'=>$userId,'contact_id'=>$contactDbId,'organization_id'=>$organizationId]); $auditAction='equipment.contact_updated';
        }
        $linked=$pdo->prepare('SELECT equipment_asset_id FROM equipment_asset_service_contacts WHERE service_contact_id=?');$linked->execute([$contactDbId]);
        foreach($linked->fetchAll(PDO::FETCH_COLUMN) as $assetDbId){equipment_brain_sync_asset($pdo,$organizationId,(int)$assetDbId,$userId);}
        app_audit($pdo,$organizationId,$userId,$auditAction,'equipment_service_contact',$publicId,null,['company_name'=>$company]);
        app_json_response(['ok'=>true,'message'=>'Service contact saved.','contacts'=>equipment_list_contacts($pdo,$organizationId)]);
    }

    if ($action === 'link_contact') {
        equipment_require($user,'equipment.edit');
        $assetPublicId=equipment_clean_id((string)($input['assetId']??'')); $contactPublicId=equipment_clean_id((string)($input['contactId']??''));
        $role=trim((string)($input['role']??'service'))?:'service'; $primary=!empty($input['primary'])?1:0;
        $a=$pdo->prepare('SELECT id FROM equipment_assets WHERE organization_id=? AND public_id=? AND archived_at IS NULL');$a->execute([$organizationId,$assetPublicId]);$assetDbId=(int)$a->fetchColumn();
        $c=$pdo->prepare('SELECT id FROM equipment_service_contacts WHERE organization_id=? AND public_id=? AND archived_at IS NULL');$c->execute([$organizationId,$contactPublicId]);$contactDbId=(int)$c->fetchColumn();
        if($assetDbId<1||$contactDbId<1)throw new InvalidArgumentException('Equipment asset or service contact was not found.');
        if($primary)$pdo->prepare('UPDATE equipment_asset_service_contacts SET is_primary=0 WHERE equipment_asset_id=? AND contact_role=?')->execute([$assetDbId,$role]);
        $pdo->prepare("INSERT INTO equipment_asset_service_contacts (equipment_asset_id,service_contact_id,contact_role,is_primary) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE is_primary=VALUES(is_primary)")->execute([$assetDbId,$contactDbId,$role,$primary]);
        equipment_brain_sync_asset($pdo,$organizationId,$assetDbId,$userId);
        app_audit($pdo,$organizationId,$userId,'equipment.contact_linked','equipment_asset',$assetPublicId,null,['contact_id'=>$contactPublicId,'role'=>$role,'primary'=>(bool)$primary]);
        app_json_response(['ok'=>true,'message'=>'Service contact linked.','assets'=>equipment_list_assets($pdo,$organizationId)]);
    }

    if ($action === 'save_event') {
        equipment_require($user,'equipment.service');
        $event=(array)($input['event']??[]); $assetPublicId=equipment_clean_id((string)($event['assetId']??''));
        $assetStmt=$pdo->prepare('SELECT id FROM equipment_assets WHERE organization_id=? AND public_id=? AND archived_at IS NULL');$assetStmt->execute([$organizationId,$assetPublicId]);$assetDbId=(int)$assetStmt->fetchColumn();if($assetDbId<1)throw new InvalidArgumentException('Equipment asset not found.');
        $contactDbId=null;$contactPublicId=equipment_clean_id((string)($event['contactId']??''));if($contactPublicId!==''){$c=$pdo->prepare('SELECT id FROM equipment_service_contacts WHERE organization_id=? AND public_id=? AND archived_at IS NULL');$c->execute([$organizationId,$contactPublicId]);$contactDbId=(int)$c->fetchColumn()?:null;}
        $description=trim((string)($event['description']??''));if($description==='')throw new InvalidArgumentException('Service description is required.');
        $servicedOn=equipment_nullable_date($event['servicedOn']??date('Y-m-d'))?:date('Y-m-d');$nextDueOn=equipment_nullable_date($event['nextDueOn']??null);
        $publicId=equipment_uuid('service');
        $statement=$pdo->prepare("INSERT INTO equipment_service_events (organization_id,public_id,equipment_asset_id,service_contact_id,event_type,status,serviced_on,technician_name,description,parts_used,cost,downtime_minutes,next_due_on,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $statement->execute([$organizationId,$publicId,$assetDbId,$contactDbId,trim((string)($event['eventType']??'maintenance'))?:'maintenance',trim((string)($event['status']??'completed'))?:'completed',$servicedOn,equipment_nullable_string($event['technicianName']??null,180),$description,equipment_nullable_string($event['partsUsed']??null,8000),equipment_nullable_decimal($event['cost']??null),trim((string)($event['downtimeMinutes']??''))===''?null:max(0,(int)$event['downtimeMinutes']),$nextDueOn,$userId,$userId]);
        $update=$pdo->prepare('UPDATE equipment_assets SET last_service_on=?, next_service_on=COALESCE(?,next_service_on), updated_by=?, updated_at=NOW(6) WHERE id=? AND organization_id=?');$update->execute([$servicedOn,$nextDueOn,$userId,$assetDbId,$organizationId]);
        equipment_brain_sync_asset($pdo,$organizationId,$assetDbId,$userId);
        app_audit($pdo,$organizationId,$userId,'equipment.service_recorded','equipment_asset',$assetPublicId,null,['event_type'=>$event['eventType']??'maintenance','serviced_on'=>$servicedOn,'next_due_on'=>$nextDueOn]);
        app_json_response(['ok'=>true,'message'=>'Service event recorded.','assets'=>equipment_list_assets($pdo,$organizationId),'events'=>equipment_list_events($pdo,$organizationId,$assetPublicId),'summary'=>equipment_brain_summary($pdo,$organizationId)]);
    }

    throw new InvalidArgumentException('Unsupported equipment action.');
} catch (InvalidArgumentException $error) {
    if($pdo->inTransaction())$pdo->rollBack();
    app_json_response(['ok'=>false,'message'=>$error->getMessage()],422);
} catch (Throwable $error) {
    if($pdo->inTransaction())$pdo->rollBack();
    app_json_response(['ok'=>false,'message'=>'The equipment action could not be completed.'],500);
}
