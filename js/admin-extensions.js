(() => {
  'use strict';

  if (!window.RestaurantAuth) return;

  const Auth = window.RestaurantAuth;
  const $ = id => document.getElementById(id);
  const serverMode = Boolean(window.RESTAURANT_SERVER_SESSION);
  const csrfToken = String(window.RESTAURANT_CSRF_TOKEN || '');
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
  const localNotificationReadKey = `restaurant-notification-read-v1:${Auth.current()?.id || 'guest'}`;
  const state = {
    notifications: [],
    notificationFilter: 'all',
    brand: null,
    llmProviders: [],
    rolesLoaded: false,
  };

  function toast(message, bad = false) {
    let node = document.querySelector('.admin-extension-toast');
    if (!node) {
      node = document.createElement('div');
      node.className = 'admin-extension-toast';
      Object.assign(node.style, {
        position: 'fixed', zIndex: '9000', right: '20px', bottom: '20px', maxWidth: '380px',
        padding: '12px 15px', borderRadius: '12px', color: '#fff', fontSize: '11px',
        fontWeight: '800', boxShadow: '0 18px 50px rgba(0,0,0,.22)', transition: '.2s'
      });
      document.body.appendChild(node);
    }
    node.style.background = bad ? '#a62a24' : '#171b1a';
    node.textContent = message;
    node.style.opacity = '1';
    clearTimeout(node._timer);
    node._timer = setTimeout(() => { node.style.opacity = '0'; }, 3000);
  }

  async function apiRequest(url, options = {}) {
    const request = {...options};
    request.headers = {...(options.headers || {}), Accept: 'application/json'};
    if (csrfToken && request.method && request.method !== 'GET') {
      request.headers['X-CSRF-Token'] = csrfToken;
    }
    if (request.body && !(request.body instanceof FormData) && typeof request.body !== 'string') {
      request.headers['Content-Type'] = 'application/json';
      request.body = JSON.stringify(request.body);
    }
    const response = await fetch(url, request);
    let data;
    try {
      data = await response.json();
    } catch {
      throw new Error(`The server returned an invalid response (${response.status}).`);
    }
    if (!response.ok || data.ok === false) {
      throw new Error(data.message || `Request failed (${response.status}).`);
    }
    return data;
  }

  function formatRelative(value) {
    if (!value) return 'Recently';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Recently';
    const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
    if (seconds < 60) return 'Just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;
    return new Intl.DateTimeFormat('en-US', {month:'short', day:'numeric'}).format(date);
  }

  function notificationIcon(type = '') {
    if (type.includes('resume')) return '▣';
    if (type.includes('quiz') || type.includes('training') || type.includes('flash')) return '✓';
    if (type.includes('role') || type.includes('permission')) return '⚙';
    if (type.includes('user') || type.includes('account')) return '♙';
    if (type.includes('brand')) return '◐';
    if (type.includes('llm')) return '⌁';
    if (type.includes('login') || type.includes('security') || type.includes('password')) return '◉';
    if (type.includes('certification')) return '★';
    return '•';
  }

  function localReadIds() {
    try { return new Set(JSON.parse(localStorage.getItem(localNotificationReadKey) || '[]')); }
    catch { return new Set(); }
  }

  function collectLocalNotifications() {
    const user = Auth.current();
    if (!user) return [];
    const readIds = localReadIds();
    const list = [];
    const push = item => list.push({...item, readAt: readIds.has(item.id) ? new Date().toISOString() : null});
    const canSeeOrganization = Auth.has('audit.view', user) || Auth.has('users.view', user) || Auth.has('resumes.view', user);
    const audits = Auth.read('restaurant-audit-v1', []);
    const auditCopy = {
      'role.created':['Account type created','A new account type and permission set was added.','roles'],
      'role.updated':['Account permissions updated','An account type permission set was changed.','roles'],
      'brand.updated':['Brand settings updated','Restaurant identity or public-page branding was changed.','brand'],
      'llm_key.updated':['LLM key updated','An encrypted provider credential was added or replaced.','llm'],
      'llm_key.removed':['LLM key removed','A provider credential was removed.','llm'],
      'resume.status_changed':['Resume status updated','An applicant moved to a new hiring status.','resumes'],
      'resume.note_added':['Resume note added','A private reviewer note was added to an applicant.','resumes'],
      'user.created':['User account created','A new training account was created.','users'],
      'user.updated':['User account updated','An account profile or access setting changed.','users'],
      'profile.updated':['Profile updated','Your personal profile settings were changed.','profile'],
      'form.published':['Resume form published','A new public application-form version is live.','forms'],
      'public_page.published':['Landing page published','The public recruitment landing page was updated.','landing-builder'],
    };
    audits.slice(0, 40).forEach(audit => {
      if (!canSeeOrganization && audit.actorUserId !== user.id) return;
      const copy = auditCopy[audit.action] || ['Recent account activity', `${audit.actorName || 'A user'} completed ${String(audit.action || 'an action').replaceAll('.', ' ')}.`, 'dashboard'];
      push({
        id: `audit-${audit.id}`,
        type: audit.action || 'activity',
        title: copy[0],
        message: canSeeOrganization ? `${audit.actorName || 'A user'}: ${copy[1]}` : copy[1],
        actionPage: copy[2],
        createdAt: audit.createdAt,
      });
    });
    if (Auth.has('resumes.view', user)) {
      const resumes = Auth.read('restaurant-resumes-v1', []);
      resumes.filter(resume => resume.status === 'new').slice(0, 10).forEach(resume => push({
        id: `resume-new-${resume.id}`,
        type: 'resume.new',
        title: 'New resume submitted',
        message: `${resume.firstName} ${resume.lastName} applied for ${resume.position || 'a restaurant position'}.`,
        actionUrl: `resume.php?id=${encodeURIComponent(resume.id)}&return=${encodeURIComponent('index.php#notifications')}`,
        createdAt: resume.submittedAt,
      }));
    }
    const quizHistory = Auth.read('restaurant-quiz-history', []);
    quizHistory.slice(0, 5).forEach((attempt, index) => push({
      id: `quiz-${attempt.id || attempt.completedAt || index}`,
      type: 'quiz.completed',
      title: 'Learning quiz completed',
      message: `Score recorded: ${attempt.percent ?? attempt.score ?? 0}%.`,
      actionPage: 'dashboard',
      createdAt: attempt.completedAt || attempt.date || new Date().toISOString(),
    }));
    const daily = Auth.read('restaurant-daily-history', []);
    daily.slice(0, 3).forEach((attempt, index) => push({
      id: `daily-${attempt.id || attempt.completedAt || index}`,
      type: 'daily_training.completed',
      title: 'Daily training completed',
      message: `Daily session score: ${attempt.percent ?? attempt.score ?? 0}%.`,
      actionPage: 'daily',
      createdAt: attempt.completedAt || attempt.date || new Date().toISOString(),
    }));
    const flashHistory = Auth.read('restaurant-flash-history', []);
    flashHistory.slice(0, 4).forEach((attempt, index) => push({
      id: `flash-${attempt.id || attempt.date || index}`,
      type: 'flashcard_session.completed',
      title: 'Flashcard session completed',
      message: `${attempt.cardsViewed || 0} cards viewed · ${attempt.confidenceRate || 0}% confidence.`,
      actionPage: 'flashcards',
      createdAt: attempt.date || new Date().toISOString(),
    }));
    const kitchenHistory = Auth.read('restaurant-kitchen-results', []);
    kitchenHistory.slice(0, 4).forEach((attempt, index) => push({
      id: `kitchen-${attempt.id || attempt.date || index}`,
      type: 'kitchen_verification.completed',
      title: 'Kitchen verification completed',
      message: `Kitchen ticket score: ${attempt.score || 0}%${attempt.passed ? ' · Passed' : ' · Needs review'}.`,
      actionPage: 'kitchen',
      createdAt: attempt.date || new Date().toISOString(),
    }));
    const certifications = Auth.read('restaurant-certifications', []);
    certifications.slice(0, 4).forEach((certificate, index) => push({
      id: `cert-${certificate.id || certificate.date || index}`,
      type: 'certification.issued',
      title: 'Position certification issued',
      message: `${certificate.positionName || certificate.positionId || 'Restaurant position'} certification was issued.`,
      actionPage: 'certifications',
      createdAt: certificate.date || new Date().toISOString(),
    }));
    if (!list.length) {
      push({
        id: 'workspace-welcome', type: 'account.welcome', title: 'Welcome to the training workspace',
        message: 'Recent training, account, hiring, and administrative activity will appear here.',
        actionPage: 'dashboard', createdAt: new Date().toISOString(),
      });
    }
    return list.sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt)).slice(0, 80);
  }

  async function recordServerActivity(detail) {
    if (!serverMode || !detail || typeof detail !== 'object') return;
    const eventType = String(detail.eventType || '');
    const sourceId = String(detail.sourceId || '');
    if (!eventType || !sourceId) return;
    const syncKey = `restaurant-activity-sync-v1:${Auth.current()?.id || 'guest'}`;
    let synced = [];
    try { synced = JSON.parse(localStorage.getItem(syncKey) || '[]'); } catch { synced = []; }
    const token = `${eventType}:${sourceId}`;
    if (synced.includes(token)) return;
    try {
      await apiRequest('api/notifications.php', {
        method:'POST',
        body:{
          action:'record_activity',
          eventType,
          sourceId,
          occurredAt:detail.occurredAt || new Date().toISOString(),
          summary:detail.summary || {},
        },
      });
      synced.unshift(token);
      localStorage.setItem(syncKey, JSON.stringify(synced.slice(0, 300)));
      await loadNotifications(false);
    } catch (error) {
      console.warn('Training activity notification could not be synchronized.', error);
    }
  }

  async function loadNotifications(showError = false) {
    try {
      if (serverMode) {
        const data = await apiRequest('api/notifications.php?limit=80', {method:'GET'});
        state.notifications = data.notifications || [];
      } else {
        state.notifications = collectLocalNotifications();
      }
      renderNotificationUI();
    } catch (error) {
      state.notifications = collectLocalNotifications();
      renderNotificationUI();
      if (showError) toast(error.message, true);
    }
  }

  function unreadNotifications() {
    return state.notifications.filter(item => !item.readAt);
  }

  function renderNotificationUI() {
    const unread = unreadNotifications().length;
    const bellCount = $('notificationBellCount');
    const profileCount = $('profileNotificationCount');
    if (bellCount) {
      bellCount.textContent = unread > 99 ? '99+' : String(unread);
      bellCount.classList.toggle('hidden', unread === 0);
    }
    if (profileCount) {
      profileCount.textContent = unread > 99 ? '99+' : String(unread);
      profileCount.classList.toggle('hidden', unread === 0);
    }
    const oldCount = $('headerNotificationCount');
    if (oldCount) oldCount.classList.add('hidden');
    if ($('notificationDropdownMeta')) $('notificationDropdownMeta').textContent = unread ? `${unread} unread notification${unread === 1 ? '' : 's'}` : 'You are all caught up';
    if ($('notificationPreviewList')) {
      const preview = state.notifications.slice(0, 6);
      $('notificationPreviewList').innerHTML = preview.length ? preview.map(item => `
        <button class="notification-preview ${item.readAt ? '' : 'unread'}" type="button" data-notification-id="${esc(item.id)}">
          <span class="notification-preview-icon">${notificationIcon(item.type)}</span>
          <span class="notification-preview-copy"><strong>${esc(item.title)}</strong><span>${esc(item.message)}</span></span>
          <time>${esc(formatRelative(item.createdAt))}</time>
        </button>`).join('') : '<div class="notification-empty"><strong>No notifications</strong><span>New activity will appear here.</span></div>';
      bindNotificationItems($('notificationPreviewList'));
    }
    renderNotificationPage();
  }

  function renderNotificationPage() {
    if (!$('notificationPageList')) return;
    const filtered = state.notificationFilter === 'unread' ? unreadNotifications() : state.notifications;
    const today = state.notifications.filter(item => Date.now() - new Date(item.createdAt).getTime() < 86400000).length;
    $('notificationSummary').innerHTML = [
      ['Unread', unreadNotifications().length, 'Needs your review'],
      ['Last 24 hours', today, 'Recent activity'],
      ['Total shown', state.notifications.length, 'Current notification history'],
    ].map(item => `<article class="notification-summary-card"><small>${item[0]}</small><strong>${item[1]}</strong><span class="muted tiny">${item[2]}</span></article>`).join('');
    $('notificationPageList').innerHTML = filtered.length ? filtered.map(item => `
      <article class="notification-page-item ${item.readAt ? '' : 'unread'}" data-notification-card="${esc(item.id)}">
        <span class="notification-page-icon">${notificationIcon(item.type)}</span>
        <div class="notification-page-copy"><h4>${esc(item.title)}</h4><p>${esc(item.message)}</p><div class="notification-page-meta">${item.readAt ? '<span>Read</span>' : '<span class="notification-unread-dot"></span><strong>Unread</strong>'}<span>·</span><time>${esc(formatRelative(item.createdAt))}</time></div></div>
        <div class="notification-page-actions">${item.actionPage || item.actionUrl ? `<button class="btn btn-light" type="button" data-notification-open="${esc(item.id)}">Open</button>` : ''}${!item.readAt ? `<button class="btn btn-soft" type="button" data-notification-read="${esc(item.id)}">Mark read</button>` : ''}</div>
      </article>`).join('') : '<div class="notification-empty"><strong>No matching notifications</strong><span>Try showing all notifications or refresh the activity feed.</span></div>';
    bindNotificationItems($('notificationPageList'));
  }

  function bindNotificationItems(root) {
    root.querySelectorAll('[data-notification-id]').forEach(button => button.onclick = () => openNotification(button.dataset.notificationId));
    root.querySelectorAll('[data-notification-open]').forEach(button => button.onclick = () => openNotification(button.dataset.notificationOpen));
    root.querySelectorAll('[data-notification-read]').forEach(button => button.onclick = event => {
      event.stopPropagation();
      markNotificationsRead([button.dataset.notificationRead]);
    });
  }

  async function markNotificationsRead(ids) {
    const targetIds = ids.filter(Boolean);
    if (!targetIds.length) return;
    try {
      if (serverMode) {
        const data = await apiRequest('api/notifications.php', {method:'POST', body:{action:'mark_read', ids:targetIds}});
        state.notifications = data.notifications || state.notifications.map(item => targetIds.includes(String(item.id)) ? {...item, readAt:new Date().toISOString()} : item);
      } else {
        const current = localReadIds();
        targetIds.forEach(id => current.add(String(id)));
        localStorage.setItem(localNotificationReadKey, JSON.stringify([...current]));
        state.notifications = collectLocalNotifications();
      }
      renderNotificationUI();
    } catch (error) {
      toast(error.message, true);
    }
  }

  async function markAllNotificationsRead() {
    try {
      if (serverMode) {
        const data = await apiRequest('api/notifications.php', {method:'POST', body:{action:'mark_all_read'}});
        state.notifications = data.notifications || state.notifications.map(item => ({...item, readAt:item.readAt || new Date().toISOString()}));
      } else {
        localStorage.setItem(localNotificationReadKey, JSON.stringify(state.notifications.map(item => item.id)));
        state.notifications = collectLocalNotifications();
      }
      renderNotificationUI();
      toast('All notifications marked as read.');
    } catch (error) {
      toast(error.message, true);
    }
  }

  async function openNotification(id) {
    const item = state.notifications.find(notification => String(notification.id) === String(id));
    if (!item) return;
    if (!item.readAt) await markNotificationsRead([String(item.id)]);
    $('notificationDropdown')?.classList.add('hidden');
    $('notificationButton')?.setAttribute('aria-expanded', 'false');
    const page = item.actionPage || (item.actionUrl && item.actionUrl.includes('#') ? item.actionUrl.split('#').pop() : '');
    if (page) {
      const nav = document.querySelector(`[data-nav="${CSS.escape(page)}"]`);
      if (nav) nav.click();
      else if (item.actionUrl) location.href = item.actionUrl;
    } else if (item.actionUrl) {
      location.href = item.actionUrl;
    }
  }

  function setImagePreview(image, fallback, url, mode = 'logo') {
    if (!image || !fallback) return;
    if (url) {
      image.src = url;
      image.classList.remove('hidden');
      fallback.classList.add('hidden');
    } else {
      image.removeAttribute('src');
      image.classList.add('hidden');
      fallback.classList.remove('hidden');
      if (mode === 'logo') fallback.textContent = ($('brandLogoText')?.value || 'FR').toUpperCase();
    }
  }

  function applyBrandToWorkspace(brand) {
    if (!brand) return;
    document.documentElement.style.setProperty('--accent', brand.primary || '#d94a2b');
    document.documentElement.style.setProperty('--accent2', brand.secondary || '#ff835f');
    document.documentElement.style.setProperty('--dark', brand.dark || '#171b1a');
    const name = document.querySelector('.sidebar .brand h1');
    if (name) name.textContent = brand.restaurantName || 'Restaurant';
    const sidebarLogo = document.querySelector('.sidebar .logo');
    if (sidebarLogo) sidebarLogo.innerHTML = brand.logoUrl ? `<img src="${esc(brand.logoUrl)}" alt="" style="width:100%;height:100%;object-fit:contain;border-radius:inherit">` : esc(brand.logoText || 'FR');
    document.title = `${brand.restaurantName || 'Restaurant'} Training Workspace`;
  }

  function setBrandForm(brand) {
    state.brand = brand;
    const values = {
      brandRestaurantName: brand.restaurantName,
      brandLegalName: brand.legalName,
      brandEmail: brand.email,
      brandPhone: brand.phone,
      brandDescription: brand.description,
      brandLogoText: brand.logoText,
      brandAddress: brand.address,
      brandPrimary: brand.primary,
      brandPrimaryText: brand.primary,
      brandSecondary: brand.secondary,
      brandSecondaryText: brand.secondary,
      brandDark: brand.dark,
      brandDarkText: brand.dark,
    };
    Object.entries(values).forEach(([id, value]) => { if ($(id)) $(id).value = value || ''; });
    if ($('brandRemoveLogo')) $('brandRemoveLogo').checked = false;
    if ($('brandRemoveCover')) $('brandRemoveCover').checked = false;
    setImagePreview($('brandLogoUploadImage'), $('brandLogoUploadFallback'), brand.logoUrl, 'logo');
    setImagePreview($('brandCoverUploadImage'), $('brandCoverUploadPreview')?.querySelector('span'), brand.coverUrl, 'cover');
    renderEnhancedBrandPreview();
    applyBrandToWorkspace(brand);
    try { Auth.write('restaurant-brand-v1', brand); } catch {}
  }

  function brandFormPayload() {
    return {
      restaurantName: $('brandRestaurantName')?.value.trim() || 'Restaurant',
      legalName: $('brandLegalName')?.value.trim() || '',
      email: $('brandEmail')?.value.trim() || '',
      phone: $('brandPhone')?.value.trim() || '',
      description: $('brandDescription')?.value.trim() || '',
      logoText: ($('brandLogoText')?.value.trim() || 'FR').toUpperCase(),
      address: $('brandAddress')?.value.trim() || '',
      primary: $('brandPrimaryText')?.value || '#d94a2b',
      secondary: $('brandSecondaryText')?.value || '#ff835f',
      dark: $('brandDarkText')?.value || '#171b1a',
      logoUrl: state.brand?.logoUrl || null,
      coverUrl: state.brand?.coverUrl || null,
    };
  }

  function renderEnhancedBrandPreview() {
    if (!$('brandPreviewHead')) return;
    const brand = brandFormPayload();
    $('brandPreviewHead').style.setProperty('--preview-primary', brand.primary);
    $('brandPreviewHead').style.setProperty('--preview-secondary', brand.secondary);
    $('brandPreviewHead').style.backgroundImage = brand.coverUrl ? `url("${brand.coverUrl.replaceAll('"', '%22')}")` : '';
    $('brandPreviewName').textContent = brand.restaurantName || 'Restaurant';
    $('brandPreviewDescription').textContent = brand.description || 'Restaurant training and careers.';
    $('brandPreviewContact').textContent = [brand.email, brand.phone, brand.address].filter(Boolean).join(' · ');
    $('brandPreviewLogo').innerHTML = brand.logoUrl ? `<img src="${esc(brand.logoUrl)}" alt="Brand logo">` : esc(brand.logoText);
    $('brandLogoUploadFallback').textContent = brand.logoText;
  }

  function previewSelectedImage(input, image, fallback, kind) {
    const file = input.files?.[0];
    if (!file) return;
    if (!['image/png','image/jpeg','image/webp'].includes(file.type)) {
      input.value = '';
      toast('Use a PNG, JPG, or WebP image.', true);
      return;
    }
    const limit = kind === 'cover' ? 10 * 1024 * 1024 : 5 * 1024 * 1024;
    if (file.size > limit) {
      input.value = '';
      toast(kind === 'cover' ? 'Cover images must be 10 MB or smaller.' : 'Logo images must be 5 MB or smaller.', true);
      return;
    }
    const reader = new FileReader();
    reader.onload = () => {
      image.src = String(reader.result || '');
      image.classList.remove('hidden');
      fallback?.classList.add('hidden');
      if (kind === 'logo') state.brand = {...brandFormPayload(), logoUrl:String(reader.result || '')};
      else state.brand = {...brandFormPayload(), coverUrl:String(reader.result || '')};
      renderEnhancedBrandPreview();
    };
    reader.readAsDataURL(file);
  }

  async function loadBrandSettings(showError = false) {
    try {
      if (serverMode) {
        const data = await apiRequest('api/brand-settings.php', {method:'GET'});
        setBrandForm(data.brand);
        $('brandStorageNotice').textContent = 'Logo and cover images are stored in the server upload directory and linked through the database. Keep uploads/ when deploying updates.';
      } else {
        setBrandForm(Auth.read('restaurant-brand-v1', {
          restaurantName:'Gelato Spot', legalName:'', email:'', phone:'', description:'Restaurant training and careers.', logoText:'GS', address:'', primary:'#d94a2b', secondary:'#ff835f', dark:'#171b1a', logoUrl:null, coverUrl:null
        }));
      }
    } catch (error) {
      if ($('brandStorageNotice')) $('brandStorageNotice').textContent = error.message;
      if (showError) toast(error.message, true);
    }
  }

  async function saveBrandSettings() {
    if (!Auth.has('brand.edit')) return toast('You do not have permission to edit brand settings.', true);
    try {
      if (serverMode) {
        const brand = brandFormPayload();
        const body = new FormData();
        const map = {
          restaurant_name:brand.restaurantName, legal_name:brand.legalName, email:brand.email, phone:brand.phone,
          description:brand.description, logo_text:brand.logoText, address:brand.address, primary:brand.primary,
          secondary:brand.secondary, dark:brand.dark,
        };
        Object.entries(map).forEach(([key, value]) => body.append(key, value));
        if ($('brandLogoFile').files?.[0]) body.append('logo', $('brandLogoFile').files[0]);
        if ($('brandCoverFile').files?.[0]) body.append('cover', $('brandCoverFile').files[0]);
        if ($('brandRemoveLogo').checked) body.append('remove_logo', '1');
        if ($('brandRemoveCover').checked) body.append('remove_cover', '1');
        const data = await apiRequest('api/brand-settings.php', {method:'POST', body});
        $('brandLogoFile').value = '';
        $('brandCoverFile').value = '';
        setBrandForm(data.brand);
        toast(data.message || 'Brand settings saved.');
      } else {
        const brand = brandFormPayload();
        Auth.write('restaurant-brand-v1', brand);
        setBrandForm(brand);
        toast('Brand settings saved in this browser.');
      }
    } catch (error) {
      toast(error.message, true);
    }
  }

  function renderLlmProviders() {
    const map = Object.fromEntries(state.llmProviders.map(provider => [provider.provider, provider]));
    const render = (provider, prefix) => {
      const item = map[provider] || {configured:false, status:'not_configured', maskedKey:'', updatedAt:null};
      const status = $(`llm${prefix}Status`);
      const meta = $(`llm${prefix}Meta`);
      if (status) {
        status.textContent = item.configured ? 'Configured' : 'Not configured';
        status.className = `status-pill ${item.configured ? 'active' : 'draft'}`;
      }
      if (meta) meta.textContent = item.configured ? `${item.maskedKey} · Updated ${formatRelative(item.updatedAt)}` : 'No key stored.';
      const remove = $(provider === 'anthropic' ? 'removeAnthropicKey' : 'removeOpenAIKey');
      if (remove) remove.disabled = !item.configured;
    };
    render('anthropic', 'Anthropic');
    render('openai', 'OpenAI');
  }

  async function loadLlmProviders(showError = false) {
    if (!Auth.has('llm_keys.view')) return;
    if (!serverMode) {
      $('llmMigrationNotice').textContent = 'Secure API-key storage requires the PHP backend. Keys are never stored in localStorage.';
      ['saveAnthropicKey','saveOpenAIKey','removeAnthropicKey','removeOpenAIKey'].forEach(id => { if ($(id)) $(id).disabled = true; });
      return;
    }
    try {
      const data = await apiRequest('api/llm-keys.php', {method:'GET'});
      state.llmProviders = data.providers || [];
      renderLlmProviders();
      $('llmMigrationNotice').textContent = 'Keys are encrypted with PHP Sodium and only their final four characters are displayed after saving.';
    } catch (error) {
      $('llmMigrationNotice').textContent = error.message;
      if (showError) toast(error.message, true);
    }
  }

  async function saveLlmKey(provider) {
    const input = $(provider === 'anthropic' ? 'llmAnthropicKey' : 'llmOpenAIKey');
    const apiKey = input.value.trim();
    if (apiKey.length < 20) return toast('Enter the complete provider API key.', true);
    try {
      const data = await apiRequest('api/llm-keys.php', {method:'POST', body:{provider, apiKey}});
      input.value = '';
      state.llmProviders = data.providers || [];
      renderLlmProviders();
      toast(data.message || 'API key saved securely.');
      loadNotifications();
    } catch (error) {
      toast(error.message, true);
    }
  }

  async function removeLlmKey(provider) {
    if (!confirm('Remove this provider API key? Agents using the provider will stop working until a new key is saved.')) return;
    try {
      const data = await apiRequest('api/llm-keys.php', {method:'DELETE', body:{provider}});
      state.llmProviders = data.providers || [];
      renderLlmProviders();
      toast(data.message || 'API key removed.');
      loadNotifications();
    } catch (error) {
      toast(error.message, true);
    }
  }

  function permissionGroups(selected = []) {
    const selectedSet = new Set(selected);
    const groups = {};
    Auth.permissionCatalog.forEach(permission => {
      (groups[permission.group] ||= []).push(permission);
    });
    return Object.entries(groups).map(([group, permissions]) => `
      <section class="permission-group"><h5>${esc(group)}</h5><div class="permission-checks">
        ${permissions.map(permission => `<label class="permission-check"><input type="checkbox" name="permissions" value="${esc(permission.key)}" ${selectedSet.has(permission.key) ? 'checked' : ''}><span><strong>${esc(permission.name)}</strong><br>${esc(permission.key)}</span></label>`).join('')}
      </div></section>`).join('');
  }

  function openAdminModal(title, eyebrow, html) {
    $('adminModalTitle').textContent = title;
    $('adminModalEyebrow').textContent = eyebrow;
    $('adminModalBody').innerHTML = html;
    $('adminModal').classList.add('open');
  }

  function closeAdminModal() {
    $('adminModal').classList.remove('open');
  }

  function renderEnhancedRoles() {
    if (!Auth.has('roles.view') || !$('roleCards')) return;
    const roles = Auth.roles();
    const users = Auth.users();
    $('roleCards').innerHTML = roles.map(role => {
      const editable = !role.owner && Auth.has('roles.edit') && Auth.has('roles.assign_permissions');
      return `<article class="role-card-admin ${editable ? 'editable' : ''}">
        <header><div><h4>${esc(role.name)}</h4><span class="status-pill ${role.system ? 'active' : 'draft'}">${role.system ? 'System' : 'Custom'}</span></div>${role.owner ? '<span title="Protected owner">🔒</span>' : ''}</header>
        <p>${esc(role.description || '')}</p><div class="role-meta"><span>${role.permissions.includes('*') ? 'All' : role.permissions.length} permissions</span><span>${users.filter(user => user.roleId === role.id).length} accounts</span></div>
        <div class="role-card-actions">${editable ? `<button class="btn btn-light" type="button" data-edit-role="${esc(role.id)}">Edit permissions</button>` : role.owner ? '<span class="muted tiny">Protected owner role</span>' : ''}</div>
      </article>`;
    }).join('');
    document.querySelectorAll('[data-edit-role]').forEach(button => button.onclick = () => openRoleEditor(button.dataset.editRole));
  }

  async function refreshRoles(showError = false) {
    if (!Auth.has('roles.view')) return;
    try {
      if (serverMode) {
        const data = await apiRequest('api/roles.php', {method:'GET'});
        Auth.saveRoles(data.roles || []);
        state.rolesLoaded = true;
      }
      renderEnhancedRoles();
      window.RestaurantAdmin?.renderUsers?.();
    } catch (error) {
      renderEnhancedRoles();
      if (showError) toast(error.message, true);
    }
  }

  function openRoleEditor(roleId = null) {
    const role = roleId ? Auth.roles().find(item => item.id === roleId) : null;
    if (role?.owner) return toast('The protected Super Admin role cannot be edited.', true);
    const creating = !role;
    if (creating && !Auth.has('roles.create')) return toast('You do not have permission to create account types.', true);
    if (!creating && (!Auth.has('roles.edit') || !Auth.has('roles.assign_permissions'))) return toast('You do not have permission to edit account permissions.', true);
    openAdminModal(creating ? 'Create account type' : `Edit ${role.name}`, 'Role-based access control', `
      <form id="enhancedRoleEditor">
        <div class="form-grid"><div><label>Account type name</label><input class="field" name="name" required value="${esc(role?.name || '')}" placeholder="Example: Kitchen Trainer"></div><div><label>Slug</label><input class="field" name="slug" ${creating ? 'required' : 'disabled'} value="${esc(role?.slug || '')}" placeholder="kitchen_trainer"></div><div class="wide"><label>Description</label><textarea class="field" name="description" required>${esc(role?.description || '')}</textarea></div></div>
        <div class="setup-divider"></div><div class="permission-groups">${permissionGroups(role?.permissions || [])}</div>
        <div class="form-actions"><button type="button" class="btn btn-light" id="cancelEnhancedRoleEditor">Cancel</button><button class="btn btn-primary">${creating ? 'Create account type' : 'Save permission changes'}</button></div>
      </form>`);
    $('cancelEnhancedRoleEditor').onclick = closeAdminModal;
    $('enhancedRoleEditor').onsubmit = async event => {
      event.preventDefault();
      const formData = new FormData(event.currentTarget);
      const payload = {
        action: creating ? 'create' : 'update',
        roleId: role?.id || '',
        name: String(formData.get('name') || '').trim(),
        slug: String(formData.get('slug') || '').trim(),
        description: String(formData.get('description') || '').trim(),
        permissions: formData.getAll('permissions'),
      };
      if (!payload.name) return toast('Account type name is required.', true);
      try {
        if (serverMode) {
          const data = await apiRequest('api/roles.php', {method:'POST', body:payload});
          Auth.saveRoles(data.roles || []);
        } else {
          const roles = Auth.roles();
          if (creating) {
            const slug = payload.slug.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
            if (roles.some(item => item.slug === slug)) throw new Error('That account-type slug already exists.');
            roles.push({id:`role-${Date.now()}`, name:payload.name, slug, description:payload.description, system:false, owner:false, permissions:payload.permissions});
          } else {
            const target = roles.find(item => item.id === role.id);
            target.name = payload.name;
            target.description = payload.description;
            target.permissions = payload.permissions;
          }
          Auth.saveRoles(roles);
        }
        closeAdminModal();
        renderEnhancedRoles();
        window.RestaurantAdmin?.renderUsers?.();
        toast(creating ? 'Account type created.' : 'Account type permissions updated.');
        loadNotifications();
      } catch (error) {
        toast(error.message, true);
      }
    };
  }

  function bindEvents() {
    $('notificationButton')?.addEventListener('click', event => {
      event.stopPropagation();
      const dropdown = $('notificationDropdown');
      const opening = dropdown.classList.contains('hidden');
      dropdown.classList.toggle('hidden', !opening);
      $('notificationButton').setAttribute('aria-expanded', String(opening));
      $('profileMenu')?.classList.add('hidden');
    });
    document.addEventListener('click', event => {
      if (!event.target.closest('.notification-wrap')) {
        $('notificationDropdown')?.classList.add('hidden');
        $('notificationButton')?.setAttribute('aria-expanded', 'false');
      }
    });
    $('notificationViewAll')?.addEventListener('click', () => {
      $('notificationDropdown')?.classList.add('hidden');
      $('profileNotificationsLink')?.click();
    });
    $('notificationMarkAllMini')?.addEventListener('click', markAllNotificationsRead);
    $('notificationMarkAll')?.addEventListener('click', markAllNotificationsRead);
    $('notificationRefresh')?.addEventListener('click', () => loadNotifications(true));
    $('notificationFilter')?.addEventListener('change', event => { state.notificationFilter = event.target.value; renderNotificationPage(); });
    $('profileNotificationsLink')?.addEventListener('click', () => $('profileMenu')?.classList.add('hidden'));

    $('brandLogoFile')?.addEventListener('change', event => previewSelectedImage(event.target, $('brandLogoUploadImage'), $('brandLogoUploadFallback'), 'logo'));
    $('brandCoverFile')?.addEventListener('change', event => previewSelectedImage(event.target, $('brandCoverUploadImage'), $('brandCoverUploadPreview')?.querySelector('span'), 'cover'));
    $('brandRemoveLogo')?.addEventListener('change', event => {
      if (event.target.checked) state.brand = {...brandFormPayload(), logoUrl:null};
      renderEnhancedBrandPreview();
      setImagePreview($('brandLogoUploadImage'), $('brandLogoUploadFallback'), event.target.checked ? null : state.brand?.logoUrl, 'logo');
    });
    $('brandRemoveCover')?.addEventListener('change', event => {
      if (event.target.checked) state.brand = {...brandFormPayload(), coverUrl:null};
      renderEnhancedBrandPreview();
      setImagePreview($('brandCoverUploadImage'), $('brandCoverUploadPreview')?.querySelector('span'), event.target.checked ? null : state.brand?.coverUrl, 'cover');
    });
    $('saveBrandSettings').onclick = saveBrandSettings;
    ['brandRestaurantName','brandLegalName','brandEmail','brandPhone','brandDescription','brandLogoText','brandAddress','brandPrimaryText','brandSecondaryText','brandDarkText'].forEach(id => $(id)?.addEventListener('input', renderEnhancedBrandPreview));
    ['brandPrimary','brandSecondary','brandDark'].forEach(id => $(id)?.addEventListener('input', event => {
      const textId = `${id}Text`;
      if ($(textId)) $(textId).value = event.target.value;
      renderEnhancedBrandPreview();
    }));

    document.querySelectorAll('[data-secret-toggle]').forEach(button => button.onclick = () => {
      const input = $(button.dataset.secretToggle);
      input.type = input.type === 'password' ? 'text' : 'password';
      button.textContent = input.type === 'password' ? 'Show' : 'Hide';
    });
    $('saveAnthropicKey')?.addEventListener('click', () => saveLlmKey('anthropic'));
    $('saveOpenAIKey')?.addEventListener('click', () => saveLlmKey('openai'));
    $('removeAnthropicKey')?.addEventListener('click', () => removeLlmKey('anthropic'));
    $('removeOpenAIKey')?.addEventListener('click', () => removeLlmKey('openai'));

    if ($('openCreateRole')) $('openCreateRole').onclick = () => openRoleEditor();
  }

  function wrapNavigation() {
    const original = window.RestaurantAdmin?.onNavigate;
    if (!window.RestaurantAdmin) return;
    window.RestaurantAdmin.onNavigate = page => {
      if (typeof original === 'function') original(page);
      if (page === 'notifications') renderNotificationPage();
      if (page === 'brand') loadBrandSettings();
      if (page === 'llm') loadLlmProviders();
      if (page === 'roles') refreshRoles();
    };
  }

  function setupPermissions() {
    const additions = [
      {key:'llm_keys.view', group:'Integrations', name:'View LLM API key status'},
      {key:'llm_keys.edit', group:'Integrations', name:'Manage LLM API keys'},
    ];
    additions.forEach(permission => {
      if (!Auth.permissionCatalog.some(item => item.key === permission.key)) Auth.permissionCatalog.push(permission);
    });
    document.querySelectorAll('[data-permission="llm_keys.view"]').forEach(element => { element.hidden = !Auth.has('llm_keys.view'); });
    const adminSwitch = document.querySelector('[data-workspace-mode="admin"]');
    if (adminSwitch && Auth.has('llm_keys.view')) adminSwitch.hidden = false;
  }

  window.addEventListener('restaurant:activity', event => recordServerActivity(event.detail));

  setupPermissions();
  wrapNavigation();
  bindEvents();
  loadNotifications();
  loadBrandSettings();
  refreshRoles();
  if (Auth.has('llm_keys.view')) loadLlmProviders();
  window.setInterval(() => loadNotifications(false), 60000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) loadNotifications(false); });
})();
