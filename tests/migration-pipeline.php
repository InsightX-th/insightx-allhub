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
