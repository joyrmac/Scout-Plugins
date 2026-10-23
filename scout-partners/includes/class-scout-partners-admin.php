<?php
/**
 * The Partners hub: its own sidebar item with the Scout bolt. Overview (who is
 * running, by level, what ends soon, clicks this month and last), the partner
 * list, and settings for levels, placements and event pages. Each partner's
 * details sit in one box on its edit screen.
 *
 * @package Scout_Partners
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Partners_Admin {

	const SLUG = 'scout-partners';
	const CAP  = 'edit_pages';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 32 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_scout_partners_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'add_meta_boxes_' . Scout_Partners::TYPE, array( __CLASS__, 'box' ) );
		add_action( 'save_post_' . Scout_Partners::TYPE, array( __CLASS__, 'save_box' ) );
		add_filter( 'manage_' . Scout_Partners::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Scout_Partners::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'parent_file', array( __CLASS__, 'highlight' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_PARTNERS_FILE ), array( __CLASS__, 'action_link' ) );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_hint' ), 10, 2 );
	}

	public static function url( string $view = '' ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . ( 'settings' === $view ? '-settings' : '' ) );
	}

	private static function icon(): string {
		if ( ! Scout_Admin_UI::branded() ) {
			return 'dashicons-awards';
		}
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#0E8FE6" d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- menu icon data URI.
	}

	public static function menu(): void {
		$list = 'edit.php?post_type=' . Scout_Partners::TYPE;
		add_menu_page( __( 'Scout Partners', 'scout-partners' ), __( 'Partners', 'scout-partners' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ), self::icon(), '3.3' );
		add_submenu_page( self::SLUG, __( 'Scout Partners', 'scout-partners' ), __( 'Overview', 'scout-partners' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
		add_submenu_page( self::SLUG, __( 'All partners', 'scout-partners' ), __( 'All partners', 'scout-partners' ), self::CAP, $list );
		add_submenu_page( self::SLUG, __( 'Add partner', 'scout-partners' ), __( 'Add partner', 'scout-partners' ), self::CAP, 'post-new.php?post_type=' . Scout_Partners::TYPE );
		add_submenu_page( self::SLUG, __( 'Partner settings', 'scout-partners' ), __( 'Settings', 'scout-partners' ), 'manage_options', self::SLUG . '-settings', array( __CLASS__, 'render_settings' ) );
	}

	/** Partner list and edit screens light up the Partners menu. */
	public static function highlight( $file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && Scout_Partners::TYPE === $screen->post_type ) {
			global $submenu_file;
			$submenu_file = 'post' === $screen->base && 'add' === $screen->action ? 'post-new.php?post_type=' . Scout_Partners::TYPE : 'edit.php?post_type=' . Scout_Partners::TYPE; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return self::SLUG;
		}
		return $file;
	}

	public static function assets( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( false === strpos( $hook, self::SLUG ) && ! ( $screen && Scout_Partners::TYPE === $screen->post_type ) ) {
			return;
		}
		Scout_Admin_UI::enqueue( SCOUT_PARTNERS_URL . 'assets/scout-admin.css' );
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Partners', 'scout-partners' ) . '</a>' );
		return $links;
	}

	public static function title_hint( $text, $post ) {
		return $post instanceof WP_Post && Scout_Partners::TYPE === $post->post_type ? __( 'Partner name', 'scout-partners' ) : $text;
	}

	/* ---------- the details box ---------- */

	public static function box(): void {
		add_meta_box( 'scout-partner', __( 'Partner details', 'scout-partners' ), array( __CLASS__, 'render_box' ), Scout_Partners::TYPE, 'normal', 'high' );
	}

	public static function render_box( $post ): void {
		$p = Scout_Partners::partner( (int) $post->ID );
		wp_nonce_field( 'scout_partner_' . $post->ID, '_sp_nonce' );
		echo '<p><label for="sp-level"><strong>' . esc_html__( 'Level', 'scout-partners' ) . '</strong></label><br><select id="sp-level" name="sp[level]"><option value="">' . esc_html__( 'No level', 'scout-partners' ) . '</option>';
		foreach ( Scout_Partners::levels() as $slug => $name ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $p['level'], $slug, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label for="sp-url"><strong>' . esc_html__( 'Website', 'scout-partners' ) . '</strong></label><br><input type="url" id="sp-url" name="sp[url]" class="widefat" value="' . esc_attr( $p['url'] ) . '" placeholder="https://"></p>';
		echo '<p><label for="sp-line"><strong>' . esc_html__( 'One line about them (optional)', 'scout-partners' ) . '</strong></label><br><input type="text" id="sp-line" name="sp[line]" class="widefat" maxlength="140" value="' . esc_attr( $p['line'] ) . '"></p>';
		$places = Scout_Partners::placements();
		if ( $places ) {
			echo '<fieldset><legend><strong>' . esc_html__( 'Where they appear', 'scout-partners' ) . '</strong></legend>';
			foreach ( $places as $slug => $name ) {
				echo '<label style="display:inline-block;margin:4px 16px 4px 0"><input type="checkbox" name="sp[placements][]" value="' . esc_attr( $slug ) . '"' . checked( in_array( $slug, $p['placements'], true ), true, false ) . '> ' . esc_html( $name ) . '</label>';
			}
			echo '<p class="description">' . esc_html__( 'Each place shows its partners wherever [scout_partners placement="…"] sits on the site. Every partner shows on the full partners list.', 'scout-partners' ) . '</p></fieldset>';
		}
		echo '<p><strong>' . esc_html__( 'Runs (optional)', 'scout-partners' ) . '</strong><br>'
			. '<label>' . esc_html__( 'From', 'scout-partners' ) . ' <input type="date" name="sp[starts]" value="' . esc_attr( $p['starts'] ) . '"></label> '
			. '<label>' . esc_html__( 'until', 'scout-partners' ) . ' <input type="date" name="sp[ends]" value="' . esc_attr( $p['ends'] ) . '"></label></p>'
			. '<p class="description">' . esc_html__( 'Outside these dates the partner comes off the site by itself. Leave both empty to run until you change it.', 'scout-partners' ) . '</p>';
		if ( taxonomy_exists( 'scout_region' ) ) {
			echo '<p class="description">' . esc_html__( 'Regions: tick them in the Regions box. A partner with no region shows everywhere; one with regions shows beside those regions\' events.', 'scout-partners' ) . '</p>';
		}
		$clicks = Scout_Partners::clicks( (int) $post->ID );
		if ( $clicks ) {
			echo '<p><strong>' . esc_html__( 'Clicks by month', 'scout-partners' ) . '</strong><br>';
			foreach ( array_slice( $clicks, 0, 6, true ) as $month => $n ) {
				echo esc_html( wp_date( 'F Y', (int) strtotime( $month . '-15' ) ) . ': ' . number_format_i18n( $n ) ) . '<br>';
			}
			echo '</p>';
		}
	}

	public static function save_box( $post_id ): void {
		if ( ! isset( $_POST['_sp_nonce'] ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sp_nonce'] ) ), 'scout_partner_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['sp'] ) ? (array) wp_unslash( $_POST['sp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in Scout_Partners::save().
		Scout_Partners::save( (int) $post_id, $in );
	}

	public static function columns( $cols ): array {
		$cols = (array) $cols;
		$date = $cols['date'] ?? '';
		unset( $cols['date'] );
		$cols['sp_level']  = __( 'Level', 'scout-partners' );
		$cols['sp_place']  = __( 'Appears', 'scout-partners' );
		$cols['sp_runs']   = __( 'Runs', 'scout-partners' );
		$cols['sp_clicks'] = __( 'Clicks this month', 'scout-partners' );
		if ( '' !== $date ) {
			$cols['date'] = $date;
		}
		return $cols;
	}

	public static function column( $col, $id ): void {
		$id = (int) $id;
		$p  = Scout_Partners::partner( $id );
		if ( 'sp_level' === $col ) {
			echo esc_html( Scout_Partners::levels()[ $p['level'] ] ?? '' );
		} elseif ( 'sp_place' === $col ) {
			$all = Scout_Partners::placements();
			echo esc_html( implode( ', ', array_filter( array_map( static fn( $s ) => $all[ $s ] ?? '', $p['placements'] ) ) ) );
		} elseif ( 'sp_runs' === $col ) {
			echo esc_html( self::runs( $p ) );
		} elseif ( 'sp_clicks' === $col ) {
			echo esc_html( number_format_i18n( Scout_Partners::clicks( $id )[ wp_date( 'Y-m' ) ] ?? 0 ) );
		}
	}

	private static function runs( array $p ): string {
		$f = static fn( $d ) => '' !== $d ? wp_date( (string) get_option( 'date_format' ), (int) strtotime( $d . ' 12:00' ) ) : '';
		if ( '' === $p['starts'] && '' === $p['ends'] ) {
			return __( 'Ongoing', 'scout-partners' );
		}
		if ( '' === $p['starts'] ) {
			/* translators: %s: end date. */
			return sprintf( __( 'Until %s', 'scout-partners' ), $f( $p['ends'] ) );
		}
		if ( '' === $p['ends'] ) {
			/* translators: %s: start date. */
			return sprintf( __( 'From %s', 'scout-partners' ), $f( $p['starts'] ) );
		}
		/* translators: 1: start date, 2: end date. */
		return sprintf( __( '%1$s to %2$s', 'scout-partners' ), $f( $p['starts'] ), $f( $p['ends'] ) );
	}

	/* ---------- overview ---------- */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		Scout_Admin_UI::open(
			__( 'Your partners', 'scout-partners' ),
			__( 'Who is running, at what level and where, what ends soon, and how many people clicked through to each one.', 'scout-partners' ),
			__( 'Partners', 'scout-partners' )
		);
		$all     = array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Scout_Partners::TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'fields'         => 'ids',
				)
			)
		);
		$live    = Scout_Partners::ids();
		$levels  = Scout_Partners::levels();
		$month   = wp_date( 'Y-m' );
		$last    = ( new DateTimeImmutable( 'first day of this month', wp_timezone() ) )->modify( '-1 month' )->format( 'Y-m' );
		$soon    = array_filter(
			$live,
			static function ( $id ) {
				$e = Scout_Partners::partner( $id )['ends'];
				return '' !== $e && $e <= wp_date( 'Y-m-d', (int) strtotime( '+30 days' ) );
			}
		);
		$clicked = 0;
		foreach ( $all as $id ) {
			$clicked += Scout_Partners::clicks( $id )[ $month ] ?? 0;
		}
		?>
		<section class="sh-box">
			<div class="sh-stats">
				<div class="sh-stat"><p class="sh-num"><?php echo (int) count( $live ); ?></p><p class="sh-note"><?php esc_html_e( 'partners on the site', 'scout-partners' ); ?></p></div>
				<?php foreach ( $levels as $slug => $name ) : ?>
					<div class="sh-stat"><p class="sh-num"><?php echo (int) count( array_filter( $live, static fn( $id ) => Scout_Partners::partner( $id )['level'] === $slug ) ); ?></p><p class="sh-note"><?php echo esc_html( $name ); ?></p></div>
				<?php endforeach; ?>
				<div class="sh-stat"><p class="sh-num"><?php echo esc_html( number_format_i18n( $clicked ) ); ?></p><p class="sh-note"><?php esc_html_e( 'clicks this month', 'scout-partners' ); ?></p></div>
			</div>
		</section>

		<?php if ( ! $all ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Getting started', 'scout-partners' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Check the levels and places under Settings. They are yours to name and order.', 'scout-partners' ); ?></li>
					<li><?php esc_html_e( 'Add each partner with their logo, website, level and where they appear.', 'scout-partners' ); ?></li>
					<li><?php esc_html_e( 'Put [scout_partners] on a Partners page, and [scout_partners placement="home-page" layout="rail"] wherever a strip of logos belongs.', 'scout-partners' ); ?></li>
				</ol>
				<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Scout_Partners::TYPE ) ); ?>"><?php esc_html_e( 'Add a partner', 'scout-partners' ); ?></a></p>
			</section>
		<?php endif; ?>

		<?php if ( $soon ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Ending within 30 days', 'scout-partners' ); ?></h2>
				<ul>
					<?php foreach ( $soon as $id ) : ?>
						<li><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a> · <?php echo esc_html( self::runs( Scout_Partners::partner( $id ) ) ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p class="sh-note"><?php esc_html_e( 'A good moment to ask about renewing.', 'scout-partners' ); ?></p>
			</section>
		<?php endif; ?>

		<?php if ( $all ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Clicks to each partner', 'scout-partners' ); ?></h2>
				<table class="sh-table">
					<thead><tr><th><?php esc_html_e( 'Partner', 'scout-partners' ); ?></th><th><?php esc_html_e( 'Level', 'scout-partners' ); ?></th><th><?php echo esc_html( wp_date( 'F', (int) strtotime( $month . '-15' ) ) ); ?></th><th><?php echo esc_html( wp_date( 'F', (int) strtotime( $last . '-15' ) ) ); ?></th><th><?php esc_html_e( 'On the site', 'scout-partners' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $all as $id ) : ?>
							<?php $c = Scout_Partners::clicks( $id ); ?>
							<tr>
								<td><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a></td>
								<td><?php echo esc_html( $levels[ Scout_Partners::partner( $id )['level'] ] ?? '' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $c[ $month ] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $c[ $last ] ?? 0 ) ); ?></td>
								<td><?php echo in_array( $id, $live, true ) ? esc_html__( 'Yes', 'scout-partners' ) : esc_html__( 'Not now (outside its dates)', 'scout-partners' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="sh-note"><?php esc_html_e( 'A click is counted when someone follows a partner\'s logo or name to their website. Search engines and link checkers are left out. No visitor details are kept.', 'scout-partners' ); ?></p>
			</section>
		<?php endif; ?>
		<?php
		Scout_Admin_UI::close();
	}

	/* ---------- settings ---------- */

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Scout_Partners::settings();
		Scout_Admin_UI::open( __( 'Partner settings', 'scout-partners' ), __( 'Your levels and the places partners can appear.', 'scout-partners' ), __( 'Partners', 'scout-partners' ) );
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'scout-partners' ) . '</p></div>';
		}
		?>
		<section class="sh-box">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="scout_partners_settings">
				<?php wp_nonce_field( 'scout_partners_settings' ); ?>
				<p><label for="sp-levels"><strong><?php esc_html_e( 'Levels, highest first, one per line', 'scout-partners' ); ?></strong></label><br>
				<textarea id="sp-levels" name="sp[levels]" rows="4" class="large-text code"><?php echo esc_textarea( $s['levels'] ); ?></textarea></p>
				<p><label for="sp-places"><strong><?php esc_html_e( 'Places partners can appear, one per line', 'scout-partners' ); ?></strong></label><br>
				<textarea id="sp-places" name="sp[placements]" rows="5" class="large-text code"><?php echo esc_textarea( $s['placements'] ); ?></textarea>
				<span class="sh-note"><?php esc_html_e( 'Each one becomes a tick box on every partner. Show a place with [scout_partners placement="home-page"], using the name in lower case with dashes.', 'scout-partners' ); ?></span></p>
				<?php if ( class_exists( 'Scout_Events' ) ) : ?>
					<p><label><input type="checkbox" name="sp[on_events]" value="1" <?php checked( $s['on_events'] ); ?>> <?php esc_html_e( 'Show partners on each event page (those placed on "Event pages", from the event\'s region and the national ones)', 'scout-partners' ); ?></label></p>
				<?php endif; ?>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'scout-partners' ); ?></button></p>
			</form>
		</section>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Using it on the site', 'scout-partners' ); ?></h2>
			<table class="sh-table">
				<tr><td>[scout_partners]</td><td><?php esc_html_e( 'Every partner on the site, grouped by level.', 'scout-partners' ); ?></td></tr>
				<tr><td>[scout_partners level="founding"]</td><td><?php esc_html_e( 'One level.', 'scout-partners' ); ?></td></tr>
				<tr><td>[scout_partners placement="home-page" layout="rail"]</td><td><?php esc_html_e( 'One place, as a single row of logos.', 'scout-partners' ); ?></td></tr>
				<tr><td>[scout_partners region="midwest"]</td><td><?php esc_html_e( 'One region\'s partners (with Scout Events regions).', 'scout-partners' ); ?></td></tr>
				<tr><td>layout="logos"</td><td><?php esc_html_e( 'Logos only, no names.', 'scout-partners' ); ?></td></tr>
			</table>
		</section>
		<?php
		Scout_Admin_UI::close();
	}

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change partner settings.', 'scout-partners' ), 403 );
		}
		check_admin_referer( 'scout_partners_settings' );
		$in = isset( $_POST['sp'] ) ? (array) wp_unslash( $_POST['sp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in save_settings().
		Scout_Partners::save_settings( $in );
		wp_safe_redirect( add_query_arg( 'saved', 1, self::url( 'settings' ) ) );
		exit;
	}
}
