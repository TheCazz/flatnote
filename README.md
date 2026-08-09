# FlatNote

FlatNote is a simple, self-hosted Markdown note app built for speed and simplicity.

## Requirements
- A web server with PHP 8.0 or newer
- Write access for `db/`
- Write access to app/ during first setup so app/settings.php can be created. After setup, only app/settings.php and app/runtime/ need to remain writable.

## Installation
1. Download the release ZIP.
2. Extract it into a web-accessible directory.
3. Open FlatNote in your browser.
4. Create the installation password.
5. Start writing.

No database, Composer, npm, Node.js, or installation script is required.

## Data
Notes are ordinary `.md` files stored in `db/`. Back up that directory to back up your notes.

## Languages
FlatNote ships with English and Swedish. Language files live in `app/lang/`. A new translation can be added by copying a language file and translating its values. `language_name` controls the name shown in Settings.

## License
MIT
