<?php
/**
 * Template Name: Profile
 *
 * Custom page template for the "Profile" page. WordPress will also
 * auto-select this file for any page with the slug "profile"
 * (page-profile.php), or it can be assigned manually via the
 * Page Attributes panel.
 *
 * NOTE: "My Listings" and the listings count query the 'loopbuy_listing'
 * post type (see inc-loopbuy-listing-cpt.php), matching the Sell and My
 * Listings pages. If your listings use a different post type, override it
 * via functions.php:
 *   add_filter( 'loopbuy_listing_post_type', fn() => 'your_post_type' );
 *
 * @package LoopBuy
 */

get_header();

$loopbuy_listing_post_type = apply_filters( 'loopbuy_listing_post_type', 'loopbuy_listing' );

if ( ! is_user_logged_in() ) :
	?>
	<main id="primary" class="site-main">
		<div class="page loopbuy-profile">
			<div class="loopbuy-profile-wrap">
				<div class="loopbuy-profile-login-notice">
					<p><?php esc_html_e( "You need to be logged in to view your profile.", 'loopbuy' ); ?></p>
					<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="auth-button"><?php esc_html_e( 'Log in', 'loopbuy' ); ?></a>
				</div>
			</div>
		</div>
	</main>
	<?php
	get_footer();
	return;
endif;

$loopbuy_user_id      = get_current_user_id();
$loopbuy_profile_sent = false;
$loopbuy_profile_errs = array();

