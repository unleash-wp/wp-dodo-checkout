<?php
/**
 * Removing the plugin removes the API key it stored. That key can issue refunds,
 * so it must not outlive the plugin in the options table and every backup.
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wpdc_api_key' );
