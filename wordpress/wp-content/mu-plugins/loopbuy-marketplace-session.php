<?php
/**
 * Plugin Name: LoopBuy Marketplace Session
 * Description: Same-origin WordPress BFF session for the LoopBuy Go marketplace API.
 * Version: 0.1.0
 *
 * WordPress administrator authentication intentionally remains independent.
 * This plugin never creates, signs in, or mutates WordPress users.
 *
 * @package LoopBuy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the internal Go API base URL.
 *
 * @return string
 */
function loopbuy_marketplace_backend_base_url() {
	if ( function_exists( 'loopbuy_backend_base_url' ) ) {
		return loopbuy_backend_base_url();
	}

	$base_url = defined( 'LOOPBUY_BACKEND_URL' )
		? (string) LOOPBUY_BACKEND_URL
		: (string) getenv( 'LOOPBUY_BACKEND_URL' );

	if ( '' === trim( $base_url ) ) {
		$base_url = 'http://api:8080';
	}

	if ( ! preg_match( '#^https?://#i', $base_url ) ) {
		return 'http://api:8080';
	}

	return rtrim( trim( $base_url ), '/' );
}

/**
 * Public Google OAuth client ID. The client secret must never be configured
 * in WordPress; the Go backend owns the authorization-code exchange.
 *
 * @return string
 */
function loopbuy_marketplace_google_client_id() {
	$client_id = defined( 'GOOGLE_CLIENT_ID' )
		? (string) GOOGLE_CLIENT_ID
		: (string) getenv( 'GOOGLE_CLIENT_ID' );
	$client_id = trim( $client_id );

	return 1 === preg_match( '/^[0-9]+-[A-Za-z0-9_-]+\.apps\.googleusercontent\.com$/D', $client_id )
		? $client_id
		: '';
}

/**
 * Exact same-origin callback registered in Google and in the Go allowlist.
 *
 * @return string
 */
function loopbuy_marketplace_google_redirect_uri() {
	return rest_url( 'loopbuy/v1/auth/google/callback' );
}

/**
 * Whether the WordPress half of Google OAuth is configured.
 *
 * @return bool
 */
function loopbuy_marketplace_google_available() {
	return '' !== loopbuy_marketplace_google_client_id();
}

/**
 * URL used by login and registration buttons to begin OAuth.
 *
 * @param string $context UI context used only for a bounded failure redirect.
 * @return string
 */
function loopbuy_marketplace_google_start_url( $context = 'login' ) {
	$context = 'register' === $context ? 'register' : 'login';

	return add_query_arg(
		array(
			'loopbuy_action' => 'google_start',
			'context'        => $context,
		),
		home_url( '/' )
	);
}

/**
 * Marketplace cookie names.
 *
 * @return array<string,string>
 */
function loopbuy_marketplace_cookie_names() {
	return array(
		'access'          => 'loopbuy_marketplace_access',
		'refresh'         => 'loopbuy_marketplace_refresh',
		'remember'        => 'loopbuy_marketplace_remember',
		'csrf'            => 'loopbuy_marketplace_csrf',
		'google_state'    => 'loopbuy_google_state',
		'google_verifier' => 'loopbuy_google_verifier',
		'google_context'  => 'loopbuy_google_context',
		'email_token'     => 'loopbuy_email_verification',
	);
}

/**
 * Read an unmodified scalar cookie value.
 *
 * Authentication tokens must not pass through text sanitizers that can alter
 * their bytes; they are validated separately before use.
 *
 * @param string $name Cookie name.
 * @return string
 */
function loopbuy_marketplace_read_cookie( $name ) {
	if ( ! isset( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
		return '';
	}

	return (string) wp_unslash( $_COOKIE[ $name ] );
}

/**
 * Validate the bounded token alphabet emitted by the Go backend.
 *
 * @param mixed $token Token candidate.
 * @return bool
 */
function loopbuy_marketplace_valid_token( $token ) {
	return is_string( $token )
		&& strlen( $token ) >= 16
		&& strlen( $token ) <= 3800
		&& 1 === preg_match( '/^[A-Za-z0-9._~-]+$/D', $token );
}

/**
 * Read a scalar backend field without accepting arrays or objects.
 *
 * @param mixed $value    Raw value.
 * @param bool  $textarea Preserve textarea-compatible newlines.
 * @return string
 */
function loopbuy_marketplace_clean_text( $value, $textarea = false ) {
	if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
		return '';
	}

	return $textarea
		? sanitize_textarea_field( (string) $value )
		: sanitize_text_field( (string) $value );
}

/**
 * Determine whether auth cookies must use Secure.
 *
 * @return bool
 */
function loopbuy_marketplace_secure_cookies() {
	return is_ssl() || 'https' === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
}

/**
 * Prevent authenticated or CSRF-bearing HTML from entering shared caches.
 *
 * @return void
 */
function loopbuy_marketplace_send_private_headers() {
	if ( headers_sent() ) {
		return;
	}

	nocache_headers();
	header( 'Cache-Control: private, no-store, max-age=0, must-revalidate', true );
	header( 'Vary: Cookie', false );
}

/**
 * Extra isolation for the email-token capture/confirmation screen.
 *
 * The token-bearing request must not leak its URL through a referrer. After
 * the immediate redirect, the clean confirmation page may send a same-origin
 * referrer so the explicit CSRF-protected POST can prove its origin.
 *
 * @param bool $token_bearing Whether the current URL contains the raw token.
 * @return void
 */
function loopbuy_marketplace_send_verification_headers( $token_bearing = false ) {
	loopbuy_marketplace_send_private_headers();

	if ( headers_sent() ) {
		return;
	}

	header( $token_bearing ? 'Referrer-Policy: no-referrer' : 'Referrer-Policy: same-origin' );
	header( "Content-Security-Policy: default-src 'self' data:; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-src 'none'; form-action 'self'; base-uri 'self'" );
}

/**
 * Build host-only cookie options shared by writes and expiry.
 *
 * @param int    $expires   Unix expiry, or zero for a browser-session cookie.
 * @param string $same_site SameSite value.
 * @return array<string,int|string|bool>
 */
function loopbuy_marketplace_cookie_options( $expires, $same_site = 'Lax' ) {
	return array(
		'expires'  => (int) $expires,
		'path'     => '/',
		'secure'   => loopbuy_marketplace_secure_cookies(),
		'httponly' => true,
		'samesite' => $same_site,
	);
}

/**
 * Write a host-only HttpOnly cookie.
 *
 * @param string $name      Cookie name.
 * @param string $value     Cookie value.
 * @param int    $expires   Unix expiry, or zero for a browser-session cookie.
 * @param string $same_site SameSite value.
 * @return true|WP_Error
 */
function loopbuy_marketplace_write_cookie( $name, $value, $expires, $same_site = 'Lax' ) {
	if ( headers_sent( $source_file, $source_line ) ) {
		return new WP_Error(
			'loopbuy_marketplace_headers_sent',
			__( 'The marketplace session could not be updated. Please reload and try again.', 'loopbuy' ),
			array(
				'file' => $source_file,
				'line' => $source_line,
			)
		);
	}

	loopbuy_marketplace_send_private_headers();

	$written = setcookie(
		$name,
		$value,
		loopbuy_marketplace_cookie_options( $expires, $same_site )
	);

	if ( ! $written ) {
		return new WP_Error( 'loopbuy_marketplace_cookie_failed', __( 'The marketplace session cookie could not be written.', 'loopbuy' ) );
	}

	$_COOKIE[ $name ] = $value;
	return true;
}

/**
 * Expire one host-only HttpOnly cookie.
 *
 * @param string $name      Cookie name.
 * @param string $same_site SameSite value.
 * @return true|WP_Error
 */
function loopbuy_marketplace_expire_cookie( $name, $same_site = 'Lax' ) {
	$result = loopbuy_marketplace_write_cookie( $name, '', time() - YEAR_IN_SECONDS, $same_site );
	unset( $_COOKIE[ $name ] );
	return $result;
}

/**
 * Replace the request-local current marketplace user cache.
 *
 * @param array|null|WP_Error $value Current user result.
 * @return void
 */
function loopbuy_marketplace_set_request_user( $value ) {
	$GLOBALS['loopbuy_marketplace_request_user_resolved'] = true;
	$GLOBALS['loopbuy_marketplace_request_user']          = $value;
}

/**
 * Clear access, refresh and persistence cookies without touching WordPress.
 *
 * @return true|WP_Error
 */
function loopbuy_marketplace_clear_session() {
	$names       = loopbuy_marketplace_cookie_names();
	$first_error = null;

	foreach ( array( 'access', 'refresh', 'remember' ) as $kind ) {
		$result = loopbuy_marketplace_expire_cookie( $names[ $kind ] );

		if ( is_wp_error( $result ) && null === $first_error ) {
			$first_error = $result;
		}
	}

	loopbuy_marketplace_set_request_user( null );

	return null === $first_error ? true : $first_error;
}

/**
 * Generate or reuse the per-browser CSRF token.
 *
 * The CSRF token is safe to render as a hidden form value. Access and refresh
 * tokens are never returned by this function or any template-facing helper.
 *
 * @param bool $rotate Force rotation after an authentication boundary.
 * @return string|WP_Error
 */
function loopbuy_marketplace_csrf_token( $rotate = false ) {
	loopbuy_marketplace_send_private_headers();

	$names = loopbuy_marketplace_cookie_names();
	$token = $rotate ? '' : loopbuy_marketplace_read_cookie( $names['csrf'] );

	if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
		try {
			$token = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $error ) {
			return new WP_Error( 'loopbuy_marketplace_csrf_generation_failed', __( 'A secure form token could not be generated.', 'loopbuy' ) );
		}

		$written = loopbuy_marketplace_write_cookie( $names['csrf'], $token, time() + DAY_IN_SECONDS, 'Strict' );

		if ( is_wp_error( $written ) ) {
			return $written;
		}
	}

	return $token;
}

/**
 * Normalize a URL to the origin fields used for same-origin comparison.
 *
 * @param string $url URL to inspect.
 * @return array|null
 */
function loopbuy_marketplace_origin_parts( $url ) {
	$parts = wp_parse_url( $url );

	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return null;
	}

	$scheme = strtolower( (string) $parts['scheme'] );
	$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return null;
	}

	return array(
		'scheme' => $scheme,
		'host'   => strtolower( rtrim( (string) $parts['host'], '.' ) ),
		'port'   => $port,
	);
}

/**
 * Require an Origin or Referer matching the configured WordPress origin.
 *
 * @return bool
 */
function loopbuy_marketplace_is_same_origin_request() {
	$candidate = '';

	if ( isset( $_SERVER['HTTP_ORIGIN'] ) && is_string( $_SERVER['HTTP_ORIGIN'] ) ) {
		$candidate = wp_unslash( $_SERVER['HTTP_ORIGIN'] );
	} elseif ( isset( $_SERVER['HTTP_REFERER'] ) && is_string( $_SERVER['HTTP_REFERER'] ) ) {
		$candidate = wp_unslash( $_SERVER['HTTP_REFERER'] );
	}

	$expected_origin  = loopbuy_marketplace_origin_parts( home_url( '/' ) );
	$candidate_origin = loopbuy_marketplace_origin_parts( $candidate );

	return null !== $expected_origin && $expected_origin === $candidate_origin;
}

/**
 * Enforce POST, same-origin and double-submit CSRF checks for a mutation.
 *
 * @param mixed $submitted_token Hidden form token.
 * @return true|WP_Error
 */
