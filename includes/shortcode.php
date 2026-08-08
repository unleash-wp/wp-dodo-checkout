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
			// Per shortcode, because a shop can sell a German edition and an
			// English one from the same site. The site locale is the fallback and
			// is wrong here by construction: it describes the SHOP, not the book.
			'lang'       => '',
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
	// Normalised here too, so the labels and the frame cannot end up in different
	// languages even when the shortcode names a third one.
	// Shortcode first, then the browser's own header. Never the site locale: it
	// describes the shop, and on this one it says en_US while the customer reads
	// German.
	$lang     = '' !== trim( (string) $atts['lang'] )
		? wpdc_two_languages( trim( (string) $atts['lang'] ) )
		: wpdc_request_language();
	$quantity = max( 1, min( 50, (int) $atts['quantity'] ) );
	$id       = wp_unique_id( 'wpdc-' );


	/**
	 * Our labels speak the language the checkout speaks.
	 *
	 * Subtotal, VAT and Total are rendered here, in WordPress's locale -- which
	 * on this shop says en_US while the customer reads German. The checkout in
	 * the frame beside them follows the request, so the two halves of one window
	 * disagreed.
	 *
	 * `switch_to_locale` was the obvious way and it does not work: measured on a
	 * real install, it returns FALSE when the site has no core language pack for
	 * that locale. Which is the normal state of an English WordPress selling to
	 * Germans -- the exact case this exists for.
	 *
	 * So the catalogue is loaded directly. It is OUR file, we know where it is,
	 * and it does not care whether WordPress core speaks the language. The
	 * previous one is put back straight after: a shortcode must not leave the
	 * rest of the page speaking something else.
	 */
	$switched = '' !== $lang && wpdc_load_catalogue( $lang );

	// AFTER the catalogue, and that order is the whole point: wpdc_enqueue
	// translates the strings it hands to JavaScript, and it used to run first --
	// so "Code applied." reached a German customer in English while everything
	// rendered below it was translated.
	wpdc_enqueue();

	ob_start();
	?>
	<div class="wp-dodo-checkout" id="<?php echo esc_attr( $id ); ?>"
		data-product="<?php echo esc_attr( $atts['product'] ); ?>"
		data-quantity="<?php echo esc_attr( (string) $quantity ); ?>"
		<?php if ( '' !== $lang ) : ?>data-lang="<?php echo esc_attr( $lang ); ?>"<?php endif; ?>>

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

			<?php
			// Dodo's inline documentation lists what a compliant embed must show:
			// item descriptions, and transaction totals including subtotal, tax
			// and grand total. Their frame carries them, but only after the
			// contact step, and their own reference layout puts them beside it.
			//
			// The numbers come from THEIR checkout.breakdown event, never from a
			// price this plugin holds. Tax depends on a country the customer has
			// not typed yet, so anything computed here would be a guess that
			// disagrees with the frame the moment they type it.
			?>
			<?php
			// One level more than looks necessary, and it is what makes the close
			// button reachable.
			//
			// The dialog itself must not scroll: an absolutely positioned button
			// inside a scrolling box scrolls away with the content, which is how
			// the only way out of a checkout disappeared on a phone. `position:
			// fixed` was tried and pins to the VIEWPORT, not the dialog, so it
			// floated mid-screen attached to nothing.
			//
			// So the scrolling happens one level in. The button hangs off the
			// dialog and stays where it is; the header scrolls with the content,
			// which is what the operator asked for and what a phone wants.
			?>
			<div class="wpdc__scroll">
			<aside class="wpdc__panel">
				<?php wpdc_render_item( $atts['product'] ); ?>

				<?php
				// Dodo's own discount field lives in their order summary, and
				// inline mode does not render one -- so it is here, in the
				// summary we were asked to build. Measured: the same session on
				// their hosted page shows "Haben Sie einen Rabattcode?"; embedded
				// it shows nothing.
				//
				// A form rather than a div with a button: Enter submits it, which
				// is what somebody who has just typed a code will press.
				?>
				<form class="wpdc__discount" novalidate>
					<input type="text" class="wpdc__discount-input"
						name="wpdc_discount"
						autocomplete="off" autocapitalize="characters" spellcheck="false"
						aria-label="<?php echo esc_attr__( 'Discount code', 'wp-dodo-checkout' ); ?>"
						placeholder="<?php echo esc_attr__( 'Discount code', 'wp-dodo-checkout' ); ?>">
					<button type="submit" class="wpdc__discount-apply">
						<?php echo esc_html__( 'Apply', 'wp-dodo-checkout' ); ?>
					</button>
					<p class="wpdc__discount-note" role="status" aria-live="polite"></p>
				</form>

				<dl class="wpdc__totals" hidden>
					<div class="wpdc__row" data-row="subtotal">
						<dt><?php echo esc_html__( 'Subtotal', 'wp-dodo-checkout' ); ?></dt><dd></dd>
					</div>
					<div class="wpdc__row wpdc__row--discount" data-row="discount" hidden>
						<dt><?php echo esc_html__( 'Discount', 'wp-dodo-checkout' ); ?><span class="wpdc__off"></span></dt><dd></dd>
					</div>
					<div class="wpdc__row" data-row="tax">
						<dt><?php echo esc_html__( 'VAT', 'wp-dodo-checkout' ); ?><span class="wpdc__rate"></span></dt><dd></dd>
					</div>
					<div class="wpdc__row wpdc__row--total" data-row="total">
						<dt><?php echo esc_html__( 'Total', 'wp-dodo-checkout' ); ?></dt><dd></dd>
					</div>
				</dl>

			</aside>

			<div class="wpdc__frame" id="<?php echo esc_attr( $id ); ?>-frame"></div>
			</div>
		</dialog>

		<p class="wpdc__message" role="status" aria-live="polite"></p>
	</div>
	<?php
	$html = (string) ob_get_clean();

	if ( $switched ) {
		wpdc_restore_catalogue();
	}

	return $html;
}

