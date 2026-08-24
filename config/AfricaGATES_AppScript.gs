/**
 * AFRICA GATES — GOOGLE APPS SCRIPT
 * Deploy: Extensions → Apps Script → Deploy → Web App
 * Execute as: Me  |  Access: Anyone
 * Paste the /exec URL into GAS_URL in .env
 */

const SS = SpreadsheetApp.getActiveSpreadsheet();

const HEADERS = {
  registrations: ['Timestamp','Display Name','Profile Type','Category','Country Code','Email','Bio','Status','Source'],
  nominations:   ['Timestamp','Programme ID','Nominee Name','Country Code','Reason','Nominator Name','Nominator Email','Status','Source'],
  votes:         ['Timestamp','Award ID','Category ID','Nominee ID','Nominee Name','Voter Email Hash','Country','Status'],
  partners:      ['Timestamp','Org Name','Contact Name','Contact Email','Phone','Partnership Type','Message','Status'],
  otp_log:       ['Timestamp','Email Hash','Purpose','Award ID','Used','Expires At'],
};

function doGet(e) {
  return ContentService
    .createTextOutput(JSON.stringify({status:'ok',service:'Africa GATES',ts:new Date().toISOString()}))
    .setMimeType(ContentService.MimeType.JSON);
}

/**
 * SHARED SECRET FOR THE PRIVILEGED ACTIONS. Leave it empty and meet.create /
 * meet.transcript are refused outright.
 *
 * This web app is deployed "access: anyone", because the platform posts to it with no
 * credentials. For appending a row to a private sheet that is an acceptable trade — the
 * worst a stranger with the URL can do is write junk into a spreadsheet.
 *
 * Booking events in YOUR calendar and reading the text of YOUR meetings is not that. So
 * put a long random string here AND the identical string in .env as GAS_SECRET, then
 * redeploy (Deploy → Manage deployments → edit → New version). Sheet writes are
 * unaffected either way, so an existing deployment keeps working.
 */
const SECRET = '';

function doPost(e) {
  let body;
  try { body = JSON.parse(e.postData.contents); } catch(err) { return respond(false,'Invalid JSON'); }

  // Actions come first: they are not sheet writes and must not fall into writeRow().
  const action = (body.action||'').toString();
  if(action) {
    if(!SECRET) return respond(false,'This Apps Script has no SECRET set, so calendar and transcript actions are refused. Set SECRET at the top of the script and GAS_SECRET in .env to the same value, then redeploy.');
    if(body.token !== SECRET) return respond(false,'Bad token');
    try {
      if(action === 'meet.create')     return meetCreate(body.data||{});
      if(action === 'meet.transcript') return meetTranscript(body.data||{});
      // Calendar sync and booking. See the block comment above calendarSync().
      if(action === 'calendar.sync')    return calendarSync(body.data||{});
      if(action === 'calendar.cancel')  return calendarCancel(body.data||{});
      if(action === 'calendar.freebusy')return calendarFreeBusy(body.data||{});
      if(action === 'calendar.slots')   return calendarSlots(body.data||{});
      // READ an event back. See the block comment above calendarRead().
      if(action === 'calendar.read')    return calendarRead(body.data||{});
      return respond(false,'Unknown action: '+action);
    } catch(err) { return respond(false,err.message); }
  }

  const sheet = (body.sheet||'').toLowerCase().replace(/[^a-z_]/g,'');
  const data  = body.data;
  if(!sheet||!data) return respond(false,'Missing sheet or data');
  try { const row = writeRow(sheet,data,body.source||'web'); return respond(true,'Written',{row}); }
  catch(err) { return respond(false,err.message); }
}

/**
 * Create a calendar event that carries a Google Meet link, and invite the guests.
 *
 * Needs the Calendar ADVANCED service (Services → + → Calendar API). CalendarApp on its
 * own cannot attach a conference, which is why this uses Calendar.Events.insert with
 * conferenceDataVersion:1 — and why the failure is reported as "enable the service"
 * rather than as a mystery.
 */
