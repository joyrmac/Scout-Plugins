<?php
/**
 * The Beehiiv API v2 calls this plugin makes, and nothing else.
 *
 * Endpoints (developers.beehiiv.com, read 2026-11-03):
 *
 *   GET  /v2/publications/{pub}                                  Test connection
 *   GET  /v2/publications/{pub}/subscriptions/by_email/{email}   Is this person on the list?
 *   POST /v2/publications/{pub}/subscriptions                    Create subscription
 *   POST /v2/publications/{pub}/subscriptions/{sub_id}/tags      Add tags
 *   PUT  /v2/publications/{pub}/subscriptions/by_email/{email}   Update custom fields
 *
 * Every call goes through wp_remote_request(), so tests can answer with the
 * pre_http_request filter. Each returns the same shape:
 *
 *   ok     true for a 2xx answer
 *   code   the HTTP status, or 0 when the request never got an answer
 *   data   the decoded "data" object
 *   retry  true when trying again later may work (no answer, 429, 5xx)
 *   error  a short error code for the log (never the response body)
 *
 * @package Scout_Newsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Newsletter_Beehiiv {

	const BASE = 'https://api.beehiiv.com/v2/publications/';

	/** Beehiiv statuses that mean "do not touch": unsubscribed or a bad address. */
	const STOP = array( 'inactive', 'invalid' );

	public static function publication(): string {
		return (string) Scout_Newsletter::settings()['publication'];
	}

	/** The publication itself: proves the key and the ID work together. */
	public static function test(): array {
		return self::call( 'GET', '' );
	}

	public static function find( string $email ): array {
		return self::call( 'GET', '/subscriptions/by_email/' . rawurlencode( $email ) );
	}

	/**
	 * The create-subscription body for a consent record. Kept separate so the
	 * tests can check it against Beehiiv's documented schema.
	 */
	public static function create_body( array $row ): array {
		$s    = Scout_Newsletter::settings();
		$body = array(
			'email'               => (string) $row['email'],
			// Someone who unsubscribed stays unsubscribed (Beehiiv: use true only for a knowing resubscribe).
			'reactivate_existing' => false,
			'send_welcome_email'  => ! empty( $s['welcome'] ),
			'utm_source'          => Scout_Newsletter::utm_source(),
			'utm_medium'          => '' !== (string) $row['source'] ? (string) $row['source'] : 'website',
		);
		if ( '' !== (string) ( $row['campaign'] ?? '' ) ) {
			$body['utm_campaign'] = (string) $row['campaign'];
		}
		if ( '' !== (string) ( $row['url'] ?? '' ) ) {
			$body['referring_site'] = (string) $row['url'];
		}
		if ( in_array( $s['double_opt'], array( 'on', 'off' ), true ) ) {
			$body['double_opt_override'] = $s['double_opt'];
		}
		$fields = array();
		if ( '' !== trim( (string) $s['field_first'] ) && '' !== (string) ( $row['first_name'] ?? '' ) ) {
			$fields[] = array(
				'name'  => trim( (string) $s['field_first'] ),
				'value' => (string) $row['first_name'],
			);
		}
		if ( '' !== trim( (string) $s['field_joined'] ) ) {
			$labels   = Scout_Newsletter::sources();
			$fields[] = array(
				'name'  => trim( (string) $s['field_joined'] ),
				'value' => (string) ( $labels[ $row['source'] ] ?? ( '' !== (string) $row['source'] ? $row['source'] : 'website' ) ),
			);
		}
		if ( $fields ) {
			$body['custom_fields'] = $fields;
		}
		$automations = array_values( array_filter( array_map( 'trim', explode( ',', (string) $s['automation'] ) ) ) );
		if ( $automations ) {
			$body['automation_ids'] = $automations;
		}
		/** Change the create-subscription body before it is sent. */
		return (array) apply_filters( 'scout_newsletter_beehiiv_body', $body, $row );
	}

	public static function create( array $row ): array {
		return self::call( 'POST', '/subscriptions', self::create_body( $row ) );
	}

	public static function add_tags( string $sub_id, array $tags ): array {
		return self::call( 'POST', '/subscriptions/' . rawurlencode( $sub_id ) . '/tags', array( 'tags' => array_values( $tags ) ) );
	}

	/** @param array<string,string> $fields Custom field name => value. */
	public static function update_fields( string $email, array $fields ): array {
		$list = array();
		foreach ( $fields as $name => $value ) {
			$list[] = array(
				'name'  => (string) $name,
				'value' => (string) $value,
			);
		}
		return self::call( 'PUT', '/subscriptions/by_email/' . rawurlencode( $email ), array( 'custom_fields' => $list ) );
	}

	/* ---------- the one HTTP door ---------- */

	public static function call( string $method, string $path, ?array $body = null ): array {
		$key = Scout_Newsletter::key();
		$pub = self::publication();
		if ( '' === $key || '' === $pub ) {
			return self::result( false, 0, array(), false, 'not_connected' );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$res = wp_remote_request( self::BASE . rawurlencode( $pub ) . $path, $args );
		if ( is_wp_error( $res ) ) {
			return self::result( false, 0, array(), true, 'no_answer' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$data = is_array( $json ) && is_array( $json['data'] ?? null ) ? $json['data'] : array();
		if ( $code >= 200 && $code < 300 ) {
			return self::result( true, $code, $data, false, '' );
		}
		$err = is_array( $json ) && isset( $json['errors'][0]['code'] ) ? sanitize_key( (string) $json['errors'][0]['code'] ) : '';
		return self::result( false, $code, array(), 429 === $code || $code >= 500, '' !== $err ? $err : 'http_' . $code );
	}

	private static function result( bool $ok, int $code, array $data, bool $retry, string $error ): array {
		return compact( 'ok', 'code', 'data', 'retry', 'error' );
	}
}
