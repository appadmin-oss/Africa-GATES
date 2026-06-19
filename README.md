# Africa GATES — Production Deployment Guide

**Continental Cultural Recognition Platform · An Afrovanguard Initiative**

## Stack
PHP 8.1+ · Slim 4 · Twig 3 · Eloquent ORM · MySQL 8.0+ · Brevo SMTP · Google Apps Script · Vanilla JS · Leaflet.js · Chart.js

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
- **PHP**: 8.1+ via MultiPHP Manager

## Cron Jobs
```
0 */6 * * *  /usr/bin/php /path/to/cron/recalculate-cpi.php
0 */4 * * *  /usr/bin/php /path/to/cron/aggregate-dashboard.php
```

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

## CPI Score Factors
| Factor | Weight |
|--------|--------|
| Vote Engagement | 25% |
| Verification Tier | 20% |
| Profile Completeness | 15% |
| Activity & Recency | 15% |
| Legacy Participation | 15% |
| Continental Reach | 10% |

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
- PHP must be 8.1+ (MultiPHP Manager)
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

# Equivalent manual approach:
mysql africa_gates < database/admin-schema.sql
mysql africa_gates < database/community-schema.sql
mysql africa_gates -e "INSERT INTO gates_admins (email, password_hash, name, role, is_active) VALUES \
  ('admin@afrovanguard.org.ng', '$(php -r \"echo password_hash('AfricaGates!2025', PASSWORD_BCRYPT);\")', \
   'Afrovanguard Admin', 'superadmin', 1)"
```

Then sign in at `/admin/login` with `admin@afrovanguard.org.ng` /
`AfricaGates!2025` and rotate the password immediately.

The schemas are idempotent (`CREATE TABLE IF NOT EXISTS` everywhere) — running
them on a DB that already has the tables is safe and a no-op.
