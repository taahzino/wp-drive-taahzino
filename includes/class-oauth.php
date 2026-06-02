<?php
namespace WPDrive;

use Google\Client;
use Google\Service\Oauth2 as GoogleOauth2;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the full Google OAuth2 token lifecycle.
 * Tokens are AES-256-CBC encrypted before storage.
 */
class OAuth {

	const TOKEN_OPTION        = 'wp_drive_tokens';
	const CLIENT_ID_OPTION    = 'wp_drive_client_id';
	const CLIENT_SEC_OPTION   = 'wp_drive_client_secret_enc';
	const REDIRECT_URI_OPTION = 'wp_drive_redirect_uri_override';
	const STATE_TRANSIENT     = 'wp_drive_oauth_state_';

	const SCOPES = [
		'https://www.googleapis.com/auth/drive.file',
		'https://www.googleapis.com/auth/userinfo.email',
		'https://www.googleapis.com/auth/userinfo.profile',
	];

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Returns the redirect URI — the override value if set, otherwise the auto-generated REST URL.
	 * The override is needed when running on non-public TLDs like .local (Google rejects those).
	 */
	public function get_redirect_uri(): string {
		$override = trim( get_option( self::REDIRECT_URI_OPTION, '' ) );
		return $override !== '' ? $override : rest_url( 'wp-drive/v1/auth/callback' );
	}

	/**
	 * Returns a base (unauthenticated) Google Client configured with saved credentials.
	 */
	public function get_client(): Client {
		$client = new Client();
		$client->setApplicationName( 'WP Drive' );
		$client->setClientId( get_option( self::CLIENT_ID_OPTION, '' ) );
		$client->setClientSecret( $this->decrypt( get_option( self::CLIENT_SEC_OPTION, '' ) ) );
		$client->setRedirectUri( $this->get_redirect_uri() );
		$client->setScopes( self::SCOPES );
		$client->setAccessType( 'offline' );
		$client->setPrompt( 'consent' );
		return $client;
	}

	/**
	 * Builds the Google OAuth2 consent URL and stores a CSRF state token.
	 *
	 * The state value encodes the user ID so the callback can locate the
	 * transient without needing an authenticated session.
	 * Format: base64( user_id . ':' . random_uuid )
	 */
	public function get_consent_url(): string {
		$client  = $this->get_client();
		$user_id = get_current_user_id();
		$token   = wp_generate_uuid4();
		$state   = base64_encode( $user_id . ':' . $token );

		set_transient( self::STATE_TRANSIENT . $user_id, $token, 600 );
		$client->setState( $state );
		return $client->createAuthUrl();
	}

	/**
	 * Exchanges an auth code for tokens and stores them encrypted.
	 * The callback arrives unauthenticated (Google redirect), so we decode
	 * the user ID from the state value instead of relying on the session.
	 *
	 * @return bool|string True on success, error code string on failure.
	 */
	public function handle_callback( string $code, string $state ) {
		// Decode state — format: base64( user_id ':' uuid )
		$decoded = base64_decode( $state, true );
		if ( false === $decoded || substr_count( $decoded, ':' ) < 1 ) {
			error_log( 'WP Drive: invalid state format.' );
			return 'state_invalid';
		}

		[ $user_id_str, $token ] = explode( ':', $decoded, 2 );
		$user_id = (int) $user_id_str;

		$stored = get_transient( self::STATE_TRANSIENT . $user_id );
		if ( ! $stored ) {
			error_log( 'WP Drive: state transient missing or expired for user ' . $user_id );
			return 'state_expired';
		}
		if ( ! hash_equals( $stored, $token ) ) {
			error_log( 'WP Drive: state CSRF mismatch.' );
			return 'state_mismatch';
		}
		delete_transient( self::STATE_TRANSIENT . $user_id );

		$client = $this->get_client();
		$result = $client->fetchAccessTokenWithAuthCode( $code );

		if ( isset( $result['error'] ) ) {
			error_log( 'WP Drive: token exchange failed — ' . $result['error'] . ': ' . ( $result['error_description'] ?? '' ) );
			return 'token_' . sanitize_key( $result['error'] );
		}

		update_option( self::TOKEN_OPTION, $this->encrypt( wp_json_encode( $result ) ), false );
		return true;
	}

