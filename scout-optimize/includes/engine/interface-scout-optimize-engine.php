<?php
/**
 * Engine contract for Scout Optimize.
 *
 * @package Scout_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The contract every optimization engine implements.
 *
 * Engines are WordPress-light: they act on file paths and parameters, not on
 * attachments, so the v1 Imagick engine and a future GCP engine can share one
 * contract. The upload pipeline (Stage C) owns attachment awareness.
 */
interface Scout_Optimize_Engine {

	/**
	 * Is this engine usable on this server right now?
	 *
	 * @return bool True when the engine can process images.
	 */
	public function is_available(): bool;

	/**
	 * Does this engine handle the given MIME type?
	 *
	 * @param string $mime_type Attachment MIME type, e.g. image/jpeg.
	 * @return bool True when the type is in scope for this engine.
	 */
	public function supports( string $mime_type ): bool;

	/**
	 * Optimize a single image file.
	 *
	 * Implementations MUST NOT modify the request source file and MUST NOT
	 * throw. Every failure returns a Result with the failed status, leaving the
	 * source byte-for-byte unchanged.
	 *
	 * @param Scout_Optimize_Request $request Immutable job description.
	 * @return Scout_Optimize_Result Outcome of the attempt.
	 */
	public function optimize( Scout_Optimize_Request $request ): Scout_Optimize_Result;
}
