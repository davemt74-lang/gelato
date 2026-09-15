(() => {
  'use strict';
  if (window.GelatoWorkspaceCommandEnhancements) return;
  window.GelatoWorkspaceCommandEnhancements = true;

  const esc=(value)=>String(value??'').replace(/[&<>"']/g,(c)=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const number=(value)=>Number(value||0).toLocaleString();
  const fmt=(value)=>{
    if(!value)return '';
    try{return new Intl.DateTimeFormat('en-US',{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(new Date(String(value).replace(' ','T')));}catch{return String(value);}
  };
  let mounted=false;

  function installStyles(){
    if(document.querySelector('style[data-workspace-command-enhancements]'))return;
    const style=document.createElement('style');style.dataset.workspaceCommandEnhancements='1';style.textContent=`
      .wce-workforce{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0 0 12px}.wce-card{min-width:0;border:1px solid #deded8;border-radius:17px;background:#fff;padding:15px;box-shadow:0 8px 28px rgba(25,26,22,.04)}.wce-card-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:12px}.wce-card-head h4{margin:0;font-size:13px}.wce-card-head p{margin:4px 0 0;color:#7a7d77;font-size:9px;line-height:1.45}.wce-card-head a,.wce-card-head button{border:0;background:transparent;padding:0;color:#151815;text-decoration:none;font-size:9px;font-weight:900;cursor:pointer;white-space:nowrap}.wce-big{display:flex;align-items:end;gap:7px;margin-bottom:10px}.wce-big strong{font-size:29px;letter-spacing:-.04em}.wce-big span{font-size:9px;color:#7d807a;padding-bottom:4px}.wce-list{display:grid;gap:7px}.wce-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:9px;align-items:center;padding:8px 9px;border-radius:11px;background:#f7f7f4}.wce-row strong{display:block;font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.wce-row span,.wce-row small{display:block;color:#7d807a;font-size:8px;margin-top:2px}.wce-row time{font-size:8px;color:#8a8d87;white-space:nowrap}.wce-status{display:inline-flex!important;width:max-content!important;padding:2px 5px;border-radius:999px;background:#fff0eb;color:#a43c22!important;font-weight:900;text-transform:uppercase;letter-spacing:.04em}.wce-schedule-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:7px;margin-bottom:10px}.wce-stat{padding:9px;border-radius:11px;background:#f7f7f4}.wce-stat small{display:block;color:#7d807a;font-size:8px;text-transform:uppercase;font-weight:900}.wce-stat strong{display:block;margin-top:3px;font-size:17px}.wce-empty{padding:16px 8px;text-align:center;color:#858882;font-size:9px}.wce-warn{color:#b14427!important}.wce-workforce-loading{opacity:.55}
      @media(max-width:1050px){.wce-workforce{grid-template-columns:1fr}.wce-card{min-height:0}}@media(max-width:620px){.wce-schedule-grid{grid-template-columns:1fr 1fr}}
    `;document.head.appendChild(style);
  }

  function markup(){
    return `<section class="wce-workforce" id="wceWorkforce" aria-label="Workforce command center">
      <article class="wce-card"><div class="wce-card-head"><div><h4>New resumes</h4><p>Latest candidates from the live hiring queue.</p></div><button type="button" data-wce-resumes>Review resumes →</button></div><div class="wce-big"><strong id="wceResumeCount">0</strong><span>waiting for review</span></div><div class="wce-list" id="wceResumes"><div class="wce-empty">Loading resumes…</div></div></article>
      <article class="wce-card"><div class="wce-card-head"><div><h4>Recent employee activity</h4><p>Training, time clock, scheduling, tasks and account activity.</p></div><a href="notifications.php">Activity →</a></div><div class="wce-list" id="wceActivity"><div class="wce-empty">Loading activity…</div></div></article>
      <article class="wce-card"><div class="wce-card-head"><div><h4>Scheduling</h4><p>This week's labor, open shifts and coverage.</p></div><a href="scheduling.php">Open schedule →</a></div><div class="wce-schedule-grid"><div class="wce-stat"><small>Scheduled hours</small><strong id="wceHours">0</strong></div><div class="wce-stat"><small>Open shifts</small><strong id="wceOpen">0</strong></div><div class="wce-stat"><small>Coverage gaps</small><strong id="wceGaps">0</strong></div><div class="wce-stat"><small>Time off pending</small><strong id="wceTimeOff">0</strong></div></div><div class="wce-list" id="wceShifts"><div class="wce-empty">Loading upcoming shifts…</div></div></article>
    </section>`;
  }

  function openResumes(){
    const button=document.querySelector('[data-nav="resumes"]');
    if(button){button.click();return;}
    location.href='workspace.php#resumes';
  }

  function render(data){
    const resumes=data.resumes||{};const schedule=data.scheduling||{};const activity=data.activity||[];
    const resumeCount=document.getElementById('wceResumeCount');if(resumeCount)resumeCount.textContent=number(resumes.newCount);
    const resumeList=document.getElementById('wceResumes');if(resumeList)resumeList.innerHTML=(resumes.latest||[]).length?(resumes.latest||[]).slice(0,4).map(r=>`<div class="wce-row"><div><strong>${esc(r.name)}</strong><span>${esc(r.position)}</span><small class="wce-status">${esc(String(r.status).replaceAll('_',' '))}</small></div><time>${esc(fmt(r.submittedAt))}</time></div>`).join(''):'<div class="wce-empty">No resume submissions yet.</div>';
    const activityList=document.getElementById('wceActivity');if(activityList)activityList.innerHTML=activity.length?activity.slice(0,6).map(a=>`<div class="wce-row"><div><strong>${esc(a.actor)}</strong><span>${esc(a.label)}${a.jobTitle?' · '+esc(a.jobTitle):''}</span></div><time>${esc(fmt(a.createdAt))}</time></div>`).join(''):'<div class="wce-empty">No recent employee activity.</div>';
    const set=(id,value)=>{const node=document.getElementById(id);if(node)node.textContent=value;};
    set('wceHours',Number(schedule.scheduledHours||0).toLocaleString(undefined,{maximumFractionDigits:1}));set('wceOpen',number(schedule.openShifts));set('wceGaps',number(schedule.coverageGaps));set('wceTimeOff',number(schedule.pendingTimeOff));
    const gaps=document.getElementById('wceGaps');gaps?.classList.toggle('wce-warn',Number(schedule.coverageGaps)>0);
    const open=document.getElementById('wceOpen');open?.classList.toggle('wce-warn',Number(schedule.openShifts)>0);
    const shifts=document.getElementById('wceShifts');if(shifts)shifts.innerHTML=(schedule.nextShifts||[]).length?schedule.nextShifts.slice(0,4).map(s=>`<div class="wce-row"><div><strong>${esc(s.employee)}</strong><span>${esc(s.title)}${s.location?' · '+esc(s.location):''}</span></div><time>${esc(fmt(s.startsAt))}</time></div>`).join(''):'<div class="wce-empty">No upcoming scheduled shifts.</div>';
  }

  async function refresh(){
    const root=document.getElementById('wceWorkforce');root?.classList.add('wce-workforce-loading');
    try{const response=await fetch('api/workspace-workforce.php',{headers:{Accept:'application/json'},cache:'no-store'});const payload=await response.json().catch(()=>({ok:false,message:'Invalid workforce response.'}));if(!response.ok||!payload.ok)throw new Error(payload.message||'Workforce data unavailable.');render(payload.workforce||{});}
    catch(error){['wceResumes','wceActivity','wceShifts'].forEach(id=>{const node=document.getElementById(id);if(node)node.innerHTML=`<div class="wce-empty">${esc(error.message||'Workforce data unavailable.')}</div>`;});}
    finally{root?.classList.remove('wce-workforce-loading');}
  }

  function enhanceAgentCard(){
    const link=document.querySelector('.cmd-agent')?.closest('.cmd-panel')?.querySelector('a[href="agent-canvas.php"]');if(link)link.remove();
    const form=document.getElementById('cmdAgentForm');if(form&&!form.dataset.dynamicCanvas){form.dataset.dynamicCanvas='1';form.addEventListener('submit',()=>window.GelatoDynamicAgentCanvas?.open(),true);}
    document.querySelectorAll('[data-cmd-prompt]').forEach(button=>{if(button.dataset.dynamicCanvas)return;button.dataset.dynamicCanvas='1';button.addEventListener('click',()=>window.GelatoDynamicAgentCanvas?.open(),true);});
  }

  function mount(){
    if(mounted)return;const grid=document.querySelector('#page-dashboard .cmd-grid');if(!grid)return;
    installStyles();grid.insertAdjacentHTML('beforebegin',markup());document.querySelector('[data-wce-resumes]')?.addEventListener('click',openResumes);mounted=true;enhanceAgentCard();refresh();
  }

  const observer=new MutationObserver(()=>{mount();enhanceAgentCard();});observer.observe(document.documentElement,{childList:true,subtree:true});
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>{mount();enhanceAgentCard();},{once:true});else{mount();enhanceAgentCard();}
  window.addEventListener('focus',()=>{if(mounted)refresh();});
})();
