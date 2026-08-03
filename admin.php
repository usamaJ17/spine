<?php
/** Admin: people, magic links, device resets, password. */

require_once __DIR__ . '/lib/bootstrap.php';

if (!db_installed()) {
    header('Location: setup.php');
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
    $hash = setting('admin_hash', '');
    if ($hash && password_verify((string) $_POST['password'], $hash)) {
        admin_login();
        header('Location: admin.php');
        exit;
    }
    usleep(600000);            // slow down guessing
    $error = 'Wrong password.';
}

if (isset($_GET['logout'])) {
    admin_logout();
    header('Location: admin.php');
    exit;
}

$in = admin_logged_in();
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0a0b0e">
<title>Admin · <?= e((string) cfg('app_name')) ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= (int) @filemtime(__DIR__ . '/assets/app.css') ?>">
</head><body>

<header class="topbar"><div class="topbar-in">
  <a class="brand" href="index.php"><span class="brand-dot">◆</span><?= e((string) cfg('app_name')) ?></a>
  <span class="faint tiny" style="margin-left:6px">admin</span>
  <span class="top-spacer"></span>
  <?php if ($in): ?><a class="btn sm ghost" href="?logout=1">Sign out</a><?php endif; ?>
</div></header>

<main class="wrap" style="max-width:760px" id="app">
<?php if (!$in): ?>

  <div style="max-width:380px;margin:12vh auto 0">
    <h1 style="font-size:26px">Admin</h1>
    <p class="muted mt8 small">Manage people and their links.</p>
    <?php if ($error): ?><p class="small mt14" style="color:#fca5a5"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="card mt14">
      <label class="lbl">Password</label>
      <input class="field" type="password" name="password" autofocus required>
      <button class="btn primary full mt14">Sign in</button>
    </form>
  </div>

<?php else: ?>
  <div class="skel"></div><div class="skel"></div>
<?php endif; ?>
</main>

<?php if ($in): ?><script src="assets/admin.js?v=<?= (int) @filemtime(__DIR__ . '/assets/admin.js') ?>"></script><?php endif; ?>
</body></html>
