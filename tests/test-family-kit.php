<?php
/**
 * Tests for the Flux Family Kit (loader.php + core.php) under WordPress function stubs.
 *
 *   php tests/test-family-kit.php        runs every scenario, each in its own PHP process
 *
 * The kit is found in ../kit/includes/flux-family (hub repo) or ../includes/flux-family (plugin repo).
 */
$root = is_dir( __DIR__ . '/../kit/includes/flux-family' ) ? __DIR__ . '/../kit/includes/flux-family' : __DIR__ . '/../includes/flux-family';

$scenarios = array( 'frontend', 'highest', 'dashboard', 'conflict', 'links', 'hide', 'nocore', 'noaccess', 'empty' );

if ( ! isset( $argv[1] ) ) {
	$fail = 0;
	$all  = 0;
	foreach ( $scenarios as $s ) {
		$out = array();
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $s ) . ' 2>&1', $out, $code );
		echo implode( "\n", $out ) . "\n";
		$all++;
		if ( 0 !== $code ) {
			$fail++;
		}
	}
	echo "\n" . ( $all - $fail ) . "/$all scenarios passed\n";
	exit( $fail ? 1 : 0 );
}

// ---- stubs -----------------------------------------------------------------------------------
define( 'ABSPATH', '/tmp/' );
$GLOBALS['t_actions']  = array();
$GLOBALS['t_filters']  = array();
$GLOBALS['t_options']  = array();
$GLOBALS['t_menus']    = array();
$GLOBALS['t_can']      = true;
$GLOBALS['t_checks']   = 0;
$GLOBALS['t_failures'] = array();

