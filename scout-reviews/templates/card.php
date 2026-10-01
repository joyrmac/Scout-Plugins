<?php
/**
 * One review card.
 *
 * Copy to {theme}/scout-reviews/card.php to change the markup.
 *
 * @var array $review Normalized review (Scout_Reviews_Query::normalize()).
 *
 * @package Scout_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$scout_source = $review['source'];
$scout_date   = Scout_Reviews_Render::date( $review['date'] );
?>
<article class="scout-review scout-review--<?php echo esc_attr( $scout_source ); ?>">
	<div class="scout-review__top">
		<span class="scout-review__badge"><?php echo esc_html( Scout_Reviews_Sources::badge( $scout_source ) ); ?></span>
		<?php if ( $review['rating'] > 0 ) : ?>
			<?php echo Scout_Reviews_Render::stars( (float) $review['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
		<?php endif; ?>
	</div>

	<blockquote class="scout-review__text"<?php echo $review['url'] ? ' cite="' . esc_url( $review['url'] ) . '"' : ''; ?>>
		<?php echo wpautop( esc_html( $review['text'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- text escaped before wpautop. ?>
	</blockquote>

	<footer class="scout-review__footer">
		<?php if ( $review['photo'] ) : ?>
			<img class="scout-review__avatar" src="<?php echo esc_url( $review['photo'] ); ?>" alt="" width="40" height="40" loading="lazy" decoding="async" />
		<?php else : ?>
			<span class="scout-review__avatar scout-review__avatar--initial" aria-hidden="true"><?php echo esc_html( Scout_Reviews_Render::initial( $review['name'] ) ); ?></span>
		<?php endif; ?>

		<div class="scout-review__who">
			<span class="scout-review__name"><?php echo esc_html( $review['name'] ); ?></span>
			<?php if ( $review['detail'] || $scout_date ) : ?>
				<span class="scout-review__meta">
					<?php echo esc_html( implode( ' · ', array_filter( array( $review['detail'], $scout_date ) ) ) ); ?>
				</span>
			<?php endif; ?>
		</div>

		<?php if ( $review['url'] ) : ?>
			<a class="scout-review__link" href="<?php echo esc_url( $review['url'] ); ?>" target="_blank" rel="noopener">
				<?php echo esc_html( Scout_Reviews_Sources::view_text( $scout_source ) ); ?>
				<span class="screen-reader-text">
					<?php
					/* translators: %s: reviewer name. */
					echo esc_html( sprintf( __( '(review by %s, opens in a new tab)', 'scout-reviews' ), $review['name'] ) );
					?>
				</span>
				<span aria-hidden="true">&rsaquo;</span>
			</a>
		<?php endif; ?>
	</footer>
</article>
