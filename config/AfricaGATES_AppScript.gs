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

function doPost(e) {
  let body;
  try { body = JSON.parse(e.postData.contents); } catch(err) { return respond(false,'Invalid JSON'); }
  const sheet = (body.sheet||'').toLowerCase().replace(/[^a-z_]/g,'');
  const data  = body.data;
  if(!sheet||!data) return respond(false,'Missing sheet or data');
  try { const row = writeRow(sheet,data,body.source||'web'); return respond(true,'Written',{row}); }
  catch(err) { return respond(false,err.message); }
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
