(() => {
  'use strict';
  if (window.GelatoVoiceDiagnostics) return;

  // Session memory only. Never ingest transcripts, audio, speaker IDs or names.
  const ALLOWED = new Set(['starting','active','ended','retry','blocked','error','failed','off','paused','resumed','wake-command','echo-ignored','barge-in','verified','verification-failed','no-speech','retry-scheduled','duplicate-final-suppressed','speaker-echo-suppressed','speaker-audio-ignored']);
  const ERRORS = new Set(['not-allowed','service-not-allowed','audio-capture','network','no-speech','aborted','language-not-supported','bad-grammar','unknown','start-failed','retry-limit']);
  const state = {
    owner: 'none', recognition: 'off', microphoneActive: false, savedListening: false,
    listenActive: false, wakeStandby: false, speech: false, speechOwner: 'none',
    profile: 'unknown', echoBlocks: 0, bargeIns: 0, restarts: 0,
    lastError: 'none', speakerTurns:{correlated:0,verifiedWithoutVisual:0,unknown:0},events: []
  };
  let panel = null, previousFocus = null;
  const esc = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','"':'&quot;'}[c]));

  function cameraHealth() {
    // Strict projection from local camera runtime: no face tracks, identities,
    // vectors, audio, transcripts, credentials or personal records.
    let source={};
    try{source=window.GelatoRoomPresence?.diagnostics||{};}catch{}
    const safeMs=value=>Number.isFinite(value)?Math.min(60000,Math.max(0,Math.round(value))):0;
    return {
      active:source.active===true,
      modelCached:source.modelCached===true,
      intervalMs:safeMs(source.intervalMs),
      lastObservationMs:safeMs(source.lastInferenceMs),
      consecutiveFailures:Math.min(10,Math.max(0,Math.trunc(Number(source.failures)||0)))
    };
  }
  function snapshot() {
    return {
      owner: state.microphoneActive ? state.owner : 'none',
      recognition: state.recognition, microphoneActive: state.microphoneActive,
      savedListening: state.savedListening, listenActive: state.listenActive,
      wakeStandby: state.wakeStandby, speech: state.speech, speechOwner: state.speechOwner,
      profile: state.profile, echoBlocks: state.echoBlocks, bargeIns: state.bargeIns,
      restarts: state.restarts, lastError: state.lastError,
      camera:cameraHealth(), speakerTurns:{...state.speakerTurns},events: state.events.map(e => ({...e}))
    };
  }
  function push(source, kind, error) {
    state.events.push({at: new Date().toISOString(), source, kind, ...(error ? {error} : {})});
    if (state.events.length > 24) state.events.shift();
  }
  function record(source, kind, details = {}) {
    if (!['global-agent','timeclock'].includes(source) || !ALLOWED.has(kind)) return;
    // Strictly allowlisted fields. Unexpected detail values cannot leak into diagnostics.
    const error = ERRORS.has(details.error) ? details.error : '';
    if (kind === 'starting') { state.owner = source; state.recognition = 'starting'; }
    if (kind === 'active') { state.owner = source; state.recognition = 'active'; state.microphoneActive = true; }
    if (kind === 'ended') { state.recognition = 'ended'; state.microphoneActive = false; }
    if (kind === 'retry') { state.restarts++; state.recognition = 'retrying'; state.microphoneActive = false; }
    if (kind === 'blocked' || kind === 'failed') {
      state.lastError = error || (kind === 'blocked' ? 'not-allowed' : 'start-failed');
      state.recognition = kind; state.microphoneActive = false; state.owner = 'none';
    }
    if (kind === 'error' && error) state.lastError = error;
    if (kind === 'off') { state.owner = 'none'; state.recognition = 'off'; state.microphoneActive = false; }
    if (kind === 'paused') { state.owner = 'none'; state.recognition = 'paused'; state.microphoneActive = false; }
    if (kind === 'echo-ignored') state.echoBlocks++;
    if (kind === 'barge-in') state.bargeIns++;
    push(source, kind, error);
    render();
  }
  function recordSpeakerTurn(detail={}) {
    // Counters consume ONLY three boolean states. Never access detail.personId,
    // eventId, voiceprint, transcript, face vectors or employee names.
    let bucket='unknown';
    if(detail.verified===true){
      bucket=detail.correlated===true?'correlated':'verifiedWithoutVisual';
    }
    state.speakerTurns[bucket]=Math.min(999,state.speakerTurns[bucket]+1);
    render();
  }
  function hydrate(detail = {}) {
    state.savedListening = !!detail.savedListening;
    state.listenActive = !!(detail.listenActive ?? detail.listening);
    state.wakeStandby = !!detail.wakeStandby;
    state.microphoneActive = !!detail.microphoneActive;
    if (state.microphoneActive && state.owner === 'none') state.owner = 'global-agent';
    if (!state.microphoneActive && state.recognition === 'active') state.recognition = 'ended';
    if (!state.microphoneActive) state.owner = 'none';
    render();
  }
  function onTurn(detail = {}) {
    state.speech = !!detail.speaking;
    // Speech synthesis owner names are a small predefined set; do not show arbitrary identifiers.
    state.speechOwner = state.speech && ['global-agent','timeclock','training','agent'].includes(detail.owner) ? detail.owner : 'none';
    state.profile = String(window.GelatoVoiceTurns?.environment || 'unknown');
    render();
  }
  function label(value) { return value === true ? 'Yes' : value === false ? 'No' : String(value); }
  function render() {
    if (!panel) return;
    const s = snapshot();
    const root = panel.querySelector('[data-voice-diagnostic-body]');
    if (!root) return;
    const items = [
      ['Microphone owner',s.owner],['Browser microphone active',label(s.microphoneActive)],
      ['Recognition',s.recognition],['Listening preference saved',label(s.savedListening)],
      ['Full listening requested',label(s.listenActive)],['Wake standby armed',label(s.wakeStandby)],
      ['Agent speaking',label(s.speech)],['Speech owner',s.speechOwner],
      ['Noise / echo profile',s.profile],['Echo blocks',s.echoBlocks],
      ['Barge-ins',s.bargeIns],['Recovery retries',s.restarts],['Last recognition error',s.lastError],
      ['Room camera enabled',label(s.camera.active)],
      ['Face models reused',label(s.camera.modelCached)],
      ['Camera sampling interval (ms)',s.camera.intervalMs],
      ['Last camera observation (ms)',s.camera.lastObservationMs],
      ['Consecutive camera errors',s.camera.consecutiveFailures],
      ['Voice + visual correlations',s.speakerTurns.correlated],
      ['Verified voice only',s.speakerTurns.verifiedWithoutVisual],
      ['Unattributed voice turns',s.speakerTurns.unknown]
    ];
    root.innerHTML = '<dl class="gvd-values">' +
      items.map(item => '<div><dt>'+esc(item[0])+'</dt><dd>'+esc(item[1])+'</dd></div>').join('') +
      '</dl><h3>Recent session events</h3><ol class="gvd-events">' +
      (s.events.length ? s.events.slice().reverse().map(e =>
        '<li><time>'+esc(e.at.slice(11,19))+'</time> '+esc(e.source)+' · '+esc(e.kind)+(e.error?' ('+esc(e.error)+')':'')+'</li>'
      ).join('') : '<li>No browser events yet.</li>') +
      '</ol><p class="gvd-privacy">Local session diagnostics only. No voice recordings, transcripts, biometrics, user identity or server-side log entries. Closing or reloading discards the panel history.</p>';
  }
  function mount() {
    if (panel || !document.body) return;
    const style = document.createElement('style');
    style.dataset.gelatoVoiceDiagnostics = '1';
    style.textContent = '.gvd-overlay{position:fixed;inset:0;z-index:2147483500;background:rgba(12,20,16,.58);display:grid;place-items:center;padding:12px;font:13px/1.4 Inter,system-ui,sans-serif;color:#1c241e}.gvd-overlay[hidden]{display:none}.gvd-dialog{width:min(590px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:18px;padding:18px;box-shadow:0 20px 60px #0004}.gvd-top{display:flex;gap:12px;align-items:center;justify-content:space-between}.gvd-top h2{font-size:17px;margin:0}.gvd-top button{border:1px solid #d7ded9;background:#eef3ef;border-radius:8px;padding:9px 12px;cursor:pointer}.gvd-values{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin:15px 0}.gvd-values>div{padding:9px;background:#f4f7f5;border-radius:9px;min-width:0}.gvd-values dt{font-size:10px;color:#53665a}.gvd-values dd{font-weight:700;margin:3px 0 0;overflow-wrap:anywhere}.gvd-events{padding-left:21px;max-height:155px;overflow:auto;font-size:11px}.gvd-events li{padding:2px}.gvd-events time{color:#516357}.gvd-privacy{font-size:11px;color:#627166}.gvd-actions{display:flex;gap:8px;flex-wrap:wrap}@media(max-width:480px){.gvd-values{grid-template-columns:1fr}}';
    document.head.appendChild(style);
    panel = document.createElement('section');
    panel.id = 'gelato-voice-diagnostics';
    panel.className = 'gvd-overlay';
    panel.hidden = true;
    panel.setAttribute('role','dialog');
    panel.setAttribute('aria-modal','true');
    panel.setAttribute('aria-labelledby','gvd-heading');
    panel.innerHTML = '<div class="gvd-dialog"><header class="gvd-top"><h2 id="gvd-heading">Agent Brain · Voice Diagnostics</h2><button type="button" data-gvd-close aria-label="Close voice diagnostics">Close</button></header><div data-voice-diagnostic-body></div><div class="gvd-actions"><button type="button" data-gvd-copy>Copy sanitized report</button><button type="button" data-gvd-clear>Clear session events</button></div></div>';
    document.body.appendChild(panel);
    panel.addEventListener('click', event => {
      if (event.target === panel || event.target.closest('[data-gvd-close]')) close();
      if (event.target.closest('[data-gvd-clear]')) { state.events=[];state.restarts=0;state.echoBlocks=0;state.bargeIns=0;state.speakerTurns={correlated:0,verifiedWithoutVisual:0,unknown:0};render(); }
      if (event.target.closest('[data-gvd-copy]')) {
        const report = JSON.stringify(snapshot(),null,2);
        void navigator.clipboard?.writeText(report).catch(() => {});
      }
    });
    document.addEventListener('keydown', event => {
      if (panel.hidden) return;
      if (event.key === 'Escape') close();
      if (event.key === 'Tab') {
        const buttons=Array.from(panel.querySelectorAll('button'));
        if (event.shiftKey && document.activeElement === buttons[0]) {event.preventDefault();buttons[buttons.length-1].focus();}
        else if (!event.shiftKey && document.activeElement === buttons[buttons.length-1]) {event.preventDefault();buttons[0].focus();}
      }
    });
    render();
  }
  function open() { mount(); if (!panel) return; previousFocus=document.activeElement; render(); panel.hidden=false; panel.querySelector('[data-gvd-close]').focus(); }
  function close() { if (!panel) return; panel.hidden=true; previousFocus?.focus?.(); }
  window.addEventListener('gelato-agent-listening-change', event => hydrate(event.detail));
  window.addEventListener('gelato-voice-turn', event => onTurn(event.detail));
  window.addEventListener('gelato-agent-ready', () => hydrate(window.GelatoGlobalAgent?.listeningStatus || {}));
  window.addEventListener('gelato-agent-speaker-turn',event => recordSpeakerTurn(event.detail));
  window.GelatoVoiceDiagnostics = {record,open,close,snapshot,hydrate};
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, {once:true}); else mount();
})();