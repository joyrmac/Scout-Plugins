<?php
// Scout Newsletter: consent records, the queue and the Beehiiv calls, with the HTTP layer
// answered by the pre_http_request filter. Nothing reaches Beehiiv. Run against a disposable
// local WordPress (home URL on localhost or 127.0.0.1) with scout-newsletter active:
//   php tools/tests/newsletter.php /path/to/wordpress
// It empties the newsletter table and settings on that site. Never point it at a real site.
require rtrim( $argv[1] ?? '', '/' ) . '/wp-load.php';
$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
	fwrite( STDERR, "Refusing: this test only runs on a local site (home URL on localhost or 127.0.0.1).\n" );
	exit( 1 );
}
if ( ! class_exists( 'Scout_Newsletter' ) ) {
	fwrite( STDERR, "Activate scout-newsletter first.\n" );
	exit( 1 );
}
wp_set_current_user( 1 );

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

/* ---------- the mocked HTTP layer ---------- */

$GLOBALS['calls']   = array();
$GLOBALS['answers'] = array(); // A list of [ method, path regex, code or WP_Error, body ].
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://api.beehiiv.com/' ) ) {
			return new WP_Error( 'blocked', 'Only Beehiiv is mocked in this test.' );
		}
		$path                = substr( $url, strlen( 'https://api.beehiiv.com' ) );
		$GLOBALS['calls'][] = array(
			'method'  => $args['method'],
			'path'    => $path,
			'headers' => $args['headers'],
			'body'    => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
		);
		foreach ( $GLOBALS['answers'] as $i => $a ) {
			if ( $a[0] === $args['method'] && preg_match( $a[1], $path ) ) {
				unset( $GLOBALS['answers'][ $i ] );
				if ( is_wp_error( $a[2] ) ) {
					return $a[2];
				}
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $a[3] ),
					'response' => array( 'code' => $a[2], 'message' => '' ),
					'cookies'  => array(),
				);
			}
		}
		return array(
			'headers'  => array(),
			'body'     => '{"errors":[{"code":"unmocked","message":"no answer scripted"}]}',
			'response' => array( 'code' => 500, 'message' => '' ),
			'cookies'  => array(),
		);
	},
	10,
	3
);
function answer( $method, $re, $code, $body = array() ) {
	$GLOBALS['answers'][] = array( $method, $re, $code, $body );
}
function reset_http() {
	$GLOBALS['calls']   = array();
	$GLOBALS['answers'] = array();
}
function calls( $method = null ) {
	return array_values( array_filter( $GLOBALS['calls'], static fn( $c ) => null === $method || $c['method'] === $method ) );
}
function sub( $id, $email, $status ) {
	return array( 'data' => array( 'id' => $id, 'email' => $email, 'status' => $status, 'created' => time() ) );
}

const PUB = 'pub_00000000-1111-2222-3333-444444444444';
$RE_FIND   = '#^/v2/publications/' . PUB . '/subscriptions/by_email/[^/]+$#';
$RE_CREATE = '#^/v2/publications/' . PUB . '/subscriptions$#';
$RE_TAGS   = '#^/v2/publications/' . PUB . '/subscriptions/[^/]+/tags$#';

/* ---------- a clean slate ---------- */

