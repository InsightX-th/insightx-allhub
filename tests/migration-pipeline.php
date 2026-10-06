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

echo "\n{$GLOBALS['isxm_pass']} passed; {$GLOBALS['isxm_fail']} failed\n";
exit( $GLOBALS['isxm_fail'] ? 1 : 0 );
