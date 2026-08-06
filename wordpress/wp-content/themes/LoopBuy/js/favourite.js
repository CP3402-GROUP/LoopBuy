(function () {
	"use strict";

	var LEGACY_STORAGE_KEY = "loopbuy_saved_products";
	var OBSOLETE_STORAGE_KEY = "loopbuy-favourites";
	var MAX_LEGACY_IDS = 100;

	function removeObsoleteStorage() {
		try {
			window.localStorage.removeItem(OBSOLETE_STORAGE_KEY);
		} catch (error) {
			// Storage may be unavailable in privacy-restricted browsers.
		}
	}

	function normaliseId(value) {
		var id = Number(value);

		return Number.isSafeInteger(id) && id > 0 ? id : null;
	}

	function normaliseIds(values) {
		var seen = Object.create(null);
		var ids = [];

		if (!Array.isArray(values)) {
			return ids;
		}

		values.forEach(function (value) {
			var id = normaliseId(value);

			if (id !== null && !seen[id]) {
				seen[id] = true;
				ids.push(id);
			}
		});

		return ids;
	}

	function readLegacyIds() {
		var values;

		try {
			var raw = window.localStorage.getItem(LEGACY_STORAGE_KEY);
			values = raw ? JSON.parse(raw) : [];
		} catch (error) {
			return [];
		}

		var ids = normaliseIds(values);

		if (ids.length > MAX_LEGACY_IDS) {
			throw new Error("Too many legacy saved listings to import safely.");
		}

		return ids;
	}

	function clearLegacyIds() {
		try {
			window.localStorage.removeItem(LEGACY_STORAGE_KEY);
			return true;
		} catch (error) {
			// A successful server import remains valid even if local cleanup fails.
			return false;
		}
	}

	function responseMessage(response, payload) {
		if (payload && typeof payload.detail === "string" && payload.detail.trim()) {
			return payload.detail.trim();
		}

		if (response.status === 401) {
			return "Please log in again to update saved listings.";
		}

		if (response.status === 403) {
			return "Your protected session expired. Reload the page and try again.";
		}

		if (response.status === 404) {
			return "This listing is no longer available.";
		}

		if (response.status === 429) {
			return "Too many requests. Please wait a moment and try again.";
		}

		return "Saved listings could not be updated right now.";
	}

	async function requestJSON(url, options) {
		var response;
		var payload = null;

		try {
			response = await window.fetch(url, options);
		} catch (error) {
			throw new Error("Saved listings are temporarily unavailable.");
		}

		try {
			payload = await response.json();
		} catch (error) {
			payload = null;
		}

		if (!response.ok) {
			var requestError = new Error(responseMessage(response, payload));
			requestError.status = response.status;
			throw requestError;
		}

		return payload;
	}

	document.addEventListener("DOMContentLoaded", function () {
		removeObsoleteStorage();

		var config = window.loopbuyFavourites || {};
		var endpoint = typeof config.endpoint === "string"
			? config.endpoint.replace(/\/+$/, "")
			: "";
		var csrf = typeof config.csrf === "string" ? config.csrf : "";
		var authenticated = config.authenticated === true;
		var loginUrl = typeof config.loginUrl === "string" ? config.loginUrl : "";
		var savedIds = new Set(normaliseIds(config.initialIds));
		var pendingIds = new Set();
		var initialising = authenticated;
		var buttons = document.querySelectorAll(
			".favourite-button, .detail-favourite-button"
		);
		var savedCards = document.querySelectorAll(".saved-product-card");
		var emptyMessage = document.getElementById("saved-empty-message");
		var statusNodes = Array.prototype.slice.call(
			document.querySelectorAll("[data-favourites-status]")
		);
		var statusTimer = null;

		function ensureStatusNode() {
			if (statusNodes.length > 0) {
				return;
			}

			var node = document.createElement("p");
			node.className = "loopbuy-favourites-toast";
			node.setAttribute("data-favourites-status", "");
			node.setAttribute("role", "status");
			node.setAttribute("aria-live", "polite");
			document.body.appendChild(node);
			statusNodes.push(node);
		}

		function announce(message, state) {
			if (message) {
				ensureStatusNode();
			}

			statusNodes.forEach(function (node) {
				node.textContent = message || "";
				node.dataset.state = state || "";
			});

			if (statusTimer !== null) {
				window.clearTimeout(statusTimer);
				statusTimer = null;
			}

			if (message && state === "success") {
				statusTimer = window.setTimeout(function () {
					announce("", "");
				}, 4500);
			}
		}

		function buttonId(button) {
			return normaliseId(button.getAttribute("data-product-id"));
		}

		function renderButton(button) {
			var id = buttonId(button);
			var isSaved = id !== null && savedIds.has(id);
			var isPending = id !== null && pendingIds.has(id);
			var isDetail = button.classList.contains("detail-favourite-button");

			button.classList.toggle("active", isSaved);
			button.classList.toggle("saved", isSaved);
			button.textContent = isDetail
				? (isSaved ? "\u2665 Saved" : "\u2661 Save")
				: (isSaved ? "\u2665" : "\u2661");
			button.setAttribute("aria-pressed", isSaved ? "true" : "false");
			button.setAttribute(
				"aria-label",
				isSaved ? "Remove saved product" : "Save product"
			);
			button.disabled = initialising || isPending;

			if (initialising || isPending) {
				button.setAttribute("aria-busy", "true");
			} else {
				button.removeAttribute("aria-busy");
			}
		}

		function renderSavedPage() {
			var visibleCount = 0;

			savedCards.forEach(function (card) {
				var id = normaliseId(card.getAttribute("data-product-id"));
				var isVisible = id !== null && (savedIds.has(id) || pendingIds.has(id));

				card.style.display = isVisible ? "" : "none";
				if (isVisible) {
					visibleCount += 1;
				}
			});

			if (emptyMessage) {
				emptyMessage.style.display = visibleCount === 0 ? "" : "none";
			}
		}

		function render() {
			buttons.forEach(renderButton);
			document.querySelectorAll("[data-saved-count]").forEach(function (badge) {
				badge.textContent = savedIds.size > 99 ? "99+" : String(savedIds.size);
				badge.hidden = savedIds.size === 0;
			});
			renderSavedPage();

			window.dispatchEvent(
				new CustomEvent("loopbuy:favourites-changed", {
					detail: { ids: Array.from(savedIds) }
				})
			);
		}

		function mutationUrl(id) {
			return endpoint + "/" + encodeURIComponent(String(id));
		}

		async function setFavourite(id, shouldSave) {
			var payload = await requestJSON(mutationUrl(id), {
				method: "POST",
				credentials: "same-origin",
				headers: {
					Accept: "application/json",
					"Content-Type": "application/json",
					"X-LoopBuy-CSRF": csrf
				},
				body: JSON.stringify({ saved: shouldSave })
			});

			if (
				!payload ||
				normaliseId(payload.listing_id) !== id ||
				payload.saved !== shouldSave
			) {
				throw new Error("Saved listings returned an invalid response.");
			}

			return payload;
		}

		async function toggleFavourite(button) {
			var id = buttonId(button);

			if (id === null || pendingIds.has(id)) {
				return;
			}

			if (!authenticated) {
				announce("Please log in to save listings.", "error");
				if (loginUrl) {
					window.location.assign(loginUrl);
				}
				return;
			}

			if (!endpoint || !csrf) {
				announce("Your protected session is unavailable. Reload the page.", "error");
				return;
			}

			var wasSaved = savedIds.has(id);
			var shouldSave = !wasSaved;

			pendingIds.add(id);
			if (shouldSave) {
				savedIds.add(id);
			} else {
				savedIds.delete(id);
			}
			announce(shouldSave ? "Saving listing..." : "Removing saved listing...", "pending");
			render();

			try {
				await setFavourite(id, shouldSave);
				announce(shouldSave ? "Listing saved." : "Listing removed from Saved.", "success");
			} catch (error) {
				if (wasSaved) {
					savedIds.add(id);
				} else {
					savedIds.delete(id);
				}
				announce(
					error && error.message
						? error.message
						: "Saved listings could not be updated right now.",
					"error"
				);

				if (error && error.status === 401) {
					authenticated = false;
				}
			} finally {
				pendingIds.delete(id);
				render();
			}
		}

		async function refreshFromServer() {
			var payload = await requestJSON(endpoint, {
				method: "GET",
				credentials: "same-origin",
				headers: { Accept: "application/json" }
			});

			if (!payload || !Array.isArray(payload.items) || !Array.isArray(payload.ids)) {
				throw new Error("Saved listings returned an invalid response.");
			}

			savedIds = new Set(normaliseIds(payload.ids));
			render();
		}

		async function importLegacyFavourites() {
			var legacyIds = readLegacyIds();

			if (legacyIds.length === 0) {
				clearLegacyIds();
				return;
			}

			for (var index = 0; index < legacyIds.length; index += 1) {
				var id = legacyIds[index];
				await setFavourite(id, true);
				savedIds.add(id);
				render();
			}

			var legacyCleared = clearLegacyIds();

			if (emptyMessage && legacyCleared) {
				window.location.reload();
				return;
			}

			announce(
				legacyCleared
					? "Saved listings were moved to your account."
					: "Saved listings were imported, but local cleanup was blocked.",
				legacyCleared ? "success" : "error"
			);
		}

		buttons.forEach(function (button) {
			button.addEventListener("click", function (event) {
				event.preventDefault();
				event.stopPropagation();
				toggleFavourite(button);
			});
		});

		render();

		if (!authenticated) {
			initialising = false;
			render();
			return;
		}

		if (!endpoint) {
			initialising = false;
			announce("Saved listings are temporarily unavailable.", "error");
			render();
			return;
		}

		(async function initialise() {
			try {
				await refreshFromServer();
				await importLegacyFavourites();
			} catch (error) {
				if (error && error.status === 401) {
					authenticated = false;
				}

				announce(
					error && error.message
						? error.message
						: "Saved listings could not be loaded right now.",
					"error"
				);
			} finally {
				initialising = false;
				render();
			}
		})();
	});
})();
