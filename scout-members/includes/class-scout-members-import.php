<?php
/**
 * Bring current members in from a spreadsheet, such as a Squarespace orders
 * or members export. Each person becomes an active member on the plan named
 * in the file. Their latest payment sets "last paid", and the renewal date is
 * one plan period after it unless the file gives one. Every run starts as a
 * preview, and running the same file twice changes nothing.
 *
 * @package Scout_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Members_Import {

	const MAX_ROWS = 5000;

	/** Column names people's exports use, mapped to ours. */
	const ALIASES = array(
		'email'          => 'email',
		'email address'  => 'email',
		'customer email' => 'email',
		'name'           => 'name',
		'customer name'  => 'name',
		'billing name'   => 'name',
		'first name'     => 'first',
		'last name'      => 'last',
		'pricing plan'   => 'plan',
		'plan'           => 'plan',
		'membership'     => 'plan',
		'product'        => 'plan',
		'order date'     => 'date',
		'date'           => 'date',
		'created'        => 'date',
		'created at'     => 'date',
		'paid on'        => 'date',
		'paid at'        => 'date',
		'total'          => 'total',
		'amount'         => 'total',
		'renews'         => 'renews',
		'renewal date'   => 'renews',
		'next billing'   => 'renews',
		'since'          => 'since',
		'member since'   => 'since',
	);

	/**
	 * @return array{dry:bool,created:int,updated:int,notes:string[]}
	 */
	public static function run( string $file, bool $dry, bool $welcome ): array {
		$report = array(
			'dry'     => $dry,
			'created' => 0,
			'updated' => 0,
			'notes'   => array(),
		);
		$people = self::people( $file, $report['notes'] );
		$plans  = Scout_Members::plans();
		foreach ( $people as $email => $p ) {
			$plan = '' !== $p['plan'] ? Scout_Members::plan_by_name( $p['plan'] ) : '';
			if ( '' !== $p['plan'] && '' === $plan ) {
				/* translators: 1: plan name in the file, 2: email. */
				$report['notes'][] = sprintf( __( 'Plan "%1$s" for %2$s is not in Settings, so they come in without a plan. Add the plan and import again.', 'scout-members' ), $p['plan'], $email );
			}
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				++$report['updated'];
			} else {
				++$report['created'];
			}
			if ( $dry ) {
				continue;
			}
			if ( ! $user ) {
				$login   = sanitize_user( strtok( $email, '@' ), true );
				$login   = '' === $login || username_exists( $login ) ? $login . wp_rand( 100, 999 ) : $login;
				$user_id = wp_insert_user(
					array(
						'user_login'   => $login,
						'user_email'   => $email,
						'user_pass'    => wp_generate_password( 24 ),
						'first_name'   => $p['first'],
						'last_name'    => $p['last'],
						'display_name' => trim( $p['first'] . ' ' . $p['last'] ) ?: $email, // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
						'role'         => Scout_Members::ROLE,
					)
				);
				if ( is_wp_error( $user_id ) ) {
					/* translators: 1: email, 2: error. */
					$report['notes'][] = sprintf( __( '%1$s was not added: %2$s', 'scout-members' ), $email, $user_id->get_error_message() );
					--$report['created'];
					continue;
				}
				$user_id = (int) $user_id;
				if ( $welcome ) {
					wp_new_user_notification( $user_id, null, 'user' );
				}
			} else {
				$user_id = (int) $user->ID;
			}
			$current = Scout_Members::member( $user_id );
			$renews  = $p['renews'];
			if ( '' === $renews && '' !== $p['date'] ) {
				$period = $plans[ $plan ]['period'] ?? 'year';
				$renews = 'once' === $period ? '' : gmdate( 'Y-m-d', strtotime( $p['date'] . ' +1 ' . $period ) );
			}
			$first  = '' !== $p['since'] ? $p['since'] : $p['first_date'];
			Scout_Members::save(
				$user_id,
				array_filter(
					array(
						'plan'   => $plan,
						'since'  => '' !== $current['since'] && ( '' === $first || $current['since'] < $first ) ? $current['since'] : $first,
						'paid'   => $p['paid'],
						'renews' => $renews,
					),
					static fn( $v ) => '' !== $v
				)
			);
			if ( 'active' !== $current['status'] ) {
				Scout_Members::set_status( $user_id, 'active' );
			}
		}
		if ( ! $people && ! $report['notes'] ) {
			$report['notes'][] = __( 'No rows with an email address were found.', 'scout-members' );
		}
		return $report;
	}

	/**
	 * One entry per email, keeping the latest payment.
	 *
	 * @return array<string,array{first:string,last:string,plan:string,date:string,first_date:string,paid:string,renews:string,since:string}>
	 */
	private static function people( string $file, array &$notes ): array {
		$fh = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading an uploaded CSV.
		if ( ! $fh ) {
			$notes[] = __( 'The file could not be read.', 'scout-members' );
			return array();
		}
		$head = fgetcsv( $fh, 0, ',', '"', '\\' );
		if ( ! $head ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return array();
		}
		$cols = array();
		foreach ( $head as $i => $h ) {
			$h = strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) );
			if ( isset( self::ALIASES[ $h ] ) && ! in_array( self::ALIASES[ $h ], $cols, true ) ) {
				$cols[ $i ] = self::ALIASES[ $h ];
			}
		}
		if ( ! in_array( 'email', $cols, true ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$notes[] = __( 'The file needs an Email column.', 'scout-members' );
			return array();
		}
		$out = array();
		$n   = 0;
		while ( ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false && $n < self::MAX_ROWS ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$n;
			$r = array();
			foreach ( $cols as $i => $key ) {
				$r[ $key ] = trim( (string) ( $row[ $i ] ?? '' ) );
			}
			$email = sanitize_email( $r['email'] ?? '' );
			if ( ! is_email( $email ) ) {
				continue;
			}
			$email = strtolower( $email );
			$first = sanitize_text_field( $r['first'] ?? '' );
			$last  = sanitize_text_field( $r['last'] ?? '' );
			if ( '' === $first && '' !== ( $r['name'] ?? '' ) ) {
				$bits  = preg_split( '/\s+/', sanitize_text_field( $r['name'] ), 2 );
				$first = $bits[0];
				$last  = $bits[1] ?? '';
			}
			$date   = self::date( $r['date'] ?? '' );
			$amount = (float) preg_replace( '/[^0-9.]/', '', $r['total'] ?? '' );
			$prev   = $out[ $email ] ?? null;
			if ( $prev && '' !== $prev['date'] && ( '' === $date || $date < $prev['date'] ) ) {
				// An older row: it can only make "member since" earlier.
				if ( '' !== $date && $date < $prev['first_date'] ) {
					$out[ $email ]['first_date'] = $date;
				}
				continue;
			}
			$out[ $email ] = array(
				'first'      => '' !== $first ? $first : ( $prev['first'] ?? '' ),
				'last'       => '' !== $last ? $last : ( $prev['last'] ?? '' ),
				'plan'       => sanitize_text_field( $r['plan'] ?? '' ),
				'date'       => $date,
				'first_date' => $prev && '' !== $prev['first_date'] && $prev['first_date'] < $date ? $prev['first_date'] : $date,
				'paid'       => $amount > 0 ? $date : ( $prev['paid'] ?? '' ),
				'renews'     => self::date( $r['renews'] ?? '' ),
				'since'      => self::date( $r['since'] ?? '' ),
			);
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $out;
	}

	/** Any date a spreadsheet writes, as YYYY-MM-DD, or an empty string. */
	private static function date( string $d ): string {
		$d = trim( $d );
		if ( '' === $d ) {
			return '';
		}
		$clean = Scout_Members::clean_date( substr( $d, 0, 10 ) );
		if ( '' !== $clean ) {
			return $clean;
		}
		$ts = strtotime( $d );
		return $ts ? gmdate( 'Y-m-d', $ts ) : '';
	}
}
