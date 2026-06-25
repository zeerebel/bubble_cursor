<?php
/**
 * Validate the plugin class under stubbed WordPress functions:
 *  - it loads with no fatals
 *  - build_js_settings() emits valid, correctly-typed JSON
 *  - sanitize() clamps and validates input
 */

// --- Minimal WP stubs -------------------------------------------------------
define( 'ABSPATH', '/tmp/' );
$GLOBALS['__options'] = array();

function plugin_dir_url( $f )  { return 'http://example.com/wp-content/plugins/bubble-cursor/'; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_basename( $f ) { return 'bubble-cursor/bubble-cursor.php'; }
function add_action( ...$a )   {}
function add_filter( ...$a )   {}
function register_activation_hook( ...$a ) {}
function apply_filters( $tag, $value ) { return $value; }
function get_option( $k, $d = false ) { return isset( $GLOBALS['__options'][ $k ] ) ? $GLOBALS['__options'][ $k ] : $d; }
function add_option( $k, $v ) { $GLOBALS['__options'][ $k ] = $v; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( (string) $s ) ) ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, array_filter( $args, fn( $v ) => null !== $v ) ); }

// --- Load the plugin --------------------------------------------------------
require __DIR__ . '/../../bubble-cursor/bubble-cursor.php';

$bc = Bubble_Cursor::instance();
echo "1) class instantiated: OK\n";

// --- Default JS settings ----------------------------------------------------
$ref = new ReflectionMethod( 'Bubble_Cursor', 'build_js_settings' );
$ref->setAccessible( true );
$json = wp_json_encode( $ref->invoke( $bc, Bubble_Cursor::get_options() ) );
$decoded = json_decode( $json, true );

echo "2) default settings JSON:\n   $json\n";

$checks = array(
	'valid JSON'              => null !== $decoded,
	'enableFluid is bool'     => is_bool( $decoded['enableFluid'] ),
	'enableFluid true'        => true === $decoded['enableFluid'],
	'hideNativeCursor false'  => false === $decoded['hideNativeCursor'],
	'hoverText = View'        => 'View' === $decoded['hoverText'],
	'hoverEffect default true' => true === $decoded['hoverEffect'],
	'hoverTextSelector empty'  => '' === $decoded['hoverTextSelector'],
	'magnetic default false'   => false === $decoded['magnetic'],
	'clickBurst default false'  => false === $decoded['clickBurst'],
	'elastic default false'    => false === $decoded['elastic'],
	'imagePreview default false' => false === $decoded['imagePreview'],
	'previewSize default 180'  => 180 == $decoded['previewSize'],
	'fluid.ADAPTIVE false'     => false === $decoded['fluid']['ADAPTIVE'],
	'fluid.SPLAT_FORCE num'   => is_numeric( $decoded['fluid']['SPLAT_FORCE'] ) && 6000 == $decoded['fluid']['SPLAT_FORCE'],
	'fluid.SPLAT_RADIUS 0.25' => 0.25 === $decoded['fluid']['SPLAT_RADIUS'],
	'fluid.BLOOM is bool'     => is_bool( $decoded['fluid']['BLOOM'] ),
	'fluid.COLORFUL true'     => true === $decoded['fluid']['COLORFUL'],
	'dotSize default 8'        => 8 == $decoded['dotSize'],
	'ringSize default 40'      => 40 == $decoded['ringSize'],
	'ringSpeed default 0.2'    => 0.2 == $decoded['ringSpeed'],
	'ringBorder default 1.5'   => 1.5 == $decoded['ringBorder'],
	'cursorOpacity default 1'  => 1 == $decoded['cursorOpacity'],
	'smokeOpacity default 1'   => 1 == $decoded['smokeOpacity'],
	'smokeBlend default empty' => '' === $decoded['smokeBlend'],
	'fluid.INTENSITY 1'        => 1 == $decoded['fluid']['INTENSITY'],
	'fluid.BLOOM_INTENSITY .8' => 0.8 == $decoded['fluid']['BLOOM_INTENSITY'],
	'fluid.CURL 30'            => 30 == $decoded['fluid']['CURL'],
	'fluid.DYE_RESOLUTION 1024' => 1024 == $decoded['fluid']['DYE_RESOLUTION'],
	'autoContrast default false' => false === $decoded['autoContrast'],
	'fluid.COLOR_MODE rainbow'  => 'rainbow' === $decoded['fluid']['COLOR_MODE'],
	'fluid.PALETTE is array(3)' => is_array( $decoded['fluid']['PALETTE'] ) && count( $decoded['fluid']['PALETTE'] ) === 3,
	'fluid.PALETTE[0] = blue'   => '#1e90ff' === $decoded['fluid']['PALETTE'][0],
	'fluid.SINGLE_COLOR blue'   => '#1e90ff' === $decoded['fluid']['SINGLE_COLOR'],
);

