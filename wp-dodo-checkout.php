<?php
/**
 * Plugin Name: WP Dodo Checkout
 * Plugin URI: https://unleash-wp.com
 * Description: Puts a Dodo Payments checkout on a WordPress page via shortcode, inline or as an overlay, with an optional order bump. Products come from your Dodo account; nothing is configured twice.
 * Version: 0.6.9
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: UnleashWP
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-dodo-checkout
 * Domain Path: /languages
 *
 * ── What this plugin is ────────────────────────────────────────────────────
 *
 * It renders a button and asks Dodo Payments for a checkout URL. A page names a
 * Dodo product id; Dodo owns the price, the tax, the receipt and the delivery.
 * Nothing here computes an amount and nothing here stores a customer.
 *
 * ── Where the API key lives, and why that matters ──────────────────────────
 *
 * Dodo has no scoped API keys -- Owner/Editor/Viewer are dashboard user roles,
 * not key permissions -- so the one key this plugin holds can create payments,
 * issue refunds and read every customer on the account. A WordPress install
 * runs whatever plugins its owner added, any one of which can read the options
 * table, so the key belongs in wp-config.php as WPDC_API_KEY. The settings
 * screen accepts one either way and says which it is using.
 *
 * ── Nothing here is specific to any shop ───────────────────────────────────
 *
 * No hardcoded product ids, no prices, no copy about a particular business. The
 * key, the mode and the product ids are configuration, and the ids come from
 * Dodo itself. Dropping this into another site is a matter of one key.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPDC_VERSION', '0.6.9' );
define( 'WPDC_DIR', plugin_dir_path( __FILE__ ) );

require_once WPDC_DIR . 'includes/config.php';
require_once WPDC_DIR . 'includes/client.php';
require_once WPDC_DIR . 'includes/shortcode.php';
require_once WPDC_DIR . 'includes/rest.php';
require_once WPDC_DIR . 'includes/apple-pay.php';
require_once WPDC_DIR . 'includes/settings.php';

/**
 * Bundled translations, loaded explicitly.
 *
 * The Text Domain header alone does nothing for a plugin that is not on
 * wordpress.org: WordPress fetches those translations from there, finds
 * nothing, and every string stays English. On a German shop that means
 * "Subtotal / Discount / VAT" beside a German checkout, which is how this was
 * noticed.
 */
function wpdc_load_textdomain(): void {
	load_plugin_textdomain( 'wp-dodo-checkout', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'wpdc_load_textdomain' );

add_action( 'init', 'wpdc_register_shortcode' );
add_action( 'init', 'wpdc_serve_apple_pay_association' );
add_action( 'rest_api_init', 'wpdc_register_rest' );
add_action( 'admin_init', 'wpdc_register_settings' );
add_action( 'admin_menu', 'wpdc_register_settings_page' );
