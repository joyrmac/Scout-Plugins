<?php
// Site Standard rule 3: plugins take their colors and fonts from the theme's
// DESIGN.md. Runs without WordPress:
//   php tools/tests/color-contract.php
//
// tools/design-build.php turns DESIGN.md into --scout-* variables in the theme's
// assets/css/design-tokens.css (--scout-surface, --scout-accent-fill,
// --scout-radius-card, --scout-font-body...). Every color and font a plugin uses
// on the front end is one fallback chain:
//   1. the variable it reads today (the theme's --surface, or --scout-booking-accent),
//   2. the DESIGN.md name,
//   3. today's default,
// for example var(--surface, var(--scout-surface, #161616)). So a theme that sets
// today's variable still wins, a theme built from DESIGN.md gets its colors, and
// a theme with neither keeps the default.
//
// This scans each listed plugin's front-end CSS and fails when a color or font
// property, or a custom property that feeds one, uses a bare hex, or a var()
// whose chain has no DESIGN.md name. Fixed colors that DESIGN.md has no role for
// (status colors, review stars, the white plate behind logos) are listed below
// with the reason.

$root = dirname( __DIR__, 2 );
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }

/* ---------- the names design-build.php writes ---------- */

define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; } // Enough of WordPress for the pure parts.
}
require $root . '/scout-core/includes/design.php';

$build = (string) file_get_contents( $root . '/tools/design-build.php' );
ok( false !== strpos( $build, "'--scout-' . str_replace( '_', '-', \$k )" ), 'design-build.php writes colors as --scout-<name>' );
ok( false !== strpos( $build, "'--scout-radius-' . \$k" ), 'design-build.php writes corners as --scout-radius-<name>' );
ok( false !== strpos( $build, "'--scout-font-' . \$role" ), 'design-build.php writes fonts as --scout-font-<role>' );

$d       = Scout_Core_Design::defaults();
$written = array();
foreach ( array_keys( $d['colors'] ) as $k ) {
	$written[] = '--scout-' . str_replace( '_', '-', $k );
}
foreach ( array_keys( $d['radius'] ) as $k ) {
	$written[] = '--scout-radius-' . $k;
}
foreach ( array_keys( $d['fonts'] ) as $k ) {
	$written[] = '--scout-font-' . $k;
}
// design-build.php writes any extra color a DESIGN.md adds; plugins may ask for this one first.
$optional = array( '--scout-accent-text' );
$design   = array_merge( $written, $optional );

/* ---------- what is scanned ---------- */

// Front-end stylesheets only. Admin CSS, the review page (its colors come from
// Review settings and it loads no theme CSS) and emails (no CSS variables in
// email) are not part of this contract.
$files = array(
	'scout-contact/assets/contact.css',
	'scout-chat/assets/chat.css',
	'scout-sitecheck/assets/sitecheck.css',
	'scout-reviews/assets/scout-reviews.css',
	'scout-booking/assets/scout-booking.css',
	'scout-events/assets/scout-events.css',
	'scout-members/assets/scout-members.css',
	'scout-partners/assets/scout-partners.css',
	'scout-results/assets/scout-results.css',
	'scout-hours/assets/scout-hours.css',
	'scout-accessibility/assets/statement.css',
);

