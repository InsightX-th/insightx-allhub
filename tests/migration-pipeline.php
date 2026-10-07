<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/migrate-support.php';
function pipeline_step( $budget = 15 ) { return ISXM_Migrate_Queue::step( ISXM_Job::get( 'migrate' ), $budget ); }
function pipeline_drain() {
    for ( $i = 0; $i < 200; $i++ ) {
        $r = pipeline_step();
        if ( is_wp_error( $r ) ) throw new RuntimeException( $r->get_error_message() );
        if ( $r['done'] ) return;
    }
    throw new RuntimeException( 'Pipeline did not settle' );
}
group( 'One final migration rewrite' );
$job = migration_fixture( 3 ); $db = $GLOBALS['wpdb'];
$old = 'https://example.com/wp-content/uploads/m1.jpg';
$db->db->exec( 'INSERT INTO wp_posts VALUES(1,' . $db->db->quote( serialize( [ 'image' => $old ] ) ) . ',' . $db->db->quote( $old ) . ')' );
$local = ISXM_TEST_UPLOADS . '/m1.jpg'; file_put_contents( $local, 'existing local' );
pipeline_step();
t( 'Index built before source traversal', ISXM_Job::get( 'migrate' )->migration['stage'] === 'transfer' );
pipeline_step();
t( 'All object units committed before final rewrite', ISXM_Job::get( 'migrate' )->processed === 3 && ISXM_Job::get( 'migrate' )->migration['stage'] === 'rewrite' );
t( 'Local media retained before final rewrite', is_file( $local ) );
t( 'Content is unchanged during transfer', strpos( $db->get_var( 'SELECT post_content FROM wp_posts WHERE ID=1' ), $old ) !== false );
$db->fail_write = true; $r = pipeline_step();
t( 'Rewrite failure retains cursor and local media', is_wp_error( $r ) && is_file( $local ) && ISXM_Job::get( 'migrate' )->migration['rewrite']['after'] === 0 );
$db->fail_write = false;
pipeline_drain();
t( 'Final pass rewrites serialized content and GUID', strpos( $db->get_var( 'SELECT post_content FROM wp_posts WHERE ID=1' ), 's3.example.test' ) !== false && strpos( $db->get_var( 'SELECT guid FROM wp_posts WHERE ID=1' ), 's3.example.test' ) !== false );
t( 'Cleanup removes original local only after rewrite', ! is_file( $local ) );
pipeline_step(); t( 'Completed checkpoint cannot recount', ISXM_Job::get( 'migrate' )->processed === 3 );

group( 'Index reads only attached-file meta' );
migration_fixture( 0 ); $db = $GLOBALS['wpdb'];
for ( $i = 1; $i <= 5; $i++ ) $db->db->exec( "INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES ($i,'_wp_attached_file','old$i.jpg'),($i,'_other','x'),($i,'_wp_attached_file','f$i.jpg')" );
$after = 0; $windows = 0;
do { $w = ISXM_Migration_Store::index_window( $after, 3 ); ISXM_Migration_Store::index_paths( $w['rows'] ); $after = $w['after']; $windows++; } while ( ! $w['done'] );
t( 'Other meta keys are skipped', $windows === 4 );
t( 'Every attachment indexed with its latest path', ISXM_Migration_Store::resolve( [ 'f1.jpg', 'f5.jpg', 'old1.jpg' ] ) === [ 'f1.jpg' => 1, 'f5.jpg' => 5 ] );

group( 'A changed mapping replaces the old one instead of stopping the run' );
migration_fixture( 0 ); $db = $GLOBALS['wpdb'];
$old = 'https://example.com/wp-content/uploads/2025/01/x.jpg';
ISXM_Migration_Store::put( 'conflict', 'url', $old, [ 'old' => $old, 'new' => 'https://cdn.test/11111111/x.jpg' ] );
$id = $db->get_var( "SELECT id FROM wp_isxs_migration_work WHERE run_id='conflict'" );
$thrown = null;
try { ISXM_Migration_Store::put( 'conflict', 'url', $old, [ 'old' => $old, 'new' => 'https://cdn.test/22222222/x.jpg' ] ); } catch ( RuntimeException $e ) { $thrown = $e->getMessage(); }
t( 'No exception on a changed mapping', $thrown === null, (string) $thrown );
t( 'Newest mapping stored, row id kept', $db->get_var( "SELECT id FROM wp_isxs_migration_work WHERE run_id='conflict'" ) === $id
    && ISXM_Migration_Store::url_map( 'conflict', $old )[ $old ] === 'https://cdn.test/22222222/x.jpg' );

group( 'Several version folders of one file: keep the live one' );
migration_fixture( 2 ); $db = $GLOBALS['wpdb'];
$keys = [ 'v100/m1.jpg', 'v200/m1.jpg', 'v100/m2.jpg' ];
$scan = function ( array $modified = [] ) use ( &$keys ) {
    $GLOBALS['isxm_t']['http_provider'] = function () use ( $keys, $modified ) { return migration_listing( $keys, '', $modified ); };
    return ISXM_Migrate::scan_source_batch( '' );
};
$page = $scan();
t( 'No dates, no WP Offload data: both copies kept (item reports the collision, no guessing)', array_keys( $page['items'][1] ) === [ 'v100/m1.jpg', 'v200/m1.jpg' ] );
$page = $scan( [ 'v100/m1.jpg' => 1000, 'v200/m1.jpg' => 2000, 'v100/m2.jpg' => 1000 ] );
t( 'Newest LastModified wins', array_keys( $page['items'][1] ) === [ 'v200/m1.jpg' ], json_encode( $page['items'] ) );
t( 'Attachments without duplicates untouched', array_keys( $page['items'][2] ) === [ 'v100/m2.jpg' ] );
$db->db->exec( 'CREATE TABLE wp_as3cf_items (id INTEGER PRIMARY KEY, bucket TEXT, path TEXT, source_type TEXT, source_id INTEGER)' );
$db->db->exec( "INSERT INTO wp_as3cf_items(bucket,path,source_type,source_id) VALUES ('media','v100/m1.jpg','media-library',1)" );
$page = $scan( [ 'v100/m1.jpg' => 1000, 'v200/m1.jpg' => 2000 ] );
t( 'WP Offload Media\'s recorded folder beats a newer stray copy', array_keys( $page['items'][1] ) === [ 'v100/m1.jpg' ], json_encode( $page['items'] ) );
$db->db->exec( "UPDATE wp_as3cf_items SET path = 'v200/m1.jpg'" );
$keys = [ 'v100/m1.jpg', 'v100/m2.jpg' ]; // the live copy is on the NEXT page
$page = $scan();
t( 'A page holding only stale copies sends nothing for that item', ! isset( $page['items'][1] ) && isset( $page['items'][2] ) );
t( 'Dropped keys still count as scanned objects', $page['scanned_keys'] === 2 );
$db->db->exec( "UPDATE wp_as3cf_items SET bucket = 'other-bucket'" );
$page = $scan();
t( 'Rows for another bucket are ignored', isset( $page['items'][1] ) );

