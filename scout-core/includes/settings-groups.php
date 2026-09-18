<?php
/**
 * Scout Core — settings groups.
 *
 * Site-wide content that is not tied to one page: the header, the footer, the
 * shared call-to-action band, the 404 page. A companion registers a group and
 * it appears as its own tab under the Scout menu, drawn with the same controls
 * (including the image picker) as page fieldsets.
 *
 *   scout_core_register_settings_group( 'site', array(
 *       'label'    => 'Site content',
 *       'sections' => array( ... same shape as fieldsets ... ),
 *   ) );
 *
 * Stored as one option, scout_group_<slug>. Read with scout_core_setting().
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Settings_Groups {

	/** @var array<string,array> */
	private static $groups = array();

	public static function add( $slug, array $args ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug || in_array( $slug, array( 'dashboard', 'business', 'seo' ), true ) ) {
			return;
		}
		$args = wp_parse_args(
			$args,
			array(
				'label'    => ucfirst( str_replace( array( '_', '-' ), ' ', $slug ) ),
				'intro'    => '',
				'sections' => array(),
			)
		);
		$sections = array();
		foreach ( (array) $args['sections'] as $skey => $section ) {
			$skey = sanitize_key( $skey );
			if ( '' === $skey ) {
				continue;
			}
			$section = wp_parse_args( (array) $section, array( 'label' => ucfirst( $skey ), 'hint' => '', 'fields' => array() ) );
			$fields  = array();
			foreach ( (array) $section['fields'] as $fkey => $field ) {
				$fkey = sanitize_key( $fkey );
				if ( '' !== $fkey ) {
					$fields[ $fkey ] = Scout_Core_Controls::normalize( $fkey, $field );
				}
			}
			$section['fields'] = $fields;
			$sections[ $skey ] = $section;
		}
		$args['sections'] = $sections;
		self::$groups[ $slug ] = $args;
	}

	/** @return array<string,array> */
	public static function all() {
		return self::$groups;
	}

	public static function get( $slug ) {
		$slug = sanitize_key( $slug );
		return isset( self::$groups[ $slug ] ) ? self::$groups[ $slug ] : null;
	}

	public static function option_name( $slug ) {
		return 'scout_group_' . sanitize_key( $slug );
	}

	/** @return array<string,array> flat key => field */
	public static function fields( array $group ) {
		$out = array();
		foreach ( $group['sections'] as $section ) {
			foreach ( $section['fields'] as $key => $field ) {
				$out[ $key ] = $field;
			}
		}
		return $out;
	}

	/**
	 * All stored values for a group, with every registered key present.
	 *
	 * @return array<string,string>
	 */
	public static function values( $slug ) {
		$group = self::get( $slug );
		if ( ! $group ) {
			return array();
		}
		$defaults = array_fill_keys( array_keys( self::fields( $group ) ), '' );
		return wp_parse_args( (array) get_option( self::option_name( $slug ), array() ), $defaults );
	}

	public static function register_settings() {
		foreach ( self::$groups as $slug => $group ) {
			register_setting(
				'scout_group_' . $slug,
				self::option_name( $slug ),
				array(
					'type'              => 'array',
					'sanitize_callback' => function ( $input ) use ( $slug ) {
						return Scout_Core_Settings_Groups::sanitize( $slug, $input );
					},
				)
			);
		}
	}

	public static function sanitize( $slug, $input ) {
		$group = self::get( $slug );
		if ( ! $group ) {
			return array();
		}
		$input = (array) $input;
		$clean = array();
		foreach ( self::fields( $group ) as $key => $field ) {
			$raw           = isset( $input[ $key ] ) ? $input[ $key ] : '';
			$clean[ $key ] = Scout_Core_Controls::sanitize( $field['control'], $raw, $field['options'] );
		}
		return $clean;
	}

	/** Draw the group as a tab body inside the Scout admin. */
	public static function render_tab( $slug ) {
		$group = self::get( $slug );
		if ( ! $group ) {
			return;
		}
		$values = self::values( $slug );
		$option = self::option_name( $slug );
		if ( '' !== $group['intro'] ) {
			echo '<p class="scout-lead">' . esc_html( $group['intro'] ) . '</p>';
		}
		echo '<form method="post" action="options.php" class="scout-fieldset">';
		settings_fields( 'scout_group_' . $slug );
		$first = true;
		foreach ( $group['sections'] as $skey => $section ) {
			echo '<details class="scout-section" data-section="' . esc_attr( $slug . ':' . $skey ) . '"' . ( $first ? ' open' : '' ) . '>';
			echo '<summary>' . esc_html( $section['label'] );
			if ( '' !== $section['hint'] ) {
				echo '<span class="scout-section__hint">' . esc_html( $section['hint'] ) . '</span>';
			}
			echo '</summary><div class="scout-section__body">';
			foreach ( $section['fields'] as $key => $field ) {
				Scout_Core_Controls::render( $option . '[' . $key . ']', 'scout-group-' . $slug . '-' . $key, $field, $values[ $key ] );
			}
			echo '</div></details>';
			$first = false;
		}
		submit_button();
		echo '</form>';
	}
}

/**
 * Public API: register a settings group (a tab under the Scout menu).
 */
function scout_core_register_settings_group( $slug, array $args ) {
	Scout_Core_Settings_Groups::add( $slug, $args );
}
