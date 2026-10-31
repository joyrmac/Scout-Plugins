<?php
// Change log checks. Run against a disposable WordPress with scout-core active:
//   php tools/tests/change-log.php /path/to/wordpress
// It adds two test users, edits pages, a menu, settings and a photo, and clears the
// change log table. Never point it at a real site.
require rtrim($argv[1] ?? '', '/').'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/nav-menu.php';
if (!class_exists('Scout_Changelog')) { fwrite(STDERR, "Scout Core with the Change log is not active.\n"); exit(1); }
$fail = 0;
function ok($cond, $msg){ global $fail; echo ($cond?'PASS ':'FAIL ').$msg."\n"; if(!$cond) $fail++; }
function rows($status='new'){ return Scout_Changelog::entries($status, 1000); }
function last(){ $r = rows(); return $r ? end($r) : null; }
function user($login, $email){
	$u = get_user_by('email', $email) ?: get_user_by('login', $login);
	$id = $u ? $u->ID : wp_insert_user(array('user_login'=>$login,'user_email'=>$email,'user_pass'=>wp_generate_password(),'role'=>'administrator','display_name'=>ucfirst($login)));
	if (is_wp_error($id)) { fwrite(STDERR, $id->get_error_message()."\n"); exit(1); }
	if (!user_can($id, 'activate_plugins')) { (new WP_User($id))->add_role('administrator'); }
	return (int) $id;
}

// Clean slate: these tests run outside cron and CLI checks on purpose.
global $wpdb;
$wpdb->query('DELETE FROM '.Scout_Changelog::table());
delete_option(Scout_Changelog::LAST_MAIL); delete_option(Scout_Changelog::MENUS); delete_option('scout_change_log_switch');
add_filter('wp_doing_cron', '__return_false');

$client = user('cltest_client', 'kai-test@example.com');
$scout  = user('cltest_scout', 'joy@scoutraleigh.com');
wp_set_current_user($scout);
$page = wp_insert_post(array('post_type'=>'page','post_title'=>'CL Test Page','post_status'=>'publish','post_content'=>'<!-- wp:paragraph --><p>Original sentence one. Original sentence two.</p><!-- /wp:paragraph -->'));

// 1. Scout's own edits are not logged.
wp_update_post(array('ID'=>$page,'post_content'=>'<p>Scout changed this.</p>'));
ok(0 === count(rows()), 'an edit by Scout is not logged');
ok(Scout_Changelog::is_scout(get_userdata($scout)), 'joy@scoutraleigh.com counts as Scout');
ok(!Scout_Changelog::is_scout(get_userdata($client)), 'the client does not count as Scout');

// 2. A client edit is logged with who, what and before and after.
wp_set_current_user($client);
wp_update_post(array('ID'=>$page,'post_content'=>'<p>Scout changed this. Kai added a sentence.</p>'));
$r = last();
ok($r && 'edited' === $r->action && 'page' === $r->object_type && (int)$r->object_id === $page, 'a client page edit is logged');
ok($r && false !== strpos($r->user_label, 'kai-test@example.com'), 'the entry names the client');
ok($r && false !== strpos($r->summary, 'text changed'), 'the summary says the text changed');
ok($r && false !== strpos($r->before_val, 'Scout changed this.') && false !== strpos($r->after_val, 'Kai added a sentence.'), 'before and after are kept as plain text');
ok($r && false === strpos($r->after_val, '<p>'), 'tags are stripped from the text');

// 3. Saving again within five minutes updates the same entry and keeps the first before.
wp_update_post(array('ID'=>$page,'post_title'=>'CL Test Page Renamed'));
ok(1 === count(rows()), 'a second save within five minutes merges into one entry');
$r = last();
ok($r && false !== strpos($r->after_val, 'Renamed') && false !== strpos($r->before_val, 'Scout changed this.'), 'the merged entry keeps the first before and the latest after');

