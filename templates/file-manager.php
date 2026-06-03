<?php
defined( 'ABSPATH' ) || exit;

$oauth     = WPDrive\Plugin::get_instance()->oauth;
$user_info = $oauth->get_user_info();
?>
<div class="wpd-fm-wrap" id="wpdFileManager">

  <!-- Top bar -->
  <div class="wpd-fm-topbar">
    <div class="wpd-fm-topbar-left">
      <!-- Breadcrumb: populated by JS -->
      <nav class="wpd-fm-breadcrumb" id="wpdBreadcrumb" aria-label="<?php esc_attr_e( 'File location', 'wp-drive-taahzino' ); ?>">
        <span class="wpd-fm-breadcrumb-item is-current" data-path=""><?php esc_html_e( 'WordPress Root', 'wp-drive-taahzino' ); ?></span>
      </nav>
    </div>

    <div class="wpd-fm-topbar-right">
      <?php if ( $user_info ) : ?>
        <span style="font-size:12px;color:#64748b;"><?php echo esc_html( $user_info['email'] ); ?></span>
        <?php if ( ! empty( $user_info['picture'] ) ) : ?>
          <img src="<?php echo esc_url( $user_info['picture'] ); ?>" alt="" style="width:28px;height:28px;border-radius:50%;border:1.5px solid #a7f3d0;">
        <?php endif; ?>
      <?php endif; ?>

      <div class="wpd-view-toggle">
        <button type="button" class="wpd-view-btn is-active" id="wpdViewList" title="<?php esc_attr_e( 'List view', 'wp-drive-taahzino' ); ?>">&#9776;</button>
        <button type="button" class="wpd-view-btn" id="wpdViewGrid" title="<?php esc_attr_e( 'Grid view', 'wp-drive-taahzino' ); ?>">&#9632;</button>
      </div>
    </div>
  </div>

  <!-- File content area -->
  <div class="wpd-fm-content" id="wpdFmContent">
    <div class="wpd-fm-loading" id="wpdFmLoading">
      <div class="wpd-spinner"></div>
      <span><?php esc_html_e( 'Loading files…', 'wp-drive-taahzino' ); ?></span>
    </div>

    <!-- List view -->
    <div id="wpdListView" style="display:none;">
      <div class="wpd-fm-list-header">
        <span></span>
        <span><?php esc_html_e( 'Name', 'wp-drive-taahzino' ); ?></span>
        <span><?php esc_html_e( 'Size', 'wp-drive-taahzino' ); ?></span>
        <span><?php esc_html_e( 'Modified', 'wp-drive-taahzino' ); ?></span>
        <span><?php esc_html_e( 'Type', 'wp-drive-taahzino' ); ?></span>
      </div>
      <div class="wpd-fm-list" id="wpdFileList"></div>
    </div>

    <!-- Grid view -->
    <div id="wpdGridView" class="wpd-fm-grid" style="display:none;"></div>

    <!-- Empty state -->
    <div class="wpd-fm-empty" id="wpdFmEmpty" style="display:none;">
      <span class="wpd-fm-empty-icon">&#128193;</span>
      <h3><?php esc_html_e( 'This folder is empty', 'wp-drive-taahzino' ); ?></h3>
      <p><?php esc_html_e( 'Upload files to your WordPress media directory to see them here.', 'wp-drive-taahzino' ); ?></p>
    </div>
  </div>

  <!-- Bottom toolbar -->
  <div class="wpd-fm-toolbar">
    <div class="wpd-fm-toolbar-info">
      <span id="wpdSelectionInfo"><?php esc_html_e( 'No items selected', 'wp-drive-taahzino' ); ?></span>
    </div>
    <div class="wpd-fm-toolbar-actions">
      <button type="button" class="wpd-btn wpd-btn-ghost" id="wpdClearSelection" style="display:none;">
        <?php esc_html_e( 'Clear', 'wp-drive-taahzino' ); ?>
      </button>
      <button type="button" class="wpd-btn wpd-btn-download" id="wpdDownloadFromDrive">
        &#8595; <?php esc_html_e( 'Download from Drive', 'wp-drive-taahzino' ); ?>
      </button>
      <button type="button" class="wpd-btn wpd-btn-primary" id="wpdUploadToDrive" disabled>
        &#9729;&#65039; <?php esc_html_e( 'Upload to Drive', 'wp-drive-taahzino' ); ?>
      </button>
    </div>
  </div>

</div><!-- .wpd-fm-wrap -->

