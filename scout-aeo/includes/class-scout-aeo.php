<?php
/**
 * The site-wide part: who may crawl the site for AI search, and the plain-text
 * guide at /llms.txt.
 *
 * Two kinds of AI crawler, two switches:
 * - Search and answer crawlers (OAI-SearchBot, ChatGPT-User, Claude-SearchBot,
 *   PerplexityBot...) fetch pages to answer someone's question right now, and
 *   are how a site gets cited. On by default.
 * - Training crawlers (GPTBot, ClaudeBot, Google-Extended, Applebot-Extended,
 *   CCBot) collect pages to train future models. Also on by default, because
 *   being known to the model helps the business get named; an owner who
 *   prefers otherwise can switch it off without losing AI search.
 *
 * Nothing here runs on staging: robots.txt and /llms.txt are only changed on
 * the live, public site (see is_live()).
 *
 * @package Scout_AEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_AEO {

	const OPTION = 'scout_aeo';

	const SEARCH_BOTS = array(
		'OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User',
		'PerplexityBot', 'Perplexity-User', 'DuckAssistBot',
	);

	const TRAINING_BOTS = array(
		'GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot',
	);

	public static function boot(): void {
		add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 20, 2 );
		// Last of all, so every group (WordPress's, an SEO plugin's, ours) is in place first.
		add_filter( 'robots_txt', array( __CLASS__, 'content_signal' ), PHP_INT_MAX, 2 );
		add_action( 'init', array( __CLASS__, 'serve_llms' ), 1 );
	}

	/** @return array{search:bool, training:bool, llms:bool, intro:string, pages:int[], posts:int} */
	public static function settings(): array {
		$s = (array) get_option( self::OPTION, array() );
		return array(
			'search'   => ! isset( $s['search'] ) || ! empty( $s['search'] ),
			'training' => ! isset( $s['training'] ) || ! empty( $s['training'] ),
			'llms'     => ! isset( $s['llms'] ) || ! empty( $s['llms'] ),
			'intro'    => (string) ( $s['intro'] ?? '' ),
			'pages'    => array_values( array_filter( array_map( 'absint', (array) ( $s['pages'] ?? array() ) ) ) ),
			'posts'    => isset( $s['posts'] ) ? max( 0, min( 50, (int) $s['posts'] ) ) : 20,
		);
	}

	/**
	 * The live, public site: search engines allowed, not a staging or local
	 * host. Filter `scout_aeo_is_live` to override.
	 */
	public static function is_live(): bool {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$live = '1' === (string) get_option( 'blog_public' )
			&& in_array( wp_get_environment_type(), array( 'production' ), true )
			&& ! preg_match( '/(wpengine\.com|wpenginepowered\.com|staging|\.local$|\.test$|localhost|^127\.|^10\.|^192\.168\.)/i', $host );
		return (bool) apply_filters( 'scout_aeo_is_live', $live );
	}

	/**
	 * Add a group per AI crawler. A crawler that finds its own group ignores
	 * the "*" group, so each group repeats the admin rules.
	 */
	public static function robots( $output, $public ) {
		if ( ! $public || ! self::is_live() ) {
			return $output;
		}
		$s     = self::settings();
		$admin = "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		$out   = rtrim( (string) $output ) . "\n";

		$groups = array(
			array( self::SEARCH_BOTS, $s['search'], 'AI search and answer engines' ),
			array( self::TRAINING_BOTS, $s['training'], 'AI model training' ),
		);
		foreach ( $groups as list( $bots, $allowed, $label ) ) {
			$out .= "\n# " . $label . ': ' . ( $allowed ? 'allowed' : 'not allowed' ) . "\n";
			foreach ( $bots as $bot ) {
				$out .= 'User-agent: ' . $bot . "\n";
			}
			$out .= $allowed ? $admin . "Allow: /\n" : "Disallow: /\n";
		}
		if ( $s['llms'] ) {
			$out .= "\n# Plain-text guide for AI tools: " . home_url( '/llms.txt' ) . "\n";
		}
		return $out;
	}

	/**
	 * The Content-Signal line (contentsignals.org): how the site's pages may be
	 * used once a crawler has them. search = a search index, ai-input = quoted in
	 * an AI answer, ai-train = training a model. Values follow the two switches.
	 *
	 * A crawler only reads the most specific group that names it, so the line
	 * goes into every group, not only "*". If any Content-Signal line is already
	 * there (a file, another plugin), it is left alone.
	 */
	public static function content_signal( $output, $public ) {
		$output = (string) $output;
		if ( ! $public || ! self::is_live() || preg_match( '/^\s*content-signal\s*:/im', $output ) ) {
			return $output;
		}
		$s     = self::settings();
		$value = (string) apply_filters(
			'scout_aeo_content_signal',
			'search=yes, ai-input=' . ( $s['search'] ? 'yes' : 'no' ) . ', ai-train=' . ( $s['training'] ? 'yes' : 'no' )
		);
		return '' === $value ? $output : self::add_signal( $output, $value );
	}

	/** Put "Content-Signal: $value" after each run of User-agent lines. Pure, for tests. */
	public static function add_signal( string $output, string $value ): string {
		$line    = 'Content-Signal: ' . $value . "\n";
		$count   = 0;
		$patched = preg_replace( '/((?:^[ \t]*user-agent[ \t]*:[^\r\n]*\r?\n)+)/im', '$1' . $line, $output, -1, $count );
		if ( null !== $patched && $count > 0 ) {
			return $patched;
		}
		return ( '' === trim( $output ) ? '' : rtrim( $output ) . "\n\n" ) . "User-agent: *\n" . $line;
	}

	public static function serve_llms(): void {
		$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( ( '' === $home ? '' : $home . '/' ) . 'llms.txt' !== $path || ! self::settings()['llms'] ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		if ( ! self::is_live() ) {
			header( 'X-Robots-Tag: noindex' );
		}
		echo self::llms_txt(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text.
		exit;
	}

	/** Business details from Scout Core, when it is running. */
	private static function business(): array {
		return class_exists( 'Scout_Core_Business' ) ? (array) Scout_Core_Business::get() : array();
	}

	/** The short description AI tools read first. */
	public static function summary(): string {
		$s = self::settings();
		if ( '' !== trim( $s['intro'] ) ) {
			return trim( $s['intro'] );
		}
		$b     = self::business();
		$name  = wp_specialchars_decode( (string) ( $b['name'] ?? '' ) ?: get_bloginfo( 'name' ), ENT_QUOTES );
		$where = trim( implode( ', ', array_filter( array( $b['city'] ?? '', $b['region'] ?? '' ) ) ) );
		$tag   = wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES );
		$line  = $name . ( $where ? ' is based in ' . $where . '.' : '.' );
		return trim( $line . ( $tag ? ' ' . $tag . ( preg_match( '/[.!?]$/', $tag ) ? '' : '.' ) : '' ) );
	}

	private static function noindexed( int $id ): bool {
		return defined( 'SCOUT_SEO_META_NOINDEX' ) && '1' === (string) get_post_meta( $id, SCOUT_SEO_META_NOINDEX, true );
	}

	/** The pages listed under "Pages": the chosen ones, or the top-level pages. */
	public static function featured_pages(): array {
		$s = self::settings();
		if ( $s['pages'] ) {
			$pages = get_posts( array( 'post_type' => 'page', 'post__in' => $s['pages'], 'orderby' => 'post__in', 'numberposts' => 30, 'post_status' => 'publish' ) );
		} else {
			// Home first, then each top-level page followed by its children.
			$all   = get_pages( array( 'sort_column' => 'menu_order,post_title' ) );
			$front = (int) get_option( 'page_on_front' );
			$skip  = '/^(privacy|terms|cookie|disclaimer|sample-page|thank-you|thanks|cart|checkout|my-account)/';
			$keep  = array_filter( $all, static fn( $p ) => ! preg_match( $skip, $p->post_name ) );
			$pages = array();
			foreach ( $keep as $p ) {
				if ( (int) $p->ID === $front ) {
					$pages[] = $p;
				}
			}
			foreach ( $keep as $p ) {
				if ( $p->post_parent || (int) $p->ID === $front ) {
					continue;
				}
				$pages[] = $p;
				foreach ( $keep as $c ) {
					if ( (int) $c->post_parent === (int) $p->ID ) {
						$pages[] = $c;
					}
				}
			}
			$pages = array_slice( $pages, 0, 30 );
		}
		$skip_ids = array( (int) get_option( 'wp_page_for_privacy_policy' ) );
		return array_values( array_filter( (array) apply_filters( 'scout_aeo_llms_pages', $pages ), static fn( $p ) => ! in_array( (int) $p->ID, $skip_ids, true ) && ! self::noindexed( (int) $p->ID ) && '' === $p->post_password ) );
	}

	/** Text without HTML entities (WordPress curls quotes into &#8220; and friends). */
	private static function plain( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private static function describe( WP_Post $p ): string {
		$text = has_excerpt( $p ) ? get_the_excerpt( $p ) : '';
		$text = trim( preg_replace( '/\s+/', ' ', self::plain( wp_strip_all_tags( (string) $text ) ) ) );
		return $text ? ': ' . wp_trim_words( $text, 30, '...' ) : '';
	}

	public static function llms_txt(): string {
		$b     = self::business();
		$name  = wp_specialchars_decode( (string) ( $b['name'] ?? '' ) ?: get_bloginfo( 'name' ), ENT_QUOTES );
		$lines = array( '# ' . $name, '', '> ' . self::summary(), '' );

		$contact = array_filter(
			array(
				$b['phone'] ?? '',
				$b['email'] ?? '',
				trim( implode( ', ', array_filter( array( $b['city'] ?? '', trim( ( $b['region'] ?? '' ) . ' ' . ( $b['postal'] ?? '' ) ) ) ) ) ),
			)
		);
		if ( $contact ) {
			$lines[] = 'Contact: ' . implode( ', ', $contact ) . '.';
			$lines[] = '';
		}

		$pages = self::featured_pages();
		if ( $pages ) {
			$lines[] = '## Pages';
			foreach ( $pages as $p ) {
				$lines[] = '- [' . self::plain( get_the_title( $p ) ) . '](' . get_permalink( $p ) . ')' . self::describe( $p );
			}
			$lines[] = '';
		}

		$count = self::settings()['posts'];
		$posts = $count ? get_posts( array( 'numberposts' => $count, 'post_status' => 'publish', 'has_password' => false ) ) : array();
		$posts = array_filter( $posts, static fn( $p ) => ! self::noindexed( (int) $p->ID ) );
		if ( $posts ) {
			$lines[] = '## Articles';
			foreach ( $posts as $p ) {
				$lines[] = '- [' . self::plain( get_the_title( $p ) ) . '](' . get_permalink( $p ) . ')' . self::describe( $p );
			}
			$lines[] = '';
		}

		$faq = Scout_AEO_FAQ::pages_with_answers( 30 );
		if ( $faq ) {
			$lines[] = '## Questions answered on this site';
			foreach ( $faq as $id ) {
				foreach ( Scout_AEO_FAQ::get( $id ) as $qa ) {
					$lines[] = '- [' . $qa['q'] . '](' . get_permalink( $id ) . '#answers)';
				}
			}
			$lines[] = '';
		}

		return (string) apply_filters( 'scout_aeo_llms_txt', implode( "\n", $lines ) );
	}
}
