<?php
/**
 * Hidden upload guard: refuses files sent to Gravity Forms File Upload fields
 * whose Visibility is Hidden or Administrative.
 *
 * Why: a reported critical Gravity Forms flaw (CVE-2026-84434, reported 9.8)
 * lets an unauthenticated visitor upload a file that runs code through any
 * File Upload field set to Hidden. Gravity Forms has no fix yet. A visitor
 * never sees a hidden field, so a real person never sends a file to one, and
 * refusing those files costs a real visitor nothing.
 *
 * Gravity Forms source was not available when this was written. Everything
 * below is written against its documented public API, and each place that
 * relies on how Gravity Forms behaves inside says "Assumes" so it can be
 * checked on staging:
 *
 * - Assumes GFAPI::get_forms() returns the active, untrashed forms, and
 *   GFAPI::get_form( $id ) returns one form array (or false), with
 *   $form['fields'] holding GF_Field objects (ArrayAccess) or plain arrays.
 * - Assumes a File Upload field has type 'fileupload' (or inputType
 *   'fileupload' when wrapped, for example by a Post or Quiz field), and its
 *   Visibility setting is stored as $field->visibility: 'visible', 'hidden'
 *   or 'administrative'.
 * - Assumes a regular submission posts gform_submit (the form id), sends a
 *   single file as $_FILES['input_{field id}'], and lists files already sent
 *   by drag and drop in $_POST['gform_uploaded_files'], a slashed JSON object
 *   keyed 'input_{field id}'.
 * - Assumes Gravity Forms reads a submission on or after the 'wp' hook (or on
 *   init at its default priority for AJAX), so our init priority 1 runs first.
 * - Assumes drag-and-drop (multi-file) uploads post to ?gf_page=upload with
 *   form_id and field_id in the request, and that Gravity Forms handles that
 *   request no earlier than plugins_loaded at its default priority. Our check
 *   runs on plugins_loaded at priority -1000, before any default-priority
 *   callback. If the staging test shows the upload still lands, Gravity
 *   Forms handles it at load time and this hook point has to move.
 *
 * @package Scout_Forms_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Forms_Guard_Uploads {

	const CACHE = 'scout_forms_guard_hidden_uploads'; // Transient: the list find() returns.

	/** Hidden values that keep a field off the public form. */
	const HIDDEN = array( 'hidden', 'administrative' );

	public static function boot(): void {
		// Drag-and-drop upload endpoint. plugins_loaded is the first hook that
		// fires after every plugin file (Gravity Forms included) has loaded.
		add_action( 'plugins_loaded', array( __CLASS__, 'guard_upload_endpoint' ), -1000 );

		// Regular submit. The user is set up just before init fires, and
		// Gravity Forms reads the submission later (see the header).
		add_action( 'init', array( __CLASS__, 'guard_submission' ), 1 );

		// Forget the cached list whenever a form changes. The last five hooks
		// are documented Gravity Forms actions; if one does not exist in a
		// given version it simply never fires and the six-hour cache covers it.
		foreach ( array( 'gform_after_save_form', 'gform_after_delete_form', 'gform_post_form_trashed', 'gform_post_form_restored', 'gform_post_form_activated', 'gform_post_form_deactivated' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'forget' ) );
		}

		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/** Is the guard switched on? Turn it off with the filter (in a plugin or mu-plugin file, not on init). */
	public static function enabled(): bool {
		return (bool) apply_filters( 'scout_forms_guard_block_hidden_uploads', true );
	}

	public static function forget(): void {
		delete_transient( self::CACHE );
	}

	/* ---------------------------------------------------------------- */
	/* Find                                                              */
	/* ---------------------------------------------------------------- */

	/** Read a field setting from a GF_Field object or a plain array. */
	private static function prop( $field, string $key ): string {
		if ( is_object( $field ) && isset( $field->$key ) ) {
			return is_scalar( $field->$key ) ? (string) $field->$key : '';
		}
		if ( is_array( $field ) && isset( $field[ $key ] ) ) {
			return is_scalar( $field[ $key ] ) ? (string) $field[ $key ] : '';
		}
		return '';
	}

	/** True when a field is a File Upload field the public never sees. */
	public static function is_hidden_upload( $field ): bool {
		$is_upload = 'fileupload' === self::prop( $field, 'type' ) || 'fileupload' === self::prop( $field, 'inputType' );
		return $is_upload && in_array( strtolower( self::prop( $field, 'visibility' ) ), self::HIDDEN, true );
	}

	/**
	 * The hidden upload fields on one form.
	 *
	 * @param mixed $form A Gravity Forms form array.
	 * @return array<int, array{field_id:int, field_label:string}>
	 */
	public static function hidden_fields( $form ): array {
		$out = array();
		if ( ! is_array( $form ) || empty( $form['fields'] ) || ! is_iterable( $form['fields'] ) ) {
			return $out;
		}
		foreach ( $form['fields'] as $field ) {
			if ( self::is_hidden_upload( $field ) ) {
				$label = self::prop( $field, 'label' );
				$out[] = array(
					'field_id'    => (int) self::prop( $field, 'id' ),
					'field_label' => '' !== $label ? $label : 'File Upload', // Untranslated: this can run before translations load.
				);
			}
		}
		return $out;
	}

	/**
	 * Every hidden upload field on the site's active forms. Cached six hours.
	 *
	 * @return array<int, array{form_id:int, form_title:string, field_id:int, field_label:string}>
	 */
	public static function find(): array {
		if ( ! class_exists( 'GFAPI' ) ) {
			return array();
		}
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$found = array();
		$forms = GFAPI::get_forms();
		foreach ( is_array( $forms ) ? $forms : array() as $form ) {
			foreach ( self::hidden_fields( $form ) as $f ) {
				$found[] = array(
					'form_id'     => (int) ( $form['id'] ?? 0 ),
					'form_title'  => (string) ( $form['title'] ?? '' ),
					'field_id'    => $f['field_id'],
					'field_label' => $f['field_label'],
				);
			}
		}
		set_transient( self::CACHE, $found, 6 * HOUR_IN_SECONDS );
		return $found;
	}

	/** One form, fresh from Gravity Forms (never the cache, so a block always sees the current setting). */
	private static function form( int $form_id ) {
		if ( $form_id <= 0 || ! class_exists( 'GFAPI' ) ) {
			return false;
		}
		$form = GFAPI::get_form( $form_id );
		return is_array( $form ) ? $form : false;
	}

	/** Logged-in staff who manage entries are trusted. Call only once the current user can be read. */
	private static function exempt(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( 'gravityforms_edit_entries' );
	}

	/* ---------------------------------------------------------------- */
	/* Block: regular submit                                             */
	/* ---------------------------------------------------------------- */

	/**
	 * Drop any file sent to a hidden upload field before Gravity Forms reads
	 * the submission. The rest of the entry goes through untouched.
	 */
	public static function guard_submission(): void {
		if ( empty( $_POST['gform_submit'] ) || ! self::enabled() ) {
			return;
		}
		$form = self::form( absint( wp_unslash( $_POST['gform_submit'] ) ) );
		if ( ! $form ) {
			return;
		}
		$hidden = self::hidden_fields( $form );
		if ( ! $hidden || self::exempt() ) {
			return;
		}
		if ( self::strip( $hidden ) ) {
			Scout_Forms_Guard::record_block( $form );
		}
	}

	/**
	 * Remove the hidden fields' files from $_FILES and gform_uploaded_files.
	 *
	 * @param array<int, array{field_id:int}> $hidden
	 * @return bool Whether anything was removed.
	 */
	public static function strip( array $hidden ): bool {
		$removed = false;
		$keys    = array();
		foreach ( $hidden as $f ) {
			$keys[] = 'input_' . (int) $f['field_id'];
		}

		foreach ( $keys as $key ) {
			if ( isset( $_FILES[ $key ] ) ) {
				unset( $_FILES[ $key ] );
				$removed = true;
			}
		}

		if ( isset( $_POST['gform_uploaded_files'] ) && is_string( $_POST['gform_uploaded_files'] ) ) {
			// $_POST is slashed by WordPress before init.
			$raw  = wp_unslash( $_POST['gform_uploaded_files'] );
			$list = json_decode( $raw, true );
			if ( is_array( $list ) ) {
				$before = count( $list );
				foreach ( $keys as $key ) {
					unset( $list[ $key ] );
				}
				if ( count( $list ) !== $before ) {
					$removed = true;
					// An empty list goes back as {} so Gravity Forms still reads an object.
					$json                             = wp_slash( (string) wp_json_encode( $list ? $list : new stdClass() ) );
					$_POST['gform_uploaded_files']    = $json;
					$_REQUEST['gform_uploaded_files'] = $json;
				}
			} else {
				// Unreadable JSON that names a hidden field: drop the whole list rather than guess.
				foreach ( $keys as $key ) {
					if ( false !== strpos( (string) $raw, '"' . $key . '"' ) ) {
						$_POST['gform_uploaded_files']    = '';
						$_REQUEST['gform_uploaded_files'] = '';
						$removed                          = true;
						break;
					}
				}
			}
		}
		return $removed;
	}

	/* ---------------------------------------------------------------- */
	/* Block: drag-and-drop upload endpoint                              */
	/* ---------------------------------------------------------------- */

	/**
	 * Refuse ?gf_page=upload requests aimed at a hidden upload field.
	 *
	 * The current user: wp-settings.php loads pluggable.php before it fires
	 * plugins_loaded, so current_user_can() works here, but WordPress only
	 * sets the user up on its own just before init. Calling it earlier makes
	 * WordPress read the login cookie now, which is safe (core's cookie and
	 * application password checks are registered in default-filters.php) but
	 * runs before any plugin that adds its own login method on init. So the
	 * user is read only in the rare case that would otherwise be refused.
	 * If a site uses such a login plugin, its staff get refused too, which is
	 * the safe direction.
	 */
	public static function guard_upload_endpoint(): void {
		if ( ! isset( $_GET['gf_page'] ) || 'upload' !== $_GET['gf_page'] ) {
			return;
		}
		if ( ! class_exists( 'GFAPI' ) ) {
			// Gravity Forms is not loaded yet or not active. Try again at the
			// earliest point after this, in case it loads late.
			if ( ! did_action( 'init' ) ) {
				add_action( 'init', array( __CLASS__, 'guard_upload_endpoint' ), 0 );
			}
			return;
		}
		if ( ! self::enabled() ) {
			return;
		}
		if ( self::refuse_upload( $_REQUEST ) && ! self::exempt() ) {
			self::deny();
		}
	}

	/**
	 * Should this upload request be refused? A decision only: counting and the
	 * 403 happen in deny(), and the logged-in exemption in the caller.
	 *
	 * @param array $request $_REQUEST (form_id, field_id).
	 */
	public static function refuse_upload( array $request ): bool {
		if ( empty( $request['form_id'] ) ) {
			return false;
		}
		$form = self::form( absint( $request['form_id'] ) );
		if ( ! $form ) {
			return false;
		}
		$hidden = self::hidden_fields( $form );
		if ( ! $hidden ) {
			return false;
		}
		$field_id = isset( $request['field_id'] ) && '' !== (string) $request['field_id'] ? absint( $request['field_id'] ) : 0;
		if ( ! $field_id ) {
			return true; // No field named, and this form has a hidden upload field: refuse.
		}
		foreach ( $hidden as $f ) {
			if ( $f['field_id'] === $field_id ) {
				return true;
			}
		}
		return false;
	}

	/** 403 in the JSON shape Gravity Forms' upload script reads, then stop. */
	private static function deny(): void {
		$form = self::form( absint( $_REQUEST['form_id'] ?? 0 ) );
		Scout_Forms_Guard::record_block( $form ? $form : array() );
		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		echo wp_json_encode(
			array(
				'status' => 'error',
				'error'  => array(
					'code'    => 403,
					'message' => 'This upload is not allowed.',
				),
			)
		);
		exit;
	}

	/* ---------------------------------------------------------------- */
	/* Warn                                                              */
	/* ---------------------------------------------------------------- */

	/** Link to a form in the Gravity Forms editor. */
	public static function edit_url( int $form_id ): string {
		return admin_url( 'admin.php?page=gf_edit_forms&id=' . $form_id );
	}

	/** Red notice for site admins naming each form and field to fix. */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$found = self::find();
		if ( ! $found ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Fix your forms: a hidden file upload field is a security risk.', 'scout-forms-guard' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Gravity Forms has a reported security flaw, with no fix yet, that can let a stranger put a harmful file on this site through any File Upload field set to Hidden. These fields need fixing:', 'scout-forms-guard' ) . '</p><ul style="list-style:disc;margin-left:20px">';
		foreach ( $found as $f ) {
			echo '<li><a href="' . esc_url( self::edit_url( $f['form_id'] ) ) . '">' . esc_html( $f['form_title'] ) . '</a>: ' . esc_html( $f['field_label'] ) . ' ' . esc_html( sprintf( /* translators: %d: field id */ __( '(field %d)', 'scout-forms-guard' ), $f['field_id'] ) ) . '</li>';
		}
		echo '</ul><p>' . esc_html__( 'To fix one, open the form, click the field, and in its Advanced settings set Visibility to Visible. If the form does not need the field, delete it instead. Save the form, and this message goes away.', 'scout-forms-guard' ) . '</p>';
		echo '<p>' . ( self::enabled()
			? esc_html__( 'Until then, Scout Forms Guard refuses any file sent to these fields.', 'scout-forms-guard' )
			: esc_html__( 'Scout Forms Guard\'s upload blocking is switched off on this site, so these fields are open until they are fixed.', 'scout-forms-guard' ) ) . '</p></div>';
	}

	/**
	 * Health check in the shape Scout Build shows (label, value, state, note,
	 * link, link_text). state is good | warn | bad.
	 */
	public static function check(): array {
		$label = __( 'Hidden upload fields', 'scout-forms-guard' );
		if ( ! class_exists( 'GFAPI' ) ) {
			return array(
				'label' => $label,
				'value' => __( 'No forms', 'scout-forms-guard' ),
				'state' => 'good',
				'note'  => __( 'Gravity Forms is not active, so there is nothing to check.', 'scout-forms-guard' ),
			);
		}
		$found = self::find();
		if ( ! $found ) {
			return array(
				'label' => $label,
				'value' => __( 'None', 'scout-forms-guard' ),
				'state' => 'good',
				'note'  => __( 'No form has a File Upload field set to Hidden.', 'scout-forms-guard' ),
			);
		}
		$titles = array();
		foreach ( $found as $f ) {
			$titles[ $f['form_id'] ] = $f['form_title'];
		}
		$note = sprintf(
			/* translators: %s: form names */
			__( 'A File Upload field set to Hidden is open to a reported Gravity Forms security flaw. Fix: %s. Set the field\'s Visibility to Visible, or delete it.', 'scout-forms-guard' ),
			implode( ', ', $titles )
		);
		$note .= ' ' . ( self::enabled()
			? __( 'Scout Forms Guard refuses files sent to it until then.', 'scout-forms-guard' )
			: __( 'Upload blocking is switched off, so the field is open.', 'scout-forms-guard' ) );
		return array(
			'label'     => $label,
			/* translators: %d: number of fields */
			'value'     => sprintf( _n( '%d field', '%d fields', count( $found ), 'scout-forms-guard' ), count( $found ) ),
			'state'     => 'bad',
			'note'      => $note,
			'link'      => self::edit_url( (int) $found[0]['form_id'] ),
			'link_text' => __( 'Open the form', 'scout-forms-guard' ),
		);
	}
}
