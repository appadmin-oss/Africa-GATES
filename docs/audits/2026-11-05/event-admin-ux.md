# Event administration UX — 5 November 2026

## Changes

The event editor now separates Details, Registration, Programme, Ticket design and Review. Its large inline stylesheet and script are external assets, and each section has its own template. The final review shows entered essentials beside the visibility choice. All existing editor fields remain in the same multipart form, preserving the controller's update markers, stable tier/session IDs and no-JavaScript behavior.

Event covers can be uploaded directly in the editor through the existing hardened image pipeline. A local copy is used so invitation attachments can read the same cover. Existing image addresses remain editable. Failed cover uploads report that the image was rejected while the other event changes were saved; no saved cover is silently replaced by an invalid file.

The event directory now supports literal search across title, city and venue, draft/published filters, stable pagination (20 records), event-local date/time display, useful empty states and clearer attendee/public-page links. Deletion remains available under More actions with confirmation. The shared event navigation connects the editor, attendees, invitations, discount codes, leads and vendor stands.

Shared stepped forms now keep a stable reference to their form, reveal the first invalid field and its containing step/disclosure, announce the validation message and focus the field. Counting required fields no longer dispatches validation events. Section changes focus the heading. New records no longer claim to be saved. Back/Next controls are hidden when enhancement is unavailable. The product editor benefits from these shared changes and has an accurate initial save state.

Responsive changes constrain the event editor and its inputs to narrow screens, wrap the numbered section controls, keep related navigation horizontally scrollable, and space the action bar above mobile chrome. Shared native photo pickers have consistent styling and visible keyboard focus.

## Verification

- 560 targeted tests and 9,298 assertions passed on SQLite and strict MySQL 8.4.
- 1,654 PHP files passed syntax checks.
- Source-contract tests now read the editor's actual partials and external script; assertions for tier ordering, colours and invitation links remain intact.
- Browser verification creates a draft, uploads a cover, confirms the saved cover, checks the review summary, exercises validation from a hidden step, searches the directory and opens attendees/codes/leads/invitations.
- Browser viewport checks cover 320, 390, 768 and 1440 pixels. Final browser output is recorded separately in validation.json.

These are targeted checks, not a complete repository or live production audit. Earlier commits remain in the branch ancestry. No new database migration is required for these event UX changes; earlier photo migrations still need to be applied on deployments that have not installed them.

## Delivery

The production archive is built with tools/release/build-production.py. It excludes Composer vendor/, real secrets/environment files, user upload contents, development tools/tests and rejected utility artwork. It includes all committed runtime changes and the production CSS bundle, with ZIP CRC and per-file SHA-256 verification.
