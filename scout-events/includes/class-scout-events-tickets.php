<?php
/**
 * Paid tickets, end to end, on the site's own WooCommerce checkout.
 *
 * - "Create a ticket" on an event makes a simple, virtual WooCommerce product
 *   (name, price, how many, sales window, the name on receipts) already ticked
 *   as one of the event's tickets.
 * - Checkout asks for each ticket holder's name, and their agreements when the
 *   event asks (Scout_Events_Agreements), plus an unticked newsletter box.
 *   With Scout Newsletter active, that box is Scout Newsletter's own checkout
 *   box (this class asks it to show for ticket carts and prints none of its
 *   own), so a buyer sees one box and one consent record is saved.
 *   A block checkout page switches to the classic checkout form while the cart
 *   holds tickets, because the block checkout has no place for repeated
 *   per-ticket fields.
 * - When the order is paid, each ticket becomes an attendee row in the
 *   sign-ups table: the same QR ticket, door list and check-in as free
 *   sign-ups, counted in the event's places left. The buyer gets one email
 *   with every ticket and the calendar file.
 * - A refund (whole or part) turns the refunded tickets void.
 *
 * Payment itself is WooCommerce's job, through whatever gateway the site
 * turns on. Nothing here touches card details.
 *
 * Off when FooEvents is active (it makes its own tickets from the same
 * products), or with the scout_events_paid_attendees filter.
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_Tickets {

	const ATTENDEES   = '_scout_attendees';
	const MADE        = '_scout_events_attendees_made';
	const OPTIN       = '_scout_newsletter_optin';
	const OPTIN_SENT  = '_scout_newsletter_sent';
	const SALES_START = '_scout_ticket_sales_start';
	const SALES_END   = '_scout_ticket_sales_end';
	const RECEIPT     = '_scout_receipt_name';
	const PUBLIC_NAME = '_scout_ticket_name';

	/** Spots left per event, for this request. */
	private static array $left = array();

	/** Do paid tickets become attendees on this site? */
	public static function on(): bool {
		return class_exists( 'WooCommerce' ) && (bool) apply_filters( 'scout_events_paid_attendees', ! class_exists( 'FooEvents' ) );
	}

	/** Runs from Scout_Events_Woo::boot(), so WooCommerce is there. */
	public static function boot(): void {
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'cart_check' ), 10, 3 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'cart_items_check' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'line_item' ), 10, 4 );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_fields' ) );
		if ( ! self::on() ) {
			return;
		}
		add_filter( 'render_block', array( __CLASS__, 'classic_checkout' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'checkout_style' ), 20 );
		add_action( 'woocommerce_checkout_after_customer_details', array( __CLASS__, 'checkout_fields' ) );
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'checkout_newsletter' ) );
		add_filter( 'scout_newsletter_checkout_box', array( __CLASS__, 'newsletter_box' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'order_meta' ), 10, 2 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'make_attendees' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'make_attendees' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'make_attendees' ), 20 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'void_order' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'void_order' ) );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'refunded' ), 10, 2 );
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'admin_item' ), 10, 3 );
	}

	/* ---------- the product ---------- */

	/** The event a product is a ticket for, or 0. */
	public static function event_of( $product ): int {
		$pid = $product instanceof WC_Product ? ( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) : (int) $product;
		return (int) get_post_meta( $pid, Scout_Events_Woo::EVENT, true );
	}

	/**
	 * Make a ticket product for an event and tick it as one of its tickets.
	 *
	 * @param array $in name, price, stock, from, to, receipt, about.
	 * @return int|WP_Error the product id
	 */
	public static function create( int $event_id, array $in ) {
		$name  = sanitize_text_field( (string) ( $in['name'] ?? '' ) );
		$price = trim( (string) ( $in['price'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'name', __( 'Give the ticket a name, such as Weekend pass.', 'scout-events' ) );
		}
		if ( '' === $price || ! is_numeric( wc_format_decimal( $price ) ) || (float) wc_format_decimal( $price ) < 0 ) {
			return new WP_Error( 'price', __( 'Give the ticket a price (0 for a free ticket).', 'scout-events' ) );
		}
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_status( 'publish' );
		$p->set_virtual( true );
		$p->set_regular_price( wc_format_decimal( $price ) );
		$about = sanitize_textarea_field( (string) ( $in['about'] ?? '' ) );
		if ( '' !== $about ) {
			$p->set_short_description( $about );
		}
		$stock = absint( $in['stock'] ?? 0 );
		if ( $stock > 0 ) {
			$p->set_manage_stock( true );
			$p->set_stock_quantity( $stock );
			$p->set_backorders( 'no' );
		}
		$p->update_meta_data( Scout_Events_Woo::EVENT, $event_id );
		$p->update_meta_data( self::SALES_START, Scout_Events::clean_date( (string) ( $in['from'] ?? '' ) ) );
		$p->update_meta_data( self::SALES_END, Scout_Events::clean_date( (string) ( $in['to'] ?? '' ) ) );
		$p->update_meta_data( self::RECEIPT, sanitize_text_field( (string) ( $in['receipt'] ?? '' ) ) );
		$id = $p->save();
		if ( ! $id ) {
			return new WP_Error( 'save', __( 'The ticket could not be saved. Try again.', 'scout-events' ) );
		}
		$ids   = Scout_Events_Woo::product_ids( $event_id );
		$ids[] = (int) $id;
		update_post_meta( $event_id, Scout_Events_Woo::META, array_values( array_unique( $ids ) ) );
		return (int) $id;
	}

	/** "On sale from Nov 10" or "Sales closed" for a product outside its sales window; empty while open. */
	public static function window_note( WC_Product $p ): string {
		$pid   = $p->get_parent_id() ? $p->get_parent_id() : $p->get_id();
		$from  = (string) get_post_meta( $pid, self::SALES_START, true );
		$to    = (string) get_post_meta( $pid, self::SALES_END, true );
		$today = Scout_Events::now()->format( 'Y-m-d' );
		if ( '' !== $from && $from > $today ) {
			/* translators: %s: date. */
			return sprintf( __( 'On sale %s', 'scout-events' ), Scout_Events::day_label( $from ) );
		}
		if ( '' !== $to && $to < $today ) {
			return __( 'Sales closed', 'scout-events' );
		}
		return '';
	}

	/** A ticket can be bought inside its sales window, while the event has room. */
	public static function purchasable( $ok, $product ) {
		if ( ! $ok || ! $product instanceof WC_Product ) {
			return $ok;
		}
		$event = self::event_of( $product );
		if ( ! $event ) {
			return $ok;
		}
		if ( '' !== self::window_note( $product ) ) {
			return false;
		}
		$left = self::left( $event );
		return null === $left || $left > 0;
	}

	/** Places left at the event (free and paid together), or null without a capacity. */
	public static function left( int $event ): ?int {
		if ( ! array_key_exists( $event, self::$left ) ) {
			// The saved fields, not the site-side event: that one asks the products, which ask here.
			$raw                  = get_post_meta( $event, '_scout_event', true );
			self::$left[ $event ] = self::on() ? Scout_Events_RSVP::spots_left( $event, Scout_Events::sanitize_event( is_array( $raw ) ? $raw : array() ) ) : null;
		}
		return self::$left[ $event ];
	}

	/** Tickets of each event already in the cart. */
	private static function in_cart(): array {
		$out = array();
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $out;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$event = self::event_of( (int) $item['product_id'] );
			if ( $event ) {
				$out[ $event ] = ( $out[ $event ] ?? 0 ) + (int) $item['quantity'];
			}
		}
		return $out;
	}

	public static function cart_check( $passed, $product_id, $qty = 1 ) {
		$event = self::event_of( (int) $product_id );
		$left  = $event ? self::left( $event ) : null;
		if ( ! $passed || null === $left ) {
			return $passed;
		}
		$want = (int) $qty + ( self::in_cart()[ $event ] ?? 0 );
		if ( $want > $left ) {
			/* translators: 1: places left, 2: event name. */
			wc_add_notice( sprintf( _n( 'Only %1$d place is left at %2$s.', 'Only %1$d places are left at %2$s.', $left, 'scout-events' ), $left, get_the_title( $event ) ), 'error' );
			return false;
		}
		return $passed;
	}

	/** At the cart and checkout: never more tickets than places left. */
	public static function cart_items_check(): void {
		foreach ( self::in_cart() as $event => $qty ) {
			$left = self::left( (int) $event );
			if ( null !== $left && $qty > $left ) {
				/* translators: 1: places left, 2: event name. */
				wc_add_notice( sprintf( _n( 'Only %1$d place is left at %2$s. Please lower the number of tickets.', 'Only %1$d places are left at %2$s. Please lower the number of tickets.', $left, 'scout-events' ), $left, get_the_title( (int) $event ) ), 'error' );
			}
		}
	}

	/** The ticket settings on the product's General tab, for products that are an event's ticket. */
	public static function product_fields(): void {
		global $post;
		$event = $post ? (int) get_post_meta( $post->ID, Scout_Events_Woo::EVENT, true ) : 0;
		if ( ! $event ) {
			return;
		}
		echo '<div class="options_group se-product-ticket">';
		/* translators: %s: event name. */
		echo '<p class="form-field"><strong>' . esc_html( sprintf( __( 'A ticket for %s', 'scout-events' ), get_the_title( $event ) ) ) . '</strong></p>';
		woocommerce_wp_text_input(
			array(
				'id'          => self::RECEIPT,
				'label'       => __( 'Name on receipts', 'scout-events' ),
				'description' => __( 'What the order, the receipt and the payment service show. Leave empty to use the product name.', 'scout-events' ),
				'desc_tip'    => true,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => self::SALES_START,
				'label' => __( 'Sales open', 'scout-events' ),
				'type'  => 'date',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => self::SALES_END,
				'label' => __( 'Sales close', 'scout-events' ),
				'type'  => 'date',
			)
		);
		echo '</div>';
	}

	public static function save_product_fields( $product ): void {
		if ( ! $product instanceof WC_Product || ! self::event_of( $product ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checks the product form's nonce before this action.
		if ( isset( $_POST[ self::RECEIPT ] ) ) {
			$product->update_meta_data( self::RECEIPT, sanitize_text_field( wp_unslash( $_POST[ self::RECEIPT ] ) ) );
		}
		foreach ( array( self::SALES_START, self::SALES_END ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$product->update_meta_data( $key, Scout_Events::clean_date( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) );
			}
		}
		// phpcs:enable
	}

	/* ---------- checkout ---------- */

	/**
	 * The ticket lines in the cart: cart key => product, event and quantity.
	 *
	 * @return array<string,array{product:WC_Product,event:int,qty:int}>
	 */
	public static function cart_tickets(): array {
		$out = array();
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $out;
		}
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$event = self::event_of( (int) $item['product_id'] );
			if ( $event && ! empty( $item['data'] ) && $item['data'] instanceof WC_Product ) {
				$out[ $key ] = array(
					'product' => $item['data'],
					'event'   => $event,
					'qty'     => max( 1, (int) $item['quantity'] ),
				);
			}
		}
		return $out;
	}

	/** While the cart holds tickets, the checkout page uses the classic form, which has room for each ticket holder. */
	public static function classic_checkout( $content, $block ) {
		if ( 'woocommerce/checkout' !== ( $block['blockName'] ?? '' ) || is_admin() || ! function_exists( 'is_wc_endpoint_url' ) ) {
			return $content;
		}
		if ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) || ! self::cart_tickets() ) {
			return $content;
		}
		/** Return false to keep the block checkout (for a site that adds its own ticket holder fields there). */
		if ( ! apply_filters( 'scout_events_classic_checkout', true ) ) {
			return $content;
		}
		return do_shortcode( '[woocommerce_checkout]' );
	}

	public static function checkout_style(): void {
		if ( function_exists( 'is_checkout' ) && is_checkout() && self::cart_tickets() ) {
			wp_enqueue_style( 'scout-events' );
		}
	}

	/** Posted values for one ticket line, to fill the form again. */
	private static function posted( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read to refill the form and checked in validate(); each value is cleaned where it is used.
		$all = isset( $_POST['se_att'] ) ? (array) wp_unslash( $_POST['se_att'] ) : array();
		return isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
	}

	/** Each ticket holder's name, and their agreements when the event asks. */
	public static function checkout_fields(): void {
		$lines = self::cart_tickets();
		if ( ! $lines ) {
			return;
		}
		wp_enqueue_style( 'scout-events' );
		$n      = 0;
		$shown  = array();
		$events = array_unique( array_column( $lines, 'event' ) );
		echo '<div class="se-attendees" id="se-attendees"><h3>' . esc_html( _n( 'Who is coming', 'Who is coming', count( $lines ), 'scout-events' ) ) . '</h3>';
		echo '<p class="se-attendees-help">' . esc_html__( 'Each ticket gets its own QR code with the holder’s name on it. Every ticket comes to the email address above.', 'scout-events' ) . '</p>';
		foreach ( $lines as $key => $line ) {
			$e = Scout_Events::event( $line['event'] );
			if ( ! isset( $shown[ $line['event'] ] ) ) {
				$shown[ $line['event'] ] = true;
				if ( count( $events ) > 1 ) {
					echo '<p class="se-attendees-event">' . esc_html( get_the_title( $line['event'] ) ) . '</p>';
				}
				echo Scout_Events_Agreements::waiver_box( $e ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			}
			$posted = self::posted( (string) $key );
			for ( $i = 0; $i < $line['qty']; $i++ ) {
				++$n;
				$v    = isset( $posted[ $i ] ) && is_array( $posted[ $i ] ) ? $posted[ $i ] : array();
				$base = 'se_att[' . $key . '][' . $i . ']';
				$id   = 'se-att-' . $n;
				echo '<fieldset class="se-attendee"><legend>' . esc_html( sprintf( /* translators: 1: ticket number, 2: ticket name. */ __( 'Ticket %1$d: %2$s', 'scout-events' ), $n, $line['product']->get_name() ) ) . '</legend>';
				echo '<p class="form-row form-row-first validate-required"><label for="' . esc_attr( $id . '-name' ) . '">' . esc_html__( 'Ticket holder’s full name', 'scout-events' ) . ' <abbr class="required" title="' . esc_attr__( 'required', 'scout-events' ) . '">*</abbr></label>'
					. '<input type="text" class="input-text" id="' . esc_attr( $id . '-name' ) . '" name="' . esc_attr( $base . '[name]' ) . '" required value="' . esc_attr( (string) ( $v['name'] ?? '' ) ) . '"' . ( 1 === $n ? ' autocomplete="name"' : ' autocomplete="off"' ) . '></p>';
				echo '<p class="form-row form-row-last"><label for="' . esc_attr( $id . '-email' ) . '">' . esc_html__( 'Their email (optional)', 'scout-events' ) . '</label>'
					. '<input type="email" class="input-text" id="' . esc_attr( $id . '-email' ) . '" name="' . esc_attr( $base . '[email]' ) . '" value="' . esc_attr( (string) ( $v['email'] ?? '' ) ) . '" autocomplete="off" aria-describedby="' . esc_attr( $id . '-email-help' ) . '"><span class="description" id="' . esc_attr( $id . '-email-help' ) . '">' . esc_html__( 'They get their own copy of this ticket.', 'scout-events' ) . '</span></p>';
				echo Scout_Events_Agreements::fields( $e, $base, $id, $v, array( 'holder' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				echo '</fieldset>';
			}
		}
		echo '</div>';
	}

	public static function checkout_newsletter(): void {
		if ( ! self::cart_tickets() || Scout_Events_Agreements::newsletter_owns_checkout() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- refilling the box only.
		echo Scout_Events_Agreements::newsletter_field( 'se_newsletter', 'se-newsletter', ! empty( $_POST['se_newsletter'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/**
	 * Read and check one ticket line's holders.
	 *
	 * @return array{errors:string[],people:array<int,array>}
	 */
	private static function holders( string $key, array $line ): array {
		$posted = self::posted( $key );
		$e      = Scout_Events::event( $line['event'] );
		$errors = array();
		$people = array();
		for ( $i = 0; $i < $line['qty']; $i++ ) {
			$v     = isset( $posted[ $i ] ) && is_array( $posted[ $i ] ) ? $posted[ $i ] : array();
			$name  = sanitize_text_field( (string) ( $v['name'] ?? '' ) );
			$email = sanitize_email( (string) ( $v['email'] ?? '' ) );
			/* translators: 1: ticket number on this line, 2: ticket name. */
			$label = sprintf( __( 'Ticket %1$d (%2$s)', 'scout-events' ), $i + 1, $line['product']->get_name() );
			if ( '' === $name ) {
				/* translators: %s: which ticket. */
				$errors[] = sprintf( __( '%s: please add the ticket holder’s name.', 'scout-events' ), $label );
			}
			if ( '' !== trim( (string) ( $v['email'] ?? '' ) ) && ! is_email( $email ) ) {
				/* translators: %s: which ticket. */
				$errors[] = sprintf( __( '%s: that email address does not look right.', 'scout-events' ), $label );
			}
			$got = Scout_Events_Agreements::collect( $v, $e, $line['event'], $name );
			foreach ( $got['errors'] as $msg ) {
				$errors[] = $label . ': ' . $msg;
			}
			$people[] = array(
				'name'  => $name,
				'email' => is_email( $email ) ? $email : '',
			) + $got['data'];
		}
		return array(
			'errors' => $errors,
			'people' => $people,
		);
	}

	public static function validate( $data, $errors ): void {
		if ( ! $errors instanceof WP_Error ) {
			return;
		}
		foreach ( self::cart_tickets() as $key => $line ) {
			foreach ( self::holders( (string) $key, $line )['errors'] as $msg ) {
				$errors->add( 'se_att', $msg );
			}
		}
	}

	/** Scout Newsletter's checkout box: shown whenever the cart holds tickets, as our own box was. */
	public static function newsletter_box( $show ): bool {
		return (bool) $show || ( self::cart_tickets() && Scout_Events_Agreements::offer_newsletter() );
	}

	/** The newsletter choice on the order, with the time it was made. Scout Newsletter saves its own when active. */
	public static function order_meta( $order, $data ): void {
		if ( Scout_Events_Agreements::newsletter_owns_checkout() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce has checked the checkout nonce.
		if ( ! empty( $_POST['se_newsletter'] ) && self::cart_tickets() && Scout_Events_Agreements::offer_newsletter() ) {
			$order->update_meta_data( self::OPTIN, current_time( 'mysql' ) );
		}
	}

	/** Each order line keeps its ticket holders, and shows the receipt name. */
	public static function line_item( $item, $cart_key, $values, $order ): void {
		$pid   = (int) ( $values['product_id'] ?? 0 );
		$event = self::event_of( $pid );
		if ( ! $event ) {
			return;
		}
		$receipt = (string) get_post_meta( $pid, self::RECEIPT, true );
		if ( '' !== $receipt ) {
			$item->add_meta_data( self::PUBLIC_NAME, $item->get_name(), true );
			$item->set_name( $receipt );
		}
		if ( self::on() && did_action( 'woocommerce_checkout_process' ) ) {
			$line = array(
				'product' => $values['data'],
				'event'   => $event,
				'qty'     => max( 1, (int) $values['quantity'] ),
			);
			$item->add_meta_data( self::ATTENDEES, self::holders( (string) $cart_key, $line )['people'], true );
		}
	}

	/* ---------- paid orders become attendees ---------- */

	/** Once per paid order: one attendee per ticket, then one email to the buyer per event. */
	public static function make_attendees( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( self::MADE ) || ! $order->is_paid() ) {
			return;
		}
		$have  = array();
		foreach ( Scout_Events_RSVP::for_order( (int) $order->get_id() ) as $r ) {
			$have[ (int) $r['item_id'] ] = ( $have[ (int) $r['item_id'] ] ?? 0 ) + 1;
		}
		$buyer = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$email = (string) $order->get_billing_email();
		$optin = (string) $order->get_meta( self::OPTIN );
		$owned = Scout_Events_Agreements::newsletter_owns_checkout();
		if ( $owned ) {
			// Scout Newsletter's box: it saves the consent record itself. The ticket only notes when they joined.
			$optin = '' === Scout_Newsletter_Sources::order_optin( $order ) ? '' : ( Scout_Newsletter_Sources::is_time( $optin ) ? $optin : current_time( 'mysql' ) );
		}
		$made  = array();
		$found = false;
		foreach ( $order->get_items() as $item_id => $item ) {
			$pid   = (int) $item->get_product_id();
			$event = self::event_of( $pid );
			if ( ! $event ) {
				continue;
			}
			$found  = true;
			$people = $item->get_meta( self::ATTENDEES );
			$people = is_array( $people ) ? array_values( $people ) : array();
			$qty    = (int) $item->get_quantity() - absint( $order->get_qty_refunded_for_item( $item_id ) );
			for ( $i = $have[ (int) $item_id ] ?? 0; $i < $qty; $i++ ) {
				$p   = isset( $people[ $i ] ) && is_array( $people[ $i ] ) ? $people[ $i ] : array();
				$row = Scout_Events_RSVP::insert(
					array(
						'event_id'   => $event,
						'user_id'    => (int) $order->get_customer_id(),
						'name'       => '' !== (string) ( $p['name'] ?? '' ) ? (string) $p['name'] : $buyer,
						'email'      => '' !== (string) ( $p['email'] ?? '' ) ? (string) $p['email'] : $email,
						'status'     => 'going',
						'source'     => 'shop',
						'order_id'   => (int) $order->get_id(),
						'item_id'    => (int) $item_id,
						'product_id' => $pid,
						'newsletter' => '' !== $optin && 0 === $i && ! $made ? $optin : null,
					) + array_intersect_key( $p, array_flip( array( 'agreed', 'signature', 'waiver_version', 'age_ok', 'photo' ) ) )
				);
				if ( $row ) {
					$made[ $event ][] = $row;
				}
			}
		}
		if ( ! $found ) {
			return;
		}
		$order->update_meta_data( self::MADE, current_time( 'mysql' ) );
		$order->save();
		self::$left = array();
		foreach ( $made as $rows ) {
			Scout_Events_RSVP::email_order( $rows, $email, '' !== $buyer ? $buyer : $email );
			foreach ( $rows as $row ) {
				// A holder with their own address gets their own copy.
				if ( strtolower( $row['email'] ) !== strtolower( $email ) ) {
					Scout_Events_RSVP::email( $row );
				}
			}
		}
		if ( ! $owned && '' !== $optin && $made && ! $order->get_meta( self::OPTIN_SENT ) ) {
			$order->update_meta_data( self::OPTIN_SENT, current_time( 'mysql' ) );
			$order->save();
			Scout_Events_Agreements::optin( $email, $buyer, 'ticket', array_keys( $made ) );
		}
	}

	/* ---------- refunds void tickets ---------- */

	/** A refunded or cancelled order: every ticket from it is void. */
	public static function void_order( $order_id ): void {
		foreach ( Scout_Events_RSVP::for_order( (int) $order_id ) as $r ) {
			if ( 'void' !== $r['status'] ) {
				Scout_Events_RSVP::set_status( (int) $r['id'], 'void' );
			}
		}
	}

	/**
	 * A partial refund: as many tickets on each line as were refunded turn void,
	 * those not yet checked in first, newest first.
	 */
	public static function refunded( $order_id, $refund_id = 0 ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$rows = Scout_Events_RSVP::for_order( (int) $order_id );
		if ( ! $rows ) {
			return;
		}
		foreach ( $order->get_items() as $item_id => $item ) {
			$refunded = absint( $order->get_qty_refunded_for_item( $item_id ) );
			$line     = array_values( array_filter( $rows, static fn( $r ) => (int) $r['item_id'] === (int) $item_id ) );
			$void     = count( array_filter( $line, static fn( $r ) => 'void' === $r['status'] ) );
			$live     = array_values( array_filter( $line, static fn( $r ) => 'void' !== $r['status'] ) );
			usort( $live, static fn( $a, $b ) => array( (int) ! empty( $a['checked_in'] ), - (int) $a['id'] ) <=> array( (int) ! empty( $b['checked_in'] ), - (int) $b['id'] ) );
			for ( $i = 0; $i < $refunded - $void && $i < count( $live ); $i++ ) {
				Scout_Events_RSVP::set_status( (int) $live[ $i ]['id'], 'void' );
			}
		}
	}

	/* ---------- the order screen ---------- */

	/** Under each ticket line on the order: the holders, their status and answers. */
	public static function admin_item( $item_id, $item, $product ): void {
		if ( ! $item instanceof WC_Order_Item_Product || ! self::event_of( (int) $item->get_product_id() ) ) {
			return;
		}
		$rows = array_filter( Scout_Events_RSVP::for_order( (int) $item->get_order_id() ), static fn( $r ) => (int) $r['item_id'] === (int) $item_id );
		if ( ! $rows ) {
			echo '<p class="se-order-note">' . esc_html__( 'Tickets are made when the order is paid (Processing or Completed).', 'scout-events' ) . '</p>';
			return;
		}
		$public = (string) $item->get_meta( self::PUBLIC_NAME );
		echo '<div class="se-order-tickets">';
		if ( '' !== $public ) {
			/* translators: %s: ticket name on the site. */
			echo '<p>' . esc_html( sprintf( __( 'On the site: %s', 'scout-events' ), $public ) ) . '</p>';
		}
		echo '<ul>';
		foreach ( $rows as $r ) {
			$bits = array( Scout_Events_RSVP::statuses()[ $r['status'] ] ?? $r['status'] );
			if ( ! empty( $r['checked_in'] ) ) {
				$bits[] = __( 'checked in', 'scout-events' );
			}
			if ( ! empty( $r['agreed'] ) ) {
				/* translators: %s: date and time. */
				$bits[] = sprintf( __( 'agreed %s', 'scout-events' ), mysql2date( 'M j, g:i a', (string) $r['agreed'] ) );
			}
			if ( '' !== $r['photo'] ) {
				$bits[] = 'no' === $r['photo'] ? __( 'no photos', 'scout-events' ) : __( 'photos OK', 'scout-events' );
			}
			echo '<li><a href="' . esc_url( Scout_Events_RSVP::ticket_url( $r ) ) . '">' . esc_html( $r['name'] ) . '</a> · ' . esc_html( implode( ', ', $bits ) ) . '</li>';
		}
		echo '</ul></div>';
	}
}