// file => [ text in the declaration, why it stays fixed ]
$exceptions = array(
	'scout-contact/assets/contact.css'         => array(
		array( 'color: #F5F5F7', 'The bar is dark glass with light text on every site (README).' ),
	),
	'scout-chat/assets/chat.css'               => array(
		array( 'background: #0E9E73', 'The green "online" dot is a status color; DESIGN.md has none.' ),
		array( 'color: #F2553D', 'The error line is a status color; DESIGN.md has none.' ),
	),
	'scout-sitecheck/assets/sitecheck.css'     => array(
		array( '--ssc-pass:var(--positive,#0E9E73)', 'Pass is a status color; DESIGN.md has none.' ),
		array( '--ssc-warn:var(--caution,#FBB024)', 'Warning is a status color; DESIGN.md has none.' ),
		array( '--ssc-fail:var(--negative,#F2553D)', 'Fail is a status color; DESIGN.md has none.' ),
		array( 'border:solid #fff', 'The tick sits on the green pass color.' ),
		array( 'color:#fff;background:var(--ssc-text-3)', 'Icon text on the status colors.' ),
		array( 'color:#1D1D1F', 'Dark icon text on the amber warning color.' ),
		array( '--ssc-surface:#fff', 'The .ssc-admin rows sit in wp-admin and use its colors.' ),
		array( '--ssc-text:#1d2327', 'The .ssc-admin rows sit in wp-admin and use its colors.' ),
		array( '--ssc-text-2:#50575e', 'The .ssc-admin rows sit in wp-admin and use its colors.' ),
		array( '--ssc-text-3:#787c82', 'The .ssc-admin rows sit in wp-admin and use its colors.' ),
		array( '--ssc-line:#dcdcde', 'The .ssc-admin rows sit in wp-admin and use its colors.' ),
	),
	'scout-reviews/assets/scout-reviews.css'   => array(
		array( '--sr-star: #fbb024', 'Review stars are a fixed amber on every site (README).' ),
	),
	'scout-booking/assets/scout-booking.css'   => array(
		array( '--scout-booking-danger', 'Cancel and error red is a status color; DESIGN.md has none.' ),
		array( 'solid #0B7F5D', 'The "done" line is a status color.' ),
	),
	'scout-events/assets/scout-events.css'     => array(
		array( '#B42318', 'Cancelled and warning red is a status color; DESIGN.md has none.' ),
		array( '#0B7F5D', 'Checked-in green is a status color.' ),
		array( '.se-chip.is-done) { background: #E3F4EE; color: #0B5F45; }', 'The "done" chip is a status color pair.' ),
		array( '.se-chip.is-warn) { background: #FBF0DC; color: #7A4B00; }', 'The "needs you" chip is a status color pair.' ),
		array( 'background: #fff', 'Logos sit on a white plate so every logo reads.' ),
	),
	'scout-members/assets/scout-members.css'   => array(
		array( 'solid #B42318', 'The error note line is a status color.' ),
		array( 'solid #0B7F5D', 'The success note line is a status color.' ),
	),
	'scout-partners/assets/scout-partners.css' => array(
		array( '--scout-partners-logo-bg', 'Logos sit on a white plate so every logo reads (README).' ),
		array( 'color: #1D1D1F', 'A partner name without a logo sits on the white plate.' ),
	),
	'scout-hours/assets/scout-hours.css'       => array(
		array( '--scout-hours-closed', 'Closed red is a status color; DESIGN.md has none.' ),
		array( '--scout-hours-open', 'Open green is a status color.' ),
	),
);

/* ---------- the checker ---------- */

const CC_COLOR_PROPS = '/^(color|background|background-color|background-image|border|border-(top|right|bottom|left|block|inline)(-(start|end))?(-color)?|border-color|outline|outline-color|fill|stroke|caret-color|accent-color|text-decoration|text-decoration-color|column-rule|column-rule-color|box-shadow|text-shadow)$/';
const CC_FONT_PROPS  = '/^(font|font-family)$/';
const CC_COLORISH    = '/#[0-9a-f]{3,8}\b|\b(rgba?|hsla?|color-mix)\(|\bcurrentColor\b|\btransparent\b|\binherit\b/i';

/** Top-level var() spans in a value: [ [ 'text' => 'var(...)', 'name' => '--x', 'fallback' => '...' or null ] ]. */
function cc_vars( string $v ): array {
	$out = array();
	$len = strlen( $v );
	for ( $i = 0; $i < $len; $i++ ) {
		if ( $i + 4 > $len || 0 !== substr_compare( $v, 'var(', $i, 4, true ) || ( $i > 0 && preg_match( '/[\w-]/', $v[ $i - 1 ] ) ) ) {
			continue;
		}
		$depth = 0;
		for ( $j = $i + 3; $j < $len; $j++ ) {
			if ( '(' === $v[ $j ] ) {
				$depth++;
			} elseif ( ')' === $v[ $j ] && 0 === --$depth ) {
				break;
			}
		}
		$text  = substr( $v, $i, $j - $i + 1 );
		$inner = substr( $text, 4, -1 );
		$comma = strpos( $inner, ',' );
		$out[] = array(
			'text'     => $text,
			'start'    => $i,
			'end'      => $j,
			'name'     => trim( false === $comma ? $inner : substr( $inner, 0, $comma ) ),
			'fallback' => false === $comma ? null : trim( substr( $inner, $comma + 1 ) ),
		);
		$i = $j;
	}
	return $out;
}

