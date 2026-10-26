<?php
/**
 * Plugin Name:       Scout Members
 * Description:       A membership the owner runs from the dashboard: people apply on the site, the owner approves each one, and members sign in to see members-only pages and events, and post invitations to each other. Works alongside Scout Events, which marks events as members only. Card billing for dues is planned for a later version.
 * Version:           0.3.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-members
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-members
 *
 * @package Scout_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_MEMBERS_VERSION', '0.3.1' );
define( 'SCOUT_MEMBERS_FILE', __FILE__ );
define( 'SCOUT_MEMBERS_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-members.php', 'scout-members' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-admin-ui.php';
require_once __DIR__ . '/includes/class-scout-members.php';
require_once __DIR__ . '/includes/class-scout-members-form.php';
require_once __DIR__ . '/includes/class-scout-members-import.php';
require_once __DIR__ . '/includes/class-scout-members-front.php';
require_once __DIR__ . '/includes/class-scout-members-admin.php';
require_once __DIR__ . '/includes/class-scout-members-events.php';
require_once __DIR__ . '/includes/class-scout-members-invitations.php';

Scout_Members::boot();
Scout_Members_Front::boot();
Scout_Members_Admin::boot();
Scout_Members_Invitations::boot();
// Members-only events need Scout Events, which may load after this plugin.
add_action( 'plugins_loaded', array( 'Scout_Members_Events', 'boot' ), 20 );

register_activation_hook(
	__FILE__,
	static function () {
		Scout_Members::role();
	}
);
