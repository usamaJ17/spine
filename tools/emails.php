<?php
/**
 * Assign email addresses to people from the command line.
 *
 *   php tools/emails.php list
 *   php tools/emails.php import addresses.txt        # preview only
 *   php tools/emails.php import addresses.txt --apply
 *   php tools/emails.php import --apply < addresses.txt
 *   php tools/emails.php set "Usama Jalal" usama@example.com
 *   php tools/emails.php clear "Usama Jalal"
 *
 * The input can be a To: line copied straight out of an email client —
 * "Name <a@b.com>, c@d.com" — one per line, or "Name = a@b.com".
 *
 * Nothing is written until you pass --apply.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/emails.php';

if (!db_installed()) {
    fwrite(STDERR, "Not installed yet — run setup.php first.\n");
    exit(1);
}
db_sync();

$argvv = array_slice($argv, 1);
$apply = in_array('--apply', $argvv, true);
$argvv = array_values(array_filter($argvv, fn($a) => $a !== '--apply'));
$cmd   = $argvv[0] ?? 'list';

const C_OFF = "\033[0m", C_DIM = "\033[2m", C_GRN = "\033[32m",
      C_YEL = "\033[33m", C_RED = "\033[31m", C_BLD = "\033[1m";

function line(string $s = ''): void
{
    fwrite(STDOUT, $s . "\n");
}

switch ($cmd) {

    case 'list': {
        $rows = db()->query('SELECT id, name, email, notify FROM people WHERE active = 1
                             ORDER BY sort_order, name')->fetchAll();
        line();
        line(C_BLD . 'Addresses on file' . C_OFF);
        line();
        foreach ($rows as $r) {
            $addr = $r['email'] !== '' ? $r['email'] : C_DIM . '— none —' . C_OFF;
            $mute = (int) $r['notify'] === 1 ? '' : C_YEL . '  (muted)' . C_OFF;
            printf("  %-22s %s%s\n", $r['name'], $addr, $mute);
        }
        $have = count(array_filter($rows, fn($r) => $r['email'] !== ''));
        line();
        line(C_DIM . "  $have of " . count($rows) . " have an address" . C_OFF);
        line();
        break;
    }

    case 'import': {
        $file = $argvv[1] ?? null;
        if ($file !== null && !is_file($file)) {
            fwrite(STDERR, "No such file: $file\n");
            exit(1);
        }
        $raw = $file !== null ? (string) file_get_contents($file) : (string) stream_get_contents(STDIN);
        if (trim($raw) === '') {
            fwrite(STDERR, "Nothing to read. Pass a file, or pipe the list in on stdin.\n");
            exit(1);
        }

        $plan = plan_email_import($raw);

        line();
        if ($plan['matched']) {
            line(C_BLD . 'Will set' . C_OFF);
            foreach ($plan['matched'] as $m) {
                $note = $m['current'] === $m['email']
                    ? C_DIM . ' (unchanged)' . C_OFF
                    : ($m['current'] !== '' ? C_YEL . ' (replaces ' . $m['current'] . ')' . C_OFF : '');
                printf("  %s%-22s%s %s%s\n", C_GRN, $m['name'], C_OFF, $m['email'], $note);
            }
            line();
        }
        foreach ([
            'ambiguous' => [C_YEL, 'Too close to call — set these by hand'],
            'unmatched' => [C_RED, 'No matching person in the circle'],
        ] as $key => [$colour, $title]) {
            if (!$plan[$key]) {
                continue;
            }
            line($colour . C_BLD . $title . C_OFF);
            foreach ($plan[$key] as $row) {
                $extra = isset($row['candidates']) ? C_DIM . '  could be: ' . implode(' / ', $row['candidates']) . C_OFF : '';
                printf("  %-40s%s\n", $row['email'] . ($row['name'] !== '' ? " ({$row['name']})" : ''), $extra);
            }
            line();
        }
        if ($plan['missing']) {
            line(C_DIM . 'Still without an address: ' . implode(', ', $plan['missing']) . C_OFF);
            line();
        }

        if (!$apply) {
            line(C_DIM . 'Preview only. Re-run with --apply to write these.' . C_OFF);
            line();
            break;
        }
        $n = apply_email_import($plan['matched']);
        line(C_GRN . C_BLD . "Updated $n " . ($n === 1 ? 'person' : 'people') . '.' . C_OFF);
        line();
        break;
    }

    case 'set': {
        $name  = $argvv[1] ?? '';
        $email = $argvv[2] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fwrite(STDERR, "Usage: php tools/emails.php set \"Full Name\" someone@example.com --apply\n");
            exit(1);
        }
        $st = db()->prepare('SELECT id, name FROM people WHERE active = 1 AND LOWER(name) = LOWER(?)');
        $st->execute([$name]);
        $p = $st->fetch();
        if (!$p) {
            fwrite(STDERR, "No person called \"$name\".\n");
            exit(1);
        }
        if (!$apply) {
            line("Would set {$p['name']} -> $email");
            line(C_DIM . 'Re-run with --apply to write it.' . C_OFF);
            break;
        }
        db()->prepare('UPDATE people SET email = ? WHERE id = ?')->execute([$email, (int) $p['id']]);
        line(C_GRN . "{$p['name']} -> $email" . C_OFF);
        break;
    }

    case 'clear': {
        $name = $argvv[1] ?? '';
        $st   = db()->prepare('SELECT id, name FROM people WHERE active = 1 AND LOWER(name) = LOWER(?)');
        $st->execute([$name]);
        $p = $st->fetch();
        if (!$p) {
            fwrite(STDERR, "No person called \"$name\".\n");
            exit(1);
        }
        if (!$apply) {
            line("Would clear the address for {$p['name']}");
            break;
        }
        db()->prepare("UPDATE people SET email = '' WHERE id = ?")->execute([(int) $p['id']]);
        line("Cleared {$p['name']}");
        break;
    }

    default:
        line('Usage:');
        line('  php tools/emails.php list');
        line('  php tools/emails.php import <file> [--apply]');
        line('  php tools/emails.php set "Full Name" a@b.com --apply');
        line('  php tools/emails.php clear "Full Name" --apply');
        exit(1);
}
