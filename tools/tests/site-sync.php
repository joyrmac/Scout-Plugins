<?php
// Site Sync integration checks. Run against a disposable WordPress whose active theme
// is a throwaway test theme with a writable scout-sync.json, scout-core active:
//   php tools/tests/site-sync.php /path/to/wordpress
// It deletes every page and attachment on that site. Never point it at a real site.
require rtrim($argv[1] ?? '', '/').'/wp-load.php';
if ('synctest' !== get_stylesheet()) { fwrite(STDERR, "Refusing: the active theme must be the throwaway 'synctest' theme.\n"); exit(1); }
wp_set_current_user(1);
$theme = get_stylesheet_directory();
$fail = 0;
function ok($cond, $msg){ global $fail; echo ($cond?'PASS ':'FAIL ').$msg."\n"; if(!$cond) $fail++; }
function states(){ $o=array(); foreach(Scout_Sync::plan()['rows'] as $r){ $o[$r['id']]=$r['state']; } return $o; }
function manifest($data){ file_put_contents(get_stylesheet_directory().'/scout-sync.json', json_encode($data, JSON_PRETTY_PRINT)); delete_option(Scout_Sync::SEEN); }
function bump($v){ $f=get_stylesheet_directory().'/style.css'; file_put_contents($f, "/*\nTheme Name: Sync Test\nVersion: $v\n*/\n"); wp_clean_themes_cache(); }

// Clean slate.
foreach (get_posts(array('post_type'=>array('page','attachment'),'numberposts'=>-1,'post_status'=>'any','fields'=>'ids')) as $id) wp_delete_post($id, true);
foreach (array(Scout_Sync::BASES, Scout_Sync::JOURNAL, Scout_Sync::LOCK, Scout_Sync::SEEN) as $o) delete_option($o);
delete_transient(Scout_Sync::SUMMARY);
update_option('blogname','Old Title');

$about   = wp_insert_post(array('post_type'=>'page','post_name'=>'about','post_title'=>'About','post_status'=>'publish','post_content'=>''));
$contact = wp_insert_post(array('post_type'=>'page','post_name'=>'contact','post_title'=>'Contact','post_status'=>'publish','post_content'=>'Old copy'));
update_post_meta($about, 'scout_hero_heading', 'A hand edit nobody knows about');
file_put_contents($theme.'/sync/pages/about.html', "<p>About copy v1</p>\n");
bump('1.0.0');
manifest(array('sync_version'=>1,
  'pages'=>array(
    array('slug'=>'about','source'=>'sync/pages/about.html'),
    array('slug'=>'contact','content'=>'Contact copy v1','previous'=>array('Old copy')),
    array('slug'=>'services','content'=>'Services copy'),
  ),
  'fields'=>array(
    array('slug'=>'about','field'=>'hero_heading','value'=>'Theme heading v1'),
    array('slug'=>'about','seo'=>'desc','value'=>'About description v1'),
    array('slug'=>'about','field'=>'hero_image','image'=>'sync/img/hero.png'),
  ),
  'options'=>array(array('option'=>'blogname','value'=>'New Title','previous'=>array('Old Title')), array('option'=>'siteurl','value'=>'http://evil')),
));

$s = states();
ok($s['page/about#content']==='update', 'empty page content is safe to fill');
ok($s['page/contact#content']==='update', 'a known earlier copy counts as untouched');
ok($s['page/services#content']==='missing', 'a page the site lacks is reported, not created');
ok($s['page/about#field:hero_heading']==='conflict', 'an unknown saved value is never treated as safe');
ok($s['page/about#field:hero_image']==='update', 'an empty image field is safe to fill');
ok(!isset($s['option#siteurl']), 'siteurl is refused');
ok(in_array('Option "siteurl" is not one Site Sync may set.', Scout_Sync::plan()['errors'], true), 'refused option is reported');

// A stale review is refused.
$r = Scout_Sync::apply('not-the-hash', 'safe');
ok(is_wp_error($r) && 'stale'===$r->get_error_code(), 'a stale review is refused');

// Automatic run after "theme update".
Scout_Sync::maybe_auto_run();
ok(get_post($about)->post_content === "<p>About copy v1</p>\n" || rtrim(get_post($about)->post_content)==='<p>About copy v1</p>', 'about content synced');
ok(get_post($contact)->post_content === 'Contact copy v1', 'contact content synced');
ok(get_option('blogname')==='New Title', 'site title synced');
ok(get_post_meta($about,'scout_hero_heading',true)==='A hand edit nobody knows about', 'the unknown hand edit was left alone');
$img = (int) get_post_meta($about,'scout_hero_image',true);
ok($img > 0 && 'attachment'===get_post_type($img), 'image imported into the media library');
$s = states();
ok($s['page/about#content']==='current' && $s['page/about#field:hero_image']==='current', 'synced rows read current');
ok(Scout_Sync::summary(true)['review']===3, 'review count is conflict + missing + refused option: '.Scout_Sync::summary(true)['review']);

// Running again does nothing new.
Scout_Sync::maybe_auto_run();
ok(Scout_Sync::apply('', 'safe', array(), 'auto')['written']===0, 'a second run writes nothing');

