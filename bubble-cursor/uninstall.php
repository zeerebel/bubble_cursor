<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 * Removes the stored options so nothing is left behind.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'bubble_cursor_options' );
