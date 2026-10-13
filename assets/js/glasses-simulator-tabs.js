(() => {
  'use strict';
  if (window.GelatoGlassesTabs) return;
  const $ = id => document.getElementById(id);
  const order=['overview','voice','camera','runtime','build'];
  const labels={overview:'Overview',voice:'Voice',camera:'Camera',runtime:'Runtime',build:'Build'};
  let active='overview',buttons={},panels={},mounted=false,diagnosticsBusy=false;
  const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function move(node,into){if(node&&into)into.appendChild(node);}
  function section(name){return panels[name];}
  function select(name,{focus=false}={}){
    if(!order.includes(name))return;
    active=name;
    for(const key of order){
      buttons[key].setAttribute('aria-selected',key===name?'true':'false');
      buttons[key].tabIndex=key===name?0:-1;
      panels[key].hidden=key!==name;
    }
    if(focus)buttons[name].focus();
  }
  function onKeys(e){
    const pos=order.indexOf(active);
    const next=e.key==='ArrowRight'?order[(pos+1)%order.length]:e.key==='ArrowLeft'?order[(pos+order.length-1)%order.length]:
      e.key==='Home'?order[0]:e.key==='End'?order[order.length-1]:null;
    if(next){e.preventDefault();select(next,{focus:true});}
  }
  function line(label,value){return '<div class="sgv-line"><span>'+esc(label)+'</span><strong>'+esc(value)+'</strong></div>';}
  function voiceUI(){
    // Voice diagnostics and Overview are created by this panel, not a nonexistent target.
    const v=window.GelatoGlassesVoiceDevice?.snapshot?.()||{};
    const r=window.GelatoGlassesVoiceUI?.status?.()||{};
    const summary=$('simVoiceCertificationSummary');
    const result=$('simVoiceCertificationChecks');
    const previous=v.certification;
    if(summary){
      summary.dataset.state=v.state||'off';
      summary.innerHTML=line('Recognition',v.state||'off')+
        line('Microphone',r.microphoneActive?'Active':'Off')+
        line('Explicit session',r.enabled?'Enabled':'Off')+
        line('Recovery attempts',String(v.retries??0)+'/3')+
        line('Last error',v.lastError||'none')+
        line('Certified at',previous?.at?new Date(previous.at).toLocaleTimeString():'Not run');
    }
    const badge=$('simVoiceCertBadge');
    if(badge){badge.textContent=previous?.status?.toUpperCase()||'NOT RUN';badge.dataset.state=previous?.status||'idle';}
    if(result){
      result.replaceChildren();
      for(const check of previous?.checks||[]){
        const item=document.createElement('div');item.className='sgv-check';item.dataset.status=check.status;
        const heading=document.createElement('strong');heading.textContent=check.label;
        const status=document.createElement('span');status.textContent=check.status.toUpperCase();
        const info=document.createElement('p');info.textContent=check.detail;
        item.append(heading,status,info);result.appendChild(item);
      }
      if(!previous)result.textContent='Select Run Certification to test this browser microphone. No audio is recorded or stored.';
    }
    const live=$('simOverviewVoiceStatus');
    const remote=window.GelatoVoicePresence?.snapshot?.().remote||[];
    const another=remote.find(peer=>peer.active);
    if(live)live.textContent=r.microphoneActive?'Glasses mic active · Listening':
      r.enabled?'Glasses mic '+(r.state==='starting'?'starting':r.state==='recovering'?'recovering':'paused')+
      (another?' · Another Gelato tab also listening':''):
      another?'Glasses mic off · '+(another.source==='global-agent'?'Global Agent':another.source==='timeclock'?'Time Clock':'Glasses')+' mic active in another tab':
      'Glasses mic off · Click Enable Voice';
    const input=$('simVoicePermissionNote');
    if(input)input.textContent='Microphone permission allows access; it does not mean this page is capturing audio.';

  }
  async function certify(){
    if(diagnosticsBusy)return;
    const api=window.GelatoGlassesVoiceDevice;
    if(!api){$('simVoiceCertificationChecks').textContent='Certification module unavailable. Reload the simulator.';return;}
    diagnosticsBusy=true;
    const run=$('simVoiceRunCertification');run.disabled=true;
    run.textContent='Checking…';
    try{await api.certify({probeMicrophone:true});}
    catch{const root=$('simVoiceCertificationChecks');if(root)root.textContent='Certification could not finish. Check browser permissions and try again.';}
    finally{diagnosticsBusy=false;run.disabled=false;run.textContent='Run Certification';voiceUI();}
  }
  function mount(){
    if(mounted)return;
    const rail=document.querySelector('.sim-console'),stage=document.querySelector('.sim-stage-panel');
    if(!rail||!stage)return;
    mounted=true;rail.classList.add('sgv-tabbed-console');
    const tabs=document.createElement('div');tabs.className='sgv-tabs';tabs.setAttribute('role','tablist');tabs.setAttribute('aria-label','Glasses simulator controls');
    const container=document.createElement('div');container.className='sgv-tab-contents';
    for(const name of order){
      const button=document.createElement('button');button.type='button';button.id='sgv-tab-'+name;button.textContent=labels[name];
      button.setAttribute('role','tab');button.setAttribute('aria-controls','sgv-panel-'+name);
      button.addEventListener('click',()=>select(name));
      button.addEventListener('keydown',onKeys);
      tabs.appendChild(button);buttons[name]=button;
      const panel=document.createElement('section');panel.id='sgv-panel-'+name;panel.className='sgv-tab-panel';
      panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby',button.id);
      container.appendChild(panel);panels[name]=panel;
    }
    rail.prepend(container);rail.prepend(tabs);
    const overview=section('overview'),voice=section('voice');
    const intro=document.createElement('div');intro.className='sgv-intro';
    intro.innerHTML='<strong>Live glasses display</strong><span>Live KDS updates are permission-scoped; glasses build validation still requires a paired device.</span><span id="simOverviewVoiceStatus" role="status" aria-live="polite">Checking microphone…</span><span id="simVoicePermissionNote">Browser permission is not an active microphone session.</span>';
    overview.appendChild(intro);
    // Preserve existing IDs, listeners and live objects: move nodes, never clone.
    move(stage.querySelector('.sim-status-row'),overview);
    move($('simLiveGuide'),overview);
    const nav=stage.querySelector('.sim-navigation-panel');
    if(nav){
      const groups=Array.from(nav.querySelectorAll(':scope > .sim-navigation-group'));
      const voiceBlock=document.createElement('section');voiceBlock.className='console-card sgv-voice-controls';
      voiceBlock.innerHTML='<div class="console-heading"><div><small>VOICE CONTROLS</small><strong>Hands-free navigation</strong></div></div>';
      const voiceGroup=groups.find(group=>group.querySelector('#simVoiceToggle'));
      if(voiceGroup)move(voiceGroup,voiceBlock);
      move($('simVoiceFeedback'),voiceBlock);
        voice.appendChild(voiceBlock);
      move(nav,overview);
    }
    const diagnostics=document.createElement('section');diagnostics.className='console-card sgv-voice-cert';
    diagnostics.innerHTML='<div class="console-heading"><div><small>DEVICE READINESS</small><strong>Voice Device Certification</strong></div><span id="simVoiceCertBadge">NOT RUN</span></div>'+
      '<div id="simVoiceCertificationSummary" class="sgv-status-grid" role="status" aria-live="polite"></div>'+
      '<div class="inline-actions"><button id="simVoiceRunCertification" type="button">Run Certification</button><button id="simVoiceRecover" type="button">Recover Voice</button></div>'+
      '<div class="inline-actions"><button id="simVoiceReset" type="button">Microphone Off</button><button id="simVoiceExport" type="button">Copy Report</button></div>'+
      '<p class="calibration-note">Certification requests microphone permission only after you click Run Certification. It briefly tests capture, then releases the stream. Recover Voice requires an explicit click. Full microphone off never restarts automatically.</p>'+
      '<div id="simVoiceCertificationChecks" class="sgv-checks" aria-label="Certification checks"></div>';
    voice.appendChild(diagnostics);
    diagnostics.querySelector('#simVoiceRunCertification').addEventListener('click',certify);
    diagnostics.querySelector('#simVoiceRecover').addEventListener('click',()=>window.GelatoGlassesVoiceUI?.recoverVoice?.());
    diagnostics.querySelector('#simVoiceReset').addEventListener('click',()=>window.GelatoGlassesVoiceUI?.stopVoice?.());
    diagnostics.querySelector('#simVoiceExport').addEventListener('click',()=>{
      const snapshot=window.GelatoGlassesVoiceDevice?.snapshot?.();
      if(!snapshot)return;
      // Allowlisted metadata only; no transcripts, audio, device IDs or user identifiers.
      const report={state:snapshot.state,microphoneActive:snapshot.microphoneActive,retries:snapshot.retries,lastError:snapshot.lastError,lastRecovery:snapshot.lastRecovery,certification:snapshot.certification,events:snapshot.events};
      void window.navigator?.clipboard?.writeText?.(JSON.stringify(report,null,2))?.catch?.(()=>{});
    });
    const cards=Array.from(rail.querySelectorAll('.console-card')).filter(card=>card!==diagnostics&&card!==voice.querySelector('.sgv-voice-controls'));
    for(const card of cards){
      const id=card.id||'';
      const group=id==='simControlAuditPanel'||card.querySelector('#consoleItem')||card.querySelector('#exceptions')?'overview':
        card.classList.contains('camera-card')||card.classList.contains('calibration-card')?'camera':
        card.classList.contains('hardware-runtime-card')||card.classList.contains('device-health-card')||
          card.classList.contains('runtime-recovery-card')?'runtime':'build';
      move(card,section(group));
    }
    // Legacy advanced wrapper was an organization aid, not a feature. Its cards are moved intact.
    rail.querySelector('#simAdvancedSettings')?.remove();
    window.addEventListener('gelato-glasses-voice-device-change',voiceUI);
    window.addEventListener('gelato-voice-presence-change',voiceUI);
    window.addEventListener('gelato-glasses-voice-status',voiceUI);
    select('overview');voiceUI();
  }
  window.GelatoGlassesTabs={mount,select,get active(){return active;}};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})();