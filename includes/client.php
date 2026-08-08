<?php
/**
 * The one place that talks to Dodo Payments.
 *
 * One place owns the credential, the transport, and the vocabulary for "this
 * did not happen". That vocabulary is the load-bearing part: a caller must be
 * able to tell a transient outage (retry) from a misconfiguration (stop asking)
 * from a plan that does not exist (fix the shortcode) WITHOUT parsing English
 * prose. Every failure carries a machine-readable `reason` and a `retriable`
 * flag alongside the sentence a human reads.
 *
 * ── The rule that survived losing the middle server ─────────────────────────
 *
 * This plugin used to post a plan key to a server that owned the allow-list.
 * Now it owns the allow-list itself, and the rule it existed for is unchanged:
 *
 *   **A request never names a product id. It names a plan key.**
 *
 * The route is public, because buying does not require an account. If a body
 * could name a product id, any visitor could mint a checkout for any product on
 * the account -- including a one cent test product, and including one that is
 * archived, unpriced or not meant for this site. A plan key can only resolve to
 * a product the shop owner deliberately marked with `uwp_plan` in Dodo.
 *
 * ── Why there is no product map to maintain ─────────────────────────────────
 *
 * The map is Dodo's own product list, filtered to those carrying the metadata
 * key. Create a product, set `uwp_plan`, sell it: no settings screen, no
 * constant to edit, no deploy. The alternative -- a key:product_id list in
 * wp-config -- drifts the first time a product is archived in Dodo and nowhere
 * else, and nothing on either side would report it.
 *
 * Two products sharing a plan key make BOTH unsellable rather than picking one.
 * Guessing which of two the owner meant is how somebody sells the wrong thing.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every failure this plugin can have, in one shape.
 *
 * @param string $reason    Machine-readable. Callers branch on this, never on the text.
 * @param bool   $retriable Whether trying again could work.
 * @param string $message   For a human, and safe to render.
 */
function wpdc_error( string $reason, bool $retriable, string $message ): array {
	return array(
		'ok'        => false,
		'reason'    => $reason,
		'retriable' => $retriable,
		'message'   => $message,
	);
}

/**
 * One request to Dodo. Returns the decoded body, or an error array.
 *
 * The key goes in an Authorization header and nowhere else. It is never placed
 * in a URL: query strings survive in access logs, proxy logs and browser
 * history, and this one can issue refunds.
 */
