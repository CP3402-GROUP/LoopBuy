<?php
/**
 * Template for the Login and email-verification page.
 *
 * @package LoopBuy
 */

$error_message       = '';
$success_message     = '';
$resend_email        = '';
$verification_needed = false;
$request_method      = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';

// Capture the email-link secret in an HttpOnly cookie, then immediately leave
// the token-bearing URL. The token is never rendered into HTML or JavaScript.
if ( 'GET' === $request_method && isset( $_GET['token'] ) ) {
	if ( function_exists( 'loopbuy_marketplace_send_verification_headers' ) ) {
		loopbuy_marketplace_send_verification_headers( true );
	}

	$captured = function_exists( 'loopbuy_marketplace_capture_verification_token' )
		? loopbuy_marketplace_capture_verification_token( $_GET['token'] )
		: new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'Email verification is temporarily unavailable.', 'loopbuy' ) );
	$redirect = is_wp_error( $captured )
		? add_query_arg( 'loopbuy_auth_error', 'email_token', home_url( '/login/' ) )
		: add_query_arg( 'loopbuy_verify_email', '1', home_url( '/login/' ) );

	wp_safe_redirect( $redirect, 303 );
	exit;
}

$verification_needed = isset( $_GET['loopbuy_verify_email'] )
	&& is_string( $_GET['loopbuy_verify_email'] )
	&& '1' === $_GET['loopbuy_verify_email']
	&& function_exists( 'loopbuy_marketplace_has_verification_token' )
	&& loopbuy_marketplace_has_verification_token();

if ( $verification_needed && function_exists( 'loopbuy_marketplace_send_verification_headers' ) ) {
	loopbuy_marketplace_send_verification_headers();
}

$current_user = function_exists( 'loopbuy_marketplace_current_user' )
	? loopbuy_marketplace_current_user()
	: new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'Marketplace sign-in is temporarily unavailable.', 'loopbuy' ) );

if ( is_array( $current_user ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$csrf_token = function_exists( 'loopbuy_marketplace_csrf_token' )
	? loopbuy_marketplace_csrf_token()
	: new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'Marketplace sign-in is temporarily unavailable.', 'loopbuy' ) );

if ( 'POST' === $request_method ) {
	$form_action = isset( $_POST['loopbuy_auth_form'] ) && is_string( $_POST['loopbuy_auth_form'] )
		? sanitize_key( wp_unslash( $_POST['loopbuy_auth_form'] ) )
		: 'login';
	$submitted_csrf = isset( $_POST['loopbuy_marketplace_csrf'] )
		? $_POST['loopbuy_marketplace_csrf']
		: '';

	if ( 'email_verify' === $form_action ) {
		if ( function_exists( 'loopbuy_marketplace_send_verification_headers' ) ) {
			loopbuy_marketplace_send_verification_headers();
		}

		$result = function_exists( 'loopbuy_marketplace_verify_captured_email' )
			? loopbuy_marketplace_verify_captured_email( $submitted_csrf )
			: new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'Email verification is temporarily unavailable.', 'loopbuy' ) );

		if ( is_wp_error( $result ) ) {
			$error_message       = $result->get_error_message();
			$verification_needed = function_exists( 'loopbuy_marketplace_has_verification_token' )
				&& loopbuy_marketplace_has_verification_token();
		} else {
			wp_safe_redirect( add_query_arg( 'loopbuy_email_verified', '1', home_url( '/login/' ) ) );
			exit;
		}
	} elseif ( 'email_resend' === $form_action ) {
		$resend_email = isset( $_POST['email'] ) && is_string( $_POST['email'] )
			? sanitize_email( wp_unslash( $_POST['email'] ) )
			: '';
		$result = function_exists( 'loopbuy_marketplace_resend_verification' )
			? loopbuy_marketplace_resend_verification( $resend_email, $submitted_csrf )
			: new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'Email verification is temporarily unavailable.', 'loopbuy' ) );

		if ( is_wp_error( $result ) ) {
			$error_message = $result->get_error_message();
		} else {
			$success_message = __( 'If that account needs verification, a fresh email has been sent.', 'loopbuy' );
		}
	} else {
		$email = isset( $_POST['email'] ) && is_string( $_POST['email'] )
			? sanitize_email( wp_unslash( $_POST['email'] ) )
			: '';
		$password = isset( $_POST['password'] ) && is_string( $_POST['password'] )
			? (string) wp_unslash( $_POST['password'] )
			: '';

		if ( ! function_exists( 'loopbuy_marketplace_login' ) ) {
			$login_result = new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'Marketplace sign-in is temporarily unavailable.', 'loopbuy' ) );
		} else {
			$login_result = loopbuy_marketplace_login(
				$email,
				$password,
				isset( $_POST['remember_me'] ),
				$submitted_csrf
			);
		}

		if ( is_wp_error( $login_result ) ) {
			$error_message = $login_result->get_error_message();

			if ( 'loopbuy_marketplace_email_unverified' === $login_result->get_error_code() ) {
				$error_data = $login_result->get_error_data();
				$resend_email = is_array( $error_data ) && isset( $error_data['email'] )
					? sanitize_email( $error_data['email'] )
					: $email;
			}
		} else {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
	}
}

