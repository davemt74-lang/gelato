<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-scene.php';

const GLASSES_VISION_STEP_SCHEMA='gelato.vision_recipe_step.v1';
const GLASSES_VISION_STEP_POLICY='gelato.recipe_step_policy.v1';

function glasses_vision_step_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_step_recognitions'");
    $q->execute();
    return (int)$q->fetchColumn()===1 && glasses_vision_scene_ready($pdo);
}

function glasses_vision_step_normalize(string $value): string
{
    $value=mb_strtolower(trim($value),'UTF-8');
    $value=preg_replace('/[^\pL\pN]+/u',' ',$value)??$value;
    return trim(preg_replace('/\s+/u',' ',$value)??$value);
}

function glasses_vision_step_contains_any(string $text,array $terms): bool
{
    foreach($terms as $term)if($term!==''&&str_contains($text,$term))return true;
    return false;
}

function glasses_vision_step_entity_index(array $scene): array
{
    $byKey=[];$byKind=[];$labels=[];
    foreach((array)$scene['entities'] as $entity){
        $key=(string)$entity['entityKey'];$kind=(string)$entity['kind'];
        $byKey[$key]=$entity;$byKind[$kind][]=$entity;
        $labels[$key]=glasses_vision_step_normalize((string)$entity['label']);
    }
    return ['byKey'=>$byKey,'byKind'=>$byKind,'labels'=>$labels];
}

function glasses_vision_step_relationship_index(array $scene): array
{
    $out=[];
    foreach((array)$scene['relationships'] as $r){
        $from=(string)($r['from']??'');$to=(string)($r['to']??'');$type=(string)($r['type']??'');
        if($from===''||$to===''||$type==='')continue;
        $out[$from][$type][$to]=true;
        $out[$to][$type][$from]=true;
    }
    return $out;
}

function glasses_vision_step_related(array $relations,string $a,array $types,?array $targets=null): bool
{
    foreach($types as $type){
        foreach(array_keys((array)($relations[$a][$type]??[])) as $other){
            if($targets===null||isset($targets[$other]))return true;
        }
    }
    return false;
}

function glasses_vision_step_region_present(array $entities,string $regionType,?string $kind=null): bool
{
    foreach($entities as $e){
        if($kind!==null&&(string)$e['kind']!==$kind)continue;
        foreach((array)($e['spatial']['regions']??[]) as $r)
            if((string)($r['regionType']??'')===$regionType)return true;
    }
    return false;
}

