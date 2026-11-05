<?php
/**
 * Settings: one option, `scout_cards_settings`, layered over defaults built from the
 * business identity in Scout Core (name, phone, email, city, profile URLs). A
 * blank field always falls back, so a fresh install shows a working team card
 * with nothing typed in.
 *
 * @package Scout_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Cards_Settings {

	const OPTION      = 'scout_cards_settings'; // not 'scout_cards': the Scout Media theme uses that name
	const MAX_PEOPLE  = 6;
	const MAX_LINKS   = 10;

	/** The business identity from Scout Core, or blanks when Scout Core is not active. */
	public static function identity() {
		$id = class_exists( 'Scout_Core_Business' ) ? Scout_Core_Business::get() : array();
		return wp_parse_args(
			(array) $id,
			array( 'name' => '', 'phone' => '', 'email' => '', 'city' => '', 'region' => '', 'postal' => '', 'country' => '', 'same_as' => '' )
		);
	}

	public static function defaults() {
		$id = self::identity();
		return array(
			'enable_cards' => 1,
			'enable_links' => 1,
			'card_base'    => 'card',
			'links_base'   => 'links',
			'mode'         => 'dark',
			'accent'       => '#0E8FE6',
			'font'         => '',
			'name'         => $id['name'] ? $id['name'] : get_bloginfo( 'name' ),
			'phone'        => $id['phone'],
			'email'        => $id['email'], // never the WordPress admin email: on client sites that is often not the business
			'city'         => $id['city'],
			'region'       => $id['region'],
			'postal'       => $id['postal'],
			'socials'      => $id['same_as'],
			'footer'       => trim( $id['city'] . ( $id['region'] ? ', ' . $id['region'] : '' ) ),
			'note'         => get_bloginfo( 'description' ),
			'team_intro'   => get_bloginfo( 'description' ),
			'pick_heading' => 'Who would you like to talk to?',
			'photo_id'     => 0,
			'logo_id'        => 0,
			'share_card_id'  => 0,
			'share_links_id' => 0,
			'spot_heading' => '',
			'spot_text'    => '',
			'spot_label'   => '',
			'spot_sub'     => '',
			'spot_url'     => '',
			'people'       => array(),
			'links_intro'  => get_bloginfo( 'description' ),
			'links_latest' => 1,
			'links'        => array(
				array( 'label' => 'Contact us', 'sub' => '', 'url' => '/contact/', 'external' => 0 ),
				array( 'label' => 'Save our contact', 'sub' => 'Add us to your phone', 'url' => '/card/', 'external' => 0 ),
			),
		);
	}

	/** Saved settings over the defaults; blanks fall back. */
	public static function get() {
		$d = self::defaults();
		$s = get_option( self::OPTION );
		if ( ! is_array( $s ) ) {
			return $d;
		}
		foreach ( $s as $k => $v ) {
			if ( in_array( $k, array( 'people', 'links' ), true ) ) {
				if ( is_array( $v ) ) {
					$d[ $k ] = $v;
				}
				continue;
			}
			if ( in_array( $k, array( 'enable_cards', 'enable_links', 'links_latest', 'photo_id', 'logo_id', 'share_card_id', 'share_links_id' ), true ) ) {
				$d[ $k ] = (int) $v;
				continue;
			}
			if ( '' !== $v && null !== $v ) {
				$d[ $k ] = $v;
			}
		}
		return $d;
	}

	public static function phone_e164( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( 10 === strlen( $digits ) ) {
			$digits = '1' . $digits;
		}
		return $digits ? '+' . $digits : '';
	}

	/** '/path/' stays on this site; anything else is used as-is. */
	public static function href( $url ) {
		return ( 0 === strpos( (string) $url, '/' ) ) ? home_url( $url ) : $url;
	}

	public static function card_url( $who = 'team', $suffix = '' ) {
		$s    = self::get();
		$path = '/' . $s['card_base'] . '/' . ( 'team' === $who ? '' : $who . '/' ) . ( $suffix ? $suffix . '/' : '' );
		return home_url( $path );
	}

	/**
	 * Spotlight under a card: an optional heading and note, then one link row
	 * (e.g. a product, a booking page, or a second business). Null when empty.
	 */
	public static function spot( $v ) {
		$out = array();
		foreach ( array( 'heading', 'text', 'label', 'sub', 'url' ) as $k ) {
			$out[ $k ] = (string) ( $v[ 'spot_' . $k ] ?? '' );
		}
		$out['href'] = '' !== $out['url'] ? self::href( $out['url'] ) : '';
		return '' === $out['heading'] . $out['text'] . $out['label'] ? null : $out;
	}

	/** people() for this request, so a page that asks more than once reads People once. */
	private static $people = null;

	/** Forget the cached people (after a save, or in tests). */
	public static function flush() {
		self::$people = null;
	}

	/**
	 * Everything each card needs, keyed 'team' then each person's link name.
	 *
	 * A card linked to a People profile (`profile_id`) reads its name, title, photo,
	 * card phone, card email, links and bio from People; it is left out (so its
	 * address answers "not found") when the person is not live or Card is off. With
	 * Scout People off, or the profile gone, the card uses its own saved details as
	 * before. After the move to People, anyone live with Card on gets a card too.
	 */
	public static function people() {
		if ( null !== self::$people ) {
			return self::$people;
		}
		$s   = self::get();
		$out = array(
			'team' => array(
				'key'      => 'team',
				'name'     => $s['name'],
				'first'    => '',
				'last'     => '',
				'title'    => '',
				'email'    => $s['email'],
				'phone'    => $s['phone'],
				'intro'    => $s['team_intro'],
				'pick'     => '',
				'tags'     => array(),
				'photo_id' => (int) $s['photo_id'],
				'photo'    => self::photo_url( (int) $s['photo_id'], true ),
				'alt'      => $s['name'],
				'bio'      => '',
				'links'    => array(),
				'show'     => Scout_Cards_People::show( array() ),
				'profile'  => 0,
				'url'      => self::card_url( 'team' ),
				'save'     => self::card_url( 'team', 'save' ),
				'share'    => self::card_url( 'team', 'share' ),
				'file'     => sanitize_title( $s['name'] ),
				'spot'     => self::spot( $s ),
			),
		);
		$reserved = array( 'save', 'share', 'team' );
		$linked   = array();
		foreach ( (array) $s['people'] as $p ) {
			$slug = sanitize_title( $p['slug'] ?? '' );
			if ( '' === $slug || in_array( $slug, $reserved, true ) ) {
				continue;
			}
			$pid = (int) ( $p['profile_id'] ?? 0 );
			if ( $pid ) {
				$linked[] = $pid;
				$c        = Scout_Cards_People::profile( $pid );
				if ( $c ) {
					if ( ! empty( $c['on'] ) && '' !== $c['name'] ) {
						$out[ $slug ] = self::person( $slug, self::from_people( $c ), $p, $s );
					}
					continue; // Not live, or Card off: no card.
				}
			}
			if ( empty( $p['name'] ) ) {
				continue;
			}
			$out[ $slug ] = self::person( $slug, self::from_record( $p ), $p, $s );
		}
		if ( Scout_Cards_People::reading() ) {
			foreach ( Scout_Cards_People::card_ids() as $pid ) {
				if ( in_array( $pid, $linked, true ) ) {
					continue;
				}
				$c = Scout_Cards_People::profile( $pid );
				if ( ! $c || empty( $c['on'] ) || '' === $c['name'] ) {
					continue;
				}
				$base = sanitize_title( '' !== $c['slug'] ? $c['slug'] : $c['name'] );
				$slug = $base;
				for ( $n = 2; '' === $slug || in_array( $slug, $reserved, true ) || isset( $out[ $slug ] ); $n++ ) {
					$slug = ( '' !== $base ? $base : 'person' ) . '-' . $n;
				}
				$out[ $slug ] = self::person( $slug, self::from_people( $c ), array( 'profile_id' => $pid ), $s );
			}
		}
		self::$people = $out;
		return $out;
	}

	/** A card's own saved details, as cards used them before People. */
	private static function from_record( array $p ) {
		return array(
			'name'     => (string) $p['name'],
			'title'    => (string) ( $p['title'] ?? '' ),
			'email'    => (string) ( $p['email'] ?? '' ),
			'phone'    => '',
			'photo_id' => (int) ( $p['photo_id'] ?? 0 ),
			'alt'      => '',
			'bio'      => '',
			'links'    => array(),
			'profile'  => 0,
		);
	}

	/** A person's details from their People profile (Scout_Team::card()). */
	private static function from_people( array $c ) {
		return array(
			'name'     => (string) $c['name'],
			'title'    => (string) $c['role'],
			'email'    => (string) $c['card_email'],
			'phone'    => (string) $c['phone'],
			'photo_id' => (int) $c['photo_id'],
			'alt'      => (string) $c['alt'],
			'bio'      => (string) $c['bio'],
			'links'    => (array) $c['links'],
			'profile'  => (int) $c['id'],
		);
	}

	/**
	 * One person's card. $f holds who they are (from People or the card's own saved
	 * details); $rec holds the card's own settings. A blank card email or phone uses
	 * the business one, as cards always have. A field switched off on the card is blank.
	 */
	private static function person( $slug, array $f, array $rec, array $s ) {
		$show  = Scout_Cards_People::show( $rec );
		$parts = preg_split( '/\s+/', trim( $f['name'] ) );
		return array(
			'key'      => $slug,
			'name'     => $f['name'],
			'first'    => $parts[0],
			'last'     => count( $parts ) > 1 ? end( $parts ) : '',
			'title'    => $f['title'],
			'email'    => $show['email'] ? ( '' !== $f['email'] ? $f['email'] : $s['email'] ) : '',
			'phone'    => $show['phone'] ? ( '' !== $f['phone'] ? $f['phone'] : $s['phone'] ) : '',
			'intro'    => $rec['intro'] ?? '',
			'pick'     => $rec['pick'] ?? '',
			'tags'     => array_values( array_filter( array_map( 'trim', explode( ',', $rec['tags'] ?? '' ) ) ) ),
			'photo_id' => $f['photo_id'],
			'photo'    => self::photo_url( $f['photo_id'], false ),
			'alt'      => '' !== $f['alt'] ? $f['alt'] : $f['name'],
			'bio'      => $show['bio'] ? $f['bio'] : '',
			'links'    => $show['links'] ? $f['links'] : array(),
			'show'     => $show,
			'profile'  => $f['profile'],
			'url'      => self::card_url( $slug ),
			'save'     => self::card_url( $slug, 'save' ),
			'share'    => self::card_url( $slug, 'share' ),
			'file'     => sanitize_title( $f['name'] ),
			'spot'     => self::spot( $rec ),
			'share_id' => (int) ( $rec['share_id'] ?? 0 ),
		);
	}

	/** Photo URL: the uploaded photo, else (team card only) the Site Icon, else ''. */
	/**
	 * The wide picture shown when a page is shared in a text or a post (1200 by 630 works
	 * everywhere). 'card' or 'links'; the links page falls back to the card's. Null when unset.
	 */
	public static function share_image( $which, $person_share_id = 0 ) {
		$s  = self::get();
		$id = 'links' === $which && $s['share_links_id'] ? $s['share_links_id'] : $s['share_card_id'];
		if ( 'person' === $which ) {
			$id = (int) $person_share_id;
		}
		$src = $id ? wp_get_attachment_image_src( (int) $id, 'full' ) : false;
		return $src ? array( 'url' => $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2] ) : null;
	}

	/** The logo for the links page and share page, or '' (they fall back to the main card's photo). */
	public static function logo_url() {
		$id = (int) self::get()['logo_id'];
		$u  = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		return $u ? (string) $u : '';
	}

	public static function photo_url( $photo_id, $team ) {
		if ( $photo_id ) {
			$u = wp_get_attachment_image_url( $photo_id, 'medium' );
			if ( $u ) {
				return $u;
			}
		}
		return $team ? (string) get_site_icon_url( 256 ) : '';
	}

	/** Profile URLs, one per line (defaults to Scout Core's same_as), as label => url for known networks. */
	public static function socials() {
		$known = array(
			'facebook.com'  => 'Facebook',
			'instagram.com' => 'Instagram',
			'linkedin.com'  => 'LinkedIn',
			'x.com'         => 'X',
			'twitter.com'   => 'X',
			'threads.net'   => 'Threads',
			'threads.com'   => 'Threads',
			'youtube.com'   => 'YouTube',
			'tiktok.com'    => 'TikTok',
			'pinterest.com' => 'Pinterest',
		);
		$out = array();
		foreach ( preg_split( '/\R+/', (string) self::get()['socials'] ) as $line ) {
			$url  = trim( $line );
			$host = preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( $host && isset( $known[ $host ] ) && ! isset( $out[ $known[ $host ] ] ) ) {
				$out[ $known[ $host ] ] = $url;
			}
		}
		return $out;
	}

	/**
	 * A person's profile links (from People) as label => url: the network's name
	 * for a known one, else the site's own name. A repeat of a label is skipped.
	 *
	 * @param array $urls Profile URLs.
	 */
	public static function label_links( array $urls ) {
		$known = array(
			'facebook.com'  => 'Facebook',
			'instagram.com' => 'Instagram',
			'linkedin.com'  => 'LinkedIn',
			'x.com'         => 'X',
			'twitter.com'   => 'X',
			'threads.net'   => 'Threads',
			'threads.com'   => 'Threads',
			'youtube.com'   => 'YouTube',
			'tiktok.com'    => 'TikTok',
			'pinterest.com' => 'Pinterest',
		);
		$out = array();
		foreach ( $urls as $url ) {
			$url  = trim( (string) $url );
			$host = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
			if ( '' === $host ) {
				continue;
			}
			$label = $known[ $host ] ?? $host;
			if ( ! isset( $out[ $label ] ) ) {
				$out[ $label ] = $url;
			}
		}
		return $out;
	}

	/** Link rows for /links/, newest post first when switched on. */
	public static function links() {
		$s    = self::get();
		$rows = array();
		if ( $s['links_latest'] ) {
			$latest = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'ignore_sticky_posts' => true ) );
			if ( $latest ) {
				$rows[] = array( 'label' => 'New on the blog', 'sub' => get_the_title( $latest[0] ), 'url' => get_permalink( $latest[0] ), 'external' => false, 'featured' => true );
			}
		}
		foreach ( (array) $s['links'] as $l ) {
			if ( empty( $l['label'] ) || empty( $l['url'] ) ) {
				continue;
			}
			$rows[] = array( 'label' => $l['label'], 'sub' => $l['sub'] ?? '', 'url' => self::href( $l['url'] ), 'external' => ! empty( $l['external'] ), 'featured' => false );
		}
		return $rows;
	}
}
