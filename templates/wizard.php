<?php
defined( 'ABSPATH' ) || exit;

/** @var WPDrive\OAuth $oauth Injected from class-admin.php context via Plugin singleton. */
$oauth          = WPDrive\Plugin::get_instance()->oauth;
$has_creds      = $oauth->has_credentials();
$is_connected   = $oauth->is_connected();
$user_info      = $is_connected ? $oauth->get_user_info() : null;

// Determine current step based on state.
if ( $is_connected ) {
	$current_step = 5;
} elseif ( $has_creds ) {
	$current_step = 4;
} else {
	$current_step = isset( $_GET['wizard_step'] ) ? (int) sanitize_text_field( wp_unslash( $_GET['wizard_step'] ) ) : 1;
}

$steps = [
	1 => __( 'Welcome', 'wp-drive-taahzino' ),
	2 => __( 'GCP Setup', 'wp-drive-taahzino' ),
	3 => __( 'Credentials', 'wp-drive-taahzino' ),
	4 => __( 'Connect', 'wp-drive-taahzino' ),
	5 => __( 'Done', 'wp-drive-taahzino' ),
];
$total_steps    = count( $steps );
$progress_pct   = round( ( ( $current_step - 1 ) / ( $total_steps - 1 ) ) * 100 );
?>
<div class="wpd-page-wrap">
<div class="wpd-wizard-wrap">
<div class="wpd-wizard-container">

  <!-- Progress bar -->
  <div class="wpd-wizard-progress">
    <div class="wpd-wizard-progress-bar" id="wpdProgressBar" style="width: <?php echo esc_attr( $progress_pct ); ?>%"></div>
  </div>

  <!-- Step indicators -->
  <div class="wpd-wizard-steps" id="wpdStepDots">
    <?php foreach ( $steps as $num => $label ) : ?>
      <?php
      $cls = 'wpd-wizard-step-dot';
      if ( $num < $current_step ) $cls .= ' is-done';
      if ( $num === $current_step ) $cls .= ' is-active';
      $circle_content = $num < $current_step ? '✓' : esc_html( $num );
      ?>
      <?php if ( $num > 1 ) : ?>
        <div class="wpd-wizard-connector <?php echo $num <= $current_step ? 'is-done' : ''; ?>"></div>
      <?php endif; ?>
      <div class="<?php echo esc_attr( $cls ); ?>" data-step="<?php echo esc_attr( $num ); ?>">
        <div class="wpd-wizard-step-circle"><?php echo $circle_content; ?></div>
        <span class="wpd-wizard-step-label"><?php echo esc_html( $label ); ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Wizard card -->
  <div class="wpd-wizard-card">

    <!-- ================================================================
         STEP 1: Welcome
         ================================================================ -->
    <div class="wpd-wizard-panel <?php echo 1 === $current_step ? 'is-active' : ''; ?>" id="wpdPanel1">
      <div class="wpd-wizard-hero">
        <div class="wpd-wizard-icon">&#9729;&#65039;</div>
        <h1 class="wpd-wizard-title"><?php esc_html_e( 'Welcome to WP Drive', 'wp-drive-taahzino' ); ?></h1>
        <p class="wpd-wizard-subtitle">
          <?php esc_html_e( 'Upload files and folders from your WordPress media library directly to Google Drive — with a live progress bar and a smooth file manager.', 'wp-drive-taahzino' ); ?>
        </p>
      </div>

      <ul class="wpd-feature-list">
        <li class="wpd-feature-item">
          <span class="wpd-feature-icon">&#128193;</span>
          <div class="wpd-feature-text">
            <strong><?php esc_html_e( 'Visual File Manager', 'wp-drive-taahzino' ); ?></strong>
            <span><?php esc_html_e( 'Browse your WP uploads directory with a clean, modern interface.', 'wp-drive-taahzino' ); ?></span>
          </div>
        </li>
        <li class="wpd-feature-item">
          <span class="wpd-feature-icon">&#9729;&#65039;</span>
          <div class="wpd-feature-text">
            <strong><?php esc_html_e( 'Google Drive Integration', 'wp-drive-taahzino' ); ?></strong>
            <span><?php esc_html_e( 'Navigate your Drive folders and choose exactly where files land.', 'wp-drive-taahzino' ); ?></span>
          </div>
        </li>
        <li class="wpd-feature-item">
          <span class="wpd-feature-icon">&#128202;</span>
          <div class="wpd-feature-text">
            <strong><?php esc_html_e( 'Live Upload Progress', 'wp-drive-taahzino' ); ?></strong>
            <span><?php esc_html_e( 'Track every file upload in real-time with per-file status indicators.', 'wp-drive-taahzino' ); ?></span>
          </div>
        </li>
      </ul>

      <div class="wpd-callout wpd-callout-info">
        <span class="wpd-callout-icon">&#8505;&#65039;</span>
        <span><?php esc_html_e( 'This setup wizard takes about 5 minutes. You will need a Google account and access to Google Cloud Console.', 'wp-drive-taahzino' ); ?></span>
      </div>
    </div>

    <!-- ================================================================
         STEP 2: GCP Setup
         ================================================================ -->
    <div class="wpd-wizard-panel <?php echo 2 === $current_step ? 'is-active' : ''; ?>" id="wpdPanel2">
      <div class="wpd-wizard-hero">
        <h1 class="wpd-wizard-title"><?php esc_html_e( 'Set Up Google Cloud', 'wp-drive-taahzino' ); ?></h1>
        <p class="wpd-wizard-subtitle">
          <?php esc_html_e( 'You need a Google Cloud project with Drive API access. This is a one-time setup that takes about 3 minutes.', 'wp-drive-taahzino' ); ?>
        </p>
      </div>

      <ol class="wpd-setup-steps">
        <li class="wpd-setup-step">
          <div class="wpd-setup-step-content">
            <strong><?php esc_html_e( 'Create a Google Cloud Project', 'wp-drive-taahzino' ); ?></strong>
            <p>
              <?php esc_html_e( 'Go to ', 'wp-drive-taahzino' ); ?>
              <a href="https://console.cloud.google.com" target="_blank" rel="noopener">console.cloud.google.com</a>
              <?php esc_html_e( ', click the project selector → New Project. Name it anything (e.g. "WP Drive").', 'wp-drive-taahzino' ); ?>
            </p>
          </div>
        </li>
        <li class="wpd-setup-step">
          <div class="wpd-setup-step-content">
            <strong><?php esc_html_e( 'Enable Google Drive API', 'wp-drive-taahzino' ); ?></strong>
            <p>
              <?php esc_html_e( 'Go to ', 'wp-drive-taahzino' ); ?>
              <strong><?php esc_html_e( 'APIs &amp; Services → Library', 'wp-drive-taahzino' ); ?></strong>
              <?php esc_html_e( ', search for "Google Drive API" and click ', 'wp-drive-taahzino' ); ?>
              <strong><?php esc_html_e( 'Enable', 'wp-drive-taahzino' ); ?></strong>.
            </p>
          </div>
        </li>
        <li class="wpd-setup-step">
          <div class="wpd-setup-step-content">
            <strong><?php esc_html_e( 'Configure OAuth Consent Screen', 'wp-drive-taahzino' ); ?></strong>
            <p>
              <?php esc_html_e( 'Go to ', 'wp-drive-taahzino' ); ?>
              <strong><?php esc_html_e( 'APIs &amp; Services → OAuth consent screen', 'wp-drive-taahzino' ); ?></strong>
              <?php esc_html_e( '. Choose ', 'wp-drive-taahzino' ); ?>
              <strong><?php esc_html_e( 'External', 'wp-drive-taahzino' ); ?></strong>
              <?php esc_html_e( ', fill in app name, and add scope: ', 'wp-drive-taahzino' ); ?>
              <code>.../auth/drive.file</code>.
              <?php esc_html_e( ' Add your Google account under Test Users.', 'wp-drive-taahzino' ); ?>
            </p>
          </div>
        </li>
        <li class="wpd-setup-step">
          <div class="wpd-setup-step-content">
            <strong><?php esc_html_e( 'Create OAuth 2.0 Credentials', 'wp-drive-taahzino' ); ?></strong>
            <p>
              <?php esc_html_e( 'Go to ', 'wp-drive-taahzino' ); ?>
              <strong><?php esc_html_e( 'APIs &amp; Services → Credentials', 'wp-drive-taahzino' ); ?></strong>
              <?php esc_html_e( '. Click ', 'wp-drive-taahzino' ); ?>
              <strong><?php esc_html_e( 'Create Credentials → OAuth client ID', 'wp-drive-taahzino' ); ?></strong>
              <?php esc_html_e( ', type = ', 'wp-drive-taahzino' ); ?>
              <strong><?php esc_html_e( 'Web application', 'wp-drive-taahzino' ); ?></strong>.
            </p>
          </div>
        </li>
        <li class="wpd-setup-step">
          <div class="wpd-setup-step-content">
            <strong><?php esc_html_e( 'Add Authorized Redirect URI', 'wp-drive-taahzino' ); ?></strong>
            <?php
            $site_host    = parse_url( get_site_url(), PHP_URL_HOST ) ?? '';
            $is_local_tld = (bool) preg_match( '/\.(local|localhost|test|dev|example|invalid)$/', $site_host );
            if ( $is_local_tld ) : ?>
            <div class="wpd-callout wpd-callout-warning" style="margin-top:8px;">
              <span class="wpd-callout-icon">&#9888;&#65039;</span>
              <div>
                <strong><?php esc_html_e( 'Your site uses a .local domain', 'wp-drive-taahzino' ); ?></strong><br>
                <?php esc_html_e( 'Google rejects .local URIs. Run a tunnel first:', 'wp-drive-taahzino' ); ?><br>
                &bull; <strong>ngrok:</strong> <code>ngrok http 80 --host-header=<?php echo esc_html( $site_host ); ?></code><br>
                &bull; <strong>LocalWP:</strong> <?php esc_html_e( 'Site → Live Link → Enable', 'wp-drive-taahzino' ); ?><br>
                <?php esc_html_e( 'Then paste your tunnel URL into "Redirect URI Override" on the next screen.', 'wp-drive-taahzino' ); ?>
              </div>
            </div>
            <?php else : ?>
            <p>
              <?php esc_html_e( 'Under Authorized redirect URIs, add exactly:', 'wp-drive-taahzino' ); ?><br>
              <code><?php echo esc_html( rest_url( 'wp-drive/v1/auth/callback' ) ); ?></code>
            </p>
            <?php endif; ?>
          </div>
        </li>
        <li class="wpd-setup-step">
          <div class="wpd-setup-step-content">
            <strong><?php esc_html_e( 'Copy Client ID and Client Secret', 'wp-drive-taahzino' ); ?></strong>
            <p><?php esc_html_e( 'After creating the credential, copy both values. You will paste them on the next screen.', 'wp-drive-taahzino' ); ?></p>
          </div>
        </li>
      </ol>

      <label class="wpd-checkbox-confirm" id="wpdGcpConfirm">
        <input type="checkbox" id="wpdGcpCheckbox">
        <span><?php esc_html_e( "I've completed the Google Cloud setup above", 'wp-drive-taahzino' ); ?></span>
      </label>
    </div>

    <!-- ================================================================
         STEP 3: Enter Credentials
         ================================================================ -->
    <div class="wpd-wizard-panel <?php echo 3 === $current_step ? 'is-active' : ''; ?>" id="wpdPanel3">
      <div class="wpd-wizard-hero">
        <h1 class="wpd-wizard-title"><?php esc_html_e( 'Enter Your Credentials', 'wp-drive-taahzino' ); ?></h1>
        <p class="wpd-wizard-subtitle">
          <?php esc_html_e( 'Paste the Client ID and Client Secret from the OAuth client you just created. They are stored encrypted.', 'wp-drive-taahzino' ); ?>
        </p>
      </div>

      <div id="wpdCredsAlert" class="wpd-alert wpd-alert-hidden"></div>

      <div class="wpd-field">
        <label class="wpd-label" for="wpdClientId">
          <?php esc_html_e( 'Client ID', 'wp-drive-taahzino' ); ?>
        </label>
        <input type="text" id="wpdClientId" class="wpd-input" placeholder="000000000000-xxxxxxxxxxxx.apps.googleusercontent.com" autocomplete="off">
        <p class="wpd-field-hint"><?php esc_html_e( 'Looks like: 000000000000-xxxx.apps.googleusercontent.com', 'wp-drive-taahzino' ); ?></p>
      </div>

      <div class="wpd-field">
        <label class="wpd-label" for="wpdClientSecret">
          <?php esc_html_e( 'Client Secret', 'wp-drive-taahzino' ); ?>
        </label>
        <div class="wpd-input-wrapper">
          <input type="password" id="wpdClientSecret" class="wpd-input" placeholder="GOCSPX-xxxxxxxxxxxxx" autocomplete="new-password">
          <button type="button" class="wpd-input-toggle" id="wpdToggleSecret" title="<?php esc_attr_e( 'Show/hide', 'wp-drive-taahzino' ); ?>">&#128065;</button>
        </div>
        <p class="wpd-field-hint"><?php esc_html_e( 'Stored encrypted. Never exposed in the frontend.', 'wp-drive-taahzino' ); ?></p>
      </div>

      <?php
      $site_host    = parse_url( get_site_url(), PHP_URL_HOST ) ?? '';
      $is_local_tld = (bool) preg_match( '/\.(local|localhost|test|dev|example|invalid)$/', $site_host );
      $override_val = get_option( WPDrive\OAuth::REDIRECT_URI_OPTION, '' );
      ?>

      <?php if ( $is_local_tld ) : ?>
      <div class="wpd-field">
        <label class="wpd-label" for="wpdRedirectOverride">
          &#9888;&#65039; <?php esc_html_e( 'Redirect URI Override', 'wp-drive-taahzino' ); ?>
          <span class="wpd-label-hint"><?php esc_html_e( '(required — .local detected)', 'wp-drive-taahzino' ); ?></span>
        </label>
        <input type="text" id="wpdRedirectOverride" class="wpd-input"
          value="<?php echo esc_attr( $override_val ); ?>"
          placeholder="https://xxxx.ngrok.io/wp-json/wp-drive/v1/auth/callback">
        <p class="wpd-field-hint">
          <?php esc_html_e( 'Paste your tunnel URL (ngrok / LocalWP Live Link) ending with ', 'wp-drive-taahzino' ); ?>
          <code>/wp-json/wp-drive/v1/auth/callback</code>.
          <?php esc_html_e( ' This is the URL you add to GCP\'s Authorized redirect URIs.', 'wp-drive-taahzino' ); ?>
        </p>
      </div>
      <?php endif; ?>

      <div class="wpd-callout wpd-callout-warning">
        <span class="wpd-callout-icon">&#128274;</span>
        <span><?php esc_html_e( 'These credentials are saved encrypted using AES-256 and your WordPress security keys. Never share your Client Secret.', 'wp-drive-taahzino' ); ?></span>
      </div>
    </div>

    <!-- ================================================================
         STEP 4: Connect to Google Drive
         ================================================================ -->
    <div class="wpd-wizard-panel <?php echo 4 === $current_step ? 'is-active' : ''; ?>" id="wpdPanel4">
      <div class="wpd-wizard-hero">
        <h1 class="wpd-wizard-title"><?php esc_html_e( 'Connect Google Drive', 'wp-drive-taahzino' ); ?></h1>
        <p class="wpd-wizard-subtitle">
          <?php esc_html_e( "Your credentials are saved. Now authorize WP Drive to access your Google Drive.", 'wp-drive-taahzino' ); ?>
        </p>
      </div>

      <div class="wpd-connect-info">
        <span class="wpd-connect-cred-icon">&#9989;</span>
        <div class="wpd-connect-cred-text">
          <strong><?php esc_html_e( 'Credentials saved', 'wp-drive-taahzino' ); ?></strong>
          <span><?php esc_html_e( 'Client ID and Secret are stored and encrypted.', 'wp-drive-taahzino' ); ?></span>
        </div>
      </div>

      <div class="wpd-callout wpd-callout-info">
        <span class="wpd-callout-icon">&#128279;</span>
        <span>
          <?php esc_html_e( "Clicking the button below will redirect you to Google's sign-in page. After you approve, you'll be brought back here automatically.", 'wp-drive-taahzino' ); ?>
        </span>
      </div>

      <div id="wpdConnectAlert" class="wpd-alert wpd-alert-hidden"></div>
    </div>

    <!-- ================================================================
         STEP 5: Done
         ================================================================ -->
    <div class="wpd-wizard-panel <?php echo 5 === $current_step ? 'is-active' : ''; ?>" id="wpdPanel5">
      <div class="wpd-success-hero">
        <div class="wpd-success-check">&#10003;</div>
        <h1 class="wpd-wizard-title"><?php esc_html_e( "You're all set!", 'wp-drive-taahzino' ); ?></h1>
        <p class="wpd-wizard-subtitle">
          <?php esc_html_e( 'WP Drive is connected to your Google account. You can now upload files from your WordPress site to Google Drive.', 'wp-drive-taahzino' ); ?>
        </p>
      </div>

      <?php if ( $user_info ) : ?>
      <div class="wpd-success-account">
        <div class="wpd-success-avatar">
          <?php if ( ! empty( $user_info['picture'] ) ) : ?>
            <img src="<?php echo esc_url( $user_info['picture'] ); ?>" alt="<?php echo esc_attr( $user_info['name'] ?? '' ); ?>">
          <?php else : ?>
            <div class="wpd-success-avatar-placeholder"><?php echo esc_html( strtoupper( substr( $user_info['email'] ?? 'G', 0, 1 ) ) ); ?></div>
          <?php endif; ?>
        </div>
        <div class="wpd-success-email">
          <strong><?php echo esc_html( $user_info['name'] ?? $user_info['email'] ); ?></strong>
          <span><?php echo esc_html( $user_info['email'] ?? '' ); ?></span>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ================================================================
         Navigation footer (rendered once, JS updates visibility/buttons)
         ================================================================ -->
    <div class="wpd-wizard-footer" id="wpdWizardFooter">
      <button type="button" class="wpd-btn wpd-btn-ghost" id="wpdBtnBack">
        &#8592; <?php esc_html_e( 'Back', 'wp-drive-taahzino' ); ?>
      </button>
      <button type="button" class="wpd-btn wpd-btn-primary wpd-btn-lg" id="wpdBtnNext">
        <?php esc_html_e( 'Get Started', 'wp-drive-taahzino' ); ?> &rarr;
        <span class="wpd-spinner"></span>
      </button>
    </div>

  </div><!-- .wpd-wizard-card -->
</div><!-- .wpd-wizard-container -->
</div><!-- .wpd-wizard-wrap -->
</div><!-- .wpd-page-wrap -->

<script>
// Pass the initial step to the JS module.
window.wpdWizardInitStep = <?php echo (int) $current_step; ?>;
</script>