if ( '' === $error_message && isset( $_GET['loopbuy_auth_error'] ) && is_string( $_GET['loopbuy_auth_error'] ) ) {
	$auth_error = sanitize_key( wp_unslash( $_GET['loopbuy_auth_error'] ) );
	$messages   = array(
		'email_token'        => __( 'This verification link is invalid or incomplete.', 'loopbuy' ),
		'google_denied'      => __( 'Google sign-in was cancelled or denied.', 'loopbuy' ),
		'google_state'       => __( 'The Google sign-in request expired or could not be verified. Please try again.', 'loopbuy' ),
		'google_exchange'    => __( 'Google could not complete sign-in. Please try again.', 'loopbuy' ),
		'google_session'     => __( 'Google signed you in, but the marketplace session could not be started.', 'loopbuy' ),
		'google_unavailable' => __( 'Google sign-in is temporarily unavailable.', 'loopbuy' ),
	);

	if ( isset( $messages[ $auth_error ] ) ) {
		$error_message = $messages[ $auth_error ];
	}
}

if ( '' === $success_message && isset( $_GET['loopbuy_email_verified'] ) && is_string( $_GET['loopbuy_email_verified'] ) && '1' === $_GET['loopbuy_email_verified'] ) {
	$success_message = __( 'Your email is verified. You can now log in.', 'loopbuy' );
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

<main class="loopbuy-login-page">
	<section class="login-section">
		<div class="login-heading">
			<div class="login-icon" aria-hidden="true"><span>&#8594;</span></div>
			<h1><?php esc_html_e( 'Welcome back', 'loopbuy' ); ?></h1>
			<p><?php esc_html_e( 'Log in to your account', 'loopbuy' ); ?></p>
		</div>

		<div class="login-card">
			<?php if ( $error_message ) : ?>
				<div class="login-message login-error" role="alert"><?php echo esc_html( $error_message ); ?></div>
			<?php endif; ?>

			<?php if ( $success_message ) : ?>
				<div class="login-message login-success" role="status"><?php echo esc_html( $success_message ); ?></div>
			<?php endif; ?>

			<?php if ( $verification_needed ) : ?>
				<div class="loopbuy-verification-panel">
					<h2><?php esc_html_e( 'Verify your email', 'loopbuy' ); ?></h2>
					<p><?php esc_html_e( 'Confirm below to activate your marketplace account. Verification tokens are sent to the API only by POST.', 'loopbuy' ); ?></p>
					<form method="post" action="<?php echo esc_url( home_url( '/login/' ) ); ?>">
						<input type="hidden" name="loopbuy_auth_form" value="email_verify">
						<?php if ( is_string( $csrf_token ) ) : ?>
							<input type="hidden" name="loopbuy_marketplace_csrf" value="<?php echo esc_attr( $csrf_token ); ?>">
						<?php endif; ?>
						<button class="login-submit-button" type="submit" <?php disabled( is_wp_error( $csrf_token ) ); ?>><?php esc_html_e( 'Verify email', 'loopbuy' ); ?></button>
					</form>
				</div>
				<div class="login-divider"><span><?php esc_html_e( 'OR', 'loopbuy' ); ?></span></div>
			<?php endif; ?>

			<?php if ( $google_available ) : ?>
				<a class="google-login-button loopbuy-google-link" href="<?php echo esc_url( loopbuy_marketplace_google_start_url( 'login' ) ); ?>">
					<span class="google-letter" aria-hidden="true">G</span>
					<?php esc_html_e( 'Continue with Google', 'loopbuy' ); ?>
				</a>
			<?php else : ?>
				<button class="google-login-button" type="button" disabled aria-disabled="true" title="<?php echo esc_attr__( 'Google sign-in is not configured.', 'loopbuy' ); ?>">
					<span class="google-letter" aria-hidden="true">G</span>
					<?php esc_html_e( 'Continue with Google', 'loopbuy' ); ?>
				</button>
			<?php endif; ?>

			<div class="login-divider"><span><?php esc_html_e( 'OR', 'loopbuy' ); ?></span></div>

			<form class="login-form" method="post" action="<?php echo esc_url( home_url( '/login/' ) ); ?>">
				<input type="hidden" name="loopbuy_auth_form" value="login">
				<?php if ( is_string( $csrf_token ) ) : ?>
					<input type="hidden" name="loopbuy_marketplace_csrf" value="<?php echo esc_attr( $csrf_token ); ?>">
				<?php endif; ?>

				<div class="login-field">
					<label for="login-email"><?php esc_html_e( 'Email', 'loopbuy' ); ?></label>
					<div class="login-input-wrapper">
						<span class="login-input-icon" aria-hidden="true">&#9993;</span>
						<input id="login-email" name="email" type="email" autocomplete="email" placeholder="you@example.com" value="<?php echo isset( $_POST['email'] ) && is_string( $_POST['email'] ) ? esc_attr( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : ''; ?>" required>
					</div>
				</div>

				<div class="login-field">
					<div class="login-password-heading">
						<label for="login-password"><?php esc_html_e( 'Password', 'loopbuy' ); ?></label>
						<span title="<?php echo esc_attr__( 'Password recovery is not connected yet.', 'loopbuy' ); ?>"><?php esc_html_e( 'Forgot password?', 'loopbuy' ); ?></span>
					</div>
					<div class="login-input-wrapper">
						<span class="login-input-icon" aria-hidden="true">&#128274;</span>
						<input id="login-password" name="password" type="password" autocomplete="current-password" maxlength="72" placeholder="********" required>
					</div>
				</div>

				<label class="login-remember"><input name="remember_me" type="checkbox" value="1"><span><?php esc_html_e( 'Remember me', 'loopbuy' ); ?></span></label>
				<button class="login-submit-button" type="submit" <?php disabled( is_wp_error( $csrf_token ) ); ?>><?php esc_html_e( 'Log in', 'loopbuy' ); ?></button>
			</form>

			<div class="loopbuy-resend-panel">
				<h2><?php esc_html_e( 'Need a new verification email?', 'loopbuy' ); ?></h2>
				<form class="login-form" method="post" action="<?php echo esc_url( home_url( '/login/' ) ); ?>">
					<input type="hidden" name="loopbuy_auth_form" value="email_resend">
					<?php if ( is_string( $csrf_token ) ) : ?>
						<input type="hidden" name="loopbuy_marketplace_csrf" value="<?php echo esc_attr( $csrf_token ); ?>">
					<?php endif; ?>
					<div class="login-input-wrapper">
						<span class="login-input-icon" aria-hidden="true">&#9993;</span>
						<input name="email" type="email" autocomplete="email" placeholder="you@example.com" value="<?php echo esc_attr( $resend_email ); ?>" required>
					</div>
					<button class="loopbuy-secondary-auth-button" type="submit" <?php disabled( is_wp_error( $csrf_token ) ); ?>><?php esc_html_e( 'Resend verification email', 'loopbuy' ); ?></button>
				</form>
			</div>
		</div>

		<p class="login-register-link"><?php esc_html_e( "Don't have an account?", 'loopbuy' ); ?> <a href="<?php echo esc_url( home_url( '/register/' ) ); ?>"><?php esc_html_e( 'Create one', 'loopbuy' ); ?></a></p>
	</section>
</main>

<?php
get_footer();
