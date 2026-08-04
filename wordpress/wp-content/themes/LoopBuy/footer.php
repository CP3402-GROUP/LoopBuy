<?php
/**
 * The template for displaying the footer
 *
 * Contains the closing of the #content div and all content after.
 *
 * @link https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package LoopBuy
 */

// Resolve the About and Contact pages by slug once, up front, so they
// keep working however permalinks are configured and are available to
// every footer column below (Company and Support both link to them).
// Falls back to /about/ and /contact/ if the pages haven't been
// created in wp-admin yet.
$loopbuy_about_page   = get_page_by_path( 'about' );
$loopbuy_about_url    = $loopbuy_about_page ? get_permalink( $loopbuy_about_page ) : home_url( '/about/' );
$loopbuy_contact_page = get_page_by_path( 'contact' );
$loopbuy_contact_url  = $loopbuy_contact_page ? get_permalink( $loopbuy_contact_page ) : home_url( '/contact/' );

$loopbuy_sell_page        = get_page_by_path( 'sell' );
$loopbuy_sell_url         = $loopbuy_sell_page ? get_permalink( $loopbuy_sell_page ) : home_url( '/sell/' );
$loopbuy_saved_page       = get_page_by_path( 'saved' );
$loopbuy_saved_url        = $loopbuy_saved_page ? get_permalink( $loopbuy_saved_page ) : home_url( '/saved/' );
$loopbuy_cart_page        = get_page_by_path( 'cart' );
$loopbuy_cart_url         = $loopbuy_cart_page ? get_permalink( $loopbuy_cart_page ) : home_url( '/cart/' );
$loopbuy_my_listings_page = get_page_by_path( 'my-listings' );
$loopbuy_my_listings_url  = $loopbuy_my_listings_page ? get_permalink( $loopbuy_my_listings_page ) : home_url( '/my-listings/' );

?>

	<footer id="colophon" class="site-footer">

		<div class="footer-widgets">

			<div class="footer-brand">
				<div class="site-branding">
					<?php
					if ( has_custom_logo() ) :
						the_custom_logo();
					else :
						?>
						<span class="site-icon" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M4 9L5.5 4H18.5L20 9" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M4 9H20V18C20 19.1046 19.1046 20 18 20H6C4.89543 20 4 19.1046 4 18V9Z" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/>
								<path d="M9 9V11C9 12.6569 10.3431 14 12 14C13.6569 14 15 12.6569 15 11V9" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</span>
						<?php
					endif;
					?>
					<p class="site-title"><a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a></p>
				</div><!-- .site-branding -->

				<p class="footer-tagline">
					<?php esc_html_e( 'Buy smarter. Sell safer. The trusted second-hand marketplace powered by AI scam detection.', 'loopbuy' ); ?>
				</p>
			</div><!-- .footer-brand -->

			<div class="footer-widget-column">
				<h2 class="widget-title"><?php esc_html_e( 'Marketplace', 'loopbuy' ); ?></h2>
				<?php if ( is_active_sidebar( 'footer-marketplace' ) ) : ?>
					<?php dynamic_sidebar( 'footer-marketplace' ); ?>
				<?php else : ?>
					<ul>
						<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Browse', 'loopbuy' ); ?></a></li>
						<li><a href="<?php echo esc_url( $loopbuy_sell_url ); ?>"><?php esc_html_e( 'Sell', 'loopbuy' ); ?></a></li>
						<li><a href="<?php echo esc_url( $loopbuy_saved_url ); ?>"><?php esc_html_e( 'Saved', 'loopbuy' ); ?></a></li>
						<li><a href="<?php echo esc_url( $loopbuy_cart_url ); ?>"><?php esc_html_e( 'Cart', 'loopbuy' ); ?></a></li>
					</ul>
				<?php endif; ?>
			</div>

			<div class="footer-widget-column">
				<h2 class="widget-title"><?php esc_html_e( 'Company', 'loopbuy' ); ?></h2>
				<?php if ( is_active_sidebar( 'footer-company' ) ) : ?>
					<?php dynamic_sidebar( 'footer-company' ); ?>
				<?php else : ?>
					<ul>
						<li><a href="<?php echo esc_url( $loopbuy_about_url ); ?>"><?php esc_html_e( 'About', 'loopbuy' ); ?></a></li>
						<li><a href="<?php echo esc_url( $loopbuy_contact_url ); ?>"><?php esc_html_e( 'Contact', 'loopbuy' ); ?></a></li>
						<li><a href="<?php echo esc_url( $loopbuy_my_listings_url ); ?>"><?php esc_html_e( 'My Listings', 'loopbuy' ); ?></a></li>
						<li><a href="<?php echo esc_url( home_url( '/orders/' ) ); ?>"><?php esc_html_e( 'Orders', 'loopbuy' ); ?></a></li>
					</ul>
				<?php endif; ?>
			</div>

			<div class="footer-widget-column">
				<h2 class="widget-title"><?php esc_html_e( 'Support', 'loopbuy' ); ?></h2>
				<?php if ( is_active_sidebar( 'footer-support' ) ) : ?>
					<?php dynamic_sidebar( 'footer-support' ); ?>
				<?php else : ?>
					<ul>
						<li><a href="<?php echo esc_url( $loopbuy_contact_url ); ?>"><?php esc_html_e( 'Help Center', 'loopbuy' ); ?></a></li>
						<li><a href="<?php echo esc_url( $loopbuy_contact_url ); ?>"><?php esc_html_e( 'Safety Tips', 'loopbuy' ); ?></a></li>
						<li><a href="<?php echo esc_url( $loopbuy_contact_url ); ?>"><?php esc_html_e( 'Report a Listing', 'loopbuy' ); ?></a></li>
						<li><a href="<?php echo esc_url( $loopbuy_contact_url ); ?>"><?php esc_html_e( 'FAQ', 'loopbuy' ); ?></a></li>
					</ul>
				<?php endif; ?>
			</div>

		</div><!-- .footer-widgets -->

		<div class="site-info">
			<span class="footer-copyright">
				<?php
				printf(
					/* translators: 1: Copyright year, 2: Site name. */
					esc_html__( '© %1$s %2$s Marketplace. All rights reserved.', 'loopbuy' ),
					esc_html( gmdate( 'Y' ) ),
					esc_html( get_bloginfo( 'name' ) )
				);
				?>
			</span>

			<span class="footer-legal-links">
				<a href="#"><?php esc_html_e( 'Privacy', 'loopbuy' ); ?></a>
				<a href="#"><?php esc_html_e( 'Terms', 'loopbuy' ); ?></a>
				<a href="#"><?php esc_html_e( 'Cookies', 'loopbuy' ); ?></a>
			</span>
		</div><!-- .site-info -->

	</footer><!-- #colophon -->
</div><!-- #page -->

<?php wp_footer(); ?>

</body>
</html>