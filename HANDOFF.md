# Handoff — Africa GATES

**Branch:** `claude/codebase-audit-v7o5tw` (pushed, green)
**Suite:** 3560 tests / 19355 assertions passing — `vendor/bin/phpunit --no-coverage` (~95s)
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
- **Merged** `claude/clone-attendee-repo-7p9x53` (interview bot, voice, guard, GCP runbook).

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
5. **Legal pages + tracking.** Blocked on plugins; see §4 and §6.

---

## 4. Open decisions — do not guess these

- **The logo green.** The user attached their logo but it never reached the
  filesystem (only the earlier HTML upload did). `logo-africa-gates.png` and
  `logo-mark.png` were rebuilt from the repo's own Africa path + bundled Playfair,
  with the green matched **by eye at `#0B5D2E`**. Get the real hex or the real
  file before rolling it into nav/favicon/OG.
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

## 6. Traps — each of these was tried and is wrong

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

## 7. Tracking — the one request that cannot be built as asked

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

## 8. Conventions worth matching

Read the surrounding code first — this codebase has strong, deliberate patterns
and its docblocks explain *why*, not *what*. Specifically:

- Console commands are **dry-run unless `--commit`** (`registry:backfill`, `privacy:purge`).
- Migrations are idempotent, driver-aware (MySQL + SQLite), and **never `exit`/`die`**.
- `OptionalColumn::filter()` guards inserts against columns a deployment lacks.
- Settings live in `gates_settings` (`key_name`/`value`), read via a private
  static `setting()` helper per service.
- Comments explain the failure that motivated the code. Match that register —
  terse "what" comments read as foreign here.
