<?php
declare(strict_types=1);

/** B8D18J5B: exact-profile CRM relationship reads through authenticated server. */
require_once __DIR__.'/customer-crm-core.php';

function fm8d18j5b_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('crm.view',$actor);
}
function fm8d18j5b_fields(array $args):array
{
    $missing=[];$id=$args['customer_public_id']??null;
    if(!is_string($id)||strlen(trim($id))<2||strlen($id)>120||
       !preg_match('/^[A-Za-z0-9_.:-]+$/D',$id))$missing[]='customer_public_id';
    if(!in_array($args['section']??null,['overview','recent_visits','favorites','notes','tags','consents'],true))
        $missing[]='section';
    return $missing;
}
function fm8d18j5b_read(array $args,array $actor):array
{
    if(!fm8d18j5b_can($actor))throw new DomainException('CRM view permission is required.');
    if($missing=fm8d18j5b_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$id=trim($args['customer_public_id']);
    if(!crm_ready($pdo))throw new RuntimeException('Customer CRM is unavailable. Complete upgrade.php first.');
    $customer=crm_customer_row($pdo,$org,$id);
    if((string)$customer['status']!=='active')throw new InvalidArgumentException('The selected customer profile is no longer active.');
    $customerId=(int)$customer['id'];$section=$args['section'];$name=(string)$customer['display_name'];
    $rows=[];$answer='';
    if(in_array($section,['overview','recent_visits','favorites'],true)){
        $metrics=crm_metrics($pdo,$org,$customerId);
        if($section==='overview'){
            $rows=['visits'=>$metrics['visits'],'lifetimeSpend'=>$metrics['lifetimeSpend'],
                'averageCheck'=>$metrics['averageCheck'],'firstVisit'=>$metrics['firstVisit'],
                'lastVisit'=>$metrics['lastVisit']];
            $answer=$name.' has '.(int)$rows['visits'].' recorded paid visit(s). '.
                'Lifetime spend: '.number_format((float)$rows['lifetimeSpend'],2).
                '; average check: '.number_format((float)$rows['averageCheck'],2).'.';
        }elseif($section==='recent_visits'){
            $rows=array_map(static fn(array $r):array=>[
                'date'=>$r['businessDate'],'checkId'=>$r['checkPublicId'],
                'total'=>$r['totalAmount'],'serviceMode'=>$r['serviceMode'],
                'location'=>$r['locationName'],
            ],array_slice((array)$metrics['recentChecks'],0,8));
            $answer='Showing '.count($rows).' most recent paid checks for '.$name.'.';
        }else{
            $rows=array_map(static fn(array $r):array=>[
                'itemName'=>$r['itemName'],'category'=>$r['categoryName'],
                'quantity'=>$r['quantity'],'netSpend'=>$r['netSpend'],
            ],array_slice((array)$metrics['favoriteItems'],0,8));
            $answer='Showing '.count($rows).' historically popular ordered item(s) for '.$name.'.';
        }
    }elseif($section==='notes'){
        $rows=array_map(static fn(array $r):array=>[
            'note'=>mb_substr((string)$r['noteBody'],0,300,'UTF-8'),
            'createdAt'=>$r['createdAt'],'createdBy'=>$r['createdBy'],
        ],array_slice(crm_notes($pdo,$org,$customerId,6),0,6));
        $answer='Showing '.count($rows).' latest internal CRM note(s) for '.$name.'. Notes are private staff records.';
    }elseif($section==='tags'){
        $rows=array_map(static fn(array $r):array=>['name'=>$r['name']],
            array_slice(crm_tags($pdo,$org,$customerId),0,20));
        $answer='Showing '.count($rows).' saved internal CRM tag(s) for '.$name.'.';
    }elseif($section==='consents'){
        $current=crm_current_consents($pdo,$org,$customerId);
        $rows=array_map(static fn(array $r):array=>[
            'channel'=>$r['channel'],'status'=>$r['status'],'capturedAt'=>$r['capturedAt'],
        ],array_values($current));
        $answer='Current saved marketing consent states for '.$name.
            '. Unknown means no recorded consent; it is not permission to contact.';
    }
    return ['ok'=>true,'data'=>['answer'=>$answer,
        'source'=>'Gelato Customer CRM (authenticated exact profile)',
        'readOnly'=>true,'customerPublicId'=>$id,'displayName'=>$name,
        'section'=>$section,'records'=>$rows]];
}
function fm8d18j5b_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j5b_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['read_crm_customer_profile'=>[
        'description'=>'Read ONE selected ACTIVE CRM customer by exact saved public profile ID, and ONE requested section: overview of paid visits, recent_visits, favorites, internal notes, tags, or current email/SMS consent states. Require the exact public ID obtained from a verified customer search or selected profile. Never guess an identity from a name, claim unknown consent is permission, expose contact fields, or perform any write. These are Gelato-local authorized records, not external LLM context.',
        'parameters'=>['type'=>'object','properties'=>[
            'customer_public_id'=>['type'=>'string','description'=>'Exact saved public CRM customer profile ID'],
            'section'=>['type'=>'string','enum'=>['overview','recent_visits','favorites','notes','tags','consents']],
        ],'required'=>['customer_public_id','section'],'additionalProperties'=>false],
        'allowedArguments'=>['customer_public_id','section'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j5b_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j5b_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j5b_read($args,$user),
    ]];
}
