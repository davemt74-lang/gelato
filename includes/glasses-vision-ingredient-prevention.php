<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-step-recognition.php';

const GLASSES_VISION_INGREDIENT_GUARD_SCHEMA='gelato.vision_ingredient_guard.v1';
const GLASSES_VISION_INGREDIENT_GUARD_POLICY='gelato.vision_ingredient_guard_policy.v1';

function glasses_vision_ingredient_guard_policy(): array
{
    return [
      'schema'=>GLASSES_VISION_INGREDIENT_GUARD_POLICY,
      'mappedIngredientMin'=>0.72,
      'unknownIngredientMin'=>0.82,
      'interactionTypes'=>['touching','on','inside','held_by','near'],
      'stopInteractionTypes'=>['touching','on','inside'],
      'duplicateConfirmedMin'=>0.88,
    ];
}

function glasses_vision_ingredient_guard_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_ingredient_preventions'");
    $q->execute();
    return (int)$q->fetchColumn()===1 && glasses_vision_step_ready($pdo);
}

function glasses_vision_ingredient_guard_recipe_components(array $scene): array
{
    $out=[];
    foreach((array)($scene['context']['recipePlan']['components']??[]) as $component){
        $key=(string)($component['componentKey']??'');
        if($key==='')continue;
        $out[$key]=[
          'componentKey'=>$key,
          'displayName'=>(string)($component['displayName']??$key),
          'status'=>(string)($component['status']??'waiting'),
          'sortOrder'=>(int)($component['sortOrder']??0),
          'expectedQuantity'=>(float)($component['expectedQuantity']??0),
          'detectedQuantity'=>(float)($component['detectedQuantity']??0),
          'optional'=>(bool)($component['optional']??false),
        ];
    }
    return $out;
}

function glasses_vision_ingredient_guard_interactions(array $scene): array
{
    $relations=[];
    foreach((array)($scene['relationships']??[]) as $r){
        $from=(string)($r['from']??'');$to=(string)($r['to']??'');$type=(string)($r['type']??'');
        if($from===''||$to===''||$type==='')continue;
        $relations[$from][$type][$to]=true;
        $relations[$to][$type][$from]=true;
    }
    return $relations;
}

function glasses_vision_ingredient_guard_targets(array $scene): array
{
    $targets=[];
    foreach((array)($scene['entities']??[]) as $e){
        if(in_array((string)($e['kind']??''),['product','container'],true))$targets[(string)$e['entityKey']]=true;
    }
    return $targets;
}

function glasses_vision_ingredient_guard_relation_types(array $relations,string $entityKey,array $targets): array
{
    $types=[];
    foreach((array)($relations[$entityKey]??[]) as $type=>$others){
        foreach(array_keys((array)$others) as $other)if(isset($targets[$other])){$types[]=(string)$type;break;}
    }
    sort($types);
    return array_values(array_unique($types));
}

