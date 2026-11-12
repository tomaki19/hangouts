<?php
// Hangout (PHP port): front controller for the JSON API and the HTML pages. PHP >= 7.4.
declare(strict_types=1);

// Never print PHP errors into responses (they can reveal file paths); log them instead.
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$cfgFile = is_file(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : null;
if (!$cfgFile) { http_response_code(500); exit('Copy config.sample.php to config.php and set the password.'); }
$cfg = require $cfgFile;
$ADMIN_HASH = (string)($cfg['admin_password_hash'] ?? '');
// Optional fixed URL prefix ('' or '/index.php'). Without it the prefix follows how the page was opened:
// /admin (rewrites work) gives short links, /index.php/admin gives /index.php/... links.
$BASE_CFG = array_key_exists('base', $cfg) ? rtrim((string)$cfg['base'], '/') : null;
$DATA_DIR = rtrim((string)($cfg['data_dir'] ?? (__DIR__ . '/data')), '/');
// Local http development only: drops the Secure flag and the __Host- prefix so the cookie works without HTTPS.
$INSECURE_COOKIE = !empty($cfg['insecure_cookie']);
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
function headers_common(): void {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:");
}
function sendJson(int $status, $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    headers_common();
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function readJson(int $max = 100 * 1024): array {
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) throw new HttpError(415, 'JSON required');
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if (strlen($raw) > $max) throw new HttpError(413, 'Too large');
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
function withHangouts(callable $fn) { global $DATA_DIR; return locked($DATA_DIR . '/hangouts.json', $fn); }

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
// __Host- cookies must be Secure, set with path=/ and no Domain, so subdomains and plain http can't overwrite them
function cookieName(): string { global $INSECURE_COOKIE; return $INSECURE_COOKIE ? 'session' : '__Host-session'; }
function endSession(): void {
    $t = $_COOKIE[cookieName()] ?? '';
    if (!is_string($t) || $t === '') return;
    $h = hash('sha256', $t);
    sessionStore(function (array &$sessions) use ($h) { unset($sessions[$h]); });
}
function isAdmin(): bool {
    $t = $_COOKIE[cookieName()] ?? '';
    if (!is_string($t) || $t === '') return false;
    $h = hash('sha256', $t);
    return sessionStore(fn(array &$sessions) => isset($sessions[$h]) && $sessions[$h] > time());
}
function setSession(?string $value): void {
    global $INSECURE_COOKIE;
    setcookie(cookieName(), $value ?? '', [
        'expires' => $value === null ? time() - 3600 : time() + SESSION_TTL,
        'path' => '/', 'secure' => !$INSECURE_COOKIE, 'httponly' => true, 'samesite' => 'Strict',
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

// ---------- hangout helpers ----------
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
function publicHangout(array $p): array {
    return [
        'id' => $p['id'], 'title' => $p['title'], 'description' => $p['description'],
        'options' => array_values($p['options']), 'closed' => !empty($p['closed']), 'logo' => logoUrl($p['id']),
        'votes' => array_map(fn($v) => ['id' => $v['id'], 'name' => $v['name'], 'answers' => (object)$v['answers']], array_values($p['votes'])),
    ];
}

// ---------- API ----------
// ---------- hangout logos (one optional image per hangout, data/logos/<hangoutId>.dat) ----------
const LOGO_TYPES = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];
const LOGO_MAX_BYTES = 800 * 1024;
const LOGO_SVG_MAX_BYTES = 200 * 1024;
function logoFile(string $hangoutId): string {
    global $DATA_DIR;
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $hangoutId)) throw new HttpError(404, 'No such hangout');
    return $DATA_DIR . '/logos/' . $hangoutId . '.dat';
}
function logoUrl(string $hangoutId): string { // '' when the hangout has no logo; ?v= busts the browser cache after a change
    global $BASE;
    $f = logoFile($hangoutId);
    return is_file($f) ? $BASE . '/api/hangouts/' . $hangoutId . '/logo?v=' . filemtime($f) : '';
}
function sendLogo(string $hangoutId): void {
    $f = logoFile($hangoutId);
    $raw = is_file($f) ? file_get_contents($f) : false;
    if ($raw === false || $raw === '') throw new HttpError(404, 'No logo');
    if ($raw[0] === '<') { // sanitized SVG (raster files never start with '<')
        $mime = 'image/svg+xml';
    } else {
        $info = getimagesizefromstring($raw);
        if (!$info || !in_array($info[2], LOGO_TYPES, true)) throw new HttpError(404, 'No logo');
        $mime = $info['mime'];
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cache-Control: public, max-age=31536000, immutable'); // URL changes with ?v= on update
    echo $raw;
    exit;
}
// Parse an uploaded SVG and strip everything that could run code or load something. Returns the clean markup.
function sanitizeSvg(string $svg): string {
    if (strlen($svg) > LOGO_SVG_MAX_BYTES) throw new HttpError(413, 'SVG too large (max 200 KB)');
    if (!class_exists('DOMDocument')) throw new HttpError(500, 'SVG needs the PHP dom extension');
    // UTF-8 only: other encodings (UTF-16 ...) would hide markup from the text checks below
    if (!mb_check_encoding($svg, 'UTF-8') || strpos($svg, "\0") !== false) throw new HttpError(400, 'SVG must be UTF-8 text');
    if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $svg)) throw new HttpError(400, 'SVG with DOCTYPE or entities is not allowed');
    $css = fn(string $c): bool => (bool)preg_match('/@import|expression\s*\(|javascript:|behavior\s*:|url\(\s*[\'"]?\s*(?!#)/i', $c);
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($ok && $doc->doctype !== null) throw new HttpError(400, 'SVG with DOCTYPE or entities is not allowed'); // also catches what the text check missed
    $root = $ok ? $doc->documentElement : null;
    if (!$root || strtolower($root->localName) !== 'svg' || $root->namespaceURI !== 'http://www.w3.org/2000/svg') throw new HttpError(400, 'Not a valid SVG');
    $banned = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'audio', 'video', 'canvas', 'a', 'animate', 'set', 'animatemotion', 'animatetransform', 'handler', 'listener', 'image'];
    $drop = [];
    foreach ((new DOMXPath($doc))->query('//*') as $el) {
        $name = strtolower($el->localName);
        // only plain SVG elements survive: foreign namespaces (XHTML, editor metadata ...) are removed with their children
        if ($el->namespaceURI !== 'http://www.w3.org/2000/svg') { $drop[] = $el; continue; }
        if (in_array($name, $banned, true) || ($name === 'style' && $css($el->textContent))) { $drop[] = $el; continue; }
        foreach (iterator_to_array($el->attributes) as $attr) {
            $an = strtolower($attr->localName);
            $v = trim($attr->value);
            if (strpos($an, 'on') === 0
                || ($an === 'href' && ($v === '' || $v[0] !== '#'))              // only references to this file's own ids
                || ($an === 'style' && $css($v))
                || preg_match('/javascript:|vbscript:|data:text/i', $v)) {
                $el->removeAttributeNode($attr);
            }
        }
    }
    foreach ($drop as $el) if ($el->parentNode) $el->parentNode->removeChild($el);
    return $doc->saveXML($root);
}

