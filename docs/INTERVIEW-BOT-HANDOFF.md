# Interview bot — handoff

For whoever picks this up next. Written 21 Aug 2026, against branch
`claude/clone-attendee-repo-7p9x53` at commit `7e88db4`.

Read this before touching the code. It says what is built, what is verified, what is
**not**, and — item 1 — one thing the existing documentation gets wrong.

---

## 0. What changed on 22 August 2026

A later session picked this up. `attendee-labs/attendee` **is** reachable for reads —
`add_repo` refuses cross-tier *pushes*, not anonymous clones — and a fresh clone lands on
`77e990ed`, the same commit this file was written against. Several things filed below as
"only a live run can settle" were settled from that source instead.

| Item | State |
|---|---|
| 1 — the false real-time claim | **Done.** Corrected in `InterviewBot`'s docblock and `docs/INTERVIEW-BOT.md`; the third place is commit history, so `CODEBASE-INDEX.md` §14 stands in for it. A fourth site this file missed — `InterviewLive`'s "STILL OPEN — the AI has no voice in the room" — is corrected too. Claim re-verified at source before writing. |
| 2 — transcript cursor | **Fixed, and it was not hypothetical.** `/transcript` does not paginate; it reorders. Details below. |
| 2 — response shapes | **Verified, not guessed.** `url` is flat (`RecordingSerializer` emits `["url","start_timestamp_ms"]`); `speaker_name` and `transcription.transcript` are correct. |
| 4/5 — guard corpus, trajectory tracing | Untouched. Both need a real judging round. |
| 6a — consent withdrawn mid-call | **Done.** Pause/resume wired, enforced from `poll()`. |
| 6b — `admit_from_waiting_room` | **Will not be built.** It is Zoom-only; these interviews are on Google Meet. See below. |
| 6c — privacy erasure | **Done.** Guard log scrubbed in place, Attendee `/delete_data` called. |
| 6e — recordings expire | **Half done, and the framing here was wrong by two orders of magnitude.** See below. |

**The cursor.** This file filed it conditionally — "if `/transcript` ever paginates or
reorders". It does not paginate. It reorders, by design: `TranscriptView` builds its list
as `filter(transcription__isnull=False).order_by("timestamp_ms")`, ordered by when the
words were spoken and filtered to those already transcribed. Transcription is
asynchronous, so an utterance whose text lands late inserts at its own offset, mid-list,
shifting every ordinal after it. That skipped a line permanently *and* — worse — made the
line id `att-7` name a different utterance than the `att-7` already in the buffer, so
`append()` compared two unrelated sentences and kept both. Identity now comes from
`timestamp_ms` + speaker uuid; `bot_cursor` is that offset, read back with five minutes of
slack because a late insert lands behind it. Parsing is split into
`AttendeeBot::parseTranscript()` so a fixture can drive it, and the tests were
mutation-checked.

**6b is a trap, not a task.** `/admit_from_waiting_room` returns 400 for any meeting type
other than Zoom (`bots_api_views.py`). Wiring it here would produce a button that always
fails. A test asserts the method does not exist, so nobody re-adds it from this file.

**6e was mis-scoped.** Filed here as a 180-day GCS lifecycle problem. The real one:
`/recording` mints a **presigned URL with `ExpiresIn=1800`** — thirty minutes, per
`Recording.url` in the provider's `bots/models.py`. `collectRecording()` stored it once, at
the end of the call, and a panel opens the sitting hours later, so every "The recording"
link on the admin page was *always* dead. A sitting now records that a recording exists and
`GET /admin/interviews/{id}/bot/recording` mints a link per click. The 180-day half is
still open and still wants R2 if recordings must outlive the bucket.

**Also fixed, and not in this file:** `normaliseState()` ended in `default => 'joining'`
while `sweep()` polls exactly `['requested','joining','in_call']`, so four real provider
states — `joined_recording_permission_denied`, `leaving`, `data_deleted`, and the breakout
-room pair — became bots polled for the rest of the sitting. A refused recording permission
read as "still joining" forever.

**One thing this file does not mention that matters if item 1 is ever acted on:**
`VOICE_AGENT_URL_PREFIX_ALLOWLIST` allows **every** URL when unset
(`url_is_allowed_for_voice_agent()` returns true on an empty env var), so a self-hosted
instance will load any URL a valid API key names into an Attendee-managed container.

---

## 1. Orientation

