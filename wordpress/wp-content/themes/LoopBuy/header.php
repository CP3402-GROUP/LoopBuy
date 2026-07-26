<?php
/**
 * The header for our theme
 *
 * This is the template that displays all of the <head> section and everything up until <div id="content">
 *
 * @link https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package LoopBuy
 */

?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">

	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div id="page" class="site">
	<a class="skip-link screen-reader-text" href="#primary"><?php esc_html_e( 'Skip to content', 'loopbuy' ); ?></a>

	<header id="masthead" class="site-header">
		<div class="site-header-inner">

			<div class="site-branding">
				<?php
				if ( has_custom_logo() ) :
					the_custom_logo();
				else :
					?>
					<span class="site-icon" aria-hidden="true">
						<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M4 9L5.5 4H18.5L20 9" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M4 9H20V18C20 19.1046 19.1046 20 18 20H6C4.89543 20 4 19.1046 4 18V9Z" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/>
							<path d="M9 9V11C9 12.6569 10.3431 14 12 14C13.6569 14 15 12.6569 15 11V9" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</span>
					<?php
				endif;

				if ( is_front_page() && is_home() ) :
					?>
					<h1 class="site-title"><a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a></h1>
					<?php
				else :
					?>
					<p class="site-title"><a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a></p>
					<?php
				endif;

				$loopbuy_description = get_bloginfo( 'description', 'display' );
				if ( $loopbuy_description || is_customize_preview() ) :
					?>
					<p class="site-description screen-reader-text"><?php echo $loopbuy_description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
				<?php endif; ?>
			</div><!-- .site-branding -->

			<form role="search" method="get" class="search-form header-search" action="<?php echo esc_url( home_url( '/' ) ); ?>">
				<label class="screen-reader-text" for="loopbuy-header-search"><?php esc_html_e( 'Search for:', 'loopbuy' ); ?></label>
				<input type="search" id="loopbuy-header-search" class="search-field" placeholder="<?php echo esc_attr_x( 'Search for anything&hellip;', 'placeholder', 'loopbuy' ); ?>" value="<?php echo get_search_query(); ?>" name="s" />
				<button type="submit" class="search-submit"><?php esc_html_e( 'Search', 'loopbuy' ); ?></button>
			</form><!-- .header-search -->

			<div class="header-actions">

				<button type="button" class="theme-toggle" id="loopbuy-theme-toggle" aria-label="<?php esc_attr_e( 'Toggle dark mode', 'loopbuy' ); ?>" aria-pressed="false">
					<svg width="19" height="19" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</button>

				<a href="<?php echo esc_url( home_url( '/sell/' ) ); ?>" class="loopbuy-sell-button">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<path d="M12 5V19M5 12H19" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
					</svg>
					<?php esc_html_e( 'Sell', 'loopbuy' ); ?>
				</a>

				<a href="#" class="header-icon-link">
					<svg width="19" height="19" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<path d="M12 20.5s-7.5-4.6-10-9.3C.5 7.8 2.4 4.5 6 4.5c2.1 0 3.6 1.2 6 3.7 2.4-2.5 3.9-3.7 6-3.7 3.6 0 5.5 3.3 4 6.7-2.5 4.7-10 9.3-10 9.3Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
					</svg>
					<span><?php esc_html_e( 'Saved', 'loopbuy' ); ?></span>
				</a>

				<a href="#" class="header-icon-link">
					<svg width="19" height="19" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<path d="M6 8V6a6 6 0 1 1 12 0v2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
						<path d="M4.5 8H19.5L18.6 19.2A2 2 0 0 1 16.6 21H7.4A2 2 0 0 1 5.4 19.2L4.5 8Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
					</svg>
					<span><?php esc_html_e( 'Cart', 'loopbuy' ); ?></span>
				</a>

				<a href="#" class="header-icon-link">
					<svg width="19" height="19" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<path d="M4 12a8 8 0 1 1 3.2 6.4L4 20l1.3-3.7A7.96 7.96 0 0 1 4 12Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
					</svg>
					<span><?php esc_html_e( 'Chat', 'loopbuy' ); ?></span>
				</a>

				<?php if ( is_user_logged_in() ) : ?>
					<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="auth-link"><?php esc_html_e( 'Log out', 'loopbuy' ); ?></a>
				<?php else : ?>
					<a href="<?php echo esc_url( wp_login_url() ); ?>" class="auth-link"><?php esc_html_e( 'Log in', 'loopbuy' ); ?></a>
					<a href="<?php echo esc_url( wp_registration_url() ); ?>" class="auth-button"><?php esc_html_e( 'Sign up', 'loopbuy' ); ?></a>
				<?php endif; ?>

				<?php if ( has_nav_menu( 'menu-1' ) ) : ?>
					<button class="menu-toggle" aria-controls="primary-menu" aria-expanded="false"><?php esc_html_e( 'Menu', 'loopbuy' ); ?></button>
				<?php endif; ?>

			</div><!-- .header-actions -->

			<?php if ( has_nav_menu( 'menu-1' ) ) : ?>
				<nav id="site-navigation" class="main-navigation">
					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'menu-1',
							'menu_id'        => 'primary-menu',
						)
					);
					?>
				</nav><!-- #site-navigation -->
			<?php endif; ?>

		</div><!-- .site-header-inner -->
	</header><!-- #masthead -->