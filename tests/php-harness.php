<?php
/**
 * PHP CLI harness with stubbed WordPress functions. Not a real WordPress
 * render: it exercises the plugin's own logic (double include, sanitize(),
 * should_load() incl. builder query args, script tag filter, cache purge,
 * uninstall). Run: php tests/php-harness.php
 */

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;
function t_check( $name, $cond, $detail = null ) {
	if ( $cond ) {
		$GLOBALS['t_pass']++;
		echo "  ok   $name\n";
	} else {
		$GLOBALS['t_fail']++;
		echo "  FAIL $name" . ( null !== $detail ? '  -> ' . json_encode( $detail ) : '' ) . "\n";
	}
}

/* ---- stub state ---- */
$GLOBALS['s'] = array(
	'actions'      => array(),
	'filters'      => array(),
	'enqueued'     => array(),
	'inline'       => array(),
	'did'          => array(),
	'calls'        => array(),
	'options'      => array(),
	'admin'        => false,
	'front'        => true,
	'customize'    => false,
	'vc_editable'  => false,
	'vc_inline'    => false,
	'multisite'    => false,
);

/* ---- stub WordPress functions ---- */
define( 'ABSPATH', __DIR__ . '/' );
function plugin_dir_url( $f ) { return 'http://example.test/wp-content/plugins/bubble-cursor/'; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_basename( $f ) { return 'bubble-cursor/' . basename( $f ); }
function register_activation_hook( $f, $cb ) { $GLOBALS['s']['activation'][] = $cb; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['s']['actions'][ $h ][] = $cb; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['s']['filters'][ $h ][] = $cb; }
function apply_filters( $h, $v ) { return $v; }
function do_action( $h ) { $GLOBALS['s']['did'][] = $h; }
function is_admin() { return $GLOBALS['s']['admin']; }
function is_front_page() { return $GLOBALS['s']['front']; }
function is_customize_preview() { return $GLOBALS['s']['customize']; }
function vc_is_page_editable() { return $GLOBALS['s']['vc_editable']; }
function vc_is_inline() { return $GLOBALS['s']['vc_inline']; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['s']['options'] ) ? $GLOBALS['s']['options'][ $k ] : $d; }
function add_option( $k, $v ) { $GLOBALS['s']['options'][ $k ] = $v; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $v ) { return $v; }
function esc_html__( $v ) { return $v; }
function __( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function wp_enqueue_style( $h, $src, $deps, $ver ) { $GLOBALS['s']['enqueued'][ 'style:' . $h ] = compact( 'src', 'ver' ); }
function wp_enqueue_script( $h, $src, $deps, $ver, $foot ) { $GLOBALS['s']['enqueued'][ $h ] = compact( 'src', 'ver', 'foot' ); }
function wp_add_inline_script( $h, $code, $pos ) { $GLOBALS['s']['inline'][ $h ] = array( $code, $pos ); }
function add_options_page() {}
// Extra stubs for rendering the settings page and the Flux Family Kit.
function has_action( $h, $cb = false ) { return ! empty( $GLOBALS['s']['actions'][ $h ] ) && in_array( $cb, $GLOBALS['s']['actions'][ $h ], true ); }
function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_html_e( $v ) { echo esc_html( $v ); }
function esc_url_raw( $u, $protocols = null ) {
	$p = parse_url( $u );
	if ( empty( $p['scheme'] ) || ( $protocols && ! in_array( strtolower( $p['scheme'] ), $protocols, true ) ) ) { return ''; }
	return $u;
}
function current_user_can( $c ) { return true; }
function sanitize_key( $k ) { return preg_replace( "/[^a-z0-9_\\-]/", "", strtolower( $k ) ); }
function esc_js( $s ) { return addslashes( (string) $s ); }
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . $g . '">'; }
function checked( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; }
function selected( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; }
function submit_button() { echo '<input type="submit">'; }
function add_menu_page() {}
function register_setting() {}
function admin_url( $p ) { return $p; }
function rocket_clean_domain() { $GLOBALS['s']['calls'][] = 'rocket_clean_domain'; }
function w3tc_flush_all() { $GLOBALS['s']['calls'][] = 'w3tc_flush_all'; }
class autoptimizeCache { public static function clearall() { $GLOBALS['s']['calls'][] = 'autoptimizeCache::clearall'; } }
// Elementor stub.
eval( 'namespace Elementor { class Preview { public $on = false; public function is_preview_mode() { return $this->on; } } class Plugin { public static $instance; public $preview; } }' );
\Elementor\Plugin::$instance          = new \Elementor\Plugin();
\Elementor\Plugin::$instance->preview = new \Elementor\Preview();

$plugin = dirname( __DIR__ ) . '/bubble-cursor/bubble-cursor.php';

