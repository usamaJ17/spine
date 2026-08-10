<?php
/** JSON API. Every front-end call lands here as ?a=<action>. */

require_once __DIR__ . '/lib/bootstrap.php';

if (!db_installed()) {
    fail('Not installed yet. Open setup.php first.', 503);
}
db_sync();

$action = isset($_GET['a']) ? (string) $_GET['a'] : '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

/* Writes must come from our own front-end. A cross-site HTML form cannot
   set a custom header, which is enough of a guard for an app with no
   real credentials to steal. */
if ($isPost) {
    if (($_SERVER['HTTP_X_SPINE'] ?? '') !== '1') {
        fail('Bad request origin.', 403);
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && !same_origin($origin, (string) ($_SERVER['HTTP_HOST'] ?? ''))) {
        fail('Cross-origin write refused.', 403);
    }
}

$me = current_user();

function require_me(): array
{
    $me = current_user();
    if (!$me) {
        fail('Pick who you are first.', 401);
    }
    return $me;
}

function require_admin(): void
{
    if (!admin_logged_in()) {
        fail('Admin only.', 403);
    }
}

try {
    switch ($action) {

        /* ------------------------------------------------------ reading */

        case 'bootstrap': {
            // Belt and braces for the cron job: if it never runs, the deadlines
            // still land — just whenever somebody next opens the app.
            run_round_schedule();

            $people    = all_people();
            $feedPage  = feed_page();
            $sum       = $me ? overlap_summary((int) $me['id']) : [];
            foreach ($people as &$p) {
                $s = $sum[$p['id']] ?? null;
                $p['overlap'] = $s['n'] ?? 0;
                $p['why']     = $s['why'] ?? '';
                $p['sample']  = $s['sample'] ?? [];
                $p['top']     = $s['top'] ?? null;
            }
            unset($p);

            json_out([
                'ok'       => true,
                'app'      => ['name' => cfg('app_name'), 'tagline' => cfg('tagline')],
                // Only your own record carries an email — person_public() never
                // exposes one, so nobody can harvest addresses from the API.
                'me'       => $me ? person_public($me) + [
                    'email'  => (string) ($me['email'] ?? ''),
                    'notify' => (int) ($me['notify'] ?? 1) === 1,
                    'mail'   => mail_enabled(),
                ] : null,
                'people'   => $people,
                'kinds'    => kind_meta(),
                'stats'    => stats(),
                'pair'     => pair_of_the_week(),
                'feed'     => $feedPage['items'],
                'feed_more' => $feedPage['more'],
                'sparks'   => spark_rows($me ? (int) $me['id'] : null, null, 40),
                'projects' => all_projects(),
                'vocab'    => tag_vocabulary(),
                'empty'    => empty_profiles(),
                'round'    => ($me && ($ar = active_round())) ? round_public($ar, (int) $me['id']) : null,
            ]);
        }

        case 'person': {
            $p = find_person((int) param('id', 0));
            if (!$p) {
                fail('No such person.', 404);
            }
            json_out([
                'ok'       => true,
                'person'   => person_public($p),
                'tags'     => person_tags((int) $p['id']),
                'projects' => person_projects((int) $p['id']),
                'overlaps' => $me ? overlaps((int) $me['id'], (int) $p['id']) : [],
                'sparks'   => spark_rows((int) $p['id'], null, 30),
                'link'     => $me && (int) $me['id'] === (int) $p['id'] ? magic_link($p) : null,
            ]);
        }

        case 'feed': {
            $limit  = min(50, max(1, (int) param('limit', FEED_PAGE)));
            $before = (int) param('before', 0) ?: null;
            $page   = feed_page($limit, $before);
            json_out(['ok' => true, 'feed' => $page['items'], 'more' => $page['more']]);
        }

        case 'sparks':
            json_out(['ok' => true, 'sparks' => spark_rows(null, null, 200), 'stats' => stats()]);

        /* ------------------------------------------------------ identity */

        case 'switch': {
            $p = find_person((int) param('person_id', 0));
            if (!$p) {
                fail('No such person.', 404);
            }
            set_user_cookie($p);
            json_out(['ok' => true, 'me' => person_public($p)]);
        }

        case 'signout':
            clear_user_cookie();
            json_out(['ok' => true]);

        /* -------------------------------------------------------- profile */

        case 'save_me': {
            $me    = require_me();
            $email = clamp_str((string) param('email', $me['email'] ?? ''), 190);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fail('That email address does not look right.');
            }
            $notify = param('notify');
            $notify = ($notify === false || $notify === '0' || $notify === 0) ? 0 : 1;

            $st = db()->prepare(
                'UPDATE people SET emoji = ?, headline = ?, city = ?, email = ?, notify = ? WHERE id = ?'
            );
            $st->execute([
                clamp_str((string) param('emoji'), 8),
                clamp_str((string) param('headline'), 160),
                clamp_str((string) param('city'), 60),
                $email,
                $notify,
                $me['id'],
            ]);
            json_out(['ok' => true]);
        }

        case 'add_tag': {
            $me       = require_me();
            $personId = (int) param('person_id', 0);
            $kind     = (string) param('kind');
            $label    = clamp_str((string) param('label'), 80);
            $note     = clamp_str((string) param('note'), 200);

            if (!in_array($kind, TAG_KINDS, true)) {
                fail('Unknown section.');
            }
            if ($label === '' || canon($label) === '') {
                fail('Write something first.');
            }
            $target = find_person($personId);
            if (!$target) {
                fail('No such person.', 404);
            }

            $mine = $personId === (int) $me['id'];
            if ($mine && $kind === 'seen_in_you') {
                fail('That section is for what other people see in you.');
            }
            if (!$mine && $kind !== 'seen_in_you') {
                fail('You can only add to your own profile — except "What others see".');
            }

            $tagId = tag_id_for($label);

            $dupe = db()->prepare('SELECT 1 FROM person_tags WHERE person_id = ? AND tag_id = ? AND kind = ?');
            $dupe->execute([$personId, $tagId, $kind]);
            if ($dupe->fetch()) {
                fail('Already there.');
            }

            $st = db()->prepare(
                'INSERT INTO person_tags (person_id, tag_id, kind, note, added_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $st->execute([$personId, $tagId, $kind, $note, $me['id'], now()]);

            if (!$mine) {
                activity_log('trait', (int) $me['id'], $personId, $tagId, $label);
            }
            json_out(['ok' => true, 'tags' => person_tags($personId, true)]);
        }

        case 'del_tag': {
            $me = require_me();
            $st = db()->prepare('SELECT * FROM person_tags WHERE id = ?');
            $st->execute([(int) param('id', 0)]);
            $row = $st->fetch();
            if (!$row) {
                fail('Already gone.', 404);
            }
            $allowed = (int) $row['person_id'] === (int) $me['id']
                    || (int) $row['added_by'] === (int) $me['id']
                    || admin_logged_in();
            if (!$allowed) {
                fail('Not yours to remove.', 403);
            }
            db()->prepare('DELETE FROM person_tags WHERE id = ?')->execute([(int) $row['id']]);
            json_out(['ok' => true, 'tags' => person_tags((int) $row['person_id'], true)]);
        }

        /* ------------------------------------------------------- projects */

        case 'add_project': {
            $me    = require_me();
            $title = clamp_str((string) param('title'), 140);
            if ($title === '') {
                fail('Give it a name.');
            }
            $st = db()->prepare(
                'INSERT INTO projects (person_id, title, blurb, kind, looking, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $kind = param('kind') === 'community' ? 'community' : 'personal';
            $st->execute([
                $me['id'], $title,
                clamp_str((string) param('blurb'), 600),
                $kind,
                clamp_str((string) param('looking'), 200),
                now(),
            ]);
            activity_log('project', (int) $me['id'], null, (int) db()->lastInsertId(), $title);
            json_out(['ok' => true, 'projects' => person_projects((int) $me['id'])]);
        }

        case 'del_project': {
            $me = require_me();
            $st = db()->prepare('SELECT * FROM projects WHERE id = ?');
            $st->execute([(int) param('id', 0)]);
            $row = $st->fetch();
            if (!$row) {
                fail('Already gone.', 404);
            }
            if ((int) $row['person_id'] !== (int) $me['id'] && !admin_logged_in()) {
                fail('Not yours.', 403);
            }
            db()->prepare('DELETE FROM projects WHERE id = ?')->execute([(int) $row['id']]);
            json_out(['ok' => true, 'projects' => person_projects((int) $me['id'])]);
        }

        /* --------------------------------------------------------- sparks */

        case 'spark_create': {
            $me    = require_me();
            $bId   = (int) param('b_id', 0);
            $other = find_person($bId);
            if (!$other) {
                fail('No such person.', 404);
            }
            if ($bId === (int) $me['id']) {
                fail('Pick someone else.');
            }
            $topic = clamp_str((string) param('topic'), 160);
            if ($topic === '') {
                fail('What is it about?');
            }

            $tagId     = (int) param('tag_id', 0) ?: null;
            $projectId = (int) param('project_id', 0) ?: null;
            $kind      = $projectId ? 'project' : ($tagId ? 'topic' : 'open');

            $st = db()->prepare(
                'INSERT INTO sparks (a_id, b_id, initiator_id, kind, tag_id, project_id,
                                     topic, message, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'open\', ?, ?)'
            );
            $st->execute([
                $me['id'], $bId, $me['id'], $kind, $tagId, $projectId,
                $topic, clamp_str((string) param('message'), 600), now(), now(),
            ]);
            $id = (int) db()->lastInsertId();
            activity_log('spark', (int) $me['id'], $bId, $id, $topic);

            // Let them know by email. Best-effort only: the spark is already
            // saved, and a dead mail server must never turn that into an error.
            [$sent, $mailErr] = notify_spark($me, $other, $topic, (string) param('message'));
            if (!$sent) {
                error_log("[spine] no spark email to {$other['name']}: $mailErr");
            }

            json_out([
                'ok'       => true,
                'id'       => $id,
                'notified' => $sent,
                'notice'   => $sent ? ($other['name'] . ' has been emailed.') : '',
                'sparks'   => spark_rows((int) $me['id'], null, 40),
            ]);
        }

        case 'spark_update': {
            $me = require_me();
            $st = db()->prepare('SELECT * FROM sparks WHERE id = ?');
            $st->execute([(int) param('id', 0)]);
            $s = $st->fetch();
            if (!$s) {
                fail('No such spark.', 404);
            }
            if ((int) $s['a_id'] !== (int) $me['id'] && (int) $s['b_id'] !== (int) $me['id'] && !admin_logged_in()) {
                fail('Only the two of you can update this.', 403);
            }
            $status = (string) param('status', $s['status']);
            if (!in_array($status, ['open', 'scheduled', 'done'], true)) {
                fail('Unknown status.');
            }
            $outcome = clamp_str((string) param('outcome', $s['outcome']), 600);

            db()->prepare('UPDATE sparks SET status = ?, outcome = ?, updated_at = ? WHERE id = ?')
                ->execute([$status, $outcome, now(), (int) $s['id']]);

            if ($status === 'done' && $s['status'] !== 'done') {
                $otherId = (int) $s['a_id'] === (int) $me['id'] ? (int) $s['b_id'] : (int) $s['a_id'];
                activity_log('done', (int) $me['id'], $otherId, (int) $s['id'], $s['topic']);
            }
            json_out(['ok' => true, 'sparks' => spark_rows((int) $me['id'], null, 40)]);
        }

        case 'spark_edit': {
            $me = require_me();
            $st = db()->prepare('SELECT * FROM sparks WHERE id = ?');
            $st->execute([(int) param('id', 0)]);
            $s = $st->fetch();
            if (!$s) {
                fail('No such spark.', 404);
            }
            if ((int) $s['initiator_id'] !== (int) $me['id'] && !admin_logged_in()) {
                fail('Only whoever started this spark can edit it.', 403);
            }

            $topic = clamp_str((string) param('topic', $s['topic']), 160);
            if ($topic === '') {
                fail('What is it about?');
            }
            $message = clamp_str((string) param('message', $s['message']), 600);

            $bId = (int) param('b_id', $s['b_id']);
            if ($bId !== (int) $s['b_id']) {
                if ($bId === (int) $me['id']) {
                    fail('Pick someone else.');
                }
                if (!find_person($bId)) {
                    fail('No such person.', 404);
                }
            }

            db()->prepare(
                'UPDATE sparks SET b_id = ?, topic = ?, message = ?, updated_at = ? WHERE id = ?'
            )->execute([$bId, $topic, $message, now(), (int) $s['id']]);

            json_out(['ok' => true, 'sparks' => spark_rows((int) $me['id'], null, 40)]);
        }

        case 'spark_delete': {
            $me = require_me();
            $st = db()->prepare('SELECT * FROM sparks WHERE id = ?');
            $st->execute([(int) param('id', 0)]);
            $s = $st->fetch();
            if (!$s) {
                fail('Already gone.', 404);
            }
            if ((int) $s['initiator_id'] !== (int) $me['id'] && !admin_logged_in()) {
                fail('Only whoever started this spark can delete it.', 403);
            }
            db()->prepare('DELETE FROM sparks WHERE id = ?')->execute([(int) $s['id']]);
            db()->prepare('DELETE FROM activity WHERE type IN (\'spark\', \'done\') AND ref_id = ?')
                ->execute([(int) $s['id']]);

            json_out(['ok' => true, 'sparks' => spark_rows((int) $me['id'], null, 40)]);
        }

        /* --------------------------------------------------------- rounds */

        case 'rounds': {
            $me  = require_me();
            $act = active_round();
            json_out([
                'ok'        => true,
                'active'    => $act ? round_public($act, (int) $me['id']) : null,
                'history'   => round_history((int) $me['id']),
                'threshold' => round_threshold(),
            ]);
        }

        case 'round': {
            $me = require_me();
            $r  = find_round((int) param('id', 0));
            if (!$r) {
                fail('No such round.', 404);
            }
            json_out(['ok' => true, 'round' => round_public($r, (int) $me['id'])]);
        }

        case 'round_create': {
            $me = require_me();
            if (active_round()) {
                fail('Something is already open. It has to close before the next one goes up.');
            }
            $title = clamp_str((string) param('title'), 240);
            if ($title === '') {
                fail('Say it in one line first.');
            }
            $kind = in_array(param('kind'), ['thought', 'idea'], true) ? (string) param('kind') : 'question';

            $expires = gmdate('Y-m-d\TH:i:s\Z', time() + round_days() * 86400);
            $digest  = gmdate('Y-m-d\TH:i:s\Z', time() + round_digest_days() * 86400);

            $st = db()->prepare(
                'INSERT INTO rounds (author_id, kind, title, body, status, threshold,
                                     expires_at, digest_at, digest_sent, created_at)
                 VALUES (?, ?, ?, ?, \'active\', ?, ?, ?, 0, ?)'
            );
            $st->execute([
                $me['id'], $kind, $title,
                clamp_str((string) param('body'), 900),
                round_threshold(), $expires, $digest, now(),
            ]);
            $id = (int) db()->lastInsertId();
            activity_log('round', (int) $me['id'], null, $id, $title);

            $round  = find_round($id);
            $mailed = notify_round_open($round, $me);

            json_out([
                'ok'     => true,
                'id'     => $id,
                'mailed' => $mailed,
                'round'  => round_public($round, (int) $me['id']),
            ]);
        }

        case 'round_answer': {
            $me = require_me();
            $r  = find_round((int) param('round_id', 0));
            if (!$r) {
                fail('No such round.', 404);
            }
            // Answering stays open after it moves to history — people who were
            // slow should still get to have their say.
            $body = clamp_str((string) param('body'), 2000);
            if ($body === '') {
                fail('Write something first.');
            }

            $existing = my_round_answer((int) $r['id'], (int) $me['id']);
            if ($existing) {
                db()->prepare('UPDATE round_answers SET body = ?, updated_at = ? WHERE id = ?')
                    ->execute([$body, now(), (int) $existing['id']]);
            } else {
                db()->prepare(
                    'INSERT INTO round_answers (round_id, person_id, body, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([(int) $r['id'], (int) $me['id'], $body, now(), now()]);
                activity_log('answer', (int) $me['id'], null, (int) $r['id'], $r['title']);
            }

            // Enough answers? Close it and let everyone read.
            $count  = round_answer_count((int) $r['id']);
            $closed = false;
            if (!$existing && $r['status'] === 'active' && $count >= (int) $r['threshold']) {
                $closed = close_round((int) $r['id'], null);
                if ($closed) {
                    activity_log('round_done', (int) $me['id'], null, (int) $r['id'], $r['title']);
                    notify_round_closed(find_round((int) $r['id']), $count);
                }
            }

            json_out([
                'ok'     => true,
                'closed' => $closed,
                'round'  => round_public(find_round((int) $r['id']), (int) $me['id']),
            ]);
        }

        case 'round_close': {
            $me = require_me();
            $r  = find_round((int) param('id', 0));
            if (!$r) {
                fail('No such round.', 404);
            }
            if ($r['status'] !== 'active') {
                fail('Already closed.');
            }
            $isAuthor = (int) $r['author_id'] === (int) $me['id'];
            $stale    = round_age_days($r) >= ROUND_STALE_DAYS;
            if (!$isAuthor && !$stale && !admin_logged_in()) {
                fail('Only whoever posted this can close it early.', 403);
            }
            close_round((int) $r['id'], (int) $me['id']);
            $count = round_answer_count((int) $r['id']);
            activity_log('round_done', (int) $me['id'], null, (int) $r['id'], $r['title']);
            if ($count > 0) {
                notify_round_closed(find_round((int) $r['id']), $count);
            }
            json_out(['ok' => true, 'round' => round_public(find_round((int) $r['id']), (int) $me['id'])]);
        }

        case 'round_delete': {
            $me = require_me();
            $r  = find_round((int) param('id', 0));
            if (!$r) {
                fail('Already gone.', 404);
            }
            $isAuthor = (int) $r['author_id'] === (int) $me['id'];
            if (!$isAuthor && !admin_logged_in()) {
                fail('Only whoever posted this can remove it.', 403);
            }
            if (round_answer_count((int) $r['id']) > 0 && !admin_logged_in()) {
                fail('People have already answered — close it instead of deleting it.');
            }
            db()->prepare('DELETE FROM round_answers WHERE round_id = ?')->execute([(int) $r['id']]);
            db()->prepare('DELETE FROM rounds WHERE id = ?')->execute([(int) $r['id']]);
            db()->prepare("DELETE FROM activity WHERE type IN ('round','round_done','answer') AND ref_id = ?")
                ->execute([(int) $r['id']]);
            json_out(['ok' => true]);
        }

        /* ---------------------------------------------------------- admin */

        case 'admin_state': {
            require_admin();
            $rows = db()->query('SELECT * FROM people ORDER BY sort_order ASC, name ASC')->fetchAll();
            $out  = [];
            foreach ($rows as $r) {
                $c = db()->prepare('SELECT COUNT(*) FROM person_tags WHERE person_id = ?');
                $c->execute([(int) $r['id']]);
                $out[] = [
                    'id'       => (int) $r['id'],
                    'name'     => $r['name'],
                    'emoji'    => $r['emoji'],
                    'hue'      => avatar_hue($r['name']),
                    'headline' => $r['headline'],
                    'city'     => $r['city'],
                    'email'    => (string) ($r['email'] ?? ''),
                    'notify'   => (int) ($r['notify'] ?? 1) === 1,
                    'active'   => (int) $r['active'] === 1,
                    'is_admin' => (int) $r['is_admin'] === 1,
                    'sort'     => (int) $r['sort_order'],
                    'tags'     => (int) $c->fetchColumn(),
                    'link'     => magic_link($r),
                    'epoch'    => (int) $r['cookie_epoch'],
                ];
            }
            // The admin's browser fetches these paths to prove they are not
            // publicly readable. A database stored outside the app folder has
            // no URL at all, so there is nothing to probe — which is the point
            // of putting it there.
            $probe   = null;
            $outside = false;
            if (db_driver() === 'sqlite') {
                $path = realpath(cfg('db')['sqlite_path']);
                $root = realpath(__DIR__);
                if ($path && $root) {
                    if (strpos($path, $root . DIRECTORY_SEPARATOR) === 0) {
                        $probe = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
                    } else {
                        $outside = true;
                    }
                }
            }

            json_out([
                'ok'      => true,
                'people'  => $out,
                'stats'   => stats(),
                'base'    => base_url(),
                'probe'   => $probe,
                'outside' => $outside,
                'env'     => is_file(__DIR__ . '/.env'),
                'setup'   => is_file(__DIR__ . '/setup.php'),
                'driver'  => db_driver(),
                'rounds'  => [
                    'threshold'   => round_threshold(),
                    'days'        => round_days(),
                    'digest_days' => round_digest_days(),
                    'cron'        => env('CRON_KEY') !== null,
                ],
                'mail'    => mail_enabled(),
                'mailhost' => mail_enabled() ? mail_config()['host'] . ':' . mail_config()['port'] : '',
            ]);
        }

        case 'admin_add_person': {
            require_admin();
            $name = clamp_str((string) param('name'), 120);
            if ($name === '') {
                fail('Name required.');
            }
            $max = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM people')->fetchColumn();
            $email = clamp_str((string) param('email'), 190);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fail('That email address does not look right.');
            }
            $st = db()->prepare(
                'INSERT INTO people (name, slug, emoji, headline, city, email, notify, token,
                                     cookie_epoch, is_admin, active, sort_order, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, 1, 0, 1, ?, ?)'
            );
            $st->execute([
                $name, slugify($name),
                clamp_str((string) param('emoji'), 8),
                clamp_str((string) param('headline'), 160),
                clamp_str((string) param('city'), 60),
                $email,
                rand_token(), $max + 10, now(),
            ]);
            json_out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
        }

        case 'admin_save_person': {
            require_admin();
            $id   = (int) param('id', 0);
            $name = clamp_str((string) param('name'), 120);
            if ($name === '') {
                fail('Name required.');
            }
            $email = clamp_str((string) param('email'), 190);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fail('That email address does not look right.');
            }
            $st = db()->prepare(
                'UPDATE people SET name = ?, slug = ?, emoji = ?, headline = ?, city = ?,
                                   email = ?, notify = ?, is_admin = ?, active = ?,
                                   sort_order = ? WHERE id = ?'
            );
            $st->execute([
                $name, slugify($name),
                clamp_str((string) param('emoji'), 8),
                clamp_str((string) param('headline'), 160),
                clamp_str((string) param('city'), 60),
                $email,
                param('notify') ? 1 : 0,
                param('is_admin') ? 1 : 0,
                param('active') === false || param('active') === '0' ? 0 : 1,
                (int) param('sort', 0),
                $id,
            ]);
            json_out(['ok' => true]);
        }

        case 'admin_delete_person': {
            require_admin();
            $id = (int) param('id', 0);
            if (!find_person($id)) {
                fail('Already gone.', 404);
            }
            db()->prepare('DELETE FROM person_tags WHERE person_id = ? OR added_by = ?')->execute([$id, $id]);
            db()->prepare('DELETE FROM projects WHERE person_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM sparks WHERE a_id = ? OR b_id = ?')->execute([$id, $id]);
            db()->prepare('DELETE FROM activity WHERE actor_id = ? OR target_id = ?')->execute([$id, $id]);
            db()->prepare('DELETE FROM people WHERE id = ?')->execute([$id]);
            json_out(['ok' => true]);
        }

        case 'admin_new_token': {
            require_admin();
            $id = (int) param('id', 0);
            db()->prepare('UPDATE people SET token = ?, cookie_epoch = cookie_epoch + 1 WHERE id = ?')
                ->execute([rand_token(), $id]);
            $p = find_person($id);
            json_out(['ok' => true, 'link' => $p ? magic_link($p) : null]);
        }

        case 'admin_reset_cookies': {
            require_admin();
            $id = (int) param('id', 0);
            db()->prepare('UPDATE people SET cookie_epoch = cookie_epoch + 1 WHERE id = ?')->execute([$id]);
            json_out(['ok' => true]);
        }

        case 'admin_set_rounds': {
            require_admin();
            $n = (int) param('threshold', 6);
            $d = (int) param('days', 4);
            $g = (int) param('digest_days', 7);
            if ($n < 2 || $n > 50) {
                fail('Answers needed: pick a number between 2 and 50.');
            }
            if ($d < 1 || $d > 60) {
                fail('Days on the floor: pick a number between 1 and 60.');
            }
            if ($g < $d || $g > 90) {
                fail('The digest has to come after it closes, and within 90 days.');
            }
            set_setting('round_threshold', (string) $n);
            set_setting('round_days', (string) $d);
            set_setting('round_digest_days', (string) $g);
            json_out(['ok' => true, 'threshold' => $n, 'days' => $d, 'digest_days' => $g]);
        }

        case 'admin_set_password': {
            require_admin();
            $pw = (string) param('password');
            if (strlen($pw) < 6) {
                fail('Use at least 6 characters.');
            }
            set_setting('admin_hash', password_hash($pw, PASSWORD_DEFAULT));
            json_out(['ok' => true]);
        }

        case 'admin_email_plan': {
            require_admin();
            require_once __DIR__ . '/lib/emails.php';
            json_out(['ok' => true] + plan_email_import((string) param('raw')));
        }

        case 'admin_email_apply': {
            require_admin();
            require_once __DIR__ . '/lib/emails.php';
            $plan = plan_email_import((string) param('raw'));
            $n    = apply_email_import($plan['matched']);
            json_out(['ok' => true, 'updated' => $n]);
        }

        case 'admin_test_mail': {
            require_admin();
            $to = clamp_str((string) param('email'), 190);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                fail('Give a valid address to send the test to.');
            }
            $app  = (string) cfg('app_name');
            $body = mail_eyebrow('Test message')
                  . mail_p('If you are reading this, <b style="color:#e9ebf1">' . e($app)
                      . '</b> can send email.', '#c9cedb', 14, 16)
                  . mail_p('Spark notifications and the round digest will reach people.', '#99a0b0', 10, 14)
                  . mail_quote('SMTP is working', mail_config()['host'] . ':' . mail_config()['port'])
                  . mail_button('Open ' . $app, base_url());
            [$ok, $err] = send_mail(
                $to, '', "$app — mail is working",
                mail_page('Your SMTP settings are correct', $body),
                "If you are reading this, $app can send email.\n\n"
                    . "Spark notifications and the round digest will reach people.\n\n" . base_url() . "\n"
            );
            if (!$ok) {
                fail($err ?: 'Sending failed.');
            }
            json_out(['ok' => true, 'sent_to' => $to]);
        }

        case 'admin_wipe_demo': {
            require_admin();
            db()->exec('DELETE FROM activity');
            db()->exec('DELETE FROM sparks');
            json_out(['ok' => true]);
        }

        default:
            fail('Unknown action.', 404);
    }
} catch (Throwable $ex) {
    error_log('[spine] ' . $ex->getMessage());
    fail('Something broke: ' . $ex->getMessage(), 500);
}
