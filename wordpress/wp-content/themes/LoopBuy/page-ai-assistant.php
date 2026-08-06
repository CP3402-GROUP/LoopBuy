<?php
/**
 * Template Name: AI Assistant
 *
 * The public-facing LoopBuy RAG shopping assistant. Browser JavaScript talks
 * only to the same-origin WordPress BFF; marketplace bearer tokens remain in
 * HttpOnly cookies and are never exposed to this template.
 *
 * @package LoopBuy
 */

$loopbuy_assistant_user = function_exists( 'loopbuy_marketplace_current_user' )
	? loopbuy_marketplace_current_user()
	: new WP_Error( 'loopbuy_marketplace_bridge_unavailable', __( 'Marketplace account service is unavailable.', 'loopbuy' ) );

$loopbuy_assistant_csrf = is_array( $loopbuy_assistant_user ) && function_exists( 'loopbuy_marketplace_csrf_token' )
	? loopbuy_marketplace_csrf_token()
	: null;

get_header();

$loopbuy_assistant_endpoint = rest_url( 'loopbuy/v1/assistant/chat' );
$loopbuy_product_base_url   = home_url( '/product-detail/' );
$loopbuy_login_url          = home_url( '/login/' );
$loopbuy_register_url       = home_url( '/register/' );
?>

