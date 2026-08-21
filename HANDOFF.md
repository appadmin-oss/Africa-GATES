# Handoff — Africa GATES

**Written:** 21 August 2026 · **Last commit at handoff:** `ed394b1` (18 commits on the branch)
**Branch:** `claude/codebase-audit-v7o5tw` (pushed, green)
**Suite:** 3569 tests / 19385 assertions passing (as of 21 Aug 2026) — `vendor/bin/phpunit --no-coverage` (~95s)
**Live site:** `afg.afrovanguard.org.ng`
**Not merged to master yet.** No PR opened — the user hasn't asked for one.

Read this before touching anything. Several items below are things that were
already tried and found to be wrong; repeating them costs a day.

---

## 1. Environment — read first, it will save you an hour

| Thing | State |
|---|---|
| `composer install` | **Works, but takes ~15 min and appears to hang** (no output). Don't kill it. `vendor/` is gitignored except `.htaccess`. |
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
- **Merged** `claude/clone-attendee-repo-7p9x53` twice — through `590429c`. Interview
  bot, voice, guard, three migrations, GCP runbook. **That workstream has its own
  handoff at `docs/INTERVIEW-BOT-HANDOFF.md` — read it before touching anything under
  `Interview*`.** Its open items are summarised in §10 below but not restated in full.

---

## 3. Outstanding work, in the order I'd do it

The user's list, unstarted. Each is a real feature, not a tweak.

1. **Live countdown in the last hours of the vote (on-site).** Smallest.
   `CountdownGif` and `DisplayTime` already do the hard part; this is a JS ticker
   on the vote/nominee pages driven by the cycle's `voting_close`.
2. **Leaderboard v2 design + roll the new logo site-wide.** One visual pass.
   `ux-review` skill is the reference for thresholds. **Do not roll the logo until
   the user confirms the green** — see §4.
3. **Fundraising for events, conversion-optimised.** Design was brainstormed in
   session: peer-to-peer campaigns per nominee (reuse the `ReferralService` shape),
   laddered asks ("₦25,000 sends a student"), thermometer with a real deadline,
   shareable artefact per donation via the existing `FlierService`.
4. **Site-wide SEO.** `Event`/`Person`/`ItemList`/`FAQPage` JSON-LD, per-page OG
   images wired to the existing flier generator, real `sitemap.xml` with priority,
   canonicals, CWV on nominee pages. **`searchfit-seo` does this better — get it.**
5. **Legal pages + tracking.** Blocked on plugins; see §4 and §8.
6. **Move the broadcast into the admin UI, with editable content.** Currently the
   campaign is a fixed Twig file (`templates/emails/final-hours.twig`) sent from a
   token-gated `/__setup/broadcast` page. That is a deploy-to-change-a-comma
   workflow, and `/__setup/*` is a plumbing surface, not a place to compose a
   message. See §6.


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

Required in `.env`: `APP_URL=https://afg.afrovanguard.org.ng` (every email link is
absolute — unset means broken links), `APP_KEY` (signs unsubscribe tokens).
**Tell them to delete `SETUP_TOKEN` when done** — it also gates the migrator.

---

## 6. Broadcast in the admin UI — the shape I would build

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

**P0 — the docs state a false claim, in three places.** `InterviewBot`'s class docblock,
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

- **Privacy erasure does not reach the bot data** (their item 6c).
  `PrivacyEraseUserCommand` clears neither `gates_interview_guard_log` nor Attendee's
  `/delete_data`. **Fold this into §8's tracking/consent work** — the same command has to
  grow anyway once analytics land, and erasure that misses a store is the failure that
  turns a DSAR into a complaint.
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
