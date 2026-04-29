<?php
// Copy this file to secrets.php on the server and fill in real values.
// secrets.php is gitignored. Apache always executes .php files, so direct
// browser access returns nothing — but the .htaccess in this folder also
// denies it as defense in depth.

return [
  'db_host'         => 'localhost',
  'db_name'         => '',
  'db_user'         => '',
  'db_pass'         => '',
  'apps_script_url' => 'https://script.google.com/macros/s/REPLACE_ME/exec',
  // Long random string used to authenticate /api/retry.php.
  // Generate with: php -r "echo bin2hex(random_bytes(24));"
  'admin_token'     => '',
];
