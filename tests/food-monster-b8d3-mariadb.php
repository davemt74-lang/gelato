<?php
declare(strict_types=1);
function app_has_permission(string $name,array $user):bool{
 return in_array('*',$user['permissions']??[],true)||in_array($name,$user['permissions']??[],true);
}
require dirname(__DIR__).'/includes/food-monster-b8d3-timecard.php';
$dsn=getenv('B8D3_MYSQL_DSN')?:'mysql:host=127.0.0.1;port=3306;dbname=b8d3_test;charset=utf8mb4';
$pdo=new PDO($dsn,'root',getenv('B8D3_MYSQL_PASSWORD')?:'testroot',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE organizations (id INT PRIMARY KEY,timezone VARCHAR(120))");
$pdo->exec("CREATE TABLE time_clock_entries (
 id INT AUTO_INCREMENT PRIMARY KEY, organization_id INT NOT NULL,user_id INT NOT NULL,
 clocked_in_at DATETIME(6) NOT NULL,clocked_out_at DATETIME(6) NULL,status VARCHAR(30) NOT NULL
)");
$pdo->exec("CREATE TABLE time_clock_breaks (
 id INT AUTO_INCREMENT PRIMARY KEY,organization_id INT NOT NULL,time_clock_entry_id INT NOT NULL,
 started_at DATETIME(6) NOT NULL,ended_at DATETIME(6) NULL
)");
$pdo->exec("INSERT INTO organizations(id,timezone) VALUES(21,'America/Phoenix'),(22,'America/Phoenix')");
$tz=new DateTimeZone('America/Phoenix');
$first=(new DateTimeImmutable('today',$tz))->modify('-2 days')->format('Y-m-d');
$second=(new DateTimeImmutable('today',$tz))->modify('-1 day')->format('Y-m-d');
$insert=$pdo->prepare("INSERT INTO time_clock_entries
 (organization_id,user_id,clocked_in_at,clocked_out_at,status) VALUES(?,?,?,?,?)");
$insert->execute([21,7,$first.' 09:00:00',$first.' 17:00:00','closed']);
$id=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO time_clock_breaks
 (organization_id,time_clock_entry_id,started_at,ended_at) VALUES(?,?,?,?)")
 ->execute([21,$id,$first.' 12:00:00',$first.' 12:30:00']);
$insert->execute([21,7,$second.' 10:00:00',$second.' 14:00:00','closed']);
$insert->execute([21,8,$first.' 00:00:00',$first.' 23:00:00','closed']);
$insert->execute([22,7,$first.' 00:00:00',$first.' 23:00:00','closed']);
$user=['id'=>7,'organization_id'=>21,'first_name'=>'David','permissions'=>['timeclock.self']];
$result=fm8d3_hours_read($pdo,$user,['period'=>'last_14_days']);
if($result['data']['completedMinutes']!==690||$result['data']['completedEntries']!==2)
 throw new RuntimeException('Actual hours or tenant/person isolation mismatch.');
if(!str_contains($result['data']['answer'],'David')||!str_contains($result['data']['answer'],'11h 30m'))
 throw new RuntimeException('Personal hours answer not verified.');
$other=fm8d3_hours_read($pdo,array_replace($user,['id'=>9]),['period'=>'last_14_days']);
if($other['data']['completedEntries']!==0
    ||!str_contains($other['data']['answer'],'not proof you worked zero'))
 throw new RuntimeException('Missing records must never become zero verified hours.');
try{
 fm8d3_hours_read($pdo,array_replace($user,['permissions'=>[]]),['period'=>'last_14_days']);
 throw new RuntimeException('Permission gate did not block access.');
}catch(DomainException){}
echo "Food Monster B8D3 MariaDB: personal hours, breaks, no-data, tenant isolation and permission checks passed.\n";
