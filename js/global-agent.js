(() => {
  'use strict';

  if (window.GelatoGlobalAgent) return;

  const S = {
    csrf: '',
    thread: '',
    permissions: [],
    user: null,
    busy: false,
    voiceEventId: '',
    voiceUntil: 0,
    verifiedSpeaker: null,
    listening: false,
    savedListening: false,
    wakeStandby: false,
    micActive: false,
    proactiveVoiceEnabled: true,
    wakePhrase: 'Hey Gelato',
    lastBargeIn: 0,
    rec: null,
    poll: null,
  };

  const has = (permission) => S.permissions.includes('*') || S.permissions.includes(permission);

  async function req(url, opt = {}) {
    const response = await fetch(url, {
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        ...(opt.body ? {'Content-Type': 'application/json', 'X-CSRF-Token': S.csrf} : {}),
        ...(opt.headers || {}),
      },
      ...opt,
    });
    const data = await response.json().catch(() => ({ok: false, message: 'Invalid server response.'}));
    if (!response.ok || !data.ok) throw new Error(data.message || 'Request failed.');
    return data;
  }

  function post(url, body) {
    return req(url, {
      method: 'POST',
      body: JSON.stringify({...body, csrf_token: S.csrf}),
    });
  }

  function installCss() {
    if (document.querySelector('style[data-gelato-global-agent]')) return;
    const style = document.createElement('style');
    style.dataset.gelatoGlobalAgent = '1';
    style.textContent = `
      #gelato-agent-bar{position:fixed;left:50%;bottom:14px;transform:translateX(-50%);z-index:2147483000;width:min(900px,calc(100% - 24px));background:#fff;border:1px solid #d9d9d2;border-radius:22px;box-shadow:0 20px 56px rgba(18,20,18,.2);padding:8px;display:flex;align-items:flex-end;gap:7px;font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif}
      #gelato-agent-bar textarea{flex:1;min-height:42px;max-height:120px;resize:none;border:0;outline:0;background:transparent;padding:11px;color:#151815;font:inherit}
      #gelato-agent-bar button,#gelato-agent-bar a{width:42px;height:42px;border:0;border-radius:13px;background:#efefe9;display:grid;place-items:center;text-decoration:none;color:#161816;cursor:pointer;font-weight:900;position:relative}
      #gelato-agent-bar .ga-send{background:#171b1a;color:#fff}
      #gelato-agent-bar .ga-listen.on{background:#d94a2b;color:#fff;animation:gaPulse 1.6s infinite}
      #gelato-agent-bar .ga-listen.wake{background:#fff1db;color:#573e14}
      #gelato-agent-bar #gaMicOff{background:#fae2d9;color:#7b311b}
      #gelato-agent-voice-state{font:700 10px/1.4 Inter,system-ui,sans-serif;padding:4px 8px;color:#345847}
      #gelato-agent-status{position:fixed;left:50%;bottom:72px;transform:translateX(-50%);z-index:2147482999;max-width:min(800px,calc(100% - 34px));background:#171b1a;color:#fff;padding:9px 12px;border-radius:12px;font:600 11px/1.4 Inter,system-ui,sans-serif;display:none;box-shadow:0 12px 32px rgba(0,0,0,.18)}
      #gelato-agent-dot{width:8px;height:8px;border-radius:50%;background:#777;position:absolute;right:4px;top:4px}
      #gelato-agent-dot.on{background:#35a36f}
      @keyframes gaPulse{50%{box-shadow:0 0 0 5px rgba(217,74,43,.12)}}
      @media(max-width:620px){#gelato-agent-bar{bottom:8px;width:calc(100% - 12px);border-radius:18px}}
    `;
    document.head.appendChild(style);
  }

  function mount() {
    if (document.body?.dataset.agentCanvas === '1' || document.body?.dataset.agentVoiceOnly === '1' || document.getElementById('gelato-agent-bar')) return;
    installCss();
    document.body.insertAdjacentHTML('beforeend', `
      <div id="gelato-agent-status" role="status"></div>
      <div id="gelato-agent-bar" aria-label="Gelato Restaurant Agent">
        <button class="ga-listen" id="gaListen" title="Start Listening" aria-label="Start Listening"><span>◉</span><i id="gelato-agent-dot"></i></button>
        <button id="gaMicOff" title="Microphone Off" aria-label="Microphone Off">◼</button>
        <button id="gaMic" title="Talk once or interrupt speech">🎙</button>
        <button id="gaInterrupt" title="Stop Gelato speaking" aria-label="Stop Agent speech">■</button>
        <textarea id="gaInput" rows="1" placeholder="Ask Gelato about prep, inventory, schedules, catering, wholesale, equipment…"></textarea>
        <a href="agent-canvas.php" id="gaCanvas" title="Open Agent Canvas">▣</a>
        <button class="ga-send" id="gaSend" title="Send">↑</button>
      </div>
    `);
    const q = (id) => document.getElementById(id);
    q('gaSend').onclick = () => send(q('gaInput').value, false);
    q('gaInput').addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        send(q('gaInput').value, false);
      }
    });
    q('gaMic').onclick = talkOnce;
    q('gaInterrupt').onclick = () => interruptSpeech('button');
    q('gaListen').onclick = () => setListening(!S.listening,{keepWake:S.listening});
    q('gaMicOff').onclick = () => setListening(false,{keepWake:false});
  }

  function status(text, milliseconds = 0) {
    const value = String(text || '');
    window.dispatchEvent(new CustomEvent('gelato-agent-status', {detail: {message: value}}));
    const element = document.getElementById('gelato-agent-status') || document.getElementById('canvasStatus');
    if (!element) return;
    element.textContent = value;
    element.style.display = value ? 'block' : 'none';
    if (milliseconds && value) {
      setTimeout(() => {
        if (element.textContent === value) element.style.display = 'none';
      }, milliseconds);
    }
  }

  async function ensureThread() {
    if (S.thread) return S.thread;
    const data = await post('api/agent-workspace.php', {action: 'new_thread'});
    S.thread = data.thread.public_id;
    return S.thread;
  }

  async function append(role, content, meta = {}) {
    await ensureThread();
    return post('api/agent-workspace.php', {
      action: 'append',
      threadId: S.thread,
      role,
      content,
      ...meta,
    });
  }

  function capabilityMessage() {
    return 'I can use the restaurant tools your account is permitted to access. Try asking about your schedule, assigned prep/tasks, time clock, catering, wholesale, recipes, inventory, or equipment. Manager-only information and actions remain permission-gated.';
  }

  async function send(raw, voice = false) {
    if(!voice)interruptSpeech('typed-message');
    const text = String(raw || '').trim();
    if (!text || S.busy) return null;

    S.busy = true;
    const input = document.getElementById('gaInput');
    if (input) input.value = '';
    status('Gelato is checking your restaurant context…');

    try {
      const submitted = await append('user', text, {
        channel: voice ? 'voice' : 'text',
        voiceEventId: voice ? S.voiceEventId : '',
      });

      const route = await post('api/agent-workspace.php', {action: 'route', message: text});
      let result;

      if (route.route) {
        const response = await fetch(route.route, {
          method: 'POST',
          cache: 'no-store',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': S.csrf,
          },
          body: JSON.stringify({
            action: 'ask',
            message: text,
            voice: Boolean(voice),
            voiceEventId: voice ? S.voiceEventId : '',
            csrf_token: S.csrf,
          }),
        });
        result = await response.json().catch(() => ({ok: false, message: 'Invalid Agent tool response.'}));
        if (!response.ok || !result.ok) throw new Error(result.message || 'The requested Agent skill is unavailable.');
      } else {
        result = {
          ok: true,
          skill: 'agent.capabilities',
          answer: capabilityMessage(),
          data: null,
          sources: [],
        };
      }

      const baseAnswer = result.answer || result.reply || 'Done.';
      // Only a fresh server-verified speaker can receive a spoken name greeting.
      const greeting=voice&&submitted?.greeting?.text?String(submitted.greeting.text):'';
      const answer=greeting?greeting+' '+baseAnswer:baseAnswer;
      const agentMessage = await append('agent', answer, {
        channel: voice ? 'voice' : 'text',
        skill: result.skill || route.domain,
        tool: route.route || 'capabilities',
        structured: result.data ?? null,
        sources: Array.isArray(result.sources) ? result.sources : [],
      });

      await post('api/agent-workspace.php', {
        action: 'record_action',
        threadId: S.thread,
        messageDatabaseId: agentMessage.message.databaseId,
        skill: result.skill || route.domain,
        route: route.route || 'capabilities',
        request: {message: text, voice: Boolean(voice)},
        result: {answer, data: result.data ?? null, sources: result.sources ?? []},
      });

      status(answer, 7000);
      window.dispatchEvent(new CustomEvent('gelato-agent-response', {
        detail: {answer, result, threadId: S.thread},
      }));
      if (voice && window.speechSynthesis && has('voice.agent')) speak(answer);
      return result;
    } catch (error) {
      status(error.message || 'Gelato could not complete that request.', 6500);
      throw error;
    } finally {
      S.busy = false;
    }
  }

  function interruptSpeech(source='button') {
    const done=window.GelatoVoiceTurns?.interrupt?.(source)||false;
    if(done)status('Agent stopped speaking. Listening is ready.',2600);
    return done;
  }
  function speak(text) {
    if(window.GelatoVoiceTurns?.speak){
      window.GelatoVoiceTurns.speak(text,{owner:'global-agent'});
      return;
    }
    try {speechSynthesis.cancel();const utterance=new SpeechSynthesisUtterance(String(text).slice(0,1600));speechSynthesis.speak(utterance);} catch {}
  }

  async function captureFeatures(milliseconds = 2800) {
    if (!navigator.mediaDevices?.getUserMedia) throw new Error('Microphone capture is not available.');
    const stream = await navigator.mediaDevices.getUserMedia({
      audio: {echoCancellation: true, noiseSuppression: true, autoGainControl: false},
    });
    const context = new (window.AudioContext || window.webkitAudioContext)();
    const source = context.createMediaStreamSource(stream);
    const analyser = context.createAnalyser();
    analyser.fftSize = 1024;
    source.connect(analyser);

    const bins = new Uint8Array(analyser.frequencyBinCount);
    const bands = new Float64Array(24);
    let frames = 0;
    const timer = setInterval(() => {
      analyser.getByteFrequencyData(bins);
      for (let band = 0; band < 24; band++) {
        const low = Math.floor(band * bins.length / 24);
        const high = Math.floor((band + 1) * bins.length / 24);
        let sum = 0;
        for (let index = low; index < high; index++) sum += bins[index];
        bands[band] += sum / Math.max(1, high - low) / 255;
      }
      frames++;
    }, 80);

    await new Promise((resolve) => setTimeout(resolve, milliseconds));
    clearInterval(timer);
    stream.getTracks().forEach((track) => track.stop());
    await context.close();

    let vector = Array.from(bands, (value) => value / Math.max(1, frames));
    const norm = Math.sqrt(vector.reduce((sum, value) => sum + value * value, 0));
    if (norm < 0.03) throw new Error('Voice sample was too quiet.');
    vector = vector.map((value) => value / norm);
    return vector;
  }

  async function identifySpeaker({allowRoster=false,force=false}={}) {
    if(!has('voice.agent'))throw new Error('Voice recognition permission is not enabled for this account.');
    if(!force&&S.verifiedSpeaker&&Date.now()<S.verifiedSpeaker.verifiedUntil
      &&(!allowRoster||S.verifiedSpeaker.rosterChecked))return S.verifiedSpeaker;
    status('Checking the enrolled voice sample… speak naturally for a few seconds.');
    const features=await captureFeatures();
    const rosterChecked=Boolean(allowRoster&&has('voice.manage'));
    const data=await post('api/voice-identity.php',{action:'verify',features,matchAny:rosterChecked});
    if(!data.match?.matched)throw new Error('Voice did not match an enrolled, active profile.');
    const match=data.match;
    const identity={
      eventId:String(match.eventId||''),userId:Number(match.userId||0),
      name:String(match.displayName||S.user?.name||''),
      sessionUserMatch:Boolean(match.sessionUserMatch),
      verifiedUntil:Date.now()+13*60*1000,rosterChecked
    };
    S.verifiedSpeaker=identity;
    // A manager recognizing a different employee must never gain that
    // employee's permissions or authenticate as them in the global Agent.
    if(identity.sessionUserMatch){
      S.voiceEventId=identity.eventId;
      S.voiceUntil=identity.verifiedUntil;
    }
    status('Voice recognized: '+identity.name+'.',2700);
    window.dispatchEvent(new CustomEvent('gelato-verified-speaker',{detail:identity}));
    return identity;
  }
  async function verifyVoice() {
    if(S.voiceEventId&&Date.now()<S.voiceUntil)return true;
    const speaker=await identifySpeaker({allowRoster:false,force:true});
    if(!speaker.sessionUserMatch)throw new Error('Voice identity differs from the signed-in user.');
    document.getElementById('gelato-agent-dot')?.classList.add('on');
    return true;
  }

  async function talkOnce() {
    try {
      interruptSpeech('talk-once');
      await verifyVoice();
      status('Listening…');
      const text = await speechOnce();
      window.dispatchEvent(new CustomEvent('gelato-agent-voice-transcript', {detail: {text}}));
      return await send(text, true);
    } catch (error) {
      status(error.message, 4500);
      return null;
    }
  }

  function parseListeningCommand(text){
    const external=window.GelatoProactiveCommands?.parse?.(text,S.wakePhrase);
    if(external)return external;
    // Do not depend on dynamic script insertion order for a security-sensitive
    // wake-only command. These are the only recognized start/stop controls.
    const phrase=String(text||'').toLowerCase().replace(/[.,!?;:]/g,' ').replace(/\\s+/g,' ').trim();
    if(!phrase.startsWith('hey gelato '))return null;
    const command=phrase.slice('hey gelato '.length);
    if(/^(start listening|resume listening|enable listening)( please)?$/.test(command))return 'start';
    if(/^(stop listening|pause listening)( please)?$/.test(command))return 'stop';
    if(/^(microphone off|turn off microphone|stop microphone|mic off)( please)?$/.test(command))return 'off';
    return null;
  }
  function listeningState(){
    return window.GelatoProactiveCommands?.display?.({
      saved:S.savedListening,active:S.listening&&S.micActive,
      wake:S.wakeStandby&&S.micActive,hidden:document.hidden
    })||{code:S.listening?'listening':'off',label:S.listening?'Listening':'Microphone off'};
  }
  function renderListening(){
    const state=listeningState(),button=document.getElementById('gaListen');
    button?.classList.toggle('on',S.listening&&S.micActive);
    button?.classList.toggle('wake',S.wakeStandby&&!S.listening);
    if(button){
      button.title=S.listening?'Stop Listening · keep wake standby':S.savedListening?'Resume saved Listening':'Start Listening';
      button.setAttribute('aria-label',button.title);
      button.setAttribute('aria-pressed',S.listening?'true':'false');
    }
    window.dispatchEvent(new CustomEvent('gelato-agent-listening-change',{
      detail:{listening:S.listening,microphoneActive:S.micActive,
        wakeStandby:S.wakeStandby,savedListening:S.savedListening,
        proactiveVoiceEnabled:S.proactiveVoiceEnabled,
        code:state.code,label:state.label,message:state.message||''}
    }));
  }
  async function loadListeningPreference(){
    if(!has('agent.proactive'))return;
    try{
      const data=await req('api/proactive-agent.php?action=preferences');
      S.savedListening=!!Number(data.preferences?.listening_enabled||0);
      S.proactiveVoiceEnabled=!!Number(data.preferences?.proactive_voice_enabled||0);
      S.wakePhrase=String(data.preferences?.wake_phrase||'Hey Gelato');
      // Persisted intent != active browser mic. Never start on load.
      S.listening=false;S.wakeStandby=false;S.micActive=false;
      renderListening();
    }catch(error){status('Could not restore saved listening preference: '+error.message,4000);}
  }
  async function enableWakeStandby(){
    if(!has('agent.proactive')){status('Proactive Agent permission required.',3500);return false;}
    if(!(window.SpeechRecognition||window.webkitSpeechRecognition)){
      status('This browser cannot start wake-word recognition.',3500);return false;
    }
    try{
      if(!(S.voiceEventId&&Date.now()<S.voiceUntil))stopContinuous();
      await verifyVoice();
    }catch(error){status(error.message||'Voice verification required.',4000);return false;}
    S.listening=false;S.wakeStandby=true;
    stopPoll();
    const started=S.rec?true:startContinuous();
    if(!started){S.wakeStandby=false;renderListening();return false;}
    status('Wake standby · microphone active. Say “Hey Gelato, start listening”.',4500);
    renderListening();return true;
  }
  async function updateProactiveVoice(enabled){
    if(!has('agent.proactive'))return false;
    try{
      const data=await post('api/proactive-agent.php',{
        action:'preferences',listeningEnabled:S.savedListening,
        proactiveVoiceEnabled:!!enabled,wakePhrase:S.wakePhrase
      });
      S.proactiveVoiceEnabled=!!Number(data.preferences?.proactive_voice_enabled??Number(!!enabled));
      renderListening();return true;
    }catch(error){status(error.message,3500);return false;}
  }
  async function setListening(on,{keepWake=false}={}){
    if(on){
      if(!has('agent.proactive')){
        status('Your account does not have proactive Agent permission.',4500);
        return false;
      }
      if(!(window.SpeechRecognition||window.webkitSpeechRecognition)){
        status('Speech recognition is unavailable. Microphone remains off.',4500);
        return false;
      }
      try{
        if(!(S.voiceEventId&&Date.now()<S.voiceUntil))stopContinuous();
        await verifyVoice();
      }catch(error){status(error.message||'Please verify your enrolled voice.',4500);renderListening();return false;}
    }
    try{
      const data=await post('api/proactive-agent.php',{
        action:'preferences',listeningEnabled:!!on,
        proactiveVoiceEnabled:S.proactiveVoiceEnabled,wakePhrase:S.wakePhrase
      });
      S.savedListening=!!Number(data.preferences?.listening_enabled??Number(!!on));
      S.listening=!!on;
      S.wakeStandby=!!(keepWake||on);
      if(on){
        if(!S.rec)startContinuous();
        startPoll();
        status('Listening active. Say “Hey Gelato, stop listening” to enter wake standby.',4500);
      }else{
        stopPoll();
        if(keepWake&&S.rec){
          status('Wake standby. Say “Hey Gelato, start listening”, or Microphone Off.',4500);
        }else{
          stopContinuous();S.wakeStandby=false;
          status('Microphone off. Saved listening preference is off.',3000);
        }
      }
      renderListening();
      return true;
    }catch(error){status(error.message,4500);return false;}
  }
  function diag(kind,details){window.GelatoVoiceDiagnostics?.record?.('global-agent',kind,details);}
  function startContinuous(){
    const SpeechRecognition=window.SpeechRecognition||window.webkitSpeechRecognition;
    if(!SpeechRecognition||document.hidden||(!S.listening&&!S.wakeStandby))return false;
    stopContinuous();
    const activeRec=new SpeechRecognition();S.rec=activeRec;diag('starting');
    activeRec.lang='en-US';activeRec.continuous=true;activeRec.interimResults=true;
    activeRec.onstart=()=>{if(S.rec!==activeRec)return;S.micActive=true;diag('active');renderListening();};
    activeRec.onresult=event=>{
      if(S.rec!==activeRec)return;
      for(let index=event.resultIndex;index<event.results.length;index++){
        const result=event.results[index],text=String(result[0]?.transcript||'').trim();
        const evaluation=window.GelatoVoiceTurns?.evaluate?.(text,result[0]?.confidence,{wakeRequired:true});
        if(evaluation?.kind==='ignored'){if(['tts-echo','post-tts-echo'].includes(evaluation.reason))diag('echo-ignored');continue;}
        // Evaluate explicit stop/start controls before generic speech barge-in.
        // "Hey Gelato stop listening" must update the saved preference,
        // not merely silence the current text-to-speech response.
        const command=result.isFinal?parseListeningCommand(text):null;
        if(command){
          if(command==='start'&&!S.listening){
            void setListening(true);
          }else if(command==='stop'&&S.listening){
            void setListening(false,{keepWake:true});
          }else if(command==='off'){
            void setListening(false,{keepWake:false});
          }
          return;
        }
        if(evaluation?.kind==='interrupt'){
          S.lastBargeIn=Date.now();diag('barge-in');
          interruptSpeech('voice-barge-in');
          status('Gelato stopped speaking.',2200);
          continue;
        }
        if(!result.isFinal||!S.listening||Date.now()-S.lastBargeIn<1300)continue;
        if(/^hey\s+gelato\b/i.test(text)){
          if(evaluation?.interrupt)interruptSpeech('voice-barge-in');
          if(Date.now()>=S.voiceUntil){
            void setListening(false,{keepWake:false});
            status('Voice check expired. Click Start Listening to verify again.',4500);
            return;
          }
          window.dispatchEvent(new CustomEvent('gelato-agent-voice-transcript',{detail:{text}}));
          void send(text,true);
        }
      }
    };
    activeRec.onerror=event=>{
      if(S.rec!==activeRec)return;
      const error=String(event.error||'unknown');diag('error',{error});
      if(['not-allowed','service-not-allowed','audio-capture'].includes(error)){
        stopContinuous();S.listening=false;S.wakeStandby=false;stopPoll();diag('blocked',{error});
        status('Microphone blocked: '+error+'. Check browser settings.',5000);
        renderListening();return;
      }
      if(!['no-speech','aborted'].includes(error))status('Listening: '+error,3000);
    };
    activeRec.onend=()=>{
      if(S.rec!==activeRec)return;
      S.micActive=false;diag('ended');renderListening();
      if((S.listening||S.wakeStandby)&&!document.hidden){
        setTimeout(()=>{if(S.rec===activeRec&&(S.listening||S.wakeStandby)&&!document.hidden){try{diag('retry');activeRec.start();}catch{diag('failed',{error:'start-failed'});S.listening=false;S.wakeStandby=false;stopPoll();stopContinuous();}}},650);
      }
    };
    try{activeRec.start();return true;}catch(error){diag('failed',{error:'start-failed'});
      stopContinuous();status('Could not start microphone: '+(error.message||'unavailable'),3500);
      renderListening();return false;
    }
  }
  function stopContinuous(){
    if(S.rec){
      const old=S.rec;S.rec=null;
      old.onend=null;old.onresult=null;old.onerror=null;
      try{if(typeof old.abort==='function')old.abort();else old.stop?.();}catch{}
    }
    S.micActive=false;diag('off');renderListening();
  }

  async function poll() {
    if (!S.listening || !has('agent.proactive')) return;
    try {
      const data = await req('api/proactive-agent.php');
      for (const event of data.events || []) {
        await append('agent', event.message, {
          channel: 'proactive',
          skill: event.event_type,
          tool: 'api/proactive-agent.php',
          structured: {priority: event.priority, actionUrl: event.action_url},
        });
        status('Gelato: ' + event.message, 7000);
        window.dispatchEvent(new CustomEvent('gelato-agent-response', {
          detail: {
            answer: event.message,
            result: {skill: event.event_type, data: {priority: event.priority, actionUrl: event.action_url}},
            threadId: S.thread,
          },
        }));
        if (S.proactiveVoiceEnabled&&has('voice.agent')) speak(event.message);
      }
    } catch {}
  }

  function startPoll() {
    stopPoll();
    poll();
    S.poll = setInterval(poll, 45000);
  }

  function stopPoll() {
    if (S.poll) clearInterval(S.poll);
    S.poll = null;
  }

  function selectThread(id) {
    if (id) S.thread = String(id);
  }

  async function init() {
    try {
      const data = await req('api/agent-workspace.php?action=bootstrap');
      S.csrf = data.csrf;
      S.thread = data.activeThread;
      S.permissions = data.permissions || [];
      S.user = data.user || null;
      mount();
      await loadListeningPreference();
      window.dispatchEvent(new CustomEvent('gelato-agent-ready', {
        detail: {threadId: S.thread, user: S.user},
      }));
    } catch (error) {
      console.debug('Gelato Agent shell unavailable:', error.message);
    }
  }

  document.addEventListener('visibilitychange',()=>{
    if(document.hidden){stopContinuous();diag('paused');if(S.listening||S.wakeStandby)status('Microphone paused · tab hidden.');}
    else if(S.listening||S.wakeStandby)startContinuous();
  });
  window.addEventListener('beforeunload',()=>{stopContinuous();interruptSpeech('page-unload');});

  window.GelatoGlobalAgent = {
    send,
    setListening,
    loadListeningPreference,
    updateProactiveVoice,
    enableWakeStandby,
    get listeningStatus(){return {...listeningState(),savedListening:S.savedListening,listenActive:S.listening,microphoneActive:S.micActive,wakeStandby:S.wakeStandby};},
    talkOnce,
    interruptSpeech,
    identifySpeaker,
    get verifiedSpeaker(){return S.verifiedSpeaker&&Date.now()<S.verifiedSpeaker.verifiedUntil?S.verifiedSpeaker:null;},
    selectThread,
    get threadId() { return S.thread; },
    get user() { return S.user; },
    get permissions() { return [...S.permissions]; },
    get listening() { return S.listening; },
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
  else init();
})();