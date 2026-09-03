<?php
/**
 * On-server Imagick optimization engine (v1).
 *
 * @package Scout_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optimizes JPEG, PNG, and GIF images with the Imagick extension.
 *
 * Guarantees enforced on every path: the source file is never modified, no
 * exception escapes optimize(), and a file is never grown (a non-shrinking
 * result leaves the served bytes equal to the original).
 */
final class Scout_Optimize_Imagick_Engine implements Scout_Optimize_Engine {

	/**
	 * MIME types handled by this engine.
	 *
	 * @var string[]
	 */
	const SUPPORTED = array( 'image/jpeg', 'image/png', 'image/gif' );

	/**
	 * Suffix for the temporary file written beside a destination.
	 *
	 * @var string
	 */
	const TEMP_SUFFIX = '.scout-tmp';

	/**
	 * Maximum pixels (width * height) allowed before an image is refused, to
	 * guard against memory exhaustion.
	 *
	 * @var int
	 */
	private int $max_pixels;

	/**
	 * Constructor.
	 *
	 * @param int|null $max_pixels Optional pixel ceiling; derived from the PHP
	 *                             memory_limit when null. Injectable for tests.
	 */
	public function __construct( ?int $max_pixels = null ) {
		$this->max_pixels = ( null === $max_pixels ) ? self::derive_max_pixels() : $max_pixels;
	}

	/**
	 * Is the Imagick extension usable on this server?
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
	}

	/**
	 * Does this engine handle the given MIME type?
	 *
	 * @param string $mime_type Attachment MIME type.
	 * @return bool
	 */
	public function supports( string $mime_type ): bool {
		return in_array( $mime_type, self::SUPPORTED, true );
	}

	/**
	 * Optimize a single image file.
	 *
	 * @param Scout_Optimize_Request $request Immutable job description.
	 * @return Scout_Optimize_Result
	 */
	public function optimize( Scout_Optimize_Request $request ): Scout_Optimize_Result {
		$source = $request->source_path;

		if ( ! is_file( $source ) || ! is_readable( $source ) ) {
			return Scout_Optimize_Result::failed( 0, 'source_unreadable' );
		}

		$original_bytes = (int) filesize( $source );

		if ( ! $this->supports( $request->mime_type ) ) {
			return Scout_Optimize_Result::skipped_unsupported( $original_bytes, 'unsupported_mime' );
		}

		try {
			return $this->run( $request, $original_bytes );
		} catch ( \Throwable $e ) {
			return Scout_Optimize_Result::failed( $original_bytes, 'engine_error', $e->getMessage() );
		}
	}

