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

$pages=['sales-import-center.php','timeclock.php','public-site-settings.php','prep-intelligence.php','equipment.php','purchasing.php','scheduling.php'];
foreach($pages as $page){
    require_text($root.'/'.$page,'js/universal-admin-page-shell.js?v=20260915-3',$page.' must load the current universal admin shell',$failures);
}

require_text($root.'/js/universal-admin-page-shell.js',"'sales-import-center.php': ['Sales Import Center'",'Universal shell must know the Sales Import Center title',$failures);
require_text($root.'/js/universal-admin-page-shell.js',"['⇩', 'Sales Import Center', 'sales-import-center.php']",'Universal sidebar must link Sales Import Center',$failures);
require_text($root.'/js/universal-admin-page-shell.js',"'public-site-settings.php': ['Public Site Settings'",'Universal shell must know Public Site Settings',$failures);

if($failures){
    fwrite(STDERR,"POS/admin shell follow-up contract failed:\n - ".implode("\n - ",$failures)."\n");
    exit(1);
}

echo "POS/admin shell follow-up contract passed.\n";
