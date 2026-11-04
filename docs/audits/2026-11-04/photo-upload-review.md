# Photo upload and display review — 4 November 2026

This change follows `7bf569d5` and `ea0aa08a` on `claude/ai-assistance-judges-features-1ka4oz`. A fresh origin fetch found no incoming branch commits. The preceding logic, account/admin, accessibility, responsiveness and image-selection work remains in the ancestry; no reset or replacement checkout was used. Original SVG icon shapes, default photographs and cover artwork were not changed.

## Corrected behavior

- Nominee self-service uploads now supply an empty public portrait. Removing that portrait promotes an active remaining photo or clears it. Explicit staff selections are preserved.
- Staff and member nominee galleries use a shared active-photo policy. Staff removal also updates self-service history, preventing a removed photo from reappearing in the other gallery. Metadata removal and portrait replacement are transactional.
- The repair migration fills empty nominee portraits from existing active photo records. It is repeatable and does not overwrite selected portraits. Referenced files must still exist on the server.
- Member accounts have an independent avatar field, authenticated upload/removal controller and service. The member is resolved from the session, never a submitted user ID. Account, account rail and site navigation display the saved photo, with the existing initials presentation when none is present. Registry identities are not automatically reassigned by matching email.
- The selected nominee portrait takes precedence over an independently linked registry avatar on ballots and recognition cards. Relevant view caches are invalidated on photo changes.
- WebP uploads retain WebP encoding and MIME. Temporary decode files are cleaned up on failures; actual file size is checked after moving the upload.

## Shared photo-addition UX

`partials/photo-upload.twig`, `photo-upload.js`, `photo-upload.css` and `Support/UploadLimits` own the common behavior. The reusable control is used for member, nominee and staff nominee photos. Progressive enhancement also covers existing visible image file inputs throughout member and staff pages, including multi-file additions. Established page-specific previews keep their own presentation.

Controls provide effective host size limits, selected filenames/sizes, previews, a clear-selection button, keyboard focus restoration, polite screen-reader feedback, supported-format checks, minimum-size feedback when specified, and native-form upload feedback. Custom fetch handlers retain their own submission state. Browser-back and form-reset states are restored. Uploads remain ordinary multipart forms without JavaScript.

Existing staff drop zones retain native label behavior and expose their file inputs to keyboard users. Dropped files dispatch the same change event as chosen files; single-photo fields take one file. Preview URLs are released when replaced or when a console page unloads.

A dedicated middleware returns HTTP 413 for multipart requests discarded by PHP because they exceed `post_max_size`, before their missing CSRF token could be mistaken for an expired session. It does not exempt uploads from authentication or CSRF checks.

## Validation

- SQLite: 207 focused regression tests, 980 assertions passed.
- MySQL 8.4 strict-mode parity: 207 focused regression tests, 980 assertions passed.
- Chromium: member upload, portrait in account/navigation, removal back to initials, nominee self-service upload and the public ballot image all passed. No page JavaScript errors. Account layout checked at 320, 390, 768 and 1440 pixels.
- Standalone shared-control browser checks: preview, selection clear/focus, invalid formats, oversized files, retry, reset and four viewport widths passed.
- Template parsing uses the real application Twig configuration; recognition tests cover selected portrait precedence and registry fallback.

These are targeted release checks, not a claim that every existing repository test or every production integration is green. Production media-provider credentials and existing user files are not available for live verification here. The rejected utility artwork remains outside the production archive.

## Deployment

Keep the deployed `.env`/secrets, `vendor/` and existing uploaded files. Overlay the release into the existing application root, keep the document root at `public/`, and run `php bin/console db:migrate` against the configured production database. The new migration is `2027_04_30_account_and_nominee_photos.php` (the next filename in this repository's existing migration sequence). Ensure the web server can write `public/uploads` and runtime `var` directories. Verify an uploaded image request and the updated account/ballot page after deployment. The archive omits production credentials and user-upload contents; it is not a replacement for those server directories.

Build a clean committed release with `python tools/release/build-production.py [output-directory]`. It exports the full commit, adds the rebuilt CSS bundle and deployment notes, verifies ZIP CRCs and per-file SHA-256 hashes, and excludes Composer dependencies, real secrets, upload contents and unapproved artwork.
