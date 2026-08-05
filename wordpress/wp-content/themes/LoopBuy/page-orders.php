<?php
/**
 * The template for displaying the Order History page.
 *
 * WordPress automatically uses this file for a page
 * whose slug is "orders".
 *
 * @package LoopBuy
 */

get_header();

require get_template_directory() . '/inc/product-data.php';

/*
 * Demo order data.
 * Later, your backend teammate can replace this with database orders.
 */
$orders = array(
	array(
		'order_id'   => 'LB-2026-001',
		'date'       => '3 August 2026',
		'status'     => 'Completed',
		'product_id' => 1,
		'quantity'   => 1,
	),
	array(
		'order_id'   => 'LB-2026-002',
		'date'       => '29 July 2026',
		'status'     => 'Completed',
		'product_id' => 2,
		'quantity'   => 1,
	),
);

/**
 * Find a product using its ID.
 *
 * @param int   $product_id Product ID.
 * @param array $products   Product list.
 *
 * @return array|null
 */
function loopbuy_find_order_product( $product_id, $products ) {
	foreach ( $products as $product ) {
		if ( (int) $product['id'] === (int) $product_id ) {
			return $product;
		}
	}

	return null;
}
?>

<main class="loopbuy-orders-page">

	<div class="loopbuy-orders-container">

		<div class="loopbuy-orders-heading">

			<div>
				<p class="loopbuy-orders-eyebrow">
					<?php esc_html_e( 'Your purchases', 'loopbuy' ); ?>
				</p>

				<h1>
					<?php esc_html_e( 'Order History', 'loopbuy' ); ?>
				</h1>
			</div>

			<a
				href="<?php echo esc_url( home_url( '/' ) ); ?>"
				class="loopbuy-orders-browse-link"
			>
				<?php esc_html_e( 'Continue shopping', 'loopbuy' ); ?>
			</a>

		</div>


		<?php if ( ! empty( $orders ) ) : ?>

			<div class="loopbuy-orders-list">

				<?php foreach ( $orders as $order ) : ?>

					<?php
					$product = loopbuy_find_order_product(
						$order['product_id'],
						$products
					);

					if ( ! $product ) {
						continue;
					}

					$quantity = max(
						1,
						(int) $order['quantity']
					);

					$total = (float) $product['price'] * $quantity;
					?>

					<article class="loopbuy-order-card">

						<header class="loopbuy-order-header">

							<div class="loopbuy-order-meta">

								<p class="loopbuy-order-number">
									<?php
									echo esc_html(
										$order['order_id']
									);
									?>
								</p>

								<p class="loopbuy-order-date">
									<?php
									echo esc_html(
										$order['date']
									);
									?>
								</p>

							</div>


							<div class="loopbuy-order-summary">

								<span class="loopbuy-order-status">
									<span aria-hidden="true">✓</span>

									<?php
									echo esc_html(
										$order['status']
									);
									?>
								</span>

								<strong class="loopbuy-order-total">
									$<?php
									echo esc_html(
										number_format(
											$total,
											2
										)
									);
									?>
								</strong>

							</div>

						</header>


						<div class="loopbuy-order-divider"></div>


						<div class="loopbuy-order-item">

							<a
								href="<?php
								echo esc_url(
									add_query_arg(
										'id',
										$product['id'],
										home_url(
											'/product-detail/'
										)
									)
								);
								?>"
								class="loopbuy-order-image"
							>
								<img
									src="<?php
									echo esc_url(
										loopbuy_product_image_url( $product )
									);
									?>"
									alt="<?php
									echo esc_attr(
										$product['name']
									);
									?>"
								>
							</a>


							<div class="loopbuy-order-product-info">

								<a
									href="<?php
									echo esc_url(
										add_query_arg(
											'id',
											$product['id'],
											home_url(
												'/product-detail/'
											)
										)
									);
									?>"
									class="loopbuy-order-product-name"
								>
									<?php
									echo esc_html(
										$product['name']
									);
									?>
								</a>

								<p>
									<?php
									printf(
										esc_html__(
											'Qty %d',
											'loopbuy'
										),
										$quantity
									);
									?>
								</p>

								<p class="loopbuy-order-product-extra">
									<?php
									echo esc_html(
										$product['condition']
									);
									?>

									<span aria-hidden="true">·</span>

									<?php
									echo esc_html(
										$product['location']
									);
									?>
								</p>

							</div>


							<div class="loopbuy-order-item-price">
								$<?php
								echo esc_html(
									number_format(
										$total,
										2
									)
								);
								?>
							</div>

						</div>


						<footer class="loopbuy-order-actions">

							<a
								href="<?php
								echo esc_url(
									add_query_arg(
										'id',
										$product['id'],
										home_url(
											'/product-detail/'
										)
									)
								);
								?>"
								class="loopbuy-order-button loopbuy-order-button-secondary"
							>
								<?php esc_html_e( 'View product', 'loopbuy' ); ?>
							</a>

							<a
								href="<?php
								echo esc_url(
									add_query_arg(
										'product_id',
										$product['id'],
										home_url(
											'/messages/'
										)
									)
								);
								?>"
								class="loopbuy-order-button loopbuy-order-button-primary"
							>
								<?php esc_html_e( 'Message seller', 'loopbuy' ); ?>
							</a>

						</footer>

					</article>

				<?php endforeach; ?>

			</div>

		<?php else : ?>

			<div class="loopbuy-orders-empty">

				<div class="loopbuy-orders-empty-icon" aria-hidden="true">
					🛍
				</div>

				<h2>
					<?php esc_html_e( 'No orders yet', 'loopbuy' ); ?>
				</h2>

				<p>
					<?php esc_html_e( 'Your completed purchases will appear here.', 'loopbuy' ); ?>
				</p>

				<a
					href="<?php echo esc_url( home_url( '/' ) ); ?>"
					class="loopbuy-order-button loopbuy-order-button-primary"
				>
					<?php esc_html_e( 'Browse products', 'loopbuy' ); ?>
				</a>

			</div>

		<?php endif; ?>

	</div>

</main>

<?php
get_footer();
