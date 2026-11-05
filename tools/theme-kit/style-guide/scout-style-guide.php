<?php
/**
 * Scout theme kit: the /style-guide/ page.
 *
 * Every Scout theme ships a style guide page (Client Design Playbook,
 * decision 4, 2026-11-05). It is built on the theme's own stylesheet and the
 * real Scout plugins, so the client approves the system before the homepage
 * and the page can never drift from the site. Search engines are told to
 * skip it.
 *
 * Copy this file into a Scout theme (inc/scout-style-guide.php) and require
 * it from functions.php, after inc/scout-requires-build.php. Then copy the
 * template for the theme's kind: page-style-guide.php into the theme root for
 * a classic theme, or style-guide.html into templates/page-style-guide.html
 * for a block theme. Both print the same sections through this file.
 *
 * What it does:
 * - Creates the page (slug style-guide) when the theme is activated, and once
 *   on an admin visit for a theme that was already active when it got this
 *   file. A page that is deleted on purpose is not made again.
 * - Marks the page noindex. With Scout Core on, the page carries Core's own
 *   noindex flag, so Core's robots control and sitemap handle it. Without
 *   Core, the page filters wp_robots itself. It also stays out of site search.
 * - Renders the sections: brief, color, type, space and shape, motion,
 *   buttons and links, Scout's parts, the logo. The theme's footer closes it.
 *
 * Styled with the theme's own CSS and the --scout-* variables only. The
 * swatches show the DESIGN.md values, which is the point of them.
 *
 * Canonical copy: Scout-Plugins/tools/theme-kit/style-guide/scout-style-guide.php.
 * Change it there, then copy it into each theme at that theme's next update.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Scout_Theme_Style_Guide' ) ) {

	final class Scout_Theme_Style_Guide {

		const SLUG    = 'style-guide';
		const TITLE   = 'Style guide';
		const OPTION  = 'scout_style_guide_page';
		const NOINDEX = '_scout_seo_noindex';

		/** Plugin stylesheets the sections need in the head, when their plugin registered them. */
		const STYLES = array( 'scout-reviews', 'scout-team', 'scout-results', 'scout-accessibility', 'scout-hours' );

		public static function boot(): void {
			add_action( 'after_switch_theme', array( __CLASS__, 'make_page' ) );
			add_action( 'admin_init', array( __CLASS__, 'make_page_once' ) );
			add_action( 'wp', array( __CLASS__, 'hide' ) );
			add_action( 'pre_get_posts', array( __CLASS__, 'out_of_search' ) );
			add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'out_of_sitemap' ), 10, 2 );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
			add_action( 'wp_head', array( __CLASS__, 'tokens' ), 1 );
			add_shortcode( 'scout_style_guide', array( __CLASS__, 'shortcode' ) );
		}

		/* ---------- the page ---------- */

		public static function page_id(): int {
			$page = get_page_by_path( self::SLUG, OBJECT, 'page' );
			return $page instanceof WP_Post && 'trash' !== $page->post_status ? (int) $page->ID : 0;
		}

		public static function is_page(): bool {
			return is_page( self::SLUG );
		}

		/** Make the page if it is missing. Runs on theme activation. */
		public static function make_page(): void {
			$id = self::page_id();
			if ( ! $id ) {
				$id = (int) wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_title'   => __( 'Style guide', 'scout' ),
						'post_name'    => self::SLUG,
						'post_content' => "<!-- wp:shortcode -->\n[scout_style_guide]\n<!-- /wp:shortcode -->",
						'post_author'  => get_current_user_id(),
					),
					false
				);
			}
			if ( $id > 0 ) {
				update_post_meta( $id, self::NOINDEX, '1' );
				update_option( self::OPTION, $id, false );
			}
		}

		/** A theme that was already active when it got this file makes the page on the next admin visit. */
		public static function make_page_once(): void {
			if ( get_option( self::OPTION ) || ! current_user_can( 'edit_theme_options' ) ) {
				return;
			}
			self::make_page();
		}

		/* ---------- out of the index ---------- */

		/**
		 * Noindex. Scout Core reads the page's own flag through its robots
		 * control; without Core the page filters wp_robots itself.
		 */
		public static function hide(): void {
			if ( ! self::is_page() ) {
				return;
			}
			$id = (int) get_queried_object_id();
			if ( $id && '1' !== (string) get_post_meta( $id, self::NOINDEX, true ) ) {
				update_post_meta( $id, self::NOINDEX, '1' );
			}
			if ( ! self::core_owns( 'seo' ) ) {
				add_filter( 'wp_robots', 'wp_robots_no_robots' );
			}
		}

		public static function out_of_search( $query ): void {
			if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
				return;
			}
			$id = self::page_id();
			if ( $id ) {
				$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), array( $id ) ) );
			}
		}

		public static function out_of_sitemap( $args, $post_type ) {
			$id = 'page' === $post_type ? self::page_id() : 0;
			if ( $id ) {
				$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), array( $id ) );
			}
			return $args;
		}

		/* ---------- assets ---------- */

		/** Plugin styles that otherwise load only where their shortcode sits in the content. */
		public static function assets(): void {
			if ( ! self::is_page() ) {
				return;
			}
			foreach ( self::STYLES as $handle ) {
				if ( wp_style_is( $handle, 'registered' ) ) {
					wp_enqueue_style( $handle );
				}
			}
			if ( class_exists( 'Scout_Contact', false ) && method_exists( 'Scout_Contact', 'assets' ) ) {
				Scout_Contact::assets();
			}
		}

		/**
		 * The --scout-* variables from DESIGN.md, printed before the theme's
		 * stylesheet so the theme's own values win where it sets them.
		 */
		public static function tokens(): void {
			if ( self::is_page() && function_exists( 'scout_design_css' ) ) {
				echo '<style id="scout-style-guide-tokens">' . scout_design_css() . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- Scout Core builds a CSS block from filtered values.
			}
		}

		/* ---------- rendering ---------- */

		public static function shortcode(): string {
			return self::html();
		}

		public static function html(): string {
			ob_start();
			self::render();
			return (string) ob_get_clean();
		}

		public static function render(): void {
			$d = function_exists( 'scout_design' ) ? scout_design() : array();
			echo '<div class="scout-style-guide ssg">';
			self::css();
			self::intro( $d );
			self::brief();
			self::color( $d );
			self::type( $d );
			self::space( $d );
			self::motion();
			self::buttons();
			self::parts();
			self::logo( $d );
			echo '</div>';
		}

		private static function h2( string $id, string $title ): void {
			echo '<h2 id="ssg-' . esc_attr( $id ) . '">' . esc_html( $title ) . '</h2>';
		}

		private static function note( string $text ): void {
			echo '<p class="ssg-note">' . esc_html( $text ) . '</p>';
		}

		private static function intro( array $d ): void {
			$name = self::business_name( $d );
			echo '<header class="ssg-intro">';
			echo '<p class="ssg-label">' . esc_html__( 'Style guide', 'scout' ) . '</p>';
			echo '<h1>' . esc_html( $name ? sprintf( /* translators: business name */ __( 'How %s looks and reads', 'scout' ), $name ) : __( 'How this site looks and reads', 'scout' ) ) . '</h1>';
			echo '<p>' . esc_html__( 'Every color, type size and part on this page is the real one: the theme styles it and the Scout plugins print it. Approve this first; the pages follow from it.', 'scout' ) . '</p>';
			echo '<nav class="ssg-nav" aria-label="' . esc_attr__( 'Sections', 'scout' ) . '"><ol>';
			foreach ( self::sections() as $id => $label ) {
				echo '<li><a href="#ssg-' . esc_attr( $id ) . '">' . esc_html( $label ) . '</a></li>';
			}
			echo '</ol></nav>';
			if ( ! empty( $d['sources'] ) ) {
				$files = array_map( 'basename', (array) $d['sources'] );
				echo '<p class="ssg-note">' . esc_html( sprintf( /* translators: file names */ __( 'Tokens read from %s by Scout Core.', 'scout' ), implode( ', ', $files ) ) ) . '</p>';
			} elseif ( ! function_exists( 'scout_design' ) ) {
				self::note( __( 'Scout Core is off, so the tokens are not read from DESIGN.md yet. Turn it on to fill in the color and type sections.', 'scout' ) );
			}
			echo '</header>';
		}

		/** @return array<string,string> */
		public static function sections(): array {
			return array(
				'brief'   => __( 'The brief', 'scout' ),
				'color'   => __( 'Color', 'scout' ),
				'type'    => __( 'Type', 'scout' ),
				'space'   => __( 'Space and shape', 'scout' ),
				'motion'  => __( 'Motion', 'scout' ),
				'buttons' => __( 'Buttons and links', 'scout' ),
				'parts'   => __( "Scout's parts", 'scout' ),
				'logo'    => __( 'Logo', 'scout' ),
			);
		}

		/* ---------- 1. the brief ---------- */

		private static function brief(): void {
			echo '<section class="ssg-section ssg-brief">';
			self::h2( 'brief', __( 'The brief', 'scout' ) );
			$blocks = self::brief_blocks( self::design_md() );
			if ( ! $blocks ) {
				self::note( __( 'DESIGN.md has no "## Brief" section yet. Add one with the register, the signature element and the list of what this site never does, and it prints here.', 'scout' ) );
				echo '</section>';
				return;
			}
			foreach ( $blocks as $block ) {
				if ( 'h3' === $block['type'] ) {
					echo '<h3>' . esc_html( $block['text'] ) . '</h3>';
				} elseif ( 'list' === $block['type'] ) {
					echo '<ul>';
					foreach ( $block['items'] as $item ) {
						echo '<li>' . esc_html( $item ) . '</li>';
					}
					echo '</ul>';
				} else {
					echo '<p>' . esc_html( $block['text'] ) . '</p>';
				}
			}
			echo '</section>';
		}

		/** The child theme's DESIGN.md, else the parent's. '' when there is none. */
		public static function design_md(): string {
			$dirs = array();
			if ( function_exists( 'get_stylesheet_directory' ) ) {
				$dirs[] = get_stylesheet_directory();
				$dirs[] = get_template_directory();
			}
			foreach ( array_unique( $dirs ) as $dir ) {
				$path = rtrim( $dir, '/' ) . '/DESIGN.md';
				if ( is_readable( $path ) ) {
					return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				}
			}
			return '';
		}

		/**
		 * The prose under "## Brief" as blocks: h3 for "### " lines, list for
		 * "- " runs, paragraphs for the rest. Ends at the next "## " heading.
		 *
		 * @return array<int,array{type:string,text?:string,items?:string[]}>
		 */
		public static function brief_blocks( string $md ): array {
			$md = str_replace( "\r\n", "\n", $md );
			if ( ! preg_match( '/^##\s+Brief\s*$\n(.*?)(?=^##\s|\z)/msi', $md, $m ) ) {
				return array();
			}
			$out  = array();
			$para = array();
			$list = array();
			$flush = static function () use ( &$out, &$para, &$list ) {
				if ( $para ) {
					$out[] = array( 'type' => 'p', 'text' => implode( ' ', $para ) );
					$para  = array();
				}
				if ( $list ) {
					$out[] = array( 'type' => 'list', 'items' => $list );
					$list  = array();
				}
			};
			foreach ( explode( "\n", $m[1] ) as $line ) {
				$line = rtrim( $line );
				if ( '' === trim( $line ) ) {
					$flush();
				} elseif ( preg_match( '/^###\s+(.+)$/', $line, $h ) ) {
					$flush();
					$out[] = array( 'type' => 'h3', 'text' => self::plain( $h[1] ) );
				} elseif ( preg_match( '/^\s*[-*]\s+(.+)$/', $line, $li ) ) {
					if ( $para ) {
						$flush();
					}
					$list[] = self::plain( $li[1] );
				} else {
					if ( $list ) {
						$flush();
					}
					$para[] = self::plain( $line );
				}
			}
			$flush();
			return $out;
		}

		/** Markdown emphasis and links dropped to plain words. */
		private static function plain( string $s ): string {
			$s = preg_replace( '/\[([^\]]+)\]\([^)]+\)/', '$1', $s );
			$s = preg_replace( '/(\*\*|__|\*|_|`)/', '', (string) $s );
			return trim( (string) $s );
		}

		/* ---------- 2. color ---------- */

		/** What each token is for, in the order the swatches print. */
		public static function roles(): array {
			return array(
				'bg'          => __( 'The page', 'scout' ),
				'surface'     => __( 'Cards and panels', 'scout' ),
				'surface_2'   => __( 'Inset areas inside a card', 'scout' ),
				'text'        => __( 'Body text and headings', 'scout' ),
				'text_2'      => __( 'Muted text: captions, hints', 'scout' ),
				'line'        => __( 'Hairlines and borders', 'scout' ),
				'accent'      => __( 'The one spark: links, focus rings, icons', 'scout' ),
				'accent_fill' => __( 'Button fill', 'scout' ),
				'on_accent'   => __( 'Text on a filled button', 'scout' ),
			);
		}

		/**
		 * The text that sits on each background. Each row: background token,
		 * text token, ratio, the ratio it needs. Ratio is 0 when a value cannot
		 * be measured (a translucent hairline).
		 *
		 * @return array<int,array{bg:string,fg:string,ratio:float,need:float,ok:bool}>
		 */
		public static function pairs( array $colors ): array {
			$on = array(
				'bg'          => array( 'text' => 4.5, 'text_2' => 4.5, 'accent' => 4.5 ),
				'surface'     => array( 'text' => 4.5, 'text_2' => 4.5, 'accent' => 4.5 ),
				'surface_2'   => array( 'text' => 4.5, 'text_2' => 4.5 ),
				'accent'      => array( 'on_accent' => 4.5 ),
				'accent_fill' => array( 'on_accent' => 4.5 ),
			);
			$out = array();
			foreach ( $on as $bg => $texts ) {
				if ( empty( $colors[ $bg ] ) ) {
					continue;
				}
				foreach ( $texts as $fg => $need ) {
					if ( empty( $colors[ $fg ] ) ) {
						continue;
					}
					$ratio = self::contrast( (string) $colors[ $fg ], (string) $colors[ $bg ] );
					$out[] = array( 'bg' => $bg, 'fg' => $fg, 'ratio' => $ratio, 'need' => $need, 'ok' => $ratio >= $need );
				}
			}
			return $out;
		}

		private static function contrast( string $a, string $b ): float {
			if ( function_exists( 'scout_design_contrast' ) ) {
				return (float) scout_design_contrast( $a, $b );
			}
			if ( class_exists( 'Scout_Core_Design', false ) ) {
				return (float) Scout_Core_Design::contrast( $a, $b );
			}
			return 0.0;
		}

		private static function color( array $d ): void {
			echo '<section class="ssg-section ssg-color">';
			self::h2( 'color', __( 'Color', 'scout' ) );
			$colors = (array) ( $d['colors'] ?? array() );
			if ( ! $colors ) {
				self::note( __( 'No tokens to show until Scout Core reads DESIGN.md.', 'scout' ) );
				echo '</section>';
				return;
			}
			echo '<p>' . esc_html__( 'Every token in DESIGN.md, with what it is for and how readable each text color is on it. Text needs 4.5:1 to pass.', 'scout' ) . '</p>';

			$issues = function_exists( 'scout_design_issues' ) ? (array) scout_design_issues() : array();
			$bad    = array_filter( $issues, static fn( $i ) => empty( $i['ok'] ) );
			if ( $issues ) {
				echo '<ul class="ssg-checks">';
				foreach ( $issues as $i ) {
					$state = ! empty( $i['ok'] ) ? __( 'passes', 'scout' ) : ( 'warn' === ( $i['level'] ?? '' ) ? __( 'low', 'scout' ) : __( 'fails', 'scout' ) );
					echo '<li class="' . ( ! empty( $i['ok'] ) ? 'is-ok' : 'is-bad' ) . '">' . esc_html( sprintf( '%s: %s:1, %s', $i['pair'], number_format( (float) $i['ratio'], 2 ), $state ) ) . '</li>';
				}
				echo '</ul>';
				if ( $bad ) {
					self::note( __( 'A failing pair above means DESIGN.md needs a change before the site goes live (rule 10).', 'scout' ) );
				}
			}

			$pairs = self::pairs( $colors );
			$roles = self::roles();
			echo '<ul class="ssg-swatches">';
			foreach ( $colors as $name => $value ) {
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				$var = '--scout-' . str_replace( '_', '-', $name );
				echo '<li class="ssg-swatch">';
				echo '<span class="ssg-chip" style="background:' . esc_attr( $value ) . '"></span>';
				echo '<strong>' . esc_html( $name ) . '</strong> <code>' . esc_html( $value ) . '</code> <code>' . esc_html( $var ) . '</code>';
				echo '<span class="ssg-role">' . esc_html( $roles[ $name ] ?? __( 'Named in DESIGN.md', 'scout' ) ) . '</span>';
				$rows = array_filter( $pairs, static fn( $p ) => $p['bg'] === $name );
				if ( $rows ) {
					echo '<ul class="ssg-pairs">';
					foreach ( $rows as $p ) {
						$fg = (string) $colors[ $p['fg'] ];
						echo '<li class="' . ( $p['ok'] ? 'is-ok' : 'is-bad' ) . '">';
						echo '<span class="ssg-sample" style="background:' . esc_attr( $value ) . ';color:' . esc_attr( $fg ) . '">' . esc_html( $p['fg'] ) . '</span> ';
						echo esc_html( $p['ratio'] > 0 ? number_format( $p['ratio'], 2 ) . ':1 ' . ( $p['ok'] ? __( 'passes', 'scout' ) : __( 'fails', 'scout' ) ) : __( 'not measurable', 'scout' ) );
						echo '</li>';
					}
					echo '</ul>';
				}
				echo '</li>';
			}
			echo '</ul>';
			echo '</section>';
		}

		/* ---------- 3. type ---------- */

		private static function type( array $d ): void {
			echo '<section class="ssg-section ssg-type">';
			self::h2( 'type', __( 'Type', 'scout' ) );
			$fonts = (array) ( $d['fonts'] ?? array() );
			if ( $fonts ) {
				echo '<ul class="ssg-fonts">';
				foreach ( array( 'heading', 'body', 'label' ) as $role ) {
					$f = (array) ( $fonts[ $role ] ?? array() );
					if ( empty( $f['family'] ) ) {
						continue;
					}
					$bits = array( (string) $f['family'] );
					if ( ! empty( $f['weight'] ) ) {
						$bits[] = (string) (int) $f['weight'];
					}
					if ( ! empty( $f['case'] ) ) {
						$bits[] = (string) $f['case'];
					}
					if ( ! empty( $f['tracking'] ) ) {
						$bits[] = (string) $f['tracking'];
					}
					$bits[] = ! empty( $f['url'] ) ? __( 'self-hosted file', 'scout' ) : __( 'system or theme-loaded', 'scout' );
					echo '<li><strong>' . esc_html( ucfirst( $role ) ) . '</strong> ' . esc_html( implode( ', ', $bits ) ) . '</li>';
				}
				echo '</ul>';
			}
			$name = self::business_name( $d );
			$name = $name ? $name : __( 'This business', 'scout' );
			echo '<div class="ssg-scale">';
			echo '<p class="ssg-label">' . esc_html__( 'Section label', 'scout' ) . '</p>';
			echo '<h1>' . esc_html( sprintf( /* translators: business name */ __( '%s does the work and stands behind it.', 'scout' ), $name ) ) . '</h1>';
			echo '<h2>' . esc_html__( 'What to expect on the first call', 'scout' ) . '</h2>';
			echo '<h3>' . esc_html__( 'A short answer to a real question', 'scout' ) . '</h3>';
			echo '<h4>' . esc_html__( 'The small print, still readable', 'scout' ) . '</h4>';
			echo '<p>' . esc_html__( 'This is body text at its normal size. It carries most of what a visitor reads, so it has to stay comfortable for a few paragraphs at a time, on a phone held at arm\'s length and on a wide screen alike. Numbers sit in it too: 12 years, 3 locations, 1 call to start.', 'scout' ) . '</p>';
			echo '<p><a href="#ssg-type">' . esc_html__( 'A link inside a sentence looks like this', 'scout' ) . '</a>, ' . esc_html__( 'and it should read as a link without the color alone doing the work.', 'scout' ) . '</p>';
			echo '<p><small>' . esc_html__( 'Small text is for captions and legal notes, never for anything a visitor must read to act.', 'scout' ) . '</small></p>';
			echo '</div>';
			echo '</section>';
		}

		/* ---------- 4. space and shape ---------- */

		private static function space( array $d ): void {
			echo '<section class="ssg-section ssg-space">';
			self::h2( 'space', __( 'Space and shape', 'scout' ) );
			echo '<p>' . esc_html__( 'The spacing steps the layout is built from, and the corner radius of each kind of shape.', 'scout' ) . '</p>';
			echo '<ol class="ssg-steps" aria-label="' . esc_attr__( 'Spacing steps', 'scout' ) . '">';
			foreach ( array( 4, 8, 12, 16, 24, 32, 48, 64 ) as $px ) {
				echo '<li><span class="ssg-step" style="width:' . (int) $px . 'px"></span><code>' . (int) $px . 'px</code></li>';
			}
			echo '</ol>';
			$radius = (array) ( $d['radius'] ?? array() );
			$labels = array(
				'pill'    => __( 'Pill: buttons and chips that read as actions', 'scout' ),
				'card'    => __( 'Card: panels, images, review cards', 'scout' ),
				'control' => __( 'Control: inputs and small boxes', 'scout' ),
				'chip'    => __( 'Chip: tags and badges', 'scout' ),
			);
			if ( $radius ) {
				echo '<ul class="ssg-shapes">';
				foreach ( $radius as $name => $value ) {
					echo '<li><span class="ssg-shape" style="border-radius:' . esc_attr( (string) $value ) . '"></span><strong>' . esc_html( (string) $name ) . '</strong> <code>' . esc_html( (string) $value ) . '</code><span class="ssg-role">' . esc_html( $labels[ $name ] ?? '' ) . '</span></li>';
				}
				echo '</ul>';
			}
			echo '<p class="ssg-rule" aria-hidden="true"></p>';
			echo '<p class="ssg-note">' . esc_html__( 'The line above is the hairline every divider and border uses.', 'scout' ) . '</p>';
			echo '</section>';
		}

		/* ---------- 5. motion ---------- */

		private static function motion(): void {
			echo '<section class="ssg-section ssg-motion">';
			self::h2( 'motion', __( 'Motion', 'scout' ) );
			echo '<p>' . esc_html__( 'Movement is short and tied to something the visitor did: a card lifts on hover, a sheet slides up when tapped. Nothing loops and nothing moves on its own.', 'scout' ) . '</p>';
			echo '<div class="ssg-motion-card" tabindex="0"><strong>' . esc_html__( 'Hover or focus this card', 'scout' ) . '</strong><span>' . esc_html__( 'It lifts a few pixels in under a fifth of a second.', 'scout' ) . '</span></div>';
			echo '<p class="ssg-motion-on">' . esc_html__( 'Motion is on for this device.', 'scout' ) . '</p>';
			echo '<p class="ssg-motion-off">' . esc_html__( 'Reduced motion is on for this device, so nothing on the site moves. This is the state visitors with that setting get everywhere.', 'scout' ) . '</p>';
			echo '</section>';
		}

		/* ---------- 6. buttons and links ---------- */

		private static function buttons(): void {
			echo '<section class="ssg-section ssg-buttons">';
			self::h2( 'buttons', __( 'Buttons and links', 'scout' ) );
			echo '<p>' . esc_html__( 'The live row uses the theme\'s own styles: hover it, tab to it, press it. The rows below hold each state still so it can be checked on a phone.', 'scout' ) . '</p>';
			$states = array(
				''           => __( 'Rest', 'scout' ),
				'is-hover'   => __( 'Hover', 'scout' ),
				'is-focus'   => __( 'Focus', 'scout' ),
				'is-pressed' => __( 'Pressed', 'scout' ),
			);
			foreach ( $states as $class => $label ) {
				echo '<div class="ssg-row ' . esc_attr( $class ) . '"><span class="ssg-row-label">' . esc_html( $label ) . '</span>';
				echo '<a class="wp-element-button wp-block-button__link ssg-btn" href="#ssg-buttons">' . esc_html__( 'Primary button', 'scout' ) . '</a>';
				echo '<button type="button" class="ssg-btn ssg-btn-quiet">' . esc_html__( 'Quiet button', 'scout' ) . '</button>';
				echo '<a class="ssg-link" href="#ssg-buttons">' . esc_html__( 'A text link', 'scout' ) . '</a>';
				echo '</div>';
			}
			echo '</section>';
		}

		/* ---------- 7. Scout's parts ---------- */

		private static function parts(): void {
			echo '<section class="ssg-section ssg-parts">';
			self::h2( 'parts', __( "Scout's parts", 'scout' ) );
			echo '<p>' . esc_html__( 'Each part below is printed by the plugin that owns it, with this theme\'s styles on top. A part that is missing here is a plugin that is not on yet.', 'scout' ) . '</p>';

			self::part( __( 'Call buttons', 'scout' ), __( 'The bar visitors see at the bottom of a phone screen.', 'scout' ), array( __CLASS__, 'call_bar' ), self::core_owns( 'call_bar' ) || class_exists( 'Scout_Contact', false ) );
			self::part( __( 'Reviews', 'scout' ), __( 'The reviews slot, with the home page set.', 'scout' ), static function () {
				scout_reviews_slot( array( 'topic' => 'home', 'count' => 3 ) );
			}, function_exists( 'scout_reviews_slot' ) );
			self::part( __( 'Hours', 'scout' ), __( 'The open or closed line.', 'scout' ), static function () {
				echo scout_hours_html( array( 'show' => 'status' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- Scout Hours escapes its own output.
			}, function_exists( 'scout_hours_html' ) );
			self::part( __( 'People', 'scout' ), __( 'One person card from the team.', 'scout' ), static function () {
				echo do_shortcode( '[scout_team limit="1" columns="1"]' );
			}, shortcode_exists( 'scout_team' ) );
			if ( defined( 'SCOUT_LAW_VERSION' ) || class_exists( 'Scout_Law_Front', false ) ) {
				self::part( __( 'Case results', 'scout' ), __( 'One approved result, as the law plugin prints it.', 'scout' ), static function () {
					echo do_shortcode( '[scout_results limit="1" filters="no"]' );
				}, shortcode_exists( 'scout_results' ) );
			}
			self::part( __( 'AI search questions', 'scout' ), __( 'The questions and answers box.', 'scout' ), array( __CLASS__, 'faq' ), shortcode_exists( 'scout_faq' ) );
			self::part( __( 'Form', 'scout' ), __( 'The first Gravity Forms form on the site.', 'scout' ), array( __CLASS__, 'form' ), class_exists( 'GFAPI', false ) && function_exists( 'gravity_form' ) );
			self::part( __( 'Accessibility statement', 'scout' ), __( 'The statement the footer links to.', 'scout' ), static function () {
				echo do_shortcode( '[scout_accessibility_statement]' );
			}, shortcode_exists( 'scout_accessibility_statement' ) );
			echo '<p class="ssg-note">' . esc_html__( 'The footer below this page is the real one.', 'scout' ) . '</p>';
			echo '</section>';
		}

		/** One part: heading, what it is, then the plugin's own output or a plain line saying the plugin is off. */
		private static function part( string $title, string $what, callable $print, bool $on ): void {
			echo '<div class="ssg-part">';
			echo '<h3>' . esc_html( $title ) . '</h3>';
			echo '<p class="ssg-role">' . esc_html( $what ) . '</p>';
			if ( ! $on ) {
				echo '<p class="ssg-off">' . esc_html( sprintf( /* translators: part name */ __( '%s: the plugin is not on for this site.', 'scout' ), $title ) ) . '</p>';
				echo '</div>';
				return;
			}
			ob_start();
			$print();
			$html = trim( (string) ob_get_clean() );
			if ( '' === $html ) {
				echo '<p class="ssg-off">' . esc_html__( 'The plugin is on but has nothing to show yet. Add the content in Scout and it prints here.', 'scout' ) . '</p>';
			} else {
				echo '<div class="ssg-part-body">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- each plugin escapes what it prints.
			}
			echo '</div>';
		}

		/**
		 * The call bar as Scout Contact prints it, held in a phone-width frame.
		 * The sheet and its open button are left to the real bar in the footer,
		 * so the page has one dialog and one set of hooks for the plugin's script.
		 */
		public static function call_bar(): void {
			if ( ! method_exists( 'Scout_Contact', 'active' ) || ! Scout_Contact::active() ) {
				echo '<p class="ssg-off">' . esc_html__( 'The bar is off on this page: add a phone in Business info or turn it on under Scout, Call buttons.', 'scout' ) . '</p>';
				return;
			}
			ob_start();
			Scout_Contact::render();
			$html = (string) ob_get_clean();
			$html = (string) preg_replace( '#<dialog\b.*?</dialog>#s', '', $html );
			$html = str_replace( array( ' data-scc>', ' data-scc-open', ' aria-haspopup="dialog" aria-controls="scc-sheet"' ), array( '>', '', '' ), $html );
			echo '<div class="ssg-phone"><div class="ssg-phone-screen">' . $html . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Scout Contact escapes its own markup.
		}

		/** This page's own answers first; else the first page that has some, so the box is never empty for no reason. */
		public static function faq(): void {
			$html = do_shortcode( '[scout_faq]' );
			if ( '' === trim( (string) $html ) && class_exists( 'Scout_AEO_FAQ', false ) && method_exists( 'Scout_AEO_FAQ', 'pages_with_answers' ) ) {
				foreach ( Scout_AEO_FAQ::pages_with_answers( 1 ) as $id ) {
					$html = Scout_AEO_FAQ::html( (int) $id );
				}
			}
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- Scout AI Search escapes its own output.
		}

		public static function form(): void {
			foreach ( (array) GFAPI::get_forms( true ) as $form ) {
				$id = (int) ( $form['id'] ?? 0 );
				if ( $id > 0 ) {
					echo gravity_form( $id, false, false, false, null, true, 0, false ); // phpcs:ignore WordPress.Security.EscapeOutput -- Gravity Forms markup.
					return;
				}
			}
		}

		/* ---------- 8. logo ---------- */

		private static function logo( array $d ): void {
			echo '<section class="ssg-section ssg-logo">';
			self::h2( 'logo', __( 'Logo', 'scout' ) );
			$logo = (string) ( $d['logo_url'] ?? '' );
			$mark = (string) ( $d['mark_url'] ?? '' );
			if ( '' === $logo && '' === $mark ) {
				self::note( __( 'No logo or mark is named in DESIGN.md yet (logo: and mark:). Add them and they print here on the page, on ink and on the accent.', 'scout' ) );
				echo '</section>';
				return;
			}
			$tiles = array(
				'ssg-on-page'   => __( 'On the page', 'scout' ),
				'ssg-on-ink'    => __( 'On ink', 'scout' ),
				'ssg-on-accent' => __( 'On the accent', 'scout' ),
			);
			foreach ( array( 'logo' => $logo, 'mark' => $mark ) as $kind => $url ) {
				if ( '' === $url ) {
					continue;
				}
				echo '<h3>' . esc_html( 'logo' === $kind ? __( 'Logo', 'scout' ) : __( 'Mark', 'scout' ) ) . '</h3>';
				echo '<ul class="ssg-tiles">';
				foreach ( $tiles as $class => $label ) {
					echo '<li class="ssg-tile ' . esc_attr( $class ) . '"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( sprintf( '%s, %s', $kind, $label ) ) . '" loading="lazy" /><span>' . esc_html( $label ) . '</span></li>';
				}
				echo '</ul>';
			}
			echo '</section>';
		}

		/* ---------- helpers ---------- */

		private static function business_name( array $d ): string {
			$name = '';
			if ( function_exists( 'scout_core_business' ) ) {
				$name = trim( (string) ( ( (array) scout_core_business() )['name'] ?? '' ) );
			}
			if ( '' === $name ) {
				$name = trim( (string) ( $d['name'] ?? '' ) );
			}
			if ( '' === $name && function_exists( 'get_bloginfo' ) ) {
				$name = trim( (string) get_bloginfo( 'name' ) );
			}
			return $name;
		}

		private static function core_owns( string $job ): bool {
			return function_exists( 'scout_core_owns' ) && scout_core_owns( $job );
		}

		/**
		 * Layout only. Colors, fonts and radii come from the --scout-* variables
		 * the theme (or Scout Core, as a fallback in the head) defines, so the
		 * guide shows the site, never its own palette.
		 */
		private static function css(): void {
			?>
<style id="scout-style-guide-css">
.ssg { max-width: 72rem; margin: 0 auto; padding: 0 1rem 4rem; }
.ssg .ssg-section { padding: 3rem 0; border-top: 1px solid var(--scout-line, currentColor); }
.ssg .ssg-intro { padding: 2rem 0 3rem; }
.ssg .ssg-label { font-family: var(--scout-font-label, inherit); font-weight: var(--scout-weight-label, 500); text-transform: uppercase; letter-spacing: .1em; font-size: .8em; color: var(--scout-text-2, inherit); margin: 0 0 .5rem; }
.ssg .ssg-note, .ssg .ssg-role, .ssg .ssg-off { color: var(--scout-text-2, inherit); font-size: .95em; }
.ssg .ssg-off { font-style: italic; }
.ssg .ssg-nav ol { display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; list-style: none; margin: 1rem 0 0; padding: 0; }
.ssg code { font-family: var(--scout-font-label, ui-monospace, monospace); font-size: .85em; }
.ssg .ssg-checks { list-style: none; padding: 0; margin: 0 0 2rem; display: grid; gap: .35rem; }
.ssg .ssg-checks li::before { content: "\2713\00a0"; }
.ssg .ssg-checks li.is-bad::before { content: "\2717\00a0"; }
.ssg .ssg-swatches { list-style: none; margin: 0; padding: 0; display: grid; gap: 1.25rem; grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr)); }
.ssg .ssg-swatch { display: grid; gap: .35rem; align-content: start; }
.ssg .ssg-chip { display: block; height: 5rem; border-radius: var(--scout-radius-card, 1rem); border: 1px solid var(--scout-line, currentColor); }
.ssg .ssg-pairs { list-style: none; margin: .25rem 0 0; padding: 0; display: grid; gap: .25rem; font-size: .9em; }
.ssg .ssg-sample { display: inline-block; min-width: 5.5rem; padding: .2rem .5rem; border-radius: var(--scout-radius-chip, .5rem); border: 1px solid var(--scout-line, currentColor); font-family: var(--scout-font-label, inherit); font-size: .85em; }
.ssg .ssg-pairs li.is-bad { font-weight: 600; }
.ssg .ssg-fonts { list-style: none; padding: 0; margin: 0 0 2rem; display: grid; gap: .35rem; }
.ssg .ssg-scale > * + * { margin-top: 1rem; }
.ssg .ssg-steps { list-style: none; margin: 0 0 2rem; padding: 0; display: grid; gap: .5rem; }
.ssg .ssg-steps li { display: flex; align-items: center; gap: .75rem; }
.ssg .ssg-step { display: inline-block; height: 1rem; background: var(--scout-accent, currentColor); border-radius: 2px; }
.ssg .ssg-shapes { list-style: none; margin: 0 0 2rem; padding: 0; display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); }
.ssg .ssg-shapes li { display: grid; gap: .35rem; }
.ssg .ssg-shape { display: block; width: 6rem; height: 3rem; background: var(--scout-surface-2, transparent); border: 2px solid var(--scout-accent, currentColor); }
.ssg .ssg-rule { height: 0; border: 0; border-top: 1px solid var(--scout-line, currentColor); margin: 0; }
.ssg .ssg-motion-card { display: grid; gap: .25rem; max-width: 24rem; padding: 1.25rem; border-radius: var(--scout-radius-card, 1rem); background: var(--scout-surface, transparent); border: 1px solid var(--scout-line, currentColor); transition: transform .18s ease, box-shadow .18s ease; }
.ssg .ssg-motion-card:hover, .ssg .ssg-motion-card:focus-visible { transform: translateY(-4px); box-shadow: 0 .75rem 1.5rem rgba(0,0,0,.12); }
.ssg .ssg-motion-card:focus-visible { outline: 2px solid var(--scout-accent, currentColor); outline-offset: 2px; }
.ssg .ssg-motion-off { display: none; }
@media (prefers-reduced-motion: reduce) {
	.ssg .ssg-motion-card { transition: none; }
	.ssg .ssg-motion-card:hover, .ssg .ssg-motion-card:focus-visible { transform: none; }
	.ssg .ssg-motion-on { display: none; }
	.ssg .ssg-motion-off { display: block; }
}
.ssg .ssg-row { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; padding: 1rem 0; border-top: 1px solid var(--scout-line, currentColor); }
.ssg .ssg-row-label { flex: 0 0 5rem; font-family: var(--scout-font-label, inherit); font-size: .8em; text-transform: uppercase; letter-spacing: .1em; color: var(--scout-text-2, inherit); }
.ssg .ssg-btn { display: inline-flex; align-items: center; min-height: 2.75rem; padding: 0 1.25rem; border-radius: var(--scout-radius-pill, 980px); font: inherit; font-weight: 500; text-decoration: none; cursor: pointer; }
.ssg a.ssg-btn:not(.ssg-btn-quiet) { background: var(--scout-accent-fill, currentColor); color: var(--scout-on-accent, inherit); border: 1px solid transparent; }
.ssg .ssg-btn-quiet { background: transparent; color: var(--scout-text, inherit); border: 1px solid var(--scout-line, currentColor); }
.ssg .ssg-link { color: var(--scout-accent, inherit); }
.ssg .is-hover .ssg-btn { filter: brightness(1.08); }
.ssg .is-hover .ssg-btn-quiet { border-color: var(--scout-accent, currentColor); }
.ssg .is-hover .ssg-link { text-decoration-thickness: 2px; }
.ssg .is-focus .ssg-btn, .ssg .is-focus .ssg-link { outline: 2px solid var(--scout-accent, currentColor); outline-offset: 2px; }
.ssg .is-pressed .ssg-btn { filter: brightness(.92); transform: translateY(1px); }
.ssg .ssg-part { padding: 1.5rem 0; }
.ssg .ssg-part + .ssg-part { border-top: 1px solid var(--scout-line, currentColor); }
.ssg .ssg-part-body { margin-top: 1rem; }
.ssg .ssg-phone { width: 100%; max-width: 24.5rem; padding: 1rem .75rem 1.25rem; border-radius: var(--scout-radius-card, 1.5rem); border: 1px solid var(--scout-line, currentColor); background: var(--scout-surface-2, transparent); }
.ssg .ssg-phone-screen { min-height: 7rem; display: flex; align-items: flex-end; }
.ssg .ssg-phone .scc { display: block; width: 100%; }
.ssg .ssg-phone .scc-bar { position: static; }
.ssg .ssg-tiles { list-style: none; margin: 0 0 2rem; padding: 0; display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr)); }
.ssg .ssg-tile { display: grid; gap: .75rem; justify-items: center; padding: 2rem 1.5rem; border-radius: var(--scout-radius-card, 1rem); border: 1px solid var(--scout-line, currentColor); }
.ssg .ssg-tile img { max-width: 100%; max-height: 5rem; width: auto; height: auto; }
.ssg .ssg-tile span { font-size: .85em; }
.ssg .ssg-on-page { background: var(--scout-bg, transparent); color: var(--scout-text, inherit); }
.ssg .ssg-on-ink { background: var(--scout-text, currentColor); color: var(--scout-bg, inherit); }
.ssg .ssg-on-accent { background: var(--scout-accent-fill, currentColor); color: var(--scout-on-accent, inherit); }
</style>
			<?php
		}
	}

	if ( function_exists( 'add_action' ) ) {
		Scout_Theme_Style_Guide::boot();
	}
}

if ( ! function_exists( 'scout_style_guide' ) ) {
	/** Print the style guide sections. Templates call this between the theme's header and footer. */
	function scout_style_guide(): void {
		Scout_Theme_Style_Guide::render();
	}
}

if ( ! function_exists( 'scout_style_guide_html' ) ) {
	/** The same sections, returned instead of printed. */
	function scout_style_guide_html(): string {
		return Scout_Theme_Style_Guide::html();
	}
}
