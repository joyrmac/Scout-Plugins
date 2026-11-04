<?php
/**
 * Scout dashboard tokens: tools/admin-kit/tokens.json is the one source.
 *
 *   php tools/admin-kit/build-tokens.php            write the token block into scout-admin.css
 *                                                   and copy the kit into every plugin
 *   php tools/admin-kit/build-tokens.php --check    write nothing; exit 1 when anything is out of date
 *   php tools/admin-kit/build-tokens.php --ds FILE  write the design system's branch file
 *                                                   (project/tokens/dashboard.css in the Scout design system)
 *
 * Needs PHP only. tools/tests/admin-tokens.php checks the values and the contrast.
 */

$root  = dirname( __DIR__, 2 );
$kit   = $root . '/tools/admin-kit';
$args  = array_slice( $argv, 1 );
$check = in_array( '--check', $args, true );
$ds    = '';
$i     = array_search( '--ds', $args, true );
if ( false !== $i ) {
	$ds = (string) ( $args[ $i + 1 ] ?? '' );
}

$t = json_decode( (string) file_get_contents( $kit . '/tokens.json' ), true );
if ( ! is_array( $t ) ) {
	fwrite( STDERR, "tokens.json is not valid JSON\n" );
	exit( 1 );
}
$val   = static fn( $v ) => preg_match( '/^\{(.+)\}$/', (string) $v, $m ) ? 'var(--' . $m[1] . ')' : (string) $v;
$lines = array();
foreach ( $t['color']['tokens'] as $x ) {
	$lines[] = "\t--{$x['name']}: " . $val( $x['value'] ) . ';';
}
$lines[] = "\t--sh-font: {$t['type']['families']['sans']};";
$lines[] = "\t--sh-mono: {$t['type']['families']['mono']};";
foreach ( array( 'size', 'spacing', 'radius', 'shadow', 'layout', 'motion' ) as $fam ) {
	foreach ( $t[ $fam ]['tokens'] as $x ) {
		$lines[] = "\t--{$x['name']}: {$x['value']};";
	}
}
$start = "\t/* Tokens: generated from tools/admin-kit/tokens.json by build-tokens.php. Change them there. */\n";
$block = $start . implode( "\n", $lines ) . "\n";

if ( '' !== $ds ) {
	$out = "/* ============================================================================\n"
		. "   SCOUT DESIGN SYSTEM: DASHBOARD BRANCH (WordPress admin)\n"
		. "   Tier 2 branch. Scoped to .sh, the wrapper every Scout screen in a client's\n"
		. "   WordPress dashboard prints. Light only. Generated from Scout-Plugins\n"
		. "   tools/admin-kit/tokens.json by tools/admin-kit/build-tokens.php --ds;\n"
		. "   edit tokens.json there, never this file. Rules: tools/admin-kit/DESIGN.md.\n"
		. "   ============================================================================ */\n\n"
		. ".sh {\n" . $block . "}\n";
	file_put_contents( $ds, $out );
	echo "wrote $ds\n";
	exit( 0 );
}

$css_file = $kit . '/scout-admin.css';
$css      = (string) file_get_contents( $css_file );
$a        = strpos( $css, "\t/* Tokens: generated" );
$b        = strpos( $css, "\tmax-width: var(--sh-max);" );
if ( false === $a || false === $b || $b < $a ) {
	fwrite( STDERR, "scout-admin.css has no token block to replace\n" );
	exit( 1 );
}
$new   = substr( $css, 0, $a ) . $block . substr( $css, $b );
$stale = array();
if ( $new !== $css ) {
	$stale[] = 'tools/admin-kit/scout-admin.css';
}
$copies = array_merge( glob( $root . '/*/assets/scout-admin.css' ), glob( $root . '/*/includes/class-scout-admin-ui.php' ) );
foreach ( $copies as $f ) {
	$src = str_ends_with( $f, '.css' ) ? $new : (string) file_get_contents( $kit . '/class-scout-admin-ui.php' );
	if ( (string) file_get_contents( $f ) !== $src ) {
		$stale[] = substr( $f, strlen( $root ) + 1 );
	}
}
if ( $check ) {
	echo $stale ? "Out of date:\n  " . implode( "\n  ", $stale ) . "\nRun php tools/admin-kit/build-tokens.php\n" : "Tokens and kit copies are current\n";
	exit( $stale ? 1 : 0 );
}
file_put_contents( $css_file, $new );
foreach ( $copies as $f ) {
	copy( str_ends_with( $f, '.css' ) ? $css_file : $kit . '/class-scout-admin-ui.php', $f );
}
echo 'Wrote the token block and copied the kit into ' . count( $copies ) . " files\n";
