<?php
// Theme kit style guide checks: the "## Brief" reader and the contrast pairs
// behind the color swatches. Runs without WordPress:
//   php tools/tests/style-guide.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
function apply_filters( $tag, $value ) { return $value; } // Enough of WordPress for the pure parts.
function __( $text, $domain = '' ) { return $text; }
require __DIR__ . '/../../scout-core/includes/design.php';
require __DIR__ . '/../theme-kit/style-guide/scout-style-guide.php';

$md = <<<'MD'
---
design: 1
name: Example Co
---

# Example Co: design rules

## Brief

**Register:** plain, warm, sure of itself. Short sentences.

### Signature element
A thick rule above every heading, in the accent. [Read why](docs/decisions.md).

### Never
- No stock photos of handshakes
- No carousels
- No gradients

## Feature: call buttons
- bar: "#0A78C2"
MD;

$blocks = Scout_Theme_Style_Guide::brief_blocks( $md );
ok( 5 === count( $blocks ), 'brief: five blocks (p, h3, p, h3, list), got ' . count( $blocks ) );
ok( 'p' === $blocks[0]['type'] && 'Register: plain, warm, sure of itself. Short sentences.' === $blocks[0]['text'], 'brief: emphasis dropped from the first paragraph' );
ok( 'h3' === $blocks[1]['type'] && 'Signature element' === $blocks[1]['text'], 'brief: ### becomes h3' );
ok( 'p' === $blocks[2]['type'] && 'A thick rule above every heading, in the accent. Read why.' === $blocks[2]['text'], 'brief: link text kept, address dropped' );
ok( 'list' === $blocks[4]['type'] && 3 === count( $blocks[4]['items'] ) && 'No carousels' === $blocks[4]['items'][1], 'brief: the no list' );
ok( array() === Scout_Theme_Style_Guide::brief_blocks( "---\nname: x\n---\n\n## Feature: x\n- a: b\n" ), 'brief: none when there is no ## Brief heading' );
ok( array() === Scout_Theme_Style_Guide::brief_blocks( '' ), 'brief: empty file' );
$stops = Scout_Theme_Style_Guide::brief_blocks( "## Brief\nOne line.\n## Feature: call buttons\n- bar: x\n" );
ok( 1 === count( $stops ) && 'One line.' === $stops[0]['text'], 'brief: stops at the next ## heading' );

$pairs = Scout_Theme_Style_Guide::pairs( Scout_Core_Design::defaults()['colors'] );
$by    = array();
foreach ( $pairs as $p ) { $by[ $p['fg'] . ' on ' . $p['bg'] ] = $p; }
ok( isset( $by['text on bg'] ) && $by['text on bg']['ok'] && $by['text on bg']['ratio'] > 15, 'pairs: body text on the page passes' );
ok( isset( $by['on_accent on accent_fill'] ) && $by['on_accent on accent_fill']['ok'], 'pairs: button text on the fill passes' );
ok( isset( $by['on_accent on accent'] ) && ! $by['on_accent on accent']['ok'], 'pairs: white on the lighter accent is below 4.5 and says so' );
ok( ! isset( $by['text on line'] ), 'pairs: nothing is measured on the translucent hairline' );
$few = Scout_Theme_Style_Guide::pairs( array( 'bg' => '#FFFFFF', 'text' => '#000000' ) );
ok( 1 === count( $few ) && 21.0 === $few[0]['ratio'], 'pairs: only the tokens that exist, black on white is 21:1' );

ok( array_keys( Scout_Theme_Style_Guide::roles() ) === array_keys( Scout_Core_Design::defaults()['colors'] ), 'roles: every default token has a role, in the same order' );

exit( $fail ? 1 : 0 );
