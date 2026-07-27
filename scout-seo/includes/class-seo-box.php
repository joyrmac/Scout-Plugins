<?php
/**
 * The "Scout SEO" editor box: SEO title, meta description, canonical override,
 * and a noindex toggle, with a live Google-style snippet preview. No ACF, no
 * third-party. This is the one piece of Yoast's UI worth keeping.
 *
 * @package Scout_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_SEO_Box {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Public post types except attachments. Filterable so a project can opt a
	 * type in or out.
	 *
	 * @return string[]
	 */
	private static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return (array) apply_filters( 'scout_seo_post_types', array_values( $types ) );
	}

	public static function register_meta() {
		$auth = static function () {
			return current_user_can( 'edit_posts' );
		};
		$map = array(
			SCOUT_SEO_META_TITLE     => 'sanitize_text_field',
			SCOUT_SEO_META_DESC      => 'sanitize_textarea_field',
			SCOUT_SEO_META_CANONICAL => 'esc_url_raw',
			SCOUT_SEO_META_NOINDEX   => static function ( $value ) {
				return $value ? '1' : '';
			},
		);
		foreach ( self::post_types() as $post_type ) {
			foreach ( $map as $key => $sanitize ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'single'            => true,
						'type'              => 'string',
						'show_in_rest'      => false,
						'sanitize_callback' => $sanitize,
						'auth_callback'     => $auth,
					)
				);
			}
		}
	}

	public static function add() {
		foreach ( self::post_types() as $post_type ) {
			add_meta_box( 'scout-seo', 'Scout SEO', array( __CLASS__, 'render' ), $post_type, 'normal', 'default' );
		}
	}

	public static function render( $post ) {
		wp_nonce_field( 'scout_seo_save', 'scout_seo_nonce' );

		$title    = get_post_meta( $post->ID, SCOUT_SEO_META_TITLE, true );
		$desc     = get_post_meta( $post->ID, SCOUT_SEO_META_DESC, true );
		$canon    = get_post_meta( $post->ID, SCOUT_SEO_META_CANONICAL, true );
		$noindex  = get_post_meta( $post->ID, SCOUT_SEO_META_NOINDEX, true );
		$url      = get_permalink( $post->ID );
		$fallback = wp_strip_all_tags( get_the_title( $post->ID ) );
		?>
		<div class="scout-seo-box" style="display:grid;gap:14px;max-width:760px;">
			<div style="border:1px solid #dcdcde;border-radius:8px;padding:12px 16px;background:#fff;">
				<div id="scout-seo-pv-title" style="color:#1a0dab;font-size:18px;line-height:1.3;"></div>
				<div id="scout-seo-pv-url" style="color:#006621;font-size:13px;"></div>
				<div id="scout-seo-pv-desc" style="color:#4d5156;font-size:13px;line-height:1.5;"></div>
			</div>

			<p style="margin:0;">
				<label for="scout-seo-title" style="font-weight:600;display:block;">SEO title <span id="scout-seo-title-count" style="font-weight:400;color:#646970;"></span></label>
				<input type="text" id="scout-seo-title" name="scout_seo[title]" value="<?php echo esc_attr( $title ); ?>" style="width:100%;" placeholder="<?php echo esc_attr( $fallback ); ?>" />
				<span style="color:#646970;font-size:12px;">Aim for about 60 characters. Leave blank to use the page title.</span>
			</p>

			<p style="margin:0;">
				<label for="scout-seo-desc" style="font-weight:600;display:block;">Meta description <span id="scout-seo-desc-count" style="font-weight:400;color:#646970;"></span></label>
				<textarea id="scout-seo-desc" name="scout_seo[desc]" rows="3" style="width:100%;"><?php echo esc_textarea( $desc ); ?></textarea>
				<span style="color:#646970;font-size:12px;">Aim for about 155 characters. Leave blank to auto-generate from the content.</span>
			</p>

			<p style="margin:0;">
				<label for="scout-seo-canonical" style="font-weight:600;display:block;">Canonical URL</label>
				<input type="url" id="scout-seo-canonical" name="scout_seo[canonical]" value="<?php echo esc_attr( $canon ); ?>" style="width:100%;" placeholder="<?php echo esc_url( $url ); ?>" />
				<span style="color:#646970;font-size:12px;">Leave blank unless this page should point search engines to a different URL.</span>
			</p>

			<p style="margin:0;">
				<label><input type="checkbox" name="scout_seo[noindex]" value="1" <?php checked( $noindex, '1' ); ?> /> Hide this page from search engines (noindex)</label>
			</p>
		</div>
		<script>
		( function () {
			var t  = document.getElementById( 'scout-seo-title' ),
				d  = document.getElementById( 'scout-seo-desc' ),
				pt = document.getElementById( 'scout-seo-pv-title' ),
				pu = document.getElementById( 'scout-seo-pv-url' ),
				pd = document.getElementById( 'scout-seo-pv-desc' ),
				tc = document.getElementById( 'scout-seo-title-count' ),
				dc = document.getElementById( 'scout-seo-desc-count' ),
				fb = <?php echo wp_json_encode( $fallback ); ?>,
				url = <?php echo wp_json_encode( $url ); ?>;
			function update() {
				pt.textContent = t.value || fb;
				pu.textContent = url;
				pd.textContent = d.value;
				tc.textContent = '(' + t.value.length + ')';
				dc.textContent = '(' + d.value.length + ')';
			}
			if ( t && d ) {
				t.addEventListener( 'input', update );
				d.addEventListener( 'input', update );
				update();
			}
		} )();
		</script>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['scout_seo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scout_seo_nonce'] ) ), 'scout_seo_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$in = isset( $_POST['scout_seo'] ) ? (array) wp_unslash( $_POST['scout_seo'] ) : array();

		self::set( $post_id, SCOUT_SEO_META_TITLE, isset( $in['title'] ) ? sanitize_text_field( $in['title'] ) : '' );
		self::set( $post_id, SCOUT_SEO_META_DESC, isset( $in['desc'] ) ? sanitize_textarea_field( $in['desc'] ) : '' );
		self::set( $post_id, SCOUT_SEO_META_CANONICAL, isset( $in['canonical'] ) ? esc_url_raw( $in['canonical'] ) : '' );
		self::set( $post_id, SCOUT_SEO_META_NOINDEX, empty( $in['noindex'] ) ? '' : '1' );
	}

	private static function set( $post_id, $key, $value ) {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}
}