function glasses_vision_step_action_cue(string $stepText,array $scene,array $index,array $relations): array
{
    $text=glasses_vision_step_normalize($stepText);
    $tools=(array)($index['byKind']['tool']??[]);$equipment=(array)($index['byKind']['equipment']??[]);
    $products=(array)($index['byKind']['product']??[]);$containers=(array)($index['byKind']['container']??[]);
    $hands=(array)($index['byKind']['hand']??[]);
    $productKeys=[];foreach($products as $e)$productKeys[(string)$e['entityKey']]=true;
    $containerKeys=[];foreach($containers as $e)$containerKeys[(string)$e['entityKey']]=true;
    $handKeys=[];foreach($hands as $e)$handKeys[(string)$e['entityKey']]=true;
    $allTargetKeys=$productKeys+$containerKeys;

    $best=0.0;$cues=[];
    $toolMatch=static function(array $tools,array $terms)use($relations,$allTargetKeys,$handKeys): array {
        $score=0.0;$matched=[];
        foreach($tools as $tool){
            $label=glasses_vision_step_normalize((string)$tool['label']);
            if(!glasses_vision_step_contains_any($label,$terms))continue;
            $key=(string)$tool['entityKey'];$s=.48;
            if(glasses_vision_step_related($relations,$key,['touching','near','on'], $allTargetKeys))$s=.78;
            if(glasses_vision_step_related($relations,$key,['held_by'], $handKeys))$s=max($s,.70);
            $score=max($score,$s);$matched[]=['entityKey'=>$key,'label'=>$tool['label'],'score'=>$s];
        }
        return [$score,$matched];
    };
    $equipmentMatch=static function(array $equipment,array $terms)use($relations,$allTargetKeys): array {
        $score=0.0;$matched=[];
        foreach($equipment as $entity){
            $label=glasses_vision_step_normalize((string)$entity['label']);
            if(!glasses_vision_step_contains_any($label,$terms))continue;
            $key=(string)$entity['entityKey'];$s=.55;
            if(glasses_vision_step_related($relations,$key,['inside','near','touching','on'], $allTargetKeys))$s=.82;
            $score=max($score,$s);$matched[]=['entityKey'=>$key,'label'=>$entity['label'],'score'=>$s];
        }
        return [$score,$matched];
    };

    if(glasses_vision_step_contains_any($text,['slice','cut','chop'])){
        [$score,$matched]=$toolMatch($tools,['knife','cutter','slicer','chopper','pizza cutter']);
        if($score>0){$cues[]=['cue'=>'cutting_tool','matches'=>$matched];$best=max($best,$score);}
    }
    if(glasses_vision_step_contains_any($text,['spread','smear'])){
        [$score,$matched]=$toolMatch($tools,['spatula','spreader','knife','spoodle','spoon']);
        if($score>0){$cues[]=['cue'=>'spreading_tool','matches'=>$matched];$best=max($best,$score);}
    }
    if(glasses_vision_step_contains_any($text,['mix','stir','whisk'])){
        [$score,$matched]=$toolMatch($tools,['spoon','whisk','spatula','spoodle','mixer']);
        if($score>0){$cues[]=['cue'=>'mixing_tool','matches'=>$matched];$best=max($best,$score);}
    }
    if(glasses_vision_step_contains_any($text,['bake','oven'])){
        [$score,$matched]=$equipmentMatch($equipment,['oven']);
        if($score>0){$cues[]=['cue'=>'oven','matches'=>$matched];$best=max($best,$score);}
    }
    if(glasses_vision_step_contains_any($text,['fry','fryer'])){
        [$score,$matched]=$equipmentMatch($equipment,['fryer','fry']);
        if($score>0){$cues[]=['cue'=>'fryer','matches'=>$matched];$best=max($best,$score);}
    }
    if(glasses_vision_step_contains_any($text,['toast','toaster'])){
        [$score,$matched]=$equipmentMatch($equipment,['toaster','oven','grill']);
        if($score>0){$cues[]=['cue'=>'toasting_equipment','matches'=>$matched];$best=max($best,$score);}
    }
    if(glasses_vision_step_contains_any($text,['grill','griddle','cook'])){
        [$score,$matched]=$equipmentMatch($equipment,['grill','griddle','flat top','stove','range']);
        if($score>0){$cues[]=['cue'=>'cooking_equipment','matches'=>$matched];$best=max($best,$score);}
    }
    if(glasses_vision_step_contains_any($text,['plate','plating'])){
        if(glasses_vision_step_region_present((array)$scene['entities'],'plate_surface','product')){
            $best=max($best,.88);$cues[]=['cue'=>'product_on_plate_surface','score'=>.88];
        }else{
            foreach((array)($index['byKind']['surface']??[]) as $surface){
                $label=glasses_vision_step_normalize((string)$surface['label']);
                if(glasses_vision_step_contains_any($label,['plate','plating'])){
                    $key=(string)$surface['entityKey'];
                    if(glasses_vision_step_related($relations,$key,['on','near','touching'],$productKeys)){
                        $best=max($best,.82);$cues[]=['cue'=>'product_near_plate','entityKey'=>$key,'score'=>.82];
                    }
                }
            }
        }
    }
    if(glasses_vision_step_contains_any($text,['handoff','hand off','serve','expo'])){
        if(glasses_vision_step_region_present((array)$scene['entities'],'handoff_surface','product')){
            $best=max($best,.90);$cues[]=['cue'=>'product_on_handoff_surface','score'=>.90];
        }
    }
    if(glasses_vision_step_contains_any($text,['wrap','fold'])){
        foreach($hands as $hand){
            $key=(string)$hand['entityKey'];
            if(glasses_vision_step_related($relations,$key,['touching','near'],$productKeys)){
                $best=max($best,.68);$cues[]=['cue'=>'hand_product_manipulation','entityKey'=>$key,'score'=>.68];
            }
        }
    }
    return ['score'=>round($best,6),'cues'=>$cues];
}

