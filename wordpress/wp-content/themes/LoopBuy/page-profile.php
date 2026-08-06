<?php
/**
 * Template Name: Profile
 *
 * Custom page template for the "Profile" page. WordPress will also
 * auto-select this file for any page with the slug "profile"
 * (page-profile.php), or it can be assigned manually via the
 * Page Attributes panel.
 *
 * @package LoopBuy
 */

$loopbuy_profile_sent = false;
$loopbuy_avatar_sent  = false;
$loopbuy_profile_errs = array();
$request_method       = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
$loopbuy_user         = function_exists( 'loopbuy_marketplace_current_user' )
	? loopbuy_marketplace_current_user()
	: new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'The marketplace account service is unavailable.', 'loopbuy' ) );
$loopbuy_profile_csrf = is_array( $loopbuy_user ) && function_exists( 'loopbuy_marketplace_csrf_token' )
	? loopbuy_marketplace_csrf_token()
	: null;

if ( isset( $_GET['loopbuy_auth_error'] ) && is_string( $_GET['loopbuy_auth_error'] ) ) {
	$loopbuy_logout_error = sanitize_key( wp_unslash( $_GET['loopbuy_auth_error'] ) );

	if ( 'logout_remote' === $loopbuy_logout_error ) {
		$loopbuy_profile_errs[] = __( 'You were signed out from this browser, but the server could not confirm refresh-token revocation. A copied token could remain valid until it expires.', 'loopbuy' );
	} elseif ( 'logout_local' === $loopbuy_logout_error ) {
		$loopbuy_profile_errs[] = __( 'This browser session could not be cleared completely. Close the browser before trying again.', 'loopbuy' );
	} elseif ( 'logout' === $loopbuy_logout_error ) {
		$loopbuy_profile_errs[] = __( 'The logout request could not be verified, so the existing browser session was kept.', 'loopbuy' );
	}
}

if ( 'POST' === $request_method && is_array( $loopbuy_user ) ) {
	$submitted_csrf = isset( $_POST['loopbuy_marketplace_csrf'] )
		? $_POST['loopbuy_marketplace_csrf']
		: '';

	if ( isset( $_POST['loopbuy_avatar_submit'] ) ) {
		if ( ! isset( $_FILES['loopbuy_avatar'] ) || ! is_array( $_FILES['loopbuy_avatar'] ) || empty( $_FILES['loopbuy_avatar']['name'] ) ) {
			$loopbuy_profile_errs[] = __( 'Choose a profile photo to upload.', 'loopbuy' );
		} elseif ( function_exists( 'loopbuy_marketplace_upload_avatar' ) ) {
			$loopbuy_avatar_update = loopbuy_marketplace_upload_avatar( $_FILES['loopbuy_avatar'], $submitted_csrf );

			if ( is_wp_error( $loopbuy_avatar_update ) ) {
				$loopbuy_profile_errs[] = $loopbuy_avatar_update->get_error_message();
			} else {
				$loopbuy_user        = $loopbuy_avatar_update;
				$loopbuy_avatar_sent = true;
			}
		} else {
			$loopbuy_profile_errs[] = __( 'The marketplace profile photo service is unavailable.', 'loopbuy' );
		}
	} elseif ( isset( $_POST['loopbuy_profile_submit'] ) ) {
		$loopbuy_profile_fields = array(
			'full_name' => isset( $_POST['loopbuy_full_name'] ) && is_string( $_POST['loopbuy_full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_full_name'] ) ) : '',
			'phone'     => isset( $_POST['loopbuy_phone'] ) && is_string( $_POST['loopbuy_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_phone'] ) ) : '',
			'location'  => isset( $_POST['loopbuy_location'] ) && is_string( $_POST['loopbuy_location'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_location'] ) ) : '',
			'bio'       => isset( $_POST['loopbuy_bio'] ) && is_string( $_POST['loopbuy_bio'] ) ? sanitize_textarea_field( wp_unslash( $_POST['loopbuy_bio'] ) ) : '',
		);

		if ( function_exists( 'loopbuy_marketplace_update_profile' ) ) {
			$loopbuy_profile_update = loopbuy_marketplace_update_profile( $loopbuy_profile_fields, $submitted_csrf );

			if ( is_wp_error( $loopbuy_profile_update ) ) {
				$loopbuy_profile_errs[] = $loopbuy_profile_update->get_error_message();
			} else {
				$loopbuy_user         = $loopbuy_profile_update;
				$loopbuy_profile_sent = true;
			}
		} else {
			$loopbuy_profile_errs[] = __( 'The marketplace profile service is unavailable.', 'loopbuy' );
		}
	} else {
		$loopbuy_profile_errs[] = __( 'The profile action is invalid. Reload the page and try again.', 'loopbuy' );
	}
}

