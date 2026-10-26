<?php
/**
 * One customer record per person, in FluentCRM.
 *
 * Listens to Scout Events and Scout Members and adds or updates the person in
 * FluentCRM, with tags for what they did, the event and its region:
 *
 *   Attendee · Waitlist · Volunteer · Vendor · Presenter · Ticket buyer
 *   Member applicant · Member · Plan: Club Member
 *   Event: Midwest Prepper Camp 2027 · Region: Midwest · Ticket: Weekend pass
 *
 * New people come in as "transactional": FluentCRM can send them receipts
 * and event notes, and they join marketing emails only when they subscribe.
 * People already subscribed or unsubscribed keep that status.
 *
 * The tag names go through the scout_crm_tags filter ( $tags, $context ),
 * and scout_crm_contact ( $data, $context ) can add fields, so a site can
 * change the scheme without touching this file.
 *
 * @package Scout_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_CRM {

	public static function boot(): void {
		if ( ! self::ready() ) {
			return;
		}
		add_action( 'scout_events_person', array( __CLASS__, 'person' ) );
		add_action( 'scout_events_ticket_bought', array( __CLASS__, 'ticket' ), 10, 4 );
		add_action( 'scout_members_status_changed', array( __CLASS__, 'member' ), 10, 3 );
	}

	public static function ready(): bool {
		return function_exists( 'FluentCrmApi' );
	}

	/* ---------- the listeners ---------- */

	/** A sign-up, volunteer, vendor or presenter from Scout Events. */
	public static function person( $p ): void {
		$p     = (array) $p;
		$roles = array(
			'attendee'  => __( 'Attendee', 'scout-crm' ),
			'waitlist'  => __( 'Waitlist', 'scout-crm' ),
			'volunteer' => __( 'Volunteer', 'scout-crm' ),
			'vendor'    => __( 'Vendor', 'scout-crm' ),
			'presenter' => __( 'Presenter', 'scout-crm' ),
		);
		$role  = (string) ( $p['role'] ?? '' );
		$tags  = array_merge( isset( $roles[ $role ] ) ? array( $roles[ $role ] ) : array(), self::event_tags( (int) ( $p['event'] ?? 0 ) ) );
		self::save(
			array(
				'email'    => (string) ( $p['email'] ?? '' ),
				'name'     => (string) ( $p['name'] ?? '' ),
				'phone'    => (string) ( $p['phone'] ?? '' ),
				'company'  => (string) ( $p['org'] ?? '' ),
			),
			$tags,
			'events-' . $role
		);
	}

	/** A paid ticket line from the shop (Scout Events with WooCommerce). */
	public static function ticket( $order, $event, $product, $qty ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$p    = wc_get_product( (int) $product );
		$tags = array_merge( array( __( 'Ticket buyer', 'scout-crm' ) ), self::event_tags( (int) $event ) );
		if ( $p ) {
			/* translators: %s: ticket product name. */
			$tags[] = sprintf( __( 'Ticket: %s', 'scout-crm' ), $p->get_name() );
		}
		self::save(
			array(
				'email'     => (string) $order->get_billing_email(),
				'first'     => (string) $order->get_billing_first_name(),
				'last'      => (string) $order->get_billing_last_name(),
				'phone'     => (string) $order->get_billing_phone(),
				'city'      => (string) $order->get_billing_city(),
				'state'     => (string) $order->get_billing_state(),
				'postal'    => (string) $order->get_billing_postcode(),
				'country'   => (string) $order->get_billing_country(),
			),
			$tags,
			'ticket'
		);
	}

	/** Membership: applied, approved, paused, ended or not accepted (Scout Members). */
	public static function member( $user_id, $new, $old ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! class_exists( 'Scout_Members' ) ) {
			return;
		}
		$m       = Scout_Members::member( (int) $user_id );
		$member  = __( 'Member', 'scout-crm' );
		$applied = __( 'Member applicant', 'scout-crm' );
		$person  = array(
			'email' => (string) $user->user_email,
			'first' => (string) $user->first_name,
			'last'  => (string) $user->last_name,
		);
		if ( 'applied' === $new ) {
			self::save( $person, array( $applied ), 'member-applied' );
		} elseif ( 'active' === $new ) {
			$plan = '' !== $m['plan'] ? (string) ( Scout_Members::plans()[ $m['plan'] ]['name'] ?? '' ) : '';
			/* translators: %s: membership plan name. */
			$tags = array_filter( array( $member, '' !== $plan ? sprintf( __( 'Plan: %s', 'scout-crm' ), $plan ) : '' ) );
			self::save( $person, $tags, 'member-active', array( $applied ) );
		} elseif ( in_array( $new, array( 'paused', 'ended', 'declined' ), true ) ) {
			// They stay in the list, without the Member tag.
			self::save( $person, array(), 'member-' . $new, array( $member, $applied ) );
		}
	}

	/* ---------- tags from an event ---------- */

	/** "Event: …" and "Region: …" for an event. */
	public static function event_tags( int $event ): array {
		if ( ! $event || 'scout_event' !== get_post_type( $event ) ) {
			return array();
		}
		/* translators: %s: event name. */
		$tags = array( sprintf( __( 'Event: %s', 'scout-crm' ), wp_specialchars_decode( get_the_title( $event ), ENT_QUOTES ) ) );
		if ( class_exists( 'Scout_Events_Regions' ) ) {
			foreach ( Scout_Events_Regions::own( $event ) as $name ) {
				/* translators: %s: region name. */
				$tags[] = sprintf( __( 'Region: %s', 'scout-crm' ), $name );
			}
		}
		return $tags;
	}

	/* ---------- FluentCRM ---------- */

	/**
	 * Add or update the person and their tags. $remove takes tags off.
	 *
	 * @param array<string,string> $p email, and any of name, first, last, phone, company, city, state, postal, country.
	 */
	public static function save( array $p, array $tags, string $context, array $remove = array() ): bool {
		$email = sanitize_email( (string) ( $p['email'] ?? '' ) );
		if ( ! self::ready() || ! is_email( $email ) ) {
			return false;
		}
		$first = (string) ( $p['first'] ?? '' );
		$last  = (string) ( $p['last'] ?? '' );
		if ( '' === $first && '' !== (string) ( $p['name'] ?? '' ) ) {
			$parts = preg_split( '/\s+/', trim( (string) $p['name'] ), 2 );
			$first = (string) ( $parts[0] ?? '' );
			$last  = (string) ( $parts[1] ?? '' );
		}
		$tags = (array) apply_filters( 'scout_crm_tags', array_values( array_unique( array_filter( $tags ) ) ), $context );
		$data = array_filter(
			array(
				'email'          => strtolower( $email ),
				'first_name'     => sanitize_text_field( $first ),
				'last_name'      => sanitize_text_field( $last ),
				'phone'          => sanitize_text_field( (string) ( $p['phone'] ?? '' ) ),
				'company_name'   => sanitize_text_field( (string) ( $p['company'] ?? '' ) ),
				'city'           => sanitize_text_field( (string) ( $p['city'] ?? '' ) ),
				'state'          => sanitize_text_field( (string) ( $p['state'] ?? '' ) ),
				'postal_code'    => sanitize_text_field( (string) ( $p['postal'] ?? '' ) ),
				'country'        => sanitize_text_field( (string) ( $p['country'] ?? '' ) ),
				// Receipts and event notes, not marketing: people subscribe themselves.
				'status'         => 'transactional',
				'source'         => 'scout',
			)
		);
		$data['tags'] = self::tag_ids( $tags );
		$data         = (array) apply_filters( 'scout_crm_contact', $data, $context );
		try {
			// Someone already in the list keeps their status, including a pending double opt-in.
			if ( FluentCrmApi( 'contacts' )->getContact( $data['email'] ) ) {
				unset( $data['status'] );
			}
			$contact = FluentCrmApi( 'contacts' )->createOrUpdate( $data );
			if ( $contact && $remove ) {
				$ids = self::tag_ids( $remove, false );
				if ( $ids ) {
					$contact->detachTags( $ids );
				}
			}
			return (bool) $contact;
		} catch ( \Throwable $e ) {
			// A CRM hiccup must never stop a ticket, sign-up or application.
			return false;
		}
	}

	/** Tag ids for tag names, making missing ones unless $make is false. */
	public static function tag_ids( array $names, bool $make = true ): array {
		static $known = array();
		$ids = array();
		foreach ( $names as $name ) {
			$name = sanitize_text_field( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$slug = sanitize_title( $name );
			if ( empty( $known[ $slug ] ) ) {
				$tag = \FluentCrm\App\Models\Tag::where( 'slug', $slug )->first();
				if ( ! $tag && $make ) {
					$made = FluentCrmApi( 'tags' )->importBulk( array( array( 'title' => $name, 'slug' => $slug ) ) );
					$tag  = $made[0] ?? null;
				}
				// Only real ids are remembered: a tag missing now may be made a moment later.
				if ( $tag ) {
					$known[ $slug ] = (int) $tag->id;
				}
			}
			if ( ! empty( $known[ $slug ] ) ) {
				$ids[] = $known[ $slug ];
			}
		}
		return array_values( array_unique( $ids ) );
	}
}
