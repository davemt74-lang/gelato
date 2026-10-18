'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const code=fs.readFileSync('js/agent-canvas.js','utf8');
const start=code.indexOf('  function pilotDeviceSummary() {');
const end=code.indexOf('\n  function structuredCard(',start);
assert(start>=0&&end>start,'fixed-field device projection exists');
const leaked='PRIVATE_TRANSCRIPT_OR_USER_ID_98271';
const window={
  GelatoVoiceDiagnostics:{
    snapshot(){return {
      recognition:'<img src=x onerror=alert(1)>',
      microphoneActive:true,restarts:2000000,echoBlocks:3,bargeIns:2,
      lastError:'raw user content '+leaked,
      events:[{text:leaked,personId:98271,voiceprint:leaked}],
      speakerTurns:{personId:98271}
    };}
  },
  GelatoKdsVoice:{mode:'always',active:true,personId:leaked},
  GelatoCameraRuntime:{snapshot(){return {
    locationId:leaked,cameras:[
      {slot:2,active:true,state:'ready',retries:900000,employeeName:leaked},
      {slot:3,active:false,state:'<script>'+leaked+'</script>',retries:2,rawFrame:leaked}
    ]
  };}}
};
const project=vm.runInNewContext('('+code.slice(start,end).trim()+')',{window});
let count=0;
function check(ok,why){assert.ok(ok,why);count++;}
const output=project();
const printable=output.map(x=>x.join('=')).join(' ; ');
check(output.length>=10,'mic, KDS and camera status projected');
check(printable.includes('Speech recognition=unavailable'),'unknown browser recognition states fail closed');
check(printable.includes('Microphone restarts=999'),'unbounded numeric counter capped');
check(printable.includes('Speech-service error=unavailable'),'unsanitized error description discarded');
check(printable.includes('KDS listening=always'),'KDS mode shown as fixed-value enum');
check(printable.includes('Camera 2=active · ready · retries 3'),'camera reconnect retries capped');
check(printable.includes('Camera 3=inactive · unavailable · retries 2'),
  'camera state refuses unsanitized arbitrary raw text');
check(!printable.includes(leaked),'transcripts, employee identities and camera frames are not projected');
check(!printable.includes('<')&&!printable.includes('onerror'),'no HTML diagnostics injection survives');
check(!code.includes('JSON.stringify(window.GelatoVoiceDiagnostics?.snapshot'),
  'raw diagnostic snapshot is not serialized in Agent Chat code');
check(code.includes('data.followUps?.issues'),'repair summaries stay inside the pilot result sidebar');
check(code.includes('esc(issue.plan)')&&code.includes('esc(issue.failureEvidence)'),
  'operator narrative is escaped before sidebar rendering');
console.log('FM-G14C sanitized browser and camera diagnostics PASS ('+count+' assertions)');
