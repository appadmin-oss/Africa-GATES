# Production-readiness QA sweep

Static tests and unit tests cannot see whole classes of production defect. These
scripts drive the real application in a real browser against a real MySQL database.

```sh
# database + app
mysqld --user=mysql --datadir=/var/lib/mysql &
bin/console db:migrate --no-interaction
bin/console admin:create admin@example.com "QA Admin" 'Str0ng-Pass-123' --role=superadmin

# server — BOTH parts matter, see tools/browser/README.md
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8125 -t public tools/browser/dev-router.php &

npm install playwright
BASE_URL=http://127.0.0.1:8125 node tools/qa/journeys.js               # journeys, XSS submit, 404
BASE_URL=http://127.0.0.1:8125 node tools/qa/xss.js                   # stored XSS on admin surfaces
QA_PHASE=responsive BASE_URL=… node tools/qa/quality.js               # overflow at 360/768/1440
QA_PHASE=a11y       BASE_URL=… node tools/qa/quality.js               # accessible names, labels
QA_PHASE=weight     BASE_URL=… node tools/qa/quality.js               # requests and bytes
```

`quality.js` is phased because one combined run exceeded the timeout and reported
nothing at all — a sweep that times out is worse than a smaller one that finishes.

## Traps that made earlier runs report false results

Every one of these produced a green result that meant nothing. They are the reason
this file exists.

1. **A seed that failed silently.** `mysql … 2>/dev/null` hid `Unknown column
   'is_verified'`, which aborted the whole batch. The seed printed "seeded", the
   tables were empty, and the XSS sweep then "passed" against no data. **Never
   suppress stderr on a fixture.** Assert row counts afterwards.
2. **XSS payloads that were never stored.** The nominate form requires thirteen
   fields; a partial POST returns 422. The sweep reported "no injected script
   executed" because nothing had been injected. `journeys.js` now checks a CSRF
   token is present, and the stored count is verified in SQL before conclusions are
   drawn.
3. **Matching escaped output and calling it a vulnerability.** A regex for
   `window.__xss` matched the correctly-escaped
   `&lt;script&gt;window.__xss=1&lt;/script&gt;` in a `data-` attribute. `xss.js`
   now asks the DOM whether the parser actually *built* an element — a `<script>`
   containing the payload, or a live `onerror`/`onload` attribute.
4. **Flagging `aria-hidden` controls.** The a11y check reported a nameless button on
   every page: `.gee-scrim`, an `aria-hidden="true" tabindex="-1"` overlay no screen
   reader ever announces. Correctly named controls are not defects.
5. **Assets 404ing behind the app router.** See `tools/browser/README.md`. If
   `Alpine loaded: false`, discard the run.

## What this sweep does NOT cover

Stated so a green run is not mistaken for completeness:

- **Payment completion.** No live gateway, so checkout is verified only up to the
  redirect. The confirm/webhook path and refunds are covered by unit tests only.
- **Email delivery.** No SMTP. OTP and notification *sending* is untested end to end;
  the OTP consume path is covered by unit tests.
- **The OTP vote journey**, which needs a code from an email.
- **Judge scoring**, which needs a judge invited by email.
- **One browser engine.** Chromium only. Safari's older `style-src-elem` /
  `style-src-attr` handling is the likeliest place for a CSP surprise.
- **Load and concurrency.** `php -S` is a dev server; the timings it reports are
  meaningless. Nothing here measures behaviour under real traffic.
- **Real data volume.** A handful of seeded rows will not surface an N+1 query that
  only hurts at ten thousand nominees.
