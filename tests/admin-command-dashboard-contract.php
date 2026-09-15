<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
    'workspace'=>$root.'/workspace.php',
    'core'=>$root.'/includes/admin-dashboard-core.php',
    'api'=>$root.'/api/admin-dashboard.php',
    'agent'=>$root.'/api/admin-dashboard-agent.php',
    'router'=>$root.'/api/agent-workspace.php',
    'js'=>$root.'/js/admin-command-dashboard.js',
    'css'=>$root.'/css/admin-command-dashboard.css',
];
foreach($files as $name=>$path){
    if(!is_file($path)){fwrite(STDERR,"Missing {$name}: {$path}\n");exit(1);}
    $files[$name]=(string)file_get_contents($path);
}

$checks=[
    'workspace gates command dashboard by permission'=>str_contains($files['workspace'],'$adminDashboardAllowed = admin_dashboard_allowed($user);'),
    'workspace loads command dashboard stylesheet'=>str_contains($files['workspace'],'css/admin-command-dashboard.css'),
    'workspace loads command dashboard runtime'=>str_contains($files['workspace'],'js/admin-command-dashboard.js'),
    'legacy training dashboard stays available for compatibility'=>str_contains($files['js'],"legacy.id = 'page-training-dashboard-legacy'"),
    'new command dashboard owns default dashboard route'=>str_contains($files['js'],"dashboard.id = 'page-dashboard'"),
    'dashboard reads live backend'=>str_contains($files['js'],"fetch('api/admin-dashboard.php'"),
    'dashboard publishes live Agent context'=>str_contains($files['js'],'window.RestaurantAdminDashboardContext = snapshot;'),
    'dashboard uses shared global Agent when available'=>str_contains($files['js'],'window.GelatoGlobalAgent?.send'),
    'dashboard includes sales today'=>str_contains($files['js'],'Sales today'),
    'dashboard includes weekly sales'=>str_contains($files['js'],'Sales this week'),
    'dashboard includes monthly sales'=>str_contains($files['js'],'Sales this month'),
    'dashboard includes active tables'=>str_contains($files['js'],'Active tables'),
    'dashboard includes open tickets'=>str_contains($files['js'],'Open POS tickets'),
    'dashboard includes READY tickets'=>str_contains($files['js'],'READY tickets'),
    'dashboard includes online orders'=>str_contains($files['js'],'Open online orders'),
    'dashboard includes wholesale section'=>str_contains($files['js'],'<h4>Wholesale</h4>'),
    'dashboard includes location performance'=>str_contains($files['js'],'Location performance'),
    'dashboard includes catering pulse'=>str_contains($files['js'],'Catering readiness'),
    'dashboard includes CRM pulse'=>str_contains($files['js'],'Active customers'),
    'core returns sales today week month'=>str_contains($files['core'],"'salesToday'")&&str_contains($files['core'],"'salesWeek'")&&str_contains($files['core'],"'salesMonth'"),
    'core returns active tables and KDS ready'=>str_contains($files['core'],"\$pos['activeTables']")&&str_contains($files['core'],"\$pos['readyTickets']"),
    'core returns per-location online order load'=>str_contains($files['core'],"\$row['onlineOrdersOpen']"),
    'core restricts catering rollup to catering operations'=>str_contains($files['core'],"source_type='catering'"),
    'core returns wholesale pipeline and receivables'=>str_contains($files['core'],"'pipelineLeads'")&&str_contains($files['core'],"'outstandingReceivables'"),
    'agent endpoint uses live dashboard snapshot'=>str_contains($files['agent'],'admin_dashboard_snapshot'),
    'agent answers wholesale context'=>str_contains($files['agent'],"preg_match('/\\bwholesale\\b/u'"),
    'agent answers location comparison'=>str_contains($files['agent'],'compare locations?'),
    'shared Agent router sends dashboard requests to dashboard skill'=>str_contains($files['router'],"'route'=>'api/admin-dashboard-agent.php'"),
    'shared Agent router preserves detailed thread history'=>str_contains($files['router'],'gaw_messages($pdo,$org,$uid,$id,220)'),
    'dashboard layout is responsive'=>str_contains($files['css'],'@media(max-width:720px)'),
];

$failed=[];
foreach($checks as $label=>$passed){
    if(!$passed)$failed[]=$label;
}
if($failed){
    fwrite(STDERR,"Admin command dashboard contract failed:\n - ".implode("\n - ",$failed)."\n");
    exit(1);
}

echo 'Admin command dashboard contract passed ('.count($checks)." checks).\n";
