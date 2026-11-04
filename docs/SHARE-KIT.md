# Nominee share kit — cards and video

Owner: Africa GATES product · Last updated: 2 Nov 2026

## What a nominee gets

`/vote/{programme}/{id-name}/flier` is the share kit. The nominee chooses:

| Choice | Values | Query |
|---|---|---|
| Size | Feed 1080×1350 · Status 1080×1920 · Square 1080×1080 · Link card 1200×630 | `size=feed\|status\|square\|link` |
| Style | Photo (photo leads) · Statement (name and their profile line lead) | `style=portrait\|statement` |
| Colour | the five site cover tones | `tone=gold\|green\|info\|live\|stone` |
| Ground | Light (tone wash) · Bold (deep tone) | `mode=light\|bold` |

Every choice is a plain link, so the kit works with JavaScript off. The preview, the
PNG download (`flier.png?…`), the print SVG (`flier.svg?…`) and the link preview
(`card.png`, always the link size) are the same card.

Each award has a default colour (`ShareCardSpec::defaultTone`): Business → gold,
Leadership/Impact/Community → green, Choral/Choir/Music → rose (`live`),
Teacher/Principal/School/Child → blue (`info`), anything else → green.

The share video (`…/video?tone=…`) is the same card in motion: Hook → Card → Proof →
Action, 15 s, 9:16, recorded in the visitor's browser (`share-video-award.js`).
Giving and event videos keep `share-video.js`.

## Where it lives

- `src/Services/ShareCardSpec.php` — the design: sizes, tones, every rule.
- `src/Services/ShareCardRenderer.php` — one layout plan, written as SVG or PNG.
- `src/Services/FlierService.php` — `svg()`, `png()`, `ogCard()` delegate to the two above.
- `src/Controllers/FlierController.php` — reads the four options, builds the chooser.
- `templates/pages/vote-flier.twig`, `public/assets/css/components/vote-pages.css` — the kit page.
- `public/assets/js/share-video-award.js`, `src/Services/ShareVideo.php` — the video.
- Design source: the "Nominee share kit" page of the Alimosho Awards design canvas.

## Rules that must hold (pinned in `tests/Unit/ShareCardTest.php`)

- Hierarchy: category → name → organisation · standing → VOTE NOW + link + QR → jury line. The ask appears once.
- Rank and standing only when the category has two or more nominees.
- No photo → the tone's rings and the nominee's initials, never an empty box.
- Long or accented names step down in size; nothing is drawn off the card.
- Tone marks GD cannot compose (ọ̀, ẹ́) are drawn as their spacing forms over the letter.
- No free text from the query string. The Statement line is the nominee's profile tagline, or the rally line.
- Bold grounds keep white type and the tone label at 4.5:1 or better.
- The link is never cut short: it steps down in size to fit, because somebody types it off a status.
- The video types no colour of its own. Its palette arrives in the story (`ShareCardSpec::videoPalette`, from `Accent`), so it cannot drift from the card.
- The video page stops playback and any recording when it is left in place (`agPage.leave`).

## Checking a change

1. `vendor/bin/phpunit --no-coverage tests/Unit/ShareCardTest.php tests/Unit/OgCardTest.php tests/Unit/StandingsAndFlierTest.php`
2. Open a nominee's `/flier` page, try each size, then download the PNG and check it on a phone.
3. Open `/video`, press Play, then "Save the video", and check the file plays.
