<?php
/** Single entry point for every request. */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');   // flip to '1' while debugging on your server

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/model.php';

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

/** Handle ?u=TOKEN magic links on any page. Returns true if it redirected. */
function handle_magic_link(): bool
{
    $token = isset($_GET['u']) ? trim((string) $_GET['u']) : '';
    if ($token === '') {
        return false;
    }
    $person = person_by_token($token);
    if ($person) {
        set_user_cookie($person);
    }
    $to = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    header('Location: ' . ($to ?: './') . ($person ? '' : '?bad_link=1'), true, 302);
    return true;
}
