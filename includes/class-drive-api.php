<?php
namespace WPDrive;

use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Http\MediaFileUpload;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps Google Drive API v3 operations.
 */
class DriveAPI {

	private OAuth $oauth;

	public function __construct( OAuth $oauth ) {
		$this->oauth = $oauth;
	}

	// -------------------------------------------------------------------------
	// File listing
	// -------------------------------------------------------------------------

	/**
	 * Lists files and folders inside a Drive folder.
	 *
	 * @param string $folder_id Drive folder ID. 'root' for My Drive root.
	 * @return array{folders:array,files:array}
	 * @throws \RuntimeException On API error.
	 */
	public function list_files( string $folder_id = 'root' ): array {
		$client  = $this->require_client();
		$service = new Drive( $client );

		$params = [
			'q'                         => sprintf( "'%s' in parents and trashed = false", addslashes( $folder_id ) ),
			'fields'                    => 'files(id,name,mimeType,size,modifiedTime,iconLink)',
			'orderBy'                   => 'folder,name',
			'pageSize'                  => 200,
			'supportsAllDrives'         => false,
			'includeItemsFromAllDrives' => false,
		];

		try {
			$result  = $service->files->listFiles( $params );
			$folders = [];
			$files   = [];

			foreach ( $result->getFiles() as $file ) {
				$is_folder = $file->getMimeType() === 'application/vnd.google-apps.folder';
				$item      = [
					'id'       => $file->getId(),
					'name'     => $file->getName(),
					'mimeType' => $file->getMimeType(),
					'size'     => $file->getSize(),
					'modified' => $file->getModifiedTime(),
				];
				if ( $is_folder ) {
					$folders[] = $item;
				} else {
					$files[] = $item;
				}
			}

			return compact( 'folders', 'files' );
		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'Drive API error: ' . $e->getMessage(), $e->getCode(), $e );
		}
	}

	// -------------------------------------------------------------------------
	// Folder creation
	// -------------------------------------------------------------------------

	/**
	 * Creates a folder on Google Drive.
	 *
	 * @param string $name      Folder display name.
	 * @param string $parent_id Parent Drive folder ID.
	 * @return string New Drive folder ID.
	 * @throws \RuntimeException On API error.
	 */
	public function create_folder( string $name, string $parent_id ): string {
		$client  = $this->require_client();
		$service = new Drive( $client );

		$meta = new DriveFile();
		$meta->setName( $name );
		$meta->setMimeType( 'application/vnd.google-apps.folder' );
		$meta->setParents( [ $parent_id ] );

		try {
			$folder = $service->files->create( $meta, [ 'fields' => 'id' ] );
			return $folder->getId();
		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'Could not create folder: ' . $e->getMessage(), $e->getCode(), $e );
		}
	}

	// -------------------------------------------------------------------------
	// File upload
	// -------------------------------------------------------------------------

	/**
	 * Uploads a single local file to Google Drive.
	 *
	 * Files > 5 MB use resumable upload (chunked). Files ≤ 5 MB use multipart upload.
	 * An optional progress callback is called after each chunk with (bytes_sent, total_bytes).
	 *
	 * @param string        $abs_path      Absolute local file path.
	 * @param string        $parent_id     Destination Drive folder ID.
	 * @param string        $name          File name on Drive (defaults to basename).
	 * @param callable|null $on_progress   fn(int $bytes_sent, int $total_bytes): void
	 * @return string Uploaded Drive file ID.
	 * @throws \RuntimeException On API error or missing file.
	 */
	public function upload_file( string $abs_path, string $parent_id, string $name = '', ?callable $on_progress = null ): string {
		if ( ! is_readable( $abs_path ) ) {
			throw new \RuntimeException( "File not readable: {$abs_path}" );
		}

		$client  = $this->require_client();
		$service = new Drive( $client );

		$display_name = $name ?: basename( $abs_path );
		$mime_type    = mime_content_type( $abs_path ) ?: 'application/octet-stream';
		$file_size    = filesize( $abs_path );

		$meta = new DriveFile();
		$meta->setName( $display_name );
		$meta->setParents( [ $parent_id ] );

		try {
			if ( $file_size > 5 * 1024 * 1024 ) {
				// Resumable upload: stream in 5 MB chunks so we can report progress.
				$client->setDefer( true );
				$request = $service->files->create( $meta, [
					'uploadType' => 'resumable',
					'fields'     => 'id',
				] );

				$chunk_size = 5 * 1024 * 1024;
				$media      = new MediaFileUpload( $client, $request, $mime_type, null, true, $chunk_size );
				$media->setFileSize( $file_size );
				$client->setDefer( false );

				$status     = false;
				$bytes_sent = 0;
				$handle     = fopen( $abs_path, 'rb' );

				while ( ! $status && ! feof( $handle ) ) {
					$chunk       = fread( $handle, $chunk_size );
					$status      = $media->nextChunk( $chunk );
					$bytes_sent += strlen( $chunk );

					if ( $on_progress ) {
						$on_progress( $bytes_sent, $file_size );
					}
				}
				fclose( $handle );

				if ( $status instanceof DriveFile ) {
					return $status->getId();
				}
				throw new \RuntimeException( 'Resumable upload did not complete.' );
			} else {
				// Multipart upload for small files — single request, no chunk progress.
				if ( $on_progress ) {
					$on_progress( 0, $file_size ); // signal: started.
				}
				$result = $service->files->create( $meta, [
					'data'       => file_get_contents( $abs_path ),
					'mimeType'   => $mime_type,
					'uploadType' => 'multipart',
					'fields'     => 'id',
				] );
				if ( $on_progress ) {
					$on_progress( $file_size, $file_size ); // signal: done.
				}
				return $result->getId();
			}
		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'Upload failed: ' . $e->getMessage(), $e->getCode(), $e );
		}
	}

	// -------------------------------------------------------------------------
	// File download
	// -------------------------------------------------------------------------

	/**
	 * Recursively lists all files and folders inside a Drive folder.
	 * Returns a flat array ordered depth-first (parents before children).
	 *
	 * @param string $folder_id  Drive folder ID.
	 * @param string $rel_prefix Relative path built so far (empty for top-level).
	 * @return array Flat list of { drive_id, name, rel_path, type:'file'|'folder', size, mime }
	 * @throws \RuntimeException On API error.
	 */
	public function list_folder_recursive( string $folder_id, string $rel_prefix = '' ): array {
		$client  = $this->require_client();
		$service = new Drive( $client );

		$params = [
			'q'        => sprintf( "'%s' in parents and trashed = false", addslashes( $folder_id ) ),
			'fields'   => 'files(id,name,mimeType,size)',
			'orderBy'  => 'folder,name',
			'pageSize' => 200,
		];

		$items = [];

		try {
			$result = $service->files->listFiles( $params );

			foreach ( $result->getFiles() as $file ) {
				$is_folder = $file->getMimeType() === 'application/vnd.google-apps.folder';
				$rel_path  = $rel_prefix !== '' ? $rel_prefix . '/' . $file->getName() : $file->getName();

				$items[] = [
					'drive_id' => $file->getId(),
					'name'     => $file->getName(),
					'rel_path' => $rel_path,
					'type'     => $is_folder ? 'folder' : 'file',
					'size'     => (int) $file->getSize(),
					'mime'     => $file->getMimeType(),
				];

				if ( $is_folder ) {
					$children = $this->list_folder_recursive( $file->getId(), $rel_path );
					$items    = array_merge( $items, $children );
				}
			}

			return $items;
		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'Drive API error: ' . $e->getMessage(), $e->getCode(), $e );
		}
	}

	/**
	 * Downloads a single Drive file to a local absolute path.
	 * Streams in 5 MB chunks so large files never buffer in memory.
	 * An optional progress callback is called after each chunk with (bytes_received, total_bytes).
	 *
	 * @param string        $drive_file_id  Drive file ID.
	 * @param string        $abs_local_path Absolute local destination path.
	 * @param callable|null $on_progress    fn(int $bytes_received, int $total_bytes): void
	 * @throws \RuntimeException On API error or filesystem write failure.
	 */
	public function download_file( string $drive_file_id, string $abs_local_path, ?callable $on_progress = null ): void {
		$client  = $this->require_client();
		$service = new Drive( $client );

		// Fetch metadata to know total size for accurate progress reporting.
		try {
			$meta        = $service->files->get( $drive_file_id, [ 'fields' => 'name,size,mimeType' ] );
			$total_bytes = max( 0, (int) $meta->getSize() );
		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'Could not fetch file metadata: ' . $e->getMessage(), $e->getCode(), $e );
		}

		// Ensure parent directory exists.
		wp_mkdir_p( dirname( $abs_local_path ) );

		if ( $on_progress && $total_bytes > 0 ) {
			$on_progress( 0, $total_bytes ); // Signal: starting.
		}

		// Stream via authorized Guzzle client — avoids buffering entire file in memory.
		$http_client = $client->authorize();

		try {
			$response = $http_client->request(
				'GET',
				'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $drive_file_id ) . '?alt=media',
				[ 'stream' => true ]
			);
		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'Download request failed: ' . $e->getMessage(), $e->getCode(), $e );
		}

		$handle = fopen( $abs_local_path, 'wb' );
		if ( ! $handle ) {
			throw new \RuntimeException( "Cannot write to: {$abs_local_path}" );
		}

		$body           = $response->getBody();
		$bytes_received = 0;
		$chunk_size     = 5 * 1024 * 1024;

		try {
			while ( ! $body->eof() ) {
				$chunk           = $body->read( $chunk_size );
				fwrite( $handle, $chunk );
				$bytes_received += strlen( $chunk );

				if ( $on_progress ) {
					$on_progress( $bytes_received, $total_bytes ?: $bytes_received );
				}
			}
		} finally {
			fclose( $handle );
		}

		// Signal: fully done (handles edge case where final chunk == total).
		if ( $on_progress && $total_bytes > 0 ) {
			$on_progress( $total_bytes, $total_bytes );
		}
	}

	// -------------------------------------------------------------------------
	// Background upload processor (called by WP-Cron)
	// -------------------------------------------------------------------------

	/**
	 * Processes an entire upload job in the background.
	 * Called by WP-Cron — runs without any HTTP timeout constraints.
	 *
	 * @param string $job_id
	 */
	public static function run_job_cron( string $job_id ): void {
		$job = get_transient( 'wp_drive_job_' . $job_id );
		if ( ! $job ) {
			return;
		}

		// Prevent timeouts — we own this process.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}
		ignore_user_abort( true );

		$instance = new self( Plugin::get_instance()->oauth );

		$job['status'] = 'running';
		set_transient( 'wp_drive_job_' . $job_id, $job, HOUR_IN_SECONDS );

		foreach ( $job['items'] as &$item ) {
			if ( 'pending' !== $item['status'] ) {
				continue;
			}

			$item['status'] = 'running';
			set_transient( 'wp_drive_job_' . $job_id, $job, HOUR_IN_SECONDS );

			try {
				if ( 'create_folder' === $item['type'] ) {
					$drive_id         = $instance->create_folder( $item['name'], $item['parent_id'] );
					$item['drive_id'] = $drive_id;
					$item['status']   = 'done';

					// Propagate the new folder ID to children waiting on this folder.
					foreach ( $job['items'] as &$other ) {
						if ( isset( $other['parent_ref'] ) && $other['parent_ref'] === $item['rel'] ) {
							$other['parent_id'] = $drive_id;
						}
					}
					unset( $other );

					set_transient( 'wp_drive_job_' . $job_id, $job, HOUR_IN_SECONDS );
				} else {
					// Upload with a per-chunk progress callback that saves to the transient.
					$drive_id = $instance->upload_file(
						$item['abs'],
						$item['parent_id'],
						$item['name'],
						static function ( int $bytes_sent, int $total_bytes ) use ( $job_id, &$job, &$item ): void {
							$item['bytes_sent']  = $bytes_sent;
							$item['total_bytes'] = $total_bytes;
							// Throttle: save at most once per ~0.5 s to avoid hammering the DB.
							static $last_save = 0;
							$now = microtime( true );
							if ( $now - $last_save >= 0.5 || $bytes_sent === $total_bytes ) {
								set_transient( 'wp_drive_job_' . $job_id, $job, HOUR_IN_SECONDS );
								$last_save = $now;
							}
						}
					);
					$item['drive_id']    = $drive_id;
					$item['status']      = 'done';
					$item['bytes_sent']  = $item['total_bytes'] ?? 0;
					$job['completed'] ++;
					set_transient( 'wp_drive_job_' . $job_id, $job, HOUR_IN_SECONDS );
				}
			} catch ( \Exception $e ) {
				$item['status'] = 'failed';
				$item['error']  = $e->getMessage();
				error_log( 'WP Drive upload error [' . $item['name'] . ']: ' . $e->getMessage() );
				if ( 'file' === $item['type'] ) {
					$job['completed'] ++;
				}
				set_transient( 'wp_drive_job_' . $job_id, $job, HOUR_IN_SECONDS );
			}
		}
		unset( $item );

		$job['status'] = 'done';
		set_transient( 'wp_drive_job_' . $job_id, $job, HOUR_IN_SECONDS );
	}

	// -------------------------------------------------------------------------
	// Background download processor (called by WP-Cron)
	// -------------------------------------------------------------------------

	/**
	 * Processes an entire download job in the background.
	 * Called by WP-Cron via the 'wp_drive_process_download_job' hook.
	 *
	 * @param string $job_id
	 */
	public static function run_download_job_cron( string $job_id ): void {
		$job = get_transient( 'wp_drive_download_job_' . $job_id );
		if ( ! $job ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}
		ignore_user_abort( true );

		$instance = new self( Plugin::get_instance()->oauth );

		$job['status'] = 'running';
		set_transient( 'wp_drive_download_job_' . $job_id, $job, HOUR_IN_SECONDS );

		foreach ( $job['items'] as &$item ) {
			if ( 'pending' !== $item['status'] ) {
				continue;
			}

			$item['status'] = 'running';
			set_transient( 'wp_drive_download_job_' . $job_id, $job, HOUR_IN_SECONDS );

			try {
				if ( 'create_dir' === $item['type'] ) {
					if ( ! wp_mkdir_p( $item['abs'] ) ) {
						throw new \RuntimeException( "Could not create directory: {$item['abs']}" );
					}
					$item['status'] = 'done';
					set_transient( 'wp_drive_download_job_' . $job_id, $job, HOUR_IN_SECONDS );
				} else {
					// Download with a per-chunk progress callback.
					$instance->download_file(
						$item['drive_id'],
						$item['abs'],
						static function ( int $bytes_received, int $total_bytes ) use ( $job_id, &$job, &$item ): void {
							$item['bytes_received'] = $bytes_received;
							$item['total_bytes']    = $total_bytes;
							static $last_save = 0;
							$now = microtime( true );
							if ( $now - $last_save >= 0.5 || $bytes_received === $total_bytes ) {
								set_transient( 'wp_drive_download_job_' . $job_id, $job, HOUR_IN_SECONDS );
								$last_save = $now;
							}
						}
					);
					$item['status']         = 'done';
					$item['bytes_received'] = $item['total_bytes'] ?? 0;
					$job['completed'] ++;
					set_transient( 'wp_drive_download_job_' . $job_id, $job, HOUR_IN_SECONDS );
				}
			} catch ( \Exception $e ) {
				$item['status'] = 'failed';
				$item['error']  = $e->getMessage();
				error_log( 'WP Drive download error [' . $item['name'] . ']: ' . $e->getMessage() );
				if ( 'file' === $item['type'] ) {
					$job['completed'] ++;
				}
				set_transient( 'wp_drive_download_job_' . $job_id, $job, HOUR_IN_SECONDS );
			}
		}
		unset( $item );

		$job['status'] = 'done';
		set_transient( 'wp_drive_download_job_' . $job_id, $job, HOUR_IN_SECONDS );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * @throws \RuntimeException If not connected.
	 */
	private function require_client(): \Google\Client {
		$client = $this->oauth->get_authenticated_client();
		if ( ! $client ) {
			throw new \RuntimeException( 'Not connected to Google Drive.' );
		}
		return $client;
	}
}
