<?php
/**
 * Site Sync: the Scout > Site Sync tab. Status, the freshness table, and the
 * buttons. Every action posts to admin-post.php and redirects back, so a page
 * refresh never repeats a write.
 *
 * Phase 1: everything is manual. "Sync safe pages" only touches pages the
 * engine can prove nobody edited in WordPress; the rest wait for a person.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Sync_Admin {

	const ACTION = 'scout_sync';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_filter( 'scout_core_dashboard_checks', array( __CLASS__, 'dashboard_check' ) );
	}

	private static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'scout', 'tab' => 'sync' ), $args ), admin_url( 'admin.php' ) );
	}

	/** Plain-language label and tone for each state. */
	private static function states() {
		return array(
			Scout_Core_Sync_Engine::IN_SYNC        => array( 'In sync', 'good', 'WordPress matches this theme version.' ),
			Scout_Core_Sync_Engine::MISSING        => array( 'Not in WordPress yet', 'ready', 'Sync safe pages creates it from the theme copy.' ),
			Scout_Core_Sync_Engine::STALE          => array( 'Update ready', 'ready', 'The theme has newer copy and nobody edited this page in WordPress, so it is safe to sync.' ),
			Scout_Core_Sync_Engine::UNMANAGED_SAME => array( 'Matches, not tracked yet', 'ready', 'Already identical to the theme. Sync safe pages starts tracking it without writing anything.' ),
			Scout_Core_Sync_Engine::EDITED         => array( 'Edited in WordPress', 'warn', 'Someone changed this page in WordPress. Copy that change into the repo, or take the theme copy.' ),
			Scout_Core_Sync_Engine::CONFLICT       => array( 'Both changed', 'warn', 'The theme has new copy and the page was also edited in WordPress. Compare before choosing.' ),
			Scout_Core_Sync_Engine::UNMANAGED      => array( 'Not tracked, differs', 'warn', 'This page was built before Site Sync and does not match the theme. Compare, then pick a version.' ),
			Scout_Core_Sync_Engine::NO_SOURCE      => array( 'Theme copy missing', 'warn', 'The manifest names a file the theme does not ship. Fix it in the repo.' ),
		);
	}

	/* ---------- Render ---------- */

	public static function render() {
		self::styles();
		self::notice();

		if ( isset( $_GET['view'], $_GET['entry'] ) && 'diff' === $_GET['view'] ) {
			self::render_diff( sanitize_text_field( wp_unslash( $_GET['entry'] ) ) );
			return;
		}

		$theme = wp_get_theme();
		echo '<p class="scout-lead">Site Sync keeps each page in WordPress matched to the copy bundled in the theme. Copy changes are made in the repo and arrive with a theme update. Pages edited here in WordPress are never overwritten without you choosing to.</p>';

		if ( ! Scout_Core_Sync_Manifest::exists() ) {
			echo '<div class="scout-hero"><h2>Status only</h2>';
			printf(
				'<p>%s %s does not ship a <code>%s</code> file yet, so there is nothing to compare. Nothing on the site is affected.</p></div>',
				esc_html( $theme->get( 'Name' ) ),
				esc_html( $theme->get( 'Version' ) ),
				esc_html( Scout_Core_Sync_Manifest::FILE )
			);
			return;
		}

		$manifest = Scout_Core_Sync_Manifest::get();
		if ( ! $manifest['ok'] ) {
			echo '<div class="scout-hero warn"><h2>The sync file cannot be read</h2><ul class="scout-sync-errors">';
			foreach ( $manifest['errors'] as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
			return;
		}

		$rows   = Scout_Core_Sync_Engine::status();
		$counts = array( 'good' => 0, 'ready' => 0, 'warn' => 0 );
		$states = self::states();
		foreach ( $rows as $row ) {
			$counts[ $states[ $row['state'] ][1] ]++;
		}

		$tone = $counts['warn'] ? 'warn' : ( $counts['ready'] ? 'ready' : 'good' );
		echo '<div class="scout-hero ' . esc_attr( 'good' === $tone ? 'good' : 'warn' ) . '">';
		if ( $counts['warn'] ) {
			echo '<h2>' . esc_html( sprintf( _n( '%d page needs review', '%d pages need review', $counts['warn'], 'scout-core' ), $counts['warn'] ) ) . '</h2>';
		} elseif ( $counts['ready'] ) {
			echo '<h2>' . esc_html( sprintf( _n( '%d page is ready to sync', '%d pages are ready to sync', $counts['ready'], 'scout-core' ), $counts['ready'] ) ) . '</h2>';
		} else {
			echo '<h2>Every page matches the theme</h2>';
		}
		printf(
			'<p>Theme %s %s. %d pages in sync, %d ready, %d to review.</p></div>',
			esc_html( $theme->get( 'Name' ) ),
			esc_html( $theme->get( 'Version' ) ),
			(int) $counts['good'],
			(int) $counts['ready'],
			(int) $counts['warn']
		);

		if ( $manifest['errors'] ) {
			echo '<div class="notice notice-warning inline"><p><strong>Sync file warnings</strong></p><ul class="scout-sync-errors">';
			foreach ( $manifest['errors'] as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
		}

		echo '<div class="scout-sync-bar">';
		self::button( 'sync_safe', 'Sync safe pages', '', 'button-primary', $counts['ready'] ? '' : 'disabled' );
		echo '<span class="description">Creates missing pages and updates pages nobody edited in WordPress. Pages marked for review are left alone.</span>';
		echo '</div>';

		echo '<h2 class="scout-sync-h">Pages</h2>';
		echo '<table class="widefat striped scout-sync-table"><thead><tr><th>Page</th><th>State</th><th>Last synced</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $rows as $slug => $row ) {
			list( $label, $class, $help ) = $states[ $row['state'] ];
			echo '<tr><td>';
			if ( $row['post_id'] ) {
				printf( '<a href="%s"><strong>%s</strong></a>', esc_url( get_edit_post_link( $row['post_id'] ) ), esc_html( $row['entry']['title'] ) );
			} else {
				echo '<strong>' . esc_html( $row['entry']['title'] ) . '</strong>';
			}
			echo '<br><code>' . esc_html( $slug ) . '</code></td>';
			printf( '<td><span class="scout-pill %s">%s</span><p class="description">%s</p></td>', esc_attr( $class ), esc_html( $label ), esc_html( $help ) );
			echo '<td>' . ( empty( $row['base'] ) ? '&mdash;' : esc_html( sprintf( '%s ago, theme %s', human_time_diff( (int) $row['base']['at'] ), $row['base']['version'] ) ) ) . '</td>';
			echo '<td class="scout-sync-actions">';
			self::row_actions( $row );
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		self::render_extras( $manifest );
	}

	private static function row_actions( array $row, $compare = true ) {
		$slug  = $row['entry']['slug'];
		$state = $row['state'];
		$diff  = $compare && in_array( $state, array( Scout_Core_Sync_Engine::STALE, Scout_Core_Sync_Engine::EDITED, Scout_Core_Sync_Engine::CONFLICT, Scout_Core_Sync_Engine::UNMANAGED ), true );
		if ( $diff ) {
			printf( '<a class="button" href="%s">Compare</a> ', esc_url( self::url( array( 'view' => 'diff', 'entry' => $slug ) ) ) );
		}
		if ( in_array( $state, array( Scout_Core_Sync_Engine::EDITED, Scout_Core_Sync_Engine::CONFLICT, Scout_Core_Sync_Engine::UNMANAGED ), true ) ) {
			self::button( 'take_theme', 'Take theme copy', $slug, '', '', 'Replace this page with the theme copy? What it holds now is saved, and Undo puts it back.' );
			self::button( 'keep_wp', 'Keep WordPress copy', $slug, '', '', 'Keep this page as it is and stop flagging it? Copy the change into the repo too, or the next theme update will flag it again.' );
		}
		if ( $row['snapshot'] ) {
			self::button( 'undo', 'Undo last sync', $slug, 'button-link', '', 'Put back what this page held before the last sync?' );
		}
	}

	private static function render_extras( array $manifest ) {
		$identity = Scout_Core_Sync_Engine::identity_diff();
		$seo      = Scout_Core_Sync_Engine::seo( false );
		$seeds    = Scout_Core_Sync_Engine::seeds( false );

		if ( $manifest['identity'] ) {
			echo '<h2 class="scout-sync-h">Site identity</h2>';
			if ( $identity ) {
				echo '<table class="widefat striped scout-sync-small"><thead><tr><th>Setting</th><th>Now</th><th>Theme</th></tr></thead><tbody>';
				foreach ( $identity as $label => $pair ) {
					printf( '<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $label ), esc_html( '' === $pair[0] ? '(blank)' : $pair[0] ), esc_html( $pair[1] ) );
				}
				echo '</tbody></table><p>';
				self::button( 'identity', 'Apply site identity', '', '', '', 'Set these to the theme values? A logo or icon is only added when the site has none.' );
				echo '</p>';
			} else {
				echo '<p>Title, tagline, front page, posts page, logo, and icon all match the theme.</p>';
			}
		}

		if ( '' !== $manifest['seo'] ) {
			echo '<h2 class="scout-sync-h">Search descriptions</h2>';
			printf(
				'<p>%d pages have a title and description in the theme. %d of those fields are blank in WordPress.%s</p>',
				(int) $seo['rows'],
				(int) $seo['blank'],
				$seo['missing_pages'] ? esc_html( ' No page yet for: ' . implode( ', ', $seo['missing_pages'] ) . '.' ) : ''
			);
			if ( $seo['blank'] ) {
				echo '<p>';
				self::button( 'seo', 'Fill blank search descriptions', '' );
				echo ' <span class="description">Only blanks. Anything written in a Scout SEO box stays as it is.</span></p>';
			}
		}

		if ( $seeds ) {
			echo '<h2 class="scout-sync-h">Starter records</h2>';
			echo '<table class="widefat striped scout-sync-small"><thead><tr><th>Type</th><th>In theme</th><th>Already here</th></tr></thead><tbody>';
			$addable = 0;
			foreach ( $seeds as $line ) {
				$addable += $line['exists'] ? $line['total'] - $line['present'] : 0;
				printf(
					'<tr><td>%s</td><td>%d</td><td>%s</td></tr>',
					esc_html( $line['type'] ),
					(int) $line['total'],
					$line['exists'] ? (int) $line['present'] : 'post type not registered'
				);
			}
			echo '</tbody></table>';
			if ( $addable > 0 ) {
				echo '<p>';
				self::button( 'seed', 'Add missing records', '' );
				echo ' <span class="description">Adds only records whose title is not already used. Never edits existing ones.</span></p>';
			}
		}
	}

	private static function render_diff( $slug ) {
		$manifest = Scout_Core_Sync_Manifest::get();
		if ( ! isset( $manifest['pages'][ $slug ] ) ) {
			echo '<p>That page is not in the sync file.</p>';
			return;
		}
		$entry = $manifest['pages'][ $slug ];
		$row   = Scout_Core_Sync_Engine::page_status( $entry );
		$page  = Scout_Core_Sync_Engine::find_page( $slug );
		$now   = $page ? $page->post_content : '';
		$theme = Scout_Core_Sync_Manifest::read( $entry['path'] );

		printf( '<p><a href="%s">&lsaquo; All pages</a></p>', esc_url( self::url() ) );
		printf( '<h2 class="scout-sync-h">%s <code>%s</code></h2>', esc_html( $entry['title'] ), esc_html( $slug ) );
		$diff = self::diff( $now, $theme );
		echo $diff ? $diff : '<p>No differences in the text.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the core diff renderer escapes every line.
		echo '<p class="scout-sync-bar">';
		self::row_actions( $row, false );
		echo '</p>';
	}

	/**
	 * Side-by-side diff showing only the changed lines and two lines around
	 * each. wp_text_diff() keeps 10,000 lines of context, which prints the
	 * whole page for a one-word change.
	 */
	private static function diff( $left, $right ) {
		if ( ! class_exists( 'WP_Text_Diff_Renderer_Table', false ) ) {
			require_once ABSPATH . WPINC . '/wp-diff.php';
		}
		$norm = static function ( $text ) {
			return explode( "\n", trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) ) );
		};
		$renderer = new WP_Text_Diff_Renderer_Table( array( 'show_split_view' => true ) );
		$renderer->_leading_context_lines  = 2;
		$renderer->_trailing_context_lines = 2;
		$rows = $renderer->render( new Text_Diff( $norm( $left ), $norm( $right ) ) );
		if ( '' === trim( (string) $rows ) ) {
			return '';
		}
		return '<table class="diff is-split-view"><colgroup><col class="left"><col class="right"></colgroup>'
			. '<thead><tr class="diff-sub-title"><th>In WordPress now</th><th>Theme copy</th></tr></thead>'
			. '<tbody>' . $rows . '</tbody></table>';
	}

	private static function button( $action, $label, $slug, $class = '', $disabled = '', $confirm = '' ) {
		printf(
			'<form method="post" action="%s" class="scout-sync-form"%s>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : ''
		);
		wp_nonce_field( self::ACTION );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::ACTION ) );
		printf( '<input type="hidden" name="do" value="%s">', esc_attr( $action ) );
		if ( '' !== $slug ) {
			printf( '<input type="hidden" name="entry" value="%s">', esc_attr( $slug ) );
		}
		printf( '<button type="submit" class="button %s"%s>%s</button></form>', esc_attr( $class ), $disabled ? ' disabled' : '', esc_html( $label ) );
	}

	/* ---------- Actions ---------- */

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to do that.', 403 );
		}
		check_admin_referer( self::ACTION );
		$do    = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$slug  = isset( $_POST['entry'] ) ? sanitize_text_field( wp_unslash( $_POST['entry'] ) ) : '';
		$pages = Scout_Core_Sync_Manifest::get()['pages'];
		$entry = isset( $pages[ $slug ] ) ? $pages[ $slug ] : null;
		$type  = 'success';
		$msg   = '';

		switch ( $do ) {
			case 'sync_safe':
				$r   = Scout_Core_Sync_Engine::sync_safe();
				$msg = sprintf( 'Created %d, updated %d, started tracking %d. %d left for review.', $r['created'], $r['synced'], $r['adopted'], $r['skipped'] );
				if ( $r['errors'] ) {
					$type = 'warning';
					$msg .= ' ' . implode( ' ', $r['errors'] );
				}
				if ( $r['created'] + $r['synced'] ) {
					$msg .= ' Purge the site cache, then check the pages.';
				}
				break;
			case 'take_theme':
				$r    = $entry ? Scout_Core_Sync_Engine::write_page( $entry ) : new WP_Error( 'x', 'That page is not in the sync file.' );
				$type = is_wp_error( $r ) ? 'error' : 'success';
				$msg  = is_wp_error( $r ) ? $r->get_error_message() : sprintf( '"%s" now matches the theme. Undo puts the old version back. Purge the site cache.', $slug );
				break;
			case 'keep_wp':
				$ok   = $entry && Scout_Core_Sync_Engine::adopt( $entry );
				$type = $ok ? 'success' : 'error';
				$msg  = $ok ? sprintf( '"%s" kept as it is. Copy the change into the repo so the next theme update carries it.', $slug ) : 'That page could not be found.';
				break;
			case 'undo':
				$r    = $entry ? Scout_Core_Sync_Engine::undo( $entry ) : new WP_Error( 'x', 'That page is not in the sync file.' );
				$type = is_wp_error( $r ) ? 'error' : 'success';
				$msg  = is_wp_error( $r ) ? $r->get_error_message() : sprintf( '"%s" is back to what it held before the last sync. Purge the site cache.', $slug );
				break;
			case 'identity':
				$changed = Scout_Core_Sync_Engine::apply_identity();
				$msg     = $changed ? 'Updated: ' . implode( ', ', $changed ) . '.' : 'Nothing to change.';
				break;
			case 'seo':
				$r   = Scout_Core_Sync_Engine::seo( true );
				$msg = sprintf( 'Filled %d blank search fields.', $r['filled'] );
				break;
			case 'seed':
				$added = 0;
				foreach ( Scout_Core_Sync_Engine::seeds( true ) as $line ) {
					$added += $line['added'];
				}
				$msg = sprintf( 'Added %d records.', $added );
				break;
			default:
				$type = 'error';
				$msg  = 'Unknown action.';
		}

		set_transient( 'scout_sync_notice_' . get_current_user_id(), array( $type, $msg ), 120 );
		wp_safe_redirect( self::url() );
		exit;
	}

	private static function notice() {
		$key  = 'scout_sync_notice_' . get_current_user_id();
		$note = get_transient( $key );
		if ( is_array( $note ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $note[0] ), esc_html( $note[1] ) );
		}
	}

	/* ---------- Dashboard card ---------- */

	/** One card on the Scout dashboard, only when the theme ships a manifest. */
	public static function dashboard_check( $checks ) {
		if ( ! Scout_Core_Sync_Manifest::exists() || ! Scout_Core_Sync_Manifest::get()['ok'] ) {
			return $checks;
		}
		$review = 0;
		$ready  = 0;
		foreach ( Scout_Core_Sync_Engine::status() as $row ) {
			if ( Scout_Core_Sync_Engine::needs_review( $row['state'] ) ) {
				$review++;
			} elseif ( Scout_Core_Sync_Engine::is_safe( $row['state'] ) ) {
				$ready++;
			}
		}
		if ( $review ) {
			$message = sprintf( _n( '%d page needs a look before it can match the theme.', '%d pages need a look before they can match the theme.', $review, 'scout-core' ), $review );
		} elseif ( $ready ) {
			$message = sprintf( _n( '%d page has an update from the theme ready to apply.', '%d pages have updates from the theme ready to apply.', $ready, 'scout-core' ), $ready );
		} else {
			$message = 'Every page matches the copy in this theme version.';
		}
		$checks[] = array(
			'label'        => 'Page content',
			'status'       => ( $review || $ready ) ? 'warn' : 'good',
			'message'      => $message,
			'action'       => ( $review || $ready ) ? self::url() : '',
			'action_label' => ( $review || $ready ) ? 'Open Site Sync' : '',
		);
		return $checks;
	}

	private static function styles() {
		echo '<style>
		.scout-sync-bar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:0 0 18px;max-width:1100px}
		.scout-sync-form{display:inline-block;margin:0 6px 6px 0}
		.scout-sync-h{margin:28px 0 10px;font-size:16px}
		.scout-sync-table{max-width:1100px}
		.scout-sync-small{max-width:640px}
		.scout-sync-table td{vertical-align:top}
		.scout-sync-table .description{margin:4px 0 0;max-width:46ch}
		.scout-sync-errors{margin:6px 0 0 18px;list-style:disc}
		.scout-pill{display:inline-block;padding:2px 10px;border-radius:980px;font-size:12px;font-weight:600;background:#e7f6f0;color:#0a6e50}
		.scout-pill.ready{background:#e6f2fc;color:#0A6FB4}
		.scout-pill.warn{background:#fdf3e1;color:#8a5a00}
		.scout-admin table.diff{width:100%;max-width:1100px;table-layout:fixed;background:#fff;border:1px solid #dcdcde;border-collapse:collapse}
		.scout-admin table.diff col.left,.scout-admin table.diff col.right{width:50%}
		.scout-admin table.diff td{padding:4px 8px;font:12px/1.5 Menlo,Consolas,monospace;white-space:pre-wrap;overflow-wrap:anywhere;vertical-align:top}
		.scout-admin table.diff th{padding:8px;text-align:left}
		@media (max-width:782px){.scout-sync-table th:nth-child(3),.scout-sync-table td:nth-child(3){display:none}}
		</style>';
	}
}
