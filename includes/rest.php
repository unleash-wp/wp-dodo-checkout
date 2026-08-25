<?php
/**
 * The endpoint the button calls.
 *
 * The browser cannot call the checkout server directly -- that would mean
 * shipping the shared secret to it -- so it calls WordPress, and WordPress
 * calls the server. This route is the only thing between a visitor and a real
 * object in the payment account, which is what shapes everything below.
 *
 * ── Why there is NO nonce here, though there used to be ─────────────────────
 *
 * A nonce expires. Photographed on the live shop: the buy button answered a
 * 403 and "Die Cookie-Pruefung ist fehlgeschlagen" -- and that sentence is not
 * ours, it is WordPress core's. `rest_cookie_check_errors()` runs as an
 * authentication filter BEFORE any route is dispatched, and its two branches
 * decide everything:
 *
 *     no nonce at all       -> wp_set_current_user( 0 ), request proceeds
 *     a nonce that is stale -> 403 rest_cookie_invalid_nonce, route never runs
 *
 * So on a public route a stale nonce is strictly WORSE than no nonce. Sending
 * one could only ever break this; it could never let in anyone who was out.
 * A tab open longer than the nonce lives, or an origin cache handing a signed
 * -in reader the signed-out HTML, and a paying customer meets a dead button.
 *
 * And it broke more than the button. The status route sent the same nonce and
 * never checked one, so the same staleness killed the poll that CONFIRMS a
 * payment: money taken, error shown. The price route below already carried
 * this reasoning in its own comment. It simply was not followed here.
 *
 * What the nonce did in substance was rate limiting, badly. That job now sits
 * in `wpdc_rest_session()` as an explicit ceiling. It was never authentication
 * either: no fact about a visitor reaches this route, because buying needs no
 * account, and the answer is a fresh checkout URL for the caller's own session
 * -- a cross-site call has no victim, only a wasted mint.
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
		'/price',
		array(
			// GET, and no nonce, unlike its neighbours. Both follow from what this
			// route is: it reads a price out of a catalogue the shop publishes on
			// its own sales pages anyway. It creates nothing, it spends nothing
			// per call -- the Dodo lookup behind it is cached per product -- and
			// it answers only about ids Dodo currently lists. A nonce here would
			// buy nothing and would break the one case this exists for: a page
			// served from a full-page cache, where no fresh nonce is available.
			'methods'             => 'GET',
			'callback'            => 'wpdc_rest_price',
			'permission_callback' => '__return_true',
			'args'                => array(
				'product' => array(
					'required'          => true,
					'validate_callback' => 'wpdc_is_product_id',
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
	/**
	 * The ceiling that replaced the nonce.
	 *
	 * Removing the nonce (see the file header) left the one route that creates
	 * real objects in the payment account with nothing in front of it, so the
	 * brake it was pretending to be is now an actual brake.
	 *
	 * SITE-WIDE, NOT PER ADDRESS. `wpdc_rest_status()` below learned that the
	 * expensive way: on most production WordPress `REMOTE_ADDR` is the proxy,
	 * so a per-address bucket is one shared bucket, and the first symptom is
	 * real customers refused. That route could key on a session id instead.
	 * This one cannot -- minting the session is what it does -- so site-wide is
	 * the only honest bucket left.
	 *
	 * The cost is stated rather than hidden: someone hammering this can hold
	 * the shop shut a minute at a time. That is why the answer is 429 with a
	 * retriable message that names the minute, and why the counter self-heals
	 * -- once it latches nothing writes it, so it expires a minute after the
	 * last request that got through.
	 */
	$minted = (int) get_transient( 'wpdc_session_all' );
	if ( $minted >= WPDC_SESSION_CEILING ) {
		return new WP_REST_Response(
			array(
				'message'   => __( 'The shop is busy right now. Please try again in a minute.', 'wp-dodo-checkout' ),
				'retriable' => true,
			),
			429
		);
	}
	set_transient( 'wpdc_session_all', $minted + 1, MINUTE_IN_SECONDS );

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
 * What this visitor's price is, so the sales page can stop guessing.
 *
 * The page renders the base price server-side and this replaces it. That order
 * is not a nicety: a sales page is the most cached thing on a WordPress site,
 * and a per-visitor price rendered INTO the HTML would be frozen by the first
 * visitor's country and served to everyone after them. So the HTML stays
 * country-free and the number arrives afterwards.
 *
 * The answer must never be cached, and that is what the header below is for.
 * Cloudflare sits in front of this site; one price cached at the edge and handed
 * to the next country is exactly the mismatch this feature exists to remove.
 */
function wpdc_rest_price( WP_REST_Request $request ): WP_REST_Response {
	$product = (string) $request->get_param( 'product' );
	$price   = wpdc_price_for_country( $product, wpdc_visitor_country() );

	if ( wpdc_is_error( $price ) ) {
		// Silent by design. The page already shows a price; saying "we could not
		// localise it" would replace a correct base price with a worry.
		$response = new WP_REST_Response( array( 'ok' => false ), 200 );
		$response->header( 'cache-control', 'private, no-store' );
		return $response;
	}

	$response = new WP_REST_Response(
		array(
			'ok' => true,
			// Minor units and a currency code, never a formatted string. The
			// browser formats it, because the browser is the only party here that
			// certainly has an Intl implementation -- PHP's is an optional
			// extension -- and because Intl knows that the yen has no decimals
			// without being told.
			'amount'    => $price['amount'],
			'currency'  => $price['currency'],
			// Whether this is the visitor's own price or the shop's default. The
			// page does not use it today; it is here so a future caller does not
			// have to infer it by comparing currencies.
			'localized' => $price['localized'],
		),
		200
	);
	$response->header( 'cache-control', 'private, no-store' );
	return $response;
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
	 * own polling -- and no customer can spend another's allowance.
	 *
	 * The number lives in config.php beside the reason it has to exceed the
	 * browser's own budget. It is not a per-minute figure in practice: writing
	 * the transient renews its life, and a checkout polls far more often than
	 * once a minute, so the counter stands for one whole checkout.
	 */
	$session = (string) $request->get_param( 'session' );
	$bucket  = 'wpdc_poll_' . md5( $session );
	$spent   = (int) get_transient( $bucket );
	if ( $spent >= WPDC_POLL_CEILING ) {
		return new WP_REST_Response( array( 'finished' => false ), 200 );
	}
	set_transient( $bucket, $spent + 1, MINUTE_IN_SECONDS );

	/**
	 * And a second ceiling for the thing the first one no longer covers.
	 *
	 * Per-session counting is right for customers and useless against somebody
	 * looping invented ids, because every new id brings its own allowance. This
	 * one is deliberately site-wide: what is being protected is the shop's
	 * single API key, which is site-wide too. Six hundred a minute is well past
	 * anything this shop sees and well below anything that would cost us Dodo's
	 * own rate limit.
	 *
	 * Unlike the per-session bucket this one does self-heal: once it latches,
	 * nothing writes it any more, so it expires a minute after the last request
	 * that got through.
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
