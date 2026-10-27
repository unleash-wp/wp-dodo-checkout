<?php
/**
 * Press access: licence keys for press, promotion and testers, without a purchase.
 *
 * The key is made here, sent to Dodo once and shown once. WordPress keeps only
 * the lic_ id and the last four characters, so a database dump cannot activate
 * anything. Same shape as a key made by hand in the dashboard: no payment, no
 * subscription, no expiry. A complimentary key never carries a date.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WPDC_PRESS_OPTION = 'wpdc_press_keys';

function wpdc_press_capability(): string {
	$cap = apply_filters( 'wpdc_press_capability', 'manage_options' );
	return is_string( $cap ) && '' !== $cap ? $cap : 'manage_options';
}

/**
 * Product ids that may be given away, from WPDC_PRESS_PRODUCTS.
 *
 * Its own list, independent of WPDC_ALLOWED_PRODUCTS: what may be sold and
 * what may be given away are different decisions. Empty switches the page off.
 */
function wpdc_press_products(): array {
	$ids = array();
	if ( defined( 'WPDC_PRESS_PRODUCTS' ) && is_string( WPDC_PRESS_PRODUCTS ) ) {
		$ids = array_map( 'trim', explode( ',', WPDC_PRESS_PRODUCTS ) );
	}
	$ids = apply_filters( 'wpdc_press_products', $ids );
	return is_array( $ids ) ? array_values( array_unique( array_filter( $ids, 'wpdc_is_product_id' ) ) ) : array();
}

function wpdc_press_enabled(): bool {
	return array() !== wpdc_press_products();
}

/**
 * XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX from 128 random bits.
 */
function wpdc_press_generate_key(): string {
	$key      = implode( '-', str_split( strtoupper( bin2hex( random_bytes( 16 ) ) ), 4 ) );
	$filtered = apply_filters( 'wpdc_press_generate_key', $key );
	// A filter that returns nothing usable must not produce an empty or spaced key.
	return is_string( $filtered ) && 1 === preg_match( '/^[A-Za-z0-9-]{8,128}$/', $filtered ) ? $filtered : $key;
}

/**
 * The Dodo product name for an id, cached; the id itself when Dodo cannot say.
 */
function wpdc_press_product_name( string $id ): string {
	$key    = 'wpdc_press_name_' . md5( wpdc_mode() . $id );
	$cached = get_transient( $key );
	if ( is_string( $cached ) && '' !== $cached ) {
		return $cached;
	}
	if ( ! wpdc_is_product_id( $id ) || ! wpdc_is_configured() ) {
		return $id;
	}
	$product = wpdc_dodo_request( 'GET', '/products/' . rawurlencode( $id ) );
	$name    = wpdc_is_error( $product ) ? '' : trim( (string) ( $product['name'] ?? '' ) );
	if ( '' === $name ) {
		return $id;
	}
	set_transient( $key, $name, HOUR_IN_SECONDS );
	return $name;
}

function wpdc_press_records(): array {
	$records = get_option( WPDC_PRESS_OPTION, array() );
	return is_array( $records ) ? $records : array();
}

/**
 * Dodo's failures, said for the owner rather than for a checkout visitor.
 */
function wpdc_press_dodo_error( array $error ): array {
	if ( 'unauthorised' === $error['reason'] ) {
		return wpdc_error( 'unauthorised', false, __( 'Dodo Payments refused the API key. Check the key and the mode.', 'wp-dodo-checkout' ) );
	}
	if ( 'not_configured' === $error['reason'] ) {
		return $error;
	}
	return wpdc_error( $error['reason'], $error['retriable'], __( 'Dodo Payments did not accept this. Nothing was created or changed.', 'wp-dodo-checkout' ) );
}

/**
 * Find the Dodo customer for this email, or create one.
 *
 * @return string|array A cus_ id, or an error array.
 */
