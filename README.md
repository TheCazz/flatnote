# FlatNote

FlatNote is a simple, self-hosted Markdown note app built for speed and simplicity.

## Requirements
- A web server with PHP 8.0 or newer
- Write access for `data/` (FlatNote stores settings, runtime data, and notes there).

## Installation
1. Download the release ZIP.
2. Extract it into a web-accessible directory.
3. Open FlatNote in your browser.
4. Create the installation password.
5. Start writing.

No database, Composer, npm, Node.js, or installation script is required.

## Data
Notes are ordinary `.md` files stored in `data/db/`. FlatNote settings and runtime data are also stored under `data/`, so backing up `data/` preserves the complete installation data.

## Languages
FlatNote ships with English and Swedish. Language files live in `app/lang/`. A new translation can be added by copying a language file and translating its values. `language_name` controls the name shown in Settings.

## License
MIT

## Writable data directory

FlatNote keeps all installation-specific writable data under `data/`:

```text
data/
├── settings.example.php
├── settings.php          # created automatically, not tracked by Git
├── runtime/
└── db/
```

The web server only needs write access to `data/`. The application code under `app/` does not need write access.

For a new installation, upload FlatNote, make `data/` writable by the web server, and open FlatNote in your browser. FlatNote automatically creates `data/settings.php` from `data/settings.example.php`.

For future upgrades, keep your existing `data/` directory. Replace or update the application files without replacing `data/settings.php`, runtime data, or notes.

To reset a forgotten password, edit `data/settings.php` and set `password_hash` to an empty string. Open FlatNote again to create a new password. Other settings are preserved.
