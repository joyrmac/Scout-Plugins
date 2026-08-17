<?php
/**
 * The document <head> SEO output: title, meta description, canonical, Open
 * Graph, and Twitter cards, plus robots control through the core `wp_robots`
 * filter. Scout Schema owns the JSON-LD; this class owns everything else in the
 * head so the two never collide.
 *
 * @package Scout_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_SEO_Head {

	public static function init() {
		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 20 );
		add_action( 'wp', array( __CLASS__, 'replace_core_canonical' ) );
		add_action( 'wp_head', array( __CLASS__, 'output' ), 1 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	/**
	 * We emit canonical for every context ourselves, so drop core's
	 * singular-only version to avoid a duplicate tag.
	 */
	public static function replace_core_canonical() {
		remove_action( 'wp_head', 'rel_canonical' );
	}

	/**
	 * Use the per-page SEO title when set; otherwise leave WordPress's default.
	 */
	public static function document_title( $title ) {
		if ( is_singular() ) {
			$custom = get_post_meta( get_queried_object_id(), SCOUT_SEO_META_TITLE, true );
			if ( '' !== $custom ) {
				return $custom;
			}
		}
		return $title;
	}

	public static function output() {
		$description = self::description();
		$canonical   = self::canonical();
		$title       = wp_get_document_title();
		$site_name   = get_bloginfo( 'name' );
		$image       = self::image();
		$is_article  = is_singular( 'post' );

		$tags = array();

		if ( '' !== $description ) {
			$tags[] = sprintf( '<meta name="description" content="%s" />', esc_attr( $description ) );
		}
		if ( $canonical ) {
			$tags[] = sprintf( '<link rel="canonical" href="%s" />', esc_url( $canonical ) );
		}

		// Open Graph.
		$tags[] = sprintf( '<meta property="og:locale" content="%s" />', esc_attr( get_locale() ) );
		$tags[] = sprintf( '<meta property="og:type" content="%s" />', $is_article ? 'article' : 'website' );
		$tags[] = sprintf( '<meta property="og:title" content="%s" />', esc_attr( $title ) );
		if ( '' !== $description ) {
			$tags[] = sprintf( '<meta property="og:description" content="%s" />', esc_attr( $description ) );
		}
		if ( $canonical ) {
			$tags[] = sprintf( '<meta property="og:url" content="%s" />', esc_url( $canonical ) );
		}
		$tags[] = sprintf( '<meta property="og:site_name" content="%s" />', esc_attr( $site_name ) );
		if ( $image ) {
			$tags[] = sprintf( '<meta property="og:image" content="%s" />', esc_url( $image ) );
		}
		if ( $is_article ) {
			$id     = get_queried_object_id();
			$tags[] = sprintf( '<meta property="article:published_time" content="%s" />', esc_attr( get_the_date( 'c', $id ) ) );
			$tags[] = sprintf( '<meta property="article:modified_time" content="%s" />', esc_attr( get_the_modified_date( 'c', $id ) ) );
		}

		// Twitter.
		$tags[] = sprintf( '<meta name="twitter:card" content="%s" />', $image ? 'summary_large_image' : 'summary' );
		$tags[] = sprintf( '<meta name="twitter:title" content="%s" />', esc_attr( $title ) );
		if ( '' !== $description ) {
			$tags[] = sprintf( '<meta name="twitter:description" content="%s" />', esc_attr( $description ) );
		}
		if ( $image ) {
			$tags[] = sprintf( '<meta name="twitter:image" content="%s" />', esc_url( $image ) );
		}

		echo "\n" . implode( "\n", $tags ) . "\n";
	}

	/**
	 * Flip the current page to noindex where appropriate. Filtering core's
	 * `wp_robots` keeps a single, correct robots meta tag.
	 */
	public static function robots( $robots ) {
		if ( is_search() || is_404() ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		if ( is_singular() && get_post_meta( get_queried_object_id(), SCOUT_SEO_META_NOINDEX, true ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		return $robots;
	}

	private static function description() {
		if ( is_singular() ) {
			$id   = get_queried_object_id();
			$desc = get_post_meta( $id, SCOUT_SEO_META_DESC, true );
			if ( '' !== $desc ) {
				return $desc;
			}
			if ( has_excerpt( $id ) ) {
				return wp_strip_all_tags( get_the_excerpt( $id ) );
			}
			$post = get_post( $id );
			if ( $post ) {
				$content = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
				$content = trim( preg_replace( '/\s+/', ' ', $content ) );
				if ( '' !== $content ) {
					return wp_trim_words( $content, 30, '' );
				}
			}
		}
		if ( is_front_page() && class_exists( 'Scout_Core_SEO_Settings' ) ) {
			$home = Scout_Core_SEO_Settings::value( 'home_description' );
			if ( '' !== $home ) {
				return $home;
			}
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term_desc = wp_strip_all_tags( term_description() );
			if ( '' !== $term_desc ) {
				return $term_desc;
			}
		}
		return get_bloginfo( 'description' );
	}

	private static function canonical() {
		if ( is_singular() ) {
			$id       = get_queried_object_id();
			$override = get_post_meta( $id, SCOUT_SEO_META_CANONICAL, true );
			if ( '' !== $override ) {
				return $override;
			}
			return get_permalink( $id );
		}
		if ( is_front_page() ) {
			return home_url( '/' );
		}
		if ( is_home() ) {
			$blog = (int) get_option( 'page_for_posts' );
			return $blog ? get_permalink( $blog ) : home_url( '/' );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$link = get_term_link( get_queried_object() );
			return is_wp_error( $link ) ? home_url( '/' ) : $link;
		}
		if ( is_post_type_archive() ) {
			$obj  = get_queried_object();
			$name = ( $obj instanceof WP_Post_Type ) ? $obj->name : '';
			$link = $name ? get_post_type_archive_link( $name ) : false;
			return $link ? $link : home_url( '/' );
		}
		return home_url( '/' );
	}

	private static function image() {
		if ( is_singular() && has_post_thumbnail( get_queried_object_id() ) ) {
			$url = get_the_post_thumbnail_url( get_queried_object_id(), 'full' );
			if ( $url ) {
				return $url;
			}
		}
		if ( class_exists( 'Scout_Core_SEO_Settings' ) ) {
			$default = Scout_Core_SEO_Settings::value( 'default_image' );
			if ( '' !== $default ) {
				return $default;
			}
		}
		$icon = get_site_icon_url();
		return $icon ? $icon : '';
	}
}
