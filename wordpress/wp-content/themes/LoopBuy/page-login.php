<?php
/**
 * Template for the Login page.
 *
 * @package LoopBuy
 */

get_header();

$error_message = '';

if ( is_user_logged_in() ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {

	if (
		! isset( $_POST['loopbuy_login_nonce'] ) ||
		! wp_verify_nonce(
			sanitize_text_field(
				wp_unslash( $_POST['loopbuy_login_nonce'] )
			),
			'loopbuy_login_action'
		)
	) {
		$error_message = 'Security check failed. Please try again.';
	} else {

		$email = isset( $_POST['email'] )
			? sanitize_email( wp_unslash( $_POST['email'] ) )
			: '';

		$password = isset( $_POST['password'] )
			? (string) wp_unslash( $_POST['password'] )
			: '';

		if ( empty( $email ) || empty( $password ) ) {
			$error_message = 'Please enter your email and password.';
		} else {

			$user = get_user_by( 'email', $email );

			if ( ! $user ) {
				$error_message = 'No account was found with this email.';
			} else {

				$login_result = wp_signon(
					array(
						'user_login'    => $user->user_login,
						'user_password' => $password,
						'remember'      => isset( $_POST['remember_me'] ),
					),
					is_ssl()
				);

				if ( is_wp_error( $login_result ) ) {
					$error_message = 'Incorrect email or password.';
				} else {
					wp_safe_redirect( home_url( '/' ) );
					exit;
				}
			}
		}
	}
}
?>

<main class="loopbuy-login-page">

	<section class="login-section">

		<div class="login-heading">

			<div class="login-icon" aria-hidden="true">
				<span>↪</span>
			</div>

			<h1>Welcome back</h1>

			<p>Log in to your account</p>

		</div>

		<div class="login-card">

			<?php if ( $error_message ) : ?>

				<div class="login-message login-error">
					<?php echo esc_html( $error_message ); ?>
				</div>

			<?php endif; ?>

			<button
				class="google-login-button"
				type="button"
			>
				<span class="google-letter">G</span>
				Continue with Google
			</button>

			<div class="login-divider">
				<span>OR</span>
			</div>

			<form
				class="login-form"
				method="post"
				action="<?php echo esc_url( home_url( '/login/' ) ); ?>"
			>

				<?php
				wp_nonce_field(
					'loopbuy_login_action',
					'loopbuy_login_nonce'
				);
				?>

				<div class="login-field">

					<label for="login-email">
						Email
					</label>

					<div class="login-input-wrapper">

						<span class="login-input-icon">✉</span>

						<input
							id="login-email"
							name="email"
							type="email"
							placeholder="you@example.com"
							value="<?php echo isset( $_POST['email'] ) ? esc_attr( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : ''; ?>"
							required
						>

					</div>

				</div>

				<div class="login-field">

					<div class="login-password-heading">

						<label for="login-password">
							Password
						</label>

						<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">
							Forgot password?
						</a>

					</div>

					<div class="login-input-wrapper">

						<span class="login-input-icon">🔒</span>

						<input
							id="login-password"
							name="password"
							type="password"
							placeholder="••••••••"
							required
						>

					</div>

				</div>

				<label class="login-remember">
					<input
						name="remember_me"
						type="checkbox"
						value="1"
					>
					<span>Remember me</span>
				</label>

				<button
					class="login-submit-button"
					type="submit"
				>
					Log in
				</button>

			</form>

		</div>

		<p class="login-register-link">

			Don't have an account?

			<a href="<?php echo esc_url( home_url( '/register/' ) ); ?>">
				Create one
			</a>

		</p>

	</section>

</main>

<?php
get_footer();