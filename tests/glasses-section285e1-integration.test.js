'use strict';
const assert=require('node:assert/strict');const fs=require('node:fs');const {test}=require('node:test');
const page=fs.readFileSync('glasses-simulator.php','utf8');
const runtime=fs.readFileSync('assets/js/glasses-camera-runtime.js','utf8');
test('camera calibration script loads before camera runtime',()=>{
 const a=page.indexOf('src="assets/js/glasses-camera-calibration-gate.js');
 const b=page.indexOf('src="assets/js/glasses-camera-runtime.js');
 assert.ok(a>0&&b>a,'calibration dependency must precede camera runtime');
});
test('live-camera sampling applies advisory calibration to every enabled slot',()=>{
 assert.match(runtime,/for\(let slot=1;slot<=5;slot\+\+\)/);
 assert.match(runtime,/calibration\.sample\(slot,/);
 assert.match(runtime,/calibration\.clear\(slot\)/);
 assert.match(runtime,/calibrationStatus:slot/);
 assert.match(runtime,/Calibration:/);
});
test('camera calibration cannot write a ticket or ingredient',()=>{
 const gate=fs.readFileSync('assets/js/glasses-camera-calibration-gate.js','utf8');
 assert.doesNotMatch(gate,/fetch\(|XMLHttpRequest|localStorage|sessionStorage|indexedDB/);
 assert.match(gate,/canAdvanceIngredient:false/);
 assert.match(gate,/fieldAccuracyCertified:false/);
});
