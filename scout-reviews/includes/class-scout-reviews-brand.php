<?php
/**
 * The client's look on the review page and the printed cards: background,
 * text and brand colors, plus heading, body and label fonts.
 *
 * Fonts come from a fixed list of Google Fonts with the weights each one
 * really has, so the font request can never fail on a weight that does not
 * exist. Text colors are worked out from the background, so a light or dark
 * background both stay readable without more settings.
 *
 * @package ScoutReviews
 */

defined( 'ABSPATH' ) || exit;

final class Scout_Reviews_Brand {

	/** Family => the css2 axis spec Google Fonts accepts for it. */
	const FONTS = array(
		'Albert Sans'         => 'wght@400;500;600;700',
		'Bricolage Grotesque' => 'opsz,wght@12..96,400;12..96,500;12..96,600;12..96,700',
		'DM Sans'             => 'wght@400;500;600;700',
		'Fraunces'            => 'wght@400;500;600;700',
		'Geist Mono'          => 'wght@400;500;600',
		'Hanken Grotesk'      => 'wght@400;500;600;700',
		'Hepta Slab'          => 'wght@400;500;600;700',
		'Instrument Sans'     => 'wght@400;500;600;700',
		'Instrument Serif'    => 'ital@0;1',
		'Inter'               => 'wght@400;500;600;700',
		'Lato'                => 'wght@400;700',
		'Libre Baskerville'   => 'ital,wght@0,400;0,700;1,400',
		'Lora'                => 'wght@400;500;600;700',
		'Manrope'             => 'wght@400;500;600;700',
		'Montserrat'          => 'wght@400;500;600;700',
		'Mulish'              => 'wght@400;500;600;700',
		'Nunito'              => 'wght@400;500;600;700',
		'Open Sans'           => 'wght@400;500;600;700',
		'Outfit'              => 'wght@400;500;600;700',
		'Playfair Display'    => 'wght@400;500;600;700',
		'Plus Jakarta Sans'   => 'wght@400;500;600;700',
		'Poppins'             => 'wght@400;500;600;700',
		'Raleway'             => 'wght@400;500;600;700',
		'Roboto'              => 'wght@400;500;700',
		'Source Serif 4'      => 'wght@400;500;600;700',
		'Space Mono'          => 'wght@400;700',
		'Work Sans'           => 'wght@400;500;600;700',
	);

	const SANS  = '-apple-system, "Segoe UI", system-ui, sans-serif';
	const MONO  = 'ui-monospace, Menlo, monospace';
	const SERIF = 'Georgia, "Times New Roman", serif';

	/** Settings keys this class owns, with Scout's own look as the default. */
	public static function defaults(): array {
		$d = array(
			'accent'       => '#0E8FE6',
			'background'   => '#0B0B0B',
			'text_color'   => '',
			'heading_font' => 'Hanken Grotesk',
			'body_font'    => 'Hanken Grotesk',
			'label_font'   => 'Geist Mono',
		);
		/**
		 * Lets a client theme supply its own look, so a fresh install already
		 * matches the site. Saved settings still win.
		 *
		 * @param array $d background, text_color, heading_font, body_font, label_font, accent.
		 */
		return (array) apply_filters( 'scout_reviews_brand', $d );
	}

	/** Clean the brand part of a settings save. */
	public static function sanitize( array $input, array $d ): array {
		$font = static function ( string $key, bool $allow_same ) use ( $input, $d ): string {
			$value = (string) ( $input[ $key ] ?? '' );
			if ( isset( self::FONTS[ $value ] ) || 'system' === $value || ( $allow_same && 'same' === $value ) ) {
				return $value;
			}
			return (string) $d[ $key ];
		};
		$bg = sanitize_hex_color( (string) ( $input['background'] ?? '' ) );
		$tx = sanitize_hex_color( (string) ( $input['text_color'] ?? '' ) );
		return array(
			'background'   => $bg ? $bg : (string) $d['background'],
			'text_color'   => ( $tx && empty( $input['text_auto'] ) ) ? $tx : '',
			'heading_font' => $font( 'heading_font', false ),
			'body_font'    => $font( 'body_font', false ),
			'label_font'   => $font( 'label_font', true ),
		);
	}

	/** One Google Fonts request for the fonts in use, or '' when none are web fonts. */
	public static function fonts_url( array $s ): string {
		$families = array();
		foreach ( array( 'heading_font', 'body_font', 'label_font' ) as $key ) {
			$name = (string) ( $s[ $key ] ?? '' );
			if ( isset( self::FONTS[ $name ] ) ) {
				$families[ $name ] = 'family=' . str_replace( ' ', '+', $name ) . ':' . self::FONTS[ $name ];
			}
		}
		return $families ? 'https://fonts.googleapis.com/css2?' . implode( '&', $families ) . '&display=swap' : '';
	}

