<?php
// Design module checks: the DESIGN.md parser, token merging, contrast math and
// scout_core_owns(). Runs without WordPress:
//   php tools/tests/design.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
function apply_filters( $tag, $value ) { return $value; } // Enough of WordPress for the pure parts.
require __DIR__ . '/../../scout-core/includes/design.php';

$md = <<<'MD'
---
design: 1
name: Scout Media
mode: dark                  # light or dark
colors:
  bg: "#0B0B0B"             # page canvas
  surface: "#161616"
  text: "#F5F5F7"
  text_2: "#B5B5B8"
  line: "rgba(255,255,255,.10)"
  accent: "#0E8FE6"         # the one spark
  accent_fill: "#0A78C2"
  on_accent: "#FFFFFF"
fonts:
  heading: { family: "Hanken Grotesk", weight: 600, file: "assets/fonts/hk-600.woff2" }
  label:   { family: "Geist Mono", weight: 500, case: upper, tracking: "1.4px" }
radius: { pill: "980px", card: "18px" }
logo: assets/img/logo.svg   # a comment with a # inside "quotes" stays out
---

# Scout Media: design rules

Some prose with a colon: it is not a token.

## Feature: call buttons
- bar: "#0A78C2"
- button style: pill
- which buttons: call, text

## Feature: Reviews (scout-reviews asks: star color)
star color: "#FBB024"
surface: "#1E1E1E"

## Something else
accent: "#FF0000"
MD;

$p = Scout_Core_Design::parse( $md );
$t = $p['tokens'];
ok( 'Scout Media' === ( $t['name'] ?? '' ), 'name read' );
ok( 'dark' === ( $t['mode'] ?? '' ), 'comment after a value is dropped' );
ok( '#0B0B0B' === ( $t['colors']['bg'] ?? '' ), 'nested color read, quotes removed, # kept' );
ok( 'rgba(255,255,255,.10)' === ( $t['colors']['line'] ?? '' ), 'rgba value kept whole' );
ok( 600 === ( $t['fonts']['heading']['weight'] ?? 0 ), 'inline map: number' );
ok( 'Hanken Grotesk' === ( $t['fonts']['heading']['family'] ?? '' ), 'inline map: quoted string with a space' );
ok( 'upper' === ( $t['fonts']['label']['case'] ?? '' ) && '1.4px' === ( $t['fonts']['label']['tracking'] ?? '' ), 'inline map: several keys' );
ok( '18px' === ( $t['radius']['card'] ?? '' ), 'inline radius map' );
ok( 'assets/img/logo.svg' === ( $t['logo'] ?? '' ), 'unquoted path with a trailing comment' );

$f = $p['features'];
ok( isset( $f['call_buttons'] ), 'feature heading becomes a key' );
ok( '#0A78C2' === ( $f['call_buttons']['bar'] ?? '' ), 'bullet line read' );
ok( 'pill' === ( $f['call_buttons']['button_style'] ?? '' ), 'multi-word key becomes snake_case' );
ok( 'call, text' === ( $f['call_buttons']['which_buttons'] ?? '' ), 'value with a comma kept' );
ok( '#FBB024' === ( $f['reviews']['star_color'] ?? '' ), 'heading with a parenthesised note; plain key: value lines' );
ok( ! isset( $f['something_else'] ), 'a heading that is not Feature: ends the section' );
ok( ! isset( $f['reviews']['accent'] ), 'lines after the next heading are not read into the feature' );

$merged = Scout_Core_Design::merge( Scout_Core_Design::defaults(), $t );
ok( '#0B0B0B' === $merged['colors']['bg'] && '#EDEDF0' === $merged['colors']['surface_2'], 'merge: file wins, defaults fill the gaps' );
ok( 400 === $merged['fonts']['body']['weight'] && 'Hanken Grotesk' === $merged['fonts']['body']['family'], 'merge: untouched nested defaults stay' );
$empty = Scout_Core_Design::merge( array( 'a' => 'keep' ), array( 'a' => '' ) );
ok( 'keep' === $empty['a'], 'merge: an empty string never erases a value' );

ok( 21.0 === Scout_Core_Design::contrast( '#000000', '#FFFFFF' ), 'contrast black on white is 21' );
ok( 1.0 === Scout_Core_Design::contrast( '#777', '#777777' ), 'contrast: short hex, same color is 1' );
ok( abs( Scout_Core_Design::contrast( '#FFFFFF', '#0A78C2' ) - 4.69 ) < 0.05, 'white on the accent fill is about 4.69 (passes AA)' );
ok( abs( Scout_Core_Design::contrast( '#FFFFFF', '#0E8FE6' ) - 3.45 ) < 0.05, 'white on bright cerulean is about 3.45 (fails AA)' );
ok( 0.0 === Scout_Core_Design::contrast( '#FFF', 'rgba(0,0,0,.5)' ), 'translucent colors are not judged' );
ok( array( 14, 143, 230 ) === Scout_Core_Design::rgb( 'rgb(14, 143, 230)' ), 'rgb() parsed' );

$issues = Scout_Core_Design::issues( $merged );
$by = array();
foreach ( $issues as $i ) { $by[ substr( $i['pair'], 0, strpos( $i['pair'], ' (' ) ) ] = $i; }
ok( ! empty( $by['Button text on the accent fill']['ok'] ), 'issues: the Scout fill passes' );
ok( ! empty( $by['Text on the page']['ok'] ), 'issues: light text on the dark page passes' );
$bad = $merged; $bad['colors']['accent_fill'] = '#0E8FE6';
$bad_issues = Scout_Core_Design::issues( $bad );
ok( in_array( false, array_column( $bad_issues, 'ok' ), true ), 'issues: a bright fill under white text fails' );

ok( false === Scout_Core_Design::owns( 'hours' ), 'owns: no plugin loaded means false' );
ok( false === Scout_Core_Design::owns( 'nonsense' ), 'owns: unknown jobs are false' );
eval( 'class Scout_Hours_Front {}' ); // Declared now, not hoisted, so the check above was honest.
ok( true === Scout_Core_Design::owns( 'Hours' ), 'owns: the plugin class makes it true (any case)' );

$css = Scout_Core_Design::css();
ok( 0 === strpos( $css, ':root{' ) && false !== strpos( $css, '--scout-accent-fill:#0A78C2;' ), 'css: a :root block with the fill' );
ok( false !== strpos( $css, '--scout-radius-pill:980px;' ) && false !== strpos( $css, '--scout-font-heading:"Hanken Grotesk", sans-serif;' ), 'css: radius and font variables' );

echo $fail ? "\n$fail FAILED\n" : "\nAll design checks passed\n";
exit( $fail ? 1 : 0 );
