# Handoff — Africa GATES

**Written:** 21 August 2026 · **Last updated:** 25 August 2026 (§3 item 1, §12–§14; index §17)
**Branch:** `claude/ai-assistance-judges-features-1ka4oz` (pushed, green)
**Suite:** ~4,580 tests passing — `vendor/bin/phpunit --no-coverage` (~140s)
**Live site:** `afg.afrovanguard.org.ng`
**Not merged to master yet.** No PR opened — the user hasn't asked for one.

Read this before touching anything. Several items below are things that were
already tried and found to be wrong; repeating them costs a day.

---

## 1. Environment — read first, it will save you an hour

| Thing | State |
|---|---|
| `composer install` | **Works, but takes ~15 min and appears to hang** (no output). Don't kill it. `vendor/` is gitignored except `.htaccess`. |
| Measuring a phone width | **Headless Chromium clamps its window to 500 CSS px.** `--window-size=390,900 --screenshot` therefore lays the page out at **500** and crops the image to 390 — which looks exactly like a horizontal overflow and is not one. It cost a wrong entry in §13 before it was caught. To measure a real narrow viewport, load a same-origin page that iframes the target at `width=390` and read `documentElement.scrollWidth` inside the frame; an iframe gets a genuine viewport at any width. Screenshots below 500px are fine for *reading* a layout and worthless for *judging* its width. |
| Running the app locally **poisons the suite twice** | A dev `.env` breaks ~14 AI tests (below), and running the dev server leaves `var/data/.gates-maintenance.lock` and `.maintenance_tick` behind, which makes `MaintenanceTest::test_tick_is_gated_by_the_setting_then_runs` fail with `skipped` — **in the full suite only**, because it passes in isolation. Both are invisible: the failures name code you did not touch. Before `phpunit`: move `.env` aside AND `rm -f var/data/.gates-maintenance.lock var/data/.maintenance_tick`. |
| Local DB | SQLite. `php database/setup-sqlite.php` then `php bin/console db:migrate`. `.env` needs `DB_DRIVER=sqlite`, `APP_ENV=development`. |
| Dev server | `cd public && php -S 127.0.0.1:PORT router.php` — **start it with `run_in_background: true`**, not `&`. A foreground `&` gets reaped and every curl returns `000`. |
| `~/.claude/plugins/` | **Empty.** The org's legal (`privacy-legal`, `ai-governance-legal`, `cocounsel-legal`, `regulatory-legal`) and SEO (`searchfit-seo`) plugins are enabled on the account but do NOT sync into a container created before they were added. A **new session** gets them. |
| GitHub scope | `appadmin-oss/*` only. `add_repo` refuses cross-tier adds, so `attendee-labs/attendee` is unreachable from a session that already has `appadmin-oss` repos. |
| The user has **no SSH** | Everything operational must be an HTTPS endpoint. See §5. |

---

## 2. What is done

Nine audit severities fixed (the audit is in the session history, not a file), plus:

- **Live countdown for email** — `CountdownGif` hand-assembles an animated GIF89a (PHP cannot write one; `imagegif()` is single-frame). `CountdownController` serves `/email/countdown.gif`, `no-store`.
- **Nominee broadcast** — `NomineeBroadcast` service, `nominees:broadcast` command (dry-run default), and `/__setup/broadcast` for the no-SSH path. Opt-out (`EmailOptOut`) with RFC 8058 one-click.
- **Event referrals** — `ReferralService` + `EventCodeResolver`. 10 paid referrals unlock 10% of what they paid, retroactive.
- **59 near-miss URL 301s** — `$aliases` table in `src/routes.php`.
- **Inbox optimisation** — fluid-hybrid wrapper, dark mode, image-blocked fallback.
- **Timezone** — `DisplayTime`, admin-settable, storage stays UTC.
- **Ticket fixes** — wrong domain, and missing artwork on Cloudinary deployments.
- **SEO** — a real content sitemap (index + 9 sections), canonicals that survive
  pagination and referral links, a crawlable favicon set, and BreadcrumbList /
  Person / FAQPage markup on the pages that show a trail. See §11.
- **Merged** `claude/clone-attendee-repo-7p9x53` twice — through `590429c`. Interview
  bot, voice, guard, three migrations, GCP runbook. **That workstream has its own
  handoff at `docs/INTERVIEW-BOT-HANDOFF.md` — read it before touching anything under
  `Interview*`.** Its open items are summarised in §10 below but not restated in full.

---

## 3. Outstanding work, in the order I'd do it

The user's list, unstarted. Each is a real feature, not a tweak.

1. ~~**Live countdown in the last hours of the vote (on-site).**~~ **Done — 25 Aug 2026.**
   `partials/vote-countdown.twig` plus the ticker in `main.js`. Two things about it
   worth not rediscovering: the remaining seconds come from the **server** and the
   browser only decrements them, because parsing `closes_at` and subtracting
   `Date.now()` makes the number a function of the visitor's system clock — a phone
   an hour or a year out (ordinary on cheap Android after a flat battery) then shows
   a confident wrong answer about when its vote stops counting. And it **escalates**:
   one quiet line until `closing_soon` (48h, drawn server-side so it cannot disagree
   with the phase gate), a live clock inside it.

   The hub at `/vote` had it and the nominee ballot did not — which is the page a
   "closing soon" share link lands on, and the only one where the deadline changes
   what somebody does in the next minute. It has it now, via `variant: 'dark'` on the
   same partial rather than a second copy: the digits are `aria-hidden` and the
   group's accessible name is the absolute deadline, and that reasoning is not
   obvious from reading the markup, so a copy would lose it within one edit.
   `VoteCountdownTest` (12) holds both halves.
2. **Leaderboard v2 design + roll the new logo site-wide.** One visual pass.
   `ux-review` skill is the reference for thresholds. **Do not roll the logo until
   the user confirms the green** — see §4.
3. **Fundraising for events, conversion-optimised.** Design was brainstormed in
   session: peer-to-peer campaigns per nominee (reuse the `ReferralService` shape),
   laddered asks ("₦25,000 sends a student"), thermometer with a real deadline,
   shareable artefact per donation via the existing `FlierService`.
