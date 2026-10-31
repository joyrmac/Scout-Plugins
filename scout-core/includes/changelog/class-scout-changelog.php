<?php
/**
 * Change log: who changed what on the site, when it wasn't Scout.
 *
 * When a client logs in and edits their own site, the repo that builds the site
 * has to hear about it, or the next theme update can undo their work. Site Sync
 * already notices a page edited in WordPress; this records who did it, when, and
 * what changed, for pages and much more (menus, photos, settings, plugins, forms).
 *
 * Decisions (Joy, 2026-10-27): entries stay until someone marks them as in the
 * repo, then move to an archive that is kept; only Scout sees the log; one weekly
 * email; first test on the Bliss Boogie staging site.
 * Spec: Scout-Media-Raleigh/content/design/change-log-spec.md.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Changelog {

	const DB_VERSION = '1';
	const DB_OPTION  = 'scout_change_log_db';
	const MENUS      = 'scout_change_log_menus';
	const LAST_MAIL  = 'scout_change_log_last_digest';
	const CRON       = 'scout_change_log_digest';
	const MERGE_SECS = 300;  // Saves by one person on one thing within five minutes are one entry.
	const MAX_TEXT   = 20000; // Before and after are each capped at this many characters.

	/** Options worth logging. Everything else is internal WordPress or plugin state. */
	const OPTIONS = array(
		'blogname'             => 'Site title',
		'blogdescription'      => 'Tagline',
		'show_on_front'        => 'Home page shows',
		'page_on_front'        => 'Home page',
		'page_for_posts'       => 'Posts page',
		'permalink_structure'  => 'Permalinks',
		'site_icon'            => 'Site icon',
		'blog_public'          => 'Search engine visibility',
		'admin_email'          => 'Admin email',
		'scout_business'       => 'Business info',
		'scout_seo_defaults'   => 'SEO settings',
		'scout_core_protection' => 'Spam protection',
	);

	/** Post types that are WordPress plumbing, never a person's edit. */
	const SKIP_TYPES = array( 'revision', 'customize_changeset', 'oembed_cache', 'user_request', 'nav_menu_item', 'attachment', 'wp_font_face', 'wp_font_family' );

	/** Pre-update values, keyed by "type:id:key", so the after-hook can log both sides. */
	private static $pending = array();

	/** Nesting count for pause()/resume(). */
	private static $paused = 0;

	/**
	 * Stop logging while code (not a person) writes to the site, such as a
	 * theme's one-time setup that runs when the client next opens the dashboard.
	 * Always pair with resume().
	 */
	public static function pause(): void {
		self::$paused++;
	}

	public static function resume(): void {
		self::$paused = max( 0, self::$paused - 1 );
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 2 );
		if ( ! self::enabled() ) {
			return;
		}

		add_action( 'post_updated', array( __CLASS__, 'post_updated' ), 20, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'post_deleted' ), 10, 2 );

		add_filter( 'update_post_metadata', array( __CLASS__, 'meta_before' ), 10, 5 );
		add_action( 'updated_post_meta', array( __CLASS__, 'meta_after' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'meta_after' ), 10, 4 );

		add_action( 'wp_update_nav_menu', array( __CLASS__, 'menu_saved' ), 20, 1 );

		add_action( 'add_attachment', array( __CLASS__, 'attachment_added' ) );
		add_action( 'attachment_updated', array( __CLASS__, 'attachment_updated' ), 10, 3 );
		add_action( 'delete_attachment', array( __CLASS__, 'attachment_deleted' ) );

		add_action( 'updated_option', array( __CLASS__, 'option_updated' ), 10, 3 );

		add_action( 'activated_plugin', array( __CLASS__, 'plugin_activated' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'plugin_deactivated' ) );
		add_action( 'deleted_plugin', array( __CLASS__, 'plugin_deleted' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'upgraded' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'theme_switched' ), 10, 3 );

		add_action( 'user_register', array( __CLASS__, 'user_added' ) );
		add_action( 'set_user_role', array( __CLASS__, 'role_changed' ), 10, 3 );

		add_action( 'customize_save_after', array( __CLASS__, 'customizer_saved' ) );
		add_action( 'gform_after_save_form', array( __CLASS__, 'form_saved' ), 10, 2 );

		add_action( self::CRON, array( __CLASS__, 'send_digest' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
	}

	/** Off switch: a filter in code, or the setting on the screen. */
	public static function enabled(): bool {
		return (bool) apply_filters( 'scout_change_log_enabled', 'off' !== get_option( 'scout_change_log_switch', 'on' ) );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'scout_change_log';
	}

	/* ---------- Storage ---------- */

	public static function maybe_install() {
		if ( self::DB_VERSION === get_option( self::DB_OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_label varchar(200) NOT NULL DEFAULT '',
			action varchar(40) NOT NULL DEFAULT '',
			object_type varchar(40) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_title varchar(255) NOT NULL DEFAULT '',
			summary text NOT NULL,
			before_val longtext NOT NULL,
			after_val longtext NOT NULL,
			revision_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(12) NOT NULL DEFAULT 'new',
			acked_at datetime NULL DEFAULT NULL,
			acked_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status_created (status, created_at),
			KEY object_lookup (object_type, object_id, user_id)
			) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Is this person Scout? Their edits already start in the repo, so they are
	 * not logged. Uses Scout Build's operator list when it is running.
	 */
	public static function is_scout( ?WP_User $user = null ): bool {
		$user = $user ? $user : wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		if ( class_exists( 'Scout_Hub_Access' ) ) {
			$scout = Scout_Hub_Access::is_operator( $user );
		} else {
			$raw   = defined( 'SCOUT_HUB_OPERATORS' ) ? (string) SCOUT_HUB_OPERATORS : 'sean@scoutraleigh.com, joy@scoutraleigh.com';
			$list  = array_filter( array_map( static function ( $e ) { return strtolower( trim( $e ) ); }, explode( ',', $raw ) ) );
			$scout = in_array( strtolower( (string) $user->user_email ), $list, true );
		}
		return (bool) apply_filters( 'scout_change_log_is_scout', $scout, $user );
	}

	/**
	 * Should a change happening right now be logged? Only when a real person who
	 * isn't Scout made it. Cron, WP-CLI and requests with nobody logged in (plugin
	 * auto-updates, Site Sync's own writes) and paused code are skipped.
	 */
	private static function should_log(): bool {
		if ( self::$paused || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return false;
		}
		// Site Sync writing the theme's copy is the repo arriving, not a person.
		if ( class_exists( 'Scout_Sync' ) && get_option( Scout_Sync::LOCK ) ) {
			return false;
		}
		if ( apply_filters( 'scout_change_log_skip', false ) ) {
			return false;
		}
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		return ! self::is_scout( $user );
	}

	private static function clip( $text ): string {
		$text = (string) $text;
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_TEXT ) : substr( $text, 0, self::MAX_TEXT );
	}

	/** Readable text from post content: blocks and tags out, one space between words. */
	public static function plain( $html ): string {
		$html = preg_replace( '/<!--.*?-->/s', ' ', (string) $html );
		$text = wp_strip_all_tags( (string) $html, true );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/** A value as text for the before and after boxes. */
	private static function text( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		return (string) $value;
	}

	/**
	 * Write one entry. A person saving the same thing again within a few minutes
	 * updates their open entry instead of adding another (the block editor saves
	 * more than once per click), keeping the first "before".
	 */
	public static function log( array $e ) {
		global $wpdb;
		$user = wp_get_current_user();
		$now  = current_time( 'mysql', true );
		$row  = array(
			'user_id'      => (int) ( $user->ID ?? 0 ),
			'user_label'   => $user && $user->exists() ? sprintf( '%s (%s)', $user->display_name, $user->user_email ) : 'System',
			'action'       => (string) ( $e['action'] ?? 'edited' ),
			'object_type'  => (string) ( $e['type'] ?? '' ),
			'object_id'    => (int) ( $e['id'] ?? 0 ),
			'object_title' => wp_html_excerpt( (string) ( $e['title'] ?? '' ), 250, '' ),
			'summary'      => (string) ( $e['summary'] ?? '' ),
			'before_val'   => self::clip( self::text( $e['before'] ?? '' ) ),
			'after_val'    => self::clip( self::text( $e['after'] ?? '' ) ),
			'revision_id'  => (int) ( $e['revision'] ?? 0 ),
		);
		$table = self::table();

		if ( empty( $e['no_merge'] ) && $row['object_id'] ) {
			$since = gmdate( 'Y-m-d H:i:s', time() - self::MERGE_SECS );
			$open  = $wpdb->get_row( $wpdb->prepare( "SELECT id, action FROM {$table} WHERE status = 'new' AND object_type = %s AND object_id = %d AND user_id = %d AND updated_at >= %s ORDER BY id DESC LIMIT 1", $row['object_type'], $row['object_id'], $row['user_id'], $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $open ) {
				$keep_action = in_array( $open->action, array( 'created', 'published' ), true ) ? $open->action : $row['action'];
				$wpdb->update(
					$table,
					array(
						'updated_at'   => $now,
						'action'       => $keep_action,
						'object_title' => $row['object_title'],
						'summary'      => $row['summary'],
						'after_val'    => $row['after_val'],
						'revision_id'  => $row['revision_id'],
					),
					array( 'id' => (int) $open->id )
				);
				return (int) $open->id;
			}
		}

		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		$row['status']     = 'new';
		$wpdb->insert( $table, $row );
		return (int) $wpdb->insert_id;
	}

	/* ---------- Pages, posts, templates ---------- */

	private static function type_label( $post_type ): string {
		$obj = get_post_type_object( $post_type );
		return $obj ? strtolower( (string) $obj->labels->singular_name ) : (string) $post_type;
	}

	private static function latest_revision( $post_id ): int {
		$revs = wp_get_post_revisions( $post_id, array( 'numberposts' => 1, 'fields' => 'ids' ) );
		return $revs ? (int) reset( $revs ) : 0;
	}

	public static function post_updated( $post_id, $after, $before ) {
		if ( ! self::should_log() || ! $after instanceof WP_Post || ! $before instanceof WP_Post ) {
			return;
		}
		if ( in_array( $after->post_type, self::SKIP_TYPES, true ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$was_draft = in_array( $before->post_status, array( 'auto-draft', 'new' ), true );
		if ( 'auto-draft' === $after->post_status ) {
			return;
		}

		$changed = array();
		if ( $before->post_title !== $after->post_title ) {
			$changed[] = 'title';
		}
		if ( $before->post_content !== $after->post_content ) {
			$changed[] = 'text';
		}
		if ( $before->post_excerpt !== $after->post_excerpt ) {
			$changed[] = 'excerpt';
		}
		if ( $before->post_status !== $after->post_status ) {
			$changed[] = 'status';
		}
		if ( ! $changed && ! $was_draft ) {
			return;
		}

		$kind  = self::type_label( $after->post_type );
		$title = '' !== $after->post_title ? $after->post_title : $after->post_name;
		if ( $was_draft ) {
			$action  = 'publish' === $after->post_status ? 'published' : 'created';
			$summary = sprintf( '%s new %s "%s"', 'published' === $action ? 'Published' : 'Created', $kind, $title );
		} elseif ( 'trash' === $after->post_status ) {
			$action  = 'trashed';
			$summary = sprintf( 'Moved %s "%s" to the trash', $kind, $title );
		} elseif ( 'trash' === $before->post_status ) {
			$action  = 'restored';
			$summary = sprintf( 'Restored %s "%s" from the trash', $kind, $title );
		} elseif ( 'publish' === $after->post_status && 'publish' !== $before->post_status ) {
			$action  = 'published';
			$summary = sprintf( 'Published %s "%s"', $kind, $title );
		} else {
			$action  = 'edited';
			$summary = sprintf( 'Edited %s "%s": %s changed', $kind, $title, implode( ', ', $changed ) );
		}

		$side = static function ( WP_Post $p ) {
			$out = 'Title: ' . $p->post_title . "\nStatus: " . $p->post_status;
			if ( '' !== $p->post_excerpt ) {
				$out .= "\nExcerpt: " . $p->post_excerpt;
			}
			$text = in_array( $p->post_type, array( 'wp_global_styles', 'wp_template', 'wp_template_part', 'wp_navigation' ), true ) ? $p->post_content : self::plain( $p->post_content );
			return $out . "\n\n" . $text;
		};

		self::log(
			array(
				'action'   => $action,
				'type'     => $after->post_type,
				'id'       => $post_id,
				'title'    => $title,
				'summary'  => $summary,
				'before'   => $was_draft ? '' : $side( $before ),
				'after'    => $side( $after ),
				'revision' => self::latest_revision( $post_id ),
			)
		);
	}

	public static function post_deleted( $post_id, $post = null ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );
		if ( ! $post || ! self::should_log() || in_array( $post->post_type, self::SKIP_TYPES, true ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		self::log(
			array(
				'action'   => 'deleted',
				'type'     => $post->post_type,
				'id'       => $post_id,
				'title'    => $post->post_title,
				'summary'  => sprintf( 'Deleted %s "%s" for good', self::type_label( $post->post_type ), $post->post_title ),
				'before'   => 'Title: ' . $post->post_title . "\n\n" . self::plain( $post->post_content ),
				'no_merge' => true,
			)
		);
	}

	/* ---------- Scout SEO fields and photo alt text (post meta) ---------- */

	private static function watched_meta( $key ): string {
		$keys = array(
			'_wp_attachment_image_alt' => 'Alt text',
		);
		foreach ( array( 'SCOUT_SEO_META_TITLE' => 'SEO title', 'SCOUT_SEO_META_DESC' => 'SEO description', 'SCOUT_SEO_META_CANONICAL' => 'Canonical address', 'SCOUT_SEO_META_NOINDEX' => 'Hidden from search' ) as $const => $label ) {
			if ( defined( $const ) ) {
				$keys[ constant( $const ) ] = $label;
			}
		}
		return $keys[ $key ] ?? '';
	}

	public static function meta_before( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		if ( '' !== self::watched_meta( $meta_key ) ) {
			self::$pending[ 'meta:' . $object_id . ':' . $meta_key ] = get_post_meta( $object_id, $meta_key, true );
		}
		return $check;
	}

	public static function meta_after( $meta_id, $object_id, $meta_key, $meta_value ) {
		$label = self::watched_meta( $meta_key );
		if ( '' === $label || ! self::should_log() ) {
			return;
		}
		$k      = 'meta:' . $object_id . ':' . $meta_key;
		$before = self::$pending[ $k ] ?? '';
		unset( self::$pending[ $k ] );
		if ( (string) $before === (string) $meta_value ) {
			return;
		}
		$post = get_post( $object_id );
		if ( ! $post ) {
			return;
		}
		self::log(
			array(
				'action'   => 'edited',
				'type'     => 'meta:' . $meta_key,
				'id'       => $object_id,
				'title'    => $post->post_title,
				'summary'  => sprintf( 'Changed the %s on %s "%s"', strtolower( $label ), self::type_label( $post->post_type ), $post->post_title ),
				'before'   => $before,
				'after'    => $meta_value,
			)
		);
	}

	/* ---------- Menus ---------- */

	public static function menu_items( $menu_id ): array {
		$items = wp_get_nav_menu_items( $menu_id, array( 'update_post_term_cache' => false ) );
		$out   = array();
		foreach ( (array) $items as $item ) {
			$depth = $item->menu_item_parent ? '  - ' : '- ';
			$out[] = $depth . $item->title . ( $item->url ? ' (' . $item->url . ')' : '' );
		}
		return $out;
	}

	public static function menu_saved( $menu_id ) {
		$menu  = wp_get_nav_menu_object( $menu_id );
		$after = self::menu_items( $menu_id );
		$all   = (array) get_option( self::MENUS, array() );
		$prev  = $all[ $menu_id ] ?? null;
		$all[ $menu_id ] = $after;
		update_option( self::MENUS, $all, false );
		if ( ! self::should_log() || ( null !== $prev && $prev === $after ) ) {
			return;
		}
		$name = $menu ? $menu->name : 'Menu ' . $menu_id;
		self::log(
			array(
				'action'  => 'edited',
				'type'    => 'nav_menu',
				'id'      => $menu_id,
				'title'   => $name,
				'summary' => sprintf( 'Changed the "%s" menu', $name ),
				'before'  => null === $prev ? '(not recorded before)' : implode( "\n", $prev ),
				'after'   => implode( "\n", $after ),
			)
		);
	}

	/* ---------- Photos and files ---------- */

	public static function attachment_added( $id ) {
		if ( ! self::should_log() ) {
			return;
		}
		$file = basename( (string) get_attached_file( $id ) );
		self::log(
			array(
				'action'   => 'uploaded',
				'type'     => 'attachment',
				'id'       => $id,
				'title'    => $file,
				'summary'  => sprintf( 'Uploaded %s', $file ),
				'after'    => wp_get_attachment_url( $id ),
			)
		);
	}

	public static function attachment_updated( $id, $after, $before ) {
		if ( ! self::should_log() || ! $after instanceof WP_Post || ! $before instanceof WP_Post ) {
			return;
		}
		if ( $before->post_title === $after->post_title && $before->post_excerpt === $after->post_excerpt && $before->post_content === $after->post_content ) {
			return;
		}
		$file = basename( (string) get_attached_file( $id ) );
		$side = static function ( WP_Post $p ) {
			return "Title: {$p->post_title}\nCaption: {$p->post_excerpt}\nDescription: {$p->post_content}";
		};
		self::log(
			array(
				'action'  => 'edited',
				'type'    => 'attachment',
				'id'      => $id,
				'title'   => $file,
				'summary' => sprintf( 'Edited the details of %s', $file ),
				'before'  => $side( $before ),
				'after'   => $side( $after ),
			)
		);
	}

	public static function attachment_deleted( $id ) {
		if ( ! self::should_log() ) {
			return;
		}
		$file = basename( (string) get_attached_file( $id ) );
		self::log(
			array(
				'action'   => 'deleted',
				'type'     => 'attachment',
				'id'       => $id,
				'title'    => $file,
				'summary'  => sprintf( 'Deleted %s from the media library', $file ),
				'before'   => wp_get_attachment_url( $id ),
				'no_merge' => true,
			)
		);
	}

	/* ---------- Settings ---------- */

	public static function option_updated( $option, $old, $new ) {
		$labels = (array) apply_filters( 'scout_change_log_options', self::OPTIONS );
		if ( ! isset( $labels[ $option ] ) || ! self::should_log() || $old === $new ) {
			return;
		}
		$show = static function ( $value ) use ( $option ) {
			if ( in_array( $option, array( 'page_on_front', 'page_for_posts' ), true ) && $value ) {
				return get_the_title( (int) $value ) . ' (#' . (int) $value . ')';
			}
			return $value;
		};
		self::log(
			array(
				'action'  => 'setting',
				'type'    => 'option',
				'id'      => 0,
				'title'   => $labels[ $option ],
				'summary' => sprintf( 'Changed the %s setting', strtolower( $labels[ $option ] ) ),
				'before'  => $show( $old ),
				'after'   => $show( $new ),
				'no_merge' => true,
			)
		);
	}

	/* ---------- Plugins, themes, updates ---------- */

	private static function plugin_name( $file ): string {
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( is_readable( $path ) && function_exists( 'get_plugin_data' ) ) {
			$data = get_plugin_data( $path, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return $data['Name'] . ( empty( $data['Version'] ) ? '' : ' ' . $data['Version'] );
			}
		}
		return (string) $file;
	}

	private static function plugin_event( $action, $verb, $file ) {
		if ( ! self::should_log() ) {
			return;
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$name = self::plugin_name( $file );
		self::log( array( 'action' => $action, 'type' => 'plugin', 'title' => $name, 'summary' => sprintf( '%s the plugin %s', $verb, $name ), 'no_merge' => true ) );
	}

	public static function plugin_activated( $file ) {
		self::plugin_event( 'activated', 'Turned on', $file );
	}

	public static function plugin_deactivated( $file ) {
		self::plugin_event( 'deactivated', 'Turned off', $file );
	}

	public static function plugin_deleted( $file, $deleted ) {
		if ( $deleted ) {
			self::plugin_event( 'deleted', 'Deleted', $file );
		}
	}

	public static function upgraded( $upgrader, $extra ) {
		if ( ! self::should_log() || empty( $extra['type'] ) ) {
			return;
		}
		$names = array();
		if ( 'plugin' === $extra['type'] ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$files = ! empty( $extra['plugins'] ) ? (array) $extra['plugins'] : ( ! empty( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );
			foreach ( $files as $f ) {
				$names[] = self::plugin_name( $f );
			}
		} elseif ( 'theme' === $extra['type'] ) {
			$slugs = ! empty( $extra['themes'] ) ? (array) $extra['themes'] : ( ! empty( $extra['theme'] ) ? array( $extra['theme'] ) : array() );
			foreach ( $slugs as $s ) {
				$t       = wp_get_theme( $s );
				$names[] = $t->exists() ? $t->get( 'Name' ) . ' ' . $t->get( 'Version' ) : $s;
			}
		} else {
			$names[] = 'WordPress';
		}
		$verb = ( 'install' === ( $extra['action'] ?? '' ) ) ? 'Installed' : 'Updated';
		self::log( array( 'action' => strtolower( $verb ), 'type' => (string) $extra['type'], 'title' => implode( ', ', $names ), 'summary' => sprintf( '%s %s %s', $verb, $extra['type'], implode( ', ', $names ) ), 'no_merge' => true ) );
	}

	public static function theme_switched( $new_name, $new_theme, $old_theme = null ) {
		if ( ! self::should_log() ) {
			return;
		}
		$old = $old_theme instanceof WP_Theme ? $old_theme->get( 'Name' ) : '';
		self::log( array( 'action' => 'switched', 'type' => 'theme', 'title' => $new_name, 'summary' => sprintf( 'Switched the theme to %s', $new_name ), 'before' => $old, 'after' => $new_name, 'no_merge' => true ) );
	}

	/* ---------- Users (never passwords) ---------- */

	public static function user_added( $user_id ) {
		if ( ! self::should_log() ) {
			return;
		}
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return;
		}
		self::log( array( 'action' => 'created', 'type' => 'user', 'title' => $u->display_name, 'summary' => sprintf( 'Added the user %s (%s) as %s', $u->display_name, $u->user_email, implode( ', ', $u->roles ) ), 'no_merge' => true ) );
	}

	public static function role_changed( $user_id, $role, $old_roles ) {
		if ( ! self::should_log() || doing_action( 'user_register' ) ) {
			return;
		}
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return;
		}
		self::log( array( 'action' => 'role', 'type' => 'user', 'title' => $u->display_name, 'summary' => sprintf( 'Changed %s\'s role to %s', $u->display_name, $role ), 'before' => implode( ', ', (array) $old_roles ), 'after' => $role, 'no_merge' => true ) );
	}

	/* ---------- Customizer and Gravity Forms ---------- */

	public static function customizer_saved( $manager ) {
		if ( ! self::should_log() || ! is_object( $manager ) || ! method_exists( $manager, 'unsanitized_post_values' ) ) {
			return;
		}
		$changed = array_keys( (array) $manager->unsanitized_post_values() );
		if ( ! $changed ) {
			return;
		}
		self::log( array( 'action' => 'setting', 'type' => 'customizer', 'title' => 'Customizer', 'summary' => 'Saved changes in the Customizer: ' . implode( ', ', array_slice( $changed, 0, 12 ) ), 'after' => implode( "\n", $changed ), 'no_merge' => true ) );
	}

	public static function form_saved( $form, $is_new ) {
		if ( ! self::should_log() || ! is_array( $form ) ) {
			return;
		}
		$title  = (string) ( $form['title'] ?? 'Form' );
		$fields = array();
		foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
			$fields[] = '- ' . ( is_object( $f ) ? $f->label : ( $f['label'] ?? '' ) );
		}
		self::log(
			array(
				'action'  => $is_new ? 'created' : 'edited',
				'type'    => 'gf_form',
				'id'      => (int) ( $form['id'] ?? 0 ),
				'title'   => $title,
				'summary' => sprintf( '%s the form "%s"', $is_new ? 'Created' : 'Edited', $title ),
				'after'   => "Fields:\n" . implode( "\n", $fields ),
			)
		);
	}

	/* ---------- Reading and marking ---------- */

	/** @return object[] */
	public static function entries( string $status = 'new', int $limit = 200 ): array {
		global $wpdb;
		$table = self::table();
		$order = 'new' === $status ? 'created_at ASC, id ASC' : 'acked_at DESC, id DESC';
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY {$order} LIMIT %d", $status, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function count_new(): int {
		global $wpdb;
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			return 0;
		}
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'new'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Move entries to the archive. Nothing is ever deleted. */
	public static function acknowledge( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$table = self::table();
		$in    = implode( ',', $ids );
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'archived', acked_at = %s, acked_by = %d WHERE status = 'new' AND id IN ({$in})", current_time( 'mysql', true ), get_current_user_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * The part of two texts that differs, with a little context, so a long page
	 * shows the changed sentence instead of the whole page.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function diff( string $a, string $b, int $context = 120 ): array {
		if ( $a === $b ) {
			return array( $a, $b );
		}
		$la = strlen( $a );
		$lb = strlen( $b );
		$p  = 0;
		while ( $p < $la && $p < $lb && $a[ $p ] === $b[ $p ] ) {
			$p++;
		}
		$s = 0;
		while ( $s < $la - $p && $s < $lb - $p && $a[ $la - 1 - $s ] === $b[ $lb - 1 - $s ] ) {
			$s++;
		}
		$start = max( 0, $p - $context );
		$cut   = static function ( string $t, int $end ) use ( $start, $context ) {
			$stop = min( strlen( $t ), $end + $context );
			$out  = substr( $t, $start, $stop - $start );
			$out  = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $out, 'UTF-8', 'UTF-8' ) : $out;
			return ( $start > 0 ? '…' : '' ) . $out . ( $stop < strlen( $t ) ? '…' : '' );
		};
		return array( $cut( $a, $la - $s ), $cut( $b, $lb - $s ) );
	}

	/** Plain text of entries, for the Copy for Claude button and the email. */
	public static function export( array $rows, bool $full = true ): string {
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$lines = array( sprintf( 'Change log for %s (%s): %d new %s, copied %s', $site, home_url( '/' ), count( $rows ), 1 === count( $rows ) ? 'entry' : 'entries', wp_date( 'Y-m-d H:i T' ) ), '' );
		foreach ( $rows as $i => $r ) {
			$when    = wp_date( 'Y-m-d H:i', strtotime( $r->created_at . ' UTC' ) );
			$lines[] = sprintf( '%d. %s · %s · %s', $i + 1, $when, $r->user_label, $r->summary );
			if ( $full && ( '' !== $r->before_val || '' !== $r->after_val ) ) {
				list( $b, $a ) = self::diff( (string) $r->before_val, (string) $r->after_val );
				if ( '' !== $b ) {
					$lines[] = '   Before: ' . str_replace( "\n", "\n           ", $b );
				}
				if ( '' !== $a ) {
					$lines[] = '   After:  ' . str_replace( "\n", "\n           ", $a );
				}
			}
			if ( $r->object_id && post_type_exists( $r->object_type ) ) {
				$lines[] = '   Where:  ' . get_permalink( (int) $r->object_id );
			}
		}
		return implode( "\n", $lines );
	}

	/* ---------- Weekly email ---------- */

	public static function schedule() {
		if ( wp_next_scheduled( self::CRON ) ) {
			return;
		}
		// Mondays at 8:00 site time.
		$tz   = wp_timezone();
		$next = new DateTimeImmutable( 'next monday 08:00', $tz );
		wp_schedule_event( $next->getTimestamp(), 'weekly', self::CRON );
	}

	public static function recipient(): string {
		$to = defined( 'SCOUT_HUB_REQUEST_EMAIL' ) ? (string) SCOUT_HUB_REQUEST_EMAIL : 'team@scoutraleigh.com';
		return (string) apply_filters( 'scout_change_log_email', $to );
	}

	/** Send the week's new entries. Nothing new since the last email, nothing sent. */
	public static function send_digest(): bool {
		$rows  = self::entries( 'new', 500 );
		$since = (string) get_option( self::LAST_MAIL, '' );
		$fresh = array_values( array_filter( $rows, static function ( $r ) use ( $since ) { return '' === $since || $r->created_at > $since; } ) );
		if ( ! $fresh ) {
			return false;
		}
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( '%s: %d %s on the site this week', $site, count( $fresh ), 1 === count( $fresh ) ? 'change' : 'changes' );
		$link    = admin_url( 'admin.php?page=scout&tab=changes' );
		$body    = self::export( $fresh, false ) . "\n\n" . sprintf( '%d %s waiting in all. Open the Change log: %s', count( $rows ), 1 === count( $rows ) ? 'entry is' : 'entries are', $link ) . "\n";
		$sent    = wp_mail( self::recipient(), $subject, $body );
		if ( $sent ) {
			update_option( self::LAST_MAIL, current_time( 'mysql', true ), false );
		}
		return (bool) $sent;
	}
}
