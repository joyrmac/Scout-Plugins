<?php
/**
 * One-time copy from a theme that made its own cards (the Scout Media theme's
 * `scout_cards` option, Scout Build -> Cards & Links) into this plugin, so moving
 * the cards from the theme to the plugin changes nothing a visitor sees.
 *
 * It runs once, on the first page load after the theme has stood down (Scout Media
 * 1.54.0 and later load their own cards only while this plugin is off), and is
 * recorded in the `scout_cards_theme_import` option. It reads what the theme's cards
 * showed: `scout_card_settings()` when the theme still has it (its saved settings over
 * its built-in copy), else the saved `scout_cards` option.
 *
 * What it fills, never overwriting anything already saved in `scout_cards_settings`:
 * - The main card and business details, only where the plugin's own value is empty
 *   and the theme's differs from what the plugin would show anyway (Business info,
 *   the site name). A value that matches Business info stays empty, so it keeps
 *   following Business info.
 * - The link rows and "Latest from the blog", when the plugin has none saved.
 * - Each theme card person as a card record (link name, name, title, email, intro,
 *   "why talk to them", tags, photo, Spotlight) unless a record with that link name
 *   is already there. The People move (includes/people.php, init 30) then links each
 *   record to its People profile, or makes one, never overwriting a People value.
 * - The social profile links the theme's cards showed, when there are none.
 *
 * The theme's option is left as it was, so turning the plugin off brings the theme's
 * own cards back exactly as before.
 *
 * @package Scout_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Cards_Theme_Import {

	const OPTION       = 'scout_cards_theme_import';
	const THEME_OPTION = 'scout_cards';

	/** Theme key (dot path) => plugin key, for the single values. */
	const MAP = array(
		'team.name'         => 'name',
		'org'               => 'org',
		'phone'             => 'phone',
		'team.email'        => 'email',
		'city'              => 'city',
		'region'            => 'region',
		'zip'               => 'postal',
		'footer'            => 'footer',
		'note'              => 'note',
		'team.intro'        => 'team_intro',
		'team.pick_heading' => 'pick_heading',
		'team.photo_id'     => 'photo_id',
		'team.spot_heading' => 'spot_heading',
		'team.spot_text'    => 'spot_text',
		'team.spot_label'   => 'spot_label',
		'team.spot_sub'     => 'spot_sub',
		'team.spot_url'     => 'spot_url',
		'links_intro'       => 'links_intro',
		'socials'           => 'socials',
	);

	/** The card record keys copied for each person. */
	const PERSON = array( 'slug', 'name', 'title', 'email', 'intro', 'pick', 'tags', 'photo_id', 'spot_heading', 'spot_text', 'spot_label', 'spot_sub', 'spot_url' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_run' ), 5 ); // before the routes (10) and the People move (30)
	}

	/** Has the copy run (or found nothing to copy)? */
	public static function done() {
		return false !== get_option( self::OPTION, false );
	}

	/**
	 * What the theme's cards showed, or null when the theme never had cards.
	 * Adds the social links the theme printed and, for a person saved before the
	 * Spotlight existed, the theme's own Spotlight for that link name.
	 */
	public static function source() {
		if ( function_exists( 'scout_card_settings' ) ) {
			$t = (array) scout_card_settings();
		} else {
			$t = get_option( self::THEME_OPTION );
			if ( ! is_array( $t ) ) {
				return null;
			}
		}
		if ( function_exists( 'scout_card_defaults' ) && is_array( $t['people'] ?? null ) ) {
			$d = array();
			foreach ( (array) ( scout_card_defaults()['people'] ?? array() ) as $p ) {
				$d[ (string) ( $p['slug'] ?? '' ) ] = $p;
			}
			foreach ( $t['people'] as $i => $p ) {
				foreach ( array( 'spot_heading', 'spot_text', 'spot_label', 'spot_sub', 'spot_url' ) as $k ) {
					if ( is_array( $p ) && ! array_key_exists( $k, $p ) ) {
						$t['people'][ $i ][ $k ] = (string) ( $d[ (string) ( $p['slug'] ?? '' ) ][ $k ] ?? '' );
					}
				}
			}
		}
		if ( function_exists( 'scout_social_links' ) ) {
			$t['socials'] = implode( "\n", self::hrefs( (string) scout_social_links() ) );
		}
		return $t;
	}

	/** The addresses in a block of links. */
	public static function hrefs( $html ) {
		preg_match_all( '/href="([^"]+)"/', (string) $html, $m );
		return array_values( array_unique( array_map( 'html_entity_decode', $m[1] ) ) );
	}

	/** A value from the theme's settings by dot path ('team.name'), or null. */
	private static function pick( array $t, $path ) {
		$v = $t;
		foreach ( explode( '.', $path ) as $k ) {
			if ( ! is_array( $v ) || ! array_key_exists( $k, $v ) ) {
				return null;
			}
			$v = $v[ $k ];
		}
		return $v;
	}

	/**
	 * The plan, without WordPress: the plugin's saved settings with the theme's filled
	 * in, and the keys that were filled. Nothing already saved is changed.
	 *
	 * @param array $theme    What the theme's cards showed (source()).
	 * @param array $saved    The plugin's saved `scout_cards_settings` (may be empty).
	 * @param array $defaults What the plugin shows with nothing saved (Settings::defaults()).
	 * @return array settings, filled (plugin keys), people (link names added).
	 */
	public static function plan( array $theme, array $saved, array $defaults ) {
		$out    = $saved;
		$filled = array();
		$empty  = static function ( $v ) {
			return null === $v || '' === $v || 0 === $v || '0' === $v;
		};
		foreach ( self::MAP as $from => $to ) {
			$v = self::pick( $theme, $from );
			if ( null === $v || is_array( $v ) ) {
				continue;
			}
			$v = is_int( $v ) || ( 'photo_id' === $to ) ? (int) $v : (string) $v;
			if ( $empty( $v ) || ( array_key_exists( $to, $saved ) && ! $empty( $saved[ $to ] ) ) ) {
				continue; // Nothing to bring, or the plugin already has its own.
			}
			if ( array_key_exists( $to, $defaults ) && (string) $defaults[ $to ] === (string) $v ) {
				continue; // The plugin shows this already (Business info, the site name).
			}
			$out[ $to ] = $v;
			$filled[]   = $to;
		}
		if ( array_key_exists( 'links_latest', $theme ) && ! array_key_exists( 'links_latest', $saved ) && (int) $theme['links_latest'] !== (int) ( $defaults['links_latest'] ?? 1 ) ) {
			$out['links_latest'] = (int) $theme['links_latest'];
			$filled[]            = 'links_latest';
		}
		if ( is_array( $theme['links'] ?? null ) && $theme['links'] && ! array_key_exists( 'links', $saved ) ) {
			$rows = array();
			foreach ( $theme['links'] as $l ) {
				if ( ! is_array( $l ) || '' === trim( (string) ( $l['label'] ?? '' ) ) || '' === trim( (string) ( $l['url'] ?? '' ) ) ) {
					continue;
				}
				$rows[] = array(
					'label'    => (string) $l['label'],
					'sub'      => (string) ( $l['sub'] ?? '' ),
					'url'      => (string) $l['url'],
					'external' => empty( $l['external'] ) ? 0 : 1,
				);
			}
			if ( $rows ) {
				$out['links'] = $rows;
				$filled[]     = 'links';
			}
		}
		// Keep the theme's own card look, so visitors see the same pages (render-theme.php).
		if ( ! array_key_exists( 'look', $saved ) || '' === (string) $saved['look'] ) {
			$out['look'] = 'theme';
			$filled[]    = 'look';
		}
		$have = array();
		foreach ( (array) ( $saved['people'] ?? array() ) as $r ) {
			$have[] = self::slug( (string) ( $r['slug'] ?? '' ) );
		}
		$added = array();
		foreach ( (array) ( $theme['people'] ?? array() ) as $p ) {
			$slug = self::slug( (string) ( $p['slug'] ?? '' ) );
			if ( ! is_array( $p ) || '' === $slug || in_array( $slug, array( 'save', 'share', 'team' ), true ) || '' === trim( (string) ( $p['name'] ?? '' ) ) || in_array( $slug, $have, true ) ) {
				continue;
			}
			$rec = array();
			foreach ( self::PERSON as $k ) {
				$rec[ $k ] = 'photo_id' === $k ? (int) ( $p[ $k ] ?? 0 ) : (string) ( $p[ $k ] ?? '' );
			}
			$rec['slug'] = $slug;
			$out['people'][] = $rec;
			$have[]          = $slug;
			$added[]         = $slug;
		}
		if ( $added ) {
			$filled[] = 'people';
		}
		return array(
			'settings' => $out,
			'filled'   => $filled,
			'people'   => $added,
		);
	}

	/** A link name the way the theme and this plugin both make them. */
	private static function slug( $s ) {
		return function_exists( 'sanitize_title' ) ? sanitize_title( $s ) : trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $s ) ), '-' );
	}

	/** Run the copy once. Writes `scout_cards_settings` only when there is something to add. */
	public static function maybe_run() {
		if ( self::done() ) {
			return;
		}
		$theme = self::source();
		if ( null === $theme ) {
			update_option( self::OPTION, array( 'done' => gmdate( 'Y-m-d' ), 'found' => 0 ), false );
			return;
		}
		$saved = get_option( Scout_Cards_Settings::OPTION );
		$saved = is_array( $saved ) ? $saved : array();
		$plan  = self::plan( $theme, $saved, Scout_Cards_Settings::defaults() );
		if ( $plan['filled'] ) {
			update_option( Scout_Cards_Settings::OPTION, $plan['settings'], false );
			Scout_Cards_Settings::flush();
		}
		// The theme's own /card/ and /links/ Pages stay (they bring the theme's cards back
		// if this plugin is turned off); the Cards screen knows not to warn about them.
		$pages = array();
		foreach ( array( 'card', 'links' ) as $slug ) {
			$pg = function_exists( 'get_page_by_path' ) ? get_page_by_path( $slug ) : null;
			if ( $pg ) {
				$pages[] = (int) $pg->ID;
			}
		}
		update_option(
			self::OPTION,
			array(
				'done'   => gmdate( 'Y-m-d' ),
				'found'  => 1,
				'filled' => $plan['filled'],
				'people' => $plan['people'],
				'pages'  => $pages,
			),
			false
		);
		if ( $plan['filled'] ) {
			$n = count( $plan['people'] );
			$note = 'Copied the business cards from the theme, so they look and read the same.' . ( $n ? sprintf( 1 === $n ? ' %d card person came over.' : ' %d card people came over.', $n ) : '' );
			$old  = get_option( Scout_Cards_People::NOTE );
			update_option( Scout_Cards_People::NOTE, array_merge( is_array( $old ) ? $old : array(), array( 'theme' => $note ) ), false );
		}
	}

	/** The theme's own card and links Pages the copy found (the Cards screen does not warn about them). */
	public static function theme_pages() {
		$o = get_option( self::OPTION );
		return is_array( $o ) ? array_map( 'intval', (array) ( $o['pages'] ?? array() ) ) : array();
	}
}
