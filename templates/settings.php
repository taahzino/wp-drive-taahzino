<?php
defined( 'ABSPATH' ) || exit;

/** @var WPDrive\OAuth $oauth */
$oauth      = WPDrive\Plugin::get_instance()->oauth;
$connected  = $oauth->is_connected();
$user_info  = $connected ? $oauth->get_user_info() : null;
$has_creds  = $oauth->has_credentials();
$client_id  = get_option( WPDrive\OAuth::CLIENT_ID_OPTION, '' );
$masked_id  = $client_id ? substr( $client_id, 0, 12 ) . str_repeat( '•', max( 0, strlen( $client_id ) - 12 ) ) : '';
?>
<div class="wpd-page-wrap">
<div class="wpd-settings-wrap">

  <!-- Header -->
  <div class="wpd-settings-header">
    <div class="wpd-settings-title">
      <div class="wpd-settings-logo">&#9729;&#65039;</div>
      <div>
        <h1><?php esc_html_e( 'WP Drive', 'wp-drive-taahzino' ); ?></h1>
        <p><?php esc_html_e( 'Google Drive integration for WordPress', 'wp-drive-taahzino' ); ?></p>
      </div>
    </div>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-drive-file-manager' ) ); ?>" class="wpd-btn wpd-btn-primary">
      &#128193; <?php esc_html_e( 'Open File Manager', 'wp-drive-taahzino' ); ?>
    </a>
  </div>

  <div class="wpd-settings-grid">

    <!-- Main column -->
    <div>

      <!-- Connection status -->
      <?php if ( $connected && $user_info ) : ?>
      <div class="wpd-status-card wpd-status-connected">
        <div class="wpd-account-row">
          <div class="wpd-account-avatar">
            <?php if ( ! empty( $user_info['picture'] ) ) : ?>
              <img src="<?php echo esc_url( $user_info['picture'] ); ?>" alt="">
            <?php else : ?>
              <div class="wpd-account-avatar-placeholder">
                <?php echo esc_html( strtoupper( substr( $user_info['email'] ?? 'G', 0, 1 ) ) ); ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="wpd-account-meta">
            <strong><?php echo esc_html( $user_info['name'] ?? $user_info['email'] ?? '' ); ?></strong>
            <span>
              <span class="wpd-badge wpd-badge-success">
                <span class="wpd-badge-dot"></span>
                <?php esc_html_e( 'Connected', 'wp-drive-taahzino' ); ?>
              </span>
              &nbsp;<?php echo esc_html( $user_info['email'] ?? '' ); ?>
            </span>
          </div>
        </div>
        <div class="wpd-status-actions">
          <button type="button" class="wpd-btn wpd-btn-danger" id="wpdBtnDisconnect">
            <?php esc_html_e( 'Disconnect', 'wp-drive-taahzino' ); ?>
            <span class="wpd-spinner"></span>
          </button>
          <button type="button" class="wpd-btn wpd-btn-secondary" id="wpdBtnReauth">
            &#128279; <?php esc_html_e( 'Re-authorize', 'wp-drive-taahzino' ); ?>
          </button>
        </div>
      </div>
      <?php else : ?>
      <div class="wpd-status-card wpd-status-disconnected">
        <div class="wpd-account-row">
          <div class="wpd-account-avatar-placeholder" style="background:#e2e8f0;color:#94a3b8;">?</div>
          <div class="wpd-account-meta">
            <strong><?php esc_html_e( 'Not connected', 'wp-drive-taahzino' ); ?></strong>
            <span><?php esc_html_e( 'Authorize the plugin to access your Google Drive.', 'wp-drive-taahzino' ); ?></span>
          </div>
        </div>
        <div class="wpd-status-actions">
          <button type="button" class="wpd-btn wpd-btn-primary" id="wpdBtnConnect" <?php disabled( ! $has_creds ); ?>>
            &#128279; <?php esc_html_e( 'Connect to Google Drive', 'wp-drive-taahzino' ); ?>
            <span class="wpd-spinner"></span>
          </button>
          <?php if ( ! $has_creds ) : ?>
          <p class="wpd-field-hint" style="margin:0;">
            <?php esc_html_e( 'Save your credentials below first.', 'wp-drive-taahzino' ); ?>
          </p>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Credentials form -->
      <div class="wpd-cred-section">
        <div class="wpd-cred-header">
          <h3><?php esc_html_e( 'OAuth 2.0 Credentials', 'wp-drive-taahzino' ); ?></h3>
          <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener" class="wpd-btn wpd-btn-ghost" style="font-size:12px;">
            &#127758; <?php esc_html_e( 'Open GCP Console', 'wp-drive-taahzino' ); ?>
          </a>
        </div>
        <div class="wpd-cred-body">

          <div id="wpdSettingsAlert" class="wpd-alert wpd-alert-hidden"></div>

          <div class="wpd-field">
            <label class="wpd-label" for="wpdSettingsClientId">
              <?php esc_html_e( 'Client ID', 'wp-drive-taahzino' ); ?>
            </label>
            <input type="text" id="wpdSettingsClientId" class="wpd-input"
              value="<?php echo esc_attr( $client_id ); ?>"
              placeholder="000000000000-xxxx.apps.googleusercontent.com">
          </div>

          <div class="wpd-field">
            <label class="wpd-label" for="wpdSettingsSecret">
              <?php esc_html_e( 'Client Secret', 'wp-drive-taahzino' ); ?>
              <span class="wpd-label-hint"><?php esc_html_e( '(leave blank to keep current)', 'wp-drive-taahzino' ); ?></span>
            </label>
            <div class="wpd-input-wrapper">
              <input type="password" id="wpdSettingsSecret" class="wpd-input"
                placeholder="<?php echo $has_creds ? esc_attr__( '(saved — paste to update)', 'wp-drive-taahzino' ) : 'GOCSPX-xxxxxxxxxxxxx'; ?>"
                autocomplete="new-password">
              <button type="button" class="wpd-input-toggle" id="wpdSettingsToggleSecret">&#128065;</button>
            </div>
          </div>

          <?php
          $override_val  = get_option( WPDrive\OAuth::REDIRECT_URI_OPTION, '' );
          $active_uri    = WPDrive\Plugin::get_instance()->oauth->get_redirect_uri();
          $site_url      = get_site_url();
          $is_local_tld  = preg_match( '/\.(local|localhost|test|dev|example|invalid)$/', parse_url( $site_url, PHP_URL_HOST ) ?? '' );
          ?>

          <?php if ( $is_local_tld ) : ?>
          <div class="wpd-callout wpd-callout-warning" style="margin-bottom:20px;">
            <span class="wpd-callout-icon">&#9888;&#65039;</span>
            <div>
              <strong><?php esc_html_e( 'Local domain detected (.local / .test / .dev)', 'wp-drive-taahzino' ); ?></strong><br>
              <?php esc_html_e( 'Google OAuth rejects non-public TLDs like .local. You must use a tunnel (ngrok, LocalWP Live Link, etc.) and paste its public URL below.', 'wp-drive-taahzino' ); ?>
              <br><a href="https://ngrok.com" target="_blank" rel="noopener" style="font-weight:600;">ngrok.com</a>
              <?php esc_html_e( ' — free, generates an https URL in seconds.', 'wp-drive-taahzino' ); ?>
            </div>
          </div>
          <?php endif; ?>

          <div class="wpd-field">
            <label class="wpd-label" for="wpdRedirectOverride">
              <?php esc_html_e( 'Redirect URI Override', 'wp-drive-taahzino' ); ?>
              <span class="wpd-label-hint"><?php esc_html_e( '(required for .local / tunnel setups)', 'wp-drive-taahzino' ); ?></span>
            </label>
            <input type="text" id="wpdRedirectOverride" class="wpd-input"
              value="<?php echo esc_attr( $override_val ); ?>"
              placeholder="https://xxxx.ngrok.io/wp-json/wp-drive/v1/auth/callback">
            <p class="wpd-field-hint">
              <?php esc_html_e( 'Leave blank to use the auto-generated URL below. If your site uses a .local domain, paste your tunnel URL here ending with ', 'wp-drive-taahzino' ); ?>
              <code>/wp-json/wp-drive/v1/auth/callback</code>.
            </p>
          </div>

          <div class="wpd-field">
            <label class="wpd-label"><?php esc_html_e( 'Active Redirect URI', 'wp-drive-taahzino' ); ?></label>
            <div class="wpd-input-wrapper">
              <input type="text" id="wpdActiveRedirectUri" class="wpd-input" value="<?php echo esc_attr( $active_uri ); ?>" readonly style="background:#f8fafc;padding-right:44px;">
              <button type="button" class="wpd-input-toggle" id="wpdCopyRedirect" title="<?php esc_attr_e( 'Copy', 'wp-drive-taahzino' ); ?>">&#128203;</button>
            </div>
            <p class="wpd-field-hint">
              <?php esc_html_e( 'Copy this exact URL and add it to your GCP OAuth credential\'s Authorized redirect URIs list. It updates instantly when you change the override above.', 'wp-drive-taahzino' ); ?>
            </p>
          </div>

          <button type="button" class="wpd-btn wpd-btn-primary" id="wpdSaveCredentials">
            <?php esc_html_e( 'Save Credentials', 'wp-drive-taahzino' ); ?>
            <span class="wpd-spinner"></span>
          </button>
        </div>
      </div>

    </div><!-- /.main-col -->

    <!-- Sidebar -->
    <div>

      <div class="wpd-sidebar-card">
        <div class="wpd-sidebar-card-header"><?php esc_html_e( 'Quick Links', 'wp-drive-taahzino' ); ?></div>
        <div class="wpd-quick-links">
          <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-drive-file-manager' ) ); ?>" class="wpd-quick-link">
            <span class="wpd-quick-link-icon">&#128193;</span>
            <?php esc_html_e( 'File Manager', 'wp-drive-taahzino' ); ?>
          </a>
          <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener" class="wpd-quick-link">
            <span class="wpd-quick-link-icon">&#127772;</span>
            <?php esc_html_e( 'GCP Credentials', 'wp-drive-taahzino' ); ?>
          </a>
          <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" rel="noopener" class="wpd-quick-link">
            <span class="wpd-quick-link-icon">&#128336;</span>
            <?php esc_html_e( 'Drive API Status', 'wp-drive-taahzino' ); ?>
          </a>
        </div>
      </div>

      <div class="wpd-sidebar-card">
        <div class="wpd-sidebar-card-header"><?php esc_html_e( 'Plugin Info', 'wp-drive-taahzino' ); ?></div>
        <div class="wpd-quick-links">
          <div class="wpd-quick-link" style="cursor:default;">
            <span class="wpd-quick-link-icon">&#128218;</span>
            <?php esc_html_e( 'Version', 'wp-drive-taahzino' ); ?>: <strong><?php echo esc_html( WP_DRIVE_VERSION ); ?></strong>
          </div>
          <div class="wpd-quick-link" style="cursor:default;">
            <span class="wpd-quick-link-icon">&#128279;</span>
            <?php esc_html_e( 'Redirect URI:', 'wp-drive-taahzino' ); ?><br>
            <code style="font-size:11px;word-break:break-all;"><?php echo esc_html( rest_url( 'wp-drive/v1/auth/callback' ) ); ?></code>
          </div>
        </div>
      </div>

      <?php if ( ! get_option( 'wp_drive_wizard_completed', false ) ) : ?>
      <div class="wpd-sidebar-card">
        <div class="wpd-quick-links">
          <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-drive-settings&reset_wizard=1' ) ); ?>" class="wpd-quick-link" style="color:#ef4444;">
            <span class="wpd-quick-link-icon">&#8635;</span>
            <?php esc_html_e( 'Re-run Setup Wizard', 'wp-drive-taahzino' ); ?>
          </a>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /.sidebar -->

  </div><!-- .wpd-settings-grid -->
</div><!-- .wpd-settings-wrap -->
</div><!-- .wpd-page-wrap -->
