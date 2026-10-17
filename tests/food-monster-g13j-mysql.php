<?php
declare(strict_types=1);
require __DIR__.'/../includes/food-monster-g13c-training.php';
$dsn=getenv('G13J_MYSQL_DSN')?:'';
if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('FM-G13J requires a real MySQL service');
$pdo=new PDO($dsn,getenv('G13J_MYSQL_USER')?:'root',
    getenv('G13J_MYSQL_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("CREATE TABLE agent_conversations (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,organization_id INT NOT NULL,user_id INT NOT NULL,
 public_id VARCHAR(32) NOT NULL,title VARCHAR(220) NOT NULL,
 primary_channel VARCHAR(24) NOT NULL DEFAULT 'text',
 last_message_at DATETIME(6) NULL,created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),
 archived_at DATETIME(6) NULL,UNIQUE KEY (organization_id,user_id,public_id)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE agent_messages (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,organization_id INT NOT NULL,
 conversation_id BIGINT NOT NULL,user_id INT NULL,public_id VARCHAR(32) NOT NULL,
 role VARCHAR(16) NOT NULL,channel VARCHAR(24) NOT NULL,content TEXT NOT NULL,
 skill VARCHAR(100) NULL,tool_name VARCHAR(100) NULL,voice_event_public_id VARCHAR(60) NULL,
 structured_json LONGTEXT NULL,sources_json LONGTEXT NULL,
 created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6)) ENGINE=InnoDB");
$checks=0;
function verify13j(bool $ok,string $what):void{
    global $checks;
    if(!$ok)throw new RuntimeException('FM-G13J real MySQL FAIL: '.$what);
    $checks++;
}
$org=22;$person=7;$other=8;
$one=fm13c_training_thread($pdo,$org,$person,true);
$two=fm13c_training_thread($pdo,$org,$other,true);
verify13j($one!==$two,'different participants have distinct persisted training threads');
verify13j(fm13c_training_thread($pdo,23,$person,false)===null,'cross-organization training thread not discoverable');
verify13j(fm13c_read_state($pdo,$org,$person,$one)===null,'new training thread has no invented history');
$old=fm13c_material(['id'=>34,'name'=>'Pizza','preparationNotes'=>"Use dough.\nAdd sauce."],'menu');
$new=fm13c_material(['id'=>34,'name'=>'Pizza','preparationNotes'=>"Use shaped dough.\nAdd sauce.\nBake as instructed."],'menu');
$turn=static fn(int $i):string=>'msg-'.str_pad(dechex($i),20,'0',STR_PAD_LEFT);
$save=static function(int $uid,string $thread,array $result,string $action)use($pdo,$org):void{
    $pdo->beginTransaction();
    gaw_append($pdo,$org,$uid,$thread,'system','Guided training progress updated',[
        'channel'=>'text','structured'=>['collection'=>'fm13c_guided_training',
            'source'=>'gelato_training','action'=>$action,'state'=>$result['state']]]);
    $pdo->commit();
};
$original=['kind'=>'menu','id'=>'34','digest'=>$old['digest'],'status'=>'completed',
    'index'=>1,'stepCount'=>2,'practiceAttempts'=>3,'practiceCorrect'=>1,'quiz'=>null];
$save($person,$one,['state'=>$original],'next');
$save($other,$two,['state'=>$original],'next');
verify13j(fm13c_read_state($pdo,$org,$person,$one)['practiceAttempts']===3,'original practice is persisted');
$review=fm13c_transition(fm13c_read_state($pdo,$org,$person,$one),
    ['action'=>'review_update'],$new,$turn(1),1900);
$save($person,$one,$review,'review_update');
verify13j(fm13c_read_state($pdo,$org,$person,$one)['refresh']['reviewDigest']===$new['digest'],
    'review digest survives canonical message JSON');
$ack=fm13c_transition(fm13c_read_state($pdo,$org,$person,$one),
    ['action'=>'acknowledge_update'],$new,$turn(2),1901);
$save($person,$one,$ack,'acknowledge_update');
verify13j(fm13c_read_state($pdo,$org,$person,$one)['digest']===$old['digest'],
    'ack does not rewrite former completion as fresh');
$start=fm13c_transition(fm13c_read_state($pdo,$org,$person,$one),
    ['action'=>'start_refresh'],$new,$turn(3),1902);
$save($person,$one,$start,'start_refresh');
$reloaded=fm13c_read_state($pdo,$org,$person,$one);
verify13j($reloaded['digest']===$new['digest']&&$reloaded['index']===0,
    'new source and step zero survive transaction');
verify13j($reloaded['refresh']['priorStatus']==='completed'
    &&$reloaded['practiceAttempts']===0,'prior completion retained with new practice counters');
verify13j(fm13c_read_state($pdo,$org,$other,$two)['digest']===$old['digest'],
    'coworker training unchanged by another person refreshing');
$crossPersonDenied=false;
try{fm13c_read_state($pdo,$org,$other,$one);}
catch(InvalidArgumentException $e){$crossPersonDenied=true;}
verify13j($crossPersonDenied,
    'cross-person thread lookup throws a denial rather than returning private messages');
$log=gaw_messages($pdo,$org,$person,$one,10);
verify13j(count($log)===4,'prior progress, review, acknowledgement and refresh append-only events retained');
verify13j(($log[2]['structured']['action']??'')==='acknowledge_update',
    'acknowledgement has its own auditable action distinct from skills certification');
verify13j(($log[3]['structured']['action']??'')==='start_refresh',
    'refresh start separately auditable and reversible only through new event');
echo 'FM-G13J real MySQL guided training continuity PASS ('.$checks.' assertions)'."\n";
