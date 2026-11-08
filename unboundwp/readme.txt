=== UnboundWP - Free Migration & Backup ===
Contributors: slickmagic19
Tags: migration, backup, restore, export, import
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free, open-source, unlimited site export, import, backup and 1-click restore. .uwp files are fully .wpress compatible.

== Description ==

UnboundWP does what the big migration plugins do - export, import, backup, 1-click restore - but free forever, open source, with no size limits and no paywalls.

* **Export** your full site (database, wp-content and WordPress core) into one portable `.uwp` file.
* **Import** any `.uwp` or `.wpress` backup - including files made by All-in-One WP Migration (plain, gzip and password protected).
* **Backups** page: download, 1-click restore that starts immediately, verify checksums, delete.
* **Logs** page: complete per-job logs you can view, download and delete.
* **No limits**: uploads arrive in auto-shrinking slices; export/import run in small resumable steps. No gateway timeouts, no "file too large", and no 300 MB import cap.
* **Resumable and self-checking**: atomic job state, per-file CRC32, resume from the exact byte/file, and a failed archive write is rolled back or rebuilt instead of producing a corrupt backup.
* **Safe restores**: serialization-aware URL replacement, path-traversal protection, quarantined restore with crash-isolated plugin reactivation, theme fallback, permalink self-heal, table-prefix aware 1:1 restores, never overwrites your live wp-config.
* **Compatible**: `.uwp` IS the `.wpress` format (4377-byte headers) under a new name - renaming between `.uwp` and `.wpress` just works.
* **Diagnostics** built in: one click probes PHP, database, locking, loopback, free space and does an archive write/read roundtrip.
* **No cloud, no accounts, no upsells.** Barebones on purpose - so it stays maintainable.

For developers: clean OOP (`UWP_Archive`, `UWP_Export`, `UWP_Import`, `UWP_Database`), WP-CLI (`wp unboundwp export|import|verify|list`), and full inline docs.

== Installation ==

1. Upload the `unboundwp` folder to `/wp-content/plugins/`.
2. Activate through Plugins.
3. Go to UnboundWP → Export / Import / Backups.

== Frequently Asked Questions ==

= Is there really no size limit? =
Yes. Browsers upload in 8 MB slices (halved automatically when a host is stricter) and the server appends them, so PHP's upload limits never trigger. Export/import each do ~8 seconds of work per request and resume - so timeouts can't happen either.

= Can I restore .wpress files? =
Yes. UnboundWP reads .wpress (plain, gzip, and password-protected) and writes .uwp files that any .wpress reader understands. The extensions are interchangeable.

= Will restore overwrite my wp-config.php? =
No. The live wp-config.php is never overwritten; an archived one is saved as wp-config.php.restored.php for inspection.

= Where are backups stored? =
In `wp-content/uploads/unboundwp-backups/`. Temp work goes to `wp-content/uploads/unboundwp-temp/`.

== Changelog ==

= 1.1.2 =
* Fixed: a restore could reactivate a hosting panel's own plugin (Hostinger, WP Engine, GoDaddy, Kinsta and similar) on a different server. Those plugins only run on their own host, so they answered HTTP 500 on every request - including wp-admin - leaving a "restored" site nobody could open while the browser retried forever. Host-panel plugins are now always left inactive and listed in the completion message.
* Every plugin activation is now verified with a cheap bootstrap request (admin-ajax). If the site answers HTTP 5xx with that plugin active, it is deactivated again on the spot and reported, so one incompatible plugin cannot brick the restore.
* Before reactivating anything, the site is checked with the backup's own active plugin list. If it does not boot, all plugins are left inactive so wp-admin stays reachable, and the completion message says exactly what was turned off.
* If the final homepage check returns HTTP 500, plugins are quarantined the same way and the result is reported instead of claiming success on a dead site.
* The restore progress note explains a sustained outage (open the site in a new tab; a plugin may be crashing it) instead of only counting retries.

= 1.1.1 =
* Fixed: a large restore could stop with "502 Bad Gateway" when the host recycled its PHP worker mid-upload. Uploads now use 8 MB slices that halve automatically on stricter hosts, retry a gateway error instead of aborting, and resume from the server's byte count after a reload - a 490 MB migration no longer starts over.
* Cross-server restores: charsets/collations this database does not support (e.g. MariaDB 11's utf8mb3_uca1400_ai_ci) are mapped to the closest supported equivalent, and a schema statement the server rejects now stops with the real reason instead of silently completing with missing tables. Row-level database errors are reported in the completion notes.
* Windows hosts: file names Windows cannot store (trailing dot/space, reserved device names) and file/folder name conflicts are skipped with a clear note instead of aborting the restore; long Linux paths restore correctly.
* Fixed: after a cross-domain restore the URL replacement could be reported (or re-written) from a stale options cache; finalize now flushes the cache before verifying.
* Dashboard assets are cache-busted by file date, so updated JavaScript takes effect immediately after an upgrade.

= 1.1.0 =
* Reliability: single-pass archive writer (about twice as fast), checksum backpatching, and automatic rollback plus rebuild whenever a write fails mid-entry - a backup can no longer be silently corrupted.
* Import: real streaming SQL parser (quote, comment and DELIMITER aware), so multi-line statements, semicolons or newlines inside values, and mysqldump extended inserts restore correctly. A truncated dump is reported instead of imported halfway.
* Import: file work lists now live on disk (copylist.tsv / corelist.tsv). Job state stays a few KB on sites with 100k+ files, and a resumed restore skips files already copied.
* Import: string primary keys are paginated correctly during URL replacement; multi-row INSERTs get prefix-correct option and meta keys.
* Safety: free-space checks before unpacking and copying, atomic writes for small files, adaptive time budget on strict hosts, atomic job-state writes.
* New restore option: keep the backup's original URLs (for same-domain restores).
* Backups page: Restore now starts the restore directly after confirmation.
* Uploaded archives are adopted into the Backups page instead of lingering in temp; abandoned temp work and old uploads are pruned automatically.
* WP-CLI: `wp unboundwp import`, `wp unboundwp verify`, and `--password` for export/import.
* Diagnostics: archive write/read roundtrip probe plus a server-limits and free-space probe.
* Fixes: uninstall temp cleanup never actually ran; the export skip list never matched (cache/backup folders were archived anyway); an old backup can no longer overwrite the resurrection loader.

= 1.0.1 =
* Fixed release zip built with explicit top-level `unboundwp/` directory entry so WordPress upload installs reliably on all hosts. No functional changes.

= 1.0.0 =
* Initial WordPress.org release. Full site export (database plus wp-content plus WordPress core) into one portable `.uwp` file, fully `.wpress` compatible.
* Import any `.uwp` or `.wpress` backup (plain, gzip, and password protected with AES-256-CBC), with serialization safe URL replacement, per file CRC32 checks, path traversal protection, and atomic core swap.
* Backups page with download, 1-click resumable restore, checksum verify, and delete. Logs page with per job full logs, download, and rotation.
* No size limits: browser uploads in 2 MB slices, export and import run in small resumable steps with server side chaining and job secret auth that survives session expiry.
* Safe restores: quarantined restore with one by one plugin reactivation, risky plugin handling, theme fallback, permalink self heal, prefix aware 1:1 restores, and live wp-config.php never overwritten.
* Diagnostics with green, amber, and red probes, fidelity manifest comparison, homepage smoke test, and WP-CLI support (`wp unboundwp export`).
