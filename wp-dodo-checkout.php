<?php
/**
 * Plugin Name: WP Dodo Checkout
 * Plugin URI: https://unleash-wp.com
 * Description: Puts a Dodo Payments checkout on a WordPress page via shortcode, inline or as an overlay, with an optional order bump. The payment API key never reaches this site.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: UnleashWP
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-dodo-checkout
 *
 * ── What this plugin is, and what it refuses to be ──────────────────────────
 *
 * It renders a button and it asks one endpoint for a checkout URL. It holds no
 * payment credential, knows no prices, and has no list of products: a page
 * names a PLAN KEY ("pro", "ebook") and the server decides what that is worth
 * and which Dodo product it maps to.
 *
 * That split is the whole design. DODO_API_KEY is account-wide, and a
 * WordPress install runs whatever plugins its owner added -- any one of which
 * can read wp-config.php and the options table. So the key stays on the
 * licence server, this plugin holds only a shared secret that can do exactly
 * one thing, and prices live where they cannot be edited by a browser.
 *
 * ── Nothing here is specific to UnleashWP ───────────────────────────────────
 *
 * No product ids, no prices, no copy about any particular shop. The API key,
 * the mode and the plan keys are configuration, and the plan keys come from
 * Dodo itself. Dropping this into another site is a matter of one key.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPDC_VERSION', '0.1.0' );
define( 'WPDC_DIR', plugin_dir_path( __FILE__ ) );

require_once WPDC_DIR . 'includes/config.php';
require_once WPDC_DIR . 'includes/client.php';
require_once WPDC_DIR . 'includes/shortcode.php';
require_once WPDC_DIR . 'includes/rest.php';
require_once WPDC_DIR . 'includes/apple-pay.php';
require_once WPDC_DIR . 'includes/settings.php';

add_action( 'init', 'wpdc_register_shortcode' );
add_action( 'init', 'wpdc_serve_apple_pay_association' );
add_action( 'rest_api_init', 'wpdc_register_rest' );
add_action( 'admin_init', 'wpdc_register_settings' );
add_action( 'admin_menu', 'wpdc_register_settings_page' );
