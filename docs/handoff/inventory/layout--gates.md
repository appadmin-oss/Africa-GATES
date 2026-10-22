# `templates/layout/gates.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** none found in `src/` (see DESTROYED.md)
**Extends:** — · **Includes/imports:** partials/a11y-head.twig, layout/nav.twig, partials/flash.twig, layout/footer.twig, partials/community-modal.twig, partials/site-search.twig, partials/cookie-notice.twig

**What the page says it is (its own header comment, abridged):**
```
 `lang` and `dir` were hard-coded `en`/`ltr` here, which made the language menu
   a control that changed nothing on ~180 pages. They come from the cookie now,
   resolved once per request by LanguageMiddleware.
```

**Data read (top-level variables/functions):** `assets`, `css`, `js`, `components`, `vendor`, `_site_url`, `gates_page`, `page_title`, `excellence`, `meta_description`, `_canonical`, `schema`, `_og_image`, `favicon`, `ag`, `breadcrumbs`, `hide_chrome`, `gee_suppressed`, `the`, `nations_live`, `nations_count`, `awards`, `continental`, `cultural`, `recognition`, `og_title`, `nominate`, `celebrate`, `og_image_w`, `og_image_h`, `swiper`, `splide`, `plyr`, `css_bundle`, `tokens`, `motion`, `main`, `base`, `shell`, `gee`, `community`, `modal`, `search`, `promo`, `a11y`, `task_page`, `gsap`, `lang`, `lang_dir`, `recognising`, `building`, `toward`, `nations`, `initiative`, `robots`, `robots_auto`, `follow`, `image`, `preview`, `large`, `meta_keywords`, `creatives`, `nominations`, `leaderboard`, `og_type`, `through`, `og_image_alt`, `og_image_type`, `png`, `apple`…

**Forms:**
- `GET (self)` fields: —; buttons: —

**States / branches (14 distinct conditions):** `schema is defined and schema` · `og_image_w and og_image_h` · `breadcrumbs is defined and breadcrumbs` · `b.url` · `not loop.last` · `css_bundle` · `task_page|default(false)` · `favicon_state|default('') == 'live'` · `not hide_chrome|default(false)` · `not own_flash|default(false)` · `not gee_suppressed` · `not lite_page|default(false)` · `not gee_suppressed|default(false)` · `cookie_ask()`

**Links out:** `{{ _canonical }}` · `/site.webmanifest` · `https://fonts.googleapis.com` · `https://fonts.gstatic.com` · `https://images.unsplash.com` · `https://r2.vidzflow.com` · `https://unpkg.com` · `https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;` · `https://unpkg.com/leaflet@1.9.4/dist/leaflet.css` · `{{ css_bundle }}` · `#main` · `/community`

**JS behaviours:** script `{{ asset('/assets/js/ag-motion.js') }}`; script `{{ asset('/assets/js/shell.js') }}`; script `{{ asset('/assets/js/a11y.js') }}`; script `{{ asset('/assets/js/chrome.js') }}`; script `{{ asset('/assets/js/header.js') }}`; script `{{ asset('/assets/js/ag-social.js') }}`; script `{{ asset('/assets/js/ag-search.js') }}`; script `{{ asset('/assets/js/vendor/alpine-3.13.5.min.js') }}`; script `{{ asset('/assets/js/community-modal.js') }}`; script `{{ asset('/assets/js/vendor/gsap-3.12.5.min.js') }}`; script `{{ asset('/assets/js/vendor/gsap-scrolltrigger-3.12.5.min.js') }}`; script `{{ asset('/assets/js/vendor/split-type-0.3.4.min.js') }}`; script `{{ asset('/assets/js/vendor/popper-2.11.8.min.js') }}`; script `{{ asset('/assets/js/vendor/tippy-6.3.7.umd.min.js') }}`; script `https://unpkg.com/leaflet@1.9.4/dist/leaflet.js`; script `{{ asset('/assets/js/vendor/swiper-8.4.7.bundle.min.js') }}`; script `{{ asset('/assets/js/vendor/splide-4.1.4.min.js') }}`; script `{{ asset('/assets/js/vendor/plyr-3.7.8.polyfilled.js') }}`; script `{{ asset('/assets/js/afg-features.js') }}`; script `{{ asset('/assets/js/main.js') }}`; script `{{ asset('/assets/js/form-validate.js') }}`; script `{{ asset('/assets/js/promo-carousel.js') }}`; script `{{ asset('/assets/js/gee.js') }}`; script `{{ asset('/assets/js/favicon.js') }}`; 11 inline <script> block(s); data hooks: `data-page`, `data-favicon`, `data-gee-page`, `data-gee-title`, `data-ag-do`; data-ag-do: set-cookie-reload, submit-form

