<?php
// Site Standard rule 24: WordPress's own export and erase tools (Tools > Export
// Personal Data, Erase Personal Data) reach every place a Scout plugin keeps a
// person's details. Runs without WordPress:
//   php tools/tests/privacy-tools.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
$root = dirname( __DIR__, 2 );
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

/* ---------- which plugins must have privacy tools ---------- */

// Plugins that keep a person's details where an email can find them.
$must = array( 'scout-booking', 'scout-build', 'scout-chat', 'scout-events', 'scout-members', 'scout-newsletter', 'scout-sitecheck', 'scout-team' );
// Plugins that keep something in the site's database but nothing an email can reach, and why.
$exempt = array(
	'scout-core'      => 'search words only, with nothing about who searched',
	'scout-crm'       => 'FluentCRM holds the contacts',
	'scout-law'       => 'Gravity Forms holds the entries',
	'scout-partners'  => 'monthly click totals only',
	'scout-redirects' => 'missing page addresses only',
	'scout-reviews'   => 'the note form asks for no email',
);
foreach ( glob( $root . '/scout-*/visitor-data.json' ) as $file ) {
	$slug = basename( dirname( $file ) );
	$data = json_decode( (string) file_get_contents( $file ), true );
	$kept = false;
	foreach ( (array) ( $data['items'] ?? array() ) as $i ) {
		$kept = $kept || ( false !== stripos( $i['where'], "this website's database" ) && false === stripos( $i['where'], 'temporary record' ) );
	}
	if ( $kept ) {
		ok( in_array( $slug, $must, true ) || isset( $exempt[ $slug ] ), "$slug keeps details in the database, so it has privacy tools or a stated reason it cannot" );
	}
}
foreach ( $must as $slug ) {
	$src   = '';
	$files = array_merge( array( "$root/$slug/$slug.php" ), glob( "$root/$slug/includes/*.php" ) );
	$main  = (string) file_get_contents( "$root/$slug/$slug.php" );
	$wired = true;
	foreach ( $files as $f ) {
		$text = (string) file_get_contents( $f );
		$src .= $text;
		if ( false !== strpos( $text, "'wp_privacy_personal_data_exporters'" ) && preg_match( '/^(?:final )?class (\w+)/m', $text, $m ) ) {
			$wired = $wired && ( false !== strpos( $main, $m[1] . '::boot()' ) || false !== strpos( $main, "'" . $m[1] . "', 'boot'" ) );
		}
	}
	$ex = substr_count( $src, "add_filter( 'wp_privacy_personal_data_exporters'" );
	$er = substr_count( $src, "add_filter( 'wp_privacy_personal_data_erasers'" );
	ok( $ex > 0 && $ex === $er, "$slug registers an exporter and an eraser for each kind of record ($ex and $er)" );
	ok( $wired, "$slug boots every class that registers them" );
}

/* ---------- WordPress stubs ---------- */

