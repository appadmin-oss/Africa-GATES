# "I fixed it, and production still does the old thing"

This has now happened three times, and every time it looked like an application bug.
Read this first the next time production behaviour does not match the code.

---

## The symptom you will actually see

A browser console full of CSP refusals:

```
Loading the stylesheet 'https://unpkg.com/swiper@8.4.7/swiper-bundle.min.css' violates
the following Content Security Policy directive: "style-src 'self' 'unsafe-inline'
https://fonts.googleapis.com". Note that 'style-src-elem' was not explicitly set…

Loading the script 'https://code.jquery.com/jquery-3.7.1.slim.min.js' violates the
following Content Security Policy directive: "script-src 'self' 'unsafe-inline'
'unsafe-eval'".

Sending form data to 'https://afg.afrovanguard.org.ng/vote/paid/start' violates the
following Content Security Policy directive: "form-action 'self'".
```

Ten to twenty blocked CDN resources, and — the expensive one — **every paid vote
blocked**, after the pending order row has already been written.

## It is not a CSP bug. Read the quoted policy.

The policy in those messages is **not the one this repository produces**. Compare:

| | quoted by the browser | `Csp::policy()` in this tree |
| --- | --- | --- |
| `script-src` | `'self' 'unsafe-inline' 'unsafe-eval'` | `'self' 'nonce-…' 'unsafe-eval'` + 8 hosts |
| `style-src` | `'self' 'unsafe-inline' https://fonts.googleapis.com` | + jsdelivr, unpkg, cdn.plyr.io |
| `style-src-elem` | absent ("was not explicitly set") | explicitly set |
| `form-action` | `'self'` | `'self'` + Paystack + Flutterwave |

**No nonce anywhere.** That single fact is conclusive: this tree cannot emit a
nonce-free policy. The running code predates the CSP rewrite entirely.

It also carries `permissions-policy: … camera=(self), payment=()`, which **no version in
this repository has ever emitted.**

## The proof, from the first time

`Csp::policy()` was edited **on the server** with a deliberate syntax error — `return "…"`
immediately followed by a statement. The site kept returning **HTTP 200 with the old
header**.

A syntax error in a file PHP loads is a fatal, not a no-op. **The file was not being
loaded.** Every minute after that spent reading the policy was spent reading the wrong
copy.

## Diagnose it in one request

```sh
curl -s https://afg.afrovanguard.org.ng/ping
```

```json
{
  "status": "ok", "app": "Africa GATES", "ts": "…",
  "rev": "2026-07-30.4-csp-nonce-assets",
  "csp": "86a7f8072e05",
  "csp_nonce": true,
  "root": "34bbadc1968a",
  "php": "8.4"
}
```

| what you see | what it means |
| --- | --- |
| `rev`/`csp`/`root` **missing entirely** | the deployed tree predates `Support\Build` — it is not this code |
| `"csp_nonce": false` | the running policy is not nonce-based — it predates the CSP rewrite |
| `root` unchanged after moving DocumentRoot | the switch did not land |
| `rev` not the value you deployed | an older tree is serving |

With a shell, `app:doctor` does the comparison for you — it fetches `APP_URL` and diffs
the **actual live header** against the policy this code produces:

```sh
php bin/console app:doctor
```

Look for `csp → live_check`. On a mismatch it prints both policies and names the three
causes below. (The nonce is normalised out before comparing — it is per-request, so a
byte comparison would report a mismatch on every healthy deployment. A diagnostic that
always fires is one people learn to ignore.)

## Three causes, fixed completely differently

### 1. DocumentRoot points at an older copy — the best fit

The classic shape: the repo is deployed to `~/africa-gates/`, and the vhost still serves
`~/public_html/` where an older copy lives. Editing files in the deploy has no effect
because nothing reads them.

```sh
# What is Apache actually serving?
grep -ri documentroot /usr/local/apache/conf/httpd.conf | grep -i afrovanguard
# Or, from inside the app, compare the `root` hash on /ping before and after.
ls -la ~/public_html/index.php ~/africa-gates/public/index.php
```

