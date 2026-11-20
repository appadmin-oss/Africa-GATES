/**
 * AFRICA GATES — SCHEDULED-WORK DRIVER (Cloudflare Worker)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Drives `/__cron/run` on a Cron Trigger, because this deployment is cPanel with no SSH
 * and cPanel's own cron has not been reliable on it.
 *
 * ── WHY A WORKER AND NOT cron-job.org ────────────────────────────────────────
 *
 * Three differences, all of which matter here:
 *
 *  1 · THE TOKEN GOES IN A HEADER. `/__cron/run` accepts `X-Cron-Token` as well as
 *      `?token=`, and a URL-borne secret ends up in the host's access logs, in any
 *      analytics that sees the path, and in the scheduler's own dashboard. A Worker
 *      secret is none of those places.
 *
 *  2 · IT READS THE ANSWER. This is the important one. `/__cron/run` answers **200 with
 *      `ok:false`** when the run completed but individual tasks threw — deliberately, so
 *      that a scheduler seeing a persistent 500 does not back off and disable the job,
 *      which would stop the tasks that WERE working. A pinger that only watches status
 *      codes therefore reports green through exactly the failure this exists to catch.
 *
 *  3 · IT CAN SAY SO. A failure reaches you as an email rather than sitting in a
 *      dashboard nobody opens.
 *
 * It does NOT require the domain to be proxied through Cloudflare. A Worker can fetch any
 * public URL; the site can stay on its current DNS.
 *
 * ── WHAT THE ENDPOINT ACTUALLY RETURNS ───────────────────────────────────────
 *
 *   200 {"ok":true,  "ran":{…}}                        every task ran
 *   200 {"ok":true,  "skipped":"another run in progress"}  the single-instance lock held
 *   200 {"ok":false, "failures":{"queue":"…"}}         partial run — SOME TASKS BROKE
 *   404 (empty)                                        token missing or wrong
 *   500 {"ok":false,"error":"maintenance could not start","why":"…"}
 *
 * A task reporting `-1` in `ran` is `Maintenance::TASK_FAILED`, not "nothing to do".
 *
 * ── CONFIGURE ────────────────────────────────────────────────────────────────
 *
 *   wrangler secret put CRON_TOKEN      ← same value as admin Settings → cron token
 *   wrangler secret put ALERT_TO        ← optional, an address for failures
 *   wrangler secret put ALERT_FROM      ← optional, must be a verified MailChannels sender
 *
 * `SITE` is a plain var in wrangler.toml, not a secret — it is a public URL.
 *
 * See docs/CLOUDFLARE-CRON-WORKER.md for the click-by-click version.
 */

/** How long to wait for one maintenance run before giving up. */
const TIMEOUT_MS = 60_000;

/**
 * Only alert after this many CONSECUTIVE bad runs.
 *
 * One failed run at 03:00 is usually a locked table or the host's own five-minute blip. An
 * email for every one of those trains you to ignore the alert, which costs you the real one.
 * Requires the KV binding; without KV every bad run alerts, which is the safe direction to
 * be wrong in.
 */
const ALERT_AFTER = 2;

export default {
  /** Cron Trigger entry point. */
  async scheduled(event, env, ctx) {
    ctx.waitUntil(runOnce(env, 'cron'));
  },

  /**
   * Manual entry point, so you can prove the whole path works without waiting for the
   * schedule. Gated by the same token — otherwise the Worker's public URL would be an
   * unauthenticated button that runs your maintenance.
   */
  async fetch(req, env) {
    const url = new URL(req.url);
    if (url.pathname !== '/run') {
      return new Response('Africa GATES cron driver. POST /run with X-Cron-Token.', {
        status: 404, headers: { 'content-type': 'text/plain' },
      });
    }
    const given = req.headers.get('X-Cron-Token') || '';
    // Length check first: timingSafeEqual throws on a length mismatch rather than
    // returning false.
    if (!env.CRON_TOKEN || given.length !== env.CRON_TOKEN.length ||
        !timingSafeEqual(given, env.CRON_TOKEN)) {
      return new Response('Not found', { status: 404 });
    }

    const r = await runOnce(env, 'manual');
    return new Response(JSON.stringify(r, null, 2), {
      status: r.ok ? 200 : 502,
      headers: { 'content-type': 'application/json' },
    });
  },
};

