<?php
declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/admin-control-core.php';
require_once __DIR__.'/admin-route-access.php';

function admin_shell_is_owner(array $user): bool
{
    return (int)($user['is_owner_role']??0)===1 || in_array('*',$user['permissions']??[],true);
}

function admin_shell_has_any(array $user,array $permissions): bool
{
    if(admin_shell_is_owner($user)) return true;
    foreach($permissions as $permission){
        if(app_has_permission((string)$permission,$user)) return true;
    }
    return false;
}

function admin_shell_item_allowed(array $user,array $item): bool
{
    $route=admin_route_access_page((string)($item['href']??''));
    if($route!=='' && admin_route_access_rule($route)!==null && !admin_route_access_allowed($user,$route)) return false;
    if(!empty($item['ownerOnly'])) return admin_shell_is_owner($user);
    if(!empty($item['always'])) return true;
    $permissions=$item['permissions']??[];
    return $permissions!==[] && admin_shell_has_any($user,$permissions);
}

function admin_shell_page_catalog(): array
{
    return [
        'admin.php'=>['Restaurant Admin','Restaurant configuration and operating controls','standard'],
        'admin-menu-import.php'=>['Menu Import','Owner menu synchronization and canonical menu data','standard'],
        'locations-admin.php'=>['Locations','Restaurant locations, hours and service configuration','standard'],
        'operations.php'=>['Operations','Tasks, prep, inventory and restaurant work management','standard'],
        'timeclock.php'=>['Time Clock + Attendance','Attendance, labor and employee Agent tools','standard'],
        'scheduling.php'=>['Staff Scheduling','Shifts, availability, coverage and labor planning','standard'],
        'purchasing.php'=>['Purchasing + Receiving','Vendors, purchase orders, receiving and cost intelligence','standard'],
        'equipment.php'=>['Equipment Catalog','Restaurant assets, service history and maintenance intelligence','standard'],
        'equipment-detail.php'=>['Equipment Detail','Asset history, service, contacts and maintenance','standard'],
        'recipes.php'=>['Recipe Library + Builder','Restaurant recipes, images and AI mapping','standard'],
        'prep-intelligence.php'=>['Prep Intelligence','Prep demand, restock pressure and production planning','standard'],
        'catering-operations.php'=>['Catering Operations','Production, fulfillment and event execution','standard'],
        'catering-pipeline.php'=>['Catering Pipeline','Catering leads, quotes and customer pipeline','standard'],
        'sales-intelligence.php'=>['Sales Intelligence','Sales performance, demand and source health','standard'],
        'sales-import-center.php'=>['Sales Import Center','Import, normalize and monitor external POS sales data','standard'],
        'sales-cost-intelligence.php'=>['Cost Intelligence','Food cost, margin and sales-cost performance','standard'],
        'customer-promotions.php'=>['Customer Promotions','Customer campaigns and account Inbox promotions','standard'],
        'customer-crm.php'=>['Customer CRM','Identity, consent and transaction relationships','standard'],
        'online-orders-admin.php'=>['Online Orders','Customer pickup and online-order operations','standard'],
        'public-site-settings.php'=>['Public Site Settings','Public website contact, location and brand settings','standard'],
        'wholesale-pipeline.php'=>['Wholesale','Wholesale pipeline, accounts and growth','standard'],
        'wholesale-accounts.php'=>['Wholesale Customers','Wholesale customer accounts and order relationships','standard'],
        'wholesale-acquisition.php'=>['Wholesale Acquisition','Wholesale prospecting and account development','standard'],
        'wholesale-commerce.php'=>['Wholesale Commerce','Wholesale products, pricing and commerce controls','standard'],
        'wholesale-customer-360.php'=>['Wholesale Customer 360','Wholesale relationship, orders and account intelligence','standard'],
        'wholesale-demand.php'=>['Wholesale Demand','Wholesale demand and production intelligence','standard'],
        'wholesale-fulfillment.php'=>['Wholesale Fulfillment','Wholesale production and fulfillment operations','standard'],
        'wholesale-order-entry.php'=>['Wholesale Order Entry','Create and manage wholesale orders','standard'],
        'wholesale-purchasing.php'=>['Wholesale Purchasing','Wholesale purchasing and supply operations','standard'],
        'wholesale-receivables.php'=>['Wholesale Receivables','Wholesale balances and receivables','standard'],

        // Native workspace: same design language, but its own SPA shell.
        'workspace.php'=>['Dashboard','Restaurant operating workspace','workspace'],

        // Purpose-built operational canvases keep their specialized headers/layouts.
        'pos.php'=>['POS','Point of sale','workstation'],
        'kds.php'=>['KDS','Kitchen display system','workstation'],
        'kds-dashboard.php'=>['KDS Dashboard','Kitchen display system','workstation'],
        'floor-planner.php'=>['Floor Planner','Floor planning canvas','workstation'],
        'floor-planner-v2.php'=>['Floor Planner 2.0','Floor planning canvas','workstation'],
        'floor-planner-ops.php'=>['Floor Planner','Floor planning canvas','workstation'],
        'floor-planner-ops-legacy.php'=>['Floor Planner','Floor planning canvas','workstation'],
        'table-service.php'=>['Table Service','Dining room service workspace','workstation'],
        'host-stand.php'=>['Host Stand','Dining room host workspace','workstation'],
        'agent-canvas.php'=>['Agent Canvas','Restaurant Agent conversation canvas','workstation'],

        // Customer / partner experiences are intentionally separate from staff/admin chrome.
        'customer-account.php'=>['Customer Account','Customer account','customer'],
        'customer-login.php'=>['Customer Login','Customer account','customer'],
        'customer-signup.php'=>['Customer Sign Up','Customer account','customer'],
        'online-order.php'=>['Online Ordering','Customer ordering','customer'],
        'wholesale-portal.php'=>['Wholesale Portal','Wholesale customer account','customer'],
        'wholesale-login.php'=>['Wholesale Login','Wholesale customer account','customer'],
        'wholesale-accept.php'=>['Wholesale Account','Wholesale customer account','customer'],
    ];
}

