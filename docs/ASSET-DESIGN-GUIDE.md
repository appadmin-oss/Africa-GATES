# Africa GATES asset direction

Updated 4 November 2026 following the owner's feedback: clean and professional utility assets, transparent backgrounds where appropriate, people only when editorially relevant, and no new wheelchair figures unless explicitly requested. Asset research is separate from the accessibility of the working product. The shipped homepage utility system is documented in [HOMEPAGE-IMAGES.md](HOMEPAGE-IMAGES.md).

## Utility icons

Keep the owner's existing SVG icon system unchanged. Generated raster artwork belongs only in larger image placements. Do not replace interface icons with 3D renders. The assistant-created standalone SVG preview exports have been withdrawn; they are not the owner's original icons.

Preserve the existing `templates/admin/partials/nav-icons.twig` sprite and its style: a 24×24 viewBox, 1.8px round outline strokes, `fill="none"`, `stroke="currentColor"`. The existing public macro in `templates/partials/icons.twig` is the other integration point. Keep a coherent family within a surface; do not introduce a second runtime icon library merely to obtain a few glyphs.

Inline SVG and internal sprites can inherit the page’s currentColor. An SVG loaded through an img element does not inherit the surrounding CSS colour; use the inline/sprite renderer for theme-aware utility icons.

SVG backgrounds are transparent when no background shape is painted. Do not add white rectangles, shadows, textures, gradients, faces or illustration scenery to navigation/action icons. Keep geometry simple, visually balanced and legible at its actual 16–24px display size. Use viewBox scaling, explicit display dimensions and enough internal padding to avoid clipped strokes. An icon's drawing size and its control's touch-target size are separate: a 20px icon can sit in a 44px button.

Decorative icons beside visible text use `aria-hidden="true" focusable="false"`. Icon-only buttons receive an accessible name on the button; tooltips are supplementary. Use visible labels for unfamiliar or consequential actions. Status requires words or another discernible signal as well as colour. Icons must inherit theme colours and remain visible in forced-colours modes.

Lucide's contribution guide is a useful reference for icon-family consistency; Heroicons demonstrates inline SVG with `currentColor`. These are references, not a recommendation to mix both libraries into the project. If outside glyphs are copied, retain their applicable attribution/license notices: Heroicons is MIT; Lucide includes ISC and its inherited Feather license notices.

## Other standalone graphics

For the generated utility collection, use bespoke two-dimensional editorial artwork with restrained texture and varied house-palette colours: green, blue, rose/coral and amber gold. The owner explicitly wants the assets to bring colour and dynamism to the quiet site; do not constrain every image to forest green and gold. The broad 3D product-render direction was stopped before integration. Retain those generated drafts outside runtime assets. Three-dimensional imagery may suit a specific product preview, but is not the site's general image style.

Keep existing SVGs for interface icons. Generate the larger utility images as raster artwork; do not substitute hand-drawn SVG files for this generated collection. Use PNG or WebP with a real alpha channel for raster cutouts; retain a clean transparent canvas rather than baking in white. Check exported edges on both dark and light backgrounds to catch white matte halos. Keep source files and clear filenames; record dimensions, intended use and alt-text treatment in an asset manifest when assets are shipped. Avoid encoded text, tiny decorative details and unnecessary raster weight.

Use transparent backgrounds where the artwork should sit on the product's own surface. Full campaign or editorial scenes may intentionally have a forest-green background. Platform app/touch icons can have their required opaque background. Do not remove a scene's intentional background just to make every file transparent.

## Matching the actual website

Ground the art in the current rendered homepage and CLAUDE.md, rather than a generic stock-icon prompt. The site uses green-white surfaces, rounded cards, dark petrol ink, Playfair headings and DM Sans labels. Larger generated artwork should echo its rounded paper/card geometry, ticket and certificate details, recognition seals and selective gateway forms. Vary the dominant colour and composition by purpose. Keep fine grain subtle, with sparse interior details and clean transparent edges. Avoid neon, heavy crayon textures, metallic/clay depth, background glows and repeated logo stamps. Text stays in HTML. Interface colour values remain centralized in Accent; image work does not recolour the UI or replace original SVG icons.

## Editorial artwork

