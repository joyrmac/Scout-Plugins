<?php
// Scout Build's white-label layer (Scout_Hub_Brand): opt-in only, each part
// hooks with config, the "off" list, footer wording on branded and
// white-label sites, menus hidden only from non-operators, the admin bar and
// the login logo order. No WordPress needed:  php tools/tests/brand.php
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['b'] = array();
function b_reset( array $setup = array(), $option = null, bool $operator = false, bool $branded = true ) {
	$GLOBALS['b'] = array(
		'setup'    => $setup,
		'options'  => null === $option ? array() : array( 'scout_brand' => $option ),
		'operator' => $operator,
		'branded'  => $branded,
		'hooks'    => array(),
		'removed'  => array(),
		'mods'     => array(),
		'icon'     => '',
	);
	Scout_Hub_Brand::reset();
}
function add_action( $hook, $cb, $p = 10, $n = 1 ) { $GLOBALS['b']['hooks'][] = $hook; return true; }
function add_filter( $hook, $cb, $p = 10, $n = 1 ) { $GLOBALS['b']['hooks'][] = $hook; return true; }
function apply_filters( $hook, $value ) { return $value; }
function get_option( $k, $d = false ) { return $GLOBALS['b']['options'][ $k ] ?? $d; }
function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $u ) { return (string) $u; }
function get_bloginfo( $k ) { return 'Example Firm'; }
function home_url( $p = '' ) { return 'https://example-firm.com' . $p; }
function remove_menu_page( $slug ) { $GLOBALS['b']['removed'][] = $slug; }
function remove_submenu_page( $parent, $child ) { $GLOBALS['b']['removed'][] = $parent . '>' . $child; }
function wp_get_current_user() { return (object) array( 'display_name' => 'Pat Lee' ); }
function get_theme_file_uri( $p ) { return 'https://example-firm.com/wp-content/themes/firm/' . $p; }
function get_theme_mod( $k ) { return $GLOBALS['b']['mods'][ $k ] ?? false; }
function wp_get_attachment_image_url( $id, $size ) { return 'https://example-firm.com/uploads/logo-' . $id . '.png'; }
function get_site_icon_url( $size ) { return $GLOBALS['b']['icon']; }

final class Scout_Hub_Setup { public static function data(): array { return $GLOBALS['b']['setup']; } }
final class Scout_Hub_Access { public static function is_operator(): bool { return $GLOBALS['b']['operator']; } }
final class Scout_Admin_UI { public static function branded(): bool { return $GLOBALS['b']['branded']; } }
final class Scout_Hub_Admin {
	public static function url( $tab = 'overview' ) { return 'https://example-firm.com/wp-admin/admin.php?page=site-build&tab=' . $tab; }
	public static function branded(): bool { return $GLOBALS['b']['branded']; }
}
final class Bar {
	public $removed = array();
	public $added   = array();
	public function remove_node( $id ) { $this->removed[] = $id; }
	public function get_node( $id ) { return 'my-account' === $id ? (object) array( 'title' => 'Howdy, <span class="display-name">Pat Lee</span><img alt="">' ) : null; }
	public function add_node( $args ) { $this->added[] = $args; }
}

require __DIR__ . '/../../scout-build/includes/class-scout-hub-brand.php';

$fails = 0;
function ok( $c, $w ) { global $fails; echo ( $c ? 'PASS ' : 'FAIL ' ) . $w . "\n"; $fails += $c ? 0 : 1; }
function hooked( string $hook ): bool { return in_array( $hook, $GLOBALS['b']['hooks'], true ); }

$part_hooks = array(
	'login'     => 'login_enqueue_scripts',
	'admin_bar' => 'admin_bar_menu',
	'footer'    => 'admin_footer_text',
	'menu'      => 'admin_menu',
	'message'   => 'admin_notices',
);

// boot() only waits for the theme; it changes nothing by itself.
b_reset();
Scout_Hub_Brand::boot();
ok( array( 'after_setup_theme' ) === $GLOBALS['b']['hooks'], 'boot only waits for the theme' );

// No brand config anywhere: nothing hooks.
b_reset( array( 'hours' => array( 'confirmed' => true ) ) );
Scout_Hub_Brand::hook();
ok( array() === $GLOBALS['b']['hooks'], 'no brand config: nothing hooks' );
ok( ! Scout_Hub_Brand::active() && '' === Scout_Hub_Brand::source(), 'no brand config: off, no source' );
ok( ! Scout_Hub_Brand::on( 'footer' ), 'no brand config: no part is on' );
ok( false !== strpos( Scout_Hub_Brand::status_text(), 'off' ), 'status says off' );

// An option without "on" true does nothing either.
b_reset( array(), array( 'message' => 'Hello', 'on' => false ) );
Scout_Hub_Brand::hook();
ok( array() === $GLOBALS['b']['hooks'], 'a saved option without "on" does nothing' );

// A brand section in the setup file: every part hooks.
b_reset( array( 'brand' => array() ) );
Scout_Hub_Brand::hook();
foreach ( $part_hooks as $part => $hook ) {
	ok( hooked( $hook ), "setup file brand section: $part hooks" );
}
ok( hooked( 'login_headerurl' ) && hooked( 'update_footer' ) && hooked( 'wp_ajax_scout_brand_dismiss' ), 'the rest of each part hooks too' );
ok( 'setup' === Scout_Hub_Brand::source() && 'White-label: on, from the setup file.' === Scout_Hub_Brand::status_text(), 'status names the setup file' );

// The option with "on" true: every part hooks.
b_reset( array(), array( 'on' => true ) );
Scout_Hub_Brand::hook();
ok( 5 === count( array_filter( $part_hooks, 'hooked' ) ), 'scout_brand option with on: every part hooks' );
ok( 'option' === Scout_Hub_Brand::source(), 'source is the option' );

