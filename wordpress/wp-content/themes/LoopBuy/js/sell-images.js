(function () {
	"use strict";

	var picker = document.getElementById("loopbuy-photo-picker");

	if (!picker) {
		return;
	}

	var input = document.getElementById("loopbuy-sell-photos");
	var dropzone = document.getElementById("loopbuy-photo-dropzone");
	var uploadTitle = document.getElementById("loopbuy-photo-upload-title");
	var selection = document.getElementById("loopbuy-photo-selection");
	var count = document.getElementById("loopbuy-photo-count");
	var preview = document.getElementById("loopbuy-photo-preview");
	var clearButton = document.getElementById("loopbuy-photo-clear");
	var status = document.getElementById("loopbuy-photo-status");
	var form = picker.closest("form");
	var maxFiles = Number.parseInt(picker.dataset.maxFiles, 10) || 10;
	var existingFiles = Math.max(0, Number.parseInt(picker.dataset.existingFiles, 10) || 0);
	var totalFileLimit = existingFiles + maxFiles;
	var maxSize = Number.parseInt(picker.dataset.maxSizeBytes, 10) || 8 * 1024 * 1024;
	var selectedFiles = [];
	var previewUrls = [];
	var acceptedTypes = ["image/jpeg", "image/png", "image/webp", "image/gif"];
	var acceptedExtension = /\.(?:jpe?g|png|webp|gif)$/i;
	var canTransferFiles = supportsFileTransfer();

	function supportsFileTransfer() {
		if (typeof window.DataTransfer !== "function") {
			return false;
		}

		try {
			var transfer = new window.DataTransfer();
			var testInput = document.createElement("input");

			testInput.type = "file";
			testInput.files = transfer.files;

			return Boolean(transfer.items && typeof transfer.items.add === "function");
		} catch (error) {
			return false;
		}
	}

	function fileKey(file) {
		return [file.name, file.size, file.lastModified, file.type].join(":");
	}

	function isAcceptedType(file) {
		return acceptedTypes.indexOf(file.type.toLowerCase()) !== -1 || (!file.type && acceptedExtension.test(file.name));
	}

	function formatSize(bytes) {
		if (bytes < 1024 * 1024) {
			return Math.max(1, Math.round(bytes / 1024)) + " KB";
		}

		return (bytes / (1024 * 1024)).toFixed(1).replace(".0", "") + " MB";
	}

	function setStatus(message, state) {
		status.textContent = message;
		status.dataset.state = state || "info";
	}

	function revokePreviewUrls() {
		previewUrls.forEach(function (url) {
			window.URL.revokeObjectURL(url);
		});
		previewUrls = [];
	}

	function syncInputFiles() {
		if (!canTransferFiles) {
			return false;
		}

		try {
			var transfer = new window.DataTransfer();

			selectedFiles.forEach(function (file) {
				transfer.items.add(file);
			});
			input.files = transfer.files;
		} catch (error) {
			canTransferFiles = false;
			return false;
		}

		return true;
	}

	function makeRemoveButton(file, index) {
		var button = document.createElement("button");

		button.type = "button";
		button.className = "loopbuy-photo-remove";
		button.setAttribute("aria-label", "Remove " + file.name);
		button.title = "Remove " + file.name;
		button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17"/></svg>';
		button.hidden = !canTransferFiles;
		button.addEventListener("click", function () {
			selectedFiles.splice(index, 1);

			if (!syncInputFiles()) {
				selectedFiles = Array.prototype.slice.call(input.files || []);
				render();
				setStatus("This browser cannot remove one file at a time. Use Clear all and choose the photos again.", "error");
				return;
			}

			render("Removed " + file.name + ".");
		});

		return button;
	}

	function makePreviewCard(file, index) {
		var item = document.createElement("li");
		var media = document.createElement("div");
		var image = document.createElement("img");
		var details = document.createElement("div");
		var name = document.createElement("span");
		var size = document.createElement("span");
		var url = window.URL.createObjectURL(file);

		previewUrls.push(url);
		item.className = "loopbuy-photo-preview-card";
		media.className = "loopbuy-photo-preview-media";
		image.src = url;
		image.alt = "Preview of " + file.name;
		image.loading = "lazy";
		image.addEventListener("error", function () {
			item.classList.add("has-preview-error");
		});

		if (index === 0 && existingFiles === 0) {
			var cover = document.createElement("span");
			cover.className = "loopbuy-photo-cover-badge";
			cover.textContent = "Cover";
			media.appendChild(cover);
		}

		media.appendChild(image);
		media.appendChild(makeRemoveButton(file, index));

		details.className = "loopbuy-photo-preview-details";
		name.className = "loopbuy-photo-preview-name";
		name.textContent = file.name;
		name.title = file.name;
		size.className = "loopbuy-photo-preview-size";
		size.textContent = formatSize(file.size);
		details.appendChild(name);
		details.appendChild(size);

		item.appendChild(media);
		item.appendChild(details);

		return item;
	}

	function render(announcement) {
		var total = selectedFiles.length;

		revokePreviewUrls();
		preview.replaceChildren();

		selectedFiles.forEach(function (file, index) {
			preview.appendChild(makePreviewCard(file, index));
		});

		selection.hidden = total === 0;
		dropzone.classList.toggle("has-photos", total > 0);
		count.textContent = existingFiles > 0
			? total + (total === 1 ? " new photo" : " new photos") + " (" + (existingFiles + total) + " of " + totalFileLimit + " total)"
			: total + " of " + maxFiles + (total === 1 ? " photo selected" : " photos selected");
		uploadTitle.textContent = total === 0 ? "Choose photos" : total < maxFiles ? "Add more photos" : "Maximum of " + maxFiles + " photos selected";

		if (announcement) {
			setStatus(announcement, total > 0 ? "success" : "info");
		} else if (total > 0) {
			setStatus(total + (total === 1 ? " photo is" : " photos are") + " ready to upload with your listing.", "success");
		} else {
			setStatus("No photos selected yet.", "info");
		}
	}

	function validateIncoming(files) {
		var valid = [];
		var invalidType = [];
		var tooLarge = [];

		files.forEach(function (file) {
			if (!isAcceptedType(file)) {
				invalidType.push(file.name);
			} else if (file.size > maxSize) {
				tooLarge.push(file.name);
			} else {
				valid.push(file);
			}
		});

		return {
			valid: valid,
			invalidType: invalidType,
			tooLarge: tooLarge,
		};
	}

	function addFiles(files) {
		var result = validateIncoming(files);
		var messages = [];

		if (!canTransferFiles) {
			if (result.invalidType.length || result.tooLarge.length || result.valid.length > maxFiles) {
				input.value = "";
				selectedFiles = [];
				messages.push("Please choose no more than " + maxFiles + " valid images and try again.");
				render();
				setStatus(messages.join(" "), "error");
				return;
			}

			selectedFiles = result.valid;
			render();
			return;
		}

		var existingKeys = Object.create(null);
		var duplicateCount = 0;
		var overflowCount = 0;

		selectedFiles.forEach(function (file) {
			existingKeys[fileKey(file)] = true;
		});

		result.valid.forEach(function (file) {
			var key = fileKey(file);

			if (existingKeys[key]) {
				duplicateCount += 1;
				return;
			}

			if (selectedFiles.length >= maxFiles) {
				overflowCount += 1;
				return;
			}

			existingKeys[key] = true;
			selectedFiles.push(file);
		});

		if (!syncInputFiles()) {
			selectedFiles = Array.prototype.slice.call(input.files || []);
			render();
			setStatus("This browser cannot combine photo selections. Please choose all photos at once.", "error");
			return;
		}

		render();

		if (result.invalidType.length) {
			messages.push(result.invalidType.length + (result.invalidType.length === 1 ? " file has" : " files have") + " an unsupported format.");
		}

		if (result.tooLarge.length) {
			messages.push(result.tooLarge.length + (result.tooLarge.length === 1 ? " file is" : " files are") + " larger than " + formatSize(maxSize) + ".");
		}

		if (overflowCount) {
			messages.push(overflowCount + (overflowCount === 1 ? " file was" : " files were") + " not added because the limit is " + maxFiles + ".");
		}

		if (duplicateCount) {
			messages.push(duplicateCount + (duplicateCount === 1 ? " duplicate was" : " duplicates were") + " skipped.");
		}

		if (messages.length) {
			setStatus(messages.join(" "), "error");
		}
	}

	input.addEventListener("change", function () {
		addFiles(Array.prototype.slice.call(input.files || []));
	});

	clearButton.addEventListener("click", function () {
		selectedFiles = [];
		input.value = "";
		render("Photo selection cleared.");
		input.focus();
	});

	["dragenter", "dragover"].forEach(function (eventName) {
		dropzone.addEventListener(eventName, function (event) {
			event.preventDefault();
			dropzone.classList.add("is-dragging");
		});
	});

	["dragleave", "drop"].forEach(function (eventName) {
		dropzone.addEventListener(eventName, function (event) {
			event.preventDefault();
			dropzone.classList.remove("is-dragging");
		});
	});

	dropzone.addEventListener("drop", function (event) {
		if (!canTransferFiles) {
			setStatus("Drag and drop is not available in this browser. Please use Choose photos.", "error");
		} else if (event.dataTransfer && event.dataTransfer.files) {
			addFiles(Array.prototype.slice.call(event.dataTransfer.files));
		}
	});

	if (form) {
		form.addEventListener("submit", function () {
			if (selectedFiles.length) {
				syncInputFiles();
				setStatus(selectedFiles.length + (selectedFiles.length === 1 ? " photo is" : " photos are") + " uploading with your listing\u2026", "success");
			}
		});
	}

	window.addEventListener("pagehide", revokePreviewUrls);
	render();
})();
