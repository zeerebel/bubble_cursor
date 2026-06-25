<?php
/**
 * Plugin Name:       Bubble Cursor — Smokey Fluid Cursor
 * Plugin URI:        https://github.com/zeerebel/bubble_cursor
 * Description:       Adds a colourful WebGL "smoke" fluid trail plus a dot + ring custom cursor with a "View" hover bubble — a replica of the TreeThemes "Deep" theme cursor. Works on any theme (Elementor or not). No coding required.
 * Version:           1.2.0
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

define( 'BUBBLE_CURSOR_VERSION', '1.2.0' );
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
			// Shape & transparency.
			'dot_size'              => 8,
			'ring_size'             => 40,
			'ring_border'           => 1.5,
			'cursor_opacity'        => 1,
			'smoke_opacity'         => 1,
			'smoke_blend'           => '',
			'auto_contrast'         => 0,
			// Colour mode: rainbow (random) | palette (your colours) | single (one colour + shades).
			'color_mode'            => 'rainbow',
			'single_color'          => '#1e90ff',
			'pal_color_1'           => '#1e90ff',
			'pal_on_1'              => 1,
			'pal_color_2'           => '#8a2be2',
			'pal_on_2'              => 1,
			'pal_color_3'           => '#00e5ff',
			'pal_on_3'              => 1,
			'pal_color_4'           => '#ff3366',
			'pal_on_4'              => 0,
			'pal_color_5'           => '#ffd166',
			'pal_on_5'              => 0,
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
	 * Collect the enabled, valid palette colours (up to 5) as a list of hex strings.
	 *
	 * @param array $o Options.
	 * @return array
	 */
	private static function palette_from_options( $o ) {
		$out = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			if ( empty( $o[ 'pal_on_' . $i ] ) ) {
				continue;
			}
			$hex = isset( $o[ 'pal_color_' . $i ] ) ? $o[ 'pal_color_' . $i ] : '';
			if ( preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $hex ) ) {
				$out[] = $hex;
			}
		}
		return $out;
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
				'dotSize'          => (float) $o['dot_size'],
				'ringSize'         => (float) $o['ring_size'],
				'ringBorder'       => (float) $o['ring_border'],
				'cursorOpacity'    => (float) $o['cursor_opacity'],
				'smokeOpacity'     => (float) $o['smoke_opacity'],
				'smokeBlend'       => $o['smoke_blend'],
				'autoContrast'     => (bool) $o['auto_contrast'],
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
					'COLOR_MODE'           => $o['color_mode'],
					'PALETTE'              => self::palette_from_options( $o ),
					'SINGLE_COLOR'         => $o['single_color'],
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

		// Colour mode + palette + auto-contrast.
		$out['auto_contrast'] = empty( $input['auto_contrast'] ) ? 0 : 1;

		$mode                = isset( $input['color_mode'] ) ? $input['color_mode'] : $d['color_mode'];
		$out['color_mode']   = in_array( $mode, array( 'rainbow', 'palette', 'single' ), true ) ? $mode : $d['color_mode'];
		$out['single_color'] = $this->sanitize_color( isset( $input['single_color'] ) ? $input['single_color'] : $d['single_color'], $d['single_color'] );

		for ( $i = 1; $i <= 5; $i++ ) {
			$ck           = 'pal_color_' . $i;
			$ok           = 'pal_on_' . $i;
			$out[ $ck ]   = $this->sanitize_color( isset( $input[ $ck ] ) ? $input[ $ck ] : $d[ $ck ], $d[ $ck ] );
			$out[ $ok ]   = empty( $input[ $ok ] ) ? 0 : 1;
		}

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
						<th scope="row"><?php esc_html_e( 'Adapt to background', 'bubble-cursor' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[auto_contrast]" value="1" <?php checked( $o['auto_contrast'], 1 ); ?>> <?php esc_html_e( 'Auto-invert the dot + ring so they stay visible on light AND dark sections (uses white + "difference" blending; overrides the dot/ring colours above).', 'bubble-cursor' ); ?></label></td>
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

				<h2 class="title"><?php esc_html_e( 'Smoke colours', 'bubble-cursor' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Colour mode', 'bubble-cursor' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $n ); ?>[color_mode]">
								<option value="rainbow" <?php selected( $o['color_mode'], 'rainbow' ); ?>><?php esc_html_e( 'Rainbow (random colours)', 'bubble-cursor' ); ?></option>
								<option value="palette" <?php selected( $o['color_mode'], 'palette' ); ?>><?php esc_html_e( 'Palette (colours I pick)', 'bubble-cursor' ); ?></option>
								<option value="single" <?php selected( $o['color_mode'], 'single' ); ?>><?php esc_html_e( 'Single colour (+ auto shades)', 'bubble-cursor' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Rainbow = the original random colours. Palette = only the colours you enable below. Single = one colour with automatic shade variation.', 'bubble-cursor' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Single colour', 'bubble-cursor' ); ?></th>
						<td><input type="color" name="<?php echo esc_attr( $n ); ?>[single_color]" value="<?php echo esc_attr( $o['single_color'] ); ?>"> <span class="description"><?php esc_html_e( 'Used when Colour mode is "Single".', 'bubble-cursor' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Palette colours', 'bubble-cursor' ); ?></th>
						<td>
							<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
								<label style="display:inline-flex;align-items:center;gap:6px;margin:0 14px 8px 0;">
									<input type="checkbox" name="<?php echo esc_attr( $n ); ?>[pal_on_<?php echo (int) $i; ?>]" value="1" <?php checked( $o[ 'pal_on_' . $i ], 1 ); ?>>
									<input type="color" name="<?php echo esc_attr( $n ); ?>[pal_color_<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $o[ 'pal_color_' . $i ] ); ?>">
								</label>
							<?php endfor; ?>
							<p class="description"><?php esc_html_e( 'Tick a colour to include it. Add a few shades of one colour, or your brand colours. Used when Colour mode is "Palette".', 'bubble-cursor' ); ?></p>
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
