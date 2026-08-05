<?php
/**
 * The template for displaying the Messages page.
 *
 * Products are added to the conversation history when the user opens:
 * /messages/?product_id=PRODUCT_ID
 *
 * Conversation history and messages are stored in localStorage for this
 * frontend demonstration.
 *
 * @package LoopBuy
 */

get_header();

require get_template_directory() . '/inc/product-data.php';

/*
 * Read the selected product ID from the URL.
 *
 * Example:
 * /messages/?product_id=5
 */
$product_id = isset( $_GET['product_id'] )
	? absint( $_GET['product_id'] )
	: 0;

$selected_product = null;

/*
 * Find the product matching the URL product ID.
 */
if ( $product_id > 0 ) {
	foreach ( $products as $product ) {
		if ( (int) $product['id'] === $product_id ) {
			$selected_product = $product;
			break;
		}
	}
}
?>

<main class="loopbuy-messages-page">

	<div class="loopbuy-messages-container">

		<h1>
			<?php esc_html_e( 'Messages', 'loopbuy' ); ?>
		</h1>

		<div class="loopbuy-chat-layout">

			<!-- Conversation history -->
			<aside class="loopbuy-conversation-list">

				<div
					id="loopbuy-no-conversations"
					class="loopbuy-no-conversations"
					style="display: none;"
				>
					<p>
						<?php esc_html_e( 'No conversations yet.', 'loopbuy' ); ?>
					</p>

					<a href="<?php echo esc_url( home_url( '/' ) ); ?>">
						<?php esc_html_e( 'Browse products', 'loopbuy' ); ?>
					</a>
				</div>

				<?php foreach ( $products as $product ) : ?>

					<?php
					$message_url = add_query_arg(
						'product_id',
						$product['id'],
						home_url( '/messages/' )
					);

					$is_active =
						$selected_product &&
						(int) $product['id'] ===
						(int) $selected_product['id'];
					?>

					<a
						href="<?php echo esc_url( $message_url ); ?>"
						class="loopbuy-conversation-item<?php echo $is_active ? ' is-active' : ''; ?>"
						data-product-id="<?php echo esc_attr( $product['id'] ); ?>"
						style="display: none;"
					>
						<img
							src="<?php
							echo esc_url(
								loopbuy_product_image_url( $product )
							);
							?>"
							alt="<?php echo esc_attr( $product['name'] ); ?>"
						>

						<div class="loopbuy-conversation-content">

							<h2>
								<?php echo esc_html( $product['name'] ); ?>
							</h2>

							<p>
								<?php
								printf(
									esc_html__(
										'Hi! Is "%s" still available?',
										'loopbuy'
									),
									esc_html( $product['name'] )
								);
								?>
							</p>

						</div>
					</a>

				<?php endforeach; ?>

			</aside>


			<!-- Selected conversation -->
			<?php if ( $selected_product ) : ?>

				<section
					class="loopbuy-chat-panel"
					data-product-id="<?php echo esc_attr( $selected_product['id'] ); ?>"
                    data-product-name="<?php echo esc_attr( $selected_product['name'] ); ?>"
				>

					<header class="loopbuy-chat-header">

						<img
							src="<?php
							echo esc_url(
								loopbuy_product_image_url( $selected_product )
							);
							?>"
							alt="<?php echo esc_attr( $selected_product['name'] ); ?>"
						>

						<div class="loopbuy-chat-product-info">

							<h2>
								<?php echo esc_html( $selected_product['name'] ); ?>
							</h2>

							<p>
								$<?php
								echo esc_html(
									number_format(
										(float) $selected_product['price'],
										2
									)
								);
								?>

								<span aria-hidden="true">·</span>

								<?php
								echo esc_html(
									$selected_product['condition']
								);
								?>
							</p>

						</div>

						<a
							href="<?php
							echo esc_url(
								add_query_arg(
									'id',
									$selected_product['id'],
									home_url( '/product-detail/' )
								)
							);
							?>"
							class="loopbuy-view-listing-link"
						>
							<?php esc_html_e( 'View listing', 'loopbuy' ); ?>
						</a>

					</header>


					<div
						id="loopbuy-chat-messages"
						class="loopbuy-chat-messages"
					>
						<div class="loopbuy-chat-empty">
							<p>
								<?php
								esc_html_e(
									'Start a conversation with the seller.',
									'loopbuy'
								);
								?>
							</p>
						</div>
					</div>


					<form
						id="loopbuy-chat-form"
						class="loopbuy-chat-form"
					>

						<label
							class="screen-reader-text"
							for="loopbuy-message-input"
						>
							<?php esc_html_e( 'Type a message', 'loopbuy' ); ?>
						</label>

						<input
							type="text"
							id="loopbuy-message-input"
							placeholder="<?php esc_attr_e( 'Type a message...', 'loopbuy' ); ?>"
							autocomplete="off"
						>

						<button
							type="submit"
							aria-label="<?php esc_attr_e( 'Send message', 'loopbuy' ); ?>"
						>
							➤
						</button>

					</form>

				</section>

			<?php else : ?>

				<section class="loopbuy-chat-panel loopbuy-chat-panel-placeholder">

					<div class="loopbuy-chat-empty">

						<p>
							<?php
							esc_html_e(
								'Select a conversation to view your messages.',
								'loopbuy'
							);
							?>
						</p>

					</div>

				</section>

			<?php endif; ?>

		</div>

	</div>

</main>


