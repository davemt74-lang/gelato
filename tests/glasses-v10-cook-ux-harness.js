'use strict';
const assert=require('assert');
const harness=require('../assets/js/glasses-v10-cook-ux-harness.js');

const report=harness.report();
assert.strictEqual(report.schema,'gelato.glasses_cook_ux.v1');
assert.strictEqual(report.centerClear,true);
assert.strictEqual(report.persistentCenterProjection,false);
assert.strictEqual(report.currentItemVisible,true);
assert.strictEqual(report.nextStepVisible,true);
assert.strictEqual(report.warningsRightRail,true);
assert.strictEqual(report.portionCorrectionVisible,true);
assert.strictEqual(report.placementCorrectionVisible,true);
assert.strictEqual(report.reworkVisible,true);
assert.strictEqual(report.finalValidationVisible,true);
assert.strictEqual(report.explicitHandoffRequired,true);
assert.strictEqual(report.stopCancelsAutomatic,true);
assert.strictEqual(report.recoveryBlocksActions,true);

const required=['current_item','next_step','ingredient_warning','portion_correction','placement_correction','rework_required','final_validation','explicit_handoff','stop_cancel','recovery_blocked'];
assert.deepStrictEqual(Object.keys(harness.scenarios),required);
for(const key of required){
  const s=harness.scenarios[key];
  assert.ok(s.title&&s.message,'scenario '+key+' must have visible copy');
  assert.ok(['normal','warning','danger','action'].includes(s.tone),'scenario '+key+' must have governed tone');
}

console.log('glasses-v10-cook-ux-harness-ok');