4. ~~**Site-wide SEO.**~~ **Done** — see §11. What is left is only what needs a
   plugin or a design pass: `searchfit-seo` for a keyword/competitor audit, and
   Core Web Vitals measured on a real device against production (the resource
   hints and the LCP preload are already tuned; nothing here has been profiled).
5. **Legal pages + tracking.** Blocked on plugins; see §4 and §8.
6. ~~**Move the broadcast into the admin UI, with editable content.**~~ **Done —
   22 Aug 2026.** `/admin/campaigns`: structured blocks, preview, test-send,
   approve, the dry-run counts above the send button, batching with auto-continue.
   `/__setup/broadcast` stays as the no-SSH escape hatch for the fixed template —
   §5 still points at it. Built to §6's design; what changed from it is noted there.


---

## 4. Open decisions — do not guess these

- ~~**The logo green.**~~ **Settled.** The committed artwork was already in the repo at
  `africa-gates-logo.svg` (1.4MB SVG wrapping four embedded PNGs — two colour layers and
  two alpha masks). Reconstructed by compositing colour+mask, trimmed of its strapline
  band, and now the source of `logo-africa-gates.png`, `logo-mark.png` and
  `og-default.png`. The SVG moved to `public/assets/img/logo-africa-gates.svg`; the
  duplicate at `public/africa-gates-logo.svg` is gone. **No hand-drawn asset remains.**
- **Referral payout.** Earnings accrue to `gates_referral_credits.paid_out_at`
  (nullable = owed). There is **no bank-details capture and no payout flow**. The
  user wants "credits converted to cash, withdrawable, and we retain the ability
  to update it" — that means an admin-editable rate/threshold (currently
  `ReferralService::RATE_BPS` / `THRESHOLD` constants) and a withdrawal request
  flow. Needs a decision on how money actually leaves.
- **No admin view of referral liability.** Nobody can see who is owed what
  without querying the table. A Finance-page panel is the obvious home.

---

## 5. The no-SSH runbook (give this to the user, not to yourself)

All token-gated by `SETUP_TOKEN` in `.env`; wrong/missing token returns **404**, not 403.

```
/__setup/migrate?token=…      creates tables, self-continues, safe to repeat
/__setup/assets?token=…       rebuilds the CSS bundle
/__setup/broadcast?token=…    DRY RUN — shows the plan, sends nothing
   &test=you@example.com      one real email to yourself, [TEST] subject, not logged
   &send=1                    sends, in batches of 25, auto-continuing
   &csv=1                     downloads nominees with no email / duplicate names
```

**For a campaign whose copy you want to change, use `/admin/campaigns` instead** — same
recipients, same suppression list, same send log, but the words live in a row rather than a
Twig file. The endpoint above remains for the fixed `final-hours` template and for a
deployment where the admin login itself is the problem.

Required in `.env`: `APP_URL=https://afg.afrovanguard.org.ng` (every email link is
absolute — unset means broken links), `APP_KEY` (signs unsubscribe tokens).
**Tell them to delete `SETUP_TOKEN` when done** — it also gates the migrator.

---

## 6. Broadcast in the admin UI — built 22 Aug 2026

> **This section was the specification, and it was followed.** What is below is kept
> because the reasoning is still the reason the thing has the shape it has. Four notes on
> what the build added or changed:
>
> - **`EmailInboxGuard` is a service, not just a CI step.** §6 asks to "run
>   `EmailInboxCompatTest` against the rendered output in CI". Failing the SAVE is strictly
>   better: CI never sees the row, and the person who can fix a too-long campaign is the
>   person who just wrote it. The twelve template-level properties now also apply to the new
>   skeleton by inheritance (`CampaignInboxCompatTest`) rather than being copied.
> - **Links are chosen from a list, never typed.** Not in §6, and it matters: `vote_url`
>   differs per recipient, so it could not be typed anyway — and free-text URLs in a
>   template a non-developer edits is an open redirect with a mailing list attached.
> - **Placeholders have fallbacks** — `{first_name|Friend}`. A nominee's first name and
>   category are genuinely blank sometimes; the old fixed template handled it with Twig
>   conditionals, which is exactly what an operator cannot write.
> - **The plain-text part is generated from the blocks.** §6 does not mention it. It had to
>   be: the old one was hand-written prose, which would keep sending the OLD copy after an
>   edit, and a text part that contradicts the HTML part is worse than a clumsy one.
>
> **Two-person approval** (item 6 below) is half-built: approval is a separate recorded act
> by a named person and any edit clears it, but there is no second-approver model. That
> needs a decision about who counts as the second person.

## 6a. The original specification

**Why it matters:** editing a campaign currently means editing a Twig file and
redeploying. The user has no SSH, so "change one line of copy" is a full deploy
cycle. And `/__setup/broadcast` sits beside the database migrator — the wrong
neighbourhood for something a comms person uses.

**What already exists and must be reused, not rebuilt:**

- `NomineeBroadcast` — recipient resolution, rendering, send log. It is already
  the single definition of *who gets mail*, shared by the console command and the
  setup endpoint. A third caller must go through it too, or the three will drift
  into mailing somebody twice.
- `EmailOptOut` — suppression, HMAC unsubscribe tokens, one-click headers.
- `gates_broadcast_log` — `UNIQUE(campaign, email_hash)` is what makes a resumed
  or re-run send safe. Do not bypass it.
- `OtpService::sendRawHtml` — the only send path that does not wrap the body in
  `brandWrap` (which would nest `<html>` inside `<html>`).
- `EmailInboxCompatTest` — 12 assertions that hold the inbox properties. **A
  WYSIWYG editor is the fastest way to break every one of them.** See below.

