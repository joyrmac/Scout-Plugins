<?php
/**
 * Plugin Name:       Scout Chat
 * Description:       A chat assistant in the corner of the site. Answers the common questions, books calls, takes change requests, and saves every visitor question to an inbox. Gives AI answers once it is connected to an AI server.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-chat
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-chat
 *
 * @package Scout_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_CHAT_VERSION', '0.1.0' );
define( 'SCOUT_CHAT_FILE', __FILE__ );
define( 'SCOUT_CHAT_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Scout
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-chat.php', 'scout-chat' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-admin-ui.php';
require_once __DIR__ . '/includes/class-scout-chat.php';
require_once __DIR__ . '/includes/class-scout-chat-admin.php';

Scout_Chat::boot();
Scout_Chat_Admin::boot();
