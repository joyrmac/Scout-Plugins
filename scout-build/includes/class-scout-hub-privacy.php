<?php
/**
 * WordPress's own privacy tools (Tools > Export Personal Data and Erase
 * Personal Data) reach the change requests visitors send through Scout Chat,
 * matched by the email the visitor gave in the chat.
 *
 * A change request is a work item about the website, so erasing keeps the
 * request and takes the person out of it: the "From the site chat" line with
 * their name, email and phone becomes "[deleted]", and the Site log line that
 * named them names Scout Chat instead. Requests staff send from the
 * dashboard belong to their WordPress account and are not touched.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Privacy {

	const GROUP = 'scout-build-requests';

	public static function boot(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}

	/** Visitor change requests whose text carries this email as "<email>". @return WP_Post[] */
	public static function requests( string $email ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'      => Scout_Hub_Requests::TYPE,
				'post_status'    => array( 'any', 'trash' ),
				'posts_per_page' => -1,
				's'              => $email,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		return array_values(
			array_filter(
				(array) $posts,
				static fn( $p ) => 0 === (int) $p->post_author && false !== stripos( (string) $p->post_content, '<' . $email . '>' )
			)
		);
	}

	public static function exporter( array $exporters ): array {
		$exporters[ self::GROUP ] = array(
			'exporter_friendly_name' => __( 'Change requests', 'scout-build' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function export_data( string $email, int $page = 1 ): array {
		$data = array();
		foreach ( self::requests( $email ) as $p ) {
			$status = (string) get_post_meta( $p->ID, Scout_Hub_Requests::META_STATUS, true );
			$fields = array(
				array(
					'name'  => __( 'Sent', 'scout-build' ),
					'value' => (string) $p->post_date,
				),
				array(
					'name'  => __( 'Request', 'scout-build' ),
					'value' => (string) $p->post_content,
				),
				array(
					'name'  => __( 'Page', 'scout-build' ),
					'value' => (string) get_post_meta( $p->ID, Scout_Hub_Requests::META_PAGE, true ),
				),
				array(
					'name'  => __( 'Status', 'scout-build' ),
					'value' => (string) ( Scout_Hub_Requests::STATUSES[ $status ] ?? $status ),
				),
			);
			$data[] = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'Change requests', 'scout-build' ),
				'item_id'     => 'request-' . $p->ID,
				'data'        => array_values( array_filter( $fields, static fn( $f ) => '' !== $f['value'] ) ),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	public static function eraser( array $erasers ): array {
		$erasers[ self::GROUP ] = array(
			'eraser_friendly_name' => __( 'Change requests', 'scout-build' ),
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	public static function erase_data( string $email, int $page = 1 ): array {
		$email = strtolower( trim( $email ) );
		$found = self::requests( $email );
		foreach ( $found as $p ) {
			$name  = '';
			$lines = explode( "\n", (string) $p->post_content );
			foreach ( $lines as $i => $line ) {
				$at = stripos( $line, '<' . $email . '>' );
				if ( false === $at ) {
					continue;
				}
				$head  = substr( $line, 0, $at );
				$colon = strpos( $head, ':' );
				$label = false !== $colon ? substr( $head, 0, $colon + 1 ) : '';
				$name  = trim( false !== $colon ? substr( $head, $colon + 1 ) : $head );
				// The label stays ("From the site chat:"); the name, email and phone after it go.
				$lines[ $i ] = trim( $label . ' ' . wp_privacy_anonymize_data( 'text' ) );
			}
			wp_update_post(
				array(
					'ID'           => $p->ID,
					'post_content' => implode( "\n", $lines ),
				)
			);
			if ( '' !== $name ) {
				self::forget_in_log( (int) $p->ID, $name );
			}
		}
		$messages = array();
		if ( $found ) {
			$messages[] = __( 'Change requests from the site chat were kept as work items, with the person’s name, email and phone removed.', 'scout-build' );
		}
		return array(
			'items_removed'  => (bool) $found,
			'items_retained' => (bool) $found,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/** The Site log's "You asked" line for a request names who asked; it now names the chat. */
	private static function forget_in_log( int $request, string $name ): void {
		if ( ! class_exists( 'Scout_Hub_Log' ) ) {
			return;
		}
		$lines = get_posts(
			array(
				'post_type'      => Scout_Hub_Log::TYPE,
				'post_status'    => array( 'any', 'trash' ),
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'meta_key'       => Scout_Hub_Log::META_REF, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'request:' . $request, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( array_map( 'intval', (array) $lines ) as $line ) {
			if ( $name === (string) get_post_meta( $line, Scout_Hub_Log::META_SOURCE, true ) ) {
				update_post_meta( $line, Scout_Hub_Log::META_SOURCE, 'Scout Chat' );
			}
		}
	}
}
