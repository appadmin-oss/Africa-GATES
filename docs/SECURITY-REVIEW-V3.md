# Africa GATES — Production Security Review (V3)

> **Date:** 2026-06-24 · **Reviewer lens:** Senior security engineer (OWASP, Zero Trust, defense-in-depth, least privilege, secure-by-default). Assumes public-internet exposure and active attackers.
> **Method:** Six parallel source-grounded reviews (authn/authz, API/BOLA, injection/XSS/SSRF/prompt-injection, data/PII, infra/deps/ops, and this session's diff), every serious claim **adversarially verified against source + a live `composer audit`** before rating. Builds on the 2026-06-23 audit (baseline rated strong).
> **Companion:** [`ARCHITECTURE-AND-SCALING-REVIEW.md`](ARCHITECTURE-AND-SCALING-REVIEW.md), [`CODEBASE-INDEX.md`](CODEBASE-INDEX.md).

---

## 1. Security Audit Summary

**Posture: STRONG. No Critical and no High findings in application code.** The audited baseline holds, and the changes made earlier this session were verified safe. The review **found and fixed 3 live dependency CVEs** and applied 3 other safe hardening upgrades; the remaining open items are Medium hardening (CSP, PII lifecycle, upload-exec portability, RBAC granularity, monitoring, DR) and Low hardening — none block production on the documented Apache/cPanel stack.

A deliberate note on severity discipline: the parallel reviewers initially proposed a "Critical IDOR," a "High PII leak," and a "Medium default-credential." Verification **downgraded all three** — the draft key is ~128-bit (not brute-forceable), the "leaked" phone is an opt-in public-directory field, and the seeder already generates a random password (only docs showed a weak literal). Reported severities below reflect the verified reality, not the first impression.

### Fixed during this review (the V3 security upgrades)
| ID | Fix | Verification |
|----|-----|--------------|
| **DEP-1** | Updated `guzzlehttp/guzzle` 7.10.4→**7.12.3** and `guzzlehttp/psr7` 2.10.2→**2.12.3** (+promises 2.5.0), patching CVE-2026-55767, CVE-2026-55568, CVE-2026-55766 (all disclosed 2026-06-18). | `composer audit` → "No security vulnerability advisories found"; suite 171/171 green. |
| **L1** | Hardened the nomination-draft capability key to `crypto.getRandomValues` (128-bit) in `afg-features.js`, and rate-limited `loadDraft` (60/hr/IP) in `ApiController`. | Lint clean; suite green. |
| **L7** | Removed the weak default-password literal (`AfricaGates!2025`) from `README.md` and the stale `MigrateCommand` docstring (the seeder already generates a random password). | Grep confirms 0 remaining references in code/docs. |
| **M5 (partial)** | Added `.github/workflows/ci.yml` — a supply-chain gate running `composer audit` + the test suite on every push/PR. | — |
| **M4 (partial)** | `viewer` role now enforced **read-only** across `/admin` (`AdminAuthMiddleware`); `RoleMiddleware` already accepts multiple roles. Finer editor/admin gating left as a documented decision (no lockout risk taken). | `AdminAuthMiddlewareTest` (6) green |
| **L2** | `/api/funnel` rate-limited (500/hr/IP, silent drop — generous so legit analytics is unaffected). | suite green |
| **M5 (signal)** | Distinct `admin.login.lockout` WARN event added for log-based alerting. | suite green |
| **M2 (mechanism)** | `privacy:purge` retention/erasure command shipped — **dry-run by default**, per-table opt-in via `RETAIN_*` env, donations/records never auto-purged. Policy + runbook in `SECURITY-HARDENING-V3.md`. | `PrivacyPurgeTest` (3) green |

> **Follow-up remediation (same review):** the items above were implemented after the initial pass, on your "fix all" instruction. Verified: **suite 180/180 green**, all files lint clean, `privacy:purge` dry-run deletes nothing. The remaining open work is **policy/infra** (retention windows to set, nginx config if non-Apache, finer RBAC matrix, CSP-nonce/Alpine-CSP project, backup/DR, SIEM) — captured in `SECURITY-HARDENING-V3.md` with concrete configs. Two items are deliberately **not** auto-changed (full nonce-CSP breaks Alpine; HMAC-pepper breaks vote-dedup) — see that doc §5.

---

## 2. Vulnerability Report (open findings)

Each: description · severity · attack scenario · business impact · remediation · prevention.

### M1 — Content-Security-Policy permits `'unsafe-inline'` and `'unsafe-eval'`
- **Description.** `SecurityHeadersMiddleware` sets `script-src 'self' 'unsafe-inline' 'unsafe-eval' https:`. `'unsafe-inline'` + `'unsafe-eval'` largely neutralise CSP as an XSS *mitigation* layer. (Twig autoescape remains the *primary* XSS defense and is intact — hence Medium, not High.)
- **Severity: Medium** (defense-in-depth).
- **Attack scenario.** If any stored/reflected XSS slips past Twig (e.g., a future `|raw` on user data, or a third-party script compromise), CSP would not contain it.
- **Business impact.** Session/credential theft, defacement, fraud-UI injection — *conditional on a separate XSS bug existing*.
- **Remediation.** Move to a **nonce-based** CSP (`script-src 'self' 'nonce-<per-request>'`); drop `'unsafe-eval'` first (verify the Stripe-gradient/GSAP path still works), then migrate inline Alpine/handlers to nonced external scripts. Ship `Content-Security-Policy-Report-Only` first to catch breakage.
- **Prevention.** CSP report-URI monitoring; lint templates for new inline scripts.

### M2 — PII lifecycle: no retention/erasure, plaintext at rest, no at-rest encryption
- **Description.** Operationally necessary PII is stored in plaintext (`gates_profiles.email`, `gates_nominations` nominator/nominee name+email+phone, `gates_donations` donor name+email+phone, `gates_partner_enquiries`, `gates_event_registrations`, `gates_newsletter.email`, `gates_comments.author_email`). There is **no retention limit, no erasure mechanism, and no at-rest/backup encryption**. (Voter emails *are* hashed in `gates_votes` — good.) Note: storing a subscriber's email is *required* to email them — the gap is lifecycle + encryption, not the existence of the field.
- **Severity: Medium** (regulatory: NDPR §5 storage-limitation/minimization; GDPR Art. 5(1)(e), Art. 17, Art. 32).
- **Attack scenario.** A DB/backup compromise exposes years of un-expired contact PII for targeted phishing/harassment; a data-subject erasure request cannot be honoured.
- **Business impact.** Regulatory exposure (NDPR/GDPR), reputational harm, breach-notification obligations.
- **Remediation.** (a) Define a retention policy per table; add a `purgeOldPii()` step to `cron/maintenance.php` with `deleted_at` soft-delete for legal hold. (b) Add an erasure path (admin action + API) for right-to-be-forgotten. (c) Encrypt DB backups at rest; sign a DPA with Brevo. (d) Drop genuinely-unneeded plaintext columns (e.g., if `gates_comments.author_email` is never used for replies, store hash-only).
- **Prevention.** Data-map + privacy review gate on any new PII column.

### M3 — Upload script-execution defense is Apache-`.htaccess`-only
- **Description.** Uploaded files are validated (finfo magic bytes), re-encoded, and stored under `public/uploads/` with a **3-layer Apache `.htaccess`** block on script execution — robust on the documented Apache/cPanel stack. nginx/LiteSpeed **ignore `.htaccess`**.
- **Severity: Medium now (Apache); HIGH if ever deployed on nginx/LiteSpeed** → RCE via uploaded `.php`.
- **Attack scenario.** On a non-Apache host, an authenticated uploader stores `shell.php` in `/uploads` and requests it → arbitrary code execution.
- **Business impact.** Full server compromise.
- **Remediation.** Best: **store uploads outside the document root** and stream them through a controller (host-agnostic). Otherwise: document the Apache requirement and ship an nginx snippet (`location ^~ /uploads/ { location ~ \.php$ { return 403; } }`). Add a deploy smoke test: `GET /uploads/probe.php` must not execute.
- **Prevention.** Treat the upload dir as untrusted on every platform; never rely on a single web-server config for code-exec prevention.

### M4 — Flat sub-superadmin RBAC (broken access control / least privilege)
- **Description.** `AdminAuthMiddleware` gates `/admin` on *being logged in*; `RoleMiddleware('superadmin')` gates only judges/admins/settings/cycle. Every other admin **write** (profiles, nominations approve/reject, programmes, categories, nominees, legacy, opportunities, events, posts, partners, products, media) is reachable by **any** authenticated admin — including the `editor` and `viewer` roles the schema defines. So the role tiers below superadmin are functionally equivalent. (`routes.php` admin group; only lines 260-261, 319-341 carry role gates.) Pre-existing, not a regression.
- **Severity: Medium** (privilege-creep; impact scales with how many non-superadmin accounts exist).
- **Attack scenario.** A compromised or malicious `editor`/`viewer` account creates bogus programmes, approves fraudulent nominations, or deletes nominees — actions the role was presumably never meant to allow.
- **Business impact.** Integrity of the awards (the core trust asset); insider-threat surface.
- **Remediation.** Define a role→capability matrix and add `RoleMiddleware` per area (e.g., `viewer`=read-only, `editor`=content CRUD, `admin`=+structure, `superadmin`=+system). Have `RoleMiddleware` accept multiple allowed roles.
- **Prevention.** Least-privilege by default — every new admin route declares its required role.

### M5 — Monitoring, alerting & incident response
- **Description.** Admin actions are audit-logged (`gates_audit_log`) and Monolog writes to file, but **security events are not alerted** (failed-login bursts, account lockouts, webhook-signature failures, fraud `block` decisions) and there is no SIEM/aggregation or IR runbook. (`composer audit` CI gate added this review.)
- **Severity: Medium** (detection/response gap — "design for detection").
- **Attack scenario.** A slow credential-stuffing or fraud-ring campaign proceeds unnoticed because no one is paged.
- **Remediation.** Emit alerts on thresholds (e.g., ≥3 failed admin logins/10min/IP; any webhook-sig failure; fraud `block`); ship logs to a SIEM/log service; write an IR runbook (roles, comms, revocation steps). Add SAST (e.g., PHPStan) to the CI workflow.
- **Prevention.** Treat alerting as part of "done" for security-relevant code paths.

### M6 — Backup / disaster recovery undocumented
- **Description.** No documented DB/uploads backup strategy, RTO/RPO, or restore test in-repo.
- **Severity: Medium** (business continuity).
- **Remediation.** Daily `mysqldump` shipped off-host (encrypted) + `public/uploads/` backup; document RTO/RPO; perform a quarterly restore drill.
- **Prevention.** Backups are a deployment deliverable, not an afterthought.

### Low findings (summary)
| ID | Finding | Severity | Remediation |
|----|---------|----------|-------------|
| L2 | `/api/funnel` (and `saveDraft`) unrate-limited → analytics pollution / table growth | Low | Generous per-IP cap (don't break legitimate high-frequency funnel events). *(Not auto-fixed to avoid harming legit analytics.)* |
| L3 | Email/IP fingerprints use **unsalted SHA-256** → rainbow-table/enumeration if DB leaks | Low | HMAC-SHA256 with a server-side pepper. **Phased** — re-hashing invalidates existing vote-dedup/rate-limit fingerprints, so introduce for new rows + migrate. |
| L4 | Admin-alert + vote-confirmation emails carry PII / nominee names | Low | Minimize: generic subjects, link to the secure admin console instead of embedding PII. |
| L5 | Prompt injection (Gee chatbot, SpamService moderation) | Low (mitigated) | Already safe: `gee.js` HTML-escapes model output before render (no AI→XSS), moderation is advisory + fails closed, no secrets in the system prompt. Keep AI out of any blocking path; monitor. |
| L6 | HSTS lacks `preload` | Low | Add `preload` and submit to the preload list (a long-term HTTPS commitment — deliberate, not automated here). |
| L9 | `/api/registry/{slug}` returns `phone` | Info/verify | It's an opt-in public-directory field alongside website/socials (registration doesn't even collect it). Confirm intent; gate it only if any phone is captured privately. |

---

## 3. Severity Matrix

| Severity | Open | Fixed this review |
|----------|------|-------------------|
| **Critical** | 0 | 0 |
| **High** | 0 | 0 |
| **Medium** | 6 (M1–M6) | DEP-1 (dependency CVEs) |
| **Low** | 6 (L2–L6, L9) | L1, L7 |
| **Info / verified-secure** | many (see §4) | — |

---

## 4. Verified-Secure (do **not** spend effort "fixing" these)

Confirmed against source this review — these are strengths:
- **Privilege escalation (the highest-stakes check):** `AdminsController`'s removed inline checks are safe — the `/admins` route group carries `RoleMiddleware('superadmin')` covering all 4 methods (independently confirmed). All `/admin` behind `AdminAuthMiddleware`, all `/judge` behind `JudgeAuthMiddleware`, judge scoring gated by `JudgeService::canScore()`.
- **Payments:** server-authoritative amounts, gateway re-verify + amount parity, idempotent `WHERE status='pending'` single-row update, webhook HMAC-SHA512 (Paystack) / hash (Flutterwave) over the raw body. The new `payments:reconcile` cron preserves every invariant.
- **Injection:** SQL via query-builder bindings throughout; `selectRaw`/`DB::raw` only on constants/column-increments (no user input); no NoSQL; `exec()` in cron uses `escapeshellarg` on a fixed path; no SSTI (no dynamic template names).
- **XSS:** Twig autoescape on; `|raw` only dev-controlled/`json_encode(JSON_SAFE)`; the new community modal outputs no untrusted data and the WhatsApp link is a constant; **AI output is HTML-escaped before render** (`gee.js`).
- **CSRF / SSRF:** global CSRF + same-origin enforcement, exemptions justified (signature-verified webhook, OTP-gated vote); no user-controlled server-side URL fetch.
- **Session / auth:** HttpOnly+SameSite=Lax+Secure cookies, `session_regenerate_id` + CSRF rotation on login; admin login per-IP throttle + per-account lockout + timing-safe verify; magic links 256-bit, hashed at rest, single-use, 15-min TTL, never logged.
- **Uploads:** finfo magic-byte validation + re-encode + UUID names (+ Apache exec block — see M3).
- **Secrets:** `.env` gitignored + above docroot + `.htaccess` deny; no secrets in code; only publishable keys reach the browser; seeder generates a random admin password.
- **Tabnabbing:** the WhatsApp `target="_blank"` carries `rel="noopener noreferrer"`.

---

## 5. Attack Scenarios (prioritised)

1. **Non-Apache redeploy → upload RCE (M3).** The most severe *conditional* path: move off Apache, lose the `.htaccess` exec block, attacker uploads a web shell. → store uploads out of docroot.
2. **Dependency CVE (DEP-1) — now closed.** A crafted cookie domain or proxy-downgrade against Guzzle's HTTP client (payments/Sheets/AI calls). → patched to 7.12.3 / 2.12.3.
3. **Insider/compromised low-tier admin (M4).** An `editor`/`viewer` mutates programmes/nominations beyond their intended role. → per-area role gates.
4. **DB/backup compromise (M2/L3).** Plaintext PII + unsalted hashes enable targeted phishing and email-enumeration. → retention/erasure + pepper + encrypted backups.
5. **XSS chained past CSP (M1).** Only reachable if a separate XSS bug exists; CSP wouldn't contain it today. → nonce CSP.

---

## 6. Secure Architecture Recommendations
- **Uploads out of the document root**, streamed via an authz-checked controller (kills the web-server-config dependency in M3).
- **Capability + ownership for drafts:** the draft key is now 128-bit crypto-random and rate-limited; longer term, bind drafts to an authenticated/OTP identity rather than a bare capability.
- **Zero-trust internal calls:** keep AI strictly advisory and out of any allow/deny decision path (already the case) as the model surface grows.
- **Secrets:** move to a secrets manager (not just `.env`) when leaving shared hosting; rotate on personnel change.

## 7. Secure Code Improvements
- Implemented: crypto-random session/draft key; `loadDraft` rate-limit; CI `composer audit`. 
- Recommended: `RoleMiddleware` accepting multiple roles + per-area gates (M4); HMAC-pepper helper for fingerprints (L3); PII retention/erasure service (M2); nonce CSP helper (M1).

## 8. Infrastructure Hardening Recommendations
- nginx/LiteSpeed upload-exec block or out-of-docroot storage (M3); HSTS `preload` (L6); CSP nonces (M1); encrypted off-host backups + restore drills (M6); CI security gate (added) + SAST (M5); pin and audit dependencies on every build (added).

## 9. Compliance Considerations (NDPR / GDPR)
- **Retention/erasure (M2):** required by NDPR §5 and GDPR Art. 5(1)(e)/17 — currently unimplemented for PII tables.
- **Encryption (GDPR Art. 32):** add at-rest/backup encryption; ensure SMTP STARTTLS enforced.
- **Processor agreements (Art. 28):** DPAs with Brevo (email), Paystack/Flutterwave (payments), and any AI provider used.
- **Records of processing / privacy policy:** ensure the public policy matches actual storage (it claims emails are hashed — true for *voters*, but not newsletter/registration contacts).

## 10. Security Monitoring Recommendations
- Alert on: failed-login bursts, account lockouts, webhook-signature failures, fraud `block` decisions, and CSP violation reports.
- Populate `gates_cron_log.runtime_ms` (done for the hub) and expose a `/metrics`-style internal signal; ship logs to a SIEM; add anomaly alerting on vote-velocity (the data already exists via `FraudService`/`CollusionService`).
- Write an incident-response runbook (detect → contain → eradicate → recover → review) and a key-rotation procedure.

---

## 11. Production Readiness Assessment

**Verdict: APPROVED to operate at current scale on the documented Apache/cPanel stack.** The live dependency CVEs are patched, the integrity-critical paths (payments, voting, authz) are verified strong, and this session's changes introduced no vulnerabilities.

**Required before significant scale / for full compliance:**
1. **M3** — make upload exec-prevention host-agnostic *before* any non-Apache deploy. *(Highest-impact conditional risk.)*
2. **M2** — PII retention + erasure + backup encryption *(regulatory).* 
3. **M4** — per-area RBAC *before* issuing non-superadmin admin accounts.
4. **M1, M5, M6** — CSP nonces, security alerting/IR, documented DR.

**Standing controls:** keep the new `composer audit` CI gate green; re-run this review after any dependency bump or new admin/API route.

---

*Findings grounded in source as of 2026-06-24; every High-candidate was verified against code and a live dependency audit before rating. Analysis + the safe fixes noted in §1; the Medium/Low items are recommendations requiring product/infra decisions, not unilateral changes to verified-secure code.*

## Addendum — CSP rebuilt (2026-07-28)

### What was wrong

```
script-src 'self' 'unsafe-inline' 'unsafe-eval' https:;
connect-src 'self' https:;
```

Read together: `'unsafe-inline'` permits any injected `<script>` to execute, and
`https:` permits a script from **any https host on the internet**. The policy
therefore offered no meaningful protection against script injection at all — on a
platform that takes card payments and runs a public ballot. `connect-src https:`
compounded it: injected script could POST anywhere it liked.

The other directives — `object-src 'none'`, `base-uri 'self'`, `form-action`,
`frame-ancestors 'self'` — were doing real work and are unchanged. It was
`script-src` that was decoration.

### What it is now

Nonce-based, with explicit host allowlists derived from what the templates actually
load. `AfricaGates\Support\Csp` owns both the policy and the per-request nonce, so
the header and the templates cannot disagree — two generators would be the obvious
way to break every script on the page.

- **`'unsafe-inline'` removed from `script-src`.** 47 inline `<script>` blocks across
  37 templates now carry `nonce="{{ csp_nonce }}"`.
- **10 inline `on*=` handlers removed.** Inline handlers require `'unsafe-inline'`
  regardless of nonces, so ten small `onclick=` attributes were the reason the whole
  policy was toothless. They are now declarative `data-ag-do` values handled by one
  delegated listener in the layout.
- **The blanket `https:` is gone** from `script-src`, `style-src`, `connect-src`,
  `font-src` and `media-src`, replaced by the hosts in use. `connect-src` is
  deliberately tightest — it is the exfiltration path.
- **`img-src` keeps `https:` on purpose.** Nominee photos and partner logos
  legitimately come from arbitrary hosts, and a blocked image is cosmetic where a
  blocked script is a broken page.

### What is still permitted, and why — stated rather than hidden

- **`'unsafe-eval'`.** Alpine 3 compiles `x-data` / `@click` / `x-show` expressions
  with `new Function`. Removing it needs Alpine's CSP build, whose restricted
  expression syntax these templates do not use, or a hand rewrite of every directive.
  That is a project, not a tightening, and doing it badly breaks the nav, the cart and
  the ballot. A test asserts `'unsafe-eval'` is present so the compromise is explicit
  and nobody "fixes" it in passing.
- **`style-src-attr 'unsafe-inline'`** — see the styles section below. Structurally
  required, not laziness.
- **Six CDN script hosts** (`jsdelivr`, `unpkg`, `code.jquery.com`, `plyr`,
  Turnstile, Google ads). Each is a supply-chain dependency — `unpkg` and `jsdelivr`
  serve whatever the named package currently resolves to. Naming them does not remove
  the exposure, but it makes it **visible and countable**, which `https:` did not.
  Vendoring them is the obvious next step.

### Why this needed tests rather than a manual check

Once a nonce is present, browsers **ignore `'unsafe-inline'` for scripts entirely**.
So an inline `<script>` missing its nonce is silently dead: no server error, no failing
test, just a broken widget someone notices weeks later. With 47 of them across 37
files, a render-level assertion was the only honest way to know they were all updated.

`CspTest` covers both levels: it renders real pages through the real Twig and asserts
every inline `<script>` carries the nonce and that the rendered nonce matches the one
the header advertises, and it scans all template sources as a backstop for the pages
the render tests do not reach (admin, judge, shop, account). Two further tests assert
no inline event handler comes back, in the rendered output and in the source.

Both source scans strip Twig comments first — the layout documents this trap in prose
and would otherwise flag itself, which is exactly how the first run failed.

### Not done

No `report-uri` / `report-to`. A report-only companion header would be the right way
to find anything this breaks in the wild before it bites, but there is no collection
endpoint to point it at, and inventing one is a separate piece of work.


### Styles — the obvious change was the wrong one

Measured first: **42 `<style>` blocks** and **1,120 `style=` attributes across 95
templates**, of which **55 interpolate Twig values**:

```twig
style="background:{{ _ac.badgeBg }};border-color:{{ _ac.badgeBorder }}"
style="left:{{ 6 + i*11 }}%;animation-delay:{{ (i*32)/100 }}s"
```

Those are data-driven — a computed colour, bar width or animation delay cannot become
a static class, and **CSP has no per-attribute nonce**. So `'unsafe-inline'` for style
*attributes* is structurally required. Extracting 1,065 static ones would gain nothing
while 55 remain, because the keyword has to stay either way. That is why "move the
inline styles" is not the fix it appears to be.

**The trap.** A nonce anywhere in `style-src` makes browsers ignore `'unsafe-inline'`
for that directive — and `style-src` governs **both** `<style>` elements and `style=`
attributes. Adding the nonce there, which is the obvious move, would have killed all
1,120 inline style attributes site-wide. Every page would have rendered unstyled.

**What was done instead.** CSP3 splits the directive, and that split is what makes
this safe:

```
style-src      'self' 'unsafe-inline' <hosts>          ← fallback only, deliberately NO nonce
style-src-elem 'self' 'nonce-…'      <hosts>          ← the 42 blocks, no 'unsafe-inline'
style-src-attr 'unsafe-inline'                         ← the 1,120 attributes
```

This protects the vector that actually matters. A full `<style>` block can overlay the
page, fake UI, and exfiltrate field values through attribute selectors with
`background-image` URLs. A single `style=` on one element can do none of that.

`style-src` is kept **nonce-free on purpose** as the fallback for browsers without the
split — putting a nonce there would walk them straight into the trap above. A test
asserts it stays nonce-free, because that looks like an omission and is not.

All 42 blocks now carry `nonce="{{ csp_nonce }}"`, verified the same two ways as the
scripts: rendered pages asserted to have no un-nonced `<style>`, plus a source scan
covering admin, judge, shop and account. The failure mode is identical — an un-nonced
block is silently dropped and the page renders unstyled, with nothing failing on the
server.
