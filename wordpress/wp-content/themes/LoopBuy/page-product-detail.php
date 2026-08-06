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

$loopbuy_detail_user = function_exists( 'loopbuy_marketplace_current_user' )
	? loopbuy_marketplace_current_user()
	: null;

/* Get a positive numeric product ID from the URL. */
$product_id_raw = isset( $_GET['id'] )
    ? wp_unslash( $_GET['id'] )
    : '1';
$product_id = filter_var(
    $product_id_raw,
    FILTER_VALIDATE_INT,
    array(
        'options' => array(
            'min_range' => 1,
        ),
    )
);

if ( false === $product_id ) {
    $product_id = 0;
}

/* Prefer the dedicated backend detail endpoint over the capped catalogue. */
$product = null;

if ( $product_id > 0 ) {
	$loopbuy_backend_product = null;

	if ( is_array( $loopbuy_detail_user )
		&& function_exists( 'loopbuy_marketplace_get_listing' ) ) {
		$loopbuy_backend_product = loopbuy_marketplace_get_listing( $product_id );
	}

	if ( null === $loopbuy_backend_product ) {
		$loopbuy_backend_product = function_exists( 'loopbuy_backend_get_public_product' )
			? loopbuy_backend_get_public_product( $product_id )
			: new WP_Error( 'loopbuy_backend_bridge_unavailable', 'The backend detail bridge is unavailable.' );
	}

    if ( is_wp_error( $loopbuy_backend_product ) ) {
        // A genuine backend/contract failure may use the bundled legacy data.
        // Suppress the catalogue API call so a 404 or empty detail response can
        // never be replaced by an unrelated row from the first 100 listings.
        $loopbuy_skip_backend_catalog = true;
        require get_template_directory() . '/inc/product-data.php';
        unset( $loopbuy_skip_backend_catalog );

        foreach ( $products as $item ) {
            if ( (int) $item['id'] === $product_id ) {
                $product = $item;
                break;
            }
        }
    } elseif ( is_array( $loopbuy_backend_product ) ) {
        $product = $loopbuy_backend_product;
    }

    unset( $loopbuy_backend_product );
}

get_header();

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

$product_moderation_status = sanitize_key(
    isset($product['moderation_status'])
        ? $product['moderation_status']
        : ''
);
$product_scam_label = sanitize_key(
    isset($product['scam_label'])
        ? $product['scam_label']
        : ''
);
$product_is_verified =
    'approved' === $product_moderation_status
    && 'low_risk' === $product_scam_label;
$product_safety_state = isset($product['safety_state'])
    ? sanitize_key($product['safety_state'])
    : 'unavailable';

if ($product_is_verified) {
    $product_safety_message = __('This listing passed safety screening.', 'loopbuy');
} elseif ('disabled' === $product_safety_state || 'not_screened' === $product_scam_label) {
    $product_safety_message = __('Automated scam screening is temporarily disabled. This listing has not been verified, so use normal buying precautions.', 'loopbuy');
} elseif ('approved' === $product_moderation_status && 'needs_review' === $product_scam_label) {
    $product_safety_message = __('Automated screening found no concrete scam language, but this borderline result is not a verification. Use normal buying precautions.', 'loopbuy');
} elseif ('pending' === $product_safety_state) {
    $product_safety_message = __('Safety screening is in progress.', 'loopbuy');
} elseif ('review' === $product_safety_state) {
    $product_safety_message = __('Safety screening requires additional review.', 'loopbuy');
} else {
    $product_safety_message = __('Safety screening status is not available.', 'loopbuy');
}

$product_image_url = function_exists( 'loopbuy_product_image_url' )
    ? loopbuy_product_image_url( $product )
    : esc_url_raw(
        isset( $product['image_url'] ) ? $product['image_url'] : '',
        array( 'http', 'https' )
    );
$product_images = isset( $product['images'] ) && is_array( $product['images'] )
    ? array_values( $product['images'] )
    : array();

if ( empty( $product_images ) && '' !== $product_image_url ) {
    $product_images[] = array(
        'image_url'  => $product_image_url,
        'is_primary' => true,
        'sort_order' => 0,
    );
}

$product_image_count = count( $product_images );