function loopbuy_marketplace_verify_mutation( $submitted_token ) {
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
		return new WP_Error( 'loopbuy_marketplace_method_not_allowed', __( 'This marketplace action requires POST.', 'loopbuy' ) );
	}

	if ( ! loopbuy_marketplace_is_same_origin_request() ) {
		return new WP_Error( 'loopbuy_marketplace_cross_origin', __( 'The marketplace action was rejected because its origin could not be verified.', 'loopbuy' ) );
	}

	$names         = loopbuy_marketplace_cookie_names();
	$cookie_token  = loopbuy_marketplace_read_cookie( $names['csrf'] );
	$submitted     = is_string( $submitted_token ) ? (string) wp_unslash( $submitted_token ) : '';
	$valid_pattern = 1 === preg_match( '/^[a-f0-9]{64}$/D', $cookie_token )
		&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $submitted );

	if ( ! $valid_pattern || ! hash_equals( $cookie_token, $submitted ) ) {
		return new WP_Error( 'loopbuy_marketplace_csrf_failed', __( 'Your marketplace session expired. Reload the page and try again.', 'loopbuy' ) );
	}

	return true;
}

/**
 * Base64url without padding, used for OAuth state and PKCE.
 *
 * @param string $bytes Raw bytes.
 * @return string
 */
function loopbuy_marketplace_base64url( $bytes ) {
	return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
}

/**
 * Redirect to the bounded login/register context with a safe status code.
 *
 * @param string $context Context cookie value.
 * @param string $key     Query-string status key.
 * @param string $value   Query-string status value.
 * @return WP_REST_Response
 */
function loopbuy_marketplace_auth_rest_redirect( $context, $key, $value ) {
	$path     = 'register' === $context ? '/register/' : '/login/';
	$location = add_query_arg( sanitize_key( $key ), sanitize_key( $value ), home_url( $path ) );
	$response = new WP_REST_Response( null, 303 );
	$response->header( 'Location', $location );
	$response->header( 'Cache-Control', 'private, no-store, max-age=0, must-revalidate' );
	$response->header( 'Pragma', 'no-cache' );
	return $response;
}

/**
 * Start Google authorization code + PKCE from a same-origin URL.
 *
 * @return void
 */
function loopbuy_marketplace_handle_google_start() {
	$action = isset( $_GET['loopbuy_action'] ) && is_string( $_GET['loopbuy_action'] )
		? sanitize_key( wp_unslash( $_GET['loopbuy_action'] ) )
		: '';

	if ( 'google_start' !== $action ) {
		return;
	}

	loopbuy_marketplace_send_private_headers();

	$context = isset( $_GET['context'] ) && is_string( $_GET['context'] ) && 'register' === sanitize_key( wp_unslash( $_GET['context'] ) )
		? 'register'
		: 'login';
	$fallback = home_url( 'register' === $context ? '/register/' : '/login/' );
	$client_id = loopbuy_marketplace_google_client_id();

	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
		status_header( 405 );
		header( 'Allow: GET' );
		exit;
	}

	if ( '' === $client_id ) {
		wp_safe_redirect( add_query_arg( 'loopbuy_auth_error', 'google_unavailable', $fallback ) );
		exit;
	}

	try {
		$state    = loopbuy_marketplace_base64url( random_bytes( 32 ) );
		$verifier = loopbuy_marketplace_base64url( random_bytes( 64 ) );
	} catch ( Exception $error ) {
		wp_safe_redirect( add_query_arg( 'loopbuy_auth_error', 'google_unavailable', $fallback ) );
		exit;
	}

	$names   = loopbuy_marketplace_cookie_names();
	$expires = time() + 10 * MINUTE_IN_SECONDS;
	$writes  = array(
		loopbuy_marketplace_write_cookie( $names['google_state'], $state, $expires, 'Lax' ),
		loopbuy_marketplace_write_cookie( $names['google_verifier'], $verifier, $expires, 'Lax' ),
		loopbuy_marketplace_write_cookie( $names['google_context'], $context, $expires, 'Lax' ),
	);

	foreach ( $writes as $write ) {
		if ( is_wp_error( $write ) ) {
			foreach ( array( 'google_state', 'google_verifier', 'google_context' ) as $kind ) {
				loopbuy_marketplace_expire_cookie( $names[ $kind ], 'Lax' );
			}

			wp_safe_redirect( add_query_arg( 'loopbuy_auth_error', 'google_unavailable', $fallback ) );
			exit;
		}
	}

	$authorization_url = add_query_arg(
		array(
			'client_id'             => $client_id,
			'redirect_uri'          => loopbuy_marketplace_google_redirect_uri(),
			'response_type'         => 'code',
			'scope'                 => 'openid email profile',
			'state'                 => $state,
			'code_challenge'        => loopbuy_marketplace_base64url( hash( 'sha256', $verifier, true ) ),
			'code_challenge_method' => 'S256',
			'prompt'                => 'select_account',
		),
		'https://accounts.google.com/o/oauth2/v2/auth'
	);

	wp_redirect( $authorization_url, 302, 'LoopBuy' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed Google OAuth origin.
	exit;
}

add_action( 'init', 'loopbuy_marketplace_handle_google_start', 1 );

/**
 * Complete Google OAuth server-to-server and store only HttpOnly session
 * cookies. The Google client secret remains exclusively in the Go backend.
 *
 * @param WP_REST_Request $request REST callback request.
 * @return WP_REST_Response
 */
function loopbuy_marketplace_google_callback( $request ) {
	loopbuy_marketplace_send_private_headers();

	$names            = loopbuy_marketplace_cookie_names();
	$expected_state   = loopbuy_marketplace_read_cookie( $names['google_state'] );
	$verifier         = loopbuy_marketplace_read_cookie( $names['google_verifier'] );
	$context_cookie   = loopbuy_marketplace_read_cookie( $names['google_context'] );
	$context          = 'register' === $context_cookie ? 'register' : 'login';
	$submitted_state  = $request->get_param( 'state' );
	$code             = $request->get_param( 'code' );
	$provider_error   = $request->get_param( 'error' );

	foreach ( array( 'google_state', 'google_verifier', 'google_context' ) as $kind ) {
		loopbuy_marketplace_expire_cookie( $names[ $kind ], 'Lax' );
	}

	if ( is_string( $provider_error ) && '' !== trim( $provider_error ) ) {
		return loopbuy_marketplace_auth_rest_redirect( $context, 'loopbuy_auth_error', 'google_denied' );
	}

	$state_valid = is_string( $submitted_state )
		&& 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/D', $submitted_state )
		&& 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/D', $expected_state )
		&& hash_equals( $expected_state, $submitted_state );
	$verifier_valid = 1 === preg_match( '/^[A-Za-z0-9_-]{43,128}$/D', $verifier );
	$code_valid     = is_string( $code )
		&& strlen( $code ) >= 8
		&& strlen( $code ) <= 4096
		&& 0 === preg_match( '/[\\x00-\\x1F\\x7F]/', $code );

	if ( ! $state_valid || ! $verifier_valid || ! $code_valid ) {
		return loopbuy_marketplace_auth_rest_redirect( $context, 'loopbuy_auth_error', 'google_state' );
	}

	$response = loopbuy_marketplace_api_request(
		'POST',
		'/api/v1/auth/google',
		array(
			'code'          => $code,
			'code_verifier' => $verifier,
			'redirect_uri'  => loopbuy_marketplace_google_redirect_uri(),
		)
	);

	if ( is_wp_error( $response ) ) {
		return loopbuy_marketplace_auth_rest_redirect( $context, 'loopbuy_auth_error', 'google_unavailable' );
	}

	if ( 200 !== $response['status'] ) {
		return loopbuy_marketplace_auth_rest_redirect( $context, 'loopbuy_auth_error', 'google_exchange' );
	}

	$user = loopbuy_marketplace_store_session( $response['data'], false );

	if ( is_wp_error( $user ) ) {
		return loopbuy_marketplace_auth_rest_redirect( $context, 'loopbuy_auth_error', 'google_session' );
	}

	loopbuy_marketplace_csrf_token( true );
	return loopbuy_marketplace_auth_rest_redirect( 'login', 'loopbuy_google', 'connected' );
}

/**
 * Register the public Google callback. State + PKCE perform authorization;
 * WordPress nonces are not suitable for a cross-site provider redirect.
 *
 * @return void
 */
function loopbuy_marketplace_register_rest_routes() {
	register_rest_route(
		'loopbuy/v1',
		'/auth/google/callback',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'loopbuy_marketplace_google_callback',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'loopbuy/v1',
		'/assistant/chat',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'loopbuy_marketplace_assistant_chat',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'loopbuy/v1',
		'/favourites',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'loopbuy_marketplace_favourites_rest',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'loopbuy/v1',
		'/favourites/(?P<listing_id>[1-9][0-9]*)',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'loopbuy_marketplace_favourite_mutation',
			'permission_callback' => '__return_true',
		)
	);
}

add_action( 'rest_api_init', 'loopbuy_marketplace_register_rest_routes' );

/**
 * Return the shared BFF signing secret when it is safe to use.
 *
 * Local development may intentionally omit this value. Production deployment
 * must provide the exact same secret to WordPress and the Go API.
 *
 * @return string
 */
function loopbuy_marketplace_bff_shared_secret() {
	$secret = defined( 'BFF_SHARED_SECRET' )
		? (string) BFF_SHARED_SECRET
		: (string) getenv( 'BFF_SHARED_SECRET' );
	$secret = trim( $secret );

	return strlen( $secret ) >= 32 ? $secret : '';
}

/**
 * Validate and preserve the exact API path used in the BFF signature.
 *
 * BFF calls use fixed internal routes. Query strings, fragments, encoded
 * separators, traversal segments, and alternative slash forms are rejected so
 * WordPress and Go cannot disagree about the signed path.
 *
 * @param mixed $path Candidate API path.
 * @return string Exact safe path, or an empty string.
 */
function loopbuy_marketplace_bff_api_path( $path ) {
	if ( ! is_string( $path ) || strlen( $path ) > 512 ) {
		return '';
	}

	return 1 === preg_match( '#^/api/v1/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#D', $path )
		? $path
		: '';
}

/**
 * Build privacy-preserving, authenticated client-bucket headers for Go.
 *
 * REMOTE_ADDR is the web-server connection peer and is the only client input
 * trusted here. Forwarding headers are intentionally never inspected. The raw
 * address is used only as HMAC input and never leaves WordPress.
 *
 * Canonical signature bytes (with no trailing newline):
 * loopbuy-bff-v1\n{timestamp}\n{clientHash}\n{METHOD}\n{API_PATH}
 *
 * @param string   $method    HTTP method.
 * @param string   $path      Exact API path without query or fragment.
 * @param int|null $timestamp Optional Unix timestamp for deterministic tests.
 * @return array<string,string> Complete signed header set, or an empty array.
 */
function loopbuy_marketplace_bff_headers( $method, $path, $timestamp = null ) {
	$secret      = loopbuy_marketplace_bff_shared_secret();
	$method      = strtoupper( (string) $method );
	$path        = loopbuy_marketplace_bff_api_path( $path );
	$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] )
		? $_SERVER['REMOTE_ADDR']
		: '';

	if ( '' === $secret
		|| ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true )
		|| '' === $path
		|| false === filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
		return array();
	}

	if ( null === $timestamp ) {
		$timestamp = time();
	}

	if ( ! is_int( $timestamp ) || $timestamp < 1 ) {
		return array();
	}

	$timestamp_text = (string) $timestamp;
	$client_hash    = hash_hmac( 'sha256', "loopbuy-client-v1\n" . $remote_addr, $secret );
	$canonical      = "loopbuy-bff-v1\n"
		. $timestamp_text . "\n"
		. $client_hash . "\n"
		. $method . "\n"
		. $path;
	$signature      = hash_hmac( 'sha256', $canonical, $secret );

	return array(
		'X-LoopBuy-BFF-Timestamp' => $timestamp_text,
		'X-LoopBuy-BFF-Client'    => $client_hash,
		'X-LoopBuy-BFF-Signature' => $signature,
	);
}

