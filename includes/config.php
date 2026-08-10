<?php
/**
 * Where the settings come from, and why a constant beats the database.
 *
 * This plugin talks to Dodo Payments directly. There is no intermediate server:
 * a shortcode names a Dodo product id, the client checks that id against the
 * live product list, and a checkout session comes back.
 *
 * ── The API key, and the risk that is being accepted on purpose ─────────────
 *
 * Dodo has no scoped API keys. The Owner / Editor / Viewer roles govern
 * DASHBOARD users, not keys, so the value below can create payments, issue
 * refunds and read every customer on the account. Checked against their docs,
 * not assumed.
 *
 * A site owner accepts that when they install any payment plugin, and it is
 * the same trade WooCommerce makes with Stripe. What this file can do is make
 * the trade as small as possible:
 *
 *   - a wp-config.php constant wins over the option, so the key can stay out of
 *     the database entirely -- and database dumps travel: backups, staging
 *     copies, a support request with an export attached
 *   - the key is never written to a log, never echoed, never sent to a browser
 *   - it is used in exactly one function, in client.php
 *
 * If the key must live in the database, rotate it the day the site is exported.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A visitor is waiting on a page. Long enough for a slow API, short enough to fail. */
const WPDC_TIMEOUT = 15;

/**
 * How often one checkout may ask whether it finished.
 *
 * Named here rather than written into the route because it is half of a pair:
 * the browser's own budget (POLL_TRIES in assets/checkout.js) must stay BELOW
 * it, and tests/run.php reads both and fails when they cross. Cross them and a
 * paying customer is throttled into the give-up panel and told their order
 * could not be confirmed -- for an order that succeeded. That is the sentence
 * this whole ceiling exists to avoid causing, so it must not be the thing the
 * ceiling produces.
 *
 * The browser asks 78 times across five minutes. The margin above that absorbs
 * a retry after a dropped connection without touching the ceiling.
 */
const WPDC_POLL_CEILING = 120;

/**
 * How long the product list is reused.
 *
 * The list IS the allow-list, and it changes when the shop owner edits Dodo --
 * not when a visitor clicks. Ten minutes means a new product is sellable within
 * ten minutes of being created, with no deploy and no settings to edit, and a
 * busy page costs one API call per ten minutes rather than one per visitor.
 */
const WPDC_CATALOG_TTL = 600;

/**
 * The cache key carries the plugin version, and that is not cosmetic.
 *
 * The cached catalogue is a SHAPE, not just data. Adding or removing a field
 * leaves every site that updates reading rows written by the previous version,
 * which is ten minutes of "Undefined array key" on a customer-facing page. It
 * happened, on a field added and then taken away again, and it was caught in a
 * browser rather than by a test -- a test builds its own cache and never meets a
 * stale one.
 *
 * Versioning the key means an update invalidates it by construction. The old
 * entry is not deleted; it expires on its own within the TTL.
 */
function wpdc_catalog_key(): string {
	return 'wpdc_catalog_' . WPDC_VERSION;
}

/**
 * The shape of a Dodo product id, in one place because three callers need it.
 *
 * The shortcode checks it so a typo says so in the editor, the REST route
 * checks it so a value from a browser does not reach an outbound HTTP request
 * unexamined, and the client checks it so a lookup cannot be handed something
 * arbitrary. None of the three is the security boundary -- that is the
 * allow-list in client.php. These only reject what is not even shaped like an
 * id, which is cheaper than asking Dodo about it.
 */
function wpdc_is_product_id( $value ): bool {
	return is_string( $value ) && 1 === preg_match( '/^pdt_[A-Za-z0-9]{1,64}$/', $value );
}

/**
 * Minor units to something readable, said plainly when there is no price.
 *
 * Two callers: the settings catalogue, and the item line a customer reads in
 * the checkout window. It has moved between here and settings.php twice as that
 * second caller came and went; it stays here while both exist.
 */