$GLOBALS['filters'] = array();
$GLOBALS['posts']   = array(); // id => array( 'post' => object, 'meta' => array )
$GLOBALS['users']   = array(); // id => object with ->ID, ->user_email, ->meta
$GLOBALS['opts']    = array( 'scout_newsletter_db' => '1' );
$GLOBALS['gone']    = array(); // deleted transients
$GLOBALS['orders']  = array();
function add_filter( $hook, $cb ) { $GLOBALS['filters'][ $hook ][] = $cb; }
function add_action() {}
function __( $s ) { return $s; }
function _n( $one, $many, $n ) { return 1 === (int) $n ? $one : $many; }
function wp_privacy_anonymize_data( $type ) { return '[deleted]'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_salt( $k = '' ) { return 'salt-' . $k; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function sanitize_email( $e ) { return trim( (string) $e ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_textarea_field( $s ) { return trim( (string) $s ); }
function esc_url_raw( $u ) { return trim( (string) $u ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( $n, $d ); }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function delete_transient( $k ) { $GLOBALS['gone'][] = $k; return true; }
function wp_clear_scheduled_hook() {}
function current_time() { return '2026-11-05 10:00:00'; }
function wp_date( $f, $t = null ) { return gmdate( $f, $t ?? time() ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function apply_filters( $h, $v ) { return $v; }

function make_post( $type, $title, $content = '', array $meta = array(), $status = 'private', $author = 0 ) {
	static $next = 100;
	$id                      = $next++;
	$GLOBALS['posts'][ $id ] = array(
		'post' => (object) array( 'ID' => $id, 'post_type' => $type, 'post_title' => $title, 'post_content' => $content, 'post_status' => $status, 'post_author' => $author, 'post_date' => '2026-11-01 09:00:00' ),
		'meta' => $meta,
	);
	return $id;
}
function get_posts( $q ) {
	$out = array();
	foreach ( $GLOBALS['posts'] as $id => $p ) {
		$o = $p['post'];
		if ( isset( $q['post_type'] ) && $o->post_type !== $q['post_type'] ) {
			continue;
		}
		$st = (array) ( $q['post_status'] ?? array( 'publish' ) );
		if ( ! in_array( 'any', $st, true ) && ! in_array( $o->post_status, $st, true ) ) {
			continue;
		}
		if ( in_array( 'any', $st, true ) && 'trash' === $o->post_status && ! in_array( 'trash', $st, true ) ) {
			continue;
		}
		if ( isset( $q['meta_key'] ) && ( ! isset( $p['meta'][ $q['meta_key'] ] ) || ( isset( $q['meta_value'] ) && strtolower( (string) $p['meta'][ $q['meta_key'] ] ) !== strtolower( (string) $q['meta_value'] ) ) ) ) {
			continue;
		}
		if ( isset( $q['s'] ) && false === stripos( $o->post_title . ' ' . $o->post_content, $q['s'] ) ) {
			continue;
		}
		$out[] = $o;
	}
	$per = (int) ( $q['posts_per_page'] ?? 5 );
	if ( $per > 0 ) {
		$out = array_slice( $out, ( max( 1, (int) ( $q['paged'] ?? 1 ) ) - 1 ) * $per, $per );
	}
	return 'ids' === ( $q['fields'] ?? '' ) ? array_map( static fn( $o ) => $o->ID, $out ) : $out;
}
function get_post( $id ) { return $GLOBALS['posts'][ $id ]['post'] ?? null; }
function get_the_title( $id ) { return $GLOBALS['posts'][ $id ]['post']->post_title ?? 'Event ' . $id; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['posts'][ $id ]['meta'][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['posts'][ $id ]['meta'][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['posts'][ $id ]['meta'][ $k ] ); return true; }
function wp_delete_post( $id, $force = false ) { $p = $GLOBALS['posts'][ $id ]['post'] ?? null; unset( $GLOBALS['posts'][ $id ] ); return $p; }
function wp_update_post( $a ) { foreach ( $a as $k => $v ) { if ( 'ID' !== $k ) { $GLOBALS['posts'][ $a['ID'] ]['post']->$k = $v; } } return $a['ID']; }
function make_user( $id, $email, array $meta = array() ) { $GLOBALS['users'][ $id ] = (object) array( 'ID' => $id, 'user_email' => $email, 'meta' => $meta ); }
function get_user_by( $f, $v ) { foreach ( $GLOBALS['users'] as $u ) { if ( strtolower( $u->user_email ) === strtolower( (string) $v ) ) { return $u; } } return false; }
function get_user_meta( $id, $k, $single = false ) { return $GLOBALS['users'][ $id ]->meta[ $k ] ?? ''; }
function delete_user_meta( $id, $k ) { unset( $GLOBALS['users'][ $id ]->meta[ $k ] ); return true; }

/** A small stand-in for $wpdb: one table per name, rows as arrays. */
final class Fake_DB {
	public $prefix = 'wp_';
	public array $t = array();
	public function prepare( $sql, ...$args ) {
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) { $v = $args[ $i++ ]; return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'"; }, $sql );
	}
	public function get_results( $sql, $mode = null ) {
		if ( ! preg_match( "/^SELECT (.+?) FROM (\S+) WHERE (\w+) = (?:'((?:[^'\\\\]|\\\\.)*)'|(\d+))( ORDER BY id ASC)?$/", trim( $sql ), $m ) ) {
			throw new RuntimeException( "Fake_DB cannot read: $sql" );
		}
		$want = '' !== ( $m[4] ?? '' ) ? stripslashes( $m[4] ) : (string) ( $m[5] ?? '' );
		$out  = array();
		foreach ( $this->t[ $m[2] ] ?? array() as $row ) {
			if ( strtolower( (string) $row[ $m[3] ] ) === strtolower( $want ) ) {
				$out[] = '*' === $m[1] ? $row : array_intersect_key( $row, array_flip( array_map( 'trim', explode( ',', $m[1] ) ) ) );
			}
		}
		return $out;
	}
	public function update( $table, $data, $where ) {
		$n = 0;
		foreach ( $this->t[ $table ] ?? array() as $k => $row ) {
			if ( array_intersect_assoc( array_map( 'strval', $where ), array_map( 'strval', $row ) ) == array_map( 'strval', $where ) ) {
				$this->t[ $table ][ $k ] = array_merge( $row, $data );
				$n++;
			}
		}
		return $n;
	}
	public function delete( $table, $where ) {
		$n = 0;
		foreach ( $this->t[ $table ] ?? array() as $k => $row ) {
			if ( array_intersect_assoc( array_map( 'strval', $where ), array_map( 'strval', $row ) ) == array_map( 'strval', $where ) ) {
				unset( $this->t[ $table ][ $k ] );
				$n++;
			}
		}
		return $n;
	}
}
$GLOBALS['wpdb'] = new Fake_DB();
$db              = $GLOBALS['wpdb'];

/** A WooCommerce order item and order, only as far as forget_holder() reads them. */
final class Fake_Item {
	public array $meta;
	public bool $saved = false;
	public function __construct( array $meta ) { $this->meta = $meta; }
	public function get_meta( $k ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function save() { $this->saved = true; }
}
final class Fake_Order {
	public array $items;
	public function __construct( array $items ) { $this->items = $items; }
	public function get_items() { return $this->items; }
}
function wc_get_order( $id ) { return $GLOBALS['orders'][ $id ] ?? false; }

foreach ( array(
	'scout-booking/includes/class-scout-booking.php',
	'scout-build/includes/class-scout-hub-requests.php',
	'scout-build/includes/class-scout-hub-log.php',
	'scout-build/includes/class-scout-hub-privacy.php',
	'scout-chat/includes/class-scout-chat.php',
	'scout-chat/includes/class-scout-chat-privacy.php',
	'scout-events/includes/class-scout-events.php',
	'scout-events/includes/class-scout-events-presenters.php',
	'scout-events/includes/class-scout-events-rsvp.php',
	'scout-events/includes/class-scout-events-tickets.php',
	'scout-events/includes/class-scout-events-vendors.php',
	'scout-members/includes/class-scout-members.php',
	'scout-members/includes/class-scout-members-admin.php',
	'scout-newsletter/includes/class-scout-newsletter.php',
	'scout-newsletter/includes/class-scout-newsletter-store.php',
	'scout-newsletter/includes/class-scout-newsletter-privacy.php',
	'scout-sitecheck/includes/class-scout-sitecheck.php',
	'scout-sitecheck/includes/class-scout-sitecheck-privacy.php',
) as $f ) {
	require $root . '/' . $f;
}

/** Each new class adds both filters when booted, and each callback names itself. */
foreach ( array( 'Scout_Chat_Privacy', 'Scout_Sitecheck_Privacy', 'Scout_Newsletter_Privacy', 'Scout_Hub_Privacy' ) as $class ) {
	$GLOBALS['filters'] = array();
	$class::boot();
	$ex = $class::exporter( array() );
	$er = $class::eraser( array() );
	ok( 1 === count( $GLOBALS['filters']['wp_privacy_personal_data_exporters'] ?? array() ) && 1 === count( $GLOBALS['filters']['wp_privacy_personal_data_erasers'] ?? array() ), "$class adds one exporter and one eraser" );
	ok( array_keys( $ex ) === array_keys( $er ) && is_callable( reset( $ex )['callback'] ) && is_callable( reset( $er )['callback'] ) && '' !== reset( $ex )['exporter_friendly_name'], "$class names its exporter and eraser the same, with working callbacks" );
}
$ex = Scout_Events_Presenters::exporter( array() );
ok( isset( $ex['scout-events-presenters'], Scout_Events_Presenters::eraser( array() )['scout-events-presenters'] ), 'Events presenters have an exporter and an eraser' );

/** Shared checks on an export: one item per record, all for this person, none for someone else. */
function export_ok( $label, $cb, $email, $other, $count, $must_hold ) {
	$mine  = call_user_func( $cb, $email, 1 );
	$their = call_user_func( $cb, $other, 1 );
	$flat  = json_encode( $mine['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	ok( $count === count( $mine['data'] ) && ! empty( $mine['done'] ), "$label: export finds $count record(s) for the email and says it is done" );
	ok( false !== strpos( $flat, $must_hold ), "$label: export carries the person's details ($must_hold)" );
	ok( array() === $their['data'], "$label: export finds nothing for another email" );
	foreach ( $mine['data'] as $item ) {
		ok( '' !== $item['group_id'] && '' !== $item['group_label'] && '' !== $item['item_id'] && is_array( $item['data'] ), "$label: item {$item['item_id']} has a group, a label and an id" );
	}
}

/* ---------- Scout Chat ---------- */
$c1 = make_post( 'scout_chat_q', 'Question: hours', "Ana: What are your hours?\n\nAssistant: Nine to five.", array( '_scout_chat_name' => 'Ana', '_scout_chat_email' => 'ana@example.com', '_scout_chat_phone' => '19195550100', '_scout_chat_page' => 'https://site.test/' ) );
$c2 = make_post( 'scout_chat_q', 'Question: parking', 'Ana: Parking?', array( '_scout_chat_name' => 'Ana', '_scout_chat_email' => 'Ana@Example.com' ), 'trash' );
$c3 = make_post( 'scout_chat_q', 'Question: price', 'Bo: Price?', array( '_scout_chat_name' => 'Bo', '_scout_chat_email' => 'bo@example.com' ) );
export_ok( 'Chat', array( 'Scout_Chat_Privacy', 'export_data' ), 'ana@example.com', 'nobody@example.com', 2, 'What are your hours?' );
$r = Scout_Chat_Privacy::erase_data( 'ana@example.com' );
ok( $r['items_removed'] && ! isset( $GLOBALS['posts'][ $c1 ] ) && ! isset( $GLOBALS['posts'][ $c2 ] ) && isset( $GLOBALS['posts'][ $c3 ] ), 'Chat: erase deletes this person\'s messages, trashed ones too, and keeps everyone else\'s' );
ok( array() === Scout_Chat_Privacy::export_data( 'ana@example.com' )['data'], 'Chat: nothing left to export after the erase' );

/* ---------- Scout Site Check ---------- */
$s1 = make_post( 'scout_sitecheck', 'cafe.test', '', array( '_ssc_name' => 'Cy', '_ssc_email' => 'cy@example.com', '_ssc_url' => 'https://cafe.test/', '_ssc_total' => 10, '_ssc_passed' => 7 ) );
$s2 = make_post( 'scout_sitecheck', 'shop.test', '', array( '_ssc_name' => 'Di', '_ssc_email' => 'di@example.com', '_ssc_url' => 'https://shop.test/' ) );
export_ok( 'Site check', array( 'Scout_Sitecheck_Privacy', 'export_data' ), 'cy@example.com', 'nobody@example.com', 1, 'https://cafe.test/' );
$r = Scout_Sitecheck_Privacy::erase_data( 'cy@example.com' );
ok( $r['items_removed'] && ! isset( $GLOBALS['posts'][ $s1 ] ) && isset( $GLOBALS['posts'][ $s2 ] ), 'Site check: erase deletes this person\'s check request and keeps the others' );
ok( in_array( 'scout_ssc_' . md5( 'emcy@example.com' . wp_salt( 'nonce' ) ), $GLOBALS['gone'], true ), 'Site check: erase clears the per-email check count' );

/* ---------- Scout Newsletter ---------- */
$nt = Scout_Newsletter_Store::table();
$db->t[ $nt ] = array(
	array( 'id' => 1, 'kind' => 'optin', 'email' => 'eve@example.com', 'first_name' => 'Eve', 'source' => 'event', 'tags' => '["Event"]', 'fields' => '{"Last name":"Lee"}', 'wording' => 'Send me the newsletter', 'url' => 'https://site.test/e/', 'campaign' => 'summit', 'created' => '2026-10-01 10:00:00', 'status' => 'sent', 'sub_id' => 'sub_1', 'result' => 'ok', 'updated' => '' ),
	array( 'id' => 2, 'kind' => 'optin', 'email' => 'eve@example.com', 'first_name' => 'Eve', 'source' => 'form', 'tags' => '[]', 'fields' => '[]', 'wording' => 'Yes please', 'url' => '', 'campaign' => '', 'created' => '2026-10-02 10:00:00', 'status' => 'queued', 'sub_id' => '', 'result' => '', 'updated' => '' ),
	array( 'id' => 3, 'kind' => 'optin', 'email' => 'fay@example.com', 'first_name' => 'Fay', 'source' => 'form', 'tags' => '[]', 'fields' => '[]', 'wording' => 'Yes please', 'url' => '', 'campaign' => '', 'created' => '2026-10-02 10:00:00', 'status' => 'sent', 'sub_id' => '', 'result' => '', 'updated' => '' ),
);
export_ok( 'Newsletter', array( 'Scout_Newsletter_Privacy', 'export_data' ), 'eve@example.com', 'nobody@example.com', 2, 'Send me the newsletter' );
$r    = Scout_Newsletter_Privacy::erase_data( 'eve@example.com' );
$rows = array_values( $db->t[ $nt ] );
ok( 3 === count( $rows ) && $r['items_removed'] && $r['items_retained'] && $r['messages'], 'Newsletter: erase keeps every consent record and says so' );
ok( 'erased:' . Scout_Newsletter::hash( 'eve@example.com' ) === $rows[0]['email'] && '' === $rows[0]['first_name'] && '[]' === $rows[0]['fields'] && '' === $rows[0]['sub_id'], 'Newsletter: the email becomes a one-way code and the name, fields and Beehiiv id go' );
ok( 'Send me the newsletter' === $rows[0]['wording'] && '2026-10-01 10:00:00' === $rows[0]['created'] && 'sent' === $rows[0]['status'], 'Newsletter: the time, words and outcome stay as proof of the yes' );
ok( 'skipped' === $rows[1]['status'], 'Newsletter: a sign-up still waiting to send is stopped' );
ok( 'fay@example.com' === $rows[2]['email'] && 'Fay' === $rows[2]['first_name'], 'Newsletter: other people\'s records are untouched' );

/* ---------- Scout Build change requests ---------- */
$q1 = make_post( 'scout_hub_request', 'Fix the hours', "Fix the hours on the contact page\n\nFrom the site chat: Gus Hart <gus@example.com> (919) 555-0101", array( '_scout_hub_status' => 'received', '_scout_hub_page' => 'https://site.test/contact/' ) );
$q2 = make_post( 'scout_hub_request', 'New photo', 'New photo please', array(), 'private', 7 ); // A staff request, from the dashboard.
$l1 = make_post( 'scout_hub_log', 'You asked: Fix the hours', '', array( '_scout_log_ref' => 'request:' . $q1, '_scout_log_source' => 'Gus Hart' ) );
export_ok( 'Change requests', array( 'Scout_Hub_Privacy', 'export_data' ), 'gus@example.com', 'nobody@example.com', 1, 'Fix the hours on the contact page' );
$r = Scout_Hub_Privacy::erase_data( 'gus@example.com' );
$text = $GLOBALS['posts'][ $q1 ]['post']->post_content;
ok( $r['items_retained'] && false === strpos( $text, 'gus@example.com' ) && false === strpos( $text, 'Gus' ) && false === strpos( $text, '555-0101' ), 'Change requests: erase takes the name, email and phone out of the request' );
ok( false !== strpos( $text, "Fix the hours on the contact page\n\nFrom the site chat: [deleted]" ), 'Change requests: the request itself stays as a work item' );
ok( 'Scout Chat' === $GLOBALS['posts'][ $l1 ]['meta']['_scout_log_source'], 'Change requests: the Site log line no longer names the person' );
ok( 'New photo please' === $GLOBALS['posts'][ $q2 ]['post']->post_content, 'Change requests: staff requests are untouched' );

/* ---------- Scout Booking ---------- */
$bt = Scout_Booking::table();
$booking = static fn( $id, $email, $paid, $order ) => array( 'id' => $id, 'token' => 'tok' . $id, 'type_id' => 't1', 'type_name' => 'Intro session', 'start_at' => '2026-12-01 10:00:00', 'end_at' => '2026-12-01 11:00:00', 'block_end' => '2026-12-01 11:00:00', 'status' => 'confirmed', 'first_name' => 'Hal', 'last_name' => 'Ito', 'email' => $email, 'phone' => '9195550102', 'answers' => '[{"q":"Goals","a":"Relax"}]', 'note' => '', 'price' => '80.00', 'due' => '80.00', 'paid' => $paid, 'order_id' => $order, 'optin' => 1, 'hold_until' => null, 'reminded' => null, 'created' => '', 'updated' => '' );
$db->t[ $bt ] = array( $booking( 1, 'hal@example.com', '0.00', 0 ), $booking( 2, 'hal@example.com', '80.00', 55 ), $booking( 3, 'ivy@example.com', '0.00', 0 ) );
export_ok( 'Booking', array( 'Scout_Booking', 'export_data' ), 'hal@example.com', 'nobody@example.com', 2, 'Relax' );
$r    = Scout_Booking::erase_data( 'hal@example.com' );
$rows = array_values( $db->t[ $bt ] );
ok( 2 === count( $rows ) && 2 === $rows[0]['id'] && 3 === $rows[1]['id'], 'Booking: erase deletes the unpaid booking and keeps the paid one' );
ok( '[deleted]' === $rows[0]['first_name'] && '' === $rows[0]['email'] && '' === $rows[0]['phone'] && '[]' === $rows[0]['answers'] && '80.00' === $rows[0]['paid'] && 55 === $rows[0]['order_id'], 'Booking: the paid booking keeps its amounts and order number without the person\'s details' );
ok( $r['items_retained'] && $r['messages'], 'Booking: erase says a paid booking was kept' );

/* ---------- Scout Events: sign-ups and paid tickets ---------- */
$et = Scout_Events_RSVP::table();
$signup = static fn( $id, $email, $name, $order = 0, $item = 0 ) => array( 'id' => $id, 'event_id' => 9, 'user_id' => 4, 'name' => $name, 'email' => $email, 'guests' => 0, 'status' => 'going', 'code' => 'c' . $id, 'created' => '2026-10-01', 'checked_in' => null, 'checked_in_by' => 0, 'reminded' => null, 'source' => $order ? 'shop' : 'signup', 'order_id' => $order, 'item_id' => $item, 'product_id' => 0, 'agreed' => '2026-10-01', 'signature' => $name, 'waiver_version' => 'v2', 'age_ok' => 1, 'photo' => 'yes', 'newsletter' => null );
$db->t[ $et ] = array(
	$signup( 1, 'jo@example.com', 'Jo Kim' ),                 // Free sign-up.
	$signup( 2, 'jo@example.com', 'Jo Kim', 77, 501 ),         // Jo's own paid ticket.
	$signup( 3, 'jo@example.com', 'Kai Friend', 77, 501 ),     // Bought by Jo for a friend who gave no email.
	$signup( 4, 'lu@example.com', 'Lu Park', 77, 501 ),        // A friend with her own email.
);
$item = new Fake_Item(
	array(
		'_scout_attendees' => array(
			array( 'name' => 'Jo Kim', 'email' => 'jo@example.com', 'signature' => 'Jo Kim', 'waiver_version' => 'v2' ),
			array( 'name' => 'Kai Friend', 'email' => '', 'signature' => 'Kai Friend', 'waiver_version' => 'v2' ),
			array( 'name' => 'Lu Park', 'email' => 'lu@example.com', 'signature' => 'Lu Park', 'waiver_version' => 'v2' ),
		),
	)
);
$GLOBALS['orders'][77] = new Fake_Order( array( 501 => $item ) );
export_ok( 'Event sign-ups', array( 'Scout_Events_RSVP', 'export_data' ), 'jo@example.com', 'nobody@example.com', 3, '#77' );
$r    = Scout_Events_RSVP::erase_data( 'jo@example.com' );
$rows = array_values( $db->t[ $et ] );
ok( 3 === count( $rows ) && 2 === $rows[0]['id'], 'Event sign-ups: erase deletes the free sign-up and keeps the paid tickets' );
ok( '[deleted]' === $rows[0]['name'] && '' === $rows[0]['email'] && '' === $rows[0]['signature'] && 0 === $rows[0]['user_id'] && 77 === $rows[0]['order_id'] && 'v2' === $rows[0]['waiver_version'], 'Event sign-ups: a kept paid ticket loses the name, email, signature and account, and keeps its order and waiver version' );
ok( 'Lu Park' === $rows[2]['name'] && 'lu@example.com' === $rows[2]['email'], 'Event sign-ups: another person on the same order keeps her ticket as it was' );
$people = $item->meta['_scout_attendees'];
ok( $item->saved && '[deleted]' === $people[0]['name'] && '' === $people[0]['signature'] && '[deleted]' === $people[1]['name'], 'Event tickets: the order\'s holder list loses the buyer and the holder bought under the buyer\'s email' );
ok( 'Lu Park' === $people[2]['name'] && 'lu@example.com' === $people[2]['email'], 'Event tickets: a holder with her own email stays on the order' );
ok( $r['items_retained'] && $r['messages'], 'Event sign-ups: erase says paid tickets were kept' );

/* ---------- Scout Events: presenters ---------- */
make_user( 11, 'mo@example.com' );
$sp = make_post( 'scout_speaker', 'Mo Reyes', '<p>Teaches breathwork.</p>', array( '_scout_speaker_user' => 11, '_scout_speaker' => array( 'role' => 'Breath coach', 'short' => 'Mo teaches breath.' ), '_scout_speaker_private' => array( 'phone' => '9195550103', 'needs' => 'Mats', 'photo_release' => 1 ), '_scout_speaker_pending' => array( 'name' => 'Mo R.', 'short' => 'New bio', 'sent' => '2026-10-03' ) ), 'publish' );
$sp2 = make_post( 'scout_speaker', 'Nia Shaw', '', array( '_scout_speaker' => array( 'role' => 'Healer', 'email' => 'nia@example.com' ), '_scout_speaker_private' => array( 'phone' => '9195550104' ) ), 'pending' );
$sp3 = make_post( 'scout_speaker', 'Oz Lin', '', array( '_scout_speaker_private' => array( 'phone' => '9195550105' ) ), 'pending' );
export_ok( 'Presenters', array( 'Scout_Events_Presenters', 'export_data' ), 'mo@example.com', 'nobody@example.com', 1, '9195550103' );
export_ok( 'Presenters (public contact email)', array( 'Scout_Events_Presenters', 'export_data' ), 'nia@example.com', 'nobody@example.com', 1, '9195550104' );
$r = Scout_Events_Presenters::erase_data( 'mo@example.com' );
ok( $r['items_removed'] && ! isset( $GLOBALS['posts'][ $sp ]['meta']['_scout_speaker_private'] ) && ! isset( $GLOBALS['posts'][ $sp ]['meta']['_scout_speaker_pending'] ), 'Presenters: erase removes the private details and waiting changes' );
ok( isset( $GLOBALS['posts'][ $sp ] ) && $r['items_retained'] && 2 === count( $r['messages'] ), 'Presenters: the public profile and login stay, and the eraser says so' );
ok( isset( $GLOBALS['posts'][ $sp3 ]['meta']['_scout_speaker_private'] ) && isset( $GLOBALS['posts'][ $sp2 ]['meta']['_scout_speaker_private'] ), 'Presenters: other presenters keep their details' );

/* ---------- Scout Events: vendors ---------- */
for ( $v = 0; $v < 205; $v++ ) {
	make_post( 'scout_vendor', 'Stall ' . $v, '', array( '_scout_vendor_private' => array( 'contact' => 'Seller ' . $v, 'email' => 'seller' . $v . '@example.com' ) ), 'publish' );
}
export_ok( 'Vendors', array( 'Scout_Events_Vendors', 'export_data' ), 'seller204@example.com', 'nobody@example.com', 1, 'Seller 204' );
$r = Scout_Events_Vendors::erase_data( 'seller204@example.com' );
ok( $r['items_removed'] && $r['items_retained'] && $r['messages'], 'Vendors: a vendor past the first 200 is found, its contact details go and the listing stays' );

/* ---------- Scout Members ---------- */
make_user( 12, 'pat@example.com', array( '_scout_member_status' => 'active', '_scout_member' => array( 'plan' => 'club', 'note' => 'Prefers texts', 'answers' => array( 'City' => 'Raleigh' ) ), '_scout_member_review' => array( 'on' => '2026-10-04', 'reason' => 'Paid twice' ) ) );
export_ok( 'Members', array( 'Scout_Members_Admin', 'export_data' ), 'pat@example.com', 'nobody@example.com', 1, 'Prefers texts' );
ok( false !== strpos( json_encode( Scout_Members_Admin::export_data( 'pat@example.com' ) ), 'Paid twice' ), 'Members: export includes a payment flagged for review' );
$r = Scout_Members_Admin::erase_data( 'pat@example.com' );
ok( $r['items_removed'] && ! isset( $GLOBALS['users'][12]->meta['_scout_member'] ) && $r['messages'], 'Members: erase removes the record and says the login stays' );

echo $fail ? "\n$fail FAILED\n" : "\nAll privacy tool checks passed\n";
exit( $fail ? 1 : 0 );
