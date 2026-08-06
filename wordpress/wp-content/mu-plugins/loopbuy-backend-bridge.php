<?php
/**
 * Plugin Name: LoopBuy Backend Bridge
 * Description: Read-only bridge from the WordPress storefront to the LoopBuy Go API.
 * Version: 0.1.0
 *
 * @package LoopBuy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'LOOPBUY_BACKEND_URL' ) ) {
	$loopbuy_backend_url = getenv( 'LOOPBUY_BACKEND_URL' );

	if ( ! is_string( $loopbuy_backend_url ) || '' === trim( $loopbuy_backend_url ) ) {
		$loopbuy_backend_url = 'http://api:8080';
	}

	define( 'LOOPBUY_BACKEND_URL', rtrim( trim( $loopbuy_backend_url ), '/' ) );
}

/**
 * Return the configured backend base URL, restricted to HTTP(S).
 *
 * @return string
 */
function loopbuy_backend_base_url() {
	$base_url = (string) LOOPBUY_BACKEND_URL;

	if ( ! preg_match( '#^https?://#i', $base_url ) ) {
		return 'http://api:8080';
	}

	return rtrim( $base_url, '/' );
}

/**
 * Return an optional browser-visible backend/media origin.
 *
 * When this is not configured, relative backend media is served through the
 * same-origin WordPress proxy below. This keeps internal Docker hostnames such
 * as api:8080 out of rendered HTML.
 *
 * @return string
 */
function loopbuy_backend_public_media_base_url() {
	$base_url = defined( 'LOOPBUY_MEDIA_PUBLIC_URL' )
		? (string) LOOPBUY_MEDIA_PUBLIC_URL
		: (string) getenv( 'LOOPBUY_MEDIA_PUBLIC_URL' );
	$base_url = trim( $base_url );

	if ( '' === $base_url || ! preg_match( '#^https?://#i', $base_url ) ) {
		return '';
	}

	return rtrim( esc_url_raw( $base_url, array( 'http', 'https' ) ), '/' );
}

/**
 * Canonicalize an API-owned /media path without allowing traversal.
 *
 * Absolute URLs are accepted only when they point at the configured internal
 * backend origin. External image URLs are handled separately and never passed
 * through this proxy.
 *
 * @param mixed $candidate Relative media path or internal backend URL.
 * @return string Empty string when the candidate is not a safe backend path.
 */
function loopbuy_backend_media_path( $candidate ) {
	if ( ! is_string( $candidate ) || '' === trim( $candidate ) || strlen( $candidate ) > 2048 ) {
		return '';
	}

	$candidate = trim( $candidate );
	$parts     = wp_parse_url( $candidate );

	if ( false === $parts || ! is_array( $parts ) ) {
		return '';
	}

	if ( isset( $parts['scheme'] ) || isset( $parts['host'] ) ) {
		$candidate_origin = function_exists( 'loopbuy_marketplace_origin_parts' )
			? loopbuy_marketplace_origin_parts( $candidate )
			: null;
		$backend_origin   = function_exists( 'loopbuy_marketplace_origin_parts' )
			? loopbuy_marketplace_origin_parts( loopbuy_backend_base_url() )
			: null;
		$public_media     = loopbuy_backend_public_media_base_url();
		$public_origin    = '' !== $public_media && function_exists( 'loopbuy_marketplace_origin_parts' )
			? loopbuy_marketplace_origin_parts( $public_media )
			: null;

		if ( null === $candidate_origin
			|| ( $candidate_origin !== $backend_origin && $candidate_origin !== $public_origin ) ) {
			return '';
		}
	}

	$path = isset( $parts['path'] ) && is_string( $parts['path'] ) ? $parts['path'] : '';

	if ( 0 !== strpos( $path, '/media/' ) ) {
		return '';
	}

	$segments = explode( '/', substr( $path, strlen( '/media/' ) ) );
	$encoded  = array();

	foreach ( $segments as $segment ) {
		$decoded = rawurldecode( $segment );

		if ( '' === $decoded
			|| '.' === $decoded
			|| '..' === $decoded
			|| preg_match( '/[\\x00-\\x1F\\x7F\\\\\/]/', $decoded ) ) {
			return '';
		}

		$encoded[] = rawurlencode( $decoded );
	}

	return '/media/' . implode( '/', $encoded );
}

