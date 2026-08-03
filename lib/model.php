<?php
/**
 * Domain queries. Everything the API returns is shaped here so the
 * front-end never has to know about the schema.
 */

function kind_meta(): array
{
    return [
        'good_at'     => ['label' => "Good at this",      'verb' => 'can teach',   'hint' => 'Things you could teach or help someone with.'],
        'curious'     => ['label' => "Curious about",     'verb' => 'wants to learn', 'hint' => 'Things you want to learn, explore or understand.'],
        'building'    => ['label' => "Working on",        'verb' => 'is building', 'hint' => 'What you are actually building or pushing right now.'],
        'life'        => ['label' => "Outside of work",   'verb' => 'enjoys',      'hint' => 'Books, sport, faith, poetry, food — the human part.'],
        'seen_in_you' => ['label' => "What others see",   'verb' => 'is known for', 'hint' => 'Added by other people. You cannot edit this one.'],
    ];
}

function person_public(array $p): array
{
    return [
        'id'       => (int) $p['id'],
        'name'     => $p['name'],
        'slug'     => $p['slug'],
        'emoji'    => $p['emoji'],
        'headline' => $p['headline'],
        'city'     => $p['city'],
        'hue'      => avatar_hue($p['name']),
        'is_admin' => (int) $p['is_admin'] === 1,
    ];
}

function all_people(): array
{
    $rows = db()->query(
        'SELECT * FROM people WHERE active = 1 ORDER BY sort_order ASC, name ASC'
    )->fetchAll();
    return array_map('person_public', $rows);
}

function find_person(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM people WHERE id = ? AND active = 1');
    $st->execute([$id]);
    $p = $st->fetch();
    return $p ?: null;
}

/** Tag rows for one person, grouped by kind. Cached per request — the
 *  matching pass asks for the same profiles many times over. */
function person_tags(int $personId, bool $fresh = false): array
{
    static $memo = [];
    if (!$fresh && isset($memo[$personId])) {
        return $memo[$personId];
    }
    $st = db()->prepare(
        'SELECT pt.id, pt.kind, pt.note, pt.added_by, pt.created_at,
                t.id AS tag_id, t.label, t.canon,
                p.name AS added_by_name
           FROM person_tags pt
           JOIN tags t   ON t.id = pt.tag_id
      LEFT JOIN people p ON p.id = pt.added_by
          WHERE pt.person_id = ?
       ORDER BY pt.created_at ASC, pt.id ASC'
    );
    $st->execute([$personId]);

    $out = array_fill_keys(TAG_KINDS, []);
    foreach ($st->fetchAll() as $r) {
        $kind = in_array($r['kind'], TAG_KINDS, true) ? $r['kind'] : 'good_at';
        $out[$kind][] = [
            'id'       => (int) $r['id'],
            'tag_id'   => (int) $r['tag_id'],
            'label'    => $r['label'],
            'note'     => $r['note'],
            'added_by' => (int) $r['added_by'],
            'by_name'  => $r['added_by_name'],
        ];
    }
    return $memo[$personId] = $out;
}

function person_projects(int $personId): array
{
    $st = db()->prepare('SELECT * FROM projects WHERE person_id = ? ORDER BY id DESC');
    $st->execute([$personId]);
    return array_map(function ($r) {
        return [
            'id'      => (int) $r['id'],
            'title'   => $r['title'],
            'blurb'   => $r['blurb'],
            'kind'    => $r['kind'],
            'looking' => $r['looking'],
            'person_id' => (int) $r['person_id'],
        ];
    }, $st->fetchAll());
}

function all_projects(): array
{
    $rows = db()->query(
        'SELECT pr.*, p.name, p.emoji
           FROM projects pr
           JOIN people p ON p.id = pr.person_id
          WHERE p.active = 1
       ORDER BY pr.id DESC'
    )->fetchAll();
    return array_map(function ($r) {
        return [
            'id'        => (int) $r['id'],
            'title'     => $r['title'],
            'blurb'     => $r['blurb'],
            'kind'      => $r['kind'],
            'looking'   => $r['looking'],
            'person_id' => (int) $r['person_id'],
            'owner'     => $r['name'],
            'emoji'     => $r['emoji'],
            'hue'       => avatar_hue($r['name']),
        ];
    }, $rows);
}

/* --------------------------------------------------------------- tags */

function tag_id_for(string $label): int
{
    $label = clamp_str($label, 80);
    $c     = canon($label);
    if ($c === '') {
        throw new RuntimeException('Empty tag');
    }
    $st = db()->prepare('SELECT id FROM tags WHERE canon = ?');
    $st->execute([$c]);
    $row = $st->fetch();
    if ($row) {
        return (int) $row['id'];
    }
    $ins = db()->prepare('INSERT INTO tags (label, canon) VALUES (?, ?)');
    $ins->execute([$label, $c]);
    return (int) db()->lastInsertId();
}

