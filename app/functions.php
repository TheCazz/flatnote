<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/lib/SimpleMarkdown.php';

function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_DAYS * 86400,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function defaultSettings(): array
{
    return [
        'password_hash' => '',
        'direct_key' => '',
        'direct_link_enabled' => true,
        'language' => 'en',
        'username' => '',
        'login_protection_enabled' => true,
        'login_max_attempts' => 5,
        'login_cooldown_minutes' => 1,
    ];
}

function loadSettings(): array
{
    $defaults = defaultSettings();
    if (!is_file(SETTINGS_FILE)) return $defaults;

    $settings = require SETTINGS_FILE;
    return is_array($settings) ? array_merge($defaults, $settings) : $defaults;
}


function availableLanguages(): array
{
    $languages = [];
    foreach (glob(APP_DIR . '/lang/*.php') ?: [] as $file) {
        $code = basename($file, '.php');
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $code)) continue;
        $data = require $file;
        if (is_array($data) && !empty($data['language_name'])) $languages[$code] = (string)$data['language_name'];
    }
    asort($languages, SORT_NATURAL | SORT_FLAG_CASE);
    return $languages;
}

function loadLanguage(string $code): array
{
    $fallbackFile = APP_DIR . '/lang/en.php';
    $fallback = is_file($fallbackFile) ? require $fallbackFile : [];
    $file = APP_DIR . '/lang/' . basename($code) . '.php';
    $selected = is_file($file) ? require $file : [];
    return array_merge(is_array($fallback) ? $fallback : [], is_array($selected) ? $selected : []);
}

function t(string $key): string
{
    global $lang;
    return (string)($lang[$key] ?? $key);
}

function saveSettings(array $settings): bool
{
    $defaults = defaultSettings();
    $settings = array_merge($defaults, $settings);

    $content = "<?php
return " . var_export([
        'password_hash' => (string)$settings['password_hash'],
        'direct_key' => (string)$settings['direct_key'],
        'direct_link_enabled' => (bool)$settings['direct_link_enabled'],
        'language' => (string)$settings['language'],
        'username' => (string)$settings['username'],
        'login_protection_enabled' => (bool)$settings['login_protection_enabled'],
        'login_max_attempts' => max(1, min(20, (int)$settings['login_max_attempts'])),
        'login_cooldown_minutes' => max(1, min(60, (int)$settings['login_cooldown_minutes'])),
    ], true) . ";
";

    $saved = file_put_contents(SETTINGS_FILE, $content, LOCK_EX) !== false;

    if ($saved && function_exists('opcache_invalidate')) {
        @opcache_invalidate(SETTINGS_FILE, true);
    }

    clearstatcache(true, SETTINGS_FILE);
    return $saved;
}

function isInstalled(): bool
{
    return loadSettings()['password_hash'] !== '';
}

function randomDirectKey(int $length = 32): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) $out .= $alphabet[random_int(0, $max)];
    return $out;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}

function requireCsrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit(t('invalid_request'));
    }
}


