# Africa GATES — Enterprise Fix & Upgrade — Design Spec

- **Date:** 2026-06-05
- **Status:** Draft for review
- **Author:** Engineering (with Claude)
- **Type:** Program design + detailed Phase 0 implementation target

---

## 1. Context

Africa GATES is a PHP 8.1 / Slim 4 / Twig 3 / Eloquent (query-builder) / MySQL
application for cultural-recognition voting, nominations, a profile registry, a
judge-scoring console, an admin console, and a community forum. It is deployed
on **commodity shared hosting (cPanel-style)**.

Three prior audits established the baseline:

1. **Security & correctness audit** — found session fixation across all auth
   flows, a blanket `/api/` CSRF exemption, an unthrottled admin magic-link, a
   client-MIME-trusted upload path, "logged-in ≠ role-checked" authorization,
   and `json_encode|raw` `</script>` breakout risk. Credited a genuinely strong
   vote core, parameterized SQL, and hashed-at-rest secrets.
2. **UI/UX & features audit** — found a *fabricated-content layer* (a fake "AI",
   a fake "Live" ticker, hardcoded marketing stats, invented testimonials/
   spotlight), a heavyweight multi-CDN frontend (jQuery + Alpine + GSAP + Lenis
   + two carousel libs + Plyr + Leaflet + Tippy + WebGL gradient), a phantom
   newsletter endpoint, and a non-accessible vote modal. Credited a mature
   design-token system and excellent navigation IA.
3. **Deep design & build-architecture audit** — found no frontend build
   pipeline (a 174 KB monolithic `main.css`), a **broken cache-tag invalidation**
   (`CacheService` never writes the `tags` column), a **CPI fairness bug**
   (community votes normalized against a *global* max, not per-category despite
   the code comment), and no automated tests. Positioned the design as modern
   but derivative.

### 1.1 Project intent (decisions from brainstorming)

- **End-state:** the complete phased program (harden → modernize → optional
  enterprise hardening), targeting a **hybrid-islands** architecture.
- **Operational status:** **demo / portfolio piece.** No live users; freedom to
  reset/reseed. "Enterprise-grade" means *demonstrating judgment and craft*,
  not protecting live traffic. The fabricated-content layer is therefore the
  single biggest liability and the highest-value fix.
- **What to showcase:** all four dimensions — security & correctness,
  engineering maturity, frontend & design, product judgment.
- **Scope of data reality:** **Nigeria-mainly today; 54-nation pan-African is
  the framed aspiration**, not current fact. All copy and visuals must tell the
  truth about this distinction.

---

## 2. Goals & non-goals

### Goals
- Make the product **true and safe**: remove fabricated content, fix the
  security and correctness defects, and prove the fixes with tests.
- Establish **engineering maturity**: a real build pipeline, CI, tests, and a
  clean deploy story that fits shared hosting.
- Deliver a **best-in-class adaptive UX**: premium motion on capable devices,
  an instant fully-functional experience on constrained devices, accessible
  throughout.
- Showcase **product judgment**: honest Nigeria-now framing with a beautiful,
  truthful 54-nation vision layer, anchored by a signature **Face Mosaic of
  Africa**.

### Non-goals
- No full SPA/SSR re-platform (Approach B rejected — it discards the strong
  server-rendered foundation and risks a half-finished rewrite).
- No production infrastructure that shared hosting cannot run (no Node runtime,
  no Redis, no containers *in production*).
- No live-data migration concerns (demo/portfolio context).

---

## 3. Hard constraints (apply to every phase)

> These constraints MUST be restated at the top of every downstream phase spec.

1. **Production is commodity shared hosting.** No root, no long-running
   processes, no Node runtime, no Redis, no containers in production. PHP via
   MultiPHP; cron available; deploy over SFTP or cPanel Git.
