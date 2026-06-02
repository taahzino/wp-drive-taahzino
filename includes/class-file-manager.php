<?php
namespace WPDrive;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the local WordPress uploads directory safely.
 * All paths are constrained to the uploads base directory.
 */
class FileManager {

	private string $base_dir;

	public function __construct() {
		$this->base_dir = rtrim( ABSPATH, '/\\' );
	}

	/**
	 * Lists the contents of a directory relative to the uploads root.
	 *
	 * @param string $rel_path Relative path (e.g. "2024/01"). Empty = root.
	 * @return array{name:string,path:string,type:string,size:int|null,modified:int,mime:string|null}[]
	 * @throws \InvalidArgumentException If the path escapes the uploads directory.
	 */
	public function list_directory( string $rel_path = '' ): array {
		$abs_path = $this->resolve( $rel_path );

		if ( ! is_dir( $abs_path ) ) {
			throw new \InvalidArgumentException( 'Path is not a directory.' );
		}

		$items  = [];
		$handle = opendir( $abs_path );
		if ( false === $handle ) {
			return [];
		}

		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full     = $abs_path . DIRECTORY_SEPARATOR . $entry;
			$rel      = ltrim( ( '' !== $rel_path ? $rel_path . '/' : '' ) . $entry, '/' );
			$is_dir   = is_dir( $full );
			$size     = $is_dir ? null : filesize( $full );
			$modified = filemtime( $full );
			$mime     = ( ! $is_dir ) ? ( mime_content_type( $full ) ?: 'application/octet-stream' ) : null;

			$items[] = [
				'name'     => $entry,
				'path'     => $rel,
				'type'     => $is_dir ? 'dir' : 'file',
				'size'     => $size,
				'modified' => $modified,
				'mime'     => $mime,
			];
		}
		closedir( $handle );

		// Sort: directories first, then alphabetically.
		usort( $items, static function ( array $a, array $b ): int {
			if ( $a['type'] !== $b['type'] ) {
				return $a['type'] === 'dir' ? -1 : 1;
			}
			return strcasecmp( $a['name'], $b['name'] );
		} );

		return $items;
	}

	/**
	 * Recursively expands a list of paths into a flat list of absolute file paths.
	 * Directories are replaced by all files inside them.
	 *
	 * @param string[] $rel_paths
	 * @return array{rel:string,abs:string,name:string,mime:string}[]
	 */
	public function expand_to_files( array $rel_paths ): array {
		$result = [];
		foreach ( $rel_paths as $rel ) {
			$abs = $this->resolve( $rel );
			if ( is_file( $abs ) ) {
				$result[] = [
					'rel'  => $rel,
					'abs'  => $abs,
					'name' => basename( $abs ),
					'mime' => mime_content_type( $abs ) ?: 'application/octet-stream',
				];
			} elseif ( is_dir( $abs ) ) {
				$this->expand_dir( $abs, $this->base_dir, $result );
			}
		}
		return $result;
	}

	/**
	 * Returns the absolute path for a path relative to the uploads base, validated
	 * against directory traversal.
	 *
	 * @throws \InvalidArgumentException On traversal attempt.
	 */
	public function resolve( string $rel_path ): string {
		// Normalise separators.
		$rel_path = str_replace( '\\', '/', $rel_path );
		$rel_path = trim( $rel_path, '/' );

		$target = '' === $rel_path
			? $this->base_dir
			: $this->base_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel_path );

		// realpath() only works on existing paths; we also need to validate during creation.
		// Use a string prefix check instead, after cleaning the path.
		$normalised = $this->normalise_path( $target );
		$base_norm  = $this->normalise_path( $this->base_dir );

		if ( strpos( $normalised, $base_norm ) !== 0 ) {
			throw new \InvalidArgumentException( 'Path traversal detected.' );
		}

		return $target;
	}

	public function get_base_dir(): string {
		return $this->base_dir;
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	private function expand_dir( string $abs_dir, string $base_dir, array &$result ): void {
		$handle = opendir( $abs_dir );
		if ( false === $handle ) {
			return;
		}
		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full = $abs_dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $full ) ) {
				$this->expand_dir( $full, $base_dir, $result );
			} else {
				$rel      = ltrim( str_replace( $base_dir, '', $full ), DIRECTORY_SEPARATOR );
				$result[] = [
					'rel'  => str_replace( DIRECTORY_SEPARATOR, '/', $rel ),
					'abs'  => $full,
					'name' => $entry,
					'mime' => mime_content_type( $full ) ?: 'application/octet-stream',
				];
			}
		}
		closedir( $handle );
	}

	/**
	 * Resolves `..` and `.` segments without requiring the path to exist.
	 */
	private function normalise_path( string $path ): string {
		$parts  = preg_split( '#[/\\\\]+#', $path );
		$stack  = [];
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $stack );
			} else {
				$stack[] = $part;
			}
		}
		return DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $stack );
	}
}