global $wpdb;
Scout_Newsletter_Store::ensure();
$wpdb->query( 'DELETE FROM ' . Scout_Newsletter_Store::table() ); // phpcs:ignore
foreach ( array( Scout_Newsletter::OPTION, Scout_Newsletter::KEY_OPTION, Scout_Newsletter::LOG, Scout_Newsletter::SUPPRESS ) as $o ) {
	delete_option( $o );
}
delete_transient( Scout_Newsletter_Queue::LOCK );
function configure( $extra = array() ) {
	$s = array_merge(
		Scout_Newsletter::defaults(),
		array(
			'enabled'      => 1,
			'publication'  => PUB,
			'name'         => 'Elevate',
			'utm_source'   => 'blissboogie.com',
			'field_first'  => 'First Name',
			'field_joined' => 'Joined From',
			'field_plan'   => 'Member Plan',
			'tags'         => array(
				'event'      => 'Event',
				'ticket'     => 'Ticket',
				'membership' => 'Member',
				'booking'    => 'Booking',
				'form'       => 'Form',
			),
		),
		$extra
	);
	update_option( Scout_Newsletter::OPTION, $s );
}
function row_for( $email ) {
	global $wpdb;
	$t  = Scout_Newsletter_Store::table();
	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE email = %s ORDER BY id DESC LIMIT 1", $email ) ); // phpcs:ignore
	return $id ? Scout_Newsletter_Store::get( $id ) : null;
}
/** When the next queue run is set for, from Action Scheduler or WP-Cron. */
function next_run() {
	if ( Scout_Newsletter_Queue::uses_action_scheduler() ) {
		$n = as_next_scheduled_action( Scout_Newsletter_Queue::HOOK, array(), Scout_Newsletter_Queue::GROUP );
		return is_int( $n ) ? $n : ( $n ? time() : 0 );
	}
	return (int) wp_next_scheduled( Scout_Newsletter_Queue::HOOK );
}
function clear_runs() {
	wp_clear_scheduled_hook( Scout_Newsletter_Queue::HOOK );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( Scout_Newsletter_Queue::HOOK, array(), Scout_Newsletter_Queue::GROUP );
	}
}
function run_queue() {
	delete_transient( Scout_Newsletter_Queue::LOCK );
	return Scout_Newsletter_Queue::run();
}

/* ---------- 1. Missing key: nothing is sent, the agreement is kept ---------- */

configure();
clear_runs();
reset_http();
do_action( 'scout_newsletter_optin', 'nokey@example.test', 'Nia', 'event', array(), array( 'wording' => 'Send me Elevate.' ) );
$r = row_for( 'nokey@example.test' );
ok( $r && 'held' === $r['status'], 'missing key: the sign-up is saved and held' );
ok( 0 === run_queue() && 0 === count( calls() ), 'missing key: the queue does nothing and makes no HTTP call' );
ok( ! next_run(), 'missing key: no queue run is scheduled' );

/* ---------- the key is stored encrypted ---------- */

Scout_Newsletter::save_key( 'bh_test_key_123' );
$stored = (string) get_option( Scout_Newsletter::KEY_OPTION );
ok( 0 === strpos( $stored, 'e:' ) && false === strpos( $stored, 'bh_test_key_123' ), 'key: stored encrypted, never in plain text' );
ok( 'bh_test_key_123' === Scout_Newsletter::key(), 'key: decrypts back to the same key' );
ok( Scout_Newsletter::sending(), 'key + publication + switch: sending is on' );

/* ---------- 2. Test connection ---------- */

reset_http();
answer( 'GET', '#^/v2/publications/' . PUB . '$#', 200, array( 'data' => array( 'id' => PUB, 'name' => 'Elevate' ) ) );
$t = Scout_Newsletter_Beehiiv::test();
ok( $t['ok'] && 'Elevate' === $t['data']['name'], 'test connection: reads the publication name' );
ok( 'Bearer bh_test_key_123' === calls()[0]['headers']['Authorization'], 'test connection: sends the key as a Bearer token' );
reset_http();
answer( 'GET', '#^/v2/publications/#', 401, array( 'errors' => array( array( 'code' => 'unauthorized', 'message' => 'x' ) ) ) );
$t = Scout_Newsletter_Beehiiv::test();
ok( ! $t['ok'] && 401 === $t['code'] && ! $t['retry'], 'test connection: a refused key is reported, not retried' );

/* ---------- 3. Subscribe a new person ---------- */