<main id="primary" class="loopbuy-assistant-page">
	<div class="loopbuy-assistant-shell">
		<header class="loopbuy-assistant-hero">
			<div class="loopbuy-assistant-hero-copy">
				<p class="loopbuy-assistant-eyebrow">
					<span class="loopbuy-assistant-spark" aria-hidden="true">&#10022;</span>
					<?php esc_html_e( 'AI shopping assistant', 'loopbuy' ); ?>
				</p>
				<h1><?php esc_html_e( 'Tell me what you need. I will search LoopBuy for it.', 'loopbuy' ); ?></h1>
				<p><?php esc_html_e( 'Describe your budget, use case or must-have features. The assistant answers from current marketplace listings and links every result back to its source.', 'loopbuy' ); ?></p>
			</div>

			<div class="loopbuy-assistant-hero-mark" aria-hidden="true">
				<svg viewBox="0 0 64 64" role="img">
					<path d="M16 24h32v25a7 7 0 0 1-7 7H23a7 7 0 0 1-7-7V24Z" />
					<path d="M23 24v-4a9 9 0 0 1 18 0v4" />
					<path d="m45 7 1.6 4.4L51 13l-4.4 1.6L45 19l-1.6-4.4L39 13l4.4-1.6L45 7Z" />
				</svg>
			</div>
		</header>

		<?php if ( null === $loopbuy_assistant_user ) : ?>
			<section class="loopbuy-assistant-gate" aria-labelledby="loopbuy-assistant-gate-title">
				<div class="loopbuy-assistant-gate-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24">
						<path d="M12 3a5 5 0 1 0 0 10 5 5 0 0 0 0-10Zm-8 18a8 8 0 0 1 16 0" />
					</svg>
				</div>
				<div>
					<p class="loopbuy-assistant-kicker"><?php esc_html_e( 'Account required', 'loopbuy' ); ?></p>
					<h2 id="loopbuy-assistant-gate-title"><?php esc_html_e( 'Log in to start a live product search', 'loopbuy' ); ?></h2>
					<p><?php esc_html_e( 'Your account lets LoopBuy protect the assistant from abuse and tailor retrieval to your marketplace activity.', 'loopbuy' ); ?></p>
					<div class="loopbuy-assistant-gate-actions">
						<a class="loopbuy-assistant-primary-link" href="<?php echo esc_url( $loopbuy_login_url ); ?>"><?php esc_html_e( 'Log in', 'loopbuy' ); ?></a>
						<a class="loopbuy-assistant-secondary-link" href="<?php echo esc_url( $loopbuy_register_url ); ?>"><?php esc_html_e( 'Create account', 'loopbuy' ); ?></a>
					</div>
				</div>
			</section>
		<?php elseif ( is_wp_error( $loopbuy_assistant_user ) ) : ?>
			<section class="loopbuy-assistant-gate loopbuy-assistant-gate-error" role="alert" aria-labelledby="loopbuy-assistant-error-title">
				<div class="loopbuy-assistant-gate-icon" aria-hidden="true">!</div>
				<div>
					<p class="loopbuy-assistant-kicker"><?php esc_html_e( 'Temporarily unavailable', 'loopbuy' ); ?></p>
					<h2 id="loopbuy-assistant-error-title"><?php esc_html_e( 'The marketplace assistant could not start', 'loopbuy' ); ?></h2>
					<p><?php echo esc_html( $loopbuy_assistant_user->get_error_message() ); ?></p>
					<a class="loopbuy-assistant-secondary-link" href="<?php echo esc_url( home_url( '/ai-assistant/' ) ); ?>"><?php esc_html_e( 'Try again', 'loopbuy' ); ?></a>
				</div>
			</section>
		<?php elseif ( ! is_string( $loopbuy_assistant_csrf ) || is_wp_error( $loopbuy_assistant_csrf ) ) : ?>
			<section class="loopbuy-assistant-gate loopbuy-assistant-gate-error" role="alert" aria-labelledby="loopbuy-assistant-session-title">
				<div class="loopbuy-assistant-gate-icon" aria-hidden="true">!</div>
				<div>
					<p class="loopbuy-assistant-kicker"><?php esc_html_e( 'Session unavailable', 'loopbuy' ); ?></p>
					<h2 id="loopbuy-assistant-session-title"><?php esc_html_e( 'We could not secure this chat session', 'loopbuy' ); ?></h2>
					<p><?php esc_html_e( 'Reload the page to create a fresh protected session before sending a message.', 'loopbuy' ); ?></p>
					<a class="loopbuy-assistant-secondary-link" href="<?php echo esc_url( home_url( '/ai-assistant/' ) ); ?>"><?php esc_html_e( 'Reload assistant', 'loopbuy' ); ?></a>
				</div>
			</section>
		<?php else : ?>
			<div
				id="loopbuy-assistant-app"
				class="loopbuy-assistant-app"
				data-endpoint="<?php echo esc_url( $loopbuy_assistant_endpoint ); ?>"
				data-product-url="<?php echo esc_url( $loopbuy_product_base_url ); ?>"
				data-login-url="<?php echo esc_url( $loopbuy_login_url ); ?>"
			>
				<section class="loopbuy-assistant-chat" aria-labelledby="loopbuy-assistant-chat-title">
					<header class="loopbuy-assistant-chat-header">
						<div>
							<h2 id="loopbuy-assistant-chat-title"><?php esc_html_e( 'LoopBuy Finder', 'loopbuy' ); ?></h2>
							<p><?php esc_html_e( 'RAG search across active marketplace listings', 'loopbuy' ); ?></p>
						</div>
						<span class="loopbuy-assistant-live-status">
							<span aria-hidden="true"></span>
							<?php esc_html_e( 'Ready', 'loopbuy' ); ?>
						</span>
					</header>

					<div
						id="loopbuy-assistant-messages"
						class="loopbuy-assistant-messages"
						role="log"
						aria-live="polite"
						aria-relevant="additions text"
					>
						<article class="loopbuy-assistant-message is-assistant">
							<div class="loopbuy-assistant-avatar" aria-hidden="true">&#10022;</div>
							<div class="loopbuy-assistant-message-content">
								<p class="loopbuy-assistant-message-label"><?php esc_html_e( 'LoopBuy Finder', 'loopbuy' ); ?></p>
								<p class="loopbuy-assistant-message-text"><?php esc_html_e( 'Hi! Tell me what you are looking for, your budget and anything you care about. I will only recommend listings I can find in the current LoopBuy catalogue.', 'loopbuy' ); ?></p>
							</div>
						</article>
					</div>

					<div class="loopbuy-assistant-suggestions" aria-labelledby="loopbuy-assistant-suggestions-title">
						<p id="loopbuy-assistant-suggestions-title"><?php esc_html_e( 'Try asking', 'loopbuy' ); ?></p>
						<div>
							<button type="button" data-loopbuy-assistant-prompt="Find me a reliable laptop under $700 for university work."><?php esc_html_e( 'Laptop under $700', 'loopbuy' ); ?></button>
							<button type="button" data-loopbuy-assistant-prompt="What gaming items are available right now?"><?php esc_html_e( 'Gaming gear', 'loopbuy' ); ?></button>
							<button type="button" data-loopbuy-assistant-prompt="Show me practical home appliances in good condition."><?php esc_html_e( 'Home essentials', 'loopbuy' ); ?></button>
							<button type="button" data-loopbuy-assistant-prompt="Help me find a good-value gift under $100."><?php esc_html_e( 'Gift under $100', 'loopbuy' ); ?></button>
						</div>
					</div>

					<form id="loopbuy-assistant-form" class="loopbuy-assistant-form">
						<input
							type="hidden"
							id="loopbuy-assistant-csrf"
							value="<?php echo esc_attr( $loopbuy_assistant_csrf ); ?>"
						>
						<label for="loopbuy-assistant-input"><?php esc_html_e( 'Ask LoopBuy Finder', 'loopbuy' ); ?></label>
						<div class="loopbuy-assistant-composer">
							<textarea
								id="loopbuy-assistant-input"
								name="message"
								rows="1"
								maxlength="4000"
								required
								autocomplete="off"
								placeholder="<?php esc_attr_e( 'e.g. I need noise-cancelling headphones under $250', 'loopbuy' ); ?>"
							></textarea>
							<button type="submit" aria-label="<?php esc_attr_e( 'Send message', 'loopbuy' ); ?>">
								<svg viewBox="0 0 24 24" aria-hidden="true">
									<path d="m4 4 17 8-17 8 3-8-3-8Zm3 8h14" />
								</svg>
							</button>
						</div>
						<div class="loopbuy-assistant-form-meta">
							<span><?php esc_html_e( 'Enter to send · Shift + Enter for a new line', 'loopbuy' ); ?></span>
							<span id="loopbuy-assistant-count" aria-live="off">0 / 4000</span>
						</div>
						<p id="loopbuy-assistant-form-status" class="screen-reader-text" role="status" aria-live="polite"></p>
					</form>
				</section>

				<aside class="loopbuy-assistant-guide" aria-label="<?php esc_attr_e( 'How LoopBuy Finder works', 'loopbuy' ); ?>">
					<div class="loopbuy-assistant-guide-card">
						<p class="loopbuy-assistant-kicker"><?php esc_html_e( 'Grounded answers', 'loopbuy' ); ?></p>
						<h2><?php esc_html_e( 'Every suggestion comes with a listing source', 'loopbuy' ); ?></h2>
						<ol>
							<li><span>1</span><div><strong><?php esc_html_e( 'You describe the need', 'loopbuy' ); ?></strong><p><?php esc_html_e( 'Add a budget, condition or use case for better matches.', 'loopbuy' ); ?></p></div></li>
							<li><span>2</span><div><strong><?php esc_html_e( 'LoopBuy retrieves listings', 'loopbuy' ); ?></strong><p><?php esc_html_e( 'Semantic search finds relevant active products.', 'loopbuy' ); ?></p></div></li>
							<li><span>3</span><div><strong><?php esc_html_e( 'You verify the source', 'loopbuy' ); ?></strong><p><?php esc_html_e( 'Open a cited listing to check its details and seller.', 'loopbuy' ); ?></p></div></li>
						</ol>
					</div>

					<div class="loopbuy-assistant-safety-note">
						<svg viewBox="0 0 24 24" aria-hidden="true">
							<path d="M12 3 4.5 6v5.5c0 4.7 3.2 8 7.5 9.5 4.3-1.5 7.5-4.8 7.5-9.5V6L12 3Z" />
							<path d="m9 12 2 2 4-4" />
						</svg>
						<div>
							<strong><?php esc_html_e( 'Keep personal details out of chat', 'loopbuy' ); ?></strong>
							<p><?php esc_html_e( 'AI answers may be incomplete. Check listing details before buying and report anything suspicious.', 'loopbuy' ); ?></p>
						</div>
					</div>
				</aside>
			</div>

			<script>
			(function () {
				'use strict';

				var root = document.getElementById('loopbuy-assistant-app');
				if (!root) {
					return;
				}

				var form = document.getElementById('loopbuy-assistant-form');
				var input = document.getElementById('loopbuy-assistant-input');
				var messages = document.getElementById('loopbuy-assistant-messages');
				var count = document.getElementById('loopbuy-assistant-count');
				var status = document.getElementById('loopbuy-assistant-form-status');
				var csrfField = document.getElementById('loopbuy-assistant-csrf');
				var sendButton = form.querySelector('button[type="submit"]');
				var suggestionButtons = root.querySelectorAll('[data-loopbuy-assistant-prompt]');
				var pending = false;

				function setStatus(message) {
					status.textContent = message;
				}

				function scrollMessages() {
					messages.scrollTop = messages.scrollHeight;
				}

				function makeAvatar(role) {
					var avatar = document.createElement('div');
					avatar.className = 'loopbuy-assistant-avatar';
					avatar.setAttribute('aria-hidden', 'true');
					avatar.textContent = role === 'user' ? 'You' : '\u2726';
					return avatar;
				}

				function appendInlineFormatting(element, value) {
					var pattern = /(\*\*[^*\n]+\*\*|`[^`\n]+`)/g;
					var lastIndex = 0;
					var match;

					while ((match = pattern.exec(value)) !== null) {
						if (match.index > lastIndex) {
							element.appendChild(document.createTextNode(value.slice(lastIndex, match.index)));
						}

						var token = match[0];
						var formatted = document.createElement(token.slice(0, 2) === '**' ? 'strong' : 'code');
						formatted.textContent = token.slice(token.slice(0, 2) === '**' ? 2 : 1, token.slice(0, 2) === '**' ? -2 : -1);
						element.appendChild(formatted);
						lastIndex = pattern.lastIndex;
					}

					if (lastIndex < value.length) {
						element.appendChild(document.createTextNode(value.slice(lastIndex)));
					}
				}

				function renderAssistantText(element, value) {
					var lines = value.replace(/\r\n?/g, '\n').split('\n');
					var paragraphLines = [];
					var activeList = null;
					var activeListType = '';

					function flushParagraph() {
						if (paragraphLines.length === 0) {
							return;
						}

						var paragraph = document.createElement('p');
						appendInlineFormatting(paragraph, paragraphLines.join(' '));
						element.appendChild(paragraph);
						paragraphLines = [];
					}

					element.textContent = '';
					lines.forEach(function (rawLine) {
						var line = rawLine.trim();
						var unordered = line.match(/^[-*]\s+(.+)$/);
						var ordered = line.match(/^\d+[.)]\s+(.+)$/);
						var listMatch = unordered || ordered;
						var listType = ordered ? 'ol' : 'ul';

						if (line === '') {
							flushParagraph();
							activeList = null;
							activeListType = '';
							return;
						}

						if (listMatch) {
							flushParagraph();
							if (!activeList || activeListType !== listType) {
								activeList = document.createElement(listType);
								activeListType = listType;
								element.appendChild(activeList);
							}

							var item = document.createElement('li');
							appendInlineFormatting(item, listMatch[1]);
							activeList.appendChild(item);
							return;
						}

						activeList = null;
						activeListType = '';
						paragraphLines.push(line.replace(/^#{1,3}\s+/, ''));
					});
					flushParagraph();
				}

				function makeMessage(role, message) {
					var article = document.createElement('article');
					var content = document.createElement('div');
					var label = document.createElement('p');
					var text = document.createElement(role === 'user' ? 'p' : 'div');

					article.className = 'loopbuy-assistant-message ' + (role === 'user' ? 'is-user' : 'is-assistant');
					content.className = 'loopbuy-assistant-message-content';
					label.className = 'loopbuy-assistant-message-label';
					label.textContent = role === 'user' ? '<?php echo esc_js( __( 'You', 'loopbuy' ) ); ?>' : '<?php echo esc_js( __( 'LoopBuy Finder', 'loopbuy' ) ); ?>';
					text.className = 'loopbuy-assistant-message-text';
					if (role === 'user') {
						text.textContent = message;
					} else {
						renderAssistantText(text, message);
					}

					content.appendChild(label);
					content.appendChild(text);
					article.appendChild(makeAvatar(role));
					article.appendChild(content);
					messages.appendChild(article);
					scrollMessages();
					return article;
				}

				function makeLoadingMessage() {
					var article = makeMessage('assistant', '<?php echo esc_js( __( 'Searching current listings…', 'loopbuy' ) ); ?>');
					article.classList.add('is-loading');
					article.setAttribute('aria-label', '<?php echo esc_js( __( 'LoopBuy Finder is searching current listings', 'loopbuy' ) ); ?>');
					return article;
				}

				function sourcePrice(source) {
					var amount = Number(source.price);
					var currency = typeof source.currency === 'string' && /^[A-Z]{3}$/.test(source.currency) ? source.currency : 'USD';
					if (!Number.isFinite(amount)) {
						return '';
					}
					try {
						return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency }).format(amount);
					} catch (error) {
						return amount.toFixed(2) + ' ' + currency;
					}
				}

				function referencedSource(answerText, sources) {
					var matcher = /(?:\blisting(?:[\s_-]+id)?\s*[:#]?\s*|#)(\d+)\b/gi;
					var match;

					while ((match = matcher.exec(answerText)) !== null) {
						var listingId = Number(match[1]);
						var source = sources.find(function (candidate) {
							return Number(candidate.listing_id) === listingId;
						});
						if (source) {
							return source;
						}
					}

					return null;
				}

				function listingUrl(source) {
					var url = new URL(root.dataset.productUrl, window.location.origin);
					url.searchParams.set('id', String(Number(source.listing_id)));
					return url.toString();
				}

				function appendSourceListItem(list, source) {
					var item = document.createElement('li');
					var link = document.createElement('a');
					var title = document.createElement('span');
					var price = document.createElement('span');

					link.href = listingUrl(source);
					title.textContent = source.title.trim();
					price.textContent = sourcePrice(source);
					link.appendChild(title);
					link.appendChild(price);
					item.appendChild(link);
					list.appendChild(item);
				}

				function appendSources(article, sources, answerText) {
					if (!Array.isArray(sources) || sources.length === 0) {
						return;
					}

					var validSources = sources.filter(function (source) {
						var id = Number(source && source.listing_id);
						return Number.isSafeInteger(id) && id > 0 && typeof source.title === 'string' && source.title.trim() !== '';
					}).slice(0, 8);

					if (validSources.length === 0) {
						return;
					}

					var container = document.createElement('div');
					var featured = referencedSource(answerText, validSources);
					var remainingSources = validSources;
					container.className = 'loopbuy-assistant-sources';

					if (featured) {
						var featuredBlock = document.createElement('div');
						var featuredLabel = document.createElement('p');
						var link = document.createElement('a');
						var summary = document.createElement('span');
						var title = document.createElement('strong');
						var price = document.createElement('span');
						var action = document.createElement('span');

						featuredBlock.className = 'loopbuy-assistant-primary-source';
						featuredLabel.textContent = '<?php echo esc_js( __( 'Recommended listing', 'loopbuy' ) ); ?>';
						link.href = listingUrl(featured);
						link.className = 'loopbuy-assistant-primary-source-link';
						title.textContent = featured.title.trim();
						price.textContent = sourcePrice(featured);
						action.textContent = '<?php echo esc_js( __( 'Open listing', 'loopbuy' ) ); ?> \u2192';
						summary.appendChild(title);
						summary.appendChild(price);
						link.appendChild(summary);
						link.appendChild(action);
						featuredBlock.appendChild(featuredLabel);
						featuredBlock.appendChild(link);
						container.appendChild(featuredBlock);
						remainingSources = validSources.filter(function (source) {
							return Number(source.listing_id) !== Number(featured.listing_id);
						});
					}

					if (remainingSources.length > 0) {
						var details = document.createElement('details');
						var detailsSummary = document.createElement('summary');
						var list = document.createElement('ul');
						var summaryLabel = featured
							? '<?php echo esc_js( __( 'Other listings considered', 'loopbuy' ) ); ?>'
							: '<?php echo esc_js( __( 'Listings considered', 'loopbuy' ) ); ?>';

						details.className = 'loopbuy-assistant-source-details';
						detailsSummary.textContent = summaryLabel + ' (' + String(remainingSources.length) + ')';
						remainingSources.forEach(function (source) {
							appendSourceListItem(list, source);
						});
						details.appendChild(detailsSummary);
						details.appendChild(list);
						container.appendChild(details);
					}

					article.querySelector('.loopbuy-assistant-message-content').appendChild(container);
				}

				function appendDegradedNotice(article, warning) {
					var notice = document.createElement('p');
					notice.className = 'loopbuy-assistant-degraded';
					notice.textContent = warning || '<?php echo esc_js( __( 'AI generation is unavailable, so these are direct retrieval results.', 'loopbuy' ) ); ?>';
					article.querySelector('.loopbuy-assistant-message-content').appendChild(notice);
				}

				function readableError(response, data) {
					if (response.status === 401) {
						return '<?php echo esc_js( __( 'Your marketplace session expired. Log in again and retry.', 'loopbuy' ) ); ?>';
					}
					if (response.status === 403) {
						return '<?php echo esc_js( __( 'This protected chat session expired. Reload the page and try again.', 'loopbuy' ) ); ?>';
					}
					if (response.status === 429) {
						return '<?php echo esc_js( __( 'You have sent several requests. Please wait a moment before trying again.', 'loopbuy' ) ); ?>';
					}
					if (response.status === 422) {
						return '<?php echo esc_js( __( 'Please shorten or rephrase that request.', 'loopbuy' ) ); ?>';
					}
					if (data && typeof data.detail === 'string' && data.detail.trim() !== '') {
						return data.detail.trim();
					}
					if (data && typeof data.message === 'string' && data.message.trim() !== '') {
						return data.message.trim();
					}
					return '<?php echo esc_js( __( 'The assistant could not answer right now. Please try again.', 'loopbuy' ) ); ?>';
				}

				function setPending(nextPending) {
					pending = nextPending;
					input.disabled = nextPending;
					sendButton.disabled = nextPending;
					suggestionButtons.forEach(function (button) {
						button.disabled = nextPending;
					});
					form.setAttribute('aria-busy', nextPending ? 'true' : 'false');
				}

				async function sendMessage(rawMessage) {
					var message = rawMessage.trim();
					if (pending || message === '') {
						return;
					}

					makeMessage('user', message);
					input.value = '';
					input.style.height = '';
					count.textContent = '0 / 4000';
					setPending(true);
					setStatus('<?php echo esc_js( __( 'Searching current listings.', 'loopbuy' ) ); ?>');
					var loading = makeLoadingMessage();

					try {
						var response = await window.fetch(root.dataset.endpoint, {
							method: 'POST',
							credentials: 'same-origin',
							headers: {
								'Accept': 'application/json',
								'Content-Type': 'application/json',
								'X-LoopBuy-CSRF': csrfField.value
							},
							body: JSON.stringify({ message: message })
						});

						var data = null;
						try {
							data = await response.json();
						} catch (parseError) {
							data = null;
						}

						loading.remove();
						if (!response.ok) {
							throw new Error(readableError(response, data));
						}
						if (!data || typeof data.answer !== 'string' || data.answer.trim() === '') {
							throw new Error('<?php echo esc_js( __( 'The assistant returned an empty response. Please try again.', 'loopbuy' ) ); ?>');
						}

						var answer = makeMessage('assistant', data.answer.trim());
						appendSources(answer, data.sources, data.answer);
						if (data.degraded === true) {
							appendDegradedNotice(answer, typeof data.warning === 'string' ? data.warning : '');
						}
						scrollMessages();
						setStatus('<?php echo esc_js( __( 'LoopBuy Finder answered with listing sources.', 'loopbuy' ) ); ?>');
					} catch (error) {
						if (loading.isConnected) {
							loading.remove();
						}
						var messageText = error instanceof Error && error.message ? error.message : '<?php echo esc_js( __( 'The assistant could not answer right now. Please try again.', 'loopbuy' ) ); ?>';
						var errorMessage = makeMessage('assistant', messageText);
						errorMessage.classList.add('is-error');
						setStatus(messageText);
					} finally {
						setPending(false);
						input.focus();
					}
				}

				form.addEventListener('submit', function (event) {
					event.preventDefault();
					sendMessage(input.value);
				});

				input.addEventListener('keydown', function (event) {
					if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
						event.preventDefault();
						form.requestSubmit();
					}
				});

				input.addEventListener('input', function () {
					count.textContent = String(input.value.length) + ' / 4000';
					input.style.height = 'auto';
					input.style.height = Math.min(input.scrollHeight, 144) + 'px';
				});

				suggestionButtons.forEach(function (button) {
					button.addEventListener('click', function () {
						var prompt = button.getAttribute('data-loopbuy-assistant-prompt') || '';
						input.value = prompt;
						input.dispatchEvent(new Event('input'));
						input.focus();
					});
				});
			}());
			</script>
		<?php endif; ?>
	</div>
</main>

<?php
get_footer();
