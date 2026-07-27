<?php
/**
 * Plugin Name:       Scout Schema
 * Description:       Emits one JSON-LD @graph (LocalBusiness or subtype + WebSite, plus FAQPage, Review/AggregateRating, and BlogPosting where relevant) from Scout Core data. No third-party SEO plugin required.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  scout-core
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-schema
 *
 * @package Scout_Schema
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_SCHEMA_VERSION', '0.1.0' );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-scout-schema.php';

add_action( 'wp_head', array( 'Scout_Schema', 'render' ), 20 );

/**
 * The aggregate rating + review nodes are cached. Clear the cache whenever a
 * testimonial changes so the graph never goes stale.
 */
add_action( 'save_post_testimonial', array( 'Scout_Schema', 'clear_cache' ) );
add_action( 'deleted_post', array( 'Scout_Schema', 'clear_cache' ) );
add_action( 'update_option_scout_business', array( 'Scout_Schema', 'clear_cache' ) );
