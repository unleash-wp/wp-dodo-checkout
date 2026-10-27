<?php
/**
 * Plain-PHP checks. No PHPUnit, no Composer, no WordPress bootstrap.
 *
 * One reason: a guard nobody can run is a guard nobody runs. The WordPress functions this plugin
 * touches are stubbed at the top, which is enough to exercise the two things
 * worth exercising -- the failure vocabulary, and what does and does not reach
 * the outbound request.
 *
 * Usage: php tests/run.php
 */

declare(strict_types=1);

// Stubbed WordPress must never run for a web visitor who finds this file.
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$root = dirname( __DIR__ );

// ─── Enough WordPress to run the client ──────────────────────────────────────

define( 'ABSPATH', $root . '/' );
// Defined by the plugin's main file, which these tests deliberately do not load
// -- so the harness stands in for it, exactly as WordPress would. Leaving it out
// made config.php fatal on a constant that is always present in production.
define( 'WPDC_VERSION', '0.19.1' );

$GLOBALS['wpdc_test_options']    = array();
$GLOBALS['wpdc_test_transients'] = array();
// A queue, not a single value: one checkout now costs a product list, a read
// per product, and the session itself. A stub that answered the same thing to
// all of them would prove nothing about which call carried what.
$GLOBALS['wpdc_test_queue']    = array();
$GLOBALS['wpdc_test_requests'] = array();