function wpdc_press_customer( string $email, string $name ) {
	$found = wpdc_dodo_request( 'GET', '/customers?email=' . rawurlencode( $email ) . '&page_number=0&page_size=100' );
	if ( wpdc_is_error( $found ) ) {
		return wpdc_press_dodo_error( $found );
	}
	// The filter is Dodo's; the match is ours, so a loose search cannot hand over a stranger.
	foreach ( (array) ( $found['items'] ?? array() ) as $row ) {
		if ( is_array( $row ) && is_string( $row['customer_id'] ?? null ) && 0 === strcasecmp( (string) ( $row['email'] ?? '' ), $email ) ) {
			return $row['customer_id'];
		}
	}

	$created = wpdc_dodo_request( 'POST', '/customers', array( 'email' => $email, 'name' => $name ) );
	if ( wpdc_is_error( $created ) ) {
		return wpdc_press_dodo_error( $created );
	}
	return is_string( $created['customer_id'] ?? null )
		? $created['customer_id']
		: wpdc_error( 'bad_response', true, __( 'Dodo Payments did not accept this. Nothing was created or changed.', 'wp-dodo-checkout' ) );
}

/**
 * Create one key. Validated input in, the record and the key out.
 */
function wpdc_press_create( array $input, int $user_id ): array {
	$invalid = __( 'Please check the form: name, a valid email, an allowed product and a site limit of 1 or more.', 'wp-dodo-checkout' );

	$name    = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
	$email   = sanitize_email( (string) ( $input['email'] ?? '' ) );
	$product = (string) ( $input['product'] ?? '' );
	$reason  = sanitize_textarea_field( (string) ( $input['reason'] ?? '' ) );
	$limit   = trim( (string) ( $input['limit'] ?? '' ) );

	if ( ! in_array( $product, wpdc_press_products(), true ) ) {
		return wpdc_error( 'product_not_allowed', false, __( 'This product is not released for press access.', 'wp-dodo-checkout' ) );
	}
	if ( '' === $name || ! is_email( $email ) || ( '' !== $limit && 1 !== preg_match( '/^[1-9][0-9]{0,5}$/', $limit ) ) ) {
		return wpdc_error( 'invalid_input', false, $invalid );
	}
	if ( ! wpdc_is_configured() ) {
		return wpdc_error( 'not_configured', false, __( 'No Dodo API key is set, so no key can be created.', 'wp-dodo-checkout' ) );
	}

	$customer = wpdc_press_customer( $email, $name );
	if ( is_array( $customer ) ) {
		return $customer;
	}

	$args = apply_filters(
		'wpdc_press_key_args',
		array(
			'customer_id'       => $customer,
			'product_id'        => $product,
			'key'               => wpdc_press_generate_key(),
			'activations_limit' => '' === $limit ? null : (int) $limit,
			'expires_at'        => null,
		),
		$input
	);
	if ( is_array( $args ) ) {
		// Owner rule: a complimentary key never expires, whatever a filter says.
		$args['expires_at'] = null;
	}
	// The filter shapes the body; it does not get to widen the allowlist.
	if ( ! is_array( $args ) || ! in_array( $args['product_id'] ?? null, wpdc_press_products(), true )
		|| ! is_string( $args['key'] ?? null ) || '' === $args['key'] || ! is_string( $args['customer_id'] ?? null ) ) {
		return wpdc_error( 'invalid_input', false, $invalid );
	}

	$made = wpdc_dodo_request( 'POST', '/license_keys', $args );
	if ( wpdc_is_error( $made ) ) {
		return wpdc_press_dodo_error( $made );
	}
	if ( ! is_string( $made['id'] ?? null ) || 1 !== preg_match( '/^lic_[A-Za-z0-9]+$/', $made['id'] ) ) {
		return wpdc_error( 'bad_response', true, __( 'Dodo Payments did not accept this. Nothing was created or changed.', 'wp-dodo-checkout' ) );
	}

	$record = array(
		'id'         => $made['id'],
		'last4'      => substr( $args['key'], -4 ),
		'name'       => $name,
		'email'      => $email,
		'product'    => $args['product_id'],
		'reason'     => $reason,
		'created_at' => time(),
		'created_by' => $user_id,
		'mode'       => wpdc_mode(),
	);
	$records                  = wpdc_press_records();
	$records[ $record['id'] ] = $record;
	// Not autoloaded: names and emails have no business on every page load.
	update_option( WPDC_PRESS_OPTION, $records, false );

	do_action( 'wpdc_press_key_created', $record, $args['key'] );

	return array(
		'ok'     => true,
		'record' => $record,
		'key'    => $args['key'],
	);
}

