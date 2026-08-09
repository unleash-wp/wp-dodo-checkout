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
		//
		// Once a minute, not once a call. This used to be reached only by a
		// click; the status route made it reachable from a polling loop, and a
		// shop whose key has been rotated would write thirty identical lines
		// per attempted checkout, from any caller. The thirtieth says nothing
		// the first did not, and a log that fills up is a log nobody reads.
		if ( false === get_transient( 'wpdc_logged_refusal' ) ) {
			set_transient( 'wpdc_logged_refusal', 1, MINUTE_IN_SECONDS );
			error_log( 'wp-dodo-checkout: the Dodo API key was refused (' . $status . ')' );
		}
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
	if ( wpdc_is_error( $result ) ) {
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
function wpdc_create_session( string $product, int $quantity = 1, ?string $bump = null, ?string $lang = null, ?string $discount = null ): array {
	if ( ! wpdc_is_configured() ) {
		return wpdc_error(
			'not_configured',
			false,
			__( 'Checkout is not configured on this site.', 'wp-dodo-checkout' )
		);
	}

	$catalog = wpdc_catalog();
	if ( wpdc_is_error( $catalog ) ) {
		return $catalog;
	}

	// A product the catalogue does not know is refused BEFORE any session is
	// created, and refreshed once first: the owner may have created it a minute
	// ago, and making them wait out a cache reads as the plugin being broken.
	if ( ! isset( $catalog[ $product ] ) ) {
		$catalog = wpdc_catalog( true );
		if ( wpdc_is_error( $catalog ) ) {
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

			/**
			 * Leave through the front door, not through their status page.
			 *
			 * Their frame ends a finished order by posting `checkout.redirect`,
			 * and their SDK does `window.location.href = redirect_to` on it. So
			 * the last screen of a purchase is decided HERE, not in our
			 * JavaScript -- and without this flag it is a page on
			 * checkout.dodopayments.com that this shop never wrote.
			 */
			'redirect_immediately'          => true,
		),
	);

	/*
	 * The language the buyer was READING, written into the order itself.
	 *
	 * Not decoration: it is the only signal the order mail has for choosing
	 * German or English. Without it the mail falls back to the billing country,
	 * so an English page selling to a German address sent a German mail --
	 * reported after a real order placed with `lang="en"` on the shortcode.
	 *
	 * Metadata survives the round trip: Dodo returns it on the webhook, where
	 * nothing else about the page the customer stood on is left.
	 */
	if ( '' !== (string) $lang ) {
		$body['metadata'] = array( 'uwp_lang' => (string) $lang );
	}

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
	// Always one of two, never the visitor's own if it is neither. A French
	// browser used to get a French checkout beside English labels, which reads
	// as a fault rather than as a shop that speaks two languages.
	$language = ( null !== $lang && '' !== trim( $lang ) )
		? wpdc_two_languages( trim( $lang ) )
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
	 * It exists because a site that already knows its visitor -- a membership
	 * plugin, a logged-in user, any session it can read -- has the email and the
	 * name before the customer types anything. Handed to Dodo here, the contact
	 * step arrives filled in, and that step is the longest thing between a
	 * decision to buy and the money.
	 *
	 * A filter rather than a setting, so the identity source stays outside this
	 * plugin entirely. This plugin sells things; it is not an account system and
	 * has no opinion about which one you run.
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

	/**
	 * A discount code, when the customer typed one.
	 *
	 * Dodo's own discount FIELD lives in their order summary, and inline mode
	 * does not render a summary -- integrators are asked to build one, which is
	 * what our panel is. The control went with the summary and was not moved
	 * into the form, so an inline checkout has nowhere to type a code. Measured:
	 * the same session opened on their hosted page shows "Haben Sie einen
	 * Rabattcode?"; embedded it does not.
	 *
	 * So the field is ours and the code rides on the session. Dodo validates it
	 * -- an unknown or expired code makes this request fail, which is how the
	 * customer finds out.
	 */
	if ( null !== $discount && wpdc_is_discount_code( $discount ) ) {
		$body['discount_codes'] = array( $discount );
	}

	/**
	 * Dodo is always told where "done" leads. Nowhere is not a neutral default.
	 *
	 * This used to be sent only when WPDC_RETURN_URL was defined, and on an
	 * install where nobody defined it the field simply never went. That reads
	 * like a missing nicety and is not one: their frame leaves itself by posting
	 * `checkout.redirect` with a destination, and with no destination the event
	 * carries nothing to navigate to.
	 *
	 * Measured on a cart discounted to zero, which is where it stops being
	 * cosmetic. There is no payment to collect, so their payment step has no
	 * method to render and their own frontend 404s fetching `.../payment-link`.
	 * The frame then sits in its skeleton for good -- while the order is
	 * ALREADY `succeeded` on their side (total 0, `payment_method: null`).
	 * The customer pays nothing, receives everything, and is told neither.
	 *
	 * Sent ONLY when somebody configured one. home_url() was tried as a floor
	 * and it is worse than nothing: their SDK navigates on `checkout.redirect`,
	 * so a paying customer was thrown to the front page the instant they
	 * finished -- no download, no key, no word about the mail, on a page that
	 * says nothing about the purchase. With no destination their frame stays
	 * put, and the shop finishes the order itself where the customer already is.
	 */
	$return = wpdc_return_url();
	if ( '' !== $return ) {
		$body['return_url'] = $return;
	}

	/**
	 * Without this Dodo shows no back control at all.
	 *
	 * Their own note on the field: "The URL to redirect the customer if they
	 * cancel or go back from the checkout. If not provided, the back button will
	 * not be displayed." So a customer on the payment step could not return to
	 * check the address they had typed -- they could only close the window and
	 * start again.
	 *
	 * Same-origin like the return url, and never taken from the request: a
	 * cancel target a visitor can choose turns this into an open redirect on the
	 * shop's own domain.
	 */
	$body['cancel_url'] = '' !== $return ? $return : home_url();

	$result = wpdc_dodo_request( 'POST', '/checkouts', $body );
	if ( wpdc_is_error( $result ) ) {
		// A refused code is the customer's typo, not our outage, and it must not
		// be reported as one. Anything that failed WITH a code and would have
		// worked without is theirs to correct -- the generic "try again in a
		// moment" would have them trying the same wrong code all day.
		if ( null !== $discount && ! $result['retriable'] ) {
			return wpdc_error(
				'discount_rejected',
				false,
				__( 'That code was not accepted.', 'wp-dodo-checkout' )
			);
		}
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

	$session = is_string( $result['session_id'] ?? null ) ? $result['session_id'] : '';

	return array( 'ok' => true, 'checkout_url' => $url, 'session_id' => $session );
}

/**
 * Did this checkout finish?
 *
 * Asked because one checkout finishes without ever saying so. A cart discounted
 * to zero has no payment to collect, and Dodo's frame renders the payment step
 * anyway: it fetches a payment link that does not exist for a zero total, takes
 * a 404, and stops. No `checkout.redirect`, no error, nothing their SDK reacts
 * to -- while the order is already `succeeded` on their side. Giving them a
 * return_url does not help, because nothing on that screen ever gets far enough
 * to use one.
 *
 * So the shop asks instead of waiting to be told. One call, one question:
 * `GET /checkouts/{id}` answers `payment_status`.
 *
 * WHAT COMES BACK TO THE BROWSER. This paragraph used to say "a boolean and
 * nothing else", and that stopped being true the moment the download links and
 * the licence key were added below. It now returns `finished`, `files` and
 * `keys` -- the same things Dodo puts in its mail -- which makes the session id
 * a capability rather than a lookup, and is why the route that reads this one
 * takes it in a POST body instead of a URL.
 *
 * What still never leaves is the customer's NAME and EMAIL. They ride along on
 * every upstream response read here, the route is public, and nothing about
 * confirming a purchase needs them.
 */
function wpdc_session_finished( string $session ): array {
	if ( ! wpdc_is_session_id( $session ) ) {
		return wpdc_error( 'bad_session', false, __( 'That checkout could not be found.', 'wp-dodo-checkout' ) );
	}

	$result = wpdc_dodo_request( 'GET', '/checkouts/' . rawurlencode( $session ), null );
	if ( wpdc_is_error( $result ) ) {
		return $result;
	}

	$status = is_string( $result['payment_status'] ?? null ) ? $result['payment_status'] : '';

	// Only `succeeded` is done. Everything else -- pending, failed, absent --
	// means keep waiting, because sending somebody to a thank-you page for a
	// payment that has not succeeded is the one wrong answer here.
	if ( 'succeeded' !== $status ) {
		return array( 'ok' => true, 'finished' => false );
	}

	$payment = is_string( $result['payment_id'] ?? null ) ? $result['payment_id'] : '';

	return array( 'ok' => true, 'finished' => true ) + wpdc_payment_goods( $payment );
}

/**
 * What this payment bought, as things a customer can hold.
 *
 * Dodo's grants are the delivery: a Digital Files entitlement puts signed
 * download URLs on the grant, a License Keys entitlement puts the key there.
 * There is no grants-by-payment call, so this walks the only path there is --
 * payment to customer, customer to grants, grants filtered back to THIS
 * payment. Anything another purchase delivered to the same customer is dropped
 * on that filter: the session in hand proves this payment, not their history.
 *
 * Empty arrays are a normal answer, not a failure. A product with no
 * entitlement attached delivers nothing, and the panel simply shows less. And
 * a failure anywhere here is ALSO the empty answer: the order finished, and
 * "finished but the goods list could not be read" must not read as "not
 * finished" to a poll that would then never stop.
 */
function wpdc_payment_goods( string $payment ): array {
	$none = array(
		'files' => array(),
		'keys'  => array(),
	);
	if ( '' === $payment ) {
		return $none;
	}

	$paid = wpdc_dodo_request( 'GET', '/payments/' . rawurlencode( $payment ), null );
	if ( wpdc_is_error( $paid ) ) {
		return $none;
	}
	$customer = $paid['customer']['customer_id'] ?? null;
	if ( ! is_string( $customer ) || '' === $customer ) {
		return $none;
	}

	// KNOWN LIMIT, stated rather than discovered: one page. A customer with more
	// than a hundred grants could have this payment's grant fall off it, and the
	// panel would quietly show nothing. Two products and no repeat buyers make
	// that impossible today; a shop with a catalogue wants paging here.
	$grants = wpdc_dodo_request(
		'GET',
		'/customers/' . rawurlencode( $customer ) . '/entitlement-grants?page_size=100',
		null
	);
	if ( wpdc_is_error( $grants ) ) {
		return $none;
	}

	$files = array();
	$keys  = array();
	foreach ( ( $grants['items'] ?? array() ) as $grant ) {
		if ( ! is_array( $grant ) || ( $grant['payment_id'] ?? '' ) !== $payment ) {
			continue;
		}
		// Case-insensitive, because their own documentation spells this status
		// two ways: `Delivered` on this endpoint and `delivered` on
		// /customers/{id}/entitlements. An exact compare that guesses wrong
		// shows an empty panel for ever, with no error anywhere -- and nobody
		// could tell that apart from "no entitlement attached yet".
		if ( 0 !== strcasecmp( 'delivered', (string) ( $grant['status'] ?? '' ) ) ) {
			continue;
		}
		/**
		 * A link the shop hosts, not a copy Dodo keeps.
		 *
		 * Their `digital_files` entitlement takes an `external_url` beside the
		 * uploaded files, and that is the shape this shop wants: the ZIP lives
		 * behind Cloudflare, so a buyer's link resolves to the current build
		 * rather than to whatever was uploaded on the day they bought. Dodo's
		 * own note on uploaded files is explicit that replacing one does not
		 * reach downloads already issued.
		 *
		 * Read here because it arrives in the same place as the files and would
		 * otherwise be dropped -- a purchase set up this way would deliver
		 * correctly by mail and show nothing at all in the popup.
		 */
		$external = $grant['digital_product_delivery']['external_url'] ?? '';
		if ( is_string( $external ) && str_starts_with( $external, 'https://' ) ) {
			/**
			 * REMEMBERED, not resolved -- and that is the fix for a live bug.
			 *
			 * Turning this into a direct link needs the licence key, and the key
			 * is on a DIFFERENT GRANT. One product carries two entitlements: a
			 * `license_key` one and a `digital_files` one, each arriving as its
			 * own row. Reading `$grant['license_key']` from the file row gives
			 * nothing, every time.
			 *
			 * So the first version asked for a link with an empty key, got
			 * refused, and silently fell back to the page -- which is exactly
			 * what the operator kept seeing:
			 *   href="https://downloads.unleash-wp.com/#k=6c70bf62-..."
			 *
			 * The grants are walked to the end first. The link is built below,
			 * once every key of this payment is known.
			 */
			$hosted_url          = $external;
			$hosted_instructions = (string) ( $grant['digital_product_delivery']['instructions'] ?? '' );
		}
		$hosted = isset( $hosted_url ) && $hosted_url === $external;

		// A hosted link wins outright, and only over the uploaded files -- a
		// `continue` here would skip this grant's licence key too. Offering the
		// upload BESIDE the link would hand the buyer two, one of which is the
		// build that was current the day it was uploaded: the exact staleness
		// the hosted link exists to avoid.
		//
		// "Wins" means a link we ACCEPTED, not merely one that was present.
		// Testing `'' === $external` gave an http:// entitlement the power to
		// suppress the uploads while being rejected itself two lines above, so
		// the grant delivered nothing at all. A link that did not pass is not a
		// better link, it is no link.
		$uploaded = $hosted ? array() : ( $grant['digital_product_delivery']['files'] ?? array() );

		foreach ( $uploaded as $file ) {
			$url  = $file['download_url'] ?? '';
			$name = $file['filename'] ?? '';
			// Their origin and their scheme, checked rather than trusted: these
			// travel to a browser and become an href.
			if ( is_string( $url ) && is_string( $name ) && '' !== $name && str_starts_with( $url, 'https://' ) ) {
				$files[] = array(
					'name'      => $name,
					'url'       => $url,
					// Signed by Dodo and already personal. Appending a key here
					// would put it in an address belonging to somebody else's
					// service for no gain.
					'needs_key' => false,
				);
			}
		}
		$key = $grant['license_key']['key'] ?? '';
		if ( is_string( $key ) && '' !== $key ) {
			$keys[] = $key;
		}
	}

	/**
	 * Now, and only now, the hosted link can become the file.
	 *
	 * Both halves are known here and nowhere earlier: the address comes from the
	 * `digital_files` grant, the licence key from the `license_key` one, and
	 * Dodo delivers them as separate rows in whichever order it likes. Building
	 * the link inside the loop meant asking with whatever half had arrived --
	 * which was an empty key, every time, on every order.
	 *
	 * Best effort by construction. Any doubt leaves the page link standing, and
	 * the customer reaches the file in two clicks rather than none: one more
	 * click is a worse afternoon, a broken link is a refund.
	 */
	if ( isset( $hosted_url ) ) {
		$direct = wpdc_direct_link( $hosted_url, (string) ( $keys[0] ?? '' ) );
		$files[] = '' !== $direct
			? array(
				'name'      => '',
				'url'       => $direct,
				// Signed, personal and complete. Nothing to append.
				'needs_key' => false,
			)
			: array(
				'name'      => $hosted_instructions ?? '',
				'url'       => $hosted_url,
				// The page, and it needs the key to show anything. The browser
				// attaches it after the '#', which no server ever sees.
				'needs_key' => true,
			);
	}

	return array(
		'files' => $files,
		'keys'  => $keys,
	);
}

/**
 * Where Dodo sends the buyer afterwards. Always a URL on THIS site.
 *
 * Never taken from the request. A return_url a visitor can choose turns this
 * into an open redirect on the shop's own domain.
 */
/**
 * Where a licence key can be redeemed for the file it bought.
 *
 * A different origin on purpose, and the one place in this plugin where that
 * is right: the download host is the shop's delivery surface, this is its sales
 * surface, and the two are separate so that a public page serving files holds
 * none of the credentials a checkout needs.
 *
 * Unset means the completion panel shows the key and no button. That is a
 * shop that has not finished setting up, not a broken one -- the key is still
 * correct and Dodo's own mail still names it.
 *
 * https only. This ends up as an href a buyer clicks straight after paying,
 * and it carries their key in the fragment; plain http would put that on the
 * wire in the clear.
 */
/**
 * A link that IS the file, asked of the shop's own download host.
 *
 * Two calls, both from this server with the buyer's key: what that key bought,
 * and then a signed URL for it. The customer gets a plain href and their
 * download starts on the click.
 *
 * ── Empty string on every doubt ─────────────────────────────────────────────
 *
 * Best effort, and the caller falls back to the page. There is no error path
 * that improves a customer's afternoon: one more click is worse than none, and
 * a broken link is a refund.
 *
 * ── Why only one file ───────────────────────────────────────────────────────
 *
 * With two, there is no way to know which the button means, and minting for
 * the first would be a guess. The page shows both and lets somebody choose,
 * which is what a page is good at.
 */
function wpdc_direct_link( string $page, string $key ): string {
	if ( '' === $key ) {
		return '';
	}
	$origin = wp_parse_url( $page, PHP_URL_SCHEME ) . '://' . wp_parse_url( $page, PHP_URL_HOST );
	if ( ! str_starts_with( $origin, 'https://' ) ) {
		return '';
	}

	$headers = array( 'Authorization' => 'Bearer ' . $key );

	$listed = wp_remote_get( $origin . '/artifacts', array( 'timeout' => WPDC_TIMEOUT, 'headers' => $headers ) );
	if ( is_wp_error( $listed ) || 200 !== wp_remote_retrieve_response_code( $listed ) ) {
		return '';
	}
	$body = json_decode( wp_remote_retrieve_body( $listed ), true );
	$artifacts = is_array( $body ) && isset( $body['artifacts'] ) && is_array( $body['artifacts'] )
		? $body['artifacts']
		: array();
	if ( 1 !== count( $artifacts ) || ! is_string( $artifacts[0] ) ) {
		return '';
	}

	$minted = wp_remote_get(
		$origin . '/link?artifact=' . rawurlencode( $artifacts[0] ),
		array( 'timeout' => WPDC_TIMEOUT, 'headers' => $headers )
	);
	if ( is_wp_error( $minted ) || 200 !== wp_remote_retrieve_response_code( $minted ) ) {
		return '';
	}
	$link = json_decode( wp_remote_retrieve_body( $minted ), true );
	$url  = is_array( $link ) && isset( $link['url'] ) ? $link['url'] : '';

	// Their origin and their scheme, checked rather than trusted: this becomes
	// an href on a page that has just taken money.
	return is_string( $url ) && str_starts_with( $url, $origin . '/' ) ? $url : '';
}

function wpdc_download_url(): string {
	if ( defined( 'WPDC_DOWNLOAD_URL' ) && is_string( WPDC_DOWNLOAD_URL ) ) {
		$configured = trim( WPDC_DOWNLOAD_URL );
		if ( str_starts_with( $configured, 'https://' ) ) {
			return $configured;
		}
	}
	return '';
}

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
