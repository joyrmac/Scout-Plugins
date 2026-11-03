<?php
/**
 * Where the writing room gets its prompts, its look and its words.
 *
 * The active theme carries a "writing" section in its scout-setup.json (child theme first):
 *
 *   "writing": {
 *     "prompts":    "writing-prompts.json",          path inside the theme
 *     "menu_label": "Your Writing",
 *     "menu_tag":   "New",                           small tag beside the menu label ("" for none)
 *     "send_to":    "team@scoutraleigh.com",         one address or a list
 *     "text":  { "kicker", "dashboard_kicker", "search_hint", "week": [ { "label", "title", "text" } x3 ] },
 *     "brand": { colors, "heading_font", "body_font", "font_files": [ { "family", "file", "style", "weight" } ] }
 *   }
 *
 * Without a theme prompts file, a file uploaded on the Writing settings screen is used. Settings typed in
 * WordPress (the Send to Scout address) win over the theme; the theme fills blanks; Scout's defaults come last.
 *
 * @package Scout_Writing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SCOUT_WRITING_SETTINGS = 'scout_writing_settings';
/** The uploaded prompts file, as JSON text. Not autoloaded. */
const SCOUT_WRITING_UPLOAD = 'scout_writing_prompts_upload';
/** Where "Send to Scout" goes when nobody has set an address. */
const SCOUT_WRITING_DEFAULT_TO = 'team@scoutraleigh.com';

/** The theme's "writing" section, or an empty array. */
function scout_writing_setup(): array {
	static $setup = null;
	if ( null !== $setup ) {
		return $setup;
	}
	$setup = array();
	foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
		$path = trailingslashit( $dir ) . 'scout-setup.json';
		if ( is_readable( $path ) ) {
			$json  = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
			$setup = is_array( $json ) && isset( $json['writing'] ) && is_array( $json['writing'] ) ? $json['writing'] : array();
			break;
		}
	}
	return $setup;
}

/**
 * A file inside the active theme (child theme first), as an absolute path and a URL, or null.
 * Paths that climb out of the theme are refused.
 *
 * @return array{path:string,url:string}|null
 */
function scout_writing_theme_file( $rel ): ?array {
	$rel = is_string( $rel ) ? ltrim( str_replace( '\\', '/', $rel ), '/' ) : '';
	if ( '' === $rel || false !== strpos( $rel, '..' ) || ! preg_match( '#^[A-Za-z0-9._/-]+$#', $rel ) ) {
		return null;
	}
	$dirs = array(
		get_stylesheet_directory() => get_stylesheet_directory_uri(),
		get_template_directory()   => get_template_directory_uri(),
	);
	foreach ( $dirs as $dir => $uri ) {
		$path = trailingslashit( $dir ) . $rel;
		if ( is_readable( $path ) && is_file( $path ) ) {
			return array( 'path' => $path, 'url' => trailingslashit( $uri ) . $rel );
		}
	}
	return null;
}

/** Saved settings: send_to, upload_name, upload_at. */
function scout_writing_settings(): array {
	$s = get_option( SCOUT_WRITING_SETTINGS, array() );
	return wp_parse_args( is_array( $s ) ? $s : array(), array( 'send_to' => '', 'upload_name' => '', 'upload_at' => 0 ) );
}

/**
 * Where the prompts come from right now.
 *
 * @return array{from:string,label:string,json:string} from is "theme", "upload" or "".
 */
