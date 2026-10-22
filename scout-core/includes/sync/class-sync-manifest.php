<?php
/**
 * Site Sync: the theme contract. Reads `scout-sync.json` from the active theme
 * and hands the engine a clean, validated list of pages, seeds, SEO rows, and
 * identity. Every source path is resolved inside the theme folder; anything
 * that points outside it, or does not exist, becomes an error row instead.
 *
 * Spec: docs/site-sync-spec.md ("The theme contract").
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Sync_Manifest {

	const FILE = 'scout-sync.json';

	/** Manifest format this engine reads. */
	const SYNC_VERSION = 1;

	/** @var array|null Per-request cache. */
	private static $loaded = null;

	/** Folder that holds the manifest: the child theme first, then the parent. */
	public static function root() {
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
			if ( is_readable( trailingslashit( $dir ) . self::FILE ) ) {
				return untrailingslashit( $dir );
			}
		}
		return '';
	}

	/** True when the active theme ships a manifest (full mode, not status-only). */
	public static function exists() {
		return '' !== self::root();
	}

	/** The theme's version header. A bump is what makes the copy "new". */
	public static function theme_version() {
		$theme = wp_get_theme();
		return (string) $theme->get( 'Version' );
	}

	/**
	 * The parsed, validated manifest.
	 *
	 * @return array{ok:bool,errors:string[],identity:array,pages:array,seeds:array,seo:string}
	 */
	public static function get() {
		if ( null !== self::$loaded ) {
			return self::$loaded;
		}
		$out = array(
			'ok'       => false,
			'errors'   => array(),
			'identity' => array(),
			'pages'    => array(),
			'seeds'    => array(),
			'seo'      => '',
		);
		$root = self::root();
		if ( '' === $root ) {
			return self::$loaded = $out;
		}

		$raw  = file_get_contents( $root . '/' . self::FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			$out['errors'][] = self::FILE . ' is not valid JSON: ' . json_last_error_msg() . '.';
			return self::$loaded = $out;
		}
		$version = isset( $data['sync_version'] ) ? (int) $data['sync_version'] : 0;
		if ( self::SYNC_VERSION !== $version ) {
			$out['errors'][] = sprintf( 'This Scout Core reads sync_version %d; the theme declares %d.', self::SYNC_VERSION, $version );
			return self::$loaded = $out;
		}

		// Identity: plain strings, image paths resolved like any other source.
		$identity = isset( $data['identity'] ) && is_array( $data['identity'] ) ? $data['identity'] : array();
		foreach ( array( 'title', 'tagline', 'posts_page' ) as $key ) {
			if ( isset( $identity[ $key ] ) && is_string( $identity[ $key ] ) ) {
				$out['identity'][ $key ] = $identity[ $key ];
			}
		}
		foreach ( array( 'logo', 'icon' ) as $key ) {
			if ( ! empty( $identity[ $key ] ) && is_string( $identity[ $key ] ) ) {
				$path = self::resolve( $identity[ $key ] );
				if ( '' === $path ) {
					$out['errors'][] = sprintf( 'Identity %s "%s" is missing or outside the theme.', $key, $identity[ $key ] );
				} else {
					$out['identity'][ $key ] = $path;
				}
			}
		}

		// Pages: slug (a path, so children work), title, source; optional template and front_page.
		$seen = array();
		foreach ( isset( $data['pages'] ) && is_array( $data['pages'] ) ? $data['pages'] : array() as $i => $row ) {
			$slug = is_array( $row ) && isset( $row['slug'] ) ? self::clean_slug( $row['slug'] ) : '';
			if ( '' === $slug ) {
				$out['errors'][] = sprintf( 'Page #%d has no usable slug.', $i + 1 );
				continue;
			}
			if ( isset( $seen[ $slug ] ) ) {
				$out['errors'][] = sprintf( 'Page "%s" is listed twice; the first entry wins.', $slug );
				continue;
			}
			$seen[ $slug ] = true;
			$source        = isset( $row['source'] ) && is_string( $row['source'] ) ? $row['source'] : '';
			$path          = '' === $source ? '' : self::resolve( $source );
			$out['pages'][ $slug ] = array(
				'slug'       => $slug,
				'title'      => isset( $row['title'] ) && is_string( $row['title'] ) ? $row['title'] : $slug,
				'template'   => isset( $row['template'] ) && is_string( $row['template'] ) ? sanitize_title( $row['template'] ) : '',
				'front_page' => ! empty( $row['front_page'] ),
				'source'     => $source,
				'path'       => $path,
			);
			if ( '' === $path ) {
				$out['errors'][] = sprintf( 'Page "%s": source "%s" is missing or outside the theme.', $slug, $source );
			}
		}

		// Seeds: one JSON file per post type.
		foreach ( isset( $data['seeds'] ) && is_array( $data['seeds'] ) ? $data['seeds'] : array() as $row ) {
			$type = is_array( $row ) && isset( $row['type'] ) ? sanitize_key( $row['type'] ) : '';
			$path = is_array( $row ) && isset( $row['source'] ) && is_string( $row['source'] ) ? self::resolve( $row['source'] ) : '';
			if ( '' === $type || '' === $path ) {
				$out['errors'][] = 'A seed entry needs a type and a source file inside the theme.';
				continue;
			}
			$out['seeds'][] = array( 'type' => $type, 'path' => $path, 'source' => $row['source'] );
		}

		// SEO: one JSON file, keyed by page slug.
		if ( isset( $data['seo']['source'] ) && is_string( $data['seo']['source'] ) ) {
			$out['seo'] = self::resolve( $data['seo']['source'] );
			if ( '' === $out['seo'] ) {
				$out['errors'][] = sprintf( 'SEO source "%s" is missing or outside the theme.', $data['seo']['source'] );
			}
		}

		$out['ok'] = true;
		return self::$loaded = $out;
	}

	/** Drop the per-request cache (after a theme switch, or in tests). */
	public static function flush() {
		self::$loaded = null;
	}

	/**
	 * Absolute path of a theme-relative source, or '' when it is missing or
	 * escapes the theme folder.
	 */
	public static function resolve( $relative ) {
		$root = self::root();
		if ( '' === $root || '' === $relative || false !== strpos( $relative, "\0" ) ) {
			return '';
		}
		$base = realpath( $root );
		$path = realpath( $root . '/' . ltrim( $relative, '/' ) );
		if ( false === $base || false === $path || ! is_file( $path ) ) {
			return '';
		}
		return 0 === strpos( $path, trailingslashit( $base ) ) ? $path : '';
	}

	/** Read a resolved source file. '' when unreadable. */
	public static function read( $path ) {
		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/** Read a resolved JSON source. Empty array when unreadable or invalid. */
	public static function read_json( $path ) {
		$data = json_decode( self::read( $path ), true );
		return is_array( $data ) ? $data : array();
	}

	/** "services/weddings" stays a path; each segment is sanitized on its own. */
	private static function clean_slug( $slug ) {
		if ( ! is_string( $slug ) ) {
			return '';
		}
		$parts = array_filter( array_map( 'sanitize_title', explode( '/', trim( $slug, '/' ) ) ), 'strlen' );
		return implode( '/', $parts );
	}
}
