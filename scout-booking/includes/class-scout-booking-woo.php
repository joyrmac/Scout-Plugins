<?php
/**
 * Deposits through the WooCommerce checkout, when the site has WooCommerce.
 *
 * Each session type that takes money online has a hidden, virtual product,
 * named with the type's "name on receipts" and priced at what is paid online
 * (the deposit or the full price). The booking form, and the pay link in an
 * approval email, put that product in the cart with the booking attached:
 * the line shows the appointment, the price follows the booking, and the
 * quantity is one. Discount codes are ordinary WooCommerce coupons, so a
 * coupon limited to the 60-minute product works at the checkout as usual.
 *
 * The order drives the booking:
 *   order placed, waiting for payment (on-hold, pending)  the time stays held.
 *   processing or completed                                booked, confirmation sent.
 *   cancelled or failed                                     the time is released.
 *   refunded                                                the booking is cancelled.
 *
 * No payment details pass through this plugin. Whatever payment methods the
 * shop has turned on are what the client sees.
 *
 * @package Scout_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Booking_Woo {

	const ITEM    = '_scout_booking';
	const PRODUCT = '_scout_booking_type';

	/** Set while this plugin adds its own product, so the cart guard lets it through. */
	private static bool $adding = false;

	public static function boot(): void {
		if ( ! Scout_Booking::payments_on() ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'pay' ), 2 );
		add_action( 'template_redirect', array( __CLASS__, 'product_page' ), 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'guard' ), 10, 2 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'from_session' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'line_item' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'placed_classic' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'placed' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status' ), 10, 4 );
		add_action( 'scout_booking_types_saved', array( __CLASS__, 'sync' ) );
		add_filter( 'scout_newsletter_checkout_box', array( __CLASS__, 'newsletter_box' ) );
	}

	/**
	 * Scout Newsletter's checkout box: skipped when the cart only pays for
	 * bookings and the booking form already offered the newsletter, so the
	 * client is asked once, on the booking form, and one consent record is saved.
	 */
	public static function newsletter_box( $show ): bool {
		if ( ! $show || ! Scout_Booking::settings()['optin'] || ! WC()->cart || WC()->cart->is_empty() ) {
			return (bool) $show;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item['scout_booking'] ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------- products ---------- */

	/**
	 * Create or update the product for each type that takes money online, and
	 * store its id on the type. Types that take nothing online keep any
	 * product they had, set to private.
	 */
	public static function sync(): void {
		$types   = Scout_Booking::types();
		$changed = false;
		foreach ( $types as $id => $t ) {
			$product = $t['product_id'] ? wc_get_product( $t['product_id'] ) : null;
			if ( $product && 'trash' === $product->get_status() ) {
				$product = null;
			}
			$due = Scout_Booking::due( $t );
			if ( $due <= 0 ) {
				if ( $product && 'private' !== $product->get_status() ) {
					$product->set_status( 'private' );
					$product->save();
				}
				continue;
			}
			if ( ! $product ) {
				$product = new WC_Product_Simple();
			}
			$product->set_name( Scout_Booking::receipt_name( $t ) );
			$product->set_status( $t['active'] ? 'publish' : 'private' );
			$product->set_catalog_visibility( 'hidden' );
			$product->set_virtual( true );
			$product->set_sold_individually( true );
			$product->set_reviews_allowed( false );
			$product->set_regular_price( wc_format_decimal( $due ) );
			$product->set_sale_price( '' );
			$product->update_meta_data( self::PRODUCT, $id );
			$pid = $product->save();
			if ( $pid !== $t['product_id'] ) {
				$types[ $id ]['product_id'] = $pid;
				$changed                    = true;
			}
		}
		if ( $changed ) {
			Scout_Booking::save_types( $types );
		}
	}

	/** A deleted type's product goes to the bin. */
	public static function retire( array $type ): void {
		$product = $type['product_id'] ? wc_get_product( $type['product_id'] ) : null;
		if ( $product ) {
			$product->delete( false );
		}
	}

	/** Visiting a booking product's page goes to the booking page instead. */
	public static function product_page(): void {
		if ( is_singular( 'product' ) && get_post_meta( (int) get_queried_object_id(), self::PRODUCT, true ) ) {
			wp_safe_redirect( Scout_Booking_Front::page_url() );
			exit;
		}
	}

	/* ---------- into the cart ---------- */

	/**
	 * ?scout_booking_pay=token: put the booking in the cart and go to the
	 * checkout. Used straight after the booking form and by the pay link in
	 * an approval email.
	 */
	public static function pay(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the secret token is the key.
		if ( ! isset( $_GET['scout_booking_pay'] ) ) {
			return;
		}
		$row = Scout_Booking::find( sanitize_key( wp_unslash( $_GET['scout_booking_pay'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $row ) {
			wp_safe_redirect( Scout_Booking_Front::page_url() );
			exit;
		}
		$type   = Scout_Booking::type( $row['type_id'] );
		$future = Scout_Booking::at( $row['start_at'] ) > Scout_Booking::now();
		if ( 'confirmed' === $row['status'] || ! $type || ! $future || $row['due'] <= 0 ) {
			wp_safe_redirect( Scout_Booking_Front::manage_url( $row ) );
			exit;
		}
		if ( 'pending' === $row['status'] && ! Scout_Booking::holds( $row ) ) {
			// The hold ran out. Hold it again if nobody took the time meanwhile.
			$start = Scout_Booking::at( $row['start_at'] );
			if ( ! Scout_Booking::conflicts( $start, Scout_Booking::at( $row['block_end'] ), $row['id'] ) ) {
				$row = Scout_Booking::update( $row['id'], array( 'hold_until' => Scout_Booking::now()->modify( '+' . Scout_Booking::settings()['hold_minutes'] . ' minutes' )->format( 'Y-m-d H:i:s' ) ) );
			} else {
				Scout_Booking::update( $row['id'], array( 'status' => 'expired' ) );
				wp_safe_redirect( add_query_arg( array( 'sb_type' => $row['type_id'], 'sb_msg' => 'held' ), Scout_Booking_Front::page_url() ) );
				exit;
			}
		}
		if ( ! in_array( $row['status'], array( 'pending', 'approved' ), true ) ) {
			wp_safe_redirect( Scout_Booking_Front::manage_url( $row ) );
			exit;
		}
		if ( ! $type['product_id'] || ! wc_get_product( $type['product_id'] ) ) {
			self::sync();
			$type = Scout_Booking::type( $row['type_id'] );
		}
		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		$cart = WC()->cart;
		if ( ! $cart || ! $type || ! $type['product_id'] ) {
			wp_safe_redirect( Scout_Booking_Front::manage_url( $row ) );
			exit;
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ( $item['scout_booking'] ?? '' ) === $row['token'] ) {
				$cart->remove_cart_item( $key );
			}
		}
		self::$adding = true;
		$cart->add_to_cart( $type['product_id'], 1, 0, array(), array( 'scout_booking' => $row['token'] ) );
		self::$adding = false;
		self::prefill( $row );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/** Fill the checkout's name, email and phone from the booking when they are empty. */
	private static function prefill( array $row ): void {
		$c = WC()->customer;
		if ( ! $c ) {
			return;
		}
		if ( '' === $c->get_billing_email() ) {
			$c->set_billing_email( $row['email'] );
		}
		if ( '' === $c->get_billing_first_name() ) {
			$c->set_billing_first_name( $row['first_name'] );
		}
		if ( '' === $c->get_billing_last_name() ) {
			$c->set_billing_last_name( $row['last_name'] );
		}
		if ( '' === $c->get_billing_phone() ) {
			$c->set_billing_phone( $row['phone'] );
		}
		$c->save();
	}

	/** A booking product only goes in the cart from a booking. */
	public static function guard( $passed, $product_id ) {
		if ( ! self::$adding && get_post_meta( (int) $product_id, self::PRODUCT, true ) ) {
			wc_add_notice( __( 'Please book this session on the booking page, where you pick a time.', 'scout-booking' ), 'error' );
			return false;
		}
		return $passed;
	}

	public static function from_session( $item, $values ) {
		if ( ! empty( $values['scout_booking'] ) ) {
			$item['scout_booking'] = $values['scout_booking'];
		}
		return $item;
	}

	/** The line costs what this booking pays online. */
	public static function prices( $cart ): void {
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['scout_booking'] ) ) {
				continue;
			}
			$row = Scout_Booking::find( (string) $item['scout_booking'] );
			if ( $row ) {
				$item['data']->set_price( $row['due'] );
			}
		}
	}

	/** The appointment under the line in the cart and checkout. */
	public static function item_data( $data, $item ) {
		if ( empty( $item['scout_booking'] ) ) {
			return $data;
		}
		$row = Scout_Booking::find( (string) $item['scout_booking'] );
		if ( ! $row ) {
			return $data;
		}
		$data[] = array(
			'key'   => __( 'Appointment', 'scout-booking' ),
			'value' => Scout_Booking::when_label( $row ),
		);
		if ( $row['price'] > $row['due'] ) {
			$data[] = array(
				'key'   => __( 'Balance at the appointment', 'scout-booking' ),
				'value' => Scout_Booking::money( $row['price'] - $row['due'] ),
			);
		}
		return $data;
	}

	/** A booking whose hold ran out, or that is no longer payable, leaves the cart. */
	public static function check(): void {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['scout_booking'] ) ) {
				continue;
			}
			$row = Scout_Booking::find( (string) $item['scout_booking'] );
			$ok  = $row && ( 'approved' === $row['status'] || ( 'pending' === $row['status'] && Scout_Booking::holds( $row ) ) );
			if ( ! $ok ) {
				$cart->remove_cart_item( $key );
				wc_add_notice( __( 'The time held for your session has run out, so it was taken out of your cart. Please pick a time again on the booking page.', 'scout-booking' ), 'error' );
			}
		}
	}

	public static function line_item( $item, $key, $values, $order ): void {
		if ( empty( $values['scout_booking'] ) ) {
			return;
		}
		$row = Scout_Booking::find( (string) $values['scout_booking'] );
		if ( ! $row ) {
			return;
		}
		$item->add_meta_data( self::ITEM, $row['token'], true );
		$item->add_meta_data( __( 'Appointment', 'scout-booking' ), Scout_Booking::when_label( $row ), true );
		if ( $row['price'] > $row['due'] ) {
			$item->add_meta_data( __( 'Balance at the appointment', 'scout-booking' ), Scout_Booking::money( $row['price'] - $row['due'] ), true );
		}
	}

	/* ---------- the order drives the booking ---------- */

	/** @return array<int,array{row:array,item:WC_Order_Item_Product}> */
	private static function bookings( WC_Order $order ): array {
		$out = array();
		foreach ( $order->get_items() as $item ) {
			$token = (string) $item->get_meta( self::ITEM );
			$row   = '' !== $token ? Scout_Booking::find( $token ) : null;
			if ( $row ) {
				$out[] = array(
					'row'  => $row,
					'item' => $item,
				);
			}
		}
		return $out;
	}

	public static function placed_classic( $order_id, $posted = array(), $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( $order ) {
			self::placed( $order );
		}
	}

	/**
	 * The order exists: link it, and keep the time held while payment is on
	 * its way (an offline method can take a while).
	 */
	public static function placed( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		foreach ( self::bookings( $order ) as $b ) {
			if ( in_array( $b['row']['status'], array( 'pending', 'approved' ), true ) ) {
				Scout_Booking::update(
					$b['row']['id'],
					array(
						'order_id'   => $order->get_id(),
						'hold_until' => Scout_Booking::now()->modify( '+' . Scout_Booking::settings()['pay_hours'] . ' hours' )->format( 'Y-m-d H:i:s' ),
					)
				);
			}
		}
	}

	public static function status( $order_id, $from, $to, $order ): void {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		foreach ( self::bookings( $order ) as $b ) {
			$row = $b['row'];
			if ( in_array( $to, array( 'processing', 'completed' ), true ) ) {
				if ( 'confirmed' === $row['status'] ) {
					continue;
				}
				$row = Scout_Booking::update(
					$row['id'],
					array(
						'status'     => 'confirmed',
						'paid'       => round( (float) $b['item']->get_total() + (float) $b['item']->get_total_tax(), 2 ),
						'order_id'   => $order->get_id(),
						'hold_until' => null,
					)
				);
				/* translators: %s: date and time. */
				$order->add_order_note( sprintf( __( 'Scout Booking: session booked for %s. Confirmation sent to the client.', 'scout-booking' ), Scout_Booking::when_label( $row ) ) );
				Scout_Booking_Emails::confirmed( $row );
			} elseif ( in_array( $to, array( 'on-hold', 'pending' ), true ) ) {
				self::placed( $order );
			} elseif ( in_array( $to, array( 'cancelled', 'failed' ), true ) ) {
				if ( 'approved' === $row['status'] ) {
					// An approved request can still be paid from its link.
					Scout_Booking::update( $row['id'], array( 'order_id' => 0 ) );
				} elseif ( 'pending' === $row['status'] ) {
					Scout_Booking::update( $row['id'], array( 'status' => 'cancelled' ) );
				} elseif ( 'confirmed' === $row['status'] ) {
					$row = Scout_Booking::update( $row['id'], array( 'status' => 'cancelled' ) );
					Scout_Booking_Emails::cancelled( $row, 'owner' );
				}
			} elseif ( 'refunded' === $to && 'confirmed' === $row['status'] ) {
				Scout_Booking::update( $row['id'], array( 'status' => 'cancelled' ) );
			}
		}
	}

	/** Discount codes used on an order, as "FIRST" or "FIRST, KAI". */
	public static function coupons( int $order_id ): string {
		$order = $order_id ? wc_get_order( $order_id ) : null;
		return $order ? strtoupper( implode( ', ', $order->get_coupon_codes() ) ) : '';
	}

	/** The order's edit screen, for the owner's list. */
	public static function order_url( int $order_id ): string {
		$order = $order_id ? wc_get_order( $order_id ) : null;
		return $order ? $order->get_edit_order_url() : '';
	}
}
