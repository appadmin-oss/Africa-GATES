# Recovered audit and validation records

These reports and selected test outputs were originally created during the 31 October session, recovered with the workspace on 4 November, and added to version control. They document historical results, not a new validation run. The core implementation was preserved in commit ea0aa08a.

- [Audit](AFRICA-GATES-AUDIT.md), [fixes](AFRICA-GATES-FIXES.md), [structure and accessibility](AFRICA-GATES-STRUCTURE.md).
- [Setup notes](CODEBASE-NOTES.md), [image research](HOMEPAGE-IMAGE-RESEARCH.md), [nextgengen audit](NEXTGENGEN-AUDIT.md).
- Selected raw test summaries and browser-check JSON are in evidence/. Credential files, private browser state, database dumps, and fixture identities are excluded.

The 47-test image/cover SQLite and MySQL runs predate the unfinished raster transition. The full 7,621-test run recorded two failures (ColourBudget and the proxy-dependent ProviderBreaker timeout), two existing public-render warnings and two discovery deprecations. The admin/account targeted run passed 154 tests and 2,033 assertions. Do not describe the full suite as green.

New assistant-created artwork has not been approved. Historical image screenshots and asset checks do not establish design approval, successful deployment, or installation of the colourful images generated during the outage. nextgengen was audited without tracked source fixes.
