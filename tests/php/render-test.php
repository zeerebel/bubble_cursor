<?php
/**
 * Render the settings page under stubbed WP admin functions and assert:
 *  - it renders with ZERO PHP notices/warnings (every $o[...] key exists)
 *  - the preset dropdown has the reset wiring (id + autocomplete + script)
 *  - applying a preset works and apply_preset is never stored
 */
error_reporting( E_ALL );
set_error_handler( function ( $no, $str ) {
	throw new Exception( "PHP notice/warning: $str" );
} );

define( 'ABSPATH', '/tmp/' );
$GLOBALS['__options'] = array();

function plugin_dir_url( $f )  { return 'http://example.com/wp-content/plugins/bubble-cursor/'; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_basename( $f ) { return 'bubble-cursor/bubble-cursor.php'; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function register_activation_hook( ...$a ) {}
function apply_filters( $t, $v ) { return $v; }
function get_option( $k, $d = false ) { return isset( $GLOBALS['__options'][ $k ] ) ? $GLOBALS['__options'][ $k ] : $d; }
function add_option( $k, $v ) { $GLOBALS['__options'][ $k ] = $v; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $s ) ) ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, array_filter( (array) $a, fn( $v ) => null !== $v ) ); }

// Admin render stubs.
function current_user_can( $c ) { return true; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return $s; }
function checked( $a, $b = true, $echo = true ) { $r = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $echo ) echo $r; return $r; }
function selected( $a, $b = true, $echo = true ) { $r = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $echo ) echo $r; return $r; }
function settings_fields( $g ) { echo "<!-- settings_fields:$g -->"; }
function submit_button() { echo '<button type="submit">Save Changes</button>'; }

require __DIR__ . '/../../bubble-cursor/bubble-cursor.php';
$bc = Bubble_Cursor::instance();

$checks = array();
$fail = 0;

// 1) Render the whole page (default options) — any undefined key throws.
try {
	ob_start();
	$bc->render_settings_page();
	$html = ob_get_clean();
	$checks['renders with no notices/warnings'] = true;
} catch ( Exception $e ) {
	if ( ob_get_level() ) { ob_end_clean(); }
	$checks['renders with no notices/warnings'] = false;
	echo 'RENDER ERROR: ' . $e->getMessage() . "\n";
	$html = '';
}

$checks['preset select has id']          = false !== strpos( $html, 'id="bc-apply-preset"' );
$checks['preset select autocomplete off'] = false !== strpos( $html, 'autocomplete="off"' );
$checks['preset reset script present']    = false !== strpos( $html, 'selectedIndex = 0' ) || false !== strpos( $html, 'selectedIndex=0' );
$checks['no preset option pre-selected']  = false === strpos( $html, 'value="neon"' . ' selected' ) && false === strpos( $html, "value=\"neon\" selected" );
$checks['page has expected sections']     = ( false !== strpos( $html, 'Quick presets' ) )
	&& ( false !== strpos( $html, 'Smoke colours' ) )
	&& ( false !== strpos( $html, 'Image preview' ) )
	&& ( false !== strpos( $html, 'Adaptive performance' ) );

// 2) Applying a preset works and is never stored.
$min = $bc->sanitize( array( 'apply_preset' => 'minimal' ) );
$checks['minimal preset applied']   = 0 === $min['enable_fluid'] && 1 === $min['magnetic'];
$checks['apply_preset NOT stored']  = ! array_key_exists( 'apply_preset', $min );
$neon = $bc->sanitize( array( 'apply_preset' => 'neon' ) );
$checks['neon preset applied']      = 'palette' === $neon['color_mode'] && ! array_key_exists( 'apply_preset', $neon );
// No preset selected -> nothing forced (rainbow stays default after a normal save).
$none = $bc->sanitize( array( 'color_mode' => 'rainbow' ) );
$checks['no preset -> no override'] = 'rainbow' === $none['color_mode'] && ! array_key_exists( 'apply_preset', $none );

echo "assertions:\n";
foreach ( $checks as $name => $ok ) {
	echo '  ' . ( $ok ? 'PASS' : 'FAIL' ) . " - $name\n";
	if ( ! $ok ) { $fail++; }
}
echo "\nHTML length: " . strlen( $html ) . " bytes\n";
echo $fail ? "RESULT: $fail FAILED\n" : "RESULT: ALL PASSED\n";
exit( $fail ? 1 : 0 );
