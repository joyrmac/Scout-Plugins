<?php
/**
 * RV park: one sidebar section for the park's own content (dashboard
 * redesign, Joy 2026-11-04). Custom content is a Plus module, so it gets its
 * own section with an overview first, instead of one top-level menu per
 * content type. Amenities and Site Types sit inside it.
 *
 * @package Scout_RVPark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_RVPark_Admin {

	const SLUG  = 'scout-rvpark';
	const TYPES = array( 'site_type', 'amenity' );

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_filter( 'register_post_type_args', array( __CLASS__, 'nest' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** Amenities and Site Types list inside the RV park section. */
	public static function nest( array $args, string $type ): array {
		if ( in_array( $type, self::TYPES, true ) ) {
			$args['show_in_menu'] = self::SLUG;
		}
		return $args;
	}

	private static function icon(): string {
		if ( class_exists( 'Scout_Admin_UI' ) && ! Scout_Admin_UI::branded() ) {
			return 'dashicons-palmtree';
		}
		return 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#0E8FE6" d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- menu icon data URI.
	}

	public static function menu(): void {
		add_menu_page( __( 'RV park', 'scout-rvpark' ), __( 'RV park', 'scout-rvpark' ), 'edit_posts', self::SLUG, array( __CLASS__, 'render' ), self::icon(), '3.4' );
		add_submenu_page( self::SLUG, __( 'RV park', 'scout-rvpark' ), __( 'Home', 'scout-rvpark' ), 'edit_posts', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::SLUG ) && class_exists( 'Scout_Admin_UI' ) ) {
			Scout_Admin_UI::enqueue( plugins_url( 'assets/scout-admin.css', SCOUT_RVPARK_FILE ) );
		}
	}

	/** One field, stored the way Scout Core stores fields ("scout_" + the field key). */
	private static function field( int $id, string $key ): string {
		return (string) get_post_meta( $id, 'scout_' . $key, true );
	}

	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$sites     = post_type_exists( 'site_type' ) ? get_posts( array( 'post_type' => 'site_type', 'post_status' => 'publish', 'posts_per_page' => 50, 'orderby' => 'menu_order title', 'order' => 'ASC' ) ) : array();
		$amenities = post_type_exists( 'amenity' ) ? get_posts( array( 'post_type' => 'amenity', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC' ) ) : array();
		// The section's Home screen: the sidebar and the bar say Home, the heading names the section.
		if ( method_exists( 'Scout_Admin_UI', 'page_head' ) ) {
			Scout_Admin_UI::page_head( __( 'RV park', 'scout-rvpark' ) );
		}
		Scout_Admin_UI::open(
			__( 'Home', 'scout-rvpark' ),
			__( 'Your site types, rates and amenities, edited like a form. Change one here and it updates on every page that lists it.', 'scout-rvpark' ),
			__( 'RV park', 'scout-rvpark' )
		);
		?>
		<section class="sh-box">
			<div class="sh-section-head">
				<h2 class="sh-title"><?php echo esc_html( sprintf( /* translators: %d: site types */ _n( 'Site types · %d', 'Site types · %d', count( $sites ), 'scout-rvpark' ), count( $sites ) ) ); ?></h2>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=site_type' ) ); ?>"><?php esc_html_e( 'Add a site type', 'scout-rvpark' ); ?></a>
			</div>
			<?php if ( ! $sites ) : ?>
				<p class="sh-note"><?php esc_html_e( 'No site types yet. Add one for each kind of site you rent, with its hookups and rates.', 'scout-rvpark' ); ?></p>
			<?php else : ?>
				<table class="sh-table">
					<thead><tr><th><?php esc_html_e( 'Site type', 'scout-rvpark' ); ?></th><th><?php esc_html_e( 'Hookups', 'scout-rvpark' ); ?></th><th><?php esc_html_e( 'Nightly', 'scout-rvpark' ); ?></th><th><?php esc_html_e( 'Monthly', 'scout-rvpark' ); ?></th><th><?php esc_html_e( 'Max rig length', 'scout-rvpark' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $sites as $p ) : ?>
						<?php
						$nightly = self::field( $p->ID, 'nightly' );
						$monthly = self::field( $p->ID, 'monthly' );
						$length  = self::field( $p->ID, 'max_length' );
						?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $p->ID ) ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a></td>
							<td><?php echo esc_html( self::field( $p->ID, 'hookups' ) ); ?></td>
							<td><?php echo '' !== $nightly ? esc_html( '$' . $nightly ) : '&mdash;'; ?></td>
							<td><?php echo '' !== $monthly ? esc_html( '$' . $monthly ) : '&mdash;'; ?></td>
							<td><?php echo '' !== $length ? esc_html( $length . ' ft' ) : '&mdash;'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<section class="sh-box">
			<div class="sh-section-head">
				<h2 class="sh-title"><?php echo esc_html( sprintf( /* translators: %d: amenities */ _n( 'Amenities · %d', 'Amenities · %d', count( $amenities ), 'scout-rvpark' ), count( $amenities ) ) ); ?></h2>
				<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=amenity' ) ); ?>"><?php esc_html_e( 'Add an amenity', 'scout-rvpark' ); ?></a>
			</div>
			<?php if ( ! $amenities ) : ?>
				<p class="sh-note"><?php esc_html_e( 'No amenities yet. Add the pool, laundry, Wi-Fi and anything else guests ask about.', 'scout-rvpark' ); ?></p>
			<?php else : ?>
				<ul class="sh-list">
					<?php foreach ( $amenities as $p ) : ?>
						<li><a href="<?php echo esc_url( (string) get_edit_post_link( $p->ID ) ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a><?php echo 'yes' === self::field( $p->ID, 'highlight' ) ? ' <span class="sh-note">' . esc_html__( 'featured on the home page', 'scout-rvpark' ) . '</span>' : ''; ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Where this shows', 'scout-rvpark' ); ?></h2>
			<p class="sh-note"><?php esc_html_e( 'Every page on your site that lists sites, rates or amenities reads from here, so a new rate only needs changing once.', 'scout-rvpark' ); ?></p>
		</section>
		<?php
		Scout_Admin_UI::close();
	}
}
