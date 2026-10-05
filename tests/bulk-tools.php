<?php
/** php tests/bulk-tools.php
 * The bulk tools exactly as the background runner drives them
 * (ISXM_Tools::execute_batch with the cursor it hands back): Offload,
 * Download, Remove — on a real SQL engine, uploading to a local fake S3.
 * Checks the order that keeps media safe (upload -> rewrite -> delete
 * local; restore -> delete remote -> rewrite back -> forget), replays
 * after a lost cursor, and the final state of every store.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/migrate-support.php';

list( $port, $log ) = isxm_start_fake_s3();
const ITEMS = 30;
$dir = ISXM_TEST_UPLOADS . '/2025/12';

function bulk_fixture() {
    global $port, $dir;
    $GLOBALS['wpdb'] = new ISXM_Migration_Test_DB(); $db = $GLOBALS['wpdb'];
    $db->db->exec( 'DROP TABLE wp_posts' );
    $db->db->exec( "CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_content TEXT, guid TEXT, post_type TEXT DEFAULT 'attachment', post_status TEXT DEFAULT 'inherit')" );
    isxm_test_configure( [ 'endpoint' => "http://127.0.0.1:$port" ], [ 'use_prefix' => false, 'use_year_month' => true, 'use_object_version' => false,
        'persist_urls' => true, 'remove_local' => true, 'deliver_enabled' => false ] );
    update_option( 'isxs_migration_schema', 1 );
    isxm_items_table( $db );
    update_option( ISXM_Items::BACKFILLED_OPTION, '1' );
    set_transient( ISXM_Tools::DESTINATION_PROBE_TRANSIENT, 'yes' );
    @mkdir( $dir, 0777, true );
    array_map( 'unlink', glob( "$dir/*" ) ?: [] );
    for ( $id = 1; $id <= ITEMS; $id++ ) {
        foreach ( [ "b$id.jpg", "b$id-300x200.jpg" ] as $f ) file_put_contents( "$dir/$f", "data:$f" );
        $url = "https://example.com/wp-content/uploads/2025/12/b$id.jpg";
        $db->db->exec( "INSERT INTO wp_posts(ID,guid) VALUES ($id," . $db->db->quote( $url ) . ')' );
        $db->db->exec( "INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES ($id,'_wp_attached_file','2025/12/b$id.jpg')" );
        isxm_test_add_post( $id, [] );
        update_post_meta( $id, '_wp_attached_file', "2025/12/b$id.jpg" );
        update_post_meta( $id, '_wp_attachment_metadata', [ 'file' => "2025/12/b$id.jpg", 'sizes' => [ 'medium' => [ 'file' => "b$id-300x200.jpg" ] ] ] );
        $db->db->exec( 'INSERT INTO wp_posts(ID,post_type,post_status,post_content) VALUES (' . ( 1000 + $id ) . ",'post','publish'," . $db->db->quote( "<img src=\"https://example.com/wp-content/uploads/2025/12/b$id-300x200.jpg\">" ) . ')' );
    }
    return $db;
}
/** Drive one tool like ISXM_Background::run() does; $each sees every cursor. */
function drive( $tool, $cursor, ?callable $each = null ) {
    ISXM_Job::start( $tool, ITEMS, $cursor );
    for ( $i = 0; $i < 100; $i++ ) {
        $r = ISXM_Tools::execute_batch( $tool, $cursor );
        if ( is_wp_error( $r ) ) return $r;
        if ( $each ) $each( $r );
        $cursor = $r['cursor'];
        if ( $r['done'] ) return $r;
    }
    throw new RuntimeException( "$tool did not settle" );
}
/** A heading that, unlike group(), keeps the state the previous section built. */
function section( $name ) { $GLOBALS['isxm_group'] = $name; echo "\n=== $name ===\n"; }
function local_count() { global $dir; return count( glob( "$dir/b*.jpg" ) ?: [] ); }
function puts() { global $log; return count( array_filter( is_file( $log ) ? file( $log ) : [], function ( $l ) { return json_decode( $l, true )['method'] === 'PUT'; } ) ); }
function content_has( $needle ) { return (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_posts WHERE post_type='post' AND post_content LIKE " . $GLOBALS['wpdb']->db->quote( "%$needle%" ) ); }

group( 'Offload: upload phase -> one rewrite -> local deletions' );
$db = bulk_fixture();
@unlink( $log );
$rewrite_started_with_files = null;
$r = drive( 'offload', 'upload|0', function ( $r ) use ( &$rewrite_started_with_files ) {
    if ( $rewrite_started_with_files === null && strpos( (string) $r['cursor'], 'rewrite|' ) === 0 ) $rewrite_started_with_files = local_count();
} );
t( 'Run finishes', ! is_wp_error( $r ) && $r['done'], is_wp_error( $r ) ? $r->get_error_message() : '' );
t( 'Every file uploaded exactly once', puts() === 2 * ITEMS, (string) puts() );
t( 'Local files all still present when the rewrite phase starts', $rewrite_started_with_files === 2 * ITEMS, (string) $rewrite_started_with_files );
t( 'Every post now points at the bucket', content_has( "127.0.0.1:$port/media/2025/12/" ) === ITEMS && content_has( 'example.com/wp-content' ) === 0 );
t( 'Every guid rewritten', (int) $db->get_var( "SELECT COUNT(*) FROM wp_posts WHERE post_type='attachment' AND guid LIKE '%127.0.0.1%'" ) === ITEMS );
t( 'Local copies removed only after the rewrite landed', local_count() === 0 );
t( 'Ledger holds every item', ISXM_Items::count_on_destination( 'media', "http://127.0.0.1:$port" ) === ITEMS );
@unlink( $log );
$r = drive( 'offload', 'upload|0' );
t( 'Replaying from a lost cursor re-uploads nothing', ! is_wp_error( $r ) && puts() === 0 );

section( 'Download: every file comes home' );
$GLOBALS['isxm_t']['http_provider'] = function ( $url, $args ) {
    if ( ! empty( $args['filename'] ) ) file_put_contents( $args['filename'], 'restored' );
    return [ 'response' => [ 'code' => 200 ], 'body' => 'restored' ];
};
$r = drive( 'download', 0 );
t( 'Download finishes with no errors', ! is_wp_error( $r ) && $r['done'] && empty( $r['errors'] ) );
t( 'All 60 files back on disk', local_count() === 2 * ITEMS, (string) local_count() );
t( 'Records untouched (still offloaded)', ISXM_Items::count_on_destination( 'media', "http://127.0.0.1:$port" ) === ITEMS );

section( 'Remove: restore -> delete remote -> rewrite back -> forget' );
$posts = 0;
$GLOBALS['isxm_t']['http_provider'] = function ( $url, $args ) use ( &$posts ) {
    if ( ( $args['method'] ?? '' ) === 'POST' ) $posts++;
    if ( ! empty( $args['filename'] ) ) file_put_contents( $args['filename'], 'restored' );
    return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
};
$r = drive( 'remove', 0 );
ISXM_Items::flush_cache();
t( 'Remove finishes with no errors', ! is_wp_error( $r ) && $r['done'] && empty( $r['errors'] ), json_encode( is_wp_error( $r ) ? $r->get_error_message() : $r['errors'] ) );
t( 'One batch delete per attachment', $posts === ITEMS, (string) $posts );
t( 'Posts point back at local files', content_has( 'example.com/wp-content/uploads/2025/12/' ) === ITEMS && content_has( '127.0.0.1' ) === 0 );
t( 'Records forgotten in both stores after the rewrite', ISXM_Items::count_on_destination( 'media', "http://127.0.0.1:$port" ) === 0 && ISXM_Offload::get_record( 1 ) === null );
t( 'Local files kept', local_count() === 2 * ITEMS );

group( 'Remove failure keeps everything' );
$db = bulk_fixture();
drive( 'offload', 'upload|0' );
$GLOBALS['isxm_t']['http_provider'] = function ( $url, $args ) {
    return [ 'response' => [ 'code' => ( $args['method'] ?? 'GET' ) === 'GET' ? 403 : 200 ], 'body' => '' ];
};
$r = drive( 'remove', 0 );
ISXM_Items::flush_cache();
t( 'Every item reports its failed restore', ! is_wp_error( $r ) && count( $r['errors'] ) === ITEMS );
t( 'No record forgotten, no URL rewritten back', ISXM_Items::count_on_destination( 'media', "http://127.0.0.1:$port" ) === ITEMS && content_has( '127.0.0.1' ) === ITEMS );

echo "\n{$GLOBALS['isxm_pass']} passed; {$GLOBALS['isxm_fail']} failed\n";
exit( $GLOBALS['isxm_fail'] ? 1 : 0 );