**The design constraint nobody expects:** an editable campaign cannot be free-form
HTML. Everything in §7 about email — fluid-hybrid wrapper, MSO conditionals, no
`data:` URIs, styled alt text, `role="presentation"` tables, no CSP nonce — is
invisible to whoever is typing, and a rich-text editor will emit `<div>`s and
inline styles that Outlook drops on the floor. Ship **structured blocks, not a
document**: the template keeps its table skeleton and the editor edits *fields*
(headline, standfirst, the two asks, CTA label, closing note) plus an ordered list
of typed blocks. Then run `EmailInboxCompatTest` against the rendered output in CI
so a bad edit fails before it is sent, not after.

**Rough shape:**

1. `gates_email_campaigns` — id, slug, subject, preheader, `blocks_json`,
   status (draft/approved/sent), timestamps, `updated_by`. Version the rows or
   keep an audit trail; a campaign that went to 800 people needs to be
   reconstructable.
2. Admin screens under `/admin/campaigns` — matching the console's existing
   conventions, not a new design language.
3. **Preview and test-send in the editor**, reusing the `&test=` path already
   built: it borrows a real recipient's data so the personalisation, countdown
   cycle and vote link are genuine, and it writes nothing to the send log.
4. **The dry-run table before the send button**, same counts as today
   (resolved / unsubscribed / already-sent / ambiguous / unreachable). Nobody
   should be able to reach "send" without having seen who it reaches.
5. Keep the batching and the auto-continue. A few thousand SMTP calls at a
   quarter-second each is far past any shared host's `max_execution_time`.
6. **Two-person approval before a live send** is worth considering: the blast
   radius is every nominee's inbox and there is no undo.

**Optimisation to carry over, not rediscover:** the rendered email is currently
16,702 bytes against Gmail's ~102KB clipping point. An editor that inlines images
as `data:` URIs, or lets somebody paste Word markup, will blow through that — and
what Gmail clips off a campaign is the footer, where the unsubscribe link lives.
Cap the rendered size in validation and fail the save, loudly.

---

## 7. Traps — each of these was tried and is wrong

- **Do NOT set `APP_TIMEZONE=Africa/Lagos`.** It pins the process clock, so new
  writes are WAT-local into columns whose existing rows are UTC, with no offset
  stored to tell them apart. Every deadline comparison goes an hour out,
  permanently and invisibly. Use `DisplayTime` at the edge. `Clock`'s docblock
  says the same thing.
- **Email templates must not carry a CSP nonce.** `CspTest` enforces the inverse
  rule for `templates/emails/` — web templates must have one, mail templates must
  have none. Symmetric on purpose so the exemption can't become a hole.
- **No `data:` URI images in email.** Gmail, Outlook desktop, Outlook.com and
  Yahoo all refuse them. The original logo was 20,848 base64 bytes AND corrupt.
- **`$_SESSION['user_id']`, not `member_id`.** `member_id` exists in this codebase
  as a template/audit field. Reading the wrong one silently means "never signed
  in" — which would make the referral self-referral check pass for everybody.
- **The vote URL is `/vote/{PROGRAMME-slug}/{Slug::idSegment($id,$name)}`.** The
  route param is named `{program}` and reads like the category. Passing the
  category slug and a bare id is a 404.
- **`/judge` is the judge portal**, a route group. Never alias it to `/judges`.
- **`gates_nominees` has no email column.** Addresses live on
  `gates_nominations.nominee_email`, are optional, and there is no FK. Resolution
  is best-effort: `profile_id` → `gates_profiles.email` first, then name+cycle
  match. **Two nominations sharing a name in one cycle is skipped, never guessed.**
- **Referral credit hangs off the single winning `pending→confirmed` transition**
  in `EventTicketService::confirm()`, with `UNIQUE(registration_id)` behind it.
  Confirmation is reachable three ways (callback, webhook, reconciler) and they race.
- **`TicketPdf` fetches remote artwork once into `var/cache/ticket-art`**, https
  only, configured media hosts only. Don't widen that allowlist — it's the one
  place a DB row can cause an outbound request.

---

## 8. Tracking — the one request that cannot be built as asked

The user wants "visitors tracked with names, email, location." **An anonymous
visitor has no name or email to read.** Anything that claims otherwise is
third-party data-broker matching, which under Nigeria's NDPA 2023 needs a lawful
basis they don't have for covert collection, and is a straight GDPR breach for any
EU/UK visitor. A cookie banner over covert identity harvesting is evidence, not
compliance.

What to build instead, which was accepted in principle: consent-gated analytics
(counts, pages, referrers, approximate country from IP then discard the IP),
identity attached only where someone gave it (signed-in members, form submissions,
ticket buyers), a real banner with a working reject, and a policy that describes
it accurately.

Also correct the premise if it comes up again: **the OpenAI API has not trained on
customer data by default since March 2023** — that's opt-in; consumer ChatGPT is
different. Check what this codebase actually calls before writing a disclosure. An
inaccurate privacy notice is its own liability.

---

## 9. Conventions worth matching

Read the surrounding code first — this codebase has strong, deliberate patterns
and its docblocks explain *why*, not *what*. Specifically:

- Console commands are **dry-run unless `--commit`** (`registry:backfill`, `privacy:purge`).
- Migrations are idempotent, driver-aware (MySQL + SQLite), and **never `exit`/`die`**.
- `OptionalColumn::filter()` guards inserts against columns a deployment lacks.
- Settings live in `gates_settings` (`key_name`/`value`), read via a private
  static `setting()` helper per service.
- Comments explain the failure that motivated the code. Match that register —
  terse "what" comments read as foreign here.

---

## 10. The interview-bot workstream (merged in, own handoff)

Full detail in **`docs/INTERVIEW-BOT-HANDOFF.md`**. What a reader of *this* file needs
to know, because two items reach beyond that workstream:

> **Update, 22 Aug 2026.** The P0 below is **done**, along with items 6a, 6c and the real
> form of 6e, and the transcript-cursor risk in P1 is fixed rather than merely documented.
> `attendee-labs/attendee` turned out to be readable after all — `add_repo` refuses
> cross-tier *pushes*, not anonymous clones — so several things filed as "only a live run
> can settle" were settled against the source at `77e990ed`. Item 6b should **not** be
> built: `/admit_from_waiting_room` is Zoom-only and these interviews are on Google Meet.
> Full detail in §0 of `docs/INTERVIEW-BOT-HANDOFF.md`. What is still genuinely blocked on
> a live instance: the smoke test, and everything downstream of a real judging round.

