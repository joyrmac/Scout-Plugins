<?php
/**
 * Scout Core — page fieldsets.
 *
 * A fieldset is a set of named, sectioned fields attached to a page (or to
 * every post of a type). It turns a designed page into a form: one field per
 * headline, paragraph, and picture, grouped the way the page is laid out, with
 * the block editor hidden so an editor can only change words and images,
 * never the layout.
 *
 *   scout_core_register_fields( 'home', array(
 *       'label'    => 'Home page',
 *       'for'      => array( 'front_page' => true ),
 *       'sections' => array(
 *           'hero' => array(
 *               'label'  => 'Hero',
 *               'fields' => array(
 *                   'hero_heading' => array( 'label' => 'Headline' ),
 *                   'hero_image'   => array( 'label' => 'Background photo', 'control' => 'image' ),
 *               ),
 *           ),
 *       ),
 *   ) );
 *
 * `for` decides which posts get the box. Any combination of:
 *   front_page => true            the page set as the site front page
 *   template   => 'templates/about.php'   pages using that page template
 *   slug       => 'about'         a page with that slug (fallback matcher)
 *   post_type  => 'service'       every post of that type
 * `post_type` defaults to 'page'.
 *
 * Values are ordinary post meta under scout_<key>, so a theme reads them with
 * scout_core_field() / scout_core_image(), or binds a block to scout/field.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Fieldsets {

	/** @var array<string,array> id => normalized fieldset */
	private static $sets = array();

	public static function add( $id, array $args ) {
		$id = sanitize_key( $id );
		if ( '' === $id ) {
			return;
		}
		$args = wp_parse_args(
			$args,
			array(
				'label'       => ucfirst( str_replace( array( '_', '-' ), ' ', $id ) ),
				'intro'       => '',
				'for'         => array(),
				'sections'    => array(),
				'hide_editor' => true,
				'priority'    => 'high',
			)
		);
		$for = wp_parse_args(
			(array) $args['for'],
			array(
				'post_type'  => 'page',
				'front_page' => false,
				'template'   => '',
				'slug'       => '',
			)
		);
		$args['for'] = $for;

		$sections = array();
		foreach ( (array) $args['sections'] as $skey => $section ) {
			$skey = sanitize_key( $skey );
			if ( '' === $skey ) {
				continue;
			}
			$section = wp_parse_args(
				(array) $section,
				array(
					'label'  => ucfirst( str_replace( '_', ' ', $skey ) ),
					'hint'   => '',
					'fields' => array(),
				)
			);
			$fields = array();
			foreach ( (array) $section['fields'] as $fkey => $field ) {
				$fkey = sanitize_key( $fkey );
				if ( '' === $fkey ) {
					continue;
				}
				$fields[ $fkey ] = Scout_Core_Controls::normalize( $fkey, $field );
			}
			$section['fields'] = $fields;
			$sections[ $skey ] = $section;
		}
		$args['sections'] = $sections;

		self::$sets[ $id ] = $args;
	}

	/** @return array<string,array> */
	public static function all() {
		return self::$sets;
	}

	/** @return array|null */
	public static function get( $id ) {
		$id = sanitize_key( $id );
		return isset( self::$sets[ $id ] ) ? self::$sets[ $id ] : null;
	}

	/**
	 * Every field across a fieldset, flat: key => definition.
	 *
	 * @return array<string,array>
	 */
	public static function fields( array $set ) {
		$out = array();
		foreach ( $set['sections'] as $section ) {
			foreach ( $section['fields'] as $key => $field ) {
				$out[ $key ] = $field;
			}
		}
		return $out;
	}

	/**
	 * Fieldsets that apply to a given post.
	 *
	 * @param int|WP_Post $post Post.
	 * @return array<string,array>
	 */
	public static function for_post( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return array();
		}
		$matched = array();
		foreach ( self::$sets as $id => $set ) {
			if ( self::matches( $set['for'], $post ) ) {
				$matched[ $id ] = $set;
			}
		}
		return $matched;
	}

	private static function matches( array $for, WP_Post $post ) {
		if ( $post->post_type !== $for['post_type'] ) {
			return false;
		}
		$specific = $for['front_page'] || '' !== $for['template'] || '' !== $for['slug'];
		if ( ! $specific ) {
			return true; // Every post of the type.
		}
		if ( $for['front_page'] && (int) get_option( 'page_on_front' ) === (int) $post->ID ) {
			return true;
		}
		if ( '' !== $for['template'] && get_page_template_slug( $post ) === $for['template'] ) {
			return true;
		}
		if ( '' !== $for['slug'] && $post->post_name === $for['slug'] ) {
			return true;
		}
		return false;
	}

	/**
	 * Register meta for every fieldset field on its post type, once per key.
	 * Runs on init after the registry is populated.
	 */
	public static function register_meta() {
		$done = array();
		foreach ( self::$sets as $set ) {
			$type = $set['for']['post_type'];
			foreach ( self::fields( $set ) as $key => $field ) {
				if ( isset( $done[ $type ][ $key ] ) ) {
					continue;
				}
				$done[ $type ][ $key ] = true;
				register_post_meta(
					$type,
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

	/* ---------- editor screen ---------- */

	public static function add_boxes( $post_type, $post = null ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$sets = self::for_post( $post );
		if ( ! $sets ) {
			return;
		}
		foreach ( $sets as $id => $set ) {
			add_meta_box(
				'scout-fieldset-' . $id,
				$set['label'],
				array( __CLASS__, 'render_box' ),
				$post_type,
				'normal',
				$set['priority'],
				array( 'fieldset' => $id )
			);
			if ( $set['hide_editor'] ) {
				// Runs after add_meta_boxes and before the classic editor decides
				// whether to draw the content box, so this hides it for matched
				// posts only. The block editor is refused in use_block_editor().
				remove_post_type_support( $post_type, 'editor' );
			}
		}
	}

	/**
	 * Matched posts use the classic screen, which is where the fieldset box
	 * lives. Everything else keeps the block editor.
	 */
	public static function use_block_editor( $use, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return $use;
		}
		foreach ( self::for_post( $post ) as $set ) {
			if ( $set['hide_editor'] ) {
				return false;
			}
		}
		return $use;
	}

	public static function render_box( $post, $box ) {
		$set = self::get( $box['args']['fieldset'] );
		if ( ! $set ) {
			return;
		}
		wp_nonce_field( 'scout_core_save_fieldset', 'scout_core_fieldset_nonce' );
		echo '<input type="hidden" name="scout_fieldsets[]" value="' . esc_attr( $box['args']['fieldset'] ) . '" />';
		echo '<div class="scout-fieldset">';
		if ( '' !== $set['intro'] ) {
			echo '<p class="scout-fieldset__intro">' . esc_html( $set['intro'] ) . '</p>';
		}
		$first = true;
		foreach ( $set['sections'] as $skey => $section ) {
			echo '<details class="scout-section" data-section="' . esc_attr( $skey ) . '"' . ( $first ? ' open' : '' ) . '>';
			echo '<summary>' . esc_html( $section['label'] );
			if ( '' !== $section['hint'] ) {
				echo '<span class="scout-section__hint">' . esc_html( $section['hint'] ) . '</span>';
			}
			echo '</summary><div class="scout-section__body">';
			foreach ( $section['fields'] as $key => $field ) {
				$value = get_post_meta( $post->ID, scout_core_meta_key( $key ), true );
				Scout_Core_Controls::render( 'scout_meta[' . $key . ']', 'scout-field-' . $key, $field, $value );
			}
			echo '</div></details>';
			$first = false;
		}
		echo '</div>';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['scout_core_fieldset_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scout_core_fieldset_nonce'] ) ), 'scout_core_save_fieldset' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$ids = isset( $_POST['scout_fieldsets'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['scout_fieldsets'] ) ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value is sanitized per control below.
		$submitted = isset( $_POST['scout_meta'] ) ? (array) wp_unslash( $_POST['scout_meta'] ) : array();

		foreach ( $ids as $id ) {
			$set = self::get( $id );
			if ( ! $set || $set['for']['post_type'] !== $post->post_type ) {
				continue;
			}
			foreach ( self::fields( $set ) as $key => $field ) {
				$raw   = isset( $submitted[ $key ] ) ? $submitted[ $key ] : '';
				$clean = Scout_Core_Controls::sanitize( $field['control'], $raw, $field['options'] );
				if ( '' === $clean ) {
					delete_post_meta( $post_id, scout_core_meta_key( $key ) );
				} else {
					update_post_meta( $post_id, scout_core_meta_key( $key ), $clean );
				}
			}
		}
	}
}

/**
 * Public API: attach a sectioned set of fields to a page or a post type.
 * Call on the `scout_core_register` action.
 *
 * @param string $id   Fieldset id.
 * @param array  $args label, intro, for, sections, hide_editor.
 */
function scout_core_register_fields( $id, array $args ) {
	Scout_Core_Fieldsets::add( $id, $args );
}
