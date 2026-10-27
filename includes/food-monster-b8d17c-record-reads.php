<?php
declare(strict_types=1);

/**
 * FM-B8D17C: model-selected domain reads through the existing authenticated
 * Gelato read broker. Never accept SQL, record IDs, organization IDs or URLs
 * from the model, and never send result records back to an LLM provider.
 */
require_once __DIR__.'/food-monster-unified-knowledge.php';

function fm8d17c_catalogue(array $principal,array $context=[]):array
{
    $voice=($context['channel']??'')==='voice';
    $approved=($context['privateVoiceApproved']??false)===true;
    $allowed=[];
    foreach(fmuk_read_topics() as $topic)
        if(fmuk_allowed($principal,$topic,$voice,$approved))$allowed[]=$topic;
    if($allowed===[])return [];
    $question=trim((string)($context['userQuestion']??''));
    return ['read_gelato_records'=>[
        'description'=>'Read actual authorized Gelato restaurant records for this signed-in person, choosing ONE domain. Use for questions about inventory, tasks, purchasing, customers/CRM, equipment, sales, staff, live orders, reservations, training, or restaurant Knowledge Center, when no more specific registered read tool is suitable. The server binds the saved chat question and organization. Never invent facts or claim a read succeeded without an observation. Available domains: '.implode(', ',$allowed),
        'parameters'=>['type'=>'object','properties'=>[
            'topic'=>['type'=>'string','enum'=>$allowed,
                'description'=>'One authorized Gelato data domain. No SQL or user-supplied query.'],
        ],'required'=>['topic'],'additionalProperties'=>false],
        'allowedArguments'=>['topic'],'impact'=>'read',
        'authorize'=>static fn(array $fresh,array $args):bool=>
            (int)($fresh['id']??0)>0&&(int)($fresh['organization_id']??0)>0
            &&is_string($args['topic']??null)
            &&in_array($args['topic'],fmuk_read_topics(),true)
            &&fmuk_allowed($fresh,$args['topic'],$voice,$approved),
        'validate'=>static fn(array $args,array $fresh):array=>
            is_string($args['topic']??null)&&in_array($args['topic'],fmuk_read_topics(),true)
                ?[]:['topic'],
        'execute'=>static function(array $args,array $fresh)use($question,$voice,$approved):array{
            $topic=(string)($args['topic']??'');
            if(!in_array($topic,fmuk_read_topics(),true)
               ||!fmuk_allowed($fresh,$topic,$voice,$approved))
                return ['ok'=>false,'data'=>[]];
            if($question===''||mb_strlen($question,'UTF-8')>4500)
                return ['ok'=>true,'data'=>['answer'=>'Please send your request through this authenticated Agent Chat so I can verify the exact question.','source'=>'Gelato canonical chat required']];
            $evidence=fmuk_evidence(app_pdo(),$fresh,$question,$voice,$approved,[$topic]);
            $full=fmuk_reply_from_evidence($evidence,$question);
            $sources=array_slice((array)($evidence['sources']??[]),0,3);
            $source=$sources?implode('; ',array_map('strval',$sources)):
                'Gelato authenticated '.$topic.' record read';
            $source=mb_substr($source,0,250,'UTF-8');
            // The executor's observation budget is 3 KB. Always make it
            // explicit when a longer record summary was shortened.
            $limit=1800;
            do{
                $short=mb_strlen($full,'UTF-8')>$limit;
                $answer=mb_substr($full,0,$limit,'UTF-8')
                    .($short?' [Brief summary; additional matching records may be omitted.]':'');
                $data=['answer'=>$answer,'source'=>$source,
                    'readOnly'=>true,'evidenceTopic'=>$topic,'truncated'=>$short];
                $encoded=json_encode($data,JSON_INVALID_UTF8_SUBSTITUTE);
                if(is_string($encoded)&&strlen($encoded)<=2850)break;
                $limit=(int)floor($limit*0.7);
            }while($limit>100);
            return ['ok'=>true,'data'=>$data];
        },
    ]];
}
