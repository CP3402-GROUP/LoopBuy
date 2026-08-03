<?php
/**
 * Template Name: Product Detail
 * Template Post Type: page
 *
 * Product detail page template for LoopBuy.
 *
 * Displays information for a selected second-hand product,
 * including product image, price, condition, location,
 * seller information, reviews, save, cart and chat actions.
 *
 * @package LoopBuy
 */

get_header();



/* Load all product data */
require get_template_directory() . '/inc/product-data.php';


/* Get product ID from URL */
$product_id = isset($_GET['id'])
    ? intval($_GET['id'])
    : 1;


/* Find the selected product */
$product = null;

foreach ($products as $item) {

    if ((int) $item['id'] === $product_id) {
        $product = $item;
        break;
    }

}


/* Product not found */
if (!$product) {
    ?>

    <main class="loopbuy-product-page">

        <div class="product-detail-container">

            <h1>Product not found</h1>

            <a href="<?php echo esc_url(home_url('/')); ?>">
                ← Back to products
            </a>

        </div>

    </main>

    <?php
    get_footer();
    return;
}
?>


<main class="loopbuy-product-page">

    <div class="product-detail-container">

        <a
            href="<?php echo esc_url(home_url('/')); ?>"
            class="product-back"
        >
            ← Back
        </a>


        <section class="product-detail-main">

            <div class="product-detail-image">

                <img
                    src="<?php
                    echo esc_url(
                        get_template_directory_uri()
                        . '/images/'
                        . $product['image']
                    );
                    ?>"
                    alt="<?php echo esc_attr($product['name']); ?>"
                >

            </div>


            <div class="product-detail-info">

                <div class="product-badges">

                    <span class="product-condition">
                        <?php echo esc_html($product['condition']); ?>
                    </span>

                    <?php if (!empty($product['verified'])) : ?>

                        <span class="product-verified">
                            ● Verified
                        </span>

                    <?php endif; ?>

                </div>


                <h1>
                    <?php echo esc_html($product['name']); ?>
                </h1>


                <p class="product-brand">
                    <?php echo esc_html($product['brand']); ?>
                </p>


                <p class="product-views">
                    ◉ <?php echo esc_html($product['views'] ?? 0); ?> views
                </p>


                <div class="product-detail-price">
                    $<?php echo esc_html($product['price']); ?>
                </div>


                <p class="product-location">
                    📍 <?php echo esc_html($product['location']); ?>
                </p>


                <p class="product-description">
                    <?php
                    echo esc_html(
                        $product['description']
                        ?? 'No description available.'
                    );
                    ?>
                </p>


                <div class="product-seller-card">

                    <div class="seller-avatar">
                        👤
                    </div>

                    <div>

                        <strong>
                            <?php
                            echo esc_html(
                                $product['seller']
                                ?? 'LoopBuy Seller'
                            );
                            ?>
                        </strong>

                        <p>View profile and reviews</p>

                    </div>

                </div>


                <div class="product-safe-box">
                    🛡 This listing passed safety screening.
                </div>


                <div class="product-actions">

                    <button
                        class="add-cart-button"
                        type="button"
                        data-product-id="<?php echo esc_attr($product['id']); ?>"
                    >
                        🛍 Add to Cart
                    </button>

                    <button
                        class="detail-favourite-button"
                        type="button"
                        data-product-id="<?php echo esc_attr($product['id']); ?>"
                    >
                        ♡ Save
                    </button>

                    <a
                        class="chat-button"
                        href="<?php
                        echo esc_url(
                            add_query_arg(
                                'product_id',
                                $product['id'],
                                home_url('/messages/')
                            )
                        );
                        ?>"
                        data-chat-product-id="<?php echo esc_attr($product['id']); ?>"
                    >
                        💬 Chat
                    </a>

                </div>

            </div>

        </section>


        <section class="product-review-section">

            <div class="review-column">

                <h2>Product Reviews</h2>

                <div class="review-box">

                    <div class="review-stars">
                        ★ ★ ★ ★ ★
                    </div>

                    <textarea
                        placeholder="Share your experience..."
                    ></textarea>

                    <button type="button">
                        ✈ Post Review
                    </button>

                </div>

                <p class="no-review">
                    No product reviews yet.
                </p>

            </div>


            <div class="review-column">

                <h2>Seller Reviews</h2>

                <div class="review-box">

                    <p>
                        Rate your experience with this seller
                    </p>

                    <div class="review-stars">
                        ★ ★ ★ ★ ★
                    </div>

                    <textarea
                        placeholder="How was the seller?"
                    ></textarea>

                    <button type="button">
                        ✈ Rate Seller
                    </button>

                </div>

                <p class="no-review">
                    No seller reviews yet.
                </p>

            </div>

        </section>

    </div>

