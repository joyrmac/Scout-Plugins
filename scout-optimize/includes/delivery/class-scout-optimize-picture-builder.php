<?php
/**
 * Builds <picture> markup offering AVIF and WebP sources with the original as
 * the fallback.
 *
 * @package Scout_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress-light. Given an <img> tag and resolvers that map an image URL to its
 * WebP (and optionally AVIF) twin URL, it returns a <picture> wrapper, or the
 * original markup unchanged when no twin is available. AVIF is offered ahead of
 * WebP so supporting browsers pick the smallest format first. Unit-testable with
 * stub resolvers.
 */
final class Scout_Optimize_Picture_Builder {

	/**
	 * Wrap an <img> tag in a <picture> with AVIF and WebP sources when twins
	 * exist. AVIF is listed first so browsers that support it choose it before
	 * WebP; the original <img> always remains as the fallback.
	 *
	 * @param string        $img_html     The original <img> tag HTML.
	 * @param callable      $resolve_webp fn(string $url): ?string returning the
	 *                                    WebP twin URL for an image URL, or null.
	 * @param callable|null $resolve_avif Optional resolver for the AVIF twin URL.
	 * @return string Picture markup, or the original HTML unchanged.
	 */
	public function build( string $img_html, callable $resolve_webp, ?callable $resolve_avif = null ): string {
		// Never double-wrap, and only act on an actual <img> tag.
		if ( false !== strpos( $img_html, '<picture' ) || false === strpos( $img_html, '<img' ) ) {
			return $img_html;
		}

		$avif_srcset = ( null !== $resolve_avif ) ? $this->twin_srcset( $img_html, $resolve_avif ) : '';
		$webp_srcset = $this->twin_srcset( $img_html, $resolve_webp );
		if ( '' === $avif_srcset && '' === $webp_srcset ) {
			return $img_html;
		}

		$sizes   = $this->attr( $img_html, 'sizes' );
		$sources = '';
		// Order matters: browsers use the first <source> they support. AVIF, the
		// smallest, is offered first, then WebP, then the untouched <img>.
		if ( '' !== $avif_srcset ) {
			$sources .= $this->source_tag( 'image/avif', $avif_srcset, $sizes );
		}
		if ( '' !== $webp_srcset ) {
			$sources .= $this->source_tag( 'image/webp', $webp_srcset, $sizes );
		}

		return '<picture>' . $sources . $img_html . '</picture>';
	}

	/**
	 * Rewrite every standalone <img> in a full HTML document into a <picture>.
	 *
	 * This is the total-coverage path: it catches images that never pass through
	 * WordPress's img filters (page-builder widgets, galleries, template markup),
	 * which is where a filter-only approach leaks. Images already inside a
	 * <picture> are left untouched, so it is safe to run alongside the filters.
	 *
	 * WordPress-light and unit-testable: it takes the HTML and the resolvers, and
	 * touches nothing else.
	 *
	 * @param string        $html         Full HTML document (or fragment).
	 * @param callable      $resolve_webp WebP twin resolver.
	 * @param callable|null $resolve_avif Optional AVIF twin resolver.
	 * @return string Rewritten HTML.
	 */
	public function rewrite_document( string $html, callable $resolve_webp, ?callable $resolve_avif = null ): string {
		if ( false === stripos( $html, '<img' ) ) {
			return $html;
		}

		// Protect existing <picture> blocks so their fallback <img> is not
		// re-wrapped, regardless of which code produced them.
		$protected = array();
		$html      = preg_replace_callback(
			'/<picture\b.*?<\/picture>/is',
			static function ( $picture ) use ( &$protected ) {
				$token               = '<!--scout-optimize-picture-' . count( $protected ) . '-->';
				$protected[ $token ] = $picture[0];
				return $token;
			},
			$html
		);

		$html = preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $tag ) use ( $resolve_webp, $resolve_avif ) {
				return $this->build( $tag[0], $resolve_webp, $resolve_avif );
			},
			$html
		);

		if ( ! empty( $protected ) ) {
			$html = strtr( $html, $protected );
		}

		return $html;
	}

	/**
	 * Build a <source> tag for one twin format.
	 *
	 * @param string $type   MIME type, e.g. image/avif.
	 * @param string $srcset Resolved srcset for the twin.
	 * @param string $sizes  The img's sizes attribute, or ''.
	 * @return string
	 */
	private function source_tag( string $type, string $srcset, string $sizes ): string {
		$source = '<source type="' . $type . '" srcset="' . $srcset . '"';
		if ( '' !== $sizes ) {
			$source .= ' sizes="' . $sizes . '"';
		}
		return $source . ' />';
	}

	/**
	 * Build a twin srcset from the img's srcset, or its src when there is none.
	 *
	 * Only entries whose twin the resolver confirms are included, so partial
	 * coverage is safe.
	 *
	 * @param string   $img_html Image HTML.
	 * @param callable $resolve  fn(string $url): ?string twin resolver.
	 * @return string Twin srcset, or '' when no twin resolves.
	 */
	private function twin_srcset( string $img_html, callable $resolve ): string {
		$srcset  = $this->attr( $img_html, 'srcset' );
		$entries = array();

		if ( '' !== $srcset ) {
			foreach ( explode( ',', $srcset ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( '' === $candidate ) {
					continue;
				}
				$parts      = preg_split( '/\s+/', $candidate );
				$url        = $parts[0];
				$descriptor = isset( $parts[1] ) ? ' ' . $parts[1] : '';
				$twin       = call_user_func( $resolve, $url );
				if ( is_string( $twin ) && '' !== $twin ) {
					$entries[] = $twin . $descriptor;
				}
			}
		} else {
			$src  = $this->attr( $img_html, 'src' );
			$twin = ( '' !== $src ) ? call_user_func( $resolve, $src ) : null;
			if ( is_string( $twin ) && '' !== $twin ) {
				$entries[] = $twin;
			}
		}

		return implode( ', ', $entries );
	}

	/**
	 * Extract a double- or single-quoted attribute value from a tag.
	 *
	 * @param string $html Tag HTML.
	 * @param string $name Attribute name.
	 * @return string Value, or '' when absent.
	 */
	private function attr( string $html, string $name ): string {
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '=("([^"]*)"|\'([^\']*)\')/i', $html, $matches ) ) {
			if ( '' !== $matches[2] ) {
				return $matches[2];
			}
			return isset( $matches[3] ) ? $matches[3] : '';
		}
		return '';
	}
}