**Accessibility affordances:** aria-hidden×12, aria-label×10, aria-live×2, aria-controls×1, aria-expanded×1, aria-modal×1, aria-atomic×1; roles: status, dialog, log; visually-hidden text×1; tabindex×2; autocomplete×1; lang/dir attrs×2; prefers-reduced-motion×3

**Legal / consent lines:**
- try { new Plyr(el, { youtube: { noCookie: true, rel: 0 } }); }
- var el = e.target.closest('[data-ag-do="set-cookie-reload"]');
- if (!el || !el.dataset.cookie) return;
- document.cookie = el.dataset.cookie + '=' + encodeURIComponent(el.value) + ';path=/;max-age=31536000';
- {% if cookie_ask() %}{% include 'partials/cookie-notice.twig' with { cookie_return: cookie_return() } %}{% endif %}

**Styling carried:** 2 <style> block(s), 0 inline style attributes; stylesheet links: /assets/css/vendor/swiper-8.4.7.bundle.min.css, /assets/css/vendor/splide-4.1.4.min.css, /assets/css/vendor/plyr-3.7.8.css, /assets/css/tokens.motion.css, /assets/css/motion.css, /assets/css/main.css, /assets/css/ui-overhaul.css, /assets/css/professional.css, /assets/css/redesign-2026.css, /assets/css/aurora.css, /assets/css/base/reset.css, /assets/css/base/typography.css, /assets/css/tokens.css, /assets/css/shell.css, /assets/css/components.css, /assets/css/components/flash.css, /assets/css/components/loader.css, /assets/css/components/footer.css, /assets/css/components/vote-countdown.css, /assets/css/components/gee.css, /assets/css/components/community-modal.css, /assets/css/components/site-search.css, /assets/css/components/forms.css, /assets/css/components/nominee-confirm.css, /assets/css/components/challenge.css, /assets/css/components/promo.css, /assets/css/components/account.css, /assets/css/components/pulse-immersive.css, /assets/css/components/tile.css, /assets/css/components/lip.css, /assets/css/a11y.css

