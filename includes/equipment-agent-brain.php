<?php
declare(strict_types=1);

require_once __DIR__.'/equipment-brain.php';
require_once __DIR__.'/equipment-agent-core.php';

function equipment_agent_brain_signal_rows(PDO $pdo,array $user): array
{
    if(!app_has_permission('equipment.view',$user)||!app_has_permission('agent.equipment_skills',$user)||!equipment_agent_ready($pdo))return [];
    $org=(int)$user['organization_id'];$summary=equipment_brain_summary($pdo,$org);$attention=equipment_agent_attention($pdo,$org,8);$rows=[];
    $outOfService=array_values(array_filter($attention,static fn(array $a):bool=>(string)$a['operational_status']==='out_of_service'));
    $criticalDown=array_values(array_filter($outOfService,static fn(array $a):bool=>in_array((string)$a['criticality'],['critical','high'],true)));
    if($criticalDown){
        $asset=$criticalDown[0];$rows[]=[
            'key'=>'equipment:critical-outage','node'=>'equipment','nodeLabel'=>'Equipment + Maintenance','severity'=>'critical','score'=>96,
            'title'=>'Critical equipment is out of service','detail'=>(string)$asset['name'].' is out of service'.($asset['location_name']?' at '.$asset['location_name']:'').'. '.count($criticalDown).' high/critical asset'.(count($criticalDown)===1?' is':'s are').' currently down.',
            'href'=>'equipment-detail.php?id='.rawurlencode((string)$asset['public_id']),'evidence'=>['outOfService'=>$summary['outOfService'],'assetPublicId'=>$asset['public_id'],'criticality'=>$asset['criticality']],
            'nextMove'=>['node'=>'equipment','nodeLabel'=>'Equipment + Maintenance','mode'=>'review','label'=>'Review','prompt'=>'Review the critical equipment outages, service history, and maintenance risk. Tell me what needs attention first.'],
        ];
    }elseif($summary['outOfService']>0){
        $asset=$outOfService[0]??$attention[0]??null;if($asset)$rows[]=[
            'key'=>'equipment:outage','node'=>'equipment','nodeLabel'=>'Equipment + Maintenance','severity'=>'high','score'=>84,
            'title'=>'Equipment is out of service','detail'=>$summary['outOfService'].' equipment asset'.($summary['outOfService']===1?' is':'s are').' out of service, including '.(string)$asset['name'].'.',
            'href'=>'equipment.php','evidence'=>['outOfService'=>$summary['outOfService'],'assetPublicId'=>$asset['public_id']],
            'nextMove'=>['node'=>'equipment','nodeLabel'=>'Equipment + Maintenance','mode'=>'review','label'=>'Review','prompt'=>'Show the out-of-service equipment and prioritize what should be repaired or returned to service.'],
        ];
    }
    if($summary['overdueMaintenance']>0){
        $due=equipment_brain_maintenance_due($pdo,$org,0);$asset=$due[0]??null;$rows[]=[
            'key'=>'equipment:maintenance-overdue','node'=>'equipment','nodeLabel'=>'Equipment + Maintenance','severity'=>'high','score'=>80,
            'title'=>'Equipment maintenance is overdue','detail'=>$summary['overdueMaintenance'].' asset'.($summary['overdueMaintenance']===1?' is':'s are').' past the recorded maintenance date'.($asset?', starting with '.(string)$asset['name']:'').'.',
            'href'=>'equipment.php','evidence'=>['overdueMaintenance'=>$summary['overdueMaintenance'],'assetPublicId'=>$asset['public_id']??null],
            'nextMove'=>['node'=>'equipment','nodeLabel'=>'Equipment + Maintenance','mode'=>'review','label'=>'Review','prompt'=>'Review overdue equipment maintenance and give me the highest-risk service priorities.'],
        ];
    }elseif($summary['dueWithin30Days']>0){
        $rows[]=[
            'key'=>'equipment:maintenance-due','node'=>'equipment','nodeLabel'=>'Equipment + Maintenance','severity'=>'normal','score'=>58,
            'title'=>'Preventive maintenance is coming due','detail'=>$summary['dueWithin30Days'].' asset'.($summary['dueWithin30Days']===1?' is':'s are').' due for service within 30 days.',
            'href'=>'equipment.php','evidence'=>['dueWithin30Days'=>$summary['dueWithin30Days']],
            'nextMove'=>['node'=>'equipment','nodeLabel'=>'Equipment + Maintenance','mode'=>'review','label'=>'Review','prompt'=>'Show equipment due for service in the next 30 days and help me plan the maintenance work.'],
        ];
    }
    return $rows;
}

function equipment_agent_merge_brain_snapshot(PDO $pdo,array $user,array $snapshot): array
{
    $extra=equipment_agent_brain_signal_rows($pdo,$user);if(!$extra)return $snapshot;
    $byKey=[];foreach(array_merge((array)($snapshot['signals']??[]),$extra) as $signal)$byKey[(string)$signal['key']]=$signal;
    $signals=array_values($byKey);$rank=static fn(string $severity):int=>match($severity){'critical'=>4,'high'=>3,'normal'=>2,'low'=>1,default=>0};
    usort($signals,static fn(array $a,array $b):int=>((int)$b['score']<=>(int)$a['score'])?:($rank((string)$b['severity'])<=>$rank((string)$a['severity']))?:strcmp((string)$a['title'],(string)$b['title']));
    $snapshot['signals']=$signals;
    $snapshot['nextMoves']=array_values(array_map(static fn(array $signal):array=>['key'=>$signal['key'],'node'=>$signal['node'],'nodeLabel'=>$signal['nodeLabel'],'severity'=>$signal['severity'],'score'=>$signal['score'],'title'=>$signal['title'],'detail'=>$signal['detail'],'href'=>$signal['href'],'mode'=>$signal['nextMove']['mode'],'label'=>$signal['nextMove']['label'],'prompt'=>$signal['nextMove']['prompt']],array_slice($signals,0,12)));
    return $snapshot;
}
