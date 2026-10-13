<?php
/**
 * Questions and answers on any page or post.
 *
 * Each page gets a "Questions this page answers" box in the editor. The
 * answers print at the end of the page (or wherever [scout_faq] is placed),
 * and the matching FAQPage schema is printed only when they are actually on
 * the page, so the schema always matches what visitors see.
 *
 * The Site Build hub counts pages with answers by META, so keep the key.
 *
 * @package Scout_AEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_AEO_FAQ {

	const META    = '_scout_aeo_faqs';
	const HEADING = '_scout_aeo_faq_heading';
	const MAX     = 8;

	/** @var array<int, array<int, array{q:string, a:string}>> Printed on this request, by post ID. */
	private static $printed = array();

	public static function boot(): void {
		add_shortcode( 'scout_faq', array( __CLASS__, 'shortcode' ) );
		add_filter( 'the_content', array( __CLASS__, 'append' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'schema' ), 5 );
		add_action( 'wp_head', array( __CLASS__, 'styles' ), 30 );
	}

	/** @return array<int, array{q:string, a:string}> */
	public static function get( int $post_id ): array {
		$rows = get_post_meta( $post_id, self::META, true );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$q = trim( (string) ( $row['q'] ?? '' ) );
			$a = trim( (string) ( $row['a'] ?? '' ) );
			if ( '' !== $q && '' !== $a ) {
				$out[] = array( 'q' => $q, 'a' => $a );
			}
		}
		return array_slice( $out, 0, self::MAX );
	}

	/** @return int[] Published posts and pages that have answers. */
	public static function pages_with_answers( int $limit = 100 ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'   => 'any',
					'post_status' => 'publish',
					'numberposts' => $limit,
					'fields'      => 'ids',
					'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				)
			)
		);
	}

	public static function html( int $post_id ): string {
		$faqs = self::get( $post_id );
		if ( ! $faqs || isset( self::$printed[ $post_id ] ) ) {
			return '';
		}
		self::$printed[ $post_id ] = $faqs;
		$heading = trim( (string) get_post_meta( $post_id, self::HEADING, true ) );
		$heading = '' !== $heading ? $heading : __( 'Common questions', 'scout-aeo' );

		$out = '<section class="scout-faq" id="answers" aria-labelledby="scout-faq-title-' . $post_id . '">'
			. '<h2 id="scout-faq-title-' . $post_id . '">' . esc_html( $heading ) . '</h2>';
		foreach ( $faqs as $qa ) {
			$out .= '<details class="scout-faq-item"><summary><h3>' . esc_html( $qa['q'] ) . '</h3></summary>'
				. wpautop( esc_html( $qa['a'] ) ) . '</details>';
		}
		return $out . '</section>';
	}

	public static function shortcode(): string {
		$id = (int) get_the_ID();
		return $id ? self::html( $id ) : '';
	}

	/** Add the answers to the end of the main content, unless [scout_faq] placed them already. */
	public static function append( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id = (int) get_the_ID();
		if ( has_shortcode( (string) get_post_field( 'post_content', $id ), 'scout_faq' ) ) {
			return $content;
		}
		return $content . self::html( $id );
	}

	/** FAQPage schema for what was printed. */
	public static function schema(): void {
		if ( ! self::$printed ) {
			return;
		}
		$id   = (int) array_key_first( self::$printed );
		$node = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'@id'        => get_permalink( $id ) . '#answers',
			'url'        => get_permalink( $id ),
			'mainEntity' => array_map(
				static fn( $qa ) => array(
					'@type'          => 'Question',
					'name'           => $qa['q'],
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $qa['a'] ),
				),
				self::$printed[ $id ]
			),
		);
		echo "<script type=\"application/ld+json\">\n" . wp_json_encode( $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n</script>\n";
	}

	/** A few neutral rules; everything else comes from the theme. */
	public static function styles(): void {
		if ( ! is_singular() || ! self::get( (int) get_queried_object_id() ) ) {
			return;
		}
		echo '<style id="scout-faq">.scout-faq{margin:3em 0}.scout-faq-item{border-top:1px solid currentColor;border-color:color-mix(in srgb,currentColor 18%,transparent)}.scout-faq-item:last-child{border-bottom:1px solid color-mix(in srgb,currentColor 18%,transparent)}.scout-faq-item summary{cursor:pointer;list-style:none;display:flex;justify-content:space-between;gap:1em;padding:1em 0}.scout-faq-item summary::-webkit-details-marker{display:none}.scout-faq-item summary::after{content:"+";font-size:1.4em;line-height:1}.scout-faq-item[open] summary::after{content:"\2013"}.scout-faq-item summary h3{margin:0;font-size:1.05em}.scout-faq-item p{margin:0 0 1em}</style>' . "\n";
	}
}
