<?php
/**
 * Focused CLI contract test for the authenticated assistant REST BFF.
 *
 * Run from the repository root:
 * php wordpress/tests/test-assistant-bff.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'MB_IN_BYTES', 1048576 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'YEAR_IN_SECONDS', 31536000 );

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code, $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}
}

class WP_REST_Server {
	const READABLE  = 'GET';
	const CREATABLE = 'POST';
}

class WP_REST_Response {
	public $data;
	public $status;
	public $headers = array();

	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function header( $name, $value ) {
		$this->headers[ $name ] = $value;
	}
}

class WP_REST_Request {
	private $headers;
	private $json;
	private $params;

	public function __construct( $headers, $json, $params = array() ) {
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
		$this->json    = $json;
		$this->params  = $params;
	}

	public function get_header( $name ) {
		$name = strtolower( $name );
		return isset( $this->headers[ $name ] ) ? $this->headers[ $name ] : '';
	}

	public function get_json_params() {
		return $this->json;
	}

	public function get_param( $name ) {
		return isset( $this->params[ $name ] ) ? $this->params[ $name ] : null;
	}
}

function add_action() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return true;
}

function register_rest_route( $namespace, $route, $args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	$GLOBALS['loopbuy_test_routes'][ $namespace . $route ] = $args;
	return true;
}

function __return_true() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return true;
}

function __( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return $text;
}

function is_wp_error( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return $value instanceof WP_Error;
}

function wp_unslash( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return $value;
}

function wp_parse_url( $url, $component = -1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return parse_url( $url, $component );
}

function home_url( $path = '/' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return 'http://localhost:18080' . ( '/' === substr( $path, 0, 1 ) ? $path : '/' . $path );
}

function rest_url( $path = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return 'http://localhost:18080/wp-json/' . ltrim( $path, '/' );
}

function sanitize_key( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
}

function sanitize_text_field( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) );
}

function sanitize_textarea_field( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return trim( strip_tags( (string) $value ) );
}

function is_ssl() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return false;
}

function nocache_headers() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return true;
}

function wp_json_encode( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return json_encode( $value );
}

function loopbuy_backend_base_url() {
	return 'http://api:8080';
}

function wp_remote_request( $url, $args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	$GLOBALS['loopbuy_test_remote_requests'][] = array(
		'url'  => $url,
		'args' => $args,
	);
	return $GLOBALS['loopbuy_test_backend_response'];
}

function wp_remote_retrieve_response_code( $response ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return $response['body'];
}

$plugin_file = dirname( __DIR__ ) . '/wp-content/mu-plugins/loopbuy-marketplace-session.php';
require $plugin_file;

/**
 * Stop immediately on a failed contract assertion.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure detail.
 * @return void
 */
function loopbuy_assistant_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

$secret = 'test-only-bff-secret-0123456789abcdef';
$csrf   = str_repeat( 'a', 64 );
$access = 'valid-access-token-0123456789';

putenv( 'BFF_SHARED_SECRET=' . $secret );
$_SERVER['REMOTE_ADDR']   = '203.0.113.42';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN']    = 'http://localhost:18080';
$_COOKIE['loopbuy_marketplace_csrf']   = $csrf;
$_COOKIE['loopbuy_marketplace_access'] = $access;
$GLOBALS['loopbuy_test_remote_requests'] = array();

loopbuy_marketplace_register_rest_routes();
loopbuy_assistant_test_assert(
	isset( $GLOBALS['loopbuy_test_routes']['loopbuy/v1/assistant/chat'] ),
	'assistant REST route was not registered'
);
loopbuy_assistant_test_assert(
	isset( $GLOBALS['loopbuy_test_routes']['loopbuy/v1/favourites'] ),
	'favourites GET REST route was not registered'
);
loopbuy_assistant_test_assert(
	isset( $GLOBALS['loopbuy_test_routes']['loopbuy/v1/favourites/(?P<listing_id>[1-9][0-9]*)'] ),
	'favourites mutation REST route was not registered'
);

$GLOBALS['loopbuy_test_backend_response'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode(
		array(
			'answer'       => "<script>alert(1)</script>\nTry the Sony headphones.",
			'sources'      => array(
				array(
					'listing_id' => 13,
					'title'      => '<b>Sony headphones</b>',
					'price'      => 210.0,
					'currency'   => 'AUD',
					'score'      => 0.91,
				),
			),
			'model'        => '<qwen-plus>',
			'degraded'     => false,
			'usage'        => array(
				'prompt_tokens'     => 20,
				'completion_tokens' => 10,
				'total_tokens'      => 30,
				'cached_tokens'     => 2,
			),
			'access_token' => 'must-not-cross-the-bff',
		)
	),
);

