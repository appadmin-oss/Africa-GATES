Implement the Africa GATES Account, Challenge and Challenge banner screens.

Read, in order: handoff-new-pages/HANDOFF-NEW-PAGES.md, HANDOFF-CLAUDE-CODE.md §0–§3, skills/mobile-native-ux/SKILL.md, the repo CLAUDE.md, then templates/pages/account/dashboard.twig.

Open each file in handoff-new-pages/designs/ in a browser and cycle every Tweaks prop (layout, tab, challenge, state, signedIn, placement). Those files are the spec.

Work in this order, one PR each:
1. Migrations + models: gates_challenges, gates_challenge_scopes, gates_challenge_entries, gates_promos, and the nominations columns. Both schema files.
2. ChallengeCopy service, ported from derive() in ChallengePage.dc.html, with unit tests for the 3 demo configs.
3. Entry/qualification service with the transactional rank assignment, plus a race test (11 concurrent qualifiers).
4. partials/promo-carousel.twig + /api/promos, mounted on account, the Nominate hub, home, vote and events.
5. pages/challenges/show.twig (all states) + the compact strip on scoped award/event/nominee pages.
6. Account rework: keep me_tabs, :target and search; add the phone hub + pushed child views and the desktop rail + table-style subpages.
7. Admin challenge builder + queues, inside the existing admin UI, per §5 (no new designs).

Rules: reuse existing services; build anything missing end to end; no inline styles; no new colours; match at 390/834/1440 + RTL; attach screenshots beside the DC and a deviations list (target 0). If something can't be matched, stop and ask.
