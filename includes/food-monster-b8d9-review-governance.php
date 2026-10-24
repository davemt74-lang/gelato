<?php
declare(strict_types=1);

/**
 * FM-B8D9 — recorded Vision Lab sample-review and release-governance visibility.
 * No image/media/biometric or model artifact URL is sent to the LLM provider.
 * Sample judgments, dataset freezes, and release approvals remain human-governed.
 */
require_once __DIR__.'/glasses-vision-lab.php';
require_once __DIR__.'/glasses-vision-annotation-qa.php';
require_once __DIR__.'/glasses-v11-model-release-candidates.php';

function fm8d9_observation(string $message,string $source='Gelato Vision Lab review ledger'):array
{
    return ['ok'=>true,'data'=>['answer'=>mb_strcut($message,0,2600,'UTF-8'),
        'source'=>$source]];
}
function fm8d9_read_allowed(array $actor):bool
{
    return app_has_permission('glasses.view',$actor);
}
function fm8d9_governance_allowed(array $actor):bool
{
    return app_has_permission('glasses.view',$actor)&&app_has_permission('glasses.manage',$actor);
}
function fm8d9_review_queue(PDO $pdo,array $actor):array
{
    if(!fm8d9_read_allowed($actor))throw new DomainException('Vision review access denied.');
    if(!glasses_vision_lab_ready($pdo))return fm8d9_observation('Vision review schema is not installed.');
    $samples=glasses_vision_lab_samples($pdo,(int)$actor['organization_id']);
    $counts=['needs_adjudication'=>0,'pending'=>0,'approved'=>0,'rejected'=>0];
    $todo=[];
    foreach($samples as $s){
        $status=(string)($s['reviewStatus']??'');
        if(isset($counts[$status]))$counts[$status]++;
        if(in_array($status,['pending','needs_adjudication'],true))$todo[]=$s;
    }
    $text='Vision sample review queue (up to the latest 250 records): '.
        $counts['needs_adjudication'].' need adjudication, '.
        $counts['pending'].' pending review, '.$counts['approved'].' approved, '.
        $counts['rejected'].' rejected. ';
    if(!$todo)return fm8d9_observation($text.'No unreviewed samples in this recorded window.');
    $lines=[$text,'Review priority (adjudication before pending):'];
    foreach(array_slice($todo,0,12) as $s)
        $lines[]=(string)$s['publicId'].' — '.$s['reviewStatus'].
            '; label '.((string)($s['canonicalLabel']??'')?:'unlabeled').
            '; mission '.((string)($s['missionTitle']??'')?:'unassigned').'.';
    if(count($todo)>12)$lines[]='Additional pending samples exist; only 12 displayed.';
    $lines[]='These records are evidence to review, not automatically approved labels.';
    return fm8d9_observation(implode("\n",$lines));
}
function fm8d9_sample(PDO $pdo,array $actor,array $args):array
{
    if(!fm8d9_read_allowed($actor))throw new DomainException('Vision review access denied.');
    if(!glasses_vision_lab_ready($pdo))return fm8d9_observation('Vision review schema is not installed.');
    $ref=trim((string)($args['sample_public_id']??''));
    if($ref===''||mb_strlen($ref,'UTF-8')>100
        ||!preg_match('/^[a-z0-9_-]+$/D',$ref))throw new InvalidArgumentException('Sample reference is required.');
    try{$s=glasses_vision_lab_sample($pdo,(int)$actor['organization_id'],$ref);}
    catch(InvalidArgumentException){return fm8d9_observation('No matching sample is recorded for this restaurant.');}
    return fm8d9_observation('Vision sample '.$s['publicId'].': review status '.$s['reviewStatus'].
        '; outcome '.((string)($s['reviewOutcome']??'')?:'not decided').
        '; label '.((string)($s['canonicalLabel']??'')?:'not assigned').
        '; mission '.((string)($s['missionTitle']??'')?:'unassigned').
        '; reviewer '.((string)($s['reviewerName']??'')?:'not assigned').
        '; reviewed '.((string)($s['reviewedAt']??'')?:'not yet').
        '. This record is not an automated image assessment. No media or private source URL was retrieved.');
}
function fm8d9_quality(PDO $pdo,array $actor):array
{
    if(!fm8d9_read_allowed($actor))throw new DomainException('Vision QA access denied.');
    if(!glasses_vision_annotation_qa_ready($pdo))
        return fm8d9_observation('Vision annotation QA schema is not installed.');
    $qa=glasses_vision_annotation_qa_analyze($pdo,(int)$actor['organization_id']);
    $s=$qa['summary'];
    $lines=['Vision annotation quality from recorded samples: '.$s['samples'].' samples; '.
        $s['completeSamples'].' complete, '.$s['incompleteSamples'].' incomplete; '.
        $s['boxIssueCount'].' box issues, '.$s['unresolvedDisagreements'].
        ' unresolved disputes; QA score '.$s['qaScore'].'/100.'];
    foreach(array_slice((array)($qa['adjudicationQueue']??[]),0,8) as $r)
        $lines[]='Needs review: '.(string)$r['samplePublicId'].' — '.
            implode(', ',array_slice(array_map('strval',(array)($r['issues']??[])),0,4));
    if(!$s['samples'])$lines[]='No training samples are available. Do not treat the score as validated model quality.';
    $lines[]='This is a recorded annotation-quality estimate, not permission to approve a sample or deploy a model.';
    return fm8d9_observation(implode("\n",$lines),'Gelato Vision annotation QA');
}
function fm8d9_release_candidates(PDO $pdo,array $actor):array
{
    if(!fm8d9_governance_allowed($actor))
        throw new DomainException('Vision model release governance access denied.');
    $catalog=glasses_v11_model_rc_catalog($pdo,(int)$actor['organization_id']);
    if(!($catalog['ready']??false))
        return fm8d9_observation('Model release-candidate governance is not installed.','Gelato V11 release candidate ledger');
    $rows=(array)($catalog['releaseCandidates']??[]);
    if(!$rows)return fm8d9_observation('No model release candidates are recorded for this restaurant.','Gelato V11 release candidate ledger');
    $lines=['Recorded model release candidates (up to 100 recent):'];
    foreach(array_slice($rows,0,12) as $r){
        $model=$r['modelPackage']??[];
        $lines[]=(string)($r['publicId']??'unknown').': '.
            (string)($r['status']??'unknown').'; '.
            (string)($model['detectorName']??'unknown detector').', model '.
            (string)($model['modelVersion']??'unknown version').'.';
    }
    if(count($rows)>12)$lines[]='More release candidates exist; only 12 displayed.';
    $lines[]='Statuses are informational; approval/rejection requires the dedicated governance workflow.';
    return fm8d9_observation(implode("\n",$lines),'Gelato V11 release candidate ledger');
}
function fm8d9_catalogue(array $principal):array
{
    if(!fm8d9_read_allowed($principal))return [];
    $read=static fn(array $actor,array $args):bool=>fm8d9_read_allowed($actor);
    $governance=static fn(array $actor,array $args):bool=>fm8d9_governance_allowed($actor);
    $none=['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false];
    $tools=[
        'get_vision_sample_review_queue'=>[
            'description'=>'Show real pending and disputed Vision Lab training samples, counts, IDs, labels and mission names for this restaurant. Nothing is reviewed or approved by this tool.',
            'parameters'=>$none,'allowedArguments'=>[],'impact'=>'read',
            'authorize'=>$read,'validate'=>static fn(array $args,array $actor):array=>[],
            'execute'=>static fn(array $args,array $actor):array=>fm8d9_review_queue(app_pdo(),$actor),
        ],
        'get_vision_sample_review_details'=>[
            'description'=>'Get the current recorded status, label, mission and reviewer for a Vision Lab training sample by its exact ID. Never retrieve images or media files. Only use sample IDs found in the review queue.',
            'parameters'=>['type'=>'object','properties'=>[
                'sample_public_id'=>['type'=>'string','description'=>'Exact recorded vision sample public ID'],
            ],'required'=>['sample_public_id'],'additionalProperties'=>false],
            'allowedArguments'=>['sample_public_id'],'impact'=>'read',
            'authorize'=>$read,
            'validate'=>static fn(array $args,array $actor):array=>
                trim((string)($args['sample_public_id']??''))===''?['sample_public_id']:[],
            'execute'=>static fn(array $args,array $actor):array=>fm8d9_sample(app_pdo(),$actor,$args),
        ],
        'get_vision_annotation_quality'=>[
            'description'=>'Compute real Vision Lab sample annotation QA quality and disagreement queue from the current tenant data. Advisory statistics, not automatic model validation.',
            'parameters'=>$none,'allowedArguments'=>[],'impact'=>'read',
            'authorize'=>$read,'validate'=>static fn(array $args,array $actor):array=>[],
            'execute'=>static fn(array $args,array $actor):array=>fm8d9_quality(app_pdo(),$actor),
        ],
    ];
    if(fm8d9_governance_allowed($principal))$tools['get_vision_release_candidate_queue']=[
        'description'=>'For authorized Vision Lab managers, list model release candidates, verified statuses and model names and versions. Never execute an approval, rejection, canary or rollout with this read-only tool.',
        'parameters'=>$none,'allowedArguments'=>[],'impact'=>'read',
        'authorize'=>$governance,'validate'=>static fn(array $args,array $actor):array=>[],
        'execute'=>static fn(array $args,array $actor):array=>fm8d9_release_candidates(app_pdo(),$actor),
    ];
    return $tools;
}