// 4. A new page, then trash.
$p2 = wp_insert_post(array('post_type'=>'page','post_title'=>'Draft Start','post_status'=>'auto-draft'));
wp_update_post(array('ID'=>$p2,'post_title'=>'Kai New Page','post_status'=>'publish','post_content'=>'Hello'));
$r = last();
ok($r && 'published' === $r->action && false !== strpos($r->summary, 'Published new page'), 'a new page is logged as published');
wp_trash_post($p2);
$r = last();
ok($r && false !== strpos($r->summary, 'trash'), 'moving a page to the trash is logged');

// 5. SEO fields and settings.
if (defined('SCOUT_SEO_META_DESC')) {
	update_post_meta($page, SCOUT_SEO_META_DESC, 'Old description');
	update_post_meta($page, SCOUT_SEO_META_DESC, 'New description');
	$r = last();
	ok($r && 'meta:'.SCOUT_SEO_META_DESC === $r->object_type && 'New description' === $r->after_val, 'an SEO description change is logged');
}
$old = get_option('blogdescription');
update_option('blogdescription', 'A tagline Kai wrote');
$r = last();
ok($r && 'option' === $r->object_type && 'A tagline Kai wrote' === $r->after_val && (string)$old === $r->before_val, 'a tagline change is logged with before and after');
update_option('cl_test_internal', 'x');
ok('option' !== last()->object_type || 'A tagline Kai wrote' === last()->after_val, 'an option outside the list is not logged');
update_option('blogdescription', $old);

// 6. Menus.
$menu = wp_create_nav_menu('CL Test Menu '.wp_rand());
wp_update_nav_menu_item($menu, 0, array('menu-item-title'=>'Home','menu-item-url'=>home_url('/'),'menu-item-status'=>'publish'));
do_action('wp_update_nav_menu', $menu);
wp_update_nav_menu_item($menu, 0, array('menu-item-title'=>'Kai Events','menu-item-url'=>home_url('/events/'),'menu-item-status'=>'publish'));
do_action('wp_update_nav_menu', $menu);
$r = last();
ok($r && 'nav_menu' === $r->object_type && false !== strpos($r->after_val, 'Kai Events'), 'a menu change is logged with the items');

// 7. Paused code and cron are not logged.
$before = count(rows());
Scout_Changelog::pause();
wp_update_post(array('ID'=>$page,'post_content'=>'Setup wrote this'));
Scout_Changelog::resume();
ok(count(rows()) === $before && false === strpos(last()->after_val, 'Setup wrote this'), 'changes made while paused are not logged');

// 8. Copy for Claude and the weekly email.
$text = Scout_Changelog::export(rows());
ok(false !== strpos($text, 'Change log for') && false !== strpos($text, 'Kai added a sentence.'), 'Copy for Claude includes the entries and their changes');
$mail = null;
add_filter('pre_wp_mail', function($null, $atts) use (&$mail){ $mail = $atts; return true; }, 10, 2);
ok(Scout_Changelog::send_digest() && $mail && 'team@scoutraleigh.com' === $mail['to'], 'the weekly email goes to team@scoutraleigh.com');
$mail = null;
ok(!Scout_Changelog::send_digest() && null === $mail, 'no email when nothing is new since the last one');

// 9. Marking moves entries to the archive; nothing is deleted.
$all = rows(); $n = count($all);
wp_set_current_user($scout);
$moved = Scout_Changelog::acknowledge(wp_list_pluck($all, 'id'));
ok($moved === $n && 0 === count(rows()) && $n === count(rows('archived')), 'marked entries move to the archive and stay');
ok((int) rows('archived')[0]->acked_by === $scout, 'the archive records who marked them');

// 10. Off switch.
update_option('scout_change_log_switch', 'off');
ok(!Scout_Changelog::enabled(), 'the off switch turns recording off');
delete_option('scout_change_log_switch');

// Clean up the test posts.
wp_delete_post($page, true); wp_delete_post($p2, true); wp_delete_nav_menu($menu);
echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
