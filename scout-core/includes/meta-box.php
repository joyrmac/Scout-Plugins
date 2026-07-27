<?php
/**
 * A single, dependency-free meta box per content type (no ACF). Renders one
 * input per registered field and saves them on save_post. The block editor
 * reads the same meta over REST; this box is the simple, always-works UI.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Meta_Box {

	public static function add() {
		foreach ( Scout_Core_Registry::all() as $slug => $type ) {
			if ( empty( $type['fields'] ) ) {
				continue;
			}
			add_meta_box(
				'scout-core-fields',
				$type['singular'] . ' Details',
				array( __CLASS__, 'render' ),
				$slug,
				'normal',
				'high'
			);
		}
	}

	public static function render( $post ) {
		$type = Scout_Core_Registry::get( $post->post_type );
		if ( ! $type ) {
			return;
		}
		wp_nonce_field( 'scout_core_save_meta', 'scout_core_meta_nonce' );

		echo '<div class="scout-core-fields" style="display:grid;gap:16px;">';
		foreach ( $type['fields'] as $key => $field ) {
			$value = get_post_meta( $post->ID, scout_core_meta_key( $key ), true );
			$name  = 'scout_meta[' . esc_attr( $key ) . ']';
			$id    = 'scout-field-' . esc_attr( $key );

			echo '<p style="margin:0;">';
			echo '<label for="' . esc_attr( $id ) . '" style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html( $field['label'] ) . '</label>';

			switch ( $field['control'] ) {
				case 'textarea':
					echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="3" style="width:100%;">' . esc_textarea( $value ) . '</textarea>';
					break;
				case 'select':
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
					echo '<option value="">' . esc_html__( '— Select —', 'scout-core' ) . '</option>';
					foreach ( $field['options'] as $opt_val => $opt_label ) {
						echo '<option value="' . esc_attr( $opt_val ) . '" ' . selected( $value, $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
					}
					echo '</select>';
					break;
				case 'number':
					echo '<input type="number" step="any" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
					break;
				case 'url':
					echo '<input type="url" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" style="width:100%;" />';
					break;
				case 'text':
				default:
					echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" style="width:100%;" />';
			}
			echo '</p>';
		}
		echo '</div>';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['scout_core_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scout_core_meta_nonce'] ) ), 'scout_core_save_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$type = Scout_Core_Registry::get( $post->post_type );
		if ( ! $type || empty( $type['fields'] ) ) {
			return;
		}

		$submitted = isset( $_POST['scout_meta'] ) ? (array) wp_unslash( $_POST['scout_meta'] ) : array();

		foreach ( $type['fields'] as $key => $field ) {
			$raw   = isset( $submitted[ $key ] ) ? $submitted[ $key ] : '';
			$clean = Scout_Core_Meta::sanitize( $field['control'], $raw, $field['options'] );

			if ( '' === $clean ) {
				delete_post_meta( $post_id, scout_core_meta_key( $key ) );
			} else {
				update_post_meta( $post_id, scout_core_meta_key( $key ), $clean );
			}
		}
	}
}
