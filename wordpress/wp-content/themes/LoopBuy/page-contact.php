<?php
/**
 * Template Name: Contact
 *
 * Custom page template for the "Contact" page. WordPress will also
 * auto-select this file for any page with the slug "contact"
 * (page-contact.php), or it can be assigned manually via the
 * Page Attributes panel.
 *
 * @package LoopBuy
 */

get_header();

$loopbuy_contact_sent   = false;
$loopbuy_contact_errors = array();

// Handle form submission.
if ( isset( $_POST['loopbuy_contact_submit'] ) ) {

	if ( ! isset( $_POST['loopbuy_contact_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['loopbuy_contact_nonce'] ) ), 'loopbuy_contact_form' ) ) {
		$loopbuy_contact_errors[] = __( 'Your session expired. Please try again.', 'loopbuy' );
	} else {
		$name    = isset( $_POST['loopbuy_contact_name'] ) ? sanitize_text_field( wp_unslash( $_POST['loopbuy_contact_name'] ) ) : '';
		$email   = isset( $_POST['loopbuy_contact_email'] ) ? sanitize_email( wp_unslash( $_POST['loopbuy_contact_email'] ) ) : '';
		$message = isset( $_POST['loopbuy_contact_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['loopbuy_contact_message'] ) ) : '';

		if ( empty( $name ) ) {
			$loopbuy_contact_errors[] = __( 'Please enter your name.', 'loopbuy' );
		}
		if ( empty( $email ) || ! is_email( $email ) ) {
			$loopbuy_contact_errors[] = __( 'Please enter a valid email address.', 'loopbuy' );
		}
		if ( empty( $message ) ) {
			$loopbuy_contact_errors[] = __( 'Please enter a message.', 'loopbuy' );
		}

		if ( empty( $loopbuy_contact_errors ) ) {
			$to      = get_option( 'admin_email' );
			/* translators: %s: Site name. */
			$subject = sprintf( __( 'New contact message on %s', 'loopbuy' ), get_bloginfo( 'name' ) );
			$body    = sprintf(
				/* translators: 1: Name, 2: Email, 3: Message. */
				__( "Name: %1\$s\nEmail: %2\$s\n\nMessage:\n%3\$s", 'loopbuy' ),
				$name,
				$email,
				$message
			);
			$headers = array( 'Reply-To: ' . $name . ' <' . $email . '>' );

			$loopbuy_contact_sent = wp_mail( $to, $subject, $body, $headers );

			if ( ! $loopbuy_contact_sent ) {
				$loopbuy_contact_errors[] = __( 'Something went wrong sending your message. Please try again.', 'loopbuy' );
			}
		}
	}
}
?>

<main id="primary" class="site-main">

	<div class="page loopbuy-contact">
		<div class="loopbuy-contact-wrap">

			<header class="loopbuy-contact-header">
				<h1 class="loopbuy-contact-title"><?php esc_html_e( 'Contact Us', 'loopbuy' ); ?></h1>
				<p class="loopbuy-contact-subtitle"><?php esc_html_e( "Questions, feedback, or reporting a suspicious listing? We're here to help.", 'loopbuy' ); ?></p>
			</header>

			<div class="loopbuy-contact-grid">

				<div class="loopbuy-contact-info">

					<div class="loopbuy-contact-info-item">
						<span class="loopbuy-contact-info-icon" aria-hidden="true">
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
						</span>
						<div>
							<p class="loopbuy-contact-info-title"><?php esc_html_e( 'Email', 'loopbuy' ); ?></p>
							<p class="loopbuy-contact-info-detail">
								<a href="mailto:support@safetrade.app">support@loopbuy.app</a>
							</p>
						</div>
					</div>

					<div class="loopbuy-contact-info-item">
						<span class="loopbuy-contact-info-icon" aria-hidden="true">
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
						</span>
						<div>
							<p class="loopbuy-contact-info-title"><?php esc_html_e( 'Location', 'loopbuy' ); ?></p>
							<p class="loopbuy-contact-info-detail"><?php esc_html_e( 'Singapore', 'loopbuy' ); ?></p>
						</div>
					</div>

					<div class="loopbuy-contact-info-item">
						<span class="loopbuy-contact-info-icon" aria-hidden="true">
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
						</span>
						<div>
							<p class="loopbuy-contact-info-title"><?php esc_html_e( 'Live Chat', 'loopbuy' ); ?></p>
							<p class="loopbuy-contact-info-detail"><?php esc_html_e( 'Use our AI assistant', 'loopbuy' ); ?></p>
						</div>
					</div>

				</div><!-- .loopbuy-contact-info -->

				<div class="loopbuy-contact-form">

					<?php if ( $loopbuy_contact_sent ) : ?>
						<p class="loopbuy-contact-status" data-state="success"><?php esc_html_e( "Thanks — your message has been sent. We'll get back to you soon.", 'loopbuy' ); ?></p>
					<?php else : ?>

						<?php if ( ! empty( $loopbuy_contact_errors ) ) : ?>
							<?php foreach ( $loopbuy_contact_errors as $loopbuy_contact_error ) : ?>
								<p class="loopbuy-contact-status" data-state="error"><?php echo esc_html( $loopbuy_contact_error ); ?></p>
							<?php endforeach; ?>
						<?php endif; ?>

						<form method="post" action="">
							<?php wp_nonce_field( 'loopbuy_contact_form', 'loopbuy_contact_nonce' ); ?>

							<div class="loopbuy-contact-field">
								<label for="loopbuy-contact-name"><?php esc_html_e( 'Name', 'loopbuy' ); ?></label>
								<input type="text" id="loopbuy-contact-name" name="loopbuy_contact_name" value="<?php echo isset( $name ) ? esc_attr( $name ) : ''; ?>" required>
							</div>

							<div class="loopbuy-contact-field">
								<label for="loopbuy-contact-email"><?php esc_html_e( 'Email', 'loopbuy' ); ?></label>
								<input type="email" id="loopbuy-contact-email" name="loopbuy_contact_email" value="<?php echo isset( $email ) ? esc_attr( $email ) : ''; ?>" required>
							</div>

							<div class="loopbuy-contact-field">
								<label for="loopbuy-contact-message"><?php esc_html_e( 'Message', 'loopbuy' ); ?></label>
								<textarea id="loopbuy-contact-message" name="loopbuy_contact_message" required><?php echo isset( $message ) ? esc_textarea( $message ) : ''; ?></textarea>
							</div>

							<button type="submit" name="loopbuy_contact_submit" class="loopbuy-contact-submit">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
								<?php esc_html_e( 'Send message', 'loopbuy' ); ?>
							</button>
						</form>

					<?php endif; ?>

				</div><!-- .loopbuy-contact-form -->

			</div><!-- .loopbuy-contact-grid -->

		</div><!-- .loopbuy-contact-wrap -->
	</div><!-- .page.loopbuy-contact -->

</main><!-- #primary -->

<?php
get_footer();