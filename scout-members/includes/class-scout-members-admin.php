<?php
/**
 * The Members hub: its own sidebar item with the Scout bolt, beside Events.
 * Home (numbers, applications to read, renewals coming up), the members
 * list, a spreadsheet import for members moving from another system, and
 * settings. Member records are personal, so only people who can list users
 * (administrators) see any of it.
 *
 * @package Scout_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Members_Admin {

	const SLUG = 'scout-members';
	const CAP  = 'list_users';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_scout_members_decide', array( __CLASS__, 'decide' ) );
		add_action( 'admin_post_scout_members_update', array( __CLASS__, 'update' ) );
		add_action( 'admin_post_scout_members_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_scout_members_import', array( __CLASS__, 'import' ) );
		add_action( 'admin_post_scout_members_review', array( __CLASS__, 'review' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_MEMBERS_FILE ), array( __CLASS__, 'action_link' ) );
		add_filter( 'scout_events_needs_you', array( __CLASS__, 'needs_you' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'toolbar' ), 92 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_box' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'new_page_parent' ), 10, 2 );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}

	/** The hub, or one of its views ('members', 'renewals', 'import', 'settings'). */
	public static function url( string $view = '', array $args = array() ): string {
		$base = admin_url( 'admin.php?page=' . self::SLUG . ( in_array( $view, array( 'members', 'renewals', 'import', 'settings' ), true ) ? '-' . $view : '' ) );
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	private static function icon(): string {
		if ( ! Scout_Admin_UI::branded() ) {
			return 'dashicons-groups';
		}
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#0E8FE6" d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- menu icon data URI.
	}

	public static function menu(): void {
		add_menu_page( __( 'Members', 'scout-members' ), __( 'Members', 'scout-members' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ), self::icon(), '3.2' );
		add_submenu_page( self::SLUG, __( 'Members', 'scout-members' ), __( 'Home', 'scout-members' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
		add_submenu_page( self::SLUG, __( 'All members', 'scout-members' ), __( 'All members', 'scout-members' ), self::CAP, self::SLUG . '-members', array( __CLASS__, 'render_members' ) );
		$review = count( self::to_review() );
		$label  = __( 'Renewals due', 'scout-members' ) . ( $review ? ' <span class="awaiting-mod">' . (int) $review . '</span>' : '' );
		add_submenu_page( self::SLUG, __( 'Renewals due', 'scout-members' ), $label, self::CAP, self::SLUG . '-renewals', array( __CLASS__, 'render_renewals' ) );
		add_submenu_page( self::SLUG, __( 'Add from spreadsheet', 'scout-members' ), __( 'Add from spreadsheet', 'scout-members' ), self::CAP, self::SLUG . '-import', array( __CLASS__, 'render_import' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'scout-members' ), __( 'Settings', 'scout-members' ), self::CAP, self::SLUG . '-settings', array( __CLASS__, 'render_settings' ) );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		Scout_Admin_UI::enqueue( SCOUT_MEMBERS_URL . 'assets/scout-admin.css' );
		wp_enqueue_style( 'scout-members-admin', SCOUT_MEMBERS_URL . 'assets/members-admin.css', array( 'scout-admin' ), SCOUT_MEMBERS_VERSION );
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Members', 'scout-members' ) . '</a>' );
		return $links;
	}

	private static function back( string $msg, string $hash = '' ): void {
		$ref = wp_get_referer();
		$url = ( $ref && false !== strpos( $ref, 'page=' . self::SLUG ) ) ? remove_query_arg( array( 'scout-members', 'member' ), $ref ) : self::url();
		$url = preg_replace( '/#.*$/', '', $url );
		wp_safe_redirect( add_query_arg( 'scout-members', $msg, $url ) . $hash );
		exit;
	}

	/* ---------- Needs you and the toolbar ---------- */

	/** @return array<int,array{text:string,url:string,action:string}> */
	public static function waiting(): array {
		if ( ! current_user_can( self::CAP ) ) {
			return array();
		}
		$items   = array();
		$applied = count( Scout_Members::ids( 'applied' ) );
		if ( $applied ) {
			$items[] = array(
				/* translators: %d: number of applications. */
				'text'   => sprintf( _n( '%d membership application to read.', '%d membership applications to read.', $applied, 'scout-members' ), $applied ),
				'url'    => self::url() . '#sm-applications',
				'action' => __( 'Read applications', 'scout-members' ),
			);
		}
		$review = count( self::to_review() );
		if ( $review ) {
			$items[] = array(
				/* translators: %d: number of payments. */
				'text'   => sprintf( _n( '%d membership payment to review (a refund, or a payment nobody matched).', '%d membership payments to review (refunds, or payments nobody matched).', $review, 'scout-members' ), $review ),
				'url'    => self::url( 'renewals' ) . '#sm-review',
				'action' => __( 'Review payments', 'scout-members' ),
			);
		}
		$due = count( Scout_Members::renewals_due() );
		if ( $due ) {
			$items[] = array(
				/* translators: %d: number of members. */
				'text'   => sprintf( _n( '%d membership renews within 30 days or is past due.', '%d memberships renew within 30 days or are past due.', $due, 'scout-members' ), $due ),
				'url'    => self::url( 'renewals' ),
				'action' => __( 'See renewals', 'scout-members' ),
			);
		}
		return $items;
	}

	/**
	 * Payments waiting for the owner: members flagged by a refund or an odd
	 * payment, and dues orders that matched nobody.
	 *
	 * @return array<int,array{member:int,order:int,reason:string,on:string}>
	 */
	public static function to_review(): array {
		$out = array();
		foreach ( Scout_Members::flagged_ids() as $id ) {
			$f = Scout_Members::flagged( $id );
			if ( $f ) {
				$out[] = array(
					'member' => $id,
					'order'  => $f['order'],
					'reason' => $f['reason'],
					'on'     => $f['on'],
				);
			}
		}
		if ( Scout_Members_Woo::active() ) {
			foreach ( Scout_Members_Woo::unmatched() as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					Scout_Members_Woo::clear_unmatched( $order_id );
					continue;
				}
				$out[] = array(
					'member' => 0,
					'order'  => $order_id,
					/* translators: 1: order number, 2: email on the order. */
					'reason' => sprintf( __( 'Dues order #%1$s from %2$s matched no member.', 'scout-members' ), $order->get_order_number(), $order->get_billing_email() ),
					'on'     => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
				);
			}
		}
		return $out;
	}

	/** Keep as is, or end the membership, for a flagged payment; clear an unmatched order. */
	public static function review(): void {
		$user_id = isset( $_POST['member'] ) ? absint( $_POST['member'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		$order   = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( 'scout_members_review_' . $user_id . '_' . $order );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to change members.', 'scout-members' ), 403 );
		}
		if ( ! $user_id ) {
			Scout_Members_Woo::clear_unmatched( $order );
			self::back( 'cleared', '#sm-review' );
		}
		if ( ! get_userdata( $user_id ) ) {
			wp_die( esc_html__( 'That member no longer exists.', 'scout-members' ), 404 );
		}
		delete_user_meta( $user_id, Scout_Members::REVIEW );
		if ( ! empty( $_POST['end'] ) ) {
			Scout_Members::set_status( $user_id, 'ended' );
			Scout_Members::log( $user_id, __( 'Ended by the owner after reviewing a payment.', 'scout-members' ) );
			self::back( 'ended', '#sm-review' );
		}
		Scout_Members::log( $user_id, __( 'Payment reviewed by the owner. Nothing else changed.', 'scout-members' ) );
		self::back( 'reviewed', '#sm-review' );
	}

	/** True when this member's approval should wait for dues through the checkout. */
	private static function dues_first( int $user_id ): bool {
		if ( ! Scout_Members_Woo::active() || ! Scout_Members_Woo::product( Scout_Members::member( $user_id )['plan'] ) ) {
			return false;
		}
		$amount = Scout_Members_Woo::amount( $user_id );
		return '' === $amount || (float) $amount > 0;
	}

	/** Membership items join the Events Needs you list, so one screen shows everything. */
	public static function needs_you( $items ): array {
		return array_merge( (array) $items, self::waiting() );
	}

	/** "Members" under the Events toolbar menu when Events is there, else its own item. */
	public static function toolbar( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$applied = count( Scout_Members::ids( 'applied' ) );
		$title   = $applied
			/* translators: %d: number of applications. */
			? sprintf( _n( 'Members (%d to read)', 'Members (%d to read)', $applied, 'scout-members' ), $applied )
			: __( 'Members', 'scout-members' );
		$bar->add_node(
			array(
				'parent' => $bar->get_node( 'scout-events' ) ? 'scout-events' : false,
				'id'     => 'scout-members',
				'title'  => esc_html( $title ),
				'href'   => self::url(),
			)
		);
	}

	/* ---------- members-only box on pages and posts ---------- */

	public static function box(): void {
		foreach ( array( 'page', 'post' ) as $type ) {
			add_meta_box( 'scout-members-only', __( 'Members only', 'scout-members' ), array( __CLASS__, 'render_box' ), $type, 'side' );
		}
	}

	public static function render_box( $post ): void {
		$id = (int) $post->ID;
		wp_nonce_field( 'scout_members_only_' . $id, '_sm_only' );
		$by = Scout_Members::locked_by( $id );
		if ( Scout_Members::home_id() === $id ) {
			echo '<p><strong>' . esc_html__( 'This is the members home.', 'scout-members' ) . '</strong> ' . esc_html__( 'It and every page under it are for members only.', 'scout-members' ) . '</p>';
		} elseif ( $by ) {
			/* translators: %s: the parent page's title. */
			echo '<p>' . esc_html( sprintf( __( 'Members only, because it sits under "%s".', 'scout-members' ), get_the_title( $by ) ) ) . '</p>';
		} else {
			echo '<p><label><input type="checkbox" name="sm_members_only" value="1" ' . checked( Scout_Members::is_members_only( $id ), true, false ) . '> ' . esc_html__( 'Only members can read this', 'scout-members' ) . '</label></p>';
		}
		$plans = Scout_Members::plans();
		if ( count( $plans ) > 1 ) {
			$own = Scout_Members::own_plans( $id );
			echo '<fieldset class="sm-box-plans"><legend>' . esc_html__( 'Only for these plans (optional)', 'scout-members' ) . '</legend>';
			foreach ( $plans as $slug => $plan ) {
				echo '<label style="display:block"><input type="checkbox" name="sm_plans[]" value="' . esc_attr( $slug ) . '" ' . checked( in_array( $slug, $own, true ), true, false ) . '> ' . esc_html( $plan['name'] ) . '</label>';
			}
			echo '</fieldset>';
			$inherited = $own ? array() : Scout_Members::plans_for( $id );
			if ( $inherited ) {
				/* translators: %s: plan names. */
				echo '<p class="description">' . esc_html( sprintf( __( 'Now: %s only, from a parent page.', 'scout-members' ), Scout_Members::plan_names( $inherited ) ) ) . '</p>';
			}
		}
		echo '<p class="description">' . esc_html__( 'Everyone else sees a sign-in form and a link to join. Tick no plans and every member can read it.', 'scout-members' ) . '</p>';
	}

	public static function save_box( $post_id, $post ): void {
		if ( ! isset( $_POST['_sm_only'] ) || wp_is_post_revision( $post_id ) || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sm_only'] ) ), 'scout_members_only_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! Scout_Members::locked_by( (int) $post_id ) && Scout_Members::home_id() !== (int) $post_id ) {
			Scout_Members::set_members_only( (int) $post_id, ! empty( $_POST['sm_members_only'] ) );
		}
		$plans = isset( $_POST['sm_plans'] ) ? array_map( 'sanitize_title', (array) wp_unslash( $_POST['sm_plans'] ) ) : array();
		Scout_Members::set_plans( (int) $post_id, $plans );
		// A plan limit on a page nobody locked would do nothing, so it locks the page too.
		if ( $plans && ! Scout_Members::locked( (int) $post_id ) ) {
			Scout_Members::set_members_only( (int) $post_id, true );
		}
	}

	/* ---------- actions ---------- */

	/** Approve an application, or mark it not accepted. */
	public static function decide(): void {
		$user_id = isset( $_POST['member'] ) ? absint( $_POST['member'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( 'scout_members_decide_' . $user_id );
		if ( ! current_user_can( self::CAP ) || ! $user_id || ! get_userdata( $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to decide on applications.', 'scout-members' ), 403 );
		}
		if ( ! empty( $_POST['approve'] ) ) {
			$plan = isset( $_POST['plan'] ) ? sanitize_title( wp_unslash( $_POST['plan'] ) ) : '';
			if ( isset( Scout_Members::plans()[ $plan ] ) ) {
				Scout_Members::save( $user_id, array( 'plan' => $plan ) );
			}
			Scout_Members::log( $user_id, __( 'Application approved.', 'scout-members' ) );
			$prepaid = Scout_Members::member( $user_id )['prepaid'];
			if ( $prepaid ) {
				// Paid with the application: the first year starts today.
				Scout_Members::save( $user_id, array( 'prepaid' => 0 ) );
				Scout_Members::set_status( $user_id, 'active' );
				/* translators: %d: order number. */
				Scout_Members::paid( $user_id, Scout_Members::today(), sprintf( __( 'order #%d, paid with the application', 'scout-members' ), $prepaid ) );
				$msg = 'approved';
			} elseif ( self::dues_first( $user_id ) ) {
				Scout_Members::set_status( $user_id, 'approved' );
				$msg = 'approved_pay';
			} else {
				Scout_Members::set_status( $user_id, 'active' );
				$msg = 'approved';
			}
			self::welcome( $user_id );
			self::back( $msg, '#sm-applications' );
		}
		// No email goes out: the owner reaches out personally if they want to.
		$prepaid = Scout_Members::member( $user_id )['prepaid'];
		Scout_Members::set_status( $user_id, 'declined' );
		Scout_Members::log( $user_id, __( 'Application not accepted.', 'scout-members' ) );
		if ( $prepaid ) {
			/* translators: %d: order number. */
			Scout_Members::flag( $user_id, sprintf( __( 'Not accepted after paying with the application (order #%d). Refund it in WooCommerce.', 'scout-members' ), $prepaid ), $prepaid );
			self::back( 'declined_paid', '#sm-applications' );
		}
		self::back( 'declined', '#sm-applications' );
	}

	private static function welcome( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$next = trim( (string) get_option( 'scout_members_next_step', '' ) );
		/* translators: %s: site name. */
		$subject = sprintf( __( 'Welcome, you are a member of %s', 'scout-members' ), $site );
		$body    = __( 'Your membership application has been approved. Welcome.', 'scout-members' ) . "\n\n";
		$m       = Scout_Members::member( $user_id );
		$pay     = 'approved' === $m['status'] ? Scout_Members_Woo::pay_url( $user_id ) : '';
		if ( '' !== $pay ) {
			/* translators: %s: site name. */
			$subject = sprintf( __( 'You are approved: welcome to %s', 'scout-members' ), $site );
			$amount  = Scout_Members_Woo::amount( $user_id );
			$body   .= ( '' !== $amount
				/* translators: 1: plan name, 2: amount. */
				? sprintf( __( 'Pay your dues (%1$s, %2$s) to open members-only pages and events:', 'scout-members' ), Scout_Members::plans()[ $m['plan'] ]['name'] ?? '', Scout_Members::money( $amount ) )
				: __( 'Pay your dues to open members-only pages and events:', 'scout-members' ) ) . "\n" . $pay . "\n\n";
		} elseif ( 'active' === $m['status'] && '' !== $m['renews'] && '' !== $m['paid'] ) {
			/* translators: %s: renewal date. */
			$body .= sprintf( __( 'Your dues are paid. Your membership renews on %s.', 'scout-members' ), Scout_Members::date_label( $m['renews'] ) ) . "\n\n";
		} elseif ( '' !== $next ) {
			$body .= $next . "\n\n";
		}
		$body .= __( 'Sign in any time to see members-only pages and events:', 'scout-members' ) . "\n" . Scout_Members::page_url() . "\n\n"
			. __( 'If you have not set a password yet, set one here:', 'scout-members' ) . "\n" . wp_lostpassword_url( Scout_Members::page_url() );
		wp_mail( $user->user_email, $subject, $body );
	}

	/** Change a member's status, plan, renewal date or note, or record a payment. */
	public static function update(): void {
		$user_id = isset( $_POST['member'] ) ? absint( $_POST['member'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( 'scout_members_update_' . $user_id );
		if ( ! current_user_can( self::CAP ) || ! $user_id || ! get_userdata( $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to change members.', 'scout-members' ), 403 );
		}
		if ( ! empty( $_POST['paid'] ) ) {
			Scout_Members::paid( $user_id, '', __( 'Paid today, recorded by hand', 'scout-members' ) );
			if ( in_array( Scout_Members::member( $user_id )['status'], array( 'approved', 'ended' ), true ) ) {
				Scout_Members::set_status( $user_id, 'active' );
			}
			self::back( 'paid', '#sm-member-' . $user_id );
		}
		$before  = Scout_Members::member( $user_id );
		$changes = array(
			'plan'   => isset( $_POST['plan'] ) ? sanitize_title( wp_unslash( $_POST['plan'] ) ) : '',
			'renews' => isset( $_POST['renews'] ) ? sanitize_text_field( wp_unslash( $_POST['renews'] ) ) : '',
			'note'   => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
		);
		if ( isset( $_POST['comp'] ) ) {
			$changes['comp'] = sanitize_text_field( wp_unslash( $_POST['comp'] ) );
		}
		Scout_Members::save( $user_id, $changes );
		$after = Scout_Members::member( $user_id );
		if ( $before['comp'] !== $after['comp'] ) {
			Scout_Members::log(
				$user_id,
				'' === $after['comp']
					? __( 'Renewal price back to the plan price.', 'scout-members' )
					/* translators: %s: amount. */
					: sprintf( __( 'Renewal price set to %s by the owner.', 'scout-members' ), Scout_Members::money( $after['comp'] ) )
			);
		}
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		Scout_Members::set_status( $user_id, $status );
		self::back( 'saved', '#sm-member-' . $user_id );
	}

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change membership settings.', 'scout-members' ), 403 );
		}
		check_admin_referer( 'scout_members_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in save_settings().
		$in = isset( $_POST['scout_members'] ) ? (array) wp_unslash( $_POST['scout_members'] ) : array();
		Scout_Members::save_settings( $in );
		if ( Scout_Members_Woo::active() && isset( $in['products'] ) && is_array( $in['products'] ) ) {
			Scout_Members_Woo::save_links( $in['products'] );
		}
		update_option( 'scout_members_questions', sanitize_textarea_field( (string) ( $in['questions'] ?? '' ) ), false );
		update_option( 'scout_members_next_step', sanitize_textarea_field( (string) ( $in['next_step'] ?? '' ) ), false );
		update_option( Scout_Members_Invitations::OPTION, sanitize_textarea_field( (string) ( $in['invitation_questions'] ?? '' ) ), false );
		self::back( 'settings' );
	}

	public static function import(): void {
		if ( ! current_user_can( self::CAP ) || ! current_user_can( 'create_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to bring members in.', 'scout-members' ), 403 );
		}
		check_admin_referer( 'scout_members_import' );
		$file = $_FILES['sm_file']['tmp_name'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a temporary upload path, read as CSV only.
		if ( '' === $file || ! is_uploaded_file( $file ) ) {
			self::back( 'nofile' );
		}
		$report = Scout_Members_Import::run( $file, empty( $_POST['sm_apply'] ), ! empty( $_POST['sm_welcome'] ) );
		set_transient( 'scout_members_import_' . get_current_user_id(), $report, 10 * MINUTE_IN_SECONDS );
		self::back( 'imported' );
	}

	/* ---------- privacy: export and erase on request ---------- */

	public static function exporter( array $exporters ): array {
		$exporters['scout-members'] = array(
			'exporter_friendly_name' => __( 'Membership', 'scout-members' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function export_data( string $email ): array {
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( $user ) {
			$m = Scout_Members::member( $user->ID );
			if ( '' !== $m['status'] ) {
				$rows = array(
					array(
						'name'  => __( 'Status', 'scout-members' ),
						'value' => Scout_Members::statuses()[ $m['status'] ] ?? $m['status'],
					),
				);
				foreach ( array( 'plan', 'applied', 'since', 'renews', 'paid', 'comp' ) as $k ) {
					if ( '' !== $m[ $k ] ) {
						$rows[] = array(
							'name'  => ucfirst( $k ),
							'value' => $m[ $k ],
						);
					}
				}
				foreach ( $m['answers'] as $q => $a ) {
					$rows[] = array(
						'name'  => $q,
						'value' => $a,
					);
				}
				foreach ( Scout_Members::history( $user->ID ) as $line ) {
					$rows[] = array(
						'name'  => __( 'History', 'scout-members' ) . ' ' . $line['on'],
						'value' => $line['text'],
					);
				}
				$data[] = array(
					'group_id'    => 'scout-members',
					'group_label' => __( 'Membership', 'scout-members' ),
					'item_id'     => 'member-' . $user->ID,
					'data'        => $rows,
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	public static function eraser( array $erasers ): array {
		$erasers['scout-members'] = array(
			'eraser_friendly_name' => __( 'Membership', 'scout-members' ),
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	public static function erase_data( string $email ): array {
		$user    = get_user_by( 'email', $email );
		$removed = false;
		if ( $user && '' !== Scout_Members::member( $user->ID )['status'] ) {
			delete_user_meta( $user->ID, Scout_Members::META );
			delete_user_meta( $user->ID, Scout_Members::STATUS );
			delete_user_meta( $user->ID, Scout_Members::LOG );
			delete_user_meta( $user->ID, Scout_Members::REVIEW );
			$removed = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/* ---------- screens ---------- */

	public static function render_members(): void {
		self::render( 'members' );
	}

	public static function render_import(): void {
		self::render( 'import' );
	}

	public static function render_renewals(): void {
		self::render( 'renewals' );
	}

	public static function render_settings(): void {
		self::render( 'settings' );
	}

	public static function render( string $view = 'overview' ): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$view = in_array( $view, array( 'overview', 'members', 'renewals', 'import', 'settings' ), true ) ? $view : 'overview';
		// Each screen gets its own name (its menu label) and one sentence on what it is for.
		$heads = array(
			'overview' => array( __( 'Home', 'scout-members' ), __( 'Applications waiting for you, renewals coming up, and your members-only pages.', 'scout-members' ) ),
			'members'  => array( __( 'All members', 'scout-members' ), __( 'Everyone on your list. Change a status, plan or renewal date on a row, then press Save on that row.', 'scout-members' ) ),
			'renewals' => array( __( 'Renewals due', 'scout-members' ), __( 'Who renews in the next 30 days, who is past due, and any payment that needs a look from you.', 'scout-members' ) ),
			'import'   => array( __( 'Add from spreadsheet', 'scout-members' ), __( 'Add your current members from a spreadsheet, such as an export from Squarespace.', 'scout-members' ) ),
			'settings' => array( __( 'Settings', 'scout-members' ), __( 'Your membership pages, plans, dues, reminder emails and the questions people answer when they apply.', 'scout-members' ) ),
		);
		Scout_Admin_UI::open( $heads[ $view ][0], $heads[ $view ][1], __( 'Members', 'scout-members' ) );
		self::message();
		echo '<div class="sm-admin">';
		if ( 'members' === $view ) {
			self::view_members();
		} elseif ( 'renewals' === $view ) {
			self::view_renewals();
		} elseif ( 'import' === $view ) {
			self::view_import();
		} elseif ( 'settings' === $view ) {
			self::view_settings();
		} else {
			self::view_overview();
		}
		echo '</div>';
		Scout_Admin_UI::close();
	}

	private static function message(): void {
		$msg   = isset( $_GET['scout-members'] ) ? sanitize_key( wp_unslash( $_GET['scout-members'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$texts = array(
			'approved'      => __( 'Approved. They have a welcome email with a link to sign in.', 'scout-members' ),
			'approved_pay'  => __( 'Approved. Their welcome email has a Pay your dues link. Members-only pages open for them as soon as they pay.', 'scout-members' ),
			'declined'      => __( 'Marked not accepted. No email was sent, so reach out yourself if you want to.', 'scout-members' ),
			'declined_paid' => __( 'Marked not accepted. They paid with their application, so refund that order in WooCommerce. It is listed under Renewals until you do.', 'scout-members' ),
			'saved'         => __( 'Saved.', 'scout-members' ),
			'paid'          => __( 'Payment recorded. The renewal date moved forward one period.', 'scout-members' ),
			'settings'      => __( 'Settings saved.', 'scout-members' ),
			'nofile'        => __( 'Choose a CSV file first.', 'scout-members' ),
			'product'       => __( 'The plan has its product. Members pay it through the checkout.', 'scout-members' ),
			'noproduct'     => __( 'The product could not be made. Add it in Products, then choose it here.', 'scout-members' ),
			'noplan'        => __( 'That plan is no longer in Settings.', 'scout-members' ),
			'reviewed'      => __( 'Done. Nothing about the membership changed.', 'scout-members' ),
			'ended'         => __( 'The membership is ended.', 'scout-members' ),
			'cleared'       => __( 'Taken off the list. Record the payment on the member with Paid today if it was theirs.', 'scout-members' ),
		);
		if ( isset( $texts[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $texts[ $msg ] ) . '</p></div>';
		}
	}

	private static function view_overview(): void {
		$active  = Scout_Members::ids( 'active' );
		$current = array_filter( $active, static fn( $id ) => Scout_Members::is_member( $id ) );
		$applied = Scout_Members::ids( 'applied' );
		$due     = Scout_Members::renewals_due();
		$waiting = self::waiting();
		if ( $waiting ) {
			self::ui_status(
				'warn',
				/* translators: %d: number of things waiting. */
				sprintf( _n( '%d thing needs you.', '%d things need you.', count( $waiting ), 'scout-members' ), count( $waiting ) ),
				$waiting[0]['text'],
				'<a class="button" href="' . esc_url( $waiting[0]['url'] ) . '">' . esc_html( $waiting[0]['action'] ) . '</a>'
			);
		} else {
			self::ui_status( 'good', __( 'All caught up.', 'scout-members' ), __( 'No applications to read, no payments to review and no renewals in the next 30 days.', 'scout-members' ) );
		}
		?>
		<section class="sh-box">
			<div class="sh-stats">
				<div class="sh-stat"><p class="sh-num"><?php echo (int) count( $current ); ?></p><p class="sh-note"><?php esc_html_e( 'members', 'scout-members' ); ?></p></div>
				<div class="sh-stat"><p class="sh-num"><?php echo (int) count( $applied ); ?></p><p class="sh-note"><a href="#sm-applications"><?php esc_html_e( 'applications to read', 'scout-members' ); ?> &rsaquo;</a></p></div>
				<div class="sh-stat"><p class="sh-num"><?php echo (int) count( $due ); ?></p><p class="sh-note"><a href="#sm-renewals"><?php esc_html_e( 'renewals in the next 30 days', 'scout-members' ); ?> &rsaquo;</a></p></div>
				<div class="sh-stat"><p class="sh-num"><?php echo (int) count( Scout_Members::plans() ); ?></p><p class="sh-note"><a href="<?php echo esc_url( self::url( 'settings' ) ); ?>"><?php esc_html_e( 'plans', 'scout-members' ); ?> &rsaquo;</a></p></div>
			</div>
		</section>

		<?php if ( ! Scout_Members::plans() || ! Scout_Members::settings()['page'] ) : ?>
			<section class="sh-box sm-setup">
				<h2 class="sh-title"><?php esc_html_e( 'Two steps to open the membership', 'scout-members' ); ?></h2>
				<ol>
					<?php if ( ! Scout_Members::settings()['page'] ) : ?>
						<li><?php esc_html_e( 'Make a Membership page and put [scout_membership] on it. It shows the application to new people and the account to members.', 'scout-members' ); ?></li>
					<?php endif; ?>
					<?php if ( ! Scout_Members::plans() ) : ?>
						<li><?php esc_html_e( 'Add your plans and application questions under Settings.', 'scout-members' ); ?> <a href="<?php echo esc_url( self::url( 'settings' ) ); ?>"><?php esc_html_e( 'Open Settings', 'scout-members' ); ?></a></li>
					<?php endif; ?>
				</ol>
			</section>
		<?php endif; ?>

		<section class="sh-box" id="sm-applications">
			<h2 class="sh-title"><?php esc_html_e( 'Applications to read', 'scout-members' ); ?></h2>
			<?php if ( $applied ) : ?>
				<ul class="sm-apps">
					<?php foreach ( $applied as $id ) : ?>
						<?php self::application( $id ); ?>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="sm-allclear"><?php esc_html_e( 'No applications waiting. New ones show up here and in Events, Needs you.', 'scout-members' ); ?></p>
			<?php endif; ?>
		</section>

		<section class="sh-box" id="sm-renewals">
			<div class="sm-box-head">
				<h2 class="sh-title"><?php esc_html_e( 'Renewing soon', 'scout-members' ); ?></h2>
				<a class="button" href="<?php echo esc_url( self::url( 'renewals' ) ); ?>"><?php esc_html_e( 'Renewals and payments', 'scout-members' ); ?></a>
			</div>
			<?php if ( $due ) : ?>
				<?php self::table( $due ); ?>
			<?php else : ?>
				<p class="sm-allclear"><?php esc_html_e( 'No memberships renew in the next 30 days.', 'scout-members' ); ?></p>
			<?php endif; ?>
		</section>

		<?php self::area(); ?>
		<?php
		unset( $waiting );
	}

	/**
	 * What members can open: the members home, each page under it, and any
	 * other members-only page, with who each one is for. Kai's map of the
	 * members area, and the place to start a new members page.
	 */
	private static function area(): void {
		$home = Scout_Members::home_id();
		$ids  = Scout_Members::locked_ids();
		?>
		<section class="sh-box" id="sm-area">
			<div class="sm-box-head">
				<h2 class="sh-title"><?php esc_html_e( 'The members area', 'scout-members' ); ?></h2>
				<?php if ( $home ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=page&sm_parent=' . $home ) ); ?>"><?php esc_html_e( 'Add a members page', 'scout-members' ); ?></a>
				<?php endif; ?>
			</div>
			<?php if ( ! $home ) : ?>
				<p><?php esc_html_e( 'Choose a members home in Settings. It is the page members see after signing in.', 'scout-members' ); ?> <a href="<?php echo esc_url( self::url( 'settings' ) ); ?>"><?php esc_html_e( 'Open Settings', 'scout-members' ); ?></a></p>
			<?php endif; ?>
			<?php if ( $ids ) : ?>
				<table class="sh-table sm-area">
					<thead><tr><th><?php esc_html_e( 'Page', 'scout-members' ); ?></th><th><?php esc_html_e( 'Who can open it', 'scout-members' ); ?></th><th></th></tr></thead>
					<tbody>
						<?php foreach ( $ids as $id ) : ?>
							<?php $plans = Scout_Members::plans_for( $id ); ?>
							<tr>
								<td><?php echo $id === $home ? '<strong>' . esc_html( get_the_title( $id ) ) . '</strong> <span class="sh-note">' . esc_html__( '(members home)', 'scout-members' ) . '</span>' : esc_html( ( wp_get_post_parent_id( $id ) ? '↳ ' : '' ) . get_the_title( $id ) ); ?></td>
								<td><?php echo esc_html( $plans ? Scout_Members::plan_names( $plans ) : __( 'Every member', 'scout-members' ) ); ?></td>
								<td><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'scout-members' ); ?></a> &middot; <a href="<?php echo esc_url( (string) get_permalink( $id ) ); ?>"><?php esc_html_e( 'View', 'scout-members' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php elseif ( $home ) : ?>
				<p class="sm-allclear"><?php esc_html_e( 'No members pages yet. Add one and it shows up on the members home.', 'scout-members' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/** "Add a members page" starts a page with the members home as its parent. */
	public static function new_page_parent( $data, $postarr ) {
		$parent = isset( $_GET['sm_parent'] ) ? absint( $_GET['sm_parent'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only sets the default parent of a new draft.
		if ( $parent && 'page' === ( $data['post_type'] ?? '' ) && 'auto-draft' === ( $data['post_status'] ?? '' ) && empty( $postarr['ID'] ) && Scout_Members::home_id() === $parent && current_user_can( 'edit_pages' ) ) {
			$data['post_parent'] = $parent;
		}
		return $data;
	}

	/** One application: who, when, their answers, and the decision. */
	private static function application( int $id ): void {
		$user = get_userdata( $id );
		if ( ! $user ) {
			return;
		}
		$m     = Scout_Members::member( $id );
		$plans = Scout_Members::plans();
		?>
		<li class="sm-app">
			<details>
				<summary>
					<span class="sm-app-name"><?php echo esc_html( $user->display_name ); ?></span>
					<span class="sm-app-meta">
						<?php
						/* translators: %s: date applied. */
						echo esc_html( sprintf( __( 'Applied %s', 'scout-members' ), Scout_Members::date_label( $m['applied'] ) ) );
						if ( '' !== $m['plan'] && '' !== Scout_Members::plan_label( $m['plan'] ) ) {
							echo ' &middot; ' . esc_html( Scout_Members::plan_label( $m['plan'] ) );
						}
						?>
					</span>
					<span class="sm-app-open"><?php esc_html_e( 'Read', 'scout-members' ); ?></span>
				</summary>
				<dl class="sm-answers">
					<div><dt><?php esc_html_e( 'Email', 'scout-members' ); ?></dt><dd><a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a></dd></div>
					<?php foreach ( $m['answers'] as $q => $a ) : ?>
						<div><dt><?php echo esc_html( $q ); ?></dt><dd><?php echo '' !== $a ? nl2br( esc_html( $a ) ) : '<span class="sm-none">' . esc_html__( 'No answer', 'scout-members' ) . '</span>'; ?></dd></div>
					<?php endforeach; ?>
				</dl>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sm-decide">
					<input type="hidden" name="action" value="scout_members_decide">
					<input type="hidden" name="member" value="<?php echo (int) $id; ?>">
					<?php wp_nonce_field( 'scout_members_decide_' . $id ); ?>
					<?php if ( $plans ) : ?>
						<label><?php esc_html_e( 'Plan', 'scout-members' ); ?>
							<select name="plan">
								<?php foreach ( $plans as $slug => $p ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $m['plan'], $slug ); ?>><?php echo esc_html( $p['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>
					<button type="submit" name="approve" value="1" class="button"><?php esc_html_e( 'Approve and welcome', 'scout-members' ); ?></button>
					<button type="submit" name="decline" value="1" class="button sh-quiet"><?php esc_html_e( 'Not accepted', 'scout-members' ); ?></button>
				</form>
				<?php if ( $m['prepaid'] ) : ?>
					<?php /* translators: %d: order number. */ ?>
					<p class="sm-chip is-good"><?php echo esc_html( sprintf( __( 'Dues paid with the application (order #%d). Approving starts their year today.', 'scout-members' ), $m['prepaid'] ) ); ?></p>
				<?php endif; ?>
				<p class="sh-note"><?php echo esc_html( Scout_Members_Woo::active() && Scout_Members_Woo::links() ? __( 'Approve sends a welcome email, with a Pay your dues link when the plan has dues. Not accepted sends nothing.', 'scout-members' ) : __( 'Approving sends a welcome email with how to sign in, and your next-step note from Settings. Not accepted sends nothing.', 'scout-members' ) ); ?></p>
			</details>
		</li>
		<?php
	}

	private static function view_members(): void {
		$ids = Scout_Members::ids( array( 'approved', 'active', 'paused', 'ended', 'declined' ) );
		if ( $ids ) {
			$current = count( array_filter( $ids, static fn( $id ) => Scout_Members::is_member( $id ) ) );
			self::ui_status(
				'good',
				/* translators: %d: number of members. */
				sprintf( _n( '%d current member.', '%d current members.', $current, 'scout-members' ), $current ),
				/* translators: %d: number of people. */
				sprintf( _n( '%d person on this list in all, counting past members and people not accepted.', '%d people on this list in all, counting past members and people not accepted.', count( $ids ), 'scout-members' ), count( $ids ) )
			);
		} else {
			self::ui_status( 'off', __( 'No members yet.', 'scout-members' ), __( 'Approve an application, or bring your current members in from a spreadsheet.', 'scout-members' ) );
		}
		?>
		<section class="sh-box">
			<div class="sm-box-head">
				<h2 class="sh-title"><?php esc_html_e( 'Status, plan and renewal date', 'scout-members' ); ?></h2>
				<a class="button" href="<?php echo esc_url( self::url( 'import' ) ); ?>"><?php esc_html_e( 'Add from spreadsheet', 'scout-members' ); ?></a>
			</div>
			<?php if ( $ids ) : ?>
				<?php self::table( $ids ); ?>
			<?php else : ?>
				<p class="sm-allclear"><?php esc_html_e( 'No members yet. Approve an application, or bring your current members in from a spreadsheet.', 'scout-members' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/** Members with their status, plan and renewal date, each editable in place. */
	private static function table( array $ids ): void {
		$plans = Scout_Members::plans();
		?>
		<div class="sm-table-wrap" role="region" aria-label="<?php esc_attr_e( 'Members', 'scout-members' ); ?>" tabindex="0">
			<table class="widefat sm-table sm-members-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Member', 'scout-members' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'scout-members' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Plan', 'scout-members' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Renews', 'scout-members' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Save', 'scout-members' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $ids as $id ) :
						$user = get_userdata( $id );
						if ( ! $user ) {
							continue;
						}
						$m    = Scout_Members::member( $id );
						$form = 'sm-f-' . $id;
						$late  = 'active' === $m['status'] && '' !== $m['renews'] && $m['renews'] < Scout_Members::today();
						$grace = $late && Scout_Members::in_grace( $id );
						?>
						<tr id="sm-member-<?php echo (int) $id; ?>">
							<td>
								<strong><?php echo esc_html( $user->display_name ); ?></strong><br>
								<span class="sh-note"><?php echo esc_html( $user->user_email ); ?></span>
								<?php if ( '' !== $m['since'] ) : ?>
									<?php /* translators: %s: date. */ ?>
									<br><span class="sh-note"><?php echo esc_html( sprintf( __( 'Since %s', 'scout-members' ), Scout_Members::date_label( $m['since'] ) ) ); ?></span>
								<?php endif; ?>
								<form id="<?php echo esc_attr( $form ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="scout_members_update">
									<input type="hidden" name="member" value="<?php echo (int) $id; ?>">
									<?php wp_nonce_field( 'scout_members_update_' . $id ); ?>
								</form>
							</td>
							<td>
								<label class="screen-reader-text" for="sm-s-<?php echo (int) $id; ?>"><?php esc_html_e( 'Status', 'scout-members' ); ?></label>
								<select id="sm-s-<?php echo (int) $id; ?>" name="status" form="<?php echo esc_attr( $form ); ?>">
									<?php foreach ( Scout_Members::statuses() as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $m['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<?php if ( $grace ) : ?>
									<?php /* translators: %s: date. */ ?>
									<br><span class="sm-chip is-warn"><?php echo esc_html( sprintf( __( 'Past due, open until %s', 'scout-members' ), Scout_Members::date_label( Scout_Members::access_until( $id ) ) ) ); ?></span>
								<?php elseif ( $late ) : ?>
									<br><span class="sm-chip is-warn"><?php esc_html_e( 'Lapsed', 'scout-members' ); ?></span>
								<?php endif; ?>
								<?php if ( Scout_Members::flagged( $id ) ) : ?>
									<br><a class="sm-chip is-warn" href="<?php echo esc_url( self::url( 'renewals' ) . '#sm-review' ); ?>"><?php esc_html_e( 'Payment to review', 'scout-members' ); ?></a>
								<?php endif; ?>
							</td>
							<td>
								<label class="screen-reader-text" for="sm-p-<?php echo (int) $id; ?>"><?php esc_html_e( 'Plan', 'scout-members' ); ?></label>
								<select id="sm-p-<?php echo (int) $id; ?>" name="plan" form="<?php echo esc_attr( $form ); ?>">
									<option value=""><?php esc_html_e( 'No plan', 'scout-members' ); ?></option>
									<?php foreach ( $plans as $slug => $p ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $m['plan'], $slug ); ?>><?php echo esc_html( $p['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<label class="sm-comp" for="sm-c-<?php echo (int) $id; ?>"><?php esc_html_e( 'Renewal price', 'scout-members' ); ?></label>
								<input type="text" inputmode="decimal" class="sm-comp-input" id="sm-c-<?php echo (int) $id; ?>" name="comp" value="<?php echo esc_attr( $m['comp'] ); ?>" placeholder="<?php echo esc_attr( '' !== ( $plans[ $m['plan'] ]['price'] ?? '' ) ? Scout_Members::money( $plans[ $m['plan'] ]['price'] ) : __( 'plan price', 'scout-members' ) ); ?>" form="<?php echo esc_attr( $form ); ?>">
								<?php if ( '' !== $m['comp'] ) : ?>
									<br><span class="sm-chip"><?php echo esc_html( 0.0 === (float) $m['comp'] ? __( 'Comped: renews free', 'scout-members' ) : sprintf( /* translators: %s: amount. */ __( 'Comped: renews at %s', 'scout-members' ), Scout_Members::money( $m['comp'] ) ) ); ?></span>
								<?php elseif ( $m['free_join'] ) : ?>
									<br><span class="sm-chip"><?php esc_html_e( 'Joined on a $0 order', 'scout-members' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<label class="screen-reader-text" for="sm-r-<?php echo (int) $id; ?>"><?php esc_html_e( 'Renews', 'scout-members' ); ?></label>
								<input type="date" id="sm-r-<?php echo (int) $id; ?>" name="renews" value="<?php echo esc_attr( $m['renews'] ); ?>" form="<?php echo esc_attr( $form ); ?>">
								<?php if ( '' !== $m['paid'] ) : ?>
									<?php /* translators: %s: date. */ ?>
									<br><span class="sh-note"><?php echo esc_html( sprintf( __( 'Last paid %s', 'scout-members' ), Scout_Members::date_label( $m['paid'] ) ) ); ?></span>
								<?php endif; ?>
							</td>
							<td class="sm-row-actions">
								<button type="submit" class="button" form="<?php echo esc_attr( $form ); ?>"><?php esc_html_e( 'Save', 'scout-members' ); ?></button>
								<button type="submit" class="button sh-quiet" name="paid" value="1" form="<?php echo esc_attr( $form ); ?>"><?php esc_html_e( 'Paid today', 'scout-members' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="sh-note"><?php echo esc_html( Scout_Members_Woo::active() ? __( 'Paid today records cash or a check and moves the renewal date forward. Checkout payments record themselves.', 'scout-members' ) : __( 'Paid today records a payment and moves the renewal date forward. Renewal price: blank uses the plan price.', 'scout-members' ) ); ?></p>
		<?php
	}

	/** Where a member's renewal stands, in a few words, with how loud to say it. */
	private static function renewal_state( int $id ): array {
		$m     = Scout_Members::member( $id );
		$today = Scout_Members::today();
		if ( 'approved' === $m['status'] ) {
			return array( __( 'Approved, dues not paid yet', 'scout-members' ), 'is-warn' );
		}
		if ( '' !== $m['paid'] && $m['paid'] >= gmdate( 'Y-m-d', strtotime( $today . ' -30 days' ) ) && ( '' === $m['renews'] || $m['renews'] > $today ) ) {
			/* translators: %s: date paid. */
			return array( sprintf( __( 'Paid %s', 'scout-members' ), Scout_Members::date_label( $m['paid'] ) ), 'is-good' );
		}
		if ( '' === $m['renews'] ) {
			return array( '', '' );
		}
		if ( $m['renews'] >= $today ) {
			$days = (int) round( ( strtotime( $m['renews'] . ' 12:00:00' ) - strtotime( $today . ' 12:00:00' ) ) / DAY_IN_SECONDS );
			/* translators: %d: days. */
			return array( 0 === $days ? __( 'Renews today', 'scout-members' ) : sprintf( _n( 'Due in %d day', 'Due in %d days', $days, 'scout-members' ), $days ), '' );
		}
		if ( Scout_Members::in_grace( $id ) ) {
			/* translators: %s: date. */
			return array( sprintf( __( 'Past due, open until %s', 'scout-members' ), Scout_Members::date_label( Scout_Members::access_until( $id ) ) ), 'is-warn' );
		}
		return array( __( 'Lapsed, access closed', 'scout-members' ), 'is-warn' );
	}

	/**
	 * Renewals due: everyone renewing in the next 30 days, past due or just
	 * lapsed, approved members who have not paid, and who paid in the last 30
	 * days. Payments to review sit on top.
	 */
	private static function view_renewals(): void {
		$review = self::to_review();
		$ids    = Scout_Members_Renewals::list_ids();
		$woo    = Scout_Members_Woo::active();
		$s      = Scout_Members::settings();
		$due    = count( Scout_Members::renewals_due() );
		$remind = 'off' === $s['reminders'] ? __( 'Reminder emails are off in Settings.', 'scout-members' ) : __( 'Reminder emails are on.', 'scout-members' );
		if ( $review ) {
			self::ui_status(
				'warn',
				/* translators: %d: number of payments. */
				sprintf( _n( '%d payment to review.', '%d payments to review.', count( $review ), 'scout-members' ), count( $review ) ),
				__( 'A refund, or a payment nobody matched. Nothing here changed anyone\'s access.', 'scout-members' ),
				'<a class="button" href="#sm-review">' . esc_html__( 'Review payments', 'scout-members' ) . '</a>'
			);
		} elseif ( $due ) {
			self::ui_status(
				'good',
				/* translators: %d: number of members. */
				sprintf( _n( '%d membership renews within 30 days or is past due.', '%d memberships renew within 30 days or are past due.', $due, 'scout-members' ), $due ),
				$remind
			);
		} else {
			self::ui_status( 'good', __( 'No renewals due.', 'scout-members' ), __( 'Nobody renews in the next 30 days and nobody is past due.', 'scout-members' ) . ' ' . $remind );
		}
		?>
		<?php if ( $review ) : ?>
			<section class="sh-box sm-review" id="sm-review">
				<h2 class="sh-title"><?php esc_html_e( 'Payments to review', 'scout-members' ); ?></h2>
				<p><?php esc_html_e( 'Nothing here changed anyone\'s access. Look at each one and decide.', 'scout-members' ); ?></p>
				<ul class="sm-review-list">
					<?php foreach ( $review as $r ) : ?>
						<?php $user = $r['member'] ? get_userdata( $r['member'] ) : null; ?>
						<li>
							<p>
								<?php if ( $user ) : ?>
									<strong><?php echo esc_html( $user->display_name ); ?></strong> <span class="sh-note"><?php echo esc_html( $user->user_email ); ?></span><br>
								<?php endif; ?>
								<?php echo esc_html( $r['reason'] ); ?>
								<?php if ( '' !== $r['on'] ) : ?>
									<span class="sh-note">(<?php echo esc_html( Scout_Members::date_label( $r['on'] ) ); ?>)</span>
								<?php endif; ?>
							</p>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sm-decide">
								<input type="hidden" name="action" value="scout_members_review">
								<input type="hidden" name="member" value="<?php echo (int) $r['member']; ?>">
								<input type="hidden" name="order" value="<?php echo (int) $r['order']; ?>">
								<?php wp_nonce_field( 'scout_members_review_' . (int) $r['member'] . '_' . (int) $r['order'] ); ?>
								<?php if ( $r['order'] && $woo ) : ?>
									<a class="button sh-quiet" href="<?php echo esc_url( self::order_link( $r['order'] ) ); ?>"><?php esc_html_e( 'Open the order', 'scout-members' ); ?></a>
								<?php endif; ?>
								<?php if ( $user && ! in_array( Scout_Members::member( $r['member'] )['status'], array( 'active', 'approved', 'paused' ), true ) ) : ?>
									<button type="submit" class="button"><?php esc_html_e( 'Done, take it off the list', 'scout-members' ); ?></button>
								<?php elseif ( $user ) : ?>
									<button type="submit" class="button"><?php esc_html_e( 'Keep the membership', 'scout-members' ); ?></button>
									<button type="submit" name="end" value="1" class="button sh-quiet"><?php esc_html_e( 'End the membership', 'scout-members' ); ?></button>
								<?php else : ?>
									<button type="submit" class="button"><?php esc_html_e( 'Done, take it off the list', 'scout-members' ); ?></button>
								<?php endif; ?>
							</form>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<section class="sh-box" id="sm-renewals-due">
			<div class="sm-box-head">
				<h2 class="sh-title"><?php esc_html_e( 'Renewals due', 'scout-members' ); ?></h2>
				<a class="button" href="<?php echo esc_url( self::url( 'members' ) ); ?>"><?php esc_html_e( 'All members', 'scout-members' ); ?></a>
			</div>
			<p>
				<?php
				if ( 'off' === $s['reminders'] ) {
					echo '<strong>' . esc_html__( 'Reminder emails are off in Settings.', 'scout-members' ) . '</strong> ';
				} elseif ( 'all' === $s['reminders'] ) {
					echo esc_html__( 'Every member gets a reminder 30 days (yearly plans) and 7 days before renewal.', 'scout-members' ) . ' ';
				} else {
					echo esc_html__( 'Members on plans with dues get a Renew now reminder 30 days (yearly plans) and 7 days before renewal.', 'scout-members' ) . ' ';
				}
				echo esc_html( $woo ? __( 'Members renew with a fresh checkout; no cards are saved.', 'scout-members' ) : __( 'Record payments with Paid today on All members.', 'scout-members' ) );
				if ( $s['grace'] > 0 ) {
					/* translators: %d: days. */
					echo ' ' . esc_html( sprintf( _n( 'Members keep access for %d day after their renewal date.', 'Members keep access for %d days after their renewal date.', $s['grace'], 'scout-members' ), $s['grace'] ) );
				} else {
					echo ' ' . esc_html__( 'Access closes the day after the renewal date (no grace period).', 'scout-members' );
				}
				?>
			</p>
			<?php if ( $ids ) : ?>
				<div class="sm-table-wrap" role="region" aria-label="<?php esc_attr_e( 'Renewals due', 'scout-members' ); ?>" tabindex="0">
					<table class="widefat sm-table sm-renewals">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Member', 'scout-members' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Renewal', 'scout-members' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Dues', 'scout-members' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Latest', 'scout-members' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $ids as $id ) :
								$user = get_userdata( $id );
								if ( ! $user ) {
									continue;
								}
								$m     = Scout_Members::member( $id );
								$state = self::renewal_state( $id );
								$dues  = $woo ? Scout_Members_Woo::amount( $id ) : Scout_Members::dues( $id );
								$log   = Scout_Members::history( $id );
								$last  = $log ? end( $log ) : null;
								?>
								<tr>
									<td><strong><?php echo esc_html( $user->display_name ); ?></strong><br><span class="sh-note"><?php echo esc_html( $user->user_email ); ?></span></td>
									<td>
										<?php if ( '' !== $m['renews'] ) : ?>
											<?php echo esc_html( Scout_Members::date_label( $m['renews'] ) ); ?><br>
										<?php endif; ?>
										<?php if ( '' !== $state[0] ) : ?>
											<span class="sm-chip <?php echo esc_attr( $state[1] ); ?>"><?php echo esc_html( $state[0] ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php echo esc_html( '' !== $m['plan'] ? ( Scout_Members::plans()[ $m['plan'] ]['name'] ?? '' ) : __( 'No plan', 'scout-members' ) ); ?>
										<?php if ( '' !== $dues ) : ?>
											<br><span class="sh-note"><?php echo esc_html( 0.0 === (float) $dues ? __( 'Free (comped)', 'scout-members' ) : Scout_Members::money( $dues ) . ( '' !== $m['comp'] ? ' ' . __( '(comped)', 'scout-members' ) : '' ) ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $last ) : ?>
											<?php echo esc_html( $last['text'] ); ?><br><span class="sh-note"><?php echo esc_html( Scout_Members::date_label( $last['on'] ) ); ?></span>
										<?php else : ?>
											<span class="sh-note"><?php esc_html_e( 'Nothing yet', 'scout-members' ); ?></span>
										<?php endif; ?>
										<?php if ( ! empty( $m['reminded']['7'] ) && $m['reminded']['7'] === $m['renews'] ) : ?>
											<br><span class="sh-note"><?php esc_html_e( '7-day reminder sent', 'scout-members' ); ?></span>
										<?php elseif ( ! empty( $m['reminded']['30'] ) && $m['reminded']['30'] === $m['renews'] ) : ?>
											<br><span class="sh-note"><?php esc_html_e( '30-day reminder sent', 'scout-members' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<p class="sm-allclear"><?php esc_html_e( 'No renewals in the next 30 days, nobody past due, and no payments in the last 30 days.', 'scout-members' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/** The order's edit screen, wherever WooCommerce keeps orders. */
	private static function order_link( int $order_id ): string {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		return $order ? (string) $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	private static function view_import(): void {
		$report = get_transient( 'scout_members_import_' . get_current_user_id() );
		?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Choose your file', 'scout-members' ); ?></h2>
			<p><?php esc_html_e( 'Moving from another system, such as Squarespace? Save your members as a CSV file and add it here. Each person becomes an active member on their plan.', 'scout-members' ); ?></p>
			<?php if ( is_array( $report ) ) : ?>
				<?php delete_transient( 'scout_members_import_' . get_current_user_id() ); ?>
				<div class="sm-report">
					<p><strong><?php echo esc_html( $report['dry'] ? __( 'Preview. Nothing has changed yet.', 'scout-members' ) : __( 'Done. Your file was added.', 'scout-members' ) ); ?></strong></p>
					<?php /* translators: 1: new members, 2: updated members. */ ?>
					<p><?php echo esc_html( sprintf( $report['dry'] ? __( '%1$d new and %2$d updated members.', 'scout-members' ) : __( '%1$d added and %2$d updated members.', 'scout-members' ), $report['created'], $report['updated'] ) ); ?></p>
					<?php if ( ! empty( $report['free'] ) ) : ?>
						<?php /* translators: %d: number of members. */ ?>
						<p><?php echo esc_html( sprintf( _n( '%d of them joined on a $0 order. Set a renewal price for each on All members, or leave it blank for the plan price.', '%d of them joined on a $0 order. Set a renewal price for each on All members, or leave it blank for the plan price.', $report['free'], 'scout-members' ), $report['free'] ) ); ?></p>
					<?php endif; ?>
					<?php if ( $report['notes'] ) : ?>
						<ul>
							<?php foreach ( $report['notes'] as $n ) : ?>
								<li><?php echo esc_html( $n ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( $report['dry'] ) : ?>
						<p><?php esc_html_e( 'If that looks right, choose the same file again, tick "Add them now", and press Add from spreadsheet.', 'scout-members' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sm-import">
				<input type="hidden" name="action" value="scout_members_import">
				<?php wp_nonce_field( 'scout_members_import' ); ?>
				<p class="sm-field"><label for="sm-file"><?php esc_html_e( 'Members file (CSV)', 'scout-members' ); ?></label><input type="file" id="sm-file" name="sm_file" accept=".csv,text/csv"></p>
				<p class="sm-field"><label><input type="checkbox" name="sm_welcome" value="1"> <?php esc_html_e( 'Email each new member a link to set their password', 'scout-members' ); ?></label><span class="sh-note"><?php esc_html_e( 'Leave unticked to add them quietly and invite them later.', 'scout-members' ); ?></span></p>
				<p class="sm-field"><label><input type="checkbox" id="sm-apply" name="sm_apply" value="1"> <?php esc_html_e( 'Add them now (leave unticked to preview)', 'scout-members' ); ?></label></p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add from spreadsheet', 'scout-members' ); ?></button></p>
			</form>
			<p class="sh-note"><?php esc_html_e( 'Columns used: Email, Name (or First Name and Last Name), Plan, Date, Total, and Renews if you have it.', 'scout-members' ); ?></p>
		</section>
		<?php
	}

	/**
	 * Dues and renewals, inside the settings form: the grace period and
	 * reminders always; with WooCommerce, when people pay, the receipt name and
	 * each plan's product.
	 */
	private static function dues_settings( array $s ): void {
		$plans = Scout_Members::plans();
		$woo   = Scout_Members_Woo::active();
		?>
		<section class="sh-box sm-dues" id="sm-dues">
			<h2 class="sh-title"><?php esc_html_e( 'Dues and renewals', 'scout-members' ); ?></h2>
			<?php if ( $woo ) : ?>
				<p class="sm-field">
					<span class="sm-label"><?php esc_html_e( 'When new members pay', 'scout-members' ); ?></span>
					<label class="sm-radio"><input type="radio" name="scout_members[pay_when]" value="approval" <?php checked( $s['pay_when'], 'approval' ); ?>> <?php esc_html_e( 'After you approve them (the welcome email has a Pay your dues link)', 'scout-members' ); ?></label>
					<label class="sm-radio"><input type="radio" name="scout_members[pay_when]" value="apply" <?php checked( $s['pay_when'], 'apply' ); ?>> <?php esc_html_e( 'With the application (the year starts the day you approve them; refund anyone you do not accept)', 'scout-members' ); ?></label>
				</p>
				<p class="sm-field">
					<label for="sm-set-receipt"><?php esc_html_e( 'Name on receipts', 'scout-members' ); ?></label>
					<input type="text" id="sm-set-receipt" name="scout_members[receipt]" value="<?php echo esc_attr( $s['receipt'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( Scout_Members::receipt_name() ); ?>">
					<span class="sh-note"><?php esc_html_e( 'New dues products start with these words, then the plan and its length. Older products keep their names.', 'scout-members' ); ?></span>
				</p>
				<?php if ( $plans ) : ?>
					<?php
					$products = wc_get_products(
						array(
							'type'    => 'simple',
							'status'  => array( 'publish', 'private', 'draft' ),
							'limit'   => 300,
							'orderby' => 'title',
							'order'   => 'ASC',
						)
					);
					$links    = Scout_Members_Woo::links();
					?>
					<div class="sm-table-wrap">
						<table class="widefat sm-table sm-plan-products">
							<thead><tr><th scope="col"><?php esc_html_e( 'Plan', 'scout-members' ); ?></th><th scope="col"><?php esc_html_e( 'Paid with this product', 'scout-members' ); ?></th></tr></thead>
							<tbody>
								<?php foreach ( $plans as $slug => $plan ) : ?>
									<?php
									$linked = $links[ $slug ] ?? 0;
									$prod   = $linked ? wc_get_product( $linked ) : null;
									?>
									<tr>
										<td><strong><?php echo esc_html( $plan['name'] ); ?></strong><br><span class="sh-note"><?php echo esc_html( Scout_Members::plan_label( $slug ) ); ?></span></td>
										<td>
											<label class="screen-reader-text" for="sm-prod-<?php echo esc_attr( $slug ); ?>"><?php esc_html_e( 'Product', 'scout-members' ); ?></label>
											<select id="sm-prod-<?php echo esc_attr( $slug ); ?>" name="scout_members[products][<?php echo esc_attr( $slug ); ?>]">
												<option value="0"><?php esc_html_e( 'None: record payments by hand', 'scout-members' ); ?></option>
												<?php foreach ( $products as $p ) : ?>
													<option value="<?php echo (int) $p->get_id(); ?>" <?php selected( $linked, $p->get_id() ); ?>><?php echo esc_html( $p->get_name() . ' (' . wp_strip_all_tags( wc_price( (float) $p->get_price() ) ) . ')' ); ?></option>
												<?php endforeach; ?>
											</select>
											<?php if ( $prod ) : ?>
												<br><a href="<?php echo esc_url( (string) get_edit_post_link( $prod->get_id() ) ); ?>"><?php esc_html_e( 'Edit the product', 'scout-members' ); ?></a>
												<?php if ( 'publish' !== $prod->get_status() || ! $prod->is_purchasable() ) : ?>
													<br><span class="sm-chip is-warn"><?php esc_html_e( 'Not for sale yet: publish it and give it a price', 'scout-members' ); ?></span>
												<?php elseif ( '' !== $plan['price'] && abs( (float) $prod->get_price() - (float) $plan['price'] ) > 0.001 ) : ?>
													<?php /* translators: 1: product price, 2: plan price. */ ?>
													<br><span class="sm-chip is-warn"><?php echo esc_html( sprintf( __( 'The checkout charges %1$s; the plan says %2$s', 'scout-members' ), Scout_Members::money( $prod->get_price() ), Scout_Members::money( $plan['price'] ) ) ); ?></span>
												<?php endif; ?>
											<?php else : ?>
												<br><button type="submit" class="button" form="sm-make-<?php echo esc_attr( $slug ); ?>"><?php esc_html_e( 'Create the product for this plan', 'scout-members' ); ?></button>
												<?php /* translators: %s: product name. */ ?>
												<span class="sh-note"><?php echo esc_html( sprintf( __( 'Makes "%s": virtual, hidden from the shop, at the plan price.', 'scout-members' ), Scout_Members_Woo::product_name( $slug ) ) ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<p class="sh-note"><?php esc_html_e( 'Members pay with the shop\'s payment methods. Paying makes them active and moves the renewal date forward.', 'scout-members' ); ?></p>
				<?php else : ?>
					<p class="sh-note"><?php esc_html_e( 'Add your plans above and save, then link each one to a product here.', 'scout-members' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<p class="sh-note"><?php esc_html_e( 'Online dues need WooCommerce. Until then, record payments with Paid today on All members.', 'scout-members' ); ?></p>
			<?php endif; ?>
			<p class="sm-field">
				<label for="sm-set-grace"><?php esc_html_e( 'Grace period (days)', 'scout-members' ); ?></label>
				<input type="number" id="sm-set-grace" name="scout_members[grace]" value="<?php echo (int) $s['grace']; ?>" min="0" max="90" class="small-text">
				<span class="sh-note"><?php esc_html_e( 'Days members keep access after their renewal date. 0 closes it the next day.', 'scout-members' ); ?></span>
			</p>
			<p class="sm-field">
				<span class="sm-label"><?php esc_html_e( 'Renewal reminders (30 days before for yearly plans, and 7 days before)', 'scout-members' ); ?></span>
				<label class="sm-radio"><input type="radio" name="scout_members[reminders]" value="checkout" <?php checked( $s['reminders'], 'checkout' ); ?>> <?php esc_html_e( 'To members whose plan has a dues product, with a Renew now link', 'scout-members' ); ?></label>
				<label class="sm-radio"><input type="radio" name="scout_members[reminders]" value="all" <?php checked( $s['reminders'], 'all' ); ?>> <?php esc_html_e( 'To every member (without a dues product, the email carries your next-step note)', 'scout-members' ); ?></label>
				<label class="sm-radio"><input type="radio" name="scout_members[reminders]" value="off" <?php checked( $s['reminders'], 'off' ); ?>> <?php esc_html_e( 'Off', 'scout-members' ); ?></label>
			</p>
		</section>
		<?php
	}

	private static function view_settings(): void {
		$s         = Scout_Members::settings();
		$questions = (string) get_option( 'scout_members_questions', '' );
		$next      = (string) get_option( 'scout_members_next_step', '' );
		if ( Scout_Members::plans() && $s['page'] ) {
			self::ui_status(
				'good',
				__( 'Membership is open.', 'scout-members' ),
				/* translators: %s: page title. */
				sprintf( __( 'People apply on "%s".', 'scout-members' ), get_the_title( (int) $s['page'] ) )
			);
		} else {
			self::ui_status(
				'warn',
				__( 'Membership is not open yet.', 'scout-members' ),
				! $s['page'] ? __( 'Choose the membership page below, then add your plans.', 'scout-members' ) : __( 'Add your plans below and save.', 'scout-members' )
			);
		}
		?>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sm-settings">
				<input type="hidden" name="action" value="scout_members_settings">
				<?php wp_nonce_field( 'scout_members_settings' ); ?>

				<section class="sh-box" id="sm-pages">
					<h2 class="sh-title"><?php esc_html_e( 'Membership pages', 'scout-members' ); ?></h2>
					<p><?php esc_html_e( 'Where people apply, and where members land once they sign in.', 'scout-members' ); ?></p>
					<p class="sm-field">
						<label for="sm-set-page"><?php esc_html_e( 'Membership page', 'scout-members' ); ?></label>
						<?php
						wp_dropdown_pages(
							array(
								'name'              => 'scout_members[page]',
								'id'                => 'sm-set-page',
								'selected'          => (int) $s['page'],
								'show_option_none'  => esc_html__( 'Choose a page', 'scout-members' ),
								'option_none_value' => '0',
							)
						);
						?>
						<span class="sh-note"><?php esc_html_e( 'The page with [scout_membership] on it. Join links and emails point here.', 'scout-members' ); ?></span>
					</p>
					<p class="sm-field">
						<label for="sm-set-home"><?php esc_html_e( 'Members home', 'scout-members' ); ?></label>
						<?php
						wp_dropdown_pages(
							array(
								'name'              => 'scout_members[home]',
								'id'                => 'sm-set-home',
								'selected'          => (int) $s['home'],
								'show_option_none'  => esc_html__( 'None yet', 'scout-members' ),
								'option_none_value' => '0',
							)
						);
						?>
						<span class="sh-note"><?php esc_html_e( 'Where members land after signing in. It and every page under it are members only.', 'scout-members' ); ?></span>
					</p>
				</section>

				<section class="sh-box" id="sm-plans">
					<h2 class="sh-title"><?php esc_html_e( 'Plans and what happens after approval', 'scout-members' ); ?></h2>
					<p class="sm-field">
						<label for="sm-set-plans"><?php esc_html_e( 'Plans, one per line', 'scout-members' ); ?></label>
						<textarea id="sm-set-plans" name="scout_members[plans]" rows="4" class="large-text code" placeholder="Club Member | 120 | year"><?php echo esc_textarea( $s['plans'] ); ?></textarea>
						<span class="sh-note"><?php esc_html_e( 'Name | price | month, year or once. People choose one when they apply, and you can change it when you approve them.', 'scout-members' ); ?></span>
					</p>
					<p class="sm-field">
						<label for="sm-set-next"><?php esc_html_e( 'Next step after approval', 'scout-members' ); ?></label>
						<textarea id="sm-set-next" name="scout_members[next_step]" rows="3" class="large-text"><?php echo esc_textarea( $next ); ?></textarea>
						<span class="sh-note"><?php esc_html_e( 'Goes in the welcome email and on the member page until their first payment is recorded. For example, how and where to pay dues.', 'scout-members' ); ?></span>
					</p>
				</section>

				<?php self::dues_settings( $s ); ?>

				<section class="sh-box" id="sm-apply">
					<h2 class="sh-title"><?php esc_html_e( 'Applying', 'scout-members' ); ?></h2>
					<p><?php esc_html_e( 'What applicants agree to, and who hears about a new application.', 'scout-members' ); ?></p>
					<p class="sm-field">
						<label for="sm-set-agree"><?php esc_html_e( 'Community agreements link (optional)', 'scout-members' ); ?></label>
						<input type="url" id="sm-set-agree" name="scout_members[agreements_url]" value="<?php echo esc_attr( $s['agreements_url'] ); ?>" class="regular-text">
						<span class="sh-note"><?php esc_html_e( 'When set, applicants must tick that they have read them.', 'scout-members' ); ?></span>
					</p>
					<p class="sm-field">
						<label for="sm-set-age"><?php esc_html_e( 'Minimum age (optional)', 'scout-members' ); ?></label>
						<input type="number" id="sm-set-age" name="scout_members[min_age]" value="<?php echo (int) $s['min_age']; ?>" min="0" max="99" class="small-text">
						<span class="sh-note"><?php esc_html_e( 'When set, applicants must confirm their age. 0 leaves it out.', 'scout-members' ); ?></span>
					</p>
					<p class="sm-field">
						<label for="sm-set-notify"><?php esc_html_e( 'Send new applications to', 'scout-members' ); ?></label>
						<input type="email" id="sm-set-notify" name="scout_members[notify]" value="<?php echo esc_attr( $s['notify'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( Scout_Members::shared_notice_email() ); ?>">
						<span class="sh-note"><?php esc_html_e( 'Leave it blank to use the notice address in Business info. The email names the applicant only. Their answers stay on the site.', 'scout-members' ); ?></span>
					</p>
				</section>

				<section class="sh-box" id="sm-questions">
					<h2 class="sh-title"><?php esc_html_e( 'Questions people answer', 'scout-members' ); ?></h2>
					<p class="sm-field">
						<label for="sm-set-q"><?php esc_html_e( 'Application questions, one per line', 'scout-members' ); ?></label>
						<textarea id="sm-set-q" name="scout_members[questions]" rows="14" class="large-text code" placeholder="<?php echo esc_attr( Scout_Members_Form::default_questions() ); ?>"><?php echo esc_textarea( $questions ); ?></textarea>
						<span class="sh-note"><?php esc_html_e( 'Type | Question | extra. Add * after the type to make it required. Types: heading, text, email, tel, date, url, textarea, yesno, choice (options in extra, separated by ;), agree (a statement; extra is the tick-box words), check, address. First name, last name and email are always asked. Left empty, three short questions are used.', 'scout-members' ); ?></span>
					</p>
					<p class="sm-field">
						<label for="sm-set-iq"><?php esc_html_e( 'Invitation questions, one per line', 'scout-members' ); ?></label>
						<textarea id="sm-set-iq" name="scout_members[invitation_questions]" rows="8" class="large-text code" placeholder="<?php echo esc_attr( Scout_Members_Invitations::default_questions() ); ?>"><?php echo esc_textarea( (string) get_option( Scout_Members_Invitations::OPTION, '' ) ); ?></textarea>
						<span class="sh-note"><?php esc_html_e( 'What members answer when they post an invitation. Same format as the application questions.', 'scout-members' ); ?></span>
					</p>
				</section>

				<p class="sm-save"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'scout-members' ); ?></button></p>
			</form>
			<?php
			// "Create the product" buttons sit in the settings form but submit these, since forms cannot nest.
			if ( Scout_Members_Woo::active() ) {
				foreach ( array_keys( Scout_Members::plans() ) as $slug ) {
					echo '<form id="sm-make-' . esc_attr( $slug ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden><input type="hidden" name="action" value="scout_members_product"><input type="hidden" name="plan" value="' . esc_attr( $slug ) . '">';
					wp_nonce_field( 'scout_members_product_' . $slug );
					echo '</form>';
				}
			}
			?>
		<?php endif; ?>

		<?php self::ui_fold_open( __( 'Add to a page', 'scout-members' ), false, 'sm-shortcodes' ); ?>
			<p><?php esc_html_e( 'Paste these on a page to show the membership on the site.', 'scout-members' ); ?></p>
			<div class="sm-table-wrap">
				<table class="sh-table sm-codes">
					<tr><td>[scout_membership]</td><td><?php esc_html_e( 'The membership page: the application for new people, the account for members.', 'scout-members' ); ?></td></tr>
					<tr><td>[scout_members_only] … [/scout_members_only]</td><td><?php esc_html_e( 'Hides the part in between from everyone but members. Add plan="VIP Member" to limit it to a plan.', 'scout-members' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Members only box', 'scout-members' ); ?></td><td><?php esc_html_e( 'On any page, post or event. Ticked, only members can read it or buy tickets. Tick plans to limit a page to them.', 'scout-members' ); ?></td></tr>
					<tr><td>[scout_member_signin]</td><td><?php esc_html_e( 'The sign-in form, anywhere on the site.', 'scout-members' ); ?></td></tr>
					<tr><td>[scout_member_link]</td><td><?php esc_html_e( 'A "Member sign in" link that becomes "Members home" once signed in. In a menu, link to #scout-member-link.', 'scout-members' ); ?></td></tr>
					<tr><td>[scout_invitations]</td><td><?php esc_html_e( 'Member invitations and a form to post one. Put it on a members page.', 'scout-members' ); ?></td></tr>
					<tr><td>[scout_members_home]</td><td><?php esc_html_e( 'The list of members pages and events. The members home shows it already.', 'scout-members' ); ?></td></tr>
				</table>
			</div>
			<p class="sh-note"><?php esc_html_e( 'Members sign in on the site, land on the members home and never see the dashboard. The WordPress password page carries the site\'s logo.', 'scout-members' ); ?></p>
		<?php self::ui_fold_close(); ?>
		<?php
	}

	/* ---------- kit wrappers ---------- */

	/**
	 * The kit's status line, or the same markup when an older kit (before
	 * 1.10.0, without status()) is the copy that loaded first.
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

	/** The kit's closed "More options" box, or the same markup on an older kit. */
	private static function ui_fold_open( string $title, bool $open = false, string $id = '' ): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_open' ) ) {
			Scout_Admin_UI::fold_open( $title, $open, $id );
			return;
		}
		echo '<details class="sh-box sh-fold"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ( $open ? ' open' : '' ) . '><summary class="sh-title">' . esc_html( $title ) . '</summary>';
	}

	/** Closes ui_fold_open(). */
	private static function ui_fold_close(): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_close' ) ) {
			Scout_Admin_UI::fold_close();
			return;
		}
		echo '</details>';
	}

}
