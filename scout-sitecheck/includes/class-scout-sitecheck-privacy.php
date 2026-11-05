<?php
/**
 * WordPress's own privacy tools (Tools > Export Personal Data and Erase
 * Personal Data) reach the site check requests a person sent, matched by the
 * email they typed on the form.
 *
 * Each check request belongs to the one person who sent it, so erasing
 * deletes the request and its results, and clears the short-lived count that
 * limits checks per email.
 *
 * @package Scout_Sitecheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Sitecheck_Privacy {

	const GROUP = 'scout-sitecheck';
	const PER   = 50;

	public static function boot(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}

	/** Check requests sent with this email, a page at a time. @return int[] */
	public static function ids( string $email, int $page = 1 ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => Scout_Sitecheck::CPT,
				'post_status'    => array( 'any', 'trash' ), // Trashed requests still hold the person's details.
				'posts_per_page' => self::PER,
				'paged'          => max( 1, $page ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'meta_key'       => '_ssc_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- privacy requests are rare.
				'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array_values( array_filter( array_map( 'intval', (array) $ids ), static fn( $id ) => strtolower( (string) get_post_meta( $id, '_ssc_email', true ) ) === $email ) );
	}

	public static function exporter( array $exporters ): array {
		$exporters[ self::GROUP ] = array(
			'exporter_friendly_name' => __( 'Check requests', 'scout-sitecheck' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function export_data( string $email, int $page = 1 ): array {
		$ids  = self::ids( $email, $page );
		$data = array();
		foreach ( $ids as $id ) {
			$post    = get_post( $id );
			$total   = (int) get_post_meta( $id, '_ssc_total', true );
			$checked = (int) get_post_meta( $id, '_ssc_checked', true );
			$fields  = array(
				array(
					'name'  => __( 'Sent', 'scout-sitecheck' ),
					'value' => $post ? (string) $post->post_date : '',
				),
				array(
					'name'  => __( 'Name', 'scout-sitecheck' ),
					'value' => (string) get_post_meta( $id, '_ssc_name', true ),
				),
				array(
					'name'  => __( 'Email', 'scout-sitecheck' ),
					'value' => (string) get_post_meta( $id, '_ssc_email', true ),
				),
				array(
					'name'  => __( 'Website checked', 'scout-sitecheck' ),
					'value' => (string) get_post_meta( $id, '_ssc_url', true ),
				),
				array(
					'name'  => __( 'Page the form was on', 'scout-sitecheck' ),
					'value' => (string) get_post_meta( $id, '_ssc_page', true ),
				),
				array(
					'name'  => __( 'Checks passed', 'scout-sitecheck' ),
					'value' => $total ? (int) get_post_meta( $id, '_ssc_passed', true ) . ' / ' . $total : '',
				),
				array(
					'name'  => __( 'Checked', 'scout-sitecheck' ),
					'value' => $checked ? gmdate( 'Y-m-d H:i', $checked ) . ' UTC' : '',
				),
			);
			$data[] = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'Check requests', 'scout-sitecheck' ),
				'item_id'     => 'sitecheck-' . $id,
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
			'eraser_friendly_name' => __( 'Check requests', 'scout-sitecheck' ),
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	/** Deleted requests drop out of the list, so every pass reads the first page. */
	public static function erase_data( string $email, int $page = 1 ): array {
		$ids     = self::ids( $email, 1 );
		$removed = false;
		foreach ( $ids as $id ) {
			wp_clear_scheduled_hook( Scout_Sitecheck::FINISH, array( $id ) );
			$removed = (bool) wp_delete_post( $id, true ) || $removed;
		}
		// The per-email check count (made the same way as Scout_Sitecheck::over()).
		delete_transient( 'scout_ssc_' . md5( 'em' . strtolower( trim( $email ) ) . wp_salt( 'nonce' ) ) );
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < self::PER,
		);
	}
}
