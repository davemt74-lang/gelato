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
    foodMonsterContext: null,
    voiceUntil: 0,
    verifiedSpeaker: null,
    usedVoiceEvents:new Set(),
    listening: false,
    savedListening: false,
    wakeStandby: false,
    micActive: false,
    proactiveVoiceEnabled: true,
    wakePhrase: 'Hey Gelato',
    lastBargeIn: 0,
    rec: null,
    poll: null,
    voiceDraft: {owned: false, previous: '', committed: ''},
    conversation: false, conversationTimer: null, conversationDraft: '',
    recentFinalSpeech: {text:'',at:0}, recognitionFaults:0, recognitionRestarts:0,
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
      #gelato-agent-bar #gaConversation.on{background:#087fb2;color:#fff}
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
        <button id="gaConversation" type="button" aria-label="Start Conversation Mode" aria-pressed="false" title="Start Conversation Mode">↔</button>
        <button id="gaInterrupt" title="Stop Gelato speaking" aria-label="Stop Agent speech">■</button>
        <textarea id="gaInput" rows="1" placeholder="Ask Gelato about prep, inventory, schedules, catering, wholesale, equipment…"></textarea>
        <a href="agent-canvas.php" id="gaCanvas" title="Open Agent Canvas">▣</a>
        <button class="ga-send" id="gaSend" title="Send">↑</button>
      </div>
    `);
    const q = (id) => document.getElementById(id);
    q('gaSend').onclick = () => send(q('gaInput').value, false);
    q('gaInput').addEventListener('input', (event) => {
      if (event.isTrusted) {resetVoiceDraft();clearConversationTimer();S.conversationDraft='';}
    });
    q('gaInput').addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        send(q('gaInput').value, false);
      }
    });
    q('gaMic').onclick = talkOnce;
    q('gaConversation').onclick = () => void setConversationMode(!S.conversation);
    q('gaInterrupt').onclick = () => interruptSpeech('button');
    q('gaListen').onclick = () => setListening(!S.listening,{keepWake:S.listening});
    q('gaMicOff').onclick = () => {void setConversationMode(false);void setListening(false,{keepWake:false});};
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

  // A quiet opt-in chip is shown only after a new server-authorized notice.
  // It must never call send(), speak(), or infer who is in the room.
  function showTrainingUpdateNotice(notice) {
    let chip=document.getElementById('gaTrainingUpdate');
    if(chip)chip.remove();
    if(!notice||notice.available!==true
       ||notice.type!=='menu_training_change'
       ||notice.suggestedCommand!=='review updated training')return;
    const bar=document.getElementById('gelato-agent-bar');
    if(!bar||!bar.parentNode)return;
    chip=document.createElement('div');
    chip.id='gaTrainingUpdate';
    chip.setAttribute('role','note');
    chip.style.cssText='display:flex;flex-wrap:wrap;align-items:center;gap:8px;max-width:100%;padding:7px 12px;margin:3px 0;font-size:12px;line-height:1.4;border:1px solid var(--border,#64748b55);border-radius:10px;background:var(--panel,#1e293b);color:inherit';
    const message=document.createElement('span');
    message.textContent='Saved training instructions changed. Review when convenient.';
    message.style.flex='1';
    chip.append(message);
    const button=document.createElement('button');
    button.type='button';
    button.textContent='Review';
    button.setAttribute('aria-label','Prepare updated training review request');
    button.onclick=()=>{
      const field=document.getElementById('gaInput');
      if(field){field.value='review updated training';field.focus();}
      chip.remove();
    };
    chip.append(button);
    const dismiss=document.createElement('button');
    dismiss.type='button';
    dismiss.textContent='×';
    dismiss.setAttribute('aria-label','Dismiss training update notice');
    dismiss.onclick=()=>chip.remove();
    chip.append(dismiss);
    bar.parentNode.insertBefore(chip,bar);
  }

  function capabilityMessage() {
    return 'I can use the restaurant tools your account is permitted to access. Try asking about your schedule, assigned prep/tasks, time clock, catering, wholesale, recipes, inventory, or equipment. Manager-only information and actions remain permission-gated.';
  }

  async function send(raw, voice = false) {
    if(!voice)interruptSpeech('typed-message');
    const text = String(raw || '').trim();
    // Never retain a prior room participant's notice across new input/speaker.
    showTrainingUpdateNotice(null);
    // Explicit spoken request; ordinary conversation NEVER requires voice verification.
    if(/^(?:(?:hey|hi)\\s+)?(?:gelato|food monster|monster)[,\\s]+(?:recognize|identify)\\s+(?:my\\s+)?voice[.!?]*$/i.test(text)
       ||/^(?:recognize|identify)\\s+(?:my\\s+)?voice[.!?]*$/i.test(text)){
      try{await identifySpeaker({allowRoster:true,force:true});}
      catch(error){status(error.message||'Speaker could not be identified. Conversation remains available.',4500);}
      const field=document.getElementById('gaInput');if(field)field.value='';
      return null;
    }
    clearConversationTimer();
    if (!text || S.busy) return null;
    // An active governed Agent Brain goal can consume an expected onboarding
    // answer before ordinary model routing. Outside that goal, chat is unchanged.
    const beforeSend=new CustomEvent('gelato-agent-before-send',{
      cancelable:true,detail:{text,voice:Boolean(voice)}
    });
    window.dispatchEvent(beforeSend);
    if(beforeSend.defaultPrevented===true){
      const field=document.getElementById('gaInput');
      if(field&&field.value.trim()===text)field.value='';
      resetVoiceDraft();S.conversationDraft='';
      return null;
    }

    S.busy = true;
    const input = document.getElementById('gaInput');
    if (input) input.value = '';
    resetVoiceDraft();
    S.conversationDraft='';
    status('Gelato is checking your restaurant context…');

    try {
      // One signed voice event is evidence for ONE completed transcript only.
      // A subsequent turn without fresh verification is deliberately unknown.
      const possibleVoiceEvent=voice&&S.verifiedSpeaker
        &&Date.now()-(S.verifiedSpeaker.verifiedAt||0)<60000
        ?String(S.verifiedSpeaker.eventId||''):'';
      const freshSpeakerEvent=/^voice-[a-zA-Z0-9-]{8,90}$/.test(possibleVoiceEvent)
        &&!S.usedVoiceEvents.has(possibleVoiceEvent)?possibleVoiceEvent:'';
      const submitted = await append('user', text, {
        channel: voice ? 'voice' : 'text',
        voiceEventId: freshSpeakerEvent,
      });
      if(freshSpeakerEvent){
        S.usedVoiceEvents.add(freshSpeakerEvent);
        if(S.usedVoiceEvents.size>32)S.usedVoiceEvents.delete(S.usedVoiceEvents.values().next().value);
      }
      // A handoff can only come from this turn's server-confirmed correlation.
      const handoff=voice&&submitted?.roomSpeaker?.handoffFromPreviousVerifiedTurn===true
        &&Boolean(submitted?.participant);
      if(voice)window.dispatchEvent(new CustomEvent('gelato-agent-speaker-turn',{
        detail:{verified:Boolean(freshSpeakerEvent)&&Boolean(submitted?.participant),
          correlated:Boolean(submitted?.roomSpeaker),unverified:!submitted?.participant,
          handoff:Boolean(handoff)}
      }));
      if(handoff)window.dispatchEvent(new CustomEvent('gelato-agent-speaker-handoff',{
        detail:{verified:true,threadId:S.thread}
      }));

      const route = await post('api/agent-workspace.php', {action: 'route', message: text, threadId: S.thread});
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
            voiceEventId: voice ? freshSpeakerEvent : '',
            // Thread comes from existing Agent Chat, never from an LLM tool suggestion.
            ...(['api/food-monster-orchestrate.php','api/food-monster-read.php','api/food-monster-approvals.php','api/food-monster-knowledge.php'].includes(route.route) ? {threadId:S.thread} : {}),
            csrf_token: S.csrf,
          }),
        });
        result = await response.json().catch(() => ({ok: false, message: 'Invalid Agent tool response.'}));
        if (!response.ok || !result.ok) throw new Error(result.message || 'The requested Agent skill is unavailable.');
      } else if (window.GelatoFoodMonsterLLM?.ask) {
        // No specialized tool route: use the server-side LLM conversation adapter.
        // Domain actions remain exclusively owned by the permission-gated routes above.
        result = await window.GelatoFoodMonsterLLM.ask({text,threadId:S.thread,csrf:S.csrf});
      } else {
        result = {
          ok: true,
          skill: 'agent.capabilities',
          answer: capabilityMessage(),
          data: null,
          sources: [],
        };
      }

      // Display only, after the canonical turn has completed routing.
      // Do not read aloud, append to the LLM answer, or auto-send a command.
      showTrainingUpdateNotice(submitted?.trainingUpdateNotice);
      const followup=submitted?.operationalFollowup;
      const createdTaskId=followup&&typeof followup.taskId==='string'
        &&/^task-[a-f0-9]{20}$/.test(followup.taskId)?followup.taskId:'';
      const followupMessage=createdTaskId
        ?(followup.alreadyCreated
          ?'I already have a task tracking that report ('+createdTaskId+').'
          :'I created an operational follow-up task ('+createdTaskId+'). It remains open for review.')
        :'';
      // Report capture succeeds independently of task creation; never imply an
      // operational action completed when its receipt is unavailable.
      const baseAnswer = result.answer || result.reply || 'Done.';
      // Only a fresh server-verified speaker can receive a spoken name greeting.
      const greeting=voice&&submitted?.greeting?.text?String(submitted.greeting.text):'';
      const suggested=submitted?.conversationalSuggestion;
      const suggestedText=(suggested
        &&['training_recap','ingredient_recap'].includes(suggested.type)
        &&Array.isArray(suggested.sourceMessageIds)
        &&suggested.sourceMessageIds.length>=2
        &&suggested.executed===false)
        ?String(suggested.text||'').slice(0,200):'';
      const answer=(greeting?greeting+' ':'')+(followupMessage?followupMessage+' ':'')+baseAnswer
        +(suggestedText?'\n\n'+suggestedText:'');
      const agentMessage = await append('agent', answer, {
        channel: voice ? 'voice' : 'text',
        skill: result.skill || route.domain,
        tool: route.route || 'capabilities',
        structured: createdTaskId ? {responseData:result.data??null,operationalFollowup:{taskId:createdTaskId,reportMessageId:String(followup.reportMessageId||''),alreadyCreated:followup.alreadyCreated===true}} : (result.data ?? null),
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
      else if(S.conversation&&voice) status('Reply ready. Browser voice output is unavailable; read the response above.',7000);
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
    // Optional identity matching is not required to listen.
    const features=await captureFeatures();
    const rosterChecked=Boolean(allowRoster&&has('voice.manage'));
    const data=await post('api/voice-identity.php',{action:'verify',features,matchAny:rosterChecked});
    if(!data.match?.matched)throw new Error('Voice did not match an enrolled, active profile.');
    const match=data.match;
    const identity={
      eventId:String(match.eventId||''),userId:Number(match.userId||0),
      name:String(match.displayName||S.user?.name||''),
      sessionUserMatch:Boolean(match.sessionUserMatch),
      verifiedAt:Date.now(),verifiedUntil:Date.now()+13*60*1000,rosterChecked
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

  // FM-G7C: Conversation Mode is opt-in and uses the existing authenticated
  // Agent Chat pathway. Server permissions and tool approvals remain authoritative.
  function clearConversationTimer(){
    if(S.conversationTimer!==null){clearTimeout(S.conversationTimer);S.conversationTimer=null;}
  }
  function renderConversation(){
    const button=document.getElementById('gaConversation');
    if(button){button.classList.toggle('on',S.conversation);button.setAttribute('aria-pressed',String(S.conversation));
      button.title=S.conversation?'Stop Conversation Mode':'Start Conversation Mode';
      button.setAttribute('aria-label',button.title);
    }
    window.dispatchEvent(new CustomEvent('gelato-agent-conversation-change',{detail:{enabled:S.conversation}}));
  }
  function ensureConversationButton(){
    if(document.getElementById('gaConversation'))return;
    const host=document.getElementById('gelato-agent-bar');if(!host)return;
    const button=document.createElement('button');button.type='button';button.id='gaConversation';button.textContent='↔';
    button.onclick=()=>void setConversationMode(!S.conversation);
    const mic=document.getElementById('gaMic');
    if(mic?.parentElement===host)mic.insertAdjacentElement('afterend',button);else host.prepend(button);
    renderConversation();
  }
  async function setConversationMode(enabled){
    if(!enabled){S.conversation=false;clearConversationTimer();S.conversationDraft='';interruptSpeech('conversation-off');renderConversation();
      status(S.listening?'Conversation Mode off · Listening still auto-sends after you pause.':'Conversation Mode off.',3000);return true;}
    if(!has('voice.agent')){status('Conversation Mode requires Voice Agent permission.',4500);return false;}
    if(!window.speechSynthesis){status('This browser cannot speak Agent responses.',4500);return false;}
    const ready=S.listening||await setListening(true);
    if(!ready){renderConversation();return false;}
    S.conversation=true;S.conversationDraft='';clearConversationTimer();renderConversation();
    status('Conversation Mode on · speak naturally and pause to send. Interrupt Gelato by talking.',6500);
    return true;
  }
  function scheduleSpokenTurn(raw,isFinal){
    // Both Listening and Conversation Mode auto-send a finalized turn.
    // Conversation Mode also adds interruption/turn-taking controls.
    if(!S.listening||S.busy||!isFinal)return;
    const text=String(raw||'').trim();if(!text)return;
    S.conversationDraft=String(document.getElementById('gaInput')?.value||text).trim();
    clearConversationTimer();
    S.conversationTimer=setTimeout(async()=>{
      S.conversationTimer=null;
      if(!S.listening||S.busy||document.hidden||!S.conversationDraft)return;
      const phrase=S.conversationDraft;S.conversationDraft='';
      // A manual edit always takes priority over automatic submission.
      const field=document.getElementById('gaInput');
      if(!S.voiceDraft.owned||field?.value!==S.voiceDraft.previous)return;
      try{await send(phrase,true);}catch(error){status('Conversation request failed: '+String(error.message||error),6500);}
    },1200);
  }
  // Finalized speech auto-sends while Listening is active; typed text never auto-sends.
  function resetVoiceDraft(){
    S.voiceDraft={owned:false,previous:'',committed:''};
  }
  function showVoiceDraft(raw,{final=false,once=false,autoSend=true}={}){
    const field=document.getElementById('gaInput');
    const text=String(raw||'').trim().slice(0,3500);
    if(!field||!text)return false;
    // Repeated browser final callbacks are not a second human utterance.
    if(final&&autoSend&&S.recentFinalSpeech.text===text.toLowerCase()
      &&Date.now()-S.recentFinalSpeech.at<2200){diag('duplicate-final-suppressed');return false;}
    if(final&&autoSend)S.recentFinalSpeech={text:text.toLowerCase(),at:Date.now()};
    let draft=S.voiceDraft;
    if(draft.owned&&field.value!==draft.previous){
      resetVoiceDraft();
      if(field.value.trim()!=='')return false;
      draft=S.voiceDraft;
    }
    if(!draft.owned&&field.value.trim()!=='')return false;
    const value=(draft.committed?draft.committed+' ':'')+text;
    field.value=value;field.dataset.voiceInterim=final?'false':'true';
    draft.owned=true;draft.previous=value;
    if(final)draft.committed=value;
    field.dispatchEvent(new Event('input',{bubbles:true}));
    if(S.listening&&!once){clearConversationTimer();if(final&&autoSend)scheduleSpokenTurn(value,true);}
    window.dispatchEvent(new CustomEvent('gelato-agent-dictation',{
      detail:{text:value,final,source:'browser-speech-recognition'}
    }));
    return true;
  }
  function speechOnce(){
    const Recognition=window.SpeechRecognition||window.webkitSpeechRecognition;
    if(!Recognition)return Promise.reject(new Error('Speech recognition is unavailable in this browser.'));
    if(S.rec)stopContinuous();
    return new Promise((resolve,reject)=>{
      const rec=new Recognition();let settled=false,finalText='',timer=0;
      rec.lang='en-US';rec.continuous=false;rec.interimResults=true;rec.maxAlternatives=1;
      function finish(error){
        if(settled)return;settled=true;clearTimeout(timer);
        rec.onresult=rec.onerror=rec.onend=null;
        try{rec.stop?.();}catch{}
        if(error)reject(error);else if(finalText)resolve(finalText);
        else reject(new Error('No speech recognized. Check microphone permissions and try again.'));
      }
      rec.onresult=event=>{
        for(let i=event.resultIndex||0;i<event.results.length;i++){
          const result=event.results[i],spoken=String(result[0]?.transcript||'').trim();
          if(!spoken)continue;
          showVoiceDraft(spoken,{final:!!result.isFinal,once:true});
          if(result.isFinal){finalText=spoken;finish();return;}
        }
      };
      rec.onerror=event=>finish(new Error('Speech recognition: '+String(event.error||'unavailable')));
      rec.onend=()=>finish();
      timer=setTimeout(()=>finish(new Error('No speech detected. Check your microphone and try again.')),16000);
      try{rec.start();}catch(error){finish(error);}
    });
  }
  async function talkOnce() {
    if(S.conversation){interruptSpeech('conversation-mic');return;}
    const shouldResume=S.listening||S.wakeStandby;
    try {
      interruptSpeech('talk-once');
      status('Listening… speaking will appear in the chat bar.');
      const text = await speechOnce();
      window.dispatchEvent(new CustomEvent('gelato-agent-voice-transcript', {detail: {text}}));
      return await send(text, true);
    } catch (error) {
      status(error.message||'Could not transcribe speech.', 4500);
      return null;
    } finally {
      if(shouldResume&&!document.hidden&&!S.rec)startContinuous();
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
    window.GelatoVoicePresence?.report?.('global-agent',!!S.micActive,S.micActive?(S.listening?'active':'wake_standby'):'off');
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
    // Wake standby uses ordinary browser speech recognition, not a biometric challenge.
    S.listening=false;S.wakeStandby=true;
    clearConversationTimer();S.conversationDraft='';
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
      // No separate voice sample before transcription.
    }
    try{
      const data=await post('api/proactive-agent.php',{
        action:'preferences',listeningEnabled:!!on,
        proactiveVoiceEnabled:S.proactiveVoiceEnabled,wakePhrase:S.wakePhrase
      });
      S.savedListening=!!Number(data.preferences?.listening_enabled??Number(!!on));
      S.listening=!!on;
      if(on){S.recognitionFaults=0;S.recognitionRestarts=0;}
      if(!on){clearConversationTimer();S.conversationDraft='';if(S.conversation){S.conversation=false;renderConversation();}}
      S.wakeStandby=!!(keepWake||on);
      if(on){
        const started=S.rec?true:startContinuous();
        if(!started){S.listening=false;S.wakeStandby=false;stopPoll();renderListening();return false;}
        startPoll();
        status('Listening active · speech sends automatically after you pause. Say “Hey Gelato” for commands.',5500);
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
  // A fatal microphone failure must stop both visible voice modes and invalidate
  // all timers; previously Conversation Mode stayed on while mic was disabled.
  function haltVoiceAfterFailure(){
    clearConversationTimer();S.conversationDraft='';resetVoiceDraft();
    S.conversation=false;S.listening=false;S.wakeStandby=false;
    stopPoll();stopContinuous();renderConversation();renderListening();
  }
  function startContinuous(){
    const SpeechRecognition=window.SpeechRecognition||window.webkitSpeechRecognition;
    if(!SpeechRecognition||document.hidden||(!S.listening&&!S.wakeStandby))return false;
    stopContinuous();
    let activeRec;
    try{activeRec=new SpeechRecognition();}catch(error){status('Could not initialize browser speech recognition.',4500);return false;}
    S.rec=activeRec;diag('starting');
    activeRec.lang='en-US';activeRec.continuous=true;activeRec.interimResults=true;
    activeRec.onstart=()=>{if(S.rec!==activeRec)return;S.micActive=true;diag('active');renderListening();};
    activeRec.onresult=event=>{
      if(S.rec!==activeRec)return;
      S.recognitionFaults=0;
      for(let index=event.resultIndex;index<event.results.length;index++){
        const result=event.results[index],text=String(result[0]?.transcript||'').trim();
        if(result.isFinal&&text)S.recognitionRestarts=0;
        const evaluation=window.GelatoVoiceTurns?.evaluate?.(text,result[0]?.confidence,{wakeRequired:!S.listening});
        // FM-G7B2E: loudspeaker playback is never proof of a new user turn.
        // Do not treat "wake-required-while-speaking" as an interruption:
        // that classification is the *reason to reject* ambient/speaker audio.
        // Web Speech does not give us an isolated echo-cancelled microphone feed,
        // so hands-free barge-in during TTS must use an explicit wake phrase.
        const agentSpeaking=!!(window.GelatoVoiceTurns?.speaking||window.speechSynthesis?.speaking);
        if(S.listening&&agentSpeaking&&text){
          if(['tts-echo','post-tts-echo'].includes(evaluation?.reason)){
            diag('speaker-echo-suppressed');continue;
          }
          // Interim guesses are especially prone to picking up speaker output.
          if(!result.isFinal)continue;
          const hasWake=/^(?:hey|hi|okay|ok)\s+gelato\b/i.test(text);
          const explicitStop=/^(?:stop speaking|interrupt)$/i.test(text);
          if(!hasWake&&!explicitStop){diag('speaker-audio-ignored');continue;}
          // A loaded shared voice-turn controller remains authoritative.
          if(!evaluation||(evaluation.kind!=='interrupt'&&evaluation.interrupt!==true))continue;
          interruptSpeech('conversation-barge-in');S.lastBargeIn=0;
          const control=parseListeningCommand(text);
          if(control==='stop')void setListening(false,{keepWake:true});
          else if(control==='off')void setListening(false,{keepWake:false});
          else if(hasWake){
            const rest=text.replace(/^(?:hey|hi|okay|ok)\s+gelato[,\s]*/i,'').trim();
            // Wake-and-question is a real new voice turn. Wake alone and
            // "stop speaking" are controls, never new model prompts.
            if(rest&&!/^(?:stop|stop speaking|be quiet|quiet|interrupt|hold on)\b/i.test(rest))
              showVoiceDraft(rest,{final:true});
          }
          continue;
        }
        if(evaluation?.kind==='ignored'){
          if(['tts-echo','post-tts-echo'].includes(evaluation.reason))diag('echo-ignored');
          // Noisy-room wake/low-confidence policies restrict commands, not
          // editable drafts while the user has explicitly enabled listening.
          else if(S.listening&&['wake-required','low-confidence'].includes(evaluation.reason)&&!/^hey\s+gelato\b/i.test(text))
            showVoiceDraft(text,{final:!!result.isFinal,autoSend:false});
          continue;
        }
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
        if(!S.listening||Date.now()-S.lastBargeIn<1300)continue;
        // Non-wake speech is visible dictation and never executes an action.
        if(!/^hey\s+gelato\b/i.test(text)){
          showVoiceDraft(text,{final:!!result.isFinal});
          if(result.isFinal)status('Speech captured · sending after a short pause…',1800);
          continue;
        }
        if(!result.isFinal)continue;
        if(/^hey\s+gelato\b/i.test(text)){
          if(evaluation?.interrupt)interruptSpeech('voice-barge-in');
          window.dispatchEvent(new CustomEvent('gelato-agent-voice-transcript',{detail:{text}}));
          void send(text,true);
        }
      }
    };
    activeRec.onerror=event=>{
      if(S.rec!==activeRec)return;
      const error=String(event.error||'unknown');diag('error',{error});
      if(!['no-speech','aborted'].includes(error))S.recognitionFaults++;
      if(['not-allowed','service-not-allowed','audio-capture'].includes(error)){
        haltVoiceAfterFailure();diag('blocked',{error});
        status('Speech recognition blocked: '+error+'. Check browser microphone and speech-service settings.',6500);
        renderListening();return;
      }
      if(!['no-speech','aborted'].includes(error))status('Speech recognition: '+error+'. No transcript was received.',5000);
    };
    activeRec.onend=()=>{
      if(S.rec!==activeRec)return;
      S.micActive=false;diag('ended');renderListening();
      if((S.listening||S.wakeStandby)&&!document.hidden){
        S.recognitionRestarts++;
        if(S.recognitionFaults>=5||S.recognitionRestarts>=5){
          status('Speech service keeps restarting. Microphone paused; press Listen to retry.',6500);
          haltVoiceAfterFailure();return;
        }
        const delay=Math.min(8000,650*Math.pow(2,Math.max(0,S.recognitionFaults-1)));
        diag('retry-scheduled',{delay,faults:S.recognitionFaults});
        setTimeout(()=>{if(S.rec===activeRec&&(S.listening||S.wakeStandby)&&!document.hidden){
          try{diag('retry');activeRec.start();}catch{
            diag('failed',{error:'start-failed'});haltVoiceAfterFailure();
          }
        }},delay);
      }
    };
    try{activeRec.start();return true;}catch(error){diag('failed',{error:'start-failed'});
      haltVoiceAfterFailure();status('Could not start microphone: '+(error.message||'unavailable'),3500);
      return false;
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

  async function refreshContext(){
    const data=await req('api/agent-workspace.php?action=food_monster_context');
    S.foodMonsterContext=data.foodMonsterContext||null;
    window.dispatchEvent(new CustomEvent('gelato-agent-context-refreshed',{detail:{foodMonsterContext:S.foodMonsterContext}}));
    return S.foodMonsterContext;
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
      S.foodMonsterContext=data.foodMonsterContext||null;
      mount();
      ensureConversationButton();renderConversation();
      await loadListeningPreference();
      window.dispatchEvent(new CustomEvent('gelato-agent-ready', {
        detail: {threadId: S.thread, user: S.user},
      }));
    } catch (error) {
      console.debug('Gelato Agent shell unavailable:', error.message);
    }
  }

  document.addEventListener('visibilitychange',()=>{
    if(document.hidden){clearConversationTimer();S.conversationDraft='';stopContinuous();diag('paused');if(S.listening||S.wakeStandby)status('Microphone paused · tab hidden.');}
    else if(S.listening||S.wakeStandby)startContinuous();
  });
  window.addEventListener('beforeunload',()=>{stopContinuous();interruptSpeech('page-unload');});

  window.GelatoGlobalAgent = {
    send,
    setListening,
    setConversationMode,
    get conversationMode(){return S.conversation;},
    loadListeningPreference,
    updateProactiveVoice,
    enableWakeStandby,
    get listeningStatus(){return {...listeningState(),savedListening:S.savedListening,listenActive:S.listening,microphoneActive:S.micActive,wakeStandby:S.wakeStandby};},
    talkOnce,
    interruptSpeech,
    identifySpeaker,
    get verifiedSpeaker(){return S.verifiedSpeaker&&Date.now()<S.verifiedSpeaker.verifiedUntil?S.verifiedSpeaker:null;},
    selectThread,
    refreshContext,
    get foodMonsterContext(){return S.foodMonsterContext;},
    get threadId() { return S.thread; },
    get user() { return S.user; },
    get permissions() { return [...S.permissions]; },
    get listening() { return S.listening; },
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
  else init();
})();