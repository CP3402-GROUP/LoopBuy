<?php
/**
 * The template for displaying the Cart page.
 *
 * WordPress automatically uses this file for a Page whose slug is "cart"
 * (template hierarchy: page-cart.php) — create a Page titled "Cart"
 * with the slug "cart" in wp-admin and it will pick this up automatically.
 *
 * Cart items are tracked client-side (no login required) in localStorage
 * under the key below, as a map of product id -> quantity, e.g. {"2":1}.
 * The "Add to Cart" button on page-product-detail.php writes to this same
 * key, so items added there show up here automatically.
 *
 * @package LoopBuy
 */

get_header();

require get_template_directory() . '/inc/product-data.php';
?>

<main class="loopbuy-cart-page">

	<section class="cart-page-container">

		<div class="cart-page-heading">

			<h1><?php esc_html_e( 'Your Cart', 'loopbuy' ); ?></h1>

			<a href="#" id="cart-clear-all" class="cart-clear-all" hidden><?php esc_html_e( 'Clear all', 'loopbuy' ); ?></a>

		</div>


		<div id="cart-empty-message" class="cart-empty-message">

			<svg width="56" height="56" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="cart-empty-icon">
				<path d="M6 8V6a6 6 0 1 1 12 0v2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
				<path d="M4.5 8H19.5L18.6 19.2A2 2 0 0 1 16.6 21H7.4A2 2 0 0 1 5.4 19.2L4.5 8Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
			</svg>

			<p class="cart-empty-title"><?php esc_html_e( 'Your cart is empty', 'loopbuy' ); ?></p>
			<p class="cart-empty-text"><?php esc_html_e( 'Browse listings and add items you love.', 'loopbuy' ); ?></p>

			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="cart-browse-button"><?php esc_html_e( 'Start browsing', 'loopbuy' ); ?></a>

		</div>


		<div id="cart-items" class="cart-items" hidden>

			<?php foreach ( $products as $product ) : ?>

				<article
					class="cart-item"
					data-product-id="<?php echo esc_attr( $product['id'] ); ?>"
					data-price="<?php echo esc_attr( $product['price'] ); ?>"
					style="display: none;"
				>

					<div class="cart-item-image">
						<img
							src="<?php
							echo esc_url(
								get_template_directory_uri()
								. '/images/'
								. $product['image']
							);
							?>"
							alt="<?php echo esc_attr( $product['name'] ); ?>"
						>
					</div>


					<div class="cart-item-content">

						<h2><?php echo esc_html( $product['name'] ); ?></h2>

						<p class="cart-item-brand"><?php echo esc_html( $product['brand'] ); ?></p>

						<p class="cart-item-price">$<?php echo esc_html( number_format( (float) $product['price'] ) ); ?></p>

						<div class="cart-qty-control">
							<button type="button" class="cart-qty-btn cart-qty-decrease" aria-label="<?php echo esc_attr__( 'Decrease quantity', 'loopbuy' ); ?>">−</button>
							<span class="cart-qty-value">1</span>
							<button type="button" class="cart-qty-btn cart-qty-increase" aria-label="<?php echo esc_attr__( 'Increase quantity', 'loopbuy' ); ?>">+</button>
						</div>

					</div>


					<button type="button" class="cart-remove-button" aria-label="<?php echo esc_attr__( 'Remove from cart', 'loopbuy' ); ?>">
						<svg width="15" height="15" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m-9 0 1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<?php esc_html_e( 'Remove', 'loopbuy' ); ?>
					</button>

				</article>

			<?php endforeach; ?>

		</div>


		<div id="cart-summary" class="cart-summary" hidden>

			<div class="cart-summary-row">
				<span class="cart-summary-label"><?php esc_html_e( 'Total', 'loopbuy' ); ?></span>
				<span class="cart-summary-total" id="cart-total">$0</span>
			</div>

			<button type="button" class="cart-checkout-button">
				→ <?php esc_html_e( 'Checkout', 'loopbuy' ); ?>
			</button>

		</div>

	</section>

</main>

<script>
/* =========================================================
   REVEAL CART ITEMS
   Reads/writes the shared localStorage cart map that
   page-product-detail.php's "Add to Cart" button writes to.
========================================================= */
document.addEventListener( 'DOMContentLoaded', function () {

	var STORAGE_KEY = 'loopbuy_cart_items';

	var emptyMessage  = document.getElementById( 'cart-empty-message' );
	var itemsWrap      = document.getElementById( 'cart-items' );
	var summary        = document.getElementById( 'cart-summary' );
	var totalEl        = document.getElementById( 'cart-total' );
	var clearAllLink   = document.getElementById( 'cart-clear-all' );
	var cards           = document.querySelectorAll( '.cart-item' );

	function getCart() {
		try {
			var raw  = window.localStorage.getItem( STORAGE_KEY );
			var cart = raw ? JSON.parse( raw ) : {};
			return ( cart && typeof cart === 'object' && ! Array.isArray( cart ) ) ? cart : {};
		} catch ( e ) {
			return {};
		}
	}

	function setCart( cart ) {
		try {
			window.localStorage.setItem( STORAGE_KEY, JSON.stringify( cart ) );
		} catch ( e ) {}
	}

	function updateCartCountBadge( count ) {
		var badge = document.querySelector( '[data-cart-count]' );
		if ( badge ) {
			badge.textContent = count;
			badge.hidden = count === 0;
		}
	}

	function formatPrice( value ) {
		return '$' + Math.round( value ).toLocaleString();
	}

	function render() {
		var cart       = getCart();
		var hasItems   = false;
		var totalQty   = 0;
		var totalPrice = 0;

		cards.forEach( function ( card ) {
			var id    = card.getAttribute( 'data-product-id' );
			var qty   = cart[ id ] || 0;
			var price = parseFloat( card.getAttribute( 'data-price' ) ) || 0;

			if ( qty > 0 ) {
				card.style.display = '';
				card.querySelector( '.cart-qty-value' ).textContent = qty;
				hasItems = true;
				totalQty += qty;
				totalPrice += qty * price;
			} else {
				card.style.display = 'none';
			}
		} );

		emptyMessage.hidden = hasItems;
		itemsWrap.hidden = ! hasItems;
		summary.hidden = ! hasItems;
		clearAllLink.hidden = ! hasItems;

		totalEl.textContent = formatPrice( totalPrice );
		updateCartCountBadge( totalQty );
	}

	cards.forEach( function ( card ) {
		var id        = card.getAttribute( 'data-product-id' );
		var minusBtn  = card.querySelector( '.cart-qty-decrease' );
		var plusBtn   = card.querySelector( '.cart-qty-increase' );
		var removeBtn = card.querySelector( '.cart-remove-button' );

		minusBtn.addEventListener( 'click', function () {
			var cart = getCart();
			var qty  = ( cart[ id ] || 0 ) - 1;

			if ( qty > 0 ) {
				cart[ id ] = qty;
			} else {
				delete cart[ id ];
			}

			setCart( cart );
			render();
		} );

		plusBtn.addEventListener( 'click', function () {
			var cart = getCart();
			cart[ id ] = ( cart[ id ] || 0 ) + 1;
			setCart( cart );
			render();
		} );

		removeBtn.addEventListener( 'click', function () {
			var cart = getCart();
			delete cart[ id ];
			setCart( cart );
			render();
		} );
	} );

	clearAllLink.addEventListener( 'click', function ( event ) {
		event.preventDefault();
		setCart( {} );
		render();
	} );

	render();

} );
</script>

<?php
get_footer();