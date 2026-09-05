# Handoff — “I will be there” flier

**Design:** `I Will Be There Flier.dc.html`
**Target:** `templates/pages/events/detail.twig` (entry points) + a new flier renderer
**Depends on:** the existing `referral` object, `event.*` fields, `is_past`. No new libraries beyond a headless renderer and a QR encoder.

> **BUILT — 26 Aug 2026.** Everything below is the handoff as received. What shipped differs
> in six places, each marked **⟶ BUILT** inline, and §10's three open decisions are answered
> in §11. The short version:
>
> - **`I Will Be There Flier.dc.html` was not in the attachment**, so there were no
>   coordinates, type sizes or colours to port. The prose here is complete about geometry that
>   matters — the QR module and pad measurements, the three canvas sizes, the state table, the
>   copy — and all of it was taken exactly. The layout is authored; see §11.4.
> - **A headless renderer is impossible on this host** and was not needed. §4's reasoning
>   against client-side canvas stands and is the reason it is server-side GD.
> - **There was no QR encoder that could hold a URL.** That was a prerequisite, not a
>   detail — see §11.5.
> - **Both `#237b22` contrast notes in §8 were right**, and one of them changed the design.

---

## 1. What this is, in one paragraph

A shareable image an attendee generates for themselves: their name, the event, and a QR. It is **not** an organiser promo asset. Anyone can make one — no account, no ticket — but a confirmed ticket earns a visible mark and turns the QR into that person's `referral.link`, so the flier pays them. The referral programme already exists in `detail.twig` as a read-only input behind a sign-in, which is why nobody uses it; this is the same link in a form people already share unprompted.

## 2. The two states

One template, one boolean. Do not build two designs.

| | Open (anyone) | Confirmed (ticket) |
|---|---|---|
| Claim | “I will be there” | “I will be there” |
| Mark | none | `Ticket confirmed` + tier chip |
| Second line | “Come with me.” (gold, invitation) | Name + what they do |
| QR target | plain event URL | `referral.link` + campaign param |
| QR label | `GET YOUR TICKET` | `SCAN FOR YOUR TICKET` |
| Requires | a name | paid, free-tier, or comped |

**Never print the negative.** No “ticket not confirmed”, no “pending”, no dimmed badge. Absence of the mark is the signal. A flier that states its own weakness is a flier nobody sends, and it takes the reach with it.

**Tier chip only where it flatters.** Patron and Supporter earn it; General does not get a badge that reads as “cheapest”. Deliberate omission, not a missing case.

## 3. Formats

Three artboards, all 1080 wide, all designed to survive WhatsApp's recompression and thumbnail crop.

| Artboard | Size | Use | Notes |
|---|---|---|---|
| `story` | 1080×1920 | WhatsApp status, IG/FB story | Primary. Photo top, claim on a single gradient plate below — the face lands in WhatsApp's centre-square thumbnail crop |
| `square` | 1080×1080 | display picture, feeds | Hard vertical split. Nothing important within 90px of an edge, because a DP renders as a circle |
| `plain` | 1080×1080 | **no photo — the most common case** | Warm ground, typographic. A different design, not a degraded one. Offer this first |

No fourth size. A third format is a third thing to keep in sync and a choice nobody wants to make.

## 4. Rendering

**Server-side, headless. Not canvas in the browser.** These layouts depend on Playfair Display at 900; a client-side export inherits whatever the device has and silently substitutes.

> **⟶ BUILT — server-side, and GD rather than headless.**
>
> There is no shell on this production host. That constraint runs through the whole platform —
> it is why maintenance is a token-gated HTTP endpoint instead of cron — so a headless
> Chromium cannot be installed and cannot be kept alive. The reasoning for rejecting canvas is
> right and is exactly why this is server-side: the faces are bundled in `resources/fonts/`
> and are the same four the ticket PDF and the nominee flier already use.
>
> That is also not a workaround invented here. `FlierService` has rendered the nominee share
> card with GD since it shipped, and its seven raster primitives — text with the letter-spacing
> GD has no concept of, wrapping measured against the real face, gradients, cover-crop, remote
> photo loading — moved into a `FlierRaster` trait so this draws with the same hands. `cover()`
> is the clearest reason: two renderers with their own crop maths is how one graphic centres a
> face and another cuts the chin off, and neither looks wrong on its own.