function admin_shell_navigation_catalog(): array
{
    return [
        ['id'=>'team','label'=>'Team & Hiring','items'=>[
            ['icon'=>'⌂','label'=>'Workspace','href'=>'workspace.php','always'=>true],
            ['icon'=>'♙','label'=>'User Accounts','href'=>'workspace.php#users','permissions'=>['users.view','users.edit']],
            ['icon'=>'⚙','label'=>'Account Types','href'=>'workspace.php#roles','permissions'=>['roles.view','roles.edit']],
            ['icon'=>'▣','label'=>'Resume Submissions','href'=>'workspace.php#resumes','permissions'=>['resumes.view']],
            ['icon'=>'⌂','label'=>'Jobs','href'=>'workspace.php#jobs','permissions'=>['jobs.view','jobs.edit']],
            ['icon'=>'☷','label'=>'Form Builder','href'=>'workspace.php#forms','ownerOnly'=>true],
            ['icon'=>'◫','label'=>'Staff Scheduling','href'=>'scheduling.php','permissions'=>['schedule.view','schedule.manage','schedule.self','staff.view','staff.manage']],
            ['icon'=>'◷','label'=>'Time Clock + Attendance','href'=>'timeclock.php','permissions'=>['timeclock.self','timeclock.view','timeclock.manage','attendance.view','voice.self','voice.manage']],
        ]],
        ['id'=>'website','label'=>'Website & Locations','items'=>[
            ['icon'=>'⌖','label'=>'Locations','href'=>'locations-admin.php','permissions'=>['locations.manage']],
            ['icon'=>'◇','label'=>'Public Site Settings','href'=>'public-site-settings.php','permissions'=>['public_pages.edit','settings.organization_edit']],
            ['icon'=>'◇','label'=>'Landing Page','href'=>'workspace.php#landing-builder','permissions'=>['public_pages.edit']],
            ['icon'=>'◐','label'=>'Brand Settings','href'=>'workspace.php#brand','permissions'=>['settings.organization_edit']],
        ]],
        ['id'=>'operations','label'=>'Operations','items'=>[
            ['icon'=>'↗','label'=>'Online Orders','href'=>'online-orders-admin.php','permissions'=>['pos.use','pos.manage','kds.view','kds.configure','crm.view','crm.manage']],
            ['icon'=>'✓','label'=>'Operations','href'=>'operations.php','permissions'=>['tasks.view','tasks.manage','tasks.self','inventory.view','inventory.manage']],
            ['icon'=>'▤','label'=>'Recipe Library + Builder','href'=>'recipes.php','permissions'=>['recipes.view','recipes.edit','recipes.ai_map']],
            ['icon'=>'▤','label'=>'Prep Intelligence','href'=>'prep-intelligence.php','permissions'=>['prep.intelligence.view','prep.intelligence.manage']],
            ['icon'=>'▦','label'=>'Floor Planner','href'=>'floor-planner-v2.php','permissions'=>['floorplans.view','floorplans.manage','floorplans.edit']],
            ['icon'=>'⚙','label'=>'Equipment Catalog','href'=>'equipment.php','permissions'=>['equipment.view','equipment.edit','equipment.service','equipment.contacts']],
            ['icon'=>'▣','label'=>'Purchasing + Receiving','href'=>'purchasing.php','permissions'=>['purchasing.view','purchasing.manage','vendors.manage','receiving.manage']],
        ]],
        ['id'=>'sales','label'=>'Sales & Events','items'=>[
            ['icon'=>'↗','label'=>'Sales Intelligence','href'=>'sales-intelligence.php','permissions'=>['sales.view']],
            ['icon'=>'⇩','label'=>'Sales Import Center','href'=>'sales-import-center.php','permissions'=>['sales.import','sales.import_profiles.manage']],
            ['icon'=>'$','label'=>'Cost Intelligence','href'=>'sales-cost-intelligence.php','permissions'=>['sales.view','inventory.view']],
            ['icon'=>'◎','label'=>'Customer CRM','href'=>'customer-crm.php','permissions'=>['crm.view','crm.manage']],
            ['icon'=>'✦','label'=>'Customer Promotions','href'=>'customer-promotions.php','permissions'=>['customer_promotions.manage']],
            ['icon'=>'◈','label'=>'Catering Operations','href'=>'catering-operations.php','permissions'=>['catering.view','catering.manage']],
            ['icon'=>'◈','label'=>'Catering Pipeline','href'=>'catering-pipeline.php','permissions'=>['catering.view','catering.manage']],
            ['icon'=>'◇','label'=>'Wholesale','href'=>'wholesale-pipeline.php','permissions'=>['wholesale.view','wholesale.manage']],
            ['icon'=>'◎','label'=>'Wholesale Customers','href'=>'wholesale-accounts.php','permissions'=>['wholesale.view','wholesale.manage']],
        ]],
        ['id'=>'ai','label'=>'AI & Knowledge','items'=>[
            ['icon'=>'✦','label'=>'Owner Agent','href'=>'workspace.php#owner','permissions'=>['agent.owner_view']],
            ['icon'=>'✦','label'=>'Agent Canvas','href'=>'agent-canvas.php','always'=>true],
            ['icon'=>'⌁','label'=>'LLM API Keys','href'=>'workspace.php#llm','ownerOnly'=>true],
            ['icon'=>'▤','label'=>'Menu Knowledge','href'=>'workspace.php#menu','always'=>true],
        ]],
    ];
}

