# Africa GATES — Cron Jobs Setup Guide

This guide covers every scheduled task the platform needs, how to configure them
in cPanel, Linux crontab, and as systemd timers, and how to verify they're running.

---

## Overview of cron jobs

| Job | Script | Frequency | What it does |
|-----|--------|-----------|--------------|
| CPI Recompute | `cron/recalculate-cpi.php` | Every 6 hours | Recalculates Cultural Power Index scores for all profiles (votes + judge scores) |
| Dashboard Aggregation | `cron/aggregate-dashboard.php` | Every 4 hours | Rebuilds cached stats, region/tier distributions, and country data |
| Maintenance / orchestrator | `cron/maintenance.php` | **Every 15 minutes** | Self-scheduling single entry: every run drains the job queue, advances award-cycle phases and prunes cache; hourly it purges expired OTP/magic/rate-limit rows; every 6h it recomputes CPI + writes a tamper-evident snapshot; daily at 06:00 it runs the collusion scan, voting reminders and digest. **Must run every 15 min** or those sub-tasks never fire. |

> If you run the orchestrator every 15 min, Jobs 1–2 below are optional — it already
> recomputes CPI every 6h. Keep the dashboard job (it builds extra cached stats the
> orchestrator doesn't). Running CPI from both is harmless (idempotent).

---

## 1 — Find your PHP binary path

Before adding any cron, get the exact path to your PHP 8.1+ binary:

```bash
which php          # → /usr/bin/php  (common on cPanel)
php -v             # Confirm it's 8.1+
```

On cPanel with MultiPHP, PHP binaries live under:

```
/opt/cpanel/ea-php81/root/usr/bin/php
/opt/cpanel/ea-php82/root/usr/bin/php
/opt/cpanel/ea-php83/root/usr/bin/php
```

Run `php -v` for each path and use whichever is 8.1 or higher.

---

## 2 — Find your project root

```bash
pwd                          # Run from the africa-gates/ directory
# e.g. /home/youruser/public_html/africa-gates
```

The cron scripts are in `<project_root>/cron/`. All paths below use
`/home/youruser/africa-gates` as a placeholder — replace with your real path.

---

## 3 — cPanel Cron Jobs (GUI method)

1. Log in to cPanel → **Cron Jobs** (under Advanced).
2. Set the **email address** for cron output (important — this is how you see errors).
3. Add each job below using the **Minute / Hour / Day / Month / Weekday** fields:

### Job 1: CPI Recompute — every 6 hours

| Field | Value |
|-------|-------|
| Minute | `0` |
| Hour | `0,6,12,18` |
| Day | `*` |
| Month | `*` |
| Weekday | `*` |

**Command:**
```
/usr/bin/php /home/youruser/africa-gates/cron/recalculate-cpi.php >> /home/youruser/africa-gates/var/logs/cpi-cron.log 2>&1
```

### Job 2: Dashboard Aggregation — every 4 hours

| Field | Value |
|-------|-------|
| Minute | `15` |
| Hour | `0,4,8,12,16,20` |
| Day | `*` |
| Month | `*` |
| Weekday | `*` |

**Command:**
```
/usr/bin/php /home/youruser/africa-gates/cron/aggregate-dashboard.php >> /home/youruser/africa-gates/var/logs/dashboard-cron.log 2>&1
```

> Note: Use minute `15` (not `0`) so it doesn't run at the same second as the CPI job.

### Job 3: Maintenance / orchestrator — every 15 minutes

| Field | Value |
|-------|-------|
| Minute | `*/15` |
| Hour | `*` |
| Day | `*` |
| Month | `*` |
| Weekday | `*` |

> This one MUST be every 15 minutes — it self-schedules its heavier sub-tasks
> (CPI, snapshots, collusion, reminders) by the current hour/minute. A daily run
> would silently skip all of them. Overlapping runs are safe (the script takes a
> single-instance lock and exits early if another run is active).

**Command:**
```
/usr/bin/php /home/youruser/africa-gates/cron/maintenance.php >> /home/youruser/africa-gates/var/logs/maintenance-cron.log 2>&1
```

---

## 4 — Linux crontab (VPS / dedicated server)

Edit the crontab for the web user (`www-data`, `ubuntu`, or your deploy user):

```bash
crontab -e
```

Paste these lines (adjust paths and PHP binary):

```cron
# Africa GATES cron jobs
# CPI recompute — every 6 hours
0 0,6,12,18 * * * /usr/bin/php /home/youruser/africa-gates/cron/recalculate-cpi.php >> /home/youruser/africa-gates/var/logs/cpi-cron.log 2>&1

# Dashboard aggregation — every 4 hours
15 0,4,8,12,16,20 * * * /usr/bin/php /home/youruser/africa-gates/cron/aggregate-dashboard.php >> /home/youruser/africa-gates/var/logs/dashboard-cron.log 2>&1

# Maintenance / orchestrator — every 15 minutes (drives cycles, queue, CPI, snapshots, collusion, reminders)
*/15 * * * * /usr/bin/php /home/youruser/africa-gates/cron/maintenance.php >> /home/youruser/africa-gates/var/logs/maintenance-cron.log 2>&1
```

Save and exit. Verify with:
```bash
crontab -l
```

---

## 5 — systemd timers (Ubuntu / Debian VPS — recommended for reliability)

systemd timers are more reliable than crontab — they survive reboots, log to journald,
and can be monitored with `systemctl`.

### Create a service file for each job

**`/etc/systemd/system/africa-gates-cpi.service`**
```ini
[Unit]
Description=Africa GATES — CPI Recompute
After=network.target

[Service]
Type=oneshot
User=www-data
WorkingDirectory=/home/youruser/africa-gates
ExecStart=/usr/bin/php /home/youruser/africa-gates/cron/recalculate-cpi.php
StandardOutput=append:/home/youruser/africa-gates/var/logs/cpi-cron.log
StandardError=append:/home/youruser/africa-gates/var/logs/cpi-cron.log
```

**`/etc/systemd/system/africa-gates-cpi.timer`**
```ini
[Unit]
Description=Africa GATES — CPI Recompute timer

[Timer]
OnCalendar=*-*-* 00,06,12,18:00:00
Persistent=true

[Install]
WantedBy=timers.target
```

**`/etc/systemd/system/africa-gates-dashboard.service`**
```ini
[Unit]
Description=Africa GATES — Dashboard Aggregation

[Service]
Type=oneshot
User=www-data
WorkingDirectory=/home/youruser/africa-gates
ExecStart=/usr/bin/php /home/youruser/africa-gates/cron/aggregate-dashboard.php
StandardOutput=append:/home/youruser/africa-gates/var/logs/dashboard-cron.log
StandardError=append:/home/youruser/africa-gates/var/logs/dashboard-cron.log
```

**`/etc/systemd/system/africa-gates-dashboard.timer`**
```ini
[Unit]
Description=Africa GATES — Dashboard Aggregation timer

[Timer]
OnCalendar=*-*-* 00,04,08,12,16,20:15:00
Persistent=true

[Install]
WantedBy=timers.target
```

**`/etc/systemd/system/africa-gates-maintenance.service`**
```ini
[Unit]
Description=Africa GATES — Maintenance

[Service]
Type=oneshot
User=www-data
WorkingDirectory=/home/youruser/africa-gates
ExecStart=/usr/bin/php /home/youruser/africa-gates/cron/maintenance.php
StandardOutput=append:/home/youruser/africa-gates/var/logs/maintenance-cron.log
StandardError=append:/home/youruser/africa-gates/var/logs/maintenance-cron.log
```

**`/etc/systemd/system/africa-gates-maintenance.timer`**
```ini
[Unit]
Description=Africa GATES — Maintenance timer

[Timer]
OnCalendar=*:0/15
Persistent=true

[Install]
WantedBy=timers.target
```

**Enable and start all timers:**
```bash
systemctl daemon-reload
systemctl enable --now africa-gates-cpi.timer
systemctl enable --now africa-gates-dashboard.timer
systemctl enable --now africa-gates-maintenance.timer
```

---

## 6 — Create log directories

All three jobs append to log files. Create the log directory before the first run:

```bash
mkdir -p /home/youruser/africa-gates/var/logs
chmod 755 /home/youruser/africa-gates/var/logs

# If running as www-data:
chown www-data:www-data /home/youruser/africa-gates/var/logs
```

> The `.env` file must be readable by the cron user — double-check ownership.

---

## 7 — Verifying cron runs

### Check the database log table

Every job writes a row to `gates_cron_log` after it runs. Check this from the
Africa GATES admin panel under **Settings → Cron Log**, or query directly:

```sql
SELECT job_name, status, message, runtime_ms, ran_at
FROM gates_cron_log
ORDER BY ran_at DESC
LIMIT 20;
```

Expected output after the first run:
```
| cpi         | success | Recomputed via console command | 2340 | 2026-06-06 06:00:02 |
| dashboard   | success | Aggregated stats, pruned 0 rows | 180 | 2026-06-06 04:15:01 |
| maintenance | success | Purged 0 OTPs, 3 cache rows    | 45  | 2026-06-06 02:00:01 |
```

### Check the log files

```bash
tail -50 /home/youruser/africa-gates/var/logs/cpi-cron.log
tail -50 /home/youruser/africa-gates/var/logs/dashboard-cron.log
tail -50 /home/youruser/africa-gates/var/logs/maintenance-cron.log
```

### systemd status

```bash
systemctl status africa-gates-cpi.timer
systemctl list-timers africa-gates-*
journalctl -u africa-gates-cpi.service -n 30
```

---

## 8 — Run manually to test

Before relying on the schedule, run each job once by hand to confirm it works:

```bash
cd /home/youruser/africa-gates

php cron/recalculate-cpi.php
php cron/aggregate-dashboard.php
php cron/maintenance.php
```

All three should print timestamped lines and exit with no PHP errors.
If you see a database connection error, check that `.env` has the correct
`DB_*` values and the database user has `SELECT`, `INSERT`, `UPDATE`, `DELETE`
permissions on the `africa_gates` database.

---

## 9 — Common errors

| Error | Cause | Fix |
|-------|-------|-----|
| `Class not found` | Autoloader not built | Run `composer install --no-dev` |
| `SQLSTATE: unable to open database` | SQLite file missing or wrong path | Run `php database/setup-sqlite.php` to recreate |
| `Dotenv: .env not readable` | Missing `.env` or wrong permissions | `chmod 640 .env` and check the path |
| `exec(): php: command not found` | Wrong PHP binary path | Use the full path from `which php` |
| Cron runs but leaderboard doesn't update | Cache not cleared | The CPI script clears cache automatically; if stale, delete rows from `gates_cache` |
| `Permission denied` writing log | Wrong ownership on `var/logs/` | `chown -R www-data var/` |

---

## 10 — Admin panel — Cron Log

Log in at `/admin/login` → navigate to **Settings** → scroll to **Cron History**.
The last 20 runs for each job are displayed with runtime, status, and any error message,
so you can confirm the schedule is working without SSH access.

---

*Built by Afrovanguard · afrovanguard.org.ng*