/**
 * Turn a safe internal media path into a browser-visible URL.
 *
 * @param string $media_path Canonical /media path.
 * @return string
 */
function loopbuy_backend_public_media_url( $media_path ) {
	$media_path = loopbuy_backend_media_path( $media_path );

	if ( '' === $media_path ) {
		return '';
	}

	$public_base = loopbuy_backend_public_media_base_url();

	if ( '' !== $public_base ) {
		return esc_url_raw( $public_base . $media_path, array( 'http', 'https' ) );
	}

	return add_query_arg(
		'loopbuy_media',
		substr( $media_path, strlen( '/media/' ) ),
		home_url( '/' )
	);
}

/**
 * Resolve a backend image candidate for safe use in storefront HTML.
 *
 * @param mixed $candidate Raw image URL from the API.
 * @return string
 */
function loopbuy_backend_public_image_url( $candidate ) {
	$media_path = loopbuy_backend_media_path( $candidate );

	if ( '' !== $media_path ) {
		return loopbuy_backend_public_media_url( $media_path );
	}

	if ( ! is_string( $candidate ) || ! preg_match( '#^https?://#i', trim( $candidate ) ) ) {
		return '';
	}

	return esc_url_raw( trim( $candidate ), array( 'http', 'https' ) );
}

/**
 * Serve one API-owned image through a bounded same-origin proxy.
 *
 * The requested upstream path is constrained to /media and redirects are
 * disabled. SVG and arbitrary content types are intentionally rejected.
 *
 * @return void
 */
