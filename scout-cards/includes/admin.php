<?php
/**
 * Dashboard screen: Scout -> Cards and links (inside Scout Core's menu), or a
 * top-level "Site Build" section on a site without Scout Core. Everything on the
 * card and links pages is edited here and saved to the `scout_cards_settings` option.
 *
 * @package Scout_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Cards_Admin {

	const SLUG = 'scout-cards';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 ); // after Scout Core's menu (10)
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_scout_cards_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_scout_cards_reset', array( __CLASS__, 'reset' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_CARDS_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/**
	 * Parent menu: the site's existing Build section when there is one (the Scout
	 * Media theme's Scout Build, Recon's Recon Build, or Scout Core's Scout menu),
	 * else Site Build (created by the Scout Build hub, or by Cards itself).
	 */
	public static function parent() {
		global $admin_page_hooks;
		foreach ( array( 'scout-build', 'recon-build', 'scout' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'site-build';
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function menu() {
		global $admin_page_hooks;
		$parent = self::parent();
		// Create the Site Build section only when nothing else has: the Scout Build
		// hub plugin (scout-build) registers it first and Cards nests inside it.
		$own = 'site-build' === $parent && ! isset( $admin_page_hooks['site-build'] );
		if ( $own ) {
			add_menu_page( 'Site Build', 'Site Build', 'edit_pages', 'site-build', array( __CLASS__, 'render' ), 'dashicons-id-alt', 3 );
			add_submenu_page( 'site-build', 'Cards & links', 'Cards & links', 'edit_pages', 'site-build', array( __CLASS__, 'render' ) );
		}
		add_submenu_page( $parent, 'Cards & links', 'Cards & links', 'edit_pages', self::SLUG, array( __CLASS__, 'render' ) );
		if ( $own ) {
			remove_submenu_page( 'site-build', self::SLUG ); // the section's first item already opens this screen
		}
	}

	public static function action_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Settings</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( false !== strpos( (string) $hook, self::SLUG ) || false !== strpos( (string) $hook, 'site-build' ) ) {
			wp_enqueue_media();
			Scout_Admin_UI::enqueue( SCOUT_CARDS_URL . 'assets/scout-admin.css' );
			wp_enqueue_style( 'scout-cards-admin', SCOUT_CARDS_URL . 'assets/cards-admin.css', array( 'scout-admin' ), SCOUT_CARDS_VERSION );
			wp_enqueue_script( 'scout-cards-qr', SCOUT_CARDS_URL . 'assets/qrcode.min.js', array(), SCOUT_CARDS_VERSION, true );
		}
	}

	/**
	 * The kit's status line. On a site mid-update an older kit (1.9.x) may be the one
	 * loaded, without status(), so the same markup is printed here instead.
	 *
	 * @param string $go Optional button HTML, already escaped.
	 */
	private static function status( $state, $headline, $detail = '', $go = '' ) {
		if ( method_exists( 'Scout_Admin_UI', 'status' ) ) {
			Scout_Admin_UI::status( $state, $headline, $detail, $go );
			return;
		}
		$state = in_array( $state, array( 'good', 'warn', 'bad', 'off' ), true ) ? $state : 'good';
		echo '<section class="sh-box"><p class="sh-status is-' . esc_attr( $state ) . '"><span class="sh-dot" aria-hidden="true"></span><strong>' . esc_html( $headline ) . '</strong>';
		if ( '' !== $detail ) {
			echo ' <span>' . esc_html( $detail ) . '</span>';
		}
		if ( '' !== $go ) {
			echo ' <span class="sh-status-go">' . $go . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller.
		}
		echo '</p></section>';
	}

	/** The kit's closed "More options" box, with the same markup when an older kit is loaded. */
	private static function fold_open( $title ) {
		if ( method_exists( 'Scout_Admin_UI', 'fold_open' ) ) {
			Scout_Admin_UI::fold_open( $title );
			return;
		}
		echo '<details class="sh-box sh-fold"><summary class="sh-title">' . esc_html( $title ) . '</summary>';
	}

	private static function fold_close() {
		if ( method_exists( 'Scout_Admin_UI', 'fold_close' ) ) {
			Scout_Admin_UI::fold_close();
			return;
		}
		echo '</details>';
	}

	private static function field( $name, $value, $label, $type = 'text', $help = '' ) {
		$id = 'scc_' . md5( $name );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'textarea' === $type ) {
			echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input class="regular-text" type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function photo( $name, $photo_id, $label, $help ) {
		$src = $photo_id ? wp_get_attachment_image_url( $photo_id, 'thumbnail' ) : '';
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td class="scc-photo-field">'
			. '<img class="scc-thumb" src="' . esc_url( (string) $src ) . '" alt=""' . ( $src ? '' : ' hidden' ) . '> '
			. '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $photo_id ? $photo_id : '' ) . '">'
			. '<button type="button" class="button scc-pick">Choose photo</button> <button type="button" class="button sh-quiet scc-clear">Remove</button>'
			. '<p class="description">' . esc_html( $help ) . '</p></td></tr>';
	}

	/** Spotlight fields: an optional block under the card. Leave all blank to hide it. */
	private static function spot_fields( $base, $v ) {
		echo '<tr class="scc-spot-head"><th scope="row" colspan="2">Spotlight <span class="description">(optional block under the card, such as a product or booking page)</span></th></tr>';
		self::field( "{$base}[spot_heading]", $v['spot_heading'] ?? '', 'Spotlight heading' );
		self::field( "{$base}[spot_text]", $v['spot_text'] ?? '', 'Spotlight note', 'textarea' );
		self::field( "{$base}[spot_label]", $v['spot_label'] ?? '', 'Button text' );
		self::field( "{$base}[spot_sub]", $v['spot_sub'] ?? '', 'Small line under it' );
		self::field( "{$base}[spot_url]", $v['spot_url'] ?? '', 'Button link', 'text', 'Start with / for a page on this site.' );
	}

	/** Sanitized spotlight values from a submitted card. */
	private static function clean_spot( $v ) {
		return array(
			'spot_heading' => sanitize_text_field( (string) ( $v['spot_heading'] ?? '' ) ),
			'spot_text'    => sanitize_textarea_field( (string) ( $v['spot_text'] ?? '' ) ),
			'spot_label'   => sanitize_text_field( (string) ( $v['spot_label'] ?? '' ) ),
			'spot_sub'     => sanitize_text_field( (string) ( $v['spot_sub'] ?? '' ) ),
			'spot_url'     => esc_url_raw( trim( (string) ( $v['spot_url'] ?? '' ) ) ),
		);
	}

	/**
	 * One row in the People list: photo, name, one line, the card links, and one
	 * outlined Edit button that opens this card's fields under the row.
	 *
	 * @param string $id     Panel id.
	 * @param string $photo  Photo URL, or '' for the first letter.
	 * @param string $name   Name shown on the row.
	 * @param string $sub    One line under the name.
	 * @param array  $card   The card from Scout_Cards_Settings::people(), or empty when it has no link yet.
	 * @param string $button Button words.
	 * @param string $class  Extra row classes.
	 * @param callable $fields Prints the panel's form table rows.
	 * @param string $go     More buttons beside Edit, already escaped.
	 * @param callable|null $more  Prints the form table rows of a closed fold under the panel's fields.
	 * @param string        $title The fold's title.
	 */
	private static function person_row( $id, $photo, $name, $sub, $card, $button, $class, $fields, $go = '', $more = null, $title = 'More' ) {
		echo '<li class="sh-row scc-row' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '">';
		echo '<div class="scc-who">';
		if ( $photo ) {
			echo '<img class="scc-avatar" src="' . esc_url( $photo ) . '" alt="">';
		} else {
			$letter = function_exists( 'mb_substr' ) ? mb_substr( trim( (string) $name ), 0, 1 ) : substr( trim( (string) $name ), 0, 1 );
			echo '<span class="scc-avatar" aria-hidden="true">' . esc_html( '' !== $letter ? strtoupper( $letter ) : '+' ) . '</span>';
		}
		echo '<div><p class="sh-row-name">' . esc_html( $name ) . '</p>';
		if ( '' !== $sub ) {
			echo '<p class="sh-row-sub">' . esc_html( $sub ) . '</p>';
		}
		echo '</div></div>';
		echo '<div class="sh-row-facts">';
		if ( $card ) {
			$path = (string) wp_parse_url( $card['url'], PHP_URL_PATH );
			echo '<a href="' . esc_url( $card['url'] ) . '" target="_blank" rel="noopener"><code>' . esc_html( '' !== $path ? $path : $card['url'] ) . '</code></a>';
			echo '<a href="' . esc_url( $card['share'] ) . '" target="_blank" rel="noopener">Share mode</a>';
			if ( ! empty( $card['profile'] ) ) {
				self::qr( $card['url'] );
			}
		}
		echo '</div>';
		echo '<div class="sh-row-go"><button type="button" class="button scc-edit" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '">' . esc_html( $button ) . '</button>' . $go . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $go is escaped by the caller.
		echo '<div class="scc-panel" id="' . esc_attr( $id ) . '"><table class="form-table" role="presentation">';
		call_user_func( $fields );
		echo '</table>';
		if ( $more ) {
			echo '<details class="scc-fold"><summary>' . esc_html( $title ) . '</summary><table class="form-table" role="presentation">';
			call_user_func( $more );
			echo '</table></details>';
		}
		echo '</div></li>';
	}

	/** A QR code for a card, drawn in the browser when it opens. */
	private static function qr( $url ) {
		echo '<details class="scc-qr"><summary>QR code</summary><div class="scc-qr-box" data-qr-url="' . esc_url( $url ) . '" role="img" aria-label="' . esc_attr( 'QR code that opens ' . $url ) . '"></div></details>';
	}

	/** A row with nothing to edit here: photo, name, one line and its buttons. */
	private static function plain_row( $photo, $name, $sub, $go, $facts = '' ) {
		echo '<li class="sh-row scc-row is-plain"><div class="scc-who">';
		if ( $photo ) {
			echo '<img class="scc-avatar" src="' . esc_url( $photo ) . '" alt="">';
		} else {
			$letter = function_exists( 'mb_substr' ) ? mb_substr( trim( (string) $name ), 0, 1 ) : substr( trim( (string) $name ), 0, 1 );
			echo '<span class="scc-avatar" aria-hidden="true">' . esc_html( strtoupper( $letter ) ) . '</span>';
		}
		echo '<div><p class="sh-row-name">' . esc_html( $name ) . '</p><p class="sh-row-sub">' . esc_html( $sub ) . '</p></div></div>';
		echo '<div class="sh-row-facts">' . $facts . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller.
		echo '<div class="sh-row-go">' . $go . '</div></li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller.
	}

	/** The People edit screen for a person, as a quiet button. */
	private static function edit_in_people( $profile_id ) {
		$url = get_edit_post_link( (int) $profile_id );
		return $url ? ' <a class="button sh-quiet" href="' . esc_url( $url ) . '">Edit in People</a>' : '';
	}

	/** Which profile fields show on a card: one checkbox each. */
	private static function show_fields( $base, array $show ) {
		$labels = array(
			'phone' => 'Phone',
			'email' => 'Email',
			'intro' => 'Intro',
			'tags'  => 'Tags',
			'links' => 'Profile links',
			'bio'   => 'Short bio',
			'save'  => 'Save contact',
		);
		echo '<tr><th scope="row">Show on the card</th><td><fieldset><legend class="screen-reader-text">Show on the card</legend>';
		foreach ( $labels as $k => $label ) {
			echo '<label class="scc-show"><input type="hidden" name="' . esc_attr( "{$base}[show_{$k}]" ) . '" value="0"><input type="checkbox" name="' . esc_attr( "{$base}[show_{$k}]" ) . '" value="1"' . checked( ! empty( $show[ $k ] ), true, false ) . '> ' . esc_html( $label ) . '</label> ';
		}
		echo '<p class="description">Each comes from their People profile, and a blank card phone or email uses the business one.</p></fieldset></td></tr>';
	}

	/**
	 * A main card value that comes from Business info: what it uses now, then a
	 * closed "Use something else" fold with the override field (open when one is
	 * saved). An empty override means "use Business info". Without Scout Core it
	 * is a plain field, as before.
	 *
	 * @param string $name  Field name.
	 * @param string $label Row label.
	 * @param string $key   The settings key.
	 * @param string $type  text, email or textarea.
	 * @param array  $s     Settings (saved over the defaults).
	 */
	private static function from_business( $name, $label, $key, $type, array $s ) {
		if ( ! self::has_core() ) {
			self::field( $name, (string) $s[ $key ], $label, $type );
			return;
		}
		$from = (string) Scout_Cards_Settings::defaults()[ $key ];
		$own  = self::override( $key );
		$id   = 'scc_' . md5( $name );
		$src  = 'name' === $key && '' === Scout_Cards_Settings::business()['name'] ? 'From the site title: ' : 'From Business info: ';
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<p class="scc-from">' . ( '' !== $from ? esc_html( $src ) . '<strong>' . esc_html( $from ) . '</strong>' : 'Business info has none yet.' ) . '</p>';
		echo '<details class="scc-else"' . ( '' !== $own ? ' open' : '' ) . '><summary>Use something else</summary>';
		echo '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ' on the card</label>';
		if ( 'textarea' === $type ) {
			echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $own ) . '</textarea>';
		} else {
			echo '<input class="regular-text" type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $own ) . '">';
		}
		echo '<p class="description">Leave it empty to use Business info.</p></details></td></tr>';
	}

	/** Is Scout Core's Business info there to read? */
	private static function has_core() {
		return class_exists( 'Scout_Core_Business' ) || function_exists( 'scout_core_business' );
	}

	/**
	 * A saved main card override, or '' when it is empty or the same as Business
	 * info (an older save copied Business info in; it shows as "From Business info").
	 *
	 * @param string $key The settings key.
	 */
	private static function override( $key ) {
		$raw = get_option( Scout_Cards_Settings::OPTION );
		$own = is_array( $raw ) ? trim( (string) ( $raw[ $key ] ?? '' ) ) : '';
		return trim( (string) Scout_Cards_Settings::defaults()[ $key ] ) === $own ? '' : $own;
	}

	/** The main card's logo or photo: what it uses now, then its pickers under "Use something else". */
	private static function team_photo( $n, array $s ) {
		if ( Scout_Cards_Settings::business()['logo'] ) {
			$from = 'From Business info: the logo.';
		} elseif ( get_site_icon_url( 256 ) ) {
			$from = 'Uses the Site Icon.';
		} else {
			$from = 'Shows the first letter of the name.';
		}
		$own = (int) $s['photo_id'] || (int) $s['logo_id'];
		echo '<tr><th scope="row">Logo or photo</th><td><p class="scc-from">' . esc_html( $from ) . '</p>';
		echo '<details class="scc-else"' . ( $own ? ' open' : '' ) . '><summary>Use something else</summary><table class="form-table" role="presentation">';
		self::photo( "{$n}[photo_id]", (int) $s['photo_id'], 'On the card', 'Empty uses the one above.' );
		self::photo( "{$n}[logo_id]", (int) $s['logo_id'], 'Links & share pages', 'Optional. A logo for the links page and share page.' );
		echo '</table></details></td></tr>';
	}

	/**
	 * The main card's fields when cards read from People. Name, email and logo come
	 * from Business info, each with "Use something else". So does the intro when
	 * Business info has a description; otherwise the intro is a card field.
	 */
	private static function team_fields( $n, array $s ) {
		self::from_business( "{$n}[name]", 'Name', 'name', 'text', $s );
		self::from_business( "{$n}[email]", 'Email', 'email', 'email', $s );
		self::team_photo( $n, $s );
		if ( self::has_core() && '' !== Scout_Cards_Settings::business()['description'] ) {
			self::from_business( "{$n}[team_intro]", 'Intro', 'team_intro', 'textarea', $s );
		} else {
			self::field( "{$n}[team_intro]", $s['team_intro'], 'Intro', 'textarea' );
		}
	}

	/** The main card's extras: the heading above the people and the Spotlight. */
	private static function team_extras( $n, array $s ) {
		self::field( "{$n}[pick_heading]", $s['pick_heading'], 'Heading above the people' );
		self::spot_fields( $n, $s );
	}

	/**
	 * The people box when cards read from People: the main card, then one row for
	 * each person with Card on. What a card says is edited in People (its Business
	 * card box); here each card has its switches, link name and extras only.
	 */
	private static function people_box( $n, array $s, array $people ) {
		echo '<section class="sh-box"><div class="scc-boxhead"><h2 class="sh-title">People with a card</h2>';
		echo '<a class="button" href="' . esc_url( Scout_Cards_People::add_url() ) . '">Add a person</a></div>';
		echo '<p>Anyone in People with Card on gets a card, with the words and contact from their profile.</p>';
		echo '<input type="hidden" name="' . esc_attr( $n ) . '[people_mode]" value="people">';
		echo '<ul class="sh-rows">';
		$team = $people['team'];
		self::person_row(
			'scc-person-main',
			(string) $team['photo'],
			(string) $s['name'],
			'Main card. It shows everyone below.',
			$team,
			'Edit',
			'',
			static function () use ( $n, $s ) {
				self::team_fields( $n, $s );
			},
			'',
			static function () use ( $n, $s ) {
				self::team_extras( $n, $s );
			},
			'Extras'
		);

		// Saved card settings by link name, the same way people() reads them.
		$list = array_values( (array) $s['people'] );
		$recs = array();
		foreach ( $list as $i => $rec ) {
			$slug = sanitize_title( (string) ( $rec['slug'] ?? '' ) );
			if ( '' !== $slug ) {
				$recs[ $slug ] = (int) $i;
			}
		}
		$i    = 0;
		$live = array();
		foreach ( $people as $key => $card ) {
			if ( 'team' === $key ) {
				continue;
			}
			$idx = isset( $recs[ $key ] ) ? $recs[ $key ] : -1;
			$rec = $idx >= 0 ? (array) $list[ $idx ] : array();
			if ( empty( $card['profile'] ) ) {
				// A card whose profile is gone: it keeps showing its own saved details.
				$base = "{$n}[people][$i]";
				$keep = '<input type="hidden" name="' . esc_attr( "{$base}[rec]" ) . '" value="' . esc_attr( $idx ) . '"><input type="hidden" name="' . esc_attr( "{$base}[profile_id]" ) . '" value="0">'
					. '<label class="scc-remove"><input type="checkbox" name="' . esc_attr( "{$base}[remove]" ) . '" value="1"> Take this card off</label>';
				$facts = '<a href="' . esc_url( $card['url'] ) . '" target="_blank" rel="noopener"><code>' . esc_html( (string) wp_parse_url( $card['url'], PHP_URL_PATH ) ) . '</code></a>';
				self::plain_row( (string) $card['photo'], (string) $card['name'], 'Not in People. This card uses the details it had saved.', $keep, $facts );
				++$i;
				continue;
			}
			$live[] = (int) $card['profile'];
			$pid    = (int) $card['profile'];
			if ( $idx >= 0 && (int) ( $rec['profile_id'] ?? 0 ) !== $pid ) {
				$idx = -1;
				$rec = array();
			}
			$base = "{$n}[people][$i]";
			self::person_row(
				'scc-person-' . $i,
				(string) $card['photo'],
				(string) $card['name'],
				(string) $card['title'],
				$card,
				'Card settings',
				'',
				static function () use ( $base, $s, $idx, $pid, $key, $card ) {
					echo '<tr hidden><td colspan="2"><input type="hidden" name="' . esc_attr( "{$base}[rec]" ) . '" value="' . esc_attr( $idx ) . '"><input type="hidden" name="' . esc_attr( "{$base}[profile_id]" ) . '" value="' . esc_attr( $pid ) . '"><input type="hidden" name="' . esc_attr( "{$base}[key]" ) . '" value="' . esc_attr( $key ) . '"></td></tr>';
					self::field( "{$base}[slug]", $key, 'Link name', 'text', 'Lowercase, no spaces. "alex" makes /' . $s['card_base'] . '/alex/.' );
					self::show_fields( $base, $card['show'] );
				},
				self::edit_in_people( $pid ),
				static function () use ( $base, $rec ) {
					self::photo( "{$base}[share_id]", (int) ( $rec['share_id'] ?? 0 ), 'Share picture', 'Optional. Shows when this card\'s link is texted or posted. 1200 by 630 pixels.' );
					self::spot_fields( $base, $rec );
				}
			);
			++$i;
		}

		// People with Card on who are not live yet: no card until they are.
		foreach ( Scout_Cards_People::available() ? array_map( 'intval', (array) Scout_Team::card_ids( false ) ) : array() as $pid ) {
			if ( in_array( $pid, $live, true ) ) {
				continue;
			}
			$c = Scout_Cards_People::profile( $pid );
			if ( ! $c ) {
				continue;
			}
			$photo = $c['photo_id'] ? (string) wp_get_attachment_image_url( (int) $c['photo_id'], 'thumbnail' ) : '';
			self::plain_row( $photo, '' !== $c['name'] ? $c['name'] : '(no name)', 'Not live in People yet, so there is no card.', self::edit_in_people( $pid ) );
		}
		echo '</ul>';
		if ( 1 === count( $people ) ) {
			echo '<p class="sh-note">No one has a card yet. Add a person, or turn on Card for someone in People.</p>';
		}
		echo '</section>';
	}

	/**
	 * The people box when cards keep their own details: Scout People is off, or
	 * its one-time move has not run yet. The same fields as before People.
	 */
	private static function legacy_box( $n, array $s, array $people ) {
		echo '<section class="sh-box"><h2 class="sh-title">Card people</h2>';
		if ( ! Scout_Cards_People::available() ) {
			echo '<p class="sh-note">Turn on Scout People to keep one profile per person. Cards then read names, photos and contact details from it.</p>';
		}
		echo '<p>Each person gets their own card and shows on the main card. Share mode opens a big QR code: open it on your phone and add it to your home screen (iPhone: Share, then Add to Home Screen), so it opens like an app.</p>';
		echo '<ul class="sh-rows">';
		$team = $people['team'];
		self::person_row(
			'scc-person-main',
			(string) $team['photo'],
			(string) $s['name'],
			'Main card. It shows everyone below.',
			$team,
			'Edit',
			'',
			static function () use ( $n, $s ) {
				self::photo( "{$n}[photo_id]", (int) $s['photo_id'], 'Logo or photo', 'Blank uses the Site Icon, or the first letter of the name.' );
				self::photo( "{$n}[logo_id]", (int) $s['logo_id'], 'Logo for the links and share pages', 'Optional. When the card shows a person, the links page and share page can show the logo instead.' );
				self::spot_fields( $n, $s );
			}
		);
		$spare = false;
		for ( $i = 0; $i < Scout_Cards_Settings::MAX_PEOPLE; $i++ ) {
			$p      = $s['people'][ $i ] ?? array( 'slug' => '', 'name' => '', 'title' => '', 'email' => '', 'intro' => '', 'pick' => '', 'tags' => '', 'photo_id' => 0 );
			$filled = '' !== trim( (string) $p['name'] );
			$key    = sanitize_title( (string) ( $p['slug'] ?? '' ) );
			$class  = '';
			if ( ! $filled ) {
				$class = 'scc-spare' . ( $spare ? '' : ' is-shown' ); // One empty slot shows; Add a person reveals the next.
				$spare = true;
			}
			$photo = $filled && ! empty( $p['photo_id'] ) ? (string) wp_get_attachment_image_url( (int) $p['photo_id'], 'thumbnail' ) : '';
			self::person_row(
				'scc-person-' . $i,
				$photo,
				$filled ? (string) $p['name'] : 'Add a person',
				$filled ? (string) ( $p['title'] ?? '' ) : 'Fill in a name to give someone their own card.',
				$filled && isset( $people[ $key ] ) ? $people[ $key ] : array(),
				$filled ? 'Edit' : 'Add a person',
				$class,
				static function () use ( $n, $s, $p, $i, $filled ) {
					self::field( "{$n}[people][$i][name]", $p['name'], 'Full name', 'text', $filled ? 'To take this person off, clear the name and save.' : '' );
					self::field( "{$n}[people][$i][slug]", $p['slug'], 'Link name', 'text', 'Lowercase, no spaces. "alex" makes /' . $s['card_base'] . '/alex/. Blank uses the first name.' );
					self::field( "{$n}[people][$i][title]", $p['title'], 'Title' );
					self::field( "{$n}[people][$i][email]", $p['email'], 'Email', 'email' );
					self::field( "{$n}[people][$i][intro]", $p['intro'], 'Intro (on their card, in their voice)', 'textarea' );
					self::field( "{$n}[people][$i][pick]", $p['pick'], 'Why talk to them (on the main card)', 'textarea' );
					self::field( "{$n}[people][$i][tags]", $p['tags'], 'Tags', 'text', 'Comma separated, two to four short topics.' );
					self::photo( "{$n}[people][$i][photo_id]", (int) $p['photo_id'], 'Photo', 'A square headshot works best.' );
					self::photo( "{$n}[people][$i][share_id]", (int) ( $p['share_id'] ?? 0 ), 'Share picture', 'Optional. Shows when this person\'s card link is texted or posted. 1200 by 630 pixels.' );
					self::spot_fields( "{$n}[people][$i]", $p );
					// Kept as they are through a save: the People profile this card belongs to, and its switches.
					echo '<tr hidden><td colspan="2"><input type="hidden" name="' . esc_attr( "{$n}[people][$i][profile_id]" ) . '" value="' . esc_attr( (int) ( $p['profile_id'] ?? 0 ) ) . '">';
					foreach ( Scout_Cards_People::show( $p ) as $k => $on ) {
						echo '<input type="hidden" name="' . esc_attr( "{$n}[people][$i][show_{$k}]" ) . '" value="' . esc_attr( $on ) . '">';
					}
					echo '</td></tr>';
				}
			);
		}
		echo '</ul></section>';
	}

	public static function render() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		$s      = Scout_Cards_Settings::get();
		$n      = Scout_Cards_Settings::OPTION;
		$people = Scout_Cards_Settings::people();
		$count  = count( $people ) - 1; // 'team' is the main card, not a person.
		$card   = Scout_Cards_Settings::card_url();
		$links  = home_url( '/' . $s['links_base'] . '/' );
		Scout_Admin_UI::open( 'Cards & links', 'A digital business card for each person, a QR code to share it, and a link page for your social bios.' );
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>Saved. The card and links pages are updated. Clear the site cache if you do not see the change.</p></div>';
		}
		if ( isset( $_GET['reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-info is-dismissible"><p>Reset to the defaults.</p></div>';
		}
		foreach ( array( $s['card_base'], $s['links_base'] ) as $slug ) {
			if ( get_page_by_path( $slug ) ) {
				echo '<div class="notice notice-warning"><p>A WordPress Page already uses <code>/' . esc_html( $slug ) . '/</code>. Scout Cards will show at that address instead of the Page. Change the address under More options, or rename that Page.</p></div>';
			}
		}
		if ( ! class_exists( 'Scout_Core_Business' ) ) {
			echo '<div class="notice notice-info"><p>Scout Core is off, so fill in the business details under More options.</p></div>';
		}
		$moved = Scout_Cards_People::take_note();
		if ( '' !== $moved ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $moved ) . '</p></div>';
		}

		// Status line: where the cards live, how many people, and whether the links page is on.
		$who = 0 === $count ? 'the main card only' : ( 1 === $count ? 'the main card and 1 person' : 'the main card and ' . $count . ' people' );
		if ( $s['enable_cards'] ) {
			self::status(
				'good',
				'Live.',
				'At /' . $s['card_base'] . '/ with ' . $who . '. The links page is ' . ( $s['enable_links'] ? 'on at /' . $s['links_base'] . '/.' : 'off.' ),
				'<a class="button" href="' . esc_url( $card ) . '" target="_blank" rel="noopener">View card</a>'
			);
		} elseif ( $s['enable_links'] ) {
			self::status(
				'off',
				'Cards off.',
				'The links page is on at /' . $s['links_base'] . '/. Turn cards on under More options.',
				'<a class="button" href="' . esc_url( $links ) . '" target="_blank" rel="noopener">View links</a>'
			);
		} else {
			self::status( 'off', 'Off.', 'Turn cards and links on under More options.' );
		}

		echo '<form method="post" class="scc-form" id="scc-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		// With JavaScript, the Edit panels and spare rows start closed; without it, every field shows.
		echo '<script>document.getElementById("scc-form").classList.add("scc-js");</script>';
		wp_nonce_field( 'scout_cards_save' );
		echo '<input type="hidden" name="action" value="scout_cards_save">';

		$reading = Scout_Cards_People::reading();
		if ( $reading ) {
			self::people_box( $n, $s, $people );
		} else {
			self::legacy_box( $n, $s, $people );
		}


		// Links page: the intro, the newest post switch, then one row per link.
		echo '<section class="sh-box"><div class="scc-boxhead"><h2 class="sh-title">Links page</h2>';
		echo '<a class="button sh-quiet" href="' . esc_url( $links ) . '" target="_blank" rel="noopener">View links</a></div>';
		echo '<p>Your "link in bio" page. Rows show in this order, and blank rows are skipped.</p>';
		echo '<table class="form-table" role="presentation">';
		self::field( "{$n}[links_intro]", $s['links_intro'], 'Intro', 'textarea' );
		echo '<tr><th scope="row">Newest blog post</th><td><label><input type="hidden" name="' . esc_attr( $n ) . '[links_latest]" value="0"><input type="checkbox" name="' . esc_attr( $n ) . '[links_latest]" value="1"' . checked( $s['links_latest'], 1, false ) . '> Show the newest post first (updates by itself)</label></td></tr>';
		echo '</table><ol class="sh-rows scc-links">';
		$spares = 0;
		for ( $i = 0; $i < Scout_Cards_Settings::MAX_LINKS; $i++ ) {
			$l     = $s['links'][ $i ] ?? array( 'label' => '', 'sub' => '', 'url' => '', 'external' => 0 );
			$class = '';
			if ( '' === trim( (string) $l['label'] ) && '' === trim( (string) $l['url'] ) ) {
				$class = ' scc-spare' . ( $spares ? '' : ' is-shown' ); // One empty row shows; Add another link reveals the next.
				++$spares;
			}
			$id = 'scc-link-' . $i;
			echo '<li class="sh-row scc-link' . esc_attr( $class ) . '"><fieldset><legend class="scc-legend">Link ' . ( $i + 1 ) . '</legend><div class="scc-link-fields">';
			echo '<p><label for="' . esc_attr( $id ) . '-label">Button text</label><input class="widefat" id="' . esc_attr( $id ) . '-label" name="' . esc_attr( "{$n}[links][$i][label]" ) . '" value="' . esc_attr( $l['label'] ) . '"></p>';
			echo '<p><label for="' . esc_attr( $id ) . '-sub">Small line under it</label><input class="widefat" id="' . esc_attr( $id ) . '-sub" name="' . esc_attr( "{$n}[links][$i][sub]" ) . '" value="' . esc_attr( $l['sub'] ) . '"></p>';
			echo '<p><label for="' . esc_attr( $id ) . '-url">Link</label><input class="widefat" id="' . esc_attr( $id ) . '-url" name="' . esc_attr( "{$n}[links][$i][url]" ) . '" value="' . esc_attr( $l['url'] ) . '" placeholder="/contact/ or https://..."><span class="description">Start with / for a page on this site.</span></p>';
			echo '<p class="scc-check"><label><input type="checkbox" name="' . esc_attr( "{$n}[links][$i][external]" ) . '" value="1"' . checked( ! empty( $l['external'] ), true, false ) . '> Open in a new tab</label></p>';
			echo '</div></fieldset></li>';
		}
		echo '</ol><p class="scc-more"><button type="button" class="button sh-quiet scc-js-only" data-scc-more=".scc-link"' . ( $spares > 1 ? '' : ' hidden' ) . '>Add another link</button></p></section>';

		// Rarely changed: look, main card words, business detail overrides, addresses.
		self::fold_open( 'More options' );
		echo '<h3 class="scc-sub">Look</h3><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">Mode</th><td><label><input type="radio" name="' . esc_attr( $n ) . '[mode]" value="dark"' . checked( $s['mode'], 'dark', false ) . '> Dark</label> &nbsp; <label><input type="radio" name="' . esc_attr( $n ) . '[mode]" value="light"' . checked( $s['mode'], 'light', false ) . '> Light</label></td></tr>';
		echo '<tr><th scope="row"><label for="scc-accent">Accent color</label></th><td><input type="color" id="scc-accent" name="' . esc_attr( $n ) . '[accent]" value="' . esc_attr( $s['accent'] ) . '"><p class="description">The brand color for buttons, links, and tags on the card.</p></td></tr>';
		self::field( "{$n}[font]", $s['font'], 'Font (optional)', 'text', 'Leave blank to use the site\'s own font. Or a CSS font list, e.g. "Hanken Grotesk", sans-serif.' );
		self::photo( "{$n}[share_card_id]", (int) $s['share_card_id'], 'Share picture: card', 'Shows when someone texts or posts the card link. 1200 by 630 pixels.' );
		self::photo( "{$n}[share_links_id]", (int) $s['share_links_id'], 'Share picture: links page', 'Shows when the links page is shared. Blank uses the card\'s.' );
		echo '</table>';

		if ( ! $reading ) {
			echo '<h3 class="scc-sub">Main card words</h3><table class="form-table" role="presentation">';
			self::field( "{$n}[team_intro]", $s['team_intro'], 'Intro', 'textarea' );
			self::field( "{$n}[pick_heading]", $s['pick_heading'], 'Heading above the people' );
			echo '</table>';
		}

		echo '<h3 class="scc-sub">Business details for the cards</h3>';
		if ( class_exists( 'Scout_Core_Business' ) ) {
			echo '<p class="sh-note">These override your Business info, on the cards and links page only. Leave a field blank to use Business info.</p>';
		} else {
			echo '<p class="sh-note">Scout Core is not active, so the cards use only what you type here.</p>';
		}
		echo '<table class="form-table" role="presentation">';
		if ( ! $reading ) {
			self::field( "{$n}[name]", $s['name'], 'Business name' );
		}
		self::field( "{$n}[phone]", $s['phone'], 'Phone', 'text', 'Used for Call, Text, and the saved contact.' );
		if ( ! $reading ) {
			self::field( "{$n}[email]", $s['email'], 'Main email', 'email' );
		}
		self::field( "{$n}[city]", $s['city'], 'City' );
		self::field( "{$n}[region]", $s['region'], 'State' );
		self::field( "{$n}[postal]", $s['postal'], 'ZIP' );
		self::field( "{$n}[socials]", $s['socials'], 'Social profiles', 'textarea', 'One URL per line. Facebook, Instagram, LinkedIn, X, Threads, YouTube, TikTok, and Pinterest show as icons.' );
		self::field( "{$n}[footer]", $s['footer'], 'Footer line', 'text', 'Shown at the bottom of every card and the links page.' );
		self::field( "{$n}[note]", $s['note'], 'Contact note', 'textarea', 'Saved in the Notes field of the phone contact.' );
		echo '</table>';

		echo '<h3 class="scc-sub">Addresses</h3><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">Turn on</th><td><label><input type="hidden" name="' . esc_attr( $n ) . '[enable_cards]" value="0"><input type="checkbox" name="' . esc_attr( $n ) . '[enable_cards]" value="1"' . checked( $s['enable_cards'], 1, false ) . '> Business cards</label> &nbsp; <label><input type="hidden" name="' . esc_attr( $n ) . '[enable_links]" value="0"><input type="checkbox" name="' . esc_attr( $n ) . '[enable_links]" value="1"' . checked( $s['enable_links'], 1, false ) . '> Links page</label></td></tr>';
		self::field( "{$n}[card_base]", $s['card_base'], 'Card address', 'text', 'The word after your domain. Default: card.' );
		self::field( "{$n}[links_base]", $s['links_base'], 'Links address', 'text', 'Default: links.' );
		echo '</table>';
		echo '<p class="scc-reset"><button type="submit" form="scc-reset" class="button sh-quiet sh-danger">Reset everything to the defaults</button></p>';
		self::fold_close();

		submit_button( 'Save cards and links' );
		echo '</form>';
		// Its button sits under More options, tied to this form by its id.
		echo '<form method="post" id="scc-reset" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Reset every field to the defaults?\');">';
		wp_nonce_field( 'scout_cards_reset' );
		echo '<input type="hidden" name="action" value="scout_cards_reset"></form>';
		?>
		<script>
		jQuery(function($){
		  $('.scc-pick').on('click',function(e){e.preventDefault();var td=$(this).closest('.scc-photo-field');
		    var f=wp.media({title:'Choose a photo',library:{type:'image'},multiple:false});
		    f.on('select',function(){var a=f.state().get('selection').first().toJSON();
		      td.find('input[type=hidden]').val(a.id);td.find('img').attr('src',(a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail.url:a.url)).prop('hidden',false);});
		    f.open();});
		  $('.scc-clear').on('click',function(e){e.preventDefault();var td=$(this).closest('.scc-photo-field');td.find('input[type=hidden]').val('');td.find('img').prop('hidden',true);});
		});
		(function(){
		  var form=document.getElementById('scc-form');
		  if(!form){return;}
		  function nextSpare(row){
		    var n=row.nextElementSibling;
		    while(n&&!n.classList.contains('scc-spare')){n=n.nextElementSibling;}
		    return n;
		  }
		  function toggle(btn,open){
		    var row=btn.closest('.scc-row');
		    row.classList.toggle('is-open',open);
		    btn.setAttribute('aria-expanded',open?'true':'false');
		    if(open&&row.classList.contains('scc-spare')){
		      var n=nextSpare(row);if(n){n.classList.add('is-shown');}
		    }
		  }
		  form.addEventListener('click',function(e){
		    var edit=e.target.closest('.scc-edit');
		    if(edit){toggle(edit,edit.getAttribute('aria-expanded')!=='true');if(edit.getAttribute('aria-expanded')==='true'){var all=document.getElementById(edit.getAttribute('aria-controls')).querySelectorAll('input:not([type=hidden]),textarea,summary,button');for(var k=0;k<all.length;k++){if(all[k].offsetParent!==null){all[k].focus();break;}}}return;}
		    var more=e.target.closest('[data-scc-more]');
		    if(more){
		      var hidden=form.querySelector(more.getAttribute('data-scc-more')+'.scc-spare:not(.is-shown)');
		      if(hidden){hidden.classList.add('is-shown');var i=hidden.querySelector('input');if(i){i.focus();}}
		      if(!form.querySelector(more.getAttribute('data-scc-more')+'.scc-spare:not(.is-shown)')){more.hidden=true;}
		    }
		  });
		  // A card's QR code is drawn when its box opens.
		  form.addEventListener('toggle',function(e){
		    var d=e.target;if(!d.classList||!d.classList.contains('scc-qr')){return;}
		    var box=d.querySelector('[data-qr-url]');if(!box||box.firstChild||!window.qrcode){return;}
		    var q=qrcode(0,'M');q.addData(box.getAttribute('data-qr-url'));q.make();box.innerHTML=q.createSvgTag({cellSize:6,margin:2,scalable:true});
		  },true);
		  // A field the browser flags (a bad email, say) may sit in a closed panel or More options: open it first.
		  form.addEventListener('invalid',function(e){
		    var el=e.target;
		    var row=el.closest('.scc-row');
		    if(row){row.classList.add('is-open','is-shown');var b=row.querySelector('.scc-edit');if(b){b.setAttribute('aria-expanded','true');}}
		    var d=el.closest('details');if(d){d.open=true;}
		    var l=el.closest('.scc-link');if(l){l.classList.add('is-shown');}
		  },true);
		})();
		</script>
		<?php
		Scout_Admin_UI::close();
	}

	/** The card's "Show on the card" switches from a submitted card, when the form sent them. */
	private static function clean_show( $v ) {
		$out = array();
		foreach ( array_keys( Scout_Cards_People::SHOW ) as $k ) {
			if ( isset( $v[ 'show_' . $k ] ) ) {
				$out[ 'show_' . $k ] = empty( $v[ 'show_' . $k ] ) ? 0 : 1;
			}
		}
		return $out;
	}

	/**
	 * Cards that read from People: each submitted card updates its own settings on
	 * its saved record (found by its People profile), or adds a record for someone
	 * new in People. A saved record keeps the name, title, email and photo it had,
	 * so the card can fall back to them. Records not on the form stay as they are.
	 *
	 * @param array $rows Submitted cards.
	 */
	private static function save_people( array $rows ) {
		$raw  = get_option( Scout_Cards_Settings::OPTION );
		$recs = is_array( $raw ) && is_array( $raw['people'] ?? null ) ? array_values( $raw['people'] ) : array();
		$drop = array();
		foreach ( $rows as $row ) {
			$row = (array) $row;
			$pid = absint( $row['profile_id'] ?? 0 );
			$idx = -1;
			if ( $pid ) {
				foreach ( $recs as $j => $r ) {
					if ( (int) ( $r['profile_id'] ?? 0 ) === $pid ) {
						$idx = $j;
						break;
					}
				}
			} else {
				$j = isset( $row['rec'] ) ? (int) $row['rec'] : -1;
				if ( $j >= 0 && isset( $recs[ $j ] ) && empty( $recs[ $j ]['profile_id'] ) ) {
					$idx = $j;
				}
			}
			if ( ! empty( $row['remove'] ) ) {
				if ( $idx >= 0 ) {
					$drop[] = $idx;
				}
				continue;
			}
			if ( $idx < 0 && ! $pid ) {
				continue;
			}
			if ( ! isset( $row['slug'] ) ) {
				continue; // Nothing to change on this card.
			}
			$old  = $idx >= 0 ? $recs[ $idx ] : array( 'profile_id' => $pid );
			$slug = sanitize_title( (string) $row['slug'] );
			if ( '' === $slug ) {
				$slug = sanitize_title( (string) ( $old['slug'] ?? ( $row['key'] ?? '' ) ) );
			}
			// The card's intro, why and tags live in People now. The record keeps its own
			// copy for the fallback, changed only when a form sends them.
			$words = array();
			foreach ( array( 'intro', 'pick' ) as $k ) {
				if ( isset( $row[ $k ] ) ) {
					$words[ $k ] = sanitize_textarea_field( (string) $row[ $k ] );
				}
			}
			if ( isset( $row['tags'] ) ) {
				$words['tags'] = sanitize_text_field( (string) $row['tags'] );
			}
			$new = array_merge(
				$old,
				array(
					'slug'     => $slug,
					'share_id' => absint( $row['share_id'] ?? 0 ),
				),
				$words,
				self::clean_spot( $row ),
				self::clean_show( $row )
			);
			if ( $idx >= 0 ) {
				$recs[ $idx ] = $new;
			} else {
				$recs[] = $new;
			}
		}
		foreach ( $drop as $j ) {
			unset( $recs[ $j ] );
		}
		return array_values( $recs );
	}

	public static function save() {
		if ( ! current_user_can( 'edit_pages' ) || ! check_admin_referer( 'scout_cards_save' ) ) {
			wp_die( 'Not allowed.' );
		}
		$in   = (array) wp_unslash( $_POST[ Scout_Cards_Settings::OPTION ] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field below.
		$txt  = function ( $v ) {
			return sanitize_text_field( (string) $v );
		};
		$area = function ( $v ) {
			return sanitize_textarea_field( (string) $v );
		};
		$out  = array();
		foreach ( array( 'name', 'phone', 'city', 'region', 'postal', 'footer', 'font', 'pick_heading' ) as $k ) {
			$out[ $k ] = $txt( $in[ $k ] ?? '' );
		}
		foreach ( array( 'note', 'team_intro', 'links_intro', 'socials' ) as $k ) {
			$out[ $k ] = $area( $in[ $k ] ?? '' );
		}
		$out['email']        = sanitize_email( $in['email'] ?? '' );
		$out['mode']         = 'light' === ( $in['mode'] ?? '' ) ? 'light' : 'dark';
		$out['accent']       = sanitize_hex_color( $in['accent'] ?? '' ) ? sanitize_hex_color( $in['accent'] ) : '#0E8FE6';
		$out['photo_id']     = absint( $in['photo_id'] ?? 0 );
		$out['logo_id']        = absint( $in['logo_id'] ?? 0 );
		$out['share_card_id']  = absint( $in['share_card_id'] ?? 0 );
		$out['share_links_id'] = absint( $in['share_links_id'] ?? 0 );
		$out                += self::clean_spot( $in );
		$out['links_latest'] = empty( $in['links_latest'] ) ? 0 : 1;
		$out['enable_cards'] = empty( $in['enable_cards'] ) ? 0 : 1;
		$out['enable_links'] = empty( $in['enable_links'] ) ? 0 : 1;
		$out['card_base']    = sanitize_title( $in['card_base'] ?? '' ) ? sanitize_title( $in['card_base'] ) : 'card';
		$out['links_base']   = sanitize_title( $in['links_base'] ?? '' ) ? sanitize_title( $in['links_base'] ) : 'links';
		if ( 'people' === ( $in['people_mode'] ?? '' ) ) {
			$out['people'] = self::save_people( (array) ( $in['people'] ?? array() ) );
		} else {
			$out['people'] = array();
			foreach ( (array) ( $in['people'] ?? array() ) as $p ) {
				if ( '' === trim( (string) ( $p['name'] ?? '' ) ) ) {
					continue;
				}
				$first = preg_split( '/\s+/', trim( $p['name'] ) )[0];
				$rec   = array(
					'name'     => $txt( $p['name'] ),
					'slug'     => sanitize_title( ! empty( $p['slug'] ) ? $p['slug'] : $first ),
					'title'    => $txt( $p['title'] ?? '' ),
					'email'    => sanitize_email( $p['email'] ?? '' ),
					'intro'    => $area( $p['intro'] ?? '' ),
					'pick'     => $area( $p['pick'] ?? '' ),
					'tags'     => $txt( $p['tags'] ?? '' ),
					'photo_id' => absint( $p['photo_id'] ?? 0 ),
					'share_id' => absint( $p['share_id'] ?? 0 ),
				) + self::clean_spot( $p );
				if ( ! empty( $p['profile_id'] ) ) {
					$rec['profile_id'] = absint( $p['profile_id'] );
				}
				$out['people'][] = $rec + self::clean_show( $p );
			}
		}
		$out['links'] = array();
		foreach ( (array) ( $in['links'] ?? array() ) as $l ) {
			if ( '' === trim( (string) ( $l['label'] ?? '' ) ) || '' === trim( (string) ( $l['url'] ?? '' ) ) ) {
				continue;
			}
			$out['links'][] = array(
				'label'    => $txt( $l['label'] ),
				'sub'      => $txt( $l['sub'] ?? '' ),
				'url'      => esc_url_raw( trim( $l['url'] ) ),
				'external' => empty( $l['external'] ) ? 0 : 1,
			);
		}
		update_option( Scout_Cards_Settings::OPTION, $out, false );
		Scout_Cards_Settings::flush();
		wp_safe_redirect( self::url( array( 'saved' => 1 ) ) );
		exit;
	}

	public static function reset() {
		if ( ! current_user_can( 'edit_pages' ) || ! check_admin_referer( 'scout_cards_reset' ) ) {
			wp_die( 'Not allowed.' );
		}
		delete_option( Scout_Cards_Settings::OPTION );
		wp_safe_redirect( self::url( array( 'reset' => 1 ) ) );
		exit;
	}
}
