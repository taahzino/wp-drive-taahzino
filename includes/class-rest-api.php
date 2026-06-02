<?php
namespace WPDrive;

defined( 'ABSPATH' ) || exit;

/**
 * Registers all WP REST API routes for the plugin.
 * Namespace: wp-drive/v1
 */
class RestAPI {

	const NS = 'wp-drive/v1';

	private OAuth       $oauth;
	private DriveAPI    $drive;
	private FileManager $files;

	public function __construct( OAuth $oauth, DriveAPI $drive, FileManager $files ) {
		$this->oauth = $oauth;
		$this->drive = $drive;
		$this->files = $files;

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$auth = [ $this, 'require_admin' ];

		// Auth routes.
		register_rest_route( self::NS, '/auth/connect', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'auth_connect' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NS, '/auth/callback', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'auth_callback' ],
			'permission_callback' => '__return_true', // Public — CSRF via state param.
		] );

		register_rest_route( self::NS, '/auth/status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'auth_status' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NS, '/auth/disconnect', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'auth_disconnect' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NS, '/auth/credentials', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'save_credentials' ],
			'permission_callback' => $auth,
			'args'                => [
				'client_id'          => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'client_secret'      => [ 'required' => true, 'type' => 'string' ],
				'redirect_uri_override' => [ 'required' => false, 'type' => 'string', 'default' => '' ],
			],
		] );

		// Local file system routes.
		register_rest_route( self::NS, '/local/files', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'local_files' ],
			'permission_callback' => $auth,
			'args'                => [
				'path' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );

		// Google Drive routes.
		register_rest_route( self::NS, '/drive/files', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'drive_files' ],
			'permission_callback' => $auth,
			'args'                => [
				'folder_id' => [ 'type' => 'string', 'default' => 'root', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );

		// Upload routes.
		register_rest_route( self::NS, '/drive/upload/start', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'upload_start' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NS, '/drive/upload/step', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'upload_step' ],
			'permission_callback' => $auth,
			'args'                => [
				'job_id' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Auth handlers
	// -------------------------------------------------------------------------

	public function auth_connect( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! $this->oauth->has_credentials() ) {
			return $this->error( 'no_credentials', 'Client ID and Secret are not configured.', 400 );
		}
		return new \WP_REST_Response( [ 'url' => $this->oauth->get_consent_url() ] );
	}

	public function auth_callback( \WP_REST_Request $req ) {
		$code  = $req->get_param( 'code' );
		$state = $req->get_param( 'state' );
		$error = $req->get_param( 'error' );

		if ( $error ) {
			wp_redirect( admin_url( 'admin.php?page=wp-drive-settings&wp_drive_error=' . urlencode( $error ) ) );
			exit;
		}

		if ( ! $code || ! $state ) {
			wp_redirect( admin_url( 'admin.php?page=wp-drive-settings&wp_drive_error=invalid_callback' ) );
			exit;
		}

		$result = $this->oauth->handle_callback( $code, $state );

		if ( true === $result ) {
			// Mark wizard as completed.
			update_option( 'wp_drive_wizard_completed', true );
			wp_redirect( admin_url( 'admin.php?page=wp-drive-settings&wp_drive_connected=1' ) );
		} else {
			// $result is a specific error code string — log and surface it.
			$error_code = is_string( $result ) ? $result : 'auth_failed';
			wp_redirect( admin_url( 'admin.php?page=wp-drive-settings&wp_drive_error=' . rawurlencode( $error_code ) ) );
		}
		exit;
	}

	public function auth_status( \WP_REST_Request $req ): \WP_REST_Response {
		$redirect_uri = $this->oauth->get_redirect_uri();
		if ( ! $this->oauth->is_connected() ) {
			return new \WP_REST_Response( [ 'connected' => false, 'redirect_uri' => $redirect_uri ] );
		}
		$info = $this->oauth->get_user_info();
		return new \WP_REST_Response( [
			'connected'    => true,
			'email'        => $info['email'] ?? null,
			'name'         => $info['name'] ?? null,
			'picture'      => $info['picture'] ?? null,
			'redirect_uri' => $redirect_uri,
		] );
	}

	public function auth_disconnect( \WP_REST_Request $req ): \WP_REST_Response {
		$this->oauth->disconnect();
		return new \WP_REST_Response( [ 'success' => true ] );
	}

	public function save_credentials( \WP_REST_Request $req ): \WP_REST_Response {
		$client_id             = $req->get_param( 'client_id' );
		$client_secret         = $req->get_param( 'client_secret' );
		$redirect_uri_override = $req->get_param( 'redirect_uri_override' ) ?? '';

		$this->oauth->save_credentials( $client_id, $client_secret );
		$this->oauth->save_redirect_uri_override( $redirect_uri_override );

		return new \WP_REST_Response( [
			'success'      => true,
			'redirect_uri' => $this->oauth->get_redirect_uri(),
		] );
	}

	// -------------------------------------------------------------------------
	// Local file system handlers
	// -------------------------------------------------------------------------

	public function local_files( \WP_REST_Request $req ): \WP_REST_Response {
		$path = $req->get_param( 'path' );
		try {
			$items = $this->files->list_directory( $path );
			return new \WP_REST_Response( [ 'items' => $items, 'path' => $path ] );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( 'invalid_path', $e->getMessage(), 403 );
		}
	}

	// -------------------------------------------------------------------------
	// Google Drive handlers
	// -------------------------------------------------------------------------

	public function drive_files( \WP_REST_Request $req ): \WP_REST_Response {
		$folder_id = $req->get_param( 'folder_id' ) ?: 'root';
		try {
			$result = $this->drive->list_files( $folder_id );
			return new \WP_REST_Response( $result );
		} catch ( \RuntimeException $e ) {
			return $this->error( 'drive_error', $e->getMessage(), 500 );
		}
	}

	// -------------------------------------------------------------------------
	// Upload handlers
	// -------------------------------------------------------------------------

	/**
	 * Initialises an upload job. Expands folders into a flat file list.
	 *
	 * Body: { items: [{type:'file'|'dir', path:'...'}], destination_folder_id: '...' }
	 */
	public function upload_start( \WP_REST_Request $req ): \WP_REST_Response {
		$body          = $req->get_json_params();
		$items         = $body['items'] ?? [];
		$dest_id       = sanitize_text_field( $body['destination_folder_id'] ?? 'root' );

		if ( empty( $items ) ) {
			return $this->error( 'no_items', 'No items specified.', 400 );
		}

		// Separate files from dirs.
		$file_paths = [];
		$dir_paths  = [];
		foreach ( $items as $item ) {
			$path = sanitize_text_field( $item['path'] ?? '' );
			if ( 'dir' === ( $item['type'] ?? 'file' ) ) {
				$dir_paths[] = $path;
			} else {
				$file_paths[] = $path;
			}
		}

		// Build job: for dirs, create a folder-map entry; for files, flat entries.
		$job_items = [];

		// Plain files first.
		foreach ( $file_paths as $rel ) {
			try {
				$abs       = $this->files->resolve( $rel );
				$job_items[] = [
					'type'      => 'file',
					'rel'       => $rel,
					'abs'       => $abs,
					'name'      => basename( $abs ),
					'mime'      => mime_content_type( $abs ) ?: 'application/octet-stream',
					'parent_id' => $dest_id,
					'status'    => 'pending',
					'drive_id'  => null,
					'error'     => null,
				];
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		// Directories: expand and record tree structure.
		foreach ( $dir_paths as $dir_rel ) {
			try {
				$this->files->resolve( $dir_rel ); // Validates path.
				// We record the dir as a special "create_folder" item,
				// and all its files as subsequent items with relative parent references.
				$this->add_dir_items( $dir_rel, $dest_id, $job_items );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		if ( empty( $job_items ) ) {
			return $this->error( 'no_valid_items', 'No valid items to upload.', 400 );
		}

		$job_id = wp_generate_uuid4();
		$job    = [
			'job_id'                => $job_id,
			'destination_folder_id' => $dest_id,
			'items'                 => $job_items,
			'total'                 => count( array_filter( $job_items, fn( $i ) => $i['type'] === 'file' ) ),
			'completed'             => 0,
			'status'                => 'running',
			'created_at'            => time(),
		];

		set_transient( 'wp_drive_job_' . $job_id, $job, HOUR_IN_SECONDS );

		return new \WP_REST_Response( [
			'job_id' => $job_id,
			'total'  => $job['total'],
			'status' => 'running',
		] );
	}

	/**
	 * Processes the next pending item in a job (one item per call).
	 *
	 * Body: { job_id: '...' }
	 */
	public function upload_step( \WP_REST_Request $req ): \WP_REST_Response {
		$job_id = $req->get_param( 'job_id' );
		$job    = get_transient( 'wp_drive_job_' . $job_id );

		if ( ! $job ) {
			return $this->error( 'job_not_found', 'Upload job not found or expired.', 404 );
		}

		// Find next pending item.
		$next_index = null;
		foreach ( $job['items'] as $i => $item ) {
			if ( 'pending' === $item['status'] ) {
				$next_index = $i;
				break;
			}
		}

		if ( null === $next_index ) {
			// All done.
			$job['status'] = 'done';
			set_transient( 'wp_drive_job_' . $job_id, $job, HOUR_IN_SECONDS );
			return new \WP_REST_Response( $this->job_summary( $job ) );
		}

		$item = &$job['items'][ $next_index ];
		$item['status'] = 'running';

		try {
			if ( 'create_folder' === $item['type'] ) {
				$drive_id             = $this->drive->create_folder( $item['name'], $item['parent_id'] );
				$item['drive_id']     = $drive_id;
				$item['status']       = 'done';
				// Update references: any item whose parent_ref matches this item's path gets this drive_id.
				foreach ( $job['items'] as &$other ) {
					if ( isset( $other['parent_ref'] ) && $other['parent_ref'] === $item['rel'] ) {
						$other['parent_id'] = $drive_id;
					}
				}
				unset( $other );
			} else {
				// File upload.
				$drive_id         = $this->drive->upload_file( $item['abs'], $item['parent_id'], $item['name'] );
				$item['drive_id'] = $drive_id;
				$item['status']   = 'done';
				$job['completed'] ++;
			}
		} catch ( \Exception $e ) {
			$item['status'] = 'failed';
			$item['error']  = $e->getMessage();
			if ( 'file' === $item['type'] ) {
				$job['completed'] ++;
			}
		}

		// Check if all done.
		$pending = array_filter( $job['items'], fn( $i ) => $i['status'] === 'pending' );
		$running = array_filter( $job['items'], fn( $i ) => $i['status'] === 'running' );
		if ( empty( $pending ) && empty( $running ) ) {
			$job['status'] = 'done';
		}

		set_transient( 'wp_drive_job_' . $job_id, $job, HOUR_IN_SECONDS );
		return new \WP_REST_Response( $this->job_summary( $job ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Recursively adds create_folder + file items to the job list.
	 */
	private function add_dir_items( string $dir_rel, string $parent_drive_id, array &$job_items ): void {
		$dir_name    = basename( $dir_rel );
		$job_items[] = [
			'type'      => 'create_folder',
			'rel'       => $dir_rel,
			'name'      => $dir_name,
			'parent_id' => $parent_drive_id,
			'parent_ref'=> null,
			'status'    => 'pending',
			'drive_id'  => null,
			'error'     => null,
		];

		try {
			$children = $this->files->list_directory( $dir_rel );
		} catch ( \Exception $e ) {
			return;
		}

		foreach ( $children as $child ) {
			$child_rel = $child['path'];
			if ( 'dir' === $child['type'] ) {
				// The child dir's parent_id will be resolved after its parent folder is created.
				// We store a parent_ref pointing to the parent dir's rel path.
				$this->add_dir_items_with_ref( $child_rel, $dir_rel, $job_items );
			} else {
				$abs = $this->files->get_base_dir() . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $child_rel );
				$job_items[] = [
					'type'       => 'file',
					'rel'        => $child_rel,
					'abs'        => $abs,
					'name'       => $child['name'],
					'mime'       => $child['mime'] ?? 'application/octet-stream',
					'parent_id'  => '', // Filled after parent folder is created.
					'parent_ref' => $dir_rel,
					'status'     => 'pending',
					'drive_id'   => null,
					'error'      => null,
				];
			}
		}
	}

	private function add_dir_items_with_ref( string $dir_rel, string $parent_rel, array &$job_items ): void {
		$dir_name    = basename( $dir_rel );
		$job_items[] = [
			'type'       => 'create_folder',
			'rel'        => $dir_rel,
			'name'       => $dir_name,
			'parent_id'  => '',
			'parent_ref' => $parent_rel,
			'status'     => 'pending',
			'drive_id'   => null,
			'error'      => null,
		];

		try {
			$children = $this->files->list_directory( $dir_rel );
		} catch ( \Exception $e ) {
			return;
		}

		foreach ( $children as $child ) {
			$child_rel = $child['path'];
			if ( 'dir' === $child['type'] ) {
				$this->add_dir_items_with_ref( $child_rel, $dir_rel, $job_items );
			} else {
				$abs = $this->files->get_base_dir() . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $child_rel );
				$job_items[] = [
					'type'       => 'file',
					'rel'        => $child_rel,
					'abs'        => $abs,
					'name'       => $child['name'],
					'mime'       => $child['mime'] ?? 'application/octet-stream',
					'parent_id'  => '',
					'parent_ref' => $dir_rel,
					'status'     => 'pending',
					'drive_id'   => null,
					'error'      => null,
				];
			}
		}
	}

	private function job_summary( array $job ): array {
		$errors = array_values( array_filter(
			array_map( fn( $i ) => ! empty( $i['error'] ) ? [ 'name' => $i['name'], 'error' => $i['error'] ] : null, $job['items'] )
		) );

		// Include per-file status for frontend progress rows.
		$items_summary = array_values( array_map( static function ( array $i ): array {
			return [
				'type'   => $i['type'],
				'name'   => $i['name'],
				'status' => $i['status'],
				'error'  => $i['error'] ?? null,
			];
		}, $job['items'] ) );

		return [
			'job_id'    => $job['job_id'],
			'status'    => $job['status'],
			'total'     => $job['total'],
			'completed' => $job['completed'],
			'percent'   => $job['total'] > 0 ? (int) round( ( $job['completed'] / $job['total'] ) * 100 ) : 0,
			'errors'    => $errors,
			'items'     => $items_summary,
		];
	}

	private function error( string $code, string $message, int $status = 500 ): \WP_REST_Response {
		return new \WP_REST_Response( [ 'code' => $code, 'message' => $message ], $status );
	}

	public function require_admin(): bool {
		return current_user_can( 'manage_options' );
	}
}
