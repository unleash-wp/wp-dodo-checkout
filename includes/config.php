<?php
/**
 * Where the settings come from, and why a constant beats the database.
 *
 * Same rule as lumo-wp's pro-client: a wp-config.php constant wins over the
 * stored option, so a site can keep its shared secret out of the database
 * entirely. That matters more here than it does for a licence key -- a
 * database dump containing this secret lets the holder mint checkout sessions
 * against the payment account, and database dumps travel: backups, staging
 * copies, a support request with an export attached.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How long to wait on the checkout server before calling it unreachable. */
const UWP_CHECKOUT_TIMEOUT = 15;

function uwp_checkout_endpoint_is_constant(): bool {
	return defined( 'UWP_CHECKOUT_ENDPOINT' ) && is_string( UWP_CHECKOUT_ENDPOINT );
}

function uwp_checkout_secret_is_constant(): bool {
	return defined( 'UWP_CHECKOUT_SECRET' ) && is_string( UWP_CHECKOUT_SECRET );
}

/** Base URL of the server that mints sessions, without a trailing slash. */
function uwp_checkout_endpoint(): string {
	$value = uwp_checkout_endpoint_is_constant()
		? UWP_CHECKOUT_ENDPOINT
		: (string) get_option( 'uwp_checkout_endpoint', '' );

	return untrailingslashit( trim( $value ) );
}

function uwp_checkout_secret(): string {
	$value = uwp_checkout_secret_is_constant()
		? UWP_CHECKOUT_SECRET
		: (string) get_option( 'uwp_checkout_secret', '' );

	return trim( $value );
}

/**
 * True when both halves are present.
 *
 * Checked before anything is rendered. A button that opens a checkout which
 * cannot be created is worse than no button: the visitor has already decided
 * to buy by the time it fails.
 */
function uwp_checkout_is_configured(): bool {
	return '' !== uwp_checkout_endpoint() && '' !== uwp_checkout_secret();
}
