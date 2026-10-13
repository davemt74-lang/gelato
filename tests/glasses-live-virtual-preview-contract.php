<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $user):bool {
    return in_array('*',$user['permissions']??[],true) || in_array($permission,$user['permissions']??[],true);
}
require __DIR__.'/../includes/glasses-simulator.php';
final class VirtualPreviewPDO extends PDO {
    public function __construct() {}
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        return new VirtualPreviewStatement($query);
    }
}
final class VirtualPreviewStatement extends PDOStatement {
    private array $args=[];
    public function __construct(private string $sql) {}
    public function execute(?array $params=null):bool {$this->args=$params??[];return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args):array {
        if(str_contains($this->sql,'FROM locations'))return [['id'=>3,'name'=>'Main Location']];
        if(str_contains($this->sql,'FROM kds_stations'))return [['public_id'=>'pizza-line','name'=>'Pizza Line']];
        return [];
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {
        if(str_contains($this->sql,'FROM kds_stations'))return ['id'=>7,'public_id'=>'pizza-line','name'=>'Pizza Line'];
        return false;
    }
}
function previewAssert(bool $result,string $msg):void{if(!$result)throw new RuntimeException($msg);}
$db=new VirtualPreviewPDO();
$owner=['organization_id'=>1,'permissions'=>['*']];
$list=glasses_simulator_virtual_devices($db,$owner);
previewAssert(count($list)===2,'one location creates All stations and Pizza Line displays');
previewAssert($list[0]['publicId']==='preview:3:all'&&$list[0]['virtualPreview']===true,'default is hardware-free all-stations display');
previewAssert($list[1]['publicId']==='preview:3:pizza-line','per-station browser preview available');
previewAssert($list[0]['simulatorWritable']===false&&$list[1]['simulatorWritable']===false,'virtual previews are never writable');
$preview=glasses_simulator_preview_device($db,$owner,'preview:3:all',false);
previewAssert($preview['id']===0&&$preview['station_public_id']===null,'unrouted all-stations projection uses no device record');
try {
    glasses_simulator_preview_device($db,$owner,'preview:3:all',true);
    throw new RuntimeException('Virtual preview incorrectly writable');
} catch(DomainException $e) {
    previewAssert(str_contains($e->getMessage(),'read-only'),'write attempts must fail clearly');
}
try {
    glasses_simulator_preview_device($db,$owner,'preview:4:all',false);
    throw new RuntimeException('Forged location accepted');
} catch(DomainException $e) {
    previewAssert(str_contains($e->getMessage(),'no longer available'),'unknown preview location denied');
}
$denied=['organization_id'=>1,'permissions'=>[]];
previewAssert(glasses_simulator_virtual_devices($db,$denied)===[],'unauthorized viewers receive no virtual stations');
$js=(string)file_get_contents(__DIR__.'/../assets/js/glasses-web-simulator.js');
$php=(string)file_get_contents(__DIR__.'/../glasses-simulator.php');
$api=(string)file_get_contents(__DIR__.'/../api/glasses-simulator.php');
previewAssert(str_contains($js,"const state={mode:'live'"),'simulator must boot Live instead of Mock');
previewAssert(str_contains($php,'<option value="live" selected>Live Gelato</option>'),'mode select must match live default');
previewAssert(str_contains($js,'const LIVE_DEVICE_KEY='),'device preference persists');
previewAssert(str_contains($js,'state.devices.find(x=>x.virtualPreview&&x.stationPublicId===null)'),'All stations auto selected');
previewAssert(str_contains($js,'if(state.device?.virtualPreview)return false;'),'recovery cannot start a build on a virtual display');
previewAssert(str_contains($js,'startLiveStationSync();'),'automatic live synchronization starts at load');
previewAssert(str_contains($js,'simControlAudit'),'disabled controls audit present');
previewAssert(str_contains($php,'id="simLiveGuide"')&&str_contains($php,'id="simControlAudit"'),'visible live/readiness status present');
previewAssert(str_contains($api,'glasses_simulator_preview_work($pdo,$device)'),'GET station updates support virtual display');
previewAssert(str_contains((string)file_get_contents(__DIR__.'/../includes/glasses-simulator.php'),'kds_production_board($pdo,$org,$locationId,$stationId,false)'),'virtual preview reads real KDS board');
previewAssert(!str_contains($js,'const state={mode:\'mock\''),'cannot silently regress to Mock boot');
echo "glasses-live-virtual-preview=ok\n";