group( 'A failing GUID is an item error; the run still completes' );
$job = migration_fixture( 2 ); $db = $GLOBALS['wpdb'];
foreach ( [ 1, 2 ] as $i ) $db->db->exec( "INSERT INTO wp_posts VALUES($i,'','https://example.com/wp-content/uploads/m$i.jpg')" );
$db->fail_guid = true;
pipeline_drain();
$db->fail_guid = false;
$job = ISXM_Job::get( 'migrate' );
t( 'Run reaches complete despite GUID write failures', $job->migration['stage'] === 'complete' );
t( 'Each failed GUID is reported per item', $job->error_count === 2 && strpos( implode( ' ', $job->errors ), 'GUID' ) !== false, json_encode( $job->errors ) );

group( 'Only key/URL settings guard a run, and a stop names them' );
$job = migration_fixture( 3 );
$set = function ( array $over ) {
    $GLOBALS['isxm_t']['options']['isxs_settings'] = array_merge( $GLOBALS['isxm_t']['options']['isxs_settings'], $over );
    ISXM_Settings::flush_cache();
};
pipeline_step(); // index
$set( [ 'assets_enabled' => true, 'assets_cdn_domain' => 'cdn.example.test', 'offload_enabled' => true ] );
$r = pipeline_step();
t( 'Toggling Asset URLs / auto-offload mid-run does not stop it', ! is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
$set( [ 'cdn_domain' => 'media.example.test' ] );
$r = pipeline_step();
t( 'Changing the CDN domain stops the run and names it', is_wp_error( $r ) && strpos( $r->get_error_message(), 'cdn_domain "" -> "media.example.test"' ) !== false, is_wp_error( $r ) ? $r->get_error_message() : 'no error' );
$set( [ 'cdn_domain' => '' ] );
t( 'Restoring it lets the run continue', ! is_wp_error( pipeline_step() ) );

group( 'A run started by an older version (whole-settings hash) still resumes' );
$job = migration_fixture( 3 );
pipeline_step(); pipeline_step();
$j = ISXM_Job::get( 'migrate' );
unset( $j->migration['settings'] );
$j->migration['configuration'] = 'hash-written-by-0.2.18-before-assets-was-toggled';
$j->save();
$r = pipeline_step();
t( 'Hash mismatch from an unrelated setting is accepted when the URLs still match', ! is_wp_error( $r ) && isset( ISXM_Job::get( 'migrate' )->migration['settings'] ), is_wp_error( $r ) ? $r->get_error_message() : '' );
$j = ISXM_Job::get( 'migrate' );
unset( $j->migration['settings'] ); $j->migration['configuration'] = 'stale'; $j->save();
$GLOBALS['isxm_t']['options']['isxs_connections']['custom']['bucket'] = 'another-bucket'; ISXM_Settings::flush_cache();
$r = pipeline_step();
t( 'A changed destination bucket is still refused', is_wp_error( $r ) && $r->get_error_code() === 'isxs_migration_settings' );

group( 'Finalize shows progress and checkpoints per chunk' );
$job = migration_fixture( 3 );
$writes = 0; $seen = [];
$GLOBALS['isxm_t']['on_option_write'] = function ( $key, $value ) use ( &$writes, &$seen ) {
    if ( $key !== 'isxs_job_migrate' ) return;
    if ( in_array( $value['migration']['stage'] ?? '', [ 'guid', 'cleanup' ], true ) ) { $writes++; if ( ! empty( $value['activity']['finalize'] ) ) $seen[] = $value['activity']['finalize']; }
};
$entries = [];
for ( $i = 0; $i < 250; $i++ ) $entries[ "/nowhere/$i.jpg" ] = [ 'path' => "/nowhere/$i.jpg", 'hash' => 'x', 'covered' => true ];
ISXM_Migration_Store::put_many( ISXM_Job::get( 'migrate' )->run_id, 'cleanup', $entries );
pipeline_drain();
unset( $GLOBALS['isxm_t']['on_option_write'] );
$last = end( $seen );
t( 'Card gets done/total for the finalize stage', $last && $last['total'] >= 250 && $last['done'] >= 200 && $last['done'] <= $last['total'], json_encode( $last ) );
t( 'One checkpoint per 100-row chunk, not one per row', $writes <= 8, (string) $writes );

group( 'Sizes of big (-scaled) and EXIF-rotated images are migrated too' );
migration_fixture( 0 ); $db = $GLOBALS['wpdb'];
$db->db->exec( "INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES (1,'_wp_attached_file','big-scaled.jpg'),(2,'_wp_attached_file','rot-rotated.jpg')" );
$GLOBALS['isxm_t']['http_provider'] = function () {
    return migration_listing( [ 'big.jpg', 'big-1536x1536.jpg', 'big-300x200.jpg', 'big-scaled.jpg', 'big-scaled-800x800.jpg', 'rot.jpg', 'rot-150x150.jpg', 'rot-rotated.jpg', 'nobody-uses-this.jpg' ] );
};
$page = ISXM_Migrate::scan_source_batch( '' );
t( 'Original and every size of the -scaled image belong to it', array_keys( $page['items'][1] ?? [] ) === [ 'big.jpg', 'big-1536x1536.jpg', 'big-300x200.jpg', 'big-scaled.jpg', 'big-scaled-800x800.jpg' ], json_encode( $page['items'] ) );
t( 'Same for -rotated', array_keys( $page['items'][2] ?? [] ) === [ 'rot.jpg', 'rot-150x150.jpg', 'rot-rotated.jpg' ] );
t( 'Objects no attachment uses are reported, not silently dropped', $page['unmatched'] === [ 'nobody-uses-this.jpg' ] );
$job = migration_fixture( 0 ); $db = $GLOBALS['wpdb'];
$db->db->exec( "INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES (1,'_wp_attached_file','m1.jpg')" );
$GLOBALS['isxm_t']['http_provider'] = function () { return migration_listing( [ 'm1.jpg', 'stray-1.jpg', 'stray-2.jpg' ] ); };
pipeline_drain();
$sk = ISXM_Job::get( 'migrate' )->migration['skipped'] ?? [];
t( 'The run keeps a count and sample of unmatched objects', ( $sk['count'] ?? 0 ) === 2 && $sk['sample'] === [ 'stray-1.jpg', 'stray-2.jpg' ], json_encode( $sk ) );

group( 'Re-run: one listing per migrated item, only missing sizes move' );
$job = migration_fixture( 0 ); $db = $GLOBALS['wpdb'];
list( $s3port ) = isxm_start_fake_s3(); // uploads stream through real cURL
$GLOBALS['isxm_t']['options']['isxs_connections']['custom']['endpoint'] = "http://127.0.0.1:$s3port"; ISXM_Settings::flush_cache();
$db->db->exec( "INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES (1,'_wp_attached_file','p-scaled.jpg')" );
isxm_test_add_post( 1, [] ); update_post_meta( 1, '_wp_attached_file', 'p-scaled.jpg' );
ISXM_Offload::set_record( 1, [ 'bucket' => 'media', 'endpoint' => "http://127.0.0.1:$s3port", 'base_key' => '19000009/', 'version' => '19000009', 'files' => [ 'p-scaled.jpg', 'p-scaled-800x800.jpg' ] ] );
$lists = 0; $gets = [];
$GLOBALS['isxm_t']['http_provider'] = function ( $url, $args ) use ( &$lists, &$gets ) {
    parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $q );
    if ( isset( $q['list-type'] ) && ( $q['prefix'] ?? '' ) === '19000009/' ) { $lists++; return migration_listing( [ '19000009/p-scaled.jpg', '19000009/p-scaled-800x800.jpg' ] ); }
    if ( isset( $q['list-type'] ) && ! empty( $q['prefix'] ) ) return migration_listing( [ $q['prefix'] ] );
    if ( isset( $q['list-type'] ) ) return migration_listing( [ 'p.jpg', 'p-1536x1536.jpg', 'p-scaled.jpg', 'p-scaled-800x800.jpg' ] );
    if ( ( $args['method'] ?? 'GET' ) === 'GET' ) { $gets[] = basename( parse_url( $url, PHP_URL_PATH ) ); if ( ! empty( $args['filename'] ) ) file_put_contents( $args['filename'], 'x' ); }
    return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
};
pipeline_step(); pipeline_step();
ISXM_Items::flush_cache();
$rec = ISXM_Offload::get_record( 1 );
t( 'Already-present size verified with one listing, not one per file', $lists === 1, (string) $lists );
t( 'Sizes already on the destination are not fetched again', ! in_array( 'p-scaled-800x800.jpg', $gets, true ) && in_array( 'p-1536x1536.jpg', $gets, true ) && in_array( 'p.jpg', $gets, true ), json_encode( $gets ) );
t( 'Record keeps its version folder, keeps present files and gains the missing ones', ( $rec['base_key'] ?? '' ) === '19000009/' && ! array_diff( [ 'p.jpg', 'p-1536x1536.jpg', 'p-scaled.jpg', 'p-scaled-800x800.jpg' ], $rec['files'] ?? [] ), json_encode( $rec ) . ' ' . json_encode( ISXM_Job::get( 'migrate' )->errors ) );