// --- Sanitization edge cases ------------------------------------------------
$dirty = array(
	'enable'               => '1',
	'scope'                => 'evil-value',           // -> falls back to 'all'
	'enable_fluid'         => '1',
	'hide_native'          => '',                      // unchecked -> 0
	'dot_color'            => 'javascript:alert(1)',   // invalid -> fallback #ffffff
	'ring_color'           => '#abc',                  // valid short hex
	'hover_text'           => '<b>Look</b>',           // stripped
	'hover_selector'       => '   ',                   // blank -> default
	'hover_text_selector'  => '.qodef-e-media-image',  // kept as-is
	'image_preview'        => '1',
	'adaptive'             => '1',
	'preview_selector'     => '.portfolio-item',
	'preview_size'         => '9999',                  // clamp -> 420
	'splat_force'          => '999999',                // clamp -> 20000
	'splat_radius'         => '-3',                    // clamp -> 0.01
	'density_dissipation'  => '0.98',
	'velocity_dissipation' => '99',                    // clamp -> 4
	'quality'              => 'ultra',                 // invalid -> medium
	'smoke_blend'          => 'evil-mode',             // invalid -> ''
	'dot_size'             => '999',                   // clamp -> 40
	'ring_size'            => '0',                     // clamp -> 10
	'ring_speed'           => '9',                     // clamp -> 0.6
	'ring_border'          => '-5',                    // clamp -> 0
	'cursor_opacity'       => '5',                     // clamp -> 1
	'smoke_opacity'        => '0',                     // clamp -> 0.1
	'intensity'            => '-2',                    // clamp -> 0.2
	'bloom_intensity'      => '9',                     // clamp -> 2
	'curl'                 => '999',                   // clamp -> 50
	'auto_contrast'        => '1',                     // checkbox on
	'color_mode'           => 'plaid',                 // invalid -> rainbow
	'single_color'         => 'nope',                  // invalid -> default #1e90ff
	'pal_on_1'             => '1',
	'pal_color_1'          => '#abcdef',               // valid, kept
	'pal_on_2'             => '',                       // disabled
	'pal_color_2'          => 'bad',                   // invalid -> default (but disabled)
);
$clean = $bc->sanitize( $dirty );
echo "3) sanitized output:\n   " . wp_json_encode( $clean ) . "\n";

