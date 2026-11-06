/* Shared progressive enhancement for image additions. Native inputs remain usable
   without JavaScript; the server remains responsible for validating file bytes. */
(() => {
  'use strict';
  const hostMax = Number(document.currentScript?.dataset.photoMaxBytes || 10485760);
  const postMax = Number(document.currentScript?.dataset.photoPostBytes || 0);
  const supported = new Set(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
  const managedPreview = '[data-nf-photo],[data-pv-file],[data-flo-photo],[data-pl-media],[data-gee-filein]';
  let sequence = 0;

  /* ── A PHONE PHOTO IS MADE THE RIGHT SIZE BEFORE IT IS SENT (5 Nov 2026) ──────────────
     Phone cameras produce 3–12 MB photos and shared hosts commonly accept 2 MB per upload, so
     the photo a nominee chose was discarded by PHP before any of our code saw it — "photos
     uploaded are not reflecting". The server shrinks every portrait to at most 1600 px anyway,
     so shrinking it HERE first (longest side SHRINK_SIDE, JPEG) loses nothing anybody would
     see, fits any limit, and costs a fraction of the data on a mobile connection.
     `imageOrientation: 'from-image'` keeps the phone's rotation. GIFs (animation) and small
     files are left alone; a browser that cannot do this sends the original, as before. */
  const SHRINK_OVER = 1.5 * 1048576, SHRINK_SIDE = 2000;
  async function shrink(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size <= SHRINK_OVER || !window.createImageBitmap) return file;
    const bitmap = await createImageBitmap(file, {imageOrientation: 'from-image'});
    const scale = Math.min(1, SHRINK_SIDE / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale); canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close?.();
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.86));
    if (!blob || blob.size >= file.size) return file;
    return new File([blob], file.name.replace(/\.[a-z0-9]+$/i, '') + '.jpg', {type: 'image/jpeg', lastModified: Date.now()});
  }
  function mount(root = document) {
    root.querySelectorAll('input[type="file"]').forEach(input => {
      if (input.dataset.photoMounted || input.hidden || !/image\//.test(input.accept)) return;
      input.dataset.photoMounted = 'true';
      const mixed = /application\/|video\/|\.pdf/.test(input.accept);
      const imageOnly = !mixed;
      const editor = input.closest('[data-portrait-editor]');
      const save = editor?.querySelector('[data-portrait-save]');
      if (save) save.hidden = true;
      const originalPortrait = editor?.querySelector('[data-portrait-current]');
      const portraitPreview = editor?.querySelector('[data-portrait-preview]');
      const externalPreview = input.matches(managedPreview) || !!input.closest('[data-file-zone]');
      const box = document.createElement('div');
      box.className = 'ag-photo-upload';
      const status = document.createElement('p');
      status.className = 'ag-photo-upload__status';
      status.id = 'photo-selection-' + (++sequence);
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), status.id].filter(Boolean).join(' '));
      const preview = document.createElement('div');
      preview.className = 'ag-photo-upload__preview';
      const clear = document.createElement('button');
      clear.type = 'button';
      clear.className = 'ag-photo-upload__clear';
      clear.textContent = editor ? 'Cancel change' : 'Clear selection';
      clear.hidden = true;
      if (imageOnly && !input.hasAttribute('data-photo-upload')) {
        const hint = document.createElement('p');
        hint.className = 'ag-photo-upload__status';
        hint.id = status.id + '-limit';
        hint.textContent = `Maximum per image on this server: ${(Math.min(Number(input.dataset.photoMaxBytes || 10485760), hostMax) / 1048576).toFixed(1)} MB.`;
        input.setAttribute('aria-describedby', input.getAttribute('aria-describedby') + ' ' + hint.id);
        box.append(hint);
      }
      box.append(status, preview, clear);
      // Never put the button inside a label: activating it would reopen the picker.
      const label = input.closest('label');
      if (editor) editor.querySelector('[data-portrait-feedback]').append(box);
      else (label || input).insertAdjacentElement('afterend', box);
      let urls = [], generation = 0;
      function release() { urls.forEach(url => URL.revokeObjectURL(url)); urls = []; preview.replaceChildren(); if (portraitPreview) { portraitPreview.replaceChildren(); portraitPreview.hidden = true; originalPortrait.hidden = false; save.hidden = true; } }
      function validate(file) {
        if (!imageOnly) return '';
        const max = Math.min(Number(input.dataset.photoMaxBytes || 10485760), hostMax);
        if (file.size > max) return `${file.name} is too large. Choose an image under ${(max / 1048576).toFixed(1)} MB.`;
        if (file.type && !supported.has(file.type)) return `${file.name} is not supported. Choose JPEG, PNG, WebP or GIF.`;
        const accepted = input.accept.split(',').map(x => x.trim());
        if (file.type && !accepted.includes('image/*') && !accepted.includes(file.type)) return `${file.name} is not one of the accepted image formats.`;
        return '';
      }
      input.addEventListener('change', () => {
        // Large photos are shrunk first (see shrink()), then this handler runs again on the
        // smaller files; the flag stops it shrinking twice.
        if (imageOnly && input.dataset.photoShrunk !== '1' && window.DataTransfer
            && Array.from(input.files || []).some(f => f.size > SHRINK_OVER && /^image\/(jpeg|png|webp)$/.test(f.type))) {
          status.textContent = 'Preparing your photo…';
          Promise.all(Array.from(input.files).map(f => shrink(f).catch(() => f))).then(out => {
            const dt = new DataTransfer();
            out.forEach(f => dt.items.add(f));
            input.files = dt.files;
            input.dataset.photoShrunk = '1';
            input.dispatchEvent(new Event('change', {bubbles: true}));
          });
          return;
        }
        delete input.dataset.photoShrunk;
        const current = ++generation;
        release(); input.setCustomValidity(''); input.removeAttribute('aria-invalid');
        const files = Array.from(input.files || []);
        clear.hidden = !files.length;
        if (!files.length) { status.textContent = 'No new file selected.'; return; }
        const error = files.map(validate).find(Boolean);
        if (error) {
          input.setCustomValidity(error); input.setAttribute('aria-invalid', 'true'); status.textContent = error; return;
        }
        if (save) save.hidden = false;
        status.textContent = files.map(f => `${f.name} (${(f.size / 1024).toFixed(0)} KB)`).join('; ') + '. Selected, not yet saved. Submit the form to save.';
        if (editor) status.textContent = 'Preview of your new photo. Save to apply it.';
        const min = Number(input.dataset.photoMinSide || 0);
        files.filter(file => supported.has(file.type)).forEach((file, index) => {
          // Decode dimensions even when a page already has its own preview.
          if (index >= 4 && !min) return;
          const url = URL.createObjectURL(file); urls.push(url);
          const image = new Image(); image.alt = ''; image.width = 96; image.height = 96;
          image.onload = () => {
            if (current !== generation) return;
            if (min && (image.naturalWidth < min || image.naturalHeight < min)) {
              const message = `${file.name} is too small. Use at least ${min} pixels on each side.`;
              input.setCustomValidity(message); input.setAttribute('aria-invalid', 'true'); status.textContent = message;
            }
          };
          image.onerror = () => {
            if (current !== generation) return;
            const message = `${file.name} could not be read as an image. Choose another photo.`;
            input.setCustomValidity(message); input.setAttribute('aria-invalid', 'true'); status.textContent = message;
          };
          image.src = url;
          if (portraitPreview) { portraitPreview.append(image); portraitPreview.hidden = false; originalPortrait.hidden = true; }
          else if (!externalPreview && index < 4) preview.append(image);
        });
      });
      clear.addEventListener('click', () => { input.value = ''; input.dispatchEvent(new Event('change', {bubbles: true})); input.focus(); });
      if (input.form && !input.form.dataset.photoSizeMounted) {
        input.form.dataset.photoSizeMounted = 'true';
        input.form.addEventListener('submit', event => {
          const total = Array.from(input.form.querySelectorAll('input[type="file"]')).flatMap(field => Array.from(field.files || [])).reduce((sum, file) => sum + file.size, 0);
          if (postMax && total + 65536 > postMax) {
            event.preventDefault();
            status.textContent = 'The selected files together exceed the server limit. Choose fewer or smaller files.';
            input.focus();
            return;
          }
          // Defer until other handlers have had a chance to take over with fetch.
          setTimeout(() => {
            if (event.defaultPrevented || !total) return;
            input.form.setAttribute('aria-busy', 'true');
            status.textContent = 'Uploading selected files. Please wait.';
            const button = event.submitter;
            if (button) { button.disabled = true; button.dataset.photoSubmitting = 'true'; }
          }, 0);
        });
      }
      input.form?.addEventListener('reset', () => {
        ++generation; release(); input.setCustomValidity(''); input.removeAttribute('aria-invalid'); status.textContent = ''; clear.hidden = true;
      });
      document.addEventListener('cn:unload', release, {once: true});
      document.addEventListener('ag:unload', release, {once: true});
      window.addEventListener('pagehide', release, {once: true});
    });
  }
  window.addEventListener('pageshow', event => {
    document.querySelectorAll('[data-photo-submitting]').forEach(button => { button.disabled = false; delete button.dataset.photoSubmitting; });
    document.querySelectorAll('form[aria-busy="true"][data-photo-size-mounted]').forEach(form => form.removeAttribute('aria-busy'));
    if (event.persisted) document.querySelectorAll('input[data-photo-mounted]').forEach(input => input.dispatchEvent(new Event('change', {bubbles: true})));
  });
  mount();
  document.addEventListener('cn:load', event => mount(event.detail?.root || document));
  document.addEventListener('ag:load', event => mount(event.detail?.root || document));
})();