function glasses_vision_step_component_status(array $scene): array
{
    $out=[];
    foreach((array)($scene['context']['recipePlan']['components']??[]) as $component)
        $out[(string)$component['componentKey']]=(string)$component['status'];
    return $out;
}

function glasses_vision_step_previous_component_complete(array $steps,int $order,array $statuses): bool
{
    foreach($steps as $step){
        if((int)$step['order'] >= $order)continue;
        foreach((array)($step['componentKeys']??[]) as $key){
            $status=$statuses[(string)$key]??'pending';
            if(!in_array($status,['confirmed','ignored'],true))return false;
        }
    }
    return true;
}

function glasses_vision_step_score_candidates(array $scene): array
{
    $steps=array_values(array_filter((array)($scene['context']['recipePlan']['declaredSteps']??[]),static fn($s)=>is_array($s)&&trim((string)($s['stepKey']??''))!==''));
    usort($steps,static fn($a,$b)=>(int)$a['order']<=>(int)$b['order']);
    $statuses=glasses_vision_step_component_status($scene);
    $visible=[];foreach((array)$scene['entities'] as $e)
        if((string)$e['kind']==='ingredient'&&$e['componentKey']!==null)$visible[(string)$e['componentKey']]=true;
    $current=(string)($scene['context']['recipePlan']['currentExpectedComponentKey']??'');
    $index=glasses_vision_step_entity_index($scene);$relations=glasses_vision_step_relationship_index($scene);
    $candidates=[];

    foreach($steps as $step){
        $keys=array_values(array_unique(array_map('strval',(array)($step['componentKeys']??[]))));
        $visibleMatches=[];$pending=[];$allComplete=!empty($keys);
        foreach($keys as $key){
            if(isset($visible[$key]))$visibleMatches[]=$key;
            $status=$statuses[$key]??'pending';
            if(!in_array($status,['confirmed','ignored'],true)){$allComplete=false;$pending[]=$key;}
        }
        $previousComplete=glasses_vision_step_previous_component_complete($steps,(int)$step['order'],$statuses);
        $action=glasses_vision_step_action_cue((string)$step['text'],$scene,$index,$relations);
        $componentScore=0.0;
        if($keys){
            $componentScore=.55*(count($visibleMatches)/count($keys));
            if($current!==''&&in_array($current,$keys,true))$componentScore+=.25;
            if($pending)$componentScore+=.05;
            if($allComplete)$componentScore=min($componentScore,.15);
        }
        $sequenceScore=$previousComplete?.10:-.18;
        $score=max(0.0,min(1.0,max($componentScore,(float)$action['score'])+$sequenceScore));
        if($allComplete)$score=min($score,.20);
        $reasons=[];
        if($visibleMatches)$reasons[]='visible_recipe_components';
        if($current!==''&&in_array($current,$keys,true))$reasons[]='current_expected_component';
        if($action['score']>0)$reasons[]='scene_action_cue';
        if($previousComplete)$reasons[]='prior_component_steps_complete'; else $reasons[]='prior_component_step_pending';
        if($allComplete)$reasons[]='step_components_already_complete';
        $candidates[]=[
          'stepKey'=>(string)$step['stepKey'],'order'=>(int)$step['order'],'text'=>(string)$step['text'],'componentKeys'=>$keys,
          'visibleComponentKeys'=>$visibleMatches,'pendingComponentKeys'=>$pending,'actionCues'=>$action['cues'],
          'previousComponentStepsComplete'=>$previousComplete,'componentsAlreadyComplete'=>$allComplete,
          'score'=>round($score,6),'reasons'=>$reasons
        ];
    }
    usort($candidates,static fn($a,$b)=>($b['score']<=>$a['score'])?:($a['order']<=>$b['order']));
    return ['steps'=>$steps,'candidates'=>$candidates,'visibleComponentKeys'=>array_keys($visible),'componentStatuses'=>$statuses,'currentExpectedComponentKey'=>$current];
}

