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
  function stopVoice(){
    enabled=false;listening=false;attempts=0;
    if(restartTimer){clearTimeout(restartTimer);restartTimer=null;}
    try{recognizer?.stop();}catch{}
    updateVoiceButton();
    feedback('Voice off. Manual navigation remains available.');
  }
  function beginListening(){
    if(!recognizer||!enabled||document.hidden)return;
    try{recognizer.start();}catch(e){
      if(e?.name!=='InvalidStateError'){
        enabled=false;updateVoiceButton();
        feedback('Microphone could not start: '+(e?.message||'check browser permission'),'error');
      }
    }
  }
  function startVoice(){
    if(!Recognition){
      feedback('Browser speech recognition is unavailable. Try Chrome, or use the buttons.','error');return;
    }
    if(enabled){stopVoice();return;}
    if(!recognizer){
      recognizer=new Recognition();
      recognizer.lang='en-US';
      recognizer.interimResults=false;
      recognizer.continuous=true;
      recognizer.maxAlternatives=1;
      recognizer.onstart=()=>{listening=true;attempts=0;feedback('Listening · Say “next”, “next item”, or “item added”.','listening');};
      recognizer.onresult=e=>{
        for(let i=e.resultIndex;i<e.results.length;i++){
          if(e.results[i].isFinal)commandFromSpeech(e.results[i][0].transcript);
        }
      };
      recognizer.onerror=e=>{
        const code=e.error||'unknown error';
        if(['not-allowed','service-not-allowed','audio-capture'].includes(code)){
          enabled=false;
          feedback('Voice microphone unavailable: '+code+'. Check permission or use manual navigation.','error');
          updateVoiceButton();return;
        }
        feedback('Voice paused: '+code+'. Navigation buttons still work.','error');
      };
      recognizer.onend=()=>{
        listening=false;
        if(enabled&&!document.hidden&&attempts<3){
          attempts++;
          restartTimer=setTimeout(beginListening,450);
        }else if(enabled){
          enabled=false;updateVoiceButton();
          feedback('Voice paused. Select Enable Voice to try again.','error');
        }
      };
    }
    enabled=true;attempts=0;updateVoiceButton();
    feedback('Requesting microphone access…','listening');
    beginListening();
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
    if(document.hidden&&enabled)stopVoice();
  });
  window.addEventListener('beforeunload',()=>{if(enabled)stopVoice();if('speechSynthesis' in window)window.speechSynthesis.cancel();});
  window.GelatoGlassesVoiceUI={refresh,normalizePhrase,dispatch};
  organizeControls();
  updateVoiceButton();
  refresh();
  feedback(Recognition?'Voice off. Click Enable Voice to allow microphone access.':'Speech recognition unavailable here; navigation buttons are ready.');
})();