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
 * Script a catalogue: the list call, then one read per product.
 *
 * @param array<string, string> $plans plan key => product id.
 */
function catalogue( array $plans ): void {
	$items = array();
	foreach ( $plans as $id ) {
		$items[] = array( 'product_id' => $id );
	}
	respond( 200, array( 'items' => $items ) );
	foreach ( $plans as $plan => $id ) {
		respond( 200, array( 'product_id' => $id, 'metadata' => array( 'uwp_plan' => $plan ) ) );
	}
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
catalogue( array( 'pro' => 'pdt_pro' ) );
respond( 200, array() ); // a session with no url
$empty = wpdc_create_session( 'pro' );
check( 'BELL: a session with no url is a failure, not an empty overlay', false === $empty['ok'] && 'no_url' === $empty['reason'] );

$GLOBALS['wpdc_test_options']  = array();
$GLOBALS['wpdc_test_requests'] = array();
$unconfigured = wpdc_create_session( 'pro' );
check( 'BELL: unconfigured is not retriable', 'not_configured' === $unconfigured['reason'] && false === $unconfigured['retriable'] );
// Asserted against the recorded requests, not against a constant. An earlier
// version of this line was `check( ..., true )`: a check that cannot fail,
// counted toward the total, which is the false all-clear this file exists to
// catch.
check( 'BELL: unconfigured makes no request at all', array() === $GLOBALS['wpdc_test_requests'] );

// ─── A request names a plan key, never a product id ──────────────────────────
//
// The route is public, because buying does not require an account. If a body
// could name a product id, any visitor could mint a checkout for any product on
// the account -- a one cent test product, an archived one, one meant for a
// different site. The plan key is the allow-list, and it resolves only through
// products the owner marked in Dodo.

configure();
catalogue( array( 'pro' => 'pdt_pro', 'ebook' => 'pdt_book' ) );
respond( 200, array( 'checkout_url' => 'https://checkout.example/session/cks_1' ) );

$result = wpdc_create_session( 'pro', 20, 'ebook' );
check( 'SILENCE: a known plan produces a checkout url', true === $result['ok'] && str_contains( $result['checkout_url'], 'cks_1' ) );

$session = last_request();
$sent    = json_decode( $session['args']['body'], true );

check( 'SILENCE: the plan resolved to its product', 'pdt_pro' === $sent['product_cart'][0]['product_id'] );
check( 'SILENCE: the quantity travels', 20 === $sent['product_cart'][0]['quantity'] );
check( 'SILENCE: the bump resolved to its own product', 'pdt_book' === $sent['product_cart'][1]['product_id'] );
check(
	// A quantity on an add-on is a way to sell somebody fifty of something they
	// ticked a box for.
	'BELL: a bump is always exactly one copy',
	1 === $sent['product_cart'][1]['quantity']
);
check(
	'BELL: nothing else is sent -- no price, no return url from a caller',
	array( 'product_cart' ) === array_keys( $sent )
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

// ─── A plan nobody marked is refused, and no session is created ──────────────

configure();
catalogue( array( 'pro' => 'pdt_pro' ) );
catalogue( array( 'pro' => 'pdt_pro' ) ); // the deliberate second look
$unknown = wpdc_create_session( 'ghost' );
check( 'BELL: an unknown plan is refused', 'unknown_plan' === $unknown['reason'] );
check( 'BELL: and it is not retriable -- trying again cannot help', false === $unknown['retriable'] );
check(
	'BELL: no checkout was created for it',
	! str_contains( json_encode( $GLOBALS['wpdc_test_requests'] ), '/checkouts' )
);

// ─── Two products claiming one key make BOTH unsellable ──────────────────────
//
// Guessing which of two the owner meant is how somebody sells the wrong thing.

configure();
respond( 200, array( 'items' => array( array( 'product_id' => 'pdt_a' ), array( 'product_id' => 'pdt_b' ) ) ) );
respond( 200, array( 'product_id' => 'pdt_a', 'metadata' => array( 'uwp_plan' => 'clash' ) ) );
respond( 200, array( 'product_id' => 'pdt_b', 'metadata' => array( 'uwp_plan' => 'clash' ) ) );
respond( 200, array( 'items' => array() ) ); // the second look finds nothing new
$clash = wpdc_create_session( 'clash' );
check( 'BELL: a contested plan key sells nothing', 'unknown_plan' === $clash['reason'] );

// ─── The catalogue is cached, so a busy page is not a busy API ───────────────

configure();
catalogue( array( 'pro' => 'pdt_pro' ) );
respond( 200, array( 'checkout_url' => 'https://checkout.example/session/cks_2' ) );
wpdc_create_session( 'pro' );
$firstCount = count( $GLOBALS['wpdc_test_requests'] );

respond( 200, array( 'checkout_url' => 'https://checkout.example/session/cks_3' ) );
wpdc_create_session( 'pro' );
check(
	'BELL: the second sale costs one call, not another catalogue read',
	count( $GLOBALS['wpdc_test_requests'] ) === $firstCount + 1
);

// ─── A refused key is ours, and never shown as the visitor\'s fault ──────────

configure();
respond( 401, array( 'error' => 'bad key' ) );
$refused = wpdc_create_session( 'pro' );
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
	// A plan key of "0" is falsy in PHP; a truthiness test would drop a bump
	// the customer ticked and charge them for something else.
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
	2 === substr_count( $rest, "'validate_callback' => 'wpdc_is_plan_key'" )
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

if ( $failures ) {
	echo count( $failures ) . " of $checks checks FAILED\n";
	foreach ( $failures as $name ) {
		echo "  - $name\n";
	}
	exit( 1 );
}

echo "all $checks checks passed\n";
