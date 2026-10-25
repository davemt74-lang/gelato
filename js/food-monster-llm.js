/* FM-B8D11: model-first Food Monster chat and task routing.
 * No API keys or tool permissions live in this browser module.
 */
(() => {
'use strict';
if(window.GelatoFoodMonsterLLM)return;
function init(){
 if(window.GelatoFoodMonsterLLM)return;
 const agent=window.GelatoGlobalAgent,mascot=window.GelatoFoodMonster;
 if(!agent?.user?.id||!mascot?.root)return;
 const preferenceKey='gelato.fm.provider.v1.'+String(agent.user.id);
 let selected='auto',status=null;
 try{selected=localStorage.getItem(preferenceKey)||'auto';}catch{}
 if(!['auto','anthropic','openai'].includes(selected))selected='auto';
 const dialog=document.createElement('dialog');dialog.id='fm-llm-dialog';dialog.setAttribute('aria-label','Food Monster language model settings');
 dialog.innerHTML='<div class="fm-llm-title"><strong>Food Monster · AI</strong><button type="button" data-fm-llm-close aria-label="Close language model settings">×</button></div>'+
 '<p>Choose the model for conversation and skill discovery. Gelato enforces your existing restaurant permissions and approvals.</p>'+
 '<label class="fm-llm-choose">Language model<select data-fm-provider><option value="auto">Auto</option><option value="anthropic">Anthropic Claude</option><option value="openai">OpenAI</option></select></label>'+
 '<p class="fm-llm-status" role="status">Checking Gelato provider configuration…</p>'+
 '<p class="fm-llm-info">Only Gelato administrators configure API keys in LLM Settings. Credentials stay on the server.</p>';
 document.body.append(dialog);
 const selector=dialog.querySelector('select'),message=dialog.querySelector('.fm-llm-status');
 selector.value=selected;
 const show=()=>{dialog.showModal?.();if(!dialog.open)dialog.setAttribute('open','');refresh();};
 const close=()=>{if(dialog.open&&dialog.close)dialog.close();else dialog.removeAttribute('open');};
 dialog.querySelector('[data-fm-llm-close]').onclick=close;
 dialog.addEventListener('cancel',e=>{e.preventDefault();close();});
 selector.addEventListener('change',()=>{
   selected=selector.value;
   try{localStorage.setItem(preferenceKey,selected);}catch{}
   render();
 });
 function render(){
   if(!status){message.textContent='Checking Gelato provider configuration…';return;}
   const available=Object.entries(status).filter(([,ready])=>ready).map(([name])=>name);
   message.textContent=available.length
      ?'Configured: '+available.map(name=>name==='anthropic'?'Claude Haiku 5.5':'OpenAI').join(' · ')+'.'
      :'No model key is configured. Some built-in tools remain available; natural-language skill discovery requires an AI provider.';
   for(const option of selector.options)
     option.disabled=option.value!=='auto'&&!status[option.value];
 }
 async function refresh(){
   try{
     const response=await fetch('api/food-monster-llm.php?action=status',{cache:'no-store',headers:{Accept:'application/json'}});
     const data=await response.json();
     if(!response.ok||!data.ok)throw Error(data.message||'Provider settings unavailable');
     status={openai:data.providers?.openai===true,anthropic:data.providers?.anthropic===true};
     if(selected!=='auto'&&!status[selected]){
       // Display the explicit selection; the server will never silently substitute
       // a different provider after the user chose one.
       message.textContent='The selected model is not configured. Switch to Auto or ask an administrator.';
     }else render();
   }catch{status=null;message.textContent='Provider configuration could not be loaded. Try again.';}
   return status;
 }
 const trigger=document.createElement('button');trigger.type='button';trigger.title='AI model';trigger.textContent='✦';
 trigger.className='fm-llm-trigger';trigger.setAttribute('aria-label','Language model settings');
 mascot.root.querySelector('.fm-actions')?.append(trigger);trigger.onclick=show;
 async function ask({text,threadId,csrf}){
   const providers=status||await refresh();
   if(!providers||(!providers.openai&&!providers.anthropic)){
     return {ok:true,skill:'agent.capabilities',
      answer:'No AI provider is configured. Built-in Gelato restaurant agents may still answer recognized requests, but natural-language skill discovery needs an administrator to configure OpenAI or Anthropic in LLM Settings.',
      sources:[],data:{offlineFallback:true}};
   }
   if(selected!=='auto'&&!providers[selected])throw Error('Selected AI provider is not configured. Open Food Monster AI model settings.');
   const response=await fetch('api/food-monster-llm.php',{
      method:'POST',cache:'no-store',
      headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':csrf},
      body:JSON.stringify({action:'chat',message:text,threadId,provider:selected,csrf_token:csrf})
   });
   const result=await response.json().catch(()=>({ok:false,message:'Invalid model response.'}));
   if(!response.ok||!result.ok)throw Error(result.message||'Could not complete model request.');
   return result;
 }
 window.GelatoFoodMonsterLLM={ask,refresh,open:show,get provider(){return selected;},get configured(){return status?{...status}:null}};
 refresh();
}
if(window.GelatoGlobalAgent?.user?.id&&window.GelatoFoodMonster)init();
else window.addEventListener('gelato-agent-ready',init,{once:true});
})();
