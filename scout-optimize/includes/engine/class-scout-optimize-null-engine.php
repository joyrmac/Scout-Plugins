<?php
/**
 * Null optimization engine.
 *
 * @package Scout_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe no-op engine returned when no real engine is available.
 *
 * Every request fails cleanly with the no_engine code, so callers never have to
 * special-case a missing engine.
 */
final class Scout_Optimize_Null_Engine implements Scout_Optimize_Engine {

	/**
	 * Always unavailable.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return false;
	}

	/**
	 * Supports nothing.
	 *
	 * @param string $mime_type Attachment MIME type.
	 * @return bool
	 */
	public function supports( string $mime_type ): bool {
		return false;
	}

	/**
	 * Always fails with no_engine, leaving the source untouched.
	 *
	 * @param Scout_Optimize_Request $request Immutable job description.
	 * @return Scout_Optimize_Result
	 */
	public function optimize( Scout_Optimize_Request $request ): Scout_Optimize_Result {
		$bytes = is_file( $request->source_path ) ? (int) filesize( $request->source_path ) : 0;
		return Scout_Optimize_Result::failed( $bytes, 'no_engine' );
	}
}
