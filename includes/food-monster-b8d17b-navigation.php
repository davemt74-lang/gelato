<?php
declare(strict_types=1);

/**
 * FM-B8D17B: server-authorized navigation, not a general URL-opening tool.
 * Destinations come from Gelato's existing staff menu and access rules are
 * checked again after a model chooses a page.
 */

function fm8d17b_page_id(string $href):string
{
    if(!preg_match('/^[a-z][a-z0-9-]*\.php(?:#[a-z][a-z0-9-]*)?$/D',$href))return '';
    return str_replace(['.php#','.php','-','#'],['_','', '_','_'],$href);
}

function fm8d17b_pages(array $actor):array
{
    if((int)($actor['id']??0)<1||(int)($actor['organization_id']??0)<1)return [];
    // The live request has bootstrapped Gelato already. Isolated, stub-only
    // runtime tests intentionally do not load the application's bootstrap.
    if(!function_exists('admin_shell_navigation_catalog')){
        if(!function_exists('app_pdo'))return [];
        require_once __DIR__.'/admin-shell-core.php';
    }
    $pages=[];
    foreach(admin_shell_navigation_catalog() as $group){
        foreach($group['items'] as $item){
            $href=(string)($item['href']??'');
            $id=fm8d17b_page_id($href);
            if($id===''||!admin_shell_item_allowed($actor,$item))continue;
            $page=admin_route_access_page($href);
            if(admin_route_access_rule($page)!==null
               &&!admin_route_access_allowed($actor,$page))continue;
            $pages[$id]=['id'=>$id,'label'=>(string)$item['label'],
                'href'=>$href,'section'=>(string)$group['label']];
        }
    }
    return $pages;
}

function fm8d17b_catalogue(array $actor):array
{
    $pages=fm8d17b_pages($actor);
    if($pages===[])return [];
    $ids=implode(', ',array_keys($pages));
    return ['navigate_to_gelato_page'=>[
        'description'=>'Open an authorized Gelato staff screen in this browser when explicitly requested. This is not a record search, SQL query or mutation. Allowed page IDs: '.$ids,
        'parameters'=>['type'=>'object','properties'=>[
            'page_id'=>['type'=>'string','enum'=>array_keys($pages),
                'description'=>'Authorized internal page ID, never a URL.'],
        ],'required'=>['page_id'],'additionalProperties'=>false],
        'allowedArguments'=>['page_id'],'impact'=>'read',
        'authorize'=>static fn(array $fresh,array $args):bool=>
            isset(fm8d17b_pages($fresh)[(string)($args['page_id']??'')]),
        'validate'=>static function(array $args,array $fresh):array{
            $id=(string)($args['page_id']??'');
            return $id!==''&&isset(fm8d17b_pages($fresh)[$id])?[]:['page_id'];
        },
        'execute'=>static function(array $args,array $fresh):array{
            $pages=fm8d17b_pages($fresh);
            $id=(string)($args['page_id']??'');
            if(!isset($pages[$id]))return ['ok'=>false,'data'=>[]];
            $page=$pages[$id];
            return ['ok'=>true,'data'=>[
                'answer'=>'I found your authorized '.$page['label'].' screen. Opening it now. You can also use the verified Open page link in this chat.',
                'source'=>'Gelato staff navigation and current account page permissions',
                'navigation'=>['kind'=>'verified_internal_page','id'=>$page['id'],
                    'label'=>$page['label'],'href'=>$page['href'],
                    'section'=>$page['section'],'executed'=>false],
            ]];
        },
    ]];
}