$checks['scope falls back to all']      = 'all' === $clean['scope'];
$checks['bad dot_color -> #ffffff']     = '#ffffff' === $clean['dot_color'];
$checks['short hex kept']               = '#abc' === $clean['ring_color'];
$checks['hover_text stripped of tags']  = 'Look' === $clean['hover_text'];
$checks['blank selector -> default']    = false !== strpos( $clean['hover_selector'], 'elementor-button' );
$checks['hover_text_selector kept']     = '.qodef-e-media-image' === $clean['hover_text_selector'];
$checks['image_preview on -> 1']        = 1 === $clean['image_preview'];
$checks['adaptive on -> 1']             = 1 === $clean['adaptive'];
$checks['preview_selector kept']        = '.portfolio-item' === $clean['preview_selector'];
$checks['preview_size clamp <= 420']    = 420 == $clean['preview_size'];
$checks['splat_force clamped <= 20000'] = 20000 == $clean['splat_force'];
$checks['splat_radius clamped >= 0.01'] = 0.01 == $clean['splat_radius'];
$checks['velocity clamped <= 4']        = 4 == $clean['velocity_dissipation'];
$checks['hide_native unchecked -> 0']   = 0 === $clean['hide_native'];
$checks['hover_effect unchecked -> 0']  = 0 === $clean['hover_effect'];
$checks['quality bad -> medium']        = 'medium' === $clean['quality'];
$checks['smoke_blend bad -> empty']     = '' === $clean['smoke_blend'];
$checks['dot_size clamp <= 40']         = 40 == $clean['dot_size'];
$checks['ring_size clamp >= 10']        = 10 == $clean['ring_size'];
$checks['ring_speed clamp <= 0.6']      = 0.6 == $clean['ring_speed'];
$checks['ring_border clamp >= 0']       = 0 == $clean['ring_border'];
$checks['cursor_opacity clamp <= 1']    = 1 == $clean['cursor_opacity'];
$checks['smoke_opacity clamp >= 0.1']   = 0.1 == $clean['smoke_opacity'];
$checks['intensity clamp >= 0.2']       = 0.2 == $clean['intensity'];
$checks['bloom_intensity clamp <= 2']   = 2 == $clean['bloom_intensity'];
$checks['curl clamp <= 50']             = 50 == $clean['curl'];
$checks['auto_contrast on -> 1']        = 1 === $clean['auto_contrast'];
$checks['color_mode bad -> rainbow']    = 'rainbow' === $clean['color_mode'];
$checks['single_color bad -> default']  = '#1e90ff' === $clean['single_color'];
$checks['pal_color_1 valid kept']       = '#abcdef' === $clean['pal_color_1'];
$checks['pal_on_2 unchecked -> 0']      = 0 === $clean['pal_on_2'];

// PALETTE built from sanitized options should contain only enabled, valid colours.
$cleanSettings = json_decode( wp_json_encode( $ref->invoke( $bc, $clean ) ), true );
$checks['palette from clean = [#abcdef]'] = array( '#abcdef' ) === $cleanSettings['fluid']['PALETTE'];

// Presets apply on save and are NOT persisted.
$neon = $bc->sanitize( array( 'apply_preset' => 'neon' ) );
$checks['preset neon -> palette mode']   = 'palette' === $neon['color_mode'];
$checks['preset neon -> pal_color_1']    = '#39ff14' === $neon['pal_color_1'];
$checks['preset neon -> bloom_int 1.3']  = 1.3 == $neon['bloom_intensity'];
$checks['preset not persisted']          = ! isset( $neon['apply_preset'] );
$minimal = $bc->sanitize( array( 'apply_preset' => 'minimal' ) );
$checks['preset minimal -> fluid off']   = 0 === $minimal['enable_fluid'];
$checks['preset minimal -> magnetic on'] = 1 === $minimal['magnetic'];
$badp = $bc->sanitize( array( 'apply_preset' => 'nope' ) );
$checks['unknown preset -> no override'] = 'rainbow' === $badp['color_mode']; // neon would set palette

// --- Report -----------------------------------------------------------------
echo "\n4) assertions:\n";
$fail = 0;
foreach ( $checks as $name => $ok ) {
	echo '   ' . ( $ok ? 'PASS' : 'FAIL' ) . " - $name\n";
	if ( ! $ok ) { $fail++; }
}
echo "\n" . ( $fail ? "RESULT: $fail FAILED" : 'RESULT: ALL PASSED' ) . "\n";
exit( $fail ? 1 : 0 );