echo "\n[A] double include does not fatal\n";
include $plugin;
$ok = true;
try {
	include $plugin; // would be "Cannot redeclare" before the guard.
} catch ( \Throwable $e ) {
	$ok = false;
}
t_check( 'including bubble-cursor.php twice completes', $ok );
t_check( 'version constant is 1.3.0', BUBBLE_CURSOR_VERSION === '1.3.0', BUBBLE_CURSOR_VERSION );
t_check( 'hooks registered once (not twice)', count( $GLOBALS['s']['actions']['wp_enqueue_scripts'] ) === 1 && count( $GLOBALS['s']['activation'] ) === 1 );
t_check( 'class and activation function exist', class_exists( 'Bubble_Cursor', false ) && function_exists( 'bubble_cursor_activate' ) );
t_check( 'plugin header says 1.3.0', 1 === preg_match( '/^\s*\* Version:\s+1\.3\.0$/m', file_get_contents( $plugin ) ) );

$bc = Bubble_Cursor::instance();
$should = new ReflectionMethod( 'Bubble_Cursor', 'should_load' );
if ( PHP_VERSION_ID < 80100 ) { $should->setAccessible( true ); }
$load = function ( $o = array() ) use ( $should, $bc ) {
	return $should->invoke( $bc, array_merge( Bubble_Cursor::defaults(), $o ) );
};

echo "\n[B] should_load()\n";
t_check( 'loads by default', true === $load() );
t_check( 'enable=0 -> no', false === $load( array( 'enable' => 0 ) ) );
t_check( 'scope=front off the front page -> no', false === ( function () use ( $load ) { $GLOBALS['s']['front'] = false; $r = $load( array( 'scope' => 'front' ) ); $GLOBALS['s']['front'] = true; return $r; } )() );
$GLOBALS['s']['admin'] = true;
t_check( 'is_admin -> no', false === $load() );
$GLOBALS['s']['admin'] = false;
$GLOBALS['s']['customize'] = true;
t_check( 'Customizer preview -> no', false === $load() );
$GLOBALS['s']['customize'] = false;
foreach ( array( 'vc_editable', 'elementor-preview', 'et_fb', 'fl_builder', 'ct_builder', 'brizy-edit', 'brizy-edit-iframe' ) as $arg ) {
	$_GET = array( $arg => '1' );
	t_check( "?$arg -> no", false === $load() );
}
$_GET = array();
$GLOBALS['s']['vc_editable'] = true;
t_check( 'vc_is_page_editable() -> no', false === $load() );
$GLOBALS['s']['vc_editable'] = false;
$GLOBALS['s']['vc_inline'] = true;
t_check( 'vc_is_inline() -> no', false === $load() );
$GLOBALS['s']['vc_inline'] = false;
\Elementor\Plugin::$instance->preview->on = true;
t_check( 'Elementor preview mode -> no', false === $load() );
\Elementor\Plugin::$instance->preview->on = false;
t_check( 'unrelated query args still load', true === ( function () use ( $load ) { $_GET = array( 'utm_source' => 'x', 'p' => '3' ); $r = $load(); $_GET = array(); return $r; } )() );
t_check( 'back to normal -> loads', true === $load() );

echo "\n[C] enqueue + script tag filter\n";
$GLOBALS['s']['options'][ BUBBLE_CURSOR_OPTION ] = array( 'ring_color' => '#ff0000', 'hover_text' => "It's \"quoted\" & <b>" );
$bc->enqueue_assets();
$e = $GLOBALS['s']['enqueued'];
t_check( 'style + both scripts enqueued', isset( $e['style:bubble-cursor'], $e['bubble-cursor'], $e['bubble-cursor-fluid'] ) && count( $e ) === 3, array_keys( $e ) );
t_check( 'asset ver = version + filemtime', 1 === preg_match( '/^1\.3\.0-\d+$/', (string) $e['bubble-cursor-fluid']['ver'] ), $e['bubble-cursor-fluid']['ver'] );
t_check( 'both scripts in footer', true === $e['bubble-cursor']['foot'] && true === $e['bubble-cursor-fluid']['foot'] );
t_check( 'inline global kept for backwards compat', 0 === strpos( $GLOBALS['s']['inline']['bubble-cursor-fluid'][0], 'window.BubbleCursorSettings = {' ) );
$_GET = array( 'vc_editable' => 'true' );
$GLOBALS['s']['enqueued'] = array();
$bc->enqueue_assets();
t_check( 'nothing enqueued in the WPBakery editor', array() === $GLOBALS['s']['enqueued'] );
$_GET = array();