function glasses_vision_ingredient_guard_assess_scene(array $scene): array
{
    $policy=glasses_vision_ingredient_guard_policy();
    $components=glasses_vision_ingredient_guard_recipe_components($scene);
    $currentKey=(string)($scene['context']['recipePlan']['currentExpectedComponentKey']??'');
    $current=$currentKey!==''?($components[$currentKey]??null):null;
    $relations=glasses_vision_ingredient_guard_interactions($scene);
    $targets=glasses_vision_ingredient_guard_targets($scene);
    $visibleByComponent=[];$unknown=[];$ingredientEntities=[];

    foreach((array)($scene['entities']??[]) as $e){
        if((string)($e['kind']??'')!=='ingredient')continue;
        $confidence=(float)($e['confidence']??0);
        $componentKey=$e['componentKey']===null?null:(string)$e['componentKey'];
        $entityKey=(string)($e['entityKey']??'');
        $interactionTypes=glasses_vision_ingredient_guard_relation_types($relations,$entityKey,$targets);
        $record=[
          'entityKey'=>$entityKey,'label'=>(string)($e['label']??''),'componentKey'=>$componentKey,
          'confidence'=>round($confidence,6),'interactionTypes'=>$interactionTypes,
          'interacting'=>count($interactionTypes)>0,
        ];
        $ingredientEntities[]=$record;
        if($componentKey!==null&&$componentKey!==''&&$confidence>=(float)$policy['mappedIngredientMin']){
            $visibleByComponent[$componentKey][]=$record;
        }elseif(($componentKey===null||$componentKey==='')&&$confidence>=(float)$policy['unknownIngredientMin']){
            $unknown[]=$record;
        }
    }

    $risks=[];$expectedVisible=$currentKey!==''&&isset($visibleByComponent[$currentKey]);
    $addRisk=static function(array &$risks,string $type,string $severity,string $message,array $detail=[]): void {
        $risks[]=['type'=>$type,'severity'=>$severity,'message'=>$message,'detail'=>$detail];
    };

    if($current!==null&&!$expectedVisible&&!$current['optional']&&!in_array($current['status'],['confirmed','ignored'],true)){
        $addRisk($risks,'missing_expected','warning','Expected ingredient is not visible.',[
          'componentKey'=>$currentKey,'displayName'=>$current['displayName'],'sortOrder'=>$current['sortOrder']
        ]);
    }

    foreach($visibleByComponent as $componentKey=>$entities){
        $component=$components[$componentKey]??null;
        if($component===null){
            $addRisk($risks,'unexpected_component','stop','Visible mapped ingredient is not part of the canonical recipe.',[
              'componentKey'=>$componentKey,'entities'=>$entities
            ]);
            continue;
        }
        if($current!==null&&$componentKey!==$currentKey&&!in_array($component['status'],['confirmed','ignored'],true)){
            $isLater=$component['sortOrder']>$current['sortOrder'];
            $strong=false;foreach($entities as $e)if(array_intersect($e['interactionTypes'],$policy['stopInteractionTypes'])){$strong=true;break;}
            $addRisk($risks,$isLater?'premature_component':'wrong_component',$strong?'stop':'warning',
              $isLater?'A later recipe ingredient is present before the expected ingredient.':'A different pending recipe ingredient is present instead of the expected ingredient.',[
                'expectedComponentKey'=>$currentKey,'visibleComponentKey'=>$componentKey,'displayName'=>$component['displayName'],
                'expectedSortOrder'=>$current['sortOrder'],'visibleSortOrder'=>$component['sortOrder'],'entities'=>$entities
              ]);
        }
        if(in_array($component['status'],['confirmed','ignored'],true)){
            foreach($entities as $e){
                if((float)$e['confidence']<(float)$policy['duplicateConfirmedMin'])continue;
                if(!array_intersect($e['interactionTypes'],$policy['stopInteractionTypes']))continue;
                $addRisk($risks,'duplicate_confirmed_component','stop','An already-accounted ingredient appears to be added again.',[
                  'componentKey'=>$componentKey,'displayName'=>$component['displayName'],'entity'=>$e
                ]);
            }
        }
    }

    foreach($unknown as $entity){
        if(!$entity['interacting'])continue;
        $strong=count(array_intersect($entity['interactionTypes'],$policy['stopInteractionTypes']))>0;
        $addRisk($risks,'unknown_ingredient',$strong?'stop':'warning','An unrecognized ingredient is near or interacting with the product.',[
          'entity'=>$entity
        ]);
    }

    $stopCount=0;$warningCount=0;
    foreach($risks as $risk){
        if($risk['severity']==='stop')$stopCount++;
        elseif($risk['severity']==='warning')$warningCount++;
    }
    if($stopCount>0)$state='stop';
    elseif($warningCount>0)$state='warning';
    elseif($current===null&&!$ingredientEntities)$state='insufficient';
    else $state='clear';

    return [
      'schema'=>GLASSES_VISION_INGREDIENT_GUARD_SCHEMA,
      'policy'=>$policy,
      'state'=>$state,
      'currentComponent'=>$current,
      'currentComponentKey'=>$currentKey!==''?$currentKey:null,
      'expectedVisible'=>$expectedVisible,
      'visibleComponentKeys'=>array_values(array_keys($visibleByComponent)),
      'ingredientEntities'=>$ingredientEntities,
      'risks'=>$risks,
      'stopCount'=>$stopCount,
      'warningCount'=>$warningCount,
      'advisoryOnly'=>true,
      'blocksBuildState'=>false,
      'changesBuildState'=>false,
      'changesKdsState'=>false,
      'confirmsComponents'=>false,
    ];
}

