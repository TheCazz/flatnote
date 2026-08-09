# Changelog

## 0.7.0

- Added `data/settings.example.php`; `data/settings.php` is created automatically on first start and is ignored by Git.
- Added GFM-style Markdown tables, including left, center, and right column alignment.
- Added responsive horizontal scrolling for wide tables on small screens.
- Moved all writable FlatNote data into a single `data/` directory.
- Notes are now stored in `data/db/`.
- Settings are now stored in `data/settings.php`.
- Login protection state and activity logs are now stored in `data/runtime/`.
- Application code under `app/` no longer needs write access during normal operation.
- Updated permission and storage documentation for the new layout.



## 0.6.1

- Added the FlatNote application icon for browser favicons and mobile home-screen shortcuts.
- Settings now uses the primary dark button style.
- Back to Settings now uses the primary dark button style.
- Copy direct link now uses the primary dark button style.
- Log out now uses a muted red button style.
- Mobile Settings and Log out actions use matching visual emphasis.

## 0.6.0

- Standardized the Save and View login activity buttons to use the primary dark button style.
- Login activity now records a visitor IP from common reverse-proxy headers when available, while also preserving the actual connection IP from `REMOTE_ADDR`.
- Login cooldown protection continues to use `REMOTE_ADDR` and does not trust proxy headers.
- Failed login messages now show the number of attempts remaining before cooldown.
- Added a 30-day login activity history for successful and failed password logins and successful direct-link logins.
- Added a dedicated Login activity page accessible from Settings → Security.
- Direct-link access can now be disabled without deleting the direct key.
- New direct-link keys now use 32 random characters.
- Existing direct-link keys remain valid until regenerated.
- Optional username can be entered during first setup or later in Settings. Leaving it blank keeps password-only login.
- Added configurable login protection with 1–20 failed attempts and 1–60 minute cooldown.
- Failed login state is stored per `REMOTE_ADDR` in `app/runtime/login.json`.
- Login protection runtime entries older than 24 hours are removed automatically.
- Successful password login clears the failed-attempt state for that IP address.
- Direct-link login does not affect the failed-login counter.
- Fixed first-setup language switching by loading the application JavaScript on the setup page.