2. **The frontend build runs OFF-host.** Vite/TypeScript compiles in CI (or
   locally); only the **static, hashed `public/assets/dist/` output** is
   uploaded. The host never runs a build step.
3. **Framer Motion is the chosen motion library** and requires a React runtime
   in the browser for animated surfaces. Resolved via **Preact + `preact/compat`**
   to minimize footprint. Reading 1 ("static-first, islands hydrate") is the
   agreed model: build with React/Framer Motion, server-render HTML with Twig,
   hydrate only interactive/animated islands in the browser; static content
   pages ship zero JS.
4. **Truth over theater.** No fabricated data, fake "live" feeds, invented
   people, or features that do not exist. Demo data must be explicitly labeled
   and gated behind `APP_ENV=demo`, never presented as real.
5. **Adaptive, not maximal, UX.** The page must be great with zero JS; motion is
   a progressive enhancement the device opts into.

---

## 4. Target architecture (post–Phase 0): Hybrid islands

```
┌─ BUILD TIME (CI or local; never on host) ─────────────────────────┐
│  Vite + TypeScript                                                │
│   • compiles Preact + Framer Motion islands → hashed static JS/CSS │
│   • emits SRI hashes; one code-split entry per island             │
│  Output: public/assets/dist/*  (flat files)                       │
└────────────────────────────────────────────────────────────────────┘
        │  uploaded via SFTP / cPanel Git
        ▼
┌─ RUNTIME — SHARED HOST (PHP only) ────────────────────────────────┐
│  PHP / Slim / Twig                                                │
│   • server-renders HTML + LIVE DB data + SEO + routing shell       │
│   • injects island mount points + initial state as JSON props      │
│   • serves flat static dist/ assets                                │
│  (optionally fronted by Cloudflare free tier for edge caching)     │
└────────────────────────────────────────────────────────────────────┘
        │  HTML to browser
        ▼
┌─ BROWSER ──────────────────────────────────────────────────────────┐
│  Static content pages (privacy, terms, SEO shell) → ZERO JS        │
│  Interactive/animated islands only → hydrate a tiny Preact +        │
│    Framer Motion runtime (code-split, lazy, capability-gated)      │
└────────────────────────────────────────────────────────────────────┘
```

### 4.1 The island contract (the boundary)
- **Twig renders** initial markup + serializes live state into a
  `<script type="application/json">` props block (real data on first paint,
  SEO-visible, no flash).
- **The island hydrates over** that markup (never renders from scratch), owns
  its interactivity and component-level motion (`AnimatePresence`, `layout`).
- **No global code reaches into an island; no island reaches into global state.**
- **Islands to build (Phase 2):** vote flow, judge ballot, admin tables,
  community threads, and the animated home sections incl. the Face Mosaic.
- **Everything else stays Twig + CSS.**

### 4.2 Deploy pipeline
GitHub Actions: `lint → PHPStan → PHPUnit → Vitest → vite build → Playwright e2e
→ deploy artifact (SFTP / cPanel Git)`. Host receives PHP + pre-built flat
assets only. Docker Compose is **dev/CI parity only**, never a production target.

---

## 5. Motion architecture

The design-system principle is **token → orchestrator → tiers**, expressed in
Framer Motion.

- **Motion tokens** live once: CSS custom properties (durations, easings,
  distances, stagger) for the CSS-only tier, plus a typed `motion.ts` exporting
  shared `transition`s and `variants` for islands. Single source of truth;
  replaces today's magic-number `data-anim-delay` attributes.
- **`<MotionConfig reducedMotion="user">` + `<LazyMotion features={domAnimation}>`**
  wrap every island root: centralizes the reduced-motion branch (replacing 9
  scattered CSS overrides) and ships the small `m` runtime with deferred
  features. Use `m.*`, never `motion.*`.
- **Variants** (`rise`, `stagger`, `fadeIn`) are the shared vocabulary;
  **`useInView` / `useScroll`** replace GSAP ScrollTrigger + SplitType + hand-
  rolled IntersectionObserver.
