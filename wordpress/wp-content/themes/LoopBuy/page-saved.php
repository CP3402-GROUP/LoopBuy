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

<script>
/* =========================================================
   REVEAL SAVED PRODUCTS
   Reads the same localStorage key that index.php writes to.
========================================================= */
document.addEventListener('DOMContentLoaded', function () {

	var STORAGE_KEY = 'loopbuy_saved_products';

	var emptyMessage = document.getElementById('saved-empty-message');
	var cards = document.querySelectorAll('.saved-product-card');

	function getSavedIds() {
		try {
			var raw = window.localStorage.getItem(STORAGE_KEY);
			var ids = raw ? JSON.parse(raw) : [];
			return Array.isArray(ids) ? ids.map(String) : [];
		} catch (e) {
			return [];
		}
	}

	function setSavedIds(ids) {
		try {
			window.localStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
		} catch (e) {}
	}

	function updateSavedCountBadge(count) {
		var badge = document.querySelector('[data-saved-count]');
		if (badge) {
			badge.textContent = count;
			badge.hidden = count === 0;
		}
	}

	function render() {
		var savedIds = getSavedIds();
		var visibleCount = 0;

		cards.forEach(function (card) {
			var id = card.getAttribute('data-product-id');
			var isSaved = savedIds.indexOf(id) !== -1;

			card.style.display = isSaved ? '' : 'none';

			if (isSaved) {
				visibleCount++;
			}
		});

		emptyMessage.style.display = visibleCount === 0 ? '' : 'none';
		updateSavedCountBadge(visibleCount);
	}

	cards.forEach(function (card) {
		var button = card.querySelector('.favourite-button');
		var id = card.getAttribute('data-product-id');

		if (!button) {
			return;
		}

		button.addEventListener('click', function (event) {
			event.preventDefault();

			var ids = getSavedIds().filter(function (savedId) {
				return savedId !== id;
			});

			setSavedIds(ids);
			render();
		});
	});

	render();

});
</script>

<?php
get_footer();