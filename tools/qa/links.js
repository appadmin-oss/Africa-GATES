#!/usr/bin/env node
/**
 * Crawl every internal link the site actually renders and report the dead ones.
 *
 * A 404 on a URL nobody links to is invisible. A 404 on a link in the footer is
 * on every page of the site. So this crawls from the rendered HTML rather than
 * from the route table: it only reports links a visitor can reach by clicking,
 * and it reports WHERE each one was found, because the fix for a dead link is
 * usually in the template that emits it.
 *
 * GET only, same-origin only, and every URL is fetched once no matter how many
 * pages point at it.
 *
 *   BASE=http://127.0.0.1:8197 node tools/qa/links.js
 */
const BASE = process.env.BASE || 'http://127.0.0.1:8197';
const MAX_PAGES = Number(process.env.MAX_PAGES || 60);

// Seeds: the public surface a visitor lands on. Crawling expands from here.
const SEEDS = [
  '/', '/awards', '/registry', '/leaderboard', '/vote', '/nominate', '/pulse',
  '/shop', '/support', '/community', '/events', '/blog', '/opportunities',
  '/privacy', '/terms', '/account/login', '/admin/login', '/judge/login',
];

const seen = new Map();          // url -> {status, redirect}
const foundOn = new Map();       // url -> Set<page>
const queue = [...SEEDS];
const crawled = new Set();

const norm = (href, from) => {
  if (!href) return null;
  const h = href.trim();
  // Non-navigational: fragments, and schemes that are not a page fetch.
  if (h === '' || h.startsWith('#')) return null;
  if (/^(mailto:|tel:|javascript:|data:|sms:|whatsapp:)/i.test(h)) return null;
  let u;
  try { u = new URL(h, new URL(from, BASE)); } catch { return null; }
  if (u.origin !== new URL(BASE).origin) return null;   // external: not ours to fix
  u.hash = '';
  return u.pathname + u.search;
};

async function check(path) {
  if (seen.has(path)) return seen.get(path);
  let r;
  try {
    // manual redirect so a 301 is reported as a 301, not silently followed —
    // a link that needs a redirect to work is worth knowing about.
    r = await fetch(new URL(path, BASE), { redirect: 'manual' });
  } catch (e) {
    const v = { status: 0, error: String(e.message || e) };
    seen.set(path, v);
    return v;
  }
  const v = { status: r.status, redirect: r.headers.get('location') || null };
  seen.set(path, v);
  return v;
}

(async () => {
  while (queue.length && crawled.size < MAX_PAGES) {
    const page = queue.shift();
    if (crawled.has(page)) continue;
    crawled.add(page);

    const res = await check(page);
    if (res.status !== 200) continue;

    let html;
    try { html = await (await fetch(new URL(page, BASE))).text(); } catch { continue; }

    for (const m of html.matchAll(/<a\b[^>]*\bhref\s*=\s*["']([^"']*)["']/gi)) {
      const target = norm(m[1], page);
      if (!target) continue;
      if (!foundOn.has(target)) foundOn.set(target, new Set());
      foundOn.get(target).add(page);
      if (!crawled.has(target) && !queue.includes(target)) queue.push(target);
    }
    // Forms that GET are navigational too.
    for (const m of html.matchAll(/<form\b[^>]*\bmethod\s*=\s*["']get["'][^>]*\baction\s*=\s*["']([^"']*)["']/gi)) {
      const target = norm(m[1], page);
      if (target && !crawled.has(target) && !queue.includes(target)) queue.push(target);
    }
  }

  const rows = [...foundOn.entries()].map(([url, pages]) => ({
    url, pages: [...pages], ...(seen.get(url) || { status: '?' }),
  }));

  const dead = rows.filter(r => r.status === 404 || r.status === 0 || r.status >= 500);
  const redirected = rows.filter(r => r.status >= 300 && r.status < 400);

  console.log(`crawled ${crawled.size} pages, ${rows.length} distinct internal links\n`);

  if (dead.length) {
    console.log(`DEAD (${dead.length}):`);
    for (const r of dead.sort((a, b) => b.pages.length - a.pages.length)) {
      console.log(`  ${r.status}  ${r.url}`);
      console.log(`        linked from ${r.pages.length}: ${r.pages.slice(0, 5).join(', ')}${r.pages.length > 5 ? ' …' : ''}`);
    }
  } else {
    console.log('DEAD: none');
  }

  if (redirected.length) {
    console.log(`\nREDIRECTED (${redirected.length}) — works, but costs the visitor a round trip:`);
    for (const r of redirected) console.log(`  ${r.status}  ${r.url} -> ${r.redirect}   (from ${r.pages.join(', ')})`);
  }

  process.exitCode = dead.length ? 1 : 0;
})();
