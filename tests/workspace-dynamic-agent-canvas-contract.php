<?php
declare(strict_types=1);

function contract(bool $ok,string $message,int $code):void
{
    if(!$ok){fwrite(STDERR,"FAIL {$code}: {$message}\n");exit($code);}
}

$root=dirname(__DIR__);
$dynamic=(string)file_get_contents($root.'/js/dynamic-agent-canvas.js');
$globalAdd=(string)file_get_contents($root.'/js/global-add-canvas.js');
$workspace=(string)file_get_contents($root.'/js/workspace-command-enhancements.js');
$shell=(string)file_get_contents($root.'/js/workspace-shell-sync.js');
$router=(string)file_get_contents($root.'/includes/agent-workspace-core.php');
$workforce=(string)file_get_contents($root.'/includes/workspace-workforce-core.php');
$workforceApi=(string)file_get_contents($root.'/api/workspace-workforce.php');
$workforceAgent=(string)file_get_contents($root.'/api/workspace-workforce-agent.php');

contract(str_contains($dynamic,"root.id='gelato-dynamic-agent'"),'dynamic Agent canvas root is missing',2);
contract(str_contains($dynamic,"document.documentElement.style.overflow='hidden'"),'canvas does not preserve the current page in-place',3);
contract(str_contains($dynamic,"document.documentElement.style.overflow=state.previousOverflow"),'canvas does not restore page scroll state',4);
contract(str_contains($dynamic,'removeLegacyCanvasButton'),'legacy Agent Canvas footer button is not removed',5);
contract(str_contains($dynamic,"event.target.closest('#gaSend')"),'footer Send does not open the dynamic canvas',6);
contract(str_contains($dynamic,"event.target?.id==='gaInput'"),'footer Enter does not open the dynamic canvas',7);
contract(str_contains($dynamic,'window.GelatoGlobalAgent.send(text,false)'),'dynamic canvas does not reuse the main Agent thread/send path',8);
contract(str_contains($dynamic,"window.addEventListener('gelato-agent-response'"),'dynamic canvas does not follow persistent Agent responses',9);
contract(str_contains($globalAdd,"js/dynamic-agent-canvas.js"),'common admin shell does not load the dynamic Agent canvas',10);
contract(str_contains($globalAdd,"js/global-agent.js"),'common admin shell does not load the global Agent',11);
contract(str_contains($workspace,'New resumes'),'New Resumes command-center panel is missing',12);
contract(str_contains($workspace,'Recent employee activity'),'Recent Employee Activity panel is missing',13);
contract(str_contains($workspace,'Scheduling'),'Scheduling panel is missing',14);
contract(str_contains($workspace,"api/workspace-workforce.php"),'workspace panels are not server-backed',15);
contract(str_contains($shell,"workspace-command-enhancements.js"),'workspace does not load command-center enhancements',16);
contract(str_contains($router,"api/workspace-workforce-agent.php"),'main Agent router is not connected to workforce intelligence',17);
contract(str_contains($workforce,'resume_submissions'),'workforce snapshot does not use canonical resume data',18);
contract(str_contains($workforce,'audit_log'),'workforce snapshot does not use canonical activity data',19);
contract(str_contains($workforce,'scheduling_summary'),'workforce snapshot does not use canonical scheduling data',20);
contract(str_contains($workforceApi,'workspace_workforce_snapshot'),'workspace API does not use shared workforce domain logic',21);
contract(str_contains($workforceAgent,"'skill'=>'workspace.workforce'"),'workforce Agent does not expose the shared skill',22);
contract(str_contains($workforceAgent,'app_verify_request_csrf'),'workforce Agent POST is missing CSRF validation',23);

echo "workspace dynamic Agent canvas contract passed\n";
