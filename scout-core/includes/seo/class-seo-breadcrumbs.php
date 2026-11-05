<?php
/**
 * Breadcrumbs (Site Standard gap 3): one trail, used for the BreadcrumbList
 * schema and for the visible breadcrumb links, so the two never disagree.
 *
 * A theme opts in with add_theme_support( 'scout-breadcrumbs' ). Until a theme
 * does, Core adds nothing, because many themes still print their own
 * BreadcrumbList and two would disagree (Site Standard rule 20: a live page
 * stays the same until its conversion). A theme shows the links with
 * scout_core_breadcrumbs().
 *
 * The trail: Home, then
 * - a page's parent pages, then the page;
 * - for a post, the blog page (Settings → Reading), then the post;
 * - for another content type, its archive when it has one, then the item;
 * - for a category, tag or other term, the blog page or the type's archive, then the term.
 * The front page, search results and missing pages have no trail.
 * Filter scout_core_breadcrumb_trail adjusts it (for example to name a page that acts as an archive).
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_SEO_Breadcrumbs {

	public static function init() {
		add_filter( 'scout_schema_graph', array( __CLASS__, 'schema' ), 20 );
	}

	/** True when this site's theme asked Core for breadcrumbs. */
	public static function enabled() {
		return (bool) apply_filters( 'scout_core_breadcrumbs', current_theme_supports( 'scout-breadcrumbs' ) );
	}

	/**
	 * The current page's trail.
	 *
	 * @return array<int,array{name:string,url:string}>
	 */
	public static function trail() {
		if ( is_front_page() || is_search() || is_404() ) {
			return array();
		}
		$home  = array( array( 'name' => __( 'Home', 'scout-core' ), 'url' => home_url( '/' ) ) );
		$items = array();
		$blog  = self::blog_page();

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post ) {
				if ( 'page' === $post->post_type ) {
					foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor ) {
						$items[] = self::post_item( (int) $ancestor );
					}
				} elseif ( 'post' === $post->post_type ) {
					if ( $blog ) {
						$items[] = self::post_item( $blog );
					}
				} else {
					$archive = self::archive_item( $post->post_type );
					if ( $archive ) {
						$items[] = $archive;
					}
				}
				$items[] = self::post_item( (int) $post->ID );
			}
		} elseif ( is_home() ) {
			if ( $blog ) {
				$items[] = self::post_item( $blog );
			}
		} elseif ( is_post_type_archive() ) {
			$type    = get_query_var( 'post_type' );
			$type    = is_array( $type ) ? reset( $type ) : $type;
			$archive = self::archive_item( (string) $type );
			if ( $archive ) {
				$items[] = $archive;
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$tax   = get_taxonomy( $term->taxonomy );
				$types = $tax ? (array) $tax->object_type : array();
				if ( in_array( 'post', $types, true ) && $blog ) {
					$items[] = self::post_item( $blog );
				} elseif ( $types ) {
					$archive = self::archive_item( (string) reset( $types ) );
					if ( $archive ) {
						$items[] = $archive;
					}
				}
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$items[] = array( 'name' => $term->name, 'url' => $link );
				}
			}
		}
		if ( ! $items ) {
			return array();
		}
		$trail = array_merge( $home, $items );
		$trail = (array) apply_filters( 'scout_core_breadcrumb_trail', $trail );
		return self::clean( $trail );
	}

	/** Drop empty items and repeats of the same address, keeping the order. */
	public static function clean( array $trail ) {
		$out  = array();
		$seen = array();
		foreach ( $trail as $item ) {
			$name = isset( $item['name'] ) ? trim( wp_strip_all_tags( (string) $item['name'] ) ) : '';
			$url  = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( '' === $name || '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$out[]        = array( 'name' => $name, 'url' => $url );
		}
		return count( $out ) > 1 ? $out : array();
	}

	/** The trail as a schema.org BreadcrumbList node, or null. */
	public static function node( array $trail ) {
		if ( ! $trail ) {
			return null;
		}
		$list = array();
		foreach ( array_values( $trail ) as $i => $item ) {
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $item['name'],
				'item'     => $item['url'],
			);
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => end( $trail )['url'] . '#breadcrumb',
			'itemListElement' => $list,
		);
	}

	/** Add the BreadcrumbList when the theme opted in and nothing else added one. */
	public static function schema( $graph ) {
		$graph = is_array( $graph ) ? $graph : array();
		if ( ! self::enabled() ) {
			return $graph;
		}
		foreach ( $graph as $node ) {
			if ( is_array( $node ) && 'BreadcrumbList' === ( $node['@type'] ?? '' ) ) {
				return $graph;
			}
		}
		$node = self::node( self::trail() );
		if ( $node ) {
			$graph[] = $node;
		}
		return $graph;
	}

	/** The visible links: a nav landmark with an ordered list, the last item marked as the current page. */
	public static function html( array $trail, array $args = array() ) {
		if ( ! $trail ) {
			return '';
		}
		$class = isset( $args['class'] ) ? (string) $args['class'] : 'scout-breadcrumbs';
		$last  = count( $trail ) - 1;
		$out   = '<nav class="' . esc_attr( $class ) . '" aria-label="' . esc_attr__( 'Breadcrumb', 'scout-core' ) . '"><ol>';
		foreach ( array_values( $trail ) as $i => $item ) {
			$out .= $i === $last
				? '<li><span aria-current="page">' . esc_html( $item['name'] ) . '</span></li>'
				: '<li><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a></li>';
		}
		return $out . '</ol></nav>';
	}

	private static function blog_page() {
		$id = (int) get_option( 'page_for_posts' );
		return ( $id && 'publish' === get_post_status( $id ) ) ? $id : 0;
	}

	private static function post_item( int $id ) {
		return array( 'name' => get_the_title( $id ), 'url' => (string) get_permalink( $id ) );
	}

	private static function archive_item( string $type ) {
		$object = get_post_type_object( $type );
		$link   = $object && $object->has_archive ? get_post_type_archive_link( $type ) : false;
		return $link ? array( 'name' => $object->labels->name, 'url' => $link ) : null;
	}
}

if ( ! function_exists( 'scout_core_breadcrumbs' ) ) {
	/** Print the breadcrumb links where the theme wants them. Returns the HTML when $echo is false. */
	function scout_core_breadcrumbs( array $args = array(), bool $echo = true ) {
		$html = Scout_SEO_Breadcrumbs::html( Scout_SEO_Breadcrumbs::trail(), $args );
		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
		return $html;
	}
}
