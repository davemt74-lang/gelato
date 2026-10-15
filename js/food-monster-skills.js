/* FM-G7B1: authenticated Agent Brain skill discovery; never direct tool execution. */
(() => {
 'use strict';
 if(window.GelatoFoodMonsterSkills)return;
 function setup(){
  if(window.GelatoFoodMonsterSkills)return;
  const agent=window.GelatoGlobalAgent,mascot=window.GelatoFoodMonster;
  if(!agent?.user?.id||!mascot?.root)return;
  const actions=mascot.root.querySelector('.fm-actions');if(!actions)return;
  const trigger=document.createElement('button');trigger.type='button';trigger.className='fm-skills-trigger';
  trigger.textContent='⚒';trigger.title='Gelato Agent Brain skills';
  trigger.setAttribute('aria-label','Explore Agent Brain skills');trigger.setAttribute('aria-haspopup','dialog');
  actions.append(trigger);
  const dialog=document.createElement('dialog');dialog.id='fm-skills-dialog';
  dialog.setAttribute('aria-label','Gelato Agent Brain skills');
  const heading=document.createElement('div');heading.className='fm-skills-header';
  const title=document.createElement('strong');title.textContent='Food Monster · Agent Brain';
  const close=document.createElement('button');close.type='button';close.textContent='×';close.setAttribute('aria-label','Close skills');
  heading.append(title,close);
  const note=document.createElement('p');note.className='fm-skills-note';
  note.textContent='Reviews available to your Gelato account. Selecting a skill prepares a request in Agent Chat. Changes still require existing permissions and approvals.';
  const message=document.createElement('p');message.className='fm-skills-status';message.setAttribute('role','status');
  const list=document.createElement('div');list.className='fm-skills-list';
  dialog.append(heading,note,message,list);document.body.append(dialog);
  const dismiss=()=>{if(dialog.open&&typeof dialog.close==='function')dialog.close();else dialog.removeAttribute('open');};
  close.onclick=dismiss;dialog.addEventListener('cancel',e=>{e.preventDefault();dismiss();});
  async function load(){
   message.textContent='Checking available skills…';list.replaceChildren();
   try{
    const response=await fetch('api/agent-workspace.php?action=food_monster_skills',{
      credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
    const result=await response.json();
    if(!response.ok||result.ok!==true||!Array.isArray(result.skills))throw Error('Skill catalog is unavailable. Try again.');
    message.textContent=result.skills.length?'Choose a skill to prepare a review:':'No review skills are available for this account.';
    for(const skill of result.skills){
      if(typeof skill.prompt!=='string'||typeof skill.label!=='string')continue;
      const row=document.createElement('button');row.type='button';row.className='fm-skills-choice';
      const name=document.createElement('strong');name.textContent=skill.label;
      const description=document.createElement('span');description.textContent=String(skill.description||'');
      row.append(name,description);
      row.onclick=()=>{
       const input=document.getElementById('gaInput');
       if(!input){message.textContent='Agent Chat is unavailable.';return;}
       if(input.value.trim()&&!window.confirm('Replace your unsent chat message?'))return;
       // No submission, no endpoint selection, and no model-granted tools.
       input.value=skill.prompt;input.dispatchEvent(new Event('input',{bubbles:true}));
       dismiss();input.focus();
       window.dispatchEvent(new CustomEvent('gelato-food-monster-skill-selected',{
         detail:{id:String(skill.id||''),node:String(skill.node||'')}}));
      };
      list.append(row);
    }
   }catch(error){message.textContent=String(error.message||'Could not load available skills.');}
  }
  trigger.onclick=()=>{if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','');void load();};
  window.GelatoFoodMonsterSkills={open:()=>trigger.click(),close:dismiss};
 }
 if(window.GelatoGlobalAgent?.user?.id&&window.GelatoFoodMonster)setup();
 else window.addEventListener('gelato-agent-ready',setup,{once:true});
})();