```
GET /events/{slug}/flier.png?fmt=story|square|plain&t={token}
```

> **⟶ BUILT, plus a POST that does the whole thing in one request.**
>
> The GET is exactly this, and it is what makes a flier a shareable URL. But the photo forced a
> second shape: the design above implies upload → store → mint → GET, and that has a storage
> layer to secure, expire and sweep. §9 asks for the discard to be confirmed "at the storage
> layer, not by reading the code".
>
> The easiest way to keep that promise is to have no storage layer. `POST` to the same path
> carries the photo, decodes it from PHP's own upload temp, draws the flier, and returns the
> PNG in the same response — and PHP unlinks the temp itself when the request ends. Nothing to
> sweep, no key to expire, no cron to forget, and nothing for a later bug to leave behind.
>
> It also happens to be what the share path wants: the browser holds the result as a blob,
> which is exactly `navigator.share`'s `files` payload, so sharing needs no second fetch.

- One Twig partial renders all three formats and both states — same fields, format switch on layout, state switch on the mark and second line.
- Cache per `(registration|guest token, fmt)`. Invalidate when payment confirms, so an open flier upgrades to confirmed.
- `t` is a signed short-lived token, not a registration id. An enumerable URL means anyone can render a flier with someone else's name on it.
- **Refuse when `is_past` or the event is cancelled.** Return 410, not a flier. A live QR on a finished event is worse than no flier at all.

### The QR

- Encodes `referral.link` (confirmed) or the event URL (open), plus a campaign param so the flier's traffic is attributable separately from a pasted link.
- **Error correction level Q.**

> **⟶ BUILT, and it needed an encoder that did not exist.**
>
> `Support\Qr` was version 1, level Q, alphanumeric, sixteen characters — deliberately, and
> exactly right for a ticket code. A referral link is 56 bytes and contains `?` and `=`, which
> are not in the alphanumeric alphabet at all: not a longer string, a different KIND of string.
> So byte mode and versions 2–6 with real multi-block interleaving, stopping short of version 7
> where the version-information area begins. Level Q throughout, as specified.
>
> Verified by DECODING, not by matching another encoder: every vector is rendered and read back
> by OpenCV, 43/43 across both sides of each version boundary. A cross-check against `segno`
> was tried and abandoned — only the twelve payloads needing no padding matched byte for byte,
> because the region after the terminator is not uniquely determined and two encoders can pad
> differently and both be right.

- **Quiet zone is 4 modules of white on every side.** At these sizes that is **26–28px**, not the 14px a comfortable-looking inset gives you. This was a real defect in the first pass: an undersized quiet zone still *looks* correct and stops scanning once WhatsApp recompresses the image. Measured in the design: 7.04px module / 28px pad on story and open, 5.76/26 on square, 6.16/27 on plain.
- The pattern never sits directly on the dark ground — it always has its own white plate.

> **⟶ BUILT at four modules — and a correction about why.**
>
> The measurements here (7.04/28, 5.76/26, 6.16/27) are implemented as 7/28, 6/26 and 6/27, and
> the plate is verified white on all four sides by sampling the rendered raster rather than
> trusted from the constants.
>
> I first reported that a simulation confirmed your warning: render the symbol, put it through
> downscale-and-JPEG twice, and watch the 2-module version fail on the second pass. **That was
> wrong and I have withdrawn it.** Probing further, shifting the plate by ONE PIXEL flips pass
> to fail, and the 2-module zone sometimes decodes where the 4-module one does not. The harness
> measures how `cv2.resize` and JPEG happen to land on the module grid against OpenCV's
> detector — not robustness. A real scanner samples continuously through a camera and
> thresholds adaptively, and none of that is modelled.
>
> So the four modules stand on the **specification**, which is the right reason and was always
> sufficient: the quiet zone is part of the symbol, not a margin around it. The script is kept
> at `tests/Support/qr-recompression-check.py` as a smoke check that a rendered flier's code
> decodes at all, with its own limits written at the top.
>
> Your underlying point is untouched and is why the number is not negotiable: an undersized
> quiet zone still looks completely correct on screen.

