# USE Lead Capture — Apps Script

Google Apps Script Web App that receives lead-form submissions from the static
USE site, drops obvious spam, appends a row to a master Sheet, and (only when
photos are attached) stores them in a per-lead Drive subfolder.

The submission Doc that earlier versions created is gone — the Sheet row holds
every field, so creating a Doc on every submit was just adding 1–3 seconds of
latency before the user could be redirected to the thank-you page.

## Files
- `Code.gs` — server logic (paste into the Apps Script editor)
- `appsscript.json` — manifest (paste into the editor's manifest view)

## Parent folder
The script writes everything inside this Drive folder:

```
1DXzcI-mGEHNKLSuqZDnx3z52QWL4yjZd
```

The deploying Google account must be at least an **Editor** on that folder.

## Spam protection (layered)
1. **Honeypot** — the existing hidden field `form_fields[field_ffc5e7c]` in
   every form. Bots fill all inputs; if non-empty the script silently returns
   `{ok:true}` and writes nothing.
2. **Time-on-page** — submissions less than 3 seconds after page load are
   silently dropped. The client JS sends `_form_age_ms`.
3. **Required-field check** — submissions missing first name or email are
   rejected with `{ok:false}`.

The script never returns a 4xx for spam — bots get a successful-looking 200
response so they don't escalate.

## Deployment

1. Open https://script.google.com → **New project**. Name it `USE Lead Capture`.
2. **View → Show manifest file** to expose `appsscript.json`. Replace its
   contents with this repo's `appsscript.json`.
3. Replace `Code.gs` contents with this repo's `Code.gs`.
4. Save. Run the `setup` function once manually:
   - Click **Run** with `setup` selected in the function dropdown.
   - Approve all OAuth scopes (Drive, Sheets, Docs, external requests).
   - The execution log should print the parent folder name + the new Sheet's
     URL. Open the parent folder in Drive and confirm the `USE Leads Index`
     spreadsheet now exists with a header row and frozen first row.
5. **Deploy → New deployment** → type **Web app**.
   - Description: `v1`
   - Execute as: **Me**
   - Who has access: **Anyone**
6. Click **Deploy**. Copy the **Web app URL** (the one ending in `/exec`).
7. Paste that URL into the `data-endpoint` attribute of the `<form
   id="home_hero_form">` on each of the 7 HTML pages (see the site repo).

## Updating

When you change `Code.gs` you must re-deploy:
- **Deploy → Manage deployments → pencil icon → New version → Deploy**.
- The `/exec` URL stays the same across versions.

## Verification

After deploy, smoke-test:

```bash
# health check
curl -L 'https://script.google.com/macros/s/.../exec'
# → {"ok":true,"ping":"use-leads"}
```

Then submit one real form via the live site and confirm in Drive:
- A new row appended in `USE Leads Index` with all submitted fields
- If photos were attached: a subfolder named
  `YYYY-MM-DD_HHMM_Last_First_Service` containing the saved files, with the
  thumbnail rendering in the `image_preview` cell and the folder URL in
  `folder_link`. No-photo submissions skip folder creation entirely.

## Spam tests

In the browser devtools console on a form page:

```js
// Honeypot test — should NOT create a Sheet row
document.getElementById('form-field-field_ffc5e7c').value = 'bot';
document.getElementById('home_hero_form').requestSubmit();
```

```js
// Timer test — submit immediately on page load (<3s) should NOT create a row
// (open page, immediately fill required fields and submit)
```

Both should redirect-or-resolve as if successful, but no row appears in the
Sheet and no folder appears in Drive.

## Resetting

If you need the script to recreate the Sheet from scratch:

1. Apps Script editor → **Project Settings** (gear icon) → **Script
   Properties**.
2. Delete the `SHEET_ID` property.
3. Move the existing `USE Leads Index` Sheet to trash if you want a clean
   slate.
4. Next submission will auto-create a new one inside the parent folder.
