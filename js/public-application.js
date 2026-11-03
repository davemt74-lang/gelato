(() => {
  'use strict';
  const form = document.getElementById('resumeForm');
  if (!form) return;
  const fields = document.getElementById('publicFormFields');
  const message = document.getElementById('submissionMessage');
  const button = form.querySelector('button[type="submit"]');
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
  let config = null, submitting = false;
  function status(text, bad = true) {
    message.className = `form-message ${text ? (bad ? 'bad' : 'good') : 'hidden'}`;
    message.setAttribute('role', bad ? 'alert' : 'status');
    message.textContent = text;
  }
  async function load() {
    const response = await fetch('api/public-applications.php', {headers:{Accept:'application/json'}, credentials:'same-origin', cache:'no-store'});
    const result = await response.json();
    if (!response.ok || !result.ok || !Array.isArray(result.jobs)) throw new Error(result.message || 'Applications are temporarily unavailable. Please try again later.');
    return result;
  }
  function render() {
    const text = (name, label, maximum, type = 'text') => `<div class="field-wrap"><label for="apply_${name}">${label} *</label><input class="field" id="apply_${name}" name="${name}" type="${type}" maxlength="${maximum}" required></div>`;
    fields.innerHTML = text('firstName','First name',100) + text('lastName','Last name',100)
      + text('email','Email address',254,'email') + text('phone','Phone number',40,'tel')
      + `<div class="field-wrap wide"><label for="apply_jobId">Position of interest *</label><select class="field" id="apply_jobId" name="jobId" required><option value="">Select an active job posting</option>${config.jobs.map(job=>`<option value="${esc(job.id)}">${esc(job.title)}</option>`).join('')}</select></div>
      <div class="field-wrap wide"><label for="apply_availability">Availability *</label><textarea class="field" id="apply_availability" name="availability" maxlength="4000" required></textarea></div>
      <div class="field-wrap wide"><label for="apply_experience">Relevant experience *</label><textarea class="field" id="apply_experience" name="experience" maxlength="12000" required></textarea></div>
      <div class="field-wrap wide"><label for="apply_resume">Resume file</label><input class="field" id="apply_resume" name="resume" type="file" accept=".pdf,.doc,.docx"><small>PDF, DOC, or DOCX · up to 10 MB</small></div>
      <div class="field-wrap wide"><label for="apply_video">Introduction video</label><input class="field" id="apply_video" name="video" type="file" accept="video/mp4,video/webm,video/quicktime,.m4v"><small>Optional MP4, WebM, MOV, or M4V · up to 10 MB</small></div>
      <div class="field-wrap wide"><label for="apply_photos">Photos</label><input class="field" id="apply_photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple><small>Up to five JPEG, PNG, or WebP images · up to 2 MB each · all attachments combined up to 25 MB</small></div>
      <div class="field-wrap field-consent"><label class="choice-option"><input name="consent" type="checkbox" value="1" required><span>I confirm the information provided is accurate. *</span></label></div>`;
    const requested = new URLSearchParams(location.search).get('job');
    if (requested && config.jobs.some(job=>job.id===requested)) form.elements.jobId.value = requested;
    button.textContent = 'Submit resume';
    button.disabled = config.jobs.length === 0;
    if (!config.jobs.length) status('There are no active job postings accepting applications right now.');
  }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!config || submitting || !form.reportValidity()) return;
    submitting = true; button.disabled = true; button.textContent = 'Submitting…'; status('');
    try {
      const photos = form.elements['photos[]'].files;
      if (photos.length > 5) throw new Error('Choose at most five photos.');
      const selected = [...photos, ...form.elements.resume.files, ...form.elements.video.files];
      if (Array.from(photos).some(file=>file.size>2*1024*1024) || selected.some(file=>file.size>10*1024*1024)) throw new Error('Resume/video files must be at most 10 MB; each photo must be at most 2 MB.');
      if (selected.reduce((sum,file)=>sum+file.size,0)>25*1024*1024) throw new Error('The application attachments must total no more than 25 MB.');
      const data = new FormData(form);
      data.set('csrf_token',config.csrfToken); data.set('idempotencyKey',config.idempotencyKey);
      const response = await fetch('api/public-applications.php', {method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:data});
      const result = await response.json();
      if (response.status === 419) {
        const refreshed = await load();
        config.csrfToken = refreshed.csrfToken;
        throw new Error('The form security token was refreshed. Please submit again.');
      }
      if (!response.ok || !result.ok || typeof result.receipt?.receipt !== 'string') throw new Error(result.message || 'Your application could not be confirmed. Keep this page open and try again.');
      const card = document.getElementById('applicationCard');
      card.innerHTML = `<div class="success-card" role="status"><div class="success-icon">✓</div><p class="eyebrow">Submission received</p><h2>Thank you, ${esc(data.get('firstName'))}.</h2><p>Your application and attachments have been saved in the restaurant’s private review queue.</p><p><strong>Receipt:</strong> <span>${esc(result.receipt.receipt)}</span></p><p>Keep this receipt for your records.</p><div class="button-row"><a class="button" href="jobs.html">View jobs</a><a class="button" href="apply.html">Submit another resume</a></div></div>`;
    } catch (error) {
      status(error.message || 'Your application could not be confirmed. Keep this page open and try again.');
      submitting = false; button.disabled = !config.jobs.length; button.textContent = 'Submit resume';
    }
  });
  load().then(result=>{config=result;render();}).catch(error=>{status(error.message);button.textContent='Applications unavailable';button.disabled=true;});
})();
