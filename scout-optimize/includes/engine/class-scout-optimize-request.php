<?php
/**
 * Immutable optimization request.
 *
 * @package Scout_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Value object describing one optimization job.
 *
 * Carries paths, not attachment IDs, so the engine stays decoupled from
 * WordPress media. The pipeline (Stage C) derives these paths from attachment
 * metadata before calling the engine.
 */
final class Scout_Optimize_Request {

	/**
	 * Constructor.
	 *
	 * @param string      $source_path      File to read. Never written to.
	 * @param string      $mime_type        Source MIME type.
	 * @param string      $preset           Preset key (lossy|balanced|lossless).
	 * @param int|null    $max_width        Resize ceiling width, or null for none.
	 * @param int|null    $max_height       Resize ceiling height, or null for none.
	 * @param bool        $generate_webp    Whether to write a WebP twin.
	 * @param string      $destination_path Where the optimized primary is written.
	 * @param string|null $webp_path        Where the WebP twin is written, or null.
	 * @param bool        $generate_avif    Whether to write an AVIF twin.
	 * @param string|null $avif_path        Where the AVIF twin is written, or null.
	 */
	public function __construct(
		public readonly string $source_path,
		public readonly string $mime_type,
		public readonly string $preset,
		public readonly ?int $max_width,
		public readonly ?int $max_height,
		public readonly bool $generate_webp,
		public readonly string $destination_path,
		public readonly ?string $webp_path,
		public readonly bool $generate_avif = false,
		public readonly ?string $avif_path = null
	) {
	}
}
