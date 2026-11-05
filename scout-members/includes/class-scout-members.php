<?php
/**
 * The membership model: who is a member, since when, until when, and what
 * they said when they applied.
 *
 * A member is a WordPress user with the scout_member role and a status:
 * applied, active, paused, ended or declined. Only an active member whose
 * renewal date has not passed counts as a member. The status lives in its own
 * user meta key so lists can query it; the rest sits in one array.
 *
 * Each member is on one of the owner's plans (name, price, how often it
 * renews). A payment moves the renewal date forward one period: the owner
 * records one with Paid today, and with WooCommerce a paid dues order does the
 * same through paid() (see Scout_Members_Woo). After the renewal date, a grace
 * period keeps the doors open while the member renews.
 *
 * @package Scout_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Members {

	const ROLE   = 'scout_member';
	const STATUS = '_scout_member_status';
	const META   = '_scout_member';
	const OPTION = 'scout_members';

	/** Post meta that marks a page, post or event as members only. */
	const ONLY = '_scout_members_only';

	/** Post meta: the plans a members-only page is limited to. Empty means every member. */
	const PLANS = '_scout_members_plans';

	/** User meta: what happened to a membership and when, newest last. */
	const LOG = '_scout_member_log';

	/** User meta: a payment the owner should look at (a refund, a payment from a declined applicant). */
	const REVIEW = '_scout_member_review';

	/** Days of access after the renewal date on a fresh install. Sites upgrading from before 0.4.0 start at 0. */
	const GRACE = 14;

	/** Option: the plugin version whose upgrade steps have run. */
	const VERSION = 'scout_members_version';

	/** Renewal reminders: to members whose plan has a dues product (the default), to every member, or none. */
	const REMINDERS = array( 'checkout', 'all', 'off' );

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'upgrade' ), 5 );
		add_action( 'init', array( __CLASS__, 'role' ), 6 );
	}

	/**
	 * Once per version. A site that already had Scout Members before 0.4.0
	 * keeps its old behaviour: access ends the day after the renewal date
	 * (grace 0) until the owner sets a grace period. Fresh installs get 14.
	 */
	public static function upgrade(): void {
		$done = (string) get_option( self::VERSION, '' );
		if ( SCOUT_MEMBERS_VERSION === $done ) {
			return;
		}
		if ( '' === $done || version_compare( $done, '0.4.0', '<' ) ) {
			$s   = get_option( self::OPTION, null );
			$had = is_array( $s ) || self::has_records();
			$s   = is_array( $s ) ? $s : array();
			if ( $had && ! isset( $s['grace'] ) ) {
				$s['grace'] = 0;
				update_option( self::OPTION, $s, false );
			}
		}
		update_option( self::VERSION, SCOUT_MEMBERS_VERSION, false );
	}

	/** Anyone with a membership record at all. */
	private static function has_records(): bool {
		return (bool) get_users(
			array(
				'meta_key' => self::STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one row, once per version.
				'fields'   => 'ID',
				'number'   => 1,
			)
		);
	}

	/** Members read the site and nothing else; the dashboard stays closed to them. */
	public static function role(): void {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, __( 'Member', 'scout-members' ), array( 'read' => true ) );
		}
	}

	/** @return array<string,string> status => label */
	public static function statuses(): array {
		return array(
			'applied'  => __( 'Applied', 'scout-members' ),
			'approved' => __( 'Approved, dues to pay', 'scout-members' ),
			'active'   => __( 'Member', 'scout-members' ),
			'paused'   => __( 'Paused', 'scout-members' ),
			'ended'    => __( 'Ended', 'scout-members' ),
			'declined' => __( 'Not accepted', 'scout-members' ),
		);
	}

	/* ---------- settings ---------- */

	/**
	 * grace: days members keep access after their renewal date (14 on a fresh
	 * install, 0 on a site upgraded from before 0.4.0). reminders: emails 30
	 * and 7 days before it, "checkout" (only members whose plan has a dues
	 * product, so every email has a pay link), "all" or "off". pay_when: with
	 * a checkout, "approval" (pay once approved) or "apply" (pay with the
	 * application). receipt: the words that start each dues product's name.
	 *
	 * @return array{page:int,home:int,agreements_url:string,notify:string,min_age:int,plans:string,grace:int,reminders:string,pay_when:string,receipt:string}
	 */
	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'page'           => (int) ( $s['page'] ?? 0 ),
			'home'           => (int) ( $s['home'] ?? 0 ),
			'agreements_url' => (string) ( $s['agreements_url'] ?? '' ),
			'notify'         => (string) ( $s['notify'] ?? '' ),
			'min_age'        => max( 0, (int) ( $s['min_age'] ?? 0 ) ),
			'plans'          => (string) ( $s['plans'] ?? '' ),
			'grace'          => isset( $s['grace'] ) ? max( 0, (int) $s['grace'] ) : self::GRACE,
			'reminders'      => in_array( $s['reminders'] ?? '', self::REMINDERS, true ) ? (string) $s['reminders'] : 'checkout',
			'pay_when'       => 'apply' === ( $s['pay_when'] ?? '' ) ? 'apply' : 'approval',
			'receipt'        => (string) ( $s['receipt'] ?? '' ),
		);
	}

	public static function save_settings( array $in ): void {
		$now = self::settings();
		update_option(
			self::OPTION,
			array(
				'page'           => absint( $in['page'] ?? 0 ),
				'home'           => absint( $in['home'] ?? 0 ),
				'agreements_url' => esc_url_raw( trim( (string) ( $in['agreements_url'] ?? '' ) ) ),
				'notify'         => sanitize_email( (string) ( $in['notify'] ?? '' ) ),
				'min_age'        => min( 99, absint( $in['min_age'] ?? 0 ) ),
				'plans'          => sanitize_textarea_field( (string) ( $in['plans'] ?? '' ) ),
				// Left out, both keep what the site has: an upgraded site's 0 never turns into 14 by a save.
				'grace'          => isset( $in['grace'] ) && '' !== (string) $in['grace'] ? min( 90, absint( $in['grace'] ) ) : $now['grace'],
				'reminders'      => in_array( $in['reminders'] ?? '', self::REMINDERS, true ) ? (string) $in['reminders'] : $now['reminders'],
				'pay_when'       => 'apply' === ( $in['pay_when'] ?? '' ) ? 'apply' : 'approval',
				'receipt'        => sanitize_text_field( (string) ( $in['receipt'] ?? '' ) ),
			),
			false
		);
	}

	/** "Bliss Boogie Connections membership": the setting, else the site name and "membership". */
	public static function receipt_name(): string {
		$name = trim( self::settings()['receipt'] );
		/* translators: %s: site name. */
		return '' !== $name ? $name : sprintf( __( '%s membership', 'scout-members' ), wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
	}

	/**
	 * The plans, written one per line in Settings as "Name | price | period",
	 * where period is month, year or once. "Club Member | 120 | year".
	 *
	 * @return array<string,array{name:string,price:string,period:string}> keyed by a slug of the name
	 */
	public static function plans(): array {
		$out = array();
		foreach ( preg_split( '/\R/', self::settings()['plans'] ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( '' === ( $parts[0] ?? '' ) ) {
				continue;
			}
			$period = strtolower( $parts[2] ?? '' );
			$period = in_array( $period, array( 'month', 'year', 'once' ), true ) ? $period : 'year';
			$price  = preg_replace( '/[^0-9.]/', '', (string) ( $parts[1] ?? '' ) );
			$out[ sanitize_title( $parts[0] ) ] = array(
				'name'   => $parts[0],
				'price'  => (string) $price,
				'period' => $period,
			);
		}
		return $out;
	}

	/** "$120 a year", or the name alone when no price is set. */
	public static function plan_label( string $slug ): string {
		$p = self::plans()[ $slug ] ?? null;
		if ( ! $p ) {
			return '';
		}
		if ( '' === $p['price'] ) {
			return $p['name'];
		}
		$amount = self::money( $p['price'] );
		$every  = array(
			'month' => __( 'a month', 'scout-members' ),
			'year'  => __( 'a year', 'scout-members' ),
			'once'  => __( 'once', 'scout-members' ),
		);
		/* translators: 1: plan name, 2: amount, 3: "a month", "a year" or "once". */
		return sprintf( __( '%1$s, %2$s %3$s', 'scout-members' ), $p['name'], $amount, $every[ $p['period'] ] );
	}

	/** "$120", or "$62.50". */
	public static function money( $amount ): string {
		$a = (float) $amount;
		return '$' . ( floor( $a ) == $a ? number_format_i18n( $a ) : number_format_i18n( $a, 2 ) ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- whole-dollar check on floats.
	}

	/** The plan whose name matches, ignoring case and spacing. Empty when none does. */
	public static function plan_by_name( string $name ): string {
		$slug = sanitize_title( $name );
		return isset( self::plans()[ $slug ] ) ? $slug : '';
	}

	/** The membership page: the one set in Settings, else the first page that shows the form. */
	public static function page_url(): string {
		$id  = self::settings()['page'];
		$url = $id && 'publish' === get_post_status( $id ) ? get_permalink( $id ) : '';
		return $url ? (string) $url : home_url( '/' );
	}

	/** The members home: the page members land on, with everything that is theirs. 0 when not set up. */
	public static function home_id(): int {
		$id = self::settings()['home'];
		return $id && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	/** The members home for members, else the membership page. */
	public static function home_url(): string {
		return self::home_id() ? (string) get_permalink( self::home_id() ) : self::page_url();
	}

	/** Where new applications are announced. */
	public static function notify_email(): string {
		$to = self::settings()['notify'];
		return is_email( $to ) ? $to : self::shared_notice_email();
	}

	/** The site's shared notice address from Business info, or the site email without Scout Core. */
	public static function shared_notice_email(): string {
		return function_exists( 'scout_core_notice_email' ) ? (string) scout_core_notice_email() : (string) get_option( 'admin_email' );
	}

	/* ---------- members ---------- */

	/**
	 * One member's record. Empty status means the user never applied.
	 *
	 * comp: a renewal price the owner set for this member ("0" renews free);
	 * empty means the plan's price. free_join: they came in on a $0 order.
	 * prepaid: the order that paid with the application, held until approval.
	 * reminded: the renewal date each reminder (30, 7) was last sent for.
	 *
	 * @return array{status:string,plan:string,applied:string,since:string,renews:string,paid:string,note:string,comp:string,free_join:string,prepaid:int,reminded:array<string,string>,answers:array<string,string>}
	 */
	public static function member( int $user_id ): array {
		$m = get_user_meta( $user_id, self::META, true );
		$m = is_array( $m ) ? $m : array();
		return array(
			'status'    => (string) get_user_meta( $user_id, self::STATUS, true ),
			'plan'      => (string) ( $m['plan'] ?? '' ),
			'applied'   => (string) ( $m['applied'] ?? '' ),
			'since'     => (string) ( $m['since'] ?? '' ),
			'renews'    => (string) ( $m['renews'] ?? '' ),
			'paid'      => (string) ( $m['paid'] ?? '' ),
			'note'      => (string) ( $m['note'] ?? '' ),
			'comp'      => (string) ( $m['comp'] ?? '' ),
			'free_join' => (string) ( $m['free_join'] ?? '' ),
			'prepaid'   => (int) ( $m['prepaid'] ?? 0 ),
			'reminded'  => is_array( $m['reminded'] ?? null ) ? $m['reminded'] : array(),
			'answers'   => is_array( $m['answers'] ?? null ) ? $m['answers'] : array(),
		);
	}

	/** Save the parts of a record other than the status. */
	public static function save( int $user_id, array $changes ): void {
		$m = array_merge( self::raw( $user_id ), self::member( $user_id ) );
		unset( $m['status'] );
		foreach ( array( 'applied', 'since', 'renews', 'paid', 'note', 'plan' ) as $key ) {
			if ( ! isset( $changes[ $key ] ) ) {
				continue;
			}
			if ( 'note' === $key ) {
				$m[ $key ] = sanitize_textarea_field( (string) $changes[ $key ] );
			} elseif ( 'plan' === $key ) {
				$m[ $key ] = sanitize_title( (string) $changes[ $key ] );
			} else {
				$m[ $key ] = self::clean_date( (string) $changes[ $key ] );
			}
		}
		if ( isset( $changes['comp'] ) ) {
			$m['comp'] = self::clean_amount( (string) $changes['comp'] );
		}
		if ( isset( $changes['free_join'] ) ) {
			$m['free_join'] = $changes['free_join'] ? '1' : '';
		}
		if ( isset( $changes['prepaid'] ) ) {
			$m['prepaid'] = absint( $changes['prepaid'] );
		}
		if ( isset( $changes['reminded'] ) && is_array( $changes['reminded'] ) ) {
			$m['reminded'] = array_map( array( __CLASS__, 'clean_date' ), array_map( 'strval', $changes['reminded'] ) );
		}
		if ( isset( $changes['answers'] ) && is_array( $changes['answers'] ) ) {
			$m['answers'] = array_map( 'sanitize_textarea_field', array_map( 'strval', $changes['answers'] ) );
		}
		update_user_meta( $user_id, self::META, $m );
	}

	/**
	 * Change a member's status. The first time someone becomes active, their
	 * "member since" date is set. Other features hear about it on
	 * scout_members_status_changed.
	 */
	public static function set_status( int $user_id, string $status ): void {
		if ( ! isset( self::statuses()[ $status ] ) ) {
			return;
		}
		$old = (string) get_user_meta( $user_id, self::STATUS, true );
		if ( $old === $status ) {
			return;
		}
		update_user_meta( $user_id, self::STATUS, $status );
		$user = get_userdata( $user_id );
		if ( $user && ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			$user->add_role( self::ROLE );
		}
		if ( 'active' === $status && '' === self::member( $user_id )['since'] ) {
			self::save( $user_id, array( 'since' => self::today() ) );
		}
		do_action( 'scout_members_status_changed', $user_id, $status, $old );
	}

	/**
	 * Record a payment: the renewal date moves one plan period forward, from
	 * the current renewal date if it is still ahead, else from the day paid.
	 * A plan paid "once" clears the renewal date. $how goes in the member's
	 * history ("Paid today", "order #123, $120"). Other features hear about it
	 * on scout_members_paid.
	 */
	public static function paid( int $user_id, string $on = '', string $how = '' ): void {
		$on   = '' !== self::clean_date( $on ) ? $on : self::today();
		$m    = self::member( $user_id );
		$plan = self::plans()[ $m['plan'] ] ?? array( 'period' => 'year' );
		$from = '' !== $m['renews'] && $m['renews'] > $on ? $m['renews'] : $on;
		$next = 'once' === $plan['period'] ? '' : gmdate( 'Y-m-d', strtotime( $from . ' +1 ' . $plan['period'] ) );
		update_user_meta( $user_id, self::META, array_merge( self::raw( $user_id ), array( 'renews' => $next, 'paid' => $on ) ) );
		$how = '' !== $how ? $how : __( 'recorded by hand', 'scout-members' );
		self::log(
			$user_id,
			'' !== $next
				/* translators: 1: how it was paid, 2: the new renewal date. */
				? sprintf( __( 'Paid (%1$s). Renews %2$s.', 'scout-members' ), $how, self::date_label( $next ) )
				/* translators: %s: how it was paid. */
				: sprintf( __( 'Paid (%s).', 'scout-members' ), $how )
		);
		do_action( 'scout_members_paid', $user_id, $on, $next );
	}

	/** Add a line to a member's history. The newest 50 are kept. */
	public static function log( int $user_id, string $text ): void {
		$log   = self::history( $user_id );
		$log[] = array(
			'on'   => self::today(),
			'text' => sanitize_text_field( $text ),
		);
		update_user_meta( $user_id, self::LOG, array_slice( $log, -50 ) );
	}

	/** @return array<int,array{on:string,text:string}> oldest first */
	public static function history( int $user_id ): array {
		$log = get_user_meta( $user_id, self::LOG, true );
		return is_array( $log ) ? array_values( $log ) : array();
	}

	/**
	 * Flag a payment for the owner to look at. Nothing about the membership
	 * changes: the owner decides on the Renewals screen.
	 */
	public static function flag( int $user_id, string $reason, int $order = 0 ): void {
		update_user_meta(
			$user_id,
			self::REVIEW,
			array(
				'on'     => self::today(),
				'reason' => sanitize_text_field( $reason ),
				'order'  => $order,
			)
		);
		self::log( $user_id, $reason );
	}

	/** @return array{on:string,reason:string,order:int}|null */
	public static function flagged( int $user_id ): ?array {
		$r = get_user_meta( $user_id, self::REVIEW, true );
		if ( ! is_array( $r ) || empty( $r['reason'] ) ) {
			return null;
		}
		return array(
			'on'     => (string) ( $r['on'] ?? '' ),
			'reason' => (string) $r['reason'],
			'order'  => (int) ( $r['order'] ?? 0 ),
		);
	}

	/** @return int[] members with a payment to review */
	public static function flagged_ids(): array {
		return array_map(
			'intval',
			get_users(
				array(
					'meta_key' => self::REVIEW, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a handful of people.
					'fields'   => 'ID',
					'number'   => 200,
				)
			)
		);
	}

	/** The member's own renewal price when the owner set one, else the plan's price. Empty when neither is set. */
	public static function dues( int $user_id ): string {
		$m = self::member( $user_id );
		if ( '' !== $m['comp'] ) {
			return $m['comp'];
		}
		return (string) ( self::plans()[ $m['plan'] ]['price'] ?? '' );
	}

	/** The last day a member keeps access: the renewal date plus the grace period. Empty when it never runs out. */
	public static function access_until( int $user_id ): string {
		$r = self::member( $user_id )['renews'];
		return '' === $r ? '' : gmdate( 'Y-m-d', strtotime( $r . ' +' . self::settings()['grace'] . ' days' ) );
	}

	/** Past the renewal date but still inside the grace period. */
	public static function in_grace( int $user_id ): bool {
		$m = self::member( $user_id );
		return 'active' === $m['status'] && '' !== $m['renews'] && $m['renews'] < self::today() && self::access_until( $user_id ) >= self::today();
	}

	/** The stored array as is, for whole-record writes. */
	private static function raw( int $user_id ): array {
		$m = get_user_meta( $user_id, self::META, true );
		return is_array( $m ) ? $m : array();
	}

	/** An active member whose renewal date, if set, has not passed by more than the grace period. */
	public static function is_member( ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		$m = self::member( $user_id );
		return 'active' === $m['status'] && ( '' === $m['renews'] || self::access_until( $user_id ) >= self::today() );
	}

	/**
	 * Members, and anyone who edits the site, can see members-only content.
	 * With a page id, a page limited to some plans also needs the member to be
	 * on one of them.
	 */
	public static function can_see( int $post_id = 0 ): bool {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}
		if ( ! self::is_member() ) {
			return false;
		}
		return self::plan_allowed( $post_id ? self::plans_for( $post_id ) : array() );
	}

	/** True when no plans are named, or the signed-in member is on one of them. */
	public static function plan_allowed( array $plans ): bool {
		return ! $plans || in_array( self::member( get_current_user_id() )['plan'], $plans, true );
	}

	/**
	 * User ids with one or more statuses, newest application first.
	 *
	 * @param string|string[] $status
	 * @return int[]
	 */
	public static function ids( $status ): array {
		$ids = get_users(
			array(
				'meta_key'     => self::STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small list, indexed key.
				'meta_value'   => (array) $status, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => 'IN',
				'fields'       => 'ID',
				'number'       => 500,
			)
		);
		$ids = array_map( 'intval', $ids );
		usort( $ids, static fn( $a, $b ) => strcmp( self::member( $b )['applied'], self::member( $a )['applied'] ) );
		return $ids;
	}

	/** Active members whose renewal date falls within the next $days, or has passed. */
	public static function renewals_due( int $days = 30 ): array {
		$limit = gmdate( 'Y-m-d', strtotime( self::today() . ' +' . $days . ' days' ) );
		return array_values(
			array_filter(
				self::ids( 'active' ),
				static function ( $id ) use ( $limit ) {
					$r = self::member( $id )['renews'];
					return '' !== $r && $r <= $limit;
				}
			)
		);
	}

	/* ---------- members-only content ---------- */

	/** Marked members only on its own box. Events use this; pages also inherit, see locked(). */
	public static function is_members_only( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, self::ONLY, true );
	}

	/**
	 * The page, its parent pages and the members home, nearest first. Pages
	 * under the members home, or under any members-only page, are members only
	 * too, so a new page in the members area is never left open by mistake.
	 *
	 * @return int[]
	 */
	private static function lineage( int $post_id ): array {
		return array_merge( array( $post_id ), array_map( 'intval', get_post_ancestors( $post_id ) ) );
	}

	/** A page or post that only members can read: its own box, the members home, or a locked parent. */
	public static function locked( int $post_id ): bool {
		if ( ! $post_id ) {
			return false;
		}
		$home = self::home_id();
		foreach ( self::lineage( $post_id ) as $id ) {
			if ( ( $home && $id === $home ) || self::is_members_only( $id ) ) {
				return true;
			}
		}
		return false;
	}

	/** The page whose lock this one follows, when it is not its own. 0 when it has its own or none. */
	public static function locked_by( int $post_id ): int {
		if ( self::is_members_only( $post_id ) || ( self::home_id() && self::home_id() === $post_id ) ) {
			return 0;
		}
		$home = self::home_id();
		foreach ( array_slice( self::lineage( $post_id ), 1 ) as $id ) {
			if ( ( $home && $id === $home ) || self::is_members_only( $id ) ) {
				return $id;
			}
		}
		return 0;
	}

	/** The plans a page is limited to: its own, else the nearest parent's. Empty means every member. */
	public static function plans_for( int $post_id ): array {
		foreach ( self::lineage( $post_id ) as $id ) {
			$plans = self::own_plans( $id );
			if ( $plans ) {
				return $plans;
			}
		}
		return array();
	}

	/** @return string[] plan slugs set on this page's own box that still exist */
	public static function own_plans( int $post_id ): array {
		$plans = get_post_meta( $post_id, self::PLANS, true );
		return is_array( $plans ) ? array_values( array_intersect( array_map( 'strval', $plans ), array_keys( self::plans() ) ) ) : array();
	}

	public static function set_plans( int $post_id, array $plans ): void {
		$plans = array_values( array_intersect( array_map( 'sanitize_title', $plans ), array_keys( self::plans() ) ) );
		if ( $plans ) {
			update_post_meta( $post_id, self::PLANS, $plans );
		} else {
			delete_post_meta( $post_id, self::PLANS );
		}
	}

	/** "VIP Member", or "Club Member or VIP Member". */
	public static function plan_names( array $plans ): string {
		$names = array_values( array_filter( array_map( static fn( $p ) => self::plans()[ $p ]['name'] ?? '', $plans ) ) );
		if ( count( $names ) < 2 ) {
			return (string) ( $names[0] ?? '' );
		}
		$last = array_pop( $names );
		/* translators: 1: plan names separated by commas, 2: the last plan name. */
		return sprintf( __( '%1$s or %2$s', 'scout-members' ), implode( ', ', $names ), $last );
	}

	/**
	 * Every published page and post that is members only, the members home and
	 * the pages under it included, for the members area list and sitemaps.
	 *
	 * @return int[]
	 */
	public static function locked_ids(): array {
		$own = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'meta_key'       => self::ONLY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a short list.
				'fields'         => 'ids',
				'posts_per_page' => 200,
			)
		);
		$roots = array_map( 'intval', $own );
		if ( self::home_id() ) {
			// The members home and its pages first, in the order the home lists them.
			array_unshift( $roots, self::home_id() );
		}
		$ids = array();
		foreach ( $roots as $root ) {
			$ids[] = $root;
			if ( 'page' === get_post_type( $root ) ) {
				$ids = array_merge( $ids, array_map( static fn( $p ) => (int) $p->ID, get_pages( array( 'child_of' => $root, 'sort_column' => 'menu_order,post_title' ) ) ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	public static function set_members_only( int $post_id, bool $on ): void {
		if ( $on ) {
			update_post_meta( $post_id, self::ONLY, 1 );
		} else {
			delete_post_meta( $post_id, self::ONLY );
		}
	}

	/* ---------- headings ---------- */

	/** Set by a shortcode's level="" for the headings it prints; 0 means the default. */
	public static int $level = 0;

	/**
	 * The level of the top headings Scout Members prints inside page content
	 * (account sections, members-only events, invitations): 2 by default, so
	 * they follow the page's H1 without skipping a level. Nested headings use
	 * one level more. A shortcode's level="3" or the scout_members_heading_level
	 * filter changes it.
	 */
	public static function heading_level(): int {
		$level = self::$level ? self::$level : (int) apply_filters( 'scout_members_heading_level', 2 );
		return max( 2, min( 5, $level ) );
	}

	/** <h2 class="…">Text</h2> at the top level, or one level under it with $nested. Text is escaped here. */
	public static function heading( string $text, string $class = '', bool $nested = false, string $id = '' ): string {
		$h = 'h' . ( self::heading_level() + ( $nested ? 1 : 0 ) );
		return '<' . $h . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>' . esc_html( $text ) . '</' . $h . '>';
	}

	/* ---------- dates ---------- */

	public static function today(): string {
		return wp_date( 'Y-m-d' );
	}

	/** "120", "62.5" or "0" for an amount a person typed; empty when it is not a number. */
	public static function clean_amount( string $a ): string {
		$a = trim( str_replace( array( '$', ',' ), '', $a ) );
		return '' !== $a && is_numeric( $a ) && (float) $a >= 0 ? (string) round( (float) $a, 2 ) : '';
	}

	public static function clean_date( string $d ): string {
		$d = trim( $d );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && checkdate( (int) substr( $d, 5, 2 ), (int) substr( $d, 8, 2 ), (int) substr( $d, 0, 4 ) ) ? $d : '';
	}

	/** "March 4, 2027" in the site's date format, or an empty string. */
	public static function date_label( string $ymd ): string {
		if ( '' === $ymd ) {
			return '';
		}
		$ts = strtotime( $ymd . ' 12:00:00' );
		return $ts ? wp_date( (string) get_option( 'date_format' ), $ts ) : '';
	}
}
