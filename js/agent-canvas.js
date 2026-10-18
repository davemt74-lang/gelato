(() => {
  'use strict';

  const $ = (selector) => document.querySelector(selector);
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[char]));

  let csrf = '';
  let threads = [];
  let active = '';
  let loading = false;

  // Local, ephemeral diagnostics for the kitchen pilot sidebar only. Never
  // collect/store transcripts, voiceprints, speaker IDs or camera images.
  const pilotLocal = {micStarts:0,micStops:0,micErrors:0,speakerTurns:0,
    verifiedSpeakerTurns:0,handoffs:0,conversationToggles:0,voiceReplies:0};
  window.addEventListener('gelato-agent-listening-change',(event)=>{
    const detail=event.detail||{};
    if(detail.microphoneActive && detail.listening)pilotLocal.micStarts++;
    if(!detail.microphoneActive)pilotLocal.micStops++;
    if(/error|unavailable|failed|exhausted/i.test(String(detail.code||'')))pilotLocal.micErrors++;
  });
  window.addEventListener('gelato-agent-speaker-turn',(event)=>{
    pilotLocal.speakerTurns++;
    if(event.detail?.verified===true)pilotLocal.verifiedSpeakerTurns++;
  });
  window.addEventListener('gelato-agent-speaker-handoff',(event)=>{
    if(event.detail?.verified===true)pilotLocal.handoffs++;
  });
  window.addEventListener('gelato-agent-conversation-change',()=>{pilotLocal.conversationToggles++;});
  window.addEventListener('gelato-voice-turn',(event)=>{
    if(event.detail?.kind==='started')pilotLocal.voiceReplies++;
  });

  async function req(url, opt = {}) {
    const response = await fetch(url, {
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        ...(opt.body ? {'Content-Type': 'application/json', 'X-CSRF-Token': csrf} : {}),
        ...(opt.headers || {}),
      },
      ...opt,
    });
    const data = await response.json().catch(() => ({ok: false, message: 'Invalid server response.'}));
    if (!response.ok || !data.ok) throw new Error(data.message || 'Request failed.');
    return data;
  }

  function post(body) {
    return req('api/agent-workspace.php', {
      method: 'POST',
      body: JSON.stringify({...body, csrf_token: csrf}),
    });
  }

  function status(text, milliseconds = 0) {
    const element = $('#canvasStatus');
    if (!element) return;
    element.textContent = text || '';
    element.style.display = text ? 'block' : 'none';
    if (milliseconds) {
      setTimeout(() => {
        if (element.textContent === text) element.style.display = 'none';
      }, milliseconds);
    }
  }

  function fmt(value) {
    if (!value) return '';
    try {
      return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
      }).format(new Date(String(value).replace(' ', 'T')));
    } catch {
      return String(value);
    }
  }

  function renderThreads() {
    const root = $('#threadList');
    if (!root) return;
    root.innerHTML = threads.length
      ? threads.map((thread) => `
          <button class="thread ${thread.public_id === active ? 'active' : ''}" data-thread="${esc(thread.public_id)}">
            <strong>${esc(thread.title || 'Conversation')}</strong>
            <small>${Number(thread.message_count || 0)} messages · ${esc(fmt(thread.last_message_at || thread.created_at))}</small>
          </button>
        `).join('')
      : '<div class="empty">No conversations.</div>';
    root.querySelectorAll('[data-thread]').forEach((button) => {
      button.onclick = () => openThread(button.dataset.thread).catch((error) => status(error.message, 4000));
    });
  }

  // G14D1: operator-guided pilot checks. This is explanatory UI only:
  // no microphone/camera permissions requested, no device writes, no auto-pass.
  const pilotGuide = Object.freeze({
    microphone:['Confirm browser microphone permission on the intended device.',
      'Start listening, stop it, then repeat after reconnecting the microphone.',
      'Check recognition state and restart/error counters in local diagnostics.'],
    transcription:['Speak a short non-sensitive test phrase in a quiet location.',
      'Verify a final result appears in the chat composer, not only an interim highlight.',
      'Repeat after stopping and restarting listening. Do not save the spoken phrase.'],
    handoff:['Invite an authorized test participant to speak after the active speaker.',
      'Confirm unverified speakers remain isolated from private agent context.',
      'Check verified handoff counts locally; do not record speaker identities.'],
    camera:['Obtain consent before enabling the selected camera.',
      'Verify tracking follows the test hand, then disconnect and reconnect once.',
      'Ensure stale boxes disappear and recovery does not duplicate streams.'],
    timeclock:['Use an approved test employee session and known active shift.',
      'Verify clocked-in, break, and clocked-out responses without changing time entries.',
      'Confirm employee-only queries cannot expose owner or other staff records.'],
    kds:['Use a safe test ticket with no production preparation in progress.',
      'Say Next Item and Next Ticket once each; confirm one intentional navigation per utterance.',
      'Verify KDS voice cannot execute duplicate commands after mic recovery.'],
    recovery:['Stop listening manually and confirm it stays stopped.',
      'Test a controlled device disconnect and page reload, never during production.',
      'Confirm any failure is reported and fresh retesting is required after repair.']
  });
  function pilotGuidance(gate,statusValue) {
    if (!Object.prototype.hasOwnProperty.call(pilotGuide,gate)) return '';
    const steps=pilotGuide[gate].map((step,i)=>
      '<li>'+esc(String(i+1)+'. '+step)+'</li>').join('');
    const repair=statusValue==='fail'||statusValue==='blocked'
      ? '<p>Failure reported: use the existing planned-fix workflow and retest on real hardware.</p>'
      : statusValue==='pass'
        ? '<p>Operator-reported pass only; no automatic field certification.</p>'
        : '<p>Complete this test manually before recording a result.</p>';
    return '<details class="pilot-guide"><summary>Guided field check and troubleshooting</summary>'+
      '<ol>'+steps+'</ol>'+repair+
      '<p>Do not include transcripts, employee identities, order IDs or credentials in evidence.</p>'+
      '</details>';
  }

  // G14D2: deterministic advisory rules over existing privacy-safe local fields.
  // Does not send diagnostics to the server, invoke devices, or mark pilot passes.
  function pilotTroubleshooting(rows) {
    const states=new Map(rows.map(([key,value])=>[key,String(value)]));
    const recognition=states.get('Speech recognition')||'unavailable';
    const error=states.get('Speech-service error')||'unavailable';
    const suggestions=[];
    const add=(subject,reason,next)=>suggestions.push({subject,reason,next});
    if(error==='not-allowed'||error==='service-not-allowed'||recognition==='blocked'){
      add('Microphone permission','Browser speech access is blocked.',
        'Check site microphone permission and operating-system privacy settings; retry only after consent.');
    }else if(error==='audio-capture'){
      add('Microphone source','The speech service reported an audio-capture error.',
        'Check device connection and input selection; perform a controlled offline reconnect test.');
    }else if(error==='network'){
      add('Speech service','The recognition service reported a network error.',
        'Check connectivity and service availability; keep production voice commands disabled until retested.');
    }else if(error==='retry-limit'||recognition==='failed'){
      add('Speech recovery','The speech recognizer is in a failed or retry-exhausted state.',
        'Stop listening, inspect the intended input device, and retest manually after recovery.');
    }else if(recognition==='retrying'){
      add('Speech recovery','The microphone is attempting recovery.',
        'Observe whether it returns to active; do not start overlapping listeners.');
    }else if(recognition==='unavailable'){
      add('Speech diagnostics','Speech recognition status is unavailable in this browser session.',
        'Open the pilot from the device being tested and confirm permission and browser support.');
    }
    if(states.get('KDS listening')!=='off'&&states.get('KDS mic active')==='No / unavailable'){
      add('KDS voice','KDS voice mode is enabled but the microphone is not confirmed active.',
        'Check the KDS voice input state using a safe test ticket; avoid production navigation until verified.');
    }
    for(let slot=1;slot<=5;slot++){
      const state=states.get('Camera '+slot);
      if(!state)continue;
      if(/ · (failed|recovering|device_changed) · /.test(state)){
        add('Camera '+slot,'The camera is not confirmed ready ('+state+').',
          'Inspect consent and physical connection, then verify tracking and a single recovered stream.');
      }
    }
    return suggestions.slice(0,8);
  }
  function pilotTroubleshootingHtml(rows) {
    const tips=pilotTroubleshooting(rows);
    return '<div class="result-card"><h3>Assisted device troubleshooting</h3>'+
      '<p>Browser-local advisory guidance only. No repairs or field results were applied.</p>'+
      (tips.length?tips.map(t=>'<div class="pilot-advice"><b>'+esc(t.subject)+'</b>'+
        '<p>'+esc(t.reason)+'</p><p>Suggested check: '+esc(t.next)+'</p></div>').join(''):
        '<p>No known diagnostic warning is visible. This does not establish device readiness.</p>')+
      '</div>';
  }

  // Strictly project existing local diagnostic snapshots into fixed, non-identity
  // fields. Never send any snapshot, event history, transcript or camera image.
  function pilotDeviceSummary() {
    let voice={},camera={};
    try { voice=window.GelatoVoiceDiagnostics?.snapshot?.()||{}; } catch {}
    try { camera=window.GelatoCameraRuntime?.snapshot?.()||{}; } catch {}
    const clamp=(v,max=999)=>Math.max(0,Math.min(max,Math.trunc(Number(v)||0)));
    const enumValue=(v,allowed)=>allowed.includes(v)?v:'unavailable';
    const rows=[
      ['Speech recognition',enumValue(voice.recognition,
        ['off','starting','active','ended','retrying','blocked','failed','paused'])],
      ['Microphone active',voice.microphoneActive===true?'Yes':'No / unavailable'],
      ['Microphone restarts',clamp(voice.restarts)],
      ['Echo blocks',clamp(voice.echoBlocks)],
      ['Barge-in events',clamp(voice.bargeIns)],
      ['Speech-service error',enumValue(voice.lastError,
        ['none','not-allowed','service-not-allowed','audio-capture','network',
         'no-speech','aborted','language-not-supported','start-failed','retry-limit'])],
      ['KDS listening',enumValue(window.GelatoKdsVoice?.mode,['off','wake','always'])],
      ['KDS mic active',window.GelatoKdsVoice?.active===true?'Yes':'No / unavailable']
    ];
    const cameras=Array.isArray(camera.cameras)?camera.cameras:[];
    for(const slot of [1,2,3,4,5]){
      const c=cameras.find(x=>Number(x?.slot)===slot);
      if(!c)continue;
      rows.push(['Camera '+slot,
        (c.active===true?'active':'inactive')+' · '+
        enumValue(c.state,['ready','off','recovering','starting','failed','disabled',
          'stopped','device_changed','manual_stop','tab_hidden','page_closed','location_changed'])+
        ' · retries '+clamp(c.retries,3)]);
    }
    return rows;
  }

  // G14D4: one compact owner-facing pilot overview, derived only from
  // already-authorized pilot response data. Commands are examples, never run here.
  function pilotCommandCenter(data) {
    const allowed=['microphone','transcription','handoff','camera','timeclock','kds','recovery'];
    const gates=Array.isArray(data.items)?data.items:[];
    const unresolved=Number(data.readiness?.unresolvedIssues||0);
    const pending=gates.filter(g=>allowed.includes(g.gate)&&g.status!=='pass');
    const state=data.active?'Active':data.closed?'Closed':'Not started';
    const safe=(v)=>esc(String(v??''));
    const first=pending[0];
    const next=(!data.active?'Start a new owner-controlled field pilot using: start kitchen pilot'
      : unresolved>0?'Review failed checks and repair plans with: kitchen pilot fixes'
      : first?'Test '+safe(first.gate)+' and record an operator result after a physical check.'
      :'All gates have recent operator-reported passes; arrange independent physical certification.');
    const checks=allowed.map(gate=>{
      const item=gates.find(x=>x.gate===gate);
      const status=['pass','fail','blocked','stale','not_tested'].includes(item?.status)
        ?item.status:'not_tested';
      return '<li><b>'+safe(gate)+'</b> — '+safe(status)+'</li>';
    }).join('');
    return '<div class="result-card"><h3>Pilot command center</h3>'+
      '<p>Run: '+safe(state)+' · Checks awaiting passing reports: '+safe(pending.length)+
      ' · Unresolved repairs: '+safe(Math.max(0,Math.min(7,unresolved)))+'</p>'+
      '<details><summary>Seven-gate field checklist</summary><ul>'+checks+'</ul></details>'+
      '<p>Next recommended action: '+next+'</p>'+
      '<p>Training context: team training insights (Super Admin only); '+
      'my training insights (authorized self-view). Neither certifies equipment.</p>'+
      '<p>Field certification pending independent real-device review. '+
      'No field evidence, device setting or production state was modified.</p></div>';
  }

  function structuredCard(data, skill, sources = []) {
    if (data == null) return '<div class="empty">No structured result for this response.</div>';
    if (skill === 'food_monster.kitchen_pilot' && Array.isArray(data.items)) {
      const ready=data.readiness||{};
      const count=ready.statusCounts||{};
      const readinessHtml='<div class="result-card"><h3>Field pilot readiness · operator reports</h3>'+
        '<p>'+esc(count.pass||0)+' passes · '+esc(count.fail||0)+' failures · '+
        esc(count.blocked||0)+' blocked · '+esc(count.stale||0)+' stale · '+
        esc(count.not_tested||0)+' not tested</p>'+
        '<p>Unresolved device follow-ups: '+esc(ready.unresolvedIssues||0)+'</p>'+
        '<p>All recent operator checks reported passing: '+
        (ready.operatorReportComplete===true?'Yes':'No')+
        '. Physical certification remains pending.</p>'+
        '<p>Employee guided practice is separate evidence; never proof of device readiness. '+
        'Use authorized typed Agent Chat: team training insights. '+
        'Staff may request their own history with: my training insights.</p></div>';
      const metrics=[
        ['Microphone transitions',pilotLocal.micStarts],
        ['Microphone off states',pilotLocal.micStops],
        ['Mic error states',pilotLocal.micErrors],
        ['Speaker turns',pilotLocal.speakerTurns],
        ['Verified turns',pilotLocal.verifiedSpeakerTurns],
        ['Verified handoffs',pilotLocal.handoffs],
        ['Conversation toggles',pilotLocal.conversationToggles],
        ['Spoken reply events',pilotLocal.voiceReplies]
      ];
      const metricsHtml=[...metrics,...pilotDeviceSummary()].map(([label,value])=>'<b>'+esc(label)+'</b><span>'+
        esc(value)+'</span>').join('');
      const fixes=Array.isArray(data.followUps?.issues)?data.followUps.issues.slice(0,7):[];
      const followupHtml=fixes.map(issue=>
        '<div class="result-card"><h3>'+esc(issue.title||issue.gate)+'</h3>'+
        '<p>Follow-up status: '+esc(issue.status||'open')+'</p>'+
        '<p>Original issue (operator report): '+esc(issue.failureEvidence||'not provided')+'</p>'+
        (issue.plan?'<p>Planned repair: '+esc(issue.plan)+'</p>':'')+
        (issue.resolution?'<p>Reported resolution: '+esc(issue.resolution)+'</p>':'')+
        '<p>Plan: plan kitchen pilot fix '+esc(issue.gate)+
        ': [20+ character plan]</p>'+
        '<p>After a NEW passing field check, resolve: resolve kitchen pilot fix '+
          esc(issue.gate)+': [20+ character resolution note]</p></div>').join('');
      const gates=data.items.slice(0,7).map(g=>
        '<div class="result-card"><h3>'+esc(g.title||g.gate)+'</h3>'+
        '<p>Operator report: '+esc(g.status||'not_tested')+'</p>'+
        (g.evidence?'<p>'+esc(g.evidence)+'</p>':'<p>Not yet recorded.</p>')+
        pilotGuidance(String(g.gate||''),String(g.status||''))+
        '<p>Record: record kitchen pilot '+esc(g.gate)+
          ' pass: [20+ character non-sensitive evidence]</p></div>').join('');
      return '<div class="result-card"><h3>Kitchen pilot · '+esc(data.passed||0)+
        '/'+esc(data.total||7)+' recent operator passes</h3>'+
        '<p>Field certification: NOT verified. All check outcomes are manually reported.</p>'+
        '<p>Run: '+esc(data.runId||'not started')+
        ' · '+(data.closed?'Closed':data.active?'Active':'Not started')+'</p>'+
        (data.partialHistory?'<p>History is partial.</p>':'')+
        '<p>Start: start kitchen pilot · Finish: close kitchen pilot</p>'+
        '<p>Unresolved follow-ups: '+esc(data.followUps?.unresolved||0)+
          ' · resolved: '+esc(data.followUps?.resolved||0)+'</p></div>'+
        readinessHtml+pilotCommandCenter(data)+
        (followupHtml?'<div class="result-card"><h3>Failure-to-fix follow-ups</h3>'+
          '<p>A planned repair needs a NEW passing field test and explicit owner resolution.</p></div>'+
          followupHtml:'')+
        '<div class="result-card"><h3>This browser session · local diagnostics</h3>'+
        '<p>Counts reset on page reload, are not sent to the server, and do not certify equipment.</p>'+
        '<div class="kv">'+metricsHtml+'</div></div>'+pilotTroubleshootingHtml(pilotDeviceSummary())+gates;
    }
    if ((skill === 'food_monster.training_change_impact' || skill === 'food_monster.shift_training_refresh')
        && Array.isArray(data.participants)) {
      const cards=data.participants.slice(0,30).map(p =>
        '<div class="result-card"><h3>' + esc(p.displayName || 'Participant') + '</h3>' +
        '<p>' + esc(p.sourceName || 'Menu source unavailable') + '</p>' +
        '<div class="kv"><b>Saved step</b><span>' + esc(p.step || 0) + ' / ' + esc(p.stepCount || 0) + '</span>' +
        '<b>Source status</b><span>' + esc(p.impact || 'unknown') + '</span>' +
        '<b>Guided practice</b><span>' + esc(p.trainingStatus || 'unknown') + '</span>' +
        '<b>Revision acknowledged</b><span>' + (p.acknowledgedUpdate ? 'Yes — refresh needed' : 'Not pending') + '</span>' +
        '<b>Guided refresh</b><span>' + (p.refreshCompleted ? 'Walkthrough complete (not certified)' :
          p.refreshInProgress ? 'In progress' : 'Not started') + '</span>' +
        (skill === 'food_monster.shift_training_refresh'
          ? '<b>Clock status</b><span>' + esc(p.shiftState || 'unavailable') + '</span>' +
            '<b>Review window</b><span>' + esc(p.reviewWindow || '') + '</span>'
          : '') + '</div>' +
        '<p>' + esc(p.recommendation || '') + '</p></div>').join('');
      return '<div class="result-card"><h3>' +
        (skill === 'food_monster.shift_training_refresh' ? 'Shift training review' : 'Procedure change impact') +
        '</h3><p>' + esc(data.affected || 0) + ' training sessions may need review. No progress was reset or rated.</p>' +
        (data.shiftSummary ? '<p>' + esc(data.shiftSummary.deferredUntilBreak || 0) +
          ' active-shift reminder(s) deferred; ' + esc(data.shiftSummary.reviewOpportunity || 0) +
          ' optional review opportunity/ies.</p>' : '') +
        (data.partial?'<span class="pill">Partial result</span>':'') + '</div>' +
        (cards||'<div class="empty">No saved menu-guided training was found for this view.</div>');
    }
    if (skill === 'food_monster.procedure_version_history') {
      if (data.mode === 'history' && Array.isArray(data.revisions)) {
        const revisions=data.revisions.slice(0,30).map(rev =>
          '<div class="result-card"><h3>' + esc(rev.versionId || '') + '</h3>' +
          '<p>Published: ' + esc(new Date(Number(rev.publishedAt || 0)*1000).toLocaleString()) + '</p>' +
          '<p>Before: ' + esc(String(rev.beforeHash || '').slice(0,14)) +
          ' · After: ' + esc(String(rev.afterHash || '').slice(0,14)) + '</p>' +
          '<p>Type "compare procedure ' + esc(rev.versionId || '') + '" to review changes.</p></div>'
        ).join('');
        return '<div class="result-card"><h3>Procedure version history</h3>' +
          '<p>' + esc(data.itemName || '') + ' · Menu version ' + esc(data.menuVersion || 0) +
          ' · Current matches last known: ' + (data.currentMatchesLatest ? 'Yes' : 'No / unknown') + '</p>' +
          '<p>Only Food Monster publications are included, not unrelated Menu Manager edits.</p>' +
          (data.partial?'<p class="pill">Partial history</p>':'') + '</div>' +
          (revisions || '<div class="empty">No recorded Food Monster publications.</div>');
      }
      if (data.mode === 'compare' && data.revision) {
        const rev=data.revision;
        return '<div class="result-card"><h3>Compare procedure revision</h3>' +
          '<p>' + esc(data.itemName || '') + ' · ' + esc(rev.versionId || '') + '</p>' +
          '<h4>Before publication</h4><pre>' + esc(rev.before || '') + '</pre>' +
          '<h4>After publication</h4><pre>' + esc(rev.after || '') + '</pre>' +
          '<p>Matches current menu: ' + (data.isCurrent?'Yes':'No') +
          '. Rollback requires a fresh owner draft, approval and separate publication.</p></div>';
      }
      if (data.mode === 'rollback_draft') {
        return '<div class="result-card"><h3>Rollback draft created</h3>' +
          '<div class="kv"><b>Version</b><span>' + esc(data.versionId || '') + '</span>' +
          '<b>Proposal</b><span>' + esc(data.proposalId || '') + '</span>' +
          '<b>Status</b><span>Draft only</span></div>' +
          '<p>No menu changes were made. Review, approve, and separately publish in typed Super Admin chat.</p></div>';
      }
    }
    if (skill === 'food_monster.procedure_review') {
      if (Array.isArray(data.candidates)) {
        const cards = data.candidates.slice(0, 12).map(item =>
          '<div class="result-card"><h3>' + esc(item.name || 'Menu item') +
          ' · #' + esc(item.itemId || '') + '</h3>' +
          '<div class="kv"><b>Help requests</b><span>' + esc(item.helpRequests || 0) + '</span>' +
          '<b>Revisited steps</b><span>' + esc(item.revisits || 0) + '</span>' +
          '<b>Staff asking for help</b><span>' + esc(item.peopleRequestingHelp || 0) + '</span>' +
          '<b>Review</b><span>' + (item.reviewSuggested ? 'Suggested' : 'Optional') + '</span></div>' +
          '<p>Recorded preparation: ' + esc(item.recordedPreparation || 'Not recorded') + '</p>' +
          '</div>').join('');
        return '<div class="result-card"><h3>Procedure improvement review</h3>' +
          '<p>Source-backed signals only. No procedure changes without typed owner approval and publication.</p>' +
          (data.partial ? '<span class="pill">Partial history</span>' : '') +
          '</div>' + (cards || '<div class="empty">No recorded review candidates.</div>');
      }
      if (data.proposalId) {
        return '<div class="result-card"><h3>Procedure proposal</h3>' +
          '<div class="kv"><b>Proposal ID</b><span>' + esc(data.proposalId) + '</span>' +
          '<b>Menu item</b><span>' + esc(data.itemId || '') + '</span>' +
          '<b>Status</b><span>' + esc(data.status || '') + '</span>' +
          '<b>Published</b><span>' + (data.published ? 'Yes' : 'No') + '</span></div>' +
          '<p>Changes are applied only following explicit owner review, approval and publication.</p></div>';
      }
    }
    // Keep the chat uncluttered: owner/self-only training insights belong
    // in the existing slide-out/side results area, never a persistent overlay.
    if (skill === 'food_monster.training_intelligence' && Array.isArray(data.participants)) {
      const people = data.participants.slice(0, 15);
      const shown = people.map((person) => {
        const current = person.current || {};
        const actions = person.actionCounts || {};
        const name = String(person.displayName || 'Participant');
        const source = person.sourceAvailable
          ? String(person.sourceTitle || 'Recorded procedure')
          : 'Procedure needs verification';
        const step = Number(current.stepIndex || 0) + 1;
        const total = Number(current.stepCount || 0);
        const attempts = Number(current.practiceAttempts || 0);
        const correct = Number(current.practiceCorrect || 0);
        const revisit = Number(person.revisits || 0);
        const help = Number(person.helpRequests || 0);
        const tips = person.coachingSuggestion
          ? '<p class="brain-coach">' + esc(person.coachingSuggestion) + '</p>' : '';
        return '<div class="result-card"><h3>' + esc(name) + '</h3>' +
          '<p>' + esc(source) + ' · ' + esc(current.status || 'unavailable') + '</p>' +
          '<div class="kv"><b>Current step</b><span>' + esc(step) + ' / ' + esc(total) + '</span>' +
          '<b>Practice choices</b><span>' + esc(correct) + ' of ' + esc(attempts) + ' selections</span>' +
          '<b>Recorded revisits</b><span>' + esc(revisit) + '</span>' +
          '<b>Help requests</b><span>' + esc(help) + '</span>' +
          '<b>Knowledge checks</b><span>' + esc(Number(actions.quiz || 0)) + '</span></div>' +
          tips + '</div>';
      }).join('');
      return '<div class="result-card"><h3>Guided training activity</h3>' +
        '<span class="pill">' + esc(Number(data.peopleWithActivity || 0)) + ' participants · ' +
          esc(Number(data.recordedEvents || 0)) + ' recorded events</span>' +
        '<p>60-day window' + (data.isPartial ? ' · partial history' : '') +
        '. Practice observations only, not employee ratings or certification.</p></div>' +
        (shown || '<div class="empty">No recorded activity in this view.</div>') +
        (people.length < data.participants.length
          ? '<div class="empty">Additional authorized participants are in the chat response.</div>' : '');
    }
    const rows = Array.isArray(data) ? data : [data];
    let html = `
      <div class="result-card">
        <h3>${esc(skill || 'Agent result')}</h3>
        <span class="pill">${rows.length} record${rows.length === 1 ? '' : 's'}</span>
      </div>
    `;
    html += rows.slice(0, 30).map((row, index) => {
      if (row === null || typeof row !== 'object') {
        return `<div class="result-card"><div>${esc(row)}</div></div>`;
      }
      const entries = Object.entries(row)
        .filter(([, value]) => value === null || ['string', 'number', 'boolean'].includes(typeof value))
        .slice(0, 14);
      const title = row.name || row.title || row.business_name || row.display_name || `Result ${index + 1}`;
      return `
        <div class="result-card">
          <h3>${esc(title)}</h3>
          <div class="kv">
            ${entries.map(([key, value]) => `<b>${esc(key.replace(/_/g, ' '))}</b><span>${esc(value)}</span>`).join('')}
          </div>
        </div>
      `;
    }).join('');
    if (sources.length) {
      html += `
        <div class="result-card">
          <h3>Sources</h3>
          <div>${sources.map((source) => `<span class="pill">${esc(source)}</span>`).join(' ')}</div>
        </div>
      `;
    }
    return html;
  }

  function renderMessages(messages) {
    const root = $('#canvasMessages');
    if (!root) return;
    if (!messages.length) {
      root.innerHTML = '<div class="empty">Start a conversation with Gelato. Your messages and operational results will remain available here.</div>';
      $('#resultBody').innerHTML = '<div class="empty">Structured Agent results will appear here.</div>';
      return;
    }
    root.innerHTML = messages.map((message) => `
      <article class="msg ${message.role === 'user' ? 'user' : 'agent'}">
        <div>
          <div class="bubble">${esc(message.content)}</div>
          <div class="meta">${message.role === 'user' ? 'You' : 'Gelato'} · ${esc(message.channel || 'text')}${message.skill ? ' · ' + esc(message.skill) : ''} · ${esc(fmt(message.created_at))}</div>
        </div>
      </article>
    `).join('');
    root.scrollTop = root.scrollHeight;
    const structured = [...messages].reverse().find((message) => message.structured != null);
    $('#resultBody').innerHTML = structured
      ? structuredCard(structured.structured, structured.skill, structured.sources || [])
      : '<div class="empty">No structured result in this conversation yet.</div>';
  }

  async function load() {
    if (loading) return;
    loading = true;
    try {
      const data = await req('api/agent-workspace.php?action=bootstrap');
      csrf = data.csrf;
      threads = data.threads || [];
      active = active || data.activeThread || '';
      window.GelatoGlobalAgent?.selectThread?.(active);
      renderThreads();
      if (active) await openThread(active);
    } finally {
      loading = false;
    }
  }

  async function openThread(id) {
    const data = await req('api/agent-workspace.php?action=thread&id=' + encodeURIComponent(id));
    if (!data.thread) throw new Error('Conversation not found.');
    // Clear on a true thread change, not the normal response repaint.
    if(active!==id)document.getElementById('gaTrainingUpdate')?.remove();
    active = id;
    window.GelatoGlobalAgent?.selectThread?.(id);
    $('#threadTitle').textContent = data.thread.title || 'Restaurant Agent';
    $('#threadMeta').textContent = `${data.messages?.length || 0} messages · permission-scoped`;
    renderMessages(data.messages || []);
    const list = await req('api/agent-workspace.php?action=threads');
    threads = list.threads || threads;
    renderThreads();
    $('#canvasSide').classList.remove('open');
  }

  async function newThread() {
    const data = await post({action: 'new_thread'});
    threads.unshift(data.thread);
    await openThread(data.thread.public_id);
    $('#canvasInput').focus();
  }

  async function submit() {
    const input = $('#canvasInput');
    const text = input.value.trim();
    if (!text || !window.GelatoGlobalAgent) return;
    input.value = '';
    status('Gelato is working…');
    try {
      await window.GelatoGlobalAgent.send(text, false);
    } catch (error) {
      status(error.message, 4000);
    }
  }

  $('#newThread').onclick = () => newThread().catch((error) => status(error.message, 4000));
  $('#canvasSend').onclick = submit;
  $('#canvasInput').addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      submit();
    }
  });
  $('#canvasMic').onclick = () => window.GelatoGlobalAgent?.talkOnce();
  $('#mobileThreads').onclick = () => $('#canvasSide').classList.toggle('open');

  window.addEventListener('gelato-agent-ready', () => {
    load().catch((error) => status(error.message, 5000));
  });

  window.addEventListener('gelato-agent-response', async (event) => {
    try {
      if (event.detail?.threadId === active) {
        await openThread(active);
        const result = event.detail.result || {};
        if (result.data != null) $('#resultBody').innerHTML = structuredCard(result.data, result.skill, result.sources || []);
      }
      status('');
    } catch (error) {
      status(error.message, 4000);
    }
  });

  if (window.GelatoGlobalAgent?.user) load().catch((error) => status(error.message, 5000));
})();
