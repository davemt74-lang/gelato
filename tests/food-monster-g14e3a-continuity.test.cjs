'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const src=fs.readFileSync('js/global-agent.js','utf8');
const begin=src.indexOf('    activeRec.onend=()=>{'),end=src.indexOf('    try{activeRec.start();return true;}',begin);
assert(begin>=0&&end>begin,'end/reconnect callback present');
const code=src.slice(begin,end);
let next=0;const timers=new Map(),messages=[],diagnostics=[];
const rec={};const S={rec,micActive:true,listening:true,wakeStandby:false,conversation:true,conversationDraft:'unsent words',
 recognitionFaults:0,recognitionRestarts:0,recognitionRetryTimer:null,voiceDraft:{owned:true,previous:'unsent words',committed:'unsent words'}};
const ctx={S,activeRec:rec,document:{hidden:false},Math,
 status:(...args)=>messages.push(args),diag:(...args)=>diagnostics.push(args),renderListening(){},
 cancelRecognitionRetry(){if(S.recognitionRetryTimer!==null)timers.delete(S.recognitionRetryTimer);S.recognitionRetryTimer=null;},
 setTimeout(fn,ms){const id=++next;timers.set(id,{fn,ms});return id;},startContinuous(){ctx.restartCount++;},restartCount:0,haltVoiceAfterFailure(){S.conversation=false;S.listening=false;}};
vm.runInNewContext(code,ctx);
let n=0;function check(v,label){assert.ok(v,label);n++;}
rec.onend();
check(S.conversation===true&&S.listening===true,'transient ending preserves enabled conversation');
check(S.conversationDraft==='unsent words'&&S.voiceDraft.previous==='unsent words','unsent speech draft survives');
check(timers.size===1,'one recovery timer');
check(messages.some(x=>/conversation remains on/.test(x[0])),'recovery visible');
const [id,task]=[...timers][0];timers.delete(id);task.fn();
check(ctx.restartCount===1,'fresh restart attempted');
check(S.conversation===true,'retry retains conversation');
rec.onend();check(timers.size===1,'subsequent end schedules only one recovery');
S.listening=false;S.wakeStandby=false;const [id2,task2]=[...timers][0];timers.delete(id2);task2.fn();
check(ctx.restartCount===1,'explicit disable stops queued reconnect');
console.log('FM-G14E3A conversation recovery PASS ('+n+' assertions)');
