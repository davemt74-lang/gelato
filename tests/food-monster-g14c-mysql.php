<?php
declare(strict_types=1);
function app_role_has_owner_authority(array $user):bool{
    return ($user['role_slug']??'')==='super_admin';
}
require_once __DIR__.'/../includes/food-monster-g14a-pilot.php';
$dsn=getenv('G14C_MYSQL_DSN')?:'';
if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('FM-G14C requires real MySQL 8');
$pdo=new PDO($dsn,getenv('G14C_MYSQL_USER')?:'root',getenv('G14C_MYSQL_PASS')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES=>false]);
$schema=[
"CREATE TABLE users (id INT PRIMARY KEY,status VARCHAR(20) NOT NULL,
 archived_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE organization_memberships (user_id INT,organization_id INT,
 status VARCHAR(20) NOT NULL,PRIMARY KEY (user_id,organization_id)) ENGINE=InnoDB",
"CREATE TABLE agent_conversations (id BIGINT AUTO_INCREMENT PRIMARY KEY,
 organization_id INT NOT NULL,user_id INT NOT NULL,public_id VARCHAR(32) NOT NULL,
 title VARCHAR(220) NOT NULL,primary_channel VARCHAR(24) NOT NULL DEFAULT 'text',
 last_message_at DATETIME(6) NULL,created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),
 archived_at DATETIME(6) NULL,
 UNIQUE KEY(organization_id,user_id,public_id)) ENGINE=InnoDB",
"CREATE TABLE agent_messages (id BIGINT AUTO_INCREMENT PRIMARY KEY,
 organization_id INT NOT NULL,conversation_id BIGINT NOT NULL,user_id INT NULL,
 public_id VARCHAR(32) NOT NULL,role VARCHAR(16) NOT NULL,
 channel VARCHAR(24) NOT NULL,content TEXT NOT NULL,
 skill VARCHAR(100) NULL,tool_name VARCHAR(100) NULL,
 voice_event_public_id VARCHAR(60) NULL,structured_json LONGTEXT NULL,
 sources_json LONGTEXT NULL,
 created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6)) ENGINE=InnoDB"
];
foreach($schema as $query)$pdo->exec($query);
$n=0;function dbCheck14a(bool $ok,string $reason):void{
    global $n;
    if(!$ok)throw new RuntimeException('FM-G14C MYSQL FAIL: '.$reason);
    $n++;
}
$org=44;$owner=7;$staff=8;
foreach([$owner,$staff] as $person){
    $pdo->prepare("INSERT INTO users (id,status) VALUES (?,'active')")->execute([$person]);
    $pdo->prepare("INSERT INTO organization_memberships VALUES (?,?,'active')")
        ->execute([$person,$org]);
}
$thread=fm14a_thread($pdo,$org,$owner,true);
dbCheck14a(preg_match('/^agent-[a-f0-9]{20}$/D',$thread)===1,
    'pilot thread stored with opaque canonical ID');
dbCheck14a(fm14a_thread($pdo,$org,$owner,false)===$thread,'same owner reuses active pilot thread');
dbCheck14a(fm14a_thread($pdo,$org,$staff,false)===null,'other member cannot discover pilot thread');
dbCheck14a(fm14a_thread($pdo,45,$owner,false)===null,'other organization cannot discover pilot');
$run='pilot-'.str_repeat('a',20);
$start=['collection'=>'fm14a_kitchen_pilot','source'=>'gelato_server','ownerId'=>$owner,
    'runId'=>$run,'action'=>'start','recordedAt'=>time(),
    'sourceTurnId'=>'msg-'.str_repeat('1',20)];
$pdo->beginTransaction();
gaw_append($pdo,$org,$owner,$thread,'system','Pilot started',[
    'structured'=>$start,'channel'=>'text']);
$pdo->commit();
$history=fm14a_events(gaw_messages($pdo,$org,$owner,$thread,50),$owner);
dbCheck14a(count($history)===1,'canonical start survives real JSON round trip');
dbCheck14a(fm14a_state($history,time())['active'],'start recovered after database write');
$records=[];
$eventNumber=2;
foreach(fm14a_gates() as $gate=>$title){
    $event=['collection'=>'fm14a_kitchen_pilot','source'=>'gelato_server',
        'ownerId'=>$owner,'runId'=>$run,'action'=>'record',
        'gate'=>$gate,'outcome'=>'pass','evidence'=>'Operator observed field equipment behaviour for '.$gate.' under real conditions.',
        'recordedAt'=>time(),'sourceTurnId'=>'msg-'.str_pad(dechex($eventNumber++),20,'0',STR_PAD_LEFT)];
    $records[]=$event;
    gaw_append($pdo,$org,$owner,$thread,'system','Pilot field observation',[
        'structured'=>$event,'channel'=>'text']);
}
$history=fm14a_events(gaw_messages($pdo,$org,$owner,$thread,50),$owner);
$state=fm14a_state($history,time());
dbCheck14a($state['passed']===7&&$state['allOperatorReportedPass'],
    'seven independently reported field gates persist in MySQL');
