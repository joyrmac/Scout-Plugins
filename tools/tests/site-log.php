<?php
// Scout Build 0.8.0's Site log: the entry cleaner, the changelog headline
// reader, and the history import. No WordPress needed:
//   php tools/tests/site-log.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['posts'] = array();
$GLOBALS['meta']  = array();
function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return 1 === $n ? $a : $b; }
function get_current_user_id() { return 0; }
function wp_get_current_user() { return null; }
function get_gmt_from_date( $d ) { return $d; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_insert_post( $post, $e = false ) { $id = count( $GLOBALS['posts'] ) + 1; $GLOBALS['posts'][ $id ] = $post; return $id; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function get_posts( $args ) {
	$out = array();
	foreach ( $GLOBALS['meta'] as $id => $m ) {
		if ( ( $m[ $args['meta_key'] ] ?? null ) === $args['meta_value'] ) { $out[] = $id; }
	}
	return $out;
}
class WP_Error { public function __construct( public $code = '', public $message = '' ) {} public function get_error_message() { return $this->message; } }

require __DIR__ . '/../../scout-build/includes/class-scout-hub-log.php';

$fails = 0;
function ok( $cond, $what ) { global $fails; if ( $cond ) { echo "ok   $what\n"; } else { $fails++; echo "FAIL $what\n"; } }

// normalize(): unknown values fall back, text is trimmed, the date gets a time.
$e = Scout_Hub_Log::normalize( array( 'title' => "  Theme 1.1.8: faster hero \x07", 'kind' => 'bogus', 'topic' => 'SPEED', 'status' => 'nope', 'date' => '2026-10-16' ) );
ok( 'Theme 1.1.8: faster hero' === $e['title'], 'title trimmed and control characters removed' );
ok( 'note' === $e['kind'] && 'speed' === $e['topic'] && 'done' === $e['status'], 'unknown kind and status fall back, topic lower-cased' );
ok( '2026-10-16 12:00:00' === $e['date'], 'a bare date gets a midday time' );
ok( true === $e['show_client'], 'a note is shown to the client by default' );
ok( false === Scout_Hub_Log::normalize( array( 'title' => 'x', 'kind' => 'alert' ) )['show_client'], 'an alert is not shown by default' );
ok( false === Scout_Hub_Log::normalize( array( 'title' => 'x', 'show_client' => 'no' ) )['show_client'], 'show_client "no" reads as false' );
ok( null === Scout_Hub_Log::normalize( array( 'title' => '   ' ) ), 'no title, no entry' );
ok( '' === Scout_Hub_Log::normalize( array( 'title' => 'x', 'date' => 'yesterday' ) )['date'], 'a date that is not a date is dropped' );

// changelog_line(): the bold headline of the version's first bullet.
$tmp = tempnam( sys_get_temp_dir(), 'cl' );
file_put_contents( $tmp, "# Scout Build changelog\n\n## 0.7.11 (2026-11-04)\n\n- **Theme updates keep downloading after a repo moves or is renamed.** A theme whose Update URI...\n- **Second thing.**\n\n## 0.7.10 (2026-11-02)\n\n- Plain bullet with no bold. Second sentence here.\n" );
ok( 'Theme updates keep downloading after a repo moves or is renamed' === Scout_Hub_Log::changelog_line( $tmp, '0.7.11' ), 'bold headline read, trailing period dropped' );
ok( 'Plain bullet with no bold' === Scout_Hub_Log::changelog_line( $tmp, '0.7.10' ), 'without bold, the first sentence' );
ok( '' === Scout_Hub_Log::changelog_line( $tmp, '0.7.9' ), 'a version not in the file gives nothing' );
ok( '' === Scout_Hub_Log::changelog_line( '/nowhere/CHANGELOG.md', '1.0.0' ), 'a missing file gives nothing' );
unlink( $tmp );

// is_scout_plugin(): by Update URI, either owner.
ok( Scout_Hub_Log::is_scout_plugin( array( 'UpdateURI' => 'https://github.com/Scout-Media-Raleigh/Scout-Plugins/scout-reviews' ) ), 'new organization counts' );
ok( Scout_Hub_Log::is_scout_plugin( array( 'UpdateURI' => 'https://github.com/joyrmac/Scout-Plugins/scout-reviews' ) ), 'old owner counts' );
ok( ! Scout_Hub_Log::is_scout_plugin( array( 'UpdateURI' => 'https://gravityforms.com' ) ), 'a third-party plugin does not' );

// import(): every real history file loads, and a second run adds nothing.
$files = glob( __DIR__ . '/../../docs/site-log-import/*.json' );
ok( 5 === count( $files ), 'five history files' );
$total = 0;
foreach ( $files as $file ) {
	$data = json_decode( file_get_contents( $file ), true );
	ok( is_array( $data ) && ! empty( $data['entries'] ), basename( $file ) . ' is valid JSON with entries' );
	$r = Scout_Hub_Log::import( $data );
	ok( $r['added'] === count( $data['entries'] ) && 0 === $r['skipped'], basename( $file ) . ': every line added (' . $r['added'] . ')' );
	$again = Scout_Hub_Log::import( $data );
	ok( 0 === $again['added'] && $again['skipped'] === count( $data['entries'] ), basename( $file ) . ': second import adds nothing' );
	$total += $r['added'];
}
ok( 75 === $total, '75 lines in all (43 rows, the 8 for every site copied to 5 sites)' );
$money = 0;
foreach ( $GLOBALS['posts'] as $p ) { if ( preg_match( '/\$\d/', $p['post_title'] . $p['post_content'] ) ) { $money++; } }
ok( 0 === $money, 'no dollar figures in the imported lines' );
$err = Scout_Hub_Log::import( array( 'site' => 'x' ) );
ok( is_wp_error( $err ), 'a file with no entries is refused' );

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit( $fails ? 1 : 0 );
