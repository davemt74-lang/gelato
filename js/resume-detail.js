(() => {
  'use strict';
  const page = window.RESTAURANT_RESUME_PAGE || {};
  const Auth = window.RestaurantAuth;
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
  const read = (key, fallback) => { try { return JSON.parse(localStorage.getItem(key) || JSON.stringify(fallback)); } catch { return fallback; } };
  const write = (key, value) => localStorage.setItem(key, JSON.stringify(value));
  const key = 'restaurant-resumes-v1';
  const format = value => { const date = new Date(value); return Number.isNaN(date.getTime()) ? 'Unknown date' : new Intl.DateTimeFormat('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'}).format(date); };
  const has = permission => {
    const permissions = page.currentUser?.permissions || [];
    return permissions.includes('*') || permissions.includes(permission) || Boolean(Auth?.has?.(permission));
  };
  const currentName = () => page.currentUser?.displayName || Auth?.current?.()?.displayName || 'Reviewer';
  const uid = prefix => `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,8)}`;
  let activeMediaUrls = [];

  function toast(message, bad = false) {
    const node = $('resumeToast');
    node.textContent = message;
    node.style.background = bad ? '#a62a24' : '#171b1a';
    node.classList.remove('hidden');
    clearTimeout(node._timer);
    node._timer = setTimeout(() => node.classList.add('hidden'), 2800);
  }

  function audit(action, entityId, data = null) {
    const list = read('restaurant-audit-v1', []);
    list.unshift({id:uid('audit'),actorUserId:page.currentUser?.id || Auth?.current?.()?.id || null,actorName:currentName(),action,entityType:'resume',entityId,data,createdAt:new Date().toISOString()});
    write('restaurant-audit-v1', list.slice(0,500));
  }

  function back() {
    const sameOrigin = document.referrer && (() => { try { return new URL(document.referrer).origin === location.origin; } catch { return false; } })();
    if (sameOrigin && history.length > 1) history.back();
    else location.href = page.returnPath || 'index.php#resumes';
  }

  function statusOptions(selected) {
    return ['new','under_review','contacted','interview_scheduled','interviewed','on_hold','offer_pending','hired','rejected','withdrawn','archived']
      .map(value => `<option value="${value}" ${value===selected?'selected':''}>${esc(value.replaceAll('_',' '))}</option>`).join('');
  }

  function findResume() {
    return read(key, []).find(item => String(item.id) === String(page.resumeId));
  }

  function saveResume(updated) {
    const list = read(key, []);
    const index = list.findIndex(item => String(item.id) === String(updated.id));
    if (index < 0) return false;
    list[index] = updated;
    write(key, list);
    return true;
  }

  function releaseMediaUrls() {
    activeMediaUrls.forEach(url => URL.revokeObjectURL(url));
    activeMediaUrls = [];
  }

  function mediaMetadataExists(resume) {
    return Boolean(resume.media?.video || (resume.media?.photos || []).length);
  }

  function formatDuration(value) {
    const seconds = Number(value || 0);
    if (!Number.isFinite(seconds) || seconds <= 0) return '';
    return `${seconds.toFixed(1)} seconds`;
  }

  async function renderSubmittedMedia(resume) {
    const root = $('resumeSubmittedMedia');
    if (!root) return;
    releaseMediaUrls();

    const metadataPresent = mediaMetadataExists(resume);
    const mediaTools = window.RestaurantFormMedia;
    if (!mediaTools) {
      root.innerHTML = metadataPresent
        ? '<div class="empty-note">Media metadata exists, but private browser media storage is unavailable.</div>'
        : '<div class="empty-note">No video or photos were submitted.</div>';
      return;
    }

    root.innerHTML = '<div class="empty-note">Loading submitted media…</div>';
    try {
      const items = await mediaTools.getResumeMedia(resume.id);
      if (!items.length) {
        root.innerHTML = metadataPresent
          ? '<div class="empty-note">This submission includes media metadata, but the files are not stored in this browser. Local media does not transfer between devices or browsers.</div>'
          : '<div class="empty-note">No video or photos were submitted.</div>';
        return;
      }

      const video = items.find(item => item.kind === 'video');
      const photos = items.filter(item => item.kind === 'photo');
      const sections = [];

      if (video?.blob) {
        const url = URL.createObjectURL(video.blob);
        activeMediaUrls.push(url);
        sections.push(`
          <div style="display:grid;gap:9px">
            <div><small style="display:block;color:#6b6e68;font-weight:800;text-transform:uppercase;letter-spacing:.06em">Introduction video</small><strong>${esc(video.name || 'Submitted video')}</strong><span style="display:block;color:#6b6e68;font-size:11px;margin-top:3px">${esc(formatDuration(video.duration))}${video.size ? ` · ${esc(mediaTools.formatBytes(video.size))}` : ''}</span></div>
            <video controls playsinline preload="metadata" src="${esc(url)}" style="display:block;width:100%;max-height:460px;border-radius:14px;background:#111"></video>
          </div>`);
      }

      if (photos.length) {
        const cards = photos.map((photo, index) => {
          const url = URL.createObjectURL(photo.blob);
          activeMediaUrls.push(url);
          return `<a href="${esc(url)}" target="_blank" rel="noopener" style="display:block;color:inherit;text-decoration:none"><img src="${esc(url)}" alt="Submitted photo ${index + 1}" style="display:block;width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:12px;background:#eee"><span style="display:block;margin-top:5px;font-size:10px;color:#6b6e68;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(photo.name || `Photo ${index + 1}`)}${photo.size ? ` · ${esc(mediaTools.formatBytes(photo.size))}` : ''}</span></a>`;
        }).join('');
        sections.push(`
          <div style="display:grid;gap:9px">
            <div><small style="display:block;color:#6b6e68;font-weight:800;text-transform:uppercase;letter-spacing:.06em">Submitted photos</small><strong>${photos.length} photo${photos.length === 1 ? '' : 's'}</strong></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px">${cards}</div>
          </div>`);
      }

      root.innerHTML = sections.join('<div class="divider"></div>') || '<div class="empty-note">No video or photos were submitted.</div>';
    } catch (error) {
      root.innerHTML = `<div class="empty-note">${esc(error.message || 'Submitted media could not be loaded from private browser storage.')}</div>`;
    }
  }

  function renderError() {
    releaseMediaUrls();
    $('resumeLoading').classList.add('hidden');
    const record = $('resumeRecord');
    record.classList.remove('hidden');
    record.innerHTML = `<section class="resume-error"><p class="eyebrow">Applicant record</p><h1>Resume not found</h1><p>This submission may have been removed, archived in another browser, or opened with an invalid identifier.</p><button class="button primary" id="resumeErrorBack" type="button">← Back to Resume Submissions</button></section>`;
    $('resumeErrorBack').onclick = back;
  }

  function render() {
    const resume = findResume();
    if (!resume) return renderError();
    releaseMediaUrls();
    $('resumeLoading').classList.add('hidden');
    const record = $('resumeRecord');
    record.classList.remove('hidden');
    const initials = `${resume.firstName?.[0]||''}${resume.lastName?.[0]||''}`.toUpperCase() || 'RS';
    const answers = Object.entries(resume.answers || {}).filter(([label]) => ![
      'First name','Last name','Email address','Phone number',
      'Position of interest','Position of interest — active job posting',
      'Availability','Relevant experience',
      'Short introduction video (30 seconds maximum)','Photos (up to 5)'
    ].includes(label));
    const canReview = has('resumes.review');
    const canNote = has('resumes.add_notes');
    const canConvert = has('resumes.convert_to_employee');
    const notes = resume.notes || [];
    record.innerHTML = `
      <section class="resume-hero">
        <div><p class="eyebrow">Applicant submission</p><h1>${esc(resume.firstName)} ${esc(resume.lastName)}</h1><p>Submitted ${esc(format(resume.submittedAt))} through ${esc(resume.source || 'the public resume form')}.</p><div class="hero-meta"><span class="status-pill ${esc(resume.status)}">${esc(resume.status.replaceAll('_',' '))}</span><span class="role-pill">${esc(resume.position || 'Future opening')}</span></div></div>
        <span class="hero-initials">${esc(initials)}</span>
      </section>
      <div class="resume-layout">
        <div class="resume-stack">
          <section class="resume-card"><div class="card-head"><div><h2>Applicant information</h2><p>Contact, availability, experience, and submitted form answers.</p></div></div>
            <div class="detail-grid">
              <div class="detail-box"><small>Email</small><strong><a href="mailto:${encodeURIComponent(resume.email||'')}">${esc(resume.email || 'Not provided')}</a></strong></div>
              <div class="detail-box"><small>Phone</small><strong>${esc(resume.phone || 'Not provided')}</strong></div>
              <div class="detail-box wide"><small>Availability</small><p>${esc(resume.availability || 'Not provided')}</p></div>
              <div class="detail-box wide"><small>Relevant experience</small><p>${esc(resume.experience || 'Not provided')}</p></div>
              <div class="detail-box wide"><small>Resume file</small><p>${esc(resume.fileName || 'No file attached')}${resume.fileSize ? ` · ${Math.max(1,Math.round(resume.fileSize/1024))} KB` : ''}</p></div>
              ${answers.map(([label,value]) => `<div class="detail-box wide"><small>${esc(label)}</small><p>${esc(value || 'Not provided')}</p></div>`).join('')}
            </div>
          </section>
          <section class="resume-card"><div class="card-head"><div><h2>Submitted media</h2><p>Private video and photo attachments saved with this browser-local application.</p></div></div><div id="resumeSubmittedMedia"><div class="empty-note">Loading submitted media…</div></div></section>
          <section class="resume-card"><div class="card-head"><div><h2>Internal reviewer notes</h2><p>Private notes are visible only to authorized hiring users.</p></div></div>
            <div class="resume-notes" id="resumeNotes">${notes.length ? notes.map(note => `<article class="resume-note"><strong>${esc(note.author || 'Reviewer')}</strong> <time>· ${esc(format(note.createdAt))}</time><br>${esc(note.text)}</article>`).join('') : '<div class="empty-note">No internal notes have been added.</div>'}</div>
            ${canNote ? `<label class="field-label" for="resumeNoteText">Add private note</label><textarea class="field" id="resumeNoteText" placeholder="Add a reviewer note, contact result, or interview follow-up..."></textarea><button class="button light full" id="addResumeNote" type="button" style="margin-top:8px">Add internal note</button>` : ''}
          </section>
        </div>
        <aside class="resume-stack">
          <section class="resume-card sticky"><div class="card-head"><div><h2>Review controls</h2><p>Update the applicant workflow and continue to the next action.</p></div></div>
            ${canReview ? `<label class="field-label" for="resumeStatus">Application status</label><select class="field" id="resumeStatus">${statusOptions(resume.status)}</select>` : `<div class="detail-box"><small>Application status</small><strong>${esc(resume.status.replaceAll('_',' '))}</strong></div>`}
            <div class="divider"></div>
            <div class="activity-list"><div class="activity-row"><i></i><span>Submitted ${esc(format(resume.submittedAt))}</span></div>${notes.slice(0,4).map(note=>`<div class="activity-row"><i></i><span>Note added by ${esc(note.author || 'Reviewer')} · ${esc(format(note.createdAt))}</span></div>`).join('')}</div>
            ${canConvert && resume.status !== 'hired' ? `<div class="divider"></div><button class="button primary full" id="convertResume" type="button">Convert to employee account</button>` : ''}
            <button class="button light full" id="backToQueue" type="button" style="margin-top:8px">← Back to Resume Submissions</button>
          </section>
        </aside>
      </div>`;

    renderSubmittedMedia(resume);
    $('backToQueue').onclick = back;
    if ($('resumeStatus')) $('resumeStatus').onchange = event => {
      const previous = resume.status;
      resume.status = event.target.value;
      resume.updatedAt = new Date().toISOString();
      saveResume(resume);
      audit('resume.status_changed', resume.id, {from:previous,to:resume.status});
      toast('Resume status updated.');
      render();
    };
    if ($('addResumeNote')) $('addResumeNote').onclick = () => {
      const text = $('resumeNoteText').value.trim();
      if (!text) return toast('Enter a note before saving.', true);
      resume.notes = resume.notes || [];
      resume.notes.unshift({id:uid('note'),author:currentName(),text,createdAt:new Date().toISOString()});
      saveResume(resume);
      audit('resume.note_added', resume.id);
      toast('Internal note added.');
      render();
    };
    if ($('convertResume')) $('convertResume').onclick = () => {
      const users = Auth?.users?.() || [];
      if (users.some(user => String(user.email).toLowerCase() === String(resume.email).toLowerCase())) return toast('An account already uses this email.', true);
      const roles = Auth?.roles?.() || [];
      const employeeRole = roles.find(role => role.slug === 'employee') || roles.find(role => !role.owner);
      if (!employeeRole) return toast('Create an Employee account type first.', true);
      users.push({id:uid('user'),email:resume.email,password:'',firstName:resume.firstName,lastName:resume.lastName,displayName:`${resume.firstName} ${resume.lastName}`,initials,roleId:employeeRole.id,position:resume.position,status:'invited',lastLogin:null,createdAt:new Date().toISOString(),progress:{quiz:0,flashcards:0,daily:0,certifications:0,overdue:0}});
      Auth.saveUsers(users);
      resume.status = 'hired';
      resume.convertedUserId = users.at(-1).id;
      saveResume(resume);
      audit('resume.converted_to_employee', resume.id, {userId:resume.convertedUserId});
      toast('Employee account created with invited status.');
      render();
    };
  }

  $('resumeBackButton').onclick = back;
  window.addEventListener('beforeunload', releaseMediaUrls);
  fetch('api/public-brand.php',{headers:{Accept:'application/json'}}).then(response=>response.ok?response.json():null).then(data=>{
    const brand=data?.brand;
    if(!brand)return;
    $('resumeBrandName').textContent=brand.restaurantName||'Restaurant';
    if(brand.logoUrl){$('resumeBrandLogo').innerHTML=`<img src="${esc(brand.logoUrl)}" alt="">`;}else $('resumeBrandLogo').textContent=(brand.logoText||'FR').toUpperCase();
    document.documentElement.style.setProperty('--accent',brand.primary||'#d94a2b');
    document.documentElement.style.setProperty('--accent2',brand.secondary||'#ff835f');
  }).catch(()=>{});
  render();
})();
