<?php
/**
 * WordPress's own privacy tools (Tools > Export Personal Data and Erase
 * Personal Data) reach the newsletter sign-up records, matched by email.
 *
 * A sign-up record is the business's proof that someone said yes, so erasing
 * keeps the record and removes the person from it: the email becomes the same
 * short one-way code the send log uses, and the first name and Beehiiv
 * fields are cleared. The time, the source, the page and the exact words
 * beside the box stay. If the business is ever asked to show that this person
 * agreed, the code still matches their email, and nothing else can be read
 * back from it. A record still waiting to go to Beehiiv is stopped, so it is
 * never sent. Beehiiv keeps its own copy; remove the person there too.
 *
 * @package Scout_Newsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Newsletter_Privacy {

	const GROUP = 'scout-newsletter';

	/** The start of an erased record's email: the rest is Scout_Newsletter::hash(). */
	const ERASED = 'erased:';

	public static function boot(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}

	/** Every record for this email, oldest first. */
	public static function rows( string $email ): array {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array();
		}
		Scout_Newsletter_Store::ensure();
		$t = Scout_Newsletter_Store::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE email = %s ORDER BY id ASC", $email ), ARRAY_A );
	}

	public static function exporter( array $exporters ): array {
		$exporters[ self::GROUP ] = array(
			'exporter_friendly_name' => __( 'Newsletter sign-ups', 'scout-newsletter' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function export_data( string $email, int $page = 1 ): array {
		$data    = array();
		$sources = Scout_Newsletter::sources();
		foreach ( self::rows( $email ) as $r ) {
			$tags   = json_decode( (string) $r['tags'], true );
			$extra  = json_decode( (string) $r['fields'], true );
			$fields = array(
				array(
					'name'  => __( 'Kind', 'scout-newsletter' ),
					'value' => 'update' === $r['kind'] ? __( 'List update (no new sign-up)', 'scout-newsletter' ) : __( 'Said yes to the newsletter', 'scout-newsletter' ),
				),
				array(
					'name'  => __( 'When (UTC)', 'scout-newsletter' ),
					'value' => (string) $r['created'],
				),
				array(
					'name'  => __( 'Email', 'scout-newsletter' ),
					'value' => (string) $r['email'],
				),
				array(
					'name'  => __( 'First name', 'scout-newsletter' ),
					'value' => (string) $r['first_name'],
				),
				array(
					'name'  => __( 'Where they joined', 'scout-newsletter' ),
					'value' => (string) ( $sources[ $r['source'] ] ?? $r['source'] ),
				),
				array(
					'name'  => __( 'Words beside the box', 'scout-newsletter' ),
					'value' => (string) $r['wording'],
				),
				array(
					'name'  => __( 'Page', 'scout-newsletter' ),
					'value' => (string) $r['url'],
				),
				array(
					'name'  => __( 'Campaign', 'scout-newsletter' ),
					'value' => (string) $r['campaign'],
				),
				array(
					'name'  => __( 'Tags', 'scout-newsletter' ),
					'value' => is_array( $tags ) ? implode( ', ', array_map( 'strval', $tags ) ) : '',
				),
				array(
					'name'  => __( 'Sent to the newsletter', 'scout-newsletter' ),
					'value' => (string) $r['status'],
				),
			);
			foreach ( is_array( $extra ) ? $extra : array() as $k => $v ) {
				$fields[] = array(
					'name'  => (string) $k,
					'value' => is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v ),
				);
			}
			$data[] = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'Newsletter sign-ups', 'scout-newsletter' ),
				'item_id'     => 'newsletter-' . (int) $r['id'],
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
			'eraser_friendly_name' => __( 'Newsletter sign-ups', 'scout-newsletter' ),
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	/** Keep each record as proof of the yes; take the person out of it. */
	public static function erase_data( string $email, int $page = 1 ): array {
		global $wpdb;
		$rows = self::rows( $email );
		$code = self::ERASED . Scout_Newsletter::hash( $email );
		foreach ( $rows as $r ) {
			$change = array(
				'email'      => $code,
				'first_name' => '',
				'fields'     => wp_json_encode( array() ),
				'sub_id'     => '',
				'updated'    => gmdate( 'Y-m-d H:i:s' ),
			);
			if ( in_array( $r['status'], array( 'held', 'queued', 'retry' ), true ) ) {
				$change['status'] = 'skipped';
				$change['result'] = 'erased on request';
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( Scout_Newsletter_Store::table(), $change, array( 'id' => (int) $r['id'] ) );
		}
		$messages = array();
		if ( $rows ) {
			$messages[] = __( 'Newsletter sign-up records were kept as proof of consent, with the email replaced by a one-way code and the name removed. Beehiiv keeps its own copy: remove the person there too.', 'scout-newsletter' );
		}
		return array(
			'items_removed'  => (bool) $rows,
			'items_retained' => (bool) $rows,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
