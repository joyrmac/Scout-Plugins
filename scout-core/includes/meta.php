<?php
/**
 * Registers post meta for every field in the registry (so fields are available
 * over REST and to the block editor), and provides the sanitizer map shared
 * with the meta box.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Meta {

	public static function register_all() {
		foreach ( Scout_Core_Registry::all() as $slug => $type ) {
			foreach ( $type['fields'] as $key => $field ) {
				register_post_meta(
					$slug,
					scout_core_meta_key( $key ),
					array(
						'single'            => true,
						'type'              => 'string',
						'show_in_rest'      => true,
						'sanitize_callback' => Scout_Core_Controls::sanitizer_for( $field['control'] ),
						'auth_callback'     => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);
			}
		}
	}

	/**
	 * Strict, options-aware sanitize used by the meta box on save.
	 *
	 * @param string $control text|textarea|richtext|number|url|select|checkbox|image.
	 * @param mixed  $value   Raw submitted value.
	 * @param array  $options For select: allowed value => label.
	 * @return string
	 */
	public static function sanitize( $control, $value, array $options = array() ) {
		return Scout_Core_Controls::sanitize( $control, $value, $options );
	}
}