/**
 * Disable or enable a key this page issued.
 */
function wpdc_press_toggle( string $id, bool $disable ): array {
	$records = wpdc_press_records();
	if ( ! isset( $records[ $id ] ) ) {
		return wpdc_error( 'unknown_key', false, __( 'This key was not issued here.', 'wp-dodo-checkout' ) );
	}
	$done = wpdc_dodo_request( 'PATCH', '/license_keys/' . rawurlencode( $id ), array( 'disabled' => $disable ) );
	if ( wpdc_is_error( $done ) ) {
		return wpdc_press_dodo_error( $done );
	}
	if ( $disable ) {
		do_action( 'wpdc_press_key_revoked', $records[ $id ] );
	}
	return array( 'ok' => true, 'record' => $records[ $id ] );
}

/**
 * Every POST to the page comes through here: right, nonce, then the action.
 */
function wpdc_press_handle( array $post ): array {
	$action = (string) ( $post['wpdc_press'] ?? '' );
	$id     = (string) ( $post['id'] ?? '' );
	$nonce  = 'create' === $action ? 'wpdc_press_create' : 'wpdc_press_toggle_' . $id;

	if ( ! wpdc_press_enabled() || ! current_user_can( wpdc_press_capability() ) ) {
		return wpdc_error( 'forbidden', false, __( 'You are not allowed to do this.', 'wp-dodo-checkout' ) );
	}
	if ( ! wp_verify_nonce( (string) ( $post['_wpnonce'] ?? '' ), $nonce ) ) {
		return wpdc_error( 'bad_nonce', false, __( 'This form has expired. Please reload the page and try again.', 'wp-dodo-checkout' ) );
	}

	if ( 'create' === $action ) {
		// A reload resubmits the POST; the token makes the second one a no-op.
		$once = (string) ( $post['wpdc_press_once'] ?? '' );
		if ( 1 !== preg_match( '/^[a-f0-9]{16,64}$/', $once ) || false !== get_transient( 'wpdc_press_once_' . $once ) ) {
			return wpdc_error( 'already_done', false, __( 'This form was already sent. Reloading does not create a second key.', 'wp-dodo-checkout' ) );
		}
		set_transient( 'wpdc_press_once_' . $once, 1, DAY_IN_SECONDS );
		return wpdc_press_create( $post, get_current_user_id() );
	}
	if ( ( 'disable' === $action || 'enable' === $action ) && 1 === preg_match( '/^lic_[A-Za-z0-9]+$/', $id ) ) {
		return wpdc_press_toggle( $id, 'disable' === $action );
	}
	return wpdc_error( 'invalid_input', false, __( 'Unknown action.', 'wp-dodo-checkout' ) );
}

function wpdc_register_press_page(): void {
	if ( ! wpdc_press_enabled() ) {
		return;
	}
	add_submenu_page(
		'options-general.php',
		__( 'Press access', 'wp-dodo-checkout' ),
		__( 'Press access', 'wp-dodo-checkout' ),
		wpdc_press_capability(),
		'wpdc-press',
		'wpdc_render_press_page'
	);
}

function wpdc_press_status_label( string $status ): string {
	$labels = array(
		'active'   => __( 'Active', 'wp-dodo-checkout' ),
		'disabled' => __( 'Disabled', 'wp-dodo-checkout' ),
		'expired'  => __( 'Expired', 'wp-dodo-checkout' ),
	);
	return $labels[ $status ] ?? __( 'Status unknown', 'wp-dodo-checkout' );
}

