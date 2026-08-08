<?php
/**
 * The one place that talks to Dodo Payments.
 *
 * One place owns the credential, the transport, and the vocabulary for "this
 * did not happen". That vocabulary is the load-bearing part: a caller must be
 * able to tell a transient outage (retry) from a misconfiguration (stop asking)
 * from a product that is not sellable (fix the shortcode) WITHOUT parsing
 * English prose. Every failure carries a machine-readable `reason` and a
 * `retriable` flag alongside the sentence a human reads.
 *
 * ── The rule the public route is shaped around ──────────────────────────────
 *
 * The REST route is public, because buying does not require an account. So a
 * request must not be able to mint a checkout for anything the shop owner did
 * not put on sale:
 *
 *   **A product id is honoured only if Dodo currently lists it.**
 *
 * That list is the allow-list. It costs nothing to maintain because it is not
 * maintained: create a product in Dodo, put its id in a shortcode, sell it.
 * Archive it and it stops being sellable within the cache window, on both
 * sides, with nothing to remember.
 *
 * An earlier version required each product to carry a `uwp_plan` metadata key
 * and the shortcode named that key instead of the id. It bought two small
 * things -- a shortcode that survives a product being recreated under a new id,
 * and a friendlier name in the editor -- and cost a step in the dashboard per
 * product plus a layer of indirection that read as an error every time somebody
 * looked at it. The id is not a secret either way: Dodo's own static payment
 * link is `checkout.dodopayments.com/buy/<product id>`.
 *
 * ── What the allow-list does and does not stop ──────────────────────────────
 *
 * It stops a crafted request from selling an archived, deleted or never-listed
 * product. It does NOT stop one from naming a different LIVE product than the
 * page shows -- somebody who reads the ids off two of your pages can mint a
 * checkout for either. They still pay the listed price for the listed thing, so
 * the exposure is "a live product can be bought", which is what a live product
 * is for. The case where that matters is a cheap test product left live: it can
 * be bought by anyone who finds its id. Archive test products.
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
 * Does a cached catalogue still have the shape this build reads?
 *
 * Versioning the cache key covers releases. It does NOT cover a shape that
 * changed while the version stayed put, which is every moment during
 * development and every hotfix that adds a field -- and the failure is not a
 * notice. A consumer that calls a typed function with a missing key gets a
 * TypeError, which is a WHITE PAGE where the checkout used to be, for as long
 * as the entry lives.
 *
 * That happened. The key was versioned, the version did not move, a field came
 * and went, and the product page returned 500. So the cache is checked against
 * what is actually read from it rather than trusted because it is an array.
 *
 * A mismatch is a cache miss, not an error: the next line fetches fresh.
 */
function wpdc_catalog_shape_ok( array $catalog ): bool {
	foreach ( $catalog as $row ) {
		if ( ! is_array( $row ) ) {
			return false;
		}
		foreach ( array( 'name', 'image', 'description', 'price', 'currency' ) as $key ) {
			if ( ! array_key_exists( $key, $row ) ) {
				return false;
			}
		}
	}
	return true;
}

/**
 * The sellable catalogue: product id => name, price, currency.
 *
 * One API call. The list endpoint carries the name and price already, so there
 * is nothing to fetch per product -- an earlier version read each product
 * individually to get at its metadata, which cost one request per product on
 * every cache miss for information that was in the list all along.
 *
 * Dodo's list excludes archived products by default, which is exactly the
 * allow-list wanted: archiving something in the dashboard is how it stops being
 * sellable here.
 *
 * @return array<string, array{name: string, image: string, description: string, price: int|null, currency: string}>|array{ok: false}
 */
function wpdc_catalog( bool $fresh = false ) {
	$cached = $fresh ? false : get_transient( wpdc_catalog_key() );
	if ( is_array( $cached ) && wpdc_catalog_shape_ok( $cached ) ) {
		return $cached;
	}

	$result = wpdc_dodo_request( 'GET', '/products?page_size=100' );
	if ( isset( $result['ok'] ) && false === $result['ok'] ) {
		return $result;
	}

	$items   = is_array( $result['items'] ?? null ) ? $result['items'] : array();
	$catalog = array();

	foreach ( $items as $item ) {
		$id = is_string( $item['product_id'] ?? null ) ? $item['product_id'] : '';
		// Checked rather than trusted, even coming from Dodo. A value that
		// reaches wpdc_is_product_id() from two directions is a value no
		// caller has to reason about.
		if ( '' === $id || ! wpdc_is_product_id( $id ) ) {
			continue;
		}

		$catalog[ $id ] = array(
			'name'        => is_string( $item['name'] ?? null ) ? $item['name'] : $id,
			// Dodo hosts the product image; showing it is what turns a window of
			// text into the thing the customer just decided to buy.
			'image'       => is_string( $item['image'] ?? null ) ? $item['image'] : '',
			'description' => is_string( $item['description'] ?? null ) ? $item['description'] : '',
			'price'       => is_int( $item['price'] ?? null ) ? $item['price'] : null,
			'currency'    => is_string( $item['currency'] ?? null ) ? $item['currency'] : '',
		);
	}

	set_transient( wpdc_catalog_key(), $catalog, WPDC_CATALOG_TTL );
	return $catalog;
}

/**
 * A checkout URL for one product, ready for the overlay.
 *
 * @param string      $product  Dodo product id.
 * @param int         $quantity How many.
 * @param string|null $bump     Optional second product id, one copy.
 */
