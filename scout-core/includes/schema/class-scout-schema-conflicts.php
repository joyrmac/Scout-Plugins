<?php
/**
 * One schema graph per page. When Scout Schema is printing the business graph,
 * SEO plugins that print their own (Yoast, Rank Math, All in One SEO, SEOPress)
 * are told not to, so Google never sees two businesses or two websites for the
 * same site.
 *
 * Only their JSON-LD is switched off. Their titles, sitemaps and settings are
 * untouched. Until the business name is filled in (Scout, Business), Scout's
 * graph is not ready and nothing is switched off. Filter
 * `scout_schema_take_over` to false to keep the other plugin's schema.
 *
 * @package Scout_Schema
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Schema_Conflicts {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'take_over' ), 1 );
	}

	/** True when Scout's graph is ready to stand on its own. */
	public static function owns_schema(): bool {
		if ( ! function_exists( 'scout_core_business' ) ) {
			return false;
		}
		$ready = '' !== trim( (string) ( scout_core_business()['name'] ?? '' ) );
		return (bool) apply_filters( 'scout_schema_take_over', $ready );
	}

	public static function take_over() {
		if ( is_admin() || is_feed() || wp_doing_ajax() || ! self::owns_schema() ) {
			return;
		}
		// Yoast SEO: the whole @graph, older and newer versions.
		add_filter( 'wpseo_json_ld_output', '__return_false', 99 );
		add_filter( 'wpseo_schema_graph', '__return_empty_array', 99 );
		// Rank Math.
		add_filter( 'rank_math/json_ld', '__return_empty_array', 99 );
		// All in One SEO.
		add_filter( 'aioseo_schema_disable', '__return_true', 99 );
		add_filter( 'aioseo_schema_output', '__return_empty_array', 99 );
		// SEOPress.
		add_filter( 'seopress_schemas_auto_output', '__return_false', 99 );
	}

	/** Names of active SEO plugins whose schema Scout is standing in for. */
	public static function others(): array {
		$found = array();
		if ( defined( 'WPSEO_VERSION' ) ) {
			$found[] = 'Yoast SEO';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$found[] = 'Rank Math';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			$found[] = 'All in One SEO';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$found[] = 'SEOPress';
		}
		return $found;
	}
}
