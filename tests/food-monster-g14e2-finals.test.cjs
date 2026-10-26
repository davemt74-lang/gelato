'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const src=fs.readFileSync('js/global-agent.js','utf8');
const a=src.indexOf('  function showVoiceDraft(raw,');
const b=src.indexOf('  function speechOnce(){',a);
assert(a>=0&&b>a,'voice composer helper found');
const code=src.slice(a,b);
let now=1000;const fields={gaInput:{value:'operator typed text',dataset:{},dispatchEvent(){}}};
const events=[];const identitySamples=[];
const S={voiceDraft:{owned:false,previous:'',committed:''},recentFinalSpeech:{text:'',at:0},listening:false};
const ctx={S,document:{getElementById:id=>fields[id]},Date:{now:()=>now},
 Event:class{},CustomEvent:class{constructor(name,arg){this.detail=arg.detail;this.type=name;}},
 window:{dispatchEvent:e=>events.push(e)},diag:()=>{},clearConversationTimer:()=>{},scheduleSpokenTurn:()=>{},
 voiceSampleFromMonitor:text=>identitySamples.push(text)};
vm.runInNewContext(code+';this.voice=showVoiceDraft;',ctx);
let n=0;function check(v,m){assert.ok(v,m);n++;}
check(ctx.voice('hello',{final:true})===false,'typed input cannot be overwritten');
check(S.recentFinalSpeech.text==='', 'rejected final does not pollute duplicate memory');
fields.gaInput.value='';
check(ctx.voice('hello',{final:true})===true,'legitimate retry accepted');
check(S.recentFinalSpeech.text==='hello','accepted final remembered');
check(identitySamples.length===1&&identitySamples[0]==='hello','accepted voice final captures a current-speaker sample');
check(ctx.voice('hello',{final:true})===false,'duplicate browser final suppressed');
check(identitySamples.length===1,'duplicate final does not capture another identity event');
now+=2300;
fields.gaInput.value='';S.voiceDraft={owned:false,previous:'',committed:''};
check(ctx.voice('hello',{final:true})===true,'later deliberate repeat accepted');
check(!src.includes('JSON.stringify(S.recentFinalSpeech)'), 'no upload of dedupe memory');
console.log('FM-G14E2 final speech continuity PASS ('+n+' assertions)');
