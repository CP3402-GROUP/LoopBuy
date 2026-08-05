<?php
/**
 * The header for our theme.
 *
 * This template displays the <head> section and the website header.
 *
 * @link https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package LoopBuy
 */

//Saved / Cart badge counts.
// Saved items are tracked client-side in localStorage as a flat array of
// product IDs (key: loopbuy_saved_products) — written by page-saved.php.
// Cart items are tracked client-side in localStorage as a map of
// product id -> quantity (key: loopbuy_cart_items) — written by
// page-cart.php and page-product-detail.php's "Add to Cart" button.
// Since this data lives in the browser (works for guests too), the counts
// below are filled in by JS on load rather than rendered from PHP.
$loopbuy_marketplace_user = function_exists( 'loopbuy_marketplace_current_user' )
	? loopbuy_marketplace_current_user()
	: new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'Marketplace account service is unavailable.', 'loopbuy' ) );
$loopbuy_marketplace_csrf = is_array( $loopbuy_marketplace_user ) && function_exists( 'loopbuy_marketplace_csrf_token' )
	? loopbuy_marketplace_csrf_token()
	: null;
?>

<!doctype html>

<html <?php language_attributes(); ?>>

<head>

	<meta charset="<?php bloginfo( 'charset' ); ?>">

	<meta
		name="viewport"
		content="width=device-width, initial-scale=1"
	>

	<link
		rel="profile"
		href="https://gmpg.org/xfn/11"
	>

	<script>
	/*
	 * Apply dark mode before the page appears.
	 */
	(function () {
		try {
			var savedTheme = window.localStorage.getItem(
				'loopbuy_theme'
			);

			if (savedTheme === 'dark') {
				document.documentElement.classList.add(
					'dark-mode'
				);
			}
		} catch (error) {}
	})();
	</script>

	<?php wp_head(); ?>

</head>


<body <?php body_class(); ?>>

<?php wp_body_open(); ?>


