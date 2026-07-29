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

require get_template_directory()
	. '/inc/product-data.php';
?>

<main class="loopbuy-saved-page">

	<section class="saved-page-container">

		<div class="saved-page-heading">

			<h1>Saved Items</h1>

			<p>
				Your favourite second-hand products.
			</p>

		</div>


		<div
			id="saved-empty-message"
			class="saved-empty-message"
		>

			<div class="saved-empty-icon">
				♡
			</div>

			<h2>No saved items yet</h2>

			<p>
				Click the heart button on a product
				to save it here.
			</p>

			<a
				class="saved-browse-button"
				href="<?php echo esc_url(home_url('/')); ?>"
			>
				Browse Products
			</a>

		</div>


		<div class="saved-products-grid">

			<?php foreach ($products as $product) : ?>

				<article
					class="saved-product-card"
					data-product-id="<?php
					echo esc_attr($product['id']);
					?>"
					style="display: none;"
				>

					<div class="saved-product-image">

						<span
							class="saved-condition-badge
							<?php
							echo (
								$product['condition']
								=== 'Good'
							)
								? 'condition-good'
								: 'condition-like-new';
							?>"
						>
							<?php
							echo esc_html(
								$product['condition']
							);
							?>
						</span>


						<button
							class="favourite-button active"
							type="button"
							data-product-id="<?php
							echo esc_attr($product['id']);
							?>"
							aria-label="Remove saved product"
						>
							♥
						</button>


						<a
							href="<?php
							echo esc_url(
								home_url(
									'/product-detail/?id='
									. $product['id']
								)
							);
							?>"
						>

							<img
								src="<?php
								echo esc_url(
									get_template_directory_uri()
									. '/images/'
									. $product['image']
								);
								?>"
								alt="<?php
								echo esc_attr(
									$product['name']
								);
								?>"
							>

						</a>

					</div>


					<div class="saved-product-content">

						<a
							class="saved-product-title-link"
							href="<?php
							echo esc_url(
								home_url(
									'/product-detail/?id='
									. $product['id']
								)
							);
							?>"
						>

							<h2>
								<?php
								echo esc_html(
									$product['name']
								);
								?>
							</h2>

						</a>


						<p class="saved-product-brand">
							<?php
							echo esc_html(
								$product['brand']
							);
							?>
						</p>


						<p class="saved-product-price">
							$<?php
							echo esc_html(
								number_format(
									(float) $product['price'],
									0
								)
							);
							?>
						</p>


						<p class="saved-product-location">
							<span aria-hidden="true">⌖</span>

							<?php
							echo esc_html(
								$product['location']
							);
							?>
						</p>

					</div>

				</article>

			<?php endforeach; ?>

		</div>

	</section>

</main>

<?php
get_footer();