<?php
/**
 * Scout Core — template helpers.
 *
 * The three-line contract a theme needs. Everything returns a plain string or
 * prints escaped HTML; nothing here knows about layout.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A field value on a post. Falls back to $default when the field is empty.
 *
 * @param string   $key     Field key (without the scout_ prefix).
 * @param int|null $post_id Post ID, defaults to the current post.
 * @param string   $default Fallback.
 * @return string Raw stored value. Escape on output.
 */
function scout_core_field( $key, $post_id = null, $default = '' ) {
	$post_id = $post_id ? (int) $post_id : get_the_ID();
	if ( ! $post_id ) {
		return $default;
	}
	$value = get_post_meta( $post_id, scout_core_meta_key( $key ), true );
	return ( '' === $value || null === $value ) ? $default : (string) $value;
}

/**
 * A settings-group value.
 *
 * @param string $group   Group slug.
 * @param string $key     Field key.
 * @param string $default Fallback.
 * @return string
 */
function scout_core_setting( $group, $key, $default = '' ) {
	$values = Scout_Core_Settings_Groups::values( $group );
	$key    = sanitize_key( $key );
	return ( isset( $values[ $key ] ) && '' !== $values[ $key ] ) ? (string) $values[ $key ] : $default;
}

/**
 * An <img> tag for an image field, with width, height, srcset, and alt from
 * the media library. Returns '' when the field is empty, so a template can
 * decide what to draw instead.
 *
 * @param string|int $value_or_key Field key on the current post, or an attachment ID.
 * @param string     $size         Registered image size.
 * @param array      $attrs        Extra attributes (class, loading, fetchpriority...).
 * @param int|null   $post_id      Post to read the field from.
 * @return string HTML.
 */
function scout_core_image( $value_or_key, $size = 'full', array $attrs = array(), $post_id = null ) {
	$id = scout_core_image_id( $value_or_key, $post_id );
	if ( ! $id ) {
		return '';
	}
	$attrs = wp_parse_args( $attrs, array( 'loading' => 'lazy', 'decoding' => 'async' ) );
	if ( isset( $attrs['fetchpriority'] ) && 'high' === $attrs['fetchpriority'] ) {
		unset( $attrs['loading'] );
	}
	$attrs = array_filter( $attrs, static function ( $v ) { return null !== $v && false !== $v; } );
	return (string) wp_get_attachment_image( $id, $size, false, $attrs );
}

/**
 * Attachment ID behind an image field (or a settings-group image).
 *
 * @param string|int $value_or_key Field key, or a numeric attachment ID, or "group:key".
 * @param int|null   $post_id      Post to read the field from.
 * @return int 0 when empty or the attachment no longer exists.
 */
function scout_core_image_id( $value_or_key, $post_id = null ) {
	if ( is_numeric( $value_or_key ) ) {
		$id = absint( $value_or_key );
	} elseif ( false !== strpos( (string) $value_or_key, ':' ) ) {
		list( $group, $key ) = explode( ':', (string) $value_or_key, 2 );
		$id = absint( scout_core_setting( $group, $key ) );
	} else {
		$id = absint( scout_core_field( $value_or_key, $post_id ) );
	}
	if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
		return 0;
	}
	return $id;
}

/**
 * URL of an image field at a given size. '' when empty.
 */
function scout_core_image_url( $value_or_key, $size = 'full', $post_id = null ) {
	$id = scout_core_image_id( $value_or_key, $post_id );
	if ( ! $id ) {
		return '';
	}
	$src = wp_get_attachment_image_src( $id, $size );
	return $src ? (string) $src[0] : '';
}
