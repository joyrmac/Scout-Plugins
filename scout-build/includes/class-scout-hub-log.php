<?php
/**
 * The Site log: one line on this site for every change made to it.
 *
 * The plugin that does the work writes the line in the same request, after
 * the work succeeds: a Scout plugin or the theme updating, a feature turned
 * on, Site Sync putting new copy in, a change request opened or moved, the
 * hidden-from-Google alert. People add plain-words notes for anything done by
 * hand. Each line says whether the client sees it, and carries the client
 * wording and the number behind any result, so the client's "Your site"
 * screen and the monthly message read one record. Nothing is typed twice.
 *
 * A done line never changes. Only the client wording, the measure and the
 * show-the-client flag can be edited afterwards; corrections are a new line.
 *
 * Spec: docs/site-log-spec.md in the Scout-Plugins repo.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Log {

	const TYPE   = 'scout_hub_log';
	const ACTION = 'scout_hub_log';

	const META_CLIENT  = '_scout_log_client_text';
	const META_SHOW    = '_scout_log_show';
	const META_KIND    = '_scout_log_kind';
	const META_TOPIC   = '_scout_log_topic';
	const META_STATUS  = '_scout_log_status';
	const META_VERSION = '_scout_log_version';
	const META_MEASURE = '_scout_log_measure';
	const META_SOURCE  = '_scout_log_source';
	const META_REF     = '_scout_log_ref';
	const META_IMPORT  = '_scout_log_import';

	const IMPORTED = 'scout_hub_log_imported'; // Set once a history import has run.

	/** Where the Scout plugins update from; plugin updates from here are logged. */
	const REPOS = array( 'scout-media-raleigh/scout-plugins', 'joyrmac/scout-plugins' );

	/** What wrote the line. */
	const KINDS = array(
		'theme'    => 'Theme',
		'plugin'   => 'Plugin',
		'feature'  => 'Feature',
		'content'  => 'Content',
		'setup'    => 'Setup',
		'request'  => 'Request',
		'approval' => 'Approval',
		'alert'    => 'Alert',
		'note'     => 'Note',
	);

	/** What the line is about. */
	const TOPICS = array(
		'speed'   => 'Speed',
		'brand'   => 'Brand',
		'content' => 'Content',
		'email'   => 'Email',
		'setup'   => 'Setup',
		'plugin'  => 'Plugin release',
		'launch'  => 'Launch',
		'seo'     => 'Search',
		'forms'   => 'Forms',
		'other'   => 'Other',
	);

	const STATUSES = array(
		'done'    => 'Done',
		'planned' => 'Planned',
		'waiting' => 'Waiting on you',
	);

	/** Whether the client sees a line by default, by kind. */
	const SHOW_DEFAULT = array(
		'theme'    => true,
		'plugin'   => true,
		'feature'  => true,
		'content'  => true,
		'setup'    => false,
		'request'  => true,
		'approval' => true,
		'alert'    => false,
		'note'     => true,
	);

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );

		// The plugins and the theme log their own updates.
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrade' ), 10, 2 );
		add_action( 'scout_hub_feature_done', array( __CLASS__, 'on_feature' ), 10, 3 );
		add_action( 'scout_sync_applied', array( __CLASS__, 'on_sync' ), 10, 2 );
		add_action( 'scout_hub_setup_done', array( __CLASS__, 'on_setup' ) );
		add_action( 'scout_hub_request_opened', array( __CLASS__, 'on_request' ), 10, 3 );
		add_action( 'scout_hub_request_status', array( __CLASS__, 'on_request_status' ), 10, 2 );
		add_action( 'scout_hub_watch_alert', array( __CLASS__, 'on_alert' ) );
		add_action( 'scout_hub_watch_cleared', array( __CLASS__, 'on_cleared' ) );

		// Approvals wait on the client, so they count in the Inbox; and the client's
		// "Your site" files under Overview, since Build is hidden from them.
		add_filter( 'scout_hub_inbox', array( __CLASS__, 'inbox_row' ) );
		add_filter( 'scout_admin_nav_group', array( __CLASS__, 'nav_group' ), 10, 2 );
	}

	/** @param array<int,array<string,mixed>> $rows */
	public static function inbox_row( $rows ): array {
		$rows   = (array) $rows;
		$n      = self::query( array( 'client' => true, 'status' => 'waiting', 'per_page' => 1 ) )['total'];
		$rows[] = array(
			'label' => __( 'Waiting on your OK', 'scout-build' ),
			'count' => (int) $n,
			'url'   => Scout_Hub_Admin::url( 'log' ),
			'note'  => __( 'Things Scout has ready that need your approval before they go live.', 'scout-build' ),
		);
		return $rows;
	}

	/** The Site history screen sits under Home for everyone who is not an operator. */
	public static function nav_group( $group, $slug ) {
		if ( false !== strpos( (string) $slug, 'tab=log' ) && ! Scout_Hub_Access::is_operator() ) {
			return 'overview';
		}
		return $group;
	}

	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'       => array( 'name' => __( 'Site history', 'scout-build' ) ),
				'public'       => false,
				'show_ui'      => false,
				'rewrite'      => false,
				'supports'     => array( 'title', 'editor', 'author' ),
				'capabilities' => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap' => true,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Writing                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Clean one entry: known kind, topic and status, trimmed text, a date.
	 * Pure, so the tests can run it without WordPress.
	 *
	 * @param array<string, mixed> $in title, detail, client_text, show_client, kind, topic,
	 *                                 status, version, measure, source, ref, date, import_id.
	 * @return array<string, mixed>|null Null when there is no title.
	 */
	public static function normalize( array $in ): ?array {
		$text = static function ( $v, int $max ): string {
			$v = trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v ) );
			return mb_substr( $v, 0, $max );
		};
		$title = $text( $in['title'] ?? '', 200 );
		if ( '' === $title ) {
			return null;
		}
		$kind   = strtolower( trim( (string) ( $in['kind'] ?? 'note' ) ) );
		$kind   = isset( self::KINDS[ $kind ] ) ? $kind : 'note';
		$topic  = strtolower( trim( (string) ( $in['topic'] ?? 'other' ) ) );
		$topic  = isset( self::TOPICS[ $topic ] ) ? $topic : 'other';
		$status = strtolower( trim( (string) ( $in['status'] ?? 'done' ) ) );
		$status = isset( self::STATUSES[ $status ] ) ? $status : 'done';
		$show   = array_key_exists( 'show_client', $in ) ? self::truthy( $in['show_client'] ) : self::SHOW_DEFAULT[ $kind ];

		$date = trim( (string) ( $in['date'] ?? '' ) );
		if ( '' !== $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $date ) ) {
			$date = '';
		}
		if ( '' !== $date && 10 === strlen( $date ) ) {
			$date .= ' 12:00:00';
		}

		return array(
			'title'       => $title,
			'detail'      => $text( $in['detail'] ?? '', 5000 ),
			'client_text' => $text( $in['client_text'] ?? '', 400 ),
			'show_client' => $show,
			'kind'        => $kind,
			'topic'       => $topic,
			'status'      => $status,
			'version'     => $text( $in['version'] ?? '', 60 ),
			'measure'     => $text( $in['measure'] ?? '', 200 ),
			'source'      => $text( $in['source'] ?? '', 60 ),
			'ref'         => $text( $in['ref'] ?? '', 300 ),
			'date'        => str_replace( 'T', ' ', $date ),
			'import_id'   => $text( $in['import_id'] ?? '', 80 ),
		);
	}

	private static function truthy( $v ): bool {
		if ( is_bool( $v ) ) {
			return $v;
		}
		return in_array( strtolower( trim( (string) $v ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Add one line. Returns the post ID, or 0 when the entry had no title or
	 * could not be saved. A line with an import ID already in the log is not
	 * added twice.
	 *
	 * @param array<string, mixed> $entry See normalize().
	 */
	public static function add( array $entry ): int {
		$e = self::normalize( $entry );
		if ( ! $e ) {
			return 0;
		}
		if ( '' !== $e['import_id'] && self::find_import( $e['import_id'] ) ) {
			return 0;
		}
		if ( '' === $e['source'] ) {
			$user        = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
			$e['source'] = ( $user && $user->exists() ) ? (string) $user->display_name : 'Scout Build';
		}
		$post = array(
			'post_type'    => self::TYPE,
			'post_status'  => 'private',
			'post_title'   => $e['title'],
			'post_content' => $e['detail'],
			'post_author'  => get_current_user_id(),
		);
		if ( '' !== $e['date'] ) {
			$post['post_date']     = $e['date'];
			$post['post_date_gmt'] = get_gmt_from_date( $e['date'] );
		}
		$id = wp_insert_post( $post, true );
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		update_post_meta( $id, self::META_CLIENT, $e['client_text'] );
		update_post_meta( $id, self::META_SHOW, $e['show_client'] ? '1' : '0' );
		update_post_meta( $id, self::META_KIND, $e['kind'] );
		update_post_meta( $id, self::META_TOPIC, $e['topic'] );
		update_post_meta( $id, self::META_STATUS, $e['status'] );
		update_post_meta( $id, self::META_VERSION, $e['version'] );
		update_post_meta( $id, self::META_MEASURE, $e['measure'] );
		update_post_meta( $id, self::META_SOURCE, $e['source'] );
		update_post_meta( $id, self::META_REF, $e['ref'] );
		if ( '' !== $e['import_id'] ) {
			update_post_meta( $id, self::META_IMPORT, $e['import_id'] );
		}
		return (int) $id;
	}

	private static function find_import( string $import_id ): int {
		$found = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_IMPORT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $import_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Change the only three fields a saved line may change: the client
	 * wording, the measure, and whether the client sees it. Planned and
	 * waiting lines may also move to done.
	 */
	public static function edit( int $id, array $fields ): bool {
		if ( self::TYPE !== get_post_type( $id ) ) {
			return false;
		}
		$e = self::normalize( array( 'title' => 'x' ) + $fields );
		if ( array_key_exists( 'client_text', $fields ) ) {
			update_post_meta( $id, self::META_CLIENT, $e['client_text'] );
		}
		if ( array_key_exists( 'measure', $fields ) ) {
			update_post_meta( $id, self::META_MEASURE, $e['measure'] );
		}
		if ( array_key_exists( 'show_client', $fields ) ) {
			update_post_meta( $id, self::META_SHOW, $e['show_client'] ? '1' : '0' );
		}
		if ( array_key_exists( 'status', $fields ) && 'done' !== get_post_meta( $id, self::META_STATUS, true ) ) {
			update_post_meta( $id, self::META_STATUS, $e['status'] );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* The automatic writers                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * A Scout plugin or the theme finished updating.
	 *
	 * @param WP_Upgrader|mixed    $upgrader   Unused.
	 * @param array<string, mixed> $hook_extra type, action, plugins or themes.
	 */
	public static function on_upgrade( $upgrader, $hook_extra ): void {
		$hook_extra = (array) $hook_extra;
		if ( ( $hook_extra['action'] ?? '' ) !== 'update' ) {
			return;
		}
		if ( 'plugin' === ( $hook_extra['type'] ?? '' ) ) {
			$files = (array) ( $hook_extra['plugins'] ?? ( isset( $hook_extra['plugin'] ) ? array( $hook_extra['plugin'] ) : array() ) );
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			wp_cache_delete( 'plugins', 'plugins' );
			$all = get_plugins();
			foreach ( $files as $file ) {
				$file = (string) $file;
				if ( ! isset( $all[ $file ] ) || ! self::is_scout_plugin( $all[ $file ] ) ) {
					continue;
				}
				$version = (string) $all[ $file ]['Version'];
				$name    = (string) $all[ $file ]['Name'];
				$what    = self::changelog_line( WP_PLUGIN_DIR . '/' . dirname( $file ) . '/CHANGELOG.md', $version );
				self::add(
					array(
						'title'   => $name . ' ' . $version . ( '' !== $what ? ': ' . $what : '' ),
						'kind'    => 'plugin',
						'topic'   => 'plugin',
						'version' => $name . ' ' . $version,
						'source'  => 'Scout Build',
					)
				);
			}
		}
		if ( 'theme' === ( $hook_extra['type'] ?? '' ) ) {
			$sheets = (array) ( $hook_extra['themes'] ?? ( isset( $hook_extra['theme'] ) ? array( $hook_extra['theme'] ) : array() ) );
			wp_clean_themes_cache();
			foreach ( $sheets as $sheet ) {
				$theme = wp_get_theme( (string) $sheet );
				if ( ! $theme->exists() ) {
					continue;
				}
				$active = get_stylesheet() === $theme->get_stylesheet();
				if ( ! $active && ! Scout_Hub_Themes::source( $theme ) ) {
					continue; // A spare theme that is not ours.
				}
				$version = (string) $theme->get( 'Version' );
				$what    = self::changelog_line( $theme->get_stylesheet_directory() . '/CHANGELOG.md', $version );
				self::add(
					array(
						/* translators: %s: version */
						'title'   => sprintf( __( 'Theme %s', 'scout-build' ), $version ) . ( '' !== $what ? ': ' . $what : '' ),
						'kind'    => 'theme',
						'topic'   => 'other',
						'version' => $version,
						'source'  => 'Scout Build',
					)
				);
			}
		}
	}

	/** @param array<string, string> $data One row of get_plugins(). */
	public static function is_scout_plugin( array $data ): bool {
		$uri = strtolower( (string) ( $data['UpdateURI'] ?? '' ) );
		foreach ( self::REPOS as $repo ) {
			if ( false !== strpos( $uri, $repo ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The first line of a version's entry in a CHANGELOG.md, as plain text.
	 * Scout changelogs put the headline in bold at the start of the first
	 * bullet ("- **Theme updates keep downloading…** A theme whose…"); that
	 * bold part is the line. Without bold, the whole first bullet, trimmed to
	 * a sentence. '' when the file or the version is not there.
	 */
	public static function changelog_line( string $path, string $version ): string {
		if ( '' === $version || ! is_readable( $path ) ) {
			return '';
		}
		$text = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file inside the plugin or theme.
		if ( ! preg_match( '/^##\s+v?' . preg_quote( $version, '/' ) . '\b[^\n]*\n(.*?)(?=^##\s|\z)/ms', $text, $m ) ) {
			return '';
		}
		foreach ( preg_split( '/\r?\n/', $m[1] ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$line = preg_replace( '/^[-*]\s+/', '', $line );
			if ( preg_match( '/^\*\*(.+?)\*\*/', $line, $b ) ) {
				$line = $b[1];
			} elseif ( preg_match( '/^(.+?[.!?])(\s|$)/u', $line, $s ) ) {
				$line = $s[1];
			}
			$line = trim( preg_replace( '/[*`_]/', '', (string) $line ) );
			$line = rtrim( $line, '.:' );
			return mb_substr( $line, 0, 160 );
		}
		return '';
	}

	/** A feature switched on or off, or sent back a version, on the Features screen. */
	public static function on_feature( string $slug, string $do, string $name ): void {
		$user = wp_get_current_user();
		$who  = $user && $user->exists() ? $user->display_name : 'Scout';
		$st   = Scout_Hub_Features::status( $slug );
		$v    = '' !== $st['version'] ? $name . ' ' . $st['version'] : '';
		$text = array(
			/* translators: 1: feature, 2: person */
			'on'   => __( '%1$s turned on by %2$s', 'scout-build' ),
			/* translators: 1: feature, 2: person */
			'off'  => __( '%1$s turned off by %2$s', 'scout-build' ),
			/* translators: 1: feature, 2: person */
			'back' => __( '%1$s sent back to the previous version by %2$s', 'scout-build' ),
		);
		if ( ! isset( $text[ $do ] ) ) {
			return;
		}
		self::add(
			array(
				'title'       => sprintf( $text[ $do ], $name, $who ),
				'kind'        => 'feature',
				'topic'       => 'plugin',
				'version'     => $v,
				'source'      => $who,
				'show_client' => 'on' === $do,
			)
		);
	}

	/**
	 * Scout Core's Site Sync put theme copy in.
	 *
	 * @param array<int, mixed> $rows The journal rows Site Sync applied.
	 * @param string            $who  'person' or 'auto'.
	 */
	public static function on_sync( $rows, $who = 'person' ): void {
		$n = is_array( $rows ) ? count( $rows ) : 0;
		if ( ! $n ) {
			return;
		}
		self::add(
			array(
				/* translators: %d: pages */
				'title'  => sprintf( _n( 'Page copy updated on %d page from the theme', 'Page copy updated on %d pages from the theme', $n, 'scout-build' ), $n ),
				'kind'   => 'content',
				'topic'  => 'content',
				'source' => 'Scout Core',
				'detail' => 'auto' === $who ? __( 'Applied automatically after a theme update.', 'scout-build' ) : '',
			)
		);
	}

	/** @param string[] $sections The scout-setup.json sections that filled something in. */
	public static function on_setup( $sections ): void {
		$sections = array_values( array_filter( array_map( 'strval', (array) $sections ) ) );
		if ( ! $sections ) {
			return;
		}
		self::add(
			array(
				/* translators: %s: sections */
				'title'  => sprintf( __( 'Business facts refreshed from the theme: %s', 'scout-build' ), implode( ', ', $sections ) ),
				'kind'   => 'setup',
				'topic'  => 'setup',
				'source' => 'Scout Build',
			)
		);
	}

	/** A change request came in. */
	public static function on_request( int $id, string $title, string $by ): void {
		self::add(
			array(
				/* translators: %s: request */
				'title'  => sprintf( __( 'You asked: %s', 'scout-build' ), $title ),
				'kind'   => 'request',
				'topic'  => 'other',
				'status' => 'planned',
				'source' => '' !== $by ? $by : 'Scout Build',
				'ref'    => 'request:' . $id,
			)
		);
	}

	/** A request moved to another status. */
	public static function on_request_status( int $id, string $status ): void {
		$labels = array(
			/* translators: %s: request */
			'progress' => __( 'In progress: %s', 'scout-build' ),
			/* translators: %s: request */
			'done'     => __( 'Done: %s', 'scout-build' ),
			/* translators: %s: request */
			'received' => __( 'Back to received: %s', 'scout-build' ),
		);
		if ( ! isset( $labels[ $status ] ) ) {
			return;
		}
		// The "You asked" line is the open item; close it when the request is done.
		foreach ( self::by_ref( 'request:' . $id ) as $line ) {
			if ( 'done' === $status ) {
				update_post_meta( $line, self::META_STATUS, 'done' );
			}
		}
		self::add(
			array(
				'title'  => sprintf( $labels[ $status ], get_the_title( $id ) ),
				'kind'   => 'request',
				'topic'  => 'other',
				'status' => 'done' === $status ? 'done' : 'planned',
				'source' => wp_get_current_user()->display_name ?: 'Scout',
				'ref'    => 'request:' . $id,
			)
		);
	}

	/** @return int[] */
	private static function by_ref( string $ref ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::TYPE,
					'post_status'    => 'private',
					'posts_per_page' => 20,
					'fields'         => 'ids',
					'meta_key'       => self::META_REF, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $ref, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	public static function on_alert(): void {
		self::add(
			array(
				'title'  => __( 'Site hidden from Google; Scout alerted', 'scout-build' ),
				'kind'   => 'alert',
				'topic'  => 'seo',
				'source' => 'Scout Build',
			)
		);
	}

	public static function on_cleared(): void {
		self::add(
			array(
				'title'  => __( 'Site visible to Google again', 'scout-build' ),
				'kind'   => 'alert',
				'topic'  => 'seo',
				'source' => 'Scout Build',
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Reading                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Lines, newest first.
	 *
	 * @param array<string, mixed> $args since (Y-m-d), month (Y-m), client (bool), kind,
	 *                                   topic, status, page, per_page, s (search).
	 * @return array{items: WP_Post[], total: int, pages: int}
	 */
	public static function query( array $args = array() ): array {
		$q = array(
			'post_type'      => self::TYPE,
			'post_status'    => 'private',
			'posts_per_page' => min( 200, max( 1, (int) ( $args['per_page'] ?? 50 ) ) ),
			'paged'          => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$meta = array();
		foreach ( array( 'kind' => self::META_KIND, 'topic' => self::META_TOPIC, 'status' => self::META_STATUS ) as $key => $meta_key ) {
			if ( ! empty( $args[ $key ] ) ) {
				$meta[] = array( 'key' => $meta_key, 'value' => (string) $args[ $key ] );
			}
		}
		if ( ! empty( $args['client'] ) ) {
			$meta[] = array( 'key' => self::META_SHOW, 'value' => '1' );
		}
		if ( $meta ) {
			$q['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		if ( ! empty( $args['month'] ) && preg_match( '/^(\d{4})-(\d{2})$/', (string) $args['month'], $m ) ) {
			$q['date_query'] = array( array( 'year' => (int) $m[1], 'month' => (int) $m[2] ) );
		} elseif ( ! empty( $args['since'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['since'] ) ) {
			$q['date_query'] = array( array( 'after' => (string) $args['since'], 'inclusive' => true ) );
		}
		if ( ! empty( $args['s'] ) ) {
			$q['s'] = (string) $args['s'];
		}
		$query = new WP_Query( $q );
		return array( 'items' => $query->posts, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages );
	}

	/**
	 * One line as an array. With $client true, the client wording replaces
	 * the title, and the internal detail is left out.
	 *
	 * @return array<string, mixed>
	 */
	public static function to_array( WP_Post $post, bool $client = false ): array {
		$meta = static fn( string $key ): string => (string) get_post_meta( $post->ID, $key, true );
		$kind = $meta( self::META_KIND );
		$out  = array(
			'id'          => (int) $post->ID,
			'date'        => get_the_date( 'Y-m-d', $post ),
			'title'       => (string) $post->post_title,
			'client_text' => $meta( self::META_CLIENT ),
			'show_client' => '1' === $meta( self::META_SHOW ),
			'kind'        => $kind,
			'topic'       => $meta( self::META_TOPIC ),
			'status'      => $meta( self::META_STATUS ) ?: 'done',
			'version'     => $meta( self::META_VERSION ),
			'measure'     => $meta( self::META_MEASURE ),
			'source'      => 'Claude' === $meta( self::META_SOURCE ) ? 'Scout' : $meta( self::META_SOURCE ), // Older notes read Claude; Scout's writing is Scout-branded (Joy, 2026-11-04).
			'ref'         => $meta( self::META_REF ),
		);
		if ( $client ) {
			$out['text'] = '' !== $out['client_text'] ? $out['client_text'] : $out['title'];
			unset( $out['client_text'] );
		} else {
			$out['detail']    = 'alert' === $kind ? '' : (string) $post->post_content;
			$out['import_id'] = $meta( self::META_IMPORT );
		}
		return $out;
	}

	const META_APPROVED_BY = '_scout_log_approved_by';
	const META_APPROVED_AT = '_scout_log_approved_at';

	/**
	 * What the client sees on Your site: lines shown to the client, sorted
	 * into what needs them, what was done this month, what is coming, and
	 * what was done earlier this year.
	 *
	 * @return array{waiting: array[], month: array[], coming: array[], earlier: array[]}
	 */
	public static function client_view(): array {
		$out   = array( 'waiting' => array(), 'month' => array(), 'coming' => array(), 'earlier' => array() );
		$month = current_time( 'Y-m' );
		$year  = current_time( 'Y' );
		$page  = self::query( array( 'client' => true, 'per_page' => 200, 'since' => $year . '-01-01' ) );
		foreach ( $page['items'] as $post ) {
			$row = self::to_array( $post, true );
			if ( 'waiting' === $row['status'] ) {
				$out['waiting'][] = $row;
			} elseif ( 'planned' === $row['status'] ) {
				$out['coming'][] = $row;
			} elseif ( 0 === strpos( $row['date'], $month ) ) {
				$out['month'][] = $row;
			} else {
				$out['earlier'][] = $row;
			}
		}
		$out['earlier'] = array_slice( $out['earlier'], 0, 20 );
		return $out;
	}

	/**
	 * The client approves a line that was waiting on them. The line becomes
	 * done with the approver's name and date on it, an approval line is added
	 * (the record), and Scout hears by email.
	 */
	public static function approve( int $id, WP_User $user ): bool {
		if ( self::TYPE !== get_post_type( $id ) || '1' !== (string) get_post_meta( $id, self::META_SHOW, true ) ) {
			return false;
		}
		if ( 'waiting' !== (string) get_post_meta( $id, self::META_STATUS, true ) ) {
			return false;
		}
		$name = (string) $user->display_name;
		update_post_meta( $id, self::META_STATUS, 'done' );
		update_post_meta( $id, self::META_APPROVED_BY, $name );
		update_post_meta( $id, self::META_APPROVED_AT, current_time( 'mysql' ) );
		$what = (string) get_post_meta( $id, self::META_CLIENT, true );
		$what = '' !== $what ? $what : get_the_title( $id );
		self::add(
			array(
				/* translators: 1: person, 2: what was approved */
				'title'  => sprintf( __( 'Approved by %1$s: %2$s', 'scout-build' ), $name, $what ),
				'kind'   => 'approval',
				'topic'  => 'content',
				'source' => $name,
				'ref'    => 'line:' . $id,
			)
		);
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		wp_mail(
			Scout_Hub_Requests::recipient(),
			/* translators: 1: site, 2: what was approved */
			sprintf( __( '[%1$s] Approved: %2$s', 'scout-build' ), $site, wp_trim_words( $what, 10, '…' ) ),
			/* translators: 1: person, 2: what, 3: date, 4: admin URL */
			sprintf( __( "%1\$s approved this on the site:\n\n%2\$s\n\n%3\$s\nSite history: %4\$s", 'scout-build' ), $name, $what, current_time( 'F j, Y g:i a' ), Scout_Hub_Admin::url( 'log' ) )
		);
		return true;
	}

	/** How many lines this month, for the nav pill. */
	public static function count_month(): int {
		return self::query( array( 'month' => current_time( 'Y-m' ), 'per_page' => 1 ) )['total'];
	}

	/* ------------------------------------------------------------------ */
	/* The screen's actions: note, edit, import, export                     */
	/* ------------------------------------------------------------------ */

	public static function handle(): void {
		check_admin_referer( self::ACTION );
		$do = sanitize_key( wp_unslash( (string) ( $_POST['do'] ?? '' ) ) );

		// The one action a client takes: approve something that was waiting on them.
		if ( 'approve' === $do ) {
			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_die( esc_html__( 'You do not have permission to do that.', 'scout-build' ), 403 );
			}
			$ok = self::approve( (int) ( $_POST['line'] ?? 0 ), wp_get_current_user() );
			self::back( $ok ? __( 'Approved. Thank you; Scout has been told.', 'scout-build' ) : __( 'That line is not waiting on an approval.', 'scout-build' ), $ok ? 'success' : 'error' );
		}

		if ( ! Scout_Hub_Access::is_operator() ) {
			wp_die( esc_html__( 'Only the people who look after this site can write to its log.', 'scout-build' ), 403 );
		}

		if ( 'note' === $do ) {
			$fields = array();
			foreach ( array( 'title', 'client_text', 'topic', 'version', 'measure', 'status', 'detail', 'date' ) as $key ) {
				$fields[ $key ] = sanitize_textarea_field( wp_unslash( (string) ( $_POST[ $key ] ?? '' ) ) );
			}
			$fields['kind']        = 'note';
			$fields['show_client'] = ! empty( $_POST['show_client'] );
			$id                    = self::add( $fields );
			self::back( $id ? __( 'Added to the log.', 'scout-build' ) : __( 'The line needs a title.', 'scout-build' ), $id ? 'success' : 'error' );
		}

		if ( 'edit' === $do ) {
			$id     = (int) ( $_POST['line'] ?? 0 );
			$fields = array(
				'client_text' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['client_text'] ?? '' ) ) ),
				'measure'     => sanitize_text_field( wp_unslash( (string) ( $_POST['measure'] ?? '' ) ) ),
				'show_client' => ! empty( $_POST['show_client'] ),
			);
			if ( isset( $_POST['status'] ) ) {
				$fields['status'] = sanitize_key( wp_unslash( (string) $_POST['status'] ) );
			}
			self::edit( $id, $fields );
			self::back( __( 'Line updated.', 'scout-build' ) );
		}

		if ( 'import' === $do ) {
			$result = self::import_upload();
			if ( is_wp_error( $result ) ) {
				self::back( $result->get_error_message(), 'error' );
			}
			update_option( self::IMPORTED, time(), false );
			/* translators: 1: lines added, 2: lines skipped */
			self::back( sprintf( __( 'History imported: %1$d added, %2$d already there.', 'scout-build' ), $result['added'], $result['skipped'] ) );
		}

		if ( 'export' === $do ) {
			self::export_csv();
		}

		if ( 'keys' === $do ) {
			$keys = Scout_Hub_Log_API::make_keys();
			// Shown once, to this person, for five minutes. Never stored readable.
			set_transient( 'scout_hub_keys_show_' . get_current_user_id(), $keys, 5 * MINUTE_IN_SECONDS );
			self::add(
				array(
					'title'       => __( 'Hub keys made on the Site history screen', 'scout-build' ),
					'kind'        => 'setup',
					'topic'       => 'setup',
					'show_client' => false,
				)
			);
			self::back( __( 'Keys made. Copy both into the password manager now; they are shown once.', 'scout-build' ) );
		}

		if ( 'forget_keys' === $do ) {
			Scout_Hub_Log_API::forget_keys();
			self::back( __( 'Keys forgotten. The hub endpoint is off until new keys are made.', 'scout-build' ) );
		}
		self::back( __( 'Nothing to do.', 'scout-build' ), 'error' );
	}

	/** @return array{added:int, skipped:int}|WP_Error */
	private static function import_upload() {
		if ( empty( $_FILES['history']['tmp_name'] ) || ! is_uploaded_file( $_FILES['history']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return new WP_Error( 'scout_log', __( 'Choose the history file for this site first.', 'scout-build' ) );
		}
		$raw  = (string) file_get_contents( $_FILES['history']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- an uploaded file read once.
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'scout_log', __( 'That file is not one of the site log history files.', 'scout-build' ) );
		}
		return self::import( $data );
	}

	/**
	 * Add every entry in a history file. Entries with an import ID already in
	 * the log are skipped, so running it twice changes nothing.
	 *
	 * @param array<string, mixed> $data {site, source, entries[]}.
	 * @return array{added:int, skipped:int}|WP_Error
	 */
	public static function import( array $data ) {
		$entries = isset( $data['entries'] ) && is_array( $data['entries'] ) ? $data['entries'] : null;
		if ( null === $entries ) {
			return new WP_Error( 'scout_log', __( 'The file has no entries.', 'scout-build' ) );
		}
		$added   = 0;
		$skipped = 0;
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( empty( $entry['import_id'] ) ) {
				$entry['import_id'] = 'file-' . md5( (string) wp_json_encode( $entry ) );
			}
			if ( empty( $entry['source'] ) ) {
				$entry['source'] = 'Import';
			}
			if ( self::add( $entry ) ) {
				++$added;
			} else {
				++$skipped;
			}
		}
		return array( 'added' => $added, 'skipped' => $skipped );
	}

	private static function export_csv(): void {
		$args = array( 'per_page' => 200, 'page' => 1 );
		foreach ( array( 'month', 'kind', 'topic', 'status' ) as $key ) {
			if ( ! empty( $_POST[ $key ] ) ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
			}
		}
		if ( ! empty( $_POST['client'] ) ) {
			$args['client'] = true;
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="site-log-' . sanitize_file_name( $host ) . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out  = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$cols = array( 'date', 'title', 'client_text', 'show_client', 'kind', 'topic', 'status', 'version', 'measure', 'source', 'ref', 'detail' );
		fputcsv( $out, $cols );
		do {
			$page = self::query( $args );
			foreach ( $page['items'] as $post ) {
				$row = self::to_array( $post );
				$line = array();
				foreach ( $cols as $c ) {
					$v      = $row[ $c ] ?? '';
					$v      = is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : (string) $v;
					$line[] = preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v; // No formulas in a spreadsheet.
				}
				fputcsv( $out, $line );
			}
			++$args['page'];
		} while ( $args['page'] <= $page['pages'] );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	private static function back( string $message, string $type = 'success' ): void {
		set_transient( 'scout_hub_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), MINUTE_IN_SECONDS );
		wp_safe_redirect( Scout_Hub_Admin::url( 'log' ) );
		exit;
	}
}
