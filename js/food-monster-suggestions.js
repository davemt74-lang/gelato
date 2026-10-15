/* FM-G5: user-permitted Next Moves, shown without a second recommendation engine.
 * The mascot never executes a restaurant action without a real user interaction.
 */
(() => {
'use strict';
if(window.GelatoFoodMonsterSuggestions)return;
function init(){
 if(window.GelatoFoodMonsterSuggestions)return;
 const fm=window.GelatoFoodMonster,agent=window.GelatoGlobalAgent;
 if(!fm?.root||!agent?.user?.id)return;
 const actor=fm.root.querySelector('.fm-actor'),actions=actor?.querySelector('.fm-actions');
 if(!actor||!actions)return;
 const stage=fm.isStage===true;
 const key='gelato.fm.suggestions.dismissed.v1.'+String(agent.user.id);
 let dismissed=new Set(),moves=[],current=null,pollHandle=0,blocked=false,autoShown=new Set();
 try{dismissed=new Set(JSON.parse(sessionStorage.getItem(key)||'[]'));}catch{}
 const persist=()=>{try{sessionStorage.setItem(key,JSON.stringify([...dismissed].slice(-100)));}catch{}};
 const button=document.createElement('button');button.type='button';button.className='fm-suggestion-trigger';
 button.title='Food Monster suggestions';button.setAttribute('aria-label','Review Food Monster recommendations');
 button.hidden=true;button.innerHTML='<span aria-hidden="true">!</span><i class="fm-suggestion-count" aria-hidden="true">0</i>';
 actions.append(button);
 const panel=document.createElement('section');panel.className='fm-suggestion-panel';panel.hidden=true;
 panel.setAttribute('role','region');panel.setAttribute('aria-label','Food Monster recommendation');
 panel.innerHTML='<strong class="fm-suggestion-title"></strong><span class="fm-suggestion-source"></span><p class="fm-suggestion-summary"></p><div class="fm-suggestion-actions"><button type="button" data-suggestion-action="review">Review</button><button type="button" data-suggestion-action="dismiss">Dismiss</button><button type="button" data-suggestion-action="close" aria-label="Hide this recommendation">×</button></div>';
 actor.append(panel);
 const title=panel.querySelector('.fm-suggestion-title'),source=panel.querySelector('.fm-suggestion-source'),summary=panel.querySelector('.fm-suggestion-summary');
 const clean=(raw,max)=>String(raw||'').replace(/[\u0000-\u001f\u007f]/g,' ').trim().slice(0,max);
 const score={critical:0,high:1,normal:2,low:3};
 function normalize(list){
  if(!Array.isArray(list))return [];
  const seen=new Set();
  return list.slice(0,30).map(m=>{
   if(!m||typeof m!=='object')return null;
   const id=clean(m.key,120),sev=clean(m.severity,15).toLowerCase();
   if(!id||seen.has(id)||dismissed.has(id))return null;
   seen.add(id);
   return {key:id,severity:Object.hasOwn(score,sev)?sev:'normal',
     title:clean(m.title,150),summary:clean(m.summary,350),label:clean(m.nodeLabel||m.node,100),
     prompt:clean(m.prompt||m.title,450)};
  }).filter(Boolean).sort((a,b)=>score[a.severity]-score[b.severity]).slice(0,8);
 }
 function render(){
   button.hidden=moves.length===0;
   button.querySelector('.fm-suggestion-count').textContent=String(moves.length);
   button.setAttribute('aria-label',moves.length+' Food Monster recommendation'+(moves.length===1?'':'s'));
   if(!moves.length){hide();fm.root.classList.remove('fm-suggestion-alert');return;}
   const urgent=moves.some(m=>['critical','high'].includes(m.severity));
   fm.root.classList.toggle('fm-suggestion-alert',urgent);
   if(panel.hidden)return;
   if(!current||!moves.some(m=>m.key===current.key))current=moves[0];
   renderPanel();
 }
 function renderPanel(){
   if(!current){hide();return;}
   title.textContent=current.title||'Restaurant improvement';
   source.textContent=(current.severity==='critical'?'Critical':current.severity==='high'?'Priority':'Suggestion')+' · '+(current.label||'Gelato Agent Brain');
   summary.textContent=current.summary;
   // Keep the panel within the viewport even when the user has dragged the mascot.
   const box=actor.getBoundingClientRect();
   panel.classList.toggle('fm-suggestion-align-right',box.left>innerWidth*.52);
 }
 function hide(){panel.hidden=true;current=null;fm.root.classList.remove('fm-suggestion-open');}
 function show(move=null){
  current=move||moves[0]||null;if(!current)return;
  panel.hidden=false;fm.root.classList.add('fm-suggestion-open');renderPanel();
 }
 function accept(list){
  moves=normalize(list);
  render();
  const high=moves.find(m=>['critical','high'].includes(m.severity)&&!autoShown.has(m.key));
  if(high){
   autoShown.add(high.key);
   // One visual prompt only. Never speak or execute until the user explicitly chooses Review.
   if(!fm.root.classList.contains('fm-minimized')&&document.visibilityState==='visible')
     show(high);
  }
 }
 function dismiss(){
  if(!current)return;
  const id=current.key;
  dismissed.add(id);persist();
  window.GelatoAgentNextMoves?.dismiss?.(id);
  moves=moves.filter(m=>m.key!==id);hide();render();
 }
 async function review(){
  if(!current)return;
  const choice=current;
  hide();
  if(!agent.send)return;
  const question=(choice.label?choice.label+': ':'')+
   'Review this recommendation and its evidence: '+(choice.prompt||choice.title)+
   '. Suggest the safest next steps. Do not execute consequential changes unless I explicitly confirm.';
  await agent.send(question,false);
 }
 panel.addEventListener('click',event=>{
  const action=event.target.closest('[data-suggestion-action]')?.dataset.suggestionAction;
  if(action==='close')hide();
  if(action==='dismiss')dismiss();
  if(action==='review')review().catch(()=>{});
 });
 button.addEventListener('click',()=>panel.hidden?show():hide());
 window.addEventListener('gelato-agent-next-moves-change',event=>accept(event.detail?.moves||[]));
 window.addEventListener('gelato-agent-response',event=>{
  const m=event.detail?.result?.data?.nextMoves;
  if(Array.isArray(m))accept(m);
 });
 if(window.GelatoAgentNextMoves?.moves?.length)accept(window.GelatoAgentNextMoves.moves);
 async function stageRefresh(){
   if(!stage||blocked||document.hidden)return;
   try{
    const response=await fetch('api/brain-orchestrator.php?action=snapshot',{cache:'no-store',headers:{Accept:'application/json'}});
    if(response.status===401||response.status===403){blocked=true;return;}
    const data=await response.json();
    if(response.ok&&data?.ok)accept(data.data?.nextMoves||[]);
   }catch{}
 }
 if(stage){
  stageRefresh();
  pollHandle=setInterval(stageRefresh,60000);
 }
 window.addEventListener('pagehide',()=>{if(pollHandle)clearInterval(pollHandle);});
 window.GelatoFoodMonsterSuggestions={accept,show,hide,get moves(){return moves.map(m=>({...m}))},get open(){return !panel.hidden}};
}
if(window.GelatoGlobalAgent?.user?.id&&window.GelatoFoodMonster)init();
else window.addEventListener('gelato-agent-ready',init,{once:true});
})();
