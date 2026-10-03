# DESTROYED — the old public pages, 3 Oct 2026

**Owner's decision (3 Oct 2026):** "Destroy old pages now — revert the in-place edits and delete every old template and stylesheet that read the retired names. Those pages stop working until each phase rebuilds them from its design file." Phase 1 (`a9d963a`) had *patched* ~95 old files by renaming retired colour variables, and *moved* old rules into `components/chrome.css` and `components/library.css`. That was a patch; this is the destroy. Nothing here was reverted and kept — every qualifying file is gone (`git rm`). Base commit: `a9d963a`.

## What qualified

A public template (`templates/pages/**`, `templates/partials/**`, `templates/layout/*` other than `shell.twig`) or a public stylesheet/script under `public/assets/` — never a Phase 1 rebuilt file (`Support\Accent`, `tokens.css`, `shell.css`, `components.css`, `dev-ui.css`, `shell.js`, `layout/shell.twig`, `pages/dev-ui.twig`, `Support\Translator`) — AND one of:

- **(a)** `a9d963a` modified it in place and its pre-image (`a9d963a^`) reads a retired name (`--ag-honour-*`, `--ag-action-*`, `--ag-caution-*`, `--ag-fault-*`, `--ag-ink-soft`, `--ag-surface-2`, `--ag-card`, `--ag-line-strong`, `--ag-bg`, `--ag-paper`, `--ag-desk`, `--ag-pulse`, `--ag-green-dark`, `--ag-error-wash`, `--ag-live-fill/-edge`, `--ag-font-body`, `--ag-fs-{hero,h2,h3,body,sm,label}`, `--ag-r-{sm,md,lg}`, `--ag-dur`, `--ag-dur-fast`, `--ag-maxw`, `--ag-nav-h`, `--ag-mobile-nav-h`, `--ag-shadow-nav`, `--ag-tracking-label`, `--ag-z-loader`);
- **(b)** `a9d963a` created or extended it by MOVING old rules (`chrome.css`, `library.css`; `nominate.css` received `.ag-ai-note`);
- **(c)** it depends on a destroyed file so completely it cannot render: it extends/includes/imports a destroyed template (every page on `layout/gates.twig`, which itself includes the destroyed `partials/cookie-notice.twig` and links `chrome.css`/`library.css`), or its own page stylesheet was destroyed (`nominate*.twig` → `nominate.css`; `partials/{article,find-band,help-nav,globe-band}.twig` → their own sheets).

Note on `nominate*.twig`: they extend `layout/shell.twig` and include NO destroyed partial (site-header, app-bar, tab-bar, challenge-strip, ai-collection-notice and promo-carousel all survive). They qualify only through `components/nominate.css`, their page sheet, which received moved rules in `a9d963a`. Destroyed as instructed.

## Counts

| Kind | Destroyed |
|---|---|
| Page templates | 92 |
| Layouts | 2 (`gates.twig`, `account-auth.twig`) |
| Partials | 9 |
| Stylesheets | 22 |
| Scripts | 0 — no public script was renamed in `a9d963a` or moved; see "Orphaned" |
| **Total files** | **125** |

Feature inventories, taken from each file at `a9d963a^` before deletion, are in [`inventory/`](inventory/) — one file per page template and layout (`pages--vote-nominee.md`, `layout--gates.md` …), plus `_partials.md`, `_stylesheets.md` and `_cross-page-rules.md`. Each ends with **the rules held by the guard tests destroyed with it**: the rebuild re-asserts every one.

## What still renders

- **`/_dev/ui`** — `pages/dev-ui.twig` on `layout/shell.twig`. Its site header, app bar, tab bar, Menu and Quick settings partials survive but **render with base styles only**: their rules lived in `chrome.css`, destroyed. That is Phase 2's to rebuild.
- **`/door/{token}`** — `pages/events/door.twig` is standalone (no layout) and read no retired name.
- **Everything that is not a page**: the 86 301-aliases, payment hand-offs and callbacks, webhooks, `/ping`, `robots.txt`, `sitemap.xml`, `*.txt`/`*.md` legal documents, `status.json`, images/cards/fliers (GD), `.ics`, the API, and **every POST handler** — voting, payments, nominations, sign-in. A POST that answers with a redirect still works; a POST that re-renders its form on a validation error now 500s on that branch.
- **Admin and judge consoles** — untouched (own layouts, held below).

## What a request to a destroyed page returns now

Routes were left in place. Walked through the real router (DemoSeeder data, its programme flipped active), every public GET:

