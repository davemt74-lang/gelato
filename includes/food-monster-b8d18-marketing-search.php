<?php
declare(strict_types=1);

/** B8D18J6A: model-selected promotion lookup, minimal tenant-scoped package facts. */
function fm8d18j6a_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('packages.view',$actor);
}
function fm8d18j6a_fields(array $args):array
{
    $query=$args['query']??null;
    if(!is_string($query)||mb_strlen(trim($query),'UTF-8')<2
       ||mb_strlen(trim($query),'UTF-8')>100
       ||preg_match('/[%_;\x00-\x1F]/u',$query))return ['query'];
    return [];
}
function fm8d18j6a_search(array $args,array $actor):array
{
    if(!fm8d18j6a_can($actor))throw new DomainException('Package Deals view permission is required.');
    if($missing=fm8d18j6a_fields($args))throw new InvalidArgumentException('Specify a specific promotion name or saved ID.');
    require_once __DIR__.'/package-deals-core.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$query=trim((string)$args['query']);
    if(!package_deals_ready($pdo))throw new RuntimeException('Package Deals is unavailable. Complete upgrade.php first.');
    $q=$pdo->prepare("SELECT public_id,name,status,featured,starts_at,ends_at
        FROM package_deals
        WHERE organization_id=? AND (LOCATE(LOWER(?),LOWER(name))>0 OR public_id=?)
        ORDER BY FIELD(status,'active','paused','draft','ended','archived'),name,id LIMIT 9");
    $q->execute([$org,$query,$query]);$rows=$q->fetchAll();
    $truncated=count($rows)>8;
    $matches=array_map(static fn(array $r):array=>[
        'publicId'=>(string)$r['public_id'],'name'=>(string)$r['name'],
        'status'=>(string)$r['status'],'featured'=>(bool)$r['featured'],
        'startsAt'=>$r['starts_at'],'endsAt'=>$r['ends_at'],
    ],array_slice($rows,0,8));
    $answer=($matches?('Found '.count($matches).' matching saved promotion(s).'):
        'No matching saved promotion was found.').($truncated?' More results exist; narrow the query.':'');
    if(count($matches)>1)$answer.=' Select the exact public ID before requesting a change.';
    return ['ok'=>true,'data'=>[
        'answer'=>$answer,'source'=>'Gelato Package Deals (authenticated organization lookup)',
        'readOnly'=>true,'matches'=>$matches,'truncated'=>$truncated,
    ]];
}
function fm8d18j6a_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j6a_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['search_marketing_packages'=>[
        'description'=>'Find saved Gelato Package Deals promotions by specific name or exact saved public ID, with current status, Featured flag and date window. This is an authenticated tenant-scoped read. It returns up to eight minimal records to Agent Chat locally and no customer data or sales figures. For several matches ask the signed-in user to select the exact public ID before any change. Never infer an ID or update a promotion from these search results.',
        'parameters'=>['type'=>'object','properties'=>[
            'query'=>['type'=>'string','description'=>'Specific promotion name fragment or exact saved public ID, at least two characters'],
        ],'required'=>['query'],'additionalProperties'=>false],
        'allowedArguments'=>['query'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j6a_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j6a_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j6a_search($args,$user),
    ]];
}