function loopbuy_backend_serve_media_proxy() {
	if ( ! isset( $_GET['loopbuy_media'] ) ) {
		return;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';

	if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
		status_header( 405 );
		header( 'Allow: GET, HEAD' );
		exit;
	}

	$raw_key = is_string( $_GET['loopbuy_media'] ) ? (string) wp_unslash( $_GET['loopbuy_media'] ) : '';
	$path    = loopbuy_backend_media_path( '/media/' . ltrim( $raw_key, '/' ) );

	if ( '' === $path ) {
		status_header( 404 );
		exit;
	}

	// Validate metadata with HEAD first. GET is streamed below in bounded chunks;
	// asking the WordPress HTTP API for the body would retain the whole image in
	// each PHP worker and make a handful of parallel 8 MiB requests expensive.
	$response = wp_remote_request(
		loopbuy_backend_base_url() . $path,
		array(
			'method'              => 'HEAD',
			'timeout'             => 8,
			'redirection'         => 0,
			'headers'             => array( 'Accept' => 'image/avif,image/webp,image/png,image/jpeg,image/gif' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		status_header( 502 );
		exit;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 !== $status ) {
		status_header( 404 === $status ? 404 : 502 );
		exit;
	}

	$content_type  = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
	$content_type  = trim( explode( ';', $content_type, 2 )[0] );
	$content_length_header = trim( (string) wp_remote_retrieve_header( $response, 'content-length' ) );
	$allowed_types = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' );
	$maximum_bytes = 12 * MB_IN_BYTES;
	$cache_control = 0 === strpos( $path, '/media/demo/' )
		? 'public, max-age=31536000, immutable'
		: 'private, no-store';

	if ( ! in_array( $content_type, $allowed_types, true )
		|| ! ctype_digit( $content_length_header )
		|| (int) $content_length_header < 1
		|| (int) $content_length_header > $maximum_bytes ) {
		status_header( 502 );
		exit;
	}

	if ( 'HEAD' === $method ) {
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Length: ' . $content_length_header );
		header( 'Cache-Control: ' . $cache_control );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cross-Origin-Resource-Policy: same-origin' );
		exit;
	}

	$context = stream_context_create(
		array(
			'http' => array(
				'method'          => 'GET',
				'timeout'         => 8,
				'follow_location' => 0,
				'max_redirects'   => 0,
				'ignore_errors'   => true,
				'header'          => "Accept: image/avif,image/webp,image/png,image/jpeg,image/gif\r\nConnection: close\r\n",
			),
		)
	);
	$stream  = @fopen( loopbuy_backend_base_url() . $path, 'rb', false, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- converted to a controlled 502.

	if ( false === $stream ) {
		status_header( 502 );
		exit;
	}

	$metadata      = stream_get_meta_data( $stream );
	$stream_status = 0;
	$stream_type   = '';
	$stream_length = '';
	foreach ( (array) ( $metadata['wrapper_data'] ?? array() ) as $header_line ) {
		$header_line = trim( (string) $header_line );
		if ( preg_match( '#^HTTP/\S+\s+(\d{3})#i', $header_line, $matches ) ) {
			$stream_status = (int) $matches[1];
		} elseif ( 0 === stripos( $header_line, 'Content-Type:' ) ) {
			$stream_type = strtolower( trim( explode( ';', substr( $header_line, 13 ), 2 )[0] ) );
		} elseif ( 0 === stripos( $header_line, 'Content-Length:' ) ) {
			$stream_length = trim( substr( $header_line, 15 ) );
		}
	}

	if ( 200 !== $stream_status || $stream_type !== $content_type || $stream_length !== $content_length_header ) {
		fclose( $stream );
		status_header( 502 );
		exit;
	}

	header( 'Content-Type: ' . $content_type );
	header( 'Content-Length: ' . $content_length_header );
	header( 'Cache-Control: ' . $cache_control );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Cross-Origin-Resource-Policy: same-origin' );

	if ( function_exists( 'wp_ob_end_flush_all' ) ) {
		wp_ob_end_flush_all();
	}
	$remaining = (int) $content_length_header;
	while ( $remaining > 0 && ! feof( $stream ) ) {
		$chunk = fread( $stream, min( 65536, $remaining ) );
		if ( false === $chunk || '' === $chunk ) {
			break;
		}
		$remaining -= strlen( $chunk );
		echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- validated binary image bytes.
	}
	fclose( $stream );

	exit;
}

add_action( 'template_redirect', 'loopbuy_backend_serve_media_proxy', 0 );

/**
 * Determine whether an array is a JSON-style list.
 *
 * @param array $value Value to inspect.
 * @return bool
 */
function loopbuy_backend_is_list( $value ) {
	if ( function_exists( 'array_is_list' ) ) {
		return array_is_list( $value );
	}

	if ( array() === $value ) {
		return true;
	}

	return array_keys( $value ) === range( 0, count( $value ) - 1 );
}

/**
 * Extract listing rows from supported API envelopes.
 *
 * Supported response shapes are a direct JSON list, {"items": [...]},
 * {"data": [...]}, and {"data": {"items": [...]}}.
 *
 * @param mixed $payload Decoded JSON response.
 * @return array|WP_Error
 */
function loopbuy_backend_extract_listing_items( $payload ) {
	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'loopbuy_backend_invalid_payload', 'The listings response is not a JSON object or list.' );
	}

	if ( loopbuy_backend_is_list( $payload ) ) {
		return $payload;
	}

	if ( isset( $payload['items'] ) && is_array( $payload['items'] ) && loopbuy_backend_is_list( $payload['items'] ) ) {
		return $payload['items'];
	}

	if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
		if ( loopbuy_backend_is_list( $payload['data'] ) ) {
			return $payload['data'];
		}

		if ( isset( $payload['data']['items'] ) && is_array( $payload['data']['items'] ) && loopbuy_backend_is_list( $payload['data']['items'] ) ) {
			return $payload['data']['items'];
		}
	}

	return new WP_Error( 'loopbuy_backend_invalid_envelope', 'The listings response does not contain an items list.' );
}

/**
 * Extract one listing from a supported detail response.
 *
 * The Go API currently returns the listing object directly. A single "item"
 * or "data" envelope is also accepted so the bridge remains compatible with
 * common API gateways. A successful empty response is authoritative and is
 * represented by null; non-empty JSON lists are rejected as a contract error.
 *
 * @param mixed $payload Decoded JSON response.
 * @return array|null|WP_Error
 */