/**
 * ── ON "CO-HOST", BECAUSE IT IS THE WORD THE REQUEST ARRIVES IN ──────────────
 *
 * Calendar's Events resource has NO co-host field, and no amount of Apps Script will add
 * one: co-host is a MEET concept, granted through the Meet REST API's spaces.members (role
 * COHOST — Workspace only, and a scope this project does not hold) or by the host with one
 * click inside the call.
 *
 * Two things an EVENT can express, and they are what people usually mean:
 *
 *   · INVITED. An attendee is admitted to the Meet straight away instead of knocking, and
 *     gets the invitation and the reminders. External addresses are attendees like any
 *     other — the limitation was never Google's, it was that this platform had no field to
 *     type one into.
 *   · guestsCanModify. They can change the event itself: move it, edit it, add people.
 *
 * Both are set below. Promotion to a true Meet co-host stays one click for the host, and
 * the admin screen says so rather than implying otherwise.
 */
function meetCreate(d) {
  if(!d.startIso || !d.endIso) return respond(false,'Missing start or end time');
  if(typeof Calendar === 'undefined' || !Calendar.Events) {
    return respond(false,'The Calendar advanced service is not enabled in this Apps Script project. Open the script, Services → + → Calendar API → Add, then redeploy.');
  }

  const guests = (d.guests||[]).filter(function(x){ return /\S+@\S+\.\S+/.test(x); });
  const event = {
    // See the note above: the nearest thing an event has to a co-host.
    guestsCanModify: d.guestsCanModify === true,
    summary:     (d.title||'Africa GATES interview').substring(0,200),
    description: (d.description||'').substring(0,2000),
    start: { dateTime: d.startIso, timeZone: d.timezone||'Africa/Lagos' },
    end:   { dateTime: d.endIso,   timeZone: d.timezone||'Africa/Lagos' },
    attendees: guests.map(function(email){ return {email:email}; }),
    conferenceData: {
      createRequest: {
        // Must be unique per request or Google reuses the previous conference.
        requestId: 'agates-' + Utilities.getUuid(),
        conferenceSolutionKey: { type: 'hangoutsMeet' }
      }
    },
    reminders: { useDefault:false, overrides:[
      {method:'email', minutes:1440}, {method:'popup', minutes:30}
    ]}
  };

  const created = Calendar.Events.insert(event, 'primary', {
    conferenceDataVersion: 1,
    sendUpdates: guests.length ? 'all' : 'none'
  });

  let meetUrl = created.hangoutLink || '';
  if(!meetUrl && created.conferenceData && created.conferenceData.entryPoints) {
    created.conferenceData.entryPoints.forEach(function(p){
      if(!meetUrl && p.entryPointType === 'video' && p.uri) meetUrl = p.uri;
    });
  }
  return respond(true, meetUrl ? 'Created' : 'Created without a Meet link',
                 {ok:true, meetUrl:meetUrl, eventId:created.id||''});
}

/**
 * Fetch the transcript Google made for a conference, by its abc-defg-hij code.
 *
 * Two routes, tried in order:
 *
 *   1. The Meet REST API (meet.googleapis.com/v2). Authoritative, gives speaker names and
 *      timings.
 *
 *      IT NEEDS A SCOPE APPS SCRIPT CANNOT WORK OUT FOR ITSELF. The call goes through
 *      UrlFetchApp with ScriptApp.getOAuthToken(), so from the editor's point of view this
 *      is an arbitrary HTTPS request — there is no Meet symbol for it to scan. Nothing
 *      prompts you, the token comes back without the scope, Meet answers 403, and this
 *      falls through to Drive looking exactly like "nobody turned transcription on".
 *
 *      So `https://www.googleapis.com/auth/meetings.space.readonly` MUST be declared in
 *      the manifest — `config/appsscript.json` in this repo has it, along with everything
 *      else the script touches. (An earlier version of this comment asked for
 *      `meetings.conference.readonly`, which is not a scope that exists; the Meet API
 *      defines only meetings.space.created, .readonly and .settings.)
 *   2. Drive. Meet saves transcripts as Google Docs in a "Meet Recordings" folder, named
 *      after the meeting. Cruder, but it works on accounts where the Meet API is not
 *      available, and it is the only route for a transcript somebody has already moved.
 *
 * A transcript exists ONLY if somebody switched transcription on during the call. Nothing
 * here can create one after the fact, so "none found" is the ordinary answer.
 */
