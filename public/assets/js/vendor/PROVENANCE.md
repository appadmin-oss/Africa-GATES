# Vendored third-party assets — where they came from

Every file here is an unmodified copy of a published npm package's own build output,
fetched from `registry.npmjs.org` at a pinned version. Nothing here was downloaded from a
CDN, hand-edited, or re-minified — with one documented exception, noted below.

**Why they are vendored rather than loaded from a CDN.** They used to be fetched from
`cdn.jsdelivr.net`, `unpkg.com` and `cdn.plyr.io`, mostly with no Subresource Integrity, on
hosts that `src/Support/Csp.php` names in `script-src` — so the browser would execute
whatever those hosts served. Two of them could not have been pinned even in principle:
`nprogress@0.2.0` ships no `.min.js`, so the CDN was generating minified bytes on the fly,
and `stripe-gradient` was loaded through jsDelivr's `+esm` transform, which is also
CDN-generated output. Serving our own copies removes the supply-chain exposure and the
availability dependency in one move, and `tests/Unit/ThirdPartyScriptIntegrityTest.php`
keeps it that way.

## How to reproduce or update any file

```sh
npm pack <package>@<version>        # from registry.npmjs.org
tar xzf <tarball>                   # extracts to ./package
cp package/<path-inside-package> public/assets/js/vendor/<local-name>
```

Then bump the version in the filename **and** in the template that references it, so the
two can never drift apart silently.

## JavaScript

| Local file | Package | Path inside the package |
|---|---|---|
| `alpine-3.13.5.min.js` | `alpinejs@3.13.5` | `dist/cdn.min.js` |
| `gsap-3.12.5.min.js` | `gsap@3.12.5` | `dist/gsap.min.js` |
| `gsap-scrolltrigger-3.12.5.min.js` | `gsap@3.12.5` | `dist/ScrollTrigger.min.js` |
| `lottie-web-5.12.2.light.min.js` | `lottie-web@5.12.2` | `build/player/lottie_light.min.js` |
| `lucide-1.28.0.min.js` | `lucide@1.28.0` | `dist/umd/lucide.min.js` (the `unpkg` field) |
| `nprogress-0.2.0.js` | `nprogress@0.2.0` | `nprogress.js` — see note |
| `plyr-3.7.8.polyfilled.js` | `plyr@3.7.8` | `dist/plyr.polyfilled.js` |
| `popper-2.11.8.min.js` | `@popperjs/core@2.11.8` | `dist/umd/popper.min.js` |
| `splide-4.1.4.min.js` | `@splidejs/splide@4.1.4` | `dist/js/splide.min.js` |
| `split-type-0.3.4.min.js` | `split-type@0.3.4` | `umd/index.min.js` |
| `swiper-8.4.7.bundle.min.js` | `swiper@8.4.7` | `swiper-bundle.min.js` |
| `tippy-6.3.7.umd.min.js` | `tippy.js@6.3.7` | `dist/tippy.umd.min.js` |

**`nprogress-0.2.0.js` is the unminified file**, because the package does not ship a
minified one. The old URL asked jsDelivr for `nprogress.min.js`, which the package has
never contained — jsDelivr was minifying it on request. That is 11 KB instead of ~4 KB and
it is the authentic published file, which is the better trade.

**`stripe-gradient-1.0.1/` is the one modified copy.** The package's `main` is CommonJS
(`module.exports`), which a browser `import()` cannot load, and its ESM source under `src/`
uses extensionless specifiers (`from './Gradient'`) that browsers do not resolve. The four
`src/*.js` files are copied verbatim except that three relative specifiers gained a `.js`
suffix. Diff against `npm pack stripe-gradient@1.0.1` to confirm that is the only change.

## CSS (`public/assets/css/vendor/`)

| Local file | Package | Path inside the package |
|---|---|---|
| `nprogress-0.2.0.css` | `nprogress@0.2.0` | `nprogress.css` |
| `plyr-3.7.8.css` | `plyr@3.7.8` | `dist/plyr.css` |
| `splide-4.1.4.min.css` | `@splidejs/splide@4.1.4` | `dist/css/splide.min.css` |
| `swiper-8.4.7.bundle.min.css` | `swiper@8.4.7` | `swiper-bundle.min.css` |
| `tippy.css` | `tippy.js@6.3.7` | `dist/tippy.css` |

## Still loaded from a third party, on purpose

* **Leaflet 1.9.4** (JS + CSS, `unpkg.com`) — pinned with `integrity` + `crossorigin`, so a
  swapped file fails closed. Vendor it too if the availability dependency ever bites.
* **jQuery 3.7.1 slim** (`code.jquery.com`) — pinned the same way.
* **Cloudflare Turnstile** and **Google AdSense** — versionless endpoints their vendors
  update in place. There is no stable hash to pin, and pinning one would break the widget
  on the vendor's next deploy.
* **Google Fonts** — the served CSS varies by user agent, so it has no fixed hash either.
