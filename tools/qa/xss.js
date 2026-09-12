// Stored-XSS: the payloads are already in the database via the real nominate form.
// This renders every surface that displays them and checks nothing executes.
// NOTE_SETTLE — domcontentloaded, see tools/browser/README.md
const { chromium } = require('playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8125';
const EMAIL = process.env.ADMIN_EMAIL || 'admin@example.com';
const PASS = process.env.ADMIN_PASSWORD || 'Str0ng-Pass-123';

let fails = 0, dialogs = 0;
const check = (n, ok, d = '') => { console.log((ok ? '  PASS  ' : '  FAIL  ') + n + (d ? '  → ' + d : '')); if (!ok) fails++; };

(async () => {
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });
  const ctx = await browser.newContext();
  ctx.on('page', p => p.on('dialog', async d => { dialogs++; await d.dismiss().catch(() => {}); }));

  const visit = async (page, path) => {
    const errs = [];
    page.on('pageerror', e => errs.push('JS: ' + e.message));
    const r = await page.goto(BASE + path, { waitUntil: 'domcontentloaded', timeout: 25000 });
    await page.waitForTimeout(800);
    const executed = await page.evaluate(() => !!window.__xss);
    return { status: r ? r.status() : 0, executed, errs, html: await page.content() };
  };

  const page = await ctx.newPage();
  await page.goto(BASE + '/admin/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', EMAIL).catch(() => {});
  await page.fill('input[name="password"]', PASS).catch(() => {});
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => {}),
    page.click('button[type="submit"], input[type="submit"]').catch(() => {}),
  ]);
  check('admin login', !page.url().includes('/admin/login'), page.url());

  // The desk lists the hostile nominee_name and nominator_name.
  for (const path of ['/admin/nominations', '/admin/nominations/review', '/admin/moderation', '/admin/data']) {
    const r = await visit(page, path);
    check(`${path}: no injected script executed`, !r.executed && dialogs === 0,
      r.executed ? 'window.__xss set' : (dialogs ? dialogs + ' dialog(s)' : ''));
    // Look for a payload that is LIVE MARKUP, not one that merely contains the
    // payload text. The first version matched `window.__xss` anywhere and flagged
    // the correctly-escaped `&lt;script&gt;window.__xss=1&lt;/script&gt;` inside a
    // data- attribute — a false positive on output encoding working exactly right.
    //
    // Live markup means an element the parser actually built: a <script> whose text
    // is our payload, or an element carrying a real onerror/onload attribute.
    const live = await page.evaluate(() => {
      const scripted = [...document.querySelectorAll('script')]
        .some(s => !s.src && /window\.__xss/.test(s.textContent || ''));
      const handlered = !!document.querySelector('[onerror],[onload]');
      const injectedSvg = !!document.querySelector('svg[onload]');
      return scripted || handlered || injectedSvg;
    });
    check(`${path}: payload is escaped text, not live markup`, !live,
      live ? 'the parser built an element from the payload' : '');
    check(`${path}: renders ${r.status} with no JS error`, r.status === 200 && r.errs.length === 0,
      r.errs.slice(0, 2).join(' | '));
  }

  // The individual review page interpolates the reason into the reviewer's view —
  // the single most injection-exposed surface on the platform.
  const ids = await page.evaluate(async (base) => {
    const res = await fetch(base + '/admin/nominations');
    const t = await res.text();
    return [...t.matchAll(/\/admin\/nominations\/(\d+)/g)].map(m => m[1]).slice(0, 3);
  }, BASE);
  check(`found ${ids.length} nomination(s) to open individually`, ids.length > 0);
  for (const id of ids) {
    const r = await visit(page, '/admin/nominations/' + id);
    check(`/admin/nominations/${id}: nothing executed`, !r.executed && dialogs === 0);
    check(`/admin/nominations/${id}: renders ${r.status}`, r.status === 200);
  }

  await browser.close();
  console.log(`\n${fails} failure(s) · ${dialogs} dialog(s)`);
  process.exit(fails ? 1 : 0);
})();