function meetTranscript(d) {
  const code = (d.meetCode||'').toString().toLowerCase();
  if(!/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/.test(code)) return respond(false,'Bad meet code');

  // ── 1. the Meet REST API ────────────────────────────────────────────────────
  try {
    const token = ScriptApp.getOAuthToken();
    const opts  = {method:'get', headers:{Authorization:'Bearer '+token}, muteHttpExceptions:true};

    // The code goes in WITH its hyphens. Meet's own discovery document gives
    // meetingCode the format [a-z]+-[a-z]+-[a-z]+ and its filter example verbatim as
    // space.meeting_code = "abc-mnop-xyz". Stripping them — which this did — produced a
    // filter that matched nothing, every time, and the failure was invisible: an empty
    // result is indistinguishable from "nobody turned transcription on", so every fetch
    // fell quietly through to the Drive branch below.
    const recs = UrlFetchApp.fetch(
      'https://meet.googleapis.com/v2/conferenceRecords?pageSize=20&filter=' +
      encodeURIComponent('space.meeting_code="' + code + '"'), opts);

    if(recs.getResponseCode() === 200) {
      const list = JSON.parse(recs.getContentText()).conferenceRecords || [];
      for(let i=0;i<list.length;i++) {
        const trs = UrlFetchApp.fetch('https://meet.googleapis.com/v2/'+list[i].name+'/transcripts', opts);
        if(trs.getResponseCode() !== 200) continue;
        const transcripts = JSON.parse(trs.getContentText()).transcripts || [];
        for(let j=0;j<transcripts.length;j++) {
          let text = '', page = '';
          do {
            const ent = UrlFetchApp.fetch('https://meet.googleapis.com/v2/'+transcripts[j].name+
              '/entries?pageSize=1000'+(page?'&pageToken='+page:''), opts);
            if(ent.getResponseCode() !== 200) break;
            const body = JSON.parse(ent.getContentText());
            (body.transcriptEntries||[]).forEach(function(en){
              const who = (en.participant||'').split('/').pop();
              text += (who? who+': ' : '') + (en.text||'') + '\n';
            });
            page = body.nextPageToken || '';
          } while(page);
          if(text.trim()) return respond(true,'Fetched',{ok:true,text:text.trim(),source:'meet-api'});
        }
      }
    }
  } catch(err) {
    // Fall through to Drive: a missing scope or an unavailable API is not a failure yet.
  }

  // ── 2. Drive ────────────────────────────────────────────────────────────────
  //
  // THIS BRANCH MUST BE SCOPED TO THE MEETING, AND IT WAS NOT.
  //
  // It used to search all of Drive for `title contains "Transcript"` and take the most
  // recently created one after sinceIso — nothing in that ties a document to THIS
  // interview. Two sittings on one day meant the second one's transcript was the newest,
  // so fetching for the first returned the wrong nominee's words. Google names a Meet
  // transcript after the calendar event, so the event title is the link, and the caller
  // passes it as titleHint.
  //
  // With no hint, this returns nothing rather than guessing. "None found — paste it in"
  // is the ordinary answer here anyway; attaching one nominee's answers to another
  // nominee's judging record is not a failure that announces itself, and this feature
  // feeds the expert score.
  const hint = (d.titleHint||'').toString().trim();
  if(!hint) return respond(true,'No transcript found',{ok:true,text:'',source:''});

  try {
    const since = d.sinceIso ? new Date(d.sinceIso) : null;
    // Drive's `title contains` takes one term, not a phrase, so the hint is filtered
    // again in JS below rather than trusted to the query alone.
    const files = DriveApp.searchFiles(
      'title contains "Transcript" and mimeType = "application/vnd.google-apps.document"');
    const needle = hint.toLowerCase();
    let best = null;
    while(files.hasNext()) {
      const f = files.next();
      if(since && f.getDateCreated() < since) continue;
      if(f.getName().toLowerCase().indexOf(needle) === -1) continue;
      if(!best || f.getDateCreated() > best.getDateCreated()) best = f;
    }
    if(best) {
      const text = DocumentApp.openById(best.getId()).getBody().getText();
      if(text && text.trim()) {
        return respond(true,'Fetched from Drive',
          {ok:true, text:text.trim(), source:'drive:'+best.getName()});
      }
    }
  } catch(err) {
    return respond(false,'Drive lookup failed: '+err.message);
  }

  return respond(true,'No transcript found',{ok:true,text:'',source:''});
}

