<?php
/**
 * The shortcode, in both display modes.
 *
 *   [wpdc_checkout product="<a product id from your Dodo account>"]
 *   [wpdc_checkout product="<id>" display="overlay" bump="<id>" bump_label="Add the eBook for 9 EUR"]
 *
 * The id is Dodo's own. Settings > Dodo Checkout lists every product this
 * account can sell, each with the shortcode ready to copy -- deliberately no id
 * is written into this plugin, so it carries no shop's catalogue with it.
 *
 * Impreza is WPBakery-based, so a shortcode is placeable as an element and
 * survives a theme change. Nothing here depends on the theme.
 *
 ── The checkout is embedded, never a navigation ────────────────────────────
 *
 * Operator goal, in his words: direct sales, and the form is what costs
 * conversion. So the checkout opens INSIDE the page, in a frame Dodo renders,
 * and the wallet buttons sit above the form. A customer with Apple Pay or
 * Google Pay types nothing at all -- name, email and address come from the
 * wallet. The form is the path beside that one, not in front of it.
 *
 * Inline rather than the overlay, and the reason is Apple Pay. Dodo's Overlay
 * Checkout page says "Apple Pay is not yet supported in overlay checkout" and
 * its Inline page warns the same; only the Digital Wallets page claims all
 * wallets work everywhere. Two specific pages against one general list, so the
 * overlay is not a surface to bet Apple Pay on. Inline supports it, at the cost
 * of one dashboard step: the domain must be registered under Settings >
 * Payment Methods > Apple Pay. This plugin already serves the association file
 * Apple asks for, from `init`.
 *
 * A redirect to Dodo's own page survives as the FAILURE path only, in
 * checkout.js: if the SDK did not load, a customer who has decided to buy must
 * not be stopped by our script loader. That is a fallback, not a mode.
 *
 * ── Why no price is written here ────────────────────────────────────────────
 *
 * A price in a shortcode is a price in the page cache, in a browser, and in a
 * translation file, and it is wrong the day it changes. The customer sees the
 * price on Dodo's checkout, which is the one place it cannot be stale. An
 * order bump therefore names a product id and a label the site owner writes,
 * not an amount this plugin computes.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wpdc_register_shortcode(): void {
	add_shortcode( 'wpdc_checkout', 'wpdc_render' );
}

function wpdc_render( $atts ): string {
	$atts = shortcode_atts(
		array(
			'product'    => '',
			'bump'       => '',
			'bump_label' => '',
			'label'      => __( 'Buy now', 'wp-dodo-checkout' ),
			'quantity'   => '1',
		),
		is_array( $atts ) ? $atts : array(),
		'wpdc_checkout'
	);

	if ( ! wpdc_is_product_id( $atts['product'] ) ) {
		// Only an editor sees this, and only where the mistake is. A visitor
		// gets nothing rather than a broken button.
		return current_user_can( 'edit_posts' )
			? '<p>' . esc_html__( 'wpdc_checkout: a product attribute holding a Dodo product id (pdt_...) is required.', 'wp-dodo-checkout' ) . '</p>'
			: '';
	}

	if ( ! wpdc_is_configured() ) {
		return current_user_can( 'manage_options' )
			? '<p>' . esc_html__( 'wpdc_checkout: set WPDC_API_KEY in wp-config.php.', 'wp-dodo-checkout' ) . '</p>'
			: '';
	}

	$bump     = wpdc_is_product_id( $atts['bump'] ) ? $atts['bump'] : '';
	$quantity = max( 1, min( 50, (int) $atts['quantity'] ) );
	$id       = wp_unique_id( 'wpdc-' );

	wpdc_enqueue();

	ob_start();
	?>
	<div class="wp-dodo-checkout" id="<?php echo esc_attr( $id ); ?>"
		data-product="<?php echo esc_attr( $atts['product'] ); ?>"
		data-quantity="<?php echo esc_attr( (string) $quantity ); ?>">

		<?php if ( '' !== $bump ) : ?>
			<label class="wpdc__bump">
				<input type="checkbox" class="wpdc__bump-input"
					value="<?php echo esc_attr( $bump ); ?>">
				<span><?php
					echo esc_html(
						'' !== $atts['bump_label']
							? $atts['bump_label']
							: __( 'Add the companion product', 'wp-dodo-checkout' )
					);
				?></span>
			</label>
		<?php endif; ?>

		<button type="button" class="wpdc__button">
			<?php echo esc_html( $atts['label'] ); ?>
		</button>

		<?php
		// A native <dialog>, not a div pretending to be one. showModal() brings
		// the focus trap, Escape, the backdrop and the top layer with it -- four
		// things that are individually easy and collectively where hand-rolled
		// modals go wrong, on the one page where a customer must not get stuck.
		//
		// Inside it sits the element Dodo's SDK injects its iframe into. This is
		// still `displayType: inline`: "inline" describes who owns the window,
		// not where it appears. Ours is the window; Dodo's is the frame.
		?>
		<dialog class="wpdc__dialog" aria-label="<?php echo esc_attr__( 'Checkout', 'wp-dodo-checkout' ); ?>">
			<button type="button" class="wpdc__close" aria-label="<?php echo esc_attr__( 'Close checkout', 'wp-dodo-checkout' ); ?>">&times;</button>
			<div class="wpdc__frame" id="<?php echo esc_attr( $id ); ?>-frame"></div>
		</dialog>

		<p class="wpdc__message" role="status" aria-live="polite"></p>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Scripts, and the one thing the browser is told.
 *
 * The Dodo SDK loads from a PINNED major version. A floating tag would mean a
 * third party can change what executes on a checkout page without anyone here
 * deploying anything, and would make a subresource integrity hash impossible
 * because the file behind the URL is allowed to change.
 *
 * It loads on every page carrying the shortcode, because the overlay is the
 * only mode. Card fields still never touch this origin: the overlay is Dodo's
 * own page in an iframe from Dodo's origin, so the PCI surface is the same as a
 * redirect.
 */
function wpdc_enqueue(): void {
	wp_enqueue_style(
		'wp-dodo-checkout',
		plugins_url( 'assets/checkout.css', WPDC_DIR . 'wp-dodo-checkout.php' ),
		array(),
		WPDC_VERSION
	);

	wp_enqueue_script(
		'wp-dodo-checkout',
		plugins_url( 'assets/checkout.js', WPDC_DIR . 'wp-dodo-checkout.php' ),
		array(),
		WPDC_VERSION,
		true
	);

	wp_enqueue_script(
		'dodo-checkout',
		'https://cdn.jsdelivr.net/npm/dodopayments-checkout@1/dist/index.js',
		array(),
		null,
		true
	);

	wp_localize_script(
		'wp-dodo-checkout',
		'wpdcCheckout',
		array(
			'endpoint' => esc_url_raw( rest_url( 'wp-dodo-checkout/v1/session' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'busy'     => __( 'Opening checkout…', 'wp-dodo-checkout' ),
			'failed'   => __( 'The checkout could not be opened. Please try again in a moment.', 'wp-dodo-checkout' ),
		)
	);
}
