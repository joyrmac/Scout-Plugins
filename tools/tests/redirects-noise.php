<?php
// Scout Redirects: bot probes stay off the missing-page list, and a broken
// link only counts when the page really links there. Runs without WordPress:
//   php tools/tests/redirects-noise.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
define( 'SCOUT_REDIRECTS_VERSION', '9.9.9' );
$GLOBALS['opts']    = array( 'show_on_front' => 'page', 'page_on_front' => 2 );
$GLOBALS['filters'] = array();
$GLOBALS['content'] = array();
$GLOBALS['urls']    = array();
$GLOBALS['menus']   = array();
$GLOBALS['navs']    = array();
function add_action() {}
function add_filter( $tag, $cb ) { $GLOBALS['filters'][ $tag ][] = $cb; }
function remove_all_filters( $tag ) { unset( $GLOBALS['filters'][ $tag ] ); }
function apply_filters( $tag, $value, ...$args ) { foreach ( $GLOBALS['filters'][ $tag ] ?? array() as $cb ) { $value = $cb( $value, ...$args ); } return $value; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function wp_parse_url( $url, $part = -1 ) { return parse_url( $url, $part ); }
function home_url( $path = '' ) { return 'https://scoutraleigh.com/' . ltrim( (string) $path, '/' ); }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function trailingslashit( $s ) { return untrailingslashit( $s ) . '/'; }
function rest_get_url_prefix() { return 'wp-json'; }
function url_to_postid( $url ) { return $GLOBALS['urls'][ rtrim( (string) parse_url( $url, PHP_URL_PATH ), '/' ) ] ?? 0; }
function get_post_field( $field, $id ) { return $GLOBALS['content'][ $id ] ?? ''; }
function wp_get_nav_menus() { return array_map( static fn( $id ) => (object) array( 'term_id' => $id ), array_keys( $GLOBALS['menus'] ) ); }
function wp_get_nav_menu_items( $id ) { return array_map( static fn( $u ) => (object) array( 'url' => $u ), $GLOBALS['menus'][ $id ] ?? array() ); }
function get_posts( $args ) { return array_map( static fn( $c ) => (object) array( 'post_content' => $c ), $GLOBALS['navs'] ); }

/** The two tables, in memory. */
final class Scout_Redirects_Store {
	public static array $redirects = array();
	public static array $log = array();
	public static function count(): int { return count( self::$redirects ); }
	public static function find( string $source ): ?object { return in_array( $source, self::$redirects, true ) ? (object) array( 'source' => $source ) : null; }
	public static function log_has( string $path ): bool { foreach ( self::$log as $r ) { if ( $r->path === $path ) { return true; } } return false; }
	public static function log_list( int $limit, bool $internal_only = false ): array {
		$rows = array_values( array_filter( self::$log, static fn( $r ) => ! $internal_only || $r->internal ) );
		return array_slice( $rows, 0, $limit );
	}
	public static function log_delete( int $id ): void { unset( self::$log[ $id ] ); }
	public static function log_unflag( int $id ): void { self::$log[ $id ]->internal = 0; }
}
function logged( $id, $path, $hits, $referrer = '', $internal = 0, $last = '2026-11-04 12:00:00' ) {
	Scout_Redirects_Store::$log[ $id ] = (object) array( 'id' => $id, 'path' => $path, 'hits' => $hits, 'referrer' => $referrer, 'internal' => $internal, 'last_seen' => $last );
}
require __DIR__ . '/../../scout-redirects/includes/class-scout-redirects.php';
$R    = 'Scout_Redirects';
$site = 'https://scoutraleigh.com';

// The probes Joy saw on scoutraleigh.com (2026-11-05), plus the usual scanner guesses.
$probes = array(
	'/settings', '/api-docs', '/terraform.tfvars', '/actuator/env', '/actuator/configprops', '/wk', '/files',
	'/wk/index.php', '/files/index.php', '/sample-page', '/ckfinder/ckfinder.html', '/register', '/auth/login',
	'/forgot-password', '/actuator', '/api/session/properties', '/portal', '/onboarding', '/hello-world',
	'/.env', '/.git/config', '/.well-known/security.txt', '/wp-config.php.bak', '/config.yml', '/db.sql',
	'/backup.zip', '/debug.log', '/php.ini', '/phpmyadmin', '/cgi-bin/luci', '/server-status', '/console',
	'/swagger-ui', '/swagger.json', '/graphql', '/vendor/phpunit/phpunit', '/case-study-sitemap.xml',
	'/blog/hello-world', '/admin/settings', '/wp-login.php', '/wp-admin/install.php', '/favicon.ico',
);
foreach ( $probes as $p ) {
	ok( $R::is_noise( $p ), "noise: {$p}" );
}
foreach ( array( '/contact-us', '/privacy-policy', '/case-study/mccloud-aquatics', '/services', '/about.html', '/old-site/team.htm', '/.well-known/webfinger', '/registration-day', '/portfolio' ) as $p ) {
	ok( ! $R::is_noise( $p ), "a page someone may want: {$p}" );
}

// A site with a real /portal page takes that one entry off the list.
add_filter( 'scout_redirects_noise_patterns', static fn( $p ) => array_diff_key( $p, array( 'portal' => 1 ) ) );
ok( ! $R::is_noise( '/portal' ) && $R::is_noise( '/onboarding' ), 'the noise filter removes one entry and keeps the rest' );
remove_all_filters( 'scout_redirects_noise_patterns' );
ok( $R::is_noise( '/portal' ), 'without the filter /portal is noise again' );

// A path saved as a redirect's old address is never noise.
Scout_Redirects_Store::$redirects = array( '/register', '/services.aspx' );
ok( ! $R::is_noise( '/register' ), 'an existing redirect source is never dropped (/register)' );
ok( ! $R::is_noise( '/services.aspx' ), 'an existing redirect source is never dropped (/services.aspx from an old site)' );
ok( $R::is_noise( '/forgot-password' ), 'other probes stay noise while redirects exist' );
add_filter( 'scout_redirects_is_noise', static fn( $n, $p ) => true );
ok( ! $R::is_noise( '/register' ) && $R::is_noise( '/anything' ), 'a redirect source wins even over the is_noise filter' );
remove_all_filters( 'scout_redirects_is_noise' );
Scout_Redirects_Store::$redirects = array();

// The site: a home page, an About page, a classic menu and a block theme menu.
$GLOBALS['urls']    = array( '/about' => 5 );
$GLOBALS['content'] = array(
	2 => '<p>Welcome.</p><a href="/services/old-page/">Old page</a> <a href="/contact-us-now">Contact</a>'
		. '<!-- wp:button {"url":"https:\/\/scoutraleigh.com\/Old-Offer\/"} -->',
	5 => '<a href="https://www.scoutraleigh.com/our-story/?ref=nav#top">Story</a> <img src="/missing-photo-page">',
);
$GLOBALS['menus'] = array( 7 => array( 'https://scoutraleigh.com/team/', '/pricing' ) );
$GLOBALS['navs']  = array( '<!-- wp:navigation-link {"label":"Gone","url":"/gone/"} /-->' );

ok( ! $R::is_broken_link( '/terraform.tfvars', $site . '/' ), 'a faked "/ links to /terraform.tfvars" is not a broken link' );
ok( ! $R::is_broken_link( '/privacy-policy', $site . '/' ), '"/ links to /privacy-policy" is not a broken link when the home page has no such link' );
ok( ! $R::is_broken_link( '/settings', $site . '/' ), '"/ links to /settings" is not a broken link' );
ok( $R::is_broken_link( '/services/old-page', $site . '/' ), 'a real link in the home page content is a broken link' );
ok( $R::is_broken_link( '/old-offer', $site . '/' ), 'a button block address (absolute, capitals, closing slash) counts' );
ok( ! $R::is_broken_link( '/contact-us', $site . '/' ), 'a link to /contact-us-now does not count as a link to /contact-us' );
ok( $R::is_broken_link( '/our-story', $site . '/about/' ), 'an absolute www link with a query string on another page counts' );
ok( $R::is_broken_link( '/missing-photo-page', $site . '/about' ), 'a relative src link counts' );
ok( ! $R::is_broken_link( '/services/old-page', $site . '/about/' ), 'a link on the home page does not make About the source' );
ok( $R::is_broken_link( '/team', $site . '/about/' ), 'a menu item counts from any real page' );
ok( $R::is_broken_link( '/pricing', $site . '/' ), 'a relative menu item counts' );
ok( $R::is_broken_link( '/gone', $site . '/' ), 'a block theme navigation link counts' );
ok( ! $R::is_broken_link( '/contact-us', $site . '/contact-us' ), 'a self-referrer (/contact-us from /contact-us) is not a broken link' );
ok( ! $R::is_broken_link( '/team', $site . '/team/' ), 'a self-referrer never counts, even for a menu link' );
ok( ! $R::is_broken_link( '/case-study/mccloud-aquatics', $site . '/case-study-sitemap.xml' ), 'a sitemap referrer is not a broken link' );
ok( ! $R::is_broken_link( '/team', $site . '/case-study-sitemap.xml' ), 'a sitemap referrer never counts, even for a menu link' );
ok( ! $R::is_broken_link( '/wk', $site . '/wk/index.php' ), '"/wk/index.php links to /wk" is not a broken link' );
ok( ! $R::is_broken_link( '/files', $site . '/files/index.php' ), '"/files/index.php links to /files" is not a broken link' );
ok( ! $R::is_broken_link( '/team', 'https://www.scoutmediaconsulting.com/' ), 'a referrer on another site is not a broken link' );
ok( ! $R::is_broken_link( '/team', '' ), 'no referrer is not a broken link' );
logged( 90, '/old-blog', 3 );
ok( ! $R::is_broken_link( '/pricing', $site . '/old-blog' ), 'a referrer that is itself a missing page is not a broken link' );
unset( Scout_Redirects_Store::$log[90] );

// The once-per-version tidy: probes go, faked broken links become plain missing pages, real ones stay.
logged( 1, '/sample-page', 95 );
logged( 2, '/terraform.tfvars', 3, $site . '/', 1 );
logged( 3, '/privacy-policy', 6, $site . '/', 1 );
logged( 4, '/contact-us', 32, $site . '/contact-us', 1 );
logged( 5, '/case-study/mccloud-aquatics', 2, $site . '/case-study-sitemap.xml', 1 );
logged( 6, '/services/old-page', 4, $site . '/', 1 );
logged( 7, '/team', 1, $site . '/about', 1 );
logged( 8, '/old-brochure', 2, 'https://www.google.com/', 0, '2026-11-01 08:00:00' );
logged( 9, '/register', 42, 'https://www.scoutmediaconsulting.com/' );
$R::sweep_noise();
$log = Scout_Redirects_Store::$log;
ok( ! isset( $log[1], $log[2], $log[9] ), 'the sweep drops /sample-page, /terraform.tfvars and /register' );
ok( isset( $log[3], $log[4], $log[5] ) && ! $log[3]->internal && ! $log[4]->internal && ! $log[5]->internal, 'the sweep keeps faked broken links as plain missing pages' );
ok( $log[6]->internal && $log[7]->internal, 'the sweep keeps real broken links' );
ok( '9.9.9' === get_option( 'scout_redirects_noise_swept' ), 'the sweep is recorded for this version' );
Scout_Redirects_Store::$log[4]->internal = 1;
$R::sweep_noise();
ok( Scout_Redirects_Store::$log[4]->internal, 'the sweep runs once per version' );

// Site status and the screen read the same checked list.
$broken = array_map( static fn( $r ) => $r->path, $R::broken_links( 5 ) );
ok( array( '/services/old-page', '/team' ) === $broken, 'broken_links lists only real links' );
ok( ! Scout_Redirects_Store::$log[4]->internal, 'broken_links clears a faked one it finds' );
ok( array( '/services/old-page' ) === array_map( static fn( $r ) => $r->path, $R::broken_links( 1 ) ), 'broken_links stops at the limit' );

// Missing pages: search visits and real broken links first, then the rest by visits.
$order = array_map( static fn( $r ) => $r->path, $R::sort_missing( array_values( Scout_Redirects_Store::$log ) ) );
ok( array( '/services/old-page', '/old-brochure', '/team', '/contact-us', '/privacy-policy', '/case-study/mccloud-aquatics' ) === $order, 'real-looking missing pages sort first: ' . implode( ', ', $order ) );
ok( $R::is_search_engine( 'https://www.google.com/' ) && $R::is_search_engine( 'https://www.bing.com/search' ) && $R::is_search_engine( 'https://duckduckgo.com/' ) && ! $R::is_search_engine( 'https://www.scoutmediaconsulting.com/' ) && ! $R::is_search_engine( 'https://notgoogle.example/' ), 'search engines are told apart from other sites' );
ok( $R::is_home_url( $site . '/x' ) && $R::is_home_url( 'https://www.scoutraleigh.com/' ) && ! $R::is_home_url( 'https://scoutraleigh.com.evil.test/' ) && ! $R::is_home_url( '/x' ), 'this site is recognized with or without www, and only this site' );

echo $fail ? "\n{$fail} failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
