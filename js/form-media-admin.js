(() => {
  'use strict';

  const FORM_KEY = 'restaurant-resume-form-v1';
  const MEDIA_FIELDS = [
    {
      id: 'f-video',
      type: 'video',
      label: 'Short introduction video (30 seconds maximum)',
      required: false,
      system: false,
    },
    {
      id: 'f-photos',
      type: 'photos',
      label: 'Photos (up to 5)',
      required: false,
      system: false,
    },
  ];

  const esc = value => String(value ?? '').replace(/[&<>'"]/g, ch => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;'
  }[ch]));

  function readForm() {
    try {
      const parsed = JSON.parse(localStorage.getItem(FORM_KEY) || 'null');
      return parsed && Array.isArray(parsed.fields) ? parsed : null;
    } catch {
      return null;
    }
  }

  function writeForm(form) {
    localStorage.setItem(FORM_KEY, JSON.stringify(form));
  }

  function toast(message, bad = false) {
    let node = document.querySelector('.media-form-toast');
    if (!node) {
      node = document.createElement('div');
      node.className = 'media-form-toast';
      Object.assign(node.style, {
        position:'fixed', zIndex:'9500', right:'20px', bottom:'20px', maxWidth:'360px',
        padding:'12px 15px', borderRadius:'12px', color:'#fff', fontSize:'11px',
        fontWeight:'800', boxShadow:'0 18px 50px rgba(0,0,0,.22)', transition:'.2s'
      });
      document.body.appendChild(node);
    }
    node.style.background = bad ? '#a62a24' : '#171b1a';
    node.textContent = message;
    node.style.opacity = '1';
    clearTimeout(node._timer);
    node._timer = setTimeout(() => { node.style.opacity = '0'; }, 3000);
  }

  function addTypeOptions() {
    const select = document.getElementById('newFieldType');
    if (!select) return false;
    const options = [
      ['video', 'Video upload — 30 seconds max'],
      ['photos', 'Photo upload — up to 5'],
    ];
    options.forEach(([value, label]) => {
      if (select.querySelector(`option[value="${value}"]`)) return;
      const option = document.createElement('option');
      option.value = value;
      option.textContent = label;
      const fileOption = select.querySelector('option[value="file"]');
      if (fileOption?.nextSibling) select.insertBefore(option, fileOption.nextSibling);
      else select.appendChild(option);
    });
    return true;
  }

  function ensureDefaultMediaFields() {
    const form = readForm();
    if (!form) return false;
    let changed = false;
    MEDIA_FIELDS.forEach(field => {
      if (form.fields.some(item => item.type === field.type)) return;
      form.fields.push({...field});
      changed = true;
    });
    if (changed) writeForm(form);
    return changed;
  }

  function bindDuplicateProtection() {
    const button = document.getElementById('addFormField');
    const typeSelect = document.getElementById('newFieldType');
    if (!button || !typeSelect || button.dataset.mediaGuardBound === 'true') return;
    button.dataset.mediaGuardBound = 'true';
    button.addEventListener('click', event => {
      const type = typeSelect.value;
      if (!['video', 'photos'].includes(type)) return;
      const form = readForm();
      if (!form?.fields?.some(field => field.type === type)) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      toast(type === 'video'
        ? 'The form already has its single video submission field.'
        : 'The form already has its photo submission field.', true);
    }, true);
  }

  let renderingPreview = false;
  function renderMediaPreview() {
    if (renderingPreview) return;
    const root = document.getElementById('formPreviewFields');
    const form = readForm();
    if (!root || !form?.fields?.length) return;
    renderingPreview = true;
    try {
      const children = Array.from(root.children);
      form.fields.forEach((field, index) => {
        if (!['video', 'photos'].includes(field.type)) return;
        const container = children[index];
        if (!container || container.dataset.mediaPreviewType === field.type) return;
        container.dataset.mediaPreviewType = field.type;
        container.className = 'preview-field';
        container.innerHTML = field.type === 'video'
          ? `<label>${esc(field.label)}${field.required ? '<span class="preview-required"> *</span>' : ''}</label><input class="field" type="file" accept="video/mp4,video/webm,video/quicktime" disabled><span class="tiny muted">One video · maximum duration 30 seconds</span>`
          : `<label>${esc(field.label)}${field.required ? '<span class="preview-required"> *</span>' : ''}</label><input class="field" type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif" multiple disabled><span class="tiny muted">Up to five photos</span>`;
      });
    } finally {
      renderingPreview = false;
    }
  }

  function initialize() {
    const typeOptionsAdded = addTypeOptions();
    const fieldsAdded = ensureDefaultMediaFields();
    bindDuplicateProtection();

    const preview = document.getElementById('formPreviewFields');
    if (preview && preview.dataset.mediaObserverBound !== 'true') {
      preview.dataset.mediaObserverBound = 'true';
      new MutationObserver(renderMediaPreview).observe(preview, {childList:true, subtree:false});
    }

    if ((fieldsAdded || typeOptionsAdded) && window.RestaurantAdmin?.onNavigate) {
      window.RestaurantAdmin.onNavigate('forms');
    }
    renderMediaPreview();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(initialize, 0), {once:true});
  } else {
    setTimeout(initialize, 0);
  }
})();
