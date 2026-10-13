<?php
/**
 * Plugin Name:       Scout AI Search
 * Description:       Gets the site ready for AI search (ChatGPT, Google AI answers, Perplexity, Claude): lets the AI search crawlers in, serves a plain-text guide at /llms.txt, and adds question-and-answer sections with matching FAQ schema to any page.
 * Version:           0.1.4
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-aeo
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-aeo
 *
 * @package Scout_AEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_AEO_VERSION', '0.1.4' );
define( 'SCOUT_AEO_FILE', __FILE__ );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Scout
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-aeo.php', 'scout-aeo' );
	},
	0
);

require_once plugin_dir_path( __FILE__ ) . 'includes/class-scout-admin-ui.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-scout-aeo.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-scout-aeo-faq.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-scout-aeo-admin.php';

Scout_AEO::boot();
Scout_AEO_FAQ::boot();
Scout_AEO_Admin::boot();
