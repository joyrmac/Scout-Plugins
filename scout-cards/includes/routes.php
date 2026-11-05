<?php
/**
 * URLs. Nothing here needs a WordPress Page: the plugin owns these addresses.
 *
 *   /card/                      team card            /card/<person>/            a person's card
 *   /card/save/                 team vCard           /card/<person>/save/       their vCard
 *   /card/share/                team share mode      /card/<person>/share/      their share mode
 *   /card/share/manifest/       home-screen manifest /card/<person>/share/manifest/
 *   /links/                     link in bio
 *
 * The "card" and "links" words are settings. Rules flush on activation, when
 * those words change, and once per plugin version.
 *
 * @package Scout_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Cards_Routes {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'dispatch' ), 1 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap' ), 11, 2 );
	}

	/**
	 * A Page at a card or links address shows the card, which is noindex, so it stays
	 * out of the sitemap (the Scout Media theme kept its /card/ and /links/ Pages out too).
	 */
	public static function sitemap( $args, $post_type ) {
		if ( 'page' !== $post_type ) {
			return $args;
		}
		$s   = Scout_Cards_Settings::get();
		$ids = array();
		foreach ( array( array( $s['enable_cards'], $s['card_base'] ), array( $s['enable_links'], $s['links_base'] ) ) as $b ) {
			$pg = $b[0] ? get_page_by_path( $b[1] ) : null;
			if ( $pg ) {
				$ids[] = (int) $pg->ID;
			}
		}
		if ( $ids ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), $ids );
		}
		return $args;
	}

	/**
	 * In the theme's own card look, a card or links address that has a Page of the same
	 * name loads as that Page, the way the theme's cards did, so the page's head and body
	 * classes stay the same. '' otherwise.
	 */
	private static function page_var( $base ) {
		if ( ! class_exists( 'Scout_Cards_Render_Theme' ) || ! Scout_Cards_Render_Theme::on() ) {
			return '';
		}
		return get_page_by_path( $base ) ? '&pagename=' . $base : '';
	}

	public static function activate() {
		self::rules();
		flush_rewrite_rules( false );
	}

	public static function rules() {
		$s = Scout_Cards_Settings::get();
		$c = preg_quote( trim( $s['card_base'], '/' ), '#' );
		$l = preg_quote( trim( $s['links_base'], '/' ), '#' );
		$cp = self::page_var( trim( $s['card_base'], '/' ) );
		$lp = self::page_var( trim( $s['links_base'], '/' ) );
		if ( $s['enable_cards'] ) {
			add_rewrite_rule( "^{$c}/?$", 'index.php?scc_view=card&scc_who=team' . $cp, 'top' );
			add_rewrite_rule( "^{$c}/save/?$", 'index.php?scc_view=vcard&scc_who=team', 'top' );
			add_rewrite_rule( "^{$c}/share/?$", 'index.php?scc_view=share&scc_who=team' . $cp, 'top' );
			add_rewrite_rule( "^{$c}/share/manifest/?$", 'index.php?scc_view=manifest&scc_who=team', 'top' );
			add_rewrite_rule( "^{$c}/([a-z0-9-]+)/save/?$", 'index.php?scc_view=vcard&scc_who=$matches[1]', 'top' );
			add_rewrite_rule( "^{$c}/([a-z0-9-]+)/share/manifest/?$", 'index.php?scc_view=manifest&scc_who=$matches[1]', 'top' );
			add_rewrite_rule( "^{$c}/([a-z0-9-]+)/share/?$", 'index.php?scc_view=share&scc_who=$matches[1]' . $cp, 'top' );
			add_rewrite_rule( "^{$c}/([a-z0-9-]+)/?$", 'index.php?scc_view=card&scc_who=$matches[1]' . $cp, 'top' );
		}
		if ( $s['enable_links'] ) {
			add_rewrite_rule( "^{$l}/?$", 'index.php?scc_view=links' . $lp, 'top' );
		}
		$stamp = SCOUT_CARDS_VERSION . '|' . $s['card_base'] . '|' . $s['links_base'] . '|' . $s['enable_cards'] . $s['enable_links'] . '|' . $cp . $lp;
		if ( get_option( 'scout_cards_routes' ) !== $stamp ) {
			flush_rewrite_rules( false );
			update_option( 'scout_cards_routes', $stamp, false );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = 'scc_view';
		$vars[] = 'scc_who';
		return $vars;
	}

	/**
	 * The theme's own card look draws its page where the theme's card templates did,
	 * as the page template, after every other `template_redirect` step has run (the
	 * theme's output filters among them), so the page comes out the same.
	 */
	private static function theme_template( $view, $who ) {
		add_filter(
			'template_include',
			static function () use ( $view, $who ) {
				$GLOBALS['scout_cards_theme_view'] = array( $view, $who );
				return SCOUT_CARDS_DIR . 'includes/theme-template.php';
			},
			99
		);
	}

	public static function dispatch() {
		$view = get_query_var( 'scc_view' );
		if ( ! $view ) {
			return;
		}
		$theme = Scout_Cards_Render_Theme::on();
		if ( 'links' === $view ) {
			if ( $theme ) {
				self::theme_template( 'links', '' );
				return;
			}
			Scout_Cards_Render::links_page();
			exit;
		}
		$people = Scout_Cards_Settings::people();
		$who    = get_query_var( 'scc_who' ) ? get_query_var( 'scc_who' ) : 'team';
		if ( ! isset( $people[ $who ] ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return; // the theme's 404 page
		}
		$p = $people[ $who ];
		if ( $theme && in_array( $view, array( 'card', 'share' ), true ) ) {
			self::theme_template( $view, $who );
			return;
		}
		switch ( $view ) {
			case 'vcard':
				Scout_Cards_VCard::send( $p );
				break;
			case 'manifest':
				if ( $theme ) {
					Scout_Cards_Render_Theme::manifest( $p );
				} else {
					Scout_Cards_Render::manifest( $p );
				}
				break;
			case 'share':
				if ( $theme ) {
					Scout_Cards_Render_Theme::share_page( $who );
				} else {
					Scout_Cards_Render::share_page( $p );
				}
				break;
			default:
				if ( $theme ) {
					Scout_Cards_Render_Theme::card_page( $who );
				} else {
					Scout_Cards_Render::card_page( $p, $people );
				}
		}
		exit;
	}
}
