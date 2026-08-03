<?php
/** JSON API. Every front-end call lands here as ?a=<action>. */

require_once __DIR__ . '/lib/bootstrap.php';

if (!db_installed()) {
    fail('Not installed yet. Open setup.php first.', 503);
}

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
            $people = all_people();
            $sum    = $me ? overlap_summary((int) $me['id']) : [];
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
                'me'       => $me ? person_public($me) : null,
                'people'   => $people,
                'kinds'    => kind_meta(),
                'stats'    => stats(),
                'pair'     => pair_of_the_week(),
                'feed'     => feed(25),
                'sparks'   => spark_rows($me ? (int) $me['id'] : null, null, 40),
                'projects' => all_projects(),
                'vocab'    => tag_vocabulary(),
                'empty'    => empty_profiles(),
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

        case 'feed':
            json_out(['ok' => true, 'feed' => feed(60)]);

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
            $me = require_me();
            $st = db()->prepare('UPDATE people SET emoji = ?, headline = ?, city = ? WHERE id = ?');
            $st->execute([
                clamp_str((string) param('emoji'), 8),
                clamp_str((string) param('headline'), 160),
                clamp_str((string) param('city'), 60),
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

            json_out(['ok' => true, 'id' => $id, 'sparks' => spark_rows((int) $me['id'], null, 40)]);
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
            ]);
        }

        case 'admin_add_person': {
            require_admin();
            $name = clamp_str((string) param('name'), 120);
            if ($name === '') {
                fail('Name required.');
            }
            $max = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM people')->fetchColumn();
            $st  = db()->prepare(
                'INSERT INTO people (name, slug, emoji, headline, city, token, cookie_epoch,
                                     is_admin, active, sort_order, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, 0, 1, ?, ?)'
            );
            $st->execute([
                $name, slugify($name),
                clamp_str((string) param('emoji'), 8),
                clamp_str((string) param('headline'), 160),
                clamp_str((string) param('city'), 60),
                rand_token(), $max + 10, now(),
            ]);
            json_out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
        }

        case 'admin_save_person': {
            require_admin();
            $id = (int) param('id', 0);
            $st = db()->prepare(
                'UPDATE people SET name = ?, slug = ?, emoji = ?, headline = ?, city = ?,
                                   is_admin = ?, active = ?, sort_order = ? WHERE id = ?'
            );
            $name = clamp_str((string) param('name'), 120);
            if ($name === '') {
                fail('Name required.');
            }
            $st->execute([
                $name, slugify($name),
                clamp_str((string) param('emoji'), 8),
                clamp_str((string) param('headline'), 160),
                clamp_str((string) param('city'), 60),
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

        case 'admin_set_password': {
            require_admin();
            $pw = (string) param('password');
            if (strlen($pw) < 6) {
                fail('Use at least 6 characters.');
            }
            set_setting('admin_hash', password_hash($pw, PASSWORD_DEFAULT));
            json_out(['ok' => true]);
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
