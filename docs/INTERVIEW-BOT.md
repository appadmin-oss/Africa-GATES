# The interview recording bot

A bot that joins a judging interview on Google Meet, records it, transcribes it with a
recogniser primed on the nominee's own name, and — only when a sitting says so — speaks.

It closes the gap `InterviewLive` has documented since it was written:

> **STILL OPEN** — the AI has no voice in the room. Occupying a participant seat and
> putting audio into a Meet call needs Google's Meet Media API and a persistent media
> process; an extension has neither, and this host has neither.

The second half of that is still true. This host is PHP-FPM on cPanel and will never hold
a WebRTC session. What changed is that the media process no longer has to live here.

---

## How the pieces fit

```
Africa GATES (cPanel, PHP)                 Attendee (Google Cloud, Docker)
──────────────────────────                 ───────────────────────────────
InterviewBot   ── dispatch/poll ─────────▶  bot joins the Meet call
    │                                            │
    │          ◀───── transcript ────────────────┤  (OpenAI transcription)
    │          ◀───── webhook (optional) ────────┤
    │                                            │
InterviewVoice ── MP3 bytes ──────────────▶  bot speaks into the room
    │
    └─▶ InterviewLive buffer ─▶ transcript ─▶ judge ballot
```

| File | What it owns |
|---|---|
| `src/Services/AttendeeBot.php` | Transport. HTTP to the Attendee API. No policy. |
| `src/Services/InterviewVoice.php` | The microphone. ElevenLabs or OpenAI TTS, the clip cache, and the rules for using it. |
| `src/Services/InterviewGuard.php` | What the bot may say. Deterministic checks, every refusal logged. |
| `src/Services/InterviewBot.php` | The sitting. Dispatch, sweep, ingest, the turn loop. |
| `src/Controllers/InterviewBotController.php` | Attendee's callback (optional). |
| `database/migrations/2026_09_27_interview_bot.php` | Bot columns on `gates_interviews`. |

**Polling is the primary path.** Attendee will POST to a webhook, and that is faster, but a
cPanel host cannot be relied on to receive one. Everything is recoverable by asking, on the
cron tick that already runs. The webhook only makes `auto` mode quick.

---

## Deploying Attendee

**See [`ATTENDEE-ON-GOOGLE-CLOUD.md`](ATTENDEE-ON-GOOGLE-CLOUD.md)** — the full runbook, checked
against the Attendee source rather than its docs. It covers sizing, the VM, GCS for
recordings over the S3-compatible API, TLS, the production compose file, and the three
configuration traps that are not guessable (`LAUNCH_BOT_METHOD`, `POSTGRES_SSL_REQUIRE`,
`AWS_ENDPOINT_URL`). It also works through why Cloud Run *services* cannot hold a bot and
why Cloud Run *jobs* are the eventual upgrade path rather than GKE.

The short version: Attendee is Django + Postgres + Redis + Celery and launches a headless
browser per meeting, so it needs its own host — roughly 2 vCPU and 4 GB **per concurrent
bot**. None of that can run on this platform's cPanel hosting, which is the whole reason it
is a separate service.

### Inside Attendee

1. Create a project — one per consumer (Africa GATES, Afrovanguard). Copy its **API key**.
2. Add an **OpenAI credential** (project → Credentials). This is what transcribes.
3. Configure the webhook, if you want fast `auto` mode:
   - URL: `https://your-africa-gates-domain/api/v1/interview/bot/webhook`
   - Header: `X-Attendee-Secret: <the value of ATTENDEE_WEBHOOK_SECRET>`

You do **not** need Google Cloud TTS credentials. Attendee's own `/speech` endpoint supports
only Google TTS; this integration bypasses it and posts MP3 bytes to `/output_audio`
instead, so the voice is ours and no second vendor is involved.

### Africa GATES side

```dotenv
ATTENDEE_API_KEY=...
ATTENDEE_BASE_URL=https://meetbot.your-domain.org   # NOT the default
ATTENDEE_BOT_NAME=Africa GATES Interview Assistant
ATTENDEE_STT_MODEL=gpt-4o-transcribe
ATTENDEE_WEBHOOK_SECRET=              # openssl rand -hex 32
OPENAI_API_KEY=...                    # already used elsewhere

INTERVIEW_TTS_ENGINE=                 # elevenlabs | openai | blank to auto-detect
INTERVIEW_ELEVEN_VOICE_ID=            # blank reuses the questionnaire's voice
INTERVIEW_TTS_VOICE=alloy             # OpenAI only
```

