<?php
declare(strict_types=1);
require_once __DIR__.'/timeclock-voice-core.php';
require_once __DIR__.'/food-monster-identity-authority.php';

/** Clock-only credentials. They are deliberately unrelated to login/PIN credentials. */
function fm9b_ready(PDO $pdo): bool
{
    try{
        return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('fm_employee_clock_pins','time_clock_entries')")->fetchColumn()===2;
    }catch(Throwable){return false;}
}

function fm9b_valid_pin(string $pin): bool
{
    return preg_match('/^\d{6}$/D',$pin)===1
        && count(array_unique(str_split($pin)))>=3
        && !in_array($pin,['123456','654321','000000','111111','121212','112233'],true);
}

/** An active assigned employee, not an owner, customer or unassigned person. */
function fm9b_employee(PDO $pdo,int $organizationId,int $personId,bool $forUpdate=false): ?array
{
    if($organizationId<1||$personId<1)return null;
    $q=$pdo->prepare("SELECT u.id,u.display_name,om.employee_number,om.primary_location_id,om.id membership_id
        FROM organization_memberships om JOIN users u ON u.id=om.user_id
        WHERE om.organization_id=? AND om.user_id=? AND om.status='active'
          AND u.status='active' AND u.archived_at IS NULL
        LIMIT 1".($forUpdate?' FOR UPDATE':''));
    $q->execute([$organizationId,$personId]);
    $employee=$q->fetch(PDO::FETCH_ASSOC);
    if(!$employee)return null;
    // Locking each query also refreshes roles inside an outer REPEATABLE READ transaction.
    $q=$pdo->prepare('SELECT r.slug,r.is_owner_role,r.is_system_role FROM user_roles ur
        JOIN roles r ON r.id=ur.role_id AND r.organization_id=?
        WHERE ur.membership_id=? AND ur.revoked_at IS NULL'.($forUpdate?' FOR UPDATE':''));
    $q->execute([$organizationId,(int)$employee['membership_id']]);$assignedEmployee=false;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $role){
        if(app_role_has_owner_authority($role)||in_array($role['slug'],['customer','wholesale_customer'],true))return null;
        if((int)$role['is_owner_role']===0&&$role['slug']!=='super_admin')$assignedEmployee=true;
    }
    if(!$assignedEmployee)return null;
    unset($employee['membership_id']);
    return $employee;
}

function fm9b_roster(PDO $pdo,array $owner): array
{
    if(!fm9a_is_super_admin_session($owner))throw new DomainException('Super Admin required.');
    $org=(int)$owner['organization_id'];
    $q=$pdo->prepare("SELECT u.id AS personId,u.display_name AS displayName,om.employee_number AS employeeNumber,
        IF(cp.user_id IS NULL,0,1) AS pinSet,
        IF(tc.user_id IS NULL,0,1) AS clockedIn
        FROM organization_memberships om JOIN users u ON u.id=om.user_id
        LEFT JOIN fm_employee_clock_pins cp ON cp.organization_id=om.organization_id AND cp.user_id=om.user_id
        LEFT JOIN (SELECT DISTINCT user_id,organization_id FROM time_clock_entries WHERE status='open' AND clocked_out_at IS NULL) tc
          ON tc.organization_id=om.organization_id AND tc.user_id=om.user_id
        WHERE om.organization_id=? AND om.status='active' AND u.status='active' AND u.archived_at IS NULL
          AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id
              WHERE ur.membership_id=om.id AND ur.revoked_at IS NULL AND r.organization_id=om.organization_id
              AND r.is_owner_role=0 AND r.slug NOT IN ('super_admin','customer','wholesale_customer'))
          AND NOT EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id
              WHERE ur.membership_id=om.id AND ur.revoked_at IS NULL
              AND (r.is_owner_role=1 OR (r.slug='super_admin' AND r.is_system_role=1)
              OR r.slug IN ('customer','wholesale_customer')))
        ORDER BY u.display_name LIMIT 200");
    $q->execute([$org]);
    return array_map(static fn($r)=>[
        'personId'=>(int)$r['personId'],
        'displayName'=>(string)$r['displayName'],
        'employeeNumber'=>$r['employeeNumber']===null?null:(string)$r['employeeNumber'],
        'pinSet'=>(bool)$r['pinSet'],
        'clockedIn'=>(bool)$r['clockedIn'],
    ],$q->fetchAll(PDO::FETCH_ASSOC));
}

