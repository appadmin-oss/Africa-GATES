# Homepage images and default covers

The original six photographs, their 480/960 WebP files, cover patterns, kind palettes and glyphs are preserved. New utility artwork lives in `public/assets/img/utility/`: 26 generated two-dimensional editorial images, exported as transparent WebP at 256, 512 and 960 pixels with an allowlist/dimension manifest. Generated PNG masters are retained separately. These are larger decorative images; the owner’s original SVG interface icons remain unchanged. All text and actions remain HTML.

## Allocation

`ImageCatalog` owns approved file paths, responsive candidates, real descriptions and canonical identity. It checks shipped files before offering them. Two resolutions of the same photograph count as one image.

`ImageSelection` owns the used-image set for one render. Its daily rotation is deterministic, uses no tracking cookies, and adds no random query parameters to image URLs.

`HomepageImages` builds the hero, six honour tiles, topic cards, audience cards and closing decoration together. The controller caches the record data, then builds the current day's plan outside that cache. With no released recipients, there is one static daily hero: the other five photos remain in their correct honour categories and a matching utility replaces the category photo already used by the hero. Topics, audiences and closing decoration use utility artwork. Thus the fallback homepage uses each original photograph once, compared with 20 photo placements before the change.

Released recipient records keep their name, category, issuing programme and destination. Their photos cannot migrate to another person's record. A repeated portrait receives conceptual artwork in its second slot; it never receives a different person's face. Closing portraits require three distinct additional records, otherwise utility artwork is used. Existing challenge artwork and organisation logos remain attached to their records.

`selected-image.twig` renders the selection with its actual description, dimensions, responsive sources and loading priority. Utilities are decorative beside visible text. When approved files are absent, it renders the existing inline cover in a sized container rather than a broken image URL. This handles absent catalog files at render time; it does not probe remote uploads or guarantee availability of third-party media servers.

## Motion and controls

A single hero is static. Multiple genuine recognition slides have named selection buttons and Pause/Play. Manual selection pauses autoplay persistently; an explicit Play resumes it. Hover, keyboard focus, a hidden tab, the system motion preference and the site's reduced-motion setting also pause rotation. Automatic changes do not announce repeatedly through a live region. Controls progressively appear when JavaScript runs.

## Default covers

`CoverVariant` derives one of four stable compositions from subject, kind and record ID (or title when no ID exists). Only the decorative pattern is mirrored inside the unchanged readability mask. Colours, title/date, glyphs and logos retain their kind and orientation. These are stable record variations, not animated or per-refresh changes.

List/detail templates pass the same identity to the shared photo/cover partial. `CoverImage` uses the same selector for off-site images, and its cache key includes the variant version so old rendered images are regenerated.

## Rebuilding image exports

Generate the artwork with the image-generation tool, retain the resulting PNG masters, then map utility keys to their master paths in a JSON file. Run `python3 tools/assets/export-generated-images.py --sources /path/to/sources.json` (requires ImageMagick). The exporter only resizes and encodes approved generated images; it does not draw artwork or modify original photographs or SVG icons. The raster catalog offers only resolutions that actually exist.

## Checks

- `HomepageImagesTest`: complete fallback allocation, six-day rotation, semantic category matches, responsive-file identity, real-record associations, duplicate portraits, missing catalog files, responsive raster sources and missing individual resolutions.
- `CoverVariantTest`: deterministic composition, kind/palette preservation and matching on-page/off-site record identity.
- Homepage, activity, cover rendering, photo slots, Twig syntax and template context regressions run on SQLite and MySQL.
- Chromium: unique fallback images, successful loads, five viewport widths, automated WCAG checks, and production carousel script behaviour including persistent pause and reduced motion.

Automated accessibility checks supplement manual assistive-technology testing; they do not certify compatibility with every screen reader.
