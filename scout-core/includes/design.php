<?php
/**
 * The site's design, read from the theme's DESIGN.md (Site Standard §4), and
 * which jobs a plugin owns on this site (rule 6).
 *
 *   $d = scout_design();                 // tokens: mode, colors, fonts, radius, type, space, motion, logo, mark
 *   $d = scout_design( 'call buttons' ); // the "## Feature: call buttons" section merged over the tokens
 *   echo scout_design_css();             // a :root block of --scout-* variables for inline styles
 *   scout_design_issues();               // contrast pairs that fail (rule 10)
 *   scout_core_owns( 'hours' );          // true when a plugin does this job here
 *
 * Order of truth: Scout's defaults, then the active theme's theme.json palette
 * and fonts, then the parent theme's DESIGN.md, then the child theme's. A theme
 * without DESIGN.md still gets sensible tokens, so a plugin can always call
 * scout_design() and never carries its own color picker for what DESIGN.md says.
 *
 * The front matter is a small YAML subset (nested maps by two-space indent,
 * inline { a: b } maps, quoted strings, # comments), parsed here so hosts need
 * no YAML extension. Feature sections are "## Feature: <name>" headings with
 * "key: value" lines (or "- key: value" bullets) under them.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Design {

	const FILE = 'DESIGN.md';

	/** @var array<string,array>|null Parsed result for this request, keyed by stylesheet. */
	private static $cache = array();

	/* ---------- public API ---------- */

	/** The merged tokens, with a feature section laid over them when asked for. */
	public static function get( string $feature = '' ): array {
		$all    = self::all();
		$tokens = $all['tokens'];
		if ( '' !== $feature ) {
			$key = self::key( $feature );
			$sec = $all['features'][ $key ] ?? array();
			$tokens['feature'] = $sec;
			foreach ( $sec as $k => $v ) {
				// A feature line named like a token (accent, surface, radius) overrides it.
				if ( isset( $tokens['colors'][ $k ] ) && is_string( $v ) ) {
					$tokens['colors'][ $k ] = $v;
				} elseif ( isset( $tokens['radius'][ $k ] ) && is_string( $v ) ) {
					$tokens['radius'][ $k ] = $v;
				}
			}
		}
		/**
		 * Filters the design tokens a plugin reads.
		 *
		 * @param array  $tokens  The merged tokens.
		 * @param string $feature The feature asked for, or ''.
		 */
		return (array) apply_filters( 'scout_design', $tokens, $feature );
	}

	/** Everything parsed: tokens plus every feature section. Cached per request. */
	public static function all(): array {
		$sheet = function_exists( 'get_stylesheet' ) ? get_stylesheet() : 'none';
		if ( isset( self::$cache[ $sheet ] ) ) {
			return self::$cache[ $sheet ];
		}
		$tokens   = self::defaults();
		$features = array();
		$sources  = array();
		$named    = self::from_theme_json();
		$tokens   = self::merge( $tokens, $named );
		$dirs     = array();
		if ( function_exists( 'get_template_directory' ) ) {
			$dirs[] = get_template_directory();
			if ( get_stylesheet_directory() !== get_template_directory() ) {
				$dirs[] = get_stylesheet_directory(); // Child last, so it wins.
			}
		}
		foreach ( $dirs as $dir ) {
			$parsed = self::file( rtrim( $dir, '/' ) . '/' . self::FILE );
			if ( null === $parsed ) {
				continue;
			}
			$sources[] = $dir . '/' . self::FILE;
			$tokens    = self::merge( $tokens, $parsed['tokens'] );
			$named     = self::merge( $named, $parsed['tokens'] );
			$features  = array_merge( $features, $parsed['features'] );
		}
		$tokens            = self::derive( $tokens, (array) ( $named['colors'] ?? array() ) );
		$tokens['sources'] = $sources;
		$tokens            = self::resolve_files( $tokens, $dirs );
		self::$cache[ $sheet ] = array( 'tokens' => $tokens, 'features' => $features );
		return self::$cache[ $sheet ];
	}

	/** Forget the parsed design (after a theme switch or update). */
	public static function flush(): void {
		self::$cache = array();
	}

	/** Scout's own tokens: a light site with the cerulean spark. */
	public static function defaults(): array {
		return array(
			'design' => 1,
			'name'   => '',
			'mode'   => 'light',
			'colors' => array(
				'bg'          => '#F5F5F7',
				'surface'     => '#FFFFFF',
				'surface_2'   => '#EDEDF0',
				'text'        => '#1D1D1F',
				'text_2'      => '#515154',
				'line'        => 'rgba(0,0,0,.08)',
				'accent'      => '#0E8FE6',
				'accent_fill' => '#0A78C2',
				'on_accent'   => '#FFFFFF',
				// A dark band (a footer, a quote strip) and its text.
				'night'          => '#0B0B0B',
				'on_night'       => '#F5F5F7',
				'on_night_2'     => '#B5B5B8',
				// A second brand color and a shade of it that reads as text. Both follow
				// the accent unless DESIGN.md names them (see derive()).
				'highlight'      => '#0E8FE6',
				'highlight_text' => '#0A78C2',
				// Meaning colors: a pass, a caution, a problem.
				'good'           => '#0E9E73',
				'warn'           => '#FBB024',
				'bad'            => '#F2553D',
			),
			'fonts'  => array(
				'heading' => array( 'family' => 'Hanken Grotesk', 'weight' => 600, 'file' => '' ),
				'body'    => array( 'family' => 'Hanken Grotesk', 'weight' => 400, 'file' => '' ),
				'label'   => array( 'family' => 'Geist Mono', 'weight' => 500, 'file' => '', 'case' => 'upper', 'tracking' => '1.4px' ),
			),
			'radius' => array( 'pill' => '980px', 'card' => '18px', 'control' => '12px', 'chip' => '8px' ),
			// The type scale, as CSS lengths (clamp() welcome).
			'type'   => array(
				'hero'  => 'clamp(40px, 7vw, 72px)',
				'h2'    => 'clamp(30px, 4.4vw, 46px)',
				'h3'    => 'clamp(22px, 2.6vw, 28px)',
				'body'  => '17px',
				'small' => '15px',
				'label' => '12px',
			),
			// Spacing on an 8px scale, plus the page width and its side gutter.
			'space'  => array(
				'1'         => '8px',
				'2'         => '16px',
				'3'         => '24px',
				'4'         => '32px',
				'5'         => '40px',
				'6'         => '48px',
				'7'         => '56px',
				'8'         => '64px',
				'container' => '1160px',
				'gutter'    => '24px',
			),
			// How long and how things move.
			'motion' => array(
				'dur_fast'  => '200ms',
				'dur_enter' => '280ms',
				'ease_out'  => 'cubic-bezier(.2,.8,.2,1)',
				'ease_in'   => 'cubic-bezier(.4,0,1,1)',
			),
			'logo'   => '',
			'mark'   => '',
		);
	}

	/* ---------- the file ---------- */

	/**
	 * Parse one DESIGN.md: the front-matter tokens and the "## Feature:" sections.
	 * Null when the file is missing or unreadable.
	 *
	 * @return array{tokens:array,features:array<string,array<string,string>>}|null
	 */
	public static function file( string $path ): ?array {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$text = (string) file_get_contents( $path );
		return self::parse( $text );
	}

	/** Parse DESIGN.md text (the file() body, split out so it can be tested without a file). */
	public static function parse( string $text ): array {
		$text   = str_replace( "\r\n", "\n", $text );
		$tokens = array();
		$body   = $text;
		if ( preg_match( '/\A---\n(.*?)\n---\n?(.*)\z/s', $text, $m ) ) {
			$tokens = self::parse_front_matter( $m[1] );
			$body   = $m[2];
		}
		return array( 'tokens' => $tokens, 'features' => self::parse_features( $body ) );
	}

	/** "## Feature: call buttons" sections: key: value lines until the next heading. */
	public static function parse_features( string $body ): array {
		$out     = array();
		$current = '';
		foreach ( explode( "\n", $body ) as $line ) {
			if ( preg_match( '/^#{2,3}\s*Feature:\s*(.+?)\s*(\(.*\))?\s*$/i', $line, $m ) ) {
				$current         = self::key( $m[1] );
				$out[ $current ] = $out[ $current ] ?? array();
				continue;
			}
			if ( preg_match( '/^#{1,6}\s/', $line ) ) {
				$current = '';
				continue;
			}
			if ( '' === $current ) {
				continue;
			}
			if ( preg_match( '/^\s*(?:[-*]\s+)?([A-Za-z][\w .-]*?)\s*:\s*(.+?)\s*$/', $line, $m ) ) {
				$out[ $current ][ self::key( $m[1] ) ] = self::scalar( $m[2] );
			}
		}
		return $out;
	}

	/** A small YAML subset: nested maps by indent, inline maps, quoted strings, comments. */
	public static function parse_front_matter( string $yaml ): array {
		$lines = array();
		foreach ( explode( "\n", $yaml ) as $raw ) {
			$line = self::strip_comment( $raw );
			if ( '' === trim( $line ) ) {
				continue;
			}
			$indent  = strlen( $line ) - strlen( ltrim( $line, ' ' ) );
			$lines[] = array( $indent, trim( $line ) );
		}
		$i = 0;
		return self::parse_block( $lines, $i, -1 );
	}

	/** @param array<int,array{0:int,1:string}> $lines */
	private static function parse_block( array $lines, int &$i, int $parent_indent ): array {
		$out = array();
		while ( $i < count( $lines ) ) {
			list( $indent, $text ) = $lines[ $i ];
			if ( $indent <= $parent_indent ) {
				break;
			}
			++$i;
			if ( ! preg_match( '/^([A-Za-z0-9_][\w-]*)\s*:\s*(.*)$/', $text, $m ) ) {
				continue; // Lists and odd lines are not part of the token format.
			}
			$key   = self::key( $m[1] );
			$value = trim( $m[2] );
			if ( '' === $value ) {
				$out[ $key ] = self::parse_block( $lines, $i, $indent ); // A nested map follows.
			} elseif ( '{' === $value[0] ) {
				$out[ $key ] = self::parse_inline( $value );
			} else {
				$out[ $key ] = self::scalar( $value );
			}
		}
		return $out;
	}

	/** { family: "Hanken Grotesk", weight: 600 } */
	private static function parse_inline( string $value ): array {
		$out   = array();
		$inner = trim( $value );
		$inner = preg_replace( '/^\{|\}$/', '', $inner );
		foreach ( self::split_top( (string) $inner ) as $pair ) {
			if ( preg_match( '/^\s*([A-Za-z_][\w-]*)\s*:\s*(.*?)\s*$/', $pair, $m ) ) {
				$out[ self::key( $m[1] ) ] = self::scalar( $m[2] );
			}
		}
		return $out;
	}

	/** Split "a: 1, b: \"x, y\"" on commas outside quotes. */
	private static function split_top( string $s ): array {
		$parts = array();
		$buf   = '';
		$q     = '';
		for ( $n = 0, $len = strlen( $s ); $n < $len; $n++ ) {
			$c = $s[ $n ];
			if ( '' !== $q ) {
				$buf .= $c;
				if ( $c === $q ) {
					$q = '';
				}
			} elseif ( '"' === $c || "'" === $c ) {
				$q    = $c;
				$buf .= $c;
			} elseif ( ',' === $c ) {
				$parts[] = $buf;
				$buf     = '';
			} else {
				$buf .= $c;
			}
		}
		if ( '' !== trim( $buf ) ) {
			$parts[] = $buf;
		}
		return $parts;
	}

	/** Drop a "# comment" that is not inside quotes. */
	private static function strip_comment( string $line ): string {
		$q = '';
		for ( $n = 0, $len = strlen( $line ); $n < $len; $n++ ) {
			$c = $line[ $n ];
			if ( '' !== $q ) {
				if ( $c === $q ) {
					$q = '';
				}
			} elseif ( '"' === $c || "'" === $c ) {
				$q = $c;
			} elseif ( '#' === $c && ( 0 === $n || ' ' === $line[ $n - 1 ] || "\t" === $line[ $n - 1 ] ) ) {
				return rtrim( substr( $line, 0, $n ) );
			}
		}
		return rtrim( $line );
	}

	/** Unquote a scalar; plain numbers become numbers; true/false become booleans. */
	private static function scalar( string $v ) {
		$v = trim( $v );
		if ( strlen( $v ) >= 2 && ( ( '"' === $v[0] && '"' === substr( $v, -1 ) ) || ( "'" === $v[0] && "'" === substr( $v, -1 ) ) ) ) {
			return substr( $v, 1, -1 );
		}
		if ( preg_match( '/^-?\d+$/', $v ) ) {
			return (int) $v;
		}
		if ( preg_match( '/^-?\d*\.\d+$/', $v ) ) {
			return (float) $v;
		}
		if ( in_array( strtolower( $v ), array( 'true', 'yes', 'on' ), true ) ) {
			return true;
		}
		if ( in_array( strtolower( $v ), array( 'false', 'no', 'off' ), true ) ) {
			return false;
		}
		return $v;
	}

	/** "Call buttons" and "call-buttons" both become call_buttons. */
	public static function key( string $s ): string {
		return trim( preg_replace( '/[^a-z0-9]+/', '_', strtolower( $s ) ), '_' );
	}

	/* ---------- merging and fallbacks ---------- */

	/** Nested merge where the second array wins; empty strings in it never erase a value. */
	public static function merge( array $base, array $over ): array {
		foreach ( $over as $k => $v ) {
			if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) ) {
				$base[ $k ] = self::merge( $base[ $k ], $v );
			} elseif ( ! ( '' === $v || null === $v ) ) {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}

	/**
	 * Fill the highlight pair from the accent when no source named one, so a
	 * one-color site stays one color and never shows Scout's own blue.
	 *
	 * highlight_text is the accent fill (or the named highlight) when that reads at
	 * 4.5:1 on the page and on cards; otherwise the highlight is stepped toward the
	 * text color until it does.
	 *
	 * @param array                $tokens The merged tokens.
	 * @param array<string,string> $named  The colors DESIGN.md or theme.json set themselves.
	 */
	public static function derive( array $tokens, array $named = array() ): array {
		$c = (array) ( $tokens['colors'] ?? array() );
		if ( empty( $c['accent'] ) ) {
			return $tokens;
		}
		$own_highlight = ! empty( $named['highlight'] );
		if ( ! $own_highlight ) {
			$tokens['colors']['highlight'] = $c['accent'];
		}
		if ( empty( $named['highlight_text'] ) ) {
			$brand = (string) $tokens['colors']['highlight'];
			$first = $own_highlight ? $brand : (string) ( $c['accent_fill'] ?? $c['accent'] );
			$tokens['colors']['highlight_text'] = self::text_shade(
				$first,
				$brand,
				(string) ( $c['text'] ?? '#000000' ),
				array( (string) ( $c['bg'] ?? '' ), (string) ( $c['surface'] ?? '' ) )
			);
		}
		return $tokens;
	}

	/**
	 * A shade of $brand that reads as text on every background given: $first when
	 * it already passes, else $brand mixed toward $toward in 5% steps until it does.
	 * Backgrounds this cannot read (rgba, names) are skipped.
	 */
	public static function text_shade( string $first, string $brand, string $toward, array $backgrounds, float $need = 4.5 ): string {
		$backgrounds = array_filter( $backgrounds, array( __CLASS__, 'rgb' ) );
		$passes      = static function ( string $color ) use ( $backgrounds, $need ): bool {
			foreach ( $backgrounds as $bg ) {
				if ( self::contrast( $color, $bg ) < $need ) {
					return false;
				}
			}
			return true;
		};
		if ( $passes( $first ) ) {
			return $first;
		}
		$from = self::rgb( $brand );
		$to   = self::rgb( $toward );
		if ( null === $from || null === $to ) {
			return $first;
		}
		$shade = $first;
		for ( $step = 1; $step <= 20; $step++ ) {
			$k     = $step / 20;
			$shade = sprintf(
				'#%02X%02X%02X',
				(int) round( $from[0] + ( $to[0] - $from[0] ) * $k ),
				(int) round( $from[1] + ( $to[1] - $from[1] ) * $k ),
				(int) round( $from[2] + ( $to[2] - $from[2] ) * $k )
			);
			if ( $passes( $shade ) ) {
				return $shade;
			}
		}
		return $shade;
	}

	/**
	 * Tokens a block theme's theme.json already states: palette slugs named
	 * like ours (bg, surface, text, accent...) or like WordPress's usual ones
	 * (base, contrast, primary), and the heading and body font families.
	 */
	public static function from_theme_json(): array {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}
		$out     = array( 'colors' => array(), 'fonts' => array() );
		$palette = (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) );
		$by_slug = array();
		foreach ( $palette as $c ) {
			if ( ! empty( $c['slug'] ) && ! empty( $c['color'] ) ) {
				$by_slug[ self::key( (string) $c['slug'] ) ] = (string) $c['color'];
			}
		}
		$want = array(
			'bg'          => array( 'bg', 'background', 'base', 'canvas', 'page' ),
			'surface'     => array( 'surface', 'card', 'panel', 'base_2' ),
			'surface_2'   => array( 'surface_2', 'inset', 'base_3' ),
			'text'        => array( 'text', 'contrast', 'ink', 'foreground' ),
			'text_2'      => array( 'text_2', 'muted', 'contrast_2', 'secondary_text' ),
			'line'        => array( 'line', 'border', 'hairline' ),
			'accent'      => array( 'accent', 'primary', 'brand' ),
			'accent_fill' => array( 'accent_fill', 'accent_dark', 'primary_dark' ),
			'on_accent'   => array( 'on_accent', 'on_primary' ),
			'night'          => array( 'night' ),
			'on_night'       => array( 'on_night' ),
			'on_night_2'     => array( 'on_night_2' ),
			'highlight'      => array( 'highlight', 'secondary' ),
			'highlight_text' => array( 'highlight_text' ),
			'good'           => array( 'good', 'success' ),
			'warn'           => array( 'warn', 'warning' ),
			'bad'            => array( 'bad', 'error', 'danger' ),
		);
		foreach ( $want as $token => $slugs ) {
			foreach ( $slugs as $slug ) {
				if ( isset( $by_slug[ $slug ] ) ) {
					$out['colors'][ $token ] = $by_slug[ $slug ];
					break;
				}
			}
		}
		$families = (array) wp_get_global_settings( array( 'typography', 'fontFamilies', 'theme' ) );
		foreach ( $families as $f ) {
			$slug = self::key( (string) ( $f['slug'] ?? '' ) );
			$fam  = trim( (string) strtok( (string) ( $f['fontFamily'] ?? '' ), ',' ), " \"'" );
			if ( '' === $fam ) {
				continue;
			}
			foreach ( array( 'heading', 'body', 'label' ) as $role ) {
				if ( $slug === $role || false !== strpos( $slug, $role ) ) {
					$out['fonts'][ $role ] = array( 'family' => $fam );
				}
			}
		}
		return array_filter( $out );
	}

	/** Turn logo, mark and font file paths into addresses on this site. */
	private static function resolve_files( array $tokens, array $dirs ): array {
		if ( ! function_exists( 'get_theme_file_uri' ) ) {
			return $tokens;
		}
		$url = static function ( $path ) use ( $dirs ): string {
			$path = (string) $path;
			if ( '' === $path || preg_match( '#^(https?:)?//#', $path ) || 0 === strpos( $path, 'data:' ) ) {
				return $path;
			}
			foreach ( array_reverse( $dirs ) as $dir ) {
				if ( is_readable( rtrim( $dir, '/' ) . '/' . ltrim( $path, '/' ) ) ) {
					return get_theme_file_uri( ltrim( $path, '/' ) );
				}
			}
			return '';
		};
		$tokens['logo_url'] = $url( $tokens['logo'] ?? '' );
		$tokens['mark_url'] = $url( $tokens['mark'] ?? '' );
		foreach ( (array) ( $tokens['fonts'] ?? array() ) as $role => $font ) {
			if ( is_array( $font ) ) {
				$tokens['fonts'][ $role ]['url'] = $url( $font['file'] ?? '' );
			}
		}
		return $tokens;
	}

	/* ---------- CSS and contrast ---------- */

	/** A :root block of custom properties (--scout-bg, --scout-accent, --scout-radius-card, --scout-font-heading...). */
	public static function css( string $prefix = '--scout-', string $selector = ':root' ): string {
		$css = '';
		foreach ( self::variables( self::get(), $prefix ) as $name => $value ) {
			$css .= $name . ':' . $value . ';';
		}
		return $selector . '{' . $css . '}';
	}

	/**
	 * The custom properties for a token set, name => value, values already made
	 * safe for CSS. Colors are --scout-<name>; radius-, font- and weight- carry
	 * their role; the type scale is fs-, spacing is space- (container and gutter
	 * bare), motion is dur- and ease-. Underscores become hyphens throughout.
	 *
	 * @return array<string,string>
	 */
	public static function variables( array $t, string $prefix = '--scout-' ): array {
		$vars = array();
		foreach ( (array) ( $t['colors'] ?? array() ) as $k => $v ) {
			$vars[ $prefix . self::dash( $k ) ] = (string) $v;
		}
		foreach ( (array) ( $t['radius'] ?? array() ) as $k => $v ) {
			$vars[ $prefix . 'radius-' . self::dash( $k ) ] = (string) $v;
		}
		foreach ( (array) ( $t['fonts'] ?? array() ) as $role => $f ) {
			$family = (string) ( $f['family'] ?? '' );
			if ( '' !== $family ) {
				$generic = ( false !== stripos( $family, 'mono' ) ) ? 'monospace' : 'sans-serif';
				$vars[ $prefix . 'font-' . $role ] = '"' . str_replace( '"', '', $family ) . '", ' . $generic;
			}
			if ( ! empty( $f['weight'] ) ) {
				$vars[ $prefix . 'weight-' . $role ] = (string) (int) $f['weight'];
			}
		}
		foreach ( (array) ( $t['type'] ?? array() ) as $k => $v ) {
			$vars[ $prefix . 'fs-' . self::dash( $k ) ] = self::length( $v );
		}
		foreach ( (array) ( $t['space'] ?? array() ) as $k => $v ) {
			$name = in_array( (string) $k, array( 'container', 'gutter' ), true ) ? self::dash( $k ) : 'space-' . self::dash( $k );
			$vars[ $prefix . $name ] = self::length( $v );
		}
		foreach ( (array) ( $t['motion'] ?? array() ) as $k => $v ) {
			$vars[ $prefix . self::dash( $k ) ] = (string) $v;
		}
		foreach ( $vars as $name => $value ) {
			$vars[ $name ] = (string) preg_replace( '/[^\w\s#,.()%"\'-]/', '', $value );
		}
		return $vars;
	}

	/** on_night_2 becomes on-night-2. */
	private static function dash( $key ): string {
		return str_replace( '_', '-', (string) $key );
	}

	/** A bare number in a length slot means pixels. */
	private static function length( $v ): string {
		return is_int( $v ) || is_float( $v ) ? $v . 'px' : (string) $v;
	}

	/** WCAG contrast ratio of two colors (hex or rgb()); 0 when one cannot be read. */
	public static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		if ( null === $la || null === $lb ) {
			return 0.0;
		}
		$hi = max( $la, $lb );
		$lo = min( $la, $lb );
		return round( ( $hi + 0.05 ) / ( $lo + 0.05 ), 2 );
	}

	/** Relative luminance, or null for a color this cannot read (an rgba() hairline, a name). */
	public static function luminance( string $color ): ?float {
		$rgb = self::rgb( $color );
		if ( null === $rgb ) {
			return null;
		}
		$lin = array_map(
			static function ( int $c ): float {
				$s = $c / 255;
				return $s <= 0.03928 ? $s / 12.92 : pow( ( $s + 0.055 ) / 1.055, 2.4 );
			},
			$rgb
		);
		return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
	}

	/** @return array{0:int,1:int,2:int}|null */
	public static function rgb( string $color ): ?array {
		$color = trim( $color );
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color, $m ) ) {
			$h = strlen( $m[1] ) === 3 ? preg_replace( '/(.)/', '$1$1', $m[1] ) : $m[1];
			return array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) );
		}
		if ( preg_match( '/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)$/i', $color, $m ) ) {
			if ( isset( $m[4] ) && (float) $m[4] < 1 ) {
				return null; // Translucent: what shows depends on what is behind it.
			}
			return array( (int) $m[1], (int) $m[2], (int) $m[3] );
		}
		return null;
	}

	/**
	 * The pairs DESIGN.md must keep readable (§4): white text on the accent fill,
	 * body text on the page and on cards, text on the dark band, and the highlight
	 * text shade on the page and on cards, all at 4.5:1. Muted text on the page and
	 * on the dark band, and the meaning colors as text on cards, are checked too, as
	 * warnings. Each row: pair, ratio, need, ok, level.
	 *
	 * @return array<int,array{pair:string,ratio:float,need:float,ok:bool,level:string}>
	 */
	public static function issues( ?array $tokens = null ): array {
		$c     = (array) ( ( $tokens ?? self::get() )['colors'] ?? array() );
		$pairs = array(
			array( 'on_accent', 'accent_fill', 4.5, 'fail', 'Button text on the accent fill' ),
			array( 'text', 'bg', 4.5, 'fail', 'Text on the page' ),
			array( 'text', 'surface', 4.5, 'fail', 'Text on cards' ),
			array( 'text_2', 'bg', 4.5, 'warn', 'Muted text on the page' ),
			array( 'on_night', 'night', 4.5, 'fail', 'Text on the dark band' ),
			array( 'on_night_2', 'night', 4.5, 'warn', 'Muted text on the dark band' ),
			array( 'highlight_text', 'bg', 4.5, 'fail', 'Highlight text on the page' ),
			array( 'highlight_text', 'surface', 4.5, 'fail', 'Highlight text on cards' ),
			array( 'good', 'surface', 4.5, 'warn', 'Good as text on cards' ),
			array( 'warn', 'surface', 4.5, 'warn', 'Warn as text on cards' ),
			array( 'bad', 'surface', 4.5, 'warn', 'Bad as text on cards' ),
		);
		$out = array();
		foreach ( $pairs as list( $fg, $bg, $need, $level, $label ) ) {
			if ( empty( $c[ $fg ] ) || empty( $c[ $bg ] ) ) {
				continue;
			}
			$ratio = self::contrast( (string) $c[ $fg ], (string) $c[ $bg ] );
			if ( $ratio <= 0 ) {
				continue;
			}
			$out[] = array(
				'pair'  => $label . ' (' . $c[ $fg ] . ' on ' . $c[ $bg ] . ')',
				'ratio' => $ratio,
				'need'  => $need,
				'ok'    => $ratio >= $need,
				'level' => $level,
			);
		}
		return $out;
	}

	/* ---------- who owns a job ---------- */

	/**
	 * Does a plugin do this job on this site? Themes use it for the few fallbacks
	 * rule 6 allows: `if ( ! scout_core_owns( 'hours' ) ) { ...theme's own hours... }`.
	 * Unknown jobs are false. The scout_core_owns filter lets a plugin claim a job.
	 */
	public static function owns( string $job ): bool {
		$job  = self::key( $job );
		$jobs = array(
			'head'          => array( 'Scout_SEO_Head' ),
			'schema'        => array( 'Scout_Schema' ),
			'business'      => array( 'Scout_Core_Business' ),
			'seo'           => array( 'Scout_SEO_Head' ),
			'sitemap'       => array( 'Scout_SEO_Sitemap' ),
			'sync'          => array( 'Scout_Sync' ),
			'changelog'     => array( 'Scout_Changelog' ),
			'spam'          => array( 'Scout_Forms_Guard', 'Scout_Core_Protection' ),
			'hours'         => array( 'Scout_Hours_Front', 'Scout_Hours' ),
			'call_bar'      => array( 'Scout_Contact' ),
			'contact'       => array( 'Scout_Contact' ),
			'cards'         => array( 'Scout_Cards_Render', 'Scout_Cards' ),
			'reviews'       => array( 'Scout_Reviews_Brand', 'Scout_Reviews' ),
			'chat'          => array( 'Scout_Chat' ),
			'redirects'     => array( 'Scout_Redirects_Store', 'Scout_Redirects' ),
			'analytics'     => array( 'Scout_Analytics' ),
			'tracking'      => array( 'Scout_Analytics' ),
			'cookie_notice' => array( 'Scout_Analytics' ),
			'team'          => array( 'Scout_Team_Editor', 'Scout_Team' ),
			'results'       => array( 'Scout_Results_Editor', 'Scout_Results' ),
			'events'        => array( 'Scout_Events_Agreements', 'Scout_Events' ),
			'members'       => array( 'Scout_Members_Events', 'Scout_Members' ),
			'partners'      => array( 'Scout_Partners_Front', 'Scout_Partners' ),
			'booking'       => array( 'Scout_Booking_Emails', 'Scout_Booking' ),
			'newsletter'    => array( 'Scout_Newsletter_Beehiiv', 'Scout_Newsletter' ),
			'images'        => array( 'Scout_Optimize' ),
			'accessibility' => array( 'Scout_Accessibility_Checks' ),
			'ai_search'     => array( 'Scout_AEO_FAQ' ),
			'faq'           => array( 'Scout_AEO_FAQ' ),
		);
		$owned = false;
		foreach ( $jobs[ $job ] ?? array() as $class ) {
			if ( class_exists( $class, false ) ) {
				$owned = true;
				break;
			}
		}
		// Scout Core's head and schema stand aside for a theme that still prints its own.
		if ( $owned && in_array( $job, array( 'head', 'schema', 'seo' ), true ) && class_exists( 'Scout_SEO_Head', false ) && method_exists( 'Scout_SEO_Head', 'theme_handles_head' ) && Scout_SEO_Head::theme_handles_head() ) {
			$owned = false;
		}
		/**
		 * Filters whether a plugin owns a job on this site.
		 *
		 * @param bool   $owned Whether a plugin does the job.
		 * @param string $job   The job key (hours, call_bar, reviews...).
		 */
		return (bool) apply_filters( 'scout_core_owns', $owned, $job );
	}
}