- **Tool-per-motion-type discipline:**
  - *Ambient* motion (scroll reveals, marquees, the hero gradient) → **CSS**
    (`animation-timeline`, transitions). Zero JS, GPU-cheap, works unhydrated.
  - *Interactive/stateful* motion (modal enter/exit, ballot transitions,
    gestures, layout/FLIP, list reorder) → **Framer Motion**. This is where it
    is genuinely superior and worth the bytes.
- **Capability tiers:**

  | Tier | What | Gate |
  |------|------|------|
  | 0 — Content | Fully visible, no motion | reduced-motion, no-JS, `save-data` |
  | 1 — Micro | CSS transitions/keyframes | always on |
  | 2 — Entrance/scroll | `useInView` / native scroll timeline | JS available |
  | 3 — Delight | WebGL aurora, Spline, mosaic face-cycling | desktop **and** `pointer:fine` **and** motion-OK **and** `deviceMemory ≥ 4` **and** not `save-data`; lazy-imported on viewport |

- **Capability gate runs *before* hydration:** if reduced-motion / `save-data` /
  `deviceMemory < 4`, the island is **not hydrated**; the static Twig HTML is the
  final experience.
- **Visible-first:** content renders without JS; motion can never hide content.

### 5.1 Runtime sizing decisions
- **Preact + `preact/compat`** (~4 KB) instead of React (~45 KB).
- **`LazyMotion` + `domAnimation`** (not `domMax` unless an island needs
  layout/drag).
- **Per-island code splitting**; **lazy hydration** (on-visible / on-idle /
  on-interaction).
- Recommended near-free infra win: **Cloudflare free tier** in front of cPanel
  for edge caching/Brotli/HTTP-3 (no server changes, no constraint violation).

---

## 6. Signature feature: Face Mosaic of Africa

**Concept:** the African continent silhouette tiled with **real approved
Nigerian profile avatars**. Empty tiles use **abstract placeholders** (gradient/
pattern tiles, initials) — never fake stock people. The metaphor is honest and
motivating: "the faces building Africa GATES — live in Nigeria, growing toward
54." The unfilled space *is* the vision, truthfully shown.

**Build (fits shared host):**
- SVG `clip-path`/mask in the shape of Africa; behind it a responsive CSS grid
  of avatar tiles. Only tiles inside the continent show. Pure HTML/CSS — renders
  instantly, meaningful with zero JS, no Leaflet/tile server.
- Avatars from `ProfileService` (approved Nigerian profiles with `avatar_path`),
  served as small **AVIF/WebP** thumbnails, `loading="lazy"`, `decoding="async"`.
- **Tile count adapts to viewport** (dense desktop, sparse mobile) so a low-end
  phone never downloads hundreds of images.

**Motion:**
- *Baseline (zero-JS / save-data / reduced-motion):* static masked mosaic, fully
  formed.
- *Tier 2:* staggered entrance radiating outward from Nigeria
  (`variants` + `staggerChildren`).
- *Tier 3:* ambient face-cycling — occasional tiles flip to another real
  profile; lazy, gated, pausable offscreen.
- *Interactive island:* hover/tap a tile → lifts, shows the profile name, links
  to `/registry/{slug}`; diamond/platinum get a gold tier-ring.

**Accessibility:** the mosaic-as-art gets `role="img"` + descriptive label;
interactive tiles are real focusable links with profile names as accessible
names; the **registry remains the accessible source of truth**.

**Replaces:** the current `bindFaceMosaic` implementation and removes the Leaflet
dependency from this surface.

---

## 7. Integrity / honesty rules (product judgment)

These rules govern the Phase 0 content overhaul and bind all later phases.

1. **No fabricated data.** Remove or replace: the "Atlas AI" canned responder's
   invented people, the fake "Live" ticker, the hardcoded 4.8★/329-reviews
   rating, invented testimonials, and the hardcoded homepage spotlight people.