/* ═══════════════════════════════════════════════════════════════════════════
   CALENDAR SYNC AND APPOINTMENT BOOKING

   ── WHY meet.create WAS NOT ENOUGH ───────────────────────────────────────────

   meet.create inserts an event with `requestId: 'agates-' + Utilities.getUuid()`. That
   uuid is right for the conference — it has to be unique or Google reuses the previous
   Meet — and it means the CALL is not idempotent: run it twice for the same sitting and
   the operator has two events, two Meet links, and two sets of invitations to a meeting
   that happens once. Rescheduling had the same shape: there was no update path at all, so
   moving a sitting meant a second event and a guest holding a stale invitation.

   ── THE STABLE KEY ──────────────────────────────────────────────────────────

   Every event this writes is stamped with `extendedProperties.private.agatesKey`, which
   the platform sets to something durable about the sitting (an interview id). Calendar can
   be SEARCHED by that — `privateExtendedProperty: 'agatesKey=<key>'` — so sync() looks
   first and patches what it finds. Re-running is then a no-op that returns the same event
   id and the same Meet link, however many times the cron fires.

   Not a stored mapping table on our side: a mapping row and a calendar can disagree, and
   when they do the calendar is right and the row is a lie. The key lives ON the event.

   ── WHAT "APPOINTMENT BOOKING" CAN AND CANNOT MEAN HERE ─────────────────────

   Google's Appointment Schedules — the booking pages with a public URL — have NO API. They
   are not in Calendar v3, not in the Apps Script advanced service, and not readable by any
   scope. So `calendar.slots` does NOT read an appointment schedule, and it must not claim
   to: it computes free slots from the calendar's own free/busy, inside working hours the
   caller passes in. That is a different thing built from what is actually available, and it
   is the honest version of the feature.

   Booking one is then just calendar.sync at the chosen time, which is what makes the
   round trip work: slots → pick → sync → the guest gets an invitation with a Meet link.
   ═══════════════════════════════════════════════════════════════════════════ */

/** The Calendar advanced service, or a response explaining how to switch it on. */
function calendarReady() {
  if(typeof Calendar === 'undefined' || !Calendar.Events) {
    return respond(false,'The Calendar advanced service is not enabled in this Apps Script project. Open the script, Services → + → Calendar API → Add, then redeploy.');
  }
  return null;
}

/** Find the one event carrying this key, or null. */
function findByKey(calendarId, key) {
  if(!key) return null;
  try {
    const found = Calendar.Events.list(calendarId, {
      privateExtendedProperty: 'agatesKey=' + key,
      // Cancelled events still match the search, and patching one back to life is not
      // what a re-run should do — a cancelled sitting that returns should be a new event
      // with its own invitation, not a resurrection nobody was told about.
      showDeleted: false,
      maxResults: 5,
      singleEvents: true
    });
    const items = (found && found.items) || [];
    return items.length ? items[0] : null;
  } catch(err) {
    // A search failure must not become a duplicate. Reported rather than swallowed.
    throw new Error('Could not search the calendar for key ' + key + ': ' + err.message);
  }
}

/**
 * Create or update one sitting in the calendar. Idempotent on `key`.
 *
 * data: { key, startIso, endIso, title, description, guests[], timezone, calendarId,
 *         withMeet (default true), notify (default true) }
 */
