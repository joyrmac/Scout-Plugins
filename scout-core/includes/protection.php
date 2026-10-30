<?php
/**
 * Spam protection for client sites, both on unless switched off:
 *
 *  - Email guard. Contact emails print in plain text (mailto links, visible
 *    text, the JSON-LD `email`), and scrapers harvest them. One output buffer,
 *    started late on template_redirect, rewrites the finished HTML so every
 *    source (theme, block bindings, plugins, schema) is covered at once:
 *      * href="mailto:..." and visible text: the address becomes a mix of
 *        decimal and hex character references (antispambot() style, but the
 *        same output every time so cached pages stay stable). Browsers show
 *        and click them as normal.
 *      * <script type="application/ld+json">: "@" inside an email becomes the
 *        JSON escape \u0040. The JSON stays valid and decodes to the same
 *        value, so Google reads the same address.
 *      * Left alone: other scripts, <style>, <textarea>, HTML comments, CDATA,
 *        and every attribute other than a mailto href (so <input value>,
 *        data-*, meta content are untouched). <noscript> holds ordinary
 *        markup for visitors without JavaScript, so it is guarded like the
 *        rest of the page.
 *    A page cache stores what this buffer returns, so cached pages are already
 *    guarded. On any error the original HTML is sent unchanged.
 *    Filter: `scout_core_email_guard` (bool).
 *
 *  - Close comments and pingbacks. comments_open and pings_open return false
 *    outside wp-admin, the X-Pingback header goes, and the pingback XML-RPC
 *    methods are removed. No comment is deleted; switching it off restores
 *    exactly what WordPress did before.
 *    Filter: `scout_core_close_comments` (bool).
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Protection {

	const OPTION = 'scout_core_protection';

	/** Simple email shape: local part, @, dotted domain with a letter TLD. */
	const EMAIL = '[A-Za-z0-9._%+\-]+@[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?)*\.[A-Za-z]{2,24}';

	/**
	 * Raw-text elements carried across a mid-page flush, so the next chunk
	 * knows it starts inside one.
	 *
	 * @var string
	 */
	private static $open = '';

	/** @return array<string,string> setting key => label */
	public static function fields() {
		return array(
			'email_guard'    => 'Hide email addresses from spam bots',
			'close_comments' => 'Close comments and pingbacks',
		);
	}

	/** @return array<string,string> Each switch is "1" (on) or "0" (off). Both default on. */
	public static function get() {
		$defaults = array_fill_keys( array_keys( self::fields() ), '1' );
		$saved    = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	public static function on( $key ) {
		$all = self::get();
		return isset( $all[ $key ] ) && '1' === (string) $all[ $key ];
	}

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_guard' ), 9999 );
		add_action( 'init', array( __CLASS__, 'maybe_close_comments' ), 20 );
	}

	public static function register_settings() {
		register_setting(
			'scout_protection_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	/** Unchecked boxes are not posted, so every switch is stored as "1" or "0". */
	public static function sanitize( $input ) {
		$input = (array) $input;
		$clean = array();
		foreach ( self::fields() as $key => $label ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? '0' : '1';
		}
		return $clean;
	}

	/* ---------- Email guard ---------- */

	/** Is this a front-end HTML page the guard should rewrite? */
	public static function guard_applies() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_trackback() || ( function_exists( 'is_favicon' ) && is_favicon() ) ) {
			return false;
		}
		if ( '' !== (string) get_query_var( 'sitemap' ) || '' !== (string) get_query_var( 'sitemap-stylesheet' ) ) {
			return false;
		}
		if ( ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) || ( function_exists( 'wp_is_xml_request' ) && wp_is_xml_request() ) ) {
			return false;
		}
		return true;
	}

	public static function maybe_guard() {
		if ( ! (bool) apply_filters( 'scout_core_email_guard', self::on( 'email_guard' ) ) ) {
			return;
		}
		if ( ! self::guard_applies() ) {
			return;
		}
		self::$open = '';
		ob_start( array( __CLASS__, 'buffer' ) );
	}

	/**
	 * Output-buffer callback. Never breaks the page: anything unexpected sends
	 * the original HTML.
	 *
	 * @param string $buffer The page (or a chunk of it, after a mid-page flush).
	 * @return string
	 */
	public static function buffer( $buffer ) {
		if ( ! is_string( $buffer ) || '' === $buffer || false === strpos( $buffer, '@' ) ) {
			return $buffer;
		}
		if ( ! self::is_html_response( $buffer ) ) {
			return $buffer;
		}
		try {
			$out = self::guard_html( $buffer );
			return is_string( $out ) ? $out : $buffer;
		} catch ( \Throwable $e ) {
			return $buffer;
		}
	}

	/** Content type is HTML (or unset) and the body is not JSON or XML. */
	private static function is_html_response( $buffer ) {
		if ( function_exists( 'headers_list' ) ) {
			foreach ( headers_list() as $header ) {
				if ( 0 === stripos( $header, 'content-type:' ) ) {
					if ( ! preg_match( '#text/html|application/xhtml\+xml#i', $header ) ) {
						return false;
					}
				}
			}
		}
		$start = ltrim( substr( $buffer, 0, 64 ) );
		if ( '' !== $start && ( '{' === $start[0] || '[' === $start[0] || 0 === strncasecmp( $start, '<?xml', 5 ) ) ) {
			return false;
		}
		return false !== strpos( $buffer, '<' ) || '' !== self::$open;
	}

	/**
	 * Guard every email in an HTML string. Pure string work, so the test
	 * harness can call it without WordPress. Idempotent: an encoded address
	 * has no "@" left to match.
	 *
	 * @param string $html Page HTML (or a chunk, continuing self::$open).
	 * @return string|null Null when a regex fails (caller sends the original).
	 */
	public static function guard_html( $html ) {
		if ( false === strpos( $html, '@' ) ) {
			return $html;
		}
		$out = '';
		$pos = 0;
		$len = strlen( $html );

		// Still inside a raw-text element from the previous chunk.
		if ( '' !== self::$open ) {
			$close = self::find_close( $html, 0, self::$open );
			if ( null === $close ) {
				return $html; // The whole chunk is inside it.
			}
			$out       .= 'ldjson' === self::$open ? self::guard_json( substr( $html, 0, $close ) ) : substr( $html, 0, $close );
			$pos        = $close;
			self::$open = '';
		}

		// Protected regions: comments, CDATA, and the raw-text elements.
		$pattern = '#<!--|<!\[CDATA\[|<(script|style|textarea|title|xmp|iframe|noembed|noframes|plaintext)\b([^>]*)>#i';
		while ( $pos < $len ) {
			if ( ! preg_match( $pattern, $html, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
				$text = self::guard_markup( substr( $html, $pos ) );
				if ( null === $text ) {
					return null;
				}
				$out .= $text;
				break;
			}
			$at   = $m[0][1];
			$text = self::guard_markup( substr( $html, $pos, $at - $pos ) );
			if ( null === $text ) {
				return null;
			}
			$out .= $text;

			if ( '<!--' === $m[0][0] || 0 === strncasecmp( $m[0][0], '<![CDATA[', 9 ) ) {
				$end_tok = '<!--' === $m[0][0] ? '-->' : ']]>';
				$end     = strpos( $html, $end_tok, $at + strlen( $m[0][0] ) );
				$end     = false === $end ? $len : $end + strlen( $end_tok );
				$out    .= substr( $html, $at, $end - $at );
				$pos     = $end;
				continue;
			}

			$tag     = strtolower( $m[1][0] );
			$open    = $m[0][0];
			$body_at = $at + strlen( $open );
			$out    .= $open;

			$kind = $tag;
			if ( 'script' === $tag && preg_match( '#\btype\s*=\s*(["\']?)\s*application/ld\+json\s*\1#i', $m[2][0] ) ) {
				$kind = 'ldjson';
			}
			$close = self::find_close( $html, $body_at, $kind );
			if ( null === $close ) {
				// Runs past the end of this chunk; carry the state forward.
				$body       = substr( $html, $body_at );
				$out       .= 'ldjson' === $kind ? self::guard_json( $body ) : self::raw_body( $tag, $body );
				self::$open = $kind;
				break;
			}
			$body = substr( $html, $body_at, $close - $body_at );
			$out .= 'ldjson' === $kind ? self::guard_json( $body ) : self::raw_body( $tag, $body );
			$pos  = $close;
		}
		return $out;
	}

	/** The title is shown text (entities decode there); everything else raw stays as is. */
	private static function raw_body( $tag, $body ) {
		if ( 'title' === $tag ) {
			$guarded = self::guard_text( $body );
			return null === $guarded ? $body : $guarded;
		}
		return $body;
	}

	/** Offset of the closing tag for a raw-text element, or null when not in this chunk. */
	private static function find_close( $html, $from, $kind ) {
		$tag = 'ldjson' === $kind ? 'script' : $kind;
		if ( 'plaintext' === $tag ) {
			return null; // Never closes.
		}
		if ( preg_match( '#</' . $tag . '\s*>#i', $html, $m, PREG_OFFSET_CAPTURE, $from ) ) {
			return $m[0][1];
		}
		return null;
	}

	/**
	 * Ordinary markup: split into tags and text in one pass. Text gets its
	 * emails encoded; inside tags only an href="mailto:..." value is touched.
	 */
	private static function guard_markup( $chunk ) {
		if ( '' === $chunk || false === strpos( $chunk, '@' ) ) {
			return $chunk;
		}
		return preg_replace_callback(
			'#(<[A-Za-z](?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>)|([^<]++)|(<)#',
			static function ( $m ) {
				if ( isset( $m[1] ) && '' !== $m[1] ) {
					return self::guard_tag( $m[1] );
				}
				if ( isset( $m[2] ) && '' !== $m[2] ) {
					$t = self::guard_text( $m[2] );
					return null === $t ? $m[2] : $t;
				}
				return $m[0];
			},
			$chunk
		);
	}

	/** An opening tag: encode the address in a mailto href, leave every other attribute. */
	private static function guard_tag( $tag ) {
		if ( false === strpos( $tag, '@' ) || false === stripos( $tag, 'mailto:' ) ) {
			return $tag;
		}
		$out = preg_replace_callback(
			'#(\shref\s*=\s*)(?:"(mailto:[^"]*)"|\'(mailto:[^\']*)\'|(mailto:[^\s>"\']+))#i',
			static function ( $m ) {
				$quote = '';
				if ( isset( $m[2] ) && '' !== $m[2] ) {
					$value = $m[2];
					$quote = '"';
				} elseif ( isset( $m[3] ) && '' !== $m[3] ) {
					$value = $m[3];
					$quote = "'";
				} else {
					$value = $m[4];
				}
				$guarded = self::guard_text( $value );
				return $m[1] . $quote . ( null === $guarded ? $value : $guarded ) . $quote;
			},
			$tag
		);
		return null === $out ? $tag : $out;
	}

	/** Encode every email address in a run of text. */
	public static function guard_text( $text ) {
		if ( false === strpos( $text, '@' ) ) {
			return $text;
		}
		return preg_replace_callback(
			'#(?<![A-Za-z0-9._%+\-&\#])' . self::EMAIL . '(?![A-Za-z0-9\-])#',
			static function ( $m ) {
				return self::encode( $m[0] );
			},
			$text
		);
	}

	/**
	 * Mixed decimal and hex character references, like antispambot(), but the
	 * same output every time (stable cached pages). "@" is always encoded.
	 */
	public static function encode( $email ) {
		$out = '';
		$len = strlen( $email );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $email[ $i ];
			if ( '@' === $c ) {
				$out .= '&#64;';
				continue;
			}
			switch ( $i % 3 ) {
				case 0:
					$out .= '&#' . ord( $c ) . ';';
					break;
				case 1:
					$out .= '&#x' . dechex( ord( $c ) ) . ';';
					break;
				default:
					$out .= $c;
			}
		}
		return $out;
	}

	/**
	 * JSON-LD: escape "@" inside email addresses as \u0040. Outside strings
	 * JSON has no letters next to "@", and the escape is inserted after a
	 * whole character, never inside another escape, so the JSON stays valid.
	 */
	public static function guard_json( $json ) {
		if ( false === strpos( $json, '@' ) ) {
			return $json;
		}
		$out = preg_replace(
			'#(?<=[A-Za-z0-9._%+\-])@(?=[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?)*\.[A-Za-z]{2,24}(?![A-Za-z0-9\-]))#',
			'\\\\u0040',
			$json
		);
		return null === $out ? $json : $out;
	}

	/* ---------- Comments and pingbacks ---------- */

	public static function maybe_close_comments() {
		if ( ! (bool) apply_filters( 'scout_core_close_comments', self::on( 'close_comments' ) ) ) {
			return;
		}
		if ( ! is_admin() ) {
			add_filter( 'comments_open', '__return_false', 20 );
			add_filter( 'pings_open', '__return_false', 20 );
		}
		add_filter( 'wp_headers', array( __CLASS__, 'drop_pingback_header' ) );
		add_filter( 'xmlrpc_methods', array( __CLASS__, 'drop_pingback_methods' ) );
	}

	public static function drop_pingback_header( $headers ) {
		if ( is_array( $headers ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	public static function drop_pingback_methods( $methods ) {
		if ( is_array( $methods ) ) {
			unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		}
		return $methods;
	}

	/* ---------- Settings tab ---------- */

	public static function render_tab() {
		$values = self::get();
		$notes  = array(
			'email_guard'    => 'Email addresses on your pages are coded so people see and click them as usual, while the bots that collect addresses for spam miss them. Search engines still read the email in your business details.',
			'close_comments' => 'Turns off new comments and pingbacks on every page and post, which mostly bring spam. Comments already saved stay in WordPress.',
		);
		echo '<form method="post" action="options.php">';
		settings_fields( 'scout_protection_group' );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( self::fields() as $key => $label ) {
			$id   = 'scout-protect-' . $key;
			$name = self::OPTION . '[' . $key . ']';
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			printf(
				'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s /> %4$s</label>',
				esc_attr( $id ),
				esc_attr( $name ),
				checked( '1', $values[ $key ], false ),
				esc_html__( 'On', 'scout-core' )
			);
			echo '<p class="description">' . esc_html( $notes[ $key ] ) . '</p>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}
}
