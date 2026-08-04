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
 * ── Why the plan is validated twice ─────────────────────────────────────────
 *
 * The server has the allow-list and is the authority. This checks the SHAPE
 * anyway, because a value from a browser should not reach an outbound HTTP
 * request unexamined, and because a shortcode typo should say so here rather
 * than spend a round trip to find out.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wpdc_register_rest(): void {
	register_rest_route(
		'wp-dodo-checkout/v1',
		'/session',
		array(
			'methods'             => 'POST',
			'callback'            => 'wpdc_rest_session',
			'permission_callback' => '__return_true',
			'args'                => array(
				'plan'     => array(
					'required'          => true,
					'validate_callback' => 'wpdc_is_plan_key',
				),
				'bump'     => array(
					'required'          => false,
					'validate_callback' => 'wpdc_is_plan_key',
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

/** Lowercase, digits, underscores. The same shape the server registers. */
function wpdc_is_plan_key( $value ): bool {
	return is_string( $value ) && 1 === preg_match( '/^[a-z0-9_]{1,64}$/', $value );
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
		(string) $request->get_param( 'plan' ),
		(int) ( $request->get_param( 'quantity' ) ?? 1 ),
		// A plan key of "0" is falsy in PHP. Comparing against null is what the
		// route's own validator already guarantees, and a truthiness test here
		// would silently drop a bump the customer ticked.
		null !== $request->get_param( 'bump' ) ? (string) $request->get_param( 'bump' ) : null
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

	return new WP_REST_Response( array( 'url' => $result['url'] ), 200 );
}