function wpdc_format_price( ?int $minor, string $currency, ?string $lang = null ): string {
	// Named by the caller when the caller knows better. The price shortcode
	// does: it carries a `lang` attribute so a shop can price a German edition
	// and an English one from pages nothing else can tell apart.
	$lang = null !== $lang && '' !== $lang ? $lang : wpdc_page_language();
	if ( null === $minor ) {
		return __( 'no price set', 'wp-dodo-checkout' );
	}

	$digits = wpdc_currency_digits( $currency );
	$amount = $minor / ( 10 ** $digits );

	if ( '' === $currency ) {
		return number_format_i18n( $amount, $digits );
	}

	/*
	 * A symbol, not a code, because a customer reads this.
	 *
	 * It used to append the currency code, which was right for the one caller it
	 * had -- an admin catalogue listing products. Then the sales page started
	 * rendering it, and "24,99 EUR" appeared where "24,99 €" had been. The
	 * script replaces it within a moment, but the server's version is what shows
	 * first, what a cached page keeps, and what somebody with JavaScript off
	 * reads for good.
	 *
	 * PHP's Intl is an OPTIONAL extension and plenty of shared hosting is
	 * without it, so it is used when present and never depended on.
	 */
	if ( class_exists( 'NumberFormatter' ) ) {
		$fmt = new NumberFormatter( wpdc_locale_for( $lang ), NumberFormatter::CURRENCY );
		$out = $fmt->formatCurrency( $amount, strtoupper( $currency ) );
		if ( is_string( $out ) && '' !== $out ) {
			return $out;
		}
	}

	return wpdc_format_price_basic( $amount, $currency, $digits, $lang );
}

/**
 * The same price without PHP's Intl extension, which plenty of hosting lacks.
 *
 * Its own function because a branch that only runs where a library is MISSING
 * is a branch that never runs on the machine writing the tests. Kept inline, it
 * survived a mutation that made every locale print the symbol in front -- the
 * check passed through NumberFormatter and never reached this code at all.
 */
function wpdc_format_price_basic( float $amount, string $currency, int $digits, string $lang ): string {
	// The symbol for the currencies this shop actually prices in, and the code
	// for anything else. A code is plain but never wrong, which is the right way
	// round for money.
	$symbols = array(
		'EUR' => '€',
		'USD' => '$',
		'GBP' => '£',
		'JPY' => '¥',
		'INR' => '₹',
		'BRL' => 'R$',
		'CHF' => 'CHF',
		// The dollars that are not THE dollar keep their letters, because an
		// Australian shown a bare $ has been told the American price.
		'CAD' => 'CA$',
		'AUD' => 'A$',
		'NZD' => 'NZ$',
		'SGD' => 'S$',
		'MXN' => 'MX$',
	);

	$symbol = $symbols[ strtoupper( $currency ) ] ?? strtoupper( $currency );
	$number = number_format_i18n( $amount, $digits );

	// German writes the symbol after the number, English before it. Two rules
	// because the shop speaks two languages -- the same reason wpdc_locale_for
	// lists two locales and not forty.
	return 'de' === $lang ? $number . ' ' . $symbol : $symbol . $number;
}

/**
 * How many decimal places a currency has, because not every currency has two.
 *
 * Dodo stores every amount in the SMALLEST unit of its currency, and for the yen
 * that unit is the yen itself. Dividing by a hundred is right for the euro and
 * wrong for eight currencies, and the direction of the error is a hundredfold
 * discount rather than a rounding difference.
 *
 * Not academic: at the time of writing, the yen price on both books is stored as
 * `24`, which this function correctly reads as twenty-four yen. Fourteen cents.
 * The old divide-by-a-hundred would have printed "0,24 JPY" and hidden a real
 * mistake behind a plausible-looking number.
 *
 * The list is ISO 4217's zero- and three-decimal currencies. It is short because
 * it is finite -- currencies do not appear weekly -- and anything unlisted is two
 * decimals, which is the overwhelming majority and the right default.
 */