$response = loopbuy_marketplace_assistant_chat(
	new WP_REST_Request( array( 'X-LoopBuy-CSRF' => $csrf ), array( 'message' => 'Find noise-cancelling headphones' ) )
);
$remote   = $GLOBALS['loopbuy_test_remote_requests'][0];

loopbuy_assistant_test_assert( 200 === $response->status, 'successful assistant status changed' );
loopbuy_assistant_test_assert( false === strpos( serialize( $response->data ), '<' ), 'backend HTML crossed the BFF' );
loopbuy_assistant_test_assert( ! isset( $response->data['access_token'] ), 'unknown backend field crossed the BFF' );
loopbuy_assistant_test_assert( false === strpos( serialize( $response->data ), $access ), 'access token reached the browser response' );
loopbuy_assistant_test_assert( 'Bearer ' . $access === $remote['args']['headers']['Authorization'], 'HttpOnly access token was not attached server-side' );
loopbuy_assistant_test_assert( 'http://api:8080/api/v1/assistant/chat' === $remote['url'], 'wrong internal assistant route' );
loopbuy_assistant_test_assert(
	array( 'message' => 'Find noise-cancelling headphones' ) === json_decode( $remote['args']['body'], true ),
	'assistant request body changed'
);
loopbuy_assistant_test_assert(
	isset(
		$remote['args']['headers']['X-LoopBuy-BFF-Timestamp'],
		$remote['args']['headers']['X-LoopBuy-BFF-Client'],
		$remote['args']['headers']['X-LoopBuy-BFF-Signature']
	),
	'assistant request omitted signed BFF headers'
);
loopbuy_assistant_test_assert(
	'private, no-store, max-age=0, must-revalidate' === $response->headers['Cache-Control'],
	'assistant response is cacheable'
);

$GLOBALS['loopbuy_test_backend_response'] = array(
	'response' => array( 'code' => 204 ),
	'body'     => '',
);
$response = loopbuy_marketplace_favourite_mutation(
	new WP_REST_Request( array( 'X-LoopBuy-CSRF' => $csrf ), array( 'saved' => true ), array( 'listing_id' => '13' ) )
);
$remote = $GLOBALS['loopbuy_test_remote_requests'][ count( $GLOBALS['loopbuy_test_remote_requests'] ) - 1 ];
loopbuy_assistant_test_assert( 200 === $response->status, 'favourite add did not return 200' );
loopbuy_assistant_test_assert( array( 'listing_id' => 13, 'saved' => true ) === $response->data, 'favourite add response changed' );
loopbuy_assistant_test_assert( 'PUT' === $remote['args']['method'], 'favourite add did not use PUT internally' );
loopbuy_assistant_test_assert( 'http://api:8080/api/v1/users/me/favourites/13' === $remote['url'], 'favourite add used the wrong backend route' );
loopbuy_assistant_test_assert( isset( $remote['args']['headers']['X-LoopBuy-BFF-Signature'] ), 'favourite add was not signed' );

$response = loopbuy_marketplace_favourite_mutation(
	new WP_REST_Request( array( 'X-LoopBuy-CSRF' => $csrf ), array( 'saved' => false ), array( 'listing_id' => '13' ) )
);
$remote = $GLOBALS['loopbuy_test_remote_requests'][ count( $GLOBALS['loopbuy_test_remote_requests'] ) - 1 ];
loopbuy_assistant_test_assert( 200 === $response->status, 'favourite remove did not return 200' );
loopbuy_assistant_test_assert( array( 'listing_id' => 13, 'saved' => false ) === $response->data, 'favourite remove response changed' );
loopbuy_assistant_test_assert( 'DELETE' === $remote['args']['method'], 'favourite remove did not use DELETE internally' );

$GLOBALS['loopbuy_test_backend_response'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode( array( 'listing_id' => 13, 'revision' => 8 ) ),
);
$updated_listing = loopbuy_marketplace_update_listing(
	13,
	7,
	array(
		'category_id'    => 2,
		'title'          => 'Updated listing',
		'description'    => 'Updated description',
		'brand'          => 'LoopBuy',
		'location'       => 'Singapore',
		'price'          => '45.50',
		'item_condition' => 'like-new',
	),
	$csrf
);
$remote          = $GLOBALS['loopbuy_test_remote_requests'][ count( $GLOBALS['loopbuy_test_remote_requests'] ) - 1 ];
$updated_payload = json_decode( $remote['args']['body'], true );
loopbuy_assistant_test_assert( is_array( $updated_listing ) && 13 === $updated_listing['listing_id'], 'listing update response changed' );
loopbuy_assistant_test_assert( 'PATCH' === $remote['args']['method'], 'listing update did not use PATCH internally' );
loopbuy_assistant_test_assert( 'http://api:8080/api/v1/listings/13' === $remote['url'], 'listing update used the wrong backend route' );
loopbuy_assistant_test_assert( 7 === $updated_payload['revision'], 'listing update omitted the revision precondition' );
loopbuy_assistant_test_assert( ! array_key_exists( 'images', $updated_payload ), 'scalar listing update unexpectedly replaced images' );

