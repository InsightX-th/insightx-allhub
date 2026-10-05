<?php
/** php tests/performance.php
 * Work per request on the hot paths: page render, upload, delete. Each
 * check counts queries or requests, so a regression back to per-image or
 * per-file round trips fails here instead of on a production site.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/migrate-support.php';

$o = new ISXM_Offload();
function reset_url_cache() {
    $p = new ReflectionProperty( 'ISXM_Offload', 'url_post_cache' );
    if ( PHP_VERSION_ID < 80100 ) $p->setAccessible( true );
    $p->setValue( null, [] );
}

group( 'F3 rendering a post with 20 images' );
$GLOBALS['wpdb'] = new ISXM_Migration_Test_DB(); $db = $GLOBALS['wpdb'];
isxm_test_configure( [], [ 'deliver_enabled' => true, 'use_prefix' => false ] );
isxm_items_table( $db );
update_option( ISXM_Items::BACKFILLED_OPTION, '1' );
$html = '';
for ( $id = 1; $id <= 20; $id++ ) {
    $db->db->exec( "INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES ($id,'_wp_attached_file','2025/10/p$id.jpg')" );
    ISXM_Offload::set_record( $id, [ 'bucket' => 'media', 'endpoint' => 'https://s3.example.test', 'base_key' => '2025/10/', 'files' => [ "p$id.jpg", "p$id-300x200.jpg" ] ] );
    $html .= "<img src=\"https://example.com/wp-content/uploads/2025/10/p$id-300x200.jpg\" srcset=\"https://example.com/wp-content/uploads/2025/10/p$id.jpg 1200w\">";
}
ISXM_Items::flush_cache(); reset_url_cache();
$GLOBALS['isxm_t']['url_ids'] = []; // attachment_url_to_postid() must not be needed
ISXM_Items::prime( range( 1, 20 ) ); // what the update_post_metadata_cache hook does for a WP_Query
$q0 = $db->queries;
$out = $o->rewrite_content_urls( $html );
$render_queries = $db->queries - $q0;
t( 'Every image rewritten', substr_count( $out, 'https://s3.example.test/' ) === 40 && strpos( $out, 'example.com/wp-content' ) === false, $out );
t( 'One lookup query for all 40 URLs, not one per image', $render_queries === 1, (string) $render_queries );
reset_url_cache();
$q0 = $db->queries;
$o->rewrite_content_urls( $html );
t( 'Next request answers from the object cache: 0 queries', $db->queries - $q0 === 0, (string) ( $db->queries - $q0 ) );
ISXM_Items::flush_cache();
$q0 = $db->queries;
ISXM_Items::prime( range( 1, 20 ) );
for ( $id = 1; $id <= 20; $id++ ) ISXM_Offload::get_record( $id );
t( 'Primed ledger: 20 records in 1 query', $db->queries - $q0 === 1, (string) ( $db->queries - $q0 ) );

group( 'F1 a new upload does not scan the whole database' );
$GLOBALS['wpdb'] = new ISXM_Test_WPDB(); // records every SELECT in isxm_t['sql']
list( $port, $log ) = isxm_start_fake_s3();
isxm_test_configure( [ 'endpoint' => "http://127.0.0.1:$port" ], [ 'offload_enabled' => true, 'persist_urls' => true, 'use_prefix' => false, 'use_object_version' => false ] );
$dir = ISXM_TEST_UPLOADS . '/2025/10'; @mkdir( $dir, 0777, true );
file_put_contents( "$dir/new.jpg", 'new' );
isxm_test_add_post( 40, [] );
update_post_meta( 40, '_wp_attached_file', '2025/10/new.jpg' );
$GLOBALS['isxm_t']['sql'] = [];
$o->offload_on_generate( [ 'file' => '2025/10/new.jpg' ], 40, 'create' );
$likes = count( array_filter( $GLOBALS['isxm_t']['sql'], function ( $q ) { return stripos( $q, ' LIKE ' ) !== false; } ) );
t( 'First upload: offloaded with no LIKE table scans', is_array( ISXM_Offload::get_record( 40 ) ) && $likes === 0, $likes . ' scans' );
$GLOBALS['isxm_t']['sql'] = [];
$o->offload_on_generate( [ 'file' => '2025/10/new.jpg' ], 40, 'update' );
$likes = count( array_filter( $GLOBALS['isxm_t']['sql'], function ( $q ) { return stripos( $q, ' LIKE ' ) !== false; } ) );
t( 'Regenerating an existing image keeps the full rewrite', $likes > 0 );

group( 'F4 sizes upload in parallel after the original' );
$port = 19500 + getmypid() % 400;
$proc = proc_open( [ 'python3', __DIR__ . '/parallel-s3.py', (string) $port ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes );
register_shutdown_function( function () use ( $proc ) { proc_terminate( $proc ); } );
for ( $i = 0; $i < 50 && ! @fsockopen( '127.0.0.1', $port ); $i++ ) usleep( 100000 );
isxm_test_configure( [ 'endpoint' => "http://127.0.0.1:$port" ], [ 'persist_urls' => false, 'use_prefix' => false, 'use_object_version' => false ] );
$sizes = [];
file_put_contents( "$dir/big.jpg", str_repeat( 'x', 5000 ) );
for ( $v = 1; $v <= 8; $v++ ) { file_put_contents( "$dir/big-{$v}00x100.jpg", str_repeat( 'y', 5000 ) ); $sizes[ "s$v" ] = [ 'file' => "big-{$v}00x100.jpg" ]; }
file_put_contents( "$dir/big-fail.jpg", 'z' ); $sizes['f'] = [ 'file' => 'big-fail.jpg' ];
isxm_test_add_post( 41, [] );
update_post_meta( 41, '_wp_attached_file', '2025/10/big.jpg' );
$started = microtime( true );
$r = $o->offload_attachment( 41, [ 'file' => '2025/10/big.jpg', 'sizes' => $sizes ] );
$seconds = microtime( true ) - $started;
$stats = json_decode( file_get_contents( "http://127.0.0.1:$port/__stats" ), true );
$record = ISXM_Offload::get_record( 41 );
t( 'Offload succeeds; a failed size is recorded, not fatal', $r === true && in_array( 'big-fail.jpg', $record['missing'] ?? [], true ) && count( $record['files'] ) === 9, is_wp_error( $r ) ? $r->get_error_message() : json_encode( $record ) );
t( 'Sizes ran concurrently (peak 4)', $stats['peak'] === 4, (string) $stats['peak'] );
t( 'Every request correctly signed', $stats['invalid'] === 0 );
t( '10 PUTs at 120 ms each finish well under serial time (1.2 s)', $seconds < 0.9, sprintf( '%.2fs', $seconds ) );
isxm_test_add_post( 42, [] );
update_post_meta( 42, '_wp_attached_file', '2025/10/fail-original.jpg' );
file_put_contents( "$dir/fail-original.jpg", 'o' );
$before = json_decode( file_get_contents( "http://127.0.0.1:$port/__stats" ), true )['calls'];
$r = $o->offload_attachment( 42, [ 'file' => '2025/10/fail-original.jpg', 'sizes' => $sizes ] );
$after = json_decode( file_get_contents( "http://127.0.0.1:$port/__stats" ), true )['calls'];
t( 'Failed original stops the item before any size is sent', is_wp_error( $r ) && count( $after ) - count( $before ) === 1 );

group( 'F7 deleting an attachment is one request, not one per file' );
isxm_test_configure( [], [] );
$calls = [];
$GLOBALS['isxm_t']['http_provider'] = function ( $url, $args ) use ( &$calls ) {
    $calls[] = $args['method'];
    return [ 'response' => [ 'code' => 200 ], 'body' => '<DeleteResult><Error><Key>gone.jpg</Key><Code>NoSuchKey</Code></Error><Error><Key>locked.jpg</Key><Code>AccessDenied</Code></Error></DeleteResult>' ];
};
$res = ( new ISXM_Client() )->delete_objects( [ 'a.jpg', 'b.jpg', 'gone.jpg', 'locked.jpg' ] );
t( 'Four keys -> one POST ?delete', $calls === [ 'POST' ] );
t( 'NoSuchKey counts as deleted, other errors are reported per key', $res['a.jpg'] === true && $res['gone.jpg'] === true && is_wp_error( $res['locked.jpg'] ) );
$calls = [];
$GLOBALS['isxm_t']['http_provider'] = function ( $url, $args ) use ( &$calls ) {
    $calls[] = $args['method'];
    return [ 'response' => [ 'code' => $args['method'] === 'POST' ? 501 : 204 ], 'body' => '' ];
};
$res = ( new ISXM_Client() )->delete_objects( [ 'a.jpg', 'b.jpg' ] );
t( 'Store without DeleteObjects falls back to single DELETEs', $calls === [ 'POST', 'DELETE', 'DELETE' ] && $res === [ 'a.jpg' => true, 'b.jpg' => true ], json_encode( $calls ) );

echo "\n{$GLOBALS['isxm_pass']} passed; {$GLOBALS['isxm_fail']} failed\n";
exit( $GLOBALS['isxm_fail'] ? 1 : 0 );
