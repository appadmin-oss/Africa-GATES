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
function meetCreate(d) {
  if(!d.startIso || !d.endIso) return respond(false,'Missing start or end time');
  if(typeof Calendar === 'undefined' || !Calendar.Events) {
    return respond(false,'The Calendar advanced service is not enabled in this Apps Script project. Open the script, Services → + → Calendar API → Add, then redeploy.');
  }

  const guests = (d.guests||[]).filter(function(x){ return /\S+@\S+\.\S+/.test(x); });
  const event = {
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
 *      timings, and needs the meetings.space.readonly + meetings.conference.readonly
 *      scopes — which Apps Script will ask you to grant the first time this runs. If your
 *      appsscript.json pins oauthScopes, add them there.
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

    const recs = UrlFetchApp.fetch(
      'https://meet.googleapis.com/v2/conferenceRecords?pageSize=20&filter=' +
      encodeURIComponent('space.meeting_code="' + code.replace(/-/g,'') + '"'), opts);

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
  try {
    const since = d.sinceIso ? new Date(d.sinceIso) : null;
    const files = DriveApp.searchFiles(
      'title contains "Transcript" and mimeType = "application/vnd.google-apps.document"');
    let best = null;
    while(files.hasNext()) {
      const f = files.next();
      if(since && f.getDateCreated() < since) continue;
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
