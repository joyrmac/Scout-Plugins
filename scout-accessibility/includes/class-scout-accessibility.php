<?php
/**
 * The statement: settings, the [scout_accessibility_statement] shortcode, the
 * statement page itself and the footer link.
 *
 * No overlay widgets, ever. This plugin adds no toolbar, no script that
 * rewrites the page, and nothing that claims to make a site compliant.
 *
 * @package Scout_Accessibility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Accessibility {

	const OPTION    = 'scout_accessibility';
	const SHORTCODE = 'scout_accessibility_statement';
	const LINK_CODE = 'scout_accessibility_link';
	const STANDARD  = 'WCAG 2.2 AA';

	/** The content a new statement page starts with. */
	const PAGE_CONTENT = "<!-- wp:shortcode -->\n[scout_accessibility_statement]\n<!-- /wp:shortcode -->";

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'fill_description' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'footer_style' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer_link' ), 50 );
	}

	/** The footer link's few lines of style, only when that link is on. */
	public static function footer_style(): void {
		if ( ! empty( self::settings()['footer_link'] ) ) {
			wp_enqueue_style( 'scout-accessibility' );
		}
	}

	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'statement' ) );
		add_shortcode( self::LINK_CODE, array( __CLASS__, 'link_shortcode' ) );
		wp_register_style( 'scout-accessibility', SCOUT_ACCESSIBILITY_URL . 'assets/statement.css', array(), SCOUT_ACCESSIBILITY_VERSION );
	}

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'email'         => '',
			'phone'         => '',
			'response_days' => 0,
			'other_formats' => 0,
			'last_reviewed' => '',
			'page_id'       => 0,
			'footer_link'   => 0,
		);
	}

	/** @return array<string,mixed> */
	public static function settings(): array {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	/** True when scout-core's business info is available. */
	public static function has_core(): bool {
		return class_exists( 'Scout_Core_Business', false );
	}

	/** @return array<string,string> */
	private static function core_business(): array {
		return self::has_core() ? (array) Scout_Core_Business::get() : array();
	}

	/** Business name from scout-core, else the site name. */
	public static function business_name(): string {
		$core = self::core_business();
		$name = trim( (string) ( $core['name'] ?? '' ) );
		return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/** Contact email: this screen first, then scout-core, then nothing. */
	public static function email(): string {
		$s = self::settings();
		if ( '' !== $s['email'] ) {
			return $s['email'];
		}
		return sanitize_email( (string) ( self::core_business()['email'] ?? '' ) );
	}

	/** Contact phone: this screen first, then scout-core. */
	public static function phone(): string {
		$s = self::settings();
		if ( '' !== $s['phone'] ) {
			return $s['phone'];
		}
		return trim( (string) ( self::core_business()['phone'] ?? '' ) );
	}

	/** The statement page, if it exists and is not in the trash. */
	public static function page(): ?WP_Post {
		$id = (int) self::settings()['page_id'];
		if ( $id ) {
			$post = get_post( $id );
			if ( $post instanceof WP_Post && 'page' === $post->post_type && 'trash' !== $post->post_status ) {
				return $post;
			}
		}
		return null;
	}

	public static function page_url(): string {
		$page = self::page();
		return ( $page && 'publish' === $page->post_status ) ? (string) get_permalink( $page ) : '';
	}

	/**
	 * Create the Accessibility page, or make sure the existing one is
	 * published and still carries the statement. Edits around the shortcode
	 * are kept.
	 */
	public static function ensure_page(): int {
		$s    = self::settings();
		$page = self::page();
		if ( ! $page ) {
			$found = get_page_by_path( 'accessibility' );
			if ( $found instanceof WP_Post && 'trash' !== $found->post_status ) {
				$page = $found;
			}
		}
		if ( $page ) {
			$update = array( 'ID' => $page->ID );
			if ( ! has_shortcode( $page->post_content, self::SHORTCODE ) ) {
				$update['post_content'] = self::PAGE_CONTENT . ( '' !== trim( $page->post_content ) ? "\n\n" . $page->post_content : '' );
			}
			if ( 'publish' !== $page->post_status ) {
				$update['post_status'] = 'publish';
			}
			if ( ! has_excerpt( $page ) ) {
				$update['post_excerpt'] = self::description();
			}
			if ( count( $update ) > 1 ) {
				wp_update_post( wp_slash( $update ) );
			}
			$id = $page->ID;
		} else {
			$id = (int) wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_title'   => __( 'Accessibility', 'scout-accessibility' ),
						'post_name'    => 'accessibility',
						'post_content' => self::PAGE_CONTENT,
						'post_excerpt' => self::description(),
					)
				)
			);
		}
		if ( $id ) {
			$s['page_id'] = $id;
			update_option( self::OPTION, $s );
		}
		return $id;
	}

	/** The page's search description, so search engines don't have to guess one. */
	public static function description(): string {
		$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name */
		return sprintf( __( 'How %s keeps this website usable for everyone, what we check, and how to reach us if something doesn\'t work for you.', 'scout-accessibility' ), $name );
	}

	/**
	 * Give an existing statement page its description once. Sites that made the
	 * page before 0.1.6 have none, and Site health flags it.
	 */
	public static function fill_description(): void {
		if ( get_option( 'scout_accessibility_desc_v1' ) || ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		$page = self::page();
		if ( $page && ! has_excerpt( $page ) && '' === (string) get_post_meta( $page->ID, '_scout_seo_desc', true ) ) {
			wp_update_post(
				array(
					'ID'           => $page->ID,
					'post_excerpt' => self::description(),
				)
			);
		}
		update_option( 'scout_accessibility_desc_v1', 1, false );
	}

	/** Format a phone number for a tel: link. */
	private static function tel( string $phone ): string {
		$digits = preg_replace( '/[^0-9+]/', '', $phone );
		return 'tel:' . $digits;
	}

	/** True when both at-launch reviews are ticked. */
	public static function launch_done(): bool {
		$launch = Scout_Accessibility_Checks::launch();
		foreach ( Scout_Accessibility_Checks::launch_items() as $key => $item ) {
			if ( empty( $launch[ $key ]['done'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * [scout_accessibility_statement]: the statement itself.
	 *
	 * @param array|string $atts Unused.
	 */
	public static function statement( $atts = array() ): string {
		wp_enqueue_style( 'scout-accessibility' );
		$s     = self::settings();
		$name  = self::business_name();
		$email = self::email();
		$phone = self::phone();
		$days  = (int) $s['response_days'];

		ob_start();
		?>
		<div class="scout-a11y">
			<h2><?php esc_html_e( 'We want everyone to be able to use this site', 'scout-accessibility' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: business name */
					esc_html__( '%s aims to meet the Web Content Accessibility Guidelines (WCAG) 2.2 at level AA. These guidelines describe how to make a website work for people who use screen readers, keyboards, zoom and other tools.', 'scout-accessibility' ),
					esc_html( $name )
				);
				?>
			</p>

			<h2><?php esc_html_e( 'How we check the site', 'scout-accessibility' ); ?></h2>
			<p><?php esc_html_e( 'Every week, automated checks look for images without descriptions, headings out of order and form fields without labels, and we work through what they find.', 'scout-accessibility' ); ?>
			<?php if ( self::launch_done() ) : ?>
				<?php esc_html_e( 'Color contrast and keyboard use need a real browser, so we review those by hand.', 'scout-accessibility' ); ?>
			<?php endif; ?></p>

			<h2><?php esc_html_e( 'Where the site may fall short', 'scout-accessibility' ); ?></h2>
			<p><?php esc_html_e( 'Some parts of the site may not meet the guidelines yet, such as older pages, documents, or videos and tools from other companies. We keep improving them as we find them.', 'scout-accessibility' ); ?></p>

			<h2><?php esc_html_e( 'Tell us if something gets in your way', 'scout-accessibility' ); ?></h2>
			<p><?php esc_html_e( 'If something on this site is hard to use, please let us know. Tell us which page you were on and what happened, and we will work with you to get you what you need.', 'scout-accessibility' ); ?></p>
			<?php if ( '' !== $email || '' !== $phone ) : ?>
				<ul class="scout-a11y-contact">
					<?php if ( '' !== $email ) : ?>
						<li><a class="scout-a11y-button" href="<?php echo esc_url( 'mailto:' . $email ); ?>" data-scout-track="accessibility_contact" data-scout-label="email">
							<?php
							/* translators: %s: email address */
							printf( esc_html__( 'Email %s', 'scout-accessibility' ), esc_html( $email ) );
							?>
						</a></li>
					<?php endif; ?>
					<?php if ( '' !== $phone ) : ?>
						<li><a class="scout-a11y-button" href="<?php echo esc_url( self::tel( $phone ) ); ?>" data-scout-track="accessibility_contact" data-scout-label="phone">
							<?php
							/* translators: %s: phone number */
							printf( esc_html__( 'Call %s', 'scout-accessibility' ), esc_html( $phone ) );
							?>
						</a></li>
					<?php endif; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $days > 0 ) : ?>
				<p>
					<?php
					printf(
						/* translators: %d: number of business days */
						esc_html( _n( 'We aim to reply within %d business day.', 'We aim to reply within %d business days.', $days, 'scout-accessibility' ) ),
						(int) $days
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $s['other_formats'] ) ) : ?>
				<p><?php esc_html_e( 'We can also send you any page in another format.', 'scout-accessibility' ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $s['last_reviewed'] ) : ?>
				<p class="scout-a11y-reviewed">
					<?php
					printf(
						/* translators: %s: month and year */
						esc_html__( 'This statement was last reviewed in %s.', 'scout-accessibility' ),
						esc_html( date_i18n( 'F Y', strtotime( $s['last_reviewed'] . ' 12:00:00' ) ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** The footer link markup, or nothing while there is no published page. */
	public static function link_html(): string {
		$url = self::page_url();
		if ( '' === $url ) {
			return '';
		}
		return '<a class="scout-a11y-link" href="' . esc_url( $url ) . '">' . esc_html__( 'Accessibility', 'scout-accessibility' ) . '</a>';
	}

	/**
	 * [scout_accessibility_link]: for a footer widget, block or menu area.
	 *
	 * @param array|string $atts Unused.
	 */
	public static function link_shortcode( $atts = array() ): string {
		return self::link_html();
	}

	/** Optional small link printed at the very end of every page. */
	public static function footer_link(): void {
		if ( empty( self::settings()['footer_link'] ) || is_admin() ) {
			return;
		}
		$link = self::link_html();
		if ( '' === $link ) {
			return;
		}
		echo '<p class="scout-a11y-footer">' . $link . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in link_html().
	}
}