function loopbuy_backend_extract_listing_item( $payload ) {
	if ( null === $payload || array() === $payload ) {
		return null;
	}

	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'loopbuy_backend_invalid_detail_payload', 'The listing response is not a JSON object.' );
	}

	if ( loopbuy_backend_is_list( $payload ) ) {
		return new WP_Error( 'loopbuy_backend_invalid_detail_envelope', 'The listing response unexpectedly contains a list.' );
	}

	foreach ( array( 'item', 'data' ) as $envelope_key ) {
		if ( ! array_key_exists( $envelope_key, $payload ) ) {
			continue;
		}

		$item = $payload[ $envelope_key ];

		if ( null === $item || array() === $item ) {
			return null;
		}

		if ( ! is_array( $item ) || loopbuy_backend_is_list( $item ) ) {
			return new WP_Error( 'loopbuy_backend_invalid_detail_envelope', 'The listing envelope does not contain one listing object.' );
		}

		return $item;
	}

	return $payload;
}

/**
 * Read a string value without accepting arrays or objects.
 *
 * @param mixed  $value    Raw value.
 * @param string $fallback Fallback value.
 * @return string
 */
function loopbuy_backend_string( $value, $fallback = '' ) {
	if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
		return $fallback;
	}

	$value = trim( sanitize_text_field( (string) $value ) );

	return '' === $value ? $fallback : $value;
}

/**
 * Normalize every usable image in a listing DTO for storefront rendering.
 *
 * The Go API returns `images[]` with `image_url`, `sort_order`, and
 * `is_primary`. Legacy single-image fields are accepted only as a fallback.
 * URLs are converted to the same-origin media proxy and deduplicated before
 * they reach HTML.
 *
 * @param array $listing Listing DTO.
 * @return array<int,array{image_id:int,image_url:string,sort_order:int,is_primary:bool}>
 */
function loopbuy_backend_listing_images( $listing ) {
	if ( ! is_array( $listing ) ) {
		return array();
	}

	$raw_images = array();

	if ( isset( $listing['images'] ) && is_array( $listing['images'] ) ) {
		foreach ( $listing['images'] as $position => $image ) {
			if ( ! is_array( $image ) ) {
				continue;
			}

			$raw_images[] = array(
				'image_id'   => isset( $image['image_id'] ) ? absint( $image['image_id'] ) : 0,
				'image_url'  => isset( $image['url'] ) ? $image['url'] : ( isset( $image['image_url'] ) ? $image['image_url'] : '' ),
				'sort_order' => isset( $image['sort_order'] ) && is_numeric( $image['sort_order'] ) ? (int) $image['sort_order'] : (int) $position,
				'is_primary' => ! empty( $image['is_primary'] ),
				'position'   => (int) $position,
			);
		}
	}

	usort(
		$raw_images,
		function ( $left, $right ) {
			if ( $left['is_primary'] !== $right['is_primary'] ) {
				return $left['is_primary'] ? -1 : 1;
			}

			if ( $left['sort_order'] !== $right['sort_order'] ) {
				return $left['sort_order'] <=> $right['sort_order'];
			}

			return $left['position'] <=> $right['position'];
		}
	);

	$images = array();
	$seen   = array();

	foreach ( $raw_images as $image ) {
		$url = loopbuy_backend_public_image_url( $image['image_url'] );

		if ( '' === $url || isset( $seen[ $url ] ) ) {
			continue;
		}

		$seen[ $url ] = true;
		$images[]     = array(
			'image_id'   => $image['image_id'],
			'image_url'  => $url,
			'sort_order' => $image['sort_order'],
			'is_primary' => $image['is_primary'],
		);
	}

	if ( ! empty( $images ) ) {
		return $images;
	}

	foreach ( array( 'primary_image_url', 'image_url' ) as $field ) {
		$url = isset( $listing[ $field ] ) ? loopbuy_backend_public_image_url( $listing[ $field ] ) : '';

		if ( '' !== $url && ! isset( $seen[ $url ] ) ) {
			return array(
				array(
					'image_id'   => 0,
					'image_url'  => $url,
					'sort_order' => 0,
					'is_primary' => true,
				),
			);
		}
	}

	return array();
}

