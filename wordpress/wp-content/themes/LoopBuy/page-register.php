<?php
/**
 * Template for marketplace registration.
 *
 * @package LoopBuy
 */

$error_message       = '';
$success_message     = '';
$pending_email       = '';
$verification_needed = false;
$request_method      = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
$current_user        = function_exists( 'loopbuy_marketplace_current_user' )
	? loopbuy_marketplace_current_user()
	: new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'Marketplace registration is temporarily unavailable.', 'loopbuy' ) );

if ( is_array( $current_user ) ) {
	wp_safe_redirect( home_url( '/profile/' ) );
	exit;
}

$csrf_token = function_exists( 'loopbuy_marketplace_csrf_token' )
	? loopbuy_marketplace_csrf_token()
	: new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'Marketplace registration is temporarily unavailable.', 'loopbuy' ) );

if ( 'POST' === $request_method ) {
	$form_action = isset( $_POST['loopbuy_auth_form'] ) && is_string( $_POST['loopbuy_auth_form'] )
		? sanitize_key( wp_unslash( $_POST['loopbuy_auth_form'] ) )
		: 'register';
	$submitted_csrf = isset( $_POST['loopbuy_marketplace_csrf'] )
		? $_POST['loopbuy_marketplace_csrf']
		: '';

	if ( 'email_resend' === $form_action ) {
		$pending_email = isset( $_POST['email'] ) && is_string( $_POST['email'] )
			? sanitize_email( wp_unslash( $_POST['email'] ) )
			: '';
		$result = function_exists( 'loopbuy_marketplace_resend_verification' )
			? loopbuy_marketplace_resend_verification( $pending_email, $submitted_csrf )
			: new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'Email verification is temporarily unavailable.', 'loopbuy' ) );

		if ( is_wp_error( $result ) ) {
			$error_message = $result->get_error_message();
		} else {
			$success_message     = __( 'If that account needs verification, a fresh email has been sent.', 'loopbuy' );
			$verification_needed = true;
		}
	} else {
		$username = isset( $_POST['username'] ) && is_string( $_POST['username'] )
			? sanitize_text_field( wp_unslash( $_POST['username'] ) )
			: '';
		$email = isset( $_POST['email'] ) && is_string( $_POST['email'] )
			? sanitize_email( wp_unslash( $_POST['email'] ) )
			: '';
		$password = isset( $_POST['password'] ) && is_string( $_POST['password'] )
			? (string) wp_unslash( $_POST['password'] )
			: '';
		$confirm_password = isset( $_POST['confirm_password'] ) && is_string( $_POST['confirm_password'] )
			? (string) wp_unslash( $_POST['confirm_password'] )
			: '';

		if ( '' === $username || '' === $email || '' === $password || '' === $confirm_password ) {
			$error_message = __( 'Please complete all fields.', 'loopbuy' );
		} elseif ( $password !== $confirm_password ) {
			$error_message = __( 'The passwords do not match.', 'loopbuy' );
		} elseif ( ! function_exists( 'loopbuy_marketplace_register' ) ) {
			$error_message = __( 'Marketplace registration is temporarily unavailable.', 'loopbuy' );
		} else {
			$register_result = loopbuy_marketplace_register( $username, $email, $password, $submitted_csrf );

			if ( is_wp_error( $register_result ) ) {
				$error_message = $register_result->get_error_message();
			} else {
				$pending_email       = sanitize_email( $register_result['email'] );
				$verification_needed = ! empty( $register_result['verification_required'] );
				$success_message     = __( 'Account created. Check your inbox and verify your email before logging in.', 'loopbuy' );
			}
		}
	}
}

if ( '' === $error_message && isset( $_GET['loopbuy_auth_error'] ) && is_string( $_GET['loopbuy_auth_error'] ) ) {
	$auth_error = sanitize_key( wp_unslash( $_GET['loopbuy_auth_error'] ) );
	$messages   = array(
		'google_denied'      => __( 'Google registration was cancelled or denied.', 'loopbuy' ),
		'google_state'       => __( 'The Google registration request expired or could not be verified. Please try again.', 'loopbuy' ),
		'google_exchange'    => __( 'Google could not complete registration. Please try again.', 'loopbuy' ),
		'google_session'     => __( 'Google connected, but the marketplace session could not be started.', 'loopbuy' ),
		'google_unavailable' => __( 'Google registration is temporarily unavailable.', 'loopbuy' ),
	);

	if ( isset( $messages[ $auth_error ] ) ) {
		$error_message = $messages[ $auth_error ];
	}
}

if ( '' === $error_message && is_wp_error( $current_user ) ) {
	$error_message = $current_user->get_error_message();
}

if ( '' === $error_message && is_wp_error( $csrf_token ) ) {
	$error_message = $csrf_token->get_error_message();
}

$google_available = function_exists( 'loopbuy_marketplace_google_available' ) && loopbuy_marketplace_google_available();

get_header();
?>

