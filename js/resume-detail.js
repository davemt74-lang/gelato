(() => {
  'use strict';
  const page = window.RESTAURANT_RESUME_PAGE || {};
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? '').replace(/[&<>'"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
  const has = permission => (page.currentUser?.permissions || []).some(value=>value==='*'||value===permission);
  const format = value => new Intl.DateTimeFormat('en-US',{dateStyle:'medium',timeStyle:'short'}).format(new Date(value.replace(' ','T')));
  let resume = null, pending = false;
  function toast(message, bad = false) { const node=$('resumeToast');node.textContent=message;node.style.background=bad?'#a62a24':'#171b1a';node.classList.remove('hidden');clearTimeout(node._timer);node._timer=setTimeout(()=>node.classList.add('hidden'),6000); }
  function back() { location.href = page.returnPath || 'workspace.php#resumes'; }
  function fail(message) { $('resumeLoading').classList.add('hidden');const node=$('resumeRecord');node.classList.remove('hidden');node.innerHTML=`<section class="resume-error"><h1>Application unavailable</h1><p>${esc(message)}</p><button class="button primary" id="resumeErrorBack">Back to applications</button></section>`;$('resumeErrorBack').onclick=back; }
  async function load() {
    const response = await fetch('api/resumes.php?id='+encodeURIComponent(page.resumeId),{headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'});
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.message || 'The application could not be loaded.');
    resume = data.resume; page.csrfToken = data.csrfToken || page.csrfToken; render();
  }
  async function save(input, endpoint='api/resumes.php') {
    if (pending) return;
    pending = true;
    document.querySelectorAll('#resumeRecord button,#resumeRecord select').forEach(node=>node.disabled=true);
    try {
      const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':page.csrfToken},body:JSON.stringify(input)});
      const data=await response.json();
      if(!response.ok || !data.ok) throw new Error(data.message || 'The change could not be saved.');
      await load();
      toast(data.message || 'Application saved.', data.invitation?.delivery==='failed');
    } catch(error) { toast(error.message,true);try{await load();}catch(loadError){fail(loadError.message);} }
    finally { pending=false;document.querySelectorAll('#resumeRecord button,#resumeRecord select').forEach(node=>node.disabled=false); }
  }
  function render() {
    $('resumeLoading').classList.add('hidden');$('resumeRecord').classList.remove('hidden');
    const statuses=['new','under_review','contacted','interview_scheduled','interviewed','on_hold','offer_pending','hired','rejected','withdrawn','archived'];
    const files=resume.attachments||[];
    $('resumeRecord').innerHTML=`<section class="resume-hero"><div><p class="eyebrow">Applicant submission</p><h1>${esc(resume.firstName)} ${esc(resume.lastName)}</h1><p>Submitted ${esc(format(resume.submittedAt))}</p><div class="hero-meta"><span class="status-pill ${esc(resume.status)}">${esc(resume.status.replaceAll('_',' '))}</span><span class="role-pill">${esc(resume.position)}</span></div><p>Receipt: ${esc(resume.id)}</p></div></section>
      <div class="resume-layout"><div class="resume-stack"><section class="resume-card"><div class="card-head"><h2>Applicant information</h2></div><div class="detail-grid">
      <div class="detail-box"><small>Email</small><strong><a href="mailto:${encodeURIComponent(resume.email)}">${esc(resume.email)}</a></strong></div><div class="detail-box"><small>Phone</small><strong>${esc(resume.phone)}</strong></div>
      <div class="detail-box wide"><small>Availability</small><p>${esc(resume.availability)}</p></div><div class="detail-box wide"><small>Experience</small><p>${esc(resume.experience)}</p></div></div></section>
      <section class="resume-card"><div class="card-head"><div><h2>Private attachments</h2><p>Files are available only to authorized hiring reviewers.</p></div></div>${files.length?files.map(file=>`<div class="detail-box wide"><a href="${esc(file.url)}" target="_blank" rel="noopener">${esc(file.name)}</a><small>${esc(file.kind)} · ${Math.max(1,Math.round(file.size/1024))} KB</small>${file.kind==='photo'?`<img src="${esc(file.url)}" alt="Applicant attachment" style="display:block;max-width:100%;max-height:400px">`:file.kind==='video'?`<video controls playsinline preload="metadata" src="${esc(file.url)}" style="display:block;width:100%;max-height:400px"></video>`:''}</div>`).join(''):'<p>No attachments were submitted.</p>'}</section>
      <section class="resume-card"><div class="card-head"><h2>Internal reviewer notes</h2></div>${(resume.notes||[]).map(note=>`<article class="resume-note"><strong>${esc(note.author)}</strong> · ${esc(format(note.createdAt))}<p>${esc(note.text)}</p></article>`).join('')||'<p>No notes have been added.</p>'}${has('resumes.add_notes')?'<label for="resumeNoteText">Add a private note</label><textarea class="field" id="resumeNoteText" maxlength="4000"></textarea><button class="button light full" id="addResumeNote">Save note</button>':''}</section></div>
      <aside class="resume-stack"><section class="resume-card sticky"><div class="card-head"><h2>Review controls</h2></div>${has('resumes.review')?`<label for="resumeStatus">Application status</label><select class="field" id="resumeStatus">${statuses.map(status=>`<option value="${status}" ${status===resume.status?'selected':''}>${esc(status.replaceAll('_',' '))}</option>`).join('')}</select>`:''}
      ${has('resumes.convert_to_employee')&&has('users.create')&&!resume.convertedUserId?'<div class="divider"></div><button class="button primary full" id="convertResume">Invite applicant as employee</button><p>The applicant receives a secure email invitation and chooses their own password.</p>':''}${resume.convertedUserId?'<p>An employee account invitation is linked to this application.</p>':''}<button class="button light full" id="backToQueue">Back to applications</button></section></aside></div>`;
    $('backToQueue').onclick=back;
    if($('resumeStatus'))$('resumeStatus').onchange=event=>save({action:'status',id:resume.id,status:event.target.value,expectedStatus:resume.status});
    if($('addResumeNote'))$('addResumeNote').onclick=()=>{const text=$('resumeNoteText').value.trim();if(!text)return toast('Enter a note first.',true);save({action:'note',id:resume.id,text});};
    if($('convertResume'))$('convertResume').onclick=()=>save({action:'invite',applicationId:resume.id},'api/users.php');
  }
  $('resumeBackButton').onclick=back;
  fetch('api/public-brand.php',{headers:{Accept:'application/json'},cache:'no-store'}).then(response=>response.ok?response.json():null).then(data=>{if(!data?.brand)return;$('resumeBrandName').textContent=data.brand.restaurantName||'Restaurant';window.GelatoPublicBrand?.renderLogo($('resumeBrandLogo'),data.brand,'Restaurant','FR');}).catch(()=>{});
  load().catch(error=>fail(error.message));
})();
