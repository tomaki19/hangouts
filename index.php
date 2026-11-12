<?php
// Hangout (PHP port): front controller for the JSON API and the HTML pages. PHP >= 7.4.
declare(strict_types=1);

$cfgFile = is_file(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : null;
if (!$cfgFile) { http_response_code(500); exit('Copy config.sample.php to config.php and set the password.'); }
$cfg = require $cfgFile;
$ADMIN_HASH = (string)($cfg['admin_password_hash'] ?? '');
// Optional fixed URL prefix ('' or '/index.php'). Without it the prefix follows how the page was opened:
// /admin (rewrites work) gives short links, /index.php/admin gives /index.php/... links.
$BASE_CFG = array_key_exists('base', $cfg) ? rtrim((string)$cfg['base'], '/') : null;
$DATA_DIR = rtrim((string)($cfg['data_dir'] ?? (__DIR__ . '/data')), '/');
if (!preg_match('/^\$(2y|argon2id?)\$/', $ADMIN_HASH)) {
    http_response_code(500);
    exit('Set admin_password_hash in config.php (create it with: php hash-password.php).');
}

const SESSION_TTL = 7 * 24 * 3600;
const ANSWERS = ['ok', 'maybe', 'no'];
const OPTION_COLORS = ['red', 'orange', 'yellow', 'lime', 'green', 'teal', 'cyan', 'blue', 'purple', 'pink', 'brown', 'gray']; // optional highlight per option

class HttpError extends Exception {
    public int $status;
    public function __construct(int $status, string $msg) { parent::__construct($msg); $this->status = $status; }
}

// ---------- helpers ----------
function rid(int $n = 9): string { return rtrim(strtr(base64_encode(random_bytes($n)), '+/', '-_'), '='); }
function str($v, int $max): string { return is_string($v) ? mb_substr(trim($v), 0, $max) : ''; }
function isHttps(): bool {
    return (($_SERVER['HTTPS'] ?? 'off') !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function headers_common(): void {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");
}
function sendJson(int $status, $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    headers_common();
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function readJson(): array {
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) throw new HttpError(415, 'JSON required');
    $raw = file_get_contents('php://input', false, null, 0, 100 * 1024 + 1);
    if (strlen($raw) > 100 * 1024) throw new HttpError(413, 'Too large');
    $d = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($d)) throw new HttpError(400, 'Bad JSON');
    return $d;
}

// Read-modify-write a JSON file under an exclusive lock. $fn receives the data by reference.
function locked(string $file, callable $fn) {
    $f = fopen($file, 'c+');
    if (!$f) throw new RuntimeException('Cannot open ' . $file);
    flock($f, LOCK_EX);
    try {
        $raw = stream_get_contents($f);
        $data = $raw ? json_decode($raw, true) : [];
        if (!is_array($data)) $data = [];
        $result = $fn($data);
        rewind($f); ftruncate($f, 0);
        fwrite($f, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($f);
        return $result;
    } finally {
        flock($f, LOCK_UN); fclose($f);
    }
}
function withPolls(callable $fn) { global $DATA_DIR; return locked($DATA_DIR . '/polls.json', $fn); }

// ---------- auth ----------
// Sessions are random tokens; only their SHA-256 is stored server-side, so neither the config
// nor the data files contain anything that can be used to log in or forge a cookie.
function sessionStore(callable $fn) { global $DATA_DIR; return locked($DATA_DIR . '/sessions.json', $fn); }
function newSession(): string {
    $token = rid(32);
    $h = hash('sha256', $token);
    sessionStore(function (array &$sessions) use ($h) {
        foreach ($sessions as $k => $exp) if ($exp <= time()) unset($sessions[$k]);
        $sessions[$h] = time() + SESSION_TTL;
    });
    return $token;
}
function endSession(): void {
    $t = $_COOKIE['session'] ?? '';
    if (!is_string($t) || $t === '') return;
    $h = hash('sha256', $t);
    sessionStore(function (array &$sessions) use ($h) { unset($sessions[$h]); });
}
function isAdmin(): bool {
    $t = $_COOKIE['session'] ?? '';
    if (!is_string($t) || $t === '') return false;
    $h = hash('sha256', $t);
    return sessionStore(fn(array &$sessions) => isset($sessions[$h]) && $sessions[$h] > time());
}
function setSession(?string $value): void {
    setcookie('session', $value ?? '', [
        'expires' => $value === null ? time() - 3600 : time() + SESSION_TTL,
        'path' => '/', 'secure' => isHttps(), 'httponly' => true, 'samesite' => 'Strict',
    ]);
}
// 10 failed logins per IP per 15 minutes
function loginThrottle(string $ip, bool $recordFail): bool {
    global $DATA_DIR;
    return locked($DATA_DIR . '/fails.json', function (array &$fails) use ($ip, $recordFail) {
        $now = time();
        foreach ($fails as $k => $f) if ($now - $f['first'] > 900) unset($fails[$k]);
        if ($recordFail) {
            if (!isset($fails[$ip])) $fails[$ip] = ['count' => 0, 'first' => $now];
            $fails[$ip]['count']++;
        }
        return isset($fails[$ip]) && $fails[$ip]['count'] >= 10;
    });
}

// ---------- poll helpers ----------
function cleanOptions($input, array $existing = []): array {
    if (!is_array($input) || count($input) < 1 || count($input) > 60) throw new HttpError(400, 'Need 1-60 options');
    $known = array_column($existing, 'id');
    $out = [];
    foreach (array_values($input) as $o) {
        $o = is_array($o) ? $o : [];
        $date = (isset($o['date']) && is_string($o['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $o['date'])) ? $o['date'] : '';
        $note = str($o['note'] ?? '', 60);
        if ($date === '' && $note === '') throw new HttpError(400, 'Option needs a date or text');
        $id = (isset($o['id']) && is_string($o['id']) && in_array($o['id'], $known, true)) ? $o['id'] : rid(4);
        $color = (isset($o['color']) && is_string($o['color']) && in_array($o['color'], OPTION_COLORS, true)) ? $o['color'] : '';
        $out[] = ['id' => $id, 'date' => $date, 'note' => $note, 'color' => $color];
    }
    return $out;
}
function publicPoll(array $p): array {
    return [
        'id' => $p['id'], 'title' => $p['title'], 'description' => $p['description'],
        'options' => array_values($p['options']), 'closed' => !empty($p['closed']),
        'votes' => array_map(fn($v) => ['id' => $v['id'], 'name' => $v['name'], 'answers' => (object)$v['answers']], array_values($p['votes'])),
    ];
}

// ---------- API ----------
function api(array $parts, string $method): void {
    global $ADMIN_HASH;
    [$a, $b, $c, $d] = array_pad($parts, 4, null);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if ($a === 'login' && $method === 'POST') {
        if (loginThrottle($ip, false)) throw new HttpError(429, 'Too many attempts, try later');
        $body = readJson();
        if (!is_string($body['password'] ?? null) || !password_verify($body['password'], $ADMIN_HASH)) {
            loginThrottle($ip, true);
            throw new HttpError(401, 'Wrong password');
        }
        setSession(newSession());
        sendJson(200, ['ok' => true]);
    }
    if ($a === 'logout' && $method === 'POST') { endSession(); setSession(null); sendJson(200, ['ok' => true]); }

    if ($a === 'admin') {
        if (!isAdmin()) throw new HttpError(401, 'Login required');
        if ($b !== 'polls') throw new HttpError(404, 'Not found');

        if ($c === null && $method === 'GET') {
            $list = withPolls(fn(array &$polls) => array_values($polls));
            usort($list, fn($x, $y) => $y['created'] <=> $x['created']);
            sendJson(200, array_map('publicPoll', $list));
        }
        if ($c === null && $method === 'POST') {
            $body = readJson();
            $title = str($body['title'] ?? '', 120);
            if ($title === '') throw new HttpError(400, 'Title required');
            $p = [
                'id' => rid(9), 'title' => $title, 'description' => str($body['description'] ?? '', 1000),
                'options' => cleanOptions($body['options'] ?? null), 'votes' => [], 'closed' => false, 'created' => time(),
            ];
            withPolls(function (array &$polls) use ($p) { $polls[$p['id']] = $p; });
            sendJson(201, publicPoll($p));
        }
        if ($c !== null && $d === null && $method === 'PUT') {
            $body = readJson();
            $title = str($body['title'] ?? '', 120);
            if ($title === '') throw new HttpError(400, 'Title required');
            $p = withPolls(function (array &$polls) use ($c, $body, $title) {
                if (!isset($polls[$c])) throw new HttpError(404, 'No such poll');
                $p = &$polls[$c];
                $p['title'] = $title;
                $p['description'] = str($body['description'] ?? '', 1000);
                $p['options'] = cleanOptions($body['options'] ?? null, $p['options']);
                $p['closed'] = !empty($body['closed']);
                $ids = array_column($p['options'], 'id');
                foreach ($p['votes'] as &$v) {
                    $v['answers'] = array_intersect_key($v['answers'], array_flip($ids));
                }
                return $p;
            });
            sendJson(200, publicPoll($p));
        }
        if ($c !== null && $d === null && $method === 'DELETE') {
            $found = withPolls(function (array &$polls) use ($c) { $f = isset($polls[$c]); unset($polls[$c]); return $f; });
            if (!$found) throw new HttpError(404, 'No such poll');
            sendJson(200, ['ok' => true]);
        }
        if ($c !== null && $d === 'votes' && isset($parts[4]) && $method === 'DELETE') {
            $vid = $parts[4];
            $p = withPolls(function (array &$polls) use ($c, $vid) {
                if (!isset($polls[$c])) throw new HttpError(404, 'No such poll');
                $polls[$c]['votes'] = array_values(array_filter($polls[$c]['votes'], fn($v) => $v['id'] !== $vid));
                return $polls[$c];
            });
            sendJson(200, publicPoll($p));
        }
        throw new HttpError(404, 'Not found');
    }

    if ($a === 'polls' && $b !== null) {
        if ($c === null && $method === 'GET') {
            $p = withPolls(fn(array &$polls) => $polls[$b] ?? null);
            if (!$p) throw new HttpError(404, 'No such poll');
            sendJson(200, publicPoll($p));
        }
        if ($c === 'votes' && $method === 'POST') {
            $body = readJson();
            [$p, $status, $voteId] = withPolls(function (array &$polls) use ($b, $body) {
                if (!isset($polls[$b])) throw new HttpError(404, 'No such poll');
                $p = &$polls[$b];
                if (!empty($p['closed'])) throw new HttpError(403, 'Poll is closed');
                $name = str($body['name'] ?? '', 40);
                if ($name === '') throw new HttpError(400, 'Name required');
                $in = is_array($body['answers'] ?? null) ? $body['answers'] : [];
                $answers = [];
                foreach ($p['options'] as $o) {
                    $v = $in[$o['id']] ?? null;
                    $answers[$o['id']] = (is_string($v) && in_array($v, ANSWERS, true)) ? $v : 'no';
                }
                if (!empty($body['voteId'])) { // anyone with the link may edit any response
                    foreach ($p['votes'] as &$v) {
                        if ($v['id'] === $body['voteId']) {
                            $v['name'] = $name; $v['answers'] = $answers;
                            return [$p, 200, $v['id']];
                        }
                    }
                    throw new HttpError(404, 'No such response');
                }
                if (count($p['votes']) >= 500) throw new HttpError(403, 'Poll is full');
                $v = ['id' => rid(6), 'name' => $name, 'answers' => $answers, 'created' => time()];
                $p['votes'][] = $v;
                return [$p, 201, $v['id']];
            });
            sendJson($status, ['poll' => publicPoll($p), 'voteId' => $voteId]);
        }
    }
    throw new HttpError(404, 'Not found');
}

function page(string $file): void {
    global $BASE;
    header('Content-Type: text/html; charset=utf-8');
    headers_common();
    echo str_replace('__BASE__', $BASE, file_get_contents(__DIR__ . '/views/' . $file));
    exit;
}

// ---------- dispatch ----------
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$viaScript = (bool)preg_match('#^/index\.php(?=/|$)#', $path);
$path = preg_replace('#^/index\.php(?=/|$)#', '', $path) ?: '/'; // /index.php/admin routes like /admin
$BASE = $BASE_CFG ?? ($viaScript ? '/index.php' : '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isApi = strpos($path, '/api/') === 0;
try {
    if ($isApi) api(array_slice(explode('/', trim($path, '/')), 1), $method);
    if ($method !== 'GET' && $method !== 'HEAD') throw new HttpError(405, 'Method not allowed');
    if ($path === '/') page('index.html');
    if ($path === '/admin') page('admin.html');
    if (preg_match('#^/p/[\w-]+$#', $path)) page('poll.html');
    throw new HttpError(404, 'Not found');
} catch (HttpError $e) {
    $status = $e->status; $msg = $e->getMessage();
} catch (Throwable $e) {
    error_log((string)$e);
    $status = 500; $msg = 'Server error';
}
if ($isApi) sendJson($status, ['error' => $msg]);
http_response_code($status);
headers_common();
echo '<h1>' . $status . '</h1><p>' . htmlspecialchars($msg) . '</p>';