<main class="loopbuy-register-page">
	<section class="register-section">
		<div class="register-heading">
			<div class="register-icon" aria-hidden="true"><span>&#9823;+</span></div>
			<h1><?php esc_html_e( 'Create your account', 'loopbuy' ); ?></h1>
			<p><?php esc_html_e( 'Sign up to get started', 'loopbuy' ); ?></p>
		</div>

		<div class="register-card">
			<?php if ( $error_message ) : ?>
				<div class="register-message register-error" role="alert"><?php echo esc_html( $error_message ); ?></div>
			<?php endif; ?>

			<?php if ( $success_message ) : ?>
				<div class="register-message register-success" role="status"><?php echo esc_html( $success_message ); ?></div>
			<?php endif; ?>

			<?php if ( $google_available ) : ?>
				<a class="google-register-button loopbuy-google-link" href="<?php echo esc_url( loopbuy_marketplace_google_start_url( 'register' ) ); ?>">
					<span class="google-letter" aria-hidden="true">G</span>
					<?php esc_html_e( 'Continue with Google', 'loopbuy' ); ?>
				</a>
			<?php else : ?>
				<button class="google-register-button" type="button" disabled aria-disabled="true" title="<?php echo esc_attr__( 'Google registration is not configured.', 'loopbuy' ); ?>">
					<span class="google-letter" aria-hidden="true">G</span>
					<?php esc_html_e( 'Continue with Google', 'loopbuy' ); ?>
				</button>
			<?php endif; ?>

			<div class="register-divider"><span><?php esc_html_e( 'OR', 'loopbuy' ); ?></span></div>

			<form class="register-form" method="post" action="<?php echo esc_url( home_url( '/register/' ) ); ?>">
				<input type="hidden" name="loopbuy_auth_form" value="register">
				<?php if ( is_string( $csrf_token ) ) : ?>
					<input type="hidden" name="loopbuy_marketplace_csrf" value="<?php echo esc_attr( $csrf_token ); ?>">
				<?php endif; ?>

				<div class="register-field">
					<label for="register-username"><?php esc_html_e( 'Username', 'loopbuy' ); ?></label>
					<div class="register-input-wrapper">
						<span class="register-input-icon" aria-hidden="true">@</span>
						<input id="register-username" name="username" type="text" autocomplete="username" placeholder="marketplace_user" pattern="[A-Za-z0-9][A-Za-z0-9_.-]{2,49}" minlength="3" maxlength="50" value="<?php echo isset( $_POST['username'] ) && is_string( $_POST['username'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_POST['username'] ) ) ) : ''; ?>" required>
					</div>
				</div>

				<div class="register-field">
					<label for="register-email"><?php esc_html_e( 'Email', 'loopbuy' ); ?></label>
					<div class="register-input-wrapper">
						<span class="register-input-icon" aria-hidden="true">&#9993;</span>
						<input id="register-email" name="email" type="email" autocomplete="email" placeholder="you@example.com" value="<?php echo isset( $_POST['email'] ) && is_string( $_POST['email'] ) ? esc_attr( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : ''; ?>" required>
					</div>
				</div>

				<div class="register-field">
					<label for="register-password"><?php esc_html_e( 'Password', 'loopbuy' ); ?></label>
					<div class="register-input-wrapper">
						<span class="register-input-icon" aria-hidden="true">&#128274;</span>
						<input id="register-password" name="password" type="password" autocomplete="new-password" placeholder="********" minlength="8" maxlength="72" required>
					</div>
				</div>

				<div class="register-field">
					<label for="register-confirm-password"><?php esc_html_e( 'Confirm Password', 'loopbuy' ); ?></label>
					<div class="register-input-wrapper">
						<span class="register-input-icon" aria-hidden="true">&#128274;</span>
						<input id="register-confirm-password" name="confirm_password" type="password" autocomplete="new-password" placeholder="********" minlength="8" maxlength="72" required>
					</div>
				</div>

				<button class="register-submit-button" type="submit" <?php disabled( is_wp_error( $csrf_token ) ); ?>><?php esc_html_e( 'Create account', 'loopbuy' ); ?></button>
			</form>

			<?php if ( $verification_needed ) : ?>
				<div class="loopbuy-resend-panel">
					<h2><?php esc_html_e( 'No email yet?', 'loopbuy' ); ?></h2>
					<form class="register-form" method="post" action="<?php echo esc_url( home_url( '/register/' ) ); ?>">
						<input type="hidden" name="loopbuy_auth_form" value="email_resend">
						<input type="hidden" name="email" value="<?php echo esc_attr( $pending_email ); ?>">
						<?php if ( is_string( $csrf_token ) ) : ?>
							<input type="hidden" name="loopbuy_marketplace_csrf" value="<?php echo esc_attr( $csrf_token ); ?>">
						<?php endif; ?>
						<button class="loopbuy-secondary-auth-button" type="submit" <?php disabled( is_wp_error( $csrf_token ) ); ?>><?php esc_html_e( 'Resend verification email', 'loopbuy' ); ?></button>
					</form>
				</div>
			<?php endif; ?>
		</div>

		<p class="register-login-link"><?php esc_html_e( 'Already have an account?', 'loopbuy' ); ?> <a href="<?php echo esc_url( home_url( '/login/' ) ); ?>"><?php esc_html_e( 'Log in', 'loopbuy' ); ?></a></p>
	</section>
</main>

<?php
get_footer();
