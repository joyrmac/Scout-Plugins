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

		echo '<div class="scout-fieldset"><div class="scout-section__body" style="border:0;padding:0;">';
		foreach ( $type['fields'] as $key => $field ) {
			$value = get_post_meta( $post->ID, scout_core_meta_key( $key ), true );
			Scout_Core_Controls::render( 'scout_meta[' . $key . ']', 'scout-field-' . $key, $field, $value );
		}
		echo '</div></div>';
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