### The photo

- Optional everywhere, and the copy says so. Most people will not upload one.
- Face-centre crop to the slot, then **discard**. Do not retain the upload, and say that in the UI — asking someone to upload a photo of their face to an events site needs a stated reason to trust it.

> **⟶ BUILT, and the promise is structural rather than a policy.** The file is never written to
> this disk at all — see §4's note. The UI says so at the point of upload, in those words, and a
> test lists every directory the platform writes to before and after a real upload and asserts
> nothing new appeared. It also asserts the photo DID reach the image, because an upload that
> was silently ignored would pass a "nothing was written" test perfectly.
- The reframe step is not optional polish. A mis-cropped selfie is the main reason a generated flier gets binned, and the type sits over the lower third, so a guide line showing what gets covered is the difference between a sent flier and a discarded one.

> **⟶ BUILT — drag, and a slider beside it.** The frame is dragged with pointer events, so one
> handler covers mouse, touch and pen, and the shaded band showing what the type covers is drawn
> over the preview. Pinch-to-zoom is **not** implemented: a cover-crop has one degree of freedom
> per axis and zoom would be a third, so what shipped is the focal point — which is the part
> that decides whether a face survives the crop. A range input does the same job for a keyboard
> and a screen reader, because a drag-only control is a control some people cannot use.
>
> The focal point is honoured server-side by `FlierRaster::cover()`, and a test renders the same
> photo at the top and the bottom of the frame and asserts the two images differ — otherwise the
> reframe screen would be a control with no effect, which looks identical to one that works.

## 5. Entry points in `detail.twig`

1. **After registration succeeds** — the confirmed path. `evReg`'s success state already renders `View your ticket →`; the flier card goes with it. This is the only moment someone is proud, and asking then costs nothing.
2. **On the event page, ungated** — the open path. Sits near the referral card. One input (name), one button.
3. **From the account area** — regenerate, switch format, re-share.

The open entry carries one line of honest incentive: *register and the code on your flier becomes your referral link — 10% of every ticket bought through it.* No nagging, no interstitial. The upgrade is simply worth something.

## 6. Generator states — build all of them

The first pass drew only the confirmed, photo-supplied, share-works case. That is the demo, not the feature.

| State | Requirement |
|---|---|
| Entry, not registered | Name only. Says no ticket needed |
| Entry, confirmed | Name prefilled, tier known, referral QR |
| Photo picker | Optional, with a stated reason to trust it |
| Reframe | Drag/pinch, with the type line shown |
| Caption | Optional, goes in the **share message, not on the image** |
| Generating | Progress + Cancel, and **nothing else pressable** |
| Ready, native share | One “Share to WhatsApp” |
| Ready, no native share | Save + Copy caption, with the reason stated |
| Generation failed | Retry, and the entered name preserved |
| Past / cancelled event | Explain, offer the events calendar |

**Generating and Ready are separate screens.** A progress bar above an enabled “Save the image” is an interface offering an action it cannot perform — and “Copy the caption *again*” implies a copy that never happened. This was caught in review and it is the kind of thing that ships if the states are merged “to save a screen”.

### Sharing

`navigator.share` with a `files` payload is **not universally available**, so a lone “Share to WhatsApp” button promises something the device may not do. Feature-detect:

```js
const canShareFile = navigator.canShare?.({ files: [testFile] }) ?? false;
```