The owner likes the warm African community illustration and a mix of human warmth with clean composition. The mostly white object-only hero was rejected. Subsequent editorial concepts use rich forest green, restrained gold, clear shapes and a useful focal point. People are optional; do not add wheelchair figures to new assets unless the owner explicitly requests them. Fictional illustration must never be presented as a photograph or verified record of an actual award recipient. Existing recipient photography stays tied to real approved records.

Earlier generated concepts remain in `/workspace/generated_images`; they have not replaced production photographs or been added to runtime pages. Do not treat every generated draft as an approved asset.

## House art style: "Golden Welcome"

Named by the owner on 17 Nov 2026 as **the art style for Africa GATES** (first used for the
*Transcend into Love* voting extension). Reference: `docs/design/art-style/golden-welcome-reference.webp`.
Web copies: `public/assets/img/announcements/golden-welcome-{800,1200}.{webp,jpg}`.

**What makes it this style**
- **Look:** warm flat-vector editorial illustration with soft gradients, clean shapes and
  gentle light. Friendly and celebratory, never cartoonish or childish.
- **Palette:** deep emerald/forest-green sky, warm gold (gates, finials, light), cream
  stone and walls, coral and terracotta accents (hibiscus, hearts, fabric), soft sunset
  glow at the horizon. The greens and golds sit close to the site's brand green and gold.
- **Motifs:** an ornate golden gate with Nigerian/West African geometric patterning (the
  "GATES" in the name), opening onto a lit path; a ribbon of light forming a heart; small
  floating hearts and confetti; tropical leaves and hibiscus framing the corners.
- **People:** diverse Nigerians and Africans in a believable mix of dress (gele and iro,
  agbada and fila, ankara shirts, a student with books, a photographer). They are smiling,
  arriving and welcoming each other. Movement runs toward the gate: people are being let
  in, not left out.
- **Composition:** the gate is the centred focal point, figures frame it at the left and
  right edges, and the upper third stays open sky (crop-safe for a title or a pop-up's
  close button). It reads at 4:3 and survives a centre crop to 1:1.
- **Never:** text, letters or numbers in the image (words stay in HTML); logos; neon or
  heavy glow; photographic realism presented as real people; real recipients' likenesses.

**Prompt seed** (append the subject):
> Warm, friendly flat-vector illustration in a modern African editorial style, "Golden
> Welcome": an ornate golden gate with West African geometric patterning, deep emerald
> sky, warm gold, cream stone, coral accents, tropical leaves and hibiscus framing the
> corners, soft sunset light, diverse smiling Nigerians in gele, agbada and ankara,
> gentle gradients, open upper third. Joyful, inclusive, premium. No text, no letters,
> no logos, no numbers.

**Alt text pattern:** describe what it shows, not the style. Example: "Illustration: a
golden gate stands open under a heart of light as smiling people in Nigerian dress arrive
and welcome each other."

## Accessibility and responsive use

Follow the purpose of the image, as W3C recommends. Decorative images receive `alt=""`; informative images receive concise text conveying their essential information; functional images describe the action. Complex charts need their information in accessible text/tables. Keep headlines, instructions, dates and calls to action as HTML, not pixels in an image.

For shipped raster artwork, produce appropriately sized responsive variants, explicit width/height and a documented crop-safe focal area. Lazy-load below-the-fold images; respect the site's existing low-data preferences. Preserve meaning at narrow widths and browser zoom, and ensure a failed or disabled image does not remove the next action.

## Primary sources consulted

- [W3C Images Tutorial](https://www.w3.org/WAI/tutorials/images/), read from its [official source](https://github.com/w3c/wai-tutorials/blob/master-2.0/content/images/index.md).
- [W3C Decorative Images](https://github.com/w3c/wai-tutorials/blob/master-2.0/content/images/decorative.md).
- [Heroicons usage and license](https://github.com/tailwindlabs/heroicons#basic-usage).
- [Lucide contribution/design guidance](https://github.com/lucide-icons/lucide/blob/main/CONTRIBUTING.md) and [license](https://github.com/lucide-icons/lucide/blob/main/LICENSE).
- Existing Africa GATES icon sprites, `src/Support/Accent.php`, and `docs/design-handoffs/HANDOFF-flier.md` (campaign surface styles).

The direct W3C and Microsoft design websites were blocked by the environment's network policy; W3C's official GitHub source was used. No claim is made to have reviewed the blocked Microsoft material.
