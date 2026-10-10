=== UnboundWP - Free Migration & Backup ===
Contributors: slickmagic19
Tags: migration, backup, restore, export, import
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free, open-source, unlimited site export, import, backup and 1-click restore. .uwp files are fully .wpress compatible.

== Description ==

UnboundWP does what the big migration plugins do - export, import, backup, 1-click restore - but free forever, open source, with no size limits and no paywalls.

* **Export** your full site (database + wp-content) into one portable `.uwp` file.
* **Import** any `.uwp` or `.wpress` backup - including files made by All-in-One WP Migration.
* **Backups** page: download, 1-click restore, delete.
* **No limits**: uploads arrive in 2 MB slices; export/import run in small resumable steps. No gateway timeouts, no "file too large".
* **Safe restores**: serialization-aware URL replacement, per-file CRC32 checks, path-traversal protection, never overwrites your live wp-config.
* **Compatible**: `.uwp` IS the `.wpress` format (4377-byte headers) under a new name - renaming between `.uwp` and `.wpress` just works.
* **No cloud, no accounts, no upsells.** Barebones on purpose - so it stays maintainable.

For developers: clean OOP (`UWP_Archive`, `UWP_Export`, `UWP_Import`, `UWP_Database`), WP-CLI (`wp unboundwp export`), and full inline docs.

== Installation ==

1. Upload the `unboundwp` folder to `/wp-content/plugins/`.
2. Activate through Plugins.
3. Go to UnboundWP → Export / Import / Backups.

== Frequently Asked Questions ==

= Is there really no size limit? =
Yes. Browsers upload in 2 MB slices and the server appends them, so PHP's upload limits never trigger. Export/import each do ~8 seconds of work per request and resume - so timeouts can't happen either.

= Can I restore .wpress files? =
Yes. UnboundWP reads .wpress (plain, gzip, and password-protected) and writes .uwp files that any .wpress reader understands. The extensions are interchangeable.

= Will restore overwrite my wp-config.php? =
No. The live wp-config.php is never overwritten; an archived one is saved as wp-config.php.restored.php for inspection.

= Where are backups stored? =
In `wp-content/uploads/unboundwp-backups/`. Temp work goes to `wp-content/uploads/unboundwp-temp/`.

== Changelog ==

= 1.0.0 =
* Initial WordPress.org release. Full site export (database plus wp-content plus WordPress core) into one portable `.uwp` file, fully `.wpress` compatible.
* Import any `.uwp` or `.wpress` backup (plain, gzip, and password protected with AES-256-CBC), with serialization safe URL replacement, per file CRC32 checks, path traversal protection, and atomic core swap.
* Backups page with download, 1-click resumable restore, checksum verify, and delete. Logs page with per job full logs, download, and rotation.
* No size limits: browser uploads in 2 MB slices, export and import run in small resumable steps with server side chaining and job secret auth that survives session expiry.
* Safe restores: quarantined restore with one by one plugin reactivation, risky plugin handling, theme fallback, permalink self heal, prefix aware 1:1 restores, and live wp-config.php never overwritten.
* Diagnostics with green, amber, and red probes, fidelity manifest comparison, homepage smoke test, and WP-CLI support (`wp unboundwp export`).
