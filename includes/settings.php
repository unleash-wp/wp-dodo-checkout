<?php
/**
 * The settings screen.
 *
 * It exists because the plugin read `get_option( 'wpdc_api_key' )` as a
 * fallback and nothing ever wrote it: a fallback to a value nobody could set,
 * which is a promise the code makes and the interface breaks.
 *
 * ── A constant beats the option, and Save must not undo that ────────────────
 *
 * A key in `wp-config.php` is not in the database, so a database dump does not
 * carry it -- and dumps travel: backups, staging copies, a support request with
 * an export attached. This key can issue refunds and read every customer, so
 * that difference is worth keeping.
 *
 * It is undone by the obvious implementation: render the resolved key into the
 * field, and the next Save copies it into an option. So when the constant is
 * set the field is DISABLED. A disabled input is not submitted, so Save has
 * nothing to write. One attribute, and the middle consequence is the one that
 * matters.
 *
 * ── Why the page shows the catalogue ────────────────────────────────────────
 *
 * A settings page that only takes a key tells you nothing about whether it
 * works. This one asks Dodo and lists what it found, which answers the question
 * somebody actually has: "what can I sell, and what do I paste into the page".
 * A working key on an account with no products looks exactly like a key typed
 * wrongly, until the page says so.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wpdc_register_settings(): void {
	register_setting(
		'wpdc',
		'wpdc_api_key',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'wpdc_sanitise_api_key',
			'default'           => '',
			// Never over REST. An option readable by anyone who can read
			// options over the API is not a secret.
			'show_in_rest'      => false,
		)
	);
	register_setting(
		'wpdc',
		'wpdc_mode',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'wpdc_sanitise_mode',
			'default'           => 'test_mode',
			'show_in_rest'      => false,
		)
	);
}

/**
 * Trim, and forget the catalogue.
 *
 * The forgetting is the point: a cached product list belongs to the key it was
 * fetched with. Leaving it would let a corrected key keep failing for ten
 * minutes, which reads as the plugin ignoring the setting rather than as a
 * cache.
 */
function wpdc_sanitise_api_key( $value ): string {
	delete_transient( 'wpdc_catalog' );
	return is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
}

function wpdc_sanitise_mode( $value ): string {
	delete_transient( 'wpdc_catalog' );
	// Anything unrecognised falls to test. Falling to live would mean a typo
	// starts taking real money from real cards.
	return 'live_mode' === $value ? 'live_mode' : 'test_mode';
}

function wpdc_register_settings_page(): void {
	add_options_page(
		__( 'Dodo Checkout', 'wp-dodo-checkout' ),
		__( 'Dodo Checkout', 'wp-dodo-checkout' ),
		'manage_options',
		'wpdc',
		'wpdc_render_settings_page'
	);
}

