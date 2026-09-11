(() => {
  'use strict';
  const Auth = window.RestaurantAuth;
  if (!Auth?.current?.()) return;

  const serverMode = Boolean(window.RESTAURANT_SERVER_SESSION);
  const csrfToken = String(window.RESTAURANT_CSRF_TOKEN || '');
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
  const state = {documents:[], selectedId:null, settings:null};
  const can = permission => Boolean(Auth.has?.(permission));
  const $ = id => document.getElementById(id);

  function toast(message, bad = false) {
    let node = document.querySelector('.knowledge-admin-toast');
    if (!node) {
      node = document.createElement('div');
      node.className = 'knowledge-admin-toast';
      document.body.appendChild(node);
    }
    node.textContent = message;
    node.classList.toggle('bad', bad);
    node.classList.add('show');
    clearTimeout(node._timer);
    node._timer = setTimeout(() => node.classList.remove('show'), 3000);
  }

  async function api(url, options = {}) {
    if (!serverMode) throw new Error('This feature requires the PHP server and database-backed workspace.');
    const request = {...options, headers:{Accept:'application/json', ...(options.headers || {})}};
    if (request.method && request.method !== 'GET' && csrfToken) request.headers['X-CSRF-Token'] = csrfToken;
    if (request.body && !(request.body instanceof FormData) && typeof request.body !== 'string') {
      request.headers['Content-Type'] = 'application/json';
      request.body = JSON.stringify(request.body);
    }
    const response = await fetch(url, request);
    let data;
    try { data = await response.json(); }
    catch { throw new Error(`The server returned an invalid response (${response.status}).`); }
    if (!response.ok || data.ok === false) throw new Error(data.message || `Request failed (${response.status}).`);
    return data;
  }

  function injectStyles() {
    if ($('publicAgentAdminStyles')) return;
    const style = document.createElement('style');
    style.id = 'publicAgentAdminStyles';
    style.textContent = `
      .agent-admin-grid{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(320px,.95fr);gap:18px;align-items:start}
      .agent-settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}.agent-settings-grid .wide{grid-column:1/-1}
      .agent-toggle-row{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:17px;border:1px solid var(--line);border-radius:16px;background:#faf9f5}.agent-toggle-row strong{display:block}.agent-toggle-row span{display:block;margin-top:4px;color:var(--muted);font-size:11px;line-height:1.45}
      .agent-switch{position:relative;width:50px;height:28px;flex:0 0 auto}.agent-switch input{opacity:0;width:0;height:0}.agent-switch i{position:absolute;inset:0;border-radius:99px;background:#d5d4ce;transition:.2s}.agent-switch i:before{content:'';position:absolute;width:22px;height:22px;left:3px;top:3px;border-radius:50%;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.2);transition:.2s}.agent-switch input:checked+i{background:var(--accent)}.agent-switch input:checked+i:before{transform:translateX(22px)}
      .provider-readiness{display:grid;gap:8px}.provider-row{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:11px 12px;border:1px solid var(--line);border-radius:12px;background:#fff}.provider-row span{color:var(--muted);font-size:11px}.provider-state{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.05em}.provider-state.ready{color:var(--good)}.provider-state.missing{color:var(--warn)}
      .knowledge-layout{display:grid;grid-template-columns:minmax(280px,.72fr) minmax(0,1.28fr);gap:18px;align-items:start}.knowledge-list{display:grid;gap:8px;max-height:620px;overflow:auto}.knowledge-item{width:100%;padding:13px;border:1px solid var(--line);border-radius:13px;background:#fff;text-align:left}.knowledge-item.active{border-color:var(--accent);box-shadow:0 0 0 3px rgba(217,74,43,.1)}.knowledge-item strong{display:block;margin-bottom:5px}.knowledge-item span{display:block;color:var(--muted);font-size:10px;line-height:1.4}.knowledge-item-meta{display:flex!important;justify-content:space-between;gap:8px;margin-top:8px}.knowledge-status{color:var(--good)!important;font-weight:900}.knowledge-empty{padding:24px;text-align:center;color:var(--muted);border:1px dashed var(--line);border-radius:14px}
      .knowledge-editor{display:grid;gap:13px}.knowledge-content{min-height:330px;resize:vertical;line-height:1.55}.knowledge-guidance{padding:14px;border-radius:14px;background:#f6f4ed;color:var(--muted);font-size:11px;line-height:1.55}.knowledge-guidance strong{color:var(--ink)}.knowledge-actions{display:flex;gap:8px;flex-wrap:wrap}.knowledge-upload{display:grid;grid-template-columns:1fr auto;gap:9px;align-items:end}.knowledge-upload .wide{grid-column:1/-1}
      .knowledge-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}.knowledge-stat{padding:14px;border:1px solid var(--line);border-radius:14px;background:#fff}.knowledge-stat small{display:block;color:var(--muted);font-size:9px;text-transform:uppercase;font-weight:900}.knowledge-stat strong{display:block;margin-top:5px;font-size:25px}
      .knowledge-admin-toast{position:fixed;z-index:9500;right:20px;bottom:20px;max-width:390px;padding:12px 15px;border-radius:12px;color:#fff;background:#171b1a;font-size:11px;font-weight:800;box-shadow:0 18px 50px rgba(0,0,0,.22);opacity:0;transform:translateY(8px);pointer-events:none;transition:.2s}.knowledge-admin-toast.bad{background:#a62a24}.knowledge-admin-toast.show{opacity:1;transform:none}
      @media(max-width:980px){.agent-admin-grid,.knowledge-layout{grid-template-columns:1fr}}@media(max-width:620px){.agent-settings-grid,.knowledge-upload,.knowledge-stats{grid-template-columns:1fr}.agent-settings-grid .wide,.knowledge-upload .wide{grid-column:auto}}
    `;
    document.head.appendChild(style);
  }

  function pageShell() {
    const content = document.querySelector('.content');
    const adminNav = document.querySelector('[data-nav-group="admin"]');
    if (!content || !adminNav) return false;

    if (can('public_agent.view') && !$('navPublicAgent')) {
      const button = document.createElement('button');
      button.type = 'button';
      button.id = 'navPublicAgent';
      button.className = 'nav-btn';
      button.dataset.publicAgentPage = 'public-agent';
      button.innerHTML = '<span class="nav-ico">✦</span><span>Public AI Agent</span>';
      adminNav.appendChild(button);
    }
    if (can('knowledge.view') && !$('navKnowledge')) {
      const button = document.createElement('button');
      button.type = 'button';
      button.id = 'navKnowledge';
      button.className = 'nav-btn';
      button.dataset.publicAgentPage = 'knowledge';
      button.innerHTML = '<span class="nav-ico">▤</span><span>Knowledge Center</span>';
      adminNav.appendChild(button);
    }

    if (!$('page-public-agent')) {
      const page = document.createElement('section');
      page.className = 'page standard-page admin-page';
      page.id = 'page-public-agent';
      page.innerHTML = `
        <div class="page-head"><div><p class="eyebrow">Public website assistant</p><h3>Public AI Agent</h3><p>Control the AI assistant shown on the public landing page. The browser never receives the provider API key.</p></div><div class="head-actions"><a class="btn btn-light" href="landing.html" target="_blank">Open landing page ↗</a><button class="btn btn-primary" id="savePublicAgent" type="button">Save settings</button></div></div>
        <div class="agent-admin-grid">
          <section class="card"><div class="agent-toggle-row"><div><strong>Landing-page AI agent</strong><span>Turn the public chat widget on or off without deleting its configuration.</span></div><label class="agent-switch"><input id="publicAgentEnabled" type="checkbox"><i></i></label></div><div class="setup-divider"></div>
            <div class="agent-settings-grid">
              <div><label for="publicAgentName">Agent name</label><input class="field" id="publicAgentName" maxlength="120"></div>
              <div><label for="publicAgentProvider">Provider</label><select class="field" id="publicAgentProvider"><option value="auto">Auto-select configured provider</option><option value="anthropic">Anthropic Claude</option><option value="openai">OpenAI</option></select></div>
              <div class="wide"><label for="publicAgentWelcome">Welcome message</label><textarea class="field" id="publicAgentWelcome" rows="3" maxlength="500"></textarea></div>
              <div><label for="publicAgentPlaceholder">Input placeholder</label><input class="field" id="publicAgentPlaceholder" maxlength="220"></div>
              <div><label for="publicAgentModel">Model identifier</label><input class="field" id="publicAgentModel" maxlength="160" placeholder="Leave blank for provider default"></div>
              <div><label for="publicAgentChunks">Knowledge chunks per answer</label><select class="field" id="publicAgentChunks">${[3,4,5,6,7,8,9,10].map(value=>`<option value="${value}">${value}</option>`).join('')}</select></div>
              <div class="wide"><label for="publicAgentPrompt">System instructions</label><textarea class="field" id="publicAgentPrompt" rows="9" maxlength="6000"></textarea></div>
            </div>
          </section>
          <aside class="card"><h4>Provider readiness</h4><p class="muted tiny">Keys are managed under LLM API Keys and remain encrypted on the server.</p><div class="provider-readiness" id="publicAgentProviders"></div><div class="setup-divider"></div><h4>Privacy and retrieval</h4><p class="muted tiny">The first release is stateless. Visitor messages are not saved by the application. Answers use only documents enabled in the Knowledge Center, and requests are rate-limited per browser session.</p></aside>
        </div>`;
      content.appendChild(page);
    }

    if (!$('page-knowledge')) {
      const page = document.createElement('section');
      page.className = 'page standard-page admin-page';
      page.id = 'page-knowledge';
      page.innerHTML = `
        <div class="page-head"><div><p class="eyebrow">Agent-ready restaurant knowledge</p><h3>Knowledge Center</h3><p>Create clear reference documents or import simple text files. Saving automatically normalizes, hashes, chunks, and indexes the content for retrieval.</p></div><div class="head-actions"><button class="btn btn-light" id="newKnowledgeDocument" type="button">New document</button></div></div>
        <div class="knowledge-stats" id="knowledgeStats"></div>
        <div class="knowledge-layout">
          <aside class="card"><div class="admin-card-head"><div><h4>Documents</h4><p>Published documents can be included in public-agent answers.</p></div></div><input class="field" id="knowledgeSearch" placeholder="Search documents…"><div class="setup-divider"></div><div class="knowledge-list" id="knowledgeList"></div></aside>
          <section class="card"><div class="knowledge-editor">
            <div><label for="knowledgeTitle">Document title</label><input class="field" id="knowledgeTitle" maxlength="240" placeholder="Example: Hours, parking, and contact information"></div>
            <div><label for="knowledgeContent">Document content</label><textarea class="field knowledge-content" id="knowledgeContent" maxlength="1000000" placeholder="Type restaurant information here…"></textarea></div>
            <label class="choice-option"><input type="checkbox" id="knowledgeAgentEnabled" checked><span>Allow the public AI agent to use this document</span></label>
            <div class="knowledge-guidance"><strong>Write for reliable retrieval:</strong> use a descriptive title, short headings, exact menu or job terms, one fact per paragraph, and explicit values for hours, prices, policies, addresses, and contact details. Do not include employee-only or sensitive information in public-agent documents.</div>
            <div class="knowledge-actions"><button class="btn btn-primary" id="saveKnowledgeDocument" type="button">Save and index</button><button class="btn btn-danger" id="deleteKnowledgeDocument" type="button">Delete</button></div>
            <div class="setup-divider"></div>
            <div class="knowledge-upload"><div><label for="knowledgeUploadTitle">Import title</label><input class="field" id="knowledgeUploadTitle" maxlength="240" placeholder="Optional — filename used when blank"></div><button class="btn btn-dark" id="uploadKnowledgeFile" type="button">Import file</button><div class="wide"><label for="knowledgeUploadFile">Text file</label><input class="field" id="knowledgeUploadFile" type="file" accept=".txt,.md,.markdown,.csv,.json,.html,.htm,.xml,text/plain,text/markdown,text/csv,application/json,text/html,application/xml,text/xml"></div></div>
          </div></section>
        </div>`;
      content.appendChild(page);
    }
    return true;
  }

  function activate(name) {
    const page = $(`page-${name}`);
    if (!page) return;
    document.querySelectorAll('.page').forEach(item => item.classList.remove('active'));
    page.classList.add('active');
    document.querySelectorAll('.nav-btn').forEach(item => item.classList.remove('active'));
    const nav = name === 'public-agent' ? $('navPublicAgent') : $('navKnowledge');
    nav?.classList.add('active');
    const title = document.querySelector('.top-title h2');
    const subtitle = document.querySelector('.top-title span');
    if (title) title.textContent = name === 'public-agent' ? 'Public AI Agent' : 'Knowledge Center';
    if (subtitle) subtitle.textContent = name === 'public-agent' ? 'Public website assistant configuration' : 'Agent-ready restaurant reference documents';
    history.replaceState(null, '', `#${name}`);
    if (name === 'public-agent') loadSettings(); else loadDocuments();
  }

  function renderSettings(settings) {
    state.settings = settings;
    $('publicAgentEnabled').checked = Boolean(settings.enabled);
    $('publicAgentName').value = settings.agentName || '';
    $('publicAgentWelcome').value = settings.welcomeMessage || '';
    $('publicAgentPlaceholder').value = settings.inputPlaceholder || '';
    $('publicAgentProvider').value = settings.provider || 'auto';
    $('publicAgentModel').value = settings.model || '';
    $('publicAgentPrompt').value = settings.systemPrompt || '';
    $('publicAgentChunks').value = String(settings.maxContextChunks || 6);
    $('publicAgentProviders').innerHTML = (settings.providers || []).map(provider => `
      <div class="provider-row"><div><strong>${esc(provider.displayName)}</strong><span>${provider.configured ? esc(provider.maskedKey) : 'No API key configured'}</span></div><span class="provider-state ${provider.configured ? 'ready' : 'missing'}">${provider.configured ? 'Ready' : 'Missing'}</span></div>`).join('');
    $('savePublicAgent').disabled = !can('public_agent.edit');
  }

  async function loadSettings() {
    if (!can('public_agent.view')) return;
    try {
      const data = await api('api/public-agent-settings.php', {method:'GET'});
      renderSettings(data.settings);
    } catch (error) {
      toast(error.message, true);
      if ($('publicAgentProviders')) $('publicAgentProviders').innerHTML = `<div class="knowledge-empty">${esc(error.message)}</div>`;
    }
  }

  async function saveSettings() {
    try {
      const data = await api('api/public-agent-settings.php', {method:'POST', body:{
        enabled:$('publicAgentEnabled').checked,
        agentName:$('publicAgentName').value.trim(),
        welcomeMessage:$('publicAgentWelcome').value.trim(),
        inputPlaceholder:$('publicAgentPlaceholder').value.trim(),
        provider:$('publicAgentProvider').value,
        model:$('publicAgentModel').value.trim(),
        systemPrompt:$('publicAgentPrompt').value.trim(),
        maxContextChunks:Number($('publicAgentChunks').value || 6),
      }});
      renderSettings(data.settings);
      toast(data.message);
    } catch (error) { toast(error.message, true); }
  }

  function renderStats(summary = {}) {
    $('knowledgeStats').innerHTML = [
      ['Documents', summary.documents || 0],
      ['Available to agent', summary.agentEnabled || 0],
      ['Indexed chunks', summary.chunks || 0],
    ].map(item => `<div class="knowledge-stat"><small>${item[0]}</small><strong>${item[1]}</strong></div>`).join('');
  }

  function renderList() {
    const query = ($('knowledgeSearch')?.value || '').trim().toLowerCase();
    const documents = state.documents.filter(document => !query || `${document.title} ${document.originalFilename}`.toLowerCase().includes(query));
    $('knowledgeList').innerHTML = documents.length ? documents.map(document => `
      <button class="knowledge-item ${state.selectedId === document.id ? 'active' : ''}" type="button" data-knowledge-id="${esc(document.id)}"><strong>${esc(document.title)}</strong><span>${document.sourceType === 'upload' ? `Imported · ${esc(document.originalFilename || 'text file')}` : 'Typed document'}</span><span class="knowledge-item-meta"><span>${document.chunkCount} chunks · v${document.version}</span><span class="knowledge-status">${document.agentEnabled ? 'Agent on' : 'Agent off'}</span></span></button>`).join('') : '<div class="knowledge-empty">No matching knowledge documents.</div>';
    $('knowledgeList').querySelectorAll('[data-knowledge-id]').forEach(button => button.onclick = () => openDocument(button.dataset.knowledgeId));
  }

  function clearEditor() {
    state.selectedId = null;
    $('knowledgeTitle').value = '';
    $('knowledgeContent').value = '';
    $('knowledgeAgentEnabled').checked = true;
    $('deleteKnowledgeDocument').disabled = true;
    $('saveKnowledgeDocument').disabled = !can('knowledge.create');
    renderList();
    $('knowledgeTitle').focus();
  }

  async function loadDocuments(selectId = null) {
    if (!can('knowledge.view')) return;
    try {
      const data = await api('api/knowledge.php', {method:'GET'});
      state.documents = data.documents || [];
      renderStats(data.summary || {});
      renderList();
      if (selectId) await openDocument(selectId);
      else if (state.selectedId && state.documents.some(document => document.id === state.selectedId)) await openDocument(state.selectedId);
      else if (!state.selectedId) clearEditor();
    } catch (error) {
      toast(error.message, true);
      renderStats();
      $('knowledgeList').innerHTML = `<div class="knowledge-empty">${esc(error.message)}</div>`;
    }
  }

  async function openDocument(id) {
    try {
      const data = await api(`api/knowledge.php?id=${encodeURIComponent(id)}`, {method:'GET'});
      const document = data.document;
      state.selectedId = document.id;
      $('knowledgeTitle').value = document.title || '';
      $('knowledgeContent').value = document.content || '';
      $('knowledgeAgentEnabled').checked = Boolean(document.agentEnabled);
      $('saveKnowledgeDocument').disabled = !can('knowledge.edit');
      $('deleteKnowledgeDocument').disabled = !can('knowledge.delete');
      renderList();
    } catch (error) { toast(error.message, true); }
  }

  async function saveDocument() {
    const isUpdate = Boolean(state.selectedId);
    if (isUpdate && !can('knowledge.edit')) return toast('You do not have permission to edit knowledge documents.', true);
    if (!isUpdate && !can('knowledge.create')) return toast('You do not have permission to create knowledge documents.', true);
    try {
      const data = await api('api/knowledge.php', {method:'POST', body:{
        id:state.selectedId || '',
        title:$('knowledgeTitle').value.trim(),
        content:$('knowledgeContent').value,
        agentEnabled:$('knowledgeAgentEnabled').checked,
      }});
      state.selectedId = data.document.id;
      toast(data.message);
      await loadDocuments(state.selectedId);
    } catch (error) { toast(error.message, true); }
  }

  async function deleteDocument() {
    if (!state.selectedId || !can('knowledge.delete')) return;
    const document = state.documents.find(item => item.id === state.selectedId);
    if (!confirm(`Delete “${document?.title || 'this document'}” and all generated chunks?`)) return;
    try {
      const data = await api('api/knowledge.php', {method:'DELETE', body:{id:state.selectedId}});
      toast(data.message);
      clearEditor();
      await loadDocuments();
    } catch (error) { toast(error.message, true); }
  }

  async function uploadFile() {
    if (!can('knowledge.create')) return toast('You do not have permission to import knowledge files.', true);
    const file = $('knowledgeUploadFile').files?.[0];
    if (!file) return toast('Choose a supported text file first.', true);
    const form = new FormData();
    form.append('title', $('knowledgeUploadTitle').value.trim());
    form.append('agentEnabled', 'true');
    form.append('file', file);
    try {
      const data = await api('api/knowledge.php', {method:'POST', body:form});
      $('knowledgeUploadTitle').value = '';
      $('knowledgeUploadFile').value = '';
      state.selectedId = data.document.id;
      toast(data.message);
      await loadDocuments(state.selectedId);
    } catch (error) { toast(error.message, true); }
  }

  function bind() {
    $('navPublicAgent')?.addEventListener('click', () => activate('public-agent'));
    $('navKnowledge')?.addEventListener('click', () => activate('knowledge'));
    $('savePublicAgent')?.addEventListener('click', saveSettings);
    $('newKnowledgeDocument')?.addEventListener('click', clearEditor);
    $('saveKnowledgeDocument')?.addEventListener('click', saveDocument);
    $('deleteKnowledgeDocument')?.addEventListener('click', deleteDocument);
    $('uploadKnowledgeFile')?.addEventListener('click', uploadFile);
    $('knowledgeSearch')?.addEventListener('input', renderList);
    document.querySelectorAll('.nav-btn:not(#navPublicAgent):not(#navKnowledge)').forEach(button => button.addEventListener('click', () => {
      $('page-public-agent')?.classList.remove('active');
      $('page-knowledge')?.classList.remove('active');
    }));
  }

  function init() {
    if (!can('public_agent.view') && !can('knowledge.view')) return;
    injectStyles();
    if (!pageShell()) return;
    bind();
    const hash = location.hash.slice(1);
    if (hash === 'public-agent' && can('public_agent.view')) activate('public-agent');
    if (hash === 'knowledge' && can('knowledge.view')) activate('knowledge');
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => setTimeout(init, 0));
  else setTimeout(init, 0);
})();
