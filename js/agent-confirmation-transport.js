/* Exact, conversation-scoped proposal evidence. No session-wide latest fallback. */
(() => {
  'use strict';
  if (window.GelatoAgentReview) return;
  const reviews = new Map(), conversations = new Map(), pendingDisplays = new WeakMap();
  let generation = 0;
  const decision = /^(?:confirm|yes|yes please|do it|go ahead|execute|apply|cancel|cancel it|discard|never mind|nevermind|stop|approve|reject)(?:\s+(?:it|that|change|action|this request|that request|this proposal|that proposal|approval))?[.!]?$/i;
  const threadPattern = /^agent-[a-f0-9]{20}$/;
  function proposal(message) {
    const data = message?.structured ?? message?.data;
    return data?.proposal ?? data?.data?.proposal ?? data?.responseData?.proposal ?? null;
  }
  function remember(threadId, message) {
    const value = proposal(message), messageId = String(message?.id || message?.public_id || '');
    if (!value || value.threadId !== threadId || value.messageId !== messageId
        || !threadPattern.test(threadId) || !/^msg-[a-f0-9]{20}$/.test(messageId)
        || !/^(?:gap|pap|sap)-[a-f0-9]{16,32}$/.test(String(value.id || ''))
        || !/^[a-f0-9]{64}$/.test(String(value.hash || ''))
        || !/^[a-z][a-z0-9_]{1,80}$/.test(String(value.node || ''))
        || !Number.isFinite(Date.parse(value.expiresAt)) || Date.parse(value.expiresAt) <= Date.now()) return false;
    reviews.set(threadId, Object.freeze({...value}));
    return true;
  }
  function fields(threadId) {
    const value = reviews.get(threadId);
    if (!value || Date.parse(value.expiresAt) <= Date.now()) {
      reviews.delete(threadId);
      return {};
    }
    return {proposalId: value.id, proposalHash: value.hash, proposalMessageId: value.messageId};
  }
  function forget(threadId, expectedId) {
    if (!expectedId || reviews.get(threadId)?.id === expectedId) reviews.delete(threadId);
  }
  function restore(threadId, messages) {
    // Call only after these canonical messages have actually been rendered.
    const rendered = new Map();
    for (const message of messages || []) {
      if (message.role !== 'agent') continue;
      rendered.set(String(message.id || message.public_id || ''), message);
      remember(threadId, message);
      const data = message.structured?.responseData ?? message.structured;
      const handoff = data?.reviewedProposal;
      const original = rendered.get(String(handoff?.messageId || ''));
      if (original && handoff.threadId === threadId && proposal(original)?.id === handoff.id
          && proposal(original)?.hash === handoff.hash && proposal(original)?.node === handoff.node)
        remember(threadId, original);
    }
  }
  async function request(url, csrf, body) {
    const response = await fetch(url, {cache: 'no-store', credentials: 'same-origin',
      headers: {Accept: 'application/json', ...(body ? {'Content-Type': 'application/json', 'X-CSRF-Token': csrf} : {})},
      ...(body ? {method: 'POST', body: JSON.stringify({...body, csrf_token: csrf})} : {})});
    const data = await response.json().catch(() => ({ok: false, message: 'Invalid Agent response.'}));
    if (!response.ok || data.ok === false) {
      const error = new Error(data.message || 'Agent request failed.');
      error.status = response.status;
      throw error;
    }
    return data;
  }
  async function handoff(threadId, value, csrf) {
    const currentGeneration = generation;
    if (!value || value.threadId !== threadId) throw new Error('The approval handoff belongs to a different conversation.');
    const history = await request('api/agent-workspace.php?action=thread&id=' + encodeURIComponent(threadId), csrf);
    assertGeneration(currentGeneration);
    const message = (history.messages || []).find(message => message.role === 'agent'
      && message.public_id === value.messageId && proposal(message)?.id === value.id
      && proposal(message)?.hash === value.hash && proposal(message)?.node === value.node);
    if (!message) throw new Error('Reopen the original saved proposal before confirming the approved action.');
    return message;
  }
  function acknowledge(result) {
    const display = pendingDisplays.get(result);
    if (!display || display.generation !== generation) return false;
    pendingDisplays.delete(result);
    remember(display.conversation.threadId, display.handoff || display.message);
    persist(display.conversation);
    return true;
  }
  function purgeReviews(keepPrefix = '') {
    try {
      for (let i = sessionStorage.length - 1; i >= 0; i--) {
        const key = sessionStorage.key(i);
        if (key && (key.startsWith('gelato.agent.review.v2.')
          || key.startsWith('gelato-private-v1:') && key.includes(':agent-review:')
          && (!keepPrefix || !key.startsWith(keepPrefix)))) sessionStorage.removeItem(key);
      }
    } catch {}
  }
  function clear() {
    generation++;
    reviews.clear(); conversations.clear(); purgeReviews();
  }
  function assertGeneration(expected) {
    if (expected !== generation) throw new Error('Your signed-in session changed. Reload the page.');
  }
  function persist(conversation) {
    if (conversation.generation !== generation) return;
    const reviewed = reviews.get(conversation.threadId);
    // Persist only identifiers, never proposal prose or business payloads.
    const identity = reviewed ? {id: reviewed.id, hash: reviewed.hash, messageId: reviewed.messageId,
      threadId: reviewed.threadId, node: reviewed.node} : null;
    try { sessionStorage.setItem(conversation.storageKey, JSON.stringify({threadId: conversation.threadId,
      review: identity})); } catch { /* Memory-only is still safe. */ }
  }
  async function conversationFor(key, csrf) {
    if (conversations.has(key)) return conversations.get(key);
    const currentGeneration = generation;
    const pending = (async () => {
      const bootstrap = await request('api/agent-workspace.php?action=bootstrap', csrf);
      const scope = bootstrap.browserState;
      if (!bootstrap.user?.id || !Number.isSafeInteger(scope?.organizationId) || scope.organizationId < 1
          || scope.userId !== 'db-user-' + String(bootstrap.user.id) || !/^[a-f0-9]{32}$/.test(String(scope.identity || ''))
          || currentGeneration !== generation) throw new Error('Sign in again before using the Agent.');
      const prefix = 'gelato-private-v1:' + scope.organizationId + ':' + scope.userId + ':' + scope.identity + ':agent-review:';
      purgeReviews(prefix);
      const storageKey = prefix + key;
      const conversation = {threadId: '', storageKey, busy: false, generation: currentGeneration};
      let saved;
      try { saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null'); } catch {}
      if (threadPattern.test(String(saved?.threadId || ''))) {
        try {
          const history = await request('api/agent-workspace.php?action=thread&id=' + encodeURIComponent(saved.threadId), csrf);
          assertGeneration(currentGeneration);
          if (history.thread?.public_id === saved.threadId) {
            conversation.threadId = saved.threadId;
            // Restore only the exact message previously displayed in this tab.
            const reviewed = (history.messages || []).find(message =>
              message.public_id === saved.review?.messageId && proposal(message)?.id === saved.review?.id
              && proposal(message)?.hash === saved.review?.hash);
            if (reviewed) remember(saved.threadId, reviewed);
          }
        } catch (error) { if (![404, 422].includes(error.status)) throw error; }
      }
      if (!conversation.threadId) {
        assertGeneration(currentGeneration);
        const created = await request('api/agent-workspace.php', csrf, {action: 'new_thread'});
        assertGeneration(currentGeneration);
        conversation.threadId = created.thread.public_id;
      }
      assertGeneration(currentGeneration);
      persist(conversation);
      return conversation;
    })();
    conversations.set(key, pending);
    try { return await pending; } catch (error) {
      if (conversations.get(key) === pending) conversations.delete(key);
      throw error;
    }
  }
  async function ask(endpoint, input, options = {}) {
    if (!/^api\/[a-z][a-z0-9-]{1,80}\.php$/.test(endpoint)) throw new Error('Invalid Agent endpoint.');
    const csrf = String(options.csrf || input.csrf_token || input.csrf || '');
    const conversation = await conversationFor(String(options.key || endpoint), csrf);
    if (conversation.busy) throw new Error('Wait for this Agent response before sending another request.');
    assertGeneration(conversation.generation);
    conversation.busy = true;
    const threadId = conversation.threadId, reviewed = fields(threadId);
    try {
      const submitted = await request('api/agent-workspace.php', csrf, {action: 'append', threadId,
        role: 'user', content: String(input.message || ''), channel: input.voice ? 'voice' : 'text',
        voiceEventId: input.voice ? String(input.voiceEventId || '') : ''});
      assertGeneration(conversation.generation);
      const safeInput = {...input};
      for (const key of ['proposalId', 'proposalHash', 'proposalMessageId']) delete safeInput[key];
      const result = await request(endpoint, csrf, {...safeInput, ...reviewed, action: input.action || 'ask',
        threadId, sourceMessageId: submitted.message.id});
      assertGeneration(conversation.generation);
      let reviewedHandoff = null;
      if (result.data?.reviewedProposal) {
        reviewedHandoff = await handoff(threadId, result.data.reviewedProposal, csrf);
        assertGeneration(conversation.generation);
        result.answer = (result.answer || result.reply || 'Approval saved.') + '\n\n' + reviewedHandoff.content;
      }
      const saved = await request('api/agent-workspace.php', csrf, {action: 'append', threadId,
        role: 'agent', content: result.answer || result.reply || 'Done.', channel: input.voice ? 'voice' : 'text',
        skill: result.skill || 'agent', tool: endpoint, structured: result.data ?? null, sources: result.sources || []});
      assertGeneration(conversation.generation);
      if (decision.test(String(input.message || '').trim())) forget(threadId, reviewed.proposalId);
      pendingDisplays.set(result, {conversation, message: saved.message, handoff: reviewedHandoff, generation});
      result.answer = saved.message.content;
      result.data = saved.message.structured;
      persist(conversation);
      return result;
    } finally { conversation.busy = false; }
  }
  async function voiceConversation(options = {}) {
    const agent=window.GelatoGlobalAgent;
    if(!agent?.user||typeof agent.talkOnce!=='function')
      throw new Error('Gelato Voice Agent is not ready. Reload the page or use the typed controls.');
    if(agent.busy)throw new Error('Wait for the current Agent response before speaking.');
    const conversation=await conversationFor(String(options.key||''),String(options.csrf||''));
    assertGeneration(conversation.generation);
    if(conversation.busy)throw new Error('Wait for this Agent response before speaking.');
    const current=String(agent.threadId||'');
    if(current&&current!==conversation.threadId&&fields(current).proposalId)
      throw new Error('Review and Confirm or Cancel the pending action in the current Gelato conversation before switching microphones.');
    // Keep the page's previously displayed proposal and canonical history.
    // Never copy microphone text into the page's typed-send handler.
    agent.selectThread(conversation.threadId);
    window.GelatoDynamicAgentCanvas?.openDrawer?.();
    return conversation;
  }
  async function microphone(options = {}) {
    const conversation=await voiceConversation(options),agent=window.GelatoGlobalAgent;
    assertGeneration(conversation.generation);
    conversation.busy=true;
    try{return await agent.talkOnce({threadId:conversation.threadId});}
    finally{conversation.busy=false;persist(conversation);}
  }
  window.addEventListener?.('gelato:private-state-cleared', clear);
  window.addEventListener?.('storage', event => {
    if (event.newValue && ['gelato-workspace-logout-v1', 'gelato-workspace-principal-v1'].includes(event.key)) clear();
  });
  window.GelatoAgentReview = {remember, fields, forget, restore, ask, microphone, voiceConversation, acknowledge, handoff, clear,
    get generation() { return generation; }, assertGeneration,
    isDecision: text => decision.test(String(text || '').trim())};
})();
