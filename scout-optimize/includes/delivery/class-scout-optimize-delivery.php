<?php
/**
 * WebP delivery (WordPress glue).
 *
 * @package Scout_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves WebP twins to supporting browsers by wrapping <img> tags in <picture>.
 *
 * Gated by the rewrite_picture setting (default OFF). Front-end only: never
 * alters admin, feed, or REST output. A twin is offered only when the .webp
 * file actually exists on disk, so partial coverage is safe and the original
 * <img> always remains as the fallback.
 */
final class Scout_Optimize_Delivery {

	/**
	 * Picture markup builder.
	 *
	 * @var Scout_Optimize_Picture_Builder
	 */
	private Scout_Optimize_Picture_Builder $builder;

	/**
	 * Cached uploads [baseurl, basedir], or null until resolved.
	 *
	 * @var array|null
	 */
	private ?array $uploads = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->builder = new Scout_Optimize_Picture_Builder();
	}

	/**
	 * Register front-end image filters. Their output is unchanged while the flag
	 * is OFF.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_content_img_tag', array( $this, 'filter_content_img' ), 20 );
		add_filter( 'wp_get_attachment_image', array( $this, 'filter_attachment_image' ), 20 );

		// Total-coverage pass: rewrite the whole rendered page so images that
		// never pass through the filters above (page-builder widgets, galleries,
		// template markup) still get their AVIF/WebP twins. A no-op while the
		// flag is OFF, and it skips anything already inside a <picture>.
		add_action( 'template_redirect', array( $this, 'maybe_start_buffer' ) );
	}

	/**
	 * Start buffering the front-end response so the whole page can be rewritten.
	 *
	 * @return void
	 */
	public function maybe_start_buffer(): void {
		if ( ! $this->enabled() ) {
			return;
		}
		ob_start( array( $this, 'rewrite_buffer' ) );
	}

	/**
	 * Output-buffer callback: rewrite every <img> in a rendered HTML page.
	 *
	 * Only touches responses that look like an HTML document, so non-HTML output
	 * on the front end (robots.txt, sitemaps, plain-text) is passed through
	 * untouched.
	 *
	 * @param string $buffer The buffered response body.
	 * @return string
	 */
	public function rewrite_buffer( $buffer ) {
		if ( ! is_string( $buffer ) || '' === $buffer ) {
			return $buffer;
		}
		if ( false === stripos( $buffer, '</body>' ) && false === stripos( $buffer, '<html' ) ) {
			return $buffer;
		}
		return $this->builder->rewrite_document(
			$buffer,
			array( $this, 'resolve_webp' ),
			array( $this, 'resolve_avif' )
		);
	}

	/**
	 * Is picture rewriting enabled for this request?
	 *
	 * @return bool
	 */
	private function enabled(): bool {
		if ( is_admin() || is_feed() || wp_is_json_request() ) {
			return false;
		}
		$settings = get_option( 'scout_optimize_settings', array() );
		return ! empty( $settings['rewrite_picture'] );
	}

	/**
	 * Filter a content <img> tag.
	 *
	 * @param mixed $filtered_image The <img> tag HTML.
	 * @return mixed
	 */
	public function filter_content_img( $filtered_image ) {
		if ( ! is_string( $filtered_image ) || ! $this->enabled() ) {
			return $filtered_image;
		}
		return $this->builder->build( $filtered_image, array( $this, 'resolve_webp' ), array( $this, 'resolve_avif' ) );
	}

	/**
	 * Filter a template-rendered attachment image.
	 *
	 * @param mixed $html The <img> tag HTML.
	 * @return mixed
	 */
	public function filter_attachment_image( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! $this->enabled() ) {
			return $html;
		}
		return $this->builder->build( $html, array( $this, 'resolve_webp' ), array( $this, 'resolve_avif' ) );
	}

	/**
	 * Resolve an uploads image URL to its WebP twin URL when the file exists.
	 *
	 * @param string $url Image URL.
	 * @return string|null WebP URL, or null when there is no twin.
	 */
	public function resolve_webp( string $url ): ?string {
		return $this->resolve_twin( $url, '.webp' );
	}

	/**
	 * Resolve an uploads image URL to its AVIF twin URL when the file exists.
	 *
	 * @param string $url Image URL.
	 * @return string|null AVIF URL, or null when there is no twin.
	 */
	public function resolve_avif( string $url ): ?string {
		return $this->resolve_twin( $url, '.avif' );
	}

	/**
	 * Resolve an uploads image URL to a same-name twin (e.g. .webp, .avif) when
	 * the twin file exists on disk.
	 *
	 * @param string $url       Image URL.
	 * @param string $extension Twin extension, including the leading dot.
	 * @return string|null Twin URL, or null when there is no twin on disk.
	 */
	private function resolve_twin( string $url, string $extension ): ?string {
		$uploads = $this->uploads();
		if ( null === $uploads ) {
			return null;
		}
		list( $baseurl, $basedir ) = $uploads;
		if ( 0 !== strpos( $url, $baseurl ) ) {
			return null;
		}
		$relative = substr( $url, strlen( $baseurl ) );
		$twin     = $basedir . $relative . $extension;
		return is_file( $twin ) ? $url . $extension : null;
	}

	/**
	 * Resolve and cache the uploads baseurl/basedir.
	 *
	 * @return array|null The [baseurl, basedir] pair, or null on error.
	 */
	private function uploads(): ?array {
		if ( null === $this->uploads ) {
			$dir = wp_get_upload_dir();
			if ( ! empty( $dir['error'] ) || empty( $dir['baseurl'] ) || empty( $dir['basedir'] ) ) {
				return null;
			}
			$this->uploads = array( $dir['baseurl'], $dir['basedir'] );
		}
		return $this->uploads;
	}
}
