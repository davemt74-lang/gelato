<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}
require_once __DIR__.'/../includes/glasses-vision-training-release.php';

/** Small valid PNGs avoid an optional GD dependency in the contract runners. */
function vision_contract_source_png(int $index): string
{
    $row="\x00".str_repeat(chr(90+$index%100).chr(100).chr(80),320);
    $chunk=static fn(string $type,string $bytes):string=>pack('N',strlen($bytes)).$type.$bytes.pack('N',crc32($type.$bytes));
    return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',320,240,8,2,0,0,0)).$chunk('IDAT',gzcompress(str_repeat($row,240),9)).$chunk('IEND','');
}

/**
 * Populate a draft fixture dataset with real retained, reviewed source media,
 * then use the normal freeze and ZIP builder. Callers separately seed the
 * qualification attestations required by their experiment/lineage contracts.
 */
function vision_contract_retained_release(PDO $pdo,int $org,int $actor,string $datasetPublic,int $count=3,int $classCount=1): array
{
    if($count<3||$count>90||$classCount<1||$classCount>3||$count<$classCount*3)
        throw new InvalidArgumentException('The retained-release fixture needs every class in all three splits.');
    $labels=['ingredient','cheese','pepperoni'];
    $lastCompleteGroup=intdiv($count,$classCount)-1;
    for($i=0;$i<$count;$i++){
        // Every capture group carries every class, so the real split retains class coverage.
        $group=min(intdiv($i,$classCount),$lastCompleteGroup);
        $media=glasses_vision_training_media_store($pdo,$org,[
            'imageBase64'=>base64_encode(vision_contract_source_png($i)),
            'consentBasis'=>'training_media_opt_in','retentionDays'=>1,
            'annotations'=>[['label'=>$labels[$i%$classCount],'bbox'=>['x'=>.1,'y'=>.1,'width'=>.4,'height'=>.4]]],
            'brightnessMean'=>.5,'contrastMean'=>.3,'blurScore'=>120,
            'captureGroup'=>$datasetPublic.'-source-'.$group,
        ],$actor);
        glasses_vision_lab_review_sample($pdo,$org,$media['samplePublicId'],['decision'=>'approve','notes'=>'Reviewed contract fixture source.'],$actor);
        glasses_vision_lab_add_dataset_sample($pdo,$org,$datasetPublic,$media['samplePublicId'],'train');
    }
    $plan=glasses_vision_curation_create_split_plan($pdo,$org,$datasetPublic,['seed'=>74,'trainRatio'=>.34,'valRatio'=>.33,'testRatio'=>.33],$actor);
    glasses_vision_curation_apply_split_plan($pdo,$org,$plan['publicId'],$actor);
    $dataset=glasses_vision_lab_freeze_dataset($pdo,$org,$datasetPublic,$actor);
    $release=glasses_vision_training_release_build($pdo,$org,$datasetPublic,'yolo11n_640',$actor);
    $row=glasses_vision_training_release_row($pdo,$org,$release['publicId']);
    $artifact=glasses_vision_training_release_artifact($pdo,$org,$release['publicId']);
    if($dataset['rowCount']!==$count||$dataset['classCount']!==$classCount||$release['manifest']['itemCount']!==$count||$artifact['bytes']<=0)
        throw new RuntimeException('The retained-release fixture did not preserve its reviewed source set.');
    $coverage=glasses_vision_lab_dataset_coverage($pdo,$org,$datasetPublic);
    if(min($coverage['splits'])<$classCount||($release['manifest']['splitPlan']['planHash']??null)!==$plan['planHash'])
        throw new RuntimeException('The retained release must bind to the applied plan and retain evidence in all splits.');
    return ['dataset'=>$dataset,'release'=>$release,'row'=>$row];
}