**P0 — the docs state a false claim, in three places.** *(Done — 22 Aug 2026.)* `InterviewBot`'s class docblock,
`docs/INTERVIEW-BOT.md` §"What `auto` honestly is on this host", and the commit history
all say real-time conversational interviewing is impossible on this host. **It is not.**
Attendee's `voice_agent_settings.url` loads a page in an *Attendee-managed* container and
streams its audio into the meeting — "no backend worker required", per its own docs. So
the cPanel host never needs to be in the audio path.

Correct those three places **even if nothing is built**. But do not simply switch it on:
that path bypasses `InterviewGuard` entirely, because the guard sits in the PHP path
between `AiGateway` and TTS while a realtime agent speaks from the browser. Trading every
grounding, verdict, promise and protected-characteristic check for latency — in the
feature that decides an award — is a blocker, not a footnote.

**P1 — nothing has ever run against a live Attendee instance.** No instance exists. The
tests deliberately do not mock cURL, so the first real proof is the smoke test in
`docs/ATTENDEE-ON-GOOGLE-CLOUD.md` §8. Highest risk is `AttendeeBot::transcript()`'s
cursor: `bot_cursor` is an **ordinal position in the response array**, not a provider ID,
so if `/transcript` ever paginates or reorders, lines are silently skipped or repeated.

**Reaches outside the workstream — worth pairing with work already listed here:**

- ~~**Privacy erasure does not reach the bot data**~~ **(their item 6c — done, 22 Aug 2026.)**
  `PrivacyEraseUserCommand` now scrubs `gates_interview_guard_log` in place (the refusal
  survives for `InterviewGuard::tally()`, the quoted sentence goes) and calls Attendee's
  `/delete_data` per bot, reporting each result without ever failing the run. Members are
  not joined to interviews directly, so it resolves
  email → `gates_profiles` → `gates_nominees.profile_id` → `gates_interviews.nominee_id`,
  failing closed. **§8's tracking/consent work still has to extend the same command** when
  analytics land — that part of the advice stands.
- **`ATTENDEE_BASE_URL` blank bills per meeting-hour**, and `ElevenLabs` shares the
  questionnaire's quota — a season of interviews can exhaust it and the only symptom is a
  play button that stops working.
- **Recommended first-season posture: `voice_mode=off` everywhere.** Transcript quality is
  the whole benefit and costs no governance argument; the voice is the part that invites
  an appeal.

Their landmines section is worth reading in full. The one most likely to be undone by
accident: **do not remove `InterviewVoice::claimTurn()`.** It is one UPDATE doing the turn
claim, the minimum gap and the utterance cap together, and `poll()` has two uncoordinated
callers (cron sweep + webhook) that previously spoke over each other.

---

## 11. SEO — what was built, and the four bugs behind it

Every one of these rendered a perfect page, returned 200, and told a crawler
something false. That is why they survived: nothing in a browser shows any of them.

**The sitemap listed no content.** `/sitemap.xml` was a fifteen-path array in
`routes.php` — `/`, `/awards`, `/vote`, all of which a crawler finds from the home
page anyway. Not one nominee ballot, registry profile, help answer, event or post was
in it, and a nominee ballot is reachable only through a paginated category listing, so
the deeper a nominee sat in a cycle the likelier a crawler never arrived. Now
`SitemapService` + a sitemap **index** at `/sitemap.xml` with nine sections at
`/sitemap-{section}.xml` (paged `-2`, `-3` past 5,000 URLs). Read that class's docblock
before changing it; three things in it are deliberate:

- **`lastmod` is true or absent.** The old file stamped `date('Y-m-d')` on every row.
  A date that always says "today" carries no information and Google ignores a
  `lastmod` it does not trust, so a page with no date column now omits the element.
  The help section is the exception and the nicest one: its corpus is a PHP file, so
  its `lastmod` is that file's mtime, which is exactly right.
- **Filters mirror the controllers exactly** — public statuses, `notMerged`,
  `status='approved'` for profiles, `is_active`, `is_published`. A merged nominee's
  page 302s and an unapproved profile 404s; either in a sitemap is a quality signal
  against the whole file. `test_a_merged_nominee_is_not_listed` and its siblings exist
  because that is the failure that arrives quietly a year later.
- **Every section is `hasTable`-guarded and try/caught.** One missing table costs one
  section, not the sitemap — a deployment between a `git pull` and a migration must
  still serve one, because a fetch error makes Search Console distrust the file.

**Pagination collapsed and referral links became canonical.** The layout built its
canonical from the path alone. `/registry?page=4` therefore declared `/registry`
canonical — telling Google eighteen profiles it had just fetched were a copy of a page
it already had. And the referral feature hands out `?ref=AGXXXX` links *designed to be
shared*: each self-canonicalised to a distinct URL, so one page could accumulate as
many indexable variants as it has referrers, splitting its own signals and putting
somebody's referral code in a search result. `Support\Canonical` now sorts parameters
into three classes (indexable / search / strip) and the layout reads `canonical_path`.
`?q=` gets `noindex, follow`; a facet is canonicalised away but stays indexable —
`noindex` on a filtered listing somebody already links to throws away a page that was
earning.

**The favicon was a data URI of the letter "G".** Google needs a favicon it can
*crawl* — a URL it can fetch and re-fetch — so every mobile result rendered with the
generic globe, and the mark was the placeholder the real artwork replaced. Now
`/favicon.ico` (16/32/48), `icon-192`, `icon-512`, `apple-touch-icon` and a
`site.webmanifest`, all generated from the committed `africa-silhouette.svg` in the
logo's own green (`#006634`). The full lockup cannot be the favicon: at 16px the
"Africa G.A.T.E.S." wordmark inside it is four grey pixels.

