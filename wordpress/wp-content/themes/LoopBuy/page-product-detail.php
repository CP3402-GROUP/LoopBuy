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
                    >
                        🛍 Add to Cart
                    </button>

                    <button
                        class="detail-favourite-button"
                        type="button"
                    >
                        ♡ Save
                    </button>

                    <button
                        class="chat-button"
                        type="button"
                    >
                        💬 Chat
                    </button>

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


<?php
get_footer(); 
?>