/**
 * A two-letter language to the locale WordPress names its catalogues with.
 *
 * `de` is `de_DE` and `en` is `en_US`; anything else is passed through and
 * WordPress falls back on its own if it has nothing. Deliberately a short list
 * rather than a guess like `xx_XX`, which names catalogues that do not exist.
 */
/**
 * Load our own catalogue for one render.
 *
 * `load_textdomain` takes a path, so nothing here depends on WordPress having
 * translations of its own. Returns false when there is no file -- English needs
 * none, and a missing catalogue is not an error, it is the source language.
 */
function wpdc_load_catalogue( string $lang ): bool {
	$file = WPDC_DIR . 'languages/wp-dodo-checkout-' . wpdc_locale_for( $lang ) . '.mo';
	if ( ! is_readable( $file ) ) {
		return false;
	}
	unload_textdomain( 'wp-dodo-checkout' );
	return load_textdomain( 'wp-dodo-checkout', $file );
}

/** Back to whatever the site was using, through the plugin's normal loader. */
function wpdc_restore_catalogue(): void {
	unload_textdomain( 'wp-dodo-checkout' );
	if ( function_exists( 'wpdc_load_textdomain' ) ) {
		wpdc_load_textdomain();
	}
}

function wpdc_locale_for( string $lang ): string {
	// Two, because the shop speaks two. A longer list would name catalogues
	// that do not exist and leave the labels English beside a checkout that is
	// not.
	return 'de' === $lang ? 'de_DE' : 'en_US';
}

/**
 * Dodo's product descriptions come back as MARKDOWN, which nothing in the API
 * says. `wp_strip_all_tags` takes the HTML and leaves `**Nur hier erhaeltlich**`
 * sitting in a checkout window with its asterisks showing. Unwrapped rather than
 * rendered: two lines of prose do not need a Markdown renderer.
 */
function wpdc_plain_text( string $markdown ): string {
	$text = wp_strip_all_tags( $markdown );
	// [label](url) -> label, before the emphasis pass or the brackets survive.
	$text = (string) preg_replace( '/\[([^\]]*)\]\([^)]*\)/', '$1', $text );
	$text = (string) preg_replace( '/[*_`~]+/', '', $text );
	$text = (string) preg_replace( '/^\s*#+\s*/m', '', $text );
	return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
}

/**
 * The item: what is being bought, and its list price.
 *
 * This price is the product's own figure and is NEVER what is charged -- that is
 * the Total below it, which comes from Dodo. If the two disagree the frame is
 * right and this is stale, and that is the direction the mistake should fall.
 */
function wpdc_render_item( string $product ): void {
	$catalog = wpdc_catalog();
	if ( isset( $catalog['ok'] ) && false === $catalog['ok'] ) {
		return; // the checkout itself will say what is wrong
	}
	$item = $catalog[ $product ] ?? null;
	if ( null === $item ) {
		return;
	}
	?>
	<div class="wpdc__item">
		<?php if ( '' !== $item['image'] ) : ?>
			<?php
			// Decorative: the name sits right beside it, so a screen reader that
			// announced the file as well would say the product twice. loading and
			// decoding are async because nothing waits on a cover.
			?>
			<img class="wpdc__item-img" src="<?php echo esc_url( $item['image'] ); ?>"
				alt="" loading="lazy" decoding="async">
		<?php endif; ?>
		<div>
			<p class="wpdc__item-name"><?php echo esc_html( $item['name'] ); ?></p>
			<?php if ( '' !== $item['description'] ) : ?>
				<p class="wpdc__item-desc"><?php echo esc_html( wp_trim_words( wpdc_plain_text( $item['description'] ), 22 ) ); ?></p>
			<?php endif; ?>
		</div>
	</div>
	<?php
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
			'discountApplied' => __( 'Code applied.', 'wp-dodo-checkout' ),
		)
	);
}
