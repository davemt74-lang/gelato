<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = app_require_permission('recipes.view');
$pdo = app_pdo();
$organizationId=(int)$user['organization_id'];$id=(int)($_GET['id']??0);if($id<1){http_response_code(404);exit('Image not found.');}
$statement=$pdo->prepare("SELECT f.storage_path,f.mime_type,f.original_name,f.file_size FROM recipe_images ri INNER JOIN files f ON f.id=ri.file_id AND f.deleted_at IS NULL INNER JOIN recipes r ON r.id=ri.recipe_id AND r.archived_at IS NULL WHERE ri.id=? AND ri.organization_id=? AND r.organization_id=? LIMIT 1");$statement->execute([$id,$organizationId,$organizationId]);$row=$statement->fetch();if(!$row){http_response_code(404);exit('Image not found.');}
$relative=str_replace('\\','/',(string)$row['storage_path']);if(!str_starts_with($relative,'storage/recipe-images/')){http_response_code(403);exit('Invalid image path.');}$root=realpath(RESTAURANT_APP_ROOT.'/storage/recipe-images');$path=realpath(RESTAURANT_APP_ROOT.'/'.$relative);if(!$root||!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||!is_file($path)){http_response_code(404);exit('Image not found.');}
header('Content-Type: '.(string)$row['mime_type']);header('Content-Length: '.filesize($path));header('Cache-Control: private, max-age=300');header('X-Content-Type-Options: nosniff');header('Content-Disposition: inline; filename="recipe-image"');readfile($path);
