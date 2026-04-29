/**
 * Universal Service Experts — Lead Capture Web App
 *
 * Receives lead-form submissions from the static site, drops obvious spam,
 * stores per-lead photos + a formatted Doc in a Drive subfolder, and appends
 * a row to the master Sheet.
 */

const PARENT_FOLDER_ID = '1DXzcI-mGEHNKLSuqZDnx3z52QWL4yjZd';
const SHEET_FILE_NAME = 'USE Leads Index';
const SHEET_TAB_NAME = 'Leads';
const MIN_FORM_AGE_MS = 3000;
const MAX_PAYLOAD_BYTES = 45 * 1024 * 1024;
const STATUS_OPTIONS = ['New', 'Contacted', 'Quoted', 'Won', 'Lost'];

const HEADERS = [
  'timestamp', 'first_name', 'last_name', 'phone', 'email',
  'service_type', 'city', 'zip', 'street_address', 'description',
  'photo_count', 'folder_link', 'doc_link', 'image_preview',
  'status', 'source_page', 'user_agent'
];

const FIELD = {
  firstName:    'form_fields[name]',
  lastName:     'form_fields[field_8f8088c]',
  email:        'form_fields[field_6817b28]',
  phone:        'form_fields[email]',
  street:       'form_fields[field_52a322a]',
  city:         'form_fields[field_23b2960]',
  zip:          'form_fields[field_3640548]',
  service:      'form_fields[field_f0ec2ff]',
  description:  'form_fields[field_d6258ad]',
  honeypot:     'form_fields[field_ffc5e7c]'
};

function doGet() {
  return jsonResponse({ ok: true, ping: 'use-leads' });
}

function doPost(e) {
  try {
    if (!e || !e.postData || !e.postData.contents) {
      return jsonResponse({ ok: true });
    }
    if (e.postData.contents.length > MAX_PAYLOAD_BYTES) {
      return jsonResponse({ ok: false, error: 'Payload too large' });
    }

    const data = JSON.parse(e.postData.contents);

    if (data[FIELD.honeypot]) {
      return jsonResponse({ ok: true });
    }

    const formAge = Number(data._form_age_ms);
    if (!Number.isFinite(formAge) || formAge < MIN_FORM_AGE_MS) {
      return jsonResponse({ ok: true });
    }

    if (!data[FIELD.firstName] || !data[FIELD.email]) {
      return jsonResponse({ ok: false, error: 'Missing required fields' });
    }

    const sheet = getOrCreateSheet();
    const parent = DriveApp.getFolderById(PARENT_FOLDER_ID);
    const leadFolder = createLeadFolder(parent, data);
    const photos = savePhotos(leadFolder, data.photos || []);
    const doc = createSubmissionDoc(leadFolder, data, photos);
    appendRow(sheet, data, leadFolder, doc, photos);

    return jsonResponse({ ok: true, redirectUrl: '/thank-you/' });
  } catch (err) {
    console.error(err);
    return jsonResponse({ ok: false, error: String(err && err.message || err) });
  }
}

function jsonResponse(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}

function getOrCreateSheet() {
  const props = PropertiesService.getScriptProperties();
  let sheetId = props.getProperty('SHEET_ID');

  if (sheetId) {
    try {
      const existing = SpreadsheetApp.openById(sheetId);
      return existing.getSheetByName(SHEET_TAB_NAME) || existing.getSheets()[0];
    } catch (err) {
      console.warn('Stored sheet missing, recreating:', err);
    }
  }

  const ss = SpreadsheetApp.create(SHEET_FILE_NAME);
  sheetId = ss.getId();
  props.setProperty('SHEET_ID', sheetId);

  const parent = DriveApp.getFolderById(PARENT_FOLDER_ID);
  const sheetFile = DriveApp.getFileById(sheetId);
  parent.addFile(sheetFile);
  DriveApp.getRootFolder().removeFile(sheetFile);

  const sheet = ss.getSheets()[0];
  sheet.setName(SHEET_TAB_NAME);
  sheet.appendRow(HEADERS);
  sheet.setFrozenRows(1);
  sheet.getRange(1, 1, 1, HEADERS.length).setFontWeight('bold');

  const statusCol = HEADERS.indexOf('status') + 1;
  const statusRule = SpreadsheetApp.newDataValidation()
    .requireValueInList(STATUS_OPTIONS, true)
    .setAllowInvalid(false)
    .build();
  sheet.getRange(2, statusCol, sheet.getMaxRows() - 1, 1).setDataValidation(statusRule);

  sheet.setColumnWidth(HEADERS.indexOf('image_preview') + 1, 120);
  sheet.setColumnWidth(HEADERS.indexOf('description') + 1, 300);

  return sheet;
}

