# Event lead portal responsiveness and UX

Based on the user's latest ai-assistance branch at a3373979, including the new team loop, approval tiers and phone layout work.

The event lead portal now offers section navigation for long setup pages, brings the active subpage tab into view, and marks More pages when the current page is outside the phone shortcuts. Shared shell table rows retain visible column labels on phones and accessible table headings. Cards can shrink below their former minimum width, long titles wrap, form actions fit narrow screens, and the mobile navigation sheet respects the device safe area.

Team creation, role assignment and approval descriptions now match the current approval tiers. Controls follow the same permissions as their server actions: event leads create teams and roles, team leads can propose assignments in their teams, and owners archive roles. The approval screen now attaches the change tier to the proposal itself even when risk or assistant notes follow it; previously it wrote into a note section and emitted PHP warnings.

Changes reuse the shared shell and its palette/type tokens; section navigation is a dedicated lead template. Existing write endpoints, approval rules and grants are preserved. Minified CSS and JS twins are rebuilt.

Validation: 12 populated event-lead pages at 320, 390, 600, 768, 1024 and 1440 pixels; current-tab visibility, section links, phone navigation state, and 200% zoom. The browser check is reproducible with tools/browser/lead-portal-check.cjs using an isolated authenticated fixture. Tests cover Lead, EventTeams, EventTeamLoop, EventPermissions, Shell7 and console palette/type/contrast. Results are in event-lead-validation.json. This is a focused portal review, not an audit of every site page.
