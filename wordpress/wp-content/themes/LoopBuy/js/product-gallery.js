(function () {
	'use strict';

	function initialiseGallery(gallery) {
		var mainImage = gallery.querySelector('[data-gallery-main]');
		var counter = gallery.querySelector('[data-gallery-count]');
		var thumbnails = Array.prototype.slice.call(
			gallery.querySelectorAll('[data-gallery-thumbnail]')
		);

		if (!mainImage || thumbnails.length < 2) {
			return;
		}

		var activeIndex = 0;

		function selectImage(nextIndex, focusThumbnail) {
			var count = thumbnails.length;
			var index = (nextIndex + count) % count;
			var selected = thumbnails[index];

			mainImage.src = selected.getAttribute('data-gallery-src') || '';
			mainImage.alt = selected.getAttribute('data-gallery-alt') || '';
			activeIndex = index;

			thumbnails.forEach(function (thumbnail, thumbnailIndex) {
				var isActive = thumbnailIndex === index;
				thumbnail.classList.toggle('is-active', isActive);
				thumbnail.setAttribute('aria-pressed', isActive ? 'true' : 'false');
				thumbnail.setAttribute('tabindex', isActive ? '0' : '-1');
			});

			if (counter) {
				counter.textContent = String(index + 1) + ' / ' + String(count);
			}

			if (focusThumbnail) {
				selected.focus();
			}
		}

		thumbnails.forEach(function (thumbnail, index) {
			thumbnail.addEventListener('click', function () {
				selectImage(index, false);
			});

			thumbnail.addEventListener('keydown', function (event) {
				var nextIndex;

				switch (event.key) {
					case 'ArrowLeft':
					case 'ArrowUp':
						nextIndex = activeIndex - 1;
						break;
					case 'ArrowRight':
					case 'ArrowDown':
						nextIndex = activeIndex + 1;
						break;
					case 'Home':
						nextIndex = 0;
						break;
					case 'End':
						nextIndex = thumbnails.length - 1;
						break;
					default:
						return;
				}

				event.preventDefault();
				selectImage(nextIndex, true);
			});
		});

		var previous = gallery.querySelector('[data-gallery-prev]');
		var next = gallery.querySelector('[data-gallery-next]');

		if (previous) {
			previous.addEventListener('click', function () {
				selectImage(activeIndex - 1, false);
			});
		}

		if (next) {
			next.addEventListener('click', function () {
				selectImage(activeIndex + 1, false);
			});
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('[data-product-gallery]').forEach(initialiseGallery);
	});
}());
