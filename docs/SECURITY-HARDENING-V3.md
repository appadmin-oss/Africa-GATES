# Africa GATES — Security Hardening Runbook (V3)

> Operational companion to [`SECURITY-REVIEW-V3.md`](SECURITY-REVIEW-V3.md). Covers the
> findings that are **policy/infra decisions or deployment config** rather than app code,
> plus the two findings that are deliberately **not** auto-fixed (with the reason).
> Code-level fixes from V3 are already applied — see the review's §1 and this doc's footers.

---

## 1. PII data retention & right-to-erasure (Finding M2)

### Policy (set these per your legal counsel; defaults shown are conservative starting points)
| Data | Table | Suggested retention | Notes |
|------|-------|---------------------|-------|
| Abandoned nomination drafts | `gates_nomination_drafts` | **30 days** (built-in default) | Transient form data, no record value. |
| Rejected nominations | `gates_nominations` (status=rejected) | 90–180 days | Approved → become public nominees; never auto-purged. |
| Closed partner enquiries | `gates_partner_enquiries` (status=closed) | 12 months | |
| Event registrations | `gates_event_registrations` | 12 months after event | |
| Unsubscribed newsletter rows | `gates_newsletter` (unsubscribed_at set) | 90 days after unsubscribe | |
| **Donations** | `gates_donations` | **Keep (7-yr legal/audit)** | **Never** auto-purged. |
| **Approved profiles / votes / snapshots** | — | **Keep** | Public record / integrity chain. |

### Mechanism — `php bin/console privacy:purge` (shipped this review)
**Safe by default:** dry-run unless `--commit`, and a table is touched only when its retention window is configured (drafts have the 30-day default). Configure windows via env (0/unset = disabled):

```
RETAIN_DRAFT_DAYS=30
RETAIN_REJECTED_NOMINATION_DAYS=120
RETAIN_CLOSED_ENQUIRY_DAYS=365
RETAIN_EVENT_REGISTRATION_DAYS=365
RETAIN_UNSUBSCRIBED_NEWSLETTER_DAYS=90
```

```bash
php bin/console privacy:purge            # dry-run: reports what WOULD be deleted
php bin/console privacy:purge --commit   # actually deletes (schedule daily once configured)
# Cron:  15 3 * * *  /usr/bin/php /path/to/bin/console privacy:purge --commit
```

### On-demand erasure (data-subject request)
Until a self-service flow exists, fulfil erasure manually (then log it for accountability):
```sql
-- Identify by email (votes are hashed — match the hash for those):
DELETE FROM gates_nomination_drafts   WHERE payload LIKE '%<email>%';
DELETE FROM gates_event_registrations WHERE email = '<email>';
DELETE FROM gates_partner_enquiries   WHERE contact_email = '<email>';
UPDATE gates_newsletter SET email = NULL, unsubscribed_at = NOW() WHERE email = '<email>';
-- Donations: retain for legal/audit; pseudonymise donor_name/donor_email instead of deleting.
```
At-rest: enable **encrypted DB backups** (below) so erased data isn't resurrected from plaintext dumps.

---

## 2. Upload script-execution — host portability (Finding M3)

