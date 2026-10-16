<?php
declare(strict_types=1);
function app_role_has_owner_authority(array $u):bool{return ($u['role_slug']??'')==='super_admin';}
if(!function_exists('mb_strlen')){function mb_strlen(string $s,string $encoding='UTF-8'):int{return strlen($s);}}
require __DIR__.'/../includes/food-monster-high-impact-approval.php';
function check_it(bool $b,string $m):void{if(!$b)throw new RuntimeException($m);echo "PASS $m\n";}
session_start();
$owner=['id'=>1,'organization_id'=>7,'role_slug'=>'super_admin'];
$staff=['id'=>2,'organization_id'=>7,'role_slug'=>'employee'];
try{fm9f_approval_propose($staff,'major_purchase','Buy new oven','thr-1234567890');check_it(false,'staff rejected');}
catch(DomainException){check_it(true,'staff rejected');}
try{fm9f_approval_propose($owner,'arbitrary_tool','Do anything','thr-1234567890');check_it(false,'allowlist');}
catch(InvalidArgumentException){check_it(true,'allowlist');}
$p=fm9f_approval_propose($owner,'major_purchase','Review oven purchase','thr-1234567890');
check_it($p['requiresApproval']&&!$p['executed'],'proposal never executes');
try{fm9f_approval_decide($staff,$p['proposalId'],'approve');check_it(false,'staff decision denied');}
catch(DomainException){check_it(true,'staff decision denied');}
try{fm9f_approval_decide($owner,'gap-incorrect','approve');check_it(false,'wrong token denied');}
catch(InvalidArgumentException){check_it(true,'wrong token denied');}
$d=fm9f_approval_decide($owner,$p['proposalId'],'approve');
check_it($d['decision']==='approve'&&!$d['executed'],'owner approval does not execute tool');
try{fm9f_approval_decide($owner,$p['proposalId'],'approve');check_it(false,'replay blocked');}
catch(InvalidArgumentException){check_it(true,'replay blocked');}
