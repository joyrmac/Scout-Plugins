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
		<?php Scout_Admin_UI::open( __( 'AI search', 'scout-aeo' ), __( 'More people now ask ChatGPT, Google\'s AI answers, Perplexity or Claude instead of searching. These settings help those tools find this site, read it, and name it in their answers.', 'scout-aeo' ) ); ?>
			<?php if ( ! Scout_AEO::is_live() ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'This copy of the site is not live (staging, or search engines are switched off), so robots.txt is left alone here. Everything below takes effect on the live site.', 'scout-aeo' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="options.php" class="sh-box">
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
							<p class="description"><?php esc_html_e( 'Both choices are also written into robots.txt as a Content-Signal line, the standard way to tell AI companies how pages may be used.', 'scout-aeo' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Instant updates', 'scout-aeo' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[indexnow]" value="1" <?php checked( $s['indexnow'] ); ?>> <?php esc_html_e( 'Tell Bing and Copilot right away when a page is published or updated (IndexNow)', 'scout-aeo' ); ?></label>
							<p class="description"><?php esc_html_e( 'ChatGPT search also draws on Bing, so changes reach AI answers sooner. Runs on the live site only.', 'scout-aeo' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Guide for AI tools', 'scout-aeo' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[llms]" value="1" <?php checked( $s['llms'] ); ?>> <?php esc_html_e( 'Publish a plain-text guide to the site at /llms.txt', 'scout-aeo' ); ?></label>
							<?php if ( $s['llms'] ) : ?>
								<p class="description"><a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View the guide', 'scout-aeo' ); ?></a> · <a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View robots.txt', 'scout-aeo' ); ?></a></p>
							<?php endif; ?></td>
					</tr>
					<?php if ( $custom ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'What the guide says', 'scout-aeo' ); ?></th>
						<td><p><?php esc_html_e( 'This site\'s theme writes its own guide, so its wording, pages and posts come from the theme. Open the guide above to read it.', 'scout-aeo' ); ?></p></td>
					</tr>
					<?php else : ?>
					<tr>
						<th scope="row"><label for="scout-aeo-intro"><?php esc_html_e( 'Short description', 'scout-aeo' ); ?></label></th>
						<td><textarea id="scout-aeo-intro" name="<?php echo esc_attr( $o ); ?>[intro]" rows="4" class="large-text" placeholder="<?php echo esc_attr( Scout_AEO::summary() ); ?>"><?php echo esc_textarea( $s['intro'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Two or three plain sentences: who the business is, what it does, where. AI tools read this first. Leave empty to use the business name, location and tagline.', 'scout-aeo' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Pages to list', 'scout-aeo' ); ?></th>
						<td><fieldset style="max-height:280px;overflow:auto;border:1px solid var(--sh-border-strong);border-radius:12px;padding:10px 14px;max-width:520px;background:#fff">
							<?php foreach ( $pages as $p ) : ?>
								<label style="display:block;margin:4px 0;padding-left:<?php echo (int) ( count( get_post_ancestors( $p ) ) * 18 ); ?>px"><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[pages][]" value="<?php echo (int) $p->ID; ?>" <?php checked( in_array( (int) $p->ID, $s['pages'], true ) ); ?>> <?php echo esc_html( get_the_title( $p ) ); ?></label>
							<?php endforeach; ?>
						</fieldset>
							<p class="description"><?php esc_html_e( 'None ticked lists the main pages automatically. Pages hidden from search are always left out.', 'scout-aeo' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="scout-aeo-posts"><?php esc_html_e( 'Recent posts to list', 'scout-aeo' ); ?></label></th>
						<td><input id="scout-aeo-posts" type="number" min="0" max="50" name="<?php echo esc_attr( $o ); ?>[posts]" value="<?php echo (int) $s['posts']; ?>" class="small-text"></td>
					</tr>
					<?php endif; ?>
				</table>
				<?php submit_button(); ?>
			</form>
			<section class="sh-box">
			<h2><?php esc_html_e( 'Questions and answers', 'scout-aeo' ); ?></h2>
			<p><?php esc_html_e( 'AI tools quote short, direct answers. Every page and post has a "Questions this page answers" box in the editor. Answers show at the end of the page (or wherever you put [scout_faq]) with the matching schema.', 'scout-aeo' ); ?></p>
			<?php
			$with = Scout_AEO_FAQ::pages_with_answers();
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
		<p><?php esc_html_e( 'Write the questions people really ask, then answer each one in two or three plain sentences that make sense on their own. AI tools often quote exactly these.', 'scout-aeo' ); ?></p>
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
