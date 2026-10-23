<?php
/**
 * More than one photo per speaker. The featured image is the main photo;
 * every other photo of them stays on the profile as an option. On the edit
 * screen the owner picks the main one with one click, adds photos from the
 * Media Library, and can show the others as a small strip on the speaker's
 * public page.
 *
 * Swapping the main photo, by any route (the Featured image box, an approved
 * presenter change, an import), keeps the old one as an option instead of
 * losing it. Removing the main photo removes it.
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_Photos {

	const META  = '_scout_speaker_photos';
	const STRIP = '_scout_speaker_strip';
	const NONCE = '_se_photos_box';
	const MAX_UPLOADS = 4;

	public static function boot(): void {
		add_action( 'add_meta_boxes_' . Scout_Events::SPEAKER, array( __CLASS__, 'box' ) );
		add_action( 'save_post_' . Scout_Events::SPEAKER, array( __CLASS__, 'save_box' ), 20 );
		add_action( 'update_post_meta', array( __CLASS__, 'keep_old_main' ), 10, 4 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/* ---------- the data ---------- */

	/** The other photos, without the main one. */
	public static function extras( int $speaker ): array {
		$ids  = array_map( 'intval', (array) get_post_meta( $speaker, self::META, true ) );
		$main = (int) get_post_thumbnail_id( $speaker );
		return array_values( array_unique( array_filter( $ids, static fn( $id ) => $id && $id !== $main && 'attachment' === get_post_type( $id ) ) ) );
	}

	/** Every photo, the main one first. */
	public static function all( int $speaker ): array {
		$main = (int) get_post_thumbnail_id( $speaker );
		return array_values( array_filter( array_merge( array( $main ), self::extras( $speaker ) ) ) );
	}

	public static function add( int $speaker, int $attachment, bool $even_if_main = false ): void {
		if ( $attachment <= 0 || ( ! $even_if_main && $attachment === (int) get_post_thumbnail_id( $speaker ) ) ) {
			return;
		}
		$ids   = array_map( 'intval', (array) get_post_meta( $speaker, self::META, true ) );
		$ids[] = $attachment;
		update_post_meta( $speaker, self::META, array_values( array_unique( array_filter( $ids ) ) ) );
	}

	/** When a speaker's main photo is replaced, the old one stays as an option. */
	public static function keep_old_main( $meta_id, $object_id, $meta_key, $meta_value ): void {
		if ( '_thumbnail_id' !== $meta_key || Scout_Events::SPEAKER !== get_post_type( (int) $object_id ) ) {
			return;
		}
		$old = (int) get_post_meta( (int) $object_id, '_thumbnail_id', true );
		if ( $old && $old !== (int) $meta_value ) {
			// The old photo is still the main one at this moment, so add it regardless.
			self::add( (int) $object_id, $old, true );
		}
	}

	/* ---------- the edit screen ---------- */

	public static function box(): void {
		add_meta_box( 'scout-speaker-photos', __( 'Photos', 'scout-events' ), array( __CLASS__, 'render_box' ), Scout_Events::SPEAKER, 'normal', 'default' );
	}

	public static function assets( $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Scout_Events::SPEAKER !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_add_inline_script(
			'media-editor',
			'(function(){document.addEventListener("click",function(e){var b=e.target.closest(".se-photos-add");if(!b){return;}e.preventDefault();'
			. 'var f=wp.media({title:' . wp_json_encode( __( 'Add photos of this speaker', 'scout-events' ) ) . ',button:{text:' . wp_json_encode( __( 'Add to their photos', 'scout-events' ) ) . '},library:{type:"image"},multiple:true});'
			. 'f.on("select",function(){var list=document.querySelector(".se-photos");f.state().get("selection").each(function(a){var d=a.toJSON();if(list.querySelector("[data-id=\""+d.id+"\"]")){return;}'
			. 'var s=(d.sizes&&d.sizes.thumbnail)?d.sizes.thumbnail.url:d.url;var li=document.createElement("li");li.className="se-photo";li.setAttribute("data-id",d.id);'
			. 'li.innerHTML="<img alt=\"\" src=\""+s+"\"><input type=\"hidden\" name=\"se_photos[]\" value=\""+d.id+"\"><label><input type=\"radio\" name=\"se_photo_main\" value=\""+d.id+"\"> "+' . wp_json_encode( __( 'Main photo', 'scout-events' ) ) . '+"</label><label><input type=\"checkbox\" name=\"se_photo_remove[]\" value=\""+d.id+"\"> "+' . wp_json_encode( __( 'Remove', 'scout-events' ) ) . '+"</label>";'
			. 'list.appendChild(li);});var e2=document.querySelector(".se-photos-empty");if(e2){e2.hidden=true;}});f.open();});})();'
		);
	}

	public static function render_box( $post ): void {
		$id    = (int) $post->ID;
		$main  = (int) get_post_thumbnail_id( $id );
		$all   = self::all( $id );
		wp_nonce_field( 'scout_photos_save', self::NONCE );
		?>
		<p class="se-help"><?php esc_html_e( 'Every photo of this speaker. The main photo shows on their card and page. Swap it any time; the old one stays here as an option.', 'scout-events' ); ?></p>
		<ul class="se-photos">
			<?php foreach ( $all as $att ) : ?>
				<li class="se-photo<?php echo $att === $main ? ' is-main' : ''; ?>" data-id="<?php echo (int) $att; ?>">
					<?php echo wp_get_attachment_image( $att, 'thumbnail', false, array( 'alt' => '' ) ); ?>
					<input type="hidden" name="se_photos[]" value="<?php echo (int) $att; ?>">
					<label><input type="radio" name="se_photo_main" value="<?php echo (int) $att; ?>" <?php checked( $att, $main ); ?>> <?php esc_html_e( 'Main photo', 'scout-events' ); ?></label>
					<?php if ( $att !== $main ) : ?>
						<label><input type="checkbox" name="se_photo_remove[]" value="<?php echo (int) $att; ?>"> <?php esc_html_e( 'Remove', 'scout-events' ); ?></label>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( ! $all ) : ?>
			<p class="se-photos-empty se-help"><?php esc_html_e( 'No photos yet.', 'scout-events' ); ?></p>
		<?php endif; ?>
		<p><button type="button" class="button se-photos-add"><?php esc_html_e( 'Add photos', 'scout-events' ); ?></button></p>
		<p><label><input type="checkbox" name="se_photo_strip" value="1" <?php checked( (bool) get_post_meta( $id, self::STRIP, true ) ); ?>> <?php esc_html_e( 'Show the other photos on their public page', 'scout-events' ); ?></label></p>
		<p class="se-help"><?php esc_html_e( 'Choose a main photo and click Update. Removing a photo here keeps it in the Media Library.', 'scout-events' ); ?></p>
		<?php
	}

	public static function save_box( $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'scout_photos_save' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$listed = array_map( 'absint', (array) wp_unslash( $_POST['se_photos'] ?? array() ) );
		$remove = array_map( 'absint', (array) wp_unslash( $_POST['se_photo_remove'] ?? array() ) );
		$keep   = array_values( array_diff( $listed, $remove ) );
		$main   = isset( $_POST['se_photo_main'] ) ? absint( $_POST['se_photo_main'] ) : 0;
		update_post_meta( (int) $post_id, self::META, array_values( array_filter( $keep, static fn( $a ) => 'attachment' === get_post_type( $a ) ) ) );
		if ( $main && in_array( $main, $keep, true ) && $main !== (int) get_post_thumbnail_id( $post_id ) ) {
			set_post_thumbnail( (int) $post_id, $main );
		}
		update_post_meta( (int) $post_id, self::STRIP, empty( $_POST['se_photo_strip'] ) ? 0 : 1 );
	}

	/* ---------- the public page ---------- */

	/** The other photos as a small strip, when the owner turned it on. */
	public static function strip( int $speaker ): string {
		$extras = self::extras( $speaker );
		if ( ! $extras || ! get_post_meta( $speaker, self::STRIP, true ) ) {
			return '';
		}
		$name = get_the_title( $speaker );
		$out  = '<ul class="se-speaker-photos" aria-label="' . esc_attr( sprintf( /* translators: %s: speaker name. */ __( 'More photos of %s', 'scout-events' ), $name ) ) . '">';
		foreach ( $extras as $att ) {
			$full = wp_get_attachment_image_url( $att, 'large' );
			$out .= '<li><a href="' . esc_url( (string) $full ) . '">' . wp_get_attachment_image( $att, 'medium', false, array( 'alt' => sprintf( /* translators: %s: speaker name. */ __( 'Photo of %s', 'scout-events' ), $name ), 'loading' => 'lazy' ) ) . '</a></li>';
		}
		return $out . '</ul>';
	}

	/* ---------- uploads from presenters ---------- */

	/**
	 * Photos sent in a form's multi-file field, into the Media Library.
	 *
	 * @return array{ids:int[],failed:bool}
	 */
	public static function upload( string $field, int $parent ): array {
		$out = array(
			'ids'    => array(),
			'failed' => false,
		);
		if ( empty( $_FILES[ $field ]['name'] ) || ! is_array( $_FILES[ $field ]['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller checks the form's nonce.
			return $out;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$files = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- handed to WordPress's own upload checks.
		$count = 0;
		foreach ( (array) $files['name'] as $i => $name ) {
			if ( '' === (string) $name ) {
				continue;
			}
			if ( ++$count > self::MAX_UPLOADS ) {
				break;
			}
			$one = array(
				'name'     => $name,
				'type'     => $files['type'][ $i ] ?? '',
				'tmp_name' => $files['tmp_name'][ $i ] ?? '',
				'error'    => $files['error'][ $i ] ?? UPLOAD_ERR_NO_FILE,
				'size'     => $files['size'][ $i ] ?? 0,
			);
			if ( (int) $one['size'] > 8 * MB_IN_BYTES || ! in_array( wp_check_filetype( (string) $name )['type'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
				$out['failed'] = true;
				continue;
			}
			$id = media_handle_sideload( $one, $parent );
			if ( is_wp_error( $id ) ) {
				$out['failed'] = true;
			} else {
				$out['ids'][] = (int) $id;
			}
		}
		return $out;
	}
}
