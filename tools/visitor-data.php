<?php
/**
 * Visitor data lists (Site Standard rule 24).
 *
 * Each plugin keeps one file, <plugin>/visitor-data.json, saying what it keeps
 * about the people who visit the site. It ships inside the plugin, so a site's
 * privacy policy screen can read what the plugins that are on actually collect.
 * This script copies each list into the plugin's README, between
 * <!-- visitor-data:start --> and <!-- visitor-data:end -->, so the two never
 * disagree.
 *
 *   php tools/visitor-data.php          write every README section
 *   php tools/visitor-data.php --check  exit 1 when a list is missing, malformed or out of step
 *
 * The file:
 * {
 *   "plugin": "scout-chat",
 *   "items": [
 *     { "what": "...", "where": "...", "kept": "...", "shared": "...", "when": "..." }
 *   ],
 *   "cookies": [ { "name": "...", "why": "...", "lasts": "..." } ],
 *   "notes": "..."
 * }
 * "items" and "cookies" may be empty; "shared", "when" and "notes" are optional.
 * An empty list says so: the plugin keeps nothing about visitors.
 */

$root  = dirname( __DIR__ );
$check = in_array( '--check', $argv, true );
$bad   = 0;
$start = '<!-- visitor-data:start -->';
$end   = '<!-- visitor-data:end -->';

/** Render one list as README Markdown. */
function scout_visitor_md( array $d ): string {
	$out = "## Visitor data\n\n";
	$items   = (array) ( $d['items'] ?? array() );
	$cookies = (array) ( $d['cookies'] ?? array() );
	if ( ! $items && ! $cookies ) {
		$out .= "This plugin keeps nothing about the people who visit the site and sets no cookies.\n";
	}
	if ( $items ) {
		$out .= "| What | Where it is kept | How long | Shared with |\n|---|---|---|---|\n";
		foreach ( $items as $i ) {
			$what = $i['what'] . ( ! empty( $i['when'] ) ? ' (' . $i['when'] . ')' : '' );
			$out .= '| ' . implode( ' | ', array_map( static fn( $v ) => str_replace( '|', '/', (string) $v ), array( $what, $i['where'], $i['kept'], '' !== (string) ( $i['shared'] ?? '' ) ? $i['shared'] : 'Nobody' ) ) ) . " |\n";
		}
	}
	if ( $cookies ) {
		$out .= ( $items ? "\n" : '' ) . "| Cookie | Why | How long |\n|---|---|---|\n";
		foreach ( $cookies as $c ) {
			$out .= '| `' . $c['name'] . '` | ' . str_replace( '|', '/', $c['why'] ) . ' | ' . str_replace( '|', '/', $c['lasts'] ) . " |\n";
		}
	}
	if ( ! empty( $d['notes'] ) ) {
		$out .= "\n" . trim( (string) $d['notes'] ) . "\n";
	}
	return $out . "\nSource: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.\n";
}

/** Problems with one list, as sentences. */
function scout_visitor_problems( $d, string $slug ): array {
	if ( ! is_array( $d ) ) {
		return array( 'visitor-data.json is not valid JSON' );
	}
	$p = array();
	if ( ( $d['plugin'] ?? '' ) !== $slug ) {
		$p[] = '"plugin" must be "' . $slug . '"';
	}
	foreach ( (array) ( $d['items'] ?? array() ) as $n => $i ) {
		foreach ( array( 'what', 'where', 'kept' ) as $k ) {
			if ( '' === trim( (string) ( $i[ $k ] ?? '' ) ) ) {
				$p[] = "item $n has no \"$k\"";
			}
		}
	}
	foreach ( (array) ( $d['cookies'] ?? array() ) as $n => $c ) {
		foreach ( array( 'name', 'why', 'lasts' ) as $k ) {
			if ( '' === trim( (string) ( $c[ $k ] ?? '' ) ) ) {
				$p[] = "cookie $n has no \"$k\"";
			}
		}
	}
	$text = json_encode( $d );
	if ( false !== strpos( (string) $text, '—' ) ) {
		$p[] = 'uses an em dash';
	}
	return $p;
}

foreach ( glob( $root . '/scout-*', GLOB_ONLYDIR ) as $dir ) {
	$slug = basename( $dir );
	if ( ! file_exists( "$dir/$slug.php" ) ) {
		continue;
	}
	$file = "$dir/visitor-data.json";
	if ( ! file_exists( $file ) ) {
		echo "MISSING $slug/visitor-data.json\n";
		$bad++;
		continue;
	}
	$data = json_decode( (string) file_get_contents( $file ), true );
	$why  = scout_visitor_problems( $data, $slug );
	if ( $why ) {
		echo "BAD $slug: " . implode( '; ', $why ) . "\n";
		$bad++;
		continue;
	}
	$readme = "$dir/README.md";
	$text   = file_exists( $readme ) ? (string) file_get_contents( $readme ) : "# $slug\n";
	$block  = $start . "\n" . scout_visitor_md( $data ) . $end;
	$a      = strpos( $text, $start );
	$b      = strpos( $text, $end );
	$next   = false !== $a && false !== $b ? substr( $text, 0, $a ) . $block . substr( $text, $b + strlen( $end ) ) : rtrim( $text ) . "\n\n" . $block . "\n";
	if ( $next === $text ) {
		continue;
	}
	if ( $check ) {
		echo "STALE $slug/README.md: run php tools/visitor-data.php\n";
		$bad++;
	} else {
		file_put_contents( $readme, $next );
		echo "wrote $slug/README.md\n";
	}
}
if ( $check ) {
	echo $bad ? "\n$bad visitor data lists need work\n" : "Every plugin lists its visitor data\n";
}
exit( $check && $bad ? 1 : 0 );
