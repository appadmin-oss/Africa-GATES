# Front-end performance

Baseline, Lighthouse mobile (Emulated Moto G Power, 2026-07-30):

| metric | before |
| --- | --- |
| Performance | **75** |
| First Contentful Paint | 3.3 s |
| Largest Contentful Paint | 4.4 s |
| Speed Index | 5.1 s |
| Total Blocking Time | 0 ms |
| Cumulative Layout Shift | 0 |

TBT and CLS were already perfect. Everything wrong was **paint being blocked on the
network**, and Lighthouse said so directly: *Render-blocking requests — est. savings of
2,360 ms*.

---

## 1. What was blocking the paint

The page head declared **fifteen render-blocking stylesheets**:

- ten local files, ~314 KiB **unminified** (`main.css` alone is 175 KiB);
- four from three different third-party CDNs (unpkg, jsdelivr, cdn.plyr.io);
- the Google Fonts stylesheet.

Plus **seven `preconnect` hints**, which Lighthouse warned about by name: *"More than 4
preconnect connections were found."*

On the connection this platform's audience actually has, the per-request latency
dominates the bytes. Fifteen serialised DNS/TCP/TLS/parse cycles before the first pixel
is the 2,360 ms.

## 2. What changed

### One bundled, minified stylesheet (`Support\AssetBundle`)

Fifteen local files → **one**. 314 KiB → 210 KiB (33% smaller).

Verified: **zero differing pixels** between the bundled and unbundled render of the same
page, compared programmatically at 412×900.

Built by `bin/console assets:build`. See §4 for why it is a PHP bundler and not a Node
one, and §3 for the property that makes it safe to forget.

### Third-party component CSS made non-blocking

The four CDN stylesheets style JS-initialised widgets — a Swiper carousel, a Splide
marquee, a Plyr video player, a Leaflet map. None is above the fold on most pages, and
none functions at all without its JavaScript. They now load with `media="print"` (fetched
at lowest priority, does not block paint) and a nonce'd script promotes them to
`media="all"` on idle.

