<?php
/**
 * Tickets from a WooCommerce shop, when the site has one.
 *
 * Each ticket type is a WooCommerce product (Weekend pass, Day pass, VIP,
 * a camping spot), with its own price and stock. On the event, the "Tickets
 * from the shop" box picks its products. Then, on the site:
 *
 * - the event page shows a ticket list in place of the single ticket button:
 *   each product with its price, "12 left" when stock is low, Sold out, and
 *   Add to cart;
 * - the event's price and on sale / sold out state come from the products,
 *   so event cards, the details panel and Google's event listing follow the
 *   shop by themselves;
 * - each product page names the event it is for.
 *
 * A paid order fires scout_events_ticket_bought ( $order, $event_id,
 * $product_id, $qty ) once per ticket line, so other features (the customer
 * list) can tag buyers by event and region. QR tickets and gate scanning come
 * from a WooCommerce ticket plugin such as FooEvents, on the same products.
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_Woo {

	const META  = '_scout_event_products';
	const EVENT = '_scout_event_id';
	const DONE  = '_scout_events_tickets_noted';

	/** "12 left" shows at or under this many. */
	const LOW = 20;

	/** WooCommerce loads after Scout Events, so this runs on plugins_loaded. */
	public static function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_action( 'add_meta_boxes_' . Scout_Events::EVENT, array( __CLASS__, 'box' ) );
		add_action( 'save_post_' . Scout_Events::EVENT, array( __CLASS__, 'save' ) );
		add_filter( 'scout_events_event', array( __CLASS__, 'live' ), 10, 2 );
		add_filter( 'scout_events_ticket_html', array( __CLASS__, 'tickets' ), 5, 3 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'product_note' ), 25 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'paid' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'paid' ) );
		add_filter( 'manage_' . Scout_Events::EVENT . '_posts_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_' . Scout_Events::EVENT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	/* ---------- which products ---------- */

	/** @return int[] the event's ticket products, in the order picked */
	public static function product_ids( int $event ): array {
		$ids = get_post_meta( $event, self::META, true );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : array();
	}

	/** @return WC_Product[] the ones that exist and are published */
	public static function products( int $event ): array {
		$out = array();
		foreach ( self::product_ids( $event ) as $pid ) {
			$p = wc_get_product( $pid );
			if ( $p && 'publish' === $p->get_status() ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/* ---------- the event box ---------- */

	public static function box(): void {
		add_meta_box( 'scout-event-woo', __( 'Tickets from the shop', 'scout-events' ), array( __CLASS__, 'render_box' ), Scout_Events::EVENT, 'normal', 'default' );
	}

	public static function render_box( $post ): void {
		wp_nonce_field( 'scout_event_woo_' . $post->ID, '_se_woo' );
		$chosen = self::product_ids( (int) $post->ID );
		$all    = wc_get_products(
			array(
				'status'  => array( 'publish', 'private', 'draft' ),
				'limit'   => 300,
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);
		echo '<p class="description">' . esc_html__( 'Tick the shop products that are tickets for this event: one per ticket type, each with its own price and stock. The event page then lists them with Add to cart, and the event shows as sold out on its own when they are all gone. Leave all unticked to use the ticket link above instead.', 'scout-events' ) . '</p>';
		if ( ! $all ) {
			echo '<p>' . esc_html__( 'No products yet.', 'scout-events' ) . ' <a href="' . esc_url( admin_url( 'post-new.php?post_type=product' ) ) . '">' . esc_html__( 'Add a ticket product', 'scout-events' ) . '</a></p>';
			return;
		}
		echo '<p><input type="search" class="widefat se-woo-find" placeholder="' . esc_attr__( 'Find a product', 'scout-events' ) . '" aria-label="' . esc_attr__( 'Find a product', 'scout-events' ) . '"></p><div class="se-woo-list" style="max-height:16em;overflow:auto">';
		// Ticked ones first, so they are easy to see.
		usort( $all, static fn( $a, $b ) => (int) in_array( $b->get_id(), $chosen, true ) <=> (int) in_array( $a->get_id(), $chosen, true ) );
		foreach ( $all as $p ) {
			$stock = $p->managing_stock() ? sprintf( /* translators: %d: items in stock. */ __( '%d in stock', 'scout-events' ), (int) $p->get_stock_quantity() ) : ( $p->is_in_stock() ? __( 'in stock', 'scout-events' ) : __( 'out of stock', 'scout-events' ) );
			$state = 'publish' !== $p->get_status() ? ' (' . __( 'not published', 'scout-events' ) . ')' : '';
			echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="se_woo[]" value="' . (int) $p->get_id() . '"' . checked( in_array( $p->get_id(), $chosen, true ), true, false ) . '> '
				. esc_html( $p->get_name() . $state ) . ' <span class="description">· ' . wp_kses_post( wc_price( (float) $p->get_price() ) ) . ' · ' . esc_html( $stock ) . '</span></label>';
		}
		echo '</div>';
		echo '<script>document.querySelectorAll(".se-woo-find").forEach(function(f){f.addEventListener("input",function(){var q=f.value.toLowerCase();f.closest(".inside").querySelectorAll(".se-woo-list label").forEach(function(l){l.hidden=q&&l.textContent.toLowerCase().indexOf(q)<0;});});});</script>';
	}

	public static function save( $post_id ): void {
		if ( ! isset( $_POST['_se_woo'] ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_se_woo'] ) ), 'scout_event_woo_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$new = isset( $_POST['se_woo'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['se_woo'] ) ) ) ) ) : array();
		$old = self::product_ids( (int) $post_id );
		// Each product remembers its event too, for its own page and for orders.
		foreach ( array_diff( $old, $new ) as $pid ) {
			if ( (int) get_post_meta( $pid, self::EVENT, true ) === (int) $post_id ) {
				delete_post_meta( $pid, self::EVENT );
			}
		}
		foreach ( $new as $pid ) {
			update_post_meta( $pid, self::EVENT, (int) $post_id );
		}
		if ( $new ) {
			update_post_meta( $post_id, self::META, $new );
		} else {
			delete_post_meta( $post_id, self::META );
		}
	}

	/* ---------- the event follows the shop ---------- */

	/** Price and on sale / sold out from the products, unless the event is cancelled or not on sale yet. */
	public static function live( $e, $id ): array {
		$e        = (array) $e;
		$products = self::products( (int) $id );
		if ( ! $products ) {
			return $e;
		}
		$prices = array_map( static fn( $p ) => (float) wc_get_price_to_display( $p ), $products );
		$open   = array_filter( $products, static fn( $p ) => $p->is_in_stock() && $p->is_purchasable() );
		$low    = min( $prices );
		$high   = max( $prices );
		$e['price_from'] = 0.0 === $high ? '' : (string) $low;
		$e['price_to']   = $high > $low ? (string) $high : '';
		$e['ticket_url'] = (string) get_permalink( (int) $id ) . '#se-tickets';
		if ( 'notyet' !== $e['availability'] && 'cancelled' !== $e['status'] ) {
			$e['availability'] = $open ? ( 0.0 === $high ? 'free' : 'onsale' ) : 'soldout';
		}
		return $e;
	}

	/** The ticket list on the event page, in place of the single button. */
	public static function tickets( $html, $id, $e ): string {
		$id       = (int) $id;
		$e        = (array) $e;
		$products = self::products( $id );
		if ( ! $products || 'cancelled' === $e['status'] || Scout_Events::is_past( $e ) || 'notyet' === $e['availability'] ) {
			return (string) $html;
		}
		$cart = wc_get_cart_url();
		$out  = '<div class="se-tickets" id="se-tickets"><h3 class="se-tickets-title">' . esc_html__( 'Tickets', 'scout-events' ) . '</h3><ul class="se-ticket-list">';
		foreach ( $products as $p ) {
			$pid   = (int) $p->get_id();
			$about = wp_strip_all_tags( (string) $p->get_short_description() );
			$out  .= '<li class="se-ticket"><div class="se-ticket-what"><span class="se-ticket-name">' . esc_html( $p->get_name() ) . '</span>';
			if ( '' !== $about ) {
				$out .= '<span class="se-ticket-about">' . esc_html( wp_trim_words( $about, 30 ) ) . '</span>';
			}
			$out .= '</div><span class="se-ticket-price">' . wp_kses_post( $p->get_price_html() ) . '</span>';
			if ( ! $p->is_in_stock() || ! $p->is_purchasable() ) {
				$out .= '<span class="se-soldout">' . esc_html__( 'Sold out', 'scout-events' ) . '</span>';
			} elseif ( ! $p->is_type( 'simple' ) ) {
				// Sizes, dates or other choices are picked on the product's own page.
				$out .= '<a class="se-button wp-element-button" href="' . esc_url( $p->get_permalink() ) . '">' . esc_html__( 'Choose', 'scout-events' ) . '</a>';
			} else {
				$left = $p->managing_stock() ? (int) $p->get_stock_quantity() : 0;
				$max  = $p->managing_stock() && ! $p->backorders_allowed() ? max( 1, $left ) : 20;
				if ( $p->managing_stock() && $left > 0 && $left <= self::LOW ) {
					/* translators: %d: tickets left. */
					$out .= '<span class="se-ticket-left">' . esc_html( sprintf( _n( '%d left', '%d left', $left, 'scout-events' ), $left ) ) . '</span>';
				}
				$out .= '<form class="se-ticket-buy" method="post" action="' . esc_url( $cart ) . '">'
					. '<label class="screen-reader-text" for="se-qty-' . $pid . '">' . esc_html__( 'How many', 'scout-events' ) . '</label>'
					. '<input type="number" id="se-qty-' . $pid . '" name="quantity" value="1" min="1" max="' . (int) min( $max, 20 ) . '" inputmode="numeric">'
					. '<input type="hidden" name="add-to-cart" value="' . $pid . '">'
					. '<button type="submit" class="se-button wp-element-button" data-scout-track="ticket_click" data-scout-event="' . esc_attr( get_the_title( $id ) ) . '">' . esc_html__( 'Add to cart', 'scout-events' ) . '</button></form>';
			}
			$out .= '</li>';
		}
		$out .= '</ul><p class="se-tickets-cart"><a href="' . esc_url( $cart ) . '">' . esc_html__( 'View cart and check out', 'scout-events' ) . '</a></p></div>';
		return $out;
	}

	/** "A ticket for: Event, dates" on the product page. */
	public static function product_note(): void {
		$pid   = (int) get_the_ID();
		$event = (int) get_post_meta( $pid, self::EVENT, true );
		if ( ! $event || 'publish' !== get_post_status( $event ) ) {
			return;
		}
		$e = Scout_Events::event( $event );
		/* translators: %s: event name and dates, linked. */
		echo '<p class="se-product-event">' . wp_kses_post( sprintf( __( 'A ticket for %s', 'scout-events' ), '<a href="' . esc_url( (string) get_permalink( $event ) ) . '">' . esc_html( get_the_title( $event ) ) . '</a>' . ( '' !== Scout_Events::dates_label( $e ) ? ', ' . esc_html( Scout_Events::dates_label( $e ) ) : '' ) ) ) . '</p>';
	}

	/* ---------- orders ---------- */

	/** Once per paid order: one action per ticket line, with its event. */
	public static function paid( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( self::DONE ) ) {
			return;
		}
		$found = false;
		foreach ( $order->get_items() as $item ) {
			$pid   = (int) $item->get_product_id();
			$event = (int) get_post_meta( $pid, self::EVENT, true );
			if ( $event ) {
				$found = true;
				/** A ticket was bought: the order, the event, the product and how many. */
				do_action( 'scout_events_ticket_bought', $order, $event, $pid, (int) $item->get_quantity() );
			}
		}
		if ( $found ) {
			$order->update_meta_data( self::DONE, 1 );
			$order->save();
		}
	}

	/* ---------- the events list ---------- */

	public static function columns( $cols ): array {
		$cols            = (array) $cols;
		$cols['se_sold'] = __( 'Tickets sold', 'scout-events' );
		return $cols;
	}

	public static function column( $col, $id ): void {
		if ( 'se_sold' !== $col ) {
			return;
		}
		$products = self::products( (int) $id );
		if ( ! $products ) {
			// Events without shop tickets leave the cell empty.
			return;
		}
		$sold = array_sum( array_map( static fn( $p ) => (int) $p->get_total_sales(), $products ) );
		echo esc_html( number_format_i18n( $sold ) );
	}
}