**What was built.** Africa GATES can now put a bot into a judging interview on Google Meet.
It records, transcribes with a recogniser primed on the nominee's name, and — only when a
sitting says so — speaks. It closes the gap `InterviewLive` has documented since it was
written: *"the AI has no voice in the room."*

**Repos on this machine**

| Path | Branch | Access |
|---|---|---|
| `/home/user/Africa-GATES` | `claude/clone-attendee-repo-7p9x53` | push ✅ |
| `/home/user/appadmin-oss/afrovanguard-site` | `claude/codebase-ai-audit-yy6ua4` | **read-only** — needs `add_repo` with `access:"push"` |
| `/home/user/attendee` | `main` @ `77e990ed` | upstream reference, unmodified |

Note `origin/master` on Africa-GATES is only the initial commit. All real work lives on
`claude/*` branches, so diffing against `master` gives you 16MB of unrelated history.

**Files added** (all on the branch, all pushed)

```
src/Services/AttendeeBot.php              transport — HTTP to the Attendee API, no policy
src/Services/InterviewVoice.php           the microphone: 2 TTS engines, clip cache, turn claim
src/Services/InterviewGuard.php           what the bot may say — deterministic, logged
src/Services/InterviewBot.php             the sitting: dispatch, sweep, ingest, turn loop
src/Controllers/InterviewBotController.php   Attendee's callback (optional)
database/migrations/2026_09_27_interview_bot.php        bot columns
database/migrations/2026_09_28_interview_guard_log.php  refusal log
database/migrations/2026_09_29_interview_speak_lock.php atomic turn claim
tests/Unit/InterviewBotTest.php           44 tests
tests/Unit/InterviewGuardTest.php         63 tests
docs/INTERVIEW-BOT.md                     the feature
docs/ATTENDEE-ON-GOOGLE-CLOUD.md          the deployment runbook
```

Modified: `InterviewService::detail()`, `InterviewLive::maybeFollowUp()`,
`Admin/Controllers/InterviewsController` (4 actions), `routes.php`, `CsrfMiddleware`,
`Support/Maintenance`, `templates/admin/interviews/show.twig`, `.env.example`.

**Run it**

```bash
cd /home/user/Africa-GATES
composer install
vendor/bin/phpunit                                    # 3560 tests, all green
vendor/bin/phpunit --filter "InterviewBotTest|InterviewGuardTest"
```

**Verified:** every test passes; no test touches the network; PHP lints clean; all
Attendee endpoints and request fields checked against the upstream source at `77e990ed`.

**Not verified:** *nothing has ever run against a live Attendee instance or a real Meet
call.* See item 2.

---

## 2. Open items, in priority order

### P0 — Item 1: the docs say real-time conversation is impossible. It isn't.

**This is the one to read first, because it contradicts what is currently written down.**

`InterviewBot`'s class docblock, `docs/INTERVIEW-BOT.md` §"What `auto` honestly is on this
host", and the commit history all say:

> *A conversational voice agent holds a duplex audio stream and answers in a few hundred
> milliseconds. That needs the process to live next to the media, which is precisely what
> this platform does not have.*

**That was wrong.** Attendee supports two real-time paths, both confirmed in its source and
docs at `77e990ed`:

1. **`voice_agent_settings.url`** (`docs/voice_agents.md`) — you supply a URL to a webpage
   running a voice agent. Attendee loads it **in an Attendee-managed container**, streams
   its audio into the meeting, and feeds meeting audio in as the page's microphone. Its own
   docs say explicitly: *"No backend worker required."* A static page running OpenAI's
   Realtime API in the browser would work.
2. **`websocket_settings.audio`** (`docs/realtime_audio.md`) — bidirectional PCM over a
   websocket at 8/16/24 kHz. This one *does* need a backend worker, so it is the wrong path
   for this deployment.

Path 1 means genuine sub-second conversational interviewing is achievable **without** the
cPanel host being in the audio path at all. The turn-based design in `InterviewBot::turn()`
is not a hard constraint; it is one option.

**But do not just switch it on.** Taking path 1 bypasses `InterviewGuard` completely — the
guard sits in the PHP path between `AiGateway` and TTS, and a realtime agent speaks
directly from the browser. You would trade every grounding, verdict, promise and
protected-characteristic check for latency, in the feature that decides an award.

**What to do:**
- Correct the three places that state the false claim. Do this even if you build nothing.
- If real-time is wanted, the design question is *how the guard survives it* — most likely
  by reimplementing the checks inside the agent page and having it POST every utterance
  back to `gates_interview_guard_log`. Treat "we lose the guard" as a blocker, not a note.
