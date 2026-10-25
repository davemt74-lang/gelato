/* FM-B8D13D: own task receipts in Agent Chat + Brain, no private arguments. */
(() => {
'use strict';
if(window.GelatoAgentTasks)return;
let agent=null,thread='',tasks=[],serial=0,loading=false,error='',open=false;
const css=[
'.fm-task-panel{font:12px/1.4 Inter,system-ui,sans-serif;color:#20302b;background:#f7fbfa;border:1px solid #cfe0db;border-radius:12px;padding:10px 12px;box-sizing:border-box}',
'.fm-task-panel[hidden]{display:none!important}',
'.fm-task-panel .head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px}',
'.fm-task-panel .head strong{font-size:12px}',
'.fm-task-panel .item{display:flex;align-items:center;justify-content:space-between;gap:9px;padding:7px 0;border-top:1px solid #dce8e3}',
'.fm-task-panel .detail{flex:1;min-width:0;overflow-wrap:anywhere}',
'.fm-task-panel small{display:block;color:#667b73;font-size:10px}',
'.fm-task-panel button{font:700 10px Inter,system-ui,sans-serif;border:1px solid #dcad9e;border-radius:8px;padding:7px 9px;background:#fff;color:#8f352c;cursor:pointer}',
'.fm-task-panel button:disabled{opacity:.5;cursor:default}',
'#fmTaskChat{margin:8px 20px 0;max-height:170px;overflow:auto}',
'#fmTaskBrain{margin:10px 12px;max-height:260px;overflow:auto}',
'#fmTaskPopup{position:fixed;z-index:2147483011;left:50%;bottom:83px;transform:translateX(-50%);width:min(480px,calc(100vw - 24px));max-height:50vh;overflow:auto;box-shadow:0 12px 40px rgba(0,0,0,.25)}',
'#fmTaskToggle{background:#e6f4ed!important;color:#17694a!important}',
'@media(max-width:720px){#fmTaskChat{margin:6px 10px 0;max-height:140px}#fmTaskBrain{display:none}}'
].join('\n');
const style=document.createElement('style');style.textContent=css;document.head.append(style);
let chat=null,brain=null,popup=null,button=null;
function panel(id){
 const node=document.createElement('section');node.id=id;node.className='fm-task-panel';node.hidden=true;
 node.setAttribute('aria-live','polite');node.setAttribute('aria-label','Agent task progress');
 return node;
}
function mount(){
 const messages=document.getElementById('canvasMessages');
 if(messages){
   chat=panel('fmTaskChat');messages.parentElement?.insertBefore(chat,messages);
   const right=document.querySelector('.results .result-body');
   if(right){brain=panel('fmTaskBrain');right.parentElement?.insertBefore(brain,right);}
 }else{
   const bar=document.getElementById('gelato-agent-bar');
   if(!bar)return;
   button=document.createElement('button');button.type='button';button.id='fmTaskToggle';
   button.title='Agent task activity';button.textContent='☷';
   button.setAttribute('aria-label','Show Agent task activity');
   bar.insertBefore(button,bar.querySelector('#gaCanvas')||bar.lastElementChild);
   popup=panel('fmTaskPopup');document.body.append(popup);
   button.onclick=()=>{open=!open;render();if(open)void refresh();};
 }
}
function label(t){
 if(t.state==='unverified')return 'Outcome unverified — check affected records';
 if(t.interrupted&&t.impact!=='read')return 'Interrupted update — verification required';
 if(t.interrupted)return 'Interrupted — safe read recovery available';
 if(t.cancelRequested&&t.state==='running')return 'Stop requested — waiting for a safe boundary';
 return {running:'Running',completed:'Completed',stopped:'Stopped',cancelled:'Cancelled'}[t.state]||String(t.state);
}
function item(t,allowCancel){
 const row=document.createElement('div');row.className='item';
 const div=document.createElement('div');div.className='detail';
 const title=document.createElement('span');title.textContent=label(t);
 const small=document.createElement('small');
 small.textContent=Number(t.completedSteps||0)+'/'+Number(t.plannedSteps||0)+' verified steps · '+(t.impact==='read'?'read only':'may update records');
 div.append(title,small);row.append(div);
 if(allowCancel&&t.canRequestCancel===true&&/^msg-[a-f0-9]{20}$/.test(t.turnId||'')){
   const stop=document.createElement('button');stop.type='button';stop.textContent='Request stop';
   stop.title='Prevents future steps; cannot undo an action already executing.';
   stop.onclick=()=>void requestStop(t,stop);row.append(stop);
 }
 return row;
}
function paint(node,allowCancel){
 if(!node)return;
 node.replaceChildren();
 if(!tasks.length){node.hidden=true;return;}
 node.hidden=false;
 const head=document.createElement('div');head.className='head';
 const title=document.createElement('strong');title.textContent='Agent tasks';
 const count=document.createElement('small');count.textContent=tasks.some(t=>t.state==='running')?'Live activity':'Recent activity';
 head.append(title,count);node.append(head);
 for(const task of tasks.slice(0,4))node.append(item(task,allowCancel));
 if(error){const p=document.createElement('small');p.textContent=error;node.append(p);}
}
function render(){
 paint(chat,true);paint(brain,false);
 if(button){
  const running=tasks.filter(t=>t.state==='running').length;
  button.textContent=running?'☷ '+running:'☷';
  button.setAttribute('aria-expanded',open?'true':'false');
  popup.hidden=!open;
  if(open){
   paint(popup,true);
   if(!tasks.length){popup.hidden=false;popup.textContent=error||'No recorded Agent tasks in this conversation.';}
  }
 }
}
async function refresh(){
 const current=String(agent?.threadId||'');
 if(!/^agent-[a-f0-9]{20}$/.test(current))return;
 if(loading)return;
 if(current!==thread){thread=current;tasks=[];error='';serial++;render();}
 const stamp=serial;loading=true;
 try{
  const response=await fetch('api/food-monster-llm.php?action=task_list&threadId='+encodeURIComponent(current),{
   cache:'no-store',credentials:'same-origin',headers:{Accept:'application/json'}});
  const data=await response.json();
  if(current!==agent.threadId||stamp!==serial)return;
  if(!response.ok||!data.ok)throw Error(data.message||'Task progress unavailable.');
  tasks=Array.isArray(data.tasks)?data.tasks:[];error='';
 }catch(e){if(current===agent.threadId&&stamp===serial)error=String(e.message||'Task progress unavailable.');}
 finally{loading=false;render();if(String(agent?.threadId||'')!==thread)void refresh();}
}
async function requestStop(task,control){
 if(!agent?.csrf||!thread)return;
 control.disabled=true;control.textContent='Requesting…';
 const current=thread;
 try{
  const response=await fetch('api/food-monster-llm.php',{
   method:'POST',credentials:'same-origin',cache:'no-store',
   headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':agent.csrf},
   body:JSON.stringify({action:'task_cancel',threadId:current,turnId:task.turnId,csrf_token:agent.csrf})
  });
  const data=await response.json();
  if(!response.ok||!data.ok)throw Error(data.message||'Stop request unavailable.');
  if(current===thread){tasks=tasks.map(t=>t.turnId===task.turnId?data.task:t);error='';render();void refresh();}
 }catch(e){error=String(e.message||'Stop request unavailable.');render();}
}
function init(){
 agent=window.GelatoGlobalAgent;
 if(!agent?.user?.id)return;
 mount();window.GelatoAgentTasks={refresh};
 void refresh();
 window.addEventListener('gelato-agent-thread-change',()=>{serial++;thread='';tasks=[];render();void refresh();});
 window.addEventListener('gelato-agent-task-start',()=>{void refresh();setTimeout(()=>void refresh(),1500);});
 window.addEventListener('gelato-agent-response',()=>void refresh());
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)void refresh();});
 setInterval(()=>{if(!document.hidden)void refresh();},7500);
}
if(window.GelatoGlobalAgent?.user?.id)init();
else window.addEventListener('gelato-agent-ready',init,{once:true});
})();
