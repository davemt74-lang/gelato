<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-training-media.php';

function glasses_vision_curation_ready(PDO $pdo): bool {
    foreach(['glasses_vision_dataset_curation_events','glasses_vision_dataset_split_plans','glasses_vision_dataset_split_assignments'] as $t){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$t]);
        if((int)$q->fetchColumn()!==1)return false;
    }
    return glasses_vision_training_media_ready($pdo);
}

function glasses_vision_curation_dataset(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array {
    $q=$pdo->prepare("SELECT * FROM glasses_vision_dataset_versions WHERE organization_id=? AND public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));
    $q->execute([$org,$publicId]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Vision dataset was not found.');return $row;
}

function glasses_vision_curation_decide(PDO $pdo,int $org,string $datasetPublic,string $samplePublic,string $decision,string $reason,int $actor): array {
    if(!in_array($decision,['include','exclude'],true))throw new InvalidArgumentException('Curation decision must be include or exclude.');
    $reason=mb_substr(trim($reason),0,1000);if($reason==='')throw new InvalidArgumentException('Curation rationale is required.');
    $dataset=glasses_vision_curation_dataset($pdo,$org,$datasetPublic);if($dataset['status']!=='draft')throw new InvalidArgumentException('Frozen datasets are immutable.');
    $q=$pdo->prepare("SELECT s.id FROM glasses_vision_training_samples s JOIN glasses_vision_dataset_items di ON di.sample_id=s.id AND di.organization_id=s.organization_id WHERE s.organization_id=? AND s.public_id=? AND di.dataset_id=? LIMIT 1");
    $q->execute([$org,$samplePublic,(int)$dataset['id']]);$sampleId=(int)$q->fetchColumn();if(!$sampleId)throw new InvalidArgumentException('Sample is not part of this dataset.');
    $pdo->prepare("INSERT INTO glasses_vision_dataset_curation_events (organization_id,dataset_id,sample_id,decision,reason,source,actor_user_id) VALUES (?,?,?,?,?,'manual',?)")
      ->execute([$org,(int)$dataset['id'],$sampleId,$decision,$reason,$actor]);
    return ['datasetPublicId'=>$datasetPublic,'samplePublicId'=>$samplePublic,'decision'=>$decision,'reason'=>$reason];
}

function glasses_vision_curation_rows(PDO $pdo,int $org,string $datasetPublic): array {
    $dataset=glasses_vision_curation_dataset($pdo,$org,$datasetPublic);
    $q=$pdo->prepare("SELECT s.public_id,s.canonical_label,s.review_status,s.source_type,s.source_reference,s.operator_user_id,u.display_name operator_name,
      di.split_name,di.created_at dataset_added_at,
      bs.public_id build_public_id,k.location_id,l.name location_name,k.station_id,ks.name station_name,pci.menu_item_id,mi.name menu_item_name,
      (SELECT GROUP_CONCAT(DISTINCT m.capture_group ORDER BY m.capture_group SEPARATOR '||') FROM glasses_vision_training_media m WHERE m.organization_id=s.organization_id AND m.sample_id=s.id AND m.status='active' AND m.capture_group IS NOT NULL) capture_groups,
      (SELECT GROUP_CONCAT(DISTINCT m.device_id ORDER BY m.device_id SEPARATOR '||') FROM glasses_vision_training_media m WHERE m.organization_id=s.organization_id AND m.sample_id=s.id AND m.status='active' AND m.device_id IS NOT NULL) device_ids,
      ce.decision curation_decision,ce.reason curation_reason,ce.created_at curation_at
      FROM glasses_vision_dataset_items di
      JOIN glasses_vision_training_samples s ON s.id=di.sample_id AND s.organization_id=di.organization_id
      LEFT JOIN users u ON u.id=s.operator_user_id
      LEFT JOIN glasses_build_observations o ON o.id=s.observation_id
      LEFT JOIN glasses_build_sessions bs ON bs.id=o.build_session_id
      LEFT JOIN kds_order_items k ON k.id=bs.kds_order_item_id
      LEFT JOIN locations l ON l.id=k.location_id
      LEFT JOIN kds_stations ks ON ks.id=k.station_id
      LEFT JOIN pos_check_items pci ON pci.id=k.pos_check_item_id
      LEFT JOIN menu_items mi ON mi.id=pci.menu_item_id
      LEFT JOIN glasses_vision_dataset_curation_events ce ON ce.id=(SELECT MAX(c2.id) FROM glasses_vision_dataset_curation_events c2 WHERE c2.organization_id=di.organization_id AND c2.dataset_id=di.dataset_id AND c2.sample_id=di.sample_id)
      WHERE di.organization_id=? AND di.dataset_id=? ORDER BY s.created_at,s.id");
    $q->execute([$org,(int)$dataset['id']]);
    return array_map(static function($r):array{
        return [
            'samplePublicId'=>$r['public_id'],'label'=>$r['canonical_label'],'reviewStatus'=>$r['review_status'],'sourceType'=>$r['source_type'],'sourceReference'=>$r['source_reference'],
            'operatorUserId'=>$r['operator_user_id']!==null?(int)$r['operator_user_id']:null,'operatorName'=>$r['operator_name'],'split'=>$r['split_name'],
            'buildPublicId'=>$r['build_public_id'],'locationId'=>$r['location_id']!==null?(int)$r['location_id']:null,'locationName'=>$r['location_name'],
            'stationId'=>$r['station_id']!==null?(int)$r['station_id']:null,'stationName'=>$r['station_name'],
            'menuItemId'=>$r['menu_item_id']!==null?(int)$r['menu_item_id']:null,'menuItemName'=>$r['menu_item_name'],
            'captureGroups'=>$r['capture_groups']?explode('||',(string)$r['capture_groups']):[],'deviceIds'=>$r['device_ids']?array_map('intval',explode('||',(string)$r['device_ids'])):[],
            'curationDecision'=>$r['curation_decision']?:'include','curationReason'=>$r['curation_reason'],'curationAt'=>$r['curation_at'],
        ];
    },$q->fetchAll());
}

function glasses_vision_curation_union_find(array $rows,array $policy): array {
    $n=count($rows);$parent=range(0,max(0,$n-1));
    $find=function($x)use(&$parent,&$find){return $parent[$x]===$x?$x:($parent[$x]=$find($parent[$x]));};
    $union=function($a,$b)use(&$parent,$find){$ra=$find($a);$rb=$find($b);if($ra!==$rb)$parent[$rb]=$ra;};
    $maps=[];
    foreach($rows as $i=>$row){
        $tokens=[];
        if(($policy['captureGroup']??true)===true)foreach($row['captureGroups'] as $v)$tokens[]='capture:'.$v;
        if(($policy['buildSession']??true)===true && $row['buildPublicId'])$tokens[]='build:'.$row['buildPublicId'];
        if(($policy['device']??false)===true)foreach(($row['deviceIds']??[]) as $v)$tokens[]='device:'.$v;
        if(($policy['operator']??false)===true && $row['operatorUserId'])$tokens[]='operator:'.$row['operatorUserId'];
        if(($policy['location']??false)===true && $row['locationId'])$tokens[]='location:'.$row['locationId'];
        if(($policy['station']??false)===true && $row['stationId'])$tokens[]='station:'.$row['stationId'];
        if(($policy['menuItem']??false)===true && $row['menuItemId'])$tokens[]='menu:'.$row['menuItemId'];
        if(!$tokens)$tokens[]='sample:'.$row['samplePublicId'];
        foreach($tokens as $token){if(isset($maps[$token]))$union($i,$maps[$token]);else$maps[$token]=$i;}
    }
    $groups=[];
    foreach($rows as $i=>$row){$root=$find($i);$groups[$root]['rows'][]=$row;}
    foreach($groups as &$group){
        $ids=array_column($group['rows'],'samplePublicId');sort($ids,SORT_STRING);
        $group['label']=implode('|',$ids);$group['key']=hash('sha256',$group['label']);$group['count']=count($ids);
    }unset($group);
    return array_values($groups);
}

function glasses_vision_curation_split_groups(array $groups,int $seed,float $train,float $val,float $test): array {
    if(abs(($train+$val+$test)-1.0)>.000001||min($train,$val,$test)<=0)throw new InvalidArgumentException('Split ratios must be positive and sum to 1.');
    if(count($groups)<3)throw new InvalidArgumentException('At least three independent lineage groups are required for train/val/test.');
    usort($groups,static fn($a,$b)=>strcmp(hash('sha256',$seed.'|'.$a['key']),hash('sha256',$seed.'|'.$b['key'])));
    $total=array_sum(array_column($groups,'count'));$targets=['train'=>$total*$train,'val'=>$total*$val,'test'=>$total*$test];$counts=['train'=>0,'val'=>0,'test'=>0];$assigned=[];
    foreach($groups as $idx=>$group){
        if($idx<3)$split=['train','val','test'][$idx];
        else{
            $scores=[];foreach($counts as $name=>$count)$scores[$name]=$targets[$name]-$count;
            arsort($scores,SORT_NUMERIC);$split=(string)array_key_first($scores);
        }
        $counts[$split]+=$group['count'];$group['split']=$split;$assigned[]=$group;
    }
    return ['groups'=>$assigned,'counts'=>$counts,'targets'=>$targets];
}

function glasses_vision_curation_create_split_plan(PDO $pdo,int $org,string $datasetPublic,array $input,int $actor): array {
    $dataset=glasses_vision_curation_dataset($pdo,$org,$datasetPublic);if($dataset['status']!=='draft')throw new InvalidArgumentException('Frozen datasets are immutable.');
    $rows=array_values(array_filter(glasses_vision_curation_rows($pdo,$org,$datasetPublic),static fn($r)=>$r['curationDecision']!=='exclude'&&$r['reviewStatus']==='approved'));
    if(count($rows)<3)throw new InvalidArgumentException('At least three included approved samples are required.');
    $policy=[
        'captureGroup'=>($input['captureGroup']??true)!==false,'buildSession'=>($input['buildSession']??true)!==false,
        'device'=>($input['device']??false)===true,'operator'=>($input['operator']??false)===true,'location'=>($input['location']??false)===true,
        'station'=>($input['station']??false)===true,'menuItem'=>($input['menuItem']??false)===true,
    ];
    $seed=max(0,(int)($input['seed']??74));$train=(float)($input['trainRatio']??.70);$val=(float)($input['valRatio']??.15);$test=(float)($input['testRatio']??.15);
    $split=glasses_vision_curation_split_groups(glasses_vision_curation_union_find($rows,$policy),$seed,$train,$val,$test);
    $assignments=[];foreach($split['groups'] as $group)foreach($group['rows'] as $row)$assignments[]=['samplePublicId'=>$row['samplePublicId'],'groupKey'=>$group['key'],'groupLabel'=>$group['label'],'split'=>$group['split'],'grouping'=>['captureGroups'=>$row['captureGroups'],'deviceIds'=>$row['deviceIds']??[],'buildPublicId'=>$row['buildPublicId'],'operatorUserId'=>$row['operatorUserId'],'locationId'=>$row['locationId'],'stationId'=>$row['stationId'],'menuItemId'=>$row['menuItemId']]];
    usort($assignments,static fn($a,$b)=>strcmp($a['samplePublicId'],$b['samplePublicId']));
    $manifest=['schema'=>'gelato.vision_group_split.v1','datasetPublicId'=>$datasetPublic,'seed'=>$seed,'ratios'=>['train'=>$train,'val'=>$val,'test'=>$test],'policy'=>$policy,'counts'=>$split['counts'],'groupCount'=>count($split['groups']),'assignments'=>$assignments];
    $hash=hash('sha256',json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$public=glasses_public_id('vision-split');
    return glasses_transaction($pdo,function()use($pdo,$org,$dataset,$public,$seed,$train,$val,$test,$policy,$manifest,$hash,$actor,$assignments):array{
        $pdo->prepare("INSERT INTO glasses_vision_dataset_split_plans (organization_id,public_id,dataset_id,seed,train_ratio,val_ratio,test_ratio,policy_json,manifest_json,plan_hash,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([$org,$public,(int)$dataset['id'],$seed,$train,$val,$test,json_encode($policy),json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$hash,$actor]);
        $planId=(int)$pdo->lastInsertId();$sq=$pdo->prepare("SELECT id FROM glasses_vision_training_samples WHERE organization_id=? AND public_id=? LIMIT 1");
        $iq=$pdo->prepare("INSERT INTO glasses_vision_dataset_split_assignments (organization_id,split_plan_id,sample_id,group_key,group_label,split_name,grouping_json) VALUES (?,?,?,?,?,?,?)");
        foreach($assignments as $a){$sq->execute([$org,$a['samplePublicId']]);$sid=(int)$sq->fetchColumn();$iq->execute([$org,$planId,$sid,$a['groupKey'],$a['groupLabel'],$a['split'],json_encode($a['grouping'],JSON_UNESCAPED_SLASHES)]);}
        return ['publicId'=>$public,'status'=>'draft','planHash'=>$hash,'manifest'=>$manifest];
    });
}

function glasses_vision_curation_apply_split_plan(PDO $pdo,int $org,string $planPublic,int $actor): array {
    return glasses_transaction($pdo,function()use($pdo,$org,$planPublic,$actor):array{
        $q=$pdo->prepare("SELECT p.*,d.public_id dataset_public_id,d.status dataset_status FROM glasses_vision_dataset_split_plans p JOIN glasses_vision_dataset_versions d ON d.id=p.dataset_id WHERE p.organization_id=? AND p.public_id=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$planPublic]);$plan=$q->fetch();if(!$plan)throw new InvalidArgumentException('Split plan was not found.');if($plan['dataset_status']!=='draft')throw new InvalidArgumentException('Frozen datasets are immutable.');
        if($plan['status']==='applied')return ['publicId'=>$planPublic,'status'=>'applied','datasetPublicId'=>$plan['dataset_public_id']];
        $pdo->prepare("DELETE di FROM glasses_vision_dataset_items di JOIN glasses_vision_dataset_curation_events ce ON ce.id=(SELECT MAX(c2.id) FROM glasses_vision_dataset_curation_events c2 WHERE c2.organization_id=di.organization_id AND c2.dataset_id=di.dataset_id AND c2.sample_id=di.sample_id) WHERE di.organization_id=? AND di.dataset_id=? AND ce.decision='exclude'")
          ->execute([$org,(int)$plan['dataset_id']]);
        $pdo->prepare("UPDATE glasses_vision_dataset_items di JOIN glasses_vision_dataset_split_assignments a ON a.sample_id=di.sample_id AND a.organization_id=di.organization_id SET di.split_name=a.split_name WHERE di.organization_id=? AND di.dataset_id=? AND a.split_plan_id=?")
          ->execute([$org,(int)$plan['dataset_id'],(int)$plan['id']]);
        $pdo->prepare("UPDATE glasses_vision_dataset_split_plans SET status='applied',applied_by=?,applied_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$actor,$org,(int)$plan['id']]);
        return ['publicId'=>$planPublic,'status'=>'applied','datasetPublicId'=>$plan['dataset_public_id'],'coverage'=>glasses_vision_lab_dataset_coverage($pdo,$org,(string)$plan['dataset_public_id'])];
    });
}

function glasses_vision_curation_split_plans(PDO $pdo,int $org,string $datasetPublic): array {
    $dataset=glasses_vision_curation_dataset($pdo,$org,$datasetPublic);
    $q=$pdo->prepare("SELECT public_id,status,seed,train_ratio,val_ratio,test_ratio,policy_json,manifest_json,plan_hash,created_at,applied_at FROM glasses_vision_dataset_split_plans WHERE organization_id=? AND dataset_id=? ORDER BY id DESC LIMIT 50");
    $q->execute([$org,(int)$dataset['id']]);
    return array_map(static fn($r)=>['publicId'=>$r['public_id'],'status'=>$r['status'],'seed'=>(int)$r['seed'],'ratios'=>['train'=>(float)$r['train_ratio'],'val'=>(float)$r['val_ratio'],'test'=>(float)$r['test_ratio']],'policy'=>json_decode((string)$r['policy_json'],true)?:[],'manifest'=>json_decode((string)$r['manifest_json'],true)?:[],'planHash'=>$r['plan_hash'],'createdAt'=>$r['created_at'],'appliedAt'=>$r['applied_at']],$q->fetchAll());
}

function glasses_vision_curation_freeze_guard(PDO $pdo,int $org,string $datasetPublic): void {
    $dataset=glasses_vision_curation_dataset($pdo,$org,$datasetPublic);if($dataset['status']==='frozen')return;
    $q=$pdo->prepare("SELECT COUNT(*) FROM glasses_vision_dataset_split_plans WHERE organization_id=? AND dataset_id=? AND status='applied'");
    $q->execute([$org,(int)$dataset['id']]);if((int)$q->fetchColumn()<1)throw new InvalidArgumentException('Dataset freeze blocked: apply a governed group-aware split plan first.');
}

function glasses_vision_curation_catalog(PDO $pdo,int $org): array {
    if(!glasses_vision_curation_ready($pdo))return ['ready'=>false];
    return ['ready'=>true,'schema'=>'gelato.vision_curation.v1'];
}
