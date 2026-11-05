<?php
/**
 * The page template for the theme's own card look (routes.php, render-theme.php).
 *
 * @package Scout_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

list( $scout_cards_view, $scout_cards_who ) = $GLOBALS['scout_cards_theme_view'];
if ( 'links' === $scout_cards_view ) {
	Scout_Cards_Render_Theme::links_page();
} elseif ( 'share' === $scout_cards_view ) {
	Scout_Cards_Render_Theme::share_page( $scout_cards_who );
} else {
	Scout_Cards_Render_Theme::card_page( $scout_cards_who );
}
