<?php
/**
 * Change log screen: Scout → Change log. Only Scout sees it (Joy, 2026-10-27).
 *
 * New entries wait at the top until someone marks them as in the repo; marked
 * entries move to the Archive view and stay there.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Changelog_Admin {

	const ACTION = 'scout_change_log';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_filter( 'scout_core_dashboard_checks', array( __CLASS__, 'check' ), 25 );
	}

	/** Scout only: a client admin never sees the tab, the check or the actions. */
	public static function allowed(): bool {
		return current_user_can( 'manage_options' ) && Scout_Changelog::is_scout();
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=scout&tab=changes' ) );
	}

	public static function check( array $checks ) {
		if ( ! self::allowed() || ! Scout_Changelog::enabled() ) {
			return $checks;
		}
		$n        = Scout_Changelog::count_new();
		$checks[] = array(
			'label'        => 'Change log',
			'status'       => $n ? 'warn' : 'good',
			'message'      => $n
				? sprintf( _n( '%d change made on the site is not in the repo yet.', '%d changes made on the site are not in the repo yet.', $n, 'scout-core' ), $n )
				: 'Every change made on the site is in the repo.',
			'action'       => self::url(),
			'action_label' => $n ? 'Open the Change log' : 'See the archive',
		);
		return $checks;
	}

	public static function handle() {
		if ( ! self::allowed() ) {
			wp_die( 'Only Scout can change the Change log.' );
		}
		check_admin_referer( self::ACTION );
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		$msg  = '';
		if ( 'ack' === $step ) {
			$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
			if ( isset( $_POST['one'] ) ) {
				$ids = array( absint( wp_unslash( $_POST['one'] ) ) );
			}
			$n   = Scout_Changelog::acknowledge( $ids );
			$msg = sprintf( _n( '%d entry moved to the archive.', '%d entries moved to the archive.', $n, 'scout-core' ), $n );
		} elseif ( 'switch' === $step ) {
			$on = ! empty( $_POST['on'] );
			update_option( 'scout_change_log_switch', $on ? 'on' : 'off', false );
			$msg = $on ? 'The Change log is on.' : 'The Change log is off. Nothing new is recorded until you turn it back on.';
		} elseif ( 'digest' === $step ) {
			$msg = Scout_Changelog::send_digest() ? 'Sent this week\'s email now.' : 'Nothing new since the last email, so nothing was sent.';
		}
		set_transient( 'scout_change_log_msg_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( self::url( isset( $_POST['view'] ) ? array( 'view' => sanitize_key( wp_unslash( $_POST['view'] ) ) ) : array() ) );
		exit;
	}

	private static function form_open( string $step, string $view = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="step" value="' . esc_attr( $step ) . '">';
		if ( '' !== $view ) {
			echo '<input type="hidden" name="view" value="' . esc_attr( $view ) . '">';
		}
		wp_nonce_field( self::ACTION );
	}

	public static function render() {
		if ( ! self::allowed() ) {
			echo '<section class="sh-box"><p>This screen is for Scout.</p></section>';
			return;
		}
		$view = isset( $_GET['view'] ) && 'archive' === $_GET['view'] ? 'archive' : 'new'; // phpcs:ignore WordPress.Security.NonceVerification
		$msg  = get_transient( 'scout_change_log_msg_' . get_current_user_id() );
		if ( $msg ) {
			delete_transient( 'scout_change_log_msg_' . get_current_user_id() );
			echo '<div class="notice notice-success inline"><p>' . esc_html( $msg ) . '</p></div>';
		}
		$on  = Scout_Changelog::enabled();
		$new = Scout_Changelog::count_new();

		echo '<div class="sh-grid sh-grid-3">';
		printf( '<section class="sh-box sh-check is-%s"><p class="sh-label">Not in the repo yet</p><p class="sh-value">%d</p></section>', $new ? 'warn' : 'good', (int) $new );
		printf( '<section class="sh-box"><p class="sh-label">Weekly email</p><p>Mondays to %s, only when something changed.</p></section>', esc_html( Scout_Changelog::recipient() ) );
		printf( '<section class="sh-box sh-check is-%s"><p class="sh-label">Recording</p><p>%s</p></section>', $on ? 'good' : 'warn', $on ? 'On. Edits by anyone who isn\'t Scout are logged.' : 'Off. Nothing is being logged.' );
		echo '</div>';

		printf(
			'<p class="sh-actions"><a class="button%s" href="%s">New (%d)</a> <a class="button%s" href="%s">Archive</a></p>',
			'new' === $view ? ' button-primary' : '',
			esc_url( self::url() ),
			(int) $new,
			'archive' === $view ? ' button-primary' : '',
			esc_url( self::url( array( 'view' => 'archive' ) ) )
		);

		$rows = Scout_Changelog::entries( 'archive' === $view ? 'archived' : 'new', 'archive' === $view ? 300 : 500 );
		if ( 'new' === $view ) {
			self::render_new( $rows );
		} else {
			self::render_archive( $rows );
		}

		echo '<section class="sh-box"><h2>Settings</h2>';
		self::form_open( 'switch', $view );
		echo '<p><label><input type="checkbox" name="on" value="1"' . checked( $on, true, false ) . '> Record changes made by anyone who isn\'t Scout</label></p>';
		echo '<p class="sh-actions"><button class="button">Save</button></p></form>';
		self::form_open( 'digest', $view );
		echo '<p class="sh-actions"><button class="button">Send this week\'s email now</button></p></form>';
		echo '</section>';
	}

	private static function when( string $utc ): string {
		$ts = strtotime( $utc . ' UTC' );
		return wp_date( 'M j, g:i a', $ts ) . ' (' . human_time_diff( $ts ) . ' ago)';
	}

	private static function details( $r ): void {
		if ( '' === (string) $r->before_val && '' === (string) $r->after_val ) {
			return;
		}
		list( $b, $a ) = Scout_Changelog::diff( (string) $r->before_val, (string) $r->after_val );
		echo '<details><summary>See the change</summary><div class="scl-diff">';
		if ( '' !== $b ) {
			echo '<div><p class="sh-label">Before</p><pre>' . esc_html( $b ) . '</pre></div>';
		}
		if ( '' !== $a ) {
			echo '<div><p class="sh-label">After</p><pre>' . esc_html( $a ) . '</pre></div>';
		}
		echo '</div>';
		if ( $r->revision_id && get_post( (int) $r->revision_id ) ) {
			echo '<p><a href="' . esc_url( admin_url( 'revision.php?revision=' . (int) $r->revision_id ) ) . '">Compare in WordPress revisions</a></p>';
		}
		echo '</details>';
	}

	private static function where( $r ): string {
		if ( $r->object_id && post_type_exists( $r->object_type ) && get_post( (int) $r->object_id ) ) {
			return ' <a href="' . esc_url( (string) get_edit_post_link( (int) $r->object_id ) ) . '">Open</a>';
		}
		return '';
	}

	private static function render_new( array $rows ): void {
		if ( ! $rows ) {
			echo '<section class="sh-box"><h2>Nothing waiting</h2><p>Every change made on the site is in the repo. New edits by the client show up here.</p></section>';
			return;
		}
		self::styles();
		echo '<section class="sh-box"><h2>Changes to fold into the repo</h2>';
		echo '<p>Copy the list for Claude, fold the changes into the repo, then mark them. Marked entries move to the archive.</p>';
		echo '<textarea id="scl-export" class="screen-reader-text" readonly>' . esc_textarea( Scout_Changelog::export( $rows ) ) . '</textarea>';
		echo '<p class="sh-actions"><button type="button" class="button button-primary" id="scl-copy">Copy for Claude</button> <span id="scl-copied" aria-live="polite"></span></p>';

		self::form_open( 'ack' );
		echo '<table class="widefat striped scl-table"><thead><tr><td class="check-column"><input type="checkbox" id="scl-all" aria-label="Select all"></td><th>When</th><th>Who</th><th>What changed</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr>';
			echo '<th class="check-column"><input type="checkbox" name="ids[]" value="' . (int) $r->id . '" aria-label="Select"></th>';
			echo '<td>' . esc_html( self::when( $r->created_at ) ) . '</td>';
			echo '<td>' . esc_html( $r->user_label ) . '</td>';
			echo '<td>' . esc_html( $r->summary ) . self::where( $r ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			self::details( $r );
			echo '</td>';
			echo '<td><button class="button" name="one" value="' . (int) $r->id . '">In the repo</button></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="sh-actions"><button class="button button-primary">Mark selected as in the repo</button></p></form>';
		echo '</section>';
		?>
		<script>
		(function(){
			var b=document.getElementById('scl-copy'),t=document.getElementById('scl-export'),s=document.getElementById('scl-copied'),all=document.getElementById('scl-all');
			if(b&&t){b.addEventListener('click',function(){var done=function(){s.textContent='Copied. Paste it into Claude.';};if(navigator.clipboard){navigator.clipboard.writeText(t.value).then(done,function(){t.classList.remove('screen-reader-text');t.select();document.execCommand('copy');done();});}else{t.classList.remove('screen-reader-text');t.select();document.execCommand('copy');done();}});}
			if(all){all.addEventListener('change',function(){document.querySelectorAll('.scl-table tbody input[type=checkbox]').forEach(function(c){c.checked=all.checked;});});}
		})();
		</script>
		<?php
	}

	private static function render_archive( array $rows ): void {
		if ( ! $rows ) {
			echo '<section class="sh-box"><h2>The archive is empty</h2><p>Entries move here once they are marked as in the repo.</p></section>';
			return;
		}
		self::styles();
		echo '<section class="sh-box"><h2>Archive</h2><p>Changes already folded into the repo, newest first. Kept for the record.</p>';
		echo '<table class="widefat striped scl-table"><thead><tr><th>When</th><th>Who</th><th>What changed</th><th>Marked</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$by = $r->acked_by ? get_userdata( (int) $r->acked_by ) : null;
			echo '<tr><td>' . esc_html( self::when( $r->created_at ) ) . '</td><td>' . esc_html( $r->user_label ) . '</td><td>' . esc_html( $r->summary );
			self::details( $r );
			echo '</td><td>' . esc_html( ( $r->acked_at ? wp_date( 'M j', strtotime( $r->acked_at . ' UTC' ) ) : '' ) . ( $by ? ' by ' . $by->display_name : '' ) ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	private static function styles(): void {
		echo '<style>.scl-table td{vertical-align:top}.scl-table details{margin-top:6px}.scl-diff{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:8px}.scl-diff pre{white-space:pre-wrap;word-break:break-word;max-height:260px;overflow:auto;background:#f6f7f7;padding:8px;border-radius:8px;margin:4px 0 0;font-size:12px}@media (max-width:782px){.scl-diff{grid-template-columns:1fr}}</style>';
	}
}