/** Every label in use, for the autocomplete datalist. */
function tag_vocabulary(): array
{
    $rows = db()->query(
        'SELECT t.label, COUNT(*) AS n
           FROM person_tags pt JOIN tags t ON t.id = pt.tag_id
       GROUP BY t.id, t.label ORDER BY n DESC, t.label ASC LIMIT 300'
    )->fetchAll();
    return array_column($rows, 'label');
}

/* ------------------------------------------------------------ matching */

/**
 * The heart of the app: why these two people should talk.
 * Returns ordered buckets, strongest connective tissue first.
 */
function overlaps(int $meId, int $themId): array
{
    if ($meId === $themId) {
        return [];
    }
    $mine   = person_tags($meId);
    $theirs = person_tags($themId);

    $byTag = function (array $list) {
        $m = [];
        foreach ($list as $t) {
            $m[$t['tag_id']] = $t;
        }
        return $m;
    };

    // Peer-added traits count as things a person is known for.
    $mineGood   = $byTag(array_merge($mine['good_at'], $mine['seen_in_you']));
    $theirsGood = $byTag(array_merge($theirs['good_at'], $theirs['seen_in_you']));

    $rules = [
        ['key' => 'they_teach', 'a' => $theirsGood, 'b' => $byTag($mine['curious']),
         'title' => 'They can teach you', 'lead' => 'Ask them about'],
        ['key' => 'you_teach',  'a' => $byTag($mine['good_at']), 'b' => $byTag($theirs['curious']),
         'title' => 'You can help them', 'lead' => 'Offer to walk them through'],
        ['key' => 'help_build', 'a' => $byTag($theirs['building']), 'b' => $mineGood,
         'title' => 'You could help what they are building', 'lead' => 'You know'],
        ['key' => 'they_help',  'a' => $byTag($mine['building']), 'b' => $theirsGood,
         'title' => 'They could help what you are building', 'lead' => 'They know'],
        ['key' => 'same_craft', 'a' => $byTag($mine['good_at']), 'b' => $byTag($theirs['good_at']),
         'title' => 'You both do this', 'lead' => 'Compare notes on'],
        ['key' => 'explore',    'a' => $byTag($mine['curious']), 'b' => $byTag($theirs['curious']),
         'title' => 'Neither of you knows this yet', 'lead' => 'Figure out together'],
        ['key' => 'human',      'a' => $byTag($mine['life']), 'b' => $byTag($theirs['life']),
         'title' => 'Common ground', 'lead' => 'You both like'],
    ];

    $out  = [];
    $seen = [];
    foreach ($rules as $r) {
        $hits = [];
        foreach (array_intersect_key($r['a'], $r['b']) as $tagId => $t) {
            if (isset($seen[$tagId])) {
                continue;
            }
            $seen[$tagId] = true;
            $hits[] = ['tag_id' => (int) $tagId, 'label' => $t['label']];
        }
        if ($hits) {
            $out[] = ['key' => $r['key'], 'title' => $r['title'], 'lead' => $r['lead'], 'tags' => $hits];
        }
    }
    return $out;
}

/**
 * Per-person summary used by the people grid and the home screen:
 * how much you two share, plus a couple of examples and the strongest reason.
 */
function overlap_summary(int $meId): array
{
    $out = [];
    foreach (all_people() as $p) {
        if ($p['id'] === $meId) {
            continue;
        }
        $buckets = overlaps($meId, $p['id']);
        $n = 0;
        foreach ($buckets as $b) {
            $n += count($b['tags']);
        }
        $out[$p['id']] = [
            'n'      => $n,
            'why'    => $buckets ? $buckets[0]['title'] : '',
            'sample' => $buckets ? array_slice(array_column($buckets[0]['tags'], 'label'), 0, 2) : [],
            'top'    => $buckets ? $buckets[0]['tags'][0] : null,
        ];
    }
    return $out;
}

/* ------------------------------------------------------------- sparks */

function spark_rows(?int $onlyPerson = null, ?string $status = null, int $limit = 200): array
{
    $sql = 'SELECT s.*, a.name AS a_name, a.emoji AS a_emoji, b.name AS b_name, b.emoji AS b_emoji
              FROM sparks s
              JOIN people a ON a.id = s.a_id
              JOIN people b ON b.id = s.b_id
             WHERE 1 = 1';
    $args = [];
    if ($onlyPerson) {
        $sql   .= ' AND (s.a_id = ? OR s.b_id = ?)';
        $args[] = $onlyPerson;
        $args[] = $onlyPerson;
    }
    if ($status) {
        $sql   .= ' AND s.status = ?';
        $args[] = $status;
    }
    $sql .= ' ORDER BY CASE s.status WHEN \'open\' THEN 0 WHEN \'scheduled\' THEN 1 ELSE 2 END,
                       s.updated_at DESC LIMIT ' . (int) $limit;

    $st = db()->prepare($sql);
    $st->execute($args);

    return array_map(function ($r) {
        return [
            'id'        => (int) $r['id'],
            'a'         => ['id' => (int) $r['a_id'], 'name' => $r['a_name'], 'emoji' => $r['a_emoji'], 'hue' => avatar_hue($r['a_name'])],
            'b'         => ['id' => (int) $r['b_id'], 'name' => $r['b_name'], 'emoji' => $r['b_emoji'], 'hue' => avatar_hue($r['b_name'])],
            'initiator' => (int) $r['initiator_id'],
            'kind'      => $r['kind'],
            'topic'     => $r['topic'],
            'message'   => $r['message'],
            'status'    => $r['status'],
            'outcome'   => $r['outcome'],
            'created'   => $r['created_at'],
            'updated'   => $r['updated_at'],
        ];
    }, $st->fetchAll());
}

