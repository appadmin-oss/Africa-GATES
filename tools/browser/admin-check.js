// waitUntil is domcontentloaded, not networkidle: `php -S` is single-threaded and a
// third-party script can hold a connection open forever. NOTE_SETTLE
const { chromium } = require('playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8125';
const EMAIL = process.env.ADMIN_EMAIL || 'admin@example.com';
const PASS  = process.env.ADMIN_PASSWORD || 'Str0ng-Pass-123';

// The admin, judge and account surfaces carry inline <script> blocks that the public
// sweep never reaches — admin/dashboard and admin/settings have the most of any
// template. Until this existed they were verified only by scanning source, which
// cannot tell you whether the browser accepted the nonce.
// Enumerated from src/routes.php, not guessed. The first version of this list had
// four invented paths (/admin/shop, /admin/donations, /admin/analytics, /admin/cron)
// which 404'd — and a 404 page passing a CSP check is a FALSE PASS, the same shape as
// the earlier run that "verified" 17 pages while every script had 404'd. The
// assertion below now rejects any non-200 outright.
const ADMIN_PAGES = [
  '/admin/login', '/admin/dashboard', '/admin/integrity-brief',
  '/admin/nominations', '/admin/nominations/review', '/admin/nominees',
  '/admin/nominees/duplicate-scan', '/admin/moderation', '/admin/assistant',
  '/admin/programmes', '/admin/programmes/new', '/admin/profiles',
  '/admin/settings', '/admin/webhooks', '/admin/admins', '/admin/judges',
  '/admin/legal', '/admin/media', '/admin/awards-page', '/admin/data',
  '/admin/forms', '/admin/forms/new', '/admin/events', '/admin/events/new',
  '/admin/posts', '/admin/posts/new', '/admin/products', '/admin/products/new',
  '/admin/opportunities', '/admin/opportunities/new', '/admin/partners',
  '/admin/legacy', '/admin/legacy/new', '/admin/registrations',
];
const OTHER = ['/judge/login', '/account/login', '/donate'];

(async () => {
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });
  const ctx = await browser.newContext();
  let violations = 0, jsErrors = 0, visited = 0, unauth = 0;

  const page = await ctx.newPage();

  // ── Log in. Without a session every /admin/* URL redirects to the login form, and
  //    the sweep would "pass" 19 pages while only ever seeing one.
  await page.goto(BASE + '/admin/login', { waitUntil: 'domcontentloaded', timeout: 25000 });
  await page.waitForTimeout(600);
  await page.fill('input[type="email"], input[name="email"]', EMAIL).catch(() => {});
  await page.fill('input[type="password"], input[name="password"]', PASS).catch(() => {});
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => {}),
    page.click('button[type="submit"], input[type="submit"]').catch(() => {}),
  ]);
  await page.waitForTimeout(800);
  const loggedIn = !page.url().includes('/admin/login');
  console.log(`login: ${loggedIn ? 'OK' : 'FAILED'}  (${page.url()})`);
  if (!loggedIn) { console.log('  ! cannot verify admin pages without a session'); }
  await page.close();

  for (const path of [...ADMIN_PAGES, ...OTHER]) {
    const p = await ctx.newPage();
    const found = [];
    p.on('console', m => { const t = m.text();
      if (/Refused to (execute|apply|load|connect)/i.test(t) || /Content Security Policy/i.test(t)) found.push('CSP: ' + t); });
    p.on('pageerror', e => found.push('JS: ' + e.message));

    let status = 0, html = '';
    try {
      const r = await p.goto(BASE + path, { waitUntil: 'domcontentloaded', timeout: 25000 });
      status = r ? r.status() : 0;
      await p.waitForTimeout(700);
      html = await p.content();
    } catch (e) { found.push('NAV: ' + e.message); }

    // A page that bounced to login tells us nothing about its own scripts.
    const bounced = p.url().includes('/login') && !path.includes('/login');
    if (bounced) unauth++;

    const csp = found.filter(f => f.startsWith('CSP:'));
    const js  = found.filter(f => !f.startsWith('CSP:'));
    violations += csp.length; jsErrors += js.length; visited++;

    const unNonced = [...html.matchAll(/<(script|style)\b([^>]*)>/gi)]
      .filter(m => !/\ssrc=/i.test(m[2]) && !/nonce=/i.test(m[2]));
    const handlers = html.match(/\son(?:click|change|load|error|submit|input|focus|blur)\s*=\s*["']/i);
    if (unNonced.length) violations++;
    if (handlers) violations++;

    // A page that did not render is not a page that passed.
    const notOk = status !== 200;
    if (notOk) violations++;
    const flag = (csp.length || unNonced.length || handlers || notOk) ? 'BAD' : (bounced ? '(auth)' : 'ok ');
    console.log(`${flag.padEnd(6)} ${String(status).padEnd(3)} ${path}` +
      (csp.length      ? `\n        ${csp.slice(0,3).join('\n        ')}` : '') +
      (unNonced.length ? `\n        un-nonced: ${unNonced.slice(0,2).map(m => m[0]).join(' | ')}` : '') +
      (handlers        ? `\n        inline handler: ${handlers[0]}` : '') +
      (js.length       ? `\n        ${js.slice(0,2).join('\n        ')}` : ''));
    await p.close();
  }

  await browser.close();
  console.log(`\n${visited} pages · ${unauth} redirected to login · ${violations} violation(s) · ${jsErrors} JS error(s)`);
  process.exit(violations > 0 ? 1 : 0);
})();
