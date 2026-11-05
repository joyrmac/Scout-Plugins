<?php
/**
 * Cards read from People. One profile per person: the name, title, photo,
 * card phone, card email, profile links and bio live in Scout People
 * (scout-team), and each card keeps only its own look and links settings
 * (link name, intro, "why talk to them", tags, share picture, spotlight and
 * which profile fields show on the card).
 *
 * A card record in `scout_cards_settings['people']` points at its profile with
 * `profile_id`. Its old name, title, email and photo stay saved, and the card
 * falls back to them when Scout People is off or the profile is gone.
 *
 * The one-time move (gated by `scout_cards_people_db`) links every card person
 * to their People profile, or makes one that shows on the card only.
 *
 * @package Scout_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Cards_People {

	const DB_OPTION  = 'scout_cards_people_db';
	const DB_VERSION = 1;
	const NOTE       = 'scout_cards_people_note';
	const LOCK       = 'scout_cards_people_moving';

	/** The profile fields a card can show or hide, and whether each starts on. */
	const SHOW = array(
		'phone' => 1,
		'email' => 1,
		'links' => 0,
		'bio'   => 0,
		'save'  => 1,
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 30 ); // after Scout People's own move (init 20)
	}

	/** Is Scout People on, with the functions Cards reads? */
	public static function available() {
		return class_exists( 'Scout_Team' ) && method_exists( 'Scout_Team', 'card' ) && method_exists( 'Scout_Team', 'card_ids' );
	}

	/** Has the one-time move run? */
	public static function upgraded() {
		return (int) get_option( self::DB_OPTION, 0 ) >= self::DB_VERSION;
	}

	/** Cards read People: Scout People is on and the move has run. */
	public static function reading() {
		return self::available() && self::upgraded();
	}

	/**
	 * Which profile fields this card shows, from its saved switches, else the defaults
	 * (which match what cards showed before: phone, email and Save contact on).
	 *
	 * @param array $rec A saved card record.
	 */
	public static function show( array $rec ) {
		$out = array();
		foreach ( self::SHOW as $k => $on ) {
			$out[ $k ] = isset( $rec[ 'show_' . $k ] ) ? (int) (bool) $rec[ 'show_' . $k ] : $on;
		}
		return $out;
	}

	/** A person's card from People, or null when Scout People is off or the profile is gone. */
	public static function profile( $id ) {
		if ( ! $id || ! self::available() ) {
			return null;
		}
		$c = Scout_Team::card( (int) $id );
		return is_array( $c ) ? $c : null;
	}

	/** People with Card switched on and live, in team page order. */
	public static function card_ids() {
		return self::available() ? array_map( 'intval', (array) Scout_Team::card_ids( true ) ) : array();
	}

	/** The People walkthrough, with Card switched on. */
	public static function add_url() {
		$url = class_exists( 'Scout_Team_Add' ) && method_exists( 'Scout_Team_Add', 'url' ) ? Scout_Team_Add::url() : admin_url( 'admin.php?page=scout-people&add=1' );
		return add_query_arg( 'card', 1, $url );
	}

	/* ---------- the one-time move ---------- */

	/**
	 * The profile a card person belongs to: the one tied to the login with the
	 * card's email, then the one with that email (or card email), then the one
	 * with exactly that name. 0 when none. A live card only matches a live
	 * profile, so the card keeps working; a profile is matched to one card only.
	 *
	 * @param array $card  name, email, user (the login with the card's email, or 0).
	 * @param array $cands Profiles: id, name, email, card_email, user, status.
	 * @param bool  $live  The card is live today.
	 * @param array $taken Profile IDs already linked to a card.
	 */
	public static function match( array $card, array $cands, $live, array $taken = array() ) {
		$open = array();
		foreach ( $cands as $c ) {
			$status = (string) ( $c['status'] ?? '' );
			if ( in_array( (int) $c['id'], $taken, true ) || 'trash' === $status || ( $live && 'publish' !== $status ) ) {
				continue;
			}
			$open[] = $c;
		}
		$user = (int) ( $card['user'] ?? 0 );
		if ( $user > 0 ) {
			foreach ( $open as $c ) {
				if ( (int) ( $c['user'] ?? 0 ) === $user ) {
					return (int) $c['id'];
				}
			}
		}
		$email = strtolower( trim( (string) ( $card['email'] ?? '' ) ) );
		if ( '' !== $email ) {
			foreach ( $open as $c ) {
				if ( strtolower( trim( (string) ( $c['email'] ?? '' ) ) ) === $email || strtolower( trim( (string) ( $c['card_email'] ?? '' ) ) ) === $email ) {
					return (int) $c['id'];
				}
			}
		}
		$name = trim( (string) ( $card['name'] ?? '' ) );
		if ( '' !== $name ) {
			foreach ( $open as $c ) {
				if ( trim( (string) ( $c['name'] ?? '' ) ) === $name ) {
					return (int) $c['id'];
				}
			}
		}
		return 0;
	}

	/**
	 * Copy a card person into a profile that already exists. Only fields the
	 * profile is missing are filled; a People value is never overwritten. Card
	 * is switched on. Also says which fields the card will now show differently,
	 * because People had its own value.
	 *
	 * @param array  $p     The profile in the full shape.
	 * @param array  $card  The saved card record.
	 * @param string $name  The profile's name.
	 * @param int    $thumb The profile's photo, or 0.
	 * @return array profile, photo (an attachment to set as the photo, or 0), changed (field names).
	 */
	public static function merge( array $p, array $card, $name, $thumb ) {
		$title = trim( (string) ( $card['title'] ?? '' ) );
		$email = trim( (string) ( $card['email'] ?? '' ) );
		$photo = (int) ( $card['photo_id'] ?? 0 );
		if ( '' === trim( (string) ( $p['role'] ?? '' ) ) && '' !== $title ) {
			$p['role'] = $title;
		}
		if ( '' === trim( (string) ( $p['card_email'] ?? '' ) ) && '' !== $email ) {
			$p['card_email'] = $email;
		}
		$set = ( ! $thumb && $photo ) ? $photo : 0;
		if ( ! isset( $p['shows'] ) || ! is_array( $p['shows'] ) ) {
			$p['shows'] = array();
		}
		$p['shows']['card'] = true;

		// What the card showed before against what it shows now.
		$changed = array();
		if ( trim( (string) $name ) !== trim( (string) ( $card['name'] ?? '' ) ) ) {
			$changed[] = 'name';
		}
		if ( trim( (string) $p['role'] ) !== $title ) {
			$changed[] = 'title';
		}
		if ( strtolower( trim( (string) $p['card_email'] ) ) !== strtolower( $email ) ) {
			$changed[] = 'email';
		}
		if ( '' !== trim( (string) ( $p['card_phone'] ?? '' ) ) ) {
			$changed[] = 'phone';
		}
		if ( ( $set ? $set : (int) $thumb ) !== $photo ) {
			$changed[] = 'photo';
		}
		return array(
			'profile' => $p,
			'photo'   => $set,
			'changed' => $changed,
		);
	}

	/**
	 * A new profile for a card person with no match: their title and card email,
	 * and Card on with everything else off, so nothing new shows on the site.
	 *
	 * @param array $card  The saved card record.
	 * @param array $empty Scout People's empty profile.
	 */
	public static function new_profile( array $card, array $empty ) {
		$p               = $empty;
		$p['role']       = trim( (string) ( $card['title'] ?? '' ) );
		$p['card_email'] = trim( (string) ( $card['email'] ?? '' ) );
		$shows           = array();
		foreach ( array_keys( (array) ( $empty['shows'] ?? array() ) ) as $k ) {
			$shows[ $k ] = false;
		}
		$shows['card'] = true;
		$p['shows']    = $shows;
		return $p;
	}

	/**
	 * Are the card people the team? Yes when People has no one in Team yet, or
	 * when any card person matched someone already in Team.
	 *
	 * @param array $cands   Profiles, each with 'team' (in the Team group).
	 * @param array $matched Matched profile IDs.
	 */
	public static function list_is_team( array $cands, array $matched ) {
		$any = false;
		foreach ( $cands as $c ) {
			if ( ! empty( $c['team'] ) && 'trash' !== ( $c['status'] ?? '' ) ) {
				$any = true;
				if ( in_array( (int) $c['id'], $matched, true ) ) {
					return true;
				}
			}
		}
		return ! $any;
	}

	/** The note the Cards screen shows once after the move, in plain words. */
	public static function note_text( array $n ) {
		$moved = (int) ( $n['moved'] ?? 0 );
		$lines = array();
		if ( $moved ) {
			$lines[] = sprintf( 1 === $moved ? 'Moved %d card person into People.' : 'Moved %d card people into People.', $moved );
		}
		$found = (int) ( $n['found'] ?? 0 );
		if ( $found ) {
			$lines[] = sprintf( 1 === $found ? '%d already had a profile, so that card now reads from it.' : '%d already had a profile, so those cards now read from them.', $found );
		}
		$list = array();
		foreach ( (array) ( $n['changed'] ?? array() ) as $who => $fields ) {
			$list[] = $who . ' (' . implode( ', ', (array) $fields ) . ')';
		}
		if ( $list ) {
			$lines[] = 'These cards now show what People has: ' . implode( '; ', $list ) . '.';
		}
		$off = (array) ( $n['off'] ?? array() );
		if ( $off ) {
			$lines[] = 'Card was on in People for ' . implode( ', ', $off ) . ', with no card yet, so it was switched off and nothing new shows. Turn it on in People to give them a card.';
		}
		return implode( ' ', $lines );
	}

	/** Every profile, for matching. */
	private static function candidates() {
		$ids  = get_posts(
			array(
				'post_type'      => Scout_Team::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'no_found_rows'  => true,
			)
		);
		$team = method_exists( 'Scout_Team', 'team_term' ) ? (int) Scout_Team::team_term() : 0;
		$out  = array();
		foreach ( $ids as $id ) {
			$c = Scout_Team::card( (int) $id );
			if ( ! $c ) {
				continue;
			}
			$out[] = array(
				'id'         => (int) $id,
				'name'       => $c['name'],
				'email'      => $c['email'],
				'card_email' => $c['card_email'],
				'user'       => $c['user'],
				'status'     => $c['status'],
				'card'       => ! empty( Scout_Team::profile( (int) $id )['shows']['card'] ),
				'team'       => $team && has_term( $team, Scout_Team::TAX, (int) $id ),
			);
		}
		return $out;
	}

	/** Make a profile for a card person. Returns its ID, or 0. */
	private static function create( array $card, $live, $team ) {
		// Scout People puts any new person in Team, and its edit screen saves from the form
		// being sent. Neither applies here: this person is made from the card.
		$group = has_action( 'wp_after_insert_post', array( 'Scout_Team', 'new_person_group' ) );
		$edit  = class_exists( 'Scout_Team_Editor' ) ? has_action( 'save_post_' . Scout_Team::POST_TYPE, array( 'Scout_Team_Editor', 'save' ) ) : false;
		if ( false !== $group ) {
			remove_action( 'wp_after_insert_post', array( 'Scout_Team', 'new_person_group' ), $group );
		}
		if ( false !== $edit ) {
			remove_action( 'save_post_' . Scout_Team::POST_TYPE, array( 'Scout_Team_Editor', 'save' ), $edit );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => Scout_Team::POST_TYPE,
				'post_title'   => (string) $card['name'],
				'post_status'  => $live ? 'publish' : 'draft',
				'post_content' => '',
				'meta_input'   => array( Scout_Team::META => self::new_profile( $card, Scout_Team::empty_profile() ) ),
			),
			true
		);
		if ( false !== $group ) {
			add_action( 'wp_after_insert_post', array( 'Scout_Team', 'new_person_group' ), $group, 3 );
		}
		if ( false !== $edit ) {
			add_action( 'save_post_' . Scout_Team::POST_TYPE, array( 'Scout_Team_Editor', 'save' ), $edit, 2 );
		}
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		if ( ! empty( $card['photo_id'] ) ) {
			set_post_thumbnail( (int) $id, (int) $card['photo_id'] );
		}
		$term = $team && method_exists( 'Scout_Team', 'team_term' ) ? (int) Scout_Team::team_term() : 0;
		if ( $term ) {
			wp_set_object_terms( (int) $id, array( $term ), Scout_Team::TAX, false );
		}
		return (int) $id;
	}

	/**
	 * The one-time move. Runs once Scout People is on and has done its own move.
	 * Never on a form post, so no other screen's fields can reach a new profile.
	 */
	public static function maybe_upgrade() {
		if ( self::upgraded() || ! self::available() || get_transient( self::LOCK ) ) {
			return;
		}
		if ( method_exists( 'Scout_Team', 'upgraded' ) && ! Scout_Team::upgraded() ) {
			return; // Scout People has not made its groups yet.
		}
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );
		$note = self::run();
		if ( $note ) {
			update_option( self::NOTE, $note, false );
		}
		update_option( self::DB_OPTION, self::DB_VERSION, false );
		delete_transient( self::LOCK );
	}

	/** The move itself. Returns the note, or an empty array when there was nothing to move. */
	public static function run() {
		$raw     = get_option( Scout_Cards_Settings::OPTION );
		$records = is_array( $raw ) && is_array( $raw['people'] ?? null ) ? $raw['people'] : array();
		$live    = (bool) Scout_Cards_Settings::get()['enable_cards'];
		$cands   = self::candidates();
		$taken   = array();
		$plan    = array();
		foreach ( $records as $i => $rec ) {
			if ( ! empty( $rec['profile_id'] ) ) {
				$taken[] = (int) $rec['profile_id'];
			}
		}
		// First pass: who matches whom.
		foreach ( $records as $i => $rec ) {
			$slug = sanitize_title( (string) ( $rec['slug'] ?? '' ) );
			if ( ! empty( $rec['profile_id'] ) || '' === trim( (string) ( $rec['name'] ?? '' ) ) || '' === $slug || in_array( $slug, array( 'save', 'share', 'team' ), true ) ) {
				continue; // Linked already, or a card that never showed.
			}
			$email = trim( (string) ( $rec['email'] ?? '' ) );
			$login = '' !== $email ? get_user_by( 'email', $email ) : false;
			$id    = self::match(
				array(
					'name'  => $rec['name'],
					'email' => $email,
					'user'  => $login ? (int) $login->ID : 0,
				),
				$cands,
				$live,
				$taken
			);
			$plan[ $i ] = $id;
			if ( $id ) {
				$taken[] = $id;
			}
		}
		if ( ! $plan ) {
			$note = array();
		} else {
			$team = (bool) apply_filters( 'scout_cards_people_in_team', self::list_is_team( $cands, array_values( array_filter( $plan ) ) ) );
			$note = array(
				'moved'   => 0,
				'found'   => 0,
				'changed' => array(),
				'off'     => array(),
			);
			foreach ( $plan as $i => $id ) {
				$rec = $records[ $i ];
				if ( $id ) {
					$c   = Scout_Team::card( $id );
					$out = self::merge( Scout_Team::profile( $id ), $rec, (string) $c['name'], (int) $c['photo_id'] );
					update_post_meta( $id, Scout_Team::META, $out['profile'] );
					if ( $out['photo'] ) {
						set_post_thumbnail( $id, $out['photo'] );
					}
					if ( $out['changed'] ) {
						$note['changed'][ (string) $rec['name'] ] = $out['changed'];
					}
					++$note['found'];
				} else {
					$id = self::create( $rec, $live, $team );
				}
				if ( $id ) {
					$records[ $i ]['profile_id'] = $id;
					++$note['moved'];
				}
			}
			$raw['people'] = $records;
			update_option( Scout_Cards_Settings::OPTION, $raw, false );
			Scout_Cards_Settings::flush();
		}

		// Card was a saved switch with nothing behind it until now. Anyone who had it on
		// with no card gets it turned off, so no new card appears on the site.
		$linked = array_values( array_filter( array_map( static fn( $r ) => (int) ( $r['profile_id'] ?? 0 ), $records ) ) );
		foreach ( $cands as $c ) {
			if ( empty( $c['card'] ) || in_array( (int) $c['id'], $linked, true ) ) {
				continue;
			}
			$p                  = Scout_Team::profile( (int) $c['id'] );
			$p['shows']['card'] = false;
			update_post_meta( (int) $c['id'], Scout_Team::META, $p );
			if ( ! $note ) {
				$note = array(
					'moved'   => 0,
					'found'   => 0,
					'changed' => array(),
					'off'     => array(),
				);
			}
			$note['off'][] = (string) $c['name'];
		}
		return $note;
	}

	/** The note from the move, shown once on the Cards screen, then removed. '' when there is none. */
	public static function take_note() {
		$n = get_option( self::NOTE );
		if ( ! is_array( $n ) ) {
			return '';
		}
		delete_option( self::NOTE );
		return self::note_text( $n );
	}
}
