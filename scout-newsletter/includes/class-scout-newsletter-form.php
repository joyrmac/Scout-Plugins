<?php
/**
 * A plain sign-up box for any page: [scout_newsletter], or the "Newsletter
 * sign-up" block. It posts through the same consent record and queue as
 * every other sign-up, so it can stand in for a Beehiiv embed one day.
 *
 *   [scout_newsletter heading="Join the newsletter" button="Sign up" name="yes" source="form" tag=""]
 *
 * The words under the button are saved with each sign-up as what they agreed to.
 *
 * @package Scout_Newsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Newsletter_Form {

	const ACTION = 'scout_newsletter_signup';

	public static function boot(): void {
		add_shortcode( 'scout_newsletter', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'block' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'submit' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'submit' ) );
	}

	public static function block(): void {
		wp_register_style( 'scout-newsletter', SCOUT_NEWSLETTER_URL . 'assets/scout-newsletter.css', array(), SCOUT_NEWSLETTER_VERSION );
		wp_register_script(
			'scout-newsletter-block',
			SCOUT_NEWSLETTER_URL . 'assets/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			SCOUT_NEWSLETTER_VERSION,
			true
		);
		register_block_type(
			'scout/newsletter',
			array(
				'api_version'     => 3,
				'title'           => __( 'Newsletter sign-up', 'scout-newsletter' ),
				'category'        => 'widgets',
				'icon'            => 'email',
				'editor_script'   => 'scout-newsletter-block',
				'style'           => 'scout-newsletter',
				'attributes'      => array(
					'heading' => array(
						'type'    => 'string',
						'default' => '',
					),
					'button'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'name'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'tag'     => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => static function ( $attrs ) {
					$attrs = (array) $attrs;
					return self::shortcode(
						array(
							'heading' => (string) ( $attrs['heading'] ?? '' ),
							'button'  => (string) ( $attrs['button'] ?? '' ),
							'name'    => empty( $attrs['name'] ) ? 'no' : 'yes',
							'tag'     => (string) ( $attrs['tag'] ?? '' ),
						)
					);
				},
			)
		);
	}

	public static function shortcode( $atts = array() ): string {
		$a = shortcode_atts(
			array(
				'heading' => '',
				'button'  => '',
				'name'    => 'yes',
				'source'  => 'form',
				'tag'     => '',
			),
			(array) $atts,
			'scout_newsletter'
		);
		static $n = 0;
		++$n;
		wp_enqueue_style( 'scout-newsletter' );
		$id      = 'scout-nl-' . $n;
		$button  = '' !== trim( $a['button'] ) ? $a['button'] : __( 'Sign up', 'scout-newsletter' );
		$state   = isset( $_GET['scout_newsletter'] ) ? sanitize_key( wp_unslash( $_GET['scout_newsletter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$thanks  = trim( (string) Scout_Newsletter::settings()['thanks'] );
		$out     = '<div class="scout-newsletter" id="' . esc_attr( $id ) . '">';
		if ( '' !== trim( $a['heading'] ) ) {
			$out .= '<h2 class="scout-newsletter__heading">' . esc_html( $a['heading'] ) . '</h2>';
		}
		if ( 'thanks' === $state ) {
			$out .= '<p class="scout-newsletter__done" role="status">' . esc_html( '' !== $thanks ? $thanks : __( 'Thank you. Watch your inbox for a note from us.', 'scout-newsletter' ) ) . '</p></div>';
			return $out;
		}
		if ( 'check' === $state ) {
			$out .= '<p class="scout-newsletter__error" role="alert">' . esc_html__( 'Please check your email address and try again.', 'scout-newsletter' ) . '</p>';
		} elseif ( 'again' === $state ) {
			$out .= '<p class="scout-newsletter__error" role="alert">' . esc_html__( 'This page was open for a while. Please enter your email again.', 'scout-newsletter' ) . '</p>';
		}
		$out .= '<form class="scout-newsletter__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
			. wp_nonce_field( self::ACTION, '_scout_nl', true, false )
			. '<input type="hidden" name="nl_source" value="' . esc_attr( sanitize_key( $a['source'] ) ) . '">'
			. '<input type="hidden" name="nl_tag" value="' . esc_attr( $a['tag'] ) . '">'
			. '<input type="hidden" name="nl_sig" value="' . esc_attr( self::sign( sanitize_key( $a['source'] ), (string) $a['tag'] ) ) . '">'
			. '<input type="hidden" name="nl_box" value="' . esc_attr( $id ) . '">';
		if ( 'no' !== $a['name'] ) {
			$out .= '<p class="scout-newsletter__field"><label for="' . esc_attr( $id ) . '-first">' . esc_html__( 'First name', 'scout-newsletter' ) . '</label>'
				. '<input type="text" id="' . esc_attr( $id ) . '-first" name="nl_first" autocomplete="given-name" maxlength="100"></p>';
		}
		$out .= '<p class="scout-newsletter__field"><label for="' . esc_attr( $id ) . '-email">' . esc_html__( 'Email', 'scout-newsletter' ) . '</label>'
			. '<input type="email" id="' . esc_attr( $id ) . '-email" name="nl_email" autocomplete="email" required maxlength="190"></p>'
			. '<p class="scout-newsletter__hp" aria-hidden="true" style="position:absolute;left:-9999px"><label for="' . esc_attr( $id ) . '-site">' . esc_html__( 'Leave this empty', 'scout-newsletter' ) . '</label><input type="text" id="' . esc_attr( $id ) . '-site" name="nl_website" tabindex="-1" autocomplete="off"></p>'
			. '<p class="scout-newsletter__submit"><button type="submit">' . esc_html( $button ) . '</button></p>'
			. '<p class="scout-newsletter__consent">' . esc_html( Scout_Newsletter::wording() ) . '</p>'
			. '</form></div>';
		return $out;
	}

	/** A signature over the box's source and tag, so a visitor cannot pick their own tags. */
	private static function sign( string $source, string $tag ): string {
		return substr( hash_hmac( 'sha256', $source . '|' . $tag, wp_salt( 'nonce' ) ), 0, 20 );
	}

	public static function submit(): void {
		$back = wp_get_referer();
		$back = $back ? $back : home_url( '/' );
		// The nonce field's referer is a path; the consent record keeps the full page address.
		if ( 0 === strpos( $back, '/' ) ) {
			$back = preg_replace( '#^(https?://[^/]+).*$#', '$1', home_url() ) . $back;
		}
		$box  = isset( $_POST['nl_box'] ) ? sanitize_html_class( wp_unslash( $_POST['nl_box'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce = isset( $_POST['_scout_nl'] ) ? sanitize_text_field( wp_unslash( $_POST['_scout_nl'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_safe_redirect( add_query_arg( 'scout_newsletter', 'again', $back ) . ( '' !== $box ? '#' . $box : '' ) );
			exit;
		}
		// A filled hidden field is a bot: thank it and move on.
		if ( ! empty( $_POST['nl_website'] ) ) {
			wp_safe_redirect( add_query_arg( 'scout_newsletter', 'thanks', $back ) . ( '' !== $box ? '#' . $box : '' ) );
			exit;
		}
		$email = isset( $_POST['nl_email'] ) ? sanitize_email( wp_unslash( $_POST['nl_email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( 'scout_newsletter', 'check', $back ) . ( '' !== $box ? '#' . $box : '' ) );
			exit;
		}
		$first  = isset( $_POST['nl_first'] ) ? sanitize_text_field( wp_unslash( $_POST['nl_first'] ) ) : '';
		$source = isset( $_POST['nl_source'] ) ? sanitize_key( wp_unslash( $_POST['nl_source'] ) ) : 'form';
		$tag    = isset( $_POST['nl_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['nl_tag'] ) ) : '';
		$sig    = isset( $_POST['nl_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['nl_sig'] ) ) : '';
		// The source and tag come from the page's own box, never from a visitor.
		if ( ! hash_equals( self::sign( $source, $tag ), $sig ) || ! isset( Scout_Newsletter::sources()[ $source ] ) ) {
			$source = 'form';
			$tag    = '';
		}
		Scout_Newsletter::optin(
			$email,
			$first,
			$source,
			'' !== $tag ? array( $tag ) : array(),
			array(
				'wording' => Scout_Newsletter::wording(),
				'url'     => remove_query_arg( 'scout_newsletter', $back ),
			)
		);
		wp_safe_redirect( add_query_arg( 'scout_newsletter', 'thanks', remove_query_arg( 'scout_newsletter', $back ) ) . ( '' !== $box ? '#' . $box : '' ) );
		exit;
	}
}
