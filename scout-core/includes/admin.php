<?php
/**
 * The one Scout admin screen. A top-level "Scout" menu with three tabs:
 *  - Dashboard: a plain-language health check ("is my site healthy and getting
 *    found?"), the heart of the plugin.
 *  - Business: the NAP + identity form (Scout_Core_Business).
 *  - SEO: site-wide SEO defaults (Scout_Core_SEO_Settings). Per-page SEO stays
 *    in the Scout SEO box on each page.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_scout' === $hook ) {
			Scout_Admin_UI::enqueue( plugins_url( 'assets/scout-admin.css', SCOUT_CORE_FILE ) );
		}
	}

	public static function menu() {
		$icon = 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><polygon points="58,0 0,58 42,58 30,100 100,40 54,40" fill="#a7aaad"/></svg>'
		);
		add_menu_page( 'Scout', 'Scout', 'manage_options', 'scout', array( __CLASS__, 'render' ), $icon, 58 );
		add_submenu_page( 'scout', 'Scout Dashboard', 'Dashboard', 'manage_options', 'scout', array( __CLASS__, 'render' ) );
		add_submenu_page( 'scout', 'Business identity', 'Business', 'manage_options', 'scout&tab=business', array( __CLASS__, 'render' ) );
		add_submenu_page( 'scout', 'SEO defaults', 'SEO', 'manage_options', 'scout&tab=seo', array( __CLASS__, 'render' ) );
		foreach ( self::groups() as $slug => $group ) {
			add_submenu_page( 'scout', $group['label'], $group['label'], 'manage_options', 'scout&tab=' . $slug, array( __CLASS__, 'render' ) );
		}
	}

	/** Companion-registered settings groups, if the class is loaded. */
	private static function groups() {
		return class_exists( 'Scout_Core_Settings_Groups' ) ? Scout_Core_Settings_Groups::all() : array();
	}

	/** @return array<string,string> tab => label */
	private static function tabs() {
		$tabs = array( 'dashboard' => 'Dashboard', 'business' => 'Business', 'seo' => 'SEO' );
		foreach ( self::groups() as $slug => $group ) {
			$tabs[ $slug ] = $group['label'];
		}
		return $tabs;
	}

	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'dashboard';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab  = self::current_tab();
		$base = admin_url( 'admin.php?page=scout' );
		self::styles();
		$tabs = self::tabs();
		Scout_Admin_UI::open( 'Business info and SEO', 'Enter your business details once and they show up correctly everywhere Google looks.', $tabs[ $tab ] );
		echo '<div class="scout-admin sh-sections">';
		echo '<nav class="sh-tabs" aria-label="Scout sections">';
		foreach ( $tabs as $key => $label ) {
			$url    = 'dashboard' === $key ? $base : $base . '&tab=' . $key;
			$active = $tab === $key;
			printf( '<a href="%s" class="%s"%s><span class="sh-tab-label">%s</span></a>', esc_url( $url ), $active ? 'is-active' : '', $active ? ' aria-current="page"' : '', esc_html( $label ) );
		}
		echo '</nav>';

		if ( 'business' === $tab ) {
			self::tab_business();
		} elseif ( 'seo' === $tab ) {
			self::tab_seo();
		} elseif ( array_key_exists( $tab, self::groups() ) ) {
			Scout_Core_Settings_Groups::render_tab( $tab );
		} else {
			self::tab_dashboard();
		}
		echo '</div>';
		Scout_Admin_UI::close();
	}

	/* ---------- Dashboard: the health check ---------- */

	public static function tab_dashboard() {
		$checks = self::checks();
		$warn   = array_filter( $checks, static function ( $c ) { return 'good' !== $c['status']; } );
		$count  = count( $warn );

		echo '<div class="scout-hero ' . ( $count ? 'warn' : 'good' ) . '">';
		if ( $count ) {
			echo '<h2>' . esc_html( sprintf( _n( '%d thing to look at', '%d things to look at', $count, 'scout-core' ), $count ) ) . '</h2>';
			echo '<p>Your site is running. These would make it stronger.</p>';
		} else {
			echo '<h2>Your site is healthy</h2>';
			echo '<p>Business details, search visibility, and sharing are all set. Nice work.</p>';
		}
		echo '</div>';

		echo '<div class="scout-cards">';
		foreach ( $checks as $c ) {
			echo '<div class="scout-card ' . esc_attr( $c['status'] ) . '">';
			echo '<span class="scout-dot" aria-hidden="true"></span>';
			echo '<div class="scout-card-body">';
			echo '<strong>' . esc_html( $c['label'] ) . '</strong>';
			echo '<p>' . esc_html( $c['message'] ) . '</p>';
			if ( ! empty( $c['items'] ) ) {
				echo '<ul class="scout-card-list">';
				foreach ( $c['items'] as $item_id ) {
					$title = get_the_title( $item_id );
					printf(
						'<li><a href="%s">%s &rsaquo;</a></li>',
						esc_url( (string) get_edit_post_link( $item_id ) ),
						esc_html( '' !== $title ? $title : sprintf( '#%d', $item_id ) )
					);
				}
				echo '</ul>';
			}
			if ( ! empty( $c['action'] ) && ! empty( $c['action_label'] ) ) {
				printf( '<a href="%s">%s &rsaquo;</a>', esc_url( $c['action'] ), esc_html( $c['action_label'] ) );
			}
			echo '</div></div>';
		}
		echo '</div>';
	}

	/** @return array<int,array<string,string>> */
	private static function checks() {
		$base     = admin_url( 'admin.php?page=scout' );
		$biz      = Scout_Core_Business::get();
		$checks   = array();

		// 1. Business identity.
		$have = ! empty( $biz['name'] ) && ! empty( $biz['phone'] ) && ! empty( $biz['city'] );
		$checks[] = array(
			'label'        => 'Business details',
			'status'       => $have ? 'good' : 'warn',
			'message'      => $have
				? 'Your name, phone, and location are set, and they feed your search listing.'
				: 'Add your business name, phone, and city so search engines show the right details.',
			'action'       => $have ? '' : $base . '&tab=business',
			'action_label' => $have ? '' : 'Add business details',
		);

		// 2. Schema type.
		$type = ! empty( $biz['business_type'] );
		$checks[] = array(
			'label'        => 'Search listing (schema)',
			'status'       => $type ? 'good' : 'warn',
			'message'      => $type
				? 'Search engines and AI get a clear description of your business.'
				: 'Set your business type (e.g. LegalService, Restaurant) so your listing is specific.',
			'action'       => $type ? '' : $base . '&tab=business',
			'action_label' => $type ? '' : 'Set business type',
		);

		// 3. Pages missing a written description. Name them, so nobody has to hunt.
		$missing_ids = self::pages_missing_description();
		$missing     = count( $missing_ids );
		$checks[]    = array(
			'label'        => 'Page descriptions',
			'status'       => $missing ? 'warn' : 'good',
			'message'      => $missing
				? sprintf(
					_n(
						'%d published page has no written description, so search engines guess one. Open it and fill in the SEO box.',
						'%d published pages have no written description, so search engines guess one. Open each and fill in the SEO box.',
						$missing,
						'scout-core'
					),
					$missing
				)
				: 'Every published page has a description written for search.',
			'items'        => array_slice( $missing_ids, 0, 10 ),
			'action'       => $missing > 10 ? admin_url( 'edit.php?post_type=page' ) : '',
			'action_label' => $missing > 10 ? 'See all pages' : '',
		);

		// 4. Accidental noindex.
		$hidden = self::pages_noindexed();
		$checks[] = array(
			'label'        => 'Visible to Google',
			'status'       => $hidden ? 'warn' : 'good',
			'message'      => $hidden
				? sprintf(
					_n( '%d page is set to hidden from search. Confirm that is on purpose.', '%d pages are set to hidden from search. Confirm that is on purpose.', $hidden, 'scout-core' ),
					$hidden
				)
				: 'No pages are accidentally hidden from search engines.',
			'action'       => '',
			'action_label' => '',
		);

		// 5. Default share image.
		$image = self::has_share_image();
		$checks[] = array(
			'label'        => 'Social sharing',
			'status'       => $image ? 'good' : 'warn',
			'message'      => $image
				? 'When someone shares a page, it shows an image instead of a blank box.'
				: 'Add a default share image so shared links do not look empty.',
			'action'       => $image ? '' : $base . '&tab=seo',
			'action_label' => $image ? '' : 'Add share image',
		);

		/**
		 * Companions add their own checks (each: label, status good|warn,
		 * message, action, action_label).
		 *
		 * @param array $checks
		 */
		return (array) apply_filters( 'scout_core_dashboard_checks', $checks );
	}

	/** @return int[] Published pages and posts with no SEO description and no excerpt. */
	private static function pages_missing_description() {
		$ids = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 200,
			'no_found_rows'  => true,
		) );
		$out = array();
		foreach ( $ids as $id ) {
			$desc = get_post_meta( $id, SCOUT_SEO_META_DESC, true );
			if ( '' === $desc && ! has_excerpt( $id ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	private static function pages_noindexed() {
		$ids = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 200,
			'no_found_rows'  => true,
			'meta_key'       => SCOUT_SEO_META_NOINDEX,
			'meta_value'     => '1',
		) );
		return count( $ids );
	}

	private static function has_share_image() {
		if ( get_site_icon_url() ) {
			return true;
		}
		return class_exists( 'Scout_Core_SEO_Settings' ) && '' !== Scout_Core_SEO_Settings::value( 'default_image' );
	}

	/* ---------- Business tab ---------- */

	public static function tab_business() {
		$values = Scout_Core_Business::get();
		echo '<p class="scout-lead">One source of truth for your name, address, phone, and profiles. This feeds your search listing (schema) and any page that binds to it. Keep it identical to your Google Business Profile.</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'scout_business_group' );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( Scout_Core_Business::fields() as $key => $label ) {
			$id   = 'scout-' . $key;
			$name = Scout_Core_Business::OPTION . '[' . $key . ']';
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( in_array( $key, array( 'hours', 'same_as' ), true ) ) {
				printf( '<textarea id="%s" name="%s" rows="5" class="large-text">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $values[ $key ] ) );
			} else {
				printf( '<input type="text" id="%s" name="%s" value="%s" class="regular-text" />', esc_attr( $id ), esc_attr( $name ), esc_attr( $values[ $key ] ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}

	/* ---------- SEO tab ---------- */

	public static function tab_seo() {
		$values = Scout_Core_SEO_Settings::get();
		echo '<p class="scout-lead">Site-wide fallbacks. Each page can override its own title and description in the <strong>Scout SEO</strong> box while you edit it. Your XML sitemap is generated automatically at <code>/wp-sitemap.xml</code> for search engines.</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'scout_seo_group' );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( Scout_Core_SEO_Settings::fields() as $key => $label ) {
			$id   = 'scout-seo-' . $key;
			$name = Scout_Core_SEO_Settings::OPTION . '[' . $key . ']';
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( 'home_description' === $key ) {
				printf( '<textarea id="%s" name="%s" rows="3" class="large-text">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $values[ $key ] ) );
			} else {
				printf( '<input type="url" id="%s" name="%s" value="%s" class="regular-text" placeholder="https://" />', esc_attr( $id ), esc_attr( $name ), esc_attr( $values[ $key ] ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}

	/* ---------- Branded, scoped admin styles ---------- */

	private static function styles() {
		echo '<style>
		.scout-admin .scout-lead{max-width:64ch;font-size:14px;color:var(--sh-text-2);margin:16px 0 20px}
		.scout-hero{background:var(--sh-surface);border:1px solid var(--sh-border);border-left:4px solid var(--sh-good);border-radius:16px;padding:20px 24px;margin:16px 0}
		.scout-hero.warn{border-left-color:#FBB024}
		.scout-hero h2{margin:0 0 4px;font-size:20px;font-weight:600;letter-spacing:-.02em}
		.scout-hero p{margin:0;color:var(--sh-text-2)}
		.scout-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
		.scout-card{display:flex;gap:12px;background:var(--sh-surface);border:1px solid var(--sh-border);border-radius:16px;padding:18px 20px}
		.scout-card .scout-dot{flex:none;width:10px;height:10px;border-radius:50%;margin-top:6px;background:var(--sh-good)}
		.scout-card.warn .scout-dot{background:#FBB024}
		.scout-card-body strong{display:block;font-size:15px;font-weight:600}
		.scout-card-body p{margin:4px 0 6px;color:var(--sh-text-2);font-size:13px;line-height:1.5}
		.scout-card-body a{font-size:13px;text-decoration:none;font-weight:500}
		.scout-card-list{margin:2px 0 0;padding:0;list-style:none;display:grid;gap:4px}
		</style>';
	}
}