function scout_writing_source(): array {
	$setup = scout_writing_setup();
	if ( ! empty( $setup['prompts'] ) ) {
		$file = scout_writing_theme_file( $setup['prompts'] );
		if ( $file ) {
			return array(
				'from'  => 'theme',
				'label' => sprintf( '%s (theme: %s)', ltrim( (string) $setup['prompts'], '/' ), wp_get_theme()->get( 'Name' ) ),
				'json'  => (string) file_get_contents( $file['path'] ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
			);
		}
	}
	$upload = (string) get_option( SCOUT_WRITING_UPLOAD, '' );
	if ( '' !== $upload ) {
		$name = scout_writing_settings()['upload_name'];
		return array( 'from' => 'upload', 'label' => '' !== $name ? $name . ' (uploaded)' : 'Uploaded file', 'json' => $upload );
	}
	return array( 'from' => '', 'label' => '', 'json' => '' );
}

/**
 * Read a prompts file into the room's shape. Prompts need an id, a step and a question; anything else
 * missing gets a safe empty value. Returns an empty "prompts" list when the file is unusable.
 */
function scout_writing_parse( string $json ): array {
	$raw  = '' !== $json ? json_decode( $json, true ) : null;
	$data = is_array( $raw ) ? $raw : array();
	foreach ( array( 'steps', 'prompts', 'dest', 'time', 'groups', 'blocks', 'step1_blocks', 'social_rules', 'guests', 'inventory' ) as $k ) {
		$data[ $k ] = isset( $data[ $k ] ) && is_array( $data[ $k ] ) ? $data[ $k ] : array();
	}
	$prompts = array();
	$seen    = array();
	foreach ( $data['prompts'] as $p ) {
		if ( ! is_array( $p ) || empty( $p['id'] ) || ! is_string( $p['id'] ) || ! isset( $p['q'] ) || '' === trim( (string) $p['q'] ) ) {
			continue;
		}
		$id = scout_writing_clean_id( $p['id'] );
		if ( '' === $id || $id !== $p['id'] || isset( $seen[ $id ] ) ) {
			continue;
		}
		$seen[ $id ] = true;
		$prompts[]   = array(
			'id'    => $id,
			'step'  => max( 1, (int) ( $p['step'] ?? 1 ) ),
			'group' => (string) ( $p['group'] ?? '' ),
			'q'     => (string) $p['q'],
			'why'   => (string) ( $p['why'] ?? '' ),
			'dest'  => array_values( array_filter( array_map( 'strval', (array) ( $p['dest'] ?? array() ) ) ) ),
			't'     => (string) ( $p['t'] ?? '' ),
		);
	}
	$data['prompts'] = $prompts;
	// Steps every prompt points at, even when the file lists none.
	$steps = array();
	foreach ( $data['steps'] as $st ) {
		if ( is_array( $st ) && isset( $st['n'] ) ) {
			$n           = (int) $st['n'];
			$title       = (string) ( $st['title'] ?? 'Step ' . $n );
			$steps[ $n ] = array(
				'n'      => $n,
				'key'    => sanitize_html_class( (string) ( $st['key'] ?? 'step-' . $n ) ),
				'title'  => $title,
				'lead'   => (string) ( $st['lead'] ?? ( isset( $st['accent'] ) ? '' : $title ) ),
				'accent' => (string) ( $st['accent'] ?? '' ),
				'tail'   => (string) ( $st['tail'] ?? '' ),
				'lede'   => (string) ( $st['lede'] ?? '' ),
			);
		}
	}
	foreach ( $prompts as $p ) {
		if ( ! isset( $steps[ $p['step'] ] ) ) {
			$steps[ $p['step'] ] = array( 'n' => $p['step'], 'key' => 'step-' . $p['step'], 'title' => 'Step ' . $p['step'], 'lead' => 'Step ' . $p['step'], 'accent' => '', 'tail' => '', 'lede' => '' );
		}
	}
	ksort( $steps );
	$data['steps'] = array_values( $steps );
	// Blocks inside a step. The older "step1_blocks" list is Step 1's blocks.
	foreach ( $data['step1_blocks'] as $b ) {
		if ( is_array( $b ) ) {
			$data['blocks'][] = $b + array( 'step' => 1 );
		}
	}
	$blocks = array();
	foreach ( $data['blocks'] as $b ) {
		if ( is_array( $b ) && ! empty( $b['id'] ) && ! empty( $b['groups'] ) ) {
			$blocks[] = array(
				'id'     => sanitize_html_class( (string) $b['id'] ),
				'step'   => max( 1, (int) ( $b['step'] ?? 1 ) ),
				'label'  => (string) ( $b['label'] ?? '' ),
				'title'  => (string) ( $b['title'] ?? '' ),
				'accent' => (string) ( $b['accent'] ?? '' ),
				'intro'  => array_map( 'strval', (array) ( $b['intro'] ?? array() ) ),
				'groups' => array_map( 'strval', (array) $b['groups'] ),
			);
		}
	}
	$data['blocks'] = $blocks;
	unset( $data['step1_blocks'] );
	return $data;
}

/** The step a reference list belongs in: its own "step", else the step with that key. */
function scout_writing_ref_step( array $list, string $key ): int {
	if ( isset( $list['step'] ) ) {
		return (int) $list['step'];
	}
	foreach ( scout_writing_data()['steps'] as $st ) {
		if ( $key === $st['key'] ) {
			return (int) $st['n'];
		}
	}
	return 0;
}

/** Brand values, Scout's neutral look underneath whatever the theme sets. */
function scout_writing_brand(): array {
	static $brand = null;
	if ( null !== $brand ) {
		return $brand;
	}
	$defaults = array(
		'paper'       => '#FFFFFF',
		'wash'        => '#F5F5F7',
		'ink'         => '#1D1D1F',
		'ink_soft'    => '#515154',
		'blue'        => '#0A6FB4',
		'blue_tint'   => '#E7F1FA',
		'gold'        => '#0E8FE6',
		'gold_text'   => '#0A6FB4',
		'gold_tint'   => '#EAF4FC',
		'line'        => '#E3E3E8',
		'accent'      => '#0A78C2',
		'accent_dark' => '#08639F',
		'on_accent'   => '#FFFFFF',
		'highlight'   => '#5FB8F2',
		'frame'       => '#D2D2D7',
		'rule_soft'   => '#E3E3E8',
		'bar'         => '#EDEDF0',
		'tip'         => '#F5F5F7',
	);
	$set   = (array) ( scout_writing_setup()['brand'] ?? array() );
	$brand = array();
	foreach ( $defaults as $k => $v ) {
		$c           = isset( $set[ $k ] ) && is_string( $set[ $k ] ) ? sanitize_hex_color( $set[ $k ] ) : null;
		$brand[ $k ] = $c ? $c : $v;
	}
	$sans                = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
	$brand['heading']    = scout_writing_font_stack( $set['heading_font'] ?? '', "Georgia, 'Times New Roman', serif", $sans );
	$brand['body']       = scout_writing_font_stack( $set['body_font'] ?? '', $sans, $sans );
	$brand['font_faces'] = scout_writing_font_faces( (array) ( $set['font_files'] ?? array() ) );
	return $brand;
}

/**
 * A CSS font-family list. A single family name gets the fallback; a list with commas is used as given;
 * nothing set gives the plain default.
 */
function scout_writing_font_stack( $value, string $fallback, string $none ): string {
	$value = is_string( $value ) ? trim( (string) preg_replace( '/[^A-Za-z0-9 ,\'"-]/', '', $value ) ) : '';
	if ( '' === $value ) {
		return $none;
	}
	if ( false !== strpos( $value, ',' ) ) {
		return $value;
	}
	return "'" . trim( $value, '\'" ' ) . "', " . $fallback;
}

/** @font-face rules for the theme's own font files (missing files are skipped). */
function scout_writing_font_faces( array $files ): string {
	$css = '';
	foreach ( $files as $f ) {
		if ( ! is_array( $f ) || empty( $f['family'] ) || empty( $f['file'] ) ) {
			continue;
		}
		$file = scout_writing_theme_file( $f['file'] );
		if ( ! $file ) {
			continue;
		}
		$family = trim( (string) preg_replace( '/[^A-Za-z0-9 -]/', '', (string) $f['family'] ) );
		$style  = isset( $f['style'] ) && 'italic' === $f['style'] ? 'italic' : 'normal';
		$weight = isset( $f['weight'] ) ? (string) preg_replace( '/[^0-9 ]/', '', (string) $f['weight'] ) : '400';
		$format = preg_match( '/\.woff$/i', $file['path'] ) ? 'woff' : 'woff2';
		$css   .= sprintf( "@font-face{font-family:'%s';src:url('%s') format('%s');font-style:%s;font-weight:%s;font-display:swap}", $family, esc_url_raw( $file['url'] ), $format, $style, '' !== trim( $weight ) ? trim( $weight ) : '400' );
	}
	return $css;
}

/** The brand as custom properties on a selector. */
function scout_writing_brand_css( string $selector ): string {
	$b    = scout_writing_brand();
	$vars = array(
		'paper'       => 'paper',
		'wash'        => 'wash',
		'ink'         => 'ink',
		'ink2'        => 'ink_soft',
		'blue'        => 'blue',
		'blue-tint'   => 'blue_tint',
		'gold'        => 'gold',
		'goldtext'    => 'gold_text',
		'gold-tint'   => 'gold_tint',
		'line'        => 'line',
		'accent'      => 'accent',
		'accent-dark' => 'accent_dark',
		'on-accent'   => 'on_accent',
		'highlight'   => 'highlight',
		'frame'       => 'frame',
		'rule-soft'   => 'rule_soft',
		'bar'         => 'bar',
		'tip'         => 'tip',
	);
	$css = $selector . '{';
	foreach ( $vars as $var => $key ) {
		$css .= '--sw-' . $var . ':' . $b[ $key ] . ';';
	}
	return $css . '--sw-serif:' . $b['heading'] . ';--sw-sans:' . $b['body'] . '}';
}

/** One of the room's words, from the theme's "text" section or the default. */
function scout_writing_copy( string $key ) {
	$text     = (array) ( scout_writing_setup()['text'] ?? array() );
	$defaults = array(
		'kicker'           => 'Your writing',
		'search_hint'      => 'Search, for example: a word from any prompt',
		'dashboard_kicker' => 'Your writing · ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		'week'             => array(
			array( 'label' => 'Early in the week', 'title' => 'One important piece', 'text' => 'One prompt from Step 1. About 20 minutes.' ),
			array( 'label' => 'Later in the week', 'title' => 'A few quick ones', 'text' => 'Two or three short prompts.' ),
			array( 'label' => 'Whenever', 'title' => 'Anything else', 'text' => 'Whatever sparks you today.' ),
		),
	);
	if ( 'week' === $key ) {
		$week = array();
		foreach ( (array) ( $text['week'] ?? array() ) as $d ) {
			if ( is_array( $d ) && ! empty( $d['title'] ) ) {
				$week[] = array( 'label' => (string) ( $d['label'] ?? '' ), 'title' => (string) $d['title'], 'text' => (string) ( $d['text'] ?? '' ) );
			}
		}
		return $week ? $week : $defaults['week'];
	}
	return isset( $text[ $key ] ) && is_string( $text[ $key ] ) && '' !== trim( $text[ $key ] ) ? $text[ $key ] : ( $defaults[ $key ] ?? '' );
}

/** The menu's name and its small tag. */
function scout_writing_menu_label(): string {
	$label = scout_writing_setup()['menu_label'] ?? '';
	return is_string( $label ) && '' !== trim( $label ) ? sanitize_text_field( $label ) : 'Your Writing';
}

function scout_writing_menu_tag(): string {
	$setup = scout_writing_setup();
	if ( array_key_exists( 'menu_tag', $setup ) ) {
		return is_string( $setup['menu_tag'] ) ? substr( sanitize_text_field( $setup['menu_tag'] ), 0, 12 ) : '';
	}
	return 'New';
}

/** Who gets "Send to Scout": the address typed in Writing settings, else the theme's, else Scout's team inbox. */
function scout_writing_recipients(): array {
	$typed = (string) scout_writing_settings()['send_to'];
	$raw   = '' !== trim( $typed ) ? $typed : ( scout_writing_setup()['send_to'] ?? '' );
	$list  = is_array( $raw ) ? $raw : preg_split( '/[\s,;]+/', (string) $raw );
	$to    = array_values( array_filter( array_map( 'sanitize_email', array_map( 'strval', (array) $list ) ), 'is_email' ) );
	return $to ? $to : array( SCOUT_WRITING_DEFAULT_TO );
}
