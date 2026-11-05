<?php
/**
 * The front end: the profile on each /team/<name>/ page, the [scout_team]
 * grid, excerpts from the short bio, and author links that point at the
 * person's profile.
 *
 * Everything a visitor sees goes through Scout_Team::public_profile(), so
 * card only contact and credential lines without a source never print here.
 * A person whose profile page is switched off answers "not found" to
 * visitors; people who can edit them still see it, marked noindex.
 *
 * Everything prints inside the theme's own template (through the_content),
 * so it works on classic and block themes and inherits the site's fonts and
 * colors. CSS custom properties let a theme restyle it.
 *
 * @package Scout_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Team_Front {

	/** True while this plugin prints a photo, so the theme's copy can be dropped. */
	private static $own_photo = false;

	/** Guards against the_content running inside itself. */
	private static $rendering = false;

	/** The person whose photo a theme is printing, for the alt text default. */
	private static $alt_name = '';

	public static function boot(): void {
		add_action( 'template_redirect', array( __CLASS__, 'guard_profile' ), 1 );
		add_action( 'pre_get_posts', array( __CLASS__, 'archive_query' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_args' ), 10, 2 );
		add_action( 'begin_fetch_post_thumbnail_html', array( __CLASS__, 'photo_begin' ), 10, 2 );
		add_action( 'end_fetch_post_thumbnail_html', array( __CLASS__, 'photo_end' ) );
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'photo_alt' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'the_content', array( __CLASS__, 'profile' ), 20 );
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'drop_theme_photo' ), 10, 2 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'excerpt' ), 10, 2 );
		add_filter( 'author_link', array( __CLASS__, 'author_link' ), 10, 2 );
		add_shortcode( 'scout_team', array( __CLASS__, 'grid' ) );
	}

	public static function assets(): void {
		wp_register_style( 'scout-team', SCOUT_TEAM_URL . 'assets/scout-team.css', array(), SCOUT_TEAM_VERSION );
		if ( is_singular( Scout_Team::POST_TYPE ) || is_post_type_archive( Scout_Team::POST_TYPE ) ) {
			wp_enqueue_style( 'scout-team' );
			return;
		}
		$post = get_post();
		if ( is_singular() && $post && has_shortcode( (string) $post->post_content, 'scout_team' ) ) {
			wp_enqueue_style( 'scout-team' );
		}
	}

	/* ---------- who can see what ---------- */

	/**
	 * Profile page switched off: visitors get the theme's "not found" page.
	 * Someone who can edit the person still sees it, so they can check it, and
	 * search engines are told not to list it.
	 */
	public static function guard_profile(): void {
		if ( ! is_singular( Scout_Team::POST_TYPE ) ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( ! $id || Scout_Team::shows( Scout_Team::profile( $id ), 'bio' ) ) {
			return;
		}
		if ( current_user_can( 'edit_post', $id ) ) {
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/** The /team/ archive lists the team page: no one whose page is off or who is not on the team page. */
	public static function archive_query( $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( Scout_Team::POST_TYPE ) ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'      => Scout_Team::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! Scout_Team::on_team_page( $id ) || ! Scout_Team::shows( Scout_Team::profile( $id ), 'bio' ) ) {
				$out[] = $id;
			}
		}
		if ( $out ) {
			$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), $out ) );
		}
	}

	/** Profile pages that are switched off stay out of the sitemap. */
	public static function sitemap_args( $args, $post_type ) {
		if ( Scout_Team::POST_TYPE !== $post_type ) {
			return $args;
		}
		$hidden = Scout_Team::hidden_profiles();
		if ( $hidden ) {
			$args['post__not_in'] = array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), $hidden );
		}
		return $args;
	}

	/* ---------- photo alt text ---------- */

	/** A theme is about to print a person's photo: remember the name. */
	public static function photo_begin( $post_id, $thumb_id ): void {
		unset( $thumb_id );
		self::$alt_name = ( ! is_admin() && ! self::$rendering && Scout_Team::POST_TYPE === get_post_type( (int) $post_id ) ) ? wp_strip_all_tags( get_the_title( (int) $post_id ) ) : '';
	}

	public static function photo_end(): void {
		self::$alt_name = '';
	}

	/**
	 * A person's photo with no alt text set reads as their name. Only fills an
	 * empty alt that came from the media library; this plugin's own markup
	 * already chooses its alt text.
	 */
	public static function photo_alt( $attr, $attachment ) {
		if ( '' === self::$alt_name || ! is_array( $attr ) || '' !== (string) ( $attr['alt'] ?? '' ) ) {
			return $attr;
		}
		$id = is_object( $attachment ) ? (int) $attachment->ID : 0;
		if ( $id && '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
			$attr['alt'] = self::$alt_name;
		}
		return $attr;
	}

	/* ---------- the profile page ---------- */

	public static function profile( $content ) {
		if ( self::$rendering || ! is_singular( Scout_Team::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id = (int) get_the_ID();
		if ( $id !== (int) get_queried_object_id() ) {
			return $content;
		}
		self::$rendering = true;
		$html            = self::render_profile( $id, (string) $content );
		self::$rendering = false;
		return $html;
	}

	/** The theme's own featured image on a profile page: the profile prints it instead. */
	public static function drop_theme_photo( $html, $post_id ) {
		if ( self::$own_photo || is_admin() || ! is_singular( Scout_Team::POST_TYPE ) ) {
			return $html;
		}
		return (int) $post_id === (int) get_queried_object_id() ? '' : $html;
	}

	private static function render_profile( int $id, string $content ): string {
		$p        = Scout_Team::public_profile( Scout_Team::profile( $id ) );
		$s        = Scout_Team::settings();
		$name     = get_the_title( $id );
		$military = Scout_Team::military_line( $p );
		$langs    = Scout_Team::languages( $p );

		ob_start();
		?>
		<div class="scout-team-profile" data-scout-view="team_profile_view" data-scout-label="<?php echo esc_attr( wp_strip_all_tags( $name ) ); ?>">
			<div class="scout-team-profile__head">
				<?php
				if ( has_post_thumbnail( $id ) ) {
					self::$own_photo = true;
					echo '<div class="scout-team-profile__photo">' . get_the_post_thumbnail( $id, 'medium_large', array( 'alt' => esc_attr( wp_strip_all_tags( $name ) ), 'fetchpriority' => 'high', 'loading' => 'eager' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup.
					self::$own_photo = false;
				}
				?>
				<div class="scout-team-profile__intro">
					<?php if ( '' !== $p['role'] ) : ?>
						<p class="scout-team-profile__role"><?php echo esc_html( $p['role'] ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $p['bio'] ) : ?>
						<p class="scout-team-profile__bio"><?php echo esc_html( $p['bio'] ); ?></p>
					<?php endif; ?>
					<?php if ( $s['contact'] && ( '' !== $p['email'] || '' !== $p['phone'] ) ) : ?>
						<p class="scout-team-profile__contact">
							<?php if ( '' !== $p['phone'] ) : ?>
								<a class="scout-team-button" href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $p['phone'] ) ); ?>" data-scout-track="team_call" data-scout-label="<?php echo esc_attr( wp_strip_all_tags( $name ) ); ?>"><?php echo esc_html( $p['phone'] ); ?></a>
							<?php endif; ?>
							<?php if ( '' !== $p['email'] ) : ?>
								<a class="scout-team-button" href="<?php echo esc_url( 'mailto:' . $p['email'] ); ?>" data-scout-track="team_email" data-scout-label="<?php echo esc_attr( wp_strip_all_tags( $name ) ); ?>"><?php echo esc_html( $p['email'] ); ?></a>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( '' !== trim( wp_strip_all_tags( $content ) ) ) : ?>
				<div class="scout-team-profile__body"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already filtered post content. ?></div>
			<?php endif; ?>

			<div class="scout-team-profile__facts">
				<?php
				if ( Scout_Team::is_law() && $p['attorney'] && $p['bar'] ) {
					$items = array();
					foreach ( $p['bar'] as $row ) {
						$line = $row['state'];
						if ( '' !== $row['year'] ) {
							/* translators: %s: year. */
							$line .= ', ' . sprintf( __( 'admitted %s', 'scout-team' ), $row['year'] );
						}
						$sub = array();
						if ( '' !== $row['number'] ) {
							/* translators: %s: bar number. */
							$sub[] = esc_html( sprintf( __( 'Bar no. %s', 'scout-team' ), $row['number'] ) );
						}
						if ( '' !== $row['url'] ) {
							$sub[] = '<a href="' . esc_url( $row['url'] ) . '" rel="noopener">' . esc_html__( 'Check the license', 'scout-team' ) . '<span class="screen-reader-text"> ' . esc_html( $row['state'] ) . '</span></a>';
						}
						$item = esc_html( $line );
						if ( $sub ) {
							$item .= '<span class="scout-team-sub">' . implode( ' <span aria-hidden="true">&middot;</span> ', $sub ) . '</span>';
						}
						$items[] = $item;
					}
					self::section( __( 'Bar admissions', 'scout-team' ), $items );
				}

				$items = array();
				foreach ( $p['education'] as $row ) {
					$items[] = esc_html( implode( ', ', array_filter( array( $row['degree'], $row['school'], $row['year'] ) ) ) );
				}
				self::section( __( 'Education', 'scout-team' ), $items );

				if ( '' !== $military ) {
					self::section( __( 'Military service', 'scout-team' ), array( esc_html( $military ) ) );
				}

				self::section( __( 'Professional memberships', 'scout-team' ), array_map( 'esc_html', wp_list_pluck( $p['memberships'], 'name' ) ) );

				$items = array();
				foreach ( $p['awards'] as $row ) {
					$items[] = esc_html( implode( ', ', array_filter( array( $row['name'], $row['issuer'], $row['year'] ) ) ) );
				}
				self::section( __( 'Awards and honors', 'scout-team' ), $items );

				if ( $langs ) {
					self::section( __( 'Languages', 'scout-team' ), array( esc_html( implode( ', ', $langs ) ) ) );
				}

				$items = array();
				foreach ( $p['same_as'] as $url ) {
					$items[] = '<a href="' . esc_url( $url ) . '" rel="me noopener">' . esc_html( self::site_label( $url ) ) . '</a>';
				}
				self::section( __( 'Other profiles', 'scout-team' ), $items, 'scout-team-links' );
				?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The profile markup for one person, as the profile page prints it.
	 *
	 * @param int    $id      Person post ID.
	 * @param string $content The full bio, already filtered.
	 */
	public static function profile_html( int $id, string $content = '' ): string {
		self::$rendering = true;
		$html            = self::render_profile( $id, $content );
		self::$rendering = false;
		return $html;
	}

	/**
	 * One facts section: a heading and a list. Items arrive escaped.
	 *
	 * @param string   $heading Section heading.
	 * @param string[] $items   Escaped HTML items.
	 * @param string   $class   Extra list class.
	 */
	private static function section( string $heading, array $items, string $class = '' ): void {
		$items = array_filter( $items );
		if ( ! $items ) {
			return;
		}
		echo '<section class="scout-team-facts">';
		echo '<h2 class="scout-team-facts__title">' . esc_html( $heading ) . '</h2>';
		echo '<ul class="scout-team-facts__list' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '">';
		foreach ( $items as $item ) {
			echo '<li>' . $item . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
		}
		echo '</ul></section>';
	}

	/** A readable name for a profile link. */
	public static function site_label( string $url ): string {
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host  = preg_replace( '/^www\./', '', $host );
		$known = array(
			'linkedin.com'       => 'LinkedIn',
			'avvo.com'           => 'Avvo',
			'justia.com'         => 'Justia',
			'lawyers.justia.com' => 'Justia',
			'martindale.com'     => 'Martindale-Hubbell',
			'superlawyers.com'   => 'Super Lawyers',
			'facebook.com'       => 'Facebook',
			'instagram.com'      => 'Instagram',
			'x.com'              => 'X',
			'twitter.com'        => 'X',
			'youtube.com'        => 'YouTube',
			'github.com'         => 'GitHub',
		);
		if ( isset( $known[ $host ] ) ) {
			return $known[ $host ];
		}
		if ( preg_match( '/(^|\.)([a-z]{2})bar\.(org|gov)$/', $host ) || preg_match( '/bar\.(org|gov)$/', $host ) ) {
			return __( 'State bar profile', 'scout-team' );
		}
		return $host;
	}

	/* ---------- the grid ---------- */

	/**
	 * [scout_team columns="3" ids="12,15" limit="-1" bio="yes" heading="h3"]
	 *
	 * Without ids: the team page, which is everyone live in the Team group with
	 * Team page switched on. With ids: those people, in that order, whatever
	 * their group. A person whose profile page is off shows without a link.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function grid( $atts ): string {
		$s    = Scout_Team::settings();
		$atts = shortcode_atts(
			array(
				'columns' => (string) $s['columns'],
				'ids'     => '',
				'limit'   => '-1',
				'bio'     => 'yes',
				'heading' => 'h3',
			),
			$atts,
			'scout_team'
		);

		$cols    = min( 4, max( 1, absint( $atts['columns'] ) ) );
		$heading = in_array( $atts['heading'], array( 'h2', 'h3', 'h4' ), true ) ? $atts['heading'] : 'h3';
		$limit   = (int) $atts['limit'];
		$args    = array(
			'post_type'      => Scout_Team::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'no_found_rows'  => true,
		);
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $atts['ids'] ) ) );
		if ( $ids ) {
			$args['post__in'] = $ids;
			$args['orderby']  = 'post__in';
		}
		$people = get_posts( $args );
		if ( ! $ids ) {
			$people = array_values(
				array_filter(
					$people,
					static function ( $person ) {
						return Scout_Team::on_team_page( (int) $person->ID );
					}
				)
			);
		}
		if ( $limit > 0 ) {
			$people = array_slice( $people, 0, $limit );
		}
		if ( ! $people ) {
			return '';
		}

		wp_enqueue_style( 'scout-team' );

		ob_start();
		echo '<ul class="scout-team-grid" style="--scout-team-cols:' . (int) $cols . '">';
		foreach ( $people as $person ) {
			$p    = Scout_Team::public_profile( Scout_Team::profile( (int) $person->ID ) );
			$name = get_the_title( $person );
			$url  = Scout_Team::shows( $p, 'bio' ) ? get_permalink( $person ) : '';
			echo '<li class="scout-team-card">';
			if ( has_post_thumbnail( $person ) ) {
				self::$rendering = true; // This markup sets its own alt text.
				$img             = get_the_post_thumbnail( $person, 'medium_large', array( 'alt' => '', 'loading' => 'lazy' ) );
				self::$rendering = false;
				if ( '' !== $url ) {
					echo '<a class="scout-team-card__photo" href="' . esc_url( $url ) . '" tabindex="-1" aria-hidden="true" data-scout-track="team_profile_click" data-scout-label="' . esc_attr( wp_strip_all_tags( $name ) ) . '">';
					echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup.
					echo '</a>';
				} else {
					echo '<span class="scout-team-card__photo" aria-hidden="true">' . $img . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup.
				}
			}
			echo '<div class="scout-team-card__text">';
			if ( '' !== $url ) {
				echo '<' . esc_html( $heading ) . ' class="scout-team-card__name"><a href="' . esc_url( $url ) . '" data-scout-track="team_profile_click">' . esc_html( wp_strip_all_tags( $name ) ) . '</a></' . esc_html( $heading ) . '>';
			} else {
				echo '<' . esc_html( $heading ) . ' class="scout-team-card__name">' . esc_html( wp_strip_all_tags( $name ) ) . '</' . esc_html( $heading ) . '>';
			}
			if ( '' !== $p['role'] ) {
				echo '<p class="scout-team-card__role">' . esc_html( $p['role'] ) . '</p>';
			}
			$military = Scout_Team::military_line( $p );
			if ( '' !== $military ) {
				echo '<p class="scout-team-card__meta">' . esc_html( $military ) . '</p>';
			}
			if ( 'no' !== $atts['bio'] && '' !== $p['bio'] ) {
				echo '<p class="scout-team-card__bio">' . esc_html( $p['bio'] ) . '</p>';
			}
			echo '</div></li>';
		}
		echo '</ul>';
		return (string) ob_get_clean();
	}

	/* ---------- excerpts and bylines ---------- */

	public static function excerpt( $excerpt, $post = null ) {
		$post = get_post( $post );
		if ( '' !== trim( (string) $excerpt ) || ! $post || Scout_Team::POST_TYPE !== $post->post_type ) {
			return $excerpt;
		}
		return Scout_Team::profile( (int) $post->ID )['bio'];
	}

	public static function author_link( $link, $author_id ) {
		if ( ! Scout_Team::settings()['bylines'] ) {
			return $link;
		}
		$member = Scout_Team::member_for_user( (int) $author_id );
		// No profile page to send readers to: keep WordPress's own author link.
		if ( ! $member || ! Scout_Team::shows( Scout_Team::profile( $member ), 'bio' ) ) {
			return $link;
		}
		return (string) get_permalink( $member );
	}
}
