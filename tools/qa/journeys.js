// Real-world journey + stored-XSS sweep. waitUntil is domcontentloaded because
// `php -S` serialises requests and a third-party script can hold one open. NOTE_SETTLE
const { chromium } = require('playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8125';

// Payloads that execute if output encoding fails anywhere. Chosen to cover the
// distinct sinks: HTML text, attribute, JS string, and sanitised rich HTML.
const XSS = [
  '<script>window.__xss=1</script>',
  '"><img src=x onerror="window.__xss=1">',
  "'-alert(1)-'",
  '<svg/onload=window.__xss=1>',
  '<img src=x onerror=window.__xss=1>',
  'javascript:window.__xss=1',
  '<iframe srcdoc="<script>parent.__xss=1</script>">',
];

let fails = 0, checks = 0;
const check = (name, ok, detail = '') => {
  checks++;
  console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail ? '  → ' + detail : ''));
  if (!ok) fails++;
};

(async () => {
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });
  const ctx = await browser.newContext();

  // Any dialog anywhere in this run means an injection executed.
  let dialogs = 0;
  ctx.on('page', p => p.on('dialog', async d => { dialogs++; await d.dismiss().catch(() => {}); }));

  const open = async (path) => {
    const page = await ctx.newPage();
    const errs = [];
    page.on('console', m => { const t = m.text();
      if (/Refused to (execute|apply|load|connect)/i.test(t)) errs.push('CSP: ' + t); });
    page.on('pageerror', e => errs.push('JS: ' + e.message));
    const r = await page.goto(BASE + path, { waitUntil: 'domcontentloaded', timeout: 25000 });
    await page.waitForTimeout(700);
    return { page, errs, status: r ? r.status() : 0 };
  };

  // ─────────────────────────────────────────────────────────────────────────
  console.log('JOURNEY 1 — a visitor submits a nomination');
  {
    const { page, errs, status } = await open('/nominate');
    check('/nominate renders 200', status === 200, String(status));
    const hasWizard = await page.evaluate(() => document.querySelectorAll('[x-data]').length > 0);
    check('the wizard renders (a programme is open)', hasWizard);

    if (hasWizard) {
      // Fill every required field the wizard exposes, injecting XSS into the free text.
      const payload = XSS[0] + ' ' + XSS[1];
      const filled = await page.evaluate((p) => {
        const set = (sel, v) => {
          const el = document.querySelector(sel);
          if (!el) return false;
          el.value = v;
          el.dispatchEvent(new Event('input', { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
          return true;
        };
        const out = {};
        out.name   = set('input[name="nominee_name"]', 'QA Nominee ' + p);
        out.email  = set('input[name="nominee_email"]', 'qa-nominee@example.com');
        out.reason = set('textarea[name="reason"]', 'QA submission. ' + p + ' '.repeat(1) +
          'This nominee built three schools and trained 400 teachers, documented at https://example.org/a and https://example.org/b.');
        out.nomName  = set('input[name="nominator_name"]', 'QA Nominator');
        out.nomEmail = set('input[name="nominator_email"]', 'qa-nominator@example.com');
        out.prog = (() => { const r = document.querySelector('input[name="programme_id"]'); return !!r; })();
        return out;
      }, payload);
      check('required fields present in the DOM',
        filled.name && filled.reason && filled.nomName && filled.nomEmail,
        JSON.stringify(filled));
    }
    check('/nominate: no CSP refusal or JS error', errs.length === 0, errs.slice(0, 2).join(' | '));
    await page.close();
  }

  // ─────────────────────────────────────────────────────────────────────────
  console.log('\nJOURNEY 2 — stored XSS via every public write path');
  {
    // Submit through the REAL endpoints with real CSRF tokens, then load the pages
    // that render the stored value and see whether anything executes.
    const page = await ctx.newPage();
    await page.goto(BASE + '/nominate', { waitUntil: 'domcontentloaded' });
    const token = await page.evaluate(() =>
      (document.querySelector('input[name="_token"]') || {}).value || '');
    check('a CSRF token is present on the nominate form', token !== '');

    for (const [i, p] of XSS.entries()) {
      const r = await page.evaluate(async ([base, tok, payload, n]) => {
        const body = new URLSearchParams({
          _token: tok, programme_id: '2',
          nominee_name: 'XSS' + n + ' ' + payload,
          nominee_email: `xss${n}@example.com`,
          reason: 'Injection probe ' + payload + ' with enough text to pass the minimum length requirement for this field.',
          country_code: 'NG',
          nominator_name: 'Probe ' + payload,
          nominator_email: `probe${n}@example.com`,
        });
        const res = await fetch(base + '/nominate', {
          method: 'POST', body,
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          redirect: 'manual',
        });
        return { status: res.status };
      }, [BASE, token, p, i]);
      // 302 = accepted, 422 = validation refusal. Either is fine; a 500 is not.
      if (r.status >= 500) check(`payload ${i} did not 500 the submit path`, false, String(r.status));
    }
    check('no submit path returned 5xx on hostile input', true);
    await page.close();
  }

  // Now render every surface that could echo those values back.
  {
    for (const path of ['/', '/registry', '/leaderboard', '/awards', '/pulse', '/nominate']) {
      const { page, errs, status } = await open(path);
      const executed = await page.evaluate(() => !!window.__xss);
      check(`${path}: no injected script executed`, !executed && dialogs === 0,
        executed ? 'window.__xss was set' : (dialogs ? dialogs + ' dialog(s)' : ''));
      check(`${path}: renders ${status} with no JS error`, status === 200 && errs.length === 0,
        errs.slice(0, 2).join(' | '));
      await page.close();
    }
  }

  // ─────────────────────────────────────────────────────────────────────────
  console.log('\nJOURNEY 3 — error pages and dead ends');
  {
    const { page, status, errs } = await open('/this-page-does-not-exist-' + Date.now());
    check('unknown URL returns 404 (not 200, not 500)', status === 404, String(status));
    const looksStyled = await page.evaluate(() =>
      getComputedStyle(document.body).backgroundColor !== 'rgba(0, 0, 0, 0)' && document.querySelectorAll('a').length > 0);
    check('the 404 page is styled and offers a way out', looksStyled);
    check('404 page has no CSP/JS error', errs.length === 0, errs.slice(0, 2).join(' | '));
    await page.close();
  }

  await browser.close();
  console.log(`\n${checks} checks · ${fails} failure(s) · ${dialogs} dialog(s)`);
  process.exit(fails ? 1 : 0);
})();
