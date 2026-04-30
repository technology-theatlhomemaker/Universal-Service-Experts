<?php
/** @var string|null $loginError */
$loginError = $loginError ?? null;
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Sign in — USE Admin</title>
  <link rel="stylesheet" href="assets/admin.css" />
</head>
<body class="login-body">
  <main class="login-card">
    <h1>USE Admin</h1>
    <p class="muted">Local-only admin for service-area data and page generation.</p>
    <form method="get" action="index.php" autocomplete="off">
      <label>
        <span>Admin token</span>
        <input type="password" name="token" required autofocus />
      </label>
      <button type="submit" class="btn-primary">Sign in</button>
    </form>
    <p class="muted small">Token is in <code>private/secrets.php</code> as <code>admin_token</code>.</p>
  </main>
</body>
</html>
