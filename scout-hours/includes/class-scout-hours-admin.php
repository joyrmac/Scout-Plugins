<?php
/**
 * Scout Build -> Hours: weekly hours, the confirmation switch, holiday
 * closures, the notice bar, and the reminder to update Google.
 *
 * Every change posts to admin-post.php with its own nonce and a
 * manage_options check, then returns to the screen.
 *
 * @package Scout_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hours_Admin {

	const SLUG = 'scout-hours';

	/** Days before a closure that the Google reminder appears. */
	const REMIND_DAYS = 7;

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'reminders' ) );
		add_action( 'admin_post_scout_hours_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_scout_hours_add', array( __CLASS__, 'add' ) );
		add_action( 'admin_post_scout_hours_delete', array( __CLASS__, 'delete' ) );
		add_action( 'admin_post_scout_hours_dismiss', array( __CLASS__, 'dismiss' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_HOURS_FILE ), array( __CLASS__, 'action_link' ) );
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
		add_submenu_page( self::parent(), __( 'Hours', 'scout-hours' ), __( 'Hours', 'scout-hours' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		Scout_Admin_UI::enqueue( SCOUT_HOURS_URL . 'assets/scout-admin.css' );
		wp_enqueue_style( 'scout-hours-admin', SCOUT_HOURS_URL . 'assets/hours-admin.css', array( 'scout-admin' ), SCOUT_HOURS_VERSION );
		wp_enqueue_script( 'scout-hours-admin', SCOUT_HOURS_URL . 'assets/hours-admin.js', array(), SCOUT_HOURS_VERSION, true );
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'scout-hours' ) . '</a>' );
		return $links;
	}

	/* ---- Saving ------------------------------------------------------- */

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change the hours.', 'scout-hours' ), 403 );
		}
		check_admin_referer( $action );
	}

	/** Back to the screen the form came from (the menu is not built on admin-post.php). */
	private static function back( string $msg ): void {
		$ref = wp_get_referer();
		$url = ( $ref && false !== strpos( $ref, 'page=' . self::SLUG ) ) ? remove_query_arg( 'scout-hours', $ref ) : self::url();
		wp_safe_redirect( add_query_arg( 'scout-hours', $msg, $url ) );
		exit;
	}

	public static function save(): void {
		self::guard( 'scout_hours_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$in   = isset( $_POST['scout_hours'] ) ? wp_unslash( (array) $_POST['scout_hours'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is cleaned below.
		$days = array();
		foreach ( array_keys( Scout_Hours::days() ) as $key ) {
			$d      = (array) ( $in['days'][ $key ] ?? array() );
			$ranges = array( array( (string) ( $d['open1'] ?? '' ), (string) ( $d['close1'] ?? '' ) ) );
			if ( ! empty( $d['split'] ) ) {
				$ranges[] = array( (string) ( $d['open2'] ?? '' ), (string) ( $d['close2'] ?? '' ) );
			}
			$days[ $key ] = array(
				'open'   => ! empty( $d['open'] ) ? 1 : 0,
				'ranges' => Scout_Hours::clean_ranges( $ranges ),
			);
		}
		$bar = (string) ( $in['bar'] ?? 'off' );
		// phpcs:enable
		update_option(
			Scout_Hours::OPTION,
			array(
				'confirmed' => ! empty( $in['confirmed'] ) ? 1 : 0,
				'bar'       => in_array( $bar, array( 'off', 'day', 'week' ), true ) ? $bar : 'off',
				'days'      => $days,
			)
		);
		self::back( 'saved' );
	}

	public static function add(): void {
		self::guard( 'scout_hours_add' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked in guard(); cleaned by clean_closure().
		$in = isset( $_POST['closure'] ) ? wp_unslash( (array) $_POST['closure'] ) : array();
		$c  = Scout_Hours::clean_closure(
			array(
				'id'     => strtolower( wp_generate_password( 10, false ) ),
				'name'   => (string) ( $in['name'] ?? '' ),
				'start'  => (string) ( $in['start'] ?? '' ),
				'end'    => (string) ( $in['end'] ?? '' ),
				'type'   => (string) ( $in['type'] ?? 'closed' ),
				'ranges' => array( array( (string) ( $in['open'] ?? '' ), (string) ( $in['close'] ?? '' ) ) ),
				'note'   => (string) ( $in['note'] ?? '' ),
			)
		);
		if ( ! $c ) {
			self::back( 'nodate' );
		}
		if ( 'special' === ( $in['type'] ?? '' ) && 'closed' === $c['type'] ) {
			self::back( 'notimes' );
		}
		// Keep the list tidy: drop closures that ended more than a month ago.
		$cutoff = Scout_Hours::now()->modify( '-31 days' )->format( 'Y-m-d' );
		$list   = array_filter(
			Scout_Hours::closures(),
			static function ( $x ) use ( $cutoff ) {
				return $x['end'] >= $cutoff;
			}
		);
		$list[] = $c;
		update_option( Scout_Hours::CLOSURES, array_values( $list ) );
		self::back( 'added' );
	}

	public static function delete(): void {
		self::guard( 'scout_hours_delete' );
		$id   = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$list = array_filter(
			Scout_Hours::closures(),
			static function ( $x ) use ( $id ) {
				return $x['id'] !== $id;
			}
		);
		update_option( Scout_Hours::CLOSURES, array_values( $list ) );
		$dismissed = array_diff( (array) get_option( Scout_Hours::DISMISSED, array() ), array( $id ) );
		update_option( Scout_Hours::DISMISSED, array_values( $dismissed ), false );
		self::back( 'removed' );
	}

	public static function dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change the hours.', 'scout-hours' ), 403 );
		}
		check_admin_referer( 'scout_hours_dismiss' );
		$id        = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$dismissed = (array) get_option( Scout_Hours::DISMISSED, array() );
		if ( '' !== $id && ! in_array( $id, $dismissed, true ) ) {
			$dismissed[] = $id;
		}
		// Forget ids of closures that no longer exist.
		$ids = wp_list_pluck( Scout_Hours::closures(), 'id' );
		update_option( Scout_Hours::DISMISSED, array_values( array_intersect( $dismissed, $ids ) ), false );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/* ---- Reminder to update Google ----------------------------------- */

	/** Closures starting within REMIND_DAYS that still need the reminder. */
	public static function due_reminders(): array {
		if ( ! Scout_Hours::is_public() ) {
			return array();
		}
		$today     = Scout_Hours::now()->format( 'Y-m-d' );
		$limit     = Scout_Hours::now()->modify( '+' . self::REMIND_DAYS . ' days' )->format( 'Y-m-d' );
		$dismissed = (array) get_option( Scout_Hours::DISMISSED, array() );
		return array_values(
			array_filter(
				Scout_Hours::closures(),
				static function ( $c ) use ( $today, $limit, $dismissed ) {
					return $c['start'] >= $today && $c['start'] <= $limit && ! in_array( $c['id'], $dismissed, true );
				}
			)
		);
	}

	public static function reminders(): void {
		$screen = get_current_screen();
		if ( ! current_user_can( 'manage_options' ) || ! $screen || ( 'dashboard' !== $screen->id && false === strpos( $screen->id, self::SLUG ) ) ) {
			return;
		}
		foreach ( self::due_reminders() as $c ) {
			$name    = '' !== $c['name'] ? $c['name'] : __( 'A closure', 'scout-hours' );
			$what    = 'closed' === $c['type']
				? __( 'closed', 'scout-hours' )
				/* translators: %s: hours, e.g. "10:00 AM to 2:00 PM". */
				: sprintf( __( 'open %s', 'scout-hours' ), Scout_Hours::ranges_label( $c['ranges'] ) );
			$dismiss = wp_nonce_url(
				admin_url( 'admin-post.php?action=scout_hours_dismiss&id=' . rawurlencode( $c['id'] ) ),
				'scout_hours_dismiss'
			);
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Reminder to update Google', 'scout-hours' ); ?></strong><br>
					<?php
					printf(
						/* translators: 1: holiday name, 2: date or dates, 3: "closed" or "open 10:00 AM to 2:00 PM". */
						esc_html__( '%1$s is coming up on %2$s. Your site will show you as %3$s. Add the same special hours to your Google Business Profile so Google Search and Maps match.', 'scout-hours' ),
						esc_html( $name ),
						esc_html( Scout_Hours::closure_dates_label( $c ) ),
						esc_html( $what )
					);
					?>
				</p>
				<p>
					<a class="button button-primary" href="https://business.google.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Google Business Profile', 'scout-hours' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'scout-hours' ); ?></span></a>
					<a class="button" href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Done, hide this reminder', 'scout-hours' ); ?></a>
				</p>
			</div>
			<?php
		}
	}

	/* ---- Screen ------------------------------------------------------- */

	private static function message(): void {
		$msg   = isset( $_GET['scout-hours'] ) ? sanitize_key( wp_unslash( $_GET['scout-hours'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$texts = array(
			'saved'   => array( 'success', __( 'Hours saved.', 'scout-hours' ) ),
			'added'   => array( 'success', __( 'Closure added.', 'scout-hours' ) ),
			'removed' => array( 'success', __( 'Closure removed.', 'scout-hours' ) ),
			'nodate'  => array( 'error', __( 'Pick the date of the closure, then add it again.', 'scout-hours' ) ),
			'notimes' => array( 'error', __( 'Special hours need an opening and a closing time.', 'scout-hours' ) ),
		);
		if ( isset( $texts[ $msg ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $texts[ $msg ][0] ), esc_html( $texts[ $msg ][1] ) );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s         = Scout_Hours::settings();
		$confirmed = $s['confirmed'];
		$closures  = Scout_Hours::upcoming_closures();
		$route     = Scout_Hours_Schema::route();

		Scout_Admin_UI::open(
			__( 'When you’re open', 'scout-hours' ),
			__( 'Set your weekly hours and holiday closures once. The site shows them, keeps the "Open now" line current, and adds them to the schema Google reads.', 'scout-hours' ),
			__( 'Hours', 'scout-hours' )
		);
		self::message();
		?>
		<div class="shh-layout">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="shh-main">
				<input type="hidden" name="action" value="scout_hours_save">
				<?php wp_nonce_field( 'scout_hours_save' ); ?>

				<section class="sh-box shh-confirm <?php echo $confirmed ? 'is-confirmed' : 'is-pending'; ?>">
					<div class="shh-confirm-head">
						<span class="sh-pill <?php echo $confirmed ? 'is-on' : 'is-received'; ?>"><?php echo esc_html( $confirmed ? __( 'Hours confirmed', 'scout-hours' ) : __( 'Hours not confirmed yet', 'scout-hours' ) ); ?></span>
					</div>
					<p class="shh-confirm-text">
						<?php
						echo esc_html(
							$confirmed
								? __( 'The hours show on the site and in schema. Untick the box to take them down while you check something.', 'scout-hours' )
								: __( 'The hours stay off the site and out of schema until the owner confirms them. People who edit the site see a short note where the hours will go.', 'scout-hours' )
						);
						?>
					</p>
					<label class="shh-check-row" for="shh-confirmed">
						<input type="checkbox" id="shh-confirmed" name="scout_hours[confirmed]" value="1" <?php checked( $confirmed ); ?>>
						<span><?php esc_html_e( 'The owner has confirmed these hours. Show them on the site.', 'scout-hours' ); ?></span>
					</label>
				</section>

				<section class="sh-box">
					<div class="shh-box-head">
						<h2 class="sh-title"><?php esc_html_e( 'Weekly hours', 'scout-hours' ); ?></h2>
						<button type="button" class="button sh-quiet shh-copy" hidden aria-live="polite" data-done="<?php esc_attr_e( 'Copied. Save to keep it.', 'scout-hours' ); ?>"><?php esc_html_e( 'Copy Monday to weekdays', 'scout-hours' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( 'Tick a day to open it. Add a second range for a day with a break, like lunch. A closing time after midnight runs into the next morning.', 'scout-hours' ); ?></p>
					<div class="shh-days">
						<?php
						foreach ( Scout_Hours::ordered_days() as $key ) {
							self::day_row( $key, $s['days'][ $key ] );
						}
						?>
					</div>
				</section>

				<section class="sh-box">
					<h2 class="sh-title"><?php esc_html_e( 'Notice bar', 'scout-hours' ); ?></h2>
					<p><?php esc_html_e( 'A thin bar across the top of every page about a closure, with its note. Visitors can hide it.', 'scout-hours' ); ?></p>
					<fieldset class="shh-radios">
						<legend class="screen-reader-text"><?php esc_html_e( 'When to show the notice bar', 'scout-hours' ); ?></legend>
						<label><input type="radio" name="scout_hours[bar]" value="off" <?php checked( 'off', $s['bar'] ); ?>> <?php esc_html_e( 'Off', 'scout-hours' ); ?></label>
						<label><input type="radio" name="scout_hours[bar]" value="day" <?php checked( 'day', $s['bar'] ); ?>> <?php esc_html_e( 'On closure days', 'scout-hours' ); ?></label>
						<label><input type="radio" name="scout_hours[bar]" value="week" <?php checked( 'week', $s['bar'] ); ?>> <?php esc_html_e( 'From a week before through the closure', 'scout-hours' ); ?></label>
					</fieldset>
				</section>

				<p class="shh-save"><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save hours', 'scout-hours' ); ?></button></p>
			</form>

			<div class="shh-side">
				<section class="sh-box">
					<h2 class="sh-title"><?php esc_html_e( 'Holiday closures', 'scout-hours' ); ?></h2>
					<p><?php esc_html_e( 'Days you are closed or keep different hours. Each one is listed under the hours table for the month before, and past dates drop off by themselves.', 'scout-hours' ); ?></p>
					<?php if ( $closures ) : ?>
						<ul class="shh-closures">
							<?php foreach ( $closures as $c ) : ?>
								<li>
									<div class="shh-closure-main">
										<p class="shh-closure-name"><?php echo esc_html( '' !== $c['name'] ? $c['name'] : __( 'Closure', 'scout-hours' ) ); ?></p>
										<p class="shh-closure-when"><?php echo esc_html( Scout_Hours::closure_dates_label( $c ) ); ?> · <?php echo esc_html( 'closed' === $c['type'] ? __( 'Closed', 'scout-hours' ) : Scout_Hours::ranges_label( $c['ranges'] ) ); ?></p>
										<?php if ( '' !== $c['note'] ) : ?>
											<p class="shh-closure-note"><?php echo esc_html( $c['note'] ); ?></p>
										<?php endif; ?>
									</div>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="scout_hours_delete">
										<input type="hidden" name="id" value="<?php echo esc_attr( $c['id'] ); ?>">
										<?php wp_nonce_field( 'scout_hours_delete' ); ?>
										<button type="submit" class="button sh-quiet shh-remove">
											<?php esc_html_e( 'Remove', 'scout-hours' ); ?><span class="screen-reader-text"> <?php echo esc_html( $c['name'] ); ?></span>
										</button>
									</form>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="shh-empty"><?php esc_html_e( 'No closures coming up.', 'scout-hours' ); ?></p>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="shh-add">
						<input type="hidden" name="action" value="scout_hours_add">
						<?php wp_nonce_field( 'scout_hours_add' ); ?>
						<h3 class="shh-add-title"><?php esc_html_e( 'Add a closure', 'scout-hours' ); ?></h3>
						<div class="shh-field shh-field-wide">
							<label for="shh-c-name"><?php esc_html_e( 'Name', 'scout-hours' ); ?></label>
							<input type="text" id="shh-c-name" name="closure[name]" placeholder="<?php esc_attr_e( 'For example, Independence Day', 'scout-hours' ); ?>">
						</div>
						<div class="shh-field">
							<label for="shh-c-start"><?php esc_html_e( 'First day', 'scout-hours' ); ?></label>
							<input type="date" id="shh-c-start" name="closure[start]" required min="<?php echo esc_attr( Scout_Hours::now()->format( 'Y-m-d' ) ); ?>">
						</div>
						<div class="shh-field">
							<label for="shh-c-end"><?php esc_html_e( 'Last day (if more than one)', 'scout-hours' ); ?></label>
							<input type="date" id="shh-c-end" name="closure[end]" min="<?php echo esc_attr( Scout_Hours::now()->format( 'Y-m-d' ) ); ?>">
						</div>
						<fieldset class="shh-field shh-field-wide shh-radios">
							<legend><?php esc_html_e( 'That day', 'scout-hours' ); ?></legend>
							<label><input type="radio" name="closure[type]" value="closed" checked> <?php esc_html_e( 'Closed all day', 'scout-hours' ); ?></label>
							<label><input type="radio" name="closure[type]" value="special"> <?php esc_html_e( 'Open with special hours', 'scout-hours' ); ?></label>
						</fieldset>
						<div class="shh-field shh-special">
							<label for="shh-c-open"><?php esc_html_e( 'Opens', 'scout-hours' ); ?></label>
							<input type="time" id="shh-c-open" name="closure[open]">
						</div>
						<div class="shh-field shh-special">
							<label for="shh-c-close"><?php esc_html_e( 'Closes', 'scout-hours' ); ?></label>
							<input type="time" id="shh-c-close" name="closure[close]">
						</div>
						<div class="shh-field shh-field-wide">
							<label for="shh-c-note"><?php esc_html_e( 'Note for visitors (optional)', 'scout-hours' ); ?></label>
							<input type="text" id="shh-c-note" name="closure[note]" placeholder="<?php esc_attr_e( 'For example, Messages left that day are answered the next morning.', 'scout-hours' ); ?>">
						</div>
						<p class="shh-field-wide"><button type="submit" class="button"><?php esc_html_e( 'Add closure', 'scout-hours' ); ?></button></p>
					</form>
				</section>

				<section class="sh-box shh-where <?php echo $confirmed ? '' : 'is-pending'; ?>">
					<h2 class="sh-title"><?php esc_html_e( 'Where it updates', 'scout-hours' ); ?></h2>
					<?php if ( ! $confirmed ) : ?>
						<p><?php esc_html_e( 'Nothing goes out until the hours are confirmed.', 'scout-hours' ); ?></p>
					<?php endif; ?>
					<ul class="shh-where-list">
						<?php
						$rows = array(
							array(
								true,
								__( 'Hours table and "Open now" line', 'scout-hours' ),
								__( 'Wherever [scout_hours] is placed, usually the footer and the contact page. The line follows the site’s timezone.', 'scout-hours' ),
							),
							array(
								'none' !== $route,
								__( 'Schema', 'scout-hours' ),
								'core' === $route
									? __( 'Opening hours and holiday hours are added to the business details from Scout Core.', 'scout-hours' )
									: ( 'standalone' === $route
										? __( 'Opening hours and holiday hours go out in a small business entry of their own.', 'scout-hours' )
										: __( 'This theme writes its own business schema, so the hours are left for the theme to add.', 'scout-hours' ) ),
							),
							array(
								'off' !== $s['bar'],
								__( 'Notice bar', 'scout-hours' ),
								'off' === $s['bar'] ? __( 'Off. Turn it on under Notice bar.', 'scout-hours' ) : ( 'week' === $s['bar'] ? __( 'From a week before each closure.', 'scout-hours' ) : __( 'On closure days.', 'scout-hours' ) ),
							),
							array(
								true,
								__( 'Reminder to update Google', 'scout-hours' ),
								__( 'A dashboard reminder a week before each closure to set the same special hours on your Google Business Profile.', 'scout-hours' ),
							),
						);
						foreach ( $rows as $row ) :
							$state = ! $confirmed ? 'is-pending' : ( $row[0] ? 'is-on' : 'is-off' );
							?>
							<li class="<?php echo esc_attr( $state ); ?>">
								<span class="shh-tick" aria-hidden="true"></span>
								<div>
									<p class="shh-where-name"><?php echo esc_html( $row[1] ); ?></p>
									<p class="sh-note"><?php echo esc_html( $row[2] ); ?></p>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>

				<section class="sh-box">
					<h2 class="sh-title"><?php esc_html_e( 'Showing the hours', 'scout-hours' ); ?></h2>
					<table class="sh-table">
						<tr><td>[scout_hours]</td><td><?php esc_html_e( 'The "Open now" line and the hours table.', 'scout-hours' ); ?></td></tr>
						<tr><td>[scout_hours show="status"]</td><td><?php esc_html_e( 'Only the "Open now" line, for a header or footer.', 'scout-hours' ); ?></td></tr>
						<tr><td>[scout_hours show="table"]</td><td><?php esc_html_e( 'Only the hours table.', 'scout-hours' ); ?></td></tr>
						<tr><td>scout_hours_html()</td><td><?php esc_html_e( 'The same from a theme template, with the same options.', 'scout-hours' ); ?></td></tr>
					</table>
				</section>
			</div>
		</div>
		<?php
		Scout_Admin_UI::close();
	}

	/** One day: open box, first range, optional second range. */
	private static function day_row( string $key, array $day ): void {
		$label  = Scout_Hours::day_label( $key );
		$name   = 'scout_hours[days][' . $key . ']';
		$r1     = $day['ranges'][0] ?? array( '09:00', '17:00' );
		$r2     = $day['ranges'][1] ?? array( '', '' );
		$split  = isset( $day['ranges'][1] );
		$id     = 'shh-' . $key;
		?>
		<fieldset class="shh-day <?php echo $day['open'] ? 'is-open' : 'is-closed'; ?>" data-day="<?php echo esc_attr( $key ); ?>">
			<legend class="screen-reader-text"><?php echo esc_html( $label ); ?></legend>
			<label class="shh-day-name" for="<?php echo esc_attr( $id ); ?>-open">
				<input type="checkbox" id="<?php echo esc_attr( $id ); ?>-open" class="shh-open" name="<?php echo esc_attr( $name ); ?>[open]" value="1" <?php checked( $day['open'] ); ?>>
				<span><?php echo esc_html( $label ); ?></span>
			</label>
			<p class="shh-closed-label"><?php esc_html_e( 'Closed', 'scout-hours' ); ?></p>
			<div class="shh-times">
				<div class="shh-range">
					<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-o1">
						<?php
						/* translators: %s: day name. */
						printf( esc_html__( '%s opens at', 'scout-hours' ), esc_html( $label ) );
						?>
					</label>
					<input type="time" id="<?php echo esc_attr( $id ); ?>-o1" name="<?php echo esc_attr( $name ); ?>[open1]" value="<?php echo esc_attr( $r1[0] ); ?>">
					<span class="shh-to" aria-hidden="true"><?php esc_html_e( 'to', 'scout-hours' ); ?></span>
					<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-c1">
						<?php
						/* translators: %s: day name. */
						printf( esc_html__( '%s closes at', 'scout-hours' ), esc_html( $label ) );
						?>
					</label>
					<input type="time" id="<?php echo esc_attr( $id ); ?>-c1" name="<?php echo esc_attr( $name ); ?>[close1]" value="<?php echo esc_attr( $r1[1] ); ?>">
				</div>
				<div class="shh-range shh-range-2">
					<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-o2">
						<?php
						/* translators: %s: day name. */
						printf( esc_html__( '%s opens again at', 'scout-hours' ), esc_html( $label ) );
						?>
					</label>
					<input type="time" id="<?php echo esc_attr( $id ); ?>-o2" name="<?php echo esc_attr( $name ); ?>[open2]" value="<?php echo esc_attr( $r2[0] ); ?>">
					<span class="shh-to" aria-hidden="true"><?php esc_html_e( 'to', 'scout-hours' ); ?></span>
					<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-c2">
						<?php
						/* translators: %s: day name. */
						printf( esc_html__( '%s closes again at', 'scout-hours' ), esc_html( $label ) );
						?>
					</label>
					<input type="time" id="<?php echo esc_attr( $id ); ?>-c2" name="<?php echo esc_attr( $name ); ?>[close2]" value="<?php echo esc_attr( $r2[1] ); ?>">
				</div>
				<label class="shh-split" for="<?php echo esc_attr( $id ); ?>-split">
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>-split" class="shh-split-box" name="<?php echo esc_attr( $name ); ?>[split]" value="1" <?php checked( $split ); ?>>
					<?php esc_html_e( 'Second range', 'scout-hours' ); ?>
				</label>
			</div>
		</fieldset>
		<?php
	}
}
