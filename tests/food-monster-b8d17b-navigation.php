<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require dirname(__DIR__).'/includes/food-monster-b8d17b-navigation.php';

$n=0;
function b17b_check(bool $okay,string $reason):void{
    global $n;
    if(!$okay){fwrite(STDERR,"B8D17B FAIL: $reason\n");exit(1);}
    $n++;
}
$actor=['id'=>71,'organization_id'=>9,'role_slug'=>'employee','is_owner_role'=>0,
    'permissions'=>['menu.view','timeclock.self']];
$pages=fm8d17b_pages($actor);
b17b_check(isset($pages['menu_manager'])&&$pages['menu_manager']['href']==='menu-manager.php',
    'menu-read role can navigate to actual Menu Manager');
b17b_check(isset($pages['timeclock'])&&$pages['timeclock']['href']==='timeclock.php',
    'employee can navigate to own Time Clock');
b17b_check(!isset($pages['customer_crm'])&&!isset($pages['scheduling']),
    'unrelated customer and scheduling pages are not advertised');
b17b_check(!isset($pages['workspace_llm'])&&!isset($pages['workspace_users']),
    'owner keys and user management cannot leak to ordinary employees');
b17b_check(fm8d17b_page_id('https://bad.example/x.php')===''
    &&fm8d17b_page_id('../admin.php')===''
    &&fm8d17b_page_id('menu-manager.php?redirect=outside')==='',
    'absolute, traversal and query URLs are not navigable');
$tools=fm8d17b_catalogue($actor);
b17b_check(isset($tools['navigate_to_gelato_page'])
    &&$tools['navigate_to_gelato_page']['impact']==='read',
    'navigation is a native read-only model-selected tool');
$tool=$tools['navigate_to_gelato_page'];
b17b_check(in_array('menu_manager',$tool['parameters']['properties']['page_id']['enum'],true)
    &&!in_array('customer_crm',$tool['parameters']['properties']['page_id']['enum'],true),
    'model receives only authorized page IDs');
b17b_check(($tool['authorize'])($actor,['page_id'=>'menu_manager'])===true,
    'fresh matching account can navigate');
$revoked=array_replace($actor,['permissions'=>[]]);
b17b_check(($tool['authorize'])($revoked,['page_id'=>'menu_manager'])===false,
    'revoked page permission fails fresh authorization');
b17b_check(($tool['authorize'])($actor,['page_id'=>'https://bad.example'])===false
    &&($tool['validate'])(['page_id'=>'../../admin.php'],$actor)!==[],
    'arbitrary model URLs and forged IDs rejected');
$result=($tool['execute'])(['page_id'=>'menu_manager'],$actor);
b17b_check(($result['data']['navigation']['kind']??'')==='verified_internal_page'
    &&($result['data']['navigation']['href']??'')==='menu-manager.php'
    &&($result['data']['navigation']['executed']??true)===false,
    'server returns local navigation receipt without claiming page opened');
$owner=array_replace($actor,['role_slug'=>'super_admin','is_owner_role'=>1,'permissions'=>['*']]);
$ownerPages=fm8d17b_pages($owner);
b17b_check(isset($ownerPages['workspace_llm'])&&isset($ownerPages['workspace_users']),
    'owner can navigate authorized management sections');
b17b_check(!isset(fm8d17b_pages(['id'=>0,'organization_id'=>9,'permissions'=>['*']])['menu_manager']),
    'unsigned-in account cannot discover navigable pages');
$guest=array_replace($actor,['organization_id'=>0]);
b17b_check(fm8d17b_pages($guest)===[],'cross-organization missing tenant is denied');
echo "Food Monster B8D17B navigation: $n assertions passed.\n";
