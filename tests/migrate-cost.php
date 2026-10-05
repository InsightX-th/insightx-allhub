<?php
/** php -d memory_limit=256M tests/migrate-cost.php
 * Cost profile of a FRESH 523,886-object migration (nothing on the
 * destination yet). Counts the work per attachment — S3 requests, SQL
 * queries, job checkpoint writes — and fails when any of it grows with the
 * size of the run, then projects wall time against real-world latency.
 * Index stage runs at full scale; transfer runs on samples from the start
 * and the end of the source listing (per-item cost must not depend on position).
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/migrate-support.php';

$total = 523886; $attachments = 90654; $six = $total - 5 * $attachments;
$noise_per_attachment = 12; // _wp_attachment_metadata, _thumbnail_id, product meta, ...
group( 'Index stage: 90,654 attachments in ' . number_format( $attachments * ( 1 + $noise_per_attachment ) ) . ' postmeta rows' );
$port = 19000 + getmypid() % 1000;
$fixture = proc_open( [ 'python3', __DIR__ . '/parallel-s3.py', (string) $port ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes );
register_shutdown_function( function () use ( $fixture ) { proc_terminate( $fixture ); } );
for ( $i = 0; $i < 50; $i++ ) { $sock = @fsockopen( '127.0.0.1', $port ); if ( $sock ) { fclose( $sock ); break; } usleep( 100000 ); }
if ( ! $sock ) throw new RuntimeException( 'S3 fixture could not bind localhost.' );
function fixture_calls() {
    $stats = json_decode( file_get_contents( 'http://127.0.0.1:' . $GLOBALS['port'] . '/__stats' ), true ); $out = [];
    foreach ( $stats['calls'] as $k => $n ) { $m = strtok( $k, ' ' ); $out[ $m ] = ( $out[ $m ] ?? 0 ) + $n; }
    return $out;
}
$GLOBALS['wpdb'] = new ISXM_Migration_Test_DB(); $db = $GLOBALS['wpdb'];
// The offload ledger is written on every transfer — include its cost too.
$db->db->exec( 'CREATE TABLE wp_isxs_items (id INTEGER PRIMARY KEY AUTOINCREMENT, source_type TEXT, source_id INTEGER, provider TEXT, bucket TEXT, endpoint TEXT, region TEXT, path_style INTEGER, base_key TEXT, object_version TEXT, origin TEXT, files TEXT, missing TEXT, is_partial INTEGER, updated_at TEXT, UNIQUE(source_type,source_id))' );
isxm_test_configure( [ 'endpoint' => 'http://127.0.0.1:' . $port ], [ 'source_provider' => 'custom', 'source_prefix' => '', 'source_use_year_month' => false,
    'use_prefix' => false, 'use_year_month' => false, 'persist_urls' => true, 'remove_local' => true, 'deliver_enabled' => true ] );
update_option( 'isxs_migration_schema', 1 ); // group() reset the option the test DB set
$GLOBALS['isxm_t']['filters']['isxm_migrate_concurrency'] = function () { return 4; };
// GET/PUT go over real cURL to the fixture (120 ms each, default 4-way
// concurrency); listings stay in the in-process fake below.

function cost_sizes( $id, $six ) { return $id <= $six ? 5 : 4; }
function cost_key( $id, $v ) { return sprintf( 'scale%06d', $id ) . ( $v ? '-' . ( $v * 100 ) . 'x100' : '' ) . '.jpg'; }
$GLOBALS['isxm_t']['meta_provider'] = function ( $id, $key ) use ( $six ) {
    if ( $key === '_wp_attached_file' ) return cost_key( $id, 0 );
    if ( $key === '_wp_attachment_metadata' ) {
        $sizes = [];
        for ( $v = 1; $v <= cost_sizes( $id, $six ); $v++ ) $sizes[ 's' . $v ] = [ 'file' => cost_key( $id, $v ) ];
        return [ 'file' => cost_key( $id, 0 ), 'sizes' => $sizes ];
    }
    return $GLOBALS['isxm_t']['meta'][ $id ][ $key ] ?? '';
};
function cost_listing_page( $after, $total, $six ) {
    $end = min( $after + 200, $total ); $keys = [];
    for ( $i = $after; $i < $end; $i++ ) {
        if ( $i < $six * 6 ) { $id = intdiv( $i, 6 ) + 1; $v = $i % 6; }
        else { $j = $i - $six * 6; $id = $six + intdiv( $j, 5 ) + 1; $v = $j % 5; }
        $keys[] = cost_key( $id, $v );
    }
    return migration_listing( $keys, $end < $total ? (string) $end : '' );
}
$http = [];
$GLOBALS['isxm_t']['http_provider'] = function ( $url, $args ) use ( $total, $six, &$http ) {
    parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $q );
    if ( ! isset( $q['list-type'] ) ) throw new RuntimeException( 'Unexpected non-list request: ' . $url );
    $kind = ! empty( $q['prefix'] ) ? 'LIST-probe' : 'LIST-page';
    $http[ $kind ] = ( $http[ $kind ] ?? 0 ) + 1;
    // Fresh destination: probes find nothing.
    return $kind === 'LIST-probe' ? migration_listing( [] ) : cost_listing_page( (int) ( $q['continuation-token'] ?? 0 ), $total, $six );
};
$job_writes = 0; $job_bytes = 0;
$GLOBALS['isxm_t']['on_option_write'] = function ( $key, $value ) use ( &$job_writes, &$job_bytes ) {
    if ( $key !== 'isxs_job_migrate' ) return;
    $job_writes++; $job_bytes += strlen( serialize( $value ) );
};

$db->db->beginTransaction();
$insert = $db->db->prepare( 'INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES (?,?,?)' );
for ( $id = 1; $id <= $attachments; $id++ ) {
    $insert->execute( [ $id, '_wp_attached_file', cost_key( $id, 0 ) ] );
    for ( $n = 0; $n < $noise_per_attachment; $n++ ) $insert->execute( [ $id, '_noise' . $n, 'x' ] );
}
$db->db->commit();
$job = ISXM_Job::start( 'migrate', $total, 'run|' );
$started = microtime( true ); $slices = 0; $q0 = $db->queries;
do { $r = ISXM_Migrate_Queue::step( ISXM_Job::get( 'migrate' ) ); $slices++; }
while ( ! is_wp_error( $r ) && ISXM_Job::get( 'migrate' )->migration['stage'] === 'index' && $slices < 10000 );
$index_seconds = microtime( true ) - $started;
t( 'Index finished', ! is_wp_error( $r ) && ISXM_Job::get( 'migrate' )->migration['stage'] === 'transfer' );
t( 'Index slices scale with attachments, not postmeta rows', $slices <= ceil( $attachments / 2000 ) + 1, (string) $slices );
t( 'Every attachment indexed', (int) $db->get_var( 'SELECT COUNT(*) FROM wp_isxs_migration_paths' ) === $attachments );
printf( "  index: %d slices, %d SELECTs, %d job writes, %.2fs\n", $slices, $db->queries - $q0, $job_writes, $index_seconds );

/** Transfer one listing page at a given source offset and return per-attachment costs. */
function cost_transfer_page( $offset ) {
    global $http, $job_writes, $job_bytes;
    $job = ISXM_Job::get( 'migrate' );
    unset( $job->migration['items'] );
    $job->cursor = 'run|' . ( $offset ? (string) $offset : '' );
    $job->save();
    $http = []; $job_writes = 0; $job_bytes = 0; $q0 = $GLOBALS['wpdb']->queries; $p0 = $job->processed; $f0 = fixture_calls();
    $started = microtime( true ); $steps = 0; $n = 1;
    do {
        $r = ISXM_Migrate_Queue::step( ISXM_Job::get( 'migrate' ) ); $steps++;
        if ( is_wp_error( $r ) ) throw new RuntimeException( $r->get_error_message() );
        $m = ISXM_Job::get( 'migrate' )->migration;
        if ( $steps === 1 && isset( $m['items'] ) ) $n = count( $m['items'] );
    } while ( isset( $m['items'] ) && $steps < 100 );
    $job = ISXM_Job::get( 'migrate' );
    $f1 = fixture_calls();
    foreach ( [ 'GET', 'PUT' ] as $m ) $http[ $m ] = ( $f1[ $m ] ?? 0 ) - ( $f0[ $m ] ?? 0 );
    $objects = $job->processed - $p0;
    return [
        'objects'   => $objects, 'errors' => $job->error_count,
        'http'      => array_sum( $http ) / $n, 'probe' => ( $http['LIST-probe'] ?? 0 ) / $n,
        'get'       => ( $http['GET'] ?? 0 ) / $n, 'put' => ( $http['PUT'] ?? 0 ) / $n,
        'sql'       => ( $GLOBALS['wpdb']->queries - $q0 ) / $n,
        'writes'    => $job_writes / $n, 'bytes' => $job_bytes / $n,
        'ms'        => ( microtime( true ) - $started ) * 1000 / $n,
    ];
}

