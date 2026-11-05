<?php
/**
 * The security baseline in Site status (Site Standard rule 22): XML-RPC off,
 * file editing off, admins limited to named people with two-step login,
 * backups with a restore tested every quarter, an uptime alert, and security
 * headers.
 *
 * Every row only reads. Nothing here turns a setting on or off, and no header
 * is added or changed. The restore test date and the uptime alert are typed in
 * by an operator on Site status, because nothing on the site can know them.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Security {

	const OPTION     = 'scout_hub_security';         // { restore_tested: Y-m-d, uptime: yes|no|'', uptime_where, since }.
	const ACTION     = 'scout_hub_security';         // admin-post action and nonce.
	const HEADERS    = 'scout_hub_security_headers'; // Transient: { at, ok, missing[] }.
	const HOOK       = 'scout_hub_security_headers';
	const LAST_LOGIN = 'scout_hub_last_login';       // User meta on administrators: time of the last sign-in.

	const IDLE_DAYS    = 180;
	const RESTORE_DAYS = 92;

	/** Names that belong to nobody in particular. */
	const GENERIC = '/^(admin|administrator|test|user)\d*$/i';

	/** Two-step login plugins, by plugin folder. */
	const TWO_STEP = array(
		'two-factor'                        => 'Two Factor',
		'wordfence'                         => 'Wordfence',
		'wordfence-login-security'          => 'Wordfence Login Security',
		'wp-2fa'                            => 'WP 2FA',
		'miniorange-2-factor-authentication' => 'miniOrange',
		'better-wp-security'                => 'Solid Security',
		'ithemes-security-pro'              => 'Solid Security',
	);

	/** The four headers, in the order they are listed. */
	const HEADER_NAMES = array( 'Strict-Transport-Security', 'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy' );

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
		add_action( 'wp_login', array( __CLASS__, 'note_login' ), 10, 2 );
		add_action( self::HOOK, array( __CLASS__, 'scan_headers' ) );
		// Refreshed with the daily WordPress checks.
		add_action( Scout_Hub_Watch::HOOK, array( __CLASS__, 'scan_headers' ) );
	}

	/** Operator settings, with defaults. */
	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'restore_tested' => (string) ( $saved['restore_tested'] ?? '' ),
			'uptime'         => (string) ( $saved['uptime'] ?? '' ),
			'uptime_where'   => (string) ( $saved['uptime_where'] ?? '' ),
			'since'          => (int) ( $saved['since'] ?? 0 ),
		);
	}

	/** When Scout started noting admin sign-ins. Set the first time it is asked. */
	private static function since(): int {
		$s = self::settings();
		if ( ! $s['since'] ) {
			$s['since'] = time();
			update_option( self::OPTION, $s, false );
		}
		return $s['since'];
	}

	/** Note when an administrator signs in. Nothing is kept for anyone else. */
	public static function note_login( $login, $user = null ): void {
		if ( $user instanceof WP_User && in_array( 'administrator', (array) $user->roles, true ) ) {
			update_user_meta( $user->ID, self::LAST_LOGIN, time() );
		}
	}

	/** Save the restore test date and the uptime alert. Operators only. */
	public static function save(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( 'manage_options' ) || ! Scout_Hub_Access::is_operator() ) {
			wp_die( esc_html__( 'Only the people who look after this site can change this.', 'scout-build' ), 403 );
		}
		$s      = self::settings();
		$date   = sanitize_text_field( wp_unslash( (string) ( $_POST['restore_tested'] ?? '' ) ) );
		$uptime = sanitize_key( wp_unslash( (string) ( $_POST['uptime'] ?? '' ) ) );
		$s['restore_tested'] = self::clean_date( $date, time() );
		$s['uptime']         = in_array( $uptime, array( 'yes', 'no' ), true ) ? $uptime : '';
		$s['uptime_where']   = substr( sanitize_text_field( wp_unslash( (string) ( $_POST['uptime_where'] ?? '' ) ) ), 0, 200 );
		update_option( self::OPTION, $s, false );
		set_transient( 'scout_hub_notice_' . get_current_user_id(), array( 'message' => __( 'Saved.', 'scout-build' ), 'type' => 'success' ), MINUTE_IN_SECONDS );
		wp_safe_redirect( Scout_Hub_Admin::url( 'health' ) . '#security' );
		exit;
	}

	/** A Y-m-d date that is real and not in the future, or ''. */
	public static function clean_date( string $date, int $now ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return strtotime( $date . ' 00:00:00 UTC' ) <= $now ? $date : '';
	}

	/* ---- The rows ------------------------------------------------------ */

	/**
	 * The rows, for people who can see them: admins' names are listed, so only
	 * people who can manage the site get this section and its count on Home.
	 */
	public static function visible_rows(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}
		$rows = self::rows();
		if ( ! Scout_Hub_Access::is_operator() ) {
			// Restore test and Uptime alert are Scout's to set, so a client never sees a look they can't clear.
			$rows = array_values( array_filter( $rows, array( __CLASS__, 'client_row' ) ) );
		}
		return $rows;
	}

	/** True for a row a client can act on (everything but Restore test and Uptime alert). */
	public static function client_row( array $row ): bool {
		return ! in_array( $row['key'] ?? '', array( 'restore', 'uptime' ), true );
	}

	/**
	 * @return array<int, array{label:string, value:string, state:string, note:string, link?:string, link_text?:string}>
	 *         state is good | warn. Nothing here is ever "bad".
	 */
	public static function rows(): array {
		$core    = class_exists( 'Scout_Core_Protection' );
		$protect = admin_url( 'admin.php?page=scout&tab=protect' );
		$s       = self::settings();
		$admins  = self::admins();
		$plugins = self::two_step_plugins();
		$now     = time();
		return array(
			self::xmlrpc_row( (bool) apply_filters( 'xmlrpc_enabled', true ), $core && Scout_Core_Protection::xmlrpc_needed(), $core, $protect ),
			self::file_edit_row( ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ),
			self::admins_row( $admins, $now, self::since() ),
			self::two_step_row( $admins, $plugins ),
			self::backups_row( self::backups_by() ),
			self::restore_row( $s['restore_tested'], $now ),
			self::uptime_row( $s['uptime'], $s['uptime_where'] ),
			self::headers_row( self::headers_scan(), $now, $core, $core && Scout_Core_Protection::on( 'browser_headers' ), $protect ),
		);
	}

	public static function xmlrpc_row( bool $enabled, bool $jetpack, bool $core, string $protect ): array {
		$row = array( 'label' => __( 'XML-RPC', 'scout-build' ) );
		if ( ! $enabled ) {
			return $row + array(
				'value' => __( 'Off', 'scout-build' ),
				'state' => 'good',
				'note'  => __( 'Bots can\'t use it to guess passwords.', 'scout-build' ),
			);
		}
		if ( $jetpack ) {
			return $row + array(
				'value' => __( 'On for Jetpack', 'scout-build' ),
				'state' => 'good',
				'note'  => __( 'Jetpack needs XML-RPC, so it stays on.', 'scout-build' ),
			);
		}
		return $row + array(
			'value' => __( 'On', 'scout-build' ),
			'state' => 'warn',
			'note'  => $core
				? __( 'XML-RPC (an old way to post from apps) lets bots guess passwords. Turn it off in Spam protection.', 'scout-build' )
				: __( 'XML-RPC (an old way to post from apps) lets bots guess passwords. Turn on Scout Core to switch it off.', 'scout-build' ),
		) + ( $core ? array( 'link' => $protect, 'link_text' => __( 'Spam protection', 'scout-build' ) ) : array() );
	}

	public static function file_edit_row( bool $off ): array {
		return array(
			'label' => __( 'File editing', 'scout-build' ),
			'value' => $off ? __( 'Off', 'scout-build' ) : __( 'On', 'scout-build' ),
			'state' => $off ? 'good' : 'warn',
			'note'  => $off
				? __( 'Nobody can change theme or plugin code from inside WordPress.', 'scout-build' )
				: __( 'Admins can change theme and plugin code in WordPress. Turn it off in wp-config.php: define( \'DISALLOW_FILE_EDIT\', true );', 'scout-build' ),
		);
	}

	/**
	 * Administrators, by display name. Email addresses are never read.
	 *
	 * @return array<int, array{name:string, login:string, last:int, registered:int, two_step:?bool}>
	 */
	public static function admins(): array {
		$out     = array();
		$plugins = self::two_step_plugins();
		foreach ( get_users( array( 'role' => 'administrator', 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name', 'user_login', 'user_registered' ) ) ) as $u ) {
			$id    = (int) $u->ID;
			$out[] = array(
				'name'       => (string) $u->display_name,
				'login'      => (string) $u->user_login,
				'last'       => self::last_login( $id ),
				'registered' => (int) strtotime( (string) $u->user_registered . ' UTC' ),
				'two_step'   => $plugins ? self::uses_two_step( $id, $plugins ) : null,
			);
		}
		return $out;
	}

	/** The latest sign-in anything on the site recorded, or 0. */
	private static function last_login( int $id ): int {
		$last = (int) get_user_meta( $id, self::LAST_LOGIN, true );
		foreach ( array( 'last_login', 'wp-last-login' ) as $key ) {
			$v    = get_user_meta( $id, $key, true );
			$last = max( $last, is_numeric( $v ) ? (int) $v : (int) strtotime( (string) $v ) );
		}
		$tokens = get_user_meta( $id, 'session_tokens', true );
		foreach ( is_array( $tokens ) ? $tokens : array() as $t ) {
			$last = max( $last, (int) ( $t['login'] ?? 0 ) );
		}
		return $last;
	}

	/**
	 * @param array $admins From admins().
	 * @param int   $since  When sign-ins started being noted here.
	 */
	public static function admins_row( array $admins, int $now, int $since ): array {
		$idle  = $now - self::IDLE_DAYS * DAY_IN_SECONDS;
		$flags = array();
		foreach ( $admins as $a ) {
			if ( preg_match( self::GENERIC, trim( $a['name'] ) ) || preg_match( self::GENERIC, trim( $a['login'] ) ) ) {
				/* translators: %s: display name */
				$flags[] = sprintf( __( '%s (a generic name)', 'scout-build' ), $a['name'] );
			} elseif ( $a['last'] ? $a['last'] < $idle : ( $since < $idle && $a['registered'] < $idle ) ) {
				/* translators: %s: display name */
				$flags[] = sprintf( __( '%s (no sign-in in 180 days)', 'scout-build' ), $a['name'] );
			}
		}
		$names = implode( ', ', array_column( $admins, 'name' ) );
		$row   = array(
			'label' => __( 'Admins', 'scout-build' ),
			/* translators: %d: number of admins */
			'value' => sprintf( _n( '%d admin', '%d admins', count( $admins ), 'scout-build' ), count( $admins ) ),
			'state' => $flags ? 'warn' : 'good',
		);
		if ( ! $flags ) {
			/* translators: %s: display names */
			return $row + array( 'note' => sprintf( __( 'Each one is a named person: %s.', 'scout-build' ), $names ) );
		}
		return $row + array(
			/* translators: %s: flagged admins */
			'note'      => sprintf( __( 'Check these: %s. Each admin should be a named person who still needs it.', 'scout-build' ), implode( '; ', $flags ) ),
			'link'      => admin_url( 'users.php?role=administrator' ),
			'link_text' => __( 'Users', 'scout-build' ),
		);
	}

	/** Names of the two-step login plugins that are on. */
	public static function two_step_plugins(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$on = array();
		foreach ( $active as $file ) {
			$dir = strtok( (string) $file, '/' );
			if ( isset( self::TWO_STEP[ $dir ] ) ) {
				$on[ self::TWO_STEP[ $dir ] ] = true;
			}
		}
		if ( class_exists( 'Two_Factor_Core' ) ) {
			$on['Two Factor'] = true;
		}
		return array_keys( $on );
	}

	/**
	 * Whether one admin has two-step login on: true, false, or null when no
	 * plugin that is on says. Other plugins can answer with the
	 * scout_hub_two_step_user filter.
	 */
	private static function uses_two_step( int $id, array $plugins ): ?bool {
		$answers = array();
		try {
			if ( class_exists( 'Two_Factor_Core' ) && method_exists( 'Two_Factor_Core', 'is_user_using_two_factor' ) ) {
				$answers[] = (bool) Two_Factor_Core::is_user_using_two_factor( $id );
			}
			if ( class_exists( '\WordfenceLS\Controller_Users' ) && method_exists( '\WordfenceLS\Controller_Users', 'shared' ) ) {
				$wf = \WordfenceLS\Controller_Users::shared();
				$user = get_userdata( $id );
				if ( $user && method_exists( $wf, 'has_2fa_active' ) ) {
					$answers[] = (bool) $wf->has_2fa_active( $user );
				}
			}
			if ( class_exists( '\WP2FA\Admin\Helpers\User_Helper' ) && method_exists( '\WP2FA\Admin\Helpers\User_Helper', 'get_enabled_method_for_user' ) ) {
				$user = get_userdata( $id );
				if ( $user ) {
					$answers[] = '' !== (string) \WP2FA\Admin\Helpers\User_Helper::get_enabled_method_for_user( $user );
				}
			}
			if ( class_exists( 'ITSEC_Two_Factor' ) && method_exists( 'ITSEC_Two_Factor', 'get_instance' ) ) {
				$itsec = ITSEC_Two_Factor::get_instance();
				if ( method_exists( $itsec, 'is_user_using_two_factor' ) ) {
					$answers[] = (bool) $itsec->is_user_using_two_factor( $id );
				}
			}
		} catch ( Throwable $e ) {
			$answers[] = null;
		}
		/**
		 * Lets another two-step login plugin say whether this user has it on.
		 *
		 * @param bool|null $answer  Null when not known.
		 * @param int       $user_id The administrator.
		 */
		$extra = apply_filters( 'scout_hub_two_step_user', null, $id );
		if ( null !== $extra ) {
			$answers[] = (bool) $extra;
		}
		if ( in_array( true, $answers, true ) ) {
			return true;
		}
		// Every plugin that is on gave an answer, and every answer was no.
		$covered = count( array_filter( $answers, static fn( $a ) => false === $a ) );
		return $answers && $covered >= count( $plugins ) && ! in_array( null, $answers, true ) ? false : null;
	}

	/**
	 * @param array    $admins  From admins(), with two_step true, false or null.
	 * @param string[] $plugins Two-step login plugins that are on.
	 */
	public static function two_step_row( array $admins, array $plugins ): array {
		$row = array( 'label' => __( 'Two-step login', 'scout-build' ) );
		if ( ! $plugins ) {
			return $row + array(
				'value'     => __( 'None found', 'scout-build' ),
				'state'     => 'warn',
				'note'      => __( 'No two-step login plugin is on. Add Two Factor and turn it on for every admin.', 'scout-build' ),
				'link'      => admin_url( 'plugin-install.php?s=two-factor&tab=search&type=term' ),
				'link_text' => __( 'Add Two Factor', 'scout-build' ),
			);
		}
		$names   = implode( ', ', $plugins );
		$off     = array_values( array_filter( $admins, static fn( $a ) => false === $a['two_step'] ) );
		$unknown = array_filter( $admins, static fn( $a ) => null === $a['two_step'] );
		if ( $off ) {
			return $row + array(
				/* translators: %d: number of admins */
				'value'     => sprintf( _n( 'Off for %d', 'Off for %d', count( $off ), 'scout-build' ), count( $off ) ),
				'state'     => 'warn',
				/* translators: 1: plugin names, 2: display names */
				'note'      => sprintf( __( '%1$s is on, but these admins haven\'t set it up: %2$s.', 'scout-build' ), $names, implode( ', ', array_column( $off, 'name' ) ) ),
				'link'      => admin_url( 'users.php?role=administrator' ),
				'link_text' => __( 'Users', 'scout-build' ),
			);
		}
		if ( $unknown || ! $admins ) {
			return $row + array(
				'value' => __( 'Can\'t tell from here', 'scout-build' ),
				'state' => 'warn',
				/* translators: %s: plugin names */
				'note'  => sprintf( __( '%s is on, but it doesn\'t say which admins use it. Check on its own screen.', 'scout-build' ), $names ),
			);
		}
		return $row + array(
			/* translators: %d: number of admins */
			'value' => sprintf( _n( 'On for %d', 'On for all %d', count( $admins ), 'scout-build' ), count( $admins ) ),
			'state' => 'good',
			/* translators: %s: plugin names */
			'note'  => sprintf( __( 'Every admin signs in with a second step (%s).', 'scout-build' ), $names ),
		);
	}

	/** Who backs the site up, when the site can tell: WP Engine, or a name from the scout_hub_backups filter. */
	public static function backups_by(): string {
		$wpe = ( function_exists( 'is_wpe' ) && is_wpe() ) || defined( 'WPE_APIKEY' ) || ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR . '/wpengine-common' ) );
		/**
		 * The host or plugin that backs this site up every day, when it is not WP Engine.
		 *
		 * @param string $by Empty when not known.
		 */
		return (string) apply_filters( 'scout_hub_backups', $wpe ? 'WP Engine' : '' );
	}

	public static function backups_row( string $by ): array {
		$row = array( 'label' => __( 'Backups', 'scout-build' ) );
		if ( 'WP Engine' === $by ) {
			return $row + array( 'value' => __( 'Daily', 'scout-build' ), 'state' => 'good', 'note' => __( 'WP Engine backs up daily.', 'scout-build' ) );
		}
		if ( '' !== $by ) {
			/* translators: %s: host or plugin */
			return $row + array( 'value' => __( 'Daily', 'scout-build' ), 'state' => 'good', 'note' => sprintf( __( '%s backs up daily.', 'scout-build' ), $by ) );
		}
		return $row + array(
			'value' => __( 'Not known', 'scout-build' ),
			'state' => 'warn',
			'note'  => __( 'This site can\'t see its backups. Confirm the host or a backup plugin saves a copy every day.', 'scout-build' ),
		);
	}

	public static function restore_row( string $date, int $now ): array {
		$row = array( 'key' => 'restore', 'label' => __( 'Restore test', 'scout-build' ) );
		$at  = '' !== $date ? (int) strtotime( $date . ' 00:00:00 UTC' ) : 0;
		if ( ! $at ) {
			return $row + array(
				'value' => __( 'Never', 'scout-build' ),
				'state' => 'warn',
				'note'  => __( 'Restore a backup to a staging copy each quarter to prove it works.', 'scout-build' ),
			);
		}
		$days = max( 0, (int) floor( ( $now - $at ) / DAY_IN_SECONDS ) );
		$old  = $days > self::RESTORE_DAYS;
		return $row + array(
			/* translators: %d: days */
			'value' => 0 === $days ? __( 'Today', 'scout-build' ) : sprintf( _n( '%d day ago', '%d days ago', $days, 'scout-build' ), $days ),
			'state' => $old ? 'warn' : 'good',
			'note'  => $old
				? __( 'Over three months ago. Restore a backup to staging to prove it still works.', 'scout-build' )
				/* translators: %s: date */
				: sprintf( __( 'A backup was restored and checked on %s.', 'scout-build' ), $date ),
		);
	}

	public static function uptime_row( string $on, string $where ): array {
		$row = array( 'key' => 'uptime', 'label' => __( 'Uptime alert', 'scout-build' ) );
		if ( 'yes' === $on ) {
			return $row + array(
				'value' => __( 'On', 'scout-build' ),
				'state' => 'good',
				/* translators: %s: where alerts go */
				'note'  => '' !== trim( $where ) ? sprintf( __( 'Alerts go to %s.', 'scout-build' ), trim( $where ) ) : __( 'Someone hears when the site goes down.', 'scout-build' ),
			);
		}
		return $row + array(
			'value' => 'no' === $on ? __( 'Off', 'scout-build' ) : __( 'Not set', 'scout-build' ),
			'state' => 'warn',
			'note'  => __( 'Set up an alert that tells someone when the site goes down.', 'scout-build' ),
		);
	}

	/* ---- Security headers ---------------------------------------------- */

	/**
	 * Which of the four headers a response leaves out. A Content-Security-Policy
	 * with frame-ancestors counts for X-Frame-Options.
	 *
	 * @param array<string,string> $headers Header names in lowercase.
	 * @return string[]
	 */
	public static function header_gaps( array $headers ): array {
		$h   = array_change_key_case( $headers, CASE_LOWER );
		$has = array(
			'Strict-Transport-Security' => isset( $h['strict-transport-security'] ),
			'X-Content-Type-Options'    => isset( $h['x-content-type-options'] ),
			'X-Frame-Options'           => isset( $h['x-frame-options'] ) || (bool) preg_match( '/frame-ancestors/i', (string) ( $h['content-security-policy'] ?? '' ) ),
			'Referrer-Policy'           => isset( $h['referrer-policy'] ),
		);
		return array_keys( array_filter( $has, static fn( $v ) => ! $v ) );
	}

	/** Load the home page's headers once and keep what is missing for 12 hours. Only reads. */
	public static function scan_headers(): void {
		$r = wp_remote_head( home_url( '/' ), array( 'timeout' => 5, 'redirection' => 3, 'sslverify' => false ) );
		if ( is_wp_error( $r ) || ! (int) wp_remote_retrieve_response_code( $r ) ) {
			set_transient( self::HEADERS, array( 'at' => time(), 'ok' => false, 'missing' => array() ), HOUR_IN_SECONDS );
			return;
		}
		$h = array();
		foreach ( wp_remote_retrieve_headers( $r ) as $name => $value ) {
			$h[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		set_transient( self::HEADERS, array( 'at' => time(), 'ok' => true, 'missing' => self::header_gaps( $h ) ), 12 * HOUR_IN_SECONDS );
	}

	/** The last header check, or null while the first one runs in the background. */
	public static function headers_scan(): ?array {
		$scan = get_transient( self::HEADERS );
		if ( is_array( $scan ) ) {
			return $scan;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
			spawn_cron();
		}
		return null;
	}

	/**
	 * @param array|null $scan       From headers_scan().
	 * @param bool       $core       Scout Core is running.
	 * @param bool       $core_sends Scout Core's "Send basic browser protections" is on.
	 */
	public static function headers_row( ?array $scan, int $now, bool $core, bool $core_sends, string $protect ): array {
		$row = array( 'label' => __( 'Security headers', 'scout-build' ) );
		if ( null === $scan ) {
			return $row + array( 'value' => __( 'Checking', 'scout-build' ), 'state' => 'good', 'note' => __( 'Checking the home page now. Reload in a minute.', 'scout-build' ) );
		}
		if ( empty( $scan['ok'] ) ) {
			return $row + array(
				'value' => __( 'Couldn\'t check', 'scout-build' ),
				'state' => 'warn',
				'note'  => __( 'The site couldn\'t load its own home page, often a staging password. It tries again in an hour.', 'scout-build' ),
			);
		}
		/* translators: %s: time since, e.g. "3 hours" */
		$when    = sprintf( __( 'Checked %s ago.', 'scout-build' ), human_time_diff( (int) $scan['at'], $now ) );
		$missing = array_values( (array) ( $scan['missing'] ?? array() ) );
		if ( ! $missing ) {
			return $row + array( 'value' => __( 'All 4 sent', 'scout-build' ), 'state' => 'good', 'note' => __( 'The home page sends all four browser protections.', 'scout-build' ) . ' ' . $when );
		}
		$fix = $core && ! $core_sends
			? __( 'Turn on "Send basic browser protections" in Spam protection.', 'scout-build' )
			: __( 'Ask the host to add them.', 'scout-build' );
		return $row + array(
			/* translators: %d: number of headers */
			'value' => sprintf( _n( '%d missing', '%d missing', count( $missing ), 'scout-build' ), count( $missing ) ),
			'state' => 'warn',
			/* translators: %s: header names */
			'note'  => sprintf( __( 'Missing: %s.', 'scout-build' ), implode( ', ', $missing ) ) . ' ' . $fix . ' ' . $when,
		) + ( $core && ! $core_sends ? array( 'link' => $protect, 'link_text' => __( 'Spam protection', 'scout-build' ) ) : array() );
	}

	/* ---- Operator form ------------------------------------------------- */

	/** The restore test and uptime alert form on Site status. Operators only. */
	public static function render_form(): void {
		if ( ! current_user_can( 'manage_options' ) || ! Scout_Hub_Access::is_operator() ) {
			return;
		}
		$s = self::settings();
		?>
		<section class="sh-box" style="margin-top:12px">
			<h3 class="sh-title"><?php esc_html_e( 'Restore test & uptime alert', 'scout-build' ); ?></h3>
			<p class="sh-note"><?php esc_html_e( 'Only people who look after the site see this.', 'scout-build' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<label for="sh-sec-restore"><?php esc_html_e( 'Last restore test', 'scout-build' ); ?></label>
				<input type="date" id="sh-sec-restore" name="restore_tested" value="<?php echo esc_attr( $s['restore_tested'] ); ?>" max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
				<label for="sh-sec-uptime"><?php esc_html_e( 'Uptime alert', 'scout-build' ); ?></label>
				<select id="sh-sec-uptime" name="uptime">
					<option value="" <?php selected( $s['uptime'], '' ); ?>><?php esc_html_e( 'Not set', 'scout-build' ); ?></option>
					<option value="yes" <?php selected( $s['uptime'], 'yes' ); ?>><?php esc_html_e( 'Yes', 'scout-build' ); ?></option>
					<option value="no" <?php selected( $s['uptime'], 'no' ); ?>><?php esc_html_e( 'No', 'scout-build' ); ?></option>
				</select>
				<label for="sh-sec-where"><?php esc_html_e( 'Where alerts go', 'scout-build' ); ?></label>
				<input type="text" id="sh-sec-where" name="uptime_where" maxlength="200" value="<?php echo esc_attr( $s['uptime_where'] ); ?>" placeholder="<?php esc_attr_e( 'UptimeRobot, emails Sean', 'scout-build' ); ?>">
				<p><button type="submit" class="button"><?php esc_html_e( 'Save', 'scout-build' ); ?></button></p>
			</form>
		</section>
		<?php
	}
}