function calendarSync(d) {
  const notReady = calendarReady(); if(notReady) return notReady;
  if(!d.key)                   return respond(false,'A sync needs a stable key, or it cannot avoid duplicating.');
  if(!d.startIso || !d.endIso) return respond(false,'Missing start or end time');

  const calendarId = d.calendarId || 'primary';
  const tz         = d.timezone || 'Africa/Lagos';
  const guests     = (d.guests||[]).filter(function(x){ return /\S+@\S+\.\S+/.test(x); });
  const notify     = d.notify === false ? 'none' : (guests.length ? 'all' : 'none');
  const withMeet   = d.withMeet !== false;

  const base = {
    // See meetCreate()'s note on co-hosts: an event cannot grant one, and this is the
    // nearest elevation it can carry.
    guestsCanModify: d.guestsCanModify === true,
    summary:     (d.title||'Africa GATES interview').substring(0,200),
    description: (d.description||'').substring(0,2000),
    start: { dateTime: d.startIso, timeZone: tz },
    end:   { dateTime: d.endIso,   timeZone: tz },
    attendees: guests.map(function(email){ return {email:email}; }),
    // The key rides on the event, so the calendar is the single source of truth about
    // which sitting this is. A mapping table on our side could disagree with it.
    extendedProperties: { private: { agatesKey: String(d.key).substring(0,120), agatesApp: 'africa-gates' } },
    reminders: { useDefault:false, overrides:[
      {method:'email', minutes:1440}, {method:'popup', minutes:30}
    ]}
  };

  const existing = findByKey(calendarId, d.key);

  if(existing) {
    // PATCH, not update: update() replaces the whole resource, which would drop the
    // conferenceData a previous run created and hand every guest a new Meet link for a
    // meeting they already have in their diary.
    const patched = Calendar.Events.patch(base, calendarId, existing.id, {
      conferenceDataVersion: 1,
      sendUpdates: notify
    });
    return respond(true,'Updated', {
      ok:true, created:false, eventId: patched.id||existing.id,
      meetUrl: meetUrlOf(patched) || meetUrlOf(existing),
      htmlLink: patched.htmlLink || existing.htmlLink || ''
    });
  }

  if(withMeet) {
    base.conferenceData = {
      createRequest: {
        // Unique per REQUEST — Google reuses the previous conference otherwise. The
        // idempotency of the whole call comes from agatesKey above, not from this.
        requestId: 'agates-' + Utilities.getUuid(),
        conferenceSolutionKey: { type: 'hangoutsMeet' }
      }
    };
  }

  const created = Calendar.Events.insert(base, calendarId, {
    conferenceDataVersion: withMeet ? 1 : 0,
    sendUpdates: notify
  });
  const url = meetUrlOf(created);

  return respond(true, (withMeet && !url) ? 'Created without a Meet link' : 'Created', {
    ok:true, created:true, eventId: created.id||'', meetUrl: url,
    htmlLink: created.htmlLink || ''
  });
}

/**
 * READ one event back out of the calendar.
 *
 * ── WHY A READ, WHEN calendar.sync ALREADY WRITES ────────────────────────────
 *
 * Because the calendar is where the appointment actually LIVES, and the platform's copy of
 * it is a copy. An organiser who drags the meeting to Thursday does it in Google Calendar —
 * that is the whole point of putting it there — and nothing told us. Our row kept the old
 * time, and the recording bot, which is dispatched off our row, turned up on Tuesday to an
 * empty room while the interview happened on Thursday with nobody recording it.
 *
 * The same is true of the Meet link: a link created or replaced in the calendar (a
 * conference re-created, an event rebuilt by hand after a mistake) never reached us, and
 * the bot was sent to a URL that no longer opened a room.
 *
 * So this is the missing direction. `calendar.sync` pushes what we intend; this reads back
 * what is true.
 *
 * Addressed by `eventId` when we have one, and by `key` otherwise — the same
 * `agatesKey` extended property `findByKey` uses, so a sitting whose event id was never
 * stored is still findable.
 *
 * A DELETED or cancelled event is reported as `found:false` rather than as an error: the
 * caller's correct response is to stop expecting a meeting, and an exception would look
 * like a broken integration instead.
 */