function get_option( string $name, $default = false ) {
	return $GLOBALS['wpdc_test_options'][ $name ] ?? $default;
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
function wp_remote_request( string $url, array $args ) {
	$GLOBALS['wpdc_test_requests'][] = array( 'url' => $url, 'args' => $args );
	$next = array_shift( $GLOBALS['wpdc_test_queue'] );
	// An exhausted queue is a test that asked for more calls than it scripted,
	// which is a fact about the test rather than about the code. Said loudly.
	return $next ?? new WP_Error( 'wpdc_test', 'no scripted response left' );
}
function wp_remote_get( string $url, array $args = array() ) {
	return wp_remote_request( $url, $args + array( 'method' => 'GET' ) );
}
function get_transient( string $k ) {
	return $GLOBALS['wpdc_test_transients'][ $k ] ?? false;
}
function set_transient( string $k, $v, $t = 0 ): bool {
	$GLOBALS['wpdc_test_transients'][ $k ] = $v;
	return true;
}
function delete_transient( string $k ): bool {
	unset( $GLOBALS['wpdc_test_transients'][ $k ] );
	return true;
}
function load_textdomain( string $domain, string $file ): bool {
	$GLOBALS['wpdc_test_domain'] = $file;
	return true;
}
function unload_textdomain( string $domain ): bool {
	$GLOBALS['wpdc_test_domain'] = null;
	return true;
}
function get_locale(): string {
	return $GLOBALS['wpdc_test_locale'] ?? 'de_DE';
}
function determine_locale(): string {
	return get_locale();
}
/**
 * Polylang, present but silent unless a test says otherwise.
 *
 * Modelled as ALWAYS DEFINED and usually empty, because that is the shape the
 * code has to survive: `function_exists` is true on any site running Polylang,
 * and an empty answer is what it gives outside the loop.
 */
function pll_current_language( string $what = 'slug' ) {
	return $GLOBALS['wpdc_test_pll'] ?? '';
}
/**
 * German grouping, because that is the locale this harness pretends to be and
 * because the thing under test is the DECIMAL COUNT, not the separators.
 */
function number_format_i18n( float $number, int $decimals = 0 ): string {
	return number_format( $number, $decimals, ',', '.' );
}
function apply_filters( string $hook, $value ) {
	return $GLOBALS['wpdc_test_filters'][ $hook ] ?? $value;
}
function is_email( $value ): bool {
	return is_string( $value ) && false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
}
function home_url(): string {
	return 'https://shop.example';
}
function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
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

// ─── Enough WordPress to run the REST routes ─────────────────────────────────
//
// Added because a check that read rest.php as TEXT could not fail for the thing
// it was named after. It asserted that "'methods' => 'POST'," appears in the
// file -- and the file contains that string twice, once per route. Changing the
// status route to GET, which is the entire failure it existed to catch, left
// all 192 checks passing.
//
// Recording what register_rest_route is actually CALLED with costs about thirty
// lines and makes the route testable instead of greppable, which also closes
// the gap underneath: the ceiling, the fail-soft answers and the response shape
// had no behavioural coverage at all.

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['wpdc_test_routes'] = array();

function register_rest_route( string $namespace, string $route, array $args ): bool {
	$GLOBALS['wpdc_test_routes'][ $namespace . $route ] = $args;
	return true;
}
function wp_verify_nonce( $nonce, $action ) {
	return ( $GLOBALS['wpdc_test_nonce'] ?? 'good' ) === $nonce ? 1 : false;
}

class WP_REST_Request {
	public function __construct(
		private array $params = array(),
		private array $headers = array()
	) {}
	public function get_param( string $name ) {
		return $this->params[ $name ] ?? null;
	}
	public function get_header( string $name ) {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}
}

class WP_REST_Response {
	public array $headers = array();
	public function __construct( public $data = null, public int $status = 200 ) {}
	public function header( string $name, string $value ): void {
		$this->headers[ strtolower( $name ) ] = $value;
	}
	public function get_data() {
		return $this->data;
	}
	public function get_status(): int {
		return $this->status;
	}
}

function shortcode_atts( array $pairs, $atts, string $tag = '' ): array {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}
function shortcode_parse_atts( string $text ) {
	preg_match_all( '/(\w+)\s*=\s*"([^"]*)"/', $text, $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $pair ) {
		$out[ strtolower( $pair[1] ) ] = $pair[2];
	}
	return $out ?: '';
}
function esc_attr( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_html( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_url( $url ): string {
	return htmlspecialchars( (string) $url, ENT_QUOTES );
}
function plugins_url( string $path = '', string $plugin = '' ): string {
	return 'https://shop.test/wp-content/plugins/wp-dodo-checkout/' . ltrim( $path, '/' );
}
function add_query_arg( string $key, string $value, string $url ): string {
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
}
if ( ! defined( 'WPDC_DIR' ) ) {
	define( 'WPDC_DIR', $root . '/' );
}

require_once $root . '/includes/config.php';
require_once $root . '/includes/client.php';
require_once $root . '/includes/rest.php';
require_once $root . '/includes/account.php';
require_once $root . '/includes/help-widget.php';

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
	$GLOBALS['wpdc_test_options']    = array( 'wpdc_api_key' => 'sk_test_key' );
	$GLOBALS['wpdc_test_transients'] = array();
	$GLOBALS['wpdc_test_filters']    = array();
	$GLOBALS['wpdc_test_requests']   = array();
	$GLOBALS['wpdc_test_queue']      = array();
	$GLOBALS['wpdc_test_log']        = array();
}

function respond( int $status, array $body ): void {
	$GLOBALS['wpdc_test_queue'][] = array(
		'response' => array( 'code' => $status ),
		'body'     => json_encode( $body ),
	);
}

/**
 * Script a catalogue: ONE list call, which carries names and prices already.
 *
 * @param array<int, string> $ids Dodo product ids this account lists.
 */
function catalogue( array $ids ): void {
	$items = array();
	foreach ( $ids as $id ) {
		$items[] = array(
			'product_id' => $id,
			'name'       => 'Product ' . $id,
			'price'      => 2499,
			'currency'   => 'EUR',
		);
	}
	respond( 200, array( 'items' => $items ) );
}

function last_request(): ?array {
	$all = $GLOBALS['wpdc_test_requests'];
	return $all ? $all[ count( $all ) - 1 ] : null;
}

// ─── The failure vocabulary ──────────────────────────────────────────────────
// The load-bearing part of the client: a caller must tell a transient outage
// (retry) from a misconfiguration (stop asking) without parsing English.

configure();

// Tested at the transport, which is where the vocabulary is decided. Going
// through wpdc_create_session would spend the scripted responses on a product
// list and prove something about the catalogue instead.

respond( 200, array( 'checkout_url' => 'https://checkout.dodo/x' ) );
$ok = wpdc_dodo_request( 'POST', '/checkouts', array() );
check( 'SILENCE: a good response comes back decoded', 'https://checkout.dodo/x' === $ok['checkout_url'] );
check(
	// The client contract, enforced on the wire rather than only promised in a
	// README: a redirect from the payment host would carry the Bearer key to
	// wherever it points, and an unverified certificate is no protection at all.
	'BELL: every Dodo request refuses redirects and verifies TLS',
	0 === last_request()['args']['redirection'] && true === last_request()['args']['sslverify']
);

respond( 401, array() );
$unauth = wpdc_dodo_request( 'GET', '/products' );
check( 'BELL: 401 is not retriable, because retrying cannot fix a wrong key', 'unauthorised' === $unauth['reason'] && false === $unauth['retriable'] );

respond( 403, array() );
$forbidden = wpdc_dodo_request( 'GET', '/products' );
check( 'BELL: 403 is treated as 401, not as a visitor error', 'unauthorised' === $forbidden['reason'] );

respond( 400, array() );
$bad = wpdc_dodo_request( 'POST', '/checkouts', array() );
check( 'BELL: a 4xx is not retriable, because the request was wrong', 'upstream_error' === $bad['reason'] && false === $bad['retriable'] );

respond( 503, array() );
$down = wpdc_dodo_request( 'GET', '/products' );
check( 'BELL: 5xx IS retriable', 'upstream_error' === $down['reason'] && true === $down['retriable'] );

$GLOBALS['wpdc_test_queue'][] = new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: live.dodopayments.com' );
$err = wpdc_dodo_request( 'GET', '/products' );
check( 'BELL: a transport error is retriable', 'unreachable' === $err['reason'] && true === $err['retriable'] );
check(
	'BELL: the transport error message never reaches the visitor',
	! str_contains( $err['message'], 'cURL' ) && ! str_contains( $err['message'], 'dodopayments.com' )
);

$GLOBALS['wpdc_test_queue'][] = array( 'response' => array( 'code' => 200 ), 'body' => 'not json' );
$junk = wpdc_dodo_request( 'GET', '/products' );
check( 'BELL: a 200 that is not an answer is a failure', 'bad_response' === $junk['reason'] );

configure();
catalogue( array( 'pdt_pro' ) );
respond( 200, array() ); // a session with no url
$empty = wpdc_create_session( 'pdt_pro' );
check( 'BELL: a session with no url is a failure, not an empty overlay', false === $empty['ok'] && 'no_url' === $empty['reason'] );

$GLOBALS['wpdc_test_options']  = array();
$GLOBALS['wpdc_test_requests'] = array();
$unconfigured = wpdc_create_session( 'pdt_pro' );
check( 'BELL: unconfigured is not retriable', 'not_configured' === $unconfigured['reason'] && false === $unconfigured['retriable'] );
// Asserted against the recorded requests, not against a constant. An earlier
// version of this line was `check( ..., true )`: a check that cannot fail,
// counted toward the total, which is the false all-clear this file exists to
// catch.
check( 'BELL: unconfigured makes no request at all', array() === $GLOBALS['wpdc_test_requests'] );

// ─── A product id is honoured only if Dodo currently lists it ────────────────
//
// The route is public, because buying does not require an account. So the id in
// a request cannot be trusted on its own: what makes it safe is that Dodo's own
// product list is the allow-list. An archived, deleted or never-listed id is
// refused before any session exists.

configure();
catalogue( array( 'pdt_pro', 'pdt_book' ) );
respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_1' ) );

$result = wpdc_create_session( 'pdt_pro', 20, 'pdt_book' );
check( 'SILENCE: a listed product produces a checkout url', true === $result['ok'] && str_contains( $result['checkout_url'], 'cks_1' ) );

$session = last_request();
$sent    = json_decode( $session['args']['body'], true );

check( 'SILENCE: the cart names the product that was asked for', 'pdt_pro' === $sent['product_cart'][0]['product_id'] );
check( 'SILENCE: the quantity travels', 20 === $sent['product_cart'][0]['quantity'] );
check( 'SILENCE: the bump is its own cart line', 'pdt_book' === $sent['product_cart'][1]['product_id'] );
check(
	// A quantity on an add-on is a way to sell somebody fifty of something they
	// ticked a box for.
	'BELL: a bump is always exactly one copy',
	1 === $sent['product_cart'][1]['quantity']
);
check(
	// The exact key set, so adding one is a deliberate act rather than a drift.
	// A price here would mean a browser could argue about what it owes.
	//
	// This is also what keeps `customization.theme_config` out. Branding belongs
	// on Dodo's Design page -- one place, three surfaces, test and live held
	// apart -- and a source grep for it failed here because client.php explains
	// that decision in a comment. Third time today a substring check matched
	// prose instead of behaviour. The key set cannot.
	'BELL: exactly these keys are sent, and no price among them',
	// `customization` since 2026-10-23: the owner put the checkout's look in code.
	array( 'product_cart', 'minimal_address', 'show_saved_payment_methods', 'feature_flags', 'customization', 'cancel_url' ) === array_keys( $sent )
);
check(
	// Reversed, and the reason is worth keeping. Sending home_url() as a floor
	// was tried: their SDK navigates on `checkout.redirect`, so a PAYING
	// customer was thrown to the front page the instant they finished -- no
	// download, no key, no word about the mail, on a page that says nothing
	// about the purchase. A destination is sent only when somebody named one;
	// with none their frame stays put, and the shop finishes the order where
	// the customer already is.
	//
	// WPDC_RETURN_URL is deliberately undefined in this harness, so this is the
	// install the decision matters on.
	'BELL: no destination is invented when nobody configured one',
	! isset( $sent['return_url'] )
);
check(
	// Their status page is a page on their domain that this shop never wrote.
	// The last screen of a purchase belongs to the seller.
	'BELL: the last screen is ours, not checkout.dodopayments.com',
	true === $sent['feature_flags']['redirect_immediately']
);
check(
	// Audit 2026-10-23: a typed email proves nothing, so it must not open anybody's saved cards.
	'BELL: without a signed customer id no saved card is offered',
	false === $sent['show_saved_payment_methods']
);
check(
	// Street, city and state skipped. This plugin sells digital goods: the
	// address is there for VAT, not for a courier, and every field before the
	// pay button is a place to give up.
	'BELL: the shortest address Dodo will accept is requested',
	true === $sent['minimal_address']
);
check(
	// Dodo shows it by default and nothing here reads it.
	'BELL: the phone field is turned off',
	false === $sent['feature_flags']['allow_phone_number_collection']
);
check(
	// Contact, address and payment on one screen, so the pay button is in view
	// at once rather than behind two next clicks.
	'BELL: the checkout is one page, not a wizard',
	true === $sent['feature_flags']['single_page']
);
check(
	// Not read anywhere, and a wallet carries its own name.
	'BELL: the cardholder name is not required',
	false === $sent['feature_flags']['require_cardholder_name']
);
check(
	'BELL: the api key travels in a header, never in the url or the body',
	'Bearer sk_test_key' === $session['args']['headers']['authorization']
		&& ! str_contains( $session['url'], 'sk_test_key' )
		&& ! str_contains( $session['args']['body'], 'sk_test_key' )
);
check(
	// Live would take real money from a real card during a half-finished setup.
	'BELL: the mode defaults to test, not live',
	str_starts_with( $session['url'], 'https://test.dodopayments.com' )
);

// ─── A checkout URL on the wrong host never reaches the browser ──────────────
//
// The URL comes back from the create-checkout call and is handed straight to a
// navigation. A compromised or confused upstream answering with a non-Dodo
// host would turn this plugin into an open redirect the moment somebody clicks
// Buy, so the host is checked before the URL leaves the server.

configure();
catalogue( array( 'pdt_pro' ) );
respond( 200, array( 'checkout_url' => 'https://evil.example/session/cks_1' ) );
$offHost = wpdc_create_session( 'pdt_pro' );
check(
	'BELL: a checkout url on a non-Dodo host is refused, not opened',
	false === $offHost['ok'] && 'bad_checkout_url' === $offHost['reason'] && false === $offHost['retriable']
);

configure();
catalogue( array( 'pdt_pro' ) );
respond( 200, array( 'checkout_url' => 'http://checkout.dodopayments.com/session/cks_1' ) );
$plainHttp = wpdc_create_session( 'pdt_pro' );
check(
	// https only: this becomes a navigation, and a plain-http one is a downgrade
	// somebody on the wire can read.
	'BELL: a plain-http checkout url is refused even on the right host',
	false === $plainHttp['ok'] && 'bad_checkout_url' === $plainHttp['reason']
);

configure();
catalogue( array( 'pdt_pro' ) );
respond( 200, array( 'checkout_url' => 'https://test.dodopayments.com/session/cks_1' ) );
$subdomain = wpdc_create_session( 'pdt_pro' );
check(
	// test. and live. are Dodo subdomains and must still pass.
	'SILENCE: a Dodo subdomain is accepted',
	true === $subdomain['ok'] && str_contains( $subdomain['checkout_url'], 'cks_1' )
);

// ─── An id Dodo does not list is refused, and no session is created ──────────
//
// THE security property of the whole plugin, stated as a test: a well-formed id
// the account does not currently list buys nothing. Without it the public route
// would sell any product whose id somebody guessed or found, including an
// archived one and a cheap test one.

configure();
catalogue( array( 'pdt_pro' ) );
catalogue( array( 'pdt_pro' ) ); // the deliberate second look
$unknown = wpdc_create_session( 'pdt_ghost' );
check( 'BELL: an unlisted product is refused', 'unknown_product' === $unknown['reason'] );
check( 'BELL: and it is not retriable -- trying again cannot help', false === $unknown['retriable'] );
check(
	'BELL: no checkout was created for it',
	! str_contains( json_encode( $GLOBALS['wpdc_test_requests'] ), '/checkouts' )
);

// A bump is the second way an id reaches the cart, and the one that would be
// forgotten: the main product IS listed, so the happy path is entered, and only
// the add-on is unlisted.
configure();
catalogue( array( 'pdt_pro' ) );
catalogue( array( 'pdt_pro' ) );
$ghostBump = wpdc_create_session( 'pdt_pro', 1, 'pdt_ghost' );
check( 'BELL: an unlisted BUMP is refused too, and sells nothing', 'unknown_product' === $ghostBump['reason'] );
check(
	'BELL: and no checkout was created around it either',
	! str_contains( json_encode( $GLOBALS['wpdc_test_requests'] ), '/checkouts' )
);

// ─── Archiving in Dodo is how something stops selling ────────────────────────
//
// Dodo's list excludes archived products, so this is not a feature to build --
// it is a consequence to prove, because it is the shop owner's only off switch.

configure();
respond( 200, array( 'items' => array() ) );
respond( 200, array( 'items' => array() ) ); // the second look agrees
$archived = wpdc_create_session( 'pdt_pro' );
check( 'BELL: a product missing from the list is not sellable', 'unknown_product' === $archived['reason'] );

// ─── The catalogue costs ONE request, not one per product ────────────────────
//
// The list carries name and price already. An earlier version read every
// product individually to reach a metadata key, so a cold cache on a busy page
// cost one request per product in the account.

configure();
respond( 200, array( 'items' => array(
	array( 'product_id' => 'pdt_a', 'name' => 'A', 'price' => 100, 'currency' => 'EUR' ),
	array( 'product_id' => 'pdt_b', 'name' => 'B', 'price' => 200, 'currency' => 'EUR' ),
	array( 'product_id' => 'pdt_c', 'name' => 'C', 'price' => 300, 'currency' => 'EUR' ),
) ) );
$three = wpdc_catalog();
check( 'SILENCE: three products came back', 3 === count( $three ) );
check( 'BELL: and they cost exactly one request', 1 === count( $GLOBALS['wpdc_test_requests'] ) );
check( 'SILENCE: name and price came from the list, not a second call', 'A' === $three['pdt_a']['name'] && 100 === $three['pdt_a']['price'] );

// ─── A malformed id from Dodo is dropped rather than trusted ─────────────────

configure();
respond( 200, array( 'items' => array(
	array( 'product_id' => 'pdt_good', 'name' => 'Good', 'price' => 1, 'currency' => 'EUR' ),
	array( 'product_id' => 'not-an-id', 'name' => 'Bad', 'price' => 1, 'currency' => 'EUR' ),
) ) );
$mixed = wpdc_catalog();
check(
	'BELL: a value not shaped like a product id never enters the catalogue',
	array( 'pdt_good' ) === array_keys( $mixed )
);

// ─── The catalogue is cached, so a busy page is not a busy API ───────────────

configure();
catalogue( array( 'pdt_pro' ) );
respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_2' ) );
wpdc_create_session( 'pdt_pro' );
$firstCount = count( $GLOBALS['wpdc_test_requests'] );

respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_3' ) );
wpdc_create_session( 'pdt_pro' );
check(
	'BELL: the second sale costs one call, not another catalogue read',
	count( $GLOBALS['wpdc_test_requests'] ) === $firstCount + 1
);

// ─── A render-time catalogue read protects the page from a slow or down API ──
//
// A `[wpdc_price]` is read while the page is built, so a slow Dodo there holds
// the whole page. At render time the fetch uses the short timeout, a recent
// failure is remembered, and the last good list is served instead of asking
// again on every view. The on-click mint keeps the full budget and no fallback.

configure();
catalogue( array( 'pdt_pro' ) );
wpdc_catalog( false, true );
check(
	'BELL: a render-time fetch uses the short timeout, not the click budget',
	WPDC_RENDER_TIMEOUT === last_request()['args']['timeout']
);

configure();
catalogue( array( 'pdt_mint' ) );
wpdc_catalog(); // the mint/default path
check(
	'BELL: the mint path keeps the full timeout',
	WPDC_TIMEOUT === last_request()['args']['timeout']
);

// A good fetch leaves a stale copy behind; a later outage serves it instead of
// hitting Dodo on every page view.
configure();
catalogue( array( 'pdt_pro' ) );            // warms the fresh + stale copies
wpdc_catalog( false, true );
$GLOBALS['wpdc_test_transients'][ wpdc_catalog_key() ] = false; // fresh copy expired
$GLOBALS['wpdc_test_requests'] = array();
$GLOBALS['wpdc_test_queue']    = array();
$GLOBALS['wpdc_test_queue'][]  = new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
$served = wpdc_catalog( false, true );
check(
	'BELL: a render-time outage serves the last good list, not an error',
	is_array( $served ) && array( 'pdt_pro' ) === array_keys( $served ) && 1 === count( $GLOBALS['wpdc_test_requests'] )
);
check(
	// The negative cache: having just failed, a second view does NOT ask again.
	'BELL: a second view inside the failure window makes no fresh request',
	( static function (): bool {
		$GLOBALS['wpdc_test_requests'] = array();
		$again = wpdc_catalog( false, true );
		return is_array( $again ) && array( 'pdt_pro' ) === array_keys( $again )
			&& array() === $GLOBALS['wpdc_test_requests'];
	} )()
);

// ─── A refused key is ours, and never shown as the visitor\'s fault ──────────

configure();
respond( 401, array( 'error' => 'bad key' ) );
$refused = wpdc_create_session( 'pdt_pro' );
check( 'BELL: a refused api key is not retriable', 'unauthorised' === $refused['reason'] && false === $refused['retriable'] );
check(
	'BELL: and the visitor is not told it is their problem',
	str_contains( $refused['message'], 'our side' )
);

// ─── A key whose prefix disagrees with the mode is flagged ───────────────────
//
// A sk_live_ key in test mode (or the reverse) makes every checkout 401, which
// the visitor reads as our fault. Run BEFORE the constants below are defined,
// while wpdc_api_key()/wpdc_mode() still read the options this sets. The key
// literals are split so the CI secret gate, which matches a real key shape,
// does not fire on a test fixture.

$GLOBALS['wpdc_test_options'] = array( 'wpdc_api_key' => 'sk_live_' . 'seedkeyvalue', 'wpdc_mode' => 'test_mode' );
check( 'BELL: a live key in test mode is a mismatch', true === wpdc_api_key_mode_mismatch() );

$GLOBALS['wpdc_test_options'] = array( 'wpdc_api_key' => 'sk_test_' . 'seedkeyvalue', 'wpdc_mode' => 'live_mode' );
check( 'BELL: a test key in live mode is a mismatch too', true === wpdc_api_key_mode_mismatch() );

$GLOBALS['wpdc_test_options'] = array( 'wpdc_api_key' => 'sk_live_' . 'seedkeyvalue', 'wpdc_mode' => 'live_mode' );
check( 'SILENCE: a live key in live mode is not a mismatch', false === wpdc_api_key_mode_mismatch() );

$GLOBALS['wpdc_test_options'] = array( 'wpdc_api_key' => 'sk_test_' . 'seedkeyvalue', 'wpdc_mode' => 'test_mode' );
check( 'SILENCE: a test key in test mode is not a mismatch', false === wpdc_api_key_mode_mismatch() );

$GLOBALS['wpdc_test_options'] = array( 'wpdc_api_key' => 'some-other-key', 'wpdc_mode' => 'live_mode' );
check( 'BELL: a key with no known prefix is never flagged, so nothing is guessed', false === wpdc_api_key_mode_mismatch() );

// ─── The settings page keeps the stored key to itself ───────────────────────
//
// Before WPDC_API_KEY is defined below, so the page renders the option path.

// Enough wp-admin to render the page. Audit 2026-10-23: the stored key was
// echoed into the HTML, so anyone who could view the page source had it.
function current_user_can( string $cap ): bool {
	// Unset grants everything; a list grants only what it names.
	return ! is_array( $GLOBALS['wpdc_test_caps'] ?? null ) || in_array( $cap, $GLOBALS['wpdc_test_caps'], true );
}
function esc_html__( string $text, string $domain = '' ): string {
	return esc_html( $text );
}
function esc_attr__( string $text, string $domain = '' ): string {
	return esc_attr( $text );
}
function settings_fields( string $group ): void {}
function submit_button(): void {}
function disabled( $value ): void {
	echo $value ? ' disabled="disabled"' : '';
}
function selected( $a, $b ): void {
	echo $a === $b ? ' selected="selected"' : '';
}
function sanitize_text_field( $value ): string {
	return trim( (string) $value );
}
function delete_option( string $name ): bool {
	unset( $GLOBALS['wpdc_test_options'][ $name ] );
	return true;
}
require_once $root . '/includes/settings.php';

check(
	'BELL: the settings page never prints the stored key, only that one is set',
	( static function (): bool {
		configure();
		$GLOBALS['wpdc_test_options']['wpdc_api_key'] = 'stored-secret-value-abc';
		respond( 200, array( 'items' => array() ) );
		ob_start();
		wpdc_render_settings_page();
		$html = (string) ob_get_clean();
		return ! str_contains( $html, 'stored-secret-value-abc' )
			&& str_contains( $html, 'value=""' )
			&& str_contains( $html, 'A key is saved. Leave this field empty to keep it.' );
	} )()
);
check(
	'BELL: saving with the key field empty keeps the stored key, a new one replaces it',
	( static function (): bool {
		configure();
		$GLOBALS['wpdc_test_options']['wpdc_api_key'] = 'stored-secret-value-abc';
		return 'stored-secret-value-abc' === wpdc_sanitise_api_key( '' )
			&& 'fresh-key' === wpdc_sanitise_api_key( ' fresh-key ' );
	} )()
);
check(
	'BELL: uninstalling removes the stored API key',
	( static function () use ( $root ): bool {
		configure();
		$GLOBALS['wpdc_test_options']['wpdc_api_key'] = 'stored-secret-value-abc';
		if ( ! is_readable( $root . '/uninstall.php' ) ) {
			return false;
		}
		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'wp-dodo-checkout/wp-dodo-checkout.php' );
		include $root . '/uninstall.php';
		return ! isset( $GLOBALS['wpdc_test_options']['wpdc_api_key'] );
	} )()
);

// ─── Configuration precedence ────────────────────────────────────────────────

define( 'WPDC_API_KEY', 'from-wp-config' );
check(
	// The reason to support constants at all: a key in wp-config.php is not in
	// the database, and database dumps travel -- backups, staging copies, a
	// support request with an export attached. This key can issue refunds.
	'BELL: a wp-config constant beats the stored option',
	'from-wp-config' === wpdc_api_key()
);

define( 'WPDC_MODE', 'nonsense' );
check(
	'BELL: an unrecognised mode falls to test, never to live',
	'test_mode' === wpdc_mode()
);

// ─── Source contracts ────────────────────────────────────────────────────────
// Promises that die quietly in a refactor, and that a stub cannot observe.

$shortcode = source( $root . '/includes/shortcode.php' );
$js        = source( $root . '/assets/checkout.js' );
$rest      = source( $root . '/includes/rest.php' );
$applepay  = source( $root . '/includes/apple-pay.php' );
$client    = source( $root . '/includes/client.php' );
$css       = source( $root . '/assets/checkout.css' );
$config    = source( $root . '/includes/config.php' );

check(
	'BELL: the overlay SDK is version-pinned, never @latest',
	str_contains( $shortcode, 'dodopayments-checkout@1' ) && ! str_contains( $shortcode, '@latest' )
);
check(
	// An EXACT version, not a floating major. `@1` let the file behind the URL
	// change with no deploy here and made an integrity hash impossible. The pin
	// is a full semver and the hash rides beside it.
	'BELL: the SDK is pinned to an exact version with an SRI hash',
	1 === preg_match( '/dodopayments-checkout@\d+\.\d+\.\d+\//', $shortcode )
		&& str_contains( $shortcode, "'sdkIntegrity' => 'sha384-" )
);
check(
	// Loaded on demand, not on every page that merely shows a price. The URL is
	// handed to our own script instead of enqueued, so the only enqueued script
	// is ours.
	'BELL: the SDK is handed to the browser, not enqueued on every page',
	str_contains( $shortcode, "'sdk'" )
		&& ! preg_match( "/wp_enqueue_script\(\s*'dodo/", $shortcode )
		&& 1 === substr_count( $shortcode, 'wp_enqueue_script(' )
);
check(
	// The connection to the CDN is warmed before the on-demand load needs it.
	'BELL: a preconnect to the CDN is emitted with the markup',
	str_contains( $shortcode, 'rel="preconnect"' )
		&& str_contains( $shortcode, 'cdn.jsdelivr.net' )
		&& str_contains( $shortcode, 'function wpdc_preconnect' )
);
check(
	// The click waits on the inline frame and its assets most of all, so their
	// handshake is warmed here too, not just the SDK CDN's.
	'BELL: the dodopayments hosts are preconnected, not only the CDN',
	str_contains( $shortcode, 'checkout.dodopayments.com' )
		&& str_contains( $shortcode, 'assets.dodopayments.com' )
);
check(
	// The logo files keep one name across releases, so without a version on the
	// url a cache serves yesterday's logo for months. Swapping the Dodo mark for
	// ours did exactly that once. Both logo urls carry the version.
	'BELL: the logo urls carry a version so a cache cannot pin an old file',
	2 === substr_count( $shortcode, "add_query_arg( 'ver', WPDC_VERSION, plugins_url( 'assets/" )
);
check(
	// The browser enforces the hash: a script injected without integrity +
	// crossOrigin would load a tampered file silently.
	'BELL: the injected SDK script carries its integrity and crossOrigin',
	str_contains( $js, 'function loadSdk' )
		&& str_contains( $js, "createElement('script')" )
		&& str_contains( $js, 's.integrity = cfg.sdkIntegrity' )
		&& str_contains( $js, "s.crossOrigin = 'anonymous'" )
);
check(
	// The first interaction with a buy control starts the load, so a click lands
	// on an SDK that is usually already there.
	'BELL: the SDK is warmed on first interaction and awaited at open',
	str_contains( $js, 'function warmSdk' )
		&& str_contains( $js, "'pointerenter'" )
		// open() waits for the load before falling back, rather than redirecting
		// the instant the SDK is not yet present.
		&& 1 === preg_match( '/function open\(root, url\)[\s\S]{0,400}loadSdk\(\)/', $js )
);
check(
	// Operator decision: the checkout is embedded in the page, never a
	// navigation away from it. So the SDK is unconditional and there is no
	// display attribute left to get wrong.
	'BELL: the SDK is loaded unconditionally, and no display mode remains',
	! str_contains( $shortcode, 'if ( $overlay )' )
		&& ! str_contains( $shortcode, "'display'" )
		&& ! str_contains( $js, "dataset.display" )
);
check(
	// Inline rather than overlay, and Apple Pay is the whole reason: Dodo's
	// Overlay page says it is not supported there. A displayType quietly reverted
	// to 'overlay' would take Apple Pay off the page with nothing failing.
	'BELL: the SDK is initialised for inline display, not overlay',
	str_contains( $js, "displayType: 'inline'" ) && ! str_contains( $js, "displayType: 'overlay'" )
);
check(
	// Dodo: "Initialization should happen once when your application loads."
	// It cannot happen at load here -- the mode is read off the session URL and
	// no session exists until a click -- so it happens on the first click and
	// not again.
	'BELL: the SDK is initialised once, not on every click',
	str_contains( $js, 'if (ready) return;' ) && 1 === substr_count( $js, 'dodo.Initialize(' )
);
check(
	// The frame needs an element to be injected into, and the id it is opened
	// with has to be the id that was rendered. Two files, one name -- the same
	// shape of defect as the class selector that swallowed every click.
	'BELL: the container the shortcode renders is the one the SDK is given',
	str_contains( $shortcode, 'class="wpdc__frame" id="<?php echo esc_attr( $id ); ?>-frame"' )
		// Through `part`, because the frame lives in the dialog and the dialog
		// no longer lives in the block. This exact lookup returning null is
		// what sent a paying customer to Dodo's hosted page instead of the
		// window: `openFrame` bailed, and the SDK-missing fallback fired.
		&& str_contains( $js, "part(root, '.wpdc__frame')" )
		&& str_contains( $js, 'elementId: frame.id' )
);
check(
	// The redirect survives as the FAILURE path: a customer who has decided to
	// buy must not be stopped because our script loader had a bad day. Deleting
	// it in the name of "embedded only" would turn a CDN hiccup into a dead
	// button.
	'BELL: a redirect fallback still exists for when the SDK is absent',
	str_contains( $js, 'window.location.assign(url)' ) && str_contains( $js, 'if (openFrame(root, url)) return;' )
);
check(
	// Dodo's own words: "adding a method here does not guarantee customers will
	// see it", and "if all payment methods are unavailable, checkout session
	// will fail". Sending nothing is what leaves the wallet buttons on. A future
	// well-meant allow-list is exactly how express checkout disappears.
	'BELL: the client never restricts payment methods, so wallets stay on',
	! str_contains( $client, 'allowed_payment_method_types' )
);
check(
	// Asked for explicitly, and it was already true -- pinned so it stays true.
	'BELL: the button text comes from the shortcode attribute',
	str_contains( $shortcode, "'label'" ) && str_contains( $shortcode, "esc_html( \$atts['label'] )" )
);
check(
	// The property, not the vocabulary. This grepped for the words price, amount
	// and total, and went red the moment the browser divided Dodo's own minor
	// units by 100 to display them -- which is not deciding what is charged, it
	// is printing what Dodo decided.
	//
	// What must stay true is narrower and testable: the browser SENDS no money.
	// It names a product and a quantity, and the server and Dodo settle the
	// rest. A price in this body would be a price a browser could argue about.
	'BELL: the browser sends no money, only what to buy',
	1 === preg_match( '/var body = \{(.*?)\};/s', $js, $m )
		&& ! preg_match( '/price|amount|total|currency/i', $m[1] )
);
check(
	// Displayed figures come from Dodo's event and nowhere else. Computing tax
	// here would be a guess that disagrees with the frame the moment the
	// customer types a country.
	'BELL: the totals shown are the ones Dodo sent',
	str_contains( $js, "event.event_type === 'checkout.breakdown'" )
		&& 2 === substr_count( $js, 'paintTotals' )
);
check(
	// Compared against null, not tested for truthiness: a truthy test is how a
	// bump the customer ticked gets dropped and they are charged for a different
	// cart than the one they agreed to.
	'BELL: the bump is read by comparison, not by truthiness',
	str_contains( $rest, "null !== \$request->get_param( 'bump' )" )
);
check(
	// Renamed to the house prefix: every other symbol in this plugin carries
	// `wpdc`, and this one carried a different one.
	'BELL: only a server-written sentence is shown to the visitor',
	str_contains( $js, 'wpdcFromServer' )
		&& ! str_contains( $js, 'say(root, err.message' )
		// One refusal path. Two catch bodies used to rebuild `fail()` by hand,
		// which is two places for a refusal to stop looking like a refusal.
		&& 1 === substr_count( $js, "note.classList.add('is-error')" )
);
check(
	// The rename shipped docs that named a shortcode the plugin did not
	// register, and every check still passed. A tag nobody registers is a
	// page that renders nothing, found by the customer rather than here.
	'BELL: the shortcode the README documents is the one that is registered',
	( static function ( string $root ): bool {
		$code = (string) file_get_contents( $root . '/includes/shortcode.php' );
		if ( ! preg_match( "/add_shortcode\\(\\s*'([a-z0-9_]+)'/", $code, $m ) ) {
			return false;
		}
		$tag = $m[1];
		$readme = (string) file_get_contents( $root . '/README.md' );
		return str_contains( $readme, '[' . $tag . ' ' ) && str_contains( $code, '[' . $tag . ' ' );
	} )( $root )
);
check(
	// The third place the name appears, and the one nothing watched: the two
	// editor-facing notices. Both said 'uwp_checkout' while the registered tag
	// is 'wpdc_checkout', so an editor who mistyped a plan was told to fix a
	// shortcode that does not exist and would search the docs for it in vain.
	// Same defect as the README check above, one file further along.
	'BELL: the notices an editor reads name the registered tag',
	( static function ( string $code ): bool {
		// ALL registered tags, not the first one. The file registers two
		// shortcodes now, and comparing every notice against whichever
		// add_shortcode happened to come first would fail a notice that names
		// the other one correctly.
		preg_match_all( "/add_shortcode\\(\\s*'([a-z0-9_]+)'/", $code, $tags );
		if ( array() === $tags[1] ) {
			return false;
		}
		preg_match_all( "/esc_html__\\(\\s*'([a-z0-9_]+):/", $code, $named );
		if ( array() === $named[1] ) {
			return false; // a notice that names no tag is not proof of anything
		}
		foreach ( $named[1] as $mentioned ) {
			if ( ! in_array( $mentioned, $tags[1], true ) ) {
				return false;
			}
		}
		return true;
	} )( $shortcode )
);
check(
	// The browser DOES send an id -- it reads one off the element -- but it must
	// carry none of its own and compute no money. A hardcoded id in shipped
	// JavaScript is a product that keeps selling after it was archived.
	'BELL: the browser hardcodes no product of its own',
	! str_contains( $js, 'pdt_' ) && ! str_contains( $js, 'product_id' )
);
check(
	// Was "the REST route verifies a nonce". That check outlived its reason: a
	// stale nonce made WordPress core answer 403 before any route ran, killing
	// the buy button and the poll that confirms a payment (rest.php records it).
	//
	// Replaced rather than deleted, by the thing that took the nonce's job.
	// Deleting it would have left the one route that creates real objects in the
	// payment account with no brake at all, and nothing in the suite saying so.
	'BELL: the session route brakes on an explicit ceiling',
	str_contains( $rest, 'WPDC_SESSION_CEILING' )
		&& str_contains( source( $root . '/includes/config.php' ), 'const WPDC_SESSION_CEILING' )
);
check(
	// Both args, counted rather than merely present: with one shared check
	// string, dropping it from `product` and leaving it on `bump` looked
	// identical -- which a mutation showed.
	//
	// Counted per ROUTE, not per file. A file-wide count of two held while two
	// routes existed; adding a third route that also validates an id would have
	// made it three and turned the check red for correct code, and the obvious
	// repair -- raising the number -- would have hidden a validator dropped
	// somewhere else. What actually matters is that every id argument any route
	// declares carries the validator.
	'BELL: the REST route validates BOTH ids before they leave the site',
	( static function ( string $code ): bool {
		$ids = 0;
		foreach ( preg_split( '/register_rest_route\(/', $code ) as $block ) {
			// Only the argument declarations, so prose mentioning an id does not
			// count as one.
			$declared = preg_match_all( "/'(product|bump)'\\s*=> array\\(/", $block );
			if ( 0 === $declared ) {
				continue;
			}
			if ( $declared !== substr_count( $block, "'validate_callback' => 'wpdc_is_product_id'" ) ) {
				return false;
			}
			$ids += $declared;
		}
		// At least the session route's two, so a rename that empties the loop
		// cannot report success.
		return $ids >= 2;
	} )( $rest )
);
check(
	// Was "the request is same-origin, so the nonce cookie is actually sent".
	// Its premise died with the nonce -- and it would have kept passing on the
	// price route's line alone, which is a check reporting on something it no
	// longer describes.
	//
	// Pinned at the shipped bytes, on BOTH doors: the header cannot come back
	// through the JavaScript, and the value cannot come back through what the
	// shortcode hands the browser. Either one alone re-arms the 403.
	'BELL: nothing ships a nonce to the browser any more',
	! str_contains( $js, 'x-wp-nonce' )
		&& ! str_contains( $js, 'cfg.nonce' )
		&& ! str_contains( $shortcode, 'wp_create_nonce' )
		// And both POSTs go out without a cookie, so core's cookie path is not
		// entered at all rather than merely satisfied.
		&& substr_count( $js, "credentials: 'omit'" ) >= 2
);
check(
	'BELL: Apple Pay is served on init, before canonical redirects',
	str_contains( $applepay, "add_action( 'init'" ) || str_contains( source( $root . '/wp-dodo-checkout.php' ), "'init', 'wpdc_serve_apple_pay_association'" )
);
check(
	'BELL: a missing association file falls through rather than serving an empty 200',
	str_contains( $applepay, 'is_readable' ) && str_contains( $applepay, 'return;' )
);
check(
	'BELL: every include refuses to run outside WordPress',
	( static function ( string $root ): bool {
		$files = glob( $root . '/includes/*.php' );
		// Zero files scanned must not read as "scanned, all fine" -- a moved
		// directory would have made this pass forever.
		if ( ! $files || count( $files ) < 5 ) {
			return false;
		}
		foreach ( $files as $file ) {
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

// ─── Markup, and the selectors that hunt it ──────────────────────────────────
//
// The defect this exists for: checkout.js looked for '.wpdc' while
// shortcode.php rendered 'wp-dodo-checkout'. Every click on every buy button
// was swallowed at that line -- no request, no message, nothing in the console
// -- while the REST route, the secret, the session mint, the webhook and the
// ledger behind it were all correct. Thirty-five checks passed the whole time,
// because every one of them looked at a single file.
//
// Same shape as the shortcode-tag check above: pull a name out of one file and
// assert it in another. Both directions, because they catch different faults --
// a selector with no markup is a dead button, and markup with no selector is
// dead weight that outlives whoever added it.

/**
 * Every class checkout.js hunts.
 *
 * `part(root, '.x')` counts too. Since the window is lifted to <body>, the
 * block and its dialog are two subtrees and every lookup goes through that
 * helper -- so a hunter that only knew `querySelector` stopped seeing almost
 * all of them, and the two checks below started passing on a shrunken set.
 */
$hunted = ( static function ( string $js ): array {
	preg_match_all(
		"/(?:\\.(?:closest|querySelector(?:All)?)\\(\\s*|\\bpart\\(\\s*root\\s*,\\s*)'\\.([\\w-]+)'/",
		$js,
		$m
	);
	return array_values( array_unique( $m[1] ) );
} )( $js );

/** Every class shortcode.php renders, from every class="..." attribute. */
$rendered = ( static function ( string $php ): array {
	preg_match_all( '/class="([^"<]*)"/', $php, $m );
	$out = array();
	foreach ( $m[1] as $attr ) {
		foreach ( preg_split( '/\s+/', trim( $attr ) ) as $cls ) {
			if ( '' !== $cls ) {
				$out[] = $cls;
			}
		}
	}
	return array_values( array_unique( $out ) );
} )( $shortcode );

check(
	// A regex that matches nothing reads as "everything is fine": both checks
	// below pass trivially on an empty set. This file already guards one regex
	// that way, and without the same guard here the whole section is decoration.
	'BELL: both extractions actually found something',
	count( $hunted ) > 0 && count( $rendered ) > 0
);
check(
	'BELL: every class the JS hunts is rendered by the shortcode',
	array_values( array_diff( $hunted, $rendered ) ) === array()
);
check(
	// The other face of the same defect: wpdc__frame was rendered for months,
	// referenced by no script and styled by no rule, left over from an
	// embedded-iframe design that never shipped.
	'BELL: every rendered class is used by the JS or styled by the CSS',
	( static function ( array $rendered, array $hunted, string $root ): bool {
		$css = (string) file_get_contents( $root . '/assets/checkout.css' );
		foreach ( $rendered as $cls ) {
			if ( in_array( $cls, $hunted, true ) ) {
				continue;
			}
			if ( str_contains( $css, '.' . $cls ) ) {
				continue;
			}
			return false;
		}
		return true;
	} )( $rendered, $hunted, $root )
);

// ─── The overlay, and the three reasons it never opened ──────────────────────
//
// Same shape as the selector check above, one layer out: a name pulled from one
// place and asserted in another. Here the other place is Dodo's own shipped
// bundle, because the mismatch was with THEIR global, not with ours.
//
// checkout.js tested `window.DodoPayments`. The UMD build attaches exactly one
// global and exports the namespace inside it:
//
//   (globalThis).DodoPaymentsCheckout = {} ... e.DodoPayments = W
//
// so `window.DodoPayments` is never set, the overlay branch was unreachable,
// and every display="overlay" navigated instead. Nothing failed: falling
// through to a navigation is a real branch with a comment explaining it. The
// overlay had never once opened, and thirty-nine checks passed throughout.

/**
 * checkout.js with its comments removed.
 *
 * Every rule below asserts on EXECUTABLE text, and this is why. Two of them
 * were first written against the whole file and two mutations walked straight
 * through: replacing the SDK lookup with `null` left the name standing in the
 * paragraph explaining the lookup, and gutting the warning left `console.warn`
 * standing in the guard beside it. A check that a comment can satisfy is a
 * check that the code can fail.
 */
$jsCode = ( static function ( string $js ): string {
	$js = preg_replace( '#/\*.*?\*/#s', '', $js );
	// Line comments, but not the // in a URL.
	return (string) preg_replace( '#(?<!:)//[^\n]*#', '', (string) $js );
} )( $js );

check(
	'BELL: the overlay reads the global the CDN bundle actually sets',
	// The assignment, not the name: the name also appears in the paragraph above it.
	preg_match( '/ns\s*=\s*window\.DodoPaymentsCheckout/', $jsCode ) === 1
);

check(
	'BELL: mode is the environment Dodo requires, not a display type',
	// The contract is `mode: "test" | "live"`, required. This passed 'overlay',
	// which is neither -- and there was no setting to get wrong, because there
	// was no setting. It is derived from the session URL now, so it cannot
	// disagree with the environment that minted it.
	! preg_match( "/mode:\s*'overlay'/", $jsCode )
		&& preg_match( "/mode:\s*environmentFor\(/", $jsCode ) === 1
);

check(
	'BELL: an unrecognised checkout host resolves to live, not test',
	// The asymmetry is the point. A live session opened in test mode is the
	// direction that quietly walks a real card into a test flow; the reverse
	// fails where somebody can see it.
	preg_match( "/return\s+\/\(\^\|\\\.\)test\\\.\/\.test\(host\)\s*\?\s*'test'\s*:\s*'live'/", $js ) === 1
);

check(
	'BELL: onEvent is passed, so a failed checkout says something',
	// Required by the SDK contract and absent before: a failed or abandoned
	// checkout produced no message for the customer and no line for anyone
	// debugging it.
	str_contains( $jsCode, 'onEvent:' ) && str_contains( $jsCode, 'checkout.error' )
);

check(
	'BELL: a missing SDK is reported, never swallowed',
	// The navigation still happens -- a customer trying to pay must not be
	// stopped by our script loader -- but silence is what hid the bug above for
	// as long as it existed.
	// The CALL. `console.warn` alone also matches the guard `&& console.warn)`.
	str_contains( $jsCode, 'console.warn(' )
);

check(
	'SILENCE: the false Apple Pay claim is gone',
	// It said the redirect was "the only mode where Apple Pay is available at
	// all". Dodo's documentation says the opposite in as many words: all
	// digital wallets are supported in overlay checkout. That sentence was
	// about to decide which mode this site shipped.
	! preg_match( '/only mode where Apple Pay/i', $js )
);

// ─── Report ──────────────────────────────────────────────────────────────────

// ─── Every option the plugin READS must be one somebody can WRITE ────────────
//
// The gap this catches was shipped: wpdc_api_key() fell back to
// get_option( 'wpdc_api_key' ) and nothing registered or rendered it. A
// fallback to a value nobody can set is a promise the code makes and the
// interface breaks, and it is invisible from either side alone.

$config   = source( $root . '/includes/config.php' );
$settings = source( $root . '/includes/settings.php' );

preg_match_all( "/get_option\(\s*'([a-z0-9_]+)'/", $config, $reads );
$read_names = array_values( array_unique( $reads[1] ) );

check(
	'SILENCE: config.php reads at least one option, so the check below is not vacuous',
	count( $read_names ) > 0
);

foreach ( $read_names as $name ) {
	// Matched INSIDE a register_setting call, not anywhere in the file. The
	// first version of this check tested `str_contains( $settings, "'name'" )`
	// and passed on the <label for> and the get_option that were already there
	// -- it said "registered" and proved "appears". Caught by a mutation that
	// renamed the registration and killed nothing.
	check(
		"BELL: the option {$name} is registered, so it can actually be set",
		1 === preg_match( "/register_setting\(\s*'wpdc',\s*'{$name}'/", $settings )
	);
}

// ─── A wp-config constant must survive Save ──────────────────────────────────
//
// The same defect found in a shipped plugin earlier: rendering the resolved key
// into the field means the next Save copies it into an option, putting it
// exactly where the constant existed to keep it out. `disabled` is what fixes
// it, and the reason is the browser rather than the styling: a disabled input
// is not submitted, so Save has nothing to write.

check(
	'BELL: the key field is disabled when the constant is set',
	str_contains( $settings, 'disabled( $from_constant )' )
);
check(
	'BELL: and it renders neither the resolved key nor the stored option',
	! str_contains( $settings, 'esc_attr( $stored )' )
		&& ! str_contains( $settings, 'esc_attr( wpdc_api_key() )' )
);
check(
	// This replaced a rule about hiding the button. The button used to be hidden
	// while the checkout was open, so a second click could not start a second
	// session -- and a CSS `display` outranking [hidden] quietly defeated it.
	// The veil makes the same guarantee structurally: a fixed pseudo-element
	// across the whole viewport, so the button behind it is not reachable and
	// there is no author `display` that can defeat it.
	//
	// It is NOT the modal top layer any more -- see the Apple Pay checks below.
	// So the guarantee is the veil's, and this is the check that says so: drop
	// the ::before and the shop behind the checkout becomes clickable again.
	'BELL: a veil covers the shop, so a second click cannot reach the button',
	str_contains( $jsCode, 'dialog.show();' )
		&& ! str_contains( $jsCode, 'button.hidden = true' )
		// A SIBLING, not a `::before` on the dialog. As a child at `z-index: -1`
		// it painted over the dialog's own background and the page showed
		// through the checkout on a phone.
		&& 1 === preg_match( '/\.wpdc__veil\s*\{[^}]*position:\s*fixed/', $css )
		&& str_contains( $jsCode, "veil.className = 'wpdc__veil'" )
		&& ! preg_match( '/\.wpdc__dialog\[open\]::before/', $css )
);
check(
	// The mobile fault, nailed down because it is invisible in the file and
	// only shows on a phone.
	//
	// The site's own stylesheet carries `.wp-dodo-checkout { align-items: center }`.
	// The lift puts that class on the dialog, so the rule follows it there --
	// and a CENTRED grid item is not stretched to its track. Measured on a
	// 375x812 phone: the 889px scroll container centred itself in a 737px
	// window and overflowed 77px above and 76px below, where `overflow: hidden`
	// cut it. Product image, name and price off the top; Dodo's pay button off
	// the bottom. `overflow-y: auto` never engaged.
	//
	// Both classes, because one loses to the theme on source order. Important,
	// because losing does not look like a style difference -- it looks like a
	// broken checkout.
	'BELL: the window stretches its scroller, so a theme cannot centre it out of view',
	1 === preg_match(
		'/\.wp-dodo-checkout\.wpdc__dialog\s*\{[^}]*align-items:\s*stretch\s*!important/',
		$css
	)
);
check(
	// The harness defines WPDC_VERSION on the main file's behalf. If the two drift
	// the cache key under test is not the cache key that ships.
	'BELL: the version the tests stand in for is the version the plugin declares',
	1 === preg_match( "/define\( 'WPDC_VERSION', '([^']+)' \);/", source( $root . '/wp-dodo-checkout.php' ), $m )
		&& WPDC_VERSION === $m[1]
		// And the plugin HEADER, the third place the number lives and the only
		// one WordPress itself reads. A constant bumped without it ships a site
		// that reports the old version to the updater while serving asset URLs
		// from the new one.
		&& 1 === preg_match( '/^ \* Version: (.+)$/m', source( $root . '/wp-dodo-checkout.php' ), $h )
		&& WPDC_VERSION === trim( $h[1] )
);
// ─── A cache from an older shape is discarded, not trusted ──────────────────
//
// The one that got away. The key carries the plugin version, which covers
// releases and NOT a shape that changed while the version stood still -- every
// moment during development, and every hotfix that adds a field. The failure is
// not a notice: a typed function called with a missing key throws, and the
// product page returns 500 for as long as the entry lives. It happened.

configure();
set_transient( wpdc_catalog_key(), array( 'pdt_old' => array( 'name' => 'Old', 'price' => 100 ) ), 600 );
respond( 200, array( 'items' => array(
	array( 'product_id' => 'pdt_new', 'name' => 'New', 'image' => 'https://img/x.png', 'description' => 'd', 'price' => 200, 'currency' => 'EUR' ),
) ) );
$refetched = wpdc_catalog();
check(
	'BELL: a cached row missing a field this build reads is treated as a miss',
	array( 'pdt_new' ) === array_keys( $refetched )
);
check(
	// isset first: with the guard removed this array holds the OLD row, and
	// indexing a key that is not there turns a clean red into a PHP warning
	// plus a red. A failing check should say what is wrong, not add noise.
	'BELL: and every row it returns carries every field',
	isset( $refetched['pdt_new'] )
		&& array( 'name', 'image', 'description', 'price', 'currency' ) === array_keys( $refetched['pdt_new'] )
);

check(
	// The cached catalogue is a shape, not just data. Adding a field to it left
	// every updated site reading rows written by the previous version -- ten
	// minutes of "Undefined array key" on a page a customer is standing on.
	// Found in a browser; a test builds its own cache and never meets a stale
	// one, which is exactly why this asserts the mechanism instead.
	'BELL: the catalogue cache key carries the version, so an update invalidates it',
	str_contains( $config, "'wpdc_catalog_' . WPDC_VERSION" )
		&& ! preg_match( "/transient\(\s*'wpdc_catalog'/", $client )
);
check(
	// A code printed on a newsletter with nowhere to type it is a support email
	// instead of a sale.
	'BELL: a discount code can be entered',
	str_contains( $client, "'allow_discount_code'           => true" )
);
check(
	// The white block in every screenshot. A min-height on the `iframe` selector
	// hits BOTH iframes the SDK injects, and the express wallet one is sized to
	// a few pixels when no wallet can be offered -- so a rule meant to stop the
	// modal opening as a sliver turned an invisible element into half a screen
	// of nothing above the payment methods. Reserve on the container, never on
	// the elements the SDK is sizing itself.
	// The frame reserves height only while it is WAITING, and only on the
	// container. On the iframe selector it hit both frames the SDK injects --
	// including the express wallet element, which is a few pixels tall when no
	// wallet can be offered -- and turned an invisible element into half a
	// screen of nothing above the payment methods.
	'BELL: no height is forced onto the iframes the SDK sizes',
	str_contains( $css, '.wpdc__frame.is-loading {' )
		&& ! preg_match( '/\.wpdc__frame iframe \{[^}]*min-height/', $css )
);
check(
	// No TOP padding. It was added because Dodo's payment step brings none of
	// its own -- but their contact step does, so on the first screen the two
	// stacked into a band of empty white above the first field. The close button
	// is a solid disc with a border precisely so it stays legible over whatever
	// their frame puts beneath it.
	// Small, and the size is the argument. At 2.5rem it doubled with the spacing
	// Dodo's contact step brings and left a band of white; at zero the payment
	// step -- which brings none -- went flush against the edge, under the close
	// button. The sides get air because their inset is a few pixels on a narrow
	// screen and content against the glass reads as a rendering fault.
	// TWO screens, two values, which is what three rounds of moving one number
	// missed. Their contact step brings its own top spacing and the two stacked
	// into a band of white; their payment step brings none and went flush
	// against the edge. Small by default, more once the payment step arrives.
	'BELL: the contact step is tight and the payment step is not',
	1 === preg_match( '/\.wpdc__dialog \.wpdc__frame \{[^}]*padding: \.5rem 1\.75rem 1\.25rem/', $css )
		&& 1 === preg_match( '/\.wpdc__frame\.is-payment \{[^}]*padding-top/', $css )
		&& str_contains( $js, "frame.classList.add('is-payment')" )
		&& str_contains( $js, "frame.classList.remove('is-payment')" )
);
check(
	// The SDK injects the express wallet element late and nearly collapsed. When
	// it fills in it pushes everything down while the scroll position stays put,
	// so a customer looking at the payment step is suddenly looking at the middle
	// of a region that did not exist a second ago. It reads as a jump into empty
	// space. The element is in OUR document, so its growth is observable.
	//
	// Counted, not merely present. The first version of this check tested that
	// the name appeared in the file -- which the DEFINITION satisfies on its
	// own, so deleting the call killed nothing. Fourth time today a check
	// matched a string instead of a behaviour.
	'BELL: a late-arriving wallet element does not leave the customer mid-scroll',
	2 === substr_count( $js, 'watchForLateGrowth' )
		&& str_contains( $js, 'frame.scrollTop = 0' )
		&& str_contains( $js, 'ResizeObserver' )
);
check(
	// Once. A customer who scrolled down on purpose must not be yanked back
	// every time something reflows.
	'BELL: and it corrects the scroll once, not on every reflow',
	str_contains( $js, 'if (settled) return;' ) && str_contains( $js, 'observer.disconnect();' )
);
check(
	// Measured, not chosen. At 544 Dodo's own checkout breaks its layout and
	// leaves a tall band of white above the content, which reads as a broken
	// embed and is a narrow viewport. The SDK reported the same content height
	// from 640 to 900, so wider buys nothing.
	// 640 was the one-column width, measured: below it Dodo's checkout breaks its
	// own layout. The frame keeps that column and the summary sits beside it.
	'BELL: the modal is wide enough that the checkout inside it renders',
	str_contains( $css, 'grid-template-columns: 1fr 640px' )
		&& str_contains( $css, '--wpdc-dialog-width: 1040px' )
);
check(
	// Dodo's inline documentation: a compliant embed shows item descriptions and
	// transaction totals -- subtotal, tax, grand total. Their frame carries them
	// only after the contact step; their reference layout puts them beside it.
	'BELL: the compliant summary is present, with all three totals',
	str_contains( $shortcode, 'data-row="subtotal"' )
		&& str_contains( $shortcode, 'data-row="tax"' )
		&& str_contains( $shortcode, 'data-row="total"' )
		&& 2 === substr_count( $shortcode, 'wpdc_render_item' )
);
// ─── Every row the panel renders is a row the script fills ──────────────────
//
// The same shape of defect as the class selector that once swallowed every
// click: two files, one set of names, and nothing comparing them. A row in the
// markup that the script never writes to stays blank forever, and a key the
// script writes with no row to write into goes nowhere. Both directions,
// because they fail differently and neither is visible in one file.

$panelRows = ( static function ( string $php ): array {
	preg_match_all( '/data-row="(\w+)"/', $php, $m );
	sort( $m[1] );
	return $m[1];
} )( $shortcode );

$paintedRows = ( static function ( string $js ): array {
	if ( ! preg_match( '/var rows = \{(.*?)\};/s', $js, $m ) ) {
		return array();
	}
	preg_match_all( '/^\s*(\w+):/m', $m[1], $keys );
	sort( $keys[1] );
	return $keys[1];
} )( $js );

check(
	'SILENCE: the panel renders rows at all, so the check below is not vacuous',
	count( $panelRows ) > 0 && count( $paintedRows ) > 0
);
check(
	'BELL: every row in the panel is one the script fills, and the reverse',
	$panelRows === $paintedRows
);

// Every class this stylesheet gives a `display` to, that the script also toggles
// `hidden` on, needs an explicit [hidden] rule -- the UA rule loses to any
// author `display`. It shipped twice today: the buy button, then the totals rows,
// where "Discount" stood with nothing beside it. Derived rather than listed, so
// the next element to grow a display is covered without anyone remembering.
$hiddenToggled = ( static function ( string $js ): array {
	preg_match_all( '/(\w+)\.hidden = /', $js, $vars );
	preg_match_all( '/querySelector\(\s*\x27\.([\w-]+)\x27\s*\)/', $js, $sel );
	return array_values( array_unique( $sel[1] ) );
} )( $js );

check(
	'SILENCE: the script toggles hidden on something, so the check below bites',
	str_contains( $js, '.hidden = ' )
);
check(
	// The rows are one live case: display:flex, hidden by the script.
	'BELL: a row the script hides is actually hidden',
	str_contains( $css, '.wpdc__row[hidden]' )
		&& 1 === preg_match( '/\.wpdc__row\[hidden\] \{[^}]*display: none/', $css )
);
check(
	// The third instance of the same collision in one day, and the worst: author
	// rules beat the user agent's regardless of specificity, so `display: grid`
	// on the dialog defeated the browser's own
	// `dialog:not([open]) { display: none }`. The checkout was laid out on the
	// page at load, with no backdrop and nobody having clicked anything.
	'BELL: a closed dialog is not displayed',
	1 === preg_match( '/\.wpdc__dialog:not\(\[open\]\) \{[^}]*display: none/', $css )
);
check(
	// Derived, so the next element to grow a display is covered without anyone
	// remembering: every class this stylesheet gives a `display` to and that is
	// hidden natively -- [hidden] or a closed dialog -- has its hidden case
	// written back.
	'BELL: every display we set gives the platform its hidden case back',
	( static function ( string $css ): bool {
		preg_match_all( '/^\.([\w-]+)[^{]*\{[^}]*\bdisplay:\s*(?!none)/m', $css, $m );
		foreach ( array_unique( $m[1] ) as $class ) {
			if ( ! str_contains( $css, '.' . $class . '[hidden]' )
				&& ! str_contains( $css, '.' . $class . ':not([open])' )
				&& in_array( $class, array( 'wpdc__row', 'wpdc__dialog', 'wpdc__button' ), true ) ) {
				return false;
			}
		}
		return true;
	} )( $css )
);

// ─── Every customer-facing string has a German translation ──────────────────
//
// The Text Domain header alone does nothing for a plugin that is not on
// wordpress.org, so the strings stayed English beside a German checkout. The
// header, the loader and the catalogue are three separate things and any one of
// them missing looks identical from the outside -- so all three are asserted,
// and the catalogue is compared against the strings actually in the source
// rather than against a list somebody has to remember to update.

$po = source( $root . '/languages/wp-dodo-checkout-de_DE.po' );

check(
	'BELL: the plugin declares where its translations live and loads them',
	str_contains( source( $root . '/wp-dodo-checkout.php' ), 'Domain Path: /languages' )
		&& str_contains( source( $root . '/wp-dodo-checkout.php' ), 'load_plugin_textdomain' )
);
check(
	'BELL: the compiled catalogue ships, not just the source po',
	file_exists( $root . '/languages/wp-dodo-checkout-de_DE.mo' )
);

/** Strings a VISITOR can see: shortcode output and the client's messages. */
$visible = ( static function ( string ...$files ): array {
	$out = array();
	foreach ( $files as $code ) {
		preg_match_all( "/(?:esc_html__|esc_attr__|__)\(\s*'([^']+)'/", $code, $m );
		$out = array_merge( $out, $m[1] );
	}
	return array_values( array_unique( $out ) );
	// rest.php too: it renders a sentence to a visitor when a session cannot be
	// created, and it sat outside this check while doing so.
} )( $shortcode, $client, $rest );

check(
	'SILENCE: there are visitor-facing strings, so the check below is not vacuous',
	count( $visible ) > 5
);
$untranslated = array();
foreach ( $visible as $string ) {
	if ( ! str_contains( $po, 'msgid "' . str_replace( '"', '\\"', $string ) . '"' ) ) {
		$untranslated[] = $string;
	}
}
check(
	// Named, not counted: a failure should say which sentence a German customer
	// would read in English.
	'BELL: no visitor-facing string is missing from the German catalogue' .
		( $untranslated ? ' [' . implode( ' | ', array_slice( $untranslated, 0, 3 ) ) . ']' : '' ),
	array() === $untranslated
);

// A caller that names a language gets it passed through; one that names none
// sends no `customization` at all, and Dodo decides. The site locale is never
// consulted -- it describes the shop, and on the operator's install it said
// en_US while the customer was reading German.
configure();
catalogue( array( 'pdt_pro' ) );
respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_l' ) );
wpdc_create_session( 'pdt_pro', 1, null, 'de' );
$withLang = json_decode( last_request()['args']['body'], true );
check(
	'BELL: a language from the caller reaches Dodo',
	'de' === ( $withLang['customization']['force_language'] ?? '' )
);
// Minted here rather than read off `$sent`, which was last assigned about nine
// hundred lines earlier in an unrelated block. The check LOOKED like it
// inspected the request just made and did not -- and `isset()` on an undefined
// variable is false, so `!isset(...)` was true no matter what: it passed even
// with the variable removed entirely. Probed.
respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_n' ) );
wpdc_create_session( 'pdt_pro', 1, null, null );
$withoutLang = json_decode( last_request()['args']['body'], true );
check(
	// The theme is always there now; what must stay absent is a guessed language.
	'BELL: and no language means no forced language',
	is_array( $withoutLang ) && ! isset( $withoutLang['customization']['force_language'] )
);

check(
	// The UA stylesheet centres an open dialog with position:absolute and
	// margin:auto -- centred in the DOCUMENT, so on a scrolled page the checkout
	// lands wherever the reader happens to be. It was photographed half over the
	// footer with the page header still above it. A modal belongs to the
	// viewport.
	'BELL: the modal is pinned to the viewport, not to the document',
	1 === preg_match( '/\.wpdc__dialog \{[^}]*position: fixed/', $css )
		&& 1 === preg_match( '/\.wpdc__dialog \{[^}]*margin: auto/', $css )
);

check(
	// Stacked on a phone, the panel and the frame each kept their own overflow,
	// so the panel was clipped mid-sentence with nothing to scroll it. One
	// column, one scroller.
	// The strip stays, the frame scrolls. The dialog used to be the single
	// scroller, so the header -- and the close button positioned against it --
	// scrolled away with the content, which is what `position: fixed` was then
	// used to paper over, pinning the button to the VIEWPORT instead: floating
	// mid-screen, attached to nothing.
	// A pinned header was tried and is wrong on a phone: it costs 3.4rem of the
	// screen permanently, on the one surface where the pay button has to come
	// into view. The window scrolls as one document, the way a page does -- and
	// the close button rides with the header it belongs to.
	// The header rides with the content -- the operator's call, and right for a
	// phone, where a pinned header costs 3.4rem permanently on the surface the
	// pay button has to reach.
	'BELL: on a phone the header scrolls with the content',
	1 === preg_match( '/@media \(max-width: 900px\)[\s\S]{0,2000}\.wpdc__scroll \{[^}]*overflow-y: auto/', $css )
		&& ! preg_match( '/\.wpdc__close \{[^}]*position: fixed/', $css )
);
check(
	// THE reason the extra element exists. An absolutely positioned button
	// inside a scrolling box scrolls away with it, which is how the only way out
	// of a checkout disappeared on a phone -- and `position: fixed` pins to the
	// VIEWPORT rather than the dialog, so it floated mid-screen attached to
	// nothing. The scrolling happens one level in; the button hangs off the
	// dialog and stays.
	'BELL: the scroller is inside the dialog, so the close button cannot scroll away',
	str_contains( $shortcode, '<div class="wpdc__scroll">' )
		&& 1 === preg_match( '/\.wpdc__scroll \{[^}]*overflow/', $css . ' ' )
		&& ! preg_match( '/\.wpdc__dialog \{[^}]*overflow-y: auto/', $css )
);
check(
	// Dodo's own inset is a few pixels on a narrow screen, so their fields ran to
	// the glass and the window read as unfinished.
	'BELL: their frame gets room down both sides, on both sizes',
	1 === preg_match( '/\.wpdc__dialog \.wpdc__frame \{[^}]*padding: \.5rem 1\.75rem 1\.25rem/', $css )
		&& 1 === preg_match( '/@media \(max-width: 900px\)[\s\S]{0,5000}padding: \.5rem 1\.5rem 1\.5rem/', $css )
);
check(
	// Subtotal, VAT and Total are rendered by us, in WordPress's locale, while
	// the frame beside them follows the shortcode or the browser -- so one window
	// spoke two languages. They follow the same decision now, and the locale is
	// restored straight after so the rest of the page is untouched.
	// `switch_to_locale` was the obvious way and it does not work: measured on a
	// real install, it returns FALSE when the site has no core language pack for
	// that locale -- which is the normal state of an English WordPress selling to
	// Germans, the exact case this exists for. Our own catalogue is loaded by
	// path instead, and put back straight after.
	'BELL: our labels speak the language the checkout speaks',
	str_contains( $shortcode, 'wpdc_load_catalogue( $lang )' )
		&& str_contains( $shortcode, 'wpdc_restore_catalogue();' )
		&& ! str_contains( $shortcode, 'switch_to_locale(' )
);

check(
	// `dialog.close()` fires `close` SYNCHRONOUSLY, and the listener for it calls
	// closeFrame again. With the marker cleared at the END, that second entry
	// found openRoot still set and ran the whole body twice -- Checkout.close()
	// included, on an SDK already told once. Claiming it first makes re-entry a
	// no-op.
	'BELL: closing claims the open marker before it closes anything',
	1 === preg_match( '/function closeFrame[\s\S]{0,600}openRoot = null;[\s\S]{0,1200}dialog\.close\(\)/', $js )
		// Two occurrences: the declaration at the top and the one claim inside
		// closeFrame. A third would mean the marker is cleared somewhere else
		// too, which is how re-entrancy came back.
		&& 2 === substr_count( $js, 'openRoot = null;' )
);

check(
	/*
	 * Apple Pay on the desktop, and why this checkout is not a modal.
	 *
	 * `showModal()` puts an element in the TOP LAYER, which is not a z-index but
	 * a plane above the whole document, and it makes the rest of the document
	 * INERT. Apple's sheet is an ordinary element on <body> at z-index 99998, so
	 * under a modal checkout it paints underneath and cannot be clicked. Two
	 * billion would not help.
	 *
	 * Reported from a live checkout, in these words: "er war da nur unter
	 * unserem popup".
	 */
	'BELL: the checkout never takes the top layer',
	str_contains( $jsCode, 'dialog.show();' )
		&& ! str_contains( $jsCode, 'showModal' )
);

check(
	// A finished order showed a greyed "Rabattcode", "Einlösen" and "Code
	// entfernen" beside the licence key. Disabled controls still read as
	// controls, and they invite the one action that can no longer work.
	//
	// Both halves, because either alone is a no-op: the JS hides the form, and
	// the CSS owes `[hidden]` its display back -- `display: grid` on that form
	// beats the attribute, which is the same trap this file has fallen into
	// three times before.
	'BELL: a completed order carries no discount form at all',
	1 === preg_match( "/function showDone[\\s\\S]{0,1200}part\\(root, '\\.wpdc__discount'\\)[\\s\\S]{0,60}hidden = true/", $jsCode )
		&& 1 === preg_match( '/\\.wpdc__discount\\[hidden\\]\\s*\\{[^}]*display:\\s*none/', $css )
);

check(
	/*
	 * The window leaves the page tree at startup.
	 *
	 * Measured on /buecher/wordpress-band-1/: the checkout sits inside a
	 * WPBakery column carrying `transform: matrix(1, 0, 0, 1, ...)`. A transform
	 * creates a stacking context AND becomes the containing block for
	 * `position: fixed`, so the window was painted over by a product card in the
	 * next column and centred itself in the column instead of the viewport. No
	 * z-index escapes a transformed ancestor.
	 *
	 * At startup, while the dialog is still EMPTY: re-parenting reloads any
	 * iframe inside it, and doing this later would tear down a live payment.
	 */
	'BELL: the window is lifted to <body>, before the SDK mounts anything in it',
	1 === preg_match( '/document\\.body\\.appendChild\\(dialog\\)/', $jsCode )
		&& 1 === preg_match( '/DOMContentLoaded.[\\s\\S]{0,120}liftDialogs\\(\\)/', $jsCode )
		// The hash comes off every trigger at the same moment, and for the same
		// reason: the theme animates `a[href^="#"]` itself, so a cancelled
		// default cancels nothing.
		&& 1 === preg_match( '/DOMContentLoaded.[\\s\\S]{0,160}disarmTriggers\\(\\)/', $jsCode )
		&& 1 === preg_match( "/removeAttribute\\('href'\\)/", $jsCode )
		&& ! preg_match( '/Checkout\\.open[\\s\\S]{0,400}appendChild\\(dialog\\)/', $jsCode )
);

check(
	// Leaving the tree breaks two things that must not break: the armour keys on
	// an ANCESTOR class, and the block finds its dialog by searching inside
	// itself. Both were retargeted; either one left behind is silent -- the
	// panel's controls turn navy on navy, or the buy button stops opening.
	'BELL: the armour and the lookup survive the move',
	0 === preg_match( '/\\.wp-dodo-checkout \\.wpdc__dialog/', $css )
		&& 1 === preg_match( '/\\.wp-dodo-checkout\\.wpdc__dialog/', $css )
		&& str_contains( $jsCode, 'data-wpdc-owner' )
		// Exactly one, and it is the deliberate fallback INSIDE `dialogFor` --
		// for the moment before the lift and for a block without an id. A second
		// one anywhere else is a caller that will stop finding its dialog.
		&& 1 === substr_count( $jsCode, "root.querySelector('.wpdc__dialog')" )
		&& 1 === preg_match( "/function dialogFor\\([\\s\\S]{0,400}root\\.querySelector\\('\\.wpdc__dialog'\\)/", $jsCode )
);

check(
	// The only signal the order mail has for German or English. Without it the
	// mail falls back to the billing country, and an English page selling to a
	// German address sent German -- found on a real order placed with lang="en".
	'BELL: the session carries the language the buyer was reading',
	1 === preg_match( "/metadata.\\]\\s*=\\s*array\\(\\s*'uwp_lang'/", $client )
);

check(
	/*
	 * One class, two jobs -- and the seam between them.
	 *
	 * The lift gave the dialog `wp-dodo-checkout` so the armoured CSS would keep
	 * reaching it. Every handler ALSO finds its block with that class, so
	 * `closest()` from inside the window answered with the window: a discount
	 * code resolved to an element carrying no `data-product`, and the mint came
	 * back `400 missing parameter: product` to a customer holding a valid code.
	 *
	 * So no handler may take `closest('.wp-dodo-checkout')` at face value again.
	 */
	'BELL: handlers resolve the block, never the window that carries its class',
	0 === preg_match( "/(?:clear|form|button|trigger)\\.closest\\('\\.wp-dodo-checkout'\\)/", $jsCode )
		&& 1 === preg_match( "/function rootFor[\\s\\S]{0,400}wpdc__dialog[\\s\\S]{0,200}wpdcOwner/", $jsCode )
);

check(
	// Any element can open the checkout -- a cover image, a price, a second
	// button further down the page. It reaches the SAME buy button rather than
	// carrying its own copy of the purchase path, so a trigger can never drift
	// away from the one thing that has been proven to work.
	'BELL: a marked element opens the checkout through the block\'s own button',
	// Capture phase, and prevented before anything else is decided: these are
	// `href="#"` links, and both the browser and Impreza's smooth scroll act on
	// that before a bubbling listener would. The page jumped to the top on
	// every cover click until this moved up here.
	1 === preg_match( "/closest\\('\\[data-wpdc-open\\]'\\)[\\s\\S]{0,900}event\\.preventDefault\\(\\)[\\s\\S]{0,200}blockForTrigger/", $jsCode )
		&&
	1 === preg_match( "/closest\\('\\[data-wpdc-open\\]'\\)/", $jsCode )
		&& 1 === preg_match( "/blockForTrigger[\\s\\S]{0,400}part\\(root, '\\.wpdc__button'\\)[\\s\\S]{0,200}button\\.click\\(\\)/", $jsCode )
);

check(
	/*
	 * The rule that keeps somebody from paying for the wrong book.
	 *
	 * Block ids are running numbers in render order, so moving the printed
	 * edition above the eBook in the page builder repoints every trigger that
	 * named one. The product id says WHAT is bought instead of WHERE it sits.
	 *
	 * And when it still cannot be decided -- several blocks, no id given --
	 * nothing opens. An image that does not react is a fault you can see; one
	 * that opens the wrong order is not.
	 */
	'BELL: an ambiguous trigger opens nothing rather than guessing',
	1 === preg_match( "/dataset\\.product === wanted/", $jsCode )
		&& 1 === preg_match( '/blocks\\.length === 1/', $jsCode )
		&& 1 === preg_match( '/console\\.warn[\\s\\S]{0,200}return null;\\s*\\}/', $jsCode )
);

check(
	// The veil is a `::before` at `z-index: -1`, and a negative z-index child
	// paints ABOVE its element's background -- so the dialog's own white never
	// covered it. Dodo's iframe is transparent, so the dimming came through the
	// payment form: card fields on a dark page with the site footer behind them.
	//
	// `.wpdc__scroll` is the first layer that is CONTENT rather than background,
	// which is why the opaque colour belongs there and nowhere else.
	'BELL: the checkout content is opaque, so the veil cannot show through it',
	1 === preg_match( '/\\.wpdc__scroll\\s*\\{[^}]*background:\\s*#fff/', $css )
);

check(
	// The arithmetic that replaces the detection. Above Impreza's fixed header
	// (111) so the checkout is not buried by the shop; below Apple's 99998 so a
	// wallet sheet lands on top without anyone watching for it.
	'BELL: the dialog stacks above this theme and below a wallet sheet',
	1 === preg_match( '/\\.wpdc__dialog\\[open\\]\\s*\\{[^}]*z-index:\\s*(\\d+)/', $css, $zed )
		&& (int) $zed[1] > 111
		&& (int) $zed[1] < 99998
);

check(
	// 0.7.2 detected the sheet and stepped down to non-modal for as long as it
	// was up. It watched `<apple-pay-modal>` -- and Apple paints into a sibling
	// div while leaving that element `visibility: hidden`, so it never fired and
	// the sheet stayed under our window.
	//
	// The lesson is not "watch a different element". It is that a checkout must
	// not depend on the private structure of somebody else's widget, because
	// that structure changes on their deploy and not on ours.
	"SILENCE: nothing here reaches into Apple's DOM",
	! str_contains( $jsCode, 'apple-pay-modal' )
		&& ! str_contains( $jsCode, 'walletSheetIsUp' )
		&& ! str_contains( $jsCode, 'wpdcWallet' )
		&& ! str_contains( $css, 'data-wpdc-wallet' )
);

check(
	// First of the three things `showModal()` gave for free. Escape is the one a
	// customer notices missing.
	'BELL: Escape still closes the checkout',
	1 === preg_match( "/addEventListener\\('keydown'[\\s\\S]{0,400}Escape[\\s\\S]{0,300}wpdc__dialog\\[open\\][\\s\\S]{0,120}closeFrame/", $jsCode )
);

check(
	// Second. A checkout that opens without moving focus leaves a keyboard or
	// screen-reader customer standing on the Buy button, behind a window they
	// cannot see.
	'BELL: opening moves focus into the dialog',
	1 === preg_match( "/dialog\\.show\\(\\);[\\s\\S]{0,400}querySelector\\('\\.wpdc__close'\\)[\\s\\S]{0,120}\\.focus\\(\\)/", $jsCode )
);

check(
	// Third. A modal dialog carries aria-modal implicitly; a non-modal one has to
	// say it, or assistive technology offers the whole shop behind the checkout
	// as if it were still available.
	'BELL: the non-modal dialog still declares itself modal to assistive tech',
	str_contains( $shortcode, '<dialog class="wpdc__dialog" aria-modal="true"' )
);

check(
	// A `1fr` track has an automatic minimum of its content, so the frame -- an
	// iframe the SDK gives a fixed pixel height -- pushed the row past the
	// dialog and the column would not scroll. The customer reached the form and
	// not the pay button.
	'BELL: the frame column can shrink, so it scrolls instead of overflowing',
	str_contains( $css, 'grid-template-rows: minmax(0, 1fr)' )
);
check(
	// showModal() focuses the dialog, and a focused element draws the browser's
	// focus ring -- a blue box around the whole checkout that reads as a
	// rendering fault. Removed on the dialog only; every control inside keeps
	// its own, and the close button has an explicit one.
	'BELL: the focus ring is off the dialog and still on its controls',
	1 === preg_match( '/\.wpdc__dialog \{[^}]*outline: none/', $css )
		&& str_contains( $css, '.wpdc__close:focus-visible' )
);
check(
	// A native dialog does NOT close on a backdrop click -- the platform gives
	// Escape and nothing else, while everything a customer has learned from
	// every other modal says the outside is a way out.
	// BOTH targets. The dark area used to be a `::before` inside the dialog, so
	// a click on it reported the dialog; it is a sibling now, so it reports the
	// veil. Checking only the dialog leaves the way out alive on a wide screen
	// and dead on a phone, where the veil covers everything the window does not.
	'BELL: clicking beside the checkout closes it, on the window AND on the veil',
	str_contains( $js, "hit.tagName === 'DIALOG'" )
		&& str_contains( $js, "classList.contains('wpdc__dialog')" )
		&& str_contains( $js, "classList.contains('wpdc__veil')" )
);
check(
	// A backdrop that outlives its window is a black sheet over the shop that
	// nothing can dismiss. It used to be part of the dialog, so `close()` took
	// it away for free; a sibling has to be told.
	'BELL: closing the checkout takes the veil with it',
	str_contains( $js, 'showVeil(false)' )
		&& false !== strpos( $js, 'dialog.close();' )
		&& strpos( $js, 'showVeil(false)' ) > strpos( $js, 'dialog.close();' )
);
check(
	// The sentence a customer reads is not the reason a developer needs.
	//
	// Dodo's `checkout.error` carries a whole event, and `event.data.message`
	// is the only part of it the panel shows. On Safari that part has been the
	// string "Load failed" -- WebKit's generic wording for a fetch that did not
	// complete. It names the symptom and hides which request, from which frame,
	// and why. Two rounds of mail with Dodo were spent on that label, because
	// it was the only thing anybody could copy out of a phone.
	//
	// The order is the assertion: whatever gets shown, the whole event is
	// logged FIRST. An edit that keeps `say()` and drops the log restores
	// exactly the state that cost those two rounds, and nothing about the
	// checkout would look any different.
	// `position: fixed`, not `overflow: hidden`. The polite version works on a
	// desktop and mobile Safari ignores it -- the page keeps scrolling under
	// the overlay, on exactly the screen where a stray thumb loses the form.
	//
	// `width: 100%` belongs to the same rule: a fixed body shrinks to its
	// content, and a layout that reflows the moment the checkout opens reads as
	// a broken page rather than as a dialog.
	//
	// The CALL is asserted, not only the machinery. A first version of this
	// check looked for the rule and for `classList.add` alone, and deleting
	// `lockPage(true)` from the open path left it green: everything needed to
	// lock the page existed, and nothing locked it.
	'BELL: the shop cannot be scrolled while the checkout is open',
	1 === preg_match( '/\.wpdc-locked\s*\{[^}]*position:\s*fixed/', $css )
		&& 1 === preg_match( '/\.wpdc-locked\s*\{[^}]*width:\s*100%/', $css )
		&& str_contains( $js, "body.classList.add('wpdc-locked')" )
		&& false !== strpos( $js, 'lockPage(true)' )
		&& strpos( $js, 'lockPage(true)' ) > strpos( $js, 'dialog.show();' )
);
check(
	// Two ways to leave somebody stranded, and both look like a broken browser
	// rather than a broken checkout.
	//
	// Forgetting the unlock freezes the shop after the dialog is gone. Keeping
	// the unlock but dropping `scrollTo` is subtler and just as bad: a fixed
	// body sits at the top, so everybody who opened the checkout halfway down a
	// sales page is returned to the masthead and has to find their place again.
	'BELL: closing the checkout gives the page back, at the offset it was left',
	str_contains( $js, 'lockPage(false)' )
		&& str_contains( $js, 'window.scrollTo(0, lockedAt)' )
		&& strpos( $js, 'lockPage(false)' ) > strpos( $js, 'dialog.close();' )
);
check(
	'BELL: a checkout.error logs the whole event, not only the sentence shown',
	false !== strpos( $js, "console.error('[wpdc] checkout.error', event)" )
		&& strpos( $js, "console.error('[wpdc] checkout.error', event)" )
			< strpos( $js, 'say(root, (event.data && event.data.message) || cfg.failed)' )
);
check(
	// On a 375px screen a 1rem margin spends room the form needs, and a card
	// whose edges nobody can see does not need rounded corners.
	// Tablets keep a margin ("a layer over the shop"). Phones go edge to edge
	// since 0.18.0, on the operator's word of 2026-10-27: at 390px the margin
	// cost the form its width and the page behind it read as clutter.
	'BELL: on a tablet the page still shows around the checkout, on a phone it does not',
	1 === preg_match( '/@media \(max-width: 900px\)[\s\S]{0,1400}width: min\(30rem/', $css )
		&& 1 === preg_match( '/@media \(max-width: 600px\)[\s\S]{0,200}width: 100vw/', $css )
);

check(
	// A shop can sell a German edition and an English one from the same site.
	// The site locale is one answer to a question that has two, and it describes
	// the SHOP rather than the edition being sold -- so the shortcode decides,
	// and the locale is only the fallback.
	'BELL: the shortcode can name the language, and the browser is the fallback',
	str_contains( $shortcode, "'lang'       => ''" )
		&& str_contains( $js, "root.dataset.lang || navigator.language" )
		&& str_contains( $rest, "'lang'     => array(" )
		&& str_contains( $client, 'wpdc_two_languages( trim( $lang ) )' )
);

// ─── Everyone lands in one of two languages ─────────────────────────────────
//
// The shop sells internationally and speaks German and English. Passing a
// visitor's own language through meant a French browser got a French checkout
// beside English labels -- worse than either alone, because it reads as a fault
// rather than as a shop that speaks two languages.

foreach ( array( 'de' => 'de', 'de-AT' => 'de', 'de_CH' => 'de', 'DE' => 'de',
	'fr' => 'en', 'fr-CA' => 'en', 'en-GB' => 'en', 'ja' => 'en', 'nl' => 'en' ) as $tag => $want ) {
	check(
		"BELL: {$tag} resolves to {$want}",
		$want === wpdc_two_languages( $tag )
	);
}

configure();
catalogue( array( 'pdt_pro' ) );
respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_f' ) );
wpdc_create_session( 'pdt_pro', 1, null, 'fr' );
$french = json_decode( last_request()['args']['body'], true );
check(
	// The one that matters: a language we do not speak must not reach Dodo, or
	// their half of the window speaks it and ours does not.
	'BELL: a language the shop does not speak never reaches Dodo',
	'en' === ( $french['customization']['force_language'] ?? '' )
);
check(
	// And the browser is normalised on the way out, not only on the way in.
	'BELL: the browser tag is collapsed in the script too',
	str_contains( $js, "/^de/i.test(tag) ? 'de' : 'en'" )
);
check(
	// The site locale is not a fallback anywhere: it describes the shop, and on
	// the operator's install it said en_US while the customer read German.
	'BELL: the language never comes from WordPress',
	str_contains( $js, 'navigator.language' )
		&& ! str_contains( $client, 'wpdc_language()' )
);
check(
	// It was a transparent glyph that read as decoration, and on a phone it was
	// the thing customers could not find.
	'BELL: the close control looks like a control',
	1 === preg_match( '/\.wpdc__close \{[^}]*background: #fff/', $css )
		&& 1 === preg_match( '/\.wpdc__close \{[^}]*border: 1px solid/', $css )
);
check(
	/*
	 * The mark is DRAWN, not typed.
	 *
	 * `&times;` is a mathematical operator borrowed to look like a cross, and it
	 * behaves like one: its weight, its size and how far it sits off centre are
	 * decided by whichever font the visitor's theme loaded. The same button is a
	 * hairline on one site and a blob on the next, and on this shop it came out
	 * light and sitting high in its circle.
	 *
	 * Two lines of SVG are the same shape in every theme, scale with the button
	 * and inherit its colour -- which is why the hover still works.
	 */
	'BELL: the close mark is drawn, not borrowed from a font',
	( static function ( string $source ): bool {
		/*
		 * The BUTTON, not the file, and that distinction IS this test.
		 *
		 * The first version asserted the character was absent from the whole
		 * file, and failed on a green tree -- because the paragraph above the
		 * button, the one explaining why we stopped using that character,
		 * contains it. A check a comment can break is a check a comment can
		 * also satisfy.
		 *
		 * Sixth time in this codebase, and this one was written ten minutes
		 * after the last. So the element is cut out and only that is read.
		 */
		$start = strpos( $source, '<button type="button" class="wpdc__close"' );
		if ( false === $start ) {
			return false;
		}
		$end = strpos( $source, '</button>', $start );
		if ( false === $end ) {
			return false;
		}
		$button = substr( $source, $start, $end - $start );

		return 1 === preg_match( '/<svg[\s\S]*<path/', $button )
			&& ! str_contains( $button, '&times;' )
			// Decorative: the button carries the label, so announcing the
			// drawing as well would say the action twice.
			&& str_contains( $button, 'aria-hidden="true"' );
	} )( $shortcode )
);
check(
	// Full bleed reads as a new page, and a customer who thinks they navigated
	// away behaves differently about closing it.
	'BELL: on a tablet the window keeps its margin',
	1 === preg_match( '/@media \(max-width: 900px\)[\s\S]{0,1200}width: min\(30rem, calc\(100vw - 2\.5rem\)\)/', $css )
		&& ! preg_match( '/@media \(max-width: 900px\)[\s\S]{0,1200}width: 100vw/', $css )
);

// ─── A known customer arrives filled in ─────────────────────────────────────
//
// Empty by default, because a WordPress shop has no idea who an anonymous
// visitor is and guessing fills somebody's checkout with somebody else's name.
// A filter, so the identity source stays outside a plugin whose job is selling.

configure();
catalogue( array( 'pdt_pro' ) );
$GLOBALS['wpdc_test_filters']['wpdc_customer'] = array( 'email' => 'k@example.com', 'name' => ' Kim ' );
respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_c' ) );
wpdc_create_session( 'pdt_pro' );
$known = json_decode( last_request()['args']['body'], true );
check(
	'BELL: a customer the site knows is passed to Dodo',
	'k@example.com' === ( $known['customer']['email'] ?? null ) && 'Kim' === ( $known['customer']['name'] ?? null )
);

configure();
catalogue( array( 'pdt_pro' ) );
$GLOBALS['wpdc_test_filters']['wpdc_customer'] = array( 'email' => 'not-an-address', 'name' => 'X' );
respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_d' ) );
wpdc_create_session( 'pdt_pro' );
$bad = json_decode( last_request()['args']['body'], true );
check(
	// A malformed address is worse than none: Dodo would take it, and the
	// receipt would go nowhere.
	'BELL: an address that is not one is dropped, not forwarded',
	! isset( $bad['customer'] )
);

check(
	// The close button is absolutely positioned in that corner, and on a phone
	// the panel is a single strip -- so without room reserved for it the button
	// sat ON the total, "24,99" cut in half behind a white disc, on the one line
	// of that strip that has to be readable.
	'BELL: on a phone the strip reserves room for the close button',
	1 === preg_match( '/@media \(max-width: 900px\)[\s\S]{0,3200}padding: 1rem 4rem/', $css )
);
check(
	// Dodo's breakdown carries an amount and no rate, and a customer reading
	// "VAT 1,63" cannot tell 7% from 19% -- the difference between a book and
	// everything else, and the number they will look for on the invoice. Derived
	// from their own two figures, and only when it lands somewhere sane.
	'BELL: the VAT line says which rate it is',
	str_contains( $js, 'function paintRate' )
		&& 2 === substr_count( $js, 'paintRate' )
		&& str_contains( $js, "(b.subTotal || 0) - (b.discount || 0)" )
		&& str_contains( $shortcode, 'wpdc__rate' )
);
check(
	// The country is not in the event, not anywhere else we can see, and a
	// country printed on a tax line is the kind of guess that ends up on a
	// receipt.
	'SILENCE: no country is invented for the tax line',
	! preg_match( '/(country|Land)\s*[:=]/i', $js )
);

check(
	// Dodo's own note on the field: "If not provided, the back button will not be
	// displayed." Without it a customer on the payment step could not return to
	// check the address they had typed -- only close the window and start again.
	'BELL: a back control exists, and its target is ours not the caller\'s',
	// A cancel target is a different question from a finish target: without one
	// Dodo shows no back control at all, so the front page is a real floor here.
	// Server-side either way -- a target a visitor could name would be an open
	// redirect on the shop's own domain.
	str_contains( $sent['cancel_url'] ?? '', 'shop.example' )
);
check(
	// Step one to step two swaps the frame's whole contents, and on a phone the
	// DIALOG is the scroller -- so somebody who scrolled to reach "Weiter zur
	// Zahlung" arrived on the payment step already scrolled past the wallet
	// button at the top of it.
	'BELL: a new step starts at its own beginning, in both scrollers',
	str_contains( $js, "checkout.customer_details_submitted" )
		&& str_contains( $js, 'scroll.scrollTop = 0' )
		&& 2 === substr_count( $js, 'frame.scrollTop = 0' )
);
check(
	// `top: 50%` centres against the positioning context, and that is the dialog
	// -- not the strip -- so on a phone the button floated halfway down the whole
	// window, over the form and half outside the rounded edge.
	'BELL: on a phone the close button sits in the strip, not mid-window',
	1 === preg_match( '/@media \(max-width: 900px\)[\s\S]{0,6000}\.wpdc__close \{[^}]*top: \.55rem/', $css )
		&& ! preg_match( '/@media \(max-width: 900px\)[\s\S]{0,6000}translateY\(-50%\)/', $css )
);

// ─── The labels are rendered before any JavaScript runs ─────────────────────
//
// Subtotal, VAT and Total come from PHP, and PHP cannot ask navigator.language.
// Falling back to the site locale put English labels beside a German checkout
// on a shop whose WordPress says en_US. Accept-Language is the same information
// one step earlier.

foreach ( array(
	'de-DE,de;q=0.9,en;q=0.8' => 'de',
	'de'                      => 'de',
	'fr-FR,fr;q=0.9'          => 'en',
	'en-GB,en;q=0.9'          => 'en',
	''                        => '',
) as $header => $want ) {
	$_SERVER['HTTP_ACCEPT_LANGUAGE'] = $header;
	check(
		"BELL: Accept-Language '{$header}' renders in " . ( '' === $want ? 'nothing' : $want ),
		$want === wpdc_request_language()
	);
}
unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );

check(
	// The function existing proves nothing: the first version of this block
	// tested only the resolution table, so deleting the fallback from the
	// shortcode killed no test and the labels would have gone back to English.
	//
	// BOTH shortcodes, counted. The header is now one step down: what they
	// consult is the PAGE's language, which asks Polylang first and falls
	// through to the header only where there is no Polylang to ask. Counting
	// catches a fallback dropped from either one.
	'BELL: and both shortcodes fall back to the page language, never the site locale',
	2 === substr_count( $shortcode, ': wpdc_page_language();' )
		&& ! preg_match( '/\$lang\s*=[^;]*get_locale/', $shortcode )
);

$GLOBALS['wpdc_test_pll']        = 'de';
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-GB,en;q=0.9';
$pageWins                        = wpdc_page_language();
$germanPrice                     = wpdc_format_price( 2499, 'EUR', wpdc_page_language() );
$GLOBALS['wpdc_test_pll']        = '';
$headerWins                      = wpdc_page_language();
unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'], $GLOBALS['wpdc_test_pll'] );
$neither = wpdc_page_language();

check(
	// A price is printed into the page BODY, so it survives in a full-page cache
	// and it belongs to the page rather than to whoever asked for the page. Read
	// off Accept-Language it rendered "€24.99" into a German sales page whenever
	// a request carried no header -- measured on the live site.
	//
	// Polylang serves each language on its own URL, so its answer is a property
	// of the page and a cache keyed on the URL is keyed on the language too.
	'BELL: the page decides its language, not the browser that asked for it',
	'de' === $pageWins && 'en' === $headerWins && '' === $neither
);
check(
	// German writes the symbol after the number. This is the visible half of the
	// rule above: with the header winning, a German page showed "€24.99".
	'BELL: a German page prints the symbol after the number',
	str_ends_with( trim( $germanPrice ), '€' )
);
check(
	// The no-Intl path, called DIRECTLY. It only runs where a library is
	// missing, so it never runs on the machine writing the tests -- and it duly
	// survived a mutation that made every locale print the symbol in front,
	// because the assertion above goes through NumberFormatter and never reaches
	// this code. That is why it is its own function now.
	'BELL: without Intl the symbol still lands on the right side of the number',
	'24,99 €' === wpdc_format_price_basic( 24.99, 'EUR', 2, 'de' )
		&& '€24,99' === wpdc_format_price_basic( 24.99, 'EUR', 2, 'en' )
		// The dollars that are not THE dollar keep their letters: an Australian
		// shown a bare $ has been told the American price.
		&& str_contains( wpdc_format_price_basic( 19.99, 'AUD', 2, 'en' ), 'A$' )
		// An unmapped currency falls back to its code rather than to nothing.
		&& str_contains( wpdc_format_price_basic( 100.0, 'HUF', 2, 'en' ), 'HUF' )
);

check(
	// One radius, set once and referenced everywhere, so a site that overrides
	// --wpdc-radius moves the whole window at once instead of four of six
	// corners. The spinner is the exception and is not a corner: it is a ring,
	// and a 5px ring is a square.
	// Two round things, and both are round for a reason rather than by
	// oversight: the spinner is a ring, not a corner, and the close button is a
	// CONTROL, not a panel -- at 5px it read as a white tile pasted on the
	// header instead of a button sitting on it.
	'BELL: every corner uses the one radius, and only the ring and the button are round',
	1 === substr_count( $css, '--wpdc-radius: 5px' )
		// Twelve: the download button, the key field, the copy button beside it,
		// the account button and the labelled way out in the completion panel,
		// and the sign-in button in the order panel.
		&& 12 === substr_count( $css, 'border-radius: var(--wpdc-radius' )
		// Four circles: the frame's spinner ring, the close button, the done
		// mark, and the spinner that waits inside the done panel.
		&& 4 === substr_count( $css, 'border-radius: 50%' )
);

// ─── A discount code, in the summary Dodo asked us to build ─────────────────
//
// Their own discount field lives in their order summary, and inline mode does
// not render one -- integrators are asked to build it, which is what the panel
// is. The control went with the summary and was not moved into the form, so an
// inline checkout had nowhere to type a code. MEASURED both ways: the same
// session opened on their hosted page shows "Haben Sie einen Rabattcode?";
// embedded it shows nothing.

configure();
catalogue( array( 'pdt_pro' ) );
respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_disc' ) );
wpdc_create_session( 'pdt_pro', 1, null, null, 'BENNY' );
$withCode = json_decode( last_request()['args']['body'], true );
check(
	'BELL: a code the customer typed rides on the session',
	array( 'BENNY' ) === ( $withCode['discount_codes'] ?? null )
);

configure();
catalogue( array( 'pdt_pro' ) );
respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_nodisc' ) );
wpdc_create_session( 'pdt_pro' );
$noCode = json_decode( last_request()['args']['body'], true );
check(
	'BELL: and no code means no discount_codes at all',
	! isset( $noCode['discount_codes'] )
);

configure();
catalogue( array( 'pdt_pro' ) );
respond( 422, array( 'error' => 'invalid discount' ) );
$refused = wpdc_create_session( 'pdt_pro', 1, null, null, 'NOPE' );
check(
	// A refused code is a typo, not an outage. "Please try again in a moment"
	// would have somebody trying the same wrong code all day.
	'BELL: a refused code is reported as the code, not as our outage',
	'discount_rejected' === $refused['reason'] && false === $refused['retriable']
);

configure();
catalogue( array( 'pdt_pro' ) );
respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_x' ) );
wpdc_create_session( 'pdt_pro', 1, null, null, 'not a code!!' );
$bad = json_decode( last_request()['args']['body'], true );
check(
	// The only value in this plugin that comes from a CUSTOMER, and it reaches
	// an outbound API call. Dodo decides whether a code is valid; the shape
	// check decides whether it is worth asking.
	'BELL: a malformed code never reaches Dodo',
	! isset( $bad['discount_codes'] )
);

check(
	// Dodo's checkout cannot be told about a code after the fact -- the code is
	// part of the session -- so applying one means a new session and a new
	// frame, and the old one has to be closed first. Two live sessions for one
	// customer is two carts.
	'BELL: applying a code re-mints the session and replaces the frame',
	str_contains( $js, "event.target.closest('.wpdc__discount')" )
		&& str_contains( $js, 'dodo.Checkout.close()' )
		&& str_contains( $js, 'function remintWith' )
);
check(
	// Applying a code and removing one are the SAME operation with a different
	// value: the code is part of the session, and Dodo's checkout can neither be
	// told about one after the fact nor told to forget one. Two copies of that
	// would be two places to fix the next thing found in it.
	'BELL: applying and removing share one path',
	// Definition, apply/remove, and the re-mint after signing in from the panel.
	4 === substr_count( $js, 'remintWith' )
		&& str_contains( $js, "event.target.closest('.wpdc__discount-clear')" )
		&& str_contains( $js, "delete root.dataset.discount;" )
);
check(
	// `justify-self` gives the element a layout, and the platform's way of
	// hiding it stops working -- the fourth time this stylesheet has had to
	// write that case back.
	'BELL: the remove control is hidden until there is something to remove',
	str_contains( $shortcode, 'class="wpdc__discount-clear" hidden' )
		&& 1 === preg_match( '/\.wpdc__discount-clear\[hidden\] \{[^}]*display: none/', $css )
);
check(
	// Somebody who mistypes a code must not lose the checkout they already had
	// open, and the next attempt must not carry the bad code.
	// Both directions: a failed apply must not leave the bad code behind, and a
	// failed removal must not leave the code half-gone.
	'BELL: a failed code leaves the open checkout alone and is not remembered',
	str_contains( $js, 'root.dataset.discount = previous;' )
		&& 2 === substr_count( $js, 'delete root.dataset.discount;' )
);

check(
	// wpdc_enqueue translates the strings it hands to JavaScript, and it ran
	// BEFORE the catalogue was loaded -- so "Code applied." reached a German
	// customer in English while everything rendered below it was translated.
	//
	// EVERY call site, not the first one in the file. This used to compare two
	// positions in the whole source, which held while exactly one function
	// enqueued. The moment a second shortcode did it too, the check reported the
	// order of two unrelated lines -- and would have gone red for correct code
	// while staying green for the bug it was written about.
	'BELL: every enqueue is preceded by the catalogue, in its own function',
	( static function ( string $code ): bool {
		$blocks = preg_split( '/\nfunction /', $code );
		$seen   = 0;
		foreach ( $blocks as $block ) {
			$call = strpos( $block, "\twpdc_enqueue();" );
			if ( false === $call ) {
				continue;
			}
			$seen += 1;
			$load = strpos( $block, 'wpdc_load_catalogue(' );
			if ( false === $load || $load > $call ) {
				return false;
			}
		}
		// Vacuously true is the failure mode this guards against: a rename of
		// the function would empty the loop and report success.
		return $seen >= 2;
	} )( $shortcode )
);
check(
	// A discount is money coming OFF. Printed like the subtotal, a 24,99
	// discount on a 24,99 item reads as a second charge -- which is what the
	// operator saw.
	'BELL: a discount is shown as a deduction, with how much came off',
	str_contains( $js, "(key === 'discount' ? '\u2212' : '')" )
		&& str_contains( $js, 'function paintOff' )
		&& 2 === substr_count( $js, 'paintOff' )
		&& str_contains( $shortcode, 'wpdc__off' )
);
check(
	// Colour alone is not enough for anyone who cannot separate red from green,
	// and this is the one line telling a customer whether the code they typed
	// did anything. The two states differ in SHAPE as well: a tick, a cross.
	'BELL: applied and refused differ by more than colour',
	1 === preg_match( '/\.wpdc__discount-note::before \{[^}]*border-width: 0 1\.5px 1\.5px 0/', $css )
		&& 1 === preg_match( '/\.wpdc__discount-note\.is-error::before \{[^}]*linear-gradient/', $css )
);

check(
	// The strip was three columns filled in DOCUMENT ORDER, so the discount form
	// arrived as a fourth box, took the third column, squeezed the product name
	// down to a few characters and an ellipsis, and pushed the price to a
	// second row. Every child says where it goes now, and the next one added
	// cannot rearrange the two that matter.
	'BELL: on a phone every part of the strip is placed, not left to source order',
	1 === preg_match( '/@media \(max-width: 900px\)[\s\S]{0,3600}\.wpdc__item-img \{[^}]*grid-column: 1/', $css )
		&& 1 === preg_match( '/@media \(max-width: 900px\)[\s\S]{0,3600}\.wpdc__totals \{[^}]*grid-column: 3/', $css )
		&& 1 === preg_match( '/@media \(max-width: 900px\)[\s\S]{0,3600}\.wpdc__discount \{[^}]*grid-column: 1 \/ -1/', $css )
);

check(
	// Silence was the old behaviour, and on a button that reports nothing it is
	// indistinguishable from a broken button: the customer clicks, waits, and
	// concludes the code does not work. Both refusals say which one they are,
	// and the shape check answers instantly instead of after a round trip.
	'BELL: an empty or malformed code is refused out loud, not silently',
	str_contains( $js, 'fail(note, cfg.discountEmpty)' )
		&& str_contains( $js, 'fail(note, cfg.discountShape)' )
		&& str_contains( $js, "/^[A-Za-z0-9_-]{1,64}$/.test(code)" )
		&& str_contains( $shortcode, "'discountEmpty'" )
);
check(
	// The browser's own ring is blue, and blue on navy is a ring nobody sees.
	// Both controls in the panel use the accent the rest of it uses.
	'BELL: the panel controls have a focus ring anyone can see',
	1 === preg_match( '/\.wpdc__discount-input:focus-visible \{[^}]*var\(--wpdc-panel-accent\)/', $css )
		&& 1 === preg_match( '/\.wpdc__discount-apply:focus-visible \{[^}]*var\(--wpdc-panel-accent\)/', $css )
);

check(
	// Dodo's own guidance: show a loading indicator until the frame reports
	// itself open. Without it there are seconds of empty white beside a panel
	// that is already full, which is what a broken embed looks like.
	'BELL: the frame shows a loading state until Dodo says it is open',
	str_contains( $js, "event.event_type === 'checkout.opened'" )
		&& str_contains( $js, "checkout.form_ready" )
		&& str_contains( $js, "frame.classList.add('is-loading')" )
		&& str_contains( $css, '.wpdc__frame.is-loading::after' )
);
check(
	// The entrance plays on the transition OUT of waiting only. Dodo's frame
	// reflows on every address change, and replaying it under a customer who is
	// mid-typing is worse than not having it.
	'BELL: the arrival plays once, not on every reflow',
	str_contains( $js, "frame.classList.contains('is-loading')" )
		&& str_contains( $js, "if (wasWaiting) frame.classList.add('is-ready')" )
		&& str_contains( $js, "frame.classList.remove('is-ready')" )
);
check(
	// Someone who asked for less motion is not asking for less information: the
	// spinner keeps turning, because a still ring says "broken" where a moving
	// one says "wait". Only the decoration is dropped.
	'BELL: reduced motion drops the decoration and keeps the spinner',
	1 === preg_match( '/prefers-reduced-motion: reduce\)[\s\S]{0,400}animation: none/', $css )
		&& 1 === preg_match( '/prefers-reduced-motion: reduce\)[\s\S]{0,500}is-loading::after[\s\S]{0,120}animation-duration/', $css )
);
check(
	// A spinner with no deadline is how a customer who has decided to buy sits
	// in front of nothing until they give up, and nobody ever hears about it.
	'BELL: the wait has a deadline that falls back to Dodo own page',
	str_contains( $js, 'LOAD_DEADLINE_MS' )
		// Six: the definition, the two events that end the wait, the close, the
		// zero-total branch, and the poll giving up -- which ends it because
		// nothing else will.
		&& 6 === substr_count( $js, 'settleLoading' )
		&& 1 === preg_match( '/loadTimer = setTimeout[\s\S]{0,400}window\.location\.assign\(url\)/', $js )
);
check(
	// A cart discounted to zero completes on Dodo's side while their frame sits
	// on a payment step it cannot draw -- no event, no error, nothing to react
	// to. This is the one screen the shop finishes itself, and it must stay the
	// one: a checkout with something to pay completes on the payment step and
	// their SDK does the redirect.
	// The session id is the capability that unlocks this order's downloads and
	// licence key, so it travels in a body, never in a URL that every server on
	// the way writes down. It also settles the permalink shapes for good:
	// rest_url() returns `.../wp-json/...` on pretty permalinks and
	// `.../index.php?rest_route=/...` on plain ones, and appending a query to
	// the second put the session inside the ROUTE -- five 404s on a live
	// checkout, measured.
	'BELL: the session id is posted, not put in a URL',
	str_contains( $js, "body: JSON.stringify({ session: session })" )
		&& ! str_contains( $js, "cfg.status + join" )
		&& ! str_contains( $js, "cfg.status + '?'" )
);

/**
 * The rule that ends in this class and carries the most classes in front of it.
 *
 * Returns [ class count, declarations ]. Zero when no rule targets it at all.
 */
function wpdc_armour_rule( string $css, string $class ): array {
	$best = array( 0, '' );

	/*
	 * Comments out FIRST, and this line is the whole reason the check works.
	 *
	 * `source()` returns the file verbatim. A rule's selector is captured as
	 * "everything since the last brace", so the comment ABOVE a rule lands
	 * inside it -- and the comment above the armour names `.w-btn`, `.button`
	 * and the control itself while explaining the theme that broke us. Counting
	 * classes over that text found four in a three-class rule, so the first
	 * version of this check passed while the selector was too weak. It was
	 * counting my own explanation.
	 *
	 * That is the fourth check in this codebase to pass by reading prose. The
	 * mutation probe is what caught it: the assertion did not fall when the
	 * chain was shortened, which is the only reason anyone looked.
	 */
	$css = (string) preg_replace( '!/\*.*?\*/!s', '', $css );

	if ( 0 === preg_match_all( '/([^{}]+)\{([^}]*)\}/', $css, $all, PREG_SET_ORDER ) ) {
		return $best;
	}
	foreach ( $all as $rule ) {
		$selector = trim( $rule[1] );
		// The rule must END in this control, not merely mention it: a rule for
		// a descendant or a sibling proves nothing about the control itself.
		if ( 1 !== preg_match( '/' . preg_quote( $class, '/' ) . '$/', $selector ) ) {
			continue;
		}
		$classes = preg_match_all( '/\.[A-Za-z_][-\w]*/', $selector );
		if ( $classes > $best[0] ) {
			$best = array( $classes, $rule[2] );
		}
	}
	return $best;
}

/*
 * `.wpdc__done-file` joined this list after a live order: it is an <a>, and a
 * theme styles links harder than anything else on a page. Impreza painted the
 * label a pale link colour on our yellow -- legible in a screenshot only if you
 * already knew what it said, hopeless for anyone whose sight is not perfect.
 * On the one control a customer has to find after paying.
 */
$panel_controls = array( '.wpdc__discount-input', '.wpdc__discount-apply', '.wpdc__done-file' );
$armoured       = true;
foreach ( $panel_controls as $selector ) {
	list( $classes, $block ) = wpdc_armour_rule( $css, $selector );

	// Four, because the theme that broke this reaches 0,3,0 -- and three would
	// only tie, leaving the outcome to whichever stylesheet WordPress happens
	// to enqueue second. A tie is not a fix.
	if ( $classes < 4 ) {
		$armoured = false;
	}
	foreach ( array( 'background', 'color', 'border', 'padding', 'font-size' ) as $property ) {
		if ( 1 !== preg_match( '/(?:^|;)\s*' . $property . '\s*:[^;]*!important/m', $block ) ) {
			$armoured = false;
		}
	}
}
check(
	/*
	 * THE defect this exists for, measured on the live shop the hour it opened.
	 *
	 * Impreza paints `[type="submit"]:not(.w-btn):not(.button)` -- specificity
	 * 0,3,0 -- with the site's navy. Our `.wpdc__discount-apply` is 0,1,0. So
	 * the apply button rendered navy on the panel's own navy: present, full
	 * size, clickable, invisible. Beside it the input arrived white and 54px
	 * tall, because `input[type="text"]` is 0,1,1 and also wins. The operator
	 * clicked a button he could not see and got "enter a code first" from a
	 * field he could not read.
	 *
	 * Important alone did not fix it, and that was measured on the live page
	 * rather than assumed: the theme's rule is important too, so specificity
	 * decided again and 0,3,0 beat 0,1,0 exactly as before. Both halves are
	 * needed, so both halves are checked -- the class count AND the priority.
	 *
	 * No number is safe forever; `#main .a .b .c .d` would still win, and only
	 * a shadow root or an iframe forecloses that. What this covers is every
	 * theme that styles form controls the ordinary way, which is the one that
	 * broke us.
	 *
	 * Read from the stylesheet with its comments stripped, so the paragraph
	 * above cannot satisfy the check it describes. This repo has shipped three
	 * checks that passed by reading prose.
	 */
	'BELL: the controls on the dark panel outrank whatever theme they land in',
	$armoured
);

// ─── The status route, exercised rather than read ────────────────────────────
//
// Everything below used to be str_contains() over rest.php. The check above
// carried a fourth operand, `str_contains( $rest, "'methods' => 'POST'," )`,
// which could not fail for its own subject: rest.php contains that string
// twice, once per route, so changing the STATUS route to GET -- the entire
// thing it guards -- left all 192 checks green. Measured, not suspected.

wpdc_register_rest();
$routes = $GLOBALS['wpdc_test_routes'];

check(
	// The session id is a capability: whoever presents it is handed this
	// order's download links and licence key. In a query string that capability
	// is in every access log, proxy log and Referer header on the way.
	'BELL: the status route is POST, and it is THIS route that is asserted',
	'POST' === ( $routes['wp-dodo-checkout/v1/status']['methods'] ?? '' )
);
check(
	'SILENCE: the session route is POST too, so the check above is about the right one',
	'POST' === ( $routes['wp-dodo-checkout/v1/session']['methods'] ?? '' )
);
check(
	// Without it the route accepts any string and spends the shop's API key
	// finding out that it was not a session id.
	'BELL: the status route validates the session id before doing anything',
	'wpdc_is_session_id' === ( $routes['wp-dodo-checkout/v1/status']['args']['session']['validate_callback'] ?? '' )
		&& true === ( $routes['wp-dodo-checkout/v1/status']['args']['session']['required'] ?? false )
);

/** One poll, scripted end to end. */
function poll( string $session = 'cks_abcdefghijklmnopqrstuvwx' ): WP_REST_Response {
	// In production every polled session is one this site minted, and the status
	// route now refuses any it did not. Registering it keeps these cases
	// exercising a real, known session rather than the new unknown-id refusal.
	wpdc_remember_session( $session );
	return wpdc_rest_status( new WP_REST_Request( array( 'session' => $session ) ) );
}

configure();
$GLOBALS['wpdc_test_transients'] = array();
respond( 200, array( 'session_id' => 'cks_x', 'status' => 'open' ) );
$open = poll();
check(
	'SILENCE: a checkout still open answers "not yet", with a 200',
	200 === $open->get_status() && false === $open->get_data()['finished']
);

$GLOBALS['wpdc_test_transients'] = array();
$GLOBALS['wpdc_test_queue']      = array();
respond( 500, array() );
$broken = poll();
check(
	// A poll that fails is not a finished order. The customer is watching a
	// spinner; an error status here would be read by the browser as "give up".
	'BELL: an upstream failure is reported as "not yet", never as finished',
	200 === $broken->get_status() && false === $broken->get_data()['finished']
);
check(
	// Audit 2026-10-23: a finished answer carries download links and keys.
	'BELL: every status answer is private, no-store',
	( static function () use ( $open, $broken ): bool {
		$unknown = wpdc_rest_status( new WP_REST_Request( array( 'session' => 'cks_nevermintedcache000000' ) ) );
		$GLOBALS['wpdc_test_options'] = array();
		$off = wpdc_rest_status( new WP_REST_Request( array( 'session' => 'cks_nevermintedcache000000' ) ) );
		configure();
		foreach ( array( $open, $broken, $unknown, $off ) as $r ) {
			if ( 'private, no-store' !== ( $r->headers['cache-control'] ?? '' ) ) {
				return false;
			}
		}
		return true;
	} )()
);

$GLOBALS['wpdc_test_transients'] = array();
$GLOBALS['wpdc_test_requests']   = array();
for ( $i = 0; $i < WPDC_POLL_CEILING + 5; $i++ ) {
	$GLOBALS['wpdc_test_queue'] = array();
	respond( 200, array( 'session_id' => 'cks_x', 'status' => 'open' ) );
	poll();
}
check(
	'BELL: one session cannot poll for ever',
	count( $GLOBALS['wpdc_test_requests'] ) <= WPDC_POLL_CEILING
);

/** The three numbers the browser schedules its wait from. */
function wpdc_js_number( string $js, string $name ): int {
	return 1 === preg_match( '/var ' . preg_quote( $name, '/' ) . ' = (\d+)/', $js, $m )
		? (int) $m[1]
		: -1;
}

$poll_tries      = wpdc_js_number( $js, 'POLL_TRIES' );
$poll_fast_tries = wpdc_js_number( $js, 'POLL_FAST_TRIES' );
$poll_fast_ms    = wpdc_js_number( $js, 'POLL_FAST_MS' );
$poll_slow_ms    = wpdc_js_number( $js, 'POLL_SLOW_MS' );

check(
	/*
	 * The pair, checked against each other rather than restated in two files.
	 *
	 * The browser must run out of tries BEFORE the server starts refusing.
	 * Cross them and the throttle -- which exists to protect the shop's API key
	 * from invented session ids -- starts firing at real customers instead, and
	 * what a paying customer then reads is "we could not confirm your order".
	 * The ceiling would be causing the exact failure it was built to prevent.
	 *
	 * A margin rather than "not equal": a dropped connection costs a retry, and
	 * a budget that only just fits leaves nothing for one.
	 */
	'BELL: the browser gives up well before the server throttles it',
	$poll_tries > 0 && $poll_tries + 10 <= WPDC_POLL_CEILING
);

check(
	/*
	 * THE defect this replaced, and the reason it survived so long.
	 *
	 * The wait was one minute flat. Every successful purchase on this shop went
	 * through a hundred-percent discount code, and a zero-total order has no
	 * payment step -- so the branch that meets a real card was the one branch no
	 * test purchase ever entered. On a real card, 3-D Secure sends the buyer to
	 * their bank's app, and coming back inside sixty seconds is not something to
	 * count on. Somebody who had just paid was told we could not confirm it.
	 *
	 * Four minutes is the floor, not the target: the schedule allows five.
	 * Measured as the schedule the browser will actually follow, so shortening
	 * either phase fails here rather than in front of a customer.
	 */
	'BELL: the confirmation window outlasts a 3-D Secure approval',
	$poll_fast_tries > 0 && $poll_slow_ms >= $poll_fast_ms
		&& ( $poll_fast_tries * $poll_fast_ms )
			+ ( ( $poll_tries - $poll_fast_tries ) * $poll_slow_ms ) >= 240000
);

check(
	/*
	 * And it stays fast for the ordinary order.
	 *
	 * A five-minute window bought by polling every fifteen seconds would fix the
	 * card payment and make every other purchase feel broken -- the tick would
	 * arrive long after the money left. The first stretch keeps the old cadence.
	 */
	'SILENCE: an order that confirms at once is not made to wait for it',
	$poll_fast_ms <= 2000 && $poll_fast_tries * $poll_fast_ms >= 30000
);

$GLOBALS['wpdc_test_requests'] = array();
$GLOBALS['wpdc_test_queue']    = array();
respond( 200, array( 'session_id' => 'cks_y', 'status' => 'open' ) );
poll( 'cks_zyxwvutsrqponmlkjihgfedc' );
check(
	// THE defect this replaced. Keyed on REMOTE_ADDR, which is the proxy on most
	// production WordPress, all visitors shared one bucket of sixty -- so the
	// third concurrent checkout was throttled and its customer told "we could
	// not confirm your order", for an order that had succeeded. Ordinary
	// traffic, not abuse. A session id belongs to exactly one checkout, so no
	// customer can spend another's allowance.
	'BELL: a second customer is not throttled by the first one having polled',
	1 === count( $GLOBALS['wpdc_test_requests'] )
);

$GLOBALS['wpdc_test_transients']['wpdc_poll_all'] = 600;
$GLOBALS['wpdc_test_requests']                    = array();
$GLOBALS['wpdc_test_queue']                       = array();
poll( 'cks_freshfreshfreshfreshfresh' );
check(
	// Per-session counting is useless against a loop of invented ids, because
	// every new id brings its own forty. What is protected here is the shop's
	// one API key, which is site-wide, so this ceiling is too.
	'BELL: and a site-wide ceiling still guards the shop API key',
	0 === count( $GLOBALS['wpdc_test_requests'] )
);
$GLOBALS['wpdc_test_transients'] = array();
check(
	'BELL: a zero-total checkout is finished by asking, not by waiting',
	1 === preg_match( "/customer_details_submitted' && '0' === root\.dataset\.due/", $js )
		&& str_contains( $js, 'function awaitCompletion' )
		&& str_contains( $js, 'cfg.status' )
);
check(
	// The route answers a boolean about somebody's own checkout. Dodo returns
	// the customer's name and email in the same response, and this route is
	// public -- so both stay on the server.
	// The route reads three responses that carry the customer's name and email
	// -- the session, the payment, the grants -- and hands back none of it. What
	// does travel is what the purchase delivered: Dodo's own signed links and
	// the key, the same things it puts in its mail.
	'BELL: the status route hands back the goods and no customer detail',
	str_contains( $rest, "'finished' =>" )
		&& ! preg_match( '/customer_email|customer_name/', $rest )
		&& str_contains( $rest, "'files'    => \$result['files'] ?? array()" )
		&& str_contains( $client, "'finished' => false" )
);
check(
	// The operator watched Dodo's payment step -- a skeleton it can never fill,
	// because there is nothing to pay -- for the seconds between submitting
	// contact details and the confirmation landing. The wait belongs where the
	// answer will appear, so the frame goes at the moment polling starts and
	// the panel carries both states in one cell.
	'BELL: the dead payment step goes the moment the wait begins',
	str_contains( $js, 'function showDone' )
		// Four: the definition, the zero-total wait, the finish, and the
		// give-up -- the last one added because the paying customer's failure
		// message had no panel to be written into.
		&& 4 === substr_count( $js, 'showDone(root,' )
		&& str_contains( $js, 'showDone(root, false)' )
		&& str_contains( $js, 'showDone(root, true)' )
		&& str_contains( $shortcode, 'class="wpdc__done-wait"' )
		&& str_contains( $shortcode, 'class="wpdc__done-ok" hidden' )
		&& 1 === preg_match( '/\.wpdc__done-wait\[hidden\],\s*\.wpdc__done-ok\[hidden\] \{[^}]*display: none/', $css )
);
check(
	// The poll used to show the completion panel when it ran out of tries, or
	// navigate to the thank-you page -- a claim the shop cannot support. Whoever
	// paid and failed read "order complete"; with a thank-you page configured
	// they landed on "thanks for your order" for an order that may not exist.
	// The rate ceiling on this very route made reaching that more likely.
	//
	// Success is claimed on a confirmed success and nowhere else, and both ways
	// out of the poll -- refusals and thrown requests -- end in the same honest
	// sentence rather than in silence.
	'BELL: an unconfirmed order is never reported as a finished one',
	str_contains( $js, 'function giveUp' )
		&& 2 === substr_count( $js, 'giveUp();' )
		// One definition, one call site, and counted as CODE. This used to count
		// bare `leave(`, which a comment mentioning the function satisfied just
		// as well -- it went red on a comment and would have gone green on a
		// second real call site hidden behind one.
		&& 1 === substr_count( $js, 'function leave(' )
		&& 1 === substr_count( $js, 'return leave(' )
		&& str_contains( $js, 'if (data && data.finished) return leave(data.redirect, data);' )
		&& ! str_contains( $js, 'tries >= POLL_TRIES) return leave(' )
		&& str_contains( $js, 'cfg.unconfirmed' )
);
check(
	// And it has to be SAID somewhere the customer can read it.
	//
	// giveUp reached straight for `.wpdc__done-wait`, which only exists once
	// showDone has built the panel -- the zero-total path does that on its way
	// in, the paying path never did. The fallback, say(), writes to
	// `.wpdc__message`, which sits outside the <dialog>; showModal() makes the
	// rest of the document inert, so that sentence rendered behind the
	// backdrop. A paying customer whose poll ran out saw NOTHING change.
	'BELL: the give-up sentence is put where a paying customer can see it',
	1 === preg_match( '/function giveUp\(\)[\s\S]{0,1200}showDone\(\s*root,\s*false\s*\)[\s\S]{0,900}wpdc__done-wait/', $js )
);
check(
	// Set once and deleted in exactly one branch, the flag survived every
	// completion and every give-up. The customer closed the dialog, bought
	// again, and the second wait returned on the guard: no poll, no panel, no
	// message, for an order that went through.
	'BELL: the wait flag is released on every exit, not just the missing-session one',
	// The BODY, not just the name. Counting call sites alone passed against a
	// release() that had been gutted to do nothing -- probed, and it survived.
	1 === preg_match( '/function release\(\)\s*\{[^}]*delete root\.dataset\.awaiting;[^}]*\}/', $js )
		// The definition plus all three exits: success, give-up, no session.
		&& 4 === substr_count( $js, 'release()' )
		&& ! str_contains( $js, "if (!session) {\n      delete root.dataset.awaiting;" )
);
check(
	// The loading deadline was cancelled on close from the start; the poll was
	// not. A customer who paid and dismissed the dialog kept a timer chain
	// alive for a minute -- and on success leave() calls location.assign(),
	// navigating somebody who is now reading something else on the page.
	'BELL: closing the popup ends the wait it belonged to',
	str_contains( $js, 'var stale = function' )
		&& 1 === preg_match( '/function closeFrame\([\s\S]{0,1500}pollGen/', $js )
		// Three: the next timer, the reply, and the failed reply. A fired
		// timeout must not spend a request against a ceiling that is finite,
		// and neither branch of the promise may act on a closed dialog.
		&& 3 === substr_count( $js, 'if (stale()) return;' )
);
check(
	// Hiding the frame left the panel's Apply button live, so a code typed
	// after paying re-minted a SECOND cart into a hidden frame: a real session
	// at Dodo the customer would never see. And nothing ever put the panel,
	// the frame or the completion back, so a second purchase on the same page
	// opened onto the previous order's completion screen.
	'BELL: the discount form closes with the checkout, and a new one opens clean',
	1 === preg_match( '/function showDone\([\s\S]{0,1500}wpdc__panel[\s\S]{0,400}disabled = true/', $js )
		&& 1 === preg_match( '/function openFrame\([\s\S]{0,2500}frame\.hidden = false[\s\S]{0,500}disabled = false/', $js )
);
check(
	// This plugin sells for a shop. It has no server of ours in the middle, no
	// licence server, no account system -- a shortcode names a Dodo product id
	// and Dodo takes the money. Comments naming a neighbouring product line kept
	// implying otherwise, and a reader who believes that reaches for the wrong
	// mechanism; it happened, twice, to the author of this line.
	'BELL: no neighbouring product line is named anywhere in this repo',
	0 === preg_match( '/lumo/i', $client . $rest . $shortcode . $config . $js . $css )
		&& 0 === preg_match( '/lumo/i', source( $root . '/README.md' ) )
		&& 0 === preg_match( '/lumo/i', source( $root . '/.github/workflows/ci.yml' ) )
);
configure();
check(
	// The failure is a TYPE in everything but name, and ten call sites across
	// five files spelled its test out by hand -- ten places to get the strict
	// comparison wrong once and read a failure as a success.
	'BELL: an error is recognised by one predicate, not by ten hand-written tests',
	true === wpdc_is_error( wpdc_error( 'x', false, 'y' ) )
		&& false === wpdc_is_error( array( 'ok' => true, 'checkout_url' => 'u' ) )
		&& false === wpdc_is_error( array( 'finished' => false ) )
		&& false === wpdc_is_error( null )
		&& 0 === preg_match( "/isset\( \\\$\w+\['ok'\] \) && false ===/", $client . $rest . $shortcode )
);

// ─── What a finished purchase delivers ───────────────────────────────────────
//
// The largest block added to the client -- session status, payment to customer,
// customer to grants, grants filtered back to this payment -- was covered by
// grepping its own source for a `return` statement. That is the failure this
// file exists to prevent, so it is exercised here against scripted responses.

/**
 * Script one grant row.
 *
 * @param array<string, mixed> $over Fields to override on the default row.
 */
function grant( array $over = array() ): array {
	return array_merge(
		array(
			'payment_id' => 'pay_1',
			'status'     => 'Delivered',
		),
		$over
	);
}

/** Script the three calls wpdc_session_finished makes for a succeeded order. */
function finished_with( array $grants, string $status = 'succeeded' ): void {
	respond( 200, array( 'payment_status' => $status, 'payment_id' => 'pay_1' ) );
	respond( 200, array( 'customer' => array( 'customer_id' => 'cus_1' ) ) );
	respond( 200, array( 'items' => $grants ) );
}

configure();
respond( 200, array( 'payment_status' => 'requires_payment_method', 'payment_id' => 'pay_1' ) );
$pending = wpdc_session_finished( 'cks_1' );
check(
	// The one wrong answer available here: a thank-you page for a payment that
	// has not succeeded. And it must not spend two more API calls asking what a
	// payment that did not happen delivered.
	'BELL: a payment that has not succeeded is not finished, and costs one call',
	false === $pending['finished'] && 1 === count( $GLOBALS['wpdc_test_requests'] )
);

configure();
finished_with( array(
	grant( array( 'digital_product_delivery' => array(
		'external_url' => 'https://downloads.example/latest.zip',
		'instructions' => 'Plugin ZIP',
		'files'        => array( array( 'download_url' => 'https://dodo.example/frozen.zip', 'filename' => 'frozen.zip' ) ),
	) ) ),
) );
$hosted = wpdc_session_finished( 'cks_1' );
check(
	// The hosted link REPLACES the upload. Both would hand the buyer a second
	// link pointing at the build that was current the day it was uploaded.
	'BELL: a hosted link is delivered and the frozen copy beside it is not',
	1 === count( $hosted['files'] )
		&& 'https://downloads.example/latest.zip' === $hosted['files'][0]['url']
		&& 'Plugin ZIP' === $hosted['files'][0]['name']
);

configure();
finished_with( array(
	grant( array( 'digital_product_delivery' => array(
		'external_url' => 'http://downloads.example/latest.zip',
		'files'        => array( array( 'download_url' => 'https://dodo.example/book.pdf', 'filename' => 'book.pdf' ) ),
	) ) ),
) );
$insecureLink = wpdc_session_finished( 'cks_1' );
check(
	// A link that did not pass is not a better link, it is no link. The rule was
	// written as "was an external_url present" rather than "did we accept one",
	// so an http:// entitlement suppressed the uploads while being rejected
	// itself -- and the grant delivered nothing at all to a paying customer.
	'BELL: a rejected hosted link does not take the uploads down with it',
	1 === count( $insecureLink['files'] )
		&& 'https://dodo.example/book.pdf' === $insecureLink['files'][0]['url']
);

configure();
finished_with( array(
	grant( array( 'digital_product_delivery' => array( 'files' => array(
		array( 'download_url' => 'https://dodo.example/book.pdf', 'filename' => 'book.pdf' ),
		array( 'download_url' => 'http://dodo.example/insecure.pdf', 'filename' => 'insecure.pdf' ),
		array( 'download_url' => 'https://dodo.example/unnamed.pdf', 'filename' => '' ),
	) ) ) ),
) );
$uploaded = wpdc_session_finished( 'cks_1' );
check(
	// These become an href in somebody's browser. Plain http and a nameless file
	// are dropped rather than rendered.
	'BELL: only a named https file is handed to the browser',
	1 === count( $uploaded['files'] ) && 'book.pdf' === $uploaded['files'][0]['name']
);

configure();
finished_with( array(
	grant( array( 'license_key' => array( 'key' => 'AAAA-BBBB' ) ) ),
	grant( array( 'payment_id' => 'pay_OTHER', 'license_key' => array( 'key' => 'SOMEBODY-ELSE' ) ) ),
	grant( array( 'status' => 'Pending', 'license_key' => array( 'key' => 'NOT-YET' ) ) ),
) );
$keys = wpdc_session_finished( 'cks_1' );
check(
	// The customer's other purchases arrive in the same list. The session in
	// hand proves THIS payment, not their history -- and a pending grant is not
	// a delivered one.
	'BELL: another payment and an undelivered grant are both dropped',
	array( 'AAAA-BBBB' ) === $keys['keys']
);

configure();
finished_with( array( grant( array( 'status' => 'delivered', 'license_key' => array( 'key' => 'LOWER-CASE' ) ) ) ) );
$case = wpdc_session_finished( 'cks_1' );
check(
	// Their documentation spells this status two ways on neighbouring endpoints.
	// Guessing wrong is invisible: an empty panel for ever.
	'BELL: a lower-case delivered status still counts as delivered',
	array( 'LOWER-CASE' ) === $case['keys']
);

configure();
respond( 200, array( 'payment_status' => 'succeeded', 'payment_id' => 'pay_1' ) );
respond( 500, array( 'error' => 'boom' ) );
$broke = wpdc_session_finished( 'cks_1' );
check(
	// The order finished. "Finished, but the goods list could not be read" must
	// not reach a poll as "not finished" -- it would never stop asking, and the
	// customer would be told their purchase could not be confirmed.
	'BELL: an unreadable goods list still reports the order as finished',
	true === $broke['finished'] && array() === $broke['files'] && array() === $broke['keys']
);

check(
	// The panel with the download, the key and the word about the mail was
	// built for the ONE case that pays nothing: the poll only ever started on a
	// zero total, so a paying customer finished and saw none of it. Both cases
	// end the same way now -- and the paid one starts on the CLICK, because
	// starting when the payment screen opens would spend the minute of asking
	// while somebody is still typing a card number and then tell them
	// mid-payment that their order could not be confirmed.
	'BELL: a paying customer reaches the completion too',
	str_contains( $js, "event.event_type === 'checkout.pay_button_clicked'" )
		// Three: the definition, the zero-total start, and the paid start.
		&& 3 === substr_count( $js, 'awaitCompletion(root)' )
		&& ! str_contains( $js, "'checkout.payment_page_opened'" )
		// One wait per checkout: the click can arrive twice if somebody goes
		// back and forward, and two polls would swap the panel under each other.
		&& str_contains( $js, "root.dataset.awaiting === '1'" )
);
check(
	// Offering the upload beside the hosted link would hand the buyer two
	// links, one of them the build that was current the day it was uploaded --
	// the staleness the hosted link exists to avoid. Scoped to the files: a
	// `continue` would have taken this grant's licence key with it.
	// Was a source-text pin on the exact expression `'' === $external ?`, which
	// is the expression that turned out to be WRONG -- it let a rejected
	// http:// link suppress the uploads. A check that nails a defect in place
	// is worse than no check. The rule is now asserted by running it, twice,
	// under "a hosted link is delivered" and "a rejected hosted link does not
	// take the uploads down with it"; what is left here is the structure those
	// two cannot see.
	'BELL: the uploads are still walked, and the choice is made once',
	str_contains( $client, 'foreach ( $uploaded as $file )' )
		&& 1 === substr_count( $client, '$uploaded = ' )
		// No `continue` on a delivery decision: it would skip the licence key
		// further down, which belongs to the same grant.
		&& 1 === preg_match( '/\$uploaded = \$hosted \? array\(\) :/', $client )
);
check(
	// Attribute defaults are evaluated before the catalogue is loaded, so a
	// default written as __( 'Buy now' ) reached a German visitor in English
	// above a fully German panel -- the same fault as "Code applied.", at a
	// second site.
	'BELL: the button label is translated after the catalogue, not before',
	1 === preg_match( "/'label'\s*=>\s*''/", $shortcode )
		&& 1 === preg_match( "/wpdc_load_catalogue\( \\\$lang \);[\s\S]{0,300}__\( 'Buy now'/", $shortcode )
);
check(
	// The shop hosts the ZIP behind Cloudflare so a buyer's link resolves to the
	// current build; Dodo's own note on uploaded files is explicit that
	// replacing one does not reach downloads already issued. Their entitlement
	// takes an `external_url` for exactly that, and it arrives in the same place
	// as the files -- dropped, a purchase set up this way would deliver by mail
	// and show nothing at all in the popup.
	'BELL: a hosted link counts as delivery, not only an uploaded file',
	// One operand, not two. The first of the pair that stood here was false for
	// every possible input -- `\]` is literal inside double quotes -- so half the
	// assertion could never fail and the `||` hid that.
	str_contains( $client, "['digital_product_delivery']['external_url']" )
);
check(
	/*
	 * THE defect this replaces, seen on a live order rather than reasoned about.
	 *
	 * Dodo delivers two shapes through one field. An uploaded file arrives as a
	 * SIGNED url: personal, complete, clickable. A hosted `external_url` is the
	 * opposite -- every buyer gets the same static address, so it proves nothing
	 * about who is asking. It is a PAGE, and that page asks for the licence key.
	 *
	 * The popup rendered it bare:
	 *   href="https://downloads.unleash-wp.com/"
	 * So a customer who had just paid landed on an empty form and was asked to
	 * type in a key the popup was showing two lines below the button. The whole
	 * point of the button is that nobody types anything.
	 *
	 * Marked on the server and attached in the browser, so the key stays out of
	 * the REST response.
	 */
	'BELL: a hosted link is marked as needing the key, a signed file is not',
	1 === preg_match( "/'url'\s*=> \\\$hosted_url,[\s\S]{0,300}'needs_key' => true/", $client )
		&& 1 === preg_match( "/'url'\s*=> \\\$url,[\s\S]{0,400}'needs_key' => false/", $client )
);
check(
	/*
	 * THE bug this replaces, and it fired on every single order.
	 *
	 * One product carries TWO entitlements -- a licence key and a set of files
	 * -- and Dodo delivers each as its own grant row. The address lives on the
	 * file row, the key on the other one. Asking for a direct link inside the
	 * loop therefore asked with whatever half had arrived, which was an empty
	 * key every time, so it fell back to the page and the operator kept seeing
	 *
	 *   href="https://downloads.unleash-wp.com/#k=6c70bf62-..."
	 *
	 * The link can only be built once every grant of the payment is walked.
	 * Pinned as a POSITION, not a presence: the call being in the file proves
	 * nothing about when it runs.
	 */
	'BELL: the direct link is built after the grants, never inside the loop',
	( static function ( string $source ): bool {
		$loop = strpos( $source, "foreach ( ( \$grants['items'] ?? array() ) as \$grant )" );
		$call = strpos( $source, 'wpdc_direct_link( $hosted_url' );
		if ( false === $loop || false === $call ) {
			return false;
		}
		// The key is collected at the bottom of the loop; the call has to come
		// after that line, not merely after the loop's opening brace.
		$collects = strpos( $source, '$keys[] = $key;' );
		return false !== $collects && $call > $collects;
	} )( $client )
);
check(
	// The other half, in the browser. The fragment and not a query: it is the one
	// part of an address a browser never sends to a server, so the key reaches no
	// access log, no proxy log and no Referer.
	'BELL: and the browser puts the key after the hash, never in the query',
	str_contains( $jsCode, 'file.needs_key && firstKey' )
		&& str_contains( $jsCode, "'k=' + encodeURIComponent(firstKey)" )
		&& ! preg_match( '/\?k=|&key=/', $jsCode )
);
check(
	// A download attribute on a PAGE asks the browser to save the HTML. It
	// belongs on the signed file and nowhere else.
	'SILENCE: only a real file is asked to download',
	1 === preg_match( "/a\.href = file\.url;[\s\S]{0,300}setAttribute\('download', ''\)/", $jsCode )
);
check(
	// Filenames and keys come out of an API response and go onto the page.
	// textContent and createElement throughout: no response of theirs becomes
	// markup here, and a download href must be theirs and must be https.
	//
	// Read from $jsCode rather than $js, and that changed the day the rule was
	// written down beside the code obeying it: a comment saying "no innerHTML
	// anywhere in this file" made this assertion FAIL, because it searched the
	// prose along with the program. A ban a paragraph can trip is not a ban.
	// $jsCode is the same file with its comments gone, and it exists for
	// exactly this -- two earlier mutations walked through checks that their
	// own explanations had satisfied.
	'BELL: the goods are built as nodes, never as markup',
	str_contains( $jsCode, 'function paintGoods' )
		// The https gate moved from an early `return` into the filter that
		// decides how many files there are -- the label depends on the count,
		// and counting entries we would refuse to render would put "· PDF" on a
		// lone button. Same property, one place: nothing but https becomes an
		// href, and nothing but a rendered file is counted.
		&& str_contains( $jsCode, "file.url.indexOf('https://') === 0" )
		&& str_contains( $jsCode, 'code.textContent = key' )
		&& ! preg_match( '/innerHTML|insertAdjacentHTML/', $jsCode )
		&& str_contains( $client, "str_starts_with( \$url, 'https://' )" )
);
check(
	// When the key IS the delivery.
	//
	// A product whose files live on the shop's own host has no link in the
	// grant: the entitlement issues a key and nothing more, because one static
	// URL cannot be personal to a buyer. Without this the customer left the
	// checkout holding a code and no address for it.
	//
	// The key goes after the '#', the one part of an address a browser never
	// sends to a server -- no access log, no proxy log, no Referer. A query
	// parameter would be written down by every hop between the buyer and the
	// file, which is the thing the download page was built to avoid.
	'BELL: a key with no file link becomes a download button, and the key rides in the fragment',
	1 === preg_match( "/get\.href = cfg\.downloadUrl \+ '#k=' \+ encodeURIComponent\(key\)/", $js )
		// Never a query parameter, on any of the three spellings that would
		// reintroduce the logging problem.
		&& ! preg_match( "/downloadUrl \+ '\?/", $js )
		&& ! preg_match( "/[?&]k=' \+ encodeURIComponent/", $js )
		// Only when Dodo delivered nothing itself: two buttons would be two
		// answers to one question, and one of them the wrong file.
		&& str_contains( $js, 'cfg.downloadUrl && files.length === 0' )
);
check(
	// It is an href a buyer clicks seconds after paying, carrying their key.
	// Plain http would put that on the wire in the clear, and the value is
	// never taken from a request -- same rule as the return URL beside it.
	'BELL: the download host is configured, https, and never comes from the caller',
	str_contains( $client, 'function wpdc_download_url' )
		&& 1 === preg_match( "/function wpdc_download_url[\s\S]{0,700}str_starts_with\( \\\$configured, 'https:\/\/' \)/", $client )
		&& str_contains( $shortcode, "'downloadUrl'     => wpdc_download_url()" )
		&& '' === wpdc_download_url()
);
check(
	// A product with no entitlement attached delivers nothing, and a grants
	// call that fails is the same empty answer -- "finished, goods unreadable"
	// must never read as "not finished" to a poll that would then never stop.
	// Their documentation spells this status `Delivered` here and `delivered`
	// on the neighbouring endpoint. An exact compare that guesses wrong is
	// invisible: an empty panel for ever, indistinguishable from a product with
	// no entitlement attached.
	'BELL: a delivered grant is recognised whichever way they capitalise it',
	str_contains( $client, "strcasecmp( 'delivered'" )
		&& ! preg_match( "/'Delivered' !== /", $client )
);
check(
	'BELL: no goods is an answer, not a failure',
	str_contains( $client, 'function wpdc_payment_goods' )
		&& 4 === substr_count( $client, 'return $none;' )
		&& str_contains( $client, "( \$grant['payment_id'] ?? '' ) !== \$payment" )
);
check(
	// A guessed session id must not be pasted into an outbound URL path, and a
	// status that is merely not-failed is not a finished order.
	'BELL: the session id is shaped before it reaches a URL',
	str_contains( $config, 'function wpdc_is_session_id' )
		&& str_contains( $config, "'/^cks_[A-Za-z0-9]{1,64}\$/'" )
		&& str_contains( $rest, "'validate_callback' => 'wpdc_is_session_id'" )
);
check(
	// Navigating to the front page on completion was shipped and read as the
	// popup breaking: the purchase ended on a page that says nothing about it.
	// Only a page somebody configured is worth leaving the dialog for.
	'BELL: without a configured thank-you page the completion is shown in place',
	str_contains( $rest, "'redirect' => wpdc_return_url()," )
		&& ! str_contains( $rest, "wpdc_return_url() : home_url()" )
		&& str_contains( $shortcode, 'class="wpdc__done" hidden' )
		&& str_contains( $js, "done.hidden = false" )
		&& 1 === preg_match( '/\.wpdc__done\[hidden\],\s*\.wpdc__frame\[hidden\] \{[^}]*display: none/', $css )
);
check(
	// Two docblocks call this the open-redirect defence, and nothing tested it:
	// removing the host comparison left all 192 checks green. It is exactly the
	// kind of guard a well-meant tidy-up deletes, because from the inside it
	// looks like a redundant string compare.
	//
	// Run, not read: constants cannot be redefined, so the harness cannot script
	// WPDC_RETURN_URL twice -- the same-origin rule is asserted against the
	// comparison the function actually performs.
	'BELL: a return URL on somebody else\'s host is refused',
	wp_parse_url( 'https://evil.example/thanks', PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST )
		&& wp_parse_url( 'https://shop.example/thanks', PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST )
		&& 1 === preg_match(
			'/function wpdc_return_url\(\)[\s\S]{0,600}wp_parse_url\(\s*\$configured,\s*PHP_URL_HOST\s*\)\s*===\s*wp_parse_url\(\s*home_url\(\),\s*PHP_URL_HOST\s*\)/',
			file_get_contents( $root . '/includes/client.php' )
		)
		// Undefined in this harness, so the function must answer with the empty
		// string that means "no thank-you page" rather than inventing one.
		&& '' === wpdc_return_url()
);
check(
	// A deadline that outlives the window it belonged to would redirect somebody
	// who deliberately closed the checkout.
	'BELL: closing the modal cancels the deadline',
	1 === preg_match( '/function closeFrame[\s\S]{0,900}settleLoading\(root\)/', $js )
);

check(
	// The panel is ours end to end: it renders no payment control and shares no
	// edge with Dodo's internals, so it cannot break when they ship. That is the
	// line between styling our own column and building against their surface.
	//
	// Sharpened when the discount field arrived: the panel now holds an input,
	// and that is fine -- a discount code is neither payment data nor personal
	// data. What must never appear here is a field that collects either, because
	// those belong in Dodo's frame and nowhere else, and a card number typed
	// into our DOM would take this shop from SAQ-A to SAQ-D.
	'BELL: the panel collects no payment or personal data',
	! preg_match(
		'/(name|id|class|placeholder|autocomplete)="[^"]*(card|cvv|cvc|iban|expiry|passw|phone|street|address|zip|postcode)/i',
		$shortcode
	)
		&& ! preg_match( '/<input[^>]*type="(password|tel)"/i', $shortcode )
);
check(
	// A cover turns a window of text into the thing the customer just chose. The
	// image is Dodo's own, and decorative -- the name is right beside it, so an
	// alt would say the product twice.
	'BELL: the cover is shown, and not announced twice',
	str_contains( $shortcode, 'wpdc__item-img' ) && str_contains( $shortcode, 'alt=""' )
		&& str_contains( $client, "'image'" )
);
check(
	// "Removing or hiding legal information violates compliance requirements",
	// says the same page. The frame is shown whole, footer included -- which is
	// why the fold that once hid it behind the wallet is gone and stays gone.
	'SILENCE: nothing hides part of Dodo frame',
	! str_contains( $css, 'wpdc__frame--folded' ) && ! str_contains( $js, 'fold' )
);
check(
	// A native <dialog>, not a div pretending. It is opened non-modal -- see the
	// Apple Pay checks above -- so Escape, focus and the backdrop are ours now;
	// what the element still buys is the open/close state, the `close` event
	// every dismissal fires, and a role assistive technology already knows.
	'BELL: the modal is a real dialog element, not a div pretending',
	str_contains( $shortcode, '<dialog class="wpdc__dialog"' )
		&& str_contains( $js, "root.querySelector('.wpdc__dialog')" )
);
check(
	// Order, not presence: a dialog that is not open has no layout, and an
	// iframe measured inside a zero-height box comes back zero. Opened after the
	// SDK renders, the frame is there and invisible.
	'BELL: the dialog is shown before the SDK is told to render into it',
	strpos( $jsCode, 'dialog.show();' ) < strpos( $jsCode, 'dodo.Checkout.open(' )
);
check(
	// Closing the window without telling the SDK leaves Dodo holding a live
	// frame in a hidden element, and the next open finds it already there.
	'BELL: closing the modal also closes the checkout',
	str_contains( $js, 'dodo.Checkout.close()' )
		&& str_contains( $js, "if (dialog && dialog.open) dialog.close();" )
);
check(
	// Escape and a click on the veil both end in `dialog.close()`, which fires
	// `close` -- listened for, so the bookkeeping happens however it was
	// dismissed, not only when the X was used.
	'BELL: a dismissal by Escape or backdrop is handled, not just the X button',
	str_contains( $js, "document.addEventListener('close'" )
);
check(
	'BELL: the mode falls to test when saved with anything unrecognised',
	str_contains( $settings, "'live_mode' === \$value ? 'live_mode' : 'test_mode'" )
);
check(
	// The page now prints product ids on purpose -- they are what goes in a
	// shortcode, and Dodo puts them in its own public payment links. The thing
	// that must never reach the page is the API key, which can issue refunds and
	// read every customer.
	'BELL: the settings page never prints the API key',
	! str_contains( $settings, 'echo wpdc_api_key' )
		&& ! str_contains( $settings, 'esc_html( wpdc_api_key' )
		&& ! str_contains( $settings, 'esc_attr( wpdc_api_key' )
);
check(
	'BELL: no admin text names a constant that no longer exists',
	! str_contains( $settings, 'WPDC_ENDPOINT' ) && ! str_contains( $shortcode, 'WPDC_ENDPOINT' )
		&& ! str_contains( $settings, 'WPDC_SECRET' ) && ! str_contains( $shortcode, 'WPDC_SECRET' )
);

check(
	/*
	 * A way out that says what it does.
	 *
	 * There is an X in the corner and Escape works, and neither helps somebody
	 * who has just paid, has their file, and is looking for the end. The
	 * operator's words: "für dumme die es nicht verstanden haben" -- which is
	 * not stupidity, it is a person finished with a task looking for the door.
	 *
	 * ONLY in the completed panel, and that is the rule worth pinning: a big
	 * friendly close button beside a card form invites the click that abandons
	 * a purchase halfway. While a payment is running, the corner X is the
	 * deliberate way out.
	 */
	'BELL: the completion panel offers a labelled way out, and only it does',
	1 === preg_match( '/wpdc__done-ok[\s\S]{0,1600}wpdc__done-dismiss/', $shortcode )
		&& 1 === substr_count( $shortcode, 'wpdc__done-dismiss' )
		&& str_contains( $jsCode, "closest('.wpdc__done-dismiss')" )
);

// ─── The price a visitor reads ───────────────────────────────────────────────
// The sales page used to say 24,99 € to everybody while the checkout charged
// ₹499 in India. Two numbers, one page, at the moment somebody decides to buy.

check(
	// Dodo stores amounts in the smallest unit of the currency, and for eight of
	// them that unit is the whole unit. Dividing by a hundred is a hundredfold
	// discount, not a rounding difference.
	'BELL: a currency without decimals is not divided by a hundred',
	0 === wpdc_currency_digits( 'JPY' )
		&& 2 === wpdc_currency_digits( 'EUR' )
		&& 3 === wpdc_currency_digits( 'KWD' )
		// Case is not the caller's problem.
		&& 0 === wpdc_currency_digits( 'jpy' )
);
check(
	// The live yen price at the time of writing is stored as 24, which is
	// fourteen cents. Printed as "0,24 JPY" that reads like a plausible number
	// and hides a real mistake; printed as "24 JPY" it is obviously wrong and
	// somebody fixes it.
	// The PROPERTY, not one spelling. Whether this reads "24 ¥" or "¥24" depends
	// on the locale and on whether PHP's Intl extension is installed, and
	// pinning a spelling made this check fail the moment the formatter learned
	// to print a symbol -- for output that was more correct, not less. What must
	// never change is the magnitude.
	'BELL: twenty-four yen prints as twenty-four',
	( static function (): bool {
		$yen = wpdc_format_price( 24, 'JPY' );
		foreach ( array( '0,24', '0.24', '24,00', '24.00' ) as $wrong ) {
			if ( str_contains( $yen, $wrong ) ) {
				return false;
			}
		}
		return str_contains( $yen, '24' );
	} )()
);
check(
	// A customer reads this, and customers read symbols. The function appended
	// the currency CODE, which was right while its only caller was an admin
	// catalogue -- and became "24,99 EUR" on a sales page the day the price
	// shortcode started rendering it, where "24,99 €" had stood for months.
	'BELL: the price a visitor reads carries a symbol, not a currency code',
	( static function (): bool {
		$eur = wpdc_format_price( 2499, 'EUR' );
		return str_contains( $eur, '24' ) && str_contains( $eur, '99' ) && ! str_contains( $eur, 'EUR' );
	} )()
);

$_SERVER['HTTP_CF_IPCOUNTRY'] = 'de';
$lower                        = wpdc_visitor_country();
$_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX';
$anon                         = wpdc_visitor_country();
$_SERVER['HTTP_CF_IPCOUNTRY'] = 'T1';
$tor                          = wpdc_visitor_country();
$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DEU';
$malformed                    = wpdc_visitor_country();
unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
$absent = wpdc_visitor_country();

check(
	// `XX` is Cloudflare's anonymising proxy and `T1` is Tor. Neither is a
	// country, and both would otherwise be looked up as one.
	'BELL: only a real country code counts as a country',
	'DE' === $lower && '' === $anon && '' === $tor && '' === $malformed && '' === $absent
);

// Base price 2499 EUR, plus a dollar rule and a yen rule.
configure();
catalogue( array( 'pdt_book' ) );
respond(
	200,
	array(
		'items' => array(
			array( 'currency' => 'USD', 'amount' => 1999 ),
			array( 'currency' => 'JPY', 'amount' => 3900 ),
		),
	)
);
$us = wpdc_price_for_country( 'pdt_book', 'US' );
$jp = wpdc_price_for_country( 'pdt_book', 'JP' );
$de = wpdc_price_for_country( 'pdt_book', 'DE' );
$ch = wpdc_price_for_country( 'pdt_book', 'CH' );
$no_country = wpdc_price_for_country( 'pdt_book', '' );
$calls      = count( $GLOBALS['wpdc_test_requests'] );

check(
	'BELL: a visitor is shown the price of their own currency',
	1999 === $us['amount'] && 'USD' === $us['currency'] && true === $us['localized']
		&& 3900 === $jp['amount'] && 'JPY' === $jp['currency']
);
check(
	// Four ways to be uncertain, one answer to all of them. The fallback is not
	// a degraded mode: it is exactly what the page showed before this feature,
	// so the worst outcome of the whole thing is the status quo.
	'BELL: every uncertainty falls back to the base price, never to nothing',
	// Base currency -- no lookup needed and none wanted.
	2499 === $de['amount'] && 'EUR' === $de['currency'] && false === $de['localized']
	// Franc is a currency we map, but no rule exists for it.
		&& 2499 === $ch['amount'] && false === $ch['localized']
	// No country at all, which is what an absent Cloudflare header gives.
		&& 2499 === $no_country['amount'] && false === $no_country['localized']
);
check(
	// One catalogue call and ONE localized-prices call, for five countries.
	// The endpoint returns every rule at once, so asking per country would
	// multiply requests by countries for an answer that arrived in one.
	'BELL: the price rules are fetched once, not once per country',
	2 === $calls
);

// The lookup itself failing, which the scenario above never reaches: Dodo is
// down, or the key was rotated. A mutation that returned the error instead of
// the base price survived every other check here, because none of them ever
// made the second call fail.
configure();
catalogue( array( 'pdt_book' ) );
respond( 500, array( 'message' => 'upstream is having a day' ) );
$outage = wpdc_price_for_country( 'pdt_book', 'US' );
check(
	// A shop whose price disappears because a third party is unreachable is a
	// shop that stops selling for a reason its customers cannot see. The base
	// price is always true, so it is what an outage falls back to.
	'BELL: an unreachable price service still yields a price',
	! wpdc_is_error( $outage )
		&& 2499 === $outage['amount']
		&& 'EUR' === $outage['currency']
		&& false === $outage['localized']
);
check(
	// Audit 2026-10-23: a failure was never remembered, so every view asked again.
	'BELL: a failed price lookup is remembered briefly, the next view asks nobody',
	( static function (): bool {
		$GLOBALS['wpdc_test_requests'] = array();
		$again = wpdc_price_for_country( 'pdt_book', 'US' );
		return ! wpdc_is_error( $again ) && 2499 === $again['amount'] && array() === $GLOBALS['wpdc_test_requests'];
	} )()
);
check(
	'BELL: at the lookup ceiling the price falls back to the base and Dodo is not asked',
	( static function (): bool {
		configure();
		catalogue( array( 'pdt_book' ) );
		$GLOBALS['wpdc_test_transients']['wpdc_price_lookups'] = WPDC_PRICE_LOOKUPS_MINUTE;
		$GLOBALS['wpdc_test_requests'] = array();
		$p = wpdc_price_for_country( 'pdt_book', 'US' );
		$asked = array_filter( $GLOBALS['wpdc_test_requests'], static fn( $r ) => str_contains( $r['url'], 'localized-prices' ) );
		return ! wpdc_is_error( $p ) && false === $p['localized'] && array() === $asked;
	} )()
);
check(
	// A busy page served from cache must never reach the ceiling.
	'BELL: only lookups that reach Dodo are counted, cached answers are free',
	( static function (): bool {
		configure();
		catalogue( array( 'pdt_book' ) );
		respond( 200, array( 'items' => array( array( 'currency' => 'USD', 'amount' => 1999 ) ) ) );
		wpdc_price_for_country( 'pdt_book', 'US' );
		wpdc_price_for_country( 'pdt_book', 'US' );
		wpdc_rest_price( new WP_REST_Request( array( 'product' => 'pdt_book' ) ) );
		return 1 === (int) get_transient( 'wpdc_price_lookups' );
	} )()
);

configure();
catalogue( array( 'pdt_book' ) );
$unknown = wpdc_price_for_country( 'pdt_not_listed', 'US' );
check(
	// The same allow-list the checkout uses. Without it this route would make
	// the shop call Dodo about any well-shaped id a stranger sends.
	'BELL: a product Dodo does not list is not priced',
	wpdc_is_error( $unknown ) && 'unknown_product' === $unknown['reason']
);

check(
	// The answer varies by country and Cloudflare sits in front of this site.
	// One price cached at the edge and handed to the next country is the exact
	// mismatch this feature exists to remove.
	'BELL: a per-visitor price is never cached anywhere',
	2 === substr_count(
		(string) strstr( (string) strstr( $rest, 'function wpdc_rest_price(' ), 'function wpdc_rest_status(', true ),
		"header( 'cache-control', 'private, no-store' )"
	)
);
check(
	// The shortcode renders the BASE price and the script replaces it. A
	// per-visitor price written into the HTML would be frozen by whichever
	// country loaded the page first and served to everyone after them.
	'BELL: the markup carries no country, so a page cache cannot freeze one',
	str_contains( $shortcode, "'<span data-wpdc-price=\"%s\">%s</span>'" )
		&& str_contains( $shortcode, 'wpdc_format_price(' )
		&& ! str_contains( $shortcode, 'wpdc_visitor_country()' )
);
check(
	// Intl knows how many decimals a currency has. Asking it is what keeps the
	// yen correct on the page after it was made correct on the server.
	// One divide-by-a-hundred survives, in the catch: an unknown currency code
	// makes Intl throw, and a checkout must not lose its totals over a
	// formatting nicety. Counted rather than forbidden, so the fallback stays
	// legal and a second one cannot appear unnoticed.
	'BELL: the browser asks Intl for the decimals instead of assuming two',
	str_contains( $js, 'resolvedOptions().maximumFractionDigits' )
		&& str_contains( $js, 'Math.pow(10, digits)' )
		&& 1 === substr_count( $js, '/ 100' )
);

// ─── The session route charges its ceiling only after validation ─────────────
//
// The ceiling exists to brake real mints. Charging it BEFORE the allow-list let
// an anonymous caller spend it on well-formed but unlisted ids -- junk that
// never reaches the payment account -- and so hold the shop shut to real buyers
// with requests that buy nothing. These exercise the route, not the client.

/** One call to the public session route, scripted end to end. */
function mint( array $params ): WP_REST_Response {
	return wpdc_rest_session( new WP_REST_Request( $params ) );
}

configure();
catalogue( array( 'pdt_pro' ) );
catalogue( array( 'pdt_pro' ) ); // the deliberate second look at an unknown id
$junk = mint( array( 'product' => 'pdt_ghost' ) );
check(
	'BELL: a well-formed but unlisted id is refused with a 400',
	400 === $junk->get_status() && 'unknown_product' === $junk->get_data()['reason']
);
check(
	// THE reorder. Charging before validation is what let junk exhaust the
	// ceiling; the counter must still be untouched after a refused id.
	'BELL: and it did NOT charge the mint ceiling',
	false === get_transient( 'wpdc_session_all' )
);
check(
	// The negative cache. A second identical junk id answers from the record
	// with no fresh Dodo call.
	'BELL: a repeat of the same unlisted id makes no new request',
	( static function (): bool {
		$GLOBALS['wpdc_test_requests'] = array();
		$again = mint( array( 'product' => 'pdt_ghost' ) );
		return 400 === $again->get_status()
			&& 'unknown_product' === $again->get_data()['reason']
			&& array() === $GLOBALS['wpdc_test_requests'];
	} )()
);
check(
	// Audit 2026-10-23: the memo above is per id, so rotating ids bought one fetch each.
	'BELL: a different invented id right after does not refetch the catalogue',
	( static function (): bool {
		$GLOBALS['wpdc_test_requests'] = array();
		$other = mint( array( 'product' => 'pdt_ghost2' ) );
		return 400 === $other->get_status()
			&& 'unknown_product' === $other->get_data()['reason']
			&& array() === $GLOBALS['wpdc_test_requests'];
	} )()
);
check(
	'BELL: once the window passes, a just-created product is found by a fresh look',
	( static function (): bool {
		unset( $GLOBALS['wpdc_test_transients'][ wpdc_catalog_key() . '_forced' ] );
		$GLOBALS['wpdc_test_requests'] = array();
		catalogue( array( 'pdt_pro', 'pdt_new' ) );
		respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_n', 'session_id' => 'cks_newprod01' ) );
		return 200 === mint( array( 'product' => 'pdt_new' ) )->get_status()
			&& 2 === count( $GLOBALS['wpdc_test_requests'] );
	} )()
);

configure();
catalogue( array( 'pdt_pro' ) );
respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_m', 'session_id' => 'cks_mint01' ) );
$good = mint( array( 'product' => 'pdt_pro' ) );
check(
	'BELL: a listed product mints and the browser is given a url and a session',
	200 === $good->get_status()
		&& str_contains( $good->get_data()['url'], 'cks_m' )
		&& 'cks_mint01' === $good->get_data()['session']
);
check(
	// Charged now, because this one reached the payment account.
	'BELL: a successful mint charges the mint ceiling, and only that bucket',
	1 === (int) get_transient( 'wpdc_session_all' )
		&& false === get_transient( 'wpdc_discount_all' )
);
check(
	// Fix 2: a minted session is remembered, so the status route will confirm it.
	'BELL: a minted session is recorded so the status route will own it',
	true === wpdc_session_known( 'cks_mint01' )
);

configure();
$GLOBALS['wpdc_test_transients']['wpdc_session_all'] = WPDC_SESSION_CEILING;
$busy = mint( array( 'product' => 'pdt_pro' ) );
check(
	'BELL: at the ceiling the route answers 429 without a mint',
	429 === $busy->get_status()
		&& true === $busy->get_data()['retriable']
		&& array() === $GLOBALS['wpdc_test_requests']
);

// ─── A discount-carrying request has its own, smaller bucket ─────────────────
//
// Testing codes is the one probe a visitor can run against this route, and the
// shared bucket made it an oracle at the mint rate AND a way to drain the mint
// ceiling. Its own bucket bounds both.

configure();
catalogue( array( 'pdt_pro' ) );
respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_d', 'session_id' => 'cks_disc01' ) );
$coded = mint( array( 'product' => 'pdt_pro', 'discount' => 'SAVE10' ) );
check(
	'BELL: a discounted mint spends the discount bucket, not the mint one',
	200 === $coded->get_status()
		&& 1 === (int) get_transient( 'wpdc_discount_all' )
		&& false === get_transient( 'wpdc_session_all' )
);

configure();
$GLOBALS['wpdc_test_transients']['wpdc_discount_all'] = WPDC_DISCOUNT_CEILING;
$codeBusy = mint( array( 'product' => 'pdt_pro', 'discount' => 'SAVE10' ) );
check(
	// The oracle is bounded here, while an ordinary buyer who names no code is
	// untouched: the mint bucket is still empty.
	'BELL: a full discount bucket stops code-testing but not the ordinary checkout',
	429 === $codeBusy->get_status()
		&& array() === $GLOBALS['wpdc_test_requests']
		&& false === get_transient( 'wpdc_session_all' )
);

// ─── The status route refuses a session this site never minted ───────────────
//
// It is public and spends the shop's one API key per call. An id it never
// minted is not its to confirm, and asking Dodo about a loop of invented ids is
// how this route becomes a way to burn the shop's rate limit.

configure();
$GLOBALS['wpdc_test_transients'] = array();
$GLOBALS['wpdc_test_requests']   = array();
$GLOBALS['wpdc_test_queue']      = array();
respond( 200, array( 'session_id' => 'cks_x', 'status' => 'open' ) ); // must NOT be consumed
$unregistered = wpdc_rest_status( new WP_REST_Request( array( 'session' => 'cks_neverminted0000000000' ) ) );
check(
	'BELL: an unknown session is answered "not yet" before any Dodo call',
	200 === $unregistered->get_status()
		&& false === $unregistered->get_data()['finished']
		&& array() === $GLOBALS['wpdc_test_requests']
);
check(
	// And a remembered one is let through: same id, now recorded, reaches Dodo.
	'BELL: a remembered session is confirmed, spending exactly one call',
	( static function (): bool {
		wpdc_remember_session( 'cks_neverminted0000000000' );
		$known = wpdc_rest_status( new WP_REST_Request( array( 'session' => 'cks_neverminted0000000000' ) ) );
		return 200 === $known->get_status()
			&& false === $known->get_data()['finished']
			&& 1 === count( $GLOBALS['wpdc_test_requests'] );
	} )()
);

// ─── An optional allow-list closes the "buy a different live SKU" gap ────────
//
// Dodo's live list stops archived and never-listed ids, but not a crafted
// request naming a different LIVE product than the page shows. WPDC_ALLOWED_
// PRODUCTS, when set, refuses anything off the list on the mint, bump and price
// paths. Defined LAST so no earlier test runs under the restriction, and so the
// default -- the constant unset -- is what every test above proved unchanged.
// The ids are plain pdt_ values, which the secret gate does not match.

define( 'WPDC_ALLOWED_PRODUCTS', 'pdt_allowed, pdt_bumpok' );

configure();
$blocked = wpdc_create_session( 'pdt_other' );
check(
	'BELL: a live-but-unlisted id is refused before any Dodo call when the allow-list is set',
	'unknown_product' === $blocked['reason']
		&& false === $blocked['retriable']
		&& array() === $GLOBALS['wpdc_test_requests']
);

configure();
catalogue( array( 'pdt_allowed' ) );
respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_a', 'session_id' => 'cks_allow01' ) );
$allowedOk = wpdc_create_session( 'pdt_allowed' );
check(
	'SILENCE: an allow-listed id still mints',
	true === $allowedOk['ok'] && str_contains( $allowedOk['checkout_url'], 'cks_a' )
);

configure();
$offBump = wpdc_create_session( 'pdt_allowed', 1, 'pdt_other' );
check(
	'BELL: a bump off the allow-list is refused, and no checkout is created around it',
	'unknown_product' === $offBump['reason'] && array() === $GLOBALS['wpdc_test_requests']
);

configure();
$offPrice = wpdc_price_for_country( 'pdt_other', 'DE' );
check(
	'BELL: the price path refuses a product off the allow-list',
	wpdc_is_error( $offPrice ) && 'unknown_product' === $offPrice['reason']
);

// The SVG carries wide inner margins: at 16px the mark rendered about 9px tall,
// smaller than the sentence it closes. Owner flagged it on the live panel.
check(
	'BELL: the Dodo mark in the payment line is cropped to its ink and centred by flex alone',
	str_contains( $shortcode, 'alt="Dodo Payments" width="125" height="18"' )
		&& 1 === preg_match( '/\.wpdc__legal-mark\s*\{[^}]*height:\s*18px/', $css )
		// A transform moves the picture but not its focus ring; the ring then sat off the logo.
		&& 0 === preg_match( '/\.wpdc__legal-mark\s*\{[^}]*transform/', $css )
		&& str_contains( (string) file_get_contents( $root . '/assets/dodo-logo.svg' ), 'viewBox="16 10 201 29"' )
);

// ─── A sign-in code to the paying address ────────────────────────────────────

wpdc_register_rest();
$routes = $GLOBALS['wpdc_test_routes'];
check(
	'BELL: the account-code route is POST and validates the session id',
	'POST' === ( $routes['wp-dodo-checkout/v1/account-code']['methods'] ?? '' )
		&& 'wpdc_is_session_id' === ( $routes['wp-dodo-checkout/v1/account-code']['args']['session']['validate_callback'] ?? '' )
);

$code_session = 'cks_handoffabcdefghijklmno';

configure();
wpdc_remember_session( $code_session );
check(
	'BELL: without a shared secret nobody is asked and the modal asks for the address',
	'' === wpdc_account_code( $code_session, 'de' ) && array() === $GLOBALS['wpdc_test_requests']
);

define( 'WPDC_ACCOUNT_SECRET', 'test-checkout-secret' );

/** A paid checkout followed by the account's answer. */
function code_scripted( string $session, int $status, array $answer, string $paid = 'succeeded' ): string {
	configure();
	wpdc_remember_session( $session );
	respond( 200, array( 'payment_status' => $paid, 'payment_id' => 'pay_0Test123', 'customer' => array( 'email' => 'typed@example.com' ) ) );
	respond( $status, $answer );
	return wpdc_account_code( $session, 'de' );
}

check(
	'BELL: an unknown session spends no call',
	( function (): bool {
		configure();
		return '' === wpdc_account_code( 'cks_neverminted0000000000', 'de' ) && array() === $GLOBALS['wpdc_test_requests'];
	} )()
);

check(
	'BELL: a paid checkout comes back as the address the account mailed',
	'buyer@example.com' === code_scripted( $code_session, 200, array( 'email' => 'Buyer@Example.com' ) )
);

$code_call = $GLOBALS['wpdc_test_requests'][1] ?? array();
check(
	'BELL: the account is asked with the secret and the payment id, never an address from here',
	'https://account.unleash-wp.com/api/handoff/code' === ( $code_call['url'] ?? '' )
		&& 'Bearer test-checkout-secret' === ( $code_call['args']['headers']['authorization'] ?? '' )
		&& '{"payment_id":"pay_0Test123","lang":"de"}' === ( $code_call['args']['body'] ?? '' )
		&& 0 === ( $code_call['args']['redirection'] ?? null )
		&& true === ( $code_call['args']['sslverify'] ?? null )
);

check(
	'BELL: a checkout that has not succeeded never asks the account',
	'' === code_scripted( $code_session, 200, array( 'email' => 'buyer@example.com' ), 'processing' )
		&& 1 === count( $GLOBALS['wpdc_test_requests'] )
);

check(
	'BELL: an answer that is not an address is dropped',
	'' === code_scripted( $code_session, 200, array( 'email' => 'not an address' ) )
		&& '' === code_scripted( $code_session, 200, array( 'url' => 'https://evil.example' ) )
);

foreach ( array( 401, 409, 429, 503 ) as $refusal ) {
	check(
		"BELL: account $refusal leaves the modal asking for the address",
		'' === code_scripted( $code_session, $refusal, array( 'error' => 'x' ) )
	);
}

check(
	'BELL: a third code for one checkout does not reach the account',
	( function () use ( $code_session ): bool {
		code_scripted( $code_session, 200, array( 'email' => 'buyer@example.com' ) );
		respond( 200, array( 'payment_status' => 'succeeded', 'payment_id' => 'pay_0Test123' ) );
		respond( 200, array( 'email' => 'buyer@example.com' ) );
		wpdc_account_code( $code_session, 'de' );
		$GLOBALS['wpdc_test_requests'] = array();
		return '' === wpdc_account_code( $code_session, 'de' ) && array() === $GLOBALS['wpdc_test_requests'];
	} )()
);

check(
	// Audit 2026-10-23: a buyer clicking before Dodo said "succeeded" burned the allowance.
	'BELL: clicks before the payment succeeded do not spend the code allowance',
	( function () use ( $code_session ): bool {
		configure();
		wpdc_remember_session( $code_session );
		for ( $i = 0; $i < WPDC_ACCOUNT_CODES_SALE + 1; $i++ ) {
			respond( 200, array( 'payment_status' => 'processing' ) );
			wpdc_account_code( $code_session, 'de' );
		}
		$GLOBALS['wpdc_test_requests'] = array();
		respond( 200, array( 'payment_status' => 'succeeded', 'payment_id' => 'pay_0Test123' ) );
		respond( 200, array( 'email' => 'buyer@example.com' ) );
		return 'buyer@example.com' === wpdc_account_code( $code_session, 'de' )
			&& 2 === count( $GLOBALS['wpdc_test_requests'] );
	} )()
);
check(
	'BELL: the Dodo lookups behind early clicks have their own ceiling',
	( function () use ( $code_session ): bool {
		configure();
		wpdc_remember_session( $code_session );
		for ( $i = 0; $i < WPDC_ACCOUNT_LOOKUPS_SALE; $i++ ) {
			respond( 200, array( 'payment_status' => 'processing' ) );
			wpdc_account_code( $code_session, 'de' );
		}
		$GLOBALS['wpdc_test_requests'] = array();
		return '' === wpdc_account_code( $code_session, 'de' ) && array() === $GLOBALS['wpdc_test_requests'];
	} )()
);
check(
	'BELL: the route answers with the address and nothing about the payment',
	( function () use ( $code_session ): bool {
		configure();
		wpdc_remember_session( $code_session );
		respond( 200, array( 'payment_status' => 'succeeded', 'payment_id' => 'pay_0Test123' ) );
		respond( 200, array( 'email' => 'buyer@example.com' ) );
		$r = wpdc_rest_account_code( new WP_REST_Request( array( 'session' => $code_session ) ) );
		return array( 'sent' => true, 'email' => 'buyer@example.com' ) === $r->get_data()
			&& ! str_contains( (string) json_encode( $r->get_data() ), 'pay_' )
			&& 'private, no-store' === ( $r->headers['cache-control'] ?? '' );
	} )()
);

check(
	'BELL: the completed panel offers the account and says how it signs in',
	str_contains( $shortcode, 'class="wpdc__account" data-wpdc-account' )
		&& str_contains( $shortcode, "'Open my account'" )
		&& str_contains( $shortcode, "'Downloads, keys and invoices. We send a sign-in code to your purchase email, no password.'" )
		&& str_contains( $shortcode, "'accountCode' => esc_url_raw( rest_url( 'wp-dodo-checkout/v1/account-code' ) )" )
		&& str_contains( $shortcode, "'account'  => wpdc_account_downloads_url()" )
);
check(
	'BELL: the account button opens the sign-in modal at the code, and still works without it',
	( function () use ( $root ): bool {
		$js = (string) file_get_contents( $root . '/assets/checkout.js' );
		$modal = (string) file_get_contents( $root . '/assets/account-login.js' );
		return str_contains( $js, 'cfg.accountCode' ) && str_contains( $js, "credentials: 'omit'" )
			&& str_contains( $js, 'login.show(btn, email ? { email: email } : null)' )
			&& str_contains( $js, "window.open(cfg.account, '_blank', 'noopener')" )
			&& str_contains( $modal, "opts && opts.email" ) && str_contains( $modal, "c.s.sentTo.replace('%s', email)" );
	} )()
);

// ─── The sign-in modal's loader ──────────────────────────────────────────────

$loader = wpdc_account_login_loader();
$login  = wpdc_account_login_config();
check(
	'BELL: every page carries only a small loader, the modal itself is fetched on click',
	str_starts_with( $loader, '<script id="wpdc-account-login">' )
		&& ! str_contains( $loader, '<script src' )
		&& strlen( WPDC_ACCOUNT_LOGIN_LOADER ) < 1500
		&& str_contains( $loader, "c.script" ) && str_ends_with( $login['script'], '/assets/account-login.js?ver=' . WPDC_VERSION )
);
check(
	'BELL: the loader talks to the configured account only, with the cookie, and opens new tabs without an opener',
	'https://account.unleash-wp.com' === $login['account']
		&& str_contains( WPDC_ACCOUNT_LOGIN_LOADER, "c.account+'/api/sso/session',{credentials:'include'}" )
		&& str_contains( WPDC_ACCOUNT_LOGIN_LOADER, "'_blank','noopener'" )
		&& str_contains( WPDC_ACCOUNT_LOGIN_LOADER, "h.indexOf(c.account+'/')===0" )
);
check(
	'BELL: a modifier click on an account link keeps the browser meaning',
	str_contains( WPDC_ACCOUNT_LOGIN_LOADER, 'e.metaKey||e.ctrlKey||e.shiftKey||e.altKey' )
);
check(
	'BELL: config text cannot close the script tag it is printed in',
	( function (): bool {
		$GLOBALS['wpdc_test_filters'] = array();
		$json = wp_json_encode( array( 'x' => '</script><script>alert(1)</script>' ) );
		return ! str_contains( $json, '</script>' );
	} )()
);
check(
	'BELL: the modal asks the account for a code and a sign-in, with the cookie, never leaking whether an address exists',
	( function () use ( $root ): bool {
		$js = (string) file_get_contents( $root . '/assets/account-login.js' );
		return str_contains( $js, "'/api/auth/email-otp/send-verification-otp', { email: email, type: 'sign-in' }" )
			&& str_contains( $js, "'/api/auth/sign-in/email-otp', { email: email, otp: code }" )
			&& str_contains( $js, "credentials: 'include'" )
			&& str_contains( $js, "autocomplete: 'one-time-code'" )
			&& str_contains( $js, "inputmode: 'numeric'" )
			// Two ways to the code step: after this modal sent one, or after the account mailed one for a purchase.
			&& 2 === substr_count( $js, "step('code')" );
	} )()
);
check(
	'BELL: with nothing configured the account is the production host',
	( function (): bool {
		return 'https://account.unleash-wp.com' === wpdc_account_url();
	} )()
);

check(
	// Measured live: after five wrong codes the account answers 403 TOO_MANY_ATTEMPTS.
	// "That code did not work" there sends the buyer back to typing a dead code.
	'BELL: a locked code tells the buyer to get a new one',
	( function () use ( $root ): bool {
		$js = (string) file_get_contents( $root . '/assets/account-login.js' );
		return str_contains( $js, 'r.status === 403 ? c.s.locked' )
			&& str_contains( (string) file_get_contents( $root . '/includes/account.php' ), "'locked'      => __( 'Too many wrong codes. Send yourself a new one.'" );
	} )()
);

check(
	// Measured live: the theme turned "Code senden" into an uppercase, transparent, framed button.
	'BELL: the modal keeps its own yellow button against the theme',
	( function () use ( $root ): bool {
		$css = (string) file_get_contents( $root . '/assets/account-login.css' );
		return 1 === preg_match( '/\.uwp-login__primary\s*\{[^}]*background:\s*#fcbe00 !important/', $css )
			&& str_contains( $css, 'text-transform: none !important' )
			&& 1 === preg_match( '/button::before,\s*dialog\.uwp-login button::after\s*\{\s*display:\s*none !important/', $css );
	} )()
);

check(
	// Measured live: Impreza's `.no-touch [type="submit"]:not(.w-btn):not(.button):hover` (0,5,0) paints the text white.
	'BELL: the modal button keeps navy text on hover against the theme',
	1 === preg_match(
		'/dialog\.uwp-login\.uwp-login \.uwp-login__primary\.uwp-login__primary:not\(\[hidden\]\):hover,[^{]*\{[^}]*color:\s*#203159 !important/',
		(string) file_get_contents( $root . '/assets/account-login.css' )
	)
);

// ─── The header shortcode ────────────────────────────────────────────────────

$icon = wpdc_render_account( array() );
check(
	'BELL: [wpdc_account] is a real link into the account that the modal takes over',
	str_starts_with( $icon, '<a class="wpdc-account-link" href="https://account.unleash-wp.com/downloads" data-uwp-login' )
		&& str_contains( $icon, '<svg' ) && str_contains( $icon, 'aria-hidden="true"' )
);
check(
	'BELL: an icon-only link still has a name a screen reader can say',
	str_contains( $icon, 'aria-label="My account"' )
);
$labelled = wpdc_render_account( array( 'label' => 'Konto <b>x</b>' ) );
check(
	'BELL: a visible label replaces the aria-label and is escaped',
	str_contains( $labelled, '<span class="wpdc-account-link__text">Konto &lt;b&gt;x&lt;/b&gt;</span>' )
		&& ! str_contains( $labelled, 'aria-label=' )
);
check(
	'BELL: the icon size is clamped, never whatever the attribute says',
	str_contains( wpdc_render_account( array( 'size' => '9999' ) ), 'width="48" height="48"' )
		&& str_contains( wpdc_render_account( array( 'size' => 'x" onload="alert(1)' ) ), 'width="12" height="12"' )
);
check(
	'BELL: the shortcode is registered under the plugin prefix',
	str_contains( $shortcode, "add_shortcode( 'wpdc_account', 'wpdc_render_account' );" )
);

// Measured live: Impreza's header Text element prints its value raw, so the
// shortcode reached the page as "[wpdc_account]".
check(
	'BELL: [wpdc_account] in a theme element that runs no shortcodes still becomes the icon',
	( function (): bool {
		$page = '<div class="w-text"><span class="w-text-value">[wpdc_account]</span></div>';
		$out  = wpdc_account_expand( $page );
		return str_contains( $out, '<a class="wpdc-account-link"' ) && ! str_contains( $out, '[wpdc_account]' );
	} )()
);
check(
	'BELL: attributes survive the late expansion',
	str_contains( wpdc_account_expand( '<p>[wpdc_account label="Konto" size="24"]</p>' ), '<span class="wpdc-account-link__text">Konto</span>' )
		&& str_contains( wpdc_account_expand( '<p>[wpdc_account size="24"]</p>' ), 'width="24" height="24"' )
);
check(
	// Audit 2026-10-23: expanded inside an attribute or a script, the link's
	// quotes break out of it.
	'BELL: the late expansion touches text only, never a tag, a script or a style',
	( function (): bool {
		$inert = array(
			'<a title="[wpdc_account]">x</a>',
			'<a title="a > b [wpdc_account]" href="/">x</a>',
			"<img alt='[wpdc_account label=\"x\"]'>",
			'<script>var s = "[wpdc_account]";</script>',
			'<SCRIPT type="text/javascript">if (a<b) { s = "[wpdc_account]"; }</SCRIPT>',
			'<style>/* [wpdc_account] */</style>',
			'<!-- [wpdc_account] -->',
		);
		foreach ( $inert as $page ) {
			if ( $page !== wpdc_account_expand( $page ) ) {
				return false;
			}
		}
		$mixed = wpdc_account_expand( '<a title="[wpdc_account]">[wpdc_account]</a><script>"[wpdc_account]"</script>' );
		return 1 === substr_count( $mixed, 'class="wpdc-account-link"' )
			&& str_starts_with( $mixed, '<a title="[wpdc_account]"><a class="wpdc-account-link"' )
			&& str_ends_with( $mixed, '</a></a><script>"[wpdc_account]"</script>' );
	} )()
);
check(
	'SILENCE: a page without the shortcode comes back byte for byte',
	'<html><body>[wpdc_checkout] [wpdc_accountx]</body></html>' === wpdc_account_expand( '<html><body>[wpdc_checkout] [wpdc_accountx]</body></html>' )
);

// ─── Spam guard in the modal (contract: account /api/pow/challenge) ──────────

$modal = (string) file_get_contents( $root . '/assets/account-login.js' );
check(
	'BELL: the modal fetches a proof-of-work challenge and solves it with SHA-256',
	str_contains( $modal, "'/api/pow/challenge'" )
		&& str_contains( $modal, "crypto.subtle.digest('SHA-256'" )
		&& str_contains( $modal, "'x-uwp-pow'" )
);
check(
	'BELL: the solved work travels as the agreed base64 JSON of salt, number, challenge, signature',
	str_contains( $modal, 'btoa(JSON.stringify({ salt: ch.salt, number: n, challenge: ch.challenge, signature: ch.signature }))' )
);
check(
	'BELL: the next challenge is fetched right after every send, so a resend is not refused for being too fast',
	1 === preg_match( '/\.then\(function \(r\) \{\s*\/\/ Every proof is single use: the next one starts now\.\s*prefetch\(\);/', $modal )
);
check(
	'BELL: a refused proof is retried once with fresh work, then said plainly',
	str_contains( $modal, "'POW_REQUIRED'" ) && str_contains( $modal, 'c.s.powFailed' )
		&& str_contains( $modal, 'setTimeout(go, 2100)' )
		&& str_contains( (string) file_get_contents( $root . '/includes/account.php' ), "'powFailed'   => __( 'The security check did not work. Try again.'" )
);
check(
	'BELL: a honeypot field the person never sees, sent as x-uwp-hp only when filled',
	str_contains( $modal, "name: 'uwp_extra_field'" ) && ! str_contains( $modal, "name: 'website'" ) && str_contains( $modal, "tabindex: '-1'" ) && str_contains( $modal, "'aria-hidden': 'true'" )
		&& str_contains( $modal, "'x-uwp-hp'" )
		&& str_contains( (string) file_get_contents( $root . '/assets/account-login.css' ), '.uwp-login__hp' )
);

check(
	'BELL: while a refused send waits to retry, the button stays busy',
	1 === preg_match( '/setTimeout\(go, 2100\); \}\)\.then\(function \(\) \{\s*busy\(button, false\);\s*return sendCode\(button, true\);/', $modal )
		&& 0 === preg_match( '/prefetch\(\);\s*busy\(button, false\);\s*if \(r\.status === 403\)/', $modal )
);

// The allow-list defined above is in force here, so these sessions buy pdt_allowed.
// ─── Prefill from the UnleashWP account ──────────────────────────────────────

/** One session, scripted, with whatever prefill the browser sent. */
function prefill_sent( ?array $prefill ): array {
	configure();
	catalogue( array( 'pdt_allowed' ) );
	respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_9', 'session_id' => 'cks_9' ) );
	wpdc_create_session( 'pdt_allowed', 1, null, 'de', null, $prefill );
	return json_decode( last_request()['args']['body'], true );
}

$filled = prefill_sent( wpdc_prefill_customer( 'kunde@example.com', 'Kunde Eins' ) );
check(
	'BELL: a signed-in customer reaches Dodo prefilled, and may still change both fields',
	array( 'email' => 'kunde@example.com', 'name' => 'Kunde Eins' ) === ( $filled['customer'] ?? null )
		&& true === ( $filled['feature_flags']['allow_customer_editing_email'] ?? null )
		&& true === ( $filled['feature_flags']['allow_customer_editing_name'] ?? null )
);
check(
	'SILENCE: without a prefill no customer is sent',
	! isset( prefill_sent( null )['customer'] )
);
check(
	'BELL: a prefill is checked, never trusted: a bad address is dropped, a name is cleaned and capped',
	null === wpdc_prefill_customer( 'not-an-address', 'X' )
		&& null === wpdc_prefill_customer( str_repeat( 'a', 250 ) . '@example.com', 'X' )
		&& array( 'email' => 'kunde@example.com' ) === wpdc_prefill_customer( 'Kunde@Example.com ', "  \t" )
		&& 'Kunde script' === ( wpdc_prefill_customer( 'kunde@example.com', "<b>Kunde</b> \x07script" )['name'] ?? '' )
		&& 100 === mb_strlen( wpdc_prefill_customer( 'kunde@example.com', str_repeat( 'ä', 300 ) )['name'] ?? '' )
);
check(
	'BELL: a site that answers wpdc_customer itself still wins over the browser',
	( function (): bool {
		$sent = prefill_sent( null );
		configure();
		$GLOBALS['wpdc_test_filters']['wpdc_customer'] = array( 'email' => 'own@example.com' );
		catalogue( array( 'pdt_allowed' ) );
		respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_8', 'session_id' => 'cks_8' ) );
		wpdc_create_session( 'pdt_allowed', 1, null, 'de', null, wpdc_prefill_customer( 'kunde@example.com', 'K' ) );
		return 'own@example.com' === ( json_decode( last_request()['args']['body'], true )['customer']['email'] ?? '' );
	} )()
);
check(
	'BELL: the session route takes email and name, both optional',
	( function (): bool {
		$GLOBALS['wpdc_test_routes'] = array();
		wpdc_register_rest();
		$args = $GLOBALS['wpdc_test_routes']['wp-dodo-checkout/v1/session']['args'] ?? array();
		return isset( $args['email'], $args['name'] ) && empty( $args['email']['required'] ) && empty( $args['name']['required'] );
	} )()
);

$checkoutJs = (string) file_get_contents( $root . '/assets/checkout.js' );
check(
	'BELL: the checkout asks the account who is signed in, with the cookie, and waits at most 800 ms',
	str_contains( $checkoutJs, "login.account + '/api/checkout/prefill', { credentials: 'include' }" )
		&& str_contains( $checkoutJs, 'setTimeout(function () { resolve(null); }, 800)' )
		&& 1 === preg_match( '/if \(me && me\.email\) \{\s*body\.email = me\.email;/', $checkoutJs )
);
check(
	'BELL: the account is asked as early as the SDK is warmed, not on the click',
	1 === preg_match( "/closest\('\[data-wpdc-open\], \.wpdc__button'\)\) \{\s*warmSdk\(\);\s*who\(\);/", $checkoutJs )
);
check(
	'BELL: "Already a customer? Sign in" sits in the panel, hidden until the account says nobody is signed in',
	str_contains( $shortcode, '<p class="wpdc__signin" hidden>' )
		&& str_contains( $shortcode, "'Already a customer? Sign in'" )
		&& str_contains( $checkoutJs, 'signin.hidden = !window.wpdcAccountLogin || !!(me && me.email);' )
);
check(
	'BELL: signing in from the checkout continues the purchase prefilled, it does not leave for Downloads',
	str_contains( $checkoutJs, 'login.show(btn, { onDone: function () {' )
		&& str_contains( $checkoutJs, 'remintWith(root, root.dataset.discount || ' )
		&& str_contains( $modal = (string) file_get_contents( $root . '/assets/account-login.js' ), 'if (onDone) { var next = onDone; onDone = null; dialog.close(); next(); return; }' )
		&& str_contains( (string) file_get_contents( $root . '/includes/account.php' ), "'continue'    => __( 'Continue to checkout'" )
);

check(
	// Measured live: the lifted dialog carries the class too, so a bare closest()
	// found the dialog, not the block with the session. The account button and
	// the sign-in link both lost their checkout that way.
	'BELL: every handler finds its checkout through rootFor, which survives the lift',
	1 === substr_count( $checkoutJs = (string) file_get_contents( $root . '/assets/checkout.js' ), "closest('.wp-dodo-checkout')" )
		&& 1 === preg_match( "/data-wpdc-account\]'\)[\s\S]{0,400}var root = rootFor\(btn\);/", $checkoutJs )
		&& 1 === preg_match( "/data-wpdc-signin\]'\)[\s\S]{0,300}var root = rootFor\(btn\);/", $checkoutJs )
);

check(
	// Measured live on 0.11.1: after signing in from the panel the discount line
	// kept "Opening checkout…" for good, because only the discount path overwrote it.
	'BELL: the re-mint after signing in clears the busy line it borrowed',
	str_contains( (string) file_get_contents( $root . '/assets/checkout.js' ), ".then(function () { note.textContent = ''; })" )
);

check(
	// Owner: the underlined line was too quiet to be found.
	'BELL: "Already a customer? Sign in" is a framed button with an icon and a reason, not a footnote',
	str_contains( $shortcode, '<span class="wpdc__signin-text"><strong>' )
		&& str_contains( $shortcode, "'Your details are filled in for you'" )
		&& str_contains( $shortcode, 'class="wpdc__signin-icon"' )
		&& 1 === preg_match( '/\.wpdc__panel \.wpdc__signin-link \{[^}]*border: 1px solid var\(--wpdc-panel-accent\) !important/', (string) file_get_contents( $root . '/assets/checkout.css' ) )
);

// ─── Signed prefill ticket from the account ──────────────────────────────────

/** A ticket as account signs it. */
function ticket_for( array $claims, string $secret = 'test-checkout-secret' ): string {
	$payload = rtrim( strtr( base64_encode( (string) json_encode( $claims ) ), '+/', '-_' ), '=' );
	return $payload . '.' . hash_hmac( 'sha256', $payload, $secret );
}
$soon    = (int) ( microtime( true ) * 1000 ) + 300000;
$billing = array( 'country' => 'DE', 'zipcode' => '10115', 'city' => 'Berlin', 'street' => 'Musterstr. 1' );

check(
	'BELL: a ticket signed by the account is read back: customer id and address',
	array( 'cid' => 'cus_0Abc123', 'billing' => $billing ) === wpdc_prefill_ticket( ticket_for( array( 'v' => 1, 'cid' => 'cus_0Abc123', 'billing' => $billing, 'exp' => $soon ) ) )
);
check(
	'BELL: a forged, expired or malformed ticket is nothing, never a customer id',
	null === wpdc_prefill_ticket( ticket_for( array( 'v' => 1, 'cid' => 'cus_0Abc123', 'exp' => $soon ), 'other-secret' ) )
		&& null === wpdc_prefill_ticket( ticket_for( array( 'v' => 1, 'cid' => 'cus_0Abc123', 'exp' => $soon - 600000 ) ) )
		&& null === wpdc_prefill_ticket( ticket_for( array( 'v' => 2, 'cid' => 'cus_0Abc123', 'exp' => $soon ) ) )
		&& null === wpdc_prefill_ticket( 'abc' )
		&& null === wpdc_prefill_ticket( str_repeat( 'a', 3000 ) . '.' . str_repeat( 'b', 64 ) )
);
check(
	'BELL: inside a valid ticket, a strange id or country is dropped, fields are capped',
	( function () use ( $soon ): bool {
		$t = wpdc_prefill_ticket( ticket_for( array( 'v' => 1, 'cid' => 'pay_notacustomer', 'billing' => array( 'country' => 'Germany', 'city' => 'X' ), 'exp' => $soon ) ) );
		$u = wpdc_prefill_ticket( ticket_for( array( 'v' => 1, 'billing' => array( 'country' => 'DE', 'city' => str_repeat( 'x', 300 ), 'street' => '<b>A</b>' ), 'exp' => $soon ) ) );
		return is_array( $t ) && ! isset( $t['cid'] ) && ! isset( $t['billing'] )
			&& 100 === strlen( $u['billing']['city'] ?? '' ) && 'A' === ( $u['billing']['street'] ?? '' );
	} )()
);

/** One session with a prefill and a ticket. */
function ticket_sent( ?array $prefill, ?array $ticket ): array {
	configure();
	catalogue( array( 'pdt_allowed' ) );
	respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_7', 'session_id' => 'cks_7' ) );
	wpdc_create_session( 'pdt_allowed', 1, null, 'de', null, $prefill, $ticket );
	return json_decode( last_request()['args']['body'], true );
}

$withCid = ticket_sent( wpdc_prefill_customer( 'kunde@example.com', 'K' ), array( 'cid' => 'cus_0Abc123', 'billing' => $billing ) );
check(
	'BELL: with a signed customer id Dodo gets the customer itself, with the address, all still editable',
	array( 'customer_id' => 'cus_0Abc123' ) === ( $withCid['customer'] ?? null )
		&& $billing === ( $withCid['billing_address'] ?? null )
		&& true === ( $withCid['show_saved_payment_methods'] ?? null )
		&& true === ( $withCid['feature_flags']['allow_customer_editing_zipcode'] ?? null )
		&& true === ( $withCid['feature_flags']['allow_customer_editing_country'] ?? null )
);
check(
	'SILENCE: without a ticket the address prefill is what it was',
	( function (): bool {
		$sent = ticket_sent( wpdc_prefill_customer( 'kunde@example.com', 'K' ), null );
		return array( 'email' => 'kunde@example.com', 'name' => 'K' ) === ( $sent['customer'] ?? null ) && ! isset( $sent['billing_address'] )
			&& false === ( $sent['show_saved_payment_methods'] ?? null );
	} )()
);
check(
	'BELL: the session route takes a ticket, optional and bounded',
	( function (): bool {
		$GLOBALS['wpdc_test_routes'] = array();
		wpdc_register_rest();
		$arg = $GLOBALS['wpdc_test_routes']['wp-dodo-checkout/v1/session']['args']['ticket'] ?? null;
		return is_array( $arg ) && empty( $arg['required'] ) && false === ( $arg['validate_callback'] )( str_repeat( 'a', 2100 ) );
	} )()
);
check(
	'BELL: the checkout asks the account for the prefill and its ticket, and passes the ticket on',
	str_contains( $checkoutJs = (string) file_get_contents( $root . '/assets/checkout.js' ), "login.account + '/api/checkout/prefill', { credentials: 'include' }" )
		&& str_contains( $checkoutJs, 'if (me.ticket) body.ticket = me.ticket;' )
);

check(
	'BELL: even a correctly signed ticket is refused past 2048 characters',
	null === wpdc_prefill_ticket( ticket_for( array( 'v' => 1, 'cid' => 'cus_0Abc123', 'pad' => str_repeat( 'x', 2100 ), 'exp' => $soon ) ) )
);
check(
	'BELL: a site that names its own customer is not overridden by a ticket',
	( function () use ( $billing ): bool {
		configure();
		$GLOBALS['wpdc_test_filters']['wpdc_customer'] = array( 'email' => 'own@example.com' );
		catalogue( array( 'pdt_allowed' ) );
		respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_6', 'session_id' => 'cks_6' ) );
		wpdc_create_session( 'pdt_allowed', 1, null, 'de', null, wpdc_prefill_customer( 'kunde@example.com', 'K' ), array( 'cid' => 'cus_0Abc123', 'billing' => $billing ) );
		$sent = json_decode( last_request()['args']['body'], true );
		return array( 'email' => 'own@example.com' ) === ( $sent['customer'] ?? null ) && ! isset( $sent['billing_address'] );
	} )()
);

// ─── The checkout dressed as UnleashWP ───────────────────────────────────────

$themed = ticket_sent( null, null );
$tc     = $themed['customization']['theme_config'] ?? array();
check(
	'BELL: Dodo\'s form is dressed as UnleashWP: larger type, the 5px corner, navy radios, focus and pay button',
	'light' === ( $themed['customization']['theme'] ?? '' )
		// Owner: at 2xl the headings and the error line were far too large.
		&& 'md' === ( $tc['font_size'] ?? '' )
		&& 'medium' === ( $tc['font_weight'] ?? '' )
		&& '5px' === ( $tc['radius'] ?? '' )
		// Measured on Dodo's page: button_primary paints the radios and the focus
		// ring; the enabled pay button takes text_primary. Navy for all of them.
		&& '#203159' === ( $tc['light']['button_primary'] ?? '' )
		&& '#ffffff' === ( $tc['light']['button_text_primary'] ?? '' )
		&& '#203159' === ( $tc['light']['text_primary'] ?? '' )
		&& '#203159' === ( $tc['light']['input_focus_border'] ?? '' )
);
check(
	'BELL: the theme rides beside the language, it does not replace it',
	'de' === ( $themed['customization']['force_language'] ?? '' )
);
check(
	// Ubuntu from the account's own server (CORS open for /fonts/*). Dodo reads the
	// family from the URL's `family` parameter; without it the frame used Hanken Grotesk.
	'BELL: the frame is set in Ubuntu from our own server, never from Google',
	'https://account.unleash-wp.com/fonts/ubuntu.css?family=Ubuntu' === ( $tc['font_primary_url'] ?? '' )
		&& ( $tc['font_primary_url'] ?? '' ) === ( $tc['font_secondary_url'] ?? '' )
		&& ! str_contains( (string) json_encode( $themed ), 'googleapis' )
);
check(
	'BELL: a site can change or drop the theme with a filter',
	( function (): bool {
		$GLOBALS['wpdc_test_filters_keep'] = true;
		$sent = ( function (): array {
			configure();
			$GLOBALS['wpdc_test_filters']['wpdc_theme_config'] = array( 'font_size' => 'xl' );
			catalogue( array( 'pdt_allowed' ) );
			respond( 200, array( 'checkout_url' => 'https://checkout.dodopayments.com/session/cks_5', 'session_id' => 'cks_5' ) );
			wpdc_create_session( 'pdt_allowed', 1, null, 'de' );
			return json_decode( last_request()['args']['body'], true );
		} )();
		return array( 'font_size' => 'xl' ) === ( $sent['customization']['theme_config'] ?? null );
	} )()
);

check(
	'BELL: a configured font must be https and the shop\'s own, never Google',
	wpdc_is_font_url( 'https://www.unleash-wp.com/wp-content/uploads/2025/08/ubuntu-v20-latin-regular.woff2' )
		&& ! wpdc_is_font_url( 'http://www.unleash-wp.com/font.woff2' )
		&& ! wpdc_is_font_url( 'https://fonts.googleapis.com/css2?family=Ubuntu' )
		&& ! wpdc_is_font_url( 'https://fonts.gstatic.com/s/ubuntu/v20/x.woff2' )
		&& ! wpdc_is_font_url( 'javascript:alert(1)' )
);

check(
	// § 312j BGB: the button that closes a paid order must say so unmistakably.
	'BELL: the pay button says what it does, in the checkout\'s language',
	'Zahlungspflichtig bestellen' === ( ticket_sent( null, null )['customization']['theme_config']['pay_button_text'] ?? '' )
		&& 'Place order and pay' === ( wpdc_theme_config( 'en' )['pay_button_text'] ?? '' )
		&& ! isset( wpdc_theme_config( '' )['pay_button_text'] )
);

check(
	// Dodo's type size never reaches the card block, the provider's own frame;
	// zooming the frame does. Proven with real clicks in Chromium only, so only
	// Chromium gets it: navigator.userAgentData exists there and nowhere else.
	'BELL: only Chromium, and only the two-column window, zooms Dodo\'s frame',
	str_contains( $checkoutJs = (string) file_get_contents( $root . '/assets/checkout.js' ), "navigator.userAgentData.brands.some(function (b) { return /Chromium/.test(b.brand); })" )
		&& str_contains( $checkoutJs, "document.documentElement.classList.add('wpdc-zoom')" )
		&& 1 === preg_match( '/@media \(min-width: 901px\) \{\s*\.wpdc-zoom \.wpdc__dialog \.wpdc__frame \{\s*zoom: 1\.15;\s*\}/', $css = (string) file_get_contents( $root . '/assets/checkout.css' ) )
);

check(
	// WCAG 1.4.11: a field's edge needs 3:1 against white. #d7deec was 1.35:1 and the
	// owner could not see the fields; #8794b0 (3.05:1) then read too hard, so 10% toward white.
	'BELL: field borders are visible but softened, dividers a step lighter',
	'#939fb8' === ( wpdc_theme_config( 'de' )['light']['border_primary'] ?? '' )
		&& '#bfc8da' === ( wpdc_theme_config( 'de' )['light']['border_secondary'] ?? '' )
);

check(
	// Audit 2026-10-23: the key goes in a header, so a redirect must not carry it on.
	'BELL: the direct-link lookups follow no redirect and cap what they read',
	( static function (): bool {
		configure();
		respond( 200, array( 'artifacts' => array( 'easy-svg-pro.zip' ) ) );
		respond( 200, array( 'url' => 'https://downloads.example/f/easy-svg-pro.zip?sig=1' ) );
		$url  = wpdc_direct_link( 'https://downloads.example/', 'key_1' );
		$sent = $GLOBALS['wpdc_test_requests'];
		if ( 'https://downloads.example/f/easy-svg-pro.zip?sig=1' !== $url || 2 !== count( $sent ) ) {
			return false;
		}
		foreach ( $sent as $call ) {
			if ( 0 !== ( $call['args']['redirection'] ?? null )
				|| true !== ( $call['args']['sslverify'] ?? null )
				|| WPDC_LINK_RESPONSE_LIMIT !== ( $call['args']['limit_response_size'] ?? null ) ) {
				return false;
			}
		}
		return true;
	} )()
);

check(
	// Audit 2026-10-23: a harness reachable over HTTP would run for any visitor.
	'BELL: the harness refuses to run outside the command line, before anything else',
	1 === preg_match(
		"/^<\\?php\\s*\\/\\*\\*.*?\\*\\/\\s*declare\\(strict_types=1\\);\\s*(?:\\/\\/[^\\n]*\\n\\s*)*if \\( 'cli' !== PHP_SAPI \\) \\{\\s*exit\\( 1 \\);\\s*\\}/s",
		(string) file_get_contents( __FILE__ )
	)
);

check(
	// Audit 2026-10-23: a moved tag runs somebody else's code with our release token.
	'BELL: every workflow action is pinned to a full commit SHA, and the release runs the checks first',
	( static function () use ( $root ): bool {
		$release = (string) file_get_contents( $root . '/.github/workflows/release.yml' );
		$uses    = 0;
		foreach ( glob( $root . '/.github/workflows/*.yml' ) ?: array() as $file ) {
			foreach ( file( $file ) ?: array() as $line ) {
				if ( 1 === preg_match( '/^\s*-?\s*uses:\s*(\S+)(.*)$/', $line, $m ) ) {
					$uses++;
					if ( 1 !== preg_match( '/^[\w.-]+\/[\w.-]+@[0-9a-f]{40}$/', $m[1] ) || 1 !== preg_match( '/^\s+# v?\d+\.\d+\.\d+\s*$/', $m[2] ) ) {
						return false;
					}
				}
			}
		}
		$tests = strpos( $release, 'run: php tests/run.php' );
		$zip   = strpos( $release, '- name: Build the zip' );
		return $uses >= 4 && false !== $tests && false !== $zip && $tests < $zip;
	} )()
);

// Defined last, because a constant cannot be undefined for the tests above.
// The host matches the shop, so only the scheme check can refuse it.
define( 'WPDC_RETURN_URL', "javascript://shop.example/%0Aalert(document.domain)" );
check(
	'BELL: a return URL that is not http or https is refused even on the shop host',
	'' === wpdc_return_url()
);

// ─── The UnleashWP help widget ───────────────────────────────────────────────

$GLOBALS['wpdc_test_pll'] = 'de';
$widget                   = wpdc_help_widget_tag();
check(
	'BELL: every page loads the help widget from the help center, deferred, in the page language',
	'<script src="https://support.unleash-wp.com/_dai/api/scripts/file/scripts/uwp-help.js" data-lang="de" defer></script>' === $widget
);
$GLOBALS['wpdc_test_pll'] = 'en';
check( 'BELL: an English page gets the English widget', str_contains( wpdc_help_widget_tag(), 'data-lang="en"' ) );
$GLOBALS['wpdc_test_filters']['wpdc_help_widget'] = false;
check( 'SILENCE: the wpdc_help_widget filter switches it off', '' === wpdc_help_widget_tag() );
unset( $GLOBALS['wpdc_test_filters']['wpdc_help_widget'] );
$GLOBALS['wpdc_test_pll'] = '';

// ─── Ubuntu in our own UI, not only in Dodo's frame ─────────────────────────
// The theme registers its faces as "Ubuntu Regular" / "Ubuntu Bold", so a stack
// that asks for "Ubuntu" fell back to the system font in the panel and the modal.
check(
	'FONT: our own UI takes Ubuntu from the account, the same file Dodo gets',
	'https://account.unleash-wp.com/fonts/ubuntu.css?family=Ubuntu' === wpdc_account_font_css()
);
check( 'FONT: the sign-in modal is told where the font lives', wpdc_account_font_css() === ( wpdc_account_login_config()['font'] ?? '' ) );
check( 'FONT: the modal loader adds the font stylesheet', str_contains( WPDC_ACCOUNT_LOGIN_LOADER, 'f.href=c.font' ) );
$shortcode_src = (string) file_get_contents( $root . '/includes/shortcode.php' );
$enqueue_src   = substr( $shortcode_src, (int) strpos( $shortcode_src, 'function wpdc_enqueue(): void {' ), 600 );
check(
	'FONT: the checkout panel enqueues the font and its stylesheet depends on it',
	str_contains( $enqueue_src, "'wp-dodo-checkout-font',\n\t\twpdc_account_font_css()" )
		&& str_contains( $enqueue_src, "array( 'wp-dodo-checkout-font' )" )
);

// ─── 0.18.0: the phone checkout ──────────────────────────────────────────────
// Site popups (Depicter, the flipbook) sit above 99000 and opened over a running
// checkout. They step aside while body.wpdc-locked is set, i.e. while it is open.
check(
	'POPUPS: known site popups are hidden while the checkout is open',
	'body.wpdc-locked :is(.depicter-popup-container,.fb3d-modal){display:none!important}' === wpdc_hidden_while_open_css()
);
$GLOBALS['wpdc_test_filters']['wpdc_hide_while_checkout_open'] = array( '.my-popup', 'x}body{display:none', '#promo' );
check( 'POPUPS: the filter adds selectors and drops anything that could break out of the rule', 'body.wpdc-locked :is(.my-popup,#promo){display:none!important}' === wpdc_hidden_while_open_css() );
$GLOBALS['wpdc_test_filters']['wpdc_hide_while_checkout_open'] = array();
check( 'POPUPS: an empty list prints nothing', '' === wpdc_hidden_while_open_css() );
unset( $GLOBALS['wpdc_test_filters']['wpdc_hide_while_checkout_open'] );
$shortcode_src = (string) file_get_contents( $root . '/includes/shortcode.php' );
check( 'POPUPS: the rule is printed with the checkout stylesheet', str_contains( $shortcode_src, "wp_add_inline_style( 'wp-dodo-checkout', \$hide )" ) );
check( 'PHONE: the discount field folds behind a toggle', str_contains( $shortcode_src, 'class="wpdc__discount-toggle" aria-expanded="false"' ) );
$css_src = (string) file_get_contents( $root . '/assets/checkout.css' );
$phone   = substr( $css_src, (int) strpos( $css_src, '@media (max-width: 600px)' ) );
check(
	'PHONE: the checkout is a full-screen sheet; only the slim bar stays, the summary scrolls',
	str_contains( $css_src, '@media (max-width: 600px)' ) && str_contains( $phone, 'height: 100dvh' )
		&& str_contains( $phone, 'grid-template-rows: auto minmax(0, 1fr)' ) && ! str_contains( $phone, 'position: sticky' )
);
// 0.18.1 "Ruhige Kasse": a white bar with the lock, a summary with room and two
// true promises beside the price, and full-width rows instead of small links.
check( 'CALM: a slim secure-checkout bar heads the sheet', str_contains( $shortcode_src, '<div class="wpdc__bar">' ) && str_contains( $shortcode_src, "esc_html__( 'Secure checkout', 'wp-dodo-checkout' )" ) );
check(
	'CALM: the summary carries two promises that hold for every product',
	str_contains( $shortcode_src, '<ul class="wpdc__trust">' )
		&& str_contains( $shortcode_src, "esc_html__( 'In your account right after payment', 'wp-dodo-checkout' )" )
		&& str_contains( $shortcode_src, "esc_html__( 'Paid securely via Dodo Payments', 'wp-dodo-checkout' )" )
);
check( 'CALM: the discount toggle says what it does', str_contains( $shortcode_src, "esc_html__( 'Add discount code', 'wp-dodo-checkout' )" ) );
check( 'CALM: on a phone the summary is light, not the navy strip', str_contains( $phone, '--wpdc-panel-bg: #ffffff' ) );
check(
	'CALM: the brand yellow stays: a stripe over the bar and a marker under the price',
	str_contains( $phone, 'border-top: 3px solid #fcbe00' ) && str_contains( $phone, 'linear-gradient(transparent 62%, #fcbe00 62%)' )
);
check( 'CALM: bar and promises stay off the desktop', 1 === preg_match( '/^\.wpdc__bar,\n\.wpdc__trust \{\n  display: none;/m', $css_src ) );
check(
	'PHONE: the toggle opens the folded discount field, and the CSS listens for the same class',
	str_contains( (string) file_get_contents( $root . '/assets/checkout.js' ), "panel.classList.toggle('is-discount-open', open)" )
		&& str_contains( $phone, '.wpdc__panel:not(.is-discount-open)' )
);

// ─── Press access (#44) ─────────────────────────────────────────────────────
//
// Licence keys without a purchase. What must hold: nobody without the right,
// the nonce or an allowed product creates anything; the full key is shown once
// and stored nowhere; Dodo receives exactly the body the dashboard would make.

define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['wpdc_test_actions'] = array();
function do_action( string $hook, ...$args ): void {
	$GLOBALS['wpdc_test_actions'][] = array( $hook, $args );
}
function update_option( string $name, $value, $autoload = null ): bool {
	$GLOBALS['wpdc_test_options'][ $name ] = $value;
	return true;
}
function get_current_user_id(): int {
	return 7;
}
function get_userdata( $id ) {
	return 7 === (int) $id ? (object) array( 'display_name' => 'Owner' ) : false;
}
function sanitize_email( $value ): string {
	return trim( (string) $value );
}
function sanitize_textarea_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}
function wp_unslash( $value ) {
	return $value;
}
function wp_nonce_field( string $action ): void {
	echo '<input type="hidden" name="_wpnonce" value="good" />';
}
function admin_url( string $path = '' ): string {
	return 'https://shop.example/wp-admin/' . $path;
}
function add_submenu_page( ...$args ) {
	$GLOBALS['wpdc_test_submenu'] = $args;
	return 'hook';
}
function wp_date( string $format, int $ts ): string {
	return gmdate( $format, $ts );
}
function _n( string $single, string $plural, int $n, string $domain = '' ): string {
	return 1 === $n ? $single : $plural;
}
function esc_textarea( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

require_once $root . '/includes/press.php';

const PRESS_PDT = 'pdt_press1';

function press_reset(): void {
	configure();
	$GLOBALS['wpdc_test_actions'] = array();
	$GLOBALS['wpdc_test_caps']    = null;
	$GLOBALS['wpdc_test_filters']['wpdc_press_products'] = array( PRESS_PDT );
	// A cached catalogue, so the "all products" default asks Dodo nothing here.
	set_transient( wpdc_catalog_key(), array( PRESS_PDT => array( 'name' => 'Press product', 'image' => '', 'description' => '', 'price' => null, 'currency' => '' ) ), HOUR_IN_SECONDS );
	unset( $GLOBALS['wpdc_test_options']['wpdc_press_keys'] );
}

/** A complete, valid create form as the browser posts it. */
function press_form( array $over = array() ): array {
	return array_merge(
		array(
			'wpdc_press'      => 'create',
			'_wpnonce'        => 'good',
			'wpdc_press_once' => bin2hex( random_bytes( 8 ) ),
			'name'            => 'Kim Reporter',
			'email'           => 'kim@press.example',
			'product'         => PRESS_PDT,
			'limit'           => '',
			'reason'          => 'Review in c\'t',
		),
		$over
	);
}

/** Customer lookup finds nobody, creation answers, the key is created. */
function press_script_success( string $customer = 'cus_new' ): void {
	respond( 200, array( 'items' => array() ) );
	respond( 200, array( 'customer_id' => $customer, 'email' => 'kim@press.example', 'name' => 'Kim Reporter' ) );
	respond(
		200,
		array(
			'id'          => 'lic_abc123',
			'key'         => 'ECHOED-BY-DODO',
			'status'      => 'active',
			'source'      => 'import',
			'customer_id' => $customer,
			'product_id'  => PRESS_PDT,
		)
	);
}

function press_license_post(): ?array {
	foreach ( $GLOBALS['wpdc_test_requests'] as $r ) {
		if ( str_ends_with( $r['url'], '/license_keys' ) && 'POST' === $r['args']['method'] ) {
			return $r;
		}
	}
	return null;
}

// The key format.
$generated = wpdc_press_generate_key();
check( 'PRESS: a generated key is 8 groups of 4 uppercase hex', 1 === preg_match( '/^[0-9A-F]{4}(-[0-9A-F]{4}){7}$/', $generated ) );
check( 'PRESS: two generated keys differ', wpdc_press_generate_key() !== $generated );
$GLOBALS['wpdc_test_filters']['wpdc_press_generate_key'] = 'MY-OWN-KEY-0001';
check( 'PRESS: wpdc_press_generate_key can replace the key', 'MY-OWN-KEY-0001' === wpdc_press_generate_key() );
$GLOBALS['wpdc_test_filters']['wpdc_press_generate_key'] = '';
check( 'PRESS: an empty filtered key falls back to a random one', 1 === preg_match( '/^[0-9A-F]{4}(-[0-9A-F]{4}){7}$/', wpdc_press_generate_key() ) );
unset( $GLOBALS['wpdc_test_filters']['wpdc_press_generate_key'] );

// The product allowlist.
press_reset();
unset( $GLOBALS['wpdc_test_filters']['wpdc_press_products'] );
delete_transient( wpdc_catalog_key() );
catalogue( array( 'pdt_a', 'pdt_b' ) );
check( 'PRESS: no WPDC_PRESS_PRODUCTS means every product of the account', array( 'pdt_a', 'pdt_b' ) === wpdc_press_products() && wpdc_press_enabled() );
delete_transient( wpdc_catalog_key() );
respond( 503, array() );
check( 'SILENCE: a catalogue Dodo cannot list leaves nothing to give away', array() === wpdc_press_products() );
set_transient( wpdc_catalog_key(), array( PRESS_PDT => array( 'name' => 'Press product', 'image' => '', 'description' => '', 'price' => null, 'currency' => '' ) ), HOUR_IN_SECONDS );
$GLOBALS['wpdc_test_filters']['wpdc_press_products'] = array( PRESS_PDT, 'not-an-id', 'pdt_two' );
check( 'PRESS: wpdc_press_products adds ids and drops what is not one', array( PRESS_PDT, 'pdt_two' ) === wpdc_press_products() );
wpdc_register_press_page();
check( 'PRESS: the page sits under Settings when enabled', 'options-general.php' === ( $GLOBALS['wpdc_test_submenu'][0] ?? '' ) );
$GLOBALS['wpdc_test_submenu']                        = null;
$GLOBALS['wpdc_test_filters']['wpdc_press_products'] = array();
wpdc_register_press_page();
check( 'PRESS: no page at all when the list is empty', null === $GLOBALS['wpdc_test_submenu'] );

press_reset();
$res = wpdc_press_handle( press_form( array( 'product' => 'pdt_notallowed' ) ) );
check( 'PRESS: a product outside the list creates nothing', false === $res['ok'] && 'product_not_allowed' === $res['reason'] && array() === $GLOBALS['wpdc_test_requests'] );

// Capability and nonce.
press_reset();
$GLOBALS['wpdc_test_caps'] = array( 'edit_posts' );
$res                       = wpdc_press_handle( press_form() );
check( 'PRESS: without manage_options nothing is created', false === $res['ok'] && 'forbidden' === $res['reason'] && array() === $GLOBALS['wpdc_test_requests'] );
$GLOBALS['wpdc_test_filters']['wpdc_press_capability'] = 'edit_posts';
press_script_success();
$res = wpdc_press_handle( press_form() );
check( 'PRESS: wpdc_press_capability moves the right', true === $res['ok'] );

press_reset();
$res = wpdc_press_handle( press_form( array( '_wpnonce' => 'forged' ) ) );
check( 'PRESS: a bad nonce creates nothing', false === $res['ok'] && 'bad_nonce' === $res['reason'] && array() === $GLOBALS['wpdc_test_requests'] );
$form = press_form();
unset( $form['_wpnonce'] );
$res = wpdc_press_handle( $form );
check( 'PRESS: a missing nonce creates nothing', false === $res['ok'] && 'bad_nonce' === $res['reason'] && array() === $GLOBALS['wpdc_test_requests'] );

// Input validation.
press_reset();
$res = wpdc_press_handle( press_form( array( 'email' => 'nope' ) ) );
check( 'PRESS: an invalid email creates nothing', false === $res['ok'] && 'invalid_input' === $res['reason'] && array() === $GLOBALS['wpdc_test_requests'] );
$res = wpdc_press_handle( press_form( array( 'limit' => '-3' ) ) );
check( 'PRESS: a negative site limit creates nothing', false === $res['ok'] && 'invalid_input' === $res['reason'] );

// The request body to Dodo.
press_reset();
press_script_success();
$res  = wpdc_press_handle( press_form() );
$post = press_license_post();
$body = $post ? json_decode( $post['args']['body'], true ) : array();
check( 'PRESS: a lifetime key is created', true === $res['ok'] && 'lic_abc123' === $res['record']['id'] );
check(
	'PRESS: exactly these keys go to POST /license_keys',
	array( 'customer_id', 'product_id', 'key', 'activations_limit', 'expires_at' ) === array_keys( $body )
);
check(
	'PRESS: lifetime and no limit are sent as null, like a key made in the dashboard',
	'cus_new' === $body['customer_id'] && PRESS_PDT === $body['product_id'] && null === $body['activations_limit'] && null === $body['expires_at']
);
check( 'PRESS: the key sent is the key shown', $res['key'] === $body['key'] && 1 === preg_match( '/^[0-9A-F]{4}(-[0-9A-F]{4}){7}$/', $body['key'] ) );
check(
	'PRESS: the customer is looked up by email first, then created',
	str_contains( $GLOBALS['wpdc_test_requests'][0]['url'], '/customers?email=kim%40press.example' )
		&& 'GET' === $GLOBALS['wpdc_test_requests'][0]['args']['method']
		&& str_ends_with( $GLOBALS['wpdc_test_requests'][1]['url'], '/customers' )
		&& array( 'email' => 'kim@press.example', 'name' => 'Kim Reporter' ) === json_decode( $GLOBALS['wpdc_test_requests'][1]['args']['body'], true )
);
check( 'PRESS: test mode talks to the test host', str_starts_with( $post['url'] ?? '', 'https://test.dodopayments.com/' ) );

press_reset();
respond( 200, array( 'items' => array( array( 'customer_id' => 'cus_old', 'email' => 'KIM@press.example', 'name' => 'Kim' ) ) ) );
respond( 200, array( 'id' => 'lic_two', 'status' => 'active' ) );
$res  = wpdc_press_handle( press_form( array( 'limit' => '3', 'duration' => '1y', 'expires_on' => '2099-01-01' ) ) );
$body = json_decode( press_license_post()['args']['body'] ?? '{}', true );
check( 'PRESS: an existing customer is reused, not created twice', true === $res['ok'] && 'cus_old' === $body['customer_id'] && 2 === count( $GLOBALS['wpdc_test_requests'] ) );
check( 'PRESS: the site limit travels as a number', 3 === $body['activations_limit'] );
check( 'PRESS: a posted duration or date is ignored: the key never expires', array_key_exists( 'expires_at', $body ) && null === $body['expires_at'] );

press_reset();
$GLOBALS['wpdc_test_filters']['wpdc_press_key_args'] = array( 'customer_id' => 'cus_new', 'product_id' => PRESS_PDT, 'key' => 'K-FILTERED', 'activations_limit' => null, 'expires_at' => '2030-01-01T00:00:00Z' );
press_script_success();
$res  = wpdc_press_handle( press_form() );
$body = json_decode( press_license_post()['args']['body'] ?? '{}', true );
check( 'PRESS: a filter setting expires_at is stripped back to null', true === $res['ok'] && array_key_exists( 'expires_at', $body ) && null === $body['expires_at'] );
unset( $GLOBALS['wpdc_test_filters']['wpdc_press_key_args'] );
check( 'PRESS: there is no duration filter any more', ! function_exists( 'wpdc_press_durations' ) && ! str_contains( (string) file_get_contents( $root . '/includes/press.php' ), 'wpdc_press_durations' ) );

// Product names from Dodo, cached, the id when Dodo cannot say.
press_reset();
respond( 200, array( 'product_id' => PRESS_PDT, 'name' => 'Easy SVG Pro' ) );
$name1 = wpdc_press_product_name( PRESS_PDT );
$name2 = wpdc_press_product_name( PRESS_PDT );
check(
	'PRESS: the product name comes from GET /products/{id}, once',
	'Easy SVG Pro' === $name1 && 'Easy SVG Pro' === $name2 && 1 === count( $GLOBALS['wpdc_test_requests'] )
		&& str_ends_with( $GLOBALS['wpdc_test_requests'][0]['url'], '/products/' . PRESS_PDT ) && 'GET' === $GLOBALS['wpdc_test_requests'][0]['args']['method']
);
press_reset();
respond( 503, array() );
check( 'PRESS: a failed lookup shows the id', PRESS_PDT === wpdc_press_product_name( PRESS_PDT ) );
check( 'PRESS: and a failure is not cached', array() === $GLOBALS['wpdc_test_transients'] || ! in_array( PRESS_PDT, $GLOBALS['wpdc_test_transients'], true ) );

press_reset();
$GLOBALS['wpdc_test_filters']['wpdc_press_key_args'] = array( 'customer_id' => 'cus_x', 'product_id' => 'pdt_sneaky', 'key' => 'K', 'activations_limit' => null, 'expires_at' => null );
press_script_success();
$res = wpdc_press_handle( press_form() );
check( 'PRESS: wpdc_press_key_args cannot swap in a product outside the list', false === $res['ok'] && null === press_license_post() );
press_reset();
$GLOBALS['wpdc_test_filters']['wpdc_press_key_args'] = array( 'customer_id' => 'cus_new', 'product_id' => PRESS_PDT, 'key' => 'FILTERED-KEY', 'activations_limit' => 5, 'expires_at' => null );
press_script_success();
$res  = wpdc_press_handle( press_form() );
$body = json_decode( press_license_post()['args']['body'] ?? '{}', true );
check( 'PRESS: wpdc_press_key_args shapes the body before the call', 5 === $body['activations_limit'] && 'FILTERED-KEY' === $res['key'] );
unset( $GLOBALS['wpdc_test_filters']['wpdc_press_key_args'] );

// What is stored, and the created action.
press_reset();
press_script_success();
$res    = wpdc_press_handle( press_form() );
$stored = $GLOBALS['wpdc_test_options']['wpdc_press_keys'] ?? array();
$record = $stored['lic_abc123'] ?? array();
check(
	'PRESS: the record holds exactly id, last four, recipient, product, reason, date, author and mode',
	array( 'id', 'last4', 'name', 'email', 'product', 'reason', 'created_at', 'created_by', 'mode' ) === array_keys( $record )
);
check( 'PRESS: the record keeps the last four characters', substr( $res['key'], -4 ) === $record['last4'] && 7 === $record['created_by'] );
$everything = json_encode( array( $GLOBALS['wpdc_test_options'], $GLOBALS['wpdc_test_transients'] ) );
check( 'PRESS: the full key is nowhere in options or transients', ! str_contains( (string) $everything, $res['key'] ) && ! str_contains( (string) $everything, str_replace( '-', '', $res['key'] ) ) );
check( 'PRESS: what Dodo echoes back is not stored either', ! str_contains( (string) $everything, 'ECHOED-BY-DODO' ) );
$created = array_values( array_filter( $GLOBALS['wpdc_test_actions'], static fn( $a ) => 'wpdc_press_key_created' === $a[0] ) );
check( 'PRESS: wpdc_press_key_created fires once with the record and the key', 1 === count( $created ) && $record === $created[0][1][0] && $res['key'] === $created[0][1][1] );

press_reset();
respond( 200, array( 'items' => array() ) );
respond( 200, array( 'customer_id' => 'cus_new' ) );
respond( 422, array( 'message' => 'nope' ) );
$res = wpdc_press_handle( press_form() );
check( 'PRESS: a refused key stores nothing and fires nothing', false === $res['ok'] && ! isset( $GLOBALS['wpdc_test_options']['wpdc_press_keys'] ) && array() === $GLOBALS['wpdc_test_actions'] );

// One submission, one key.
press_reset();
press_script_success();
$form = press_form();
wpdc_press_handle( $form );
$before = count( $GLOBALS['wpdc_test_requests'] );
$again  = wpdc_press_handle( $form );
check( 'PRESS: reposting the same form creates no second key', false === $again['ok'] && 'already_done' === $again['reason'] && count( $GLOBALS['wpdc_test_requests'] ) === $before );

// The one-time display.
press_reset();
press_script_success();
respond( 200, array( 'product_id' => PRESS_PDT, 'name' => 'Easy SVG Pro' ) );
respond( 200, array( 'id' => 'lic_abc123', 'status' => 'active', 'key' => 'ECHOED-BY-DODO', 'instances_count' => 0, 'activations_limit' => null ) );
$_POST = press_form();
ob_start();
wpdc_render_press_page();
$first = (string) ob_get_clean();
$_POST = array();
respond( 200, array( 'id' => 'lic_abc123', 'status' => 'active', 'key' => 'ECHOED-BY-DODO', 'instances_count' => 0 ) );
ob_start();
wpdc_render_press_page();
$second = (string) ob_get_clean();
preg_match( '/id="wpdc-press-key"[^>]*value="([^"]+)"/', $first, $shown );
$full = $shown[1] ?? '';
check( 'PRESS: the page shows the full key after creating it', 1 === preg_match( '/^[0-9A-F]{4}(-[0-9A-F]{4}){7}$/', $full ) );
check( 'PRESS: the full key appears once in that page', '' !== $full && 1 === substr_count( $first, $full ) );
check( 'PRESS: the next visit shows only the last four', '' !== $full && ! str_contains( $second, $full ) && str_contains( $second, substr( $full, -4 ) ) );
check( 'PRESS: the list shows Dodo\'s status but never Dodo\'s copy of the key', str_contains( $second, 'Active' ) && ! str_contains( $first . $second, 'ECHOED-BY-DODO' ) );
check(
	'PRESS: the dropdown and the list show the product name, the id only beside it',
	str_contains( $second, '>Easy SVG Pro (' . PRESS_PDT . ')</option>' ) && str_contains( $second, '<td>Easy SVG Pro<br><code>' . PRESS_PDT . '</code></td>' )
);
check(
	'PRESS: the status cell says the status and the site limit, nothing else',
	str_contains( $second, 'Active<br><span class="description">Websites: unlimited</span>' ) && ! str_contains( $second, 'lifetime' ) && ! str_contains( $second, 'sites,' )
);
check( 'PRESS: no duration or date field is left in the form', ! str_contains( $second, 'name="duration"' ) && ! str_contains( $second, 'name="expires_on"' ) );
check( 'PRESS: every form on the page carries a nonce', substr_count( $second, '<form' ) === substr_count( $second, 'name="_wpnonce"' ) && substr_count( $second, '<form' ) >= 2 );

press_reset();
$GLOBALS['wpdc_test_caps'] = array( 'edit_posts' );
ob_start();
wpdc_render_press_page();
check( 'PRESS: the page renders nothing without the right', '' === trim( (string) ob_get_clean() ) );

press_reset();
$GLOBALS['wpdc_test_options']['wpdc_press_keys'] = array(
	'lic_x' => array( 'id' => 'lic_x', 'last4' => 'ABCD', 'name' => '<script>x</script>', 'email' => 'a@b.c', 'product' => PRESS_PDT, 'reason' => '"><img src=x>', 'created_at' => time(), 'created_by' => 7, 'mode' => 'test_mode' ),
);
respond( 200, array( 'name' => 'Easy SVG Pro' ) );
respond( 200, array( 'id' => 'lic_x', 'status' => 'disabled', 'activations_limit' => 3 ) );
ob_start();
wpdc_render_press_page();
$html = (string) ob_get_clean();
check( 'PRESS: a limit reads as Websites: N', str_contains( $html, 'Disabled<br><span class="description">Websites: 3</span>' ) );
check( 'PRESS: names and reasons are escaped', ! str_contains( $html, '<script>x' ) && ! str_contains( $html, '"><img' ) && str_contains( $html, 'Disabled' ) );

// Disable and enable.
press_reset();
$GLOBALS['wpdc_test_options']['wpdc_press_keys'] = array(
	'lic_x' => array( 'id' => 'lic_x', 'last4' => 'ABCD', 'name' => 'Kim', 'email' => 'a@b.c', 'product' => PRESS_PDT, 'reason' => 'r', 'created_at' => time(), 'created_by' => 7, 'mode' => 'test_mode' ),
);
respond( 200, array( 'id' => 'lic_x', 'status' => 'disabled' ) );
$res = wpdc_press_handle( array( 'wpdc_press' => 'disable', 'id' => 'lic_x', '_wpnonce' => 'good' ) );
$req = last_request();
check(
	'PRESS: disabling sends PATCH /license_keys/{id} with disabled true and nothing else',
	true === $res['ok'] && 'PATCH' === $req['args']['method'] && str_ends_with( $req['url'], '/license_keys/lic_x' )
		&& array( 'disabled' => true ) === json_decode( $req['args']['body'], true )
);
$revoked = array_values( array_filter( $GLOBALS['wpdc_test_actions'], static fn( $a ) => 'wpdc_press_key_revoked' === $a[0] ) );
check( 'PRESS: wpdc_press_key_revoked fires with the record', 1 === count( $revoked ) && 'lic_x' === $revoked[0][1][0]['id'] );

$GLOBALS['wpdc_test_actions'] = array();
respond( 200, array( 'id' => 'lic_x', 'status' => 'active' ) );
$res = wpdc_press_handle( array( 'wpdc_press' => 'enable', 'id' => 'lic_x', '_wpnonce' => 'good' ) );
check(
	'PRESS: enabling sends disabled false, and is not a revocation',
	true === $res['ok'] && array( 'disabled' => false ) === json_decode( last_request()['args']['body'], true ) && array() === $GLOBALS['wpdc_test_actions']
);

$GLOBALS['wpdc_test_requests'] = array();
$res = wpdc_press_handle( array( 'wpdc_press' => 'disable', 'id' => 'lic_someoneElse', '_wpnonce' => 'good' ) );
check( 'PRESS: a key this page did not issue cannot be touched', false === $res['ok'] && array() === $GLOBALS['wpdc_test_requests'] );
$res = wpdc_press_handle( array( 'wpdc_press' => 'disable', 'id' => 'lic_x', '_wpnonce' => 'forged' ) );
check( 'PRESS: disabling needs the nonce', false === $res['ok'] && 'bad_nonce' === $res['reason'] && array() === $GLOBALS['wpdc_test_requests'] );
$GLOBALS['wpdc_test_caps'] = array();
$res = wpdc_press_handle( array( 'wpdc_press' => 'disable', 'id' => 'lic_x', '_wpnonce' => 'good' ) );
check( 'PRESS: disabling needs the right', false === $res['ok'] && 'forbidden' === $res['reason'] && array() === $GLOBALS['wpdc_test_requests'] );

// Source-level promises.
$press_src = source( $root . '/includes/press.php' );
check( 'PRESS: nothing in press.php writes to a log', ! str_contains( $press_src, 'error_log' ) );
check( 'PRESS: no mail is sent', ! str_contains( $press_src, 'wp_mail' ) );
preg_match_all( "/(?:esc_html__|esc_attr__|__)\(\s*'([^']+)'/", $press_src, $press_strings );
preg_match_all( "/_n\(\s*'([^']+)',\s*'([^']+)'/", $press_src, $press_plurals );
check( 'PRESS: counted strings use _n', count( $press_plurals[1] ) >= 2 );
$press_strings = array_values( array_unique( array_merge( $press_strings[1], $press_plurals[1], $press_plurals[2] ) ) );
$press_missing = array_values( array_filter( $press_strings, static fn( $s ) => 1 !== preg_match( '/^msgid(?:_plural)? "' . preg_quote( str_replace( '"', '\\"', $s ), '/' ) . '"$/m', $po ) ) );
check( 'PRESS: there are UI strings, so the checks below are not vacuous', count( $press_strings ) > 10 );
check( 'PRESS: every press string is in the German catalogue' . ( $press_missing ? ' [' . implode( ' | ', array_slice( $press_missing, 0, 3 ) ) . ']' : '' ), array() === $press_missing );
$dashed = array_filter( $press_strings, static fn( $s ) => str_contains( $s, "\u{2013}" ) || str_contains( $s, "\u{2014}" ) );
check( 'PRESS: no en or em dash in a press string', array() === $dashed );
$po_press = '';
foreach ( $press_strings as $s ) {
	if ( preg_match( '/msgid "' . preg_quote( str_replace( '"', '\\"', $s ), '/' ) . '"\n(?:msgid_plural "[^\n]*"\n)?((?:msgstr(?:\[\d\])? "[^\n]*"\n?)+)/', $po, $m ) ) {
		$po_press .= $m[1];
	}
}
check( 'PRESS: no en or em dash in their German either', '' !== $po_press && ! str_contains( $po_press, "\u{2013}" ) && ! str_contains( $po_press, "\u{2014}" ) );
check( 'PRESS: uninstall removes the records with their names and emails', str_contains( source( $root . '/uninstall.php' ), "delete_option( 'wpdc_press_keys' )" ) );

if ( $failures ) {
	echo count( $failures ) . " of $checks checks FAILED\n";
	foreach ( $failures as $name ) {
		echo "  - $name\n";
	}
	exit( 1 );
}

echo "all $checks checks passed\n";

