<?php
// Release ring checks for Scout Build (Site Standard rule 19). Run against a disposable
// WordPress with scout-build active (it rewrites Scout Build's release and catalog caches):
//   php tools/tests/release-rings.php /path/to/wordpress
require rtrim( $argv[1] ?? '', '/' ) . '/wp-load.php';
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
$repo = Scout_Hub_Catalog::REPO;
$now  = time();
$rel  = static function ( $slug, $ver, $ago_days ) use ( $now ) {
	return array( 'tag_name' => "$slug-v$ver", 'draft' => false, 'prerelease' => false, 'published_at' => gmdate( 'c', $now - (int) ( $ago_days * DAY_IN_SECONDS ) ), 'assets' => array( array( 'name' => "$slug.zip", 'browser_download_url' => "https://github.com/$repo/releases/download/$slug-v$ver/$slug.zip" ) ) );
};
$feed = array( $rel( 'scout-hours', '9.0.0', 0.2 ), $rel( 'scout-chat', '9.0.0', 1.5 ), $rel( 'scout-aeo', '9.0.0', 3 ),
	array( 'tag_name' => 'scout-team-v9.0.0', 'draft' => false, 'prerelease' => false, 'assets' => array( array( 'name' => 'scout-team.zip', 'browser_download_url' => 'x' ) ) ) );
set_transient( 'scout_updater_feed', array( 'ok' => true, 'releases' => $feed ), HOUR_IN_SECONDS );
delete_transient( Scout_Hub_Catalog::RELEASES_KEY );
$catalog = json_decode( file_get_contents( SCOUT_HUB_DIR . 'catalog.json' ), true );
set_transient( Scout_Hub_Catalog::CATALOG_KEY, $catalog, HOUR_IN_SECONDS );
delete_option( Scout_Hub_Rings::OPTION_RING ); delete_option( Scout_Hub_Rings::OPTION_PAUSE );
$item = static fn( $slug, $ver ) => (object) array( 'id' => "github.com/$repo/$slug", 'slug' => $slug, 'new_version' => $ver );
$auto = static fn( $slug, $ver ) => Scout_Hub_Rings::filter( true, $item( $slug, $ver ) );

ok( 3 === Scout_Hub_Rings::guess(), 'a local test address counts as a client site (ring 3): ' . Scout_Hub_Rings::guess() );
ok( Scout_Hub_Catalog::published( 'scout-hours', '9.0.0' ) > $now - DAY_IN_SECONDS, 'the release date is read from the version list' );
ok( 0 === Scout_Hub_Catalog::published( 'scout-team', '9.0.0' ), 'a release without a date reads 0' );

ok( false === $auto( 'scout-hours', '9.0.0' ), 'ring 3: a release out for hours waits' );
ok( false === $auto( 'scout-chat', '9.0.0' ), 'ring 3: a release out for a day and a half waits' );
ok( true === $auto( 'scout-aeo', '9.0.0' ), 'ring 3: a release out for three days installs' );
ok( true === $auto( 'scout-team', '9.0.0' ), 'a release with no date is never held by its ring' );
ok( null === Scout_Hub_Rings::filter( null, (object) array( 'id' => 'w.org/plugins/akismet', 'slug' => 'akismet', 'new_version' => '9' ) ), 'other plugins pass through untouched' );
ok( false !== strpos( Scout_Hub_Rings::wait_reason( 'scout-hours', '9.0.0' ), 'Installs by itself' ), 'the Updates screen says when it installs' );

update_option( Scout_Hub_Rings::OPTION_RING, 2 );
ok( 2 === Scout_Hub_Rings::ring() && true === $auto( 'scout-chat', '9.0.0' ) && false === $auto( 'scout-hours', '9.0.0' ), 'ring 2: a day later; the day-and-a-half release installs, the hours-old one waits' );
update_option( Scout_Hub_Rings::OPTION_RING, 1 );
ok( true === $auto( 'scout-hours', '9.0.0' ), 'ring 1: installs at once' );

update_option( Scout_Hub_Rings::OPTION_PAUSE, $now + DAY_IN_SECONDS );
ok( false === $auto( 'scout-aeo', '9.0.0' ) && false !== strpos( Scout_Hub_Rings::wait_reason( 'scout-aeo', '9.0.0' ), 'paused' ), 'a pause holds every Scout release on this site' );
update_option( Scout_Hub_Rings::OPTION_PAUSE, $now - 60 );
ok( true === $auto( 'scout-aeo', '9.0.0' ), 'an expired pause holds nothing' );

$catalog['hold'] = array( array( 'slug' => 'scout-aeo', 'version' => '9.0.0', 'note' => 'Fix coming.' ), array( 'slug' => 'scout-chat', 'version' => '*' ) );
set_transient( Scout_Hub_Catalog::CATALOG_KEY, $catalog, HOUR_IN_SECONDS );
ok( false === $auto( 'scout-aeo', '9.0.0' ) && false !== strpos( Scout_Hub_Rings::wait_reason( 'scout-aeo', '9.0.0' ), 'Fix coming.' ), 'a catalog hold stops that version everywhere, with its note' );
ok( true === $auto( 'scout-aeo', '9.0.1' ), 'a catalog hold on one version lets the next one through' );
ok( false === $auto( 'scout-chat', '9.0.0' ), 'a "*" hold stops every version of that plugin' );
ok( 2 === count( Scout_Hub_Catalog::holds() ), 'holds() lists both' );

delete_option( Scout_Hub_Rings::OPTION_RING ); delete_option( Scout_Hub_Rings::OPTION_PAUSE );
delete_transient( 'scout_updater_feed' ); delete_transient( Scout_Hub_Catalog::RELEASES_KEY ); delete_transient( Scout_Hub_Catalog::CATALOG_KEY );
echo $fail ? "\n$fail FAILED\n" : "\nAll ring checks passed\n";
exit( $fail ? 1 : 0 );
