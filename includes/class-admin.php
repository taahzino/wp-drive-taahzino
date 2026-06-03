<?php
namespace WPDrive;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI: menu pages, asset enqueuing, and page rendering.
 */
class Admin {

	private OAuth $oauth;

	/** Query param set by REST callback after OAuth success. */
	const CONNECTED_PARAM = 'wp_drive_connected';
	const ERROR_PARAM     = 'wp_drive_error';

	public function __construct( OAuth $oauth ) {
		$this->oauth = $oauth;

		add_action( 'admin_menu', [ $this, 'register_menus' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ $this, 'show_notices' ] );

		// Deactivation confirmation modal — only on the plugins list screen.
		add_action( 'admin_footer-plugins.php', [ $this, 'render_deactivation_modal' ] );
	}

	// -------------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------------

	public function register_menus(): void {
		add_menu_page(
			__( 'WP Drive', 'wp-drive-taahzino' ),
			__( 'WP Drive', 'wp-drive-taahzino' ),
			'manage_options',
			'wp-drive-settings',
			[ $this, 'render_main_page' ],
			$this->get_menu_icon(),
			75
		);

		add_submenu_page(
			'wp-drive-settings',
			__( 'Settings', 'wp-drive-taahzino' ),
			__( 'Settings', 'wp-drive-taahzino' ),
			'manage_options',
			'wp-drive-settings',
			[ $this, 'render_main_page' ]
		);

		add_submenu_page(
			'wp-drive-settings',
			__( 'File Manager', 'wp-drive-taahzino' ),
			__( 'File Manager', 'wp-drive-taahzino' ),
			'manage_options',
			'wp-drive-file-manager',
			[ $this, 'render_file_manager_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Asset enqueuing
	// -------------------------------------------------------------------------

	public function enqueue_assets( string $hook ): void {
		$our_pages = [ 'toplevel_page_wp-drive-settings', 'wp-drive_page_wp-drive-file-manager' ];
		if ( ! in_array( $hook, $our_pages, true ) ) {
			return;
		}

		$ver = WP_DRIVE_VERSION;

		// Shared admin CSS.
		wp_enqueue_style(
			'wp-drive-admin',
			WP_DRIVE_URL . 'assets/css/admin.css',
			[],
			$ver
		);

		if ( 'toplevel_page_wp-drive-settings' === $hook ) {
			wp_enqueue_script(
				'wp-drive-admin',
				WP_DRIVE_URL . 'assets/js/admin.js',
				[],
				$ver,
				true
			);
			wp_localize_script( 'wp-drive-admin', 'wpDrive', $this->js_data() );
		}

		if ( 'wp-drive_page_wp-drive-file-manager' === $hook ) {
			wp_enqueue_style(
				'wp-drive-file-manager',
				WP_DRIVE_URL . 'assets/css/file-manager.css',
				[],
				$ver
			);
			wp_enqueue_script(
				'wp-drive-file-manager',
				WP_DRIVE_URL . 'assets/js/file-manager.js',
				[],
				$ver,
				true
			);
			wp_enqueue_script(
				'wp-drive-picker',
				WP_DRIVE_URL . 'assets/js/drive-picker.js',
				[ 'wp-drive-file-manager' ],
				$ver,
				true
			);
			wp_enqueue_script(
				'wp-drive-downloader',
				WP_DRIVE_URL . 'assets/js/drive-downloader.js',
				[ 'wp-drive-file-manager' ],
				$ver,
				true
			);
			wp_localize_script( 'wp-drive-file-manager', 'wpDrive', $this->js_data() );
		}
	}

	// -------------------------------------------------------------------------
	// Page renderers
	// -------------------------------------------------------------------------

	public function render_main_page(): void {
		$wizard_done = get_option( 'wp_drive_wizard_completed', false );

		if ( ! $wizard_done ) {
			include WP_DRIVE_DIR . 'templates/wizard.php';
		} else {
			include WP_DRIVE_DIR . 'templates/settings.php';
		}
	}

	public function render_file_manager_page(): void {
		if ( ! $this->oauth->is_connected() ) {
			$this->render_not_connected_notice();
			return;
		}
		include WP_DRIVE_DIR . 'templates/file-manager.php';
	}

	// -------------------------------------------------------------------------
	// Notices
	// -------------------------------------------------------------------------

	public function show_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'wp-drive' ) === false ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET[ self::CONNECTED_PARAM ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo '<strong>' . esc_html__( 'WP Drive', 'wp-drive-taahzino' ) . ':</strong> ';
			echo esc_html__( 'Successfully connected to Google Drive!', 'wp-drive-taahzino' );
			echo '</p></div>';
		}

		if ( ! empty( $_GET[ self::ERROR_PARAM ] ) ) {
			$err = sanitize_text_field( wp_unslash( $_GET[ self::ERROR_PARAM ] ) );
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo '<strong>' . esc_html__( 'WP Drive:', 'wp-drive-taahzino' ) . '</strong> ';
			/* translators: %s: error code */
			echo esc_html( sprintf( __( 'Google OAuth error: %s', 'wp-drive-taahzino' ), $err ) );
			echo '</p></div>';
		}
		// phpcs:enable
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function js_data(): array {
		return [
			'restBase'     => rest_url( 'wp-drive/v1' ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'connected'    => $this->oauth->is_connected(),
			'uploadsUrl'   => wp_upload_dir()['baseurl'],
			'settingsUrl'  => admin_url( 'admin.php?page=wp-drive-settings' ),
			'fileManagerUrl' => admin_url( 'admin.php?page=wp-drive-file-manager' ),
			'wizardDone'   => (bool) get_option( 'wp_drive_wizard_completed', false ),
			'i18n'         => [
				'connecting'   => __( 'Connecting…', 'wp-drive-taahzino' ),
				'connected'    => __( 'Connected', 'wp-drive-taahzino' ),
				'disconnect'   => __( 'Disconnect', 'wp-drive-taahzino' ),
				'error'        => __( 'Something went wrong. Please try again.', 'wp-drive-taahzino' ),
			],
		];
	}

	private function render_not_connected_notice(): void {
		echo '<div class="wrap wpd-not-connected">';
		echo '<div class="wpd-notice-card">';
		echo '<div class="wpd-notice-icon">&#128279;</div>';
		echo '<h2>' . esc_html__( 'Not Connected', 'wp-drive-taahzino' ) . '</h2>';
		echo '<p>' . esc_html__( 'Please connect your Google Drive account in the Settings page first.', 'wp-drive-taahzino' ) . '</p>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=wp-drive-settings' ) ) . '" class="wpd-btn wpd-btn-primary">';
		echo esc_html__( 'Go to Settings', 'wp-drive-taahzino' );
		echo '</a>';
		echo '</div>';
		echo '</div>';
	}

	private function get_menu_icon(): string {
		// Simple Drive-inspired SVG icon (base64-encoded).
		return 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><path d="M7.5 3L2 13.5 6.5 21h11l4.5-7.5L17.5 3H7.5z" fill="none" stroke="#a0a5aa" stroke-width="1.5"/><path d="M2 13.5h20M7.5 3l4.5 10.5M17.5 3L13 13.5" stroke="#a0a5aa" stroke-width="1.5"/></svg>' );
	}

	// -------------------------------------------------------------------------
	// Deactivation confirmation modal
	// -------------------------------------------------------------------------

	/**
	 * Injects a confirmation modal + JS into the plugins.php footer.
	 * Intercepts the deactivation link for this plugin so the user must
	 * explicitly confirm that all data will be erased before proceeding.
	 */
	public function render_deactivation_modal(): void {
		$plugin_slug = 'wp-drive-taahzino/wp-drive-taahzino.php';
		?>
		<!-- WP Drive deactivation confirmation modal -->
		<div id="wpdDeactivateOverlay" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.55);backdrop-filter:blur(3px);align-items:center;justify-content:center;">
		  <div id="wpdDeactivateModal" role="dialog" aria-modal="true" aria-labelledby="wpdDeactivateTitle"
		       style="background:#fff;border-radius:16px;padding:40px 36px 32px;max-width:460px;width:calc(100% - 40px);box-shadow:0 25px 60px rgba(0,0,0,.25);text-align:center;position:relative;">

		    <!-- Icon -->
		    <div style="width:64px;height:64px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:28px;">&#128465;&#65039;</div>

		    <h2 id="wpdDeactivateTitle" style="margin:0 0 12px;font-size:20px;font-weight:700;color:#0f172a;">
		      <?php esc_html_e( 'Deactivate WP Drive?', 'wp-drive-taahzino' ); ?>
		    </h2>

		    <p style="margin:0 0 20px;color:#475569;font-size:14px;line-height:1.6;">
		      <?php esc_html_e( 'Deactivating will immediately and permanently delete:', 'wp-drive-taahzino' ); ?>
		    </p>

		    <ul style="text-align:left;margin:0 0 24px;padding:0 0 0 20px;color:#475569;font-size:13px;line-height:2;">
		      <li><?php esc_html_e( 'Your Google OAuth tokens (you will need to re-authorise)', 'wp-drive-taahzino' ); ?></li>
		      <li><?php esc_html_e( 'Your Client ID and Client Secret', 'wp-drive-taahzino' ); ?></li>
		      <li><?php esc_html_e( 'All plugin settings and wizard progress', 'wp-drive-taahzino' ); ?></li>
		      <li><?php esc_html_e( 'Any pending upload job records', 'wp-drive-taahzino' ); ?></li>
		    </ul>

		    <p style="margin:0 0 28px;padding:12px 16px;background:#fef2f2;border-radius:8px;color:#b91c1c;font-size:13px;font-weight:600;">
		      &#9888;&#65039; <?php esc_html_e( 'This cannot be undone.', 'wp-drive-taahzino' ); ?>
		    </p>

		    <div style="display:flex;gap:12px;justify-content:center;">
		      <button id="wpdDeactivateCancel" type="button"
		              style="flex:1;padding:12px 20px;border:1.5px solid #e2e8f0;border-radius:10px;background:#fff;color:#334155;font-size:14px;font-weight:600;cursor:pointer;">
		        <?php esc_html_e( 'Cancel', 'wp-drive-taahzino' ); ?>
		      </button>
		      <a id="wpdDeactivateConfirm" href="#"
		         style="flex:1;padding:12px 20px;border:none;border-radius:10px;background:#dc2626;color:#fff;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;">
		        &#128465;&#65039; <?php esc_html_e( 'Yes, Deactivate', 'wp-drive-taahzino' ); ?>
		      </a>
		    </div>
		  </div>
		</div>

		<script>
		(function () {
		  var PLUGIN = <?php echo wp_json_encode( $plugin_slug ); ?>;

		  function ready(fn) {
		    if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
		  }

		  ready(function () {
		    // The plugins table uses data-plugin attribute on <tr> rows.
		    var row = document.querySelector('tr[data-plugin="' + PLUGIN + '"]');
		    if (!row) return;

		    var deactivateLink = row.querySelector('.deactivate a');
		    if (!deactivateLink) return;

		    var overlay  = document.getElementById('wpdDeactivateOverlay');
		    var confirm  = document.getElementById('wpdDeactivateConfirm');
		    var cancel   = document.getElementById('wpdDeactivateCancel');

		    function openModal(href) {
		      confirm.href = href;
		      overlay.style.display = 'flex';
		      cancel.focus();
		    }

		    function closeModal() {
		      overlay.style.display = 'none';
		    }

		    // Intercept the deactivation link.
		    deactivateLink.addEventListener('click', function (e) {
		      e.preventDefault();
		      openModal(this.href);
		    });

		    // Close on Cancel button.
		    cancel.addEventListener('click', closeModal);

		    // Close on backdrop click.
		    overlay.addEventListener('click', function (e) {
		      if (e.target === overlay) closeModal();
		    });

		    // Close on Escape key.
		    document.addEventListener('keydown', function (e) {
		      if (e.key === 'Escape' && overlay.style.display === 'flex') closeModal();
		    });
		  });
		}());
		</script>
		<?php
	}
}
