<?php
/**
 * InsightX AllHub (renamed from InsightX Offload): stands aside while the old
 * insightx-offload plugin is active or already loaded, and deactivates it on
 * activation. Each case runs in a fresh PHP process.
 */
$pass = 0; $fail = 0;
function t( $label, $ok, $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS $label\n"; } else { $fail++; echo "  FAIL $label  -> $detail\n"; }
}
$main = dirname( __DIR__ ) . '/insightx-allhub.php';
$stubs = '<?php
define( "ABSPATH", "/tmp/" );
$GLOBALS["t"] = [ "actions" => [], "activation" => null, "deactivated" => null ];
function add_action( $h, $cb, $p = 10 ) { $GLOBALS["t"]["actions"][ $h ] = $cb; }
function add_filter() {}
function register_activation_hook( $f, $cb ) { $GLOBALS["t"]["activation"] = $cb; }
function register_deactivation_hook() {}
function deactivate_plugins( $p, $silent = false, $nw = null ) { $GLOBALS["t"]["deactivated"] = [ $p, $silent, $nw ]; }
function is_multisite() { return ! empty( $GLOBALS["ms"] ); }
function get_option( $k, $d = false ) { return $k === "active_plugins" ? ( $GLOBALS["active"] ?? [] ) : $d; }
function get_site_option( $k, $d = false ) { return $k === "active_sitewide_plugins" ? ( $GLOBALS["sitewide"] ?? [] ) : $d; }
function esc_html__( $s ) { return $s; }
function trailingslashit( $s ) { return rtrim( $s, "/" ) . "/"; }
function plugin_dir_path( $f ) { return trailingslashit( dirname( $f ) ); }
function plugin_dir_url( $f ) { return "https://example.test/"; }
register_shutdown_function( function () {
	$t = $GLOBALS["t"];
	if ( $t["activation"] ) ( $t["activation"] )( false );
	ob_start(); if ( isset( $t["actions"]["admin_notices"] ) ) ( $t["actions"]["admin_notices"] )(); $notice = ob_get_clean();
	echo "\n" . json_encode( [
		"booted"      => defined( "ISXM_PLUGIN_DIR" ) && strpos( ISXM_PLUGIN_DIR, "insightx-allhub" ) !== false,
		"has_admin"   => class_exists( "ISXM_Admin", false ),
		"activation"  => (bool) $t["activation"],
		"deactivated" => $GLOBALS["t"]["deactivated"],
		"notice"      => $notice,
		"net_notice"  => isset( $t["actions"]["network_admin_notices"] ),
	] );
} );
';
$run = function ( $pre ) use ( $stubs, $main ) {
	$file = tempnam( sys_get_temp_dir(), 'isxren' ) . '.php';
	file_put_contents( $file, $stubs . $pre . "\ninclude " . var_export( $main, true ) . ";\n" );
	$out = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
	@unlink( $file );
	$lines = explode( "\n", trim( (string) $out ) );
	return [ json_decode( end( $lines ), true ), $out ];
};
$old = 'insightx-offload/insightx-offload.php';

echo "=== Old InsightX Offload still active ===\n";
[ $r, $out ] = $run( '$GLOBALS["active"] = [ "akismet/akismet.php", "' . $old . '" ];' );
t( 'R1 AllHub does not boot (no ISXM_* code loaded, no fatal from duplicate classes)', is_array( $r ) && ! $r['booted'] && ! $r['has_admin'], $out );
t( 'R2 activating AllHub deactivates insightx-offload', is_array( $r ) && $r['activation'] && $r['deactivated'][0] === $old && $r['deactivated'][1] === true, $out );
t( 'R3 admins are told to deactivate and delete the old plugin', is_array( $r ) && strpos( $r['notice'], 'InsightX Offload is now InsightX AllHub' ) !== false && $r['net_notice'], $out );

echo "\n=== Old plugin already loaded on this request (the activation request) ===\n";
[ $r, $out ] = $run( 'define( "ISXM_PLUGIN_DIR", "/x/insightx-offload/" );' );
t( 'R4 AllHub stands aside and still deactivates the old plugin on activation', is_array( $r ) && ! $r['has_admin'] && $r['deactivated'][0] === $old, $out );

echo "\n=== Old plugin network-active (multisite) ===\n";
[ $r, $out ] = $run( '$GLOBALS["ms"] = true; $GLOBALS["sitewide"] = [ "' . $old . '" => time() ];' );
t( 'R5 network-active old plugin is detected too', is_array( $r ) && ! $r['booted'] && $r['deactivated'][0] === $old, $out );

echo "\n=== Old plugin not active ===\n";
[ $r, $out ] = $run( '$GLOBALS["active"] = [ "insightx-allhub/insightx-allhub.php" ];' );
t( 'R6 AllHub boots normally and deactivates nothing', is_array( $r ) && $r['booted'] && $r['deactivated'] === null && $r['notice'] === '', $out );
t( 'R7 a look-alike plugin (insightx-offload-pro) is not mistaken for the old one', ( function () use ( $run ) {
	[ $r ] = $run( '$GLOBALS["active"] = [ "insightx-offload-pro/insightx-offload.php" ];' );
	return is_array( $r ) && $r['booted'];
} )(), '' );

echo "\n========================================\n$pass passed; $fail failed\n";
exit( $fail ? 1 : 0 );
