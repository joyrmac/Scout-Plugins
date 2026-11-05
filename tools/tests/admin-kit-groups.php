<?php
// Admin kit grouping checks: every Scout screen lands in the group the Site Standard §7
// map says (Overview, Your business, Content, Search and AI, Site health, Inbox, Build).
// A test so the grouping never drifts from the map again. Runs without WordPress:
//   php tools/tests/admin-kit-groups.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
function apply_filters( $tag, $value ) { return $value; }
function add_action() {}
function add_filter() {}
function current_user_can( $cap ) { return $GLOBALS['scout_test_can'] ?? true; }
require __DIR__ . '/../admin-kit/class-scout-admin-ui.php';

$map = array(
	// slug => [label, expected group]. Dashboard redesign, Joy 2026-11-04.
	'site-build-features'                     => array( 'Overview', 'overview' ),
	'site-build'                              => array( 'Overview', 'overview' ),
	'site-build-features&tab=features'        => array( 'Features', 'build' ),
	'site-build&tab=features'                 => array( 'Features', 'build' ),
	'site-build-features&tab=updates'         => array( 'Updates', 'build' ),
	'site-build-features&tab=log'             => array( 'Site history', 'build' ),
	'scout&tab=changes'                       => array( 'Change log', 'build' ),
	'scout&tab=sync'                          => array( 'Page copy', 'build' ),
	'scout-mail'                              => array( 'Email', 'build' ),
	'scout-events-crm'                        => array( 'Customer list', 'business' ), // Lives in the Events (Plus) section, for the owner too.
	'scout-chat'                              => array( 'Chat', 'website' ),
	'northcrest-theme'                        => array( 'Theme', 'build' ),
	'site-build-features&tab=inbox'           => array( 'To do', 'inbox' ),
	'site-build-features&tab=requests'        => array( 'Request a change', 'inbox' ),
	'edit.php?post_type=scout_chat_q'         => array( 'Chat messages', 'inbox' ),
	'scout-reviews-inbox'                     => array( 'New reviews', 'inbox' ),
	'scout-reviews-ask'                       => array( 'Ask for reviews', 'website' ),
	'edit.php?post_type=scout_sitecheck'      => array( 'Check requests', 'inbox' ),
	'site-build-features&tab=health'          => array( 'Site status', 'health' ),
	'scout'                                   => array( 'Google checks', 'health' ),
	'scout&tab=protect'                       => array( 'Spam protection', 'health' ),
	'scout-optimize'                          => array( 'Image optimizer', 'health' ),
	'scout-accessibility'                     => array( 'Accessibility', 'policies' ),
	'scout-disclaimers'                       => array( 'Disclaimers', 'policies' ),
	'scout-privacy'                           => array( 'Privacy & terms', 'policies' ),
	'scout&tab=seo'                           => array( 'Search settings', 'search' ),
	'scout-ai-search'                         => array( 'AI search', 'search' ),
	'scout-analytics'                         => array( 'Visits & cookies', 'search' ),
	'site-build-robots'                       => array( 'Search engine rules', 'search' ),
	'scout-recon'                             => array( 'Reports', 'reports' ),
	'scout-contact'                           => array( 'Call buttons', 'website' ),
	'scout-redirects'                         => array( 'Moved pages', 'website' ),
	'scout-cards'                             => array( 'Cards & links', 'website' ),
	'scout-newsletter'                        => array( 'Newsletter', 'website' ),
	'scout-reviews-settings'                  => array( 'Review settings', 'website' ),
	'edit.php?post_type=scout_review'         => array( 'Reviews', 'website' ),
	'scout-sitecheck'                         => array( 'Free site check', 'website' ),
	'scout-writing'                           => array( 'Write', 'business' ), // only in its own Plus section; the group never shows there
	'scout-accessibility-checks'              => array( 'Accessibility checks', 'health' ),
	'scout-accessibility'                     => array( 'Accessibility', 'policies' ),
	'scout&tab=business'                      => array( 'Business info', 'business' ),
	'scout-hours'                             => array( 'Hours', 'business' ),
	'scout-team'                              => array( 'People settings', 'people' ),
	'edit.php?post_type=scout_team'           => array( 'Everyone', 'people' ),
	'scout-people'                            => array( 'People', 'people' ),
	'edit-tags.php?taxonomy=scout_people_group&post_type=scout_team' => array( 'Groups', 'people' ),
	'scout-results'                           => array( 'Case results', 'business' ),
	'scout-booking'                           => array( 'Schedule', 'business' ),
	'scout-events'                            => array( 'Home', 'business' ),
	'scout-members'                           => array( 'Home', 'business' ),
	'scout-partners'                          => array( 'Home', 'business' ),
	'scout&tab=rvpark'                        => array( 'RV park', 'business' ),
	'scout-rvpark'                            => array( 'Home', 'business' ),
	'site-build-features&tab=features&group=inbox' => array( 'To do', 'inbox' ),
	'site-build-features&tab=features&group=policies' => array( 'Legal pages', 'policies' ),
);
foreach ( $map as $slug => list( $label, $want ) ) {
	$got = Scout_Admin_UI::group( $slug, $label );
	ok( $got === $want, sprintf( '%-48s %-22s -> %s', $slug, $label, $got ) . ( $got === $want ? '' : " (wanted $want)" ) );
}
ok( array_keys( Scout_Admin_UI::GROUPS ) === array( 'overview', 'inbox', 'reports', 'business', 'people', 'website', 'search', 'policies', 'health', 'build' ), 'the groups, in menu order' );
ok( 'Home' === Scout_Admin_UI::label( 'overview' ) && 'Search' === Scout_Admin_UI::label( 'search' ) && 'Scout tools' === Scout_Admin_UI::label( 'build' ), 'the new names' );
ok( array() === Scout_Admin_UI::hidden(), 'with no hub loaded and full rights, nothing is hidden' );
eval( 'class Scout_Hub_Access { public static function is_operator() { return false; } }' );
ok( array( 'build' ) === Scout_Admin_UI::hidden(), 'a client owner does not see Scout tools' );
ok( 'overview' === Scout_Admin_UI::group( 'site-build-features&tab=log', 'Site history' ), "a client's Site history sits under Home, where they can reach it" );
ok( ! array_filter( Scout_Admin_UI::LOOKING, static fn( $l ) => count( $l ) > 2 ), 'no group carries more than two Looking for links' );
ok( ! array_diff( array_keys( Scout_Admin_UI::LOOKING ), array_keys( Scout_Admin_UI::GROUPS ) ), 'Looking for links belong to real groups' );
ok( in_array( 'scout-writing', Scout_Admin_UI::PLUS_MENUS, true ) && in_array( 'scout-events', Scout_Admin_UI::PLUS_MENUS, true ), 'Plus modules get the Plus tag in the sidebar' );
$GLOBALS['scout_test_can'] = false;
ok( array( 'build', 'reports', 'website', 'search', 'policies', 'health' ) === Scout_Admin_UI::hidden(), 'staff see Home, To do, Business and People' );
echo $fail ? "\n$fail FAILED\n" : "\nAll grouping checks passed\n";
exit( $fail ? 1 : 0 );