- Supported → one primary button, share sheet with image + caption.
- Not supported → **Save the image** as primary, caption copied to clipboard, and one sentence saying why there are two steps. Do not silently fall back to `wa.me`, which cannot attach an image and produces a link with no flier.

## 7. Copy

The caption is prefilled with event name, date and link, and **the first line is left blank** for the sharer. A fully prefilled caption gets sent verbatim and reads like an ad; an empty one gets sent with no context. Blank first line, everything else supplied.

## 8. Accessibility and privacy

- The QR is `role="img"` with a label naming its destination. A code with no text alternative is unreachable to a screen reader and unusable to anyone who cannot scan it — **print the short URL as text beside it** in a future pass.
- Generator: real labels on the name and caption fields, 44px targets, visible focus, progress announced via `role="progressbar"`.
- Contrast note from review: `#237b22` measures **4.12:1 on a `#dfe3dd` ground** — fine on white cards, short of 4.5:1 on a tinted page. Check link and small-text colours against the ground they actually sit on, not against white.
- State the photo-discard policy at the point of upload, not in a policy page.

> **⟶ BUILT — all ten, and driven in a real browser.**
>
> The state machine was exercised in headless Chromium against the rendered page with `fetch`
> stubbed, so every stage was entered and every transition taken. What came back:
>
> | checked | result |
> |---|---|
> | Generating | one screen, and the only pressable button is `Cancel` |
> | Cancel | returns to Entry with the name **and** the caption still there |
> | Failed | the server's own message, `Try again`, nothing typed lost |
> | Ready, no file share | `Save the image` primary, `Copy the caption` beside it |
> | Confirmed | posts `t=<token>` and no name |
> | Reframe | type line visible; render posts `fmt=story`, the photo, and `focus_x` |
> | Any stage | exactly one screen visible — never two |
>
> Cancel uses an `AbortController` and really aborts. A Cancel that only changes the screen
> leaves the request running and the next one racing it, so the second flier can land after the
> first and overwrite it.
>
> `navigator.canShare({files:[…]})` is asked with a real one-byte `File`, which is the only
> honest test — the API exists on desktop Chrome and refuses files, and exists on iOS Safari and
> accepts them. In headless Chrome it correctly reported false and the two-step path appeared.
>
> Targets measured in the browser: every control 44px or more, the primary CTA 52.

## 9. Verify before shipping

- [ ] Scan every format's QR **after** sending it through WhatsApp, both directions, on a real phone.
- [ ] Confirmed flier renders with tier chip; General has no chip.
- [ ] Open flier has no mark and no negative language anywhere.
- [ ] Paying upgrades an already-generated open flier to confirmed.
- [ ] `is_past` and cancelled both return 410, not an image.
- [ ] Token cannot be altered to render another person's name.
- [ ] Long name (40+ chars) does not break any of the three layouts.
- [ ] No-photo path produces the `plain` design, not a dark layout with a hole.
- [ ] Generating screen has nothing pressable but Cancel.
- [ ] Fallback path tested on a browser without file share.
- [ ] Photo upload is not persisted — confirm at the storage layer, not by reading the code.