if ( is_wp_error( $loopbuy_profile_csrf ) ) {
	$loopbuy_profile_errs[] = $loopbuy_profile_csrf->get_error_message();
}

get_header();

if ( is_wp_error( $loopbuy_user ) ) :
	?>
	<main id="primary" class="site-main">
		<div class="page loopbuy-profile">
			<div class="loopbuy-profile-wrap">
				<div class="loopbuy-profile-login-notice">
					<p><?php echo esc_html( $loopbuy_user->get_error_message() ); ?></p>
					<a href="<?php echo esc_url( home_url( '/profile/' ) ); ?>" class="auth-button"><?php esc_html_e( 'Try again', 'loopbuy' ); ?></a>
				</div>
			</div>
		</div>
	</main>
	<?php
	get_footer();
	return;
endif;

if ( ! is_array( $loopbuy_user ) ) :
	?>
	<main id="primary" class="site-main">
		<div class="page loopbuy-profile">
			<div class="loopbuy-profile-wrap">
				<div class="loopbuy-profile-login-notice">
					<p><?php esc_html_e( "You need to be logged in to view your profile.", 'loopbuy' ); ?></p>
					<a href="<?php echo esc_url( home_url( '/login/' ) ); ?>" class="auth-button"><?php esc_html_e( 'Log in', 'loopbuy' ); ?></a>
				</div>
			</div>
		</div>
	</main>
	<?php
	get_footer();
	return;
endif;

