<?php
/**
 * Minimal WordPress stand-in: the real ISXM_File_Manager class against a temp
 * folder, with only the WordPress functions it calls stubbed.
 * `php tests/fs.php` is the whole setup.
 */

error_reporting( E_ALL );

define( 'ABSPATH', sys_get_temp_dir() . '/isxfm_wp/' );

$GLOBALS['isxfm_filters'] = [];

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function add_filter( $hook, $cb ) { $GLOBALS['isxfm_filters'][ $hook ][] = $cb; }
function apply_filters( $hook, $value, ...$args ) {
    foreach ( $GLOBALS['isxfm_filters'][ $hook ] ?? [] as $cb ) $value = $cb( $value, ...$args );
    return $value;
}

require __DIR__ . '/../includes/class-isxm-file-manager.php';

$GLOBALS['isxfm_pass'] = 0;
$GLOBALS['isxfm_fail'] = 0;

function group( $title ) { echo "\n== $title ==\n"; }

function check( $label, $ok ) {
    if ( $ok ) { $GLOBALS['isxfm_pass']++; echo "  ok   $label\n"; }
    else { $GLOBALS['isxfm_fail']++; echo "  FAIL $label\n"; }
}

function code_of( $result ) { return is_wp_error( $result ) ? $result->get_error_code() : 'ok'; }

/** Fresh site tree: wp-config.php, index.php, wp-content/plugins/a/b.txt, download/Specification/ADAM AUDIO/. */
function fresh_root() {
    $root = ABSPATH;
    if ( is_dir( $root ) ) exec( 'rm -rf ' . escapeshellarg( $root ) );
    foreach ( [ 'wp-admin', 'wp-includes', 'wp-content/plugins/a', 'wp-content/themes', 'download/Specification/ADAM AUDIO' ] as $dir ) {
        mkdir( $root . $dir, 0777, true );
    }
    file_put_contents( $root . 'wp-config.php', '<?php' );
    file_put_contents( $root . 'index.php', '<?php' );
    file_put_contents( $root . 'wp-content/plugins/a/b.txt', 'b' );
    file_put_contents( $root . 'download/Specification/ADAM AUDIO/old.pdf', 'pdf' );
    $GLOBALS['isxfm_filters'] = [];
    return $root;
}

function finish() {
    printf( "\n%d passed; %d failed\n", $GLOBALS['isxfm_pass'], $GLOBALS['isxfm_fail'] );
    exit( $GLOBALS['isxfm_fail'] ? 1 : 0 );
}