> **⟶ Where this list stands.**
>
> - [x] **Confirmed flier renders with the tier chip; General has no chip.** An allow-list, and
>       a test walks Patron / Supporter / VIP / General / Standard / blank.
> - [x] **Open flier has no mark and no negative language anywhere.** A test strips the comments
>       and greps the renderer's whole source for "not confirmed", "unconfirmed", "pending",
>       "no ticket".
> - [x] **`is_past` and cancelled both return 410, not an image.** Both, plus a token minted for
>       one event refusing to render under another's slug.
> - [x] **Token cannot be altered to render another person's name.** The forged-payload attack
>       is the test, not just a flipped signature.
> - [x] **Long name (40+ chars) does not break any of the three layouts.** And a long title, in
>       the margins test.
> - [x] **No-photo path produces the `plain` design.** In the renderer, so a hand-written URL
>       cannot talk a format into drawing the hole.
> - [x] **Generating screen has nothing pressable but Cancel.** Enumerated in the browser.
> - [x] **Fallback path tested on a browser without file share.** Headless Chrome is one.
> - [x] **Photo upload is not persisted — confirmed at the storage layer.** Every directory the
>       platform writes to is listed before and after a real upload.
> - [ ] **Scan every format's QR after sending it through WhatsApp, on a real phone.** Still
>       open, and now the ONLY way to answer it: the simulation turned out to be too noisy to
>       stand in for a handset — see the correction under §4's quiet-zone note.
> - [x] **Paying upgrades an already-generated open flier to confirmed.** The register response
>       mints the token and the generator picks it up, so the next render carries the mark.

## 10. Open decisions

1. **Should the open flier be rate-limited?** Right now anyone can generate one with any name. A signed token per session limits abuse, but adds friction to the ungated path that is the whole point. My call: allow it, watch it, add friction only if abused.
2. **Short URL as text beside the QR.** Recommended for accessibility and for anyone screenshotting the flier off a screen. Needs a short-link service decision. — *answered in §11.2: no shortener; the referral code is already print-shaped and the platform already has one minter for the URL.*
3. **Whether a free-tier registration counts as “confirmed”.** I have assumed yes — they have a real ticket. Worth confirming, because it decides whether the mark means *paid* or *ticketed*.

---

## 11. The open decisions, answered

### 11.1 · Rate-limiting the open flier — allowed, as you called it

Your call was "allow it, watch it, add friction only if abused", and that is what shipped. The
only gate is the CSRF check, which stops a drive-by without asking a visitor for anything —
and the ungated path is the entire feature, so friction there is friction on the point.

What makes this safer than it sounds: the name is capped at 60 characters, stripped of control
characters, and never stored. There is no row created, nothing to enumerate afterwards, and the
image is `no-store`. The abuse case is somebody generating a flier with a name that is not
theirs, which they could also do in any image editor in less time.

### 11.2 · The short URL beside the QR — decided: no shortener, and the platform decided it

A QR is unreachable to a screen reader and unusable to anyone who cannot scan, and you
recommended printing a short URL beside it. That reads as needing a shortener. It does not, and
the reason is that this platform already answered the question the shortener would answer.

**What is printed** is the full referral address with the scheme dropped, because nobody types
one — `afg.afrovanguard.org.ng/events/{slug}?ref=AGXXXXXX`. It is derived from the same string
the code encodes rather than assembled again, so the two cannot drift, and typing it credits the
sharer exactly as scanning does. An address without the referral parameter would have been a
link that does not credit the sharer, which is the whole point of the flier.

**Why that is already short enough.** A referral code is `AG` plus six characters from an
alphabet with no `O`/`0`, no `I`/`1`/`L` and no vowels. `ReferralService::mint()` explains the
vowels in its own note: the generator cannot produce a real word by accident, *"which on a code
somebody prints is worth more than the entropy it costs"*. That sentence was written for exactly
this surface. And `ReferralCaptureMiddleware` already accepts `r` as well as `ref` — *"short
links get shortened again by the people sharing them, and a referrer who typed the short form
should not silently lose the commission for it"*. The code was designed to be printed and the
capture was designed to survive being shortened by somebody else.

**Why building one would cost more than it returns.** A shortener is a new table, a new public
route, a redirect nobody can audit from a flier, and a second construction of a referral URL. The
last of those is the expensive part: this codebase's own worked example of drift is two readers
of one setting disagreeing about whether an integration is configured. `ReferralService::link()`
is the single minter, and the flier now calls it — the first pass of this feature assembled
`?ref=` by hand and was a second minter, agreeing today and disagreeing the first time somebody
renames the parameter. `tests/Unit/EventFlierTest.php` asserts against `link()` itself rather
than a literal, so a change to the minter must move the flier with it or fail.

