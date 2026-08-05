<?php
/**
 * Template Name: LoopBuy Privacy Policy
 *
 * @package LoopBuy
 */

get_header();
?>

<main class="loopbuy-legal-page">
	<article class="loopbuy-legal-card">
		<header class="loopbuy-legal-header">
			<p class="loopbuy-legal-eyebrow"><?php esc_html_e( 'LoopBuy beta', 'loopbuy' ); ?></p>
			<h1><?php esc_html_e( 'Privacy Policy', 'loopbuy' ); ?></h1>
			<p><?php esc_html_e( 'Effective 5 August 2026. This policy describes the current prototype and will be updated when production operations or providers change.', 'loopbuy' ); ?></p>
		</header>

		<section id="cookies-and-storage">
			<h2><?php esc_html_e( 'What we collect', 'loopbuy' ); ?></h2>
			<p><?php esc_html_e( 'We collect account details such as username and email, optional profile details, listings and locally hosted images, favourites, cart activity, conversations and messages. We may also process IP address, device and security logs needed to protect and operate the service.', 'loopbuy' ); ?></p>
		</section>

		<section>
			<h2><?php esc_html_e( 'Google sign-in and email', 'loopbuy' ); ?></h2>
			<p><?php esc_html_e( 'If you choose Google sign-in, LoopBuy receives the basic identity information you approve: your stable Google account identifier, name and verified email address. Password-based accounts must verify their email. Transactional verification messages are delivered through Resend. We do not receive your Google password.', 'loopbuy' ); ?></p>
		</section>

		<section>
			<h2><?php esc_html_e( 'How information is used', 'loopbuy' ); ?></h2>
			<p><?php esc_html_e( 'Information is used to provide accounts and marketplace features, display and recommend listings, support buyer-seller conversations, detect abuse or suspected scams, maintain security and respond to support requests.', 'loopbuy' ); ?></p>
		</section>

		<section>
			<h2><?php esc_html_e( 'AI features', 'loopbuy' ); ?></h2>
			<p><?php esc_html_e( 'Search and assistant features may send relevant listing text and your prompt to configured AI providers, including OpenAI for embeddings and Qwen for language generation. Scam and recommendation scores are automated signals, not guarantees or final judgments. Avoid entering sensitive personal information into the assistant.', 'loopbuy' ); ?></p>
		</section>

		<section>
			<h2><?php esc_html_e( 'Cookies and storage', 'loopbuy' ); ?></h2>
			<p><?php esc_html_e( 'LoopBuy uses necessary cookies for authentication, refresh sessions, CSRF protection and Google OAuth state. Marketplace access and refresh tokens are stored in host-only HttpOnly cookies and are not exposed to page JavaScript. Listing images are stored on the LoopBuy server rather than a third-party image host.', 'loopbuy' ); ?></p>
		</section>

		<section>
			<h2><?php esc_html_e( 'Sharing and retention', 'loopbuy' ); ?></h2>
			<p><?php esc_html_e( 'Public listings and seller display details are visible to visitors. Messages are visible to conversation participants. We share information with infrastructure and service providers only as needed to operate LoopBuy, authenticate users, send email and provide AI features, or when legally required. Account content is kept while the account is active and may remain temporarily in backups or security logs. Exact production retention periods will be published before commercial launch.', 'loopbuy' ); ?></p>
		</section>

		<section>
			<h2><?php esc_html_e( 'Your choices', 'loopbuy' ); ?></h2>
			<p><?php esc_html_e( 'You may update profile details and can request access, correction or deletion of account information. You can also choose password sign-in instead of Google. Some records may be retained where required for security, fraud prevention or legal obligations.', 'loopbuy' ); ?></p>
		</section>

		<section>
			<h2><?php esc_html_e( 'Contact and changes', 'loopbuy' ); ?></h2>
			<p>
				<?php esc_html_e( 'Privacy questions and account requests can be sent through the', 'loopbuy' ); ?>
				<a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'contact page', 'loopbuy' ); ?></a>.
				<?php esc_html_e( 'Material changes will be published here with a revised effective date.', 'loopbuy' ); ?>
			</p>
		</section>

		<p class="loopbuy-legal-note"><?php esc_html_e( 'This is an interim beta policy and must receive jurisdiction-specific legal review before a commercial launch.', 'loopbuy' ); ?></p>
	</article>
</main>

<?php
get_footer();
