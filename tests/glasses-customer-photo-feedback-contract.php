<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/glasses-customer-photo-feedback.php';
function expectFeedback(bool $ok,string $what):void {
 if(!$ok){fwrite(STDERR,"FAIL Section262: $what\n");exit(1);}
}
$v=glasses_customer_photo_feedback_validate(5,"  Excellent photo  ");
expectFeedback($v['rating']===5&&$v['comment']==='Excellent photo','valid review trimmed');
for($n of [-1,0,6,20]){
 try{glasses_customer_photo_feedback_validate($n,'bad');throw new RuntimeException('accepted invalid rating');}
 catch(InvalidArgumentException){}
}
try{
 glasses_customer_photo_feedback_validate(3,str_repeat('x',501));
 throw new RuntimeException('accepted oversized comment');
}catch(InvalidArgumentException){}
$photos=[
 ['kdsItemPublicId'=>'A','stage'=>'pre_oven','itemName'=>'Pepperoni','shareId'=>1],
 ['kdsItemPublicId'=>'B','stage'=>'post_oven','itemName'=>'Pepperoni','shareId'=>2],
 ['kdsItemPublicId'=>'A','stage'=>'post_oven','itemName'=>'Pepperoni','shareId'=>3],
 ['kdsItemPublicId'=>'C','stage'=>'other','itemName'=>'Pizza','shareId'=>4],
];
$group=glasses_customer_photo_group_by_item($photos);
expectFeedback(count($group)===2,'different order items never grouped together');
expectFeedback($group['A']['pre_oven']['shareId']===1,'correct before image');
expectFeedback($group['A']['post_oven']['shareId']===3,'correct after image');
expectFeedback($group['B']['pre_oven']===null,'missing before remains clearly empty');
expectFeedback($group['B']['post_oven']['shareId']===2,'other pizza after stays separate');
expectFeedback(glasses_customer_photo_stage_camera_valid('pre_oven',2),'stage2');
expectFeedback(glasses_customer_photo_stage_camera_valid('post_oven',3),'stage3');
expectFeedback(!glasses_customer_photo_stage_camera_valid('pre_oven',3),'wrong stage camera forbidden');
echo "section262-private-paired-gallery-and-feedback=ok\n";