**Where the address is *not* printed:** an event whose organiser turned sharing off. Sharing is
per-event — `ReferralService::enabledForEvent()` — and when it is off, `usable()` tells the buyer
the code is not in use and `credit()` declines the money. A flier still printing `?ref=` there
would promise a commission that will never arrive: the sharer does the work and finds out
nothing, the buyer is told the code is dead, and neither can see why. The flier falls back to the
plain event URL, same as the open state.

**Nothing still open here.**

### 11.3 · Does a free-tier registration count as confirmed — yes

You assumed yes and asked for it to be confirmed. It is built that way, and the reason is worth
recording: the mark means **ticketed**, not paid. Somebody with a free General seat holds a real
ticket and is really coming, which is the thing the flier asserts. A mark that meant "paid"
would make the free tier's holders look like people who had not quite registered.

A **pending** registration is not confirmed — a held seat is not a ticket, and a flier reading
"Ticket confirmed" over a payment that has not landed is a claim the door would refuse.

### 11.4 · The layout is authored, and here is what changed after looking at it

`I Will Be There Flier.dc.html` was not in the attachment, so there were no coordinates. Four
things were wrong in the first pass and only visible in a rendered image:

- **Fixed QR coordinates per format** collided with the metadata on all three, because the text
  above wraps to one line or two depending on the title and the name. The plate is opaque, so it
  read as a cropped date rather than a bug. The whole stack is measured and centred now.
- **The chips drew through the name** — `text()` takes a baseline and a chip box grows downward.
- **`story` without a photo** was 1120px of flat dark green above the type: the "dark layout with
  a hole" §9 forbids.
- **The photo met the plate at a hard seam**, because one gradient over the whole canvas ends at
  a different green than the scrim faded to.

And three things were added to make it read as designed rather than assembled, all of them this
platform's own idioms rather than invention: a **gold hairline** under the claim, because without
it nothing was separated from anything and the eye had no reason to stop at the headline; the
date and place as a **tracked uppercase micro-label**, so a date stops reading as a sentence; and
the QR's caption **stacked beside the code** with the host under it, which closed the empty half
of the composition with something useful.

One deviation from house style, stated because it is one: those micro-labels are tracked
uppercase **sans**, not the mono face the design system reserves for metadata. AGMono's period
and colon sit at the left of their cells, so "14:00" renders as "14: 00" and a domain as
"afg. afrovanguard. org. ng" — the face, not the tracking; it survived setting tracking to zero.
The kicker keeps the mono, because "AFRICA GATES" has no punctuation in it to open up.

### 11.5 · And the prerequisite that was not in the handoff

There was no QR encoder on this platform that could hold a URL. `Support\Qr` was version 1,
alphanumeric, sixteen characters — right for a ticket code, and a referral link is 56 bytes
containing `?` and `=`, which are not in that alphabet at all. Byte mode and versions 2–6 with
real interleaving had to be built before any of §4's QR requirements could be met at all. It is
verified by decoding rather than by matching another encoder; see the commit.

### 11.6 · What the first shipped version got wrong, found by using it

Six faults, five of which no test in this suite could have caught because they are about what
an image looks like or where a fixed element lands. They are recorded because each one names a
class of mistake, not because the list is interesting.

**The POST answered 406 on production.** A status this application returns from no route, for
any input — so a request filter in front of PHP, and cPanel ships mod_security with
`status:406` as its default deny. Two things in that request line are shapes a shared host
rejects: a POST body on a path a static handler claims by extension (`/flier.png`), and a
multipart image, which is what the multipart rules are for. There is no shell on that host, so
the filter cannot be read or relaxed. The address the browser posts to now has no extension,
and the photo has a **transport ladder**: multipart, then base64 in a urlencoded body, then no
photo at all with a sentence saying so. Only statuses this application never emits count as
"filtered" — 405, 406, 415, 501; **403 is deliberately excluded**, because `CsrfMiddleware`
answers 403 and retrying a rejected token in three transports is three failures and a
confusing message.