function glasses_vision_step_recognize(PDO $pdo,int $org,string $scenePublicId): array
{
    if(!glasses_vision_step_ready($pdo))throw new RuntimeException('Vision Lab V9 step-recognition migration is not installed.');
    $scene=glasses_vision_scene_row($pdo,$org,trim($scenePublicId));
    if(!glasses_vision_scene_verify($pdo,$org,$scene['publicId'])['passed'])
        throw new InvalidArgumentException('Recipe-step recognition requires an intact V9 scene.');

    $scored=glasses_vision_step_score_candidates($scene);
    $steps=$scored['steps'];$candidates=$scored['candidates'];
    $state='insufficient';$recognized=null;$next=null;$confidence=0.0;$margin=0.0;
    if($steps&&$candidates){
        $best=$candidates[0];$second=$candidates[1]??null;
        $confidence=(float)$best['score'];$margin=round($confidence-(float)($second['score']??0),6);
        if($confidence>=.65&&$margin>=.12){
            $state='recognized';$recognized=$best;
            foreach($steps as $step)if((int)$step['order']>(int)$best['order']){$next=$step;break;}
        }elseif($confidence>=.50)$state='ambiguous';
    }

    $evidence=[
      'schema'=>GLASSES_VISION_STEP_SCHEMA,'policy'=>GLASSES_VISION_STEP_POLICY,
      'scene'=>['publicId'=>$scene['publicId'],'sceneHash'=>$scene['sceneHash'],'sourceFingerprint'=>$scene['sourceFingerprint']],
      'buildSessionPublicId'=>$scene['buildSessionPublicId'],
      'buildContextHash'=>$scene['context']['buildContextHash']??null,
      'buildDefinition'=>$scene['context']['buildSession']['buildDefinition']??null,
      'currentExpectedComponentKey'=>$scored['currentExpectedComponentKey'],
      'visibleComponentKeys'=>$scored['visibleComponentKeys'],'componentStatuses'=>$scored['componentStatuses'],
      'entityHashes'=>array_map(static fn($e)=>[$e['entityKey'],$e['entityHash']],$scene['entities']),
      'relationships'=>$scene['relationships'],
    ];
    $result=[
      'state'=>$state,
      'recognizedStep'=>$recognized?['stepKey'=>$recognized['stepKey'],'order'=>$recognized['order'],'text'=>$recognized['text']]:null,
      'nextStep'=>$next?['stepKey'=>(string)$next['stepKey'],'order'=>(int)$next['order'],'text'=>(string)$next['text']]:null,
      'confidence'=>round($confidence,6),'margin'=>round($margin,6),
      'advisoryOnly'=>true,'changesBuildState'=>false,'changesKdsState'=>false,
    ];
    $material=['evidence'=>$evidence,'candidates'=>$candidates,'result'=>$result];
    $recognitionHash=hash('sha256',glasses_vision_training_release_json($material));
    $recognitionKey=hash('sha256',GLASSES_VISION_STEP_POLICY.'|'.$scene['sceneHash']);

    $q=$pdo->prepare("SELECT public_id,recognition_hash FROM glasses_vision_step_recognitions WHERE organization_id=? AND recognition_key=? LIMIT 1");
    $q->execute([$org,$recognitionKey]);$existing=$q->fetch();
    if($existing){
        if(!hash_equals((string)$existing['recognition_hash'],$recognitionHash))
            throw new InvalidArgumentException('Recipe-step recognition identity conflicts with different immutable evidence.');
        return glasses_vision_step_row($pdo,$org,(string)$existing['public_id']);
    }

    return glasses_transaction($pdo,function()use($pdo,$org,$scene,$recognitionKey,$state,$recognized,$next,$confidence,$margin,$evidence,$candidates,$recognitionHash):array{
        $sq=$pdo->prepare("SELECT id FROM glasses_vision_scene_snapshots WHERE organization_id=? AND public_id=? LIMIT 1");
        $sq->execute([$org,$scene['publicId']]);$sceneId=(int)$sq->fetchColumn();
        $bq=$pdo->prepare("SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=? LIMIT 1");
        $bq->execute([$org,$scene['buildSessionPublicId']]);$buildId=(int)$bq->fetchColumn();
        $public=glasses_public_id('vision-step');
        $pdo->prepare("INSERT INTO glasses_vision_step_recognitions
          (organization_id,public_id,scene_snapshot_id,build_session_id,recognition_key,state,recognized_step_key,recognized_step_order,recognized_step_text,
           next_step_key,next_step_order,next_step_text,confidence,margin,evidence_json,candidates_json,recognition_hash)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([$org,$public,$sceneId,$buildId,$recognitionKey,$state,
            $recognized['stepKey']??null,$recognized['order']??null,$recognized['text']??null,
            $next['stepKey']??null,$next['order']??null,$next['text']??null,$confidence,$margin,
            glasses_vision_training_release_json($evidence),glasses_vision_training_release_json($candidates),$recognitionHash]);
        glasses_vision_lineage_edge($pdo,$org,'vision_scene',$scene['publicId'],$scene['sceneHash'],'recognized_recipe_step_as','recipe_step_recognition',$public,$recognitionHash,
          ['state'=>$state,'recognizedStepKey'=>$recognized['stepKey']??null,'nextStepKey'=>$next['stepKey']??null],null);
        return glasses_vision_step_row($pdo,$org,$public);
    });
}

function glasses_vision_step_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT r.*,s.public_id scene_public_id,s.scene_hash,b.public_id build_public_id
      FROM glasses_vision_step_recognitions r
      JOIN glasses_vision_scene_snapshots s ON s.id=r.scene_snapshot_id AND s.organization_id=r.organization_id
      JOIN glasses_build_sessions b ON b.id=r.build_session_id AND b.organization_id=r.organization_id
      WHERE r.organization_id=? AND r.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$r=$q->fetch();
    if(!$r)throw new InvalidArgumentException('Recipe-step recognition was not found.');
    return [
      'schema'=>GLASSES_VISION_STEP_SCHEMA,'publicId'=>$r['public_id'],'scenePublicId'=>$r['scene_public_id'],'sceneHash'=>$r['scene_hash'],
      'buildSessionPublicId'=>$r['build_public_id'],'state'=>$r['state'],
      'recognizedStep'=>$r['recognized_step_key']!==null?['stepKey'=>$r['recognized_step_key'],'order'=>(int)$r['recognized_step_order'],'text'=>$r['recognized_step_text']]:null,
      'nextStep'=>$r['next_step_key']!==null?['stepKey'=>$r['next_step_key'],'order'=>(int)$r['next_step_order'],'text'=>$r['next_step_text']]:null,
      'confidence'=>(float)$r['confidence'],'margin'=>(float)$r['margin'],
      'evidence'=>json_decode((string)$r['evidence_json'],true)?:[],'candidates'=>json_decode((string)$r['candidates_json'],true)?:[],
      'recognitionHash'=>$r['recognition_hash'],'createdAt'=>$r['created_at']
    ];
}

