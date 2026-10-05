<?php
/** php tests/items.php
 * The offload ledger (ISXM_Items) against a real SQL engine: records,
 * priming, destination counts, keyset scans and the legacy backfill.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/migrate-support.php';

function ledger_fixture() {
    $GLOBALS['wpdb'] = new ISXM_Migration_Test_DB(); $db = $GLOBALS['wpdb'];
    $db->db->exec( 'DROP TABLE wp_posts' );
    $db->db->exec( "CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_content TEXT, guid TEXT, post_type TEXT DEFAULT 'attachment', post_status TEXT DEFAULT 'inherit')" );
    isxm_test_configure( [], [] );
    isxm_items_table( $db );
    ISXM_Items::flush_cache();
    return $db;
}
function rec( $bucket = 'media', $files = [ 'a.jpg' ], array $extra = [] ) {
    return array_merge( [ 'bucket' => $bucket, 'endpoint' => 'https://s3.example.test', 'base_key' => 'k/', 'files' => $files ], $extra );
}

group( 'Records' );
$db = ledger_fixture();
t( 'put + get round-trips the postmeta shape', ISXM_Items::put( 1, rec( 'media', [ 'a.jpg', 'a-1.jpg' ], [ 'missing' => [ 'a-2.jpg' ], 'version' => '123', 'origin' => 'migrate' ] ) ) );
ISXM_Items::flush_cache();
$r = ISXM_Items::get( 1 );
t( 'Files, missing, version and origin survive', $r['files'] === [ 'a.jpg', 'a-1.jpg' ] && $r['missing'] === [ 'a-2.jpg' ] && $r['version'] === '123' && $r['origin'] === 'migrate', json_encode( $r ) );
ISXM_Items::put( 1, rec() );
ISXM_Items::flush_cache();
t( 'Clearing "missing" stores NULL, not a stale list', empty( ISXM_Items::get( 1 )['missing'] ) && (int) $db->get_var( 'SELECT is_partial FROM wp_isxs_items WHERE source_id=1' ) === 0 );
t( 'delete() forgets the row', ISXM_Items::delete( 1 ) && ISXM_Items::get( 1 ) === null );
t( 'Ledger not installed: put() is a no-op success (postmeta only)', ( function () { delete_option( ISXM_Items::DB_VERSION_OPTION ); $ok = ISXM_Items::put( 2, rec() ); update_option( ISXM_Items::DB_VERSION_OPTION, ISXM_Items::DB_VERSION ); return $ok; } )() && ISXM_Items::get( 2 ) === null );

group( 'Priming' );
$db = ledger_fixture();
for ( $i = 1; $i <= 10; $i++ ) ISXM_Items::put( $i, rec() );
ISXM_Items::flush_cache();
$q0 = $db->queries;
ISXM_Items::prime( range( 1, 12 ) );
for ( $i = 1; $i <= 12; $i++ ) ISXM_Items::get( $i );
t( '12 lookups (2 misses) cost one query', $db->queries - $q0 === 1, (string) ( $db->queries - $q0 ) );
t( 'Misses are cached as null', ISXM_Items::get( 12 ) === null );

group( 'Destination counts and keyset scans' );
$db = ledger_fixture();
for ( $i = 1; $i <= 25; $i++ ) $db->db->exec( "INSERT INTO wp_posts(ID) VALUES ($i)" );
$db->db->exec( "UPDATE wp_posts SET post_status='trash' WHERE ID=25" );
$db->db->exec( "UPDATE wp_posts SET post_type='post' WHERE ID=24" );
for ( $i = 1; $i <= 10; $i++ ) ISXM_Items::put( $i, rec( 'media', [ 'a.jpg' ], [ 'origin' => $i <= 4 ? 'offload' : 'migrate', 'missing' => $i === 3 ? [ 'x.jpg' ] : [] ] ) );
ISXM_Items::put( 11, rec( 'other' ) );
ISXM_Items::put( 25, rec() ); // trashed attachment: never counted
$c = ISXM_Items::destination_counts( 'media', 'https://s3.example.test' );
t( 'in_bucket / offloaded / partial', $c === [ 'in_bucket' => 10, 'offloaded' => 4, 'partial' => 1 ], json_encode( $c ) );
t( 'count_on_destination matches', ISXM_Items::count_on_destination( 'media', 'https://s3.example.test' ) === 10 );
$pending = []; $after = 0;
do { $w = ISXM_Items::pending_ids( 4, $after, 'media', 'https://s3.example.test' ); $pending = array_merge( $pending, $w['ids'] ); $after = $w['last_id']; } while ( ! $w['done'] );
t( 'Pending = live attachments not on this destination, paged without gaps', $pending === range( 11, 23 ), json_encode( $pending ) );
$on = []; $after = 0;
do { $w = ISXM_Items::offloaded_ids( 3, $after, 'media', 'https://s3.example.test' ); $on = array_merge( $on, $w['ids'] ); $after = $w['last_id']; } while ( ! $w['done'] );
t( 'Offloaded ids paged without gaps or duplicates', $on === range( 1, 10 ), json_encode( $on ) );
$w = ISXM_Items::records_window( 100, 0, 'media', 'https://s3.example.test' );
t( 'records_window returns full records keyed by id', array_keys( $w['records'] ) === range( 1, 10 ) && $w['records'][3]['missing'] === [ 'x.jpg' ] );

group( 'Backfill from legacy postmeta' );
$db = ledger_fixture();
$db->db->exec( "INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES (5,'_isxs_offload'," . $db->db->quote( serialize( rec() ) ) . "),(6,'_isxs_offload','garbage'),(7,'_isxs_offload'," . $db->db->quote( serialize( rec( 'media', [] ) ) ) . ")" );
$w = ISXM_Items::backfill_window( 10, 0 );
t( 'Valid records copied, broken ones skipped', $w['copied'] === 1 && $w['skipped'] === 2 && ISXM_Items::get( 5 )['bucket'] === 'media', json_encode( $w ) );
t( 'Replaying the window does not duplicate rows', ISXM_Items::backfill_window( 10, 0 )['copied'] === 1 && (int) $db->get_var( 'SELECT COUNT(*) FROM wp_isxs_items' ) === 1 );

group( 'Sync: full scan against the real listing' );
$db = ledger_fixture();
isxm_test_configure( [], [ 'use_prefix' => true, 'prefix' => 'site' ] );
update_option( ISXM_Items::BACKFILLED_OPTION, '1' ); // ledger is the source of truth
for ( $i = 1; $i <= 4; $i++ ) {
    $db->db->exec( "INSERT INTO wp_posts(ID) VALUES ($i)" );
    ISXM_Items::put( $i, [ 'bucket' => 'media', 'endpoint' => 'https://s3.example.test', 'base_key' => "site/$i/", 'files' => [ 'a.jpg', 'a-1.jpg' ] ] );
}
// 1 complete, 2 missing a size, 3 missing its original, 4 complete; plus an orphan and a foreign key.
$GLOBALS['isxm_t']['http_provider'] = function () {
    return migration_listing( [ 'site/1/a.jpg', 'site/1/a-1.jpg', 'site/2/a.jpg', 'site/3/a-1.jpg', 'site/4/a.jpg', 'site/4/a-1.jpg', 'site/old.jpg', 'backup/db.sql', 'site/1/a.jpg' ] );
};
$res = ISXM_Sync::scan_full();
t( 'Stale = original gone, partial = size gone', $res['stale_ids'] === [ 3 ] && $res['partial_ids'] === [ 2 ], json_encode( [ $res['stale_ids'], $res['partial_ids'] ] ) );
t( 'Orphans inside the prefix counted, outside ones kept apart', $res['orphan'] === 1 && $res['outside_prefix'] === 1, json_encode( [ $res['orphan'], $res['outside_prefix'] ] ) );
$windows = 0; $pairs = 0; $after = 0;
do { $w = ISXM_Sync::scan_expected_window( 3, $after ); $after = $w['last_id']; $pairs += count( $w['pairs'] ); $windows++; } while ( ! $w['done'] );
t( 'Expected keys paged from the ledger without gaps', $pairs === 8 && $windows === 2 );

echo "\n{$GLOBALS['isxm_pass']} passed; {$GLOBALS['isxm_fail']} failed\n";
exit( $GLOBALS['isxm_fail'] ? 1 : 0 );