- Keep the turn-based path. It is the right default for final panels regardless.

---

### P1 — Item 2: nothing has run against a live instance

No Attendee instance exists. Every line of this integration is unexercised against the real
API. The tests deliberately do not mock cURL (a mocked transport asserts the mock was
written, not that the integration works), so the first real proof is a smoke test.

**Do:** follow `docs/ATTENDEE-ON-GOOGLE-CLOUD.md` end to end, then its §8 smoke test.

**Highest-risk things that only a live run can settle**, in order:

| Risk | Why it might break | Where |
|---|---|---|
| Transcript cursor | `bot_cursor` is an **ordinal position** in the response array, not a provider ID. If `/transcript` ever paginates or reorders, the cursor silently skips or repeats lines. | `AttendeeBot::transcript()` |
| Response shapes | Field names (`transcription.transcript`, `speaker_name`, `state`, `transcription_state`) read from source at one commit. A different instance version may differ. | `AttendeeBot` throughout |
| `join_at` clamp | Attendee rejects a past `join_at`. The clamp adds 30s; clock skew between the two hosts could still land in the past. | `InterviewBot::joinAt()` |
| Recording URL | `/recording` response shape is guessed as `url` or `recording.url`. | `AttendeeBot::recordingUrl()` |

If the cursor turns out to be fragile, switch to the provider's own utterance ID if one is
exposed, and keep the ordinal only as a fallback.

---

### P1 — Item 3: Afrovanguard is code-complete and undeployed

`afrovanguard-site` already has a full Attendee provider — `lib/AttendeeBot.php`,
`Meetings::inviteBot()/removeBot()/dispatchDueBots()/pollBotTranscripts()`, leadership
controls, `docs/meetings.md`. It needs **no code**. What it needs:

```dotenv
AV_MEET_BOT_PROVIDER=attendee
AV_ATTENDEE_API_KEY=<the Afrovanguard project key>
AV_ATTENDEE_BASE_URL=https://meetbot.your-domain.org
```

Left blank, `AV_ATTENDEE_BASE_URL` points at `app.attendee.dev`, which bills per
meeting-hour. One Attendee instance serves both platforms — separate projects, separate API
keys, separate transcript stores.

**Blocked on:** push access. Call `add_repo` with `access:"push"` for
`appadmin-oss/afrovanguard-site`. Note an attached clone lives at a **different path**
(`/home/user/afrovanguard-site`), so clone fresh rather than reusing the read-only checkout.

---

### P2 — Item 4: the guard corpus is a starting point, not a finished spec

`tests/Unit/InterviewGuardTest.php` holds 14 questions that must pass and 18 that must not.
**That corpus is the specification** — the patterns in `InterviewGuard` are an
implementation of it.

The first version of these rules used bare topic words and blocked **8 of 10** legitimate
questions (`medical`, `church`, `disability`, `pregnan`, `transfer`, `weak`, `concerning`,
`outstanding`). The current rules match *framing* instead: a question about the nominee's
**work** passes, a question about their **person** does not.

**Rules for changing it:**
- Add every real-world false positive to `legitimateQuestions()` **before** relaxing a rule.
- Never widen a pattern without checking `forbiddenQuestions()` still passes.
- After the first real judging round, run `InterviewGuard::tally()` and read the refused
  sentences. A high `ungrounded` count usually means the **recogniser** is mangling a name,
  not that the model is confabulating — check the transcript before touching the guard.

**Known limit, by design:** this cannot catch a question that is grounded, polite, on-topic
and simply *bad* — leading, condescending, testing nothing. No pattern can. That is the
argument for `assisted` mode, not a bug to fix.

---

### P2 — Item 5: evaluation layer 2 is missing

Of the three standard layers, layer 1 exists (the corpus is a golden set) and layer 3 is
trivial (`InterviewGuard::tally()` on a schedule). **Layer 2 — trajectory tracing** — does
not: there is no way to review the *sequence* of a sitting (which questions, in what order,
what was refused, where the nominee was cut off) as a whole.

A correct answer reached by a broken path breaks tomorrow. Build this after the first real
round, from real sittings — 50 well-chosen cases beat 500 synthetic ones.

---

### P3 — Item 6: smaller things, each self-contained