- A handler that renders a destroyed template throws `Twig\Error\LoaderError`. In production `public/index.php` installs `Handlers\ErrorHandler`, which tries `pages/error.twig` — also destroyed — and falls through to its last resort: **HTTP 500, body `<h1>500</h1><p>An internal error occurred.</p><p>Reference XXXX-XXXX</p>`**, and **`PublicFault::record()` writes a fault for every such request**. Expect the fault log (and anything alerting on it) to fill with these until pages are rebuilt.
- An unmatched URL: **404 with `<h1>404</h1><p>Not found.</p>`** (same fallback, no reference). This applies to admin 404s too.
- Branches that redirect before rendering (sign-in bounces, `/discover`, `/vote/{id}`, payment callbacks) are unchanged.
- In the walk, 66 public GET paths went 200/404/410 → 500; 18 still answer 200 (data, text documents, `/_dev/ui`, `/ping`).

## Held — no phase rebuilds these; owner to decide

Patched in place by `a9d963a` (retired names renamed) but **not destroyed**, because no phase of the redesign rebuilds them, so deleting them would be permanent:

- **Admin/judge templates (16):** `admin/{analytics,campaigns/index,events/tickets,finance,interviews/index,interviews/run,interviews/show,login,magic,payments-disputes,payments-ledger,payments-triage,questionnaires/index,support/index,support/show}.twig`, `judge/login.twig`.
- **Stylesheets shared with admin/judge:** `public/assets/css/a11y.css` (loaded by `admin/layout.twig`, `judge/layout.twig`), `public/assets/css/components/auth.css` (admin/judge sign-in screens). Both qualify under (a); destroying either breaks a held surface. `admin.css`, `judge.css` excluded by instruction.
- **`templates/partials/viz.twig`** — qualifies under (a) (`--ag-font-body`) but is included by `admin/stands/index.twig`.
- **`src/` behaviour/infrastructure patched by `a9d963a`:** `Services/{ChallengeFlier,EmailCampaign,InviteMailer,InviteReminders,Newsletter/Newsletter,Newsletter/NewsletterAudience,NomineeBroadcast,OtpService,QuestionnaireInvites,StandNotice}.php`, `Support/{Accent,AssetBundle,Languages,Translator}.php`, `config/container.php` — mail, GD, Accent consumers, i18n. Not pages.
- **Email templates (`templates/emails/`, 7 files):** none read a retired name and none includes a destroyed partial. Untouched.

## Orphaned by the destroy — kept, did not qualify

These read no retired name and include nothing destroyed, so they stay, but nothing renders or links them any more. Each phase destroys the ones it owns (GAPS §7.2):

```
## templates (public) with no includer and no renderer
templates/layout/footer.twig
templates/partials/account-payout.twig
templates/partials/ad-slot.twig
templates/partials/ai-collection-notice.twig
templates/partials/celebrate.twig
templates/partials/challenge-strip.twig
templates/partials/comments.twig
templates/partials/community-modal.twig
templates/partials/field.twig
templates/partials/flash.twig
templates/partials/lottie.twig
templates/partials/member-autofill.twig
templates/partials/org-page.twig
templates/partials/promo-carousel.twig
templates/partials/site-search.twig
templates/partials/support-prompt.twig
templates/partials/tile.twig
templates/partials/vote-countdown.twig
## public css/js not linked by any surviving template, src, or AssetBundle
public/assets/css/components/awards.css
public/assets/css/components/newsletter.css
public/assets/js/account.js
public/assets/js/afg-features.js
public/assets/js/favicon.js
public/assets/js/globe-band.js
public/assets/js/header.js
public/assets/js/nominate-share.js
public/assets/js/nominate.js
public/assets/js/passkeys.js
public/assets/js/promo-carousel.js
```

`layout/nav.twig` and the legacy sheets (`main.css`, `ui-overhaul.css`, `professional.css`, `redesign-2026.css`, `aurora.css`, `motion.css`, `tokens.motion.css`, `base/reset.css`, `components/promo.css`, `components/tile.css`) are referenced only by `AssetBundle::STYLESHEETS` or by each other; no surviving page loads them.

## Edits to Phase 1 files (the only non-test files rewritten)

- `templates/layout/shell.twig` — links `tokens.css`, `shell.css`, `components.css` and nothing else (dropped `chrome.css`, `library.css`, `forms.css`); header comment updated.
- `src/Support/AssetBundle.php` — `STYLESHEETS` lost the 17 destroyed sheets; comments rewritten.
- `public/assets/css/tokens.css` — removed `--ag-sp-20`, `--ag-sp-22`, `--ag-r-22`, `--ag-z-appbar`, `--ag-z-bar`, `--ag-z-header`, `--ag-z-gee`: their every reader was destroyed (see Token fallout).
- `pages/dev-ui.twig` needed no change.
- Outside Phase 1, one diagnostic: `src/routes.php` `/__setup/deployed` lost the 14 rows that checked a marker inside a destroyed template (a page deliberately gone is not a failed upload). The rows are listed in `inventory/_cross-page-rules.md`.

## Tests

