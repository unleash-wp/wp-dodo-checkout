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

$GLOBALS['wpdc_test_options']  = array();
$GLOBALS['wpdc_test_response'] = null;
$GLOBALS['wpdc_test_request']  = null;

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
function wp_remote_post( string $url, array $args ) {
	$GLOBALS['wpdc_test_request'] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['wpdc_test_response'];
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
	$GLOBALS['wpdc_test_options'] = array(
		'wpdc_endpoint' => 'https://mcp.example/',
		'wpdc_secret'   => 's3cret',
	);
}

function respond( int $status, array $body ): void {
	$GLOBALS['wpdc_test_response'] = array(
		'response' => array( 'code' => $status ),
		'body'     => json_encode( $body ),
	);
}

// ─── The failure vocabulary ──────────────────────────────────────────────────
// The load-bearing part of the client: a caller must tell a transient outage
// (retry) from a misconfiguration (stop asking) without parsing English.

configure();

respond( 200, array( 'checkoutUrl' => 'https://checkout.dodo/x' ) );
$ok = wpdc_create_session( 'pro' );
check( 'SILENCE: a good response yields the url', true === $ok['ok'] && 'https://checkout.dodo/x' === $ok['url'] );

respond( 401, array() );
$unauth = wpdc_create_session( 'pro' );
check( 'BELL: 401 is not retriable, because retrying cannot fix a wrong secret', false === $unauth['ok'] && 'unauthorized' === $unauth['reason'] && false === $unauth['retriable'] );

respond( 400, array() );
$plan = wpdc_create_session( 'nope' );
check( 'BELL: 400 is not retriable, because the shortcode is wrong', false === $plan['ok'] && 'unknown_plan' === $plan['reason'] && false === $plan['retriable'] );

respond( 429, array() );
$rate = wpdc_create_session( 'pro' );
check( 'BELL: 429 IS retriable', 'rate_limited' === $rate['reason'] && true === $rate['retriable'] );

respond( 503, array() );
$down = wpdc_create_session( 'pro' );
check( 'BELL: 5xx IS retriable', 'unavailable' === $down['reason'] && true === $down['retriable'] );

$GLOBALS['wpdc_test_response'] = new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: mcp.example' );
$err = wpdc_create_session( 'pro' );
check( 'BELL: a transport error is retriable', 'unreachable' === $err['reason'] && true === $err['retriable'] );
check(
	'BELL: the transport error message never reaches the visitor',
	! str_contains( $err['message'], 'mcp.example' ) && ! str_contains( $err['message'], 'cURL' )
);

respond( 200, array() );
$empty = wpdc_create_session( 'pro' );
check( 'BELL: a 200 with no url is a failure, not an empty redirect', false === $empty['ok'] );

$GLOBALS['wpdc_test_options'] = array();
$GLOBALS['wpdc_test_request']  = null;
$unconfigured = wpdc_create_session( 'pro' );
check( 'BELL: unconfigured is not retriable', 'not_configured' === $unconfigured['reason'] && false === $unconfigured['retriable'] );
// Asserted against the recorded request, not against a constant. The previous
// version of this line was `check( ..., true )`: a check that cannot fail,
// counted toward the total, which is exactly the false all-clear the rest of
// this file exists to catch.
check( 'BELL: unconfigured makes no request at all', null === $GLOBALS['wpdc_test_request'] );

// ─── What reaches the outbound request ───────────────────────────────────────

configure();
respond( 200, array( 'checkoutUrl' => 'u' ) );
wpdc_create_session( 'pro', 20, 'ebook' );
$sent = json_decode( $GLOBALS['wpdc_test_request']['args']['body'], true );

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
	's3cret' === $GLOBALS['wpdc_test_request']['args']['headers']['x-lumo-checkout-secret']
		&& ! str_contains( $GLOBALS['wpdc_test_request']['url'], 's3cret' )
		&& ! str_contains( $GLOBALS['wpdc_test_request']['args']['body'], 's3cret' )
);
check(
	'BELL: a trailing slash on the endpoint does not double up',
	'https://mcp.example/api/checkout-session' === $GLOBALS['wpdc_test_request']['url']
);

wpdc_create_session( 'pro' );
$plain = json_decode( $GLOBALS['wpdc_test_request']['args']['body'], true );
check( 'SILENCE: no bump means no bump key', ! array_key_exists( 'bump', $plain ) );

// ─── Configuration precedence ────────────────────────────────────────────────

define( 'WPDC_SECRET', 'from-wp-config' );
check(
	'BELL: a wp-config constant beats the stored option',
	'from-wp-config' === wpdc_secret()
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