if ( $product_image_count > 1 ) {
    $loopbuy_product_gallery_script = get_template_directory() . '/js/product-gallery.js';

    if ( is_file( $loopbuy_product_gallery_script ) ) {
        wp_enqueue_script(
            'loopbuy-product-gallery',
            get_template_directory_uri() . '/js/product-gallery.js',
            array(),
            (string) filemtime( $loopbuy_product_gallery_script ),
            true
        );
    }
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

            <div
                class="product-gallery-shell<?php echo $product_image_count > 1 ? ' has-multiple-images' : ''; ?>"
                data-product-gallery
                aria-label="<?php esc_attr_e( 'Listing photos', 'loopbuy' ); ?>"
            >
                <figure class="product-detail-image" data-gallery-stage>
                    <?php if ( $product_image_count > 0 ) : ?>
                        <img
                            src="<?php echo esc_url( $product_images[0]['image_url'] ); ?>"
                            alt="<?php echo esc_attr( sprintf( __( '%1$s, photo %2$d of %3$d', 'loopbuy' ), $product['name'], 1, $product_image_count ) ); ?>"
                            data-gallery-main
                            decoding="async"
                        >

                        <?php if ( $product_image_count > 1 ) : ?>
                            <button class="product-gallery-nav product-gallery-prev" type="button" data-gallery-prev aria-label="<?php esc_attr_e( 'Show previous photo', 'loopbuy' ); ?>">
                                <span aria-hidden="true">&#8249;</span>
                            </button>
                            <button class="product-gallery-nav product-gallery-next" type="button" data-gallery-next aria-label="<?php esc_attr_e( 'Show next photo', 'loopbuy' ); ?>">
                                <span aria-hidden="true">&#8250;</span>
                            </button>
                            <figcaption class="product-gallery-count" data-gallery-count aria-live="polite">
                                <?php echo esc_html( sprintf( __( '%1$d / %2$d', 'loopbuy' ), 1, $product_image_count ) ); ?>
                            </figcaption>
                        <?php endif; ?>
                    <?php else : ?>
                        <div class="product-gallery-empty" data-gallery-empty role="img" aria-label="<?php esc_attr_e( 'No listing photo available', 'loopbuy' ); ?>">
                            <svg width="52" height="52" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="16" rx="2.5" stroke="currentColor" stroke-width="1.5"/>
                                <circle cx="8.5" cy="9" r="1.5" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M5.5 17L10 12.5L13 15.5L15.5 13L19 16.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span><?php esc_html_e( 'No photo available', 'loopbuy' ); ?></span>
                        </div>
                    <?php endif; ?>
                </figure>

                <?php if ( $product_image_count > 1 ) : ?>
                    <div class="product-gallery-thumbnails" role="group" aria-label="<?php esc_attr_e( 'Choose a listing photo', 'loopbuy' ); ?>" data-gallery-thumbnails>
                        <?php foreach ( $product_images as $product_image_index => $product_image ) : ?>
                            <?php
                            $product_photo_number = $product_image_index + 1;
                            $product_photo_alt    = sprintf(
                                __( '%1$s, photo %2$d of %3$d', 'loopbuy' ),
                                $product['name'],
                                $product_photo_number,
                                $product_image_count
                            );
                            ?>
                            <button
                                class="product-gallery-thumbnail<?php echo 0 === $product_image_index ? ' is-active' : ''; ?>"
                                type="button"
                                data-gallery-thumbnail
                                data-gallery-index="<?php echo esc_attr( (string) $product_image_index ); ?>"
                                data-gallery-src="<?php echo esc_url( $product_image['image_url'] ); ?>"
                                data-gallery-alt="<?php echo esc_attr( $product_photo_alt ); ?>"
                                aria-label="<?php echo esc_attr( sprintf( __( 'Show photo %1$d of %2$d', 'loopbuy' ), $product_photo_number, $product_image_count ) ); ?>"
                                aria-pressed="<?php echo 0 === $product_image_index ? 'true' : 'false'; ?>"
                                tabindex="<?php echo 0 === $product_image_index ? '0' : '-1'; ?>"
                            >
                                <img src="<?php echo esc_url( $product_image['image_url'] ); ?>" alt="" loading="lazy" decoding="async">
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>


            <div class="product-detail-info">

                <div class="product-badges">

                    <span class="product-condition">
                        <?php echo esc_html($product['condition']); ?>
                    </span>

                    <?php if ($product_is_verified) : ?>

                        <span class="product-verified">
                            &#10003; <?php esc_html_e( 'Verified', 'loopbuy' ); ?>
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
                    🛡 <?php echo esc_html($product_safety_message); ?>
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
                        aria-label="<?php esc_attr_e( 'Save product', 'loopbuy' ); ?>"
                        aria-pressed="false"
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