/** Does the text hold one of the DESIGN.md names? */
function cc_has_design( string $text, array $design ): bool {
	foreach ( $design as $name ) {
		if ( preg_match( '/' . preg_quote( $name, '/' ) . '(?![\w-])/', $text ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Problems in one stylesheet: [ line, declaration, why, the hex or variable at fault ].
 *
 * @param string   $css    The stylesheet.
 * @param string[] $design DESIGN.md variable names.
 */
function cc_check( string $css, array $design ): array {
	// Comments out, line breaks kept so line numbers hold.
	$css     = preg_replace_callback( '#/\*.*?\*/#s', static fn( $m ) => str_repeat( "\n", substr_count( $m[0], "\n" ) ), $css );
	$defined = array();
	preg_match_all( '/(?<![\w-])(--[\w-]+)\s*:/', $css, $m );
	foreach ( $m[1] as $n ) {
		$defined[ $n ] = true;
	}
	$problems = array();
	preg_match_all( '/(?<=[{;])\s*(-{0,2}[a-z][\w-]*)\s*:\s*([^;{}]+)/i', $css, $decls, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );
	foreach ( $decls as $d ) {
		$prop   = strtolower( $d[1][0] );
		$value  = trim( $d[2][0] );
		$line   = substr_count( substr( $css, 0, $d[0][1] ), "\n" ) + 1;
		$custom = 0 === strpos( $prop, '--' );
		$font   = (bool) preg_match( CC_FONT_PROPS, $prop );
		$color  = (bool) preg_match( CC_COLOR_PROPS, $prop );
		if ( ! $custom && ! $font && ! $color ) {
			continue;
		}
		$decl = $prop . ': ' . $value;
		$vars = cc_vars( $value );
		// 1. A hex outside every var(), or inside a var() chain with no DESIGN.md name.
		if ( preg_match_all( '/#[0-9a-f]{3,8}\b/i', $value, $hex, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $hex[0] as $h ) {
				$in = null;
				foreach ( $vars as $v ) {
					if ( $h[1] > $v['start'] && $h[1] < $v['end'] ) {
						$in = $v;
					}
				}
				if ( null === $in ) {
					$problems[] = array( $line, $decl, 'bare ' . $h[0] . ' (put it at the end of a chain with a DESIGN.md name)', $h[0] );
				} elseif ( ! cc_has_design( $in['text'], $design ) ) {
					$problems[] = array( $line, $decl, $h[0] . ' in ' . $in['name'] . ' with no DESIGN.md step', $h[0] );
				}
			}
		}
		// 2. A var() that brings its own color or font default but has no DESIGN.md step.
		foreach ( $vars as $v ) {
			if ( in_array( $v['name'], $design, true ) || cc_has_design( $v['text'], $design ) ) {
				continue;
			}
			$fb = (string) $v['fallback'];
			if ( null === $v['fallback'] ) {
				// var(--alias) with no default: fine when this sheet defines the alias.
				if ( isset( $defined[ $v['name'] ] ) || ( ! $color && ! $font ) ) {
					continue;
				}
				$problems[] = array( $line, $decl, $v['name'] . ' is not set in this plugin and has no DESIGN.md step or default', $v['name'] );
				continue;
			}
			$fontish = $font || preg_match( '/^--[\w-]*(font|mono|sans|serif)/', $v['name'] ) || preg_match( '/["\']|\b(monospace|sans-serif|serif)\b/', $fb );
			if ( preg_match( CC_COLORISH, $fb ) || $fontish ) {
				if ( 1 === preg_match( '/#[0-9a-f]{3,8}\b/i', $fb ) ) {
					continue; // Already reported by check 1.
				}
				$problems[] = array( $line, $decl, $v['name'] . ' falls back to "' . $fb . '" with no DESIGN.md step', $v['name'] );
			}
		}
		// 3. A font family typed outside a var() chain.
		if ( $font ) {
			$outside = $value;
			foreach ( array_reverse( $vars ) as $v ) {
				$outside = substr( $outside, 0, $v['start'] ) . substr( $outside, $v['end'] + 1 );
			}
			if ( preg_match( '/["\']/', $outside ) ) {
				$problems[] = array( $line, $decl, 'a font family typed outside a chain with --scout-font-*', '' );
			}
		}
	}
	return $problems;
}

/* ---------- the checker catches what it should ---------- */

$sample = <<<'CSS'
.x { color: #fff; }
.x { --a: var(--accent, #0E8FE6); }
.x { border: 1px solid var(--line, rgba(0,0,0,.1)); }
.x { font-family: var(--font-mono, "Geist Mono", monospace); }
.x { font-family: "Geist Mono", monospace; }
.x { background: var(--surface); }
.x { --ok: var(--accent, var(--scout-accent, #0E8FE6)); color: var(--ok); }
.x { font: 16px/1.4 var(--font-sans, var(--scout-font-body, 'Hanken Grotesk', sans-serif)); }
.x { border-color: var(--scout-accent-text, var(--scout-accent, #5FB8F2)); }
.x { background: linear-gradient(90deg, var(--ok) var(--pct, 100%), transparent 0); }
CSS;
$found = cc_check( $sample, $design );
$lines = array_unique( array_map( static fn( $p ) => $p[0], $found ) );
sort( $lines );
ok( array( 1, 2, 3, 4, 5, 6 ) === array_values( $lines ), 'the checker flags a bare hex, single var() chains, typed fonts and an unset variable, and passes full chains (flagged lines: ' . implode( ', ', $lines ) . ')' );

/* ---------- the plugins ---------- */

$used_exceptions = array();
foreach ( $files as $rel ) {
	$path = $root . '/' . $rel;
	if ( ! is_readable( $path ) ) {
		ok( false, "$rel exists" );
		continue;
	}
	$css      = (string) file_get_contents( $path );
	$problems = cc_check( $css, $design );
	$src      = explode( "\n", $css );
	$squash   = static fn( $t ) => preg_replace( '/\s+/', '', (string) $t );
	$left     = array();
	foreach ( $problems as $p ) {
		$excused = false;
		foreach ( $exceptions[ $rel ] ?? array() as $i => $e ) {
			// The listed text is in the declaration, or it is on the same line and names the hex or variable at fault.
			$needle = $squash( $e[0] );
			$in_decl = false !== strpos( $squash( $p[1] ), $needle );
			$on_line = false !== strpos( $squash( $src[ $p[0] - 1 ] ?? '' ), $needle ) && '' !== $p[3] && false !== stripos( $needle, $p[3] );
			if ( $in_decl || $on_line ) {
				$excused                          = true;
				$used_exceptions[ $rel . '#' . $i ] = true;
			}
		}
		if ( ! $excused ) {
			$left[] = $p;
		}
	}
	foreach ( $left as $p ) {
		echo "  $rel:{$p[0]}  {$p[2]}\n    {$p[1]}\n";
	}
	ok( ! $left, $rel . ' reads DESIGN.md for every color and font' . ( $left ? ' (' . count( $left ) . ' to fix)' : '' ) );

	// Every --scout-* design-looking name it asks for is one design-build.php writes.
	preg_match_all( '/--scout-(?:bg|surface[\w-]*|text[\w-]*|line[\w-]*|accent[\w-]*|on-accent[\w-]*|radius-[\w-]+|font-[\w-]+)(?![\w-])/', preg_replace( '#/\*.*?\*/#s', '', $css ), $names );
	$unknown = array_diff( array_unique( $names[0] ), $design );
	ok( ! $unknown, $rel . ' asks only for names design-build.php writes' . ( $unknown ? ' (unknown: ' . implode( ', ', $unknown ) . ')' : '' ) );
}

// An exception nobody needs any more goes.
foreach ( $exceptions as $rel => $list ) {
	foreach ( $list as $i => $e ) {
		ok( isset( $used_exceptions[ $rel . '#' . $i ] ), "exception still needed: $rel \"{$e[0]}\"" );
	}
}

echo $fail ? "\n$fail check(s) failed. Site Standard rule 3: colors and fonts come from DESIGN.md.\n" : "\nEvery listed plugin takes its colors and fonts from DESIGN.md, with today's variables first.\n";
exit( $fail ? 1 : 0 );
