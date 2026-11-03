<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/hiring-core.php';
if ($_SERVER['REQUEST_METHOD']!=='GET' && $_SERVER['REQUEST_METHOD']!=='HEAD') { header('Allow: GET, HEAD'); http_response_code(405); exit; }
$user=app_current_user();
if (!$user) { http_response_code(401); exit('Sign in to view application files.'); }
if (!app_has_permission('resumes.view',$user)) { http_response_code(403); exit('You do not have permission to view application files.'); }
$organizationId=(int)$user['organization_id'];
$statement=app_pdo()->prepare("SELECT f.*,rf.file_type FROM resume_files rf
    JOIN resume_submissions r ON r.id=rf.resume_submission_id AND r.organization_id=? AND r.public_id=?
    JOIN files f ON f.id=rf.file_id AND f.organization_id=? AND f.visibility='hiring_private' AND f.deleted_at IS NULL
    WHERE f.id=? LIMIT 1");
$statement->execute([$organizationId,(string)($_GET['application']??''),$organizationId,(int)($_GET['id']??0)]);
$file=$statement->fetch();
try { $path=$file?app_private_storage_read((string)$file['storage_path'],'hiring-documents/'.$organizationId):null; }
catch (Throwable) { http_response_code(503); exit('Private application files are temporarily unavailable.'); }
if (!$path) { http_response_code(404); exit('Application file not found.'); }
header('Content-Type: '.$file['mime_type']);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
$disposition=in_array($file['file_type'],['photo','video'],true)?'inline':'attachment';
header('Content-Disposition: '.$disposition.'; filename="attachment"; filename*=UTF-8\'\''.rawurlencode((string)$file['original_name']));
header('Content-Length: '.filesize($path));
session_write_close();
if ($_SERVER['REQUEST_METHOD']==='GET') readfile($path);
