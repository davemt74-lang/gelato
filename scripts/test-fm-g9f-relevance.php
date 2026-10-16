<?php
declare(strict_types=1);
if(!function_exists('mb_strtolower')){
function mb_strtolower(string $s,string $enc='UTF-8'):string{return strtolower($s);}
function mb_strlen(string $s,string $enc='UTF-8'):int{return strlen($s);}
function mb_substr(string $s,int $start,?int $length=null,string $enc='UTF-8'):string{return substr($s,$start,$length);}
}
require_once __DIR__.'/../includes/food-monster-conversation-memory.php';
function fm9f_check(bool $ok,string $name):void {if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
$m=['openReports'=>[['messageId'=>'msg-a','text'=>'Oven is leaking near door','createdAt'=>'2026-10-15']],
'recentIngredientQuestions'=>[['messageId'=>'msg-b','text'=>'Does pesto contain dairy ingredients?','createdAt'=>'2026-10-15']],
'recentTrainingQuestions'=>[['messageId'=>'msg-c','text'=>'How to stretch pizza dough?','createdAt'=>'2026-10-14']]];
fm9f_check(count(fm9f_relevant_context($m,'Hi!'))===0,'no greeting leakage');
fm9f_check(count(fm9f_relevant_context($m,'How is the oven doing?'))===1,'relevant open report');
fm9f_check(fm9f_relevant_context($m,'Does pesto have dairy?')[0]['item']['messageId']==='msg-b','ingredient memory');
fm9f_check(count(fm9f_relevant_context($m,'What is the next ticket?'))===0,'unrelated ticket omitted');
fm9f_check(count(fm9f_relevant_context(['openReports'=>[]],'Oven leaking'))===0,'resolved report excluded');
fm9f_check(count(fm9f_relevant_context($m,'oven door',1))===1,'bounded results');
fm9f_check(count(fm9f_relevant_context($m,'Remember?'))===2,'explicit recall returns prior questions but not unrelated reports');
