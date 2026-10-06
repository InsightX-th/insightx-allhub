<?php
/** php tests/integrity.php
 * Data-integrity regressions: nothing local is deleted, and no record is
 * forgotten, unless the database durably agrees. Each group reproduces one
 * failure mode that used to lose or orphan media.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/migrate-support.php';

list( $port, $log ) = isxm_start_fake_s3();
$o = new ISXM_Offload();

/** Fresh SQLite DB + ledger, destination = fake S3, one attachment with two sizes on disk. */
function integrity_fixture( $id, array $settings = [] ) {
    global $port;
    $GLOBALS['wpdb'] = new ISXM_Migration_Test_DB();
    isxm_test_configure( [ 'endpoint' => "http://127.0.0.1:$port" ], array_merge( [ 'use_prefix' => false, 'use_year_month' => true,
        'use_object_version' => false, 'persist_urls' => false, 'remove_local' => true, 'deliver_enabled' => true ], $settings ) );
    update_option( 'isxs_migration_schema', 1 );
    isxm_items_table( $GLOBALS['wpdb'] );
    $dir = ISXM_TEST_UPLOADS . '/2025/09';
    @mkdir( $dir, 0777, true );
    foreach ( [ "i$id.jpg", "i$id-300x200.jpg" ] as $f ) file_put_contents( "$dir/$f", "data:$f" );
    isxm_test_add_post( $id, [] );
    update_post_meta( $id, '_wp_attached_file', "2025/09/i$id.jpg" );
    return [ 'file' => "2025/09/i$id.jpg", 'sizes' => [ 'medium' => [ 'file' => "i$id-300x200.jpg" ] ] ];
}
function ledger_rows( $id ) {
    return (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_isxs_items WHERE source_id = $id" );
}

group( 'D1 record write fails -> local files kept' );
$meta = integrity_fixture( 1 );
$GLOBALS['wpdb']->fail_query = '/^REPLACE INTO wp_isxs_items/';
$r = $o->offload_attachment( 1, $meta );
t( 'Offload reports the failed record write', is_wp_error( $r ) && $r->get_error_code() === 'isxs_record_write', is_wp_error( $r ) ? $r->get_error_code() : 'true' );
t( 'Local original and size are still on disk', is_file( ISXM_TEST_UPLOADS . '/2025/09/i1.jpg' ) && is_file( ISXM_TEST_UPLOADS . '/2025/09/i1-300x200.jpg' ) );
$GLOBALS['wpdb']->fail_query = '';
$r = $o->offload_attachment( 1, $meta );
t( 'Retry with a healthy DB succeeds and only then removes local files', $r === true && ! is_file( ISXM_TEST_UPLOADS . '/2025/09/i1.jpg' ) );

group( 'D2 ledger upsert is atomic and never stale' );
integrity_fixture( 2 );
$a = [ 'bucket' => 'media', 'endpoint' => 'e', 'base_key' => 'old/', 'files' => [ 'i2.jpg' ] ];
$b = [ 'bucket' => 'media', 'endpoint' => 'e', 'base_key' => 'new/', 'files' => [ 'i2.jpg' ] ];
t( 'First write succeeds', ISXM_Offload::set_record( 2, $a ) );
t( 'Second write replaces, never duplicates', ISXM_Offload::set_record( 2, $b ) && ledger_rows( 2 ) === 1 );
ISXM_Items::flush_cache();
t( 'Ledger holds the newest record', ISXM_Items::get( 2 )['base_key'] === 'new/' );
$GLOBALS['wpdb']->fail_query = '/^REPLACE INTO wp_isxs_items/';
t( 'A failed ledger write is reported', ! ISXM_Offload::set_record( 2, $a ) );
ISXM_Items::flush_cache();
t( 'A failed ledger write drops the stale row instead of letting it win', ledger_rows( 2 ) === 0 );
$GLOBALS['wpdb']->fail_query = '';

group( 'D3 one offload per attachment at a time' );
$meta = integrity_fixture( 3, [ 'remove_local' => false ] );
@unlink( $log );
update_option( 'isxs_lock_3', time() );
$r = $o->offload_attachment( 3, $meta );
t( 'Held lock -> isxs_busy without touching the bucket', is_wp_error( $r ) && $r->get_error_code() === 'isxs_busy' && ! is_file( $log ) );
t( 'Busy is not recorded as a failure', get_post_meta( 3, ISXM_Offload::ERROR_META_KEY, true ) === '' );
update_option( 'isxs_lock_3', time() - ISXM_Offload::LOCK_TTL - 1 );
t( 'Expired lock is taken over', $o->offload_attachment( 3, $meta ) === true );
t( 'Lock released after the offload', get_option( 'isxs_lock_3', null ) === null );

group( 'D4 deferred Remove keeps the record until the rewrite lands' );
integrity_fixture( 4 );
$record = [ 'bucket' => 'media', 'endpoint' => "http://127.0.0.1:$port", 'base_key' => '2025/09/', 'files' => [ 'i4.jpg', 'i4-300x200.jpg' ] ];
ISXM_Offload::set_record( 4, $record );
$pairs = $o->remove_remote_attachment( 4, true );
t( 'Deferred remove returns the reverse pairs', is_array( $pairs ) );
ISXM_Items::flush_cache();
t( 'Record survives until the caller confirms the rewrite', ISXM_Offload::get_record( 4 ) !== null );
ISXM_Offload::finish_removal( [ 4 ] );
ISXM_Items::flush_cache();
t( 'finish_removal forgets the item in both stores', ISXM_Offload::get_record( 4 ) === null && ledger_rows( 4 ) === 0 );
integrity_fixture( 5 );
ISXM_Offload::set_record( 5, array_merge( $record, [ 'files' => [ 'i5.jpg' ] ] ) );
t( 'Immediate remove still forgets the item', $o->remove_remote_attachment( 5 ) === true && ISXM_Offload::get_record( 5 ) === null );

group( 'D5 one-pass rewrite never loses or clobbers a write' );
integrity_fixture( 6 );
$db = $GLOBALS['wpdb'];
$old = 'https://example.com/wp-content/uploads/2025/09/i6.jpg'; $new = 'https://cdn.test/i6.jpg';
$db->db->exec( "INSERT INTO wp_posts(ID,post_content) VALUES (1," . $db->db->quote( "<img src=\"$old\">" ) . ")" );
$db->db->exec( "INSERT INTO wp_options(option_id,option_name,option_value) VALUES (1,'isxs_settings'," . $db->db->quote( $old ) . ")" );
$map = function ( $v ) use ( $old, $new ) { return strpos( $v, $old ) !== false ? [ $old => $new ] : []; };
$edited = false;
$racing = function ( $v ) use ( $map, $db, &$edited ) {
    if ( ! $edited ) { $edited = true; $db->db->exec( "UPDATE wp_posts SET post_content = post_content || ' edited' WHERE ID = 1" ); }
    return $map( $v );
};
$w = ISXM_DB_Rewriter::rewrite_window( 'wp_posts', 'ID', 'post_content', $racing, 0, 100, 'ID', 'posts' );
t( 'Concurrent edit is not overwritten; window stops before the row', ! is_wp_error( $w ) && ! $w['done'] && $w['last_id'] === 0 && strpos( $db->get_var( 'SELECT post_content FROM wp_posts WHERE ID=1' ), 'edited' ) !== false );
$w = ISXM_DB_Rewriter::rewrite_window( 'wp_posts', 'ID', 'post_content', $map, $w['last_id'], 100, 'ID', 'posts' );
$row = $db->get_var( 'SELECT post_content FROM wp_posts WHERE ID=1' );
t( 'Retry rewrites the fresh value and keeps the edit', $w['done'] && strpos( $row, $new ) !== false && strpos( $row, 'edited' ) !== false, $row );
$db->db->exec( "UPDATE wp_posts SET post_content = " . $db->db->quote( "<img src=\"$old\">" ) . " WHERE ID = 1" );
$db->fail_write = true;
$w = ISXM_DB_Rewriter::rewrite_window( 'wp_posts', 'ID', 'post_content', $map, 0, 100, 'ID', 'posts' );
t( 'Failed write is an error, so the caller keeps its cursor', is_wp_error( $w ) && $w->get_error_code() === 'isxs_rewrite_write' );
$db->fail_write = false;
$w = ISXM_DB_Rewriter::rewrite_window( 'wp_options', 'option_id', 'option_value', $map, 0, 100, 'option_name', 'options' );
t( 'Plugin-internal options are never rewritten', $w['done'] && $db->get_var( "SELECT option_value FROM wp_options WHERE option_name='isxs_settings'" ) === $old );
$w = ISXM_DB_Rewriter::rewrite_window( 'wp_posts', 'ID', 'post_content', $map, 0, 100, 'ID', 'posts', microtime( true ) - 1 );
t( 'Expired deadline stops before any row', ! $w['done'] && $w['processed'] === 0 && $w['last_id'] === 0 );

group( 'D6 size URLs of -scaled images resolve' );
integrity_fixture( 7 );
$base = 'https://example.com/wp-content/uploads/';
$GLOBALS['isxm_t']['url_ids'][ $base . '2025/09/big-scaled.jpg' ] = 7;
ISXM_Offload::set_record( 7, [ 'bucket' => 'media', 'endpoint' => "http://127.0.0.1:$port", 'base_key' => '2025/09/',
    'files' => [ 'big-scaled.jpg', 'big.jpg', 'big-300x200.jpg' ] ] );
$url = ISXM_Offload::resolve_local_url( $base . '2025/09/big-300x200.jpg' );
t( 'Size of a -scaled image maps to its remote URL', is_string( $url ) && strpos( $url, '/2025/09/big-300x200.jpg' ) !== false, (string) $url );
t( 'The unscaled original maps too', is_string( ISXM_Offload::resolve_local_url( $base . '2025/09/big.jpg' ) ) );
t( 'Unknown files still resolve to nothing', ISXM_Offload::resolve_local_url( $base . '2025/09/other-300x200.jpg' ) === null );

group( 'D7 Sync apply re-checks before forgetting an item' );
integrity_fixture( 8, [ 'remove_local' => false ] );
ISXM_Offload::set_record( 8, [ 'bucket' => 'media', 'endpoint' => "http://127.0.0.1:$port", 'base_key' => '2025/09/', 'files' => [ 'i8.jpg' ] ] );
$GLOBALS['isxm_t']['http_provider'] = function () { return migration_listing( [ '2025/09/i8.jpg' ] ); };
$res = ISXM_Sync::cleanup_stale( [ 8 ] );
ISXM_Items::flush_cache();
t( 'Item re-uploaded since the scan keeps its record', $res['cleaned'] === 0 && ISXM_Offload::get_record( 8 ) !== null );
$GLOBALS['isxm_t']['http_provider'] = function () { return migration_listing( [] ); };
$res = ISXM_Sync::cleanup_stale( [ 8 ] );
ISXM_Items::flush_cache();
t( 'Item really gone is cleaned', $res['cleaned'] === 1 && ISXM_Offload::get_record( 8 ) === null );

group( 'D8 Remove flags an item lost in both places' );
integrity_fixture( 9 );
@unlink( ISXM_TEST_UPLOADS . '/2025/09/i9.jpg' );
@unlink( ISXM_TEST_UPLOADS . '/2025/09/i9-300x200.jpg' );
ISXM_Offload::set_record( 9, [ 'bucket' => 'media', 'endpoint' => 'https://s3.example.test', 'base_key' => '2025/09/', 'files' => [ 'i9.jpg' ] ] );
$GLOBALS['isxm_t']['http_provider'] = function ( $url, $args ) { return [ 'response' => [ 'code' => ( $args['method'] ?? 'GET' ) === 'GET' ? 404 : 204 ], 'body' => '' ]; };
$o->remove_remote_attachment( 9 );
t( 'Data-loss flag set instead of silently forgetting', (int) get_post_meta( 9, ISXM_Offload::DATA_LOSS_META_KEY, true ) > 0 );

group( 'D10 a GUID longer than the column never stops a run' );
integrity_fixture( 11 );
$db = $GLOBALS['wpdb'];
$old = 'https://example.com/wp-content/uploads/2025/09/x.jpg';
$long = 'https://media.example.test/wp-content/uploads/2024/05/12345678/' . rawurlencode( 'ลำโพงห้องประชุมไร้สายรุ่นใหม่-1.webp' );
$db->db->exec( 'INSERT INTO wp_posts(ID,post_content,guid) VALUES (11,\'\',' . $db->db->quote( $old ) . ')' );
$r = ISXM_DB_Rewriter::update_attachment_guid( 11, [ [ 'old' => $old, 'new' => $long ] ] );
t( 'Over-long GUID (' . strlen( $long ) . ' chars) is skipped, not an error', $r === 0 && $db->get_var( 'SELECT guid FROM wp_posts WHERE ID=11' ) === $old );
$r = ISXM_DB_Rewriter::update_attachment_guid( 11, [ [ 'old' => $old, 'new' => 'https://media.example.test/x.jpg' ] ] );
t( 'A GUID that fits is still rewritten', $r === 1 && $db->get_var( 'SELECT guid FROM wp_posts WHERE ID=11' ) === 'https://media.example.test/x.jpg' );
$db->db->exec( "UPDATE wp_posts SET guid = " . $db->db->quote( $old ) . " WHERE ID = 11" );
$db->fail_guid = true;
$r = ISXM_DB_Rewriter::update_attachment_guid( 11, [ [ 'old' => $old, 'new' => 'https://media.example.test/x.jpg' ] ] );
t( 'A real write failure is reported with the database reason', is_wp_error( $r ) && strpos( $r->get_error_message(), 'injected write failure' ) !== false, is_wp_error( $r ) ? $r->get_error_message() : 'no error' );
$db->fail_guid = false;

group( 'D11 duplicate attachments share the objects of one file' );
integrity_fixture( 12 ); // #12 owns 2025/09/i12.jpg
$db = $GLOBALS['wpdb'];
$db->db->exec( "INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES (12,'_wp_attached_file','2025/09/i12.jpg'),(13,'_wp_attached_file','2025/09/i12.jpg')" );
isxm_test_add_post( 13, [] ); update_post_meta( 13, '_wp_attached_file', '2025/09/i12.jpg' );
$twin = [ 'bucket' => 'media', 'endpoint' => "http://127.0.0.1:$port", 'base_key' => '2025/09/', 'files' => [ 'i12.jpg', 'i12-300x200.jpg' ] ];
ISXM_Offload::set_record( 12, $twin );
@unlink( ISXM_TEST_UPLOADS . '/2025/09/i12.jpg' ); @unlink( ISXM_TEST_UPLOADS . '/2025/09/i12-300x200.jpg' );
@unlink( $log );
$r = $o->offload_attachment( 13, null );
ISXM_Items::flush_cache();
t( 'The duplicate adopts its twin\'s record instead of failing', $r === true && ( ISXM_Offload::get_record( 13 )['files'] ?? [] ) === $twin['files'], is_wp_error( $r ) ? $r->get_error_message() : '' );
t( 'Nothing is uploaded for it', ! is_file( $log ) );
$deletes = [];
$GLOBALS['isxm_t']['http_provider'] = function ( $url, $args ) use ( &$deletes ) {
    if ( in_array( $args['method'] ?? '', [ 'DELETE', 'POST' ], true ) ) $deletes[] = $args['method'] . ' ' . $url . ' ' . ( $args['body'] ?? '' );
    return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
};
$GLOBALS['isxm_t']['options']['isxs_connections']['custom']['endpoint'] = 'https://s3.example.test'; ISXM_Settings::flush_cache();
$shared = [ 'bucket' => 'media', 'endpoint' => 'https://s3.example.test', 'base_key' => '2025/09/', 'files' => [ 'i12.jpg', 'i12-300x200.jpg' ] ];
ISXM_Offload::set_record( 12, $shared ); ISXM_Offload::set_record( 13, $shared );
$o->delete_remote_on_delete( 13 );
t( 'Deleting one duplicate keeps the objects the other still uses', $deletes === [], json_encode( $deletes ) );
$o->delete_remote_on_delete( 12 );
t( 'Deleting the last user removes them', count( $deletes ) === 1 && strpos( $deletes[0], 'i12-300x200.jpg' ) !== false, json_encode( $deletes ) );
integrity_fixture( 12 );
ISXM_Offload::set_record( 12, $shared ); ISXM_Offload::set_record( 13, $shared );
$GLOBALS['isxm_t']['options']['isxs_connections']['custom']['endpoint'] = 'https://s3.example.test'; ISXM_Settings::flush_cache();
$deletes = [];
$GLOBALS['isxm_t']['http_provider'] = function ( $url, $args ) use ( &$deletes ) {
    if ( in_array( $args['method'] ?? '', [ 'DELETE', 'POST' ], true ) ) $deletes[] = $args['method'];
    if ( ! empty( $args['filename'] ) ) file_put_contents( $args['filename'], 'x' );
    return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
};
$r = $o->remove_remote_attachment( 13 );
t( 'Remove from Bucket on a duplicate restores it but leaves the shared objects', $r === true && $deletes === [] && ISXM_Offload::get_record( 12 ) !== null, json_encode( [ $r, $deletes ] ) );
integrity_fixture( 14 );
@unlink( ISXM_TEST_UPLOADS . '/2025/09/i14.jpg' );
t( 'No twin on the destination: still "missing local"', ( function () use ( $o ) { $r = $o->offload_attachment( 14, null ); return is_wp_error( $r ) && $r->get_error_code() === 'isxs_missing_local'; } )() );

group( 'D9 replayed Sync pages are not double counted' );
$run = 'integrity' . getmypid();
ISXM_Sync::cleanup_run_files( $run );
$pairs = [ [ 'k/a.jpg', 7 ], [ 'k/a-1.jpg', 7 ] ];
ISXM_Sync::append_expected( $run, $pairs );
ISXM_Sync::append_expected( $run, $pairs ); // page replayed after a crash
$e = ISXM_Sync::load_expected( $run );
t( 'Each expected key counted once', $e['counts'] === [ 7 => 2 ] );
ISXM_Sync::cleanup_run_files( $run );

echo "\n{$GLOBALS['isxm_pass']} passed; {$GLOBALS['isxm_fail']} failed\n";
exit( $GLOBALS['isxm_fail'] ? 1 : 0 );
