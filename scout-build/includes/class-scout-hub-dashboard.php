<?php
/**
 * The Scout box on the WordPress Dashboard: what the site has on, what needs a
 * look, and one click into the Scout screens. First box on the page, so the
 * person who logs in sees it before anything else.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Dashboard {

	const ID = 'scout_hub_dashboard';

	public static function boot(): void {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$title = Scout_Hub_Admin::branded() ? __( 'Scout', 'scout-build' ) : __( 'Site Build', 'scout-build' );
		wp_add_dashboard_widget( self::ID, $title, array( __CLASS__, 'render' ) );

		// First in the left column.
		global $wp_meta_boxes;
		$core = $wp_meta_boxes['dashboard']['normal']['core'] ?? array();
		if ( isset( $core[ self::ID ] ) ) {
			$wp_meta_boxes['dashboard']['normal']['core'] = array( self::ID => $core[ self::ID ] ) + $core; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering only.
		}
	}

	/** Rating, count and new reviews, when Scout Reviews is on. */
	private static function reviews(): void {
		if ( ! class_exists( 'Scout_Reviews_Query' ) || ! class_exists( 'Scout_Reviews_Post_Type' ) ) {
			return;
		}
		$totals = Scout_Reviews_Query::totals();
		$top    = $totals[0] ?? null;
		$new    = class_exists( 'Scout_Reviews_Google_Sync' ) ? count(
			get_posts(
				array(
					'post_type'      => Scout_Reviews_Post_Type::TYPE,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 50,
					'meta_key'       => Scout_Reviews_Google_Sync::META_NEW, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		) : 0;
		$list   = admin_url( 'edit.php?post_type=' . Scout_Reviews_Post_Type::TYPE );
		?>
		<p class="sd-reviews">
			<span class="sd-stars" aria-hidden="true">&#9733;</span>
			<?php
			if ( $top ) {
				$name = class_exists( 'Scout_Reviews_Sources' ) ? ( Scout_Reviews_Sources::all()[ $top['source'] ]['label'] ?? ucfirst( $top['source'] ) ) : ucfirst( $top['source'] );
				/* translators: 1: rating, 2: review count, 3: platform */
				echo esc_html( sprintf( _n( '%1$s from %2$d review on %3$s', '%1$s from %2$d reviews on %3$s', (int) $top['count'], 'scout-build' ), number_format( (float) $top['rating'], 1 ), (int) $top['count'], $name ) );
			} else {
				esc_html_e( 'No review totals yet. Add them in Reviews, Settings.', 'scout-build' );
			}
			if ( $new ) {
				/* translators: %d: new reviews */
				echo ' &middot; <strong>' . esc_html( sprintf( _n( '%d new review', '%d new reviews', $new, 'scout-build' ), $new ) ) . '</strong>';
			}
			?>
			&middot; <a href="<?php echo esc_url( $list ); ?>"><?php esc_html_e( 'See reviews', 'scout-build' ); ?></a>
		</p>
		<?php
	}

	/** Spam blocked this month, when Scout Forms Guard 0.2.0+ is on. */
	private static function spam(): void {
		if ( ! class_exists( 'Scout_Forms_Guard' ) || ! method_exists( 'Scout_Forms_Guard', 'blocked' ) ) {
			return;
		}
		$month = (int) Scout_Forms_Guard::blocked();
		$all   = (int) Scout_Forms_Guard::blocked( 'all' );
		?>
		<p class="sd-spam">
			<span class="sd-shield" aria-hidden="true"></span>
			<?php
			if ( $month ) {
				/* translators: %d: spam submissions blocked this month */
				echo '<strong>' . esc_html( sprintf( _n( '%d spam submission blocked this month', '%d spam submissions blocked this month', $month, 'scout-build' ), $month ) ) . '</strong>';
			} else {
				esc_html_e( 'Spam protection is on. Nothing blocked yet this month.', 'scout-build' );
			}
			if ( $all > $month ) {
				/* translators: %d: spam submissions blocked in the last year */
				echo ' &middot; ' . esc_html( sprintf( _n( '%d in the last year', '%d in the last year', $all, 'scout-build' ), $all ) );
			}
			?>
			<span class="sd-note"><?php esc_html_e( 'Your forms check every message invisibly, with no puzzles for real visitors.', 'scout-build' ); ?></span>
		</p>
		<?php
	}

	public static function render(): void {
		$on = 0;
		foreach ( array_keys( Scout_Hub_Catalog::features() ) as $slug ) {
			$on += 'on' === Scout_Hub_Features::status( $slug )['state'] ? 1 : 0;
		}
		$ai     = Scout_Hub_Health::ai_checks();
		$basics = Scout_Hub_Health::checks();
		$fix    = array_values( array_filter( $basics, static fn( $c ) => 'bad' === $c['state'] ) );
		$look   = array_values( array_filter( $basics, static fn( $c ) => 'warn' === $c['state'] ) );
		$todo   = array_slice( array_merge( $fix, $look ), 0, 4 );
		$open   = count( array_filter( Scout_Hub_Requests::all(), static fn( $p ) => 'done' !== get_post_meta( $p->ID, Scout_Hub_Requests::META_STATUS, true ) ) );
		$mail   = class_exists( 'Scout_Hub_Mail' ) && Scout_Hub_Mail::ready();
		$stats  = array(
			array( (string) $on, __( 'features on', 'scout-build' ), Scout_Hub_Admin::url( 'features' ) ),
			/* translators: 1: checks passed, 2: total */
			array( sprintf( '%1$d/%2$d', count( array_filter( $ai, static fn( $c ) => 'good' === $c['state'] ) ), count( $ai ) ), __( 'ready for AI search', 'scout-build' ), Scout_Hub_Admin::url( 'health' ) ),
			array( $mail ? __( 'On', 'scout-build' ) : __( 'Off', 'scout-build' ), __( 'Google email', 'scout-build' ), current_user_can( 'manage_options' ) && class_exists( 'Scout_Hub_Mail' ) ? Scout_Hub_Mail::url() : '' ),
			array( (string) $open, __( 'open requests', 'scout-build' ), Scout_Hub_Admin::url( 'requests' ) ),
		);
		?>
		<style>
			#<?php echo esc_attr( self::ID ); ?> .hndle::before{content:""; display:inline-block; width:12px; height:15px; margin-right:8px; background:#0E8FE6; clip-path:polygon(58% 0,0 58%,42% 58%,30% 100%,100% 40%,54% 40%); vertical-align:-2px}
			.sd-stats{display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:8px; margin:4px 0 12px}
			.sd-stat{display:block; padding:10px; border:1px solid #dcdcde; border-radius:10px; text-decoration:none; color:inherit}
			a.sd-stat:hover{border-color:#0A78C2}
			.sd-stat b{display:block; font-size:20px; line-height:1.1; font-weight:600}
			.sd-stat span{display:block; font-size:12px; color:#50575e; margin-top:2px}
			.sd-reviews{margin:0 0 12px; padding:10px 12px; border-radius:10px; background:#f6f7f7}
			.sd-stars{color:#FBB024; margin-right:4px}
			.sd-todo{margin:0 0 12px; padding:0; list-style:none; display:grid; gap:6px}
			.sd-todo li{display:grid; grid-template-columns:10px 1fr; gap:8px; align-items:start}
			.sd-dot{width:8px; height:8px; border-radius:50%; margin-top:6px; background:#FBB024}
			.sd-dot.is-bad{background:#F2553D}
			.sd-ok{margin:0 0 12px; color:#0E7A5A}
			.sd-ask{display:grid; gap:6px; margin:0 0 12px; padding-top:12px; border-top:1px solid #dcdcde}
			.sd-ask textarea{width:100%}
			.sd-ask .button{justify-self:start}
			.sd-links{display:flex; flex-wrap:wrap; gap:8px}
			@media (max-width:600px){.sd-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
		</style>
		<div class="sd-stats">
			<?php foreach ( $stats as $s ) : ?>
				<?php if ( '' !== $s[2] ) : ?>
					<a class="sd-stat" href="<?php echo esc_url( $s[2] ); ?>"><b><?php echo esc_html( $s[0] ); ?></b><span><?php echo esc_html( $s[1] ); ?></span></a>
				<?php else : ?>
					<div class="sd-stat"><b><?php echo esc_html( $s[0] ); ?></b><span><?php echo esc_html( $s[1] ); ?></span></div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php self::reviews(); ?>
		<?php if ( $todo ) : ?>
			<ul class="sd-todo">
				<?php foreach ( $todo as $c ) : ?>
					<li><span class="sd-dot<?php echo 'bad' === $c['state'] ? ' is-bad' : ''; ?>" aria-hidden="true"></span><span><strong><?php echo esc_html( $c['label'] . ': ' . $c['value'] ); ?>.</strong> <?php echo esc_html( $c['note'] ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="sd-ok"><?php esc_html_e( 'Every site check passes.', 'scout-build' ); ?></p>
		<?php endif; ?>
		<form class="sd-ask" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Scout_Hub_Requests::ACTION ); ?>">
			<input type="hidden" name="do" value="new">
			<?php wp_nonce_field( Scout_Hub_Requests::ACTION ); ?>
			<label for="sd-what"><strong><?php esc_html_e( 'Need something changed?', 'scout-build' ); ?></strong></label>
			<textarea id="sd-what" name="what" rows="3" required placeholder="<?php esc_attr_e( 'Tell us what and where. We\'ll take it from here.', 'scout-build' ); ?>"></textarea>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Send request', 'scout-build' ); ?></button>
		</form>
		<p class="sd-links">
			<a class="button" href="<?php echo esc_url( Scout_Hub_Admin::url( 'features' ) ); ?>"><?php esc_html_e( 'Open Scout', 'scout-build' ); ?></a>
			<a class="button" href="<?php echo esc_url( Scout_Hub_Admin::url( 'health' ) ); ?>"><?php esc_html_e( 'Site health', 'scout-build' ); ?></a>
			<a class="button" href="<?php echo esc_url( Scout_Hub_Admin::url( 'requests' ) ); ?>"><?php esc_html_e( 'Your requests', 'scout-build' ); ?></a>
		</p>
		<?php
	}
}
