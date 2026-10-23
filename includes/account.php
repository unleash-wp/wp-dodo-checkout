<?php
/**
 * The way from a finished checkout into the UnleashWP account.
 *
 * The browser only ever holds its own session id. This file turns it into a
 * payment id on the server and asks the account to mail a sign-in code to the
 * address that paid. The code proves the inbox: Dodo does not verify the
 * address a buyer types, so a purchase alone must never open an account.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WPDC_ACCOUNT_DEFAULT    = 'https://account.unleash-wp.com';
const WPDC_ACCOUNT_CODES_SALE = 2;

function wpdc_account_url(): string {
	if ( defined( 'WPDC_ACCOUNT_URL' ) && is_string( WPDC_ACCOUNT_URL ) ) {
		$configured = untrailingslashit( trim( WPDC_ACCOUNT_URL ) );
		if ( 1 === preg_match( '#^https://[a-z0-9.-]+(:\d+)?$#i', $configured ) ) {
			return $configured;
		}
	}
	return WPDC_ACCOUNT_DEFAULT;
}

/** Where a customer goes when there is no modal: the account signs them in there. */
function wpdc_account_downloads_url(): string {
	return wpdc_account_url() . '/downloads';
}

function wpdc_account_secret(): string {
	return defined( 'WPDC_ACCOUNT_SECRET' ) && is_string( WPDC_ACCOUNT_SECRET ) ? trim( WPDC_ACCOUNT_SECRET ) : '';
}

/**
 * Ask the account to mail a sign-in code to the address that paid for this
 * checkout. Returns that address, which the browser holding the session may
 * see like it sees the licence key, or '' when the modal should ask for it.
 */
function wpdc_account_code( string $session, string $lang ): string {
	$secret = wpdc_account_secret();
	if ( '' === $secret || ! wpdc_is_session_id( $session ) || ! wpdc_session_known( $session ) ) {
		return '';
	}

	$bucket = 'wpdc_account_code_' . md5( $session );
	$spent  = (int) get_transient( $bucket );
	if ( $spent >= WPDC_ACCOUNT_CODES_SALE ) {
		return '';
	}
	set_transient( $bucket, $spent + 1, HOUR_IN_SECONDS );

	$checkout = wpdc_dodo_request( 'GET', '/checkouts/' . rawurlencode( $session ), null );
	if ( wpdc_is_error( $checkout ) || 'succeeded' !== ( $checkout['payment_status'] ?? '' ) ) {
		return '';
	}
	$payment = $checkout['payment_id'] ?? '';
	if ( ! is_string( $payment ) || 1 !== preg_match( '/^pay_[A-Za-z0-9]+$/', $payment ) ) {
		return '';
	}

	$response = wp_remote_request(
		wpdc_account_url() . '/api/handoff/code',
		array(
			'method'      => 'POST',
			'timeout'     => 8,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'authorization' => 'Bearer ' . $secret,
				'content-type'  => 'application/json',
				'accept'        => 'application/json',
			),
			'body'        => wp_json_encode(
				array(
					'payment_id' => $payment,
					'lang'       => 'de' === $lang ? 'de' : 'en',
				)
			),
		)
	);
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return '';
	}

	$body  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$email = is_array( $body ) && is_string( $body['email'] ?? null ) ? strtolower( trim( $body['email'] ) ) : '';
	return is_email( $email ) ? $email : '';
}

function wpdc_rest_account_code( WP_REST_Request $request ): WP_REST_Response {
	$email = wpdc_account_code( (string) $request->get_param( 'session' ), wpdc_page_language() );
	$response = new WP_REST_Response( '' === $email ? array( 'sent' => false ) : array( 'sent' => true, 'email' => $email ), 200 );
	$response->header( 'cache-control', 'private, no-store' );
	return $response;
}

/**
 * Everything the sign-in modal needs, in the page's language. The modal itself
 * is fetched on the first click; only this and a few hundred bytes of loader
 * ride on every page.
 */
