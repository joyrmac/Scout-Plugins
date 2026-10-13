<?php
/**
 * Plugin Name:       Scout Call Buttons
 * Description:       A call, text and "Let's talk" bar pinned to the bottom of the screen on phones, with a sheet that offers calling, texting or booking a time.
 * Version:           0.1.4
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-contact
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-contact
 *
 * @package Scout_Contact
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_CONTACT_VERSION', '0.1.4' );
define( 'SCOUT_CONTACT_FILE', __FILE__ );
define( 'SCOUT_CONTACT_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-contact.php', 'scout-contact' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-admin-ui.php';
require_once __DIR__ . '/includes/class-scout-contact.php';
require_once __DIR__ . '/includes/class-scout-contact-admin.php';

Scout_Contact::boot();
Scout_Contact_Admin::boot();
