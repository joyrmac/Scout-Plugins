<?php
/**
 * The "AI search" settings screen, nested in the site's Build section, and
 * the "Questions this page answers" box in the page editor.
 *
 * @package Scout_AEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_AEO_Admin {

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

	const SLUG  = 'scout-ai-search';
	const NONCE = 'scout_aeo_faq_nonce';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_post' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_AEO_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/** Nest under the hub or theme Build section; fall back to Settings. */
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
		add_submenu_page( self::parent(), __( 'AI search', 'scout-aeo' ), __( 'AI search', 'scout-aeo' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::SLUG ) ) {
			Scout_Admin_UI::enqueue( plugins_url( 'assets/scout-admin.css', SCOUT_AEO_FILE ) );
		}
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'scout-aeo' ) . '</a>' );
		return $links;
	}

	public static function register(): void {
		register_setting( 'scout_aeo_group', Scout_AEO::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ): array {
		$in = (array) $in;
		return array(
			'search'   => ! empty( $in['search'] ) ? 1 : 0,
			'training' => ! empty( $in['training'] ) ? 1 : 0,
			'llms'     => ! empty( $in['llms'] ) ? 1 : 0,
			'indexnow' => ! empty( $in['indexnow'] ) ? 1 : 0,
			'intro'    => sanitize_textarea_field( (string) ( $in['intro'] ?? '' ) ),
			'pages'    => array_values( array_filter( array_map( 'absint', (array) ( $in['pages'] ?? array() ) ) ) ),
			'posts'    => max( 0, min( 50, (int) ( $in['posts'] ?? 20 ) ) ),
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = Scout_AEO::settings();
		$o     = Scout_AEO::OPTION;
		$pages = get_pages( array( 'sort_column' => 'menu_order,post_title' ) );
		$custom = has_filter( 'scout_aeo_llms_txt' );
		?>
		<?php Scout_Admin_UI::open( __( 'AI search', 'scout-aeo' ), __( 'Helps AI tools like ChatGPT, Google\'s AI answers and Perplexity find this site and name it in answers.', 'scout-aeo' ) ); ?>
			<?php
			$with = Scout_AEO_FAQ::pages_with_answers();
			$live = Scout_AEO::is_live();
			if ( $s['search'] ) {
				$headline = __( 'On.', 'scout-aeo' );
				$detail   = __( 'AI search tools can read the site to answer people\'s questions.', 'scout-aeo' );
			} else {
				$headline = __( 'Off.', 'scout-aeo' );
				$detail   = __( 'AI search tools are asked not to read the site, so AI answers will rarely quote it.', 'scout-aeo' );
			}
			$detail .= ' ' . ( $s['training'] ? __( 'Model training is allowed.', 'scout-aeo' ) : __( 'Model training is switched off.', 'scout-aeo' ) );
			$detail .= ' ' . ( $s['llms'] ? __( 'The AI guide page is on.', 'scout-aeo' ) : __( 'The AI guide page is off.', 'scout-aeo' ) );
			$detail .= ' ' . ( $s['indexnow'] ? __( 'Bing hears about changes right away.', 'scout-aeo' ) : __( 'Instant updates to Bing are off.', 'scout-aeo' ) );
			/* translators: %d: number of pages with questions and answers. */
			$detail .= ' ' . sprintf( _n( '%d page has questions and answers.', '%d pages have questions and answers.', count( $with ), 'scout-aeo' ), count( $with ) );
			if ( ! $live ) {
				$detail .= ' ' . __( 'This copy of the site is not live, so its search engine rules (robots.txt) stay as they are. The settings below work on the live site.', 'scout-aeo' );
			}
			$go = $s['llms'] ? '<a class="button" href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View the AI guide page', 'scout-aeo' ) . '</a>' : '';
			self::ui_status( ! $s['search'] ? 'off' : ( $live ? 'good' : 'warn' ), $headline, $detail, $go );
			?>
			<form method="post" action="options.php">
				<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Who can read the site', 'scout-aeo' ); ?></h2>
				<?php settings_fields( 'scout_aeo_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'AI search tools', 'scout-aeo' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[search]" value="1" <?php checked( $s['search'] ); ?>> <?php esc_html_e( 'Let AI search tools read the site to answer people\'s questions', 'scout-aeo' ); ?></label>
							<p class="description"><?php esc_html_e( 'This is how the site gets quoted and linked in AI answers. Leave it on.', 'scout-aeo' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AI model training', 'scout-aeo' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[training]" value="1" <?php checked( $s['training'] ); ?>> <?php esc_html_e( 'Let AI companies learn from the site\'s public pages', 'scout-aeo' ); ?></label>
							<p class="description"><?php esc_html_e( 'Helps the AI know the business by name. Switching it off does not affect AI search above.', 'scout-aeo' ); ?></p>
							<p class="description"><?php esc_html_e( 'Both choices also go into the search engine rules (robots.txt), which tell AI companies how pages may be used.', 'scout-aeo' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Instant updates', 'scout-aeo' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[indexnow]" value="1" <?php checked( $s['indexnow'] ); ?>> <?php esc_html_e( 'Tell Bing and Copilot right away when a page is published or updated (IndexNow)', 'scout-aeo' ); ?></label>
							<p class="description"><?php esc_html_e( 'ChatGPT search also draws on Bing, so changes reach AI answers sooner. Runs on the live site only.', 'scout-aeo' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AI guide page', 'scout-aeo' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[llms]" value="1" <?php checked( $s['llms'] ); ?>> <?php esc_html_e( 'Publish a plain-text guide to the site for AI tools, at /llms.txt', 'scout-aeo' ); ?></label>
							<?php if ( $s['llms'] ) : ?>
								<p class="description"><a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View the AI guide page', 'scout-aeo' ); ?></a> · <a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View search engine rules', 'scout-aeo' ); ?></a></p>
							<?php endif; ?></td>
					</tr>
				</table>
				</section>

				<?php self::ui_fold_open( __( 'More options', 'scout-aeo' ) ); ?>
				<table class="form-table" role="presentation">
					<?php if ( $custom ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'What the guide says', 'scout-aeo' ); ?></th>
						<td><p><?php esc_html_e( 'This theme writes its own AI guide page, so its wording comes from the theme.', 'scout-aeo' ); ?></p></td>
					</tr>
					<?php else : ?>
					<tr>
						<th scope="row"><label for="scout-aeo-intro"><?php esc_html_e( 'Short description', 'scout-aeo' ); ?></label></th>
						<td><textarea id="scout-aeo-intro" name="<?php echo esc_attr( $o ); ?>[intro]" rows="4" class="large-text" placeholder="<?php echo esc_attr( Scout_AEO::summary() ); ?>"><?php echo esc_textarea( $s['intro'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Two or three plain sentences: who the business is, what it does and where. Leave empty to use the business details.', 'scout-aeo' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Pages to list', 'scout-aeo' ); ?></th>
						<td><fieldset style="max-height:280px;overflow:auto;border:1px solid var(--sh-border-strong);border-radius:12px;padding:10px 14px;max-width:520px;background:var(--sh-surface)">
							<?php foreach ( $pages as $p ) : ?>
								<label style="display:block;margin:4px 0;padding-left:<?php echo (int) ( count( get_post_ancestors( $p ) ) * 18 ); ?>px"><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[pages][]" value="<?php echo (int) $p->ID; ?>" <?php checked( in_array( (int) $p->ID, $s['pages'], true ) ); ?>> <?php echo esc_html( get_the_title( $p ) ); ?></label>
							<?php endforeach; ?>
						</fieldset>
							<p class="description"><?php esc_html_e( 'Tick none to list the main pages. Pages hidden from search are always left out.', 'scout-aeo' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="scout-aeo-posts"><?php esc_html_e( 'Recent posts to list', 'scout-aeo' ); ?></label></th>
						<td><input id="scout-aeo-posts" type="number" min="0" max="50" name="<?php echo esc_attr( $o ); ?>[posts]" value="<?php echo (int) $s['posts']; ?>" class="small-text"></td>
					</tr>
					<?php endif; ?>
				</table>
				<?php self::ui_fold_close(); ?>
				<?php submit_button( __( 'Save changes', 'scout-aeo' ) ); ?>
			</form>
			<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Questions and answers', 'scout-aeo' ); ?></h2>
			<p><?php esc_html_e( 'Add them in the "Questions this page answers" box on any page or post. They show at the end of the page.', 'scout-aeo' ); ?></p>
			<?php
			if ( $with ) {
				echo '<ul style="list-style:disc;padding-left:20px;margin:0">';
				foreach ( $with as $id ) {
					echo '<li><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a> (' . count( Scout_AEO_FAQ::get( $id ) ) . ')</li>';
				}
				echo '</ul>';
			} else {
				echo '<p class="sh-note">' . esc_html__( 'No pages have answers yet. Start with the pages that bring in the most business.', 'scout-aeo' ) . '</p>';
			}
			?>
			</section>
		<?php
		Scout_Admin_UI::close();
	}

	public static function meta_box(): void {
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}
			add_meta_box( 'scout-aeo-faq', __( 'Questions this page answers', 'scout-aeo' ), array( __CLASS__, 'render_box' ), $type, 'normal', 'default' );
		}
	}

	public static function render_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		$rows    = Scout_AEO_FAQ::get( (int) $post->ID );
		$heading = (string) get_post_meta( $post->ID, Scout_AEO_FAQ::HEADING, true );
		$show    = max( 3, min( Scout_AEO_FAQ::MAX, count( $rows ) + 1 ) );
		?>
		<p><?php esc_html_e( 'Write the questions people really ask, and answer each in two or three plain sentences.', 'scout-aeo' ); ?></p>
		<p><label><strong><?php esc_html_e( 'Section heading', 'scout-aeo' ); ?></strong><br>
			<input type="text" class="widefat" name="scout_aeo_heading" value="<?php echo esc_attr( $heading ); ?>" placeholder="<?php esc_attr_e( 'Common questions', 'scout-aeo' ); ?>"></label></p>
		<?php for ( $i = 0; $i < Scout_AEO_FAQ::MAX; $i++ ) : ?>
			<div class="scout-aeo-row" style="border-top:1px solid #dcdcde;padding:10px 0;<?php echo $i >= $show ? 'display:none' : ''; ?>">
				<label><?php /* translators: %d: question number */ printf( esc_html__( 'Question %d', 'scout-aeo' ), (int) $i + 1 ); ?><br>
					<input type="text" class="widefat" name="scout_aeo_faq[<?php echo (int) $i; ?>][q]" value="<?php echo esc_attr( $rows[ $i ]['q'] ?? '' ); ?>"></label>
				<label style="display:block;margin-top:6px"><?php esc_html_e( 'Answer', 'scout-aeo' ); ?><br>
					<textarea class="widefat" rows="3" name="scout_aeo_faq[<?php echo (int) $i; ?>][a]"><?php echo esc_textarea( $rows[ $i ]['a'] ?? '' ); ?></textarea></label>
			</div>
		<?php endfor; ?>
		<?php if ( $show < Scout_AEO_FAQ::MAX ) : ?>
			<p><button type="button" class="button" onclick="var r=this.closest('.inside').querySelector('.scout-aeo-row[style*=&quot;display:none&quot;]');if(r){r.style.display='';}if(!this.closest('.inside').querySelector('.scout-aeo-row[style*=&quot;display:none&quot;]')){this.remove();}"><?php esc_html_e( 'Add another question', 'scout-aeo' ); ?></button></p>
		<?php endif; ?>
		<?php
	}

	public static function save_post( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$rows = array();
		foreach ( (array) wp_unslash( $_POST['scout_aeo_faq'] ?? array() ) as $row ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
			$q = sanitize_text_field( (string) ( $row['q'] ?? '' ) );
			$a = sanitize_textarea_field( (string) ( $row['a'] ?? '' ) );
			if ( '' !== $q && '' !== $a ) {
				$rows[] = array( 'q' => $q, 'a' => $a );
			}
		}
		$rows ? update_post_meta( $post_id, Scout_AEO_FAQ::META, array_slice( $rows, 0, Scout_AEO_FAQ::MAX ) ) : delete_post_meta( $post_id, Scout_AEO_FAQ::META );
		$heading = sanitize_text_field( (string) wp_unslash( $_POST['scout_aeo_heading'] ?? '' ) );
		'' !== $heading ? update_post_meta( $post_id, Scout_AEO_FAQ::HEADING, $heading ) : delete_post_meta( $post_id, Scout_AEO_FAQ::HEADING );
	}
}
