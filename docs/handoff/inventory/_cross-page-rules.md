# Cross-page rules — guards whose subject was every page, or the layout

Rules from destroyed tests that applied to the public site as a whole (or whose page could not be pinned to one template), plus the sweeps that were kept but lost their population or canary. Every rebuilt page owes these.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `DeployedEndpointTest (kept) — /__setup/deployed rows for destroyed templates removed from src/routes.php` **(guard kept, edited)** — The deploy diagnostic checked marker strings in vote-nominee (pvMsg, vnMsg, vn-qty, vn-story), vote-messages (vmi-list), vote-message (vm__quote), vote-supporters (vsu__grid), vote-message-assets (vmItem), claim-dispute (class="cdp"), help-category (hcc-list), help (hc-cats), interview (ivp__consent), my-work (mw__work, mw__prog). A rebuilt page re-adds its row.
- `PublicIaTest (sweep kept; new KIND)` **(guard kept, edited)** — A route whose handler renders only templates missing from the tree is a destroyed page awaiting rebuild and is out of the reachability sweep until its template returns (proven: re-creating pages/shop/index.twig puts /shop back in and fails).
- `ThirdPartyScriptIntegrityTest::test_the_scanner_still_sees_the_known_remaining_externals` **(guard kept, edited)** — Named survivors Leaflet (unpkg, layout/gates), Turnstile (challenges.cloudflare.com, vote-nominee) and AdSense (pagead2, shop/index) all left with their pages; the control now asserts NO third-party script remains, and the first rebuild that brings one back names it.
- `CspHostCoverageTest::test_the_scan_finds_the_hosts_the_templates_really_use` **(guard kept, edited)** — Negative control lost its script half (unpkg.com, challenges.cloudflare.com); style half kept (fonts.googleapis.com, from shell.twig). The first rebuild loading Turnstile restores the assertion.
