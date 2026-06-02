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
	 * Uploads a single local file to Google Drive using resumable upload.
	 *
	 * @param string   $abs_path  Absolute local file path.
	 * @param string   $parent_id Destination Drive folder ID.
	 * @param string   $name      File name on Drive (defaults to basename).
	 * @return string  Uploaded Drive file ID.
	 * @throws \RuntimeException On API error or missing file.
	 */
	public function upload_file( string $abs_path, string $parent_id, string $name = '' ): string {
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
				// Resumable upload for files larger than 5 MB.
				$client->setDefer( true );
				$request = $service->files->create( $meta, [
					'uploadType' => 'resumable',
					'fields'     => 'id',
				] );

				$chunk_size = 5 * 1024 * 1024;
				$media      = new MediaFileUpload( $client, $request, $mime_type, null, true, $chunk_size );
				$media->setFileSize( $file_size );
				$client->setDefer( false );

				$status = false;
				$handle = fopen( $abs_path, 'rb' );
				while ( ! $status && ! feof( $handle ) ) {
					$chunk  = fread( $handle, $chunk_size );
					$status = $media->nextChunk( $chunk );
				}
				fclose( $handle );

				if ( $status instanceof DriveFile ) {
					return $status->getId();
				}
				throw new \RuntimeException( 'Resumable upload did not complete.' );
			} else {
				// Multipart upload for small files.
				$result = $service->files->create( $meta, [
					'data'       => file_get_contents( $abs_path ),
					'mimeType'   => $mime_type,
					'uploadType' => 'multipart',
					'fields'     => 'id',
				] );
				return $result->getId();
			}
		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'Upload failed: ' . $e->getMessage(), $e->getCode(), $e );
		}
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
