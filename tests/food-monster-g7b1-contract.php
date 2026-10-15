<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/food-monster-skill-registry.php';
$n=0;
function verify(bool $value,string $message):void {global $n;if(!$value){fwrite(STDERR,"FAIL: $message\n");exit(1);}++$n;}
$allowed=['inventory.view'=>true,'inventory.agent'=>true,'schedule.view'=>true];
$check=static fn(string $permission,array $user):bool=>!empty($user['permissions'][$permission]);
$user=['permissions'=>$allowed];
$skills=fm_g7b_skills_for_user($user,$check);
verify(count($skills)===1,'Only fully authorized skill disclosed');
verify($skills[0]['id']==='inventory.review','Inventory review allowed');
verify(!isset($skills[0]['route']),'Internal route not disclosed');
verify(fm_g7b_resolve_skill('inventory.review',$user,$check)!==null,'Known authorized ID resolved');
verify(fm_g7b_resolve_skill('scheduling.review',$user,$check)===null,'Partial permission denied');
verify(fm_g7b_resolve_skill('purchasing.propose',$user,$check)===null,'Write proposal denied');
verify(fm_g7b_resolve_skill('../../admin',$user,$check)===null,'Arbitrary path denied');
verify(fm_g7b_resolve_skill('inventory.review',['permissions'=>[]],$check)===null,'Revocation immediately effective');
foreach(fm_g7b_skill_catalog() as $id=>$skill){
    verify(in_array($skill['effect'],['read','propose'],true),'No execution effects in B1');
    verify(str_starts_with($skill['route'],'api/')&&!str_contains($skill['route'],'..'),'Fixed internal route');
    verify($skill['effect']==='read'||$skill['approval']==='destination_required','Proposal requires destination approval');
}
echo "FM-G7B1 skill registry: PASS ($n checks)\n";
