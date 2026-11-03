<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/bootstrap.php';

if (($argv[1] ?? '') === 'reset-worker') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    fwrite(STDOUT, "ready\n"); fflush(STDOUT);
    try {
        app_password_reset_complete(app_pdo(), $input['token'], $input['hash']);
        echo json_encode(['won' => true]);
    } catch (InvalidArgumentException) {
        echo json_encode(['won' => false]);
    }
    exit;
}

$checks = 0;
function ha_assert(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function ha_one(PDO $pdo, string $sql, array $args = []): mixed {
    $q = $pdo->prepare($sql); $q->execute($args); return $q->fetchColumn();
}
function ha_reject(callable $action, string $message): void {
    try { $action(); } catch (InvalidArgumentException) { ha_assert(true, $message); return; }
    ha_assert(false, $message);
}
function ha_token(PDO $pdo, int $uid, bool $expired = false): string {
    $token = bin2hex(random_bytes(32));
    $expiry = $expired ? 'DATE_SUB(NOW(6), INTERVAL 1 MINUTE)' : 'DATE_ADD(NOW(6), INTERVAL 1 HOUR)';
    $pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES (?,?,' . $expiry . ')')
        ->execute([$uid, hash('sha256', $token)]);
    return $token;
}

$pdo = app_pdo();
app_boot_session();
$suffix = bin2hex(random_bytes(6));
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Auth hardening ' . $suffix]);
$org = (int)$pdo->lastInsertId();
$hash = app_password_hash('Hardening-Original!42');
$pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status,password_changed_at)
    VALUES (?,?,'Auth','Test','Auth Test','active',NOW(6))")
    ->execute(['auth-hardening-' . $suffix . '@example.test', $hash]);
$uid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")
    ->execute([$org, $uid]);
$membership = (int)$pdo->lastInsertId();
$role = (int)ha_one($pdo, "SELECT id FROM roles WHERE organization_id=? AND slug='super_admin'", [$org]);
if ($role < 1) {
    $pdo->prepare("INSERT INTO roles(organization_id,name,slug,is_system_role,is_owner_role) VALUES (?,'Super Admin','super_admin',1,1)")
        ->execute([$org]);
    $role = (int)$pdo->lastInsertId();
}
$pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$membership,$role,$uid]);

$_SESSION['agent_node_pending'] = ['old' => 'must disappear'];
app_session_start_user($pdo, $org, $uid, 'password', $hash);
$first = $_SESSION;
$firstId = session_id();
ha_assert(!isset($_SESSION['agent_node_pending']), 'Sign-in must discard previous actor actions.');
ha_assert(strlen($_SESSION['browser_state_id']) === 32, 'Sign-in must establish a fresh browser scope.');
$current = app_current_user(true);
ha_assert($current !== null && (int)$current['id'] === $uid, 'Credential-bound session must authenticate.');
ha_assert(!array_key_exists('password_hash', $current) && !array_key_exists('password_changed_at', $current), 'Current-user payload must omit credential material.');
app_session_start_user($pdo, $org, $uid, 'trusted_terminal_pin', $hash);
$second = $_SESSION;
ha_assert(session_id() !== $firstId, 'Every sign-in must rotate the session ID.');
ha_assert($first['browser_state_id'] !== $second['browser_state_id'], 'A new login must not reuse browser-private drafts.');
ha_assert(app_current_user(true) !== null, 'PIN session must use the same credential contract.');

$row = $pdo->query('SELECT id,password_hash,password_changed_at,' . $org . ' AS organization_id FROM users WHERE id=' . $uid)->fetch();
$now = time();
ha_assert(app_session_is_current($first, $row, [], $now), 'Fresh independent password session must be valid.');
$legacy = ['user_id'=>$uid,'organization_id'=>$org,'authenticated_at'=>$now];
ha_assert(!app_session_is_current($legacy, $row, [], $now), 'Legacy session must not be silently upgraded.');
$expired = $first; $expired['authenticated_at']=$now-1000; $expired['last_activity_at']=$now-901;
ha_assert(!app_session_is_current($expired, $row, ['session_idle_timeout_seconds'=>900], $now), 'Idle timeout must be enforced without garbage collection.');
$expired['last_activity_at']=$now;
ha_assert(!app_session_is_current($expired, $row, ['session_absolute_lifetime_seconds'=>900], $now), 'Activity must not extend absolute lifetime.');
$future=$first; $future['authenticated_at']=$now+1; $future['last_activity_at']=$now+1;
ha_assert(!app_session_is_current($future,$row,[],$now), 'Future authentication times must fail closed.');
$wrongOrg=$first; $wrongOrg['organization_id']=$org+1;
ha_assert(!app_session_is_current($wrongOrg,$row,[],$now), 'Session identity must remain organization scoped.');

