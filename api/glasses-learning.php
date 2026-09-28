<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/glasses-learning.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];

if(!app_has_permission('glasses.view',$user))
    app_json_response(['ok'=>false,'message'=>'AR glasses permission required.'],403);
if(!glasses_learning_ready($pdo))
    app_json_response(['ok'=>false,'message'=>'AR learning ledger migration is not installed. Run upgrade.php.'],503);

try{
    if($_SERVER['REQUEST_METHOD']!=='GET'){
        header('Allow: GET');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }

    $locationId=(int)($_GET['locationId']??0);
    $stationPublicId=trim((string)($_GET['stationPublicId']??''))?:null;
    $days=glasses_learning_window_days((int)($_GET['days']??30));
    $view=trim((string)($_GET['view']??'summary'));

    if($locationId<1)throw new InvalidArgumentException('Choose a restaurant location.');
    if(!operational_location_allowed($pdo,$user,'glasses.view',$locationId))
        throw new DomainException('AR glasses access is not assigned at this restaurant location.');

    if($stationPublicId!==null){
        $q=$pdo->prepare("SELECT COUNT(*) FROM kds_stations WHERE organization_id=? AND location_id=? AND public_id=? AND status='active'");
        $q->execute([$org,$locationId,$stationPublicId]);
        if((int)$q->fetchColumn()!==1)throw new InvalidArgumentException('Active kitchen station was not found at this location.');
    }

    if($view==='summary'){
        app_json_response([
            'ok'=>true,
            'analytics'=>glasses_learning_analytics($pdo,$org,$locationId,$stationPublicId,$days),
            'permissions'=>['view'=>true,'export'=>app_has_permission('glasses.manage',$user)],
        ]);
    }

    if($view==='dataset'){
        if(!app_has_permission('glasses.manage',$user))
            app_json_response(['ok'=>false,'message'=>'AR glasses management permission required to export learning data.'],403);

        $correctedOnly=!isset($_GET['correctedOnly'])||((string)$_GET['correctedOnly']!=='0');
        $limit=max(1,min(20000,(int)($_GET['limit']??5000)));
        $dataset=glasses_learning_dataset($pdo,$org,$locationId,$stationPublicId,$days,$correctedOnly,$limit);

        if((string)($_GET['download']??'')==='1'){
            $stamp=(new DateTimeImmutable())->format('Ymd-His');
            $filename='gelato-ar-learning-'.$locationId.'-'.$stamp.'.jsonl';
            header('Content-Type: application/x-ndjson; charset=utf-8');
            header('Content-Disposition: attachment; filename="'.$filename.'"');
            header('X-Gelato-Dataset-Schema: '.$dataset['schema']);
            header('X-Gelato-Dataset-Hash: '.$dataset['datasetHash']);
            header('X-Content-Type-Options: nosniff');

            echo json_encode([
                'type'=>'dataset_manifest',
                'schema'=>$dataset['schema'],
                'correctedOnly'=>$dataset['correctedOnly'],
                'rowCount'=>$dataset['rowCount'],
                'datasetHash'=>$dataset['datasetHash'],
                'generatedAt'=>(new DateTimeImmutable())->format(DATE_ATOM),
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";

            foreach($dataset['rows'] as $row)
                echo json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            exit;
        }

        app_json_response(['ok'=>true,'dataset'=>$dataset]);
    }

    app_json_response(['ok'=>false,'message'=>'Unsupported learning analytics view.'],422);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[gelato-glasses-learning] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>operational_safe_error($e,'AR learning analytics could not complete the request.')],500);
}
