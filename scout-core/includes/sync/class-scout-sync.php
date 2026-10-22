<?php
/**
 * Site Sync: one updating system for every Scout site.
 *
 * The theme ships a scout-sync.json manifest that lists the copy it bundles
 * (page content, page fields, SEO, shared settings). This engine compares each
 * item three ways, the copy in the theme, the copy WordPress holds, and the
 * base recorded at the last sync, so it can tell an untouched page (safe to
 * update) from one somebody edited in WordPress (never overwritten silently).
 *
 * Writes are reviewed, locked, journaled before they start, compared again
 * right before each write, and can be undone. The design started from the
 * reviewed content update built for a client site in September 2026 and is
 * generalized here so every site shares it. Spec: docs/site-sync-spec.md.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Sync {

	const MANIFEST  = 'scout-sync.json';
	const BASES     = 'scout_sync_bases';
	const JOURNAL   = 'scout_sync_journal';
	const LOCK      = 'scout_sync_lock';
	const SEEN      = 'scout_sync_seen';
	const SUMMARY   = 'scout_sync_summary';
	const META_FILE = '_scout_sync_file_hash';

	/** States a row can be in. */
	const CURRENT  = 'current';   // WordPress already matches the theme.
	const UPDATE   = 'update';    // Theme changed, WordPress untouched: safe.
	const EDITED   = 'edited';    // Edited in WordPress, theme unchanged.
	const CONFLICT = 'conflict';  // Both changed, or an unknown saved value.
	const MISSING  = 'missing';   // The page or post the item names is not there.

	/** Options a manifest may set. Anything else is ignored. */
	const ALLOWED_OPTIONS = array( 'blogname', 'blogdescription' );

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_auto_run' ), 20 );
		add_action( 'after_switch_theme', array( __CLASS__, 'forget_seen' ) );
		// A saved edit can change what needs review; recount on the next look.
		add_action( 'save_post', array( __CLASS__, 'forget_summary' ) );
		add_action( 'updated_option', array( __CLASS__, 'forget_summary_option' ) );
	}

	/* ---------- Manifest ---------- */

	/** Absolute path of the active theme's manifest. */
	public static function manifest_path() {
		return (string) apply_filters( 'scout_sync_manifest_path', trailingslashit( get_stylesheet_directory() ) . self::MANIFEST );
	}

	/**
	 * The parsed manifest, or null when the theme ships none.
	 * Companions can add items through the `scout_sync_manifest` filter.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function manifest() {
		$path = self::manifest_path();
		$data = null;
		if ( is_readable( $path ) ) {
			$data = json_decode( (string) file_get_contents( $path ), true );
			if ( ! is_array( $data ) ) {
				$data = array( 'error' => 'scout-sync.json is not valid JSON.' );
			}
		}
		$data = apply_filters( 'scout_sync_manifest', $data );
		return is_array( $data ) ? $data : null;
	}

	/** Read a file the manifest points at, relative to the theme root. */
	private static function source( $relative ) {
		$root = realpath( get_stylesheet_directory() );
		$file = realpath( trailingslashit( get_stylesheet_directory() ) . ltrim( (string) $relative, '/' ) );
		// Only files inside the theme. A manifest never reads elsewhere.
		if ( ! $root || ! $file || 0 !== strpos( $file, $root . DIRECTORY_SEPARATOR ) || ! is_file( $file ) ) {
			return null;
		}
		return $file;
	}

	/**
	 * Every item the manifest declares, flattened to one shape.
	 *
	 * @return array{items: array<int,array<string,mixed>>, errors: string[]}
	 */
	public static function items() {
		$manifest = self::manifest();
		$items    = array();
		$errors   = array();
		if ( null === $manifest ) {
			return array( 'items' => array(), 'errors' => array() );
		}
		if ( ! empty( $manifest['error'] ) ) {
			return array( 'items' => array(), 'errors' => array( $manifest['error'] ) );
		}
		if ( 1 !== (int) ( $manifest['sync_version'] ?? 0 ) ) {
			return array( 'items' => array(), 'errors' => array( 'scout-sync.json needs "sync_version": 1.' ) );
		}

		foreach ( (array) ( $manifest['pages'] ?? array() ) as $page ) {
			$type = sanitize_key( $page['post_type'] ?? 'page' );
			$slug = (string) ( $page['slug'] ?? '' );
			if ( isset( $page['title'] ) ) {
				$items[] = array( 'kind' => 'post', 'post_type' => $type, 'slug' => $slug, 'field' => 'title', 'value' => (string) $page['title'] );
			}
			if ( isset( $page['source'] ) || isset( $page['content'] ) ) {
				$items[] = array( 'kind' => 'post', 'post_type' => $type, 'slug' => $slug, 'field' => 'content', 'source' => $page['source'] ?? null, 'value' => $page['content'] ?? null, 'previous' => $page['previous'] ?? array() );
			}
		}
		foreach ( (array) ( $manifest['fields'] ?? array() ) as $f ) {
			$field = '';
			if ( isset( $f['field'] ) ) {
				$field = 'field:' . sanitize_key( $f['field'] );
			} elseif ( isset( $f['seo'] ) ) {
				$field = 'seo:' . ( 'title' === $f['seo'] ? 'title' : 'desc' );
			} elseif ( isset( $f['meta'] ) ) {
				$field = 'meta:' . (string) $f['meta'];
			} elseif ( isset( $f['post_field'] ) && in_array( $f['post_field'], array( 'title', 'content', 'excerpt' ), true ) ) {
				$field = $f['post_field'];
			}
			if ( '' === $field ) {
				$errors[] = 'A field entry names no field, seo, meta or post_field.';
				continue;
			}
			$items[] = array(
				'kind'      => 'post',
				'post_type' => sanitize_key( $f['post_type'] ?? 'page' ),
				'slug'      => (string) ( $f['slug'] ?? '' ),
				'field'     => $field,
				'value'     => $f['value'] ?? null,
				'source'    => $f['source'] ?? null,
				'image'     => $f['image'] ?? null,
				'previous'  => $f['previous'] ?? array(),
			);
		}
		foreach ( (array) ( $manifest['settings'] ?? array() ) as $s ) {
			$items[] = array( 'kind' => 'setting', 'group' => sanitize_key( $s['group'] ?? '' ), 'key' => sanitize_key( $s['key'] ?? '' ), 'value' => $s['value'] ?? null, 'source' => $s['source'] ?? null, 'previous' => $s['previous'] ?? array() );
		}
		foreach ( (array) ( $manifest['options'] ?? array() ) as $o ) {
			$name = (string) ( $o['option'] ?? '' );
			if ( ! in_array( $name, self::ALLOWED_OPTIONS, true ) ) {
				$errors[] = 'Option "' . $name . '" is not one Site Sync may set.';
				continue;
			}
			$items[] = array( 'kind' => 'option', 'option' => $name, 'value' => $o['value'] ?? null, 'previous' => $o['previous'] ?? array() );
		}

		foreach ( $items as &$item ) {
			$item['id']       = self::item_id( $item );
			$item['previous'] = array_map( 'strval', (array) ( $item['previous'] ?? array() ) );
			if ( ! empty( $item['image'] ) ) {
				$file = self::source( $item['image'] );
				if ( ! $file ) {
					$errors[] = 'Image missing from the theme: ' . $item['image'];
				}
				$item['file']  = $file;
				$item['theme'] = $file ? 'img:' . hash_file( 'sha256', $file ) : '';
			} elseif ( null !== ( $item['source'] ?? null ) ) {
				$file = self::source( $item['source'] );
				if ( ! $file ) {
					$errors[] = 'File missing from the theme: ' . $item['source'];
				}
				$item['theme'] = $file ? (string) file_get_contents( $file ) : '';
			} else {
				$item['theme'] = (string) ( $item['value'] ?? '' );
			}
		}
		unset( $item );
		return array( 'items' => $items, 'errors' => $errors );
	}

	/** A stable id for an item, e.g. "page/about#content". */
	public static function item_id( array $item ) {
		if ( 'setting' === $item['kind'] ) {
			return 'setting/' . $item['group'] . '#' . $item['key'];
		}
		if ( 'option' === $item['kind'] ) {
			return 'option#' . $item['option'];
		}
		return $item['post_type'] . '/' . $item['slug'] . '#' . $item['field'];
	}

	/** A plain label for the dashboard. */
	public static function label( array $item ) {
		$field = preg_replace( '/^(field|seo|meta):/', '', $item['field'] ?? '' );
		if ( 'setting' === $item['kind'] ) {
			return 'Site setting: ' . $item['key'];
		}
		if ( 'option' === $item['kind'] ) {
			return 'blogname' === $item['option'] ? 'Site title' : 'Tagline';
		}
		$prefix = 0 === strpos( (string) $item['field'], 'seo:' ) ? 'SEO ' : '';
		return $item['slug'] . ': ' . $prefix . str_replace( '_', ' ', (string) $field );
	}

	/* ---------- Reading what WordPress holds ---------- */

	/** The post an item names, or 0 when it is missing or ambiguous. */
	private static function post_id( array $item, array &$cache ) {
		$key = $item['post_type'] . '/' . $item['slug'];
		if ( ! isset( $cache[ $key ] ) ) {
			$slug  = $item['slug'];
			$found = array();
			if ( 'page' === $item['post_type'] && false !== strpos( $slug, '/' ) ) {
				$page  = get_page_by_path( $slug, OBJECT, 'page' );
				$found = $page ? array( (int) $page->ID ) : array();
			} else {
				$found = get_posts(
					array(
						'post_type'   => $item['post_type'],
						'name'        => sanitize_title( $slug ),
						'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
						'numberposts' => 2,
						'fields'      => 'ids',
					)
				);
			}
			$cache[ $key ] = 1 === count( $found ) ? (int) $found[0] : 0;
		}
		return $cache[ $key ];
	}

	/** The raw saved value for an item. */
	private static function read( array $item ) {
		if ( 'setting' === $item['kind'] ) {
			$values = get_option( Scout_Core_Settings_Groups::option_name( $item['group'] ), array() );
			return (string) ( is_array( $values ) ? ( $values[ $item['key'] ] ?? '' ) : '' );
		}
		if ( 'option' === $item['kind'] ) {
			return (string) get_option( $item['option'], '' );
		}
		$id    = (int) $item['post_id'];
		$field = $item['field'];
		if ( in_array( $field, array( 'title', 'content', 'excerpt' ), true ) ) {
			$post = get_post( $id );
			return $post ? (string) $post->{'post_' . $field} : '';
		}
		return (string) get_post_meta( $id, self::meta_key( $field ), true );
	}

	private static function meta_key( $field ) {
		if ( 0 === strpos( $field, 'field:' ) ) {
			return scout_core_meta_key( substr( $field, 6 ) );
		}
		if ( 'seo:title' === $field ) {
			return SCOUT_SEO_META_TITLE;
		}
		if ( 'seo:desc' === $field ) {
			return SCOUT_SEO_META_DESC;
		}
		return substr( $field, 5 );
	}

	/** What the saved value means for comparison: text as is, images by file. */
	private static function comparable( array $item, $raw ) {
		if ( empty( $item['image'] ) ) {
			return self::normalize( $raw );
		}
		$id = (int) $raw;
		if ( ! $id ) {
			return '';
		}
		$hash = (string) get_post_meta( $id, self::META_FILE, true );
		if ( '' === $hash ) {
			$path = get_attached_file( $id );
			$hash = $path && is_file( $path ) ? hash_file( 'sha256', $path ) : 'missing';
		}
		return 'img:' . $hash;
	}

	/** Line endings and trailing space never count as an edit. */
	public static function normalize( $text ) {
		return rtrim( str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) );
	}

	private static function hash( $text ) {
		return hash( 'sha256', (string) $text );
	}

	/* ---------- The plan ---------- */

	/**
	 * Every item with its state. Read-only.
	 *
	 * @return array{rows: array<int,array<string,mixed>>, errors: string[], hash: string, manifest: bool}
	 */
	public static function plan() {
		$parsed = self::items();
		$bases  = get_option( self::BASES, array() );
		$bases  = is_array( $bases ) ? $bases : array();
		$cache  = array();
		$rows   = array();

		foreach ( $parsed['items'] as $item ) {
			if ( 'post' === $item['kind'] ) {
				$item['post_id'] = self::post_id( $item, $cache );
				if ( ! $item['post_id'] ) {
					$item['state'] = self::MISSING;
					$rows[]        = $item;
					continue;
				}
			}
			if ( 'setting' === $item['kind'] && ! class_exists( 'Scout_Core_Settings_Groups' ) ) {
				$item['state'] = self::MISSING;
				$rows[]        = $item;
				continue;
			}
			$item['raw']   = self::read( $item );
			$theme         = empty( $item['image'] ) ? self::normalize( $item['theme'] ) : $item['theme'];
			$db            = self::comparable( $item, $item['raw'] );
			$item['t']     = self::hash( $theme );
			$item['d']     = self::hash( $db );
			$base          = $bases[ $item['id'] ] ?? null;
			$item['state'] = self::state( $theme, $db, $item, $base );
			$item['synced'] = is_array( $base );
			$rows[]        = $item;
		}

		$hash = self::hash(
			wp_json_encode(
				array(
					home_url(),
					array_map(
						static function ( $r ) {
							return array( $r['id'], $r['state'], $r['t'] ?? '', $r['d'] ?? '' );
						},
						$rows
					),
				)
			)
		);
		return array( 'rows' => $rows, 'errors' => $parsed['errors'], 'hash' => $hash, 'manifest' => null !== self::manifest() );
	}

	/**
	 * The three-way decision.
	 *
	 * @param array{t:string,d:string}|null $base Hashes recorded at the last sync.
	 */
	private static function state( $theme, $db, array $item, $base ) {
		if ( $theme === $db ) {
			return self::CURRENT;
		}
		$t = self::hash( $theme );
		$d = self::hash( $db );
		if ( is_array( $base ) && isset( $base['t'], $base['d'] ) ) {
			$theme_moved = $t !== $base['t'];
			$db_moved    = $d !== $base['d'];
			if ( ! $theme_moved && ! $db_moved ) {
				return self::CURRENT; // A WordPress copy someone chose to keep.
			}
			if ( $theme_moved && ! $db_moved ) {
				// A kept WordPress copy is never replaced automatically, even when the theme moves on.
				return empty( $base['kept'] ) ? self::UPDATE : self::CONFLICT;
			}
			return $theme_moved ? self::CONFLICT : self::EDITED;
		}
		// Never synced. Empty, or a copy an earlier release shipped, is safe.
		if ( '' === $db || in_array( $db, array_map( array( __CLASS__, 'normalize' ), $item['previous'] ), true ) ) {
			return self::UPDATE;
		}
		return self::CONFLICT;
	}

	/** Counts by state, cached for notices and health checks. */
	public static function summary( $refresh = false ) {
		$cached = get_transient( self::SUMMARY );
		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}
		$plan   = self::plan();
		$counts = array_fill_keys( array( self::CURRENT, self::UPDATE, self::EDITED, self::CONFLICT, self::MISSING ), 0 );
		foreach ( $plan['rows'] as $row ) {
			++$counts[ $row['state'] ];
		}
		$summary = array(
			'manifest' => $plan['manifest'],
			'counts'   => $counts,
			'errors'   => count( $plan['errors'] ),
			'review'   => $counts[ self::EDITED ] + $counts[ self::CONFLICT ] + $counts[ self::MISSING ] + count( $plan['errors'] ),
			'time'     => time(),
		);
		set_transient( self::SUMMARY, $summary, 12 * HOUR_IN_SECONDS );
		return $summary;
	}

	/* ---------- Writing ---------- */

	/**
	 * Apply the theme copy to the chosen rows.
	 *
	 * @param string   $hash The plan hash the person reviewed, or '' for automatic runs.
	 * @param string   $mode 'safe' (every UPDATE row), 'take' (chosen rows, theme copy wins) or 'keep' (chosen rows, record the WordPress copy).
	 * @param string[] $ids  Rows for 'take' and 'keep'.
	 * @param string   $who  'person' or 'auto'.
	 * @return array{written:int}|WP_Error
	 */
	public static function apply( $hash, $mode = 'safe', array $ids = array(), $who = 'person' ) {
		if ( 'person' === $who && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Not allowed.' );
		}
		if ( ! self::lock() ) {
			return new WP_Error( 'locked', 'Another sync is running. If one was interrupted, wait 15 minutes and try again.' );
		}
		try {
			$plan = self::plan();
			if ( 'person' === $who && ! hash_equals( $plan['hash'], (string) $hash ) ) {
				return new WP_Error( 'stale', 'Something changed since you looked. Review it again before syncing.' );
			}
			$journal = get_option( self::JOURNAL, array() );
			if ( in_array( $journal['state'] ?? '', array( 'applying', 'restoring' ), true ) ) {
				return new WP_Error( 'unfinished', 'The last sync did not finish. Undo it first, then sync again.' );
			}

			$pick = array();
			foreach ( $plan['rows'] as $row ) {
				if ( 'safe' === $mode && self::UPDATE === $row['state'] ) {
					$pick[] = $row;
				} elseif ( 'safe' !== $mode && in_array( $row['id'], $ids, true ) && self::MISSING !== $row['state'] && self::CURRENT !== $row['state'] ) {
					$pick[] = $row;
				}
			}
			if ( ! $pick ) {
				return array( 'written' => 0 );
			}

			$bases = get_option( self::BASES, array() );
			$bases = is_array( $bases ) ? $bases : array();

			if ( 'keep' === $mode ) {
				foreach ( $pick as $row ) {
					$bases[ $row['id'] ] = array( 't' => $row['t'], 'd' => $row['d'], 'kept' => true );
				}
				update_option( self::BASES, $bases, false );
				self::summary( true );
				return array( 'written' => 0 );
			}

			// Images are prepared before any text changes, so a failed import changes nothing.
			foreach ( $pick as &$row ) {
				$row['write'] = $row['theme'];
				if ( ! empty( $row['image'] ) ) {
					$attachment = self::import_image( $row['file'] );
					if ( ! $attachment ) {
						return new WP_Error( 'media', 'Could not add the image for ' . self::label( $row ) . '. Nothing was changed.' );
					}
					$row['write'] = (string) $attachment;
				}
			}
			unset( $row );

			$journal = array(
				'state' => 'applying',
				'time'  => gmdate( 'c' ),
				'user'  => get_current_user_id(),
				'who'   => $who,
				'theme' => self::theme_key(),
				'rows'  => array_map(
					static function ( $r ) {
						return array_intersect_key( $r, array_flip( array( 'id', 'kind', 'post_type', 'slug', 'field', 'group', 'key', 'option', 'post_id', 'image', 'raw', 'write' ) ) );
					},
					$pick
				),
			);
			if ( ! self::save_journal( $journal ) ) {
				return new WP_Error( 'journal', 'Could not save the undo record. Nothing was changed.' );
			}

			$written = 0;
			foreach ( $pick as $row ) {
				if ( ! self::write( $row, $row['raw'], $row['write'] ) ) {
					$journal['state'] = 'failed';
					self::save_journal( $journal );
					self::summary( true );
					return new WP_Error( 'write', 'Sync stopped at ' . self::label( $row ) . ', because it changed while syncing. Undo the last sync from the Site Sync screen, then review again.' );
				}
				$bases[ $row['id'] ] = array( 't' => $row['t'], 'd' => $row['t'] );
				++$written;
			}
			update_option( self::BASES, $bases, false );
			$journal['state'] = 'applied';
			self::save_journal( $journal );
			self::summary( true );
			do_action( 'scout_sync_applied', $journal['rows'], $who );
			return array( 'written' => $written );
		} finally {
			delete_option( self::LOCK );
		}
	}

	/**
	 * Put back what the last sync replaced. A field edited since is left alone.
	 *
	 * @return array{restored:int,skipped:string[]}|WP_Error
	 */
	public static function undo() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Not allowed.' );
		}
		$journal = get_option( self::JOURNAL, array() );
		if ( ! in_array( $journal['state'] ?? '', array( 'applied', 'failed', 'applying' ), true ) ) {
			return new WP_Error( 'nothing', 'There is no sync to undo.' );
		}
		if ( ! self::lock() ) {
			return new WP_Error( 'locked', 'Another sync is running. Try again in a few minutes.' );
		}
		try {
			$journal['state'] = 'restoring';
			self::save_journal( $journal );
			$bases    = get_option( self::BASES, array() );
			$restored = 0;
			$skipped  = array();
			foreach ( $journal['rows'] as $row ) {
				$now = self::read( $row );
				if ( self::normalize( $now ) === self::normalize( $row['raw'] ) ) {
					continue; // Never written, or already back.
				}
				if ( self::normalize( $now ) !== self::normalize( $row['write'] ) || ! self::write( $row, $now, $row['raw'] ) ) {
					$skipped[] = self::label( $row );
					continue;
				}
				unset( $bases[ $row['id'] ] );
				++$restored;
			}
			update_option( self::BASES, $bases, false );
			$journal['state'] = 'restored';
			self::save_journal( $journal );
			self::summary( true );
			return array( 'restored' => $restored, 'skipped' => $skipped );
		} finally {
			delete_option( self::LOCK );
		}
	}

	/** Compare and write one field. False when it changed underneath us. */
	private static function write( array $row, $expected, $value ) {
		if ( self::normalize( self::read( $row ) ) !== self::normalize( $expected ) ) {
			return false;
		}
		if ( 'setting' === $row['kind'] ) {
			$name   = Scout_Core_Settings_Groups::option_name( $row['group'] );
			$values = get_option( $name, array() );
			$values = is_array( $values ) ? $values : array();
			$values[ $row['key'] ] = $value;
			update_option( $name, $values );
		} elseif ( 'option' === $row['kind'] ) {
			update_option( $row['option'], $value );
		} elseif ( in_array( $row['field'], array( 'title', 'content', 'excerpt' ), true ) ) {
			$result = wp_update_post(
				array(
					'ID'                     => (int) $row['post_id'],
					'post_' . $row['field'] => wp_slash( $value ),
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return false;
			}
		} else {
			update_post_meta( (int) $row['post_id'], self::meta_key( $row['field'] ), wp_slash( $value ) );
		}
		return self::normalize( self::read( $row ) ) === self::normalize( $value );
	}

	/** Add a theme image to the media library once, tagged with its file hash. */
	private static function import_image( $file ) {
		$hash     = hash_file( 'sha256', $file );
		$existing = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'meta_key'    => self::META_FILE, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $hash,           // phpcs:ignore WordPress.DB.SlowDBQuery
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		if ( $existing ) {
			return (int) $existing[0];
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$tmp = wp_tempnam( wp_basename( $file ) );
		if ( ! $tmp || ! copy( $file, $tmp ) ) {
			return 0;
		}
		$id = media_handle_sideload( array( 'name' => wp_basename( $file ), 'tmp_name' => $tmp ), 0 );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return 0;
		}
		update_post_meta( $id, self::META_FILE, $hash );
		return (int) $id;
	}

	private static function save_journal( array $journal ) {
		update_option( self::JOURNAL, $journal, false );
		return get_option( self::JOURNAL ) === $journal;
	}

	/** One sync at a time. A lock older than 15 minutes is treated as abandoned. */
	private static function lock() {
		$old = get_option( self::LOCK );
		if ( is_array( $old ) && ( $old['expires'] ?? 0 ) < time() ) {
			delete_option( self::LOCK );
		}
		return add_option( self::LOCK, array( 'expires' => time() + 900 ), '', false );
	}

	/* ---------- Automatic runs ---------- */

	/** The active theme, its version and its manifest, as one key. */
	public static function theme_key() {
		$theme = wp_get_theme();
		$path  = self::manifest_path();
		return get_stylesheet() . '@' . $theme->get( 'Version' ) . '#' . ( is_readable( $path ) ? md5_file( $path ) : 'none' );
	}

	public static function forget_summary() {
		delete_transient( self::SUMMARY );
	}

	public static function forget_summary_option( $option ) {
		if ( in_array( $option, self::ALLOWED_OPTIONS, true ) || 0 === strpos( (string) $option, 'scout_group_' ) ) {
			delete_transient( self::SUMMARY );
		}
	}

	public static function forget_seen() {
		delete_option( self::SEEN );
	}

	/**
	 * After a theme update, sync the rows nobody has touched in WordPress and
	 * leave everything else for review. Runs once per theme version.
	 */
	public static function maybe_auto_run() {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key = self::theme_key();
		if ( get_option( self::SEEN ) === $key ) {
			return;
		}
		update_option( self::SEEN, $key, false );
		if ( null === self::manifest() || ! apply_filters( 'scout_sync_auto', true ) ) {
			self::summary( true );
			return;
		}
		$result = self::apply( '', 'safe', array(), 'auto' );
		if ( ! is_wp_error( $result ) ) {
			set_transient( 'scout_sync_last_auto', array( 'written' => $result['written'], 'time' => time() ), DAY_IN_SECONDS );
		}
		self::summary( true );
	}
}
