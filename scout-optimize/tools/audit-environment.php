<?php
/**
 * Scout Optimize — Stage A environment audit.
 *
 * Run on a WP Engine STAGING environment via WP-CLI:
 *
 *     wp eval-file tools/audit-environment.php
 *
 * Read-only: gathers the facts the build plan's Stage A gate needs (Imagick
 * capability, PHP limits, library size). Makes no changes of any kind.
 *
 * @package Scout_Optimize
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "This script is intended to be run via: wp eval-file tools/audit-environment.php\n";
	exit( 1 );
}

$report   = array();
$warnings = array();

/* -------------------------------------------------- Core versions & limits */

$report['WordPress version'] = get_bloginfo( 'version' );
$report['PHP version']       = PHP_VERSION;
$report['memory_limit']      = ini_get( 'memory_limit' );
$report['max_execution_time'] = ini_get( 'max_execution_time' ) . 's';
$report['upload_max_filesize'] = ini_get( 'upload_max_filesize' );
$report['post_max_size']     = ini_get( 'post_max_size' );
$report['big_image_size_threshold'] = (string) apply_filters( 'big_image_size_threshold', 2560 );

/* ------------------------------------------------------------ Image engine */

if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
	$imagick_version            = Imagick::getVersion();
	$report['Imagick']          = 'present';
	$report['ImageMagick build'] = isset( $imagick_version['versionString'] ) ? $imagick_version['versionString'] : 'unknown';

	$formats                = array_map( 'strtoupper', Imagick::queryFormats() );
	$report['WEBP encode']  = in_array( 'WEBP', $formats, true ) ? 'YES' : 'NO';
	$report['AVIF encode']  = in_array( 'AVIF', $formats, true ) ? 'YES (not used in v1)' : 'NO (expected; not used in v1)';
	$report['JPEG/PNG/GIF'] = ( in_array( 'JPEG', $formats, true ) && in_array( 'PNG', $formats, true ) && in_array( 'GIF', $formats, true ) ) ? 'YES' : 'PARTIAL — investigate';

	if ( 'NO' === $report['WEBP encode'] ) {
		$warnings[] = 'Imagick lacks WEBP encoding — v1 engine plan needs revisiting (GD fallback or host ticket).';
	}
} else {
	$report['Imagick'] = 'MISSING';
	$warnings[]        = 'Imagick not available — the v1 engine decision assumed it. STOP and surface to operator.';
}

$report['GD'] = extension_loaded( 'gd' ) ? 'present (fallback available)' : 'absent';

if ( function_exists( 'gd_info' ) ) {
	$gd = gd_info();
	$report['GD WebP support'] = ! empty( $gd['WebP Support'] ) ? 'YES' : 'NO';
}

/* -------------------------------------------------------- Media library size */

global $wpdb;

$mime_counts = $wpdb->get_results(
	"SELECT post_mime_type, COUNT(*) AS n
	 FROM {$wpdb->posts}
	 WHERE post_type = 'attachment'
	 GROUP BY post_mime_type
	 ORDER BY n DESC"
);

$total_attachments = 0;
foreach ( $mime_counts as $row ) {
	$total_attachments += (int) $row->n;
}
$report['Attachments (total)'] = (string) $total_attachments;
foreach ( $mime_counts as $row ) {
	$report[ '  · ' . $row->post_mime_type ] = (string) $row->n;
}

$uploads     = wp_get_upload_dir();
$upload_path = $uploads['basedir'];

$bytes_total = 0;
$files_total = 0;
$files_large = 0; // > 1 MB — the sweep-candidate count.

if ( is_dir( $upload_path ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $upload_path, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $file ) {
		if ( $file->isFile() ) {
			$size         = $file->getSize();
			$bytes_total += $size;
			$files_total++;
			if ( $size > MB_IN_BYTES ) {
				$files_large++;
			}
		}
	}
	$report['Uploads dir']            = $upload_path;
	$report['Uploads size']           = size_format( $bytes_total, 2 );
	$report['Files in uploads']       = (string) $files_total;
	$report['Files > 1MB (sweep candidates)'] = (string) $files_large;
} else {
	$warnings[] = 'Uploads directory not found at ' . $upload_path;
}

$disk_free = @disk_free_space( $upload_path );
if ( false !== $disk_free ) {
	$report['Disk free (uploads volume)'] = size_format( $disk_free, 2 );
	// Originals preservation roughly doubles image storage during rollout.
	if ( $disk_free < $bytes_total ) {
		$warnings[] = 'Free disk is smaller than the current uploads dir — originals preservation may not fit. Surface to operator before any sweep.';
	}
}

/* ----------------------------------------------------------------- Output */

WP_CLI::log( '' );
WP_CLI::log( '=== Scout Optimize — Stage A Environment Audit (read-only) ===' );
foreach ( $report as $key => $value ) {
	WP_CLI::log( str_pad( $key, 36 ) . ' : ' . $value );
}

if ( $warnings ) {
	WP_CLI::log( '' );
	foreach ( $warnings as $warning ) {
		WP_CLI::warning( $warning );
	}
	WP_CLI::log( 'Gate result: BLOCKERS FOUND — resolve with operator before Stage B.' );
} else {
	WP_CLI::log( '' );
	WP_CLI::success( 'Gate result: no blockers — Imagick path confirmed for Stage B.' );
}
