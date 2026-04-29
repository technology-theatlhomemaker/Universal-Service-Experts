# Universal Service Experts — Static Site

Statically-rendered version of universalserviceexperts.com, exported from the
former WordPress install on Kinsta. Deployable as plain HTML/CSS/JS to any
static host (Hostinger, Cloudflare Pages, Netlify, S3, etc.) with no PHP,
MySQL, or WordPress required at runtime.

## Status

- [x] WP backup imported locally (Docker + MariaDB)
- [x] Site rendered, crawled, and post-processed into static HTML
- [x] Dead links cleaned (phone, email, address)
- [x] All 8 pages verified to render at parity with the live site
- [ ] Form submission backend (Google Apps Script + Drive + Sheets) — see TODO
- [ ] Form action wired to backend in static HTML — see TODO
- [ ] Pushed to Hostinger and DNS cut over

## Repository Layout

```
public_html/                       deployable site — push this to Hostinger
├── index.html                     home
├── hvac/index.html                
├── plumbing/index.html            
├── handyman-services/index.html   
├── electrical-services/index.html 
├── metering-services/index.html   
├── contact-us/index.html          
├── thank-you/index.html           form-submit success page
├── api/                           PHP backend (lead.php, db.php, migrations)
├── assets/                        config.js, use-form.js
├── media/                         site images (was wp-content/uploads/)
└── lib/                           Elementor + theme + jQuery assets
    ├── elementor/                 Elementor Free frontend JS+CSS
    ├── elementor-pro/             Elementor Pro frontend JS+CSS
    ├── theme/                     hello-elementor theme stylesheets
    ├── jquery/                    jquery, jquery-migrate, jquery-ui core
    ├── hooks.min.js               @wordpress/hooks (Elementor dep)
    ├── i18n.min.js                @wordpress/i18n (Elementor dep)
    └── emoji-release.min.js       emoji renderer (legacy browsers)

rebuild-YYYYMMDD.tar.gz            archive of the build environment
                                   (Docker compose, scripts, WP working copy)

wp-full-site-backup-04-23-26.zip   pristine WP backup from Kinsta
                                   (database dump + public/ filesystem)
```

## Local development

The site is plain HTML/CSS/JS — any static file server works. Pick one:

### Python (no dependencies, ships with macOS)

```bash
./scripts/build-config.sh           # one-time, generates config.js from .env
cd public_html
python3 -m http.server 8000
```

Open http://localhost:8000.

### Node (`npx serve`)

```bash
./scripts/build-config.sh
npx serve public_html -l 8000
```

### PHP

```bash
./scripts/build-config.sh
php -S localhost:8000 -t public_html
```

### Notes

- Run `./scripts/build-config.sh` **before** starting the server, otherwise
  `/assets/config.js` returns 404 and form submissions show "Form is not
  configured."
- After editing `.env`, re-run the build script — your browser will pick up
  the new `config.js` on next reload (force-refresh if cached).
- The form's `/dev` Apps Script URL only authenticates the script owner. Use
  the same Google account in your browser as the one that deployed the
  script, or you'll see CORS / 401 errors on submit.

## Deploying to Hostinger

The site lives in `public_html/`. The contents of that folder go into
Hostinger's web root.

### Option A — File Manager (simplest)

1. hPanel → Files → File Manager → enter your domain's `public_html/`.
2. Clear it (move any existing files to a backup folder).
3. Drag the **contents** of the local `public_html/` folder (not the folder
   itself) into the remote `public_html/`.
4. Site is live.

### Option B — SFTP / FTP

1. hPanel → Files → FTP Accounts to get credentials.
2. Use FileZilla, Cyberduck, `lftp`, or `rsync`.
3. Upload local `public_html/*` into remote `public_html/`.

### Option C — Git auto-deploy

1. Move site contents from `public_html/` to repo root (Hostinger expects
   files at the repo root for auto-deploy, not under `public_html/`).
2. `git init`, commit, push to GitHub/GitLab.
3. hPanel → Websites → Git → connect the repo.
4. Hostinger pulls and deploys on every push.

## Configuring the form backend

The lead-capture forms POST to `/api/lead.php` (same-origin), which writes
the submission to a Hostinger MySQL queue and forwards it to a Google
Apps Script Web App in the background. Two pieces of config:

| Where        | Holds                                              |
|--------------|----------------------------------------------------|
| `.env`       | DB creds, Apps Script `/exec` URL, admin token     |
| `public_html/api/secrets.php` | Generated from `.env` by a build script |

Both are gitignored. Only `secrets.php` lives on the server.

### First-time setup

```bash
cp .env.example .env
# Edit .env — fill in DB_*, APPS_SCRIPT_URL, ADMIN_TOKEN.
./scripts/build-secrets.sh
```

This writes `public_html/api/secrets.php` (chmod 600). Upload it with the
rest of `public_html/` on your next deploy. Then hit
`https://yourdomain.com/api/migrate.php?token=<ADMIN_TOKEN>` once to apply
the schema.

### Changing a value later

```bash
# 1. Edit .env
# 2. Regenerate
./scripts/build-secrets.sh
# 3. Re-upload public_html/api/secrets.php to the server
```

For PHP endpoint internals (lead.php / retry.php / migrate.php) and the
queue table, see [public_html/api/README.md](public_html/api/README.md). For
the Apps Script side (Code.gs, deploy steps, OAuth scopes, spam tests), see
[apps_script/README.md](apps_script/README.md).

