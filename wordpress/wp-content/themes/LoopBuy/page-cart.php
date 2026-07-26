<?php
/**
 * The template for displaying the Cart page.
 *
 * WordPress automatically uses this file for a Page whose slug is "cart"
 * (template hierarchy: page-cart.php) — create a Page titled "Cart"
 * with the slug "cart" in wp-admin and it will pick this up automatically.
 *
 * @package LoopBuy
 */

get_header();
?>

<main id="primary" class="site-main">
	<div class="page loopbuy-cart">

		<div class="loopbuy-empty-state">
			<svg width="56" height="56" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="loopbuy-empty-state-icon">
				<path d="M6 8V6a6 6 0 1 1 12 0v2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
				<path d="M4.5 8H19.5L18.6 19.2A2 2 0 0 1 16.6 21H7.4A2 2 0 0 1 5.4 19.2L4.5 8Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
			</svg>

			<p class="loopbuy-empty-state-title"><?php esc_html_e( 'Your cart is empty', 'loopbuy' ); ?></p>
			<p class="loopbuy-empty-state-text"><?php esc_html_e( 'Browse listings and add items you love.', 'loopbuy' ); ?></p>

			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="loopbuy-empty-state-button"><?php esc_html_e( 'Start browsing', 'loopbuy' ); ?></a>
		</div>

	</div><!-- .loopbuy-cart -->
</main><!-- #primary -->

<?php
get_footer();