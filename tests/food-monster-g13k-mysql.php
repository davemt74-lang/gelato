<?php
declare(strict_types=1);
function app_has_permission(string $p,array $u):bool{return in_array($p,$u['permissions']??[],true);}
function app_role_has_owner_authority(array $u):bool{return ($u['role_slug']??'')==='super_admin';}
require_once __DIR__.'/../includes/food-monster-g13k-training-notice.php';
$dsn=getenv('G13K_MYSQL_DSN')?:'';
if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('FM-G13K requires real MySQL 8');
$pdo=new PDO($dsn,getenv('G13K_MYSQL_USER')?:'root',getenv('G13K_MYSQL_PASS')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES=>false]);
$create=[
"CREATE TABLE users (id INT PRIMARY KEY, status VARCHAR(20),archived_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE organization_memberships (user_id INT,organization_id INT,
 status VARCHAR(20),PRIMARY KEY (user_id,organization_id)) ENGINE=InnoDB",
"CREATE TABLE menu_sections (id INT PRIMARY KEY,organization_id INT,name VARCHAR(120),
 slug VARCHAR(120),description TEXT,status VARCHAR(20)) ENGINE=InnoDB",
"CREATE TABLE menu_items (id INT PRIMARY KEY,organization_id INT,section_id INT,
 name VARCHAR(120),slug VARCHAR(120),description TEXT,preparation_notes TEXT,
 behavior_tags_json TEXT NULL,is_active TINYINT) ENGINE=InnoDB",
"CREATE TABLE menu_item_prices (id INT PRIMARY KEY AUTO_INCREMENT,menu_item_id INT,
 option_name VARCHAR(100),size_code VARCHAR(30),amount DECIMAL(9,2),
 currency VARCHAR(10),sort_order INT,active_from DATETIME NULL,active_until DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE ingredients (id INT PRIMARY KEY,canonical_name VARCHAR(100),
 category VARCHAR(30),verification_status VARCHAR(20)) ENGINE=InnoDB",
"CREATE TABLE menu_item_ingredients (menu_item_id INT,ingredient_id INT,
 display_name VARCHAR(100),is_optional TINYINT,can_remove TINYINT,sort_order INT) ENGINE=InnoDB",
"CREATE TABLE agent_conversations (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,organization_id INT NOT NULL,user_id INT NOT NULL,
 public_id VARCHAR(32) NOT NULL,title VARCHAR(220) NOT NULL,
 primary_channel VARCHAR(24) NOT NULL DEFAULT 'text',
 last_message_at DATETIME(6) NULL,created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),archived_at DATETIME(6) NULL,
 UNIQUE KEY (organization_id,user_id,public_id)) ENGINE=InnoDB",
"CREATE TABLE agent_messages (id BIGINT AUTO_INCREMENT PRIMARY KEY,
 organization_id INT NOT NULL,conversation_id BIGINT NOT NULL,user_id INT NULL,
 public_id VARCHAR(32) NOT NULL,role VARCHAR(16) NOT NULL,channel VARCHAR(24) NOT NULL,
 content TEXT NOT NULL,skill VARCHAR(100) NULL,tool_name VARCHAR(100) NULL,
 voice_event_public_id VARCHAR(60) NULL,structured_json LONGTEXT NULL,sources_json LONGTEXT NULL,
 created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6)) ENGINE=InnoDB"
];
foreach($create as $sql)$pdo->exec($sql);
$n=0;
function check13kdb(bool $ok,string $name):void{global $n;
    if(!$ok)throw new RuntimeException('FM-G13K MySQL FAIL '.$name);$n++;
}
$org=20;$pid=7;$other=8;$item=34;
foreach([$pid,$other] as $id){
    $pdo->prepare("INSERT INTO users VALUES (?,'active',NULL)")->execute([$id]);
    $pdo->prepare("INSERT INTO organization_memberships VALUES (?,?,'active')")->execute([$id,$org]);
}
$pdo->prepare("INSERT INTO menu_sections VALUES (?,?,?,?,?,'active')")
    ->execute([1,$org,'Pizza','pizza','Approved menu']);
