<?php
/**
 * Provision the WordPress records required by the LoopBuy theme.
 *
 * This script is intentionally idempotent: existing pages and their content
 * are left untouched, while missing route pages are created and published.
 * It is executed by deployment/deploy.sh inside the WordPress container.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "ERROR: this provisioning script may only run from the CLI.\n");
	exit(1);
}

$wordpress_root = getenv('WORDPRESS_ROOT') ?: '/var/www/html';
$wp_load        = rtrim($wordpress_root, '/') . '/wp-load.php';

if (!is_readable($wp_load)) {
	fwrite(STDERR, "ERROR: WordPress bootstrap is missing or unreadable: {$wp_load}\n");
	exit(1);
}

define('WP_USE_THEMES', false);
require_once $wp_load;

if (!function_exists('is_blog_installed') || !is_blog_installed()) {
	fwrite(STDERR, "ERROR: WordPress is not installed yet; complete the initial installer first.\n");
	exit(1);
}

$required_pages = array(
	'about'          => 'About',
	'ai-assistant'   => 'AI Shopping Assistant',
	'cart'           => 'Cart',
	'contact'        => 'Contact',
	'login'          => 'Login',
	'messages'       => 'Messages',
	'my-listings'    => 'My Listings',
	'orders'         => 'Orders',
	'product-detail' => 'Product Detail',
	'profile'        => 'Profile',
	'privacy-policy' => 'Privacy Policy',
	'register'       => 'Register',
	'saved'          => 'Saved',
	'sell'           => 'Sell',
	'terms'          => 'Terms of Service',
);

$theme = wp_get_theme('LoopBuy');
if (!$theme->exists() || $theme->errors()) {
	$error_message = $theme->errors()
		? $theme->errors()->get_error_message()
		: 'theme directory was not found';
	fwrite(STDERR, "ERROR: LoopBuy theme is unavailable: {$error_message}\n");
	exit(1);
}

if (get_stylesheet() !== $theme->get_stylesheet()) {
	switch_theme($theme->get_stylesheet());

	if (get_stylesheet() !== $theme->get_stylesheet()) {
		fwrite(STDERR, "ERROR: failed to activate the LoopBuy theme.\n");
		exit(1);
	}

	printf("Activated theme: %s\n", $theme->get_stylesheet());
} else {
	printf("Theme already active: %s\n", $theme->get_stylesheet());
}

$current_site_title = trim((string) get_option('blogname'));
if ('' === $current_site_title || 'LoopBuy Smoke' === $current_site_title) {
	update_option('blogname', 'LoopBuy');
	printf("Updated site title: LoopBuy\n");
} else {
	printf("Site title already configured: %s\n", $current_site_title);
}

$administrator_ids = get_users(
	array(
		'role'    => 'administrator',
		'fields'  => 'ID',
		'number'  => 1,
		'orderby' => 'ID',
		'order'   => 'ASC',
	)
);
$author_id      = $administrator_ids ? (int) $administrator_ids[0] : 0;
$created_count  = 0;
$existing_count = 0;
$errors         = array();

foreach ($required_pages as $slug => $title) {
	$existing_page = get_page_by_path($slug, OBJECT, 'page');

	if ($existing_page instanceof WP_Post) {
		if ('trash' === $existing_page->post_status) {
			$errors[] = sprintf(
				"page '%s' exists in Trash (ID %d); restore it or permanently delete it",
				$slug,
				$existing_page->ID
			);
			continue;
		}

		// OAuth production requires public legal URLs. Preserve the existing
		// page content, but publish the two legal route records when WordPress
		// created one of them as a draft during installation.
		if (in_array($slug, array('privacy-policy', 'terms'), true) && 'publish' !== $existing_page->post_status) {
			$updated_page_id = wp_update_post(
				array(
					'ID'          => $existing_page->ID,
					'post_status' => 'publish',
				),
				true
			);

			if (is_wp_error($updated_page_id)) {
				$errors[] = sprintf(
					"failed to publish page '%s': %s",
					$slug,
					$updated_page_id->get_error_message()
				);
				continue;
			}

			$existing_page->post_status = 'publish';
			printf("Published required legal page: %s (slug=%s, ID=%d)\n", $existing_page->post_title, $slug, $existing_page->ID);
		}

		++$existing_count;
		printf(
			"Page already exists: %s (slug=%s, ID=%d, status=%s)\n",
			$existing_page->post_title,
			$slug,
			$existing_page->ID,
			$existing_page->post_status
		);
		continue;
	}

	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => '',
			'post_author'  => $author_id,
		),
		true
	);

	if (is_wp_error($page_id)) {
		$errors[] = sprintf(
			"failed to create page '%s': %s",
			$slug,
			$page_id->get_error_message()
		);
		continue;
	}

	++$created_count;
	printf("Created page: %s (slug=%s, ID=%d)\n", $title, $slug, $page_id);
}

$permalink_structure = '/%postname%/';
if (get_option('permalink_structure') !== $permalink_structure) {
	update_option('permalink_structure', $permalink_structure);
	printf("Updated permalink structure: %s\n", $permalink_structure);
} else {
	printf("Permalink structure already configured: %s\n", $permalink_structure);
}

global $wp_rewrite;
$wp_rewrite->set_permalink_structure($permalink_structure);
$wp_rewrite->flush_rules(false);

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';

$htaccess_path = trailingslashit(get_home_path()) . '.htaccess';
$rewrite_rules = explode("\n", trim($wp_rewrite->mod_rewrite_rules()));

if (!insert_with_markers($htaccess_path, 'WordPress', $rewrite_rules)) {
	$errors[] = "failed to update WordPress rewrite rules in {$htaccess_path}";
} else {
	printf("Updated rewrite rules: %s\n", $htaccess_path);
}

if ($errors) {
	foreach ($errors as $error) {
		fwrite(STDERR, "ERROR: {$error}\n");
	}
	exit(1);
}

printf(
	"Provisioning completed successfully: created=%d, existing=%d\n",
	$created_count,
	$existing_count
);