	/**
	 * Core transform. May throw; optimize() wraps it.
	 *
	 * @param Scout_Optimize_Request $request        Job.
	 * @param int                    $original_bytes Source size in bytes.
	 * @return Scout_Optimize_Result
	 * @throws ImagickException When Imagick cannot decode or encode the image.
	 */
	private function run( Scout_Optimize_Request $request, int $original_bytes ): Scout_Optimize_Result {
		// Read only the header first to guard memory before a full decode.
		$probe = new Imagick();
		$probe->pingImage( $request->source_path );
		$pixels = (int) $probe->getImageWidth() * (int) $probe->getImageHeight();
		$frames = (int) $probe->getNumberImages();
		$probe->clear();

		if ( 'image/gif' === $request->mime_type && $frames > 1 ) {
			return Scout_Optimize_Result::skipped_unsupported( $original_bytes, 'animated_gif' );
		}

		if ( $pixels > $this->max_pixels ) {
			return Scout_Optimize_Result::failed( $original_bytes, 'too_large' );
		}

		$params = Scout_Optimize_Presets::params( $request->preset );
		$image  = new Imagick( $request->source_path );

		// Bake in correct orientation, then normalize colour to sRGB for the web.
		$this->auto_orient( $image );
		if ( Imagick::COLORSPACE_CMYK === $image->getImageColorspace() ) {
			$image->transformImageColorspace( Imagick::COLORSPACE_SRGB );
		}

		$this->maybe_resize( $image, $request->max_width, $request->max_height );
		$this->encode_primary( $image, $request->mime_type, $params );

		$temp = $request->destination_path . self::TEMP_SUFFIX;
		$this->write_to( $image, $temp );
		$optimized_bytes = (int) filesize( $temp );

		$width  = (int) $image->getImageWidth();
		$height = (int) $image->getImageHeight();

		$gain = $optimized_bytes < $original_bytes;
		if ( $gain ) {
			$this->place( $temp, $request->destination_path );
			$serve_bytes = $optimized_bytes;
		} else {
			$this->discard( $temp );
			$this->keep_original( $request->source_path, $request->destination_path );
			$serve_bytes = $original_bytes;
		}

		$webp_bytes = null;
		if ( $request->generate_webp && null !== $request->webp_path ) {
			$webp_bytes = $this->write_webp( $image, $request->webp_path, $params, $serve_bytes );
		}

		$avif_bytes = null;
		if ( $request->generate_avif && null !== $request->avif_path && self::supports_format( 'AVIF' ) ) {
			$avif_bytes = $this->write_avif( $image, $request->avif_path, $params, $serve_bytes );
		}

		$image->clear();

		if ( $gain ) {
			return Scout_Optimize_Result::ok( $original_bytes, $optimized_bytes, $webp_bytes, $width, $height, $avif_bytes );
		}
		return Scout_Optimize_Result::skipped_no_gain( $original_bytes, $webp_bytes, $avif_bytes );
	}

	/**
	 * Apply EXIF orientation, then reset the tag to top-left.
	 *
	 * Implemented manually with rotate/flop because the imagick extension has no
	 * autoOrientImage() method. Images without an orientation tag pass through
	 * untouched.
	 *
	 * @param Imagick $image Image to orient in place.
	 * @return void
	 * @throws ImagickException When a transform fails.
	 */
	private function auto_orient( Imagick $image ): void {
		switch ( $image->getImageOrientation() ) {
			case Imagick::ORIENTATION_TOPRIGHT:
				$image->flopImage();
				break;
			case Imagick::ORIENTATION_BOTTOMRIGHT:
				$image->rotateImage( new ImagickPixel(), 180 );
				break;
			case Imagick::ORIENTATION_BOTTOMLEFT:
				$image->flopImage();
				$image->rotateImage( new ImagickPixel(), 180 );
				break;
			case Imagick::ORIENTATION_LEFTTOP:
				$image->flopImage();
				$image->rotateImage( new ImagickPixel(), -90 );
				break;
			case Imagick::ORIENTATION_RIGHTTOP:
				$image->rotateImage( new ImagickPixel(), 90 );
				break;
			case Imagick::ORIENTATION_RIGHTBOTTOM:
				$image->flopImage();
				$image->rotateImage( new ImagickPixel(), 90 );
				break;
			case Imagick::ORIENTATION_LEFTBOTTOM:
				$image->rotateImage( new ImagickPixel(), -90 );
				break;
			default:
				return;
		}
		$image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
	}

	/**
	 * Downscale only, preserving aspect ratio. Never upscales.
	 *
	 * @param Imagick  $image      Image to resize in place.
	 * @param int|null $max_width  Ceiling width, or null.
	 * @param int|null $max_height Ceiling height, or null.
	 * @return void
	 * @throws ImagickException When resizing fails.
	 */
	private function maybe_resize( Imagick $image, ?int $max_width, ?int $max_height ): void {
		if ( null === $max_width && null === $max_height ) {
			return;
		}
		$width  = (int) $image->getImageWidth();
		$height = (int) $image->getImageHeight();
		$box_w  = ( null === $max_width ) ? $width : $max_width;
		$box_h  = ( null === $max_height ) ? $height : $max_height;
		if ( $width <= $box_w && $height <= $box_h ) {
			return;
		}
		$image->resizeImage( $box_w, $box_h, Imagick::FILTER_LANCZOS, 1, true );
	}

