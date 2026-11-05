<?php
/**
 * Build a theme's design files from its DESIGN.md (Scout Site Standard §4).
 *
 *   php tools/design-build.php <theme-dir>           write theme.json tokens and assets/css/design-tokens.css
 *   php tools/design-build.php <theme-dir> --check   write nothing; exit 1 when the files are out of date
 *
 * One source: the front matter on top of DESIGN.md. From it:
 *   - theme.json (block themes, or any theme that already has one): the palette slugs
 *     bg, surface, surface-2, text, text-2, line, accent, accent-fill, on-accent, night,
 *     on-night, on-night-2, highlight, highlight-text, good, warn, bad; the font families
 *     heading, body, label (with @font-face when a file is named); and
 *     settings.custom.scout (mode, radius, logo, mark). Entries the theme already has
 *     under other slugs are kept as they are. The type, space and motion scales stay
 *     out of theme.json.
 *   - assets/css/design-tokens.css: a :root block of --scout-* variables, scales included
 *     (classic themes enqueue it; block themes may too).
 * The contrast pairs §4 requires must pass 4.5:1 or nothing is written and the build
 * fails: button text on the accent fill, text on the page, text on cards, text on the
 * dark band, highlight text on the page and on cards.
 *
 * Uses Scout Core's own reader (scout-core/includes/design.php), so the theme and
 * the plugins can never read the same file two ways. Needs PHP only, no WordPress.
 */

$args  = array_slice( $argv, 1 );
$check = in_array( '--check', $args, true );
$dir   = '';
foreach ( $args as $a ) {
	if ( '' === $dir && '-' !== $a[0] ) {
		$dir = rtrim( $a, '/' );
	}
}
if ( '' === $dir || ! is_dir( $dir ) ) {
	fwrite( STDERR, "Usage: php tools/design-build.php <theme-dir> [--check]\n" );
	exit( 2 );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}
require __DIR__ . '/../scout-core/includes/design.php';

$design_md = $dir . '/DESIGN.md';
$parsed    = Scout_Core_Design::file( $design_md );
if ( null === $parsed ) {
	echo "::error file={$design_md}::No DESIGN.md in {$dir}. Site Standard §3 and §4: the theme's design lives in DESIGN.md.\n";
	exit( 1 );
}
$tokens = Scout_Core_Design::merge( Scout_Core_Design::defaults(), $parsed['tokens'] );
$tokens = Scout_Core_Design::derive( $tokens, (array) ( $parsed['tokens']['colors'] ?? array() ) );
if ( empty( $parsed['tokens']['colors'] ) ) {
	echo "::error file={$design_md}::DESIGN.md has no colors block in its front matter (see Site Standard §4 for the format).\n";
	exit( 1 );
}

// Contrast gate.
$failed = 0;
foreach ( Scout_Core_Design::issues( $tokens ) as $i ) {
	if ( ! $i['ok'] && 'fail' === $i['level'] ) {
		echo "::error file={$design_md}::Contrast {$i['ratio']}:1, needs {$i['need']}:1: {$i['pair']}. Darken the fill or the text.\n";
		$failed++;
	} elseif ( ! $i['ok'] ) {
		echo "::warning file={$design_md}::Contrast {$i['ratio']}:1 (under {$i['need']}:1): {$i['pair']}.\n";
	}
}
if ( $failed ) {
	exit( 1 );
}

$out_of_date = 0;
$written     = array();