	/** A CSS font-family value for a saved choice. */
	public static function stack( string $name, string $fallback = self::SANS ): string {
		if ( ! isset( self::FONTS[ $name ] ) ) {
			return $fallback;
		}
		$generic = in_array( $name, array( 'Geist Mono', 'Space Mono' ), true ) ? self::MONO
			: ( in_array( $name, array( 'Fraunces', 'Instrument Serif', 'Libre Baskerville', 'Lora', 'Playfair Display', 'Source Serif 4' ), true ) ? self::SERIF : self::SANS );
		return '"' . $name . '", ' . $generic;
	}

	/** Font stacks for heading, body and label. */
	public static function stacks( array $s ): array {
		$body  = self::stack( (string) $s['body_font'] );
		$label = 'same' === $s['label_font'] ? $body : self::stack( (string) $s['label_font'] );
		return array(
			'head'  => self::stack( (string) $s['heading_font'] ),
			'body'  => $body,
			'label' => $label,
		);
	}

	/** Relative luminance of a #rrggbb color (WCAG). */
	public static function luminance( string $hex ): float {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$out = array();
		foreach ( array( 0, 2, 4 ) as $i ) {
			$c     = hexdec( substr( $hex, $i, 2 ) ) / 255;
			$out[] = $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		}
		return 0.2126 * $out[0] + 0.7152 * $out[1] + 0.0722 * $out[2];
	}

	public static function contrast( string $a, string $b ): float {
		$x = self::luminance( $a );
		$y = self::luminance( $b );
		return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
	}

	/** White or near-black, whichever reads better on the color. */
	public static function on( string $hex ): string {
		return self::contrast( $hex, '#FFFFFF' ) >= self::contrast( $hex, '#111111' ) ? '#FFFFFF' : '#111111';
	}

	/**
	 * CSS custom properties for the review page.
	 *
	 * @param array $s Scout Reviews settings.
	 */
	public static function page_vars( array $s ): string {
		$bg     = (string) $s['background'];
		$accent = (string) $s['accent'];
		$dark   = self::luminance( $bg ) < 0.18;
		$text   = '' !== $s['text_color'] ? (string) $s['text_color'] : ( $dark ? '#F5F5F7' : '#1D1D1F' );
		$mix    = $dark ? '#FFFFFF' : '#000000';
		$f      = self::stacks( $s );
		$vars   = array(
			'--accent'      => $accent,
			'--on-accent'   => self::on( $accent ),
			'--bg'          => $bg,
			'--surface'     => "color-mix(in srgb, {$bg} 92%, {$mix})",
			'--text'        => $text,
			'--text-2'      => "color-mix(in srgb, {$text} 78%, {$bg})",
			'--text-3'      => "color-mix(in srgb, {$text} 64%, {$bg})",
			'--line'        => $dark ? 'rgba(255,255,255,.10)' : 'rgba(0,0,0,.10)',
			'--strong'      => $dark ? 'rgba(255,255,255,.16)' : 'rgba(0,0,0,.18)',
			// Eyebrow and focus color: the brand color, pushed toward the text until it reads.
			'--link'        => self::contrast( $accent, $bg ) >= 4.5 ? $accent : "color-mix(in srgb, {$accent} 55%, {$text})",
			'--font-head'   => $f['head'],
			'--font-body'   => $f['body'],
			'--font-label'  => $f['label'],
			'color-scheme'  => $dark ? 'dark' : 'light',
		);
		$css = '';
		foreach ( $vars as $k => $v ) {
			$css .= $k . ':' . $v . ';';
		}
		return $css;
	}

	/** CSS custom properties for the printed cards (always on white paper). */
	public static function card_vars( array $s ): string {
		$f      = self::stacks( $s );
		$accent = (string) $s['accent'];
		// A pale brand color would vanish on paper; deepen it until it reads.
		$head = self::contrast( $accent, '#FFFFFF' ) >= 3 ? $accent : "color-mix(in srgb, {$accent} 60%, #111)";
		$ink  = '' !== $s['text_color'] && self::contrast( (string) $s['text_color'], '#FFFFFF' ) >= 7 ? (string) $s['text_color'] : '#1D1D1F';
		return '--srk-accent:' . $head . ';--srk-ink:' . $ink . ';--srk-font-head:' . $f['head'] . ';--srk-font-body:' . $f['body'] . ';--srk-font-label:' . $f['label'] . ';';
	}
}
