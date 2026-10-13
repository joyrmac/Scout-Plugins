<?php
/**
 * What visitors see: the hours table, the live "Open now" line, and the
 * optional notice bar on closure days.
 *
 *   [scout_hours]                 table and status line
 *   [scout_hours show="status"]   only the status line
 *   [scout_hours show="table"]    only the table
 *   scout_hours_html( array( 'show' => 'status' ) )  the same from PHP
 *
 * The status line is written into the page for visitors without scripts,
 * then a small script recomputes it from a two-week plan printed with the
 * page, so a cached page still says the right thing an hour (or a week)
 * later. The script only loads on pages that show hours or the bar.
 *
 * Until the hours are confirmed nothing renders for visitors. People who can
 * edit the site see a short note in its place.
 *
 * @package Scout_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hours_Front {

	/** Upcoming special dates listed under the table, in days. */
	const LIST_AHEAD = 30;

	private static bool $bar_printed = false;
	private static bool $data_added  = false;

	public static function boot(): void {
		add_shortcode( 'scout_hours', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'bar_top' ), 5 );
		add_action( 'wp_footer', array( __CLASS__, 'bar_fallback' ), 5 );
	}

	public static function register(): void {
		wp_register_style( 'scout-hours', SCOUT_HOURS_URL . 'assets/scout-hours.css', array(), SCOUT_HOURS_VERSION );
		wp_register_script(
			'scout-hours',
			SCOUT_HOURS_URL . 'assets/scout-hours.js',
			array(),
			SCOUT_HOURS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		// Load early when the page content already carries the shortcode.
		$post = get_post();
		if ( Scout_Hours::is_public() && $post && has_shortcode( (string) $post->post_content, 'scout_hours' ) ) {
			self::enqueue();
		}
		if ( null !== self::bar_message() ) {
			self::enqueue();
		}
	}

	private static function enqueue(): void {
		if ( ! wp_script_is( 'scout-hours', 'registered' ) ) {
			self::register_only();
		}
		wp_enqueue_style( 'scout-hours' );
		wp_enqueue_script( 'scout-hours' );
		if ( ! self::$data_added ) {
			self::$data_added = true;
			$data             = array(
				'days' => Scout_Hours::payload(),
				't'    => Scout_Hours::strings(),
				'bar'  => self::bar_candidates(),
			);
			wp_add_inline_script( 'scout-hours', 'window.scoutHours=' . wp_json_encode( $data ) . ';', 'before' );
		}
	}

	/** Registration for calls that come before wp_enqueue_scripts (rare). */
	private static function register_only(): void {
		wp_register_style( 'scout-hours', SCOUT_HOURS_URL . 'assets/scout-hours.css', array(), SCOUT_HOURS_VERSION );
		wp_register_script( 'scout-hours', SCOUT_HOURS_URL . 'assets/scout-hours.js', array(), SCOUT_HOURS_VERSION, true );
	}

	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'show' => 'both' ), $atts, 'scout_hours' );
		return self::html( array( 'show' => (string) $atts['show'] ) );
	}

	/**
	 * The hours block.
	 *
	 * @param array{show?:string} $args show: 'both' (default), 'table' or 'status'.
	 */
	public static function html( array $args = array() ): string {
		$show = in_array( $args['show'] ?? 'both', array( 'both', 'table', 'status' ), true ) ? ( $args['show'] ?? 'both' ) : 'both';
		if ( ! Scout_Hours::is_public() ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p class="scout-hours-pending">' . esc_html__( 'The hours show here once they are marked as confirmed in Hours. Only people who edit the site see this note.', 'scout-hours' ) . '</p>';
			}
			return '';
		}
		if ( ! Scout_Hours::has_hours() ) {
			return '';
		}
		self::enqueue();

		$out = '<div class="scout-hours">';
		if ( 'table' !== $show ) {
			$out .= self::status_html();
		}
		if ( 'status' !== $show ) {
			$out .= self::table_html();
		}
		return $out . '</div>';
	}

	private static function status_html(): string {
		$status = Scout_Hours::status();
		if ( '' === $status['text'] ) {
			return '';
		}
		return '<p class="scout-hours-status ' . ( $status['open'] ? 'is-open' : 'is-closed' ) . '" data-scout-hours-status role="status">'
			. '<span class="scout-hours-dot" aria-hidden="true"></span>'
			. '<span class="scout-hours-status-text">' . esc_html( $status['text'] ) . '</span></p>';
	}

	private static function table_html(): string {
		$s     = Scout_Hours::settings();
		$today = strtolower( Scout_Hours::now()->format( 'l' ) );
		$rows  = '';
		foreach ( Scout_Hours::ordered_days() as $key ) {
			$day   = $s['days'][ $key ];
			$label = Scout_Hours::ranges_label( $day['open'] ? $day['ranges'] : array() );
			$rows .= '<tr data-day="' . esc_attr( $key ) . '"' . ( $key === $today ? ' class="is-today" aria-current="date"' : '' ) . '>'
				. '<th scope="row">' . esc_html( Scout_Hours::day_label( $key ) ) . '</th>'
				. '<td>' . esc_html( $label ) . '</td></tr>';
		}
		$out = '<table class="scout-hours-table"><caption class="scout-hours-caption">' . esc_html__( 'Opening hours', 'scout-hours' ) . '</caption><tbody>' . $rows . '</tbody></table>';

		$limit    = Scout_Hours::now()->modify( '+' . self::LIST_AHEAD . ' days' )->format( 'Y-m-d' );
		$upcoming = array_filter(
			Scout_Hours::upcoming_closures(),
			static function ( $c ) use ( $limit ) {
				return $c['start'] <= $limit;
			}
		);
		if ( $upcoming ) {
			$out .= '<p class="scout-hours-special-title">' . esc_html__( 'Holiday hours', 'scout-hours' ) . '</p><ul class="scout-hours-special">';
			foreach ( $upcoming as $c ) {
				$what = 'closed' === $c['type'] ? __( 'Closed', 'scout-hours' ) : Scout_Hours::ranges_label( $c['ranges'] );
				$name = '' !== $c['name'] ? ' (' . $c['name'] . ')' : '';
				$out .= '<li><span class="scout-hours-special-date">' . esc_html( Scout_Hours::closure_dates_label( $c ) . $name ) . '</span> '
					. '<span class="scout-hours-special-hours">' . esc_html( $what ) . '</span>'
					. ( '' !== $c['note'] ? '<span class="scout-hours-special-note">' . esc_html( $c['note'] ) . '</span>' : '' )
					. '</li>';
			}
			$out .= '</ul>';
		}
		return $out;
	}

	/* ---- Notice bar ---------------------------------------------------- */

	/**
	 * Closures the bar may show, with the words for "on the day" and "coming
	 * up", and the moments each applies. The script picks the right one.
	 */
	private static function bar_candidates(): array {
		$s = Scout_Hours::settings();
		if ( 'off' === $s['bar'] || ! $s['confirmed'] ) {
			return array();
		}
		$tz    = wp_timezone();
		$limit = Scout_Hours::now()->modify( '+' . Scout_Hours::WINDOW . ' days' )->format( 'Y-m-d' );
		$out   = array();
		foreach ( Scout_Hours::upcoming_closures() as $c ) {
			if ( $c['start'] > $limit ) {
				break;
			}
			$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $c['start'], $tz );
			$end   = DateTimeImmutable::createFromFormat( '!Y-m-d', $c['end'], $tz )->modify( '+1 day' );
			$name  = '' !== $c['name'] ? $c['name'] : __( 'the holiday', 'scout-hours' );
			if ( 'closed' === $c['type'] ) {
				/* translators: %s: holiday name. */
				$today = sprintf( __( 'Closed today for %s.', 'scout-hours' ), $name );
				/* translators: 1: date or dates, 2: holiday name. */
				$ahead = sprintf( __( 'Closed %1$s for %2$s.', 'scout-hours' ), Scout_Hours::closure_dates_label( $c ), $name );
			} else {
				$hours = Scout_Hours::ranges_label( $c['ranges'] );
				/* translators: 1: hours, 2: holiday name. */
				$today = sprintf( __( 'Open %1$s today for %2$s.', 'scout-hours' ), $hours, $name );
				/* translators: 1: hours, 2: date or dates, 3: holiday name. */
				$ahead = sprintf( __( 'Open %1$s on %2$s for %3$s.', 'scout-hours' ), $hours, Scout_Hours::closure_dates_label( $c ), $name );
			}
			$out[] = array(
				'id'    => $c['id'],
				's'     => $start->getTimestamp(),
				'e'     => $end->getTimestamp(),
				'today' => $today,
				'ahead' => 'week' === $s['bar'] ? $ahead : '',
				'note'  => $c['note'],
			);
		}
		return $out;
	}

	/**
	 * What the bar says right now, or null when it stays hidden.
	 *
	 * @return array{id:string,text:string,note:string}|null
	 */
	private static function bar_message(): ?array {
		if ( is_admin() ) {
			return null;
		}
		$now = Scout_Hours::now()->getTimestamp();
		foreach ( self::bar_candidates() as $c ) {
			if ( $c['s'] <= $now && $now < $c['e'] ) {
				return array(
					'id'   => $c['id'],
					'text' => $c['today'],
					'note' => $c['note'],
				);
			}
		}
		foreach ( self::bar_candidates() as $c ) {
			if ( '' !== $c['ahead'] && $c['s'] > $now && $c['s'] - $now <= 7 * DAY_IN_SECONDS ) {
				return array(
					'id'   => $c['id'],
					'text' => $c['ahead'],
					'note' => $c['note'],
				);
			}
		}
		return null;
	}

	public static function bar_top(): void {
		self::bar( false );
	}

	/** Themes without wp_body_open still get the bar, pinned to the bottom. */
	public static function bar_fallback(): void {
		self::bar( true );
	}

	private static function bar( bool $pinned ): void {
		if ( self::$bar_printed ) {
			return;
		}
		$msg = self::bar_message();
		if ( null === $msg ) {
			return;
		}
		self::$bar_printed = true;
		?>
<div class="scout-hours-bar<?php echo $pinned ? ' is-pinned' : ''; ?>" id="scout-hours-bar" role="region" aria-label="<?php esc_attr_e( 'Holiday hours', 'scout-hours' ); ?>" data-id="<?php echo esc_attr( $msg['id'] ); ?>">
	<p><strong class="scout-hours-bar-text"><?php echo esc_html( $msg['text'] ); ?></strong><span class="scout-hours-bar-note"><?php echo '' !== $msg['note'] ? ' ' . esc_html( $msg['note'] ) : ''; ?></span></p>
	<button type="button" class="scout-hours-bar-close" aria-label="<?php esc_attr_e( 'Hide this notice', 'scout-hours' ); ?>"><span aria-hidden="true">&times;</span></button>
</div>
		<?php
	}
}