/** One maintenance run, judged on the BODY rather than the status code. */
async function runOnce(env, source) {
  const site = (env.SITE || '').replace(/\/+$/, '');
  if (!site || !env.CRON_TOKEN) {
    return { ok: false, why: 'SITE or CRON_TOKEN is not configured on the Worker.' };
  }

  const started = Date.now();
  let res, body, text;

  try {
    res = await fetch(site + '/__cron/run', {
      method: 'POST',
      headers: {
        'X-Cron-Token': env.CRON_TOKEN,
        'User-Agent': 'AfricaGATES-CronWorker/1 (+cloudflare)',
      },
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    text = await res.text();
  } catch (e) {
    // A network failure IS the outage — the site is unreachable from the edge.
    return await verdict(env, source, {
      ok: false, status: 0, ms: Date.now() - started,
      why: 'the request never completed: ' + (e && e.message ? e.message : String(e)),
    });
  }

  try { body = JSON.parse(text); } catch { body = null; }

  // 404 means the token was rejected. Called out by name because it is the one failure
  // that looks like a missing page and is actually a mismatched secret.
  if (res.status === 404) {
    return await verdict(env, source, {
      ok: false, status: 404, ms: Date.now() - started,
      why: 'the endpoint answered 404 — the Worker\'s CRON_TOKEN does not match the one in '
         + 'admin Settings, or no token is set there at all.',
    });
  }

  if (body === null) {
    return await verdict(env, source, {
      ok: false, status: res.status, ms: Date.now() - started,
      why: 'the response was not JSON: ' + text.slice(0, 300),
    });
  }

  // ── THE WHOLE POINT ────────────────────────────────────────────────────────
  // 200 with ok:false is a partial run: the orchestrator finished, individual tasks threw.
  // A status-code-only check calls this healthy.
  const failures = body.failures || {};
  const names = Object.keys(failures);
  const ok = res.status === 200 && body.ok === true;

  return await verdict(env, source, {
    ok,
    status: res.status,
    ms: Date.now() - started,
    skipped: body.skipped || null,
    ran: body.ran || null,
    failures,
    why: ok ? null
       : names.length
         ? names.length + ' task(s) failed: '
           + names.map((n) => n + ' — ' + failures[n]).join(' | ')
         : (body.why || body.error || 'the run reported ok:false with no detail'),
  });
}

/**
 * Record the outcome and alert on a run of consecutive failures.
 *
 * A skipped run — the single-instance lock held because the CLI cron or another Worker
 * invocation was already inside — is neither a success nor a failure and must not reset
 * the streak in either direction: treating it as success would paper over a stuck lock,
 * and treating it as failure would alert on healthy overlap.
 */
async function verdict(env, source, r) {
  const line = JSON.stringify({ at: new Date().toISOString(), source, ...r });
  console.log(line);

  if (r.skipped) return r;

  let streak = r.ok ? 0 : ALERT_AFTER;   // no KV → every failure alerts

  if (env.STATE) {
    try {
      const prev = parseInt((await env.STATE.get('fail_streak')) || '0', 10) || 0;
      streak = r.ok ? 0 : prev + 1;
      await env.STATE.put('fail_streak', String(streak));
      await env.STATE.put('last_run', line, { expirationTtl: 60 * 60 * 24 * 30 });
    } catch { /* KV is a convenience; never let it swallow the run */ }
  }

  if (!r.ok && streak >= ALERT_AFTER) await alert(env, r, streak);

  return r;
}

/** Email through MailChannels. Silent no-op when ALERT_TO is unset. */
async function alert(env, r, streak) {
  if (!env.ALERT_TO || !env.ALERT_FROM) return;

  const text =
    'Africa GATES scheduled work has failed ' + streak + ' run(s) in a row.\n\n' +
    'What happened: ' + r.why + '\n' +
    'HTTP status:   ' + r.status + '\n' +
    'Took:          ' + r.ms + 'ms\n\n' +
    'Open ' + (env.SITE || '') + '/status to see what this is affecting, and\n' +
    '/admin/settings for the cron token if the status above is 404.\n\n' +
    'Refunds, reminders and queued email do not go out while this is failing.\n';

  try {
    await fetch('https://api.mailchannels.net/tx/v1/send', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        personalizations: [{ to: [{ email: env.ALERT_TO }] }],
        from: { email: env.ALERT_FROM, name: 'Africa GATES cron' },
        subject: 'Africa GATES: scheduled work is failing',
        content: [{ type: 'text/plain', value: text }],
      }),
    });
  } catch { /* an alert that throws must not take the run down with it */ }
}

/**
 * Constant-time compare. Callers check the length first — `timingSafeEqual` THROWS on a
 * length mismatch rather than returning false, which would be an unhandled exception on
 * every wrong-length guess.
 *
 * The fallback accumulates with XOR rather than using `every()`: `every()` stops at the
 * first mismatched byte, which is exactly the early exit this function exists to avoid.
 */
function timingSafeEqual(a, b) {
  const enc = new TextEncoder();
  const x = enc.encode(a), y = enc.encode(b);
  if (x.byteLength !== y.byteLength) return false;
  if (crypto.subtle && crypto.subtle.timingSafeEqual) {
    return crypto.subtle.timingSafeEqual(x, y);
  }
  let diff = 0;
  for (let i = 0; i < x.byteLength; i++) diff |= x[i] ^ y[i];
  return diff === 0;
}
