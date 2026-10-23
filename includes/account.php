<?php
/**
 * The way from a finished checkout into the UnleashWP account.
 *
 * The browser only ever holds its own session id. This file turns it into a
 * payment id on the server and asks the account for a one-click sign-in; the
 * account checks that payment with Dodo itself and never takes an email from us.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WPDC_ACCOUNT_DEFAULT  = 'https://account.unleash-wp.com';
const WPDC_HANDOFF_PER_SALE = 2;

function wpdc_account_url(): string {
	if ( defined( 'WPDC_ACCOUNT_URL' ) && is_string( WPDC_ACCOUNT_URL ) ) {
		$configured = untrailingslashit( trim( WPDC_ACCOUNT_URL ) );
		if ( 1 === preg_match( '#^https://[a-z0-9.-]+(:\d+)?$#i', $configured ) ) {
			return $configured;
		}
	}
	return WPDC_ACCOUNT_DEFAULT;
}

/** Where a customer goes when the one-click way is not available: the account signs them in there. */
function wpdc_account_downloads_url(): string {
	return wpdc_account_url() . '/downloads';
}

function wpdc_account_secret(): string {
	return defined( 'WPDC_ACCOUNT_SECRET' ) && is_string( WPDC_ACCOUNT_SECRET ) ? trim( WPDC_ACCOUNT_SECRET ) : '';
}

/**
 * The address to open for this checkout: a one-click sign-in when every check
 * passes, the plain Downloads page otherwise. Never an error, because the order
 * is done either way and the customer still needs a way in.
 */
function wpdc_account_handoff( string $session ): string {
	$fallback = wpdc_account_downloads_url();
	$secret   = wpdc_account_secret();
	if ( '' === $secret || ! wpdc_is_session_id( $session ) || ! wpdc_session_known( $session ) ) {
		return $fallback;
	}

	$bucket = 'wpdc_handoff_' . md5( $session );
	$spent  = (int) get_transient( $bucket );
	if ( $spent >= WPDC_HANDOFF_PER_SALE ) {
		return $fallback;
	}
	set_transient( $bucket, $spent + 1, HOUR_IN_SECONDS );

	$checkout = wpdc_dodo_request( 'GET', '/checkouts/' . rawurlencode( $session ), null );
	if ( wpdc_is_error( $checkout ) || 'succeeded' !== ( $checkout['payment_status'] ?? '' ) ) {
		return $fallback;
	}
	$payment = $checkout['payment_id'] ?? '';
	if ( ! is_string( $payment ) || 1 !== preg_match( '/^pay_[A-Za-z0-9]+$/', $payment ) ) {
		return $fallback;
	}

	$response = wp_remote_request(
		wpdc_account_url() . '/api/handoff/mint',
		array(
			'method'      => 'POST',
			'timeout'     => 5,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'authorization' => 'Bearer ' . $secret,
				'content-type'  => 'application/json',
				'accept'        => 'application/json',
			),
			'body'        => wp_json_encode( array( 'payment_id' => $payment ) ),
		)
	);
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return $fallback;
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$url  = is_array( $body ) && is_string( $body['url'] ?? null ) ? $body['url'] : '';

	// Checked rather than trusted: whatever this says becomes the page a buyer lands on.
	$prefix = wpdc_account_url() . '/api/auth/handoff/redeem?t=';
	return str_starts_with( $url, $prefix ) && 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', substr( $url, strlen( $prefix ) ) )
		? $url
		: $fallback;
}

function wpdc_rest_handoff( WP_REST_Request $request ): WP_REST_Response {
	$response = new WP_REST_Response( array( 'url' => wpdc_account_handoff( (string) $request->get_param( 'session' ) ) ), 200 );
	$response->header( 'cache-control', 'private, no-store' );
	return $response;
}
