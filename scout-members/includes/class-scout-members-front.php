<?php
/**
 * The member's side of the site.
 *
 * [scout_membership] is the one membership page: the application for anyone
 * who has not applied, and the member's own account once they have (status,
 * plan, renewal date, members-only events). [scout_members_only]...[/] hides
 * part of a page from non-members, and the "Members only" box on a page or
 * post hides the whole of it, for every member or only some plans.
 *
 * The members home (Settings) is where members land after signing in. It and
 * every page under it are members only, and it lists the pages under it that
 * the member can open, so adding a page there is all it takes to add a perk.
 *
 * Members sign in on the site itself ([scout_member_signin], and inside every
 * members-only note), and [scout_member_link] or a menu link to
 * #scout-member-link reads "Member sign in" or "Members home" as it should.
 * Members never see the dashboard.
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
		add_shortcode( 'scout_members_home', array( __CLASS__, 'home' ) );
		add_shortcode( 'scout_member_signin', array( __CLASS__, 'signin_shortcode' ) );
		add_shortcode( 'scout_member_link', array( __CLASS__, 'link_shortcode' ) );
		add_action( 'admin_post_nopriv_scout_members_signin', array( __CLASS__, 'signin' ) );
		add_action( 'admin_post_scout_members_signin', array( __CLASS__, 'signin' ) );
		add_filter( 'the_content', array( __CLASS__, 'home_content' ), 30 );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'menu_link' ) );
		add_filter( 'render_block_core/navigation-link', array( __CLASS__, 'block_link' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap' ), 10, 2 );
		add_filter( 'get_post_metadata', array( __CLASS__, 'seo_meta' ), 10, 3 );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_style' ) );
		add_filter( 'login_headerurl', static fn() => home_url( '/' ) );
		add_filter( 'login_headertext', static fn() => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
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
		if ( ! $user->exists() ) {
			// Outside the application form: forms cannot sit inside forms.
			$open = isset( $_GET['membership'] ) && 'signin' === $_GET['membership']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$out .= '<details class="sm-signin-more"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html__( 'Already a member? Sign in', 'scout-members' ) . '</summary>' . self::signin_form( Scout_Members::home_url() ) . '</details>';
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
			<?php if ( 'active' === $status && Scout_Members::home_id() && Scout_Members::home_id() !== (int) get_the_ID() ) : ?>
				<p><a class="sm-button wp-element-button" href="<?php echo esc_url( Scout_Members::home_url() ); ?>"><?php esc_html_e( 'Go to the members home', 'scout-members' ); ?></a></p>
			<?php endif; ?>
			<p class="sm-help"><?php echo esc_html( $user->display_name ); ?> &middot; <a href="<?php echo esc_url( wp_logout_url( Scout_Members::page_url() ) ); ?>"><?php esc_html_e( 'Sign out', 'scout-members' ); ?></a></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------- members-only content ---------- */

	/** The box non-members see in place of members-only content. */
	/**
	 * The note people see in place of members-only content: how to join, and
	 * the sign-in form right there. $plans names the plans a page is limited to.
	 */
	public static function gate( string $what = '', array $plans = array() ): string {
		wp_enqueue_style( 'scout-members' );
		$user = wp_get_current_user();
		$m    = $user->exists() ? Scout_Members::member( $user->ID ) : null;
		$out  = '<div class="sm-gate"><p class="sm-gate-title">' . esc_html__( 'For members', 'scout-members' ) . '</p>';
		if ( $m && 'applied' === $m['status'] ) {
			$out .= '<p>' . esc_html__( 'This opens once your membership application is approved.', 'scout-members' ) . '</p>';
		} elseif ( $plans && Scout_Members::is_member() ) {
			/* translators: %s: plan names, such as "VIP Member". */
			$out .= '<p>' . esc_html( sprintf( __( 'This is part of the %s membership.', 'scout-members' ), Scout_Members::plan_names( $plans ) ) ) . '</p><p><a href="' . esc_url( Scout_Members::page_url() ) . '">' . esc_html__( 'See your membership', 'scout-members' ) . '</a></p>';
		} elseif ( $m && in_array( $m['status'], array( 'active', 'paused' ), true ) ) {
			$out .= '<p>' . esc_html__( 'This opens again once your membership is active.', 'scout-members' ) . '</p><p><a href="' . esc_url( Scout_Members::page_url() ) . '">' . esc_html__( 'See your membership', 'scout-members' ) . '</a></p>';
		} else {
			$out .= '<p>' . esc_html( '' !== $what ? $what : __( 'This part of the site is for members.', 'scout-members' ) ) . '</p>';
			if ( $user->exists() ) {
				$out .= '<p class="sm-gate-actions"><a class="sm-button wp-element-button" href="' . esc_url( Scout_Members::page_url() ) . '">' . esc_html__( 'Become a member', 'scout-members' ) . '</a></p>';
			} else {
				$out .= self::signin_form( (string) get_permalink() );
				$out .= '<p class="sm-help">' . esc_html__( 'Not a member yet?', 'scout-members' ) . ' <a href="' . esc_url( Scout_Members::page_url() ) . '">' . esc_html__( 'Apply to join', 'scout-members' ) . '</a></p>';
			}
		}
		return $out . '</div>';
	}

	/** [scout_members_only plan="VIP Member"] … [/scout_members_only]; plan is optional, names separated by commas. */
	public static function members_only( $atts, $content = '' ): string {
		$atts  = shortcode_atts( array( 'plan' => '' ), (array) $atts );
		$plans = array_values( array_filter( array_map( static fn( $n ) => Scout_Members::plan_by_name( trim( $n ) ), explode( ',', (string) $atts['plan'] ) ) ) );
		if ( Scout_Members::can_see() && Scout_Members::plan_allowed( current_user_can( 'edit_posts' ) ? array() : $plans ) ) {
			return do_shortcode( (string) $content );
		}
		return self::gate( '', $plans );
	}

	/**
	 * A page or post whose whole content is for members. Events marked members
	 * only stay readable; only their ticket button is held back.
	 */
	private static function gated( int $id ): bool {
		return $id && in_array( get_post_type( $id ), array( 'page', 'post' ), true ) && Scout_Members::locked( $id );
	}

	/** A page or post marked members only shows the gate instead of its content. */
	public static function gate_content( $content ) {
		$id = (int) get_the_ID();
		if ( ! self::gated( $id ) || Scout_Members::can_see( $id ) ) {
			return $content;
		}
		// Keep the gate from being run through the rest of the content filters as page text.
		return self::gate( __( 'This page is for members.', 'scout-members' ), Scout_Members::plans_for( $id ) );
	}

	public static function gate_excerpt( $excerpt, $post = null ) {
		$id = $post instanceof WP_Post ? $post->ID : (int) get_the_ID();
		return self::gated( (int) $id ) && ! Scout_Members::can_see( (int) $id ) ? '' : $excerpt;
	}

	/** Search engines skip members-only pages. */
	public static function robots( array $robots ): array {
		if ( is_singular() && self::gated( (int) get_queried_object_id() ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	/** Sitemaps leave out the members area. */
	public static function sitemap( array $args, string $type ): array {
		if ( in_array( $type, array( 'page', 'post' ), true ) ) {
			$locked = Scout_Members::locked_ids();
			if ( $locked ) {
				$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), $locked );
			}
		}
		return $args;
	}

	/**
	 * Scout's SEO and AI-search plugins read a page's own text and its "hide
	 * from search" setting directly. For a members-only page they get "hide"
	 * always (so it stays out of llms.txt and search), and a plain description
	 * in place of the page's words for anyone who cannot open it, so share
	 * cards and the description tag never quote members-only text.
	 */
	public static function seo_meta( $value, $object_id, $meta_key ) {
		$noindex = defined( 'SCOUT_SEO_META_NOINDEX' ) ? SCOUT_SEO_META_NOINDEX : '';
		$desc    = defined( 'SCOUT_SEO_META_DESC' ) ? SCOUT_SEO_META_DESC : '';
		if ( null !== $value || '' === (string) $meta_key || ! in_array( $meta_key, array( $noindex, $desc ), true ) ) {
			return $value;
		}
		$id = (int) $object_id;
		if ( ! in_array( get_post_type( $id ), array( 'page', 'post' ), true ) || ! Scout_Members::locked( $id ) ) {
			return $value;
		}
		if ( $meta_key === $noindex ) {
			// Not on edit screens, where saving would copy it onto the page for good.
			return is_admin() ? $value : array( '1' );
		}
		return is_admin() || Scout_Members::can_see( $id ) ? $value : array( __( 'A page for members.', 'scout-members' ) );
	}

	/* ---------- the members home ---------- */

	/** On the members home, the list of what is theirs follows the page's own words. */
	public static function home_content( $content ) {
		$id = (int) get_the_ID();
		if ( ! $id || Scout_Members::home_id() !== $id || ! in_the_loop() || ! is_main_query() || has_shortcode( (string) $content, 'scout_members_home' ) || ! Scout_Members::can_see( $id ) ) {
			return $content;
		}
		return $content . self::home();
	}

	/**
	 * [scout_members_home] the pages under the members home that this member
	 * can open, each with its summary, then the members-only events and a link
	 * to their own membership.
	 */
	public static function home(): string {
		$home = Scout_Members::home_id();
		if ( ! $home ) {
			return '';
		}
		if ( ! Scout_Members::can_see( $home ) ) {
			return self::gate( __( 'This page is for members.', 'scout-members' ) );
		}
		wp_enqueue_style( 'scout-members' );
		$pages = array_filter(
			get_pages(
				array(
					'parent'      => $home,
					'sort_column' => 'menu_order,post_title',
				)
			),
			static fn( $p ) => Scout_Members::can_see( (int) $p->ID )
		);
		$user = wp_get_current_user();
		$out  = '<div class="sm-home" id="sm-home">';
		if ( $pages ) {
			$out .= '<ul class="sm-perks">';
			foreach ( $pages as $p ) {
				$plans = Scout_Members::plans_for( (int) $p->ID );
				$out  .= '<li class="sm-perk"><a href="' . esc_url( (string) get_permalink( $p ) ) . '"><span class="sm-perk-title">' . esc_html( get_the_title( $p ) ) . '</span>';
				if ( $plans ) {
					$out .= '<span class="sm-perk-tag">' . esc_html( Scout_Members::plan_names( $plans ) ) . '</span>';
				}
				if ( has_excerpt( $p ) ) {
					$out .= '<span class="sm-perk-text">' . esc_html( wp_strip_all_tags( get_the_excerpt( $p ) ) ) . '</span>';
				}
				$out .= '</a></li>';
			}
			$out .= '</ul>';
		} elseif ( current_user_can( 'edit_pages' ) ) {
			$out .= '<p class="sm-note">' . esc_html__( 'Only you see this: add pages with this page as their parent and they show up here for members, with their excerpt as the summary.', 'scout-members' ) . '</p>';
		}
		/** Members-only events and anything else a member should see. */
		$out .= (string) apply_filters( 'scout_members_account_extra', '', (int) $user->ID );
		$out .= '<p class="sm-help"><a href="' . esc_url( Scout_Members::page_url() ) . '">' . esc_html__( 'Your membership', 'scout-members' ) . '</a> &middot; <a href="' . esc_url( wp_logout_url( Scout_Members::page_url() ) ) . '">' . esc_html__( 'Sign out', 'scout-members' ) . '</a></p>';
		return $out . '</div>';
	}

	/* ---------- signing in ---------- */

	/** The sign-in form, on the site. Failed tries come back here with a note. */
	public static function signin_form( string $back = '' ): string {
		wp_enqueue_style( 'scout-members' );
		$back   = '' !== $back ? $back : (string) get_permalink();
		$failed = isset( $_GET['membership'] ) && 'signin' === $_GET['membership']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		static $n = 0;
		++$n;
		$id = 'sm-signin' . ( $n > 1 ? '-' . $n : '' );
		ob_start();
		?>
		<form class="sm-signin-form" id="<?php echo esc_attr( $id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="scout_members_signin">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $back ); ?>">
			<?php if ( $failed ) : ?>
				<p class="sm-note is-bad" role="alert"><?php esc_html_e( 'That email and password did not match. Try again, or set a new password.', 'scout-members' ); ?></p>
			<?php endif; ?>
			<p class="sm-field"><label for="<?php echo esc_attr( $id ); ?>-email"><?php esc_html_e( 'Email', 'scout-members' ); ?></label><input type="text" id="<?php echo esc_attr( $id ); ?>-email" name="log" autocomplete="username" required></p>
			<p class="sm-field"><label for="<?php echo esc_attr( $id ); ?>-pass"><?php esc_html_e( 'Password', 'scout-members' ); ?></label><input type="password" id="<?php echo esc_attr( $id ); ?>-pass" name="pwd" autocomplete="current-password" required></p>
			<p class="sm-check"><label><input type="checkbox" name="rememberme" value="forever"> <?php esc_html_e( 'Keep me signed in on this device', 'scout-members' ); ?></label></p>
			<p class="sm-gate-actions"><button type="submit" class="sm-button wp-element-button"><?php esc_html_e( 'Sign in', 'scout-members' ); ?></button> <a href="<?php echo esc_url( wp_lostpassword_url( $back ) ); ?>"><?php esc_html_e( 'Set or reset your password', 'scout-members' ); ?></a></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/** [scout_member_signin] the form for people signed out, the way in for people signed in. */
	public static function signin_shortcode(): string {
		if ( is_user_logged_in() ) {
			$link = self::member_link();
			return '<p class="sm-signed-in"><a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a></p>';
		}
		return self::signin_form( Scout_Members::home_url() );
	}

	/**
	 * Sign in from the site's own form. Like the WordPress sign-in page, it
	 * carries no form token: a token would go stale on cached pages and lock
	 * members out, and signing in changes nothing a stranger could misuse.
	 */
	public static function signin(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see above.
		$back = isset( $_POST['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ), '' ) : '';
		$user = wp_signon(
			array(
				'user_login'    => isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '',
				'user_password' => isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords are taken as typed.
				'remember'      => ! empty( $_POST['rememberme'] ),
			),
			is_ssl()
		);
		// phpcs:enable
		if ( is_wp_error( $user ) ) {
			$to = '' !== $back ? $back : Scout_Members::page_url();
			wp_safe_redirect( add_query_arg( 'membership', 'signin', $to ) . '#sm-signin' );
			exit;
		}
		$to = '' !== $back ? $back : admin_url();
		wp_safe_redirect( (string) apply_filters( 'login_redirect', $to, $back, $user ) );
		exit;
	}

	/**
	 * What a member link says and where it goes: "Member sign in" for people
	 * signed out, "Members home" for members, "Your membership" for applicants.
	 *
	 * @return array{label:string,url:string}
	 */
	public static function member_link(): array {
		if ( ! is_user_logged_in() ) {
			return array(
				'label' => __( 'Member sign in', 'scout-members' ),
				'url'   => ( Scout_Members::home_id() ? Scout_Members::home_url() : Scout_Members::page_url() ) . '#sm-signin',
			);
		}
		if ( Scout_Members::is_member() && Scout_Members::home_id() ) {
			return array(
				'label' => __( 'Members home', 'scout-members' ),
				'url'   => Scout_Members::home_url(),
			);
		}
		return array(
			'label' => __( 'Your membership', 'scout-members' ),
			'url'   => Scout_Members::page_url(),
		);
	}

	/** [scout_member_link] for a header or footer. */
	public static function link_shortcode(): string {
		$link = self::member_link();
		return '<a class="sm-member-link" href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a>';
	}

	/** A menu item linking to #scout-member-link becomes the member link. */
	public static function menu_link( $items ) {
		foreach ( (array) $items as $item ) {
			if ( isset( $item->url ) && '#scout-member-link' === $item->url ) {
				$link        = self::member_link();
				$item->url   = $link['url'];
				$item->title = $link['label'];
			}
		}
		return $items;
	}

	/** The same in a block theme's Navigation block. */
	public static function block_link( $html, $block ): string {
		if ( '#scout-member-link' !== ( $block['attrs']['url'] ?? '' ) ) {
			return (string) $html;
		}
		$link = self::member_link();
		$html = str_replace( 'href="#scout-member-link"', 'href="' . esc_url( $link['url'] ) . '"', (string) $html );
		return (string) preg_replace( '/(<span class="wp-block-navigation-item__label">).*?(<\/span>)/s', '$1' . esc_html( $link['label'] ) . '$2', $html, 1 );
	}

	/** The WordPress sign-in and password pages carry the site's name and logo, not WordPress's. */
	public static function login_style(): void {
		$logo = (int) get_theme_mod( 'custom_logo' );
		$src  = $logo ? wp_get_attachment_image_url( $logo, 'medium' ) : '';
		$src  = $src ? $src : get_site_icon_url( 192 );
		$css  = $src
			? '#login h1 a{background-image:url(' . esc_url( $src ) . ');background-size:contain;background-position:center;width:100%;max-width:200px;height:96px}'
			: '#login h1 a{background:none;text-indent:0;width:auto;height:auto;font-size:1.4em;font-weight:600;line-height:1.3}';
		wp_add_inline_style( 'login', $css );
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
			return Scout_Members::is_member( $user->ID ) ? Scout_Members::home_url() : Scout_Members::page_url();
		}
		return $to;
	}
}
