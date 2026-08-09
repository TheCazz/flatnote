<?php

define('APP_ROOT', dirname(__DIR__));
define('APP_DIR', __DIR__);
define('DATA_DIR', APP_ROOT . '/data');
define('DB_DIR', DATA_DIR . '/db');
define('SETTINGS_FILE', DATA_DIR . '/settings.php');
define('SESSION_NAME', 'simple_notes_session');
define('SESSION_DAYS', 30);

define('RUNTIME_DIR', DATA_DIR . '/runtime');
define('LOGIN_STATE_FILE', RUNTIME_DIR . '/login.json');

define('LOGIN_LOG_FILE', RUNTIME_DIR . '/login-log.json');
