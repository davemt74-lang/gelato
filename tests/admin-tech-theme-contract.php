<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$css=(string)file_get_contents($root.'/css/admin-tech-theme.css');
$shell=(string)file_get_contents($root.'/js/universal-admin-page-shell.js');
$workspace=(string)file_get_contents($root.'/workspace.php');

$checks=[
    'shared admin theme exists'=>$css!=='',
    'canvas is white'=>str_contains($css,'--tech-canvas:#ffffff'),
    'universal sidebar uses canvas color'=>str_contains($css,'body.gelato-universal-admin-page .uas-sidebar')&&str_contains($css,'background:var(--tech-canvas)!important'),
    'workspace sidebar uses canvas color'=>str_contains($css,'body.gelato-admin-tech-theme .sidebar')&&str_contains($css,'background:var(--tech-canvas)!important'),
    'main admin canvas is white'=>str_contains($css,'body.gelato-universal-admin-page main')&&str_contains($css,'background:var(--tech-canvas)!important'),
    'workspace canvas is white'=>str_contains($css,'body.gelato-admin-tech-theme .app')&&str_contains($css,'background:var(--tech-canvas)!important'),
    'modern tech border token exists'=>str_contains($css,'--tech-border:#e5e7eb'),
    'modern tech shadow token exists'=>str_contains($css,'--tech-shadow:'),
    'mobile universal breakpoint exists'=>str_contains($css,'@media(max-width:900px)')&&str_contains($css,'.uas-sidebar'),
    'mobile compact breakpoint exists'=>str_contains($css,'@media(max-width:520px)'),
    'universal shell loads theme'=>str_contains($shell,"css/admin-tech-theme.css?v=20260915-tech1")&&str_contains($shell,'ensureTheme();'),
    'workspace loads theme last'=>str_contains($workspace,'css/admin-tech-theme.css?v=20260915-tech1'),
    'workspace adds theme body class'=>str_contains($workspace,'gelato-admin-tech-theme'),
    'customer pages are not targeted'=>!str_contains($css,'customer-page'),
];

$failed=[];
foreach($checks as $label=>$ok)if(!$ok)$failed[]=$label;
if($failed){
    fwrite(STDERR,"Admin tech theme contract failed:\n - ".implode("\n - ",$failed)."\n");
    exit(1);
}

echo 'PASS: '.count($checks)." admin tech theme, white canvas/sidebar, and responsive shell checks.\n";
