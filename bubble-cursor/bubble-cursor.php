<?php
/**
 * Plugin Name:       Bubble Cursor — Smokey Fluid Cursor
 * Plugin URI:        https://github.com/zeerebel/bubble_cursor
 * Description:       Adds a colourful WebGL "smoke" fluid trail plus a dot + ring custom cursor with a "View" hover bubble — a replica of the TreeThemes "Deep" theme cursor. Works on any theme (Elementor or not). No coding required.
 * Version:           1.0.0
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

define( 'BUBBLE_CURSOR_VERSION', '1.0.0' );
define( 'BUBBLE_CURSOR_FILE', __FILE__ );
define( 'BUBBLE_CURSOR_URL', plugin_dir_url( __FILE__ ) );
define( 'BUBBLE_CURSOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'BUBBLE_CURSOR_OPTION', 'bubble_cursor_options' );

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
			'hover_text'            => 'View',
			'hover_selector'        => 'a[href], button:not(:disabled), input[type="submit"], input[type="button"], .elementor-button, [data-bubble-cursor-hover]',
			'colorful'              => 1,
			'bloom'                 => 1,
			'splat_force'           => 6000,
			'splat_radius'          => 0.25,
			'density_dissipation'   => 0.98,
			'velocity_dissipation'  => 0.98,
		);
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
			BUBBLE_CURSOR_VERSION
		);

		wp_enqueue_script(
			'bubble-cursor-fluid',
			BUBBLE_CURSOR_URL . 'assets/js/fluid-cursor.js',
			array(),
			BUBBLE_CURSOR_VERSION,
			true
		);

		wp_enqueue_script(
			'bubble-cursor',
			BUBBLE_CURSOR_URL . 'assets/js/bubble-cursor.js',
			array( 'bubble-cursor-fluid' ),
			BUBBLE_CURSOR_VERSION,
			true
		);

		// Emit typed settings (booleans/numbers preserved) before the engine loads.
		$settings = $this->build_js_settings( $o );
		wp_add_inline_script(
			'bubble-cursor-fluid',
			'window.BubbleCursorSettings = ' . wp_json_encode( $settings ) . ';',
			'before'
		);
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
				'hideNativeCursor' => (bool) $o['hide_native'],
				'hideOnTouch'      => (bool) $o['hide_on_touch'],
				'dotColor'         => $o['dot_color'],
				'ringColor'        => $o['ring_color'],
				'hoverText'        => '' === $o['hover_text'] ? false : $o['hover_text'],
				'hoverSelector'    => $o['hover_selector'],
				'fluid'            => array(
					'SPLAT_FORCE'          => (float) $o['splat_force'],
					'SPLAT_RADIUS'         => (float) $o['splat_radius'],
					'DENSITY_DISSIPATION'  => (float) $o['density_dissipation'],
					'VELOCITY_DISSIPATION' => (float) $o['velocity_dissipation'],
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
		$out['colorful']      = empty( $input['colorful'] ) ? 0 : 1;
		$out['bloom']         = empty( $input['bloom'] ) ? 0 : 1;

		$scope         = isset( $input['scope'] ) ? $input['scope'] : $d['scope'];
		$out['scope']  = in_array( $scope, array( 'all', 'front' ), true ) ? $scope : $d['scope'];

		$out['dot_color']  = $this->sanitize_color( isset( $input['dot_color'] ) ? $input['dot_color'] : $d['dot_color'], $d['dot_color'] );
		$out['ring_color'] = $this->sanitize_color( isset( $input['ring_color'] ) ? $input['ring_color'] : $d['ring_color'], $d['ring_color'] );

		$out['hover_text']     = isset( $input['hover_text'] ) ? sanitize_text_field( $input['hover_text'] ) : $d['hover_text'];
		$out['hover_selector'] = isset( $input['hover_selector'] ) ? sanitize_text_field( $input['hover_selector'] ) : $d['hover_selector'];
		if ( '' === trim( $out['hover_selector'] ) ) {
			$out['hover_selector'] = $d['hover_selector'];
		}

		$out['splat_force']          = $this->clamp_float( $input, 'splat_force', $d, 100, 20000 );
		$out['splat_radius']         = $this->clamp_float( $input, 'splat_radius', $d, 0.01, 1 );
		$out['density_dissipation']  = $this->clamp_float( $input, 'density_dissipation', $d, 0.5, 4 );
		$out['velocity_dissipation'] = $this->clamp_float( $input, 'velocity_dissipation', $d, 0.5, 4 );

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
			<p><?php esc_html_e( 'A colourful WebGL smoke trail plus a dot + ring custom cursor with a "View" hover bubble. Tip: it needs a mouse — it is hidden on touch devices and for visitors who prefer reduced motion.', 'bubble-cursor' ); ?></p>
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
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[hide_native]" value="1" <?php checked( $o['hide_native'], 1 ); ?>> <?php esc_html_e( 'Hide the operating-system arrow (the Deep demo keeps it visible)', 'bubble-cursor' ); ?></label></td>
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
						<th scope="row"><?php esc_html_e( 'Hover text', 'bubble-cursor' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[hover_text]" value="<?php echo esc_attr( $o['hover_text'] ); ?>" placeholder="View">
							<p class="description"><?php esc_html_e( 'Word shown inside the ring when hovering elements that carry a hover label. Leave blank to disable. Add data-bubble-cursor-text="Open" to any element to override per-element. Elementor containers using the "Cursor Hover Effect Text" setting are detected automatically.', 'bubble-cursor' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Hover selector', 'bubble-cursor' ); ?></th>
						<td>
							<input type="text" class="large-text code" name="<?php echo esc_attr( $n ); ?>[hover_selector]" value="<?php echo esc_attr( $o['hover_selector'] ); ?>">
							<p class="description"><?php esc_html_e( 'CSS selector for elements that trigger the enlarged ring (advanced).', 'bubble-cursor' ); ?></p>
						</td>
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
		</div>
		<?php
	}
}

/**
 * Set sane defaults on activation.
 */
function bubble_cursor_activate() {
	if ( false === get_option( BUBBLE_CURSOR_OPTION, false ) ) {
		add_option( BUBBLE_CURSOR_OPTION, Bubble_Cursor::defaults() );
	}
}
register_activation_hook( __FILE__, 'bubble_cursor_activate' );

Bubble_Cursor::instance();
