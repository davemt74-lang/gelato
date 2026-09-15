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
    listening: false,
    rec: null,
    poll: null,
    pageContext: null,
    contextActions: [],
  };
  let pageContextLoader = null;

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

  function ensurePageContext() {
    if (window.GelatoAgentPageContext) return Promise.resolve(window.GelatoAgentPageContext);
    if (pageContextLoader) return pageContextLoader;
    pageContextLoader = new Promise((resolve) => {
      const existing = document.querySelector('script[data-gelato-agent-page-context-loader]');
      if (existing) {
        existing.addEventListener('load', () => resolve(window.GelatoAgentPageContext || null), {once:true});
        existing.addEventListener('error', () => resolve(null), {once:true});
        return;
      }
      const script = document.createElement('script');
      script.src = 'js/agent-page-context.js?v=20260915-context1';
      script.dataset.gelatoAgentPageContextLoader = '1';
      script.onload = () => resolve(window.GelatoAgentPageContext || null);
      script.onerror = () => resolve(null);
      document.head.appendChild(script);
    });
    return pageContextLoader;
  }

  async function currentPageContext() {
    await ensurePageContext();
    try { return window.GelatoAgentPageContext?.snapshot?.() || {}; }
    catch { return {}; }
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
      #gelato-agent-status{position:fixed;left:50%;bottom:72px;transform:translateX(-50%);z-index:2147482999;max-width:min(800px,calc(100% - 34px));background:#171b1a;color:#fff;padding:9px 12px;border-radius:12px;font:600 11px/1.4 Inter,system-ui,sans-serif;display:none;box-shadow:0 12px 32px rgba(0,0,0,.18)}
      #gelato-agent-dot{width:8px;height:8px;border-radius:50%;background:#777;position:absolute;right:4px;top:4px}
      #gelato-agent-dot.on{background:#35a36f}
      @keyframes gaPulse{50%{box-shadow:0 0 0 5px rgba(217,74,43,.12)}}
      @media(max-width:620px){#gelato-agent-bar{bottom:8px;width:calc(100% - 12px);border-radius:18px}}
    `;
    document.head.appendChild(style);
  }

  function mount() {
    if (document.body?.dataset.agentCanvas === '1' || document.getElementById('gelato-agent-bar')) return;
    installCss();
    document.body.insertAdjacentHTML('beforeend', `
      <div id="gelato-agent-status" role="status"></div>
      <div id="gelato-agent-bar" aria-label="Gelato Restaurant Agent">
        <button class="ga-listen" id="gaListen" title="Listening Mode"><span>◉</span><i id="gelato-agent-dot"></i></button>
        <button id="gaMic" title="Talk once">🎙</button>
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
    q('gaListen').onclick = () => setListening(!S.listening);
  }

  function status(text, milliseconds = 0) {
    const element = document.getElementById('gelato-agent-status') || document.getElementById('canvasStatus');
    if (!element) return;
    element.textContent = text || '';
    element.style.display = text ? 'block' : 'none';
    if (milliseconds && text) {
      setTimeout(() => {
        if (element.textContent === text) element.style.display = 'none';
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
    const text = String(raw || '').trim();
    if (!text || S.busy) return null;

    S.busy = true;
    const input = document.getElementById('gaInput');
    if (input) input.value = '';
    status('Gelato is checking your restaurant context…');

    try {
      const pageContext = await currentPageContext();
      await append('user', text, {
        channel: voice ? 'voice' : 'text',
        voiceEventId: voice ? S.voiceEventId : '',
      });

      const route = await post('api/agent-workspace.php', {action: 'route', message: text, pageContext});
      S.pageContext = route.pageContext || pageContext;
      S.contextActions = Array.isArray(route.contextActions) ? route.contextActions : [];
      let result;

      if (route.route) {
        const toolMessage = text + String(route.contextPrompt || '');
        const toolArgs = route.toolArgs && typeof route.toolArgs === 'object' ? route.toolArgs : {};
        const response = await fetch(route.route, {
          method: 'POST',
          cache: 'no-store',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': S.csrf,
          },
          body: JSON.stringify({
            ...toolArgs,
            action: 'ask',
            message: toolMessage,
            pageContext: S.pageContext,
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

      const answer = result.answer || result.reply || 'Done.';
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
        request: {message: text, voice: Boolean(voice), pageContext: S.pageContext},
        result: {answer, data: result.data ?? null, sources: result.sources ?? []},
      });

      status(answer, 7000);
      window.dispatchEvent(new CustomEvent('gelato-agent-response', {
        detail: {answer, result, threadId: S.thread, pageContext: S.pageContext, contextActions: S.contextActions},
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

  function speak(text) {
    try {
      speechSynthesis.cancel();
      const utterance = new SpeechSynthesisUtterance(String(text).slice(0, 1600));
      utterance.rate = 1;
      speechSynthesis.speak(utterance);
    } catch {}
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

  async function verifyVoice() {
    if (!(has('voice.self') || has('voice.agent'))) throw new Error('Voice recognition is not enabled for this account.');
    if (S.voiceEventId && Date.now() < S.voiceUntil) return true;

    status('Voice check… speak naturally for a few seconds.');
    const features = await captureFeatures();
    const data = await post('api/voice-identity.php', {action: 'verify', features});
    if (!data.match?.matched || !data.match?.sessionUserMatch) throw new Error('Voice did not match your enrolled profile.');

    S.voiceEventId = data.match.eventId;
    S.voiceUntil = Date.now() + 14 * 60 * 1000;
    document.getElementById('gelato-agent-dot')?.classList.add('on');
    status(`Voice recognized as ${S.user?.firstName || 'you'}.`, 2200);
    return true;
  }

  function speechOnce() {
    return new Promise((resolve, reject) => {
      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
      if (!SpeechRecognition) {
        reject(new Error('Browser speech recognition is not available.'));
        return;
      }
      const recognizer = new SpeechRecognition();
      recognizer.lang = 'en-US';
      recognizer.interimResults = false;
      recognizer.continuous = false;
      recognizer.onresult = (event) => resolve([...event.results].map((row) => row[0].transcript).join(' '));
      recognizer.onerror = (event) => reject(new Error('Speech recognition: ' + event.error));
      recognizer.start();
    });
  }

  async function talkOnce() {
    try {
      await verifyVoice();
      status('Listening…');
      const text = await speechOnce();
      return await send(text, true);
    } catch (error) {
      status(error.message, 4500);
      return null;
    }
  }

  async function setListening(on) {
    if (on) {
      try {
        await verifyVoice();
      } catch (error) {
        status(error.message, 4500);
        return;
      }
      if (!has('agent.proactive')) {
        status('Your account does not have proactive Agent permission.', 4500);
        return;
      }
    }

    try {
      await post('api/proactive-agent.php', {
        action: 'preferences',
        listeningEnabled: on,
        proactiveVoiceEnabled: on,
        wakePhrase: 'Hey Gelato',
      });
      S.listening = on;
      document.getElementById('gaListen')?.classList.toggle('on', on);
      if (on) {
        startContinuous();
        startPoll();
        status('Listening Mode on. Say “Hey Gelato…”', 3000);
      } else {
        stopContinuous();
        stopPoll();
        status('Listening Mode off.', 1800);
      }
    } catch (error) {
      status(error.message, 4500);
    }
  }

  function startContinuous() {
    stopContinuous();
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) {
      status('Continuous speech recognition is not supported.', 4000);
      return;
    }

    S.rec = new SpeechRecognition();
    S.rec.lang = 'en-US';
    S.rec.continuous = true;
    S.rec.interimResults = false;
    S.rec.onresult = (event) => {
      for (let index = event.resultIndex; index < event.results.length; index++) {
        if (!event.results[index].isFinal) continue;
        const text = event.results[index][0].transcript.trim();
        if (/^hey\s+gelato\b/i.test(text)) {
          if (Date.now() >= S.voiceUntil) {
            setListening(false);
            status('Voice check expired. Re-enable Listening Mode.', 4500);
            return;
          }
          send(text, true);
        }
      }
    };
    S.rec.onerror = (event) => {
      if (!['no-speech', 'aborted'].includes(event.error)) status('Listening: ' + event.error, 3000);
    };
    S.rec.onend = () => {
      if (S.listening) {
        setTimeout(() => {
          try { S.rec?.start(); } catch {}
        }, 450);
      }
    };
    try { S.rec.start(); } catch {}
  }

  function stopContinuous() {
    if (!S.rec) return;
    try {
      S.rec.onend = null;
      S.rec.stop();
    } catch {}
    S.rec = null;
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
            pageContext: S.pageContext,
            contextActions: S.contextActions,
          },
        }));
        if (has('voice.agent')) speak(event.message);
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
      ensurePageContext();
      const data = await req('api/agent-workspace.php?action=bootstrap');
      S.csrf = data.csrf;
      S.thread = data.activeThread;
      S.permissions = data.permissions || [];
      S.user = data.user || null;
      mount();
      window.dispatchEvent(new CustomEvent('gelato-agent-ready', {
        detail: {threadId: S.thread, user: S.user},
      }));
    } catch (error) {
      console.debug('Gelato Agent shell unavailable:', error.message);
    }
  }

  window.GelatoGlobalAgent = {
    send,
    setListening,
    talkOnce,
    selectThread,
    get threadId() { return S.thread; },
    get user() { return S.user; },
    get permissions() { return [...S.permissions]; },
    get pageContext() { return S.pageContext; },
    get contextActions() { return [...S.contextActions]; },
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
  else init();
})();
