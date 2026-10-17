<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/food-monster-g13h-unified-audit.php';
require_once __DIR__.'/../includes/food-monster-g13f-procedure-review.php';
$dsn=getenv('G13H_MYSQL_DSN')?:'';
if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('FM-G13H requires real MySQL, not SQLite or a mock');
$user=getenv('G13H_MYSQL_USER')?:'root';
$pass=getenv('G13H_MYSQL_PASS')?:'';
function db13h():PDO{
    global $dsn,$user,$pass;
    return new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false]);
}
$pdo=db13h();
$n=0;
function checkDb(bool $ok,string $message):void{
    global $n;if(!$ok)throw new RuntimeException('FM-G13H MySQL FAIL: '.$message);$n++;
}
$pdo->exec("CREATE TABLE IF NOT EXISTS agent_conversations (
 id BIGINT PRIMARY KEY AUTO_INCREMENT,organization_id INT NOT NULL,user_id INT NOT NULL,
 public_id VARCHAR(32) NOT NULL,title VARCHAR(220) NOT NULL,
 primary_channel VARCHAR(24) NOT NULL DEFAULT 'text',
 last_message_at DATETIME(6) NULL,created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),
 archived_at DATETIME(6) NULL,UNIQUE KEY (organization_id,user_id,public_id)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS agent_messages (
 id BIGINT PRIMARY KEY AUTO_INCREMENT,organization_id INT NOT NULL,
 conversation_id BIGINT NOT NULL,user_id INT NULL,public_id VARCHAR(32) NOT NULL,
 role VARCHAR(16) NOT NULL,channel VARCHAR(24) NOT NULL,content TEXT NOT NULL,
 skill VARCHAR(100) NULL,tool_name VARCHAR(100) NULL,voice_event_public_id VARCHAR(60) NULL,
 structured_json LONGTEXT NULL,sources_json LONGTEXT NULL,
 created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS menu_items (
 id INT PRIMARY KEY AUTO_INCREMENT,organization_id INT NOT NULL,
 name VARCHAR(100) NOT NULL,preparation_notes TEXT NULL,
 version INT NOT NULL DEFAULT 1,is_active TINYINT NOT NULL DEFAULT 1,
 updated_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6)) ENGINE=InnoDB");
$org=42;$other=43;$actor=7;$item=101;
$pdo->prepare("INSERT INTO menu_items (id,organization_id,name,preparation_notes,version)
  VALUES (?,?,?,?,?)")->execute([$item,$org,'Pie','First approved preparation note.',1]);
$old='First approved preparation note.';
$new='Second approved preparation note with a complete step.';
$pdo->beginTransaction();
$locked=fm13f_source_snapshot($pdo,$org,$item);
checkDb((int)$locked['version']===1,'real InnoDB row locks expose native version');
$q=$pdo->prepare("UPDATE menu_items SET preparation_notes=?,version=version+1
 WHERE organization_id=? AND id=? AND version=? AND preparation_notes=?");
$q->execute([$new,$org,$item,1,$old]);checkDb($q->rowCount()===1,'native version-and-content CAS succeeds once');
$eventId=fm13h_audit_procedure($pdo,$org,$actor,$item,'Pie',$old,$new,1,2,'menu_manager');
checkDb(preg_match('/^msg-[a-f0-9]{20}$/D',$eventId)===1,'audit event inserted as canonical system message');
$pdo->commit();
$read=$pdo->prepare('SELECT preparation_notes,version FROM menu_items WHERE organization_id=? AND id=?');
$read->execute([$org,$item]);$current=$read->fetch();
checkDb($current['preparation_notes']===$new&&(int)$current['version']===2,'commit persists changed procedure and native version');
$events=fm13h_recent($pdo,$org);
checkDb(count($events['events'])===1&&!$events['partial'],'same-tenant unified reader finds committed revision');
$versions=fm13h_unified([],$events['events'],$org,9);
checkDb(count($versions)===1&&$versions[0]['before']===$old&&$versions[0]['after']===$new,'hash-verified before-after survives JSON round trip');
$wrong=fm13h_recent($pdo,$other);
checkDb($wrong['events']===[],'tenant 43 cannot query tenant 42 audit');
$q->execute(['Unauthorized stale edit',$org,$item,1,$old]);
checkDb($q->rowCount()===0,'stale pre-edit request cannot overwrite approved version');
$pdo->beginTransaction();
$q->execute(['Third proposed note',$org,$item,2,$new]);
checkDb($q->rowCount()===1,'second transaction prepared');
$pdo->rollBack();
$read->execute([$org,$item]);$current=$read->fetch();
checkDb($current['preparation_notes']===$new&&(int)$current['version']===2,'rolled back menu write leaves no revision increment');

$pdo->exec("CREATE TRIGGER fm13h_fail_audit BEFORE INSERT ON agent_messages
 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test audit write failure'");
try{
    $pdo->beginTransaction();
    $q->execute(['Unlogged change is forbidden',$org,$item,2,$new]);
    fm13h_audit_procedure($pdo,$org,$actor,$item,'Pie',$new,
        'Unlogged change is forbidden',2,3,'menu_manager');
    throw new RuntimeException('Audit failure trigger failed to run');
}catch(PDOException $e){
    if($pdo->inTransaction())$pdo->rollBack();
    checkDb(str_contains($e->getMessage(),'test audit write failure'),'audit failure surfaces as SQL exception');
}
$pdo->exec('DROP TRIGGER fm13h_fail_audit');
$read->execute([$org,$item]);$current=$read->fetch();
checkDb($current['preparation_notes']===$new&&(int)$current['version']===2,
    'failed audit insert atomically rolls back menu mutation');
$events=fm13h_recent($pdo,$org);
checkDb(count($events['events'])===1,'failed audit did not leave orphaned or partially changed records');
// Hold row lock in one connection while another real connection attempts
// a versioned write; it must time out, not bypass the lock.
$one=db13h();$two=db13h();
$one->beginTransaction();
$one->prepare("SELECT version FROM menu_items WHERE organization_id=? AND id=? FOR UPDATE")->execute([$org,$item]);
$two->exec('SET innodb_lock_wait_timeout=1');
$blocked=false;
try{
    $q2=$two->prepare("UPDATE menu_items SET preparation_notes=?,version=version+1
        WHERE organization_id=? AND id=? AND version=?");
    $q2->execute(['Conflicting concurrent note',$org,$item,2]);
}catch(PDOException $e){
    $blocked=in_array((string)$e->getCode(),['HY000','40001'],true);
}
checkDb($blocked,'second PDO connection cannot edit a locked menu source');
$one->commit();
$read->execute([$org,$item]);$current=$read->fetch();
checkDb($current['preparation_notes']===$new&&(int)$current['version']===2,
    'timed-out concurrent writer leaves audited procedure unchanged');
$one=null;$two=null;
echo 'FM-G13H real MySQL certification PASS ('.$n.' assertions)'."\n";