function admin_shell_filtered_navigation(array $user): array
{
    $groups=[];
    foreach(admin_shell_navigation_catalog() as $group){
        $items=[];
        foreach($group['items'] as $item){
            if(!admin_shell_item_allowed($user,$item)) continue;
            unset($item['permissions'],$item['ownerOnly'],$item['always']);
            $items[]=$item;
        }
        if($items!==[]) $groups[]=['id'=>$group['id'],'label'=>$group['label'],'items'=>$items];
    }
    return $groups;
}

function admin_shell_header_links(array $user): array
{
    $links=[];
    if(app_has_permission('pos.use',$user)) $links[]=['label'=>'POS','href'=>'pos.php','style'=>'dark'];
    if(app_has_permission('kds.view',$user)) $links[]=['label'=>'KDS','href'=>'kds.php','style'=>'kds'];
    return $links;
}

function admin_shell_account(array $user): array
{
    $first=trim((string)($user['first_name']??''));
    $last=trim((string)($user['last_name']??''));
    $initials=mb_strtoupper(mb_substr($first,0,1,'UTF-8').mb_substr($last,0,1,'UTF-8'),'UTF-8');
    if($initials==='') $initials='A';
    $menu=[
        ['label'=>'My profile','href'=>'workspace.php#profile','icon'=>'◉'],
        ['label'=>'Notifications','href'=>'workspace.php#notifications','icon'=>'♢'],
    ];
    if(admin_control_allowed($user)) $menu[]=['label'=>'Restaurant Admin','href'=>'admin.php','icon'=>'▦'];
    $menu[]=['label'=>'Public website ↗','href'=>'index.php','icon'=>'◇','target'=>'_blank'];
    $menu[]=['separator'=>true];
    $menu[]=['label'=>'Log out','href'=>'logout.php','icon'=>'↪','danger'=>true];
    return [
        'displayName'=>(string)($user['display_name']??trim($first.' '.$last)?:'Account'),
        'initials'=>$initials,
        'role'=>(string)($user['role_name']??$user['job_title']??'Restaurant access'),
        'menu'=>$menu,
    ];
}

function admin_shell_page_context(array $user,string $page): array
{
    $page=basename(strtolower(trim($page)));
    $catalog=admin_shell_page_catalog();
    [$title,$subtitle,$type]=$catalog[$page]??[ucwords(str_replace(['-','.php'],[' ',''],$page?:'Admin')),'Gelato restaurant administration','standard'];
    if((string)($user['role_slug']??'')==='wholesale_customer') $type='customer';
    return [
        'page'=>$page,
        'title'=>$title,
        'subtitle'=>$subtitle,
        'type'=>$type,
        'navigation'=>$type==='standard'||$type==='workspace'?admin_shell_filtered_navigation($user):[],
        'headerLinks'=>$type==='standard'||$type==='workspace'?admin_shell_header_links($user):[],
        'account'=>admin_shell_account($user),
    ];
}
