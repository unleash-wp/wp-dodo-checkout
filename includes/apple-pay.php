<?php
/**
 * Serving the Apple Pay domain association file from PHP.
 *
 * Apple requires the file at
 * /.well-known/apple-developer-merchantid-domain-association over HTTPS, with
 * no redirect and a plain content type. On WordPress that is the fiddly part:
 * a dot-directory in the web root is what rewrite rules, security plugins and
 * "block hidden files" server snippets all reach for first, and the failure is
 * silent -- Apple Pay simply never appears, which reads as an unsupported
 * device rather than a missing file.
 *
 * Serving it from `init` sidesteps all of that. The file lives in the plugin,
 * travels with it, and cannot be removed by a hardening rule that does not
 * know what it is.
 *
 * The redirect part is why this runs on `init` and exits immediately: later
 * hooks run after canonical redirects, and a 301 to a trailing slash fails
 * Apple's check just as surely as a 404.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const UWP_APPLE_PAY_PATH = '/.well-known/apple-developer-merchantid-domain-association';

function uwp_checkout_serve_apple_pay_association(): void {
	$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	if ( ! is_string( $path ) ) {
		return;
	}
	// Exact match, and no trailing slash tolerated: Apple fetches this one
	// path and follows nothing.
	if ( untrailingslashit( $path ) !== UWP_APPLE_PAY_PATH ) {
		return;
	}

	$file = UWP_CHECKOUT_DIR . 'apple-developer-merchantid-domain-association';
	if ( ! is_readable( $file ) ) {
		// Nothing to serve. Deliberately falls through to WordPress rather
		// than emitting an empty 200: an empty file passes the fetch and
		// fails the verification, which is a far more confusing place to
		// debug from than a 404.
		return;
	}

	// text/plain rather than octet-stream: both are accepted, and one of them
	// is readable in a browser when somebody is trying to work out whether the
	// file is actually being served.
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'Cache-Control: public, max-age=3600' );
	header( 'X-Content-Type-Options: nosniff' );
	echo file_get_contents( $file ); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}