function wpdc_currency_digits( string $currency ): int {
	$upper = strtoupper( $currency );

	$none = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
	if ( in_array( $upper, $none, true ) ) {
		return 0;
	}

	$three = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );
	if ( in_array( $upper, $three, true ) ) {
		return 3;
	}

	return 2;
}

/**
 * Where the visitor is, according to the only party in the chain that knows.
 *
 * Cloudflare sits in front of this site and can be told to stamp `CF-IPCountry`
 * on every request. That header is the whole geo story here, and the reasons for
 * not doing it any other way are worth writing down once:
 *
 *   - Dodo's own `/api/country` answers by IP and would be the ideal source,
 *     since it is the same judgement the checkout will make. It sends no
 *     `access-control-allow-origin`, so a browser cannot read it, and calling it
 *     from PHP would geolocate THIS SERVER rather than the visitor. Measured,
 *     not assumed.
 *   - `navigator.language` is not location. A German on holiday in Thailand
 *     still says `de-DE` and is still charged in whatever currency Dodo picks
 *     from his address.
 *   - A third-party lookup service means a request per visitor, a consent
 *     question, and one more thing that can be down.
 *
 * Empty when the header is absent, and an empty country means the base price.
 * That is the honest failure: the shop shows what it has always shown.
 */
function wpdc_visitor_country(): string {
	$raw = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';
	if ( ! is_string( $raw ) ) {
		return '';
	}
	$code = strtoupper( trim( $raw ) );

	// Cloudflare sends `XX` for anonymising proxies and `T1` for Tor. Neither is
	// a country, and treating them as one would map them to nothing anyway --
	// rejecting them here says so out loud.
	if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) || 'XX' === $code || 'T1' === $code ) {
		return '';
	}

	return $code;
}


/**
 * A two-letter language to the locale WordPress names its catalogues with.
 *
 * `de` is `de_DE`, everything else is `en_US`. Deliberately a short list rather
 * than a guess like `xx_XX`, which names a catalogue that does not exist and
 * leaves the labels English beside a checkout that is not.
 *
 * This block spent a while stranded above the wrong function, which is how a
 * comment stops being documentation and starts being noise.
 */
function wpdc_locale_for( string $lang ): string {
	// Two, because the shop speaks two. A longer list would name catalogues
	// that do not exist and leave the labels English beside a checkout that is
	// not.
	return 'de' === $lang ? 'de_DE' : 'en_US';
}

/**
 * Two languages, and everyone lands in one of them.
 *
 * The shop sells to an international audience and speaks German and English.
 * Passing a visitor's own language straight through meant a French browser got
 * a French checkout beside English labels -- worse than either language alone,
 * because it looks like something broke rather than like a shop that speaks two
 * languages.
 *
 * German-speaking locales (de, de-AT, de-CH) get German. Everyone else gets
 * English, which is the language a shop reaches furthest with when it cannot
 * offer a visitor's own.
 */
function wpdc_two_languages( string $tag ): string {
	return 0 === stripos( $tag, 'de' ) ? 'de' : 'en';
}

/**
 * The language for a render that happens before any JavaScript runs.
 *
 * The labels beside Dodo's frame -- Subtotal, VAT, Total -- are produced by PHP,
 * and PHP cannot ask `navigator.language`. Falling back to the site locale put
 * English labels beside a German checkout on a shop whose WordPress says en_US.
 *
 * `Accept-Language` is the same information one step earlier: the browser sends
 * it with the request that renders the page. Only the first tag is read and
 * only the first two letters of it, because everything collapses to two
 * languages anyway.
 *
 * KNOWN LIMIT, worth stating rather than discovering: a full-page cache serves
 * one render to everybody, so a cached page keeps whichever language the first
 * visitor had. A shop that adds page caching wants `Vary: Accept-Language` or a
 * cache key that includes it.
 */
function wpdc_request_language(): string {
	$header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
	if ( ! is_string( $header ) || '' === $header ) {
		return '';
	}
	$first = strtok( $header, ',;' );
	return is_string( $first ) ? wpdc_two_languages( trim( $first ) ) : '';
}

