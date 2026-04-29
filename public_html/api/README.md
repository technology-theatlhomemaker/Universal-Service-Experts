# /api — lead-capture queue

PHP endpoint that accepts form submissions, writes them to a Hostinger
MySQL table, returns instantly to the user, and then forwards the row to
the Apps Script Web App in the background after the response is closed.

## Why this exists

The form used to POST directly to Apps Script `/exec`. With photos, the
user waited 5–15 seconds while Apps Script created folders and uploaded
files to Drive — long enough to hurt retention. Now:

```
browser → /api/lead.php → MySQL row + 200 OK → /thank-you/
                       └── (background) → Apps Script /exec → Drive + Sheet
```

`fastcgi_finish_request()` (PHP-FPM / LiteSpeed) closes the connection
after the INSERT, so the Apps Script call doesn't block the response.

## Files

| File                  | Purpose                                                             |
|-----------------------|---------------------------------------------------------------------|
| `lead.php`            | Public POST endpoint the form submits to                            |
| `retry.php`           | Admin manual retry: `GET /api/retry.php?token=<admin_token>`        |
| `push.php`            | Shared curl-and-update helper used by both                          |
| `db.php`              | PDO + config helpers                                                |
| `secrets.example.php` | Template — copy to `secrets.php` on the server                      |
| `schema.sql`          | One table: `leads` (write-ahead log + retry buffer)                 |
| `.htaccess`           | Denies direct access to non-entrypoint files                        |

## First-time deploy

1. **Database.** hPanel → **Databases → MySQL Databases** → create one. Note
   the host, db name, user, password.
2. **Schema.** Open phpMyAdmin (or any MySQL client) and run
   [schema.sql](./schema.sql) against the new DB.
3. **Secrets.** SFTP/SSH to the server and copy
   `secrets.example.php` → `secrets.php` in this folder. Fill in:
   - DB credentials from step 1
   - `apps_script_url` — your Apps Script `/exec` URL
   - `admin_token` — generate with
     `php -r 'echo bin2hex(random_bytes(24));'`

   **Never commit `secrets.php`.** It is gitignored.
4. **Smoke test.** From your laptop:
   ```bash
   curl -X POST https://yourdomain.com/api/lead.php \
     -H 'Content-Type: text/plain' \
     -d '{"form_fields[name]":"Test","form_fields[field_6817b28]":"t@example.com","_form_age_ms":5000}'
   ```
   Expect `{"ok":true,"redirectUrl":"\/thank-you\/"}` within ~300ms. Check
   the `leads` table — there should be one new row, and within ~30s its
   `status` should flip from `pending` to `sent`.

## Operations

- **See pending leads:** `SELECT id, created_at, attempts, last_error FROM leads WHERE status='pending';`
- **Force a retry of all pending:** `GET /api/retry.php?token=<admin_token>`
- **Permanently failed leads** (5 attempts hit) end up in
  `status='failed'` — inspect `last_error`, fix root cause, then either
  reset (`UPDATE leads SET status='pending', attempts=0 WHERE id=...`) and
  hit retry, or replay manually.
- **Housekeeping:** old `sent` rows can be pruned periodically:
  `DELETE FROM leads WHERE status='sent' AND sent_at < (NOW() - INTERVAL 30 DAY);`

## Spam protection

Same three layers as before — moved server-side into `lead.php`:
1. Honeypot field non-empty → silent `{ok:true}`, no row written.
2. `_form_age_ms < 3000` → silent `{ok:true}`, no row written.
3. Missing `first_name` or `email` → 400 with explicit error.
