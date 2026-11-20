# Voting extension: ready-to-post copy

Fill in the four placeholders before posting: **[AWARD]**, **[OLD CLOSE]**, **[NEW CLOSE]**
(date, time and timezone, e.g. "Friday 28 November, 11:59pm WAT"), and **[VOTE LINK]**.

Why this copy holds up: it gives the real reason (some nominees were told late), says the
change applies to everyone equally, and confirms that no vote, price or rule changed. It
claims nothing you can't back up. Don't add phrases like "due to popular demand". A claim
like that invites the "they extended it to sell votes" reading.

---

## 1. Short post (X / Instagram caption / WhatsApp status)

> **Voting for [AWARD] now closes [NEW CLOSE].**
>
> Some of our nominees received word of their nomination only in the last few days.
> Transcend into Love means nobody honoured this year should lose their chance because a
> message reached them late. So every nominee now gets the same full window.
>
> Every vote already cast stands. Prices and rules are unchanged. The new date applies to
> every nominee in every category of the award.
>
> Vote: [VOTE LINK]

## 2. Longer post (Facebook / LinkedIn / website notice)

> **A fuller window for every nominee: [AWARD] voting now closes [NEW CLOSE]**
>
> This year's theme is *Transcend into Love*. For us that has to mean more than a slogan
> on a stage. It means making room for everyone we set out to honour.
>
> Over the past few days we learned that a number of nominees received news of their
> nomination late. Some heard only this week, and some are hearing it today. They have had
> far less time than others to tell their communities. An award decided before some of its
> nominees could even take part would not honour them.
>
> So we have moved the voting close from [OLD CLOSE] to **[NEW CLOSE]**.
>
> To be clear about what has and hasn't changed:
>
> - **The new date applies to everyone.** Every nominee in every category of [AWARD] gets
>   the same extra time. No individual or category was singled out.
> - **Every vote already cast stands** exactly as recorded.
> - **Voting prices and rules are unchanged.** Nothing about how votes are counted or how
>   winners are decided has changed.
> - **One extension, with a fixed end.** [NEW CLOSE] is final.
>
> If you've voted, thank you. If you know a nominee who has only just heard, now is the
> time to stand with them.
>
> Vote: [VOTE LINK]
> Questions: reply here or write to us. We'll answer openly.

## 3. Bulk email (via Admin → Campaigns)

**Subject:** [AWARD] voting now closes [NEW CLOSE]
**Preview text:** Some nominees heard late, so everyone gets the same fair window.

> **Heading:** A fuller window for every nominee
>
> Hello,
>
> Voting for **[AWARD]** now closes on **[NEW CLOSE]** (previously [OLD CLOSE]).
>
> This year's theme is *Transcend into Love*, and we take it to mean that nobody we set out
> to honour should be left out because news reached them late. Several nominees learned of
> their nomination only in the last few days, so they have had much less time than others
> to share it with the people who know their work. We've extended voting so every nominee
> has a fair chance to take part.
>
> **What this means for you**
>
> - The new close date applies to every nominee in every category, equally.
> - Any vote you have already cast stands exactly as recorded.
> - Prices and rules are unchanged, and so is how winners are decided.
> - This is a single extension. [NEW CLOSE] is final.
>
> **[Button: Vote now] → [VOTE LINK]**
>
> If you're a nominee and only just heard, welcome. Your page is live, and the full window
> is yours.
>
> If you have any question about the extension, simply reply to this email. A person reads
> every reply.
>
> With gratitude,
> The Africa GATES team

**Plain-text footer line (optional):** You're receiving this because you voted in, were
nominated for, or subscribed to updates about [AWARD].

## 4. Answers for the inevitable comments

- **"So it's rigged / you just want more money."** "The date moved for every nominee at
  once, because some only heard about their nomination this week. Every vote already cast
  stands, and prices and rules haven't changed. Fair to all is the whole point."
- **"My nominee was leading. This is unfair."** "Their votes are all still there. The
  extension gives the same extra days to every nominee, including yours."
- **"Will you extend again?"** "No. [NEW CLOSE] is final."

Only say the last line if it is true. Don't extend a second time after publishing it.

---

## 5. Website pop-up card (Admin → Settings → Public site)

Turn on **"Also show it as a pop-up card"** and fill in:

- **Announcement banner text** (the slim row that stays after the card is closed):
  `[AWARD] voting now closes [NEW CLOSE]. Every nominee gets the same full window.`
- **Announcement link:** `/vote` · **Link label:** `Vote now`
- **Pop-up title:** `A fuller window for every nominee`
- **Pop-up text:**

  ```
  Some nominees only just heard about their nomination. In the spirit of Transcend into Love, every nominee now has until [NEW CLOSE].

  Every vote already cast stands. Prices and rules are unchanged.
  ```
- **Pop-up image or GIF:** upload the GIF to Cloudinary (or the media library) and paste
  its `https://` address.
- **Describe the image:** `Animated illustration: a heart-shaped ribbon of light opens a gate as more people step through to join the celebration.`
- **Stop showing it at:** set this to [NEW CLOSE], so the card comes down when voting ends.

## 6. Prompt for the GIF

Keep it friendly and warm. It should look like a celebration, not an apology. Use one short
loop (2–4 s), with no text in the image. Text inside a GIF can't be read by screen readers
or translated, and it goes out of date. The title and button carry the words.

**Image / video generator prompt (Midjourney, DALL·E, Ideogram, Firefly, Sora, Runway, Kling):**

> Warm, friendly flat-vector illustration in a modern African editorial style, 4:3. An
> ornate golden gate stands slightly open, and a glowing ribbon of light curves out of it
> into the shape of a heart. Diverse African people (a young woman in a gele, an elder in
> agbada, a student, a man with a camera) arrive from the edges of the frame, smiling,
> some just stepping in, as the gate swings a little wider to welcome them. Small
> confetti, soft sparkles, a few floating hearts. Palette: deep emerald green, warm gold,
> cream, soft coral accents. Clean shapes, gentle gradients, plenty of breathing room in
> the upper third. Joyful, inclusive, celebratory, premium. No text, no letters, no logos,
> no numbers.

**Animation instruction (for an image-to-video tool, or for a designer):**

> Seamless 3-second loop: the gate eases open about 10%, the light ribbon draws itself into
> a heart and gently pulses, two new figures walk in from the edges, and confetti drifts
> down. Subtle, slow, calm motion, no flashing. The first and last frames match.

**Export:** 800×600 px, under ~1.5 MB (most visitors are on mobile data), at most 15 fps,
loops forever. Upload an MP4 or WebP version too, if your host converts. If the file is
heavy, a still PNG of the first frame works almost as well.
