<?php

// FlatNote default settings.
//
// This file is only a template for new installations.
// FlatNote creates data/settings.php from this file on first start.
//
// PASSWORD RESET:
// If you forget your password, edit data/settings.php and change
// password_hash to an empty string:
//
//     'password_hash' => '',
//
// Then open FlatNote again and create a new password.
// Other settings are preserved.

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