/* --------------------------------------------------------------- feed */

function feed(int $limit = 40): array
{
    $st = db()->prepare(
        'SELECT ac.*, a.name AS actor_name, a.emoji AS actor_emoji,
                t.name AS target_name, t.emoji AS target_emoji
           FROM activity ac
           JOIN people a  ON a.id = ac.actor_id
      LEFT JOIN people t  ON t.id = ac.target_id
       ORDER BY ac.id DESC LIMIT ' . (int) $limit
    );
    $st->execute();
    return array_map(function ($r) {
        return [
            'id'     => (int) $r['id'],
            'type'   => $r['type'],
            'actor'  => ['id' => (int) $r['actor_id'], 'name' => $r['actor_name'], 'emoji' => $r['actor_emoji'], 'hue' => avatar_hue($r['actor_name'])],
            'target' => $r['target_id'] ? ['id' => (int) $r['target_id'], 'name' => $r['target_name'], 'emoji' => $r['target_emoji'], 'hue' => avatar_hue($r['target_name'])] : null,
            'ref'    => $r['ref_id'] ? (int) $r['ref_id'] : null,
            'text'   => $r['text'],
            'at'     => $r['created_at'],
        ];
    }, $st->fetchAll());
}

/* ----------------------------------------------------- pair of the week */

/**
 * Deterministic per ISO week: everyone opening the site on the same week
 * sees the same suggested pair. Pairs who have never sparked come first.
 */
function pair_of_the_week(): ?array
{
    $people = all_people();
    // Someone with a blank profile has nothing to spark about — leave them
    // out unless that would leave nobody.
    $empty   = array_column(empty_profiles(), 'id');
    $filled  = array_values(array_filter($people, fn($p) => !in_array($p['id'], $empty, true)));
    if (count($filled) >= 2) {
        $people = $filled;
    }
    if (count($people) < 2) {
        return null;
    }
    $week = gmdate('o-W');

    $pairCounts = [];
    foreach (db()->query('SELECT a_id, b_id FROM sparks')->fetchAll() as $s) {
        $k = min($s['a_id'], $s['b_id']) . '-' . max($s['a_id'], $s['b_id']);
        $pairCounts[$k] = ($pairCounts[$k] ?? 0) + 1;
    }

    $best = null;
    foreach ($people as $i => $a) {
        foreach (array_slice($people, $i + 1) as $b) {
            $k     = min($a['id'], $b['id']) . '-' . max($a['id'], $b['id']);
            $score = ($pairCounts[$k] ?? 0) * 1000
                   + (hexdec(substr(md5($week . ':' . $k), 0, 6)) % 1000);
            if ($best === null || $score < $best['score']) {
                $best = ['score' => $score, 'a' => $a, 'b' => $b];
            }
        }
    }
    if (!$best) {
        return null;
    }

    $shared = overlaps($best['a']['id'], $best['b']['id']);
    return [
        'week'   => $week,
        'a'      => $best['a'],
        'b'      => $best['b'],
        'shared' => $shared,
    ];
}

/* -------------------------------------------------------------- stats */

function stats(): array
{
    $one = function (string $sql) {
        return (int) db()->query($sql)->fetchColumn();
    };
    return [
        'people'    => $one('SELECT COUNT(*) FROM people WHERE active = 1'),
        'sparks'    => $one('SELECT COUNT(*) FROM sparks'),
        'done'      => $one("SELECT COUNT(*) FROM sparks WHERE status = 'done'"),
        'open'      => $one("SELECT COUNT(*) FROM sparks WHERE status <> 'done'"),
        'traits'    => $one("SELECT COUNT(*) FROM person_tags WHERE kind = 'seen_in_you'"),
        'projects'  => $one('SELECT COUNT(*) FROM projects'),
    ];
}

/** People with no tags yet — used to nudge on the home screen. */
function empty_profiles(): array
{
    $rows = db()->query(
        'SELECT p.id, p.name FROM people p
          WHERE p.active = 1
            AND (SELECT COUNT(*) FROM person_tags pt
                  WHERE pt.person_id = p.id AND pt.kind <> \'seen_in_you\') = 0
       ORDER BY p.name'
    )->fetchAll();
    return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $rows);
}
