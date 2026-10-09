<?php
/**
 * Backup module (bundled in InsightX AllHub): security checks.
 *
 *  A. Every AJAX endpoint is authenticated, with the right strength.
 *  B. Reset Hub asks for the account password before anything runs.
 *  C. The one-time password reveal after a reset.
 *  D. Background jobs authenticate with a long per-job secret.
 *  E. The storage folder is locked down and never somewhere WordPress replaces.
 *  F. An import never overwrites or deletes InsightX AllHub itself.
 *  G. Menu, hidden screens and tabs follow each screen's capability.
 *  H. Backup downloads can only ever serve files from the backups folder.
 *
 * Code that needs fresh constants or stubs runs in its own PHP process.
 * `php tests/backup/security.php` — no WordPress, no network.
 */

error_reporting( E_ALL );
$pass = 0; $fail = 0; $group = '';
function group( $name ) { global $group; $group = $name; echo "\n=== $name ===\n"; }
function t( $label, $ok, $detail = '' ) {
	global $pass, $fail, $group;
	if ( $ok ) { $pass++; echo "  PASS $label\n"; } else { $fail++; echo "  FAIL [$group] $label  -> $detail\n"; }
}

$ROOT   = dirname( __DIR__, 2 );            // insightx-allhub/
$MODULE = $ROOT . '/modules/backup';
$ADMIN  = file_get_contents( $MODULE . '/includes/class-isx-admin.php' );

/** Runs PHP code in a fresh process; returns its trimmed output. */
function isx_sub( $code ) {
	$f = tempnam( sys_get_temp_dir(), 'isxsec' ) . '.php';
	file_put_contents( $f, "<?php\n" . $code );
	$out = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $f ) . ' 2>&1' );
	@unlink( $f );
	return trim( (string) $out );
}

/** Body of a public/private static method of ISX_Admin. */
function isx_method_body( $src, $method ) {
	if ( ! preg_match( '/static function ' . preg_quote( $method, '/' ) . '\([^)]*\)\s*\{/', $src, $m, PREG_OFFSET_CAPTURE ) ) return '';
	$start = $m[0][1] + strlen( $m[0][0] );
	$depth = 1;
	for ( $i = $start; $i < strlen( $src ) && $depth > 0; $i++ ) {
		if ( $src[ $i ] === '{' ) $depth++;
		if ( $src[ $i ] === '}' ) $depth--;
	}
	return substr( $src, $start, $i - $start - 1 );
}