**Not `onload="this.media='all'"`**, which is the recipe everywhere on the web: that is an
inline event handler, and this site's CSP is nonce-based with no `script-src-attr
'unsafe-inline'`. The browser refuses to run it and the styles never apply. A
CSP-correct site has to do this with a nonce'd script — verified in a real browser that
all four flip to `all`.

**The trade:** with JavaScript off, those four stylesheets never apply. That costs nothing
real — the widgets they style are inert without JavaScript anyway, and the no-JS paths
this platform genuinely supports (voting, nomination, the activity search) use none of
them.

### Preconnects cut from seven to two

Kept: `fonts.googleapis.com` and `fonts.gstatic.com`, because the font stylesheet is
render-blocking and gstatic serves the files it references — both are on the critical
path.

Demoted to `dns-prefetch`: the three script CDNs and the two media hosts. Every request
to those is now `defer`red or non-blocking, so resolving DNS early is all they need; the
connection can wait until something wants it. DNS is the slow part on mobile and
prefetching it is nearly free.

### Render-blocking stylesheets: 15 → 2

The bundle, and Google Fonts. Confirmed against a live server.

## 3. Forgetting the build is safe

`public/assets/dist/` is gitignored build output, so a fresh clone or a fresh deploy has
no bundle. `AssetBundle::url()` returns `null` on **any** doubt — no manifest, missing
file, or *any source stylesheet newer than the build* — and the layout falls back to the
fifteen individual `<link>`s.

So the failure mode of skipping `assets:build` is **correct but slow**, never wrong. That
matters more than it sounds: the alternative design (serve the bundle if the file exists)
is how a developer edits `nav.css`, sees nothing change, and loses an hour.

`bin/console app:doctor` reports it, and lists it as a problem when it is missing or
stale, because it is the one performance setting that is invisible from the outside and
reverts silently.

Verified live: touching `a11y.css` flips the page back to 15 links; re-running
`assets:build` flips it back to 1.

## 4. Why a PHP bundler

This deploys to shared cPanel: no Node, frequently no shell, and `composer update` is a
gamble. **A build step that cannot run where the code runs is a build step that will be
skipped**, and the failure mode of a skipped CSS build — if it were mandatory — is a site
with no styling.

So: PHP, runnable three ways.

```bash
bin/console assets:build          # normal
bin/console assets:build --check  # CI / post-deploy verification; non-zero if stale
```

No shell at all? `GET /__setup/assets?token=<SETUP_TOKEN>` — same token gate and same
404-without-it behaviour as `/__setup/migrate`, which exists for exactly this reason.

**Add `assets:build` to your deploy steps**, after uploading files and alongside
`db:migrate`.

## 5. The minifier, and the bug it shipped with

`AssetBundle::minify()` is deliberately conservative: it strips comments, collapses
whitespace, and removes the space around `{ } ; ,` plus the final semicolon in a block. It
does **not** touch `+`, `~`, `>` or `:`, attempt colour shortening, unit stripping, or
rule merging.

The reason is a bug that was written, shipped into the built file, and caught by grepping
the output rather than by reading the code. The first version removed the space *after*
combinators. That is harmless in a selector (`a +b` is valid) and **invalid inside
`calc()`**, where the spec requires whitespace on both sides of `+` and `-`. It produced
three occurrences of `calc(100% +0.5rem)` in `main.css` — each of which a browser discards
as invalid, silently collapsing whatever it sized.

This is the single most common way a hand-rolled CSS minifier breaks a site, this
project's CSS uses `calc()` in 28 places, and the bytes the combinator rule would have
saved are a rounding error. Two tests now pin it — one on synthetic cases, one running
the real stylesheets through the real minifier — and both were confirmed to fail when the
bug is reintroduced.

Structural equivalence is also asserted per file: brace balance and the counts of `{`,
`}`, `calc(`, `var(`, `!important`, `url(`, `@media`, `@keyframes`, `@supports`,
`@font-face` must be identical before and after.

## 6. Cascade order is the correctness argument

CSS is order-dependent and this project relies on it: `a11y.css` is last so its WCAG
corrections override everything, and the legacy sheets are layered under the newer modular
ones.

`AssetBundle::STYLESHEETS` is the **single** source of that order. The layout's fallback
lists the same files in the same order, and `AssetBundleTest` parses the template and
asserts the two are identical — because a divergence would appear **only** on deployments
that ran the build, and look fine on every developer machine.

## 7. Still open, and honestly not fixed

**Reduce unused CSS — est. 28 KiB.** Real, and untouched. Every page still receives every
rule, because the sheets are global and the selectors are not attributed to pages. Fixing
it means per-page CSS or a critical-CSS inline step, which needs to know which selectors
each template actually uses. That is a real project with real regression risk, not a
tightening.

**No HTML is edge-cacheable.** Every page carries a per-request CSP nonce, so no CDN may
cache a page. At continental traffic this is the largest single lever still unpulled, and
it is a genuine trade-off: edge-cacheable HTML needs nonce-free pages, i.e. moving the
remaining inline scripts to files and switching `style-src-elem` to hashes. Already
recorded in `SecurityHeadersMiddleware::applyCachePolicy()`.

**The Google Fonts request is large.** Four families, and Playfair Display alone asks for
nine weights in both roman and italic. Trimming to the weights actually used would be a
real saving, but it needs an audit of the CSS and templates to avoid silently losing a
weight somewhere.

**Minify JavaScript — est. 4 KiB.** Not attempted. `alpine-3.13.5.min.js` is already
minified and the two first-party files are small, so the return does not justify
hand-rolling a JS minifier in PHP — ASI, regex literals and template strings make that a
genuinely hazardous thing to get right.

**Forced reflow** and **31 non-composited animations** were also flagged. Both are
JavaScript/animation work in `main.css` and the GSAP setup, independent of everything
above, and neither affects the score directly (TBT is already 0 ms).

## 8. Re-measuring

The numbers in this document are the *before*. Re-run Lighthouse against the deployed
site **after** `assets:build` has run there — measuring locally, or on a deploy where the
bundle is missing, measures the fallback path and will show no improvement at all.
