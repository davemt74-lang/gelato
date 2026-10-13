<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/glasses-order-photo-stages.php';
function ok(bool $test,string $message):void{if(!$test){fwrite(STDERR,"FAIL stage photo: $message\n");exit(1);}}
$input=['stage'=>'pre_oven','action'=>'bypass','kdsItemPublicId'=>'kds-111','expectedRevision'=>0,
  'bypassReason'=>'Camera unavailable for this ticket'];
$v=glasses_order_photo_validate('pre_oven','bypass',$input);
ok($v['stage']==='pre_oven'&&$v['reason']==='Camera unavailable for this ticket','valid step 1 bypass');
ok(glasses_order_photo_validate('post_oven','bypass',$input)['stage']==='post_oven','valid step 2 bypass');
$confirm=['stage'=>'post_oven','action'=>'confirm','kdsItemPublicId'=>'kds-111',
 'expectedRevision'=>0,'photoLibraryId'=>345];
ok(glasses_order_photo_validate('post_oven','confirm',$confirm)['photoLibraryId']===345,'photo confirmation');
$reopen=['stage'=>'pre_oven','action'=>'reopen','kdsItemPublicId'=>'kds-111','expectedRevision'=>2];
ok(glasses_order_photo_validate('pre_oven','reopen',$reopen)['expectedRevision']===2,'revision preserved');
$reject=static function(string $stage,string $action,array $payload):void{
  try{glasses_order_photo_validate($stage,$action,$payload);}
  catch(InvalidArgumentException){return;}
  throw new RuntimeException('Expected stage photo validation to reject unsafe input');
};
$reject('other','bypass',$input);
$reject('pre_oven','auto_confirm',$input);
$reject('pre_oven','bypass',array_replace($input,['bypassReason'=>'']));
$reject('pre_oven','bypass',array_replace($input,['bypassReason'=>'No']));
$reject('post_oven','confirm',array_replace($confirm,['photoLibraryId'=>null]));
$reject('post_oven','confirm',array_replace($confirm,['expectedRevision'=>-1]));
$reject('post_oven','confirm',array_replace($confirm,['kdsItemPublicId'=>'']));
echo "section259-pre-post-oven-bypass-validation=ok\n";
