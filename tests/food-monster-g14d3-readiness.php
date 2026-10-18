<?php
declare(strict_types=1);
function app_role_has_owner_authority(array $u):bool{return ($u['role_slug']??'')==='super_admin';}
require_once __DIR__.'/../includes/food-monster-g14a-pilot.php';
$n=0;
function yes14d3(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);$n++;}
$g=array_keys(fm14a_gates());$items=[];
foreach($g as $key)$items[]=['gate'=>$key,'status'=>'pass'];
$issues=['unresolved'=>0];
$r=fm14d3_readiness($items,true,$issues);
yes14d3($r['operatorReportComplete']===true,'seven reported passes');
yes14d3($r['fieldCertified']===false,'never certified');
yes14d3($r['trainingIncluded']===false&&$r['trainingCertification']===false,'training cannot certify');
yes14d3($r['statusCounts']['pass']===7,'count passing');
yes14d3(count($r['nextChecks'])===0,'nothing pending');
yes14d3(!fm14d3_readiness($items,false,$issues)['operatorReportComplete'],'closed pilot not complete');
yes14d3(!fm14d3_readiness($items,true,['unresolved'=>1])['operatorReportComplete'],'open issue blocks complete');
foreach(['fail','blocked','stale','not_tested'] as $s){
    $changed=$items;$changed[0]['status']=$s;
    $out=fm14d3_readiness($changed,true,$issues);
    yes14d3(!$out['operatorReportComplete']&&$out['statusCounts'][$s]===1,'non-pass '.$s);
    yes14d3($out['nextChecks'][0]['gate']===$g[0],'followup '.$s);
}
yes14d3($r['trainingReviewCommand']==='team training insights','existing scoped command');
$ui=file_get_contents(__DIR__.'/../js/agent-canvas.js');
yes14d3(str_contains($ui,'data.readiness||{}'),'readiness rendered');
yes14d3(str_contains($ui,'my training insights'),'self permission pathway');
yes14d3(str_contains($ui,'team training insights'),'team permission pathway');
echo "FM-G14D3 readiness PASS ($n assertions)\n";
