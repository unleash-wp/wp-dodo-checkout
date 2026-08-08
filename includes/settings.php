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
 * works. This one asks Dodo and lists the plan keys it found, which is the only
 * thing that answers the question somebody actually has: "will my shortcode
 * work". A key typed correctly into a product that carries no `uwp_plan` looks
 * exactly like a key typed wrongly, until the page says so.
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
	delete_transient( 'wpdc_plan_map' );
	return is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
}

function wpdc_sanitise_mode( $value ): string {
	delete_transient( 'wpdc_plan_map' );
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
 * Ask Dodo what is sellable, and say it plainly.
 *
 * Three states, three sentences, and the middle one is why this section exists:
 * a key that works but reaches no marked product looks exactly like a key that
 * does not work, until somebody is told the difference.
 */
function wpdc_render_catalogue(): void {
	if ( ! wpdc_is_configured() ) {
		echo '<p>' . esc_html__( 'No API key yet, so nothing was checked. This does not mean there is nothing to sell.', 'wp-dodo-checkout' ) . '</p>';
		return;
	}

	// Deliberately the cached map: this page must not become a way to spend an
	// API call per admin page load. Saving the key clears it, so the reading
	// after a change is always fresh.
	$map = wpdc_plan_map();

	if ( isset( $map['ok'] ) && false === $map['ok'] ) {
		printf(
			'<div class="notice notice-error inline"><p>%s</p></div>',
			esc_html( $map['message'] )
		);
		return;
	}

	if ( array() === $map ) {
		echo '<div class="notice notice-warning inline"><p>';
		printf(
			/* translators: %s: the metadata key. */
			esc_html__( 'Dodo answered, and no product carries %s in its metadata. Set it on a product in the Dodo dashboard and it becomes sellable here, with no changes to this site.', 'wp-dodo-checkout' ),
			'<code>' . esc_html( WPDC_PLAN_KEY ) . '</code>'
		);
		echo '</p></div>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr>';
	echo '<th>' . esc_html__( 'Plan key', 'wp-dodo-checkout' ) . '</th>';
	echo '<th>' . esc_html__( 'Shortcode', 'wp-dodo-checkout' ) . '</th>';
	echo '</tr></thead><tbody>';

	// Keys only, so the product id is never even in scope here. It is not
	// something an editor needs, and a page that prints it invites somebody to
	// put it in a shortcode -- which is exactly what the plan key exists to
	// stop. Not fetching it is a stronger guarantee than not echoing it.
	foreach ( array_keys( $map ) as $plan ) {
		printf(
			'<tr><td><code>%s</code></td><td><code>[wpdc_checkout plan="%s" display="overlay"]</code></td></tr>',
			esc_html( $plan ),
			esc_attr( $plan )
		);
	}

	echo '</tbody></table>';
}
