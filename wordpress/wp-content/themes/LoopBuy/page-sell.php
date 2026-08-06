<?php
/**
 * Marketplace listing creation and local image upload.
 *
 * Listings are created in the Go API, then images are uploaded server-to-server
 * through the WordPress BFF. Marketplace access tokens never enter HTML or JS.
 *
 * @package LoopBuy
 */

$loopbuy_sell_errors      = array();
$loopbuy_request_method   = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
$loopbuy_marketplace_user = function_exists( 'loopbuy_marketplace_current_user' )
	? loopbuy_marketplace_current_user()
	: new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'The marketplace account service is unavailable.', 'loopbuy' ) );
$loopbuy_sell_csrf        = is_array( $loopbuy_marketplace_user ) && function_exists( 'loopbuy_marketplace_csrf_token' )
	? loopbuy_marketplace_csrf_token()
	: null;
$loopbuy_categories       = function_exists( 'loopbuy_marketplace_listing_categories' )
	? loopbuy_marketplace_listing_categories()
	: new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'Listing categories are unavailable.', 'loopbuy' ) );
$loopbuy_image_limit_mb   = max( 1, min( 8, (int) floor( wp_max_upload_size() / MB_IN_BYTES ) ) );
$loopbuy_v                = array(
	'title'       => '',
	'price'       => '',
	'brand'       => '',
	'category_id' => '',
	'condition'   => 'good',
	'location'    => '',
	'description' => '',
);

/**
 * Normalize and pre-validate a bounded multi-file PHP upload.
 *
 * @param string $field_name Multi-file field name.
 * @return array|WP_Error
 */
if ( ! function_exists( 'loopbuy_sell_uploaded_images' ) ) {
	function loopbuy_sell_uploaded_images( $field_name ) {
		if ( empty( $_FILES[ $field_name ] ) || ! is_array( $_FILES[ $field_name ] ) ) {
			return array();
		}

		$files = $_FILES[ $field_name ];

		foreach ( array( 'name', 'type', 'tmp_name', 'error', 'size' ) as $key ) {
			if ( ! isset( $files[ $key ] ) || ! is_array( $files[ $key ] ) ) {
				return new WP_Error( 'loopbuy_sell_invalid_upload', __( 'The image upload request is malformed.', 'loopbuy' ) );
			}
		}

		$file_count = count( $files['name'] );

		if ( $file_count > 10 ) {
			return new WP_Error( 'loopbuy_sell_too_many_images', __( 'Upload no more than 10 images.', 'loopbuy' ) );
		}

		$uploads = array();

		for ( $index = 0; $index < $file_count; $index++ ) {
			if ( ! isset( $files['error'][ $index ] ) ) {
				return new WP_Error( 'loopbuy_sell_invalid_upload', __( 'The image upload request is malformed.', 'loopbuy' ) );
			}

			if ( UPLOAD_ERR_NO_FILE === (int) $files['error'][ $index ] ) {
				continue;
			}

			foreach ( array( 'name', 'type', 'tmp_name', 'size' ) as $key ) {
				if ( ! isset( $files[ $key ][ $index ] ) || is_array( $files[ $key ][ $index ] ) ) {
					return new WP_Error( 'loopbuy_sell_invalid_upload', __( 'The image upload request is malformed.', 'loopbuy' ) );
				}
			}

			$file = array(
				'name'     => $files['name'][ $index ],
				'type'     => $files['type'][ $index ],
				'tmp_name' => $files['tmp_name'][ $index ],
				'error'    => $files['error'][ $index ],
				'size'     => $files['size'][ $index ],
			);
			$checked = function_exists( 'loopbuy_marketplace_build_image_multipart' )
				? loopbuy_marketplace_build_image_multipart( $file, count( $uploads ), empty( $uploads ) )
				: new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'Marketplace image uploads are unavailable.', 'loopbuy' ) );

			if ( is_wp_error( $checked ) ) {
				return $checked;
			}

			unset( $checked );
			$uploads[] = $file;
		}

		return $uploads;
	}
}

