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
// [stem, who, url, element to outline, area to crop to]
const SHOTS = [
  ['today', 'volunteer', '/volunteer', '.sh7-head__acts .sh7-btn--primary', '.sh7-head'],
  ['nominees', 'volunteer', `/volunteer/cycle/${c}/nominees`, '.sh7-tabs', '.sh7-tabs, .sh7-sec--rows'],
  ['log-contact', 'volunteer', `/volunteer/cycle/${c}/nominees?n=${n}`, '.sh7-sec--form:has(input[value="outreach_log"]) .sh7-form__submit .sh7-btn', '.sh7-sec--form:has(input[value="outreach_log"])'],
  ['follow-up', 'volunteer', `/volunteer/cycle/${c}/nominees/due`, '.sh7-tab.is-on', '.sh7-tabs, .sh7-sec--rows'],
  ['hours-log', 'volunteer', '/volunteer/hours/log', '.sh7-form__submit .sh7-btn', '.sh7-sec--form'],
  ['hours-review', 'volunteer', '/volunteer/hours/review', '.sh7-rowform .sh7-btn', '.sh7-tabs, .sh7-sec--rows'],
  ['event', 'volunteer', `/volunteer/event/${e}`, '.sh7-hero__acts .sh7-btn--primary', '.sh7-hero'],
  ['requests', 'volunteer', '/volunteer/requests?new=hours', '.sh7-form__submit .sh7-btn', '.sh7-sec--form'],
  ['team', 'lead', '/volunteer/team/approvals', '.sh7-rowform', '.sh7-tabs, .sh7-sec--rows'],
];

const { chromium } = pkg;
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const failed = [];
try {
  for (const [stem, who, url, mark, area] of SHOTS) {
    const ctx = await browser.newContext({ viewport: { width: 1366, height: 768 }, bypassCSP: true });
    await ctx.addCookies([{ name: 'PHPSESSID', value: seed.session[who], url: BASE }]);
    const page = await ctx.newPage();
    await page.goto(BASE + url, { waitUntil: 'networkidle' });
    await page.addStyleTag({ content: '[data-academy-mark]{outline:3px solid var(--cn-green)!important;outline-offset:3px;border-radius:8px}' });
    const m = await page.$(mark);
    if (m) await m.evaluate(el => el.setAttribute('data-academy-mark', ''));
    const box = await page.evaluate(([sel, markSel]) => {
      const els = [...document.querySelectorAll(sel)].filter(x => x.getBoundingClientRect().height > 0).slice(0, 3);
      if (!els.length) return null;
      // Only <main> scrolls in the shell, so bring the area to the top of the window and crop in
      // window coordinates; an area taller than the window is cut at its foot.
      els[0].scrollIntoView({ block: 'start' });
      // …unless that hides the control the step is about: then the control sits at the foot.
      const mk = document.querySelector(markSel);
      if (mk && mk.getBoundingClientRect().bottom > innerHeight - 40) mk.scrollIntoView({ block: 'end' });
      const r = els.map(x => x.getBoundingClientRect());
      return { x: Math.min(...r.map(b => b.left)), y: Math.max(0, Math.min(...r.map(b => b.top))),
               r: Math.max(...r.map(b => b.right)), b: Math.min(innerHeight, Math.max(...r.map(b => b.bottom)), mk ? mk.getBoundingClientRect().bottom + 16 : innerHeight) };
    }, [area, mark]);
    if (!box || !m) { failed.push(stem + ' (' + (m ? area : mark) + ')'); await ctx.close(); continue; }
        const pad = 24, clip = { x: Math.max(0, box.x - pad), y: Math.max(0, box.y - pad) };
    clip.width = Math.min(1366 - clip.x, box.r - box.x + 2 * pad);
    clip.height = Math.min(768 - clip.y, box.b - box.y + 2 * pad);
    const png = join(tmp, stem + '.png');
    await page.screenshot({ path: png, clip });
    execFileSync('php', ['-r', `$i=imagecreatefrompng($argv[1]);imagewebp($i,$argv[2],72);`, png, join(OUT, stem + '.webp')]);
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