dbCheck14a($state['fieldCertified']===false,
    'even complete manual gates never advertise hardware certification');
dbCheck14a(fm14a_validate_action(['action'=>'record','gate'=>'camera',
    'evidence'=>'No private employee details recorded here.'],$state,
    $records[0]['sourceTurnId'],$history)!==null,'original chat turn cannot replay pass after database read');
$badOwner=false;
try{gaw_messages($pdo,$org,$staff,$thread,30);}
catch(InvalidArgumentException $e){$badOwner=true;}
dbCheck14a($badOwner,'staff cannot read pilot thread owned by Super Admin');
$badOrg=false;
try{gaw_messages($pdo,45,$owner,$thread,30);}
catch(InvalidArgumentException $e){$badOrg=true;}
dbCheck14a($badOrg,'cross-tenant attempt cannot read pilot state');
$pdo->beginTransaction();
$failure=['collection'=>'fm14a_kitchen_pilot','source'=>'gelato_server',
    'ownerId'=>$owner,'runId'=>$run,'action'=>'record',
    'gate'=>'handoff','outcome'=>'fail',
    'evidence'=>'Live shift speaker handoff caused lost context when tested.',
    'recordedAt'=>time(),'sourceTurnId'=>'msg-'.str_repeat('e',20)];
gaw_append($pdo,$org,$owner,$thread,'system','Pilot failure report',[
    'structured'=>$failure,'channel'=>'text']);
$pdo->rollBack();
$state=fm14a_state(fm14a_events(gaw_messages($pdo,$org,$owner,$thread,50),$owner),time());
dbCheck14a($state['passed']===7,
    'transaction rollback leaves no phantom field test failure event');
// A failed physical check requires a new repair plan, subsequent new PASS,
// and explicit owner resolution; verify the event order survives real JSON.
$emitFix=static function(string $action,int $turnNumber,?string $outcome=null)use($pdo,$org,$owner,$thread,$run):array{
    $ev=['collection'=>'fm14a_kitchen_pilot','source'=>'gelato_server','ownerId'=>$owner,
        'runId'=>$run,'action'=>$action,'sourceTurnId'=>'msg-'.str_pad(dechex($turnNumber),20,'0',STR_PAD_LEFT),
        'recordedAt'=>time(),'gate'=>'camera'];
    if($action==='record'){
        $ev['outcome']=$outcome;
        $ev['evidence']='Physical test observed with active camera in pilot station.';
    }else{
        $ev['note']=$action==='plan_fix'
            ?'Replace the failing USB connector and retest camera reconnect.'
            :'New physical camera reconnect test passed; fix was reviewed by owner.';
    }
    gaw_append($pdo,$org,$owner,$thread,'system','Pilot repair event',[
        'channel'=>'text','structured'=>$ev]);
    return $ev;
};
$emitFix('record',40,'fail');
$events=fm14a_events(gaw_messages($pdo,$org,$owner,$thread,50),$owner);
$state=fm14a_state($events,time());
dbCheck14a($state['followUps']['unresolved']===1
    &&$state['followUps']['issues'][0]['status']==='open',
    'real database preserves newly failed camera issue');
dbCheck14a(fm14a_validate_action(['action'=>'resolve_fix','gate'=>'camera',
    'note'=>'Device physically checked after replacement of USB connector.'],
    $state,'msg-'.str_repeat('f',20),$events)!==null,
    'cannot resolve a failed gate without a plan and subsequent passing retest');
$emitFix('plan_fix',41);
$events=fm14a_events(gaw_messages($pdo,$org,$owner,$thread,50),$owner);
$state=fm14a_state($events,time());
dbCheck14a($state['followUps']['issues'][0]['status']==='planned',
    'repair plan survives real MySQL event store');
$emitFix('record',42,'pass');
$events=fm14a_events(gaw_messages($pdo,$org,$owner,$thread,50),$owner);
$state=fm14a_state($events,time());
dbCheck14a($state['followUps']['issues'][0]['status']==='ready_for_review',
    'new post-plan field PASS moves to awaiting owner resolution');
$emitFix('resolve_fix',43);
$state=fm14a_state(fm14a_events(gaw_messages($pdo,$org,$owner,$thread,50),$owner),time());
dbCheck14a($state['followUps']['resolved']===1
    &&$state['followUps']['unresolved']===0,
    'separate resolution event closes repaired failure');

$pdo->prepare("UPDATE organization_memberships SET status='inactive'
    WHERE user_id=? AND organization_id=?")->execute([$owner,$org]);
$q=$pdo->prepare("SELECT u.id FROM users u INNER JOIN organization_memberships om
    ON om.user_id=u.id AND om.organization_id=? AND om.status='active'
    WHERE u.id=? AND u.status='active' AND u.archived_at IS NULL FOR UPDATE");
$q->execute([$org,$owner]);
dbCheck14a(!$q->fetchColumn(),'revoked owner membership fails authenticated write lock');
echo 'FM-G14C real MySQL pilot repair and owner-isolated evidence PASS ('.$n.' assertions)'."\n";