**Nine pages showed a breadcrumb and marked up none of it.** The trail existed
visually on the ballot, the programme page, the registry profile, the judge page and
both help pages; only the `/vote` hub emitted `BreadcrumbList`. All now pass
`breadcrumbs`, plus `Person` on the ballot (the highest-intent page on the site and
the only content page with no structured data at all) and on judge pages, and
`FAQPage` on help answers, whose titles are literally the questions.

Two things to know before editing any of it:

- **The layout's `BreadcrumbList` is hand-laid JSON in Twig**, with `|json_encode` per
  value. That is the right shape, and its failure is invisible: a judge called
  `Ọlá "The Bridge" O'Brien & Sons` in a `|raw` field produces a block Google silently
  drops. `SeoStructuredDataTest` renders the real template and `json_decode`s every
  block for exactly that name — it is not a grep for `"@type"`, because a grep passes
  on unparseable JSON.
- **The help breadcrumb used to link `/help?q={category}`**, a search URL that is now
  `noindex`. It points at `/help/c/{cat}` now. Do not send a trail into a page marked
  "do not index".

Tests: `SeoSitemapTest` (14), `SeoCanonicalTest` (14), `SeoStructuredDataTest` (5).
Each was mutation-checked — the service was broken four ways and each break failed
exactly the one test that names it.

---

## 12. The AI outage — one unread field, and what it hid

**25 Aug 2026.** The public status page had read
`AI assistance · Slower than usual · 0% answering` for weeks. Both halves of that
sentence were true and both were misleading, and the reason is worth keeping because
it is not the first instance of its shape here — `docs/CODEBASE-INDEX.md` §17 tabulates
the other four.

**`AiCapability::$timeout` was read by nothing.** Every capability declares one — 4s
for the classifier on the nomination submit, 20s for a thread summary, 30s for a
judge's dossier map, 120s for the document reader that uploads files before it
reasons — and `AiGateway` never passed it. Every call ran on `AiService`'s **6s**
constructor default, so the fourteen capabilities declaring more than six seconds
were cut off mid-generation on every single request, after which the chain paid six
more seconds a hop for two more hops. Slow, and never an answer.

That is exactly the fault `AiCapability::$model` had before `route()` was passed to
`complete()`, and the one `TicketLinkService::prune()` had with no caller: declared as
data, documented, faithfully recorded in the audit log, absent from the wire. **When
you add a field to a declaration in this codebase, grep for a reader before you
believe it.**

It is a per-call override (`AiService::withTimeout()`) rather than a `complete()`
parameter, and that is not stylistic: `AiGatewayTest`'s double overrides `complete()`
by signature, and a subclass with *fewer* parameters than its parent is a PHP fatal
error, not a failing test. The override is consumed and restored per call, because
`AiService::boot()` results are reused inside one request and a 120-second budget
left behind would be inherited by a classifier on a form POST.

Three things fell out of fixing it:

- **A read timeout was being filed as "unreachable".** cURL reports HTTP code `0` for
  a timeout as well as for a connection that never opened, and `ProviderBreaker`
  sidelines a provider for five minutes on the strength of that text — its whole
  justification being that unreachability is a fact that stays true. So every
  cut-off generation also blacklisted a healthy, answering provider, which is the
  outcome the breaker's own docblock forbids. `CURLINFO_CONNECT_TIME` settles it
  without guessing at cURL's wording: non-zero means the handshake completed, which
  proves the network path. `httpPost()` now writes `TIMEOUT after Ns` for that case
  and `HTTP 0` only for the real one, so `isUnreachable()` finally means what it says.
- **`CURLOPT_CONNECTTIMEOUT` was unset.** Harmless at a flat 6s; with budgets now
  running to 120s, discovering a blocked outbound port — *this deployment's actual
  fault* — would have cost the whole budget. Connecting is not the slow part of a
  model call, so it has its own short ceiling (`AiService::CONNECT_TIMEOUT`).
- **The interview's own accounting wrote `outcome = 'ok'`.** Both readers of
  `gates_ai_calls` ask for `'OK'`. MySQL's collation matched it and SQLite's `=` did
  not, so a successful interview turn counted as a success on production and a
  failure in every test and dev database — the MySQL/SQLite divergence CLAUDE.md
  warns about, arriving as a status page that is honest in one environment only.
  `AiGateway::record()` normalises at the door now.

**And the status row itself was measuring the wrong thing.** `gates_ai_calls` holds a
row for every REFUSAL as well as every call, by design — a call the gateway stopped is
as auditable as one it made. But a refusal never asked a provider anything, so
counting refusals as failed answers is what produced 0%: three of the six refusal
words are ordinary configuration (no key, capability switched off, budget spent) and
each of them alone drove the ratio to zero. The percentage is now over calls that
*reached* a provider, and where a refusal is the story the row names which one and
what clears it — "no provider configured", "today's allowance is spent, it resets at
midnight". Latency had been recorded since the log existed and never read; the row
whose label is literally "Slower than usual" now says how slow.

**And the admin console could not answer "why" either.** The capability table showed
{@see AiGateway::spendReport()}'s failure COUNT — "3" in a gold chip — while
`gates_ai_calls.error` had held up to 300 characters of the provider's own refusal
since the log was built, unread. On a host with no shell that count was the end of the
trail: a rejected key, a decommissioned model, an egress block and a summary that ran
out of time all rendered as the same chip. `AiGateway::recentFailures()` now lists the
last few, with the provider's words first and `likelyCause()`'s one-thing-to-change
after — never only the interpretation, because a guess that displaces the evidence is
how somebody rotates a working key while the real fault is an egress block. Refusals
are excluded for the same reason the status ratio excludes them.

`AiGateway::REFUSALS` is the single definition of "the gateway declined before asking
anybody", shared by that list and the status check, and it lives beside the code that
writes those words.

While there: `SystemStatusTest`'s AI assertions matched `'AI'` as a **substring of
'Messages waiting'** and were testing the queue row. The one asserting that the AI row
never reports a full outage passed for as long as the queue was empty. Use
`aiRow()`, not `componentSaying(…, 'AI')`.

Tests: `AiTimeBudgetTest` (12), plus six in `SystemStatusTest`. Mutation-checked —
removing the timeout plumbing fails three, collapsing the refusal split fails three.

