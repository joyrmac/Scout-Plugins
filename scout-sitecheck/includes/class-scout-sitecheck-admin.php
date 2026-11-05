<?php
/**
 * Scout → Free site check (settings) and Check requests (the inbox).
 *
 * @package Scout_Sitecheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Sitecheck_Admin {

	/**
	 * One status line at the top of the screen (admin kit 1.10.0). An older kit
	 * copy may be the one loaded mid-update, so fall back to the same markup.
	 *
	 * @param string $go Button markup, escaped by the caller.
	 */
	private static function ui_status( string $state, string $headline, string $detail = '', string $go = '' ): void {
		if ( method_exists( 'Scout_Admin_UI', 'status' ) ) {
			Scout_Admin_UI::status( $state, $headline, $detail, $go );
			return;
		}
		$state = in_array( $state, array( 'good', 'warn', 'bad', 'off' ), true ) ? $state : 'good';
		echo '<section class="sh-box"><p class="sh-status is-' . esc_attr( $state ) . '"><span class="sh-dot" aria-hidden="true"></span><strong>' . esc_html( $headline ) . '</strong>';
		if ( '' !== $detail ) {
			echo ' <span>' . esc_html( $detail ) . '</span>';
		}
		if ( '' !== $go ) {
			echo ' <span class="sh-status-go">' . $go . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller.
		}
		echo '</p></section>';
	}

	/** A closed "More options" box (admin kit 1.10.0), with the same fallback. */
	private static function ui_fold_open( string $title ): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_open' ) ) {
			Scout_Admin_UI::fold_open( $title );
			return;
		}
		echo '<details class="sh-box sh-fold"><summary class="sh-title">' . esc_html( $title ) . '</summary>';
	}

	private static function ui_fold_close(): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_close' ) ) {
			Scout_Admin_UI::fold_close();
			return;
		}
		echo '</details>';
	}

	const SLUG = 'scout-sitecheck';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_scout_ssc_send', array( __CLASS__, 'send_now' ) );
		add_action( 'admin_post_scout_ssc_page', array( __CLASS__, 'create_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_SITECHECK_FILE ), array( __CLASS__, 'action_link' ) );
		add_filter( 'manage_' . Scout_Sitecheck::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Scout_Sitecheck::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'add_meta_boxes_' . Scout_Sitecheck::CPT, array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . Scout_Sitecheck::CPT, array( __CLASS__, 'save_followed' ) );
		add_filter( 'parent_file', array( __CLASS__, 'highlight' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'states' ), 10, 2 );
		add_filter( 'scout_admin_nav_group', array( __CLASS__, 'nav_group' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function parent(): string {
		global $admin_page_hooks;
		foreach ( array( 'scout-build', 'recon-build', 'site-build', 'scout' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'options-general.php';
	}

	public static function url(): string {
		return 'options-general.php' === self::parent() ? admin_url( 'options-general.php?page=' . self::SLUG ) : admin_url( 'admin.php?page=' . self::SLUG );
	}

	/** Site checks nobody has followed up on yet. */
	public static function waiting(): int {
		$q = new WP_Query(
			array(
				'post_type'      => Scout_Sitecheck::CPT,
				'post_status'    => 'private',
				'meta_key'       => '_ssc_followed', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '0', // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => 99,
				'no_found_rows'  => true,
			)
		);
		return count( $q->posts );
	}

	public static function menu(): void {
		$parent = self::parent();
		$n      = self::waiting();
		$bubble = $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '';
		add_submenu_page( $parent, __( 'Check requests', 'scout-sitecheck' ), __( 'Check requests', 'scout-sitecheck' ) . $bubble, 'edit_posts', 'edit.php?post_type=' . Scout_Sitecheck::CPT );
		add_submenu_page( $parent, __( 'Free site check', 'scout-sitecheck' ), __( 'Free site check', 'scout-sitecheck' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/** Site checks are leads, so they sit with the business screens. */
	public static function nav_group( string $group, string $slug ): string {
		if ( false === strpos( $slug, 'sitecheck' ) ) {
			return $group;
		}
		// Requests from visitors wait in the Inbox; the settings sit with the rest of the website.
		// An older admin kit (before 1.8.0) can be the copy loaded first and has no "website"
		// group, so file the settings under Your business there.
		$kit = defined( 'Scout_Admin_UI::VERSION' ) ? (string) Scout_Admin_UI::VERSION : '0';
		if ( false !== strpos( $slug, 'post_type=' ) ) {
			return 'inbox';
		}
		return version_compare( $kit, '1.8.0', '>=' ) ? 'website' : 'business';
	}

	public static function highlight( $file ) {
		global $submenu_file;
		$screen = get_current_screen();
		if ( $screen && Scout_Sitecheck::CPT === $screen->post_type ) {
			$submenu_file = 'edit.php?post_type=' . Scout_Sitecheck::CPT; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return self::parent();
		}
		return $file;
	}

	public static function states( array $states, WP_Post $post ): array {
		return Scout_Sitecheck::CPT === $post->post_type ? array() : $states;
	}

	public static function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( false !== strpos( $hook, self::SLUG ) || ( $screen && Scout_Sitecheck::CPT === $screen->post_type ) ) {
			Scout_Admin_UI::enqueue( SCOUT_SITECHECK_URL . 'assets/scout-admin.css' );
			wp_enqueue_style( 'scout-sitecheck', SCOUT_SITECHECK_URL . 'assets/sitecheck.css', array(), SCOUT_SITECHECK_VERSION );
		}
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'scout-sitecheck' ) . '</a>' );
		return $links;
	}

	/* ---------- the inbox ---------- */

	public static function columns( array $cols ): array {
		return array(
			'cb'         => $cols['cb'] ?? '',
			'title'      => __( 'Site', 'scout-sitecheck' ),
			'ssc_from'   => __( 'From', 'scout-sitecheck' ),
			'ssc_score'  => __( 'Passed', 'scout-sitecheck' ),
			'ssc_status' => __( 'Report', 'scout-sitecheck' ),
			'ssc_done'   => __( 'Followed up', 'scout-sitecheck' ),
			'date'       => __( 'Checked', 'scout-sitecheck' ),
		);
	}

	public static function status_label( int $id ): array {
		$labels = array(
			// Kit pill tones: progress for normal waiting states, good, and bad for a report that failed.
			'running'     => array( __( 'Running', 'scout-sitecheck' ), 'progress' ),
			'held'        => array( __( 'Waiting for review', 'scout-sitecheck' ), 'progress' ),
			'sent'        => array( __( 'Sent', 'scout-sitecheck' ), 'good' ),
			'not_sent'    => array( __( 'Not sent', 'scout-sitecheck' ), 'bad' ),
			'unreachable' => array( __( 'Couldn\'t reach the site', 'scout-sitecheck' ), 'bad' ),
		);
		return $labels[ (string) get_post_meta( $id, '_ssc_status', true ) ] ?? $labels['running'];
	}

	public static function column( string $col, int $id ): void {
		if ( 'ssc_from' === $col ) {
			$email = (string) get_post_meta( $id, '_ssc_email', true );
			echo esc_html( (string) get_post_meta( $id, '_ssc_name', true ) );
			if ( '' !== $email ) {
				echo '<br><a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
			}
		} elseif ( 'ssc_score' === $col ) {
			$total = (int) get_post_meta( $id, '_ssc_total', true );
			echo $total ? esc_html( (int) get_post_meta( $id, '_ssc_passed', true ) . ' / ' . $total ) : '&nbsp;';
		} elseif ( 'ssc_status' === $col ) {
			list( $label, $tone ) = self::status_label( $id );
			echo '<span class="sh-pill is-' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
		} elseif ( 'ssc_done' === $col ) {
			echo get_post_meta( $id, '_ssc_followed', true ) ? '<span class="sh-pill is-good">' . esc_html__( 'Yes', 'scout-sitecheck' ) . '</span>' : '<span class="sh-pill">' . esc_html__( 'Not yet', 'scout-sitecheck' ) . '</span>'; // Calm: not following up yet is not a warning.
		}
	}

	public static function meta_box(): void {
		remove_meta_box( 'submitdiv', Scout_Sitecheck::CPT, 'side' );
		add_meta_box( 'scout-ssc-report', __( 'Report', 'scout-sitecheck' ), array( __CLASS__, 'report_box' ), Scout_Sitecheck::CPT, 'normal', 'high' );
		add_meta_box( 'scout-ssc-follow', __( 'Follow up', 'scout-sitecheck' ), array( __CLASS__, 'follow_box' ), Scout_Sitecheck::CPT, 'side', 'high' );
	}

	public static function report_box( WP_Post $post ): void {
		$rows = Scout_Sitecheck::rows( $post->ID );
		list( $label ) = self::status_label( $post->ID );
		$url = (string) get_post_meta( $post->ID, '_ssc_url', true );
		echo '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a></p><p>' . esc_html( $label ) . '</p>';
		if ( null === $rows ) {
			echo '<p>' . esc_html__( 'The check hasn\'t finished. If the visitor left the page, it finishes on its own within ten minutes.', 'scout-sitecheck' ) . '</p>';
			return;
		}
		$r = Scout_Sitecheck::summary( $post->ID, $rows );
		/* translators: 1: checks passed, 2: total checks */
		echo '<p><strong>' . esc_html( sprintf( __( '%1$d of %2$d passed', 'scout-sitecheck' ), (int) $r['passed'], (int) $r['total'] ) ) . '</strong></p><p>' . esc_html( $r['headline'] ) . '</p>';
		echo '<div class="ssc ssc-admin">' . Scout_Sitecheck_Report::rows_html( $rows ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
	}

	public static function follow_box( WP_Post $post ): void {
		$name  = (string) get_post_meta( $post->ID, '_ssc_name', true );
		$email = (string) get_post_meta( $post->ID, '_ssc_email', true );
		$subj  = rawurlencode( sprintf( /* translators: %s: site */ __( 'Your site check for %s', 'scout-sitecheck' ), $post->post_title ) );
		$sent  = (int) get_post_meta( $post->ID, '_ssc_sent', true );
		$ready = null !== Scout_Sitecheck::rows( $post->ID );
		wp_nonce_field( 'scout_ssc_followed', 'scout_ssc_nonce' );
		?>
		<p><strong><?php echo esc_html( $name ); ?></strong><br><?php echo esc_html( $email ); ?></p>
		<?php if ( $ready ) : ?>
			<p><a class="button<?php echo $sent ? '' : ' button-primary'; ?>" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=scout_ssc_send&id=' . $post->ID ), 'scout_ssc_send_' . $post->ID ) ); ?>"><?php echo $sent ? esc_html__( 'Send the report again', 'scout-sitecheck' ) : esc_html__( 'Send report', 'scout-sitecheck' ); ?></a></p>
			<?php if ( $sent ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Emailed %s.', 'scout-sitecheck' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $sent ) ) ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
		<p><a class="button" href="<?php echo esc_url( 'mailto:' . $email . '?subject=' . $subj ); ?>"><?php esc_html_e( 'Reply by email', 'scout-sitecheck' ); ?></a></p>
		<p><label><input type="checkbox" name="scout_ssc_followed" value="1" <?php checked( (bool) get_post_meta( $post->ID, '_ssc_followed', true ) ); ?>> <?php esc_html_e( 'Followed up', 'scout-sitecheck' ); ?></label></p>
		<?php submit_button( __( 'Save', 'scout-sitecheck' ), 'secondary', 'save', false ); ?>
		<?php
	}

	public static function save_followed( int $id ): void {
		if ( ! isset( $_POST['scout_ssc_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['scout_ssc_nonce'] ), 'scout_ssc_followed' ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		update_post_meta( $id, '_ssc_followed', empty( $_POST['scout_ssc_followed'] ) ? 0 : 1 );
	}

	public static function send_now(): void {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'scout_ssc_send_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) || Scout_Sitecheck::CPT !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'scout-sitecheck' ) );
		}
		$ok = Scout_Sitecheck_Report::send( $id );
		if ( $ok ) {
			update_post_meta( $id, '_ssc_status', 'sent' );
		}
		wp_safe_redirect( add_query_arg( 'ssc_sent', $ok ? '1' : '0', admin_url( 'post.php?post=' . $id . '&action=edit' ) ) );
		exit;
	}

	public static function notice(): void {
		if ( ! isset( $_GET['ssc_sent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- display only.
			return;
		}
		$ok = '1' === $_GET['ssc_sent']; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . ' is-dismissible"><p>' . ( $ok ? esc_html__( 'Report sent.', 'scout-sitecheck' ) : esc_html__( 'The email didn\'t go out. Check the site\'s email setup, then try again.', 'scout-sitecheck' ) ) . '</p></div>';
	}

	/* ---------- settings ---------- */

	public static function register(): void {
		register_setting( 'scout_sitecheck_group', Scout_Sitecheck::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ): array {
		$in  = (array) $in;
		$old = Scout_Sitecheck::settings();
		$key = trim( (string) ( $in['psi_key'] ?? '' ) );
		if ( '' === $key ) {
			$key = $old['psi_key']; // Blank keeps the saved key; it is never shown back.
		}
		if ( ! empty( $in['psi_key_clear'] ) ) {
			$key = '';
		}
		return array(
			'notify'     => sanitize_email( (string) ( $in['notify'] ?? '' ) ),
			'lead_name'  => sanitize_text_field( (string) ( $in['lead_name'] ?? '' ) ),
			'lead_photo' => esc_url_raw( (string) ( $in['lead_photo'] ?? '' ) ),
			'book_url'   => esc_url_raw( (string) ( $in['book_url'] ?? '' ) ),
			'psi_key'    => sanitize_text_field( $key ),
			'hold'       => ! empty( $in['hold'] ) ? 1 : 0,
			'page'       => $old['page'],
		);
	}

	public static function create_page(): void {
		check_admin_referer( 'scout_ssc_page' );
		if ( ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'scout-sitecheck' ) );
		}
		$s  = Scout_Sitecheck::settings();
		$id = $s['page'] && get_post( $s['page'] ) ? $s['page'] : 0;
		if ( ! $id ) {
			$id = (int) wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'draft',
					'post_title'   => __( 'Free website check', 'scout-sitecheck' ),
					'post_name'    => 'site-check',
					'post_excerpt' => __( 'Enter your web address and get a free check of your site\'s speed, security, search basics, phone layout and AI readiness in about a minute.', 'scout-sitecheck' ),
					'post_content' => '<!-- wp:paragraph --><p>' . esc_html__( 'Enter your website address and we\'ll check eight things that decide whether people and Google can find you, trust you and reach you. You\'ll see the results here in about a minute, and we\'ll email you the full report.', 'scout-sitecheck' ) . '</p><!-- /wp:paragraph --><!-- wp:shortcode -->[scout_site_check]<!-- /wp:shortcode -->',
				)
			);
			$opt         = (array) get_option( Scout_Sitecheck::OPTION, array() );
			$opt['page'] = $id;
			update_option( Scout_Sitecheck::OPTION, $opt );
		}
		wp_safe_redirect( $id ? admin_url( 'post.php?post=' . $id . '&action=edit' ) : self::url() );
		exit;
	}

	private static function row( string $label, string $field, string $help = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $field . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- fields escaped by caller.
	}

	private static function text( string $key, string $value, string $placeholder = '', string $type = 'text' ): string {
		return '<input type="' . esc_attr( $type ) . '" class="regular-text" name="' . esc_attr( Scout_Sitecheck::OPTION . '[' . $key . ']' ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = Scout_Sitecheck::settings();
		$o    = Scout_Sitecheck::OPTION;
		$page = $s['page'] ? get_post( $s['page'] ) : null;
		$live = $page && 'publish' === $page->post_status;
		$key  = '' !== Scout_Sitecheck::psi_key();
		$n    = self::waiting();
		Scout_Admin_UI::open( __( 'Free site check', 'scout-sitecheck' ), __( 'Visitors enter their web address and get a free check of eight things, with the full report by email.', 'scout-sitecheck' ), __( 'Free site check', 'scout-sitecheck' ) );
		settings_errors( $o );
		?>
		<?php
		// The status line: a few words, one sentence, one button. The speed key's state sits under More options.
		if ( $live ) {
			$headline = __( 'Live.', 'scout-sitecheck' );
			$detail   = $key ? __( 'The free site check page is on the site.', 'scout-sitecheck' ) : __( 'The page is on the site, but speed checks often fail until you add a Google speed key under More options.', 'scout-sitecheck' );
		} elseif ( $page ) {
			$headline = __( 'Draft.', 'scout-sitecheck' );
			$detail   = __( 'The page is made but not published yet.', 'scout-sitecheck' );
		} else {
			$headline = __( 'Not made yet.', 'scout-sitecheck' );
			$detail   = __( 'Make the page as a draft, check it, then publish it.', 'scout-sitecheck' );
		}
		if ( $n ) {
			/* translators: 1: "Live." 2: number of site checks waiting for follow-up. */
			$headline = sprintf( _n( '%1$s %2$d check is waiting.', '%1$s %2$d checks are waiting.', $n, 'scout-sitecheck' ), $headline, $n );
		}
		if ( $page ) {
			$go = '<a class="button" href="' . esc_url( (string) get_edit_post_link( $page->ID ) ) . '">' . esc_html__( 'Edit the page', 'scout-sitecheck' ) . '</a>';
			if ( $live ) {
				$go .= ' <a class="button sh-quiet" href="' . esc_url( (string) get_permalink( $page ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'scout-sitecheck' ) . '</a>';
			}
		} else {
			$go = '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=scout_ssc_page' ), 'scout_ssc_page' ) ) . '">' . esc_html__( 'Make the page (as a draft)', 'scout-sitecheck' ) . '</a>';
		}
		$go .= ' <a class="button sh-quiet" href="' . esc_url( admin_url( 'edit.php?post_type=' . Scout_Sitecheck::CPT ) ) . '">' . esc_html__( 'Check requests', 'scout-sitecheck' ) . ' &rsaquo;</a>';
		// Amber only for a real gap: no page live, or speed checks failing for want of a key.
		self::ui_status( $live && $key ? 'good' : ( $live || $page ? 'warn' : 'off' ), $headline, $detail, $go );
		?>

		<form method="post" action="options.php">
			<?php settings_fields( 'scout_sitecheck_group' ); ?>
			<div class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Who follows up', 'scout-sitecheck' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::row( __( 'Email new checks to', 'scout-sitecheck' ), self::text( 'notify', $s['notify'], Scout_Sitecheck::notify_to(), 'email' ), __( 'Gets every check and the replies. Leave blank to use Send notices to in Business info.', 'scout-sitecheck' ) );
					self::row( __( 'Who follows up', 'scout-sitecheck' ), self::text( 'lead_name', $s['lead_name'], __( 'A first name', 'scout-sitecheck' ) ), __( 'The name in the report, like "Maria will walk you through them." Name only someone who agreed.', 'scout-sitecheck' ) );
					self::row( __( 'Their photo', 'scout-sitecheck' ), self::text( 'lead_photo', $s['lead_photo'], 'https://', 'url' ), __( 'Optional. A JPG or PNG link, since email apps can\'t show WebP.', 'scout-sitecheck' ) );
					self::row( __( 'Booking page', 'scout-sitecheck' ), self::text( 'book_url', $s['book_url'], Scout_Sitecheck::book_url(), 'url' ), __( 'Where "Book a call" goes. Blank uses the site\'s booking page, or Contact.', 'scout-sitecheck' ) );
					self::row( __( 'Review before sending', 'scout-sitecheck' ), '<label><input type="checkbox" name="' . esc_attr( $o ) . '[hold]" value="1" ' . checked( $s['hold'], true, false ) . '> ' . esc_html__( 'Hold each report until someone opens it and clicks Send report', 'scout-sitecheck' ) . '</label>', __( 'Off: the report emails right away.', 'scout-sitecheck' ) );
					?>
				</table>
			</div>
			<?php self::ui_fold_open( __( 'More options', 'scout-sitecheck' ) ); ?>
				<h3><?php esc_html_e( 'Google\'s speed tool', 'scout-sitecheck' ); ?></h3>
				<p class="sh-status<?php echo $key ? '' : ' is-warn'; ?>"><span class="sh-dot" aria-hidden="true"></span><strong><?php echo esc_html( $key ? __( 'Key saved.', 'scout-sitecheck' ) : __( 'No key yet.', 'scout-sitecheck' ) ); ?></strong> <span><?php esc_html_e( 'Without a free PageSpeed Insights key from Google Cloud, Google often turns speed checks away.', 'scout-sitecheck' ); ?></span></p>
				<table class="form-table" role="presentation">
					<?php
					if ( defined( 'SCOUT_SITECHECK_PSI_KEY' ) ) {
						self::row( __( 'Key', 'scout-sitecheck' ), esc_html__( 'Set in wp-config.php (SCOUT_SITECHECK_PSI_KEY).', 'scout-sitecheck' ) );
					} else {
						self::row(
							__( 'Key', 'scout-sitecheck' ),
							self::text( 'psi_key', '', '' !== $s['psi_key'] ? __( 'Saved. Leave blank to keep it.', 'scout-sitecheck' ) : '', 'password' )
							. ( '' !== $s['psi_key'] ? '<br><label><input type="checkbox" name="' . esc_attr( $o ) . '[psi_key_clear]" value="1"> ' . esc_html__( 'Remove the saved key', 'scout-sitecheck' ) . '</label>' : '' )
						);
					}
					?>
				</table>
				<h3><?php esc_html_e( 'Putting it on another page', 'scout-sitecheck' ); ?></h3>
				<p><?php esc_html_e( 'Add a Shortcode block with [scout_site_check]. Use [scout_site_check sample="no"] to leave out the sample report.', 'scout-sitecheck' ); ?></p>
			<?php self::ui_fold_close(); ?>
			<?php submit_button( __( 'Save changes', 'scout-sitecheck' ), $page ? 'primary' : 'secondary' ); // One primary: Make the page leads until the page exists. ?>
		</form>
		<?php
		Scout_Admin_UI::close();
	}
}