/** Owner password is required to provision a clock credential on a shared terminal. */
function fm9b_set_pin(PDO $pdo,array $owner,int $personId,string $newPin,string $ownerPassword): void
{
    if(!fm9a_is_super_admin_session($owner))throw new DomainException('Super Admin required.');
    if(!fm9b_valid_pin($newPin))throw new InvalidArgumentException('Choose a six-digit shift PIN with at least three different digits.');
    if(strlen($ownerPassword)<1||strlen($ownerPassword)>300)throw new DomainException('Super Admin password required.');
    $org=(int)$owner['organization_id'];
    $q=$pdo->prepare('SELECT password_hash FROM users WHERE id=? LIMIT 1');
    $q->execute([(int)$owner['id']]);
    if(!password_verify($ownerPassword,(string)$q->fetchColumn()))throw new DomainException('Super Admin password was not accepted.');
    $hash=app_password_hash($newPin);
    tv_employee_transaction($pdo,$org,$personId,function()use($pdo,$org,$personId,$hash,$owner):array{
        if(!fm9b_employee($pdo,$org,$personId,true))throw new DomainException('Active employee required.');
        $pdo->prepare("INSERT INTO fm_employee_clock_pins (organization_id,user_id,pin_hash,created_by,failures,locked_until)
            VALUES (?,?,?,?,0,NULL) ON DUPLICATE KEY UPDATE pin_hash=VALUES(pin_hash),
            created_by=VALUES(created_by),failures=0,locked_until=NULL")
            ->execute([$org,$personId,$hash,(int)$owner['id']]);
        app_audit($pdo,$org,(int)$owner['id'],'timeclock.employee_pin_set','user',(string)$personId,null,['clockOnly'=>true]);
        return [];
    });
}

/** Employee identifies themself by person selection plus shift PIN; no login/session mutation. */
function fm9b_clock(PDO $pdo,array $owner,int $personId,string $pin,string $action): array
{
    if(!fm9a_is_super_admin_session($owner))throw new DomainException('Super Admin required.');
    if(!in_array($action,['clock_in','clock_out'],true))throw new InvalidArgumentException('Unsupported shift action.');
    if(!preg_match('/^\d{6}$/D',$pin))throw new DomainException('Shift PIN not accepted.');
    $org=(int)$owner['organization_id'];
    $ownTransaction=!$pdo->inTransaction();
    if($ownTransaction)$pdo->beginTransaction();
    try{
        tv_lock_employee($pdo,$org,$personId);
        $employee=fm9b_employee($pdo,$org,$personId,true);
        if(!$employee)throw new DomainException('Shift PIN not accepted.');
        $q=$pdo->prepare('SELECT pin_hash,failures,locked_until FROM fm_employee_clock_pins WHERE organization_id=? AND user_id=? FOR UPDATE');
        $q->execute([$org,$personId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||($row['locked_until']!==null&&strtotime((string)$row['locked_until'])>time()))
            throw new DomainException('Shift PIN not accepted or temporarily locked.');
        if(!password_verify($pin,(string)$row['pin_hash'])){
            $fails=(int)$row['failures']+1;
            $lock=$fails>=5?date('Y-m-d H:i:s',time()+15*60):null;
            $pdo->prepare('UPDATE fm_employee_clock_pins SET failures=?,locked_until=? WHERE organization_id=? AND user_id=?')
                ->execute([$fails>=5?0:$fails,$lock,$org,$personId]);
            // Commit the failed-attempt counter without any attendance action.
            if($ownTransaction)$pdo->commit();
            throw new DomainException('Shift PIN not accepted or temporarily locked.');
        }
        // Canonical services reuse the employee lock held above.
        $entry=$action==='clock_in'
            ?tv_clock_in($pdo,$org,$personId,(int)$owner['id'],'manual','Employee shift PIN verified')
            :tv_clock_out($pdo,$org,$personId,(int)$owner['id'],'manual','Employee shift PIN verified');
        $pdo->prepare('UPDATE fm_employee_clock_pins SET failures=0,locked_until=NULL WHERE organization_id=? AND user_id=?')
            ->execute([$org,$personId]);
        app_audit($pdo,$org,(int)$owner['id'],'timeclock.employee_'.$action,'user',(string)$personId,null,[
            'clockOnly'=>true,'method'=>'employee_shift_pin','clockId'=>$entry['public_id']??null,
        ]);
        if($ownTransaction)$pdo->commit();
        return ['personId'=>$personId,'displayName'=>(string)$employee['display_name'],
            'status'=>$action==='clock_in'?'clocked_in':'clocked_out',
            'clockPublicId'=>(string)($entry['public_id']??'')];
    }catch(Throwable $error){
        if($ownTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
}
