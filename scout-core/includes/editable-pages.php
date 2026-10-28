<?php
/**
 * Editable pages: a designed page's words and photos are edited on the page
 * in the block editor, with the layout locked.
 *
 * The client's repo build (tools/editable-pages/build_blocks.py) turns each
 * approved static page into block content made of five Scout blocks, plus the
 * markup nobody edits (icons, decorative marks, scripts):
 *
 *   scout/box     a layout element. Locked: cannot be moved, deleted or restyled.
 *   scout/text    a run of words. Editable, with bold, italic and links.
 *   scout/image   a photo. Replaceable from the Media Library.
 *   scout/frozen  markup with no words: icons and decorative marks, kept in the theme.
 *   scout/part    a theme part (a form, a widget) the theme renders itself.
 *
 * A theme opts in:
 *
 *     add_theme_support( 'scout-editable-pages', array(
 *         'content_dir' => get_template_directory() . '/content',  // index.php + <page>.php
 *         'editor_css'  => array( get_template_directory_uri() . '/assets/css/editor.css' ),
 *     ) );
 *
 * and calls scout_core_render_editable() where the page body goes, falling
 * back to its own template markup when it returns false. Theme parts render
 * through the `scout_core_editable_part` filter.
 *
 * Setup fills each editable page once; a page nobody has edited follows new
 * theme versions, and an edited page is never overwritten.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Editable_Pages {

	const HASH_META = '_scout_editable_hash';
	const VERSION_OPTION = 'scout_editable_version';
	const MARK = '<!-- wp:scout/box';

	/** The theme's opt-in settings, or null when the theme has not opted in. */
	public static function config(): ?array {
		$support = get_theme_support( 'scout-editable-pages' );
		if ( ! $support ) {
			return null;
		}
		$args = is_array( $support ) && isset( $support[0] ) && is_array( $support[0] ) ? $support[0] : array();
		return wp_parse_args(
			$args,
			array(
				'content_dir'  => get_template_directory() . '/content',
				'editor_css'   => array(),
				'review_class' => 'confirm',
			)
		);
	}

	/** Pages with editable content: page path => content key. */
	public static function pages(): array {
		static $pages = null;
		if ( null === $pages ) {
			$cfg   = self::config();
			$file  = $cfg ? trailingslashit( $cfg['content_dir'] ) . 'index.php' : '';
			$pages = ( $file && is_readable( $file ) ) ? (array) include $file : array();
		}
		return $pages;
	}

	/**
	 * One page's shipped content: "blocks" (the block markup) and "frozen"
	 * (markup nobody edits). Stored as PHP so the files print nothing if opened
	 * in a browser; the blocks can carry editor-only review notes.
	 */
	public static function data( string $key ): array {
		static $cache = array();
		if ( ! isset( $cache[ $key ] ) ) {
			$cfg           = self::config();
			$file          = $cfg ? trailingslashit( $cfg['content_dir'] ) . sanitize_key( $key ) . '.php' : '';
			$cache[ $key ] = ( $file && is_readable( $file ) ) ? (array) include $file : array();
		}
		return $cache[ $key ];
	}

	public static function frozen( string $ref ): string {
		$key = (string) strstr( $ref, ':', true );
		return (string) ( self::data( $key )['frozen'][ $ref ] ?? '' );
	}

	/** Stored content keeps {{home}} and {{theme}} so it moves between staging and production. */
	public static function tokens( string $html ): string {
		return str_replace( array( '{{home}}', '{{theme}}' ), array( untrailingslashit( home_url() ), get_template_directory_uri() ), $html );
	}

	/** Review notes in the approved copy print for people who can edit pages only. */
	public static function review_notes( string $html ): string {
		$cfg = self::config();
		if ( ! $cfg || current_user_can( 'edit_pages' ) ) {
			return $html;
		}
		return (string) preg_replace( '#<span class="' . preg_quote( (string) $cfg['review_class'], '#' ) . '">.*?</span>#s', '', $html );
	}

	/* ---- Rendering ---- */

	private static function tag( array $attrs ): string {
		$tag = strtolower( (string) ( $attrs['tag'] ?? '' ) );
		return preg_match( '/^[a-z][a-z0-9-]*$/', $tag ) && ! in_array( $tag, array( 'script', 'style', 'iframe', 'object', 'embed' ), true ) ? $tag : '';
	}

	/** The approved element's attributes, as stored; event handlers stripped as a second line of defense. */
	private static function attrs( array $attrs ): string {
		return (string) preg_replace( '/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) ( $attrs['attrs'] ?? '' ) );
	}

	public static function render_box( array $attrs, string $content, WP_Block $block ): string {
		$inside = '';
		foreach ( $block->inner_blocks as $child ) {
			$inside .= (string) ( $child->attributes['ws'] ?? '' ) . $child->render();
		}
		$tag = self::tag( $attrs );
		return '' === $tag ? $inside : '<' . $tag . self::attrs( $attrs ) . '>' . $inside . "\n</" . $tag . '>';
	}

	public static function render_text( array $attrs ): string {
		$words = (string) ( $attrs['content'] ?? '' );
		$tag   = self::tag( $attrs );
		return '' === $tag ? $words : '<' . $tag . self::attrs( $attrs ) . '>' . $words . '</' . $tag . '>';
	}

	public static function render_frozen( array $attrs ): string {
		return self::frozen( (string) ( $attrs['ref'] ?? '' ) );
	}

	/**
	 * A photo: the approved image until an editor picks a new one, then that
	 * Media Library image with WordPress's responsive sizes, keeping the
	 * approved image's class, sizes and loading behavior.
	 */
	public static function render_image( array $attrs ): string {
		$orig = self::frozen( (string) ( $attrs['ref'] ?? '' ) );
		$id   = (int) ( $attrs['id'] ?? 0 );
		$alt  = isset( $attrs['alt'] ) ? (string) $attrs['alt'] : null;
		if ( $id && wp_attachment_is_image( $id ) ) {
			$img = array();
			if ( preg_match( '/<img\b[^>]*>/i', $orig, $m ) ) {
				foreach ( array( 'class', 'loading', 'fetchpriority', 'decoding', 'sizes' ) as $name ) {
					if ( preg_match( '/\s' . $name . '="([^"]*)"/i', $m[0], $v ) ) {
						$img[ $name ] = $v[1];
					}
				}
				if ( null === $alt && preg_match( '/\salt="([^"]*)"/i', $m[0], $v ) ) {
					$alt = html_entity_decode( $v[1], ENT_QUOTES );
				}
			}
			$img['alt'] = (string) $alt;
			$img       += array( 'loading' => false, 'fetchpriority' => 'auto' );
			return wp_get_attachment_image( $id, 'full', false, $img );
		}
		if ( null !== $alt ) {
			$orig = (string) preg_replace( '/(<img\b[^>]*\salt=")[^"]*(")/i', '${1}' . esc_attr( $alt ) . '${2}', $orig, 1 );
		}
		return $orig;
	}

	/** A theme part (form, widget): the theme answers the `scout_core_editable_part` filter. */
	public static function render_part( array $attrs ): string {
		$name = sanitize_key( (string) ( $attrs['name'] ?? '' ) );
		return (string) apply_filters( 'scout_core_editable_part', '', $name, $attrs );
	}

	public static function register_blocks(): void {
		if ( ! self::config() ) {
			return;
		}
		wp_register_script(
			'scout-editable-pages',
			plugins_url( 'assets/editable-pages.js', SCOUT_CORE_FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-plugins', 'wp-editor', 'wp-core-data' ),
			SCOUT_CORE_VERSION,
			true
		);
		$ws     = array( 'type' => 'string', 'default' => '' );
		$common = array(
			'api_version'   => 3,
			'editor_script' => 'scout-editable-pages',
			'supports'      => array( 'html' => false, 'reusable' => false, 'inserter' => false, 'customClassName' => false, 'className' => false ),
		);
		register_block_type(
			'scout/box',
			$common + array(
				'attributes'        => array(
					'tag'          => array( 'type' => 'string', 'default' => '' ),
					'attrs'        => array( 'type' => 'string', 'default' => '' ),
					'root'         => array( 'type' => 'boolean', 'default' => false ),
					'bodyclass'    => array( 'type' => 'string', 'default' => '' ),
					'templateLock' => array( 'type' => array( 'string', 'boolean' ), 'default' => false ),
					'lock'         => array( 'type' => 'object' ),
					'ws'           => $ws,
				),
				'skip_inner_blocks' => true,
				'render_callback'   => array( __CLASS__, 'render_box' ),
			)
		);
		register_block_type(
			'scout/text',
			$common + array(
				'attributes'      => array(
					'tag'     => array( 'type' => 'string', 'default' => '' ),
					'attrs'   => array( 'type' => 'string', 'default' => '' ),
					'content' => array( 'type' => 'string', 'default' => '', 'role' => 'content' ),
					'ws'      => $ws,
				),
				'render_callback' => array( __CLASS__, 'render_text' ),
			)
		);
		register_block_type(
			'scout/image',
			$common + array(
				'attributes'      => array(
					'ref' => array( 'type' => 'string', 'default' => '' ),
					'id'  => array( 'type' => 'number', 'role' => 'content' ),
					'alt' => array( 'type' => 'string', 'role' => 'content' ),
					'ws'  => $ws,
				),
				'render_callback' => array( __CLASS__, 'render_image' ),
			)
		);
		register_block_type(
			'scout/frozen',
			$common + array(
				'attributes'      => array(
					'ref' => array( 'type' => 'string', 'default' => '' ),
					'ws'  => $ws,
				),
				'render_callback' => array( __CLASS__, 'render_frozen' ),
			)
		);
		register_block_type(
			'scout/part',
			$common + array(
				'attributes'      => array(
					'name'  => array( 'type' => 'string', 'default' => '' ),
					'label' => array( 'type' => 'string', 'default' => '' ),
					'ws'    => $ws,
				),
				'render_callback' => array( __CLASS__, 'render_part' ),
			)
		);
	}

	/** The current page's rendered editable content (review notes still in), or null. */
	public static function html(): ?string {
		static $html = false;
		if ( false === $html ) {
			$post = is_singular() ? get_post( get_queried_object_id() ) : null;
			$html = ( self::config() && $post && false !== strpos( (string) $post->post_content, self::MARK ) )
				? self::tokens( do_blocks( (string) $post->post_content ) )
				: null;
		}
		return $html;
	}

	/* ---- Import ---- */

	public static function shipped( string $key ): string {
		return (string) ( self::data( $key )['blocks'] ?? '' );
	}

	public static function page_for( string $path ): ?WP_Post {
		if ( '/' === $path ) {
			$id = (int) get_option( 'page_on_front' );
			return $id ? get_post( $id ) : null;
		}
		return get_page_by_path( trim( $path, '/' ) );
	}

	/**
	 * Fill each editable page from the theme. An empty page is filled; a page
	 * nobody has edited since the last import (its content still matches the
	 * stored hash) is refreshed; an edited page is kept.
	 */
	public static function import(): void {
		foreach ( self::pages() as $path => $key ) {
			$page   = self::page_for( (string) $path );
			$blocks = self::shipped( (string) $key );
			if ( ! $page || '' === $blocks ) {
				continue;
			}
			$current   = (string) $page->post_content;
			$stored    = (string) get_post_meta( $page->ID, self::HASH_META, true );
			$untouched = '' === trim( $current ) || ( $stored && md5( $current ) === $stored );
			if ( ! $untouched || $current === $blocks ) {
				continue;
			}
			$saved = wp_update_post( array( 'ID' => $page->ID, 'post_content' => wp_slash( $blocks ) ), true );
			if ( ! is_wp_error( $saved ) ) {
				update_post_meta( $page->ID, self::HASH_META, md5( (string) get_post( $page->ID )->post_content ) );
			}
		}
		update_option( self::VERSION_OPTION, self::version_key() );
	}

	private static function version_key(): string {
		return SCOUT_CORE_VERSION . '|' . get_template() . '|' . (string) wp_get_theme( get_template() )->get( 'Version' );
	}

	/** A new theme or Scout Core version refreshes untouched pages on the next admin visit. */
	public static function maybe_import(): void {
		if ( self::config() && current_user_can( 'edit_pages' ) && get_option( self::VERSION_OPTION ) !== self::version_key() ) {
			self::import();
		}
	}

	/* ---- Editor ---- */

	/**
	 * Clients and editors get the content-only lock on the whole editor, which
	 * has no unlock button: words and photos only. Administrators keep
	 * WordPress's "Edit pattern" button to move or remove sections.
	 */
	public static function editor_settings( array $settings, $context ): array {
		$post = $context instanceof WP_Block_Editor_Context ? $context->post : null;
		if ( self::config() && $post && false !== strpos( (string) $post->post_content, self::MARK ) && ! current_user_can( 'manage_options' ) ) {
			$settings['templateLock'] = 'contentOnly';
		}
		return $settings;
	}

	/** The site's own styles inside the editor (the theme's scoped editor stylesheet). */
	public static function editor_styles(): void {
		$cfg = self::config();
		if ( ! $cfg || ! is_admin() ) {
			return;
		}
		foreach ( array_values( (array) $cfg['editor_css'] ) as $i => $url ) {
			wp_enqueue_style( 'scout-editable-' . $i, $url, array(), (string) wp_get_theme( get_template() )->get( 'Version' ) );
		}
	}

	/** Frozen markup for the editor's previews. */
	public static function editor_data(): void {
		if ( ! self::config() ) {
			return;
		}
		$post = get_post();
		$key  = '';
		if ( $post ) {
			$path = ( (int) get_option( 'page_on_front' ) === (int) $post->ID ) ? '/' : '/' . trim( (string) get_page_uri( $post ), '/' ) . '/';
			$key  = (string) ( self::pages()[ $path ] ?? '' );
		}
		$frozen = array();
		if ( $key ) {
			foreach ( (array) ( self::data( $key )['frozen'] ?? array() ) as $ref => $html ) {
				$frozen[ $ref ] = self::tokens( (string) $html );
			}
		}
		wp_add_inline_script(
			'scout-editable-pages',
			'window.scoutEditable = ' . wp_json_encode(
				array(
					'frozen' => $frozen,
					'home'   => untrailingslashit( home_url() ),
					'theme'  => get_template_directory_uri(),
				)
			) . ';',
			'before'
		);
	}

	/* ---- Schema ---- */

	/**
	 * On an editable page, FAQPage structured data comes from the questions
	 * people actually see (<details><summary>), so an edit never leaves the
	 * search markup behind.
	 */
	public static function faq_entities(): array {
		$html = self::html();
		if ( null === $html ) {
			return array();
		}
		$cfg  = self::config();
		$html = (string) preg_replace( '#<span class="' . preg_quote( (string) $cfg['review_class'], '#' ) . '">.*?</span>#s', '', $html );
		preg_match_all( '#<details\b[^>]*>\s*<summary\b[^>]*>(.*?)</summary>(.*?)</details>#s', $html, $m, PREG_SET_ORDER );
		$plain = static function ( string $s ): string {
			return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		};
		$out = array();
		foreach ( $m as $qa ) {
			$out[] = array(
				'@type'          => 'Question',
				'name'           => $plain( $qa[1] ),
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $plain( $qa[2] ) ),
			);
		}
		return $out;
	}

	/** Scout Core's own graph: replace or add the FAQPage node on an editable page. */
	public static function schema_graph( $graph ) {
		$faq = self::faq_entities();
		if ( ! $faq || ! is_array( $graph ) ) {
			return $graph;
		}
		foreach ( $graph as $k => $node ) {
			if ( is_array( $node ) && 'FAQPage' === ( $node['@type'] ?? '' ) ) {
				$graph[ $k ]['mainEntity'] = $faq;
				return $graph;
			}
		}
		$graph['faq'] = array(
			'@type'      => 'FAQPage',
			'@id'        => get_permalink() . '#faq',
			'mainEntity' => $faq,
		);
		return $graph;
	}

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_import' ) );
		add_action( 'after_switch_theme', array( __CLASS__, 'import' ), 20 );
		add_filter( 'block_editor_settings_all', array( __CLASS__, 'editor_settings' ), 10, 2 );
		add_action( 'enqueue_block_assets', array( __CLASS__, 'editor_styles' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_data' ) );
		add_filter( 'scout_schema_graph', array( __CLASS__, 'schema_graph' ) );
	}
}

/**
 * Print the current page's editable content, if it has any. Themes call this
 * where the page body goes and fall back to their own markup when it returns
 * false (before the content has been imported).
 */
function scout_core_render_editable(): bool {
	$html = Scout_Core_Editable_Pages::html();
	if ( null === $html ) {
		return false;
	}
	echo Scout_Core_Editable_Pages::review_notes( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks.
	return true;
}

/** Fill or refresh the editable pages now (theme setup routines call this). */
function scout_core_import_editable(): void {
	Scout_Core_Editable_Pages::import();
}

/** FAQ entities from the current editable page, for themes that print their own schema. */
function scout_core_editable_faq(): array {
	return Scout_Core_Editable_Pages::faq_entities();
}

Scout_Core_Editable_Pages::boot();
