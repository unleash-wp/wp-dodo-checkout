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
			// POST, not GET. The session id is a CAPABILITY -- whoever holds it
			// is handed this order's download links and licence key -- and a
			// capability in a query string is a capability in every access log,
			// proxy log and Referer header between here and the server.
			'methods'             => 'POST',
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
 * Did the checkout the caller is looking at finish, and what did it deliver?
 *
 * Public like the session route, and for the same reason -- buying needs no
 * account, so confirming a purchase cannot need one either.
 *
 * WHAT THE SESSION ID IS WORTH, stated plainly because an earlier version of
 * this comment claimed it was worth a boolean and that stopped being true the
 * moment the download links and the licence key were added below: the id is a
 * CAPABILITY. Presenting it returns this order's signed download URLs and its
 * key -- the same things Dodo puts in the mail. It is minted by Dodo, random,
 * never guessed in practice, and it goes back in a POST body rather than a URL
 * so it stays out of every log on the way. What still never leaves is the
 * customer's name and email, which ride along on every upstream response this
 * route reads.
 *
 * The API key is spent per call, so the ceiling below is not optional.
 */
function wpdc_rest_status( WP_REST_Request $request ): WP_REST_Response {
	if ( ! wpdc_is_configured() ) {
		return new WP_REST_Response( array( 'finished' => false ), 200 );
	}

	/**
	 * A ceiling, because this route spends the shop's API key on request.
	 *
	 * It is public -- buying needs no account, so polling cannot need one
	 * either -- and every call it serves becomes one to three outbound calls to
	 * Dodo carrying our key. Left open, somebody looping well-shaped but
	 * invented session ids turns this site into a way to burn the shop's own
	 * rate limit, and the first symptom would be real customers unable to check
	 * out.
	 *
	 * KEYED ON THE SESSION, NOT ON THE ADDRESS, and that is the whole point.
	 *
	 * The first version of this counted per `REMOTE_ADDR`. On most production
	 * WordPress that is the proxy -- Cloudflare, a load balancer, the host's own
	 * front end -- so every visitor on the site shared ONE bucket of sixty. A
	 * checkout polls thirty times, so the third concurrent customer was
	 * throttled and told "we could not confirm your order", for an order that
	 * had succeeded. Ordinary traffic, not abuse, producing the worst sentence
	 * this system can say. Trusting a forwarded header instead would have moved
	 * the problem rather than fixed it: whoever can set that header sets their
	 * own bucket.
	 *
	 * A session id is minted by Dodo and belongs to exactly one checkout, so a
	 * ceiling on it bounds the thing actually worth bounding -- one customer's
	 * own polling -- and no customer can spend another's allowance. Forty over
	 * a minute against the thirty a real poll asks for.
	 */
	$session = (string) $request->get_param( 'session' );
	$bucket  = 'wpdc_poll_' . md5( $session );
	$spent   = (int) get_transient( $bucket );
	if ( $spent >= 40 ) {
		return new WP_REST_Response( array( 'finished' => false ), 200 );
	}
	set_transient( $bucket, $spent + 1, MINUTE_IN_SECONDS );

	/**
	 * And a second ceiling for the thing the first one no longer covers.
	 *
	 * Per-session counting is right for customers and useless against somebody
	 * looping invented ids, because every new id brings its own forty. This one
	 * is deliberately site-wide: what is being protected is the shop's single
	 * API key, which is site-wide too. Six hundred a minute is fifteen
	 * simultaneous checkouts, far past anything this shop sees and far below
	 * anything that would cost us Dodo's own rate limit.
	 */
	$all = (int) get_transient( 'wpdc_poll_all' );
	if ( $all >= 600 ) {
		return new WP_REST_Response( array( 'finished' => false ), 200 );
	}
	set_transient( 'wpdc_poll_all', $all + 1, MINUTE_IN_SECONDS );

	$result = wpdc_session_finished( $session );
	if ( wpdc_is_error( $result ) ) {
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
			// The goods themselves: Dodo's signed download links and the
			// licence key, when the product carries those entitlements. Handed
			// to the holder of the session id on purpose -- it is the browser
			// that created this checkout, and the same links go into the mail
			// Dodo sends. The customer's name and email, which ride along on
			// every response this route reads, still stay on the server.
			'files'    => $result['files'] ?? array(),
			'keys'     => $result['keys'] ?? array(),
		),
		200
	);
}