/**
 * Send one bounded server-to-server JSON request to the Go API.
 *
 * @param string     $method       HTTP method.
 * @param string     $path         Fixed API path.
 * @param array|null $payload      Optional JSON body.
 * @param string     $access_token Optional bearer token, never exposed client-side.
 * @return array|WP_Error Array with status and decoded data.
 */
function loopbuy_marketplace_api_request( $method, $path, $payload = null, $access_token = '' ) {
	$method = strtoupper( (string) $method );
	$path   = loopbuy_marketplace_bff_api_path( $path );

	if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) || '' === $path ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_api_request', __( 'The marketplace API request is invalid.', 'loopbuy' ) );
	}

	$headers = array_merge(
		array( 'Accept' => 'application/json' ),
		loopbuy_marketplace_bff_headers( $method, $path )
	);

	if ( '' !== $access_token ) {
		if ( ! loopbuy_marketplace_valid_token( $access_token ) ) {
			return new WP_Error( 'loopbuy_marketplace_invalid_access_token', __( 'The marketplace session is invalid.', 'loopbuy' ) );
		}

		$headers['Authorization'] = 'Bearer ' . $access_token;
	}

	$args = array(
		'method'              => $method,
		'timeout'             => 8,
		'redirection'         => 0,
		'limit_response_size' => MB_IN_BYTES,
		'headers'             => $headers,
	);

	if ( null !== $payload ) {
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'loopbuy_marketplace_invalid_api_payload', __( 'The marketplace API payload is invalid.', 'loopbuy' ) );
		}

		$encoded = wp_json_encode( $payload );

		if ( false === $encoded ) {
			return new WP_Error( 'loopbuy_marketplace_json_encode_failed', __( 'The marketplace request could not be encoded.', 'loopbuy' ) );
		}

		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = $encoded;
	}

	$response = wp_remote_request( loopbuy_marketplace_backend_base_url() . $path, $args );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'loopbuy_marketplace_backend_unavailable', __( 'The marketplace account service is temporarily unavailable.', 'loopbuy' ) );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = (string) wp_remote_retrieve_body( $response );

	if ( '' === trim( $body ) ) {
		return array(
			'status' => $status,
			'data'   => null,
		);
	}

	$data = json_decode( $body, true, 64, JSON_BIGINT_AS_STRING );

	if ( JSON_ERROR_NONE !== json_last_error() ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_backend_json', __( 'The marketplace account service returned an invalid response.', 'loopbuy' ) );
	}

	return array(
		'status' => $status,
		'data'   => $data,
	);
}

/**
 * Extract a bounded human-readable problem detail from the Go API.
 *
 * @param array  $response API response wrapper.
 * @param string $fallback Safe fallback message.
 * @return string
 */
function loopbuy_marketplace_problem_message( $response, $fallback ) {
	if ( isset( $response['data']['detail'] ) && is_string( $response['data']['detail'] ) ) {
		$detail = sanitize_text_field( $response['data']['detail'] );

		if ( '' !== $detail && strlen( $detail ) <= 300 ) {
			return $detail;
		}
	}

	return $fallback;
}

/**
 * Normalize the private user DTO returned by auth and /users/me.
 *
 * @param mixed $payload User payload.
 * @return array|WP_Error
 */
function loopbuy_marketplace_normalize_user( $payload ) {
	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_user', __( 'The marketplace user response is invalid.', 'loopbuy' ) );
	}

	$raw_id = isset( $payload['user_id'] ) ? $payload['user_id'] : null;

	if ( ! is_int( $raw_id ) && ! ( is_string( $raw_id ) && ctype_digit( $raw_id ) ) ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_user_id', __( 'The marketplace user ID is invalid.', 'loopbuy' ) );
	}

	$user_id  = (int) $raw_id;
	$username = isset( $payload['username'] ) && is_string( $payload['username'] )
		? loopbuy_marketplace_clean_text( $payload['username'] )
		: '';
	$email    = isset( $payload['email'] ) && is_string( $payload['email'] )
		? sanitize_email( $payload['email'] )
		: '';

	if ( $user_id < 1 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]{2,49}$/D', $username ) || ! is_email( $email ) ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_user', __( 'The marketplace user response is incomplete.', 'loopbuy' ) );
	}

	$profile = isset( $payload['profile'] ) && is_array( $payload['profile'] )
		? $payload['profile']
		: array();
	$image   = isset( $profile['profile_image'] ) && is_string( $profile['profile_image'] )
		? trim( $profile['profile_image'] )
		: '';

	if ( '' !== $image ) {
		$image = function_exists( 'loopbuy_backend_public_image_url' )
			? loopbuy_backend_public_image_url( $image )
			: esc_url_raw( $image, array( 'http', 'https' ) );
	}

	return array(
		'user_id'        => $user_id,
		'username'       => $username,
		'email'          => $email,
		'email_verified' => isset( $payload['email_verified'] ) && true === $payload['email_verified'],
		'role'           => isset( $payload['role'] ) ? sanitize_key( loopbuy_marketplace_clean_text( $payload['role'] ) ) : '',
		'status'         => isset( $payload['status'] ) ? sanitize_key( loopbuy_marketplace_clean_text( $payload['status'] ) ) : '',
		'created_at'     => isset( $payload['created_at'] ) ? loopbuy_marketplace_clean_text( $payload['created_at'] ) : '',
		'profile'        => array(
			'full_name'    => isset( $profile['full_name'] ) ? loopbuy_marketplace_clean_text( $profile['full_name'] ) : '',
			'phone'        => isset( $profile['phone'] ) ? loopbuy_marketplace_clean_text( $profile['phone'] ) : '',
			'location'     => isset( $profile['location'] ) ? loopbuy_marketplace_clean_text( $profile['location'] ) : '',
			'bio'          => isset( $profile['bio'] ) ? loopbuy_marketplace_clean_text( $profile['bio'], true ) : '',
			'profile_image' => $image,
		),
	);
}

/**
 * Parse and cap an RFC3339 token expiry.
 *
 * @param mixed $value       Raw expiry.
 * @param int   $maximum_ttl Maximum accepted TTL.
 * @return int|false
 */
function loopbuy_marketplace_token_expiry( $value, $maximum_ttl ) {
	if ( ! is_string( $value ) || '' === trim( $value ) ) {
		return false;
	}

	$timestamp = strtotime( $value );
	$now       = time();

	if ( false === $timestamp || $timestamp <= $now ) {
		return false;
	}

	return min( $timestamp, $now + $maximum_ttl );
}

/**
 * Validate the auth/refresh token response without exposing it to templates.
 *
 * @param mixed $payload Token payload.
 * @return array|WP_Error Internal session payload.
 */
function loopbuy_marketplace_normalize_session_payload( $payload ) {
	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_session_payload', __( 'The marketplace session response is invalid.', 'loopbuy' ) );
	}

	$access_token  = isset( $payload['access_token'] ) ? $payload['access_token'] : '';
	$refresh_token = isset( $payload['refresh_token'] ) ? $payload['refresh_token'] : '';
	$token_type    = isset( $payload['token_type'] ) ? (string) $payload['token_type'] : '';
	$access_expiry = loopbuy_marketplace_token_expiry(
		isset( $payload['expires_at'] ) ? $payload['expires_at'] : null,
		2 * HOUR_IN_SECONDS
	);
	$refresh_expiry = loopbuy_marketplace_token_expiry(
		isset( $payload['refresh_expires_at'] ) ? $payload['refresh_expires_at'] : null,
		32 * DAY_IN_SECONDS
	);
	$user = loopbuy_marketplace_normalize_user( isset( $payload['user'] ) ? $payload['user'] : null );

	if ( ! loopbuy_marketplace_valid_token( $access_token )
		|| ! loopbuy_marketplace_valid_token( $refresh_token )
		|| 'bearer' !== strtolower( $token_type )
		|| false === $access_expiry
		|| false === $refresh_expiry
		|| is_wp_error( $user ) ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_session_payload', __( 'The marketplace session response is incomplete.', 'loopbuy' ) );
	}

	return array(
		'access_token'  => $access_token,
		'access_expiry' => $access_expiry,
		'refresh_token' => $refresh_token,
		'refresh_expiry' => $refresh_expiry,
		'user'          => $user,
	);
}

/**
 * Store a validated Go session only in HttpOnly cookies.
 *
 * @param mixed $payload  Raw token response.
 * @param bool  $remember Whether cookies persist across browser restarts.
 * @return array|WP_Error Normalized user only; tokens never leave this helper.
 */
function loopbuy_marketplace_store_session( $payload, $remember ) {
	$session = loopbuy_marketplace_normalize_session_payload( $payload );

	if ( is_wp_error( $session ) ) {
		return $session;
	}

	if ( headers_sent() ) {
		return new WP_Error( 'loopbuy_marketplace_headers_sent', __( 'The marketplace session could not be started. Reload and try again.', 'loopbuy' ) );
	}

	$names          = loopbuy_marketplace_cookie_names();
	$access_expiry  = $remember ? $session['access_expiry'] : 0;
	$refresh_expiry = $remember ? $session['refresh_expiry'] : 0;
	$results        = array(
		loopbuy_marketplace_write_cookie( $names['access'], $session['access_token'], $access_expiry ),
		loopbuy_marketplace_write_cookie( $names['refresh'], $session['refresh_token'], $refresh_expiry ),
		loopbuy_marketplace_write_cookie( $names['remember'], $remember ? '1' : '0', $refresh_expiry ),
	);

	foreach ( $results as $result ) {
		if ( is_wp_error( $result ) ) {
			loopbuy_marketplace_clear_session();
			return $result;
		}
	}

	loopbuy_marketplace_set_request_user( $session['user'] );
	return $session['user'];
}

/**
 * Rotate a refresh token and replace both HttpOnly auth cookies.
 *
 * @return array|WP_Error Normalized user only.
 */
