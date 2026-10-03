#!/usr/bin/env node
/**
 * The activity search's accessibility contract, verified in a real browser.
 *
 * ActivityPageAccessibilityTest asserts the MARKUP and the script's source. This
 * asserts the BEHAVIOUR, which is where an ARIA combobox actually fails: attributes
 * can all be present and correct while ArrowDown moves focus out of the input, or
 * while the status region never announces, or while the whole thing only works
 * because JavaScript happened to load.
 *
 * The two checks worth having a browser for:
 *   • focus STAYS in the input while aria-activedescendant moves. A static test
 *     cannot see document.activeElement.
 *   • the page still searches with JavaScript DISABLED — run in a second context
 *     with javaScriptEnabled:false, because a no-JS claim is only credible if
 *     something has actually turned it off.
 *
 *   BASE=http://127.0.0.1:8196 node tools/qa/activity-a11y.js
 */
const { chromium } = require('playwright');
const BASE = process.env.BASE || process.env.BASE_URL || 'http://127.0.0.1:8196';
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const p = await (await b.newContext()).newPage();
  const errs = [], csp = [];
  p.on('console', m => { const t = m.text();
    if (/Content Security Policy|Refused to/i.test(t)) csp.push(t);
    else if (m.type() === 'error') errs.push(t); });
  p.on('pageerror', e => errs.push('JS: ' + e.message));

  await p.goto(BASE + '/activity', { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(700);

  const fail = [];
  const ok = (cond, label) => { console.log((cond ? '  ok   ' : '  FAIL ') + label); if (!cond) fail.push(label); };

  // The script must have upgraded the plain input into a combobox.
  const up = await p.evaluate(() => {
    const i = document.getElementById('actQ');
    return { role: i.getAttribute('role'), expanded: i.getAttribute('aria-expanded'),
             controls: i.getAttribute('aria-controls'), auto: i.getAttribute('aria-autocomplete'),
             listRole: document.getElementById('actResults').getAttribute('role') };
  });
  ok(up.role === 'combobox', 'input upgraded to role=combobox');
  ok(up.controls === 'actResults', 'aria-controls points at the listbox');
  ok(up.auto === 'list', 'aria-autocomplete=list');
  ok(up.listRole === 'listbox', 'results list is role=listbox');

  // Type and wait for the live result.
  await p.click('#actQ');
  await p.type('#actQ', 'Nominee 1200', { delay: 25 });
  await p.waitForTimeout(900);
  const after = await p.evaluate(() => ({
    status: document.getElementById('actStatus').textContent.trim(),
    options: document.querySelectorAll('#actResults [role="option"]').length,
    expanded: document.getElementById('actQ').getAttribute('aria-expanded'),
  }));
  ok(after.options > 0, `live search returned options (${after.options})`);
  ok(/result/.test(after.status), `status announced: "${after.status.slice(0,60)}"`);
  ok(after.expanded === 'true', 'aria-expanded=true once results exist');

  // Keyboard: ArrowDown must set activedescendant WITHOUT moving focus off the input.
  await p.keyboard.press('ArrowDown');
  const nav = await p.evaluate(() => ({
    ad: document.getElementById('actQ').getAttribute('aria-activedescendant'),
    focusIsInput: document.activeElement && document.activeElement.id === 'actQ',
    selected: document.querySelectorAll('#actResults [aria-selected="true"]').length,
    activeStyled: document.querySelectorAll('#actResults [data-active="true"]').length,
  }));
  ok(!!nav.ad, 'ArrowDown sets aria-activedescendant');
  ok(nav.focusIsInput, 'focus STAYS in the input (query remains editable)');
  ok(nav.selected === 1, 'exactly one option is aria-selected');
  ok(nav.activeStyled === 1, 'the active option is visually marked for keyboard users');

  // The query must still be editable after arrowing.
  await p.keyboard.type('X');
  const stillEditable = await p.evaluate(() => document.getElementById('actQ').value.endsWith('X'));
  ok(stillEditable, 'typing after ArrowDown still edits the query');

  // Escape closes, second Escape clears.
  await p.waitForTimeout(500);
  await p.keyboard.press('ArrowDown');
  await p.keyboard.press('Escape');
  const closed = await p.evaluate(() => document.getElementById('actQ').getAttribute('aria-expanded'));
  ok(closed === 'false', 'first Escape closes the list');
  await p.keyboard.press('Escape');
  await p.waitForTimeout(400);
  const cleared = await p.evaluate(() => document.getElementById('actQ').value);
  ok(cleared === '', 'second Escape clears the input');

  // No-JS parity: same query, server-rendered.
  const ctx2 = await b.newContext({ javaScriptEnabled: false });
  const p2 = await ctx2.newPage();
  await p2.goto(BASE + '/activity?q=Nominee+1200', { waitUntil: 'domcontentloaded' });
  const noJs = await p2.evaluate(() => document.querySelectorAll('.act__link').length).catch(() => -1);
  const noJsHtml = await p2.content();
  ok(/Nominee 1200\d/.test(noJsHtml), 'results render with JavaScript DISABLED');
  ok(!/role="combobox"/.test(noJsHtml.replace(/<script[\s\S]*?<\/script>/g, '')),
     'with JS off the markup does not claim to be a combobox');

  console.log('\nCSP violations: ' + csp.length + '   JS errors: ' + errs.length);
  csp.slice(0,3).forEach(m => console.log('  CSP: ' + m.slice(0,120)));
  errs.filter(e => !/ERR_TUNNEL|Failed to load resource/.test(e)).slice(0,4).forEach(e => console.log('  ERR: ' + e.slice(0,120)));
  await b.close();
  console.log(fail.length ? `\n${fail.length} FAILED` : '\nall checks passed');
  process.exit(fail.length ? 1 : 0);
})();
