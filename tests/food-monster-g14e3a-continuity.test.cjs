'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('js/global-agent.js','utf8');
function section(start,end){
  const a=source.indexOf(start),b=source.indexOf(end,a+start.length);
  assert(a>=0&&b>a,'source section: '+start);
  return source.slice(a,b);
}
const helpers=section('  function clearConversationTimer(){','  function renderConversation(){')+
  section('  function scheduleSpokenTurn(raw,isFinal){','  // G14E3A: hold a finalized')+
  section('  function suspendSpokenTurnForReconnect(){','  // Finalized speech auto-sends');
const onEnd=section('    activeRec.onend=()=>{','    try{activeRec.start();return true;}');
const onStart=section('    activeRec.onstart=()=>{','    activeRec.onresult=event=>{');
let now=1000,nextId=0;
const timers=new Map(),messages=[],sent=[];
const rec={};
const field={value:'',dataset:{}};
const S={rec,listening:true,conversation:true,micActive:true,wakeStandby:false,busy:false,
  conversationTimer:null,conversationDraft:'',recoveryPendingTurn:null,
  voiceDraft:{owned:false,previous:'',committed:''},recognitionRetryTimer:null,
  recognitionRestarts:0,recognitionFaults:0};
const ctx={S,activeRec:rec,Date:{now:()=>now},Math,
  document:{hidden:false,getElementById:()=>field},
  status:(message)=>messages.push(message),diag:()=>{},renderListening:()=>{},
  setTimeout(fn,ms){const id=++nextId;timers.set(id,{fn,ms});return id;},
  clearTimeout(id){timers.delete(id);},
  cancelRecognitionRetry(){if(S.recognitionRetryTimer!==null)timers.delete(S.recognitionRetryTimer);S.recognitionRetryTimer=null;},
  send:async(text,voice)=>{sent.push({text,voice});},
  startContinuous:()=>{},haltVoiceAfterFailure:()=>{S.conversation=false;S.listening=false;}};
vm.runInNewContext(helpers+';this.fns={scheduleSpokenTurn,suspendSpokenTurnForReconnect,resumeSpokenTurnAfterReconnect};',ctx);
vm.runInNewContext(onEnd+onStart,ctx);
let n=0;
function check(condition,message){assert.ok(condition,message);n++;}
function setup(text='test order'){
  for(const id of timers.keys())timers.delete(id);
  S.rec=rec;S.listening=true;S.conversation=true;S.busy=false;
  S.recognitionRetryTimer=null;S.recognitionRestarts=0;S.recognitionFaults=0;
  S.recoveryPendingTurn=null;S.conversationTimer=null;S.conversationDraft='';
  S.voiceDraft={owned:true,previous:text,committed:text};field.value=text;
  ctx.document.hidden=false;
  ctx.fns.scheduleSpokenTurn(text,true);
  check(S.conversationTimer!==null,'final turn has a pending send timer');
  rec.onend();
  check(S.conversationTimer===null,'mic loss cancels auto-send timer');
  check(S.recoveryPendingTurn?.text===text,'mic loss preserves finalized unsent turn');
}
(async()=>{
  setup('read order');
  check(S.listening&&S.conversation,'temporary outage preserves listening and conversation');
  check(messages.some(s=>s.includes('conversation remains on')),'reconnect message rendered');
  const disconnectedTimers=[...timers.values()].filter(t=>t.ms===1200);
  check(disconnectedTimers.length===0,'no submission timer runs while disconnected');
  check(ctx.fns.resumeSpokenTurnAfterReconnect()===true,'recovery re-arms pending turn');
  check(S.recoveryPendingTurn===null,'pending snapshot consumed exactly once');
  check(ctx.fns.resumeSpokenTurnAfterReconnect()===false,'repeated reconnect cannot rearm');
  const sendTimer=[...timers.values()].find(t=>t.ms===1200);
  check(Boolean(sendTimer),'one automatic send timer follows recovery');
  timers.delete([...timers.entries()].find(([,v])=>v===sendTimer)[0]);
  await sendTimer.fn();
  check(sent.length===1&&sent[0].text==='read order'&&sent[0].voice===true,'one recovered spoken request sent');
  setup('keep my note');
  field.value='manual edit';
  check(ctx.fns.resumeSpokenTurnAfterReconnect()===false,'manual typing cancels auto-send');
  check(![...timers.values()].some(t=>t.ms===1200),'manual edit never re-arms send timer');
  check(field.value==='manual edit','manual edit remains intact');
  setup('old request');
  now+=16001;
  check(ctx.fns.resumeSpokenTurnAfterReconnect()===false,'stale transcript never auto-sends');
  check(field.value==='old request','stale transcript remains editable');
  setup('stop immediately');
  S.listening=false;S.recoveryPendingTurn=null;
  check(ctx.fns.resumeSpokenTurnAfterReconnect()===false,'explicit microphone stop cannot auto-send');
  setup('busy interruption');
  S.busy=true;
  check(ctx.fns.resumeSpokenTurnAfterReconnect()===false,'existing request blocks auto-send');
  setup('hidden tab');
  ctx.document.hidden=true;
  check(ctx.fns.resumeSpokenTurnAfterReconnect()===false,'hidden tab cannot auto-send');
  check(source.includes('if(!on){S.recoveryPendingTurn=null;'),'manual stop clears pending snapshot in production');
  check(source.includes('if(document.hidden){S.recoveryPendingTurn=null;'),'tab hiding clears pending snapshot');
  check(source.includes('S.recoveryPendingTurn=null;clearConversationTimer();S.conversationDraft=\'\';resetVoiceDraft();'),'fatal error clears recovery state');
  check(onStart.includes('resumeSpokenTurnAfterReconnect()'),'new recognizer start activates recovery');
  check(sent.length===1,'stale, edited and stopped turns never submitted');
  console.log('FM-G14E3A recovered-turn continuity PASS ('+n+' assertions)');
})().catch(error=>{console.error(error);process.exitCode=1;});
