<?php
/**
 * WordPress's own privacy tools (Tools > Export Personal Data and Erase
 * Personal Data) reach the chat messages a visitor left, matched by the email
 * they typed in the chat.
 *
 * Each chat message is one visitor's conversation with the assistant, so
 * erasing deletes the whole message. A visitor who left only a phone number
 * cannot be matched by email; staff delete that message by hand.
 *
 * @package Scout_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Chat_Privacy {

	const GROUP = 'scout-chat';
	const PER   = 50;

	public static function boot(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}

	/** Chat messages left with this email, a page at a time. @return int[] */
	public static function ids( string $email, int $page = 1 ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => Scout_Chat::CPT,
				'post_status'    => array( 'any', 'trash' ), // Trashed messages still hold the visitor's details.
				'posts_per_page' => self::PER,
				'paged'          => max( 1, $page ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'meta_key'       => '_scout_chat_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- privacy requests are rare.
				'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array_values( array_filter( array_map( 'intval', (array) $ids ), static fn( $id ) => strtolower( (string) get_post_meta( $id, '_scout_chat_email', true ) ) === $email ) );
	}

	public static function exporter( array $exporters ): array {
		$exporters[ self::GROUP ] = array(
			'exporter_friendly_name' => __( 'Chat messages', 'scout-chat' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function export_data( string $email, int $page = 1 ): array {
		$ids  = self::ids( $email, $page );
		$data = array();
		foreach ( $ids as $id ) {
			$post   = get_post( $id );
			$fields = array(
				array(
					'name'  => __( 'Sent', 'scout-chat' ),
					'value' => $post ? (string) $post->post_date : '',
				),
				array(
					'name'  => __( 'Name', 'scout-chat' ),
					'value' => (string) get_post_meta( $id, '_scout_chat_name', true ),
				),
				array(
					'name'  => __( 'Email', 'scout-chat' ),
					'value' => (string) get_post_meta( $id, '_scout_chat_email', true ),
				),
				array(
					'name'  => __( 'Phone', 'scout-chat' ),
					'value' => (string) get_post_meta( $id, '_scout_chat_phone', true ),
				),
				array(
					'name'  => __( 'Page', 'scout-chat' ),
					'value' => (string) get_post_meta( $id, '_scout_chat_page', true ),
				),
				array(
					'name'  => __( 'Conversation', 'scout-chat' ),
					'value' => $post ? (string) $post->post_content : '',
				),
			);
			$data[] = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'Chat messages', 'scout-chat' ),
				'item_id'     => 'chat-' . $id,
				'data'        => array_values( array_filter( $fields, static fn( $f ) => '' !== $f['value'] ) ),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $ids ) < self::PER,
		);
	}

	public static function eraser( array $erasers ): array {
		$erasers[ self::GROUP ] = array(
			'eraser_friendly_name' => __( 'Chat messages', 'scout-chat' ),
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	/** Deleted messages drop out of the list, so every pass reads the first page. */
	public static function erase_data( string $email, int $page = 1 ): array {
		$ids     = self::ids( $email, 1 );
		$removed = false;
		foreach ( $ids as $id ) {
			$removed = (bool) wp_delete_post( $id, true ) || $removed;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < self::PER,
		);
	}
}