function glasses_vision_ingredient_guard_assess(PDO $pdo,int $org,string $scenePublicId): array
{
    if(!glasses_vision_ingredient_guard_ready($pdo))throw new RuntimeException('Vision Lab V9 ingredient-prevention migration is not installed.');
    $scene=glasses_vision_scene_row($pdo,$org,trim($scenePublicId));
    if(!glasses_vision_scene_verify($pdo,$org,$scene['publicId'])['passed'])
        throw new InvalidArgumentException('Ingredient prevention requires an intact V9 scene.');

    $build=glasses_build_payload($pdo,$org,(string)$scene['buildSessionPublicId']);
    if((string)$build['status']!=='active')throw new InvalidArgumentException('Ingredient prevention requires an active build session.');
    $sceneDefinition=(string)($scene['context']['buildSession']['buildDefinition']['publicId']??'');
    $liveDefinition=(string)($build['buildDefinition']['publicId']??'');
    if($sceneDefinition===''||$liveDefinition===''||!hash_equals($sceneDefinition,$liveDefinition))
        throw new InvalidArgumentException('Ingredient prevention requires the same canonical build definition captured by the scene.');
    $sceneBuildContextHash=(string)($scene['context']['buildContextHash']??'');
    $liveBuildContextHash=glasses_vision_step_build_context_hash($build);
    if($sceneBuildContextHash===''||!hash_equals($sceneBuildContextHash,$liveBuildContextHash))
        throw new InvalidArgumentException('Ingredient prevention requires a scene captured from the current canonical build state.');

    $assessment=glasses_vision_ingredient_guard_assess_scene($scene);
    $evidence=[
      'schema'=>GLASSES_VISION_INGREDIENT_GUARD_SCHEMA,
      'policy'=>$assessment['policy'],
      'scene'=>['publicId'=>$scene['publicId'],'sceneHash'=>$scene['sceneHash'],'sourceFingerprint'=>$scene['sourceFingerprint']],
      'buildSessionPublicId'=>$scene['buildSessionPublicId'],
      'buildContextHash'=>$sceneBuildContextHash,
      'liveBuildContextHash'=>$liveBuildContextHash,
      'buildDefinition'=>$scene['context']['buildSession']['buildDefinition']??null,
      'recipePlan'=>$scene['context']['recipePlan']??null,
      'visibleComponentKeys'=>$assessment['visibleComponentKeys'],
      'entityHashes'=>array_map(static fn($e)=>[$e['entityKey'],$e['entityHash']],(array)$scene['entities']),
      'relationships'=>$scene['relationships'],
    ];
    $result=$assessment;
    unset($result['policy'],$result['schema'],$result['ingredientEntities']);
    $material=['evidence'=>$evidence,'result'=>$result,'risks'=>$assessment['risks']];
    $assessmentHash=hash('sha256',glasses_vision_training_release_json($material));
    $assessmentKey=hash('sha256',glasses_vision_training_release_json([
      'schema'=>GLASSES_VISION_INGREDIENT_GUARD_SCHEMA,
      'policy'=>$assessment['policy'],
      'sceneHash'=>$scene['sceneHash']
    ]));

    return glasses_transaction($pdo,function()use($pdo,$org,$scene,$assessment,$assessmentKey,$assessmentHash,$evidence,$result):array{
        $q=$pdo->prepare("SELECT public_id,assessment_hash FROM glasses_vision_ingredient_preventions WHERE organization_id=? AND assessment_key=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$assessmentKey]);$existing=$q->fetch();
        if($existing){
            if(!hash_equals((string)$existing['assessment_hash'],$assessmentHash))
                throw new InvalidArgumentException('Ingredient-prevention identity conflicts with different immutable evidence.');
            return glasses_vision_ingredient_guard_row($pdo,$org,(string)$existing['public_id']);
        }
        $sq=$pdo->prepare("SELECT id FROM glasses_vision_scene_snapshots WHERE organization_id=? AND public_id=? LIMIT 1");
        $sq->execute([$org,$scene['publicId']]);$sceneId=(int)$sq->fetchColumn();
        $bq=$pdo->prepare("SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=? LIMIT 1");
        $bq->execute([$org,$scene['buildSessionPublicId']]);$buildId=(int)$bq->fetchColumn();
        $public=glasses_public_id('vision-ingredient-guard');
        $pdo->prepare("INSERT INTO glasses_vision_ingredient_preventions
          (organization_id,public_id,scene_snapshot_id,build_session_id,assessment_key,state,current_component_key,expected_visible,stop_count,warning_count,evidence_json,result_json,risks_json,assessment_hash)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([$org,$public,$sceneId,$buildId,$assessmentKey,$assessment['state'],$assessment['currentComponentKey'],
            $assessment['expectedVisible']?1:0,$assessment['stopCount'],$assessment['warningCount'],
            glasses_vision_training_release_json($evidence),glasses_vision_training_release_json($result),
            glasses_vision_training_release_json($assessment['risks']),$assessmentHash]);
        glasses_vision_lineage_edge($pdo,$org,'vision_scene',$scene['publicId'],$scene['sceneHash'],'assessed_ingredient_risk_as',
          'ingredient_prevention',$public,$assessmentHash,
          ['state'=>$assessment['state'],'currentComponentKey'=>$assessment['currentComponentKey'],'stopCount'=>$assessment['stopCount'],'warningCount'=>$assessment['warningCount']],null);
        return glasses_vision_ingredient_guard_row($pdo,$org,$public);
    });
}

function glasses_vision_ingredient_guard_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT g.*,s.public_id scene_public_id,s.scene_hash,b.public_id build_public_id
      FROM glasses_vision_ingredient_preventions g
      JOIN glasses_vision_scene_snapshots s ON s.id=g.scene_snapshot_id AND s.organization_id=g.organization_id
      JOIN glasses_build_sessions b ON b.id=g.build_session_id AND b.organization_id=g.organization_id
      WHERE g.organization_id=? AND g.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$r=$q->fetch();
    if(!$r)throw new InvalidArgumentException('Ingredient-prevention assessment was not found.');
    $evidence=json_decode((string)$r['evidence_json'],true)?:[];
    $result=json_decode((string)$r['result_json'],true)?:[];
    $risks=json_decode((string)$r['risks_json'],true)?:[];
    return [
      'publicId'=>(string)$r['public_id'],'scenePublicId'=>(string)$r['scene_public_id'],'sceneHash'=>(string)$r['scene_hash'],
      'buildSessionPublicId'=>(string)$r['build_public_id'],'state'=>(string)$r['state'],
      'currentComponentKey'=>$r['current_component_key']!==null?(string)$r['current_component_key']:null,
      'expectedVisible'=>(bool)$r['expected_visible'],'stopCount'=>(int)$r['stop_count'],'warningCount'=>(int)$r['warning_count'],
      'evidence'=>$evidence,'result'=>$result,'risks'=>$risks,'assessmentHash'=>(string)$r['assessment_hash'],
      'advisoryOnly'=>true,'blocksBuildState'=>false,'changesBuildState'=>false,'changesKdsState'=>false,'confirmsComponents'=>false,
      'createdAt'=>(string)$r['created_at'],
    ];
}

function glasses_vision_ingredient_guard_verify(PDO $pdo,int $org,string $publicId): array
{
    $row=glasses_vision_ingredient_guard_row($pdo,$org,$publicId);
    $material=['evidence'=>$row['evidence'],'result'=>$row['result'],'risks'=>$row['risks']];
    $calculated=hash('sha256',glasses_vision_training_release_json($material));
    return ['passed'=>hash_equals($row['assessmentHash'],$calculated),'publicId'=>$row['publicId'],'assessmentHash'=>$row['assessmentHash'],'calculatedHash'=>$calculated];
}

function glasses_vision_ingredient_guard_recent(PDO $pdo,int $org,int $limit=50): array
{
    $limit=max(1,min(100,$limit));
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_ingredient_preventions WHERE organization_id=? ORDER BY id DESC LIMIT ".$limit);
    $q->execute([$org]);$out=[];
    foreach($q->fetchAll() as $r)$out[]=glasses_vision_ingredient_guard_row($pdo,$org,(string)$r['public_id']);
    return $out;
}
