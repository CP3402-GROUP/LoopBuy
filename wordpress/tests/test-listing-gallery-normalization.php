<?php
/**
 * Focused CLI contract test for listing image normalization.
 *
 * Run from the repository root:
 * php wordpress/tests/test-listing-gallery-normalization.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'LOOPBUY_MEDIA_PUBLIC_URL', 'https://media.loopbuy.test' );

function add_action() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return true;
}

function wp_parse_url( $url, $component = -1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return parse_url( $url, $component );
}

function esc_url_raw( $url ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return is_string( $url ) && preg_match( '#^https?://#i', $url ) ? $url : '';
}

function absint( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return abs( (int) $value );
}

require dirname( __DIR__ ) . '/wp-content/mu-plugins/loopbuy-backend-bridge.php';

function loopbuy_gallery_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

$images = loopbuy_backend_listing_images(
	array(
		'image_url' => '/media/listings/13/stale.jpg',
		'images'    => array(
			array(
				'image_id'   => 21,
				'image_url'  => '/media/listings/13/second.jpg',
				'sort_order' => 1,
				'is_primary' => false,
			),
			array(
				'image_id'   => 20,
				'image_url'  => '/media/listings/13/cover.jpg',
				'sort_order' => 0,
				'is_primary' => true,
			),
			array(
				'image_id'   => 22,
				'image_url'  => '/media/listings/13/second.jpg',
				'sort_order' => 2,
				'is_primary' => false,
			),
		),
	)
);

loopbuy_gallery_test_assert( 2 === count( $images ), 'gallery did not preserve and deduplicate all DTO images' );
loopbuy_gallery_test_assert( true === $images[0]['is_primary'], 'primary image was not sorted first' );
loopbuy_gallery_test_assert(
	'https://media.loopbuy.test/media/listings/13/cover.jpg' === $images[0]['image_url'],
	'internal cover URL was not converted to the public media origin'
);
loopbuy_gallery_test_assert(
	'https://media.loopbuy.test/media/listings/13/second.jpg' === $images[1]['image_url'],
	'second image was not preserved'
);

$fallback = loopbuy_backend_listing_images( array( 'image_url' => 'https://cdn.example.test/photo.jpg' ) );
loopbuy_gallery_test_assert( 1 === count( $fallback ), 'legacy single-image fallback was lost' );
loopbuy_gallery_test_assert( 'https://cdn.example.test/photo.jpg' === $fallback[0]['image_url'], 'external fallback URL changed' );
loopbuy_gallery_test_assert( array() === loopbuy_backend_listing_images( array() ), 'empty listing did not produce an empty gallery' );

fwrite( STDOUT, "PASS: listing gallery normalization contract\n" );
