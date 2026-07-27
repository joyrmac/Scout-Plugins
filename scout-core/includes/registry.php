<?php
/**
 * Scout Core — type registry and the small public API.
 *
 * Companions (and Scout Core's own universal types) register content types
 * here on the `scout_core_register` action. Nothing touches WordPress directly
 * at this stage: post types, meta, the meta box, and block bindings all read
 * from this one registry so they can never disagree.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Registry {

	/** @var array<string,array> slug => normalized type definition */
	private static $types = array();

	/**
	 * Add (or replace) a content type definition.
	 *
	 * @param string $slug Post type key.
	 * @param array  $args Type definition (see scout_core_register_type()).
	 */
	public static function add( $slug, array $args ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return;
		}

		$args = wp_parse_args(
			$args,
			array(
				'singular'    => ucfirst( str_replace( array( '_', '-' ), ' ', $slug ) ),
				'plural'      => ucfirst( str_replace( array( '_', '-' ), ' ', $slug ) ) . 's',
				'menu_icon'   => 'dashicons-admin-post',
				'public'      => true,
				'has_archive' => false,
				'supports'    => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'rewrite'     => true,
				'fields'      => array(),
			)
		);

		// Normalize each field to a predictable shape.
		$fields = array();
		foreach ( (array) $args['fields'] as $key => $field ) {
			$key = sanitize_key( $key );
			if ( '' === $key ) {
				continue;
			}
			$fields[ $key ] = wp_parse_args(
				(array) $field,
				array(
					'label'   => ucfirst( str_replace( '_', ' ', $key ) ),
					'control' => 'text', // text | textarea | number | url | select.
					'options' => array(), // For select: value => label.
				)
			);
		}
		$args['fields'] = $fields;

		self::$types[ $slug ] = $args;
	}

	/** @return array<string,array> */
	public static function all() {
		return self::$types;
	}

	/** @return array|null */
	public static function get( $slug ) {
		$slug = sanitize_key( $slug );
		return isset( self::$types[ $slug ] ) ? self::$types[ $slug ] : null;
	}
}

/**
 * Public API: register a Scout content type.
 *
 * Call this on the `scout_core_register` action from any companion plugin:
 *
 *     add_action( 'scout_core_register', function () {
 *         scout_core_register_type( 'amenity', array(
 *             'singular' => 'Amenity',
 *             'plural'   => 'Amenities',
 *             'fields'   => array(
 *                 'summary' => array( 'label' => 'Summary', 'control' => 'textarea' ),
 *             ),
 *         ) );
 *     } );
 *
 * @param string $slug Post type key.
 * @param array  $args singular, plural, menu_icon, public, has_archive,
 *                     supports, rewrite, fields[ key => { label, control, options } ].
 */
function scout_core_register_type( $slug, array $args = array() ) {
	Scout_Core_Registry::add( $slug, $args );
}

/**
 * Storage key for a field. Field keys are developer-friendly ("author_name");
 * storage is namespaced ("scout_author_name") to avoid collisions.
 *
 * @param string $field_key Developer-facing field key.
 * @return string Meta key used in the database.
 */
function scout_core_meta_key( $field_key ) {
	return 'scout_' . sanitize_key( $field_key );
}
