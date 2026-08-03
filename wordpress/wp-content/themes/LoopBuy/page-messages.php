<?php
/**
 * The template for displaying the Messages page.
 *
 * @package LoopBuy
 */

get_header();

require get_template_directory() . '/inc/product-data.php';

$product_id       = isset( $_GET['product_id'] ) ? intval( $_GET['product_id'] ) : 0;
$selected_product = null;

foreach ( $products as $product ) {
	if ( intval( $product['id'] ) === $product_id ) {
		$selected_product = $product;
		break;
	}
}

// Use the first product when no product ID is provided.
if ( ! $selected_product && ! empty( $products ) ) {
	$selected_product = $products[0];
}
?>

<main class="loopbuy-messages-page">

	<div class="loopbuy-messages-container">

		<h1><?php esc_html_e( 'Messages', 'loopbuy' ); ?></h1>

		<div class="loopbuy-chat-layout">

			<aside class="loopbuy-conversation-list">

				<?php foreach ( $products as $product ) : ?>

					<?php
					$message_url = add_query_arg(
						'product_id',
						$product['id'],
						home_url( '/messages/' )
					);

					$is_active = intval( $product['id'] ) === intval( $selected_product['id'] );
					?>

					<a
						href="<?php echo esc_url( $message_url ); ?>"
						class="loopbuy-conversation-item <?php echo $is_active ? 'is-active' : ''; ?>"
					>
						<img
							src="<?php echo esc_url(
								get_template_directory_uri()
								. '/images/'
								. $product['image']
							); ?>"
							alt="<?php echo esc_attr( $product['name'] ); ?>"
						>

						<div>
							<h2><?php echo esc_html( $product['name'] ); ?></h2>

							<p>
								<?php
								printf(
									esc_html__( 'Hi! Is "%s" still available?', 'loopbuy' ),
									esc_html( $product['name'] )
								);
								?>
							</p>
						</div>
					</a>

				<?php endforeach; ?>

			</aside>


			<section
				class="loopbuy-chat-panel"
				data-product-id="<?php echo esc_attr( $selected_product['id'] ); ?>"
			>

				<header class="loopbuy-chat-header">

					<img
						src="<?php echo esc_url(
							get_template_directory_uri()
							. '/images/'
							. $selected_product['image']
						); ?>"
						alt="<?php echo esc_attr( $selected_product['name'] ); ?>"
					>

					<div>
						<h2><?php echo esc_html( $selected_product['name'] ); ?></h2>

						<p>
							$<?php echo esc_html(
								number_format( (float) $selected_product['price'], 2 )
							); ?>
							·
							<?php echo esc_html( $selected_product['condition'] ); ?>
						</p>
					</div>

					<a
						href="<?php echo esc_url(
							add_query_arg(
								'id',
								$selected_product['id'],
								home_url( '/product-detail/' )
							)
						); ?>"
						class="loopbuy-view-listing-link"
					>
						View listing
					</a>

				</header>


				<div id="loopbuy-chat-messages" class="loopbuy-chat-messages">

					<div class="loopbuy-chat-empty">
						<p><?php esc_html_e( 'Start a conversation with the seller.', 'loopbuy' ); ?></p>
					</div>

				</div>


				<form id="loopbuy-chat-form" class="loopbuy-chat-form">

					<label class="screen-reader-text" for="loopbuy-message-input">
						<?php esc_html_e( 'Type a message', 'loopbuy' ); ?>
					</label>

					<input
						type="text"
						id="loopbuy-message-input"
						placeholder="<?php esc_attr_e( 'Type a message...', 'loopbuy' ); ?>"
						autocomplete="off"
					>

					<button type="submit" aria-label="<?php esc_attr_e( 'Send message', 'loopbuy' ); ?>">
						➤
					</button>

				</form>

			</section>

		</div>

	</div>

</main>


<script>
document.addEventListener('DOMContentLoaded', function () {
	const chatPanel = document.querySelector('.loopbuy-chat-panel');
	const messagesContainer = document.getElementById('loopbuy-chat-messages');
	const messageForm = document.getElementById('loopbuy-chat-form');
	const messageInput = document.getElementById('loopbuy-message-input');

	if (!chatPanel || !messagesContainer || !messageForm || !messageInput) {
		return;
	}

	const productId = chatPanel.dataset.productId;
	const storageKey = 'loopbuy_messages_' + productId;

	function getMessages() {
		try {
			const savedMessages = localStorage.getItem(storageKey);
			return savedMessages ? JSON.parse(savedMessages) : [];
		} catch (error) {
			return [];
		}
	}

	function saveMessages(messages) {
		localStorage.setItem(storageKey, JSON.stringify(messages));
	}

	function renderMessages() {
		const messages = getMessages();

		messagesContainer.innerHTML = '';

		if (messages.length === 0) {
			messagesContainer.innerHTML = `
				<div class="loopbuy-chat-empty">
					<p>Start a conversation with the seller.</p>
				</div>
			`;
			return;
		}

		messages.forEach(function (message) {
			const bubble = document.createElement('div');
			bubble.className = 'loopbuy-message-bubble loopbuy-message-buyer';
			bubble.textContent = message.text;
			messagesContainer.appendChild(bubble);
		});

		messagesContainer.scrollTop = messagesContainer.scrollHeight;
	}

	messageForm.addEventListener('submit', function (event) {
		event.preventDefault();

		const text = messageInput.value.trim();

		if (!text) {
			return;
		}

		const messages = getMessages();

		messages.push({
			text: text,
			sentAt: new Date().toISOString()
		});

		saveMessages(messages);
		messageInput.value = '';
		renderMessages();
	});

	renderMessages();
});
</script>

<?php
get_footer();