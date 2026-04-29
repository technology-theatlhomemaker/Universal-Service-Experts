(function () {
  'use strict';

  const DEFAULT_ENDPOINT = '/api/lead.php';

  const FORM_ID = 'home_hero_form';
  const PHOTO_INPUT_NAME = 'form_fields[field_0ea4ad2][]';
  const MAX_LONG_EDGE = 1920;
  const JPEG_QUALITY = 0.85;
  const MAX_PHOTOS = 10;
  const MAX_BYTES_PER_PHOTO = 8 * 1024 * 1024;

  const formLoadedAt = Date.now();

  let initialized = false;

  document.addEventListener('DOMContentLoaded', init);
  if (document.readyState !== 'loading') init();

  function init() {
    if (initialized) return;
    const form = document.getElementById(FORM_ID);
    if (!form) return;
    initialized = true;

    form.addEventListener('submit', onSubmit, true);
  }

  async function onSubmit(e) {
    e.preventDefault();
    e.stopImmediatePropagation();

    const form = e.currentTarget;
    const endpoint = form.getAttribute('data-endpoint') || DEFAULT_ENDPOINT;

    const validationError = validateRequired(form);
    if (validationError) {
      showError(form, validationError);
      return;
    }

    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="elementor-button-content-wrapper"><span class="elementor-button-text">Sending…</span></span>';
    }
    clearError(form);

    const fileInput = form.querySelector(`input[name="${PHOTO_INPUT_NAME}"]`);

    try {
      const payload = await buildPayload(form, fileInput);
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain;charset=utf-8' },
        body: JSON.stringify(payload),
        redirect: 'follow'
      });

      let body = {};
      try { body = await res.json(); } catch (_) { body = {}; }

      if (body.ok) {
        window.location.href = body.redirectUrl || '/thank-you/';
        return;
      }
      throw new Error(body.error || 'Submission failed. Please try again.');
    } catch (err) {
      console.error(err);
      showError(form, err.message || 'Something went wrong. Please try again or call us.');
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
      }
    }
  }

  function validateRequired(form) {
    const firstName = form.querySelector('input[name="form_fields[name]"]');
    const email = form.querySelector('input[name="form_fields[field_6817b28]"]');
    if (!firstName || !firstName.value.trim()) {
      return 'Please enter your first name.';
    }
    if (!email || !email.value.trim()) {
      return 'Please enter your email address.';
    }
    return null;
  }

  async function buildPayload(form, fileInput) {
    const payload = {};

    const fields = form.querySelectorAll('input, textarea, select');
    fields.forEach((el) => {
      if (!el.name) return;
      if (el.type === 'file') return;
      if (el.type === 'submit' || el.type === 'button') return;
      if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
      payload[el.name] = el.value;
    });

    payload._form_age_ms = Date.now() - formLoadedAt;
    payload._source_page = window.location.pathname;
    payload._user_agent = navigator.userAgent;

    payload.photos = [];
    if (fileInput && fileInput.files && fileInput.files.length) {
      const files = Array.from(fileInput.files).slice(0, MAX_PHOTOS);
      for (const file of files) {
        try {
          const photo = await processPhoto(file);
          if (photo) payload.photos.push(photo);
        } catch (err) {
          console.warn('Photo skipped:', file.name, err);
        }
      }
    }

    return payload;
  }

  async function processPhoto(file) {
    if (!file.type.startsWith('image/')) {
      if (file.size > MAX_BYTES_PER_PHOTO) return null;
      return fileToBase64Entry(file);
    }
    try {
      return await resizeImage(file);
    } catch (err) {
      console.warn('Resize failed, sending original:', err);
      if (file.size > MAX_BYTES_PER_PHOTO) return null;
      return fileToBase64Entry(file);
    }
  }

  function fileToBase64Entry(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => {
        const result = reader.result;
        const comma = result.indexOf(',');
        resolve({
          name: file.name,
          mimeType: file.type || 'application/octet-stream',
          base64: comma >= 0 ? result.slice(comma + 1) : result
        });
      };
      reader.onerror = () => reject(reader.error);
      reader.readAsDataURL(file);
    });
  }

  async function resizeImage(file) {
    const dataUrl = await readAsDataUrl(file);
    const img = await loadImage(dataUrl);

    const longest = Math.max(img.width, img.height);
    const scale = longest > MAX_LONG_EDGE ? MAX_LONG_EDGE / longest : 1;
    const w = Math.round(img.width * scale);
    const h = Math.round(img.height * scale);

    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(img, 0, 0, w, h);

    const outDataUrl = canvas.toDataURL('image/jpeg', JPEG_QUALITY);
    const comma = outDataUrl.indexOf(',');
    const baseName = file.name.replace(/\.[^.]+$/, '') || 'photo';

    return {
      name: `${baseName}.jpg`,
      mimeType: 'image/jpeg',
      base64: outDataUrl.slice(comma + 1)
    };
  }

  function readAsDataUrl(file) {
    return new Promise((resolve, reject) => {
      const r = new FileReader();
      r.onload = () => resolve(r.result);
      r.onerror = () => reject(r.error);
      r.readAsDataURL(file);
    });
  }

  function loadImage(src) {
    return new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error('Image decode failed'));
      img.src = src;
    });
  }

  function showError(form, msg) {
    let banner = form.querySelector('.use-form-error');
    if (!banner) {
      banner = document.createElement('div');
      banner.className = 'use-form-error';
      banner.style.cssText = 'color:#b00020;background:#fde7e9;border:1px solid #f5c2c7;padding:10px 12px;border-radius:6px;margin:10px 0;font-size:14px;';
      const wrapper = form.querySelector('.elementor-form-fields-wrapper') || form;
      wrapper.insertBefore(banner, wrapper.firstChild);
    }
    banner.textContent = msg;
  }

  function clearError(form) {
    const banner = form.querySelector('.use-form-error');
    if (banner) banner.remove();
  }
})();
