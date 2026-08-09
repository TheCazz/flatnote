<?php

define('APP_ROOT', dirname(__DIR__));
define('APP_DIR', __DIR__);
define('DB_DIR', APP_ROOT . '/db');
define('SETTINGS_FILE', APP_DIR . '/settings.php');
define('SESSION_NAME', 'simple_notes_session');
define('SESSION_DAYS', 30);

define('RUNTIME_DIR', APP_DIR . '/runtime');
define('LOGIN_STATE_FILE', RUNTIME_DIR . '/login.json');

define('LOGIN_LOG_FILE', RUNTIME_DIR . '/login-log.json');
