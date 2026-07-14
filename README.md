# Africa GATES — Production Deployment Guide

**Continental Cultural Recognition Platform · An Afrovanguard Initiative**

## Stack
PHP 8.4+ · Slim 4 · Twig 3 · Eloquent ORM · MySQL 8.0+ · Brevo SMTP · Google Apps Script · Vanilla JS · Leaflet.js · Chart.js

## Quick Start (Production · MySQL)

```bash
# 1. Install dependencies
composer install --no-dev --optimize-autoloader

# 2. Configure environment
cp .env.example .env
# Fill in: DB_*, SMTP_*, GAS_URL

# 3. Create MySQL database
mysql -u root -p -e "CREATE DATABASE africa_gates CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. Run migrations  (recommended: one shot via the console)
php bin/console db:migrate --with-seed-admin --with-seed-rubric
# OR manually:
mysql africa_gates < database/schema.sql
mysql africa_gates < database/seed.sql
mysql africa_gates < database/admin-schema.sql       # NEW — admin/auth/judges/settings tables
mysql africa_gates < database/community-schema.sql   # NEW — comments/cheers/activity/threads/rubric

# 5. Set permissions
chmod 755 public/ var/ var/cache/
chmod 600 .env
mkdir -p var/cache/twig && chmod 755 var/cache/twig
```

## Local Dev (SQLite — zero-config)

For local development the app ships with a SQLite fallback and a one-shot
seeder that loads rich sample data (20 profiles, 40 nominees, 2,400+ votes,
5 legacy events, 6 opportunities) so every page renders out of the box.

```bash
composer install
cp .env.example .env
# In .env, set:  DB_DRIVER=sqlite  and  APP_ENV=development
php database/setup-sqlite.php
cd public && php -S 127.0.0.1:8000
# Visit http://127.0.0.1:8000/africa-gates/
```

The SQLite DB lives at `var/data/africa_gates.sqlite`. Delete it and re-run
`php database/setup-sqlite.php` to start fresh.

## Frontend Assets

Static assets live under `public/assets/`:

- `assets/css/main.css`  — design tokens, layout, nav, footer, buttons, modals, toasts
- `assets/css/home.css`  — homepage-specific (hero, ticker, CPI ring, leaderboard, awards, legacy)
- `assets/css/pages.css` — inner page heroes, forms, awards detail, profile page
- `assets/js/main.js`    — reveal-on-scroll, count-up, scrollspy, toasts, modals, hero parallax

Images load from `images.unsplash.com` — ensure outbound HTTPS is allowed on
the production host (typically the case on standard hosting).

## cPanel Setup
- **Document root**: point to `/africa-gates/public`
- **PHP**: 8.4+ via MultiPHP Manager (matches the `>=8.4` constraint in `composer.json`)

## Cron Jobs

The platform self-runs via a single **automation hub**, `cron/maintenance.php`, which
selects work by the clock: every run drains the job queue (Google Sheets sync) + advances
award cycles + prunes cache; hourly it purges expired OTP/magic/rate-limit rows; every 6h it
recomputes CPI + writes tamper-evident snapshots; daily it runs the collusion scan + voting
reminders. **This one line is required** — without it the queue is never drained and cycles
never advance:

```
*/15 * * * * /usr/bin/php /path/to/cron/maintenance.php
*/10 * * * * /usr/bin/php /path/to/bin/console payments:reconcile   # confirm paid orders whose browser callback dropped
0 */4 * * *  /usr/bin/php /path/to/cron/aggregate-dashboard.php      # OPTIONAL: pre-warm the dashboard stats cache
15 3 * * *   /usr/bin/php /path/to/bin/console privacy:purge --commit # OPTIONAL: PII retention purge — only after setting RETAIN_* (see docs/SECURITY-HARDENING-V3.md)
```

`cron/recalculate-cpi.php` is a **standalone** CPI recompute (it delegates to the same
`bin/console cpi:recompute`). It is redundant when `maintenance.php` is scheduled — use it
only if you prefer discrete cron lines over the hub. **Do not schedule both**, or CPI will
recompute twice every 6 hours.

## Google Apps Script
1. Open Google Sheets → Extensions → Apps Script
2. Paste `config/AfricaGATES_AppScript.gs`
3. Deploy → Web App → Execute as Me → Anyone
4. Copy the `/exec` URL → `.env` → `GAS_URL=https://script.google.com/...`
5. Run `setupTriggers()` once manually

