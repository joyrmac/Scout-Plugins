<?php
// Site Standard rule 4: every plugin's README says what it needs from the theme,
// under the heading "## What this feature needs from the theme". Runs without
// WordPress:
//   php tools/tests/readme-theme-needs.php
//
// A plugin folder is a scout-* folder holding <slug>/<slug>.php, the same test
// tools/visitor-data.php uses. The heading must appear once, as its own line.

$root  = dirname( __DIR__, 2 );
$title = '## What this feature needs from the theme';

// Allowed to be missing for now. Remove a slug once its README has the section.
$later = array(
	// Scout Cards is being reworked in a separate change that owns its README.
	'scout-cards' => true,
);

$fail = 0;
$seen = 0;
foreach ( glob( $root . '/scout-*', GLOB_ONLYDIR ) as $dir ) {
	$slug = basename( $dir );
	if ( ! file_exists( "$dir/$slug.php" ) ) {
		continue;
	}
	$seen++;
	$readme = "$dir/README.md";
	$text   = file_exists( $readme ) ? str_replace( "\r\n", "\n", (string) file_get_contents( $readme ) ) : '';
	$count  = preg_match_all( '/^' . preg_quote( $title, '/' ) . '[ \t]*$/m', $text );
	if ( 1 === $count ) {
		echo "PASS $slug\n";
		continue;
	}
	if ( 0 === $count && isset( $later[ $slug ] ) ) {
		echo "SKIP $slug (allowed to be missing for now)\n";
		continue;
	}
	echo 'FAIL ' . $slug . ': README.md ' . ( 0 === $count ? 'has no "' . $title . '" section' : 'has the "' . $title . '" heading ' . $count . ' times' ) . "\n";
	$fail++;
}

if ( 0 === $seen ) {
	echo "FAIL no plugin folders found under $root\n";
	$fail++;
}
echo $fail ? "\n$fail README(s) need the section (Site Standard rule 4).\n" : "\nEvery plugin README says what it needs from the theme.\n";
exit( $fail ? 1 : 0 );
