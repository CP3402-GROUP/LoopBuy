<?php
/**
 * Marketplace account listing dashboard.
 *
 * Listings live in the Go marketplace database. The WordPress BFF returns
 * normalized product view models while keeping access tokens out of HTML.
 *
 * @package LoopBuy
 */

$loopbuy_marketplace_user = function_exists( 'loopbuy_marketplace_current_user' )
	? loopbuy_marketplace_current_user()
	: new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'The marketplace account service is unavailable.', 'loopbuy' ) );
$loopbuy_my_listings      = is_array( $loopbuy_marketplace_user ) && function_exists( 'loopbuy_marketplace_my_listings' )
	? loopbuy_marketplace_my_listings()
	: array();
$loopbuy_posted_id        = isset( $_GET['posted'] ) && is_string( $_GET['posted'] ) && ctype_digit( wp_unslash( $_GET['posted'] ) )
	? (int) $_GET['posted']
	: 0;
$loopbuy_partial_upload   = $loopbuy_posted_id > 0
	&& isset( $_GET['upload'] )
	&& is_string( $_GET['upload'] )
	&& 'partial' === sanitize_key( wp_unslash( $_GET['upload'] ) );
$loopbuy_sell_page_url    = home_url( '/sell/' );

if ( is_array( $loopbuy_marketplace_user ) && ! function_exists( 'loopbuy_marketplace_my_listings' ) ) {
	$loopbuy_my_listings = new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'Your marketplace listings are unavailable.', 'loopbuy' ) );
}

get_header();
?>

