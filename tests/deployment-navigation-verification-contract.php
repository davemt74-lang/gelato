<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/admin-shell-core.php';
function verify_deploy(bool $x,string $label):void{if(!$x){fwrite(STDERR,"FAIL: $label\n");exit(1);}}
verify_deploy(app_role_has_owner_authority(['slug'=>'super_admin','is_system_role'=>1,'is_owner_role'=>0]),'canonical owner role');
verify_deploy(!app_role_has_owner_authority(['slug'=>'super_admin','is_system_role'=>0,'is_owner_role'=>0]),'custom spoof denied');
$workspace=file_get_contents(__DIR__.'/../workspace.php');
verify_deploy(str_contains($workspace,'$isOwner = app_role_has_owner_authority($role);'),'workspace permissions match owner');
verify_deploy(str_contains($workspace,'$permissions = $isOwner ?'),'role snapshot owner wildcard');
verify_deploy(str_contains($workspace,'admin_shell_filtered_navigation($user)'),'server menu fallback');
verify_deploy(str_contains($workspace,'Build '),'visible build marker');
$admin=file_get_contents(__DIR__.'/../admin.php');
verify_deploy(str_contains($admin,'href="deployment-check.php"'),'live diagnostics link');
verify_deploy(str_contains($admin,'Admin release:'),'admin build marker');
$diag=file_get_contents(__DIR__.'/../deployment-check.php');
verify_deploy(str_contains($diag,'app_require_auth()'),'diagnostics authenticated');
verify_deploy(str_contains($diag,'http_response_code(403)'),'owner check');
verify_deploy(str_contains($diag,'DEPLOY_SHA.txt'),'build marker source');
verify_deploy(str_contains($diag,'information_schema.tables'),'schema diagnostics');
verify_deploy(!str_contains($diag,'UPDATE users'),'read-only diagnostic');
verify_deploy(is_file(__DIR__.'/../floor-planner-v2.php'),'floor planner file exists');
echo "deployment-navigation-verification=ok\n";