reset_http();
$id = Scout_Newsletter::optin( 'New.Person@Example.test', 'Ana', 'event', array( 'Pause and Play' ), array( 'wording' => 'Yes, send me Elevate.', 'url' => 'http://127.0.0.1:8931/events/pause/', 'campaign' => 'pause-and-play' ) );
ok( 0 === count( calls() ), 'the visitor never waits: ticking the box makes no HTTP call' );
ok( (bool) next_run(), 'subscribe: a queue run is scheduled (' . ( Scout_Newsletter_Queue::uses_action_scheduler() ? 'Action Scheduler' : 'WP-Cron' ) . ')' );
$r = Scout_Newsletter_Store::get( $id );
ok( 'new.person@example.test' === $r['email'] && 'queued' === $r['status'], 'consent record: email saved lower case, queued' );
ok( 'Yes, send me Elevate.' === $r['wording'] && 'http://127.0.0.1:8931/events/pause/' === $r['url'] && 'event' === $r['source'] && '' !== $r['created'], 'consent record: who, when, where and the words shown' );
ok( array( 'Event', 'Pause and Play' ) === $r['tags'], 'tags: the event tag from the map plus the extra tag' );

answer( 'GET', $RE_FIND, 404, array( 'errors' => array( array( 'code' => 'not_found', 'message' => 'x' ) ) ) );
answer( 'POST', $RE_CREATE, 200, sub( 'sub_new1', 'new.person@example.test', 'active' ) );
answer( 'POST', $RE_TAGS, 200, sub( 'sub_new1', 'new.person@example.test', 'active' ) );
run_queue();
$c = calls();
ok( 3 === count( $c ) && 'GET' === $c[0]['method'] && 'POST' === $c[1]['method'] && 'POST' === $c[2]['method'], 'subscribe: looks the person up, creates, then tags' );
ok( false !== strpos( $c[0]['path'], '/by_email/new.person%40example.test' ), 'lookup: the email is URL encoded in the path' );
$body = $c[1]['body'];
ok( false === $body['reactivate_existing'], 'create: reactivate_existing is false' );
ok( 'blissboogie.com' === $body['utm_source'] && 'event' === $body['utm_medium'] && 'pause-and-play' === $body['utm_campaign'], 'create: utm source, medium and campaign' );
ok( 'http://127.0.0.1:8931/events/pause/' === $body['referring_site'], 'create: referring_site is the page' );
ok( array( array( 'name' => 'First Name', 'value' => 'Ana' ), array( 'name' => 'Joined From', 'value' => 'Event sign-up' ) ) === $body['custom_fields'], 'create: custom fields as name/value pairs' );
ok( false === $body['send_welcome_email'] && ! isset( $body['double_opt_override'] ), 'create: welcome email off, Beehiiv double opt-in default kept' );
ok( '/v2/publications/' . PUB . '/subscriptions/sub_new1/tags' === $c[2]['path'] && array( 'tags' => array( 'Event', 'Pause and Play' ) ) === $c[2]['body'], 'tags: posted to the new subscription' );
$r = Scout_Newsletter_Store::get( $id );
ok( 'sent' === $r['status'] && 'sub_new1' === $r['sub_id'] && 1 === $r['attempts'], 'subscribe: record marked sent with the subscription id' );

/* ---------- 4. Someone already on the list: tags only ---------- */

reset_http();
Scout_Newsletter::optin( 'already@example.test', 'Bo', 'ticket' );
answer( 'GET', $RE_FIND, 200, sub( 'sub_old', 'already@example.test', 'active' ) );
answer( 'POST', $RE_TAGS, 200, sub( 'sub_old', 'already@example.test', 'active' ) );
run_queue();
ok( 2 === count( calls() ) && 0 === count( array_filter( calls( 'POST' ), static fn( $c ) => preg_match( '#/subscriptions$#', $c['path'] ) ) ), 'existing subscriber: no create call, only tags' );
ok( 'sent' === row_for( 'already@example.test' )['status'], 'existing subscriber: marked sent' );

/* ---------- 5. Unsubscribed in Beehiiv: never added back ---------- */

