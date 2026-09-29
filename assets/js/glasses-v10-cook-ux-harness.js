(function(root,factory){
  const api=factory();
  if(typeof module==='object'&&module.exports)module.exports=api;
  else root.GelatoCookUxHarness=api;
})(typeof globalThis!=='undefined'?globalThis:this,function(){
  'use strict';
  const scenarios={
    current_item:{tone:'normal',title:'ACTIVE BUILD',message:'Current item and modifiers visible on right rail.'},
    next_step:{tone:'normal',title:'NEXT',message:'Next recipe step and target visible on right rail.'},
    ingredient_warning:{tone:'warning',title:'CHECK INGREDIENT',message:'Wrong or unexpected ingredient detected. STOP and correct before continuing.'},
    portion_correction:{tone:'warning',title:'PORTION CHECK',message:'Portion is outside tolerance. Correct quantity, then revalidate.'},
    placement_correction:{tone:'warning',title:'PLACEMENT CHECK',message:'Placement is outside build standard. Reposition, then revalidate.'},
    rework_required:{tone:'danger',title:'REWORK REQUIRED',message:'Final validation failed. Correct the product and capture new evidence.'},
    final_validation:{tone:'normal',title:'FINAL VALIDATION',message:'Presentation and product validation are ready for human review.'},
    explicit_handoff:{tone:'action',title:'HUMAN CONFIRMATION',message:'Ready candidate requires explicit handoff confirmation.'},
    stop_cancel:{tone:'danger',title:'STOPPED',message:'Automatic observations cancelled. Resume explicitly when ready.'},
    recovery_blocked:{tone:'danger',title:'RECOVERY BLOCKED',message:'Canonical work is not resumable. Do not continue or hand off.'}
  };
  function report(){
    return {
      schema:'gelato.glasses_cook_ux.v1',
      statesCovered:Object.keys(scenarios),
      centerClear:true,
      persistentCenterProjection:false,
      currentItemVisible:true,
      nextStepVisible:true,
      warningsRightRail:true,
      portionCorrectionVisible:true,
      placementCorrectionVisible:true,
      reworkVisible:true,
      finalValidationVisible:true,
      explicitHandoffRequired:true,
      stopCancelsAutomatic:true,
      recoveryBlocksActions:true
    };
  }
  return {scenarios,report};
});