// Image re-import is reused, not duplicated.
// Hand edit in WordPress, then a theme update that does not touch that field.
wp_update_post(array('ID'=>$contact,'post_content'=>'Contact edited in WP'));
file_put_contents($theme.'/sync/pages/about.html', "<p>About copy v2</p>\n");
bump('1.0.1');
$s = states();
ok($s['page/contact#content']==='edited', 'a WordPress edit is flagged as edited');
ok($s['page/about#content']==='update', 'an untouched page with new theme copy is an update');
Scout_Sync::maybe_auto_run();
ok(rtrim(get_post($about)->post_content)==='<p>About copy v2</p>', 'auto run applied the theme update');
ok(get_post($contact)->post_content==='Contact edited in WP', 'auto run kept the WordPress edit');

// Theme changes a field that was also edited in WordPress: conflict, untouched.
manifest(array_merge(json_decode(file_get_contents($theme.'/scout-sync.json'),true), array()));
$m = json_decode(file_get_contents($theme.'/scout-sync.json'),true);
$m['pages'][1]['content']='Contact copy v2'; manifest($m); bump('1.0.2');
ok(states()['page/contact#content']==='conflict', 'both changed is a conflict');
Scout_Sync::maybe_auto_run();
ok(get_post($contact)->post_content==='Contact edited in WP', 'a conflict is never auto-overwritten');

// Keep the WordPress copy, then the theme moves again: still waits for a person.
$plan = Scout_Sync::plan();
$r = Scout_Sync::apply($plan['hash'], 'keep', array('page/contact#content'));
ok(!is_wp_error($r) && states()['page/contact#content']==='current', 'keeping the WordPress copy clears the flag');
$m['pages'][1]['content']='Contact copy v3'; manifest($m); bump('1.0.3');
ok(states()['page/contact#content']==='conflict', 'a kept copy is not overwritten when the theme moves on');

// Take the theme copy on purpose.
$plan = Scout_Sync::plan();
$r = Scout_Sync::apply($plan['hash'], 'take', array('page/contact#content'));
ok(!is_wp_error($r) && get_post($contact)->post_content==='Contact copy v3', 'take writes the theme copy');
ok(states()['page/contact#content']==='current', 'and reads current afterwards');

// Undo puts back what the last sync replaced.
$r = Scout_Sync::undo();
ok(!is_wp_error($r) && 1===$r['restored'] && get_post($contact)->post_content==='Contact edited in WP', 'undo restores the previous copy');

// A field edited between review and write stops the sync and changes nothing more.
$m['pages'][0]['content']='About v3 inline'; unset($m['pages'][0]['source']); manifest($m); bump('1.0.4');
$plan = Scout_Sync::plan();
add_filter('scout_sync_applied', '__return_null');
// Simulate a racing edit: change the page after planning but before apply re-plans by hooking the lock.
wp_update_post(array('ID'=>$about,'post_content'=>'Racing edit'));
$r = Scout_Sync::apply($plan['hash'], 'safe');
ok(is_wp_error($r) && 'stale'===$r->get_error_code(), 'an edit after review makes the review stale');
ok(get_post($about)->post_content==='Racing edit', 'and nothing was written');

// Adoption: a page that already matches gets a base, so the next theme copy is a safe update.
$faq = wp_insert_post(array('post_type'=>'page','post_name'=>'faq','post_title'=>'FAQ','post_status'=>'publish','post_content'=>'FAQ copy v1'));
$m['pages'][] = array('slug'=>'faq','content'=>'FAQ copy v1'); manifest($m); bump('1.0.5');
ok(states()['page/faq#content']==='current', 'a page that already matches reads current');
Scout_Sync::maybe_auto_run();
$m['pages'][count($m['pages'])-1]['content']='FAQ copy v2'; manifest($m); bump('1.0.6');
ok(states()['page/faq#content']==='update', 'after adoption, new theme copy on a matching page is a safe update');
Scout_Sync::maybe_auto_run();
ok(get_post($faq)->post_content==='FAQ copy v2', 'and the automatic run applies it');

// A source file missing from a theme release never blanks a page.
file_put_contents($theme.'/sync/pages/faq.html', 'FAQ copy v2');
$m['pages'][count($m['pages'])-1] = array('slug'=>'faq','source'=>'sync/pages/faq.html'); manifest($m); bump('1.0.7');
Scout_Sync::maybe_auto_run();
unlink($theme.'/sync/pages/faq.html'); bump('1.0.8');
ok(!isset(states()['page/faq#content']), 'a row whose file is missing is left out');
ok(in_array('File missing from the theme: sync/pages/faq.html', Scout_Sync::plan()['errors'], true), 'and reported');
Scout_Sync::maybe_auto_run();
$r = Scout_Sync::apply(Scout_Sync::plan()['hash'], 'take', array('page/faq#content'));
ok(get_post($faq)->post_content==='FAQ copy v2', 'neither the automatic run nor "Use the theme copy" blanked it');

// No manifest: status only.
unlink($theme.'/scout-sync.json');
ok(Scout_Sync::plan()['manifest']===false && Scout_Sync::summary(true)['review']===0, 'no manifest means status only, nothing to review');

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
