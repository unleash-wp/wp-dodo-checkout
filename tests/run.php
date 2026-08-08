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
// Defined by the plugin's main file, which these tests deliberately do not load
// -- so the harness stands in for it, exactly as WordPress would. Leaving it out
// made config.php fatal on a constant that is always present in production.
define( 'WPDC_VERSION', '0.1.0' );

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
function get_transient( string $k ) {
	return $GLOBALS['wpdc_test_transients'][ $k ] ?? false;
}
function set_transient( string $k, $v, $t = 0 ): bool {
	$GLOBALS['wpdc_test_transients'][ $k ] = $v;
	return true;
}
function get_locale(): string {
	return $GLOBALS['wpdc_test_locale'] ?? 'de_DE';
}
function determine_locale(): string {
	return get_locale();
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
	$GLOBALS['wpdc_test_options']    = array( 'wpdc_api_key' => 'sk_test_key' );
	$GLOBALS['wpdc_test_transients'] = array();
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
respond( 200, array( 'checkout_url' => 'https://checkout.example/session/cks_1' ) );

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
	array( 'product_cart', 'minimal_address', 'show_saved_payment_methods', 'feature_flags' ) === array_keys( $sent )
);
check(
	// The only field reduction that improves the more somebody buys.
	'BELL: a returning customer is offered the card they already used',
	true === $sent['show_saved_payment_methods']
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
respond( 200, array( 'checkout_url' => 'https://checkout.example/session/cks_2' ) );
wpdc_create_session( 'pdt_pro' );
$firstCount = count( $GLOBALS['wpdc_test_requests'] );

respond( 200, array( 'checkout_url' => 'https://checkout.example/session/cks_3' ) );
wpdc_create_session( 'pdt_pro' );
check(
	'BELL: the second sale costs one call, not another catalogue read',
	count( $GLOBALS['wpdc_test_requests'] ) === $firstCount + 1
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
		&& str_contains( $js, "root.querySelector('.wpdc__frame')" )
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
	'BELL: only a server-written sentence is shown to the visitor',
	str_contains( $js, 'uwpFromServer' ) && ! str_contains( $js, 'say(root, err.message' )
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
		if ( ! preg_match( "/add_shortcode\\(\\s*'([a-z0-9_]+)'/", $code, $m ) ) {
			return false;
		}
		$tag = $m[1];
		preg_match_all( "/esc_html__\\(\\s*'([a-z0-9_]+):/", $code, $named );
		if ( array() === $named[1] ) {
			return false; // a notice that names no tag is not proof of anything
		}
		foreach ( $named[1] as $mentioned ) {
			if ( $mentioned !== $tag ) {
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
	'BELL: the REST route verifies a nonce',
	str_contains( $rest, 'wp_verify_nonce' )
);
check(
	// Both args, counted rather than merely present: with one shared check
	// string, dropping it from `product` and leaving it on `bump` looked
	// identical -- which a mutation showed.
	'BELL: the REST route validates BOTH ids before they leave the site',
	2 === substr_count( $rest, "'validate_callback' => 'wpdc_is_product_id'" )
);
check(
	'BELL: the request is same-origin, so the nonce cookie is actually sent',
	str_contains( $js, "credentials: 'same-origin'" )
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

/** Every class checkout.js hunts, from closest() and querySelector(). */
$hunted = ( static function ( string $js ): array {
	preg_match_all( "/\\.(?:closest|querySelector(?:All)?)\\(\\s*'\\.([\\w-]+)'/", $js, $m );
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
	'BELL: and it renders the stored option, never the resolved key',
	str_contains( $settings, '$from_constant ? \'\' : esc_attr( $stored )' )
		&& ! str_contains( $settings, 'esc_attr( wpdc_api_key() )' )
);
check(
	// This replaced a rule about hiding the button. The button used to be hidden
	// while the checkout was open, so a second click could not start a second
	// session -- and a CSS `display` outranking [hidden] quietly defeated it.
	// A modal makes the same guarantee structurally: nothing behind it is
	// clickable, in every browser, with no rule to defeat.
	'BELL: the checkout is modal, so a second click cannot reach the button',
	str_contains( $js, 'dialog.showModal()' ) && ! str_contains( $js, 'button.hidden = true' )
);
check(
	// The harness defines WPDC_VERSION on the main file's behalf. If the two drift
	// the cache key under test is not the cache key that ships.
	'BELL: the version the tests stand in for is the version the plugin declares',
	1 === preg_match( "/define\( 'WPDC_VERSION', '([^']+)' \);/", source( $root . '/wp-dodo-checkout.php' ), $m )
		&& WPDC_VERSION === $m[1]
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
	// Dodo's payment step brings no top spacing of its own, so its first element
	// sat flush against the modal edge and under the close button, which is
	// absolutely positioned in that corner. Their contact step does bring
	// spacing, so one screen looked right and the next did not.
	'BELL: the frame keeps the checkout clear of the edge and the close button',
	str_contains( $css, 'padding: 2.5rem 0 1rem' )
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
} )( $shortcode, $client );

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
respond( 200, array( 'checkout_url' => 'https://checkout.example/session/cks_l' ) );
wpdc_create_session( 'pdt_pro', 1, null, 'de' );
$withLang = json_decode( last_request()['args']['body'], true );
check(
	'BELL: a language from the caller reaches Dodo',
	'de' === ( $withLang['customization']['force_language'] ?? '' )
);
check(
	'BELL: and no language means no customization block at all',
	! isset( $sent['customization'] )
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
	'BELL: on a narrow screen the dialog scrolls, and its parts do not clip',
	1 === preg_match( '/@media \(max-width: 900px\)[\s\S]{0,900}overflow-y: auto/', $css )
		&& 1 === preg_match( '/@media \(max-width: 900px\)[\s\S]{0,900}overflow: visible/', $css )
);

check(
	// `dialog.close()` fires `close` SYNCHRONOUSLY, and the listener for it calls
	// closeFrame again. With the marker cleared at the END, that second entry
	// found openRoot still set and ran the whole body twice -- Checkout.close()
	// included, on an SDK already told once. Claiming it first makes re-entry a
	// no-op.
	'BELL: closing claims the open marker before it closes anything',
	1 === preg_match( '/function closeFrame[\s\S]{0,600}openRoot = null;[\s\S]{0,400}dialog\.close\(\)/', $js )
		// Two occurrences: the declaration at the top and the one claim inside
		// closeFrame. A third would mean the marker is cleared somewhere else
		// too, which is how re-entrancy came back.
		&& 2 === substr_count( $js, 'openRoot = null;' )
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
	'BELL: clicking beside the checkout closes it',
	str_contains( $js, "event.target.tagName === 'DIALOG'" )
		&& str_contains( $js, "classList.contains('wpdc__dialog')" )
);
check(
	// On a 375px screen a 1rem margin spends room the form needs, and a card
	// whose edges nobody can see does not need rounded corners.
	// NOT edge to edge, on the operator's word: a margin down both sides and a
	// strip of backdrop say "a layer over the shop", where full bleed reads as a
	// new page and somebody who thinks they navigated away behaves differently
	// about closing it.
	'BELL: on a phone the page still shows around the checkout',
	1 === preg_match( '/@media \(max-width: 900px\)[\s\S]{0,1400}width: min\(30rem/', $css )
		&& ! preg_match( '/width: 100vw/', $css )
);

check(
	// A shop can sell a German edition and an English one from the same site.
	// The site locale is one answer to a question that has two, and it describes
	// the SHOP rather than the edition being sold -- so the shortcode decides,
	// and the locale is only the fallback.
	'BELL: the shortcode can name the language, and the browser is the fallback',
	str_contains( $shortcode, "'lang'       => ''" )
		&& str_contains( $js, "root.dataset.lang || (navigator.language" )
		&& str_contains( $rest, "'lang'     => array(" )
		&& str_contains( $client, 'preg_match( \'/^[a-z]{2}$/i\', $lang )' )
);
check(
	// Written by the shop owner, never by this plugin. A refund window is a
	// promise only the seller can make, and a reassuring sentence invented on
	// somebody's checkout is a commitment they never gave.
	//
	// Checked against the RENDERED strings, not the file: the file explains the
	// rule in a comment, and grepping the comment for the words it forbids was
	// the seventh time today a check read prose as behaviour.
	'BELL: trust lines come from the shortcode, and none are invented here',
	str_contains( $shortcode, "'trust'      => ''" )
		&& ( static function ( string $php ): bool {
			preg_match_all( "/(?:esc_html__|esc_attr__|__)\(\s*'([^']+)'/", $php, $m );
			foreach ( $m[1] as $string ) {
				if ( preg_match( '/(money.?back|refund|guarantee|[0-9]+ days?)/i', $string ) ) {
					return false;
				}
			}
			return true;
		} )( $shortcode )
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
	// Full bleed reads as a new page, and a customer who thinks they navigated
	// away behaves differently about closing it.
	'BELL: on a phone the page still shows around the checkout',
	1 === preg_match( '/@media \(max-width: 900px\)[\s\S]{0,1200}width: min\(30rem, calc\(100vw - 2\.5rem\)\)/', $css )
		&& ! preg_match( '/@media \(max-width: 900px\)[\s\S]{0,1200}width: 100vw/', $css )
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
		&& 4 === substr_count( $js, 'settleLoading' )
		&& 1 === preg_match( '/loadTimer = setTimeout[\s\S]{0,400}window\.location\.assign\(url\)/', $js )
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
	'BELL: the panel carries no payment control of its own',
	! preg_match( '/wpdc__panel[\s\S]{0,2000}?<(input|button|form)/', $shortcode )
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
	// A native <dialog>, so the focus trap, Escape, the backdrop and the top
	// layer come from the browser. Hand-rolling those is where modals go wrong,
	// and this is the one page a customer must not get stuck on.
	'BELL: the modal is a real dialog element, not a div pretending',
	str_contains( $shortcode, '<dialog class="wpdc__dialog"' )
		&& str_contains( $js, "root.querySelector('.wpdc__dialog')" )
);
check(
	// Order, not presence: a dialog that is not open has no layout, and an
	// iframe measured inside a zero-height box comes back zero. Opened after the
	// SDK renders, the frame is there and invisible.
	'BELL: the dialog is shown before the SDK is told to render into it',
	strpos( $js, 'dialog.showModal()' ) < strpos( $js, 'dodo.Checkout.open(' )
);
check(
	// Closing the window without telling the SDK leaves Dodo holding a live
	// frame in a hidden element, and the next open finds it already there.
	'BELL: closing the modal also closes the checkout',
	str_contains( $js, 'dodo.Checkout.close()' )
		&& str_contains( $js, "if (dialog && dialog.open) dialog.close();" )
);
check(
	// Escape and the backdrop are the dialog's own, and both fire `close` --
	// listened for, so the bookkeeping happens however it was dismissed, not
	// only when the X was used.
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

if ( $failures ) {
	echo count( $failures ) . " of $checks checks FAILED\n";
	foreach ( $failures as $name ) {
		echo "  - $name\n";
	}
	exit( 1 );
}

echo "all $checks checks passed\n";