reset_http();
Scout_Newsletter::optin( 'gone@example.test', 'Cy', 'form' );
answer( 'GET', $RE_FIND, 200, sub( 'sub_gone', 'gone@example.test', 'inactive' ) );
run_queue();
ok( 1 === count( calls() ), 'unsubscribed: only the lookup, no create and no tags' );
$r = row_for( 'gone@example.test' );
ok( 'skipped' === $r['status'] && 'unsubscribed' === $r['result'], 'unsubscribed: record skipped as unsubscribed' );
ok( Scout_Newsletter::suppressed( 'gone@example.test' ), 'unsubscribed: remembered on the site' );
reset_http();
Scout_Newsletter::optin( 'gone@example.test', 'Cy', 'event' );
run_queue();
ok( 0 === count( calls() ) && 'skipped' === row_for( 'gone@example.test' )['status'], 'unsubscribed: a later tick is saved but never sent, with no API call' );

// A create that comes back inactive (a race with an unsubscribe) is also left alone.
reset_http();
Scout_Newsletter::optin( 'race@example.test', '', 'form' );
answer( 'GET', $RE_FIND, 404, array() );
answer( 'POST', $RE_CREATE, 200, sub( 'sub_race', 'race@example.test', 'inactive' ) );
run_queue();
ok( 2 === count( calls() ) && 'skipped' === row_for( 'race@example.test' )['status'], 'unsubscribed: an inactive answer to create gets no tags' );

/* ---------- 6. Beehiiv down: retry later, then succeed ---------- */