$expiredToken=ha_token($pdo,$uid,true);
ha_reject(fn()=>app_password_reset_complete($pdo,$expiredToken,app_password_hash('Expired-Must-Not-Write!42')), 'Expired token must be rejected in transaction.');
ha_assert(hash_equals($hash,(string)ha_one($pdo,'SELECT password_hash FROM users WHERE id=?',[$uid])), 'Expired token must not change credentials.');
$token=ha_token($pdo,$uid);
$alternate=ha_token($pdo,$uid);
$newHash=app_password_hash('Hardening-Recovered!43');
app_password_reset_complete($pdo,$token,$newHash);
ha_reject(fn()=>app_password_reset_complete($pdo,$token,$hash), 'Consumed token must not replay.');
ha_reject(fn()=>app_password_reset_complete($pdo,$alternate,$hash), 'Recovery must invalidate other outstanding tokens.');
$_SESSION=$first;
ha_assert(app_current_user(true)===null && !isset($_SESSION['user_id']), 'Password recovery must revoke an independent password session.');
$_SESSION=$second;
ha_assert(app_current_user(true)===null && !isset($_SESSION['user_id']), 'Password recovery must revoke an independent PIN session.');
ha_reject(fn()=>app_session_start_user($pdo,$org,$uid,'password',$hash), 'An earlier password verification must not establish a new session after recovery.');
app_session_start_user($pdo,$org,$uid,'password',$newHash);
ha_assert(app_current_user(true)!==null, 'Recovered credentials must establish a usable session.');

for($i=0;$i<5;$i++) app_auth_record_failure($pdo,$uid);
ha_assert((int)ha_one($pdo,'SELECT locked_until>NOW(6) FROM users WHERE id=?',[$uid])===1, 'Five failed attempts must lock the account.');
ha_reject(fn()=>app_session_start_user($pdo,$org,$uid,'password',$newHash), 'A locked account must not obtain a new session with the correct password.');
$pdo->prepare('UPDATE users SET locked_until=NULL,failed_login_count=0 WHERE id=?')->execute([$uid]);

// Two real independent connections wait behind the same user lock, then race one token.
$raceToken=ha_token($pdo,$uid);
$raceHashes=[app_password_hash('Concurrent-Reset-One!42'),app_password_hash('Concurrent-Reset-Two!43')];
$pdo->beginTransaction();
$pdo->query('SELECT id FROM users WHERE id=' . $uid . ' FOR UPDATE')->fetch();
$workers=[];
try {
    foreach($raceHashes as $raceHash){
        $process=proc_open([PHP_BINARY,__FILE__,'reset-worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Could not start reset concurrency worker.');
        fwrite($pipes[0],json_encode(['token'=>$raceToken,'hash'=>$raceHash],JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        stream_set_timeout($pipes[1],10);
        if(trim((string)fgets($pipes[1]))!=='ready')throw new RuntimeException('Reset worker did not become ready.');
        $workers[]=[$process,$pipes,$raceHash];
    }
    $pdo->commit();
    $wins=0; $winnerHash=null;
    foreach($workers as [$process,$pipes,$raceHash]){
        $result=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($process)!==0)throw new RuntimeException('Reset worker failed: '.$errors);
        $outcome=json_decode($result,true,512,JSON_THROW_ON_ERROR);
        if($outcome['won']){$wins++;$winnerHash=$raceHash;}
    }
    ha_assert($wins===1,'Exactly one concurrent token redemption may change the password.');
    ha_assert(hash_equals((string)$winnerHash,(string)ha_one($pdo,'SELECT password_hash FROM users WHERE id=?',[$uid])), 'The losing reset must not overwrite the winner.');
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
}

echo "Authentication hardening: PASS ($checks behavioral checks, including independent-connection reset race)\n";
