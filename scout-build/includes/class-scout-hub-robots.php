<?php
/**
 * The robots.txt file on the server.
 *
 * WordPress writes robots.txt on the fly, and Scout features (AI search, the
 * sitemap guard) add to it. A real robots.txt file in the site's root folder
 * wins over all of that: the web server sends the file and WordPress never
 * runs. Yoast's file editor leaves one behind the first time anyone uses it.
 *
 * This screen compares that file with what WordPress would send, and when no
 * rule in the file would be lost, swaps to WordPress's version with one click.
 * The file is renamed (kept as a backup next to it), never deleted, and can be
 * put back the same way. A file with rules WordPress would not repeat is left
 * alone for a person to review.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Robots {

	const PAGE    = 'site-build-robots';
	const ADOPT   = 'scout_hub_robots_adopt';
	const RESTORE = 'scout_hub_robots_restore';
	const BACKUP  = 'robots.txt.scout-backup-';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 120 );
		add_action( 'admin_post_' . self::ADOPT, array( __CLASS__, 'adopt' ) );
		add_action( 'admin_post_' . self::RESTORE, array( __CLASS__, 'restore' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** A page with no menu item: reached from the Site health card. */
	public static function menu(): void {
		add_submenu_page( '', __( 'robots.txt file', 'scout-build' ), '', 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::PAGE ) ) {
			Scout_Admin_UI::enqueue( SCOUT_HUB_URL . 'assets/scout-admin.css' );
		}
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	public static function file(): string {
		return ABSPATH . 'robots.txt';
	}

	public static function has_file(): bool {
		return file_exists( self::file() );
	}

	/** What WordPress would send with no file in the way (core's base plus every robots_txt filter). */
	public static function generated(): string {
		$public = (string) get_option( 'blog_public' );
		$path   = (string) wp_parse_url( site_url(), PHP_URL_PATH );
		$base   = "User-agent: *\n" . ( '0' === $public ? "Disallow: /\n" : "Disallow: {$path}/wp-admin/\nAllow: {$path}/wp-admin/admin-ajax.php\n" );
		return (string) apply_filters( 'robots_txt', $base, $public );
	}

	/**
	 * Read robots.txt into the rules each crawler would follow.
	 * Groups that name the same crawler are combined, as crawlers do.
	 *
	 * @return array{agents: array<string, string[]>, other: string[]}
	 */
	public static function parse( string $text ): array {
		$agents  = array();
		$other   = array();
		$current = array();
		$in_body = false;
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = trim( preg_replace( '/#.*/', '', $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$name = strtolower( $name );
			if ( 'user-agent' === $name ) {
				if ( $in_body ) {
					$current = array();
					$in_body = false;
				}
				$current[]                        = strtolower( $value );
				$agents[ strtolower( $value ) ] ??= array();
			} elseif ( 'sitemap' === $name ) {
				$other[] = 'sitemap: ' . $value;
			} elseif ( $current ) {
				$in_body = true;
				foreach ( $current as $a ) {
					$agents[ $a ][] = $name . ': ' . $value;
				}
			}
		}
		foreach ( $agents as $a => $rules ) {
			// An empty Disallow allows everything, the same as having no rule.
			$agents[ $a ] = array_values( array_unique( array_diff( $rules, array( 'disallow: ' ) ) ) );
		}
		return array( 'agents' => $agents, 'other' => array_values( array_unique( $other ) ) );
	}

	/**
	 * Rules in the file that WordPress's version would not carry over, per
	 * crawler. Empty means swapping loses nothing. A crawler that only the
	 * generated version names must still carry the file's "*" rules, because
	 * today that crawler follows them.
	 *
	 * @return string[] Readable lines, e.g. "GPTBot: Disallow: /private/".
	 */
	public static function lost_rules( string $file, string $generated ): array {
		$f    = self::parse( $file );
		$g    = self::parse( $generated );
		$lost = array();
		$star = $f['agents']['*'] ?? array();
		foreach ( array_unique( array_merge( array_keys( $f['agents'] ), array_keys( $g['agents'] ) ) ) as $agent ) {
			$was = $f['agents'][ $agent ] ?? $star;
			$now = $g['agents'][ $agent ] ?? ( $g['agents']['*'] ?? array() );
			foreach ( array_diff( $was, $now ) as $rule ) {
				// A crawler the site's own settings now block outright (AI training switched
				// off) has nothing left to lose.
				if ( in_array( 'disallow: /', $now, true ) ) {
					continue;
				}
				$lost[] = ( '*' === $agent ? __( 'Every crawler', 'scout-build' ) : $agent ) . ': ' . ucfirst( $rule );
			}
		}
		foreach ( array_diff( $f['other'], $g['other'] ) as $line ) {
			$lost[] = ucfirst( $line );
		}
		return $lost;
	}

	/** Why the swap is not offered, or '' when it is safe. */
	public static function blocker(): string {
		if ( ! self::has_file() ) {
			return __( 'There is no robots.txt file on the server.', 'scout-build' );
		}
		if ( '1' !== (string) get_option( 'blog_public' ) ) {
			return __( 'Search engines are switched off in Settings, Reading, so WordPress would block everything. Switch them on first.', 'scout-build' );
		}
		if ( ! is_readable( self::file() ) || ! wp_is_writable( ABSPATH ) ) {
			return __( 'WordPress cannot rename the file on this server. Rename or remove it over SFTP instead.', 'scout-build' );
		}
		$gen = self::parse( self::generated() );
		if ( in_array( 'disallow: /', $gen['agents']['*'] ?? array(), true ) ) {
			return __( 'WordPress\'s own version would block the whole site, so the file stays.', 'scout-build' );
		}
		if ( self::lost_rules( (string) file_get_contents( self::file() ), self::generated() ) ) {
			return __( 'The file has rules WordPress would not repeat. A person should decide what to keep.', 'scout-build' );
		}
		return '';
	}

	public static function latest_backup(): string {
		$found = glob( ABSPATH . self::BACKUP . '*' );
		if ( ! $found ) {
			return '';
		}
		rsort( $found, SORT_STRING );
		return $found[0];
	}

	private static function guard( string $action ): void {
		check_admin_referer( $action );
		if ( ! current_user_can( 'manage_options' ) || ! Scout_Hub_Access::is_operator() ) {
			wp_die( esc_html__( 'Only the people who look after this site can change robots.txt.', 'scout-build' ), 403 );
		}
	}

	private static function done( string $message, string $type ): void {
		set_transient( 'scout_hub_robots_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function adopt(): void {
		self::guard( self::ADOPT );
		$why = self::blocker();
		if ( '' !== $why ) {
			self::done( $why, 'error' );
		}
		$backup = ABSPATH . self::BACKUP . gmdate( 'Ymd-His' );
		for ( $n = 2; file_exists( $backup ); $n++ ) {
			$backup = ABSPATH . self::BACKUP . gmdate( 'Ymd-His' ) . '-' . $n;
		}
		if ( ! @rename( self::file(), $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			self::done( __( 'The server would not rename the file. Nothing changed.', 'scout-build' ), 'error' );
		}
		self::done( __( 'Done. WordPress now writes robots.txt. The old file is kept as a backup. Clear the host\'s cache (WP Engine caches robots.txt) before you check it.', 'scout-build' ), 'success' );
	}

	public static function restore(): void {
		self::guard( self::RESTORE );
		$backup = self::latest_backup();
		if ( '' === $backup || self::has_file() ) {
			self::done( __( 'There is nothing to put back, or a robots.txt file is already there.', 'scout-build' ), 'error' );
		}
		if ( ! @rename( $backup, self::file() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			self::done( __( 'The server would not rename the backup. Nothing changed.', 'scout-build' ), 'error' );
		}
		self::done( __( 'The old robots.txt file is back. Clear the host\'s cache before you check it.', 'scout-build' ), 'success' );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = get_transient( 'scout_hub_robots_notice_' . get_current_user_id() );
		delete_transient( 'scout_hub_robots_notice_' . get_current_user_id() );
		$has  = self::has_file();
		$why  = $has ? self::blocker() : '';
		$lost = $has ? self::lost_rules( (string) file_get_contents( self::file() ), self::generated() ) : array();
		$op   = Scout_Hub_Access::is_operator();

		Scout_Admin_UI::open( __( 'robots.txt file', 'scout-build' ), __( 'robots.txt tells search engines and AI tools what they may read. WordPress writes it, and Scout features add to it, unless a file on the server takes over.', 'scout-build' ), __( 'Site health', 'scout-build' ) );
		if ( $notice ) {
			printf( '<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
		}
		?>
		<div class="sh-box">
			<?php if ( ! $has ) : ?>
				<h2 class="sh-title"><?php esc_html_e( 'WordPress writes robots.txt', 'scout-build' ); ?></h2>
				<p><?php esc_html_e( 'There is no file in the way, so everything Scout adds reaches search engines.', 'scout-build' ); ?> <a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View robots.txt', 'scout-build' ); ?></a></p>
				<?php if ( $op && self::latest_backup() ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::RESTORE ); ?>">
						<?php wp_nonce_field( self::RESTORE ); ?>
						<p><button type="submit" class="button"><?php esc_html_e( 'Put the old file back', 'scout-build' ); ?></button></p>
					</form>
				<?php endif; ?>
			<?php else : ?>
				<h2 class="sh-title"><?php esc_html_e( 'A file on the server is taking over', 'scout-build' ); ?></h2>
				<p><?php esc_html_e( 'While this file is there, WordPress\'s robots.txt and everything Scout adds to it are ignored.', 'scout-build' ); ?></p>
				<?php if ( '' === $why ) : ?>
					<p><strong><?php esc_html_e( 'Safe to switch: WordPress\'s version keeps every rule in the file.', 'scout-build' ); ?></strong></p>
					<?php if ( $op ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ADOPT ); ?>">
							<?php wp_nonce_field( self::ADOPT ); ?>
							<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Use WordPress\'s robots.txt', 'scout-build' ); ?></button></p>
							<p class="description"><?php esc_html_e( 'The file is renamed and kept as a backup, so this can be undone here.', 'scout-build' ); ?></p>
						</form>
					<?php endif; ?>
				<?php else : ?>
					<p><strong><?php echo esc_html( $why ); ?></strong></p>
					<?php if ( $lost ) : ?>
						<p><?php esc_html_e( 'Rules only the file has:', 'scout-build' ); ?></p>
						<ul class="ul-disc">
							<?php foreach ( array_slice( $lost, 0, 20 ) as $l ) : ?>
								<li><code><?php echo esc_html( $l ); ?></code></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php if ( $has ) : ?>
			<div class="sh-grid sh-grid-2">
				<section class="sh-box">
					<h2 class="sh-title"><?php esc_html_e( 'The file on the server', 'scout-build' ); ?></h2>
					<pre style="white-space:pre-wrap;margin:0"><?php echo esc_html( (string) file_get_contents( self::file() ) ); ?></pre>
				</section>
				<section class="sh-box">
					<h2 class="sh-title"><?php esc_html_e( 'What WordPress would send', 'scout-build' ); ?></h2>
					<pre style="white-space:pre-wrap;margin:0"><?php echo esc_html( self::generated() ); ?></pre>
				</section>
			</div>
		<?php endif; ?>
		<?php
		Scout_Admin_UI::close();
	}
}
