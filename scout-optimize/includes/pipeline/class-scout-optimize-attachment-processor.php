<?php
/**
 * Attachment optimization orchestrator.
 *
 * @package Scout_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backs up an attachment's original, then optimizes the served full-size and
 * its generated sub-sizes in place.
 *
 * WordPress-light: it works on file paths and an injected engine plus originals
 * store, so it is unit-testable without a WordPress bootstrap. The pipeline (WP
 * glue) supplies the paths from attachment metadata.
 */
final class Scout_Optimize_Attachment_Processor {

	/**
	 * Optimization engine.
	 *
	 * @var Scout_Optimize_Engine
	 */
	private Scout_Optimize_Engine $engine;

	/**
	 * Originals backup/restore store.
	 *
	 * @var Scout_Optimize_Originals
	 */
	private Scout_Optimize_Originals $originals;

	/**
	 * Preset key.
	 *
	 * @var string
	 */
	private string $preset;

	/**
	 * Resize ceiling width for the full-size, or null.
	 *
	 * @var int|null
	 */
	private ?int $max_width;

	/**
	 * Resize ceiling height for the full-size, or null.
	 *
	 * @var int|null
	 */
	private ?int $max_height;

	/**
	 * Constructor.
	 *
	 * @param Scout_Optimize_Engine    $engine     Optimization engine.
	 * @param Scout_Optimize_Originals $originals  Originals store.
	 * @param string                   $preset     Preset key.
	 * @param int|null                 $max_width  Full-size resize ceiling width.
	 * @param int|null                 $max_height Full-size resize ceiling height.
	 */
	public function __construct(
		Scout_Optimize_Engine $engine,
		Scout_Optimize_Originals $originals,
		string $preset,
		?int $max_width,
		?int $max_height
	) {
		$this->engine     = $engine;
		$this->originals  = $originals;
		$this->preset     = $preset;
		$this->max_width  = $max_width;
		$this->max_height = $max_height;
	}

	/**
	 * Optimize an attachment's served files, preserving the original first.
	 *
	 * The full-size is backed up before anything is optimized (originals-only
	 * policy: sub-sizes are regenerable from the original). Only the full-size
	 * is eligible for downscaling; generated sub-sizes are recompressed at their
	 * existing dimensions.
	 *
	 * @param string   $original_path Absolute path to the served full-size file.
	 * @param string[] $size_paths    Absolute paths to the generated sub-sizes.
	 * @param string   $mime_type     Attachment MIME type.
	 * @param bool     $generate_webp Whether to write WebP twins.
	 * @param bool     $generate_avif Whether to write AVIF twins.
	 * @return array Summary suitable for storing in attachment meta.
	 */
	public function process( string $original_path, array $size_paths, string $mime_type, bool $generate_webp, bool $generate_avif = false ): array {
		$this->originals->backup( $original_path );

		$results   = array();
		$results[] = $this->optimize_file( $original_path, $mime_type, $generate_webp, $generate_avif, true );
		foreach ( $size_paths as $size_path ) {
			$results[] = $this->optimize_file( (string) $size_path, $mime_type, $generate_webp, $generate_avif, false );
		}

		return $this->summarize( $results );
	}

	/**
	 * Optimize one file in place.
	 *
	 * @param string $path          File to optimize (destination equals source).
	 * @param string $mime_type     MIME type.
	 * @param bool   $generate_webp Whether to write a WebP twin.
	 * @param bool   $generate_avif Whether to write an AVIF twin.
	 * @param bool   $allow_resize  Whether the resize ceiling applies.
	 * @return Scout_Optimize_Result
	 */
	private function optimize_file( string $path, string $mime_type, bool $generate_webp, bool $generate_avif, bool $allow_resize ): Scout_Optimize_Result {
		$webp_path = $generate_webp ? $path . '.webp' : null;
		$avif_path = $generate_avif ? $path . '.avif' : null;
		$request   = new Scout_Optimize_Request(
			$path,
			$mime_type,
			$this->preset,
			$allow_resize ? $this->max_width : null,
			$allow_resize ? $this->max_height : null,
			$generate_webp,
			$path,
			$webp_path,
			$generate_avif,
			$avif_path
		);
		return $this->engine->optimize( $request );
	}

	/**
	 * Roll up per-file results into an attachment-level summary.
	 *
	 * @param Scout_Optimize_Result[] $results Per-file results.
	 * @return array
	 */
	private function summarize( array $results ): array {
		$original  = 0;
		$optimized = 0;
		$webp      = 0;
		$avif      = 0;
		$ok        = 0;
		$files     = array();

		foreach ( $results as $result ) {
			$original  += $result->original_bytes;
			$optimized += ( null !== $result->optimized_bytes ) ? $result->optimized_bytes : $result->original_bytes;
			if ( null !== $result->webp_bytes ) {
				$webp += $result->webp_bytes;
			}
			if ( null !== $result->avif_bytes ) {
				$avif += $result->avif_bytes;
			}
			if ( $result->is_ok() ) {
				++$ok;
			}
			$files[] = array(
				'status'    => $result->status,
				'original'  => $result->original_bytes,
				'optimized' => $result->optimized_bytes,
				'webp'      => $result->webp_bytes,
				'avif'      => $result->avif_bytes,
				'code'      => $result->error_code,
			);
		}

		return array(
			'version'         => defined( 'SCOUT_OPTIMIZE_VERSION' ) ? SCOUT_OPTIMIZE_VERSION : '',
			'preset'          => $this->preset,
			'file_count'      => count( $results ),
			'optimized_count' => $ok,
			'original_bytes'  => $original,
			'optimized_bytes' => $optimized,
			'webp_bytes'      => $webp,
			'avif_bytes'      => $avif,
			'files'           => $files,
		);
	}
}
