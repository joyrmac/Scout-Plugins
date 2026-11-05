<?php
// Scout Core: privacy policy and terms drafted from the visitor data lists, sent to the
// client's lawyer, approved, then made into a draft page. Runs without WordPress:
//   php tools/tests/core-policies.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_PLUGIN_DIR', dirname( __DIR__, 2 ) ); // The repo: every plugin folder sits here.
$GLOBALS['opts'] = array(
	'active_plugins' => array( 'scout-chat/scout-chat.php', 'scout-analytics/scout-analytics.php', 'scout-hours/scout-hours.php', 'akismet/akismet.php' ),
	'date_format'    => 'F j, Y',
);
function add_action() {}
function apply_filters( $t, $v ) { return $v; }
function __( $s ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; }
function get_bloginfo( $k ) { return 'Firm Site'; }
function home_url( $p = '' ) { return 'https://firm.test' . $p; }
function wp_date( $f, $t ) { return gmdate( $f, $t ); }
function scout_core_business() { return array( 'name' => 'Firm & Co', 'legal_name' => 'Firm and Company LLC', 'email' => 'hello@firm.test', 'phone' => '(919) 555-0100', 'city' => 'Raleigh', 'region' => 'NC' ); }
require __DIR__ . '/../../scout-core/includes/policies.php';
$P = 'Scout_Core_Policies';

// What the site collects.
$src = $P::sources();
ok( array( 'scout-analytics', 'scout-chat', 'scout-hours' ) === array_keys( $src ), 'only Scout plugins that are on and keep something are listed: ' . implode( ', ', array_keys( $src ) ) );
ok( 'scout-chat' !== $src['scout-chat']['name'] && '' !== $src['scout-chat']['name'], 'each source is named by its plugin name' );

// The privacy draft.
$html = $P::privacy( $src );
ok( false !== strpos( $html, 'Firm and Company LLC runs this website, https://firm.test/' ), 'the draft names the business by its legal name and the site' );
ok( false !== strpos( $html, 'hello@firm.test or (919) 555-0100' ), 'the draft says how to reach the business' );
ok( false !== strpos( $html, 'sessionStorage' ) || false !== strpos( $html, 'browser storage' ), 'browser storage the chat uses is listed under cookies' );
ok( false !== strpos( $html, 'privacy choices link' ), 'with Analytics on, the draft points to the privacy choices link' );
ok( false !== strpos( $html, '[Confirm: we do not sell personal information.]' ), 'claims only the business can make are left in brackets for the lawyer' );
ok( false === strpos( $html, "\u{2014}" ) && false === strpos( $P::terms(), "\u{2014}" ), 'no em dashes in either draft' );
$chat_line = $src['scout-chat']['items'][0]['what'];
ok( false !== strpos( $html, esc_html( $chat_line ) ), 'every item from the chat list is in the draft' );

// Gravity Forms counts as a source, with Law Firm Tools' delete time when it is on.
$GLOBALS['opts']['active_plugins'][] = 'gravityforms/gravityforms.php';
function scout_law_entry_days() { return 90; }
$src2 = $P::sources();
ok( isset( $src2['gravityforms'] ) && '90 days, then deleted' === $src2['gravityforms']['items'][0]['kept'], 'form entries are listed, kept for the Law Firm Tools delete time' );

// The terms draft.
$terms = $P::terms();
ok( false !== strpos( $terms, 'governed by the laws of NC' ), 'the terms use the business state' );

// The email.
$email = $P::email();
ok( 0 === strpos( $email, 'Subject: Please review our website privacy policy and terms of service' ), 'the email starts with its subject' );
ok( false !== strpos( $email, 'PRIVACY POLICY (DRAFT)' ) && false !== strpos( $email, 'TERMS OF SERVICE (DRAFT)' ), 'the email carries both drafts' );
ok( false === strpos( $email, '<h2>' ) && false !== strpos( $email, "\n- " ), 'the drafts in the email are plain text with bullets' );
ok( false === stripos( $email, 'claude' ), 'the email is in Scout and the business\'s words' );

// Approve, and spot what changed.
$state = array( 'privacy' => array( 'status' => 'draft', 'sent' => 0, 'approved' => 0, 'note' => '', 'by' => '', 'html' => '', 'lines' => array(), 'page' => 0 ) );
$state['terms'] = $state['privacy'];
$state = $P::apply( $state, 'privacy', 'sent', '', 'Joy', 1000, $src );
ok( 'sent' === $state['privacy']['status'] && 1000 === $state['privacy']['sent'], 'marked as sent to the lawyer' );
$same = $P::apply( $state, 'privacy', 'approve', '   ', 'Joy', 2000, $src );
ok( 'sent' === $same['privacy']['status'], 'an approval needs a note saying who approved it' );
$state = $P::apply( $state, 'privacy', 'approve', 'Approved by the client\'s lawyer, by email, Nov 12', 'Joy', 2000, $src );
ok( 'approved' === $state['privacy']['status'] && 'Joy' === $state['privacy']['by'] && $state['privacy']['html'] === $html, 'approval keeps who pressed it, the note, and the exact text approved' );
ok( ! $P::out_of_date( 'privacy', $state['privacy'], $src ), 'nothing changed: up to date' );
$more = $src + array( 'scout-sitecheck' => array( 'name' => 'Scout Site Check', 'items' => array( array( 'what' => 'Name and email', 'where' => 'db', 'kept' => 'forever' ) ), 'cookies' => array() ) );
ok( $P::out_of_date( 'privacy', $state['privacy'], $more ), 'turning on a feature that collects something makes the approved policy out of date' );
$c = $P::changes( $state['privacy'], $more );
ok( array( 'Scout Site Check: Name and email' ) === $c['added'] && array() === $c['removed'], 'and the screen says exactly what is new' );
$less = $src;
unset( $less['scout-hours'] );
ok( $P::changes( $state['privacy'], $less )['removed'] && ! $P::changes( $state['privacy'], $less )['added'], 'turning one off lists what is no longer collected' );
$state['privacy']['page'] = 55;
$state = $P::apply( $state, 'privacy', 'approve', 'Approved again, Dec 1', 'Joy', 3000, $more );
ok( 0 === $state['privacy']['page'], 'a new approval gets its own draft page; the old page is left alone' );
$state = $P::apply( $state, 'privacy', 'reopen', '', 'Joy', 4000, $more );
ok( 'draft' === $state['privacy']['status'], 'starting a new draft' );

// Terms go out of date when business info changes.
$t = $P::apply( $state, 'terms', 'approve', 'Approved, Nov 12', 'Joy', 2000, $src );
ok( ! $P::out_of_date( 'terms', $t['terms'] ), 'approved terms are up to date' );
$t['terms']['html'] = str_replace( 'NC', 'SC', $t['terms']['html'] );
ok( $P::out_of_date( 'terms', $t['terms'] ), 'terms approved for other business details read as out of date' );

echo $fail ? "\n$fail FAILED\n" : "\nAll privacy and terms checks passed\n";
exit( $fail ? 1 : 0 );
