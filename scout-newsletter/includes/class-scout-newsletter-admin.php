<?php
/**
 * Scout -> Newsletter: the Beehiiv connection, the tag map, the consent
 * records and what the queue has been doing.
 *
 * Operators change it (the Scout Build operator list, or administrators on a
 * site without Scout Build); everyone else with access sees the status.
 *
 * @package Scout_Newsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Newsletter_Admin {

	/**
	 * One status line at the top of the screen (admin kit 1.10.0). An older kit
	 * copy may be the one loaded mid-update, so fall back to the same markup.
	 *
	 * @param string $go Button markup, escaped by the caller.
	 */
	private static function ui_status( string $state, string $headline, string $detail = '', string $go = '' ): void {
		if ( method_exists( 'Scout_Admin_UI', 'status' ) ) {
			Scout_Admin_UI::status( $state, $headline, $detail, $go );
			return;
		}
		$state = in_array( $state, array( 'good', 'warn', 'bad', 'off' ), true ) ? $state : 'good';
		echo '<section class="sh-box"><p class="sh-status is-' . esc_attr( $state ) . '"><span class="sh-dot" aria-hidden="true"></span><strong>' . esc_html( $headline ) . '</strong>';
		if ( '' !== $detail ) {
			echo ' <span>' . esc_html( $detail ) . '</span>';
		}
		if ( '' !== $go ) {
			echo ' <span class="sh-status-go">' . $go . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller.
		}
		echo '</p></section>';
	}

	/** A closed "More options" box (admin kit 1.10.0), with the same fallback. */
	private static function ui_fold_open( string $title ): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_open' ) ) {
			Scout_Admin_UI::fold_open( $title );
			return;
		}
		echo '<details class="sh-box sh-fold"><summary class="sh-title">' . esc_html( $title ) . '</summary>';
	}

	private static function ui_fold_close(): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_close' ) ) {
			Scout_Admin_UI::fold_close();
			return;
		}
		echo '</details>';
	}

	const SLUG = 'scout-newsletter';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_scout_newsletter_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_scout_newsletter_test', array( __CLASS__, 'test' ) );
		add_action( 'admin_post_scout_newsletter_release', array( __CLASS__, 'release' ) );
		add_action( 'admin_post_scout_newsletter_export', array( __CLASS__, 'export' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_NEWSLETTER_FILE ), array( __CLASS__, 'action_link' ) );
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

	/**
	 * The screen's address. admin.php?page= finds the screen under whichever
	 * menu holds it, which matters after a save: admin-post.php never builds the
	 * menu, so the parent cannot be looked up there.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	/** Operators change settings; the Scout Build list decides who that is. */
	public static function can_change(): bool {
		if ( class_exists( 'Scout_Hub_Access' ) ) {
			return Scout_Hub_Access::is_operator();
		}
		return current_user_can( 'manage_options' );
	}

	public static function menu(): void {
		add_submenu_page( self::parent(), __( 'Newsletter', 'scout-newsletter' ), __( 'Newsletter', 'scout-newsletter' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::SLUG ) ) {
			Scout_Admin_UI::enqueue( SCOUT_NEWSLETTER_URL . 'assets/scout-admin.css' );
		}
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'scout-newsletter' ) . '</a>' );
		return $links;
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::can_change() ) {
			wp_die( esc_html__( 'Only the people who look after this site can change the newsletter settings.', 'scout-newsletter' ), 403 );
		}
		check_admin_referer( $action );
	}

	/* ---------- saving ---------- */

	public static function save(): void {
		self::guard( 'scout_newsletter_save' );
		$in  = isset( $_POST['nl'] ) && is_array( $_POST['nl'] ) ? wp_unslash( $_POST['nl'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.
		$old = Scout_Newsletter::settings();
		$d   = Scout_Newsletter::defaults();
		$out = array();
		foreach ( array( 'enabled', 'welcome', 'keep_current', 'checkout_box' ) as $k ) {
			$out[ $k ] = ! empty( $in[ $k ] ) ? 1 : 0;
		}
		foreach ( array( 'name', 'utm_source', 'field_first', 'field_joined', 'field_plan', 'members_box', 'thanks' ) as $k ) {
			$out[ $k ] = sanitize_text_field( (string) ( $in[ $k ] ?? '' ) );
		}
		$out['wording']     = sanitize_text_field( (string) ( $in['wording'] ?? '' ) );
		$out['publication'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $in['publication'] ?? '' ) );
		$out['automation']  = implode( ',', array_filter( array_map( static fn( $a ) => preg_replace( '/[^A-Za-z0-9_\-]/', '', trim( $a ) ), explode( ',', (string) ( $in['automation'] ?? '' ) ) ) ) );
		$out['double_opt']  = in_array( $in['double_opt'] ?? '', array( 'on', 'off', 'not_set' ), true ) ? $in['double_opt'] : 'not_set';
		$out['tags']        = array();
		foreach ( array_keys( $d['tags'] ) as $src ) {
			$out['tags'][ $src ] = sanitize_text_field( (string) ( $in['tags'][ $src ] ?? '' ) );
		}
		update_option( Scout_Newsletter::OPTION, $out, false );

		if ( ! Scout_Newsletter::key_in_config() ) {
			if ( ! empty( $_POST['forget_key'] ) ) {
				Scout_Newsletter::save_key( '' );
			} else {
				$key = isset( $_POST['nl_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['nl_key'] ) ) ) : '';
				if ( '' !== $key ) {
					Scout_Newsletter::save_key( $key );
				}
			}
		}
		delete_transient( 'scout_newsletter_test' );
		// Switching sending on picks up anything already queued.
		if ( ! empty( $out['enabled'] ) && empty( $old['enabled'] ) ) {
			Scout_Newsletter_Queue::schedule();
		}
		wp_safe_redirect( self::url( array( 'nl_notice' => 'saved' ) ) );
		exit;
	}

	public static function test(): void {
		self::guard( 'scout_newsletter_test' );
		$res = Scout_Newsletter_Beehiiv::test();
		set_transient(
			'scout_newsletter_test',
			array(
				'ok'   => $res['ok'],
				'code' => $res['code'],
				'name' => $res['ok'] ? sanitize_text_field( (string) ( $res['data']['name'] ?? '' ) ) : '',
				'err'  => $res['error'],
				'time' => time(),
			),
			DAY_IN_SECONDS
		);
		Scout_Newsletter::log( '', 'test', $res['ok'] ? 'connected' : 'failed: ' . $res['error'] );
		wp_safe_redirect( self::url( array( 'nl_notice' => 'tested' ) ) );
		exit;
	}

	public static function release(): void {
		self::guard( 'scout_newsletter_release' );
		$n = Scout_Newsletter::sending() ? Scout_Newsletter_Store::release_held() : 0;
		if ( $n ) {
			Scout_Newsletter_Queue::schedule();
		}
		wp_safe_redirect( self::url( array( 'nl_notice' => 'released', 'n' => $n ) ) );
		exit;
	}

	/** Every consent record as a spreadsheet: the proof of who agreed, when, where and to what. */
	public static function export(): void {
		self::guard( 'scout_newsletter_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=newsletter-consent-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Agreed (UTC)', 'Email', 'First name', 'Where', 'Page', 'Campaign', 'Words shown', 'Tags', 'Status', 'Result' ) );
		$offset = 0;
		do {
			$rows = Scout_Newsletter_Store::recent( 500, $offset );
			foreach ( $rows as $r ) {
				fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), array( $r['created'], $r['email'], $r['first_name'], $r['source'], $r['url'], $r['campaign'], $r['wording'], implode( ', ', $r['tags'] ), $r['status'], $r['result'] ) ) );
			}
			$offset += 500;
		} while ( count( $rows ) === 500 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/** Keep spreadsheet apps from running a cell as a formula. */
	private static function csv_cell( $v ): string {
		$v = (string) $v;
		return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
	}

	/* ---------- the screen ---------- */

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = Scout_Newsletter::settings();
		$can     = self::can_change();
		$counts  = Scout_Newsletter_Store::counts();
		$test    = get_transient( 'scout_newsletter_test' );
		$notice  = isset( $_GET['nl_notice'] ) ? sanitize_key( wp_unslash( $_GET['nl_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$waiting = $counts['queued'] + $counts['retry'];

		Scout_Admin_UI::open(
			__( 'Newsletter', 'scout-newsletter' ),
			__( 'People who tick the newsletter box go to your Beehiiv list, tagged by where they joined.', 'scout-newsletter' )
		);

		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Newsletter settings saved.', 'scout-newsletter' ) . '</p></div>';
		} elseif ( 'released' === $notice ) {
			$n = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			/* translators: %d: number of sign-ups. */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d waiting sign-up is on its way to Beehiiv.', '%d waiting sign-ups are on their way to Beehiiv.', $n, 'scout-newsletter' ), $n ) ) . '</p></div>';
		} elseif ( 'tested' === $notice && is_array( $test ) ) {
			if ( $test['ok'] ) {
				/* translators: %s: Beehiiv publication name. */
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Connected to Beehiiv: %s.', 'scout-newsletter' ), '' !== $test['name'] ? $test['name'] : __( 'your publication', 'scout-newsletter' ) ) ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( self::test_error( $test ) ) . '</p></div>';
			}
		}

		$connected = Scout_Newsletter::connected();
		$conn_ok   = $connected && is_array( $test ) && $test['ok'];
		$conn_bad  = $connected && is_array( $test ) && ! $test['ok'];
		?>
		<?php
		$sending = Scout_Newsletter::sending();
		if ( ! $connected ) {
			$state    = 'off';
			$headline = __( 'Not connected.', 'scout-newsletter' );
			$detail   = __( 'Add the Beehiiv API key and publication ID below.', 'scout-newsletter' );
		} elseif ( $conn_bad ) {
			$state    = 'bad';
			$headline = __( 'Not working.', 'scout-newsletter' );
			$detail   = self::test_error( $test );
		} else {
			$state    = $conn_ok ? 'good' : 'warn';
			$headline = $conn_ok ? __( 'Connected.', 'scout-newsletter' ) : __( 'Not tested yet.', 'scout-newsletter' );
			if ( $conn_ok ) {
				/* translators: %s: Beehiiv publication name. */
				$detail = sprintf( __( 'Beehiiv publication: %s.', 'scout-newsletter' ), '' !== $test['name'] ? $test['name'] : __( 'your publication', 'scout-newsletter' ) );
			} else {
				$detail = __( 'Press Test connection to check the key and publication.', 'scout-newsletter' );
			}
		}
		$detail .= ' ' . ( $sending ? __( 'Sending is on: ticked boxes go to Beehiiv within a few minutes.', 'scout-newsletter' ) : __( 'Sending is off: ticked boxes are saved here and wait until it is on.', 'scout-newsletter' ) );
		/* translators: 1: total sign-ups, 2: sent, 3: waiting, 4: held, 5: skipped, 6: failed. */
		$detail .= ' ' . sprintf( __( '%1$d sign-ups: %2$d sent, %3$d in the queue, %4$d held, %5$d skipped, %6$d failed.', 'scout-newsletter' ), array_sum( $counts ), $counts['sent'], $waiting, $counts['held'], $counts['skipped'], $counts['failed'] );
		if ( 'good' === $state && ( ! $sending || $counts['held'] || $counts['retry'] ) ) {
			$state = 'warn';
		}
		if ( $counts['failed'] ) {
			$state = 'bad';
		}
		self::ui_status( $state, $headline, $detail );
		?>

		<?php if ( $counts['held'] && $can ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Waiting sign-ups', 'scout-newsletter' ); ?></h2>
				<p>
					<?php
					/* translators: %d: number of sign-ups. */
					echo esc_html( sprintf( _n( '%d person ticked the box while sending was off or the key was refused.', '%d people ticked the box while sending was off or the key was refused.', $counts['held'], 'scout-newsletter' ), $counts['held'] ) );
					?>
					<?php esc_html_e( 'They agreed, so they can be sent once the connection works. On a staging copy of the site these are test sign-ups: leave them.', 'scout-newsletter' ); ?>
				</p>
				<?php if ( Scout_Newsletter::sending() ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="scout_newsletter_release">
						<?php wp_nonce_field( 'scout_newsletter_release' ); ?>
						<p class="sh-actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Send them to Beehiiv', 'scout-newsletter' ); ?></button></p>
					</form>
				<?php else : ?>
					<p class="sh-note"><?php esc_html_e( 'Connect Beehiiv and switch sending on first.', 'scout-newsletter' ); ?></p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php if ( $can ) : ?>
			<?php self::render_form( $s ); ?>
		<?php else : ?>
			<section class="sh-box">
				<p><?php esc_html_e( 'Scout looks after these settings. To change the newsletter tags or wording, send a change request.', 'scout-newsletter' ); ?></p>
			</section>
		<?php endif; ?>

		<?php self::render_records(); ?>
		<?php self::render_log(); ?>
		<?php
		Scout_Admin_UI::close();
	}

	private static function test_error( array $test ): string {
		if ( 'not_connected' === $test['err'] ) {
			return __( 'Add the API key and the publication ID first.', 'scout-newsletter' );
		}
		if ( 401 === (int) $test['code'] || 403 === (int) $test['code'] ) {
			return __( 'Beehiiv refused the API key. Check it was copied in full and has not been deleted in Beehiiv (Settings, API).', 'scout-newsletter' );
		}
		if ( 404 === (int) $test['code'] ) {
			return __( 'Beehiiv does not know that publication ID. It starts with pub_ and is on the same Beehiiv screen as the API key.', 'scout-newsletter' );
		}
		if ( 0 === (int) $test['code'] ) {
			return __( 'Beehiiv did not answer. Try again in a few minutes.', 'scout-newsletter' );
		}
		/* translators: %d: HTTP status code. */
		return sprintf( __( 'Beehiiv answered with an error (%d). Try again in a few minutes.', 'scout-newsletter' ), (int) $test['code'] );
	}

	private static function render_form( array $s ): void {
		$have_key = '' !== Scout_Newsletter::key();
		$labels   = Scout_Newsletter::sources();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="scout_newsletter_save">
			<?php wp_nonce_field( 'scout_newsletter_save' ); ?>
			<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Email list connection', 'scout-newsletter' ); ?></h2>
			<p class="sh-note"><?php esc_html_e( 'Your newsletter service is Beehiiv. Paste its key here so sign-ups reach your list.', 'scout-newsletter' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="nl-key"><?php esc_html_e( 'Beehiiv API key', 'scout-newsletter' ); ?></label></th>
					<td>
						<?php if ( Scout_Newsletter::key_in_config() ) : ?>
							<p><span class="sh-pill is-on"><?php esc_html_e( 'Set in wp-config.php', 'scout-newsletter' ); ?></span></p>
						<?php else : ?>
							<input type="password" id="nl-key" name="nl_key" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( $have_key ? __( 'Saved. Paste a new key to replace it.', 'scout-newsletter' ) : '' ); ?>">
							<?php if ( $have_key ) : ?>
								<label style="margin-left:8px"><input type="checkbox" name="forget_key" value="1"> <?php esc_html_e( 'Forget the saved key', 'scout-newsletter' ); ?></label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'From Beehiiv, Settings, API. Stored encrypted and sent only to Beehiiv. Or put it in wp-config.php as SCOUT_BEEHIIV_KEY.', 'scout-newsletter' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="nl-pub"><?php esc_html_e( 'Publication ID', 'scout-newsletter' ); ?></label></th>
					<td>
						<input type="text" id="nl-pub" name="nl[publication]" class="regular-text code" value="<?php echo esc_attr( $s['publication'] ); ?>" placeholder="pub_00000000-0000-0000-0000-000000000000" <?php disabled( defined( 'SCOUT_BEEHIIV_PUBLICATION' ) && '' !== (string) SCOUT_BEEHIIV_PUBLICATION ); ?>>
						<p class="description"><?php esc_html_e( 'Starts with pub_. Shown on the same Beehiiv screen as the API key.', 'scout-newsletter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sending', 'scout-newsletter' ); ?></th>
					<td>
						<label><input type="checkbox" name="nl[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> <?php esc_html_e( 'Send ticked boxes to Beehiiv', 'scout-newsletter' ); ?></label>
						<p class="description"><?php esc_html_e( 'Leave off on staging and until launch. Sign-ups are still saved here while off.', 'scout-newsletter' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="sh-actions">
				<button type="submit" class="button" form="scout-nl-test"><?php esc_html_e( 'Test connection', 'scout-newsletter' ); ?></button>
			</p>
			<p class="sh-note"><?php esc_html_e( 'Save first. Test connection checks the saved key and publication.', 'scout-newsletter' ); ?></p>
			</section>

			<?php self::ui_fold_open( __( 'More options', 'scout-newsletter' ) ); ?>
			<h3><?php esc_html_e( 'Tags by where they joined', 'scout-newsletter' ); ?></h3>
			<p><?php esc_html_e( 'The Beehiiv tag each sign-up gets. Use a comma between two tags. Beehiiv allows 100 tags, so tag by type, not by event.', 'scout-newsletter' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( $labels as $key => $label ) : ?>
					<tr>
						<th scope="row"><label for="nl-tag-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td><input type="text" id="nl-tag-<?php echo esc_attr( $key ); ?>" name="nl[tags][<?php echo esc_attr( $key ); ?>]" class="regular-text" value="<?php echo esc_attr( $s['tags'][ $key ] ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h3 style="margin-top:28px"><?php esc_html_e( 'What Beehiiv receives', 'scout-newsletter' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Confirmation email', 'scout-newsletter' ); ?></th>
					<td>
						<select name="nl[double_opt]" aria-label="<?php esc_attr_e( 'Confirmation email', 'scout-newsletter' ); ?>">
							<option value="not_set" <?php selected( $s['double_opt'], 'not_set' ); ?>><?php esc_html_e( 'Same as the Beehiiv setting (recommended)', 'scout-newsletter' ); ?></option>
							<option value="on" <?php selected( $s['double_opt'], 'on' ); ?>><?php esc_html_e( 'Always ask them to confirm', 'scout-newsletter' ); ?></option>
							<option value="off" <?php selected( $s['double_opt'], 'off' ); ?>><?php esc_html_e( 'Never ask them to confirm', 'scout-newsletter' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Matching Beehiiv keeps site sign-ups the same as the footer form.', 'scout-newsletter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Welcome email', 'scout-newsletter' ); ?></th>
					<td><label><input type="checkbox" name="nl[welcome]" value="1" <?php checked( ! empty( $s['welcome'] ) ); ?>> <?php esc_html_e( "Send Beehiiv's welcome email to new sign-ups", 'scout-newsletter' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="nl-auto"><?php esc_html_e( 'Automation ID', 'scout-newsletter' ); ?></label></th>
					<td>
						<input type="text" id="nl-auto" name="nl[automation]" class="regular-text code" value="<?php echo esc_attr( $s['automation'] ); ?>">
						<p class="description"><?php esc_html_e( 'Optional. A Beehiiv automation whose trigger is "Add by API", such as a "joined on the site" welcome. Separate two with a comma.', 'scout-newsletter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="nl-utm"><?php esc_html_e( 'Source name', 'scout-newsletter' ); ?></label></th>
					<td>
						<input type="text" id="nl-utm" name="nl[utm_source]" class="regular-text" value="<?php echo esc_attr( $s['utm_source'] ); ?>" placeholder="<?php echo esc_attr( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>">
						<p class="description"><?php esc_html_e( 'Sent as utm_source. Where they joined (event, ticket and so on) goes as utm_medium, so Beehiiv reports show both.', 'scout-newsletter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Beehiiv custom fields', 'scout-newsletter' ); ?></th>
					<td>
						<p><label><?php esc_html_e( 'First name field', 'scout-newsletter' ); ?><br><input type="text" name="nl[field_first]" class="regular-text" value="<?php echo esc_attr( $s['field_first'] ); ?>" placeholder="First Name"></label></p>
						<p><label><?php esc_html_e( '"Joined from" field', 'scout-newsletter' ); ?><br><input type="text" name="nl[field_joined]" class="regular-text" value="<?php echo esc_attr( $s['field_joined'] ); ?>"></label></p>
						<p><label><?php esc_html_e( 'Member plan field', 'scout-newsletter' ); ?><br><input type="text" name="nl[field_plan]" class="regular-text" value="<?php echo esc_attr( $s['field_plan'] ); ?>"></label></p>
						<p class="description"><?php esc_html_e( 'Each name must match a Beehiiv custom field letter for letter. Leave a box empty to send nothing.', 'scout-newsletter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Keep subscribers up to date', 'scout-newsletter' ); ?></th>
					<td>
						<label><input type="checkbox" name="nl[keep_current]" value="1" <?php checked( ! empty( $s['keep_current'] ) ); ?>> <?php esc_html_e( 'Tag people already on the list when they sign up, buy a ticket or join', 'scout-newsletter' ); ?></label>
						<p class="description"><?php esc_html_e( 'Never subscribes anyone. People not already on the list are left alone.', 'scout-newsletter' ); ?></p>
					</td>
				</tr>
			</table>

			<h3 style="margin-top:28px"><?php esc_html_e( 'The box on this site', 'scout-newsletter' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="nl-name"><?php esc_html_e( 'Newsletter name', 'scout-newsletter' ); ?></label></th>
					<td><input type="text" id="nl-name" name="nl[name]" class="regular-text" value="<?php echo esc_attr( $s['name'] ); ?>" placeholder="<?php echo esc_attr( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="nl-wording"><?php esc_html_e( 'Words next to the box', 'scout-newsletter' ); ?></label></th>
					<td>
						<input type="text" id="nl-wording" name="nl[wording]" class="large-text" value="<?php echo esc_attr( $s['wording'] ); ?>" placeholder="<?php echo esc_attr( Scout_Newsletter::wording() ); ?>">
						<p class="description"><?php esc_html_e( 'Shown beside the checkout box and the sign-up box, and saved with each yes.', 'scout-newsletter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Checkout', 'scout-newsletter' ); ?></th>
					<td>
						<label><input type="checkbox" name="nl[checkout_box]" value="1" <?php checked( ! empty( $s['checkout_box'] ) ); ?>> <?php esc_html_e( 'Add the newsletter box to every WooCommerce checkout', 'scout-newsletter' ); ?></label>
						<p class="description"><?php esc_html_e( 'Starts unticked. Checkouts with event tickets always show it. Added to the list once the order is paid.', 'scout-newsletter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="nl-members"><?php esc_html_e( 'Membership application', 'scout-newsletter' ); ?></label></th>
					<td>
						<input type="text" id="nl-members" name="nl[members_box]" class="large-text" value="<?php echo esc_attr( $s['members_box'] ); ?>">
						<p class="description"><?php esc_html_e( 'The tick-box question on the membership application, word for word. Left empty, any tick-box question naming the newsletter counts.', 'scout-newsletter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="nl-thanks"><?php esc_html_e( 'Thank-you message', 'scout-newsletter' ); ?></label></th>
					<td>
						<input type="text" id="nl-thanks" name="nl[thanks]" class="large-text" value="<?php echo esc_attr( $s['thanks'] ); ?>" placeholder="<?php esc_attr_e( 'Thank you. Watch your inbox for a note from us.', 'scout-newsletter' ); ?>">
						<p class="description"><?php esc_html_e( 'Shown after someone uses the sign-up box ([scout_newsletter] or the Newsletter sign-up block).', 'scout-newsletter' ); ?></p>
					</td>
				</tr>
			</table>
			<?php self::ui_fold_close(); ?>
			<p class="sh-actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'scout-newsletter' ); ?></button></p>
		</form>
		<form id="scout-nl-test" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="scout_newsletter_test">
			<?php wp_nonce_field( 'scout_newsletter_test' ); ?>
		</form>
		<?php
	}

	private static function render_records(): void {
		$rows   = Scout_Newsletter_Store::recent( 15 );
		$labels = Scout_Newsletter::sources() + array( 'membership_application' => __( 'Membership application', 'scout-newsletter' ) );
		?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Who said yes', 'scout-newsletter' ); ?></h2>
			<p><?php esc_html_e( 'Who said yes, when, where and the words they saw. Kept as proof that each person asked to join.', 'scout-newsletter' ); ?></p>
			<?php if ( $rows ) : ?>
				<div class="scout-nl-scroll" style="overflow-x:auto"><table class="widefat striped">
					<thead><tr><th scope="col"><?php esc_html_e( 'Agreed', 'scout-newsletter' ); ?></th><th scope="col"><?php esc_html_e( 'Who', 'scout-newsletter' ); ?></th><th scope="col"><?php esc_html_e( 'Where', 'scout-newsletter' ); ?></th><th scope="col"><?php esc_html_e( 'Words shown', 'scout-newsletter' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'scout-newsletter' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'M j, Y g:i a', (int) strtotime( $r['created'] . ' UTC' ) ) ); ?></td>
							<td><?php echo esc_html( Scout_Newsletter::mask( $r['email'] ) ); ?></td>
							<td><?php echo esc_html( $labels[ $r['source'] ] ?? ( '' !== $r['source'] ? $r['source'] : '-' ) ); ?></td>
							<td><?php echo esc_html( wp_trim_words( $r['wording'], 14 ) ); ?></td>
							<td><span class="sh-pill <?php echo esc_attr( 'sent' === $r['status'] ? 'is-done' : ( in_array( $r['status'], array( 'failed', 'skipped' ), true ) ? '' : 'is-received' ) ); ?>"><?php echo esc_html( self::status_label( $r['status'] ) ); ?></span> <span class="description"><?php echo esc_html( $r['result'] ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
				<?php if ( self::can_change() ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="scout_newsletter_export">
						<?php wp_nonce_field( 'scout_newsletter_export' ); ?>
						<p class="sh-actions" style="margin-top:14px"><button type="submit" class="button sh-quiet"><?php esc_html_e( 'Download spreadsheet', 'scout-newsletter' ); ?></button></p>
					</form>
				<?php endif; ?>
			<?php else : ?>
				<p class="sh-note"><?php esc_html_e( 'Nobody has ticked a newsletter box on this site yet.', 'scout-newsletter' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function status_label( string $status ): string {
		$map = array(
			'held'    => __( 'Waiting', 'scout-newsletter' ),
			'queued'  => __( 'In the queue', 'scout-newsletter' ),
			'retry'   => __( 'Trying again', 'scout-newsletter' ),
			'sent'    => __( 'Sent', 'scout-newsletter' ),
			'skipped' => __( 'Skipped', 'scout-newsletter' ),
			'failed'  => __( 'Failed', 'scout-newsletter' ),
		);
		return $map[ $status ] ?? $status;
	}

	private static function render_log(): void {
		$log = array_reverse( (array) get_option( Scout_Newsletter::LOG, array() ) );
		?>
		<?php self::ui_fold_open( __( 'Recent activity', 'scout-newsletter' ) ); ?>
			<p><?php esc_html_e( 'What the site sent to Beehiiv and how it went. People show as a short code.', 'scout-newsletter' ); ?></p>
			<?php if ( $log ) : ?>
				<div class="scout-nl-scroll" style="overflow-x:auto"><table class="widefat striped">
					<thead><tr><th scope="col"><?php esc_html_e( 'When', 'scout-newsletter' ); ?></th><th scope="col"><?php esc_html_e( 'Person', 'scout-newsletter' ); ?></th><th scope="col"><?php esc_html_e( 'What', 'scout-newsletter' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'scout-newsletter' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $log, 0, 20 ) as $l ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'M j, g:i a', (int) $l['t'] ) ); ?></td>
							<td><code><?php echo esc_html( '' !== $l['h'] ? $l['h'] : '-' ); ?></code></td>
							<td><?php echo esc_html( $l['w'] ); ?></td>
							<td><?php echo esc_html( $l['r'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php else : ?>
				<p class="sh-note"><?php esc_html_e( 'Nothing yet.', 'scout-newsletter' ); ?></p>
			<?php endif; ?>
		<?php self::ui_fold_close(); ?>
		<?php
	}
}