- **548 test methods destroyed** (suite 7,127 → 6,580 = 7,127 − 548 + 1 new `DeadTokenTest` method), 25 whole files: `AccountTabsTest`, `ActivityPageAccessibilityTest`, `ChallengePageTest`, `CookieChoiceScreenTest`, `DonationGoalTest`, `EventFlierGeneratorTest`, `EventReferralPromptTest`, `EventTierSelectionTest`, `FormAccessibilityTest`, `GivingFormFlowTest`, `GuidedFormTest`, `HeadingHierarchyTest`, `HighlightToAskTest`, `InterviewPageTest`, `OrgConsoleLedeTest`, `PageRenderSmokeTest`, `PaidVoteReceiptTest`, `PartnerDashboardTest`, `PhaseSurfaceRenderTest`, `ProfileCpiClaimTest`, `PulseTimelineTest`, `SeoStructuredDataTest`, `SplashScreenTest`, `SupportHandoffLinksTest`, `VoteProgrammeLayoutTest`.
  Each method's rule is recorded in the inventory of the page it guarded. Besides the tests that failed, methods that read a destroyed file and then **passed on an empty string** were destroyed too (e.g. `assertStringNotContainsString` over `''`).
- **Sweeps kept and updated, never destroyed:** `ColourLiteralTest` baseline (80 entries gone), `ColourBudgetTest` (`UNDECLARED` emptied; band privilege and exclusive-state holders emptied; ballot half of the money rule removed), `ColourIsNeverAloneTest` (`BACKLOG` down to one entry), `DeadTokenTest` (new shrink-only `AWAITING_REBUILD`), `CookieRegistryTest` (repaired: now reads PSR-7 `Set-Cookie`, proven failing on a planted writer), `PublicIaTest` (learned the kind "a route whose every template is missing", proven failing), `SecurityHeadersTest` (microphone control moved to planted constraints), `CspHostCoverageTest` and `ThirdPartyScriptIntegrityTest` (no third-party script remains anywhere; controls inverted/narrowed), layout lists in `AccentTest`, `AdminContrastTest`, `OneMainLandmarkTest`, `ChromeReachabilityTest`, `SiteHeaderTest`, `ShellLayoutTest`; `SupportTicketNamingTest` and `PaidOnlyBallotCopyTest` surface lists; `AssetBundleTest` fixture moved from `footer.css` to `tile.css`; `DeployedEndpointTest` asserts a surviving row.
- **Failing for any other reason:** only `PasskeyTest` (8, environmental: PHP 8.3 container), exactly as before the destroy.
- **Now vacuous until a rebuild arrives:** `FormErrorStateTest::test_every_layout_carrying_a_validated_form_loads_the_validator` — the first rebuilt `data-ag-validate` form on `layout/shell.twig` fails it until `forms.css` is rebuilt and linked. `layout/shell.twig` also does not load `a11y.css`.

## Token fallout

Seventeen custom properties lost their last reader:

- **Removed** from `tokens.css` (no rebuilt file reads them): `--ag-sp-20`, `--ag-sp-22`, `--ag-r-22`, `--ag-z-appbar`, `--ag-z-bar`, `--ag-z-header`, `--ag-z-gee`. They are handoff steps (§6.3/6.4/6.7); the phase that rebuilds a reader restores the step at the handoff value.
- **Not removed — owner to decide:** `--ag-gold-wash`, `--ag-gold-wash-2`, `--ag-green-edge`, `--ag-info-wash`, `--ag-live-ink`, `--ag-live-wash`, `--ag-sh-mega` in `Support\Accent`. The palette ships ±0 under the handoff's names (GAPS §8 Q1) and `AccentTest` pins it name for name; deleting a palette entry is the owner's call. Held in `DeadTokenTest::AWAITING_REBUILD`, a list that fails when an entry gains a reader.
- **Not removed:** `--ob-accent`, `--ob-accent-dark`, `--ob-accent-wash`, written by the orphaned `partials/org-page.twig` (did not qualify). Same list.

## The destroy list