	/**
	 * Apply format and quality settings for the primary output.
	 *
	 * @param Imagick $image     Image to configure in place.
	 * @param string  $mime_type Source MIME type.
	 * @param array   $params    Preset parameters from Scout_Optimize_Presets.
	 * @return void
	 * @throws ImagickException When Imagick rejects a setting.
	 */
	private function encode_primary( Imagick $image, string $mime_type, array $params ): void {
		switch ( $mime_type ) {
			case 'image/jpeg':
				$image->setImageFormat( 'jpeg' );
				$image->setInterlaceScheme( Imagick::INTERLACE_PLANE );
				$image->setImageCompressionQuality( (int) $params['jpeg_quality'] );
				$image->setSamplingFactors(
					$params['jpeg_subsample'] ? array( '2x2', '1x1', '1x1' ) : array( '1x1', '1x1', '1x1' )
				);
				break;
			case 'image/png':
				$image->setImageFormat( 'png' );
				if ( $params['png_quantize'] ) {
					// Dither on to soften banding when reducing a photo to a palette.
					$image->quantizeImage( (int) $params['png_max_colors'], Imagick::COLORSPACE_SRGB, 0, true, false );
				}
				$image->setOption( 'png:compression-level', '9' );
				break;
			case 'image/gif':
				$image->setImageFormat( 'gif' );
				break;
		}

		if ( $params['strip_metadata'] ) {
			$image->stripImage();
		}
	}

	/**
	 * Write a WebP twin, keeping it only when smaller than the served primary.
	 *
	 * @param Imagick $image       Optimized source image.
	 * @param string  $webp_path   Destination for the twin.
	 * @param array   $params      Preset parameters.
	 * @param int     $serve_bytes Size of the primary that will be served.
	 * @return int|null Twin size in bytes, or null when not kept.
	 * @throws ImagickException When Imagick cannot encode WebP.
	 */
	private function write_webp( Imagick $image, string $webp_path, array $params, int $serve_bytes ): ?int {
		$webp = clone $image;
		$webp->setImageFormat( 'webp' );
		if ( $params['webp_lossless'] ) {
			$webp->setOption( 'webp:lossless', 'true' );
		} else {
			$webp->setImageCompressionQuality( (int) $params['webp_quality'] );
		}

		$temp = $webp_path . self::TEMP_SUFFIX;
		$this->write_to( $webp, $temp );
		$bytes = (int) filesize( $temp );
		$webp->clear();

		if ( $bytes < $serve_bytes ) {
			$this->place( $temp, $webp_path );
			return $bytes;
		}
		$this->discard( $temp );
		return null;
	}

	/**
	 * Write an AVIF twin, keeping it only when smaller than the served primary.
	 *
	 * AVIF typically beats WebP by 20-30% on photographic content. The twin is
	 * written from the already-optimized image and kept only if it is smaller
	 * than the bytes that will actually be served, so a twin is never a
	 * regression. Servers without an AVIF delegate never reach this method.
	 *
	 * @param Imagick $image       Optimized source image.
	 * @param string  $avif_path   Destination for the twin.
	 * @param array   $params      Preset parameters.
	 * @param int     $serve_bytes Size of the primary that will be served.
	 * @return int|null Twin size in bytes, or null when not kept.
	 * @throws ImagickException When Imagick cannot encode AVIF.
	 */
	private function write_avif( Imagick $image, string $avif_path, array $params, int $serve_bytes ): ?int {
		$avif = clone $image;
		$avif->setImageFormat( 'avif' );
		if ( $params['avif_lossless'] ) {
			$avif->setOption( 'heic:lossless', 'true' );
			$avif->setImageCompressionQuality( 100 );
		} else {
			$avif->setImageCompressionQuality( (int) $params['avif_quality'] );
		}

		$temp = $avif_path . self::TEMP_SUFFIX;
		$this->write_to( $avif, $temp );
		$bytes = (int) filesize( $temp );
		$avif->clear();

		if ( $bytes < $serve_bytes ) {
			$this->place( $temp, $avif_path );
			return $bytes;
		}
		$this->discard( $temp );
		return null;
	}

