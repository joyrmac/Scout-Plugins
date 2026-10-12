<?php
/**
 * Request a change: a short form in the dashboard that emails the team and
 * keeps a list on the site, so the client can see each request and where it
 * stands (Received, In progress, Done).
 *
 * The email goes to team@scoutraleigh.com unless the site sets its own:
 *   define( 'SCOUT_HUB_REQUEST_EMAIL', 'someone@example.com' );
 * Partner-side sites must set this, so nothing routes to a Scout address.
 *
 * A "Request a change" link in the admin bar opens the form with the page the
 * person was looking at already filled in.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Requests {

	const TYPE        = 'scout_hub_request';
	const ACTION      = 'scout_hub_request';
	const META_PAGE   = '_scout_hub_page';
	const META_WHEN   = '_scout_hub_when';
	const META_STATUS = '_scout_hub_status';
	const META_FILE   = '_scout_hub_file';

	const STATUSES = array(
		'received' => 'Received',
		'progress' => 'In progress',
		'done'     => 'Done',
	);

	const WHEN = array(
		'whenever' => 'Whenever you can',
		'week'     => 'This week',
		'soon'     => 'As soon as possible',
	);

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
	}

	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'       => array( 'name' => __( 'Change requests', 'scout-build' ) ),
				'public'       => false,
				'show_ui'      => false,
				'rewrite'      => false,
				'supports'     => array( 'title', 'editor', 'author' ),
				'capabilities' => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap' => true,
			)
		);
	}

	public static function recipient(): string {
		$to = defined( 'SCOUT_HUB_REQUEST_EMAIL' ) ? (string) SCOUT_HUB_REQUEST_EMAIL : 'team@scoutraleigh.com';
		return (string) apply_filters( 'scout_hub_request_recipient', $to );
	}

	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$here = is_admin() ? '' : home_url( add_query_arg( array() ) );
		$bar->add_node(
			array(
				'id'    => 'scout-hub-request',
				'title' => __( 'Request a change', 'scout-build' ),
				'href'  => Scout_Hub_Admin::url( 'requests', $here ? array( 'from' => rawurlencode( $here ) ) : array() ),
			)
		);
	}

	/** @return WP_Post[] */
	public static function all(): array {
		return get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}

	public static function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'scout-build' ), 403 );
		}
		$do = sanitize_key( wp_unslash( (string) ( $_POST['do'] ?? 'new' ) ) );

		if ( 'status' === $do ) {
			if ( ! Scout_Hub_Access::is_operator() ) {
				wp_die( esc_html__( 'Only the people who look after this site can change a request\'s status.', 'scout-build' ), 403 );
			}
			$id     = (int) ( $_POST['request'] ?? 0 );
			$status = sanitize_key( wp_unslash( (string) ( $_POST['status'] ?? '' ) ) );
			if ( self::TYPE === get_post_type( $id ) && isset( self::STATUSES[ $status ] ) ) {
				update_post_meta( $id, self::META_STATUS, $status );
			}
			self::back( __( 'Status updated.', 'scout-build' ) );
		}

		$what = trim( sanitize_textarea_field( wp_unslash( (string) ( $_POST['what'] ?? '' ) ) ) );
		$page = esc_url_raw( trim( wp_unslash( (string) ( $_POST['page'] ?? '' ) ) ) );
		$when = sanitize_key( wp_unslash( (string) ( $_POST['when'] ?? 'whenever' ) ) );
		$when = isset( self::WHEN[ $when ] ) ? $when : 'whenever';
		if ( '' === $what ) {
			self::back( __( 'Tell us what you need changed, then send it again.', 'scout-build' ), 'error' );
		}

		$user  = wp_get_current_user();
		$title = wp_trim_words( $what, 10, '…' );
		$id    = wp_insert_post(
			array(
				'post_type'    => self::TYPE,
				'post_status'  => 'private',
				'post_title'   => $title,
				'post_content' => $what,
				'post_author'  => $user->ID,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			self::back( __( 'The request could not be saved. Please email us instead.', 'scout-build' ), 'error' );
		}
		update_post_meta( $id, self::META_PAGE, $page );
		update_post_meta( $id, self::META_WHEN, $when );
		update_post_meta( $id, self::META_STATUS, 'received' );

		$attachments = array();
		if ( ! empty( $_FILES['file']['name'] ) && current_user_can( 'upload_files' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$file_id = media_handle_upload( 'file', 0 );
			if ( ! is_wp_error( $file_id ) ) {
				update_post_meta( $id, self::META_FILE, (int) $file_id );
				$attachments[] = get_attached_file( $file_id );
			}
		}

		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$lines = array(
			/* translators: 1: person, 2: site */
			sprintf( __( 'From: %1$s on %2$s', 'scout-build' ), $user->display_name . ' <' . $user->user_email . '>', $site ),
			/* translators: %s: timing */
			sprintf( __( 'When: %s', 'scout-build' ), self::WHEN[ $when ] ),
			$page ? sprintf( /* translators: %s: page URL */ __( 'Page: %s', 'scout-build' ), $page ) : __( 'Page: not given', 'scout-build' ),
			'',
			$what,
			'',
			/* translators: %s: admin URL */
			sprintf( __( 'Request list: %s', 'scout-build' ), Scout_Hub_Admin::url( 'requests' ) ),
		);
		wp_mail(
			self::recipient(),
			/* translators: 1: site, 2: short title */
			sprintf( __( '[%1$s] Change request: %2$s', 'scout-build' ), $site, $title ),
			implode( "\n", $lines ),
			array( 'Reply-To: ' . $user->display_name . ' <' . $user->user_email . '>' ),
			$attachments
		);
		self::back( __( 'Sent. We will reply by email, and you can follow it in the list below.', 'scout-build' ) );
	}

	private static function back( string $message, string $type = 'success' ): void {
		set_transient( 'scout_hub_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), MINUTE_IN_SECONDS );
		wp_safe_redirect( Scout_Hub_Admin::url( 'requests' ) );
		exit;
	}
}
