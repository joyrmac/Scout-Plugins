<?php
/**
 * Plugin Name:       Scout Core
 * Description:       Scout's content model: custom post types, native fields, the business identity, and Block Bindings sources. Theme-independent on purpose, so a client's content and SEO survive any future redesign.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-core
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-core
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
require_once plugin_dir_path( __FILE__ ) . 'includes/class-scout-plugin-updater.php';
Scout_Plugin_Updater::boot( __FILE__, 'scout-core' );

define( 'SCOUT_CORE_VERSION', '0.1.0' );
define( 'SCOUT_CORE_DIR', plugin_dir_path( __FILE__ ) );

require_once SCOUT_CORE_DIR . 'includes/registry.php';
require_once SCOUT_CORE_DIR . 'includes/post-types.php';
require_once SCOUT_CORE_DIR . 'includes/meta.php';
require_once SCOUT_CORE_DIR . 'includes/meta-box.php';
require_once SCOUT_CORE_DIR . 'includes/block-bindings.php';
require_once SCOUT_CORE_DIR . 'includes/business.php';
require_once SCOUT_CORE_DIR . 'includes/universal-types.php';

/**
 * Registration pipeline.
 *
 * The registry is filled at init:1 via the `scout_core_register` action that
 * the universal types and any companion plugins hook into. Post types, meta,
 * and bindings then read from that one registry in turn, so they can never
 * drift apart.
 */
add_action( 'init', function () { do_action( 'scout_core_register' ); }, 1 );
add_action( 'init', array( 'Scout_Core_Post_Types', 'register_all' ), 5 );
add_action( 'init', array( 'Scout_Core_Meta', 'register_all' ), 6 );
add_action( 'init', array( 'Scout_Core_Block_Bindings', 'register' ), 7 );

add_action( 'add_meta_boxes', array( 'Scout_Core_Meta_Box', 'add' ) );
add_action( 'save_post', array( 'Scout_Core_Meta_Box', 'save' ), 10, 2 );

add_action( 'admin_menu', array( 'Scout_Core_Business', 'admin_menu' ) );
add_action( 'admin_init', array( 'Scout_Core_Business', 'register_settings' ) );

/**
 * On activation, register the types once and flush rewrite rules so any
 * archive/slug routes resolve immediately. Deactivation flushes them back out.
 */
register_activation_hook( __FILE__, function () {
	do_action( 'scout_core_register' );
	Scout_Core_Post_Types::register_all();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