function wpdc_create_session( string $product, int $quantity = 1, ?string $bump = null, ?string $lang = null ): array {
	if ( ! wpdc_is_configured() ) {
		return wpdc_error(
			'not_configured',
			false,
			__( 'Checkout is not configured on this site.', 'wp-dodo-checkout' )
		);
	}

	$catalog = wpdc_catalog();
	if ( isset( $catalog['ok'] ) && false === $catalog['ok'] ) {
		return $catalog;
	}

	// A product the catalogue does not know is refused BEFORE any session is
	// created, and refreshed once first: the owner may have created it a minute
	// ago, and making them wait out a cache reads as the plugin being broken.
	if ( ! isset( $catalog[ $product ] ) ) {
		$catalog = wpdc_catalog( true );
		if ( isset( $catalog['ok'] ) && false === $catalog['ok'] ) {
			return $catalog;
		}
	}
	if ( ! isset( $catalog[ $product ] ) ) {
		return wpdc_error(
			'unknown_product',
			false,
			__( 'That product is not available.', 'wp-dodo-checkout' )
		);
	}

	$cart = array( array( 'product_id' => $product, 'quantity' => $quantity ) );

	if ( null !== $bump && '' !== $bump ) {
		if ( ! isset( $catalog[ $bump ] ) ) {
			return wpdc_error(
				'unknown_product',
				false,
				__( 'That extra is not available.', 'wp-dodo-checkout' )
			);
		}
		// One copy of an add-on, always. A quantity on a bump is a way to sell
		// somebody fifty of something they ticked a box for.
		$cart[] = array( 'product_id' => $bump, 'quantity' => 1 );
	}

	$body = array(
		'product_cart' => $cart,

		// Country, and a postcode only where a tax authority needs one. Street,
		// city and state are skipped. Dodo's own words: "For maximum conversion,
		// enable minimal address collection... for digital products and SaaS
		// flows where complete billing details aren't required."
		//
		// This plugin sells digital goods -- there is nothing to ship, and the
		// address exists to satisfy VAT, not a courier. A shop with physical
		// products would have to turn this off.
		'minimal_address' => true,

		// A returning customer sees the card they used last time instead of
		// typing it again. Off by default at Dodo. It costs nothing here and it
		// is the only field-count reduction that gets BETTER the more somebody
		// buys.
		'show_saved_payment_methods' => true,

		'feature_flags' => array(
			// Dodo shows it by default and nobody here reads it. An optional
			// field on a payment form is not free: it is one more thing between
			// a decision to buy and the money.
			'allow_phone_number_collection' => false,

			// A field the shop owner can point a campaign at. Off means a code
			// printed on a newsletter has nowhere to go and the customer writes
			// to support instead of buying.
			'allow_discount_code'           => true,
		),
	);

	// Deliberately NOT sent: `customization.theme_config`. Dodo's dashboard has
	// a Design page that does the same thing visually and applies it to the
	// checkout, the customer portal AND the storefront at once, with test and
	// live held separately. Branding from here would cover one of those three
	// and disagree with the other two the first time somebody used the page.

	// ONE language for the whole window.
	//
	// Dodo's frame localises itself and WordPress localises our labels, and
	// nothing made the two agree: a German page could carry a German summary
	// beside an English checkout, or the reverse, depending on a visitor's
	// browser. This points Dodo at the site's locale so both halves follow one
	// decision. An unknown locale is left out rather than guessed -- without the
	// field Dodo chooses, which is better than us choosing wrongly.
	// Whatever the request carried, and nothing else.
	//
	// The site locale is deliberately NOT a fallback. It describes the shop --
	// one answer where a shop selling a German edition and an English one has
	// two -- and on the operator's install it read en_US while the customer was
	// reading German. The caller sends the shortcode's language or the browser's;
	// with neither, the field is omitted and Dodo decides, which is a better
	// guess than ours.
	$language = ( null !== $lang && 1 === preg_match( '/^[a-z]{2}$/i', $lang ) )
		? strtolower( $lang )
		: '';
	if ( '' !== $language ) {
		$body['customization'] = array( 'force_language' => $language );
	}

	/**
	 * Who is buying, when the site already knows.
	 *
	 * Empty by default and by design: a WordPress shop has no idea who an
	 * anonymous visitor is, and guessing is how somebody's checkout gets filled
	 * with somebody else's name.
	 *
	 * It exists because the answer is coming. UnleashWP's account service --
	 * magic link, no password, edit your details once -- issues a session on
	 * `.unleash-wp.com`, and a site that can read it knows the email and name
	 * before the customer types anything. Handed to Dodo here, the contact step
	 * arrives filled in, which is the step this checkout has been fighting all
	 * day.
	 *
	 * A filter rather than a setting, so the identity source stays outside this
	 * plugin. It sells things; it should not also be an account system.
	 *
	 *   add_filter( 'wpdc_customer', fn() => array(
	 *       'email' => $session->email,
	 *       'name'  => $session->name,
	 *   ) );
	 */
	$customer = apply_filters( 'wpdc_customer', null );
	if ( is_array( $customer ) && is_string( $customer['email'] ?? null ) && is_email( $customer['email'] ) ) {
		$body['customer'] = array( 'email' => $customer['email'] );
		if ( is_string( $customer['name'] ?? null ) && '' !== trim( $customer['name'] ) ) {
			$body['customer']['name'] = trim( $customer['name'] );
		}
	}

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
