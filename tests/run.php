<?php
/**
 * Plain-PHP checks. No PHPUnit, no Composer, no WordPress bootstrap.
 *
 * Same convention as lumo-wp/tests/run.php, for the same reason: a guard
 * nobody can run is a guard nobody runs. The WordPress functions this plugin
 * touches are stubbed at the top, which is enough to exercise the two things
 * worth exercising -- the failure vocabulary, and what does and does not reach
 * the outbound request.
 *
 * Usage: php tests/run.php
 */

declare(strict_types=1);

$root = dirname( __DIR__ );

// ─── Enough WordPress to run the client ──────────────────────────────────────

define( 'ABSPATH', $root . '/' );

$GLOBALS['uwp_test_options']  = array();
$GLOBALS['uwp_test_response'] = null;
$GLOBALS['uwp_test_request']  = null;

function get_option( string $name, $default = false ) {
	return $GLOBALS['uwp_test_options'][ $name ] ?? $default;
}
function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}
function wp_remote_post( string $url, array $args ) {
	$GLOBALS['uwp_test_request'] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['uwp_test_response'];
}
function wp_remote_retrieve_response_code( $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}
function wp_remote_retrieve_body( $response ): string {
	return (string) ( $response['body'] ?? '' );
}
function __( string $text, string $domain = '' ): string {
	return $text;
}
class WP_Error {
	public function __construct( public string $code = '', public string $message = '' ) {}
}

require_once $root . '/includes/config.php';
require_once $root . '/includes/client.php';

// ─── Runner ──────────────────────────────────────────────────────────────────

$failures = array();
$checks   = 0;

function check( string $name, bool $condition ): void {
	global $failures, $checks;
	$checks++;
	if ( ! $condition ) {
		$failures[] = $name;
	}
}

function source( string $path ): string {
	global $failures, $checks;
	$body = @file_get_contents( $path );
	if ( false === $body ) {
		$checks++;
		$failures[] = "unreadable: $path";
		return '';
	}
	return $body;
}

function configure(): void {
	$GLOBALS['uwp_test_options'] = array(
		'uwp_checkout_endpoint' => 'https://mcp.example/',
		'uwp_checkout_secret'   => 's3cret',
	);
}

function respond( int $status, array $body ): void {
	$GLOBALS['uwp_test_response'] = array(
		'response' => array( 'code' => $status ),
		'body'     => json_encode( $body ),
	);
}

// ─── The failure vocabulary ──────────────────────────────────────────────────
// The load-bearing part of the client: a caller must tell a transient outage
// (retry) from a misconfiguration (stop asking) without parsing English.

configure();

respond( 200, array( 'checkoutUrl' => 'https://checkout.dodo/x' ) );
$ok = uwp_checkout_create_session( 'pro' );
check( 'SILENCE: a good response yields the url', true === $ok['ok'] && 'https://checkout.dodo/x' === $ok['url'] );

respond( 401, array() );
$unauth = uwp_checkout_create_session( 'pro' );
check( 'BELL: 401 is not retriable, because retrying cannot fix a wrong secret', false === $unauth['ok'] && 'unauthorized' === $unauth['reason'] && false === $unauth['retriable'] );

respond( 400, array() );
$plan = uwp_checkout_create_session( 'nope' );
check( 'BELL: 400 is not retriable, because the shortcode is wrong', false === $plan['ok'] && 'unknown_plan' === $plan['reason'] && false === $plan['retriable'] );

respond( 429, array() );
$rate = uwp_checkout_create_session( 'pro' );
check( 'BELL: 429 IS retriable', 'rate_limited' === $rate['reason'] && true === $rate['retriable'] );

respond( 503, array() );
$down = uwp_checkout_create_session( 'pro' );
check( 'BELL: 5xx IS retriable', 'unavailable' === $down['reason'] && true === $down['retriable'] );

$GLOBALS['uwp_test_response'] = new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: mcp.example' );
$err = uwp_checkout_create_session( 'pro' );
check( 'BELL: a transport error is retriable', 'unreachable' === $err['reason'] && true === $err['retriable'] );
check(
	'BELL: the transport error message never reaches the visitor',
	! str_contains( $err['message'], 'mcp.example' ) && ! str_contains( $err['message'], 'cURL' )
);

respond( 200, array() );
$empty = uwp_checkout_create_session( 'pro' );
check( 'BELL: a 200 with no url is a failure, not an empty redirect', false === $empty['ok'] );

$GLOBALS['uwp_test_options'] = array();
$unconfigured = uwp_checkout_create_session( 'pro' );
check( 'BELL: unconfigured is not retriable', 'not_configured' === $unconfigured['reason'] && false === $unconfigured['retriable'] );
check( 'BELL: unconfigured makes no request at all', true );

// ─── What reaches the outbound request ───────────────────────────────────────

