<?php
/**
 * Plugin Name:       Scout Cards
 * Description:       Digital business cards and a social "link in bio" page, built into the client's own site: a team card, a card for each person in People with Card on, Save contact (vCard with photo), share mode with a big QR code, a home-screen icon, and /links/. Reads the business identity from Scout Core; edited in Scout -> Cards and links.
 * Version:           1.0.27
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-cards
 * Update URI:        https://github.com/Scout-Media-Raleigh/Scout-Plugins/scout-cards
 *
 * @package Scout_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_CARDS_VERSION', '1.0.27' );
define( 'SCOUT_CARDS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCOUT_CARDS_URL', plugin_dir_url( __FILE__ ) );
define( 'SCOUT_CARDS_FILE', __FILE__ );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Scout
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-cards.php', 'scout-cards' );
	},
	0
);

require_once SCOUT_CARDS_DIR . 'includes/class-scout-admin-ui.php';
require_once SCOUT_CARDS_DIR . 'includes/settings.php';
require_once SCOUT_CARDS_DIR . 'includes/people.php';
require_once SCOUT_CARDS_DIR . 'includes/routes.php';
require_once SCOUT_CARDS_DIR . 'includes/vcard.php';
require_once SCOUT_CARDS_DIR . 'includes/render.php';
require_once SCOUT_CARDS_DIR . 'includes/admin.php';
require_once SCOUT_CARDS_DIR . 'includes/theme-import.php';

/**
 * True when the active theme still makes the cards and links pages itself: its
 * Cards screen and its card builder are both loaded (the Scout Media theme before
 * 1.54.0). Then this plugin stays out of the way: no routes, no menu, nothing that
 * could take over the theme's /card/ and /links/ addresses. Scout Media 1.54.0 and
 * later load neither while this plugin is active, so the plugin takes over and
 * copies the theme's card settings once (includes/theme-import.php). Filter
 * `scout_cards_theme_has_cards` to decide.
 */
function scout_cards_theme_has_cards() {
	$theme = function_exists( 'scout_cards_admin_page' ) && function_exists( 'scout_card_people' );
	return (bool) apply_filters( 'scout_cards_theme_has_cards', $theme );
}

// After the theme has loaded, so the check above can see it.
add_action(
	'after_setup_theme',
	static function () {
		if ( scout_cards_theme_has_cards() ) {
			add_action( 'admin_notices', 'scout_cards_resting_notice' );
			return;
		}
		Scout_Cards_Theme_Import::init(); // before the People move, so it links the copied people too
		Scout_Cards_Routes::init();
		Scout_Cards_People::init();
		Scout_Cards_Admin::init();
	},
	20
);

/** On the Plugins screen only: say why the plugin is doing nothing. */
function scout_cards_resting_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-info"><p>' . esc_html__( 'This theme already makes the cards and links pages, so Scout Cards is standing down and changes nothing. You can deactivate it.', 'scout-cards' ) . '</p></div>';
}

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! scout_cards_theme_has_cards() ) {
			Scout_Cards_Routes::activate();
		}
	}
);
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
