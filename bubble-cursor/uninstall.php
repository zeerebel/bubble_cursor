<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 * Removes the stored options so nothing is left behind (every site on
 * multisite, plus the network-level option).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( is_multisite() ) {
	$bubble_cursor_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $bubble_cursor_site_ids as $bubble_cursor_site_id ) {
		delete_blog_option( $bubble_cursor_site_id, 'bubble_cursor_options' );
	}
	delete_site_option( 'bubble_cursor_options' );
} else {
	delete_option( 'bubble_cursor_options' );
}