$old="Stretch dough.\nAdd tomato sauce.";
$new="Stretch dough evenly.\nAdd approved tomato sauce.";
$pdo->prepare("INSERT INTO menu_items VALUES (?,?,?,?,?,?,?,NULL,1)")
    ->execute([$item,$org,1,'House Pie','house-pie','',$new]);
$oldMaterial=fm13c_material(['id'=>$item,'name'=>'House Pie','preparationNotes'=>$old],'menu');
$user=['id'=>$pid,'organization_id'=>$org,'role_slug'=>'employee',
    'permissions'=>['training.self_view']];
$thread=fm13c_training_thread($pdo,$org,$pid,true);
$state=['kind'=>'menu','id'=>(string)$item,'digest'=>$oldMaterial['digest'],
 'status'=>'paused','index'=>1,'stepCount'=>2,'practiceAttempts'=>3,'practiceCorrect'=>1];
gaw_append($pdo,$org,$pid,$thread,'system','Initial training state',[
 'structured'=>['collection'=>'fm13c_guided_training','source'=>'gelato_training',
    'state'=>$state,'action'=>'pause']]);
check13kdb(fm13c_read_state($pdo,$org,$pid,$thread)['digest']===$oldMaterial['digest'],
    'training ledger stores original approved procedure');
$notice=fm13k_try_notice($pdo,$user,$pid);
check13kdb(($notice['available']??false)===true,'changed procedure creates first bounded notice');
check13kdb(($notice['suggestedCommand']??'')==='review updated training',
    'notice only offers user-initiated action');
check13kdb(!isset($notice['personId'])&&!isset($notice['sourceDigest'])
    &&!isset($notice['itemId']),'shared response does not expose employee or menu identifiers');
check13kdb(fm13k_try_notice($pdo,$user,$pid)===null,
    'immediate repeated message does not create a new notice');
check13kdb(fm13c_read_state($pdo,$org,$pid,$thread)['digest']===$oldMaterial['digest'],
    'notice in separate thread leaves latest training state unchanged');
$notices=fm13k_notice_thread($pdo,$org,$pid,false);
check13kdb($notices!==null&&$notices!==$thread,'separate person-scoped notice ledger');
$rows=gaw_messages($pdo,$org,$pid,$notices,20);
check13kdb(count($rows)===1&&($rows[0]['structured']['collection']??'')==='fm13k_training_notice',
    'notice delivery persisted as a server system event');
check13kdb(fm13k_latest_notice($pdo,$org,$pid,$notices)['sourceDigest']!==$oldMaterial['digest'],
    'notice ties to current revised source');
check13kdb(fm13k_notice_thread($pdo,$org,$other,false)===null,
    'coworker cannot inherit another employee notice ledger');
$otherUser=$user;$otherUser['id']=$other;
check13kdb(fm13k_try_notice($pdo,$otherUser,$other)===null,
    'employee without saved training never receives another employee alert');
$pdo->prepare("UPDATE menu_items SET preparation_notes=? WHERE id=? AND organization_id=?")
    ->execute(["Stretch dough evenly.\nAdd approved tomato sauce.\nBake safely.",$item,$org]);
check13kdb(fm13k_try_notice($pdo,$user,$pid)!==null,
    'second new procedure revision may issue a distinct notice despite cooldown');
check13kdb(count(gaw_messages($pdo,$org,$pid,$notices,20))===2,
    'two source revisions produce exactly two durable notification records');
$pdo->prepare("UPDATE organization_memberships SET status='inactive' WHERE user_id=? AND organization_id=?")
    ->execute([$pid,$org]);
check13kdb(fm13k_try_notice($pdo,$user,$pid)===null,
    'revoked current-organization membership blocks subsequent notification');
check13kdb(fm13c_read_state($pdo,$org,$pid,$thread)['digest']===$oldMaterial['digest'],
    'revocation and notices never rewrite training progress');
echo 'FM-G13K real MySQL member isolation and notice dedup PASS ('.$n.' assertions)'."\n";
