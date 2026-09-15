<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/admin-shell-core.php';

$user=app_require_auth();
$page=basename(strtolower((string)($_GET['page']??'workspace.php')));
$context=admin_shell_page_context($user,$page);

if(($context['type']??'')==='standard'
    && admin_route_access_rule($page)!==null
    && !admin_route_access_allowed($user,$page)){
    app_json_response(['ok'=>false,'message'=>'You do not have permission to access this administration page.'],403);
}

app_json_response([
    'ok'=>true,
    'shell'=>$context,
]);
