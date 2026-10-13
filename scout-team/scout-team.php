<?php
/**
 * Plugin Name:       Scout Team
 * Description:       Team profiles with real credentials: role, photo, short bio, bar admissions, education, memberships, awards, military service, languages and profile links. Each person gets a profile page and Person schema, and a grid shortcode shows the whole team.
 * Version:           0.1.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-team
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-team
 *
 * @package Scout_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_TEAM_VERSION', '0.1.1' );
define( 'SCOUT_TEAM_FILE', __FILE__ );
define( 'SCOUT_TEAM_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Scout
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-team.php', 'scout-team' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-admin-ui.php';
require_once __DIR__ . '/includes/class-scout-team.php';
require_once __DIR__ . '/includes/class-scout-team-editor.php';
require_once __DIR__ . '/includes/class-scout-team-front.php';
require_once __DIR__ . '/includes/class-scout-team-schema.php';
require_once __DIR__ . '/includes/class-scout-team-admin.php';

Scout_Team::boot();
Scout_Team_Editor::boot();
Scout_Team_Front::boot();
Scout_Team_Schema::boot();
Scout_Team_Admin::boot();

register_activation_hook(
	__FILE__,
	static function () {
		Scout_Team::register_post_type();
		flush_rewrite_rules();
	}
);
register_deactivation_hook(
	__FILE__,
	static function () {
		unregister_post_type( Scout_Team::POST_TYPE );
		flush_rewrite_rules();
	}
);
