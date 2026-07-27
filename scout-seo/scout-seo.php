<?php
/**
 * Plugin Name:       Scout SEO
 * Description:       In-house SEO head tags (title, meta description, canonical, Open Graph, Twitter), robots control, an editor snippet-preview box, and tuning of WordPress core's built-in XML sitemap. The Yoast replacement. Pairs with Scout Schema, which owns the JSON-LD.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-seo
 *
 * @package Scout_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_SEO_VERSION', '0.1.0' );

// Per-post meta keys (protected; edited through the Scout SEO box).
define( 'SCOUT_SEO_META_TITLE', '_scout_seo_title' );
define( 'SCOUT_SEO_META_DESC', '_scout_seo_desc' );
define( 'SCOUT_SEO_META_CANONICAL', '_scout_seo_canonical' );
define( 'SCOUT_SEO_META_NOINDEX', '_scout_seo_noindex' );

$scout_seo_inc = plugin_dir_path( __FILE__ ) . 'includes/';
require_once $scout_seo_inc . 'class-seo-head.php';
require_once $scout_seo_inc . 'class-seo-box.php';
require_once $scout_seo_inc . 'class-seo-sitemap.php';

Scout_SEO_Head::init();
Scout_SEO_Box::init();
Scout_SEO_Sitemap::init();
