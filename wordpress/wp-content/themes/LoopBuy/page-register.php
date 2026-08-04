<?php
/**
 * Template for the Register page.
 *
 * @package LoopBuy
 */

get_header();

$error_message   = '';
$success_message = '';

if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {

	if (
		! isset( $_POST['loopbuy_register_nonce'] ) ||
		! wp_verify_nonce(
			sanitize_text_field(
				wp_unslash( $_POST['loopbuy_register_nonce'] )
			),
			'loopbuy_register_action'
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

		$confirm_password = isset( $_POST['confirm_password'] )
			? (string) wp_unslash( $_POST['confirm_password'] )
			: '';

		if ( empty( $email ) || empty( $password ) || empty( $confirm_password ) ) {
			$error_message = 'Please complete all fields.';
		} elseif ( ! is_email( $email ) ) {
			$error_message = 'Please enter a valid email address.';
		} elseif ( strlen( $password ) < 8 ) {
			$error_message = 'Password must contain at least 8 characters.';
		} elseif ( $password !== $confirm_password ) {
			$error_message = 'The passwords do not match.';
		} elseif ( email_exists( $email ) ) {
			$error_message = 'An account with this email already exists.';
		} else {

			$username = sanitize_user(
				strstr( $email, '@', true ),
				true
			);

			if ( empty( $username ) ) {
				$username = 'loopbuy_user';
			}

			$original_username = $username;
			$number            = 1;

			while ( username_exists( $username ) ) {
				$username = $original_username . $number;
				$number++;
			}

			$user_id = wp_insert_user(
				array(
					'user_login' => $username,
					'user_email' => $email,
					'user_pass'  => $password,
					'role'       => 'subscriber',
				)
			);

			if ( is_wp_error( $user_id ) ) {
				$error_message = $user_id->get_error_message();
			} else {
				$success_message = 'Your account has been created successfully.';
			}
		}
	}
}
?>

<main class="loopbuy-register-page">

	<section class="register-section">

		<div class="register-heading">

			<div class="register-icon" aria-hidden="true">
				<span>♙+</span>
			</div>

			<h1>Create your account</h1>

			<p>Sign up to get started</p>

		</div>

		<div class="register-card">

			<?php if ( $error_message ) : ?>

				<div class="register-message register-error">
					<?php echo esc_html( $error_message ); ?>
				</div>

			<?php endif; ?>

			<?php if ( $success_message ) : ?>

				<div class="register-message register-success">
					<?php echo esc_html( $success_message ); ?>
				</div>

			<?php endif; ?>

			<button
				class="google-register-button"
				type="button"
			>
				<span class="google-letter">G</span>
				Continue with Google
			</button>

			<div class="register-divider">
				<span>OR</span>
			</div>

			<form
				class="register-form"
				method="post"
				action="<?php echo esc_url( home_url( '/register/' ) ); ?>"
			>

				<?php
				wp_nonce_field(
					'loopbuy_register_action',
					'loopbuy_register_nonce'
				);
				?>

				<div class="register-field">

					<label for="register-email">
						Email
					</label>

					<div class="register-input-wrapper">

						<span class="register-input-icon">✉</span>

						<input
							id="register-email"
							name="email"
							type="email"
							placeholder="you@example.com"
							value="<?php echo isset( $_POST['email'] ) ? esc_attr( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : ''; ?>"
							required
						>

					</div>

				</div>

				<div class="register-field">

					<label for="register-password">
						Password
					</label>

					<div class="register-input-wrapper">

						<span class="register-input-icon">🔒</span>

						<input
							id="register-password"
							name="password"
							type="password"
							placeholder="••••••••"
							minlength="8"
							required
						>

					</div>

				</div>

				<div class="register-field">

					<label for="register-confirm-password">
						Confirm Password
					</label>

					<div class="register-input-wrapper">

						<span class="register-input-icon">🔒</span>

						<input
							id="register-confirm-password"
							name="confirm_password"
							type="password"
							placeholder="••••••••"
							minlength="8"
							required
						>

					</div>

				</div>

				<button
					class="register-submit-button"
					type="submit"
				>
					Create account
				</button>

			</form>

		</div>

		<p class="register-login-link">
			Already have an account?

			<a href="<?php echo esc_url( home_url( '/login/' ) ); ?>">
				Log in
			</a>
		</p>

	</section>

</main>

<?php
get_footer();