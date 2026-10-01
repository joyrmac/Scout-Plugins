<?php
/**
 * Plugin Name:       Scout Reviews
 * Plugin URI:        https://scoutraleigh.com
 * Description:       Reviews from Google, Facebook, Yelp, Clutch, and anywhere else, kept in one place and shown on the site in a custom design. Every review links back to where it was posted, and the rating summary uses the real totals from each platform.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * Author URI:        https://scoutraleigh.com
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-reviews
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-reviews
 *
 * @package Scout_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_REVIEWS_VERSION', '0.1.0' );
define( 'SCOUT_REVIEWS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCOUT_REVIEWS_URL', plugin_dir_url( __FILE__ ) );
define( 'SCOUT_REVIEWS_FILE', __FILE__ );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
require_once SCOUT_REVIEWS_DIR . 'includes/class-scout-plugin-updater.php';
Scout_Plugin_Updater::boot( __FILE__, 'scout-reviews' );

require_once SCOUT_REVIEWS_DIR . 'includes/class-scout-reviews-sources.php';
require_once SCOUT_REVIEWS_DIR . 'includes/class-scout-reviews-post-type.php';
require_once SCOUT_REVIEWS_DIR . 'includes/class-scout-reviews-settings.php';
require_once SCOUT_REVIEWS_DIR . 'includes/class-scout-reviews-query.php';
require_once SCOUT_REVIEWS_DIR . 'includes/class-scout-reviews-render.php';

add_action( 'init', array( 'Scout_Reviews_Post_Type', 'register' ) );
add_action( 'init', array( 'Scout_Reviews_Render', 'register' ) );
add_action( 'admin_init', array( 'Scout_Reviews_Settings', 'register' ) );
add_action( 'admin_menu', array( 'Scout_Reviews_Settings', 'menu' ) );

if ( is_admin() ) {
	Scout_Reviews_Post_Type::admin_hooks();
}
