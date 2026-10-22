<?php
/**
 * Plugin Name:       Scout Events
 * Description:       Events, sessions and speakers the owner edits in plain forms: event pages with dates, venue, ticket button and what to know, a day-by-day schedule, speaker pages, and Event schema so the event can appear in Google. Works with any ticket link today and is built to sit in front of a WooCommerce ticket engine later.
 * Version:           0.5.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-events
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-events
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_EVENTS_VERSION', '0.5.0' );
define( 'SCOUT_EVENTS_FILE', __FILE__ );
define( 'SCOUT_EVENTS_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Scout
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-events.php', 'scout-events' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-admin-ui.php';
require_once __DIR__ . '/includes/class-scout-events.php';
require_once __DIR__ . '/includes/class-scout-events-editor.php';
require_once __DIR__ . '/includes/class-scout-events-front.php';
require_once __DIR__ . '/includes/class-scout-events-schema.php';
require_once __DIR__ . '/includes/class-scout-events-import.php';
require_once __DIR__ . '/includes/class-scout-events-presenters.php';
require_once __DIR__ . '/includes/class-scout-events-admin.php';
require_once __DIR__ . '/includes/functions.php';

Scout_Events::boot();
Scout_Events_Editor::boot();
Scout_Events_Front::boot();
Scout_Events_Schema::boot();
Scout_Events_Admin::boot();
Scout_Events_Presenters::boot();

register_activation_hook(
	__FILE__,
	static function () {
		Scout_Events::register_post_types();
		Scout_Events_Presenters::role();
		flush_rewrite_rules();
	}
);
register_deactivation_hook(
	__FILE__,
	static function () {
		foreach ( Scout_Events::post_types() as $type ) {
			unregister_post_type( $type );
		}
		flush_rewrite_rules();
	}
);
