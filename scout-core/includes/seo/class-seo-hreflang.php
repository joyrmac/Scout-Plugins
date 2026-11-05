<?php
/**
 * Language versions (hreflang, Site Standard gap 2). When a page has a copy in
 * another language, Google needs links between the two or it treats them as
 * duplicates or shows the wrong one.
 *
 * Stored per post, protected meta:
 *  - `_scout_hreflang`: array of language code => post ID, every other page in
 *    the group. Saved on every page in the group, so the links always run both
 *    ways, and taking a page out of the group takes it out everywhere.
 *  - `_scout_lang`: the page's own language as a BCP 47 tag (es, pt-BR). When
 *    unset, the site language (get_locale() as a tag) is used.
 *
 * Output, only on a singular page that has links:
 *  - <link rel="alternate" hreflang> for the page itself, each linked version
 *    that is published and not hidden from search, and x-default pointing at
 *    the version in the site language. Absolute canonical addresses only.
 *  - The <html lang> attribute on a page whose language is not the site's.
 * No links, no output: pages nobody has linked do not change.
 *
 * Stands down when a translation plugin (WPML, Polylang, TranslatePress,
 * Weglot) is active, since those print their own tags, or through the
 * `scout_core_hreflang_stand_down` filter.
 *
 * The sitemap is left alone: Core tunes WordPress's own sitemap, whose renderer
 * has no place for xhtml:link alternates, and the head tags are enough.
 *
 * @package Scout_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_SEO_Hreflang {

	const META      = '_scout_hreflang';
	const LANG_META = '_scout_lang';

	/** A simple BCP 47 tag: language, optional script, optional region (es, zh-Hant, pt-BR, es-419). */
	const PATTERN = '^[A-Za-z]{2,3}(-[A-Za-z]{4})?(-([A-Za-z]{2}|[0-9]{3}))?$';

	/** The common picks offered in the box. Any other code can be typed in. */
	const COMMON = array(
		'en' => 'English',
		'es' => 'Spanish',
		'fr' => 'French',
		'pt' => 'Portuguese',
		'zh' => 'Chinese',
		'vi' => 'Vietnamese',
		'ko' => 'Korean',
	);

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output' ), 3 );
		add_filter( 'language_attributes', array( __CLASS__, 'language_attributes' ), 20, 2 );
		add_filter( 'scout_core_dashboard_checks', array( __CLASS__, 'add_check' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'forget' ) );
	}

	/* ---------- Codes ---------- */

	/** A BCP 47 tag in its usual case (pt-BR, zh-Hant), or '' when it does not look like one. */
	public static function code( $raw ): string {
		$raw = str_replace( '_', '-', trim( (string) $raw ) );
		if ( '' === $raw || ! preg_match( '/' . self::PATTERN . '/', $raw ) ) {
			return '';
		}
		$parts = explode( '-', $raw );
		$out   = array( strtolower( array_shift( $parts ) ) );
		foreach ( $parts as $part ) {
			$out[] = 4 === strlen( $part ) ? ucfirst( strtolower( $part ) ) : strtoupper( $part );
		}
		return implode( '-', $out );
	}

	/** The site language as a tag: en_US becomes en-US. */
	public static function site_lang(): string {
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
		$parts  = explode( '_', $locale );
		$region = isset( $parts[1] ) && preg_match( '/^([A-Za-z]{2}|[0-9]{3})$/', $parts[1] ) ? '-' . $parts[1] : '';
		$tag    = self::code( $parts[0] . $region );
		return '' !== $tag ? $tag : 'en';
	}

	/** The first part of a tag, lowercase: pt-BR gives pt. */
	private static function primary( string $tag ): string {
		return strtolower( (string) strtok( $tag, '-' ) );
	}

	/** A post's own language: its saved tag, else the site language. */
	public static function lang( int $id ): string {
		$saved = self::code( get_post_meta( $id, self::LANG_META, true ) );
		return '' !== $saved ? $saved : self::site_lang();
	}

	/**
	 * The other versions a post links to.
	 *
	 * @return array<string,int> language => post ID
	 */
	public static function links( int $id ): array {
		$raw = get_post_meta( $id, self::META, true );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $lang => $pid ) {
			$lang = self::code( $lang );
			$pid  = (int) $pid;
			if ( '' !== $lang && $pid > 0 && $pid !== $id && ! isset( $out[ $lang ] ) ) {
				$out[ $lang ] = $pid;
			}
		}
		return $out;
	}

	/* ---------- Saving: always both ways ---------- */

	/**
	 * Save a post's language and its links, and write the same group onto every
	 * linked page. A page picked here that already had versions brings them
	 * along when their language is free. A page taken out of the group loses
	 * its links to the group too.
	 *
	 * @param int               $id     The post being saved.
	 * @param string            $own    Its language; blank keeps what it has.
	 * @param array<string,int> $picked language => post ID from the form.
	 * @return array<string,int> The group, language => post ID, the post included.
	 */
	public static function save_links( int $id, string $own, array $picked ): array {
		$own     = self::code( $own );
		$own     = '' !== $own ? $own : self::lang( $id );
		$before  = self::links( $id );
		$group   = array( $own => $id );
		$chosen  = array();
		foreach ( $picked as $lang => $pid ) {
			$lang = self::code( $lang );
			$pid  = (int) $pid;
			if ( '' === $lang || $pid <= 0 || $pid === $id || isset( $group[ $lang ] ) || in_array( $pid, $group, true ) || ! get_post( $pid ) ) {
				continue;
			}
			$group[ $lang ] = $pid;
			$chosen[]       = $pid;
		}
		// Versions this page dropped are not pulled back in through another page.
		$dropped = array_diff( array_values( $before ), $chosen );
		foreach ( $chosen as $pid ) {
			foreach ( self::links( $pid ) as $lang => $other ) {
				if ( ! isset( $group[ $lang ] ) && ! in_array( $other, $group, true ) && ! in_array( $other, $dropped, true ) && get_post( $other ) ) {
					$group[ $lang ] = $other;
				}
			}
		}

		$members = array_values( $group );
		$touched = array_values( $before );
		foreach ( $members as $member ) {
			$touched = array_merge( $touched, array_values( self::links( $member ) ) );
		}
		foreach ( array_diff( array_unique( $touched ), $members ) as $outside ) {
			$keep = array();
			foreach ( self::links( (int) $outside ) as $lang => $other ) {
				if ( ! in_array( $other, $members, true ) ) {
					$keep[ $lang ] = $other;
				}
			}
			self::write( (int) $outside, $keep );
			if ( ! $keep && self::lang( (int) $outside ) === self::site_lang() ) {
				delete_post_meta( (int) $outside, self::LANG_META );
			}
		}

		foreach ( $group as $lang => $member ) {
			$others = $group;
			unset( $others[ $lang ] );
			self::write( $member, $others );
			if ( $others || $lang !== self::site_lang() ) {
				update_post_meta( $member, self::LANG_META, $lang );
			} else {
				delete_post_meta( $member, self::LANG_META );
			}
		}
		return $group;
	}

	/** Save the links from the Scout SEO box. Called from Scout_SEO_Box::save(). */
	public static function save_from_form( int $post_id, array $in ): void {
		if ( empty( $in['hreflang_form'] ) || self::stand_down() ) {
			return;
		}
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return;
		}
		$picked = array();
		foreach ( (array) ( $in['hreflang'] ?? array() ) as $row ) {
			$row  = (array) $row;
			$lang = self::code( $row['lang'] ?? '' );
			$pid  = (int) ( $row['post'] ?? 0 );
			if ( '' !== $lang && $pid > 0 && ! isset( $picked[ $lang ] ) && current_user_can( 'edit_post', $pid ) ) {
				$picked[ $lang ] = $pid;
			}
		}
		self::save_links( $post_id, (string) ( $in['lang'] ?? '' ), $picked );
	}

	/** A deleted post leaves every group it was in. */
	public static function forget( $post_id ) {
		$post_id = (int) $post_id;
		$group   = array_values( self::links( $post_id ) );
		foreach ( $group as $other ) {
			$group = array_merge( $group, array_values( self::links( $other ) ) );
		}
		foreach ( array_diff( array_unique( $group ), array( $post_id ) ) as $other ) {
			$keep = array();
			foreach ( self::links( $other ) as $lang => $pid ) {
				if ( $pid !== $post_id ) {
					$keep[ $lang ] = $pid;
				}
			}
			self::write( $other, $keep );
		}
		delete_post_meta( $post_id, self::META );
	}

	private static function write( int $id, array $links ): void {
		if ( $links ) {
			ksort( $links );
			update_post_meta( $id, self::META, $links );
		} else {
			delete_post_meta( $id, self::META );
		}
	}

	/* ---------- Front end ---------- */

	/** A translation plugin prints its own language tags, so Scout steps aside. */
	public static function stand_down(): bool {
		$plugin = defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress', false )       // WPML.
			|| defined( 'POLYLANG_VERSION' ) || function_exists( 'pll_the_languages' )           // Polylang.
			|| defined( 'TRP_PLUGIN_VERSION' ) || class_exists( 'TRP_Translate_Press', false )   // TranslatePress.
			|| defined( 'WEGLOT_VERSION' );                                                       // Weglot.
		return (bool) apply_filters( 'scout_core_hreflang_stand_down', $plugin );
	}

	/** Published, not hidden from search, and not a thank-you page. */
	public static function indexable( int $id ): bool {
		if ( 'publish' !== get_post_status( $id ) || get_post_meta( $id, SCOUT_SEO_META_NOINDEX, true ) ) {
			return false;
		}
		if ( 'page' === get_post_type( $id ) && class_exists( 'Scout_SEO_Head' ) && in_array( $id, Scout_SEO_Head::thanks_pages(), true ) ) {
			return false;
		}
		return true;
	}

	/** The page's absolute address, or '' when its canonical points at another page. */
	public static function url( int $id ): string {
		$url = (string) get_permalink( $id );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		$override = (string) get_post_meta( $id, SCOUT_SEO_META_CANONICAL, true );
		if ( '' !== $override && rtrim( $override, '/' ) !== rtrim( $url, '/' ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * The hreflang tags for one post, or none.
	 *
	 * @return string[]
	 */
	public static function tags( int $id ): array {
		if ( self::stand_down() ) {
			return array();
		}
		$links = self::links( $id );
		if ( ! $links || ! self::indexable( $id ) ) {
			return array();
		}
		$urls = array();
		foreach ( array( self::lang( $id ) => $id ) + $links as $lang => $pid ) {
			$url = self::indexable( $pid ) ? self::url( $pid ) : '';
			if ( '' !== $url ) {
				$urls[ $lang ] = $url;
			}
		}
		if ( count( $urls ) < 2 || ! isset( $urls[ self::lang( $id ) ] ) ) {
			return array();
		}
		ksort( $urls );
		$out = array();
		foreach ( $urls as $lang => $url ) {
			$out[] = sprintf( '<link rel="alternate" hreflang="%s" href="%s" />', esc_attr( $lang ), esc_url( $url ) );
		}
		$default = self::default_lang( array_keys( $urls ) );
		if ( '' !== $default ) {
			$out[] = sprintf( '<link rel="alternate" hreflang="x-default" href="%s" />', esc_url( $urls[ $default ] ) );
		}
		return $out;
	}

	/**
	 * Which version is the default: the one in the site language exactly, else
	 * the one that shares its first part (en for en-US), else none.
	 *
	 * @param string[] $langs
	 */
	public static function default_lang( array $langs ): string {
		$site = self::site_lang();
		foreach ( $langs as $lang ) {
			if ( strtolower( $lang ) === strtolower( $site ) ) {
				return $lang;
			}
		}
		$primary = self::primary( $site );
		if ( in_array( $primary, $langs, true ) ) {
			return $primary;
		}
		foreach ( $langs as $lang ) {
			if ( self::primary( $lang ) === $primary ) {
				return $lang;
			}
		}
		return '';
	}

	public static function output() {
		if ( ! is_singular() ) {
			return;
		}
		$tags = self::tags( (int) get_queried_object_id() );
		if ( $tags ) {
			echo "\n" . implode( "\n", $tags ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- each tag is escaped in tags().
		}
	}

	/**
	 * <html lang> on a page whose own language is not the site's. A page in the
	 * site language, or one with no language saved, keeps WordPress's attribute.
	 */
	public static function language_attributes( $output, $doctype = 'html' ) {
		if ( is_admin() || ! is_singular() || self::stand_down() ) {
			return $output;
		}
		$lang = self::code( get_post_meta( (int) get_queried_object_id(), self::LANG_META, true ) );
		if ( '' === $lang || self::primary( $lang ) === self::primary( self::site_lang() ) ) {
			return $output;
		}
		$output = (string) $output;
		$attr   = 'lang="' . esc_attr( $lang ) . '"';
		if ( preg_match( '/\blang="[^"]*"/', $output ) ) {
			return (string) preg_replace( '/\blang="[^"]*"/', $attr, $output, 1 );
		}
		return trim( $output . ' ' . $attr );
	}

	/* ---------- Google checks ---------- */

	public static function add_check( $checks ) {
		$row = self::check();
		if ( $row ) {
			$checks[] = $row;
		}
		return $checks;
	}

	/**
	 * The "Language versions" row: shown only when some page has links.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function check() {
		if ( self::stand_down() ) {
			return null;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'meta_key'       => self::META,
			)
		);
		if ( ! $ids ) {
			return null;
		}
		$broken = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			foreach ( self::links( $id ) as $pid ) {
				if ( ! in_array( $id, self::links( $pid ), true ) || ! self::indexable( $pid ) ) {
					$broken[] = $id;
					break;
				}
			}
		}
		$count = count( $broken );
		return array(
			'label'        => 'Language versions',
			'status'       => $count ? 'warn' : 'good',
			'message'      => $count
				? sprintf(
					_n(
						'%d page links to a language version that is not live or does not link back. Open it and check Other language versions.',
						'%d pages link to a language version that is not live or does not link back. Open each and check Other language versions.',
						$count,
						'scout-core'
					),
					$count
				)
				: 'Each page in another language links back, and every version is live.',
			'items'        => array_slice( $broken, 0, 10 ),
			'action'       => '',
			'action_label' => '',
		);
	}

	/* ---------- The fields in the Scout SEO box ---------- */

	public static function render_fields( $post ) {
		$help = 'color:#646970;font-size:12px;';
		echo '<div style="border-top:1px solid #dcdcde;padding-top:12px;display:grid;gap:8px;">';
		echo '<p style="margin:0;font-weight:600;">Other language versions</p>';
		if ( self::stand_down() ) {
			echo '<p style="margin:0;' . esc_attr( $help ) . '">Your translation plugin links the language versions of this page.</p></div>';
			return;
		}
		$id    = (int) $post->ID;
		$saved = self::code( get_post_meta( $id, self::LANG_META, true ) );
		$links = self::links( $id );
		$rows  = array();
		foreach ( $links as $lang => $pid ) {
			$rows[] = array( $lang, $pid );
		}
		$rows[] = array( '', 0 );
		$rows[] = array( '', 0 );

		$choices = get_posts(
			array(
				'post_type'      => $post->post_type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 300,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'exclude'        => array( $id ),
				'no_found_rows'  => true,
			)
		);
		$options = array();
		foreach ( (array) $choices as $choice ) {
			$options[ (int) $choice->ID ] = $choice;
		}
		foreach ( $links as $pid ) {
			if ( ! isset( $options[ $pid ] ) && get_post( $pid ) ) {
				$options[ $pid ] = get_post( $pid );
			}
		}

		echo '<input type="hidden" name="scout_seo[hreflang_form]" value="1" />';
		echo '<datalist id="scout-hreflang-langs">';
		foreach ( self::COMMON as $code => $name ) {
			printf( '<option value="%s" label="%s"></option>', esc_attr( $code ), esc_attr( $name ) );
		}
		echo '</datalist>';
		$pattern = self::PATTERN;
		$title   = 'A language code, like es or pt-BR';

		printf(
			'<p style="margin:0;"><label for="scout-seo-lang">This page&rsquo;s language</label> <input type="text" id="scout-seo-lang" name="scout_seo[lang]" value="%s" placeholder="%s" list="scout-hreflang-langs" pattern="%s" title="%s" size="8" autocomplete="off" spellcheck="false" /></p>',
			esc_attr( $saved ),
			esc_attr( self::site_lang() ),
			esc_attr( $pattern ),
			esc_attr( $title )
		);
		foreach ( $rows as $i => $row ) {
			echo '<p style="margin:0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
			printf(
				'<input type="text" name="scout_seo[hreflang][%1$d][lang]" value="%2$s" aria-label="Language" placeholder="es" list="scout-hreflang-langs" pattern="%3$s" title="%4$s" size="8" autocomplete="off" spellcheck="false" />',
				(int) $i,
				esc_attr( $row[0] ),
				esc_attr( $pattern ),
				esc_attr( $title )
			);
			printf( '<select name="scout_seo[hreflang][%d][post]" aria-label="Page" style="max-width:100%%;">', (int) $i );
			echo '<option value="0">Choose a page</option>';
			foreach ( $options as $pid => $choice ) {
				$name = wp_strip_all_tags( get_the_title( $choice ) );
				$name = '' !== $name ? $name : sprintf( '#%d', $pid );
				if ( 'publish' !== $choice->post_status ) {
					$name .= ' (not published)';
				}
				printf( '<option value="%d"%s>%s</option>', (int) $pid, selected( (int) $row[1], (int) $pid, false ), esc_html( $name ) );
			}
			echo '</select></p>';
		}
		echo '<span style="' . esc_attr( $help ) . '">Link the same page in another language so Google shows each visitor the right one.</span>';
		echo '</div>';
	}
}