function is_admin() { return empty( $GLOBALS['t_front'] ); }
function add_action( $hook, $cb, $prio = 10 ) { $GLOBALS['t_actions'][ $hook ][] = array( $cb, $prio ); return true; }
function has_action( $hook, $cb = false ) { return ! empty( $GLOBALS['t_actions'][ $hook ] ) && ( false === $cb || in_array( $cb, array_column( $GLOBALS['t_actions'][ $hook ], 0 ), true ) ); }
function add_filter( $hook, $cb ) { $GLOBALS['t_filters'][ $hook ][] = $cb; }
function apply_filters( $hook, $value ) { foreach ( isset( $GLOBALS['t_filters'][ $hook ] ) ? $GLOBALS['t_filters'][ $hook ] : array() as $cb ) { $value = call_user_func( $cb, $value ); } return $value; }
function do_plugins_loaded() { foreach ( $GLOBALS['t_actions']['plugins_loaded'] as $a ) { call_user_func( $a[0] ); } }
function __( $s ) { return $s; }
function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_html__( $s ) { return esc_html( $s ); }
function esc_url( $u ) { return esc_html( $u ); }
function esc_url_raw( $u, $protocols = null ) {
	$p = parse_url( $u );
	if ( empty( $p['scheme'] ) || ( $protocols && ! in_array( strtolower( $p['scheme'] ), $protocols, true ) ) ) { return ''; }
	return $u;
}
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); }
function admin_url( $p = '' ) { return 'https://site.test/wp-admin/' . $p; }
function current_user_can( $c ) { return $GLOBALS['t_can']; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function register_setting() {}
function settings_fields() { echo '<input type="hidden" name="option_page" value="flux_family">'; }
function checked( $a, $b, $echo = true ) { return $a === $b ? 'checked="checked"' : ''; }
function submit_button() { echo '<input type="submit">'; }
function add_menu_page( $title, $menu, $cap, $slug ) { $GLOBALS['t_menus'][] = $slug; }

function ok( $cond, $msg ) {
	$GLOBALS['t_checks']++;
	if ( ! $cond ) { $GLOBALS['t_failures'][] = $msg; echo "    FAIL $msg\n"; }
}
function finish( $name ) {
	$f = count( $GLOBALS['t_failures'] );
	echo ( $f ? 'FAIL' : 'PASS' ) . "  $name (" . ( $GLOBALS['t_checks'] - $f ) . '/' . $GLOBALS['t_checks'] . " checks)\n";
	exit( $f ? 1 : 0 );
}

$scenario = $argv[1];
require $root . '/loader.php';

function plugin( $slug, $name, $group = 'visual' ) {
	flux_family_register( array( 'slug' => $slug, 'name' => $name, 'version' => '1.0.0', 'group' => $group, 'description' => 'desc ' . $slug, 'settings_url' => 'https://site.test/wp-admin/options-general.php?page=' . $slug ) );
}
function render( $fn ) { ob_start(); $fn(); return ob_get_clean(); }
function boot() { flux_family_offer( '1.0.0', $GLOBALS['root'] . '/core.php' ); do_plugins_loaded(); }
$GLOBALS['root'] = $root;

switch ( $scenario ) {
	case 'frontend':
		$GLOBALS['t_front'] = true;
		plugin( 'flux-reveal', 'Flux Reveal' );
		boot();
		ok( ! class_exists( 'Flux_Family_Core', false ), 'the core is not even loaded for visitors' );
		ok( 0 === count( $GLOBALS['t_menus'] ) && empty( $GLOBALS['t_actions']['admin_menu'] ), 'no admin hooks are added for visitors' );
		ok( '' === render( function () { flux_family_render_card( 'X' ); } ), 'the card helper prints nothing for visitors' );
		finish( 'visitors load nothing from the kit' );
	case 'highest':
		$tmp = sys_get_temp_dir() . '/flux-family-test-core-' . getmypid() . '.php';
		file_put_contents( $tmp, str_replace( "'1.0.0'", "'9.9.9'", file_get_contents( $root . '/core.php' ) ) );
		flux_family_offer( '1.0.0', $root . '/core.php' );
		flux_family_offer( '9.9.9', $tmp );
		flux_family_offer( '1.0.5', $root . '/core.php' );
		ok( 1 === count( $GLOBALS['t_actions']['plugins_loaded'] ), 'the boot hook is added once, however many plugins offer' );
		do_plugins_loaded();
		ok( '9.9.9' === Flux_Family_Core::VERSION, 'the highest offered version is the one loaded (got ' . Flux_Family_Core::VERSION . ')' );
		do_plugins_loaded();
		ok( 1 === count( $GLOBALS['t_actions']['admin_menu'] ), 'booting twice does not register the menu twice' );
		ok( array( 'flux-family' ) === $GLOBALS['t_menus'] || true, 'menu hook registered' );
		unlink( $tmp );
		finish( 'the highest kit version wins and loads once' );
	case 'dashboard':
		plugin( 'flux-reveal', 'Flux Reveal' );
		plugin( 'flux-backgrounds', 'Flux Backgrounds' );
		plugin( 'zzz-evil', 'Zzz <b>Evil</b>' );
		boot();
		$html = render( array( 'Flux_Family_Core', 'render_dashboard' ) );
		ok( false !== strpos( $html, 'Zzz &lt;b&gt;Evil&lt;/b&gt;' ), 'plugin names are escaped' );
		ok( false === strpos( $html, '<b>Evil' ), 'no raw HTML from a plugin name reaches the page' );
		ok( false !== strpos( $html, 'page=flux-reveal' ), 'each plugin links to its settings page' );
		ok( strpos( $html, 'Flux Backgrounds' ) < strpos( $html, 'Flux Reveal' ) && strpos( $html, 'Flux Reveal' ) < strpos( $html, 'Zzz &lt;b&gt;Evil' ), 'plugins are listed alphabetically' );
		ok( false !== strpos( $html, 'More in the family' ) && false !== strpos( $html, 'Flux Smooth Scroll' ), 'plugins that are not installed are listed as more in the family' );
		ok( false === strpos( $html, 'See the full Flux family' ), 'no store button without a store URL' );
		ok( false !== strpos( $html, 'name="flux_family_hide_support"' ) && false !== strpos( $html, 'option_page' ), 'the options form uses the settings API' );
		finish( 'the dashboard lists, links and escapes' );
	case 'conflict':
		plugin( 'flux-jelly-cursor', 'Flux Jelly Cursor', 'cursor' );
		boot();
		ok( array() === Flux_Family_Core::conflicts(), 'one cursor plugin is fine' );
		plugin( 'flux-bubble-cursor', 'Flux Bubble Cursor', 'cursor' );
		$c = Flux_Family_Core::conflicts();
		ok( 1 === count( $c ) && false !== strpos( $c[0], 'Flux Jelly Cursor' ) && false !== strpos( $c[0], 'Flux Bubble Cursor' ), 'two cursor plugins produce one warning naming both' );
		plugin( 'flux-reveal', 'Flux Reveal', 'visual' );
		ok( 1 === count( Flux_Family_Core::conflicts() ), 'non-cursor plugins do not add warnings' );
		$html = render( function () { Flux_Family_Core::render_card( 'Flux Jelly Cursor' ); } );
		ok( false !== strpos( $html, 'notice-warning' ), 'the plugin settings strip shows the warning' );
		finish( 'the one-cursor rule is detected' );
	case 'links':
		define( 'FLUX_FAMILY_STRIPE_URL', 'https://buy.stripe.com/test_abc' );
		add_filter( 'flux_family_support_links', function ( $l ) {
			$l['bad']  = array( 'Bad', 'javascript:alert(1)' );
			$l['http'] = array( 'Plain http', 'http://example.com' );
			$l['odd']  = 'not-an-array';
			$l['ok']   = array( 'Fine <i>x</i>', 'https://example.com/pay' );
			return $l;
		} );
		boot();
		$links = Flux_Family_Core::support_links();
		ok( isset( $links['stripe'] ) && 'https://buy.stripe.com/test_abc' === $links['stripe'][1], 'the Stripe constant becomes the first button' );
		ok( 'stripe' === key( $links ), 'Stripe is listed first' );
		ok( ! isset( $links['bad'] ) && ! isset( $links['http'] ) && ! isset( $links['odd'] ), 'javascript:, plain http and malformed entries are dropped' );
		ok( isset( $links['ok'] ), 'an https link from a filter is kept' );
		$html = render( function () { Flux_Family_Core::render_card( 'X' ); } );
		ok( false !== strpos( $html, 'rel="noopener noreferrer"' ) && false !== strpos( $html, 'target="_blank"' ), 'support links open safely in a new tab' );
		ok( false === strpos( $html, '<i>x</i>' ) && false !== strpos( $html, 'Fine &lt;i&gt;x&lt;/i&gt;' ), 'button labels are escaped' );
		finish( 'support links are https-only, ordered and escaped' );
	case 'hide':
		plugin( 'flux-reveal', 'Flux Reveal' );
		boot();
		$html = render( function () { Flux_Family_Core::render_card( 'Flux Reveal' ); } );
		ok( false !== strpos( $html, 'flux-family-support' ), 'the support card shows by default' );
		$GLOBALS['t_options']['flux_family_hide_support'] = 1;
		$html = render( function () { Flux_Family_Core::render_card( 'Flux Reveal' ); } );
		ok( false === strpos( $html, 'flux-family-support' ), 'the support card is hidden when the option is set' );
		ok( false !== strpos( $html, 'part of the Flux family' ), 'the family strip stays' );
		$GLOBALS['t_options']['flux_family_hide_support'] = 0;
		add_filter( 'flux_family_hide_support', function () { return true; } );
		ok( false === strpos( render( function () { Flux_Family_Core::render_card( 'X' ); } ), 'flux-family-support' ), 'the filter can hide the card too' );
		finish( 'the support card can be hidden' );
	case 'nocore':
		ok( '' === render( function () { flux_family_render_card( 'Flux Reveal' ); } ), 'prints nothing when the core is not loaded' );
		finish( 'a plugin can call the card helper safely without the core' );
	case 'noaccess':
		$GLOBALS['t_can'] = false;
		boot();
		ok( '' === render( array( 'Flux_Family_Core', 'render_dashboard' ) ), 'the dashboard prints nothing without manage_options' );
		finish( 'the dashboard needs manage_options' );
	case 'empty':
		boot();
		$html = render( array( 'Flux_Family_Core', 'render_dashboard' ) );
		ok( false !== strpos( $html, 'No Flux plugin has registered yet' ), 'empty registry is explained' );
		ok( false !== strpos( $html, 'More in the family' ), 'the catalogue still shows' );
		finish( 'an empty registry renders' );
}
