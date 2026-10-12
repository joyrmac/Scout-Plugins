<?php
/**
 * A reviews section: rating summary, review cards, optional disclaimer.
 *
 * Copy to {theme}/scout-reviews/section.php to change the markup.
 *
 * @var array  $reviews    Normalized reviews (Scout_Reviews_Query::normalize()).
 * @var array  $totals     Platform totals (Scout_Reviews_Query::totals()).
 * @var string $layout     grid | row | list.
 * @var string $disclaimer Text from Reviews > Settings, may be empty.
 *
 * @package Scout_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="scout-reviews scout-reviews--<?php echo esc_attr( $layout ); ?>" aria-label="<?php esc_attr_e( 'Reviews', 'scout-reviews' ); ?>">
	<?php
	if ( $totals ) {
		echo Scout_Reviews_Render::template( 'summary', array( 'totals' => $totals ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
	}
	?>

	<?php if ( $reviews ) : ?>
		<div class="scout-reviews__list"<?php echo 'row' === $layout ? ' tabindex="0"' : ''; ?>>
			<?php
			foreach ( $reviews as $review ) {
				echo Scout_Reviews_Render::template( 'card', array( 'review' => $review ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
			}
			?>
		</div>
	<?php endif; ?>

	<?php if ( '' !== trim( $disclaimer ) ) : ?>
		<p class="scout-reviews__disclaimer"><?php echo esc_html( $disclaimer ); ?></p>
	<?php endif; ?>
</section>
