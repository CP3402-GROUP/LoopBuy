<?php
/**
 * The template for displaying the "My Listings" page.
 *
 * WordPress automatically uses this file for a Page whose slug is
 * "my-listings" (template hierarchy: page-my-listings.php) — just create
 * a Page titled "My Listings" with the slug "my-listings" in wp-admin and
 * it will pick this up automatically, no need to manually assign a template.
 *
 * Data model assumptions (adjust to match your actual setup):
 * - Listings are stored as a custom post type: 'loopbuy_listing'
 * - post_author is the seller
 * - Meta keys: '_loopbuy_price' (number), '_loopbuy_condition'
 *   ('new' | 'like-new' | 'good' | 'fair'), '_loopbuy_status'
 *   ('active' | 'sold'), featured image = listing photo.
 *
 * @package LoopBuy
 */

get_header();

$current_user_id = get_current_user_id();

$listings_query = new WP_Query(
	array(
		'post_type'      => 'loopbuy_listing',
		'author'         => $current_user_id,
		'post_status'    => array( 'publish', 'draft' ),
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
	)
);

$sell_page_url = home_url( '/sell' );
?>

<main id="primary" class="site-main">
	<div class="page loopbuy-my-listings">
		<div class="loopbuy-mylistings-container">

			<div class="loopbuy-mylistings-heading">
				<h1><?php esc_html_e( 'My Listings', 'loopbuy' ); ?></h1>
				<a href="<?php echo esc_url( $sell_page_url ); ?>" class="loopbuy-mylistings-new-btn">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<path d="M12 5V19M5 12H19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					</svg>
					<?php esc_html_e( 'New listing', 'loopbuy' ); ?>
				</a>
			</div>

			<?php if ( isset( $_GET['posted'] ) && absint( $_GET['posted'] ) > 0 ) : ?>
				<p class="loopbuy-mylistings-status" data-state="success">
					<?php esc_html_e( 'Your listing is live!', 'loopbuy' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( ! $listings_query->have_posts() ) : ?>

				<!-- Empty state -->
				<div class="loopbuy-mylistings-empty">
					<span class="loopbuy-mylistings-empty-icon" aria-hidden="true">
						<svg width="46" height="46" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M21 16.5V7.5C21 6.7 20.55 5.97 19.84 5.6L12.84 2.03C12.31 1.76 11.69 1.76 11.16 2.03L4.16 5.6C3.45 5.97 3 6.7 3 7.5V16.5C3 17.3 3.45 18.03 4.16 18.4L11.16 21.97C11.69 22.24 12.31 22.24 12.84 21.97L19.84 18.4C20.55 18.03 21 17.3 21 16.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
							<path d="M3.3 7L12 12M12 12L20.7 7M12 12V21.5" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
						</svg>
					</span>
					<h2><?php esc_html_e( 'You have no listings yet', 'loopbuy' ); ?></h2>
					<a href="<?php echo esc_url( $sell_page_url ); ?>" class="loopbuy-mylistings-empty-btn">
						<?php esc_html_e( 'Post your first item', 'loopbuy' ); ?>
					</a>
				</div>

			<?php else : ?>

				<!-- Listings -->
				<div class="loopbuy-mylistings-list">
					<?php
					while ( $listings_query->have_posts() ) :
						$listings_query->the_post();

						$listing_id     = get_the_ID();
						$price          = get_post_meta( $listing_id, '_loopbuy_price', true );
						$condition      = get_post_meta( $listing_id, '_loopbuy_condition', true );
						$status         = get_post_meta( $listing_id, '_loopbuy_status', true );
						$status         = $status ? $status : 'active';
						$condition_label = $condition ? ucwords( str_replace( '-', ' ', $condition ) ) : '';

						$condition_class = 'condition-' . sanitize_html_class( $condition ? $condition : 'good' );
						$status_class    = 'status-' . sanitize_html_class( $status );
						?>

						<div class="loopbuy-mylistings-item" data-listing-id="<?php echo esc_attr( $listing_id ); ?>">

							<a href="<?php the_permalink(); ?>" class="loopbuy-mylistings-item-image">
								<?php if ( has_post_thumbnail() ) : ?>
									<?php the_post_thumbnail( 'thumbnail' ); ?>
								<?php else : ?>
									<span class="loopbuy-mylistings-item-image-placeholder" aria-hidden="true"></span>
								<?php endif; ?>
							</a>

							<div class="loopbuy-mylistings-item-content">
								<div class="loopbuy-mylistings-item-title-row">
									<a href="<?php the_permalink(); ?>" class="loopbuy-mylistings-item-title">
										<?php the_title(); ?>
									</a>
									<?php if ( $condition_label ) : ?>
										<span class="loopbuy-condition-badge <?php echo esc_attr( $condition_class ); ?>">
											<?php echo esc_html( $condition_label ); ?>
										</span>
									<?php endif; ?>
								</div>

								<?php if ( '' !== $price ) : ?>
									<p class="loopbuy-mylistings-item-price">
										$<?php echo esc_html( number_format_i18n( (float) $price ) ); ?>
									</p>
								<?php endif; ?>

								<span class="loopbuy-status-badge <?php echo esc_attr( $status_class ); ?>">
									<?php echo 'sold' === $status ? esc_html__( 'Sold', 'loopbuy' ) : esc_html__( 'Active', 'loopbuy' ); ?>
								</span>
							</div>

							<div class="loopbuy-mylistings-item-actions">
								<?php if ( 'sold' !== $status ) : ?>
									<button type="button" class="loopbuy-mylistings-action-btn loopbuy-mark-sold" data-listing-id="<?php echo esc_attr( $listing_id ); ?>">
										<svg width="15" height="15" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
											<path d="M12 22C17.5 22 22 17.5 22 12C22 6.5 17.5 2 12 2C6.5 2 2 6.5 2 12C2 17.5 6.5 22 12 22Z" stroke="currentColor" stroke-width="1.6"/>
											<path d="M8 12.5L10.5 15L16 9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
										<?php esc_html_e( 'Mark sold', 'loopbuy' ); ?>
									</button>
								<?php endif; ?>

								<a href="<?php echo esc_url( add_query_arg( 'edit', $listing_id, $sell_page_url ) ); ?>" class="loopbuy-mylistings-action-btn">
									<svg width="15" height="15" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
										<path d="M4 20H8.5L18.4 10.1C19.3 9.2 19.3 7.7 18.4 6.8L17.2 5.6C16.3 4.7 14.8 4.7 13.9 5.6L4 15.5V20Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
										<path d="M12.5 6.5L17.5 11.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
									</svg>
									<?php esc_html_e( 'Edit', 'loopbuy' ); ?>
								</a>

								<button type="button" class="loopbuy-mylistings-delete-btn loopbuy-delete-listing" data-listing-id="<?php echo esc_attr( $listing_id ); ?>" aria-label="<?php esc_attr_e( 'Delete listing', 'loopbuy' ); ?>">
									<svg width="17" height="17" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
										<path d="M4 7H20M9 7V5C9 4.4 9.4 4 10 4H14C14.6 4 15 4.4 15 5V7M18.5 7L17.8 18.5C17.7 19.7 16.7 20.6 15.5 20.6H8.5C7.3 20.6 6.3 19.7 6.2 18.5L5.5 7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M10 11V16M14 11V16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
									</svg>
								</button>
							</div>

						</div>

					<?php endwhile; ?>
				</div>

				<?php wp_reset_postdata(); ?>

			<?php endif; ?>

		</div><!-- .loopbuy-mylistings-container -->
	</div><!-- .loopbuy-my-listings -->
</main><!-- #primary -->

<script>
document.addEventListener( 'DOMContentLoaded', function () {

	// Delete a listing.
	document.querySelectorAll( '.loopbuy-delete-listing' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			if ( ! window.confirm( '<?php echo esc_js( __( 'Delete this listing? This cannot be undone.', 'loopbuy' ) ); ?>' ) ) {
				return;
			}

			var listingId = btn.getAttribute( 'data-listing-id' );
			var item       = btn.closest( '.loopbuy-mylistings-item' );

			// TODO: wire this up to your delete endpoint (REST route or admin-ajax action).
			fetch( '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=loopbuy_delete_listing&listing_id=' + encodeURIComponent( listingId ) +
					'&nonce=<?php echo esc_js( wp_create_nonce( 'loopbuy_my_listings' ) ); ?>'
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( res ) {
					if ( res && res.success ) {
						item.remove();
						if ( ! document.querySelector( '.loopbuy-mylistings-item' ) ) {
							window.location.reload();
						}
					}
				} )
				.catch( function () {
					window.location.reload();
				} );
		} );
	} );

	// Mark a listing as sold.
	document.querySelectorAll( '.loopbuy-mark-sold' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var listingId = btn.getAttribute( 'data-listing-id' );

			// TODO: wire this up to your update endpoint (REST route or admin-ajax action).
			fetch( '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=loopbuy_mark_listing_sold&listing_id=' + encodeURIComponent( listingId ) +
					'&nonce=<?php echo esc_js( wp_create_nonce( 'loopbuy_my_listings' ) ); ?>'
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( res ) {
					if ( res && res.success ) {
						window.location.reload();
					}
				} )
				.catch( function () {
					window.location.reload();
				} );
		} );
	} );

} );
</script>

<?php
get_footer();