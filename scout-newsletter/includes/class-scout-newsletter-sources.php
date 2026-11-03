<?php
/**
 * Where ticked boxes come from, besides plugins that call the action directly.
 *
 *   Scout Events     scout_events_person with 'newsletter' => true (and
 *                    'newsletter_text' for the words shown), the same moment
 *                    Scout CRM Sync listens to. Sign-ups count as "event";
 *                    volunteers, vendors and presenters as "form".
 *   Tickets          scout_events_ticket_bought, and any paid WooCommerce
 *                    order carrying a ticked box from the checkout.
 *   Scout Members    a ticked newsletter question on the application.
 *   Gravity Forms    a per-form "Newsletter consent field" setting.
 *
 * With "Keep subscribers up to date" on, the same moments also add the type
 * tag (and the member plan field) for people who are already on the list,
 * without ever subscribing anyone.
 *
 * @package Scout_Newsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Newsletter_Sources {

	/** Order meta: the words next to the ticked checkout box. */
	const ORDER_OPTIN = '_scout_newsletter_optin';
	/** Order meta: this order's opt-in has been handled. */
	const ORDER_DONE = '_scout_newsletter_done';
	/** The block checkout's field id (WooCommerce additional checkout fields). */
	const BLOCK_FIELD = 'scout-newsletter/optin';

	public static function boot(): void {
		add_action( 'scout_events_person', array( __CLASS__, 'person' ) );
		add_action( 'scout_events_ticket_bought', array( __CLASS__, 'ticket' ), 10, 4 );
		add_action( 'scout_members_status_changed', array( __CLASS__, 'member' ), 10, 3 );

		// Gravity Forms.
		add_filter( 'gform_form_settings_fields', array( __CLASS__, 'gf_settings' ), 10, 2 );
		add_action( 'gform_after_submission', array( __CLASS__, 'gf_submission' ), 10, 2 );

		// WooCommerce checkout box (off unless switched on in the settings).
		if ( ! empty( Scout_Newsletter::settings()['checkout_box'] ) ) {
			add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'checkout_box' ) );
			add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'checkout_save' ), 10, 2 );
			add_action( 'woocommerce_init', array( __CLASS__, 'block_field' ) );
		}
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'order_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'order_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'order_paid' ) );
	}

	/* ---------- Scout Events ---------- */

	/** A sign-up, volunteer, vendor or presenter. */
	public static function person( $p ): void {
		$p      = (array) $p;
		$email  = (string) ( $p['email'] ?? '' );
		$role   = (string) ( $p['role'] ?? '' );
		$source = in_array( $role, array( 'attendee', 'waitlist' ), true ) ? 'event' : 'form';
		$event  = (int) ( $p['event'] ?? 0 );
		$first  = (string) strtok( trim( (string) ( $p['name'] ?? '' ) ), ' ' );
		if ( ! empty( $p['newsletter'] ) ) {
			Scout_Newsletter::optin(
				$email,
				$first,
				$source,
				array(),
				array(
					'wording'  => (string) ( $p['newsletter_text'] ?? '' ),
					'url'      => $event ? (string) get_permalink( $event ) : '',
					'campaign' => $event ? (string) get_post_field( 'post_name', $event ) : '',
				)
			);
			return;
		}
		Scout_Newsletter::update( $email, Scout_Newsletter::tags_for( $source, array(), $email ), array(), $source );
	}

	/** A paid ticket line (Scout Events with WooCommerce). */
	public static function ticket( $order, $event, $product, $qty ): void {
		if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
			self::order_paid( (int) $order->get_id(), (int) $event );
		}
	}

	/* ---------- WooCommerce ---------- */

	public static function checkout_box(): void {
		echo '<p class="form-row scout-newsletter-optin"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="scout_newsletter_optin" value="1"> <span>' . esc_html( Scout_Newsletter::wording() ) . '</span></label></p>';
	}

	/** Classic checkout: keep the words they saw on the order. WooCommerce checked its own nonce. */
	public static function checkout_save( $order, $data ): void {
		if ( ! empty( $_POST['scout_newsletter_optin'] ) && is_object( $order ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$order->update_meta_data( self::ORDER_OPTIN, Scout_Newsletter::wording() );
		}
	}

	/** Block checkout: an optional, unticked box through WooCommerce's own field API. */
	public static function block_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => self::BLOCK_FIELD,
					'label'    => Scout_Newsletter::wording(),
					'location' => 'order',
					'type'     => 'checkbox',
					'required' => false,
				)
			);
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** The words a ticked box on this order showed, or ''. */
	public static function order_optin( $order ): string {
		$words = (string) $order->get_meta( self::ORDER_OPTIN );
		if ( '' !== $words ) {
			return $words;
		}
		// WooCommerce stores block checkout fields as _wc_other/<id>.
		if ( $order->get_meta( '_wc_other/' . self::BLOCK_FIELD ) ) {
			return Scout_Newsletter::wording();
		}
		return '';
	}

	/** Once per paid order: send the buyer if they ticked the box. */
	public static function order_paid( $order_id, int $event = 0 ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order || $order->get_meta( self::ORDER_DONE ) ) {
			return;
		}
		$words  = self::order_optin( $order );
		$email  = (string) $order->get_billing_email();
		$source = $event || self::has_ticket( $order ) ? 'ticket' : 'form';
		if ( '' !== $words ) {
			Scout_Newsletter::optin(
				$email,
				(string) $order->get_billing_first_name(),
				$source,
				array(),
				array(
					'wording'  => $words,
					'url'      => function_exists( 'wc_get_checkout_url' ) ? (string) wc_get_checkout_url() : '',
					'campaign' => $event ? (string) get_post_field( 'post_name', $event ) : '',
				)
			);
		} elseif ( 'ticket' === $source ) {
			Scout_Newsletter::update( $email, Scout_Newsletter::tags_for( 'ticket', array(), $email ), array(), 'ticket' );
		}
		$order->update_meta_data( self::ORDER_DONE, 1 );
		$order->save();
	}

	/** True when an order holds a Scout Events ticket. */
	private static function has_ticket( $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( method_exists( $item, 'get_product_id' ) && get_post_meta( (int) $item->get_product_id(), class_exists( 'Scout_Events_Woo' ) ? Scout_Events_Woo::EVENT : '_scout_event_id', true ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------- Scout Members ---------- */

	public static function member( $user_id, $new, $old ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! class_exists( 'Scout_Members' ) ) {
			return;
		}
		$email = (string) $user->user_email;
		$s     = Scout_Newsletter::settings();
		if ( 'applied' === $new ) {
			$words = self::member_optin( (int) $user_id );
			if ( '' !== $words ) {
				Scout_Newsletter::optin( $email, (string) $user->first_name, 'membership_application', array(), array( 'wording' => $words ) );
			}
			return;
		}
		$field = trim( (string) $s['field_plan'] );
		if ( 'active' === $new ) {
			$m    = Scout_Members::member( (int) $user_id );
			$plan = '' !== $m['plan'] ? (string) ( Scout_Members::plans()[ $m['plan'] ]['name'] ?? $m['plan'] ) : __( 'Member', 'scout-newsletter' );
			Scout_Newsletter::update( $email, Scout_Newsletter::tags_for( 'membership', array(), $email ), '' !== $field ? array( $field => $plan ) : array(), 'membership' );
		} elseif ( in_array( $new, array( 'paused', 'ended' ), true ) && '' !== $field ) {
			Scout_Newsletter::update( $email, array(), array( $field => '' ), 'membership' );
		}
	}

	/**
	 * The words of a ticked newsletter question on the application, or ''.
	 * The question is the one named in the settings, or any tick-box question
	 * that mentions the newsletter or its name.
	 */
	public static function member_optin( int $user_id ): string {
		if ( ! class_exists( 'Scout_Members_Form' ) ) {
			return '';
		}
		$answers = Scout_Members::member( $user_id )['answers'];
		$named   = trim( (string) Scout_Newsletter::settings()['members_box'] );
		$name    = strtolower( Scout_Newsletter::name() );
		foreach ( Scout_Members_Form::questions() as $q ) {
			if ( 'check' !== $q['type'] ) {
				continue;
			}
			$label = (string) $q['label'];
			$l     = strtolower( $label );
			$match = '' !== $named ? 0 === strcasecmp( $named, $label ) : ( false !== strpos( $l, 'newsletter' ) || ( '' !== $name && false !== strpos( $l, $name ) ) );
			if ( $match && __( 'Yes', 'scout-members' ) === ( $answers[ $label ] ?? '' ) ) { // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				return $label;
			}
		}
		return '';
	}

	/* ---------- Gravity Forms ---------- */

	/** Form Settings: a "Newsletter" section with the consent field and an extra tag. */
	public static function gf_settings( $fields, $form ) {
		$choices = array(
			array(
				'label' => __( 'None: this form does not send anyone', 'scout-newsletter' ),
				'value' => '',
			),
		);
		foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
			$type = (string) self::prop( $f, 'type' );
			if ( in_array( $type, array( 'consent', 'checkbox' ), true ) ) {
				$choices[] = array(
					'label' => wp_strip_all_tags( (string) self::prop( $f, 'label' ) ) . ' (' . $type . ')',
					'value' => (string) self::prop( $f, 'id' ),
				);
			}
		}
		$fields['scout_newsletter'] = array(
			'title'  => __( 'Newsletter', 'scout-newsletter' ),
			'fields' => array(
				array(
					'name'    => 'scout_newsletter_field',
					'type'    => 'select',
					'label'   => __( 'Newsletter consent field', 'scout-newsletter' ),
					'tooltip' => __( 'When this box is ticked, the person is sent to the newsletter with the words they saw. Use an optional Consent or Checkboxes field that starts unticked.', 'scout-newsletter' ),
					'choices' => $choices,
				),
				array(
					'name'    => 'scout_newsletter_tag',
					'type'    => 'text',
					'label'   => __( 'Extra newsletter tag', 'scout-newsletter' ),
					'tooltip' => __( 'Optional. Added beside the Form tag from the newsletter settings.', 'scout-newsletter' ),
				),
			),
		);
		return $fields;
	}

	public static function gf_submission( $entry, $form ): void {
		$entry = (array) $entry;
		$form  = (array) $form;
		$fid   = (string) ( $form['scout_newsletter_field'] ?? '' );
		if ( '' === $fid || 'spam' === ( $entry['status'] ?? '' ) ) {
			return;
		}
		$field = null;
		$email = '';
		$first = '';
		foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
			$id   = (string) self::prop( $f, 'id' );
			$type = (string) self::prop( $f, 'type' );
			if ( $id === $fid ) {
				$field = $f;
			} elseif ( 'email' === $type && '' === $email ) {
				$email = (string) ( $entry[ $id ] ?? '' );
			} elseif ( 'name' === $type && '' === $first ) {
				$first = (string) ( $entry[ $id . '.3' ] ?? '' );
			}
		}
		if ( ! $field ) {
			return;
		}
		$words = self::gf_ticked( $field, $entry );
		if ( '' === $words ) {
			return;
		}
		$tag = trim( (string) ( $form['scout_newsletter_tag'] ?? '' ) );
		Scout_Newsletter::optin(
			$email,
			$first,
			'form',
			'' !== $tag ? array( $tag ) : array(),
			array(
				'wording'  => $words,
				'url'      => (string) ( $entry['source_url'] ?? '' ),
				'campaign' => sanitize_title( (string) ( $form['title'] ?? '' ) ),
			)
		);
	}

	/** The words of a ticked consent or checkbox field, or '' when not ticked. */
	public static function gf_ticked( $field, array $entry ): string {
		$id   = (string) self::prop( $field, 'id' );
		$type = (string) self::prop( $field, 'type' );
		if ( 'consent' === $type ) {
			if ( '1' !== (string) ( $entry[ $id . '.1' ] ?? '' ) ) {
				return '';
			}
			// Gravity Forms saves the checkbox words shown at submission in .2.
			$words = (string) ( $entry[ $id . '.2' ] ?? '' );
			return '' !== $words ? $words : wp_strip_all_tags( (string) self::prop( $field, 'checkboxLabel' ) );
		}
		if ( 'checkbox' === $type ) {
			$ticked = array();
			foreach ( $entry as $k => $v ) {
				if ( 0 === strpos( (string) $k, $id . '.' ) && '' !== (string) $v ) {
					$ticked[] = (string) $v;
				}
			}
			if ( ! $ticked ) {
				return '';
			}
			return trim( wp_strip_all_tags( (string) self::prop( $field, 'label' ) ) . ': ' . implode( ', ', $ticked ), ': ' );
		}
		return '';
	}

	/** A Gravity Forms field property, from the object or an array. */
	private static function prop( $f, string $key ) {
		if ( is_object( $f ) ) {
			return $f->$key ?? '';
		}
		return is_array( $f ) ? ( $f[ $key ] ?? '' ) : '';
	}
}
