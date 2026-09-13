<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/operations-core.php';
$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if(!app_has_permission('tasks.self',$user)&&!app_has_permission('agent.employee_view',$user))app_json_response(['ok'=>false,'message'=>'Employee Agent permission required.'],403);
if(!operations_core_ready($pdo))app_json_response(['ok'=>false,'message'=>'Operations Core migration is not installed. Run upgrade.php.'],503);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$in=app_json_input();app_verify_request_csrf($in);$message=trim((string)($in['message']??''));if($message===''||mb_strlen($message,'UTF-8')>2000)app_json_response(['ok'=>false,'message'=>'Enter an employee Agent request no longer than 2,000 characters.'],422);
$normalized=mb_strtolower(preg_replace('/^hey\s+gelato[,\s]*/iu','',$message)??$message,'UTF-8');
if(preg_match('/\b(prep|task|tasks|assigned|assignment|to do|todo|opening|closing|cleaning)\b/u',$normalized)){
    $category=preg_match('/\bprep\b/u',$normalized)?'prep':'';
    $rows=operations_task_list($pdo,$org,['category'=>$category,'userId'=>$uid,'query'=>'']);
    if(!$rows){$answer=$category?'You do not have any open prep tasks assigned to you.':'You do not have any open restaurant tasks assigned to you.';app_json_response(['ok'=>true,'skill'=>$category?'employee.prep':'employee.tasks','answer'=>$answer,'data'=>[],'sources'=>[]]);}
    $lines=[];foreach(array_slice($rows,0,30) as $row)$lines[]=$row['title'].' — '.($row['status']??'queued').($row['due_at']?' — due '.$row['due_at']:'').($row['station']?' — '.$row['station']:'');
    $answer=($category?'Your assigned prep:':'Your assigned restaurant tasks:')."\n- ".implode("\n- ",$lines);
    app_audit($pdo,$org,$uid,'agent.employee_tasks_used','user',(string)$uid,null,['count'=>count($rows),'category'=>$category?:'all']);
    app_json_response(['ok'=>true,'skill'=>$category?'employee.prep':'employee.tasks','answer'=>$answer,'data'=>$rows,'sources'=>array_column($rows,'public_id')]);
}
app_json_response(['ok'=>true,'skill'=>'employee.context','answer'=>'I can help with your assigned prep and restaurant tasks here. Ask about your schedule or time clock and Gelato will route those questions to the scheduling and attendance systems.','data'=>null,'sources'=>[]]);
