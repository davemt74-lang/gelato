<?php
declare(strict_types=1);

/** B8D18J2C: exact-period, read-only stored prep shortage forecast. */
require_once __DIR__.'/prep-agent-core.php';

function fm8d18j2c_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('prep.intelligence.view',$actor)
        &&app_has_permission('prep.intelligence.agent',$actor)
        &&(app_has_permission('inventory.forecast.view',$actor)||app_has_permission('inventory.view',$actor));
}
function fm8d18j2c_fields(array $args):array
{
    $missing=[];
    $date=$args['date']??null;
    if(!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))$missing[]='date';
    else {
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('America/Phoenix'));
        if(!$parsed||$parsed->format('Y-m-d')!==$date)$missing[]='date';
    }
    if(!in_array($args['service']??null,['all','morning','lunch','dinner','closing'],true))$missing[]='service';
    return $missing;
}
function fm8d18j2c_read(array $args,array $actor):array
{
    if(!fm8d18j2c_can($actor))throw new DomainException('Prep and inventory forecast view permissions are required.');
    if($missing=fm8d18j2c_fields($args))throw new InvalidArgumentException('Specify exact '.implode(', ',$missing).'.');
    $pdo=app_pdo();$org=(int)$actor['organization_id'];
    if(!prep_intelligence_ready($pdo))throw new RuntimeException('Prep Intelligence is unavailable. Complete upgrade.php first.');
    $plan=prep_plan_for_period($pdo,$org,$args['date'],$args['service']);
    if(!$plan)return ['ok'=>true,'data'=>['answer'=>'No saved prep plan exists for '.$args['date'].' '.$args['service'].'.','source'=>'Gelato Prep Intelligence (stored forecast)','forecastAsOf'=>'stored','items'=>[]]];
    $detail=prep_plan_detail($pdo,$org,(string)$plan['public_id']);
    $flagged=array_values(array_filter((array)($detail['forecasts']??[]),
        static fn(array $row):bool=>(float)($row['shortage_quantity']??0)>0||(float)($row['restock_quantity']??0)>0));
    $summary=$flagged?('Stored forecast flags '.count($flagged).' item(s) for '.$args['date'].' '.$args['service'].'.'):'No stored shortage or restock flags for '.$args['date'].' '.$args['service'].'.';
    return ['ok'=>true,'data'=>['answer'=>$summary.' This is a saved forecast, not a freshly recalculated inventory count.','source'=>'Gelato Prep Intelligence (stored forecast)','forecastAsOf'=>'stored','items'=>$flagged]];
}
function fm8d18j2c_catalogue(array $actor):array
{
    if(!fm8d18j2c_can($actor))return [];
    return ['get_prep_shortages'=>[
        'description'=>'Read only the STORED shortage/restock forecast on an existing prep plan for one exact date and service. Require exact YYYY-MM-DD and all/morning/lunch/dinner/closing. Never invent a date, claim that the forecast is live, promise new recalculation, or create a purchase. For a missing plan, say so.',
        'parameters'=>['type'=>'object','properties'=>[
            'date'=>['type'=>'string','description'=>'Exact restaurant-local YYYY-MM-DD date'],
            'service'=>['type'=>'string','enum'=>['all','morning','lunch','dinner','closing']],
        ],'required'=>['date','service'],'additionalProperties'=>false],
        'allowedArguments'=>['date','service'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j2c_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j2c_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j2c_read($args,$user),
    ]];
}