**The crop cut the face off.** §4 asks for a "face-centre crop" and the first build had no
face detection — the photo was cover-cropped around a fixed anchor, which centres the middle
of the frame, and almost nobody's face is in the middle of a photo they took of themselves.
`FaceFinder` finds it: skin **chrominance**, not RGB, because skin varies enormously in luma
and very little in chrominance, and an RGB threshold is a brightness rule wearing a colour
rule's clothes that fails on dark skin first. On this platform's audience that is not a
partial feature. It returns null rather than a guess whenever what it found is not head-shaped,
and null is the anchor that shipped before — so it can improve on the old behaviour or tie it,
never do worse.

Its first version put the point on the **chin**: skin is contiguous from forehead to
collarbone, so a face, a neck and a bare shoulder flood-fill into one region and 0.42 down
*that* box is not the eye line. The head's height is estimated from its **width** now, measured
in the top band of the region, which shoulders cannot inflate.

**The generator was in the rail.** 320px and `position:sticky` — so the drag-to-reframe canvas
was a thumbnail with a sticky ancestor, on the one screen whose entire job is showing somebody
their own face. It is a dialog over the page now: bottom sheet on a phone, centred from 721px,
the event still visible behind it. **Opening pushes a history entry**, so the back gesture
closes the sheet instead of leaving the page — the interaction people try first. Focus moves
in, is trapped, and is given back; the root's overflow is locked and restored.

Its z-index was 70, which is exactly the Gee assistant's, so DOM order decided and Gee won: its
launcher sat on top of a field inside a modal. 1100 now, chosen by enumerating every fixed and
sticky element's computed z-index in a browser rather than by adding ten to the last one anybody
remembered.

**The reframe step came before the render.** It therefore had to guess where the crop would
land, and it guessed the middle while the renderer used the face — a preview that disagrees
with the image it previews. The render comes first now and reframing is a correction to
something visible; the route reports the focal point it used in `X-Flier-Focus`, resolved by
the one function `png()` also calls.

**The printed address ran off the canvas.** It was drawn with `wrapMeasured()`, which splits on
whitespace and keeps an over-wide single token anyway — correct for a name, wrong for an
address, which has no spaces in it. Off-canvas text does not throw; it is simply not there, and
the flier read as an address ending mid-code. It became reachable the moment the printed line
grew a `?ref=` on it, which is the moment it became the thing that pays the sharer. It wraps
across two lines now, breaking after a separator, because the last characters are the referral
**code** and truncating them truncates somebody's commission. `width()` also learned about
tracking: `text()` draws a tracked line character by character, so measuring the untracked
string and drawing the tracked one is its own off-by-a-line-width.

**The open flier printed no name.** The name was drawn only in the confirmed branch, so the
ungated path — the common one, the whole reason the feature exists — carried none, while the
generator required one, capped it, cleaned it and signed it into a token. §1's own first line
is "their name, the event, and a QR"; §2's table is describing the line **above** the name, not
a replacement for it. One template, one boolean: the name is the constant, and the state decides
what sits over it. The invitation then needed its own gap constant, because 22px below a chip's
padded box reads as space and 22px between two 38px baselines does not.

### 11.7 · The flier takes the event's colour, and there are four of them

The layout was authored with eleven hard-coded hexes — a dark green plate, a warm paper, one
gold — so every flier for every event came out the same colour. On a platform where
`EventTierPalette` already moves the tier chips, the registration card's selection light and
the printed ticket to the organiser's accent, that meant an organiser could set their event
teal, watch three surfaces follow, and then find the graphic their attendees actually post to
WhatsApp was still green.

**`EventFlierTheme` computes the whole palette from the event's accent.** Nothing is stored and
nothing is picked: a style is a KEY and every hex is derived on every read, which is the same
reason a tier stores a slot rather than a colour. Change the accent and every flier already
shared keeps working while every new one moves.

