# Africa GATES: homepage and default-image study

Implementation now lives in [HOMEPAGE-IMAGES.md](../../HOMEPAGE-IMAGES.md); the findings below preserve the pre-change study.

Studied 31 October 2026. This is research and a proposal; homepage code and existing default image files have not been changed for this study. The browser inspection used the local audit database, not a production deployment.

## What the site currently does

`src/Services/HomeFront.php` defines six owner-supplied photographs: teacher, nurse, community-leader, creative, business and young-leader. Each has 480px and 960px WebP versions: twelve files represent six actual photographs.

`HomeController` obtains real recognitions, campaign/challenge data and counts through `home:front:v7`, cached for five minutes. Image choices for the generic homepage sections are separately fixed in PHP/Twig; rotating the hero does not change those choices.

In the inspected fallback state, the rendered page contains:

| Area | Default-photo placements |
|---|---:|
| Hero slides | 4 |
| Who do you want to honour? | 6 |
| Featured topic cards | 3 |
| Recognise with Africa GATES | 4 |
| Closing portraits | 3 |
| Total | 20 |

The hero figure includes slides initially hidden by its carousel. Per-photo totals are teacher 4, community-leader 4, nurse 3, young-leader 3, creative 3 and business 3. The rendered inventory is `/workspace/homepage-image-study/rendered-inventory.json`. Desktop and phone screenshots are in that directory.

Even when genuine winners supply both the hero and closing portraits, the other three sections still allocate thirteen placements to the six generic photographs. Thus repetition is built into the section mapping, rather than being solely a carousel issue.

## There are two different default systems

1. **Homepage photographs:** fixed selections in `HomeFront::heroSlides()`, `HomeFront::honourTiles()`, `HomeController::closingPics()` and two inline arrays in `templates/pages/home.twig`. The sections do not coordinate their choices.
2. **Record covers and avatars:** `partials/photo.twig` delegates missing person photos to `AvatarMark`/`avatar.twig`, and missing object photos to `CoverKind`/`cover.twig`. Covers already have eight patterns and kind-based tones, including product-category handling. They often look alike because records of the same kind share the same composition. Initial avatars already have stable, ID-derived tones.

`CoverImage` separately renders off-site share images from the same cover resolver. A variation design must keep those images consistent with the related record; randomly changing only the website could make the card and shared preview disagree.

## Other findings

- The Communities card uses the creative/designer photograph while describing a community celebration. I inspected the actual file: it depicts a designer draping fabric. Dynamic selection must carry the actual image description, while purely decorative contexts should retain empty alt text.
- Hero autoplay advances every 5.5 seconds. It pauses on hover/focus, hidden tabs and reduced motion, but the inspected page has no explicit Pause/Play button. W3C recommends an explicit control for automatic animation. Choosing images dynamically does not require autoplay.
- Organisation cards use the 960px source directly, despite smaller existing versions. Some homepage image contexts already use srcset; selection should preserve and consistently pass the matching responsive metadata. CSS already reserves many image slots, so missing intrinsic attributes alone are not evidence of a measured layout-shift defect.

## Recommended approach

### 1. Preserve the existing originals and design language

Retain every current default photograph, its two sizes, existing cover patterns, colours and glyphs. Actual recipient/organisation/product uploads retain their correct identity. Keep person avatars stable by ID.

### 2. Centralise selection metadata

Introduce an ImageCatalog and a homepage ImageSelection service. The catalog describes an asset's canonical ID, existing paths, responsive sizes, allowed uses, actual alternative text and crop/focal metadata. The selector receives all homepage slots together, assigns eligible images and records used canonical IDs. Different resolutions of one photograph must never count as different images.

Selection priority should protect semantic matches: a teacher card must continue to show the teacher photograph, not an unrelated picture chosen merely to fill a gap. Spread unavoidable repeats apart and favour less-used eligible assets. Real records continue to come from the existing released-results service.

### 3. Start with deterministic rotation

Use a day/edition seed for generic homepage selection, and render the selected image immediately on the server. Hold it stable while the visitor reads. Start with a shared daily seed, without introducing a new visitor-tracking cookie solely for image variety. Keep asset URLs and their existing versioning stable; do not add random cache-busting parameters.

The site's existing five-minute cache stores content datasets. Coordinate the selection seed/cache key explicitly rather than inserting a random result into that shared dataset. Per-visitor selection is possible later, but its caching implications must be deliberate.

### 4. Make default covers varied but recognisable

An item-ID-derived variant can select approved compositions within the existing kind's visual family. Its kind, status colour, title/date and logo remain truthful. The current base design remains the fallback. On-site and shared-image rendering must use the same stable variant.

This would be an additional presentation layer; it does not require rewriting or replacing existing default image files. Exact permitted variations should be previewed before implementation, given the instruction to preserve the current defaults.

### 5. Use utility imagery where it actually serves the content

The Events, organiser and shop topic cards are good candidates for calendar/ticket, award-management and frame/certificate utility graphics. The existing default photos remain available. Showing utilities in those slots would be a separate, visible presentation change to preview, not an unannounced replacement of your defaults.

Keep utility assets simple, professional, transparent where appropriate and without added people or wheelchair figures. Informative labels and actions remain HTML.

## Important limit

A strict zero-repeat page cannot be produced by shuffling six pictures into twenty photo placements. The six honour categories already require all six matching images. Full deduplication requires fewer repeated photo placements or additional approved images/utility assets. With all placements and their exact image families retained, the selector can provide controlled variety and reduce clustering, but cannot honestly promise no duplicates.

## Verification before implementation is considered complete

Test canonical asset IDs across the entire page, first-page and repeated-visit seeds, image/category compatibility, empty and real-recognition states, unchanged original file checksums, missing-file fallback and matching alt text. Verify mobile crops, image dimensions, responsive candidates, loading priority, reduced motion and keyboard controls. Keep the main hero eager/high-priority and defer below-the-fold images. Check the rendered page rather than counting twelve resolution files as twelve unique photographs.

## Primary research sources

- [MDN: img element](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/img): responsive sources, sizes, fetch priority, lazy loading and dimensions. Read from the live [official source](https://github.com/mdn/content/blob/main/files/en-us/web/html/reference/elements/img/index.md).
- [MDN: Lazy loading](https://developer.mozilla.org/en-US/docs/Web/Performance/Guides/Lazy_loading): prioritising critical visible resources and deferring non-critical images. Read from the [official source](https://github.com/mdn/content/blob/main/files/en-us/web/performance/guides/lazy_loading/index.md).
- [MDN: HTTP caching](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/Caching): variation and personalised-response caching. Read from the [official source](https://github.com/mdn/content/blob/main/files/en-us/web/http/guides/caching/index.md).
- [W3C: carousel animation controls](https://www.w3.org/WAI/tutorials/carousels/animations/): explicit stop/resume controls and pause on hover/focus. Read from the [official tutorial source](https://github.com/w3c/wai-tutorials/blob/master-2.0/content/carousels/animations.md).
- [W3C: images](https://www.w3.org/WAI/tutorials/images/): purpose-based alternative text, decorative images and avoiding essential text baked into images, read through its official repository.

The selection architecture, slot priorities and daily rotation are recommendations based on this site's constraints; the cited standards do not prescribe a specific randomisation algorithm or prove a conversion benefit.
