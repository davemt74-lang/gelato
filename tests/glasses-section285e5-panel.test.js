'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs');
const html=fs.readFileSync('glasses-simulator.php','utf8');
const ui=fs.readFileSync('assets/js/glasses-field-readiness-ui.js','utf8');
const core=fs.readFileSync('assets/js/glasses-field-readiness.js','utf8');
test('field engine and UI load in correct order',()=>{
 const a=html.indexOf('src="assets/js/glasses-field-readiness.js');
 const b=html.indexOf('src="assets/js/glasses-field-readiness-ui.js');
 assert.ok(a>0&&b>a);
});
test('manual entry requires reviewer attestation and measured latency',()=>{
 assert.match(ui,/glassesFieldIndependent/);
 assert.match(ui,/independentlyAdjudicated:true/);
 assert.match(ui,/A measured latency is required/);
 assert.match(html,/glassesFieldTrialId/);
 assert.match(html,/glassesFieldOutcome/);
});
test('independent evaluation never enables automatic progression',()=>{
 assert.match(core,/fieldCertified:false/);
 assert.match(core,/automaticIngredientProgressionEnabled:false/);
 assert.match(ui,/Automatic advancement OFF/);
 assert.doesNotMatch(ui,/fetch\(|XMLHttpRequest|localStorage|sessionStorage|indexedDB/);
});
test('reports are aggregate-only with no trial identifiers',()=>{
 assert.match(ui,/engine\.evaluate\(\)/);
 assert.doesNotMatch(ui,/engine\.snapshot\(\)/);
});