$GLOBALS['loopbuy_test_backend_response'] = array(
	'response' => array( 'code' => 201 ),
	'body'     => json_encode( array( 'assessment_id' => 4, 'listing_id' => 13, 'label' => 'low_risk' ) ),
);
$assessment = loopbuy_marketplace_reassess_listing( 13, $csrf );
$remote     = $GLOBALS['loopbuy_test_remote_requests'][ count( $GLOBALS['loopbuy_test_remote_requests'] ) - 1 ];
loopbuy_assistant_test_assert( is_array( $assessment ) && 4 === $assessment['assessment_id'], 'listing reassessment response changed' );
loopbuy_assistant_test_assert( 'POST' === $remote['args']['method'], 'listing reassessment did not use POST internally' );
loopbuy_assistant_test_assert( 'http://api:8080/api/v1/listings/13/scam-assessments' === $remote['url'], 'listing reassessment used the wrong backend route' );

$GLOBALS['loopbuy_test_backend_response'] = array(
	'response' => array( 'code' => 204 ),
	'body'     => '',
);
$archived = loopbuy_marketplace_archive_listing( 13, $csrf );
$remote   = $GLOBALS['loopbuy_test_remote_requests'][ count( $GLOBALS['loopbuy_test_remote_requests'] ) - 1 ];
loopbuy_assistant_test_assert( true === $archived, 'listing archive did not succeed' );
loopbuy_assistant_test_assert( 'DELETE' === $remote['args']['method'], 'listing archive did not use DELETE internally' );
loopbuy_assistant_test_assert( 'http://api:8080/api/v1/listings/13' === $remote['url'], 'listing archive used the wrong backend route' );

$request_count = count( $GLOBALS['loopbuy_test_remote_requests'] );
$response      = loopbuy_marketplace_assistant_chat(
	new WP_REST_Request( array( 'X-LoopBuy-CSRF' => str_repeat( 'b', 64 ) ), array( 'message' => 'Find a bicycle' ) )
);
loopbuy_assistant_test_assert( 403 === $response->status, 'bad CSRF was not rejected' );
loopbuy_assistant_test_assert( $request_count === count( $GLOBALS['loopbuy_test_remote_requests'] ), 'bad CSRF reached the Go API' );

$response = loopbuy_marketplace_assistant_chat(
	new WP_REST_Request( array( 'X-LoopBuy-CSRF' => $csrf ), array( 'message' => 'Find a bicycle', 'unexpected' => true ) )
);
loopbuy_assistant_test_assert( 400 === $response->status, 'unknown JSON field was accepted' );

unset( $_COOKIE['loopbuy_marketplace_access'], $_COOKIE['loopbuy_marketplace_refresh'] );
$response = loopbuy_marketplace_assistant_chat(
	new WP_REST_Request( array( 'X-LoopBuy-CSRF' => $csrf ), array( 'message' => 'Find a bicycle' ) )
);
loopbuy_assistant_test_assert( 401 === $response->status, 'missing marketplace session was not rejected' );

$_COOKIE['loopbuy_marketplace_access'] = $access;
$GLOBALS['loopbuy_test_backend_response'] = array(
	'response' => array( 'code' => 422 ),
	'body'     => json_encode(
		array(
			'type'         => 'https://loopbuy.local/problems/validation-failed',
			'title'        => '<b>Validation failed</b>',
			'status'       => 422,
			'detail'       => '<script>bad</script>Message is invalid.',
			'instance'     => '/api/v1/assistant/chat',
			'access_token' => 'must-not-cross-the-bff',
		)
	),
);
$response = loopbuy_marketplace_assistant_chat(
	new WP_REST_Request( array( 'X-LoopBuy-CSRF' => $csrf ), array( 'message' => 'Find a bicycle' ) )
);
loopbuy_assistant_test_assert( 422 === $response->status, 'backend error status was not preserved' );
loopbuy_assistant_test_assert( 422 === $response->data['status'], 'problem body status was not preserved' );
loopbuy_assistant_test_assert( false === strpos( serialize( $response->data ), '<' ), 'backend problem HTML crossed the BFF' );
loopbuy_assistant_test_assert( false === strpos( serialize( $response->data ), 'must-not-cross-the-bff' ), 'backend secret field crossed the BFF' );
loopbuy_assistant_test_assert(
	'rest_url' !== $response->data['instance'] && false !== strpos( $response->data['instance'], '/wp-json/loopbuy/v1/assistant/chat' ),
	'internal backend instance crossed the BFF'
);

fwrite( STDOUT, "PASS: WordPress assistant BFF contract\n" );

