<?php
/**
 * One-time installer. Creates the schema, seeds the circle, sets the admin
 * password, then locks itself. Delete this file afterwards if you like.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/seed.php';

$lock = __DIR__ . '/data/.installed';

// Locked by the marker file, and independently by the database already having
// people in it — so deleting data/.installed cannot reopen the installer and
// let a passer-by set their own admin password.
$done = is_file($lock);
if (!$done) {
    try {
        $done = db_installed()
            && (int) db()->query('SELECT COUNT(*) FROM people')->fetchColumn() > 0;
    } catch (Throwable $e) {
        $done = false;
    }
}

$justNow  = false;          // only the person who ran the install sees the links
$error    = '';
$links    = [];

if (!$done && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $pw  = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['password2'] ?? '');
    if (strlen($pw) < 6) {
        $error = 'Admin password needs at least 6 characters.';
    } elseif ($pw !== $pw2) {
        $error = 'The two passwords do not match.';
    } else {
        try {
            db_migrate();
            $existing = (int) db()->query('SELECT COUNT(*) FROM people')->fetchColumn();
            if ($existing === 0 && empty($_POST['skip_seed'])) {
                run_seed();
            }
            set_setting('admin_hash', password_hash($pw, PASSWORD_DEFAULT));
            set_setting('installed_at', now());
            file_put_contents($lock, now());
            admin_login();                 // so the finish screen is yours alone
            $done = $justNow = true;
        } catch (Throwable $ex) {
            $error = $ex->getMessage();
        }
    }
}

// Private links are shown only right after installing, or to a signed-in
// admin. Otherwise anyone hitting setup.php could sign in as anybody.
$maySeeLinks = $done && ($justNow || admin_logged_in());
if ($maySeeLinks && db_installed()) {
    $links = db()->query('SELECT * FROM people WHERE active = 1 ORDER BY sort_order')->fetchAll();
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Set up <?= e((string) cfg('app_name')) ?></title>
<link rel="stylesheet" href="assets/app.css">
</head><body>
<main class="wrap" style="max-width:640px;padding-top:36px">

<div class="brand" style="margin-bottom:20px"><span class="brand-dot">◆</span><?= e((string) cfg('app_name')) ?></div>

<?php if (!$done): ?>

  <h1 style="font-size:27px">Set up your circle</h1>
  <p class="muted mt8">This creates the database, fills in the profiles from your
     25 July session notes, and sets your admin password.</p>

  <?php if ($error): ?>
    <div class="card mt14" style="border-color:rgba(248,113,113,.4)">
      <b style="color:#fca5a5">Could not install</b>
      <p class="small muted mt8"><?= e($error) ?></p>
      <p class="tiny faint mt8">Using SQLite? Make sure the <code>data/</code> folder is writable.
         No <code>pdo_sqlite</code> on this host? Switch to MySQL in <code>lib/config.php</code>.</p>
    </div>
  <?php endif; ?>

  <div class="card mt20">
    <form method="post">
      <label class="lbl">Admin password</label>
      <input class="field" type="password" name="password" autofocus required minlength="6"
             placeholder="You will need this for /admin.php">
      <label class="lbl mt14">Again</label>
      <input class="field" type="password" name="password2" required minlength="6">
      <label class="row gap10 mt14 small muted" style="cursor:pointer">
        <input type="checkbox" name="skip_seed" value="1"> Start completely empty (no seeded people)
      </label>
      <button class="btn primary full mt20">Install</button>
    </form>
  </div>

  <?php $dbPath = cfg('db')['sqlite_path']; ?>
  <div class="card mt14">
    <div class="tiny faint">Driver <b style="color:var(--txt)"><?= e(db_driver()) ?></b><?php
      if (db_driver() === 'sqlite'): ?> · <code><?= e($dbPath) ?></code><?php endif; ?></div>
    <p class="tiny faint mt8">Configure everything in <code>.env</code> (copy <code>.env.example</code>),
       not in <code>lib/config.php</code>. After deploying, open <code>/admin.php</code> and
       check for a red warning about the database being downloadable — if you see one, this
       host is ignoring <code>.htaccess</code>.</p>
  </div>

<?php elseif (!$maySeeLinks): ?>

  <h1 style="font-size:27px">Already installed.</h1>
  <p class="muted mt8">Nothing to do here. Delete <code>setup.php</code> from the server —
     everything else lives in the admin console.</p>
  <div class="row gap10 mt20">
    <a class="btn primary grow center" href="index.php">Open the app</a>
    <a class="btn grow center" href="admin.php">Admin</a>
  </div>

<?php else: ?>

  <h1 style="font-size:27px">Ready.</h1>
  <p class="muted mt8">Send each person their own link privately — one tap and that
     device is signed in as them, forever, with no password.</p>

  <div class="card mt20">
    <?php foreach ($links as $p): $link = magic_link($p); ?>
      <div class="fitem">
        <div class="av sm" style="--h:<?= avatar_hue($p['name']) ?>"><?= e($p['emoji'] ?: mb_substr($p['name'], 0, 1)) ?></div>
        <div class="grow" style="min-width:0">
          <div class="txt"><b><?= e($p['name']) ?></b><?= (int) $p['is_admin'] ? ' <span class="faint tiny">admin</span>' : '' ?></div>
          <div class="linkbox" style="margin-top:6px"><code><?= e($link) ?></code></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="nudge mt20">
    <span style="font-size:20px">🔒</span>
    <div class="grow"><div class="t">Delete <code>setup.php</code> now</div>
    <div class="s">It is locked, but removing it is cleaner. You can regenerate any link from /admin.php.</div></div>
  </div>

  <div class="row gap10 mt20">
    <a class="btn primary grow center" href="index.php">Open the app</a>
    <a class="btn grow center" href="admin.php">Admin</a>
  </div>

<?php endif; ?>

</main></body></html>
