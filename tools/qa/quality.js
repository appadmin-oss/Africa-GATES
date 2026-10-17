// Accessibility, responsive layout and page-weight sweep. These break real-world use
// as reliably as security bugs do, and none of it is visible to PHPUnit.
// NOTE_SETTLE — domcontentloaded, see tools/browser/README.md
const { chromium } = require('playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8125';
const PAGES = (process.env.QA_PAGES || '/,/awards,/registry,/leaderboard,/nominate,/vote,/privacy,/shop').split(',');
const PHASE = process.env.QA_PHASE || 'all';   // responsive | a11y | weight | all
const VIEWPORTS = [
  { name: 'phone   ', width: 360, height: 740 },
  { name: 'tablet  ', width: 768, height: 1024 },
  { name: 'desktop ', width: 1440, height: 900 },
];

let fails = 0, warns = 0;
const check = (n, ok, d = '') => { console.log((ok ? '  PASS  ' : '  FAIL  ') + n + (d ? '  → ' + d : '')); if (!ok) fails++; };
const warn = (n, ok, d = '') => { if (!ok) { console.log('  WARN  ' + n + (d ? '  → ' + d : '')); warns++; } };

(async () => {
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });

  if (PHASE === 'all' || PHASE === 'responsive') {
  console.log('RESPONSIVE — no horizontal overflow at any width');
  for (const vp of VIEWPORTS) {
    const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
    const page = await ctx.newPage();
    const overflowing = [];
    for (const path of PAGES) {
      await page.goto(BASE + path, { waitUntil: 'domcontentloaded', timeout: 25000 });
      await page.waitForTimeout(150);
      const o = await page.evaluate(() => ({
        docW: document.documentElement.scrollWidth,
        winW: window.innerWidth,
        // The specific elements sticking out, so a failure is actionable.
        culprits: [...document.querySelectorAll('body *')]
          .filter(e => e.getBoundingClientRect().right > window.innerWidth + 2)
          .slice(0, 3)
          .map(e => e.tagName.toLowerCase() + (e.className ? '.' + String(e.className).split(' ')[0] : '')),
      }));
      if (o.docW > o.winW + 2) overflowing.push(`${path} (${o.docW}>${o.winW}: ${o.culprits.join(', ')})`);
    }
    check(`${vp.name} ${vp.width}px: no page scrolls sideways`, overflowing.length === 0,
      overflowing.slice(0, 3).join('  |  '));
    await ctx.close();
  }
  }

  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();

  if (PHASE === 'all' || PHASE === 'a11y') {
  console.log('\nACCESSIBILITY — the checks a screen-reader user depends on');
  for (const path of PAGES) {
    await page.goto(BASE + path, { waitUntil: 'domcontentloaded', timeout: 25000 });
    await page.waitForTimeout(150);
    const a = await page.evaluate(() => {
      const q = s => [...document.querySelectorAll(s)];
      return {
        lang: document.documentElement.getAttribute('lang'),
        title: (document.title || '').trim(),
        h1: q('h1').length,
        imgNoAlt: q('img:not([alt])').length,
        inputsNoLabel: q('input:not([type=hidden]):not([type=submit]):not([type=button]), select, textarea')
          .filter(el => !el.getAttribute('aria-label') && !el.getAttribute('aria-labelledby')
            && !(el.id && document.querySelector(`label[for="${el.id}"]`)) && !el.closest('label')).length,
        // aria-hidden="true" elements are correctly invisible to assistive tech, so a
        // nameless one is not a defect. The first run flagged .gee-scrim on every page
        // — an aria-hidden, tabindex=-1 overlay that no screen reader ever announces.
        btnNoName: q('button').filter(b => !(b.textContent || '').trim()
          && !b.getAttribute('aria-label') && !b.getAttribute('title')
          && b.getAttribute('aria-hidden') !== 'true').length,
        linksNoName: q('a[href]').filter(a => !(a.textContent || '').trim()
          && !a.getAttribute('aria-label') && !a.getAttribute('title')
          && a.getAttribute('aria-hidden') !== 'true'
          && !a.querySelector('img[alt]:not([alt=""])')).length,
        skipLink: q('a[href^="#"]').some(a => /skip/i.test(a.textContent || '')),
        landmarks: q('main, [role=main]').length,
      };
    });
    const problems = [];
    if (!a.lang) problems.push('no lang attribute');
    if (!a.title) problems.push('no <title>');
    if (a.h1 !== 1) problems.push(`${a.h1} <h1>`);
    if (a.imgNoAlt) problems.push(`${a.imgNoAlt} img without alt`);
    if (a.inputsNoLabel) problems.push(`${a.inputsNoLabel} unlabelled field(s)`);
    if (a.btnNoName) problems.push(`${a.btnNoName} nameless button(s)`);
    if (a.linksNoName) problems.push(`${a.linksNoName} nameless link(s)`);
    if (!a.landmarks) problems.push('no <main> landmark');
    check(`${path}`, problems.length === 0, problems.join(', '));
    warn(`${path}: no skip-to-content link`, a.skipLink);
  }
  }

  if (PHASE === 'all' || PHASE === 'weight') {
  console.log('\nPAGE WEIGHT — what a Nigerian mobile connection actually pays for');
  for (const path of ['/', '/awards', '/vote', '/registry']) {
    let bytes = 0, requests = 0, slow = [];
    const onResp = async (r) => {
      requests++;
      try { const b = await r.body(); bytes += b.length; } catch {}
    };
    page.on('response', onResp);
    const t0 = Date.now();
    await page.goto(BASE + path, { waitUntil: 'load', timeout: 30000 }).catch(() => {});
    const ms = Date.now() - t0;
    await page.waitForTimeout(300);
    page.off('response', onResp);
    const kb = Math.round(bytes / 1024);
    console.log(`  ${path.padEnd(12)} ${String(requests).padStart(3)} req  ${String(kb).padStart(5)} KB  ${ms} ms`);
    warn(`${path} is over 2 MB`, kb < 2048, kb + ' KB');
  }
  }

  await browser.close();
  console.log(`\n${fails} failure(s) · ${warns} warning(s)`);
  process.exit(fails ? 1 : 0);
})();
