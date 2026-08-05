<?php
/**
 * Focused CLI contract test for privacy-preserving WordPress BFF signatures.
 *
 * Run from the repository root:
 * php wordpress/tests/test-bff-signing.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'MB_IN_BYTES', 1048576 );

if ( ! function_exists( 'add_action' ) ) {
	function add_action() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return true;
	}
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['loopbuy_test_remote_request'] = array(
			'url'  => $url,
			'args' => $args,
		);

		return array(
			'response' => array( 'code' => 204 ),
			'body'     => '',
		);
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $response['response']['code'];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $response['body'];
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return false;
	}
}

$plugin_file = getenv( 'LOOPBUY_MARKETPLACE_PLUGIN_FILE' );
$plugin_file = is_string( $plugin_file ) && '' !== $plugin_file
	? $plugin_file
	: dirname( __DIR__ ) . '/wp-content/mu-plugins/loopbuy-marketplace-session.php';

require $plugin_file;

/**
 * Stop immediately on a failed contract assertion.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure detail.
 * @return void
 */
function loopbuy_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

$secret    = 'test-only-bff-secret-0123456789abcdef';
$client_ip = '203.0.113.42';
$timestamp = 1700000000;
$method    = 'POST';
$path      = '/api/v1/auth/login';

putenv( 'BFF_SHARED_SECRET=' . $secret );
$_SERVER['REMOTE_ADDR']          = $client_ip;
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';

$headers = loopbuy_marketplace_bff_headers( $method, $path, $timestamp );
$client  = hash_hmac( 'sha256', "loopbuy-client-v1\n" . $client_ip, $secret );
$message = "loopbuy-bff-v1\n{$timestamp}\n{$client}\n{$method}\n{$path}";
$signed  = hash_hmac( 'sha256', $message, $secret );

loopbuy_test_assert(
	array_keys( $headers ) === array(
		'X-LoopBuy-BFF-Timestamp',
		'X-LoopBuy-BFF-Client',
		'X-LoopBuy-BFF-Signature',
	),
	'signed header set or order changed'
);
loopbuy_test_assert( (string) $timestamp === $headers['X-LoopBuy-BFF-Timestamp'], 'timestamp mismatch' );
loopbuy_test_assert( hash_equals( $client, $headers['X-LoopBuy-BFF-Client'] ), 'client bucket mismatch' );
loopbuy_test_assert( hash_equals( $signed, $headers['X-LoopBuy-BFF-Signature'] ), 'signature mismatch' );
loopbuy_test_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $headers['X-LoopBuy-BFF-Client'] ), 'client bucket is not lowercase hex' );
loopbuy_test_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $headers['X-LoopBuy-BFF-Signature'] ), 'signature is not lowercase hex' );
loopbuy_test_assert( false === strpos( implode( '', $headers ), $client_ip ), 'raw client address leaked into headers' );
loopbuy_test_assert( false === strpos( implode( '', $headers ), $_SERVER['HTTP_X_FORWARDED_FOR'] ), 'forwarded address leaked into headers' );

$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.77';
loopbuy_test_assert(
	$headers === loopbuy_marketplace_bff_headers( $method, $path, $timestamp ),
	'X-Forwarded-For influenced the signature'
);

$api_response = loopbuy_marketplace_api_request( 'GET', '/api/v1/categories' );
$api_request  = $GLOBALS['loopbuy_test_remote_request'];

loopbuy_test_assert( 204 === $api_response['status'], 'API request test double did not complete' );
loopbuy_test_assert(
	isset(
		$api_request['args']['headers']['X-LoopBuy-BFF-Timestamp'],
		$api_request['args']['headers']['X-LoopBuy-BFF-Client'],
		$api_request['args']['headers']['X-LoopBuy-BFF-Signature']
	),
	'loopbuy_marketplace_api_request omitted signed BFF headers'
);
loopbuy_test_assert(
	false === strpos( serialize( $api_request ), $client_ip ),
	'loopbuy_marketplace_api_request leaked the raw client address'
);

putenv( 'BFF_SHARED_SECRET' );
loopbuy_test_assert( array() === loopbuy_marketplace_bff_headers( $method, $path, $timestamp ), 'headers emitted without a secret' );

loopbuy_marketplace_api_request( 'GET', '/api/v1/categories' );
$unsigned_request = $GLOBALS['loopbuy_test_remote_request'];
loopbuy_test_assert(
	! isset( $unsigned_request['args']['headers']['X-LoopBuy-BFF-Timestamp'] )
		&& ! isset( $unsigned_request['args']['headers']['X-LoopBuy-BFF-Client'] )
		&& ! isset( $unsigned_request['args']['headers']['X-LoopBuy-BFF-Signature'] ),
	'API request emitted a partial or complete signed header set without a secret'
);

putenv( 'BFF_SHARED_SECRET=too-short' );
loopbuy_test_assert( array() === loopbuy_marketplace_bff_headers( $method, $path, $timestamp ), 'headers emitted with a short secret' );

putenv( 'BFF_SHARED_SECRET=' . $secret );
$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
loopbuy_test_assert( array() === loopbuy_marketplace_bff_headers( $method, $path, $timestamp ), 'headers emitted for an invalid peer address' );
loopbuy_test_assert( array() === loopbuy_marketplace_bff_headers( $method, $path . '?x=1', $timestamp ), 'query-bearing API path was signed' );
loopbuy_test_assert( array() === loopbuy_marketplace_bff_headers( $method, '/api/v1/../admin', $timestamp ), 'traversal path was signed' );

$plugin_source = file_get_contents( $plugin_file );
loopbuy_test_assert( false !== $plugin_source, 'could not read plugin for static checks' );
loopbuy_test_assert( false === strpos( $plugin_source, 'HTTP_X_FORWARDED_FOR' ), 'plugin reads X-Forwarded-For' );
loopbuy_test_assert( false === strpos( $plugin_source, 'HTTP_FORWARDED' ), 'plugin reads Forwarded' );

fwrite( STDOUT, "PASS: WordPress BFF signing contract\n" );