function calendarRead(d) {
  const notReady = calendarReady(); if(notReady) return notReady;

  const calendarId = d.calendarId || 'primary';
  let ev = null;

  try {
    if(d.eventId) {
      ev = Calendar.Events.get(calendarId, String(d.eventId));
    } else if(d.key) {
      ev = findByKey(calendarId, String(d.key));
    } else {
      return respond(false,'A read needs an eventId or a key.');
    }
  } catch(err) {
    // 404/410 mean the event is gone, which is an ANSWER. Anything else is a fault.
    const m = String(err.message||'');
    if(/not found|deleted|404|410/i.test(m)) return respond(true,'Gone',{ok:true, found:false});
    return respond(false, m);
  }

  if(!ev || ev.status === 'cancelled') return respond(true,'Gone',{ok:true, found:false});

  return respond(true,'Found',{
    ok: true,
    found: true,
    eventId:  ev.id || '',
    // Google returns dateTime for a timed event and date for an all-day one. An
    // all-day interview is not a thing, but returning both rather than assuming keeps a
    // hand-made event from arriving here as an empty string.
    startIso: (ev.start && (ev.start.dateTime || ev.start.date)) || '',
    endIso:   (ev.end   && (ev.end.dateTime   || ev.end.date))   || '',
    timezone: (ev.start && ev.start.timeZone) || '',
    meetUrl:  meetUrlOf(ev),
    htmlLink: ev.htmlLink || '',
    summary:  ev.summary || '',
    status:   ev.status || ''
  });
}

/** The video entry point of an event, however Google chose to express it. */
function meetUrlOf(ev) {
  if(!ev) return '';
  if(ev.hangoutLink) return ev.hangoutLink;
  let url = '';
  if(ev.conferenceData && ev.conferenceData.entryPoints) {
    ev.conferenceData.entryPoints.forEach(function(p){
      if(!url && p.entryPointType === 'video' && p.uri) url = p.uri;
    });
  }
  return url;
}

/**
 * Cancel a sitting. Idempotent: cancelling one that is already gone is a success.
 *
 * Deleted rather than marked cancelled, because a cancelled-but-present event stays in
 * every guest's calendar as a greyed-out row and reads as "maybe". `sendUpdates` is what
 * actually tells them, and it is on by default — a cancellation nobody is told about is
 * the guest turning up.
 */
function calendarCancel(d) {
  const notReady = calendarReady(); if(notReady) return notReady;
  if(!d.key) return respond(false,'A cancel needs the key the event was created with.');

  const calendarId = d.calendarId || 'primary';
  const existing = findByKey(calendarId, d.key);
  if(!existing) return respond(true,'Nothing to cancel', {ok:true, cancelled:false});

  Calendar.Events.remove(calendarId, existing.id, {
    sendUpdates: d.notify === false ? 'none' : 'all'
  });
  return respond(true,'Cancelled', {ok:true, cancelled:true, eventId: existing.id});
}

/**
 * Busy blocks in a window. The raw material for a booking screen.
 *
 * data: { fromIso, toIso, calendarId, timezone }
 */
function calendarFreeBusy(d) {
  const notReady = calendarReady(); if(notReady) return notReady;
  if(!d.fromIso || !d.toIso) return respond(false,'Missing fromIso or toIso');
  if(!Calendar.Freebusy) {
    return respond(false,'This Calendar service build has no Freebusy. Re-add the Calendar API advanced service (v3) and redeploy.');
  }

  const calendarId = d.calendarId || 'primary';
  const q = Calendar.Freebusy.query({
    timeMin: d.fromIso, timeMax: d.toIso,
    timeZone: d.timezone || 'Africa/Lagos',
    items: [{ id: calendarId }]
  });

  const cal  = (q.calendars && q.calendars[calendarId]) || {};
  // An error here is per-calendar, not per-request: a wrong id returns 200 with an errors
  // array, and treating that as "no busy blocks" would offer every slot in a full week.
  if(cal.errors && cal.errors.length) {
    return respond(false,'Calendar ' + calendarId + ': ' + (cal.errors[0].reason || 'not readable'));
  }

  return respond(true,'Free/busy read', {
    ok:true, calendarId: calendarId,
    busy: (cal.busy||[]).map(function(b){ return {start:b.start, end:b.end}; })
  });
}

