<?php
/**
 * Scout Build -> Redirects: the redirect list, the missing-page watch, broken
 * links on the site's own pages, settings, and CSV import and export.
 *
 * @package Scout_Redirects
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Redirects_Admin {

	const SLUG     = 'scout-redirects';
	const PER_PAGE = 25;

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'auto_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_REDIRECTS_FILE ), array( __CLASS__, 'action_link' ) );
		foreach ( array( 'save', 'delete', 'ignore', 'clear', 'settings', 'export', 'import', 'undo' ) as $action ) {
			add_action( 'admin_post_scout_redirects_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/** Nest under the site's Build section; fall back to Settings. */
	public static function parent(): string {
		global $admin_page_hooks;
		foreach ( array( 'scout-build', 'recon-build', 'site-build', 'scout' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'options-general.php';
	}

	public static function url( array $args = array() ): string {
		$base = 'options-general.php' === self::parent() ? admin_url( 'options-general.php?page=' . self::SLUG ) : admin_url( 'admin.php?page=' . self::SLUG );
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	public static function menu(): void {
		add_submenu_page( self::parent(), __( 'Redirects', 'scout-redirects' ), __( 'Redirects', 'scout-redirects' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		Scout_Admin_UI::enqueue( SCOUT_REDIRECTS_URL . 'assets/scout-admin.css' );
		wp_enqueue_style( 'scout-redirects-admin', SCOUT_REDIRECTS_URL . 'assets/scout-redirects-admin.css', array( 'scout-admin' ), SCOUT_REDIRECTS_VERSION );
		wp_enqueue_script( 'scout-redirects-admin', SCOUT_REDIRECTS_URL . 'assets/scout-redirects-admin.js', array(), SCOUT_REDIRECTS_VERSION, true );
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'scout-redirects' ) . '</a>' );
		return $links;
	}

	/* ---------------------------------------------------------------- */
	/* Saving                                                            */
	/* ---------------------------------------------------------------- */

	/** Capability and nonce check shared by every handler. */
	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change redirects.', 'scout-redirects' ), 403 );
		}
		check_admin_referer( 'scout_redirects_' . $action );
	}

	/**
	 * Return to the screen. admin-post.php runs without the admin menu, so the
	 * screen's own address (under Scout Build or under Settings) comes from the
	 * form's referrer rather than from parent().
	 */
	private static function back( array $args, string $anchor = '' ): void {
		$ref  = (string) wp_get_referer();
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		if ( '' !== $ref && false !== strpos( $ref, 'page=' . self::SLUG ) ) {
			$path = (string) wp_parse_url( $ref, PHP_URL_PATH );
			$base = admin_url( basename( $path ) . '?page=' . self::SLUG );
		}
		wp_safe_redirect( add_query_arg( $args, $base ) . ( '' !== $anchor ? '#' . $anchor : '' ) );
		exit;
	}

	private static function post_text( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	public static function handle_save(): void {
		self::guard( 'save' );
		$id   = absint( self::post_text( 'id' ) );
		$from = self::post_text( 'from' );
		$to   = self::post_text( 'to' );
		$type = absint( self::post_text( 'type' ) );
		$res  = Scout_Redirects::add( $from, $to, $type, 'manual', $id );
		if ( is_wp_error( $res ) ) {
			$args = array(
				'scout_err' => $res->get_error_code(),
				'from'      => rawurlencode( $from ),
				'to'        => rawurlencode( $to ),
			);
			if ( $id ) {
				$args['edit'] = $id;
			}
			self::back( $args, 'scout-add' );
		}
		self::back( array( 'scout_msg' => $id ? 'updated' : 'added' ), 'scout-list' );
	}

	public static function handle_delete(): void {
		self::guard( 'delete' );
		Scout_Redirects_Store::delete( absint( self::post_text( 'id' ) ) );
		self::back( array( 'scout_msg' => 'deleted' ), 'scout-list' );
	}

	public static function handle_ignore(): void {
		self::guard( 'ignore' );
		Scout_Redirects_Store::log_delete( absint( self::post_text( 'id' ) ) );
		self::back( array( 'scout_msg' => 'ignored' ), 'scout-missing' );
	}

	public static function handle_clear(): void {
		self::guard( 'clear' );
		Scout_Redirects_Store::log_clear();
		self::back( array( 'scout_msg' => 'cleared' ), 'scout-missing' );
	}

	public static function handle_settings(): void {
		self::guard( 'settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$settings = array(
			'auto_pages' => empty( $_POST['auto_pages'] ) ? 0 : 1,
			'watch_404'  => empty( $_POST['watch_404'] ) ? 0 : 1,
			'log_cap'    => max( 50, min( 2000, absint( self::post_text( 'log_cap' ) ) ) ),
		);
		// phpcs:enable
		update_option( Scout_Redirects::OPTION, $settings, false );
		Scout_Redirects_Store::log_trim( $settings['log_cap'] );
		self::back( array( 'scout_msg' => 'settings' ), 'scout-settings' );
	}

	/** Neutralize a cell a spreadsheet would read as a formula. */
	private static function csv_cell( $value ): string {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	public static function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export redirects.', 'scout-redirects' ), 403 );
		}
		check_admin_referer( 'scout_redirects_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="redirects-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'from', 'to', 'type', 'hits', 'last_used' ) );
		foreach ( Scout_Redirects_Store::all() as $row ) {
			fputcsv( $out, array( self::csv_cell( $row->source ), self::csv_cell( $row->target ), (int) $row->type, (int) $row->hits, (string) $row->last_hit ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	public static function handle_import(): void {
		self::guard( 'import' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$file = isset( $_FILES['csv'] ) && is_array( $_FILES['csv'] ) ? $_FILES['csv'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::back( array( 'scout_err' => 'nofile' ), 'scout-io' );
		}
		$name = strtolower( sanitize_file_name( (string) ( $file['name'] ?? '' ) ) );
		if ( ! preg_match( '/\.(csv|txt)$/', $name ) || (int) $file['size'] > 2 * MB_IN_BYTES ) {
			self::back( array( 'scout_err' => 'badfile' ), 'scout-io' );
		}
		$handle = fopen( $file['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$added  = 0;
		$skip   = 0;
		$line   = 0;
		while ( $handle && false !== ( $cells = fgetcsv( $handle, 4096, ',', '"', '\\' ) ) && $line < 5000 ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$line;
			$cells = array_map( static fn( $c ) => trim( ltrim( (string) $c, "\xEF\xBB\xBF'" ) ), (array) $cells );
			if ( count( $cells ) < 2 || ( '' === $cells[0] && '' === $cells[1] ) ) {
				continue;
			}
			if ( 1 === $line && in_array( strtolower( $cells[0] ), array( 'from', 'source', 'old', 'old address', 'old url' ), true ) ) {
				continue;
			}
			$type_cell = strtolower( $cells[2] ?? '' );
			$type      = ( '302' === $type_cell || 'temporary' === $type_cell ) ? 302 : 301;
			$res       = Scout_Redirects::add( sanitize_text_field( $cells[0] ), sanitize_text_field( $cells[1] ), $type, 'import' );
			if ( is_wp_error( $res ) ) {
				++$skip;
			} else {
				++$added;
			}
		}
		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		self::back(
			array(
				'scout_msg' => 'imported',
				'n'         => $added,
				'skipped'   => $skip,
			),
			'scout-io'
		);
	}

	/** Undo the redirects just made for a page whose address changed. */
	public static function handle_undo(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change redirects.', 'scout-redirects' ), 403 );
		}
		check_admin_referer( 'scout_redirects_undo' );
		$key  = Scout_Redirects::NOTICE . get_current_user_id();
		$data = get_transient( $key );
		foreach ( (array) ( is_array( $data ) ? ( $data['made'] ?? array() ) : array() ) as $pair ) {
			if ( is_array( $pair ) && isset( $pair[0] ) ) {
				Scout_Redirects_Store::delete_source( (string) $pair[0] );
			}
		}
		delete_transient( $key );
		self::back( array( 'scout_msg' => 'undone' ), 'scout-list' );
	}

	/** After a page's address changes, say what was forwarded and offer to undo it, once. */
	public static function auto_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key  = Scout_Redirects::NOTICE . get_current_user_id();
		$data = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data['made'] ) || ! empty( $data['shown'] ) ) {
			return;
		}
		// Shown once; the undo link keeps working for an hour.
		$data['shown'] = 1;
		set_transient( $key, $data, HOUR_IN_SECONDS );
		$made  = (array) $data['made'];
		$first = (array) $made[0];
		$extra = count( $made ) - 1;
		$undo  = wp_nonce_url( admin_url( 'admin-post.php?action=scout_redirects_undo' ), 'scout_redirects_undo' );
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<?php
				printf(
					/* translators: 1: old address, 2: new address */
					esc_html__( 'A page moved, so visitors and search engines going to %1$s now land on %2$s.', 'scout-redirects' ),
					'<code>' . esc_html( (string) ( $first[0] ?? '' ) ) . '</code>',
					'<code>' . esc_html( (string) ( $first[1] ?? '' ) ) . '</code>'
				);
				if ( $extra > 0 ) {
					echo ' ' . esc_html(
						sprintf(
							/* translators: %d: number of child pages */
							_n( '%d child page moved with it and is forwarded too.', '%d child pages moved with it and are forwarded too.', $extra, 'scout-redirects' ),
							$extra
						)
					);
				}
				?>
				<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'See redirects', 'scout-redirects' ); ?></a>
				&middot;
				<a href="<?php echo esc_url( $undo ); ?>"><?php esc_html_e( 'Undo', 'scout-redirects' ); ?></a>
			</p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- */
	/* Screen                                                            */
	/* ---------------------------------------------------------------- */

	private static function ago( ?string $gmt ): string {
		if ( empty( $gmt ) || '0000-00-00 00:00:00' === $gmt ) {
			return __( 'Not used yet', 'scout-redirects' );
		}
		$time = strtotime( $gmt . ' UTC' );
		if ( ! $time ) {
			return '';
		}
		/* translators: %s: time span, like "3 hours" */
		return sprintf( __( '%s ago', 'scout-redirects' ), human_time_diff( $time, time() ) );
	}

	private static function messages(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$msg = isset( $_GET['scout_msg'] ) ? sanitize_key( $_GET['scout_msg'] ) : '';
		$err = isset( $_GET['scout_err'] ) ? sanitize_key( $_GET['scout_err'] ) : '';
		$n   = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0;
		$sk  = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
		// phpcs:enable
		$ok = array(
			'added'    => __( 'Redirect added. It works right away.', 'scout-redirects' ),
			'updated'  => __( 'Redirect saved.', 'scout-redirects' ),
			'deleted'  => __( 'Redirect removed. The old address now shows the missing-page screen again.', 'scout-redirects' ),
			'ignored'  => __( 'Removed from the list. It comes back if someone asks for it again.', 'scout-redirects' ),
			'cleared'  => __( 'The missing-page list is clear.', 'scout-redirects' ),
			'settings' => __( 'Settings saved.', 'scout-redirects' ),
			'undone'   => __( 'Done. The old page address is no longer forwarded.', 'scout-redirects' ),
		);
		$bad = array(
			'source'   => __( 'The old address needs to be a page on this site, like /old-page. The home page cannot be forwarded.', 'scout-redirects' ),
			'reserved' => __( 'That old address belongs to WordPress itself (the dashboard, login, files or the site API), so it stays as it is.', 'scout-redirects' ),
			'target'   => __( 'The new address needs to be a path on this site, like /services/, or a full web address starting with https://.', 'scout-redirects' ),
			'loop'     => __( 'The old and new addresses are the same page, which would send visitors in a circle.', 'scout-redirects' ),
			'nofile'   => __( 'Choose a CSV file to import.', 'scout-redirects' ),
			'badfile'  => __( 'That file is not a CSV, or it is larger than 2 MB.', 'scout-redirects' ),
		);
		if ( 'imported' === $msg ) {
			$text = sprintf(
				/* translators: %d: number of redirects */
				_n( 'Imported %d redirect.', 'Imported %d redirects.', $n, 'scout-redirects' ),
				$n
			);
			if ( $sk ) {
				$text .= ' ' . sprintf(
					/* translators: %d: number of rows */
					_n( '%d row was skipped because an address was not usable.', '%d rows were skipped because an address was not usable.', $sk, 'scout-redirects' ),
					$sk
				);
			}
			echo '<div class="notice notice-success"><p>' . esc_html( $text ) . '</p></div>';
		} elseif ( isset( $ok[ $msg ] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $ok[ $msg ] ) . '</p></div>';
		}
		if ( isset( $bad[ $err ] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $bad[ $err ] ) . '</p></div>';
		}
	}

	private static function action_form( string $action, int $id, string $label, string $class = 'button', string $confirm = '' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sr-inline"<?php echo '' !== $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>>
			<input type="hidden" name="action" value="scout_redirects_<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
			<?php wp_nonce_field( 'scout_redirects_' . $action ); ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by callers. ?></button>
		</form>
		<?php
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen state.
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$prefill = isset( $_GET['from'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['from'] ) ) ) : '';
		$pre_to  = isset( $_GET['to'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['to'] ) ) ) : '';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$all_404 = ! empty( $_GET['missing'] );
		// phpcs:enable

		$editing = $edit_id ? Scout_Redirects_Store::get( $edit_id ) : null;
		$from    = '' !== $prefill ? $prefill : ( $editing ? $editing->source : '' );
		$to      = '' !== $pre_to ? $pre_to : ( $editing ? $editing->target : '' );
		$type    = $editing ? (int) $editing->type : 301;

		$s        = Scout_Redirects::settings();
		$total    = Scout_Redirects_Store::count();
		$listed   = '' !== $search ? Scout_Redirects_Store::search_count( $search ) : $total;
		$rows     = Scout_Redirects_Store::page( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE, $search );
		$week     = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
		$missing7 = Scout_Redirects_Store::log_count_since( $week );
		$broken   = Scout_Redirects_Store::log_count_since( '1970-01-01 00:00:00', true );
		$missing  = Scout_Redirects_Store::log_list( $all_404 ? $s['log_cap'] : 10 );
		$logged   = Scout_Redirects_Store::log_count_since( '1970-01-01 00:00:00' );
		$internal = Scout_Redirects_Store::log_list( 10, true );
		$post_url = admin_url( 'admin-post.php' );

		Scout_Admin_UI::open(
			__( 'Old pages, forwarded to new ones', 'scout-redirects' ),
			__( 'When a page moves or goes away, a redirect sends visitors and search engines to the right place. This screen also lists the missing pages people ask for, so each one can become a redirect in one click.', 'scout-redirects' ),
			__( 'Redirects', 'scout-redirects' )
		);
		self::messages();
		?>
		<div class="sh-grid sh-grid-3 sr-stats">
			<section class="sh-box sr-stat">
				<p class="sr-stat-label"><span class="sr-dot" aria-hidden="true"></span><?php esc_html_e( 'Redirects live', 'scout-redirects' ); ?></p>
				<p class="sr-stat-num"><?php echo esc_html( number_format_i18n( $total ) ); ?></p>
				<p class="sh-note"><?php esc_html_e( 'Old addresses that forward to a new page.', 'scout-redirects' ); ?></p>
			</section>
			<section class="sh-box sr-stat">
				<p class="sr-stat-label"><?php esc_html_e( 'Missing pages hit', 'scout-redirects' ); ?></p>
				<p class="sr-stat-num"><?php echo esc_html( number_format_i18n( $missing7 ) ); ?></p>
				<p class="sh-note"><?php esc_html_e( 'Different missing addresses visitors tried in the last 7 days.', 'scout-redirects' ); ?></p>
			</section>
			<section class="sh-box sr-stat">
				<p class="sr-stat-label"><?php esc_html_e( 'Broken links on site', 'scout-redirects' ); ?></p>
				<p class="sr-stat-num"><?php echo esc_html( number_format_i18n( $broken ) ); ?></p>
				<p class="sh-note"><?php esc_html_e( 'Missing pages that a link on one of your own pages leads to.', 'scout-redirects' ); ?></p>
			</section>
		</div>

		<div class="sr-layout">
			<section class="sh-box sr-main" id="scout-list">
				<h2 class="sh-title"><?php esc_html_e( 'Redirect list', 'scout-redirects' ); ?></h2>
				<p><?php esc_html_e( 'Old addresses and where they go now. Use permanent for pages that moved for good; search engines then move the old page\'s standing to the new one.', 'scout-redirects' ); ?></p>

				<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="sr-add" id="scout-add">
					<input type="hidden" name="action" value="scout_redirects_save">
					<input type="hidden" name="id" value="<?php echo esc_attr( (string) ( $editing ? (int) $editing->id : 0 ) ); ?>">
					<?php wp_nonce_field( 'scout_redirects_save' ); ?>
					<div class="sr-field">
						<label for="sr-from"><?php esc_html_e( 'Old address', 'scout-redirects' ); ?></label>
						<input id="sr-from" name="from" type="text" class="code" placeholder="/old-page" value="<?php echo esc_attr( $from ); ?>" required autocomplete="off" spellcheck="false">
					</div>
					<div class="sr-field">
						<label for="sr-to"><?php esc_html_e( 'New address', 'scout-redirects' ); ?></label>
						<input id="sr-to" name="to" type="text" class="code" placeholder="/services/" value="<?php echo esc_attr( $to ); ?>" required autocomplete="off" spellcheck="false"<?php echo ( '' !== $prefill && '' === $to ) ? ' data-sr-focus="1"' : ''; ?>>
					</div>
					<div class="sr-field sr-field-type">
						<label for="sr-type"><?php esc_html_e( 'Type', 'scout-redirects' ); ?></label>
						<select id="sr-type" name="type">
							<option value="301" <?php selected( 301, $type ); ?>><?php esc_html_e( 'Permanent (301)', 'scout-redirects' ); ?></option>
							<option value="302" <?php selected( 302, $type ); ?>><?php esc_html_e( 'Temporary (302)', 'scout-redirects' ); ?></option>
						</select>
					</div>
					<div class="sr-field sr-field-go">
						<button type="submit" class="button button-primary"><?php echo $editing ? esc_html__( 'Save changes', 'scout-redirects' ) : esc_html__( 'Add redirect', 'scout-redirects' ); ?></button>
						<?php if ( $editing || '' !== $prefill ) : ?>
							<a class="button sh-quiet" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'scout-redirects' ); ?></a>
						<?php endif; ?>
					</div>
				</form>
				<p class="sh-note sr-hint"><?php esc_html_e( 'The old address is a path on this site. The new address can be a path, like /services/, or a full web address. Capital letters and a closing slash on the old address do not matter.', 'scout-redirects' ); ?></p>

				<?php if ( $total > self::PER_PAGE || '' !== $search ) : ?>
					<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' === self::parent() ? 'options-general.php' : 'admin.php' ) ); ?>" class="sr-search" role="search">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
						<label for="sr-search" class="screen-reader-text"><?php esc_html_e( 'Search redirects', 'scout-redirects' ); ?></label>
						<input id="sr-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search old or new addresses', 'scout-redirects' ); ?>">
						<button type="submit" class="button"><?php esc_html_e( 'Search', 'scout-redirects' ); ?></button>
					</form>
				<?php endif; ?>

				<?php if ( ! $rows ) : ?>
					<p class="sr-empty">
						<?php
						echo '' !== $search
							? esc_html__( 'No redirects match that search.', 'scout-redirects' )
							: esc_html__( 'No redirects yet. Add an old address above, or import the list from an old site as a CSV file below.', 'scout-redirects' );
						?>
					</p>
				<?php else : ?>
					<table class="sr-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'From', 'scout-redirects' ); ?></th>
								<th scope="col"><?php esc_html_e( 'To', 'scout-redirects' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Type', 'scout-redirects' ); ?></th>
								<th scope="col" class="sr-num"><?php esc_html_e( 'Hits', 'scout-redirects' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Last used', 'scout-redirects' ); ?></th>
								<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'scout-redirects' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr<?php echo $editing && (int) $editing->id === (int) $row->id ? ' class="is-editing"' : ''; ?>>
								<td class="sr-path" data-label="<?php esc_attr_e( 'From', 'scout-redirects' ); ?>">
									<?php echo esc_html( $row->source ); ?>
									<?php if ( 'auto' === $row->origin ) : ?>
										<span class="sr-tag"><?php esc_html_e( 'Page moved', 'scout-redirects' ); ?></span>
									<?php elseif ( 'import' === $row->origin ) : ?>
										<span class="sr-tag"><?php esc_html_e( 'Imported', 'scout-redirects' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="sr-path" data-label="<?php esc_attr_e( 'To', 'scout-redirects' ); ?>"><a href="<?php echo esc_url( Scout_Redirects::target_url( $row->target ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row->target ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'scout-redirects' ); ?></span></a></td>
								<td data-label="<?php esc_attr_e( 'Type', 'scout-redirects' ); ?>"><span class="sr-chip" title="<?php echo 301 === (int) $row->type ? esc_attr__( 'Permanent', 'scout-redirects' ) : esc_attr__( 'Temporary', 'scout-redirects' ); ?>"><?php echo esc_html( (string) (int) $row->type ); ?></span></td>
								<td class="sr-num" data-label="<?php esc_attr_e( 'Hits', 'scout-redirects' ); ?>"><?php echo esc_html( number_format_i18n( (int) $row->hits ) ); ?></td>
								<td class="sr-when" data-label="<?php esc_attr_e( 'Last used', 'scout-redirects' ); ?>"><?php echo esc_html( self::ago( $row->last_hit ) ); ?></td>
								<td class="sr-actions">
									<a class="button sh-quiet" href="<?php echo esc_url( self::url( array( 'edit' => (int) $row->id ) ) . '#scout-add' ); ?>"><?php esc_html_e( 'Edit', 'scout-redirects' ); ?><span class="screen-reader-text"> <?php echo esc_html( $row->source ); ?></span></a>
									<?php
									self::action_form(
										'delete',
										(int) $row->id,
										esc_html__( 'Delete', 'scout-redirects' ) . '<span class="screen-reader-text"> ' . esc_html( $row->source ) . '</span>',
										'button sh-quiet sh-danger',
										/* translators: %s: old address */
										sprintf( __( 'Stop forwarding %s?', 'scout-redirects' ), $row->source )
									);
									?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php
					$pages = (int) ceil( $listed / self::PER_PAGE );
					if ( $pages > 1 ) :
						?>
						<nav class="sr-pager" aria-label="<?php esc_attr_e( 'Redirect pages', 'scout-redirects' ); ?>">
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg( 'paged', '%#%', self::url( '' !== $search ? array( 's' => rawurlencode( $search ) ) : array() ) ),
										'format'    => '',
										'current'   => $paged,
										'total'     => $pages,
										'prev_text' => __( 'Previous', 'scout-redirects' ),
										'next_text' => __( 'Next', 'scout-redirects' ),
									)
								)
							);
							?>
						</nav>
					<?php endif; ?>
				<?php endif; ?>
			</section>

			<div class="sr-side">
				<section class="sh-box" id="scout-missing">
					<h2 class="sh-title"><?php esc_html_e( 'Missing pages', 'scout-redirects' ); ?></h2>
					<p><?php esc_html_e( 'Addresses people asked for that are not on the site, most visited first. Search engine crawlers, bots and file requests are left out.', 'scout-redirects' ); ?></p>
					<?php if ( ! $s['watch_404'] ) : ?>
						<p class="sr-empty"><?php esc_html_e( 'Watching for missing pages is turned off in the settings below.', 'scout-redirects' ); ?></p>
					<?php elseif ( ! $missing ) : ?>
						<p class="sr-empty"><?php esc_html_e( 'Nothing missing so far. When someone asks for a page that is not here, it shows up in this list.', 'scout-redirects' ); ?></p>
					<?php else : ?>
						<ul class="sr-list">
							<?php foreach ( $missing as $row ) : ?>
								<li>
									<div class="sr-list-text">
										<p class="sr-path"><?php echo esc_html( $row->path ); ?></p>
										<p class="sh-note">
											<?php
											echo esc_html(
												sprintf(
													/* translators: 1: number of visits, 2: time since */
													_n( '%1$s visit, last seen %2$s', '%1$s visits, last seen %2$s', (int) $row->hits, 'scout-redirects' ),
													number_format_i18n( (int) $row->hits ),
													self::ago( $row->last_seen )
												)
											);
											?>
										</p>
										<?php if ( '' !== $row->referrer ) : ?>
											<p class="sh-note sr-ref">
												<?php
												echo esc_html(
													(int) $row->internal
														/* translators: %s: page address on this site */
														? sprintf( __( 'Linked from your page %s', 'scout-redirects' ), Scout_Redirects::normalize_source( $row->referrer ) )
														/* translators: %s: other website */
														: sprintf( __( 'Came from %s', 'scout-redirects' ), (string) wp_parse_url( $row->referrer, PHP_URL_HOST ) )
												);
												?>
											</p>
										<?php endif; ?>
									</div>
									<div class="sr-list-actions">
										<a class="button" href="<?php echo esc_url( self::url( array( 'from' => rawurlencode( $row->path ) ) ) . '#scout-add' ); ?>"><?php esc_html_e( 'Forward it', 'scout-redirects' ); ?><span class="screen-reader-text"> <?php echo esc_html( $row->path ); ?></span></a>
										<?php self::action_form( 'ignore', (int) $row->id, esc_html__( 'Ignore', 'scout-redirects' ) . '<span class="screen-reader-text"> ' . esc_html( $row->path ) . '</span>', 'button sh-quiet' ); ?>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
						<div class="sr-foot">
							<?php if ( ! $all_404 && $logged > count( $missing ) ) : ?>
								<a class="button sh-quiet" href="<?php echo esc_url( self::url( array( 'missing' => 'all' ) ) . '#scout-missing' ); ?>">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: number of missing pages */
											__( 'Show all %s', 'scout-redirects' ),
											number_format_i18n( $logged )
										)
									);
									?>
								</a>
							<?php endif; ?>
							<?php self::action_form( 'clear', 0, esc_html__( 'Clear the list', 'scout-redirects' ), 'button sh-quiet sh-danger', __( 'Clear every missing page from the list? Redirects are not affected.', 'scout-redirects' ) ); ?>
						</div>
					<?php endif; ?>
				</section>

				<section class="sh-box" id="scout-broken">
					<h2 class="sh-title"><?php esc_html_e( 'Broken links on your pages', 'scout-redirects' ); ?></h2>
					<p><?php esc_html_e( 'A visitor clicked a link on one of these pages and found nothing. These also show in Tools, Site Health.', 'scout-redirects' ); ?></p>
					<?php if ( ! $internal ) : ?>
						<p class="sr-empty"><?php esc_html_e( 'No broken links found yet. Your pages link to pages that exist.', 'scout-redirects' ); ?></p>
					<?php else : ?>
						<ul class="sr-list">
							<?php
							foreach ( $internal as $row ) :
								$page    = Scout_Redirects::normalize_source( $row->referrer );
								$page_id = url_to_postid( $row->referrer );
								$edit    = $page_id ? get_edit_post_link( $page_id ) : '';
								?>
								<li>
									<span class="sr-flag" aria-hidden="true">!</span>
									<div class="sr-list-text">
										<p class="sr-strong">
											<?php
											printf(
												/* translators: 1: page with the link, 2: missing address */
												esc_html__( '%1$s links to %2$s', 'scout-redirects' ),
												'<span class="sr-path">' . esc_html( $page ) . '</span>',
												'<span class="sr-path">' . esc_html( $row->path ) . '</span>'
											);
											?>
										</p>
										<p class="sh-note"><?php esc_html_e( 'Fix it by adding a redirect or editing the link.', 'scout-redirects' ); ?></p>
									</div>
									<div class="sr-list-actions">
										<a class="button" href="<?php echo esc_url( self::url( array( 'from' => rawurlencode( $row->path ) ) ) . '#scout-add' ); ?>"><?php esc_html_e( 'Add redirect', 'scout-redirects' ); ?><span class="screen-reader-text"> <?php echo esc_html( $row->path ); ?></span></a>
										<?php if ( $edit ) : ?>
											<a class="button sh-quiet" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit the page', 'scout-redirects' ); ?><span class="screen-reader-text"> <?php echo esc_html( $page ); ?></span></a>
										<?php endif; ?>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</section>
			</div>
		</div>

		<div class="sh-grid sh-grid-2">
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="sh-box" id="scout-settings">
				<h2 class="sh-title"><?php esc_html_e( 'Settings', 'scout-redirects' ); ?></h2>
				<input type="hidden" name="action" value="scout_redirects_settings">
				<?php wp_nonce_field( 'scout_redirects_settings' ); ?>
				<p class="sr-check"><label><input type="checkbox" name="auto_pages" value="1" <?php checked( $s['auto_pages'] ); ?>> <?php esc_html_e( 'Forward a page automatically when its address changes', 'scout-redirects' ); ?></label></p>
				<p class="sh-note"><?php esc_html_e( 'Covers published pages and their child pages. WordPress already forwards blog posts on its own.', 'scout-redirects' ); ?></p>
				<p class="sr-check"><label><input type="checkbox" name="watch_404" value="1" <?php checked( $s['watch_404'] ); ?>> <?php esc_html_e( 'Keep a list of missing pages people ask for', 'scout-redirects' ); ?></label></p>
				<p class="sr-field sr-field-cap">
					<label for="sr-cap"><?php esc_html_e( 'Missing pages to keep', 'scout-redirects' ); ?></label>
					<input id="sr-cap" type="number" name="log_cap" min="50" max="2000" step="50" value="<?php echo esc_attr( (string) $s['log_cap'] ); ?>">
				</p>
				<p class="sh-note"><?php esc_html_e( 'When the list is full, the oldest, least visited entries drop off. Between 50 and 2,000.', 'scout-redirects' ); ?></p>
				<?php submit_button( __( 'Save settings', 'scout-redirects' ) ); ?>
			</form>

			<section class="sh-box" id="scout-io">
				<h2 class="sh-title"><?php esc_html_e( 'Import and export', 'scout-redirects' ); ?></h2>
				<p><?php esc_html_e( 'Move redirects in and out as a CSV file, the kind any spreadsheet opens. Columns: from, to, and an optional type (301 or 302). A header row is fine.', 'scout-redirects' ); ?></p>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" enctype="multipart/form-data" class="sr-import">
					<input type="hidden" name="action" value="scout_redirects_import">
					<?php wp_nonce_field( 'scout_redirects_import' ); ?>
					<label for="sr-csv"><?php esc_html_e( 'CSV file', 'scout-redirects' ); ?></label>
					<input id="sr-csv" type="file" name="csv" accept=".csv,text/csv" required>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'scout-redirects' ); ?></button>
				</form>
				<p class="sh-note"><?php esc_html_e( 'A row with an old address that is already here replaces that redirect.', 'scout-redirects' ); ?></p>
				<p class="sr-export">
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=scout_redirects_export' ), 'scout_redirects_export' ) ); ?>"><?php esc_html_e( 'Download all redirects', 'scout-redirects' ); ?></a>
				</p>
			</section>
		</div>
		<?php
		Scout_Admin_UI::close();
	}
}
