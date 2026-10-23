<?php
declare(strict_types=1);
function entry_assert(bool $good, string $name): void { if (!$good) {fwrite(STDERR,"FAIL: {$name}\n");exit(1);} }
$html=file_get_contents(__DIR__.'/../index.html');
$workspace=file_get_contents(__DIR__.'/../workspace.php');
entry_assert(str_contains($html,'id="gelato-canonical-workspace-entry"'),'static entry redirect exists');
entry_assert(str_contains($html,"new URL('workspace.php', window.location.href)"),'redirect points to PHP workspace');
entry_assert(str_contains($html,'window.RESTAURANT_SERVER_SESSION === true'),'PHP render skips redirect');
entry_assert(str_contains($workspace,"window.RESTAURANT_SERVER_SESSION=true"),'server marker injected');
entry_assert(str_contains($workspace,'Cache-Control: private, no-store'),'dynamic route cache disabled');
entry_assert(!str_contains($workspace, '$sidebarRoutes'), 'duplicate PHP sidebar injection removed');
entry_assert(str_contains($workspace, 'gelato-server-navigation'), 'server-side admin tools fallback remains available');
entry_assert(str_contains($workspace, 'js/workspace-shell-sync.js?v=20261023-manual-chat1'), 'authorized sidebar synchronized by canonical client');
entry_assert(str_contains($workspace,'admin_shell_filtered_navigation($user)'),'permission-filtered nav');
entry_assert(str_contains($workspace,'?v='),'asset cache bust on PHP-rendered shell');
entry_assert(str_contains($workspace,"'<body>'"), 'workspace body injection');
$htaccess=file_get_contents(__DIR__.'/../.htaccess');
entry_assert(str_contains($htaccess,'DirectoryIndex index.php index.html'),'PHP default document');
entry_assert(str_contains($htaccess,'RewriteRule ^index\\.html$ workspace.php'),'legacy URL HTTP redirect');
foreach (['floor-planner-v2.php','glasses-vision-lab.php','pos.php','kds.php','admin.php'] as $route) {
  entry_assert(is_file(__DIR__.'/../'.$route),'Required route '.$route);
}
echo "static-workspace-entry=ok\n";
