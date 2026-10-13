<?php
/**
 * The site health strip: a few facts read from WordPress itself, each with a
 * plain verdict. Nothing is sent anywhere.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Health {

	const SCAN_KEY  = 'scout_hub_answer_scan';
	const SCAN_HOOK = 'scout_hub_answer_scan';

	public static function boot(): void {
		add_action( self::SCAN_HOOK, array( __CLASS__, 'run_scan' ) );
	}

	/**
	 * Which key pages print FAQ schema. Themes and plugins all do FAQs their
	 * own way, so this looks at what the pages actually send: it loads up to
	 * 15 of the main pages from the site itself (in the background, through
	 * WP-Cron) and counts those carrying FAQPage. Cached for 12 hours.
	 *
	 * @return array{found:int, checked:int, time:int}|null Null while the first scan runs.
	 */
	public static function answer_scan(): ?array {
		$scan = get_transient( self::SCAN_KEY );
		if ( is_array( $scan ) ) {
			return $scan;
		}
		if ( ! wp_next_scheduled( self::SCAN_HOOK ) ) {
			wp_schedule_single_event( time(), self::SCAN_HOOK );
			spawn_cron();
		}
		return null;
	}

	public static function forget_scan(): void {
		delete_transient( self::SCAN_KEY );
	}

	public static function run_scan(): void {
		$all   = get_pages( array( 'sort_column' => 'menu_order,post_title' ) );
		$front = (int) get_option( 'page_on_front' );
		$top   = array_filter( $all, static fn( $p ) => ! $p->post_parent );
		$ids   = wp_list_pluck( $top, 'ID' );
		$kids  = array_filter( $all, static fn( $p ) => in_array( (int) $p->post_parent, array_map( 'intval', $ids ), true ) );
		$pages = array_merge( $top, $kids );
		usort( $pages, static fn( $a, $b ) => ( (int) $b->ID === $front ) <=> ( (int) $a->ID === $front ) );
		$pages = array_slice( array_filter( $pages, static fn( $p ) => ! preg_match( '/^(privacy|terms|cookie|thank|sample-page|card|links)/', $p->post_name ) ), 0, 15 );

		$found   = 0;
		$checked = 0;
		foreach ( $pages as $p ) {
			$r = wp_remote_get( get_permalink( $p ), array( 'timeout' => 8, 'redirection' => 2, 'sslverify' => false, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
			if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
				continue;
			}
			++$checked;
			if ( preg_match( '/"@type"\s*:\s*(\[[^\]]*)?"FAQPage"/', (string) wp_remote_retrieve_body( $r ) ) ) {
				++$found;
			}
		}
		set_transient( self::SCAN_KEY, array( 'found' => $found, 'checked' => $checked, 'time' => time() ), $checked ? 12 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS );
	}

	/**
	 * @return array<int, array{label:string, value:string, state:string, note:string}>
	 *         state is good | warn | bad.
	 */
	public static function checks(): array {
		global $wp_version;
		$out = array();

		$updates = 0;
		$plugins = get_site_transient( 'update_plugins' );
		if ( is_object( $plugins ) && ! empty( $plugins->response ) ) {
			$updates += count( (array) $plugins->response );
		}
		$core = get_site_transient( 'update_core' );
		$core_update = false;
		if ( is_object( $core ) && ! empty( $core->updates ) ) {
			foreach ( $core->updates as $u ) {
				if ( isset( $u->response ) && 'upgrade' === $u->response ) {
					$core_update = true;
				}
			}
		}

		$out[] = array(
			'label' => __( 'WordPress', 'scout-build' ),
			'value' => (string) $wp_version,
			'state' => $core_update ? 'warn' : 'good',
			'note'  => $core_update ? __( 'A newer version is available.', 'scout-build' ) : __( 'Up to date.', 'scout-build' ),
		);
		$php_ok = version_compare( PHP_VERSION, '8.1', '>=' );
		$out[]  = array(
			'label' => __( 'PHP', 'scout-build' ),
			'value' => PHP_VERSION,
			'state' => $php_ok ? 'good' : 'warn',
			'note'  => $php_ok ? __( 'Supported.', 'scout-build' ) : __( 'Ask the host to move to PHP 8.1 or newer.', 'scout-build' ),
		);
		$out[] = array(
			'label' => __( 'Updates waiting', 'scout-build' ),
			'value' => (string) $updates,
			'state' => $updates ? 'warn' : 'good',
			'note'  => $updates ? __( 'Plugins with an update ready.', 'scout-build' ) : __( 'Nothing waiting.', 'scout-build' ),
		);
		$https = 0 === strpos( home_url(), 'https://' );
		$out[] = array(
			'label' => __( 'Security lock', 'scout-build' ),
			'value' => $https ? __( 'On', 'scout-build' ) : __( 'Off', 'scout-build' ),
			'state' => $https ? 'good' : 'bad',
			'note'  => $https ? __( 'The site loads over HTTPS.', 'scout-build' ) : __( 'The site address is not HTTPS.', 'scout-build' ),
		);
		$public = '1' === (string) get_option( 'blog_public' );
		$out[]  = array(
			'label' => __( 'Search engines', 'scout-build' ),
			'value' => $public ? __( 'Allowed', 'scout-build' ) : __( 'Hidden', 'scout-build' ),
			'state' => $public ? 'good' : 'warn',
			'note'  => $public ? __( 'Google can find the site.', 'scout-build' ) : __( 'Settings, Reading asks search engines to stay away. Right for staging, wrong for a live site.', 'scout-build' ),
		);

		$health = json_decode( (string) get_transient( 'health-check-site-status-result' ), true );
		if ( is_array( $health ) ) {
			$critical = (int) ( $health['critical'] ?? 0 );
			$advised  = (int) ( $health['recommended'] ?? 0 );
			$out[]    = array(
				'label' => __( 'Site Health', 'scout-build' ),
				'value' => $critical ? __( 'Needs attention', 'scout-build' ) : __( 'Good', 'scout-build' ),
				'state' => $critical ? 'bad' : ( $advised > 3 ? 'warn' : 'good' ),
				/* translators: 1: critical count, 2: recommended count */
				'note'  => sprintf( __( '%1$d critical, %2$d recommended (WordPress Site Health).', 'scout-build' ), $critical, $advised ),
			);
		} else {
			$out[] = array(
				'label' => __( 'Site Health', 'scout-build' ),
				'value' => __( 'Not checked yet', 'scout-build' ),
				'state' => 'warn',
				'note'  => __( 'Open Tools, Site Health once to run WordPress\'s own check.', 'scout-build' ),
			);
		}
		return $out;
	}

	/**
	 * How ready the site is for AI search (ChatGPT, Google AI answers,
	 * Perplexity, Claude). Each check reads WordPress itself; nothing is sent.
	 * Works with or without the AI search feature (scout-aeo) turned on, and
	 * counts what the theme or Scout Core already does.
	 *
	 * @return array<int, array{label:string, value:string, state:string, note:string, link?:string, link_text?:string}>
	 */
	public static function ai_checks(): array {
		$out    = array();
		$aeo    = class_exists( 'Scout_AEO' );
		$set    = $aeo ? admin_url( 'admin.php?page=scout-ai-search' ) : Scout_Hub_Admin::url( 'features' );
		$set_tx = $aeo ? __( 'AI search settings', 'scout-build' ) : __( 'Turn on AI search', 'scout-build' );

		// 1. Crawlers allowed.
		$file    = file_exists( ABSPATH . 'robots.txt' );
		$blocked = self::blocked_ai_bots();
		$public  = '1' === (string) get_option( 'blog_public' );
		if ( ! $public ) {
			$out[] = array( 'label' => __( 'AI crawlers', 'scout-build' ), 'value' => __( 'Hidden', 'scout-build' ), 'state' => 'warn', 'note' => __( 'Search engines are switched off in Settings, Reading, so AI tools are kept out too. Right for staging only.', 'scout-build' ) );
		} elseif ( $file ) {
			$safe  = '' === Scout_Hub_Robots::blocker();
			$out[] = array(
				'label'     => __( 'AI crawlers', 'scout-build' ),
				'value'     => $safe ? __( 'File in the way', 'scout-build' ) : __( 'Check by hand', 'scout-build' ),
				'state'     => 'warn',
				'note'      => $safe ? __( 'A robots.txt file on the server hides WordPress\'s version and everything Scout adds to it. Switching is safe: no rule would be lost.', 'scout-build' ) : __( 'A robots.txt file on the server overrides WordPress, and it has rules of its own. Review it before switching.', 'scout-build' ),
				'link'      => Scout_Hub_Robots::url(),
				'link_text' => $safe ? __( 'Switch to WordPress\'s robots.txt', 'scout-build' ) : __( 'Review the file', 'scout-build' ),
			);
		} elseif ( $blocked ) {
			/* translators: %s: crawler names */
			$out[] = array( 'label' => __( 'AI crawlers', 'scout-build' ), 'value' => __( 'Blocked', 'scout-build' ), 'state' => 'bad', 'note' => sprintf( __( 'robots.txt blocks %s.', 'scout-build' ), implode( ', ', $blocked ) ), 'link' => $set, 'link_text' => $set_tx );
		} else {
			$named = $aeo || function_exists( 'scout_llms_txt' );
			$out[] = array( 'label' => __( 'AI crawlers', 'scout-build' ), 'value' => __( 'Welcome', 'scout-build' ), 'state' => 'good', 'note' => $named ? __( 'robots.txt names the AI search tools and lets them in.', 'scout-build' ) : __( 'Nothing blocks them. Turning on AI search names them outright.', 'scout-build' ) );
		}

		// 2. The plain-text guide.
		$llms  = ( $aeo && Scout_AEO::settings()['llms'] ) || function_exists( 'scout_llms_txt' ) || file_exists( ABSPATH . 'llms.txt' );
		$out[] = array(
			'label' => __( 'Guide for AI tools', 'scout-build' ),
			'value' => $llms ? __( 'Published', 'scout-build' ) : __( 'Missing', 'scout-build' ),
			'state' => $llms ? 'good' : 'warn',
			'note'  => $llms ? __( 'A plain-text summary of the site is at /llms.txt.', 'scout-build' ) : __( 'There is no /llms.txt for AI tools to read.', 'scout-build' ),
		) + ( $llms ? array() : array( 'link' => $set, 'link_text' => $set_tx ) );

		// 3. Structured data.
		$schema = class_exists( 'Scout_Schema' ) || function_exists( 'scout_head' ) || defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' );
		$out[]  = array(
			'label' => __( 'Structured data', 'scout-build' ),
			'value' => $schema ? __( 'On', 'scout-build' ) : __( 'Missing', 'scout-build' ),
			'state' => $schema ? 'good' : 'bad',
			'note'  => $schema ? __( 'Pages tell search and AI tools who the business is, in their own format.', 'scout-build' ) : __( 'No schema found. Turn on Business info and SEO.', 'scout-build' ),
		) + ( $schema ? array() : array( 'link' => Scout_Hub_Admin::url( 'features' ), 'link_text' => __( 'Your features', 'scout-build' ) ) );

		// 4. Business details complete (Scout Core sites).
		if ( class_exists( 'Scout_Core_Business' ) ) {
			$b       = (array) Scout_Core_Business::get();
			$missing = array_keys( array_filter( array( 'name' => $b['name'] ?? '', 'phone' => $b['phone'] ?? '', 'city' => $b['city'] ?? '' ), static fn( $v ) => '' === trim( (string) $v ) ) );
			$out[]   = array(
				'label' => __( 'Business details', 'scout-build' ),
				'value' => $missing ? __( 'Incomplete', 'scout-build' ) : __( 'Complete', 'scout-build' ),
				'state' => $missing ? 'warn' : 'good',
				/* translators: %s: missing fields */
				'note'  => $missing ? sprintf( __( 'Missing: %s. AI tools match these against Google and directories.', 'scout-build' ), implode( ', ', $missing ) ) : __( 'Name, phone and city are filled in.', 'scout-build' ),
			) + ( $missing ? array( 'link' => admin_url( 'admin.php?page=scout&tab=business' ), 'link_text' => __( 'Business details', 'scout-build' ) ) : array() );
		}

		// 5. Direct answers on pages: what the key pages actually print.
		$scan = self::answer_scan();
		$hub  = post_type_exists( 'faq' ) ? (int) ( wp_count_posts( 'faq' )->publish ?? 0 ) : 0;
		if ( null === $scan ) {
			$out[] = array( 'label' => __( 'Direct answers', 'scout-build' ), 'value' => __( 'Checking', 'scout-build' ), 'state' => 'warn', 'note' => __( 'Looking through the main pages for questions and answers. Refresh in a minute.', 'scout-build' ) );
		} elseif ( ! $scan['checked'] ) {
			$out[] = array( 'label' => __( 'Direct answers', 'scout-build' ), 'value' => __( 'Could not check', 'scout-build' ), 'state' => 'warn', 'note' => __( 'The site could not load its own pages (a password or firewall on staging does this). It tries again in 30 minutes.', 'scout-build' ) );
		} else {
			$good  = $scan['found'] >= min( 3, $scan['checked'] ) || $hub >= 5;
			$bits  = array(
				/* translators: 1: pages with answers, 2: pages checked */
				sprintf( __( '%1$d of %2$d main pages answer questions', 'scout-build' ), $scan['found'], $scan['checked'] ),
			);
			if ( $hub ) {
				/* translators: %d: FAQ count */
				$bits[] = sprintf( _n( '%d FAQ', '%d FAQs', $hub, 'scout-build' ), $hub );
			}
			/* translators: %s: time since the check */
			$bits[] = sprintf( __( 'checked %s ago', 'scout-build' ), human_time_diff( (int) $scan['time'] ) );
			$out[]  = array(
				'label' => __( 'Direct answers', 'scout-build' ),
				/* translators: 1: pages with answers, 2: pages checked */
				'value' => sprintf( __( '%1$d of %2$d pages', 'scout-build' ), $scan['found'], $scan['checked'] ),
				'state' => $good ? 'good' : 'warn',
				'note'  => ucfirst( implode( ', ', $bits ) ) . '.' . ( $good ? '' : ' ' . __( 'AI tools quote short, direct answers. Add questions to the pages that bring in business.', 'scout-build' ) ),
			) + ( $good ? array() : array( 'link' => $set, 'link_text' => $set_tx ) );
		}

		// 6. Fresh content.
		$last = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'orderby' => 'modified', 'order' => 'DESC' ) );
		if ( $last ) {
			$days  = (int) floor( ( time() - (int) get_post_modified_time( 'U', true, $last[0] ) ) / DAY_IN_SECONDS );
			$state = $days <= 60 ? 'good' : ( $days <= 180 ? 'warn' : 'bad' );
			/* translators: %d: days */
			$out[] = array( 'label' => __( 'Fresh content', 'scout-build' ), 'value' => 0 === $days ? __( 'Today', 'scout-build' ) : sprintf( _n( '%d day ago', '%d days ago', $days, 'scout-build' ), $days ), 'state' => $state, 'note' => 'good' === $state ? __( 'A post was published or updated recently.', 'scout-build' ) : __( 'AI tools favor current pages. Publish or refresh a post.', 'scout-build' ) );
		} else {
			$out[] = array( 'label' => __( 'Fresh content', 'scout-build' ), 'value' => __( 'No posts', 'scout-build' ), 'state' => 'warn', 'note' => __( 'Posts that answer customers\' questions are what AI tools cite most.', 'scout-build' ) );
		}

		// 7. Named authors with a bio.
		$recent = get_posts( array( 'numberposts' => 10, 'post_status' => 'publish' ) );
		if ( $recent ) {
			$with  = count( array_filter( $recent, static fn( $p ) => '' !== trim( (string) get_the_author_meta( 'description', (int) $p->post_author ) ) ) );
			$share = $with / count( $recent );
			$out[] = array(
				'label' => __( 'Real authors', 'scout-build' ),
				/* translators: 1: posts with a bio, 2: posts checked */
				'value' => sprintf( __( '%1$d of %2$d', 'scout-build' ), $with, count( $recent ) ),
				'state' => $share >= 0.8 ? 'good' : 'warn',
				'note'  => $share >= 0.8 ? __( 'Recent posts are signed by a person with a bio.', 'scout-build' ) : __( 'Add a short bio to each author\'s profile (Users, Profile, Biographical Info).', 'scout-build' ),
			) + ( $share >= 0.8 ? array() : array( 'link' => admin_url( 'users.php' ), 'link_text' => __( 'Users', 'scout-build' ) ) );
		}
		return $out;
	}

	/** @return string[] Key AI crawlers that robots.txt sends away from the whole site. */
	private static function blocked_ai_bots(): array {
		$public = '1' === (string) get_option( 'blog_public' );
		$text   = "User-agent: *\n" . ( $public ? "Disallow: /wp-admin/\n" : "Disallow: /\n" );
		$text   = (string) apply_filters( 'robots_txt', $text, $public );
		$groups = array();
		$agents = array();
		$rules  = false;
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = trim( preg_replace( '/#.*/', '', $line ) );
			if ( preg_match( '/^user-agent:\s*(.+)$/i', $line, $m ) ) {
				if ( $rules ) {
					$agents = array();
					$rules  = false;
				}
				$agents[] = strtolower( trim( $m[1] ) );
			} elseif ( preg_match( '/^(dis)?allow:\s*(.*)$/i', $line, $m ) ) {
				$rules = true;
				foreach ( $agents as $a ) {
					$groups[ $a ][] = array( '' !== $m[1], trim( $m[2] ) );
				}
			}
		}
		$blocked = array();
		foreach ( array( 'OAI-SearchBot', 'PerplexityBot', 'Claude-SearchBot', 'GPTBot' ) as $bot ) {
			$g = $groups[ strtolower( $bot ) ] ?? ( $groups['*'] ?? array() );
			$all_out = false;
			$root_in = false;
			foreach ( $g as list( $dis, $path ) ) {
				if ( $dis && '/' === $path ) {
					$all_out = true;
				}
				if ( ! $dis && '/' === $path ) {
					$root_in = true;
				}
			}
			if ( $all_out && ! $root_in ) {
				$blocked[] = $bot;
			}
		}
		return $blocked;
	}
}