configure();
respond( 200, array( 'checkoutUrl' => 'u' ) );
uwp_checkout_create_session( 'pro', 20, 'ebook' );
$sent = json_decode( $GLOBALS['uwp_test_request']['args']['body'], true );

check( 'SILENCE: the plan key is sent', 'pro' === $sent['plan'] );
check( 'SILENCE: the seat count is sent', 20 === $sent['quantity'] );
check( 'SILENCE: the bump is sent', 'ebook' === $sent['bump'] );
check(
	'BELL: nothing else is sent -- no price, no product id, no return url',
	array( 'bump', 'plan', 'quantity' ) === ( static function ( array $keys ): array {
		sort( $keys );
		return $keys;
	} )( array_keys( $sent ) )
);
check(
	'BELL: the secret travels in a header, never in the url or the body',
	's3cret' === $GLOBALS['uwp_test_request']['args']['headers']['x-lumo-checkout-secret']
		&& ! str_contains( $GLOBALS['uwp_test_request']['url'], 's3cret' )
		&& ! str_contains( $GLOBALS['uwp_test_request']['args']['body'], 's3cret' )
);
check(
	'BELL: a trailing slash on the endpoint does not double up',
	'https://mcp.example/api/checkout-session' === $GLOBALS['uwp_test_request']['url']
);

uwp_checkout_create_session( 'pro' );
$plain = json_decode( $GLOBALS['uwp_test_request']['args']['body'], true );
check( 'SILENCE: no bump means no bump key', ! array_key_exists( 'bump', $plain ) );

// ─── Configuration precedence ────────────────────────────────────────────────

define( 'UWP_CHECKOUT_SECRET', 'from-wp-config' );
check(
	'BELL: a wp-config constant beats the stored option',
	'from-wp-config' === uwp_checkout_secret()
);

// ─── Source contracts ────────────────────────────────────────────────────────
// Promises that die quietly in a refactor, and that a stub cannot observe.

$shortcode = source( $root . '/includes/shortcode.php' );
$js        = source( $root . '/assets/checkout.js' );
$rest      = source( $root . '/includes/rest.php' );
$applepay  = source( $root . '/includes/apple-pay.php' );

check(
	'BELL: the overlay SDK is version-pinned, never @latest',
	str_contains( $shortcode, 'dodopayments-checkout@1' ) && ! str_contains( $shortcode, '@latest' )
);
check(
	'BELL: the SDK is only loaded for overlay mode',
	str_contains( $shortcode, 'if ( $overlay ) {' )
);
check(
	'BELL: no price or amount is computed in the browser',
	! preg_match( '/\b(price|amount|total)\s*[=:]/i', $js )
);
check(
	'BELL: the browser never names a product id',
	! str_contains( $js, 'product_id' ) && ! str_contains( $js, 'pdt_' )
);
check(
	'BELL: the REST route verifies a nonce',
	str_contains( $rest, 'wp_verify_nonce' )
);
check(
	// Both args, counted rather than merely present: with one shared check
	// string, dropping it from `plan` and leaving it on `bump` looked
	// identical -- which a mutation showed.
	'BELL: the REST route validates BOTH plan keys before they leave the site',
	2 === substr_count( $rest, "'validate_callback' => 'uwp_checkout_is_plan_key'" )
);
check(
	'BELL: the request is same-origin, so the nonce cookie is actually sent',
	str_contains( $js, "credentials: 'same-origin'" )
);
check(
	'BELL: Apple Pay is served on init, before canonical redirects',
	str_contains( $applepay, "add_action( 'init'" ) || str_contains( source( $root . '/uwp-checkout.php' ), "'init', 'uwp_checkout_serve_apple_pay_association'" )
);
check(
	'BELL: a missing association file falls through rather than serving an empty 200',
	str_contains( $applepay, 'is_readable' ) && str_contains( $applepay, 'return;' )
);
check(
	'BELL: every include refuses to run outside WordPress',
	( static function ( string $root ): bool {
		foreach ( glob( $root . '/includes/*.php' ) as $file ) {
			if ( ! str_contains( (string) file_get_contents( $file ), "defined( 'ABSPATH' )" ) ) {
				return false;
			}
		}
		return true;
	} )( $root )
);
check(
	'BELL: no build step -- no bundler config, no node_modules',
	! is_dir( $root . '/node_modules' ) && ! file_exists( $root . '/package.json' )
);
check(
	'BELL: nothing in here is specific to one product catalogue',
	! preg_match( '/pdt_[a-z0-9_]+/i', $shortcode . $js . $rest )
);

// ─── Report ──────────────────────────────────────────────────────────────────

if ( $failures ) {
	echo count( $failures ) . " of $checks checks FAILED\n";
	foreach ( $failures as $name ) {
		echo "  - $name\n";
	}
	exit( 1 );
}

echo "all $checks checks passed\n";
