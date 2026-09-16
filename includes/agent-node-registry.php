<?php
declare(strict_types=1);

function gaw_agent_nodes(): array
{
    return [
        'command_center'=>[
            'label'=>'Manager Command Center',
            'route'=>'api/admin-dashboard-agent.php',
            'domain'=>'admin_dashboard',
            'mode'=>'read_orchestrator',
        ],
        'daily_manager'=>[
            'label'=>'Daily Manager Brief',
            'route'=>'api/daily-manager-agent.php',
            'domain'=>'daily_manager_brief',
            'mode'=>'read_orchestrator',
        ],
        'sales_cost'=>[
            'label'=>'Sales Cost Intelligence',
            'route'=>'api/sales-cost-agent.php',
            'domain'=>'sales_cost_intelligence',
            'mode'=>'read',
        ],
        'sales'=>[
            'label'=>'Sales Intelligence',
            'route'=>'api/sales-agent.php',
            'domain'=>'sales_intelligence',
            'mode'=>'read',
        ],
        'employee_development'=>[
            'label'=>'Employee Development',
            'route'=>'api/employee-development-agent.php',
            'domain'=>'employee_development',
            'mode'=>'read',
        ],
        'employee_handoff'=>[
            'label'=>'Employee Handoff',
            'route'=>'api/employee-agent.php',
            'domain'=>'employee_handoff',
            'mode'=>'read_write',
        ],
        'purchasing'=>[
            'label'=>'Purchasing + Inventory',
            'route'=>'api/purchasing-agent.php',
            'domain'=>'purchasing',
            'mode'=>'read_confirmed_write',
        ],
        'scheduling'=>[
            'label'=>'Scheduling + Employee Operations',
            'route'=>'api/scheduling-agent.php',
            'domain'=>'scheduling',
            'mode'=>'read_confirmed_write',
        ],
        'pos'=>[
            'label'=>'POS Context',
            'route'=>'api/pos-agent.php',
            'domain'=>'pos_context',
            'mode'=>'read',
        ],
    ];
}

function gaw_agent_node(string $key): ?array
{
    $nodes=gaw_agent_nodes();
    return $nodes[$key]??null;
}

function gaw_node_route(string $key,?string $domainOverride=null): array
{
    $node=gaw_agent_node($key);
    if(!$node)throw new InvalidArgumentException('Unknown Agent node.');
    return [
        'route'=>$node['route'],
        'domain'=>$domainOverride?:$node['domain'],
        'node'=>$key,
        'nodeLabel'=>$node['label'],
        'nodeMode'=>$node['mode'],
    ];
}

function gaw_normalize_node_route(array $route): array
{
    $domain=(string)($route['domain']??'');
    foreach(gaw_agent_nodes() as $key=>$node){
        if($domain===(string)$node['domain']||(string)($route['route']??'')===(string)$node['route']){
            return ['ok'=>true]+gaw_node_route($key,$domain!==''?$domain:null);
        }
    }
    return ['ok'=>true]+$route;
}

function gaw_pending_action_node(int $organizationId,int $userId): ?string
{
    $key=$organizationId.':'.$userId;
    $maps=[
        'purchasing'=>'purchasing_agent_pending',
        'scheduling'=>'schedule_agent_pending',
    ];
    $candidates=[];
    foreach($maps as $node=>$sessionKey){
        $proposal=$_SESSION[$sessionKey][$key]??null;
        if(!is_array($proposal))continue;
        $expires=(int)($proposal['expires']??0);
        if($expires<time()){
            unset($_SESSION[$sessionKey][$key]);
            continue;
        }
        $candidates[]=['node'=>$node,'expires'=>$expires];
    }
    if(!$candidates)return null;
    usort($candidates,static fn($a,$b)=>$b['expires']<=>$a['expires']);
    return (string)$candidates[0]['node'];
}
