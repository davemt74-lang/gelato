'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs');
const page=fs.readFileSync('glasses-simulator.php','utf8');
const ui=fs.readFileSync('assets/js/glasses-placement-review-ui.js','utf8');
const engine=fs.readFileSync('assets/js/glasses-hand-to-food-evidence.js','utf8');
test('read-only evidence engine loads before placement UI',()=>{
 const a=page.indexOf('src="assets/js/glasses-hand-to-food-evidence.js');
 const b=page.indexOf('src="assets/js/glasses-placement-review-ui.js');
 assert.ok(a>0&&b>a);
});
test('production object observations reach scoped hand-to-food engine',()=>{
 assert.match(ui,/motion\.observe\(\{context:motionContext,frame:/);
 assert.match(ui,/gelato-glasses-hand-to-food-review/);
 assert.match(ui,/reviewOnly:true,kdsChanged:false/);
 assert.match(ui,/motion\?\.reset\(\)/);
 assert.match(ui,/cameraGeneration\+\+/);
});
test('no persistence, network, or automatic ticket changes in recognition module',()=>{
 assert.doesNotMatch(engine,/fetch\(|XMLHttpRequest|localStorage|sessionStorage|indexedDB/);
 assert.match(engine,/verifiedPlacement:false,automatic:false/);
});
