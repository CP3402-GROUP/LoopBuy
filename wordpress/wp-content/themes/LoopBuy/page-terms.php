<?php
/**
 * Template Name: LoopBuy Terms of Service
 *
 * @package LoopBuy
 */

get_header();
?>

<main class="loopbuy-legal-page">
	<article class="loopbuy-legal-card">
		<header class="loopbuy-legal-header">
			<p class="loopbuy-legal-eyebrow"><?php esc_html_e( 'LoopBuy beta', 'loopbuy' ); ?></p>
			<h1><?php esc_html_e( 'Terms of Service', 'loopbuy' ); ?></h1>
			<p><?php esc_html_e( 'Effective 5 August 2026. By using this prototype, you agree to these interim terms.', 'loopbuy' ); ?></p>
		</header>

		<section>
			<h2><?php esc_html_e( 'Using LoopBuy', 'loopbuy' ); ?></h2>
			<p><?php esc_html_e( 'You must provide accurate account information, keep your account secure and use the service lawfully. You are responsible for activity performed through your account and for reviewing a listing before arranging a transaction.', 'loopbuy' ); ?></p>
		</section>

		<section>
			<h2><?php esc_html_e( 'Listings and transactions', 'loopbuy' ); ?></h2>
			<p><?php esc_html_e( 'Sellers are responsible for the accuracy, legality, condition, ownership and delivery of listed items. Buyers and sellers are responsible for agreeing on payment, delivery, returns and disputes. Unless a checkout explicitly says otherwise, LoopBuy does not act as an escrow provider and is not a party to the transaction.', 'loopbuy' ); ?></p>
		</section>

		<section>
			<h2><?php esc_html_e( 'Prohibited conduct', 'loopbuy' ); ?></h2>
			<p><?php esc_html_e( 'Do not list illegal, stolen, counterfeit, unsafe or misleading items; impersonate others; send spam or harassment; attempt fraud; scrape or overload the service; bypass access controls; upload malware; or misuse personal information obtained through LoopBuy.', 'loopbuy' ); ?></p>
		</section>

		<section>
			<h2><?php esc_html_e( 'Automated and AI features', 'loopbuy' ); ?></h2>
			<p><?php esc_html_e( 'Recommendations, assistant answers and scam-risk signals can be incomplete or wrong. They are aids, not guarantees that an item is suitable, authentic or safe. Verify important information independently and report suspicious activity.', 'loopbuy' ); ?></p>
		</section>

		<section>
			<h2><?php esc_html_e( 'Your content', 'loopbuy' ); ?></h2>
			<p><?php esc_html_e( 'You retain ownership of content you submit. You give LoopBuy permission to host, reproduce and display that content as needed to operate and improve the marketplace. You must have the rights required to upload it.', 'loopbuy' ); ?></p>
		</section>

		<section>
			<h2><?php esc_html_e( 'Moderation and account action', 'loopbuy' ); ?></h2>
			<p><?php esc_html_e( 'LoopBuy may review, restrict or remove content and may suspend accounts to protect users, investigate abuse or comply with law. Automated flags should be reviewed before permanent enforcement whenever practical.', 'loopbuy' ); ?></p>
		</section>

		<section>
			<h2><?php esc_html_e( 'Beta availability and liability', 'loopbuy' ); ?></h2>
			<p><?php esc_html_e( 'The service is provided on a beta, as-available basis and may change or experience interruptions. To the extent permitted by applicable law, LoopBuy does not guarantee listings, users, transactions, AI output or uninterrupted availability. Nothing here excludes rights or liabilities that cannot legally be excluded.', 'loopbuy' ); ?></p>
		</section>

		<section>
			<h2><?php esc_html_e( 'Privacy, changes and contact', 'loopbuy' ); ?></h2>
			<p>
				<?php esc_html_e( 'Use of personal information is described in the', 'loopbuy' ); ?>
				<a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>"><?php esc_html_e( 'Privacy Policy', 'loopbuy' ); ?></a>.
				<?php esc_html_e( 'Updated terms will be posted here. Questions can be sent through the', 'loopbuy' ); ?>
				<a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'contact page', 'loopbuy' ); ?></a>.
			</p>
		</section>

		<p class="loopbuy-legal-note"><?php esc_html_e( 'These interim beta terms are not a substitute for jurisdiction-specific legal review before commercial launch.', 'loopbuy' ); ?></p>
	</article>
</main>

<?php
get_footer();