/**
 * Open slots in a window, computed from free/busy.
 *
 * NOT an Appointment Schedule. Google's booking pages have no API at any scope, so this
 * builds slots from the calendar's own busy blocks inside working hours the caller passes
 * in. Booking one is calendar.sync at that time.
 *
 * data: { fromIso, toIso, minutes (default 30), gapMinutes (default 0),
 *         dayStart (default '09:00'), dayEnd (default '17:00'),
 *         days (default [1,2,3,4,5] — Mon..Fri, 0 = Sunday),
 *         leadMinutes (default 120), max (default 100), calendarId, timezone }
 */
function calendarSlots(d) {
  const fb = calendarFreeBusy(d);
  const parsed = JSON.parse(fb.getContent());
  if(!parsed.success) return fb;

  const minutes = Math.max(5, Math.min(480, Number(d.minutes||30)));
  const gap     = Math.max(0, Math.min(240, Number(d.gapMinutes||0)));
  const step    = (minutes + gap) * 60000;
  const lead    = Math.max(0, Number(d.leadMinutes === undefined ? 120 : d.leadMinutes)) * 60000;
  const cap     = Math.max(1, Math.min(500, Number(d.max||100)));
  const days    = Array.isArray(d.days) ? d.days.map(Number) : [1,2,3,4,5];

  const from = new Date(d.fromIso).getTime();
  const to   = new Date(d.toIso).getTime();
  if(!from || !to || to <= from) return respond(false,'fromIso must be before toIso');

  // The lead time is a real constraint, not politeness: a slot offered fifteen minutes
  // from now is one a judge cannot actually be in, and it is the slot somebody books.
  const earliest = Math.max(from, Date.now() + lead);

  const busy = (parsed.busy||[]).map(function(b){
    return { s: new Date(b.start).getTime(), e: new Date(b.end).getTime() };
  }).filter(function(b){ return b.s && b.e; });

  const tz = d.timezone || 'Africa/Lagos';
  const hhmm = function(str, fallback) {
    const m = /^(\d{1,2}):(\d{2})$/.exec(String(str||''));
    return m ? (Number(m[1]) * 60 + Number(m[2])) : fallback;
  };
  const openMin  = hhmm(d.dayStart, 9*60);
  const closeMin = hhmm(d.dayEnd,  17*60);

  const slots = [];
  for(let t = Math.ceil(earliest / step) * step; t + minutes*60000 <= to && slots.length < cap; t += step) {
    const start = new Date(t);
    const end   = new Date(t + minutes*60000);

    // Day-of-week and time-of-day are read IN THE TARGET TIMEZONE, not the script's. A
    // script set to one zone offering slots for a calendar in another is how a 9am slot
    // lands at 4am for the person taking the interview.
    const dow  = Number(Utilities.formatDate(start, tz, 'u')) % 7;   // u: 1=Mon..7=Sun
    const mins = Number(Utilities.formatDate(start, tz, 'H')) * 60
               + Number(Utilities.formatDate(start, tz, 'm'));

    if(days.indexOf(dow) === -1) continue;
    if(mins < openMin || (mins + minutes) > closeMin) continue;

    const clash = busy.some(function(b){ return b.s < end.getTime() && b.e > start.getTime(); });
    if(clash) continue;

    slots.push({ startIso: start.toISOString(), endIso: end.toISOString() });
  }

  return respond(true, slots.length + ' slot(s)', {
    ok:true, minutes:minutes, timezone:tz, slots:slots,
    // Said plainly, because somebody will ask why this does not match their booking page.
    note:'Computed from calendar free/busy. Google Appointment Schedules have no API, so ' +
         'this is not reading one.'
  });
}