2. **Real counts or no counts.** Replace hardcoded marketing numbers
   ("1,247 profiles", "24 categories", "seven editions") with real `COUNT(*)`
   queries, or remove the specific figure.
3. **Nigeria-now, 54-dream framing.** Live data is Nigeria-focused; the
   continent is shown as an explicitly-labeled aspiration ("Building toward 54"),
   never populated with invented data.
4. **Demo data is labeled and gated.** Rich sample data is allowed only behind
   `APP_ENV=demo` and must be visibly marked as a demonstration, never presented
   as real production data.
5. **No phantom features.** Either implement `/api/newsletter/subscribe` or
   remove the form. "Atlas AI" is relabeled as a scripted help bot until/unless a
   real backend (Phase 2, using the existing multi-LLM `SpamService` plumbing) is
   built; the "trained / verified daily" claims are removed now.
6. **Honest empty states.** Live, data-driven components render truthful empty
   states; they do not fall back to fictional content.

---

## 8. The four-phase program

Each phase is independently shippable and gets its own future spec + plan, EXCEPT
Phase 0, which is detailed in §9 and is the target of the *next* implementation
plan.

- **Phase 0 — Stabilize ("make it true and safe").** Security P0/P1,
  correctness bugfixes, content-integrity overhaul, repo hygiene, and a PHPUnit
  test harness. **No new build tooling** (pure PHP/Twig + a seeded motion-token
  file). Detailed in §9.
- **Phase 1 — Foundation ("engineering maturity").** Vite + TS + Preact build
  (bundle/minify/hash, SRI, self-host deps, per-route code-split, drop jQuery +
  one carousel lib); GitHub Actions CI; Docker Compose dev parity; a versioned
  migration tool replacing the raw-SQL runner; expanded backend tests +
  Playwright e2e on vote/nominate; convert the **vote flow** as the reference
  island.
  - **Aurora gradient (decision):** **Approach B** — *retain* the existing real
    `stripe-gradient` WebGL mesh canvas; Framer Motion **orchestrates** it only
    (entrance fade/scale via `whileInView`, optional tint, start/stop on
    `useInView`, freeze on `reducedMotion`/`save-data`). Framer Motion does NOT
    render the mesh (it cannot animate WebGL pixels); the shader stays WebGL.
    Keep the static CSS gradient as the Tier-0 fallback. Pin/self-host the
    `stripe-gradient` module (it is currently an unpinned ESM CDN import).
- **Phase 2 — Elevate ("frontend, design & product").** Remaining islands
  (judge ballot, admin tables, community, animated home sections); the full
  motion layer + **Face Mosaic of Africa**; WCAG 2.2 AA pass; performance-equity
  (self-hosted AVIF/responsive imagery, Lighthouse budget in CI); the
  African-rooted **design-originality** pass; optional real "Atlas" assistant.
  - **Illustration aesthetic (decision):** a signature **illustration** layer
    sits alongside ordinary photography — editorial illustration, semi-realistic
    stylized character art, ink-and-wash / sketch linework, vector poster art,
    and graphic-novel/comic-influenced rendering. Both real photos and
    illustrations are welcome as visual aesthetic; the brand-distinctive layer is
    the artwork, which can also fill Face Mosaic tiles in place of photo avatars.
    The honesty rule is about **identities, not imagery**: Phase 0 must not
    introduce fabricated *people/nominees* presented as real data, but decorative
    photography and illustration are fine. Phase 0 leaves honest empty states
    plus a `public/assets/img/editorial/` drop-in convention for the artwork.
- **Phase 3 — Enterprise hardening (optional, documented).** Feasible-on-shared-
  hosting subset: APCu cache if available (else keep DB), Sentry SDK + structured
  logs, CSP nonces finalized, OpenAPI + API versioning, a written threat model.
  Container/IaC ambitions documented as "if migrated to a VPS later," not built.

