<?php
/**
 * Partners on the site.
 *
 *   [scout_partners]                          every live partner, grouped by level
 *   [scout_partners level="founding"]         one level
 *   [scout_partners placement="home-page"]    the partners placed there
 *   [scout_partners region="midwest"]         one region's partners
 *   [scout_partners layout="rail"]            one row of logos, for a header or sidebar strip
 *   [scout_partners layout="logos"]           logos only, no names or lines
 *   [scout_partners title="Our partners"]     a heading above it
 *
 * Every link goes through the site once, which counts the click and sends
 * the visitor straight on to the partner. Links are marked sponsored, as
 * search engines ask for paid placements.
 *
 * With Scout Events, partners can also show on each event page (Settings):
 * the partners placed on "Event pages", from the event's region and the
 * national ones with no region.
 *
 * @package Scout_Partners
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Partners_Front {

	public static function boot(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ) );
		add_shortcode( 'scout_partners', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_nopriv_scout_partner', array( __CLASS__, 'go' ) );
		add_action( 'admin_post_scout_partner', array( __CLASS__, 'go' ) );
		add_filter( 'the_content', array( __CLASS__, 'on_event_page' ), 23 );
	}

	public static function register(): void {
		wp_register_style( 'scout-partners', SCOUT_PARTNERS_URL . 'assets/scout-partners.css', array(), SCOUT_PARTNERS_VERSION );
	}

	/** The counted link for a partner, or '' when it has no website. */
	public static function link( int $id ): string {
		return '' !== Scout_Partners::partner( $id )['url'] ? add_query_arg( array( 'action' => 'scout_partner', 'p' => $id ), admin_url( 'admin-post.php' ) ) : '';
	}

	/**
	 * Count the click and go on to the partner's own website. Only ever to the
	 * address saved on that partner, so the link cannot be bent to send people
	 * anywhere else.
	 */
	public static function go(): void {
		$id = isset( $_GET['p'] ) ? absint( $_GET['p'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public link.
		$to = $id && Scout_Partners::TYPE === get_post_type( $id ) && 'publish' === get_post_status( $id ) ? Scout_Partners::partner( $id )['url'] : '';
		if ( '' === $to ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		// Search engines and link checkers follow links too; they are not visitors.
		if ( '' !== $agent && ! preg_match( '/bot|crawl|spider|slurp|preview|monitor|check/', $agent ) ) {
			Scout_Partners::count_click( $id );
		}
		nocache_headers();
		wp_redirect( $to, 302, 'Scout Partners' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the partner's own saved address, never one from the request.
		exit;
	}

	public static function shortcode( $atts ): string {
		$a = shortcode_atts(
			array(
				'level'     => '',
				'placement' => '',
				'region'    => '',
				'layout'    => 'grid',
				'title'     => '',
			),
			(array) $atts,
			'scout_partners'
		);
		$ids = Scout_Partners::ids(
			array(
				'level'     => $a['level'],
				'placement' => $a['placement'],
				'region'    => $a['region'],
			)
		);
		return self::render( $ids, in_array( $a['layout'], array( 'grid', 'rail', 'logos' ), true ) ? $a['layout'] : 'grid', (string) $a['title'] );
	}

	/** Partners as a block: grouped by level in the grid, one strip in the rail. */
	public static function render( array $ids, string $layout = 'grid', string $title = '' ): string {
		if ( ! $ids ) {
			return '';
		}
		wp_enqueue_style( 'scout-partners' );
		$out = '<section class="sp-partners sp-' . esc_attr( $layout ) . '" aria-label="' . esc_attr( '' !== $title ? $title : __( 'Partners', 'scout-partners' ) ) . '">';
		if ( '' !== $title ) {
			$out .= '<h2 class="sp-title">' . esc_html( $title ) . '</h2>';
		}
		if ( 'grid' !== $layout ) {
			$out .= '<ul class="sp-list">';
			foreach ( $ids as $id ) {
				$out .= self::card( $id, 'logos' === $layout || 'rail' === $layout );
			}
			return $out . '</ul></section>';
		}
		$levels = Scout_Partners::levels();
		$groups = array();
		foreach ( $ids as $id ) {
			$groups[ Scout_Partners::partner( $id )['level'] ][] = $id;
		}
		foreach ( $groups as $level => $list ) {
			$name = $levels[ $level ] ?? '';
			$out .= '<div class="sp-level sp-level-' . esc_attr( '' !== $level ? $level : 'other' ) . '">';
			if ( '' !== $name && count( $groups ) > 1 ) {
				/* translators: %s: partner level, such as Founding. */
				$out .= '<h3 class="sp-level-name">' . esc_html( sprintf( __( '%s partners', 'scout-partners' ), $name ) ) . '</h3>';
			}
			$out .= '<ul class="sp-list">';
			foreach ( $list as $id ) {
				$out .= self::card( $id, false );
			}
			$out .= '</ul></div>';
		}
		return $out . '</section>';
	}

	private static function card( int $id, bool $logo_only ): string {
		$p    = Scout_Partners::partner( $id );
		$name = get_the_title( $id );
		$logo = has_post_thumbnail( $id ) ? get_the_post_thumbnail( $id, 'medium', array( 'class' => 'sp-logo', 'alt' => $logo_only ? $name : '', 'loading' => 'lazy' ) ) : '';
		$body = '' !== $logo ? $logo : '<span class="sp-logo is-text">' . esc_html( $name ) . '</span>';
		if ( ! $logo_only ) {
			$body .= '<span class="sp-name">' . esc_html( $name ) . '</span>';
			if ( '' !== $p['line'] ) {
				$body .= '<span class="sp-line">' . esc_html( $p['line'] ) . '</span>';
			}
		}
		$link = self::link( $id );
		$out  = '<li class="sp-partner">';
		$out .= '' !== $link
			? '<a class="sp-link" href="' . esc_url( $link ) . '" rel="sponsored noopener" target="_blank">' . $body . '<span class="screen-reader-text">' . esc_html__( '(opens in a new tab)', 'scout-partners' ) . '</span></a>'
			: '<span class="sp-link">' . $body . '</span>';
		return $out . '</li>';
	}

	/** Partners near the end of each event page, when Settings says so. */
	public static function on_event_page( $content ) {
		if ( ! Scout_Partners::settings()['on_events'] || ! class_exists( 'Scout_Events' ) || ! is_singular( Scout_Events::EVENT ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id      = (int) get_the_ID();
		$regions = class_exists( 'Scout_Events_Regions' ) ? array_keys( Scout_Events_Regions::own( $id ) ) : array();
		$place   = isset( Scout_Partners::placements()['event-pages'] ) ? 'event-pages' : '';
		$ids     = Scout_Partners::ids(
			array(
				'placement' => $place,
				'regions'   => $regions,
			)
		);
		return $content . self::render( $ids, 'grid', __( 'Thank you to our partners', 'scout-partners' ) );
	}
}
