# The Document Root — the one fact behind the 403 and the 500

Two outages, reported days apart, that looked unrelated:

> There's an issue with the attached htaccess file. It 403s. I replaced the htaccess
> that 403s with the one below, but the site 500s.

Both were the same cause: **the web server's DocumentRoot points at the project root,
not at `./public`.**

---

## 1. Why that produces exactly those two symptoms

Apache reads `.htaccess` from the DocumentRoot **downward, never above it**. So the
project-root `.htaccess` only has any effect at all when the DocumentRoot is the project
root. That is the diagnosis, and it is also why nothing else pointed at it.

### The 403

The project-root `.htaccess` was:

```apache
<IfModule mod_authz_core.c>
  Require all denied
</IfModule>
```

Written as defence-in-depth *for this exact misconfiguration* — so that if a host ever
served the project root, `.env` and the database could not be downloaded. On a host where
the misconfiguration is real, a deny-all at the DocumentRoot is not a safety net. It is
the site being switched off. Every URL, 403.

### The 500

Replacing that file with a copy of `public/.htaccess` looks obviously right and is
obviously wrong. Its last rule is:

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

At the project root there is no `index.php` — it is in `public/`. So the rule rewrites
every request to a path that does not exist, which does not match `!-f` either, so it
rewrites again, until Apache hits the internal-redirect limit and returns 500.

---

## 2. The actual fix (do this)

**cPanel → Domains → Manage → Document Root → append `/public`.**

```
/home/afrovang/africa-gates            ← wrong
/home/afrovang/africa-gates/public     ← right
```

Nothing else needs to change. `/ping` will then report `"docroot":"public (correct)"`.

If the host offers no way to change it, the mitigation below keeps the site working —
but read §4 first, because it is genuinely weaker.

---

## 3. The mitigation that is now shipped

The project-root `.htaccess` is a **forwarder**, so the site is correct under both
configurations:

| DocumentRoot | What happens |
|---|---|
| `./public` | The root `.htaccess` is never read. Zero effect. |
| project root | Requests are forwarded into `public/`; the source tree is refused. |

Four layers, deliberately not all depending on the same module:

| Layer | Mechanism | Survives |
|---|---|---|
| 1 | `RedirectMatch 404` on sensitive path prefixes (mod_alias) | mod_rewrite being off |
| 2 | `<FilesMatch>` + `Require all denied` on sensitive filenames, at every depth | mod_rewrite being off |
| 3 | A deny-all `.htaccess` **inside** each non-public directory | the root file being edited or emptied |
| 4 | `RewriteRule ^(.*)$ public/$1` (mod_rewrite) | — |

Layer 3 matters most and is the least obvious: an operator whose site returns 403 for
every URL will empty the root `.htaccess` — that is the correct instinct — and that must
not publish the source tree. `bin/`, `config/`, `cron/`, `database/`, `docs/`,
`resources/`, `src/`, `templates/`, `tests/`, `var/` and `vendor/` each carry their own
deny.

### Verified against a real Apache, both ways

Not reasoned about — run. Apache 2.4.58, `AllowOverride All`, `mod_access_compat`
**disabled** (the stock 2.4 default, so the compatibility guards were actually exercised):

```
DocumentRoot = project root          DocumentRoot = public/
  /                        200         /                        200
  /vote                    200         /vote                    200
  /awards                  200         /awards                  200
  /ping                    200         /ping                    200
  /assets/…/logo.svg       200         /assets/…/logo.svg       200
  /robots.txt              200         /robots.txt              200
  /no-such-page            404         /no-such-page            404
  /africa-gates/vote       301 → /vote  /africa-gates/vote      301 → /vote
  /public/vote             301 → /vote

  /.env                    403
  /config/container.php    403
  /src/routes.php          403
  /vendor/autoload.php     403
  /composer.json           403
  /templates/…/vote.twig   403
  /bin/console             403
  /database/schema.sql     403
  /var/logs/app.log        403
```

### Two bugs that only real testing found

