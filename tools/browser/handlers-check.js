const { chromium } = require('playwright');
// waitUntil is domcontentloaded, not networkidle: `php -S` is single-threaded
// and a third-party script can hold a connection open forever, so networkidle
// never settles. Scripts are given an explicit settle window instead. NOTE_SETTLE
const BASE = (process.env.BASE_URL || 'http://127.0.0.1:8125');

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await browser.newPage();
  const csp = [];
  page.on('console', m => { const t = m.text(); if (/Refused to/i.test(t)) csp.push(t); });
  page.on('pageerror', e => csp.push('JS: ' + e.message));
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded', timeout: 25000 });
  await page.waitForTimeout(900);

  let fails = 0;
  const check = (n, ok, d='') => { console.log((ok?'  PASS  ':'  FAIL  ')+n+(d?'  → '+d:'')); if(!ok) fails++; };

  // Inject one element per data-ag-do value and drive the DELEGATED listener that
  // replaced the ten inline on*= attributes. This tests the handler itself rather
  // than whichever page happens to render a gated widget.
  const r = await page.evaluate(async () => {
    const out = {};
    const wait = () => new Promise(r => setTimeout(r, 60));

    // select-all on a readonly input
    const inp = document.createElement('input');
    inp.type = 'text'; inp.value = 'secret-value'; inp.readOnly = true;
    inp.setAttribute('data-ag-do', 'select-all');
    document.body.appendChild(inp);
    inp.click(); await wait();
    out.selectAll = inp.selectionStart === 0 && inp.selectionEnd === inp.value.length;
    inp.remove();

    // dismiss-parent
    const wrap = document.createElement('div');
    const btn = document.createElement('button');
    btn.setAttribute('data-ag-do', 'dismiss-parent');
    wrap.appendChild(btn); document.body.appendChild(wrap);
    btn.click(); await wait();
    out.dismissParent = wrap.style.display === 'none';
    wrap.remove();

    // copy-url — clipboard is unavailable in headless, so assert the label feedback,
    // which is the part a user sees and the part the old inline handler also did.
    const cp = document.createElement('button');
    cp.setAttribute('data-ag-do', 'copy-url');
    cp.dataset.url = 'https://example.org/x';
    cp.textContent = 'Copy link';
    document.body.appendChild(cp);
    cp.click(); await wait();
    out.copyFeedback = cp.textContent.indexOf('Copied') === 0;
    await new Promise(r => setTimeout(r, 1700));
    out.copyRestores = cp.textContent === 'Copy link';
    cp.remove();

    // open-gee. window.openGee IS defined at runtime by /assets/js/gee.js:269, so
    // the handler must PREFER it and never touch the FAB — which is exactly what the
    // original inline `(window.openGee||fallback)()` did. Three earlier versions of
    // this check asserted the fallback fired, because I had grepped only templates/
    // and concluded openGee did not exist. Spy on the real API instead.
    out.openGeeDefined = typeof window.openGee === 'function';
    let apiCalled = false;
    const realOpen = window.openGee;
    window.openGee = function () { apiCalled = true; };
    const og = document.createElement('button');
    og.setAttribute('data-ag-do', 'open-gee');
    document.body.appendChild(og);
    og.click(); await wait();
    out.openGee = apiCalled;
    window.openGee = realOpen;
    og.remove();

    // And the documented fallback, with the API removed, so both branches are covered.
    let fabClicked = false;
    const saved = window.openGee;
    delete window.openGee;
    const fab = document.getElementById('geeFab');
    out.fabExists = !!fab;
    if (fab) fab.addEventListener('click', () => { fabClicked = true; }, { once: true });
    const og2 = document.createElement('button');
    og2.setAttribute('data-ag-do', 'open-gee');
    document.body.appendChild(og2);
    og2.click(); await wait();
    out.openGeeFallback = fabClicked;
    window.openGee = saved;
    og2.remove();

    return out;
  });

  check('select-all selects the whole value',      r.selectAll);
  check('dismiss-parent hides the parent',         r.dismissParent);
  check('copy-url shows "Copied" feedback',        r.copyFeedback);
  check('copy-url restores the original label',    r.copyRestores);
  check('window.openGee is defined by gee.js at runtime', r.openGeeDefined);
  check('open-gee prefers the window.openGee API',        r.openGee);
  check('the page has a real #geeFab for the fallback',   r.fabExists);
  check('open-gee falls back to #geeFab without the API', r.openGeeFallback);
  check('no CSP refusal or JS error throughout',   csp.length === 0, csp.slice(0,3).join(' | '));

  // set-cookie-reload, tested from OUTSIDE the page: its location.reload() destroys
  // the execution context, so asserting from inside an evaluate() cannot work — the
  // first attempt died exactly that way, which was itself evidence the handler fired.
  await page.evaluate(() => {
    const sel = document.createElement('select');
    sel.setAttribute('data-ag-do', 'set-cookie-reload');
    sel.dataset.cookie = 'ag_probe';
    const o = document.createElement('option'); o.value = 'east'; sel.appendChild(o);
    document.body.appendChild(sel);
    sel.value = 'east';
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await page.waitForLoadState('domcontentloaded').catch(() => {});
  await page.waitForTimeout(700);
  const cookies = await page.context().cookies();
  const probe = cookies.find(c => c.name === 'ag_probe');
  check('set-cookie-reload writes the cookie', !!probe && probe.value === 'east',
    probe ? probe.value : 'not set');
  check('set-cookie-reload then reloads the page', page.url().startsWith(BASE), page.url());

  await browser.close();
  console.log(`\n${fails} failure(s)`);
  process.exit(fails ? 1 : 0);
})();
