# Batch 3 Design — Nominations Contact Channels, Share Links, Webhooks, Member Autofill, Community v2, AI Everywhere

Date: 2026-07-04 · Status: approved-for-implementation (user directive: execute progressively in priority order)
Baseline: suite green — 286 tests / 808 assertions. Pre-batch snapshot: `../africa-gates-pre-batch3-2026-07-04.tar.gz`.

Standing constraints (carried from batches 1–2):
- MySQL is canonical — every new table/column lands in `database/schema.sql` FIRST, with `database/sqlite-schema.sql` parity (tests load schema files, not migrations). Migration files also added for live DBs.
- Audited vote/payment paths are **additive-only**.
- Integrations off-by-default, superadmin-configurable, graceful local fallback when unconfigured; loud-fail in prod for email only.
- No hardcoded demo values; no browser prompts; secrets write-only in admin UI.

---

## 1. Nomination contact logic + SMS/WhatsApp channels (Task #1)

**Current:** `AwardService::submitNomination()` throws unless `nominee_email` is a valid email. `nominee_phone` optional, unvalidated, stored raw. All notifications are email-only (`OtpService` SMTP). No SMS/WhatsApp code exists anywhere.

**Target rules:**
- Nominee contact requirement becomes **email OR phone** (either valid one proceeds; both allowed; neither = validation error).
- Phone numbers are normalized to **E.164** (Twilio + WhatsApp Business Cloud API compatible) at the boundary, stored normalized.
- Channel orchestration for nominee + nominator notifications:
  - email present → email (existing branded flow).
  - phone present → SMS via Twilio (if configured) **then** WhatsApp (if configured) — both when both configured.
  - WhatsApp **always** sends when a phone is present and WhatsApp is configured.
  - both email + phone present → email + SMS (+ WhatsApp per rule above).
- All message sends are best-effort synchronous with a short timeout; failures enqueue a `notify.sms` / `notify.whatsapp` retry job on the existing `QueueService` (5 attempts, linear backoff). Never blocks or fails the nomination.

