# Spark art: prompts for AI-generated illustrations

The pictures beside every money target (vote pages, the voting list, the award page) and in the
announcement pop-up. Each subject exists in two styles:

| Style | Look | File suffix |
|---|---|---|
| **App** | Glossy 3D objects on a soft gradient, the bright promo style of fintech apps such as OPay | `-bold` |
| **Africa GATES** | Flat shapes, gold and green on a cream disc, a scatter of confetti (as `public/assets/img/illustrations/`) | `-house` |

The site ships hand-drawn vector versions of all sixteen (`public/assets/img/spark/*.svg`, made by
`scripts/spark-art.py`). **To replace one with a generated image, save it under the same name with
`.webp` (or `.png` / `.jpg`) in the same folder.** The site uses the generated file automatically and
falls back to the drawing if you remove it. No code change, no setting.

## Where each one appears

| Subject | Shown when |
|---|---|
| `rocket` | a target is under 25% |
| `megaphone` | 25–49%; also the pop-up's default picture |
| `target` | 50–74% |
| `trophy` | 75% and over; a settled vote target |
| `unlock` | the milestone or vote target is reached |
| `gift` | the reward is unlocked, or the top share of a ladder is reached |
| `coins` | the early steps of a ladder ("power up the reward") |
| `ballot` | available for the pop-up |

The two styles take turns, minute by minute. The pop-up uses whichever one is picked in
**Admin → Settings → Built-in illustration**.

## Format for every image

- **3:2, 1200 × 800 px**, exported as WebP (quality 80) or PNG. Keep each file under ~150 KB.
- **One object, centred, filling about 60% of the frame**, with clear space around it. It is shown
  from 84 px wide (the list) to 290 px wide (the pop-up), so it must read at a glance when small.
- **No text, letters, numbers, logos or brand marks** in the image. The words are on the page. The
  naira sign on the coins is the one exception.
- Colours: **Africa GATES green `#237b22`** (lighter `#5cc95a`, deeper `#1a6118`) and **gold
  `#f3b416`** (lighter `#ffe27a`), with small touches of **pink `#e0245e`** and **blue `#1f6fa3`**.
  Not OPay's brand colours or logo: the style is borrowed, the identity stays ours.

## The two style blocks

Paste the style block, then the subject line under it.

**App style (`-bold`)**

> Glossy 3D icon illustration in a playful fintech-app promo style, soft clay-like rendering
> with smooth gradients, gentle studio lighting from the top left, a crisp white highlight on each
> object, a soft contact shadow underneath. Bright, optimistic, energetic. Background: a soft
> radial blob of pale mint green (#e9f8ea fading to white) with two or three tiny floating
> sparkles in gold (#f3b416), green (#237b22) and pink (#e0245e). Main colours: rich green
> (#237b22 to #5cc95a) and warm gold (#f3b416 to #ffe27a). Centred, 3:2, 1200×800, no text, no
> logos, no people.

**Africa GATES style (`-house`)**

> Flat vector illustration, clean geometric shapes, no gradients, no outlines, subtle two-tone
> shading only. Warm cream background (#fbf1d8) with a slightly darker cream circle (#f6e3b4)
> behind the object and a soft oval shadow beneath it. A light scatter of confetti around the
> object: small rounded rectangles, dots and five-point stars in green (#237b22), gold (#f3b416)
> and orange (#e8892b). Celebratory, dignified, African awards ceremony mood. Centred, 3:2,
> 1200×800, no text, no logos, no people.

## Subjects

| File name (without extension) | Subject line |
|---|---|
| `rocket-bold` / `rocket-house` | A small rocket launching diagonally up to the right with a bright gold flame, a round blue porthole window, green nose cone and fins, a short trail of sparkles behind it. |
| `megaphone-bold` / `megaphone-house` | A green megaphone with a gold rim, tilted slightly up, three curved gold sound waves coming out of it, a pink handle. |
| `target-bold` / `target-house` | A round archery target (pink and white rings, gold bullseye) with a green arrow that has just hit the centre, a small burst of sparkle at the impact. |
| `trophy-bold` / `trophy-house` | A shining gold trophy cup with two handles and a star on the front, on a green base with a gold plate, small stars floating around it. |
| `unlock-bold` / `unlock-house` | A green padlock that has just sprung open (shackle lifted), with two gold coins bearing the naira sign ₦ beside it. |
| `gift-bold` / `gift-house` | A green gift box with a gold ribbon and a big gold bow, the lid slightly lifted, gold coins and sparkles bursting out of the top. |
| `coins-bold` / `coins-house` | Two neat stacks of gold coins of different heights, the front coin bearing the naira sign ₦, with a bold green arrow rising up beside them. |
| `ballot-bold` / `ballot-house` | A green ballot box with a white voting card dropping into the slot; the card has a pink heart on it, and a small gold tick floats beside the box. |

## Extra pop-up banners (wide)

For announcements you upload yourself (Settings → Pop-up image), the card's header is **16:9**.
Use the app style block with these subjects, at **1600 × 900**:

1. *Voting is open*: a green ballot box with voting cards flying in from both sides, confetti, a
   gold trophy in the background slightly out of focus.
2. *Voting extended*: a large gold stopwatch or clock with a green "+" badge, sparkles, the hands
   sweeping forward.
3. *Results day*: a gold trophy on a green podium under two soft spotlights, gold confetti falling.
4. *Nominations open*: a glowing gold star being placed into a green envelope, small stars around.
5. *Thank you, supporters*: a pair of hands forming a heart shape in green and gold, surrounded
   by floating hearts and sparkles (stylised 3D hands, no realistic skin detail).
6. *Rewards unlocked*: an open green gift box with gold coins and confetti bursting out.

Keep the right-hand quarter quieter: the close button sits over the top right corner of the image.
