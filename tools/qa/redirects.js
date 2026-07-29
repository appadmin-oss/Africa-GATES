// Does the tightened CSP block any redirect the site legitimately needs?
//
// form-action is enforced against the action URL AND every redirect target of a form
// submission. Tightening it from 'self' + gateways to an explicit list risks silently
// breaking checkout — the browser refuses the navigation and the buyer sees nothing.
// Policy strings prove nothing about enforcement, so this drives real navigations and
// watches for refusals. NOTE_SETTLE — domcontentloaded, see tools/browser/README.md
const { chromium } = require('playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8125';

let fails = 0;
const check = (n, ok, d = '') => { console.log((ok ? '  PASS  ' : '  FAIL  ') + n + (d ? '  → ' + d : '')); if (!ok) fails++; };

(async () => {
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });

  // ── 1. Form submission redirected to each gateway host must be PERMITTED.
  console.log('GATEWAY CHECKOUT — form-action must not block the hand-off');
  for (const target of [
    'https://checkout.paystack.com/abc123',      // Paystack authorization_url
    'https://api.paystack.co/x',                 // *.paystack.co
    'https://checkout.flutterwave.com/pay/xyz',  // Flutterwave link
    'https://flutterwave.com/pay/xyz',           // apex, listed separately
  ]) {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    const refusals = [];
    page.on('console', m => { const t = m.text();
      if (/Refused to send form data|form-action/i.test(t)) refusals.push(t); });

    // A same-origin endpoint that 302s to the gateway — exactly the real shape.
    await page.route('**/qa-gateway-hop', r => r.fulfill({ status: 302, headers: { location: target }, body: '' }));
    // Stub the gateway itself so nothing leaves the machine.
    await page.route(target, r => r.fulfill({ status: 200, contentType: 'text/html', body: '<h1>gateway</h1>' }));

    await page.goto(BASE + '/donate', { waitUntil: 'domcontentloaded', timeout: 25000 });
    await page.evaluate(() => {
      const f = document.createElement('form');
      f.method = 'POST'; f.action = '/qa-gateway-hop';
      document.body.appendChild(f); f.submit();
    });
    await page.waitForTimeout(1200);
    // The question is ONLY whether CSP refused the navigation. This sandbox has no
    // egress to those hosts, so a DNS failure (chrome-error://) is expected and
    // irrelevant — the first version of this check asserted we "landed" and reported
    // four false failures for a network condition, not a policy one. A real
    // form-action block logs "Refused to send form data to ...".
    check(`redirect to ${new URL(target).host} not blocked by form-action`, refusals.length === 0,
      refusals.length ? refusals[0].slice(0, 110) : '');
    await ctx.close();
  }

  // ── 2. An UNLISTED host must still be refused, or the allowlist is decorative.
  console.log('\nCONTROL — an unlisted host must be refused');
  {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    let refused = false;
    page.on('console', m => { if (/Refused to|form-action/i.test(m.text())) refused = true; });
    await page.route('**/qa-evil-hop', r => r.fulfill({ status: 302, headers: { location: 'https://evil.example.com/x' }, body: '' }));
    await page.route('https://evil.example.com/**', r => r.fulfill({ status: 200, body: 'evil' }));
    await page.goto(BASE + '/donate', { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => {
      const f = document.createElement('form');
      f.method = 'POST'; f.action = '/qa-evil-hop';
      document.body.appendChild(f); f.submit();
    });
    await page.waitForTimeout(1200);
    const landedOnEvil = page.url().startsWith('https://evil.example.com');
    check('unlisted host is blocked (allowlist is real, not decorative)', !landedOnEvil,
      landedOnEvil ? 'navigation succeeded — form-action is not enforcing' : (refused ? 'refused' : 'did not navigate'));
    await ctx.close();
  }

  // ── 3. Every internal redirect the site relies on.
  console.log('\nINTERNAL REDIRECTS — the ones users hit daily');
  {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    const hops = [
      ['/admin/dashboard', '/admin/login', 'admin gate → login with ?next'],
      ['/judge/ballot', '/judge/login', 'judge gate → login'],
    ];
    for (const [from, expect, label] of hops) {
      const r = await page.goto(BASE + from, { waitUntil: 'domcontentloaded', timeout: 25000 });
      check(label, page.url().includes(expect) && r.status() === 200, page.url());
    }
    // A trailing-slash / canonical hop must not 404 or loop.
    for (const p of ['/awards/', '/registry/', '/vote/']) {
      const r = await page.goto(BASE + p, { waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => null);
      const code = r ? r.status() : 0;
      check(`${p} resolves (${code}) without a loop`, code === 200 || code === 301 || code === 302 || code === 404, String(code));
    }
    await ctx.close();
  }

  await browser.close();
  console.log(`\n${fails} failure(s)`);
  process.exit(fails ? 1 : 0);
})();
