<?php
/**
 * The update report on Scout tools, Updates (Joy, 2026-11-05): one plain-text
 * block with everything needed to update a site, in the same order as the
 * screen, for pasting into the chat.
 *
 * Only text building lives here, so it runs without WordPress
 * (tools/tests/update-report.php). Scout_Hub_Admin gathers the facts and
 * passes them in; the Site status part is the Site status copy, word for word.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Update_Report {

	/** Scout Core goes first among waiting updates: every other Scout plugin calls it. */
	const CORE = 'scout-core';

	/**
	 * Plugin rows with every waiting update on top, Scout Core first among them,
	 * then the rest, each group by name.
	 *
	 * @param array<int,array{slug:string,name:string,available:string}> $rows
	 * @return array<int,array>
	 */
	public static function sort( array $rows ): array {
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$wait_a = '' !== (string) ( $a['available'] ?? '' );
				$wait_b = '' !== (string) ( $b['available'] ?? '' );
				if ( $wait_a !== $wait_b ) {
					return $wait_a ? -1 : 1;
				}
				if ( $wait_a ) {
					$core_a = self::CORE === ( $a['slug'] ?? '' );
					$core_b = self::CORE === ( $b['slug'] ?? '' );
					if ( $core_a !== $core_b ) {
						return $core_a ? -1 : 1;
					}
				}
				return strcasecmp( (string) ( $a['name'] ?? $a['slug'] ?? '' ), (string) ( $b['name'] ?? $b['slug'] ?? '' ) );
			}
		);
		return array_values( $rows );
	}

	/**
	 * Site status rows counted the way the copied report marks them: [ok], [look], [fix].
	 *
	 * @param array<int,array{label:string,state:string}> $rows state is good, warn or bad.
	 * @return array{ok:int,look:int,fix:int,fix_names:string[]}
	 */
	public static function counts( array $rows ): array {
		$out = array( 'ok' => 0, 'look' => 0, 'fix' => 0, 'fix_names' => array() );
		foreach ( $rows as $row ) {
			$state = (string) ( $row['state'] ?? '' );
			if ( 'good' === $state ) {
				++$out['ok'];
			} elseif ( 'bad' === $state ) {
				++$out['fix'];
				$out['fix_names'][] = (string) ( $row['label'] ?? '' );
			} else {
				++$out['look'];
			}
		}
		return $out;
	}

	/** Email addresses and keys out of a line we wrote. */
	public static function scrub( string $text ): string {
		$text = (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email hidden]', $text );
		return self::scrub_keys( $text );
	}

	/**
	 * Anything shaped like an access key: GitHub tokens, API secrets, AWS and Slack keys.
	 * Run over the whole report, Site status part included; a real report never has one.
	 */
	public static function scrub_keys( string $text ): string {
		return (string) preg_replace( '/\b(github_pat_[A-Za-z0-9_]{20,}|gh[pousr]_[A-Za-z0-9]{20,}|sk-[A-Za-z0-9_\-]{20,}|AKIA[0-9A-Z]{16}|xox[abpr]-[A-Za-z0-9\-]{10,})\b/', '[key hidden]', $text );
	}

	/**
	 * The whole report.
	 *
	 * @param array $d {
	 *     @type string $site     Site name.
	 *     @type string $url      Home address.
	 *     @type string $time     When the report was made, e.g. "2026-11-05 14:10".
	 *     @type string $wp       WordPress version.
	 *     @type string $php      PHP version.
	 *     @type array  $theme    name, version, state (one short line), key (saved, not set, set in wp-config.php, or '').
	 *     @type string $checked  When the versions were last checked, e.g. "5 minutes ago", or ''.
	 *     @type bool   $offline  True when GitHub could not be reached.
	 *     @type array  $plugins  Rows: slug, name, installed, available, on, status.
	 *     @type string $copy     Page copy state, one line.
	 *     @type string $changes  Change log state, one line.
	 *     @type array  $checks   From counts().
	 *     @type string $health   The Site status report text, word for word.
	 * }
	 */
	public static function text( array $d ): string {
		$plugins = self::sort( (array) ( $d['plugins'] ?? array() ) );
		$waiting = array_values( array_filter( $plugins, static fn( $p ) => '' !== (string) ( $p['available'] ?? '' ) ) );
		$theme   = (array) ( $d['theme'] ?? array() );
		$checks  = (array) ( $d['checks'] ?? array() ) + array( 'ok' => 0, 'look' => 0, 'fix' => 0, 'fix_names' => array() );

		$lines = array(
			sprintf( 'Update report: %s (%s)', (string) ( $d['site'] ?? '' ), (string) ( $d['url'] ?? '' ) ),
			'Made ' . (string) ( $d['time'] ?? '' ),
			sprintf( 'WordPress %s, PHP %s, theme %s %s', (string) ( $d['wp'] ?? '' ), (string) ( $d['php'] ?? '' ), (string) ( $theme['name'] ?? '' ), (string) ( $theme['version'] ?? '' ) ),
			'',
			'1. CHECK FOR NEW VERSIONS',
		);
		if ( ! empty( $d['offline'] ) ) {
			$lines[] = 'Could not reach GitHub on the last check.';
		}
		$lines[] = '' !== (string) ( $d['checked'] ?? '' ) ? 'Last checked ' . $d['checked'] . '.' : 'Not checked yet.';

		$lines[] = '';
		$lines[] = sprintf( '2. PLUGINS (%d waiting)', count( $waiting ) );
		if ( $waiting && self::CORE === ( $waiting[0]['slug'] ?? '' ) ) {
			$lines[] = 'Update Scout Core first.';
		}
		foreach ( $plugins as $p ) {
			$name    = (string) ( $p['name'] ?? $p['slug'] ?? '' ) . ( empty( $p['on'] ) ? ' (off)' : '' );
			$version = (string) ( $p['installed'] ?? '' );
			$new     = (string) ( $p['available'] ?? '' );
			$line    = '- ' . $name . ' ' . $version . ( '' !== $new ? ', new ' . $new : '' );
			$status  = (string) ( $p['status'] ?? '' );
			$lines[] = $line . ( '' !== $status ? ': ' . $status : '' );
		}

		$lines[] = '';
		$lines[] = '3. THEME';
		$lines[] = trim( (string) ( $theme['name'] ?? '' ) . ' ' . (string) ( $theme['version'] ?? '' ) ) . ( '' !== (string) ( $theme['state'] ?? '' ) ? ': ' . $theme['state'] : '' );
		if ( '' !== (string) ( $theme['key'] ?? '' ) ) {
			$lines[] = 'Theme key: ' . $theme['key'];
		}

		$lines[] = '';
		$lines[] = '4. PAGE COPY';
		$lines[] = (string) ( $d['copy'] ?? '' );

		$lines[] = '';
		$lines[] = '5. BRING CHANGES BACK';
		$lines[] = (string) ( $d['changes'] ?? '' );

		$lines[] = '';
		$lines[] = '6. CHECK THE SITE';
		$lines[] = sprintf( '%d ok, %d look, %d fix.', $checks['ok'], $checks['look'], $checks['fix'] ) . ( $checks['fix_names'] ? ' Fix: ' . implode( ', ', $checks['fix_names'] ) . '.' : '' );

		// Everything above is ours, so it never carries an address or a key.
		$text = self::scrub( implode( "\n", $lines ) );

		// The Site status copy, unchanged, so it reads the same as the Copy the report button there.
		$health = trim( (string) ( $d['health'] ?? '' ) );
		if ( '' !== $health ) {
			$text .= "\n\nSITE STATUS\n" . $health;
		}
		return self::scrub_keys( $text );
	}
}
