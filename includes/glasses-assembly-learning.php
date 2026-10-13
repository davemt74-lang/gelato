<?php
declare(strict_types=1);
/**
 * Section 268: thin, explicit assembly-HUD -> EXISTING V3 Vision Lab candidate bridge.
 * No camera upload, correction label invention, KDS write or model auto-training.
 */
require_once __DIR__.'/glasses-employee-runtimes.php';
require_once __DIR__.'/glasses-work.php';
require_once __DIR__.'/glasses-vision-active-learning.php';
function glasses_assembly_learning_types(): array
{
    return [
      'low_confidence'=>['candidate'=>'low_confidence','priority'=>72,
          'reason'=>'Cook reported low-confidence ingredient recognition'],
      'wrong_ingredient'=>['candidate'=>'correction','priority'=>92,
          'reason'=>'Cook reports ingredient recognized incorrectly'],
      'missed_ingredient'=>['candidate'=>'correction','priority'=>84,
          'reason'=>'Cook reports missing detection of a configured ingredient'],
      'difficult_view'=>['candidate'=>'low_confidence','priority'=>62,
          'reason'=>'Cook requested new photos for a difficult ingredient view']
    ];
}
function glasses_assembly_learning_submit(PDO $pdo,array $user,array $input): array
{
    if(!glasses_vision_active_learning_ready($pdo))
        throw new DomainException('Install the existing Vision Lab active-learning migration.');
    if(!app_has_permission('glasses.view',$user))
        throw new DomainException('Glasses viewing permission is required.');
    $runtimeId=trim((string)($input['runtimePublicId']??''));
    $runtime=glasses_employee_runtime_row($pdo,$user,$runtimeId);
    $org=(int)$user['organization_id'];
    $device=glasses_device_row($pdo,$org,(string)$runtime['device_public_id']);
    glasses_employee_runtime_assert_binding($pdo,$user,$runtimeId,$device);
    if(!operational_location_allowed($pdo,$user,'glasses.view',(int)$device['location_id']))
        throw new DomainException('This restaurant location is not accessible.');
    if((int)($device['station_id']??0)<1)
        throw new DomainException('Assign this pair of glasses to a kitchen station first.');
    $feedback=(string)($input['feedbackType']??'');
    $types=glasses_assembly_learning_types();
    if(!isset($types[$feedback]))throw new InvalidArgumentException('Choose a supported training feedback type.');
    $kdsId=trim((string)($input['kdsItemPublicId']??''));
    $key=trim((string)($input['ingredientKey']??''));
    if($kdsId===''||strlen($kdsId)>80||$key===''||strlen($key)>190)
        throw new InvalidArgumentException('Select a current KDS item and configured ingredient.');
    $work=glasses_current_work($pdo,$device);
    $selected=null;
    foreach((array)($work['items']??[]) as $item)
        if(hash_equals((string)($item['kdsItemPublicId']??''),$kdsId)){$selected=$item;break;}
    if(!$selected||!in_array((string)$selected['status'],['queued','in_progress','held','ready'],true))
        throw new DomainException('The selected kitchen item is no longer active at this glasses station.');
    $ingredients=(array)($selected['menu']['ingredients']??[]);
    $canonical=null;$name=null;
    foreach($ingredients as $i=>$part){
        $label=trim((string)($part['canonicalName']??$part['name']??''));
        $expected=(string)$i.':'.$label;
        if($label!==''&&hash_equals($expected,$key)){
            $canonical=$label;$name=trim((string)($part['name']??$label));break;
        }
    }
    if($canonical===null)
        throw new InvalidArgumentException('Only currently configured menu-item ingredients can be flagged.');
    $note=mb_substr(trim((string)($input['note']??'')),0,160,'UTF-8');
    $ref='assembly:'.substr(hash('sha256',$kdsId.'|'.$key.'|'.$feedback),0,52);
    $kind=$types[$feedback];
    $candidate=[
        'candidateType'=>$kind['candidate'],'sourceType'=>'assembly_hud',
        'sourceReference'=>$ref,
        'deviceId'=>(int)$device['id'],'locationId'=>(int)$device['location_id'],
        'stationId'=>(int)$device['station_id'],
        'operatorUserId'=>(int)$runtime['employee_user_id'],
        'canonicalLabel'=>$canonical,'priorityScore'=>$kind['priority'],
        'severity'=>$feedback==='wrong_ingredient'?'high':'normal',
        'reason'=>$kind['reason'].($note!==''?': '.$note:''),
        'suggestedTitle'=>'Collect ingredient evidence · '.$name,
        'evidence'=>[
            'schema'=>'gelato.assembly_hud_learning.v1',
            'runtimePublicId'=>$runtimeId,
            'devicePublicId'=>(string)$device['public_id'],
            'employeeUserId'=>(int)$runtime['employee_user_id'],
            'kdsItemPublicId'=>$kdsId,
            'ticketPublicId'=>(string)$selected['ticket']['checkPublicId'],
            'ticketNumber'=>(string)$selected['ticket']['checkNumber'],
            'menuItemId'=>(int)$selected['posLine']['menuItemId'],
            'ingredientKey'=>$key,
            'canonicalIngredient'=>$canonical,'feedbackType'=>$feedback,
            'observationSource'=>'operator_reported',
            'cameraEvidence'=>false,'modelVerified'=>false,
            'reviewRequired'=>true,'autoTrain'=>false
        ]
    ];
    try{
        $result=glasses_vision_active_learning_upsert($pdo,$org,$candidate);
    }catch(PDOException $e){
        if((string)$e->getCode()!=='23000')throw $e;
        // Two employees/retries may report the same KDS ingredient concurrently.
        // The V3 unique candidate key remains authoritative for deduplication.
        $result=glasses_vision_active_learning_upsert($pdo,$org,$candidate);
    }
    if($result['created']){
        app_audit($pdo,$org,(int)$user['id'],'glasses.assembly_learning_flag','vision_active_learning_candidate',
            (string)$result['publicId'],null,
            ['runtimePublicId'=>$runtimeId,'employeeUserId'=>(int)$runtime['employee_user_id'],
                'kdsItemPublicId'=>$kdsId,'ingredientKey'=>$key,'feedbackType'=>$feedback]);
    }
    return ['candidatePublicId'=>(string)$result['publicId'],
        'status'=>(string)$result['status'],'created'=>(bool)$result['created'],
        'reviewRequired'=>true,'modelUnchanged'=>true,'photoAttached'=>false];
}
