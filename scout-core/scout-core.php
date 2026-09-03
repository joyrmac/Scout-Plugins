<?php
/**
 * Plugin Name:       Scout Core
 * Plugin URI:        https://scoutraleigh.com/platform/scout-core
 * Description:       The Scout engine for a client site: business identity, content model (post types, fields, Block Bindings), the JSON-LD schema graph, and the in-house SEO head tags and sitemap tuning. One plugin, one Scout dashboard. The Yoast replacement, built to survive any future redesign.
 * Version:           1.0.4
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * Author URI:        https://scoutraleigh.com
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-core
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-core
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_CORE_VERSION', '1.0.4' );
define( 'SCOUT_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCOUT_CORE_FILE', __FILE__ );

/*
 * SEO per-post meta keys (were scout-seo; folded in). Protected, edited through
 * the Scout SEO box.
 */
define( 'SCOUT_SEO_META_TITLE', '_scout_seo_title' );
define( 'SCOUT_SEO_META_DESC', '_scout_seo_desc' );
define( 'SCOUT_SEO_META_CANONICAL', '_scout_seo_canonical' );
define( 'SCOUT_SEO_META_NOINDEX', '_scout_seo_noindex' );

/* ---- Content model (business identity, post types, fields, bindings) ---- */
require_once SCOUT_CORE_DIR . 'includes/registry.php';
require_once SCOUT_CORE_DIR . 'includes/post-types.php';
require_once SCOUT_CORE_DIR . 'includes/meta.php';
require_once SCOUT_CORE_DIR . 'includes/meta-box.php';
require_once SCOUT_CORE_DIR . 'includes/block-bindings.php';
require_once SCOUT_CORE_DIR . 'includes/business.php';
require_once SCOUT_CORE_DIR . 'includes/universal-types.php';

/* ---- Schema module (was scout-schema) ---- */
require_once SCOUT_CORE_DIR . 'includes/schema/class-scout-schema.php';

/* ---- SEO module (was scout-seo) ---- */
require_once SCOUT_CORE_DIR . 'includes/seo/class-seo-head.php';
require_once SCOUT_CORE_DIR . 'includes/seo/class-seo-box.php';
require_once SCOUT_CORE_DIR . 'includes/seo/class-seo-sitemap.php';

/* ---- SEO global defaults, the unified Scout admin, and the update tracker ---- */
require_once SCOUT_CORE_DIR . 'includes/seo/seo-settings.php';
require_once SCOUT_CORE_DIR . 'includes/admin.php';

/*
 * Self-updating from the Scout Plugins releases. Replaces the earlier hosted
 * manifest tracker, which needed a JSON file hosted somewhere and never got
 * switched on.
 */
require_once SCOUT_CORE_DIR . 'includes/class-scout-plugin-updater.php';
Scout_Plugin_Updater::boot( __FILE__, 'scout-core' );

/*
 * Registration pipeline. The registry is filled at init:1 via `scout_core_register`
 * that universal types and companion plugins hook, then post types, meta, and
 * bindings read from that one registry so they can never drift.
 */
add_action( 'init', function () { do_action( 'scout_core_register' ); }, 1 );
add_action( 'init', array( 'Scout_Core_Post_Types', 'register_all' ), 5 );
add_action( 'init', array( 'Scout_Core_Meta', 'register_all' ), 6 );
add_action( 'init', array( 'Scout_Core_Block_Bindings', 'register' ), 7 );

add_action( 'add_meta_boxes', array( 'Scout_Core_Meta_Box', 'add' ) );
add_action( 'save_post', array( 'Scout_Core_Meta_Box', 'save' ), 10, 2 );

/* Settings storage (options + sanitizers). The UI is the unified Scout admin. */
add_action( 'admin_init', array( 'Scout_Core_Business', 'register_settings' ) );
add_action( 'admin_init', array( 'Scout_Core_SEO_Settings', 'register_settings' ) );

/* Schema: render the graph, keep its cache fresh. */
add_action( 'wp_head', array( 'Scout_Schema', 'render' ), 20 );
add_action( 'save_post_testimonial', array( 'Scout_Schema', 'clear_cache' ) );
add_action( 'deleted_post', array( 'Scout_Schema', 'clear_cache' ) );
add_action( 'update_option_scout_business', array( 'Scout_Schema', 'clear_cache' ) );

/* SEO: head tags, the per-post box, sitemap tuning. */
Scout_SEO_Head::init();
Scout_SEO_Box::init();
Scout_SEO_Sitemap::init();

/* The one Scout menu (Dashboard / Business / SEO). */
Scout_Core_Admin::init();

/*
 * On activation register the types once and flush rewrite rules so archive/slug
 * routes resolve immediately. Deactivation flushes them back out.
 */
register_activation_hook( __FILE__, function () {
	do_action( 'scout_core_register' );
	Scout_Core_Post_Types::register_all();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
