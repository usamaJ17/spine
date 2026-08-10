<?php
/**
 * Bulk-assigning email addresses to people.
 *
 * Shared by the CLI (tools/emails.php) and the admin console, so both use
 * exactly the same parsing and matching rules.
 *
 * Nothing here ever writes an address to a file — addresses live in the
 * database only, because every file in this project is tracked by git.
 */

/**
 * Pull "Name <email>" pairs and bare addresses out of pasted text.
 * Handles the shape you get from copying a To: line out of an email client.
 */
function parse_email_list(string $raw): array
{
    $out = [];

    // "Display Name <someone@example.com>"
    if (preg_match_all('/([^,;<>\n]*?)\s*<\s*([^\s<>@,;]+@[^\s<>@,;]+)\s*>/u', $raw, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $out[] = ['name' => trim($hit[1]), 'email' => strtolower(trim($hit[2]))];
        }
        $raw = preg_replace('/([^,;<>\n]*?)\s*<\s*([^\s<>@,;]+@[^\s<>@,;]+)\s*>/u', ' ', $raw) ?? $raw;
    }

    // "Name = email" or "Name: email"
    if (preg_match_all('/^\s*([^=:\n]+?)\s*[=:]\s*([^\s,;]+@[^\s,;]+)\s*$/mu', $raw, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $out[] = ['name' => trim($hit[1]), 'email' => strtolower(trim($hit[2]))];
        }
        $raw = preg_replace('/^\s*([^=:\n]+?)\s*[=:]\s*([^\s,;]+@[^\s,;]+)\s*$/mu', ' ', $raw) ?? $raw;
    }

    // Whatever addresses are left over, with no name attached.
    if (preg_match_all('/[^\s,;<>]+@[^\s,;<>]+\.[A-Za-z]{2,}/u', $raw, $m)) {
        foreach ($m[0] as $addr) {
            $out[] = ['name' => '', 'email' => strtolower(rtrim(trim($addr), '.,;'))];
        }
    }

    // De-duplicate on the address, preferring an entry that carries a name.
    $byAddr = [];
    foreach ($out as $row) {
        if (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        if (!isset($byAddr[$row['email']]) || ($row['name'] !== '' && $byAddr[$row['email']]['name'] === '')) {
            $byAddr[$row['email']] = $row;
        }
    }
    return array_values($byAddr);
}

/** Lowercase alphabetic tokens of a name: "Hafiz Talha Jalal" -> [hafiz,talha,jalal] */
function name_tokens(string $name): array
{
    $clean = preg_replace('/[^a-z\s]/', ' ', mb_strtolower($name, 'UTF-8')) ?? '';
    return array_values(array_filter(preg_split('/\s+/', trim($clean)) ?: [], fn($t) => strlen($t) > 1));
}

/**
 * Score how well one address belongs to one person.
 * A supplied display name counts for much more than a guess from the
 * local-part of the address.
 */
function email_match_score(array $person, array $entry): int
{
    $personTokens = name_tokens($person['name']);
    if (!$personTokens) {
        return 0;
    }

    $score = 0;

    if ($entry['name'] !== '') {
        $given = name_tokens($entry['name']);
        foreach ($personTokens as $t) {
            if (in_array($t, $given, true)) {
                $score += 10;
            }
        }
    }

    // Guess from the local part: "ghafoorilyas1" contains ghafoor and ilyas.
    $local = strtolower((string) strstr($entry['email'], '@', true));
    $local = preg_replace('/[^a-z]/', '', $local) ?? '';
    if ($local !== '') {
        foreach ($personTokens as $t) {
            if (strlen($t) >= 4 && str_contains($local, $t)) {
                $score += 3;
            }
        }
    }
    return $score;
}

/**
 * Work out which address belongs to whom.
 * Returns matched / unmatched / ambiguous, and never guesses when two people
 * score the same — a wrongly assigned address emails the wrong person.
 */
function plan_email_import(string $raw): array
{
    $entries = parse_email_list($raw);
    $people  = db()->query('SELECT id, name, email FROM people WHERE active = 1 ORDER BY sort_order, name')
                   ->fetchAll();

    $matched = $unmatched = $ambiguous = [];
    $claimed = [];

    foreach ($entries as $entry) {
        $scores = [];
        foreach ($people as $p) {
            $s = email_match_score($p, $entry);
            if ($s > 0) {
                $scores[] = ['person' => $p, 'score' => $s];
            }
        }
        usort($scores, fn($a, $b) => $b['score'] <=> $a['score']);

        if (!$scores) {
            $unmatched[] = $entry;
            continue;
        }
        if (count($scores) > 1 && $scores[0]['score'] === $scores[1]['score']) {
            $ambiguous[] = $entry + ['candidates' => [$scores[0]['person']['name'], $scores[1]['person']['name']]];
            continue;
        }

        $winner = $scores[0]['person'];
        if (isset($claimed[$winner['id']])) {
            $ambiguous[] = $entry + ['candidates' => [$winner['name'] . ' (already taken by ' . $claimed[$winner['id']] . ')']];
            continue;
        }
        $claimed[$winner['id']] = $entry['email'];

        $matched[] = [
            'id'      => (int) $winner['id'],
            'name'    => $winner['name'],
            'email'   => $entry['email'],
            'current' => (string) ($winner['email'] ?? ''),
            'score'   => $scores[0]['score'],
        ];
    }

    // People in the circle who still end up with no address.
    $withAddr = array_column($matched, 'id');
    $missing  = [];
    foreach ($people as $p) {
        if (!in_array((int) $p['id'], $withAddr, true) && ($p['email'] ?? '') === '') {
            $missing[] = $p['name'];
        }
    }

    return compact('matched', 'unmatched', 'ambiguous', 'missing');
}

/** Write the matched addresses. Returns how many rows actually changed. */
function apply_email_import(array $matched): int
{
    $st = db()->prepare('UPDATE people SET email = ? WHERE id = ?');
    $n  = 0;
    foreach ($matched as $row) {
        if (($row['current'] ?? '') === $row['email']) {
            continue;                       // already correct, leave it alone
        }
        $st->execute([$row['email'], (int) $row['id']]);
        $n++;
    }
    return $n;
}
