# Africa GATES admin, account and accessibility refactor

Work is local on `claude/ai-assistance-judges-features-1ka4oz`; no deployment or commit was made. Earlier eight audit fixes remain in place.

## Organisation and workflows

- Members is a dedicated entry in People & audience. Its searchable, paginated directory preserves the existing users-dataset roles. Verification filters, literal search handling and safe field lists avoid exposing credentials.
- Existing `/admin/data/users/{id}` URLs now route to the member controller's support view: account summary, last 20 points entries and the original audited grant/deduct workflow. The generic data explorer stays generic. Staff console accounts remain separate under Team, with bounded search and pagination.
- Analytics is a first-level destination under Insights. The page has section navigation, readable cards and explicit selected-window/lifetime/cohort reporting scope. Its empty report shape is consistent.
- Profile, Security and Settings share account-settings navigation. Password changes have their own Security form and POST endpoint, which cannot overwrite profile fields from an old browser tab. Legacy profile password submissions remain compatible.

## Module boundaries

`MemberAccountsService` owns member directory/detail read models; `StaffDirectoryService` owns the staff directory. Controllers enforce access and select views. `UserAccountService::changePassword` owns shared validation and update rules. Profile, security and preferences are separate account-section templates. Points form markup is shared between the member view and data explorer.

`console/people.css` and `console/analytics.css` own their respective layouts; the interim workspace stylesheet and analytics inline stylesheet were removed. Colours come from Accent rather than a second CSS palette. Existing navigation/permission metadata remains the source for the rail, palette and handbook.

## Accessibility and responsive behaviour

Measured admin text contrast failures were corrected in the shared Accent palette. The tests independently calculate every declared text/ground pair and reject the historical low-contrast value. The old failure inventory is empty.

Shared `accessibility-focus.js` makes skip links reliably focus the main landmark, focuses redirected error messages and makes horizontally overflowing tables named keyboard-focusable regions. Existing labels are preserved. It supports full loads and console navigation lifecycle events. Server confirmations now remain in the main content, instead of disappearing after a toast timeout.

New directory controls have explicit labels, scoped table headings and descriptive review links. Analytics table headings carry column scope. Settings navigation wraps at narrow sizes and text zoom. Existing passkey, reduced-motion, forced-colours and display/reading mechanisms are retained.

## Evidence

Final MySQL regression selection: **154 tests, 2,033 assertions, passed**. It covers permissions, search/detail, staff search, password security, navigation, palette/typography, template syntax, representative public pages and the seeded admin-route render sweep. The chart data-table regression test passes. A separate 22-test component check passed with 289 assertions; shell/skip-link follow-up checks passed 22 tests and 115 assertions (`shell-checks.log`).

The broad SQLite run completed **7,613 tests and 58,143 assertions**, with five failures. Four were integration contracts introduced by this refactor (new member fixture, typography tokens/capitalisation and the main landmark assertion); they were fixed and passed in the targeted SQLite/MySQL reruns. The remaining pre-existing ColourBudget failure covers six unrelated templates. The broad run also recorded two existing public-render warnings, two existing discovery deprecations and one environment-dependent skip. The full broad suite was not rerun after the integration corrections; it must not be described as fully green. Its saved log is `full-sqlite-before-integration-fixes.log`.

The audit preview uses only `africa_gates_audit`. Chromium verified member grants and compensating deductions through the real form; its balance returned to the starting value and the ledger showed both entries. The initial 14-view desktop/mobile layout sweep passed. Final reflow checks cover **35 page/viewport/zoom combinations**, with zero page or main-content overflow at 320, 390, 768 and 1440px and the tested 200% zoom cases. Axe before/after evidence is saved as `a11y-baseline.json` and `a11y-after.json`: the 12 representative pages pass the automated WCAG-tagged rules after the contrast changes.

Keyboard checks confirm public/admin skip-link focus, client error-summary focus with hint/error descriptions preserved, server-error focus, named date/value tables in Chromium's accessibility tree, arrow-key horizontal scrolling, Escape dismissal and focus restoration for native confirmation dialogs. Native modal page focus stays contained while browser chrome remains reachable. Selected account/report controls retain an underline under forced colours, and reduced-motion preference emulation is recognised. These checks are recorded in `screen-reader-keyboard.json` and `responsive-accessibility.json`.

Asset research and current owner preferences are documented in `docs/ASSET-DESIGN-GUIDE.md`. Earlier generated illustration concepts remain outside the runtime assets. No unverified illustration was substituted for an actual recipient's photograph.

Automated checks validate specific rules and workflows; native NVDA, JAWS and VoiceOver sessions and usability testing with disabled participants remain needed before claiming comprehensive assistive-technology coverage.
