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
function wpdc_format_price( ?int $minor, string $currency ): string {
	if ( null === $minor ) {
		return __( 'no price set', 'wp-dodo-checkout' );
	}
	return number_format_i18n( $minor / 100, 2 ) . ( '' !== $currency ? ' ' . $currency : '' );
}

/**
 * The site's language as a two-letter code, for Dodo.
 *
 * WordPress locales look like `de_DE` or `pt_BR`; Dodo wants the language part.
 * An unrecognisable locale yields an empty string, and an empty string is left
 * OUT of the request rather than sent -- forcing a language we are not sure of
 * is worse than letting Dodo choose, which is what it does without the field.
 */
function wpdc_language(): string {
	$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
	return 1 === preg_match( '/^([a-z]{2})(?:[_-]|$)/i', (string) $locale, $m ) ? strtolower( $m[1] ) : '';
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
