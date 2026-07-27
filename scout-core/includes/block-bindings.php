<?php
/**
 * Block Bindings sources (WordPress 6.5+). These let a block in the theme bind
 * its content to Scout data, so design lives in the theme and content lives in
 * the fields. That binding is the separation this whole platform is built on.
 *
 *   scout/field    — a field on the current post   (args: { "key": "summary" })
 *   scout/business — a value from the business identity (args: { "key": "phone" })
 *
 * Example block markup, in a theme template or pattern:
 *
 *   <!-- wp:paragraph {"metadata":{"bindings":{"content":
 *        {"source":"scout/field","args":{"key":"summary"}}}}} -->
 *   <p></p>
 *   <!-- /wp:paragraph -->
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Block_Bindings {

	public static function register() {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return; // Pre-6.5: bindings unavailable. A theme can read the meta directly instead.
		}

		register_block_bindings_source(
			'scout/field',
			array(
				'label'              => __( 'Scout: Field', 'scout-core' ),
				'get_value_callback' => array( __CLASS__, 'get_field' ),
				'uses_context'       => array( 'postId' ),
			)
		);

		register_block_bindings_source(
			'scout/business',
			array(
				'label'              => __( 'Scout: Business', 'scout-core' ),
				'get_value_callback' => array( __CLASS__, 'get_business' ),
			)
		);
	}

	public static function get_field( $source_args, $block_instance ) {
		if ( empty( $source_args['key'] ) ) {
			return null;
		}
		$post_id = isset( $block_instance->context['postId'] ) ? $block_instance->context['postId'] : get_the_ID();
		if ( ! $post_id ) {
			return null;
		}
		$value = get_post_meta( $post_id, scout_core_meta_key( $source_args['key'] ), true );
		return ( '' === $value ) ? null : $value;
	}

	public static function get_business( $source_args ) {
		if ( empty( $source_args['key'] ) ) {
			return null;
		}
		$business = scout_core_business();
		$key      = sanitize_key( $source_args['key'] );
		return ( isset( $business[ $key ] ) && '' !== $business[ $key ] ) ? $business[ $key ] : null;
	}
}