function wpdc_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$from_constant = wpdc_api_key_is_constant();
	$mode_constant = defined( 'WPDC_MODE' ) && is_string( WPDC_MODE );
	$stored        = (string) get_option( 'wpdc_api_key', '' );
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Dodo Checkout', 'wp-dodo-checkout' ); ?></h1>

		<form action="options.php" method="post">
			<?php settings_fields( 'wpdc' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wpdc_api_key"><?php echo esc_html__( 'Dodo API key', 'wp-dodo-checkout' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="wpdc_api_key"
							name="wpdc_api_key"
							class="regular-text"
							value="<?php echo $from_constant ? '' : esc_attr( $stored ); ?>"
							autocomplete="off"
							<?php disabled( $from_constant ); ?>
						/>
						<p class="description">
							<?php
							echo $from_constant
								? esc_html__( 'Set in wp-config.php as WPDC_API_KEY, which is why this field is disabled: saving here would copy the key into the database, and keeping it out is the whole point of the constant.', 'wp-dodo-checkout' )
								: esc_html__( 'Dodo has no restricted API keys, so this one can issue refunds and read every customer. Prefer WPDC_API_KEY in wp-config.php, which keeps it out of the database and out of every backup.', 'wp-dodo-checkout' );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wpdc_mode"><?php echo esc_html__( 'Mode', 'wp-dodo-checkout' ); ?></label>
					</th>
					<td>
						<select id="wpdc_mode" name="wpdc_mode" <?php disabled( $mode_constant ); ?>>
							<option value="test_mode" <?php selected( 'test_mode', wpdc_mode() ); ?>>
								<?php echo esc_html__( 'Test — no real money moves', 'wp-dodo-checkout' ); ?>
							</option>
							<option value="live_mode" <?php selected( 'live_mode', wpdc_mode() ); ?>>
								<?php echo esc_html__( 'Live — real cards are charged', 'wp-dodo-checkout' ); ?>
							</option>
						</select>
						<?php if ( $mode_constant ) : ?>
							<p class="description"><?php echo esc_html__( 'Set in wp-config.php as WPDC_MODE.', 'wp-dodo-checkout' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php echo esc_html__( 'What this site can sell', 'wp-dodo-checkout' ); ?></h2>
		<?php wpdc_render_catalogue(); ?>
	</div>
	<?php
}

/**
 * Ask Dodo what is sellable, and hand over something to paste.
 *
 * Three states, three sentences, and the middle one is why this section exists:
 * a key that works but reaches no product looks exactly like a key that does
 * not work, until somebody is told the difference.
 */
function wpdc_render_catalogue(): void {
	if ( ! wpdc_is_configured() ) {
		echo '<p>' . esc_html__( 'No API key yet, so nothing was checked. This does not mean there is nothing to sell.', 'wp-dodo-checkout' ) . '</p>';
		return;
	}

	// Deliberately the cached catalogue: this page must not become a way to
	// spend an API call per admin page load. Saving the key clears it, so the
	// reading after a change is always fresh.
	$catalog = wpdc_catalog();

	if ( isset( $catalog['ok'] ) && false === $catalog['ok'] ) {
		printf(
			'<div class="notice notice-error inline"><p>%s</p></div>',
			esc_html( $catalog['message'] )
		);
		return;
	}

	if ( array() === $catalog ) {
		echo '<div class="notice notice-warning inline"><p>';
		echo esc_html__( 'Dodo answered, and this account has no live products. Create one in the Dodo dashboard and it becomes sellable here, with no changes to this site.', 'wp-dodo-checkout' );
		echo '</p></div>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr>';
	echo '<th>' . esc_html__( 'Product', 'wp-dodo-checkout' ) . '</th>';
	echo '<th>' . esc_html__( 'Price', 'wp-dodo-checkout' ) . '</th>';
	echo '<th>' . esc_html__( 'Shortcode', 'wp-dodo-checkout' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $catalog as $id => $product ) {
		printf(
			'<tr><td>%s<br><code>%s</code></td><td>%s</td><td><code>[wpdc_checkout product="%s"]</code></td></tr>',
			esc_html( $product['name'] ),
			esc_html( $id ),
			// Formatted here rather than stored: the page is showing what Dodo
			// says today, and it is never used to charge anybody. The amount a
			// customer pays is settled on Dodo's checkout.
			esc_html( wpdc_format_price( $product['price'], $product['currency'] ) ),
			esc_attr( $id )
		);
	}

	echo '</tbody></table>';
	echo '<p class="description">' . esc_html__( 'Archiving a product in Dodo stops it selling here within ten minutes. Nothing on this site needs changing.', 'wp-dodo-checkout' ) . '</p>';
}

/** Minor units to something readable, said plainly when there is no price. */
function wpdc_format_price( ?int $minor, string $currency ): string {
	if ( null === $minor ) {
		return __( 'no price set', 'wp-dodo-checkout' );
	}
	return number_format_i18n( $minor / 100, 2 ) . ( '' !== $currency ? ' ' . $currency : '' );
}
