/**
 * The admin console's behaviour layer (public/assets/js/console-ux.js), driven in a real
 * browser at desktop and phone width.
 *
 * ConsoleUxTest reads the markup; it cannot see what the BROWSER does: that a find box
 * narrows the rows, a header sorts, a row opens its record, three presses send one
 * request, a filter applies when chosen, and a phone list is cards rather than a strip.
 *
 *     php -S 127.0.0.1:8102 -t public <a router that returns false for real files>
 *     ADMIN_EMAIL=… ADMIN_PASS=… node scripts/console-ux-check.mjs [screenshot-dir]
 *
 * Needs a list with at least eight rows on /admin/programmes. Exits non-zero on a failure.
 */
import fs from 'fs';
import os from 'os';
import pkg from '/opt/node22/lib/node_modules/playwright/index.js';
const { chromium } = pkg;
const BASE = process.env.BASE || 'http://127.0.0.1:8102';
const OUT = process.argv[2] || os.tmpdir();
const STATE = OUT + '/console-ux-state.json';
const res = []; const check = (n, ok, d = '') => { res.push(ok); console.log((ok ? '  ok   ' : '  FAIL ') + n.padEnd(58) + d); };
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
{ // sign in ONCE: the admin sign-in is throttled per address, and a check that signs in per
  // page trips it and then reports the login form as every page it visits.
  const c0 = await browser.newContext(); const p0 = await c0.newPage();
  await p0.goto(BASE + '/admin/login');
  await p0.fill('input[name=email]', process.env.ADMIN_EMAIL || ''); await p0.fill('input[name=password]', process.env.ADMIN_PASS || '');
  await Promise.all([p0.waitForNavigation(), p0.click('button[type=submit]')]);
  await c0.storageState({ path: STATE }); await c0.close();
}
async function login(ctx) {
  const p = await ctx.newPage(); const errs = [];
  p.on('pageerror', e => errs.push(String(e).slice(0, 200)));
  p.errs = errs; return p;
}
// ── desktop
let ctx = await browser.newContext({ storageState: STATE, viewport: { width: 1440, height: 900 } });
let p = await login(ctx);
await p.goto(BASE + '/admin/programmes'); await p.waitForTimeout(300);
const n = await p.$$eval('.cn-main tbody tr', r => r.length);
check('programmes list has rows', n >= 20, n + ' rows');
check('a find box is offered', !!(await p.$('[data-ux-find]')));
await p.fill('[data-ux-find]', 'nairobi'); await p.waitForTimeout(100);
const shown = await p.$$eval('.cn-main tbody tr', r => r.filter(x => !x.hidden).length);
check('find narrows the list', shown > 0 && shown < n, shown + ' of ' + n);
check('the count says so', /of/.test(await p.textContent('.cn-find__n')), await p.textContent('.cn-find__n'));
await p.fill('[data-ux-find]', 'zzzz'); await p.waitForTimeout(100);
check('no match says so', await p.isVisible('.cn-find__empty'));
await p.fill('[data-ux-find]', '');
const sortBtn = await p.$('th .cn-sort');
check('headers sort', !!sortBtn);
await sortBtn.click();
const first = await p.$$eval('.cn-main tbody tr td:first-child', t => t.slice(0, 3).map(x => x.textContent.trim().slice(0, 18)));
check('aria-sort set', (await p.$eval('th[aria-sort="ascending"]', x => !!x).catch(() => false)), first.join(' | '));
await sortBtn.click();
const firstDesc = await p.$$eval('.cn-main tbody tr td:first-child', t => t[0].textContent.trim().slice(0, 18));
check('second press reverses', firstDesc !== first[0], firstDesc);
check('rows are links', (await p.$$eval('tr[data-row-href]', r => r.length)) > 0);
await p.click('h1'); await p.keyboard.press('j'); await p.keyboard.press('j');
check('j moves the cursor', !!(await p.$('tr.is-cursor')));
await p.keyboard.press('?'); await p.waitForTimeout(100);
check('? opens the shortcut list', await p.isVisible('dialog.cn-keys'));
await p.screenshot({ path: OUT + '/ux-keys.png' });
await p.keyboard.press('Escape');
await p.keyboard.press('/'); 
check('/ focuses the find box', await p.evaluate(() => document.activeElement && document.activeElement.hasAttribute('data-ux-find')));
await p.keyboard.press('Escape'); await p.click('h1');
const href = await p.$eval('tr[data-row-href]', r => r.getAttribute('data-row-href'));
await Promise.all([p.waitForNavigation(), p.click('tr[data-row-href] td:nth-child(3)')]);
check('clicking a row opens it', p.url().endsWith(href), href);
await p.screenshot({ path: OUT + '/ux-edit.png' });
// double submit + busy label
await p.goto(BASE + '/admin/settings');
let posts = 0; p.on('request', r => { if (r.method() === 'POST' && r.url().includes('/admin/settings')) posts++; });
await p.evaluate(() => { const b = document.querySelector('#st-form button[type=submit], button[form="st-form"]'); b.click(); b.click(); b.click(); });
await p.waitForLoadState('domcontentloaded'); await p.waitForTimeout(800);
check('three presses send one request', posts === 1, posts + ' POST');
// filters auto-apply
await p.goto(BASE + '/admin/nominees');
const sel = await p.$('.cn-main form[method=get] select');
const opts = await sel.$$eval('option', o => o.map(x => x.value).filter(Boolean));
await Promise.all([p.waitForNavigation(), sel.selectOption(opts[0])]);
check('a filter applies when chosen', p.url().includes('?'), p.url().replace(BASE, ''));
const clears = await p.$$eval('.cn-main form[method=get] a', as => as.filter(a => /^clear/i.test(a.textContent.trim())).map(a => a.textContent.trim()));
check('and offers to clear it, exactly once', clears.length === 1, clears.join(', '));
check('no page errors (desktop)', p.errs.length === 0, p.errs.join(' / '));
await ctx.close();
// ── phone
ctx = await browser.newContext({ storageState: STATE, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
p = await login(ctx);
await p.goto(BASE + '/admin/programmes'); await p.waitForTimeout(300);
const st = await p.evaluate(() => { const t = document.querySelector('table[data-stack]'); const td = t && t.querySelector('tbody td:nth-child(2)'); return { stacked: !!t, disp: td && getComputedStyle(td).display, label: td && getComputedStyle(td, '::before').content, w: document.documentElement.scrollWidth }; });
check('phone: rows are labelled cards', st.stacked && st.disp === 'flex' && st.label && st.label !== 'none', JSON.stringify(st));
check('phone: no sideways page', st.w <= 390, st.w + 'px');
await p.screenshot({ path: OUT + '/ux-phone-list.png' });
await p.goto(BASE + '/admin/nominees'); await p.waitForTimeout(300);
check('phone: filters fold behind one button', await p.isVisible('.cn-filter-toggle') && !(await p.isVisible('form.cn-folded select')));
await p.screenshot({ path: OUT + '/ux-phone-nominees.png' });
await p.click('.cn-filter-toggle');
check('phone: and open on demand', await p.isVisible('.cn-main form[method=get] select'));
check('no page errors (phone)', p.errs.length === 0, p.errs.join(' / '));
await browser.close();
process.exit(res.every(Boolean) ? 0 : 1);
