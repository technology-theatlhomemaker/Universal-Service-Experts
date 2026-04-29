<?php
// Reference for what secrets.php must return. Do NOT hand-edit secrets.php
// in production — generate it from .env with `scripts/build-secrets.sh`,
// which writes a chmod-600 file to `private/secrets.php` (a SIBLING of
// public_html/, above the web root). secrets.php is gitignored.

return [
  'db_host'         => 'localhost',
  'db_name'         => '',
  'db_user'         => '',
  'db_pass'         => '',
  'apps_script_url' => 'https://script.google.com/macros/s/REPLACE_ME/exec',
  // Long random string used to authenticate /api/retry.php and /api/migrate.php.
  // Generate with: php -r "echo bin2hex(random_bytes(24));"
  'admin_token'     => '',
];
