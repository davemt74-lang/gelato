<?php
declare(strict_types=1);

/**
 * FM-B8D11: model-selected, permission-scoped delegation to existing
 * authenticated restaurant agents. Delegation itself is read-only. Actual
 * data access and any write/confirmation stay inside the destination API.
 * Neither the LLM nor the browser can provide a route or choose PHP code.
 */
require_once __DIR__.'/agent-node-registry.php';

function fm8d11_available_domains(array $actor):array
{
    $has=static fn(string $permission):bool=>app_has_permission($permission,$actor);
    $any=static function(array $names)use($has):bool{
        foreach($names as $name)if($has($name))return true;
        return false;
    };
    $rules=[
        'daily_manager'=> $has('manager.brief.view')&&$has('manager.brief.agent'),
        'live_shift'=> $any(['table_service.view','pos.use'])&&$any(['host.view','pos.use','table_service.view']),
        'front_of_house'=> $has('host.view')&&$any(['table_service.view','host.use','host.manage']),
        'sales_cost'=> $has('sales.agent')&&$any(['sales.costs.view','sales.view']),
        'sales'=> $has('sales.agent')&&$has('sales.view'),
        'crm'=> $has('crm.view'),
        'marketing'=> $any(['packages.view','public_pages.edit','settings.organization_edit']),
        'catering'=> $has('catering.agent'),
        'wholesale'=> $has('wholesale.agent')&&$has('wholesale.view'),
        'prep'=> $has('prep.intelligence.agent')&&$has('prep.intelligence.view'),
        'operations'=> ($has('tasks.agent')&&$has('tasks.view'))
            ||($has('inventory.agent')&&$has('inventory.view')),
        'timeclock'=> $has('timeclock.agent'),
        'online_orders'=> $any(['online_orders.fulfill','order_recovery.view','order_recovery.manage',
            'order_recovery.refund','pos.use','kds.view','crm.view']),
        'recipes'=> $has('recipes.view')&&$has('recipes.agent'),
        'equipment'=> $has('equipment.view')&&$has('agent.equipment_skills'),
        'kds'=> $has('kds.view'),
        'employee_development'=> $any(['employee.performance.view','employee.manage','staff.manage']),
        'employee_handoff'=> $any(['employee.handoffs.view','employee.handoffs.create',
            'employee.handoffs.manage','employee.self','agent.employee_view']),
        'purchasing'=> $has('purchasing.agent')&&$has('purchasing.view'),
        'scheduling'=> $any(['schedule.agent','schedule.view','schedule.self']),
    ];
    $nodes=gaw_agent_nodes();$allowed=[];
    foreach($rules as $name=>$isAllowed){
        if($isAllowed&&isset($nodes[$name]))$allowed[$name]=$nodes[$name];
    }
    // These existing task-specific Agent APIs are outside the node registry.
    if($has('menu.view'))$allowed['menu_brain']=[
        'label'=>'Menu Management','route'=>'api/agent-brain.php',
        'domain'=>'restaurant_brain','mode'=>'read_confirmed_write'];
    if($any(['resumes.view','audit.view','schedule.view']))$allowed['workforce']=[
        'label'=>'Staff and Hiring','route'=>'api/workspace-workforce-agent.php',
        'domain'=>'workspace_workforce','mode'=>'read'];
    if($any(['employee.self','tasks.self','agent.employee_view','training.self_view']))
        $allowed['employee_home']=[
            'label'=>'Employee Home','route'=>'api/employee-agent.php',
            'domain'=>'employee_home','mode'=>'read_confirmed_write'];
    if(($actor['role_slug']??'')==='wholesale_customer'
       &&$has('wholesale_portal.agent'))$allowed['wholesale_portal']=[
            'label'=>'Wholesale Customer Portal','route'=>'api/wholesale-portal-agent.php',
            'domain'=>'wholesale_portal','mode'=>'read_confirmed_write'];
    // Legacy owner gate remains a typed, nonexecuting approval protocol.
    // This is a governance exception, not general keyword intent routing.
    if(($actor['role_slug']??'')==='super_admin')$allowed['high_impact_approval']=[
        'label'=>'High-Impact Owner Approval','route'=>'api/food-monster-approvals.php',
        'domain'=>'high_impact_approval','mode'=>'human_approval_only'];
    return $allowed;
}

function fm8d11_catalogue(array $principal,array $context=[]):array
{
    if(!preg_match('/^agent-[a-f0-9]{20}$/D',(string)($context['threadId']??''))
       ||!preg_match('/^msg-[a-f0-9]{20}$/D',(string)($context['messageId']??'')))
        return [];
    $available=fm8d11_available_domains($principal);
    if(!$available)return [];
    $descriptions=[];
    foreach($available as $name=>$node){
        $descriptions[]=$name.' = '.$node['label'];
    }
    return ['delegate_restaurant_domain'=>[
        'description'=>'Hand a restaurant-specific request to an EXISTING Gelato operational agent when no dedicated model tool can complete it. Choose the best authorized domain by meaning, not by keywords. Especially use for operational status, customers, employee records, tasks, receiving, purchase orders, sales, production, equipment, and follow-up on a pending confirmation. Authorized domains: '.implode('; ',$descriptions).'. The receiving agent independently checks permissions and any required human approval. For explicit owner requests to propose, approve, reject or confirm a high-impact purchase, refund, task archive or permission change, use high_impact_approval if present; that older module requires typed verified approval phrases and independently prevents execution without its confirmation. Do not use this tool for general questions or when a dedicated model-selected native tool is available.',
        'parameters'=>['type'=>'object','properties'=>[
            'domain'=>['type'=>'string','enum'=>array_keys($available),
                'description'=>'Exact allowed existing agent domain, never a URL'],
        ],'required'=>['domain'],'additionalProperties'=>false],
        'allowedArguments'=>['domain'],'impact'=>'read',
        'authorize'=>static function(array $actor,array $args):bool{
            $available=fm8d11_available_domains($actor);
            return isset($available[(string)($args['domain']??'')]);
        },
        'validate'=>static fn(array $args,array $actor):array=>
            !is_string($args['domain']??null)||($args['domain']??'')===''?['domain']:[],
        'execute'=>static function(array $args,array $actor)use($context):array{
            $available=fm8d11_available_domains($actor);
            $domain=(string)$args['domain'];
            if(!isset($available[$domain]))
                throw new DomainException('Existing agent is not available to this account.');
            $node=$available[$domain];$route=(string)$node['route'];
            if(!preg_match('~^api/[a-z][a-z0-9-]{1,80}\.php$~D',$route))
                throw new DomainException('Unsafe legacy agent route.');
            return ['ok'=>true,'data'=>[
                'answer'=>'Using Gelato '.$node['label'].' to verify the request.',
                'source'=>'Gelato authenticated Agent node registry',
                'delegation'=>[
                    'route'=>$route,'domain'=>(string)$node['domain'],
                    'node'=>$domain,'threadId'=>(string)$context['threadId'],
                    'sourceMessageId'=>(string)$context['messageId'],
                    'mode'=>(string)$node['mode'],
                ],
            ]];
        },
    ]];
}
