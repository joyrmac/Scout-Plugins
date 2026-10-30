<?php
// Scout Core's email guard and comment switches. No WordPress needed:
//   php tools/tests/email-guard.php
// It loads the real protection class with the few WordPress functions it uses stubbed out.
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['t_opt']     = array();
$GLOBALS['t_filters'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_opt'] ) ? $GLOBALS['t_opt'][ $k ] : $d; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function apply_filters( $tag, $value ) { return $value; }
function add_filter( $tag, $cb, $p = 10 ) { $GLOBALS['t_filters'][ $tag ] = $cb; }
function is_admin() { return false; }
require dirname( __DIR__, 2 ) . '/scout-core/includes/protection.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
function guard( $html ) {
	return Scout_Core_Protection::buffer( $html );
}
function shown( $html ) {
	return html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}
function ldjson( $html ) {
	preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $m );
	return $m[1] ?? '';
}

$email = 'GolfCarSolutions22@gmail.com';
$page  = function ( $body, $head = '' ) {
	return "<!doctype html>\n<html><head><title>Andy</title>{$head}</head><body>{$body}</body></html>";
};

// 1. Mailto link.
$in  = $page( '<a class="btn" href="mailto:' . $email . '?subject=Golf%20cart">Email</a>' );
$out = guard( $in );
ok( false === strpos( $out, $email ) && false === strpos( $out, '@gmail' ), 'mailto link: address no longer in plain text' );
ok( false !== strpos( shown( $out ), 'href="mailto:' . $email . '?subject=Golf%20cart"' ), 'mailto link: decodes to the same href' );
ok( false !== strpos( $out, '&#64;' ), 'mailto link: "@" is a character reference' );
$single = guard( $page( "<a href='mailto:{$email}'>x</a>" ) );
ok( false === strpos( $single, $email ) && false !== strpos( shown( $single ), "href='mailto:{$email}'" ), 'mailto link in single quotes' );

// 2. Visible email.
$in  = $page( '<p>Write to ' . $email . ', any time.</p>' );
$out = guard( $in );
ok( false === strpos( $out, $email ), 'visible email: not in plain text' );
ok( shown( $out ) === $in, 'visible email: page reads the same once entities decode' );
ok( preg_match( '/&#\d+;/', $out ) && preg_match( '/&#x[0-9a-f]+;/', $out ), 'visible email: mixed decimal and hex references' );

// 3. Textarea untouched.
$in  = $page( '<form><textarea name="m">Reply to ' . $email . '</textarea><input type="email" value="' . $email . '" data-x="' . $email . '"></form>' );
$out = guard( $in );
ok( $out === $in, 'textarea, input value and data attribute: untouched' );

// 4. JSON-LD stays valid and decodes equal.
$data = array(
	'@context' => 'https://schema.org',
	'@graph'   => array(
		array( '@type' => 'AutoRepair', '@id' => 'https://andysgolfcarts.com/#business', 'email' => $email, 'name' => "Andy's Golf Car Garage", 'url' => 'mailto:' . $email ),
	),
);
$json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$in   = $page( '<p>Hi</p>', '<script type="application/ld+json">' . $json . '</script>' );
$out  = guard( $in );
$after = ldjson( $out );
ok( false === strpos( $after, $email ), 'JSON-LD: address not in plain text' );
ok( false !== strpos( $after, '\\u0040gmail.com' ), 'JSON-LD: "@" escaped as \\u0040' );
ok( null !== json_decode( $after, true ) && json_decode( $after, true ) === json_decode( $json, true ), 'JSON-LD: valid, decodes equal to the original' );
ok( false !== strpos( $after, '"@context"' ) && false !== strpos( $after, '"@type"' ), 'JSON-LD: @context and @type keys untouched' );

// 5. Inline script untouched.
$in  = $page( '<script>var to = "' . $email . '"; if (a<b) { x(); }</script><style>/* ' . $email . ' */</style><!-- ' . $email . ' -->' );
$out = guard( $in );
ok( $out === $in, 'inline script, style and HTML comment: untouched' );

// 6. No email: byte for byte.
$in = $page( '<p>Call (919) 704-5207. Follow @andysgolfcars and see https://x.com/@andy.</p>' );
ok( guard( $in ) === $in, 'page with no email address: unchanged byte for byte' );
$plain = $page( '<p>Nothing to see</p>' );
ok( guard( $plain ) === $plain, 'page with no "@" at all: unchanged byte for byte' );

// 7. Idempotent and noscript.
$in   = $page( '<noscript><a href="mailto:' . $email . '">' . $email . '</a></noscript><p>' . $email . '</p>', '<script type="application/ld+json">' . $json . '</script>' );
$once = guard( $in );
ok( guard( $once ) === $once, 'running twice changes nothing' );
ok( false === strpos( $once, $email ), 'noscript markup is guarded like the rest of the page' );

// 8. Not HTML: left alone.
ok( guard( $json ) === $json, 'a JSON body passes through' );
$xml = '<?xml version="1.0"?><urlset><loc>mailto:' . $email . '</loc></urlset>';
ok( guard( $xml ) === $xml, 'an XML body passes through' );

// 9. A mid-page flush inside a script keeps the script untouched.
$a   = $page( '' ) . '<script>var a="' . $email;
$b   = '";</script><p>' . $email . '</p>';
$out = guard( $a ) . guard( $b );
ok( false !== strpos( $out, 'var a="' . $email . '";</script>' ) && false === strpos( $out, '<p>' . $email ), 'flush mid-script: script kept, later text guarded' );

// 10. Settings default on, sanitize stores 1/0.
ok( Scout_Core_Protection::on( 'email_guard' ) && Scout_Core_Protection::on( 'close_comments' ), 'both switches on by default' );
ok( array( 'email_guard' => '0', 'close_comments' => '1' ) === Scout_Core_Protection::sanitize( array( 'close_comments' => '1' ) ), 'an unchecked box saves as off' );
$GLOBALS['t_opt']['scout_core_protection'] = array( 'email_guard' => '0', 'close_comments' => '0' );
ok( ! Scout_Core_Protection::on( 'email_guard' ), 'saved off reads off' );
Scout_Core_Protection::maybe_close_comments();
ok( empty( $GLOBALS['t_filters'] ), 'comments switch off: no filters added' );
$GLOBALS['t_opt'] = array();
Scout_Core_Protection::maybe_close_comments();
ok( isset( $GLOBALS['t_filters']['comments_open'], $GLOBALS['t_filters']['pings_open'], $GLOBALS['t_filters']['xmlrpc_methods'], $GLOBALS['t_filters']['wp_headers'] ), 'comments switch on: comments, pings, XML-RPC and header filters added' );
$m = Scout_Core_Protection::drop_pingback_methods( array( 'pingback.ping' => 1, 'pingback.extensions.getPingbacks' => 1, 'wp.getPosts' => 1 ) );
ok( array( 'wp.getPosts' => 1 ) === $m, 'pingback XML-RPC methods removed, others kept' );
$h = Scout_Core_Protection::drop_pingback_header( array( 'X-Pingback' => 'x', 'Content-Type' => 'text/html' ) );
ok( array( 'Content-Type' => 'text/html' ) === $h, 'X-Pingback header removed' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
