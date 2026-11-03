<?php
/**
 * Writing settings (administrators): where the prompts come from, an upload when the theme has none,
 * where "Send to Scout" goes, and what the move from the Bliss Boogie writing room did.
 *
 * @package Scout_Writing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SCOUT_WRITING_SETTINGS_PAGE = 'scout-writing-settings';
/** Largest prompts file accepted, in bytes. */
const SCOUT_WRITING_UPLOAD_MAX = 2097152;

function scout_writing_settings_assets_hook() {
	add_action(
		'admin_enqueue_scripts',
		static function () {
			Scout_Admin_UI::enqueue( plugins_url( 'assets/scout-admin.css', SCOUT_WRITING_FILE ) );
		}
	);
}

function scout_writing_settings_url( array $args = array() ): string {
	return add_query_arg( array_merge( array( 'page' => SCOUT_WRITING_SETTINGS_PAGE ), $args ), admin_url( 'admin.php' ) );
}

/** Save, upload or remove (admin-post). */
add_action( 'admin_post_scout_writing_settings', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, only administrators can change the writing room.', 'scout-writing' ), 403 );
	}
	check_admin_referer( 'scout_writing_settings' );
	$do       = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	$settings = scout_writing_settings();
	$msg      = 'saved';
	if ( 'save' === $do ) {
		$raw   = isset( $_POST['send_to'] ) ? sanitize_text_field( wp_unslash( $_POST['send_to'] ) ) : '';
		$parts = array_filter( (array) preg_split( '/[\s,;]+/', $raw ) );
		$good  = array_values( array_filter( array_map( 'sanitize_email', $parts ), 'is_email' ) );
		if ( count( $good ) !== count( $parts ) ) {
			$msg = 'bad_email';
		} else {
			$settings['send_to'] = implode( ', ', $good );
		}
	} elseif ( 'upload' === $do ) {
		$file = $_FILES['prompts'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below, read as text, never moved or served.
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$msg = 'no_file';
		} elseif ( (int) $file['size'] > SCOUT_WRITING_UPLOAD_MAX ) {
			$msg = 'too_big';
		} else {
			$json = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the uploaded temp file.
			$data = scout_writing_parse( $json );
			if ( ! $data['prompts'] ) {
				$msg = 'bad_file';
			} else {
				update_option( SCOUT_WRITING_UPLOAD, wp_json_encode( json_decode( $json, true ) ), false );
				$settings['upload_name'] = sanitize_file_name( (string) $file['name'] );
				$settings['upload_at']   = time();
				$msg                     = 'uploaded';
			}
		}
	} elseif ( 'remove' === $do ) {
		delete_option( SCOUT_WRITING_UPLOAD );
		$settings['upload_name'] = '';
		$settings['upload_at']   = 0;
		$msg                     = 'removed';
	} elseif ( 'migrate' === $do ) {
		$moved = scout_writing_migrate();
		$msg   = array_sum( $moved ) ? 'migrated' : 'nothing';
	}
	update_option( SCOUT_WRITING_SETTINGS, $settings, false );
	wp_safe_redirect( scout_writing_settings_url( array( 'sw_msg' => $msg ) ) );
	exit;
} );

