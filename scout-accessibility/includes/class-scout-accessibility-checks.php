<?php
/**
 * The weekly checks (WP-Cron) and the at-launch checklist.
 *
 * Weekly, over published pages and posts: images with no alt attribute,
 * skipped heading levels in the content, form fields with no label in the
 * rendered content, and whether the statement page exists and is linked.
 * Color contrast and keyboard use need a real browser, so they live on the
 * at-launch checklist a person ticks off by hand.
 *
 * @package Scout_Accessibility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Accessibility_Checks {

	const CRON    = 'scout_accessibility_weekly';
	const RESULTS = 'scout_accessibility_results';
	const LAUNCH  = 'scout_accessibility_launch';

	public static function boot(): void {
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		// Re-schedule after an update or a restore that lost the event.
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/** @return array<string,array<string,string>> key => title, how */
	public static function launch_items(): array {
		return array(
			'contrast' => array(
				'title' => __( 'Color contrast', 'scout-accessibility' ),
				'how'   => __( 'Text and buttons read clearly against their background: at least 4.5 to 1 for normal text and 3 to 1 for large text and icons. Check each page template with a contrast checker in the browser, including hover and focus colors.', 'scout-accessibility' ),
			),
			'keyboard' => array(
				'title' => __( 'Keyboard use', 'scout-accessibility' ),
				'how'   => __( 'Tab through each page template without a mouse. Every link, button, menu and form field can be reached and used, the focus ring is easy to see, and nothing traps the keyboard.', 'scout-accessibility' ),
			),
		);
	}

	/** @return array<string,array{done:int,date:string,name:string}> */
	public static function launch(): array {
		$saved = (array) get_option( self::LAUNCH, array() );
		$out   = array();
		foreach ( array_keys( self::launch_items() ) as $key ) {
			$row         = (array) ( $saved[ $key ] ?? array() );
			$out[ $key ] = array(
				'done' => empty( $row['done'] ) ? 0 : 1,
				'date' => (string) ( $row['date'] ?? '' ),
				'name' => (string) ( $row['name'] ?? '' ),
			);
		}
		return $out;
	}

	/** @return array<string,mixed> The last results, or an empty array. */
	public static function results(): array {
		return (array) get_option( self::RESULTS, array() );
	}

	/** Post types the weekly checks read. */
	private static function post_types(): array {
		return (array) apply_filters( 'scout_accessibility_post_types', array( 'page', 'post' ) );
	}

	/** Run every weekly check and store the results. */
	public static function run(): array {
		$ids = get_posts(
			array(
				'post_type'        => self::post_types(),
				'post_status'      => 'publish',
				'posts_per_page'   => (int) apply_filters( 'scout_accessibility_max_posts', 300 ),
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		$images   = array();
		$headings = array();
		$forms    = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$html = self::render( $post );

			$missing = self::images_without_alt( $html );
			$thumb   = (int) get_post_thumbnail_id( $post );
			if ( $thumb && '' === trim( (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) ) ) {
				++$missing['featured'];
			}
			if ( $missing['content'] || $missing['featured'] ) {
				$images[] = array(
					'id'       => $id,
					'count'    => $missing['content'],
					'featured' => $missing['featured'],
				);
			}

			$skips = self::heading_skips( $html, self::template_prints_title( $post ) );
			if ( $skips ) {
				$headings[] = array(
					'id'    => $id,
					'skips' => $skips,
				);
			}

			$unlabeled = self::fields_without_labels( $html );
			if ( $unlabeled ) {
				$forms[] = array(
					'id'     => $id,
					'fields' => $unlabeled,
				);
			}
		}

		$results = array(
			'time'      => time(),
			'scanned'   => count( $ids ),
			'images'    => $images,
			'headings'  => $headings,
			'forms'     => $forms,
			'statement' => self::statement_check(),
		);
		update_option( self::RESULTS, $results, false );
		return $results;
	}

	/** The post's content as visitors get it, so shortcodes and blocks (forms included) are rendered. */
	private static function render( WP_Post $post ): string {
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
		setup_postdata( $post );
		$html = (string) apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		wp_reset_postdata();
		return $html;
	}

	/** Parse an HTML fragment. Null when there is nothing to read. */
	private static function dom( string $html ): ?DOMDocument {
		if ( '' === trim( $html ) ) {
			return null;
		}
		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?><div id="scout-a11y-root">' . $html . '</div>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $dom;
	}

	/**
	 * Images with no alt attribute at all. An empty alt is a deliberate
	 * "decorative" choice and passes.
	 *
	 * @return array{content:int,featured:int}
	 */
	public static function images_without_alt( string $html ): array {
		$count = 0;
		$dom   = self::dom( $html );
		if ( $dom ) {
			foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
				if ( ! $img->hasAttribute( 'alt' ) && 'presentation' !== $img->getAttribute( 'role' ) && 'true' !== $img->getAttribute( 'aria-hidden' ) ) {
					++$count;
				}
			}
		}
		return array(
			'content'  => $count,
			'featured' => 0,
		);
	}

	/**
	 * Does the theme print the page title as the H1 above the content?
	 * Classic themes do. A block theme does only when its template for this
	 * page has a level-1 title block or its own H1; many custom block themes
	 * put the H1 in the page content instead.
	 */
	public static function template_prints_title( WP_Post $post ): bool {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() || ! function_exists( 'get_block_template' ) ) {
			return true;
		}
		$custom = (string) get_page_template_slug( $post );
		$slugs  = 'page' === $post->post_type
			? array( $custom, 'page-' . $post->post_name, 'page-' . $post->ID, 'page', 'singular', 'index' )
			: array( $custom, 'single-' . $post->post_type . '-' . $post->post_name, 'single-' . $post->post_type, 'single', 'singular', 'index' );
		foreach ( array_filter( $slugs ) as $slug ) {
			$template = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template' );
			if ( $template && '' !== (string) $template->content ) {
				return (bool) preg_match( '/<h1\b|wp:post-title\s+\{[^}]*"level":\s*1\b/i', (string) $template->content );
			}
		}
		return true;
	}

	/**
	 * Heading levels that skip a step. When the theme prints the page title
	 * as the H1, content starts below it; when it doesn't, the content's own
	 * first H1 is the page title and is fine.
	 *
	 * @return array<int,array{from:int,to:int}> to = 1 means an extra H1.
	 */
	public static function heading_skips( string $html, bool $title_in_template = true ): array {
		$skips = array();
		if ( ! preg_match_all( '/<h([1-6])\b/i', $html, $m ) ) {
			return $skips;
		}
		$prev    = 1;
		$own_h1  = ! $title_in_template;
		foreach ( $m[1] as $level ) {
			$level = (int) $level;
			if ( 1 === $level && $own_h1 ) {
				$own_h1 = false; // The page's one H1, written in the content.
				$prev   = 1;
				continue;
			}
			if ( 1 === $level ) {
				$skips[] = array(
					'from' => $prev,
					'to'   => 1,
				);
			} elseif ( $level > $prev + 1 ) {
				$skips[] = array(
					'from' => $prev,
					'to'   => $level,
				);
			}
			$prev = $level;
		}
		return $skips;
	}

	/**
	 * Form fields with no label a screen reader can announce. A placeholder
	 * alone does not count.
	 *
	 * @return array<int,string> A short description of each field.
	 */
	public static function fields_without_labels( string $html ): array {
		$out = array();
		if ( false === stripos( $html, '<input' ) && false === stripos( $html, '<select' ) && false === stripos( $html, '<textarea' ) ) {
			return $out;
		}
		$dom = self::dom( $html );
		if ( ! $dom ) {
			return $out;
		}
		$for = array();
		foreach ( $dom->getElementsByTagName( 'label' ) as $label ) {
			if ( '' !== $label->getAttribute( 'for' ) && '' !== trim( $label->textContent ) ) {
				$for[ $label->getAttribute( 'for' ) ] = true;
			}
		}
		$skip = array( 'hidden', 'submit', 'button', 'reset', 'image' );
		$xp   = new DOMXPath( $dom );
		foreach ( $xp->query( '//input|//select|//textarea' ) as $field ) {
			/** @var DOMElement $field */
			$type = strtolower( $field->getAttribute( 'type' ) );
			if ( 'input' === $field->nodeName && in_array( $type, $skip, true ) ) {
				continue;
			}
			if ( 'true' === $field->getAttribute( 'aria-hidden' ) || false !== stripos( str_replace( ' ', '', $field->getAttribute( 'style' ) ), 'display:none' ) ) {
				continue;
			}
			if ( '' !== trim( $field->getAttribute( 'aria-label' ) ) || '' !== trim( $field->getAttribute( 'aria-labelledby' ) ) || '' !== trim( $field->getAttribute( 'title' ) ) ) {
				continue;
			}
			$id = $field->getAttribute( 'id' );
			if ( '' !== $id && isset( $for[ $id ] ) ) {
				continue;
			}
			$wrapped = false;
			for ( $node = $field->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
				if ( 'label' === $node->nodeName ) {
					$wrapped = true;
					break;
				}
			}
			if ( $wrapped ) {
				continue;
			}
			$name  = $field->getAttribute( 'name' );
			$kind  = 'input' === $field->nodeName ? ( '' !== $type ? $type : 'text' ) : $field->nodeName;
			$out[] = '' !== $name ? $kind . ' "' . $name . '"' : $kind;
		}
		return $out;
	}

	/**
	 * Does the statement page exist, is it linked from the site, and was it
	 * reviewed in the last year?
	 *
	 * @return array{exists:bool,linked:bool,reviewed:bool}
	 */
	public static function statement_check(): array {
		$url      = Scout_Accessibility::page_url();
		$s        = Scout_Accessibility::settings();
		$reviewed = '' !== $s['last_reviewed'] && strtotime( $s['last_reviewed'] ) >= strtotime( '-1 year' );
		return array(
			'exists'   => '' !== $url,
			'linked'   => '' !== $url && self::is_linked( $url ),
			'reviewed' => $reviewed,
		);
	}

	/** Look for a link to the statement on the home page, then in menus, widgets and footer parts. */
	private static function is_linked( string $url ): bool {
		if ( ! empty( Scout_Accessibility::settings()['footer_link'] ) ) {
			return true;
		}
		// The theme or a widget printed [scout_accessibility_link] on a live page in the last 30 days.
		$seen = (string) get_option( Scout_Accessibility::LINK_SEEN, '' );
		if ( '' !== $seen && strtotime( $seen ) >= strtotime( '-30 days' ) ) {
			return true;
		}
		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
			)
		);
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$body = (string) wp_remote_retrieve_body( $response );
			if ( false !== strpos( $body, 'href="' . $url . '"' ) || false !== strpos( $body, 'href="' . esc_url( $url ) . '"' ) || ( '' !== $path && false !== strpos( $body, 'href="' . $path . '"' ) ) ) {
				return true;
			}
		}

		// Fallbacks when the site cannot load itself (staging logins, local copies).
		$page_id = (int) Scout_Accessibility::settings()['page_id'];
		// Only menus the theme actually shows (assigned to a menu location) count.
		foreach ( array_unique( array_filter( array_map( 'intval', (array) get_nav_menu_locations() ) ) ) as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
				if ( ( 'post_type' === $item->type && (int) $item->object_id === $page_id ) || untrailingslashit( (string) $item->url ) === untrailingslashit( $url ) ) {
					return true;
				}
			}
		}
		$haystack = '';
		foreach ( array( 'widget_block', 'widget_text', 'widget_custom_html' ) as $opt ) {
			$haystack .= wp_json_encode( get_option( $opt, array() ) );
		}
		$parts = get_posts(
			array(
				'post_type'      => array( 'wp_template_part', 'wp_navigation' ),
				'post_status'    => 'publish',
				'posts_per_page' => 50,
			)
		);
		foreach ( $parts as $part ) {
			$haystack .= $part->post_content;
		}
		$haystack = str_replace( '\\/', '/', $haystack );
		return false !== strpos( $haystack, Scout_Accessibility::LINK_CODE )
			|| false !== strpos( $haystack, $url )
			|| ( '' !== $path && false !== strpos( $haystack, '"' . $path . '"' ) )
			|| false !== strpos( $haystack, '"id":' . $page_id . ',' );
	}
}