Leaving `ATTENDEE_BASE_URL` blank points at `app.attendee.dev`, the vendor's hosted service,
which is **billed per meeting-hour**. Admin → Interviews prints which instance is answering,
because discovering it from an invoice is the expensive way to find out.

### Which voice

Two engines. `INTERVIEW_TTS_ENGINE` picks one; blank auto-detects, preferring **ElevenLabs**.

That order is not about price — ElevenLabs is generally *dearer* per character. It is about
accent. This voice asks forty nominees about their life's work, and OpenAI's catalogue is
eight American-English presets with no say in the matter; ElevenLabs has a library you can
choose from. For a continental African panel that is not a cosmetic decision.

**ElevenLabs shares the questionnaire's key and therefore its quota.** A season of
interviews can exhaust what nominees use to hear their own questions read aloud, and nobody
finds out until a play button stops working. Watch the dashboard during a round.

What actually bounds the bill is neither engine: it is the **clip cache** in
`var/cache/interview-voice`. The opening disclosure and every scripted pack question are
byte-identical across sittings, so they are synthesised once for the season and replayed
from disk. Only generated follow-ups are ever paid for twice. The key includes engine,
voice and model, so changing the voice in Settings does not keep serving the old one.

The model that decides *what* to ask is separate and stays with `AiGateway` — the brain and
the mouth are billed by different vendors on different units, and coupling them would mean
changing the voice required re-testing the questions.

---

## The three voice modes

Set per sitting, in Admin → Interviews → *(a sitting)* → **The recording bot**.

| Mode | What happens | What you are claiming |
|---|---|---|
| `off` | Records and transcribes. Never speaks. | A recorder was present. |
| `assisted` | A panellist presses **ask**; the bot reads the question aloud. | The model wrote the question; a person decided to ask it. |
| `auto` | The bot asks and follows up on its own. | **A model conducted this interview.** |

`off` is the default, and every sitting that existed before this feature has it — switching
the feature on cannot retroactively give a voice to something scheduled last week.

**`interview_voice_max`** in Settings caps all of them at once. It is a ceiling: it can only
lower what a sitting asked for, never raise it. Set it to `assisted` to allow the
human-approved path platform-wide while forbidding autonomous questioning everywhere.

### What `auto` honestly is on this host

Turn-based, not conversational. The nominee finishes; the transcript reaches us (a second or
two on the webhook, up to a cron interval otherwise); a model writes one question; OpenAI
synthesises it; Attendee plays it.

That is a few seconds per turn at best. It is a competent structured interviewer and it is
not a conversationalist — it will not interrupt, back-channel, or handle somebody talking
over it. A panellist should stay on the call.

If you need genuine real-time conversation, that needs a duplex audio agent sitting next to
the media (OpenAI's Realtime API against Attendee's WebSocket audio stream), which is a
different piece of work and does not run on this side of the wire at all.

---

## Keeping it grounded

Everything the bot is about to say — model-written **or typed by a panellist** — passes
`InterviewGuard` first. Seven rules, checked in this order:

| Rule | Refuses | Why it is first / where it sits |
|---|---|---|
| `injected` | "Ignore previous instructions…", "system prompt", "as an AI language model" | The transcript is untrusted text going into a prompt. `AiGateway` fences the input; this checks the **output**, because a fence that fails is invisible unless something downstream looks. |
| `off_limits` | **Your** religion, ethnicity, politics, health, marriage — and bank details, BVN, passwords | Before grounding, so it holds even if the nominee raised it first. Matched on *possessive framing*, not topic — see below. |
| `evaluative` | "Well done", "that is impressive", "outstanding work" | Praise from the machine conducting the interview reads as the panel's verdict, and shapes every remaining answer. |
| `promise` | "You will be shortlisted", "the judges will call you", "**I** guarantee" | A machine cannot commit the panel to a result, a timeline, or a callback. |
| `not_a_question` | Statements | The bot asks; it does not opine. The fixed opening is exempt. |
| `too_long` | Over 40 words (120 for the scripted opening) | A question, not a speech. |
| `ungrounded` | **Any number or proper noun that appears nowhere on the record** | The one that catches invention. See below. |

### Framing, not vocabulary — and why that matters here

The first version of these rules listed bare topic words: `medical`, `church`, `disability`,
`pregnan`, `transfer`, `weak`, `concerning`. Probed against ten questions a real panel would
ask, **it blocked eight of them**:

> "How many medical outreaches did the team run last year?"
> "What does the church hall cost you each month?"
> "How is the disability access funded at the centre?"
> "How did you transfer the training to the other nine schools?"

Every one is an ordinary question to a nominee in this platform's own categories. A guard
that refuses those is not cautious, it is broken — it gets switched off, and then it
protects nobody.

The distinction it was missing: those questions are about the nominee's **work**. What a
panel may not weigh is the nominee's **person** — their faith, their health, their marriage,
who they vote for. Those are separated by possessive and second-person framing, so that is
what the patterns match. `Which ethnic group do you belong to?` is refused; `Which
communities do the pupils come from?` is not, because who a programme serves is how impact
is evidenced.

The corpus in `tests/Unit/InterviewGuardTest.php` is the specification — 14 questions that
must pass, 18 that must not. **Extend it when a refusal turns out to be wrong**, and change
the patterns only with the corpus green.

Content is checked **before** shape deliberately. "Congratulations, you have won." is both
a promise and not a question; recording it as a formatting complaint would hide, in the
tally a panel reads, that the model was promising a nominee an award.

### The grounding rule

This is the rule that matters and the only one that is not a word list.

Every specific in a candidate question — every number, every capitalised name — must
already appear in the **grounding corpus**: what the nominee has actually said, plus their
own dossier, plus the question pack.

So the bot can probe, challenge and ask for evidence, but it **cannot introduce a fact**.
"How was the 400 counted?" passes when they said 400. "How did the UNICEF grant help?" is
refused when nobody said UNICEF — which is exactly the failure that forces a nominee to
either correct a machine in front of a panel or accept a false premise, with the transcript
recording whichever they chose.

Numbers are normalised, so `4,000` matches `4000`. Sentence-initial capitals and common
words are skipped. With an empty corpus the rule **fails open** — before the first answer
there is nothing to be grounded in, and refusing the opening question of every interview
would make the feature useless. The other six rules still apply.

### Why not an LLM judge

The obvious design is a second model call scoring each candidate. Rejected, in order:

- **It is the same failure mode.** A model asked to spot a hallucination can hallucinate
  the verdict. Two draws from one distribution is not independent verification.
- **It doubles the latency**, inside a pause a nominee is already sitting through.
- **It doubles the tokens**, on the tightest capability budget on the platform.
- **It cannot be tested.** A rule that rejects "well done" fails the same way every time
  and a test pins it. A judge model's refusal rate is a distribution.

These checks will not catch everything — they cannot tell a grounded, polite, on-topic
question from a *useless* one. That needs a person, which is what `assisted` mode is.

### Reading the record

Every refusal is a row in `gates_interview_guard_log`, with the sentence that was refused.
The last five show on the sitting page; `InterviewGuard::tally()` counts by reason. Rows are
pruned after **180 days** — the same window as the recordings — because a refused follow-up
is generated from what a nominee said, and nothing else on this platform derived from a
recorded interview is kept forever.

Keeping the text is the point. "ungrounded × 14" is not actionable; the actual sentence
tells you whether the pack is badly written, the recogniser is mangling a name, or the
model is genuinely confabulating — three different fixes. A few refusals per sitting is
the guard working. Many means something upstream needs attention.

---

## Consent

`consent_at` gates three things, and it is one column, not three checkboxes:

1. **Whether a bot is sent at all.** Stricter than the capture rule. A nominee who consents
   late gets a bot on the next sweep rather than at the start — a worse experience, and the
   right trade: a recording process in the room before anybody agreed to it must not happen,
   and "it was not recording yet" is not a distinction a nominee can verify.
2. **Whether anything it hears is stored.** `InterviewLive::mayCapture()`, unchanged.
3. **Whether it may speak.** A bot that talks to somebody who has not agreed to be recorded
   is participating in a conversation it is not allowed to remember.

The bot's opening line is a **fixed string**, not model-written, and it says it is an AI and
that the sitting is recorded. A disclosure that varies with a sampler is not a disclosure. It
is printed on the console so an operator can read it before the room hears it.

Every voice-mode change is audited with its **old and new value**. "Who turned autonomous
questioning on for the sitting that decided this award, and when" is a question a panel may
have to answer to a nominee who appeals.

---

## Operating it

**The sweep** runs on every maintenance tick (`interviewbot` in the cron log). It sends bots
to sittings starting within 12 minutes, polls in-flight ones, ingests transcript, runs the
turn loop, and pulls bots out 25 minutes past the end of their slot.