Uploads are validated (finfo magic bytes) and **re-encoded** (a polyglot `.php` can't survive GD re-encode as a valid image), then stored under `public/uploads/` with a 3-layer **Apache `.htaccess`** exec block. That block is **ignored by nginx/LiteSpeed**.

**If deploying on Apache/cPanel:** no action — the `.htaccess` defenses apply.

**If deploying on nginx/LiteSpeed,** add an equivalent (the `.htaccess` won't run):
```nginx
# Never execute anything under /uploads, and never serve dotfiles.
location ^~ /uploads/ {
    location ~ \.(php|phtml|phar|cgi|pl|py|asp|sh)$ { return 403; }
}
location ~ /\.(?!well-known) { deny all; }   # block .env, .htaccess, etc.
```
**Best long-term fix:** store uploads **outside the document root** and stream them through an authz-checked controller — eliminates the web-server-config dependency entirely (a small migration: move the dir, add a `GET /media/{path}` controller, rewrite stored `*_path` URLs).

**Deploy smoke test (any host):** `curl -I https://<host>/uploads/probe.php` must return 403/404, never execute.

---

## 3. Security monitoring & incident response (Finding M5)

The app **emits** security signals; wire **alerting** in your log/SIEM layer. Alert on these (greppable) events:

| Signal | Source | Alert when |
|--------|--------|-----------|
| `admin.login.lockout` | `AuthService` (WARN) — **added this review** | any occurrence (account under attack) |
| `admin.login.ip_throttled` | `AuthService` (WARN) | spike (credential stuffing) |
| `[payment] webhook signature rejected` | `PaymentController` (WARN) | repeated (forged-webhook probing) |
| fraud `block` decisions | `FraudService` / `gates_fraud_scores` (decision='block') | spike (vote-fraud campaign) |
| CSP violations | browser `report-uri` (if enabled) | any (possible injection attempt) |

- **Ship logs off-box** (`var/logs/*.log` → a log service / SIEM) so they survive host compromise and can be alerted on.
- **Incident-response runbook (minimal):** Detect (alert fires) → Contain (disable the affected admin via `/admin/admins` toggle or `is_active=0`; rotate secrets if a key is suspected) → Eradicate (patch, force password resets) → Recover (restore from backup if needed) → Review (write it up). Keep an on-call contact + the hosting/DB/DNS/Brevo/Paystack credentials in a sealed runbook.
- Keep `gates_cron_log.runtime_ms` populated (already done for the maintenance hub) and review the cron log for missed runs.

---

## 4. Backup & disaster recovery (Finding M6)

- **Database (daily, encrypted, off-host):**
  ```bash
  0 2 * * *  mysqldump --single-transaction -u <user> -p<pass> africa_gates \
             | gzip | gpg --encrypt -r ops@afrovanguard.org.ng > /backups/agates_$(date +\%F).sql.gz.gpg
  # then rsync/aws s3 cp the file off the server; keep 30 daily + 12 monthly.
  ```
- **Uploads:** back up `public/uploads/` alongside the DB (gitignored, not in the repo).
- **Secrets:** store `.env` in a password manager / secrets vault (it is gitignored and must never be committed).
- **Targets:** define RPO (≤24h with daily backups) and RTO (document the restore steps). **Test a restore quarterly** — an untested backup is not a backup.

---

## 5. Documented decisions — deliberately **not** auto-changed

- **M1 — CSP `'unsafe-inline'`/`'unsafe-eval'`.** The policy is already otherwise strong (`object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'self'`, scoped `frame-src`/sources — `SecurityHeadersMiddleware.php`). The residual `'unsafe-inline'`/`'unsafe-eval'` is **required by Alpine.js** (it `Function()`-evaluates `x-data`/`x-show`). Removing it needs the **Alpine CSP build** + a nonce on every inline script across all templates — a dedicated project, not a patch. Do it report-only first (`Content-Security-Policy-Report-Only`) to catch breakage. *Not changed — would break voting/community/admin sitewide.*
- **L3 — unsalted SHA-256 email/IP fingerprints.** A pepper (HMAC) can't be retrofitted: the plaintext is already gone, so existing hashes can't be re-computed, and changing the function at a cutover would **break one-vote-per-category dedup** (a prior voter's new hash wouldn't match their old one). Introduce a pepper only at a clean data reset, or accept the (low) rainbow-table risk. *Not changed — would break vote integrity.*
- **L6 — HSTS `preload`.** Adding `preload` + submitting to the browser preload list is a multi-month, hard-to-reverse commitment that forces **all** subdomains to HTTPS. Add it deliberately once you've confirmed every subdomain is HTTPS-only — not as an automated change.
- **L4 — PII in admin/notification emails.** Operationally useful (operators act on them); minimise to taste by linking to `/admin/...` instead of embedding contact details. Low priority.

---

## 6. Standing controls
- Keep the **`composer audit` CI gate** (`.github/workflows/ci.yml`) green — it caught the live Guzzle/psr7 CVEs this review. Add SAST (PHPStan) when convenient.
- Re-run [`SECURITY-REVIEW-V3.md`](SECURITY-REVIEW-V3.md) after any dependency bump or new admin/API route.
- Rotate SMTP / payment / AI keys on personnel change; never commit `.env`.

---

*Code fixes applied in V3 (see review §1): dependency CVEs patched; `viewer` role enforced read-only; nomination-draft key hardened + `loadDraft` rate-limited; `/api/funnel` rate-limited; `admin.login.lockout` security signal added; `privacy:purge` retention tool shipped (safe by default); weak default-credential docs removed; CI `composer audit` gate added.*
