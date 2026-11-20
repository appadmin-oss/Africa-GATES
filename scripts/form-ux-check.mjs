/**
 * The form error state, driven in a real browser.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS BESIDE `FormErrorStateTest`
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * That test reads the markup and the CSS. It cannot see the two faults that actually
 * shipped here, because both are about what the BROWSER does with them:
 *
 *   1. The validator required a `.ag-fieldset` wrapper to place its message. On every
 *      form built before the macros — which is most of them — it found none, bailed,
 *      and the only thing the enhancement did was suppress the native bubble. A form
 *      with `novalidate` and no message is strictly worse than the bubble it replaced.
 *
 *   2. Validating on blur INSERTS an element. With `autofocus` on the first field, the
 *      press of the submit button blurred it, the message appeared, everything below
 *      shifted down, and the button moved out from under the pointer between mousedown
 *      and mouseup — so the browser generated no click and the form never submitted.
 *      No error, no console line; the first press simply did nothing and the second
 *      worked. On a phone that is a dead button.
 *
 * Neither is visible in the source. Run it against the dev server:
 *
 *     php -S 127.0.0.1:8102 -t public <a router that returns false for real files>
 *     node scripts/form-ux-check.mjs
 *
 * Exits non-zero on a failure, so it is usable from CI the day there is one.
 */

import pkg from '/opt/node22/lib/node_modules/playwright/index.js';

const { chromium } = pkg;
const BASE = process.env.BASE || 'http://127.0.0.1:8102';
const URL_ = BASE + '/account/register?as=individual';

const results = [];
const check = (name, pass, detail = '') => {
  results.push({ name, pass: !!pass, detail });
  console.log((pass ? '  ok   ' : '  FAIL ') + name.padEnd(46) + detail);
};

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage();

await page.goto(URL_, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(700);

check('the native bubble is off',
  await page.$eval('form[data-ag-validate]', f => f.hasAttribute('novalidate')));
check('the validator attached',
  await page.evaluate(() => !!document.querySelector('form[data-ag-validate][data-ag-validated]')));

// ── fault 2: the first press must submit ──────────────────────────────────────
let submitted = false;
await page.exposeFunction('sawSubmit', () => { submitted = true; });
await page.evaluate(() => document.querySelector('form[data-ag-validate]')
  .addEventListener('submit', () => window.sawSubmit(), true));

await page.click('button[type="submit"]');
await page.waitForTimeout(400);

check('the FIRST press of submit is not eaten by a layout shift', submitted);

// ── the summary ───────────────────────────────────────────────────────────────
const sum = await page.$('[data-ag-form-summary]');
check('a summary appears', !!sum);
check('the summary is announced', sum && (await sum.getAttribute('role')) === 'alert');
check('the summary takes focus',
  await page.evaluate(() => document.activeElement?.hasAttribute('data-ag-form-summary')));
check('the summary counts the failures',
  await page.$eval('.ag-formsum__t', e => /There (is one|are \d+) thing/.test(e.textContent)),
  await page.$eval('.ag-formsum__t', e => e.textContent.trim()));
check('every failure links to its field',
  await page.$$eval('.ag-formsum__list a', as => as.length > 0 && as.every(a => {
    const id = a.getAttribute('href').slice(1);
    return !!document.getElementById(id);
  })));
// WCAG 2.5.8 — the AA 24px floor is NOT conditional on a coarse pointer.
check('summary links meet the 24px target floor',
  await page.$$eval('.ag-formsum__list a',
    as => as.every(a => a.getBoundingClientRect().height >= 24)),
  (await page.$$eval('.ag-formsum__list a', as => as.map(a => Math.round(a.getBoundingClientRect().height)))).join(','));

// ── the field ─────────────────────────────────────────────────────────────────
check('the field is marked invalid',
  await page.$eval('#name', e => e.getAttribute('aria-invalid') === 'true'));
check('the message is pointed at by describedby',
  await page.$eval('#name', e => (e.getAttribute('aria-describedby') || '').includes('name-err')));
check('the message is a sentence, not a colour',
  await page.$eval('#name-err', e => e.textContent.trim().length > 5),
  await page.$eval('#name-err', e => e.textContent.trim()));
check('the icon is hidden from assistive technology',
  await page.$eval('#name-err svg', e => e.getAttribute('aria-hidden') === 'true'));

// fault 1: the state has to be VISIBLE, not only announced.
check('the control is visibly marked (not only announced)',
  await page.$eval('#name', e => {
    const cs = getComputedStyle(e);
    return e.hasAttribute('data-invalid') && cs.boxShadow !== 'none';
  }),
  await page.$eval('#name', e => getComputedStyle(e).boxShadow.slice(0, 34)));

// ── correcting it ─────────────────────────────────────────────────────────────
await page.fill('#email', 'not-an-address');
await page.click('#phone');
await page.waitForTimeout(200);
check('blurring to another field still validates',
  await page.$eval('#email', e => e.getAttribute('aria-invalid') === 'true'));

await page.fill('#email', 'still-wrong');
await page.waitForTimeout(150);
check('it stays marked while still wrong',
  await page.$eval('#email', e => e.getAttribute('aria-invalid') === 'true'));

await page.fill('#email', 'someone@example.com');
await page.waitForTimeout(150);
check('it clears the moment it is right',
  await page.$eval('#email', e => !e.hasAttribute('aria-invalid')));

// ── and the whole point: a good form must still go through ────────────────────
await page.fill('#name', 'Ada Lovelace');
await page.fill('#email', `ada.${Date.now()}@example.com`);
await page.fill('#phone', '+2348031234567');
const before = page.url();
await page.click('button[type="submit"]');
await page.waitForTimeout(1500);
check('a valid form still posts', page.url() !== before,
  page.url().replace(BASE, ''));

await browser.close();

const failed = results.filter(r => !r.pass);
console.log('\n  ' + (results.length - failed.length) + '/' + results.length + ' checks passed\n');
process.exit(failed.length ? 1 : 0);
