# Driving scheduled work from a Cloudflare Worker

**Files:** `deploy/cloudflare/status-worker.js`, `deploy/cloudflare/wrangler.toml`
**Endpoint it drives:** `/__cron/run` (defined in `src/routes.php`, `$cronRun`)

---

## Why this exists

This deployment is cPanel with no SSH, and cPanel's own cron has not been dependable on it.
Scheduled work is not decorative here — while it is not running:

- refunds are not retried (the ladder is 1h → 6h → 24h),
- queued email does not go out (questionnaire invitations, receipts, reminders),
- award cycles do not advance,
- CPI is not recomputed and no tamper-evident snapshot is written,
- and `/status` records nothing, so the history strip on the status page has a hole in it
  exactly where the outage was.

`CronHealth::STALE_HOURS` is 6. Past that, `/status` reports **Scheduled work — Down** in
words, on the public page.

## Why a Worker rather than cron-job.org

Three differences, and the second is the one that matters.

1. **The token travels in a header.** `/__cron/run` accepts `X-Cron-Token` as well as
   `?token=`. A secret in a URL ends up in the host's access logs, in anything that sees
   the path, and in the scheduler's own dashboard. A Worker secret is in none of those.

2. **The Worker reads the answer.** `/__cron/run` deliberately returns **`200` with
   `ok:false`** when the orchestrator finished but individual tasks threw. That is not a
   bug — an earlier version answered `500`, and webcron services responded by backing off
   and disabling the job, which stopped the tasks that were still working. The cost of that
   choice is that *a pinger which only watches status codes reports green through exactly
   the failure it was hired to catch.* This Worker parses the body.

3. **It can tell you.** A failure arrives as an email instead of sitting in a dashboard
   nobody opens.

It does **not** require the domain to be proxied through Cloudflare. A Worker can fetch any
public URL; DNS can stay exactly where it is.

---

## Setup

### 1. Set the cron token on the site

Admin → **Settings** → the cron token field. Any random string of **12 characters or more**
— it is compared with `hash_equals`, so length is the only constraint.

If you have shell access you can instead put `CRON_TOKEN=…` in `.env`; the route checks the
environment first and falls back to the setting, so either works and you need only one.

Confirm it before going further — open in a browser:

```
https://afg.afrovanguard.org.ng/__cron/run?token=YOUR_TOKEN
```

You should get JSON. **A 404 here means the token is wrong**, not that the route is
missing — the endpoint is invisible without a matching token by design.

### 2. Install wrangler and log in

```bash
npm install -g wrangler
wrangler login
```

### 3. Deploy

```bash
cd deploy/cloudflare
wrangler secret put CRON_TOKEN      # paste the same value as step 1
wrangler deploy
```

Edit `SITE` in `wrangler.toml` first if the domain is not
`https://afg.afrovanguard.org.ng`.

### 4. Prove the whole path works

Do not wait fifteen minutes to find out.

```bash
curl -X POST https://africa-gates-cron.<your-subdomain>.workers.dev/run \
     -H "X-Cron-Token: YOUR_TOKEN"
```

`{"ok": true, …}` means the Worker reached the site, the token matched, and maintenance
ran. Then check `/status` — **Scheduled work** should read *Working*, with the gap since
the last run.

### 5. Optional — email on failure

```bash
wrangler secret put ALERT_TO      # where failures go
wrangler secret put ALERT_FROM    # a sender on a domain in this Cloudflare account
```

### 6. Optional — KV, so one blip does not email you

```bash
wrangler kv namespace create STATE
```

Paste the printed id into the `[[kv_namespaces]]` block in `wrangler.toml`, uncomment both
lines, and `wrangler deploy` again. With KV the Worker waits for **two consecutive**
failures before alerting; without it every failure alerts. One failed run at 03:00 is
usually a locked table or a five-minute blip at the host, and an email for each of those
teaches you to ignore the one that matters.

---

## What the responses mean

| Response | Meaning | Do |
|---|---|---|
| `200 {"ok":true,"ran":{…}}` | Everything ran. | Nothing. |
| `200 {"ok":true,"skipped":"another run in progress"}` | The single-instance lock held — the CLI cron or an overlapping hit was already inside. | Nothing. Normal, and it neither resets nor advances the failure streak. |
| `200 {"ok":false,"failures":{…}}` | **The run completed; named tasks threw.** | Read `failures` — it names the task and the reason. |
| `404`, empty | Token missing or wrong. | Compare the Worker secret against admin Settings. |
| `500 {"ok":false,"error":"maintenance could not start"}` | The orchestrator itself could not start — no database, no container. | `why` and `at` in the body name the file and line. |

A task reporting **`-1`** in `ran` is `Maintenance::TASK_FAILED`, not "nothing to do".
Zero means it ran and had no work, which is the common case; the sentinel exists precisely
so a crash cannot hide inside that number.

## Cost

Free tier. Cron Triggers are free, the free plan allows 5 per Worker and this uses 1, and
100,000 requests/day covers 96 runs.

## Keeping cPanel cron as well

Harmless and worth doing. `CronGuard::acquire('maintenance', …)` means whichever arrives
second exits cleanly with `skipped`, so the two cannot double-run. Two schedulers means
neither one going quiet takes maintenance down with it.

## Rolling it back

`wrangler delete` removes the Worker. Nothing on the site changes — `/__cron/run` is an
ordinary route and does not know or care what is calling it.
