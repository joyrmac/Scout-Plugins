<?php
/**
 * The Events hub: its own sidebar item with the Scout bolt, holding the
 * Overview (numbers, Needs you, Coming up), the Events, Sessions and Speakers
 * lists, the spreadsheet import, and settings. Anyone who can edit posts sees
 * all of it; only administrators change the settings.
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_Admin {

	const SLUG = 'scout-events';

	/** Unfinished events listed one by one under Needs you; the rest share one line. */
	const UNFINISHED_SHOWN = 3;

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_filter( 'parent_file', array( __CLASS__, 'highlight' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_scout_events_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_scout_events_import', array( __CLASS__, 'import' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_EVENTS_FILE ), array( __CLASS__, 'action_link' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'toolbar' ), 91 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'toolbar_style' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'toolbar_style' ) );
	}

	/**
	 * Events is a tool the owner uses every week, so it has its own place in
	 * the sidebar with the Scout bolt (Joy, 2026-10-22), instead of sitting
	 * inside the Build menu with the site's settings. Partner sites
	 * (SCOUT_HUB_BRAND false) get a plain calendar icon.
	 */
	public static function parent(): string {
		return self::SLUG;
	}

	/** The hub, or one of its views ('import', 'settings'). */
	public static function url( array $args = array() ): string {
		$view = $args['view'] ?? '';
		unset( $args['view'] );
		$base = admin_url( 'admin.php?page=' . self::SLUG . ( in_array( $view, array( 'import', 'settings' ), true ) ? '-' . $view : '' ) );
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	/** The same bolt the Scout menu uses, so the two read as one family. */
	private static function icon(): string {
		if ( ! Scout_Admin_UI::branded() ) {
			return 'dashicons-calendar-alt';
		}
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#0E8FE6" d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- menu icon data URI.
	}

	/**
	 * Back to the screen a form came from. The admin menu is not built on
	 * admin-post.php, so parent() cannot be trusted there; the referer can.
	 */
	private static function back( string $msg, string $hash = '' ): void {
		$ref = wp_get_referer();
		$url = ( $ref && false !== strpos( $ref, 'page=' . self::SLUG ) ) ? remove_query_arg( 'scout-events', $ref ) : admin_url( 'admin.php?page=' . self::SLUG );
		$url = preg_replace( '/#.*$/', '', $url );
		wp_safe_redirect( add_query_arg( 'scout-events', $msg, $url ) . $hash );
		exit;
	}

	public static function menu(): void {
		add_menu_page( __( 'Scout Events', 'scout-events' ), __( 'Events', 'scout-events' ), 'edit_posts', self::SLUG, array( __CLASS__, 'render' ), self::icon(), '3.1' );
		add_submenu_page( self::SLUG, __( 'Scout Events', 'scout-events' ), __( 'Overview', 'scout-events' ), 'edit_posts', self::SLUG, array( __CLASS__, 'render' ) );
		global $submenu;
		$lists = array(
			Scout_Events::EVENT   => __( 'All events', 'scout-events' ),
			Scout_Events::SESSION => __( 'Sessions', 'scout-events' ),
			Scout_Events::SPEAKER => __( 'Speakers', 'scout-events' ),
		);
		foreach ( $lists as $type => $label ) {
			$list = 'edit.php?post_type=' . $type;
			remove_menu_page( $list );
			unset( $submenu[ $list ] ); // Its leftover sub-list would otherwise claim the screen.
			add_submenu_page( self::SLUG, $label, $label, 'edit_posts', $list );
		}
		add_submenu_page( self::SLUG, __( 'Bring in a spreadsheet', 'scout-events' ), __( 'Import', 'scout-events' ), 'edit_posts', self::SLUG . '-import', array( __CLASS__, 'render_import' ) );
		add_submenu_page( self::SLUG, __( 'Showing events and settings', 'scout-events' ), __( 'Settings', 'scout-events' ), 'edit_posts', self::SLUG . '-settings', array( __CLASS__, 'render_settings' ) );
		// Tickets, members and applications add their screens here, before Import.
		do_action( 'scout_events_menu', self::SLUG );
	}

	/** Keep the Events menu open while editing an event, session or speaker. */
	public static function highlight( $file ) {
		global $submenu_file;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && in_array( $screen->post_type, Scout_Events::post_types(), true ) && empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$submenu_file = 'edit.php?post_type=' . $screen->post_type; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return self::SLUG;
		}
		return $file;
	}

	/**
	 * Events in the toolbar, right after Scout, with the same bolt: the
	 * overview, what needs attention, and quick adds.
	 */
	public static function toolbar( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$branded = Scout_Admin_UI::branded();
		$count   = is_admin() || is_admin_bar_showing() ? count( self::needs_you() ) : 0;
		$title   = ( $branded ? '<span class="se-bar-bolt" aria-hidden="true"></span>' : '<span class="ab-icon dashicons dashicons-calendar-alt" aria-hidden="true"></span>' )
			. '<span class="se-bar-label">' . esc_html__( 'Events', 'scout-events' ) . '</span>'
			. ( $count ? ' <span class="se-bar-count" aria-label="' . esc_attr( sprintf( /* translators: %d: number of items. */ _n( '%d thing needs you', '%d things need you', $count, 'scout-events' ), $count ) ) . '">' . (int) $count . '</span>' : '' );
		$bar->add_node(
			array(
				'id'    => 'scout-events',
				'title' => $title,
				'href'  => self::url(),
			)
		);
		$items = array(
			'scout-events-overview' => array( __( 'Overview', 'scout-events' ), self::url() ),
			'scout-events-needs'    => array(
				$count
					/* translators: %d: number of items. */
					? sprintf( _n( 'Needs you (%d)', 'Needs you (%d)', $count, 'scout-events' ), $count )
					: __( 'Needs you', 'scout-events' ),
				self::url() . '#se-needs',
			),
			'scout-events-add'      => array( __( 'Add event', 'scout-events' ), admin_url( 'post-new.php?post_type=' . Scout_Events::EVENT ) ),
			'scout-events-session'  => array( __( 'Add session', 'scout-events' ), admin_url( 'post-new.php?post_type=' . Scout_Events::SESSION ) ),
			'scout-events-speaker'  => array( __( 'Add speaker', 'scout-events' ), admin_url( 'post-new.php?post_type=' . Scout_Events::SPEAKER ) ),
		);
		foreach ( $items as $id => $item ) {
			$bar->add_node(
				array(
					'parent' => 'scout-events',
					'id'     => $id,
					'title'  => esc_html( $item[0] ),
					'href'   => $item[1],
				)
			);
		}
	}

	/** The bolt and the count beside "Events" in the toolbar. */
	public static function toolbar_style(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		wp_add_inline_style(
			'admin-bar',
			'#wpadminbar #wp-admin-bar-scout-events>.ab-item{display:flex;align-items:center;gap:6px}'
			. '#wpadminbar .se-bar-bolt{display:inline-block;width:11px;height:14px;background:#0E8FE6;clip-path:polygon(58% 0,0 58%,42% 58%,30% 100%,100% 40%,54% 40%)}'
			. '#wpadminbar .se-bar-count{display:inline-block;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:#0A78C2;color:#fff;font-size:11px;line-height:18px;text-align:center}'
		);
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		Scout_Admin_UI::enqueue( SCOUT_EVENTS_URL . 'assets/scout-admin.css' );
		wp_enqueue_style( 'scout-events-admin', SCOUT_EVENTS_URL . 'assets/events-admin.css', array( 'scout-admin' ), SCOUT_EVENTS_VERSION );
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Events', 'scout-events' ) . '</a>' );
		return $links;
	}

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change event settings.', 'scout-events' ), 403 );
		}
		check_admin_referer( 'scout_events_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key is cleaned below.
		$in  = isset( $_POST['scout_events'] ) ? (array) wp_unslash( $_POST['scout_events'] ) : array();
		$old = Scout_Events::settings();
		$new = array(
			'event_slug'   => sanitize_title( (string) ( $in['event_slug'] ?? '' ) ),
			'session_slug' => sanitize_title( (string) ( $in['session_slug'] ?? '' ) ),
			'speaker_slug' => sanitize_title( (string) ( $in['speaker_slug'] ?? '' ) ),
			'currency'     => strtoupper( sanitize_text_field( (string) ( $in['currency'] ?? '' ) ) ),
			'schema'       => ! empty( $in['schema'] ) ? 1 : 0,
		);
		foreach ( array( 'event_slug', 'session_slug', 'speaker_slug' ) as $key ) {
			if ( '' === $new[ $key ] ) {
				$new[ $key ] = $old[ $key ];
			}
		}
		// Three types cannot share one address.
		$slugs = array( $new['event_slug'], $new['session_slug'], $new['speaker_slug'] );
		if ( count( array_unique( $slugs ) ) < 3 ) {
			self::back( 'sameslug' );
		}
		if ( ! preg_match( '/^[A-Z]{3}$/', $new['currency'] ) ) {
			$new['currency'] = $old['currency'];
		}
		update_option( Scout_Events::OPTION, $new );
		self::back( 'saved' );
	}

	/** Run an import (or its preview) and come back to the screen with the report. */
	public static function import(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to import events.', 'scout-events' ), 403 );
		}
		check_admin_referer( 'scout_events_import' );
		$paths = array();
		foreach ( array( 'events', 'speakers', 'sessions' ) as $kind ) {
			$file = $_FILES[ 'se_' . $kind ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only the temp path is used, after is_uploaded_file().
			if ( is_array( $file ) && UPLOAD_ERR_OK === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) && is_uploaded_file( (string) $file['tmp_name'] ) ) {
				$paths[ $kind ] = (string) $file['tmp_name'];
			}
		}
		if ( ! $paths ) {
			self::back( 'nofile', '#se-import' );
		}
		$dry = empty( $_POST['se_apply'] );
		if ( ! $dry && ! current_user_can( 'upload_files' ) ) {
			$dry = true;
		}
		$report = ( new Scout_Events_Import( $dry ) )->run( $paths );
		set_transient( 'scout_events_import_' . get_current_user_id(), $report, HOUR_IN_SECONDS );
		self::back( $dry ? 'previewed' : 'imported', '#se-import' );
	}

	/** The last import report for this user, once. */
	private static function import_report(): void {
		$key    = 'scout_events_import_' . get_current_user_id();
		$report = get_transient( $key );
		if ( ! is_array( $report ) ) {
			return;
		}
		delete_transient( $key );
		$labels = array(
			'events'   => __( 'events', 'scout-events' ),
			'speakers' => __( 'speakers', 'scout-events' ),
			'sessions' => __( 'sessions', 'scout-events' ),
		);
		$lines  = array();
		foreach ( $labels as $kind => $label ) {
			$c = (int) $report['created'][ $kind ];
			$u = (int) $report['updated'][ $kind ];
			if ( $c || $u ) {
				$lines[] = $report['dry']
					/* translators: 1: count new, 2: count updated, 3: "events" etc. */
					? sprintf( __( '%1$d new and %2$d updated %3$s', 'scout-events' ), $c, $u, $label )
					/* translators: 1: count added, 2: count updated, 3: "events" etc. */
					: sprintf( __( '%1$d added and %2$d updated %3$s', 'scout-events' ), $c, $u, $label );
			}
		}
		echo '<div class="se-report ' . ( $report['dry'] ? 'is-preview' : 'is-done' ) . '">';
		echo '<p class="se-report-head">' . esc_html( $report['dry'] ? __( 'Preview. Nothing has changed yet.', 'scout-events' ) : __( 'Import finished.', 'scout-events' ) ) . '</p>';
		echo '<p>' . esc_html( $lines ? implode( ', ', $lines ) . '.' : __( 'No rows to bring in.', 'scout-events' ) ) . '</p>';
		if ( $report['notes'] ) {
			echo '<details' . ( count( $report['notes'] ) < 8 ? ' open' : '' ) . '><summary>';
			/* translators: %d: number of notes. */
			echo esc_html( sprintf( _n( '%d note to check', '%d notes to check', count( $report['notes'] ), 'scout-events' ), count( $report['notes'] ) ) );
			echo '</summary><ul>';
			foreach ( $report['notes'] as $n ) {
				echo '<li>' . esc_html( $n ) . '</li>';
			}
			echo '</ul></details>';
		}
		if ( $report['dry'] ) {
			echo '<p>' . esc_html__( 'If that looks right, choose the same files again, tick "Bring them in", and import.', 'scout-events' ) . '</p>';
		}
		echo '</div>';
	}

	private static function message(): void {
		$msg   = isset( $_GET['scout-events'] ) ? sanitize_key( wp_unslash( $_GET['scout-events'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$texts = array(
			'saved'    => array( 'success', __( 'Settings saved. Page addresses update on the next visit.', 'scout-events' ) ),
			'sameslug' => array( 'error', __( 'Events, sessions and speakers each need their own address word. Nothing was saved.', 'scout-events' ) ),
			'nofile'   => array( 'error', __( 'Choose at least one spreadsheet file to import.', 'scout-events' ) ),
		);
		if ( isset( $texts[ $msg ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $texts[ $msg ][0] ), esc_html( $texts[ $msg ][1] ) );
		}
	}

	/**
	 * Everything waiting on a person, most useful first. Other Scout
	 * features add theirs with the scout_events_needs_you filter.
	 *
	 * @return array<int,array{text:string,url:string,action:string}>
	 */
	public static function needs_you(): array {
		$items  = array();
		$drafts = get_posts(
			array(
				'post_type'      => Scout_Events::SESSION,
				'post_status'    => array( 'draft', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		if ( $drafts ) {
			$items[] = array(
				/* translators: %d: number of draft sessions. */
				'text'   => sprintf( _n( '%d session is a draft. Check its day and time, then publish it.', '%d sessions are drafts. Check their day and time, then publish them.', count( $drafts ), 'scout-events' ), count( $drafts ) ),
				'url'    => admin_url( 'edit.php?post_type=' . Scout_Events::SESSION . '&post_status=draft' ),
				'action' => __( 'Check sessions', 'scout-events' ),
			);
		}

		$upcoming = Scout_Events::event_ids( 'upcoming' );
		$event_drafts = get_posts(
			array(
				'post_type'      => Scout_Events::EVENT,
				'post_status'    => array( 'draft', 'pending' ),
				'posts_per_page' => 50,
				'fields'         => 'ids',
			)
		);
		// The soonest few unfinished events get a line each; the rest are counted
		// in one line, so a year-long calendar does not bury everything else.
		$unfinished = 0;
		foreach ( array_merge( $upcoming, array_map( 'intval', $event_drafts ) ) as $id ) {
			$e    = Scout_Events::event( $id );
			$todo = array();
			foreach ( Scout_Events::checks( $id ) as $c ) {
				if ( ! $c[1] ) {
					$todo[] = lcfirst( $c[0] );
				}
			}
			if ( $todo && ++$unfinished > self::UNFINISHED_SHOWN ) {
				$todo = array();
			}
			if ( $todo ) {
				$items[] = array(
					/* translators: 1: event name, 2: list of missing items. */
					'text'   => sprintf( __( '%1$s still needs %2$s.', 'scout-events' ), get_the_title( $id ), implode( ', ', $todo ) ),
					'url'    => (string) get_edit_post_link( $id, 'raw' ),
					'action' => __( 'Finish event', 'scout-events' ),
				);
			}
			if ( 'onsale' === $e['availability'] && '' !== $e['sales_end'] && $e['sales_end'] < Scout_Events::now()->format( 'Y-m-d' ) ) {
				$items[] = array(
					/* translators: %s: event name. */
					'text'   => sprintf( __( 'Ticket sales for %s were set to close, but it still says On sale.', 'scout-events' ), get_the_title( $id ) ),
					'url'    => (string) get_edit_post_link( $id, 'raw' ),
					'action' => __( 'Update tickets', 'scout-events' ),
				);
			}
			$untimed = 0;
			foreach ( Scout_Events::session_ids( $id ) as $sid ) {
				$sd = Scout_Events::session( $sid );
				if ( '' === $sd['date'] || '' === $sd['start'] ) {
					++$untimed;
				}
			}
			if ( $untimed ) {
				$items[] = array(
					/* translators: 1: number of sessions, 2: event name. */
					'text'   => sprintf( _n( '%1$d session in %2$s has no day or time yet.', '%1$d sessions in %2$s have no day or time yet.', $untimed, 'scout-events' ), $untimed, get_the_title( $id ) ),
					'url'    => admin_url( 'edit.php?post_type=' . Scout_Events::SESSION . '&se_event=' . $id ),
					'action' => __( 'Set times', 'scout-events' ),
				);
			}
		}
		if ( $unfinished > self::UNFINISHED_SHOWN ) {
			$rest    = $unfinished - self::UNFINISHED_SHOWN;
			$items[] = array(
				/* translators: %d: number of events. */
				'text'   => sprintf( _n( '%d more event still needs details. Coming up, below, shows what each one is missing.', '%d more events still need details. Coming up, below, shows what each one is missing.', $rest, 'scout-events' ), $rest ),
				'url'    => '#se-coming-up',
				'action' => __( 'See them', 'scout-events' ),
			);
		}

		$no_photo = array_filter(
			Scout_Events::speaker_ids(),
			static fn( $pid ) => ! has_post_thumbnail( $pid )
		);
		if ( $no_photo ) {
			$names   = array_map( 'get_the_title', array_slice( array_values( $no_photo ), 0, 3 ) );
			$more    = count( $no_photo ) > 3 ? ' ' . sprintf( /* translators: %d: how many more. */ __( 'and %d more', 'scout-events' ), count( $no_photo ) - 3 ) : '';
			$items[] = array(
				/* translators: 1: names, 2: "and 3 more" or nothing. */
				'text'   => sprintf( __( 'No photo yet: %1$s%2$s.', 'scout-events' ), implode( ', ', $names ), $more ),
				'url'    => admin_url( 'edit.php?post_type=' . Scout_Events::SPEAKER ),
				'action' => __( 'Add photos', 'scout-events' ),
			);
		}
		return (array) apply_filters( 'scout_events_needs_you', $items );
	}

	public static function render_import(): void {
		self::render( 'import' );
	}

	public static function render_settings(): void {
		self::render( 'settings' );
	}

	public static function render( string $view = 'overview' ): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$view = in_array( $view, array( 'overview', 'import', 'settings' ), true ) ? $view : 'overview';

		Scout_Admin_UI::open(
			__( 'Your events', 'scout-events' ),
			__( 'Everything about your events in one place: what is coming up, what needs you, and every event, session and speaker. Change something here and the site follows.', 'scout-events' ),
			__( 'Events', 'scout-events' )
		);
		self::message();
		echo '<div class="se-admin">';
		if ( 'import' === $view ) {
			self::view_import();
		} elseif ( 'settings' === $view ) {
			self::view_settings();
		} else {
			self::view_overview();
		}
		echo '</div>';
		Scout_Admin_UI::close();
	}

	private static function view_overview(): void {
		$upcoming = Scout_Events::event_ids( 'upcoming' );
		$drafts   = get_posts(
			array(
				'post_type'      => Scout_Events::EVENT,
				'post_status'    => array( 'draft', 'pending', 'future' ),
				'posts_per_page' => 20,
				'fields'         => 'ids',
			)
		);
		$sessions = 0;
		foreach ( $upcoming as $id ) {
			$sessions += count( Scout_Events::session_ids( $id ) );
		}
		$needs = self::needs_you();
		$next  = $upcoming ? Scout_Events::event( $upcoming[0] ) : null;
		?>
		<section class="sh-box">
			<div class="sh-stats">
				<div class="sh-stat">
					<p class="sh-num"><?php echo (int) count( $upcoming ); ?></p>
					<p class="sh-note"><?php esc_html_e( 'upcoming events', 'scout-events' ); ?></p>
				</div>
				<div class="sh-stat">
					<p class="sh-num"><?php echo (int) $sessions; ?></p>
					<p class="sh-note"><?php esc_html_e( 'sessions in them', 'scout-events' ); ?></p>
				</div>
				<div class="sh-stat">
					<p class="sh-num"><?php echo (int) count( Scout_Events::speaker_ids() ); ?></p>
					<p class="sh-note"><?php esc_html_e( 'speakers', 'scout-events' ); ?></p>
				</div>
				<div class="sh-stat">
					<p class="sh-num"><?php echo (int) count( $needs ); ?></p>
					<p class="sh-note"><a href="#se-needs"><?php esc_html_e( 'things need you', 'scout-events' ); ?> &rsaquo;</a></p>
				</div>
				<?php if ( $next ) : ?>
					<div class="sh-stat">
						<p class="sh-num sh-num-text"><?php echo esc_html( Scout_Events::dates_label( $next ) ); ?></p>
						<p class="sh-note"><?php echo esc_html( get_the_title( $upcoming[0] ) ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</section>

		<section class="sh-box" id="se-needs">
			<h2 class="sh-title"><?php esc_html_e( 'Needs you', 'scout-events' ); ?></h2>
			<?php if ( $needs ) : ?>
				<ul class="se-needs">
					<?php foreach ( $needs as $n ) : ?>
						<li><span><?php echo esc_html( $n['text'] ); ?></span><a class="button" href="<?php echo esc_url( $n['url'] ); ?>"><?php echo esc_html( $n['action'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="se-allclear"><?php esc_html_e( 'Nothing is waiting on you. Every upcoming event is ready to share.', 'scout-events' ); ?></p>
			<?php endif; ?>
		</section>

		<section class="sh-box" id="se-coming-up">
			<div class="se-box-head">
				<h2 class="sh-title"><?php esc_html_e( 'Coming up', 'scout-events' ); ?></h2>
				<span class="se-actions">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Scout_Events::EVENT ) ); ?>"><?php esc_html_e( 'Add event', 'scout-events' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Scout_Events::SESSION ) ); ?>"><?php esc_html_e( 'Add session', 'scout-events' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Scout_Events::SPEAKER ) ); ?>"><?php esc_html_e( 'Add speaker', 'scout-events' ); ?></a>
				</span>
			</div>
			<?php self::table( array_merge( $upcoming, array_map( 'intval', $drafts ) ) ); ?>
		</section>
		<?php
		do_action( 'scout_events_hub_overview' );
	}

	private static function view_import(): void {
		?>
		<section class="sh-box" id="se-import">
			<h2 class="sh-title"><?php esc_html_e( 'Bring in a spreadsheet', 'scout-events' ); ?></h2>
			<p><?php esc_html_e( 'Add many events, speakers and sessions at once from CSV files saved from a spreadsheet. Each run is a preview first, so nothing changes until you say so. Running the same files again updates what is there instead of adding copies.', 'scout-events' ); ?></p>
			<?php self::import_report(); ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="se-import">
				<input type="hidden" name="action" value="scout_events_import">
				<?php wp_nonce_field( 'scout_events_import' ); ?>
				<p class="se-field"><label for="se-f-events"><?php esc_html_e( 'Events file (optional)', 'scout-events' ); ?></label><input type="file" id="se-f-events" name="se_events" accept=".csv,text/csv"></p>
				<p class="se-field"><label for="se-f-speakers"><?php esc_html_e( 'Speakers file (optional)', 'scout-events' ); ?></label><input type="file" id="se-f-speakers" name="se_speakers" accept=".csv,text/csv"></p>
				<p class="se-field"><label for="se-f-sessions"><?php esc_html_e( 'Sessions file (optional)', 'scout-events' ); ?></label><input type="file" id="se-f-sessions" name="se_sessions" accept=".csv,text/csv"></p>
				<p class="se-field"><label for="se-apply"><input type="checkbox" id="se-apply" name="se_apply" value="1"> <?php esc_html_e( 'Bring them in (leave unticked to preview)', 'scout-events' ); ?></label></p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'scout-events' ); ?></button></p>
			</form>
			<p class="sh-note"><?php esc_html_e( 'Photos: put a file name already in the Media Library, or a web address, in the photo column. Columns are listed in the plugin README.', 'scout-events' ); ?></p>
		</section>
		<?php
	}

	private static function view_settings(): void {
		$s = Scout_Events::settings();
		?>
		<div class="se-admin-cols">
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Showing events on the site', 'scout-events' ); ?></h2>
				<p><?php esc_html_e( 'Each event already has its own page with the details, schedule and speakers. Paste these on any page to list them there.', 'scout-events' ); ?></p>
				<table class="sh-table">
					<tr><td>[scout_events]</td><td><?php esc_html_e( 'Upcoming events, soonest first.', 'scout-events' ); ?></td></tr>
					<tr><td>[scout_events show="past" limit="6"]</td><td><?php esc_html_e( 'The six most recent past events.', 'scout-events' ); ?></td></tr>
					<tr><td>[scout_event id="12"]</td><td><?php esc_html_e( 'One event’s details and ticket button. Without an id, the next event.', 'scout-events' ); ?></td></tr>
					<tr><td>[scout_schedule event="12"]</td><td><?php esc_html_e( 'The schedule, day by day. Add layout="grid" for rooms side by side.', 'scout-events' ); ?></td></tr>
					<tr><td>[scout_speakers event="12"]</td><td><?php esc_html_e( 'Speaker cards for one event. Without event, every speaker.', 'scout-events' ); ?></td></tr>
				</table>
				<p class="sh-note"><?php esc_html_e( 'The event id is the number in the address bar when you edit the event (post=12).', 'scout-events' ); ?></p>
			</section>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<section class="sh-box">
					<h2 class="sh-title"><?php esc_html_e( 'Settings', 'scout-events' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="se-settings">
						<input type="hidden" name="action" value="scout_events_settings">
						<?php wp_nonce_field( 'scout_events_settings' ); ?>
						<p class="se-field">
							<label for="se-set-event"><?php esc_html_e( 'Event page address', 'scout-events' ); ?></label>
							<span class="se-slug"><?php echo esc_html( home_url( '/' ) ); ?><input type="text" id="se-set-event" name="scout_events[event_slug]" value="<?php echo esc_attr( $s['event_slug'] ); ?>">/name</span>
						</p>
						<p class="se-field">
							<label for="se-set-session"><?php esc_html_e( 'Session page address', 'scout-events' ); ?></label>
							<span class="se-slug"><?php echo esc_html( home_url( '/' ) ); ?><input type="text" id="se-set-session" name="scout_events[session_slug]" value="<?php echo esc_attr( $s['session_slug'] ); ?>">/name</span>
						</p>
						<p class="se-field">
							<label for="se-set-speaker"><?php esc_html_e( 'Speaker page address', 'scout-events' ); ?></label>
							<span class="se-slug"><?php echo esc_html( home_url( '/' ) ); ?><input type="text" id="se-set-speaker" name="scout_events[speaker_slug]" value="<?php echo esc_attr( $s['speaker_slug'] ); ?>">/name</span>
						</p>
						<p class="se-field">
							<label for="se-set-currency"><?php esc_html_e( 'Currency code', 'scout-events' ); ?></label>
							<input type="text" id="se-set-currency" name="scout_events[currency]" value="<?php echo esc_attr( $s['currency'] ); ?>" maxlength="3" class="se-short-input">
						</p>
						<p class="se-field">
							<label for="se-set-schema"><input type="checkbox" id="se-set-schema" name="scout_events[schema]" value="1" <?php checked( $s['schema'] ); ?>> <?php esc_html_e( 'Add event schema for Google', 'scout-events' ); ?></label>
							<span class="sh-note"><?php esc_html_e( 'Turn off only if the theme already prints its own event schema.', 'scout-events' ); ?></span>
						</p>
						<p><button type="submit" class="button"><?php esc_html_e( 'Save settings', 'scout-events' ); ?></button></p>
					</form>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}

	/** The upcoming and draft events with what each still needs. */
	private static function table( array $ids ): void {
		if ( ! $ids ) {
			echo '<p class="se-empty-admin">' . esc_html__( 'No upcoming events yet. Add the first one and it gets a page of its own.', 'scout-events' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped se-overview">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Event', 'scout-events' ); ?></th>
					<th scope="col"><?php esc_html_e( 'When', 'scout-events' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tickets', 'scout-events' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Still needed', 'scout-events' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $ids as $id ) :
					$e      = Scout_Events::event( $id );
					$todo   = array();
					foreach ( Scout_Events::checks( $id ) as $c ) {
						if ( ! $c[1] ) {
							$todo[] = $c[0];
						}
					}
					$status = get_post_status( $id );
					?>
					<tr>
						<td>
							<a class="se-row-title" href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a>
							<?php if ( 'publish' !== $status ) : ?>
								<span class="se-chip"><?php echo esc_html( 'future' === $status ? __( 'Scheduled', 'scout-events' ) : __( 'Draft', 'scout-events' ) ); ?></span>
							<?php endif; ?>
							<?php if ( 'scheduled' !== $e['status'] ) : ?>
								<span class="se-chip is-warn"><?php echo esc_html( Scout_Events::statuses()[ $e['status'] ] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( Scout_Events::dates_label( $e ) ); ?></td>
						<td>
							<?php echo esc_html( Scout_Events::availabilities()[ $e['availability'] ] ); ?>
							<?php $price = Scout_Events::price_label( $e ); ?>
							<?php if ( '' !== $price ) : ?>
								<br><span class="sh-note"><?php echo esc_html( $price ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $todo ) : ?>
								<?php echo esc_html( implode( ', ', $todo ) ); ?>
							<?php else : ?>
								<span class="se-chip is-done"><?php esc_html_e( 'Ready', 'scout-events' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
