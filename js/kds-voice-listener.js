(() => {
  'use strict';
  if (window.GelatoKdsVoice) return;
  const PreferenceKey='gelato.kds.listenPreference.v2';
  const SpeechRecognition=window.SpeechRecognition||window.webkitSpeechRecognition;
  let mode='off',active=false,recognizer=null,restartTimer=null,failures=0,voiceBusy=false,pausedForSpeech=false,armedUntil=0;
  let lastBargeIn=0;
  let preferred='off';
  try { preferred=localStorage.getItem(PreferenceKey)||'off'; } catch {}
  const status=(code,message)=>{
    window.dispatchEvent(new CustomEvent('gelato-kds-voice-state',{detail:{mode,active,code,message}}));
  };
  function normalize(command) {
    return String(command||'').trim().toLowerCase().replace(/[.,!?;:]/g,' ').replace(/\s+/g,' ').trim();
  }
  function emitAnswer(text,kind='answer') {
    window.dispatchEvent(new CustomEvent('gelato-kds-voice-answer',{detail:{text:String(text||''),kind}}));
  }
  function snapshot(){return window.GelatoKdsSlots?.snapshot?.()||{slots:[],allSlots:[],total:0,page:0,locationId:0,stationPublicId:''};}
  function readSlot(slot){
    const number=Number(slot);
    if(!Number.isSafeInteger(number)||number<1)return 'Say an active order number, starting with order 1.';
    const result=window.GelatoKdsSlots?.read?.(number);
    return result?.text||'KDS board is not ready. Try again after the orders load.';
  }
  function readAll(){
    const all=snapshot().allSlots||[];
    if(!all.length)return 'There are no active KDS orders.';
    const items=all.map(item=>readSlot(item.slot));
    return items.join(' Next. ');
  }
  async function askKitchen(message){
    const ctx=snapshot();
    const response=await fetch('api/kds-agent.php',{
      method:'POST',cache:'no-store',credentials:'same-origin',
      headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':window.KDS_CONFIG?.csrf||''},
      body:JSON.stringify({csrf_token:window.KDS_CONFIG?.csrf||'',message,pageContext:{
        module:'kds',locationId:ctx.locationId,stationPublicId:ctx.stationPublicId,
        checkPublicId:'',visiblePage:ctx.page,visibleSlots:ctx.slots,allSlots:ctx.allSlots
      }})
    });
    const data=await response.json().catch(()=>({ok:false,message:'Invalid KDS response.'}));
    if(!response.ok||!data.ok)throw new Error(data.message||'KDS order lookup failed.');
    return data.answer||'No information available for that order.';
  }
  async function handleCommand(raw,{wakeRequired=false}={}) {
    let phrase=normalize(raw);
    const wake=/^(?:hey|hi|okay|ok)\s+gelato\b\s*/i;
    const woken=wake.test(phrase);
    if(woken)phrase=phrase.replace(wake,'').trim();
    const now=Date.now();
    if(wakeRequired&&!woken&&now>=armedUntil)return {ignored:true};
    if(woken&&!phrase){armedUntil=now+10000;status('wake-detected','Hey Gelato heard. Say your order command.');return {ignored:true};}
    armedUntil=0;
    const slot=phrase.match(/\b(?:order|slot)\s*(?:number\s*|#\s*)?([1-9]\d*)\b/);
    let answer;
    if(slot && /^(?:read|repeat|tell me|what|what s|whats|show|status|check|order|ticket|slot)\b/.test(phrase)){
      answer=readSlot(slot[1]);
    } else if(/^(?:read|repeat|list|tell me)\s+(?:all\s+)?(?:the\s+)?(?:active\s+|current\s+)?orders?\b/.test(phrase)){
      answer=readAll();
    } else if(/^(?:status|kitchen status|how many orders)\b/.test(phrase)){
      const current=snapshot();const count=current.allSlots?.length||0;
      answer=count+' active orders across '+Math.max(1,Math.ceil(count/8))+' KDS page(s), eight displayed at a time. '+(count?'Say read any order number from 1 to '+count+'.':'Waiting for kitchen orders.');
    } else if(/^(?:help|commands|what can i say)\b/.test(phrase)){
      answer='Say read order 1, repeat order 12, read all orders, or kitchen status. Orders are numbered across all KDS pages, eight per screen. Hands-free mode also recognizes Hey Gelato.';
    } else if(/^(?:stop listening|turn off voice|microphone off)\b/.test(phrase)){
      setMode('off');answer='Kitchen listening is off.';
    } else {
      try{answer=await askKitchen(phrase);}catch(e){answer=e.message||'Unable to query the KDS Agent.';}
    }
    emitAnswer(answer);
    return {ok:true,answer};
  }
  function cancelRestart(){if(restartTimer){clearTimeout(restartTimer);restartTimer=null;}}
  function stopRecognition(){
    cancelRestart();
    if(recognizer){const old=recognizer;recognizer=null;old.onend=null;try{old.abort();}catch{}}
    active=false;
  }
  function scheduleRestart(){
    cancelRestart();
    if(mode==='off'||document.hidden)return;
    const delay=Math.min(8000,500*Math.pow(2,Math.min(failures,4)));
    status('retrying','Microphone reconnecting in '+(delay/1000).toFixed(1)+'s…');
    restartTimer=setTimeout(()=>{restartTimer=null;startRecognition();},delay);
  }
  function startRecognition(){
    if(mode==='off'||document.hidden)return;
    if(!SpeechRecognition){mode='off';status('unsupported','Speech recognition is unavailable. Try Chrome or use the KDS controls.');return;}
    stopRecognition();
    try{
      const r=new SpeechRecognition();recognizer=r;
      r.lang='en-US';r.continuous=true;r.interimResults=true;r.maxAlternatives=1;
      r.onstart=()=>{if(recognizer!==r)return;active=true;failures=0;status('listening',mode==='wake'?'Listening for “Hey Gelato”…':'Always-on listening · say read order 12 or any active order number.');};
      r.onresult=e=>{
        if(mode==='off')return;
        for(let i=e.resultIndex;i<e.results.length;i++){
          const result=e.results[i];
          const words=result[0]?.transcript||'';
          const decision=window.GelatoVoiceTurns?.evaluate?.(words,result[0]?.confidence,{wakeRequired:mode==='wake'});
          if(decision?.kind==='interrupt'){
            if(Date.now()-lastBargeIn>1000){
              lastBargeIn=Date.now();
              window.GelatoVoiceTurns?.interrupt?.('kds-voice-barge-in');
              status('interrupted','Gelato stopped. You can give the next command.');
            }
            continue;
          }
          if(!result.isFinal||decision?.kind==='ignored')continue;
          if(decision?.interrupt)window.GelatoVoiceTurns?.interrupt?.('kds-voice-barge-in');
          status('heard','Heard: '+words);
          Promise.resolve(handleCommand(words,{wakeRequired:mode==='wake'})).catch(err=>status('error',err.message||'Voice request failed.'));
        }
      };
      r.onerror=e=>{
        if(recognizer!==r)return;
        const code=e.error||'unknown';
        if(['not-allowed','service-not-allowed','audio-capture'].includes(code)){
          mode='off';stopRecognition();status('blocked','Microphone '+code+'. Allow access in browser settings and turn listening on again.');return;
        }
        if(code!=='no-speech'&&code!=='aborted')status('error','Speech recognition: '+code);
      };
      r.onend=()=>{if(recognizer!==r)return;active=false;recognizer=null;if(mode!=='off'){failures++;scheduleRestart();}};
      r.start();status('starting','Requesting microphone…');
    }catch(e){
      active=false;recognizer=null;
      failures++;
      status('error',e?.message||'Microphone could not start');
      scheduleRestart();
    }
  }
  function setMode(next){
    if(!['off','wake','always'].includes(next))return false;
    if(next!=='off'&&!SpeechRecognition){status('unsupported','Speech recognition unavailable in this browser.');return false;}
    if(next===mode){
      if(next==='off')return true;
      setMode('off');return true;
    }
    stopRecognition();mode=next;armedUntil=0;pausedForSpeech=false;failures=0;
    try {localStorage.setItem(PreferenceKey,next);} catch {}
    if(next==='off'){status('off','Microphone off · kitchen announcements can remain on.');return true;}
    if(document.hidden){status('paused','KDS tab is hidden. Return to the KDS to resume listening.');return true;}
    startRecognition();
    return true;
  }
  function pauseForSpeech(){
    if(mode==='off')return;
    // Keep recognition armed for an explicit Hey Gelato barge-in.
    // During TTS, generic/background transcripts are rejected by the shared
    // echo guard; no protected order mutations are performed by this channel.
    pausedForSpeech=true;
    status('speaking','Gelato speaking · say “Hey Gelato stop” or press Stop speaking.');
  }
  function resumeAfterSpeech(){
    if(mode==='off'||!pausedForSpeech)return;
    pausedForSpeech=false;
    if(document.hidden){status('paused','Microphone paused while KDS is in the background.');return;}
    if(!recognizer)startRecognition();
    else status('listening',mode==='wake'?'Listening for Hey Gelato…':'Listening for a kitchen command.');
  }
  function talkOnce(){
    if(window.GelatoVoiceTurns?.speaking)window.GelatoVoiceTurns.interrupt('tap-to-interrupt');
    if(!SpeechRecognition){status('unsupported','Speech recognition unavailable; use the order reference buttons.');return false;}
    if(mode!=='off'){status('listening','Already listening · say read order 4.');return true;}
    stopRecognition();
    const r=new SpeechRecognition();recognizer=r;
    r.lang='en-US';r.continuous=false;r.interimResults=false;
    r.onstart=()=>{active=true;status('listening','Tap-to-talk · ask a kitchen question.');};
    r.onresult=e=>{for(const result of e.results)if(result.isFinal)handleCommand(result[0].transcript).catch(err=>status('error',err.message));};
    r.onerror=e=>{status('error','Microphone: '+(e.error||'unknown')+'. Try enabling permission.');};
    r.onend=()=>{if(recognizer===r){recognizer=null;active=false;status('off','Tap-to-talk complete. Always-on is off.');}};
    try{r.start();return true;}catch(e){status('error',e.message||'Microphone unavailable');return false;}
  }
  document.addEventListener('visibilitychange',()=>{
    if(document.hidden){
      stopRecognition();
      if(mode!=='off')status('paused','KDS is in the background. Microphone paused.');
    } else if(mode!=='off')startRecognition();
  });
  window.addEventListener('beforeunload',stopRecognition);
  window.GelatoKdsVoice={setMode,get mode(){return mode;},get active(){return active;},get supported(){return !!SpeechRecognition;},
    get preferred(){return preferred;},talkOnce,readSlot,readAll,handleCommand,pauseForSpeech,resumeAfterSpeech};
  setTimeout(()=>status('off',preferred!=='off'?'Saved '+preferred+' preference. Select the switch to activate the microphone for this session.':'Microphone off. Select Hey Gelato or Always listening to start.'),0);
})();