group( 'Indexed URL lookup forms' );
foreach ( [ 'image.jpg', 'photo(1).jpg', 'my image.jpg', 'ภาพ.jpg' ] as $name ) {
    $old = 'https://source.test/version/' . $name; $new = 'https://dest.test/' . rawurlencode( $name );
    ISXM_Migration_Store::put( 'forms', 'url', $old, [ 'old' => $old, 'new' => $new ] );
    foreach ( [ $old, '<img src="' . $old . '?v=1">', serialize( [ $old ] ), wp_json_encode( [ $old ] ), 'url(' . $old . ')' ] as $value ) {
        $map = ISXM_Migration_Store::url_map( 'forms', $value );
        $out = ISXM_DB_Rewriter::recursive_replace_pairs( $value, $map );
        t( 'URL form preserved: ' . $name . ' ' . substr( $value, 0, 9 ), $out !== $value && strpos( $out, 'source.test' ) === false, $out );
    }
}
$before = count( $GLOBALS['isxm_t']['purged_posts'] ?? [] );
$db->db->exec( "INSERT INTO wp_posts VALUES (5,'unrelated','')" );
ISXM_DB_Rewriter::replace_urls_window( [], [ 'table' => 0, 'after' => 4 ], 0, function ( $v ) { return ISXM_Migration_Store::url_map( 'forms', $v ); } );
t( 'Unrelated content does not purge caches', count( $GLOBALS['isxm_t']['purged_posts'] ?? [] ) === $before );

group( 'Extra source folders are copied key-for-key and their links rewritten' );
t( 'Sanitize: comma (or newline) separated, blanks dropped, inside-Source-Prefix dropped, duplicates merged',
    ISXM_Tools::sanitize_extra_prefixes( "download/, , /x/y ,wp-content/uploads/2024/\ndownload", 'wp-content/uploads/' ) === 'download/, x/y/' );
