<?php
/**
 * The endpoint the button calls.
 *
 * The browser cannot call the checkout server directly -- that would mean
 * shipping the shared secret to it -- so it calls WordPress, and WordPress
 * calls the server. This route is the only thing between a visitor and a real
 * object in the payment account, which is what shapes everything below.
 *
 * ── Why a nonce, on a public route ──────────────────────────────────────────
 *
 * The route is public by necessity: buying does not require an account. The
 * nonce is not authentication and is not treated as such; it is what stops
 * another site from driving this endpoint from a visitor's browser. WordPress
 * issues one to logged-out visitors too, tied to the session cookie, which is
 * exactly the property wanted here.
 *
 * ── Why the id is validated here as well as in the client ───────────────────
 *
 * client.php holds the authority: an id is honoured only if Dodo currently
 * lists it. This checks the SHAPE anyway, because a value from a browser should
 * not reach an outbound HTTP request unexamined, and because rejecting
 * "pro" or "<script>" costs nothing here and costs a round trip there.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wpdc_register_rest(): void {
	register_rest_route(
		'wp-dodo-checkout/v1',
		'/status',
		array(
			'methods'             => 'GET',
			'callback'            => 'wpdc_rest_status',
			'permission_callback' => '__return_true',
			'args'                => array(
				'session' => array(
					'required'          => true,
					'validate_callback' => 'wpdc_is_session_id',
				),
			),
		)
	);

	register_rest_route(
		'wp-dodo-checkout/v1',
		'/session',
		array(
			'methods'             => 'POST',
			'callback'            => 'wpdc_rest_session',
			'permission_callback' => '__return_true',
			'args'                => array(
				'product'  => array(
					'required'          => true,
					'validate_callback' => 'wpdc_is_product_id',
				),
				'bump'     => array(
					'required'          => false,
					'validate_callback' => 'wpdc_is_product_id',
				),
				'lang'     => array(
					'required'          => false,
					'validate_callback' => static fn( $value ): bool =>
						is_string( $value ) && 1 === preg_match( '/^[a-z]{2}$/i', $value ),
				),
				'discount' => array(
					'required'          => false,
					'validate_callback' => 'wpdc_is_discount_code',
				),
				'quantity' => array(
					'required'          => false,
					'validate_callback' => static fn( $value ): bool =>
						is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 50,
				),
			),
		)
	);
}

function wpdc_rest_session( WP_REST_Request $request ): WP_REST_Response {
	$nonce = $request->get_header( 'x-wp-nonce' );
	if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return new WP_REST_Response(
			array(
				'message'   => __( 'Please reload the page and try again.', 'wp-dodo-checkout' ),
				'retriable' => true,
			),
			403
		);
	}

	$result = wpdc_create_session(
		(string) $request->get_param( 'product' ),
		(int) ( $request->get_param( 'quantity' ) ?? 1 ),
		// Compared against null, not tested for truthiness. The route's own
		// validator already guarantees the shape, and a truthy test is the kind
		// of thing that silently drops a bump the customer ticked.
		null !== $request->get_param( 'bump' ) ? (string) $request->get_param( 'bump' ) : null,
		null !== $request->get_param( 'lang' ) ? (string) $request->get_param( 'lang' ) : null,
		null !== $request->get_param( 'discount' ) ? (string) $request->get_param( 'discount' ) : null
	);

	if ( ! $result['ok'] ) {
		// The status mirrors retriable, so the browser does not have to read
		// the reason to decide whether trying again could help. The reason is
		// there for a developer looking at the network tab, and never
		// rendered.
		$status = $result['retriable'] ? 503 : 400;
		return new WP_REST_Response(
			array(
				'message'   => $result['message'],
				'reason'    => $result['reason'],
				'retriable' => $result['retriable'],
			),
			$status
		);
	}

	// Dodo's name stays inside the client; the browser gets this route's own
	// contract. Translating here is what stops a provider rename from reaching
	// the JavaScript.
	return new WP_REST_Response(
		array(
			'url' => $result['checkout_url'],
			// Carried so the browser can ask later whether this checkout
			// finished. It identifies the visitor's own checkout and nothing
			// else -- the status route answers a boolean about it, never the
			// name or the email Dodo returns alongside.
			'session' => $result['session_id'],
		),
		200
	);
}

/**
 * Did the checkout the caller is looking at finish?
 *
 * Exists for one case, and is deliberately not more general than that: a cart
 * discounted to zero completes on Dodo's side while their frame sits on a
 * payment step it cannot draw. Nothing tells the browser, so the browser asks.
 *
 * Public like the session route, and for the same reason -- buying needs no
 * account. It answers `{ finished: bool, redirect: string }` and nothing more,
 * so a guessed session id buys a boolean about somebody else's order and no
 * detail of it.
 */
function wpdc_rest_status( WP_REST_Request $request ): WP_REST_Response {
	if ( ! wpdc_is_configured() ) {
		return new WP_REST_Response( array( 'finished' => false ), 200 );
	}

	$result = wpdc_session_finished( (string) $request->get_param( 'session' ) );
	if ( isset( $result['ok'] ) && false === $result['ok'] ) {
		// A checkout that cannot be asked about is not a finished one. Reported
		// as "not yet" rather than as an error, because the caller is polling
		// and there is nothing for a customer to do about a failed poll.
		return new WP_REST_Response( array( 'finished' => false ), 200 );
	}

	return new WP_REST_Response(
		array(
			'finished' => (bool) $result['finished'],
			// Where "done" leads is decided here, never by the caller. Empty
			// when no WPDC_RETURN_URL is configured -- the front page was tried
			// as a floor and reads as the popup breaking, because the purchase
			// ends on a page that says nothing about it. With no destination
			// worth showing, the browser shows the completion where the
			// customer already is.
			'redirect' => wpdc_return_url(),
		),
		200
	);
}
