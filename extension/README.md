# Africa GATES — Interview Assistant (Chrome extension)

Sits inside a Google Meet call during a judging interview. It shows the panel the question
pack with the criterion each question tests, and it reads Meet's **live captions** so the
transcript writes itself.

## What it can and cannot do

**It can** read the call and write to your screen: the current question, what it is testing,
the follow-ups, which parts of the rubric you have covered, and a live follow-up question
generated from what the nominee just said.

**It cannot speak in the call.** Putting audio into a Meet call means occupying a participant
seat through Google's Meet Media API, which needs a persistent media process — not something
this platform's hosting can run. The next question appears on your screen and *you* read it
aloud.

**It cannot turn captions on.** Google requires a person to press **CC**, every call. The
panel says so until captions actually start arriving.

## Why captions rather than Google's transcripts

Google's own transcription is a paid Workspace feature. Its live captions are free on every
account, including personal ones. So this is the difference between a transcript that writes
itself and somebody retyping a forty-minute conversation.

Both routes still work: the interview screen can fetch a Google transcript, or you can paste
one in. This is the third route and usually the easiest.

## Install (one laptop, two minutes)

1. `chrome://extensions` → turn on **Developer mode** (top right).
2. **Load unpacked** → choose this `extension/` folder.
3. Click the extension icon. Paste:
   - the **live key** for the interview, copied from the interview screen in the admin console
   - the **site address** (`https://afg.afrovanguard.org.ng`)
4. Join the Meet call and press **CC**.

One key per interview. Paste the next one before the next call.

There is no Chrome Web Store listing, deliberately: a store review cycle between you and a
fix is not worth it for an internal tool used by a handful of people. If you would rather
have one, or want it force-installed for a Workspace domain, the same folder is what gets
packed.

## When Google changes Meet

They will. Captions are read out of a page whose markup Google owns and renames without
notice, so the extension tries three strategies in order:

1. Caption containers by ARIA role and label — the most durable, because those are
   accessibility contracts rather than styling.
2. Known class and `jsname` selectors — fast, and they will eventually rot.
3. **You point at it.** Press *"Captions not found?"* in the panel, then click on the caption
   text. Where you clicked is remembered and used in every call from then on.

If all three find nothing, the panel says so in red rather than sitting there looking busy —
and the interview screen shows when captions last arrived, so a silent stop is visible rather
than being discovered afterwards by a panel with an empty transcript.

## Privacy and consent

- Nothing is captured unless the **nominee** has given permission to be recorded, by pressing
  the button on their own interview page. If they have not, the panel says so and the captions
  are discarded — not stored and held pending a decision.
- The captions become a transcript labelled `machine`, so judges know a computer wrote it
  down. The transcript reaches the judging panel and nowhere else.
- The **live key** is scoped to one interview. It can read that sitting's questions, add
  caption lines to it, and close it. It cannot see the nominee's contact details, any other
  interview, or anything else in the admin console. Replace it any time from the interview
  screen.
- The extension asks for two permissions and no more: `storage` (to keep the key and where
  you dragged the panel) and access to the Africa GATES site itself. It has no access to any
  other site, reads no other tab, and sends nothing anywhere else.

## Files

| File | What it is |
|---|---|
| `manifest.json` | MV3 manifest — permissions, and the Meet match pattern |
| `content.js` | The panel and the caption reader, running in the Meet tab |
| `worker.js` | Every network call, so requests are privileged extension requests and need no CORS |
| `panel.css` | The panel's styling, fully self-asserting because it lands in someone else's app |
| `popup.html` / `popup.js` | Where the live key and site address are set |