function loopbuy_marketplace_refresh_session() {
	$names         = loopbuy_marketplace_cookie_names();
	$refresh_token = loopbuy_marketplace_read_cookie( $names['refresh'] );

	if ( ! loopbuy_marketplace_valid_token( $refresh_token ) ) {
		loopbuy_marketplace_clear_session();
		return new WP_Error( 'loopbuy_marketplace_session_expired', __( 'Your marketplace session has expired. Please log in again.', 'loopbuy' ) );
	}

	$response = loopbuy_marketplace_api_request(
		'POST',
		'/api/v1/auth/refresh',
		array( 'refresh_token' => $refresh_token )
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( in_array( $response['status'], array( 400, 401, 404 ), true ) ) {
		loopbuy_marketplace_clear_session();
		return new WP_Error( 'loopbuy_marketplace_session_expired', __( 'Your marketplace session has expired. Please log in again.', 'loopbuy' ) );
	}

	if ( 200 !== $response['status'] ) {
		return new WP_Error( 'loopbuy_marketplace_refresh_failed', __( 'The marketplace session could not be refreshed. Please try again.', 'loopbuy' ) );
	}

	$remember = '1' === loopbuy_marketplace_read_cookie( $names['remember'] );
	return loopbuy_marketplace_store_session( $response['data'], $remember );
}

/**
 * Send an authenticated request, rotating refresh once after a 401.
 *
 * @param string     $method  HTTP method.
 * @param string     $path    API path.
 * @param array|null $payload Optional body.
 * @return array|WP_Error
 */
function loopbuy_marketplace_authenticated_request( $method, $path, $payload = null ) {
	$names         = loopbuy_marketplace_cookie_names();
	$access_token  = loopbuy_marketplace_read_cookie( $names['access'] );
	$refresh_token = loopbuy_marketplace_read_cookie( $names['refresh'] );

	if ( ! loopbuy_marketplace_valid_token( $access_token ) ) {
		$access_token = '';
	}

	if ( '' === $access_token ) {
		if ( ! loopbuy_marketplace_valid_token( $refresh_token ) ) {
			loopbuy_marketplace_clear_session();
			return new WP_Error( 'loopbuy_marketplace_auth_required', __( 'Please log in to your marketplace account.', 'loopbuy' ) );
		}

		$refreshed = loopbuy_marketplace_refresh_session();

		if ( is_wp_error( $refreshed ) ) {
			return $refreshed;
		}

		$access_token = loopbuy_marketplace_read_cookie( $names['access'] );
	}

	$response = loopbuy_marketplace_api_request( $method, $path, $payload, $access_token );

	if ( is_wp_error( $response ) || 401 !== $response['status'] ) {
		return $response;
	}

	$refreshed = loopbuy_marketplace_refresh_session();

	if ( is_wp_error( $refreshed ) ) {
		return $refreshed;
	}

	$access_token = loopbuy_marketplace_read_cookie( $names['access'] );
	$response     = loopbuy_marketplace_api_request( $method, $path, $payload, $access_token );

	if ( ! is_wp_error( $response ) && 401 === $response['status'] ) {
		loopbuy_marketplace_clear_session();
		return new WP_Error( 'loopbuy_marketplace_session_expired', __( 'Your marketplace session has expired. Please log in again.', 'loopbuy' ) );
	}

	return $response;
}

/**
 * Return a bounded RFC 9457-style REST error without exposing backend details.
 *
 * @param int    $status HTTP status.
 * @param string $code   Stable local problem identifier.
 * @param string $title  Short public title.
 * @param string $detail Public detail.
 * @return WP_REST_Response
 */
function loopbuy_marketplace_assistant_problem( $status, $code, $title, $detail ) {
	$status = (int) $status;

	if ( $status < 400 || $status > 599 ) {
		$status = 502;
	}

	$code = sanitize_key( (string) $code );

	if ( '' === $code ) {
		$code = 'assistant-request-failed';
	}

	$response = new WP_REST_Response(
		array(
			'type'     => home_url( '/problems/' . $code ),
			'title'    => sanitize_text_field( (string) $title ),
			'status'   => $status,
			'detail'   => sanitize_text_field( (string) $detail ),
			'instance' => rest_url( 'loopbuy/v1/assistant/chat' ),
		),
		$status
	);
	$response->header( 'Content-Type', 'application/problem+json; charset=UTF-8' );
	$response->header( 'Cache-Control', 'private, no-store, max-age=0, must-revalidate' );
	return $response;
}

/**
 * Convert an internal BFF error to a public REST response.
 *
 * @param WP_Error $error Internal error.
 * @return WP_REST_Response
 */
function loopbuy_marketplace_assistant_wp_error( $error ) {
	$code = $error->get_error_code();

	if ( in_array( $code, array( 'loopbuy_marketplace_auth_required', 'loopbuy_marketplace_session_expired' ), true ) ) {
		return loopbuy_marketplace_assistant_problem( 401, 'authentication-required', __( 'Authentication required', 'loopbuy' ), __( 'Please log in to use the shopping assistant.', 'loopbuy' ) );
	}

	if ( 'loopbuy_marketplace_method_not_allowed' === $code ) {
		return loopbuy_marketplace_assistant_problem( 405, 'method-not-allowed', __( 'Method not allowed', 'loopbuy' ), __( 'The shopping assistant requires POST.', 'loopbuy' ) );
	}

	if ( in_array( $code, array( 'loopbuy_marketplace_cross_origin', 'loopbuy_marketplace_csrf_failed' ), true ) ) {
		return loopbuy_marketplace_assistant_problem( 403, 'csrf-failed', __( 'Request rejected', 'loopbuy' ), __( 'Reload the page and try again.', 'loopbuy' ) );
	}

	if ( 'loopbuy_marketplace_backend_unavailable' === $code ) {
		return loopbuy_marketplace_assistant_problem( 503, 'assistant-unavailable', __( 'Assistant unavailable', 'loopbuy' ), __( 'The shopping assistant is temporarily unavailable.', 'loopbuy' ) );
	}

	return loopbuy_marketplace_assistant_problem( 502, 'assistant-invalid-response', __( 'Assistant unavailable', 'loopbuy' ), __( 'The shopping assistant returned an invalid response.', 'loopbuy' ) );
}

/**
 * Whitelist and sanitize a successful assistant response.
 *
 * No backend-only fields, credentials, HTML, or arbitrary nested JSON cross
 * this boundary. Returning null tells the caller to fail closed with 502.
 *
 * @param mixed $data Decoded backend response.
 * @return array|null
 */
function loopbuy_marketplace_sanitize_assistant_response( $data ) {
	if ( ! is_array( $data )
		|| ! isset( $data['answer'], $data['sources'], $data['model'], $data['degraded'], $data['usage'] )
		|| ! is_string( $data['answer'] )
		|| ! is_array( $data['sources'] )
		|| ! is_string( $data['model'] )
		|| ! is_bool( $data['degraded'] )
		|| ! is_array( $data['usage'] ) ) {
		return null;
	}

	$usage = array();
	foreach ( array( 'prompt_tokens', 'completion_tokens', 'total_tokens' ) as $field ) {
		if ( ! isset( $data['usage'][ $field ] ) || ! is_int( $data['usage'][ $field ] ) || $data['usage'][ $field ] < 0 ) {
			return null;
		}

		$usage[ $field ] = $data['usage'][ $field ];
	}

	if ( isset( $data['usage']['cached_tokens'] ) ) {
		if ( ! is_int( $data['usage']['cached_tokens'] ) || $data['usage']['cached_tokens'] < 0 ) {
			return null;
		}

		$usage['cached_tokens'] = $data['usage']['cached_tokens'];
	}

	$sources = array();
	foreach ( $data['sources'] as $source ) {
		if ( ! is_array( $source )
			|| ! isset( $source['listing_id'], $source['title'], $source['price'], $source['currency'], $source['score'] )
			|| ! is_int( $source['listing_id'] )
			|| $source['listing_id'] < 1
			|| ! is_string( $source['title'] )
			|| ! is_numeric( $source['price'] )
			|| ! is_string( $source['currency'] )
			|| ! is_numeric( $source['score'] ) ) {
			return null;
		}

		$price = (float) $source['price'];
		$score = (float) $source['score'];

		if ( ! is_finite( $price ) || ! is_finite( $score ) ) {
			return null;
		}

		$sources[] = array(
			'listing_id' => $source['listing_id'],
			'title'      => sanitize_text_field( $source['title'] ),
			'price'      => $price,
			'currency'   => sanitize_text_field( $source['currency'] ),
			'score'      => $score,
		);
	}

	$sanitized = array(
		'answer'   => sanitize_textarea_field( $data['answer'] ),
		'sources'  => $sources,
		'model'    => sanitize_text_field( $data['model'] ),
		'degraded' => $data['degraded'],
		'usage'    => $usage,
	);

	if ( isset( $data['warning'] ) && is_string( $data['warning'] ) && '' !== trim( $data['warning'] ) ) {
		$sanitized['warning'] = sanitize_text_field( $data['warning'] );
	}

	return $sanitized;
}

/**
 * Proxy one stateless RAG chat turn through the authenticated same-origin BFF.
 *
 * Browser contract: POST JSON {"message":"..."} and send the page-rendered
 * double-submit value in X-LoopBuy-CSRF. Access/refresh JWTs remain HttpOnly
 * cookies and are attached only to the internal Go request.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response
 */
function loopbuy_marketplace_assistant_chat( $request ) {
	loopbuy_marketplace_send_private_headers();

	$verified = loopbuy_marketplace_verify_mutation( $request->get_header( 'x-loopbuy-csrf' ) );

	if ( is_wp_error( $verified ) ) {
		return loopbuy_marketplace_assistant_wp_error( $verified );
	}

	$payload = $request->get_json_params();

	if ( ! is_array( $payload )
		|| array( 'message' ) !== array_keys( $payload )
		|| ! is_string( $payload['message'] ) ) {
		return loopbuy_marketplace_assistant_problem( 400, 'invalid-request', __( 'Invalid request', 'loopbuy' ), __( 'Send one JSON message field.', 'loopbuy' ) );
	}

	$message = trim( $payload['message'] );

	if ( '' === $message || strlen( $message ) > 4000 || 1 !== preg_match( '//u', $message ) ) {
		return loopbuy_marketplace_assistant_problem( 422, 'validation-failed', __( 'Validation failed', 'loopbuy' ), __( 'Message must contain between 1 and 4,000 UTF-8 bytes.', 'loopbuy' ) );
	}

	$result = loopbuy_marketplace_authenticated_request(
		'POST',
		'/api/v1/assistant/chat',
		array( 'message' => $message )
	);

	if ( is_wp_error( $result ) ) {
		return loopbuy_marketplace_assistant_wp_error( $result );
	}

	$status = isset( $result['status'] ) ? (int) $result['status'] : 502;

	if ( 200 !== $status ) {
		$data      = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$title     = isset( $data['title'] ) && is_string( $data['title'] ) ? $data['title'] : __( 'Assistant request failed', 'loopbuy' );
		$detail    = isset( $data['detail'] ) && is_string( $data['detail'] ) ? $data['detail'] : __( 'The shopping assistant could not complete the request.', 'loopbuy' );
		$type_path = isset( $data['type'] ) && is_string( $data['type'] ) ? wp_parse_url( $data['type'], PHP_URL_PATH ) : '';
		$code      = is_string( $type_path ) ? basename( $type_path ) : 'assistant-request-failed';

		return loopbuy_marketplace_assistant_problem( $status, $code, $title, $detail );
	}

	$data = loopbuy_marketplace_sanitize_assistant_response( isset( $result['data'] ) ? $result['data'] : null );

	if ( null === $data ) {
		return loopbuy_marketplace_assistant_problem( 502, 'assistant-invalid-response', __( 'Assistant unavailable', 'loopbuy' ), __( 'The shopping assistant returned an invalid response.', 'loopbuy' ) );
	}

	$response = new WP_REST_Response( $data, 200 );
	$response->header( 'Cache-Control', 'private, no-store, max-age=0, must-revalidate' );
	return $response;
}

/**
 * Normalize the listing collection returned by an authenticated endpoint.
 *
 * @param mixed  $payload    Backend response payload.
 * @param string $error_code Local error identifier.
 * @return array|WP_Error
 */
function loopbuy_marketplace_normalize_listing_collection( $payload, $error_code ) {
	if ( ! is_array( $payload ) || ! isset( $payload['items'] ) || ! is_array( $payload['items'] ) || ! function_exists( 'loopbuy_backend_normalize_listing' ) ) {
		return new WP_Error( $error_code, __( 'Marketplace listings returned an invalid response.', 'loopbuy' ) );
	}

	$products = array();

	foreach ( $payload['items'] as $item ) {
		$product = loopbuy_backend_normalize_listing( $item );

		if ( is_wp_error( $product ) ) {
			return new WP_Error( $error_code, __( 'Marketplace listings returned an invalid response.', 'loopbuy' ) );
		}

		$products[] = $product;
	}

	return $products;
}

/**
 * Return the current account's saved listings from MySQL.
 *
 * @param bool $force Ignore the request-local cache.
 * @return array|WP_Error
 */
function loopbuy_marketplace_list_favourites( $force = false ) {
	if ( ! $force && array_key_exists( 'loopbuy_marketplace_request_favourites', $GLOBALS ) ) {
		return $GLOBALS['loopbuy_marketplace_request_favourites'];
	}

	$response = loopbuy_marketplace_authenticated_request( 'GET', '/api/v1/users/me/favourites' );

	if ( is_wp_error( $response ) ) {
		$GLOBALS['loopbuy_marketplace_request_favourites'] = $response;
		return $response;
	}

	if ( 200 !== $response['status'] ) {
		$error = new WP_Error( 'loopbuy_marketplace_favourites_failed', __( 'Saved listings could not be loaded right now.', 'loopbuy' ) );
		$GLOBALS['loopbuy_marketplace_request_favourites'] = $error;
		return $error;
	}

	$products = loopbuy_marketplace_normalize_listing_collection( $response['data'], 'loopbuy_marketplace_invalid_favourites' );
	$GLOBALS['loopbuy_marketplace_request_favourites'] = $products;
	return $products;
}

/**
 * Return every listing owned by the current account, including review states.
 *
 * @param bool $force Ignore the request-local cache.
 * @return array|WP_Error
 */
function loopbuy_marketplace_my_listings( $force = false ) {
	if ( ! $force && array_key_exists( 'loopbuy_marketplace_request_listings', $GLOBALS ) ) {
		return $GLOBALS['loopbuy_marketplace_request_listings'];
	}

	$response = loopbuy_marketplace_authenticated_request( 'GET', '/api/v1/users/me/listings' );

	if ( is_wp_error( $response ) ) {
		$GLOBALS['loopbuy_marketplace_request_listings'] = $response;
		return $response;
	}

	if ( 200 !== $response['status'] ) {
		$error = new WP_Error( 'loopbuy_marketplace_my_listings_failed', __( 'Your listings could not be loaded right now.', 'loopbuy' ) );
		$GLOBALS['loopbuy_marketplace_request_listings'] = $error;
		return $error;
	}

	$products = loopbuy_marketplace_normalize_listing_collection( $response['data'], 'loopbuy_marketplace_invalid_my_listings' );
	$GLOBALS['loopbuy_marketplace_request_listings'] = $products;
	return $products;
}

/**
 * Load one listing through the authenticated API so its owner can preview a
 * pending or under-review listing without exposing it publicly.
 *
 * @param int|string $listing_id Positive listing ID.
 * @return array|null|WP_Error
 */
function loopbuy_marketplace_get_listing( $listing_id ) {
	$listing_id = is_numeric( $listing_id ) ? (int) $listing_id : 0;

	if ( $listing_id < 1 ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_listing_id', __( 'The listing ID is invalid.', 'loopbuy' ) );
	}

	$response = loopbuy_marketplace_authenticated_request( 'GET', '/api/v1/listings/' . $listing_id );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( 404 === $response['status'] || 204 === $response['status'] ) {
		return null;
	}

	if ( 200 !== $response['status'] || ! function_exists( 'loopbuy_backend_normalize_listing' ) ) {
		return new WP_Error( 'loopbuy_marketplace_listing_failed', __( 'The listing could not be loaded right now.', 'loopbuy' ) );
	}

	return loopbuy_backend_normalize_listing( $response['data'] );
}

/**
 * Build a bounded public error for the favourites REST bridge.
 *
 * @param int    $status HTTP status.
 * @param string $code   Stable problem identifier.
 * @param string $detail Public detail.
 * @return WP_REST_Response
 */
function loopbuy_marketplace_favourite_problem( $status, $code, $detail ) {
	$status = (int) $status;
	$status = $status >= 400 && $status <= 599 ? $status : 502;
	$code   = sanitize_key( (string) $code );

	$response = new WP_REST_Response(
		array(
			'type'     => home_url( '/problems/' . $code ),
			'title'    => __( 'Saved listing request failed', 'loopbuy' ),
			'status'   => $status,
			'detail'   => sanitize_text_field( (string) $detail ),
			'instance' => rest_url( 'loopbuy/v1/favourites' ),
		),
		$status
	);
	$response->header( 'Content-Type', 'application/problem+json; charset=UTF-8' );
	$response->header( 'Cache-Control', 'private, no-store, max-age=0, must-revalidate' );
	return $response;
}

/**
 * Return the canonical saved IDs for client-side button synchronisation.
 *
 * @return WP_REST_Response
 */
function loopbuy_marketplace_favourites_rest() {
	loopbuy_marketplace_send_private_headers();
	$products = loopbuy_marketplace_list_favourites( true );

	if ( is_wp_error( $products ) ) {
		$code = $products->get_error_code();

		if ( in_array( $code, array( 'loopbuy_marketplace_auth_required', 'loopbuy_marketplace_session_expired' ), true ) ) {
			return loopbuy_marketplace_favourite_problem( 401, 'authentication-required', __( 'Please log in to view saved listings.', 'loopbuy' ) );
		}

		$status = 'loopbuy_marketplace_backend_unavailable' === $code ? 503 : 502;
		return loopbuy_marketplace_favourite_problem( $status, 'saved-listings-unavailable', __( 'Saved listings are temporarily unavailable.', 'loopbuy' ) );
	}

	$ids = array();
	foreach ( $products as $product ) {
		if ( isset( $product['id'] ) ) {
			$ids[] = (int) $product['id'];
		}
	}

	$response = new WP_REST_Response(
		array(
			'items' => $products,
			'ids'   => $ids,
		),
		200
	);
	$response->header( 'Cache-Control', 'private, no-store, max-age=0, must-revalidate' );
	return $response;
}

/**
 * Add or remove one saved listing through the authenticated same-origin BFF.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response
 */
function loopbuy_marketplace_favourite_mutation( $request ) {
	loopbuy_marketplace_send_private_headers();

	$verified = loopbuy_marketplace_verify_mutation( $request->get_header( 'x-loopbuy-csrf' ) );

	if ( is_wp_error( $verified ) ) {
		$status = in_array( $verified->get_error_code(), array( 'loopbuy_marketplace_cross_origin', 'loopbuy_marketplace_csrf_failed' ), true ) ? 403 : 405;
		return loopbuy_marketplace_favourite_problem( $status, 'request-rejected', __( 'Reload the page and try again.', 'loopbuy' ) );
	}

	$payload    = $request->get_json_params();
	$listing_id = (int) $request->get_param( 'listing_id' );

	if ( $listing_id < 1 || ! is_array( $payload ) || 1 !== count( $payload ) || ! array_key_exists( 'saved', $payload ) || ! is_bool( $payload['saved'] ) ) {
		return loopbuy_marketplace_favourite_problem( 400, 'invalid-request', __( 'Send one boolean saved field.', 'loopbuy' ) );
	}

	$saved    = $payload['saved'];
	$method   = $saved ? 'PUT' : 'DELETE';
	$response = loopbuy_marketplace_authenticated_request( $method, '/api/v1/users/me/favourites/' . $listing_id );

	if ( is_wp_error( $response ) ) {
		$code = $response->get_error_code();

		if ( in_array( $code, array( 'loopbuy_marketplace_auth_required', 'loopbuy_marketplace_session_expired' ), true ) ) {
			return loopbuy_marketplace_favourite_problem( 401, 'authentication-required', __( 'Please log in to save listings.', 'loopbuy' ) );
		}

		$status = 'loopbuy_marketplace_backend_unavailable' === $code ? 503 : 502;
		return loopbuy_marketplace_favourite_problem( $status, 'saved-listing-unavailable', __( 'Saved listings are temporarily unavailable.', 'loopbuy' ) );
	}

	if ( 204 !== $response['status'] ) {
		if ( 401 === $response['status'] ) {
			return loopbuy_marketplace_favourite_problem( 401, 'authentication-required', __( 'Please log in to save listings.', 'loopbuy' ) );
		}

		if ( 404 === $response['status'] ) {
			return loopbuy_marketplace_favourite_problem( 404, 'listing-not-found', __( 'That listing is no longer available.', 'loopbuy' ) );
		}

		return loopbuy_marketplace_favourite_problem( 502, 'saved-listing-failed', __( 'The saved listing could not be updated right now.', 'loopbuy' ) );
	}

	unset( $GLOBALS['loopbuy_marketplace_request_favourites'] );
	$result = new WP_REST_Response(
		array(
			'listing_id' => $listing_id,
			'saved'      => $saved,
		),
		200
	);
	$result->header( 'Cache-Control', 'private, no-store, max-age=0, must-revalidate' );
	return $result;
}

/**
 * Return the current Go marketplace user, null, or an honest availability error.
 *
 * @param bool $force Ignore the request-local result cache.
 * @return array|null|WP_Error
 */
function loopbuy_marketplace_current_user( $force = false ) {
	if ( ! $force && ! empty( $GLOBALS['loopbuy_marketplace_request_user_resolved'] ) ) {
		return isset( $GLOBALS['loopbuy_marketplace_request_user'] )
			? $GLOBALS['loopbuy_marketplace_request_user']
			: null;
	}

	$names          = loopbuy_marketplace_cookie_names();
	$access_cookie  = loopbuy_marketplace_read_cookie( $names['access'] );
	$refresh_cookie = loopbuy_marketplace_read_cookie( $names['refresh'] );

	if ( '' !== $access_cookie || '' !== $refresh_cookie ) {
		loopbuy_marketplace_send_private_headers();
	}

	if ( '' === $access_cookie && '' === $refresh_cookie ) {
		loopbuy_marketplace_set_request_user( null );
		return null;
	}

	$response = loopbuy_marketplace_authenticated_request( 'GET', '/api/v1/users/me' );

	if ( is_wp_error( $response ) ) {
		if ( in_array( $response->get_error_code(), array( 'loopbuy_marketplace_auth_required', 'loopbuy_marketplace_session_expired' ), true ) ) {
			loopbuy_marketplace_set_request_user( null );
			return null;
		}

		loopbuy_marketplace_set_request_user( $response );
		return $response;
	}

	if ( 200 !== $response['status'] ) {
		$error = new WP_Error(
			'loopbuy_marketplace_me_failed',
			loopbuy_marketplace_problem_message( $response, __( 'The marketplace account could not be loaded.', 'loopbuy' ) )
		);
		loopbuy_marketplace_set_request_user( $error );
		return $error;
	}

	$user = loopbuy_marketplace_normalize_user( $response['data'] );
	loopbuy_marketplace_set_request_user( $user );
	return $user;
}

/**
 * Authenticate with Go and store the returned session in HttpOnly cookies.
 *
 * @param string $email      Email address.
 * @param string $password   Plain password sent only server-to-server.
 * @param bool   $remember   Persist cookies across browser restarts.
 * @param mixed  $csrf_token Submitted CSRF token.
 * @return array|WP_Error Normalized marketplace user.
 */
function loopbuy_marketplace_login( $email, $password, $remember, $csrf_token ) {
	$verified = loopbuy_marketplace_verify_mutation( $csrf_token );

	if ( is_wp_error( $verified ) ) {
		return $verified;
	}

	$email    = is_string( $email ) ? sanitize_email( $email ) : '';
	$password = is_string( $password ) ? $password : '';

	if ( ! is_email( $email ) || '' === $password || strlen( $password ) > 72 ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_credentials', __( 'Please enter a valid email and password.', 'loopbuy' ) );
	}

	$response = loopbuy_marketplace_api_request(
		'POST',
		'/api/v1/auth/login',
		array(
			'email'    => $email,
			'password' => $password,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( 401 === $response['status'] ) {
		return new WP_Error( 'loopbuy_marketplace_login_failed', __( 'Email or password is incorrect.', 'loopbuy' ) );
	}

	if ( 403 === $response['status'] ) {
		return new WP_Error(
			'loopbuy_marketplace_email_unverified',
			__( 'Verify your email before logging in. You can request a new verification email below.', 'loopbuy' ),
			array( 'email' => $email )
		);
	}

	if ( 429 === $response['status'] ) {
		return new WP_Error( 'loopbuy_marketplace_login_limited', __( 'Too many sign-in attempts. Please try again later.', 'loopbuy' ) );
	}

	if ( 200 !== $response['status'] ) {
		return new WP_Error( 'loopbuy_marketplace_login_failed', __( 'Marketplace sign-in is temporarily unavailable.', 'loopbuy' ) );
	}

	$user = loopbuy_marketplace_store_session( $response['data'], (bool) $remember );

	if ( ! is_wp_error( $user ) ) {
		loopbuy_marketplace_csrf_token( true );
	}

	return $user;
}

/**
 * Register with Go and request email verification.
 *
 * @param string $username   Marketplace username.
 * @param string $email      Email address.
 * @param string $password   Password sent only server-to-server.
 * @param mixed  $csrf_token Submitted CSRF token.
 * @return array|WP_Error Verification-required state and normalized email.
 */
function loopbuy_marketplace_register( $username, $email, $password, $csrf_token ) {
	$verified = loopbuy_marketplace_verify_mutation( $csrf_token );

	if ( is_wp_error( $verified ) ) {
		return $verified;
	}

	$username = is_string( $username ) ? trim( $username ) : '';
	$email    = is_string( $email ) ? sanitize_email( $email ) : '';
	$password = is_string( $password ) ? $password : '';

	if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]{2,49}$/D', $username ) ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_username', __( 'Username must be 3-50 characters using letters, numbers, dot, dash, or underscore.', 'loopbuy' ) );
	}

	if ( ! is_email( $email ) || strlen( $password ) < 8 || strlen( $password ) > 72 ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_registration', __( 'Enter a valid email and a password between 8 and 72 characters.', 'loopbuy' ) );
	}

	$response = loopbuy_marketplace_api_request(
		'POST',
		'/api/v1/auth/register',
		array(
			'username' => $username,
			'email'    => $email,
			'password' => $password,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( 409 === $response['status'] ) {
		return new WP_Error( 'loopbuy_marketplace_registration_conflict', __( 'That username or email is already in use.', 'loopbuy' ) );
	}

	if ( 429 === $response['status'] ) {
		return new WP_Error( 'loopbuy_marketplace_registration_limited', __( 'Too many registration attempts. Please try again later.', 'loopbuy' ) );
	}

	if ( 202 !== $response['status'] ) {
		$message = 422 === $response['status']
			? loopbuy_marketplace_problem_message( $response, __( 'One or more registration fields are invalid.', 'loopbuy' ) )
			: __( 'Marketplace registration is temporarily unavailable.', 'loopbuy' );
		return new WP_Error( 'loopbuy_marketplace_registration_failed', $message );
	}

	$status         = isset( $response['data']['status'] ) && is_string( $response['data']['status'] )
		? sanitize_key( $response['data']['status'] )
		: '';
	$response_email = isset( $response['data']['email'] ) && is_string( $response['data']['email'] )
		? sanitize_email( $response['data']['email'] )
		: '';

	if ( 'verification_required' !== $status || ! is_email( $response_email ) || strtolower( $response_email ) !== strtolower( $email ) ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_registration_response', __( 'Marketplace registration returned an invalid response.', 'loopbuy' ) );
	}

	return array(
		'verification_required' => true,
		'email'                 => $response_email,
	);
}

/**
 * Move an email-link token from the URL into a short-lived HttpOnly cookie.
 * This lets the browser immediately leave the secret-bearing URL before any
 * HTML, theme JavaScript, or third-party request can observe it.
 *
 * @param mixed $token Raw token query parameter.
 * @return true|WP_Error
 */
function loopbuy_marketplace_capture_verification_token( $token ) {
	$token = is_string( $token ) ? (string) wp_unslash( $token ) : '';

	if ( strlen( $token ) < 32 || strlen( $token ) > 512 || 1 !== preg_match( '/^[A-Za-z0-9._~-]+$/D', $token ) ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_verification_token', __( 'This verification link is invalid or incomplete.', 'loopbuy' ) );
	}

	$names = loopbuy_marketplace_cookie_names();
	return loopbuy_marketplace_write_cookie( $names['email_token'], $token, time() + 10 * MINUTE_IN_SECONDS, 'Strict' );
}

/**
 * Whether a valid captured token is waiting for explicit user confirmation.
 *
 * @return bool
 */
function loopbuy_marketplace_has_verification_token() {
	$names = loopbuy_marketplace_cookie_names();
	$token = loopbuy_marketplace_read_cookie( $names['email_token'] );

	return strlen( $token ) >= 32
		&& strlen( $token ) <= 512
		&& 1 === preg_match( '/^[A-Za-z0-9._~-]+$/D', $token );
}

/**
 * Verify an email token through a same-origin CSRF-protected POST.
 *
 * @param mixed $token      Raw token received in the email link.
 * @param mixed $csrf_token Submitted CSRF token.
 * @return true|WP_Error
 */
function loopbuy_marketplace_verify_email( $token, $csrf_token ) {
	$verified = loopbuy_marketplace_verify_mutation( $csrf_token );

	if ( is_wp_error( $verified ) ) {
		return $verified;
	}

	$token = is_string( $token ) ? (string) wp_unslash( $token ) : '';

	if ( strlen( $token ) < 32 || strlen( $token ) > 512 || 1 !== preg_match( '/^[A-Za-z0-9._~-]+$/D', $token ) ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_verification_token', __( 'This verification link is invalid or incomplete.', 'loopbuy' ) );
	}

	$response = loopbuy_marketplace_api_request(
		'POST',
		'/api/v1/auth/email/verify',
		array( 'token' => $token )
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( 204 !== $response['status'] ) {
		$message = in_array( $response['status'], array( 400, 404, 409, 410, 422 ), true )
			? __( 'This verification link is invalid, expired, or has already been used.', 'loopbuy' )
			: __( 'Email verification is temporarily unavailable. Please try again.', 'loopbuy' );
		return new WP_Error( 'loopbuy_marketplace_email_verification_failed', $message );
	}

	loopbuy_marketplace_clear_session();
	loopbuy_marketplace_csrf_token( true );
	return true;
}

/**
 * Consume the captured HttpOnly verification token after explicit POST.
 *
 * @param mixed $csrf_token Submitted CSRF token.
 * @return true|WP_Error
 */
function loopbuy_marketplace_verify_captured_email( $csrf_token ) {
	$names = loopbuy_marketplace_cookie_names();
	$token = loopbuy_marketplace_read_cookie( $names['email_token'] );

	if ( ! loopbuy_marketplace_has_verification_token() ) {
		loopbuy_marketplace_expire_cookie( $names['email_token'], 'Strict' );
		return new WP_Error( 'loopbuy_marketplace_invalid_verification_token', __( 'This verification link is invalid or has expired.', 'loopbuy' ) );
	}

	$result = loopbuy_marketplace_verify_email( $token, $csrf_token );

	if ( ! is_wp_error( $result ) ) {
		loopbuy_marketplace_expire_cookie( $names['email_token'], 'Strict' );
	}

	return $result;
}

/**
 * Request a generic resend response without disclosing account existence.
 *
 * @param mixed $email      Email address.
 * @param mixed $csrf_token Submitted CSRF token.
 * @return true|WP_Error
 */
function loopbuy_marketplace_resend_verification( $email, $csrf_token ) {
	$verified = loopbuy_marketplace_verify_mutation( $csrf_token );

	if ( is_wp_error( $verified ) ) {
		return $verified;
	}

	$email = is_string( $email ) ? sanitize_email( wp_unslash( $email ) ) : '';

	if ( ! is_email( $email ) ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_resend_email', __( 'Enter a valid email address.', 'loopbuy' ) );
	}

	$response = loopbuy_marketplace_api_request(
		'POST',
		'/api/v1/auth/email/resend',
		array( 'email' => $email )
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( 429 === $response['status'] ) {
		return new WP_Error( 'loopbuy_marketplace_resend_limited', __( 'Please wait before requesting another verification email.', 'loopbuy' ) );
	}

	$status = isset( $response['data']['status'] ) && is_string( $response['data']['status'] )
		? sanitize_key( $response['data']['status'] )
		: '';

	if ( 202 !== $response['status'] || 'accepted' !== $status ) {
		return new WP_Error( 'loopbuy_marketplace_resend_failed', __( 'A verification email could not be requested right now.', 'loopbuy' ) );
	}

	return true;
}

/**
 * Load active listing categories for the server-rendered sell form.
 *
 * @return array|WP_Error
 */
function loopbuy_marketplace_listing_categories() {
	$response = loopbuy_marketplace_api_request( 'GET', '/api/v1/categories' );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( 200 !== $response['status'] || ! isset( $response['data']['items'] ) || ! is_array( $response['data']['items'] ) ) {
		return new WP_Error( 'loopbuy_marketplace_categories_failed', __( 'Listing categories could not be loaded.', 'loopbuy' ) );
	}

	$categories = array();

	foreach ( $response['data']['items'] as $item ) {
		if ( ! is_array( $item ) || empty( $item['is_active'] ) ) {
			continue;
		}

		$id   = isset( $item['category_id'] ) && is_numeric( $item['category_id'] ) ? (int) $item['category_id'] : 0;
		$name = isset( $item['name'] ) && is_string( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '';
		$slug = isset( $item['slug'] ) && is_string( $item['slug'] ) ? sanitize_title( $item['slug'] ) : '';

		if ( $id > 0 && '' !== $name && '' !== $slug ) {
			$categories[] = array(
				'category_id' => $id,
				'name'        => $name,
				'slug'        => $slug,
			);
		}
	}

	if ( empty( $categories ) ) {
		return new WP_Error( 'loopbuy_marketplace_categories_empty', __( 'No active listing categories are available.', 'loopbuy' ) );
	}

	return $categories;
}

/**
 * Create a marketplace listing through the authenticated Go API.
 *
 * @param array $fields     Validated form fields.
 * @param mixed $csrf_token Submitted CSRF token.
 * @return array|WP_Error Raw listing DTO with a validated listing ID.
 */
function loopbuy_marketplace_create_listing( $fields, $csrf_token ) {
	$verified = loopbuy_marketplace_verify_mutation( $csrf_token );

	if ( is_wp_error( $verified ) ) {
		return $verified;
	}

	if ( ! is_array( $fields ) ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_listing', __( 'The listing fields are invalid.', 'loopbuy' ) );
	}

	$category_id = isset( $fields['category_id'] ) && is_numeric( $fields['category_id'] ) ? (int) $fields['category_id'] : 0;
	$title       = isset( $fields['title'] ) && is_string( $fields['title'] ) ? trim( sanitize_text_field( $fields['title'] ) ) : '';
	$description = isset( $fields['description'] ) && is_string( $fields['description'] ) ? trim( sanitize_textarea_field( $fields['description'] ) ) : '';
	$brand       = isset( $fields['brand'] ) && is_string( $fields['brand'] ) ? trim( sanitize_text_field( $fields['brand'] ) ) : '';
	$location    = isset( $fields['location'] ) && is_string( $fields['location'] ) ? trim( sanitize_text_field( $fields['location'] ) ) : '';
	$price       = isset( $fields['price'] ) && is_numeric( $fields['price'] ) ? (float) $fields['price'] : -1;
	$condition   = isset( $fields['item_condition'] ) && is_string( $fields['item_condition'] )
		? str_replace( '-', '_', sanitize_key( $fields['item_condition'] ) )
		: '';

	if ( $category_id < 1
		|| '' === $title
		|| strlen( $title ) > 150
		|| strlen( $description ) > 10000
		|| strlen( $brand ) > 100
		|| strlen( $location ) > 120
		|| ! is_finite( $price )
		|| $price < 0
		|| $price > 99999999.99
		|| ! in_array( $condition, array( 'new', 'like_new', 'good', 'fair' ), true ) ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_listing', __( 'One or more listing fields are incomplete or outside the allowed limits.', 'loopbuy' ) );
	}

	$response = loopbuy_marketplace_authenticated_request(
		'POST',
		'/api/v1/listings',
		array(
			'category_id'    => $category_id,
			'title'          => $title,
			'description'    => $description,
			'brand'          => $brand,
			'location'       => $location,
			'price'          => $price,
			'currency'       => 'SGD',
			'item_condition' => $condition,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( 201 !== $response['status'] || ! is_array( $response['data'] ) ) {
		$message = in_array( $response['status'], array( 404, 409, 422 ), true )
			? loopbuy_marketplace_problem_message( $response, __( 'The listing fields could not be accepted.', 'loopbuy' ) )
			: __( 'The listing could not be created right now.', 'loopbuy' );
		return new WP_Error( 'loopbuy_marketplace_listing_create_failed', $message );
	}

	$listing_id = isset( $response['data']['listing_id'] ) && is_numeric( $response['data']['listing_id'] )
		? (int) $response['data']['listing_id']
		: 0;

	if ( $listing_id < 1 ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_listing_response', __( 'The listing service returned an invalid response.', 'loopbuy' ) );
	}

	$response['data']['listing_id'] = $listing_id;
	return $response['data'];
}

/**
 * Validate and encode one PHP upload as the backend multipart contract.
 *
 * @param array  $file       One normalized $_FILES row.
 * @param int    $sort_order Requested image order.
 * @param bool   $is_primary Whether this is the primary image.
 * @return array|WP_Error Body and content type.
 */
function loopbuy_marketplace_build_image_multipart( $file, $sort_order, $is_primary ) {
	if ( ! is_array( $file )
		|| ! isset( $file['error'], $file['size'], $file['tmp_name'], $file['name'] )
		|| UPLOAD_ERR_OK !== (int) $file['error']
		|| ! is_string( $file['tmp_name'] )
		|| ! is_uploaded_file( $file['tmp_name'] )
		|| (int) $file['size'] < 1
		|| (int) $file['size'] > 8 * MB_IN_BYTES ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_image_upload', __( 'Each image must be a successful upload no larger than 8 MB.', 'loopbuy' ) );
	}

	$filename  = sanitize_file_name( is_string( $file['name'] ) ? $file['name'] : '' );
	$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
	$types     = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'gif'  => 'image/gif',
	);

	if ( '' === $filename || ! isset( $types[ $extension ] ) || ! class_exists( 'finfo' ) ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_image_type', __( 'Use a JPEG, PNG, WebP, or GIF image.', 'loopbuy' ) );
	}

	$finfo     = new finfo( FILEINFO_MIME_TYPE );
	$mime_type = (string) $finfo->file( $file['tmp_name'] );

	if ( $types[ $extension ] !== $mime_type ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_image_type', __( 'The image contents do not match the filename extension.', 'loopbuy' ) );
	}

	$contents = file_get_contents( $file['tmp_name'] );

	if ( false === $contents || strlen( $contents ) !== (int) $file['size'] ) {
		return new WP_Error( 'loopbuy_marketplace_image_read_failed', __( 'An uploaded image could not be read.', 'loopbuy' ) );
	}

	try {
		$boundary = 'loopbuy-' . bin2hex( random_bytes( 18 ) );
	} catch ( Exception $error ) {
		return new WP_Error( 'loopbuy_marketplace_multipart_failed', __( 'The image upload could not be prepared.', 'loopbuy' ) );
	}

	$disposition_name = str_replace( array( '"', "\r", "\n" ), '', $filename );
	$lines            = array(
		'--' . $boundary,
		'Content-Disposition: form-data; name="image"; filename="' . $disposition_name . '"',
		'Content-Type: ' . $mime_type,
		'',
	);
	$body             = implode( "\r\n", $lines ) . "\r\n" . $contents . "\r\n";
	$body            .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"sort_order\"\r\n\r\n" . (int) $sort_order . "\r\n";
	$body            .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"is_primary\"\r\n\r\n" . ( $is_primary ? 'true' : 'false' ) . "\r\n";
	$body            .= '--' . $boundary . "--\r\n";

	return array(
		'body'         => $body,
		'content_type' => 'multipart/form-data; boundary=' . $boundary,
	);
}

/**
 * Validate and encode one profile photo using the avatar upload contract.
 *
 * @param array $file One normalized $_FILES row.
 * @return array|WP_Error Body and content type.
 */
function loopbuy_marketplace_build_avatar_multipart( $file ) {
	if ( ! is_array( $file )
		|| ! isset( $file['error'], $file['size'], $file['tmp_name'], $file['name'] )
		|| UPLOAD_ERR_OK !== (int) $file['error']
		|| ! is_string( $file['tmp_name'] )
		|| ! is_uploaded_file( $file['tmp_name'] )
		|| (int) $file['size'] < 1
		|| (int) $file['size'] > 2 * MB_IN_BYTES ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_avatar_upload', __( 'Choose a profile photo no larger than 2 MB.', 'loopbuy' ) );
	}

	$filename  = sanitize_file_name( is_string( $file['name'] ) ? $file['name'] : '' );
	$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
	$types     = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
	);

	if ( '' === $filename || ! isset( $types[ $extension ] ) || ! class_exists( 'finfo' ) || ! function_exists( 'wp_getimagesize' ) ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_avatar_type', __( 'Use a JPEG, PNG, or WebP profile photo.', 'loopbuy' ) );
	}

	$finfo      = new finfo( FILEINFO_MIME_TYPE );
	$mime_type  = (string) $finfo->file( $file['tmp_name'] );
	$dimensions = wp_getimagesize( $file['tmp_name'] );

	if ( $types[ $extension ] !== $mime_type
		|| ! is_array( $dimensions )
		|| ! isset( $dimensions[0], $dimensions[1], $dimensions['mime'] )
		|| $mime_type !== $dimensions['mime']
		|| (int) $dimensions[0] < 1
		|| (int) $dimensions[1] < 1
		|| (int) $dimensions[0] > 4096
		|| (int) $dimensions[1] > 4096
		|| (int) $dimensions[0] * (int) $dimensions[1] > 16000000 ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_avatar_type', __( 'Use a valid JPEG, PNG, or WebP no larger than 4096 by 4096 pixels.', 'loopbuy' ) );
	}

	$contents = file_get_contents( $file['tmp_name'] );

	if ( false === $contents || strlen( $contents ) !== (int) $file['size'] ) {
		return new WP_Error( 'loopbuy_marketplace_avatar_read_failed', __( 'The profile photo could not be read.', 'loopbuy' ) );
	}

	try {
		$boundary = 'loopbuy-' . bin2hex( random_bytes( 18 ) );
	} catch ( Exception $error ) {
		return new WP_Error( 'loopbuy_marketplace_multipart_failed', __( 'The profile photo could not be prepared.', 'loopbuy' ) );
	}

	$disposition_name = str_replace( array( '"', "\r", "\n" ), '', $filename );
	$body             = '--' . $boundary . "\r\n";
	$body            .= 'Content-Disposition: form-data; name="image"; filename="' . $disposition_name . '"' . "\r\n";
	$body            .= 'Content-Type: ' . $mime_type . "\r\n\r\n";
	$body            .= $contents . "\r\n--" . $boundary . "--\r\n";

	return array(
		'body'         => $body,
		'content_type' => 'multipart/form-data; boundary=' . $boundary,
	);
}

/**
 * Send a prebuilt multipart body using a bearer token held only by the BFF.
 *
 * @param string $path         Fixed listing-image or current-user avatar path.
 * @param array  $multipart    Encoded body and content type.
 * @param string $access_token Backend access token.
 * @return array|WP_Error
 */
function loopbuy_marketplace_multipart_request( $path, $multipart, $access_token ) {
	$is_listing_image = 1 === preg_match( '#^/api/v1/listings/[1-9][0-9]*/images/upload$#D', $path );
	$is_avatar        = '/api/v1/users/me/avatar' === $path;

	if ( ( ! $is_listing_image && ! $is_avatar )
		|| ! is_array( $multipart )
		|| ! isset( $multipart['body'], $multipart['content_type'] )
		|| ! loopbuy_marketplace_valid_token( $access_token ) ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_upload_request', __( 'The image upload request is invalid.', 'loopbuy' ) );
	}

	$response = wp_remote_request(
		loopbuy_marketplace_backend_base_url() . $path,
		array(
			'method'              => 'POST',
			'timeout'             => 25,
			'redirection'         => 0,
			'limit_response_size' => MB_IN_BYTES,
			'data_format'         => 'body',
			'headers'             => array_merge(
				array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'   => $multipart['content_type'],
					'Content-Length' => strlen( $multipart['body'] ),
				),
				loopbuy_marketplace_bff_headers( 'POST', $path )
			),
			'body'                => $multipart['body'],
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'loopbuy_marketplace_backend_unavailable', __( 'The marketplace image service is temporarily unavailable.', 'loopbuy' ) );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = (string) wp_remote_retrieve_body( $response );
	$data   = null;

	if ( '' !== trim( $body ) ) {
		$data = json_decode( $body, true, 32, JSON_BIGINT_AS_STRING );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'loopbuy_marketplace_invalid_backend_json', __( 'The marketplace image service returned an invalid response.', 'loopbuy' ) );
		}
	}

	return array(
		'status' => $status,
		'data'   => $data,
	);
}

/**
 * Upload one image, refreshing the BFF session once after a 401.
 *
 * @param int   $listing_id Listing ID owned by the current account.
 * @param array $file       One normalized PHP upload.
 * @param int   $sort_order Requested order.
 * @param bool  $is_primary Primary image flag.
 * @return array|WP_Error ListingImage DTO.
 */
function loopbuy_marketplace_upload_listing_image( $listing_id, $file, $sort_order, $is_primary ) {
	$listing_id = (int) $listing_id;

	if ( $listing_id < 1 || $sort_order < 0 || $sort_order > 1000 ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_upload_request', __( 'The image upload request is invalid.', 'loopbuy' ) );
	}

	$multipart = loopbuy_marketplace_build_image_multipart( $file, $sort_order, $is_primary );

	if ( is_wp_error( $multipart ) ) {
		return $multipart;
	}

	$names        = loopbuy_marketplace_cookie_names();
	$access_token = loopbuy_marketplace_read_cookie( $names['access'] );

	if ( ! loopbuy_marketplace_valid_token( $access_token ) ) {
		$refreshed = loopbuy_marketplace_refresh_session();

		if ( is_wp_error( $refreshed ) ) {
			return $refreshed;
		}

		$access_token = loopbuy_marketplace_read_cookie( $names['access'] );
	}

	$path     = '/api/v1/listings/' . $listing_id . '/images/upload';
	$response = loopbuy_marketplace_multipart_request( $path, $multipart, $access_token );

	if ( ! is_wp_error( $response ) && 401 === $response['status'] ) {
		$refreshed = loopbuy_marketplace_refresh_session();

		if ( is_wp_error( $refreshed ) ) {
			return $refreshed;
		}

		$response = loopbuy_marketplace_multipart_request(
			$path,
			$multipart,
			loopbuy_marketplace_read_cookie( $names['access'] )
		);
	}

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( 201 !== $response['status'] || ! is_array( $response['data'] ) ) {
		$message = in_array( $response['status'], array( 404, 413, 415, 422 ), true )
			? loopbuy_marketplace_problem_message( $response, __( 'One of the images could not be accepted.', 'loopbuy' ) )
			: __( 'One of the images could not be uploaded right now.', 'loopbuy' );
		return new WP_Error( 'loopbuy_marketplace_image_upload_failed', $message );
	}

	$image_id = isset( $response['data']['image_id'] ) && is_numeric( $response['data']['image_id'] )
		? (int) $response['data']['image_id']
		: 0;
	$image_url = isset( $response['data']['image_url'] ) && is_string( $response['data']['image_url'] )
		? loopbuy_backend_media_path( $response['data']['image_url'] )
		: '';

	if ( $image_id < 1 || '' === $image_url ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_image_response', __( 'The marketplace image service returned an invalid response.', 'loopbuy' ) );
	}

	return $response['data'];
}

/**
 * Upload and replace the current account's locally hosted profile photo.
 *
 * @param array $file       Normalized PHP upload.
 * @param mixed $csrf_token Submitted CSRF token.
 * @return array|WP_Error Updated normalized user.
 */
function loopbuy_marketplace_upload_avatar( $file, $csrf_token ) {
	$verified = loopbuy_marketplace_verify_mutation( $csrf_token );

	if ( is_wp_error( $verified ) ) {
		return $verified;
	}

	$current = loopbuy_marketplace_current_user();

	if ( is_wp_error( $current ) ) {
		return $current;
	}

	if ( ! is_array( $current ) ) {
		return new WP_Error( 'loopbuy_marketplace_auth_required', __( 'Please log in to update your profile photo.', 'loopbuy' ) );
	}

	$multipart = loopbuy_marketplace_build_avatar_multipart( $file );

	if ( is_wp_error( $multipart ) ) {
		return $multipart;
	}

	$names        = loopbuy_marketplace_cookie_names();
	$access_token = loopbuy_marketplace_read_cookie( $names['access'] );

	if ( ! loopbuy_marketplace_valid_token( $access_token ) ) {
		$refreshed = loopbuy_marketplace_refresh_session();

		if ( is_wp_error( $refreshed ) ) {
			return $refreshed;
		}

		$access_token = loopbuy_marketplace_read_cookie( $names['access'] );
	}

	$path     = '/api/v1/users/me/avatar';
	$response = loopbuy_marketplace_multipart_request( $path, $multipart, $access_token );

	if ( ! is_wp_error( $response ) && 401 === $response['status'] ) {
		$refreshed = loopbuy_marketplace_refresh_session();

		if ( is_wp_error( $refreshed ) ) {
			return $refreshed;
		}

		$response = loopbuy_marketplace_multipart_request(
			$path,
			$multipart,
			loopbuy_marketplace_read_cookie( $names['access'] )
		);
	}

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( 200 !== $response['status'] ) {
		$message = in_array( $response['status'], array( 413, 415, 422 ), true )
			? loopbuy_marketplace_problem_message( $response, __( 'The profile photo could not be accepted.', 'loopbuy' ) )
			: __( 'The profile photo could not be uploaded right now.', 'loopbuy' );
		return new WP_Error( 'loopbuy_marketplace_avatar_upload_failed', $message );
	}

	$user = loopbuy_marketplace_normalize_user( $response['data'] );

	if ( is_wp_error( $user ) ) {
		return $user;
	}

	loopbuy_marketplace_set_request_user( $user );
	return $user;
}

/**
 * Patch the current Go marketplace profile.
 *
 * @param array $fields     Submitted profile fields.
 * @param mixed $csrf_token Submitted CSRF token.
 * @return array|WP_Error Updated normalized user.
 */
function loopbuy_marketplace_update_profile( $fields, $csrf_token ) {
	$verified = loopbuy_marketplace_verify_mutation( $csrf_token );

	if ( is_wp_error( $verified ) ) {
		return $verified;
	}

	$current = loopbuy_marketplace_current_user();

	if ( is_wp_error( $current ) ) {
		return $current;
	}

	if ( ! is_array( $current ) ) {
		return new WP_Error( 'loopbuy_marketplace_auth_required', __( 'Please log in to update your marketplace profile.', 'loopbuy' ) );
	}

	$payload = array(
		'full_name' => isset( $fields['full_name'] ) && is_string( $fields['full_name'] ) ? sanitize_text_field( $fields['full_name'] ) : '',
		'phone'     => isset( $fields['phone'] ) && is_string( $fields['phone'] ) ? sanitize_text_field( $fields['phone'] ) : '',
		'location'  => isset( $fields['location'] ) && is_string( $fields['location'] ) ? sanitize_text_field( $fields['location'] ) : '',
		'bio'       => isset( $fields['bio'] ) && is_string( $fields['bio'] ) ? sanitize_textarea_field( $fields['bio'] ) : '',
	);

	if ( '' === $payload['full_name']
		|| strlen( $payload['full_name'] ) > 100
		|| strlen( $payload['phone'] ) > 32
		|| strlen( $payload['location'] ) > 120
		|| strlen( $payload['bio'] ) > 2000 ) {
		return new WP_Error( 'loopbuy_marketplace_invalid_profile', __( 'One or more profile fields are invalid.', 'loopbuy' ) );
	}

	$response = loopbuy_marketplace_authenticated_request( 'PATCH', '/api/v1/users/me', $payload );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( 200 !== $response['status'] ) {
		$message = in_array( $response['status'], array( 409, 422 ), true )
			? loopbuy_marketplace_problem_message( $response, __( 'One or more profile fields are invalid.', 'loopbuy' ) )
			: __( 'The marketplace profile could not be updated right now.', 'loopbuy' );
		return new WP_Error( 'loopbuy_marketplace_profile_update_failed', $message );
	}

	$user = loopbuy_marketplace_normalize_user( $response['data'] );
	loopbuy_marketplace_set_request_user( $user );
	return $user;
}

/**
 * Clear the browser session after a verified logout request, regardless of
 * whether remote refresh-token revocation could be confirmed.
 *
 * @param bool $remote_failed Whether Go revocation failed or returned non-204.
 * @return true|WP_Error
 */
function loopbuy_marketplace_finish_logout( $remote_failed ) {
	$cleared = loopbuy_marketplace_clear_session();
	$rotated = loopbuy_marketplace_csrf_token( true );

	if ( is_wp_error( $cleared ) ) {
		return new WP_Error(
			'loopbuy_marketplace_logout_local_failed',
			__( 'This browser session could not be cleared completely. Close the browser and try again.', 'loopbuy' )
		);
	}

	if ( is_wp_error( $rotated ) ) {
		return new WP_Error(
			'loopbuy_marketplace_logout_local_failed',
			__( 'You were signed out, but a new form-security token could not be created. Reload before signing in again.', 'loopbuy' )
		);
	}

	if ( $remote_failed ) {
		return new WP_Error(
			'loopbuy_marketplace_logout_remote_failed',
			__( 'You were signed out from this browser, but server-side session revocation could not be confirmed.', 'loopbuy' ),
			array( 'local_session_cleared' => true )
		);
	}

	return true;
}

/**
 * Best-effort revoke the current Go refresh token, then always clear local
 * auth cookies. CSRF/origin verification still happens before any clearing so
 * an unrelated site cannot force a logout.
 *
 * @param mixed $csrf_token Submitted CSRF token.
 * @return true|WP_Error
 */
function loopbuy_marketplace_logout( $csrf_token ) {
	$verified = loopbuy_marketplace_verify_mutation( $csrf_token );

	if ( is_wp_error( $verified ) ) {
		return $verified;
	}

	$names          = loopbuy_marketplace_cookie_names();
	$access_token   = loopbuy_marketplace_read_cookie( $names['access'] );
	$refresh_token  = loopbuy_marketplace_read_cookie( $names['refresh'] );
	$remote_failed  = false;
	$remote_pending = loopbuy_marketplace_valid_token( $refresh_token );

	if ( $remote_pending && ! loopbuy_marketplace_valid_token( $access_token ) ) {
		$refreshed = loopbuy_marketplace_refresh_session();

		if ( is_wp_error( $refreshed ) ) {
			// An expired/invalid refresh token has nothing left to revoke. An
			// infrastructure failure is different: report that revocation could
			// not be confirmed, but still clear this browser below.
			if ( 'loopbuy_marketplace_session_expired' === $refreshed->get_error_code() ) {
				$remote_pending = false;
			} else {
				$remote_failed  = true;
				$remote_pending = false;
			}
		} else {
			$access_token  = loopbuy_marketplace_read_cookie( $names['access'] );
			$refresh_token = loopbuy_marketplace_read_cookie( $names['refresh'] );
		}
	}

	$response = null;

	if ( $remote_pending && loopbuy_marketplace_valid_token( $access_token ) && loopbuy_marketplace_valid_token( $refresh_token ) ) {
		$response = loopbuy_marketplace_api_request(
			'POST',
			'/api/v1/auth/logout',
			array( 'refresh_token' => $refresh_token ),
			$access_token
		);

		if ( ! is_wp_error( $response ) && 401 === $response['status'] ) {
			$refreshed = loopbuy_marketplace_refresh_session();

			if ( is_wp_error( $refreshed ) ) {
				if ( 'loopbuy_marketplace_session_expired' === $refreshed->get_error_code() ) {
					$response = array( 'status' => 204 );
				} else {
					$remote_failed = true;
					$response      = null;
				}
			} else {
				$access_token  = loopbuy_marketplace_read_cookie( $names['access'] );
				$refresh_token = loopbuy_marketplace_read_cookie( $names['refresh'] );
				$response      = loopbuy_marketplace_api_request(
					'POST',
					'/api/v1/auth/logout',
					array( 'refresh_token' => $refresh_token ),
					$access_token
				);
			}
		}

		if ( is_wp_error( $response ) || ( is_array( $response ) && 204 !== $response['status'] ) ) {
			$remote_failed = true;
		}
	}

	return loopbuy_marketplace_finish_logout( $remote_failed );
}

/**
 * Handle the same-origin POST logout form for both WP guests and WP admins.
 *
 * @return void
 */
function loopbuy_marketplace_handle_logout() {
	$csrf_token = isset( $_POST['loopbuy_marketplace_csrf'] )
		? $_POST['loopbuy_marketplace_csrf']
		: '';
	$result     = loopbuy_marketplace_logout( $csrf_token );

	if ( is_wp_error( $result ) ) {
		$error_code = $result->get_error_code();

		if ( 'loopbuy_marketplace_logout_remote_failed' === $error_code ) {
			$redirect = add_query_arg( 'loopbuy_auth_error', 'logout_remote', home_url( '/profile/' ) );
		} elseif ( 'loopbuy_marketplace_logout_local_failed' === $error_code ) {
			$redirect = add_query_arg( 'loopbuy_auth_error', 'logout_local', home_url( '/profile/' ) );
		} else {
			$redirect = add_query_arg( 'loopbuy_auth_error', 'logout', home_url( '/profile/' ) );
		}
	} else {
		$redirect = add_query_arg( 'loopbuy_logged_out', '1', home_url( '/' ) );
	}

	wp_safe_redirect( $redirect );
	exit;
}

add_action( 'admin_post_nopriv_loopbuy_marketplace_logout', 'loopbuy_marketplace_handle_logout' );
add_action( 'admin_post_loopbuy_marketplace_logout', 'loopbuy_marketplace_handle_logout' );