<!-- Drive Downloader modal (owned by drive-downloader.js) -->
<div id="wpdDownloaderOverlay" style="display:none;position:fixed;inset:0;z-index:100001;background:rgba(15,23,42,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Download from Google Drive', 'wp-drive-taahzino' ); ?>">
  <div id="wpdDownloaderModal" style="background:#fff;border-radius:16px;width:560px;max-width:calc(100vw - 40px);max-height:calc(100vh - 80px);display:flex;flex-direction:column;box-shadow:0 25px 60px rgba(0,0,0,.25);overflow:hidden;">

    <!-- Header -->
    <div class="wpd-picker-header">
      <h2 id="wpdDlTitle"><?php esc_html_e( 'Download from Google Drive', 'wp-drive-taahzino' ); ?></h2>
      <button type="button" class="wpd-picker-close" id="wpdDlClose" aria-label="<?php esc_attr_e( 'Close', 'wp-drive-taahzino' ); ?>">&#x2715;</button>
    </div>

    <!-- Breadcrumb: Drive path or Local path (toggled by JS) -->
    <div class="wpd-picker-breadcrumb" id="wpdDlBreadcrumb">
      <span class="wpd-dl-bc-item is-current">My Drive</span>
    </div>

    <!-- Body: Drive list / local folder list / progress (replaced by JS) -->
    <div class="wpd-picker-body" id="wpdDlBody">
      <div class="wpd-dl-loading">
        <div class="wpd-spinner"></div>
        <span><?php esc_html_e( 'Loading Drive…', 'wp-drive-taahzino' ); ?></span>
      </div>
    </div>

    <!-- Footer: actions (replaced by JS per view) -->
    <div class="wpd-picker-footer" id="wpdDlFooter">
      <div class="wpd-dl-footer-info"><?php esc_html_e( 'Select files or folders to download', 'wp-drive-taahzino' ); ?></div>
      <div class="wpd-dl-footer-actions">
        <button type="button" class="wpd-btn wpd-btn-ghost" id="wpdDlCancelBtn"><?php esc_html_e( 'Cancel', 'wp-drive-taahzino' ); ?></button>
        <button type="button" class="wpd-btn wpd-btn-primary" disabled><?php esc_html_e( 'Choose Destination →', 'wp-drive-taahzino' ); ?></button>
      </div>
    </div>

  </div>
</div>

<!-- Drive picker / upload progress modal (rendered by drive-picker.js) -->
<div id="wpdPickerOverlay" class="wpd-picker-overlay" style="display:none;" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Choose Google Drive destination', 'wp-drive-taahzino' ); ?>">
  <div class="wpd-picker-modal">

    <div class="wpd-picker-header">
      <h2 id="wpdPickerTitle"><?php esc_html_e( 'Choose destination in Drive', 'wp-drive-taahzino' ); ?></h2>
      <button type="button" class="wpd-picker-close" id="wpdPickerClose" aria-label="<?php esc_attr_e( 'Close', 'wp-drive-taahzino' ); ?>">&#x2715;</button>
    </div>

    <!-- Breadcrumb: drive navigation (hidden during progress view) -->
    <div class="wpd-picker-breadcrumb" id="wpdDriveBreadcrumb">
      <span class="wpd-picker-breadcrumb-item is-current" data-folder-id="root">My Drive</span>
    </div>

    <!-- Body: switches between folder browser and progress view -->
    <div class="wpd-picker-body" id="wpdPickerBody">
      <div class="wpd-drive-loading" id="wpdDriveLoading">
        <div class="wpd-spinner"></div>
        <span><?php esc_html_e( 'Loading Drive…', 'wp-drive-taahzino' ); ?></span>
      </div>
      <div class="wpd-drive-list" id="wpdDriveList" style="display:none;"></div>
      <div class="wpd-drive-empty" id="wpdDriveEmpty" style="display:none;">
        <span style="font-size:36px;">&#128193;</span>
        <span><?php esc_html_e( 'This folder is empty', 'wp-drive-taahzino' ); ?></span>
      </div>
    </div>

    <!-- Footer: destination + upload button (hidden during progress view) -->
    <div class="wpd-picker-footer" id="wpdPickerFooter">
      <div class="wpd-picker-dest">
        <span class="wpd-picker-dest-label"><?php esc_html_e( 'Upload to:', 'wp-drive-taahzino' ); ?></span>
        <span class="wpd-picker-dest-name" id="wpdDestName">My Drive</span>
      </div>
      <div class="wpd-picker-footer-actions">
        <button type="button" class="wpd-btn wpd-btn-secondary" id="wpdPickerCancel">
          <?php esc_html_e( 'Cancel', 'wp-drive-taahzino' ); ?>
        </button>
        <button type="button" class="wpd-btn wpd-btn-primary" id="wpdUploadHere">
          &#9729;&#65039; <?php esc_html_e( 'Upload Here', 'wp-drive-taahzino' ); ?>
          <span class="wpd-spinner"></span>
        </button>
      </div>
    </div>

  </div><!-- .wpd-picker-modal -->
</div><!-- .wpd-picker-overlay -->