/**
 * The language of the PAGE, which is not the language of whoever asked for it.
 *
 * `wpdc_request_language` reads `Accept-Language`, and for something rendered
 * inside a dialog after a click that is close enough. For a price printed into
 * the page body it is wrong twice over:
 *
 *   - A German page read by an English browser rendered "€24.99" beside German
 *     prose. Measured on the live site, which is how this was found.
 *   - Under any full-page cache the first visitor's language is frozen into the
 *     HTML and served to everybody after them.
 *
 * Polylang knows the answer and it is not a guess: it serves each language on
 * its own URL, so its answer is a property of the page rather than of the
 * request -- and a cache keyed on the URL is therefore keyed on the language
 * too. Without Polylang the header remains the best available signal, with the
 * limit named above.
 */
function wpdc_page_language(): string {
	if ( function_exists( 'pll_current_language' ) ) {
		$slug = pll_current_language( 'slug' );
		if ( is_string( $slug ) && '' !== $slug ) {
			return wpdc_two_languages( $slug );
		}
	}
	return wpdc_request_language();
}

/**
 * The shape a discount code may have.
 *
 * Checked because this one comes from a CUSTOMER -- it is the only value in the
 * whole plugin that does -- and it reaches an outbound API call. Dodo decides
 * whether a code is valid; this only decides whether it is worth asking.
 */
function wpdc_is_discount_code( $value ): bool {
	return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $value );
}

/**
 * The shape of a Dodo checkout session id.
 *
 * Checked because this one comes back from a BROWSER, on the status route, and
 * is pasted straight into an outbound URL path. Dodo decides whether a session
 * exists; this only decides whether it is worth asking, and keeps anything with
 * a slash in it out of the path.
 */
function wpdc_is_session_id( $value ): bool {
	return is_string( $value ) && 1 === preg_match( '/^cks_[A-Za-z0-9]{1,64}$/', $value );
}

/**
 * Is this an error rather than a result?
 *
 * Every function in the client answers either a result or `wpdc_error()`, and
 * ten call sites across five files spelled the same test by hand. The failure
 * is a TYPE in everything but name, and a hand-written test is a place to get
 * the strict comparison wrong once and read a failure as a success.
 */
function wpdc_is_error( $value ): bool {
	return is_array( $value ) && isset( $value['ok'] ) && false === $value['ok'];
}

function wpdc_api_key_is_constant(): bool {
	return defined( 'WPDC_API_KEY' ) && is_string( WPDC_API_KEY ) && '' !== trim( WPDC_API_KEY );
}

function wpdc_api_key(): string {
	$value = wpdc_api_key_is_constant()
		? WPDC_API_KEY
		: (string) get_option( 'wpdc_api_key', '' );

	return trim( $value );
}

/**
 * `live_mode` or `test_mode`, and it defaults to TEST.
 *
 * Defaulting to live would mean a half-finished setup takes real money from a
 * real card. Defaulting to test means a half-finished setup takes nothing and
 * says so, which is the direction a mistake should fall.
 */
function wpdc_mode(): string {
	$value = defined( 'WPDC_MODE' ) && is_string( WPDC_MODE )
		? WPDC_MODE
		: (string) get_option( 'wpdc_mode', '' );

	return 'live_mode' === trim( $value ) ? 'live_mode' : 'test_mode';
}

/** Dodo's API host for the current mode. */
function wpdc_api_base(): string {
	return 'live_mode' === wpdc_mode()
		? 'https://live.dodopayments.com'
		: 'https://test.dodopayments.com';
}

/**
 * True when a checkout could actually be created.
 *
 * Checked before anything is rendered. A button that opens a checkout which
 * cannot be created is worse than no button: the visitor has already decided to
 * buy by the time it fails.
 */
function wpdc_is_configured(): bool {
	return '' !== wpdc_api_key();
}
