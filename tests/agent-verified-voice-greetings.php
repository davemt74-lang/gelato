<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $user):bool{return in_array('*',$user['permissions']??[],true)||in_array($permission,$user['permissions']??[],true);}
require dirname(__DIR__).'/includes/agent-voice-conversation.php';
final class GreetingPDO extends PDO {
    public array $match=['matched_user_id'=>12,'display_name'=>'Taylor Kitchen','first_name'=>'Taylor'];
    public array $timing=['new_day'=>1,'idle_min'=>1440];
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new GreetingStatement($this,$query);}
}
final class GreetingStatement extends PDOStatement {
    private array $params=[];
    public function __construct(private GreetingPDO $connection,private string $sql){}
    public function execute(?array $params=null):bool{$this->params=$params??[];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{
        if(str_contains($this->sql,'voice_identity_events'))return $this->connection->match;
        if(str_contains($this->sql,'MAX(created_at)'))return $this->connection->timing;
        return false;
    }
}
function check(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
$db=new GreetingPDO();
$staff=['id'=>12,'permissions'=>['voice.agent']];
$manager=['id'=>8,'permissions'=>['voice.agent','voice.manage']];
$unprivileged=['id'=>8,'permissions'=>['voice.agent']];
check(avc_verified_speaker($db,5,$staff,'voice-valid')['firstName']==='Taylor','staff may greet matched own voice');
check(avc_verified_speaker($db,5,$manager,'voice-valid')['userId']===12,'authorized manager may recognize a different enrolled staff speaker');
check(avc_verified_speaker($db,5,$unprivileged,'voice-valid')===null,'other user voice cannot be adopted without manage permission');
check(avc_verified_speaker($db,5,$manager,'')===null,'missing event never resolves identity');
check(avc_verified_speaker($db,5,['id'=>8,'permissions'=>[]],'voice-valid')===null,'voice permission required');
check(avc_greeting($db,5,12,'Taylor')==='Good to hear from you, Taylor.','first conversation of day greeting');
$db->timing=['new_day'=>0,'idle_min'=>130];
check(avc_greeting($db,5,12,'Taylor')==='Welcome back, Taylor.','long idle greeting');
$db->timing=['new_day'=>0,'idle_min'=>8];
check(avc_greeting($db,5,12,'Taylor')===null,'normal replies do not repeat name');
check(avc_greeting($db,5,12,'')===null,'no name means no greeting');
echo "agent-verified-voice-greetings=ok\n";
