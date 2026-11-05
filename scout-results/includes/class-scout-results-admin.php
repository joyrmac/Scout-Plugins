<?php
/**
 * Scout Build -> Case results: the state, the disclaimer wording, where the
 * results stand, and how to put the grid on a page.
 *
 * @package Scout_Results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Results_Admin {

	/**
	 * One status line at the top of the screen (admin kit 1.10.0). An older kit
	 * copy may be the one loaded mid-update, so fall back to the same markup.
	 *
	 * @param string $go Button markup, escaped by the caller.
	 */
	private static function ui_status( string $state, string $headline, string $detail = '', string $go = '' ): void {
		if ( method_exists( 'Scout_Admin_UI', 'status' ) ) {
			Scout_Admin_UI::status( $state, $headline, $detail, $go );
			return;
		}
		$state = in_array( $state, array( 'good', 'warn', 'bad', 'off' ), true ) ? $state : 'good';
		echo '<section class="sh-box"><p class="sh-status is-' . esc_attr( $state ) . '"><span class="sh-dot" aria-hidden="true"></span><strong>' . esc_html( $headline ) . '</strong>';
		if ( '' !== $detail ) {
			echo ' <span>' . esc_html( $detail ) . '</span>';
		}
		if ( '' !== $go ) {
			echo ' <span class="sh-status-go">' . $go . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller.
		}
		echo '</p></section>';
	}

	/** A closed "More options" box (admin kit 1.10.0), with the same fallback. */
	private static function ui_fold_open( string $title ): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_open' ) ) {
			Scout_Admin_UI::fold_open( $title );
			return;
		}
		echo '<details class="sh-box sh-fold"><summary class="sh-title">' . esc_html( $title ) . '</summary>';
	}

	private static function ui_fold_close(): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_close' ) ) {
			Scout_Admin_UI::fold_close();
			return;
		}
		echo '</details>';
	}

	const SLUG = 'scout-results';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_filter( 'parent_file', array( __CLASS__, 'highlight' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_RESULTS_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/** Nest under the site's Build section; fall back to Settings. */
	public static function parent(): string {
		global $admin_page_hooks;
		foreach ( array( 'scout-build', 'recon-build', 'site-build', 'scout' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'options-general.php';
	}

	public static function url(): string {
		return 'options-general.php' === self::parent() ? admin_url( 'options-general.php?page=' . self::SLUG ) : admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function menu(): void {
		add_submenu_page( self::parent(), __( 'Case results', 'scout-results' ), __( 'Case results', 'scout-results' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
		// The list sits in the Build menu too, so the sidebar keeps one Scout section.
		if ( 'options-general.php' !== self::parent() ) {
			$list = 'edit.php?post_type=' . Scout_Results::POST_TYPE;
			global $submenu;
			remove_menu_page( $list );
			unset( $submenu[ $list ] ); // Its leftover sub-list would otherwise claim the screen.
			add_submenu_page( self::parent(), __( 'All case results', 'scout-results' ), __( 'All case results', 'scout-results' ), 'edit_posts', $list );
		}
	}

	/** Keep the Build menu open while editing an entry. */
	public static function highlight( $file ) {
		global $submenu_file;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( 'options-general.php' !== self::parent() && $screen && Scout_Results::POST_TYPE === $screen->post_type && empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$submenu_file = 'edit.php?post_type=' . Scout_Results::POST_TYPE; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return self::parent();
		}
		return $file;
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::SLUG ) ) {
			Scout_Admin_UI::enqueue( SCOUT_RESULTS_URL . 'assets/scout-admin.css' );
			wp_enqueue_style( 'scout-results', SCOUT_RESULTS_URL . 'assets/scout-results.css', array(), SCOUT_RESULTS_VERSION );
			wp_enqueue_style( 'scout-results-editor', SCOUT_RESULTS_URL . 'assets/results-editor.css', array( 'scout-results', 'scout-admin' ), SCOUT_RESULTS_VERSION );
			wp_enqueue_script( 'scout-results-settings', SCOUT_RESULTS_URL . 'assets/results-settings.js', array(), SCOUT_RESULTS_VERSION, true );
			$defaults = array();
			foreach ( array_keys( Scout_Results::states() ) as $state ) {
				$defaults[ $state ] = Scout_Results::default_disclaimer( $state );
			}
			wp_localize_script( 'scout-results-settings', 'scoutResultsDefaults', $defaults );
		}
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'scout-results' ) . '</a>' );
		return $links;
	}

	public static function register(): void {
		register_setting( 'scout_results_group', Scout_Results::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ): array {
		$in    = (array) $in;
		$state = sanitize_key( (string) ( $in['state'] ?? '' ) );
		$state = isset( Scout_Results::states()[ $state ] ) ? $state : 'nc';
		$text  = trim( sanitize_textarea_field( (string) ( $in['disclaimer'] ?? '' ) ) );
		// Saving the state's own wording unchanged keeps it following that state.
		if ( $text === Scout_Results::default_disclaimer( $state ) ) {
			$text = '';
		}
		return array(
			'state'      => $state,
			'disclaimer' => $text,
		);
	}

	/** Counts of approved, waiting and draft results. */
	private static function counts(): array {
		$live    = 0;
		$waiting = 0;
		$ids     = get_posts(
			array(
				'post_type'      => Scout_Results::POST_TYPE,
				'post_status'    => array( 'publish', 'pending', 'draft', 'future' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$approved = Scout_Results::is_approved( '1' === (string) get_post_meta( $id, Scout_Results::META['approved'], true ), (string) get_post_meta( $id, Scout_Results::META['approver'], true ) );
			if ( 'publish' === get_post_status( $id ) && $approved ) {
				++$live;
			} elseif ( ! $approved ) {
				++$waiting;
			}
		}
		return array(
			'live'    => $live,
			'waiting' => $waiting,
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = Scout_Results::settings();
		$o      = Scout_Results::OPTION;
		$counts = self::counts();
		$list   = admin_url( 'edit.php?post_type=' . Scout_Results::POST_TYPE );
		$new    = admin_url( 'post-new.php?post_type=' . Scout_Results::POST_TYPE );
		$states = Scout_Results::states();

		Scout_Admin_UI::open(
			__( 'Case results', 'scout-results' ),
			__( 'Show the firm\'s case results with the firm\'s approval on every one and the disclaimer printed next to each result.', 'scout-results' ),
			__( 'Case results', 'scout-results' )
		);
		settings_errors( $o );
		?>
		<?php
		/* translators: %d: number of case results on the site. */
		$headline = sprintf( _n( '%d result on the site.', '%d results on the site.', $counts['live'], 'scout-results' ), $counts['live'] );
		/* translators: %d: number of case results waiting for the firm's approval. */
		$detail  = sprintf( _n( '%d is waiting for the firm.', '%d are waiting for the firm.', $counts['waiting'], 'scout-results' ), $counts['waiting'] );
		$detail .= ' ' . __( 'A result goes live once the firm ticks "Approved by the firm" and adds the approver\'s name. The disclaimer prints beside every result and cannot be switched off for one.', 'scout-results' );
		$go      = '<a class="button" href="' . esc_url( $new ) . '">' . esc_html__( 'Add a case result', 'scout-results' ) . '</a>';
		self::ui_status( $counts['waiting'] ? 'warn' : ( $counts['live'] ? 'good' : 'off' ), $headline, $detail, $go );
		?>

		<div class="sr-settings">
			<form method="post" action="options.php" class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Disclaimer', 'scout-results' ); ?></h2>
				<p><?php esc_html_e( 'Added to every result on its own. Have the firm approve the wording before any result goes live.', 'scout-results' ); ?></p>
				<?php settings_fields( 'scout_results_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sr-state"><?php esc_html_e( 'State rule set', 'scout-results' ); ?></label></th>
						<td>
							<select id="sr-state" name="<?php echo esc_attr( $o ); ?>[state]">
								<?php foreach ( $states as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['state'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Sets the starting wording. North Carolina, Virginia and Florida each have their own advertising rules, so the firm checks the final text.', 'scout-results' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sr-disclaimer"><?php esc_html_e( 'Disclaimer wording', 'scout-results' ); ?></label></th>
						<td>
							<textarea id="sr-disclaimer" class="large-text" rows="5" name="<?php echo esc_attr( $o ); ?>[disclaimer]" placeholder="<?php echo esc_attr( Scout_Results::default_disclaimer( $s['state'] ) ); ?>"><?php echo esc_textarea( $s['disclaimer'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Leave blank to use the state\'s starting wording shown in gray. A blank disclaimer is never printed; the starting wording is used instead.', 'scout-results' ); ?></p>
							<?php if ( class_exists( 'Scout_Law_Disclaimers' ) && in_array( 'results', Scout_Law_Disclaimers::places_for( 'results' ), true ) ) : ?>
								<p class="description"><strong><?php esc_html_e( 'Law firm tools is on, so the wording set on Scout → Policies → Disclaimers prints next to every result instead of this box.', 'scout-results' ); ?></strong></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save disclaimer', 'scout-results' ) ); ?>
			</form>

			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'How a result looks on the site', 'scout-results' ); ?></h2>
				<p><?php esc_html_e( 'A sample card in the site\'s own fonts and colors. Your saved disclaimer is shown under it.', 'scout-results' ); ?></p>
				<div class="sr-sample">
					<span class="sr-sample-tag"><?php esc_html_e( 'Sample data', 'scout-results' ); ?></span>
					<?php
					echo '<div class="scout-results">' . Scout_Results_Render::card( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside card().
						array(
							'headline'    => __( 'Settlement for a client hurt in a rear-end crash', 'scout-results' ),
							'amount'      => '',
							'type_label'  => __( 'Settlement', 'scout-results' ),
							'description' => __( 'Sample firm represented a driver whose car was struck from behind at a stoplight. The case settled before trial.', 'scout-results' ),
							'date'        => '',
							'area'        => __( 'Car accidents', 'scout-results' ),
							'area_slug'   => '',
						)
					) . '</div>';
					?>
				</div>
			</section>
		</div>

		<?php self::ui_fold_open( __( 'Add to a page', 'scout-results' ) ); ?>
			<p><?php esc_html_e( 'Add a shortcode block to any page. Visitors can filter the grid by practice area.', 'scout-results' ); ?></p>
			<table class="sh-table sr-shortcodes">
				<tr><td>[scout_results]</td><td><?php esc_html_e( 'Every approved result, with practice-area filters.', 'scout-results' ); ?></td></tr>
				<tr><td>[scout_results area="car-accidents"]</td><td><?php esc_html_e( 'Only one practice area, for that practice area\'s page. Use the slug from Case results, Practice areas.', 'scout-results' ); ?></td></tr>
				<tr><td>[scout_results heading="Case results" limit="6"]</td><td><?php esc_html_e( 'A heading above the grid and a cap on how many show.', 'scout-results' ); ?></td></tr>
				<tr><td>[scout_results filters="no"]</td><td><?php esc_html_e( 'The grid without the filter buttons.', 'scout-results' ); ?></td></tr>
				<tr><td>[scout_result id="12"]</td><td><?php esc_html_e( 'One result by its ID, with the disclaimer.', 'scout-results' ); ?></td></tr>
			</table>
			<p class="sh-actions">
				<a class="button" href="<?php echo esc_url( $list ); ?>"><?php esc_html_e( 'See all case results', 'scout-results' ); ?></a>
				<a class="button sh-quiet" href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . Scout_Results::TAXONOMY . '&post_type=' . Scout_Results::POST_TYPE ) ); ?>"><?php esc_html_e( 'Practice areas', 'scout-results' ); ?></a>
			</p>
		<?php self::ui_fold_close(); ?>
		<?php
		Scout_Admin_UI::close();
	}
}
