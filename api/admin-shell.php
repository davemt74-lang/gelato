<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/admin-shell-core.php';

$user=app_require_auth();
$page=basename((string)($_GET['page']??'workspace.php'));
app_json_response([
    'ok'=>true,
    'shell'=>admin_shell_page_context($user,$page),
]);
