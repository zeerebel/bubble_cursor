<?php
/**
 * Flux Family Kit: loader (STABLE API: never change these signatures).
 *
 * Every Flux plugin ships its own copy of this kit so it stays installable alone. When several
 * Flux plugins are active, each one OFFERS its copy of core.php and the highest version is the
 * only one loaded. Plugins also REGISTER themselves here, so the shared "Flux" dashboard can list
 * them. Nothing here talks to the network.
 *
 * @package Flux_Family
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'flux_family_offer' ) ) {

	/**
	 * Offer this plugin's copy of the kit core. Call once, at file load.
	 *
	 * @param string $version   Kit version of this copy, e.g. '1.0.0'.
	 * @param string $core_file Absolute path to this copy's core.php.
	 */
	function flux_family_offer( $version, $core_file ) {
		global $flux_family_offers;
		if ( ! is_array( $flux_family_offers ) ) {
			$flux_family_offers = array();
		}
		$flux_family_offers[ (string) $version ] = (string) $core_file;
		if ( ! has_action( 'plugins_loaded', 'flux_family_boot' ) ) {
			add_action( 'plugins_loaded', 'flux_family_boot', 1 );
		}
	}

	/**
	 * Register a Flux plugin with the family. Call once, at file load.
	 *
	 * @param array $args {
	 *     @type string          $slug         Final repo/plugin name, e.g. 'flux-reveal' (required).
	 *     @type string          $name         Display name, e.g. 'Flux Reveal' (required).
	 *     @type string          $version      The plugin's own version.
	 *     @type string          $group        'cursor' (only one may be active), 'scroll', 'visual', 'ui'.
	 *     @type string|callable $settings_url Admin URL of the plugin's settings page, or a callable returning it.
	 *     @type string          $description  One line.
	 * }
	 */
	function flux_family_register( $args ) {
		global $flux_family_registry;
		if ( ! is_array( $args ) || empty( $args['slug'] ) || empty( $args['name'] ) ) {
			return;
		}
		if ( ! is_array( $flux_family_registry ) ) {
			$flux_family_registry = array();
		}
		$flux_family_registry[ (string) $args['slug'] ] = $args;
	}

	/** Load the highest offered core, once, in the admin, after all plugin files are included. */
	function flux_family_boot() {
		global $flux_family_offers;
		// The kit is admin-only: visitors never load core.php, so it costs the front end nothing.
		if ( ! is_admin() || class_exists( 'Flux_Family_Core', false ) || empty( $flux_family_offers ) ) {
			return;
		}
		$versions = array_keys( $flux_family_offers );
		usort( $versions, 'version_compare' );
		$file = $flux_family_offers[ end( $versions ) ];
		if ( is_readable( $file ) ) {
			require_once $file;
		}
		if ( class_exists( 'Flux_Family_Core', false ) ) {
			Flux_Family_Core::init();
		}
	}

	/**
	 * Print the family strip and support card. Safe to call from any plugin's settings page; prints
	 * nothing if the kit core is not loaded.
	 *
	 * @param string $plugin_name Display name of the calling plugin.
	 */
	function flux_family_render_card( $plugin_name = '' ) {
		if ( class_exists( 'Flux_Family_Core', false ) ) {
			Flux_Family_Core::render_card( $plugin_name );
		}
	}
}
