<?php
/**
 * Flux Family Kit: core 1.0.0.
 *
 * Gives every Flux plugin one shared "Flux" admin dashboard (what is installed, what is active,
 * where each plugin's settings are, a one-cursor warning) and one shared "Support the Flux family"
 * card. No network requests, no tracking, no keys: support buttons are plain links to pages hosted
 * by Stripe, Buy Me a Coffee or PayPal.
 *
 * @package Flux_Family
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Flux_Family_Core', false ) ) {

	final class Flux_Family_Core {

		const VERSION = '1.0.0';

		/** Author's defaults. Paste the Stripe Payment Link below (or define the constant in wp-config.php). */
		const DEFAULT_STRIPE_URL = '';
		const DEFAULT_COFFEE_URL = 'https://www.buymeacoffee.com/fusionmma';
		const DEFAULT_PAYPAL_URL = 'https://www.paypal.com/donate/?hosted_button_id=83Z86HGGSE2PU';

		/** Option: hide the support card on plugin settings screens. */
		const OPT_HIDE = 'flux_family_hide_support';

		private static $started = false;

		public static function init() {
			if ( self::$started ) {
				return;
			}
			self::$started = true;
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 9 );
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		}

		/** Every plugin that has registered itself, sorted by name. */
		public static function plugins() {
			global $flux_family_registry;
			$list = is_array( $flux_family_registry ) ? $flux_family_registry : array();
			uasort(
				$list,
				function ( $a, $b ) {
					return strcasecmp( (string) $a['name'], (string) $b['name'] );
				}
			);
			return $list;
		}

		/** The known members of the family (so the dashboard can show what is not installed yet). */
		public static function catalogue() {
			$known = array(
				'flux-reel'              => array( 'Flux Reel', 'Scroll timecode, film-style menu overlay and footer reel.' ),
				'flux-backgrounds'       => array( 'Flux Backgrounds', 'Animated WebGL backgrounds.' ),
				'flux-preloader'         => array( 'Flux Preloader', 'Intro splash that counts up while the page loads.' ),
				'flux-reveal'            => array( 'Flux Reveal', 'Blur-in scroll reveals for headings and sections.' ),
				'flux-smooth-scroll'     => array( 'Flux Smooth Scroll', 'Weighted smooth scrolling (Lenis).' ),
				'flux-scroll-progress'   => array( 'Flux Scroll Progress', 'Slim reading-progress bar.' ),
				'flux-jelly-cursor'      => array( 'Flux Jelly Cursor', 'Elastic jelly blob cursor.' ),
				'flux-bubble-cursor'     => array( 'Flux Bubble Cursor', 'Dot, ring and smoke-trail cursor.' ),
				'super-duper-cursor'     => array( 'Super Duper Cursor', 'Dual-layer cursor that reacts to what you hover.' ),
				'super-duper-cursor-pro' => array( 'Super Duper Cursor Pro', 'Dual and jelly cursor engines in one plugin.' ),
			);
			return apply_filters( 'flux_family_catalogue', $known );
		}

		/** Groups where only one active plugin makes sense. */
		public static function exclusive_groups() {
			return apply_filters( 'flux_family_exclusive_groups', array( 'cursor' => __( 'a custom cursor', 'flux-family' ) ) );
		}

		/** Human warnings, e.g. two cursor plugins active. */
		public static function conflicts() {
			$by_group = array();
			foreach ( self::plugins() as $p ) {
				$g = isset( $p['group'] ) ? (string) $p['group'] : '';
				if ( '' !== $g ) {
					$by_group[ $g ][] = $p['name'];
				}
			}
			$out = array();
			foreach ( self::exclusive_groups() as $group => $label ) {
				if ( isset( $by_group[ $group ] ) && count( $by_group[ $group ] ) > 1 ) {
					$out[] = sprintf(
						/* translators: 1: group label such as "a custom cursor", 2: plugin names */
						__( 'A page can only have %1$s at a time, but these are all active: %2$s. Keep one active and deactivate the others.', 'flux-family' ),
						$label,
						implode( ', ', $by_group[ $group ] )
					);
				}
			}
			return $out;
		}

		/** Allow only https links. */
		private static function clean_url( $url ) {
			$url = is_string( $url ) ? trim( $url ) : '';
			if ( '' === $url ) {
				return '';
			}
			return esc_url_raw( $url, array( 'https' ) );
		}

		/** Support buttons, in order: label => url (empty ones are dropped). */
		public static function support_links() {
			$stripe = defined( 'FLUX_FAMILY_STRIPE_URL' ) ? FLUX_FAMILY_STRIPE_URL : self::DEFAULT_STRIPE_URL;
			$links  = array(
				'stripe' => array( __( 'Support with Stripe', 'flux-family' ), $stripe ),
				'coffee' => array( __( 'Buy me a coffee', 'flux-family' ), self::DEFAULT_COFFEE_URL ),
				'paypal' => array( __( 'Donate via PayPal', 'flux-family' ), self::DEFAULT_PAYPAL_URL ),
			);
			$links  = apply_filters( 'flux_family_support_links', $links );
			$clean  = array();
			if ( is_array( $links ) ) {
				foreach ( $links as $key => $pair ) {
					if ( ! is_array( $pair ) || count( $pair ) < 2 ) {
						continue;
					}
					$url = self::clean_url( $pair[1] );
					if ( '' !== $url ) {
						$clean[ sanitize_key( (string) $key ) ] = array( (string) $pair[0], $url );
					}
				}
			}
			return $clean;
		}

		/** Link to the author's store/site, if set (constant or filter). */
		public static function store_url() {
			$url = defined( 'FLUX_FAMILY_STORE_URL' ) ? FLUX_FAMILY_STORE_URL : '';
			return self::clean_url( apply_filters( 'flux_family_store_url', $url ) );
		}

		public static function support_hidden() {
			return (bool) apply_filters( 'flux_family_hide_support', (bool) get_option( self::OPT_HIDE, false ) );
		}

		public static function dashboard_url() {
			return admin_url( 'admin.php?page=flux-family' );
		}

		// --- Admin ---

		public static function register_settings() {
			register_setting(
				'flux_family',
				self::OPT_HIDE,
				array(
					'type'              => 'boolean',
					'sanitize_callback' => function ( $v ) {
						return ! empty( $v ) ? 1 : 0;
					},
					'default'           => 0,
				)
			);
		}

		public static function admin_menu() {
			add_menu_page(
				__( 'Flux Family', 'flux-family' ),
				__( 'Flux', 'flux-family' ),
				'manage_options',
				'flux-family',
				array( __CLASS__, 'render_dashboard' ),
				'dashicons-screenoptions',
				81
			);
		}

		private static function settings_link( $p ) {
			$url = isset( $p['settings_url'] ) ? $p['settings_url'] : '';
			if ( is_callable( $url ) ) {
				$url = call_user_func( $url );
			}
			return is_string( $url ) ? esc_url( $url ) : '';
		}

		public static function render_dashboard() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$installed = self::plugins();
			$known     = self::catalogue();
			$store     = self::store_url();
			echo '<div class="wrap"><h1>' . esc_html__( 'Flux Family', 'flux-family' ) . '</h1>';
			echo '<p>' . esc_html__( 'The Flux plugins are one family: they share conventions, a dashboard and a support page, and they are built to work together. Each one also works fine on its own.', 'flux-family' ) . '</p>';
			foreach ( self::conflicts() as $msg ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( $msg ) . '</p></div>';
			}
			echo '<h2>' . esc_html__( 'Active on this site', 'flux-family' ) . '</h2>';
			if ( ! $installed ) {
				echo '<p>' . esc_html__( 'No Flux plugin has registered yet.', 'flux-family' ) . '</p>';
			} else {
				echo '<table class="widefat striped" style="max-width:820px"><thead><tr><th>' . esc_html__( 'Plugin', 'flux-family' ) . '</th><th>' . esc_html__( 'Version', 'flux-family' ) . '</th><th>' . esc_html__( 'What it does', 'flux-family' ) . '</th><th></th></tr></thead><tbody>';
				foreach ( $installed as $slug => $p ) {
					$link = self::settings_link( $p );
					echo '<tr><td><strong>' . esc_html( $p['name'] ) . '</strong></td><td>' . esc_html( isset( $p['version'] ) ? (string) $p['version'] : '' ) . '</td><td>' . esc_html( isset( $p['description'] ) ? (string) $p['description'] : '' ) . '</td><td>';
					if ( '' !== $link ) {
						echo '<a class="button" href="' . $link . '">' . esc_html__( 'Settings', 'flux-family' ) . '</a>';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
			}
			$missing = array_diff_key( $known, $installed );
			if ( $missing ) {
				echo '<h2>' . esc_html__( 'More in the family', 'flux-family' ) . '</h2><ul style="list-style:disc;margin-left:20px">';
				foreach ( $missing as $slug => $info ) {
					echo '<li><strong>' . esc_html( $info[0] ) . '</strong>: ' . esc_html( $info[1] ) . '</li>';
				}
				echo '</ul>';
				if ( '' !== $store ) {
					echo '<p><a class="button button-primary" href="' . esc_url( $store ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'See the full Flux family', 'flux-family' ) . '</a></p>';
				}
			}
			self::render_support_card();
			echo '<h2>' . esc_html__( 'Options', 'flux-family' ) . '</h2><form method="post" action="options.php">';
			settings_fields( 'flux_family' );
			echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_HIDE ) . '" value="1" ' . checked( (bool) get_option( self::OPT_HIDE, false ), true, false ) . '> ' . esc_html__( 'Hide the support card on the plugin settings screens', 'flux-family' ) . '</label>';
			submit_button();
			echo '</form></div>';
		}

		/** The support card. Used on the dashboard and, via render_card(), on plugin screens. */
		private static function render_support_card() {
			$links = self::support_links();
			if ( ! $links ) {
				return;
			}
			echo '<div class="flux-family-support" style="background:#f9f9f9;padding:16px;border-left:4px solid #2271b1;margin:24px 0;max-width:820px">';
			echo '<h3 style="margin-top:0">' . esc_html__( 'Support the Flux family', 'flux-family' ) . '</h3>';
			echo '<p>' . esc_html__( 'These plugins are built and maintained by one person. If they save you time, a small contribution keeps the family growing.', 'flux-family' ) . '</p><p>';
			foreach ( $links as $pair ) {
				echo '<a href="' . esc_url( $pair[1] ) . '" target="_blank" rel="noopener noreferrer" class="button" style="margin-right:8px">' . esc_html( $pair[0] ) . '</a>';
			}
			echo '</p></div>';
		}

		/** Strip for a plugin's own settings screen: family link, one-cursor warning, support card. */
		public static function render_card( $plugin_name = '' ) {
			$count = count( self::plugins() );
			echo '<div class="flux-family-strip" style="max-width:820px;margin:20px 0">';
			echo '<p>' . sprintf(
				/* translators: 1: plugin name, 2: link to the Flux dashboard */
				esc_html__( '%1$s is part of the Flux family. %2$s', 'flux-family' ),
				esc_html( (string) $plugin_name ),
				'<a href="' . esc_url( self::dashboard_url() ) . '">' . esc_html( sprintf( _n( 'See the %d active Flux plugin.', 'See the %d active Flux plugins.', $count, 'flux-family' ), $count ) ) . '</a>'
			) . '</p>';
			foreach ( self::conflicts() as $msg ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html( $msg ) . '</p></div>';
			}
			if ( ! self::support_hidden() ) {
				self::render_support_card();
			}
			echo '</div>';
		}
	}
}