function saveLogo(string $hangoutId, string $dataUrl): void {
    if (!preg_match('#^data:image/(png|jpeg|webp|svg\+xml);base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) throw new HttpError(400, 'Use a PNG, JPEG, WebP or SVG image');
    $raw = base64_decode($m[2], true);
    if ($raw === false) throw new HttpError(400, 'Bad image data');
    if ($m[1] === 'svg+xml') {
        $raw = sanitizeSvg($raw);
    } else {
        if (strlen($raw) > LOGO_MAX_BYTES) throw new HttpError(413, 'Image too large (max 800 KB)');
        $info = getimagesizefromstring($raw); // checks the real content, not the claimed type
        if (!$info || !in_array($info[2], LOGO_TYPES, true)) throw new HttpError(400, 'Not a valid PNG, JPEG, WebP or SVG image');
        if ($info[0] > 2000 || $info[1] > 2000) throw new HttpError(400, 'Image dimensions too large');
    }
    $f = logoFile($hangoutId);
    if (!is_dir(dirname($f))) mkdir(dirname($f), 0755, true);
    $tmp = $f . '.tmp';
    if (file_put_contents($tmp, $raw, LOCK_EX) === false || !rename($tmp, $f)) throw new RuntimeException('Cannot save logo');
}

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
        if ($b !== 'hangouts') throw new HttpError(404, 'Not found');

        if ($c !== null && $d === 'logo' && ($method === 'PUT' || $method === 'DELETE')) {
            if (!withHangouts(fn(array &$hangouts) => isset($hangouts[$c]))) throw new HttpError(404, 'No such hangout');
            if ($method === 'PUT') {
                $body = readJson(1200 * 1024);
                saveLogo($c, is_string($body['image'] ?? null) ? $body['image'] : '');
            } elseif (is_file(logoFile($c))) {
                unlink(logoFile($c));
            }
            sendJson(200, ['logo' => logoUrl($c)]);
        }

        if ($c === null && $method === 'GET') {
            $list = withHangouts(fn(array &$hangouts) => array_values($hangouts));
            usort($list, fn($x, $y) => $y['created'] <=> $x['created']);
            sendJson(200, array_map('publicHangout', $list));
        }
        if ($c === null && $method === 'POST') {
            $body = readJson();
            $title = str($body['title'] ?? '', 120);
            if ($title === '') throw new HttpError(400, 'Title required');
            $p = [
                'id' => rid(9), 'title' => $title, 'description' => str($body['description'] ?? '', 1000),
                'options' => cleanOptions($body['options'] ?? null), 'votes' => [], 'closed' => false, 'created' => time(),
            ];
            withHangouts(function (array &$hangouts) use ($p) { $hangouts[$p['id']] = $p; });
            sendJson(201, publicHangout($p));
        }
        if ($c !== null && $d === null && $method === 'PUT') {
            $body = readJson();
            $title = str($body['title'] ?? '', 120);
            if ($title === '') throw new HttpError(400, 'Title required');
            $p = withHangouts(function (array &$hangouts) use ($c, $body, $title) {
                if (!isset($hangouts[$c])) throw new HttpError(404, 'No such hangout');
                $p = &$hangouts[$c];
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
            sendJson(200, publicHangout($p));
        }
        if ($c !== null && $d === null && $method === 'DELETE') {
            $found = withHangouts(function (array &$hangouts) use ($c) { $f = isset($hangouts[$c]); unset($hangouts[$c]); return $f; });
            if (!$found) throw new HttpError(404, 'No such hangout');
            if (is_file(logoFile($c))) unlink(logoFile($c));
            sendJson(200, ['ok' => true]);
        }
        if ($c !== null && $d === 'votes' && isset($parts[4]) && $method === 'DELETE') {
            $vid = $parts[4];
            $p = withHangouts(function (array &$hangouts) use ($c, $vid) {
                if (!isset($hangouts[$c])) throw new HttpError(404, 'No such hangout');
                $hangouts[$c]['votes'] = array_values(array_filter($hangouts[$c]['votes'], fn($v) => $v['id'] !== $vid));
                return $hangouts[$c];
            });
            sendJson(200, publicHangout($p));
        }
        throw new HttpError(404, 'Not found');
    }

    if ($a === 'hangouts' && $b !== null) {
        if ($c === 'logo' && $method === 'GET') {
            if (!withHangouts(fn(array &$hangouts) => isset($hangouts[$b]))) throw new HttpError(404, 'No such hangout');
            sendLogo($b);
        }
        if ($c === null && $method === 'GET') {
            $p = withHangouts(fn(array &$hangouts) => $hangouts[$b] ?? null);
            if (!$p) throw new HttpError(404, 'No such hangout');
            sendJson(200, publicHangout($p));
        }
        if ($c === 'votes' && $method === 'POST') {
            $body = readJson();
            [$p, $status, $voteId] = withHangouts(function (array &$hangouts) use ($b, $body) {
                if (!isset($hangouts[$b])) throw new HttpError(404, 'No such hangout');
                $p = &$hangouts[$b];
                if (!empty($p['closed'])) throw new HttpError(403, 'Hangout is closed');
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
                if (count($p['votes']) >= 500) throw new HttpError(403, 'Hangout is full');
                $v = ['id' => rid(6), 'name' => $name, 'answers' => $answers, 'created' => time()];
                $p['votes'][] = $v;
                return [$p, 201, $v['id']];
            });
            sendJson($status, ['hangout' => publicHangout($p), 'voteId' => $voteId]);
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
    if (preg_match('#^/p/[\w-]+$#', $path)) page('hangout.html');
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
