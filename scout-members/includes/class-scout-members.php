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
 * renews). Until card billing arrives, the owner records a payment with one
 * click and the renewal date moves forward one period. Billing will call the
 * same paid() and set_status(), so nothing else here has to change.
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

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'role' ), 6 );
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
			'active'   => __( 'Member', 'scout-members' ),
			'paused'   => __( 'Paused', 'scout-members' ),
			'ended'    => __( 'Ended', 'scout-members' ),
			'declined' => __( 'Not accepted', 'scout-members' ),
		);
	}

	/* ---------- settings ---------- */

	/** @return array{page:int,home:int,agreements_url:string,notify:string,min_age:int,plans:string} */
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
		);
	}

	public static function save_settings( array $in ): void {
		update_option(
			self::OPTION,
			array(
				'page'           => absint( $in['page'] ?? 0 ),
				'home'           => absint( $in['home'] ?? 0 ),
				'agreements_url' => esc_url_raw( trim( (string) ( $in['agreements_url'] ?? '' ) ) ),
				'notify'         => sanitize_email( (string) ( $in['notify'] ?? '' ) ),
				'min_age'        => min( 99, absint( $in['min_age'] ?? 0 ) ),
				'plans'          => sanitize_textarea_field( (string) ( $in['plans'] ?? '' ) ),
			),
			false
		);
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
		$amount = '$' . ( floor( (float) $p['price'] ) == (float) $p['price'] ? number_format_i18n( (float) $p['price'] ) : number_format_i18n( (float) $p['price'], 2 ) ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- whole-dollar check on floats.
		$every  = array(
			'month' => __( 'a month', 'scout-members' ),
			'year'  => __( 'a year', 'scout-members' ),
			'once'  => __( 'once', 'scout-members' ),
		);
		/* translators: 1: plan name, 2: amount, 3: "a month", "a year" or "once". */
		return sprintf( __( '%1$s, %2$s %3$s', 'scout-members' ), $p['name'], $amount, $every[ $p['period'] ] );
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
		return is_email( $to ) ? $to : (string) get_option( 'admin_email' );
	}

	/* ---------- members ---------- */

	/**
	 * One member's record. Empty status means the user never applied.
	 *
	 * @return array{status:string,plan:string,applied:string,since:string,renews:string,paid:string,note:string,answers:array<string,string>}
	 */
	public static function member( int $user_id ): array {
		$m = get_user_meta( $user_id, self::META, true );
		$m = is_array( $m ) ? $m : array();
		return array(
			'status'  => (string) get_user_meta( $user_id, self::STATUS, true ),
			'plan'    => (string) ( $m['plan'] ?? '' ),
			'applied' => (string) ( $m['applied'] ?? '' ),
			'since'   => (string) ( $m['since'] ?? '' ),
			'renews'  => (string) ( $m['renews'] ?? '' ),
			'paid'    => (string) ( $m['paid'] ?? '' ),
			'note'    => (string) ( $m['note'] ?? '' ),
			'answers' => is_array( $m['answers'] ?? null ) ? $m['answers'] : array(),
		);
	}

	/** Save the parts of a record other than the status. */
	public static function save( int $user_id, array $changes ): void {
		$m = self::member( $user_id );
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
	 * A plan paid "once" clears the renewal date.
	 */
	public static function paid( int $user_id, string $on = '' ): void {
		$on   = '' !== self::clean_date( $on ) ? $on : self::today();
		$m    = self::member( $user_id );
		$plan = self::plans()[ $m['plan'] ] ?? array( 'period' => 'year' );
		$from = '' !== $m['renews'] && $m['renews'] > $on ? $m['renews'] : $on;
		$next = 'once' === $plan['period'] ? '' : gmdate( 'Y-m-d', strtotime( $from . ' +1 ' . $plan['period'] ) );
		update_user_meta( $user_id, self::META, array_merge( self::raw( $user_id ), array( 'renews' => $next, 'paid' => $on ) ) );
	}

	/** The stored array as is, for whole-record writes. */
	private static function raw( int $user_id ): array {
		$m = get_user_meta( $user_id, self::META, true );
		return is_array( $m ) ? $m : array();
	}

	/** An active member whose renewal date, if set, has not passed. */
	public static function is_member( ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		$m = self::member( $user_id );
		return 'active' === $m['status'] && ( '' === $m['renews'] || $m['renews'] >= self::today() );
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

	/* ---------- dates ---------- */

	public static function today(): string {
		return wp_date( 'Y-m-d' );
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