// Process before get_header() so successful submission follows POST/Redirect/GET.
if ( 'POST' === $loopbuy_request_method ) {
	if ( empty( $_POST ) && ! empty( $_SERVER['CONTENT_LENGTH'] ) ) {
		$loopbuy_sell_errors[] = __( 'The upload is larger than this WordPress server accepts.', 'loopbuy' );
	} elseif ( ! is_array( $loopbuy_marketplace_user ) ) {
		$loopbuy_sell_errors[] = __( 'Please log in to post a listing.', 'loopbuy' );
	} else {
		$loopbuy_v['title']       = isset( $_POST['loopbuy_title'] ) && is_string( $_POST['loopbuy_title'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_title'] ) ) : '';
		$loopbuy_v['price']       = isset( $_POST['loopbuy_price'] ) && is_string( $_POST['loopbuy_price'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_price'] ) ) : '';
		$loopbuy_v['brand']       = isset( $_POST['loopbuy_brand'] ) && is_string( $_POST['loopbuy_brand'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_brand'] ) ) : '';
		$loopbuy_v['category_id'] = isset( $_POST['loopbuy_category'] ) && is_string( $_POST['loopbuy_category'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_category'] ) ) : '';
		$loopbuy_v['condition']   = isset( $_POST['loopbuy_condition'] ) && is_string( $_POST['loopbuy_condition'] ) ? sanitize_key( wp_unslash( $_POST['loopbuy_condition'] ) ) : 'good';
		$loopbuy_v['location']    = isset( $_POST['loopbuy_location'] ) && is_string( $_POST['loopbuy_location'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_location'] ) ) : '';
		$loopbuy_v['description'] = isset( $_POST['loopbuy_description'] ) && is_string( $_POST['loopbuy_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['loopbuy_description'] ) ) : '';

		if ( '' === $loopbuy_v['title'] ) {
			$loopbuy_sell_errors[] = __( 'Please enter a title for your listing.', 'loopbuy' );
		}

		if ( '' === $loopbuy_v['price'] || ! is_numeric( $loopbuy_v['price'] ) || (float) $loopbuy_v['price'] < 0 ) {
			$loopbuy_sell_errors[] = __( 'Please enter a valid price.', 'loopbuy' );
		}

		$loopbuy_uploads = loopbuy_sell_uploaded_images( 'loopbuy_photos' );

		if ( is_wp_error( $loopbuy_uploads ) ) {
			$loopbuy_sell_errors[] = $loopbuy_uploads->get_error_message();
		}

		if ( empty( $loopbuy_sell_errors ) ) {
			$submitted_csrf = isset( $_POST['loopbuy_marketplace_csrf'] ) ? $_POST['loopbuy_marketplace_csrf'] : '';
			$listing = loopbuy_marketplace_create_listing(
				array(
					'category_id'    => $loopbuy_v['category_id'],
					'title'          => $loopbuy_v['title'],
					'description'    => $loopbuy_v['description'],
					'brand'          => $loopbuy_v['brand'],
					'location'       => $loopbuy_v['location'],
					'price'          => $loopbuy_v['price'],
					'item_condition' => $loopbuy_v['condition'],
				),
				$submitted_csrf
			);

			if ( is_wp_error( $listing ) ) {
				$loopbuy_sell_errors[] = $listing->get_error_message();
			} else {
				$partial_upload = false;

				foreach ( $loopbuy_uploads as $index => $upload ) {
					$uploaded = loopbuy_marketplace_upload_listing_image( $listing['listing_id'], $upload, $index, 0 === $index );

					if ( is_wp_error( $uploaded ) ) {
						$partial_upload = true;
						break;
					}
				}

				$redirect_args = array( 'posted' => $listing['listing_id'] );

				if ( $partial_upload ) {
					$redirect_args['upload'] = 'partial';
				}

				wp_safe_redirect( add_query_arg( $redirect_args, home_url( '/my-listings/' ) ) );
				exit;
			}
		}
	}
}

if ( is_wp_error( $loopbuy_categories ) ) {
	$loopbuy_sell_errors[] = $loopbuy_categories->get_error_message();
}

if ( is_wp_error( $loopbuy_sell_csrf ) ) {
	$loopbuy_sell_errors[] = $loopbuy_sell_csrf->get_error_message();
}

get_header();
?>

<main id="primary" class="site-main">
	<div class="page loopbuy-sell">
		<div class="loopbuy-sell-header">
			<h1 class="loopbuy-sell-title"><?php esc_html_e( 'Post a listing', 'loopbuy' ); ?></h1>
			<p class="loopbuy-sell-subtitle"><?php esc_html_e( 'Images stay on the LoopBuy server, and scam screening runs before the listing becomes public.', 'loopbuy' ); ?></p>
		</div>

		<?php foreach ( array_unique( $loopbuy_sell_errors ) as $loopbuy_sell_error ) : ?>
			<p class="loopbuy-sell-status" data-state="error" role="alert"><?php echo esc_html( $loopbuy_sell_error ); ?></p>
		<?php endforeach; ?>

		<?php if ( ! is_array( $loopbuy_marketplace_user ) ) : ?>
			<div class="loopbuy-profile-login-notice">
				<p><?php echo esc_html( is_wp_error( $loopbuy_marketplace_user ) ? $loopbuy_marketplace_user->get_error_message() : __( 'You need to be logged in to post a listing.', 'loopbuy' ) ); ?></p>
				<a href="<?php echo esc_url( home_url( '/login/' ) ); ?>" class="auth-button"><?php esc_html_e( 'Log in', 'loopbuy' ); ?></a>
			</div>
		<?php else : ?>
			<form class="loopbuy-sell-form" method="post" action="<?php echo esc_url( home_url( '/sell/' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="loopbuy_sell_submit" value="1">
				<?php if ( is_string( $loopbuy_sell_csrf ) ) : ?>
					<input type="hidden" name="loopbuy_marketplace_csrf" value="<?php echo esc_attr( $loopbuy_sell_csrf ); ?>">
				<?php endif; ?>

				<div class="loopbuy-sell-field">
					<label for="loopbuy-sell-photos"><?php esc_html_e( 'Photos', 'loopbuy' ); ?></label>
					<label for="loopbuy-sell-photos" class="loopbuy-photo-upload" id="loopbuy-photo-dropzone">
						<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 15.5V4M12 4L7.5 8.5M12 4L16.5 8.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 15.5V17.5C4 18.6046 4.89543 19.5 6 19.5H18C19.1046 19.5 20 18.6046 20 17.5V15.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
						<span>
							<?php
							printf(
								/* translators: %d is the current per-file server limit in megabytes. */
								esc_html__( 'Choose up to 10 JPEG, PNG, WebP, or GIF images (up to %d MB each)', 'loopbuy' ),
								(int) $loopbuy_image_limit_mb
							);
							?>
						</span>
						<input type="file" id="loopbuy-sell-photos" name="loopbuy_photos[]" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" multiple hidden>
					</label>
				</div>

				<div class="loopbuy-sell-field">
					<label for="loopbuy-sell-title"><?php esc_html_e( 'Title', 'loopbuy' ); ?></label>
					<input type="text" id="loopbuy-sell-title" name="loopbuy_title" value="<?php echo esc_attr( $loopbuy_v['title'] ); ?>" maxlength="150" placeholder="<?php echo esc_attr_x( 'e.g. iPhone 13 Pro 128GB', 'sell form placeholder', 'loopbuy' ); ?>" required>
				</div>

				<div class="loopbuy-sell-row">
					<div class="loopbuy-sell-field">
						<label for="loopbuy-sell-price"><?php esc_html_e( 'Price (SGD)', 'loopbuy' ); ?></label>
						<input type="number" step="0.01" min="0" max="99999999.99" id="loopbuy-sell-price" name="loopbuy_price" value="<?php echo esc_attr( $loopbuy_v['price'] ); ?>" placeholder="0.00" required>
					</div>
					<div class="loopbuy-sell-field">
						<label for="loopbuy-sell-brand"><?php esc_html_e( 'Brand', 'loopbuy' ); ?></label>
						<input type="text" id="loopbuy-sell-brand" name="loopbuy_brand" value="<?php echo esc_attr( $loopbuy_v['brand'] ); ?>" maxlength="100" placeholder="<?php echo esc_attr_x( 'Apple', 'sell form placeholder', 'loopbuy' ); ?>">
					</div>
				</div>

				<div class="loopbuy-sell-row">
					<div class="loopbuy-sell-field">
						<label for="loopbuy-sell-category"><?php esc_html_e( 'Category', 'loopbuy' ); ?></label>
						<select id="loopbuy-sell-category" name="loopbuy_category" required <?php disabled( is_wp_error( $loopbuy_categories ) ); ?>>
							<option value=""><?php esc_html_e( 'Select…', 'loopbuy' ); ?></option>
							<?php if ( is_array( $loopbuy_categories ) ) : ?>
								<?php foreach ( $loopbuy_categories as $loopbuy_category ) : ?>
									<option value="<?php echo esc_attr( $loopbuy_category['category_id'] ); ?>" <?php selected( (string) $loopbuy_v['category_id'], (string) $loopbuy_category['category_id'] ); ?>><?php echo esc_html( $loopbuy_category['name'] ); ?></option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
					</div>
					<div class="loopbuy-sell-field">
						<label for="loopbuy-sell-condition"><?php esc_html_e( 'Condition', 'loopbuy' ); ?></label>
						<select id="loopbuy-sell-condition" name="loopbuy_condition" required>
							<option value="new" <?php selected( $loopbuy_v['condition'], 'new' ); ?>><?php esc_html_e( 'New', 'loopbuy' ); ?></option>
							<option value="like-new" <?php selected( $loopbuy_v['condition'], 'like-new' ); ?>><?php esc_html_e( 'Like New', 'loopbuy' ); ?></option>
							<option value="good" <?php selected( $loopbuy_v['condition'], 'good' ); ?>><?php esc_html_e( 'Good', 'loopbuy' ); ?></option>
							<option value="fair" <?php selected( $loopbuy_v['condition'], 'fair' ); ?>><?php esc_html_e( 'Fair', 'loopbuy' ); ?></option>
						</select>
					</div>
				</div>

				<div class="loopbuy-sell-field">
					<label for="loopbuy-sell-location"><?php esc_html_e( 'Location', 'loopbuy' ); ?></label>
					<input type="text" id="loopbuy-sell-location" name="loopbuy_location" value="<?php echo esc_attr( $loopbuy_v['location'] ); ?>" maxlength="120" placeholder="<?php echo esc_attr_x( 'Singapore', 'sell form placeholder', 'loopbuy' ); ?>">
				</div>

				<div class="loopbuy-sell-field">
					<label for="loopbuy-sell-description"><?php esc_html_e( 'Description', 'loopbuy' ); ?></label>
					<textarea id="loopbuy-sell-description" name="loopbuy_description" rows="5" maxlength="10000" placeholder="<?php echo esc_attr_x( 'Describe your item…', 'sell form placeholder', 'loopbuy' ); ?>"><?php echo esc_textarea( $loopbuy_v['description'] ); ?></textarea>
				</div>

				<div class="loopbuy-ai-panel">
					<div class="loopbuy-ai-panel-label">
						<span aria-hidden="true">&#10024;</span>
						<span><?php esc_html_e( 'Automatic scam screening runs server-side when you submit.', 'loopbuy' ); ?></span>
					</div>
				</div>

				<button type="submit" class="loopbuy-sell-submit" <?php disabled( ! is_string( $loopbuy_sell_csrf ) || is_wp_error( $loopbuy_categories ) ); ?>><?php esc_html_e( 'Submit listing for review', 'loopbuy' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
</main>

<?php
get_footer();
