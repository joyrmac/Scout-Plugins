<?php
/**
 * The team member edit screen: a Profile box under the name, then the
 * optional full bio, with a live preview card and the trust checklist on the
 * side. Uses the classic screen so the layout matches the Scout design and
 * every field is a plain, labelled input.
 *
 * @package Scout_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Team_Editor {

	const NONCE = 'scout_team_nonce';

	public static function boot(): void {
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'classic' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'add_meta_boxes_' . Scout_Team::POST_TYPE, array( __CLASS__, 'boxes' ) );
		add_action( 'edit_form_after_title', array( __CLASS__, 'after_title' ) );
		add_action( 'save_post_' . Scout_Team::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manage_' . Scout_Team::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Scout_Team::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'list_order' ) );
	}

	/** The list screen shows people in site order, the same as the grid. */
	public static function list_order( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || Scout_Team::POST_TYPE !== $query->get( 'post_type' ) || $query->get( 'orderby' ) ) {
			return;
		}
		$query->set(
			'orderby',
			array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			)
		);
	}

	public static function classic( $use, $post_type ) {
		return Scout_Team::POST_TYPE === $post_type ? false : $use;
	}

	public static function title_placeholder( $text, $post ) {
		return ( $post instanceof WP_Post && Scout_Team::POST_TYPE === $post->post_type ) ? __( 'Full name', 'scout-team' ) : $text;
	}

	public static function assets( $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || Scout_Team::POST_TYPE !== $screen->post_type ) {
			return;
		}
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			Scout_Admin_UI::enqueue( SCOUT_TEAM_URL . 'assets/scout-admin.css' );
			wp_enqueue_style( 'scout-team-editor', SCOUT_TEAM_URL . 'assets/scout-team-editor.css', array( 'scout-admin' ), SCOUT_TEAM_VERSION );
			wp_enqueue_script( 'scout-team-editor', SCOUT_TEAM_URL . 'assets/scout-team-editor.js', array(), SCOUT_TEAM_VERSION, true );
		} elseif ( 'edit.php' === $hook ) {
			wp_enqueue_style( 'scout-team-editor', SCOUT_TEAM_URL . 'assets/scout-team-editor.css', array(), SCOUT_TEAM_VERSION );
		}
	}

	public static function boxes(): void {
		add_meta_box( 'scout-team-profile', __( 'Profile', 'scout-team' ), array( __CLASS__, 'render_profile' ), Scout_Team::POST_TYPE, 'scout_team_top', 'high' );
		add_meta_box( 'scout-team-preview', __( 'Preview', 'scout-team' ), array( __CLASS__, 'render_preview' ), Scout_Team::POST_TYPE, 'side', 'high' );
		add_meta_box( 'scout-team-trust', __( 'Trust signals', 'scout-team' ), array( __CLASS__, 'render_trust' ), Scout_Team::POST_TYPE, 'side', 'high' );
	}

	/** Profile box straight under the name, then a heading for the full bio. */
	public static function after_title( $post ): void {
		if ( Scout_Team::POST_TYPE !== $post->post_type ) {
			return;
		}
		echo '<div class="scout-team-top">';
		do_meta_boxes( Scout_Team::POST_TYPE, 'scout_team_top', $post );
		echo '</div>';
		if ( post_type_supports( Scout_Team::POST_TYPE, 'editor' ) ) {
			echo '<h2 class="scout-team-fullbio">' . esc_html__( 'Full bio (optional)', 'scout-team' ) . '<span>' . esc_html__( 'Shows on the profile page under the short bio.', 'scout-team' ) . '</span></h2>';
		}
	}

	/* ---------- the Profile box ---------- */

	public static function render_profile( $post ): void {
		$p = Scout_Team::profile( (int) $post->ID );
		wp_nonce_field( 'scout_team_save', self::NONCE );
		$law = Scout_Team::is_law();
		?>
		<div class="sh scout-team-form">
			<p class="sh-note"><?php esc_html_e( 'Every field also feeds the Person schema that tells search engines who this person is. Empty fields stay hidden on the site.', 'scout-team' ); ?></p>

			<div class="st-grid">
				<p class="st-field">
					<label for="st-role"><?php esc_html_e( 'Role or title', 'scout-team' ); ?></label>
					<input id="st-role" type="text" name="scout_team[role]" value="<?php echo esc_attr( $p['role'] ); ?>" placeholder="<?php esc_attr_e( 'Partner', 'scout-team' ); ?>" data-st-preview="role">
				</p>
				<p class="st-field">
					<label for="st-languages"><?php esc_html_e( 'Languages', 'scout-team' ); ?></label>
					<input id="st-languages" type="text" name="scout_team[languages]" value="<?php echo esc_attr( $p['languages'] ); ?>" placeholder="<?php esc_attr_e( 'English, Spanish', 'scout-team' ); ?>">
				</p>
			</div>

			<p class="st-field">
				<label for="st-bio"><?php esc_html_e( 'Short bio', 'scout-team' ); ?></label>
				<textarea id="st-bio" name="scout_team[bio]" rows="3"><?php echo esc_textarea( $p['bio'] ); ?></textarea>
				<span class="description"><?php esc_html_e( 'Two or three sentences in plain language. Shows on the team grid and at the top of the profile.', 'scout-team' ); ?></span>
			</p>

			<fieldset class="st-group">
				<legend><?php esc_html_e( 'Military service', 'scout-team' ); ?></legend>
				<div class="st-grid">
					<p class="st-field">
						<label for="st-branch"><?php esc_html_e( 'Branch', 'scout-team' ); ?></label>
						<select id="st-branch" name="scout_team[military_branch]" data-st-preview="branch">
							<?php foreach ( Scout_Team::branches() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $p['military_branch'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="st-field">
						<label for="st-years"><?php esc_html_e( 'Years served', 'scout-team' ); ?></label>
						<input id="st-years" type="text" name="scout_team[military_years]" value="<?php echo esc_attr( $p['military_years'] ); ?>" placeholder="<?php esc_attr_e( '2004 to 2012', 'scout-team' ); ?>" data-st-preview="years">
					</p>
				</div>
			</fieldset>

			<?php self::repeater( 'education', __( 'Education', 'scout-team' ), __( 'Add a school', 'scout-team' ), array( 'school' => __( 'School', 'scout-team' ), 'degree' => __( 'Degree', 'scout-team' ), 'year' => __( 'Year', 'scout-team' ) ), $p['education'] ); ?>

			<?php if ( $law ) : ?>
				<div class="st-law">
					<p class="st-check">
						<label><input type="checkbox" name="scout_team[attorney]" value="1" <?php checked( $p['attorney'] ); ?> data-st-toggle="bar"> <?php esc_html_e( 'This person is an attorney', 'scout-team' ); ?></label>
					</p>
					<div data-st-show="bar" <?php echo $p['attorney'] ? '' : 'hidden'; ?>>
						<?php self::repeater( 'bar', __( 'Bar admissions', 'scout-team' ), __( 'Add a bar admission', 'scout-team' ), array( 'state' => __( 'State or court', 'scout-team' ), 'year' => __( 'Year admitted', 'scout-team' ), 'number' => __( 'Bar number (optional)', 'scout-team' ), 'url' => __( 'Bar profile link (optional)', 'scout-team' ) ), $p['bar'], __( 'Shows only when filled in. A bar profile link lets visitors confirm the license themselves.', 'scout-team' ) ); ?>
					</div>
				</div>
			<?php else : ?>
				<?php
				// Keep any saved admissions if the site is switched out of law mode.
				foreach ( $p['bar'] as $i => $row ) {
					foreach ( $row as $col => $value ) {
						echo '<input type="hidden" name="scout_team[bar][' . esc_attr( (string) $i ) . '][' . esc_attr( $col ) . ']" value="' . esc_attr( $value ) . '">';
					}
				}
				?>
				<input type="hidden" name="scout_team[attorney]" value="<?php echo esc_attr( (string) $p['attorney'] ); ?>">
			<?php endif; ?>

			<?php self::repeater( 'awards', __( 'Awards and honors', 'scout-team' ), __( 'Add an award', 'scout-team' ), array( 'name' => __( 'Award', 'scout-team' ), 'year' => __( 'Year', 'scout-team' ), 'issuer' => __( 'Given by', 'scout-team' ) ), $p['awards'], __( 'List only recognitions you can document, with the group that gave each one.', 'scout-team' ) ); ?>

			<div class="st-grid">
				<p class="st-field">
					<label for="st-memberships"><?php esc_html_e( 'Professional memberships', 'scout-team' ); ?></label>
					<textarea id="st-memberships" name="scout_team[memberships]" rows="3"><?php echo esc_textarea( implode( "\n", $p['memberships'] ) ); ?></textarea>
					<span class="description"><?php esc_html_e( 'One per line.', 'scout-team' ); ?></span>
				</p>
				<p class="st-field">
					<label for="st-sameas"><?php esc_html_e( 'Profile links', 'scout-team' ); ?></label>
					<textarea id="st-sameas" name="scout_team[same_as]" rows="3" placeholder="https://www.linkedin.com/in/..."><?php echo esc_textarea( implode( "\n", $p['same_as'] ) ); ?></textarea>
					<span class="description"><?php esc_html_e( 'One link per line: LinkedIn, a state bar profile, Avvo, or another public profile of this person.', 'scout-team' ); ?></span>
				</p>
			</div>

			<fieldset class="st-group">
				<legend><?php esc_html_e( 'Contact and byline', 'scout-team' ); ?></legend>
				<div class="st-grid">
					<p class="st-field">
						<label for="st-email"><?php esc_html_e( 'Email (optional)', 'scout-team' ); ?></label>
						<input id="st-email" type="email" name="scout_team[email]" value="<?php echo esc_attr( $p['email'] ); ?>">
					</p>
					<p class="st-field">
						<label for="st-phone"><?php esc_html_e( 'Phone (optional)', 'scout-team' ); ?></label>
						<input id="st-phone" type="tel" name="scout_team[phone]" value="<?php echo esc_attr( $p['phone'] ); ?>">
					</p>
				</div>
				<p class="st-field">
					<label for="st-user"><?php esc_html_e( 'Writes posts as', 'scout-team' ); ?></label>
					<?php
					wp_dropdown_users(
						array(
							'name'              => 'scout_team[user]',
							'id'                => 'st-user',
							'selected'          => (int) $p['user'],
							'show_option_none'  => __( 'Nobody (no byline)', 'scout-team' ),
							'option_none_value' => '0',
							'capability'        => array( 'edit_posts' ),
						)
					);
					?>
					<span class="description"><?php esc_html_e( 'Pick their WordPress login and the author name on their posts links to this profile.', 'scout-team' ); ?></span>
				</p>
				<?php if ( ! Scout_Team::settings()['contact'] ) : ?>
					<p class="description"><?php esc_html_e( 'Email and phone go into the schema only. Turn on "Show email and phone" in Team settings to print them on the profile.', 'scout-team' ); ?></p>
				<?php endif; ?>
			</fieldset>
		</div>
		<?php
	}

	/**
	 * A repeatable group: one row per entry, a template for new rows.
	 *
	 * @param string $group  Group key.
	 * @param string $legend Group label.
	 * @param string $add    Add button text.
	 * @param array  $cols   col => label.
	 * @param array  $rows   Saved rows.
	 * @param string $help   Optional help line.
	 */
	private static function repeater( string $group, string $legend, string $add, array $cols, array $rows, string $help = '' ): void {
		$id = 'st-' . $group;
		echo '<fieldset class="st-group st-repeater" data-st-repeater="' . esc_attr( $group ) . '">';
		echo '<legend>' . esc_html( $legend ) . '</legend>';
		echo '<div class="st-rows" id="' . esc_attr( $id . '-rows' ) . '">';
		$rows = $rows ? array_values( $rows ) : array( array() );
		foreach ( $rows as $i => $row ) {
			self::row( $group, (string) $i, $cols, $row );
		}
		echo '</div>';
		echo '<template id="' . esc_attr( $id . '-template' ) . '">';
		self::row( $group, '__i__', $cols, array() );
		echo '</template>';
		echo '<button type="button" class="button st-add" data-st-add="' . esc_attr( $group ) . '">' . esc_html( $add ) . '</button>';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</fieldset>';
	}

	private static function row( string $group, string $i, array $cols, array $row ): void {
		echo '<div class="st-row st-cols-' . count( $cols ) . '">';
		foreach ( $cols as $col => $label ) {
			$fid  = 'st-' . $group . '-' . $i . '-' . $col;
			$type = 'url' === $col ? 'url' : 'text';
			$attr = 'year' === $col ? ' inputmode="numeric" pattern="[0-9]{4}" maxlength="4"' : '';
			echo '<p class="st-field st-col-' . esc_attr( $col ) . '">';
			echo '<label for="' . esc_attr( $fid ) . '">' . esc_html( $label ) . '</label>';
			echo '<input id="' . esc_attr( $fid ) . '" type="' . esc_attr( $type ) . '" name="scout_team[' . esc_attr( $group ) . '][' . esc_attr( $i ) . '][' . esc_attr( $col ) . ']" value="' . esc_attr( (string) ( $row[ $col ] ?? '' ) ) . '"' . $attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attr is a fixed string.
			echo '</p>';
		}
		echo '<button type="button" class="button-link st-remove" data-st-remove>' . esc_html__( 'Remove', 'scout-team' ) . '</button>';
		echo '</div>';
	}

	/* ---------- side boxes ---------- */

	public static function render_preview( $post ): void {
		$p     = Scout_Team::profile( (int) $post->ID );
		$thumb = get_the_post_thumbnail( $post, array( 160, 160 ), array( 'alt' => '' ) );
		?>
		<div class="st-card" data-st-card>
			<div class="st-card__photo"><?php echo $thumb ? $thumb : '<span class="st-card__empty" aria-hidden="true"></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup. ?></div>
			<div class="st-card__text">
				<p class="st-card__role" data-st-out="role" data-st-fallback="<?php esc_attr_e( 'Role', 'scout-team' ); ?>"><?php echo esc_html( '' !== $p['role'] ? $p['role'] : __( 'Role', 'scout-team' ) ); ?></p>
				<p class="st-card__name" data-st-out="name" data-st-fallback="<?php esc_attr_e( 'Full name', 'scout-team' ); ?>"><?php echo esc_html( '' !== $post->post_title ? $post->post_title : __( 'Full name', 'scout-team' ) ); ?></p>
				<p class="st-card__meta" data-st-out="service"><?php echo esc_html( Scout_Team::military_line( $p ) ); ?></p>
			</div>
		</div>
		<p class="description"><?php esc_html_e( 'How this person looks on the team grid. Colors and fonts follow the site theme.', 'scout-team' ); ?></p>
		<?php
	}

	public static function render_trust( $post ): void {
		echo '<p class="description">' . esc_html__( 'What visitors and search engines look for before they trust a real person. Updates when you save.', 'scout-team' ) . '</p>';
		echo '<ul class="st-trust">';
		foreach ( Scout_Team::checks( (int) $post->ID ) as $key => $check ) {
			list( $label, $note, $done ) = $check;
			echo '<li class="' . ( $done ? 'is-done' : 'is-todo' ) . '">';
			echo '<span class="st-trust__mark" aria-hidden="true">' . ( $done ? '&#10003;' : '!' ) . '</span>';
			echo '<span class="st-trust__text"><strong>' . esc_html( $label ) . '</strong><span>' . esc_html( $note ) . '</span></span>';
			echo '<span class="st-trust__pill">' . esc_html( $done ? __( 'Done', 'scout-team' ) : __( 'Add', 'scout-team' ) ) . '</span>';
			echo '</li>';
		}
		echo '</ul>';
	}

	/* ---------- save ---------- */

	public static function save( $post_id, $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'scout_team_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every key is sanitized in sanitize_profile().
		$raw = isset( $_POST['scout_team'] ) ? (array) wp_unslash( $_POST['scout_team'] ) : array();
		$p   = Scout_Team::sanitize_profile( $raw );
		// Only someone who can edit other people's posts may tie a profile to another login.
		if ( $p['user'] && get_current_user_id() !== $p['user'] && ! current_user_can( 'edit_others_posts' ) ) {
			$p['user'] = (int) Scout_Team::profile( (int) $post_id )['user'];
		}
		update_post_meta( $post_id, Scout_Team::META, $p );
	}

	/* ---------- list screen ---------- */

	public static function columns( array $cols ): array {
		$out = array();
		foreach ( $cols as $key => $label ) {
			if ( 'title' === $key ) {
				$out['st_photo'] = '<span class="screen-reader-text">' . esc_html__( 'Photo', 'scout-team' ) . '</span>';
				$out[ $key ]     = __( 'Name', 'scout-team' );
				$out['st_role']  = __( 'Role', 'scout-team' );
				$out['st_trust'] = __( 'Trust signals', 'scout-team' );
				continue;
			}
			$out[ $key ] = $label;
		}
		return $out;
	}

	public static function column( $col, $post_id ): void {
		if ( 'st_photo' === $col ) {
			echo get_the_post_thumbnail( (int) $post_id, array( 44, 44 ), array( 'class' => 'st-thumb', 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup.
		} elseif ( 'st_role' === $col ) {
			echo esc_html( Scout_Team::profile( (int) $post_id )['role'] );
		} elseif ( 'st_trust' === $col ) {
			$checks = Scout_Team::checks( (int) $post_id );
			$done   = count( array_filter( wp_list_pluck( $checks, 2 ) ) );
			/* translators: 1: checks done, 2: total checks. */
			echo '<span class="st-count' . ( $done === count( $checks ) ? ' is-done' : '' ) . '">' . esc_html( sprintf( __( '%1$d of %2$d', 'scout-team' ), $done, count( $checks ) ) ) . '</span>';
		}
	}
}
