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

/* ---------- the extended token set (Client Design Playbook, decision 2) ---------- */

// Absent keys fall back to the defaults, and the highlight pair follows the accent.
$old = Scout_Core_Design::derive( $merged, $t['colors'] );
ok( '#0B0B0B' === $old['colors']['night'] && '#F5F5F7' === $old['colors']['on_night'] && '#B5B5B8' === $old['colors']['on_night_2'], 'defaults: night band and its text when DESIGN.md has none' );
ok( '#0E9E73' === $old['colors']['good'] && '#FBB024' === $old['colors']['warn'] && '#F2553D' === $old['colors']['bad'], 'defaults: good, warn and bad' );
ok( 'clamp(40px, 7vw, 72px)' === $old['type']['hero'] && 'clamp(30px, 4.4vw, 46px)' === $old['type']['h2'] && '17px' === $old['type']['body'] && '15px' === $old['type']['small'] && '12px' === $old['type']['label'], 'defaults: the type scale' );
ok( '8px' === $old['space']['1'] && '64px' === $old['space']['8'] && '1160px' === $old['space']['container'] && '24px' === $old['space']['gutter'], 'defaults: the 8px space scale, container and gutter' );
ok( '200ms' === $old['motion']['dur_fast'] && '280ms' === $old['motion']['dur_enter'] && '' !== $old['motion']['ease_out'] && '' !== $old['motion']['ease_in'], 'defaults: motion' );
ok( '#0E8FE6' === $old['colors']['highlight'], 'derive: highlight follows the accent when DESIGN.md names none' );
ok( '#0A78C2' !== $old['colors']['highlight_text'], 'derive: the fill (4.2:1 on this dark page) is not used as highlight text' );
ok( Scout_Core_Design::contrast( $old['colors']['highlight_text'], '#0B0B0B' ) >= 4.5 && Scout_Core_Design::contrast( $old['colors']['highlight_text'], '#161616' ) >= 4.5, 'derive: highlight text reads at 4.5:1 on the page and on cards' );
$light = Scout_Core_Design::derive( Scout_Core_Design::defaults() );
ok( Scout_Core_Design::contrast( $light['colors']['highlight_text'], '#F5F5F7' ) >= 4.5 && Scout_Core_Design::contrast( $light['colors']['highlight_text'], '#FFFFFF' ) >= 4.5, 'derive: Scout light defaults get a readable highlight text' );
$orange = Scout_Core_Design::merge( Scout_Core_Design::defaults(), array( 'colors' => array( 'highlight' => '#FF7A00' ) ) );
$orange = Scout_Core_Design::derive( $orange, array( 'highlight' => '#FF7A00' ) );
ok( '#FF7A00' === $orange['colors']['highlight'] && Scout_Core_Design::contrast( $orange['colors']['highlight_text'], '#F5F5F7' ) >= 4.5, 'derive: a named highlight keeps its color and gets its own text shade' );
$kept = Scout_Core_Design::derive( $orange, array( 'highlight' => '#FF7A00', 'highlight_text' => '#123456' ) );
ok( $orange['colors']['highlight_text'] === $kept['colors']['highlight_text'], 'derive: nothing changes when both are named' );
ok( '#0A78C2' === Scout_Core_Design::text_shade( '#0A78C2', '#0E8FE6', '#1D1D1F', array( '#FFFFFF' ) ), 'text_shade: the first candidate stays when it passes' );
ok( '#0A78C2' === Scout_Core_Design::text_shade( '#0A78C2', '#0E8FE6', '#1D1D1F', array( 'rgba(0,0,0,.5)' ) ), 'text_shade: a translucent background is skipped' );

$md2 = <<<'MD'
---
design: 1
colors:
  bg: "#F5F5F7"
  surface: "#FFFFFF"
  text: "#1D1D1F"
  accent: "#D14C00"
  accent_fill: "#A83D00"
  night: "#101820"
  on_night: "#FFFFFF"
  on_night_2: "#777777"
  highlight: "#D14C00"
  highlight_text: "#8A3300"
  good: "#0E9E73"
  warn: "#FBB024"
  bad: "#F2553D"
type:
  hero: "clamp(44px, 8vw, 80px)"   # the biggest line
  h3: 24px
  body: "18px"
space:
  1: 4px
  2: 8px
  container: "1200px"
  gutter: 32
motion:
  dur_fast: 150ms
  ease_out: "cubic-bezier(.16,1,.3,1)"
---
MD;
$p2 = Scout_Core_Design::parse( $md2 );
$t2 = $p2['tokens'];
ok( '#101820' === ( $t2['colors']['night'] ?? '' ) && '#777777' === ( $t2['colors']['on_night_2'] ?? '' ), 'parse: night colors' );
ok( '#8A3300' === ( $t2['colors']['highlight_text'] ?? '' ) && '#F2553D' === ( $t2['colors']['bad'] ?? '' ), 'parse: highlight and meaning colors' );
ok( 'clamp(44px, 8vw, 80px)' === ( $t2['type']['hero'] ?? '' ) && '24px' === ( $t2['type']['h3'] ?? '' ), 'parse: type scale, clamp() kept whole, comment dropped' );
ok( '4px' === ( $t2['space']['1'] ?? '' ) && '8px' === ( $t2['space']['2'] ?? '' ) && '1200px' === ( $t2['space']['container'] ?? '' ), 'parse: space keys that are numbers' );
ok( 32 === ( $t2['space']['gutter'] ?? 0 ), 'parse: a bare number stays a number' );
ok( '150ms' === ( $t2['motion']['dur_fast'] ?? '' ) && 'cubic-bezier(.16,1,.3,1)' === ( $t2['motion']['ease_out'] ?? '' ), 'parse: motion' );