$before = '<script id="bubble-cursor-fluid-js-before">window.BubbleCursorSettings = {};</script>';
$fluid  = $before . "\n<script src='http://example.test/fluid-cursor.js?ver=1' id=\"bubble-cursor-fluid-js\"></script>";
$main   = "<script src='http://example.test/bubble-cursor.js?ver=1' id=\"bubble-cursor-js\"></script>";
$f      = $bc->filter_script_tag( $fluid, 'bubble-cursor-fluid' );
$m      = $bc->filter_script_tag( $main, 'bubble-cursor' );
foreach ( array( 'data-no-optimize="1"', 'data-no-defer="1"', 'data-no-minify="1"', 'data-no-delay="1"', 'data-cfasync="false"', 'data-pagespeed-no-defer', 'data-wpfc-render="false"', 'nowprocket' ) as $attr ) {
	t_check( "main tag has $attr", false !== strpos( $m, $attr ) );
	t_check( "fluid tag (and its inline script) has $attr", substr_count( $f, $attr ) === 2, substr_count( $f, $attr ) );
}
t_check( 'src/id of main tag preserved', false !== strpos( $m, "src='http://example.test/bubble-cursor.js?ver=1'" ) && false !== strpos( $m, 'id="bubble-cursor-js"' ) );
preg_match( '/data-bc-settings="([^"]*)"/', $m, $mm );
$decoded = isset( $mm[1] ) ? json_decode( html_entity_decode( $mm[1], ENT_QUOTES ), true ) : null;
t_check( 'main tag carries valid data-bc-settings JSON', is_array( $decoded ) && '#ff0000' === $decoded['ringColor'], $mm );
t_check( 'quotes/ampersands/angle brackets in settings survive the attribute', is_array( $decoded ) && "It's \"quoted\" & <b>" === $decoded['hoverText'], $decoded['hoverText'] ?? null );
t_check( 'raw attribute contains no unescaped double quote or <', isset( $mm[1] ) && false === strpos( $mm[1], '<' ) );
t_check( 'fluid tag does NOT get data-bc-settings', false === strpos( $f, 'data-bc-settings' ) );
t_check( 'unrelated handle untouched', "<script src='x.js'></script>" === $bc->filter_script_tag( "<script src='x.js'></script>", 'jquery' ) );
t_check( 'filter is idempotent (no duplicate attributes)', substr_count( $bc->filter_script_tag( $m, 'bubble-cursor' ), 'data-no-optimize' ) === 1 );
t_check( 'script_loader_tag filter registered', ! empty( $GLOBALS['s']['filters']['script_loader_tag'] ) );

echo "\n[D] sanitize() and JS settings\n";
$san = function ( $in ) use ( $bc ) { return $bc->sanitize( $in ); };
t_check( 'hide_native kept when dot on', 1 === $san( array( 'enable_dot' => 1, 'hide_native' => 1 ) )['hide_native'] );
t_check( 'hide_native kept when ring on', 1 === $san( array( 'enable_ring' => 1, 'hide_native' => 1 ) )['hide_native'] );
t_check( 'hide_native forced to 0 when dot AND ring are off', 0 === $san( array( 'hide_native' => 1, 'enable_fluid' => 1 ) )['hide_native'] );
t_check( 'clamps still work (ring_size 999 -> 120)', 120 == $san( array( 'ring_size' => 999 ) )['ring_size'], $san( array( 'ring_size' => 999 ) )['ring_size'] );
t_check( 'bad colour falls back', '#ffffff' === $san( array( 'dot_color' => 'red; x' ) )['dot_color'] );
$bj = new ReflectionMethod( 'Bubble_Cursor', 'build_js_settings' );
if ( PHP_VERSION_ID < 80100 ) { $bj->setAccessible( true ); }
$o = array_merge( Bubble_Cursor::defaults(), array( 'hide_native' => 1, 'enable_dot' => 0, 'enable_ring' => 0 ) );
t_check( 'stale saved hide_native is neutralised in JS settings', false === $bj->invoke( $bc, $o )['hideNativeCursor'] );
$o['enable_dot'] = 1;
t_check( 'JS hideNativeCursor true when a follower is on', true === $bj->invoke( $bc, $o )['hideNativeCursor'] );

echo "\n[E] cache purge on settings save\n";
t_check( 'hooked to update_option and add_option', ! empty( $GLOBALS['s']['actions'][ 'update_option_' . BUBBLE_CURSOR_OPTION ] ) && ! empty( $GLOBALS['s']['actions'][ 'add_option_' . BUBBLE_CURSOR_OPTION ] ) );
$bc->purge_caches();
$calls = $GLOBALS['s']['calls'];
t_check( 'rocket_clean_domain / w3tc_flush_all / autoptimize called when present', array( 'rocket_clean_domain', 'w3tc_flush_all', 'autoptimizeCache::clearall' ) === $calls, $calls );
t_check( 'litespeed_purge_all action fired', in_array( 'litespeed_purge_all', $GLOBALS['s']['did'], true ) );

