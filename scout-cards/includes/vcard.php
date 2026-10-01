<?php
/**
 * Save contact: a vCard 3.0 built on the fly from the settings, served from a
 * URL with no ".vcf" ending (some hosts treat file extensions as static files
 * and never reach WordPress). The photo is a 400px JPEG, because phones read
 * JPEG most reliably: the uploaded photo, else the Site Icon for the team card.
 *
 * @package Scout_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Cards_VCard {

	public static function escape( $s ) {
		return str_replace( array( '\\', ',', ';', "\n" ), array( '\\\\', '\\,', '\\;', '\\n' ), (string) $s );
	}

	/** Fold a line at 75 octets; continuation lines start with a space. */
	public static function fold( $line ) {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out  = substr( $line, 0, 75 );
		$rest = substr( $line, 75 );
		while ( '' !== $rest && false !== $rest ) {
			$out .= "\r\n " . substr( $rest, 0, 74 );
			$rest = substr( $rest, 74 );
		}
		return $out;
	}

	/** JPEG bytes from an attachment, resized to 400x400, or ''. */
	public static function photo( $attachment_id ) {
		if ( ! $attachment_id ) {
			return '';
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return '';
		}
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return '';
		}
		$editor->resize( 400, 400, true );
		// wp_tempnam() only loads in the admin, so build the temp path by hand.
		$tmp   = trailingslashit( get_temp_dir() ) . 'scout-card-' . wp_generate_password( 8, false ) . '.jpg';
		$saved = $editor->save( $tmp, 'image/jpeg' );
		if ( is_wp_error( $saved ) || ! file_exists( $saved['path'] ) ) {
			return '';
		}
		$bytes = (string) file_get_contents( $saved['path'] );
		wp_delete_file( $saved['path'] );
		return $bytes;
	}

	public static function build( array $p ) {
		$s     = Scout_Cards_Settings::get();
		$lines = array( 'BEGIN:VCARD', 'VERSION:3.0' );
		if ( 'team' === $p['key'] ) {
			$lines[] = 'N:;;;;';
			$lines[] = 'FN:' . self::escape( $s['name'] );
			$lines[] = 'X-ABShowAs:COMPANY';
		} else {
			$lines[] = 'N:' . self::escape( $p['last'] ) . ';' . self::escape( $p['first'] ) . ';;;';
			$lines[] = 'FN:' . self::escape( $p['name'] );
			if ( $p['title'] ) {
				$lines[] = 'TITLE:' . self::escape( $p['title'] );
			}
		}
		$lines[] = 'ORG:' . self::escape( $s['name'] );
		if ( $p['email'] ) {
			$lines[] = 'EMAIL;TYPE=WORK,INTERNET:' . $p['email'];
		}
		$tel = Scout_Cards_Settings::phone_e164( $s['phone'] );
		if ( $tel ) {
			$lines[] = 'TEL;TYPE=WORK,VOICE:' . $tel;
		}
		if ( $s['city'] ) {
			$lines[] = 'ADR;TYPE=WORK:;;;' . self::escape( $s['city'] ) . ';' . self::escape( $s['region'] ) . ';' . self::escape( $s['postal'] ) . ';USA';
		}
		$lines[] = 'URL;TYPE=WORK:' . home_url( '/' );
		if ( $s['note'] ) {
			$lines[] = 'NOTE:' . self::escape( $s['note'] );
		}
		$photo_id = $p['photo_id'] ? $p['photo_id'] : ( 'team' === $p['key'] ? (int) get_option( 'site_icon' ) : 0 );
		$photo    = self::photo( $photo_id );
		if ( '' !== $photo ) {
			$lines[] = 'PHOTO;ENCODING=b;TYPE=JPEG:' . base64_encode( $photo ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$lines[] = 'END:VCARD';
		return implode( "\r\n", array_map( array( __CLASS__, 'fold' ), $lines ) ) . "\r\n";
	}

	public static function send( array $p ) {
		nocache_headers();
		header( 'Content-Type: text/vcard; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . ( $p['file'] ? $p['file'] : 'contact' ) . '.vcf"' );
		header( 'X-Robots-Tag: noindex' );
		echo self::build( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- vCard text, not HTML.
	}
}
