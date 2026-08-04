document.addEventListener("DOMContentLoaded", function () {

	let favourites =
		JSON.parse(
			localStorage.getItem("loopbuy-favourites")
		) || [];

	const buttons =
		document.querySelectorAll(".favourite-button");

	const savedCards =
		document.querySelectorAll(".saved-product-card");

	const emptyMessage =
		document.getElementById("saved-empty-message");


	function saveFavourites() {

		localStorage.setItem(
			"loopbuy-favourites",
			JSON.stringify(favourites)
		);

	}


	function updateButtons() {

		buttons.forEach(function (button) {

			const productId =
				parseInt(button.dataset.productId, 10);

			if (favourites.includes(productId)) {

				button.classList.add("active");
				button.textContent = "♥";
				button.setAttribute(
					"aria-label",
					"Remove saved product"
				);

			} else {

				button.classList.remove("active");
				button.textContent = "♡";
				button.setAttribute(
					"aria-label",
					"Save product"
				);

			}

		});

	}


	function updateSavedPage() {

		if (!savedCards.length) {
			return;
		}

		let visibleCount = 0;

		savedCards.forEach(function (card) {

			const productId =
				parseInt(card.dataset.productId, 10);

			if (favourites.includes(productId)) {

				card.style.display = "";
				visibleCount++;

			} else {

				card.style.display = "none";

			}

		});

		if (emptyMessage) {

			emptyMessage.style.display =
				visibleCount === 0
					? "block"
					: "none";

		}

	}


	buttons.forEach(function (button) {

		button.addEventListener("click", function (event) {

			event.preventDefault();
			event.stopPropagation();

			const productId =
				parseInt(button.dataset.productId, 10);

			if (favourites.includes(productId)) {

				favourites = favourites.filter(function (id) {
					return id !== productId;
				});

			} else {

				favourites.push(productId);

			}

			saveFavourites();
			updateButtons();
			updateSavedPage();

		});

	});


	updateButtons();
	updateSavedPage();

});