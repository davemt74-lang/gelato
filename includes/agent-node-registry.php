<?php
declare(strict_types=1);

function gaw_agent_nodes(): array
{
    return [
        'brain'=>[
            'label'=>'Main Agent Brain',
            'route'=>'api/brain-orchestrator.php',
            'domain'=>'agent_brain_orchestration',
            'mode'=>'read_orchestrator',
        ],
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
        'live_shift'=>[
            'label'=>'Live Shift Orchestration',
            'route'=>'api/live-shift-agent.php',
            'domain'=>'live_shift',
            'mode'=>'read_write',
        ],
        'front_of_house'=>[
            'label'=>'Front of House',
            'route'=>'api/front-of-house-agent.php',
            'domain'=>'front_of_house',
            'mode'=>'read_confirmed_write',
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
        'crm'=>[
            'label'=>'Customer CRM',
            'route'=>'api/customer-crm-agent.php',
            'domain'=>'customer_crm',
            'mode'=>'read_confirmed_write',
        ],
        'marketing'=>[
            'label'=>'Marketing + Public Site',
            'route'=>'api/marketing-agent.php',
            'domain'=>'marketing',
            'mode'=>'read_confirmed_write',
        ],
        'catering'=>[
            'label'=>'Catering Operations',
            'route'=>'api/catering-agent.php',
            'domain'=>'catering',
            'mode'=>'read_confirmed_write',
        ],
        'wholesale'=>[
            'label'=>'Wholesale Fulfillment',
            'route'=>'api/wholesale-agent.php',
            'domain'=>'wholesale',
            'mode'=>'read_confirmed_write',
        ],
        'prep'=>[
            'label'=>'Prep + Inventory Intelligence',
            'route'=>'api/prep-intelligence-agent.php',
            'domain'=>'prep_intelligence',
            'mode'=>'read_confirmed_write',
        ],
        'operations'=>[
            'label'=>'Restaurant Operations',
            'route'=>'api/operations-agent.php',
            'domain'=>'operations',
            'mode'=>'read_confirmed_write',
        ],
        'online_orders'=>[
            'label'=>'Online Ordering + Pickup Fulfillment',
            'route'=>'api/online-order-agent.php',
            'domain'=>'online_order_fulfillment',
            'mode'=>'read_confirmed_write',
        ],
        'recipes'=>[
            'label' => 'Recipe + Production Standards',
            'route'=>'api/recipe-agent.php',
            'domain'=>'recipe_production_standards',
            'mode'=>'read_confirmed_write',
        ],
        'equipment'=>[
            'label'=>'Equipment + Maintenance',
            'route'=>'api/equipment-agent.php',
            'domain'=>'equipment_maintenance',
            'mode'=>'read_confirmed_write',
        ],
        'kds'=>[
            'label'=>'Kitchen Display System',
            'route'=>'api/kds-agent.php',
            'domain'=>'kds',
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
    $candidates=[];

    foreach((array)($_SESSION['agent_node_pending']??[]) as $node=>$rows){
        $proposal=is_array($rows)?($rows[$key]??null):null;
        if(!is_array($proposal))continue;
        $expires=(int)($proposal['expires']??0);
        if($expires<time()){
            unset($_SESSION['agent_node_pending'][$node][$key]);
            continue;
        }
        $candidates[]=['node'=>(string)$node,'created'=>(int)($proposal['created']??($expires-600)),'expires'=>$expires];
    }

    $legacy=[
        'purchasing'=>'purchasing_agent_pending',
        'scheduling'=>'schedule_agent_pending',
    ];
    foreach($legacy as $node=>$sessionKey){
        $proposal=$_SESSION[$sessionKey][$key]??null;
        if(!is_array($proposal))continue;
        $expires=(int)($proposal['expires']??0);
        if($expires<time()){
            unset($_SESSION[$sessionKey][$key]);
            continue;
        }
        $candidates[]=['node'=>$node,'created'=>(int)($proposal['created']??($expires-600)),'expires'=>$expires];
    }

    if(!$candidates)return null;
    usort($candidates,static fn($a,$b)=>($b['created']<=>$a['created'])?:($b['expires']<=>$a['expires']));
    return (string)$candidates[0]['node'];
}
