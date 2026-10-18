'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const source=fs.readFileSync(require('node:path').join(__dirname,'../js/agent-canvas.js'),'utf8');
const guides=['microphone','transcription','handoff','camera','timeclock','kds','recovery'];
for(const gate of guides){
  assert.match(source,new RegExp('    '+gate+':\\['),'missing guided gate: '+gate);
}
assert.match(source,/function pilotGuidance\(gate,statusValue\)/);
assert.match(source,/Object\.prototype\.hasOwnProperty\.call\(pilotGuide,gate\)/);
assert.match(source,/pilotGuidance\(String\(g\.gate\|\|''\),String\(g\.status\|\|''\)\)/);
assert.match(source,/esc\(String\(i\+1\)\+'\. '\+step\)/);
assert.match(source,/operator-reported pass only|Operator-reported pass only/);
assert.match(source,/no automatic field certification/);
assert.match(source,/Do not include transcripts, employee identities/);
assert.doesNotMatch(source.slice(source.indexOf('const pilotGuide = Object.freeze'),source.indexOf('function structuredCard(')),/fetch\(|post\(|getUserMedia\(|localStorage\.|sessionStorage\./);
console.log('FM-G14D1 guided pilot privacy & coverage: 15 checks PASS');
