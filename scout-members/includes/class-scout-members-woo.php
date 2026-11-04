<?php
/**
 * Dues through the site's own checkout, when WooCommerce is active.
 *
 * Each plan can link to a WooCommerce product (Settings, Dues and renewals):
 * a simple, virtual product hidden from the shop, named in plain words for
 * receipts ("Bliss Boogie Connections membership, Club Member, 1 year").
 * "Create the product for this plan" makes one in a click.
 *
 * A pay link (pay_url()) carries the member and a signed key. Opening it puts
 * the plan's product in the cart, tied to that member, and goes to checkout,
 * signed in or not. When the order is paid, the member becomes active and the
 * renewal date moves forward one period through Scout_Members::paid(). The
 * order remembers the member in its own meta, so the match never depends on
 * the email typed at checkout; an order for a dues product without that falls
 * back to the buyer's account, then their email.
 *
 * Refunds and cancellations of a recorded payment never take a membership
 * away by themselves: the member is flagged on the Renewals screen and the
 * owner decides. No card is ever saved here; renewals are a fresh checkout
 * from the reminder email.
 *
 * @package Scout_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Members_Woo {

	/** Option: plan slug => product id. */
	const OPTION = 'scout_members_products';

	/** Product meta: the plan a product pays for. */
	const PLAN = '_scout_members_plan';

	/** Order and order item meta: the member an order pays for. */
	const MEMBER = '_scout_member_id';

	/** Order meta: the payment was recorded on the member (or flagged). */
	const DONE = '_scout_members_recorded';

	/** Option: dues orders no member matched, for the owner. */
	const UNMATCHED = 'scout_members_unmatched';

	/** Cart item key that carries the member. */
	const CART = 'scout_member';

	public static function boot(): void {
		if ( ! self::active() ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'open_pay_link' ), 5 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'member_price' ), 20 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'tie_line' ), 10, 4 );
		add_filter( 'woocommerce_checkout_registration_required', array( __CLASS__, 'no_account_needed' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'only_by_link' ), 10, 2 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'paid' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'paid' ) );
		add_filter( 'woocommerce_payment_complete_order_status', array( __CLASS__, 'complete_dues_orders' ), 10, 3 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'undone' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'undone' ) );
		add_action( 'woocommerce_order_partially_refunded', array( __CLASS__, 'undone' ) );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thank_you' ), 5 );
		add_action( 'admin_post_scout_members_product', array( __CLASS__, 'create_product' ) );
	}

	/** WooCommerce is there to take payments. */
	public static function active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/** Tell WooCommerce this plugin works with its order storage (HPOS). */
	public static function declare_compatibility(): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SCOUT_MEMBERS_FILE, true );
		}
	}

	/* ---------- plans and products ---------- */

	/** @return array<string,int> plan slug => product id, for plans that still exist */
	public static function links(): array {
		$links = get_option( self::OPTION, array() );
		$links = is_array( $links ) ? array_map( 'intval', $links ) : array();
		return array_intersect_key( array_filter( $links ), Scout_Members::plans() );
	}

	/** The product a plan's dues are paid with, when it exists and can be bought. */
	public static function product( string $plan ): ?WC_Product {
		if ( ! self::active() ) {
			return null;
		}
		$id = self::links()[ $plan ] ?? 0;
		$p  = $id ? wc_get_product( $id ) : null;
		return $p && 'publish' === $p->get_status() && $p->is_purchasable() ? $p : null;
	}

	/** The plan a product pays for, or an empty string. */
	public static function plan_of( int $product_id ): string {
		$plan = array_search( $product_id, self::links(), true );
		return false === $plan ? '' : (string) $plan;
	}

	/** Save Settings' plan => product choices, and mark each product with its plan. */
	public static function save_links( array $in ): void {
		$old   = self::links();
		$clean = array();
		foreach ( Scout_Members::plans() as $slug => $plan ) {
			$id = absint( $in[ $slug ] ?? 0 );
			if ( $id && 'product' === get_post_type( $id ) ) {
				$clean[ $slug ] = $id;
				update_post_meta( $id, self::PLAN, $slug );
			}
		}
		foreach ( array_diff( $old, $clean ) as $gone ) {
			delete_post_meta( $gone, self::PLAN );
		}
		update_option( self::OPTION, $clean, false );
	}

	/** "Bliss Boogie Connections membership, Club Member, 1 year". */
	public static function product_name( string $plan ): string {
		$p     = Scout_Members::plans()[ $plan ] ?? null;
		$parts = array( Scout_Members::receipt_name() );
		if ( $p ) {
			$parts[] = $p['name'];
			if ( 'year' === $p['period'] ) {
				$parts[] = __( '1 year', 'scout-members' );
			} elseif ( 'month' === $p['period'] ) {
				$parts[] = __( '1 month', 'scout-members' );
			}
		}
		return implode( ', ', $parts );
	}

	/** "Create the product for this plan": a simple, virtual, hidden product at the plan's price. */
	public static function create_product(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'publish_products' ) ) {
			wp_die( esc_html__( 'You do not have permission to add products.', 'scout-members' ), 403 );
		}
		$plan = isset( $_POST['plan'] ) ? sanitize_title( wp_unslash( $_POST['plan'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( 'scout_members_product_' . $plan );
		$plans = Scout_Members::plans();
		$back  = Scout_Members_Admin::url( 'settings' );
		if ( ! isset( $plans[ $plan ] ) ) {
			wp_safe_redirect( add_query_arg( 'scout-members', 'noplan', $back ) );
			exit;
		}
		$have = self::links()[ $plan ] ?? 0;
		if ( $have && wc_get_product( $have ) ) {
			wp_safe_redirect( add_query_arg( 'scout-members', 'product', $back ) . '#sm-dues' );
			exit;
		}
		$product = new WC_Product_Simple();
		$product->set_name( self::product_name( $plan ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_reviews_allowed( false );
		if ( '' !== $plans[ $plan ]['price'] ) {
			$product->set_regular_price( $plans[ $plan ]['price'] );
		}
		$product->set_short_description( __( 'Membership dues.', 'scout-members' ) );
		$id = $product->save();
		if ( $id ) {
			update_post_meta( $id, self::PLAN, $plan );
			$links          = self::links();
			$links[ $plan ] = (int) $id;
			update_option( self::OPTION, $links, false );
		}
		wp_safe_redirect( add_query_arg( 'scout-members', $id ? 'product' : 'noproduct', $back ) . '#sm-dues' );
		exit;
	}

	/* ---------- who can pay, and the link ---------- */

	/**
	 * What a member would pay now: "dues" (approved, or applying when people
	 * pay with the application), "renew" (an active member within 30 days of
	 * renewing, in the grace period or lapsed), or an empty string.
	 */
	public static function pay_kind( int $user_id ): string {
		$m = Scout_Members::member( $user_id );
		if ( ! self::product( $m['plan'] ) || Scout_Members::flagged( $user_id ) ) {
			return '';
		}
		if ( 'approved' === $m['status'] ) {
			return 'dues';
		}
		if ( 'applied' === $m['status'] && ! $m['prepaid'] && 'apply' === Scout_Members::settings()['pay_when'] ) {
			return 'dues';
		}
		if ( 'active' === $m['status'] && '' !== $m['renews'] ) {
			$period = Scout_Members::plans()[ $m['plan'] ]['period'] ?? 'year';
			$window = 'month' === $period ? 7 : 30;
			if ( $m['renews'] <= gmdate( 'Y-m-d', strtotime( Scout_Members::today() . ' +' . $window . ' days' ) ) ) {
				return 'renew';
			}
		}
		return '';
	}

	/** The key that proves a pay link came from this site, for this member and this renewal date. */
	private static function key( int $user_id ): string {
		$m = Scout_Members::member( $user_id );
		return substr( hash_hmac( 'sha256', 'pay|' . $user_id . '|' . $m['renews'] . '|' . $m['prepaid'] . '|' . $m['status'], wp_salt( 'auth' ) ), 0, 24 );
	}

	/** A link that takes the member straight to checkout with their dues. Empty when there is nothing to pay. */
	public static function pay_url( int $user_id ): string {
		if ( '' === self::pay_kind( $user_id ) ) {
			return '';
		}
		return add_query_arg(
			array(
				'sm-pay' => $user_id,
				'sm-key' => self::key( $user_id ),
			),
			home_url( '/' )
		);
	}

	/** What the checkout will charge this member: their renewal price, else the product's price. */
	public static function amount( int $user_id ): string {
		$m = Scout_Members::member( $user_id );
		if ( '' !== $m['comp'] ) {
			return $m['comp'];
		}
		$p = self::product( $m['plan'] );
		return $p ? (string) $p->get_price() : Scout_Members::dues( $user_id );
	}

	/** Opening a pay link: the dues go in the cart, tied to the member, and the checkout opens. */
	public static function open_pay_link(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the signed key is the check.
		if ( ! isset( $_GET['sm-pay'], $_GET['sm-key'] ) ) {
			return;
		}
		$user_id = absint( $_GET['sm-pay'] );
		$key     = sanitize_text_field( wp_unslash( $_GET['sm-key'] ) );
		// phpcs:enable
		$page = Scout_Members::page_url();
		if ( ! $user_id || ! get_userdata( $user_id ) || ! hash_equals( self::key( $user_id ), $key ) || '' === self::pay_kind( $user_id ) ) {
			wp_safe_redirect( add_query_arg( 'membership', 'paylink', $page ) . '#sm-portal' );
			exit;
		}
		$m       = Scout_Members::member( $user_id );
		$product = self::product( $m['plan'] );
		if ( ! $product || ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_safe_redirect( add_query_arg( 'membership', 'paylink', $page ) . '#sm-portal' );
			exit;
		}
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		// One membership at a time; anything else in the cart (tickets) stays.
		foreach ( WC()->cart->get_cart() as $cart_key => $item ) {
			if ( ! empty( $item[ self::CART ] ) || '' !== self::plan_of( (int) $item['product_id'] ) ) {
				WC()->cart->remove_cart_item( $cart_key );
			}
		}
		WC()->cart->add_to_cart(
			$product->get_id(),
			1,
			0,
			array(),
			array(
				self::CART           => $user_id,
				'scout_member_kind'  => self::pay_kind( $user_id ),
				'scout_member_price' => '' !== $m['comp'] ? $m['comp'] : '',
			)
		);
		// Fill in who is paying, unless someone else is signed in on this browser.
		$current = get_current_user_id();
		if ( WC()->customer && ( ! $current || $current === $user_id ) ) {
			$user = get_userdata( $user_id );
			if ( '' === (string) WC()->customer->get_billing_email() ) {
				WC()->customer->set_billing_email( $user->user_email );
			}
			if ( '' === (string) WC()->customer->get_billing_first_name() ) {
				WC()->customer->set_billing_first_name( $user->first_name );
			}
			if ( '' === (string) WC()->customer->get_billing_last_name() ) {
				WC()->customer->set_billing_last_name( $user->last_name );
			}
			WC()->customer->save();
		}
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/** A member's own renewal price (a comp) replaces the product price in the cart. */
	public static function member_price( $cart ): void {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item[ self::CART ] ) && isset( $item['scout_member_price'] ) && '' !== (string) $item['scout_member_price'] && isset( $item['data'] ) ) {
				$item['data']->set_price( (float) $item['scout_member_price'] );
			}
		}
	}

	/** The order line, and the order, remember the member. */
	public static function tie_line( $item, $cart_key, $values, $order ): void {
		if ( empty( $values[ self::CART ] ) ) {
			return;
		}
		$user_id = absint( $values[ self::CART ] );
		$item->add_meta_data( self::MEMBER, $user_id, true );
		$order->update_meta_data( self::MEMBER, $user_id );
	}

	/** Paying dues never asks for a new account: the member already has one. */
	public static function no_account_needed( $required ) {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( ! empty( $item[ self::CART ] ) ) {
					return false;
				}
			}
		}
		return $required;
	}

	/**
	 * Dues products go in the cart through a pay link, which knows the member.
	 * Someone signed in can still buy their own plan's product from its page.
	 */
	public static function only_by_link( $ok, $product_id ) {
		if ( ! $ok || '' === self::plan_of( (int) $product_id ) || doing_action( 'template_redirect' ) ) {
			return $ok;
		}
		if ( current_user_can( 'edit_posts' ) || '' !== Scout_Members::member( get_current_user_id() )['status'] ) {
			return $ok;
		}
		wc_add_notice( __( 'Membership starts with an application. Apply first, and the dues link comes by email.', 'scout-members' ), 'notice' );
		return false;
	}

	/* ---------- orders ---------- */

	/**
	 * The member an order line pays for: the line's own meta, the order's, the
	 * buyer's account, then the billing email. 0 when nobody matches.
	 */
	private static function member_for( WC_Order $order, $item ): int {
		foreach ( array( (int) $item->get_meta( self::MEMBER ), (int) $order->get_meta( self::MEMBER ), (int) $order->get_customer_id() ) as $id ) {
			if ( $id && '' !== Scout_Members::member( $id )['status'] ) {
				return $id;
			}
		}
		$user = get_user_by( 'email', (string) $order->get_billing_email() );
		return $user && '' !== Scout_Members::member( (int) $user->ID )['status'] ? (int) $user->ID : 0;
	}

	/** A paid order for a dues product: the member becomes active and renews a period later. */
	public static function paid( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order->get_meta( self::DONE ) ) {
			return;
		}
		$found = false;
		foreach ( $order->get_items() as $item ) {
			$plan = self::plan_of( (int) $item->get_product_id() );
			if ( '' === $plan ) {
				continue;
			}
			$found   = true;
			$user_id = self::member_for( $order, $item );
			/* translators: 1: order number, 2: amount. */
			$how = sprintf( __( 'order #%1$s, %2$s', 'scout-members' ), $order->get_order_number(), Scout_Members::money( (float) $item->get_total() ) );
			if ( ! $user_id ) {
				$list   = (array) get_option( self::UNMATCHED, array() );
				$list[] = (int) $order->get_id();
				update_option( self::UNMATCHED, array_values( array_unique( array_map( 'intval', $list ) ) ), false );
				$order->add_order_note( __( 'Membership dues: no member matches this order. It waits under Members, Renewals, for the owner to match by hand.', 'scout-members' ) );
				continue;
			}
			$m    = Scout_Members::member( $user_id );
			$name = (string) get_userdata( $user_id )->display_name;
			if ( $plan !== $m['plan'] ) {
				Scout_Members::save( $user_id, array( 'plan' => $plan ) );
				/* translators: %s: plan name. */
				Scout_Members::log( $user_id, sprintf( __( 'Plan set to %s by their payment.', 'scout-members' ), Scout_Members::plans()[ $plan ]['name'] ) );
			}
			if ( 'applied' === $m['status'] ) {
				// Paid with the application: the year starts the day it is approved.
				Scout_Members::save( $user_id, array( 'prepaid' => (int) $order->get_id() ) );
				/* translators: %s: how it was paid. */
				Scout_Members::log( $user_id, sprintf( __( 'Paid with the application (%s). Held until approval.', 'scout-members' ), $how ) );
				/* translators: %s: member name. */
				$order->add_order_note( sprintf( __( 'Membership dues for %s, paid with the application. Their membership starts the day it is approved.', 'scout-members' ), $name ) );
			} elseif ( in_array( $m['status'], array( 'declined', 'paused' ), true ) ) {
				/* translators: 1: how it was paid, 2: status. */
				Scout_Members::flag( $user_id, sprintf( __( 'Paid (%1$s) while the membership was %2$s. Nothing changed.', 'scout-members' ), $how, strtolower( Scout_Members::statuses()[ $m['status'] ] ) ), (int) $order->get_id() );
				/* translators: %s: member name. */
				$order->add_order_note( sprintf( __( 'Membership dues for %s. Their membership is not active, so it is waiting for the owner under Members, Renewals.', 'scout-members' ), $name ) );
			} else {
				Scout_Members::paid( $user_id, Scout_Members::today(), $how );
				Scout_Members::set_status( $user_id, 'active' );
				$renews = Scout_Members::member( $user_id )['renews'];
				$order->add_order_note(
					'' !== $renews
						/* translators: 1: member name, 2: renewal date. */
						? sprintf( __( 'Membership dues recorded for %1$s. Active, renews %2$s.', 'scout-members' ), $name, Scout_Members::date_label( $renews ) )
						/* translators: %s: member name. */
						: sprintf( __( 'Membership dues recorded for %s. Active.', 'scout-members' ), $name )
				);
			}
			if ( ! $order->get_meta( self::MEMBER ) ) {
				$order->update_meta_data( self::MEMBER, $user_id );
			}
			if ( ! $order->get_customer_id() ) {
				// A guest checkout: the order shows under the member's own account.
				$order->set_customer_id( $user_id );
			}
		}
		if ( $found ) {
			$order->update_meta_data( self::DONE, 1 );
			$order->save();
		}
	}

	/** Orders of dues alone complete when paid; there is nothing to ship. */
	public static function complete_dues_orders( $status, $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $order->get_items() ) {
			return $status;
		}
		foreach ( $order->get_items() as $item ) {
			if ( '' === self::plan_of( (int) $item->get_product_id() ) ) {
				return $status;
			}
		}
		return 'completed';
	}

	/**
	 * A recorded dues payment refunded or cancelled: the membership stays as
	 * it is and the owner gets it to review.
	 */
	public static function undone( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $order->get_meta( self::DONE ) ) {
			return;
		}
		$user_id = (int) $order->get_meta( self::MEMBER );
		if ( ! $user_id ) {
			return;
		}
		$what = 'cancelled' === $order->get_status()
			? __( 'cancelled', 'scout-members' )
			: ( (float) $order->get_total_refunded() < (float) $order->get_total() ? __( 'partly refunded', 'scout-members' ) : __( 'refunded', 'scout-members' ) );
		/* translators: 1: order number, 2: "refunded", "partly refunded" or "cancelled". */
		$reason = sprintf( __( 'Dues order #%1$s was %2$s. The membership was left as it is.', 'scout-members' ), $order->get_order_number(), $what );
		$open   = Scout_Members::flagged( $user_id );
		if ( $open && $open['order'] === (int) $order->get_id() && $open['reason'] === $reason ) {
			return;
		}
		Scout_Members::flag( $user_id, $reason, (int) $order->get_id() );
		$order->add_order_note( __( 'Membership left as it is. The owner reviews it under Members, Renewals.', 'scout-members' ) );
	}

	/** The order received page says where the membership stands. */
	public static function thank_you( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$user_id = (int) $order->get_meta( self::MEMBER );
		if ( ! $user_id || ! $order->get_meta( self::DONE ) ) {
			return;
		}
		$m = Scout_Members::member( $user_id );
		if ( 'applied' === $m['status'] ) {
			$text = __( 'Your dues are paid. Your membership starts the day your application is approved, and we will email you then.', 'scout-members' );
		} elseif ( 'active' === $m['status'] && '' !== $m['renews'] ) {
			/* translators: %s: renewal date. */
			$text = sprintf( __( 'Your membership is active. It renews on %s.', 'scout-members' ), Scout_Members::date_label( $m['renews'] ) );
		} elseif ( 'active' === $m['status'] ) {
			$text = __( 'Your membership is active.', 'scout-members' );
		} else {
			return;
		}
		wp_enqueue_style( 'scout-members' );
		echo '<p class="sm-thanks">' . esc_html( $text ) . ' <a href="' . esc_url( Scout_Members::home_url() ) . '">' . esc_html( Scout_Members::home_id() ? __( 'Go to the members home', 'scout-members' ) : __( 'See your membership', 'scout-members' ) ) . '</a></p>';
	}

	/** @return int[] dues orders that matched no member and are still waiting */
	public static function unmatched(): array {
		return array_values( array_filter( array_map( 'intval', (array) get_option( self::UNMATCHED, array() ) ) ) );
	}

	public static function clear_unmatched( int $order_id ): void {
		update_option( self::UNMATCHED, array_values( array_diff( self::unmatched(), array( $order_id ) ) ), false );
	}
}
