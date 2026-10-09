<?php
/**
 * Backup bundled in InsightX AllHub: loads on its own, stands aside when the
 * old standalone plugin is active, and treats all of Offload as "itself".
 * Each case runs in a fresh PHP process (constants cannot be undefined).
 */
$pass = 0; $fail = 0;
function t( $label, $ok, $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS $label\n"; } else { $fail++; echo "  FAIL $label  -> $detail\n"; }
}
$root = dirname( __DIR__, 2 ) . '/';
$stubs = '<?php
define( "ABSPATH", sys_get_temp_dir() . "/isx_mod_wp/" );
define( "WP_CONTENT_DIR", ABSPATH . "wp-content" );
define( "ISXM_PLUGIN_DIR", ' . var_export( $root, true ) . ' );
@mkdir( WP_CONTENT_DIR, 0777, true );
$GLOBALS["hooks"] = [];
function add_action( $h, $cb, $p = 10 ) { $GLOBALS["hooks"][] = $h; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS["hooks"][] = $h; }
function get_option( $k, $d = false ) { return $d; }
function update_option() { return true; }
function delete_option() { return true; }
function untrailingslashit( $s ) { return rtrim( $s, "/\\\\" ); }
function trailingslashit( $s ) { return untrailingslashit( $s ) . "/"; }
function plugin_dir_path( $f ) { return trailingslashit( dirname( $f ) ); }
function plugin_dir_url( $f ) { return "https://example.test/" . basename( dirname( $f ) ) . "/"; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || @mkdir( $d, 0777, true ); }
function is_admin() { return false; }
function __( $s ) { return $s; }
function esc_html__( $s ) { return $s; }
';
$run = function ( $body ) use ( $stubs ) {
	$file = tempnam( sys_get_temp_dir(), 'isxmod' ) . '.php';
	file_put_contents( $file, $stubs . $body );
	$out = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
	@unlink( $file );
	return trim( (string) $out );
};

echo "\n=== Bundled Backup module ===\n";
$out = $run( '$loaded = require ISXM_PLUGIN_DIR . "modules/backup/load.php";
echo json_encode( [ "loaded" => $loaded, "file" => ISX_FILE, "root" => ISX_PLUGIN_ROOT, "admin" => class_exists( "ISX_Admin", false ), "cron" => in_array( "isx_scheduled_backup", $GLOBALS["hooks"], true ), "path" => ISX_PATH ] );' );
$r = json_decode( $out, true );
t( 'Loads on its own (old plugin absent)', is_array( $r ) && $r['loaded'] === true && $r['admin'] === true, $out );
t( 'ISX_FILE is InsightX AllHub\'s plugin file (import keeps it active)', is_array( $r ) && $r['file'] === $root . 'insightx-allhub.php', $out );
t( 'All of InsightX AllHub is protected from import/clean and storage', is_array( $r ) && $r['root'] === rtrim( $root, '/' ), $out );
t( 'Views and assets still come from the module folder', is_array( $r ) && $r['path'] === $root . 'modules/backup/', $out );
t( 'The scheduled-backup hook is registered on every request', is_array( $r ) && $r['cron'] === true, $out );

$out = $run( 'define( "ISX_VERSION", "0.1.31" ); // the standalone plugin got here first
$loaded = require ISXM_PLUGIN_DIR . "modules/backup/load.php";
echo json_encode( [ "loaded" => $loaded, "admin" => class_exists( "ISX_Admin", false ), "notice" => in_array( "admin_notices", $GLOBALS["hooks"], true ) ] );' );
$r = json_decode( $out, true );
t( 'Old plugin active: the bundled copy stands aside (no "cannot redeclare")', is_array( $r ) && $r['loaded'] === false && $r['admin'] === false, $out );
t( '…and asks for the old plugin to be removed', is_array( $r ) && $r['notice'] === true, $out );

echo "\n$pass passed; $fail failed\n";
exit( $fail ? 1 : 0 );
