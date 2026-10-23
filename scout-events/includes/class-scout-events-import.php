<?php
/**
 * Bring events, speakers and sessions in from spreadsheets (CSV), so a
 * presenter list from a form, a past festival or a client's own sheet does
 * not have to be typed in one entry at a time.
 *
 * Three files, each optional, read in this order so sessions can point at
 * the events and speakers above them:
 *
 *   events.csv    title, slug, start_date, start_time, end_date, end_time,
 *                 status, mode, venue, street, city, region, postal, country,
 *                 online_url, availability, ticket_url, ticket_label,
 *                 price_from, price_to, sales_start, sales_end, min_age,
 *                 capacity, know, schema_type, excerpt, description, photo,
 *                 post_status
 *   speakers.csv  name, slug, role, short_bio, bio, website, links, photo,
 *                 photos (more to choose from, "|" between), order, post_status
 *   sessions.csv  title, slug, event, date, start, end, room, track, kind,
 *                 speakers, capacity, description, post_status
 *   vendors.csv   name, slug, city, website, about, products, events, photo,
 *                 post_status (public details only: contact details stay off
 *                 spreadsheets and come in through the vendor form)
 *
 * Column names are matched without regard to case; unknown columns are
 * ignored. "links" and "speakers" take several values separated by "|".
 * "know" takes several lines separated by "|". "photo" is a file name already
 * in the Media Library or a web address to download. "event" and "speakers"
 * match by slug, then by title or name.
 *
 * A row matches an existing entry by slug, else by title (sessions: title
 * within the same event). Rows that share a title but name different slugs
 * stay separate entries. Matching rows are updated with the columns that
 * have a value; empty cells never wipe a field. Running the same files twice
 * changes nothing. Every run starts as a preview that writes nothing.
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_Import {

	const MAX_ROWS = 3000;

	/** @var array<string,int> slug or lowercased title => post id, per type, filled as rows are read. */
	private array $index = array();

	/** @var array<string,int[]> post ids written by this run, per type. */
	private array $seen = array();

	private bool $dry;

	/** @var array{created:array<string,int>,updated:array<string,int>,notes:string[]} */
	private array $report = array(
		'created' => array(
			'events'   => 0,
			'speakers' => 0,
			'sessions' => 0,
			'vendors'  => 0,
		),
		'updated' => array(
			'events'   => 0,
			'speakers' => 0,
			'sessions' => 0,
			'vendors'  => 0,
		),
		'notes'   => array(),
	);

	/** Preview ids handed out for rows that would be created, so later files can refer to them. */
	private int $fake = 0;

	public function __construct( bool $dry ) {
		$this->dry = $dry;
	}

	/**
	 * Run the import.
	 *
	 * @param array<string,string> $paths 'events', 'speakers', 'sessions' => readable CSV path.
	 * @return array{created:array<string,int>,updated:array<string,int>,notes:string[],dry:bool}
	 */
	public function run( array $paths ): array {
		foreach ( array( 'events', 'speakers', 'sessions', 'vendors' ) as $kind ) {
			if ( empty( $paths[ $kind ] ) ) {
				continue;
			}
			$rows = self::read_csv( $paths[ $kind ] );
			if ( is_string( $rows ) ) {
				$this->note( $kind, 0, $rows );
				continue;
			}
			foreach ( $rows as $n => $row ) {
				$this->{ 'row_' . $kind }( $row, $n + 2 );
			}
		}
		return $this->report + array( 'dry' => $this->dry );
	}

	/**
	 * A CSV file as rows keyed by lowercased header, or an error message.
	 *
	 * @return array<int,array<string,string>>|string
	 */
	public static function read_csv( string $path ) {
		$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading an uploaded temp file.
		if ( ! $fh ) {
			return __( 'The file could not be opened.', 'scout-events' );
		}
		$head = fgetcsv( $fh, 0, ',', '"', '\\' );
		if ( ! $head ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return __( 'The file is empty.', 'scout-events' );
		}
		$head[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $head[0] ); // Spreadsheet apps add a byte-order mark.
		$head    = array_map(
			static function ( $h ) {
				return strtolower( str_replace( ' ', '_', trim( (string) $h ) ) );
			},
			$head
		);
		$rows = array();
		while ( ( $line = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( array( null ) === $line || '' === trim( implode( '', array_map( 'strval', $line ) ) ) ) {
				continue;
			}
			$row = array();
			foreach ( $head as $i => $key ) {
				$row[ $key ] = trim( (string) ( $line[ $i ] ?? '' ) );
			}
			$rows[] = $row;
			if ( count( $rows ) > self::MAX_ROWS ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				/* translators: %d: row limit. */
				return sprintf( __( 'The file has more than %d rows. Split it into smaller files.', 'scout-events' ), self::MAX_ROWS );
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $rows;
	}

	/* ---------- rows ---------- */

	private function row_events( array $r, int $line ): void {
		$title = $r['title'] ?? '';
		if ( '' === $title ) {
			$this->note( 'events', $line, __( 'No title, skipped.', 'scout-events' ) );
			return;
		}
		$id    = $this->find( Scout_Events::EVENT, $r['slug'] ?? '', $title, true );
		$merge = array();
		$map   = array( 'start_date', 'start_time', 'end_date', 'end_time', 'status', 'mode', 'venue', 'street', 'city', 'region', 'postal', 'country', 'online_url', 'availability', 'ticket_url', 'ticket_label', 'price_from', 'price_to', 'sales_start', 'sales_end', 'min_age', 'capacity', 'schema_type', 'signup', 'signup_guests' );
		foreach ( $map as $key ) {
			if ( isset( $r[ $key ] ) && '' !== $r[ $key ] ) {
				$merge[ $key ] = $r[ $key ];
			}
		}
		if ( ! empty( $r['know'] ) ) {
			$merge['know'] = implode( "\n", array_map( 'trim', explode( '|', $r['know'] ) ) );
		}
		if ( isset( $merge['start_date'] ) && '' === Scout_Events::clean_date( $merge['start_date'] ) ) {
			/* translators: %s: the date as written. */
			$this->note( 'events', $line, sprintf( __( 'Start date "%s" is not YYYY-MM-DD, left empty.', 'scout-events' ), $merge['start_date'] ) );
		}
		$id = $this->save_post( Scout_Events::EVENT, 'events', $id, $r, $title, $line );
		if ( ! $id ) {
			return;
		}
		if ( ! $this->dry ) {
			$current = $id > 0 ? get_post_meta( $id, '_scout_event', true ) : array();
			Scout_Events::save_event( $id, array_merge( is_array( $current ) ? $current : array(), $merge ) );
			$this->photo( $id, $r['photo'] ?? '', 'events', $line );
			/** After an events.csv row is saved, with every column, so other Scout features can read their own. */
			do_action( 'scout_events_imported_event', $id, $r );
		}
	}

	private function row_vendors( array $r, int $line ): void {
		$name = $r['name'] ?? '';
		if ( '' === $name ) {
			$this->note( 'vendors', $line, __( 'No name, skipped.', 'scout-events' ) );
			return;
		}
		$id               = $this->find( Scout_Events_Vendors::TYPE, $r['slug'] ?? '', $name, true );
		$r['description'] = $r['about'] ?? '';
		$id               = $this->save_post( Scout_Events_Vendors::TYPE, 'vendors', $id, $r, $name, $line );
		if ( ! $id || $this->dry ) {
			return;
		}
		$v = Scout_Events_Vendors::vendor( $id );
		foreach ( array( 'city', 'website', 'products' ) as $k ) {
			if ( '' !== ( $r[ $k ] ?? '' ) ) {
				$v[ $k ] = $r[ $k ];
			}
		}
		foreach ( array_filter( array_map( 'trim', explode( '|', $r['events'] ?? '' ) ) ) as $word ) {
			$event = $this->find( Scout_Events::EVENT, $word, $word );
			if ( $event > 0 ) {
				$v['events'][] = $event;
			} else {
				/* translators: %s: event as written. */
				$this->note( 'vendors', $line, sprintf( __( 'Event "%s" not found.', 'scout-events' ), $word ) );
			}
		}
		$v['events'] = array_unique( $v['events'] );
		Scout_Events_Vendors::save_vendor( $id, $v );
		$this->photo( $id, $r['photo'] ?? '', 'vendors', $line );
	}

	private function row_speakers( array $r, int $line ): void {
		$name = $r['name'] ?? '';
		if ( '' === $name ) {
			$this->note( 'speakers', $line, __( 'No name, skipped.', 'scout-events' ) );
			return;
		}
		$id = $this->find( Scout_Events::SPEAKER, $r['slug'] ?? '', $name, true );
		$r['description'] = $r['bio'] ?? '';
		$id               = $this->save_post( Scout_Events::SPEAKER, 'speakers', $id, $r, $name, $line );
		if ( ! $id || $this->dry ) {
			return;
		}
		$current = Scout_Events::speaker( $id );
		$merge   = array();
		if ( '' !== ( $r['role'] ?? '' ) ) {
			$merge['role'] = $r['role'];
		}
		if ( '' !== ( $r['short_bio'] ?? '' ) ) {
			$merge['short'] = $r['short_bio'];
		}
		if ( '' !== ( $r['website'] ?? '' ) ) {
			$merge['website'] = $r['website'];
		}
		if ( '' !== ( $r['links'] ?? '' ) ) {
			$merge['links'] = array_merge( $current['links'], array_map( 'trim', explode( '|', $r['links'] ) ) );
		}
		update_post_meta( $id, '_scout_speaker', Scout_Events::sanitize_speaker( array_merge( $current, $merge ) ) );
		if ( '' !== ( $r['order'] ?? '' ) ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'menu_order' => (int) $r['order'],
				)
			);
		}
		$this->photo( $id, $r['photo'] ?? '', 'speakers', $line );
		// More photos to choose from: file names separated by "|".
		foreach ( array_filter( array_map( 'trim', explode( '|', $r['photos'] ?? '' ) ) ) as $file ) {
			$att = self::attachment_by_file( $file );
			if ( $att ) {
				Scout_Events_Photos::add( $id, $att );
			} else {
				/* translators: %s: file name. */
				$this->note( 'speakers', $line, sprintf( __( 'Photo %s is not in the Media Library yet. Upload it, then run the import again.', 'scout-events' ), $file ) );
			}
		}
	}

	private function row_sessions( array $r, int $line ): void {
		$title = $r['title'] ?? '';
		if ( '' === $title ) {
			$this->note( 'sessions', $line, __( 'No title, skipped.', 'scout-events' ) );
			return;
		}
		$event = '' !== ( $r['event'] ?? '' ) ? $this->find( Scout_Events::EVENT, $r['event'], $r['event'] ) : 0;
		if ( '' !== ( $r['event'] ?? '' ) && ! $event ) {
			/* translators: %s: event as written. */
			$this->note( 'sessions', $line, sprintf( __( 'No event called "%s", so the session is not linked to one.', 'scout-events' ), $r['event'] ) );
		}
		$speakers = array();
		foreach ( array_filter( array_map( 'trim', explode( '|', $r['speakers'] ?? '' ) ) ) as $who ) {
			$pid = $this->find( Scout_Events::SPEAKER, $who, $who );
			if ( $pid ) {
				$speakers[] = $pid;
			} else {
				/* translators: %s: speaker as written. */
				$this->note( 'sessions', $line, sprintf( __( 'No speaker called "%s".', 'scout-events' ), $who ) );
			}
		}
		$id = $this->find_session( $r['slug'] ?? '', $title, $event, $speakers );
		$id = $this->save_post( Scout_Events::SESSION, 'sessions', $id, $r, $title, $line );
		if ( $id ) {
			$this->index[ Scout_Events::SESSION . '|e|' . $event . '|' . strtolower( $title ) ] = $id;
		}
		if ( ! $id || $this->dry ) {
			return;
		}
		$s = Scout_Events::session( $id );
		if ( $event > 0 ) {
			$s['event'] = $event;
		}
		foreach ( array( 'date', 'start', 'end', 'room', 'track', 'kind', 'capacity' ) as $key ) {
			if ( '' !== ( $r[ $key ] ?? '' ) ) {
				$s[ $key ] = $r[ $key ];
			}
		}
		if ( $speakers ) {
			$s['speakers'] = array_values( array_unique( array_merge( $s['speakers'], array_filter( $speakers, static fn( $p ) => $p > 0 ) ) ) );
		}
		Scout_Events::save_session( $id, $s );
	}

	/* ---------- helpers ---------- */

	/**
	 * Create or update the post itself. Returns its id (a negative preview id
	 * in a dry run for rows that would be created), or 0 on failure.
	 */
	private function save_post( string $type, string $kind, int $id, array $r, string $title, int $line ): int {
		$status = in_array( $r['post_status'] ?? '', array( 'publish', 'draft', 'pending', 'private' ), true ) ? $r['post_status'] : '';
		if ( $this->dry ) {
			if ( $id ) {
				++$this->report['updated'][ $kind ];
			} else {
				++$this->report['created'][ $kind ];
				$id = --$this->fake;
			}
			$this->remember( $type, $r['slug'] ?? '', $title, $id );
			return $id;
		}
		$post = array(
			'post_type'  => $type,
			'post_title' => $title,
		);
		if ( '' !== ( $r['description'] ?? '' ) ) {
			$post['post_content'] = wp_kses_post( $r['description'] );
		}
		if ( '' !== ( $r['excerpt'] ?? '' ) ) {
			$post['post_excerpt'] = sanitize_textarea_field( $r['excerpt'] );
		}
		if ( '' !== ( $r['slug'] ?? '' ) ) {
			$post['post_name'] = sanitize_title( $r['slug'] );
		}
		if ( $id > 0 ) {
			$post['ID'] = $id;
			if ( '' !== $status ) {
				$post['post_status'] = $status;
			}
			$result = wp_update_post( wp_slash( $post ), true );
			++$this->report['updated'][ $kind ];
		} else {
			$post['post_status'] = '' !== $status ? $status : 'publish';
			$result              = wp_insert_post( wp_slash( $post ), true );
			++$this->report['created'][ $kind ];
		}
		if ( is_wp_error( $result ) ) {
			$this->note( $kind, $line, $result->get_error_message() );
			return 0;
		}
		$this->remember( $type, $r['slug'] ?? '', $title, (int) $result );
		return (int) $result;
	}

	/**
	 * An existing post of a type by slug, then title, including ones read
	 * earlier in this run.
	 *
	 * For a row that names its own slug, the title only matches a post this
	 * run has not written yet, so rows that share a title (Pause & Play in
	 * January, April and August) stay separate posts.
	 *
	 * @param bool $row True when looking up the row being imported, false for a reference to another post.
	 */
	private function find( string $type, string $slug, string $title, bool $row = false ): int {
		$slug_key  = $type . '|s|' . sanitize_title( $slug );
		$title_key = $type . '|t|' . strtolower( trim( $title ) );
		$own_slug  = $row && '' !== $slug;
		if ( '' !== $slug && isset( $this->index[ $slug_key ] ) ) {
			return $this->index[ $slug_key ];
		}
		if ( ! $own_slug && '' !== $title && isset( $this->index[ $title_key ] ) ) {
			return $this->index[ $title_key ];
		}
		$statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );
		if ( '' !== $slug ) {
			$found = get_posts(
				array(
					'post_type'      => $type,
					'name'           => sanitize_title( $slug ),
					'post_status'    => $statuses,
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( $found ) {
				return (int) $found[0];
			}
		}
		if ( '' !== $title ) {
			$found = get_posts(
				array(
					'post_type'      => $type,
					'title'          => $title,
					'post_status'    => $statuses,
					'post__not_in'   => $own_slug ? array_filter( $this->seen[ $type ] ?? array(), static fn( $i ) => $i > 0 ) : array(),
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( $found ) {
				return (int) $found[0];
			}
		}
		return 0;
	}

	/**
	 * A session by slug, else by title within the same event, else by title
	 * and the same speakers in any event, so a session moved to another event
	 * between runs is updated rather than copied.
	 *
	 * @param int[] $speakers Speaker ids named on the row.
	 */
	private function find_session( string $slug, string $title, int $event, array $speakers = array() ): int {
		if ( '' !== $slug ) {
			$id = $this->find( Scout_Events::SESSION, $slug, '' );
			if ( $id ) {
				return $id;
			}
		}
		$key = Scout_Events::SESSION . '|e|' . $event . '|' . strtolower( $title );
		if ( isset( $this->index[ $key ] ) ) {
			return $this->index[ $key ];
		}
		if ( $event <= 0 ) {
			return $this->find( Scout_Events::SESSION, '', $title );
		}
		foreach ( Scout_Events::session_ids_any( $event ) as $sid ) {
			if ( self::same_title( (string) get_post_field( 'post_title', $sid, 'raw' ), $title ) ) {
				return $sid;
			}
		}
		$people = array_filter( $speakers, static fn( $p ) => $p > 0 );
		if ( $people ) {
			$candidates = get_posts(
				array(
					'post_type'      => Scout_Events::SESSION,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'title'          => $title,
					'posts_per_page' => 20,
					'fields'         => 'ids',
				)
			);
			foreach ( $candidates as $sid ) {
				if ( array_intersect( $people, Scout_Events::session( (int) $sid )['speakers'] ) ) {
					return (int) $sid;
				}
			}
		}
		return 0;
	}

	/** Titles match ignoring case and how "&" was stored. */
	private static function same_title( string $a, string $b ): bool {
		$clean = static function ( string $t ): string {
			return strtolower( trim( html_entity_decode( $t, ENT_QUOTES, 'UTF-8' ) ) );
		};
		return $clean( $a ) === $clean( $b );
	}

	private function remember( string $type, string $slug, string $title, int $id ): void {
		if ( '' !== $slug ) {
			$this->index[ $type . '|s|' . sanitize_title( $slug ) ] = $id;
		}
		$this->index[ $type . '|t|' . strtolower( trim( $title ) ) ] = $id;
		$this->seen[ $type ][]                                      = $id;
	}

	/** Set the featured image from a Media Library file name or a web address, once. */
	private function photo( int $post_id, string $photo, string $kind, int $line ): void {
		if ( '' === $photo ) {
			return;
		}
		// The main photo is never replaced, so a choice made on the site stands. A speaker's
		// different photo joins their Photos box as an option instead.
		if ( has_post_thumbnail( $post_id ) ) {
			if ( 'speakers' === $kind && ! preg_match( '#^https?://#i', $photo ) ) {
				$att = self::attachment_by_file( $photo );
				if ( $att ) {
					Scout_Events_Photos::add( $post_id, $att );
				}
			}
			return;
		}
		$attachment = 0;
		if ( preg_match( '#^https?://#i', $photo ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$result = media_sideload_image( esc_url_raw( $photo ), $post_id, get_the_title( $post_id ), 'id' );
			if ( is_wp_error( $result ) ) {
				/* translators: 1: photo address, 2: error. */
				$this->note( $kind, $line, sprintf( __( 'Photo %1$s could not be downloaded: %2$s', 'scout-events' ), $photo, $result->get_error_message() ) );
				return;
			}
			$attachment = (int) $result;
		} else {
			$attachment = self::attachment_by_file( $photo );
			if ( ! $attachment ) {
				/* translators: %s: file name. */
				$this->note( $kind, $line, sprintf( __( 'Photo %s is not in the Media Library yet. Upload it, then run the import again.', 'scout-events' ), $photo ) );
				return;
			}
		}
		set_post_thumbnail( $post_id, $attachment );
	}

	/** A Media Library item by its original file name (WordPress may add -1, -scaled). */
	public static function attachment_by_file( string $file ): int {
		$base = pathinfo( sanitize_file_name( $file ), PATHINFO_FILENAME );
		if ( '' === $base ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one lookup per imported row.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
				'%' . $wpdb->esc_like( $base ) . '%'
			)
		);
		foreach ( (array) $rows as $row ) {
			$name = pathinfo( (string) $row->meta_value, PATHINFO_FILENAME );
			if ( preg_match( '/^' . preg_quote( $base, '/' ) . '(-\d+)?(-scaled)?$/i', $name ) ) {
				return (int) $row->post_id;
			}
		}
		return 0;
	}

	private function note( string $kind, int $line, string $text ): void {
		$labels                   = array(
			'events'   => __( 'Events', 'scout-events' ),
			'speakers' => __( 'Speakers', 'scout-events' ),
			'sessions' => __( 'Sessions', 'scout-events' ),
			'vendors'  => __( 'Vendors', 'scout-events' ),
		);
		$where                    = $line > 0
			/* translators: 1: file label, 2: row number. */
			? sprintf( __( '%1$s, row %2$d', 'scout-events' ), $labels[ $kind ], $line )
			: $labels[ $kind ];
		$this->report['notes'][] = $where . ': ' . $text;
	}
}
