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
			'email'        => $id['email'] ? $id['email'] : get_option( 'admin_email' ),
			'city'         => $id['city'],
			'region'       => $id['region'],
			'postal'       => $id['postal'],
			'socials'      => $id['same_as'],
			'footer'       => trim( $id['city'] . ( $id['region'] ? ', ' . $id['region'] : '' ) ),
			'note'         => get_bloginfo( 'description' ),
			'team_intro'   => get_bloginfo( 'description' ),
			'pick_heading' => 'Who would you like to talk to?',
			'photo_id'     => 0,
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
			if ( in_array( $k, array( 'enable_cards', 'enable_links', 'links_latest', 'photo_id' ), true ) ) {
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

	/** Everything each card needs, keyed 'team' then each person's link name. */
	public static function people() {
		$s   = self::get();
		$out = array(
			'team' => array(
				'key'      => 'team',
				'name'     => $s['name'],
				'first'    => '',
				'last'     => '',
				'title'    => '',
				'email'    => $s['email'],
				'intro'    => $s['team_intro'],
				'pick'     => '',
				'tags'     => array(),
				'photo_id' => (int) $s['photo_id'],
				'photo'    => self::photo_url( (int) $s['photo_id'], true ),
				'url'      => self::card_url( 'team' ),
				'save'     => self::card_url( 'team', 'save' ),
				'share'    => self::card_url( 'team', 'share' ),
				'file'     => sanitize_title( $s['name'] ),
			),
		);
		foreach ( (array) $s['people'] as $p ) {
			$slug = sanitize_title( $p['slug'] ?? '' );
			if ( '' === $slug || in_array( $slug, array( 'save', 'share', 'team' ), true ) || empty( $p['name'] ) ) {
				continue;
			}
			$parts        = preg_split( '/\s+/', trim( $p['name'] ) );
			$out[ $slug ] = array(
				'key'      => $slug,
				'name'     => $p['name'],
				'first'    => $parts[0],
				'last'     => count( $parts ) > 1 ? end( $parts ) : '',
				'title'    => $p['title'] ?? '',
				'email'    => ! empty( $p['email'] ) ? $p['email'] : $s['email'],
				'intro'    => $p['intro'] ?? '',
				'pick'     => $p['pick'] ?? '',
				'tags'     => array_values( array_filter( array_map( 'trim', explode( ',', $p['tags'] ?? '' ) ) ) ),
				'photo_id' => (int) ( $p['photo_id'] ?? 0 ),
				'photo'    => self::photo_url( (int) ( $p['photo_id'] ?? 0 ), false ),
				'url'      => self::card_url( $slug ),
				'save'     => self::card_url( $slug, 'save' ),
				'share'    => self::card_url( $slug, 'share' ),
				'file'     => sanitize_title( $p['name'] ),
			);
		}
		return $out;
	}

	/** Photo URL: the uploaded photo, else (team card only) the Site Icon, else ''. */
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
