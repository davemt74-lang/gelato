(()=>{'use strict';
/* Section 266: UI-only notification bus. No KDS writes or model assertions. */
const cfg=window.GELATO_GLASSES_SIMULATOR||{};
const STORAGE='gelato.glasses.hudNotificationSpeech.v1:u'+String(cfg.userId||0)+':rt'+String(cfg.runtimePublicId||'default');
const CATEGORY={info:'Information',success:'Success',warning:'Warning',blocking:'Blocking / error'};
const TICKETS={'ticket.started':'Ticket started','ticket.ready':'Ticket ready','ticket.paused':'Ticket paused','ticket.resumed':'Ticket resumed','ticket.finished':'Ticket finished'};
const DEFAULTS={info:false,success:true,warning:true,blocking:true,
  'ticket.started':true,'ticket.ready':true,'ticket.paused':true,'ticket.resumed':true,'ticket.finished':true};
const PRIORITY={info:1,success:2,warning:3,blocking:4};
const DURATIONS={info:2300,success:2800,warning:4200,blocking:5500};
let settings={...DEFAULTS},queue=[],active=null,timer=null;
const recent=new Map(),history=[];
const $=id=>document.getElementById(id);
const escape=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
try{const saved=JSON.parse(localStorage.getItem(STORAGE)||'{}');
  for(const key of Object.keys(DEFAULTS))if(typeof saved[key]==='boolean')settings[key]=saved[key];
}catch{}
function voiceEligible(n){
  if(!settings[n.severity]||n.event in TICKETS&&!settings[n.event])return false;
  if(n.spokenFromVoice||document.hidden||document.documentElement.classList.contains('glasses-runtime-revoked'))return false;
  if(!$('simVoiceReplies')?.checked)return false;
  return window.GelatoGlassesVoiceUI?.status?.().enabled===true;
}
function speak(n){
  if(!voiceEligible(n))return;
  const phrase=n.speech||n.message;
  if(!phrase)return;
  window.GelatoGlassesVoiceUI?.speakNotification?.(phrase);
}
function render(){
  const root=$('glassesHudNotification');if(!root)return;
  if(!active){root.hidden=true;root.textContent='';return;}
  const title=$('glassesHudNotificationTitle'),msg=$('glassesHudNotificationMessage'),meta=$('glassesHudNotificationMeta');
  root.hidden=false;root.dataset.severity=active.severity;
  if(title)title.textContent=active.ticketNumber
    ?'TICKET #'+active.ticketNumber
    :(CATEGORY[active.severity]||'NOTICE').toUpperCase();
  if(msg)msg.textContent=active.message;
  if(meta)meta.textContent=active.ticketNumber
    ?[String(active.serviceMode||'').replaceAll('_',' ').toUpperCase(),String(active.ticketStatus||'').toUpperCase()].filter(Boolean).join(' · ')
    :(active.event||'').replaceAll('.',' ').toUpperCase();
}
function advance(){
  if(timer){clearTimeout(timer);timer=null;}
  active=queue.shift()||null;render();
  if(!active)return;
  speak(active);
  timer=setTimeout(()=>{timer=null;active=null;advance();},DURATIONS[active.severity]||2800);
}
function publish(input={}){
  if(!input||typeof input!=='object')return false;
  const event=String(input.event||'').slice(0,70);
  const severity=PRIORITY[input.severity]?input.severity:'info';
  const message=String(input.message||'').trim().slice(0,200);
  if(!event||!message)return false;
  const n={event,severity,message,
    speech:String(input.speech||'').trim().slice(0,200),
    ticketNumber:String(input.ticketNumber||'').trim().slice(0,60),
    ticketPublicId:String(input.ticketPublicId||'').trim().slice(0,90),
    serviceMode:String(input.serviceMode||'').trim().slice(0,45),
    ticketStatus:String(input.ticketStatus||'').trim().slice(0,30),
    spokenFromVoice:!!input.spokenFromVoice,createdAt:Date.now()};
  const key=String(input.dedupeKey||[n.event,n.ticketPublicId,n.ticketNumber,n.message].join('|')).slice(0,320);
  for(const [k,ts] of recent)if(n.createdAt-ts>15000)recent.delete(k);
  if(recent.has(key)&&n.createdAt-recent.get(key)<7000)return false;
  recent.set(key,n.createdAt);
  history.unshift({...n,key});if(history.length>40)history.length=40;
  if(active&&PRIORITY[n.severity]>PRIORITY[active.severity]){
    queue.unshift(active);active=null;if(timer){clearTimeout(timer);timer=null;}
  }
  if(queue.length>=8)queue.pop();
  // Do not displace a higher priority alert with a later low-priority event.
  const idx=queue.findIndex(q=>PRIORITY[n.severity]>PRIORITY[q.severity]);
  if(idx<0)queue.push(n);else queue.splice(idx,0,n);
  if(!active)advance();
  return true;
}
function setVoice(key,enabled){
  if(!Object.hasOwn(DEFAULTS,key))return false;
  settings[key]=!!enabled;
  try{localStorage.setItem(STORAGE,JSON.stringify(settings));}catch{}
  return true;
}
function createControls(){
  const root=$('glassesHudNotificationVoiceSettings');if(!root)return;
  const options=[...Object.entries(CATEGORY),...Object.entries(TICKETS)];
  root.innerHTML='<p class="hud-voice-caption">Speak only while Voice and Speak replies are enabled. Visual notices remain on.</p>'+
    '<div class="hud-voice-toggle-grid">'+options.map(([id,name])=>
      '<label class="hud-voice-toggle"><span>'+escape(name)+'</span><input type="checkbox" data-hud-speech="'+escape(id)+'"'+(settings[id]?' checked':'')+' aria-label="Speak '+escape(name)+' notifications"></label>'
    ).join('')+'</div>';
  root.addEventListener('change',e=>{
    const el=e.target.closest('[data-hud-speech]');
    if(el)setVoice(el.dataset.hudSpeech,el.checked);
  });
}
function ticketDetails(item){
  const ticket=item?.ticket||{};
  return {ticketPublicId:String(ticket.checkPublicId||''),ticketNumber:String(ticket.checkNumber||''),
    serviceMode:String(ticket.serviceMode||''),};
}
const STATUS_EVENTS={'start_order':'ticket.started','ready_order':'ticket.ready',
  'hold_order':'ticket.paused','fire_order':'ticket.resumed','finish_order':'ticket.finished'};
const TITLES={'ticket.started':'started','ticket.ready':'ready','ticket.paused':'paused',
  'ticket.resumed':'resumed','ticket.finished':'finished'};
function announceTicket(event,item,opts={}){
  if(!Object.hasOwn(TITLES,event)||!item?.ticket?.checkPublicId||!item?.ticket?.checkNumber)return false;
  const t=ticketDetails(item),action=TITLES[event],noun='Ticket '+t.ticketNumber;
  const severity=event==='ticket.paused'?'warning':event==='ticket.finished'?'success':'info';
  return publish({event,severity,...t,ticketStatus:action,
    message:'Ticket #'+t.ticketNumber+' '+action+'.',speech:noun+' '+action+'.',
    spokenFromVoice:!!opts.spokenFromVoice});
}
function stateByTicket(items){
  const groups=new Map();
  for(const item of items||[]){
    const id=String(item?.ticket?.checkPublicId||'');
    if(!id)continue;
    if(!groups.has(id))groups.set(id,{item,statuses:[]});
    groups.get(id).statuses.push(String(item.status||''));
  }
  const result=new Map();
  for(const [id,g] of groups){
    const statuses=g.statuses;
    result.set(id,{item:g.item,
      stage:statuses.every(s=>s==='held')?'paused':
        statuses.every(s=>s==='ready')?'ready':
        statuses.some(s=>s==='in_progress')?'started':
        statuses.every(s=>s==='queued')?'queued':'mixed'});
  }
  return result;
}
function transitioned(before,after){
  const prev=before instanceof Map?before:stateByTicket(before);
  const next=stateByTicket(after);
  const events=[];
  for(const [id,state] of next){
    const old=prev.get(id);
    if(!old)continue; // First sight of any ticket never announces a start.
    let ev=null;
    if(old.stage==='paused'&&state.stage==='queued')ev='ticket.resumed';
    else if(old.stage!=='paused'&&state.stage==='paused')ev='ticket.paused';
    else if(old.stage!=='started'&&state.stage==='started'&&old.stage==='queued')ev='ticket.started';
    else if(old.stage!=='ready'&&state.stage==='ready')ev='ticket.ready';
    if(ev)events.push({event:ev,item:state.item});
  }
  return events; // No missing-ticket-to-finished event.
}
function clear(){
  queue=[];active=null;if(timer){clearTimeout(timer);timer=null;}render();
}
createControls();
window.GelatoGlassesHudNotifications={publish,announceTicket,transitioned,stateByTicket,
  setVoice,voiceSettings:()=>({...settings}),voiceEligible,history:()=>history.slice(),clear,
  eventForCommand:action=>STATUS_EVENTS[action]||null};
})();