// "on": false in the setup file wins over the option.
b_reset( array( 'brand' => array( 'on' => false ) ), array( 'on' => true ) );
Scout_Hub_Brand::hook();
ok( array() === $GLOBALS['b']['hooks'], 'setup file "on": false turns it off' );

// The "off" list.
b_reset( array( 'brand' => array( 'off' => array( 'admin_bar', 'message', 'nonsense' ) ) ) );
Scout_Hub_Brand::hook();
ok( ! hooked( 'admin_bar_menu' ) && ! hooked( 'admin_notices' ), 'parts in "off" do not hook' );
ok( hooked( 'login_enqueue_scripts' ) && hooked( 'admin_footer_text' ) && hooked( 'admin_menu' ), 'the other parts still hook' );
ok( array( 'admin_bar', 'message' ) === Scout_Hub_Brand::settings()['off'], 'unknown names in "off" are dropped' );
ok( false !== strpos( Scout_Hub_Brand::status_text(), 'Turned off: Admin bar, Dashboard message.' ), 'status lists the parts turned off' );

// The setup file wins over the option, key by key.
b_reset( array( 'brand' => array( 'color' => '#1A2E5A' ) ), array( 'on' => true, 'color' => '#000000', 'message' => 'From the option' ) );
$s = Scout_Hub_Brand::settings();
ok( '#1A2E5A' === $s['color'] && 'From the option' === $s['message'], 'setup file wins key by key' );
b_reset( array( 'brand' => array( 'color' => 'red; } body { display:none' ) ) );
ok( '' === Scout_Hub_Brand::settings()['color'], 'a color that is not a hex is dropped' );

// Footer wording.
b_reset( array( 'brand' => array() ), null, false, true );
$f = Scout_Hub_Brand::footer_left();
ok( false !== strpos( $f, 'Built and looked after by Scout Media.' ) && false !== strpos( $f, 'Request a change' ), 'branded footer names Scout Media, with Request a change' );
b_reset( array( 'brand' => array() ), null, false, false );
$f = Scout_Hub_Brand::footer_left();
ok( false !== strpos( $f, 'Built and looked after by your web team.' ) && false !== strpos( $f, 'Request a change' ), 'white-label footer says your web team' );
ok( false === stripos( $f, 'scout' ), 'white-label footer never names Scout' );
ok( '' === Scout_Hub_Brand::footer_right( 'Version 6.8' ), 'WordPress version hidden from clients' );
b_reset( array( 'brand' => array() ), null, true );
ok( 'Version 6.8' === Scout_Hub_Brand::footer_right( 'Version 6.8' ), 'operators still see the WordPress version' );

// Menus hidden only from non-operators.
b_reset( array( 'brand' => array( 'hide_menus' => array( 'tools.php', 'options-general.php>options-privacy.php' ) ) ), null, false );
Scout_Hub_Brand::hide_menus();
ok( array( 'tools.php', 'options-general.php>options-privacy.php' ) === $GLOBALS['b']['removed'], 'clients: listed menus and submenus are hidden' );
b_reset( array( 'brand' => array( 'hide_menus' => array( 'tools.php' ) ) ), null, true );
Scout_Hub_Brand::hide_menus();
ok( array() === $GLOBALS['b']['removed'], 'operators: nothing is hidden' );

// Admin bar.
b_reset( array( 'brand' => array() ), null, false );
$bar = new Bar();
Scout_Hub_Brand::admin_bar( $bar );
ok( in_array( 'wp-logo', $bar->removed, true ) && in_array( 'updates', $bar->removed, true ), 'clients: no WordPress logo, no updates counter' );
ok( isset( $bar->added[0]['title'] ) && 0 === strpos( $bar->added[0]['title'], 'Hi, <span class="display-name">Pat Lee</span>' ), '"Hi, Name" instead of "Howdy"' );
b_reset( array( 'brand' => array() ), null, true );
$bar = new Bar();
Scout_Hub_Brand::admin_bar( $bar );
ok( ! in_array( 'updates', $bar->removed, true ), 'operators keep the updates counter' );

// Login logo: brand logo, then the Customizer logo, then the Site Icon.
b_reset( array( 'brand' => array( 'logo' => 'assets/img/logo.svg' ) ) );
$GLOBALS['b']['mods']['custom_logo'] = 12;
ok( 'https://example-firm.com/wp-content/themes/firm/assets/img/logo.svg' === Scout_Hub_Brand::logo_url(), 'the brand logo comes first' );
b_reset( array( 'brand' => array() ) );
$GLOBALS['b']['mods']['custom_logo'] = 12;
$GLOBALS['b']['icon']                = 'https://example-firm.com/icon.png';
ok( 'https://example-firm.com/uploads/logo-12.png' === Scout_Hub_Brand::logo_url(), 'then the Customizer logo' );
unset( $GLOBALS['b']['mods']['custom_logo'] );
ok( 'https://example-firm.com/icon.png' === Scout_Hub_Brand::logo_url(), 'then the Site Icon' );

// SCOUT_BRAND_OFF turns it all off, even with config.
b_reset( array( 'brand' => array() ), array( 'on' => true ) );
define( 'SCOUT_BRAND_OFF', true );
Scout_Hub_Brand::hook();
ok( array() === $GLOBALS['b']['hooks'] && 'config_off' === Scout_Hub_Brand::source(), 'SCOUT_BRAND_OFF: nothing hooks' );
ok( false !== strpos( Scout_Hub_Brand::status_text(), 'SCOUT_BRAND_OFF' ), 'status names the wp-config.php switch' );

echo $fails ? "\n$fails failed\n" : "\nAll brand checks passed\n";
exit( $fails ? 1 : 0 );
