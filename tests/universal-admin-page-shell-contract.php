<?php
declare(strict_types=1);

function uas_test(bool $ok,string $message,int $code): void
{
    if($ok)return;
    fwrite(STDERR,"FAIL {$code}: {$message}\n");
    exit($code);
}

$root=dirname(__DIR__);
$shell=file_get_contents($root.'/js/universal-admin-page-shell.js');
uas_test(is_string($shell)&&$shell!=='','Universal admin page shell is missing',2);

foreach(['Team & Hiring','Website & Locations','Operations','Sales & Events','AI & Knowledge'] as $label){
    uas_test(str_contains($shell,$label),'Universal shell category missing: '.$label,3);
}
uas_test(str_contains($shell,"const STATE_KEY = 'gelato-admin-nav-accordion-v1'"),'Universal shell does not share accordion persistence key',4);
uas_test(str_contains($shell,"href=\"pos.php\">POS</a><a class=\"uas-header-link kds\" href=\"kds.php\">KDS</a>"),'POS and KDS are not adjacent in the universal header',5);
uas_test(str_contains($shell,"['↗', 'Online Orders', 'online-orders-admin.php']"),'Online Orders is missing from universal navigation',6);
uas_test(str_contains($shell,"['✓', 'Operations', 'operations.php']"),'Operations is missing from universal navigation',7);
uas_test(str_contains($shell,"['▤', 'Recipe Library + Builder', 'recipes.php']"),'Recipe Library + Builder is missing from universal navigation',8);
uas_test(str_contains($shell,"['↗', 'Sales Intelligence', 'sales-intelligence.php']"),'Sales Intelligence is missing from universal navigation',9);
uas_test(str_contains($shell,"['◎', 'Customer CRM', 'customer-crm.php']"),'Customer CRM is missing from universal navigation',10);
uas_test(str_contains($shell,"['✦', 'Customer Promotions', 'customer-promotions.php']"),'Customer Promotions is missing from universal navigation',11);
uas_test(str_contains($shell,"['◈', 'Catering Operations', 'catering-operations.php']"),'Catering Operations is missing from universal navigation',12);
uas_test(str_contains($shell,"['◈', 'Catering Pipeline', 'catering-pipeline.php']"),'Catering Pipeline is missing from universal navigation',13);
uas_test(str_contains($shell,"['⌖', 'Locations', 'locations-admin.php']"),'Locations is missing from universal navigation',14);
uas_test(str_contains($shell,"workspace.php#forms"),'Form Builder is missing from universal navigation',15);
uas_test(str_contains($shell,"function movePageActions"),'Standalone page actions are not preserved in universal header',16);
uas_test(str_contains($shell,"function duplicateDestination"),'Duplicate local-header destinations are not removed',17);
uas_test(str_contains($shell,"class=\"uas-account-menu\""),'Clean universal account menu is missing',18);
uas_test(str_contains($shell,"'scheduling.php': ['Staff Scheduling'"),'Scheduling title metadata is missing from the universal shell',19);
uas_test(str_contains($shell,"['◫', 'Staff Scheduling', 'scheduling.php']"),'Scheduling is missing from universal navigation',20);

$targets=[
    'locations-admin.php','operations.php','catering-operations.php','catering-pipeline.php',
    'sales-intelligence.php','customer-promotions.php','customer-crm.php','online-orders-admin.php','recipes.php',
    'scheduling.php',
];
foreach($targets as $index=>$target){
    $page=file_get_contents($root.'/'.$target);
    uas_test(is_string($page)&&str_contains($page,'js/universal-admin-page-shell.js'),$target.' is not integrated with the universal shell',30+$index);
}

echo "universal-admin-page-shell=ok\n";