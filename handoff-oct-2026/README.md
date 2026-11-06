# Prompt for Claude Code: October 2026 release

Paste this into Claude Code from the repo root, with the `handoff-oct-2026/` folder copied into the repo:

```
Read handoff-oct-2026/README.md and do parts A, B and C in order. One PR per part.
Follow every MUST exactly. Stop and ask if anything in the repo contradicts this file.
```

---

## Part A: seed the "Celebrate Nigeria" Independence Day challenge (deadline: live by 1 Oct 2026 00:00 WAT)

**The challenge (fixed; do not change copy or numbers):**

| Field | Value |
|---|---|
| Slug | `celebrate-nigeria-2026` → `/challenges/celebrate-nigeria-2026` |
| Title / kicker | Celebrate Nigeria / Independence Day challenge |
| Rule | Nominate **10 different people** for the **Alimosho Awards** (current edition: Choral · Business · Impact). |
| Qualify | All 10 nominees **verified**: full details given, nominee confirmed by SMS/WhatsApp, approved by the team. |
| Winners | **First 11** to qualify (`mode=first`, `cap=11`) |
| Prize | **₦6,000 each** (`cash_each`, 6000, NGN), paid within 7 days to a wallet or bank account in the winner's own name |
| Entry | Signed in, **verified phone**, Nigeria, 18+ |
| Window | 1 Oct 2026 00:00 to 15 Oct 2026 23:59:59, Africa/Lagos |
| Art | `challenge/assets/alimosho-celebrates-nigeria.png` → `public/assets/img/challenges/` |

**Steps:**
1. **MUST check first:** do `gates_challenges`, `gates_challenge_scopes`, `gates_challenge_entries`, `gates_challenge_events` and `gates_promos` exist, plus the nomination columns in `PROMPT-CHALLENGES.md` §2?
   - If not, build `PROMPT-CHALLENGES.md` §6 steps 1–5 first. That means the migrations in **both** schema files, `ChallengeService`, nominee confirmation and the `/challenges/{slug}` page.
   - The seed is useless without the rules engine. Do not ship the seed alone.
2. Copy the art to `public/assets/img/challenges/alimosho-celebrates-nigeria.png`. Resize it to max 1200px wide and keep the PNG with transparency. Also make a WebP alongside it.
   - It MUST appear as the challenge art: on the challenge page hero, the `/challenges` card and every promo banner (it's the seed's `art_url`). Alt text: "Àlímọ̀ṣọ́ celebrates Nigeria".
   - Also copy `challenge/assets/okun-alimosho-logo.png` to `public/assets/img/hosts/`. Show it as the host logo beside "Hosted by Okun Alimosho" on the challenge page and the Alimosho Awards pages. Alt text: "Okun Alimosho".
3. Move `challenge/seed/2026_10_01_celebrate_nigeria.php` into the repo's seed folder.
   - Adapt **only** table and column names to what actually exists. Keep the logic, the copy and the idempotency.
   - It refuses to run if Alimosho Awards or an open edition is missing. That is intended: create the edition through admin, never inside the seed.
4. Run it on dev SQLite, then again to prove it's idempotent (the row counts must not change). Then on prod MySQL.
5. **Acceptance (all MUST pass, with screenshots at 390 and 1440):**
   - `/challenges/celebrate-nigeria-2026` renders the Open state with: Prize ₦6,000 each · Winners First 11 · To qualify 10 verified nominees · Ends 15 Oct.
   - `/challenges` lists it first.
   - Banners show on Nominate hub, Home, Account (signed in only), Vote hub, and the Alimosho Awards pages, and on no other award.
   - The nomination form on Alimosho shows "Counts toward Celebrate Nigeria · n/10" for a joined member.
   - **Tests:**
     - The 11th qualifier wins and the 12th gets "Challenge full".
     - Self-nomination is rejected.
     - The same nominee twice counts once.
     - A nomination for another award doesn't count.
     - After 15 Oct 23:59:59 WAT the challenge reads Ended.
   - Times display in WAT.
   - Winners are shown publicly as first name + initial only.

## Part B: entrance loader v5

Files: `loader/loader.css`, `loader/gates-loader-edits.twig`, with `loader/Loader Preview.dc.html` as the visual spec.
1. Replace `public/assets/css/components/loader.css` with `loader/loader.css` verbatim.
2. In `templates/layout/gates.twig`:
   - **EDIT 1:** replace the `#agLoader` block. Paste the two existing continent `d` values where marked. Don't redraw them.
   - **EDIT 2:** replace the exit script.
   - Keep the CSP nonce.
3. **Spec MUST match:**
   - Brand-green disc `#006432` on `#fbfbfa`, continent `#7fc87c`, "Africa" in Playfair 28px ink, "GATES" in DM Sans 11.5px, tracked .28em, green, and a 72×2px hairline.
   - **Total 1.36s:** disc 0–.36 · continent .16–.50 · Africa .28–.62 · GATES .34–.68 · bar .20–.92 · lift-out 1.04–1.28 · panel fade 1.12–1.36.
   - Shows only on the first page view of a session (keep the existing `ag-loading` gate).
   - Hard cap 2.2s.
   - Reduced motion: no loader.
   - bfcache restore: removed instantly.
4. **Acceptance:** record it at 60fps. No layout shift when it's removed. Lighthouse LCP doesn't get worse by more than 50ms.

## Part C: dynamic favicon

Files: `favicon/*`. The mark is the brand disc + continent, built from the exact loader paths (not redrawn).
1. Copy the files:
   - `favicon.svg`, `favicon.ico` (16/32/48), `apple-touch-icon.png` (180, opaque) → `public/`
   - `favicon-192.png` and `favicon-512.png` → `public/assets/icons/` (wire them into the web manifest)
   - `favicon.js` → `public/assets/js/favicon.js`
2. Replace the icon links in `gates.twig` `<head>` exactly:
   ```html
   <link rel="icon" id="agFavicon" type="image/svg+xml" href="{{ asset('/favicon.svg') }}">
   <link rel="alternate icon" href="{{ asset('/favicon.ico') }}" sizes="16x16 32x32 48x48">
   <link rel="apple-touch-icon" href="{{ asset('/apple-touch-icon.png') }}">
   ```
   Load `favicon.js` with `defer` + nonce, after `gee.js`.
3. **States** (only these; nothing else may touch the favicon):

   | State | Trigger | Look |
   |---|---|---|
   | idle | default | The SVG itself; it inverts in OS dark mode with no JS |
   | busy | before a vote, payment or nomination request: `agFavicon.set('busy')` | A light-green ring sweeping, 0.9s/turn |
   | error | request failed: `set('error')`, cleared on the next success or after 30s | A red `#b42318` dot, top-right |
   | live | `<body data-favicon="live">` on an open vote page or a live stream | A red `#e0245e` dot pulsing ~0.6Hz |
   | unread | `agFavicon.unread(n)` from the notification poll and Gee | A green `#237b22` badge, 1–9 or "9+" |

   - Priority order: error > live > unread > busy > idle.
   - No animation while `document.hidden`. Reduced motion gives static badges.
   - Title prefix `(n) ` only when the tab is hidden **and** the count went up. Remove it on return.
4. **Acceptance:** screenshots of each state in the Chrome, Safari and Firefox tab strips, in light and dark. Never flashes faster than 2Hz.

---

**MUST NOT:** invent award editions or categories in seeds · show prize money anywhere except the challenge surfaces · mention AI · change the challenge copy · add colours outside the tokens.