</main>

<script>
/* =========================================================
   PRODUCT DETAIL PAGE ACTIONS

   1. Add product to cart
   2. Update the header cart count
   3. Save the product to chat history before opening Messages
========================================================= */

document.addEventListener('DOMContentLoaded', function () {

	/* =====================================================
	   ELEMENTS
	===================================================== */

	var addToCartButton = document.querySelector('.add-cart-button');
	var chatButton = document.querySelector('.chat-button');


	/* =====================================================
	   CART
	===================================================== */

	var CART_STORAGE_KEY = 'loopbuy_cart_items';

	function getCart() {
		try {
			var raw = window.localStorage.getItem(CART_STORAGE_KEY);
			var cart = raw ? JSON.parse(raw) : {};

			if (
				cart &&
				typeof cart === 'object' &&
				!Array.isArray(cart)
			) {
				return cart;
			}

			return {};
		} catch (error) {
			return {};
		}
	}

	function setCart(cart) {
		try {
			window.localStorage.setItem(
				CART_STORAGE_KEY,
				JSON.stringify(cart)
			);
		} catch (error) {
			console.error('Unable to save cart.', error);
		}
	}

	function getCartTotalQuantity(cart) {
		return Object.keys(cart).reduce(function (total, productId) {
			var quantity = parseInt(cart[productId], 10) || 0;
			return total + quantity;
		}, 0);
	}

	function updateCartCountBadge(count) {
		var badge = document.querySelector('[data-cart-count]');

		if (!badge) {
			return;
		}

		badge.textContent = count;
		badge.hidden = count === 0;
	}

	function initialiseCartCount() {
		var cart = getCart();
		var totalQuantity = getCartTotalQuantity(cart);

		updateCartCountBadge(totalQuantity);
	}

	function handleAddToCart() {
		if (!addToCartButton) {
			return;
		}

		var productId = addToCartButton.getAttribute('data-product-id');

		if (!productId) {
			return;
		}

		var cart = getCart();

		cart[productId] = (parseInt(cart[productId], 10) || 0) + 1;

		setCart(cart);
		updateCartCountBadge(getCartTotalQuantity(cart));

		var originalText = addToCartButton.innerHTML;

		addToCartButton.innerHTML = '✓ Added to Cart';
		addToCartButton.disabled = true;

		window.setTimeout(function () {
			addToCartButton.innerHTML = originalText;
			addToCartButton.disabled = false;
		}, 1200);
	}

	if (addToCartButton) {
		addToCartButton.addEventListener(
			'click',
			handleAddToCart
		);
	}

	initialiseCartCount();


	/* =====================================================
	   CHAT HISTORY

	   Stores only products that the user has actually clicked
	   Chat for. The Messages page can read this list and hide
	   all other products.
	===================================================== */

	var CHAT_HISTORY_KEY = 'loopbuy_chat_history';

	function getChatHistory() {
		try {
			var raw = window.localStorage.getItem(CHAT_HISTORY_KEY);
			var history = raw ? JSON.parse(raw) : [];

			if (Array.isArray(history)) {
				return history.map(String);
			}

			return [];
		} catch (error) {
			return [];
		}
	}

	function setChatHistory(history) {
		try {
			window.localStorage.setItem(
				CHAT_HISTORY_KEY,
				JSON.stringify(history)
			);
		} catch (error) {
			console.error('Unable to save chat history.', error);
		}
	}

	function saveProductToChatHistory() {
		if (!chatButton) {
			return;
		}

		var productId = chatButton.getAttribute(
			'data-chat-product-id'
		);

		if (!productId) {
			return;
		}

		var history = getChatHistory();

		if (!history.includes(String(productId))) {
			history.unshift(String(productId));
		}

		setChatHistory(history);
	}

	if (chatButton) {
		chatButton.addEventListener(
			'click',
			saveProductToChatHistory
		);
	}

});
</script>

<?php
get_footer();
?>