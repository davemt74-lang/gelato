<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-runtime-soak.php';

const GLASSES_COOK_UX_SCHEMA='gelato.glasses_cook_ux.v1';

function glasses_cook_ux_policy(): array
{
    return [
      'schema'=>GLASSES_COOK_UX_SCHEMA,
      'persistentProjectionZones'=>['top_status','left_orders','right_workflow'],
      'centerPersistentProjectionAllowed'=>false,
      'transientCenterEvidenceAllowed'=>true,
      'requiredWorkflowStates'=>[
        'current_item','next_step','ingredient_warning','portion_correction','placement_correction',
        'rework_required','final_validation','explicit_handoff','stop_cancel','recovery_blocked'
      ],
      'consequentialActions'=>[
        'handoffRequiresExplicitHumanConfirmation'=>true,
        'stopCancelsAutomaticObservation'=>true,
        'recoveryBlocksConsequentialControls'=>true,
      ],
    ];
}

function glasses_cook_ux_evaluate(array $report): array
{
    $policy=glasses_cook_ux_policy();
    $states=array_values(array_unique(array_filter((array)($report['statesCovered']??[]),'is_string')));
    $missing=array_values(array_diff($policy['requiredWorkflowStates'],$states));
    $checks=[
      'center_clear'=>!empty($report['centerClear']) && empty($report['persistentCenterProjection']),
      'current_item_visible'=>!empty($report['currentItemVisible']),
      'next_step_visible'=>!empty($report['nextStepVisible']),
      'warnings_right_rail'=>!empty($report['warningsRightRail']),
      'portion_correction_visible'=>!empty($report['portionCorrectionVisible']),
      'placement_correction_visible'=>!empty($report['placementCorrectionVisible']),
      'rework_visible'=>!empty($report['reworkVisible']),
      'final_validation_visible'=>!empty($report['finalValidationVisible']),
      'explicit_handoff_required'=>!empty($report['explicitHandoffRequired']),
      'stop_cancels_automatic'=>!empty($report['stopCancelsAutomatic']),
      'recovery_blocks_actions'=>!empty($report['recoveryBlocksActions']),
      'all_workflow_states'=>$missing===[],
    ];
    $passed=!in_array(false,$checks,true);
    return [
      'schema'=>GLASSES_COOK_UX_SCHEMA,
      'passed'=>$passed,
      'score'=>round(array_sum(array_map(static fn($v)=>$v?1:0,$checks))/count($checks)*10,1),
      'checks'=>$checks,
      'missingStates'=>$missing,
      'policy'=>$policy,
    ];
}