function glasses_vision_step_verify(PDO $pdo,int $org,string $publicId): array
{
    $r=glasses_vision_step_row($pdo,$org,$publicId);
    $result=[
      'state'=>$r['state'],'recognizedStep'=>$r['recognizedStep'],'nextStep'=>$r['nextStep'],
      'confidence'=>$r['confidence'],'margin'=>$r['margin'],'advisoryOnly'=>true,'changesBuildState'=>false,'changesKdsState'=>false,
    ];
    $hash=hash('sha256',glasses_vision_training_release_json(['evidence'=>$r['evidence'],'candidates'=>$r['candidates'],'result'=>$result]));
    $scene=glasses_vision_scene_row($pdo,$org,$r['scenePublicId']);
    $passed=hash_equals($r['recognitionHash'],$hash)
      &&glasses_vision_scene_verify($pdo,$org,$r['scenePublicId'])['passed']
      &&hash_equals((string)($r['evidence']['scene']['sceneHash']??''),(string)$scene['sceneHash'])
      &&hash_equals((string)($r['evidence']['scene']['sourceFingerprint']??''),(string)$scene['sourceFingerprint']);
    return ['passed'=>$passed,'recognitionHash'=>$r['recognitionHash'],'recomputedRecognitionHash'=>$hash];
}

function glasses_vision_step_recent(PDO $pdo,int $org,int $limit=50): array
{
    if(!glasses_vision_step_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_VISION_STEP_SCHEMA,'recognitions'=>[]];
    $limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT public_id FROM glasses_vision_step_recognitions WHERE organization_id=? ORDER BY id DESC LIMIT {$limit}");
    $q->execute([$org]);$rows=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public)$rows[]=glasses_vision_step_row($pdo,$org,(string)$public);
    return ['ready'=>true,'schema'=>GLASSES_VISION_STEP_SCHEMA,'recognitions'=>$rows];
}
