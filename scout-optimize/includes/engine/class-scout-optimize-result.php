<?php
/**
 * Immutable optimization result.
 *
 * @package Scout_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Value object describing the outcome of one optimization attempt.
 *
 * On any status other than ok, the source and the currently-served file are
 * guaranteed byte-for-byte unchanged.
 */
final class Scout_Optimize_Result {

	/**
	 * Optimized successfully; a smaller primary was written.
	 *
	 * @var string
	 */
	const STATUS_OK = 'ok';

	/**
	 * Skipped because the engine does not handle this input (e.g. animated GIF).
	 *
	 * @var string
	 */
	const STATUS_SKIPPED_UNSUPPORTED = 'skipped_unsupported';

	/**
	 * Skipped because optimization would not shrink the file.
	 *
	 * @var string
	 */
	const STATUS_SKIPPED_NO_GAIN = 'skipped_no_gain';

	/**
	 * Failed; the source is untouched.
	 *
	 * @var string
	 */
	const STATUS_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param string      $status          One of the STATUS_* constants.
	 * @param int         $original_bytes  Source size in bytes.
	 * @param int|null    $optimized_bytes Primary output size, or null.
	 * @param int|null    $webp_bytes      WebP twin size, or null.
	 * @param int|null    $width           Final primary width, or null.
	 * @param int|null    $height          Final primary height, or null.
	 * @param string|null $error_code      Stable machine code on failure/skip.
	 * @param string|null $message         Human-readable, category-only note.
	 * @param int|null    $avif_bytes      AVIF twin size, or null.
	 */
	public function __construct(
		public readonly string $status,
		public readonly int $original_bytes,
		public readonly ?int $optimized_bytes = null,
		public readonly ?int $webp_bytes = null,
		public readonly ?int $width = null,
		public readonly ?int $height = null,
		public readonly ?string $error_code = null,
		public readonly ?string $message = null,
		public readonly ?int $avif_bytes = null
	) {
	}

	/**
	 * Build a successful result.
	 *
	 * @param int      $original_bytes  Source size.
	 * @param int      $optimized_bytes Primary size (smaller than the source).
	 * @param int|null $webp_bytes      WebP twin size, or null when none kept.
	 * @param int      $width           Final width.
	 * @param int      $height          Final height.
	 * @param int|null $avif_bytes      AVIF twin size, or null when none kept.
	 * @return self
	 */
	public static function ok( int $original_bytes, int $optimized_bytes, ?int $webp_bytes, int $width, int $height, ?int $avif_bytes = null ): self {
		return new self( self::STATUS_OK, $original_bytes, $optimized_bytes, $webp_bytes, $width, $height, null, null, $avif_bytes );
	}

	/**
	 * Build a no-gain result. The served file keeps the original bytes.
	 *
	 * @param int      $original_bytes Source size.
	 * @param int|null $webp_bytes     WebP twin size, or null when none kept.
	 * @param int|null $avif_bytes     AVIF twin size, or null when none kept.
	 * @return self
	 */
	public static function skipped_no_gain( int $original_bytes, ?int $webp_bytes = null, ?int $avif_bytes = null ): self {
		return new self( self::STATUS_SKIPPED_NO_GAIN, $original_bytes, $original_bytes, $webp_bytes, null, null, null, null, $avif_bytes );
	}

	/**
	 * Build an unsupported-input result.
	 *
	 * @param int    $original_bytes Source size.
	 * @param string $error_code     Stable machine code.
	 * @return self
	 */
	public static function skipped_unsupported( int $original_bytes, string $error_code ): self {
		return new self( self::STATUS_SKIPPED_UNSUPPORTED, $original_bytes, null, null, null, null, $error_code );
	}

	/**
	 * Build a failed result. The source is untouched.
	 *
	 * @param int    $original_bytes Source size (0 when unreadable).
	 * @param string $error_code     Stable machine code.
	 * @param string $message        Optional category-only detail.
	 * @return self
	 */
	public static function failed( int $original_bytes, string $error_code, string $message = '' ): self {
		return new self( self::STATUS_FAILED, $original_bytes, null, null, null, null, $error_code, ( '' === $message ) ? null : $message );
	}

	/**
	 * Did the attempt produce a smaller primary?
	 *
	 * @return bool
	 */
	public function is_ok(): bool {
		return self::STATUS_OK === $this->status;
	}

	/**
	 * Fraction of the original size saved on the primary (0.0 when none).
	 *
	 * @return float
	 */
	public function savings_ratio(): float {
		if ( $this->original_bytes <= 0 || null === $this->optimized_bytes ) {
			return 0.0;
		}
		return 1 - ( $this->optimized_bytes / $this->original_bytes );
	}
}
