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

function loadSettings(): array
{
    if (!is_file(SETTINGS_FILE)) return ['password_hash' => '', 'direct_key' => '', 'language' => 'en'];
    $settings = require SETTINGS_FILE;
    return is_array($settings) ? array_merge(['password_hash' => '', 'direct_key' => '', 'language' => 'en'], $settings) : ['password_hash' => '', 'direct_key' => '', 'language' => 'en'];
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
    $content = "<?php
return " . var_export([
        'password_hash' => (string)($settings['password_hash'] ?? ''),
        'direct_key' => (string)($settings['direct_key'] ?? ''),
        'language' => (string)($settings['language'] ?? 'en'),
    ], true) . ";
";

    $saved = file_put_contents(SETTINGS_FILE, $content, LOCK_EX) !== false;

    // settings.php skrivs om under drift. Om OPcache används måste den gamla
    // kompilerade versionen ogiltigförklaras direkt.
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

function randomDirectKey(int $length = 15): string
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
        exit('Ogiltig begäran. Ladda om sidan och försök igen.');
    }
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