function wpdc_account_login_config(): array {
	return array(
		'account' => wpdc_account_url(),
		'lang'    => wpdc_page_language(),
		'script'  => add_query_arg( 'ver', WPDC_VERSION, plugins_url( 'assets/account-login.js', WPDC_DIR . 'wp-dodo-checkout.php' ) ),
		'style'   => add_query_arg( 'ver', WPDC_VERSION, plugins_url( 'assets/account-login.css', WPDC_DIR . 'wp-dodo-checkout.php' ) ),
		'logo'    => add_query_arg( 'ver', WPDC_VERSION, plugins_url( 'assets/brand-logo.svg', WPDC_DIR . 'wp-dodo-checkout.php' ) ),
		's'       => array(
			'title'       => __( 'Sign in to UnleashWP', 'wp-dodo-checkout' ),
			'lead'        => __( 'Your downloads, licence keys and invoices, with no password. We send you a code.', 'wp-dodo-checkout' ),
			'email'       => __( 'Email', 'wp-dodo-checkout' ),
			'placeholder' => __( 'your@email.com', 'wp-dodo-checkout' ),
			'badEmail'    => __( 'Enter a valid email address.', 'wp-dodo-checkout' ),
			'send'        => __( 'Send code', 'wp-dodo-checkout' ),
			'sending'     => __( 'Sending…', 'wp-dodo-checkout' ),
			'sent'        => __( 'If that address can sign in, a code is on its way. It lasts ten minutes and works once.', 'wp-dodo-checkout' ),
			/* translators: %s: the purchase email address */
			'sentTo'      => __( 'We sent a code to %s. It lasts ten minutes and works once.', 'wp-dodo-checkout' ),
			'code'        => __( '6-digit code', 'wp-dodo-checkout' ),
			'signIn'      => __( 'Sign in', 'wp-dodo-checkout' ),
			'checking'    => __( 'Checking…', 'wp-dodo-checkout' ),
			'resend'      => __( 'Resend code', 'wp-dodo-checkout' ),
			/* translators: %d: seconds until another code may be sent */
			'wait'        => __( 'You can send a new code in %d seconds.', 'wp-dodo-checkout' ),
			'other'       => __( 'Use a different address', 'wp-dodo-checkout' ),
			'wrong'       => __( 'That code did not work. Check it, or send a new one.', 'wp-dodo-checkout' ),
			'tooMany'     => __( 'Too many tries. Wait a minute and try again.', 'wp-dodo-checkout' ),
			'locked'      => __( 'Too many wrong codes. Send yourself a new one.', 'wp-dodo-checkout' ),
			'powFailed'   => __( 'The security check did not work. Try again.', 'wp-dodo-checkout' ),
			'offline'     => __( 'No connection. Try again in a moment.', 'wp-dodo-checkout' ),
			'signedIn'    => __( 'You are signed in.', 'wp-dodo-checkout' ),
			'open'        => __( 'Open my downloads', 'wp-dodo-checkout' ),
			'close'       => __( 'Close', 'wp-dodo-checkout' ),
		),
	);
}

/**
 * Any link into the account opens the modal instead; without JavaScript it is
 * still a plain link that lands on the account's own sign-in. A modifier click
 * keeps the browser's own meaning.
 */