	/**
	 * Is a named image format available in this Imagick build?
	 *
	 * Used to guard AVIF encoding, whose delegate is not present on every host.
	 *
	 * @param string $format Uppercase format name, e.g. 'AVIF'.
	 * @return bool
	 */
	private static function supports_format( string $format ): bool {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}
		return in_array( $format, array_map( 'strtoupper', Imagick::queryFormats() ), true );
	}

	/**
	 * Write an image to a path, forcing the format so Imagick never infers it
	 * from the temp file's extension.
	 *
	 * @param Imagick $image Image to write.
	 * @param string  $path  Destination path (may carry a non-image extension).
	 * @return void
	 * @throws ImagickException When the write fails.
	 */
	private function write_to( Imagick $image, string $path ): void {
		$format = strtolower( $image->getImageFormat() );
		$image->writeImage( '' !== $format ? $format . ':' . $path : $path );
	}

	/**
	 * Move a temp file onto its destination.
	 *
	 * @param string $temp        Temp path.
	 * @param string $destination Final path.
	 * @return void
	 * @throws RuntimeException When the move fails.
	 */
	private function place( string $temp, string $destination ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-directory move of a temp file into place; WP_Filesystem has no atomic rename and is not loaded in the engine's WP-light context.
		if ( ! rename( $temp, $destination ) ) {
			throw new RuntimeException( 'place_failed' );
		}
	}

	/**
	 * Delete a temp file if it exists.
	 *
	 * @param string $temp Temp path.
	 * @return void
	 */
	private function discard( string $temp ): void {
		if ( is_file( $temp ) ) {
			unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing our own just-written temp file; wp_delete_file is unavailable in the engine's WP-light context.
		}
	}

	/**
	 * Ensure the destination holds a valid image when optimization did not help.
	 *
	 * Copies the pristine source to the destination only when they differ, so
	 * an in-place request leaves the source untouched.
	 *
	 * @param string $source      Pristine source path.
	 * @param string $destination Destination path.
	 * @return void
	 */
	private function keep_original( string $source, string $destination ): void {
		if ( $source !== $destination ) {
			copy( $source, $destination );
		}
	}

	/**
	 * Derive a pixel ceiling from the PHP memory limit.
	 *
	 * @return int Maximum pixels for a single decode.
	 */
	private static function derive_max_pixels(): int {
		$limit = self::bytes_from_ini( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return 24000000; // ~24MP fallback when memory is unlimited or unknown.
		}
		// Imagick Q16 uses roughly 8 bytes per pixel plus working copies; budget
		// a quarter of available memory for the largest single decode.
		$budget = (int) floor( ( $limit * 0.25 ) / 8 );
		return max( 4000000, $budget );
	}

	/**
	 * Parse a PHP shorthand byte value (e.g. "512M") to bytes.
	 *
	 * @param string $value Shorthand value.
	 * @return int Bytes, or 0 when unlimited/unknown.
	 */
	private static function bytes_from_ini( string $value ): int {
		$value = trim( $value );
		if ( '' === $value || '-1' === $value ) {
			return 0;
		}
		$unit   = strtolower( $value[ strlen( $value ) - 1 ] );
		$number = (int) $value;
		switch ( $unit ) {
			case 'g':
				return $number * 1024 * 1024 * 1024;
			case 'm':
				return $number * 1024 * 1024;
			case 'k':
				return $number * 1024;
			default:
				return (int) $value;
		}
	}
}