/**
 * Find the primary image URL in a listing DTO.
 *
 * @param array $listing Listing DTO.
 * @return string
 */
function loopbuy_backend_listing_image( $listing ) {
	$images = loopbuy_backend_listing_images( $listing );

	return isset( $images[0]['image_url'] ) ? $images[0]['image_url'] : '';
}

/**
 * Normalize one backend listing into the legacy product view model used by
 * the current PHP templates.
 *
 * Required DTO fields: id/listing_id, title, price, condition/item_condition,
 * and either category/category_name/category_slug. Invalid rows are rejected.
 *
 * @param array $listing Listing DTO.
 * @return array|WP_Error
 */
function loopbuy_backend_normalize_listing( $listing ) {
	if ( ! is_array( $listing ) ) {
		return new WP_Error( 'loopbuy_backend_invalid_listing', 'A listing row is not an object.' );
	}

	$raw_id = isset( $listing['id'] ) ? $listing['id'] : ( isset( $listing['listing_id'] ) ? $listing['listing_id'] : null );

	if ( ! is_int( $raw_id ) && ! ( is_string( $raw_id ) && ctype_digit( $raw_id ) ) ) {
		return new WP_Error( 'loopbuy_backend_invalid_listing_id', 'A listing has an invalid listing_id.' );
	}

	$listing_id = (int) $raw_id;

	if ( $listing_id < 1 ) {
		return new WP_Error( 'loopbuy_backend_invalid_listing_id', 'A listing has a non-positive listing_id.' );
	}

	$title = loopbuy_backend_string( isset( $listing['title'] ) ? $listing['title'] : '' );

	if ( '' === $title ) {
		return new WP_Error( 'loopbuy_backend_invalid_title', 'A listing has an empty title.' );
	}

	$raw_price = isset( $listing['price'] ) ? $listing['price'] : null;

	if ( ! is_numeric( $raw_price ) || ! is_finite( (float) $raw_price ) || (float) $raw_price < 0 ) {
		return new WP_Error( 'loopbuy_backend_invalid_price', 'A listing has an invalid price.' );
	}

	$condition_key = strtolower(
		loopbuy_backend_string(
			isset( $listing['condition'] ) ? $listing['condition'] : ( isset( $listing['item_condition'] ) ? $listing['item_condition'] : '' )
		)
	);
	$condition_key = str_replace( array( '_', ' ' ), '-', $condition_key );
	$conditions    = array(
		'new'      => 'New',
		'like-new' => 'Like New',
		'good'     => 'Good',
		'fair'     => 'Fair',
	);

	if ( ! isset( $conditions[ $condition_key ] ) ) {
		return new WP_Error( 'loopbuy_backend_invalid_condition', 'A listing has an unsupported item_condition.' );
	}

	$category_slug = loopbuy_backend_string( isset( $listing['category_slug'] ) ? $listing['category_slug'] : '' );
	$category_name = loopbuy_backend_string( isset( $listing['category_name'] ) ? $listing['category_name'] : '' );

	if ( isset( $listing['category'] ) && is_array( $listing['category'] ) ) {
		$category_slug = loopbuy_backend_string(
			isset( $listing['category']['slug'] ) ? $listing['category']['slug'] : '',
			$category_slug
		);
		$category_name = loopbuy_backend_string(
			isset( $listing['category']['name'] ) ? $listing['category']['name'] : '',
			$category_name
		);
	} elseif ( isset( $listing['category'] ) && is_string( $listing['category'] ) ) {
		$category_name = loopbuy_backend_string( $listing['category'], $category_name );
	}

	if ( '' === $category_slug && '' !== $category_name ) {
		$category_slug = sanitize_title( $category_name );
	}

	$category_slug = sanitize_title( $category_slug );

	if ( '' === $category_slug ) {
		return new WP_Error( 'loopbuy_backend_invalid_category', 'A listing has no usable category.' );
	}

	$seller_name = '';

	if ( isset( $listing['seller'] ) && is_array( $listing['seller'] ) ) {
		$seller_name = loopbuy_backend_string( isset( $listing['seller']['display_name'] ) ? $listing['seller']['display_name'] : '' );

		if ( '' === $seller_name ) {
			$seller_name = loopbuy_backend_string( isset( $listing['seller']['full_name'] ) ? $listing['seller']['full_name'] : '' );
		}

		if ( '' === $seller_name ) {
			$seller_name = loopbuy_backend_string( isset( $listing['seller']['username'] ) ? $listing['seller']['username'] : '' );
		}
	}

	$seller_name = loopbuy_backend_string(
		isset( $listing['seller_name'] ) ? $listing['seller_name'] : '',
		$seller_name
	);
	$seller_name = '' === $seller_name ? __( 'LoopBuy Seller', 'loopbuy' ) : $seller_name;

	$moderation_status = sanitize_key( loopbuy_backend_string( isset( $listing['moderation_status'] ) ? $listing['moderation_status'] : '' ) );
	$scam_label        = sanitize_key( loopbuy_backend_string( isset( $listing['scam_label'] ) ? $listing['scam_label'] : '' ) );

	if ( '' === $scam_label && isset( $listing['scam'] ) && is_array( $listing['scam'] ) ) {
		$scam_label = sanitize_key( loopbuy_backend_string( isset( $listing['scam']['label'] ) ? $listing['scam']['label'] : '' ) );
	}

	$verified          = 'approved' === $moderation_status && 'low_risk' === $scam_label;
	$safety_state      = 'unavailable';

	if ( 'not_screened' === $scam_label ) {
		$safety_state = 'disabled';
	} elseif ( $verified ) {
		$safety_state = 'approved';
	} elseif ( in_array( $moderation_status, array( 'pending', 'queued', 'processing' ), true ) ) {
		$safety_state = 'pending';
	} elseif ( '' !== $moderation_status || '' !== $scam_label ) {
		$safety_state = 'review';
	}

	$views = isset( $listing['views_count'] ) && is_numeric( $listing['views_count'] )
		? max( 0, (int) $listing['views_count'] )
		: 0;

	$images    = loopbuy_backend_listing_images( $listing );
	$image_url = isset( $images[0]['image_url'] ) ? $images[0]['image_url'] : '';

	return array(
		'id'                => $listing_id,
		'name'              => $title,
		'brand'             => loopbuy_backend_string( isset( $listing['brand'] ) ? $listing['brand'] : '' ),
		'price'             => (float) $raw_price,
		'location'          => loopbuy_backend_string( isset( $listing['location'] ) ? $listing['location'] : '' ),
		'condition'         => $conditions[ $condition_key ],
		'category'          => $category_slug,
		'category_name'     => $category_name,
		'image'             => $image_url,
		'image_url'         => $image_url,
		'images'            => $images,
		'description'       => isset( $listing['description'] ) && is_string( $listing['description'] )
			? sanitize_textarea_field( $listing['description'] )
			: '',
		'seller'            => $seller_name,
		'views'             => $views,
		'verified'          => $verified,
		'safety_state'      => $safety_state,
		'moderation_status' => $moderation_status,
		'scam_label'        => $scam_label,
		'seller_id'         => isset( $listing['seller']['id'] ) ? absint( $listing['seller']['id'] ) : ( isset( $listing['seller_id'] ) ? absint( $listing['seller_id'] ) : 0 ),
		'category_id'       => isset( $listing['category']['id'] ) ? absint( $listing['category']['id'] ) : ( isset( $listing['category_id'] ) ? absint( $listing['category_id'] ) : 0 ),
		'status'            => sanitize_key( loopbuy_backend_string( isset( $listing['status'] ) ? $listing['status'] : '' ) ),
		'currency'          => strtoupper( loopbuy_backend_string( isset( $listing['currency'] ) ? $listing['currency'] : 'SGD' ) ),
		'revision'          => isset( $listing['revision'] ) && is_numeric( $listing['revision'] ) ? max( 0, (int) $listing['revision'] ) : 0,
		'created_at'        => loopbuy_backend_string( isset( $listing['created_at'] ) ? $listing['created_at'] : '' ),
		'updated_at'        => loopbuy_backend_string( isset( $listing['updated_at'] ) ? $listing['updated_at'] : '' ),
	);
}

