<?php
/**
 * What happens around a person's page when it changes: a forward from the old
 * address when the address changes (through Scout Redirects, when it is on),
 * and one line in the Site log (through Scout Build, when it is on) when a
 * person goes live, is changed, or is taken off the site.
 *
 * Both are optional. Without those plugins nothing here runs, and WordPress
 * still forwards an old person address on its own.
 *
 * @package Scout_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Team_Changes {

	public static function boot(): void {
		add_action( 'post_updated', array( __CLASS__, 'address_changed' ), 10, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'log' ), 10, 3 );
	}

	/**
	 * A live person's address changed (/team/old/ to /team/new/): forward the
	 * old one with a 301, so links and search results keep working.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $after   The person after the save.
	 * @param WP_Post $before  The person before the save.
	 */
	public static function address_changed( $post_id, $after, $before ): void {
		unset( $post_id );
		if ( ! $after instanceof WP_Post || ! $before instanceof WP_Post || Scout_Team::POST_TYPE !== $after->post_type ) {
			return;
		}
		if ( 'publish' !== $after->post_status || 'publish' !== $before->post_status || $after->post_name === $before->post_name || '' === $before->post_name ) {
			return;
		}
		if ( ! class_exists( 'Scout_Redirects' ) || ! method_exists( 'Scout_Redirects', 'add' ) ) {
			return;
		}
		// The same "forward a page when its address changes" switch Scout Redirects uses for pages.
		if ( method_exists( 'Scout_Redirects', 'settings' ) && empty( Scout_Redirects::settings()['auto_pages'] ) ) {
			return;
		}
		$old = (string) get_permalink( $before );
		$new = (string) get_permalink( $after );
		if ( '' === $old || '' === $new || $old === $new ) {
			return;
		}
		if ( class_exists( 'Scout_Redirects_Store' ) && method_exists( 'Scout_Redirects', 'normalize_source' ) && method_exists( 'Scout_Redirects', 'normalize_target' ) ) {
			$new_path = Scout_Redirects::normalize_source( $new );
			$old_path = Scout_Redirects::normalize_source( $old );
			// The new address is live now, so nothing may forward it away.
			if ( '' !== $new_path && method_exists( 'Scout_Redirects_Store', 'delete_source' ) ) {
				Scout_Redirects_Store::delete_source( $new_path );
			}
			// Forwards that pointed at the old address go straight to the new one.
			if ( '' !== $old_path && method_exists( 'Scout_Redirects_Store', 'retarget' ) ) {
				Scout_Redirects_Store::retarget( $old_path, Scout_Redirects::normalize_target( $new ) );
			}
		}
		Scout_Redirects::add( $old, $new, 301, 'auto' );
	}

	/**
	 * One plain line in the Site log: a person went live, was changed, or was
	 * taken off the site.
	 *
	 * @param string  $new  New status.
	 * @param string  $old  Old status.
	 * @param WP_Post $post The person.
	 */
	public static function log( $new, $old, $post ): void {
		if ( ! $post instanceof WP_Post || Scout_Team::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post ) || ! class_exists( 'Scout_Hub_Log' ) || ! method_exists( 'Scout_Hub_Log', 'add' ) ) {
			return;
		}
		$title = self::title( (string) $new, (string) $old, wp_strip_all_tags( (string) $post->post_title ) );
		if ( '' === $title ) {
			return;
		}
		Scout_Hub_Log::add(
			array(
				'title'  => $title,
				'kind'   => 'content',
				'topic'  => 'content',
				'source' => 'Scout People',
				'ref'    => (string) get_edit_post_link( $post->ID, 'raw' ),
			)
		);
	}

	/**
	 * The Site log line for a status change, or '' when there is nothing to log.
	 *
	 * @param string $new  New status.
	 * @param string $old  Old status.
	 * @param string $name The person's name.
	 */
	public static function title( string $new, string $old, string $name ): string {
		$name = '' !== trim( $name ) ? $name : __( '(no name)', 'scout-team' );
		if ( 'publish' === $new && 'publish' !== $old ) {
			/* translators: %s: person's name. */
			return sprintf( __( 'Profile published: %s', 'scout-team' ), $name );
		}
		if ( 'publish' === $new ) {
			/* translators: %s: person's name. */
			return sprintf( __( 'Profile updated: %s', 'scout-team' ), $name );
		}
		if ( 'publish' === $old ) {
			/* translators: %s: person's name. */
			return sprintf( __( 'Profile taken off the site: %s', 'scout-team' ), $name );
		}
		return '';
	}
}
