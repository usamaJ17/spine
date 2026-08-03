<?php
/**
 * PDO connection + schema. Works on both SQLite and MySQL so the same
 * codebase runs locally and on shared hosting without edits.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = cfg('db');
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if ($db['driver'] === 'mysql') {
        $m   = $db['mysql'];
        $dsn = "mysql:host={$m['host']};port={$m['port']};dbname={$m['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $m['user'], $m['pass'], $opts);
    } else {
        $path = $db['sqlite_path'];
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }

    return $pdo;
}

function db_driver(): string
{
    return cfg('db')['driver'] === 'mysql' ? 'mysql' : 'sqlite';
}

/** True once the schema exists. Cheap enough to call on every request. */
function db_installed(): bool
{
    try {
        db()->query('SELECT 1 FROM people LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Schema, expressed once with a couple of driver placeholders.
 *   {PK}   auto-incrementing integer primary key
 *   {OPTS} table options (MySQL needs the charset)
 */
function db_schema(): array
{
    $sql = [

        "CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(64) PRIMARY KEY,
            v TEXT
        ) {OPTS}",

        "CREATE TABLE IF NOT EXISTS people (
            id           {PK},
            name         VARCHAR(120) NOT NULL,
            slug         VARCHAR(120) NOT NULL,
            emoji        VARCHAR(16)  DEFAULT '',
            headline     VARCHAR(200) DEFAULT '',
            city         VARCHAR(80)  DEFAULT '',
            token        VARCHAR(40)  NOT NULL,
            cookie_epoch INTEGER      NOT NULL DEFAULT 1,
            is_admin     INTEGER      NOT NULL DEFAULT 0,
            active       INTEGER      NOT NULL DEFAULT 1,
            sort_order   INTEGER      NOT NULL DEFAULT 0,
            created_at   VARCHAR(25)  NOT NULL
        ) {OPTS}",

        "CREATE TABLE IF NOT EXISTS tags (
            id    {PK},
            label VARCHAR(90) NOT NULL,
            canon VARCHAR(90) NOT NULL
        ) {OPTS}",

        "CREATE TABLE IF NOT EXISTS person_tags (
            id         {PK},
            person_id  INTEGER NOT NULL,
            tag_id     INTEGER NOT NULL,
            kind       VARCHAR(20) NOT NULL,
            note       VARCHAR(240) DEFAULT '',
            added_by   INTEGER NOT NULL,
            created_at VARCHAR(25) NOT NULL
        ) {OPTS}",

        "CREATE TABLE IF NOT EXISTS projects (
            id         {PK},
            person_id  INTEGER NOT NULL,
            title      VARCHAR(140) NOT NULL,
            blurb      VARCHAR(600) DEFAULT '',
            kind       VARCHAR(20)  NOT NULL DEFAULT 'personal',
            looking    VARCHAR(240) DEFAULT '',
            created_at VARCHAR(25)  NOT NULL
        ) {OPTS}",

        "CREATE TABLE IF NOT EXISTS sparks (
            id           {PK},
            a_id         INTEGER NOT NULL,
            b_id         INTEGER NOT NULL,
            initiator_id INTEGER NOT NULL,
            kind         VARCHAR(20) NOT NULL DEFAULT 'topic',
            tag_id       INTEGER NULL,
            project_id   INTEGER NULL,
            topic        VARCHAR(160) NOT NULL,
            message      VARCHAR(600) DEFAULT '',
            status       VARCHAR(16)  NOT NULL DEFAULT 'open',
            outcome      VARCHAR(600) DEFAULT '',
            created_at   VARCHAR(25) NOT NULL,
            updated_at   VARCHAR(25) NOT NULL
        ) {OPTS}",

        "CREATE TABLE IF NOT EXISTS activity (
            id         {PK},
            type       VARCHAR(30) NOT NULL,
            actor_id   INTEGER NOT NULL,
            target_id  INTEGER NULL,
            ref_id     INTEGER NULL,
            text       VARCHAR(400) DEFAULT '',
            created_at VARCHAR(25) NOT NULL
        ) {OPTS}",

        "CREATE INDEX IF NOT EXISTS idx_pt_person ON person_tags (person_id)",
        "CREATE INDEX IF NOT EXISTS idx_pt_tag    ON person_tags (tag_id)",
        "CREATE INDEX IF NOT EXISTS idx_tag_canon ON tags (canon)",
        "CREATE INDEX IF NOT EXISTS idx_sp_a      ON sparks (a_id)",
        "CREATE INDEX IF NOT EXISTS idx_sp_b      ON sparks (b_id)",
        "CREATE INDEX IF NOT EXISTS idx_act_time  ON activity (created_at)",
        "CREATE INDEX IF NOT EXISTS idx_people_tk ON people (token)",
    ];

    $mysql = db_driver() === 'mysql';
    $pk    = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $opts  = $mysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $out = [];
    foreach ($sql as $stmt) {
        // MySQL < 8.0.13 has no "CREATE INDEX IF NOT EXISTS"; those statements
        // are run individually and their duplicate-key errors ignored.
        $out[] = str_replace(['{PK}', '{OPTS}'], [$pk, $opts], $stmt);
    }
    return $out;
}

function db_migrate(): void
{
    $mysql = db_driver() === 'mysql';
    foreach (db_schema() as $stmt) {
        if ($mysql && stripos($stmt, 'CREATE INDEX') === 0) {
            $stmt = str_ireplace('IF NOT EXISTS ', '', $stmt);
            try {
                db()->exec($stmt);
            } catch (Throwable $e) {
                // index already exists — fine
            }
            continue;
        }
        db()->exec($stmt);
    }
}

/* ---------------------------------------------------------------- helpers */

function setting(string $key, ?string $default = null): ?string
{
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$key]);
    $row = $st->fetch();
    return $row ? $row['v'] : $default;
}

function set_setting(string $key, string $value): void
{
    $exists = db()->prepare('SELECT 1 FROM settings WHERE k = ?');
    $exists->execute([$key]);
    if ($exists->fetch()) {
        $st = db()->prepare('UPDATE settings SET v = ? WHERE k = ?');
        $st->execute([$value, $key]);
    } else {
        $st = db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?)');
        $st->execute([$key, $value]);
    }
}
