<?php
/**
 * Site Sync: the freshness engine. Compares the copy bundled in the theme with
 * what each WordPress page holds, using content hashes and a stored base (the
 * hashes recorded the last time the engine wrote the page). The base is what
 * makes a three-way decision possible:
 *
 *   theme vs base | db vs base | state
 *   same          | same       | in sync
 *   changed       | same       | stale: safe to sync, WordPress never touched it
 *   same          | changed    | edited in WordPress: flow it back to the repo
 *   changed       | changed    | conflict: needs review, never auto-overwritten
 *
 * The standing rule: the engine never silently overwrites a hand edit. Every
 * write saves the previous body as a snapshot first, so one click undoes it.
 *
 * Spec: docs/site-sync-spec.md. Phase 1 is manual: nothing here runs on its own.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Sync_Engine {

	/** Post meta: hashes and version recorded at the last sync. */
	const META_BASE = '_scout_sync_base';

	/** Post meta: the body a sync replaced, for one-click undo. */
	const META_SNAPSHOT = '_scout_sync_snapshot';

	/** Option: theme source path => attachment ID, so logos are imported once. */
	const OPTION_MEDIA = 'scout_sync_media';

	/* States. "Safe" ones may be written without a person looking first. */
	const IN_SYNC        = 'in_sync';
	const MISSING        = 'missing';
	const STALE          = 'stale';
	const EDITED         = 'edited';
	const CONFLICT       = 'conflict';
	const UNMANAGED      = 'unmanaged';
	const UNMANAGED_SAME = 'unmanaged_same';
	const NO_SOURCE      = 'no_source';

	/** Content hash. Line endings and outer whitespace never count as a change. */
	public static function hash( $content ) {
		$content = str_replace( array( "\r\n", "\r" ), "\n", (string) $content );
		return hash( 'sha256', trim( $content ) );
	}

	/** The WordPress page behind a manifest slug (a path for child pages). */
	public static function find_page( $slug ) {
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		return $page instanceof WP_Post ? $page : null;
	}

	/**
	 * One row per manifest page: what the theme has, what WordPress has, and
	 * the state that decides what may happen next.
	 *
	 * @return array<string,array>
	 */
	public static function status() {
		$rows     = array();
		$manifest = Scout_Core_Sync_Manifest::get();
		foreach ( $manifest['pages'] as $slug => $entry ) {
			$rows[ $slug ] = self::page_status( $entry );
		}
		return $rows;
	}

	/** @param array $entry A manifest page entry. */
	public static function page_status( array $entry ) {
		$page   = self::find_page( $entry['slug'] );
		$source = Scout_Core_Sync_Manifest::read( $entry['path'] );
		$row    = array(
			'entry'      => $entry,
			'post_id'    => $page ? $page->ID : 0,
			'theme_hash' => '' === $entry['path'] ? '' : self::hash( $source ),
			'db_hash'    => $page ? self::hash( $page->post_content ) : '',
			'base'       => $page ? self::base( $page->ID ) : array(),
			'snapshot'   => $page ? (bool) get_post_meta( $page->ID, self::META_SNAPSHOT, true ) : false,
		);

		if ( '' === $entry['path'] ) {
			$row['state'] = self::NO_SOURCE;
		} elseif ( ! $page ) {
			$row['state'] = self::MISSING;
		} elseif ( empty( $row['base'] ) ) {
			$row['state'] = $row['db_hash'] === $row['theme_hash'] ? self::UNMANAGED_SAME : self::UNMANAGED;
		} elseif ( $row['db_hash'] === $row['theme_hash'] ) {
			// Identical text is in sync whatever the base says (the repo caught up with a WordPress edit).
			$row['state'] = self::IN_SYNC;
		} else {
			$theme_moved = $row['theme_hash'] !== $row['base']['theme'];
			$db_moved    = $row['db_hash'] !== $row['base']['db'];
			if ( $theme_moved && ( $db_moved || ! empty( $row['base']['kept'] ) ) ) {
				// A kept WordPress copy still differs from the theme, so new theme copy is never "safe" over it.
				$row['state'] = self::CONFLICT;
			} elseif ( $theme_moved ) {
				$row['state'] = self::STALE;
			} elseif ( $db_moved ) {
				$row['state'] = self::EDITED;
			} else {
				$row['state'] = self::IN_SYNC;
			}
		}
		return $row;
	}

	/** States the engine may act on without a person reviewing a diff. */
	public static function is_safe( $state ) {
		return in_array( $state, array( self::MISSING, self::STALE, self::UNMANAGED_SAME ), true );
	}

	/** States that need a person: shown as "needs review". */
	public static function needs_review( $state ) {
		return in_array( $state, array( self::EDITED, self::CONFLICT, self::UNMANAGED, self::NO_SOURCE ), true );
	}

	/** @return array{theme:string,db:string,version:string,at:int,kept?:bool}|array */
	public static function base( $post_id ) {
		$base = get_post_meta( $post_id, self::META_BASE, true );
		return is_array( $base ) && isset( $base['theme'], $base['db'] ) ? $base : array();
	}

	/* ---------- Page actions ---------- */

	/**
	 * Bring every safe page up to date: create missing pages, write stale ones,
	 * and adopt unmanaged pages that already match. Nothing that needs review
	 * is touched.
	 *
	 * @return array{created:int,synced:int,adopted:int,skipped:int,errors:string[]}
	 */
	public static function sync_safe() {
		$out = array( 'created' => 0, 'synced' => 0, 'adopted' => 0, 'skipped' => 0, 'errors' => array() );
		// Manifest order, so a parent page exists before its children.
		foreach ( Scout_Core_Sync_Manifest::get()['pages'] as $entry ) {
			$row = self::page_status( $entry );
			if ( self::MISSING === $row['state'] ) {
				$r = self::create_page( $entry );
				is_wp_error( $r ) ? $out['errors'][] = $r->get_error_message() : $out['created']++;
			} elseif ( self::STALE === $row['state'] ) {
				$r = self::write_page( $entry );
				is_wp_error( $r ) ? $out['errors'][] = $r->get_error_message() : $out['synced']++;
			} elseif ( self::UNMANAGED_SAME === $row['state'] ) {
				self::adopt( $entry );
				$out['adopted']++;
			} elseif ( self::IN_SYNC === $row['state'] && ( $row['base']['theme'] !== $row['theme_hash'] || $row['base']['db'] !== $row['db_hash'] ) ) {
				// Same text, old base: refresh the base so the next theme change compares against today.
				self::adopt( $entry );
			} elseif ( self::needs_review( $row['state'] ) ) {
				$out['skipped']++;
			}
		}
		return $out;
	}

	/**
	 * Write the theme copy over the WordPress page, after saving what it held.
	 * Used for stale pages, and for "take theme copy" on a reviewed page.
	 *
	 * @return int|WP_Error Post ID.
	 */
	public static function write_page( array $entry ) {
		$page = self::find_page( $entry['slug'] );
		if ( ! $page ) {
			return self::create_page( $entry );
		}
		$source = Scout_Core_Sync_Manifest::read( $entry['path'] );
		if ( '' === trim( $source ) ) {
			return new WP_Error( 'scout_sync_empty', sprintf( '"%s": the theme copy is empty, so the page was left alone.', $entry['slug'] ) );
		}
		update_post_meta(
			$page->ID,
			self::META_SNAPSHOT,
			array(
				'content' => $page->post_content,
				'at'      => time(),
				'version' => Scout_Core_Sync_Manifest::theme_version(),
			)
		);
		$r = wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_content' => wp_slash( $source ),
			),
			true
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		self::record_base( $page->ID, $source );
		return $page->ID;
	}

	/**
	 * Create a missing page from the theme copy.
	 *
	 * @return int|WP_Error Post ID.
	 */
	public static function create_page( array $entry ) {
		$source = Scout_Core_Sync_Manifest::read( $entry['path'] );
		if ( '' === $entry['path'] ) {
			return new WP_Error( 'scout_sync_no_source', sprintf( '"%s": the theme has no copy for this page.', $entry['slug'] ) );
		}
		$parent_id = 0;
		$parts     = explode( '/', $entry['slug'] );
		$name      = array_pop( $parts );
		if ( $parts ) {
			$parent = self::find_page( implode( '/', $parts ) );
			if ( ! $parent ) {
				return new WP_Error( 'scout_sync_parent', sprintf( '"%s": its parent page "%s" does not exist yet.', $entry['slug'], implode( '/', $parts ) ) );
			}
			$parent_id = $parent->ID;
		}
		$args = array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'post_title'     => $entry['title'],
			'post_name'      => $name,
			'post_parent'    => $parent_id,
			'post_content'   => wp_slash( $source ),
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		);
		// front-page is picked by the template hierarchy; it is never a page template.
		if ( '' !== $entry['template'] && 'front-page' !== $entry['template'] ) {
			$args['page_template'] = $entry['template'];
		}
		$id = wp_insert_post( $args, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::record_base( $id, $source );
		return $id;
	}

	/**
	 * Mark a page as managed without writing it. Used when the WordPress page
	 * already matches the theme, and for "keep WordPress copy" on a reviewed
	 * page: the current body becomes the agreed version until the next theme
	 * update moves the copy.
	 */
	public static function adopt( array $entry ) {
		$page = self::find_page( $entry['slug'] );
		if ( ! $page ) {
			return false;
		}
		$theme = self::hash( Scout_Core_Sync_Manifest::read( $entry['path'] ) );
		$db    = self::hash( $page->post_content );
		update_post_meta(
			$page->ID,
			self::META_BASE,
			array(
				'theme'   => $theme,
				'db'      => $db,
				'version' => Scout_Core_Sync_Manifest::theme_version(),
				'at'      => time(),
				// Kept on purpose while it differs from the theme: later theme copy needs review, never auto-sync.
				'kept'    => $theme !== $db,
			)
		);
		return true;
	}

	/**
	 * Put back the body the last sync replaced. The page then reads as edited
	 * in WordPress, which is the truth: it no longer matches the theme.
	 *
	 * @return int|WP_Error
	 */
	public static function undo( array $entry ) {
		$page = self::find_page( $entry['slug'] );
		$snap = $page ? get_post_meta( $page->ID, self::META_SNAPSHOT, true ) : null;
		if ( ! $page || ! is_array( $snap ) || ! isset( $snap['content'] ) ) {
			return new WP_Error( 'scout_sync_no_snapshot', sprintf( '"%s": there is no earlier version to put back.', $entry['slug'] ) );
		}
		$r = wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_content' => wp_slash( $snap['content'] ),
			),
			true
		);
		if ( ! is_wp_error( $r ) ) {
			delete_post_meta( $page->ID, self::META_SNAPSHOT );
		}
		return $r;
	}

	/**
	 * Record the base after a write. The db hash is read back from the saved
	 * post, so anything WordPress itself changes on save (filters, kses) is
	 * part of the base and never reads as a hand edit.
	 */
	private static function record_base( $post_id, $source ) {
		clean_post_cache( $post_id );
		$saved = get_post( $post_id );
		update_post_meta(
			$post_id,
			self::META_BASE,
			array(
				'theme'   => self::hash( $source ),
				'db'      => self::hash( $saved ? $saved->post_content : '' ),
				'version' => Scout_Core_Sync_Manifest::theme_version(),
				'at'      => time(),
			)
		);
	}

	/* ---------- Seeds ---------- */

	/**
	 * Seed records from the theme's JSON. Each row: title (required), content,
	 * excerpt, and fields (Scout field key => value). A row whose title is
	 * already used by that type, in any status, is skipped, so seeding is
	 * safe to run again and never touches what someone wrote in WordPress.
	 *
	 * @return array<int,array{type:string,source:string,exists:bool,total:int,present:int,added:int}>
	 */
	public static function seeds( $apply = false ) {
		$report = array();
		foreach ( Scout_Core_Sync_Manifest::get()['seeds'] as $seed ) {
			$rows = Scout_Core_Sync_Manifest::read_json( $seed['path'] );
			$line = array(
				'type'    => $seed['type'],
				'source'  => $seed['source'],
				'exists'  => post_type_exists( $seed['type'] ),
				'total'   => count( $rows ),
				'present' => 0,
				'added'   => 0,
			);
			if ( ! $line['exists'] ) {
				$report[] = $line;
				continue;
			}
			$have = array_flip(
				array_map(
					'strval',
					wp_list_pluck(
						get_posts(
							array(
								'post_type'      => $seed['type'],
								'post_status'    => 'any',
								'posts_per_page' => -1,
								'no_found_rows'  => true,
							)
						),
						'post_title'
					)
				)
			);
			foreach ( $rows as $row ) {
				$title = is_array( $row ) && isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';
				if ( '' === $title ) {
					continue;
				}
				if ( isset( $have[ $title ] ) ) {
					$line['present']++;
					continue;
				}
				if ( ! $apply ) {
					continue;
				}
				$id = wp_insert_post(
					array(
						'post_type'      => $seed['type'],
						'post_status'    => 'publish',
						'post_title'     => $title,
						'post_content'   => wp_slash( isset( $row['content'] ) ? (string) $row['content'] : '' ),
						'post_excerpt'   => wp_slash( isset( $row['excerpt'] ) ? (string) $row['excerpt'] : '' ),
						'comment_status' => 'closed',
						'ping_status'    => 'closed',
					),
					true
				);
				if ( is_wp_error( $id ) ) {
					continue;
				}
				foreach ( isset( $row['fields'] ) && is_array( $row['fields'] ) ? $row['fields'] : array() as $key => $value ) {
					if ( is_scalar( $value ) ) {
						update_post_meta( $id, scout_core_meta_key( $key ), wp_slash( (string) $value ) );
					}
				}
				$have[ $title ] = true;
				$line['added']++;
			}
			$report[] = $line;
		}
		return $report;
	}

	/* ---------- SEO ---------- */

	/**
	 * Fill blank Scout SEO titles and descriptions from the theme's JSON
	 * (slug => {title, description}). Only blanks: a description someone
	 * wrote in the Scout SEO box always wins.
	 *
	 * @return array{rows:int,blank:int,filled:int,missing_pages:string[]}
	 */
	public static function seo( $apply = false ) {
		$out  = array( 'rows' => 0, 'blank' => 0, 'filled' => 0, 'missing_pages' => array() );
		$path = Scout_Core_Sync_Manifest::get()['seo'];
		foreach ( Scout_Core_Sync_Manifest::read_json( $path ) as $slug => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out['rows']++;
			$page = self::find_page( (string) $slug );
			if ( ! $page ) {
				$out['missing_pages'][] = (string) $slug;
				continue;
			}
			foreach ( array( 'title' => SCOUT_SEO_META_TITLE, 'description' => SCOUT_SEO_META_DESC ) as $key => $meta ) {
				$value = isset( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : '';
				if ( '' === $value || '' !== trim( (string) get_post_meta( $page->ID, $meta, true ) ) ) {
					continue;
				}
				$out['blank']++;
				if ( $apply ) {
					update_post_meta( $page->ID, $meta, wp_slash( sanitize_text_field( $value ) ) );
					$out['filled']++;
				}
			}
		}
		return $out;
	}

	/* ---------- Identity ---------- */

	/**
	 * What applying the identity block would change: label => [now, theme].
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function identity_diff() {
		$id    = Scout_Core_Sync_Manifest::get()['identity'];
		$diff  = array();
		$front = self::front_entry();
		if ( isset( $id['title'] ) && get_option( 'blogname' ) !== $id['title'] ) {
			$diff['Site title'] = array( (string) get_option( 'blogname' ), $id['title'] );
		}
		if ( isset( $id['tagline'] ) && get_option( 'blogdescription' ) !== $id['tagline'] ) {
			$diff['Tagline'] = array( (string) get_option( 'blogdescription' ), $id['tagline'] );
		}
		if ( $front ) {
			$page = self::find_page( $front['slug'] );
			if ( ! $page || 'page' !== get_option( 'show_on_front' ) || (int) get_option( 'page_on_front' ) !== $page->ID ) {
				$diff['Front page'] = array( self::page_label( (int) get_option( 'page_on_front' ) ), $front['slug'] );
			}
		}
		if ( ! empty( $id['posts_page'] ) ) {
			$page = self::find_page( $id['posts_page'] );
			if ( ! $page || (int) get_option( 'page_for_posts' ) !== $page->ID ) {
				$diff['Posts page'] = array( self::page_label( (int) get_option( 'page_for_posts' ) ), $id['posts_page'] );
			}
		}
		if ( ! empty( $id['logo'] ) && ! get_theme_mod( 'custom_logo' ) ) {
			$diff['Logo'] = array( 'none', basename( $id['logo'] ) );
		}
		if ( ! empty( $id['icon'] ) && ! get_option( 'site_icon' ) ) {
			$diff['Site icon'] = array( 'none', basename( $id['icon'] ) );
		}
		return $diff;
	}

	/**
	 * Apply the identity block. Title and tagline are set to the theme's;
	 * front and posts pages point at the manifest pages once they exist; a
	 * logo or site icon is added only when the site has none.
	 *
	 * @return string[] Labels of what changed.
	 */
	public static function apply_identity() {
		$id      = Scout_Core_Sync_Manifest::get()['identity'];
		$changed = array();
		foreach ( self::identity_diff() as $label => $pair ) {
			switch ( $label ) {
				case 'Site title':
					update_option( 'blogname', $id['title'] );
					break;
				case 'Tagline':
					update_option( 'blogdescription', $id['tagline'] );
					break;
				case 'Front page':
					$page = self::find_page( self::front_entry()['slug'] );
					if ( ! $page ) {
						continue 2;
					}
					update_option( 'show_on_front', 'page' );
					update_option( 'page_on_front', $page->ID );
					break;
				case 'Posts page':
					$page = self::find_page( $id['posts_page'] );
					if ( ! $page ) {
						continue 2;
					}
					update_option( 'page_for_posts', $page->ID );
					break;
				case 'Logo':
					$att = self::import_media( $id['logo'] );
					if ( ! $att ) {
						continue 2;
					}
					set_theme_mod( 'custom_logo', $att );
					break;
				case 'Site icon':
					$att = self::import_media( $id['icon'] );
					if ( ! $att ) {
						continue 2;
					}
					update_option( 'site_icon', $att );
					break;
			}
			$changed[] = $label;
		}
		return $changed;
	}

	/** The manifest page flagged front_page, if any. */
	private static function front_entry() {
		foreach ( Scout_Core_Sync_Manifest::get()['pages'] as $entry ) {
			if ( $entry['front_page'] ) {
				return $entry;
			}
		}
		return null;
	}

	private static function page_label( $id ) {
		$post = $id ? get_post( $id ) : null;
		return $post ? $post->post_name : 'none';
	}

	/** Copy a theme image into the media library once; reuse it after that. */
	private static function import_media( $path ) {
		$known = (array) get_option( self::OPTION_MEDIA, array() );
		$key   = md5( $path . '|' . (string) @filesize( $path ) );
		if ( ! empty( $known[ $key ] ) && 'attachment' === get_post_type( (int) $known[ $key ] ) ) {
			return (int) $known[ $key ];
		}
		$type = wp_check_filetype( basename( $path ) );
		if ( empty( $type['type'] ) || 0 !== strpos( $type['type'], 'image/' ) ) {
			return 0;
		}
		$up = wp_upload_bits( basename( $path ), null, Scout_Core_Sync_Manifest::read( $path ) );
		if ( ! empty( $up['error'] ) ) {
			return 0;
		}
		$att = wp_insert_attachment(
			array(
				'post_mime_type' => $type['type'],
				'post_title'     => sanitize_file_name( pathinfo( $path, PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
			),
			$up['file']
		);
		if ( is_wp_error( $att ) || ! $att ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $up['file'] ) );
		$known[ $key ] = $att;
		update_option( self::OPTION_MEDIA, $known, false );
		return (int) $att;
	}
}
