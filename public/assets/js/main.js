/* ═══════════════════════════════════════════════════════════════
   Africa GATES — Site JavaScript
   Drives GSAP-based entrance animations, scroll-triggered text
   highlighting (SplitType), Swiper talent slider, case-study tabs,
   nav drawer, scroll progress, count-ups, and form helpers.
═══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  document.documentElement.classList.add('wf-loading');

  const $  = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
  const on = (el, ev, fn, opts) => el && el.addEventListener(ev, fn, opts || false);
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ─── Toasts — global, accessible, auto-dismissing ───────────
     Usage: window.toast('Saved', { type:'success'|'error'|'info',
     msg:'optional detail', duration:4000 }). The host is created on
     first use, so no markup is required in the page. */
  const TOAST_ICONS = { success: '✓', error: '!', info: 'i' };
  function toast(title, opts = {}) {
    const o = typeof opts === 'string' ? { type: opts } : opts;
    const { type = 'success', msg = '', duration = 4000 } = o;
    let host = $('.toast-host');
    if (!host) {
      host = document.createElement('div');
      host.className = 'toast-host';
      host.setAttribute('aria-live', 'polite');
      document.body.appendChild(host);
    }
    const el = document.createElement('div');
    el.className = `toast toast--${type}`;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.innerHTML =
      `<span class="toast__icon" aria-hidden="true">${TOAST_ICONS[type] || TOAST_ICONS.info}</span>` +
      `<div><div class="toast__title">${esc(title)}</div>` +
      (msg ? `<div class="toast__msg">${esc(msg)}</div>` : '') + '</div>' +
      '<button class="toast__x" type="button" aria-label="Dismiss">&times;</button>';
    host.appendChild(el);
    requestAnimationFrame(() => el.classList.add('is-show'));
    const dismiss = () => {
      el.classList.add('is-leaving');
      el.classList.remove('is-show');
      setTimeout(() => el.remove(), 340);
    };
    let timer = setTimeout(dismiss, duration);
    on(el.querySelector('.toast__x'), 'click', () => { clearTimeout(timer); dismiss(); });
    on(el, 'mouseenter', () => clearTimeout(timer));
    on(el, 'mouseleave', () => { timer = setTimeout(dismiss, 1500); });
    return dismiss;
  }
  window.toast = toast;

  /* ─── Footer year ─────────────────────────────────────────── */
  function setFooterYear() {
    const y = $('#footYear');
    if (y) y.textContent = new Date().getFullYear();
  }

  /* ─── Nav drawer (header hamburger + bottom-bar Menu) ───────── */
  function bindDrawer() {
    const drawer = $('#afgDrawer');
    if (!drawer) return;
    const toggle  = $('#afgToggle');     // header hamburger
    const tabMenu = $('#afgTabMenu');    // bottom-bar "Menu"
    const triggers = [toggle, tabMenu].filter(Boolean);
    // Stagger the drawer item entrance for a crafted feel.
    $$('.afg-drawer__item', drawer).forEach((el, i) => { el.style.animationDelay = (40 + i * 45) + 'ms'; });

    const setState = (open) => {
      drawer.classList.toggle('is-open', open);
      toggle && toggle.classList.toggle('is-open', open);
      triggers.forEach(t => t.setAttribute('aria-expanded', String(open)));
      document.body.style.overflow = open ? 'hidden' : '';
    };
    const close = () => setState(false);
    const isOpen = () => drawer.classList.contains('is-open');

    triggers.forEach(t => on(t, 'click', (e) => { e.preventDefault(); setState(!isOpen()); }));
    $$('a, .afg-drawer__ai', drawer).forEach(el => on(el, 'click', close));
    on(document, 'keydown', e => { if (e.key === 'Escape' && isOpen()) close(); });
  }

  /* ─── Sticky nav state on scroll ──────────────────────────── */
  function bindNavScroll() {
    const nav = $('#afgNav');
    if (!nav) return;
    const tick = () => nav.classList.toggle('is-scrolled', window.scrollY > 40);
    tick();
    on(window, 'scroll', tick, { passive: true });
  }

  /* ─── Scroll progress bar ─────────────────────────────────── */
  function bindScrollProgress() {
    const bar = $('#scrollProg');
    if (!bar) return;
    const update = () => {
      const h = document.documentElement;
      const max = h.scrollHeight - h.clientHeight;
      bar.style.width = (max > 0 ? (h.scrollTop / max) * 100 : 0) + '%';
    };
    update();
    on(window, 'scroll', update, { passive: true });
  }

  /* ─── Back to top ─────────────────────────────────────────── */
  function bindBackToTop() {
    const btn = $('#backToTop');
    if (!btn) return;
    on(window, 'scroll', () => {
      btn.classList.toggle('is-show', window.scrollY > 640);
    }, { passive: true });
    on(btn, 'click', (e) => { e.preventDefault(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
  }

  /* ─── Smooth anchor links ─────────────────────────────────── */
  function bindSmoothScroll() {
    $$('a[href^="#"]').forEach(a => {
      const id = a.getAttribute('href');
      if (id.length < 2 || id === '#') return;
      on(a, 'click', e => {
        const tgt = document.querySelector(id);
        if (!tgt) return;
        e.preventDefault();
        const top = tgt.getBoundingClientRect().top + window.scrollY - 80;
        window.scrollTo({ top, behavior: reduceMotion ? 'auto' : 'smooth' });
      });
    });
  }

  /* ─── GSAP entrance animations on [data-anim] ─────────────── */
  function bindEntranceAnims() {
    if (!window.gsap || !window.ScrollTrigger) return;
    gsap.registerPlugin(ScrollTrigger);

    const items = $$('[data-anim]');
    if (!items.length) return;

    items.forEach((el) => {
      const kind = el.getAttribute('data-anim') || 'up';
      const delay = parseFloat(el.getAttribute('data-anim-delay') || '0') * 0.08;

      let from;
      if (kind === 'scale') from = { opacity: 0, scale: 0.96 };
      else if (kind === 'fade') from = { opacity: 0 };
      else if (kind === 'right') from = { opacity: 0, x: 32 };
      else from = { opacity: 0, y: 30 };

      gsap.fromTo(el, from, {
        opacity: 1, y: 0, x: 0, scale: 1,
        duration: 0.9,
        ease: 'power3.out',
        delay,
        scrollTrigger: {
          trigger: el,
          start: 'top 88%',
          once: true,
          invalidateOnRefresh: true
        }
      });
    });
  }

  /* ─── Scroll-highlight text (SplitType + GSAP) ────────────── */
  function bindScrollHighlight() {
    if (!window.gsap || !window.ScrollTrigger || !window.SplitType) return;
    const isMobile = window.matchMedia('(max-width: 767px)').matches;

    $$('[data-scroll-highlight]').forEach((el) => {
      if (el.dataset.hlReady === '1') return;
      el.dataset.hlReady = '1';
      const split = new SplitType(el, { types: 'chars,words' });
      gsap.set(split.chars, { color: '#cdd1d2' });
      gsap.to(split.chars, {
        color: '#10292C',
        ease: 'none',
        stagger: isMobile ? 0.03 : 0.06,
        scrollTrigger: {
          trigger: el,
          start: 'top 80%',
          end: isMobile ? '+=100%' : 'top 25%',
          scrub: true,
          invalidateOnRefresh: true
        }
      });
    });
  }

  /* ─── data-text-anim: word slide-up with overflow mask ────── */
  function bindWordSlideUp() {
    if (!window.gsap || !window.ScrollTrigger || !window.SplitType) return;

    $$('[data-text-anim]').forEach((el) => {
      if (el.dataset.taReady === '1') return;
      el.dataset.taReady = '1';
      const split = new SplitType(el, { types: 'lines,words,chars', tagName: 'span' });
      el.classList.add('is-ready');
      const delay    = parseFloat(el.getAttribute('data-text-anim-delay')    || '0');
      const stagger  = parseFloat(el.getAttribute('data-text-anim-stagger')  || '0.5');
      const duration = parseFloat(el.getAttribute('data-text-anim-duration') || '1');

      gsap.set(split.chars, { yPercent: 110, opacity: 0 });
      gsap.to(split.chars, {
        yPercent: 0,
        opacity: 1,
        duration,
        ease: 'power3.out',
        stagger: { amount: stagger, from: 'start', ease: 'power1.inOut' },
        delay,
        scrollTrigger: { trigger: el, start: 'top 85%', once: true }
      });
    });
  }

  /* ─── text-anime: char rotateX reveal (3D flip) ───────────── */
  function bindCharRotateX() {
    if (!window.gsap || !window.ScrollTrigger || !window.SplitType) return;

    $$('[text-anime]').forEach((el) => {
      if (el.dataset.raReady === '1') return;
      el.dataset.raReady = '1';
      const split = new SplitType(el, { types: 'words,chars', tagName: 'span' });
      el.classList.add('is-ready');
      gsap.set(split.chars, {
        opacity: 0,
        rotateX: -80,
        transformPerspective: 1000,
        transformStyle: 'preserve-3d'
      });
      gsap.to(split.chars, {
        opacity: 1,
        rotateX: 0,
        duration: 0.6,
        ease: 'power2.out',
        stagger: { each: 0.02, from: 'center' },
        scrollTrigger: { trigger: el, start: 'top 70%', once: true }
      });
    });
  }

  /* ─── Count-up numbers ─────────────────────────────────────── */
  function bindCountUp() {
    if (!window.gsap || !window.ScrollTrigger) return;
    $$('[data-count]').forEach((el) => {
      const target = parseFloat((el.getAttribute('data-count') || '').replace(/[, ]/g, ''));
      if (!Number.isFinite(target)) return;
      const suffix = el.getAttribute('data-count-suffix') || '';
      const obj = { v: 0 };
      gsap.to(obj, {
        v: target,
        duration: 1.6,
        ease: 'power1.out',
        snap: { v: target % 1 === 0 ? 1 : 0.01 },
        scrollTrigger: { trigger: el, start: 'top 85%', once: true },
        onUpdate: () => {
          el.textContent = (Math.round(obj.v * 100) / 100).toLocaleString() + suffix;
        }
      });
    });
  }

  /* ─── Talent slider (Swiper) ───────────────────────────────── */
  function bindTalentSwiper() {
    if (!window.Swiper) return;
    const root = $('.talent-swiper');
    if (!root) return;

    if (!root.querySelector('.button-prev')) {
      const prev = document.createElement('div');
      prev.className = 'swiper-arrow button-prev';
      prev.innerHTML = '<svg viewBox="0 0 20 20" fill="none"><path d="M11 15l-5-5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      const next = document.createElement('div');
      next.className = 'swiper-arrow button-next';
      next.innerHTML = '<svg viewBox="0 0 20 20" fill="none"><path d="M9 5l5 5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      root.appendChild(prev);
      root.appendChild(next);
    }

    new Swiper(root, {
      slidesPerView: 'auto',
      spaceBetween: 24,
      loop: true,
      centeredSlides: false,
      speed: 420,
      mousewheel: { forceToAxis: true },
      navigation: {
        nextEl: root.querySelector('.button-next'),
        prevEl: root.querySelector('.button-prev')
      }
    });
  }

  /* ─── Logo marquee (Splide) — duplicates list, infinite loop ─ */
  function bindLogoMarquee() {
    if (!window.Splide) return;
    $$('.marquee-splide').forEach((root) => {
      if (root.dataset.spReady === '1') return;
      root.dataset.spReady = '1';
      new Splide(root, {
        type: 'loop',
        drag: 'free',
        focus: 'center',
        arrows: false,
        pagination: false,
        autoScroll: { speed: 1, pauseOnHover: false },
        autoWidth: true,
        gap: '3rem'
      }).mount();
    });
  }

  /* ─── Case-study auto-progressing tabs ─────────────────────── */
  function bindCaseStudyTabs() {
    const root = $('.case-study');
    if (!root) return;
    const tabs   = $$('.case-study__tab', root);
    const panels = $$('.case-study__panel', root);
    if (!tabs.length || !panels.length) return;

    const DUR = 8000;
    let current = 0;
    let paused = false;
    let startTs = 0;
    let elapsed = 0;
    let rafId = null;

    const setActive = (idx) => {
      tabs.forEach((t, i) => t.classList.toggle('is-active', i === idx));
      panels.forEach((p, i) => p.hidden = i !== idx);
      tabs.forEach(t => {
        const bar = t.querySelector('.case-study__tab-progress-inner');
        if (bar) bar.style.width = '0%';
      });
      current = idx;
      startTs = 0;
      elapsed = 0;
    };

    const tick = (ts) => {
      if (paused) { rafId = null; return; }
      if (!startTs) startTs = ts;
      const e = ts - startTs + elapsed;
      const bar = tabs[current].querySelector('.case-study__tab-progress-inner');
      const pct = Math.min((e / DUR) * 100, 100);
      if (bar) bar.style.width = pct + '%';
      if (pct >= 100) setActive((current + 1) % tabs.length);
      rafId = requestAnimationFrame(tick);
    };

    setActive(0);
    tabs.forEach((t, i) => on(t, 'click', () => setActive(i)));
    on(root, 'mouseenter', () => {
      if (rafId) {
        paused = true;
        const bar = tabs[current].querySelector('.case-study__tab-progress-inner');
        const w = parseFloat(bar?.style.width || '0');
        elapsed = (w / 100) * DUR;
        cancelAnimationFrame(rafId); rafId = null;
      }
    });
    on(root, 'mouseleave', () => {
      paused = false; startTs = 0;
      rafId = requestAnimationFrame(tick);
    });

    const io = new IntersectionObserver((es) => {
      es.forEach(e => {
        if (e.isIntersecting && !rafId) rafId = requestAnimationFrame(tick);
      });
    }, { threshold: 0.4 });
    io.observe(root);
  }

  /* ─── Subscribe form helpers (no Marketo on our side) ──────── */
  function bindSubscribeForms() {
    $$('.subscribe-form').forEach((form) => {
      on(form, 'submit', (e) => {
        e.preventDefault();
        const email = form.querySelector('input[type="email"]')?.value?.trim() || '';
        const errEl = form.querySelector('.form-error');
        const okEl  = form.querySelector('.form-success');
        if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
          if (errEl) errEl.classList.add('is-show');
          toast('Enter a valid email address', { type: 'error' });
          return;
        }
        if (errEl) errEl.classList.remove('is-show');
        const row = form.querySelector('.subscribe-row');
        const btn = form.querySelector('button[type="submit"], button');
        btn && (btn.disabled = true);
        fetch('/api/newsletter/subscribe', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email, source: form.dataset.source || 'site' })
        })
          .then(r => r.json().catch(() => ({ success: false })))
          .then(d => {
            if (d && d.success) {
              row && row.setAttribute('hidden', '');
              if (okEl) okEl.classList.add('is-show');
              toast('You’re subscribed', { msg: 'Insights will land in your inbox.' });
            } else {
              toast('Could not subscribe', { type: 'error', msg: (d && d.message) || 'Please try again in a moment.' });
            }
          })
          .catch(() => toast('Could not subscribe', { type: 'error', msg: 'Network error. Please retry.' }))
          .finally(() => { btn && (btn.disabled = false); });
      });

      const input = form.querySelector('input[type="email"]');
      if (input) {
        on(input, 'input', () => form.querySelector('.form-error')?.classList.remove('is-show'));
      }
    });
  }

  /* ─── Inline form validation (progressive enhancement) ────────
     Native constraint validation stays the fallback if JS never runs;
     when it does, we suppress the browser's bubbles and render our own
     accessible, inline messages keyed off the Constraint Validation API. */
  function bindFormValidation() {
    // Server-POST forms only (real action attr). Alpine-driven forms
    // such as the comments panel manage their own submit + state.
    const forms = $$('form.form-stack[action]').filter((f) => !f.classList.contains('subscribe-form'));

    const labelText = (form, el) => (
      form.querySelector(`label[for="${el.id}"]`)?.textContent || 'This field'
    ).replace(/\s*\(.*?\)\s*/g, ' ').replace(/[:*]/g, '').replace(/\s+/g, ' ').trim();

    const messageFor = (form, el) => {
      const v = el.validity;
      const label = labelText(form, el);
      if (v.valueMissing)   return `${label} is required.`;
      if (v.typeMismatch && el.type === 'email') return 'Enter a valid email address, e.g. you@example.com.';
      if (v.typeMismatch && el.type === 'url')   return 'Enter a full URL starting with https://';
      if (v.tooShort)       return `${label} needs at least ${el.minLength} characters (you’ve typed ${el.value.length}).`;
      if (v.tooLong)        return `${label} must be ${el.maxLength} characters or fewer.`;
      if (v.patternMismatch) return `${label} isn’t in the expected format.`;
      return el.validationMessage || 'Please check this field.';
    };

    const groupOf = (el) => el.closest('.form-group') || el.parentElement;

    const errorEl = (el) => {
      const group = groupOf(el);
      let err = group.querySelector('.form-error');
      if (!err) {
        err = document.createElement('p');
        err.className = 'form-error';
        err.id = `${el.id || el.name || 'field'}-error`;
        err.setAttribute('aria-live', 'polite');
        group.appendChild(err);
      }
      return err;
    };

    const showError = (form, el) => {
      const err = errorEl(el);
      err.textContent = messageFor(form, el);
      err.classList.add('is-show');
      groupOf(el).classList.add('is-invalid');
      el.setAttribute('aria-invalid', 'true');
      el.setAttribute('aria-describedby', err.id);
    };

    const clearError = (el) => {
      const group = groupOf(el);
      group.querySelector('.form-error')?.classList.remove('is-show');
      group.classList.remove('is-invalid');
      el.removeAttribute('aria-invalid');
    };

    const validate = (form, el) => {
      if (el.checkValidity()) { clearError(el); return true; }
      showError(form, el);
      return false;
    };

    forms.forEach((form) => {
      form.setAttribute('novalidate', '');
      const fields = $$('.form-input, .form-select, .form-textarea', form).filter((el) => el.willValidate);

      fields.forEach((el) => {
        on(el, 'blur', () => validate(form, el));
        on(el, 'input', () => { if (groupOf(el).classList.contains('is-invalid')) validate(form, el); });
        if (el.tagName === 'SELECT') on(el, 'change', () => validate(form, el));
      });

      on(form, 'submit', (e) => {
        let firstBad = null;
        fields.forEach((el) => { if (!validate(form, el) && !firstBad) firstBad = el; });
        if (firstBad) {
          e.preventDefault();
          firstBad.focus({ preventScroll: true });
          firstBad.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
          return;
        }
        // Valid: give feedback and guard against double-submit. Deferred so
        // the native submit captures form data before the button disables.
        const btn = form.querySelector('button[type="submit"], button:not([type])');
        if (btn && !btn.disabled) {
          const label = btn.querySelector('span') || btn;
          const original = label.textContent;
          setTimeout(() => {
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            label.textContent = 'Submitting…';
          }, 0);
          // Restore if the page is restored from bfcache (back button).
          on(window, 'pageshow', (ev) => {
            if (ev.persisted) { btn.disabled = false; btn.removeAttribute('aria-busy'); label.textContent = original; }
          });
        }
      });
    });
  }

  /* ─── Image fade-in — soften pop-in for lazy <img> on load ──── */
  function bindImageFade() {
    if (reduceMotion) return;
    $$('img[loading="lazy"]').forEach((img) => {
      if (img.complete && img.naturalWidth) return; // already painted
      img.classList.add('img-fade');
      const done = () => img.classList.add('is-loaded');
      on(img, 'load', done);
      on(img, 'error', done); // never leave a broken image invisible
    });
  }

  /* ─── Testimonial card hover scale (GSAP) ─────────────────── */
  function bindHoverScale() {
    if (!window.gsap) return;
    const hasHover = window.matchMedia('(hover: hover)').matches;
    if (!hasHover) return;
    $$('[data-hover-scale]').forEach((card) => {
      on(card, 'mouseenter', () => {
        gsap.to(card, { scale: 1.05, duration: 0.32, ease: 'power3.out' });
      });
      on(card, 'mouseleave', () => {
        gsap.to(card, { scale: 1, duration: 0.32, ease: 'power3.out' });
      });
    });
  }

  /* ─── Wrap-stagger: [el-anim="wrap"] auto-fades children ──── */
  function bindWrapStagger() {
    if (!window.gsap || !window.ScrollTrigger) return;
    $$('[el-anim="wrap"]').forEach((wrap) => {
      if (wrap.dataset.wsReady === '1') return;
      wrap.dataset.wsReady = '1';
      const manual = $$('[el-anim="item"]', wrap);
      const targets = manual.length ? manual : Array.from(wrap.children);
      if (!targets.length) return;
      gsap.set(targets, { autoAlpha: 0, y: 22 });
      ScrollTrigger.create({
        trigger: wrap,
        start: 'top 85%',
        once: true,
        onEnter: () => {
          gsap.to(targets, {
            autoAlpha: 1, y: 0,
            duration: 0.75,
            ease: 'power3.out',
            stagger: 0.08
          });
        }
      });
    });
  }

  /* ─── Icon mosaic: scatter + blur-in on scroll ────────────── */
  function bindBlurIcons() {
    if (!window.gsap || !window.ScrollTrigger) return;
    const icons = $$('[data-blur-icon]');
    if (!icons.length) return;

    // Stable pseudo-random scatter so positions don't shimmer on refresh
    const stableRand = (i, min, max, salt = 0) => {
      let s = (i + 1) * 7919 + salt * 104729;
      s ^= s << 13; s ^= s >> 17; s ^= s << 5;
      const t = Math.abs(s % 10000) / 10000;
      return min + (max - min) * t;
    };

    icons.forEach((icon, i) => {
      const xFrom = stableRand(i, -120, 120, 1);
      const yFrom = stableRand(i, -100, 100, 2);

      icon.style.position = icon.style.position || 'relative';
      const blurLayer = document.createElement('div');
      blurLayer.style.cssText = `
        position:absolute;inset:0;
        backdrop-filter:blur(4px);
        -webkit-backdrop-filter:blur(4px);
        pointer-events:none;
      `;
      icon.appendChild(blurLayer);

      gsap.set(icon, { scale: 1.3, x: xFrom, y: yFrom });

      const container = icon.closest('[data-blur-trigger]') || icon;
      gsap.to(icon, {
        scale: 1, x: 0, y: 0,
        ease: 'none',
        scrollTrigger: {
          trigger: container,
          start: 'top bottom',
          end: '50% 50%',
          scrub: true
        }
      });
      gsap.to(blurLayer, {
        opacity: 0,
        ease: 'none',
        scrollTrigger: {
          trigger: container,
          start: 'top bottom',
          end: '50% 50%',
          scrub: true
        }
      });
    });
  }

  /* ─── Hero collage subtle pointer-tilt parallax ───────────── */
  function bindCollageTilt() {
    if (window.matchMedia('(hover: none)').matches || reduceMotion) return;
    const stage = $('.hero-collage');
    if (!stage) return;
    const tiles = $$('.hero-collage__tile', stage);
    let rect = null;
    on(stage, 'mouseenter', () => { rect = stage.getBoundingClientRect(); });
    on(stage, 'mousemove', (e) => {
      if (!rect) rect = stage.getBoundingClientRect();
      const dx = (e.clientX - rect.left) / rect.width  - 0.5;
      const dy = (e.clientY - rect.top)  / rect.height - 0.5;
      tiles.forEach((t, i) => {
        const depth = ((i % 3) + 1) * 6;
        t.style.transform = `translate3d(${(-dx * depth).toFixed(2)}px, ${(-dy * depth).toFixed(2)}px, 0)`;
      });
    });
    on(stage, 'mouseleave', () => {
      rect = null;
      tiles.forEach(t => t.style.transform = '');
    });
  }

  /* ─── Continental map (Leaflet + dark CartoDB) ────────────── */
  function bindMap() {
    if (!window.L) return;
    const el = $('#cpiMap');
    if (!el || el.dataset.mapReady === '1') return;
    el.dataset.mapReady = '1';

    const init = () => {
      const map = L.map(el, {
        zoomControl: true,
        attributionControl: true,
        scrollWheelZoom: false,
        worldCopyJump: false,
        zoomSnap: 0.5
      }).setView([3, 18], 3);

      L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_nolabels/{z}/{x}/{y}@2x.png', {
        subdomains: 'abcd',
        maxZoom: 9,
        attribution: '&copy; <a href="https://carto.com/attributions" target="_blank">CARTO</a>'
      }).addTo(map);
      L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_only_labels/{z}/{x}/{y}@2x.png', {
        subdomains: 'abcd',
        maxZoom: 9,
        pane: 'shadowPane',
        attribution: ''
      }).addTo(map);

      const TIER_COL = {
        diamond:  '#7dd3fc',
        platinum: '#e2e8f0',
        gold:     '#fbc329',
        silver:   '#cbd5e1',
        bronze:   '#c89060',
        unranked: '#475569'
      };

      const renderPins = (pins) => {
        if (!Array.isArray(pins) || !pins.length) return;
        pins.forEach((p) => {
          if (p.lat == null || p.lng == null) return;
          const colour = TIER_COL[p.cpi_tier] || TIER_COL.gold;
          const radius = 5 + Math.min(10, (p.cpi_score || 0) / 110);
          const marker = L.circleMarker([p.lat, p.lng], {
            radius,
            color: colour,
            fillColor: colour,
            fillOpacity: 0.55,
            weight: 1.5
          });
          marker.bindPopup(
            `<div class="cpi-pin-popup">
               <div class="cpi-pin-popup__name">${esc(p.display_name)}</div>
               <div class="cpi-pin-popup__meta">${esc(p.category || '')}</div>
               <div class="cpi-pin-popup__score">CPI ${p.cpi_score} · ${esc((p.cpi_tier || '').replace(/^./, c => c.toUpperCase()))}</div>
               <a class="cpi-pin-popup__link" href="/registry/${esc(p.slug)}">View profile →</a>
             </div>`
          );
          marker.addTo(map);
        });
      };

      const FALLBACK = [
        { slug:'kolade-fashola',  display_name:'Kolade Fashola',   category:'Education leader',   cpi_score:924, cpi_tier:'diamond',  lat:6.524, lng:3.379 },
        { slug:'aisha-kareem',    display_name:'Aisha Kareem',     category:'Tech entrepreneur',  cpi_score:855, cpi_tier:'diamond',  lat:30.044, lng:31.236 },
        { slug:'femi-adeyemi',    display_name:'Femi Adeyemi',     category:'Choral director',    cpi_score:812, cpi_tier:'platinum', lat:7.388, lng:3.896 },
        { slug:'sunrise-academy', display_name:'Sunrise Academy',  category:'Education',          cpi_score:852, cpi_tier:'diamond',  lat:5.604, lng:-0.187 },
        { slug:'voices-soweto',   display_name:'Voices of Soweto', category:'Choir',              cpi_score:695, cpi_tier:'gold',     lat:-26.205, lng:27.852 },
        { slug:'safari-eats',     display_name:'Safari Eats',      category:'Hospitality',        cpi_score:665, cpi_tier:'gold',     lat:-1.292, lng:36.821 },
        { slug:'salaam-tech',     display_name:'Salaam Tech',      category:'EdTech',             cpi_score:598, cpi_tier:'gold',     lat:30.044, lng:31.236 },
        { slug:'kigali-craft',    display_name:'Kigali Craft Co.', category:'Crafts',             cpi_score:558, cpi_tier:'gold',     lat:-1.95,  lng:30.06 },
        { slug:'casablanca-jazz', display_name:'Casablanca Jazz',  category:'Music',              cpi_score:530, cpi_tier:'silver',   lat:33.573, lng:-7.589 },
        { slug:'addis-design',    display_name:'Addis Design Co.', category:'Design',             cpi_score:498, cpi_tier:'silver',   lat:9.005,  lng:38.763 },
        { slug:'dakar-stories',   display_name:'Dakar Stories',    category:'Journalism',         cpi_score:485, cpi_tier:'silver',   lat:14.692, lng:-17.446 },
        { slug:'kampala-bytes',   display_name:'Kampala Bytes',    category:'FinTech',            cpi_score:472, cpi_tier:'silver',   lat:0.347,  lng:32.582 }
      ];

      fetch('/api/map-pins')
        .then(r => r.ok ? r.json() : null)
        .then(d => renderPins((d && d.success && d.pins) ? d.pins : FALLBACK))
        .catch(() => renderPins(FALLBACK));
    };

    // Defer until visible to avoid layout cost on long pages
    const io = new IntersectionObserver((es) => {
      es.forEach(e => {
        if (e.isIntersecting) { io.disconnect(); init(); }
      });
    }, { threshold: 0.05 });
    io.observe(el);
  }

  const esc = (s) => String(s == null ? '' : s).replace(/[<>&"]/g, c =>
    ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]));

  /* ─── Drawer backdrop — fixed to sync with ALL triggers ─────── */
  function ensureDrawerBackdrop() {
    if ($('.afg-drawer-backdrop')) return;
    const drawer = $('#afgDrawer');
    if (!drawer) return;
    const bd = document.createElement('div');
    bd.className = 'afg-drawer-backdrop';
    document.body.appendChild(bd);

    const toggle  = $('#afgToggle');
    const tabMenu = $('#afgTabMenu');
    const triggers = [toggle, tabMenu].filter(Boolean);

    // Close on backdrop click
    on(bd, 'click', () => {
      drawer.classList.remove('is-open');
      bd.classList.remove('is-show');
      triggers.forEach(t => t && t.setAttribute('aria-expanded', 'false'));
      document.body.style.overflow = '';
    });

    // Keep backdrop in sync whenever any trigger opens/closes the drawer.
    // We use a MutationObserver so we don't need to re-wire every trigger.
    const obs = new MutationObserver(() => {
      const isOpen = drawer.classList.contains('is-open');
      bd.classList.toggle('is-show', isOpen);
    });
    obs.observe(drawer, { attributes: true, attributeFilter: ['class'] });

    // Also close on Escape key
    on(document, 'keydown', e => {
      if (e.key === 'Escape' && drawer.classList.contains('is-open')) {
        drawer.classList.remove('is-open');
        bd.classList.remove('is-show');
        triggers.forEach(t => t && t.setAttribute('aria-expanded', 'false'));
        document.body.style.overflow = '';
      }
    });
  }

  /* ─── Solo hero — word reveal (GSAP-friendly) ─────────────── */
  function bindAtlasHero() {
    const title = $('.solo-hero__title, .atlas-hero__title, .ah-hero__title[data-atlas-word]');
    if (title) {
      const words = $$('[data-atlas-word]', title);
      const reveal = () => words.forEach((w, i) => {
        setTimeout(() => w.classList.add('is-in'), 80 + i * 110);
      });
      if (reduceMotion) words.forEach(w => w.classList.add('is-in'));
      else requestAnimationFrame(reveal);
    }
  }

  /* ─── Aurora — inject a soft mesh only into opted-in body sections ── */
  function injectAuroraCanvases() {
    // Subpage heroes (.p-hero) are now clean & light (Africa GATES style) — no mesh.
    // Only sections that explicitly opt in via [data-aurora-host] get one.
    const hosts = $$('[data-aurora-host]:not(.has-aurora-canvas)');
    hosts.forEach((host, i) => {
      const cs = getComputedStyle(host);
      if (cs.position === 'static') host.style.position = 'relative';
      host.classList.add('has-aurora-canvas');
      const cv = document.createElement('canvas');
      cv.id = 'auroraAuto' + i;
      cv.className = 'aurora-canvas aurora-canvas--soft';
      cv.setAttribute('aria-hidden', 'true');
      host.insertBefore(cv, host.firstChild);
    });
  }

  /* ─── Face mosaic — staggered scale-in, clipped to Africa mask ─ */
  function bindFaceMosaic() {
    const mosaic = $('[data-faces]');
    if (!mosaic) return;
    const tiles = $$('[data-face-tile]', mosaic);
    if (!tiles.length) return;
    if (reduceMotion || !('IntersectionObserver' in window)) {
      tiles.forEach(t => t.classList.add('is-in'));
      return;
    }
    const obs = new IntersectionObserver((entries) => {
      entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('is-in'); obs.unobserve(e.target); } });
    }, { threshold: 0.05, rootMargin: '0px 0px -8% 0px' });
    tiles.forEach(t => obs.observe(t));
  }

  /* ─── Spline 3D — desktop-only, lazy-loaded when in view ─────── */
  function bindSpline() {
    const host = $('[data-spline-host]');
    if (!host) return;
    const url = host.getAttribute('data-spline-url');
    if (!url) return;
    const isDesktop = window.matchMedia('(min-width: 992px)').matches
                   && window.matchMedia('(pointer: fine)').matches;
    if (!isDesktop || reduceMotion) return; // mobile/touch/reduced-motion: never load the runtime

    let loaded = false;
    const load = () => {
      if (loaded) return;
      loaded = true;
      // Inject the (heavy) viewer runtime only now, then mount the element.
      const s = document.createElement('script');
      s.type = 'module';
      s.src = 'https://unpkg.com/@splinetool/viewer@1.9.48/build/spline-viewer.js';
      s.onload = () => {
        const v = document.createElement('spline-viewer');
        v.setAttribute('url', url);
        v.setAttribute('loading-anim-type', 'spinner-small-light');
        v.setAttribute('events-target', 'global');
        host.insertBefore(v, host.firstChild);
      };
      document.head.appendChild(s);
    };

    if ('IntersectionObserver' in window) {
      const obs = new IntersectionObserver((entries) => {
        entries.forEach(e => { if (e.isIntersecting) { load(); obs.disconnect(); } });
      }, { rootMargin: '200px' });
      obs.observe(host);
    } else {
      load();
    }
  }

  /* ─── Mega nav — accessible hover + click dropdowns ──────────── */
  function bindMegaNav() {
    const wraps = $$('[data-mega]');
    if (!wraps.length) return;
    let openWrap = null;
    let closeTimer = null;

    function closeAll() {
      wraps.forEach(w => {
        w.classList.remove('is-open');
        w.querySelector('[data-mega-trigger]')?.setAttribute('aria-expanded', 'false');
      });
      openWrap = null;
    }
    function open(w) {
      if (openWrap === w) return;
      if (openWrap) {
        openWrap.classList.remove('is-open');
        openWrap.querySelector('[data-mega-trigger]')?.setAttribute('aria-expanded', 'false');
      }
      w.classList.add('is-open');
      w.querySelector('[data-mega-trigger]')?.setAttribute('aria-expanded', 'true');
      openWrap = w;
    }

    wraps.forEach(w => {
      const trig = w.querySelector('[data-mega-trigger]');
      if (!trig) return;
      on(trig, 'click', (e) => {
        e.preventDefault();
        w.classList.contains('is-open') ? closeAll() : open(w);
      });
      on(w, 'pointerenter', () => { if (closeTimer) clearTimeout(closeTimer); open(w); });
      on(w, 'pointerleave', () => {
        closeTimer = setTimeout(() => { if (!w.matches(':hover')) { w.classList.remove('is-open'); trig.setAttribute('aria-expanded', 'false'); if (openWrap === w) openWrap = null; } }, 140);
      });
      on(trig, 'keydown', (e) => {
        if (e.key === 'Escape') { closeAll(); trig.focus(); }
        if (e.key === 'ArrowDown') { e.preventDefault(); open(w); w.querySelector('.afg-mega__link')?.focus(); }
      });
      // Close once keyboard focus leaves the menu entirely (Tab-out).
      on(w, 'focusout', () => {
        setTimeout(() => {
          if (!w.contains(document.activeElement)) {
            w.classList.remove('is-open');
            trig.setAttribute('aria-expanded', 'false');
            if (openWrap === w) openWrap = null;
          }
        }, 0);
      });
    });

    on(document, 'click', (e) => {
      if (!e.target.closest('[data-mega]')) closeAll();
    });
    on(document, 'keydown', (e) => { if (e.key === 'Escape') closeAll(); });
  }

  /* ─── Immersive stack — pinned-scroll story cards ──────────── */
  function bindImmersiveStack() {
    const stack = $('[data-immersive-stack]');
    if (!stack) return;
    const cards = $$('[data-immersive-card]', stack);
    const steps = $$('[data-immersive-step]', stack);
    const bar   = $('[data-immersive-progress]', stack);
    if (!cards.length) return;

    function setActive(idx) {
      cards.forEach((c, i) => c.classList.toggle('is-active', i === idx));
      steps.forEach((s, i) => s.classList.toggle('is-active', i === idx));
      if (bar) bar.style.height = ((idx / Math.max(1, cards.length - 1)) * 100) + '%';
    }

    if (!('IntersectionObserver' in window)) { cards.forEach(c => c.classList.add('is-active')); return; }
    const obs = new IntersectionObserver((entries) => {
      // Pick the most-visible card.
      let best = null;
      entries.forEach(e => {
        if (!best || e.intersectionRatio > best.intersectionRatio) best = e;
      });
      // Also consider all currently-intersecting in case some entries are leaving.
      const visible = cards
        .map((c, i) => ({ c, i, r: c.getBoundingClientRect() }))
        .filter(x => x.r.top < window.innerHeight * 0.6 && x.r.bottom > window.innerHeight * 0.3)
        .sort((a, b) => Math.abs((a.r.top + a.r.bottom) / 2 - window.innerHeight / 2) - Math.abs((b.r.top + b.r.bottom) / 2 - window.innerHeight / 2))[0];
      if (visible) setActive(visible.i);
    }, { threshold: [0, 0.25, 0.5, 0.75, 1] });
    cards.forEach(c => obs.observe(c));

    steps.forEach((s, i) => on(s, 'click', () => {
      cards[i]?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }));

    setActive(0);
  }

  /* ─── Gee, the page-aware guide, now lives in /assets/js/gee.js ───
     (replaced the old scripted #aiAssistant widget; see components/gee.css) */

  /* ─── Boot ─────────────────────────────────────────────────── */
  /* ─── Spotlight — auto-rotating featured nominee ─────────────── */
  function bindSpotlight() {
    const stage = $('[data-spotlight]');
    if (!stage) return;
    const cards = $$('[data-spotlight-card]', stage);
    const dots  = $$('[data-spotlight-dot]', stage);
    if (cards.length < 2) return;

    let idx = 0, timer = null;
    const showRaw = (n) => {
      idx = (n + cards.length) % cards.length;
      cards.forEach((c, i) => c.classList.toggle('is-active', i === idx));
      dots.forEach((d, i)  => d.classList.toggle('is-active', i === idx));
    };
    const next = () => showRaw(idx + 1);
    const start = () => { stop(); if (!reduceMotion) timer = setInterval(next, 6500); };
    const stop  = () => { if (timer) { clearInterval(timer); timer = null; } };

    dots.forEach((d, i) => on(d, 'click', () => { showRaw(i); start(); }));
    // Pause on hover (desktop) so it's not aggressive while reading.
    on(stage, 'pointerenter', stop);
    on(stage, 'pointerleave', start);
    // Pause when the section is offscreen — save CPU/data.
    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver(entries => entries.forEach(e => e.isIntersecting ? start() : stop()), { threshold: 0.2 });
      io.observe(stage);
    } else {
      start();
    }
  }

  function boot() {
    document.documentElement.classList.remove('wf-loading');
    setFooterYear();
    bindDrawer();
    bindNavScroll();
    bindScrollProgress();
    bindBackToTop();
    bindSmoothScroll();
    injectAuroraCanvases();
    bindAtlasHero();
    bindFaceMosaic();
    bindSpline();
    bindMegaNav();
    bindImmersiveStack();
    bindSpotlight();
    bindEntranceAnims();
    bindWrapStagger();
    bindScrollHighlight();
    bindWordSlideUp();
    bindCharRotateX();
    bindCountUp();
    bindTalentSwiper();
    bindLogoMarquee();
    bindCaseStudyTabs();
    bindSubscribeForms();
    bindFormValidation();
    bindImageFade();
    bindHoverScale();
    bindBlurIcons();
    bindCollageTilt();
    bindMap();

    if (window.ScrollTrigger) setTimeout(() => window.ScrollTrigger.refresh(), 300);
  }

  if (document.readyState === 'loading') {
    on(document, 'DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
