<?php
/**
 * The shortcode, in both display modes.
 *
 *   [uwp_checkout plan="pro"]
 *   [uwp_checkout plan="pro" display="overlay" bump="ebook" bump_label="Add the eBook for 9 EUR"]
 *
 * Impreza is WPBakery-based, so a shortcode is placeable as an element and
 * survives a theme change. Nothing here depends on the theme.
 *
 * ── Why inline is the default ───────────────────────────────────────────────
 *
 * Dodo's own documentation states Apple Pay is not available for overlay
 * checkout. On mobile traffic that is the difference between a two-tap
 * purchase and a form. Overlay stays available because the difference is one
 * parameter, and because a site owner may have a reason.
 *
 * ── Why no price is written here ────────────────────────────────────────────
 *
 * A price in a shortcode is a price in the page cache, in a browser, and in a
 * translation file, and it is wrong the day it changes. The customer sees the
 * price on Dodo's checkout, which is the one place it cannot be stale. An
 * order bump therefore names a plan key and a label the site owner writes, not
 * an amount this plugin computes.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function uwp_checkout_register_shortcode(): void {
	add_shortcode( 'uwp_checkout', 'uwp_checkout_render' );
}

function uwp_checkout_render( $atts ): string {
	$atts = shortcode_atts(
		array(
			'plan'       => '',
			'display'    => 'inline',
			'bump'       => '',
			'bump_label' => '',
			'label'      => __( 'Buy now', 'uwp-checkout' ),
			'quantity'   => '1',
		),
		is_array( $atts ) ? $atts : array(),
		'uwp_checkout'
	);

	if ( ! uwp_checkout_is_plan_key( $atts['plan'] ) ) {
		// Only an editor sees this, and only where the mistake is. A visitor
		// gets nothing rather than a broken button.
		return current_user_can( 'edit_posts' )
			? '<p>' . esc_html__( 'uwp_checkout: a valid plan attribute is required.', 'uwp-checkout' ) . '</p>'
			: '';
	}

	if ( ! uwp_checkout_is_configured() ) {
		return current_user_can( 'manage_options' )
			? '<p>' . esc_html__( 'uwp_checkout: set UWP_CHECKOUT_ENDPOINT and UWP_CHECKOUT_SECRET.', 'uwp-checkout' ) . '</p>'
			: '';
	}

	$bump     = uwp_checkout_is_plan_key( $atts['bump'] ) ? $atts['bump'] : '';
	$overlay  = 'overlay' === $atts['display'];
	$quantity = max( 1, min( 50, (int) $atts['quantity'] ) );
	$id       = wp_unique_id( 'uwp-checkout-' );

	uwp_checkout_enqueue( $overlay );

	ob_start();
	?>
	<div class="uwp-checkout" id="<?php echo esc_attr( $id ); ?>"
		data-plan="<?php echo esc_attr( $atts['plan'] ); ?>"
		data-quantity="<?php echo esc_attr( (string) $quantity ); ?>"
		data-display="<?php echo esc_attr( $overlay ? 'overlay' : 'inline' ); ?>">

		<?php if ( '' !== $bump ) : ?>
			<label class="uwp-checkout__bump">
				<input type="checkbox" class="uwp-checkout__bump-input"
					value="<?php echo esc_attr( $bump ); ?>">
				<span><?php
					echo esc_html(
						'' !== $atts['bump_label']
							? $atts['bump_label']
							: __( 'Add the companion product', 'uwp-checkout' )
					);
				?></span>
			</label>
		<?php endif; ?>

		<button type="button" class="uwp-checkout__button">
			<?php echo esc_html( $atts['label'] ); ?>
		</button>

		<p class="uwp-checkout__message" role="status" aria-live="polite"></p>

		<?php if ( ! $overlay ) : ?>
			<div class="uwp-checkout__frame"></div>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Scripts, and the one thing the browser is told.
 *
 * The Dodo SDK is only loaded for overlay mode, and only from a PINNED
 * version. A floating tag would mean a third party can change what executes on
 * a checkout page without anyone here deploying anything -- and would make a
 * subresource integrity hash impossible, because the file behind the URL is
 * allowed to change. Inline mode does not load it at all: it navigates to
 * Dodo's own page, which is also what keeps card fields off this origin and
 * out of scope.
 */
function uwp_checkout_enqueue( bool $overlay ): void {
	wp_enqueue_style(
		'uwp-checkout',
		plugins_url( 'assets/checkout.css', UWP_CHECKOUT_DIR . 'uwp-checkout.php' ),
		array(),
		UWP_CHECKOUT_VERSION
	);

	wp_enqueue_script(
		'uwp-checkout',
		plugins_url( 'assets/checkout.js', UWP_CHECKOUT_DIR . 'uwp-checkout.php' ),
		array(),
		UWP_CHECKOUT_VERSION,
		true
	);

	if ( $overlay ) {
		wp_enqueue_script(
			'dodo-checkout',
			'https://cdn.jsdelivr.net/npm/dodopayments-checkout@1/dist/index.js',
			array(),
			null,
			true
		);
	}

	wp_localize_script(
		'uwp-checkout',
		'uwpCheckout',
		array(
			'endpoint' => esc_url_raw( rest_url( 'uwp-checkout/v1/session' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'busy'     => __( 'Opening checkout…', 'uwp-checkout' ),
			'failed'   => __( 'The checkout could not be opened. Please try again in a moment.', 'uwp-checkout' ),
		)
	);
}
