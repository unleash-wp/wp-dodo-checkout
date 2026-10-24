<?php
/**
 * The UnleashWP help widget on every page of the shop: search, the AI, a ticket and the news, all read
 * from the help center (support.unleash-wp.com). Loaded from there, so a change to the help center
 * reaches the shop without a plugin release. add_filter( 'wpdc_help_widget', '__return_false' ) turns it off.
 *
 * @package WPDC
 */

defined( 'ABSPATH' ) || exit;

const WPDC_HELP_WIDGET_URL = 'https://support.unleash-wp.com/_dai/api/scripts/file/scripts/uwp-help.js';

function wpdc_help_widget_tag(): string {
	if ( ! apply_filters( 'wpdc_help_widget', true ) ) {
		return '';
	}
	return '<script src="' . WPDC_HELP_WIDGET_URL . '" data-lang="' . wpdc_page_language() . '" defer></script>';
}

function wpdc_print_help_widget(): void {
	echo wpdc_help_widget_tag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed URL and a two-letter language from wpdc_page_language().
}