/**
 * Fetch and normalize one public listing by its positive numeric ID.
 *
 * A backend 404, a 204, or a successful empty payload is authoritative and
 * returns null. WP_Error is reserved for invalid arguments, transport errors,
 * unexpected HTTP responses, or malformed response contracts so callers can
 * deliberately choose whether to use legacy fixtures.
 *
 * @param int|string $listing_id Positive numeric listing ID.
 * @return array|null|WP_Error
 */
function loopbuy_backend_get_public_product( $listing_id ) {
	if ( is_int( $listing_id ) ) {
		$normalized_id = $listing_id;
	} elseif ( is_string( $listing_id ) && ctype_digit( $listing_id ) ) {
		$normalized_id = (int) $listing_id;
	} else {
		return new WP_Error( 'loopbuy_backend_invalid_listing_id_argument', 'A positive numeric listing ID is required.' );
	}

	if ( $normalized_id < 1 ) {
		return new WP_Error( 'loopbuy_backend_invalid_listing_id_argument', 'A positive numeric listing ID is required.' );
	}

	$url               = loopbuy_backend_base_url() . '/api/v1/listings/' . rawurlencode( (string) $normalized_id );
	$cache_key         = 'loopbuy_listing_' . md5( $url );
	$failure_cache_key = 'loopbuy_listing_fail_' . md5( $url );
	$cached            = get_transient( $cache_key );

	if ( is_array( $cached ) && array_key_exists( 'found', $cached ) ) {
		if ( false === $cached['found'] ) {
			return null;
		}

		if ( true === $cached['found'] && isset( $cached['item'] ) && is_array( $cached['item'] ) ) {
			return $cached['item'];
		}
	}

	if ( get_transient( $failure_cache_key ) ) {
		return new WP_Error( 'loopbuy_backend_listing_temporarily_unavailable', 'The listing API is temporarily unavailable.' );
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'             => 2.5,
			'redirection'         => 0,
			'limit_response_size' => 512 * KB_IN_BYTES,
			'headers'             => array(
				'Accept' => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		set_transient( $failure_cache_key, 1, 15 );
		do_action( 'loopbuy_backend_listing_error', $response, $normalized_id );
		return $response;
	}

	$status_code = (int) wp_remote_retrieve_response_code( $response );

	if ( 404 === $status_code || 204 === $status_code ) {
		set_transient( $cache_key, array( 'found' => false ), 15 );
		delete_transient( $failure_cache_key );
		return null;
	}

	if ( 200 !== $status_code ) {
		$error = new WP_Error( 'loopbuy_backend_listing_http_error', 'The listing API returned an unexpected status.', array( 'status' => $status_code ) );
		set_transient( $failure_cache_key, 1, 15 );
		do_action( 'loopbuy_backend_listing_error', $error, $normalized_id );
		return $error;
	}

	$body = wp_remote_retrieve_body( $response );

	if ( '' === trim( $body ) ) {
		set_transient( $cache_key, array( 'found' => false ), 15 );
		delete_transient( $failure_cache_key );
		return null;
	}

	$payload = json_decode( $body, true, 64, JSON_BIGINT_AS_STRING );

	if ( JSON_ERROR_NONE !== json_last_error() ) {
		$error = new WP_Error( 'loopbuy_backend_listing_invalid_json', 'The listing API returned invalid JSON.' );
		set_transient( $failure_cache_key, 1, 15 );
		do_action( 'loopbuy_backend_listing_error', $error, $normalized_id );
		return $error;
	}

	$item = loopbuy_backend_extract_listing_item( $payload );

	if ( is_wp_error( $item ) ) {
		set_transient( $failure_cache_key, 1, 15 );
		do_action( 'loopbuy_backend_listing_error', $item, $normalized_id );
		return $item;
	}

	if ( null === $item ) {
		set_transient( $cache_key, array( 'found' => false ), 15 );
		delete_transient( $failure_cache_key );
		return null;
	}

	$product = loopbuy_backend_normalize_listing( $item );

	if ( is_wp_error( $product ) ) {
		set_transient( $failure_cache_key, 1, 15 );
		do_action( 'loopbuy_backend_listing_error', $product, $normalized_id );
		return $product;
	}

	if ( $normalized_id !== (int) $product['id'] ) {
		$error = new WP_Error( 'loopbuy_backend_listing_id_mismatch', 'The listing response ID does not match the requested listing ID.' );
		set_transient( $failure_cache_key, 1, 15 );
		do_action( 'loopbuy_backend_listing_error', $error, $normalized_id );
		return $error;
	}

	set_transient(
		$cache_key,
		array(
			'found' => true,
			'item'  => $product,
		),
		30
	);
	delete_transient( $failure_cache_key );

	return $product;
}

/**
 * Fetch and normalize the public listing catalogue.
 *
 * A successful empty API list remains empty. WP_Error is reserved for an
 * unavailable or malformed API so the theme can intentionally use fixtures.
 *
 * @return array|WP_Error
 */
function loopbuy_backend_get_public_products() {
	$url               = add_query_arg( 'limit', 100, loopbuy_backend_base_url() . '/api/v1/listings' );
	$cache_key         = 'loopbuy_catalog_' . md5( $url );
	$failure_cache_key = 'loopbuy_catalog_fail_' . md5( $url );
	$cached            = get_transient( $cache_key );

	if ( is_array( $cached ) && isset( $cached['items'] ) && is_array( $cached['items'] ) ) {
		return $cached['items'];
	}

	if ( get_transient( $failure_cache_key ) ) {
		return new WP_Error( 'loopbuy_backend_temporarily_unavailable', 'The listings API is temporarily unavailable.' );
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'             => 2.5,
			'redirection'         => 0,
			'limit_response_size' => 2 * MB_IN_BYTES,
			'headers'             => array(
				'Accept' => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		set_transient( $failure_cache_key, 1, 15 );
		do_action( 'loopbuy_backend_catalog_error', $response );
		return $response;
	}

	$status_code = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 !== $status_code ) {
		$error = new WP_Error( 'loopbuy_backend_http_error', 'The listings API returned an unexpected status.', array( 'status' => $status_code ) );
		set_transient( $failure_cache_key, 1, 15 );
		do_action( 'loopbuy_backend_catalog_error', $error );
		return $error;
	}

	$payload = json_decode( wp_remote_retrieve_body( $response ), true, 64, JSON_BIGINT_AS_STRING );

	if ( JSON_ERROR_NONE !== json_last_error() ) {
		$error = new WP_Error( 'loopbuy_backend_invalid_json', 'The listings API returned invalid JSON.' );
		set_transient( $failure_cache_key, 1, 15 );
		do_action( 'loopbuy_backend_catalog_error', $error );
		return $error;
	}

	$items = loopbuy_backend_extract_listing_items( $payload );

	if ( is_wp_error( $items ) ) {
		set_transient( $failure_cache_key, 1, 15 );
		do_action( 'loopbuy_backend_catalog_error', $items );
		return $items;
	}

	$products = array();

	foreach ( $items as $item ) {
		$product = loopbuy_backend_normalize_listing( $item );

		if ( ! is_wp_error( $product ) ) {
			$products[] = $product;
		}
	}

	if ( ! empty( $items ) && empty( $products ) ) {
		$error = new WP_Error( 'loopbuy_backend_no_valid_listings', 'The listings API returned no valid listing rows.' );
		set_transient( $failure_cache_key, 1, 15 );
		do_action( 'loopbuy_backend_catalog_error', $error );
		return $error;
	}

	set_transient( $cache_key, array( 'items' => $products ), 30 );
	delete_transient( $failure_cache_key );

	return $products;
}