$loopbuy_profile        = isset( $loopbuy_user['profile'] ) && is_array( $loopbuy_user['profile'] ) ? $loopbuy_user['profile'] : array();
$loopbuy_display_name   = ! empty( $loopbuy_profile['full_name'] ) ? $loopbuy_profile['full_name'] : $loopbuy_user['username'];
$loopbuy_user_email     = $loopbuy_user['email'];
$loopbuy_phone_value    = isset( $loopbuy_profile['phone'] ) ? $loopbuy_profile['phone'] : '';
$loopbuy_location_value = isset( $loopbuy_profile['location'] ) ? $loopbuy_profile['location'] : '';
$loopbuy_bio_value      = isset( $loopbuy_profile['bio'] ) ? $loopbuy_profile['bio'] : '';
$loopbuy_avatar_url     = isset( $loopbuy_profile['profile_image'] ) ? $loopbuy_profile['profile_image'] : '';
$loopbuy_initial        = strtoupper(
	function_exists( 'mb_substr' )
		? mb_substr( $loopbuy_display_name, 0, 1, 'UTF-8' )
		: substr( $loopbuy_display_name, 0, 1 )
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
						<?php esc_html_e( 'Marketplace account', 'loopbuy' ); ?>
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
						<?php if ( $loopbuy_avatar_sent ) : ?>
							<p class="loopbuy-profile-status" data-state="success"><?php esc_html_e( 'Your profile photo has been updated.', 'loopbuy' ); ?></p>
						<?php endif; ?>

						<?php foreach ( $loopbuy_profile_errs as $loopbuy_profile_err ) : ?>
							<p class="loopbuy-profile-status" data-state="error"><?php echo esc_html( $loopbuy_profile_err ); ?></p>
						<?php endforeach; ?>

						<form method="post" action="" enctype="multipart/form-data" class="loopbuy-profile-avatar-form">
							<?php if ( is_string( $loopbuy_profile_csrf ) ) : ?>
								<input type="hidden" name="loopbuy_marketplace_csrf" value="<?php echo esc_attr( $loopbuy_profile_csrf ); ?>">
							<?php endif; ?>

							<div class="loopbuy-profile-photo-row">
								<span class="loopbuy-profile-photo-avatar" aria-hidden="true">
									<?php if ( $loopbuy_avatar_url ) : ?>
										<img src="<?php echo esc_url( $loopbuy_avatar_url ); ?>" alt="">
									<?php else : ?>
										<?php echo esc_html( $loopbuy_initial ); ?>
									<?php endif; ?>
								</span>
								<label class="loopbuy-profile-photo-button" for="loopbuy-avatar">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M6 10l6-6 6 6"/><path d="M4 20h16"/></svg>
									<?php esc_html_e( 'Choose photo', 'loopbuy' ); ?>
								</label>
								<input class="loopbuy-profile-photo-input" type="file" id="loopbuy-avatar" name="loopbuy_avatar" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
								<button
									type="submit"
									name="loopbuy_avatar_submit"
									class="loopbuy-profile-photo-button"
									<?php disabled( is_wp_error( $loopbuy_profile_csrf ) || ! is_string( $loopbuy_profile_csrf ) ); ?>
								>
									<?php esc_html_e( 'Upload', 'loopbuy' ); ?>
								</button>
							</div>
							<p class="loopbuy-profile-photo-help"><?php esc_html_e( 'JPEG, PNG, or WebP. Maximum 2 MB, 4096 pixels per side, and 16 megapixels total.', 'loopbuy' ); ?></p>
						</form>

						<form method="post" action="">
							<?php if ( is_string( $loopbuy_profile_csrf ) ) : ?>
								<input type="hidden" name="loopbuy_marketplace_csrf" value="<?php echo esc_attr( $loopbuy_profile_csrf ); ?>">
							<?php endif; ?>

							<div class="loopbuy-profile-field">
								<label for="loopbuy-full-name"><?php esc_html_e( 'Full Name', 'loopbuy' ); ?></label>
								<input type="text" id="loopbuy-full-name" name="loopbuy_full_name" value="<?php echo esc_attr( $loopbuy_display_name ); ?>" maxlength="100" required>
							</div>

							<div class="loopbuy-profile-field">
								<label for="loopbuy-phone"><?php esc_html_e( 'Phone', 'loopbuy' ); ?></label>
								<input type="tel" id="loopbuy-phone" name="loopbuy_phone" value="<?php echo esc_attr( $loopbuy_phone_value ); ?>" maxlength="32">
							</div>

							<div class="loopbuy-profile-field">
								<label for="loopbuy-location"><?php esc_html_e( 'Location', 'loopbuy' ); ?></label>
								<input type="text" id="loopbuy-location" name="loopbuy_location" value="<?php echo esc_attr( $loopbuy_location_value ); ?>" maxlength="120">
							</div>

							<div class="loopbuy-profile-field">
								<label for="loopbuy-bio"><?php esc_html_e( 'Bio', 'loopbuy' ); ?></label>
								<textarea id="loopbuy-bio" name="loopbuy_bio" maxlength="2000"><?php echo esc_textarea( $loopbuy_bio_value ); ?></textarea>
							</div>

							<button
								type="submit"
								name="loopbuy_profile_submit"
								class="loopbuy-profile-save"
								<?php disabled( is_wp_error( $loopbuy_profile_csrf ) || ! is_string( $loopbuy_profile_csrf ) ); ?>
							>
								<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/></svg>
								<?php esc_html_e( 'Save changes', 'loopbuy' ); ?>
							</button>
						</form>
					</div><!-- .loopbuy-profile-card (edit) -->

					<div class="loopbuy-profile-card">
						<h2 class="loopbuy-profile-card-title"><?php esc_html_e( 'Seller Reviews', 'loopbuy' ); ?></h2>
						<p class="loopbuy-profile-empty"><?php esc_html_e( 'Seller reviews are not connected to marketplace accounts yet.', 'loopbuy' ); ?></p>
					</div><!-- .loopbuy-profile-card (reviews) -->

				</div><!-- .loopbuy-profile-col-left -->

				<div class="loopbuy-profile-col-right">
					<div class="loopbuy-profile-card">
						<div class="loopbuy-profile-card-header-row">
							<h2 class="loopbuy-profile-card-title"><?php esc_html_e( 'My Listings', 'loopbuy' ); ?></h2>
							<a class="loopbuy-profile-new-link" href="<?php echo esc_url( home_url( '/sell/' ) ); ?>">+ <?php esc_html_e( 'Sell', 'loopbuy' ); ?></a>
						</div>

						<p class="loopbuy-profile-empty"><?php esc_html_e( 'Manage active, pending, rejected, sold, and archived listings from one place.', 'loopbuy' ); ?></p>
						<p><a class="loopbuy-profile-save" href="<?php echo esc_url( home_url( '/my-listings/' ) ); ?>"><?php esc_html_e( 'Open My Listings', 'loopbuy' ); ?></a></p>
					</div><!-- .loopbuy-profile-card (listings) -->
				</div><!-- .loopbuy-profile-col-right -->

			</div><!-- .loopbuy-profile-grid -->

		</div><!-- .loopbuy-profile-wrap -->
	</div><!-- .page.loopbuy-profile -->

</main><!-- #primary -->

<?php
get_footer();