| Path | Why it qualifies | Rebuilt by (phase · DC) | Route(s) — walk status before→after | Inventory |
|---|---|---|---|---|
| `templates/layout/account-auth.twig` | (c) depends on destroyed `layout/gates.twig` | Phase 8 · SignIn family | — | [layout--account-auth.md](inventory/layout--account-auth.md) |
| `templates/layout/gates.twig` | (c) depends on destroyed `partials/cookie-notice.twig` | Phase 1 replaced it with `layout/shell.twig`; every page moves there as its phase rebuilds it | — | [layout--gates.md](inventory/layout--gates.md) |
| `templates/pages/account/dashboard.twig` | (c) depends on destroyed `layout/gates.twig`, `partials/viz.twig` | no phase — AccountPage.dc.html is not in the bundle (Q16) | `GET /account[/]` (walk: 302→302) | [pages--account--dashboard.md](inventory/pages--account--dashboard.md) |
| `templates/pages/account/forgot.twig` | (c) depends on destroyed `layout/account-auth.twig` | Phase 8? · SignIn family (no matching view) | `GET /account/forgot` (walk: 200→500) | [pages--account--forgot.md](inventory/pages--account--forgot.md) |
| `templates/pages/account/login.twig` | (c) depends on destroyed `layout/account-auth.twig` | Phase 8 · SignIn `view=phone/code` | `GET /account/login` (walk: 200→500) | [pages--account--login.md](inventory/pages--account--login.md) |
| `templates/pages/account/register.twig` | (c) depends on destroyed `layout/account-auth.twig` | Phase 8 · SignIn join | `GET /account/register` (walk: 200→500) | [pages--account--register.md](inventory/pages--account--register.md) |
| `templates/pages/account/reset.twig` | (c) depends on destroyed `layout/account-auth.twig` | Phase 8? · SignIn family (no matching view) | `GET /account/reset` (walk: 200→500) | [pages--account--reset.md](inventory/pages--account--reset.md) |
| `templates/pages/account/verify-notice.twig` | (c) depends on destroyed `layout/account-auth.twig` | Phase 8? · SignIn family (no matching view) | `GET /account/verify` (walk: 200→500) | [pages--account--verify-notice.md](inventory/pages--account--verify-notice.md) |
| `templates/pages/activity.twig` | (c) depends on destroyed `layout/gates.twig` | Phase 4 · retire → DiscoverPage Live | `GET /activity` (walk: 200→500) | [pages--activity.md](inventory/pages--activity.md) |
| `templates/pages/awards/index.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 5 · AwardsPage `view=index` | `GET /awards` (walk: 200→500) | [pages--awards--index.md](inventory/pages--awards--index.md) |
| `templates/pages/awards/programme.twig` | (c) depends on destroyed `layout/gates.twig` | Phase 5 · AwardsPage `view=detail` | `GET /awards/{p}` (walk: 404→404) | [pages--awards--programme.md](inventory/pages--awards--programme.md) |
| `templates/pages/blog/index.twig` | (c) depends on destroyed `layout/gates.twig` | Phase 9 · BlogPage `view=index` | `GET /blog` (walk: 200→500) | [pages--blog--index.md](inventory/pages--blog--index.md) |
| `templates/pages/blog/post.twig` | (c) depends on destroyed `layout/gates.twig` | Phase 9 · BlogPage `view=post` | `GET /blog/{slug}` (walk: 404→404) | [pages--blog--post.md](inventory/pages--blog--post.md) |
| `templates/pages/challenges/index.twig` | (c) depends on destroyed `layout/gates.twig` | no phase — ChallengePage.dc.html is not in the bundle (Q16) | `GET /challenges` (walk: 200→500) | [pages--challenges--index.md](inventory/pages--challenges--index.md) |
| `templates/pages/challenges/show.twig` | (c) depends on destroyed `layout/gates.twig` | no phase — ChallengePage.dc.html is not in the bundle (Q16) | — (no route renders it) | [pages--challenges--show.md](inventory/pages--challenges--show.md) |
| `templates/pages/claim-dispute.twig` | (c) depends on destroyed `layout/gates.twig` | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | `GET /claim/dispute/{token:[a-f0-9]{32}}` (walk: 200→500); `POST /claim/dispute/{token:[a-f0-9]{32}}` | [pages--claim-dispute.md](inventory/pages--claim-dispute.md) |
| `templates/pages/community/new-thread.twig` | (c) depends on destroyed `layout/gates.twig` | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /community/new` (walk: 302→302) | [pages--community--new-thread.md](inventory/pages--community--new-thread.md) |
| `templates/pages/community/thread.twig` | (c) depends on destroyed `layout/gates.twig` | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /community/{slug}` (walk: 404→404) | [pages--community--thread.md](inventory/pages--community--thread.md) |
| `templates/pages/community/threads.twig` | (c) depends on destroyed `layout/gates.twig` | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /community` (walk: 200→500) | [pages--community--threads.md](inventory/pages--community--threads.md) |
| `templates/pages/donate-giving.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | `GET /giving/manage/{token:[a-f0-9]{32}}` (walk: 404→500) | [pages--donate-giving.md](inventory/pages--donate-giving.md) |
| `templates/pages/donate-success.twig` | (c) depends on destroyed `layout/gates.twig`, `partials/success.twig` | Phase 7 · GivingPage `view=done` + Celebration `kind=give` | `GET /giving/success` (walk: 200→500) | [pages--donate-success.md](inventory/pages--donate-success.md) |
| `templates/pages/donate.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 7 · GivingPage `view=campaign` | `GET /giving` (walk: 200→500); `GET /giving/{slug:(?!apply$|manage$|redirect$|callback$|success$|giving$|gift$|donate$|stop$)[a-z0-9][a-z0-9-]{1,118}}` (walk: 404→500); `GET /giving/{slug:(?!apply$|manage$|redirect$|callback$|success$|giving$|gift$|donate$|stop$)[a-z0-9][a-z0-9-]{1,118}}/{campaign:[a-z0-9][a-z0-9-]{1,118}}` (walk: 404→500) | [pages--donate.md](inventory/pages--donate.md) |
| `templates/pages/email-unsubscribe.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /email/unsubscribe` (walk: 200→500); `POST /email/unsubscribe` | [pages--email-unsubscribe.md](inventory/pages--email-unsubscribe.md) |
| `templates/pages/error.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC — the error handler's page; owner to schedule (see "Error pages" below) | — (no route renders it) | [pages--error.md](inventory/pages--error.md) |
| `templates/pages/events.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 7 · EventsPage `view=index` | `GET /events` (walk: 200→500) | [pages--events.md](inventory/pages--events.md) |
| `templates/pages/events/detail.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 7 · EventsPage `view=detail` | `GET /events/{slug}` (walk: 404→404) | [pages--events--detail.md](inventory/pages--events--detail.md) |
| `templates/pages/events/ticket.twig` | (c) depends on destroyed `layout/gates.twig` | Phase 7 · TicketPage | `GET /events/ticket/{ref:[A-Za-z0-9\-]{8,60}}` (walk: 404→500) | [pages--events--ticket.md](inventory/pages--events--ticket.md) |
| `templates/pages/form.twig` | (c) depends on destroyed `layout/gates.twig` | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | `GET /f/{key}` (walk: 404→404); `POST /f/{key}` | [pages--form.md](inventory/pages--form.md) |
| `templates/pages/gated-form.twig` | (c) depends on destroyed `layout/gates.twig` | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | `GET /form/{token}` (walk: 410→500); `POST /form/{token}` | [pages--gated-form.md](inventory/pages--gated-form.md) |
| `templates/pages/help-article.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 9 · HelpCentre `view=article` | `GET /help/{slug:[a-z0-9-]+}` (walk: 302→302) | [pages--help-article.md](inventory/pages--help-article.md) |
| `templates/pages/help-category.twig` | (c) depends on destroyed `layout/gates.twig` | Phase 9 · HelpCentre (no category view) | `GET /help/c/{cat:[a-z0-9-]+}` (walk: 302→302) | [pages--help-category.md](inventory/pages--help-category.md) |
| `templates/pages/help.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 9/3 · HelpCentre `view=index` | `GET /help` (walk: 200→500) | [pages--help.md](inventory/pages--help.md) |
| `templates/pages/home.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 4 · HomePageV3 + WeAreAfrica | `GET [/]` (walk: 200→500) | [pages--home.md](inventory/pages--home.md) |
| `templates/pages/honour.twig` | (c) depends on destroyed `layout/gates.twig` | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /honour/{reference}` (walk: 404→404) | [pages--honour.md](inventory/pages--honour.md) |
| `templates/pages/integrity.twig` | (c) depends on destroyed `layout/gates.twig` | Phase 9 · DocPage `doc=integrity` | `GET /integrity` (walk: 200→500) | [pages--integrity.md](inventory/pages--integrity.md) |
| `templates/pages/interview.twig` | (c) depends on destroyed `layout/gates.twig` | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | `GET /interview/{token:[a-f0-9]{32}}` (walk: 404→500) | [pages--interview.md](inventory/pages--interview.md) |
| `templates/pages/judge.twig` | (c) depends on destroyed `layout/gates.twig` | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /judges/{slug}` | [pages--judge.md](inventory/pages--judge.md) |
| `templates/pages/judges.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /judges` | [pages--judges.md](inventory/pages--judges.md) |
| `templates/pages/leaderboard.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 6 · Leaderboard | `GET /leaderboard` (walk: 200→500) | [pages--leaderboard.md](inventory/pages--leaderboard.md) |
| `templates/pages/legacy/event.twig` | (c) depends on destroyed `layout/gates.twig` | Phase 6 · LegacyVault `view=edition` | `GET /legacy/{slug}` (walk: 404→404) | [pages--legacy--event.md](inventory/pages--legacy--event.md) |
| `templates/pages/legacy/index.twig` | (c) depends on destroyed `layout/gates.twig` | Phase 6 · LegacyVault `view=index` | `GET /legacy` (walk: 200→500) | [pages--legacy--index.md](inventory/pages--legacy--index.md) |
| `templates/pages/legal.twig` | (c) depends on destroyed `layout/gates.twig`, `partials/cookie-choice.twig` | Phase 9/2 · DocPage `doc=terms/privacy/cookies` + CookieConsent | `GET /privacy` (walk: 200→500); `GET /terms` (walk: 200→500); `GET /cookies` (walk: 200→500); `GET /refunds` (walk: 200→500); `GET /vendor-terms` (walk: 200→500) | [pages--legal.md](inventory/pages--legal.md) |
| `templates/pages/my-work.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | `GET /my-work/{token:[a-f0-9]{32}}` (walk: 404→500) | [pages--my-work.md](inventory/pages--my-work.md) |
| `templates/pages/my-work/interview.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | `GET /my-work/{token:[a-f0-9]{32}}` (walk: 404→500) | [pages--my-work--interview.md](inventory/pages--my-work--interview.md) |
| `templates/pages/newsletter/confirm.twig` | (c) depends on destroyed `layout/gates.twig` | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /email/confirm` (walk: 200→500); `POST /email/confirm` | [pages--newsletter--confirm.md](inventory/pages--newsletter--confirm.md) |
| `templates/pages/newsletter/index.twig` | (c) depends on destroyed `layout/gates.twig` | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /newsletter` (walk: 200→500); `POST /newsletter` | [pages--newsletter--index.md](inventory/pages--newsletter--index.md) |
| `templates/pages/nominate-award.twig` | (c) depends on destroyed `css/components/nominate.css` | Phase 8 · NominationFlow | `POST /nominate`; `GET /nominate/{slug:[a-z0-9-]+}` (walk: 200→500) | [pages--nominate-award.md](inventory/pages--nominate-award.md) |
| `templates/pages/nominate-success.twig` | (c) depends on destroyed `css/components/nominate.css` | Phase 8/3 · Celebration `kind=nominate` | `GET /nominate/success` (walk: 200→500) | [pages--nominate-success.md](inventory/pages--nominate-success.md) |
| `templates/pages/nominate.twig` | (c) depends on destroyed `css/components/nominate.css` | Phase 8 · NominateHub | `GET /nominate` (walk: 200→500); `POST /nominate` | [pages--nominate.md](inventory/pages--nominate.md) |
| `templates/pages/nominee-claim.twig` | (c) depends on destroyed `layout/gates.twig` | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | `GET /claim/{id:[0-9]+}` (walk: 200→500) | [pages--nominee-claim.md](inventory/pages--nominee-claim.md) |
| `templates/pages/nominee-confirm-done.twig` | (c) depends on destroyed `layout/gates.twig` | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | `GET /n/confirm/{token:[a-f0-9]{40}}` (walk: 404→500) | [pages--nominee-confirm-done.md](inventory/pages--nominee-confirm-done.md) |
| `templates/pages/nominee-confirm.twig` | (c) depends on destroyed `layout/gates.twig` | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | — (no route renders it) | [pages--nominee-confirm.md](inventory/pages--nominee-confirm.md) |
| `templates/pages/opportunities.twig` | (c) depends on destroyed `layout/gates.twig` | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /opportunities` (walk: 200→500) | [pages--opportunities.md](inventory/pages--opportunities.md) |
| `templates/pages/org/dashboard.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | `GET /org` (walk: 302→302) | [pages--org--dashboard.md](inventory/pages--org--dashboard.md) |
| `templates/pages/org/login.twig` | (c) depends on destroyed `layout/gates.twig` | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | `GET /org/login` (walk: 200→500) | [pages--org--login.md](inventory/pages--org--login.md) |
| `templates/pages/partner-success.twig` | (c) depends on destroyed `layout/gates.twig`, `partials/success.twig` | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /partner/success` (walk: 200→500) | [pages--partner-success.md](inventory/pages--partner-success.md) |
| `templates/pages/partner.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /partner` (walk: 200→500); `POST /partner` | [pages--partner.md](inventory/pages--partner.md) |
| `templates/pages/pay-success.twig` | (c) depends on destroyed `layout/gates.twig`, `partials/success.twig` | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /pay/success` (walk: 200→500) | [pages--pay-success.md](inventory/pages--pay-success.md) |
| `templates/pages/philosophy.twig` | (c) depends on destroyed `layout/gates.twig` | Phase 9 · DocPage `doc=philosophy` | `GET /philosophy` (walk: 200→500) | [pages--philosophy.md](inventory/pages--philosophy.md) |
| `templates/pages/programme-terms.twig` | (c) depends on destroyed `layout/gates.twig` | Phase 9 · DocPage `doc=programme-terms` | `GET /terms/{slug}` (walk: 200→500) | [pages--programme-terms.md](inventory/pages--programme-terms.md) |
| `templates/pages/pulse.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 8 · PulsePage | `GET /pulse` (walk: 200→500); `GET /pulse/reels` (walk: 200→500) | [pages--pulse.md](inventory/pages--pulse.md) |
| `templates/pages/registry/index.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 4? · DiscoverPage (inferred) | `GET /registry` (walk: 200→500) | [pages--registry--index.md](inventory/pages--registry--index.md) |
| `templates/pages/registry/profile.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 6 · ProfilePage | `GET /registry/{slug}` (walk: 404→404) | [pages--registry--profile.md](inventory/pages--registry--profile.md) |
| `templates/pages/registry/register-success.twig` | (c) depends on destroyed `layout/gates.twig`, `partials/success.twig` | none — referenced by nothing (GAPS §5.4) | — (no route renders it) | [pages--registry--register-success.md](inventory/pages--registry--register-success.md) |
| `templates/pages/registry/register.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | none — already unrouted (GAPS §5.4) | — (no route renders it) | [pages--registry--register.md](inventory/pages--registry--register.md) |
| `templates/pages/results/edition.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 5 · ResultsPage | `GET /results/{edition:[a-z][a-z0-9-]*}` (walk: 404→404) | [pages--results--edition.md](inventory/pages--results--edition.md) |
| `templates/pages/results/hall.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC (`/winners`) — owner to schedule | `GET /winners` (walk: 200→500) | [pages--results--hall.md](inventory/pages--results--hall.md) |
| `templates/pages/results/index.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 5? · ambiguous (Q14) | `GET /results` (walk: 200→500) | [pages--results--index.md](inventory/pages--results--index.md) |
| `templates/pages/results/late.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 5? · ambiguous (Q14) | `GET /results/{slug:[0-9]+[^/]*}` (walk: 404→404) | [pages--results--late.md](inventory/pages--results--late.md) |
| `templates/pages/results/open.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 5 · ResultsPage | `GET /results/{edition:[a-z][a-z0-9-]*}` (walk: 404→404) | [pages--results--open.md](inventory/pages--results--open.md) |
| `templates/pages/results/show.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 5? · ambiguous (Q14) | `GET /results/{slug:[0-9]+[^/]*}` (walk: 404→404) | [pages--results--show.md](inventory/pages--results--show.md) |
| `templates/pages/shop/alert-stopped.twig` | (c) depends on destroyed `layout/gates.twig` | Phase 7 · ShopPage `view=stopped` | `GET /shop/back-in-stock/stop/{token:[a-f0-9]{32}}` (walk: 200→500) | [pages--shop--alert-stopped.md](inventory/pages--shop--alert-stopped.md) |
| `templates/pages/shop/index.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 7 · ShopPage `view=index` | `GET /shop` (walk: 200→500) | [pages--shop--index.md](inventory/pages--shop--index.md) |
| `templates/pages/shop/item.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 7 · ShopPage `view=item` | `GET /shop/{slug}` (walk: 404→404) | [pages--shop--item.md](inventory/pages--shop--item.md) |
| `templates/pages/shop/order.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 7 · ShopPage `view=order` | `GET /shop/order/{ref:[A-Za-z0-9\-]{8,72}}` (walk: 404→500) | [pages--shop--order.md](inventory/pages--shop--order.md) |
| `templates/pages/shop/success.twig` | (c) depends on destroyed `layout/gates.twig`, `partials/success.twig` | Phase 7 · ShopPage `view=done` | `GET /shop/success` (walk: 200→500) | [pages--shop--success.md](inventory/pages--shop--success.md) |
| `templates/pages/stands/apply.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | `GET /events/{slug}/stands/apply` (walk: 302→302); `POST /events/{slug}/stands/apply` | [pages--stands--apply.md](inventory/pages--stands--apply.md) |
| `templates/pages/stands/call.twig` | (c) depends on destroyed `layout/gates.twig`, `partials/viz.twig` | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | `GET /events/{slug}/stands` (walk: 302→302) | [pages--stands--call.md](inventory/pages--stands--call.md) |
| `templates/pages/stands/offer.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | `GET /stand/{token:[a-f0-9]{48}}` (walk: 404→500) | [pages--stands--offer.md](inventory/pages--stands--offer.md) |
| `templates/pages/status.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 9 · StatusPageV2 | `GET /status` (walk: 200→500) | [pages--status.md](inventory/pages--status.md) |
| `templates/pages/support-assistant.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 3 · retire → Gee `mode=support` | `GET /support/assistant` (walk: 200→500) | [pages--support-assistant.md](inventory/pages--support-assistant.md) |
| `templates/pages/support-ticket-link.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | `GET /support/t/{token:[a-f0-9]{64}}` (walk: 404→500) | [pages--support-ticket-link.md](inventory/pages--support-ticket-link.md) |
| `templates/pages/support-tickets.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC — member/partner/token page, undesigned (GAPS §5.2, REFERENCE §18.7) | `GET /support/tickets` (walk: 302→302) | [pages--support-tickets.md](inventory/pages--support-tickets.md) |
| `templates/pages/support.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /support` (walk: 200→500) | [pages--support.md](inventory/pages--support.md) |
| `templates/pages/terms.twig` | (c) depends on destroyed `layout/gates.twig` | none — referenced by nothing (GAPS §5.4) | — (no route renders it) | [pages--terms.md](inventory/pages--terms.md) |
| `templates/pages/vote-flier.twig` | (c) depends on destroyed `layout/gates.twig` | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /vote/{program}/{slug:[0-9]+[^/]*}/flier` (walk: 200→500) | [pages--vote-flier.md](inventory/pages--vote-flier.md) |
| `templates/pages/vote-message.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /m/{token:[A-Za-z0-9_-]{16,32}}` (walk: 404→404) | [pages--vote-message.md](inventory/pages--vote-message.md) |
| `templates/pages/vote-messages.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /vote/{program}/{slug:[0-9]+[^/]*}/messages` (walk: 200→500) | [pages--vote-messages.md](inventory/pages--vote-messages.md) |
| `templates/pages/vote-nominee.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 5 · NomineePage + VoteBallot | `GET /vote/{program}/{slug}` (walk: 302→302) | [pages--vote-nominee.md](inventory/pages--vote-nominee.md) |
| `templates/pages/vote-paid-success.twig` | (c) depends on destroyed `layout/gates.twig`, `partials/success.twig` | Phase 5/3 · Celebration `kind=vote` (inferred) | `GET /vote/paid/success` (walk: 200→500) | [pages--vote-paid-success.md](inventory/pages--vote-paid-success.md) |
| `templates/pages/vote-program.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 5 · VotePage `view=vote` | `GET /vote/{program}` (walk: 302→302) | [pages--vote-program.md](inventory/pages--vote-program.md) |
| `templates/pages/vote-supporters.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /vote/{program}/{slug:[0-9]+[^/]*}/supporters` (walk: 200→500) | [pages--vote-supporters.md](inventory/pages--vote-supporters.md) |
| `templates/pages/vote-verify.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC (GAPS §5.1 "No DC") — owner to schedule | `GET /vote/verify` (walk: 200→500) | [pages--vote-verify.md](inventory/pages--vote-verify.md) |
| `templates/pages/vote.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 5 · VoteHub | `GET /vote` (walk: 200→500) | [pages--vote.md](inventory/pages--vote.md) |
| `templates/partials/article.twig` | (c) depends on destroyed `css/components/article.css` | Phase 9 · DocPage (the article macros for legal/integrity/philosophy) | — | [_partials.md](inventory/_partials.md) |
| `templates/partials/cookie-choice.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 2/9 · CookieConsent on DocPage `doc=cookies` | — | [_partials.md](inventory/_partials.md) |
| `templates/partials/cookie-notice.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 2 · CookieConsent | — | [_partials.md](inventory/_partials.md) |
| `templates/partials/find-band.twig` | (c) depends on destroyed `css/components/find.css` | Phase 4 · DiscoverPage | — | [_partials.md](inventory/_partials.md) |
| `templates/partials/globe-band.twig` | (c) depends on destroyed `css/globe-band.css` | Phase 4 · HomePageV3 globe band | — | [_partials.md](inventory/_partials.md) |
| `templates/partials/help-nav.twig` | (c) depends on destroyed `css/components/help-nav.css` | Phase 9 · HelpCentre | — | [_partials.md](inventory/_partials.md) |
| `templates/partials/shop-cart.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 7 · ShopPage | — | [_partials.md](inventory/_partials.md) |
| `templates/partials/success.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 3 · Celebration (and each success page's phase) | — | [_partials.md](inventory/_partials.md) |
| `templates/partials/vote-message-assets.twig` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC (`/m/{token}`, vote messages) | — | [_partials.md](inventory/_partials.md) |
| `public/assets/css/base/typography.css` | (a) a9d963a renamed retired names in place; pre-image reads them | none — `.ag-scope` legacy base, superseded by tokens/components | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/account-auth.css` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 8 · SignIn | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/account.css` | (a) a9d963a renamed retired names in place; pre-image reads them | Q16 (account) | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/article.css` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 9 · DocPage | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/challenge.css` | (a) a9d963a renamed retired names in place; pre-image reads them | Q16 (challenges) | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/chrome.css` | (b) created/extended in a9d963a by MOVING old rules | Phase 2 (chrome) | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/community-modal.css` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC (community) — owner | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/find.css` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 4 · DiscoverPage (find band) | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/flash.css` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 2 (chrome) | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/footer.css` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 2 (chrome) | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/forms.css` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 2/8 — the shared form states, rebuilt with the first rebuilt form | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/gee.css` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 3 · Gee | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/help-nav.css` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 9 · HelpCentre | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/library.css` | (b) created/extended in a9d963a by MOVING old rules | with its readers: Phase 5 (award page) · Q16 (account, challenges) | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/lip.css` | (a) a9d963a renamed retired names in place; pre-image reads them | none — the legacy lip (CLAUDE.md: survives until its pages are rebuilt; they are gone) | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/loader.css` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 2 (chrome) — the splash | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/nominate.css` | (b) created/extended in a9d963a by MOVING old rules (`.ag-ai-note` appended from components.css) | Phase 8 · NominateHub / NominationFlow | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/nominee-confirm.css` | (a) a9d963a renamed retired names in place; pre-image reads them | no DC (token page) | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/pulse-immersive.css` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 8 · PulsePage | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/site-search.css` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 2 · search palette | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/components/vote-countdown.css` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 2 (chrome) | — | [_stylesheets.md](inventory/_stylesheets.md) |
| `public/assets/css/globe-band.css` | (a) a9d963a renamed retired names in place; pre-image reads them | Phase 4 · HomePageV3 globe band | — | [_stylesheets.md](inventory/_stylesheets.md) |