function wpdc_dodo_request( string $method, string $path, ?array $body = null ) {
	$args = array(
		'method'  => $method,
		'timeout' => WPDC_TIMEOUT,
		'headers' => array(
			'authorization' => 'Bearer ' . wpdc_api_key(),
			'accept'        => 'application/json',
		),
	);

	if ( null !== $body ) {
		$args['headers']['content-type'] = 'application/json';
		$args['body']                    = wp_json_encode( $body );
	}

	$response = wp_remote_request( wpdc_api_base() . $path, $args );

	if ( is_wp_error( $response ) ) {
		return wpdc_error(
			'unreachable',
			true,
			__( 'The payment provider could not be reached. Please try again in a moment.', 'wp-dodo-checkout' )
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$parsed = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( 401 === $status || 403 === $status ) {
		// Ours, not the visitor's. Said plainly in the log and vaguely on the
		// page: a visitor cannot act on it and should not be shown our
		// configuration problems.
		error_log( 'wp-dodo-checkout: the Dodo API key was refused (' . $status . ')' );
		return wpdc_error(
			'unauthorised',
			false,
			__( 'Checkout is not available right now. This is a fault on our side.', 'wp-dodo-checkout' )
		);
	}

	if ( $status < 200 || $status > 299 ) {
		return wpdc_error(
			'upstream_error',
			$status >= 500,
			__( 'The payment provider could not complete this. Please try again in a moment.', 'wp-dodo-checkout' )
		);
	}

	if ( ! is_array( $parsed ) ) {
		return wpdc_error(
			'bad_response',
			true,
			__( 'The payment provider sent something unexpected. Please try again.', 'wp-dodo-checkout' )
		);
	}

	return $parsed;
}

/**
 * plan key => product id, from Dodo, cached.
 *
 * Only products carrying `uwp_plan` appear, which IS the allow-list. A key
 * claimed by two products is dropped from the map entirely, so both stop being
 * sellable and the owner finds out by trying rather than by a customer
 * receiving the wrong file.
 *
 * @return array<string, string>|array{ok: false}
 */
function wpdc_plan_map( bool $fresh = false ) {
	$cached = $fresh ? false : get_transient( 'wpdc_plan_map' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$result = wpdc_dodo_request( 'GET', '/products?page_size=100' );
	if ( isset( $result['ok'] ) && false === $result['ok'] ) {
		return $result;
	}

	$items = is_array( $result['items'] ?? null ) ? $result['items'] : array();
	$map   = array();
	$clash = array();

	foreach ( $items as $item ) {
		$id = is_string( $item['product_id'] ?? null ) ? $item['product_id'] : '';
		if ( '' === $id ) {
			continue;
		}
		// The list endpoint does not carry metadata, so each product is read
		// once. Cached for ten minutes, so this cost lands on the shop owner's
		// editing rhythm rather than on visitors.
		$full = wpdc_dodo_request( 'GET', '/products/' . rawurlencode( $id ) );
		if ( isset( $full['ok'] ) && false === $full['ok'] ) {
			return $full;
		}

		$plan = is_array( $full['metadata'] ?? null ) ? ( $full['metadata'][ WPDC_PLAN_KEY ] ?? '' ) : '';
		$plan = is_string( $plan ) ? trim( $plan ) : '';
		if ( '' === $plan || ! wpdc_is_plan_key( $plan ) ) {
			continue;
		}

		if ( isset( $map[ $plan ] ) ) {
			$clash[ $plan ] = true;
			continue;
		}
		$map[ $plan ] = $id;
	}

	foreach ( array_keys( $clash ) as $key ) {
		error_log( 'wp-dodo-checkout: two products claim ' . WPDC_PLAN_KEY . '="' . $key . '"; neither is sellable' );
		unset( $map[ $key ] );
	}

	set_transient( 'wpdc_plan_map', $map, WPDC_CATALOG_TTL );
	return $map;
}

/**
 * A checkout URL for one plan, ready for the overlay.
 *
 * @param string      $plan     Plan key, never a product id.
 * @param int         $quantity How many.
 * @param string|null $bump     Optional second plan key, one copy.
 */
function wpdc_create_session( string $plan, int $quantity = 1, ?string $bump = null ): array {
	if ( ! wpdc_is_configured() ) {
		return wpdc_error(
			'not_configured',
			false,
			__( 'Checkout is not configured on this site.', 'wp-dodo-checkout' )
		);
	}

	$map = wpdc_plan_map();
	if ( isset( $map['ok'] ) && false === $map['ok'] ) {
		return $map;
	}

	// A plan the map does not know is refused BEFORE any session is created,
	// and refreshed once first: the owner may have added the product a minute
	// ago, and making them wait out a cache reads as the plugin being broken.
	if ( ! isset( $map[ $plan ] ) ) {
		$map = wpdc_plan_map( true );
		if ( isset( $map['ok'] ) && false === $map['ok'] ) {
			return $map;
		}
	}
	if ( ! isset( $map[ $plan ] ) ) {
		return wpdc_error(
			'unknown_plan',
			false,
			__( 'That product is not available.', 'wp-dodo-checkout' )
		);
	}

	$cart = array( array( 'product_id' => $map[ $plan ], 'quantity' => $quantity ) );

	if ( null !== $bump && '' !== $bump ) {
		if ( ! isset( $map[ $bump ] ) ) {
			return wpdc_error(
				'unknown_plan',
				false,
				__( 'That extra is not available.', 'wp-dodo-checkout' )
			);
		}
		// One copy of an add-on, always. A quantity on a bump is a way to sell
		// somebody fifty of something they ticked a box for.
		$cart[] = array( 'product_id' => $map[ $bump ], 'quantity' => 1 );
	}

	$body = array( 'product_cart' => $cart );

	$return = wpdc_return_url();
	if ( '' !== $return ) {
		$body['return_url'] = $return;
	}

	$result = wpdc_dodo_request( 'POST', '/checkouts', $body );
	if ( isset( $result['ok'] ) && false === $result['ok'] ) {
		return $result;
	}

	$url = is_string( $result['checkout_url'] ?? null ) ? $result['checkout_url'] : '';
	if ( '' === $url ) {
		// Treated as a failure rather than returned empty, so a caller cannot
		// open an overlay pointing at nothing.
		return wpdc_error(
			'no_url',
			true,
			__( 'The checkout could not be opened. Please try again.', 'wp-dodo-checkout' )
		);
	}

	return array( 'ok' => true, 'checkout_url' => $url );
}

/**
 * Where Dodo sends the buyer afterwards. Always a URL on THIS site.
 *
 * Never taken from the request. A return_url a visitor can choose turns this
 * into an open redirect on the shop's own domain.
 */
function wpdc_return_url(): string {
	if ( defined( 'WPDC_RETURN_URL' ) && is_string( WPDC_RETURN_URL ) ) {
		$configured = trim( WPDC_RETURN_URL );
		// Same-origin only, checked rather than trusted.
		if ( '' !== $configured && wp_parse_url( $configured, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return $configured;
		}
	}
	return '';
}
