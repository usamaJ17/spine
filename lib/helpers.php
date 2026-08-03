<?php
/**
 * Shared helpers: config, identity cookies, small utilities.
 *
 * Identity model (deliberately not real auth):
 *   - every person has a secret `token`; their magic link is /?u=TOKEN
 *   - visiting it sets a signed cookie "pid.epoch.hmac"
 *   - "switch user" sets the same cookie server-side without revealing tokens
 *   - admin can bump cookie_epoch to invalidate every device for one person,
 *     or mint a brand new token to invalidate the old magic link.
 */

const TAG_KINDS = ['good_at', 'curious', 'building', 'life', 'seen_in_you'];

/**
 * Read the .env file sitting next to index.php.
 *
 * Deliberately tiny: KEY=value, one per line, # for comments, optional
 * quotes. Real environment variables are used as a fallback so this also
 * works on hosts that inject config that way.
 */
function env_all(): array
{
    static $vars = null;
    if ($vars !== null) {
        return $vars;
    }
    $vars = [];
    $file = dirname(__DIR__) . '/.env';
    if (!is_file($file) || !is_readable($file)) {
        return $vars;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $k = strtoupper(trim(substr($line, 0, $eq)));
        $v = trim(substr($line, $eq + 1));

        $quoted = strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0];
        if ($quoted) {
            $v = substr($v, 1, -1);
        } elseif (($hash = strpos($v, ' #')) !== false) {
            $v = rtrim(substr($v, 0, $hash));   // trailing comment on a bare value
        }
        $vars[$k] = $v;
    }
    return $vars;
}

function env(string $key, $default = null)
{
    $v = env_all()[$key] ?? null;
    if ($v === null) {
        $g = getenv($key);
        $v = $g === false ? null : $g;
    }
    return ($v === null || $v === '') ? $default : $v;
}

/**
 * Config: lib/config.php holds the defaults, .env overrides them.
 * .env is git-ignored and blocked by .htaccess, so nothing secret and
 * nothing server-specific ever reaches the repository.
 */
function cfg(?string $key = null)
{
    static $c = null;
    if ($c === null) {
        $c = require __DIR__ . '/config.php';

        $c['app_name']    = env('APP_NAME', $c['app_name']);
        $c['tagline']     = env('TAGLINE', $c['tagline']);
        $c['base_url']    = env('BASE_URL', $c['base_url']);
        $c['cookie_days'] = (int) env('COOKIE_DAYS', $c['cookie_days']);

        $c['db']['driver'] = env('DB_DRIVER', $c['db']['driver']);

        $path = env('DB_PATH');
        if ($path !== null) {
            // Absolute stays as-is; relative is resolved against the app folder.
            $c['db']['sqlite_path'] = (str_starts_with($path, '/') || preg_match('~^[A-Za-z]:~', $path))
                ? $path
                : dirname(__DIR__) . '/' . ltrim($path, '/');
        }

        foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'name' => 'DB_NAME',
                  'user' => 'DB_USER', 'pass' => 'DB_PASS'] as $k => $envKey) {
            $c['db']['mysql'][$k] = env($envKey, $c['db']['mysql'][$k]);
        }
        $c['db']['mysql']['port'] = (int) $c['db']['mysql']['port'];
    }
    return $key === null ? $c : ($c[$key] ?? null);
}

/**
 * Key that signs the sign-in cookies.
 *
 * APP_SECRET in .env wins, because .env is not in git and survives a redeploy
 * that replaces the whole folder — otherwise a regenerated key would sign
 * everybody out and they would each have to open their link again.
 * Without it, one is generated into data/secret.key.
 */
function app_secret(): string
{
    static $s = null;
    if ($s !== null) {
        return $s;
    }
    $fromEnv = env('APP_SECRET');
    if (is_string($fromEnv) && strlen($fromEnv) >= 16) {
        return $s = $fromEnv;
    }
    $file = __DIR__ . '/../data/secret.key';
    if (!is_file($file)) {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX);
        @chmod($file, 0600);
    }
    return $s = trim((string) file_get_contents($file));
}

function base_url(): string
{
    $configured = trim((string) cfg('base_url'));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}

function now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/**
 * Does an Origin header point at this same site?
 * HTTP_HOST carries the port ("site.com:8099") while parse_url's HOST does
 * not, so both sides have to be parsed the same way or every write is
 * refused on any non-default port.
 */
function same_origin(string $origin, string $httpHost): bool
{
    $a = parse_url($origin);
    $b = parse_url('http://' . $httpHost);
    if (!isset($a['host'], $b['host'])) {
        return false;
    }
    if (strcasecmp($a['host'], $b['host']) !== 0) {
        return false;
    }
    $pa = $a['port'] ?? null;
    $pb = $b['port'] ?? null;
    return $pa === null || $pb === null || (int) $pa === (int) $pb;
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function rand_token(int $bytes = 9): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', 'xy'), '=');
}

