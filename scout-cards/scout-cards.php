<?php
/**
 * Plugin Name:       Scout Cards
 * Description:       Digital business cards and a social "link in bio" page, built into the client's own site: a team card, a card per person, Save contact (vCard with photo), share mode with a big QR code, a home-screen icon, and /links/. Reads the business identity from Scout Core; edited in Scout -> Cards & Links.
 * Version:           1.0.3
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-cards
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-cards
 *
 * @package Scout_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_CARDS_VERSION', '1.0.3' );
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

require_once SCOUT_CARDS_DIR . 'includes/settings.php';
require_once SCOUT_CARDS_DIR . 'includes/routes.php';
require_once SCOUT_CARDS_DIR . 'includes/vcard.php';
require_once SCOUT_CARDS_DIR . 'includes/render.php';
require_once SCOUT_CARDS_DIR . 'includes/admin.php';

Scout_Cards_Routes::init();
Scout_Cards_Admin::init();

register_activation_hook( __FILE__, array( 'Scout_Cards_Routes', 'activate' ) );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
