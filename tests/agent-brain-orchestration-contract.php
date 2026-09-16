<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$paths=[
    'core'=>$root.'/includes/agent-brain-orchestrator.php',
    'api'=>$root.'/api/brain-orchestrator.php',
    'events'=>$root.'/api/brain-events.php',
    'nodes'=>$root.'/includes/agent-node-registry.php',
    'command'=>$root.'/api/admin-dashboard-agent.php',
    'nextMoves'=>$root.'/js/agent-next-moves.js',
    'loader'=>$root.'/js/global-add-canvas.js',
];
$files=[];
foreach($paths as $name=>$path){if(!is_file($path)){fwrite(STDERR,"Missing {$name}: {$path}\n");exit(1);}$files[$name]=(string)file_get_contents($path);}

$checks=[
    'main Brain node is registered'=>str_contains($files['nodes'],"'brain'=>[")&&str_contains($files['nodes'],"'route'=>'api/brain-orchestrator.php'")&&str_contains($files['nodes'],"'mode'=>'read_orchestrator'"),
    'orchestrator builds dashboard signals'=>str_contains($files['core'],'abo_dashboard_signals')&&str_contains($files['core'],'admin_dashboard_snapshot'),
    'orchestrator reads purchasing'=>str_contains($files['core'],'purchasing_summary')&&str_contains($files['core'],'purchasing_suggestions'),
    'orchestrator reads scheduling'=>str_contains($files['core'],'scheduling_summary'),
    'orchestrator reads prep'=>str_contains($files['core'],'prep_plan_detail'),
    'orchestrator reads operations'=>str_contains($files['core'],'operations_inventory_list'),
    'orchestrator reads CRM'=>str_contains($files['core'],"crm_customers")&&str_contains($files['core'],"DATE_SUB(NOW(),INTERVAL 45 DAY)"),
    'kitchen and staffing correlation exists'=>str_contains($files['core'],"brain:kitchen-staffing")&&str_contains($files['core'],"kds:ready")&&str_contains($files['core'],"scheduling:coverage"),
    'prep and purchasing correlation exists'=>str_contains($files['core'],"brain:prep-purchasing")&&str_contains($files['core'],"prep:shortage")&&str_contains($files['core'],"purchasing:pressure"),
    'catering and inventory correlation exists'=>str_contains($files['core'],"brain:catering-inventory")&&str_contains($files['core'],"catering:risk"),
    'next moves include destination node'=>str_contains($files['core'],"'node'=>$move['node']")&&str_contains($files['core'],"'nodeLabel'=>$move['nodeLabel']"),
    'orchestrator explains write boundary'=>str_contains($files['core'],'Any consequential change remains owned and permission-gated by the destination node.'),
    'read-only snapshot endpoint exists'=>str_contains($files['api'],"action=(string)(\$_GET['action']??'snapshot')")&&str_contains($files['api'],"agent.brain.snapshot"),
    'orchestration usage is audited'=>str_contains($files['api'],'agent.brain_orchestration_used'),
    'command center delegates broad priorities to Brain'=>str_contains($files['command'],'$brainIntent=')&&str_contains($files['command'],'agent_brain_orchestration_snapshot'),
    'specific command dashboard behavior remains'=>str_contains($files['command'],'admin_dashboard_agent_answer')&&str_contains($files['command'],"preg_match('/\\bwholesale\\b/u'"),
    'proactive bridge requires proactive permission'=>str_contains($files['events'],"app_has_permission('agent.proactive',$user)"),
    'proactive bridge uses existing event table'=>str_contains($files['events'],'proactive_agent_events')&&str_contains($files['events'],'ON DUPLICATE KEY UPDATE'),
    'next moves are session dismissible'=>str_contains($files['nextMoves'],'sessionStorage')&&str_contains($files['nextMoves'],'dismiss'),
    'next moves use main global Agent'=>str_contains($files['nextMoves'],'window.GelatoGlobalAgent?.send?.'),
    'propose fix preserves confirmation boundary'=>str_contains($files['nextMoves'],'Do not execute a consequential change until I explicitly confirm.'),
    'shared admin shell loads next moves'=>str_contains($files['loader'],'js/agent-next-moves.js?v=20260916-brain1'),
];

$failed=[];foreach($checks as $label=>$passed)if(!$passed)$failed[]=$label;
if($failed){fwrite(STDERR,"Agent Brain orchestration contract failed:\n - ".implode("\n - ",$failed)."\n");exit(1);}
echo 'Agent Brain orchestration contract passed ('.count($checks)." checks).\n";
