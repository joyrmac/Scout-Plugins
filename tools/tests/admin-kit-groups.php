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
	'site-build-features&tab=log'             => array( 'Site log', 'build' ),
	'scout&tab=changes'                       => array( 'Change log', 'build' ),
	'scout&tab=sync'                          => array( 'Site Sync', 'build' ),
	'scout-mail'                              => array( 'Email', 'build' ),
	'scout-events-crm'                        => array( 'Customer list', 'build' ),
	'scout-chat'                              => array( 'Chat settings', 'build' ),
	'northcrest-theme'                        => array( 'Theme', 'build' ),
	'site-build-features&tab=inbox'           => array( 'Inbox', 'inbox' ),
	'site-build-features&tab=requests'        => array( 'Request a change', 'inbox' ),
	'edit.php?post_type=scout_chat_q'         => array( 'Chat questions', 'inbox' ),
	'scout-reviews-inbox'                     => array( 'Review inbox', 'inbox' ),
	'scout-reviews-ask'                       => array( 'Ask for reviews', 'inbox' ),
	'edit.php?post_type=scout_sitecheck'      => array( 'Site checks', 'inbox' ),
	'site-build-features&tab=health'          => array( 'Site health', 'health' ),
	'scout'                                   => array( 'Search health', 'health' ),
	'scout&tab=protect'                       => array( 'Spam protection', 'health' ),
	'scout-optimize'                          => array( 'Image optimizer', 'health' ),
	'scout-accessibility'                     => array( 'Accessibility', 'policies' ),
	'scout-disclaimers'                       => array( 'Disclaimers', 'policies' ),
	'scout&tab=seo'                           => array( 'SEO', 'search' ),
	'scout-ai-search'                         => array( 'AI search', 'search' ),
	'scout-analytics'                         => array( 'Analytics', 'search' ),
	'site-build-robots'                       => array( 'robots.txt file', 'search' ),
	'scout-recon'                             => array( 'Reports', 'reports' ),
	'scout-contact'                           => array( 'Call buttons', 'website' ),
	'scout-redirects'                         => array( 'Redirects', 'website' ),
	'scout-cards'                             => array( 'Cards & Links', 'website' ),
	'scout-newsletter'                        => array( 'Newsletter', 'website' ),
	'scout-reviews-settings'                  => array( 'Review settings', 'website' ),
	'edit.php?post_type=scout_review'         => array( 'Reviews', 'website' ),
	'scout-sitecheck'                         => array( 'Site check settings', 'website' ),
	'scout-writing'                           => array( 'Write', 'website' ),
	'scout&tab=business'                      => array( 'Business info', 'business' ),
	'scout-hours'                             => array( 'Hours', 'business' ),
	'scout-team'                              => array( 'Team settings', 'business' ),
	'edit.php?post_type=scout_team'           => array( 'Team members', 'business' ),
	'scout-results'                           => array( 'Case results settings', 'business' ),
	'scout-booking'                           => array( 'Schedule', 'business' ),
	'scout-events'                            => array( 'Overview', 'business' ),
	'scout-members'                           => array( 'Overview', 'business' ),
	'scout-partners'                          => array( 'Overview', 'business' ),
	'scout&tab=rvpark'                        => array( 'RV park', 'business' ),
	'site-build-features&tab=features&group=inbox' => array( 'Inbox', 'inbox' ),
	'site-build-features&tab=features&group=policies' => array( 'Policies', 'policies' ),
);
foreach ( $map as $slug => list( $label, $want ) ) {
	$got = Scout_Admin_UI::group( $slug, $label );
	ok( $got === $want, sprintf( '%-48s %-22s -> %s', $slug, $label, $got ) . ( $got === $want ? '' : " (wanted $want)" ) );
}
ok( array_keys( Scout_Admin_UI::GROUPS ) === array( 'overview', 'inbox', 'reports', 'business', 'website', 'search', 'policies', 'health', 'build' ), 'the groups, in menu order' );
ok( 'Home' === Scout_Admin_UI::label( 'overview' ) && 'Get found' === Scout_Admin_UI::label( 'search' ) && 'Scout tools' === Scout_Admin_UI::label( 'build' ), 'the new names' );
ok( array() === Scout_Admin_UI::hidden(), 'with no hub loaded and full rights, nothing is hidden' );
eval( 'class Scout_Hub_Access { public static function is_operator() { return false; } }' );
ok( array( 'build' ) === Scout_Admin_UI::hidden(), 'a client owner does not see Scout tools' );
$GLOBALS['scout_test_can'] = false;
ok( array( 'build', 'reports', 'website', 'search', 'policies', 'health' ) === Scout_Admin_UI::hidden(), 'staff see Home, Inbox and Your business only' );
echo $fail ? "\n$fail FAILED\n" : "\nAll grouping checks passed\n";
exit( $fail ? 1 : 0 );