---

## 9. Phase 0 — detailed design (the next implementation target)

**Theme:** make the product true and safe, proven by tests, with zero new build
tooling. All work is in PHP/Twig + SQL + a single seeded CSS/TS token file.

**Methodology:** test-driven where it touches logic. Before changing
`VoteService` or the CPI engine, write **characterization tests** that pin
current correct behavior, then change under green.

### 9.1 Workstream A — Test harness bootstrap
- Add `phpunit/phpunit` (dev) and a `tests/` tree with a SQLite-backed test
  bootstrap (reuse the existing SQLite path) so tests run with no external DB.
- Characterization tests for `VoteService::castVote` (happy path, wrong OTP,
  expired OTP, attempt cap, duplicate vote, race) and the CPI engine
  (per-nominee scoring + profile rollup + tiering).
- This harness is the safety net for Workstreams B–C.

### 9.2 Workstream B — Security fixes
- **Session regeneration:** call `session_regenerate_id(true)` before writing
  identity in `AuthService::startSession`, admin magic-link consume, and judge
  OTP verify.
- **CSRF scope:** narrow the `/api/` exemption to the OTP-gated endpoints
  (`/api/vote`, `/api/otp/request`); for other state-changing `/api/` routes add
  an `Origin`/`Referer` allowlist check.
- **Magic-link throttle:** rate-limit `AuthController::magicRequest` per IP and
  per email (parity with the judge flow).
- **`JSON_HEX_TAG`:** apply to every `json_encode|raw` site (ballot, dashboard,
  any latent `structured_data`).
- **Login throttle:** add a per-IP throttle to admin password login; close the
  unknown-email timing gap.
- **Minimal RBAC:** introduce a role check that distinguishes `superadmin` from
  `editor`, enforced in middleware or a shared guard — or, if all admins are
  intended super-equivalent for the demo, document that decision explicitly.

### 9.3 Workstream C — Correctness fixes
- **CPI per-category normalization:** normalize the community vote component
  against the **per-category** max vote count, not the global max. Update the
  code to match (and verify) the documented intent.
- **Cache-tag invalidation:** make `CacheService` actually work — either write
  the `tags` column on `remember()`/`updateOrInsert` and match on it, or switch
  invalidation to a key-prefix scheme. Verify `forgetByTag('leaderboard')` and
  `forgetByTag('registry')` purge the right rows.
- **`voter_country` mislabel:** stop storing the nominee's country in the vote's
  `voter_country`; either capture the real voter country or rename/drop the
  column. Decide and document. **Note:** "capture the real voter country" implies
  a schema + vote-time data-capture change (larger blast radius); rename/drop is
  the smaller change. Prefer the smaller change unless there is a concrete need.

### 9.4 Workstream D — Content-integrity overhaul
Implements §7. Concretely:
- Replace the homepage fake "Live" ticker, hardcoded rating, invented
  testimonials, and hardcoded spotlight with **real-data-driven** components fed
  by existing services (or honest empty states).
- Replace hardcoded counts with real queries or remove them.
- Relabel "Atlas AI" as a scripted help bot; remove "trained / verified daily."
- Implement or remove `/api/newsletter/subscribe`.
- Introduce `APP_ENV=demo` gating + visible "demonstration data" labeling for the
  rich sample seed.
- Apply the Nigeria-now / 54-dream framing to all relevant copy.

### 9.5 Workstream E — Repo hygiene
- Delete the duplicate app copy
  (`africa-gates-voting-and-nomination-...QLdKH/`) and the 6.5 MB `.zip` from the
  tree; add patterns to `.gitignore`.
- Seed the **motion-token file** (CSS custom properties + a `motion.ts` stub) so
  Phase 1/2 do not rebuild it. No React yet.

