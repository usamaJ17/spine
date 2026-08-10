<?php
require_once __DIR__ . '/lib/bootstrap.php';

if (!db_installed()) {
    header('Location: setup.php');
    exit;
}
db_sync();
base_url();          // remembers the site address for cron.php, which has no request
if (handle_magic_link()) {
    exit;
}

$name = e((string) cfg('app_name'));
// Asset version from file mtimes, so browsers never serve a stale app.js.
$ver = (string) max(
    (int) @filemtime(__DIR__ . '/assets/app.css'),
    (int) @filemtime(__DIR__ . '/assets/app.js')
);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0a0b0e">
<meta name="referrer" content="no-referrer">
<title><?= $name ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= $ver ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='9' fill='%23ff9647'/><text x='16' y='23' font-size='19' text-anchor='middle' fill='%232b1500'>&#9670;</text></svg>">
</head>
<body>

<header class="topbar">
  <div class="topbar-in">
    <a class="brand" href="#/"><span class="brand-dot">◆</span><?= $name ?></a>
    <nav class="top-nav" id="topnav"></nav>
    <span class="top-spacer"></span>
    <a class="helpbtn" href="#/how" title="How this works" aria-label="How this works"><span class="q">?</span><span class="lbl">How it works</span></a>
    <span id="mechip"></span>
  </div>
</header>

<main class="wrap" id="app">
  <div class="skel"></div><div class="skel"></div><div class="skel"></div>
</main>

<nav class="tabbar" id="tabbar"></nav>
<datalist id="vocab"></datalist>

<noscript><p style="padding:24px">This app needs JavaScript.</p></noscript>
<script src="assets/app.js?v=<?= $ver ?>"></script>
</body>
</html>