$m2 = Scout_Core_Design::derive( Scout_Core_Design::merge( Scout_Core_Design::defaults(), $t2 ), $t2['colors'] );
ok( '#D14C00' === $m2['colors']['highlight'] && '#8A3300' === $m2['colors']['highlight_text'], 'merge: named highlight pair wins over the derived one' );
ok( 'clamp(30px, 4.4vw, 46px)' === $m2['type']['h2'] && '24px' === $m2['space']['3'] && '280ms' === $m2['motion']['dur_enter'], 'merge: scale keys the file skips keep their defaults' );

$issues2 = Scout_Core_Design::issues( $m2 );
$by2     = array();
foreach ( $issues2 as $i ) { $by2[ substr( $i['pair'], 0, strpos( $i['pair'], ' (' ) ) ] = $i; }
ok( 11 === count( $issues2 ), 'issues: eleven pairs when every color is readable' );
foreach ( array( 'Button text on the accent fill', 'Text on the page', 'Text on cards', 'Muted text on the page' ) as $label ) {
	ok( isset( $by2[ $label ] ), "issues: the original pair \"$label\" is still checked" );
}
ok( isset( $by2['Text on the dark band'] ) && 'fail' === $by2['Text on the dark band']['level'] && $by2['Text on the dark band']['ok'] && 4.5 === $by2['Text on the dark band']['need'], 'issues: on_night on night (fail level) passes here' );
ok( isset( $by2['Muted text on the dark band'] ) && 'warn' === $by2['Muted text on the dark band']['level'] && ! $by2['Muted text on the dark band']['ok'], 'issues: on_night_2 on night is a warning when under 4.5' );
ok( isset( $by2['Highlight text on the page'] ) && 'fail' === $by2['Highlight text on the page']['level'] && $by2['Highlight text on the page']['ok'], 'issues: highlight_text on bg (fail level)' );
ok( isset( $by2['Highlight text on cards'] ) && 'fail' === $by2['Highlight text on cards']['level'] && $by2['Highlight text on cards']['ok'], 'issues: highlight_text on surface (fail level)' );
foreach ( array( 'Good', 'Warn', 'Bad' ) as $label ) {
	$row = $by2[ "$label as text on cards" ] ?? null;
	ok( $row && 'warn' === $row['level'] && ! $row['ok'] && 4.5 === $row['need'], "issues: $label on surface is a warning, not a failure" );
}
$dim = $m2; $dim['colors']['on_night'] = '#555555';
$dim_issues = Scout_Core_Design::issues( $dim );
$dim_row = array_values( array_filter( $dim_issues, static fn( $i ) => 0 === strpos( $i['pair'], 'Text on the dark band' ) ) )[0];
ok( ! $dim_row['ok'] && 'fail' === $dim_row['level'], 'issues: dim text on the dark band fails' );
$pale = $m2; $pale['colors']['highlight_text'] = '#FFB380';
ok( 2 === count( array_filter( Scout_Core_Design::issues( $pale ), static fn( $i ) => ! $i['ok'] && 'fail' === $i['level'] ) ), 'issues: a pale highlight text fails on the page and on cards' );

$vars = Scout_Core_Design::variables( $m2 );
ok( '#101820' === $vars['--scout-night'] && '#777777' === $vars['--scout-on-night-2'] && '#8A3300' === $vars['--scout-highlight-text'] && '#0E9E73' === $vars['--scout-good'], 'variables: new colors, underscores to hyphens' );
ok( 'clamp(44px, 8vw, 80px)' === $vars['--scout-fs-hero'] && '12px' === $vars['--scout-fs-label'], 'variables: --scout-fs-* from the type scale' );
ok( '4px' === $vars['--scout-space-1'] && '24px' === $vars['--scout-space-3'] && '1200px' === $vars['--scout-container'] && '32px' === $vars['--scout-gutter'], 'variables: --scout-space-*, container and gutter; a bare number means px' );
ok( '150ms' === $vars['--scout-dur-fast'] && '280ms' === $vars['--scout-dur-enter'] && 'cubic-bezier(.16,1,.3,1)' === $vars['--scout-ease-out'], 'variables: --scout-dur-* and --scout-ease-*' );
$css2 = Scout_Core_Design::css();
ok( false !== strpos( $css2, '--scout-night:#0B0B0B;' ) && false !== strpos( $css2, '--scout-fs-hero:clamp(40px, 7vw, 72px);' ) && false !== strpos( $css2, '--scout-space-8:64px;' ) && false !== strpos( $css2, '--scout-ease-in:cubic-bezier(.4,0,1,1);' ), 'css: the defaults carry the new variables' );

echo $fail ? "\n$fail FAILED\n" : "\nAll design checks passed\n";
exit( $fail ? 1 : 0 );