const WPDC_ACCOUNT_LOGIN_LOADER = <<<'JS'
(function(c){var asked=0;
function hit(t){var a=t&&t.closest&&t.closest('a[href],[data-uwp-login]');if(!a)return null;if(a.hasAttribute('data-uwp-login'))return a;var h=a.href||'';return h===c.account||h.indexOf(c.account+'/')===0?a:null}
function ask(){if(asked)return;asked=1;fetch(c.account+'/api/sso/session',{credentials:'include'}).then(function(r){return r.json()}).then(function(d){c.signedIn=!!(d&&d.signedIn)}).catch(function(){})}
function load(a,o){if(c.open){c.open(a,o);return}var n=2,go=function(){if(--n===0&&c.open)c.open(a,o)};var l=document.createElement('link');l.rel='stylesheet';l.href=c.style;l.onload=l.onerror=go;document.head.appendChild(l);var s=document.createElement('script');s.src=c.script;s.onload=go;document.head.appendChild(s)}
document.addEventListener('pointerover',function(e){if(hit(e.target))ask()},{passive:true});
document.addEventListener('focusin',function(e){if(hit(e.target))ask()});
document.addEventListener('click',function(e){var a=hit(e.target);if(!a||e.defaultPrevented||e.button||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;e.preventDefault();if(c.signedIn){window.open(a.href||c.account+'/downloads','_blank','noopener');return}load(a)});
c.show=load;
})(window.wpdcAccountLogin);
JS;

function wpdc_account_login_loader(): string {
	return '<script id="wpdc-account-login">window.wpdcAccountLogin=' . wp_json_encode( wpdc_account_login_config() ) . ';' . WPDC_ACCOUNT_LOGIN_LOADER . '</script>' . "\n";
}

function wpdc_print_account_login(): void {
	$switched = function_exists( 'wpdc_load_catalogue' ) && wpdc_load_catalogue( wpdc_page_language() );
	echo wpdc_account_login_loader(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from wp_json_encode, a fixed script.
	if ( $switched ) {
		wpdc_restore_catalogue();
	}
}

/**
 * [wpdc_account] for the header: a person icon that opens the sign-in modal,
 * and without JavaScript a plain link into the account. label="Konto" shows
 * text beside the icon; size="20" sets the icon in pixels (12 to 48).
 */
function wpdc_render_account( $atts ): string {
	$atts = shortcode_atts(
		array(
			'label' => '',
			'size'  => '20',
		),
		is_array( $atts ) ? $atts : array(),
		'wpdc_account'
	);

	$switched = function_exists( 'wpdc_load_catalogue' ) && wpdc_load_catalogue( wpdc_page_language() );
	$name     = __( 'My account', 'wp-dodo-checkout' );
	if ( $switched ) {
		wpdc_restore_catalogue();
	}

	$size  = max( 12, min( 48, (int) $atts['size'] ) );
	$label = trim( (string) $atts['label'] );

	return '<a class="wpdc-account-link" href="' . esc_url( wpdc_account_downloads_url() ) . '" data-uwp-login'
		. ( '' === $label ? ' aria-label="' . esc_attr( $name ) . '"' : '' )
		. ' style="display:inline-flex;align-items:center;justify-content:center;gap:6px;min-width:44px;min-height:44px;color:inherit;text-decoration:none">'
		. '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">'
		. '<path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.42 0-8 2.24-8 5v1a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-1c0-2.76-3.58-5-8-5Z"/></svg>'
		. ( '' === $label ? '' : '<span class="wpdc-account-link__text">' . esc_html( $label ) . '</span>' )
		. '</a>';
}

/**
 * Themes print some fields raw: Impreza's header Text element showed the
 * shortcode as text. So the finished front-end page is searched once for it,
 * and only a page that contains it is touched.
 */
function wpdc_account_expand( string $html ): string {
	if ( ! str_contains( $html, '[wpdc_account' ) ) {
		return $html;
	}
	$out = preg_replace_callback(
		'/\[wpdc_account(\s[^\]]*)?\]/',
		static function ( array $m ): string {
			$atts = shortcode_parse_atts( trim( $m[1] ?? '' ) );
			return wpdc_render_account( is_array( $atts ) ? $atts : array() );
		},
		$html
	);
	return is_string( $out ) ? $out : $html;
}

function wpdc_account_buffer(): void {
	if ( is_admin() || wp_doing_ajax() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	ob_start( 'wpdc_account_expand' );
}
