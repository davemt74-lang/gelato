/* FM-G8D2A: private participant Agent Workspace over the shared chat footer. */
(()=>{'use strict';
if(!document.body.classList.contains('food-monster-stage-page')||window.GelatoFoodMonsterParticipantHistory)return;
const stage=document.getElementById('food-monster-stage');if(!stage)return;
const csrf=()=>document.querySelector('meta[name="gelato-csrf"]')?.content||'';
const escape=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const state={userId:0,own:false,tab:'timeline',data:null,open:false,loadedMessages:[]};
const panel=document.createElement('section');panel.className='fm-history-panel';panel.hidden=true;
panel.setAttribute('aria-label','Participant Agent Workspace');
panel.innerHTML='<div class="fm-history-head"><strong>Participant Agent Workspace</strong><button type="button" data-fmh-close aria-label="Close history">×</button></div>'+
 '<nav class="fm-history-tabs" aria-label="Participant workspace sections">'+
 ['timeline','chat','goals'].map(t=>'<button type="button" data-fmh-tab="'+t+'">'+t[0].toUpperCase()+t.slice(1)+'</button>').join('')+'</nav>'+
 '<div class="fm-history-content" role="region" aria-live="polite"></div>';
stage.append(panel);
const content=panel.querySelector('.fm-history-content');
const note=s=>{content.textContent=String(s||'').slice(0,300)};
const fetchJSON=async(url,opt)=>{
 const r=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json',...(opt?.body?{'Content-Type':'application/json','X-CSRF-Token':csrf()}: {})},...opt});
 const j=await r.json().catch(()=>({message:'Invalid response'}));
 if(!r.ok||!j.ok)throw Error(j.message||'Participant history unavailable.');
 return j;
};
async function load(){
 const uid=state.userId;if(!uid)return;
 const data=await fetchJSON('api/food-monster-participant-history.php?action=workspace&userId='+encodeURIComponent(uid));
 if(uid!==state.userId)return;state.data=data;state.own=!!data.ownWorkspace;render();
}
async function open(userId){
 const id=Number(userId)||Number(window.GelatoGlobalAgent?.user?.id)||0;
 if(!id)return;
 state.userId=id;state.open=true;state.tab='timeline';state.data=null;state.loadedMessages=[];
 window.GelatoFoodMonsterGoals?.hide?.();
 panel.hidden=false;document.body.classList.add('fm-history-open');note('Loading participant history…');
 try{await load()}catch(e){note(e.message)}
}
function close(){state.open=false;panel.hidden=true;document.body.classList.remove('fm-history-open');state.loadedMessages=[];}
function render(){
 if(!state.open||!state.data)return;
 const d=state.data;
 for(const button of panel.querySelectorAll('[data-fmh-tab]'))button.setAttribute('aria-pressed',String(button.dataset.fmhTab===state.tab));
 if(state.tab==='timeline'){
  const events=Array.isArray(d.events)?d.events:[];
  content.innerHTML='<p class="fm-history-note">Timestamped actions. Private messages and biometric samples are not part of this feed.</p>'+
   (events.length?events.map(e=>'<article class="fm-history-event"><strong>'+escape(e.summary)+'</strong><small>'+escape(e.at)+' · '+escape(e.source)+'</small></article>').join(''):'<p>No linked participant events yet.</p>');
 }else if(state.tab==='goals'){
  const goals=Array.isArray(d.goals)?d.goals:[];
  content.innerHTML='<p class="fm-history-note">Verified account goals and current progress</p>'+
   (goals.length?goals.map(g=>'<article class="fm-history-event"><strong>'+escape(g.phase.replaceAll('_',' '))+'</strong><small>'+escape(g.status)+' · '+escape(g.updatedAt)+'</small></article>').join(''):'<p>No onboarding goals linked to this account.</p>');
 }else{
  if(!d.ownWorkspace){content.innerHTML='<p>Private Agent conversations are visible only to the signed-in participant.</p>';return;}
  const folders=d.folders||[];
  content.innerHTML='<p class="fm-history-note">Your Agent Chat threads stay private to your Gelato login. Link a current thread to organize it here.</p>'+
   '<button type="button" data-fmh-save>Save current conversation to my folder</button>'+
   (folders.length?folders.map(f=>'<article class="fm-history-event"><strong>'+escape(f.title)+'</strong><small>'+escape(f.createdAt)+'</small><div class="fm-history-actions"><button data-fmh-open-thread="'+escape(f.threadId)+'">Read</button><button data-fmh-remove="'+escape(f.id)+'">Remove link</button></div></article>').join(''):'<p>No bookmarked conversations.</p>')+
   '<section class="fm-history-messages" aria-label="Private thread messages"></section>';
 }
}
async function action(button){
 try{
  if(button.hasAttribute('data-fmh-save')){
   const tid=window.GelatoGlobalAgent?.threadId;
   if(!tid)throw Error('Start an Agent Chat conversation before saving it.');
   const title='Participant chat · '+new Date().toLocaleDateString();
   const d=await fetchJSON('api/food-monster-participant-history.php',{method:'POST',body:JSON.stringify({action:'link_thread',threadId:tid,title,csrf_token:csrf()})});
   state.data.folders=d.folders;render();
  }else if(button.hasAttribute('data-fmh-remove')){
   const d=await fetchJSON('api/food-monster-participant-history.php',{method:'POST',body:JSON.stringify({action:'remove_thread',folderId:button.dataset.fmhRemove,csrf_token:csrf()})});
   state.data.folders=d.folders;render();
  }else if(button.hasAttribute('data-fmh-open-thread')){
   const id=button.dataset.fmhOpenThread;
   const d=await fetchJSON('api/agent-workspace.php?action=thread&id='+encodeURIComponent(id));
   const messages=Array.isArray(d.messages)?d.messages:[];
   const target=panel.querySelector('.fm-history-messages');if(!target)return;
   target.innerHTML='<h3>Private conversation</h3>'+
    (messages.slice(-35).map(m=>'<p><strong>'+escape(m.role)+'</strong> · '+escape(m.content).slice(0,1300)+'</p>').join('')||'<p>No messages saved.</p>');
  }
 }catch(e){note(e.message)}
}
panel.addEventListener('click',event=>{
 const b=event.target.closest('button');if(!b)return;
 if(b.hasAttribute('data-fmh-close'))close();
 else if(b.hasAttribute('data-fmh-tab')){state.tab=b.dataset.fmhTab;render()}
 else void action(b);
});
window.GelatoFoodMonsterParticipantHistory={open,close,load,get opened(){return state.open}};
})();
