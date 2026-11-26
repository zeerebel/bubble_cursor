<?php
/**
 * Plugin Name:       Bubble Cursor — Smokey Fluid Cursor
 * Plugin URI:        https://github.com/zeerebel/bubble_cursor
 * Description:       Adds a colourful WebGL "smoke" fluid trail plus a dot + ring custom cursor with a "View" hover bubble — a replica of the TreeThemes "Deep" theme cursor. Works on any theme (Elementor or not). No coding required.
 * Version:           1.3.1
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            zeerebel
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bubble-cursor
 *
 * The bundled WebGL fluid engine (assets/js/fluid-cursor.js) is adapted from
 * Pavel Dobryakov's WebGL-Fluid-Simulation, distributed under the MIT License.
 * See assets/js/LICENSE-fluid.txt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

// A second copy of the plugin (e.g. a re-uploaded zip in another folder) must
// not redeclare anything: bail out quietly if one is already loaded.
if ( defined( 'BUBBLE_CURSOR_VERSION' ) ) {
	return;
}

define( 'BUBBLE_CURSOR_VERSION', '1.3.1' );
define( 'BUBBLE_CURSOR_FILE', __FILE__ );
define( 'BUBBLE_CURSOR_URL', plugin_dir_url( __FILE__ ) );
define( 'BUBBLE_CURSOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'BUBBLE_CURSOR_OPTION', 'bubble_cursor_options' );

// Flux Family Kit (admin-only; registering is just an array write on the front end).
// Do not edit includes/flux-family/: change the kit in the family hub and sync it.
require_once __DIR__ . '/includes/flux-family/loader.php';
flux_family_offer( '1.0.0', __DIR__ . '/includes/flux-family/core.php' );
flux_family_register(
	array(
		'slug'         => 'flux-bubble-cursor',
		'name'         => 'Flux Bubble Cursor',
		'version'      => BUBBLE_CURSOR_VERSION,
		'group'        => 'cursor',
		'description'  => 'Dot, ring and smoke-trail cursor.',
		'settings_url' => function () {
			return admin_url( 'options-general.php?page=bubble-cursor' );
		},
	)
);

if ( ! class_exists( 'Bubble_Cursor', false ) ) {

	/**
	 * Main plugin class.
	 */
	final class Bubble_Cursor {

		/**
		 * Singleton instance.
		 *
		 * @var Bubble_Cursor
		 */
		private static $instance = null;

		/**
		 * JSON of the front-end settings, mirrored into a data-bc-settings
		 * attribute so optimizers that drop the inline script cannot lose it.
		 *
		 * @var string
		 */
		private $js_settings_json = '';

		/**
		 * Boot the plugin.
		 *
		 * @return Bubble_Cursor
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( BUBBLE_CURSOR_FILE ), array( $this, 'action_links' ) );
			add_filter( 'script_loader_tag', array( $this, 'filter_script_tag' ), 10, 2 );
			// Settings are baked into page HTML, so cached pages must be purged on save.
			add_action( 'update_option_' . BUBBLE_CURSOR_OPTION, array( $this, 'purge_caches' ) );
			add_action( 'add_option_' . BUBBLE_CURSOR_OPTION, array( $this, 'purge_caches' ) );
		}

		/**
		 * Default option values (mirror the TreeThemes "Deep" demo2 cursor).
		 *
		 * @return array
		 */
		public static function defaults() {
			return array(
				'enable'                => 1,
				'scope'                 => 'all',     // all | front.
				'enable_fluid'          => 1,
				'enable_ring'           => 1,
				'enable_dot'            => 1,
				'hide_native'           => 0,
				'hide_on_touch'         => 1,
				'dot_color'             => '#ffffff',
				'ring_color'            => '#ffffff',
				'hover_text'            => 1,         // Show a word in the ring on hover (on/off).
				'hover_label'           => 'View',    // The default word; blank = no word.
				'hover_selector'        => 'a[href], button:not(:disabled), input[type="submit"], input[type="button"], .elementor-button, [data-bubble-cursor-hover]',
				// Shape & transparency.
				'dot_size'              => 8,
				'ring_size'             => 40,
				'ring_border'           => 1.5,
				'cursor_opacity'        => 1,
				'smoke_opacity'         => 1,
				'smoke_blend'           => '',
				'ring_blend'            => '',
				// Smoke physics / intensity.
				'colorful'              => 1,
				'bloom'                 => 1,
				'bloom_intensity'       => 0.8,
				'intensity'             => 1,
				'curl'                  => 30,
				'quality'               => 'medium',
				'splat_force'           => 6000,
				'splat_radius'          => 0.25,
				'density_dissipation'   => 0.98,
				'velocity_dissipation'  => 0.98,
			);
		}

		/**
		 * Allowed CSS mix-blend-mode values for the smoke canvas.
		 *
		 * @return array
		 */
		private static function blend_modes() {
			return array( '', 'screen', 'lighten', 'overlay', 'difference', 'color-dodge', 'hard-light', 'soft-light' );
		}

		/**
		 * Allowed ring blend modes (the smoke list plus exclusion).
		 *
		 * @return string[]
		 */
		private static function ring_blend_modes() {
			return array_merge( self::blend_modes(), array( 'exclusion' ) );
		}

		/**
		 * Map a quality preset to a dye (smoke) resolution.
		 *
		 * @param string $quality low | medium | high.
		 * @return int
		 */
		private static function quality_to_dye( $quality ) {
			switch ( $quality ) {
				case 'low':
					return 512;
				case 'high':
					return 1440;
				case 'medium':
				default:
					return 1024;
			}
		}

		/**
		 * Merged options (saved values over defaults).
		 *
		 * @return array
		 */
		public static function get_options() {
			$saved = get_option( BUBBLE_CURSOR_OPTION, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			// Before 1.3.1 'hover_text' held the word itself (blank = off). Carry that over.
			if ( array_key_exists( 'hover_text', $saved ) && ! array_key_exists( 'hover_label', $saved ) && is_string( $saved['hover_text'] ) && ! in_array( $saved['hover_text'], array( '0', '1' ), true ) ) {
				$saved['hover_label'] = $saved['hover_text'];
				$saved['hover_text']  = '' === $saved['hover_text'] ? 0 : 1;
			}
			return wp_parse_args( $saved, self::defaults() );
		}

		/* ------------------------------------------------------------------ *
		 * Front-end
		 * ------------------------------------------------------------------ */

		/**
		 * Should the cursor load on the current request?
		 *
		 * @param array $o Options.
		 * @return bool
		 */
		private function should_load( $o ) {
			if ( empty( $o['enable'] ) ) {
				return false;
			}
			if ( is_admin() ) {
				return false;
			}
			if ( self::is_builder_request() ) {
				return false;
			}
			if ( 'front' === $o['scope'] && ! is_front_page() ) {
				return false;
			}
			/**
			 * Filter whether the Bubble Cursor assets load on this request.
			 *
			 * @param bool  $load Whether to load.
			 * @param array $o    Options.
			 */
			return (bool) apply_filters( 'bubble_cursor_should_load', true, $o );
		}

		/**
		 * Is this request a page-builder editor or preview iframe (or the
		 * Customizer preview)? The cursor must not draw over those.
		 *
		 * Read-only presence checks on query args; nothing is processed or saved,
		 * so no nonce is needed.
		 *
		 * @return bool
		 */
		public static function is_builder_request() {
			if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
				return true;
			}
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			foreach ( array( 'vc_editable', 'elementor-preview', 'et_fb', 'fl_builder', 'ct_builder', 'brizy-edit', 'brizy-edit-iframe' ) as $arg ) {
				if ( isset( $_GET[ $arg ] ) ) {
					return true;
				}
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			if ( function_exists( 'vc_is_page_editable' ) && vc_is_page_editable() ) {
				return true;
			}
			if ( function_exists( 'vc_is_inline' ) && vc_is_inline() ) {
				return true;
			}
			if ( class_exists( '\\Elementor\\Plugin' ) ) {
				$elementor = \Elementor\Plugin::$instance;
				if ( isset( $elementor->preview ) && is_object( $elementor->preview ) && $elementor->preview->is_preview_mode() ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Asset version: plugin version plus file mtime, so an edited asset is
		 * never served stale from browser/CDN caches.
		 *
		 * @param string $relative Path relative to the plugin folder.
		 * @return string
		 */
		private function asset_version( $relative ) {
			$file = BUBBLE_CURSOR_PATH . $relative;
			$time = file_exists( $file ) ? filemtime( $file ) : false;
			return $time ? BUBBLE_CURSOR_VERSION . '-' . $time : BUBBLE_CURSOR_VERSION;
		}

		/**
		 * Enqueue styles and scripts.
		 */
		public function enqueue_assets() {
			$o = self::get_options();
			if ( ! $this->should_load( $o ) ) {
				return;
			}

			wp_enqueue_style(
				'bubble-cursor',
				BUBBLE_CURSOR_URL . 'assets/css/bubble-cursor.css',
				array(),
				$this->asset_version( 'assets/css/bubble-cursor.css' )
			);

			wp_enqueue_script(
				'bubble-cursor-fluid',
				BUBBLE_CURSOR_URL . 'assets/js/fluid-cursor.js',
				array(),
				$this->asset_version( 'assets/js/fluid-cursor.js' ),
				true
			);

			wp_enqueue_script(
				'bubble-cursor',
				BUBBLE_CURSOR_URL . 'assets/js/bubble-cursor.js',
				array( 'bubble-cursor-fluid' ),
				$this->asset_version( 'assets/js/bubble-cursor.js' ),
				true
			);

			// Emit typed settings (booleans/numbers preserved) before the engine loads.
			$settings                = $this->build_js_settings( $o );
			$this->js_settings_json = wp_json_encode( $settings );
			wp_add_inline_script(
				'bubble-cursor-fluid',
				'window.BubbleCursorSettings = ' . $this->js_settings_json . ';',
				'before'
			);
		}

		/**
		 * Opt the plugin's scripts out of optimizer rewriting (WP Rocket,
		 * LiteSpeed, Rocket Loader, Autoptimize, WP-Optimize, SG Optimizer...)
		 * and mirror the settings onto the main script tag.
		 *
		 * @param string $tag    Script HTML (may include inline before/after scripts).
		 * @param string $handle Script handle.
		 * @return string
		 */
		public function filter_script_tag( $tag, $handle ) {
			if ( 'bubble-cursor-fluid' !== $handle && 'bubble-cursor' !== $handle ) {
				return $tag;
			}
			$opt_out = array(
				'data-no-optimize'       => '1',
				'data-no-defer'          => '1',
				'data-no-minify'         => '1',
				'data-no-delay'          => '1',
				'data-cfasync'           => 'false',
				'data-pagespeed-no-defer' => null,
				'data-wpfc-render'       => 'false',
				'nowprocket'             => null,
			);
			$json = $this->js_settings_json;

			return preg_replace_callback(
				'/<script\b[^>]*>/i',
				static function ( $m ) use ( $opt_out, $json, $handle ) {
					$open  = $m[0];
					$extra = '';
					foreach ( $opt_out as $name => $value ) {
						if ( false !== stripos( $open, ' ' . $name ) ) {
							continue;
						}
						$extra .= null === $value ? ' ' . $name : ' ' . $name . '="' . $value . '"';
					}
					if ( 'bubble-cursor' === $handle && '' !== $json && false === stripos( $open, 'data-bc-settings' ) && preg_match( '/\bid=["\']bubble-cursor-js["\']/i', $open ) ) {
						$extra .= ' data-bc-settings="' . esc_attr( $json ) . '"';
					}
					return substr( $open, 0, 7 ) . $extra . substr( $open, 7 );
				},
				$tag
			);
		}

		/**
		 * Purge page caches so visitors do not keep seeing old settings.
		 * Each integration is called only if it exists.
		 */
		public function purge_caches() {
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
			}
			do_action( 'litespeed_purge_all' );
			if ( function_exists( 'w3tc_flush_all' ) ) {
				w3tc_flush_all();
			}
			if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
				autoptimizeCache::clearall();
			}
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
				sg_cachepress_purge_cache();
			}
		}

		/**
		 * Build the JS settings object passed to the front-end.
		 *
		 * @param array $o Options.
		 * @return array
		 */
		private function build_js_settings( $o ) {
			/**
			 * Filter the front-end settings object before it is printed.
			 *
			 * @param array $settings JS settings.
			 * @param array $o        Saved options.
			 */
			return apply_filters(
				'bubble_cursor_js_settings',
				array(
					'enableFluid'      => (bool) $o['enable_fluid'],
					'enableRing'       => (bool) $o['enable_ring'],
					'enableDot'        => (bool) $o['enable_dot'],
					'hideNativeCursor' => (bool) $o['hide_native'] && ( $o['enable_dot'] || $o['enable_ring'] ),
					'hideOnTouch'      => (bool) $o['hide_on_touch'],
					'dotColor'         => $o['dot_color'],
					'ringColor'        => $o['ring_color'],
					'hoverText'        => (bool) $o['hover_text'],
					'hoverLabel'       => (string) $o['hover_label'],
					'hoverSelector'    => $o['hover_selector'],
					'dotSize'          => (float) $o['dot_size'],
					'ringSize'         => (float) $o['ring_size'],
					'ringBorder'       => (float) $o['ring_border'],
					'cursorOpacity'    => (float) $o['cursor_opacity'],
					'smokeOpacity'     => (float) $o['smoke_opacity'],
					'smokeBlend'       => $o['smoke_blend'],
					'mixBlend'         => $o['ring_blend'],
					'fluid'            => array(
						'SPLAT_FORCE'          => (float) $o['splat_force'],
						'SPLAT_RADIUS'         => (float) $o['splat_radius'],
						'DENSITY_DISSIPATION'  => (float) $o['density_dissipation'],
						'VELOCITY_DISSIPATION' => (float) $o['velocity_dissipation'],
						'CURL'                 => (float) $o['curl'],
						'INTENSITY'            => (float) $o['intensity'],
						'BLOOM_INTENSITY'      => (float) $o['bloom_intensity'],
						'DYE_RESOLUTION'       => self::quality_to_dye( $o['quality'] ),
						'COLORFUL'             => (bool) $o['colorful'],
						'BLOOM'                => (bool) $o['bloom'],
					),
				),
				$o
			);
		}

		/* ------------------------------------------------------------------ *
		 * Admin settings
		 * ------------------------------------------------------------------ */

		public function add_settings_page() {
			add_options_page(
				__( 'Bubble Cursor', 'bubble-cursor' ),
				__( 'Bubble Cursor', 'bubble-cursor' ),
				'manage_options',
				'bubble-cursor',
				array( $this, 'render_settings_page' )
			);
		}

		public function action_links( $links ) {
			$url  = admin_url( 'options-general.php?page=bubble-cursor' );
			$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'bubble-cursor' ) . '</a>';
			array_unshift( $links, $link );
			return $links;
		}

		public function register_settings() {
			register_setting(
				'bubble_cursor_group',
				BUBBLE_CURSOR_OPTION,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( $this, 'sanitize' ),
					'default'           => self::defaults(),
				)
			);
		}

		/**
		 * Sanitize all options before save.
		 *
		 * @param array $input Raw input.
		 * @return array
		 */
		public function sanitize( $input ) {
			$d   = self::defaults();
			$out = array();

			$out['enable']        = empty( $input['enable'] ) ? 0 : 1;
			$out['enable_fluid']  = empty( $input['enable_fluid'] ) ? 0 : 1;
			$out['enable_ring']   = empty( $input['enable_ring'] ) ? 0 : 1;
			$out['enable_dot']    = empty( $input['enable_dot'] ) ? 0 : 1;
			$out['hide_native']   = empty( $input['hide_native'] ) ? 0 : 1;
			$out['hide_on_touch'] = empty( $input['hide_on_touch'] ) ? 0 : 1;
			// Hiding the native pointer with no dot/ring to replace it leaves no cursor at all.
			if ( ! $out['enable_dot'] && ! $out['enable_ring'] ) {
				$out['hide_native'] = 0;
			}
			$out['colorful']      = empty( $input['colorful'] ) ? 0 : 1;
			$out['bloom']         = empty( $input['bloom'] ) ? 0 : 1;

			$scope         = isset( $input['scope'] ) ? $input['scope'] : $d['scope'];
			$out['scope']  = in_array( $scope, array( 'all', 'front' ), true ) ? $scope : $d['scope'];

			$out['dot_color']  = $this->sanitize_color( isset( $input['dot_color'] ) ? $input['dot_color'] : $d['dot_color'], $d['dot_color'] );
			$out['ring_color'] = $this->sanitize_color( isset( $input['ring_color'] ) ? $input['ring_color'] : $d['ring_color'], $d['ring_color'] );

			$out['hover_text']     = empty( $input['hover_text'] ) ? 0 : 1;
			// The default word may be blank (no word); only a missing key falls back to 'View'.
			$out['hover_label']    = isset( $input['hover_label'] ) ? mb_substr( sanitize_text_field( $input['hover_label'] ), 0, 40 ) : $d['hover_label'];
			$out['hover_selector'] = isset( $input['hover_selector'] ) ? sanitize_text_field( $input['hover_selector'] ) : $d['hover_selector'];
			if ( '' === trim( $out['hover_selector'] ) ) {
				$out['hover_selector'] = $d['hover_selector'];
			}

			$out['splat_force']          = $this->clamp_float( $input, 'splat_force', $d, 100, 20000 );
			$out['splat_radius']         = $this->clamp_float( $input, 'splat_radius', $d, 0.01, 1 );
			$out['density_dissipation']  = $this->clamp_float( $input, 'density_dissipation', $d, 0.5, 4 );
			$out['velocity_dissipation'] = $this->clamp_float( $input, 'velocity_dissipation', $d, 0.5, 4 );

			// Shape & transparency.
			$out['dot_size']       = $this->clamp_float( $input, 'dot_size', $d, 2, 40 );
			$out['ring_size']      = $this->clamp_float( $input, 'ring_size', $d, 10, 120 );
			$out['ring_border']    = $this->clamp_float( $input, 'ring_border', $d, 0, 8 );
			$out['cursor_opacity'] = $this->clamp_float( $input, 'cursor_opacity', $d, 0.1, 1 );
			$out['smoke_opacity']  = $this->clamp_float( $input, 'smoke_opacity', $d, 0.1, 1 );

			// Smoke intensity.
			$out['intensity']       = $this->clamp_float( $input, 'intensity', $d, 0.2, 3 );
			$out['bloom_intensity'] = $this->clamp_float( $input, 'bloom_intensity', $d, 0, 2 );
			$out['curl']            = $this->clamp_float( $input, 'curl', $d, 0, 50 );

			$quality        = isset( $input['quality'] ) ? $input['quality'] : $d['quality'];
			$out['quality'] = in_array( $quality, array( 'low', 'medium', 'high' ), true ) ? $quality : $d['quality'];

			$blend              = isset( $input['smoke_blend'] ) ? $input['smoke_blend'] : $d['smoke_blend'];
			$out['smoke_blend'] = in_array( $blend, self::blend_modes(), true ) ? $blend : $d['smoke_blend'];

			$ring_blend         = isset( $input['ring_blend'] ) ? $input['ring_blend'] : $d['ring_blend'];
			$out['ring_blend']  = in_array( $ring_blend, self::ring_blend_modes(), true ) ? $ring_blend : $d['ring_blend'];

			return $out;
		}

		private function clamp_float( $input, $key, $d, $min, $max ) {
			$val = isset( $input[ $key ] ) ? (float) $input[ $key ] : (float) $d[ $key ];
			return max( $min, min( $max, $val ) );
		}

		private function sanitize_color( $value, $fallback ) {
			$value = sanitize_text_field( $value );
			if ( preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $value ) ) {
				return $value;
			}
			return $fallback;
		}

		/**
		 * Render the settings screen.
		 */
		public function render_settings_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$o = self::get_options();
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Bubble Cursor — Smokey Fluid Cursor', 'bubble-cursor' ); ?></h1>
				<p><?php esc_html_e( 'A colourful WebGL smoke trail plus a dot + ring custom cursor with a "View" hover bubble. Tip: it needs a mouse — it is hidden on touch-only devices (touch-screen laptops with a mouse still get it), in page-builder editors, and for visitors who prefer reduced motion.', 'bubble-cursor' ); ?></p>
				<form method="post" action="options.php">
					<?php settings_fields( 'bubble_cursor_group' ); ?>
					<?php $n = BUBBLE_CURSOR_OPTION; ?>

					<h2 class="title"><?php esc_html_e( 'General', 'bubble-cursor' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable cursor', 'bubble-cursor' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enable]" value="1" <?php checked( $o['enable'], 1 ); ?>> <?php esc_html_e( 'Turn the whole effect on', 'bubble-cursor' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Where to load', 'bubble-cursor' ); ?></th>
							<td>
								<select name="<?php echo esc_attr( $n ); ?>[scope]">
									<option value="all" <?php selected( $o['scope'], 'all' ); ?>><?php esc_html_e( 'Entire site', 'bubble-cursor' ); ?></option>
									<option value="front" <?php selected( $o['scope'], 'front' ); ?>><?php esc_html_e( 'Front page only', 'bubble-cursor' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Hide on touch devices', 'bubble-cursor' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[hide_on_touch]" value="1" <?php checked( $o['hide_on_touch'], 1 ); ?>> <?php esc_html_e( 'Recommended', 'bubble-cursor' ); ?></label></td>
						</tr>
					</table>

					<h2 class="title"><?php esc_html_e( 'Layers', 'bubble-cursor' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Smoke (fluid) trail', 'bubble-cursor' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enable_fluid]" value="1" <?php checked( $o['enable_fluid'], 1 ); ?>> <?php esc_html_e( 'WebGL fluid smoke that follows the mouse', 'bubble-cursor' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Ring follower', 'bubble-cursor' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enable_ring]" value="1" <?php checked( $o['enable_ring'], 1 ); ?>> <?php esc_html_e( 'Outline ring that eases behind the pointer', 'bubble-cursor' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Dot follower', 'bubble-cursor' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enable_dot]" value="1" <?php checked( $o['enable_dot'], 1 ); ?>> <?php esc_html_e( 'Small dot that tracks the pointer tightly', 'bubble-cursor' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Hide native cursor', 'bubble-cursor' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[hide_native]" value="1" <?php checked( $o['hide_native'], 1 ); ?>> <?php esc_html_e( 'Hide the operating-system arrow (the Deep demo keeps it visible). Ignored when both the dot and the ring are off, and for visitors who prefer reduced motion.', 'bubble-cursor' ); ?></label></td>
						</tr>
					</table>

					<h2 class="title"><?php esc_html_e( 'Colours & hover', 'bubble-cursor' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Dot colour', 'bubble-cursor' ); ?></th>
							<td><input type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[dot_color]" value="<?php echo esc_attr( $o['dot_color'] ); ?>" placeholder="#ffffff"> <span class="description"><?php esc_html_e( 'Hex, e.g. #ffffff', 'bubble-cursor' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Ring colour', 'bubble-cursor' ); ?></th>
							<td><input type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[ring_color]" value="<?php echo esc_attr( $o['ring_color'] ); ?>" placeholder="#ffffff"></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Hover word', 'bubble-cursor' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[hover_text]" value="1" <?php checked( $o['hover_text'], 1 ); ?>> <?php esc_html_e( 'Show a word inside the ring when hovering', 'bubble-cursor' ); ?></label>
								<p class="description"><?php esc_html_e( 'Turn off to get only the enlarged ring, never a word (this also ignores data-bubble-cursor-text and the Elementor setting).', 'bubble-cursor' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Default hover label', 'bubble-cursor' ); ?></th>
							<td>
								<input type="text" class="regular-text" maxlength="40" name="<?php echo esc_attr( $n ); ?>[hover_label]" value="<?php echo esc_attr( $o['hover_label'] ); ?>" placeholder="View">
								<p class="description"><?php esc_html_e( 'The word shown for links and buttons that have no label of their own (default "View"). Leave blank for no default word. Add data-bubble-cursor-text="Open" to any element to override per element, data-bubble-cursor-no-text (or an empty data-bubble-cursor-text) to show no word on it. Elementor containers using the "Cursor Hover Effect Text" setting are detected automatically.', 'bubble-cursor' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Hover selector', 'bubble-cursor' ); ?></th>
							<td>
								<input type="text" class="large-text code" name="<?php echo esc_attr( $n ); ?>[hover_selector]" value="<?php echo esc_attr( $o['hover_selector'] ); ?>">
								<p class="description"><?php esc_html_e( 'CSS selector for elements that trigger the enlarged ring (advanced).', 'bubble-cursor' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Ring blend mode', 'bubble-cursor' ); ?></th>
							<td>
								<select name="<?php echo esc_attr( $n ); ?>[ring_blend]">
									<?php foreach ( self::ring_blend_modes() as $mode ) : ?>
										<option value="<?php echo esc_attr( $mode ); ?>" <?php selected( $o['ring_blend'], $mode ); ?>><?php echo '' === $mode ? esc_html__( 'Normal', 'bubble-cursor' ) : esc_html( $mode ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( '"difference" or "exclusion" keep the ring visible over both light and dark areas.', 'bubble-cursor' ); ?></p>
							</td>
						</tr>
					</table>

					<h2 class="title"><?php esc_html_e( 'Shape, size & transparency', 'bubble-cursor' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Dot size', 'bubble-cursor' ); ?></th>
							<td><input type="number" step="1" min="2" max="40" name="<?php echo esc_attr( $n ); ?>[dot_size]" value="<?php echo esc_attr( $o['dot_size'] ); ?>"> <span class="description"><?php esc_html_e( 'px (default 8)', 'bubble-cursor' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Ring size', 'bubble-cursor' ); ?></th>
							<td><input type="number" step="1" min="10" max="120" name="<?php echo esc_attr( $n ); ?>[ring_size]" value="<?php echo esc_attr( $o['ring_size'] ); ?>"> <span class="description"><?php esc_html_e( 'px (default 40)', 'bubble-cursor' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Ring thickness', 'bubble-cursor' ); ?></th>
							<td><input type="number" step="0.5" min="0" max="8" name="<?php echo esc_attr( $n ); ?>[ring_border]" value="<?php echo esc_attr( $o['ring_border'] ); ?>"> <span class="description"><?php esc_html_e( 'px border (default 1.5)', 'bubble-cursor' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Cursor opacity', 'bubble-cursor' ); ?></th>
							<td><input type="number" step="0.05" min="0.1" max="1" name="<?php echo esc_attr( $n ); ?>[cursor_opacity]" value="<?php echo esc_attr( $o['cursor_opacity'] ); ?>"> <span class="description"><?php esc_html_e( 'Transparency of the dot + ring, 0.1–1 (default 1).', 'bubble-cursor' ); ?></span></td>
						</tr>
					</table>

					<h2 class="title"><?php esc_html_e( 'Smoke tuning', 'bubble-cursor' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Colourful', 'bubble-cursor' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[colorful]" value="1" <?php checked( $o['colorful'], 1 ); ?>> <?php esc_html_e( 'Cycle smoke colours (off = single colour per stroke)', 'bubble-cursor' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Bloom glow', 'bubble-cursor' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[bloom]" value="1" <?php checked( $o['bloom'], 1 ); ?>> <?php esc_html_e( 'Soft glow around bright smoke', 'bubble-cursor' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Bloom intensity', 'bubble-cursor' ); ?></th>
							<td><input type="number" step="0.1" min="0" max="2" name="<?php echo esc_attr( $n ); ?>[bloom_intensity]" value="<?php echo esc_attr( $o['bloom_intensity'] ); ?>"> <span class="description"><?php esc_html_e( 'Strength of the glow, 0–2 (default 0.8).', 'bubble-cursor' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Smoke intensity', 'bubble-cursor' ); ?></th>
							<td><input type="number" step="0.1" min="0.2" max="3" name="<?php echo esc_attr( $n ); ?>[intensity]" value="<?php echo esc_attr( $o['intensity'] ); ?>"> <span class="description"><?php esc_html_e( 'Brightness / vividness of each puff, 0.2–3 (default 1).', 'bubble-cursor' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Swirl', 'bubble-cursor' ); ?></th>
							<td><input type="number" step="1" min="0" max="50" name="<?php echo esc_attr( $n ); ?>[curl]" value="<?php echo esc_attr( $o['curl'] ); ?>"> <span class="description"><?php esc_html_e( 'How much the smoke curls / swirls, 0–50 (default 30).', 'bubble-cursor' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Smoke opacity', 'bubble-cursor' ); ?></th>
							<td><input type="number" step="0.05" min="0.1" max="1" name="<?php echo esc_attr( $n ); ?>[smoke_opacity]" value="<?php echo esc_attr( $o['smoke_opacity'] ); ?>"> <span class="description"><?php esc_html_e( 'Transparency of the whole smoke layer, 0.1–1 (default 1).', 'bubble-cursor' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Quality', 'bubble-cursor' ); ?></th>
							<td>
								<select name="<?php echo esc_attr( $n ); ?>[quality]">
									<option value="low" <?php selected( $o['quality'], 'low' ); ?>><?php esc_html_e( 'Low (fastest)', 'bubble-cursor' ); ?></option>
									<option value="medium" <?php selected( $o['quality'], 'medium' ); ?>><?php esc_html_e( 'Medium (default)', 'bubble-cursor' ); ?></option>
									<option value="high" <?php selected( $o['quality'], 'high' ); ?>><?php esc_html_e( 'High (sharpest, heavier)', 'bubble-cursor' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Smoke resolution. Use Low on lower-powered devices for smoother performance.', 'bubble-cursor' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Smoke blend mode', 'bubble-cursor' ); ?></th>
							<td>
								<select name="<?php echo esc_attr( $n ); ?>[smoke_blend]">
									<option value="" <?php selected( $o['smoke_blend'], '' ); ?>><?php esc_html_e( 'Normal (over content)', 'bubble-cursor' ); ?></option>
									<option value="screen" <?php selected( $o['smoke_blend'], 'screen' ); ?>>screen</option>
									<option value="lighten" <?php selected( $o['smoke_blend'], 'lighten' ); ?>>lighten</option>
									<option value="overlay" <?php selected( $o['smoke_blend'], 'overlay' ); ?>>overlay</option>
									<option value="soft-light" <?php selected( $o['smoke_blend'], 'soft-light' ); ?>>soft-light</option>
									<option value="hard-light" <?php selected( $o['smoke_blend'], 'hard-light' ); ?>>hard-light</option>
									<option value="color-dodge" <?php selected( $o['smoke_blend'], 'color-dodge' ); ?>>color-dodge</option>
									<option value="difference" <?php selected( $o['smoke_blend'], 'difference' ); ?>>difference</option>
								</select>
								<p class="description"><?php esc_html_e( 'How the smoke mixes with the page. "screen" or "lighten" keep text more readable on dark sites.', 'bubble-cursor' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Splat force', 'bubble-cursor' ); ?></th>
							<td><input type="number" step="100" min="100" max="20000" name="<?php echo esc_attr( $n ); ?>[splat_force]" value="<?php echo esc_attr( $o['splat_force'] ); ?>"> <span class="description"><?php esc_html_e( 'How forcefully the mouse pushes the fluid (default 6000).', 'bubble-cursor' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Splat radius', 'bubble-cursor' ); ?></th>
							<td><input type="number" step="0.01" min="0.01" max="1" name="<?php echo esc_attr( $n ); ?>[splat_radius]" value="<?php echo esc_attr( $o['splat_radius'] ); ?>"> <span class="description"><?php esc_html_e( 'Size of each smoke puff (default 0.25).', 'bubble-cursor' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Density fade', 'bubble-cursor' ); ?></th>
							<td><input type="number" step="0.01" min="0.5" max="4" name="<?php echo esc_attr( $n ); ?>[density_dissipation]" value="<?php echo esc_attr( $o['density_dissipation'] ); ?>"> <span class="description"><?php esc_html_e( 'Higher = smoke fades faster (default 0.98).', 'bubble-cursor' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Velocity fade', 'bubble-cursor' ); ?></th>
							<td><input type="number" step="0.01" min="0.5" max="4" name="<?php echo esc_attr( $n ); ?>[velocity_dissipation]" value="<?php echo esc_attr( $o['velocity_dissipation'] ); ?>"> <span class="description"><?php esc_html_e( 'Higher = motion settles faster (default 0.98).', 'bubble-cursor' ); ?></span></td>
						</tr>
					</table>

					<?php submit_button(); ?>
				</form>
				<?php flux_family_render_card( 'Flux Bubble Cursor' ); ?>
			</div>
			<?php
		}
	}

}

/**
 * Set sane defaults on activation.
 */
if ( ! function_exists( 'bubble_cursor_activate' ) ) {
	function bubble_cursor_activate() {
		if ( false === get_option( BUBBLE_CURSOR_OPTION, false ) ) {
			add_option( BUBBLE_CURSOR_OPTION, Bubble_Cursor::defaults() );
		}
	}
}
register_activation_hook( __FILE__, 'bubble_cursor_activate' );

Bubble_Cursor::instance();
