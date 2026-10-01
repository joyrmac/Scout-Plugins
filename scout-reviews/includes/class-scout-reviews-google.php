<?php
/**
 * Google Business Profile connection: OAuth sign-in, token refresh, and the
 * three API calls the sync needs.
 *
 * Credentials: define SCOUT_REVIEWS_GOOGLE_CLIENT_ID and
 * SCOUT_REVIEWS_GOOGLE_CLIENT_SECRET in wp-config.php (preferred, keeps the
 * secret out of the database), or type them into Reviews > Settings.
 *
 * Stored in the `scout_reviews_google` option (never autoloaded):
 * client_id, client_secret, refresh_token, account, location, location_title,
 * last_sync, last_error.
 *
 * @package Scout_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Reviews_Google {

	const OPTION = 'scout_reviews_google';
	const SCOPE  = 'https://www.googleapis.com/auth/business.manage';

	const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

	const ACCOUNTS_URL  = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';
	const LOCATIONS_URL = 'https://mybusinessbusinessinformation.googleapis.com/v1/%s/locations';
	const LOCATION_URL  = 'https://mybusinessbusinessinformation.googleapis.com/v1/%s';
	const REVIEWS_URL   = 'https://mybusiness.googleapis.com/v4/%s/%s/reviews';

	const TOKEN_TRANSIENT = 'scout_reviews_google_token';

	/* ---- Stored state ---- */

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'client_id'      => '',
				'client_secret'  => '',
				'refresh_token'  => '',
				'account'        => '',
				'location'       => '',
				'location_title' => '',
				'last_sync'      => 0,
				'last_error'     => '',
			),
			is_array( $saved ) ? $saved : array()
		);
	}

	public static function update( array $changes ): void {
		update_option( self::OPTION, array_merge( self::get(), $changes ), false );
	}

	public static function client_id(): string {
		return defined( 'SCOUT_REVIEWS_GOOGLE_CLIENT_ID' ) ? (string) SCOUT_REVIEWS_GOOGLE_CLIENT_ID : self::get()['client_id'];
	}

	public static function client_secret(): string {
		return defined( 'SCOUT_REVIEWS_GOOGLE_CLIENT_SECRET' ) ? (string) SCOUT_REVIEWS_GOOGLE_CLIENT_SECRET : self::get()['client_secret'];
	}

	public static function credentials_in_config(): bool {
		return defined( 'SCOUT_REVIEWS_GOOGLE_CLIENT_ID' ) && defined( 'SCOUT_REVIEWS_GOOGLE_CLIENT_SECRET' );
	}

	public static function has_credentials(): bool {
		return '' !== self::client_id() && '' !== self::client_secret();
	}

	/** Signed in to Google (may still need a location picked). */
	public static function is_connected(): bool {
		return '' !== self::get()['refresh_token'];
	}

	/** Signed in and a location picked: ready to sync. */
	public static function is_ready(): bool {
		$s = self::get();
		return self::is_connected() && '' !== $s['account'] && '' !== $s['location'];
	}

	/**
	 * The exact URL to paste into Google Cloud as the authorized redirect URI.
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=scout_reviews_google_callback' );
	}

	/* ---- OAuth ---- */

	public static function auth_url( string $state ): string {
		return add_query_arg(
			array(
				'client_id'              => rawurlencode( self::client_id() ),
				'redirect_uri'           => rawurlencode( self::redirect_uri() ),
				'response_type'          => 'code',
				'scope'                  => rawurlencode( self::SCOPE ),
				'access_type'            => 'offline',
				'prompt'                 => 'consent', // Always return a refresh token.
				'include_granted_scopes' => 'true',
				'state'                  => rawurlencode( $state ),
			),
			self::AUTH_URL
		);
	}

	/**
	 * Trade the one-time code from Google's redirect for a refresh token.
	 *
	 * @return true|WP_Error
	 */
	public static function exchange_code( string $code ) {
		$data = self::token_request(
			array(
				'code'          => $code,
				'client_id'     => self::client_id(),
				'client_secret' => self::client_secret(),
				'redirect_uri'  => self::redirect_uri(),
				'grant_type'    => 'authorization_code',
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['refresh_token'] ) ) {
			return new WP_Error( 'scout_reviews_no_refresh', __( 'Google did not return a long-term sign-in. Disconnect, then connect again.', 'scout-reviews' ) );
		}
		self::update( array( 'refresh_token' => (string) $data['refresh_token'], 'last_error' => '' ) );
		self::cache_access_token( $data );
		return true;
	}

	/**
	 * A valid short-lived access token, refreshed when needed.
	 *
	 * @return string|WP_Error
	 */
	public static function access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$refresh = self::get()['refresh_token'];
		if ( '' === $refresh ) {
			return new WP_Error( 'scout_reviews_not_connected', __( 'Google is not connected.', 'scout-reviews' ) );
		}
		$data = self::token_request(
			array(
				'refresh_token' => $refresh,
				'client_id'     => self::client_id(),
				'client_secret' => self::client_secret(),
				'grant_type'    => 'refresh_token',
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return self::cache_access_token( $data );
	}

	private static function cache_access_token( array $data ): string {
		$token = (string) ( $data['access_token'] ?? '' );
		$ttl   = max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 120 );
		if ( '' !== $token ) {
			set_transient( self::TOKEN_TRANSIENT, $token, $ttl );
		}
		return $token;
	}

	/**
	 * @return array|WP_Error
	 */
	private static function token_request( array $body ) {
		$response = wp_remote_post( self::TOKEN_URL, array( 'timeout' => 15, 'body' => $body ) );
		$data     = self::decode( $response );
		if ( is_wp_error( $data ) ) {
			// invalid_grant means the sign-in was revoked or expired: start over.
			if ( 'invalid_grant' === $data->get_error_code() ) {
				self::update( array( 'refresh_token' => '' ) );
				delete_transient( self::TOKEN_TRANSIENT );
				return new WP_Error( 'invalid_grant', __( 'Google sign-in expired or was removed. Click Connect Google to sign in again.', 'scout-reviews' ) );
			}
		}
		return $data;
	}

	/**
	 * Sign out: revoke the token at Google and forget everything but the
	 * credentials.
	 */
	public static function disconnect(): void {
		$refresh = self::get()['refresh_token'];
		if ( '' !== $refresh ) {
			wp_remote_post( self::REVOKE_URL, array( 'timeout' => 10, 'body' => array( 'token' => $refresh ) ) );
		}
		delete_transient( self::TOKEN_TRANSIENT );
		self::update(
			array(
				'refresh_token'  => '',
				'account'        => '',
				'location'       => '',
				'location_title' => '',
				'last_error'     => '',
			)
		);
	}

	/* ---- API calls ---- */

	/**
	 * Every location this Google sign-in can manage, across all its accounts.
	 *
	 * @return array<int, array{account:string, location:string, title:string, address:string}>|WP_Error
	 */
	public static function locations() {
		$accounts = self::api_get( self::ACCOUNTS_URL );
		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}

		$out = array();
		foreach ( (array) ( $accounts['accounts'] ?? array() ) as $account ) {
			$account_name = (string) ( $account['name'] ?? '' ); // "accounts/123".
			if ( '' === $account_name ) {
				continue;
			}
			$page = '';
			do {
				$args = array( 'readMask' => 'name,title,storefrontAddress', 'pageSize' => 100 );
				if ( $page ) {
					$args['pageToken'] = $page;
				}
				$data = self::api_get( sprintf( self::LOCATIONS_URL, $account_name ), $args );
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				foreach ( (array) ( $data['locations'] ?? array() ) as $loc ) {
					$addr  = $loc['storefrontAddress'] ?? array();
					$out[] = array(
						'account'  => $account_name,
						'location' => (string) ( $loc['name'] ?? '' ), // "locations/456".
						'title'    => (string) ( $loc['title'] ?? '' ),
						'address'  => trim( (string) ( $addr['locality'] ?? '' ) . ', ' . (string) ( $addr['administrativeArea'] ?? '' ), ', ' ),
					);
				}
				$page = (string) ( $data['nextPageToken'] ?? '' );
			} while ( '' !== $page );
		}
		return $out;
	}

	/**
	 * The public Maps link and "write a review" link for the chosen location.
	 *
	 * @return array{maps:string, write:string}|WP_Error
	 */
	public static function location_links() {
		$data = self::api_get( sprintf( self::LOCATION_URL, self::get()['location'] ), array( 'readMask' => 'metadata' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return array(
			'maps'  => (string) ( $data['metadata']['mapsUri'] ?? '' ),
			'write' => (string) ( $data['metadata']['newReviewUri'] ?? '' ),
		);
	}

	/**
	 * One page of reviews, newest first.
	 *
	 * @return array|WP_Error Raw v4 response: reviews, averageRating, totalReviewCount, nextPageToken.
	 */
	public static function reviews_page( string $page_token = '' ) {
		$s    = self::get();
		$args = array( 'pageSize' => 50, 'orderBy' => 'updateTime desc' );
		if ( '' !== $page_token ) {
			$args['pageToken'] = $page_token;
		}
		return self::api_get( sprintf( self::REVIEWS_URL, $s['account'], $s['location'] ), $args );
	}

	/**
	 * @return array|WP_Error
	 */
	private static function api_get( string $url, array $args = array() ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$response = wp_remote_get(
			add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), $url ),
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		return self::decode( $response );
	}

	/**
	 * JSON body on 2xx, or a WP_Error carrying Google's own message.
	 *
	 * @param array|WP_Error $response
	 * @return array|WP_Error
	 */
	private static function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		if ( $code >= 200 && $code < 300 ) {
			return $data;
		}

		// Token endpoint errors: { error: "invalid_grant", error_description }.
		// API errors: { error: { code, message, status } }.
		if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
			return new WP_Error( $data['error'], (string) ( $data['error_description'] ?? $data['error'] ) );
		}
		$message = (string) ( $data['error']['message'] ?? '' );
		if ( 429 === $code || ( 403 === $code && false !== stripos( $message, 'quota' ) ) ) {
			$message = __( 'Google has not turned on API access for this project yet (quota is 0), or the daily limit was reached. Check the Google Cloud console.', 'scout-reviews' );
		}
		/* translators: 1: HTTP status code, 2: Google's error message. */
		return new WP_Error( 'scout_reviews_google_http', sprintf( __( 'Google returned an error (%1$d): %2$s', 'scout-reviews' ), $code, $message ? $message : __( 'no details', 'scout-reviews' ) ) );
	}
}