### 12a. The judges' side of it

Three faults, all the same shape as the above — built carefully, with the part that
makes it useful left out.

- **The ballot served maps of dossiers that no longer existed.** `forNominee()` has
  always matched on the content hash, so the *button* never served a stale map.
  `forBallot()` did not, and it is the one the ballot renders from — so once a nominee
  added evidence, every judge on the panel went on reading a map of the entry as it
  stood before it, silently. A `gaps` line reading "no third-party confirmation of the
  figures" outlived the letter that confirmed them. Same fault as a silently truncated
  dossier, which the class already refuses to ship: the map is not wrong about what it
  read, it is wrong about what it is a map **of**. Marked and offered for re-reading —
  not hidden (throws away something still mostly true) and not refreshed on render
  (the model call on scroll the panel exists not to make).
- **The maps arrived too late to be maps.** All generated on demand, so a shortlist of
  forty was forty button presses and forty waits of up to thirty seconds — during
  which the judge has nothing to do but start reading, which is the moment the map
  existed to come before. Nothing in the design objected to doing it early: the map is
  cached *per nominee* and shared by the whole panel, deliberately, so orientation is
  not a variable between judges. One made at 04:00 by cron is byte-identical to the
  one the first judge would have waited for, and is made once instead of once per
  judge who gets there first. `JudgeAssist::sweep()`, hourly, capped at
  `SWEEP_LIMIT`; shortlist only; never the sandbox; stops the moment the capability is
  unavailable rather than writing forty `BUDGET_CALLS` rows into the log on the day
  somebody needs to read it. Addressable as `/__cron/run?task=judgemaps` because there
  is no SSH here.
- **A failed dossier was retried for ever.** `store()` has written a `failed` row since
  the day it shipped and nothing ever read one, so a dossier the model chokes on cost
  a fresh call on every button press from each judge on the panel, and would have cost
  another on every sweep for the rest of the round. `RETRY_AFTER_MIN` is keyed on the
  **dossier**, not the nominee: somebody who has just added the evidence the last
  attempt choked on deserves an immediate attempt.

Assembly is batched (`JudgeAssist::dossiers()`, four queries for a whole ballot) to
make the first two affordable, with the single-nominee call now a wrapper on it. A
divergence between the two would not throw — it would quietly mean every map read as
stale and the sweep regenerated all of them for ever, which is what
`test_the_batched_dossier_matches_the_single_one` is for.

`JudgeAssist::pending()` is deliberately separate from `sweep()`: the sweep is a loop
around one model call and every decision about WHETHER to make it is in `pending()`.
Together they would have been testable only by configuring a provider key and letting the
suite spend real tokens — so the selection would have gone uncovered, on the one scheduled
task on this platform that spends money. Its four rules (judging phase only, published
shortlist only, never the sandbox, never a current map or a recent failure) are each held
by one test and each mutation-checked.

Tests: `JudgeAssistTest` grew from 19 to 36.

### 12b. A flaky fixture, found while running all this

`ReferralSettingsTest::test_switching_off_does_not_erase_what_is_already_owed` failed once
in five full suite runs, asserting `0 > 0`. Not a race and not the switch it names:
`gates_referral_credits` has `UNIQUE(source_type, source_id)` and that uniqueness IS the
idempotency guarantee — confirmation is reachable three ways and they race, so
`creditSale()` swallows the duplicate-key throw by design. The fixture drew
`random_int(1, 1_000_000)` for the id, and the test makes **exactly** `THRESHOLD` (10)
credits, so one repeated draw silently leaves nine, which is below the threshold, so
nothing is payable and the assertion is correct about a state the test never meant to
create.

Ids are arbitrary to every assertion in the three files that did this, so the randomness
bought nothing and cost a test that fails for an unrelated reason. All three now count.
The two that use a raw `insert()` would have thrown rather than gone quiet — still a test
erroring about the wrong thing.

---

## 13. The status page, made standard (25 Aug 2026)

`SystemStatus::record()` had stored the per-component state of every snapshot since the log
existed and **only `overall` was ever read** — the sixth instance of §17's pattern, and the
most visible, because a per-component history bar is the single most recognisable element
of a status page and the data was already on disk.

The page now has the anatomy people know: verdict → component list, each row with its
state, its live measurement and its own 14-day bar on one shared axis → incidents → how to
read it. What it refuses to copy from the genre is the dishonesty:

- **The denominator is checks whose result is KNOWN.** Time we could not measure is not time
  we were up. The sample count is printed so the denominator is visible.
- **The figure is FLOORED, never rounded.** One failure in twenty thousand checks is 99.995%,
  which rounds to a clean hundred and erases the outage the reader came about.
- **A gap in the record is not an incident.** Cron missing a beat is not the platform
  breaking; manufacturing outages out of scheduling jitter makes the one list that names
  real faults worthless. UNKNOWN closes a run rather than extending it — once we stopped
  being able to see the fault we cannot claim it was still there.
- **A fault seen once reports as one check**, not as fifteen minutes nobody witnessed.

`/status.json` is uncached and CORS-open. Every status page people are used to publishes
one, an uptime monitor cannot scrape Twig, and with no shell here it is the only thing an
external watcher can act on. The **Cloudflare Worker in `deploy/cloudflare/` is the obvious
first consumer** — it currently guesses from an HTTP code.

**Traps found while building it, worth not re-finding:**

- `Carbon::diffInMinutes` is **signed** and its argument order changed meaning between major
  versions. It silently returned a negative span, so every incident duration read "seen
  once". Spans come from epoch seconds now.
- `SystemStatusTest` matched `'AI'` as a substring of **'Messages waiting'** and was
  asserting against the queue row. Use `aiRow()`.
- The stamp printed a bare UTC datetime with **no zone**, to an audience an hour ahead of it.
  The test was asserting the raw stored string — i.e. asserting the bug.