<div id="page" class="site">

	<a
		class="skip-link screen-reader-text"
		href="#primary"
	>
		<?php esc_html_e( 'Skip to content', 'loopbuy' ); ?>
	</a>


	<header id="masthead" class="site-header">

		<div class="site-header-inner">


			<!-- ==========================================
			     WEBSITE BRANDING
			=========================================== -->

			<div class="site-branding">

				<?php if ( has_custom_logo() ) : ?>

					<?php the_custom_logo(); ?>

				<?php else : ?>

					<span class="site-icon" aria-hidden="true">

						<svg
							width="22"
							height="22"
							viewBox="0 0 24 24"
							fill="none"
							xmlns="http://www.w3.org/2000/svg"
						>
							<path
								d="M4 9L5.5 4H18.5L20 9"
								stroke="#fff"
								stroke-width="1.8"
								stroke-linecap="round"
								stroke-linejoin="round"
							/>

							<path
								d="M4 9H20V18C20 19.1046 19.1046 20 18 20H6C4.89543 20 4 19.1046 4 18V9Z"
								stroke="#fff"
								stroke-width="1.8"
								stroke-linejoin="round"
							/>

							<path
								d="M9 9V11C9 12.6569 10.3431 14 12 14C13.6569 14 15 12.6569 15 11V9"
								stroke="#fff"
								stroke-width="1.8"
								stroke-linecap="round"
								stroke-linejoin="round"
							/>
						</svg>

					</span>

				<?php endif; ?>


				<?php if ( is_front_page() && is_home() ) : ?>

					<h1 class="site-title">

						<a
							href="<?php echo esc_url( home_url( '/' ) ); ?>"
							rel="home"
						>
							<?php bloginfo( 'name' ); ?>
						</a>

					</h1>

				<?php else : ?>

					<p class="site-title">

						<a
							href="<?php echo esc_url( home_url( '/' ) ); ?>"
							rel="home"
						>
							<?php bloginfo( 'name' ); ?>
						</a>

					</p>

				<?php endif; ?>


				<?php
				$loopbuy_description = get_bloginfo(
					'description',
					'display'
				);
				?>

				<?php if ( $loopbuy_description || is_customize_preview() ) : ?>

					<p class="site-description screen-reader-text">

						<?php
						echo esc_html(
							$loopbuy_description
						);
						?>

					</p>

				<?php endif; ?>

			</div>


			<!-- ==========================================
			     HEADER SEARCH
			=========================================== -->

			<form
				role="search"
				method="get"
				class="search-form header-search"
				action="<?php echo esc_url( home_url( '/' ) ); ?>"
			>

				<label
					class="screen-reader-text"
					for="loopbuy-header-search"
				>
					<?php esc_html_e( 'Search for:', 'loopbuy' ); ?>
				</label>

				<input
					type="search"
					id="loopbuy-header-search"
					class="search-field"
					placeholder="<?php echo esc_attr_x( 'Search for anything&hellip;', 'placeholder', 'loopbuy' ); ?>"
					value="<?php echo esc_attr( get_search_query() ); ?>"
					name="s"
				>

				<button
					type="submit"
					class="search-submit"
				>
					<?php esc_html_e( 'Search', 'loopbuy' ); ?>
				</button>

			</form>


			<!-- ==========================================
			     HEADER ACTIONS
			=========================================== -->

			<div class="header-actions">


				<!-- Dark mode -->

				<button
					type="button"
					class="theme-toggle"
					id="loopbuy-theme-toggle"
					aria-label="<?php esc_attr_e( 'Toggle dark mode', 'loopbuy' ); ?>"
					aria-pressed="false"
				>

					<svg
						width="19"
						height="19"
						viewBox="0 0 24 24"
						fill="none"
						xmlns="http://www.w3.org/2000/svg"
						aria-hidden="true"
					>
						<path
							d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"
							stroke="currentColor"
							stroke-width="2.1"
							stroke-linecap="round"
							stroke-linejoin="round"
						/>
					</svg>

				</button>


				<!-- Sell -->

				<a
					href="<?php echo esc_url( home_url( '/sell/' ) ); ?>"
					class="loopbuy-sell-button"
				>

					<svg
						width="16"
						height="16"
						viewBox="0 0 24 24"
						fill="none"
						xmlns="http://www.w3.org/2000/svg"
						aria-hidden="true"
					>
						<path
							d="M12 5V19M5 12H19"
							stroke="currentColor"
							stroke-width="2.2"
							stroke-linecap="round"
						/>
					</svg>

					<?php esc_html_e( 'Sell', 'loopbuy' ); ?>

				</a>


				<!-- Saved -->

				<a
					href="<?php echo esc_url( home_url( '/saved/' ) ); ?>"
					class="header-icon-link"
				>

					<span class="header-icon-wrap">

						<svg
							width="19"
							height="19"
							viewBox="0 0 24 24"
							fill="none"
							xmlns="http://www.w3.org/2000/svg"
							aria-hidden="true"
						>
							<path
								d="M12 20.5s-7.5-4.6-10-9.3C.5 7.8 2.4 4.5 6 4.5c2.1 0 3.6 1.2 6 3.7 2.4-2.5 3.9-3.7 6-3.7 3.6 0 5.5 3.3 4 6.7-2.5 4.7-10 9.3-10 9.3Z"
								stroke="currentColor"
								stroke-width="1.7"
								stroke-linejoin="round"
							/>
						</svg>

						<span
							class="loopbuy-header-badge"
							data-saved-count
							hidden
						>
							0
						</span>

					</span>

					<span class="header-icon-label">
						<?php esc_html_e( 'Saved', 'loopbuy' ); ?>
					</span>

				</a>


				<!-- Cart -->

				<a
					href="<?php echo esc_url( home_url( '/cart/' ) ); ?>"
					class="header-icon-link"
				>

					<span class="header-icon-wrap">

						<svg
							width="19"
							height="19"
							viewBox="0 0 24 24"
							fill="none"
							xmlns="http://www.w3.org/2000/svg"
							aria-hidden="true"
						>
							<path
								d="M6 8V6a6 6 0 1 1 12 0v2"
								stroke="currentColor"
								stroke-width="1.7"
								stroke-linecap="round"
							/>

							<path
								d="M4.5 8H19.5L18.6 19.2A2 2 0 0 1 16.6 21H7.4A2 2 0 0 1 5.4 19.2L4.5 8Z"
								stroke="currentColor"
								stroke-width="1.7"
								stroke-linejoin="round"
							/>
						</svg>

						<span
							class="loopbuy-header-badge"
							data-cart-count
							hidden
						>
							0
						</span>

					</span>

					<span class="header-icon-label">
						<?php esc_html_e( 'Cart', 'loopbuy' ); ?>
					</span>

				</a>


				<!-- AI shopping assistant -->

				<a
					href="<?php echo esc_url( home_url( '/ai-assistant/' ) ); ?>"
					class="header-icon-link loopbuy-ai-header-link"
				>

					<svg
						width="19"
						height="19"
						viewBox="0 0 24 24"
						fill="none"
						xmlns="http://www.w3.org/2000/svg"
						aria-hidden="true"
					>
						<path
							d="M12 3l1.15 3.35L16.5 7.5l-3.35 1.15L12 12l-1.15-3.35L7.5 7.5l3.35-1.15L12 3Z"
							stroke="currentColor"
							stroke-width="1.7"
							stroke-linejoin="round"
						/>
						<path
							d="M18.5 12l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8.8-2.2Z"
							stroke="currentColor"
							stroke-width="1.7"
							stroke-linejoin="round"
						/>
						<path
							d="M7 13l1 2.75 2.75 1L8 17.75 7 20.5 6 17.75l-2.75-1 2.75-1L7 13Z"
							stroke="currentColor"
							stroke-width="1.7"
							stroke-linejoin="round"
						/>
					</svg>

					<span class="header-icon-label">
						<?php esc_html_e( 'AI Finder', 'loopbuy' ); ?>
					</span>

				</a>


				<!-- Chat -->

				<a
					href="<?php echo esc_url( home_url( '/messages/' ) ); ?>"
					class="header-icon-link"
				>

					<svg
						width="19"
						height="19"
						viewBox="0 0 24 24"
						fill="none"
						xmlns="http://www.w3.org/2000/svg"
						aria-hidden="true"
					>
						<path
							d="M4 12a8 8 0 1 1 3.2 6.4L4 20l1.3-3.7A7.96 7.96 0 0 1 4 12Z"
							stroke="currentColor"
							stroke-width="1.7"
							stroke-linejoin="round"
						/>
					</svg>

					<span class="header-icon-label">
						<?php esc_html_e( 'Messages', 'loopbuy' ); ?>
					</span>

				</a>


				<!-- ==========================================
				     USER ACCOUNT
				=========================================== -->

				<?php if ( is_array( $loopbuy_marketplace_user ) ) : ?>

					<?php
					$loopbuy_user_profile = isset( $loopbuy_marketplace_user['profile'] ) && is_array( $loopbuy_marketplace_user['profile'] )
						? $loopbuy_marketplace_user['profile']
						: array();
					$loopbuy_user_name    = ! empty( $loopbuy_user_profile['full_name'] )
						? $loopbuy_user_profile['full_name']
						: $loopbuy_marketplace_user['username'];

					$loopbuy_user_initial = strtoupper(
						function_exists( 'mb_substr' ) ? mb_substr(
							$loopbuy_user_name,
							0,
							1,
							'UTF-8'
						) : substr( $loopbuy_user_name, 0, 1 )
					);
					?>

					<div class="loopbuy-user-menu">

						<button
							type="button"
							class="loopbuy-user-avatar"
							id="loopbuy-user-menu-button"
							aria-haspopup="true"
							aria-expanded="false"
							aria-controls="loopbuy-user-dropdown"
						>
							<?php echo esc_html( $loopbuy_user_initial ); ?>

							<span class="screen-reader-text">
								<?php esc_html_e( 'Account menu', 'loopbuy' ); ?>
							</span>
						</button>


						<div
							class="loopbuy-user-dropdown"
							id="loopbuy-user-dropdown"
							role="menu"
							aria-labelledby="loopbuy-user-menu-button"
							hidden
						>

							<div class="loopbuy-user-dropdown-header">

								<p class="loopbuy-user-dropdown-name">
									<?php echo esc_html( $loopbuy_user_name ); ?>
								</p>

								<p class="loopbuy-user-dropdown-email">
									<?php echo esc_html( $loopbuy_marketplace_user['email'] ); ?>
								</p>

							</div>


							<ul class="loopbuy-user-dropdown-list">

								<li>

									<a
										href="<?php echo esc_url( home_url( '/profile/' ) ); ?>"
										role="menuitem"
									>

										<svg
											width="18"
											height="18"
											viewBox="0 0 24 24"
											fill="none"
											stroke="currentColor"
											stroke-width="2"
											stroke-linecap="round"
											stroke-linejoin="round"
											aria-hidden="true"
										>
											<rect
												x="3"
												y="3"
												width="7"
												height="7"
												rx="1"
											/>

											<rect
												x="14"
												y="3"
												width="7"
												height="7"
												rx="1"
											/>

											<rect
												x="14"
												y="14"
												width="7"
												height="7"
												rx="1"
											/>

											<rect
												x="3"
												y="14"
												width="7"
												height="7"
												rx="1"
											/>
										</svg>

										<?php esc_html_e( 'Profile', 'loopbuy' ); ?>

									</a>

								</li>


								<li>

									<a
										href="<?php echo esc_url( home_url( '/my-listings/' ) ); ?>"
										role="menuitem"
									>

										<svg
											width="18"
											height="18"
											viewBox="0 0 24 24"
											fill="none"
											stroke="currentColor"
											stroke-width="2"
											stroke-linecap="round"
											stroke-linejoin="round"
											aria-hidden="true"
										>
											<path d="M21 8 12 3 3 8l9 5 9-5Z"/>
											<path d="M3 8v8l9 5 9-5V8"/>
											<path d="M12 13v8"/>
										</svg>

										<?php esc_html_e( 'My Listings', 'loopbuy' ); ?>

									</a>

								</li>


								<li>

									<a
										href="<?php echo esc_url( home_url( '/orders/' ) ); ?>"
										role="menuitem"
									>

										<svg
											width="18"
											height="18"
											viewBox="0 0 24 24"
											fill="none"
											stroke="currentColor"
											stroke-width="2"
											stroke-linecap="round"
											stroke-linejoin="round"
											aria-hidden="true"
										>
											<rect
												x="8"
												y="2"
												width="8"
												height="4"
												rx="1"
											/>

											<path d="M9 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3"/>
											<path d="M9 12h6M9 16h6M9 8h1"/>
										</svg>

										<?php esc_html_e( 'Orders', 'loopbuy' ); ?>

									</a>

								</li>


								<li>

									<a
										href="<?php echo esc_url( home_url( '/messages/' ) ); ?>"
										role="menuitem"
									>

										<svg
											width="18"
											height="18"
											viewBox="0 0 24 24"
											fill="none"
											stroke="currentColor"
											stroke-width="2"
											stroke-linecap="round"
											stroke-linejoin="round"
											aria-hidden="true"
										>
											<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/>
										</svg>

										<?php esc_html_e( 'Messages', 'loopbuy' ); ?>

									</a>

								</li>

							</ul>


							<div class="loopbuy-user-dropdown-footer">

								<form
									method="post"
									action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
									class="loopbuy-user-dropdown-logout-form"
								>
									<input type="hidden" name="action" value="loopbuy_marketplace_logout">
									<?php if ( is_string( $loopbuy_marketplace_csrf ) ) : ?>
										<input type="hidden" name="loopbuy_marketplace_csrf" value="<?php echo esc_attr( $loopbuy_marketplace_csrf ); ?>">
									<?php endif; ?>
									<button
										type="submit"
										class="loopbuy-user-dropdown-logout"
										role="menuitem"
										<?php disabled( is_wp_error( $loopbuy_marketplace_csrf ) || ! is_string( $loopbuy_marketplace_csrf ) ); ?>
									>

									<svg
										width="18"
										height="18"
										viewBox="0 0 24 24"
										fill="none"
										stroke="currentColor"
										stroke-width="2"
										stroke-linecap="round"
										stroke-linejoin="round"
										aria-hidden="true"
									>
										<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
										<path d="M16 17l5-5-5-5"/>
										<path d="M21 12H9"/>
									</svg>

									<?php esc_html_e( 'Log out', 'loopbuy' ); ?>

									</button>
								</form>

							</div>

						</div>

					</div>

				<?php elseif ( is_wp_error( $loopbuy_marketplace_user ) ) : ?>

					<a
						href="<?php echo esc_url( home_url( '/profile/' ) ); ?>"
						class="auth-link"
						title="<?php echo esc_attr( $loopbuy_marketplace_user->get_error_message() ); ?>"
					>
						<?php esc_html_e( 'Account unavailable', 'loopbuy' ); ?>
					</a>

				<?php else : ?>

					<a
						href="<?php echo esc_url( home_url( '/login/' ) ); ?>"
						class="auth-link"
					>
						<?php esc_html_e( 'Log in', 'loopbuy' ); ?>
					</a>

					<a
						href="<?php echo esc_url( home_url( '/register/' ) ); ?>"
						class="auth-button"
					>
						<?php esc_html_e( 'Sign up', 'loopbuy' ); ?>
					</a>

				<?php endif; ?>


				<?php if ( has_nav_menu( 'menu-1' ) ) : ?>

					<button
						class="menu-toggle"
						aria-controls="primary-menu"
						aria-expanded="false"
					>
						<?php esc_html_e( 'Menu', 'loopbuy' ); ?>
					</button>

				<?php endif; ?>

			</div>


			<!-- ==========================================
			     DARK MODE JAVASCRIPT
			=========================================== -->

			<script>
			(function () {

				var THEME_KEY = 'loopbuy_theme';

				var toggle = document.getElementById(
					'loopbuy-theme-toggle'
				);

				var root = document.documentElement;

				if (!toggle) {
					return;
				}


				function isDarkMode() {
					return root.classList.contains(
						'dark-mode'
					);
				}


				function updatePressedState() {
					toggle.setAttribute(
						'aria-pressed',
						isDarkMode() ? 'true' : 'false'
					);
				}


				updatePressedState();


				toggle.addEventListener(
					'click',
					function () {
						root.classList.toggle(
							'dark-mode'
						);

						updatePressedState();

						try {
							window.localStorage.setItem(
								THEME_KEY,
								isDarkMode()
									? 'dark'
									: 'light'
							);
						} catch (error) {}
					}
				);

			})();
			</script>


			<!-- ==========================================
			     SAVED AND CART BADGES
			=========================================== -->

			<script>
			(function () {

				var SAVED_KEY = 'loopbuy_saved_products';
				var CART_KEY = 'loopbuy_cart_items';


				function updateBadge(selector, count) {
					var badge = document.querySelector(
						selector
					);

					if (!badge) {
						return;
					}

					badge.textContent =
						count > 99
							? '99+'
							: String(count);

					badge.hidden = count === 0;
				}


				function refreshBadges() {
					var savedCount = 0;
					var cartCount = 0;


					try {
						var rawSaved =
							window.localStorage.getItem(
								SAVED_KEY
							);

						var savedIds =
							rawSaved
								? JSON.parse(rawSaved)
								: [];

						savedCount =
							Array.isArray(savedIds)
								? savedIds.length
								: 0;
					} catch (error) {}


					try {
						var rawCart =
							window.localStorage.getItem(
								CART_KEY
							);

						var cart =
							rawCart
								? JSON.parse(rawCart)
								: {};

						if (
							cart &&
							typeof cart === 'object' &&
							!Array.isArray(cart)
						) {
							Object.keys(cart).forEach(
								function (productId) {
									cartCount +=
										parseInt(
											cart[productId],
											10
										) || 0;
								}
							);
						}
					} catch (error) {}


					updateBadge(
						'[data-saved-count]',
						savedCount
					);

					updateBadge(
						'[data-cart-count]',
						cartCount
					);
				}


				document.addEventListener(
					'DOMContentLoaded',
					refreshBadges
				);


				window.addEventListener(
					'storage',
					function (event) {
						if (
							event.key === SAVED_KEY ||
							event.key === CART_KEY
						) {
							refreshBadges();
						}
					}
				);

			})();
			</script>


			<!-- ==========================================
			     USER DROPDOWN JAVASCRIPT
			=========================================== -->

			<?php if ( is_array( $loopbuy_marketplace_user ) ) : ?>

				<script>
				(function () {

					var toggle = document.getElementById(
						'loopbuy-user-menu-button'
					);

					var menu = document.getElementById(
						'loopbuy-user-dropdown'
					);

					if (!toggle || !menu) {
						return;
					}


					function closeMenu() {
						menu.hidden = true;

						toggle.setAttribute(
							'aria-expanded',
							'false'
						);
					}


					function openMenu() {
						menu.hidden = false;

						toggle.setAttribute(
							'aria-expanded',
							'true'
						);
					}


					toggle.addEventListener(
						'click',
						function (event) {
							event.stopPropagation();

							if (menu.hidden) {
								openMenu();
							} else {
								closeMenu();
							}
						}
					);


					document.addEventListener(
						'click',
						function (event) {
							if (
								!menu.hidden &&
								!menu.contains(event.target) &&
								event.target !== toggle
							) {
								closeMenu();
							}
						}
					);


					document.addEventListener(
						'keydown',
						function (event) {
							if (
								event.key === 'Escape' &&
								!menu.hidden
							) {
								closeMenu();
								toggle.focus();
							}
						}
					);

				})();
				</script>

			<?php endif; ?>


			<!-- ==========================================
			     WORDPRESS NAVIGATION
			=========================================== -->

			<?php if ( has_nav_menu( 'menu-1' ) ) : ?>

				<nav
					id="site-navigation"
					class="main-navigation"
				>

					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'menu-1',
							'menu_id'        => 'primary-menu',
						)
					);
					?>

				</nav>

			<?php endif; ?>

		</div>

	</header>