function slugify(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-') ?: 'person';
}

/** Canonical form used to merge "A.I.", "ai" and "  AI " into one tag. */
function canon(string $label): string
{
    $s = mb_strtolower(trim($label), 'UTF-8');
    $s = str_replace(['.', '_'], ['', ' '], $s);
    $s = preg_replace('/[^\p{L}\p{N}+#]+/u', ' ', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    // crude singularisation so "maps" and "map" don't split
    if (mb_strlen($s) > 4 && substr($s, -1) === 's' && substr($s, -2) !== 'ss') {
        $s = substr($s, 0, -1);
    }
    return $s;
}

/* ------------------------------------------------------------ identity */

function sign(string $payload): string
{
    return hash_hmac('sha256', $payload, app_secret());
}

function set_user_cookie(array $person): void
{
    $payload = $person['id'] . '.' . $person['cookie_epoch'];
    $value   = $payload . '.' . substr(sign($payload), 0, 32);
    $days    = (int) (cfg('cookie_days') ?: 365);
    setcookie('spine_id', $value, [
        'expires'  => time() + $days * 86400,
        'path'     => cookie_path(),
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['spine_id'] = $value;
}

function clear_user_cookie(): void
{
    setcookie('spine_id', '', ['expires' => time() - 3600, 'path' => cookie_path()]);
    unset($_COOKIE['spine_id']);
}

function cookie_path(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $dir === '' ? '/' : $dir . '/';
}

/** The person this browser is acting as, or null. */
function current_user(): ?array
{
    static $cached = false, $user = null;
    if ($cached) {
        return $user;
    }
    $cached = true;

    $raw = $_COOKIE['spine_id'] ?? '';
    if (!$raw || substr_count($raw, '.') !== 2) {
        return null;
    }
    [$pid, $epoch, $sig] = explode('.', $raw);
    if (!hash_equals(substr(sign($pid . '.' . $epoch), 0, 32), $sig)) {
        return null;
    }

    $st = db()->prepare('SELECT * FROM people WHERE id = ? AND active = 1');
    $st->execute([(int) $pid]);
    $p = $st->fetch();
    if (!$p || (int) $p['cookie_epoch'] !== (int) $epoch) {
        return null;
    }
    return $user = $p;
}

function person_by_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $st = db()->prepare('SELECT * FROM people WHERE token = ? AND active = 1');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

function magic_link(array $person): string
{
    return base_url() . '/?u=' . rawurlencode($person['token']);
}

/* --------------------------------------------------------------- admin */

function admin_logged_in(): bool
{
    $raw = $_COOKIE['spine_admin'] ?? '';
    if (!$raw || substr_count($raw, '.') !== 1) {
        return false;
    }
    [$exp, $sig] = explode('.', $raw);
    if (!ctype_digit($exp) || (int) $exp < time()) {
        return false;
    }
    return hash_equals(substr(sign('admin.' . $exp), 0, 32), $sig);
}

function admin_login(int $hours = 12): void
{
    $exp   = time() + $hours * 3600;
    $value = $exp . '.' . substr(sign('admin.' . $exp), 0, 32);
    setcookie('spine_admin', $value, [
        'expires'  => $exp,
        'path'     => cookie_path(),
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function admin_logout(): void
{
    setcookie('spine_admin', '', ['expires' => time() - 3600, 'path' => cookie_path()]);
}

/* ---------------------------------------------------------------- misc */

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $code = 400): void
{
    json_out(['ok' => false, 'error' => $message], $code);
}

function body(): array
{
    static $b = null;
    if ($b !== null) {
        return $b;
    }
    $raw = file_get_contents('php://input') ?: '';
    $j   = json_decode($raw, true);
    return $b = is_array($j) ? $j : $_POST;
}

function param(string $key, $default = '')
{
    $v = body()[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function clamp_str(string $s, int $max): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max, 'UTF-8');
}

function activity_log(string $type, int $actor, ?int $target, ?int $ref, string $text = ''): void
{
    $st = db()->prepare(
        'INSERT INTO activity (type, actor_id, target_id, ref_id, text, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$type, $actor, $target, $ref, clamp_str($text, 400), now()]);
}

/** Colour ring for avatars — stable per person, no assets needed. */
function avatar_hue(string $name): int
{
    return (int) (hexdec(substr(md5($name), 0, 4)) % 360);
}

function boot(): void
{
    require_once __DIR__ . '/db.php';
    date_default_timezone_set('UTC');
    mb_internal_encoding('UTF-8');
}
