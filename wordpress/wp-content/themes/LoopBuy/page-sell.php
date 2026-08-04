<?php
/**
 * The template for displaying the "Post a listing" (Sell) page.
 *
 * WordPress automatically uses this file for a Page whose slug is "sell"
 * (template hierarchy: page-sell.php) — just create a Page titled "Sell"
 * with the slug "sell" in wp-admin and it will pick this up automatically,
 * no need to manually assign a template.
 *
 * On submit, this creates a 'loopbuy_listing' post (see
 * inc-loopbuy-listing-cpt.php for the post type registration) so the new
 * listing immediately shows up on the My Listings page and on the
 * profile page's "My Listings" panel.
 *
 * @package LoopBuy
 */

$loopbuy_sell_errors    = array();
$loopbuy_new_listing_id = 0;

// Sticky field values: start empty, get overwritten below if the form was submitted.
$loopbuy_v = array(
	'title'       => '',
	'price'       => '',
	'brand'       => '',
	'category'    => '',
	'condition'   => 'good',
	'location'    => '',
	'description' => '',
);

/**
 * Handles a multi-file input (name="field[]") upload and attaches each
 * file to $post_id. Returns an array of attachment IDs.
 */
if ( ! function_exists( 'loopbuy_handle_sell_photo_uploads' ) ) {
	function loopbuy_handle_sell_photo_uploads( $field_name, $post_id ) {

		if ( empty( $_FILES[ $field_name ] ) || empty( $_FILES[ $field_name ]['name'][0] ) ) {
			return array();
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_ids = array();
		$files          = $_FILES[ $field_name ];
		$file_count     = count( $files['name'] );

		for ( $i = 0; $i < $file_count; $i++ ) {

			if ( empty( $files['name'][ $i ] ) ) {
				continue;
			}

			// media_handle_upload() reads a single file out of $_FILES by key,
			// so temporarily stage this one file under its own key.
			$_FILES['loopbuy_single_upload'] = array(
				'name'     => $files['name'][ $i ],
				'type'     => $files['type'][ $i ],
				'tmp_name' => $files['tmp_name'][ $i ],
				'error'    => $files['error'][ $i ],
				'size'     => $files['size'][ $i ],
			);

			$attachment_id = media_handle_upload( 'loopbuy_single_upload', $post_id );

			if ( ! is_wp_error( $attachment_id ) ) {
				$attachment_ids[] = $attachment_id;
			}
		}

		unset( $_FILES['loopbuy_single_upload'] );

		return $attachment_ids;
	}
}

// Handle form submission BEFORE get_header() so we can redirect on success.
if ( isset( $_POST['loopbuy_sell_submit'] ) ) {

	if ( ! is_user_logged_in() ) {

		$loopbuy_sell_errors[] = __( 'Please log in to post a listing.', 'loopbuy' );

	} elseif ( ! isset( $_POST['loopbuy_sell_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['loopbuy_sell_nonce'] ) ), 'loopbuy_sell_form' ) ) {

		$loopbuy_sell_errors[] = __( 'Your session expired. Please try again.', 'loopbuy' );

	} else {

		$loopbuy_v['title']       = isset( $_POST['loopbuy_title'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_title'] ) ) : '';
		$loopbuy_v['price']       = isset( $_POST['loopbuy_price'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_price'] ) ) : '';
		$loopbuy_v['brand']       = isset( $_POST['loopbuy_brand'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_brand'] ) ) : '';
		$loopbuy_v['category']    = isset( $_POST['loopbuy_category'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_category'] ) ) : '';
		$loopbuy_v['condition']   = isset( $_POST['loopbuy_condition'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_condition'] ) ) : 'good';
		$loopbuy_v['location']    = isset( $_POST['loopbuy_location'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_location'] ) ) : '';
		$loopbuy_v['description'] = isset( $_POST['loopbuy_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['loopbuy_description'] ) ) : '';

		if ( empty( $loopbuy_v['title'] ) ) {
			$loopbuy_sell_errors[] = __( 'Please enter a title for your listing.', 'loopbuy' );
		}

		if ( '' === $loopbuy_v['price'] || ! is_numeric( $loopbuy_v['price'] ) || (float) $loopbuy_v['price'] < 0 ) {
			$loopbuy_sell_errors[] = __( 'Please enter a valid price.', 'loopbuy' );
		}

		if ( empty( $loopbuy_sell_errors ) ) {

			$loopbuy_post_id = wp_insert_post(
				array(
					'post_type'    => 'loopbuy_listing',
					'post_title'   => $loopbuy_v['title'],
					'post_content' => $loopbuy_v['description'],
					'post_status'  => 'publish',
					'post_author'  => get_current_user_id(),
				),
				true
			);

			if ( is_wp_error( $loopbuy_post_id ) ) {

				$loopbuy_sell_errors[] = $loopbuy_post_id->get_error_message();

			} else {

				update_post_meta( $loopbuy_post_id, '_loopbuy_price', (float) $loopbuy_v['price'] );
				update_post_meta( $loopbuy_post_id, '_loopbuy_brand', $loopbuy_v['brand'] );
				update_post_meta( $loopbuy_post_id, '_loopbuy_category', $loopbuy_v['category'] );
				update_post_meta( $loopbuy_post_id, '_loopbuy_condition', $loopbuy_v['condition'] );
				update_post_meta( $loopbuy_post_id, '_loopbuy_location', $loopbuy_v['location'] );
				update_post_meta( $loopbuy_post_id, '_loopbuy_status', 'active' );

				$loopbuy_attachment_ids = loopbuy_handle_sell_photo_uploads( 'loopbuy_photos', $loopbuy_post_id );

				if ( ! empty( $loopbuy_attachment_ids ) ) {
					set_post_thumbnail( $loopbuy_post_id, $loopbuy_attachment_ids[0] );
					update_post_meta( $loopbuy_post_id, '_loopbuy_gallery', $loopbuy_attachment_ids );
				}

				$loopbuy_new_listing_id = $loopbuy_post_id;

				// Send the seller straight to My Listings so they immediately see it.
				wp_safe_redirect( add_query_arg( 'posted', $loopbuy_post_id, home_url( '/my-listings/' ) ) );
				exit;
			}
		}
	}
}

get_header();
?>

<main id="primary" class="site-main">
	<div class="page loopbuy-sell">

		<div class="loopbuy-sell-header">
			<h1 class="loopbuy-sell-title"><?php esc_html_e( 'Post a listing', 'loopbuy' ); ?></h1>
			<p class="loopbuy-sell-subtitle"><?php esc_html_e( 'Our AI screens listings for scams before they go live.', 'loopbuy' ); ?></p>
		</div>

		<?php if ( ! is_user_logged_in() ) : ?>

			<div class="loopbuy-profile-login-notice">
				<p><?php esc_html_e( 'You need to be logged in to post a listing.', 'loopbuy' ); ?></p>
				<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="auth-button"><?php esc_html_e( 'Log in', 'loopbuy' ); ?></a>
			</div>

		<?php else : ?>

		<form class="loopbuy-sell-form" method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'loopbuy_sell_form', 'loopbuy_sell_nonce' ); ?>

			<?php foreach ( $loopbuy_sell_errors as $loopbuy_sell_error ) : ?>
				<p class="loopbuy-sell-status" data-state="error"><?php echo esc_html( $loopbuy_sell_error ); ?></p>
			<?php endforeach; ?>

			<div class="loopbuy-sell-field">
				<label for="loopbuy-sell-photos"><?php esc_html_e( 'Photos', 'loopbuy' ); ?></label>
				<label for="loopbuy-sell-photos" class="loopbuy-photo-upload" id="loopbuy-photo-dropzone">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<path d="M12 15.5V4M12 4L7.5 8.5M12 4L16.5 8.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M4 15.5V17.5C4 18.6046 4.89543 19.5 6 19.5H18C19.1046 19.5 20 18.6046 20 17.5V15.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
					</svg>
					<span class="screen-reader-text"><?php esc_html_e( 'Upload photos', 'loopbuy' ); ?></span>
					<input type="file" id="loopbuy-sell-photos" name="loopbuy_photos[]" accept="image/*" multiple hidden>
				</label>
			</div>

			<div class="loopbuy-sell-field">
				<label for="loopbuy-sell-title"><?php esc_html_e( 'Title', 'loopbuy' ); ?></label>
				<input type="text" id="loopbuy-sell-title" name="loopbuy_title" value="<?php echo esc_attr( $loopbuy_v['title'] ); ?>" placeholder="<?php echo esc_attr_x( 'e.g. iPhone 13 Pro 128GB', 'sell form placeholder', 'loopbuy' ); ?>">
			</div>

			<div class="loopbuy-sell-row">
				<div class="loopbuy-sell-field">
					<label for="loopbuy-sell-price"><?php esc_html_e( 'Price ($)', 'loopbuy' ); ?></label>
					<input type="number" step="0.01" min="0" id="loopbuy-sell-price" name="loopbuy_price" value="<?php echo esc_attr( $loopbuy_v['price'] ); ?>" placeholder="0.00">
				</div>
				<div class="loopbuy-sell-field">
					<label for="loopbuy-sell-brand"><?php esc_html_e( 'Brand', 'loopbuy' ); ?></label>
					<input type="text" id="loopbuy-sell-brand" name="loopbuy_brand" value="<?php echo esc_attr( $loopbuy_v['brand'] ); ?>" placeholder="<?php echo esc_attr_x( 'Apple', 'sell form placeholder', 'loopbuy' ); ?>">
				</div>
			</div>

			<div class="loopbuy-ai-panel">
				<div class="loopbuy-ai-panel-label">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<path d="M12 3L13.7 9.1L19.8 11L13.7 12.9L12 19L10.3 12.9L4.2 11L10.3 9.1L12 3Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
						<path d="M19 3V6.5M17.3 4.75H20.7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
					</svg>
					<span><?php esc_html_e( 'AI Price Recommendation', 'loopbuy' ); ?></span>
				</div>
				<button type="button" class="loopbuy-ai-button" id="loopbuy-suggest-price">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<path d="M12 2.5V21.5M16.5 6H9.8C8.2 6 7 7.2 7 8.8C7 10.3 8.2 11.5 9.8 11.5H14.2C15.8 11.5 17 12.7 17 14.3C17 15.8 15.8 17 14.2 17H7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
					</svg>
					<?php esc_html_e( 'Suggest price', 'loopbuy' ); ?>
				</button>
			</div>

			<div class="loopbuy-sell-row">
				<div class="loopbuy-sell-field">
					<label for="loopbuy-sell-category"><?php esc_html_e( 'Category', 'loopbuy' ); ?></label>
					<select id="loopbuy-sell-category" name="loopbuy_category">
						<option value="" <?php selected( $loopbuy_v['category'], '' ); ?>><?php esc_html_e( 'Select&hellip;', 'loopbuy' ); ?></option>
						<option value="gaming" <?php selected( $loopbuy_v['category'], 'gaming' ); ?>><?php esc_html_e( 'Gaming', 'loopbuy' ); ?></option>
						<option value="fashion" <?php selected( $loopbuy_v['category'], 'fashion' ); ?>><?php esc_html_e( 'Fashion', 'loopbuy' ); ?></option>
						<option value="sports" <?php selected( $loopbuy_v['category'], 'sports' ); ?>><?php esc_html_e( 'Sports', 'loopbuy' ); ?></option>
						<option value="home-appliances" <?php selected( $loopbuy_v['category'], 'home-appliances' ); ?>><?php esc_html_e( 'Home Appliances', 'loopbuy' ); ?></option>
						<option value="electronics" <?php selected( $loopbuy_v['category'], 'electronics' ); ?>><?php esc_html_e( 'Electronics', 'loopbuy' ); ?></option>
						<option value="books" <?php selected( $loopbuy_v['category'], 'books' ); ?>><?php esc_html_e( 'Books', 'loopbuy' ); ?></option>
						<option value="furniture" <?php selected( $loopbuy_v['category'], 'furniture' ); ?>><?php esc_html_e( 'Furniture', 'loopbuy' ); ?></option>
						<option value="others" <?php selected( $loopbuy_v['category'], 'others' ); ?>><?php esc_html_e( 'Others', 'loopbuy' ); ?></option>
					</select>
				</div>
				<div class="loopbuy-sell-field">
					<label for="loopbuy-sell-condition"><?php esc_html_e( 'Condition', 'loopbuy' ); ?></label>
					<select id="loopbuy-sell-condition" name="loopbuy_condition">
						<option value="new" <?php selected( $loopbuy_v['condition'], 'new' ); ?>><?php esc_html_e( 'New', 'loopbuy' ); ?></option>
						<option value="like-new" <?php selected( $loopbuy_v['condition'], 'like-new' ); ?>><?php esc_html_e( 'Like New', 'loopbuy' ); ?></option>
						<option value="good" <?php selected( $loopbuy_v['condition'], 'good' ); ?>><?php esc_html_e( 'Good', 'loopbuy' ); ?></option>
						<option value="fair" <?php selected( $loopbuy_v['condition'], 'fair' ); ?>><?php esc_html_e( 'Fair', 'loopbuy' ); ?></option>
					</select>
				</div>
			</div>

			<div class="loopbuy-sell-field">
				<label for="loopbuy-sell-location"><?php esc_html_e( 'Location', 'loopbuy' ); ?></label>
				<input type="text" id="loopbuy-sell-location" name="loopbuy_location" value="<?php echo esc_attr( $loopbuy_v['location'] ); ?>" placeholder="<?php echo esc_attr_x( 'Singapore', 'sell form placeholder', 'loopbuy' ); ?>">
			</div>

			<div class="loopbuy-sell-field">
				<label for="loopbuy-sell-description"><?php esc_html_e( 'Description', 'loopbuy' ); ?></label>
				<textarea id="loopbuy-sell-description" name="loopbuy_description" rows="5" placeholder="<?php echo esc_attr_x( 'Describe your item&hellip;', 'sell form placeholder', 'loopbuy' ); ?>"><?php echo esc_textarea( $loopbuy_v['description'] ); ?></textarea>
			</div>

			<div class="loopbuy-ai-panel">
				<div class="loopbuy-ai-panel-label">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<path d="M12 3L19 6.2V11C19 15.3 16.1 19 12 20.5C7.9 19 5 15.3 5 11V6.2L12 3Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
						<path d="M9 11.6L11 13.6L15 9.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					<span><?php esc_html_e( 'AI Scam Detection', 'loopbuy' ); ?></span>
				</div>
				<button type="button" class="loopbuy-ai-button" id="loopbuy-scan-listing">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<path d="M12 3L19 6.2V11C19 15.3 16.1 19 12 20.5C7.9 19 5 15.3 5 11V6.2L12 3Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
						<path d="M9 11.6L11 13.6L15 9.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					<?php esc_html_e( 'Scan listing', 'loopbuy' ); ?>
				</button>
			</div>

			<button type="submit" name="loopbuy_sell_submit" class="loopbuy-sell-submit"><?php esc_html_e( 'Publish listing', 'loopbuy' ); ?></button>

		</form>

		<?php endif; ?>

	</div><!-- .loopbuy-sell -->
</main><!-- #primary -->

<?php
get_footer();