<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$failures=[];

function require_text(string $path,string $needle,string $label,array &$failures): void {
    $content=@file_get_contents($path);
    if($content===false||!str_contains($content,$needle))$failures[]=$label;
}
function reject_text(string $path,string $needle,string $label,array &$failures): void {
    $content=@file_get_contents($path);
    if($content!==false&&str_contains($content,$needle))$failures[]=$label;
}

$pos=$root.'/pos.php';
require_text($pos,'<nav class="workspace-tabs" aria-label="POS workspace"><button class="workspace-tab active" type="button" data-pos-view="menu">Menu</button><button class="workspace-tab" type="button" data-pos-view="floor">Floor Plan</button>','POS Menu and Floor Plan must live in the main top header',$failures);
reject_text($pos,'Floor plan · table service · native sales ledger','Legacy Gelato POS header subtitle must stay removed',$failures);
reject_text($pos,'<div class="logo">G</div>','Legacy Gelato POS logo block must stay removed',$failures);

require_text($root.'/includes/pos-core.php',"table_name='service_tables'",'POS open-check feed must safely detect service_tables',$failures);
require_text($root.'/includes/pos-core.php','t.id table_id','POS open-check feed must expose the service table ID',$failures);
require_text($root.'/js/pos.js','Table ID #${tableId}','Active Tickets must display the canonical Table ID number',$failures);

$bootstrap=$root.'/includes/bootstrap.php';
$core=$root.'/includes/admin-shell-core.php';
$shell=$root.'/js/universal-admin-page-shell.js';
$pages=['sales-import-center.php','timeclock.php','public-site-settings.php','prep-intelligence.php','equipment.php','purchasing.php','scheduling.php'];
foreach($pages as $page){
    require_text($bootstrap,"'{$page}'",$page.' must be centrally registered for the universal admin shell',$failures);
    require_text($core,"'{$page}'",$page.' must be classified by the canonical server shell model',$failures);
}

require_text($shell,'const groups = Array.isArray(shell.navigation)','Universal shell must render server-provided navigation',$failures);
require_text($core,"'sales-import-center.php'=>['Sales Import Center'",'Canonical shell must know the Sales Import Center title',$failures);
require_text($core,"['icon'=>'⇩','label'=>'Sales Import Center','href'=>'sales-import-center.php'",'Canonical sidebar must link Sales Import Center',$failures);
require_text($core,"'public-site-settings.php'=>['Public Site Settings'",'Canonical shell must know Public Site Settings',$failures);
reject_text($shell,"'sales-import-center.php': ['Sales Import Center'",'Browser shell must not own page-title metadata',$failures);

if($failures){
    fwrite(STDERR,"POS/admin shell follow-up contract failed:\n - ".implode("\n - ",$failures)."\n");
    exit(1);
}

echo "POS/admin shell follow-up contract passed.\n";
