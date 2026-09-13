<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/service-reservation-protection.php';

$gate=(string)($argv[1]??'');
$mode=(string)($argv[2]??'');
$org=(int)($argv[3]??0);
$location=(int)($argv[4]??0);
$user=(int)($argv[5]??0);
$args=array_slice($argv,6);

$deadline=microtime(true)+10.0;
while($gate!==''&&!is_file($gate)){
    if(microtime(true)>$deadline)throw new RuntimeException('Concurrency gate timed out.');
    usleep(10000);
}

$pdo=app_pdo();
try{
    if($mode==='seat'){
        $result=service_reservation_protection_party_seat($pdo,$org,$location,(string)($args[0]??''),(int)($args[1]??2),null,'concurrency seat',$user);
        echo json_encode(['ok'=>true,'mode'=>$mode,'publicId'=>$result['publicId']??null],JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if($mode==='assign'){
        $reservation=(string)($args[0]??'');$tables=array_values(array_filter(explode(',',(string)($args[1]??''))));
        $result=service_seatability_reservation_assign($pdo,$org,$reservation,$tables,$user);
        echo json_encode(['ok'=>true,'mode'=>$mode,'reservation'=>$reservation,'tables'=>array_column($result['tables']??[],'publicId')],JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if($mode==='combination'){
        $name=(string)($args[0]??'Concurrent combo');$tables=array_values(array_filter(explode(',',(string)($args[1]??''))));
        $result=service_ops_combination_save($pdo,$org,$location,['name'=>$name,'tablePublicIds'=>$tables],$user);
        echo json_encode(['ok'=>true,'mode'=>$mode,'publicId'=>$result['publicId']??null,'tables'=>array_column($result['tables']??[],'publicId')],JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    throw new InvalidArgumentException('Unsupported concurrency worker mode.');
}catch(Throwable $e){
    $payload=['ok'=>false,'mode'=>$mode,'class'=>get_class($e),'message'=>$e->getMessage()];
    @file_put_contents(sys_get_temp_dir().'/gelato-concurrency-errors.log',json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND|LOCK_EX);
    echo json_encode($payload,JSON_THROW_ON_ERROR)."\n";
    exit(2);
}
