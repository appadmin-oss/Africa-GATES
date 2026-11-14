/**
 * THE VOLUNTEER ACADEMY'S SCREENSHOTS — regenerate after any change to the volunteer workspace:
 *
 *     node scripts/academy-shots.mjs
 *
 * Seeds a throwaway SQLite database (scripts/academy-seed.php: invented people only), serves the
 * app on it (scripts/academy-router.php; no .env is written), opens each page at 1366×768,
 * outlines the control the step is about in the console's own green (a palette token, never a
 * typed colour) and crops to that part of the screen with some context around it. Writes
 * public/assets/img/academy/{stem}.webp — the stems VolunteerController::SHOTS names — and
 * removes its session files and the maintenance markers the dev server leaves in var/data.
 */
import pkg from '/opt/node22/lib/node_modules/playwright/index.js';
import { execFileSync, spawn } from 'node:child_process';
import { mkdtempSync, rmSync, unlinkSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, 'public/assets/img/academy');
const tmp = mkdtempSync(join(tmpdir(), 'academy-'));
const db = join(tmp, 'academy.sqlite');
const seed = JSON.parse(execFileSync('php', [join(ROOT, 'scripts/academy-seed.php'), db], { encoding: 'utf8' }).trim().split('\n').pop());
const PORT = 8139, BASE = `http://127.0.0.1:${PORT}`;
const srv = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', join(ROOT, 'public'), join(ROOT, 'scripts/academy-router.php')],
  { env: { ...process.env, DB_DRIVER: 'sqlite', DB_PATH: db, APP_ENV: 'local', SESSION_SECURE: 'false' }, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1200));

const c = seed.cycle, e = seed.event, n = seed.nominee;
// [stem, who, url, the control to outline]
const SHOTS = [
  ['start', null, '/volunteer/apply', 'body'],
  ['today', 'volunteer', '/volunteer', '.sh7-head__acts .sh7-btn--primary'],
  ['nominees', 'volunteer', `/volunteer/cycle/${c}/nominees`, '.sh7-tabs'],
  ['log-contact', 'volunteer', `/volunteer/cycle/${c}/nominees?n=${n}`, '.sh7-sec--form:has(input[value="outreach_log"]) .sh7-form__submit .sh7-btn'],
  ['follow-up', 'volunteer', `/volunteer/cycle/${c}/nominees/due`, '.sh7-tab.is-on'],
  ['hours-log', 'volunteer', '/volunteer/hours/log', '.sh7-form__submit .sh7-btn'],
  ['hours-review', 'volunteer', '/volunteer/hours/review', '.sh7-rowform .sh7-btn'],
  ['event', 'volunteer', `/volunteer/event/${e}`, '.sh7-hero__acts .sh7-btn--primary'],
  ['tasks', 'volunteer', '/volunteer/tasks', '.sh7-tabs'],
  ['requests', 'volunteer', '/volunteer/requests?new=hours', '.sh7-form__submit .sh7-btn'],
  ['record', 'volunteer', '/volunteer/record', '.sh7-tabs'],
  ['break', 'volunteer', '/volunteer/me/leave', '.sh7-page'],
  ['team', 'lead', '/volunteer/team/approvals', '.sh7-rowform'],
];

// THE WHOLE SCREEN, NOT A STRIP (owner, 1 Nov 2026: "it does not have the screenshots"). The
// first cut cropped to the part of the page a step was about, which left a 100px header bar for
// Today and a sliver of tabs for Nominees: nobody could tell what screen they were looking at.
// Each shot is now the full window at a laptop size, scrolled so the control sits in view, with
// that control outlined; the academy frames it as a browser window.
const W = 1200, H = 760;
const { chromium } = pkg;
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const failed = [];
try {
  for (const [stem, who, url, mark] of SHOTS) {
    const ctx = await browser.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 1.5, bypassCSP: true });
    // `null` is somebody signed out: the application is the first thing a new volunteer sees.
    if (who) await ctx.addCookies([{ name: 'PHPSESSID', value: seed.session[who], url: BASE }]);
    const page = await ctx.newPage();
    await page.goto(BASE + url, { waitUntil: 'networkidle' });
    await page.addStyleTag({ content: '[data-academy-mark]{outline:3px solid var(--cn-green)!important;outline-offset:3px;border-radius:8px}' });
    const m = await page.$(mark);
    if (!m) { failed.push(stem + ' (' + mark + ')'); await ctx.close(); continue; }
    // A whole-page mark (.sh7-page, body) is the screen itself: nothing to outline.
    if (!['.sh7-page', 'body'].includes(mark)) await m.evaluate(el => el.setAttribute('data-academy-mark', ''));
    // Only <main> scrolls in the shell. Leave a control near the top where it is; bring one further
    // down into the middle of the window.
    await m.evaluate(el => { if (el.getBoundingClientRect().bottom > innerHeight - 60) el.scrollIntoView({ block: 'center' }); });
    await page.waitForTimeout(150);
    const png = join(tmp, stem + '.png');
    await page.screenshot({ path: png });
    execFileSync('php', ['-r', `$i=imagecreatefrompng($argv[1]);imagewebp($i,$argv[2],80);`, png, join(OUT, stem + '.webp')]);
    await ctx.close();
  }
} finally {
  await browser.close();
  srv.kill();
  for (const s of Object.values(seed.session)) { const f = join(ROOT, 'var/sessions/sess_' + s); if (existsSync(f)) unlinkSync(f); }
  for (const f of ['var/data/.gates-maintenance.lock', 'var/data/.maintenance_tick']) if (existsSync(join(ROOT, f))) unlinkSync(join(ROOT, f));
  rmSync(tmp, { recursive: true, force: true });
}
if (failed.length) { console.error('Not found on the page: ' + failed.join(', ')); process.exit(1); }
console.log('Wrote ' + SHOTS.length + ' screenshots to public/assets/img/academy/');