**DocumentRoot must point at `public/`** — the repository root contains `.env`, the SQLite
database and the migration scripts, and its `.htaccess` denies everything precisely
because a misconfigured root would otherwise serve them.

### 2. Opcache is holding the previous compile

With `opcache.validate_timestamps=0` — common in production — PHP never re-reads a
changed file. An edit, including a syntax error, has no observable effect until the pool
is reloaded.

```sh
php bin/console app:doctor    # opcache → validate_timestamps
```

Fix: restart PHP-FPM, or in cPanel use *Software → MultiPHP Manager* / *Restart PHP*. Not
`opcache_reset()` from a web request — that resets the **web** SAPI's cache, which is what
you want, but only if the web SAPI is the one you think it is.

### 3. A proxy or CDN is replacing the header

Cloudflare Transform Rules (*Rules → Transform Rules → Modify Response Header*), or a
cPanel/WHM "security headers" module, or ModSecurity. Test the origin directly, bypassing
the CDN:

```sh
curl -sI --resolve afg.afrovanguard.org.ng:443:<ORIGIN_IP> \
     https://afg.afrovanguard.org.ng/ping | grep -i content-security-policy
```

If the origin returns the correct policy and the public URL returns the old one, it is
being injected downstream. `app:doctor` reports `live_headers_seen: 2` when two policies
are present — the browser enforces the **intersection**, so each can look fine alone
while the combination blocks everything.

## Why the paid-vote failure deserves its own note

`form-action 'self'` blocking `https://afg.afrovanguard.org.ng/vote/paid/start` reads
like a browser bug: the URL is same-origin, which plainly satisfies `'self'`.

It is refused because **Chrome applies `form-action` to the redirect a submission lands
on**, and `POST /vote/paid/start` writes a pending order and then 302s to the gateway's
hosted checkout. A policy without the gateway hosts therefore blocks **every paid vote**
— after the money row exists — and attributes the violation to the same-origin URL the
user submitted. Revenue, silently, with nothing in the server logs.

That is why `Csp::PAY_HOSTS` is in `form-action`, and why `CspHostCoverageTest` keeps all
fifteen originally-refused URLs as a verbatim fixture.

## What this branch changed so the problem is smaller when it recurs

Fewer things now depend on the policy being current, because they no longer depend on a
third-party host at all:

- **Popper and Tippy are self-hosted.** Both files had been committed under
  `public/assets/js/vendor/` all along while both layouts fetched them from unpkg —
  paying a cross-origin round trip for bytes already on disk. `'self'` is permitted by
  every policy this site has ever served, **including the stale one still live**, so
  these work regardless of which CSP is in force.
- **split-type is self-hosted.** It was loaded from
  `cdn.jsdelivr.net/gh/timothydesign/script/split-type.js` — an **unversioned** file in a
  personal GitHub repo, which jsdelivr serves from the default branch HEAD. An unrelated
  third party could have changed code this site executes at any moment. Vendored at
  0.3.4.
- **Every remaining CDN URL is version-pinned.** `unpkg.com/lucide@latest` was the worst
  of these — `@latest` is an instruction to run whatever gets published next — along with
  `swiper@8`, `tippy.js@6` and `@popperjs/core@2`. Each is pinned to the version it
  already resolved to, so nothing served changes today and nothing changes underneath you
  tomorrow. `DeployFingerprintTest` fails the suite on a new unpinned URL, `src` or
  `href`.

Still remote and necessarily so: Turnstile (Cloudflare requires their host), jQuery, GSAP
+ ScrollTrigger, Leaflet, Swiper, Splide and Plyr. Vendoring those is a reasonable next
step and would remove the last of the `script-src`/`style-src` host allowlist — but it
would **not** fix the live site, because the deployed tree's templates would still point
at the CDNs. Fix the deploy first.
