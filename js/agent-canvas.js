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

  function structuredCard(data, skill, sources = []) {
    if (data == null) return '<div class="empty">No structured result for this response.</div>';
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