| # | Item | Where |
|---|---|---|
| 6a | **Consent withdrawn mid-call has no path.** Attendee exposes `/pause_recording` and `/resume_recording`; nothing uses them. Today the only option is removing the bot entirely. | `AttendeeBot` |
| 6b | **A bot stuck in `joining` needs a human to admit it.** Attendee exposes `/admit_from_waiting_room`. Wiring it would remove the most likely live failure. | `AttendeeBot`, console |
| 6c | **Privacy erasure does not reach the bot data.** `PrivacyEraseUserCommand` does not clear `gates_interview_guard_log` rows or call Attendee's `/delete_data`. The guard log auto-prunes at 180 days, but erasure-on-request should be immediate. | `Console/Commands/PrivacyEraseUserCommand.php` |
| 6d | **Recogniser prime is thin.** Only nominee name, organisation, category — capped at 900 chars. Adding panellists' names and the programme's recurring jargon is the single biggest transcript-quality lever. | `InterviewBot::recogniserPrompt()` |
| 6e | **Recordings expire.** `bot_recording_url` is a link, not a copy, and the GCS lifecycle rule deletes at 180 days. If they must outlive that, push to R2 — `R2Service` already exists. | `InterviewBot::collectRecording()` |
| 6f | **Cloud Run Jobs launcher.** ~150 lines in `bots/launch_bot_utils.py` upstream. This is the scale-up path, *not* GKE — see runbook §11.2. Only worth it if interviews stop being seasonal. | fork of `attendee` |
| 6g | **Admin authz is role-level, not per-interview.** Consistent with every other admin route here, so not a new hole — but worth knowing if per-panel scoping is ever wanted. | `InterviewsController::blocked()` |

---

## 3. Landmines — things that will bite you

**Do not remove the atomic turn claim.** `InterviewVoice::claimTurn()` is one UPDATE that
does the claim, the minimum gap and the utterance cap together. `poll()` has two
uncoordinated callers (cron sweep + webhook); the earlier read-modify-write on `live_meta`
let both speak at once and lost the counter that bounds a stuck `auto` loop. The claim
window *is* the gap, which is why nothing needs releasing — a process that dies
mid-utterance self-heals instead of muting the sitting.

**Do not use `str_word_count()` anywhere near this feature.** It treats every accented
character as a word boundary, so French and Portuguese answers are miscounted — which
corrupts both the length cap and "has the nominee finished answering?". Use
`InterviewGuard::words()`.

**Consent gates three things, and it is one column.** `consent_at` decides whether a bot is
*sent*, whether anything it hears is *stored*, and whether it may *speak*. Do not add a
second consent column; it will eventually disagree with the first.

**The opening disclosure is a fixed string on purpose.** A disclosure that varies with a
sampler is not a disclosure. It is exempt from grounding and the question-mark rule, and
from nothing else. Its 60-word length is why `MAX_WORDS_SCRIPTED` exists.

**`interview_bot_enabled=0` means evacuate.** It silences everything *and* the sweep pulls
live bots out. It used to gate only dispatch, which meant a bot already in the room kept
recording — the exact situation you flip the switch in. Keep both halves.

**The webhook is optional and must stay optional.** Polling is the primary path because a
cPanel host cannot be relied on to receive callbacks. No callback is registered at all
without `ATTENDEE_WEBHOOK_SECRET` over HTTPS. Never make anything depend on it.

**`ATTENDEE_BASE_URL` blank = billed per meeting-hour.** The console warns; keep the warning.

**ElevenLabs shares the questionnaire's quota.** `VoiceService` spends the same key reading
prompts to nominees. A season of interviews can exhaust it, and nobody finds out until a
play button stops working.

---

## 4. Definition of done for this workstream

- [x] The false real-time claim corrected in all three places (item 1) — 22 Aug 2026
- [ ] Attendee deployed; runbook §8 smoke test passes; cursor behaviour confirmed live
      (**the cursor no longer needs a live run to be safe** — see the 22 Aug note in §2)
- [ ] Afrovanguard pointed at the same instance
- [ ] One full judging round run at `voice_mode=off`, transcript quality reviewed
- [ ] `InterviewGuard::tally()` read after that round; corpus extended from real refusals
- [ ] Only then: `assisted` on a real sitting

Recommended posture for the first season: **`off` everywhere.** The transcript quality is
the entire benefit and costs no governance argument; the voice is the part that invites an
appeal. `auto` belongs in first-round screening, never a final panel — and whichever mode
ran should go in the sitting's outcome note.
