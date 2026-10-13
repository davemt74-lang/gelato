<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/glasses-camera-locations.php';
function expectCamera(bool $ok,string $msg):void{if(!$ok){fwrite(STDERR,"FAIL CAMERA SETUP: ".$msg."\n");exit(1);}}
foreach(range(1,5) as $slot){
  $def=glasses_camera_location_default(7,$slot);
  expectCamera($def['slot']===$slot && $def['locationId']===7,'default camera location');
  expectCamera($def['enabled']===false,'camera must default off');
  expectCamera($def['zone']['width']===.8,'reasonable default capture area');
}
$base=['displayName'=>'Pizza oven camera','role'=>'pre_oven','enabled'=>true,
  'rotation'=>90,'zone'=>['x'=>.1,'y'=>.2,'width'=>.7,'height'=>.6],
  'stationPublicId'=>null,'expectedRevision'=>0];
$v=glasses_camera_location_validate(2,$base);
expectCamera($v['enabled']===true&&$v['zone']['width']===.7,'valid camera setup');
$reject=static function(array $payload,int $slot=2):void{
  try{glasses_camera_location_validate($slot,$payload);}
  catch(InvalidArgumentException){return;}
  throw new RuntimeException('Invalid configuration was accepted.');
};
$reject($base,6);
$reject($base,0);
$reject(array_replace($base,['enabled'=>1]));
$reject(array_replace($base,['role'=>'anything']));
$reject(array_replace($base,['displayName'=>'']));
$reject(array_replace($base,['rotation'=>45]));
$reject(array_replace($base,['expectedRevision'=>-1]));
$reject(array_replace($base,['zone'=>['x'=>.9,'y'=>.2,'width'=>.2,'height'=>.6]]));
$reject(array_replace($base,['zone'=>['x'=>.1,'y'=>.2,'width'=>.001,'height'=>.6]]));
$reject(array_replace($base,['zone'=>['x'=>NAN,'y'=>.2,'width'=>.7,'height'=>.6]]));
echo "section257-camera-config-validation=ok\n";
