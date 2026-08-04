<?php
/**
 * The one place that talks to the checkout server.
 *
 * Directly modelled on lumo-wp/includes/pro-client.php, and for the same
 * reason: a second consumer is exactly where a re-implementation starts. One
 * place owns the credential, the transport, and the vocabulary for "this did
 * not happen".
 *
 * That vocabulary is the load-bearing part. A caller must be able to tell a
 * transient outage (retry) from a misconfiguration (stop asking) from a plan
 * that does not exist (fix the shortcode) WITHOUT parsing English prose. Every
 * failure carries a machine-readable `reason` and a `retriable` flag alongside
 * the sentence a human reads.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ask the server for a checkout URL.
 *
 * @param string      $plan     Plan key. Never a payment-provider product id:
 *                              the server maps keys to products, so a page --
 *                              or a browser -- cannot name what it buys.
 * @param int         $quantity Seats.
 * @param string|null $bump     Optional second plan key, one copy.
 *
 * @return array{ok:true,url:string}|array{ok:false,reason:string,retriable:bool,message:string}
 */
function wpdc_create_session( string $plan, int $quantity = 1, ?string $bump = null ): array {
	if ( ! wpdc_is_configured() ) {
		return wpdc_error(
			'not_configured',
			false,
			__( 'Checkout is not set up on this site yet.', 'wp-dodo-checkout' )
		);
	}

	$body = array(
		'plan'     => $plan,
		'quantity' => $quantity,
	);
	if ( null !== $bump && '' !== $bump ) {
		$body['bump'] = $bump;
	}

	$response = wp_remote_post(
		wpdc_endpoint() . '/api/checkout-session',
		array(
			'timeout' => WPDC_TIMEOUT,
			'headers' => array(
				'content-type'             => 'application/json',
				'x-lumo-checkout-secret'   => wpdc_secret(),
			),
			'body'    => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		// Never the WP_Error message: it can carry the endpoint host, and this
		// string is on its way to a browser.
		return wpdc_error(
			'unreachable',
			true,
			__( 'The checkout could not be opened. Please try again in a moment.', 'wp-dodo-checkout' )
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( 200 === $status && is_array( $data ) && ! empty( $data['checkoutUrl'] ) ) {
		return array( 'ok' => true, 'url' => (string) $data['checkoutUrl'] );
	}

	// 401 means this site's secret is wrong, which no visitor can do anything
	// about and no amount of retrying will fix. Separated from a 5xx for
	// exactly that reason: one is a page to fix, the other is a minute to wait.
	if ( 401 === $status ) {
		return wpdc_error(
			'unauthorized',
			false,
			__( 'Checkout is not set up correctly on this site.', 'wp-dodo-checkout' )
		);
	}

	if ( 400 === $status ) {
		return wpdc_error(
			'unknown_plan',
			false,
			__( 'That option is not available.', 'wp-dodo-checkout' )
		);
	}

	if ( 429 === $status ) {
		return wpdc_error(
			'rate_limited',
			true,
			__( 'Too many attempts just now. Please try again in a minute.', 'wp-dodo-checkout' )
		);
	}

	return wpdc_error(
		'unavailable',
		true,
		__( 'The checkout could not be opened. Please try again in a moment.', 'wp-dodo-checkout' )
	);
}

/**
 * @return array{ok:false,reason:string,retriable:bool,message:string}
 */
function wpdc_error( string $reason, bool $retriable, string $message ): array {
	return array(
		'ok'        => false,
		'reason'    => $reason,
		'retriable' => $retriable,
		'message'   => $message,
	);
}
