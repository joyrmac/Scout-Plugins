<?php
/**
 * Plugin Name:       Scout — North Crest RV Park
 * Description:       Per-client content types for the North Crest RV Park build. The worked example of the Scout Core companion pattern: register a client's specific types without ever forking Scout Core.
 * Version:           0.1.4
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  scout-core
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-rvpark
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-rvpark
 *
 * @package Scout_RVPark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Scout
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-rvpark.php', 'scout-rvpark' );
	},
	0
);

/**
 * Register the park's specific content types on the Scout Core action. In a
 * real engagement this plugin lives in the client's project repo, not in the
 * shared platform; it is included here as the reference example.
 */
add_action( 'scout_core_register', function () {
	if ( ! function_exists( 'scout_core_register_type' ) ) {
		return; // Scout Core not active.
	}

	// Amenities: the park's features (pool, laundry, dog run, Wi-Fi...).
	scout_core_register_type(
		'amenity',
		array(
			'singular'    => 'Amenity',
			'plural'      => 'Amenities',
			'menu_icon'   => 'dashicons-palmtree',
			// No list page: /amenities/ belongs to the site's Amenities page, and a
			// post type's list page would take that address over.
			'has_archive' => false,
			'rewrite'     => array( 'slug' => 'amenities' ),
			'fields'      => array(
				'icon'      => array( 'label' => 'Icon (dashicon name or emoji)', 'control' => 'text' ),
				'summary'   => array( 'label' => 'Short summary', 'control' => 'textarea' ),
				'highlight' => array(
					'label'   => 'Feature on the homepage?',
					'control' => 'select',
					'options' => array(
						'no'  => 'No',
						'yes' => 'Yes',
					),
				),
			),
		)
	);

	// Site types: the bookable RV site categories, with rates and rig limits.
	scout_core_register_type(
		'site_type',
		array(
			'singular'  => 'Site Type',
			'plural'    => 'Site Types',
			'menu_icon' => 'dashicons-location',
			'fields'    => array(
				'hookups'    => array( 'label' => 'Hookups (e.g. Full / 30amp / 50amp)', 'control' => 'text' ),
				'nightly'    => array( 'label' => 'Nightly rate (USD)', 'control' => 'number' ),
				'monthly'    => array( 'label' => 'Monthly rate (USD)', 'control' => 'number' ),
				'max_length' => array( 'label' => 'Max rig length (ft)', 'control' => 'number' ),
			),
		)
	);
} );

// The amenity list page is gone (0.1.3): refresh permalinks once so its old
// address rule stops claiming /amenities/.
add_action( 'init', function () {
	if ( get_option( 'scout_rvpark_rewrite' ) !== '0.1.3' ) {
		flush_rewrite_rules( false );
		update_option( 'scout_rvpark_rewrite', '0.1.3', false );
	}
}, 99 );
