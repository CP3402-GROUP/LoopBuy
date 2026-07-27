<?php
/**
 * The template for displaying the Saved Items page.
 *
 * WordPress automatically uses this file for a Page whose slug is "saved"
 * (template hierarchy: page-saved.php) — create a Page titled "Saved"
 * with the slug "saved" in wp-admin and it will pick this up automatically.
 *
 * @package LoopBuy
 */

get_header();
?>

<main id="primary" class="site-main">
	<div class="page loopbuy-saved">

		<h1 class="loopbuy-page-title"><?php esc_html_e( 'Saved Items', 'loopbuy' ); ?></h1>

		<div class="loopbuy-empty-state">
			<svg width="56" height="56" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="loopbuy-empty-state-icon">
				<path d="M12 20.5s-7.5-4.6-10-9.3C.5 7.8 2.4 4.5 6 4.5c2.1 0 3.6 1.2 6 3.7 2.4-2.5 3.9-3.7 6-3.7 3.6 0 5.5 3.3 4 6.7-2.5 4.7-10 9.3-10 9.3Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
			</svg>

			<p class="loopbuy-empty-state-title"><?php esc_html_e( 'No saved items yet', 'loopbuy' ); ?></p>
			<p class="loopbuy-empty-state-text"><?php esc_html_e( 'Tap the heart on any listing to save it.', 'loopbuy' ); ?></p>

			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="loopbuy-empty-state-button"><?php esc_html_e( 'Browse listings', 'loopbuy' ); ?></a>
		</div>

	</div><!-- .loopbuy-saved -->
</main><!-- #primary -->

<?php
get_footer();