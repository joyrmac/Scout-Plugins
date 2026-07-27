<?php
/**
 * Registers every post type in the registry. Runs on init (priority 5), after
 * `scout_core_register` (priority 1) has populated the registry.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Post_Types {

	public static function register_all() {
		foreach ( Scout_Core_Registry::all() as $slug => $type ) {
			if ( post_type_exists( $slug ) ) {
				continue;
			}

			$singular = $type['singular'];
			$plural   = $type['plural'];

			$labels = array(
				'name'               => $plural,
				'singular_name'      => $singular,
				'menu_name'          => $plural,
				'add_new'            => 'Add New',
				'add_new_item'       => 'Add New ' . $singular,
				'edit_item'          => 'Edit ' . $singular,
				'new_item'           => 'New ' . $singular,
				'view_item'          => 'View ' . $singular,
				'view_items'         => 'View ' . $plural,
				'search_items'       => 'Search ' . $plural,
				'all_items'          => 'All ' . $plural,
				'not_found'          => 'No ' . strtolower( $plural ) . ' found.',
				'not_found_in_trash' => 'No ' . strtolower( $plural ) . ' found in Trash.',
			);

			register_post_type(
				$slug,
				array(
					'labels'       => $labels,
					'public'       => (bool) $type['public'],
					'show_in_rest' => true, // Required for the block editor and Block Bindings.
					'menu_icon'    => $type['menu_icon'],
					'has_archive'  => $type['has_archive'],
					'rewrite'      => $type['rewrite'],
					'supports'     => $type['supports'],
				)
			);
		}
	}
}
