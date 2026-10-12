<?php
/**
 * Originals store: backup and restore primitives.
 *
 * @package Scout_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preserves pristine copies of image files and restores them on demand.
 *
 * This is the home of the one-way-door invariant: an original is copied here
 * before it is ever optimized, the first pristine copy always wins, and nothing
 * is ever removed except by the Stage F purge tool behind a confirmation gate.
 *
 * WordPress-light by design: it takes the uploads and store directories as
 * constructor arguments so it can be unit-tested without a WordPress bootstrap.
 * The pipeline (Stage C) supplies real paths from wp_get_upload_dir().
 */
final class Scout_Optimize_Originals {

	/**
	 * Absolute uploads base directory (no trailing slash).
	 *
	 * @var string
	 */
	private string $uploads_dir;

	/**
	 * Absolute originals store directory (no trailing slash).
	 *
	 * @var string
	 */
	private string $store_dir;

	/**
	 * Constructor.
	 *
	 * @param string $uploads_dir Absolute uploads base directory.
	 * @param string $store_dir   Absolute originals store directory.
	 */
	public function __construct( string $uploads_dir, string $store_dir ) {
		$this->uploads_dir = rtrim( $uploads_dir, '/' );
		$this->store_dir   = rtrim( $store_dir, '/' );
	}

	/**
	 * Where the pristine backup for a file lives.
	 *
	 * Mirrors the file's path relative to the uploads directory. Files outside
	 * the uploads directory are mirrored under an _external subfolder.
	 *
	 * @param string $file Absolute path to the working file.
	 * @return string Absolute backup path.
	 */
	public function backup_path_for( string $file ): string {
		$prefix = $this->uploads_dir . '/';
		if ( str_starts_with( $file, $prefix ) ) {
			$relative = substr( $file, strlen( $prefix ) );
		} else {
			$relative = '_external/' . basename( $file );
		}
		return $this->store_dir . '/' . $relative;
	}

	/**
	 * Is a pristine backup already stored for this file?
	 *
	 * @param string $file Absolute path to the working file.
	 * @return bool
	 */
	public function has_backup( string $file ): bool {
		return is_file( $this->backup_path_for( $file ) );
	}

	/**
	 * Back up a file's pristine bytes. Idempotent: the first copy always wins.
	 *
	 * @param string $file Absolute path to the working file.
	 * @return bool True when a backup exists after the call.
	 */
	public function backup( string $file ): bool {
		if ( ! is_file( $file ) ) {
			return false;
		}
		if ( $this->has_backup( $file ) ) {
			return true;
		}
		$target = $this->backup_path_for( $file );
		$dir    = dirname( $target );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Local originals store directory; WP_Filesystem is unavailable in this WP-light, unit-tested class.
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			return false;
		}
		return copy( $file, $target );
	}

	/**
	 * Restore a file from its pristine backup.
	 *
	 * @param string $file Absolute path to the working file.
	 * @return bool True when the file was restored.
	 */
	public function restore( string $file ): bool {
		if ( ! $this->has_backup( $file ) ) {
			return false;
		}
		return copy( $this->backup_path_for( $file ), $file );
	}
}
