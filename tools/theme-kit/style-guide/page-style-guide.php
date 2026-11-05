<?php
/**
 * Scout theme kit: the /style-guide/ page for a classic theme.
 *
 * Copy this file into the theme root as page-style-guide.php. WordPress picks
 * it for the page whose slug is style-guide, so no template name is needed.
 * The sections come from inc/scout-style-guide.php; this file only wraps
 * them in the theme's own header and footer.
 *
 * Canonical copy: Scout-Plugins/tools/theme-kit/style-guide/page-style-guide.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="main" class="scout-style-guide-page">
	<?php
	if ( function_exists( 'scout_style_guide' ) ) {
		scout_style_guide();
	} else {
		echo '<p>' . esc_html__( 'The style guide include is missing. Require inc/scout-style-guide.php from functions.php.', 'scout' ) . '</p>';
	}
	?>
</main>
<?php
get_footer();
