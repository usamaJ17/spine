<?php
/**
 * Daily housekeeping. Point Hostinger's cron at this once a day.
 *
 *   Over HTTP (needs CRON_KEY in .env):
 *     curl -s "https://yourdomain.com/cron.php?key=YOUR_CRON_KEY"
 *
 *   Or straight from PHP, which needs no key because it is not a web request:
 *     /usr/bin/php /home/uXXXXXXXX/public_html/cron.php
 *
 * What it does:
 *   - closes anything that has held the floor for its full run and hands the
 *     floor back, so the next person can post
 *   - three days later, emails everyone the question and every answer
 *
 * Both jobs are also run lazily whenever someone opens the app, so a missing
 * or broken cron only delays them — it never stalls the circle.
 */

require_once __DIR__ . '/lib/bootstrap.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    $key      = (string) env('CRON_KEY', '');
    $supplied = (string) ($_GET['key'] ?? '');
    if ($key === '' || !hash_equals($key, $supplied)) {
        http_response_code(404);
        exit;                       // give a passer-by nothing to work with
    }
    header('Content-Type: application/json; charset=utf-8');
}

if (!db_installed()) {
    echo $isCli ? "Not installed.\n" : json_encode(['ok' => false, 'error' => 'not installed']);
    exit(1);
}
db_sync();

$result = run_round_schedule();

$summary = [
    'ok'       => true,
    'at'       => now(),
    'closed'   => $result['closed'],
    'digested' => $result['digested'],
];

if ($isCli) {
    printf("[%s] closed %d, digested %d\n", now(), count($result['closed']), count($result['digested']));
    foreach ($result['closed'] as $c) {
        echo "  closed:   {$c['title']}\n";
    }
    foreach ($result['digested'] as $d) {
        echo "  digested: {$d['title']} -> {$d['mailed']} emails\n";
    }
} else {
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