## TODO

### Form submission backend (Google Apps Script + Drive + Sheets)

The "Schedule Your Service Call" form on the home and contact-us pages
currently renders correctly but submits nowhere. Submissions need to be
captured and stored.

Plan agreed during build:

- Per-submission Google Drive folder named
  `YYYY-MM-DD_HHMM_LastName_FirstName_ServiceType` containing:
  - `Submission.gdoc` — formatted form data (clean, printable, forwardable)
  - All uploaded photos as separate files
- Master Google Sheet "USE Leads Index" with one row per submission:
  - timestamp, first name, last name, phone, email, service type, city, zip,
    street address, description, photo count, folder link, doc link,
    image-in-cell preview of first photo, status
- Status column has dropdown: New / Contacted / Scheduled / Completed / No-show
- Field 11 (`form_fields[field_ffc5e7c]`) is a honeypot — silently drop any
  submission where it is non-empty (free spam protection)
- Optional email alert on each new submission

Open decisions before implementation:

- [ ] Confirm folder naming format
- [ ] Confirm Sheet column order
- [ ] Confirm Doc layout (text-only vs. inline photo thumbnails)
- [ ] Email alert recipient(s)?
- [ ] Which Google account owns the Sheet, Drive folder, and Apps Script?

Build tasks (waiting on the decisions above):

- [ ] Create master Sheet and parent Drive folder
- [ ] Write Apps Script web-app handler
- [ ] Deploy as Web App (Execute as: Me, Access: Anyone)
- [ ] Capture deployment URL for the next step

### Wire form action to the Apps Script endpoint

After the Apps Script is deployed:

- [ ] Find every `<form class="elementor-form" ...>` in `public_html/`
- [ ] Rewrite the `action` attribute to the Apps Script web-app URL
- [ ] Strip Elementor nonces / `_wpnonce` hidden inputs (no longer functional)
- [ ] Add a small JS shim that:
  - Intercepts submit
  - Resizes uploaded photos client-side to ≤1600 px before encoding
  - Base64-encodes the file inputs
  - POSTs as `application/x-www-form-urlencoded` (avoids CORS)
  - On 200, redirects to `/thank-you/`
- [ ] Keep the honeypot field visually hidden but submitted
- [ ] End-to-end test: real submission with a photo lands in the Sheet + Drive
- [ ] End-to-end test: honeypot-filled submission is silently dropped

### Other outstanding items

#### Dead links still needing direction

Header/footer (all 8 pages):

- [ ] **Privacy Policy** — page does not exist. Draft, link externally, or
      remove the link?
- [ ] **Terms of Use** — same options
- [ ] **"20% Off"** CTA button — destination?
- [ ] **"Save $75"** CTA button — destination?

Homepage:

- [ ] **"Learn More"** button — destination?

Plumbing page sub-nav (5 items, plumbing only):

- [ ] About Us / Appliance Brands / Reviews / Service Areas — pages don't
      exist. Build them, point at anchors, or remove?
- [ ] Contact Us — point at `/contact-us/`?

#### Content corrections

- [ ] Form label typo: "**Why** type of work do you need?" → "**What** type
      of work do you need?"
      (lives in `_elementor_data` for `elementor_library` post 565
      "001 Final Form" — same form is reused across all pages, single fix)

#### Optional polish

- [ ] `sitemap.xml` for the static site
- [ ] `robots.txt`
- [ ] Analytics (Google / Plausible / Fathom)
- [ ] Open Graph / Twitter Card meta where missing
- [ ] Lighthouse audit, target 95+
- [ ] Confirm WebP/AVIF where supported

## Re-exporting from WordPress

If you need to make changes (fix the typo, add a Privacy Policy page, swap
copy), the cleanest workflow is to re-export rather than hand-edit the
generated HTML.

Steps:

1. Extract the build environment:
   `tar -xzf rebuild-YYYYMMDD.tar.gz`
2. Bring up the Docker stack:
   `cd rebuild/local && docker compose up -d`
3. Local WP at http://localhost:8181 — edit content via wp-admin
4. Re-crawl:
   ```bash
   wget --mirror --page-requisites --convert-links --adjust-extension \
        --no-host-directories --no-parent \
        --reject "wp-admin*,wp-login*,xmlrpc*,wp-cron*,wp-trackback*,*.zip" \
        --exclude-directories="/wp-admin,/wp-json" \
        --execute robots=off \
        --directory-prefix=dist-wget \
        http://localhost:8181/
   ```
5. Post-process:
   `python3 post-process-static.py`
   (writes the cleaned site to `public_html/` at the project root)
6. Spot-check by serving locally:
   `cd public_html && python3 -m http.server 8282`
7. Tear down: `docker compose down`

## Recovery

Two independent safety nets:

| Archive | Restores to | When you'd use it |
|---|---|---|
| `wp-full-site-backup-04-23-26.zip` | Pristine WP backup from Kinsta (DB + filesystem) | "I need the original WordPress site back from scratch" |
| `rebuild-YYYYMMDD.tar.gz` | The full build environment we developed (Docker, scripts, edited WP) | "I just need to re-export the static site with my edits intact" |

Keep both until the new site has been live and stable for at least 30 days.

## Credits

Built with `wget` (mirror) and a Python post-processor against a Dockerized
WordPress + MariaDB stack restored from a Kinsta backup. No build toolchain,
no framework, no runtime dependencies — the deployable site is just files.