function wpdc_render_press_page(): void {
	if ( ! wpdc_press_enabled() || ! current_user_can( wpdc_press_capability() ) ) {
		return;
	}

	$result = null;
	if ( isset( $_POST['wpdc_press'] ) ) {
		$result = wpdc_press_handle( wp_unslash( $_POST ) );
	}

	$names = array();
	foreach ( wpdc_press_products() as $id ) {
		$name         = wpdc_press_product_name( $id );
		$names[ $id ] = $name === $id ? $id : $name . ' (' . $id . ')';
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Press access', 'wp-dodo-checkout' ); ?></h1>
		<p><?php echo esc_html__( 'Licence keys for press, promotion and testers, without a purchase. No email is sent: you pass the key on yourself.', 'wp-dodo-checkout' ); ?></p>

		<?php if ( 'test_mode' === wpdc_mode() ) : ?>
			<div class="notice notice-info inline"><p><?php echo esc_html__( 'Test mode: keys are created in the Dodo Payments test environment and do not work against live.', 'wp-dodo-checkout' ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! wpdc_is_configured() ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html__( 'No Dodo API key is set, so no key can be created.', 'wp-dodo-checkout' ); ?></p></div>
		<?php endif; ?>

		<?php if ( is_array( $result ) && true === ( $result['ok'] ?? false ) && isset( $result['key'] ) ) : ?>
			<div class="notice notice-success inline">
				<p><strong><?php echo esc_html__( 'Key created. Copy it now: it is shown only this once and is not stored on this site.', 'wp-dodo-checkout' ); ?></strong></p>
				<p>
					<input type="text" id="wpdc-press-key" class="large-text code" readonly autocomplete="off" value="<?php echo esc_attr( $result['key'] ); ?>" onfocus="this.select()" />
				</p>
				<p><button type="button" class="button" onclick="var k=document.getElementById('wpdc-press-key');k.select();if(navigator.clipboard){navigator.clipboard.writeText(k.value)}"><?php echo esc_html__( 'Copy key', 'wp-dodo-checkout' ); ?></button></p>
			</div>
		<?php elseif ( is_array( $result ) && true === ( $result['ok'] ?? false ) ) : ?>
			<div class="notice notice-success inline"><p><?php echo esc_html__( 'Saved at Dodo Payments.', 'wp-dodo-checkout' ); ?></p></div>
		<?php elseif ( is_array( $result ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( (string) $result['message'] ); ?></p></div>
		<?php endif; ?>

		<h2><?php echo esc_html__( 'New key', 'wp-dodo-checkout' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'wpdc_press_create' ); ?>
			<input type="hidden" name="wpdc_press" value="create" />
			<input type="hidden" name="wpdc_press_once" value="<?php echo esc_attr( bin2hex( random_bytes( 16 ) ) ); ?>" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpdc-press-name"><?php echo esc_html__( 'Name', 'wp-dodo-checkout' ); ?></label></th>
					<td><input type="text" id="wpdc-press-name" name="name" class="regular-text" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wpdc-press-email"><?php echo esc_html__( 'Email', 'wp-dodo-checkout' ); ?></label></th>
					<td><input type="email" id="wpdc-press-email" name="email" class="regular-text" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wpdc-press-product"><?php echo esc_html__( 'Product', 'wp-dodo-checkout' ); ?></label></th>
					<td>
						<select id="wpdc-press-product" name="product">
							<?php foreach ( $names as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpdc-press-limit"><?php echo esc_html__( 'Site limit', 'wp-dodo-checkout' ); ?></label></th>
					<td>
						<input type="number" id="wpdc-press-limit" name="limit" min="1" step="1" class="small-text" />
						<p class="description"><?php echo esc_html__( 'Empty means unlimited.', 'wp-dodo-checkout' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpdc-press-reason"><?php echo esc_html__( 'Reason', 'wp-dodo-checkout' ); ?></label></th>
					<td><textarea id="wpdc-press-reason" name="reason" rows="2" class="large-text"></textarea></td>
				</tr>
			</table>
			<?php submit_button( __( 'Create key', 'wp-dodo-checkout' ) ); ?>
		</form>

		<h2><?php echo esc_html__( 'Issued keys', 'wp-dodo-checkout' ); ?></h2>
		<?php wpdc_render_press_list(); ?>
	</div>
	<?php
}

function wpdc_render_press_list(): void {
	$mode    = wpdc_mode();
	$all     = wpdc_press_records();
	$records = array_filter( $all, static fn( $r ) => is_array( $r ) && ( $r['mode'] ?? '' ) === $mode );
	$hidden  = count( $all ) - count( $records );

	if ( array() === $records ) {
		echo '<p>' . esc_html__( 'No keys issued yet in this mode.', 'wp-dodo-checkout' ) . '</p>';
	} else {
		uasort( $records, static fn( $a, $b ) => (int) $b['created_at'] <=> (int) $a['created_at'] );
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Recipient', 'wp-dodo-checkout' ), __( 'Product', 'wp-dodo-checkout' ), __( 'Key', 'wp-dodo-checkout' ), __( 'Reason', 'wp-dodo-checkout' ), __( 'Created', 'wp-dodo-checkout' ), __( 'Status', 'wp-dodo-checkout' ), '' ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		// One lookup per key, capped: the status is Dodo's, never a copy kept here.
		foreach ( array_slice( $records, 0, 50, true ) as $id => $record ) {
			$live   = wpdc_dodo_request( 'GET', '/license_keys/' . rawurlencode( (string) $id ) );
			$status = wpdc_is_error( $live ) ? '' : (string) ( $live['status'] ?? '' );
			$author = get_userdata( (int) $record['created_by'] );
			$sites  = '';
			if ( ! wpdc_is_error( $live ) ) {
				$limit = $live['activations_limit'] ?? null;
				$sites = is_int( $limit )
					? sprintf( _n( 'Websites: %d', 'Websites: %d', $limit, 'wp-dodo-checkout' ), $limit )
					: __( 'Websites: unlimited', 'wp-dodo-checkout' );
			}

			printf(
				'<tr><td>%s<br>%s</td><td>%s<br><code>%s</code></td><td><code>&hellip;%s</code><br><code>%s</code></td><td>%s</td><td>%s<br>%s</td><td>%s<br><span class="description">%s</span></td><td>',
				esc_html( (string) $record['name'] ),
				esc_html( (string) $record['email'] ),
				esc_html( wpdc_press_product_name( (string) $record['product'] ) ),
				esc_html( (string) $record['product'] ),
				esc_html( (string) $record['last4'] ),
				esc_html( (string) $id ),
				esc_html( (string) $record['reason'] ),
				esc_html( wp_date( 'Y-m-d H:i', (int) $record['created_at'] ) ),
				esc_html( $author ? (string) $author->display_name : '#' . (int) $record['created_by'] ),
				esc_html( wpdc_press_status_label( $status ) ),
				esc_html( $sites )
			);
			if ( 'active' === $status || 'disabled' === $status ) {
				$disable = 'active' === $status;
				echo '<form method="post">';
				wp_nonce_field( 'wpdc_press_toggle_' . $id );
				printf(
					'<input type="hidden" name="wpdc_press" value="%s" /><input type="hidden" name="id" value="%s" /><button type="submit" class="button%s">%s</button>',
					esc_attr( $disable ? 'disable' : 'enable' ),
					esc_attr( (string) $id ),
					$disable ? ' button-link-delete' : '',
					esc_html( $disable ? __( 'Disable', 'wp-dodo-checkout' ) : __( 'Enable', 'wp-dodo-checkout' ) )
				);
				echo '</form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	if ( $hidden > 0 ) {
		echo '<p class="description">' . esc_html( sprintf( _n( '%d key from the other mode is not shown.', '%d keys from the other mode are not shown.', $hidden, 'wp-dodo-checkout' ), $hidden ) ) . '</p>';
	}
}
