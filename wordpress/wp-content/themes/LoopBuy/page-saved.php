<?php
/**
 * The template for displaying the Saved Items page.
 *
 * Saved listings are account data. The Go API remains the source of truth;
 * this template never substitutes public catalogue fixtures or browser-local
 * IDs when the authenticated favourites service is unavailable.
 *
 * @package LoopBuy
 */

$loopbuy_saved_user = function_exists( 'loopbuy_marketplace_current_user' )
	? loopbuy_marketplace_current_user()
	: new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'Marketplace accounts are temporarily unavailable.', 'loopbuy' ) );

$loopbuy_saved_result = array();

if ( is_array( $loopbuy_saved_user ) ) {
	$loopbuy_saved_result = function_exists( 'loopbuy_marketplace_list_favourites' )
		? loopbuy_marketplace_list_favourites()
		: new WP_Error( 'loopbuy_marketplace_favourites_unavailable', __( 'Saved listings are temporarily unavailable.', 'loopbuy' ) );
}

$loopbuy_saved_products = is_array( $loopbuy_saved_result ) ? $loopbuy_saved_result : array();

get_header();
?>

<main id="primary" class="loopbuy-saved-page">
	<section class="saved-page-container">
		<header class="saved-page-heading">
			<h1><?php esc_html_e( 'Saved Items', 'loopbuy' ); ?></h1>
			<p><?php esc_html_e( 'Your favourite second-hand products, saved to your account.', 'loopbuy' ); ?></p>
		</header>

		<p
			class="loopbuy-favourites-status"
			data-favourites-status
			role="status"
			aria-live="polite"
		></p>

		<?php if ( null === $loopbuy_saved_user ) : ?>
			<div class="saved-empty-message">
				<div class="saved-empty-icon" aria-hidden="true">♡</div>
				<h2><?php esc_html_e( 'Log in to see your saved listings', 'loopbuy' ); ?></h2>
				<p><?php esc_html_e( 'Saved items follow your marketplace account across browsers and devices.', 'loopbuy' ); ?></p>
				<a class="saved-browse-button" href="<?php echo esc_url( home_url( '/login/' ) ); ?>">
					<?php esc_html_e( 'Log in', 'loopbuy' ); ?>
				</a>
			</div>
		<?php elseif ( is_wp_error( $loopbuy_saved_user ) ) : ?>
			<div class="saved-empty-message" role="alert">
				<div class="saved-empty-icon" aria-hidden="true">!</div>
				<h2><?php esc_html_e( 'Saved listings are unavailable', 'loopbuy' ); ?></h2>
				<p><?php echo esc_html( $loopbuy_saved_user->get_error_message() ); ?></p>
				<a class="saved-browse-button" href="<?php echo esc_url( home_url( '/saved/' ) ); ?>">
					<?php esc_html_e( 'Try again', 'loopbuy' ); ?>
				</a>
			</div>
		<?php elseif ( is_wp_error( $loopbuy_saved_result ) ) : ?>
			<div class="saved-empty-message" role="alert">
				<div class="saved-empty-icon" aria-hidden="true">!</div>
				<h2><?php esc_html_e( 'Saved listings could not be loaded', 'loopbuy' ); ?></h2>
				<p><?php echo esc_html( $loopbuy_saved_result->get_error_message() ); ?></p>
				<a class="saved-browse-button" href="<?php echo esc_url( home_url( '/saved/' ) ); ?>">
					<?php esc_html_e( 'Try again', 'loopbuy' ); ?>
				</a>
			</div>
		<?php else : ?>
			<div
				id="saved-empty-message"
				class="saved-empty-message"
				<?php if ( ! empty( $loopbuy_saved_products ) ) : ?>
					style="display: none;"
				<?php endif; ?>
			>
				<div class="saved-empty-icon" aria-hidden="true">♡</div>
				<h2><?php esc_html_e( 'No saved items yet', 'loopbuy' ); ?></h2>
				<p><?php esc_html_e( 'Click the heart button on a listing to save it here.', 'loopbuy' ); ?></p>
				<a class="saved-browse-button" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php esc_html_e( 'Browse Products', 'loopbuy' ); ?>
				</a>
			</div>

			<div class="saved-products-grid">
				<?php foreach ( $loopbuy_saved_products as $product ) : ?>
					<?php
					if ( ! is_array( $product ) || empty( $product['id'] ) || ! is_numeric( $product['id'] ) ) {
						continue;
					}

					$product_id = (int) $product['id'];

					if ( $product_id < 1 ) {
						continue;
					}

					$product_name = isset( $product['name'] ) && is_string( $product['name'] )
						? $product['name']
						: __( 'Marketplace listing', 'loopbuy' );
					$product_brand = isset( $product['brand'] ) && is_string( $product['brand'] ) ? $product['brand'] : '';
					$product_price = isset( $product['price'] ) && is_numeric( $product['price'] ) ? (float) $product['price'] : 0.0;
					$product_currency = isset( $product['currency'] ) && is_string( $product['currency'] )
						? strtoupper( sanitize_key( $product['currency'] ) )
						: 'SGD';
					$product_location = isset( $product['location'] ) && is_string( $product['location'] ) ? $product['location'] : '';
					$product_condition = isset( $product['condition'] ) && is_string( $product['condition'] )
						? $product['condition']
						: __( 'Used', 'loopbuy' );
					$condition_class = 'condition-' . sanitize_html_class( str_replace( ' ', '-', strtolower( $product_condition ) ) );
					$image_url = isset( $product['image_url'] ) && is_string( $product['image_url'] )
						? trim( $product['image_url'] )
						: '';
					$product_url = add_query_arg( 'id', $product_id, home_url( '/product-detail/' ) );
					?>

					<article class="saved-product-card" data-product-id="<?php echo esc_attr( $product_id ); ?>">
						<div class="saved-product-image">
							<span class="saved-condition-badge <?php echo esc_attr( $condition_class ); ?>">
								<?php echo esc_html( $product_condition ); ?>
							</span>

							<button
								class="favourite-button active"
								type="button"
								data-product-id="<?php echo esc_attr( $product_id ); ?>"
								aria-label="<?php esc_attr_e( 'Remove saved product', 'loopbuy' ); ?>"
								aria-pressed="true"
							>
								♥
							</button>

							<a href="<?php echo esc_url( $product_url ); ?>">
								<?php if ( '' !== $image_url ) : ?>
									<img
										src="<?php echo esc_url( $image_url ); ?>"
										alt="<?php echo esc_attr( $product_name ); ?>"
										loading="lazy"
										decoding="async"
									>
								<?php else : ?>
									<span class="saved-product-image-placeholder">
										<?php esc_html_e( 'No image available', 'loopbuy' ); ?>
									</span>
								<?php endif; ?>
							</a>
						</div>

						<div class="saved-product-content">
							<a class="saved-product-title-link" href="<?php echo esc_url( $product_url ); ?>">
								<h2><?php echo esc_html( $product_name ); ?></h2>
							</a>

							<?php if ( '' !== $product_brand ) : ?>
								<p class="saved-product-brand"><?php echo esc_html( $product_brand ); ?></p>
							<?php endif; ?>

							<p class="saved-product-price">
								<?php echo esc_html( number_format_i18n( $product_price, 2 ) . ' ' . $product_currency ); ?>
							</p>

							<?php if ( '' !== $product_location ) : ?>
								<p class="saved-product-location">
									<span aria-hidden="true">⌖</span>
									<?php echo esc_html( $product_location ); ?>
								</p>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
</main>

<?php
get_footer();
