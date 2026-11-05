<?php
// Scout Core: language versions (hreflang). Runs without WordPress:
//   php tools/tests/core-hreflang.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
define( 'SCOUT_SEO_META_NOINDEX', '_scout_seo_noindex' );
define( 'SCOUT_SEO_META_CANONICAL', '_scout_seo_canonical' );

$GLOBALS['meta']    = array();
$GLOBALS['status']  = array();
$GLOBALS['locale']  = 'en_US';
$GLOBALS['queried'] = 0;
$GLOBALS['filters'] = array();
function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) { return array_key_exists( $tag, $GLOBALS['filters'] ) ? $GLOBALS['filters'][ $tag ] : $value; }
function _n( $one, $many, $n ) { return 1 === $n ? $one : $many; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function get_locale() { return $GLOBALS['locale']; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
function get_post( $id ) { return isset( $GLOBALS['status'][ $id ] ) ? (object) array( 'ID' => $id, 'post_status' => $GLOBALS['status'][ $id ] ) : null; }
function get_post_status( $id ) { return $GLOBALS['status'][ $id ] ?? false; }
function get_post_type( $id ) { return 'page'; }
function get_permalink( $id ) { return 'https://firm.test/p' . $id . '/'; }
function current_user_can() { return true; }
function is_singular() { return $GLOBALS['queried'] > 0; }
function is_admin() { return false; }
function get_queried_object_id() { return $GLOBALS['queried']; }
function get_posts( $args ) {
	$out = array();
	foreach ( $GLOBALS['meta'] as $id => $m ) {
		if ( ! empty( $m[ $args['meta_key'] ?? '' ] ) && 'trash' !== ( $GLOBALS['status'][ $id ] ?? '' ) ) {
			$out[] = $id;
		}
	}
	return $out;
}
require __DIR__ . '/../../scout-core/includes/seo/class-seo-hreflang.php';
$L = 'Scout_SEO_Hreflang';

function reset_site() {
	$GLOBALS['meta']    = array();
	$GLOBALS['status']  = array( 1 => 'publish', 2 => 'publish', 3 => 'publish', 4 => 'draft', 5 => 'publish' );
	$GLOBALS['filters'] = array();
	$GLOBALS['locale']  = 'en_US';
}
function links( $id ) { return Scout_SEO_Hreflang::links( $id ); }
function lang_of( $id ) { return $GLOBALS['meta'][ $id ]['_scout_lang'] ?? ''; }

// Codes.
ok( 'pt-BR' === $L::code( 'pt_br' ) && 'zh-Hant' === $L::code( 'ZH-hant' ) && 'es-419' === $L::code( 'es-419' ) && 'es' === $L::code( ' ES ' ), 'language codes are tidied into the usual case' );
ok( '' === $L::code( 'x-default' ) && '' === $L::code( 'spanish' ) && '' === $L::code( 'es-' ) && '' === $L::code( '<es>' ), 'anything that is not a simple language code is refused' );
ok( 'en-US' === $L::site_lang(), 'the site language comes from the WordPress locale as a tag' );

// No links: nothing at all.
reset_site();
$GLOBALS['queried'] = 1;
ok( array() === $L::tags( 1 ), 'a page with no language versions prints nothing' );
ob_start();
$L::output();
ok( '' === ob_get_clean(), 'the head stays exactly as it was without links' );
ok( 'lang="en-US"' === $L::language_attributes( 'lang="en-US"' ), 'the html lang attribute is untouched without a saved language' );
ok( null === $L::check(), 'the Google checks row is not shown when no page has links' );

// Two languages, linked from one side.
$L::save_links( 1, '', array( 'es' => 2 ) );
ok( array( 'es' => 2 ) === links( 1 ) && array( 'en-US' => 1 ) === links( 2 ), 'one link is saved on both pages, so it runs both ways' );
ok( 'en-US' === lang_of( 1 ) && 'es' === lang_of( 2 ), 'each page keeps its own language, the site language by default' );
$tags = $L::tags( 1 );
ok(
	array(
		'<link rel="alternate" hreflang="en-US" href="https://firm.test/p1/" />',
		'<link rel="alternate" hreflang="es" href="https://firm.test/p2/" />',
		'<link rel="alternate" hreflang="x-default" href="https://firm.test/p1/" />',
	) === $tags,
	'two languages: the page itself, its Spanish version, and x-default on the site language'
);
ok( $L::tags( 2 ) === $tags, 'the Spanish page prints the same set' );

// html lang on the Spanish page only.
$GLOBALS['queried'] = 2;
ok( 'lang="es"' === $L::language_attributes( 'lang="en-US"' ), 'the Spanish page says lang="es"' );
ok( 'lang="es" dir="ltr"' === $L::language_attributes( 'lang="en-US" dir="ltr"' ), 'only the lang part changes' );
$GLOBALS['queried'] = 1;
ok( 'lang="en-US"' === $L::language_attributes( 'lang="en-US"' ), 'the page in the site language keeps WordPress\'s attribute' );

// Three languages: adding French on the Spanish page brings the whole group along.
$L::save_links( 2, 'es', array( 'en-US' => 1, 'fr' => 3 ) );
ok( array( 'es' => 2, 'fr' => 3 ) === links( 1 ) && array( 'en-US' => 1, 'es' => 2 ) === links( 3 ), 'a third language joins every page in the group' );
$tags = $L::tags( 3 );
ok( 4 === count( $tags ) && false !== strpos( $tags[1], 'hreflang="es"' ) && false !== strpos( $tags[2], 'hreflang="fr" href="https://firm.test/p3/"' ) && false !== strpos( $tags[3], 'x-default" href="https://firm.test/p1/"' ), 'three languages print three tags and x-default' );

// Removing one side removes the other.
$L::save_links( 1, '', array( 'es' => 2 ) );
ok( array() === links( 3 ) && array( 'en-US' => 1 ) === links( 2 ) && array( 'es' => 2 ) === links( 1 ), 'taking French off one page takes it off every page' );
ok( 'fr' === lang_of( 3 ), 'the French page keeps its own language' );
$L::save_links( 2, 'es', array() );
ok( array() === links( 1 ) && array() === links( 2 ) && ! isset( $GLOBALS['meta'][1]['_scout_lang'] ), 'clearing the last link clears both sides, and the site-language page goes back to no saved language' );

// Unpublished or hidden targets are skipped.
$L::save_links( 1, '', array( 'es' => 2, 'fr' => 3, 'pt-BR' => 4 ) );
$tags = $L::tags( 1 );
ok( 4 === count( $tags ) && false === strpos( implode( '', $tags ), 'pt-BR' ), 'a draft version is left out' );
$GLOBALS['meta'][3]['_scout_seo_noindex'] = '1';
$tags = $L::tags( 1 );
ok( 3 === count( $tags ) && false === strpos( implode( '', $tags ), 'hreflang="fr"' ), 'a version hidden from search is left out' );
$GLOBALS['meta'][2]['_scout_seo_canonical'] = 'https://firm.test/p1/';
ok( array() === $L::tags( 1 ), 'with no live version left, nothing prints' );
unset( $GLOBALS['meta'][2]['_scout_seo_canonical'] );
$GLOBALS['meta'][1]['_scout_seo_noindex'] = '1';
ok( array() === $L::tags( 1 ), 'a page hidden from search prints no language tags itself' );
unset( $GLOBALS['meta'][1]['_scout_seo_noindex'], $GLOBALS['meta'][3]['_scout_seo_noindex'] );

// Google checks row.
$row = $L::check();
ok( 'Language versions' === $row['label'] && 'warn' === $row['status'] && array( 1, 2, 3 ) === $row['items'], 'a draft version makes the row ask for a look and name the pages that link to it' );
$L::save_links( 1, '', array( 'es' => 2, 'fr' => 3 ) );
ok( array() === links( 4 ), 'dropping the draft from the group clears its links too' );
$row = $L::check();
ok( 'good' === $row['status'] && array() === $row['items'], 'every pair links back and is live: All set' );
$GLOBALS['meta'][3]['_scout_hreflang'] = array( 'en-US' => 1 );
$row = $L::check();
ok( 'warn' === $row['status'] && array( 2 ) === $row['items'], 'a version that does not link back is named' );
$L::forget( 3 );
ok( array( 'es' => 2 ) === links( 1 ) && array( 'en-US' => 1 ) === links( 2 ), 'a deleted page leaves every group it was in' );

// Form input.
reset_site();
$L::save_from_form( 1, array( 'hreflang_form' => '1', 'lang' => '', 'hreflang' => array( array( 'lang' => 'ES', 'post' => '2' ), array( 'lang' => 'not a code', 'post' => '3' ), array( 'lang' => '', 'post' => '0' ) ) ) );
ok( array( 'es' => 2 ) === links( 1 ), 'the box saves good rows and skips a bad code and empty rows' );
$L::save_from_form( 1, array( 'title' => 'x' ) );
ok( array( 'es' => 2 ) === links( 1 ), 'a save without the language fields leaves the links alone' );

// A site in another language: x-default follows the site language.
$GLOBALS['locale'] = 'es_MX';
$GLOBALS['meta']   = array();
$L::save_links( 5, 'es', array( 'en' => 1 ) );
$tags = $L::tags( 1 );
ok( false !== strpos( end( $tags ), 'x-default" href="https://firm.test/p5/"' ), 'on a Spanish site, x-default points at the Spanish page by its first part' );
$GLOBALS['locale'] = 'en_US';

// Stand down for a translation plugin.
$GLOBALS['filters']['scout_core_hreflang_stand_down'] = true;
$GLOBALS['queried'] = 5;
ok( array() === $L::tags( 1 ) && null === $L::check() && 'lang="en-US"' === $L::language_attributes( 'lang="en-US"' ), 'with a translation plugin on, Scout prints nothing and shows no check' );
$L::save_from_form( 1, array( 'hreflang_form' => '1', 'hreflang' => array() ) );
ok( array( 'es' => 5 ) === links( 1 ), 'and the box does not change the saved links' );
unset( $GLOBALS['filters']['scout_core_hreflang_stand_down'] );
define( 'POLYLANG_VERSION', '3.6' );
ok( $L::stand_down() && array() === $L::tags( 1 ), 'Polylang being active is noticed on its own' );

echo $fail ? "\n$fail FAILED\n" : "\nAll language version checks passed\n";
exit( $fail ? 1 : 0 );