### 9.5.1 In-scope Phase 0 stretch items (from the §10 table)
The following lower-priority items are **in scope for the Phase 0 plan** because
they are cheap and touch the same files: the `X-XSS-Protection` header conflict
(P3), removing obviously dead controller work feeding hardcoded views (P3, only
where it overlaps Workstream D), and reconciling the marketing numbers (P3, part
of Workstream D). **Deferred out of Phase 0:** upload byte-sniffing (Phase 1),
sanitizing admin rich-text (Phase 1), and CSP nonces (Phase 3).

### 9.6 Phase 0 success criteria
- All new PHPUnit tests pass on a **local run** (CI does not exist until Phase 1;
  Phase 0 is intentionally pre-CI).
- Manual verification: all three logins rotate the session id; magic-link is
  throttled; `/api/` CSRF scope behaves; leaderboard/registry caches purge after
  a vote/registration; CPI uses per-category normalization.
- No fabricated "live" data remains on any page; demo data is labeled and gated.
- Duplicate copy + zip removed.

---

## 10. Consolidated priority order (mapped to phases)

| Pri | Item | Phase |
|-----|------|-------|
| P0 | Session regeneration on all logins | 0 (B) |
| P0 | Content-integrity overhaul (fabricated data, Atlas, counts) | 0 (D) |
| P0 | Remove duplicate app copy + zip | 0 (E) |
| P1 | CPI per-category normalization | 0 (C) |
| P1 | CacheService tag invalidation | 0 (C) |
| P1 | Narrow `/api/` CSRF + Origin check | 0 (B) |
| P1 | Rate-limit admin magic-link | 0 (B) |
| P1 | `JSON_HEX_TAG` on `json_encode\|raw` | 0 (B) |
| P1 | Accessible vote modal | 2 (island rebuild) |
| P1 | Trim & secure dependency stack | 1 |
| P1 | Minimal RBAC | 0 (B) |
| P2 | Upload byte-sniffing | 0/1 |
| P2 | Per-IP login throttle + enum timing | 0 (B) |
| P2 | Sanitize admin rich-text | 1 |
| P2 | Frontend build pipeline | 1 |
| P2 | Self-host & optimize imagery | 2 |
| P2 | Wire/remove newsletter endpoint | 0 (D) |
| P2 | Tighten CSP toward nonces | 3 |
| P3 | Automated tests (broaden) | 1 |
| P3 | Versioned migration ledger | 1 |
| P3 | Reconcile marketing numbers; persist announce dismissal | 0/2 |
| P3 | `X-XSS-Protection` header conflict | 0 |
| P3 | Remove dead work | 0/2 |
| P3 | Design-originality (African-rooted identity) | 2 |

---

## 11. Testing strategy
- **Phase 0:** PHPUnit, SQLite-backed, characterization-first on `VoteService`
  and CPI; assertion tests on the new security/correctness behavior.
- **Phase 1:** PHPStan static analysis; Vitest for island logic; Playwright e2e
  on vote/nominate; all gated in GitHub Actions.
- **Phase 2:** axe-core accessibility checks; Lighthouse CI budget tuned for
  low-end mobile.

## 12. Risks & open questions
- **RBAC scope:** confirm whether `editor` should be restricted, or all admins
  are super-equivalent for the demo. (Decided in Phase 0 B; document either way.)
- **`voter_country`:** capture real voter country vs. rename/drop — decide in
  Phase 0 C.
- **Cloudflare:** optional; not required for correctness. Treat as a Phase 1
  recommendation, not a hard dependency.
- **Demo data realism:** the Face Mosaic and registry need enough *real* Nigerian
  seed profiles (with avatars) to look credible; otherwise placeholders dominate.
  Decide acceptable seed volume in Phase 2.

## 13. Scope note for the implementation planner
The **next implementation plan covers Phase 0 only** (§9). Phases 1–3 are
roadmap context and will each receive their own spec → plan cycle. Do not plan
Phases 1–3 in the Phase 0 plan.
