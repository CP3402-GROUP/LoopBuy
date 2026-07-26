<?php
/**
 * The template for displaying the "Post a listing" (Sell) page.
 *
 * WordPress automatically uses this file for a Page whose slug is "sell"
 * (template hierarchy: page-sell.php) — just create a Page titled "Sell"
 * with the slug "sell" in wp-admin and it will pick this up automatically,
 * no need to manually assign a template.
 *
 * @package LoopBuy
 */

get_header();
?>

<main id="primary" class="site-main">
	<div class="page loopbuy-sell">

		<div class="loopbuy-sell-header">
			<h1 class="loopbuy-sell-title"><?php esc_html_e( 'Post a listing', 'loopbuy' ); ?></h1>
			<p class="loopbuy-sell-subtitle"><?php esc_html_e( 'Our AI screens listings for scams before they go live.', 'loopbuy' ); ?></p>
		</div>

		<form class="loopbuy-sell-form" method="post" enctype="multipart/form-data">

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
				<input type="text" id="loopbuy-sell-title" name="loopbuy_title" placeholder="<?php echo esc_attr_x( 'e.g. iPhone 13 Pro 128GB', 'sell form placeholder', 'loopbuy' ); ?>">
			</div>

			<div class="loopbuy-sell-row">
				<div class="loopbuy-sell-field">
					<label for="loopbuy-sell-price"><?php esc_html_e( 'Price ($)', 'loopbuy' ); ?></label>
					<input type="number" step="0.01" min="0" id="loopbuy-sell-price" name="loopbuy_price" placeholder="0.00">
				</div>
				<div class="loopbuy-sell-field">
					<label for="loopbuy-sell-brand"><?php esc_html_e( 'Brand', 'loopbuy' ); ?></label>
					<input type="text" id="loopbuy-sell-brand" name="loopbuy_brand" placeholder="<?php echo esc_attr_x( 'Apple', 'sell form placeholder', 'loopbuy' ); ?>">
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
						<option value=""><?php esc_html_e( 'Select&hellip;', 'loopbuy' ); ?></option>
						<option value="gaming"><?php esc_html_e( 'Gaming', 'loopbuy' ); ?></option>
						<option value="fashion"><?php esc_html_e( 'Fashion', 'loopbuy' ); ?></option>
						<option value="sports"><?php esc_html_e( 'Sports', 'loopbuy' ); ?></option>
						<option value="home-appliances"><?php esc_html_e( 'Home Appliances', 'loopbuy' ); ?></option>
						<option value="electronics"><?php esc_html_e( 'Electronics', 'loopbuy' ); ?></option>
						<option value="books"><?php esc_html_e( 'Books', 'loopbuy' ); ?></option>
						<option value="furniture"><?php esc_html_e( 'Furniture', 'loopbuy' ); ?></option>
						<option value="others"><?php esc_html_e( 'Others', 'loopbuy' ); ?></option>
					</select>
				</div>
				<div class="loopbuy-sell-field">
					<label for="loopbuy-sell-condition"><?php esc_html_e( 'Condition', 'loopbuy' ); ?></label>
					<select id="loopbuy-sell-condition" name="loopbuy_condition">
						<option value="new"><?php esc_html_e( 'New', 'loopbuy' ); ?></option>
						<option value="like-new"><?php esc_html_e( 'Like New', 'loopbuy' ); ?></option>
						<option value="good" selected="selected"><?php esc_html_e( 'Good', 'loopbuy' ); ?></option>
						<option value="fair"><?php esc_html_e( 'Fair', 'loopbuy' ); ?></option>
					</select>
				</div>
			</div>

			<div class="loopbuy-sell-field">
				<label for="loopbuy-sell-location"><?php esc_html_e( 'Location', 'loopbuy' ); ?></label>
				<input type="text" id="loopbuy-sell-location" name="loopbuy_location" placeholder="<?php echo esc_attr_x( 'Singapore', 'sell form placeholder', 'loopbuy' ); ?>">
			</div>

			<div class="loopbuy-sell-field">
				<label for="loopbuy-sell-description"><?php esc_html_e( 'Description', 'loopbuy' ); ?></label>
				<textarea id="loopbuy-sell-description" name="loopbuy_description" rows="5" placeholder="<?php echo esc_attr_x( 'Describe your item&hellip;', 'sell form placeholder', 'loopbuy' ); ?>"></textarea>
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

			<button type="submit" class="loopbuy-sell-submit"><?php esc_html_e( 'Publish listing', 'loopbuy' ); ?></button>

		</form>

	</div><!-- .loopbuy-sell -->
</main><!-- #primary -->

<?php
get_footer();