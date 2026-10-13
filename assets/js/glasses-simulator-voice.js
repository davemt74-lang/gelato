(() => {
  'use strict';
  const $=id=>document.getElementById(id);
  const nav=()=>window.GelatoGlassesNavigation;
  const Recognition=window.SpeechRecognition||window.webkitSpeechRecognition;
  const toggle=$('simVoiceToggle'),status=$('simVoiceFeedback'),stage=$('glassesStage'),notice=$('simNavFeedback');
  let recognizer=null,enabled=false,listening=false,attempts=0,restartTimer=null,noticeTimer=null;
  function feedback(message,state='idle'){
    if(status){status.textContent=message;status.dataset.state=state;}
  }
  function announce(message){
    if(!$('simVoiceReplies')?.checked||!('speechSynthesis' in window))return;
    window.speechSynthesis.cancel();
    const utterance=new SpeechSynthesisUtterance(message);
    utterance.rate=0.96;utterance.pitch=1;utterance.volume=0.9;
    recentSpeech=String(message).trim().toLowerCase();
    utterance.onend=utterance.onerror=()=>{recentSpeech='';};
    window.speechSynthesis.speak(utterance);
  }
  function flash(message){
    if(!notice)return;
    notice.textContent=message;notice.hidden=false;
    clearTimeout(noticeTimer);
    noticeTimer=setTimeout(()=>{notice.hidden=true;},6500);
  }
  function normalizePhrase(raw){
    let phrase=String(raw||'').trim().toLowerCase().replace(/[.,!?;:]+/g,' ').replace(/\s+/g,' ');
    phrase=phrase.replace(/^(?:okay |ok |hey |gelato |glasses |please )+/g,'').trim();
    if(/^(?:stop (?:listening|voice)|turn (?:off|voice off)|microphone off)$/.test(phrase))return 'stop_voice';
    if(/^(?:help|commands|voice commands|what can i say)$/.test(phrase))return 'help';
    if(/^(?:next (?:item|order|ticket)|skip (?:item|order|ticket)|following (?:item|order))$/.test(phrase))return 'next_item';
    if(/^(?:previous (?:item|order|ticket)|last (?:item|order))$/.test(phrase))return 'previous_item';
    if(/^(?:item added|ingredient added|added item|i added it|added|mark step done)$/.test(phrase))return 'item_added';
    if(/^(?:next|next step|continue|move on|go forward)$/.test(phrase))return 'next';
    if(/^(?:previous|previous step|back|go back)$/.test(phrase))return 'previous';
    if(/^(?:repeat|repeat step|what's next|what is next|read this step)$/.test(phrase))return 'repeat';
    if(/^(?:status|order status|what is the status|how many orders|read status)$/.test(phrase))return 'status';
    return null;
  }
  function dispatch(command,{spoken=false}={}){
    if(command==='stop_voice'){stopVoice();flash('Voice listening stopped.');return;}
    if(command==='help'){
      const message='Say next for the next build instruction, next item for the next POS item, item added to acknowledge a preview step, previous, repeat, status, or stop listening.';
      feedback(message);flash(message);if(spoken)announce(message);return;
    }
    const provider=nav();
    if(!provider||typeof provider.dispatch!=='function'){
      feedback('Simulator is still starting. Try again in a moment.','error');return;
    }
    const result=provider.dispatch(command)||{ok:false,message:'Command failed.'};
    feedback(result.message,result.ok?'ready':'error');
    flash(result.message);
    if(spoken)announce(result.message);
  }
  function commandFromSpeech(transcript){
    feedback('Heard: '+transcript,'ready');
    const action=normalizePhrase(transcript);
    if(!action){
      const message='Command not recognized. Try next, next item, item added, repeat, or status.';
      feedback(message,'error');flash(message);announce(message);return;
    }
    dispatch(action,{spoken:true});
  }
  function updateVoiceButton(){
    if(!toggle)return;
    toggle.setAttribute('aria-pressed',enabled?'true':'false');
    toggle.textContent=enabled?'Stop Voice':'Enable Voice';
    if(!Recognition){
      toggle.disabled=true;
      toggle.title='Speech recognition requires a compatible Chromium browser. Navigation buttons still work.';
      feedback('Voice recognition is not supported by this browser; use the navigation buttons.','error');
    }
  }
  let sequence=0, lastError='none', recoverScheduled=false, recentSpeech='';
  const device=()=>window.GelatoGlassesVoiceDevice;
  const diag=(next,values={})=>device()?.transition?.(next,values);
  function voiceStatus(){
    return {enabled,microphoneActive:listening,state:device()?.snapshot?.().state||(listening?'active':'off'),attempts,lastError};
  }
  function clearRecovery(){
    if(restartTimer){clearTimeout(restartTimer);restartTimer=null;}
    recoverScheduled=false;
  }
  function releaseRecognition(){
    const previous=recognizer;recognizer=null;
    if(!previous)return;
    previous.onend=null;previous.onerror=null;previous.onresult=null;previous.onstart=null;
    try{previous.abort?.()||previous.stop?.();}catch{}
  }
  function stopVoice(){
    ++sequence;enabled=false;listening=false;attempts=0;lastError='none';
    clearRecovery();releaseRecognition();diag('off');updateVoiceButton();
    feedback('Microphone off. Manual navigation remains available.');
  }
  function scheduleRecovery(reason='aborted'){
    if(!enabled||document.hidden||recoverScheduled)return;
    const delay=device()?.recoveryDelay?.(attempts+1)??[750,1500,3000][attempts]??null;
    if(delay===null){
      enabled=false;listening=false;clearRecovery();releaseRecognition();
      diag('failed',{error:'start-failed',retry:attempts});
      updateVoiceButton();
      feedback('Voice recovery stopped after three retries. Select Recover Voice to try again.','error');
      return;
    }
    attempts++;
    recoverScheduled=true;
    diag('recovering',{error:reason,retry:attempts});
    feedback('Voice reconnecting (attempt '+attempts+'/3)…','recovering');
    const token=sequence;
    restartTimer=setTimeout(()=>{
      restartTimer=null;recoverScheduled=false;
      if(!enabled||document.hidden||token!==sequence)return;
      releaseRecognition();
      beginListening();
    },delay);
  }
  function beginListening(){
    if(!enabled||document.hidden||recognizer?.__starting)return;
    if(!Recognition){enabled=false;diag('failed',{error:'start-failed'});updateVoiceButton();return;}
    const token=sequence;
    const active=new Recognition();recognizer=active;active.__starting=true;
    active.lang='en-US';active.interimResults=false;active.continuous=true;active.maxAlternatives=1;
    active.onstart=()=>{
      if(recognizer!==active||!enabled||token!==sequence)return;
      active.__starting=false;listening=true;diag('active',{microphoneActive:true});
      feedback('Listening · Say “next”, “next item”, or “item added”.','listening');
    };
    active.onresult=event=>{
      if(recognizer!==active||!enabled)return;
      attempts=0; // A real result proves recovery reached a functional recognition cycle.
      for(let i=event.resultIndex;i<event.results.length;i++){
        if(event.results[i].isFinal) {
          const transcript=String(event.results[i][0]?.transcript||'');
          if(recentSpeech&&transcript.trim().toLowerCase()===recentSpeech)continue;
          commandFromSpeech(transcript);
        }
      }
    };
    active.onerror=event=>{
      if(recognizer!==active||token!==sequence||!enabled)return;
      const code=String(event.error||'unknown');
      lastError=code;
      if(['not-allowed','service-not-allowed','audio-capture'].includes(code)){
        enabled=false;listening=false;clearRecovery();releaseRecognition();
        diag('blocked',{error:code});updateVoiceButton();
        feedback('Voice blocked: '+code+'. Check the microphone and browser permissions.','error');
        return;
      }
      if(code==='no-speech'||code==='aborted'||code==='network'||code==='language-not-supported'){
        listening=false;diag('paused',{error:code});
        scheduleRecovery(code);return;
      }
      listening=false;diag('failed',{error:code});enabled=false;
      clearRecovery();releaseRecognition();updateVoiceButton();
      feedback('Voice recognition failed: '+code+'. Select Recover Voice.','error');
    };
    active.onend=()=>{
      if(recognizer!==active||token!==sequence)return;
      listening=false;active.__starting=false;
      if(!enabled)return;
      if(document.hidden){diag('paused');return;}
      scheduleRecovery(lastError==='none'?'aborted':lastError);
    };
    diag('starting',{consented:true});
    try {active.start();}
    catch(e){
      if(recognizer!==active)return;
      listening=false;lastError='start-failed';releaseRecognition();
      scheduleRecovery('start-failed');
    }
  }
  function startVoice(){
    if(!Recognition){
      feedback('Browser speech recognition is unavailable. Try Chrome, or use the buttons.','error');return false;
    }
    if(enabled){stopVoice();return true;}
    clearRecovery();releaseRecognition();++sequence;
    enabled=true;listening=false;attempts=0;lastError='none';recentSpeech='';
    diag('starting',{consented:true,retry:0});
    updateVoiceButton();feedback('Requesting browser microphone access…','listening');
    beginListening();return true;
  }
  function recoverVoice(){
    // Explicit user action: permitted even after a previous blocked/failed state.
    if(enabled)stopVoice();
    return startVoice();
  }
  function refresh(){
    if(!$('simNavContext'))return;
    const current=nav()?.context?.();
    const step=current?.step||1;
    $('simNavContext').textContent=current?.item
      ? (current.item+' · Step '+step+' · '+(current.virtual?'Preview only':'Selected station'))
      : 'Waiting for a ticket to be routed into KDS';
    if($('simViewHudOnly'))$('simViewHudOnly').setAttribute('aria-pressed',$('frameModeSelect')?.value==='none'?'true':'false');
    if($('simViewGlasses'))$('simViewGlasses').setAttribute('aria-pressed',$('frameModeSelect')?.value==='svg'?'true':'false');
  }
  function frame(value){
    const select=$('frameModeSelect');
    if(!select)return;
    select.value=value;
    select.dispatchEvent(new Event('change',{bubbles:true}));
    feedback(value==='none'?'HUD-only view. Orders and current instructions now fill the preview.':'Glasses-outline view selected.');
    refresh();
  }
  function organizeControls(){
    const aside=document.querySelector('.sim-console');
    if(!aside)return;
    const work=$('consoleItem')?.closest('.console-card');
    const audit=$('simControlAuditPanel');
    const vision=$('componentControls')?.closest('.console-card');
    const exceptions=$('exceptions')?.closest('.console-card');
    const event=$('eventLog')?.closest('.console-card');
    // Kitchen workflow comes first. Advanced experimental and calibration
    // controls remain available but do not bury live work under 12 panels.
    for(const card of [event,exceptions,vision,audit,work].filter(Boolean)){
      aside.insertBefore(card,aside.firstChild);
    }
    const primary=new Set([work,audit,vision,exceptions,event]);
    const advanced=[...aside.querySelectorAll(':scope > .console-card')].filter(card=>!primary.has(card));
    if(advanced.length&&!$('simAdvancedSettings')){
      const details=document.createElement('details');
      details.id='simAdvancedSettings';
      details.className='sim-advanced-settings';
      const summary=document.createElement('summary');
      summary.textContent='Advanced settings · Vision, calibration, models, diagnostics ('+advanced.length+')';
      const body=document.createElement('div');
      body.className='sim-advanced-body';
      details.append(summary,body);
      advanced.forEach(card=>body.appendChild(card));
      aside.appendChild(details);
    }
  }
  const commands=[
    ['simNavNext','next'],['simNavPrevious','previous'],
    ['simNavNextItem','next_item'],['simNavPreviousItem','previous_item'],
    ['simNavItemAdded','item_added'],['simNavRepeat','repeat']
  ];
  for(const [id,command] of commands)$(id)?.addEventListener('click',()=>dispatch(command));
  toggle?.addEventListener('click',startVoice);
  $('simVoiceHelp')?.addEventListener('click',()=>dispatch('help'));
  $('simViewHudOnly')?.addEventListener('click',()=>frame('none'));
  $('simViewGlasses')?.addEventListener('click',()=>frame('svg'));
  document.addEventListener('visibilitychange',()=>{
    if(document.hidden&&enabled){clearRecovery();listening=false;releaseRecognition();diag('paused');feedback('Voice paused while this tab is hidden.');}
    else if(!document.hidden&&enabled&&!listening){scheduleRecovery('aborted');}
  });
  window.navigator?.mediaDevices?.addEventListener?.('devicechange',()=>{
    if(!enabled)return;
    lastError='device-lost';listening=false;releaseRecognition();scheduleRecovery('device-lost');
  });
  window.addEventListener('beforeunload',()=>{if(enabled)stopVoice();if('speechSynthesis' in window)window.speechSynthesis.cancel();});
  window.GelatoGlassesVoiceUI={refresh,normalizePhrase,dispatch,startVoice,stopVoice,recoverVoice,status:voiceStatus};
  organizeControls();
  updateVoiceButton();
  refresh();
  feedback(Recognition?'Voice off. Click Enable Voice to allow microphone access.':'Speech recognition unavailable here; navigation buttons are ready.');
})();