**Kill switches**

| Where | Effect |
|---|---|
| `interview_bot_enabled=0` (Settings) | No bot is sent, **any bot already in a call is withdrawn on the next sweep, and nothing can speak in the meantime.** |
| `interview_voice_max=off` (Settings) | Bots record but nothing speaks. |
| Blank `ATTENDEE_API_KEY` | The feature does not exist; interviews fall back to the extension. |
| Blank `OPENAI_API_KEY` **and** `ELEVENLABS_API_KEY` | No voice at all; every sitting reads as `off`. |
| Blank `OPENAI_API_KEY` alone | Attendee falls back to Meet's free captions for transcription. |

**When something goes wrong**

| Symptom | Likely cause |
|---|---|
| "It could not join" | Meeting link wrong, or the host has to admit the bot manually. |
| Bot sits in `joining` | Waiting to be admitted. Let it in from Meet. |
| No transcript after the call | Check `transcription_state` on the Attendee side; the sweep re-polls. |
| Bot joined but never spoke | Either `voice_effective` is `off` (check the platform cap and the TTS keys), or the guard is refusing everything — the sitting page lists what it stopped. |
| Guard refuses almost every question | Usually the recogniser mangling a name, so the model repeats a garbled version that matches nothing on the record. Check the transcript, and widen the recogniser prime. |
| Names mangled in the transcript | The recogniser prime only covers the nominee, their organisation and category. Judges' names are not in it. |

An unreachable Attendee instance is **not** treated as a failed bot. A timed-out poll writes
nothing, so one bad network moment during a sweep cannot end a running interview.

---

## Making the most of it

Ordered by value for effort.

**1. Run `off` for a whole round first.** The transcript quality is the entire benefit; the
voice is the risky part. A season of recorded, well-transcribed interviews is worth more to a
panel than a talking bot, and it costs no governance argument.

**2. Use `assisted` as the default once you do turn voice on.** It gets you the tiring part —
the bot reads long questions consistently, in the same tone, at the same pace, for the
fortieth nominee as for the first — without anybody being able to say a machine ran the
interview. Panel fatigue is real and it is not evenly distributed across a day of sittings.

**3. Reserve `auto` for first-round screening, not finals.** Where a sitting narrows fifty
nominees to fifteen, a consistent machine interviewer is arguably *fairer* than three tired
humans with different styles. Where it decides an award, the appeal risk is not worth it.
Record in the outcome note which mode ran.

**4. Prime the recogniser harder.** The single biggest quality lever. It currently sends the
nominee's name, organisation and category. Adding the panellists' names and the programme's
recurring jargon would measurably cut mistranscription — `AttendeeBot::recogniserPrompt()`,
capped at 900 characters.

**5. Keep the recording link's expiry in mind.** `bot_recording_url` is a link, not a copy,
and it expires. A judge who needs to hear how a name was actually pronounced should do it
during the judging window. If recordings need to outlive that, push them to R2 — the
platform already has `R2Service`.

**6. Read the guard log after the first round.** `InterviewGuard::tally()` tells you the
hallucination rate as a number rather than a feeling, and the refused sentences tell you
which of three things to fix. It is the cheapest evidence you will have that the AI is
behaving, and the thing to show a panel that asks.

**7. Watch the first three sittings live.** Cheap insurance. Bot admission, caption quality
and the turn pacing are all things you find out about in ninety seconds of watching and
otherwise find out about from a bad transcript a week later.

---

## What was deliberately not built

- **A fork of Attendee.** The voice goes through `/output_audio` with MP3 bytes, so OpenAI
  TTS needs no upstream change and you can pull their updates.
- **Signature verification on the webhook.** Attendee's signing scheme has moved between
  versions; verifying one this codebase cannot be sure of would read as authentication while
  failing open. A shared secret compared in constant time is honest about what it is.
- **Trusting the webhook body.** It names a bot, which is used to look up a sitting and for
  nothing else. All state is re-fetched from Attendee over an authenticated connection.
- **A second consent column.** There is one, and a second would eventually disagree with it.
- **An LLM judging the LLM.** See *Why not an LLM judge* above: a model asked to spot a
  hallucination can hallucinate the verdict, and it would double the latency and the tokens
  to buy a check that cannot be tested.
- **Anything that writes a score.** Unchanged: `InterviewReview` produces no numbers, and
  neither does this.