It composes `EventTierPalette` rather than repeating it — its HSL conversion, its `ink()`
guarantee, its `contrast()`, its 152° `bold` rotation. Not one line of colour maths is written
twice, because two derivations of one accent is exactly how two surfaces come to disagree about
what the event's colour is.

**Four styles, and each is a treatment rather than a hue** — the hue already comes from the
event:

| key | ground | what it is for |
|---|---|---|
| `deep` | the accent taken down to a dark field | the default behind a photograph |
| `paper` | a warm light ground carrying a whisper of the hue | the default with no photo |
| `bold` | the accent itself, full chroma | a poster |
| `tint` | `deep`, with the photograph desaturated and re-coloured into the accent | photo formats only |

`tint` is offered only where there is a photo slot: an option that changes nothing when chosen
is worse than one that is absent, because the person presses it and concludes the flier is
broken. The picker draws each style as a **miniature of the composition** — ground, two type
lines, the accent bar — in this event's real colours, resolved server-side. A picker showing a
generic chip beside a flier that comes out teal is a control that lies about its own outcome.

**The format no longer decides whether the flier is light.** It was `$paper = $fmt === 'plain'`,
so there was no such thing as a dark no-photo flier or a light one with a photograph in it, and
neither of those is a bad idea. The style decides the ground; the format decides the shape.

**Every pair is measured, and sampling the accent space is what caught the faults.** The floors
are 4.5:1 for anything that carries words (WCAG 1.4.3 — the meta line is 26px in a 1080px image,
which is small on the phone a flier is actually looked at on) and 3:1 for the hairline rule and
the chip fill (1.4.11). `EventFlierThemeTest` walks the hue wheel at two saturations and three
lightnesses, plus the greys and the primaries, and asserts the floors rather than the values.
Three faults it found, none of which any example-based test would have:

- **`bold` collapsed to one colour** on a mid-lightness saturated hue — `#237b22`, `#1d6fa5`,
  `#ff0000`. Nothing but near-white clears 4.5:1 on that ground, so the name, the title and the
  date all came out white. The lightness is banded out of the middle now, and the band is chosen
  by MEASURING which ink is more legible rather than by comparing HSL lightness: pure `#0000ff`
  has an `$l` of exactly 0.50 and went into the light band, where its best available ink managed
  3.5:1.
- **A gold event's `paper` flier came out olive.** One accent value was doing duty as both a
  graphic and a line of text, and the text floor darkened it past recognition. There are two now
  — `accent` at 3:1 for the rule and the chip fill, `accent_text` at 4.5:1 for the kicker and
  the invitation. That split is this codebase's own `fill` vs `edge` distinction, inverted:
  here the graphics want the identity and the text wants whatever reads.
- **A navy event and a plum event got the same near-black `deep` ground** — 29 redmean units
  apart. The event's colour survived only in the highlight, so the ground of the graphic people
  actually post was effectively black for everybody. Eight points of lightness fixed it, and
  white ink is still above 13:1.

**Two pixels do not follow the event, and the theme declares them so a reader exists.** The QR
plate is white and its modules are black in every style: a symbol needs dark modules on a light
field, that is the specification rather than a preference, and a code that scans is worth more
than a code that matches. They are passed into the renderer rather than left as two literals
because a value the theme declares and nothing reads is the most expensive shape of bug here.

**And one bug found only by driving the page.** The swatch data was handed to Alpine as JSON
arguments inside `x-data="evFlier(…, {…}, {…})"`. The HTML parser destroyed it: the first `"`
inside the JSON closed the attribute and everything after it was re-read as a list of attribute
names, so the element came out carrying `in=""`, `the=""`, `full=""` and Alpine never saw a
component at all. No console error, no failed request — a dialog that did nothing when pressed.
JSON goes in the script block now, the way this template already hands over the event title.
