<?php
/**
 * Members-only events, when Scout Events is active. An event marked members
 * only stays on the public calendar with a "Members only" tag; members get
 * the ticket button and everyone else a link to become a member. The members'
 * account page lists the members-only events coming up.
 *
 * @package Scout_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Members_Events {

	public static function boot(): void {
		if ( ! class_exists( 'Scout_Events' ) ) {
			return;
		}
		add_action( 'add_meta_boxes_' . Scout_Events::EVENT, array( __CLASS__, 'box' ) );
		add_action( 'save_post_' . Scout_Events::EVENT, array( __CLASS__, 'save' ) );
		add_filter( 'scout_events_detail_rows', array( __CLASS__, 'rows' ), 10, 2 );
		add_filter( 'scout_events_ticket_html', array( __CLASS__, 'ticket' ), 10, 2 );
		add_filter( 'scout_events_card_tags', array( __CLASS__, 'tags' ), 10, 2 );
		add_action( 'scout_events_imported_event', array( __CLASS__, 'imported' ), 10, 2 );
		add_filter( 'scout_members_account_extra', array( __CLASS__, 'account' ) );
	}

	public static function box(): void {
		add_meta_box( 'scout-members-only', __( 'Members only', 'scout-members' ), array( __CLASS__, 'render_box' ), Scout_Events::EVENT, 'side' );
	}

	public static function render_box( $post ): void {
		wp_nonce_field( 'scout_members_event_' . $post->ID, '_sm_event' );
		echo '<p><label><input type="checkbox" name="sm_members_only" value="1" ' . checked( Scout_Members::is_members_only( (int) $post->ID ), true, false ) . '> ' . esc_html__( 'Only members can come', 'scout-members' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'The event stays on the calendar with a Members only tag. Members see the ticket button; everyone else sees how to join.', 'scout-members' ) . '</p>';
	}

	public static function save( $post_id ): void {
		if ( ! isset( $_POST['_sm_event'] ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sm_event'] ) ), 'scout_members_event_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		Scout_Members::set_members_only( (int) $post_id, ! empty( $_POST['sm_members_only'] ) );
	}

	public static function rows( $rows, $id ): array {
		$rows = (array) $rows;
		if ( Scout_Members::is_members_only( (int) $id ) ) {
			$rows[] = array( __( 'Who can come', 'scout-members' ), esc_html__( 'Members only', 'scout-members' ) );
		}
		return $rows;
	}

	public static function ticket( $html, $id ): string {
		if ( ! Scout_Members::is_members_only( (int) $id ) || Scout_Members::can_see() ) {
			return (string) $html;
		}
		$m = is_user_logged_in() ? Scout_Members::member( get_current_user_id() ) : null;
		if ( $m && 'applied' === $m['status'] ) {
			return '<p class="se-cta sm-cta"><span class="se-soon">' . esc_html__( 'Members only. This opens once your membership application is approved.', 'scout-members' ) . '</span></p>';
		}
		$out = '<p class="se-cta sm-cta"><span class="se-soon">' . esc_html__( 'This event is for members.', 'scout-members' ) . '</span> <a class="se-button sm-button wp-element-button" href="' . esc_url( Scout_Members::page_url() ) . '">' . esc_html__( 'Become a member', 'scout-members' ) . '</a>';
		if ( ! is_user_logged_in() ) {
			$out .= ' <a class="sm-signin" href="' . esc_url( wp_login_url( (string) get_permalink( (int) $id ) ) ) . '">' . esc_html__( 'Sign in', 'scout-members' ) . '</a>';
		}
		return $out . '</p>';
	}

	public static function tags( $tags, $id ): array {
		$tags = (array) $tags;
		if ( Scout_Members::is_members_only( (int) $id ) ) {
			array_unshift( $tags, __( 'Members only', 'scout-members' ) );
		}
		return $tags;
	}

	/** A members_only column in events.csv: yes, members, 1 or true turn it on; no, 0 or false turn it off. */
	public static function imported( $id, $row ): void {
		$v = strtolower( trim( (string) ( ( (array) $row )['members_only'] ?? '' ) ) );
		if ( in_array( $v, array( 'yes', 'y', '1', 'true', 'members' ), true ) ) {
			Scout_Members::set_members_only( (int) $id, true );
		} elseif ( in_array( $v, array( 'no', 'n', '0', 'false' ), true ) ) {
			Scout_Members::set_members_only( (int) $id, false );
		}
	}

	/** The members-only events coming up, on the member's account page. */
	public static function account( $html ): string {
		$ids = array_values( array_filter( Scout_Events::event_ids( 'upcoming' ), static fn( $id ) => Scout_Members::is_members_only( (int) $id ) ) );
		if ( ! $ids || ! class_exists( 'Scout_Events_Front' ) ) {
			return (string) $html;
		}
		return (string) $html . '<h3 class="sm-sub">' . esc_html__( 'Members-only events coming up', 'scout-members' ) . '</h3>' . Scout_Events_Front::events( array_slice( $ids, 0, 6 ) );
	}
}
