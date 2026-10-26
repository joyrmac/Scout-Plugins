<?php
/**
 * Events, Customer list: whether the sync is on, and one button that brings
 * in everyone from before it was switched on (sign-ups, volunteers, vendors,
 * presenters, members and paid shop tickets), tagged the same way. Running it
 * twice changes nothing: each person is matched by email.
 *
 * @package Scout_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_CRM_Backfill {

	const PAGE = 'scout-events-crm';

	public static function boot(): void {
		add_action( 'scout_events_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_scout_crm_backfill', array( __CLASS__, 'run' ) );
	}

	public static function menu( $parent ): void {
		add_submenu_page( (string) $parent, __( 'Customer list', 'scout-crm' ), __( 'Customer list', 'scout-crm' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ui = class_exists( 'Scout_Admin_UI' );
		if ( $ui ) {
			Scout_Admin_UI::open( __( 'Customer list', 'scout-crm' ), __( 'Everyone who buys, signs up, volunteers, applies or joins, in one list in FluentCRM, tagged by what they did, the event and its region.', 'scout-crm' ), __( 'Events', 'scout-crm' ) );
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Customer list', 'scout-crm' ) . '</h1>';
		}
		$done = get_transient( 'scout_crm_backfill_' . get_current_user_id() );
		if ( is_array( $done ) ) {
			delete_transient( 'scout_crm_backfill_' . get_current_user_id() );
			$parts = array();
			foreach ( $done as $what => $n ) {
				$parts[] = $what . ': ' . number_format_i18n( (int) $n );
			}
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Done. Added or updated:', 'scout-crm' ) . ' ' . esc_html( implode( ' · ', $parts ) ) . '</p></div>';
		}
		echo '<section class="sh-box"><h2 class="sh-title">' . esc_html__( 'Status', 'scout-crm' ) . '</h2>';
		if ( ! Scout_CRM::ready() ) {
			echo '<p>' . esc_html__( 'FluentCRM is not active, so nothing is sent yet. Install and activate FluentCRM (free) and this page takes it from there.', 'scout-crm' ) . '</p></section>';
		} else {
			echo '<p>' . esc_html__( 'On. New tickets, sign-ups, volunteers, vendor and presenter applications and membership changes go to FluentCRM as they happen.', 'scout-crm' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=fluentcrm-admin#/subscribers' ) ) . '">' . esc_html__( 'Open the contacts', 'scout-crm' ) . '</a></p>';
			echo '<p class="sh-note">' . esc_html__( 'New people are added as transactional: they get receipts and event notes, and join marketing emails only when they subscribe. People already in the list keep their status.', 'scout-crm' ) . '</p></section>';
			echo '<section class="sh-box"><h2 class="sh-title">' . esc_html__( 'Bring in everyone so far', 'scout-crm' ) . '</h2><p>' . esc_html__( 'Adds the people from before the sync was on, tagged the same way. Safe to run again: people are matched by email.', 'scout-crm' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="scout_crm_backfill">';
			wp_nonce_field( 'scout_crm_backfill' );
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Bring them in', 'scout-crm' ) . '</button></p></form></section>';
		}
		echo '<section class="sh-box"><h2 class="sh-title">' . esc_html__( 'Tags it uses', 'scout-crm' ) . '</h2><p>' . esc_html__( 'Attendee, Waitlist, Volunteer, Vendor, Presenter, Ticket buyer, Member applicant, Member, Plan: (plan name), Event: (event name), Region: (region name), Ticket: (ticket type).', 'scout-crm' ) . '</p></section>';
		if ( $ui ) {
			Scout_Admin_UI::close();
		} else {
			echo '</div>';
		}
	}

	public static function run(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'scout-crm' ), 403 );
		}
		check_admin_referer( 'scout_crm_backfill' );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- a one-off bulk run.
		}
		$count = array();
		$add   = static function ( string $label, bool $ok ) use ( &$count ) {
			$count[ $label ] = ( $count[ $label ] ?? 0 ) + ( $ok ? 1 : 0 );
		};
		global $wpdb;

		if ( class_exists( 'Scout_Events_RSVP' ) ) {
			$rows = $wpdb->get_results( 'SELECT event_id, name, email, status FROM ' . Scout_Events_RSVP::table() . " WHERE status IN ('going','waitlist') LIMIT 5000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $r ) {
				$role = 'going' === $r['status'] ? 'attendee' : 'waitlist';
				$add( __( 'sign-ups', 'scout-crm' ), Scout_CRM::save( array( 'email' => $r['email'], 'name' => $r['name'] ), array_merge( array( 'going' === $r['status'] ? __( 'Attendee', 'scout-crm' ) : __( 'Waitlist', 'scout-crm' ) ), Scout_CRM::event_tags( (int) $r['event_id'] ) ), 'events-' . $role ) );
			}
		}
		if ( class_exists( 'Scout_Events_Volunteers' ) ) {
			$rows = $wpdb->get_results( 'SELECT event_id, email, MIN(name) AS name, MIN(phone) AS phone FROM ' . Scout_Events_Volunteers::table() . " WHERE status = 'going' GROUP BY event_id, email LIMIT 5000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $r ) {
				$add( __( 'volunteers', 'scout-crm' ), Scout_CRM::save( array( 'email' => $r['email'], 'name' => $r['name'], 'phone' => $r['phone'] ), array_merge( array( __( 'Volunteer', 'scout-crm' ) ), Scout_CRM::event_tags( (int) $r['event_id'] ) ), 'events-volunteer' ) );
			}
		}
		if ( class_exists( 'Scout_Events_Vendors' ) ) {
			$ids = get_posts( array( 'post_type' => Scout_Events_Vendors::TYPE, 'post_status' => array( 'publish', 'pending' ), 'posts_per_page' => 2000, 'fields' => 'ids' ) );
			foreach ( $ids as $id ) {
				$priv = get_post_meta( (int) $id, Scout_Events_Vendors::PRIVATE, true );
				$priv = is_array( $priv ) ? $priv : array();
				$tags = array( __( 'Vendor', 'scout-crm' ) );
				foreach ( Scout_Events_Vendors::vendor( (int) $id )['events'] as $event ) {
					$tags = array_merge( $tags, Scout_CRM::event_tags( (int) $event ) );
				}
				$add( __( 'vendors', 'scout-crm' ), Scout_CRM::save( array( 'email' => (string) ( $priv['email'] ?? '' ), 'name' => (string) ( $priv['contact'] ?? '' ), 'phone' => (string) ( $priv['phone'] ?? '' ), 'company' => get_the_title( (int) $id ) ), $tags, 'events-vendor' ) );
			}
		}
		if ( class_exists( 'Scout_Events_Presenters' ) ) {
			$ids = get_posts( array( 'post_type' => 'scout_speaker', 'post_status' => array( 'publish', 'pending' ), 'posts_per_page' => 2000, 'fields' => 'ids', 'meta_key' => Scout_Events_Presenters::USER ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a one-off run.
			foreach ( $ids as $id ) {
				$user = get_userdata( (int) get_post_meta( (int) $id, Scout_Events_Presenters::USER, true ) );
				if ( ! $user ) {
					continue;
				}
				$tags = array( __( 'Presenter', 'scout-crm' ) );
				foreach ( Scout_Events::sessions_for_speaker( (int) $id ) as $sid ) {
					$tags = array_merge( $tags, Scout_CRM::event_tags( Scout_Events::session( (int) $sid )['event'] ) );
				}
				$add( __( 'presenters', 'scout-crm' ), Scout_CRM::save( array( 'email' => $user->user_email, 'name' => get_the_title( (int) $id ) ), $tags, 'events-presenter' ) );
			}
		}
		if ( class_exists( 'Scout_Members' ) ) {
			foreach ( Scout_Members::ids( array( 'applied', 'active' ) ) as $uid ) {
				Scout_CRM::member( $uid, Scout_Members::member( $uid )['status'], '' );
				$add( __( 'members', 'scout-crm' ), true );
			}
		}
		if ( class_exists( 'Scout_Events_Woo' ) && function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( array( 'status' => array( 'processing', 'completed' ), 'limit' => 2000 ) );
			foreach ( $orders as $order ) {
				foreach ( $order->get_items() as $item ) {
					$pid   = (int) $item->get_product_id();
					$event = (int) get_post_meta( $pid, Scout_Events_Woo::EVENT, true );
					if ( $event ) {
						Scout_CRM::ticket( $order, $event, $pid, (int) $item->get_quantity() );
						$add( __( 'ticket lines', 'scout-crm' ), true );
					}
				}
			}
		}
		set_transient( 'scout_crm_backfill_' . get_current_user_id(), $count, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}
}