echo "\n=== Transfer cost per attachment (fresh destination) ===\n";
$first = cost_transfer_page( 0 );
$last = cost_transfer_page( $total - 200 - ( $total % 200 ) );
foreach ( [ 'start' => $first, 'end' => $last ] as $label => $c ) {
    printf( "  %-5s %d objects: per attachment %.1f S3 calls (%.1f GET, %.1f PUT, %.1f LIST probes), %.1f SQL, %.1f job writes (%.0f KiB), %.0f ms wall\n",
        $label, $c['objects'], $c['http'], $c['get'], $c['put'], $c['probe'], $c['sql'], $c['writes'], $c['bytes'] / 1024, $c['ms'] );
}
t( 'Sample pages transferred without errors', $first['objects'] === 200 && $last['objects'] > 0 && $last['errors'] === 0, $last['errors'] . ' errors' );
t( 'Each object downloaded and uploaded exactly once', abs( $first['get'] - $first['put'] ) < 0.01 && $first['get'] > 5 && $first['get'] < 7, sprintf( '%.1f GET %.1f PUT', $first['get'], $first['put'] ) );
t( 'Per-attachment S3 calls do not grow with position', $last['http'] <= $first['http'] * 1.1 );
t( 'Per-attachment SQL does not grow with position', $last['sql'] <= $first['sql'] * 1.1 );
t( 'Job checkpoint size does not grow with position', $last['bytes'] <= $first['bytes'] * 1.1 );
t( 'Fresh destination is not probed per file before uploading', $first['probe'] < 1, sprintf( '%.1f probes', $first['probe'] ) );

// Fixture answers every GET/PUT in 120 ms, a fair stand-in for a
// cross-region S3. Projection assumes one continuous runner.
$hours = ( $first['ms'] + $last['ms'] ) / 2 / 1000 * $attachments / 3600;
printf( "  projection: %.1f h for %s attachments with a continuous runner; %.0f h with the old 25s-on / 300s-stall gap\n",
    $hours, number_format( $attachments ), $hours * 325 / 25 );
t( 'Continuous migration projects under 24 hours', $hours < 24, sprintf( '%.1f h', $hours ) );

echo "\n{$GLOBALS['isxm_pass']} passed; {$GLOBALS['isxm_fail']} failed\n";
exit( $GLOBALS['isxm_fail'] ? 1 : 0 );
