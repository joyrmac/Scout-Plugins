<?php
/**
 * The eight checks, run in four steps.
 *
 * basics:   security lock, page titles, business info for Google, phone layout
 * speed:    Google's speed tool (PageSpeed Insights, phone)
 * crawlers: AI crawlers (robots.txt and a test visit as each bot), sitemap
 * photos:   photo sizes on the home page
 *
 * Every row is ['key', 'title', 'status' => pass|warn|fail|skip, 'text', 'source'].
 * "skip" means we could not check it this time; it is left out of the score.
 * Every request goes through wp_safe_remote_*(), which refuses private and
 * local addresses, including on redirects.
 *
 * @package Scout_Sitecheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Sitecheck_Checks {

	const UA        = 'Mozilla/5.0 (compatible; ScoutSiteCheck/0.1; +https://scoutraleigh.com/)';
	const BIG_PHOTO = 500 * 1024;

	/** AI crawlers: robots.txt token => [plain name, user agent for the test visit or ''] */
	const BOTS = array(
		'GPTBot'            => array( 'ChatGPT', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.1; +https://openai.com/gptbot' ),
		'ClaudeBot'         => array( 'Claude', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ClaudeBot/1.0; +claudebot@anthropic.com' ),
		'PerplexityBot'     => array( 'Perplexity', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot' ),
		'Google-Extended'   => array( 'Gemini', '' ),
		'Applebot-Extended' => array( 'Apple Intelligence', '' ),
	);

	public static function step( string $step, string $url ): array {
		switch ( $step ) {
			case 'basics':
				return self::basics( $url );
			case 'speed':
				return array( self::speed( $url ) );
			case 'crawlers':
				return self::crawlers( $url );
			case 'photos':
				return array( self::photos( $url ) );
		}
		return array();
	}

	/* ---------- fetching ---------- */

	/** @return array{ok:bool,code:int,body:string,url:string,error:string} */
	private static function get( string $url, array $args = array() ): array {
		$res = wp_safe_remote_get(
			$url,
			array_merge(
				array(
					'timeout'             => 15,
					'redirection'         => 5,
					'user-agent'          => self::UA,
					'limit_response_size' => 3 * MB_IN_BYTES,
				),
				$args
			)
		);
		if ( is_wp_error( $res ) ) {
			return array(
				'ok'    => false,
				'code'  => 0,
				'body'  => '',
				'url'   => $url,
				'error' => $res->get_error_message(),
			);
		}
		$final = $url;
		if ( isset( $res['http_response'] ) && is_object( $res['http_response'] ) && method_exists( $res['http_response'], 'get_response_object' ) ) {
			$final = (string) $res['http_response']->get_response_object()->url;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return array(
			'ok'    => $code >= 200 && $code < 400,
			'code'  => $code,
			'body'  => (string) wp_remote_retrieve_body( $res ),
			'url'   => $final,
			'error' => '',
		);
	}

	/** The home page over https, or over http when https fails. */
	private static function home( string $url ): array {
		$https = self::get( $url );
		if ( $https['ok'] ) {
			$https['secure'] = 0 === stripos( $https['url'], 'https://' );
			return $https;
		}
		$http = self::get( preg_replace( '#^https://#i', 'http://', $url ) );
		if ( $http['ok'] ) {
			$http['secure']    = 0 === stripos( $http['url'], 'https://' );
			$http['tls_error'] = $https['error'];
			return $http;
		}
		$https['secure'] = false;
		return $https;
	}

	private static function dom( string $html ): ?DOMDocument {
		if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$doc  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $doc;
	}

	/** Resolve a link found on $base into an absolute http(s) URL, or ''. */
	private static function absolute( string $href, string $base ): string {
		$href = trim( html_entity_decode( $href ) );
		if ( '' === $href || preg_match( '#^(data:|mailto:|tel:|javascript:|\#)#i', $href ) ) {
			return '';
		}
		if ( 0 === strpos( $href, '//' ) ) {
			return 'https:' . $href;
		}
		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}
		$p    = wp_parse_url( $base );
		$root = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		if ( 0 === strpos( $href, '/' ) ) {
			return $root . $href;
		}
		$dir = preg_replace( '#/[^/]*$#', '/', (string) ( $p['path'] ?? '/' ) );
		return $root . $dir . $href;
	}

	private static function host( string $url ): string {
		return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
	}

	private static function row( string $key, string $title, string $status, string $text, string $source = '' ): array {
		return compact( 'key', 'title', 'status', 'text', 'source' );
	}

	/** "A, B and C" */
	private static function join( array $items ): string {
		$items = array_values( $items );
		if ( count( $items ) < 2 ) {
			return (string) ( $items[0] ?? '' );
		}
		$last = array_pop( $items );
		return implode( ', ', $items ) . ' ' . __( 'and', 'scout-sitecheck' ) . ' ' . $last;
	}

	/* ---------- basics ---------- */

	private static function basics( string $url ): array {
		$home = self::home( $url );
		if ( ! $home['ok'] ) {
			return array( 'fatal' => sprintf( /* translators: %s: site */ __( 'We couldn\'t reach %s. Check the spelling and try again. If the site is up, its host may be blocking checks like this one, and we can run it by hand.', 'scout-sitecheck' ), self::host( $url ) ) );
		}
		$doc  = self::dom( $home['body'] );
		$rows = array(
			self::security( $home ),
			self::titles( $home, $doc ),
			self::schema( $home['body'], $doc ),
			self::phone( $doc ),
		);
		$p            = wp_parse_url( $home['url'] );
		$rows['base'] = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . '/';
		return $rows;
	}

	private static function security( array $home ): array {
		$t = __( 'Security lock', 'scout-sitecheck' );
		if ( ! $home['secure'] ) {
			return self::row( 'security', $t, 'fail', __( 'The site opens without the padlock (HTTPS), so browsers mark it "Not secure" and visitors see a warning.', 'scout-sitecheck' ) );
		}
		$days = self::cert_days_left( (string) wp_parse_url( $home['url'], PHP_URL_HOST ) );
		if ( null !== $days && $days < 14 ) {
			return self::row( 'security', $t, 'warn', sprintf( /* translators: %d: days */ _n( 'HTTPS is on, but the security certificate runs out in %d day.', 'HTTPS is on, but the security certificate runs out in %d days.', max( 0, $days ), 'scout-sitecheck' ), max( 0, $days ) ) );
		}
		return self::row( 'security', $t, 'pass', __( 'HTTPS is on and the security certificate is current.', 'scout-sitecheck' ) );
	}

	/** Days until the site's certificate expires, or null if we can't tell. */
	private static function cert_days_left( string $host ): ?int {
		if ( '' === $host || ! function_exists( 'stream_socket_client' ) || ! function_exists( 'openssl_x509_parse' ) ) {
			return null;
		}
		$ctx  = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'SNI_enabled'       => true,
					'peer_name'         => $host,
				),
			)
		);
		$sock = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a failed lookup is an answer, not an error.
		if ( ! $sock ) {
			return null;
		}
		$params = stream_context_get_params( $sock );
		fclose( $sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$cert = $params['options']['ssl']['peer_certificate'] ?? null;
		$info = $cert ? openssl_x509_parse( $cert ) : false;
		if ( ! $info || empty( $info['validTo_time_t'] ) ) {
			return null;
		}
		return (int) floor( ( (int) $info['validTo_time_t'] - time() ) / DAY_IN_SECONDS );
	}

	private static function title_of( ?DOMDocument $doc ): string {
		if ( ! $doc ) {
			return '';
		}
		$t = $doc->getElementsByTagName( 'title' );
		return $t->length ? trim( preg_replace( '/\s+/', ' ', $t->item( 0 )->textContent ) ) : '';
	}

	private static function titles( array $home, ?DOMDocument $doc ): array {
		$t      = __( 'Page titles', 'scout-sitecheck' );
		$titles = array( $home['url'] => self::title_of( $doc ) );
		// Up to four more pages from the home page's own links.
		if ( $doc ) {
			$host  = self::host( $home['url'] );
			$links = array();
			foreach ( $doc->getElementsByTagName( 'a' ) as $a ) {
				$abs = self::absolute( (string) $a->getAttribute( 'href' ), $home['url'] );
				if ( '' === $abs || self::host( $abs ) !== $host ) {
					continue;
				}
				$abs  = preg_replace( '/[#?].*$/', '', $abs );
				$path = (string) wp_parse_url( $abs, PHP_URL_PATH );
				if ( '' === $path || '/' === $path || preg_match( '#\.(pdf|jpe?g|png|gif|webp|svg|zip|docx?|xlsx?|mp4)$#i', $path ) || preg_match( '#/(wp-admin|wp-login|feed|cart|checkout|my-account)#', $path ) ) {
					continue;
				}
				$links[ untrailingslashit( $abs ) ] = $abs;
				if ( count( $links ) >= 4 ) {
					break;
				}
			}
			foreach ( $links as $link ) {
				$page = self::get( $link, array( 'timeout' => 10 ) );
				if ( $page['ok'] ) {
					$titles[ $link ] = self::title_of( self::dom( $page['body'] ) );
				}
			}
		}
		$n       = count( $titles );
		$missing = count( array_filter( $titles, static fn( $x ) => '' === $x ) );
		$counts  = array_count_values( array_filter( $titles ) );
		$dupes   = array_sum( array_filter( $counts, static fn( $c ) => $c > 1 ) );
		if ( '' === reset( $titles ) ) {
			return self::row( 'titles', $t, 'fail', __( 'Your home page has no title, which is the line Google shows as the blue link in search results.', 'scout-sitecheck' ) );
		}
		if ( $missing ) {
			/* translators: 1: pages without a title, 2: pages checked */
			return self::row( 'titles', $t, 'warn', sprintf( __( '%1$d of the %2$d pages we checked have no title, the line Google shows as the blue link in search results.', 'scout-sitecheck' ), $missing, $n ) );
		}
		if ( $dupes ) {
			/* translators: 1: pages sharing a title, 2: pages checked */
			return self::row( 'titles', $t, 'warn', sprintf( __( '%1$d of the %2$d pages we checked share the same title, so Google can\'t tell them apart in search results.', 'scout-sitecheck' ), $dupes, $n ) );
		}
		/* translators: %d: pages checked */
		return self::row( 'titles', $t, 'pass', sprintf( _n( 'The page we checked has its own title.', 'All %d pages we checked have their own title.', $n, 'scout-sitecheck' ), $n ) );
	}

	/** Every @type in the page's JSON-LD and microdata, plus whether any node carries an address or phone. */
	private static function schema_types( string $html, ?DOMDocument $doc ): array {
		$types   = array();
		$contact = false;
		$walk    = static function ( $node ) use ( &$walk, &$types, &$contact ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			if ( isset( $node['@type'] ) ) {
				foreach ( (array) $node['@type'] as $type ) {
					$types[] = (string) $type;
				}
				if ( ! empty( $node['address'] ) || ! empty( $node['telephone'] ) ) {
					$contact = true;
				}
			}
			foreach ( $node as $child ) {
				$walk( $child );
			}
		};
		if ( $doc ) {
			foreach ( $doc->getElementsByTagName( 'script' ) as $s ) {
				if ( false !== stripos( (string) $s->getAttribute( 'type' ), 'ld+json' ) ) {
					$walk( json_decode( trim( $s->textContent ), true ) );
				}
			}
		}
		if ( preg_match_all( '#itemtype=["\']https?://schema\.org/(\w+)#i', $html, $m ) ) {
			$types = array_merge( $types, $m[1] );
		}
		return array( array_unique( $types ), $contact );
	}

	private static function schema( string $html, ?DOMDocument $doc ): array {
		$t = __( 'Business info for Google', 'scout-sitecheck' );
		list( $types, $contact ) = self::schema_types( $html, $doc );
		$generic = array( 'WebSite', 'WebPage', 'BreadcrumbList', 'ImageObject', 'Person', 'SearchAction', 'ReadAction', 'ListItem', 'SiteNavigationElement', 'CollectionPage', 'ItemList', 'Article', 'BlogPosting', 'FAQPage', 'Question', 'Answer', 'EntryPoint', 'PropertyValueSpecification', 'Organization', 'ContactPoint' );
		$business = array_diff( $types, $generic );
		if ( $business && $contact ) {
			return self::row( 'schema', $t, 'pass', __( 'Google can read your business details, like your address and phone, from hidden labels on the page.', 'scout-sitecheck' ), __( 'Schema markup', 'scout-sitecheck' ) );
		}
		if ( $types ) {
			return self::row( 'schema', $t, 'warn', __( 'Google sees some details about the site, but not your business\'s address, phone and hours.', 'scout-sitecheck' ), __( 'Schema markup', 'scout-sitecheck' ) );
		}
		return self::row( 'schema', $t, 'fail', __( 'No business info for Google found, so Google has to guess your hours and address.', 'scout-sitecheck' ), __( 'Schema markup', 'scout-sitecheck' ) );
	}

	private static function phone( ?DOMDocument $doc ): array {
		$t        = __( 'Phone layout', 'scout-sitecheck' );
		$viewport = '';
		if ( $doc ) {
			foreach ( $doc->getElementsByTagName( 'meta' ) as $m ) {
				if ( 'viewport' === strtolower( (string) $m->getAttribute( 'name' ) ) ) {
					$viewport = strtolower( str_replace( ' ', '', (string) $m->getAttribute( 'content' ) ) );
				}
			}
		}
		if ( false === strpos( $viewport, 'width=device-width' ) ) {
			return self::row( 'phone', $t, 'fail', __( 'The page isn\'t set up for phones, so it shows up shrunk down and hard to read on a small screen.', 'scout-sitecheck' ) );
		}
		if ( false !== strpos( $viewport, 'user-scalable=no' ) || false !== strpos( $viewport, 'user-scalable=0' ) || preg_match( '/maximum-scale=1(\.0)?(,|$)/', $viewport ) ) {
			return self::row( 'phone', $t, 'warn', __( 'The page fits phone screens, but it stops people from zooming in, which makes it hard to read for some visitors.', 'scout-sitecheck' ) );
		}
		return self::row( 'phone', $t, 'pass', __( 'The page is set up to fit phone screens.', 'scout-sitecheck' ) );
	}

	/* ---------- speed ---------- */

	private static function speed( string $url ): array {
		$t   = __( 'Speed', 'scout-sitecheck' );
		$src = __( 'Google speed tool', 'scout-sitecheck' );
		$api = add_query_arg(
			array_filter(
				array(
					'url'      => rawurlencode( $url ),
					'strategy' => 'mobile',
					'category' => 'performance',
					'key'      => Scout_Sitecheck::psi_key(),
				)
			),
			'https://www.googleapis.com/pagespeedonline/v5/runPagespeed'
		);
		$res = wp_remote_get( $api, array( 'timeout' => 90 ) );
		$data = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$score = $data['lighthouseResult']['categories']['performance']['score'] ?? null;
		if ( null === $score ) {
			return self::row( 'speed', $t, 'skip', __( 'Google\'s speed tool didn\'t answer this time, so speed is left out of your score.', 'scout-sitecheck' ), $src );
		}
		$score = (int) round( (float) $score * 100 );
		$lcp   = (float) ( $data['lighthouseResult']['audits']['largest-contentful-paint']['numericValue'] ?? 0 ) / 1000;
		$secs  = number_format_i18n( $lcp, 1 );
		$src  .= ' · ' . sprintf( /* translators: %d: score */ __( 'score %d', 'scout-sitecheck' ), $score );
		if ( $score >= 90 ) {
			if ( $lcp <= 2.5 ) {
				/* translators: %s: seconds */
				return self::row( 'speed', $t, 'pass', sprintf( __( 'The main content shows in %s seconds on phones, inside Google\'s 2.5-second goal.', 'scout-sitecheck' ), $secs ), $src );
			}
			/* translators: 1: score, 2: seconds */
			return self::row( 'speed', $t, 'pass', sprintf( __( 'Google gives the site %1$d out of 100 for speed on phones. The main content shows in %2$s seconds, close to Google\'s 2.5-second goal.', 'scout-sitecheck' ), $score, $secs ), $src );
		}
		/* translators: %s: seconds */
		return self::row( 'speed', $t, $score < 50 ? 'fail' : 'warn', sprintf( __( 'The main content takes %s seconds to show on phones. Aim for under 2.5 seconds.', 'scout-sitecheck' ), $secs ), $src );
	}

	/* ---------- crawlers ---------- */

	/** robots.txt as [user-agent token (lowercase) => list of [directive, path]]. */
	private static function robots_groups( string $txt ): array {
		$groups  = array();
		$current = array();
		$in_rules = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $txt ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field = strtolower( $field );
			if ( 'user-agent' === $field ) {
				if ( $in_rules ) {
					$current  = array();
					$in_rules = false;
				}
				$current[] = strtolower( $value );
				foreach ( $current as $ua ) {
					$groups[ $ua ] = $groups[ $ua ] ?? array();
				}
			} elseif ( in_array( $field, array( 'allow', 'disallow' ), true ) ) {
				$in_rules = true;
				foreach ( $current as $ua ) {
					$groups[ $ua ][] = array( $field, $value );
				}
			}
		}
		return $groups;
	}

	/** True when a group's rules shut out the whole site. */
	private static function blocks_all( array $rules ): bool {
		$blocked = false;
		foreach ( $rules as list( $directive, $path ) ) {
			if ( 'disallow' === $directive && '/' === $path ) {
				$blocked = true;
			}
			if ( 'allow' === $directive && '/' === $path ) {
				return false;
			}
		}
		return $blocked;
	}

	private static function crawlers( string $url ): array {
		$robots = self::get( $url . 'robots.txt', array( 'redirection' => 3, 'timeout' => 10 ) );
		$txt    = $robots['ok'] && false === stripos( substr( $robots['body'], 0, 200 ), '<html' ) ? $robots['body'] : '';
		$groups = self::robots_groups( $txt );

		// AI crawlers.
		$allowed = array();
		$blocked = array();
		$home    = null;
		foreach ( self::BOTS as $token => list( $name, $ua ) ) {
			$rules = $groups[ strtolower( $token ) ] ?? ( $groups['*'] ?? array() );
			$no    = self::blocks_all( $rules );
			if ( ! $no && '' !== $ua ) {
				// A firewall can turn a bot away even when robots.txt lets it in.
				$visit = self::get( $url, array( 'user-agent' => $ua, 'timeout' => 10, 'limit_response_size' => 200 * KB_IN_BYTES ) );
				$no    = in_array( $visit['code'], array( 401, 403, 406, 429, 451, 503 ), true );
			}
			if ( $no ) {
				$blocked[] = $name;
			} else {
				$allowed[] = $name;
			}
		}
		$t   = __( 'AI crawlers', 'scout-sitecheck' );
		$src = __( 'Crawler check', 'scout-sitecheck' );
		if ( ! $blocked ) {
			$ai = self::row( 'ai', $t, 'pass', sprintf( /* translators: %s: list of AI tools */ __( '%s can all read your site, so they can name you in their answers.', 'scout-sitecheck' ), self::join( $allowed ) ), $src );
		} elseif ( ! $allowed ) {
			$ai = self::row( 'ai', $t, 'fail', __( 'AI tools like ChatGPT, Claude and Perplexity are blocked from reading your site, so they can\'t name you in their answers.', 'scout-sitecheck' ), $src );
		} else {
			/* translators: 1: tools that can read the site, 2: tools that are blocked */
			$ai = self::row( 'ai', $t, 'warn', sprintf( _n( '%1$s can read your site. %2$s is blocked.', '%1$s can read your site. %2$s are blocked.', count( $blocked ), 'scout-sitecheck' ), self::join( $allowed ), self::join( $blocked ) ), $src );
		}

		// Sitemap: robots.txt first, then the usual addresses.
		$tries = array();
		if ( preg_match_all( '/^\s*sitemap:\s*(\S+)/im', $txt, $m ) ) {
			$tries = array_slice( $m[1], 0, 2 );
		}
		$tries = array_merge( $tries, array( $url . 'sitemap.xml', $url . 'sitemap_index.xml', $url . 'wp-sitemap.xml' ) );
		$found = '';
		foreach ( array_unique( $tries ) as $try ) {
			$map = self::get( $try, array( 'timeout' => 10, 'limit_response_size' => 512 * KB_IN_BYTES ) );
			if ( $map['ok'] && preg_match( '/<(urlset|sitemapindex)[\s>]/i', $map['body'] ) ) {
				$found = (string) wp_parse_url( $map['url'], PHP_URL_PATH );
				break;
			}
		}
		$t = __( 'Sitemap', 'scout-sitecheck' );
		$sitemap = '' !== $found
			? self::row( 'sitemap', $t, 'pass', sprintf( /* translators: %s: sitemap path */ __( 'Found at %s, so Google can find every page.', 'scout-sitecheck' ), $found ) )
			: self::row( 'sitemap', $t, 'fail', __( 'No sitemap found. A sitemap is the list of pages Google uses to find everything on your site.', 'scout-sitecheck' ) );

		return array( $ai, $sitemap );
	}

	/* ---------- photos ---------- */

	private static function photos( string $url ): array {
		$t    = __( 'Photo sizes', 'scout-sitecheck' );
		$home = self::get( $url );
		$doc  = $home['ok'] ? self::dom( $home['body'] ) : null;
		$srcs = array();
		if ( $doc ) {
			foreach ( $doc->getElementsByTagName( 'img' ) as $img ) {
				$src = (string) $img->getAttribute( 'src' );
				if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
					$src = (string) ( $img->getAttribute( 'data-src' ) ?: $img->getAttribute( 'data-lazy-src' ) );
				}
				$abs = self::absolute( $src, $home['url'] );
				if ( '' !== $abs && ! preg_match( '#\.svg(\?|$)#i', $abs ) ) {
					$srcs[ $abs ] = true;
				}
				if ( count( $srcs ) >= 15 ) {
					break;
				}
			}
		}
		$sizes = array();
		foreach ( array_keys( $srcs ) as $src ) {
			$head = wp_safe_remote_head( $src, array( 'timeout' => 6, 'redirection' => 3, 'user-agent' => self::UA ) );
			$len  = is_wp_error( $head ) ? 0 : (int) wp_remote_retrieve_header( $head, 'content-length' );
			if ( $len > 0 ) {
				$sizes[] = $len;
			}
		}
		if ( ! $sizes ) {
			return self::row( 'photos', $t, 'skip', __( 'We couldn\'t measure the photos on your home page this time, so photo sizes are left out of your score.', 'scout-sitecheck' ) );
		}
		$big = array_filter( $sizes, static fn( $s ) => $s > self::BIG_PHOTO );
		if ( ! $big ) {
			/* translators: %d: photos checked */
			return self::row( 'photos', $t, 'pass', sprintf( _n( 'The photo on your home page is under 500 KB, a good size for phones.', 'All %d photos on your home page are under 500 KB, a good size for phones.', count( $sizes ), 'scout-sitecheck' ), count( $sizes ) ) );
		}
		$text = sprintf(
			/* translators: 1: big photos, 2: photos checked, 3: size of the biggest */
			_n( '%1$d of %2$d photos on your home page is over 500 KB (the biggest is %3$s). Big photos are one of the most common reasons a site is slow on phones.', '%1$d of %2$d photos on your home page are over 500 KB (the biggest is %3$s). Big photos are one of the most common reasons a site is slow on phones.', count( $big ), 'scout-sitecheck' ),
			count( $big ),
			count( $sizes ),
			size_format( max( $big ), max( $big ) >= MB_IN_BYTES ? 1 : 0 )
		);
		return self::row( 'photos', $t, count( $big ) * 2 > count( $sizes ) ? 'fail' : 'warn', $text );
	}
}
