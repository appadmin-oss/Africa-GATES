/**
 * The promo band, driven in a browser.
 *
 * §4 of the handoff makes four demands that cannot be checked by reading the markup:
 * the band pauses on hover, on focus-within, on the pause button and under
 * `prefers-reduced-motion`; and an off-screen slide must be out of the tab order.
 * The last is the commonest carousel fault there is — a keyboard user tabs into a
 * slide nobody can see and the viewport scrolls sideways to nothing.
 *
 *     node scripts/promo-band-check.mjs
 */
import pkg from '/opt/node22/lib/node_modules/playwright/index.js';

const { chromium } = pkg;
const BASE = process.env.BASE || 'http://127.0.0.1:8102';
const results = [];
const check = (n, pass, detail = '') => {
  results.push(!!pass);
  console.log((pass ? '  ok   ' : '  FAIL ') + String(n).padEnd(48) + detail);
};

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

// ── the ordinary case ────────────────────────────────────────────────────────
let page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(800);

const band = '[data-ag-promos]';
check('the band is present', await page.$(band) !== null);
check('it announces itself as a carousel',
  await page.$eval(band, b => b.getAttribute('aria-roledescription')) === 'carousel');

const n = await page.$$eval('.pb__slide', s => s.length);
check('every slide is announced as one',
  await page.$$eval('.pb__slide', s => s.every(x => x.getAttribute('aria-roledescription') === 'slide')),
  n + ' slides');

// off-screen slides must be inert
check('off-screen slides are out of the tab order',
  await page.$$eval('.pb__slide', s => s.slice(1).every(x => x.hasAttribute('inert'))));
check('only the current slide is exposed',
  await page.$$eval('.pb__slide', s => !s[0].hasAttribute('aria-hidden')
    && s.slice(1).every(x => x.hasAttribute('aria-hidden'))));

// ── 2.2.2: it advances, and it can be stopped ────────────────────────────────
const at = () => page.$eval('[data-pb-track]', t => t.style.getPropertyValue('--pb-at'));
const before = await at();
await page.waitForTimeout(5600);
check('it advances on its own', (await at()) !== before, `${before} -> ${await at()}`);

await page.click('[data-pb-pause]');
const paused = await at();
await page.waitForTimeout(5600);
check('the pause button stops it', (await at()) === paused);
check('the pause button says what it does now',
  await page.$eval('[data-pb-pause]', b => b.getAttribute('aria-label')) === 'Play slides');
await page.click('[data-pb-pause]');

// hover
await page.hover(band);
const hovered = await at();
await page.waitForTimeout(5600);
check('hovering stops it', (await at()) === hovered);
await page.mouse.move(0, 0);

// focus-within — the one the comp does NOT do and §4 requires
await page.focus('.pb__dot-btn');
const focused = await at();
await page.waitForTimeout(5600);
check('focus inside stops it (§4, not in the comp)', (await at()) === focused);

// dots
check('dot targets are at least 32px',
  await page.$$eval('.pb__dot-btn', bs => bs.every(b => {
    const r = b.getBoundingClientRect(); return r.width >= 32 && r.height >= 32;
  })),
  (await page.$$eval('.pb__dot-btn', bs => bs.map(b => Math.round(b.getBoundingClientRect().width)))).join(','));

await page.click('.pb__dot-btn:nth-of-type(2)');
await page.waitForTimeout(300);
check('a dot moves to its slide', (await at()) === '1');
check('aria-current follows',
  await page.$$eval('.pb__dot-btn', bs => bs[1].getAttribute('aria-current') === 'true'
    && bs[0].getAttribute('aria-current') === 'false'));

await page.close();

// ── reduced motion ───────────────────────────────────────────────────────────
page = await browser.newPage({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(800);
const rm = await page.$eval('[data-pb-track]', t => t.style.getPropertyValue('--pb-at'));
await page.waitForTimeout(5600);
check('reduced motion stops it entirely',
  (await page.$eval('[data-pb-track]', t => t.style.getPropertyValue('--pb-at'))) === rm);
check('and the decoration is not animating',
  await page.$eval('.pb__ring', e => getComputedStyle(e).animationName === 'none'));
await page.close();

// ── with no JavaScript at all ────────────────────────────────────────────────
const ctx = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 900 } });
page = await ctx.newPage();
await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
check('with JS off the first slide is readable',
  await page.$eval('.pb__slide .pb__t', e => e.textContent.trim().length > 0),
  await page.$eval('.pb__slide .pb__t', e => e.textContent.trim()));
check('and its link works',
  await page.$eval('.pb__slide .pb__cta', e => (e.getAttribute('href') || '').length > 1));
await ctx.close();

await browser.close();

const bad = results.filter(r => !r).length;
console.log('\n  ' + (results.length - bad) + '/' + results.length + ' checks passed\n');
process.exit(bad ? 1 : 0);