reset_http();
$id = Scout_Newsletter::optin( 'retry@example.test', 'Di', 'booking' );
answer( 'GET', $RE_FIND, 503, array( 'errors' => array( array( 'code' => 'unavailable', 'message' => 'x' ) ) ) );
run_queue();
$r     = Scout_Newsletter_Store::get( $id );
$delay = strtotime( $r['next_try'] . ' UTC' ) - time();
ok( 'retry' === $r['status'] && 1 === $r['attempts'] && $delay > 240 && $delay <= 300, 'API down: marked retry, next try in 5 minutes' );
$next = next_run();
ok( $next && $next - time() > 240, 'API down: the queue is scheduled for the retry' );
reset_http();
run_queue();
ok( 0 === count( calls() ), 'API down: nothing is tried before the retry time' );
Scout_Newsletter_Store::set( $id, array( 'next_try' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
answer( 'GET', $RE_FIND, new WP_Error( 'http_request_failed', 'timed out' ) );
run_queue();
$r = Scout_Newsletter_Store::get( $id );
ok( 'retry' === $r['status'] && 2 === $r['attempts'] && 'no_answer' === $r['result'], 'no answer at all: retried again, 15 minutes later' );
Scout_Newsletter_Store::set( $id, array( 'next_try' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
reset_http();
answer( 'GET', $RE_FIND, 404, array() );
answer( 'POST', $RE_CREATE, 200, sub( 'sub_r', 'retry@example.test', 'pending' ) );
answer( 'POST', $RE_TAGS, 200, array() );
run_queue();
ok( 'sent' === Scout_Newsletter_Store::get( $id )['status'], 'API back: the retry goes through' );

// A tag call that fails after the create retries the tags only.
reset_http();
$id = Scout_Newsletter::optin( 'tagretry@example.test', '', 'event' );
answer( 'GET', $RE_FIND, 404, array() );
answer( 'POST', $RE_CREATE, 200, sub( 'sub_t', 'tagretry@example.test', 'active' ) );
answer( 'POST', $RE_TAGS, 429, array() );
run_queue();
Scout_Newsletter_Store::set( $id, array( 'next_try' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
reset_http();
answer( 'POST', $RE_TAGS, 200, array() );
run_queue();
ok( 1 === count( calls() ) && 'sent' === Scout_Newsletter_Store::get( $id )['status'], 'rate limited on tags: the retry only re-sends the tags' );

// Six failures in a row: failed, not retried forever.
reset_http();
$id = Scout_Newsletter::optin( 'never@example.test', '', 'form' );
for ( $i = 0; $i < 6; $i++ ) {
	Scout_Newsletter_Store::set( $id, array( 'next_try' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
	answer( 'GET', $RE_FIND, 500, array() );
	run_queue();
}
$r = Scout_Newsletter_Store::get( $id );
ok( 'failed' === $r['status'] && 6 === $r['attempts'], 'API down for a day: failed after six tries' );

/* ---------- 7. A refused key holds the sign-up and stops the batch ---------- */

reset_http();
Scout_Newsletter::optin( 'held1@example.test', '', 'form' );
Scout_Newsletter::optin( 'held2@example.test', '', 'form' );
answer( 'GET', $RE_FIND, 401, array() );
answer( 'GET', $RE_FIND, 401, array() );
run_queue();
ok( 1 === count( calls() ) && 'held' === row_for( 'held1@example.test' )['status'], 'refused key: held, and the rest of the batch waits' );
ok( 2 <= Scout_Newsletter_Store::release_held(), 'refused key: Send them to Beehiiv puts held sign-ups back in the queue' );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Scout_Newsletter_Store::table() . " SET status = 'skipped' WHERE email IN (%s, %s, %s)", 'held1@example.test', 'held2@example.test', 'nokey@example.test' ) ); // phpcs:ignore

/* ---------- 8. A double tick makes one send ---------- */

reset_http();
$a = Scout_Newsletter::optin( 'twice@example.test', 'Ed', 'ticket', array( 'A' ) );
$b = Scout_Newsletter::optin( 'twice@example.test', 'Ed', 'ticket', array( 'B' ) );
ok( $a === $b && array( 'Ticket', 'A', 'B' ) === Scout_Newsletter_Store::get( $a )['tags'], 'double tick: one record, tags merged' );
answer( 'GET', $RE_FIND, 200, sub( 'sub_tw', 'twice@example.test', 'active' ) );
answer( 'POST', $RE_TAGS, 200, array() );
run_queue();

/* ---------- 9. Keep subscribers up to date (never subscribes) ---------- */

reset_http();
ok( 0 === Scout_Newsletter::update( 'member@example.test', array( 'Member' ), array( 'Member Plan' => 'Club' ) ), 'updates: off by default' );
configure( array( 'keep_current' => 1 ) );
Scout_Newsletter::update( 'member@example.test', array( 'Member' ), array( 'Member Plan' => 'Club' ), 'membership' );
answer( 'GET', $RE_FIND, 200, sub( 'sub_m', 'member@example.test', 'active' ) );
answer( 'PUT', $RE_FIND, 200, sub( 'sub_m', 'member@example.test', 'active' ) );
answer( 'POST', $RE_TAGS, 200, array() );
run_queue();
$put = calls( 'PUT' );
ok( 1 === count( $put ) && array( 'custom_fields' => array( array( 'name' => 'Member Plan', 'value' => 'Club' ) ) ) === $put[0]['body'], 'updates: member plan field set by email' );
reset_http();
Scout_Newsletter::update( 'stranger@example.test', array( 'Event' ), array(), 'event' );
answer( 'GET', $RE_FIND, 404, array() );
run_queue();
ok( 1 === count( calls() ) && 'skipped' === row_for( 'stranger@example.test' )['status'], 'updates: someone not on the list is never subscribed' );
ok( array( 'held' => 0, 'queued' => 0 ) === array_intersect_key( Scout_Newsletter_Store::counts(), array( 'held' => 0, 'queued' => 0 ) ), 'updates: not counted as consent records' );
configure();

/* ---------- 10. Scout Events, Gravity Forms and the sign-up box ---------- */

reset_http();
do_action( 'scout_events_person', array( 'email' => 'rsvp@example.test', 'name' => 'Fay Lee', 'role' => 'attendee', 'event' => 0, 'newsletter' => true, 'newsletter_text' => 'Send me Elevate too.' ) );
$r = row_for( 'rsvp@example.test' );
ok( $r && 'event' === $r['source'] && 'Fay' === $r['first_name'] && 'Send me Elevate too.' === $r['wording'], 'Scout Events: a ticked RSVP box is saved with its words' );
do_action( 'scout_events_person', array( 'email' => 'quiet@example.test', 'name' => 'Gus', 'role' => 'attendee', 'event' => 0 ) );
ok( null === row_for( 'quiet@example.test' ), 'Scout Events: no box ticked, nobody added' );

$form  = array(
	'title'                  => 'Contact us',
	'scout_newsletter_field' => '4',
	'scout_newsletter_tag'   => 'Contact',
	'fields'                 => array(
		array( 'id' => 1, 'type' => 'name', 'label' => 'Name' ),
		array( 'id' => 2, 'type' => 'email', 'label' => 'Email' ),
		array( 'id' => 4, 'type' => 'consent', 'label' => 'Newsletter', 'checkboxLabel' => 'Send me Elevate.' ),
	),
);
$entry = array( 'status' => 'active', 'source_url' => 'http://127.0.0.1:8931/contact/', '1.3' => 'Hal', '2' => 'gf@example.test', '4.1' => '1', '4.2' => 'Send me Elevate (as shown).', '4.3' => '1' );
Scout_Newsletter_Sources::gf_submission( $entry, $form );
$r = row_for( 'gf@example.test' );
ok( $r && 'form' === $r['source'] && 'Hal' === $r['first_name'] && 'Send me Elevate (as shown).' === $r['wording'] && array( 'Form', 'Contact' ) === $r['tags'], 'Gravity Forms: a ticked consent field sends the person with its words and tags' );
$entry['2'] = 'gf-no@example.test';
unset( $entry['4.1'] );
Scout_Newsletter_Sources::gf_submission( $entry, $form );
ok( null === row_for( 'gf-no@example.test' ), 'Gravity Forms: an unticked box sends nobody' );
$entry['2']      = 'gf-spam@example.test';
$entry['4.1']    = '1';
$entry['status'] = 'spam';
Scout_Newsletter_Sources::gf_submission( $entry, $form );
ok( null === row_for( 'gf-spam@example.test' ), 'Gravity Forms: spam entries are ignored' );
$settings = Scout_Newsletter_Sources::gf_settings( array(), $form );
ok( '4' === $settings['scout_newsletter']['fields'][0]['choices'][1]['value'], 'Gravity Forms: the form setting lists the consent field' );

$html = do_shortcode( '[scout_newsletter heading="Join Elevate" tag="Footer"]' );
ok( false !== strpos( $html, 'name="nl_email"' ) && false !== strpos( $html, 'Join Elevate' ) && false !== strpos( $html, 'unsubscribe at any time' ), 'sign-up box: renders the form with the words people agree to' );
ok( false !== strpos( $html, 'name="nl_sig"' ), 'sign-up box: source and tag are signed' );

/* ---------- 11. Privacy: the log holds no email ---------- */

$log = wp_json_encode( get_option( Scout_Newsletter::LOG ) );
ok( '' !== $log && false === strpos( $log, '@' ) && false === strpos( $log, 'example' ), 'log: no emails, only hashed codes' );
ok( 12 === strlen( Scout_Newsletter::hash( 'a@b.c' ) ) && Scout_Newsletter::hash( 'A@B.C' ) === Scout_Newsletter::hash( 'a@b.c' ), 'log: hash is short, one-way and case-insensitive' );

/* ---------- 12. Live-shaped request: the create body matches Beehiiv's documented schema ---------- */

// Fields from developers.beehiiv.com/api-reference/subscriptions/create (read 2026-11-03).
$documented = array(
	'email'                               => 'string',
	'reactivate_existing'                 => 'boolean',
	'send_welcome_email'                  => 'boolean',
	'utm_source'                          => 'string',
	'utm_medium'                          => 'string',
	'utm_campaign'                        => 'string',
	'utm_term'                            => 'string',
	'utm_content'                         => 'string',
	'referring_site'                      => 'string',
	'referral_code'                       => 'string',
	'custom_fields'                       => 'array',
	'double_opt_override'                 => 'string',
	'tier'                                => 'string',
	'premium_tiers'                       => 'array',
	'premium_tier_ids'                    => 'array',
	'stripe_customer_id'                  => 'string',
	'automation_ids'                      => 'array',
	'newsletter_list_ids'                 => 'array',
	'skip_newsletter_list_auto_subscribe' => 'boolean',
	'complimentary_gift_id'               => 'string',
);
configure( array( 'welcome' => 1, 'double_opt' => 'on', 'automation' => 'aut_1111, aut_2222' ) );
$body = Scout_Newsletter_Beehiiv::create_body( array( 'email' => 'shape@example.test', 'first_name' => 'Ivy', 'source' => 'membership', 'campaign' => 'spring', 'url' => 'http://127.0.0.1:8931/join/' ) );
$bad  = array();
foreach ( $body as $k => $v ) {
	if ( ! isset( $documented[ $k ] ) || gettype( $v ) !== $documented[ $k ] ) {
		$bad[] = $k;
	}
}
ok( ! $bad, 'schema: every create field is documented and the right type' . ( $bad ? ' (' . implode( ', ', $bad ) . ')' : '' ) );
ok( is_email( $body['email'] ) && false === $body['reactivate_existing'], 'schema: email present, reactivate_existing false' );
ok( 'on' === $body['double_opt_override'] && in_array( $body['double_opt_override'], array( 'on', 'off', 'not_set' ), true ), 'schema: double_opt_override uses an allowed value' );
ok( array( 'aut_1111', 'aut_2222' ) === $body['automation_ids'] && true === $body['send_welcome_email'], 'schema: automation_ids is a list of ids, welcome email follows the setting' );
$fields_ok = true;
foreach ( $body['custom_fields'] as $f ) {
	$fields_ok = $fields_ok && array( 'name', 'value' ) === array_keys( $f ) && is_string( $f['name'] );
}
ok( $fields_ok, 'schema: custom_fields items are { name, value }' );
reset_http();
Scout_Newsletter_Beehiiv::add_tags( 'sub_x', array( 'Event' ) );
Scout_Newsletter_Beehiiv::update_fields( 'a+b@example.test', array( 'Member Plan' => 'VIP' ) );
$c = calls();
ok( 'application/json' === $c[0]['headers']['Content-Type'] && is_array( $c[0]['body']['tags'] ), 'schema: add tags posts JSON { tags: [...] }' );
ok( 'PUT' === $c[1]['method'] && false !== strpos( $c[1]['path'], '/by_email/a%2Bb%40example.test' ), 'schema: update by email is a PUT with the email URL encoded' );

/* ---------- 13. WooCommerce (when active): Action Scheduler and the checkout box ---------- */

configure();
if ( function_exists( 'wc_create_order' ) ) {
	ok( Scout_Newsletter_Queue::uses_action_scheduler(), 'WooCommerce: the queue uses Action Scheduler' );
	clear_runs();
	Scout_Newsletter_Queue::schedule();
	ok( false !== as_next_scheduled_action( Scout_Newsletter_Queue::HOOK, array(), Scout_Newsletter_Queue::GROUP ), 'WooCommerce: a queue run is in Action Scheduler' );

	ob_start();
	Scout_Newsletter_Sources::checkout_box();
	ok( '' === ob_get_clean(), 'checkout box: hidden while the setting is off and no plugin asks for it' );
	add_filter( 'scout_newsletter_checkout_box', '__return_true' );
	ob_start();
	Scout_Newsletter_Sources::checkout_box();
	$box = ob_get_clean();
	remove_filter( 'scout_newsletter_checkout_box', '__return_true' );
	ok( false !== strpos( $box, 'type="checkbox"' ) && false === strpos( $box, 'checked' ) && false === strpos( $box, 'required' ), 'checkout box: optional and unticked' );

	$order = wc_create_order();
	$order->set_billing_email( 'buyer@example.test' );
	$order->set_billing_first_name( 'Jo' );
	$_POST['scout_newsletter_optin'] = '1';
	Scout_Newsletter_Sources::checkout_save( $order, array() );
	unset( $_POST['scout_newsletter_optin'] );
	$order->save();
	ok( '' !== $order->get_meta( Scout_Newsletter_Sources::ORDER_OPTIN ), 'checkout box: the words are kept on the order' );
	ok( null === row_for( 'buyer@example.test' ), 'checkout box: nothing is sent before the order is paid' );
	$order->update_status( 'processing' );
	$r = row_for( 'buyer@example.test' );
	ok( $r && 'Jo' === $r['first_name'] && '' !== $r['wording'], 'checkout box: sent once the order is paid' );
	$order = wc_get_order( $order->get_id() );
	$order->update_status( 'completed' );
	global $wpdb;
	$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Scout_Newsletter_Store::table() . ' WHERE email = %s', 'buyer@example.test' ) ); // phpcs:ignore
	ok( 1 === $n, 'checkout box: one record per order, however many status changes' );

	$plain = wc_create_order();
	$plain->set_billing_email( 'nobox@example.test' );
	$plain->save();
	$plain->update_status( 'processing' );
	ok( null === row_for( 'nobox@example.test' ), 'checkout: a buyer who did not tick the box is never added' );

	$block = wc_create_order();
	$block->set_billing_email( 'block@example.test' );
	$block->update_meta_data( '_wc_other/' . Scout_Newsletter_Sources::BLOCK_FIELD, '1' );
	$block->save();
	$block->update_status( 'processing' );
	ok( null !== row_for( 'block@example.test' ), 'block checkout: a ticked box from the checkout block is sent' );
} else {
	echo "SKIP WooCommerce is not active\n";
}

/* ---------- 14. Scout Members (when active): the application's newsletter question ---------- */

if ( class_exists( 'Scout_Members' ) && class_exists( 'Scout_Members_Form' ) ) {
	$old_q = get_option( 'scout_members_questions' );
	update_option( 'scout_members_questions', "text | How did you hear about us?\ncheck | Send me the Elevate newsletter" );
	foreach ( array( 'applicant-yes@example.test' => 'Yes', 'applicant-no@example.test' => 'No' ) as $mail => $answer ) {
		$existing = get_user_by( 'email', $mail );
		if ( $existing ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $existing->ID );
		}
		$uid = wp_insert_user( array( 'user_login' => sanitize_user( strtok( $mail, '@' ) ), 'user_email' => $mail, 'user_pass' => wp_generate_password(), 'first_name' => 'Kim' ) );
		Scout_Members::save( $uid, array( 'answers' => array( 'How did you hear about us?' => 'A friend', 'Send me the Elevate newsletter' => $answer ) ) );
		Scout_Members::set_status( $uid, 'applied' );
	}
	$r = row_for( 'applicant-yes@example.test' );
	ok( $r && 'Send me the Elevate newsletter' === $r['wording'] && 'Kim' === $r['first_name'], 'Scout Members: a ticked newsletter question sends the applicant' );
	ok( null === row_for( 'applicant-no@example.test' ), 'Scout Members: an unticked question sends nobody' );
	false === $old_q ? delete_option( 'scout_members_questions' ) : update_option( 'scout_members_questions', $old_q );
} else {
	echo "SKIP Scout Members is not active\n";
}

/* ---------- tidy up ---------- */

configure( array( 'enabled' => 0 ) );
delete_option( Scout_Newsletter::KEY_OPTION );
clear_runs();

echo "\n" . ( $fail ? "{$fail} FAILED" : 'All passed' ) . "\n";
exit( $fail ? 1 : 0 );