echo "\n[G] Flux Family Kit\n";
global $flux_family_registry, $flux_family_offers;
$reg = isset( $flux_family_registry['flux-bubble-cursor'] ) ? $flux_family_registry['flux-bubble-cursor'] : array();
t_check( 'family: registers slug flux-bubble-cursor, group cursor, name Flux Bubble Cursor', 'flux-bubble-cursor' === ( $reg['slug'] ?? '' ) && 'cursor' === ( $reg['group'] ?? '' ) && 'Flux Bubble Cursor' === ( $reg['name'] ?? '' ), $reg );
t_check( 'family: registered version is BUBBLE_CURSOR_VERSION', BUBBLE_CURSOR_VERSION === ( $reg['version'] ?? '' ) );
t_check( 'family: settings_url points at the real settings page', is_callable( $reg['settings_url'] ?? null ) && false !== strpos( call_user_func( $reg['settings_url'] ), 'options-general.php?page=bubble-cursor' ) );
$kit_core = dirname( __DIR__ ) . '/bubble-cursor/includes/flux-family/core.php';
t_check( 'family: offers the bundled core.php (inside bubble-cursor/)', isset( $flux_family_offers['1.0.0'] ) && $flux_family_offers['1.0.0'] === $kit_core && is_readable( $kit_core ), $flux_family_offers );

// Front end: booting the kit loads nothing and prints nothing.
$GLOBALS['s']['admin'] = false;
ob_start();
flux_family_boot();
flux_family_render_card( 'Flux Bubble Cursor' );
$silent = ob_get_clean();
t_check( 'family: front end loads no kit core and prints nothing', '' === $silent && ! class_exists( 'Flux_Family_Core', false ) );
t_check( 'family: front-end should_load result unchanged by the kit', true === $load() );
t_check( 'family: front-end JS settings carry nothing from the kit', false === stripos( json_encode( $bj->invoke( $bc, Bubble_Cursor::defaults() ) ), 'family' ) );
t_check( 'family: kit adds nothing to the front-end hooks', empty( $GLOBALS['s']['actions']['wp_footer'] ) && empty( $GLOBALS['s']['actions']['wp_head'] ) );

// Admin: the kit loads and the settings page ends with the family strip.
$GLOBALS['s']['admin'] = true;
flux_family_boot();
t_check( 'family: admin boot loads Flux_Family_Core', class_exists( 'Flux_Family_Core', false ) );
ob_start();
$bc->render_settings_page();
$page = ob_get_clean();
t_check( 'family: settings page output contains the family strip', false !== strpos( $page, 'flux-family-strip' ) && false !== strpos( $page, 'Flux Bubble Cursor is part of the Flux family' ) );
t_check( 'family: strip is inside the wrap, after the form', strrpos( $page, '</form>' ) < strpos( $page, 'flux-family-strip' ) && strrpos( $page, '</div>' ) > strpos( $page, 'flux-family-strip' ) );
$GLOBALS['s']['admin'] = false;

echo "\n[F] uninstall.php\n";
$GLOBALS['s']['deleted'] = array();
function is_multisite() { return $GLOBALS['s']['multisite']; }
function get_sites( $a ) { return array( 1, 2, 7 ); }
function delete_option( $k ) { $GLOBALS['s']['deleted'][] = "option:$k"; }
function delete_blog_option( $id, $k ) { $GLOBALS['s']['deleted'][] = "blog$id:$k"; }
function delete_site_option( $k ) { $GLOBALS['s']['deleted'][] = "site:$k"; }
define( 'WP_UNINSTALL_PLUGIN', 'bubble-cursor/bubble-cursor.php' );
include dirname( __DIR__ ) . '/bubble-cursor/uninstall.php';
t_check( 'single site: delete_option only', array( 'option:bubble_cursor_options' ) === $GLOBALS['s']['deleted'], $GLOBALS['s']['deleted'] );
$GLOBALS['s']['deleted']   = array();
$GLOBALS['s']['multisite'] = true;
include dirname( __DIR__ ) . '/bubble-cursor/uninstall.php';
t_check( 'multisite: every site + network option removed', array( 'blog1:bubble_cursor_options', 'blog2:bubble_cursor_options', 'blog7:bubble_cursor_options', 'site:bubble_cursor_options' ) === $GLOBALS['s']['deleted'], $GLOBALS['s']['deleted'] );

echo "\nPHP harness: {$GLOBALS['t_pass']} passed, {$GLOBALS['t_fail']} failed\n";
exit( $GLOBALS['t_fail'] ? 1 : 0 );
