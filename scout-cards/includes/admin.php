<?php
/**
 * Dashboard screen: Scout -> Cards & Links (inside Scout Core's menu), or a
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

	/** Parent menu: Scout Core's "scout" section when present, else our own "Site Build". */
	public static function parent() {
		global $admin_page_hooks;
		return isset( $admin_page_hooks['scout'] ) ? 'scout' : 'site-build';
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function menu() {
		$parent = self::parent();
		if ( 'site-build' === $parent ) {
			add_menu_page( 'Site Build', 'Site Build', 'edit_pages', 'site-build', array( __CLASS__, 'render' ), 'dashicons-id-alt', 3 );
			add_submenu_page( 'site-build', 'Cards & Links', 'Cards & Links', 'edit_pages', 'site-build', array( __CLASS__, 'render' ) );
		}
		add_submenu_page( $parent, 'Cards & Links', 'Cards & Links', 'edit_pages', self::SLUG, array( __CLASS__, 'render' ) );
		if ( 'site-build' === $parent ) {
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
		}
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
			. '<img src="' . esc_url( (string) $src ) . '" style="width:64px;height:64px;border-radius:50%;object-fit:cover;vertical-align:middle;' . ( $src ? '' : 'display:none;' ) . '" alt=""> '
			. '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $photo_id ? $photo_id : '' ) . '">'
			. '<button type="button" class="button scc-pick">Choose photo</button> <button type="button" class="button-link scc-clear">Remove</button>'
			. '<p class="description">' . esc_html( $help ) . '</p></td></tr>';
	}

	/** Spotlight fields: an optional block under the card. Leave all blank to hide it. */
	private static function spot_fields( $base, $v ) {
		echo '<tr><th scope="row" colspan="2" style="padding-bottom:0">Spotlight <span class="description" style="font-weight:400">(optional block under the card, such as a product or booking page)</span></th></tr>';
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

	public static function render() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		$s      = Scout_Cards_Settings::get();
		$n      = Scout_Cards_Settings::OPTION;
		$people = Scout_Cards_Settings::people();
		echo '<div class="wrap"><h1>Cards &amp; Links</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>Saved. The card and links pages are updated. Clear the site cache if you do not see the change.</p></div>';
		}
		if ( isset( $_GET['reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-info is-dismissible"><p>Reset to the defaults.</p></div>';
		}
		foreach ( array( $s['card_base'], $s['links_base'] ) as $slug ) {
			if ( get_page_by_path( $slug ) ) {
				echo '<div class="notice notice-warning"><p>A WordPress Page already uses <code>/' . esc_html( $slug ) . '/</code>. Scout Cards will show at that address instead of the Page. Change the address below, or rename that Page.</p></div>';
			}
		}
		if ( ! class_exists( 'Scout_Core_Business' ) ) {
			echo '<div class="notice notice-info"><p>Scout Core is not active, so fill in the business details below. With Scout Core, they come from its Business screen automatically.</p></div>';
		}

		echo '<p>Your digital business cards and your social "link in bio" page. ';
		echo '<a class="button" href="' . esc_url( Scout_Cards_Settings::card_url() ) . '" target="_blank">View card</a> ';
		echo '<a class="button" href="' . esc_url( home_url( '/' . $s['links_base'] . '/' ) ) . '" target="_blank">View links</a></p>';

		echo '<h2>Share mode links</h2><p>Open your link on your phone, then add it to your home screen (iPhone: Share, then Add to Home Screen). It opens like an app with a big QR code and share buttons.</p><ul>';
		foreach ( $people as $p ) {
			echo '<li><strong>' . esc_html( $p['name'] ) . ':</strong> <a href="' . esc_url( $p['share'] ) . '" target="_blank"><code>' . esc_html( $p['share'] ) . '</code></a></li>';
		}
		echo '</ul>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'scout_cards_save' );
		echo '<input type="hidden" name="action" value="scout_cards_save">';

		echo '<h2>Look</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">Mode</th><td><label><input type="radio" name="' . esc_attr( $n ) . '[mode]" value="dark"' . checked( $s['mode'], 'dark', false ) . '> Dark</label> &nbsp; <label><input type="radio" name="' . esc_attr( $n ) . '[mode]" value="light"' . checked( $s['mode'], 'light', false ) . '> Light</label></td></tr>';
		echo '<tr><th scope="row"><label for="scc-accent">Accent color</label></th><td><input type="color" id="scc-accent" name="' . esc_attr( $n ) . '[accent]" value="' . esc_attr( $s['accent'] ) . '"><p class="description">The brand color for buttons, links, and tags.</p></td></tr>';
		self::field( "{$n}[font]", $s['font'], 'Font (optional)', 'text', 'Leave blank to use the site\'s own font. Or a CSS font list, e.g. "Hanken Grotesk", sans-serif.' );
		echo '</table>';

		echo '<h2>Business details</h2><table class="form-table" role="presentation">';
		self::field( "{$n}[name]", $s['name'], 'Business name' );
		self::field( "{$n}[phone]", $s['phone'], 'Phone', 'text', 'Used for Call, Text, and the saved contact.' );
		self::field( "{$n}[email]", $s['email'], 'Main email', 'email' );
		self::field( "{$n}[city]", $s['city'], 'City' );
		self::field( "{$n}[region]", $s['region'], 'State' );
		self::field( "{$n}[postal]", $s['postal'], 'ZIP' );
		self::field( "{$n}[socials]", $s['socials'], 'Social profiles', 'textarea', 'One URL per line. Facebook, Instagram, LinkedIn, X, Threads, YouTube, TikTok, and Pinterest show as icons.' );
		self::field( "{$n}[footer]", $s['footer'], 'Footer line', 'text', 'Shown at the bottom of every card and the links page.' );
		self::field( "{$n}[note]", $s['note'], 'Contact note', 'textarea', 'Saved in the Notes field of the phone contact.' );
		echo '</table><p class="description">Blank fields use the details from Scout -> Business.</p>';

		echo '<h2>Main card</h2><table class="form-table" role="presentation">';
		self::field( "{$n}[team_intro]", $s['team_intro'], 'Intro', 'textarea' );
		self::field( "{$n}[pick_heading]", $s['pick_heading'], 'Heading above the people' );
		self::photo( "{$n}[photo_id]", (int) $s['photo_id'], 'Logo or photo', 'Blank uses the Site Icon, or the first letter of the name.' );
		self::spot_fields( $n, $s );
		echo '</table>';

		echo '<h2>People</h2><p>Each person gets their own card, and shows on the main card. Leave the name blank to hide a slot.</p>';
		for ( $i = 0; $i < Scout_Cards_Settings::MAX_PEOPLE; $i++ ) {
			$p = $s['people'][ $i ] ?? array( 'slug' => '', 'name' => '', 'title' => '', 'email' => '', 'intro' => '', 'pick' => '', 'tags' => '', 'photo_id' => 0 );
			echo '<details' . ( $p['name'] || 0 === $i ? ' open' : '' ) . '><summary style="cursor:pointer;font-weight:600;margin:10px 0">Person ' . ( $i + 1 ) . ( $p['name'] ? ': ' . esc_html( $p['name'] ) : '' ) . '</summary><table class="form-table" role="presentation">';
			self::field( "{$n}[people][$i][name]", $p['name'], 'Full name' );
			self::field( "{$n}[people][$i][slug]", $p['slug'], 'Link name', 'text', 'Lowercase, no spaces. "alex" makes /' . $s['card_base'] . '/alex/. Blank uses the first name.' );
			self::field( "{$n}[people][$i][title]", $p['title'], 'Title' );
			self::field( "{$n}[people][$i][email]", $p['email'], 'Email', 'email' );
			self::field( "{$n}[people][$i][intro]", $p['intro'], 'Intro (on their card, in their voice)', 'textarea' );
			self::field( "{$n}[people][$i][pick]", $p['pick'], 'Why talk to them (on the main card)', 'textarea' );
			self::field( "{$n}[people][$i][tags]", $p['tags'], 'Tags', 'text', 'Comma separated, two to four short topics.' );
			self::photo( "{$n}[people][$i][photo_id]", (int) $p['photo_id'], 'Photo', 'A square headshot works best.' );
			self::spot_fields( "{$n}[people][$i]", $p );
			echo '</table></details>';
		}

		echo '<h2>Links page</h2><table class="form-table" role="presentation">';
		self::field( "{$n}[links_intro]", $s['links_intro'], 'Intro', 'textarea' );
		echo '<tr><th scope="row">Newest blog post</th><td><label><input type="hidden" name="' . esc_attr( $n ) . '[links_latest]" value="0"><input type="checkbox" name="' . esc_attr( $n ) . '[links_latest]" value="1"' . checked( $s['links_latest'], 1, false ) . '> Show the newest post first (updates by itself)</label></td></tr>';
		echo '</table><table class="widefat striped" style="max-width:1100px"><thead><tr><th>Button text</th><th>Small line under it</th><th>Link (start with / for a page on this site)</th><th>New tab</th></tr></thead><tbody>';
		for ( $i = 0; $i < Scout_Cards_Settings::MAX_LINKS; $i++ ) {
			$l = $s['links'][ $i ] ?? array( 'label' => '', 'sub' => '', 'url' => '', 'external' => 0 );
			echo '<tr><td><input class="widefat" name="' . esc_attr( "{$n}[links][$i][label]" ) . '" value="' . esc_attr( $l['label'] ) . '"></td>'
				. '<td><input class="widefat" name="' . esc_attr( "{$n}[links][$i][sub]" ) . '" value="' . esc_attr( $l['sub'] ) . '"></td>'
				. '<td><input class="widefat" name="' . esc_attr( "{$n}[links][$i][url]" ) . '" value="' . esc_attr( $l['url'] ) . '" placeholder="/contact/ or https://..."></td>'
				. '<td><input type="checkbox" name="' . esc_attr( "{$n}[links][$i][external]" ) . '" value="1"' . checked( ! empty( $l['external'] ), true, false ) . '></td></tr>';
		}
		echo '</tbody></table><p class="description">Blank rows are skipped. Rows show in this order.</p>';

		echo '<h2>Addresses</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">Turn on</th><td><label><input type="hidden" name="' . esc_attr( $n ) . '[enable_cards]" value="0"><input type="checkbox" name="' . esc_attr( $n ) . '[enable_cards]" value="1"' . checked( $s['enable_cards'], 1, false ) . '> Business cards</label> &nbsp; <label><input type="hidden" name="' . esc_attr( $n ) . '[enable_links]" value="0"><input type="checkbox" name="' . esc_attr( $n ) . '[enable_links]" value="1"' . checked( $s['enable_links'], 1, false ) . '> Links page</label></td></tr>';
		self::field( "{$n}[card_base]", $s['card_base'], 'Card address', 'text', 'The word after your domain. Default: card.' );
		self::field( "{$n}[links_base]", $s['links_base'], 'Links address', 'text', 'Default: links.' );
		echo '</table>';

		submit_button( 'Save cards and links' );
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Reset every field to the defaults?\');">';
		wp_nonce_field( 'scout_cards_reset' );
		echo '<input type="hidden" name="action" value="scout_cards_reset"><button class="button-link-delete">Reset to defaults</button></form>';
		?>
		<script>
		jQuery(function($){
		  $('.scc-pick').on('click',function(e){e.preventDefault();var td=$(this).closest('td');
		    var f=wp.media({title:'Choose a photo',library:{type:'image'},multiple:false});
		    f.on('select',function(){var a=f.state().get('selection').first().toJSON();
		      td.find('input[type=hidden]').val(a.id);td.find('img').attr('src',(a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail.url:a.url)).show();});
		    f.open();});
		  $('.scc-clear').on('click',function(e){e.preventDefault();var td=$(this).closest('td');td.find('input[type=hidden]').val('');td.find('img').hide();});
		});
		</script>
		<?php
		echo '</div>';
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
		$out                += self::clean_spot( $in );
		$out['links_latest'] = empty( $in['links_latest'] ) ? 0 : 1;
		$out['enable_cards'] = empty( $in['enable_cards'] ) ? 0 : 1;
		$out['enable_links'] = empty( $in['enable_links'] ) ? 0 : 1;
		$out['card_base']    = sanitize_title( $in['card_base'] ?? '' ) ? sanitize_title( $in['card_base'] ) : 'card';
		$out['links_base']   = sanitize_title( $in['links_base'] ?? '' ) ? sanitize_title( $in['links_base'] ) : 'links';
		$out['people']       = array();
		foreach ( (array) ( $in['people'] ?? array() ) as $p ) {
			if ( '' === trim( (string) ( $p['name'] ?? '' ) ) ) {
				continue;
			}
			$first           = preg_split( '/\s+/', trim( $p['name'] ) )[0];
			$out['people'][] = array(
				'name'     => $txt( $p['name'] ),
				'slug'     => sanitize_title( ! empty( $p['slug'] ) ? $p['slug'] : $first ),
				'title'    => $txt( $p['title'] ?? '' ),
				'email'    => sanitize_email( $p['email'] ?? '' ),
				'intro'    => $area( $p['intro'] ?? '' ),
				'pick'     => $area( $p['pick'] ?? '' ),
				'tags'     => $txt( $p['tags'] ?? '' ),
				'photo_id' => absint( $p['photo_id'] ?? 0 ),
			) + self::clean_spot( $p );
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
