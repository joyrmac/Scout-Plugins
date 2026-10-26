<?php
/**
 * Member invitations: offerings created by members, for members.
 *
 * [scout_invitations] goes on a members-only page. Members see the
 * invitations the owner has approved, their own invitations with a way to
 * take one down, and a form to post a new one. A new invitation waits as
 * Pending under Members, Invitations, where only the owner reads it; Publish
 * puts it on the page and tells the member. The questions are the owner's own,
 * written in Settings the same way as the application's.
 *
 * Invitations are personal (they often carry a contact line), so only people
 * who can list users (administrators) see them in the dashboard, and the
 * WordPress privacy tools export and erase them with the member's record.
 *
 * @package Scout_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Members_Invitations {

	const TYPE    = 'scout_invitation';
	const META    = '_scout_invitation';
	const UNTIL   = '_scout_invitation_until';
	const OPTION  = 'scout_members_invitation_questions';
	const PAGE    = 'scout_members_invitations_page';
	const MIN_SEC = 4;

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_shortcode( 'scout_invitations', array( __CLASS__, 'board' ) );
		add_action( 'admin_post_scout_members_invite', array( __CLASS__, 'submit' ) );
		add_action( 'admin_post_scout_members_invite_withdraw', array( __CLASS__, 'withdraw' ) );
		add_action( 'add_meta_boxes_' . self::TYPE, array( __CLASS__, 'box' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save_box' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'published' ), 10, 3 );
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'scout_events_needs_you', array( __CLASS__, 'needs_you' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu_order' ), 99 );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}

	/** Not public: invitations only ever show on the members-only page that holds the board. */
	public static function register(): void {
		$cap = Scout_Members_Admin::CAP;
		register_post_type(
			self::TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Invitations', 'scout-members' ),
					'singular_name' => __( 'Invitation', 'scout-members' ),
					'edit_item'     => __( 'Read invitation', 'scout-members' ),
					'search_items'  => __( 'Search invitations', 'scout-members' ),
					'not_found'     => __( 'No invitations yet. Members post them from the invitations page.', 'scout-members' ),
					'all_items'     => __( 'Invitations', 'scout-members' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => Scout_Members_Admin::SLUG,
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				'map_meta_cap' => false,
				'capabilities' => array(
					'edit_post'              => $cap,
					'read_post'              => $cap,
					'delete_post'            => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'edit_published_posts'   => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'delete_posts'           => $cap,
					'delete_others_posts'    => $cap,
					'delete_published_posts' => $cap,
					// Members write invitations on the site; there is nothing to start in the dashboard.
					'create_posts'           => 'do_not_allow',
				),
			)
		);
	}

	/* ---------- the questions ---------- */

	/** Used when the owner has not written their own. */
	public static function default_questions(): string {
		return implode(
			"\n",
			array(
				'textarea* | ' . __( 'What are you inviting people into?', 'scout-members' ),
				'text | ' . __( 'When', 'scout-members' ),
				'text | ' . __( 'Where, or online', 'scout-members' ),
				'text* | ' . __( 'How to reach you', 'scout-members' ) . ' | ' . __( 'Members see this with your invitation.', 'scout-members' ),
			)
		);
	}

	public static function questions(): array {
		$src = trim( (string) get_option( self::OPTION, '' ) );
		return Scout_Members_Form::parse( '' !== $src ? $src : self::default_questions() );
	}

	/** @return array<string,string> question => answer */
	public static function answers( int $id ): array {
		$a = get_post_meta( $id, self::META, true );
		return is_array( $a ) ? array_map( 'strval', $a ) : array();
	}

	/* ---------- the board on the site ---------- */

	private static function page_url(): string {
		$id  = (int) get_option( self::PAGE, 0 );
		$url = $id && 'publish' === get_post_status( $id ) ? get_permalink( $id ) : '';
		return $url ? (string) $url : Scout_Members::home_url();
	}

	/** Live invitations: approved, and not past their "show until" date. */
	private static function live(): array {
		$ids = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
			)
		);
		$today = Scout_Members::today();
		return array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static function ( $id ) use ( $today ) {
					$until = (string) get_post_meta( $id, self::UNTIL, true );
					return '' === $until || $until >= $today;
				}
			)
		);
	}

	/** One invitation as members see it. */
	private static function card( int $id ): string {
		$author = get_userdata( (int) get_post_field( 'post_author', $id ) );
		$first  = $author ? ( '' !== (string) $author->first_name ? (string) $author->first_name : (string) $author->display_name ) : '';
		$out    = '<article class="sm-invite" id="sm-invite-' . $id . '"><h3 class="sm-invite-title">' . esc_html( get_the_title( $id ) ) . '</h3>';
		$meta   = array_filter(
			array(
				/* translators: %s: the member's first name. */
				'' !== $first ? sprintf( __( 'From %s', 'scout-members' ), $first ) : '',
				get_the_date( '', $id ),
			)
		);
		$out .= '<p class="sm-invite-meta">' . esc_html( implode( ' · ', $meta ) ) . '</p><dl class="sm-invite-answers">';
		// Tick boxes (agreements) are for the owner's record, not for the card.
		$ticks = array();
		foreach ( self::questions() as $q ) {
			if ( 'agree' === $q['type'] ) {
				$ticks[] = '' !== $q['extra'] ? $q['extra'] : __( 'I agree.', 'scout-members' );
			} elseif ( 'check' === $q['type'] ) {
				$ticks[] = $q['label'];
			}
		}
		foreach ( self::answers( $id ) as $q => $a ) {
			if ( '' === trim( $a ) || in_array( $q, $ticks, true ) ) {
				continue;
			}
			$out .= '<div><dt>' . esc_html( $q ) . '</dt><dd>' . wp_kses_post( make_clickable( wpautop( esc_html( $a ) ) ) ) . '</dd></div>';
		}
		return $out . '</dl></article>';
	}

	public static function board(): string {
		wp_enqueue_style( 'scout-members' );
		$page = (int) get_the_ID();
		if ( is_singular() && $page && (int) get_option( self::PAGE, 0 ) !== $page ) {
			update_option( self::PAGE, $page, false );
		}
		if ( ! Scout_Members::can_see( $page ) ) {
			return Scout_Members_Front::gate( __( 'Invitations are for members.', 'scout-members' ) );
		}
		$user = wp_get_current_user();
		$out  = '<div class="sm-invitations" id="sm-invitations">' . self::notice();

		$live = self::live();
		if ( $live ) {
			$out .= '<div class="sm-invite-list">';
			foreach ( $live as $id ) {
				$out .= self::card( $id );
			}
			$out .= '</div>';
		} else {
			$out .= '<p class="sm-note">' . esc_html__( 'No invitations up right now. Be the first to post one.', 'scout-members' ) . '</p>';
		}

		$mine = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => array( 'pending', 'publish' ),
				'author'         => (int) $user->ID,
				'posts_per_page' => 20,
				'fields'         => 'ids',
			)
		);
		if ( $mine ) {
			$out .= '<h3 class="sm-sub">' . esc_html__( 'Your invitations', 'scout-members' ) . '</h3><ul class="sm-mine">';
			foreach ( $mine as $id ) {
				$status = 'pending' === get_post_status( $id ) ? __( 'Waiting to be read', 'scout-members' ) : __( 'Up on this page', 'scout-members' );
				$out   .= '<li><span class="sm-mine-title">' . esc_html( get_the_title( $id ) ) . '</span> <span class="sm-mine-status">' . esc_html( $status ) . '</span>'
					. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sm-mine-drop"><input type="hidden" name="action" value="scout_members_invite_withdraw"><input type="hidden" name="invitation" value="' . (int) $id . '">'
					. wp_nonce_field( 'scout_members_invite_withdraw_' . $id, '_sm_nonce', true, false )
					. '<button type="submit" class="sm-link-button">' . esc_html__( 'Take it down', 'scout-members' ) . '</button></form></li>';
			}
			$out .= '</ul>';
		}

		$open = isset( $_GET['invitation'] ) && 'missing' === $_GET['invitation']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$out .= '<details class="sm-invite-new"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html__( 'Post an invitation', 'scout-members' ) . '</summary>' . self::form() . '</details>';
		return $out . '</div>';
	}

	private static function notice(): string {
		$msg   = isset( $_GET['invitation'] ) ? sanitize_key( wp_unslash( $_GET['invitation'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$texts = array(
			'sent'     => __( 'Thank you. Your invitation is in. It shows on this page once it has been read.', 'scout-members' ),
			'down'     => __( 'Your invitation has been taken down.', 'scout-members' ),
			'missing'  => __( 'A few required answers are missing. Please check the form and send it again.', 'scout-members' ),
			'tooquick' => __( 'That was very fast. Please check your answers and send the form again.', 'scout-members' ),
		);
		if ( ! isset( $texts[ $msg ] ) ) {
			return '';
		}
		return '<p class="sm-note ' . ( in_array( $msg, array( 'sent', 'down' ), true ) ? 'is-good' : 'is-bad' ) . '" role="status">' . esc_html( $texts[ $msg ] ) . '</p>';
	}

	private static function form(): string {
		$out  = '<form class="sm-form sm-invite-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="sm-invite-form">';
		$out .= '<input type="hidden" name="action" value="scout_members_invite">' . wp_nonce_field( 'scout_members_invite', '_sm_nonce', true, false );
		$out .= '<input type="hidden" name="sm_t" value="' . esc_attr( (string) time() ) . '">';
		$out .= '<p class="sm-hp" aria-hidden="true"><label for="sm-invite-website2">' . esc_html__( 'Leave this empty', 'scout-members' ) . '</label><input type="text" id="sm-invite-website2" name="sm_website2" tabindex="-1" autocomplete="off"></p>';
		$out .= Scout_Members_Form::input( 'title', __( 'Title of your invitation', 'scout-members' ), 'text', true );
		foreach ( self::questions() as $q ) {
			if ( 'heading' === $q['type'] ) {
				$out .= ( '' !== $q['label'] ? '<h4 class="sm-invite-heading">' . esc_html( $q['label'] ) . '</h4>' : '' ) . ( '' !== $q['extra'] ? '<p class="sm-statement">' . esc_html( $q['extra'] ) . '</p>' : '' );
				continue;
			}
			$out .= Scout_Members_Form::question( $q );
		}
		$out .= Scout_Members_Form::input( 'until', __( 'Show it until (optional)', 'scout-members' ), 'date', false, '', __( 'After this date it comes down by itself.', 'scout-members' ) );
		$out .= '<p><button type="submit" class="sm-button wp-element-button">' . esc_html__( 'Send my invitation', 'scout-members' ) . '</button></p>';
		$out .= '<p class="sm-help">' . esc_html__( 'Only the people who run the membership read it before it goes up. Members see it on this page, never anyone else.', 'scout-members' ) . '</p>';
		return $out . '</form>';
	}

	/* ---------- posting and taking down ---------- */

	private static function back( string $msg, string $hash = '#sm-invitations' ): void {
		$ref = wp_get_referer();
		$url = $ref ? remove_query_arg( 'invitation', $ref ) : self::page_url();
		wp_safe_redirect( add_query_arg( 'invitation', $msg, $url ) . $hash );
		exit;
	}

	public static function submit(): void {
		check_admin_referer( 'scout_members_invite', '_sm_nonce' );
		if ( ! Scout_Members::can_see( (int) get_option( self::PAGE, 0 ) ) ) {
			wp_die( esc_html__( 'Invitations are for members. Sign in and try again.', 'scout-members' ), 403 );
		}
		if ( ! empty( $_POST['sm_website2'] ) ) {
			self::back( 'sent' );
		}
		$t = isset( $_POST['sm_t'] ) ? (int) $_POST['sm_t'] : 0;
		if ( $t <= 0 || time() - $t < self::MIN_SEC ) {
			self::back( 'tooquick' );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is cleaned where it is read.
		$m     = isset( $_POST['m'] ) ? (array) wp_unslash( $_POST['m'] ) : array();
		$title = sanitize_text_field( (string) ( $m['title'] ?? '' ) );
		$ans   = Scout_Members_Form::answers( $m, self::questions() );
		if ( '' === $title || null === $ans ) {
			self::back( 'missing', '#sm-invite-form' );
		}
		$id = wp_insert_post(
			array(
				'post_type'   => self::TYPE,
				'post_status' => 'pending',
				'post_title'  => $title,
				'post_author' => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			self::back( 'missing', '#sm-invite-form' );
		}
		update_post_meta( $id, self::META, $ans );
		$until = Scout_Members::clean_date( (string) ( $m['until'] ?? '' ) );
		if ( '' !== $until ) {
			update_post_meta( $id, self::UNTIL, $until );
		}
		// The title and a link only: the invitation itself stays on the site.
		/* translators: %s: invitation title. */
		wp_mail( Scout_Members::notify_email(), sprintf( __( 'New member invitation to read: %s', 'scout-members' ), $title ), __( "A member posted an invitation. Read it, and Publish it to put it on the members page:\n", 'scout-members' ) . admin_url( 'post.php?post=' . $id . '&action=edit' ) );
		self::back( 'sent' );
	}

	public static function withdraw(): void {
		$id = isset( $_POST['invitation'] ) ? absint( $_POST['invitation'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( 'scout_members_invite_withdraw_' . $id, '_sm_nonce' );
		if ( ! $id || self::TYPE !== get_post_type( $id ) || (int) get_post_field( 'post_author', $id ) !== get_current_user_id() ) {
			wp_die( esc_html__( 'You can only take down your own invitations.', 'scout-members' ), 403 );
		}
		wp_trash_post( $id );
		self::back( 'down' );
	}

	/** Publishing an invitation tells the member it is up. */
	public static function published( $new, $old, $post ): void {
		if ( 'publish' !== $new || 'publish' === $old || ! $post instanceof WP_Post || self::TYPE !== $post->post_type ) {
			return;
		}
		$user = get_userdata( (int) $post->post_author );
		if ( ! $user ) {
			return;
		}
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name. */
		wp_mail( $user->user_email, sprintf( __( 'Your invitation is up on %s', 'scout-members' ), $site ), sprintf( /* translators: %s: invitation title. */ __( "Your invitation \"%s\" is now on the members page:\n", 'scout-members' ), wp_specialchars_decode( $post->post_title, ENT_QUOTES ) ) . self::page_url() );
	}

	/* ---------- the dashboard ---------- */

	public static function box(): void {
		add_meta_box( 'scout-invitation', __( 'The invitation', 'scout-members' ), array( __CLASS__, 'render_box' ), self::TYPE, 'normal', 'high' );
	}

	public static function render_box( $post ): void {
		wp_nonce_field( 'scout_invitation_' . $post->ID, '_sm_invite' );
		$user = get_userdata( (int) $post->post_author );
		if ( $user ) {
			echo '<p><strong>' . esc_html__( 'From', 'scout-members' ) . ':</strong> ' . esc_html( $user->display_name ) . ' &middot; <a href="' . esc_url( Scout_Members_Admin::url( 'members' ) . '#sm-member-' . $user->ID ) . '">' . esc_html__( 'Their membership', 'scout-members' ) . '</a></p>';
		}
		echo '<p class="description">' . esc_html__( 'Publish puts it on the members page and emails the member. Move to Trash sends nothing. You can tidy the wording first.', 'scout-members' ) . '</p>';
		$i = 0;
		foreach ( self::answers( (int) $post->ID ) as $q => $a ) {
			++$i;
			echo '<p><label for="sm-inv-' . (int) $i . '"><strong>' . esc_html( $q ) . '</strong></label><br><textarea class="widefat" rows="3" id="sm-inv-' . (int) $i . '" name="sm_inv[' . esc_attr( $q ) . ']">' . esc_textarea( $a ) . '</textarea></p>';
		}
		echo '<p><label for="sm-inv-until"><strong>' . esc_html__( 'Show until', 'scout-members' ) . '</strong></label><br><input type="date" id="sm-inv-until" name="sm_inv_until" value="' . esc_attr( (string) get_post_meta( (int) $post->ID, self::UNTIL, true ) ) . '"></p>';
	}

	public static function save_box( $post_id, $post ): void {
		if ( ! isset( $_POST['_sm_invite'] ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sm_invite'] ) ), 'scout_invitation_' . $post_id ) || ! current_user_can( Scout_Members_Admin::CAP ) ) {
			return;
		}
		$old = self::answers( (int) $post_id );
		$in  = isset( $_POST['sm_inv'] ) ? (array) wp_unslash( $_POST['sm_inv'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned below.
		$new = array();
		// Only the questions already on it, in their order: the box edits answers, it does not add questions.
		foreach ( $old as $q => $a ) {
			$new[ $q ] = isset( $in[ $q ] ) ? sanitize_textarea_field( (string) $in[ $q ] ) : $a;
		}
		update_post_meta( $post_id, self::META, $new );
		$until = Scout_Members::clean_date( isset( $_POST['sm_inv_until'] ) ? sanitize_text_field( wp_unslash( $_POST['sm_inv_until'] ) ) : '' );
		if ( '' !== $until ) {
			update_post_meta( $post_id, self::UNTIL, $until );
		} else {
			delete_post_meta( $post_id, self::UNTIL );
		}
	}

	public static function columns( $cols ): array {
		$cols = (array) $cols;
		$date = $cols['date'] ?? '';
		unset( $cols['date'] );
		$cols['sm_from']  = __( 'From', 'scout-members' );
		$cols['sm_until'] = __( 'Shows until', 'scout-members' );
		if ( '' !== $date ) {
			$cols['date'] = $date;
		}
		return $cols;
	}

	public static function column( $col, $id ): void {
		if ( 'sm_from' === $col ) {
			$user = get_userdata( (int) get_post_field( 'post_author', (int) $id ) );
			echo esc_html( $user ? $user->display_name : '' );
		} elseif ( 'sm_until' === $col ) {
			echo esc_html( Scout_Members::date_label( (string) get_post_meta( (int) $id, self::UNTIL, true ) ) );
		}
	}

	/** Invitations sits after All members, with a count while some wait to be read. */
	public static function menu_order(): void {
		global $submenu;
		$slug = Scout_Members_Admin::SLUG;
		if ( empty( $submenu[ $slug ] ) ) {
			return;
		}
		$items = array_values( $submenu[ $slug ] );
		$link  = 'edit.php?post_type=' . self::TYPE;
		$mine  = array_values( array_filter( $items, static fn( $i ) => $link === ( $i[2] ?? '' ) ) );
		if ( ! $mine ) {
			return;
		}
		$items = array_values( array_filter( $items, static fn( $i ) => $link !== ( $i[2] ?? '' ) ) );
		$n     = self::pending_count();
		if ( $n ) {
			$mine[0][0] .= ' <span class="awaiting-mod count-' . $n . '"><span class="pending-count">' . number_format_i18n( $n ) . '</span></span>';
		}
		$at = 0;
		foreach ( $items as $i => $item ) {
			if ( $slug . '-members' === ( $item[2] ?? '' ) ) {
				$at = $i + 1;
			}
		}
		array_splice( $items, $at, 0, $mine );
		$submenu[ $slug ] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering our own submenu.
	}

	public static function pending_count(): int {
		return (int) ( wp_count_posts( self::TYPE )->pending ?? 0 );
	}

	public static function needs_you( $items ): array {
		$n = current_user_can( Scout_Members_Admin::CAP ) ? self::pending_count() : 0;
		if ( $n ) {
			$items   = (array) $items;
			$items[] = array(
				/* translators: %d: number of invitations. */
				'text'   => sprintf( _n( '%d member invitation to read.', '%d member invitations to read.', $n, 'scout-members' ), $n ),
				'url'    => admin_url( 'edit.php?post_type=' . self::TYPE . '&post_status=pending' ),
				'action' => __( 'Read invitations', 'scout-members' ),
			);
		}
		return (array) $items;
	}

	/* ---------- privacy ---------- */

	private static function ids_by( string $email ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::TYPE,
					// Taken-down invitations sit in the trash and still belong to the member.
					'post_status'    => array( 'publish', 'pending', 'draft', 'private', 'trash' ),
					'author'         => (int) $user->ID,
					'posts_per_page' => 200,
					'fields'         => 'ids',
				)
			)
		);
	}

	public static function exporter( array $exporters ): array {
		$exporters['scout-members-invitations'] = array(
			'exporter_friendly_name' => __( 'Member invitations', 'scout-members' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function export_data( string $email ): array {
		$data = array();
		foreach ( self::ids_by( $email ) as $id ) {
			$rows = array(
				array(
					'name'  => __( 'Title', 'scout-members' ),
					'value' => get_the_title( $id ),
				),
			);
			foreach ( self::answers( $id ) as $q => $a ) {
				$rows[] = array(
					'name'  => $q,
					'value' => $a,
				);
			}
			$data[] = array(
				'group_id'    => 'scout-members-invitations',
				'group_label' => __( 'Member invitations', 'scout-members' ),
				'item_id'     => 'invitation-' . $id,
				'data'        => $rows,
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	public static function eraser( array $erasers ): array {
		$erasers['scout-members-invitations'] = array(
			'eraser_friendly_name' => __( 'Member invitations', 'scout-members' ),
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	public static function erase_data( string $email ): array {
		$ids = self::ids_by( $email );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		return array(
			'items_removed'  => count( $ids ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