function clientIp(): string
{
    // Säkerhetsräknaren använder alltid den faktiska anslutningsadressen.
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function visitorIp(): string
{
    // För loggning försöker vi även hitta klientadressen bakom en reverse proxy.
    // Säkerhetsräknaren använder fortfarande clientIp()/REMOTE_ADDR och påverkas
    // därför inte av förfalskade proxyheaders.
    $candidates = [];

    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidates[] = trim((string)$_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $candidates[] = trim((string)$_SERVER['HTTP_X_REAL_IP']);
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']) as $forwardedIp) {
            $candidates[] = trim($forwardedIp);
        }
    }

    foreach ($candidates as $candidate) {
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    return clientIp();
}

function ensureRuntimeDir(): bool
{
    if (is_dir(RUNTIME_DIR)) return is_writable(RUNTIME_DIR);
    return @mkdir(RUNTIME_DIR, 0775, true);
}

function loadJsonFile(string $file): array
{
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function saveJsonFile(string $file, array $data): bool
{
    if (!ensureRuntimeDir()) return false;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    return file_put_contents($file, $json . "\n", LOCK_EX) !== false;
}

function loadLoginState(): array
{
    return loadJsonFile(LOGIN_STATE_FILE);
}

function saveLoginState(array $state): bool
{
    return saveJsonFile(LOGIN_STATE_FILE, $state);
}

function cleanupLoginState(array $state, ?int $now = null): array
{
    $now ??= time();
    $cutoff = $now - 86400;
    foreach ($state as $ip => $entry) {
        $lastFailed = (int)($entry['last_failed'] ?? 0);
        $lockedUntil = (int)($entry['locked_until'] ?? 0);
        if ($lastFailed < $cutoff && $lockedUntil <= $now) unset($state[$ip]);
    }
    return $state;
}

function loginCooldownRemaining(array $settings, ?string $ip = null): int
{
    if (empty($settings['login_protection_enabled'])) return 0;
    $ip ??= clientIp();
    $now = time();
    $state = cleanupLoginState(loadLoginState(), $now);
    saveLoginState($state);
    return max(0, (int)($state[$ip]['locked_until'] ?? 0) - $now);
}

function recordFailedLogin(array $settings, ?string $ip = null): array
{
    $maxAttempts = max(1, min(20, (int)($settings['login_max_attempts'] ?? 5)));
    if (empty($settings['login_protection_enabled'])) {
        return ['remaining' => $maxAttempts, 'cooldown' => 0];
    }

    $ip ??= clientIp();
    $now = time();
    $state = cleanupLoginState(loadLoginState(), $now);
    $entry = $state[$ip] ?? ['failed_attempts' => 0, 'last_failed' => 0, 'locked_until' => 0];

    $entry['failed_attempts'] = (int)$entry['failed_attempts'] + 1;
    $entry['last_failed'] = $now;

    $cooldownSeconds = max(1, min(60, (int)($settings['login_cooldown_minutes'] ?? 1))) * 60;

    if ($entry['failed_attempts'] >= $maxAttempts) {
        $entry['locked_until'] = $now + $cooldownSeconds;
        $entry['failed_attempts'] = 0;
        $remaining = 0;
    } else {
        $remaining = $maxAttempts - $entry['failed_attempts'];
    }

    $state[$ip] = $entry;
    saveLoginState($state);

    return ['remaining' => $remaining, 'cooldown' => max(0, (int)$entry['locked_until'] - $now)];
}

function resetFailedLogin(?string $ip = null): void
{
    $ip ??= clientIp();
    $state = cleanupLoginState(loadLoginState());
    if (isset($state[$ip])) {
        unset($state[$ip]);
        saveLoginState($state);
    }
}

function cleanupLoginLog(array $entries, ?int $now = null): array
{
    $now ??= time();
    $cutoff = $now - (30 * 86400);
    $entries = array_values(array_filter($entries, static function ($entry) use ($cutoff) {
        return is_array($entry) && (int)($entry['time'] ?? 0) >= $cutoff;
    }));
    usort($entries, static fn($a, $b) => ((int)($b['time'] ?? 0)) <=> ((int)($a['time'] ?? 0)));
    return $entries;
}

function loadLoginLog(): array
{
    $entries = cleanupLoginLog(loadJsonFile(LOGIN_LOG_FILE));
    saveJsonFile(LOGIN_LOG_FILE, $entries);
    return $entries;
}

function logLoginEvent(string $result, string $method, ?string $ip = null): void
{
    $entries = cleanupLoginLog(loadJsonFile(LOGIN_LOG_FILE));
    $connectionIp = $ip ?? clientIp();
    $loggedVisitorIp = $ip ?? visitorIp();

    array_unshift($entries, [
        'time' => time(),
        'ip' => $loggedVisitorIp,
        'connection_ip' => $connectionIp,
        'method' => $method,
        'result' => $result,
    ]);
    saveJsonFile(LOGIN_LOG_FILE, cleanupLoginLog($entries));
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['logged_in']);
}

function loginUser(): void
{
    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    csrfToken();
}

function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function slugify(string $text): string
{
    $text = strtr($text, ['Å'=>'A','Ä'=>'A','Ö'=>'O','å'=>'a','ä'=>'a','ö'=>'o']);
    if (function_exists('iconv')) {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($ascii !== false) $text = $ascii;
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');
    return $text !== '' ? $text : 'sida';
}

function timestampMs(): int
{
    return (int) floor(microtime(true) * 1000);
}

function encodeCategory(string $category): string
{
    // Kategorien ska kunna återställas exakt, även med å, ä, ö och andra UTF-8-tecken.
    // Base64url använder endast filnamnssäkra ASCII-tecken.
    return 'c-' . rtrim(strtr(base64_encode(trim($category)), '+/', '-_'), '=');
}

function decodeCategory(string $value): string
{
    if (!str_starts_with($value, 'c-')) {
        // Bakåtkompatibilitet med filer skapade i v0.5.0.
        return humanizeSlug($value);
    }

    $encoded = substr($value, 2);
    $padding = strlen($encoded) % 4;
    if ($padding !== 0) $encoded .= str_repeat('=', 4 - $padding);

    $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
    return ($decoded !== false && trim($decoded) !== '') ? trim($decoded) : humanizeSlug($value);
}

function buildFilename(string $category, string $title): string
{
    return encodeCategory($category) . '_-_' . timestampMs() . '_-_' . slugify($title) . '.md';
}

function safePagePath(string $filename): ?string
{
    if ($filename !== basename($filename) || !preg_match('/\.md$/i', $filename)) return null;
    $path = DB_DIR . '/' . $filename;
    return is_file($path) ? $path : null;
}

function parseFilename(string $filename): ?array
{
    if (!preg_match('/^(.+?)_-_(\d{10,16})_-_(.+)\.md$/', $filename, $m)) return null;
    return ['category_slug' => $m[1], 'created' => (int)$m[2], 'title_slug' => $m[3]];
}

function humanizeSlug(string $slug): string
{
    return ucfirst(str_replace('-', ' ', $slug));
}

function readPage(string $filename): ?array
{
    $path = safePagePath($filename);
    if (!$path) return null;
    $parts = parseFilename($filename);
    if (!$parts) return null;
    $raw = file_get_contents($path);
    if ($raw === false) return null;
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = explode("\n", $raw);
    $title = humanizeSlug($parts['title_slug']);
    if (isset($lines[0]) && preg_match('/^#\s+(.+)$/u', $lines[0], $m)) {
        $title = trim($m[1]);
        array_shift($lines);
        if (($lines[0] ?? null) === '') array_shift($lines);
    }
    $body = implode("\n", $lines);
    return [
        'filename' => $filename,
        'path' => $path,
        'title' => $title,
        'category' => decodeCategory($parts['category_slug']),
        'category_slug' => $parts['category_slug'],
        'created' => $parts['created'],
        'modified' => (int) filemtime($path),
        'body' => $body,
    ];
}

function getPages(): array
{
    if (!is_dir(DB_DIR)) return [];
    $pages = [];
    foreach (glob(DB_DIR . '/*.md') ?: [] as $path) {
        $page = readPage(basename($path));
        if ($page) $pages[] = $page;
    }
    return $pages;
}

function categoryNameMap(array $pages): array
{
    $map = [];
    foreach ($pages as $page) $map[$page['category_slug']] = $page['category'];
    ksort($map, SORT_NATURAL | SORT_FLAG_CASE);
    return $map;
}

function categoryColorMap(array $pages): array
{
    $firstSeen = [];
    foreach ($pages as $page) {
        $slug = $page['category_slug'];
        $created = $page['created'];
        if (!isset($firstSeen[$slug]) || $created < $firstSeen[$slug]) $firstSeen[$slug] = $created;
    }
    asort($firstSeen, SORT_NUMERIC);
    $map = [];
    $i = 0;
    foreach (array_keys($firstSeen) as $slug) {
        $map[$slug] = ($i % 12) + 1;
        $i++;
    }
    return $map;
}

function savePageFile(string $oldFilename, string $title, string $category, string $body): string
{
    $title = trim($title);
    $category = trim($category);
    if ($title === '' || $category === '') throw new RuntimeException('Rubrik och kategori måste anges.');

    $newFilename = buildFilename($category, $title);
    $newPath = DB_DIR . '/' . $newFilename;
    $content = '# ' . $title . "\n\n" . rtrim($body) . "\n";
    if (file_put_contents($newPath, $content, LOCK_EX) === false) throw new RuntimeException('Kunde inte spara sidan i db/.');

    if ($oldFilename !== '' && $oldFilename !== $newFilename) {
        $oldPath = safePagePath($oldFilename);
        if ($oldPath) @unlink($oldPath);
    }
    return $newFilename;
}

function deletePageFile(string $filename): bool
{
    $path = safePagePath($filename);
    return $path ? unlink($path) : false;
}

function sortPages(array $pages, string $sort): array
{
    usort($pages, function ($a, $b) use ($sort) {
        return match ($sort) {
            'title' => strnatcasecmp($a['title'], $b['title']),
            'created' => $b['created'] <=> $a['created'],
            'modified' => $b['modified'] <=> $a['modified'],
            default => ($c = strnatcasecmp($a['category'], $b['category'])) !== 0 ? $c : strnatcasecmp($a['title'], $b['title']),
        };
    });
    return $pages;
}

function renderMarkdown(string $body): string
{
    return SimpleMarkdown::render($body);
}

function directLink(string $key): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return $scheme . '://' . $host . $path . '?key=' . rawurlencode($key);
}
