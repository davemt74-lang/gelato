'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs');
const source=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
test('auxiliary loop adapts delays to observed inference duration',()=>{
 assert.match(source,/function scheduleAuxiliaryRecognition\(epoch,elapsedMs=0\)/);
 assert.match(source,/Math\.max\(300,Math\.min\(1400/);
 assert.match(source,/scheduleAuxiliaryRecognition\(epoch,Date\.now\(\)-capturedAt\)/);
});
test('shared model serializes inference across primary and stationary cameras',()=>{
 const aux=source.slice(source.indexOf('async function pollAuxiliaryRecognition'),source.indexOf("window.addEventListener('gelato-camera-configuration-changed'"));
 assert.match(aux,/if\(!source\|\|model\.busy\)/);
 assert.match(aux,/model\.busy=true/);
 assert.match(aux,/model\.busy=false/);
 assert.match(aux,/Date\.now\(\)-capturedAt>1600/);
 assert.match(aux,/source\.video\.srcObject!==source\.stream/);
});
test('scheduler does not authorize ingredient completion or mutate KDS',()=>{
 const aux=source.slice(source.indexOf('function scheduleAuxiliaryRecognition'),source.indexOf("window.addEventListener('gelato-camera-configuration-changed'"));
 assert.doesNotMatch(aux,/kds\.item\.transition|applyReviewedIngredient|build\.observe/);
});
