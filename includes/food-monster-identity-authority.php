<?php
declare(strict_types=1);

/**
 * FM-G9A: separate the trusted website operator, operational clock-ins and
 * Food Monster's future delegated execution policy.
 *
 * Only the server-supplied app_require_auth() user is an authority source.
 * Camera, voice, time-clock and browser context are NEVER credentials.
 */
function fm9a_is_super_admin_session(array $operator): bool
{
    return (int)($operator['id']??0)>0
        && (int)($operator['organization_id']??0)>0
        && function_exists('app_role_has_owner_authority')
        && app_role_has_owner_authority($operator);
}

/** Pure policy foundation. Callers must still authorize each actual tool. */
function fm9a_agent_action_decision(array $operator, string $impact): string
{
    if(!in_array($impact,['read','routine','high_impact'],true))return 'denied';
    if(!fm9a_is_super_admin_session($operator))return 'denied';
    return $impact==='high_impact'?'approval_required':'delegated';
}

/** Normalize existing time-clock records, never manufacture a website session. */
function fm9a_clocked_in_people(array $rows): array
{
    $people=[];$seen=[];
    foreach($rows as $row){
        if(!is_array($row))continue;
        $personId=(int)($row['user_id']??0);
        $clockId=trim((string)($row['clock_public_id']??''));
        if($personId<1||$clockId===''||isset($seen[$personId]))continue;
        $seen[$personId]=true;
        $location=(int)($row['location_id']??0);
        $people[]=[
            'personId'=>$personId,
            'displayName'=>(string)($row['display_name']??''),
            'status'=>'clocked_in',
            'clockPublicId'=>$clockId,
            'clockedInAt'=>(string)($row['clocked_in_at']??''),
            'shiftTitle'=>($row['shift_title']??null)===null?null:(string)$row['shift_title'],
            'locationId'=>$location>0?$location:null,
            'websiteAuthenticated'=>false,
            'agentAdministration'=>false,
        ];
    }
    return $people;
}

/** Assemble context without confusing attendance or observed people with login. */
function fm9a_build_context(array $operator,array $roster,string $clockStatus): array
{
    $id=(int)($operator['id']??0);$org=(int)($operator['organization_id']??0);
    if($id<1||$org<1)throw new InvalidArgumentException('Authenticated Gelato operator required.');
    $superAdmin=fm9a_is_super_admin_session($operator);
    return [
        'operator'=>[
            'personId'=>$id,
            'displayName'=>(string)($operator['display_name']??''),
            'source'=>'authenticated_website_session',
            'isSuperAdmin'=>$superAdmin,
            'clockInRequired'=>false,
        ],
        'clockedIn'=>[
            'status'=>$superAdmin?$clockStatus:'not_authorized',
            'people'=>$superAdmin?fm9a_clocked_in_people($roster):[],
        ],
        'room'=>['status'=>'not_connected','people'=>[],'currentSpeaker'=>null],
        'agentAuthority'=>[
            'principal'=>'food_monster_agent',
            'source'=>'super_admin_delegation',
            'routine'=>fm9a_agent_action_decision($operator,'routine'),
            'highImpact'=>fm9a_agent_action_decision($operator,'high_impact'),
        ],
        'employeeAgentAuthority'=>'conversation_only',
    ];
}

/**
 * Returns server-verified context for existing Agent Workspace bootstrap.
 * No client-supplied person or permission identifiers are accepted.
 * An unavailable time clock must not be presented as an empty active shift.
 */
function fm9a_context(PDO $pdo,array $operator): array
{
    if(!fm9a_is_super_admin_session($operator))
        return fm9a_build_context($operator,[],'not_authorized');
    $rows=[];$clockStatus='unavailable';
    try{
        require_once __DIR__.'/timeclock-agent-core.php';
        if(timeclock_agent_ready($pdo)){
            $rows=timeclock_agent_active_staff($pdo,(int)$operator['organization_id'],null);
            $clockStatus='available';
        }
    }catch(Throwable $error){
        error_log('FM-G9A time-clock context unavailable: '.get_class($error));
    }
    return fm9a_build_context($operator,$rows,$clockStatus);
}
