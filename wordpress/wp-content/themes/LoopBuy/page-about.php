<?php
/**
 * Template Name: About
 *
 * A custom page template for the "About" page. WordPress will also
 * auto-select this file for any page with the slug "about" (page-about.php),
 * but the Template Name header lets it be assigned manually from the
 * Page Attributes panel too.
 *
 * @package LoopBuy
 */

get_header();
?>

<main id="primary" class="site-main">

	<div class="page loopbuy-about">
		<div class="loopbuy-about-wrap">

			<header class="loopbuy-about-header">
				<h1 class="loopbuy-about-title"><?php esc_html_e( 'About SafeTrade', 'loopbuy' ); ?></h1>
				<p class="loopbuy-about-subtitle"><?php esc_html_e( 'SafeTrade is a community-driven marketplace for second-hand items — built around trust, safety, and smart tools so everyone can buy and sell with confidence.', 'loopbuy' ); ?></p>
			</header>

			<div class="loopbuy-about-features">

				<div class="loopbuy-about-feature">
					<span class="loopbuy-about-feature-icon" aria-hidden="true">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
					</span>
					<h2 class="loopbuy-about-feature-title"><?php esc_html_e( 'AI Scam Detection', 'loopbuy' ); ?></h2>
					<p class="loopbuy-about-feature-text"><?php esc_html_e( 'Every listing is screened for suspicious pricing, scam language, and duplicate images.', 'loopbuy' ); ?></p>
				</div>

				<div class="loopbuy-about-feature">
					<span class="loopbuy-about-feature-icon" aria-hidden="true">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8"/></svg>
					</span>
					<h2 class="loopbuy-about-feature-title"><?php esc_html_e( 'Smart Pricing', 'loopbuy' ); ?></h2>
					<p class="loopbuy-about-feature-text"><?php esc_html_e( 'Get AI price recommendations based on similar sold listings.', 'loopbuy' ); ?></p>
				</div>

				<div class="loopbuy-about-feature">
					<span class="loopbuy-about-feature-icon" aria-hidden="true">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
					</span>
					<h2 class="loopbuy-about-feature-title"><?php esc_html_e( 'Real-time Chat', 'loopbuy' ); ?></h2>
					<p class="loopbuy-about-feature-text"><?php esc_html_e( 'Message sellers directly with read receipts and conversation history.', 'loopbuy' ); ?></p>
				</div>

				<div class="loopbuy-about-feature">
					<span class="loopbuy-about-feature-icon" aria-hidden="true">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
					</span>
					<h2 class="loopbuy-about-feature-title"><?php esc_html_e( 'Trusted Reviews', 'loopbuy' ); ?></h2>
					<p class="loopbuy-about-feature-text"><?php esc_html_e( 'Rate sellers and build trust in your local community.', 'loopbuy' ); ?></p>
				</div>

			</div>

			<div class="loopbuy-about-cta">
				<span class="loopbuy-about-cta-icon" aria-hidden="true">
					<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
				</span>
				<h2 class="loopbuy-about-cta-title"><?php esc_html_e( 'Ready to start trading?', 'loopbuy' ); ?></h2>
				<p class="loopbuy-about-cta-text"><?php esc_html_e( 'Join a safer way to buy and sell second-hand.', 'loopbuy' ); ?></p>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="loopbuy-about-cta-button"><?php esc_html_e( 'Browse listings', 'loopbuy' ); ?></a>
			</div>

			<?php
			// Preserve any editor content entered for this page (e.g. extra
			// paragraphs added in the block editor) below the design blocks.
			while ( have_posts() ) :
				the_post();
				$content = get_the_content();
				if ( ! empty( trim( $content ) ) ) :
					?>
					<div class="entry-content loopbuy-about-entry">
						<?php the_content(); ?>
					</div>
					<?php
				endif;
			endwhile;
			?>

		</div><!-- .loopbuy-about-wrap -->
	</div><!-- .page.loopbuy-about -->

</main><!-- #primary -->

<?php
get_footer();