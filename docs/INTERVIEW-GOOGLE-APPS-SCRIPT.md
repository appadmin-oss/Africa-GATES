# The interview Apps Script — credential and setup

What makes "Create a Meet link" and "Fetch from Google" work on
`/admin/interviews/{id}`. About **twenty minutes**, all of it in a browser — there is no
shell step, which is the point.

The code is `config/AfricaGATES_AppScript.gs`; the manifest is `config/appsscript.json`.
The PHP half is `src/Services/GoogleMeetService.php`.

---

## 0. What the credential actually is

There isn't a Google credential on this server, and that is deliberate.

Creating a Meet link means the Calendar API with `conferenceDataVersion=1`, or Meet's
`spaces.create`. Both need OAuth **as a user** — a service account cannot own a Meet space
without domain-wide delegation, which is somebody's IT decision and not something a
nomination platform can assume. That leaves a refresh-token dance: consent screen, token
store, rotation policy, client library. On cPanel with no shell and no daemon, every one of
those breaks quietly six months later.

So the Apps Script **is** the credential. It is deployed by the operator with *execute as:
me*, and inside it `Calendar.Events.insert` and the Meet API run as that operator's own
Google account. This platform holds no Google token and no Google identity — only a shared
secret that says "the caller is Africa GATES".

| In `.env` | What it is |
|---|---|
| `GAS_URL` | The web app's `/exec` URL. Also used by `GoogleSheetsService` for sheet writes. |
| `GAS_SECRET` | A long random string, identical to `SECRET` at the top of the `.gs`. |

`GAS_SECRET` is what stops anyone holding the `/exec` URL booking meetings in the
operator's calendar and reading the text of their conversations. The web app is deployed
"access: anyone" because sheet writes are unauthenticated, and for appending a row to a
private sheet that is a tolerable trade. For calendar and transcripts it is not, so **the
script refuses both actions outright while `SECRET` is empty** — an existing deployment
keeps writing rows and simply cannot do the new things until you set it.

---

## 1. Deploy

1. Open the Google Sheet the platform already writes to → **Extensions → Apps Script**.
2. Paste `config/AfricaGATES_AppScript.gs` over `Code.gs`.
3. **Project Settings → tick "Show `appsscript.json` manifest file in editor"**, then paste
   `config/appsscript.json` over the manifest. Do not skip this — §3 explains why the
   transcript fetch cannot work without it.
4. **Services → + → Calendar API → Add** (identifier `Calendar`, v3). `CalendarApp` alone
   cannot attach a conference; the script uses `Calendar.Events.insert`, and without the
   advanced service it reports "enable the service" rather than failing mysteriously.
5. Generate a secret and put the same value in both places:

   ```bash
   openssl rand -hex 32
   ```

   - top of the `.gs`: `const SECRET = '…';`
   - `.env`: `GAS_SECRET=…`

6. **Deploy → New deployment → Web app**. Execute as **Me**, access **Anyone**. Copy the
   `/exec` URL into `.env` as `GAS_URL`.
7. Run any function once from the editor to trigger the consent screen, and grant
   everything it asks for.

**Every later change to the script needs Deploy → Manage deployments → edit → New
version.** Saving is not deploying. A stale deployment is the single most common failure
here, and the PHP says so by name when the script returns HTML instead of JSON.

---

## 2. Check it

```
/admin/interviews/{id}  →  "Create the Meet link"
```

The admin screen never hides a failure: `GoogleMeetService::why()` puts the reason next to
the "paste a link" box, because a button that silently does nothing is how half-features
survive for months here. Nothing in this document is *required* — a Meet link can be
pasted, and so can a transcript. This makes the common path one press instead of six.

`GET` the `/exec` URL in a browser and you should get
`{"status":"ok","service":"Africa GATES",…}`.

---

## 3. The transcript path, and why it needs the manifest

A transcript exists **only if somebody switched transcription on during the call**
(Activities → Transcripts). It is off by default and it is a Workspace feature. So "none
found" is the ordinary answer, and the platform reports it as a next step, not an error.

The script tries two routes:

**1. The Meet REST API.** Authoritative — speaker names and timings.

This is the part that needs `config/appsscript.json`. The call goes out through
`UrlFetchApp` carrying `ScriptApp.getOAuthToken()`, so from the editor's point of view it
is an arbitrary HTTPS request: there is no Meet symbol to scan, **nothing prompts you for
the scope, and the token comes back without it**. Meet answers 403, the script falls
through to Drive, and the whole thing looks exactly like "nobody turned transcription on".
Declaring `https://www.googleapis.com/auth/meetings.space.readonly` in the manifest is the
only thing that fixes it.

**2. Drive.** Meet saves transcripts as Google Docs named after the calendar event. Cruder,
but it works where the Meet API is not available.

This branch is scoped to the sitting by the event title, which the platform sends as
`titleHint` and builds in exactly one place — `GoogleMeetService::eventTitle()` — so the
name used at creation and the name searched for a day later cannot drift. **With no hint
the script returns nothing rather than guessing.** That is deliberate: it previously took
the newest "Transcript" document in the entire Drive, so two interviews on one day meant
the second one's transcript answered a fetch for the first. Attaching one nominee's answers
to another nominee's judging record is not a failure that announces itself, and this feeds
the expert half of the score.

---

## 4. Two corrections, if you are comparing against an older copy

Both were live and both failed silently — the reason they lasted is that a wrong answer and
an empty one look identical here.

| Was | Is |
|---|---|
| The Meet API filter stripped hyphens: `space.meeting_code="abcdefghij"` | Meet's discovery document gives `meetingCode` the format `[a-z]+-[a-z]+-[a-z]+` and its own filter example as `space.meeting_code = "abc-mnop-xyz"`. Hyphens stay. The stripped form matched nothing, ever. |
| The Drive fallback searched all of Drive and took the newest | Scoped by event title; returns nothing without one. |
| The comment asked for the `meetings.conference.readonly` scope | No such scope exists. Meet defines only `meetings.space.created`, `.readonly` and `.settings`. |

---

## 5. Where this sits next to the bot

This is the **Google Meet** door: it books the room and asks Google for the transcript
Google made.

`docs/INTERVIEW-BOT.md` is a different thing — a bot that joins the call as a participant
and transcribes it itself, with a recogniser primed on the nominee's name. They are not
alternatives so much as different postures, and they can both be off: a panel can create a
Meet link by hand, run the call, and paste the transcript in.

The bot's recogniser is the better transcript for African names — see the note on
`AttendeeBot` about what Google's default recogniser does to them — but it needs a host,
and this needs nothing but the operator's Google account.