**`public/.htaccess` hardcoded `RewriteBase /`.** That is only true when the DocumentRoot
*is* `public/`. With the DocumentRoot at the project root, this directory is served at
`/public/`, so every pretty URL was rewritten to `/index.php` — a path that does not
exist there. It worked anyway, because the root forwarder caught the stray `/index.php`
and prefixed it: correctness resting on a three-hop accident. `RewriteBase` is gone;
mod_rewrite derives the prefix from the directory's own URL path, which is right at either
depth and takes one hop.

**mod_rewrite rules are not inherited.** Unlike almost every other `.htaccess` directive,
a subdirectory's `RewriteRule` set *replaces* the parent's rather than merging. So a
`/public/… → /…` canonical redirect written in the project-root file is dead code for any
request that already names `public/` — found by `LogLevel rewrite:trace5` after the first
attempt silently did nothing. It lives in `public/.htaccess` now.

---

## 4. Why you should still fix the DocumentRoot

The forwarder makes the site work. It does not make the deployment right:

- The whole tree — `.env`, the SQLite database if you use one, `var/logs/`, `vendor/`,
  every migration script — is physically **inside the web root**. It is unreadable
  because of `.htaccess` rules, not because it is unreachable.
- Those rules are one `AllowOverride None`, one host migration, or one nginx away from
  not applying. Files outside the web root do not have that failure mode.
- It survives a mod_rewrite outage (layers 1–3) but not a server that ignores `.htaccess`
  entirely.

`app:doctor` and `/ping` both report it, and `app:doctor` raises it as a problem, so it
cannot quietly become permanent now that the symptoms are gone.

---

## 5. Checking it without a shell

```
https://your-site/ping
```

```json
{"rev":"…","csp_nonce":true,"root":"34bbadc1968a","docroot":"public (correct)","php":"8.4"}
```

`docroot` is one of:

| Value | Meaning |
|---|---|
| `public (correct)` | Nothing to do. |
| `project-root (WRONG …)` | The forwarder is carrying the site. Fix it in cPanel. |
| `elsewhere (WRONG …)` | The DocumentRoot is **a different copy of the app** — the shape of "the deploy is not the tree you edited". |

That last value is worth taking seriously: it is the same failure that
`docs/DEPLOY-NOT-TAKING-EFFECT.md` documents, where a syntax error planted on the server
changed nothing because PHP was not loading the file at all.

---

## 6. Rules for editing any `.htaccess` in this repo

Three site-wide outages have come out of these files. `tests/Unit/DocumentRootTest.php`
pins every property below, and each guard was verified to fail when the corresponding
mistake is reintroduced.

1. **Never put an unscoped `Require all denied` / `Deny from all` at the project root.**
   Inside a `<FilesMatch>` it is correct and expected; at file scope it is the 403.
2. **Never put `RewriteRule ^ index.php` anywhere but `public/`.** It is the 500.
3. **Never use a directive a shared host may reject.** An unknown directive in
   `.htaccess` is a 500 for every URL beneath it. Already established the hard way:
   - `Header always setifempty` — Apache 2.4.7+, **not implemented by LiteSpeed**, which
     is what a large share of cPanel hosts run.
   - `<IfVersion>` does not rescue it — that is itself a directive the server must
     understand (mod_version).
   - `Options -ExecCGI`, `php_flag`, `php_value` — need an `AllowOverride` the host may
     not grant, and `php_flag` does nothing under php-fpm regardless.
4. **Gate every Apache 2.2 directive.** `Order`, `Deny from`, `Allow from` are
   `mod_access_compat`, which stock Apache 2.4 does **not** load. Unguarded,
   `Invalid command 'Order'` is a site-wide 500. Write the 2.4 form inside
   `<IfModule mod_authz_core.c>` with the 2.2 form in the `<IfModule !mod_authz_core.c>`
   fallback.
5. **Never add a path to the denylist that `public/` serves.** A
   `RedirectMatch 404 ^/+assets` 404s every stylesheet on the site, from a rule that
   reads as a security improvement.
6. **Test it against a real Apache before shipping.** Reasoning about `.htaccess` is how
   all three outages happened. `mod_access_compat` disabled and
   `LogLevel rewrite:trace5` are what turn a guess into an answer.