**Guard tests that read this page (at the time of the destroy):**
- `AccentTest::test_every_layout_emits_the_palette_before_its_stylesheets` — (no docblock)
- `AdminContrastTest::test_every_layout_loads_the_accessibility_layer` — The accessibility layer must reach every surface, not just the public site.
- `AssetBundleTest::test_the_layout_fallback_matches_the_bundle_list_exactly` — The layout's fallback must list exactly the same files, in the same order.
- `AssetBundleTest::test_the_layout_prefers_the_bundle_when_one_exists` — (no docblock)
- `ChromeReachabilityTest::test_a_layout_never_mounts_a_sheet_it_cannot_open` — (no docblock)
- `ColourBudgetTest::test_chrome_is_not_charged_to_a_page` — (no docblock)
- `DeployFingerprintTest::test_vendored_libraries_are_loaded_from_this_origin` — Popper and Tippy are served from this origin.
- `DoorScreenTest::test_the_hidden_attribute_beats_a_display_declaration` — The attribute is only `display:none` in the UA stylesheet, so any author `display` beats it — and the arrivals sheet, the offline strip and the party row are all `display:flex`.
- `FaviconTest::test_the_head_carries_the_three_links_and_the_script_after_gee` — (no docblock)
- `FaviconTest::test_an_open_ballot_declares_itself_live_and_a_closed_one_does_not` — "An open vote page", and only an open one: a closed ballot is not live.
- `FlashKeyTest::test_the_success_alias_actually_resolves_to_the_rendered_variable` — (no docblock)
- `FlashKeyTest::test_the_public_layout_renders_all_three_kinds` — The PUBLIC layout, which rendered none of them at all.
- `LegalCoverageTest::test_the_platform_really_has_no_third_party_trackers` — (no docblock)
- `NationsLiveTest::test_the_copy_actually_asks_for_it` — A resolver with no caller is this codebase's most expensive bug, and this one exists ONLY to replace typed copy.
- `OgImageTest::test_the_dimensions_the_meta_tags_declare_match_the_image` — (no docblock)
- `PublicResultsTest::test_the_reply_script_waits_for_the_deferred_helper_it_needs` — AND THE BUTTON IS BOUND TO SOMETHING.
- `ReleasedStandingTest` (whole file, via a helper/constant/data provider: 15 tests) — test_a_released_page_publishes_the_sealed_index_not_a_recomputed_one, test_a_rules_change_cannot_rename_the_winner_of_a_released_award, test_a_nominee_added_after_the_release_is_not_ranked_into_it, test_a_nominee_sealed_below_quorum_is_not_swept_in_when_judging_finishes, test_a_release_with_no_seal_is_not_dressed_up_as_an_announcement, test_the_page_states_whether_the_figures_were_announced_or_recomputed, test_a_cycle_is_sealed_once_however_often_the_sweep_runs, test_a_released_page_publishes_the_sealed_placings, test_a_seal_missing_a_placing_is_reconstructed_and_labelled, test_the_sealed_reach_denominator_survives_the_rows_being_purged, test_a_nominee_the_live_draw_would_rank_in_is_reported_to_the_operator, test_a_seal_the_rules_still_agree_with_reports_no_movement …
- `SchemaTest::test_hostile_text_in_an_appeal_still_produces_valid_json` — Hostile text in a charity name cannot break the block.
- `SchemaTest::test_no_builder_lets_hostile_text_escape_the_script_element` — No builder may emit a value that closes the JSON-LD script element.
- `SeoCanonicalTest` (whole file, via a helper/constant/data provider: 14 tests) — test_a_paginated_page_canonicalises_to_itself, test_page_one_collapses_to_the_bare_path, test_a_referral_link_canonicalises_to_the_clean_page, test_campaign_parameters_are_stripped, test_a_filter_is_canonicalised_away_but_not_deindexed, test_an_internal_search_result_is_noindex_follow, test_an_empty_search_box_is_still_the_indexable_page, test_an_array_shaped_parameter_does_not_warn, test_a_bare_path_is_unchanged, test_the_layout_builds_its_canonical_from_canonical_path, test_the_globals_the_layout_reads_are_registered, test_the_favicon_is_a_crawlable_file_and_not_a_data_uri …
- `ShellLayoutTest::test_the_overflow_lock_belongs_to_the_shell_layout_alone` — (no docblock)
- `SiteHeaderTest::test_no_id_appears_twice_in_one_document` — (no docblock)
- `SplashScreenTest` (whole file, via a helper/constant/data provider: 15 tests) — test_the_splash_contains_no_smil, test_the_reveal_is_driven_by_the_playing_class, test_no_animation_can_leave_the_logo_half_drawn, test_the_exit_is_not_a_fixed_delay_animation, test_there_is_a_hard_cap_measured_from_navigation, test_the_reveal_starts_on_a_rendered_frame, test_task_pages_never_show_the_splash, test_a_controller_can_exempt_a_page_explicitly, test_it_stays_mobile_only_once_per_session_and_motion_safe, test_the_session_flag_is_stamped_even_when_the_splash_is_skipped, test_the_cap_has_no_escape_for_a_reveal_in_progress, test_the_drawn_phases_finish_before_the_mark_starts_leaving …
- `StandingsAndFlierTest::test_the_bundled_fonts_are_the_faces_the_site_actually_loads` — (no docblock)
- `TallyOnlyCommunityHalfTest` (whole file, via a helper/constant/data provider: 14 tests) — test_an_unmeasured_edition_pays_the_tally_term_only, test_measured_reach_cannot_pay_a_full_half_to_a_narrow_nominee, test_the_drawn_cycle_reports_that_reach_was_unmeasurable, test_the_release_screen_states_the_half_was_paid_on_tally_alone, test_the_public_page_states_the_half_was_paid_on_tally_alone, test_neither_screen_says_it_when_the_rows_are_there, test_the_screen_names_the_layer_that_chose_the_basis, test_it_distinguishes_a_programme_override_from_a_cycle_one, test_the_narrowest_layer_decides_and_matches_what_was_scored, test_an_unoverridden_cycle_gets_no_notice, test_a_shortlist_no_longer_hides_a_bigger_tally_from_the_yardstick, test_a_full_community_half_now_requires_matching_the_biggest_tally …
- `TwigBlockScopeTest::test_the_scan_catches_a_reintroduced_cross_block_read` — And it must find the bug when the bug is put back.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `AssetBundleTest::test_the_layout_fallback_matches_the_bundle_list_exactly` — The layout's fallback must list exactly the same files, in the same order.
- `AssetBundleTest::test_the_layout_prefers_the_bundle_when_one_exists` — the fallback branch is what makes a missing build harmless
- `DeployFingerprintTest::test_vendored_libraries_are_loaded_from_this_origin` — Popper and Tippy are served from this origin.
- `FaviconTest::test_an_open_ballot_declares_itself_live_and_a_closed_one_does_not` — "An open vote page", and only an open one: a closed ballot is not live. — *{% set favicon_state*
- `FaviconTest::test_the_head_carries_the_three_links_and_the_script_after_gee` — favicon.js is not loaded with defer and the nonce — *favicon.js must load after gee.js*
- `FlashKeyTest::test_the_public_layout_renders_all_three_kinds` — The PUBLIC layout, which rendered none of them at all. — *the partial exists but the public layout never includes it*
- `LegalCoverageTest::test_the_platform_really_has_no_third_party_trackers` — the cookie policy says we run no trackers — this one would make it a lie
- `NationsLiveTest::test_the_copy_actually_asks_for_it` — A resolver with no caller is this codebase's most expensive bug, and this one exists ONLY to replace typed copy.
- `OgImageTest::test_the_dimensions_the_meta_tags_declare_match_the_image` — The dimensions the meta tags declare match the image.
- `SeoCanonicalTest::test_the_favicon_is_a_crawlable_file_and_not_a_data_uri` — THE BUG: the favicon was an inline `data:image/svg+xml` of the letter G — the placeholder the real artwork replaced. — *a data: URI has no URL for a crawler to fetch*
- `SeoCanonicalTest::test_the_layout_builds_its_canonical_from_canonical_path` — A helper nothing calls is not a fix. — *the canonical must come from canonical_path, not the raw request path*
- `SplashScreenTest::test_a_bfcache_restore_removes_it_outright` — Restored from the back/forward cache, it goes at once rather than fading in.
- `SplashScreenTest::test_a_controller_can_exempt_a_page_explicitly` — A page can opt out by name, not only by section. — *both ticket branches (found, and not-found) must be exempt*
- `SplashScreenTest::test_it_stays_mobile_only_once_per_session_and_motion_safe` — It stays mobile only once per session and motion safe.
- `SplashScreenTest::test_reduced_motion_is_refused_twice` — Two denials, not one: the gate decides, and the stylesheet refuses anyway. — *the stylesheet does not deny itself under reduced motion*
- `SplashScreenTest::test_task_pages_never_show_the_splash` — Nobody arriving with a problem watches an animation. — *The exempt list no longer feeds the decision.*
- `SplashScreenTest::test_the_cap_has_no_escape_for_a_reveal_in_progress` — THE CAP HAS TO ACTUALLY CAP, AND THE SHIPPED SCRIPT'S DID NOT. — *the cap must call the exit, not merely schedule a check that can decline*
- `SplashScreenTest::test_the_drawn_phases_finish_before_the_mark_starts_leaving` — The CSS schedule is the spec, and a sum nobody checks is a comment. — *the five reveal phases (disc, continent, Africa, GATES, hairline) are not all keyed on .is-playing*
- `SplashScreenTest::test_the_exit_is_not_a_fixed_delay_animation` — The exit is not a fixed delay animation.
- `SplashScreenTest::test_the_reveal_is_driven_by_the_playing_class` — {$part} must animate from .is-playing, not from first render. — *The hairline too, or it is a static rule under a mark that is still arriving.*
- `SplashScreenTest::test_the_reveal_starts_on_a_rendered_frame` — The reveal starts on a rendered frame.
- `SplashScreenTest::test_the_session_flag_is_stamped_even_when_the_splash_is_skipped` — A task page must not merely POSTPONE the intro to the next page.
- `SplashScreenTest::test_the_splash_contains_no_smil` — Same reason as <animate> — it runs on the SMIL clock.
- `SplashScreenTest::test_the_two_outlines_are_the_originals` — THE CONTINENT IS NOT REDRAWN, and the handoff says so in as many words. — *the two outlines are no longer grouped as ag-loader__land*
- `SplashScreenTest::test_there_is_a_hard_cap_measured_from_navigation` — the three phases no longer add up to the 1.36s the spec states — *The cap is the promise that a slow page shows content rather than a logo.*
- `SplashScreenTest::test_no_animation_can_leave_the_logo_half_drawn` — Every reveal animation holds its end state.
- `FlashKeyTest::test_pages_that_render_their_own_flash_suppress_the_layout_rail` — The pages that place their own flash must opt OUT, or every message appears twice.
- `ShellLayoutTest::test_the_overflow_lock_belongs_to_the_shell_layout_alone (half removed)` **(guard kept, edited)** — A page on the old public layout scrolls the document: its <html> must never carry `ag-shelled` (the overflow lock belongs to the shell layout alone).
- `AccentTest::test_every_layout_emits_the_palette_before_its_stylesheets (gates.twig dropped from its list)` **(guard kept, edited)** — Every layout emits `<style nonce>{{ ag_accents()|raw }}` BEFORE it links tokens.css.
- `AdminContrastTest::test_every_layout_loads_the_accessibility_layer (gates.twig dropped from its list)` **(guard kept, edited)** — Every layout loads a11y.css (touch-target minimums, forced-colors, prefers-contrast, aria-invalid, .sr-only). NOTE: layout/shell.twig does not load it today — the first public rebuild must decide.
- `OneMainLandmarkTest::test_each_layout_has_exactly_one (gates dropped)` **(guard kept, edited)** — The layout renders exactly one `<main id="main">`; a page never opens a second.
- `ColourBudgetTest::test_chrome_is_not_charged_to_a_page (gates.twig dropped)` **(guard kept, edited)** — The layout and the nav are chrome: never charged a page's colour budget.
- `ChromeReachabilityTest::test_a_layout_never_mounts_a_sheet_it_cannot_open (gates dropped)` **(guard kept, edited)** — A layout never mounts a sheet (menu, quick settings, search) without a reachable button/link that opens it; the old layout mounted its chrome through layout/nav.twig.
- `SiteHeaderTest::test_no_id_appears_twice_in_one_document (gates dropped)` **(guard kept, edited)** — No id appears twice in one rendered document; a partial mounted twice (display-reading) takes a `uid`.
- `CookieRegistryTest::test_every_cookie_the_shipped_code_writes_is_on_the_published_list` **(guard kept, edited)** — The data-cookie writers (ag_region, ag_currency on the shop selects; the delegated listener in layout/gates.twig) were destroyed; the sweep now also reads the PSR-7 Set-Cookie form (Languages::apply, CookiePrefs::apply) it had been blind to; proven failing on a planted undeclared writer.
