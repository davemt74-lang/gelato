<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-kitchen-outcomes.php';

const GLASSES_VISION_V9_RELEASE='v9.0-rc1';

function glasses_vision_v9_release_manifest(): array
{
    return [
      'release'=>GLASSES_VISION_V9_RELEASE,
      'sections'=>[
        1=>['name'=>'Live Scene Understanding','migration'=>'20261117_vision_lab_v9_live_scene_understanding.sql','runtime'=>'glasses-vision-scene.php'],
        2=>['name'=>'Recipe Step Recognition','migration'=>'20261118_vision_lab_v9_recipe_step_recognition.sql','runtime'=>'glasses-vision-step-recognition.php'],
        3=>['name'=>'Missing / Wrong Ingredient Prevention','migration'=>'20261119_vision_lab_v9_ingredient_prevention.sql','runtime'=>'glasses-vision-ingredient-prevention.php'],
        4=>['name'=>'Portion / Quantity Verification','migration'=>'20261120_vision_lab_v9_quantity_verification.sql','runtime'=>'glasses-vision-quantity-verification.php'],
        5=>['name'=>'Build Quality / Placement Verification','migration'=>'20261121_vision_lab_v9_quality_verification.sql','runtime'=>'glasses-vision-quality-verification.php'],
        6=>['name'=>'Final Product Validation / Presentation Quality','migration'=>'20261122_vision_lab_v9_final_product_validation.sql','runtime'=>'glasses-vision-final-validation.php'],
        7=>['name'=>'Governed Human Confirmation & Kitchen Handoff','migration'=>'20261123_vision_lab_v9_human_handoff_confirmation.sql','runtime'=>'glasses-vision-handoff-confirmation.php'],
        8=>['name'=>'Exception Recovery, Rework & Revalidation','migration'=>'20261124_vision_lab_v9_rework_revalidation.sql','runtime'=>'glasses-vision-rework.php'],
        9=>['name'=>'Production Learning from Kitchen Outcomes','migration'=>'20261125_vision_lab_v9_kitchen_outcomes.sql','runtime'=>'glasses-vision-kitchen-outcomes.php'],
      ],
      'authority'=>[
        'canonicalBuild'=>'glasses-build.php',
        'canonicalKdsHandoff'=>'glasses-handoff.php',
        'finalConsequentialBoundary'=>'Section 7 explicit human confirmation',
        'learningBoundary'=>'Section 9 pending-review evidence only',
      ],
      'releaseGates'=>[
        'allSectionContracts'=>true,
        'cleanInstall'=>true,
        'upgradeExhaustion'=>true,
        'upgradeIdempotence'=>true,
        'canonicalAuthorityGuardrails'=>true,
        'productionPackage'=>true,
      ],
    ];
}

function glasses_vision_v9_release_readiness(PDO $pdo,string $root): array
{
    $manifest=glasses_vision_v9_release_manifest();
    $missing=[];$tables=[
      'glasses_vision_scene_snapshots',
      'glasses_vision_step_recognitions',
      'glasses_vision_ingredient_preventions',
      'glasses_vision_quantity_verifications',
      'glasses_vision_quality_verifications',
      'glasses_vision_final_validations',
      'glasses_vision_handoff_confirmations',
      'glasses_vision_rework_cases',
      'glasses_vision_rework_attempts',
      'glasses_vision_kitchen_outcomes',
    ];
    foreach($tables as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);if((int)$q->fetchColumn()!==1)$missing[]='table:'.$table;
    }
    foreach($manifest['sections'] as $n=>$section){
        if(!is_file(rtrim($root,'/').'/database/'.$section['migration']))$missing[]='migration:section-'.$n;
        if(!is_file(rtrim($root,'/').'/includes/'.$section['runtime']))$missing[]='runtime:section-'.$n;
    }
    $requiredFunctions=[
      'glasses_vision_scene_capture','glasses_vision_step_recognize','glasses_vision_ingredient_guard_assess',
      'glasses_vision_quantity_verify_scene','glasses_vision_quality_verify_scene','glasses_vision_final_validate_scene',
      'glasses_vision_handoff_confirm','glasses_vision_rework_revalidate','glasses_vision_kitchen_outcome_sync',
    ];
    foreach($requiredFunctions as $fn)if(!function_exists($fn))$missing[]='function:'.$fn;

    return [
      'release'=>$manifest['release'],
      'ready'=>$missing===[],
      'missing'=>$missing,
      'sectionCount'=>count($manifest['sections']),
      'authority'=>$manifest['authority'],
      'releaseGates'=>$manifest['releaseGates'],
    ];
}
