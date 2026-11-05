<?php
/**
 * The firm's disclaimer lines: the wording, where each line shows, and the
 * firm's approval of the exact wording.
 *
 * Starting wording comes per state (North Carolina, Virginia, Florida, DC).
 * It is a starting point the firm edits and approves, never a statement that
 * any wording satisfies a bar rule. A line always prints something: a blank
 * setting falls back to the starting wording, so a page never loses its
 * disclaimer. An approval belongs to one exact wording; changing the words
 * after approval marks the line "Changed since approval" until the firm
 * approves again. Every approval is kept as the firm's advertising record.
 *
 * @package Scout_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Law_Disclaimers {

	const OPTION = 'scout_law_disclaimers';
	const LOG    = 'scout_law_approval_log';

	/** States with their own starting wording. */
	public static function states(): array {
		return array(
			'nc'    => __( 'North Carolina', 'scout-law' ),
			'va'    => __( 'Virginia', 'scout-law' ),
			'fl'    => __( 'Florida', 'scout-law' ),
			'dc'    => __( 'Washington, DC', 'scout-law' ),
			'other' => __( 'Another state', 'scout-law' ),
		);
	}

	/** Places a line can show. */
	public static function places(): array {
		return array(
			'footer'  => __( 'The footer of every page', 'scout-law' ),
			'forms'   => __( 'Under every form', 'scout-law' ),
			'results' => __( 'Next to case results', 'scout-law' ),
			'reviews' => __( 'Under reviews', 'scout-law' ),
		);
	}

	/**
	 * The lines, in the order the screen shows them.
	 *
	 * @return array<string,array{label:string,help:string,places:string[]}>
	 */
	public static function lines(): array {
		return array(
			'relationship' => array(
				'label'  => __( 'Not legal advice', 'scout-law' ),
				'help'   => __( 'Says the website is general information and that contacting the firm does not make it the visitor\'s lawyer.', 'scout-law' ),
				'places' => array( 'footer' ),
			),
			'forms'        => array(
				'label'  => __( 'Before someone sends a form', 'scout-law' ),
				'help'   => __( 'Asks people not to send confidential details through the website.', 'scout-law' ),
				'places' => array( 'forms' ),
			),
			'advertising'  => array(
				'label'  => __( 'Attorney advertising', 'scout-law' ),
				'help'   => __( 'A short label some firms show on their website. Your firm decides whether it shows.', 'scout-law' ),
				'places' => array( 'footer' ),
			),
			'results'      => array(
				'label'  => __( 'Prior results', 'scout-law' ),
				'help'   => __( 'Printed next to every case result. Replaces the wording on the Case results screen while this plugin is on.', 'scout-law' ),
				'places' => array( 'results' ),
			),
			'reviews'      => array(
				'label'  => __( 'Reviews and testimonials', 'scout-law' ),
				'help'   => __( 'Printed under the reviews on the site.', 'scout-law' ),
				'places' => array( 'reviews' ),
			),
		);
	}

	/** Starting wording for a line in a state. */
	public static function starting( string $line, string $state ): string {
		$results = array(
			'nc'    => __( 'Prior results do not guarantee a similar outcome. Every case is different and depends on its own facts.', 'scout-law' ),
			'va'    => __( 'Prior results do not guarantee a similar outcome. Each case depends on its own facts and circumstances.', 'scout-law' ),
			'fl'    => __( 'Prior results do not guarantee a similar outcome. Every case is different, and the result in any case depends on its own facts and the law that applies.', 'scout-law' ),
			'dc'    => __( 'Prior results do not guarantee a similar outcome. Every case depends on its own facts.', 'scout-law' ),
			'other' => __( 'Prior results do not guarantee a similar outcome.', 'scout-law' ),
		);
		$text = array(
			'relationship' => __( 'The information on this website is general information, not legal advice. Contacting us does not create an attorney-client relationship.', 'scout-law' ),
			'forms'        => __( 'Please don\'t include confidential details in this form. Sending it doesn\'t make us your lawyers.', 'scout-law' ),
			'advertising'  => __( 'Attorney advertising.', 'scout-law' ),
			'results'      => $results[ $state ] ?? $results['other'],
			'reviews'      => __( 'These reviews reflect the experiences of individual clients. Every case is different, and prior results do not guarantee a similar outcome.', 'scout-law' ),
		);
		/** Filter the starting wording for a line and state. */
		return (string) apply_filters( 'scout_law_starting_wording', $text[ $line ] ?? '', $line, $state );
	}

	/** Everything saved, with defaults filled in. */
	public static function settings(): array {
		$s     = get_option( self::OPTION, array() );
		$s     = is_array( $s ) ? $s : array();
		$state = isset( $s['state'], self::states()[ $s['state'] ] ) ? (string) $s['state'] : 'nc';
		$lines = array();
		foreach ( self::lines() as $key => $def ) {
			$saved         = isset( $s['lines'][ $key ] ) && is_array( $s['lines'][ $key ] ) ? $s['lines'][ $key ] : null;
			$lines[ $key ] = array(
				'text'   => (string) ( $saved['text'] ?? '' ),
				'places' => null === $saved ? $def['places'] : array_values( array_intersect( (array) ( $saved['places'] ?? array() ), array_keys( self::places() ) ) ),
			);
		}
		return array(
			'state'     => $state,
			'lines'     => $lines,
			'approvals' => isset( $s['approvals'] ) && is_array( $s['approvals'] ) ? $s['approvals'] : array(),
		);
	}

	/** The wording a line prints. Never empty. */
	public static function text( string $line ): string {
		$s     = self::settings();
		$start = self::starting( $line, $s['state'] );
		$text  = trim( (string) ( $s['lines'][ $line ]['text'] ?? '' ) );
		$text  = '' !== $text ? $text : $start;
		/** Filter the wording a line prints. An empty return falls back to the starting wording. */
		$out = trim( (string) apply_filters( 'scout_law_disclaimer_text', $text, $line ) );
		return '' !== $out ? $out : $start;
	}

	/** Where a line shows. */
	public static function places_for( string $line ): array {
		return self::settings()['lines'][ $line ]['places'] ?? array();
	}

	/** Lines that show in a place, in screen order. */
	public static function in_place( string $place ): array {
		$out = array();
		foreach ( array_keys( self::lines() ) as $key ) {
			if ( in_array( $place, self::places_for( $key ), true ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/** A fingerprint of one exact wording, ignoring extra spaces. */
	public static function fingerprint( string $text ): string {
		return md5( trim( (string) preg_replace( '/\s+/', ' ', $text ) ) );
	}

	/**
	 * Where a line stands with the firm.
	 *
	 * @return string approved | changed | waiting
	 */
	public static function status( string $line ): string {
		$approval = self::settings()['approvals'][ $line ] ?? null;
		if ( ! is_array( $approval ) || empty( $approval['hash'] ) ) {
			return 'waiting';
		}
		return hash_equals( (string) $approval['hash'], self::fingerprint( self::text( $line ) ) ) ? 'approved' : 'changed';
	}

	/** The approval on file for a line, or an empty array. */
	public static function approval( string $line ): array {
		$a = self::settings()['approvals'][ $line ] ?? array();
		return is_array( $a ) ? $a : array();
	}

	/** Lines that show somewhere and still need the firm's approval of their current wording. */
	public static function waiting(): array {
		$out = array();
		foreach ( array_keys( self::lines() ) as $key ) {
			if ( self::places_for( $key ) && 'approved' !== self::status( $key ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Record the firm's approval of a line's current wording, and keep it in the log.
	 *
	 * @param string $line    Line key.
	 * @param string $by      The approver's name, as the firm gives it.
	 * @param int    $user_id Who recorded it in WordPress.
	 */
	public static function approve( string $line, string $by, int $user_id ): bool {
		$by = trim( $by );
		if ( '' === $by || ! isset( self::lines()[ $line ] ) ) {
			return false;
		}
		$text = self::text( $line );
		$now  = time();
		$s    = get_option( self::OPTION, array() );
		$s    = is_array( $s ) ? $s : array();

		$s['approvals'][ $line ] = array(
			'hash' => self::fingerprint( $text ),
			'by'   => $by,
			'at'   => $now,
			'user' => $user_id,
		);
		update_option( self::OPTION, $s );

		$log   = get_option( self::LOG, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'line'   => $line,
			'text'   => $text,
			'places' => self::places_for( $line ),
			'by'     => $by,
			'at'     => $now,
			'user'   => $user_id,
		);
		update_option( self::LOG, $log, false );
		/** A disclaimer line was approved. The Site log can listen. */
		do_action( 'scout_law_disclaimer_approved', $line, $by, $text );
		return true;
	}

	/** Every approval on record, newest first. */
	public static function log(): array {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}
}