function createLeadFolder(parent, data) {
  const tz = Session.getScriptTimeZone() || 'America/New_York';
  const stamp = Utilities.formatDate(new Date(), tz, 'yyyy-MM-dd_HHmm');
  const last = sanitizeName(data[FIELD.lastName] || 'Unknown');
  const first = sanitizeName(data[FIELD.firstName] || 'Unknown');
  const service = sanitizeName(data[FIELD.service] || 'General');
  const name = `${stamp}_${last}_${first}_${service}`;
  return parent.createFolder(name);
}

function sanitizeName(s) {
  return String(s).trim().replace(/[^A-Za-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'x';
}

function savePhotos(folder, photos) {
  const saved = [];
  if (!Array.isArray(photos)) return saved;

  photos.forEach((p, i) => {
    if (!p || !p.base64) return;
    try {
      const bytes = Utilities.base64Decode(p.base64);
      const mime = p.mimeType || 'image/jpeg';
      const ext = mime.split('/')[1] || 'jpg';
      const name = (p.name && sanitizeName(p.name.replace(/\.[^.]+$/, ''))) || `photo_${i + 1}`;
      const blob = Utilities.newBlob(bytes, mime, `${name}.${ext}`);
      const file = folder.createFile(blob);
      saved.push({
        fileId: file.getId(),
        name: file.getName(),
        url: file.getUrl(),
        thumbUrl: `https://drive.google.com/thumbnail?id=${file.getId()}`
      });
    } catch (err) {
      console.error('Photo save failed', err);
    }
  });

  return saved;
}

function createSubmissionDoc(folder, data, photos) {
  const doc = DocumentApp.create('Submission');
  const docFile = DriveApp.getFileById(doc.getId());
  folder.addFile(docFile);
  DriveApp.getRootFolder().removeFile(docFile);

  const body = doc.getBody();
  body.clear();

  body.appendParagraph('Lead Submission').setHeading(DocumentApp.ParagraphHeading.HEADING1);
  body.appendParagraph(new Date().toString()).setItalic(true);

  const rows = [
    ['First Name',     data[FIELD.firstName]],
    ['Last Name',      data[FIELD.lastName]],
    ['Email',          data[FIELD.email]],
    ['Phone',          data[FIELD.phone]],
    ['Service Type',   data[FIELD.service]],
    ['Street Address', data[FIELD.street]],
    ['City',           data[FIELD.city]],
    ['ZIP',            data[FIELD.zip]],
    ['Source Page',    data._source_page || data['referer_title'] || '']
  ];

  const table = body.appendTable(rows.map(r => [r[0], String(r[1] || '')]));
  table.getRow(0).editAsText().setBold(true);

  body.appendParagraph('Description').setHeading(DocumentApp.ParagraphHeading.HEADING2);
  body.appendParagraph(String(data[FIELD.description] || '(none provided)'));

  if (photos.length) {
    body.appendParagraph('Photos').setHeading(DocumentApp.ParagraphHeading.HEADING2);
    photos.forEach(p => {
      const para = body.appendParagraph('');
      para.appendText(p.name).setLinkUrl(p.url);
    });
  }

  doc.saveAndClose();
  return docFile;
}

function appendRow(sheet, data, folder, docFile, photos) {
  const tz = Session.getScriptTimeZone() || 'America/New_York';
  const ts = Utilities.formatDate(new Date(), tz, 'yyyy-MM-dd HH:mm:ss');
  const previewFormula = photos.length
    ? `=IMAGE("https://drive.google.com/thumbnail?id=${photos[0].fileId}")`
    : '';

  sheet.appendRow([
    ts,
    data[FIELD.firstName] || '',
    data[FIELD.lastName] || '',
    data[FIELD.phone] || '',
    data[FIELD.email] || '',
    data[FIELD.service] || '',
    data[FIELD.city] || '',
    data[FIELD.zip] || '',
    data[FIELD.street] || '',
    data[FIELD.description] || '',
    photos.length,
    folder.getUrl(),
    docFile.getUrl(),
    previewFormula,
    'New',
    data._source_page || data['referer_title'] || '',
    data._user_agent || ''
  ]);

  const lastRow = sheet.getLastRow();
  sheet.setRowHeight(lastRow, 80);
}

/**
 * Run once manually after deploy to surface OAuth consent and
 * verify the script can write to the parent folder.
 */
function setup() {
  const folder = DriveApp.getFolderById(PARENT_FOLDER_ID);
  const sheet = getOrCreateSheet();
  console.log('Parent folder:', folder.getName(), folder.getUrl());
  console.log('Sheet:', sheet.getParent().getName(), sheet.getParent().getUrl());
}
