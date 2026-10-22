<?php
/**
 * The member's side of the site.
 *
 * [scout_membership] is the one membership page: the application for anyone
 * who has not applied, and the member's own account once they have (status,
 * plan, renewal date, members-only events). [scout_members_only]...[/] hides
 * part of a page from non-members, and the "Members only" box on a page or
 * post hides the whole of it. Members never see the dashboard.
 *
 * @package Scout_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Members_Front {

	const MIN_SECONDS = 4;

	public static function boot(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ) );
		add_shortcode( 'scout_membership', array( __CLASS__, 'membership' ) );
		add_shortcode( 'scout_members_only', array( __CLASS__, 'members_only' ) );
		add_action( 'admin_post_nopriv_scout_members_apply', array( __CLASS__, 'apply' ) );
		add_action( 'admin_post_scout_members_apply', array( __CLASS__, 'apply' ) );
		add_filter( 'the_content', array( __CLASS__, 'gate_content' ), 1 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'gate_excerpt' ), 20, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_admin' ), 1 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
	}

	public static function register(): void {
		wp_register_style( 'scout-members', SCOUT_MEMBERS_URL . 'assets/scout-members.css', array(), SCOUT_MEMBERS_VERSION );
	}

	/** True for a signed-in person whose only job here is being a member. */
	private static function member_only_user(): bool {
		$user = wp_get_current_user();
		return $user->exists() && in_array( Scout_Members::ROLE, (array) $user->roles, true ) && ! current_user_can( 'edit_posts' );
	}

	/* ---------- the membership page ---------- */

	public static function membership(): string {
		wp_enqueue_style( 'scout-members' );
		// The first page that shows the membership becomes "the" membership page until Settings says otherwise.
		if ( is_singular() && ! Scout_Members::settings()['page'] ) {
			Scout_Members::save_settings( array_merge( Scout_Members::settings(), array( 'page' => (int) get_the_ID() ) ) );
		}
		$user = wp_get_current_user();
		$out  = '<div class="sm-portal" id="sm-portal">' . self::notice();
		$m    = $user->exists() ? Scout_Members::member( $user->ID ) : null;
		$sent = isset( $_GET['membership'] ) && 'applied' === $_GET['membership']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( $m && '' !== $m['status'] ) {
			$out .= self::account( $user, $m );
		} elseif ( ! $sent ) {
			$out .= Scout_Members_Form::render( $user );
		}
		return $out . '</div>';
	}

	private static function notice(): string {
		$msg   = isset( $_GET['membership'] ) ? sanitize_key( wp_unslash( $_GET['membership'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$texts = array(
			'applied'  => __( 'Thank you. Your application is in. We will write to you by email once it has been read.', 'scout-members' ),
			'exists'   => __( 'That email already has an account here. Sign in first, then send the application.', 'scout-members' ),
			'missing'  => __( 'A few required answers are missing. Please check the form and send it again.', 'scout-members' ),
			'tooquick' => __( 'That was very fast. Please check your answers and send the form again.', 'scout-members' ),
		);
		if ( ! isset( $texts[ $msg ] ) ) {
			return '';
		}
		return '<p class="sm-note ' . ( 'applied' === $msg ? 'is-good' : 'is-bad' ) . '" role="status">' . esc_html( $texts[ $msg ] ) . '</p>';
	}

	private static function account( WP_User $user, array $m ): string {
		$status = $m['status'];
		if ( 'active' === $status && ! Scout_Members::is_member( $user->ID ) ) {
			$status = 'lapsed';
		}
		$titles = array(
			'applied'  => __( 'Your application is being read', 'scout-members' ),
			'active'   => __( 'You are a member', 'scout-members' ),
			'lapsed'   => __( 'Your membership is due for renewal', 'scout-members' ),
			'paused'   => __( 'Your membership is paused', 'scout-members' ),
			'ended'    => __( 'Your membership has ended', 'scout-members' ),
			'declined' => __( 'Thank you for applying', 'scout-members' ),
		);
		$lines = array(
			'applied'  => __( 'We will write to you by email once it has been read. Nothing else is needed from you for now.', 'scout-members' ),
			'active'   => __( 'Welcome. Members-only pages and events open for you whenever you are signed in.', 'scout-members' ),
			'lapsed'   => __( 'Renew to keep members-only pages and events open. Get in touch if you have questions.', 'scout-members' ),
			'paused'   => __( 'Members-only pages and events are closed while it is paused. Get in touch to pick it back up.', 'scout-members' ),
			'ended'    => __( 'Thank you for being part of it. Get in touch any time you would like to come back.', 'scout-members' ),
			'declined' => __( 'This membership is not the right fit at the moment. Thank you for the care you put into your answers.', 'scout-members' ),
		);
		$facts = array();
		if ( '' !== $m['plan'] && '' !== Scout_Members::plan_label( $m['plan'] ) && in_array( $status, array( 'active', 'lapsed', 'paused' ), true ) ) {
			$facts[ __( 'Plan', 'scout-members' ) ] = Scout_Members::plan_label( $m['plan'] );
		}
		if ( '' !== $m['since'] && 'declined' !== $status ) {
			$facts[ __( 'Member since', 'scout-members' ) ] = Scout_Members::date_label( $m['since'] );
		}
		if ( '' !== $m['renews'] && in_array( $status, array( 'active', 'lapsed' ), true ) ) {
			$facts[ __( 'Renews', 'scout-members' ) ] = Scout_Members::date_label( $m['renews'] );
		}
		ob_start();
		?>
		<div class="sm-account is-<?php echo esc_attr( $status ); ?>">
			<p class="sm-badge"><?php echo esc_html( $titles[ $status ] ); ?></p>
			<p class="sm-lede"><?php echo esc_html( $lines[ $status ] ); ?></p>
			<?php if ( ( 'active' === $status && '' === $m['paid'] ) || 'lapsed' === $status ) : ?>
				<?php $next = (string) get_option( 'scout_members_next_step', '' ); ?>
				<?php if ( '' !== trim( $next ) ) : ?>
					<div class="sm-next"><?php echo wp_kses_post( wpautop( make_clickable( esc_html( $next ) ) ) ); ?></div>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $facts ) : ?>
				<dl class="sm-facts">
					<?php foreach ( $facts as $label => $value ) : ?>
						<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd></div>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
			<?php
			if ( 'active' === $status ) {
				/** Members-only events and anything else a member should see on their account. */
				echo (string) apply_filters( 'scout_members_account_extra', '', $user->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each source escapes its own markup.
			}
			?>
			<p class="sm-help"><?php echo esc_html( $user->display_name ); ?> &middot; <a href="<?php echo esc_url( wp_logout_url( Scout_Members::page_url() ) ); ?>"><?php esc_html_e( 'Sign out', 'scout-members' ); ?></a></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------- members-only content ---------- */

	/** The box non-members see in place of members-only content. */
	public static function gate( string $what = '' ): string {
		wp_enqueue_style( 'scout-members' );
		$user = wp_get_current_user();
		$m    = $user->exists() ? Scout_Members::member( $user->ID ) : null;
		$out  = '<div class="sm-gate"><p class="sm-gate-title">' . esc_html__( 'For members', 'scout-members' ) . '</p>';
		if ( $m && 'applied' === $m['status'] ) {
			$out .= '<p>' . esc_html__( 'This opens once your membership application is approved.', 'scout-members' ) . '</p>';
		} elseif ( $m && in_array( $m['status'], array( 'active', 'paused' ), true ) ) {
			$out .= '<p>' . esc_html__( 'This opens again once your membership is active.', 'scout-members' ) . '</p><p><a href="' . esc_url( Scout_Members::page_url() ) . '">' . esc_html__( 'See your membership', 'scout-members' ) . '</a></p>';
		} else {
			$out .= '<p>' . esc_html( '' !== $what ? $what : __( 'This part of the site is for members.', 'scout-members' ) ) . '</p>';
			$out .= '<p class="sm-gate-actions"><a class="sm-button wp-element-button" href="' . esc_url( Scout_Members::page_url() ) . '">' . esc_html__( 'Become a member', 'scout-members' ) . '</a>';
			if ( ! $user->exists() ) {
				$out .= ' <a class="sm-signin" href="' . esc_url( wp_login_url( (string) get_permalink() ) ) . '">' . esc_html__( 'Sign in', 'scout-members' ) . '</a>';
			}
			$out .= '</p>';
		}
		return $out . '</div>';
	}

	public static function members_only( $atts, $content = '' ): string {
		if ( Scout_Members::can_see() ) {
			return do_shortcode( (string) $content );
		}
		return self::gate();
	}

	/**
	 * A page or post whose whole content is for members. Events marked members
	 * only stay readable; only their ticket button is held back.
	 */
	private static function gated( int $id ): bool {
		return $id && in_array( get_post_type( $id ), array( 'page', 'post' ), true ) && Scout_Members::is_members_only( $id );
	}

	/** A page or post marked members only shows the gate instead of its content. */
	public static function gate_content( $content ) {
		$id = (int) get_the_ID();
		if ( ! self::gated( $id ) || Scout_Members::can_see() ) {
			return $content;
		}
		// Keep the gate from being run through the rest of the content filters as page text.
		return self::gate( __( 'This page is for members.', 'scout-members' ) );
	}

	public static function gate_excerpt( $excerpt, $post = null ) {
		$id = $post instanceof WP_Post ? $post->ID : (int) get_the_ID();
		return self::gated( (int) $id ) && ! Scout_Members::can_see() ? '' : $excerpt;
	}

	/** Search engines skip members-only pages. */
	public static function robots( array $robots ): array {
		if ( is_singular() && self::gated( (int) get_queried_object_id() ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	/* ---------- the application ---------- */

	private static function back( string $msg ): void {
		$ref = wp_get_referer();
		$url = $ref ? remove_query_arg( 'membership', $ref ) : Scout_Members::page_url();
		wp_safe_redirect( add_query_arg( 'membership', $msg, $url ) . '#sm-portal' );
		exit;
	}

	public static function apply(): void {
		$nonce = isset( $_POST['_sm_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_sm_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'scout_members_apply' ) ) {
			wp_die( esc_html__( 'This form has expired. Go back, reload the page and send it again.', 'scout-members' ), 400 );
		}
		// Bots get a quiet "applied" so they learn nothing.
		if ( ! empty( $_POST['sm_website2'] ) ) {
			self::back( 'applied' );
		}
		$t = isset( $_POST['sm_t'] ) ? (int) $_POST['sm_t'] : 0;
		if ( $t <= 0 || time() - $t < self::MIN_SECONDS ) {
			self::back( 'tooquick' );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is cleaned where it is read.
		$m     = isset( $_POST['m'] ) ? (array) wp_unslash( $_POST['m'] ) : array();
		$first = sanitize_text_field( (string) ( $m['first'] ?? '' ) );
		$last  = sanitize_text_field( (string) ( $m['last'] ?? '' ) );
		$email = sanitize_email( (string) ( $m['email'] ?? '' ) );
		$s     = Scout_Members::settings();
		$ans   = Scout_Members_Form::answers( $m );
		if ( '' === $first || '' === $last || ! is_email( $email ) || null === $ans
			|| ( $s['min_age'] > 0 && empty( $m['age'] ) )
			|| ( '' !== $s['agreements_url'] && empty( $m['agreements'] ) ) ) {
			self::back( 'missing' );
		}
		if ( $s['min_age'] > 0 ) {
			/* translators: %d: minimum age. */
			$ans[ sprintf( __( 'Is %d or older', 'scout-members' ), $s['min_age'] ) ] = __( 'Yes', 'scout-members' );
		}
		if ( '' !== $s['agreements_url'] ) {
			$ans[ __( 'Agrees to the community agreements', 'scout-members' ) ] = __( 'Yes', 'scout-members' );
		}

		$user_id = get_current_user_id();
		$new     = false;
		if ( ! $user_id ) {
			if ( email_exists( $email ) ) {
				self::back( 'exists' );
			}
			$login   = sanitize_user( strtok( $email, '@' ), true );
			$login   = '' === $login || username_exists( $login ) ? $login . wp_rand( 100, 999 ) : $login;
			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 24 ),
					'first_name'   => $first,
					'last_name'    => $last,
					'display_name' => trim( $first . ' ' . $last ),
					'role'         => Scout_Members::ROLE,
				)
			);
			if ( is_wp_error( $user_id ) ) {
				self::back( 'missing' );
			}
			$new = true;
		} else {
			$current = Scout_Members::member( $user_id );
			if ( in_array( $current['status'], array( 'applied', 'active', 'paused' ), true ) ) {
				wp_safe_redirect( Scout_Members::page_url() );
				exit;
			}
		}
		$user_id = (int) $user_id;
		$plan    = sanitize_title( (string) ( $m['plan'] ?? '' ) );
		Scout_Members::save(
			$user_id,
			array(
				'applied' => Scout_Members::today(),
				'plan'    => isset( Scout_Members::plans()[ $plan ] ) ? $plan : '',
				'answers' => $ans,
			)
		);
		Scout_Members::set_status( $user_id, 'applied' );

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		if ( $new ) {
			// The set-password email, so they can sign in to see where things stand.
			wp_new_user_notification( $user_id, null, 'user' );
		}
		/* translators: %s: site name. */
		wp_mail( $email, sprintf( __( 'We have your membership application for %s', 'scout-members' ), $site ), __( "Thank you for applying. A person reads every application, and we will write to you here once yours has been read.\n\nYou can check where it stands any time by signing in:\n", 'scout-members' ) . Scout_Members::page_url() );
		// Names only: the answers stay on the site, never in an email.
		/* translators: %s: applicant name. */
		wp_mail( Scout_Members::notify_email(), sprintf( __( 'New membership application: %s', 'scout-members' ), trim( $first . ' ' . $last ) ), __( "A new membership application is waiting. Read it and decide here:\n", 'scout-members' ) . Scout_Members_Admin::url() );
		self::back( 'applied' );
	}

	/* ---------- members stay on the site ---------- */

	public static function keep_out_of_admin(): void {
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		global $pagenow;
		if ( 'admin-post.php' === $pagenow || ! self::member_only_user() ) {
			return;
		}
		wp_safe_redirect( Scout_Members::page_url() );
		exit;
	}

	public static function admin_bar( $show ) {
		return self::member_only_user() ? false : $show;
	}

	public static function login_redirect( $to, $requested, $user ) {
		if ( $user instanceof WP_User && in_array( Scout_Members::ROLE, (array) $user->roles, true ) && ! user_can( $user, 'edit_posts' ) && ( '' === (string) $requested || false !== strpos( (string) $requested, 'wp-admin' ) ) ) {
			return Scout_Members::page_url();
		}
		return $to;
	}
}