- Three mobile rules on the status page are worth keeping: releasing `max-width` as well as
  `min-width` at the breakpoint (a cap that survives the media query keeps the state in a
  narrow gutter and squeezes the component's name into four lines); stacking the history bar
  and its uptime figure as blocks rather than wrapped flex items; and `min-width:0` on the
  incident timestamp, without which `flex-shrink` never takes effect and the longest string
  in the row keeps its max-content width. All three are right at 320–390px. **The reason I
  went looking for them was not** — see the next entry.
- ~~**A pre-existing horizontal overflow at 390px is site-wide.**~~ **Wrong — there is no
  such overflow.** Measured, and every page reports
  `documentElement.scrollWidth == clientWidth` at 390, 360 and 320 (`/`, `/help`, `/status`,
  `/vote`, `/events`, `/registry`, `/leaderboard`). The claim came from a screenshot, and the
  screenshot was lying. See §1's note on measuring a phone width — it is the whole reason
  this entry exists.

Tests: `StatusHistoryTest` (27), plus six in `SystemStatusTest`.

## 14. The judging schedule and its reminders (25 Aug 2026)

`gates_interviews` holds a time, a panel, a joining link and a `calendar_event_id` per
sitting. **Every screen that read any of it read one sitting** — `/admin/interviews/{id}`.
So an operator running a panel of ten across forty entries had the whole round in the
database and no way to answer "what is Tuesday", "does Dr Achebe know about Thursday", or
"did the calendar actually take it". And a judge could see their ballots and not their
calls: the only place a sitting reached them was an email three days old.

`JudgeSchedule` + `/admin/judges/schedule` + a "Your calls" panel on the judge dashboard.

**A CSV is not "add to calendar".** The panel invitation already attached the judge's whole
run as a spreadsheet — which answers "what am I doing next week" and cannot remind anybody
on the morning, so the link had to be found again in a three-day-old email. There is an
`.ics` beside it now: **one VCALENDAR with many VEVENTs**, because concatenating
single-event files gives a client several calendars in one attachment and none of them does
anything useful with that. `Ics::calendar()` was added for it and `Ics::event()`'s output is
byte-identical. UIDs derive from the sitting id, so a re-send after a reschedule **updates**
the entries instead of doubling them.

**Reminders are manual and scoped.** `InterviewService::invite()` is per-sitting and
automatic, which covers none of the cases an organiser actually rings about: the round
moved and everybody needs today's links; one judge says she never got it; a programme's
panel is confirmed a week late. Three scopes — all / one programme / one judge — all
reducing to a set of judge ids, so the send has one behaviour to test. **A judge with
nothing scheduled is never mailed, even by "remind all"**: a reminder about no meetings is
the fastest way to teach a panel to ignore us. Partial sends say so — "sent 4" on a panel
of seven reads as all of them.

**Drift is the case the calendar check exists for.** A missing event is loud; an event
dragged to a new time in Google is silent — the invitations already went with the old time
and our reminders keep sending it. `verify()` reports agree / DRIFTED / gone separately, and
an unreachable script as **UNKNOWN and never as missing**: telling somebody forty sittings
are absent because a deployment is misconfigured sends them to recreate forty events that
already exist. Compared as instants, not strings: Google answers ISO-8601 with an offset and
we store naive UTC, so the same moment fails any string test. Verified **on demand** — forty
sittings is forty round trips to an Apps Script deployment, and a screen that spends the
script's quota on every glance stops working during the round it exists for.

**Four traps hit while building it, all caught by guards that already existed** — which is
the best argument for each of them:

- `name="csrf_token"` is the plausible, wrong field name. `CsrfMiddleware` reads **`_token`**
  and nothing else, so all four forms were inert. `CsrfFieldNameTest` caught it — that guard
  exists because three admin templates shipped this way once.
- `AdminDestructiveConfirmTest` measures the **literal prose** of a `data-confirm`, Twig
  stripped, and requires ≥40 characters. "Email all {{ n }} judges?" reads fine in the editor
  and collapses to a shrug. The confirmations name the consequence now: an email cannot be
  unsent, and each says how many inboxes it reaches.
- `AdminClassCoverageTest` — `ad-input` and `ad-label` are plausible and do not exist.
  `.ad-form` styles its labels and controls by **descendant selector**; an invented class
  name yields an unstyled field that looks deliberate. That guard exists because two forms
  once wrote `ad-form__row`.
- The schedule screen said "Synced" for a sitting with an event id when the script could not
  be reached. All that is known is an id was stored once; it says **"Event on file"** now.

Tests: `JudgeScheduleTest` (28), plus two in `JudgeInterviewMailTest`.

### And an event that is a fundraiser now says so

The registration panel on `/events/{slug}` read "Tickets" above the prices whether the event
was a summit or a fundraising dinner. It follows the event now — derived from the live
appeal already joined to it via `OrgCampaign::forEvent()`, **not** a second flag on the row
that goes stale the first time somebody closes the appeal and forgets it.

It still does **not** call the ticket a donation. The money moves through the ticket flow
with the ticket flow's refund terms; the appeal is a separate transaction with its own.
Naming a paid admission a gift is the same category error as calling it a support ticket,
pointed the other way — and it is the one that ends up in a dispute.

---

## 15. The three things that could not be triggered (25 Aug 2026)

Reported as: *"It is impossible to trigger the extension. Also, the site is not reading the
GAS secret and the calendar. The interview is also currently not well-structured."*

All three were real, and none of them was in the code that appeared to be at fault. Full
account in `docs/CODEBASE-INDEX.md` §18. What matters when you next work here:

### The GAS secret was read correctly, from the one place it can never be written

`GoogleMeetService::boot()` read `.env` only. There is no SSH on production, so the secret
could not be set at all — and every screen said "set `GAS_SECRET` in `.env`", which is true
and useless. Both values resolve **`gates_settings` first, `.env` as fallback** now
(`GoogleMeetService::gasUrl()` / `::gasSecret()`), and `/admin/settings` has a field for
each. `GoogleSheetsService::boot()` shares the resolver — the two halves must not be able to
disagree about whether the deployment is configured.

**If you add another consumer of the Apps Script, call the resolver.** `Env::get('GAS_URL')`
is now the wrong way to read it, and there is one such call left nowhere: the three that
existed (`ApiController`, the container's `GoogleSheetsService` factory, and the dead
`gas_url` Twig global, which had no reader at all) were all converted or removed.

### A `<form>` inside a `<form>` is deleted, and the button posts to the outer one

This is the finding worth carrying forward, because it had shipped **three** times and each
instance looked like something else:

- Settings' "Check the sync" saved the page and returned no rows — indistinguishable from a
  Google integration that cannot be reached. This is most of "the site is not reading the
  calendar".
- A category's "Delete" ran the **update**. After the confirm dialog said yes.
- The questionnaire's "Copy them in so I can edit them" posted an **empty** outcome list to
  the route that stores outcome lists.

`formaction` on the submit button is the fix. `NestedFormTest` scans every template for it
now, and also holds `admin.js` to `requestSubmit(submitter)` — `form.submit()` drops the
pressed button's `formaction`, which would make a confirmed delete run the save.

### The extension: two blockers, either one sufficient

**Nothing served the folder.** `GET /admin/interviews/extension.zip` builds it now, with
this deployment's host injected into `manifest.json` and both `DEFAULT_BASE` constants. That
injection is not a convenience: `host_permissions` is what makes the worker's fetch a
privileged extension request, and typing the right address into the popup **cannot** fix a
wrong manifest.

**And `content.js` returned before it started.** `if (!CODE) return;` on line one, with
`CODE` from the URL — and a content script is injected once per document load while Meet is
an SPA. Anybody who reaches a call by clicking it in the Meet list had the script run at a
moment when the path was `/`. The panel never appeared, and nothing anywhere said why.

Three consequences of making `connect()` re-entrant, all now guarded and all worth knowing
if you touch that file: `hunt()` self-schedules, `observe()` leaked a `MutationObserver` per
container (double-queued every caption line), and the pending buffer would file one call's
captions against another interview.

### The interview screen is now three groups, not eleven sections

Before / during / after, as `<details>`, with the group matching the sitting's state open.
`phase` is set at **template scope** in `show.twig` — if it ever drifts inside a block it
renders as `null` in all three tags and the page comes back 200 with everything collapsed,
which reads as a design choice. `InterviewScreenPhaseTest` renders all three states for
exactly that reason.

**One trap hit writing that test, worth repeating:** PHP's `+` array union keeps the **left**
operand for a duplicate key, so a fixture written `[...defaults] + $over` silently discards
every override. Four tests asserted against the same unmodified row and looked like a
template bug. And the transcript lives in `gates_nominee_interviews`, not a table named for
transcripts — check the schema, not the fixture.

---

## 16. The cinematic tier selection (26 Aug 2026)

Built from `docs/design-handoffs/ticket-tier-effect.md`, which is the handoff as received with
**⟶ BUILT** notes inline where the implementation differs and its three open decisions
answered in a new §10. Read that file rather than this section if you are changing the effect;
what follows is only what a future reader needs to know before touching anything near it.

### The design file was not in the attachment

`Ticket Tier Effect.dc.html` (option 6B) was referenced but not provided, so the `TONES`
object §5c says to "port verbatim" could not be ported. Everything specified in prose — four
durations, lap counts, spark counts, the ten layers' timing multipliers, the 520ms row press,
the 1.075 total beat, the .42 rack focus — was taken exactly. The gradients were authored from
one `--tier-hue` per row. If the file turns up, the arc and flash gradients are four lines in
`templates/pages/events/detail.twig` and nothing else depends on their shape.

### Three things the handoff specified that were wrong for this codebase

- **`loop.index0` for rank.** Tiers are ordered by `sort_order`. See CLAUDE.md.
- **Glow discs extending 22px past the card.** The sidebar gutter at 390px is 16px. `box-shadow`
  does not contribute to scroll width, so the bloom is a shadow on layers pinned to `inset:0`
  and the 390px check passes by construction. Measured: `scrollWidth == clientWidth` at 390,
  360, 320, 768, 1280.
- **`aria-pressed` on buttons.** The list it described had *no* selected state exposed to
  assistive tech at all — colour alone, WCAG 1.4.1, plus no name/role/value (4.1.2). It is a
  `role="radiogroup"` with `aria-checked`, a roving tabindex and arrow keys now, which is both
  the accessibility fix and the convention for picking one of a set.

### If you touch the effect, two things will bite

**Every `@keyframes` needs a paired twin.** A finished CSS animation does not restart when the
same `animation-name` is re-applied, and pressing one tier twice is what people do while
comparing. The card carries `.is-a`/`.is-b` by `burst % 2` and the *name change* is the
restart. `EventTierSelectionTest::test_the_burst_counter_is_what_makes_a_repeat_press_replay`
walks every keyframe in the file and fails any without a twin — one missing `B` means that
layer replays only every other press, which is close to invisible by eye.

**Do not add `overflow:hidden` to `.ed-rsvp`.** It clips the whole effect, and the symptom is
"the animation does not work" with nothing pointing at that line. The one place `overflow:hidden`
is correct is `.ed-fx__ring`, which is what confines the rotating conic gradients to the rim.

### And a false positive worth knowing about

`EventReferralPromptTest` asserts a page that promises 8% does not also say `10%` anywhere.
It was searching the raw body — 109KB including the inline stylesheet — and a CSS keyframe stop
is spelled `10%`. Adding `@keyframes edLapA{ … 10%{opacity:1} … }` failed it instantly, on a
page that was correct everywhere a reader could look. The guard now strips `<style>`, `<script>`
and inline `style` attributes first and keeps its full strength over visible text. The same
latent trap would have fired on an event that happened to be 10% full, via the progress bar's
`width:10%`.

### What is still open

**Mid-range Android.** Not measured — headless Chromium on a server cannot stand in for it. The
element budget is 10 layers on `calm`/`rise`/`hold` and 18 on `peak`, everything is `transform`
or `opacity`, and the two `box-shadow` values are static and crossfaded rather than keyframed.
It should be fine. It has not been proven fine on the device it runs on.
