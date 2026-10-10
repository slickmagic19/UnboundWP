# UnboundWP - Free Migration and Backup

Free, open source WordPress site export, import, backup, and 1-click restore. No size limits, no paywalls, no accounts.

`.uwp` files are fully `.wpress` compatible. Renaming between `.uwp` and `.wpress` just works.

## Features

* **Export** your full site (database plus wp-content plus WordPress core) into one portable `.uwp` file.
* **Import** any `.uwp` or `.wpress` backup, including files made by All-in-One WP Migration (plain, gzip, and password protected with AES-256-CBC).
* **Backups** page: download, 1-click resumable restore, checksum verify, delete.
* **Logs** page: per job full logs with download and rotation.
* **No limits**: browser uploads arrive in 2 MB slices, export and import run in small resumable steps with server side chaining. Timeouts and upload limits do not stop a run.
* **Safe restores**: serialization safe URL replacement, per file CRC32 checks, path traversal protection, quarantined restore with one by one plugin reactivation, theme fallback, permalink self heal, prefix aware 1:1 restores. The live `wp-config.php` is never overwritten.
* **Diagnostics**: green, amber, and red probes plus a fidelity manifest comparison and homepage smoke test.
* **WP-CLI**: `wp unboundwp export`.
* **No cloud, no accounts, no upsells.**

## Requirements

* WordPress 5.8 or newer
* PHP 7.4 or newer

## Installation

### From WordPress.org (once approved)

1. Go to Plugins then Add New, search for `UnboundWP`.
2. Install and activate.
3. Go to UnboundWP then Export, Import, or Backups.

### From a release zip

1. Download the latest `unboundwp-*.zip` from [Releases](https://github.com/slickmagic19/UnboundWP/releases).
2. Go to Plugins then Add New then Upload Plugin, upload the zip.
3. Activate, then open UnboundWP.

### From source

1. Clone this repo.
2. Copy (or symlink) the `unboundwp/` folder into `wp-content/plugins/`.
3. Activate through Plugins.

## Usage

* **Export**: UnboundWP then Export, then Export Now. Download the finished `.uwp` file when prompted. Finished backups also stay in `wp-content/uploads/unboundwp-backups/`.
* **Import**: UnboundWP then Import, upload a `.uwp` or `.wpress` file, then restore. Interrupted runs show a resume banner.
* **Backups**: download, 1-click restore, verify checksums, delete.
* **Logs**: view, download, or delete per job logs.
* **About**: portability info, reliability notes, and installation versions.

Backups live in `wp-content/uploads/unboundwp-backups/`. Temp work goes to `wp-content/uploads/unboundwp-temp/`.

## Compatibility

`.uwp` IS the `.wpress` format (4377-byte headers) under a new name. UnboundWP reads `.wpress` and writes `.uwp` files that any `.wpress` reader understands.

## Repository layout

```
unboundwp/          The actual plugin (this is what ships to WordPress.org)
  unboundwp.php     Plugin header and bootstrap (version lives here)
  readme.txt        WordPress.org readme (Stable tag lives here)
  includes/         PHP classes (UWP_Archive, UWP_Export, UWP_Import, ...)
  templates/        Admin screens
  assets/           Admin CSS and JS
.wordpress-org/     WordPress.org directory assets (banners, icons)
```

The plugin version is defined in two places, keep them in sync:

* `unboundwp/unboundwp.php` header plus `UWP_VERSION`
* `unboundwp/readme.txt` `Stable tag`
* `unboundwp/mu-loader.php` `UWP_MU_LOADER_VERSION`

## Building a release zip

From the repo root on Windows (PowerShell):

```powershell
Compress-Archive -Path unboundwp -DestinationPath unboundwp-1.0.0.zip -Force
```

Release zips (`*.zip`) are git ignored. Do not commit them.

## Frequently asked questions

**Is there really no size limit?**
Yes. Browsers upload in 2 MB slices and the server appends them, so PHP upload limits never trigger. Export and import each do a few seconds of work per request and resume, so timeouts cannot stall a run either.

**Can I restore .wpress files?**
Yes. UnboundWP reads `.wpress` (plain, gzip, and password protected) and writes `.uwp` files that any `.wpress` reader understands.

**Will restore overwrite my wp-config.php?**
No. The live `wp-config.php` is never overwritten. An archived one is saved as `wp-config.php.restored.php` for inspection.

## Contributing

Issues and pull requests are welcome at [slickmagic19/UnboundWP](https://github.com/slickmagic19/UnboundWP). Please keep changes small, commented, and PHP 7.4 compatible.

## License

GPLv2 or later. See `unboundwp/readme.txt` and the plugin header for details.
