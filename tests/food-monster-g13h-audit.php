<?php
declare(strict_types=1);
function app_has_permission(string $p,array $u):bool{return in_array($p,$u['permissions']??[],true);}
function app_role_has_owner_authority(array $u):bool{return ($u['role_slug']??'')==='super_admin';}
require_once __DIR__.'/../includes/food-monster-g13h-unified-audit.php';
require_once __DIR__.'/../includes/food-monster-g13g-procedure-history.php';
$n=0;
function ok13h(bool $ok,string $what):void {global $n;if(!$ok)throw new RuntimeException('FM-G13H FAIL '.$what);$n++;}
$before='Stretch dough using the approved written preparation steps.';
$after='Stretch dough evenly using the approved station procedure and bake as directed.';
$event=[
 'public_id'=>'msg-'.str_repeat('a',20),'role'=>'system','person_id'=>8,
 'created_at'=>'2026-10-17 12:00:00',
 'structured'=>[
  'collection'=>'fm13h_procedure_revision','source'=>'gelato_server',
  'orgId'=>4,'actorId'=>8,'itemId'=>91,'name'=>'House Pie',
  'origin'=>'menu_manager','proposalId'=>null,'before'=>$before,'after'=>$after,
  'beforeHash'=>hash('sha256',$before),'afterHash'=>hash('sha256',$after),
  'beforeVersion'=>4,'afterVersion'=>5
 ]];
$normalized=fm13h_normalize($event,4,2);
ok13h($normalized!==null,'canonical menu manager revision accepted');
ok13h($normalized['origin']==='menu_manager'&&$normalized['actorId']===8,'actual actor and origin preserved');
ok13h($normalized['ownerId']===2,'owner retrieving history can draft without borrowing editor authority');
ok13h($normalized['versionId']==='ver-'.str_repeat('a',20),'revision id tied to event id');
ok13h($normalized['sourceVersion']===4&&$normalized['afterVersion']===5,'native menu versions retained');
ok13h($normalized['before']===$before&&$normalized['after']===$after,'exact before-after source recorded');
$invalid=$event;$invalid['person_id']=2;
ok13h(fm13h_normalize($invalid,4,2)===null,'actor mismatch rejected');
ok13h(fm13h_normalize($event,5,2)===null,'tenant mismatch rejected');
$invalid=$event;$invalid['role']='user';
ok13h(fm13h_normalize($invalid,4,2)===null,'client/user forged record denied');
$invalid=$event;$invalid['structured']['source']='browser';
ok13h(fm13h_normalize($invalid,4,2)===null,'foreign source denied');
$invalid=$event;$invalid['structured']['beforeHash']=str_repeat('f',64);
ok13h(fm13h_normalize($invalid,4,2)===null,'tampered historical text rejected');
$invalid=$event;$invalid['structured']['afterVersion']=4;
ok13h(fm13h_normalize($invalid,4,2)===null,'nonadvancing native version rejected');
$invalid=$event;$invalid['structured']['after']=$before;$invalid['structured']['afterHash']=hash('sha256',$before);
ok13h(fm13h_normalize($invalid,4,2)===null,'unchanged procedure excluded');
$invalid=$event;$invalid['public_id']='forged';
ok13h(fm13h_normalize($invalid,4,2)===null,'noncanonical event identifier denied');
$proposal=['id'=>'prp-'.str_repeat('b',20),'status'=>'published','orgId'=>4,
 'ownerId'=>2,'itemId'=>91,'itemName'=>'House Pie','createdAt'=>10,'publishedAt'=>20,
 'sourceHash'=>hash('sha256',$before),'original'=>$before,'replacement'=>$after];
$legacy=[[
 'versionId'=>fm13g_version_id($proposal),'proposalId'=>$proposal['id'],
 'publishedAt'=>20,'itemId'=>91,'before'=>$before,'after'=>$after,
 'beforeHash'=>hash('sha256',$before),'afterHash'=>hash('sha256',$after),
 'orgId'=>4,'ownerId'=>2
]];
$one=$event;$one['structured']['origin']='food_monster';
$one['structured']['proposalId']=$proposal['id'];
$unified=fm13h_unified($legacy,[$one],4,2);
ok13h(count($unified)===1,'dual-logged Food Monster publication deduplicated by proposal id');
ok13h($unified[0]['versionId']==='ver-'.str_repeat('a',20),'native event wins over legacy copy');
$unified=fm13h_unified($legacy,[$event],4,2);
ok13h(count($unified)===2,'Menu Manager and historical Food Monster publications both visible');
ok13h($unified[1]['origin']==='food_monster_legacy','legacy source correctly labeled');
$rollback=fm13g_rollback_proposal($normalized,['id'=>91,'name'=>'House Pie',
  'preparation_notes'=>$after,'version'=>5],4,2,100,'prp-'.str_repeat('c',20));
ok13h($rollback!==null&&$rollback['replacement']===$before,'Menu Manager revision also supports safe rollback draft');
ok13h((int)$rollback['sourceVersion']===5,'rollback pins current menu version');
ok13h(!fm13f_decide($rollback,'publish',$after,100)['allowed'],'rollback cannot skip approval');
$api=(string)file_get_contents(__DIR__.'/../api/food-monster-procedure-history.php');
$review=(string)file_get_contents(__DIR__.'/../api/food-monster-procedure-review.php');
$manager=(string)file_get_contents(__DIR__.'/../includes/menu-manager-core.php');
ok13h(str_contains($api,'fm13h_unified(')&&str_contains($api,'fm13h_recent('),'API merges native and existing histories');
ok13h(str_contains($manager,"fm13h_audit_procedure("),'Menu Manager saves audit inside same transaction');
ok13h(str_contains($review,'fm13h_audit_procedure('),'Food Monster publish audits same transaction');
ok13h(str_contains($review,'version=version+1')&&str_contains($review,"AND version=?"),'published source protects native version');
ok13h(str_contains($api,'fm13g_turn_seen($events,$turnId)'),'replay protection retained');
echo 'FM-G13H unified procedure audit PASS ('.$n.' assertions)'."\n";