## Key Pages
| Route | Description |
|-------|-------------|
| `/africa-gates` | Home |
| `/africa-gates/vote` | Voting (shows closed state if not active) |
| `/africa-gates/nominate` | Nominations (shows closed state if not active) |
| `/africa-gates/awards` | Award programmes |
| `/africa-gates/leaderboard` | CPI rankings |
| `/africa-gates/registry` | Profile registry |
| `/africa-gates/legacy` | Legacy vault |
| `/africa-gates/opportunities` | Opportunities |

## Award Cycle Management
```sql
-- Open voting for Business Awards
UPDATE gates_award_cycles SET status='voting',
  voting_open=NOW(), voting_close='2025-09-15 23:59:59'
WHERE programme_id=4 AND year=2025;

-- Close voting
UPDATE gates_award_cycles SET status='results'
WHERE programme_id=4 AND year=2025;
```

**Note:** Voting and nominations are MUTUALLY EXCLUSIVE per programme. The pages automatically show a "closed" state when not in the relevant cycle phase.

## CPI Score (Cultural Power Index)

Scores run 0–1000. A **nominee's score** (per award category) blends two signals
(`CpiService::nomineeScore`; weights resolved by `RuleEngine`, defaults below):

| Component | Weight | Source |
|-----------|--------|--------|
| Community votes | 45% | this nominee's **organic** votes ÷ the category's top organic vote count (purchased bonus votes are excluded, so money can't move rank) |
| Expert judges | 55% | weighted average of judges' **complete** scorecards (0–10), counted only once the per-cycle judge quorum is met |

Weights and quorum are overridable per programme/cycle via `gates_rule_sets`.

A **profile's CPI** is the mean of its linked nominee scores
(`CpiService::profileRollup`). A profile not yet linked to any nominee gets a
baseline (`CpiService::baselineScore`): **50% verification tier + 30% profile
completeness + 20% reach** (views capped at 5,000).

**Tiers:** diamond ≥850 · platinum ≥650 · gold ≥450 · silver ≥250 · bronze ≥100 · unranked <100.

## Environment Variables
```
APP_ENV=production
DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS
SMTP_HOST / SMTP_PORT / SMTP_USER / SMTP_PASS
MAIL_FROM_ADDRESS / MAIL_FROM_NAME
GAS_URL=https://script.google.com/macros/s/YOUR_ID/exec
ANNOUNCE_TEXT=Nominations open — 2025 Cycle
```

## Built by Afrovanguard · afrovanguard.org.ng

## Troubleshooting 404s

### 1. Test routing works
Visit `/ping` — should return JSON. If 404, mod_rewrite is not enabled.

### 2. Enable mod_rewrite (Apache)
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### 3. AllowOverride (Apache config)
Your `<Directory>` block needs `AllowOverride All`:
```apache
<Directory /var/www/html>
    AllowOverride All
</Directory>
```

### 4. App in a subdirectory?
If your site is at `http://example.com/myapp/africa-gates/`, set in `.env`:
```
APP_BASE_PATH=/myapp
```
And update `.htaccess` `RewriteBase` to match:
```
RewriteBase /myapp/
```

### 5. cPanel shared hosting
- Set document root to the `public/` folder
- PHP must be 8.4+ (MultiPHP Manager)
- Run `composer install` via SSH or cPanel Terminal

### 6. Local development (PHP built-in server)
```bash
cd public
php -S localhost:8000
# Visit http://localhost:8000/africa-gates/
```

### 7. Database connection errors
Check `.env` DB credentials. Tables must exist — run schema.sql first.

## Upgrade an existing production DB (admin login was failing)

If admin login fails on a deploy that was set up before the admin / community
tables existed, run:

```bash
# On the production server, in the repo root:
php bin/console db:migrate --with-seed-admin --with-seed-rubric

# Schemas only (the console command above already creates the admin):
mysql africa_gates < database/admin-schema.sql
mysql africa_gates < database/community-schema.sql
```

`db:migrate --with-seed-admin` creates the first superadmin with a **strong random
password printed once** (or set `SEED_ADMIN_PASSWORD` to your own value beforehand).
Save it to a password manager, sign in at `/admin/login`, and rotate it immediately.
Never deploy with a shared or known default credential.

The schemas are idempotent (`CREATE TABLE IF NOT EXISTS` everywhere) — running
them on a DB that already has the tables is safe and a no-op.