	/**
	 * Returns an authenticated Google Client, auto-refreshing the token if expired.
	 */
	public function get_authenticated_client(): ?Client {
		$encrypted = get_option( self::TOKEN_OPTION, '' );
		if ( ! $encrypted ) {
			return null;
		}

		$token_json = $this->decrypt( $encrypted );
		if ( ! $token_json ) {
			return null;
		}

		$token = json_decode( $token_json, true );
		if ( ! $token ) {
			return null;
		}

		$client = $this->get_client();
		$client->setAccessToken( $token );

		if ( $client->isAccessTokenExpired() ) {
			if ( empty( $token['refresh_token'] ) ) {
				$this->disconnect();
				return null;
			}
			$new_token = $client->fetchAccessTokenWithRefreshToken( $token['refresh_token'] );
			if ( isset( $new_token['error'] ) ) {
				$this->disconnect();
				return null;
			}
			// Preserve refresh token if new response omits it (Google behaviour).
			if ( empty( $new_token['refresh_token'] ) ) {
				$new_token['refresh_token'] = $token['refresh_token'];
			}
			$client->setAccessToken( $new_token );
			update_option( self::TOKEN_OPTION, $this->encrypt( wp_json_encode( $new_token ) ), false );
		}

		return $client;
	}

	public function is_connected(): bool {
		return ! empty( get_option( self::TOKEN_OPTION, '' ) );
	}

	/**
	 * Returns the connected Google account info or null if not connected.
	 *
	 * @return array{email:string,name:string,picture:string}|null
	 */
	public function get_user_info(): ?array {
		$client = $this->get_authenticated_client();
		if ( ! $client ) {
			return null;
		}
		try {
			$service  = new GoogleOauth2( $client );
			$userinfo = $service->userinfo->get();
			return [
				'email'   => $userinfo->getEmail(),
				'name'    => $userinfo->getName(),
				'picture' => $userinfo->getPicture(),
			];
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Revokes the token and clears stored credentials.
	 */
	public function disconnect(): void {
		$client = $this->get_authenticated_client();
		if ( $client ) {
			try {
				$client->revokeToken();
			} catch ( \Exception $e ) {
				// Best-effort revocation.
			}
		}
		delete_option( self::TOKEN_OPTION );
	}

	/**
	 * Saves Client ID and encrypted Client Secret to wp_options.
	 */
	public function save_credentials( string $client_id, string $client_secret ): void {
		update_option( self::CLIENT_ID_OPTION, sanitize_text_field( $client_id ) );
		update_option( self::CLIENT_SEC_OPTION, $this->encrypt( $client_secret ), false );
	}

	/**
	 * Saves (or clears) the redirect URI override.
	 */
	public function save_redirect_uri_override( string $uri ): void {
		$uri = trim( $uri );
		if ( $uri === '' ) {
			delete_option( self::REDIRECT_URI_OPTION );
		} else {
			update_option( self::REDIRECT_URI_OPTION, esc_url_raw( $uri ) );
		}
	}

	public function has_credentials(): bool {
		return ! empty( get_option( self::CLIENT_ID_OPTION, '' ) );
	}

	// -------------------------------------------------------------------------
	// Encryption helpers
	// -------------------------------------------------------------------------

	private function encryption_key(): string {
		$salt = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'wp-drive-fallback-key';
		return hash( 'sha256', $salt . AUTH_KEY, true );
	}

	private function encrypt( string $data ): string {
		$key        = $this->encryption_key();
		$iv         = openssl_random_pseudo_bytes( 16 );
		$ciphertext = openssl_encrypt( $data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return base64_encode( $iv . $ciphertext );
	}

	private function decrypt( string $data ): string {
		if ( empty( $data ) ) {
			return '';
		}
		$decoded = base64_decode( $data, true );
		if ( false === $decoded || strlen( $decoded ) < 17 ) {
			return '';
		}
		$key        = $this->encryption_key();
		$iv         = substr( $decoded, 0, 16 );
		$ciphertext = substr( $decoded, 16 );
		$result     = openssl_decrypt( $ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return false === $result ? '' : $result;
	}
}
