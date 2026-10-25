/* ══════════════════════════════════════════════════════════════════════════════
   THE SHOP · ShopPage.dc.html, Phase 7 §8.12
   ══════════════════════════════════════════════════════════════════════════════

   Everything on the index works without this file: filters, sort and search are GET
   forms, chips are links, "Load more" is a link to ?n=. This upgrades them in place, and
   adds the one thing that needs a script — the cart.

   THE CART IS A PREVIEW. It lives in this browser (`ag-cart`, CookieRegistry) and every
   figure in it is what the page was shown. The money is decided again by the server:
   checkout re-prices the whole cart, and the drawer asks /shop/quote (the same
   `totals()`) before anybody presses Pay, so the delivery line, a discount and anything
   trimmed for stock are the server's words, not this file's. */
(function () {
  'use strict';

  var KEY = 'ag-cart';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var json = function (s, d) { try { return JSON.parse(s || ''); } catch (e) { return d; } };
  /* What Tab can reach inside a sheet: enabled, not hidden, actually laid out. */
  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  function focusables(root) {
    return $$(FOCUSABLE, root).filter(function (el) { return !el.closest('[hidden]') && el.getClientRects().length > 0; });
  }
  /* Keep Tab inside an open sheet. aria-modal tells a screen reader the page behind is
     out of reach; it does nothing for the keyboard, which walked straight out of the cart
     and the filter sheet into a product grid hidden under the scrim. */
  function trapTab(e, root) {
    var f = focusables(root);
    if (!f.length) { e.preventDefault(); root.focus(); return; }
    var first = f[0], last = f[f.length - 1], at = document.activeElement;
    if (e.shiftKey && (at === first || at === root || !root.contains(at))) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && (at === last || !root.contains(at))) { e.preventDefault(); first.focus(); }
  }
  var put = function (s, map) { return String(s).replace(/%(\w+)%/g, function (m, k) { return k in map ? map[k] : m; }); };

  var drawer = $('[data-sp-cart]');
  var W = drawer ? json(drawer.getAttribute('data-sp-words'), {}) : {};
  var FX = drawer ? json(drawer.getAttribute('data-sp-fx'), { cur: 'NGN', rate: 1, sym: '₦' }) : { cur: 'NGN', rate: 1, sym: '₦' };
  var FREE = drawer ? parseInt(drawer.getAttribute('data-sp-free'), 10) || 0 : 0;

  /* The same arithmetic as ShopController::priceLabel(), so a figure the script draws
     reads exactly like one the server drew beside it. */
  function naira(n) { return '₦' + Math.round(n).toLocaleString('en-NG'); }
  function money(ngn) {
    if (!FX || FX.cur === 'NGN') return naira(ngn);
    var v = ngn * (+FX.rate || 1), sym = FX.sym || '';
    var sep = (sym === '$' || sym === '£' || sym === '€') ? '' : ' ';
    return sym + sep + v.toLocaleString('en', { minimumFractionDigits: v < 100 ? 2 : 0, maximumFractionDigits: v < 100 ? 2 : 0 });
  }

  /* ── The basket ─────────────────────────────────────────────────────────── */

  // A cart is essential to a purchase somebody asked for, so it is stored whatever the
  // Preferences answer; a browser that refuses storage still has a cart for this page.
  var memory = {};
  function load() {
    try { var c = json(localStorage.getItem(KEY), {}); return c && typeof c === 'object' ? c : {}; }
    catch (e) { return memory; }
  }
  function save(c) {
    memory = c;
    try { localStorage.setItem(KEY, JSON.stringify(c)); } catch (e) { /* kept in memory */ }
    paint();
  }
  function lines() {
    var c = load();
    return Object.keys(c).map(function (k) { return c[k]; }).filter(function (l) { return l && l.qty > 0; });
  }
  function count() { return lines().reduce(function (a, l) { return a + (l.qty | 0); }, 0); }
  function subtotal() { return lines().reduce(function (a, l) { return a + l.price * l.qty; }, 0); }

  function add(item, qty) {
    var c = load(), k = item.key || item.slug;
    var had = c[k] ? c[k].qty : 0;
    c[k] = Object.assign({}, item, { key: k, qty: Math.min(20, had + (qty || 1)) });
    save(c);
    return c[k];
  }
  function setQty(k, q) {
    var c = load();
    if (!c[k]) return;
    if (q < 1) delete c[k]; else c[k].qty = Math.min(20, q);
    save(c);
  }

  /* ── Badges, and the drawer's cart step ─────────────────────────────────── */

  function paint() {
    var n = count();
    $$('[data-sp-count]').forEach(function (b) { b.textContent = n; b.hidden = n < 1; });
    $$('[data-sp-cart-label]').forEach(function (b) {
      b.setAttribute('aria-label', n > 0 ? put(b.getAttribute('data-sp-cart-label'), { n: n }) : (W.cart || 'Cart'));
    });
    if (!drawer) return;

    var list = $('[data-sp-lines]', drawer), all = lines();
    list.textContent = '';
    all.forEach(function (l) {
      var li = document.createElement('li');
      li.className = 'sp-line';
      var img = document.createElement('span');
      img.className = 'sp-line__img';
      if (l.cover) { var im = document.createElement('img'); im.src = l.cover; im.alt = ''; im.loading = 'lazy'; img.appendChild(im); }
      var b = document.createElement('span');
      b.className = 'sp-line__b';
      var nm = document.createElement('b'); nm.textContent = l.name; b.appendChild(nm);
      if (l.variant) { var v = document.createElement('span'); v.textContent = l.variant; b.appendChild(v); }
      var row = document.createElement('span');
      row.className = 'sp-line__row';
      row.innerHTML = '<span class="sp-qty sp-qty--sm" role="group"><button type="button" data-sp-line-dec></button><span class="sp-qty__n"></span><button type="button" data-sp-line-inc></button></span><button type="button" class="sp-line__rm" data-sp-line-rm></button>';
      $('[data-sp-line-dec]', row).textContent = '−';
      $('[data-sp-line-dec]', row).setAttribute('aria-label', W.fewer || 'One fewer');
      $('[data-sp-line-inc]', row).textContent = '+';
      $('[data-sp-line-inc]', row).setAttribute('aria-label', W.more || 'One more');
      $('.sp-qty__n', row).textContent = l.qty;
      $('[data-sp-line-rm]', row).textContent = W.remove || 'Remove';
      b.appendChild(row);
      var p = document.createElement('span');
      p.className = 'sp-line__p';
      p.textContent = money(l.price * l.qty);
      li.setAttribute('data-sp-key', l.key);
      li.appendChild(img); li.appendChild(b); li.appendChild(p);
      list.appendChild(li);
    });

    $('[data-sp-empty]', drawer).hidden = all.length > 0;
    var sub = subtotal();
    $('[data-sp-total]', drawer).textContent = money(sub);
    var ngn = $('[data-sp-ngn]', drawer);
    ngn.hidden = FX.cur === 'NGN' || sub < 1;
    ngn.textContent = put(W.charged || '', { amount: naira(sub) });
    var next = $('[data-sp-next]', drawer);
    next.disabled = all.length < 1;

    // Free delivery: a preview against the threshold the server prints. The quote, once
    // asked, replaces it with the server's own sentence.
    var row = $('[data-sp-free-row]', drawer);
    row.hidden = !FREE || all.length < 1;
    if (FREE) {
      $('[data-sp-free-text]', drawer).textContent = sub >= FREE ? W.freeGot : put(W.freeLeft || '', { amount: money(FREE - sub) });
      freeMeter(Math.min(100, Math.round(sub * 100 / FREE)));
    }
    $('[data-sp-cart-json]', drawer).value = JSON.stringify(load());
  }

  /* ── Opening and closing ────────────────────────────────────────────────── */

  var scrim = $('[data-sp-scrim]'), back = null;
  function step(name) {
    $$('[data-sp-step]', drawer).forEach(function (s) { s.hidden = s.getAttribute('data-sp-step') !== name; });
    var co = name === 'checkout';
    $('[data-sp-cart-title]', drawer).textContent = co ? W.checkout : W.cart;
    $('[data-sp-next]', drawer).hidden = co;
    $('[data-sp-pay]', drawer).hidden = !co;
    $('[data-sp-back]', drawer).hidden = !co;
    if (co) quote();
  }
  // The fill and the value a screen reader is told move together.
  function freeMeter(p) {
    $('[data-sp-free-bar]', drawer).style.width = p + '%';
    var m = $('[data-sp-free-meter]', drawer);
    if (m) m.setAttribute('aria-valuenow', String(p));
  }
  function open(at) {
    if (!drawer) return;
    back = document.activeElement;
    paint();
    drawer.hidden = false; scrim.hidden = false;
    document.documentElement.classList.add('sp-locked');
    requestAnimationFrame(function () { drawer.classList.add('is-open'); scrim.classList.add('is-open'); });
    step(at === 'checkout' && count() > 0 ? 'checkout' : 'cart');
    drawer.focus();
  }
  function close() {
    if (!drawer || drawer.hidden) return;
    drawer.classList.remove('is-open'); scrim.classList.remove('is-open');
    document.documentElement.classList.remove('sp-locked');
    setTimeout(function () { drawer.hidden = true; scrim.hidden = true; }, 220);
    if (back && back.focus) back.focus();
  }

  /* ── The quote: the server's own totals, before Pay ─────────────────────── */

  var asking = 0;
  function quote() {
    if (!drawer) return;
    var form = $('[data-sp-checkout]', drawer), mine = ++asking;
    var body = new URLSearchParams();
    // The token is read at SEND time — csrf-fresh.js keeps the field current, and a copy
    // taken at load would outlive every refresh (CsrfRecoveryTest).
    body.set('_token', form.elements._token.value);
    body.set('cart', JSON.stringify(load()));
    body.set('region', form.elements.region ? form.elements.region.value : '');
    body.set('discount', form.elements.discount ? form.elements.discount.value.trim() : '');
    body.set('email', form.elements.email ? form.elements.email.value.trim() : '');
    var pay = $('[data-sp-pay]', drawer);
    pay.textContent = put(W.pay || '', { amount: money(subtotal()) });

    fetch('/shop/quote', { method: 'POST', body: body, credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (q) {
        if (mine !== asking || !q || !q.success) return;
        var sum = $('[data-sp-sum]', drawer);
        sum.textContent = '';
        var row = function (k, v, cls) {
          var li = document.createElement('li'); if (cls) li.className = cls;
          var a = document.createElement('span'); a.textContent = k;
          var b = document.createElement('b'); b.textContent = v;
          li.appendChild(a); li.appendChild(b); sum.appendChild(li);
        };
        row((q.count === 1 ? W.item : put(W.items || '', { n: q.count })), money(q.goods));
        row(W.delivery, q.shipping > 0 ? money(q.shipping) : W.free);
        var ship = $('[data-sp-ship]', drawer);
        if (ship) ship.textContent = q.shipping_why || '';
        if (q.discount > 0) row(q.code || W.discount, '−' + money(q.discount), 'is-off');
        row(W.total, money(q.charged), 'is-tot');

        var note = $('[data-sp-code-note]', drawer);
        if (note) note.textContent = q.note || '';
        var warn = $('[data-sp-adjusted]', drawer);
        warn.hidden = !(q.adjusted && q.adjusted.length);
        if (!warn.hidden) {
          warn.textContent = '';
          var h = document.createElement('b'); h.textContent = W.changed; warn.appendChild(h);
          q.adjusted.forEach(function (t) { var p = document.createElement('span'); p.textContent = t; warn.appendChild(p); });
        }
        $('[data-sp-total]', drawer).textContent = money(q.charged);
        var ngn = $('[data-sp-ngn]', drawer);
        ngn.hidden = FX.cur === 'NGN';
        ngn.textContent = put(W.charged || '', { amount: naira(q.charged) });
        pay.textContent = put(W.pay || '', { amount: money(q.charged) });
        var free = $('[data-sp-free-row]', drawer);
        if (q.free_over) {
          free.hidden = false;
          $('[data-sp-free-text]', drawer).textContent = q.short_by > 0 ? put(W.freeLeft || '', { amount: money(q.short_by) }) : W.freeGot;
          freeMeter(Math.min(100, Math.round((q.free_over - q.short_by) * 100 / q.free_over)));
        } else {
          free.hidden = true;
        }
      })
      .catch(function () { /* the checkout itself prices the cart again */ });
  }

  /* ── Delegated clicks ───────────────────────────────────────────────────── */

  var added = $('[data-sp-added]'), addedTimer = 0;
  function confirmAdd(l, n) {
    if (!added) return;
    var img = $('[data-sp-added-img]', added);
    img.textContent = '';
    if (l.cover) { var im = document.createElement('img'); im.src = l.cover; im.alt = ''; img.appendChild(im); }
    $('[data-sp-added-name]', added).textContent = l.name;
    $('[data-sp-added-opt]', added).textContent = [l.variant, n > 1 ? '× ' + n : ''].filter(Boolean).join(' · ');
    $('[data-sp-added-price]', added).textContent = money(l.price * n);
    var c = count();
    $('[data-sp-added-count]', added).textContent = c === 1 ? W.item : put(W.items || '', { n: c });
    $('[data-sp-added-total]', added).textContent = money(subtotal());
    added.hidden = false;
    clearTimeout(addedTimer);
    requestAnimationFrame(function () { added.classList.add('is-open'); });
  }
  function hideAdded() {
    if (!added || added.hidden) return;
    added.classList.remove('is-open');
    addedTimer = setTimeout(function () { added.hidden = true; }, 200);
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target : e.target.parentElement;
    if (!t) return;
    var el;

    if ((el = t.closest('[data-sp-add]'))) {
      e.preventDefault();
      var item = json(el.getAttribute('data-sp-add'), null);
      if (item) { add(item, 1); confirmAdd(item, 1); }
      return;
    }
    if ((el = t.closest('[data-sp-cart-open]'))) {
      e.preventDefault(); hideAdded(); open(el.getAttribute('data-sp-cart-open')); return;
    }
    if (t.closest('[data-sp-cart-close]') || t.closest('[data-sp-scrim]')) { e.preventDefault(); close(); return; }
    if (t.closest('[data-sp-added-close]')) { hideAdded(); return; }
    if (t.closest('[data-sp-next]')) { if (count() > 0) step('checkout'); return; }
    if (t.closest('[data-sp-back]')) { step('cart'); return; }
    if (t.closest('[data-sp-apply]')) { quote(); return; }

    var line = t.closest('[data-sp-key]');
    if (line) {
      var k = line.getAttribute('data-sp-key'), c = load()[k];
      if (!c) return;
      if (t.closest('[data-sp-line-dec]')) setQty(k, c.qty - 1);
      else if (t.closest('[data-sp-line-inc]')) setQty(k, c.qty + 1);
      else if (t.closest('[data-sp-line-rm]')) setQty(k, 0);
      return;
    }

    // The filter sheet. `:target` opens it with no script; with one, a class does, so
    // closing it does not leave a `#` in the address and jump the page to the top.
    if ((el = t.closest('[data-sp-filters-open]'))) { e.preventDefault(); sheet(true); return; }
    if (t.closest('[data-sp-filters-close]')) { e.preventDefault(); sheet(false); return; }

    if ((el = t.closest('[data-sp-more-go]'))) { e.preventDefault(); more(el); return; }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Tab') {
      if (drawer && !drawer.hidden) trapTab(e, drawer);
      else if (rail && rail.classList.contains('is-open') && phone.matches) trapTab(e, rail);
      return;
    }
    if (e.key !== 'Escape') return;
    if (drawer && !drawer.hidden) close();
    else if (rail && rail.classList.contains('is-open')) sheet(false);
    else hideAdded();
  });

  if (drawer) {
    var form = $('[data-sp-checkout]', drawer);
    form.addEventListener('change', function (e) { if (e.target.matches('[data-sp-region]')) quote(); });
    form.addEventListener('submit', function () { $('[data-sp-cart-json]', drawer).value = JSON.stringify(load()); });
    var code = $('[data-sp-code]', drawer);
    if (code) code.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); quote(); } });
  }

  // Another tab added something: the badge follows.
  window.addEventListener('storage', function (e) { if (e.key === KEY) paint(); });

  /* ── The index: submit on change, the sheet, Load more ──────────────────── */

  var rail = $('[data-sp-sheet]');
  var wide = window.matchMedia('(min-width: 1024px)');
  // Below 600 the rail is a bottom sheet over the page (shop.css), so it is a modal dialog
  // there and says so. From 600 it is a panel above or beside the grid — an ordinary part
  // of the page, which must not claim to be a dialog or hold the keyboard.
  var phone = window.matchMedia('(max-width: 599.98px)');
  var sheetBack = null;
  function sheetRole() {
    if (!rail) return;
    var modal = rail.classList.contains('is-open') && phone.matches;
    if (modal) { rail.setAttribute('role', 'dialog'); rail.setAttribute('aria-modal', 'true'); }
    else { rail.removeAttribute('role'); rail.removeAttribute('aria-modal'); }
    $$('[data-sp-filters-open]').forEach(function (b) { b.setAttribute('aria-expanded', modal ? 'true' : 'false'); });
  }
  function sheet(on) {
    if (!rail) return;
    var was = rail.classList.contains('is-open');
    if (on && !was) sheetBack = document.activeElement;
    rail.classList.toggle('is-open', on);
    document.documentElement.classList.toggle('sp-locked', on && !wide.matches);
    sheetRole();
    if (on) rail.focus();
    else {
      if (location.hash === '#spFilters') history.replaceState(null, '', location.pathname + location.search);
      // Back to whatever opened it — not left on a control that has just been hidden,
      // which drops focus to the end of the document.
      if (was && phone.matches) {
        var to = (sheetBack && document.contains(sheetBack) && sheetBack.getClientRects().length) ? sheetBack : $('[data-sp-filters-open]');
        if (to && to.focus) to.focus();
      }
      sheetBack = null;
    }
  }
  if (rail) {
    sheetRole();
    if (phone.addEventListener) phone.addEventListener('change', sheetRole);
  }

  $$('[data-sp-autosubmit]').forEach(function (f) {
    if (window.AGA11y && window.AGA11y.autoSubmit) { $$('select, input', f).forEach(function (el) { window.AGA11y.autoSubmit(el, f); }); return; }
    f.addEventListener('change', function () { f.submit(); });
  });
  // The rail applies on change where it sits beside the grid. As a sheet it waits for
  // "Show N items", because a phone reloading under somebody's thumb at every tick is a
  // sheet nobody can finish.
  var filters = $('[data-sp-filters]');
  if (filters) filters.addEventListener('change', function (e) {
    if (!wide.matches || e.target.type === 'number') return;
    // Back on the box that was ticked after the reload (a11y.js), not at the top of the page.
    if (window.AGA11y && window.AGA11y.keepPlace) window.AGA11y.keepPlace(filters, e.target);
    filters.submit();
  });

  function more(a) {
    var box = a.closest('[data-sp-more]'), grid = $('[data-sp-grid]');
    if (!grid) { location.href = a.href; return; }
    a.setAttribute('aria-busy', 'true');
    fetch(a.href, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var have = grid.children.length;
        var next = $$('[data-sp-grid] > *', doc).slice(have);
        next.forEach(function (n) { grid.appendChild(document.importNode(n, true)); });
        var fresh = $('[data-sp-more]', doc);
        if (fresh) box.replaceWith(document.importNode(fresh, true)); else box.remove();
        history.replaceState(null, '', a.href);
        var first = grid.children[have];
        var link = first && first.querySelector('.sp-card__body');
        if (link) link.focus({ preventScroll: true });
      })
      .catch(function () { location.href = a.href; });
  }

  /* ── The product page ───────────────────────────────────────────────────── */

  var item = $('[data-sp-page="item"]');
  if (item) product(item);

  function product(root) {
    var P = json(root.getAttribute('data-sp-product'), {});
    var V = json(root.getAttribute('data-sp-variants'), []);
    var WAIT = json(root.getAttribute('data-sp-waiting'), {}) || {};
    var IW = json(root.getAttribute('data-sp-words'), {});
    var axes = $$('[data-sp-axis]', root);
    var pick = {}, qty = 1;
    var buyBar = $('[data-sp-buybar]');

    function nameOf(ax) {
      var l = $('.sp-opt__l > span', ax);
      return l ? l.firstChild.textContent.trim() : '';
    }
    function chosen() {
      if (!axes.length) return null;
      for (var i = 0; i < axes.length; i++) if (!pick[axes[i].getAttribute('data-sp-axis')]) return false;
      return V.filter(function (v) {
        return (!pick.a || v.a === pick.a) && (!pick.b || v.b === pick.b);
      })[0] || false;
    }
    function missing() {
      for (var i = 0; i < axes.length; i++) if (!pick[axes[i].getAttribute('data-sp-axis')]) return axes[i];
      return null;
    }

    function render() {
      // Narrow each choice's "sold out" against the OTHER axis's live pick: a colour in
      // stock in one size must not read as gone, and must when that size is chosen.
      axes.forEach(function (ax) {
        var k = ax.getAttribute('data-sp-axis'), other = k === 'a' ? 'b' : 'a';
        $$('[data-sp-choice]', ax).forEach(function (b) {
          var val = b.getAttribute('data-sp-choice');
          var rows = V.filter(function (v) { return v[k] === val && (!pick[other] || v[other] === pick[other]); });
          var gone = rows.length > 0 && rows.every(function (v) { return v.gone; });
          b.classList.toggle('is-gone', gone);
          b.setAttribute('aria-checked', pick[k] === val ? 'true' : 'false');
          b.classList.toggle('is-on', pick[k] === val);
        });
        $('[data-sp-axis-name]', ax).textContent = pick[k] ? '· ' + pick[k] : '';
      });

      var v = chosen(), price = v ? v.price : P.price, label = v ? v.label : P.price_label;
      var what = v ? [v.a, v.b].filter(Boolean).join(' / ') : '';
      var gone = v ? v.gone : false;
      var line = $('[data-sp-stockline]', root);
      if (v) {
        var n = WAIT[v.id] || 0;
        line.textContent = v.gone ? (n > 0 ? put(IW.goneWait, { v: what, n: n }) : put(IW.gone, { v: what }))
          : v.low ? put(IW.only, { n: v.stock, v: what }) : IW.inStock;
        line.className = 'sp-stockline' + (v.gone ? ' is-gone' : v.low ? ' is-low' : ' is-ok');
      } else if (axes.length) {
        line.textContent = '';
      }

      var priceEl = $('[data-sp-price]', root);
      if (v && priceEl) priceEl.textContent = label;
      var ngnLine = $('[data-sp-ngn-line]', root);
      if (ngnLine) ngnLine.textContent = put(IW.charged, { amount: naira(price * qty) });

      $$('[data-sp-can-buy]', root).forEach(function (el) { el.hidden = gone; });
      if (buyBar) buyBar.hidden = gone;
      var alertF = $('[data-sp-alert]', root);
      if (alertF && axes.length) {
        alertF.hidden = !gone;
        if (gone) {
          $('[data-sp-alert-variant]', alertF).value = v.id;
          $('[data-sp-alert-h]', alertF).textContent = put(IW.back, { v: what });
        }
      }

      $$('[data-sp-qty]', root.ownerDocument).forEach(function (q) { q.textContent = qty; });
      var miss = missing();
      var addLabel = miss ? put(IW.choose, { axis: nameOf(miss).toLowerCase() }) : IW.add;
      $$('[data-sp-add-item]').forEach(function (b) { b.textContent = addLabel; });
      $$('[data-sp-buy]', root).forEach(function (b) {
        b.textContent = miss ? addLabel : put(IW.buy, { amount: money(price * qty) });
      });
      var lt = $('[data-sp-line-total]'), lw = $('[data-sp-line-what]');
      if (lt) lt.textContent = money(price * qty);
      if (lw) lw.textContent = [what, put(IW.qty, { n: qty })].filter(Boolean).join(' · ');
    }

    function photo(src) {
      if (!src) return;
      var img = $('.sp-photos__main img', root);
      if (img) { img.src = src; }
      $$('[data-sp-thumb]', root).forEach(function (t) {
        var on = t.getAttribute('data-sp-thumb') === src;
        t.classList.toggle('is-on', on); t.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    }

    function line() {
      var v = chosen();
      if (v === false) return null;
      var key = v ? P.slug + '|' + v.id : P.slug;
      return { key: key, slug: P.slug, variant_id: v ? v.id : 0, variant: v ? [v.a, v.b].filter(Boolean).join(' / ') : '',
               name: P.name, price: v ? v.price : P.price, cover: (v && v.image) || P.cover || '' };
    }
    function addItem(thenCheckout) {
      var l = line();
      if (!l) {
        var ax = missing();
        if (ax) { ax.classList.remove('is-ask'); void ax.offsetWidth; ax.classList.add('is-ask');
          var first = $('[data-sp-choice]', ax); if (first) first.focus(); }
        return;
      }
      add(l, qty);
      if (thenCheckout) open('checkout'); else confirmAdd(l, qty);
    }

    root.ownerDocument.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target : e.target.parentElement;
      if (!t) return;
      var b;
      if ((b = t.closest('[data-sp-choice]')) && root.contains(b)) {
        var ax = b.closest('[data-sp-axis]'), k = ax.getAttribute('data-sp-axis'), val = b.getAttribute('data-sp-choice');
        pick[k] = val;
        ax.classList.remove('is-ask');
        photo(b.getAttribute('data-sp-image'));
        render(); return;
      }
      if (t.closest('[data-sp-dec]')) { qty = Math.max(1, qty - 1); render(); return; }
      if (t.closest('[data-sp-inc]')) { qty = Math.min(20, qty + 1); render(); return; }
      if (t.closest('[data-sp-add-item]')) { addItem(false); return; }
      if (t.closest('[data-sp-buy]')) { addItem(true); return; }
      if ((b = t.closest('[data-sp-thumb]'))) { photo(b.getAttribute('data-sp-thumb')); return; }
    });

    // Arrow keys move along a radio row, as a radio group's keyboard contract says.
    axes.forEach(function (ax) {
      ax.addEventListener('keydown', function (e) {
        if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].indexOf(e.key) < 0) return;
        var all = $$('[data-sp-choice]', ax), i = all.indexOf(document.activeElement);
        if (i < 0) return;
        e.preventDefault();
        var n = all[(i + (e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? all.length - 1 : 1)) % all.length];
        n.focus(); n.click();
      });
    });

    // A colour is chosen for them only when the photo they are looking at is that
    // colour's: the first swatch in stock. A size is never chosen on anybody's behalf.
    axes.forEach(function (ax) {
      if (!ax.classList.contains('sp-opt--swatch')) return;
      var first = $$('[data-sp-choice]', ax).filter(function (b) { return !b.classList.contains('is-gone'); })[0];
      if (first) pick[ax.getAttribute('data-sp-axis')] = first.getAttribute('data-sp-choice');
    });
    render();

    // The restock alert answers in place.
    var alertF = $('[data-sp-alert]', root);
    if (alertF) alertF.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(alertF);
      fd.set('_token', alertF.elements._token.value);
      fetch(alertF.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          var done = $('[data-sp-alert-done]', alertF);
          done.textContent = r.message || '';
          done.hidden = false;
          done.classList.toggle('is-err', !r.success);
          if (r.success) $('[data-sp-alert-todo]', alertF).hidden = true;
        })
        .catch(function () { alertF.submit(); });
    });

    // The phone gallery's dots follow the swipe.
    var track = $('[data-sp-gal]', root), dots = $$('[data-sp-dot]', root);
    if (track && dots.length) track.addEventListener('scroll', function () {
      var i = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
      dots.forEach(function (d, j) { d.classList.toggle('is-on', j === Math.abs(i)); });
    }, { passive: true });
  }

  paint();
  // A bounced checkout comes back with its fields filled; the cart reopens at that step.
  if (drawer && drawer.getAttribute('data-sp-reopen') === '1' && count() > 0) open('checkout');
})();
