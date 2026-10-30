<?php
/**
 * The Scout admin screens. A top-level "Scout" menu with three screens
 * (with the Scout Build hub: Business info and SEO; its Site health shows the
 * search checks). The shared kit draws the section nav across all of them:
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

	/** The menu these screens sit in: a theme's Build menu when there is one, else Scout's own. */
	public static function parent() {
		global $admin_page_hooks;
		foreach ( array( 'scout-build', 'recon-build' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'scout';
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_scout' === $hook || '_page_scout' === substr( (string) $hook, -11 ) ) {
			Scout_Admin_UI::enqueue( plugins_url( 'assets/scout-admin.css', SCOUT_CORE_FILE ) );
		}
	}

	/**
	 * Is the Scout Build hub running? Then it owns the home screen and site
	 * health, and this plugin adds only its settings screens.
	 */
	public static function hub() {
		return class_exists( 'Scout_Hub_Admin' );
	}

	public static function menu() {
		$label = Scout_Admin_UI::branded() ? 'Scout' : 'Site Build';
		$icon  = 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#0E8FE6" d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/></svg>'
		);
		// A theme's own Build menu (Scout Build, Recon Build) comes first: the
		// screens join it so the site has one Scout menu, not two. The page slug
		// stays "scout" either way, so every link to these screens still works.
		$parent = self::parent();
		if ( 'scout' === $parent ) {
			add_menu_page( $label, $label, 'manage_options', 'scout', array( __CLASS__, 'render' ), $icon, 3 );
		}
		if ( self::hub() ) {
			// The hub's Site health carries these checks, so this screen opens on Business info.
			add_submenu_page( $parent, 'Business info', 'Business info', 'manage_options', 'scout', array( __CLASS__, 'render' ) );
		} else {
			add_submenu_page( $parent, 'Search health', 'Search health', 'manage_options', 'scout', array( __CLASS__, 'render' ) );
			add_submenu_page( $parent, 'Business info', 'Business info', 'manage_options', 'scout&tab=business', array( __CLASS__, 'render' ) );
		}
		add_submenu_page( $parent, 'SEO', 'SEO', 'manage_options', 'scout&tab=seo', array( __CLASS__, 'render' ) );
		add_submenu_page( $parent, 'Site Sync', 'Site Sync', 'manage_options', 'scout&tab=sync', array( __CLASS__, 'render' ) );
		add_submenu_page( $parent, 'Spam protection', 'Spam protection', 'manage_options', 'scout&tab=protect', array( __CLASS__, 'render' ) );
		foreach ( self::groups() as $slug => $group ) {
			add_submenu_page( $parent, $group['label'], $group['label'], 'manage_options', 'scout&tab=' . $slug, array( __CLASS__, 'render' ) );
		}
	}

	/** Companion-registered settings groups, if the class is loaded. */
	private static function groups() {
		return class_exists( 'Scout_Core_Settings_Groups' ) ? Scout_Core_Settings_Groups::all() : array();
	}

	/** @return array<string,string> tab => label */
	private static function tabs() {
		$tabs = self::hub()
			? array( 'business' => 'Business info', 'seo' => 'SEO' )
			: array( 'dashboard' => 'Search health', 'business' => 'Business info', 'seo' => 'SEO' );
		$tabs['sync']    = 'Site Sync';
		$tabs['protect'] = 'Spam protection';
		foreach ( self::groups() as $slug => $group ) {
			$tabs[ $slug ] = $group['label'];
		}
		return $tabs;
	}

	private static function current_tab() {
		$home = self::hub() ? 'business' : 'dashboard';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : $home;
		return array_key_exists( $tab, self::tabs() ) ? $tab : $home;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab   = self::current_tab();
		$tabs  = self::tabs();
		$ledes = array(
			'dashboard' => 'A quick check that search engines and AI tools can find the business and read it correctly.',
			'business'  => 'Your name, address, phone and profiles, entered once. They feed your search listing and every page that shows them. Keep them identical to your Google Business Profile.',
			'sync'      => 'Keeps each page in step with the copy the theme ships. Pages nobody has edited update on their own after a theme update; anything changed in WordPress waits for you.',
			'protect'   => 'Two switches that keep spam away from the business. Both are on unless you turn them off.',
			'seo'       => 'Site-wide search settings. Each page can set its own title and description in its SEO box, and your sitemap is built automatically at /wp-sitemap.xml.',
		);
		self::styles();
		Scout_Admin_UI::open( $tabs[ $tab ], $ledes[ $tab ] ?? '', $tabs[ $tab ] );
		echo '<div class="scout-admin sh-sections">';

		if ( 'business' === $tab ) {
			self::tab_business();
		} elseif ( 'seo' === $tab ) {
			self::tab_seo();
		} elseif ( 'sync' === $tab ) {
			Scout_Sync_Admin::render();
		} elseif ( 'protect' === $tab ) {
			Scout_Core_Protection::render_tab();
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
		$count  = count( array_filter( $checks, static function ( $c ) { return 'good' !== $c['status']; } ) );
		echo '<section class="sh-box">';
		echo '<h2>' . esc_html( $count ? sprintf( _n( '%d thing to look at', '%d things to look at', $count, 'scout-core' ), $count ) : 'Your site is healthy' ) . '</h2>';
		echo '<p>' . esc_html( $count ? 'Your site is running. These would make it stronger.' : 'Business details, search visibility and sharing are all set.' ) . '</p>';
		echo '</section>';
		self::render_checks( $checks );
	}

	/**
	 * Check cards in the shared Scout style. The Scout Build hub prints the same
	 * cards on its Site health screen, so both read as one product.
	 *
	 * @param array<int,array<string,mixed>> $checks From checks().
	 */
	public static function render_checks( array $checks ) {
		echo '<div class="sh-grid sh-grid-3">';
		foreach ( $checks as $c ) {
			$state = 'good' === $c['status'] ? 'good' : ( 'bad' === $c['status'] ? 'bad' : 'warn' );
			echo '<section class="sh-box sh-check is-' . esc_attr( $state ) . '">';
			echo '<p class="sh-label">' . esc_html( $c['label'] ) . '</p>';
			echo '<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span>' . esc_html( 'good' === $state ? 'All set' : 'Needs a look' ) . '</p>';
			echo '<p class="sh-note">' . esc_html( $c['message'] ) . '</p>';
			if ( ! empty( $c['items'] ) ) {
				echo '<ul class="sh-list">';
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
				printf( '<p class="sh-note"><a href="%s">%s &rsaquo;</a></p>', esc_url( $c['action'] ), esc_html( $c['action_label'] ) );
			}
			echo '</section>';
		}
		echo '</div>';
	}

	/**
	 * The search checks, also shown in the Scout Build hub's Site health.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function checks() {
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
		// A theme that writes its own head (scout_head, recon_head) sets each
		// page's description in its templates and never reads the SEO box, so
		// an empty box there is not a gap and filling it would change nothing.
		$theme_head  = class_exists( 'Scout_SEO_Head' ) && Scout_SEO_Head::theme_handles_head();
		$missing_ids = $theme_head ? array() : self::pages_missing_description();
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
				: ( $theme_head ? 'Your theme writes each page\'s description for search.' : 'Every published page has a description written for search.' ),
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
		</style>';
	}
}
