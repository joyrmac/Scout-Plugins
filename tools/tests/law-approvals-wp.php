<?php
// Scout Law Firm Tools approval checks. Run against a disposable WordPress with
// scout-law active (it creates and deletes test posts):
//   php tools/tests/law-approvals-wp.php /path/to/wordpress
// The block editor flow (Pending review notice, the Firm approval panel) was
// checked by hand in a live block editor; this covers the server side.
require rtrim( $argv[1] ?? '', '/' ) . '/wp-load.php';
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
wp_set_current_user( 1 );
$made = array();
$new  = static function ( $title, $content, $status = 'draft' ) use ( &$made ) { $id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => $title, 'post_content' => $content, 'post_status' => $status ) ); $made[] = $id; return $id; };

ok( Scout_Law_Approvals::gated( 'post' ), 'blog posts need approval by default' );

// Code path: publishing with no approval is held as Pending review.
$a = $new( 'Held post', 'Words', 'publish' );
ok( 'pending' === get_post_status( $a ), 'a publish with no approval is saved as Pending review' );

// An approval recorded, then publish: goes live, record names the exact words.
Scout_Law_Approvals::record( $a, 'Dana Whitfield' );
wp_update_post( array( 'ID' => $a, 'post_status' => 'publish' ) );
ok( 'publish' === get_post_status( $a ) && 'approved' === Scout_Law_Approvals::status( get_post( $a ) ), 'with an approval on record it publishes, status approved' );

// The block editor order: the approval is recorded before WordPress saves the new revision.
$b = $new( 'Same save', 'Words the firm has not seen' );
wp_save_post_revision( $b );
remove_action( 'post_updated', 'wp_save_post_revision', 10 ); // Hold revisions back, as the REST controller's order does.
wp_update_post( array( 'ID' => $b, 'post_content' => 'Final words the firm approved' ) );
Scout_Law_Approvals::record( $b, 'Dana Whitfield' );
$row = Scout_Law_Approvals::log()[0];
ok( 0 === (int) $row['revision'], 'before the revision exists, the record waits instead of naming the wrong version' );
add_action( 'post_updated', 'wp_save_post_revision', 10, 1 );
wp_save_post_revision( $b );
do_action( 'wp_after_insert_post', $b, get_post( $b ), true, null );
$row = Scout_Law_Approvals::log()[0];
$rev = get_post( (int) $row['revision'] );
ok( $rev && 'Final words the firm approved' === $rev->post_content, 'once saved, the record points at the revision with the approved words' );

// Edited after approval: stays live, listed as edited.
wp_update_post( array( 'ID' => $a, 'post_content' => 'Changed later' ) );
ok( 'publish' === get_post_status( $a ) && 'edited' === Scout_Law_Approvals::status( get_post( $a ) ), 'a live post edited after approval stays live and reads edited' );
$q = Scout_Law_Approvals::queues();
ok( in_array( $a, $q['edited'], true ), 'and it is in the Edited since approval queue' );
ok( Scout_Law_Approvals::fingerprint( 'T', "a  b\n c" ) === Scout_Law_Approvals::fingerprint( 'T ', 'a b c' ), 'extra spaces do not count as a change' );

foreach ( $made as $id ) { wp_delete_post( $id, true ); }
echo $fail ? "\n$fail FAILED\n" : "\nAll approval checks passed\n";
exit( $fail ? 1 : 0 );