function writeRow(sheetName, data, source) {
  let ws = SS.getSheetByName(sheetName);
  if(!ws) {
    ws = SS.insertSheet(sheetName);
    const h = HEADERS[sheetName];
    if(h) {
      ws.appendRow(h);
      ws.getRange(1,1,1,h.length).setBackground('#007b00').setFontColor('#ffffff').setFontWeight('bold').setFontSize(11);
      ws.setFrozenRows(1);
    }
  }
  const ts = new Date().toISOString();
  let row;
  switch(sheetName) {
    case 'registrations': row=[ts,s(data.display_name),s(data.profile_type),s(data.category),s(data.country_code),s(data.email),s(data.bio||''),'pending',source]; break;
    case 'nominations':   row=[ts,s(data.programme_id),s(data.nominee_name),s(data.country_code),s(data.reason),s(data.nominator_name),s(data.nominator_email),'pending',source]; break;
    case 'votes':         row=[ts,s(data.award_id),s(data.category_id||''),s(data.nominee_id),s(data.nominee_name||''),s(data.voter_email_hash||''),s(data.country||''),'recorded']; break;
    case 'partners':      row=[ts,s(data.org_name),s(data.contact_name),s(data.contact_email),s(data.contact_phone||''),s(data.partnership_type||''),s(data.message),'new']; break;
    case 'otp_log':       row=[ts,s(data.email_hash),s(data.purpose||'vote'),s(data.award_id||''),s(data.is_used?'yes':'no'),s(data.expires_at||'')]; break;
    default: row=[ts,JSON.stringify(data),source];
  }
  ws.appendRow(row);
  if(ws.getLastRow()===2) ws.autoResizeColumns(1,ws.getLastColumn());
  ws.getRange(ws.getLastRow(),1,1,ws.getLastColumn()).setBackground('#fff9e6');
  return ws.getLastRow();
}

function s(val) {
  if(val===null||val===undefined) return '';
  const str=String(val).trim();
  if(/^[=+\-@\t\r]/.test(str)) return "'"+str;
  return str.substring(0,1000);
}

function respond(success, message, extra) {
  return ContentService.createTextOutput(JSON.stringify({success,message,timestamp:new Date().toISOString(),...extra||{}})).setMimeType(ContentService.MimeType.JSON);
}

function sendDailySummary() {
  const adminEmail = Session.getActiveUser().getEmail();
  const yesterday  = new Date(Date.now()-86400000);
  const sheets     = ['registrations','nominations','votes','partners'];
  let body = `<h2 style="color:#007b00">Africa GATES Daily Summary</h2><p>${yesterday.toDateString()}</p><table border="1" cellpadding="6" style="border-collapse:collapse"><tr><th>Sheet</th><th>New Rows</th></tr>`;
  sheets.forEach(name => {
    const ws=SS.getSheetByName(name); if(!ws||ws.getLastRow()<2){body+=`<tr><td>${name}</td><td>0</td></tr>`;return;}
    const tsCol=ws.getRange(2,1,ws.getLastRow()-1,1).getValues();
    const count=tsCol.filter(r=>{try{const d=new Date(r[0]);return d>=yesterday&&d<new Date()}catch(e){return false}}).length;
    body+=`<tr><td>${name}</td><td>${count}</td></tr>`;
  });
  body+=`</table><p><a href="https://docs.google.com/spreadsheets/d/${SS.getId()}">Open Spreadsheet</a></p>`;
  MailApp.sendEmail({to:adminEmail,subject:`Africa GATES Daily — ${yesterday.toDateString()}`,htmlBody:body});
}

function setupTriggers() {
  ScriptApp.getProjectTriggers().forEach(t=>ScriptApp.deleteTrigger(t));
  ScriptApp.newTrigger('sendDailySummary').timeBased().everyDays(1).atHour(8).create();
}

function onOpen() {
  SpreadsheetApp.getUi().createMenu('Africa GATES').addItem('Setup Triggers','setupTriggers').addItem('Daily Summary','sendDailySummary').addToUi();
}
