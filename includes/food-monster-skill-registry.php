<?php
declare(strict_types=1);
/**
 * FM-G7B1: curated discovery only. A registry entry is never execution authority.
 * Existing node handlers continue to own write validation, approvals, and receipts.
 */
function fm_g7b_skill_catalog(): array {
    return [
        'inventory.review'=>['label'=>'Review inventory exceptions','node'=>'operations','route'=>'api/operations-agent.php','permissions'=>['inventory.view','inventory.agent'],'effect'=>'read','approval'=>'none'],
        'purchasing.review'=>['label'=>'Review purchase orders','node'=>'purchasing','route'=>'api/purchasing-agent.php','permissions'=>['purchasing.view','purchasing.agent'],'effect'=>'read','approval'=>'none'],
        'scheduling.review'=>['label'=>'Review scheduling coverage','node'=>'scheduling','route'=>'api/scheduling-agent.php','permissions'=>['schedule.view','schedule.agent'],'effect'=>'read','approval'=>'none'],
        'crm.review'=>['label'=>'Review customer records','node'=>'crm','route'=>'api/customer-crm-agent.php','permissions'=>['crm.view'],'effect'=>'read','approval'=>'none'],
        'prep.review'=>['label'=>'Review prep recommendations','node'=>'prep','route'=>'api/prep-intelligence-agent.php','permissions'=>['prep.intelligence.view','prep.intelligence.agent'],'effect'=>'read','approval'=>'none'],
        'equipment.review'=>['label'=>'Review equipment status','node'=>'equipment','route'=>'api/equipment-agent.php','permissions'=>['equipment.view','agent.equipment_skills'],'effect'=>'read','approval'=>'none'],
        'recipes.review'=>['label'=>'Review production recipes','node'=>'recipes','route'=>'api/recipe-agent.php','permissions'=>['recipes.view','recipes.agent'],'effect'=>'read','approval'=>'none'],
        'kds.review'=>['label'=>'Review kitchen tickets','node'=>'kds','route'=>'api/kds-agent.php','permissions'=>['kds.view'],'effect'=>'read','approval'=>'none'],
        'operations.propose'=>['label'=>'Propose operations change','node'=>'operations','route'=>'api/operations-agent.php','permissions'=>['tasks.agent','tasks.view'],'effect'=>'propose','approval'=>'destination_required'],
        'purchasing.propose'=>['label'=>'Propose purchasing change','node'=>'purchasing','route'=>'api/purchasing-agent.php','permissions'=>['purchasing.view','purchasing.agent'],'effect'=>'propose','approval'=>'destination_required'],
        'scheduling.propose'=>['label'=>'Propose scheduling change','node'=>'scheduling','route'=>'api/scheduling-agent.php','permissions'=>['schedule.agent'],'effect'=>'propose','approval'=>'destination_required'],
    ];
}
/** Never use caller-provided route or permission values. */
function fm_g7b_skills_for_user(array $user, callable $hasPermission): array {
    $out=[];
    foreach(fm_g7b_skill_catalog() as $id=>$skill){
        foreach($skill['permissions'] as $permission){
            if(!$hasPermission($permission,$user)) continue 2;
        }
        $out[]=['id'=>$id,'label'=>$skill['label'],'node'=>$skill['node'],
                'effect'=>$skill['effect'],'approval'=>$skill['approval']];
    }
    return $out;
}
/** Resolve only known IDs, with permission checks repeated at time of use. */
function fm_g7b_resolve_skill(string $skillId,array $user,callable $hasPermission): ?array {
    $catalog=fm_g7b_skill_catalog();
    if(!isset($catalog[$skillId]))return null;
    $skill=$catalog[$skillId];
    foreach($skill['permissions'] as $permission){
        if(!$hasPermission($permission,$user))return null;
    }
    return ['id'=>$skillId]+$skill;
}