**New components:**
- `src/Support/Phone.php` — static E.164 normalizer/validator. Country-aware (dial-code map for the site's country list; handles leading `0` national format, `+`/`00` international). Returns null for garbage; max 15 digits per E.164.
- `src/Services/SmsService.php` — mirrors `AiService` pattern (`boot()` from `gates_settings` with env fallback, `configured()`, `status()`, inert when unconfigured):
  - Twilio SMS: `sms_twilio_sid|token|from` (env `TWILIO_ACCOUNT_SID|TWILIO_AUTH_TOKEN|TWILIO_SMS_FROM`).
  - WhatsApp, provider-agnostic: Meta WA Business Cloud API (`wa_phone_number_id`, `wa_access_token`; env `WA_PHONE_NUMBER_ID|WA_ACCESS_TOKEN`) preferred; Twilio WhatsApp (`sms_twilio_wa_from` / env `TWILIO_WA_FROM`) as alternative. First configured wins.
  - Master toggles `sms_enabled`, `wa_enabled` (default off).
  - Compliance: transactional-only content, sender identity in body ("Africa GATES"), audit log table, hashed+masked recipient storage, no message content retention beyond template id.
- `gates_messages` table — delivery audit: id, channel(enum sms/whatsapp), to_hash, to_masked, template(varchar), status(enum sent/failed/queued), provider, provider_ref, error, created_at.
- Channel router: small static `Notifier::contactChannels(?email, ?phoneE164, SmsService)` returning ordered channel plan; used by NominationController (and later vote/registration flows).

**Touched:** `AwardService::submitNomination` (validation email-or-phone + normalized phone persist), `NominationController::submit` (validation, orchestration, SMS/WA content), `ApiController::submitNomination` (parity), `templates/pages/nominate.twig` (labels "at least one", Alpine step-1 validation), Settings UI card (Messaging) + allowlist, `.env.example`, `cron/maintenance.php` job handlers registration, schema files + migration.

## 2. Enterprise nomination reference (Task #2)

**Current:** `'NOM-' . $id` (e.g. NOM-42) — guessable, unbranded, no checksum.

**Target:** `AGN-YYYY-XXXXXX-C` — `AGN` prefix, cycle year, 6-char Crockford base32 (id-derived, offset-obfuscated, no vowel confusables), `C` = mod-37 check character. Example: `AGN-2026-0004D2-K`.
- `src/Support/Reference.php`: `nomination(int $id, int $year): string`, `isValid(string $ref): bool`, `parseId(string $ref): ?int`.
- New nullable `reference VARCHAR(24) UNIQUE` column on `gates_nominations`; written on insert; legacy `NOM-{id}` remains accepted anywhere references are looked up (admin search). Emails/SMS/success page show the new format.

## 3. Shareable prefill nomination links (Task #3)

**Current:** browser-local drafts only (`gates_nomination_drafts`, opaque session key). No shareable URL.

**Target:** nominator can generate `https://site/nominate?share={token}` that prefills nominee fields (name, email, phone, country, state, LGA, org, programme, category) — fully editable by the recipient.
- `gates_nomination_links`: id, token VARCHAR(64) UNIQUE (32-byte hex), payload TEXT(JSON, nominee-side fields only), created_ip_hash, hits INT, expires_at (default +30 days), created_at.
- `POST /api/v1/nominations/share-link` (rate-limited 10/hr/IP) → token; offered on the success page ("Invite others to second this nomination") and as a "Share a prefilled form" tool on the wizard.
- `GET /nominate?share={token}` → server loads payload (valid + unexpired), passes as `prefill` to the template; hit counter incremented; `share_link.used` webhook.
- PII stays server-side behind the high-entropy token; tokens expire; no enumeration (constant-time-ish lookup by unique index, 404 on miss).

## 4. Webhook event catalog expansion (Task #4)

**Current events:** `nomination.approved`, `event.registration`, `order.paid`, `ping` only.

**Add (dispatch AFTER commit, best-effort, additive):** `nomination.submitted`, `nomination.rejected`, `vote.cast`, `member.registered`, `member.verified`, `donation.confirmed`, `points.redeemed`, `community.thread_created`, `community.comment_posted`, `moderation.flagged`, `moderation.actioned`, `cycle.status_changed`, `winner.announced`, `share_link.created`, `share_link.used`, `partner.enquiry`. Payloads carry ids + display labels, never raw emails/phones (hashes or masked forms). Admin webhooks UI lists the full catalog (it already renders `WebhookService::EVENTS`).

## 5. Member profile autofill (Task #5)

Signed-in members see a "Use my profile details" control (Alpine, one click, reversible) on: nomination step 4 (nominator fields), vote ballot (name/email/phone), event RSVP, partner/contact forms where applicable. Server passes `member: {name,email,phone}` from session (fresh DB read) into templates; JS only fills empty-or-overwrite-on-click; everything stays editable. No forced behavior; no data written until submit.

## 6. Member area enrichment (Task #6)

**Current:** dashboard = points card + ledger + profile edit + bookmarks; post-registration is a bare "verify your email" notice.

**Add:** vote history ("My votes" via `voter_email_hash = sha256(member email)` — read-only), "My nominations" (by nominator_email) with status + reference, my share links (+ hits), community activity summary, profile completeness meter, quick actions (nominate/vote/community), welcome email on verification, and an onboarding checklist card on first login. All read-only aggregation — no changes to audited write paths.

## 7. Community v2 (Task #7)

- **Access model change (user directive):** guests = view-only (threads, comments, polls visible). Posting comments/threads, poll voting, and cheering require sign-in. Server-side 401→login redirect + UI CTAs ("Sign in to join the conversation"). Member identity (user_id) recorded on posts.
- **Layout modernization:** rebuilt `threads.twig`/`thread.twig`/`new-thread.twig` on the v3 design system — proper page header, category (space) chips, thread cards with avatars/initials + relative time + activity, inline composer for members, skeleton/empty states, mobile-first, dark-mode aware.
- **Member features:** report button (members flag content → quarantine queue + `moderation.flagged` webhook), reply notifications (queued email to thread author, opt-out link), author soft-delete of own posts, admin thread lock/pin UI (status enum already supports it).
- **AI in community:** "Summarize thread" (member-visible, cached per thread version), AI-assist in composer ("Help me phrase this"), both rate-limited with silent fallback (feature hides when AI unconfigured). Gee fab is suppressed on `/community*` for signed-in members (AI affordances are in-page); guests still get Gee.

## 8. AI everywhere + invisible rate limits (Task #8)

- **Unify:** `GuideService` (Gee) now uses `AiService::complete()` provider chain (Groq→Gemini→Anthropic→OpenAI) instead of its own Anthropic-only client — **Groq models are first-class for Gee too** (user directive 2026-07-04); `GEE_MODEL`/`ANTHROPIC_API_KEY` still honored. Scripted fallback stays = the "never feels rate-limited" degrade path.
- **Site-state knowledge (user directive 2026-07-04):** new `GuideService::siteState()` — a cached (~10 min, CacheService) snapshot of live platform state (active programmes + cycle statuses/deadlines, nominee/vote/thread counts, next event, review SLA, points/shop toggles) rendered into Gee's system prompt every request, so Gee always answers from the current state of the site without inventing figures.
- **Make AI-agent bridge (user directive 2026-07-04, off-by-default):** settings `gee_make_agent_url` + `gee_make_agent_key` (write-only) — when set, Gee forwards the conversation (message, history, page, site-state digest) to the Make agent endpoint (HMAC/bearer-authenticated) and relays its reply, giving Gee access to the agent's tools; on timeout/failure it falls back to the native AiService chain, then scripted. Inbound: authenticated `POST /api/v1/agent/gee` (same bearer key) lets the Make agent query Gee/site-state so the two communicate both ways.
- **Public AI budget:** per-IP + global daily budget via `RateLimitService`; when exceeded, responses transparently come from the scripted/cached tier (`source: scripted`) — no errors, no "limit reached".
- **Admin AI assistant:** `/admin/assistant` chat (all roles see it; superadmin = unlimited; other roles rate-limited) grounded with live platform stats (counts, pending queues, cycle status — read-only queries). Plus contextual helpers: nomination queue AI triage summary, one-click "AI summary" on a nomination review page, AI draft assist in content editors (blog/events).
- **Public AI features:** nomination story improver ("Polish my story" on step 3), thread summaries (Task 7), Gee everywhere else.

## 9. AI moderation upgrade (Task #9)

SpamService 2-stage pipeline already exists (heuristic → AI 0.20–0.60 band → allow/quarantine/reject + `gates_moderation_log`). Add: admin-configurable thresholds (`mod_threshold_quarantine`, `mod_threshold_reject` settings), member reports feeding the same queue, "AI re-check" button in `/admin/moderation`, `moderation.flagged`/`moderation.actioned` webhooks, and moderation coverage for share-link payloads and community replies (already covered) — replies to threads currently reuse postComment (covered).

## 10. Agentic nomination + voting (Task #10)

- **Pre-submit (public, invisible limits):** AI category suggestion + completeness feedback on the nomination wizard (queued behind "Polish my story" affordance).
- **Post-submit (queued job `ai.nomination_triage`):** dedupe detection vs existing nominees/nominations (name+country fuzzy), quality score + one-paragraph summary for admins → stored in `gates_nomination_insights` (nomination_id UNIQUE, dedupe_json, quality_score, summary, model, created_at); surfaced in admin nomination review. Advisory-only — never auto-approves/rejects.
- **Voting:** Gee ballot guidance + FraudService continues to own risk (no change to audited path).

## 11. Optimization/audit pass (Task #11)

Query/index review for new tables, cache headers, a11y sweep of new templates, docs update (CODEBASE-INDEX §), full suite + adversarial review of the batch.

---

**Sequencing** = task order above. Each task: schema-first → tests → implement → suite green before the next.
