<?php
/**
 * Site Sync screen, notices and health check.
 *
 * The screen is the Scout menu's "Site Sync" tab: counts at the top, then every
 * row that is not already current, with what WordPress holds next to what the
 * theme ships. Looking never changes anything; every change is a button.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Sync_Admin {

	const ACTION = 'scout_sync';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'scout_core_dashboard_checks', array( __CLASS__, 'check' ), 20 );
	}

	public static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=scout&tab=sync' ) );
	}

	/* ---------- Notice and health check ---------- */

	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) || ( isset( $_GET['tab'] ) && 'sync' === $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$summary = Scout_Sync::summary();
		$auto    = get_transient( 'scout_sync_last_auto' );
		if ( is_array( $auto ) && $auto['written'] > 0 ) {
			delete_transient( 'scout_sync_last_auto' );
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>Site Sync:</strong> %s <a href="%s">See what changed</a>.</p></div>',
				esc_html( sprintf( _n( 'the theme update brought %d change to the site.', 'the theme update brought %d changes to the site.', $auto['written'], 'scout-core' ), $auto['written'] ) ),
				esc_url( self::url() )
			);
		}
		if ( $summary['review'] > 0 ) {
			printf(
				'<div class="notice notice-warning"><p><strong>Site Sync:</strong> %s <a href="%s">Review</a>.</p></div>',
				esc_html( sprintf( _n( '%d item needs a look before it can sync.', '%d items need a look before they can sync.', $summary['review'], 'scout-core' ), $summary['review'] ) ),
				esc_url( self::url() )
			);
		}
	}

	public static function check( array $checks ) {
		$summary = Scout_Sync::summary();
		if ( ! $summary['manifest'] ) {
			return $checks;
		}
		$checks[] = array(
			'label'        => 'Site Sync',
			'status'       => $summary['review'] ? 'warn' : 'good',
			'message'      => $summary['review']
				? sprintf( _n( '%d item needs a look before it can sync.', '%d items need a look before they can sync.', $summary['review'], 'scout-core' ), $summary['review'] )
				: 'The site matches the copy in the theme, apart from edits you chose to keep.',
			'action'       => self::url(),
			'action_label' => $summary['review'] ? 'Review Site Sync' : 'Open Site Sync',
		);
		return $checks;
	}

	/* ---------- Screen ---------- */

	public static function render() {
		$plan  = Scout_Sync::plan();
		$state = array();
		foreach ( $plan['rows'] as $row ) {
			$state[ $row['state'] ][] = $row;
		}
		Scout_Sync::summary( true );

		self::flash();

		if ( ! $plan['manifest'] ) {
			echo '<section class="sh-box"><h2>This theme does not bundle any copy yet</h2>';
			echo '<p>Site Sync keeps pages in step with the copy a theme ships in its <code>scout-sync.json</code> file. This theme has none, so there is nothing to sync and nothing will change.</p></section>';
			return;
		}

		$counts = array(
			Scout_Sync::CURRENT  => array( 'Up to date', 'good' ),
			Scout_Sync::UPDATE   => array( 'Ready to update', 'good' ),
			Scout_Sync::EDITED   => array( 'Edited in WordPress', 'warn' ),
			Scout_Sync::CONFLICT => array( 'Needs a decision', 'warn' ),
			Scout_Sync::MISSING  => array( 'Page not found', 'bad' ),
		);
		echo '<div class="sh-grid sh-grid-3">';
		foreach ( $counts as $key => $meta ) {
			$n = count( $state[ $key ] ?? array() );
			if ( ! $n && in_array( $key, array( Scout_Sync::MISSING, Scout_Sync::CONFLICT ), true ) ) {
				continue;
			}
			printf(
				'<section class="sh-box sh-check is-%s"><p class="sh-label">%s</p><p class="sh-value">%d</p></section>',
				esc_attr( $n && 'good' !== $meta[1] ? $meta[1] : 'good' ),
				esc_html( $meta[0] ),
				(int) $n
			);
		}
		echo '</div>';

		foreach ( $plan['errors'] as $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}

		$updates = $state[ Scout_Sync::UPDATE ] ?? array();
		if ( $updates ) {
			echo '<section class="sh-box"><h2>' . esc_html( sprintf( _n( '%d update from the theme', '%d updates from the theme', count( $updates ), 'scout-core' ), count( $updates ) ) ) . '</h2>';
			echo '<p>Nobody has edited these in WordPress since the last sync, so the theme copy can go straight in. Each page keeps a revision, and you can undo the whole sync below.</p>';
			self::rows( $updates, false );
			self::form( 'safe', $plan['hash'], 'Sync these now' );
			echo '</section>';
		}

		$review = array_merge( $state[ Scout_Sync::EDITED ] ?? array(), $state[ Scout_Sync::CONFLICT ] ?? array() );
		if ( $review ) {
			echo '<section class="sh-box"><h2>Changes made in WordPress</h2>';
			echo '<p>These differ from the theme copy. Site Sync never overwrites them on its own. Tick the ones to decide on, then choose. "Keep the WordPress copy" also means the edit should go back into the client repo so the next theme carries it.</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			self::rows( $review, true );
			self::hidden( 'pick', $plan['hash'] );
			echo '<p class="sh-actions"><button class="button button-primary" name="choice" value="keep">Keep the WordPress copy</button> <button class="button" name="choice" value="take">Use the theme copy</button></p>';
			echo '</form></section>';
		}

		$missing = $state[ Scout_Sync::MISSING ] ?? array();
		if ( $missing ) {
			echo '<section class="sh-box"><h2>Pages the theme names but the site does not have</h2><p>Create them, or run the theme\'s setup, then come back.</p><ul class="sh-list">';
			foreach ( $missing as $row ) {
				echo '<li>' . esc_html( Scout_Sync::label( $row ) ) . '</li>';
			}
			echo '</ul></section>';
		}

		if ( ! $updates && ! $review && ! $missing && ! $plan['errors'] ) {
			echo '<section class="sh-box"><h2>Everything is in sync</h2><p>Every page matches the copy in this theme. When a theme update changes the copy, the untouched pages update on their own.</p></section>';
		}

		$journal = get_option( Scout_Sync::JOURNAL, array() );
		if ( in_array( $journal['state'] ?? '', array( 'applied', 'failed', 'applying' ), true ) ) {
			echo '<section class="sh-box"><h2>Last sync</h2>';
			printf(
				'<p>%s, %s. %s</p>',
				esc_html( 'auto' === ( $journal['who'] ?? '' ) ? 'Automatic, after a theme update' : 'Run by hand' ),
				esc_html( wp_date( 'M j, g:i a', strtotime( $journal['time'] ) ) ),
				esc_html( sprintf( _n( '%d item changed.', '%d items changed.', count( $journal['rows'] ), 'scout-core' ), count( $journal['rows'] ) ) )
			);
			self::form( 'undo', '', 'Undo the last sync', 'button' );
			echo '</section>';
		}
	}

	private static function rows( array $rows, $pick ) {
		echo '<table class="widefat striped"><thead><tr>';
		if ( $pick ) {
			echo '<td class="check-column"></td>';
		}
		echo '<th>Item</th><th>Now in WordPress</th><th>In the theme</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			if ( $pick ) {
				printf( '<th class="check-column"><input type="checkbox" name="ids[]" value="%s"></th>', esc_attr( $row['id'] ) );
			}
			printf(
				'<td><strong>%s</strong>%s</td><td>%s</td><td>%s</td>',
				esc_html( Scout_Sync::label( $row ) ),
				Scout_Sync::CONFLICT === $row['state'] ? '<br><span class="sh-tag">' . esc_html( empty( $row['synced'] ) ? 'Never synced, and different' : 'Changed in both' ) . '</span>' : '',
				esc_html( self::preview( $row, $row['raw'] ?? '' ) ),
				esc_html( self::preview( $row, $row['theme'] ) )
			);
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function preview( array $row, $text ) {
		if ( ! empty( $row['image'] ) ) {
			return $text === $row['theme'] ? 'Image: ' . wp_basename( (string) $row['image'] ) : ( $text ? 'Image #' . (int) $text : 'No image' );
		}
		$plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
		if ( '' === $plain ) {
			return '(empty)';
		}
		return mb_strlen( $plain ) > 220 ? mb_substr( $plain, 0, 220 ) . '...' : $plain;
	}

	private static function hidden( $step, $hash ) {
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="step" value="' . esc_attr( $step ) . '">';
		echo '<input type="hidden" name="review" value="' . esc_attr( $hash ) . '">';
		wp_nonce_field( self::ACTION );
	}

	private static function form( $step, $hash, $label, $class = 'button button-primary' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden( $step, $hash );
		echo '<p class="sh-actions"><button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></p></form>';
	}

	private static function flash() {
		$msg = get_transient( 'scout_sync_flash_' . get_current_user_id() );
		if ( ! $msg ) {
			return;
		}
		delete_transient( 'scout_sync_flash_' . get_current_user_id() );
		printf( '<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr( $msg['type'] ), esc_html( $msg['text'] ) );
	}

	/* ---------- Actions ---------- */

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$step   = sanitize_key( wp_unslash( $_POST['step'] ?? '' ) );
		$review = sanitize_text_field( wp_unslash( $_POST['review'] ?? '' ) );
		$ids    = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['ids'] ?? array() ) );
		$choice = sanitize_key( wp_unslash( $_POST['choice'] ?? '' ) );

		if ( 'undo' === $step ) {
			$result = Scout_Sync::undo();
			$text   = is_wp_error( $result ) ? $result->get_error_message() : sprintf( 'Undone: %d put back.', $result['restored'] ) . ( $result['skipped'] ? ' Left alone because they changed since: ' . implode( ', ', $result['skipped'] ) . '.' : '' );
		} elseif ( 'pick' === $step ) {
			if ( ! $ids ) {
				$result = new WP_Error( 'none', 'Tick at least one item first.' );
			} else {
				$result = Scout_Sync::apply( $review, 'take' === $choice ? 'take' : 'keep', $ids );
			}
			$text = is_wp_error( $result ) ? $result->get_error_message() : ( 'take' === $choice ? sprintf( 'Theme copy used for %d item(s).', $result['written'] ) : 'Kept the WordPress copy. Bring the edit back into the client repo so the next theme carries it.' );
		} else {
			$result = Scout_Sync::apply( $review, 'safe' );
			$text   = is_wp_error( $result ) ? $result->get_error_message() : sprintf( 'Synced %d item(s). Purge the hosting cache to see them live.', $result['written'] );
		}
		set_transient( 'scout_sync_flash_' . get_current_user_id(), array( 'type' => is_wp_error( $result ) ? 'error' : 'success', 'text' => $text ), 300 );
		wp_safe_redirect( self::url() );
		exit;
	}
}