$fake_log  = sys_get_temp_dir() . '/isxm_fake_s3_pipe_' . getmypid() . '.jsonl';
$fake_port = 19000 + ( getmypid() % 1000 );
@unlink( $fake_log );
$fake = proc_open( [ PHP_BINARY, '-S', "127.0.0.1:$fake_port", __DIR__ . '/fake-s3.php' ],
    [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes, null, [ 'ISXM_FAKE_S3_LOG' => $fake_log ] );
for ( $i = 0; $i < 50 && ! @fsockopen( '127.0.0.1', $fake_port ); $i++ ) usleep( 100000 );
$job = migration_fixture( 1 ); $db = $GLOBALS['wpdb'];
$conn = get_option( 'isxs_connections' );
$conn['custom']['endpoint'] = "http://127.0.0.1:$fake_port";
$conn['minio'] = $conn['custom']; $conn['minio']['bucket'] = 'source';
update_option( 'isxs_connections', $conn );
$GLOBALS['isxm_t']['options']['isxs_settings'] = array_merge( get_option( 'isxs_settings' ), [
    'source_provider' => 'minio', 'source_extra_prefixes' => "download/", 'source_public_base_url' => 'https://src.example.com' ] );
ISXM_Settings::flush_cache();
$GLOBALS['isxm_t']['http_provider'] = function ( $url, $args ) {
    parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $q );
    if ( isset( $q['list-type'] ) ) {
        $prefix = $q['prefix'] ?? '';
        if ( $prefix === 'download/' ) return migration_listing( [ 'download/', 'download/Specification/a.pdf', 'download/Specification/Spec Sheet.pdf', 'download/Specification/broken.pdf' ] );
        return migration_listing( $prefix !== '' ? [ $prefix ] : [ 'm1.jpg' ] );
    }
    if ( strpos( $url, 'broken' ) !== false ) return [ 'response' => [ 'code' => 403 ], 'body' => '<Error><Code>AccessDenied</Code></Error>' ];
    $body = 'bytes of ' . rawurldecode( basename( parse_url( $url, PHP_URL_PATH ) ) );
    if ( ! empty( $args['filename'] ) ) file_put_contents( $args['filename'], $body );
    return [ 'response' => [ 'code' => 200 ], 'body' => $body ];
};
$src = 'https://src.example.com/download/Specification/';
$db->db->exec( 'INSERT INTO wp_posts VALUES(50,' . $db->db->quote( '<a href="' . $src . 'a.pdf">A</a> <a href="' . $src . 'Spec%20Sheet.pdf">B</a>' ) . ",'')" );
pipeline_drain();
$puts = [];
foreach ( is_file( $fake_log ) ? file( $fake_log ) : [] as $line ) { $r = json_decode( $line, true ); if ( $r['method'] === 'PUT' ) $puts[] = rawurldecode( $r['uri'] ); }
proc_terminate( $fake ); @unlink( $fake_log );
$done = ISXM_Job::get( 'migrate' );
t( 'Both files PUT under their original key', in_array( '/media/download/Specification/a.pdf', $puts, true ) && in_array( '/media/download/Specification/Spec Sheet.pdf', $puts, true ), json_encode( $puts ) );
t( 'Folder marker and failed download are not uploaded', ! preg_grep( '~/media/download/$|broken~', $puts ) );
t( 'A failed file is an item error; the run still completes', $done->migration['stage'] === 'complete' && $done->error_count === 1 && strpos( implode( ' ', $done->errors ), 'broken.pdf' ) !== false, json_encode( [ $done->migration['stage'], $done->error_count, $done->errors ] ) );
t( 'Copied count reported to the card', ( $done->activity['extras_copied'] ?? null ) === 2 );
$content = $db->get_var( 'SELECT post_content FROM wp_posts WHERE ID=50' );
$base = ISXM_Settings::public_base_url() . '/download/Specification/';
t( 'Raw and percent-encoded links rewritten to the destination', strpos( $content, 'src.example.com' ) === false && strpos( $content, $base . 'a.pdf' ) !== false && strpos( $content, $base . 'Spec%20Sheet.pdf' ) !== false, $content );

