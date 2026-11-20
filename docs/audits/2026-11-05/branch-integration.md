# Branch integration review — 5 November 2026

The original checkout fetched only master. Its saved origin reference for the working branch was stale at 713d180c. A normal push was rejected, and an explicit branch fetch obtained 66 newer commits through 8779f5f9. The local fetch configuration now also includes the working branch. No force push or history rewrite was used.

The merge preserves the remote branch history and the six local audit/photo/event commits. Fourteen files required conflict resolution. Review covered both those conflicts and interacting changes that merged automatically:

- Event directory search/pagination coexist with lead summaries and direct links to lead management. Existing event roles, ownership and approval gates are retained. Invalid map links preserve the previous saved link; failed cover uploads preserve other event fields.
- The latest nominee layout is retained. The public nominee portrait takes precedence over a stale registry avatar. NomineePhoto owns nominee selection (the nominee's latest choice wins); NomineePortrait delegates selection while retaining gallery removal, locking and cache invalidation.
- The latest branch introduced gates_users.photo_path while the profile editor used avatar_path. Writers now keep both columns aligned; readers prefer photo_path with compatibility fallback. Removal clears both, and taking down a nominee photo retires that same account photo without erasing a different account selection. Tests exercise the actual stored values and header reader.
- Screenshot review caught an oversized sticky event action bar on phones; mobile actions now stay in document flow below the fields, while desktop keeps its sticky actions.
- New public navigation lifecycle hooks remount upload controls and accessibility behavior after page swaps. Hero rotation retains Pause/Play, focus and reduced-motion behavior while using the new page lifecycle to clean up timers/listeners. Deferred images and versioned responsive URLs preserve the latest speed improvements.
- Rejected experimental artwork cannot become live from leftover files: the catalogue requires explicit production approval. Original photographs and default covers remain; updated tests check the current production fallback and responsive original photographs.
- The events-feed corrupt UTF-8 fixture now checks strict MySQL rejection at storage and SQLite recovery at serialization; the original fixture could not exist in a MySQL UTF-8 column.
- Source-contract tests follow the modular event templates and image catalogue. Assertions for new branch behavior remain; the older conflicting assertion that staff portraits always beat a nominee's own choice now follows the latest branch's explicit ownership policy.

Validation results are recorded in merge-validation.json. The earlier event-admin report describes pre-merge checks and is not the evidence for this integrated revision.
