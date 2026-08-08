<?php
/**
 * Where the settings come from, and why a constant beats the database.
 *
 * This plugin talks to Dodo Payments directly. There is no intermediate server
 * and nothing here knows about any other product: a shortcode names a plan key,
 * Dodo says which product carries it, and a checkout session comes back.
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

/** Where a product's plan key lives in its Dodo metadata. */
const WPDC_PLAN_KEY = 'uwp_plan';

/**
 * How long the product list is reused.
 *
 * The list is what turns a plan key into a product id, and it changes when the
 * shop owner edits Dodo -- not when a visitor clicks. Ten minutes means a new
 * product is sellable within ten minutes of being created, with no deploy and
 * no settings screen, and a busy page costs one API call per ten minutes rather
 * than one per visitor.
 */
const WPDC_CATALOG_TTL = 600;

/**
 * The shape a plan key may have, in one place because two callers need it.
 *
 * The REST route checks it so a shortcode typo says so immediately rather than
 * spending a round trip to find out, and the client checks it so a value read
 * from Dodo's metadata cannot reach a lookup unexamined. Neither is redundant:
 * they guard different directions.
 */
function wpdc_is_plan_key( $value ): bool {
	return is_string( $value ) && 1 === preg_match( '/^[a-z0-9_]{1,64}$/', $value );
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