if ( ! function_exists( 'scout_design' ) ) {
	/** The site's design tokens, or a feature's section laid over them. */
	function scout_design( string $feature = '' ): array {
		return Scout_Core_Design::get( $feature );
	}
}
if ( ! function_exists( 'scout_design_css' ) ) {
	/** A :root block of --scout-* custom properties from the tokens. */
	function scout_design_css( string $prefix = '--scout-', string $selector = ':root' ): string {
		return Scout_Core_Design::css( $prefix, $selector );
	}
}
if ( ! function_exists( 'scout_design_contrast' ) ) {
	/** WCAG contrast ratio between two colors. */
	function scout_design_contrast( string $a, string $b ): float {
		return Scout_Core_Design::contrast( $a, $b );
	}
}
if ( ! function_exists( 'scout_design_issues' ) ) {
	/** Contrast pairs from DESIGN.md with their ratio and whether they pass. */
	function scout_design_issues(): array {
		return Scout_Core_Design::issues();
	}
}
if ( ! function_exists( 'scout_core_owns' ) ) {
	/** True when a Scout plugin does this job on this site (rule 6). */
	function scout_core_owns( string $job ): bool {
		return Scout_Core_Design::owns( $job );
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'switch_theme', array( 'Scout_Core_Design', 'flush' ) );
	add_action( 'upgrader_process_complete', array( 'Scout_Core_Design', 'flush' ) );
}