group( 'Upload missing sizes of a partial item' );
$fake_log  = sys_get_temp_dir() . '/isxm_fake_s3_partial_' . getmypid() . '.jsonl';
$fake_port = 20000 + ( getmypid() % 1000 );
@unlink( $fake_log );
$fake = proc_open( [ PHP_BINARY, '-S', "127.0.0.1:$fake_port", __DIR__ . '/fake-s3.php' ],
    [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes, null, [ 'ISXM_FAKE_S3_LOG' => $fake_log ] );
for ( $i = 0; $i < 50 && ! @fsockopen( '127.0.0.1', $fake_port ); $i++ ) usleep( 100000 );
migration_fixture( 0 ); $db = $GLOBALS['wpdb'];
// The ledger/legacy queries filter on post_type/post_status.
$db->db->exec( 'DROP TABLE wp_posts' );
$db->db->exec( "CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_content TEXT, guid TEXT, post_type TEXT DEFAULT 'attachment', post_status TEXT DEFAULT 'inherit')" );
isxm_items_table( $db );
$conn = get_option( 'isxs_connections' ); $conn['custom']['endpoint'] = "http://127.0.0.1:$fake_port"; update_option( 'isxs_connections', $conn );
ISXM_Settings::flush_cache();
$dest = [ 'bucket' => 'media', 'endpoint' => "http://127.0.0.1:$fake_port", 'path_style' => true, 'base_key' => 'p/', 'version' => '' ];
foreach ( [ 1, 2, 3, 4, 5 ] as $id ) {
    isxm_test_add_post( $id, [] ); update_post_meta( $id, '_wp_attached_file', "p$id.jpg" );
    $db->db->exec( "INSERT INTO wp_posts(ID) VALUES ($id)" );
}
ISXM_Offload::set_record( 1, $dest + [ 'files' => [ 'p1.jpg' ], 'missing' => [ 'p1-150x150.jpg', 'p1-300x300.jpg' ] ] );
ISXM_Offload::set_record( 2, $dest + [ 'files' => [ 'p2.jpg' ], 'missing' => [ 'p2-150x150.jpg' ] ] );
ISXM_Offload::set_record( 3, $dest + [ 'files' => [ 'p3.jpg' ], 'missing' => [ 'p3-150x150.jpg' ] ] );
ISXM_Offload::set_record( 4, [ 'bucket' => 'old', 'endpoint' => 'https://old.example' ] + $dest + [ 'files' => [ 'p4.jpg' ], 'missing' => [ 'p4-150x150.jpg' ] ] );
ISXM_Offload::set_record( 5, $dest + [ 'files' => [ 'p5.jpg' ] ] );
@unlink( ISXM_TEST_UPLOADS . '/p1.jpg' ); // original already removed by "Remove Local Media"
file_put_contents( ISXM_TEST_UPLOADS . '/p1-150x150.jpg', 'small' );
$r = ISXM_Offload::complete_missing_sizes( 1, false );
$puts = [];
foreach ( is_file( $fake_log ) ? file( $fake_log ) : [] as $line ) { $row = json_decode( $line, true ); if ( $row['method'] === 'PUT' ) $puts[] = $row['uri']; }
$rec = ISXM_Offload::get_record( 1 );
t( 'Only the missing size found locally is uploaded, into the record folder', $r === true && $puts === [ '/media/p/p1-150x150.jpg' ], json_encode( [ $r, $puts ] ) );
t( 'Record: uploaded size moves to files, the absent one stays missing', $rec['files'] === [ 'p1.jpg', 'p1-150x150.jpg' ] && $rec['missing'] === [ 'p1-300x300.jpg' ] && ISXM_Offload::status_for( 1 )['status'] === 'partial' );
t( 'Uploaded local copy removed (Remove Local Media + persist)', ! is_file( ISXM_TEST_UPLOADS . '/p1-150x150.jpg' ) );
file_put_contents( ISXM_TEST_UPLOADS . '/p1-300x300.jpg', 'medium' );
ISXM_Offload::complete_missing_sizes( 1, false );
t( 'Last missing size uploaded: item is complete', ! isset( ISXM_Offload::get_record( 1 )['missing'] ) && ISXM_Offload::status_for( 1 )['status'] === 'offloaded' );
$r = ISXM_Offload::complete_missing_sizes( 2 );
t( 'Nothing on the server: error names the size, record unchanged', is_wp_error( $r ) && strpos( $r->get_error_message(), 'p2-150x150.jpg' ) !== false && ISXM_Offload::get_record( 2 )['missing'] === [ 'p2-150x150.jpg' ] );
file_put_contents( ISXM_TEST_UPLOADS . '/p3-150x150.jpg', 'small' );
$cb = new ReflectionMethod( 'ISXM_Tools', 'offload_one_callback' );
if ( PHP_VERSION_ID < 80100 ) $cb->setAccessible( true ); // no-op (deprecated) on 8.1+
$pairs = call_user_func( $cb->invoke( new ISXM_Tools() ), 3 );
t( 'Offload Now on a partial item fills it in instead of failing on the removed original', ! is_wp_error( $pairs ) && ISXM_Offload::status_for( 3 )['status'] === 'offloaded', is_wp_error( $pairs ) ? $pairs->get_error_message() : '' );
ISXM_Offload::clear_pending_deletions();
update_option( ISXM_Items::BACKFILLED_OPTION, '1' );
t( 'Ledger: partial ids on the current bucket only', ISXM_Tools::get_partial_ids( 10 )['ids'] === [ 2 ], json_encode( ISXM_Tools::get_partial_ids( 10 ) ) );
delete_option( ISXM_Items::BACKFILLED_OPTION );
// The meta stub is in-memory; mirror the records into the SQL table the legacy query reads.
foreach ( [ 1, 2, 3, 4, 5 ] as $id ) {
    $db->db->exec( 'INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES (' . $id . ',' . $db->db->quote( ISXM_Offload::META_KEY ) . ',' . $db->db->quote( serialize( ISXM_Offload::get_record( $id ) ) ) . ')' );
}
t( 'Legacy postmeta: same answer', ISXM_Tools::get_partial_ids( 10 )['ids'] === [ 2 ], json_encode( ISXM_Tools::get_partial_ids( 10 ) ) );
proc_terminate( $fake ); @unlink( $fake_log );

group( 'Missing sizes that never existed are built from the original' );
if ( ! function_exists( 'imagecreatetruecolor' ) ) {
    t( 'SKIPPED: GD not available', true );
} else {
    // Minimal stand-ins for WordPress' image editor API, backed by GD.
    if ( ! class_exists( 'ISXM_Test_Editor' ) ) {
        class ISXM_Test_Editor {
            private $im;
            public function __construct( $file ) { $this->im = imagecreatefromjpeg( $file ); }
            public function resize( $w, $h, $crop ) { $this->im = imagescale( $this->im, $w, $h ); return true; }
            public function save( $path ) { imagejpeg( $this->im, $path ); return [ 'path' => $path ]; }
        }
    }
    if ( ! function_exists( 'wp_get_image_editor' ) ) {
        function wp_get_image_editor( $file ) { return is_file( $file ) && @getimagesize( $file ) ? new ISXM_Test_Editor( $file ) : new WP_Error( 'image_no_editor', 'no editor' ); }
        function wp_attachment_is_image( $id ) { return true; }
        function wp_get_registered_image_subsizes() { return [ 'thumbnail' => [ 'width' => 150, 'height' => 150, 'crop' => true ] ]; }
    }
    $fake_log  = sys_get_temp_dir() . '/isxm_fake_s3_gen_' . getmypid() . '.jsonl';
    $fake_port = 23000 + ( getmypid() % 1000 );
    @unlink( $fake_log );
    $fake = proc_open( [ PHP_BINARY, '-S', "127.0.0.1:$fake_port", __DIR__ . '/fake-s3.php' ],
        [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes, null, [ 'ISXM_FAKE_S3_LOG' => $fake_log ] );
    for ( $i = 0; $i < 50 && ! @fsockopen( '127.0.0.1', $fake_port ); $i++ ) usleep( 100000 );
    migration_fixture( 0 ); $db = $GLOBALS['wpdb'];
    $db->db->exec( 'DROP TABLE wp_posts' );
    $db->db->exec( "CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_content TEXT, guid TEXT, post_type TEXT DEFAULT 'attachment', post_status TEXT DEFAULT 'inherit')" );
    isxm_items_table( $db );
    $conn = get_option( 'isxs_connections' ); $conn['custom']['endpoint'] = "http://127.0.0.1:$fake_port"; update_option( 'isxs_connections', $conn );
    ISXM_Settings::flush_cache();
    $jpeg = function ( $path ) { $im = imagecreatetruecolor( 400, 300 ); imagejpeg( $im, $path ); };
    $dest = [ 'bucket' => 'media', 'endpoint' => "http://127.0.0.1:$fake_port", 'path_style' => true, 'base_key' => 'g/', 'version' => '' ];
    foreach ( [ 1, 2, 3 ] as $id ) {
        isxm_test_add_post( $id, [] ); update_post_meta( $id, '_wp_attached_file', "g$id.jpg" );
        update_post_meta( $id, '_wp_attachment_metadata', [ 'width' => 400, 'height' => 300, 'file' => "g$id.jpg", 'sizes' => [ 'thumbnail' => [ 'file' => "g$id-150x150.jpg", 'width' => 150, 'height' => 150 ] ] ] );
        $db->db->exec( "INSERT INTO wp_posts(ID) VALUES ($id)" );
        ISXM_Offload::set_record( $id, $dest + [ 'files' => [ "g$id.jpg" ], 'missing' => [ "g$id-150x150.jpg" ] ] );
        @unlink( ISXM_TEST_UPLOADS . "/g$id-150x150.jpg" );
    }
    $jpeg( ISXM_TEST_UPLOADS . '/g1.jpg' );                 // original still on the server
    @unlink( ISXM_TEST_UPLOADS . '/g2.jpg' );               // original only on the bucket
    $tmp = tempnam( sys_get_temp_dir(), 'g2' ); $jpeg( $tmp ); $g2 = file_get_contents( $tmp ); unlink( $tmp );
    $GLOBALS['isxm_t']['http_provider'] = function ( $url, $args ) use ( $g2 ) {
        if ( strpos( $url, 'g2.jpg' ) === false ) return [ 'response' => [ 'code' => 404 ], 'body' => '' ];
        if ( ! empty( $args['filename'] ) ) file_put_contents( $args['filename'], $g2 );
        return [ 'response' => [ 'code' => 200 ], 'body' => $g2 ];
    };
    $puts = function () use ( $fake_log ) { $o = []; foreach ( is_file( $fake_log ) ? file( $fake_log ) : [] as $line ) { $row = json_decode( $line, true ); if ( $row['method'] === 'PUT' ) $o[] = $row['uri']; } return $o; };
    $r = ISXM_Offload::complete_missing_sizes( 1, false );
    t( 'Built from the local original and uploaded; nothing left behind', $r === true && $puts() === [ '/media/g/g1-150x150.jpg' ] && ISXM_Offload::status_for( 1 )['status'] === 'offloaded' && ! is_file( ISXM_TEST_UPLOADS . '/g1-150x150.jpg' ), json_encode( [ is_wp_error( $r ) ? $r->get_error_message() : $r, $puts() ] ) );
    $r = ISXM_Offload::complete_missing_sizes( 2, false );
    t( 'Original only on the bucket: downloaded to a temp file, size built and uploaded', $r === true && in_array( '/media/g/g2-150x150.jpg', $puts(), true ) && ISXM_Offload::status_for( 2 )['status'] === 'offloaded', is_wp_error( $r ) ? $r->get_error_message() : '' );
    @unlink( ISXM_TEST_UPLOADS . '/g3.jpg' );
    $r = ISXM_Offload::complete_missing_sizes( 3, false );
    t( 'Nothing to build from: the error names the folder that was checked', is_wp_error( $r ) && strpos( $r->get_error_message(), 'wp-content/uploads/' ) !== false && strpos( $r->get_error_message(), 'g3-150x150.jpg' ) !== false && strpos( $r->get_error_message(), sys_get_temp_dir() ) === false, is_wp_error( $r ) ? $r->get_error_message() : '' );
    // Offload's second pass: no new uploads left → missing sizes → rewrite.
    update_option( ISXM_Items::BACKFILLED_OPTION, '1' );
    $off = new ReflectionMethod( 'ISXM_Tools', 'batch_offload' );
    if ( PHP_VERSION_ID < 80100 ) $off->setAccessible( true );
    $step = $off->invoke( new ISXM_Tools(), 'upload|0' );
    t( 'Offload moves on to the missing-sizes pass when uploads are done', $step['cursor'] === 'partial|0' && ! $step['done'], json_encode( $step ) );
    $step = $off->invoke( new ISXM_Tools(), 'partial|0' );
    t( 'Missing-sizes pass then hands over to the rewrite', $step['cursor'] === 'rewrite|posts:0' && ! $step['done'] && $step['processed'] === 1, json_encode( $step ) );
    delete_option( ISXM_Items::BACKFILLED_OPTION );
    proc_terminate( $fake ); @unlink( $fake_log );
}

group( 'Upload server folders' );
$fake_log  = sys_get_temp_dir() . '/isxm_fake_s3_server_' . getmypid() . '.jsonl';
$fake_port = 22000 + ( getmypid() % 1000 );
@unlink( $fake_log );
$fake = proc_open( [ PHP_BINARY, '-S', "127.0.0.1:$fake_port", __DIR__ . '/fake-s3.php' ],
    [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes, null, [ 'ISXM_FAKE_S3_LOG' => $fake_log ] );
for ( $i = 0; $i < 50 && ! @fsockopen( '127.0.0.1', $fake_port ); $i++ ) usleep( 100000 );
migration_fixture( 0 ); $db = $GLOBALS['wpdb'];
$conn = get_option( 'isxs_connections' ); $conn['custom']['endpoint'] = "http://127.0.0.1:$fake_port"; update_option( 'isxs_connections', $conn );
ISXM_Settings::flush_cache();
$spec = ABSPATH . 'download/Specification/';
foreach ( [ 'a.pdf', 'sub/b.pdf', 'Spec Sheet.pdf', '.hidden', '.git/config' ] as $f ) { wp_mkdir_p( dirname( $spec . $f ) ); file_put_contents( $spec . $f, 'x' ); }
foreach ( [ 'wp-admin', 'wp-content/plugins/p', 'wp-content/themes/t', 'wp-content/keep' ] as $d ) { wp_mkdir_p( ABSPATH . $d ); file_put_contents( ABSPATH . $d . '/f.txt', 'x' ); }
$s = ISXM_Tools::sanitize_server_folders( "download/Specification/, ../etc/, wp-admin/, wp-content/plugins/, missing/, , download/Specification" );
t( 'Sanitize: inside-the-site folders only, core code and traversal dropped', $s['value'] === 'download/Specification/' && $s['rejected'] === [ '../etc/', 'wp-admin/', 'wp-content/plugins/', 'missing/' ], json_encode( $s ) );
t( 'wp-content itself may be picked, but its walk skips plugins/ and themes/', ISXM_Tools::server_folder_path( 'wp-content/' ) !== '' && ISXM_Tools::server_folder_files( 'wp-content/' ) === array_values( array_filter( ISXM_Tools::server_folder_files( 'wp-content/' ), function ( $f ) { return strpos( $f, 'wp-content/plugins/' ) !== 0 && strpos( $f, 'wp-content/themes/' ) !== 0; } ) ) && in_array( 'wp-content/keep/f.txt', ISXM_Tools::server_folder_files( 'wp-content/' ), true ) );
t( 'Files listed sorted, relative to the site root, hidden ones skipped', ISXM_Tools::server_folder_files( 'download/Specification/' ) === [ 'download/Specification/Spec Sheet.pdf', 'download/Specification/a.pdf', 'download/Specification/sub/b.pdf' ] );
$GLOBALS['isxm_t']['options']['isxs_settings']['offload_server_folders'] = 'download/Specification/'; ISXM_Settings::flush_cache();
$db->db->exec( 'INSERT INTO wp_posts VALUES(70,' . $db->db->quote( '<a href="https://example.com/download/Specification/a.pdf">A</a> <a href="http://example.com/download/Specification/Spec%20Sheet.pdf">B</a> <a href="/download/Specification/a.pdf">rel</a>' ) . ",'')" );
$batch = new ReflectionMethod( 'ISXM_Tools', 'batch_offload_folders' );
if ( PHP_VERSION_ID < 80100 ) $batch->setAccessible( true );
$r = $batch->invoke( new ISXM_Tools(), '0|download/Specification/Spec Sheet.pdf' );
$puts = function () use ( $fake_log ) { $o = []; foreach ( is_file( $fake_log ) ? file( $fake_log ) : [] as $line ) { $row = json_decode( $line, true ); if ( $row['method'] === 'PUT' ) $o[] = rawurldecode( $row['uri'] ); } return $o; };
t( 'Resume from the cursor: only the files after it are uploaded', $r['done'] && $r['processed'] === 2 && $puts() === [ '/media/download/Specification/a.pdf', '/media/download/Specification/sub/b.pdf' ], json_encode( [ $r, $puts() ] ) );
@unlink( $fake_log );
$r = $batch->invoke( new ISXM_Tools(), 0 );
t( 'Full run: every file under its own path, nothing hidden', $r['done'] && $r['errors'] === [] && $puts() === [ '/media/download/Specification/Spec Sheet.pdf', '/media/download/Specification/a.pdf', '/media/download/Specification/sub/b.pdf' ], json_encode( $puts() ) );
t( 'Server copies are kept', is_file( $spec . 'a.pdf' ) && is_file( $spec . 'Spec Sheet.pdf' ) );
$content = $db->get_var( 'SELECT post_content FROM wp_posts WHERE ID=70' );
$base = ISXM_Settings::public_base_url() . '/download/Specification/';
t( 'Site links (http and https, raw and encoded) now point at the bucket; relative link untouched', strpos( $content, 'href="' . $base . 'a.pdf"' ) !== false && strpos( $content, 'href="' . $base . 'Spec%20Sheet.pdf"' ) !== false && strpos( $content, 'href="/download/Specification/a.pdf"' ) !== false && strpos( $content, 'example.com/download' ) === false, $content );
t( 'Total counts the files to upload', ISXM_Tools::tool_total( 'offload_folders' ) === 3 );
proc_terminate( $fake ); @unlink( $fake_log );

group( 'Source folder picker' );
migration_fixture( 2 );
$seen = [];
$GLOBALS['isxm_t']['http_provider'] = function ( $url ) use ( &$seen ) {
    parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $q ); $seen[] = $q;
    if ( isset( $q['delimiter'] ) ) {
        return [ 'response' => [ 'code' => 200 ], 'body' => '<ListBucketResult><IsTruncated>false</IsTruncated>'
            . '<CommonPrefixes><Prefix>wp-content/uploads/</Prefix></CommonPrefixes><CommonPrefixes><Prefix>wp-content/a&amp;b/</Prefix></CommonPrefixes></ListBucketResult>' ];
    }
    $prefix = $q['prefix'] ?? '';
    if ( $prefix === 'wp-content/uploads/' ) return migration_listing( [ 'wp-content/uploads/', 'wp-content/uploads/m1.jpg', 'wp-content/uploads/m2-300x300.jpg', 'wp-content/uploads/stray.jpg' ] );
    return migration_listing( [ $prefix . 'spec.pdf' ] );
};
$folders = ISXM_Migrate::list_source_folders( 'wp-content/' );
t( 'Lists one level via delimiter=/ and decodes entities', $folders['folders'] === [ 'wp-content/uploads/', 'wp-content/a&b/' ] && ! $folders['truncated'] && end( $seen )['prefix'] === 'wp-content/' && end( $seen )['delimiter'] === '/', json_encode( $folders ) );
t( 'Uploads folder: originals and sizes resolve to attachments, strays and markers do not', ISXM_Migrate::count_attachment_matches( 'wp-content/uploads/' ) === 2 );
t( 'Non-media folder scores 0', ISXM_Migrate::count_attachment_matches( 'download/' ) === 0 );
$roles = ISXM_Tools::assign_source_folders( [ 'download/', 'wp-content/uploads/' ], 'old/' );
t( 'The folder with attachments becomes the Source Prefix, the rest copied as-is', $roles['prefix'] === 'wp-content/uploads/' && $roles['media'] && $roles['extras'] === 'download/' && $roles['warning'] === '' && $roles['notice'] === '', json_encode( $roles ) );
$calls = 0;
$roles = ISXM_Tools::assign_source_folders( [ 'download/Specification/' ], 'wp-content/uploads/', function () use ( &$calls ) { $calls++; return 0; } );
t( 'A single non-media folder is sampled and copied as-is: no Media Library in this run', $calls === 1 && ! $roles['media'] && $roles['extras'] === 'download/Specification/' && $roles['prefix'] === 'wp-content/uploads/' && $roles['notice'] !== '' && $roles['warning'] === '', json_encode( $roles ) );
$roles = ISXM_Tools::assign_source_folders( [ 'wp-content/uploads/' ], '', function () { return 7; } );
t( 'A single uploads folder is the Media Library', $roles['media'] && $roles['prefix'] === 'wp-content/uploads/' && $roles['extras'] === '' );
$roles = ISXM_Tools::assign_source_folders( [ 'a/', 'old/' ], 'old/', function () { return 0; } );
t( 'No matches anywhere: every folder copied as-is, previous prefix kept but unused', ! $roles['media'] && $roles['prefix'] === 'old/' && $roles['extras'] === 'a/, old/' );
$roles = ISXM_Tools::assign_source_folders( [ 'a/', 'b/' ], 'old/', function () { return new WP_Error( 'x', 'down' ); } );
t( 'Source unreachable: previous setting kept, connection warning', ! $roles['media'] && $roles['extras'] === 'a/, b/' && strpos( $roles['warning'], 'source connection' ) !== false );
$roles = ISXM_Tools::assign_source_folders( [ 'wp-content/uploads/', 'wp-content/uploads/2024/', 'download/' ], '', function ( $f ) { return $f === 'wp-content/uploads/' ? 5 : 1; } );
t( 'A folder inside the chosen root is not copied twice', $roles['extras'] === 'download/', json_encode( $roles ) );
$GLOBALS['isxm_t']['options']['isxs_settings'] = array_merge( get_option( 'isxs_settings' ), [ 'source_prefix' => 'wp-content/uploads/', 'source_extra_prefixes' => "download/\nfiles/" ] );
ISXM_Settings::flush_cache();
t( 'Picker value derives from the two settings, root first', ISXM_Settings::source_folders() === 'wp-content/uploads/, download/, files/' );
$GLOBALS['isxm_t']['options']['isxs_settings']['source_media'] = false; ISXM_Settings::flush_cache();
t( 'Without a Media Library the unused root is not shown', ISXM_Settings::source_folders() === 'download/, files/' );
$GLOBALS['isxm_t']['options']['isxs_settings']['cdn_domain'] = 'cdn.example.test'; ISXM_Settings::flush_cache();
t( 'Source Public URL on the destination host: a warning, not a refusal', ISXM_Tools::source_url_warning( 'https://cdn.example.test', 'cdn.example.test' ) !== ''
    && ISXM_Tools::source_url_warning( 'https://old-bucket.s3.amazonaws.com', 'cdn.example.test' ) === '' && ISXM_Tools::source_url_warning( '', 'cdn.example.test' ) === '' );

group( 'Migrate only the picked folders (no Media Library)' );
$fake_log  = sys_get_temp_dir() . '/isxm_fake_s3_extras_only_' . getmypid() . '.jsonl';
$fake_port = 21000 + ( getmypid() % 1000 );
@unlink( $fake_log );
$fake = proc_open( [ PHP_BINARY, '-S', "127.0.0.1:$fake_port", __DIR__ . '/fake-s3.php' ],
    [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes, null, [ 'ISXM_FAKE_S3_LOG' => $fake_log ] );
for ( $i = 0; $i < 50 && ! @fsockopen( '127.0.0.1', $fake_port ); $i++ ) usleep( 100000 );
$job = migration_fixture( 2 ); $db = $GLOBALS['wpdb'];
$job->total = 0; $job->save(); // a real migrate starts at 0 (tool_total('migrate'))
$conn = get_option( 'isxs_connections' );
$conn['custom']['endpoint'] = "http://127.0.0.1:$fake_port";
$conn['minio'] = $conn['custom']; $conn['minio']['bucket'] = 'source';
update_option( 'isxs_connections', $conn );
$GLOBALS['isxm_t']['options']['isxs_settings'] = array_merge( get_option( 'isxs_settings' ), [
    'source_provider' => 'minio', 'source_media' => false, 'source_extra_prefixes' => 'download/Specification/', 'source_public_base_url' => 'https://src.example.com' ] );
ISXM_Settings::flush_cache();
$listed = [];
$GLOBALS['isxm_t']['http_provider'] = function ( $url, $args ) use ( &$listed ) {
    parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $q );
    if ( isset( $q['list-type'] ) ) {
        $listed[] = $q['prefix'] ?? '';
        $pfx = $q['prefix'] ?? '';
        return migration_listing( $pfx === 'download/Specification/' ? [ 'download/Specification/x.pdf', 'download/Specification/y.pdf' ] : ( $pfx === 'download/Manual/' ? [ 'download/Manual/m.pdf', 'download/Manual/n.pdf' ] : [ 'm1.jpg', 'm2.jpg' ] ) );
    }
    if ( ! empty( $args['filename'] ) ) file_put_contents( $args['filename'], 'pdf' );
    return [ 'response' => [ 'code' => 200 ], 'body' => 'pdf' ];
};
$count = ( new ReflectionMethod( 'ISXM_Tools', 'batch_migrate' ) );
if ( PHP_VERSION_ID < 80100 ) $count->setAccessible( true );
$counted = $count->invoke( new ISXM_Tools(), 'count|' );
t( 'Counting phase counts only the picked folder, exactly', $counted['cursor'] === 'run|' && $counted['total_delta'] === 2 && $counted['total_complete'] === true && $listed === [ 'download/Specification/' ], json_encode( [ $counted, $listed ] ) );
// What the runner does with a counting result.
$job = ISXM_Job::get( 'migrate' ); $job->total += $counted['total_delta']; $job->total_complete = true; $job->save();
$listed = [];
pipeline_drain();
$puts = [];
foreach ( is_file( $fake_log ) ? file( $fake_log ) : [] as $line ) { $row = json_decode( $line, true ); if ( $row['method'] === 'PUT' ) $puts[] = $row['uri']; }
proc_terminate( $fake ); @unlink( $fake_log );
$done = ISXM_Job::get( 'migrate' );
t( 'Media Library untouched: only the picked folder is listed', $listed === [ 'download/Specification/' ], json_encode( $listed ) );
t( 'Picked files copied under their own keys', $puts === [ '/media/download/Specification/x.pdf', '/media/download/Specification/y.pdf' ], json_encode( $puts ) );
t( 'Total fixed from the start; progress ends at total/total', $done->processed === 2 && $done->total === 2 && $done->total_complete && $done->migration['stage'] === 'complete', json_encode( [ $done->processed, $done->total, $done->total_complete, $done->migration['stage'] ] ) );
$GLOBALS['isxm_t']['options']['isxs_settings']['source_extra_prefixes'] = 'download/Specification/, download/Manual/'; ISXM_Settings::flush_cache();
$listed = [];
$first  = $count->invoke( new ISXM_Tools(), 'count|' );
$second = $count->invoke( new ISXM_Tools(), $first['cursor'] );
t( 'Several folders: counted one after the other, complete only after the last', $first['cursor'] === 'count|1:' && ! $first['total_complete'] && $first['phase'] === 'counting' && $second['cursor'] === 'run|' && $second['total_complete'] && $first['total_delta'] + $second['total_delta'] === 4 && $listed === [ 'download/Specification/', 'download/Manual/' ], json_encode( [ $first, $second, $listed ] ) );

echo "\n{$GLOBALS['isxm_pass']} passed; {$GLOBALS['isxm_fail']} failed\n";
exit( $GLOBALS['isxm_fail'] ? 1 : 0 );
