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
| `src/Services/InterviewVoice.php` | The microphone. OpenAI TTS, and the rules for using it. |
| `src/Services/InterviewBot.php` | The sitting. Dispatch, sweep, ingest, the turn loop. |
| `src/Controllers/InterviewBotController.php` | Attendee's callback (optional). |
| `database/migrations/2026_09_27_interview_bot.php` | Bot columns on `gates_interviews`. |

**Polling is the primary path.** Attendee will POST to a webhook, and that is faster, but a
cPanel host cannot be relied on to receive one. Everything is recoverable by asking, on the
cron tick that already runs. The webhook only makes `auto` mode quick.

---

## Deploying Attendee on Google Cloud

> **Full runnable guide: [ATTENDEE-GOOGLE-CLOUD.md](ATTENDEE-GOOGLE-CLOUD.md).** What
> follows is the summary and the reasoning; that document has the compose file, the
> environment, the Cloud SQL and Cloud Storage wiring, and the troubleshooting table,
> all verified against Attendee v1.64.1.

Attendee is Django + Postgres + Redis + Celery, and it launches a **headless browser per
meeting**. That last part drives the sizing: bots are CPU- and memory-hungry and they are
not fractional.

### Sizing

Budget roughly **2 vCPU and 4 GB per concurrent bot**, plus about 2 vCPU / 4 GB for the web,
worker and Redis processes.

| Concurrent interviews | Machine type | Rough monthly (us-central1, on-demand) |
|---|---|---|
| 1–2 | `e2-standard-4` (4 vCPU, 16 GB) | ~$100 |
| 3–4 | `e2-standard-8` (8 vCPU, 32 GB) | ~$200 |

Two ways to cut that materially:

- **Spot VMs** are 60–90% cheaper. A bot evicted mid-interview loses that recording, so
  this is right for a pilot and wrong for finals week.
- **Stop the VM between judging rounds.** Interviews are seasonal. A box that runs three
  weeks a year should not be billed for twelve months — `gcloud compute instances stop`
  costs nothing but the disk.

Check current prices before committing; the figures above are order-of-magnitude.

### The shape to deploy

A **single Compute Engine VM running Docker Compose** is the right answer here, and it is
worth saying why not the alternatives:

- **Cloud Run** cannot host this. Bots are long-lived stateful processes holding a media
  session, not request-scoped containers.
- **GKE** is what Attendee's own production manifests target and it scales bots as pods
  properly. It is also a cluster to operate. Below about five concurrent interviews it is
  strictly more work for no benefit.

So: one VM, Docker Compose, and revisit if you outgrow it.

### Steps

```bash
# 1. A VM with a static IP, in the region nearest your panellists.
gcloud compute instances create attendee \
  --machine-type=e2-standard-4 \
  --boot-disk-size=100GB \
  --image-family=ubuntu-2404-lts --image-project=ubuntu-os-cloud \
  --zone=europe-west1-b

gcloud compute addresses create attendee-ip --region=europe-west1

# 2. HTTPS in, nothing else. Attendee's API must not be open to the internet
#    on any other port, and Postgres/Redis must not be reachable at all.
#    Port 80 is included because Caddy's default certificate flow uses the
#    ACME HTTP-01 challenge; drop it only if you switch Caddy to TLS-ALPN.
gcloud compute firewall-rules create attendee-web \
  --allow=tcp:80,tcp:443 --target-tags=attendee
```

Then on the VM: install Docker, clone `github.com/attendee-labs/attendee`, and put Caddy in
front for TLS. Note that **upstream ships no production compose file** — only
`dev.docker-compose.yaml`, which binds the source as a volume and runs Django's dev server.
[ATTENDEE-GOOGLE-CLOUD.md](ATTENDEE-GOOGLE-CLOUD.md) supplies the production one. Point a
DNS record at the static IP — you need a real certificate, because this platform refuses to
register a webhook over plain HTTP.

Two other things that guide covers and this summary does not: there is **no published
Docker image** (upstream CI builds with `push: false`, so you build it yourself), and the
image is **amd64-only** — `zoom-meeting-sdk` is an x86 wheel, so an Arm machine type will
not run it.

### Inside Attendee

1. Create a project; copy its **API key**.
2. Add an **OpenAI credential** (project → Credentials). This is what transcribes.
3. Configure the webhook, if you want fast `auto` mode:
   - URL: `https://your-africa-gates-domain/api/v1/interview/bot/webhook`
   - Header: `X-Attendee-Secret: <the value of ATTENDEE_WEBHOOK_SECRET>`

You do **not** need Google Cloud TTS credentials. Attendee's own `/speech` endpoint supports
only Google TTS; this integration bypasses it and posts MP3 bytes to `/output_audio`
instead, so the voice is OpenAI's and no second vendor is involved.

### Africa GATES side

```dotenv
ATTENDEE_API_KEY=...
ATTENDEE_BASE_URL=https://meetbot.your-domain.org   # NOT the default
ATTENDEE_BOT_NAME=Africa GATES Interview Assistant
ATTENDEE_STT_MODEL=gpt-4o-transcribe
INTERVIEW_TTS_MODEL=gpt-4o-mini-tts
INTERVIEW_TTS_VOICE=alloy
ATTENDEE_WEBHOOK_SECRET=              # openssl rand -hex 32
OPENAI_API_KEY=...                    # already used elsewhere
```

Leaving `ATTENDEE_BASE_URL` blank points at `app.attendee.dev`, the vendor's hosted service,
which is **billed per meeting-hour**. Admin → Interviews prints which instance is answering,
because discovering it from an invoice is the expensive way to find out.

**Pick a voice.** `alloy` is a placeholder, not a recommendation. For a continental African
panel the accent is not a cosmetic choice — the same point the ElevenLabs configuration
already makes.

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
| `interview_bot_enabled=0` (Settings) | No bot is sent by any route. |
| `interview_voice_max=off` (Settings) | Bots record but nothing speaks. |
| Blank `ATTENDEE_API_KEY` | The feature does not exist; interviews fall back to the extension. |
| Blank `OPENAI_API_KEY` | No voice, and Attendee falls back to Meet's free captions. |

**When something goes wrong**

| Symptom | Likely cause |
|---|---|
| "It could not join" | Meeting link wrong, or the host has to admit the bot manually. |
| Bot sits in `joining` | Waiting to be admitted. Let it in from Meet. |
| No transcript after the call | Check `transcription_state` on the Attendee side; the sweep re-polls. |
| Bot joined but never spoke | `voice_effective` is `off` — check the platform cap and `OPENAI_API_KEY`. |
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

**6. Watch the first three sittings live.** Cheap insurance. Bot admission, caption quality
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
- **Anything that writes a score.** Unchanged: `InterviewReview` produces no numbers, and
  neither does this.