// Handle form submission.
if ( isset( $_POST['loopbuy_profile_submit'] ) ) {

	if ( ! isset( $_POST['loopbuy_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['loopbuy_profile_nonce'] ) ), 'loopbuy_profile_form' ) ) {
		$loopbuy_profile_errs[] = __( 'Your session expired. Please try again.', 'loopbuy' );
	} else {

		// Avatar upload (optional).
		if ( ! empty( $_FILES['loopbuy_avatar']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$loopbuy_attachment_id = media_handle_upload( 'loopbuy_avatar', 0 );

			if ( is_wp_error( $loopbuy_attachment_id ) ) {
				$loopbuy_profile_errs[] = $loopbuy_attachment_id->get_error_message();
			} else {
				update_user_meta( $loopbuy_user_id, 'loopbuy_avatar_id', $loopbuy_attachment_id );
			}
		}

		$loopbuy_full_name = isset( $_POST['loopbuy_full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_full_name'] ) ) : '';
		$loopbuy_phone      = isset( $_POST['loopbuy_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_phone'] ) ) : '';
		$loopbuy_location   = isset( $_POST['loopbuy_location'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_location'] ) ) : '';
		$loopbuy_bio        = isset( $_POST['loopbuy_bio'] ) ? sanitize_textarea_field( wp_unslash( $_POST['loopbuy_bio'] ) ) : '';

		if ( empty( $loopbuy_full_name ) ) {
			$loopbuy_profile_errs[] = __( 'Please enter your full name.', 'loopbuy' );
		}

		if ( empty( $loopbuy_profile_errs ) ) {
			$loopbuy_update = wp_update_user(
				array(
					'ID'           => $loopbuy_user_id,
					'display_name' => $loopbuy_full_name,
					'description'  => $loopbuy_bio,
				)
			);

			if ( is_wp_error( $loopbuy_update ) ) {
				$loopbuy_profile_errs[] = $loopbuy_update->get_error_message();
			} else {
				update_user_meta( $loopbuy_user_id, 'loopbuy_phone', $loopbuy_phone );
				update_user_meta( $loopbuy_user_id, 'loopbuy_location', $loopbuy_location );
				$loopbuy_profile_sent = true;
			}
		}
	}
}

// Current values (post-save, these reflect what was just submitted).
$loopbuy_user          = get_userdata( $loopbuy_user_id );
$loopbuy_display_name  = $loopbuy_user->display_name;
$loopbuy_user_email    = $loopbuy_user->user_email;
$loopbuy_phone_value   = get_user_meta( $loopbuy_user_id, 'loopbuy_phone', true );
$loopbuy_location_value = get_user_meta( $loopbuy_user_id, 'loopbuy_location', true );
$loopbuy_bio_value     = get_the_author_meta( 'description', $loopbuy_user_id );
$loopbuy_avatar_id     = get_user_meta( $loopbuy_user_id, 'loopbuy_avatar_id', true );
$loopbuy_avatar_url    = $loopbuy_avatar_id ? wp_get_attachment_image_url( $loopbuy_avatar_id, 'thumbnail' ) : '';
$loopbuy_initial       = strtoupper( substr( $loopbuy_display_name ? $loopbuy_display_name : $loopbuy_user->user_login, 0, 1 ) );

$loopbuy_listings_count = count_user_posts( $loopbuy_user_id, $loopbuy_listing_post_type, true );

$loopbuy_listings_query = new WP_Query(
	array(
		'post_type'      => $loopbuy_listing_post_type,
		'author'         => $loopbuy_user_id,
		'posts_per_page' => 20,
		'post_status'    => 'publish',
		'orderby'        => 'date',
		'order'          => 'DESC',
	)
);
?>

<main id="primary" class="site-main">

	<div class="page loopbuy-profile">
		<div class="loopbuy-profile-wrap">

			<div class="loopbuy-profile-summary">
				<span class="loopbuy-profile-avatar" aria-hidden="true">
					<?php if ( $loopbuy_avatar_url ) : ?>
						<img src="<?php echo esc_url( $loopbuy_avatar_url ); ?>" alt="">
					<?php else : ?>
						<?php echo esc_html( $loopbuy_initial ); ?>
					<?php endif; ?>
				</span>
				<div>
					<p class="loopbuy-profile-summary-name"><?php echo esc_html( $loopbuy_display_name ); ?></p>
					<p class="loopbuy-profile-summary-email"><?php echo esc_html( $loopbuy_user_email ); ?></p>
					<span class="loopbuy-profile-summary-meta">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>
						<?php
						printf(
							/* translators: %s: number of listings. */
							esc_html( _n( '%s listing', '%s listings', $loopbuy_listings_count, 'loopbuy' ) ),
							esc_html( number_format_i18n( $loopbuy_listings_count ) )
						);
						?>
					</span>
				</div>
			</div><!-- .loopbuy-profile-summary -->

			<div class="loopbuy-profile-grid">

				<div class="loopbuy-profile-col-left">

					<div class="loopbuy-profile-card">
						<h2 class="loopbuy-profile-card-title"><?php esc_html_e( 'Edit Profile', 'loopbuy' ); ?></h2>

						<?php if ( $loopbuy_profile_sent ) : ?>
							<p class="loopbuy-profile-status" data-state="success"><?php esc_html_e( 'Your profile has been updated.', 'loopbuy' ); ?></p>
						<?php endif; ?>

						<?php foreach ( $loopbuy_profile_errs as $loopbuy_profile_err ) : ?>
							<p class="loopbuy-profile-status" data-state="error"><?php echo esc_html( $loopbuy_profile_err ); ?></p>
						<?php endforeach; ?>

						<form method="post" action="" enctype="multipart/form-data">
							<?php wp_nonce_field( 'loopbuy_profile_form', 'loopbuy_profile_nonce' ); ?>

							<div class="loopbuy-profile-photo-row">
								<span class="loopbuy-profile-photo-avatar" aria-hidden="true">
									<?php if ( $loopbuy_avatar_url ) : ?>
										<img src="<?php echo esc_url( $loopbuy_avatar_url ); ?>" alt="">
									<?php else : ?>
										<?php echo esc_html( $loopbuy_initial ); ?>
									<?php endif; ?>
								</span>
								<label class="loopbuy-profile-photo-button">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M6 10l6-6 6 6"/><path d="M4 20h16"/></svg>
									<?php esc_html_e( 'Change photo', 'loopbuy' ); ?>
									<input type="file" name="loopbuy_avatar" accept="image/*" class="loopbuy-profile-photo-input">
								</label>
							</div>

							<div class="loopbuy-profile-field">
								<label for="loopbuy-full-name"><?php esc_html_e( 'Full Name', 'loopbuy' ); ?></label>
								<input type="text" id="loopbuy-full-name" name="loopbuy_full_name" value="<?php echo esc_attr( $loopbuy_display_name ); ?>" required>
							</div>

							<div class="loopbuy-profile-field">
								<label for="loopbuy-phone"><?php esc_html_e( 'Phone', 'loopbuy' ); ?></label>
								<input type="tel" id="loopbuy-phone" name="loopbuy_phone" value="<?php echo esc_attr( $loopbuy_phone_value ); ?>">
							</div>

							<div class="loopbuy-profile-field">
								<label for="loopbuy-location"><?php esc_html_e( 'Location', 'loopbuy' ); ?></label>
								<input type="text" id="loopbuy-location" name="loopbuy_location" value="<?php echo esc_attr( $loopbuy_location_value ); ?>">
							</div>

							<div class="loopbuy-profile-field">
								<label for="loopbuy-bio"><?php esc_html_e( 'Bio', 'loopbuy' ); ?></label>
								<textarea id="loopbuy-bio" name="loopbuy_bio"><?php echo esc_textarea( $loopbuy_bio_value ); ?></textarea>
							</div>

							<button type="submit" name="loopbuy_profile_submit" class="loopbuy-profile-save">
								<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/></svg>
								<?php esc_html_e( 'Save changes', 'loopbuy' ); ?>
							</button>
						</form>
					</div><!-- .loopbuy-profile-card (edit) -->

					<div class="loopbuy-profile-card">
						<h2 class="loopbuy-profile-card-title"><?php esc_html_e( 'Seller Reviews', 'loopbuy' ); ?></h2>
						<p class="loopbuy-profile-empty"><?php esc_html_e( 'No reviews yet.', 'loopbuy' ); ?></p>
					</div><!-- .loopbuy-profile-card (reviews) -->

				</div><!-- .loopbuy-profile-col-left -->

				<div class="loopbuy-profile-col-right">
					<div class="loopbuy-profile-card">
						<div class="loopbuy-profile-card-header-row">
							<h2 class="loopbuy-profile-card-title"><?php esc_html_e( 'My Listings', 'loopbuy' ); ?></h2>
							<a href="<?php echo esc_url( home_url( '/sell/' ) ); ?>" class="loopbuy-profile-new-link">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5V19M5 12H19"/></svg>
								<?php esc_html_e( 'New', 'loopbuy' ); ?>
							</a>
						</div>

						<?php if ( $loopbuy_listings_query->have_posts() ) : ?>
							<ul class="loopbuy-profile-listings">
								<?php
								while ( $loopbuy_listings_query->have_posts() ) :
									$loopbuy_listings_query->the_post();

									$loopbuy_listing_id     = get_the_ID();
									$loopbuy_listing_price  = get_post_meta( $loopbuy_listing_id, '_loopbuy_price', true );
									$loopbuy_listing_status = get_post_meta( $loopbuy_listing_id, '_loopbuy_status', true );
									$loopbuy_listing_status = $loopbuy_listing_status ? $loopbuy_listing_status : 'active';
									?>
									<li class="loopbuy-profile-listing-item">
										<a href="<?php the_permalink(); ?>" class="loopbuy-profile-listing-link">
											<span class="loopbuy-profile-listing-image">
												<?php if ( has_post_thumbnail() ) : ?>
													<?php the_post_thumbnail( 'thumbnail' ); ?>
												<?php else : ?>
													<span class="loopbuy-profile-listing-image-placeholder" aria-hidden="true"></span>
												<?php endif; ?>
											</span>
											<span class="loopbuy-profile-listing-info">
												<span class="loopbuy-profile-listing-title"><?php the_title(); ?></span>
												<?php if ( '' !== $loopbuy_listing_price ) : ?>
													<span class="loopbuy-profile-listing-price">$<?php echo esc_html( number_format_i18n( (float) $loopbuy_listing_price ) ); ?></span>
												<?php endif; ?>
												<span class="loopbuy-profile-listing-status status-<?php echo esc_attr( $loopbuy_listing_status ); ?>">
													<?php echo 'sold' === $loopbuy_listing_status ? esc_html__( 'Sold', 'loopbuy' ) : esc_html__( 'Active', 'loopbuy' ); ?>
												</span>
											</span>
										</a>
									</li>
								<?php endwhile; ?>
							</ul>
							<?php wp_reset_postdata(); ?>
						<?php else : ?>
							<p class="loopbuy-profile-empty"><?php esc_html_e( 'No listings yet.', 'loopbuy' ); ?></p>
						<?php endif; ?>
					</div><!-- .loopbuy-profile-card (listings) -->
				</div><!-- .loopbuy-profile-col-right -->

			</div><!-- .loopbuy-profile-grid -->

		</div><!-- .loopbuy-profile-wrap -->
	</div><!-- .page.loopbuy-profile -->

</main><!-- #primary -->

<?php
get_footer();