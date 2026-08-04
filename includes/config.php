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
const WPDC_TIMEOUT = 15;

function wpdc_endpoint_is_constant(): bool {
	return defined( 'WPDC_ENDPOINT' ) && is_string( WPDC_ENDPOINT );
}

function wpdc_secret_is_constant(): bool {
	return defined( 'WPDC_SECRET' ) && is_string( WPDC_SECRET );
}

/** Base URL of the server that mints sessions, without a trailing slash. */
function wpdc_endpoint(): string {
	$value = wpdc_endpoint_is_constant()
		? WPDC_ENDPOINT
		: (string) get_option( 'wpdc_endpoint', '' );

	return untrailingslashit( trim( $value ) );
}

function wpdc_secret(): string {
	$value = wpdc_secret_is_constant()
		? WPDC_SECRET
		: (string) get_option( 'wpdc_secret', '' );

	return trim( $value );
}

/**
 * True when both halves are present.
 *
 * Checked before anything is rendered. A button that opens a checkout which
 * cannot be created is worse than no button: the visitor has already decided
 * to buy by the time it fails.
 */
function wpdc_is_configured(): bool {
	return '' !== wpdc_endpoint() && '' !== wpdc_secret();
}
