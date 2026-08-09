<?php
require_once __DIR__ . '/app/functions.php';
startAppSession();

if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
if (!is_dir(DB_DIR)) @mkdir(DB_DIR, 0775, true);
if (!is_dir(RUNTIME_DIR)) @mkdir(RUNTIME_DIR, 0775, true);

$settings = loadSettings();

$requestedLanguage = (string)($_GET['lang'] ?? '');
$availableLanguages = availableLanguages();
$activeLanguage = (string)($settings['language'] ?? 'en');

if (!isInstalled() && $requestedLanguage !== '' && isset($availableLanguages[$requestedLanguage])) {
    $activeLanguage = $requestedLanguage;
}

$lang = loadLanguage($activeLanguage);
$error = '';
$message = '';

if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
if (!is_dir(DB_DIR)) @mkdir(DB_DIR, 0775, true);
if (!is_dir(RUNTIME_DIR)) @mkdir(RUNTIME_DIR, 0775, true);

// Direktlänk: logga in och ta bort nyckeln ur adressfältet.
if (isset($_GET['key']) && isInstalled()) {
    $key = (string) $_GET['key'];
    if (!empty($settings['direct_link_enabled'])
        && $settings['direct_key'] !== ''
        && hash_equals($settings['direct_key'], $key)) {
        logLoginEvent('success', 'direct_link');
        loginUser();
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $error = t('invalid_direct_link');
}

// Första installation.
if (!isInstalled() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'setup') {
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');
    $setupLanguage = (string)($_POST['language'] ?? $activeLanguage);
    $username = trim((string)($_POST['username'] ?? ''));
    $availableSetupLanguages = availableLanguages();
    if (!isset($availableSetupLanguages[$setupLanguage])) $setupLanguage = 'en';
    if ($password === '' || strlen($password) < 6) {
        $error = t('password_min');
    } elseif ($password !== $password2) {
        $error = t('password_mismatch');
    } elseif (!is_writable(DATA_DIR)) {
        $error = t('app_not_writable');
    } else {
        $settings['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $settings['direct_key'] = randomDirectKey();
        $settings['direct_link_enabled'] = true;
        $settings['language'] = $setupLanguage;
        $settings['username'] = $username;
        $settings['login_protection_enabled'] = true;
        $settings['login_max_attempts'] = 5;
        $settings['login_cooldown_minutes'] = 1;
        if (!saveSettings($settings)) {
            $error = t('settings_save_error');
        } else {
            loginUser();
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
    }
}

// Vanlig login.
if (isInstalled() && !isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $remaining = loginCooldownRemaining($settings);

    if ($remaining > 0) {
        $error = sprintf(t('login_locked'), $remaining);
    } else {
        $password = (string)($_POST['password'] ?? '');
        $username = trim((string)($_POST['username'] ?? ''));

        $configuredUsername = trim((string)($settings['username'] ?? ''));
        $usernameOk = $configuredUsername === ''
            || hash_equals($configuredUsername, $username);
        $passwordOk = password_verify($password, $settings['password_hash']);

        if ($usernameOk && $passwordOk) {
            resetFailedLogin();
            logLoginEvent('success', 'password');
            loginUser();
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }

        logLoginEvent('failed', 'password');
        $attempt = recordFailedLogin($settings);
        if ($attempt['cooldown'] > 0) {
            $error = sprintf(t('login_locked'), $attempt['cooldown']);
        } else {
            $error = t('wrong_credentials') . ' ' . sprintf(t('attempts_remaining'), $attempt['remaining']);
        }
    }
}

// Logout.
if (isset($_GET['logout'])) {
    logoutUser();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if (!isInstalled() || !isLoggedIn()) {
    ?><!DOCTYPE html>
<html lang="<?= h($activeLanguage) ?>"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(APP_NAME) ?></title><link rel="stylesheet" href="app/css/style.css">
<link rel="icon" href="app/icons/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="app/icons/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="192x192" href="app/icons/icon-192.png">
<link rel="apple-touch-icon" sizes="180x180" href="app/icons/apple-touch-icon">
</head><body class="auth-body">
<div class="auth-card">
    <h1><?= h(APP_NAME) ?></h1>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
    <?php if (!isInstalled()): ?>
        <p><?= h(t('first_start')) ?></p>
        <form method="post" class="auth-form" action="?lang=<?= rawurlencode($activeLanguage) ?>">
            <input type="hidden" name="action" value="setup">
            <label><?= h(t('language')) ?>
                <select name="language" id="setupLanguage">
                    <?php foreach (availableLanguages() as $code => $name): ?>
                        <option value="<?= h($code) ?>" <?= $code === $activeLanguage ? 'selected' : '' ?>><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?= h(t('username_optional')) ?>
                <input type="text" name="username" autocomplete="username">
            </label>

            <label><?= h(t('password')) ?><input type="password" name="password" required autofocus></label>
            <label><?= h(t('repeat_password')) ?><input type="password" name="password2" required></label>
            <button class="btn primary" type="submit"><?= h(t('create_installation')) ?></button>
        </form>
        <div class="setup-info success-info">
            <span class="setup-info-icon">✓</span>
            <span><?= h(t('direct_key_setup_help')) ?></span>
        </div>
    <?php else: ?>
        <form method="post" class="auth-form">
            <input type="hidden" name="action" value="login">
            <?php if (trim((string)($settings['username'] ?? '')) !== ''): ?>
                <label><?= h(t('username')) ?><input type="text" name="username" required autofocus></label>
                <label><?= h(t('password')) ?><input type="password" name="password" required></label>
            <?php else: ?>
                <label><?= h(t('password')) ?><input type="password" name="password" required autofocus></label>
            <?php endif; ?>
            <button class="btn primary" type="submit"><?= h(t('login')) ?></button>
        </form>
    <?php endif; ?>
    <div class="auth-version">v<?= h(APP_VERSION) ?></div>
</div>
<script src="app/js/app.js?v=<?= rawurlencode(APP_VERSION) ?>"></script>
</body></html><?php
    exit;
}

// Inloggade POST-actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_page') {
            $old = (string)($_POST['old_filename'] ?? '');
            $filename = savePageFile($old, (string)($_POST['title'] ?? ''), (string)($_POST['category'] ?? ''), (string)($_POST['body'] ?? ''));
            header('Location: ?page=' . rawurlencode($filename) . '&saved=1');
            exit;
        }
        if ($action === 'delete_page') {
            $filename = (string)($_POST['filename'] ?? '');
            deletePageFile($filename);
            header('Location: ?deleted=1');
            exit;
        }
        if ($action === 'change_password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');
            $new2 = (string)($_POST['new_password2'] ?? '');
            if (!password_verify($current, $settings['password_hash'])) throw new RuntimeException(t('current_password_wrong'));
            if (strlen($new) < 6) throw new RuntimeException(t('new_password_min'));
            if ($new !== $new2) throw new RuntimeException(t('new_password_mismatch'));
            $settings['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
            if (!saveSettings($settings)) throw new RuntimeException(t('password_save_error'));
            $message = t('password_changed');
        }
        if ($action === 'save_direct_link') {
            $settings['direct_link_enabled'] = isset($_POST['direct_link_enabled']);
            if (!saveSettings($settings)) throw new RuntimeException(t('settings_save_error'));
            $message = t('direct_link_setting_saved');
        }
        if ($action === 'new_direct_key') {
            $settings['direct_key'] = randomDirectKey();
            if (!saveSettings($settings)) throw new RuntimeException(t('key_save_error'));
            $message = t('key_changed');
        }
        if ($action === 'save_security') {
            $username = trim((string)($_POST['username'] ?? ''));
            $protectionEnabled = isset($_POST['login_protection_enabled']);
            $maxAttempts = (int)($_POST['login_max_attempts'] ?? 5);
            $cooldownMinutes = (int)($_POST['login_cooldown_minutes'] ?? 1);

            if ($maxAttempts < 1 || $maxAttempts > 20) throw new RuntimeException(t('attempts_range'));
            if ($cooldownMinutes < 1 || $cooldownMinutes > 60) throw new RuntimeException(t('cooldown_range'));

            $settings['username'] = $username;
            $settings['login_protection_enabled'] = $protectionEnabled;
            $settings['login_max_attempts'] = $maxAttempts;
            $settings['login_cooldown_minutes'] = $cooldownMinutes;

            if (!saveSettings($settings)) throw new RuntimeException(t('settings_save_error'));
            $message = t('security_saved');
        }
        if ($action === 'change_language') {
            $language = (string)($_POST['language'] ?? 'en');
            $available = availableLanguages();
            if (!isset($available[$language])) throw new RuntimeException('Invalid language.');
            $settings['language'] = $language;
            if (!saveSettings($settings)) throw new RuntimeException('Could not save language.');
            header('Location: ?action=settings&lang_saved=' . rawurlencode($language));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $settings = loadSettings();
    $lang = loadLanguage((string)($settings['language'] ?? 'en'));
}

$pages = getPages();
$sort = (string)($_GET['sort'] ?? 'category');
if (!in_array($sort, ['category','title','created','modified'], true)) $sort = 'category';
$pages = sortPages($pages, $sort);
$categories = categoryNameMap($pages);
$categoryColors = categoryColorMap($pages);

$current = null;
if (isset($_GET['page'])) $current = readPage((string)$_GET['page']);
if (!$current && $pages) $current = $pages[0];

$mode = (string)($_GET['action'] ?? 'view');
$isNew = $mode === 'new';
$isEdit = $mode === 'edit' && $current;
$settingsOpen = $mode === 'settings';
$loginLogOpen = $mode === 'login_log';

if (isset($_GET['saved'])) $message = t('saved');
if (isset($_GET['deleted'])) $message = t('deleted');
?><!DOCTYPE html>
<html lang="<?= h($settings['language'] ?? 'en') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(APP_NAME) ?><?= $current ? ' – ' . h($current['title']) : '' ?></title>
<link rel="stylesheet" href="app/css/style.css">
<link rel="icon" href="app/icons/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="app/icons/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="192x192" href="app/icons/icon-192.png">
<link rel="apple-touch-icon" sizes="180x180" href="app/icons/apple-touch-icon">
</head>
<body>
<div class="app">
<header class="topbar">
    <div class="brand"><button class="menu-toggle" id="menuToggle" aria-label="Öppna meny">☰</button><span><?= h(APP_NAME) ?></span></div>
    <div class="top-actions desktop-actions">
        <a class="btn primary" href="?action=new"><?= h(t('new_page')) ?></a>
        <a class="btn primary" href="?action=settings"><?= h(t('settings')) ?></a>
        <a class="btn danger" href="?logout=1"><?= h(t('logout')) ?></a>
    </div>
    <div class="mobile-actions">
        <a class="btn primary mobile-new" href="?action=new" title="Ny sida">+</a>
        <button class="btn mobile-more" id="mobileMore" title="Fler alternativ">⋮</button>
        <div class="mobile-menu" id="mobileMenu">
            <a class="mobile-primary" href="?action=settings"><?= h(t('settings')) ?></a>
            <a class="mobile-danger" href="?logout=1"><?= h(t('logout')) ?></a>
        </div>
    </div>
</header>

<div class="layout">
<aside class="sidebar" id="sidebar">
    <input class="search" id="pageSearch" type="search" placeholder="<?= h(t('search')) ?>">
    <div class="sort-row"><label for="sortSelect"><?= h(t('sorting')) ?></label>
        <select id="sortSelect">
            <option value="category" <?= $sort==='category'?'selected':'' ?>><?= h(t('category')) ?></option>
            <option value="title" <?= $sort==='title'?'selected':'' ?>><?= h(t('az')) ?></option>
            <option value="created" <?= $sort==='created'?'selected':'' ?>><?= h(t('newest')) ?></option>
            <option value="modified" <?= $sort==='modified'?'selected':'' ?>><?= h(t('modified')) ?></option>
        </select>
    </div>

    <?php if (!$pages): ?><div class="empty-sidebar"><?= h(t('no_pages')) ?></div><?php endif; ?>
    <?php
    $lastCategory = null;
    foreach ($pages as $page):
        if ($sort === 'category' && $page['category_slug'] !== $lastCategory):
            $lastCategory = $page['category_slug']; ?>
            <div class="category-title page-filter-item" data-search="<?= h(strtolower($page['category'].' '.$page['title'])) ?>">
                <span class="color-bar cat-<?= ($categoryColors[$page['category_slug']] ?? 1) ?>"></span><?= h($page['category']) ?>
            </div>
        <?php endif; ?>
        <a class="page-link page-filter-item <?= $current && $current['filename']===$page['filename']?'active':'' ?>" data-search="<?= h(strtolower($page['category'].' '.$page['title'])) ?>" href="?page=<?= rawurlencode($page['filename']) ?>&sort=<?= h($sort) ?>">
            <?php if ($sort !== 'category'): ?><span class="mini-color cat-<?= ($categoryColors[$page['category_slug']] ?? 1) ?>"></span><?php endif; ?><?= h($page['title']) ?>
        </a>
    <?php endforeach; ?>
</aside>

<main class="content"><div class="note-shell">
<?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
<?php if ($message): ?><div class="alert success" id="flashMessage"><?= h($message) ?></div><?php endif; ?>

<?php if ($loginLogOpen):
    $loginEntries = loadLoginLog();
?>
    <div class="note-header">
        <div>
            <div class="note-category"><?= h(t('security')) ?></div>
            <h1 class="note-title"><?= h(t('login_activity')) ?></h1>
        </div>
        <a class="btn primary" href="?action=settings">← <?= h(t('back_to_settings')) ?></a>
    </div>
    <section class="note-card">
        <p class="help"><?= h(t('login_activity_retention')) ?></p>
        <p class="help"><?= h(t('proxy_ip_help')) ?></p>
        <?php if (!$loginEntries): ?>
            <p><?= h(t('no_login_activity')) ?></p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="login-table">
                    <thead><tr>
                        <th><?= h(t('date_time')) ?></th>
                        <th><?= h(t('ip_address')) ?></th>
                        <th><?= h(t('connection_ip')) ?></th>
                        <th><?= h(t('method')) ?></th>
                        <th><?= h(t('result')) ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($loginEntries as $entry): ?>
                        <tr>
                            <td><?= h(date('Y-m-d H:i:s', (int)($entry['time'] ?? 0))) ?></td>
                            <td><?= h((string)($entry['ip'] ?? '')) ?></td>
                            <td><?= h((string)($entry['connection_ip'] ?? ($entry['ip'] ?? ''))) ?></td>
                            <td><?= h(($entry['method'] ?? '') === 'direct_link' ? t('direct_link_method') : t('password_method')) ?></td>
                            <td><span class="login-result <?= ($entry['result'] ?? '') === 'success' ? 'ok' : 'fail' ?>"><?= h(($entry['result'] ?? '') === 'success' ? t('success') : t('failed')) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php elseif ($settingsOpen): ?>
    <div class="note-header"><div><div class="note-category">Installation</div><h1 class="note-title"><?= h(t('settings')) ?></h1></div></div>
    <section class="note-card settings-grid">
        <div class="settings-section"><h2><?= h(t('change_password')) ?></h2>
            <form method="post" class="settings-form">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="change_password">
                <label><?= h(t('current_password')) ?><input type="password" name="current_password" required></label>
                <label><?= h(t('new_password')) ?><input type="password" name="new_password" required></label>
                <label><?= h(t('repeat_new_password')) ?><input type="password" name="new_password2" required></label>
                <button class="btn primary" type="submit"><?= h(t('change_password')) ?></button>
            </form>
        </div>
        <div class="settings-section"><h2><?= h(t('direct_link')) ?></h2>
            <p class="help"><?= h(t('direct_help')) ?></p>
            <p class="help"><?= h(t('direct_key_length_help')) ?></p>
            <form method="post" class="settings-form compact-form">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="save_direct_link">
                <label class="checkbox-label">
                    <input type="checkbox" name="direct_link_enabled" value="1" <?= !empty($settings['direct_link_enabled']) ? 'checked' : '' ?>>
                    <span><?= h(t('enable_direct_link')) ?></span>
                </label>
                <button class="btn primary" type="submit"><?= h(t('save')) ?></button>
            </form>
            <?php if (!empty($settings['direct_link_enabled'])): ?>
                <div class="copy-row"><input id="directLink" readonly value="<?= h(directLink($settings['direct_key'])) ?>"><button class="btn primary" type="button" id="copyDirectLink"><?= h(t('copy')) ?></button></div>
                <form method="post" onsubmit="return confirm(<?= h(json_encode(t('old_key_warning'), JSON_UNESCAPED_UNICODE)) ?>);">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="new_direct_key">
                    <button class="btn primary" type="submit"><?= h(t('new_direct_key')) ?></button>
                </form>
            <?php else: ?>
                <p class="help"><?= h(t('direct_link_disabled')) ?></p>
            <?php endif; ?>
        </div>
        <div class="settings-section"><h2><?= h(t('security')) ?></h2>
            <form method="post" class="settings-form">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="save_security">

                <label><?= h(t('username_optional')) ?>
                    <input type="text" name="username" value="<?= h((string)($settings['username'] ?? '')) ?>" autocomplete="username">
                </label>
                <p class="help"><?= h(t('username_help')) ?></p>

                <hr class="settings-divider">

                <label class="checkbox-label">
                    <input type="checkbox" name="login_protection_enabled" value="1" <?= !empty($settings['login_protection_enabled']) ? 'checked' : '' ?>>
                    <span><?= h(t('enable_login_protection')) ?></span>
                </label>
                <label><?= h(t('failed_attempts')) ?>
                    <input type="number" name="login_max_attempts" min="1" max="20" value="<?= h((string)$settings['login_max_attempts']) ?>" required>
                </label>
                <label><?= h(t('cooldown_minutes')) ?>
                    <input type="number" name="login_cooldown_minutes" min="1" max="60" value="<?= h((string)$settings['login_cooldown_minutes']) ?>" required>
                </label>
                <p class="help"><?= h(t('login_protection_help')) ?></p>
                <button class="btn primary" type="submit"><?= h(t('save_security')) ?></button>
            </form>
            <div class="security-log-link">
                <h3><?= h(t('login_activity')) ?></h3>
                <p class="help"><?= h(t('login_activity_help')) ?></p>
                <a class="btn primary" href="?action=login_log"><?= h(t('view_login_activity')) ?></a>
            </div>
        </div>
        <div class="settings-section"><h2><?= h(t('language')) ?></h2>
            <form method="post" class="settings-form">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="change_language">
                <label><?= h(t('language')) ?>
                    <select name="language">
                        <?php foreach (availableLanguages() as $code => $name): ?>
                            <option value="<?= h($code) ?>" <?= ($settings['language'] ?? 'en') === $code ? 'selected' : '' ?>><?= h($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn primary" type="submit"><?= h(t('save_language')) ?></button>
            </form>
        </div>
        <div class="settings-section"><h2><?= h(t('about')) ?></h2>
            <p class="help about-text"><?= h(t('about_text')) ?></p>
            <p class="help"><strong>FlatNote v<?= h(APP_VERSION) ?></strong><br>License: MIT<br>GitHub: github.com/TheCazz/flatnote</p>
        </div>
    </section>
<?php elseif ($isNew || $isEdit):
    $editPage = $isEdit ? $current : null;
    $editTitle = $editPage['title'] ?? '';
    $editCategory = $editPage['category'] ?? (array_values($categories)[0] ?? '');
    $editBody = $editPage['body'] ?? '';
?>
    <div class="note-header"><div><div class="note-category"><?= h($isNew ? t('new_page_label') : t('edit_mode')) ?></div><h1 class="note-title"><?= h($isNew ? t('create_page') : t('edit_page')) ?></h1></div></div>
    <form method="post" class="note-card editor-form" id="editorForm">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="save_page"><input type="hidden" name="old_filename" value="<?= h($editPage['filename'] ?? '') ?>">
        <div class="editor-meta">
            <input type="text" name="title" value="<?= h($editTitle) ?>" placeholder="<?= h(t('title')) ?>" required autofocus>
            <input
                id="categoryInput"
                type="text"
                name="category"
                list="categoryList"
                value="<?= h($editCategory) ?>"
                placeholder="<?= h(t('category')) ?>"
                autocomplete="off"
                required
            >
            <datalist id="categoryList">
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= h($cat) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="toolbar">
            <button class="tool" type="button" data-wrap="**" title="<?= h(t('bold')) ?>"><strong>B</strong></button>
            <button class="tool" type="button" data-wrap="*" title="<?= h(t('italic')) ?>"><em>I</em></button>
            <button class="tool" type="button" data-wrap="~~" title="<?= h(t('strike')) ?>"><s>S</s></button>
            <button class="tool" type="button" data-prefix="- " title="<?= h(t('bullet_list')) ?>">•</button>
            <button class="tool" type="button" data-prefix="- [ ] " title="<?= h(t('checklist')) ?>">☑</button>
            <button class="tool" type="button" id="insertTableBtn" title="<?= h(t('table')) ?>">▦</button>
        </div>
        <textarea name="body" id="editorText" placeholder="<?= h(t('start_writing')) ?>"><?= h($editBody) ?></textarea>
        <div class="editor-actions">
            <a class="btn danger" id="cancelEdit" href="<?= $editPage ? '?page='.rawurlencode($editPage['filename']) : '?' ?>"><?= h(t('cancel')) ?></a>
            <button class="btn primary" type="submit"><?= h(t('save')) ?></button>
        </div>
    </form>
<?php elseif ($current): ?>
    <div class="note-header">
        <div class="note-title-wrap"><div class="note-category"><span class="color-bar cat-<?= ($categoryColors[$current['category_slug']] ?? 1) ?>"></span><?= h($current['category']) ?></div><h1 class="note-title"><?= h($current['title']) ?></h1></div>
        <div class="note-actions"><a class="btn primary" href="?page=<?= rawurlencode($current['filename']) ?>&action=edit"><?= h(t('edit')) ?></a><button class="btn danger-outline" type="button" data-modal-open="deleteModal"><?= h(t('delete')) ?></button></div>
    </div>
    <article class="note-card markdown-body"><?= renderMarkdown($current['body']) ?></article>
    <div class="page-meta"><?= h(t('created')) ?> <?= date('Y-m-d H:i', (int) floor($current['created']/1000)) ?> · <?= h(t('changed')) ?> <?= date('Y-m-d H:i', $current['modified']) ?></div>
<?php else: ?>
    <div class="welcome note-card"><h1><?= h(t('no_pages_title')) ?></h1><p><?= h(t('no_pages_help')) ?></p><a class="btn primary" href="?action=new"><?= h(t('new_page')) ?></a></div>
<?php endif; ?>
</div></main>
</div>
<footer class="footer">v<?= h(APP_VERSION) ?></footer>
</div>
<div class="scrim" id="scrim"></div>


<?php if ($current): ?><div class="modal" id="deleteModal" aria-hidden="true"><div class="modal-card"><h2><?= h(t('delete_page')) ?></h2><p><?= h(t('delete_named')) ?> <strong><?= h($current['title']) ?></strong>? <?= h(t('cannot_undo')) ?></p><form method="post"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="delete_page"><input type="hidden" name="filename" value="<?= h($current['filename']) ?>"><div class="modal-actions"><button class="btn danger" type="button" data-modal-close><?= h(t('cancel')) ?></button><button class="btn danger" type="submit"><?= h(t('delete')) ?></button></div></form></div></div><?php endif; ?>

<script src="app/js/app.js?v=<?= rawurlencode(APP_VERSION) ?>"></script>
</body></html>