<main id="primary" class="site-main">
	<div class="page loopbuy-my-listings">
		<div class="loopbuy-mylistings-container">
			<div class="loopbuy-mylistings-heading">
				<h1><?php esc_html_e( 'My Listings', 'loopbuy' ); ?></h1>
				<?php if ( is_array( $loopbuy_marketplace_user ) ) : ?>
					<a href="<?php echo esc_url( $loopbuy_sell_page_url ); ?>" class="loopbuy-mylistings-new-btn">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M12 5V19M5 12H19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						</svg>
						<?php esc_html_e( 'New listing', 'loopbuy' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( $loopbuy_posted_id > 0 ) : ?>
				<p class="loopbuy-mylistings-status" data-state="<?php echo esc_attr( $loopbuy_partial_upload ? 'error' : 'success' ); ?>" role="status">
					<?php
					echo esc_html(
						$loopbuy_partial_upload
							? __( 'Your listing was created, but one or more images could not be uploaded. Its current review status is shown below.', 'loopbuy' )
							: __( 'Your listing was submitted. Its status below shows whether it is live or waiting for review.', 'loopbuy' )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( ! is_array( $loopbuy_marketplace_user ) ) : ?>
				<div class="loopbuy-profile-login-notice">
					<p><?php echo esc_html( is_wp_error( $loopbuy_marketplace_user ) ? $loopbuy_marketplace_user->get_error_message() : __( 'Log in to view your listings.', 'loopbuy' ) ); ?></p>
					<a href="<?php echo esc_url( home_url( '/login/' ) ); ?>" class="auth-button"><?php esc_html_e( 'Log in', 'loopbuy' ); ?></a>
				</div>
			<?php elseif ( is_wp_error( $loopbuy_my_listings ) ) : ?>
				<p class="loopbuy-mylistings-status" data-state="error" role="alert"><?php echo esc_html( $loopbuy_my_listings->get_error_message() ); ?></p>
			<?php elseif ( empty( $loopbuy_my_listings ) ) : ?>
				<div class="loopbuy-mylistings-empty">
					<span class="loopbuy-mylistings-empty-icon" aria-hidden="true">
						<svg width="46" height="46" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M21 16.5V7.5C21 6.7 20.55 5.97 19.84 5.6L12.84 2.03C12.31 1.76 11.69 1.76 11.16 2.03L4.16 5.6C3.45 5.97 3 6.7 3 7.5V16.5C3 17.3 3.45 18.03 4.16 18.4L11.16 21.97C11.69 22.24 12.31 22.24 12.84 21.97L19.84 18.4C20.55 18.03 21 17.3 21 16.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
							<path d="M3.3 7L12 12M12 12L20.7 7M12 12V21.5" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
						</svg>
					</span>
					<h2><?php esc_html_e( 'You have no listings yet', 'loopbuy' ); ?></h2>
					<a href="<?php echo esc_url( $loopbuy_sell_page_url ); ?>" class="loopbuy-mylistings-empty-btn"><?php esc_html_e( 'Post your first item', 'loopbuy' ); ?></a>
				</div>
			<?php else : ?>
				<div class="loopbuy-mylistings-list">
					<?php foreach ( $loopbuy_my_listings as $loopbuy_listing ) : ?>
						<?php
						if ( ! is_array( $loopbuy_listing ) ) {
							continue;
						}

						$loopbuy_listing_id = isset( $loopbuy_listing['id'] ) ? absint( $loopbuy_listing['id'] ) : 0;
						$loopbuy_title      = isset( $loopbuy_listing['name'] ) && is_string( $loopbuy_listing['name'] )
							? sanitize_text_field( $loopbuy_listing['name'] )
							: '';

						if ( $loopbuy_listing_id < 1 || '' === $loopbuy_title ) {
							continue;
						}

						$loopbuy_condition = isset( $loopbuy_listing['condition'] ) && is_string( $loopbuy_listing['condition'] )
							? sanitize_text_field( $loopbuy_listing['condition'] )
							: '';
						$loopbuy_condition_key = sanitize_html_class( str_replace( array( '_', ' ' ), '-', strtolower( $loopbuy_condition ) ) );
						$loopbuy_status         = isset( $loopbuy_listing['status'] ) && is_string( $loopbuy_listing['status'] )
							? sanitize_key( $loopbuy_listing['status'] )
							: '';
						$loopbuy_moderation     = isset( $loopbuy_listing['moderation_status'] ) && is_string( $loopbuy_listing['moderation_status'] )
							? sanitize_key( $loopbuy_listing['moderation_status'] )
							: '';

						if ( 'sold' === $loopbuy_status ) {
							$loopbuy_status_label = __( 'Sold', 'loopbuy' );
							$loopbuy_status_class = 'status-sold';
						} elseif ( 'reserved' === $loopbuy_status ) {
							$loopbuy_status_label = __( 'Reserved', 'loopbuy' );
							$loopbuy_status_class = 'status-active status-reserved';
						} elseif ( 'archived' === $loopbuy_status ) {
							$loopbuy_status_label = __( 'Archived', 'loopbuy' );
							$loopbuy_status_class = 'status-active status-archived';
						} elseif ( 'draft' === $loopbuy_status ) {
							$loopbuy_status_label = __( 'Draft', 'loopbuy' );
							$loopbuy_status_class = 'status-active status-draft';
						} elseif ( 'rejected' === $loopbuy_moderation ) {
							$loopbuy_status_label = __( 'Rejected', 'loopbuy' );
							$loopbuy_status_class = 'status-active status-rejected';
						} elseif ( 'under_review' === $loopbuy_status || in_array( $loopbuy_moderation, array( 'pending', 'review', 'unavailable' ), true ) ) {
							$loopbuy_status_label = __( 'Under review', 'loopbuy' );
							$loopbuy_status_class = 'status-active status-under-review';
						} elseif ( 'active' === $loopbuy_status && 'approved' === $loopbuy_moderation ) {
							$loopbuy_status_label = __( 'Active', 'loopbuy' );
							$loopbuy_status_class = 'status-active';
						} else {
							$loopbuy_status_label = __( 'Unavailable', 'loopbuy' );
							$loopbuy_status_class = 'status-active status-unavailable';
						}

						$loopbuy_detail_url = home_url( '/product-detail/?id=' . $loopbuy_listing_id );
						$loopbuy_image_raw  = isset( $loopbuy_listing['image_url'] ) && is_string( $loopbuy_listing['image_url'] )
							? $loopbuy_listing['image_url']
							: ( isset( $loopbuy_listing['image'] ) && is_string( $loopbuy_listing['image'] ) ? $loopbuy_listing['image'] : '' );
						$loopbuy_image_url  = esc_url_raw( $loopbuy_image_raw, array( 'http', 'https' ) );
						$loopbuy_price      = isset( $loopbuy_listing['price'] ) && is_numeric( $loopbuy_listing['price'] )
							? (float) $loopbuy_listing['price']
							: null;
						$loopbuy_currency   = isset( $loopbuy_listing['currency'] ) && is_string( $loopbuy_listing['currency'] )
							? strtoupper( sanitize_text_field( $loopbuy_listing['currency'] ) )
							: 'SGD';
						?>

						<article class="loopbuy-mylistings-item" data-listing-id="<?php echo esc_attr( $loopbuy_listing_id ); ?>">
							<a href="<?php echo esc_url( $loopbuy_detail_url ); ?>" class="loopbuy-mylistings-item-image">
								<?php if ( '' !== $loopbuy_image_url ) : ?>
									<img src="<?php echo esc_url( $loopbuy_image_url ); ?>" alt="<?php echo esc_attr( $loopbuy_title ); ?>" loading="lazy" decoding="async">
								<?php else : ?>
									<span class="loopbuy-mylistings-item-image-placeholder" aria-hidden="true"></span>
								<?php endif; ?>
							</a>

							<div class="loopbuy-mylistings-item-content">
								<div class="loopbuy-mylistings-item-title-row">
									<a href="<?php echo esc_url( $loopbuy_detail_url ); ?>" class="loopbuy-mylistings-item-title"><?php echo esc_html( $loopbuy_title ); ?></a>
									<?php if ( '' !== $loopbuy_condition ) : ?>
										<span class="loopbuy-condition-badge condition-<?php echo esc_attr( $loopbuy_condition_key ); ?>"><?php echo esc_html( $loopbuy_condition ); ?></span>
									<?php endif; ?>
								</div>

								<?php if ( null !== $loopbuy_price ) : ?>
									<p class="loopbuy-mylistings-item-price"><?php echo esc_html( $loopbuy_currency . ' ' . number_format_i18n( $loopbuy_price, 2 ) ); ?></p>
								<?php endif; ?>

								<span class="loopbuy-status-badge <?php echo esc_attr( $loopbuy_status_class ); ?>"><?php echo esc_html( $loopbuy_status_label ); ?></span>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</main>

<?php
get_footer();
