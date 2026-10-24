<?php
declare(strict_types=1);

/**
 * FM-B8D7 — model-discovered read-only Vision Lab and glasses operations.
 * Calls existing tenant-scoped services; raw frames, biometrics, model artifact
 * URLs, and private evidence are never forwarded to the LLM provider.
 */
require_once __DIR__.'/glasses-vision-lab.php';
require_once __DIR__.'/glasses-vision-operations.php';
require_once __DIR__.'/glasses-vision-models.php';
require_once __DIR__.'/glasses-vision-scene.php';
require_once __DIR__.'/glasses-vision-final-validation.php';
require_once __DIR__.'/glasses-vision-fleet-health.php';

function fm8d7_observe(string $answer,string $source='Gelato Vision Lab'):array
{
    return ['ok'=>true,'data'=>['answer'=>mb_strcut($answer,0,2600,'UTF-8'),'source'=>$source]];
}
function fm8d7_status(PDO $pdo,array $actor):array
{
    $data=glasses_vision_lab_catalog($pdo,$actor);
    if(empty($data['ready']))return fm8d7_observe('Vision Lab is not yet installed or migrated; no live observations can be verified.');
    $counts=['registered devices'=>count((array)($data['devices']??[])),
        'device assignments'=>count((array)($data['assignments']??[])),
        'collection missions'=>count((array)($data['missions']??[])),
        'training samples'=>count((array)($data['samples']??[])),
        'datasets'=>count((array)($data['datasets']??[])),
        'open drift incidents'=>count((array)($data['driftIncidents']??[]))];
    $lines=['Vision Lab is installed. Current Gelato records:'];
    foreach($counts as $name=>$count)$lines[]=$name.': '.$count.'.';
    if($counts['collection missions']===0&&$counts['training samples']===0)
        $lines[]='No recorded training mission or sample was found; this is not evidence that live cameras are idle.';
    return fm8d7_observe(implode("\n",$lines));
}
function fm8d7_operations(PDO $pdo,array $actor):array
{
    $data=glasses_vision_ops_catalog($pdo,$actor);
    if(empty($data['ready']))return fm8d7_observe('AR Glasses Operations is not installed or upgraded.');
    $s=(array)($data['summary']??[]);
    $lines=['AR glasses device health recorded at '.(string)($data['generatedAt']??'this check').':',
        'Devices: '.(int)($s['total']??0).' total; '.(int)($s['healthy']??0).' healthy; '.
        (int)($s['warning']??0).' warning; '.(int)($s['critical']??0).' critical; '.
        (int)($s['offline']??0).' offline; '.(int)($s['stale']??0).' stale.',
        'Active model rollouts: '.(int)($s['activeRollouts']??0).
        '; open drift incidents: '.(int)($s['openIncidents']??0).'.'];
    foreach(array_slice((array)($data['needsAttention']??[]),0,6) as $device){
        $name=trim((string)($device['displayName']??$device['devicePublicId']??'Device'));
        $state=(string)($device['health']['state']??'unknown');
        $lines[]=$name.' requires attention ('.$state.').';
    }
    return fm8d7_observe(implode("\n",$lines),'Gelato AR Glasses Operations');
}
function fm8d7_models(PDO $pdo,array $actor):array
{
    $data=glasses_vision_model_catalog($pdo,$actor);
    if(empty($data['ready']))return fm8d7_observe('Vision model rollout database is not installed or upgraded.');
    $packages=(array)($data['packages']??[]);$rollouts=(array)($data['rollouts']??[]);
    $active=array_values(array_filter($rollouts,
        static fn(array $row):bool=>($row['status']??'')==='active'));
    $lines=['Vision model packages recorded: '.count($packages).
        '; rollouts recorded: '.count($rollouts).'; currently active rollouts: '.count($active).'.'];
    foreach(array_slice($active,0,7) as $row)$lines[]=
        (string)($row['detectorName']??'Detector').': '.
        (string)($row['targetLabel']??'Unknown model').', location '.
        (string)($row['locationName']??'all authorized locations').', canary '.
        number_format((float)($row['canaryPercent']??0),1).'%.';
    if(!$active)$lines[]='No active rollout is recorded. This does not establish whether a device is actively observing.';
    $incidents=(array)($data['driftIncidents']??[]);
    $lines[]='Recorded drift incidents: '.count($incidents).'.';
    return fm8d7_observe(implode("\n",$lines),'Gelato Vision Model Registry');
}
function fm8d7_scenes(PDO $pdo,array $actor,array $args):array
{
    $limit=max(1,min(5,(int)($args['limit']??3)));
    $data=glasses_vision_scene_recent($pdo,(int)$actor['organization_id'],$limit);
    if(empty($data['ready']))return fm8d7_observe('Live Vision Scene database is not installed or upgraded.');
    $rows=(array)($data['scenes']??[]);
    if(!$rows)return fm8d7_observe('No saved Vision Scene observations are available for this restaurant. I cannot claim the camera saw anything.');
    $lines=['Most recent saved Vision Scene observations (recorded evidence, not a live camera feed):'];
    foreach($rows as $row){
        $labels=array_slice(array_values(array_unique(array_filter(array_map(
            static fn(array $e):string=>trim((string)($e['label']??'')),(array)($row['entities']??[]))))),0,8);
        $lines[]=(string)($row['capturedAt']??'Time not recorded').' — '.
            (string)($row['sceneState']??'unknown').' — detected labels: '.
            ($labels?implode(', ',$labels):'none recorded').'.';
    }
    return fm8d7_observe(implode("\n",$lines),'Gelato Saved Vision Scenes');
}
function fm8d7_validations(PDO $pdo,array $actor,array $args):array
{
    if(!glasses_vision_final_ready($pdo))
        return fm8d7_observe('Final Product Validation database is not installed or upgraded.');
    $rows=glasses_vision_final_recent($pdo,(int)$actor['organization_id'],
        max(1,min(8,(int)($args['limit']??5))));
    if(!$rows)return fm8d7_observe('No recorded final-product vision validation exists for this restaurant.');
    $lines=['Recent final-product visual checks (advisory, not release authorization):'];
    foreach($rows as $r)$lines[]=(string)($r['createdAt']??'Unknown date').': '.
        (string)($r['state']??'unknown').', readiness candidate '.
        (!empty($r['readyCandidate'])?'yes':'no').
        ', confidence '.number_format((float)($r['confidence']??0),2).'.';
    return fm8d7_observe(implode("\n",$lines),'Gelato Final Product Validation');
}
function fm8d7_fleet(PDO $pdo,array $actor):array
{
    $data=glasses_vision_fleet_health_catalog($pdo,(int)$actor['organization_id']);
    if(empty($data['ready']))return fm8d7_observe('Vision Fleet Health database is not installed or upgraded.');
    $states=(array)($data['states']??[]);
    $lines=['Recorded fleet-health analyses: '.count((array)($data['analyses']??[])).
        '; historical fleet actions: '.count((array)($data['actions']??[])).'.'];
    foreach($states as $state=>$n)$lines[]=(string)$state.': '.(int)$n.' analyses.';
    $lines[]='These are historical analyses, not a claim of real-time device control or health.';
    return fm8d7_observe(implode("\n",$lines),'Gelato Vision Fleet Health');
}
function fm8d7_vision_catalogue(array $principal):array
{
    if(!app_has_permission('glasses.view',$principal))return [];
    $auth=static fn(array $actor,array $args):bool=>app_has_permission('glasses.view',$actor);
    $none=['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false];
    $limit=['type'=>'object','properties'=>[
        'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>8,
            'description'=>'Number of latest recorded observations to summarize']],
        'additionalProperties'=>false];
    $validate=static fn(array $args,array $actor):array=>[];
    return [
      'get_vision_lab_overview'=>[
        'description'=>'Read real Vision Lab inventory of devices, assignments, missions, saved samples, datasets and open drift incidents for the signed-in restaurant. Use when asked what Vision Lab knows or whether training data exists. No model actions or camera access.',
        'parameters'=>$none,'allowedArguments'=>[],'impact'=>'read',
        'authorize'=>$auth,'validate'=>$validate,
        'execute'=>static fn(array $args,array $actor):array=>fm8d7_status(app_pdo(),$actor),
      ],
      'get_vision_device_health'=>[
        'description'=>'Read current AR Glasses Operations recorded device health, offline or warning devices, active rollouts and drift alerts. Use for "are my glasses connected", which cameras are offline, or equipment health. Does not start or stop a device.',
        'parameters'=>$none,'allowedArguments'=>[],'impact'=>'read',
        'authorize'=>$auth,'validate'=>$validate,
        'execute'=>static fn(array $args,array $actor):array=>fm8d7_operations(app_pdo(),$actor),
      ],
      'get_vision_model_rollouts'=>[
        'description'=>'Read active model deployments, detector names, model versions, canary percentages, and current drift incident counts. Use for which vision model is in production and rollout status.',
        'parameters'=>$none,'allowedArguments'=>[],'impact'=>'read',
        'authorize'=>$auth,'validate'=>$validate,
        'execute'=>static fn(array $args,array $actor):array=>fm8d7_models(app_pdo(),$actor),
      ],
      'get_recent_vision_observations'=>[
        'description'=>'Read the last 1–5 SAVED Vision Scene observation summaries and detected object labels from the signed-in restaurant. This is evidence history, not a live camera feed. Never pretend to see live video.',
        'parameters'=>$limit,'allowedArguments'=>['limit'],'impact'=>'read',
        'authorize'=>$auth,'validate'=>$validate,
        'execute'=>static fn(array $args,array $actor):array=>fm8d7_scenes(app_pdo(),$actor,$args),
      ],
      'get_recent_product_vision_checks'=>[
        'description'=>'Read recent saved camera-assisted final product presentation or quality checks. Results are advisory and never automatically authorize release or food safety compliance.',
        'parameters'=>$limit,'allowedArguments'=>['limit'],'impact'=>'read',
        'authorize'=>$auth,'validate'=>$validate,
        'execute'=>static fn(array $args,array $actor):array=>fm8d7_validations(app_pdo(),$actor,$args),
      ],
      'get_vision_fleet_analyses'=>[
        'description'=>'Read historical fleet-health analyses, state breakdowns, and count of recorded fleet actions. Never interpret past analyses as proof of live device connection.',
        'parameters'=>$none,'allowedArguments'=>[],'impact'=>'read',
        'authorize'=>$auth,'validate'=>$validate,
        'execute'=>static fn(array $args,array $actor):array=>fm8d7_fleet(app_pdo(),$actor),
      ],
    ];
}