// Stubs shared by the ISX_Admin subprocesses.
$ADMIN_STUBS = '
define( "ABSPATH", "/" );
class ISX_Logger { public static function __callStatic( $n, $a ) {} }
class ISX_Reject extends Exception {}
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : $s; }
function sanitize_key( $s ) { return strtolower( preg_replace( "/[^a-z0-9_\-]/i", "", (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function __( $t ) { return $t; }
function esc_html__( $t ) { return $t; }
function _n( $a, $b, $n ) { return $n == 1 ? $a : $b; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_html_e( $t ) { echo $t; }
function admin_url( $p = "" ) { return "/wp-admin/" . $p; }
function wp_send_json_error( $d = null ) { throw new ISX_Reject( is_array( $d ) && isset( $d["message"] ) ? $d["message"] : "error" ); }
function wp_send_json_success( $d = null ) { echo "OK:" . json_encode( $d ); throw new ISX_Reject( "__ok__" ); }
function wp_die( $m = "" ) { throw new ISX_Reject( (string) $m ); }
function status_header() {} function nocache_headers() {}
function add_action() {} function add_filter() {}
function untrailingslashit( $s ) { return rtrim( $s, "/\\\\" ); }
';

// =====================================================================
group( 'A. Every AJAX endpoint is authenticated, with the right strength' );
// =====================================================================
preg_match_all( "/add_action\(\s*'wp_ajax_(nopriv_)?(\w+)',\s*array\(\s*__CLASS__,\s*'(\w+)'\s*\)/", $ADMIN, $mm, PREG_SET_ORDER );
$actions = array(); $nopriv = array();
foreach ( $mm as $m ) {
	$actions[ $m[2] ] = $m[3];
	if ( $m[1] !== '' ) $nopriv[] = $m[2];
}
// Capability each logged-in endpoint must demand (through guard()). Import,
// restore and Reset Hub write plugin/theme code, so they need more than
// "export" — and DISALLOW_FILE_MODS stops them (guard(), see run.php G5).
$guarded = array(
	'isx_export_start' => 'export', 'isx_list_tables' => 'export', 'isx_download' => 'export', 'isx_log_poll' => 'export',
	'isx_backups_list' => 'export', 'isx_backups_delete' => 'export', 'isx_backups_list_content' => 'export', 'isx_backups_verify' => 'export',
	'isx_storage_save' => 'export', 'isx_storage_dir_save' => 'export', 'isx_schedule_save' => 'export', 'isx_cleanup_uploads' => 'export',
	'isx_import_create' => 'import', 'isx_import_chunk' => 'import', 'isx_backups_restore' => 'import',
	'isx_storage_import_list' => 'import', 'isx_storage_import_prepare' => 'import',
	'isx_reset_run' => 'manage_options',
);
// Authenticated by the job's on-disk secret instead (an import replaces
// wp_users mid-run, so the WP session cannot be relied on).
$by_secret = array( 'isx_run', 'isx_job_cancel', 'isx_import_decrypt' );
$by_nonce  = array( 'isx_client_log' );          // log lines only
$by_token  = array( 'isx_reset_password_reveal' ); // one-time token + capability

t( 'A1 26 handlers registered, every one of them classified here', count( $mm ) === 26 && ! array_diff( array_keys( $actions ), array_merge( array_keys( $guarded ), $by_secret, $by_nonce, $by_token ) ), json_encode( array_diff( array_keys( $actions ), array_merge( array_keys( $guarded ), $by_secret, $by_nonce, $by_token ) ) ) );
sort( $nopriv );
t( 'A2 only the job runner, cancel and client log are open to logged-out requests', $nopriv === array( 'isx_client_log', 'isx_job_cancel', 'isx_run' ), json_encode( $nopriv ) );
foreach ( $guarded as $action => $cap ) {
	$body = isx_method_body( $ADMIN, $actions[ $action ] );
	preg_match( "/self::guard\(\s*'(\w+)'/", $body, $g, PREG_OFFSET_CAPTURE );
	$first_input = min( array_map( function ( $needle ) use ( $body ) { $p = strpos( $body, $needle ); return $p === false ? PHP_INT_MAX : $p; }, array( '$_POST', '$_GET', '$_REQUEST', '$_FILES' ) ) );
	t( "A3 $action demands '$cap' before reading any input", isset( $g[1] ) && $g[1][0] === $cap && $g[0][1] < $first_input, isset( $g[1] ) ? $g[1][0] . ' @' . $g[0][1] . ' vs input @' . $first_input : 'no guard()' );
}
foreach ( $by_secret as $action ) {
	$body = isx_method_body( $ADMIN, $actions[ $action ] );
	t( "A4 $action compares the job secret in constant time", strpos( $body, "hash_equals( (string) \$job->get( 'secret' )" ) !== false );
}
t( 'A5 the client log needs a valid nonce', strpos( isx_method_body( $ADMIN, $actions['isx_client_log'] ), 'check_ajax_referer( self::NONCE' ) !== false );
$guard = isx_method_body( $ADMIN, 'guard' );
t( 'A6 guard() checks capability, file-mods and nonce, in that order', strpos( $guard, 'current_user_can' ) < strpos( $guard, 'DISALLOW_FILE_MODS' ) && strpos( $guard, 'DISALLOW_FILE_MODS' ) < strpos( $guard, 'check_ajax_referer' ) );
t( 'A7 the client log keeps only scalar, sanitized context', strpos( isx_method_body( $ADMIN, 'ajax_client_log' ), 'is_scalar( $value )' ) !== false && strpos( isx_method_body( $ADMIN, 'ajax_client_log' ), 'sanitize_text_field( (string) $value )' ) !== false );

// guard() itself, run for real.
function isx_guard( $cap, array $caps, $nonce_ok = true, $multisite = false, $mods_off = false ) {
	global $ADMIN_STUBS, $MODULE;
	return isx_sub( $ADMIN_STUBS . ( $mods_off ? 'define( "DISALLOW_FILE_MODS", true );' : '' ) . '
function is_multisite() { return ' . var_export( $multisite, true ) . '; }
function current_user_can( $c ) { return in_array( $c, ' . var_export( $caps, true ) . ', true ); }
function check_ajax_referer() { return ' . var_export( $nonce_ok, true ) . '; }
require ' . var_export( $MODULE . '/includes/class-isx-admin.php', true ) . ';
$m = new ReflectionMethod( "ISX_Admin", "guard" ); if ( PHP_VERSION_ID < 80100 ) $m->setAccessible( true );
try { $m->invoke( null, ' . var_export( $cap, true ) . ' ); echo "allowed"; } catch ( ISX_Reject $e ) { echo "rejected: " . $e->getMessage(); }' );
}
t( 'A8 a valid admin request passes', isx_guard( 'export', array( 'export' ) ) === 'allowed' );
$r = isx_guard( 'export', array( 'export' ), false );
t( 'A9 a forged or expired nonce is refused', strpos( $r, 'rejected' ) === 0 && stripos( $r, 'nonce' ) !== false, $r );
$r = isx_guard( 'import', array( 'export' ) );
t( 'A10 export rights do not open import', strpos( $r, 'rejected' ) === 0, $r );
$r = isx_guard( 'manage_options', array( 'export', 'import' ) );
t( 'A11 import rights do not open Reset Hub', strpos( $r, 'rejected' ) === 0, $r );
$r = isx_guard( 'manage_options', array( 'manage_options' ), true, false, true );
t( 'A12 DISALLOW_FILE_MODS also closes Reset Hub', strpos( $r, 'rejected' ) === 0 && strpos( $r, 'DISALLOW_FILE_MODS' ) !== false, $r );

// =====================================================================
group( 'B. Reset Hub asks for the account password first' );
// =====================================================================
$run = isx_method_body( $ADMIN, 'ajax_reset_run' );
t( 'B1 capability → password → tool, in that order', strpos( $run, "self::guard( 'manage_options' )" ) < strpos( $run, 'self::verify_reset_password()' ) && strpos( $run, 'self::verify_reset_password()' ) < strpos( $run, 'call_user_func' ) );
t( 'B2 only tools from the fixed dispatch table can run', strpos( $run, 'isset( self::$reset_dispatch[ $tool ] )' ) !== false );
function isx_reset_password( $given ) {
	global $ADMIN_STUBS, $MODULE;
	return isx_sub( $ADMIN_STUBS . '
function wp_get_current_user() { return new class { public $ID = 1; public $user_pass = "HASH"; function exists() { return true; } }; }
function wp_check_password( $p, $h, $id ) { return $p === "right-password" && $h === "HASH" && $id === 1; }
require ' . var_export( $MODULE . '/includes/class-isx-admin.php', true ) . ';
$_POST = ' . var_export( $given === null ? array() : array( 'password' => $given ), true ) . ';
$m = new ReflectionMethod( "ISX_Admin", "verify_reset_password" ); if ( PHP_VERSION_ID < 80100 ) $m->setAccessible( true );
try { $m->invoke( null ); echo "confirmed"; } catch ( ISX_Reject $e ) { echo "rejected: " . $e->getMessage(); }' );
}
t( 'B3 no password → refused', strpos( isx_reset_password( null ), 'rejected' ) === 0 );
t( 'B4 empty password → refused', strpos( isx_reset_password( '' ), 'rejected' ) === 0 );
$r = isx_reset_password( 'guess' );
t( 'B5 wrong password → refused', strpos( $r, 'rejected' ) === 0 && strpos( $r, 'Incorrect' ) !== false, $r );
t( 'B6 the account\'s own password → confirmed', isx_reset_password( 'right-password' ) === 'confirmed' );

// =====================================================================
group( 'C. One-time password reveal after a reset' );
// =====================================================================
function isx_reveal( array $calls, $is_admin = true ) {
	global $ADMIN_STUBS, $MODULE;
	return isx_sub( $ADMIN_STUBS . '
class ISX_Crypto { public static function decrypt_string( $s ) { return $s === "ENC" ? "new-admin-password" : ""; } }
function is_multisite() { return false; }
function current_user_can( $c ) { return ' . var_export( $is_admin, true ) . ' && $c === "manage_options"; }
$GLOBALS["t"] = [ "isx_reset_pw_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA" => "ENC" ];
function get_transient( $k ) { return $GLOBALS["t"][ $k ] ?? false; }
function delete_transient( $k ) { unset( $GLOBALS["t"][ $k ] ); }
require ' . var_export( $MODULE . '/includes/class-isx-admin.php', true ) . ';
foreach ( ' . var_export( $calls, true ) . ' as $token ) {
	$_POST = [ "token" => $token ];
	try { ISX_Admin::ajax_reset_password_reveal(); } catch ( ISX_Reject $e ) { if ( $e->getMessage() !== "__ok__" ) echo "rejected: " . $e->getMessage(); }
	echo "\n";
}' );
}
$good = str_repeat( 'A', 32 );
$out  = explode( "\n", isx_reveal( array( $good, $good ) ) );
t( 'C1 the right token reveals the new password once', strpos( $out[0], 'new-admin-password' ) !== false, $out[0] );
t( 'C2 …and never again (deleted on read)', strpos( $out[1] ?? '', 'rejected' ) === 0, $out[1] ?? '' );
$r = isx_reveal( array( $good ), false );
t( 'C3 a non-administrator cannot use a token', strpos( $r, 'rejected' ) === 0 && strpos( $r, 'new-admin-password' ) === false, $r );
foreach ( array( 'short', str_repeat( 'A', 31 ) . '!', '../../etc/passwd', str_repeat( 'A', 33 ) ) as $bad ) {
	$r = isx_reveal( array( $bad ) );
	t( 'C4 malformed token refused: ' . json_encode( $bad ), strpos( $r, 'rejected' ) === 0 && strpos( $r, 'new-admin-password' ) === false, $r );
}
t( 'C5 the reveal never goes through the nonce (the reset rotated it), but checks capability first', strpos( isx_method_body( $ADMIN, 'ajax_reset_password_reveal' ), 'current_user_can( self::cap( \'manage_options\' ) )' ) < strpos( isx_method_body( $ADMIN, 'ajax_reset_password_reveal' ), 'get_transient' ) );

// =====================================================================
group( 'D. Background jobs authenticate with a long per-job secret' );
// =====================================================================
$job_src = file_get_contents( $MODULE . '/includes/class-isx-job.php' );
t( 'D1 each job gets a 32-character random secret', strpos( $job_src, "'secret'   => wp_generate_password( 32, false, false )" ) !== false );
t( 'D2 the runner refuses a job it cannot find before touching anything', strpos( isx_method_body( $ADMIN, 'ajax_run' ), 'if ( ! $job )' ) < strpos( isx_method_body( $ADMIN, 'ajax_run' ), 'hash_equals' ) );
t( 'D3 a missing-job flood is logged at most once a minute', strpos( isx_method_body( $ADMIN, 'ajax_run' ), "set_transient( 'isx_job_missing_logged', 1, MINUTE_IN_SECONDS )" ) !== false );

// =====================================================================
group( 'E. Storage folder: locked down, never inside a folder WordPress replaces' );
// =====================================================================
$out = isx_sub( '
$base = sys_get_temp_dir() . "/isxsec_wp_" . getmypid();
define( "ABSPATH", $base . "/" );
define( "WP_CONTENT_DIR", $base . "/wp-content" );
define( "WP_PLUGIN_DIR", WP_CONTENT_DIR . "/plugins" );
define( "ISX_PLUGIN_ROOT", WP_PLUGIN_DIR . "/insightx-allhub" );
function untrailingslashit( $s ) { return rtrim( $s, "/\\\\" ); }
require ' . var_export( $MODULE . '/includes/functions-isx-storage.php', true ) . ';
$wc = WP_CONTENT_DIR; $r = [];
foreach ( [ ISX_PLUGIN_ROOT, ISX_PLUGIN_ROOT . "/storage", ISX_PLUGIN_ROOT . "/modules/backup/storage", "$wc/plugins/insightx-backup/storage", "$wc/themes/flatsome/backups", "$wc/mu-plugins/b", "$wc/plugins/../plugins/x", "$wc/insightx-backup", "$wc/uploads/backups", "/srv/backups" ] as $p ) $r[ str_replace( $base, "", $p ) ] = isx_storage_path_conflict( $p ) !== "";
echo json_encode( $r );' );
$r = json_decode( $out, true );
t( 'E1 InsightX AllHub\'s own folder (and anything in it) is refused', is_array( $r ) && $r['/wp-content/plugins/insightx-allhub'] && $r['/wp-content/plugins/insightx-allhub/storage'] && $r['/wp-content/plugins/insightx-allhub/modules/backup/storage'], $out );
t( 'E2 inside any plugin, theme or mu-plugin folder is refused (replaced on update)', is_array( $r ) && $r['/wp-content/plugins/insightx-backup/storage'] && $r['/wp-content/themes/flatsome/backups'] && $r['/wp-content/mu-plugins/b'], $out );
t( 'E3 "../" cannot walk back into plugins', is_array( $r ) && $r['/wp-content/plugins/../plugins/x'], $out );
t( 'E4 a dedicated folder elsewhere is fine (the default, uploads, outside the site)', is_array( $r ) && ! $r['/wp-content/insightx-backup'] && ! $r['/wp-content/uploads/backups'] && ! $r['/srv/backups'], $out );
$out = isx_sub( '
$dir = sys_get_temp_dir() . "/isxsec_store_" . getmypid();
@mkdir( $dir, 0777, true );
define( "ABSPATH", "/" ); define( "ISX_STORAGE_PATH", $dir );
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function isx_htaccess_deny_all() { return "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"; }
' . preg_replace( '/^.*?(function isx_protect_storage\(\) \{.*?\n\}).*$/s', '$1', file_get_contents( $MODULE . '/backup.php' ) ) . '
file_put_contents( "$dir/.htaccess", "Deny from all" ); // the old Apache 2.2-only rule
isx_protect_storage();
$h = file_get_contents( "$dir/.htaccess" );
file_put_contents( "$dir/.htaccess", "# custom rules" );
isx_protect_storage();
echo json_encode( [ "deny24" => strpos( $h, "Require all denied" ) !== false, "deny22" => strpos( $h, "Deny from all" ) !== false, "index" => is_file( "$dir/index.php" ), "custom_kept" => file_get_contents( "$dir/.htaccess" ) === "# custom rules" ] );
exec( "rm -rf " . escapeshellarg( $dir ) );' );
$r = json_decode( preg_replace( '/^.*?(\{.*\})\s*$/s', '$1', $out ), true );
t( 'E5 the storage root denies web access on Apache 2.4 and 2.2', is_array( $r ) && $r['deny24'] && $r['deny22'], $out );
t( 'E6 …and has an index.php (no directory listing)', is_array( $r ) && $r['index'], $out );
t( 'E7 the old one-line rule is upgraded, an admin\'s own rules are left alone', is_array( $r ) && $r['custom_kept'], $out );

// =====================================================================
group( 'F. An import never overwrites or deletes InsightX AllHub itself' );
// =====================================================================
$out = isx_sub( '
$base = sys_get_temp_dir() . "/isxsec_imp_" . getmypid();
define( "ABSPATH", $base . "/" );
define( "WP_CONTENT_DIR", $base . "/wp-content" );
define( "ISX_PLUGIN_ROOT", WP_CONTENT_DIR . "/plugins/insightx-allhub" );
define( "ISX_PATH", ISX_PLUGIN_ROOT . "/modules/backup/" );
define( "ISX_STORAGE_PATH", WP_CONTENT_DIR . "/insightx-backup" );
function untrailingslashit( $s ) { return rtrim( $s, "/\\\\" ); }
function trailingslashit( $s ) { return untrailingslashit( $s ) . "/"; }
function __( $t ) { return $t; }
require ' . var_export( $MODULE . '/includes/class-isx-files.php', true ) . ';
$p = new ReflectionMethod( "ISX_Files", "permanent_protected" ); $e = new ReflectionMethod( "ISX_Files", "is_excluded" );
if ( PHP_VERSION_ID < 80100 ) { $p->setAccessible( true ); $e->setAccessible( true ); }
$prot = $p->invoke( null ); $wc = WP_CONTENT_DIR; $r = [ "root_listed" => in_array( ISX_PLUGIN_ROOT, $prot, true ) ];
foreach ( [ "$wc/plugins/insightx-allhub/insightx-allhub.php", "$wc/plugins/insightx-allhub/includes/class-isxm-tools.php", "$wc/plugins/insightx-allhub/modules/backup/includes/class-isx-import.php", "$wc/insightx-backup/backups/x.wpress", "$wc/plugins/akismet/akismet.php", "$wc/plugins/insightx-allhub-evil/x.php", "$wc/uploads/2024/a.jpg" ] as $f ) $r[ str_replace( $wc, "", $f ) ] = $e->invoke( null, $f, $prot );
echo json_encode( $r );' );
$r = json_decode( $out, true );
t( 'F1 the whole InsightX AllHub folder is a protected path', is_array( $r ) && $r['root_listed'], $out );
t( 'F2 its main file, its own code and the Backup module are never restored over or cleaned', is_array( $r ) && $r['/plugins/insightx-allhub/insightx-allhub.php'] && $r['/plugins/insightx-allhub/includes/class-isxm-tools.php'] && $r['/plugins/insightx-allhub/modules/backup/includes/class-isx-import.php'], $out );
t( 'F3 the backups themselves are protected too', is_array( $r ) && $r['/insightx-backup/backups/x.wpress'], $out );
t( 'F4 other plugins and uploads are restored normally', is_array( $r ) && ! $r['/plugins/akismet/akismet.php'] && ! $r['/uploads/2024/a.jpg'], $out );
t( 'F5 a look-alike folder (insightx-allhub-evil) is not treated as ours', is_array( $r ) && ! $r['/plugins/insightx-allhub-evil/x.php'], $out );
$imp = file_get_contents( $MODULE . '/includes/class-isx-import.php' );
t( 'F6 after an import, InsightX AllHub is put back into active_plugins if it went missing', strpos( $imp, '$self   = plugin_basename( ISX_FILE );' ) !== false && strpos( $imp, '$active[] = $self;' ) !== false );
$reset = file_get_contents( $MODULE . '/includes/class-isx-reset.php' );
t( 'F7 Reset Hub never deletes InsightX AllHub (it is left out of "remove all plugins")', strpos( $reset, 'array_diff( array_keys( get_plugins() ), array( $self ) )' ) !== false );

// =====================================================================
group( 'G. Menu, hidden screens and tabs follow each screen\'s capability' );
// =====================================================================
function isx_menu( array $caps, $multisite = false ) {
	global $ADMIN_STUBS, $MODULE;
	return isx_sub( $ADMIN_STUBS . '
define( "ISXM_PLUGIN_DIR", "/x/" ); define( "ISX_STORAGE_PATH", "/site/wp-content/insightx-backup" );
function is_multisite() { return ' . var_export( $multisite, true ) . '; }
function current_user_can( $c ) { return in_array( $c, ' . var_export( $caps, true ) . ', true ); }
function get_option( $k, $d = false ) { return $d; }
class ISX_Backups { public static function all() { return [ 1, 2 ]; } }
class ISX_Destinations { public static function providers() { return []; } }
$GLOBALS["pages"] = [];
function add_submenu_page( $parent, $title, $menu, $cap, $slug ) { $GLOBALS["pages"][ $slug ] = [ $parent, $cap ]; }
function remove_submenu_page( $p, $s ) { $GLOBALS["removed"][] = $s; }
require ' . var_export( $MODULE . '/includes/class-isx-admin.php', true ) . ';
ISX_Admin::menu();
$_GET = [ "page" => "isx_backups" ];
ob_start(); ISX_Admin::tabs(); $h = ob_get_clean();
preg_match_all( "/page=(isx_[a-z_]+)/", $h, $m );
echo json_encode( [ "pages" => $GLOBALS["pages"], "removed" => $GLOBALS["removed"] ?? [], "tabs" => $m[1], "count_badge" => strpos( $h, "isx-tab-count\">2<" ) !== false, "parent" => ISX_Admin::parent_file( "x" ), "sub" => ISX_Admin::submenu_file( "x" ) ] );' );
}
$r = json_decode( isx_menu( array( 'export', 'import', 'manage_options' ) ), true );
t( 'G1 one menu item under InsightX AllHub (Export); the rest are hidden screens', is_array( $r ) && $r['pages']['isx_export'][0] === 'insightx-allhub' && count( array_filter( $r['pages'], function ( $p ) { return $p[0] === 'options.php'; } ) ) === 6, json_encode( $r ) );
t( 'G2 hidden screens are not removed after registering (that made WordPress refuse them)', is_array( $r ) && $r['removed'] === array() );
t( 'G3 Reset Hub needs manage_options; import needs import; the rest export', is_array( $r ) && $r['pages']['isx_reset_hub'][1] === 'manage_options' && $r['pages']['isx_import'][1] === 'import' && $r['pages']['isx_backups'][1] === 'export' );
t( 'G4 an administrator sees all seven tabs, with the backup count', is_array( $r ) && count( $r['tabs'] ) === 7 && $r['count_badge'], json_encode( $r['tabs'] ?? null ) );
t( 'G5 every Backup tab keeps InsightX AllHub → Backup highlighted', is_array( $r ) && $r['parent'] === 'insightx-allhub' && $r['sub'] === 'isx_export' );
$r = json_decode( isx_menu( array( 'export' ) ), true );
t( 'G6 an export-only user sees no Import or Reset Hub tab', is_array( $r ) && ! in_array( 'isx_reset_hub', $r['tabs'], true ) && ! in_array( 'isx_import', $r['tabs'], true ) && in_array( 'isx_export', $r['tabs'], true ), json_encode( $r['tabs'] ?? null ) );
$r = json_decode( isx_menu( array( 'export', 'import', 'manage_options', 'manage_network_options' ), true ), true );
t( 'G7 multisite: every screen needs the network capability', is_array( $r ) && count( array_unique( array_column( $r['pages'], 1 ) ) ) === 1 && $r['pages']['isx_export'][1] === 'manage_network_options' );

// =====================================================================
group( 'H. Backup downloads only ever come from the backups folder' );
// =====================================================================
$out = isx_sub( '
$dir = sys_get_temp_dir() . "/isxsec_dl_" . getmypid();
@mkdir( "$dir/backups", 0777, true );
define( "ABSPATH", "/" ); define( "ISX_STORAGE_PATH", $dir );
file_put_contents( "$dir/secret.wpress", "outside" );
file_put_contents( "$dir/backups/shop-01012026-AbCdEfGhIjKlMnOp.wpress", "pkg" );
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function isx_htaccess_deny_all() { return "x"; }
function __( $t ) { return $t; }
require ' . var_export( $MODULE . '/includes/class-isx-backups.php', true ) . ';
$r = [];
foreach ( [ "../secret.wpress", "..\\\\secret.wpress", "/etc/passwd", "%2e%2e/secret.wpress", "shop-01012026-AbCdEfGhIjKlMnOp.wpress", "x.wpress.php", "x.php", "" ] as $n ) {
	$p = ISX_Backups::path( $n );
	$r[ $n ] = $p === null ? null : ( strpos( realpath( $p ), realpath( "$dir/backups" ) . "/" ) === 0 ? "inside" : "OUTSIDE" );
}
echo json_encode( $r );
exec( "rm -rf " . escapeshellarg( $dir ) );' );
$r = json_decode( preg_replace( '/^.*?(\{.*\})\s*$/s', '$1', $out ), true );
t( 'H1 "../" never reaches a file outside the backups folder', is_array( $r ) && $r['../secret.wpress'] !== 'OUTSIDE' && $r['..\\secret.wpress'] !== 'OUTSIDE', $out );
t( 'H2 absolute and encoded paths resolve to nothing', is_array( $r ) && $r['/etc/passwd'] === null && $r['%2e%2e/secret.wpress'] === null, $out );
t( 'H3 only *.wpress names (no script files, no empty name)', is_array( $r ) && $r['x.wpress.php'] === null && $r['x.php'] === null && $r[''] === null, $out );
t( 'H4 a real backup resolves inside the backups folder', is_array( $r ) && $r['shop-01012026-AbCdEfGhIjKlMnOp.wpress'] === 'inside', $out );
$dl = isx_method_body( $ADMIN, 'ajax_download' );
t( 'H5 download is a guarded GET and serves only what ISX_Backups::path() resolved', false !== ( $gp = strpos( $dl, "self::guard( 'export', true )" ) ) && false !== ( $pp = strpos( $dl, 'ISX_Backups::path( $name )' ) ) && $gp < $pp && strpos( $dl, 'if ( $path === null )' ) !== false );
t( 'H6 the served file name is the basename only (no header injection via path)', strpos( $dl, '$filename = basename( $path );' ) !== false );

echo "\n========================================\nTOTAL: $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
