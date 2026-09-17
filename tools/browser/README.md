# Browser verification

Static analysis cannot tell you whether a Content-Security-Policy actually works. A
missing nonce produces no server error and no failing PHPUnit test — the browser just
refuses to run the script and the page quietly loses a feature. These two checks load
the real site in real Chromium and watch the console.

```sh
npm install playwright          # once; Chromium is already at $PLAYWRIGHT_BROWSERS_PATH

# serve the app with static assets AND multiple workers (both parts matter — see below)
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8125 -t public tools/browser/dev-router.php &

BASE_URL=http://127.0.0.1:8125 node tools/browser/csp-check.js       # 17 public pages
BASE_URL=http://127.0.0.1:8125 node tools/browser/handlers-check.js  # the delegated handlers
BASE_URL=http://127.0.0.1:8125 node tools/browser/admin-check.js     # 37 authenticated pages
```

Both exit non-zero on failure, so they can gate a deploy.

## Why `dev-router.php` exists, and why it is not optional

`php -S … -t public public/index.php` routes **every** request through Slim, which
404s every static asset. The first run of `csp-check.js` reported **17 pages, 0 CSP
violations** — and it was worthless, because Alpine and every vendored script had
404'd, so no JavaScript executed at all. A green CSP result on a page with no scripts
proves nothing.

`dev-router.php` returns `false` for real files so PHP serves them, and hands
everything else to the app. With it, `window.Alpine` is defined and the checks are
meaningful. If you see `Alpine loaded: false`, the assets are not being served and the
result should be discarded.

## What `handlers-check.js` covers

The ten inline `on*=` attributes removed for the CSP became one delegated listener on
`data-ag-do`. Rather than depend on whichever page renders a settings-gated widget, it
injects one element per action and drives the listener directly — `select-all`,
`dismiss-parent`, `copy-url` (label feedback and restore), `open-gee` (both the
`window.openGee` path and the `#geeFab` fallback) and `set-cookie-reload`.

Two things that make this harder than it looks, both learned the hard way:

- `set-cookie-reload` calls `location.reload()`, which destroys the execution context
  and kills any `page.evaluate()` still running. It is asserted from **outside** the
  page, via `context().cookies()`.
- `open-gee` must be checked against the **real** `window.openGee`, which
  `public/assets/js/gee.js` defines at runtime. Three earlier versions of that check
  asserted the `#geeFab` fallback fired and "failed" — because grepping only
  `templates/` had suggested `window.openGee` did not exist. Both branches are now
  covered explicitly.

## Why `PHP_CLI_SERVER_WORKERS` is required

`php -S` is single-threaded: it serves one request at a time. A browser loading a page
opens several subresource requests at once, and after a dozen pages the server wedges —
navigation then times out on `domcontentloaded` and the run dies with an error that
looks like a page fault but is really the server refusing to answer.

`PHP_CLI_SERVER_WORKERS=8` forks workers and the problem disappears. Without it these
checks are flaky in a way that reads as a code failure.

## `admin-check.js` — the surface the public sweep cannot see

`admin/dashboard` and `admin/settings` carry more inline `<script>` blocks than any
other templates, and `admin/settings` and `admin/webhooks/form` hold two of the ten
converted `select-all` handlers. None of that is reachable without a session, so until
this check existed those pages were verified only by scanning source — which cannot
tell you whether the browser accepted the nonce.

It logs in first and reports `login: OK/FAILED` with the resulting URL, then sweeps 34
admin routes plus the judge, account and donate entry points. Needs an admin:

```sh
bin/console admin:create admin@example.com "Test Admin" 'Str0ng-Pass-123' --role=superadmin
ADMIN_EMAIL=admin@example.com ADMIN_PASSWORD='Str0ng-Pass-123' \
  BASE_URL=http://127.0.0.1:8125 node tools/browser/admin-check.js
```

Two guards that exist because the first version lacked them:

- **A non-200 is a failure, not a pass.** The first route list had four invented paths
  (`/admin/shop`, `/admin/donations`, `/admin/analytics`, `/admin/cron`). They 404'd and
  the sweep reported them `ok` — a 404 page passes a CSP check trivially. The list is
  now enumerated from `src/routes.php` and any non-200 counts as a violation.
- **Pages that bounce to the login form are counted separately** (`redirected to login`).
  Without a session every `/admin/*` URL redirects, so the sweep would "pass" 34 pages
  while only ever seeing the login form. A non-zero count there invalidates the run.
