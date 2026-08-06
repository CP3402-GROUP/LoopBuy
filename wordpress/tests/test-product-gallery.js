'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function element(attributes) {
	const listeners = {};
	const classes = new Set();

	return {
		attributes: Object.assign({}, attributes),
		listeners,
		classList: {
			toggle(name, enabled) {
				if (enabled) {
					classes.add(name);
				} else {
					classes.delete(name);
				}
			},
			contains(name) {
				return classes.has(name);
			},
		},
		addEventListener(name, handler) {
			listeners[name] = handler;
		},
		getAttribute(name) {
			return this.attributes[name] || null;
		},
		setAttribute(name, value) {
			this.attributes[name] = String(value);
		},
		focus() {
			this.focused = true;
		},
	};
}

const main = element();
const counter = element();
const previous = element();
const next = element();
const first = element({
	'data-gallery-src': '/media/listings/13/first.jpg',
	'data-gallery-alt': 'Guitar, photo 1 of 2',
});
const second = element({
	'data-gallery-src': '/media/listings/13/second.jpg',
	'data-gallery-alt': 'Guitar, photo 2 of 2',
});
const thumbnails = [first, second];
const gallery = {
	querySelector(selector) {
		return {
			'[data-gallery-main]': main,
			'[data-gallery-count]': counter,
			'[data-gallery-prev]': previous,
			'[data-gallery-next]': next,
		}[selector] || null;
	},
	querySelectorAll(selector) {
		return selector === '[data-gallery-thumbnail]' ? thumbnails : [];
	},
};
const document = {
	addEventListener(name, handler) {
		assert.equal(name, 'DOMContentLoaded');
		handler();
	},
	querySelectorAll(selector) {
		return selector === '[data-product-gallery]' ? [gallery] : [];
	},
};

const scriptPath = path.join(
	__dirname,
	'..',
	'wp-content',
	'themes',
	'LoopBuy',
	'js',
	'product-gallery.js'
);
vm.runInNewContext(fs.readFileSync(scriptPath, 'utf8'), { document });

second.listeners.click();
assert.equal(main.src, '/media/listings/13/second.jpg');
assert.equal(main.alt, 'Guitar, photo 2 of 2');
assert.equal(counter.textContent, '2 / 2');
assert.equal(second.attributes['aria-pressed'], 'true');
assert.equal(first.attributes.tabindex, '-1');

let prevented = false;
second.listeners.keydown({
	key: 'ArrowLeft',
	preventDefault() {
		prevented = true;
	},
});
assert.equal(prevented, true);
assert.equal(main.src, '/media/listings/13/first.jpg');
assert.equal(first.focused, true);

previous.listeners.click();
assert.equal(main.src, '/media/listings/13/second.jpg');
next.listeners.click();
assert.equal(main.src, '/media/listings/13/first.jpg');

process.stdout.write('PASS: product gallery interaction contract\n');