/* ---------- theme.json ---------- */
$tj_path  = $dir . '/theme.json';
$is_block = file_exists( $tj_path ) || is_dir( $dir . '/templates' );
if ( $is_block ) {
	$tj = file_exists( $tj_path ) ? json_decode( (string) file_get_contents( $tj_path ), true ) : null;
	if ( file_exists( $tj_path ) && ! is_array( $tj ) ) {
		echo "::error file={$tj_path}::theme.json is not valid JSON.\n";
		exit( 1 );
	}
	if ( ! is_array( $tj ) ) {
		$tj = array( '$schema' => 'https://schemas.wp.org/trunk/theme.json', 'version' => 3, 'settings' => array() );
	}
	$names = array(
		'bg'          => 'Page background',
		'surface'     => 'Surface',
		'surface_2'   => 'Surface 2',
		'text'        => 'Text',
		'text_2'      => 'Muted text',
		'line'        => 'Hairline',
		'accent'      => 'Accent',
		'accent_fill' => 'Accent fill',
		'on_accent'   => 'On accent',
		'night'          => 'Night',
		'on_night'       => 'On night',
		'on_night_2'     => 'Muted on night',
		'highlight'      => 'Highlight',
		'highlight_text' => 'Highlight text',
		'good'           => 'Good',
		'warn'           => 'Warn',
		'bad'            => 'Bad',
	);
	$palette = (array) ( $tj['settings']['color']['palette'] ?? array() );
	$by_slug = array();
	foreach ( $palette as $i => $c ) {
		$by_slug[ (string) ( $c['slug'] ?? '' ) ] = $i;
	}
	foreach ( $names as $token => $name ) {
		$color = (string) ( $tokens['colors'][ $token ] ?? '' );
		if ( '' === $color ) {
			continue;
		}
		$slug  = str_replace( '_', '-', $token );
		$entry = array( 'slug' => $slug, 'color' => $color, 'name' => $name );
		if ( isset( $by_slug[ $slug ] ) ) {
			$palette[ $by_slug[ $slug ] ] = $entry;
		} else {
			$palette[] = $entry;
		}
	}
	$tj['settings']['color']['palette'] = array_values( $palette );

	$families = (array) ( $tj['settings']['typography']['fontFamilies'] ?? array() );
	$fam_by   = array();
	foreach ( $families as $i => $f ) {
		$fam_by[ (string) ( $f['slug'] ?? '' ) ] = $i;
	}
	foreach ( array( 'heading', 'body', 'label' ) as $role ) {
		$f = (array) ( $tokens['fonts'][ $role ] ?? array() );
		if ( empty( $f['family'] ) ) {
			continue;
		}
		$generic = ( false !== stripos( (string) $f['family'], 'mono' ) ) ? 'monospace' : ( preg_match( '/serif/i', (string) $f['family'] ) && ! preg_match( '/sans/i', (string) $f['family'] ) ? 'serif' : 'sans-serif' );
		$entry   = array(
			'slug'       => $role,
			'name'       => ucfirst( $role ) . ': ' . $f['family'],
			'fontFamily' => '"' . $f['family'] . '", ' . $generic,
		);
		if ( ! empty( $f['file'] ) ) {
			$entry['fontFace'] = array(
				array(
					'fontFamily' => (string) $f['family'],
					'fontWeight' => (string) ( $f['weight'] ?? '400' ),
					'fontStyle'  => 'normal',
					'fontDisplay' => 'swap',
					'src'        => array( 'file:./' . ltrim( (string) $f['file'], './' ) ),
				),
			);
		}
		if ( isset( $fam_by[ $role ] ) ) {
			$families[ $fam_by[ $role ] ] = $entry;
		} else {
			$families[] = $entry;
		}
	}
	$tj['settings']['typography']['fontFamilies'] = array_values( $families );

	$tj['settings']['custom']['scout'] = array(
		'mode'   => (string) $tokens['mode'],
		'radius' => (array) $tokens['radius'],
		'logo'   => (string) ( $tokens['logo'] ?? '' ),
		'mark'   => (string) ( $tokens['mark'] ?? '' ),
	);

	$new_json = json_encode( $tj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	$old      = file_exists( $tj_path ) ? json_decode( (string) file_get_contents( $tj_path ), true ) : null;
	$same     = is_array( $old )
		&& ( $old['settings']['color']['palette'] ?? null ) === $tj['settings']['color']['palette']
		&& ( $old['settings']['typography']['fontFamilies'] ?? null ) === $tj['settings']['typography']['fontFamilies']
		&& ( $old['settings']['custom']['scout'] ?? null ) === $tj['settings']['custom']['scout'];
	if ( ! $same ) {
		if ( $check ) {
			echo "::error file={$tj_path}::theme.json does not match DESIGN.md. Run: php tools/design-build.php {$dir}\n";
			$out_of_date++;
		} else {
			file_put_contents( $tj_path, $new_json );
			$written[] = $tj_path;
		}
	}
}

/* ---------- assets/css/design-tokens.css ---------- */
$css_dir  = $dir . '/assets/css';
$css_path = $css_dir . '/design-tokens.css';
// css() reads the live site; build the block from these tokens instead, the same way.
$vars                 = Scout_Core_Design::variables( $tokens );
$vars['--scout-mode'] = preg_replace( '/[^\w-]/', '', (string) $tokens['mode'] );
$css                  = "/* Generated from DESIGN.md by Scout-Plugins/tools/design-build.php. Edit DESIGN.md, not this file. */\n:root {\n";
foreach ( $vars as $name => $value ) {
	$css .= "\t{$name}: {$value};\n";
}
$css .= "}\n";
$old_css = file_exists( $css_path ) ? (string) file_get_contents( $css_path ) : null;
if ( $old_css !== $css ) {
	if ( $check ) {
		echo "::error file={$css_path}::design-tokens.css does not match DESIGN.md. Run: php tools/design-build.php {$dir}\n";
		$out_of_date++;
	} else {
		if ( ! is_dir( $css_dir ) ) {
			mkdir( $css_dir, 0755, true );
		}
		file_put_contents( $css_path, $css );
		$written[] = $css_path;
	}
}

if ( $check ) {
	echo $out_of_date ? "Design files out of date: {$out_of_date}\n" : "Design files match DESIGN.md.\n";
	exit( $out_of_date ? 1 : 0 );
}
echo $written ? 'Wrote: ' . implode( ', ', $written ) . "\n" : "Design files already match DESIGN.md.\n";