/** The settings screen. */
function scout_writing_settings_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = scout_writing_settings();
	$source   = scout_writing_source();
	$data     = scout_writing_data();
	$setup    = scout_writing_setup();
	$notes    = array(
		'saved'     => array( 'ok', 'Saved.' ),
		'uploaded'  => array( 'ok', 'Prompts file uploaded.' ),
		'removed'   => array( 'ok', 'Uploaded file removed.' ),
		'migrated'  => array( 'ok', 'Moved. See the log below.' ),
		'nothing'   => array( 'ok', 'Nothing left to move.' ),
		'bad_email' => array( 'err', 'One of those addresses is not an email address. Nothing was changed.' ),
		'no_file'   => array( 'err', 'Choose a .json prompts file first.' ),
		'too_big'   => array( 'err', 'That file is over 2 MB, which is far bigger than a prompts file should be.' ),
		'bad_file'  => array( 'err', 'That file has no prompts the room can read. Each prompt needs an id, a step and a question (q).' ),
	);
	$msg = isset( $_GET['sw_msg'] ) ? sanitize_key( wp_unslash( $_GET['sw_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.

	Scout_Admin_UI::open( 'Writing settings', 'Where the writing room gets its prompts, and where Send to Scout goes.', 'Writing' );
	if ( isset( $notes[ $msg ] ) ) {
		echo '<div class="notice notice-' . ( 'ok' === $notes[ $msg ][0] ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $notes[ $msg ][1] ) . '</p></div>';
	}

	echo '<section class="sh-box"><h2>Prompts</h2>';
	if ( 'theme' === $source['from'] ) {
		echo '<p>' . esc_html( sprintf( '%d prompts in %d steps, from %s.', count( $data['prompts'] ), count( $data['steps'] ), $source['label'] ) ) . '</p>';
		echo '<p class="description">The theme carries this file, so a theme update brings new prompts. Writing already done stays, matched by each prompt\'s id.</p>';
	} elseif ( 'upload' === $source['from'] ) {
		echo '<p>' . esc_html( sprintf( '%d prompts in %d steps, from %s on %s.', count( $data['prompts'] ), count( $data['steps'] ), $source['label'], wp_date( 'F j, Y', (int) $settings['upload_at'] ) ) ) . '</p>';
		if ( ! empty( $setup['prompts'] ) ) {
			echo '<p class="description">' . esc_html( sprintf( 'The theme names %s, but that file is not in the theme, so the uploaded file is used.', (string) $setup['prompts'] ) ) . '</p>';
		}
	} else {
		echo '<p><b>No prompts yet.</b> Writers see nothing until there are prompts; administrators see a short setup note in the menu.</p>';
	}
	if ( 'theme' !== $source['from'] ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		wp_nonce_field( 'scout_writing_settings' );
		echo '<input type="hidden" name="action" value="scout_writing_settings">';
		echo '<p><label for="sw-prompts-file">' . ( 'upload' === $source['from'] ? 'Replace it with another prompts file' : 'Upload a prompts file' ) . ' (.json)</label><br><input type="file" id="sw-prompts-file" name="prompts" accept=".json,application/json"></p>';
		echo '<p><button type="submit" class="button button-primary" name="do" value="upload">Upload</button>';
		if ( 'upload' === $source['from'] ) {
			echo ' <button type="submit" class="button" name="do" value="remove">Remove the uploaded file</button>';
		}
		echo '</p></form>';
		echo '<p class="description">Better still, keep the file in the theme: add <code>"writing": { "prompts": "writing-prompts.json" }</code> to its <code>scout-setup.json</code>. The format is in the Scout Writing README.</p>';
	}
	echo '</section>';

	echo '<section class="sh-box"><h2>Send to Scout</h2>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'scout_writing_settings' );
	echo '<input type="hidden" name="action" value="scout_writing_settings">';
	$fallback = ! empty( $setup['send_to'] ) ? implode( ', ', (array) $setup['send_to'] ) . ' (from the theme)' : SCOUT_WRITING_DEFAULT_TO;
	echo '<p><label for="sw-send-to">Email addresses</label><br><input type="text" class="regular-text" id="sw-send-to" name="send_to" value="' . esc_attr( $settings['send_to'] ) . '" placeholder="' . esc_attr( $fallback ) . '"></p>';
	echo '<p class="description">' . esc_html( 'Leave it empty to use ' . $fallback . '. Now going to: ' . implode( ', ', scout_writing_recipients() ) . '.' ) . '</p>';
	echo '<p><button type="submit" class="button button-primary" name="do" value="save">Save</button></p></form></section>';

	$log    = (array) get_option( SCOUT_WRITING_MIGRATION_LOG, array() );
	$legacy = scout_writing_has_legacy();
	if ( $log || $legacy ) {
		echo '<section class="sh-box"><h2>Moved from the Bliss Boogie writing room</h2>';
		if ( $log ) {
			echo '<ul>';
			foreach ( array_reverse( $log ) as $row ) {
				echo '<li>' . esc_html( wp_date( 'M j, Y g:i a', (int) ( $row['at'] ?? 0 ) ) . ': ' . (string) ( $row['line'] ?? '' ) ) . '</li>';
			}
			echo '</ul>';
		}
		if ( $legacy ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'scout_writing_settings' );
			echo '<input type="hidden" name="action" value="scout_writing_settings"><p>Some writing from the old room has not moved yet. <button type="submit" class="button" name="do" value="migrate">Move it now</button></p></form>';
		}
		echo '</section>';
	}
	Scout_Admin_UI::close();
}
