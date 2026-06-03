=== WP Drive ===
Contributors: taahzino
Tags: google drive, file manager, upload, cloud storage
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to Google Drive. Browse your uploads, select files or folders, and upload them directly to Drive with a live progress bar.

== Description ==

WP Drive adds a full-featured file manager to your WordPress admin that lets you:

* **Browse** your WordPress uploads directory in a clean, modern list or grid view.
* **Select** individual files or entire folders with multi-select checkboxes.
* **Navigate** your Google Drive folder tree and choose exactly where files land.
* **Upload** selected items to Google Drive with per-file progress tracking and an overall progress bar.

= Key Features =

* Installation wizard that guides you through Google Cloud Console setup step by step.
* Secure OAuth 2.0 authorization — tokens stored AES-256 encrypted using your WP security keys.
* Resumable Google Drive uploads (5 MB chunks) for reliable large-file handling.
* Folder uploads maintain original directory structure on Drive.
* Per-file status indicators: Pending → Uploading → Done / Failed.
* No page reloads during upload — pure asynchronous progress polling.

= Requirements =

* WordPress 5.8+
* PHP 7.4+ with `openssl`, `curl`, `json` extensions
* A Google Cloud project with Drive API enabled and OAuth 2.0 credentials

See the plugin's DEV-SETUP guide for full Google Cloud Console setup instructions.

== Installation ==

1. Upload the `wp-drive-taahzino` folder to `/wp-content/plugins/`.
2. Run `composer install` inside the plugin folder to install PHP dependencies.
3. Activate the plugin in **Plugins → Installed Plugins**.
4. Follow the Setup Wizard that appears automatically under **WP Drive → Settings**.

== Frequently Asked Questions ==

= Is my Client Secret safe? =

Yes. The Client Secret and OAuth tokens are encrypted with AES-256-CBC using your WordPress `SECURE_AUTH_KEY` before storage. They are never sent to the browser.

= What is the maximum upload file size? =

Files up to PHP's `upload_max_filesize` can be uploaded. Files larger than 5 MB use Google's resumable upload protocol for reliability.

= Can I upload to Shared Drives? =

Not in v1.0. Only personal Drive is supported.

== Changelog ==

= 1.1.0 =
* Added Download from Drive feature — browse Google Drive, select files/folders, and download them to any directory in your WordPress filesystem.
* Live download progress bar with per-file byte-level tracking (same UX as upload).
* Download from Drive button added to the File Manager toolbar.
* New background cron processor for downloads (wp_drive_process_download_job).
* New REST endpoints: POST /drive/download/start and GET /drive/download/{job_id}/status.
* Incremental upload progress bar — progress now updates smoothly per chunk instead of jumping from 0% to done.
* Faster polling interval (800 ms) with animated shimmer bar during active transfers.
* Setup wizard now includes Publish App step with explanation of why it matters.
* Deactivation now shows a confirmation modal and immediately purges all plugin data.

= 1.0.0 =
* Initial release.
