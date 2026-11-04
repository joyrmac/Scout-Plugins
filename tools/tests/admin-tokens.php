<?php
// The Scout dashboard tokens: tools/admin-kit/tokens.json is the source, and the
// admin kit CSS must declare the same values. Also checks contrast. Runs without WordPress:
//   php tools/tests/admin-tokens.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
$root = dirname( __DIR__, 2 );
$json = json_decode( (string) file_get_contents( $root . '/tools/admin-kit/tokens.json' ), true );
$css  = (string) file_get_contents( $root . '/tools/admin-kit/scout-admin.css' );
ok( is_array( $json ), 'tokens.json is valid JSON' );

// Every family is a list of {name, value}, the shape the design system page reads.
$want = array();
foreach ( $json as $family => $data ) {
	if ( 'type' === $family ) {
		continue;
	}
	ok( isset( $data['tokens'] ) && array_is_list( $data['tokens'] ), "$family is a token list" );
	foreach ( $data['tokens'] as $t ) {
		$v = (string) $t['value'];
		$want[ $t['name'] ] = preg_match( '/^\{(.+)\}$/', $v, $m ) ? 'var(--' . $m[1] . ')' : $v;
	}
}
ok( isset( $json['type']['families']['sans'], $json['type']['families']['mono'] ), 'type has the sans and mono families' );
$want['sh-font'] = $json['type']['families']['sans'];
$want['sh-mono'] = $json['type']['families']['mono'];

// The .sh block in the CSS.
preg_match( '/^\.sh \{(.*?)^\}/ms', $css, $m );
preg_match_all( '/--(sh-[a-z0-9-]+):\s*([^;]+);/', $m[1] ?? '', $mm, PREG_SET_ORDER );
$have = array();
foreach ( $mm as $d ) {
	$have[ $d[1] ] = trim( $d[2] );
}
$norm    = static fn( $v ) => strtolower( str_replace( ' ', '', $v ) );
$missing = array_diff_key( $want, $have );
$extra   = array_diff_key( $have, $want );
$wrong   = array_keys( array_filter( $want, static fn( $v, $k ) => isset( $have[ $k ] ) && $norm( $have[ $k ] ) !== $norm( $v ), ARRAY_FILTER_USE_BOTH ) );
ok( ! $missing, 'every token is declared in the kit CSS' . ( $missing ? ': missing ' . implode( ', ', array_keys( $missing ) ) : '' ) );
ok( ! $extra, 'the kit CSS declares no token that tokens.json lacks' . ( $extra ? ': ' . implode( ', ', array_keys( $extra ) ) : '' ) );
ok( ! $wrong, 'every value matches tokens.json' . ( $wrong ? ': ' . implode( ', ', $wrong ) : '' ) );

preg_match_all( '/var\(--(sh-[a-z0-9-]+)\)/', $css, $used );
$undeclared = array_diff( array_unique( $used[1] ), array_keys( $have ) );
ok( ! $undeclared, 'every variable the kit uses is declared' . ( $undeclared ? ': ' . implode( ', ', $undeclared ) : '' ) );

// White text never sits on the bright spark (3.45:1); it goes on the fill.
$bad = 0;
foreach ( explode( '}', $css ) as $rule ) {
	if ( preg_match( '/background(-color)?:\s*var\(--sh-accent\)/', $rule ) && preg_match( '/(^|[;{\s])color:\s*(#fff\b|#ffffff\b|white\b|var\(--sh-on-accent\))/i', $rule ) ) {
		$bad++;
	}
}
ok( 0 === $bad, 'no rule puts white text on sh-accent' );

// Contrast (WCAG 2): body text 4.5:1.
$hex = static function ( $name ) use ( $want, &$hex ) {
	$v = $want[ $name ];
	return preg_match( '/^var\(--(.+)\)$/', $v, $m ) ? $hex( $m[1] ) : $v;
};
$lum = static function ( $h ) {
	$h = ltrim( $h, '#' );
	$c = array_map( static fn( $x ) => hexdec( $x ) / 255, str_split( 3 === strlen( $h ) ? $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2] : $h, 2 ) );
	$c = array_map( static fn( $x ) => $x <= 0.03928 ? $x / 12.92 : ( ( $x + 0.055 ) / 1.055 ) ** 2.4, $c );
	return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$ratio = static function ( $a, $b ) use ( $hex, $lum ) {
	$x = $lum( $hex( $a ) );
	$y = $lum( $hex( $b ) );
	return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
};
$pairs = array(
	array( 'sh-on-accent', 'sh-accent-fill' ),
	array( 'sh-on-accent', 'sh-accent-fill-hover' ),
	array( 'sh-text', 'sh-surface' ),
	array( 'sh-text-2', 'sh-surface' ),
	array( 'sh-text-2', 'sh-surface-2' ),
	array( 'sh-text-3', 'sh-surface' ),
	array( 'sh-text-3', 'sh-bg' ),
	array( 'sh-accent-text', 'sh-surface' ),
	array( 'sh-good', 'sh-surface' ),
	array( 'sh-warn', 'sh-surface' ),
	array( 'sh-bad', 'sh-surface' ),
);
foreach ( $pairs as $p ) {
	$r = $ratio( $p[0], $p[1] );
	ok( $r >= 4.5, sprintf( '%s on %s is %.2f:1 (needs 4.5)', $p[0], $p[1], $r ) );
}
// Status text on its own tint, the tint laid over white (pills, tags).
$over = static function ( $rgba ) {
	preg_match( '/rgba\((\d+),\s*(\d+),\s*(\d+),\s*([\d.]+)\)/', $rgba, $m );
	$mix = static fn( $c ) => (int) round( $c * $m[4] + 255 * ( 1 - $m[4] ) );
	return sprintf( '#%02x%02x%02x', $mix( $m[1] ), $mix( $m[2] ), $mix( $m[3] ) );
};
foreach ( array( array( 'sh-good', 'sh-good-tint' ), array( 'sh-warn', 'sh-warn-tint' ), array( 'sh-bad', 'sh-bad-tint' ), array( 'sh-accent-text', 'sh-glow' ) ) as $p ) {
	$x = $lum( $hex( $p[0] ) );
	$y = $lum( $over( $want[ $p[1] ] ) );
	$r = ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
	ok( $r >= 4.5, sprintf( '%s on %s is %.2f:1 (needs 4.5)', $p[0], $p[1], $r ) );
}
ok( $ratio( 'sh-on-accent', 'sh-accent' ) < 4.5, 'the bright spark really is too light for white text, which is why the fill exists' );

echo $fail ? "\n$fail FAILED\n" : "\nAll token checks passed\n";
exit( $fail ? 1 : 0 );
