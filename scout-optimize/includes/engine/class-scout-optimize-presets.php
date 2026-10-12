<?php
/**
 * Quality presets and their per-format encoder parameters.
 *
 * @package Scout_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a preset key to concrete encoder parameters.
 *
 * Values ratified 2026-07-23. Presets are Scout-set per site and invisible to
 * clients. Numbers are starting defaults tuned against real client libraries.
 */
final class Scout_Optimize_Presets {

	/**
	 * Smallest output; palette-quantizes PNGs.
	 *
	 * @var string
	 */
	const LOSSY = 'lossy';

	/**
	 * Default. Near-invisible loss on most photos.
	 *
	 * @var string
	 */
	const BALANCED = 'balanced';

	/**
	 * Visually lossless; strips metadata only.
	 *
	 * @var string
	 */
	const LOSSLESS = 'lossless';

	/**
	 * Is this a known preset key?
	 *
	 * @param string $preset Preset key.
	 * @return bool
	 */
	public static function is_valid( string $preset ): bool {
		return in_array( $preset, array( self::LOSSY, self::BALANCED, self::LOSSLESS ), true );
	}

	/**
	 * Return the preset key, falling back to balanced when unknown.
	 *
	 * @param string $preset Preset key.
	 * @return string
	 */
	public static function normalize( string $preset ): string {
		return self::is_valid( $preset ) ? $preset : self::BALANCED;
	}

	/**
	 * Encoder parameters for a preset.
	 *
	 * AVIF encodes more efficiently than WebP, so a given visual quality sits a
	 * few points lower on the quality scale; the twin is kept only when it beats
	 * the served primary, so an unhelpful AVIF is simply discarded.
	 *
	 * @param string $preset Preset key (normalized internally).
	 * @return array{jpeg_quality:int,jpeg_subsample:bool,webp_quality:int,webp_lossless:bool,avif_quality:int,avif_lossless:bool,png_quantize:bool,png_max_colors:int,strip_metadata:bool}
	 */
	public static function params( string $preset ): array {
		switch ( self::normalize( $preset ) ) {
			case self::LOSSY:
				return array(
					'jpeg_quality'   => 62,
					'jpeg_subsample' => true,
					'webp_quality'   => 62,
					'webp_lossless'  => false,
					'avif_quality'   => 50,
					'avif_lossless'  => false,
					'png_quantize'   => true,
					'png_max_colors' => 256,
					'strip_metadata' => true,
				);
			case self::LOSSLESS:
				return array(
					'jpeg_quality'   => 92,
					'jpeg_subsample' => false,
					'webp_quality'   => 100,
					'webp_lossless'  => true,
					'avif_quality'   => 90,
					'avif_lossless'  => true,
					'png_quantize'   => false,
					'png_max_colors' => 256,
					'strip_metadata' => true,
				);
			case self::BALANCED:
			default:
				return array(
					'jpeg_quality'   => 76,
					'jpeg_subsample' => true,
					'webp_quality'   => 72,
					'webp_lossless'  => false,
					'avif_quality'   => 58,
					'avif_lossless'  => false,
					'png_quantize'   => true,
					'png_max_colors' => 256,
					'strip_metadata' => true,
				);
		}
	}
}