<script>
document.addEventListener('DOMContentLoaded', function () {

	/* =====================================================
	   CONVERSATION HISTORY
	===================================================== */

	var CHAT_HISTORY_KEY = 'loopbuy_chat_history';

	var conversationItems = document.querySelectorAll(
		'.loopbuy-conversation-item'
	);

	var noConversations = document.getElementById(
		'loopbuy-no-conversations'
	);


	function getChatHistory() {
		try {
			var raw = window.localStorage.getItem(
				CHAT_HISTORY_KEY
			);

			var history = raw ? JSON.parse(raw) : [];

			if (!Array.isArray(history)) {
				return [];
			}

			return history.map(String);
		} catch (error) {
			return [];
		}
	}


	function saveChatHistory(history) {
		try {
			window.localStorage.setItem(
				CHAT_HISTORY_KEY,
				JSON.stringify(history)
			);
		} catch (error) {
			console.error(
				'Unable to save chat history.',
				error
			);
		}
	}


	function getCurrentProductId() {
		var parameters = new URLSearchParams(
			window.location.search
		);

		var productId = parameters.get('product_id');

		return productId
			? String(productId)
			: '';
	}


	/*
	 * Add the product to history only when the user visits:
	 * /messages/?product_id=...
	 */
	function addCurrentProductToHistory() {
		var currentProductId = getCurrentProductId();

		if (!currentProductId) {
			return;
		}

		var history = getChatHistory();

		history = history.filter(function (productId) {
			return productId !== currentProductId;
		});

		/*
		 * Place the newest conversation at the top.
		 */
		history.unshift(currentProductId);

		saveChatHistory(history);
	}


	function showOnlyConversationHistory() {
		var history = getChatHistory();
		var visibleCount = 0;

		/*
		 * Display products in the same order as chat history.
		 */
		history.forEach(function (historyProductId) {
			var matchingItem = document.querySelector(
				'.loopbuy-conversation-item[data-product-id="' +
				CSS.escape(historyProductId) +
				'"]'
			);

			if (!matchingItem) {
				return;
			}

			matchingItem.style.display = 'flex';

			var conversationList =
				matchingItem.parentElement;

			if (conversationList) {
				conversationList.appendChild(
					matchingItem
				);
			}

			visibleCount += 1;
		});


		/*
		 * Make sure products outside chat history stay hidden.
		 */
		conversationItems.forEach(function (item) {
			var productId = String(
				item.getAttribute('data-product-id')
			);

			if (!history.includes(productId)) {
				item.style.display = 'none';
			}
		});


		if (noConversations) {
			noConversations.style.display =
				visibleCount === 0
					? 'block'
					: 'none';
		}
	}


	addCurrentProductToHistory();
	showOnlyConversationHistory();


	/* =====================================================
	   SELECTED PRODUCT MESSAGES
	===================================================== */

	var chatPanel = document.querySelector(
		'.loopbuy-chat-panel[data-product-id]'
	);

	var messagesContainer = document.getElementById(
		'loopbuy-chat-messages'
	);

	var messageForm = document.getElementById(
		'loopbuy-chat-form'
	);

	var messageInput = document.getElementById(
		'loopbuy-message-input'
	);

	if (
		!chatPanel ||
		!messagesContainer ||
		!messageForm ||
		!messageInput
	) {
		return;
	}

	var selectedProductId = String(
		chatPanel.getAttribute('data-product-id')
	);

	var MESSAGE_STORAGE_KEY =
		'loopbuy_messages_' + selectedProductId;


	function getMessages() {
        try {
            var raw = window.localStorage.getItem(
                MESSAGE_STORAGE_KEY
            );

            var messages = raw ? JSON.parse(raw) : [];

            if (Array.isArray(messages) && messages.length > 0) {
                return messages;
            }

            var productName =
                chatPanel.getAttribute('data-product-name') ||
                'this product';

            var firstMessage = [
                {
                    text: 'Hi! Is "' + productName + '" still available?',
                    sentAt: new Date().toISOString(),
                    sender: 'buyer'
                }
            ];

            saveMessages(firstMessage);

            return firstMessage;

        } catch (error) {
            return [];
        }
    }


	function saveMessages(messages) {
		try {
			window.localStorage.setItem(
				MESSAGE_STORAGE_KEY,
				JSON.stringify(messages)
			);
		} catch (error) {
			console.error(
				'Unable to save messages.',
				error
			);
		}
	}


	function renderMessages() {
		var messages = getMessages();

		messagesContainer.innerHTML = '';

		if (messages.length === 0) {
			var emptyMessage =
				document.createElement('div');

			emptyMessage.className =
				'loopbuy-chat-empty';

			var emptyText =
				document.createElement('p');

			emptyText.textContent =
				'Start a conversation with the seller.';

			emptyMessage.appendChild(emptyText);
			messagesContainer.appendChild(emptyMessage);

			return;
		}


		messages.forEach(function (message) {
			var bubble =
				document.createElement('div');

			bubble.className =
				'loopbuy-message-bubble ' +
				'loopbuy-message-buyer';

			bubble.textContent = message.text;

			messagesContainer.appendChild(bubble);
		});


		messagesContainer.scrollTop =
			messagesContainer.scrollHeight;
	}


	messageForm.addEventListener(
		'submit',
		function (event) {
			event.preventDefault();

			var text = messageInput.value.trim();

			if (!text) {
				return;
			}

			var messages = getMessages();

			messages.push({
				text: text,
				sentAt: new Date().toISOString()
			});

			saveMessages(messages);

			messageInput.value = '';

			renderMessages();
		}
	);


	renderMessages();

});
</script>

<?php
get_footer();
