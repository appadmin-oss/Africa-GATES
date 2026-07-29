const { chromium } = require('playwright');

// waitUntil is domcontentloaded, not networkidle: `php -S` is single-threaded
// and a third-party script can hold a connection open forever, so networkidle
// never settles. Scripts are given an explicit settle window instead. NOTE_SETTLE
// BASE_URL or BASE — both are accepted because every other tool in tools/qa/ uses
// BASE, and a sweep that silently ignores the base URL you gave it reports on a
// server that is not running. See the mark logic below: that is exactly how this
// script once printed `ok 0` for seventeen pages it had never loaded.
const BASE = process.env.BASE_URL || process.env.BASE || 'http://127.0.0.1:8125';
const PAGES = ['/', '/awards', '/registry', '/leaderboard', '/nominate', '/vote',
               '/support', '/help', '/privacy', '/terms', '/pulse', '/shop',
               '/opportunities', '/events', '/blog', '/integrity', '/judges'];

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext();
  let violations = 0, jsErrors = 0, checked = 0, badPages = 0;

  for (const path of PAGES) {
    const page = await ctx.newPage();
    const found = [];
    page.on('console', m => {
      const t = m.text();
      if (/Refused to (execute|apply|load|connect)/i.test(t) || /Content Security Policy/i.test(t)) found.push('CSP: ' + t);
    });
    page.on('pageerror', e => found.push('JS: ' + e.message));

    let status = 0;
    try {
      const r = await page.goto(BASE + path, { waitUntil: 'domcontentloaded', timeout: 25000 });
      status = r ? r.status() : 0;
      // Give Alpine and the deferred scripts a moment to initialise.
      await page.waitForTimeout(900);
    } catch (e) { found.push('NAV: ' + e.message); }

    const csp = found.filter(f => f.startsWith('CSP:'));
    const js  = found.filter(f => f.startsWith('JS:') || f.startsWith('NAV:'));
    violations += csp.length; jsErrors += js.length; checked++;

    // A page that did not load, or loaded non-200, has ZERO CSP violations for the
    // uninteresting reason that no CSP was ever evaluated. Marking that `ok` is a
    // false green — the whole sweep once passed against a refused connection.
    const reachable = status >= 200 && status < 400;
    if (!reachable) badPages++;
    const mark = !reachable ? 'DEAD' : (csp.length === 0 ? 'ok ' : 'CSP ');
    console.log(`${mark} ${String(status).padEnd(3)} ${path}` +
      (csp.length ? `\n      ${csp.slice(0,4).join('\n      ')}` : '') +
      (js.length  ? `\n      ${js.slice(0,3).join('\n      ')}` : ''));
    await page.close();
  }

  // Alpine is the thing most likely to be broken by the CSP change — prove it booted.
  const p = await ctx.newPage();
  await p.goto(BASE + '/', { waitUntil: 'domcontentloaded', timeout: 25000 });
  await p.waitForTimeout(900);
  const alpine = await p.evaluate(() => ({
    loaded: typeof window.Alpine !== 'undefined',
    initialised: document.querySelectorAll('[x-data]').length > 0
      ? !!document.querySelector('[x-data]').__x || document.querySelectorAll('[x-data]').length > 0 : null,
    xDataCount: document.querySelectorAll('[x-data]').length,
    styledBody: getComputedStyle(document.body).backgroundColor,
    inlineStyleWorks: (() => { const d = document.createElement('div'); d.setAttribute('style','width:42px'); document.body.appendChild(d);
      const w = getComputedStyle(d).width; d.remove(); return w; })(),
  }));
  console.log('\nAlpine loaded:      ' + alpine.loaded);
  console.log('[x-data] elements:  ' + alpine.xDataCount);
  console.log('body background:    ' + alpine.styledBody + '   (a <style> block set this)');
  console.log('style= attr width:  ' + alpine.inlineStyleWorks + '  (must be 42px — style-src-attr)');
  await browser.close();

  console.log(`\n${checked} pages · ${badPages} unreachable · ${violations} CSP violation(s) · ${jsErrors} JS error(s)`);
  if (badPages > 0) console.log('An unreachable page proves nothing about the CSP — fix the server or the BASE first.');
  process.exit(violations > 0 || badPages > 0 ? 1 : 0);
})();
